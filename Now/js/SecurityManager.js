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
      // MUST NOT be 'auth_user': that key belongs to AuthManager, which stores
      // the user PROFILE there (a JSON object) for display. This one expects a
      // JWT STRING. Sharing the key made initJWT() read the profile, fail to
      // validate it as a JWT, and clearJWTToken() delete it on every page load,
      // so the profile cache never survived and the app could not work offline.
      // (Real tokens here are httpOnly cookies, so this key is rarely used.)
      storageKey: 'auth_jwt',
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
        // Allow social SDK hosts; include 'unsafe-eval' if Telegram widget requires it.
        'script-src': ["'self'", "https://accounts.google.com", "https://apis.google.com", "https://connect.facebook.net", "https://telegram.org", "https://*.telegram.org", "'unsafe-inline'", "'unsafe-eval'"],
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

  /**
   * Initializes the security layer: CSRF tokens, JWT handling, CSP and the
   * HTTP interceptors, each according to its own enabled flag.
   *
   * @param {Object} [options={}] - Configuration merged over the defaults
   * @returns {Promise<Object>} The manager instance
   * @throws {Error} When initialization fails
   */
  async init(options = {}) {
    try {
      this.config = this.mergeDeep(this.config, options);

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
  /**
   * Prepares CSRF protection: reads or fetches the token, starts the refresh
   * timer, and injects the token into the forms already on the page.
   *
   * @returns {Promise<void>}
   */
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

  /**
   * Reads the current CSRF token, trying the cookie, then the meta tag, then
   * a hidden input.
   *
   * @returns {string|null} Token, or null when none is present
   */
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

  /**
   * Fetches a new CSRF token from the server and propagates it to the meta tag
   * and every form on the page.
   *
   * @returns {Promise<string|null>} New token, or null when the request failed
   */
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
        // The API's own message says what actually went wrong (a missing endpoint,
        // a permission problem); the status alone says almost nothing.
        const reason = response.data?.message || response.statusText || response.status;
        throw new Error(`Failed to get CSRF token: ${reason}`);
      }

      const data = response.data || {};
      this.state.csrfToken = data.data?.csrf_token || null;

      if (!this.state.csrfToken) {
        throw new Error('Failed to get CSRF token: response carried no csrf_token');
      }

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

  /**
   * Writes the token into the CSRF meta tag, creating the tag when absent.
   *
   * @param {string} token - CSRF token
   * @returns {void}
   */
  updateCSRFMeta(token) {
    let meta = document.querySelector(`meta[name="${this.config.csrf.metaName}"]`);
    if (!meta) {
      meta = document.createElement('meta');
      meta.name = this.config.csrf.metaName;
      document.head.appendChild(meta);
    }
    meta.setAttribute('content', token);
  },

  /**
   * Injects the current CSRF token into every form in the document.
   *
   * @returns {void}
   */
  injectCSRFIntoForms() {
    if (!this.state.csrfToken) return;

    document.querySelectorAll('form').forEach(form => {
      this.injectCSRFIntoForm(form, this.state.csrfToken);
    });
  },

  /**
   * Adds or updates the hidden CSRF input of one form.
   *
   * Forms whose method needs no token, whose action is excluded, or which
   * carry data-csrf="false" are skipped.
   *
   * @param {HTMLFormElement} form - Form to protect
   * @param {string} [token=null] - Token to use; defaults to the current one
   * @returns {void}
   */
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

  /**
   * Rewrites the value of every CSRF input in the document.
   *
   * @param {string} token - New CSRF token
   * @returns {void}
   */
  updateCSRFInForms(token) {
    document.querySelectorAll(`input[name="${this.config.csrf.tokenName}"]`).forEach(input => {
      input.value = token;
    });
  },

  /**
   * Starts the CSRF refresh timer, replacing any timer already running.
   *
   * @returns {void}
   */
  startCSRFRefresh() {
    if (this.csrfRefreshTimer) {
      clearInterval(this.csrfRefreshTimer);
    }

    this.csrfRefreshTimer = setInterval(async () => {
      await this.refreshCSRFToken();
    }, this.config.csrf.refreshInterval);
  },

  // ============ JWT Management ============
  /**
   * Prepares JWT handling: loads the stored token, drops it when it fails the
   * structural check, and starts the refresh timer when it is still usable.
   *
   * @returns {Promise<void>}
   */
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

  /**
   * Reads the JWT from local storage.
   *
   * Tokens delivered as httpOnly cookies are invisible here by design; this
   * only covers tokens the application stores itself.
   *
   * @returns {string|null} Stored token, or null
   */
  getJWTToken() {
    // JWT tokens are typically httpOnly cookies, so we can't access them directly
    // This method would be used for non-httpOnly tokens stored in localStorage
    return localStorage.getItem(this.config.jwt.storageKey);
  },

  /**
   * Structural pre-check for a JWT held client-side.
   *
   * SECURITY: This does NOT and CANNOT verify the token signature — the secret
   * lives only on the server. A `true` result means "this token is well-formed,
   * unexpired, and claims our issuer/audience", NOT "this token is authentic".
   * The server `/auth/verify` endpoint (see AuthManager.checkAuthStatus) is the
   * sole authority for authentication/authorization. Use this only to decide
   * whether to keep or drop a locally cached token before hitting the server.
   */
  validateJWTToken(token) {
    if (!token) return false;

    try {
      const parts = token.split('.');
      if (parts.length !== 3) return false;

      // Reject forged "alg: none" tokens and any algorithm we do not expect.
      // (Defence-in-depth: the server enforces this too.)
      const header = JSON.parse(this.base64UrlDecode(parts[0]));
      const alg = String(header.alg || '').toUpperCase();
      if (alg === 'NONE' || alg !== String(this.config.jwt.algorithm).toUpperCase()) {
        return false;
      }

      const payload = JSON.parse(this.base64UrlDecode(parts[1]));

      // Check expiration (with small clock-skew leeway)
      if (payload.exp && Date.now() >= (payload.exp * 1000) + 0) {
        return false;
      }

      // Reject tokens that are not yet valid
      if (payload.nbf && Date.now() < payload.nbf * 1000) {
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

  /**
   * Decodes a base64url segment. JWT uses base64url, not standard base64, so
   * the alphabet is translated and the padding restored before decoding.
   *
   * @param {string} segment - Base64url encoded segment
   * @returns {string} Decoded string
   */
  base64UrlDecode(segment) {
    const padded = segment.replace(/-/g, '+').replace(/_/g, '/');
    const pad = padded.length % 4 ? '='.repeat(4 - (padded.length % 4)) : '';
    return atob(padded + pad);
  },

  /**
   * Drops the stored JWT, stops the refresh timer and emits jwt:cleared.
   *
   * @returns {Promise<void>}
   */
  async clearJWTToken() {
    this.state.jwtToken = null;
    localStorage.removeItem(this.config.jwt.storageKey);

    if (this.jwtRefreshTimer) {
      clearInterval(this.jwtRefreshTimer);
    }

    this.emit('jwt:cleared');
  },

  /**
   * Schedules the next JWT refresh for refreshBeforeExpiry milliseconds before
   * the token expires. Does nothing when the token already expired.
   *
   * @returns {void}
   */
  startJWTRefresh() {
    if (this.jwtRefreshTimer) {
      clearInterval(this.jwtRefreshTimer);
    }

    // Calculate refresh time based on token expiry
    const token = this.state.jwtToken;
    if (!token) return;

    try {
      const payload = JSON.parse(this.base64UrlDecode(token.split('.')[1]));
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

  /**
   * Requests a fresh JWT from the refresh endpoint, stores it and schedules
   * the following refresh.
   *
   * @returns {Promise<void>}
   */
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
  /**
   * Strips script tags, javascript: URLs and inline event handlers from a
   * string, then trims it to the configured maximum length.
   *
   * base64 image data URLs are passed through untouched.
   *
   * @param {*} value - Value to clean; non-strings are returned unchanged
   * @returns {*} Cleaned value
   */
  sanitizeInput(value) {
    if (!this.config.sanitization.enabled) return value;
    if (typeof value !== 'string') return value;

    // Keep data URL images intact (base64 is large and can contain patterns that sanitizers alter).
    if (/^data:image\/[a-zA-Z]+;base64,/.test(value)) return value;

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
  /**
   * Applies the Content Security Policy: adds the meta tag when the document
   * has none, and starts listening for violation reports.
   *
   * @returns {void}
   */
  initCSP() {
    // Add CSP meta tag if not exists
    if (!document.querySelector('meta[http-equiv="Content-Security-Policy"]')) {
      this.addCSPMeta();
    }

    // Setup CSP violation reporting
    document.addEventListener('securitypolicyviolation', this.handleCSPViolation.bind(this));
  },

  /**
   * Builds the CSP header value from config.csp.directives and adds it to the
   * document as a meta tag.
   *
   * @returns {void}
   */
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

  /**
   * Records a CSP violation, emits csp:violation, and reports it to the server
   * when a report URI is configured.
   *
   * @param {SecurityPolicyViolationEvent} event - Violation event
   * @returns {void}
   */
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

  /**
   * Posts a CSP violation to the configured report endpoint.
   *
   * Failures are swallowed: reporting must never break the page.
   *
   * @param {Object} violation - Violation record
   * @returns {Promise<void>}
   */
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
  /**
   * Installs the request and response interceptors on the HTTP client.
   *
   * Requests get the CSRF header and sanitized bodies; responses adopt a
   * rotated token, and status 419 triggers the CSRF recovery flow.
   *
   * @returns {void}
   */
  setupHttpInterceptors() {
    if (!window.http) return;

    // Request interceptor
    window.http.addRequestInterceptor(async (config) => {
      // Add CSRF token
      if (this.config.csrf.enabled && this.shouldAddCSRF(config)) {
        config.headers = config.headers || {};
        config.headers[this.config.csrf.headerName] = this.state.csrfToken;
      }



      // Sanitize request data (no validation).
      // X-Skip-Sanitize (set by FormManager for forms with
      // data-sanitize-input="false") opts a request out — the server
      // encodes those fields itself and this stripping is lossy.
      if (config.body && this.config.sanitization.enabled && config.headers?.['X-Skip-Sanitize'] !== 'true') {
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

  /**
   * Decides whether a request needs the CSRF header, based on its method and
   * whether its URL is excluded.
   *
   * @param {Object} config - Request configuration
   * @returns {boolean} True when the header must be added
   */
  shouldAddCSRF(config) {
    if (!config.method) return false;

    const method = config.method.toUpperCase();
    if (!this.config.csrf.requireForMethods.includes(method)) {
      return false;
    }

    return !this.isPathExcluded(config.url);
  },

  /**
   * Cleans a request body, handling JSON strings, FormData and plain objects.
   *
   * @param {*} data - Request body
   * @returns {*} Cleaned body of the same shape
   */
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
        if (typeof value === 'string' && /^data:image\/[a-zA-Z]+;base64,/.test(value)) {
          sanitized.append(key, value);
        } else {
          sanitized.append(key, this.sanitizeInput(value));
        }
      }
      return sanitized;
    }

    return this.sanitizeObject(data);
  },

  /**
   * Cleans every string value of an object, recursing into nested objects.
   *
   * @param {*} obj - Object or value to clean
   * @returns {*} Cleaned copy
   */
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
  /**
   * Enhances the forms already in the document and watches for new ones, so
   * dynamically added forms are protected too.
   *
   * @returns {void}
   */
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

  /**
   * Applies the security configuration of one form, currently CSRF injection,
   * and marks it so the work is not repeated.
   *
   * @param {HTMLFormElement} form - Form to enhance
   * @returns {void}
   */
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

  /**
   * Reads the security settings a form declares through data attributes.
   *
   * @param {HTMLFormElement} form - Form to inspect
   * @returns {Object} Settings, currently {csrf}
   */
  extractFormSecurityConfig(form) {
    return {
      csrf: this.getDataBool(form, 'csrf', this.config.csrf.enabled)
    };
  },

  // ============ Error Handlers ============
  /**
   * Recovers from a rejected CSRF token (HTTP 419).
   *
   * Emits csrf:retry first so a caller can re-send the failed request — the
   * server may only consume a pending token on success — then refreshes the
   * token as a fallback.
   *
   * @returns {Promise<void>}
   */
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
  /**
   * Tests a path against config.csrf.excludePaths, where a trailing '*' makes
   * the entry a prefix match.
   *
   * @param {string} path - URL or form action to test
   * @returns {boolean} True when the path is excluded from CSRF
   */
  isPathExcluded(path) {
    if (!path) return false;

    return this.config.csrf.excludePaths.some(pattern => {
      if (pattern.endsWith('*')) {
        return path.startsWith(pattern.slice(0, -1));
      }
      return path === pattern;
    });
  },

  /**
   * Reads a boolean data attribute, accepting 'true' and '1'.
   *
   * @param {HTMLElement} element - Element carrying the attribute
   * @param {string} attribute - Dataset key
   * @param {boolean} [defaultValue=false] - Value when the attribute is absent
   * @returns {boolean} Parsed value
   */
  getDataBool(element, attribute, defaultValue = false) {
    const value = element.dataset[attribute];
    if (value === undefined) return defaultValue;
    return value === 'true' || value === '1';
  },

  /**
   * Reads a cookie by name.
   *
   * @param {string} name - Cookie name
   * @returns {string|null} Cookie value, or null when not set
   */
  getCookie(name) {
    const value = `; ${document.cookie}`;
    const parts = value.split(`; ${name}=`);
    if (parts.length === 2) {
      return parts.pop().split(';').shift();
    }
    return null;
  },

  /**
   * Shows a notification when NotificationManager is available.
   *
   * @param {string} message - Message to display
   * @param {string} [type='info'] - Notification type
   * @returns {void}
   */
  showNotification(message, type = 'info') {
    if (window.NotificationManager) {
      window.NotificationManager[type](message);
    }
  },

  /**
   * Emits an event through EventManager and as a DOM CustomEvent, so listeners
   * can use either channel.
   *
   * @param {string} event - Event name
   * @param {Object} [data={}] - Event payload
   * @returns {void}
   */
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

  /**
   * Reports an error to ErrorManager and emits security:error.
   *
   * @param {string} message - Description of what failed
   * @param {Error} error - Error that was caught
   * @returns {void}
   */
  handleError(message, error) {
    if (window.ErrorManager) {
      window.ErrorManager.handle(error, {
        context: 'SecurityManager',
        message
      });
    }

    this.emit('security:error', {message, error});
  },

  /**
   * Merges source into target recursively, concatenating arrays and skipping
   * the keys isUnsafeKey() rejects.
   *
   * @param {Object} target - Object to merge into; mutated
   * @param {Object} source - Object to merge from
   * @returns {Object} The merged target
   */
  mergeDeep(target, source) {
    const isObject = (obj) => obj && typeof obj === 'object' && !Array.isArray(obj);

    if (!isObject(target) || !isObject(source)) {
      return source;
    }

    Object.keys(source).forEach(key => {
      // Prototype-pollution guard: never copy __proto__/constructor/prototype
      if (this.isUnsafeKey(key)) return;

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

  // ============ Centralized sanitization & safe-object API ============
  // This is the single security layer the rest of the framework routes through.
  // Render paths should call setSafeHtml/sanitizeHtml/escapeHtml/sanitizeUrl
  // instead of touching innerHTML directly; object merges/path-sets should use
  // safeMerge/safeSetByPath to stay free of prototype pollution.

  /**
   * Reports whether a key must never be written through merge, clone or
   * path-set operations, which is what keeps them free of prototype pollution.
   *
   * @param {string} key - Key about to be written
   * @returns {boolean} True when the key is unsafe
   */
  isUnsafeKey(key) {
    return key === '__proto__' || key === 'constructor' || key === 'prototype';
  },

  /**
   * Entity-encode a value for safe insertion as HTML text. Self-contained so it
   * works regardless of Utils load order.
   */
  escapeHtml(value) {
    const s = value == null ? '' : String(value);
    return s
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  },

  /**
   * Return a sanitized HTML string safe to assign to innerHTML.
   * Prefers DOMPurify, falls back to the framework allowlist sanitizer
   * (TemplateManager.sanitizeElement), and finally to full entity-encoding.
   */
  sanitizeHtml(html, options = {}) {
    if (html == null) return '';
    const str = String(html);

    if (window.DOMPurify) {
      return window.DOMPurify.sanitize(str, options.domPurify || {});
    }

    const tm = window.TemplateManager;
    if (tm && typeof tm.sanitizeElement === 'function') {
      try {
        const out = tm.sanitizeElement(str);
        if (typeof out === 'string') return out;
      } catch (e) {
        // fall through to escaping
      }
    }

    // Last resort: lossless-but-safe — render as inert text.
    return this.escapeHtml(str);
  },

  /**
   * Assign sanitized HTML to an element. Preferred over `el.innerHTML = ...`
   * anywhere the markup is dynamic / data-derived.
   */
  setSafeHtml(element, html) {
    if (element) {
      element.innerHTML = this.sanitizeHtml(html);
    }
    return element;
  },

  /**
   * Validate/clean a URL for use in href/src. Returns '' for dangerous schemes
   * (javascript:, data:, vbscript:, ...).
   */
  sanitizeUrl(url) {
    if (url == null) return '';
    const str = String(url).trim();

    const tm = window.TemplateManager;
    if (tm && typeof tm.sanitizeUrlAttribute === 'function') {
      return tm.sanitizeUrlAttribute(str) || '';
    }

    const lower = str.toLowerCase();
    const blocked = ['javascript:', 'data:', 'vbscript:', 'file:', 'about:'];
    if (blocked.some(proto => lower.startsWith(proto))) return '';
    return str;
  },

  /**
   * Prototype-pollution-safe recursive merge. Use instead of ad-hoc deep merges
   * when `source` may originate from untrusted JSON.
   */
  safeMerge(target, source) {
    const isObject = (obj) => obj && typeof obj === 'object' && !Array.isArray(obj);
    if (!isObject(target) || !isObject(source)) return source;

    Object.keys(source).forEach(key => {
      if (this.isUnsafeKey(key)) return;
      const targetValue = target[key];
      const sourceValue = source[key];
      if (Array.isArray(targetValue) && Array.isArray(sourceValue)) {
        target[key] = targetValue.concat(sourceValue);
      } else if (isObject(targetValue) && isObject(sourceValue)) {
        target[key] = this.safeMerge(Object.assign({}, targetValue), sourceValue);
      } else {
        target[key] = sourceValue;
      }
    });
    return target;
  },

  /**
   * Prototype-pollution-safe "set nested property by path". Refuses to traverse
   * or write __proto__/constructor/prototype. Returns the (possibly unchanged)
   * root object.
   */
  safeSetByPath(obj, path, value) {
    if (!obj || typeof obj !== 'object') return obj;
    const parts = Array.isArray(path) ? path : String(path).split('.');
    let cur = obj;
    for (let i = 0; i < parts.length - 1; i++) {
      const key = parts[i];
      if (this.isUnsafeKey(key)) return obj;
      if (typeof cur[key] !== 'object' || cur[key] === null) {
        cur[key] = {};
      }
      cur = cur[key];
    }
    const last = parts[parts.length - 1];
    if (this.isUnsafeKey(last)) return obj;
    cur[last] = value;
    return obj;
  },

  // ============ Public API ============
  /**
   * Returns the CSRF token a form should submit.
   *
   * @param {HTMLFormElement} form - Form asking for the token
   * @returns {string|null} Current CSRF token
   */
  getCSRFTokenForForm(form) {
    return this.state.csrfToken;
  },

  /**
   * Refreshes the CSRF and JWT tokens together.
   *
   * @returns {Promise<Array>} Results of both refreshes
   */
  refreshTokens() {
    return Promise.all([
      this.refreshCSRFToken(),
      this.refreshJWTToken()
    ]);
  },

  // Rate limiting is enforced by the backend (see class header). The former
  // isRateLimited()/getRateLimitStatus() helpers referenced a checkRateLimit()
  // that never existed and were removed as dead, throw-on-call code.

  /**
   * Adds the CSRF header to a request configuration when the request needs it.
   *
   * @param {Object} config - Request configuration; mutated
   * @returns {Object} The same configuration
   */
  addCSRFToRequest(config) {
    if (this.config.csrf.enabled && this.shouldAddCSRF(config) && this.state.csrfToken) {
      config.headers = config.headers || {};
      config.headers[this.config.csrf.headerName] = this.state.csrfToken;
    }
    return config;
  },

  // ============ Cleanup ============
  /**
   * Stops the refresh timers, clears the recorded violations and marks the
   * manager uninitialized.
   *
   * @returns {void}
   */
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
