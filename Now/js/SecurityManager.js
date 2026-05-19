/**
 * SecurityManager - Central security management system
 * Manage CSRF, JWT, and other security.
 * Note: Rate limiting is handled by backend.
 */
const SecurityManager = {
  config: {
    // CSRF Configuration
    csrf: {
      enabled: false,
      tokenName: '_token',
      headerName: 'X-CSRF-Token',
      cookieName: 'XSRF-TOKEN',
      tokenUrl: 'api/auth/csrf-token',
      metaName: 'csrf-token',
      autoRefresh: true,
      refreshInterval: 30 * 60 * 1000, // 30 minutes
      validateOnSubmit: true,
      requireForMethods: ['POST', 'PUT', 'PATCH', 'DELETE'],
      excludePaths: ['api/public/*', '/auth/verify'],
      tokenLength: 40
    },

    // JWT Configuration
    jwt: {
      enabled: true,
      cookieName: 'auth_token',
      refreshCookieName: 'refresh_token',
      storageKey: 'auth_user',
      autoRefresh: true,
      refreshBeforeExpiry: 5 * 60 * 1000, // 5 minutes
      refreshEndpoint: 'api/auth/refresh',
      validateSignature: true,
      algorithm: 'HS256',
      issuer: window.location.hostname,
      audience: window.location.hostname
    },



    sanitization: {
      enabled: true,
      removeScripts: true,
      removeEvents: true,
      allowedTags: ['b', 'i', 'u', 'strong', 'em'],
      maxInputLength: 10000
    },

    // Content Security Policy
    csp: {
      enabled: true,
      directives: {
        'default-src': ["'self'"],
        'script-src': ["'self'", "'unsafe-inline'"],
        'style-src': ["'self'", "'unsafe-inline'"],
        'img-src': ["'self'", "data:", "https:"],
        'font-src': ["'self'"],
        'connect-src': ["'self'"],
        'frame-src': ["'none'"],
        'object-src': ["'none'"]
      },
      reportUri: 'api/csp-report'
    }
  },

  state: {
    initialized: false,
    csrfToken: null,
    jwtToken: null,
    lastTokenRefresh: null,
    securityHeaders: new Map(),
    violations: new Set()
  },

  async init(options = {}) {
    try {
      this.config = {...this.config, ...this.mergeDeep(this.config, options)};

      // Initialize CSRF protection
      if (this.config.csrf.enabled) {
        await this.initCSRF();
      }

      // Initialize JWT management
      if (this.config.jwt.enabled) {
        await this.initJWT();
      }



      // Initialize CSP
      if (this.config.csp.enabled) {
        this.initCSP();
      }

      // Setup HTTP interceptors
      this.setupHttpInterceptors();

      this.state.initialized = true;

      this.emit('security:initialized', {
        csrf: this.config.csrf.enabled,
        jwt: this.config.jwt.enabled
      });

      return this;

    } catch (error) {
      this.handleError('Security initialization failed', error);
      throw error;
    }
  },

  // ============ CSRF Management ============
  async initCSRF() {
    try {
      // Get existing token
      this.state.csrfToken = this.getCSRFToken();

      // Generate new token if needed
      if (!this.state.csrfToken) {
        await this.refreshCSRFToken();
      }

      // Setup auto-refresh
      if (this.config.csrf.autoRefresh) {
        this.startCSRFRefresh();
      }

      // Inject into existing forms
      this.injectCSRFIntoForms();
    } catch (error) {
      this.handleError('CSRF initialization failed', error);
    }
  },

  getCSRFToken() {
    // Priority: Cookie > Meta tag > Input field
    let token = null;

    // Try cookie first
    if (this.config.csrf.cookieName) {
      token = this.getCookie(this.config.csrf.cookieName);
    }

    // Try meta tag
    if (!token && this.config.csrf.metaName) {
      const meta = document.querySelector(`meta[name="${this.config.csrf.metaName}"]`);
      token = meta?.getAttribute('content');
    }

    // Try hidden input
    if (!token) {
      const input = document.querySelector(`input[name="${this.config.csrf.tokenName}"]`);
      token = input?.value;
    }

    return token;
  },

  async refreshCSRFToken() {
    try {
      if (!this.config.csrf.enabled) return null;

      const apiService = window.ApiService || window.Now?.getManager?.('api');
      const headers = {
        'X-Requested-With': 'XMLHttpRequest'
      };

      let response;
      if (apiService?.get) {
        response = await apiService.get(this.config.csrf.tokenUrl, {}, {headers});
      } else if (window.simpleFetch?.get) {
        response = await simpleFetch.get(this.config.csrf.tokenUrl, {headers});
      } else {
        throw new Error('ApiService is not available');
      }

      if (!response.success) {
        throw new Error(`Failed to get CSRF token: ${response.status}`);
      }

      const data = response.data || {};
      this.state.csrfToken = data.data.csrf_token || null;

      // Update meta tag
      this.updateCSRFMeta(this.state.csrfToken);

      // Update all forms
      this.updateCSRFInForms(this.state.csrfToken);

      this.state.lastTokenRefresh = Date.now();
      this.emit('csrf:refreshed', {token: this.state.csrfToken});

      return this.state.csrfToken;

    } catch (error) {
      this.handleError('CSRF token refresh failed', error);
      return null;
    }
  },

  updateCSRFMeta(token) {
    let meta = document.querySelector(`meta[name="${this.config.csrf.metaName}"]`);
    if (!meta) {
      meta = document.createElement('meta');
      meta.name = this.config.csrf.metaName;
      document.head.appendChild(meta);
    }
    meta.setAttribute('content', token);
  },

  injectCSRFIntoForms() {
    if (!this.state.csrfToken) return;

    document.querySelectorAll('form').forEach(form => {
      this.injectCSRFIntoForm(form, this.state.csrfToken);
    });
  },

  injectCSRFIntoForm(form, token = null) {
    if (!this.config.csrf.enabled) return;

    token = token || this.state.csrfToken;
    if (!token) return;

    // Check if form should be excluded
    const action = form.getAttribute('action') || '';
    const method = (form.getAttribute('method') || 'GET').toUpperCase();

    if (!this.config.csrf.requireForMethods.includes(method)) {
      return;
    }

    if (this.isPathExcluded(action)) {
      return;
    }

    // Check data-csrf attribute
    const csrfSetting = form.dataset.csrf;
    if (csrfSetting === 'false' || csrfSetting === 'disabled') {
      return;
    }

    // Find or create CSRF input
    let csrfInput = form.querySelector(`input[name="${this.config.csrf.tokenName}"]`);
    if (!csrfInput) {
      csrfInput = document.createElement('input');
      csrfInput.type = 'hidden';
      csrfInput.name = this.config.csrf.tokenName;
      form.appendChild(csrfInput);
    }

    csrfInput.value = token;
  },

  updateCSRFInForms(token) {
    document.querySelectorAll(`input[name="${this.config.csrf.tokenName}"]`).forEach(input => {
      input.value = token;
    });
  },

  startCSRFRefresh() {
    if (this.csrfRefreshTimer) {
      clearInterval(this.csrfRefreshTimer);
    }

    this.csrfRefreshTimer = setInterval(async () => {
      await this.refreshCSRFToken();
    }, this.config.csrf.refreshInterval);
  },

  // ============ JWT Management ============
  async initJWT() {
    try {
      // Get existing token
      this.state.jwtToken = this.getJWTToken();

      // Validate token if exists
      if (this.state.jwtToken) {
        const isValid = this.validateJWTToken(this.state.jwtToken);
        if (!isValid) {
          await this.clearJWTToken();
        }
      }

      // Setup auto-refresh
      if (this.config.jwt.autoRefresh && this.state.jwtToken) {
        this.startJWTRefresh();
      }
    } catch (error) {
      this.handleError('JWT initialization failed', error);
    }
  },

  getJWTToken() {
    // JWT tokens are typically httpOnly cookies, so we can't access them directly
    // This method would be used for non-httpOnly tokens stored in localStorage
    return localStorage.getItem(this.config.jwt.storageKey);
  },

  validateJWTToken(token) {
    if (!token) return false;

    try {
      const parts = token.split('.');
      if (parts.length !== 3) return false;

      const payload = JSON.parse(atob(parts[1]));

      // Check expiration
      if (payload.exp && Date.now() >= payload.exp * 1000) {
        return false;
      }

      // Check issuer
      if (this.config.jwt.issuer && payload.iss !== this.config.jwt.issuer) {
        return false;
      }

      // Check audience
      if (this.config.jwt.audience && payload.aud !== this.config.jwt.audience) {
        return false;
      }

      return true;

    } catch (error) {
      return false;
    }
  },

  async clearJWTToken() {
    this.state.jwtToken = null;
    localStorage.removeItem(this.config.jwt.storageKey);

    if (this.jwtRefreshTimer) {
      clearInterval(this.jwtRefreshTimer);
    }

    this.emit('jwt:cleared');
  },

  startJWTRefresh() {
    if (this.jwtRefreshTimer) {
      clearInterval(this.jwtRefreshTimer);
    }

    // Calculate refresh time based on token expiry
    const token = this.state.jwtToken;
    if (!token) return;

    try {
      const payload = JSON.parse(atob(token.split('.')[1]));
      const exp = payload.exp;
      const now = Math.floor(Date.now() / 1000);
      const timeUntilRefresh = (exp - now) * 1000 - this.config.jwt.refreshBeforeExpiry;

      if (timeUntilRefresh > 0) {
        this.jwtRefreshTimer = setTimeout(async () => {
          await this.refreshJWTToken();
        }, timeUntilRefresh);
      }

    } catch (error) {
    }
  },

  async refreshJWTToken() {
    try {
      const refreshUrl = this.config.jwt.refreshEndpoint || 'api/auth/refresh';
      const apiService = window.ApiService || window.Now?.getManager?.('api');
      const headers = {
        'X-Requested-With': 'XMLHttpRequest'
      };

      let response;
      if (apiService?.post) {
        response = await apiService.post(refreshUrl, null, {headers});
      } else if (window.simpleFetch?.post) {
        response = await simpleFetch.post(refreshUrl, null, {headers});
      } else {
        throw new Error('ApiService is not available');
      }

      if (response.success) {
        const data = response.data || {};
        if (data.token) {
          this.state.jwtToken = data.token;
          localStorage.setItem(this.config.jwt.storageKey, data.token);
          this.startJWTRefresh(); // Setup next refresh
          this.emit('jwt:refreshed', {token: data.token});
        }
      }

    } catch (error) {
      this.handleError('JWT refresh failed', error);
    }
  },

  // ============ Input Sanitization (no validation) ============
  sanitizeInput(value) {
    if (!this.config.sanitization.enabled) return value;
    if (typeof value !== 'string') return value;

    let sanitized = value;

    // Remove script tags
    if (this.config.sanitization.removeScripts) {
      sanitized = sanitized.replace(/<script[^>]*>.*?<\/script>/gi, '');
    }

    // Remove javascript: URLs
    sanitized = sanitized.replace(/javascript:/gi, '');

    // Remove event handlers
    if (this.config.sanitization.removeEvents) {
      sanitized = sanitized.replace(/on\w+\s*=/gi, '');
    }

    // Trim to max length
    if (sanitized.length > this.config.sanitization.maxInputLength) {
      sanitized = sanitized.substring(0, this.config.sanitization.maxInputLength);
    }

    return sanitized;
  },

  // ============ Content Security Policy ============
  initCSP() {
    // Add CSP meta tag if not exists
    if (!document.querySelector('meta[http-equiv="Content-Security-Policy"]')) {
      this.addCSPMeta();
    }

    // Setup CSP violation reporting
    document.addEventListener('securitypolicyviolation', this.handleCSPViolation.bind(this));
  },

  addCSPMeta() {
    const meta = document.createElement('meta');
    meta.setAttribute('http-equiv', 'Content-Security-Policy');

    const directives = [];
    for (const [key, values] of Object.entries(this.config.csp.directives)) {
      directives.push(`${key} ${values.join(' ')}`);
    }

    meta.setAttribute('content', directives.join('; '));
    document.head.appendChild(meta);
  },

  handleCSPViolation(event) {
    const violation = {
      directive: event.violatedDirective,
      uri: event.blockedURI,
      source: event.sourceFile,
      line: event.lineNumber,
      timestamp: Date.now()
    };

    this.state.violations.add(violation);
    this.emit('csp:violation', violation);

    // Report to server if configured
    if (this.config.csp.reportUri) {
      this.reportCSPViolation(violation);
    }
  },

  async reportCSPViolation(violation) {
    try {
      const apiService = window.ApiService || window.Now?.getManager?.('api');
      const headers = {
        'Content-Type': 'application/json'
      };

      if (apiService?.post) {
        await apiService.post(this.config.csp.reportUri, violation, {headers});
      } else if (window.simpleFetch?.post) {
        await simpleFetch.post(this.config.csp.reportUri, violation, {headers});
      } else {
        throw new Error('ApiService is not available');
      }
    } catch (error) {
    }
  },

  // ============ HTTP Interceptors ============
  setupHttpInterceptors() {
    if (!window.http) return;

    // Request interceptor
    window.http.addRequestInterceptor(async (config) => {
      // Add CSRF token
      if (this.config.csrf.enabled && this.shouldAddCSRF(config)) {
        config.headers = config.headers || {};
        config.headers[this.config.csrf.headerName] = this.state.csrfToken;
      }



      // Sanitize request data (no validation)
      if (config.body && this.config.sanitization.enabled) {
        config.body = this.sanitizeRequestData(config.body);
      }

      return config;
    });

    // Response interceptor
    window.http.addResponseInterceptor(
      (response) => {
        const newToken = response.headers[this.config.csrf.headerName.toLowerCase()];
        if (newToken && newToken !== this.state.csrfToken) {
          this.state.csrfToken = newToken;
          this.updateCSRFMeta(newToken);
          this.updateCSRFInForms(newToken);
        }

        return response;
      },
      (error) => {
        if (error.status === 419) { // CSRF token mismatch
          this.handleCSRFError();
        }

        throw error;
      }
    );
  },

  shouldAddCSRF(config) {
    if (!config.method) return false;

    const method = config.method.toUpperCase();
    if (!this.config.csrf.requireForMethods.includes(method)) {
      return false;
    }

    return !this.isPathExcluded(config.url);
  },

  sanitizeRequestData(data) {
    if (typeof data === 'string') {
      try {
        const parsed = JSON.parse(data);
        return JSON.stringify(this.sanitizeObject(parsed));
      } catch {
        return this.sanitizeInput(data);
      }
    }

    if (data instanceof FormData) {
      const sanitized = new FormData();
      for (const [key, value] of data) {
        sanitized.append(key, this.sanitizeInput(value));
      }
      return sanitized;
    }

    return this.sanitizeObject(data);
  },

  sanitizeObject(obj) {
    if (typeof obj !== 'object' || obj === null) {
      return this.sanitizeInput(obj);
    }

    const sanitized = {};
    for (const [key, value] of Object.entries(obj)) {
      if (typeof value === 'object') {
        sanitized[key] = this.sanitizeObject(value);
      } else {
        sanitized[key] = this.sanitizeInput(value);
      }
    }

    return sanitized;
  },

  // ============ Form Interceptors ============
  setupFormInterceptors() {
    // Intercept form creation
    const observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
          if (node.nodeType === 1) {
            if (node.tagName === 'FORM') {
              this.enhanceForm(node);
            } else {
              const forms = node.querySelectorAll('form');
              forms.forEach(form => this.enhanceForm(form));
            }
          }
        });
      });
    });

    observer.observe(document.body, {
      childList: true,
      subtree: true
    });

    // Enhance existing forms
    document.querySelectorAll('form').forEach(form => {
      this.enhanceForm(form);
    });
  },

  enhanceForm(form) {
    // Skip if already enhanced
    if (form.dataset.securityEnhanced) return;

    // Read security configuration from data attributes
    const config = this.extractFormSecurityConfig(form);

    // Apply CSRF protection
    if (config.csrf !== false) {
      this.injectCSRFIntoForm(form);
    }

    form.dataset.securityEnhanced = 'true';
  },

  extractFormSecurityConfig(form) {
    return {
      csrf: this.getDataBool(form, 'csrf', this.config.csrf.enabled)
    };
  },

  // ============ Error Handlers ============
  async handleCSRFError() {
    // Skip CSRF error handling if CSRF is disabled
    if (!this.config.csrf.enabled) {
      return;
    }

    // If we have a token, try a single automatic retry before refreshing the token.
    // This helps when the server marks tokens as pending and only consumes them on success.
    try {
      if (this.state.csrfToken) {
        // Emit an event so callers can retry their last request if they want.
        // Consumers can listen to 'csrf:retry' to re-send the failed request.
        this.emit('csrf:retry', {token: this.state.csrfToken});

        // Notify user that the token will be retried automatically
        this.showNotification(Now.translate('Security token present. Attempting a retry...'), 'info');

        // Give consumers a short window to retry; after that, refresh token as fallback
        await new Promise(resolve => setTimeout(resolve, 800));

        // If token is unchanged and no consumer retried, refresh as fallback
        await this.refreshCSRFToken();
        this.showNotification(Now.translate('Security token updated. Please try again.'), 'warning');
        return;
      }

      // No token available -> refresh
      await this.refreshCSRFToken();

      this.showNotification(Now.translate('Security token updated. Please try again.'), 'warning');

    } catch (error) {
      this.showNotification(Now.translate('Security error. Please refresh the page.'), 'error');
    }
  },

  // ============ Utility Methods ============
  isPathExcluded(path) {
    if (!path) return false;

    return this.config.csrf.excludePaths.some(pattern => {
      if (pattern.endsWith('*')) {
        return path.startsWith(pattern.slice(0, -1));
      }
      return path === pattern;
    });
  },

  getDataBool(element, attribute, defaultValue = false) {
    const value = element.dataset[attribute];
    if (value === undefined) return defaultValue;
    return value === 'true' || value === '1';
  },

  getCookie(name) {
    const value = `; ${document.cookie}`;
    const parts = value.split(`; ${name}=`);
    if (parts.length === 2) {
      return parts.pop().split(';').shift();
    }
    return null;
  },

  showNotification(message, type = 'info') {
    if (window.NotificationManager) {
      window.NotificationManager[type](message);
    }
  },

  emit(event, data = {}) {
    if (window.EventManager) {
      window.EventManager.emit(event, data);
    }

    // Also emit as DOM event
    const customEvent = new CustomEvent(event, {
      detail: data,
      bubbles: true,
      cancelable: true
    });
    document.dispatchEvent(customEvent);
  },

  handleError(message, error) {
    if (window.ErrorManager) {
      window.ErrorManager.handle(error, {
        context: 'SecurityManager',
        message
      });
    }

    this.emit('security:error', {message, error});
  },

  mergeDeep(target, source) {
    const isObject = (obj) => obj && typeof obj === 'object' && !Array.isArray(obj);

    if (!isObject(target) || !isObject(source)) {
      return source;
    }

    Object.keys(source).forEach(key => {
      const targetValue = target[key];
      const sourceValue = source[key];

      if (Array.isArray(targetValue) && Array.isArray(sourceValue)) {
        target[key] = targetValue.concat(sourceValue);
      } else if (isObject(targetValue) && isObject(sourceValue)) {
        target[key] = this.mergeDeep(Object.assign({}, targetValue), sourceValue);
      } else {
        target[key] = sourceValue;
      }
    });

    return target;
  },

  // ============ Public API ============
  getCSRFTokenForForm(form) {
    return this.state.csrfToken;
  },

  refreshTokens() {
    return Promise.all([
      this.refreshCSRFToken(),
      this.refreshJWTToken()
    ]);
  },

  isRateLimited(endpoint = null) {
    const result = this.checkRateLimit(endpoint || window.location.pathname);
    return !result.allowed;
  },

  getRateLimitStatus(endpoint = null) {
    return this.checkRateLimit(endpoint || window.location.pathname);
  },

  addCSRFToRequest(config) {
    if (this.config.csrf.enabled && this.shouldAddCSRF(config) && this.state.csrfToken) {
      config.headers = config.headers || {};
      config.headers[this.config.csrf.headerName] = this.state.csrfToken;
    }
    return config;
  },

  // ============ Cleanup ============
  destroy() {
    if (this.csrfRefreshTimer) {
      clearInterval(this.csrfRefreshTimer);
    }

    if (this.jwtRefreshTimer) {
      clearInterval(this.jwtRefreshTimer);
      clearTimeout(this.jwtRefreshTimer);
    }

    this.state.initialized = false;
    this.state.violations.clear();

    this.emit('security:destroyed');
  }
};

// Register with Now.js framework
if (window.Now?.registerManager) {
  Now.registerManager('security', SecurityManager);
}

// Expose globally
window.SecurityManager = SecurityManager;
