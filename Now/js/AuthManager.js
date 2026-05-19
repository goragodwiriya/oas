/**
 * AuthManager - Central authentication manager
 * Handles login/logout flows, token refresh, user session state, and security integration.
 */
const AuthManager = {
  // --- Configuration ------------------------------------------------------
  config: {
    enabled: false,
    type: 'jwt-httponly',
    autoInit: true,

    endpoints: {
      login: 'api/v1/auth/login',
      logout: 'api/v1/auth/logout',
      verify: 'api/v1/auth/verify',
      refresh: 'api/v1/auth/refresh',
      me: 'api/v1/auth/me',
      social: 'api/v1/auth/social/{provider}',
      callback: 'api/v1/auth/callback'
    },

    security: {
      csrf: true,
      csrfIncludeSafeMethods: true,
      autoRefresh: true,
      refreshBeforeExpiry: 5 * 60 * 1000, // 5 minutes
      clearAllCachesOnLogout: true, // Clear all caches when logging out
      clearAuthKeysOnLogout: []  // Optional: Additional auth-related keys to remove (merged with defaults)
    },

    redirects: {
      afterLogin: '/',
      afterLogout: '/login',
      unauthorized: '/login',
      forbidden: '/403'
    },

    token: {
      headerName: 'Authorization',
      cookieName: 'auth_token',
      refreshCookieName: 'refresh_token',
      storageKey: 'auth_user',
      cookieOptions: {
        path: '/',
        secure: (typeof window !== 'undefined') ? window.location.protocol === 'https:' : false,
        sameSite: 'Strict'
      },
      cookieMaxAge: null
    }
  },

  // --- Runtime state -----------------------------------------------------
  state: {
    initialized: false,
    authenticated: false,
    user: null,
    loading: false,
    error: null,
    loginAttempts: 0,
    lockedUntil: null,
    refreshTimer: null
  },

  tokenService: null,

  ensureTokenService(force = false) {
    if (!this.config?.token) {
      this.config.token = {
        cookieName: 'auth_token',
        refreshCookieName: 'refresh_token'
      };
    }

    if (!this.tokenService || force) {
      const baseCookieOptions = {
        path: '/',
        secure: (typeof window !== 'undefined') ? (window.location.protocol === 'https:' && !window.location.hostname.includes('localhost')) : false,
        sameSite: 'Lax',
        ...(this.config.token.cookieOptions || {})
      };

      this.tokenService = new TokenService({
        storageMethod: 'cookie',
        cookieName: this.config.token.cookieName,
        refreshCookieName: this.config.token.refreshCookieName,
        cookieOptions: baseCookieOptions
      });
    }

    return this.tokenService;
  },

  buildTokenCookieOptions(token) {
    const cookieOptions = {
      path: '/',
      secure: (typeof window !== 'undefined') ? (window.location.protocol === 'https:' && !window.location.hostname.includes('localhost')) : false,
      sameSite: 'Lax',
      ...(this.config.token.cookieOptions || {})
    };

    const configuredMaxAge = this.config.token.cookieMaxAge;
    let maxAge = (typeof configuredMaxAge === 'number' && configuredMaxAge > 0) ? configuredMaxAge : null;

    try {
      const service = this.ensureTokenService();
      if (!maxAge && token && typeof service?.getTokenExpiry === 'function') {
        const expiry = service.getTokenExpiry(token);
        if (expiry) {
          const secondsRemaining = expiry - Math.floor(Date.now() / 1000);
          if (secondsRemaining > 0) {
            maxAge = secondsRemaining;
          }
        }
      }
    } catch (err) {
      console.warn('Failed to derive cookie maxAge from token expiry', err);
    }

    if (maxAge) {
      cookieOptions.maxAge = Math.max(1, Math.floor(maxAge));
    } else if ('maxAge' in cookieOptions && (cookieOptions.maxAge === null || cookieOptions.maxAge === undefined)) {
      delete cookieOptions.maxAge;
    }

    return cookieOptions;
  },

  rehydrateAccessToken() {
    try {
      const tokenService = this.ensureTokenService();
      const cookieName = this.config.token.cookieName;

      let token = null;

      if (tokenService?.getCookie) {
        token = tokenService.getCookie(cookieName);
      }

      if (!token) {
        if (typeof ApiService?.clearAccessToken === 'function') {
          ApiService.clearAccessToken();
        }
        return null;
      }

      // Check if token is expired
      if (typeof tokenService?.isTokenExpired === 'function') {
        if (tokenService.isTokenExpired(token)) {
          tokenService.remove?.();
          if (typeof ApiService?.clearAccessToken === 'function') {
            ApiService.clearAccessToken();
          }
          return null;
        }
      }

      if (typeof ApiService?.setAccessToken === 'function') {
        ApiService.setAccessToken(token);
      }

      return token;
    } catch (error) {
      console.error('AuthManager: Failed to rehydrate access token', error);
      return null;
    }
  },

  // --- Token helpers ----------------------------------------------------
  getAuthStrategy() {
    const strategy = ApiService?.config?.security?.authStrategy
      || this.config?.security?.authStrategy
      || this.config?.type;
    if (!strategy) {
      return 'hybrid';
    }
    if (['hybrid', 'storage', 'cookie'].includes(strategy)) {
      return strategy;
    }
    // Map legacy type names to strategies
    if (strategy === 'jwt-httponly') {
      return 'hybrid';
    }
    return 'hybrid';
  },

  resolveTokenFromData(data) {
    if (!data || typeof data !== 'object') {
      return null;
    }

    // Only support data.token - no fallbacks
    if (data.data && typeof data.data === 'object' && data.data.token) {
      return data.data.token;
    }

    if (data.token) {
      return data.token;
    }

    console.warn('AuthManager: No token found in response data');
    return null;
  },

  resolveUserFromData(data) {
    if (!data || typeof data !== 'object') {
      return null;
    }

    // Support multiple data structures
    if (data.data && typeof data.data === 'object') {
      // Check for data.data.user first
      if (data.data.user && typeof data.data.user === 'object') {
        return data.data.user;
      }
      // Check if data.data itself contains user fields (id, username, name)
      if (data.data.id && data.data.username) {
        return data.data;
      }
    }

    if (data.user && typeof data.user === 'object') {
      return data.user;
    }

    console.warn('AuthManager: No user found in response data');
    return null;
  },

  extractTokenFromHeaders(headers = {}) {
    if (!headers || typeof headers !== 'object') {
      return null;
    }

    const authorization = headers.authorization || headers.Authorization;
    if (authorization) {
      const parts = authorization.split(' ');
      if (parts.length === 2 && /^Bearer$/i.test(parts[0])) {
        return parts[1].trim();
      }
      return authorization.trim();
    }

    return headers['x-access-token']
      || headers['X-Access-Token']
      || headers['access-token']
      || headers['x-token']
      || headers['X-Token']
      || null;
  },

  /**
   * Try to read a stored refresh token from TokenService (cookie/localStorage)
   * Returns the token string or null when not available.
   */
  getRefreshToken() {
    try {
      const service = this.ensureTokenService();
      const cookieName = (this.config && this.config.token && this.config.token.refreshCookieName)
        || (service && service.options && service.options.refreshCookieName)
        || 'refresh_token';

      if (service && typeof service.getCookie === 'function') {
        const val = service.getCookie(cookieName);
        if (val) return val;
      }

      // fallback: try common localStorage keys
      if (service && service.options && service.options.storageMethod === 'localStorage') {
        try {
          const stored = localStorage.getItem(service.options.localStorageKey || 'auth');
          if (stored) {
            const parsed = JSON.parse(stored);
            if (parsed && parsed.refresh_token) return parsed.refresh_token;
          }
        } catch (e) {
          // ignore
        }
      }
    } catch (e) {
      console.warn('getRefreshToken failed:', e && e.message ? e.message : e);
    }
    return null;
  },

  buildRefreshRequestOptions() {
    const includeCreds = (ApiService?.config?.security?.sendCredentials) ? 'include' : 'same-origin';
    const options = {
      throwOnError: false,
      credentials: includeCreds
    };

    try {
      const csrfToken = this.getCSRFToken?.();
      if (csrfToken) {
        options.headers = {
          ...(options.headers || {}),
          'X-CSRF-TOKEN': csrfToken
        };
      }
    } catch (e) {
      console.warn('Failed to attach CSRF token to refresh request', e);
    }

    return options;
  },

  async fetchAccessToken(options = {}) {
    const {
      fallbackUser = null,
      preferRefresh = true,
      preferVerifyFallback = true
    } = options;

    const attempts = [];
    const includeCreds = ApiService?.config?.security?.sendCredentials ? 'include' : 'same-origin';

    if (preferRefresh && this.config.endpoints?.refresh) {
      attempts.push(async () => {
        const refreshToken = this.getRefreshToken();
        const payload = refreshToken ? {refresh_token: refreshToken} : null;
        const response = await simpleFetch.post(
          this.config.endpoints.refresh,
          payload,
          this.buildRefreshRequestOptions()
        );

        if (response?.success && response.data?.success) {
          const tokenFromData = this.resolveTokenFromData(response.data);
          const tokenFromHeaders = this.extractTokenFromHeaders(response.headers);
          const token = tokenFromData || tokenFromHeaders;
          if (token) {
            const user = this.resolveUserFromData(response.data) || fallbackUser;
            return {token, user};
          }
        }

        return null;
      });
    }

    if (preferVerifyFallback && this.config.endpoints?.verify) {
      attempts.push(async () => {
        const response = await simpleFetch.get(this.config.endpoints.verify, {
          throwOnError: false,
          credentials: includeCreds
        });

        if (response?.success && response.data?.success) {
          const tokenFromData = this.resolveTokenFromData(response.data);
          const tokenFromHeaders = this.extractTokenFromHeaders(response.headers);
          const token = tokenFromData || tokenFromHeaders;
          if (token) {
            const user = this.resolveUserFromData(response.data) || fallbackUser;
            return {token, user};
          }
        }

        return null;
      });
    }

    for (const attempt of attempts) {
      try {
        const result = await attempt();
        if (result?.token) {
          return result;
        }
      } catch (error) {
        console.warn('Token fetch attempt failed:', error);
      }
    }

    return null;
  },

  async ensureAccessToken(tokenCandidate, options = {}) {
    const {user = null} = options;

    if (!tokenCandidate) {
      throw new Error('No access token provided - authentication required');
    }

    // Set in ApiService memory for immediate use
    if (typeof ApiService?.setAccessToken === 'function') {
      ApiService.setAccessToken(tokenCandidate)
    }

    // Store in cookie for persistence across reloads
    try {
      const tokenService = this.ensureTokenService();
      if (tokenService) {
        const cookieOptions = this.buildTokenCookieOptions(tokenCandidate);
        const success = tokenService.store(tokenCandidate, {
          user: user || this.state.user,
          timestamp: Date.now(),
          cookieOptions
        });

        if (!success) {
          console.warn('AuthManager: Failed to store token in cookie');
        }
      }
    } catch (err) {
      console.error('AuthManager: TokenService failed to store token', err);
    }

    return tokenCandidate;
  },

  // --- Lifecycle ---------------------------------------------------------
  isInitialized() {
    return this.state?.initialized === true;
  },

  async init(options = {}) {
    const sharedAuthConfig = (window.Now && Now.DEFAULT_CONFIG && Now.DEFAULT_CONFIG.auth) ? Now.DEFAULT_CONFIG.auth : {};
    const mergedEndpoints = {
      ...(this.config.endpoints || {}),
      ...(sharedAuthConfig.endpoints || {}),
      ...(options.endpoints || {})
    };

    this.config = {
      ...this.config,
      ...sharedAuthConfig,
      ...options,
      endpoints: mergedEndpoints
    };

    if (!this.config.enabled) {
      this.state.initialized = false;
      this.state.loading = false;
      return this;
    }

    // TokenService stores refresh tokens (cookies). Access tokens stay in memory via ApiService for hybrid flow.
    this.ensureTokenService(true);

    // Attempt to restore any previously stored access token before running auth checks
    this.rehydrateAccessToken();

    // Ensure ApiService will send Authorization headers
    if (window.ApiService && typeof ApiService.config === 'object') {
      ApiService.config.security = ApiService.config.security || {};
      ApiService.config.security.bearerAuth = true;
      ApiService.config.security.authStrategy = 'hybrid';
    }

    if (window.SecurityManager) {
      this.setupSecurityIntegration();
    }

    try {
      this.state.loading = true;
      this.state.error = null;

      this.setupHttpInterceptors();

      await this.checkAuthStatus();

      if (this.config.security.autoRefresh) {
        this.setupAutoRefresh();
      }

      this.state.initialized = true;
      this.emit('auth:initialized', {
        authenticated: this.state.authenticated,
        user: this.state.user
      });

      return this;
    } catch (error) {
      this.state.error = error;
      this.handleError('Auth initialization failed', error);
      throw error;
    } finally {
      this.state.loading = false;
    }
  },

  // --- HTTP integration --------------------------------------------------
  setupHttpInterceptors() {
    if (!window.http || !window.http.addRequestInterceptor) {
      return;
    }

    http.addRequestInterceptor(async (config) => {
      if (!this.config.security.csrf) {
        return config;
      }

      const method = (config.method || 'GET').toUpperCase();
      const safeMethods = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];
      const includeSafe = this.config.security.csrfIncludeSafeMethods !== false;
      const needsToken = includeSafe || !safeMethods.includes(method);

      if (needsToken) {
        const csrfToken = this.getCSRFToken();
        if (csrfToken) {
          const isHeadersInstance = (typeof Headers !== 'undefined') && (config.headers instanceof Headers);
          const existingHeaders = isHeadersInstance ? Object.fromEntries(config.headers.entries()) : (config.headers || {});
          config.headers = {
            ...existingHeaders,
            'X-CSRF-Token': csrfToken
          };
        }
      }

      return config;
    });

    http.addResponseInterceptor(
      (response) => {
        const newCsrfToken = response.headers?.['X-CSRF-Token'] || response.headers?.get?.('X-CSRF-Token');
        if (newCsrfToken) {
          this.updateCSRFToken(newCsrfToken);
        }
        return response;
      },
      async (errorOrResponse) => {
        // Handle both error objects and response objects
        const status = errorOrResponse.status;
        const originalRequest = errorOrResponse.config;

        if (status === 401 && !originalRequest?._retry) {
          if (originalRequest) {
            originalRequest._retry = true;
          }

          if (this.config.security.autoRefresh) {
            const refreshed = await this.refreshToken();
            if (refreshed && originalRequest) {
              return http.request(originalRequest.url, originalRequest);
            }
          }

          // Perform logout and redirect
          await this.logout(false);
          this.redirectTo(this.config.redirects.unauthorized);

          // Return the response to prevent further processing
          return errorOrResponse;
        }

        if (status === 403) {
          this.redirectTo(this.config.redirects.forbidden);
          return errorOrResponse;
        }

        // For other errors, throw or return as-is
        if (errorOrResponse.error) {
          throw errorOrResponse;
        }

        return errorOrResponse;
      }
    );
  },


  // --- Authentication state ---------------------------------------------
  async checkAuthStatus() {
    try {
      // Always rehydrate token before checking
      const rehydratedToken = this.rehydrateAccessToken();

      const includeCreds = (window.ApiService && ApiService.config?.security?.sendCredentials)
        ? 'include'
        : 'same-origin';

      const response = await simpleFetch.get(this.config.endpoints.verify, {
        throwOnError: false,
        credentials: includeCreds
      });

      if (response?.success && response.data?.success) {
        const resolvedUser = this.resolveUserFromData(response.data);

        if (!resolvedUser) {
          throw new Error('No user data in verify response');
        }

        this.state.authenticated = true;
        this.state.user = resolvedUser;

        // Store user data
        if (this.state.user) {
          try {
            localStorage.setItem(
              this.config.token.storageKey,
              JSON.stringify({
                ...this.state.user,
                timestamp: Date.now()
              })
            );
          } catch (e) {
            console.warn('AuthManager: Failed to persist authenticated user', e);
          }
        }

        return {
          authenticated: true,
          user: this.state.user,
          token: rehydratedToken
        };
      }

      this.clearAuthData();
      return {
        authenticated: false,
        user: null,
        error: response?.data?.message || response?.statusText || 'Authentication failed'
      };
    } catch (error) {
      this.clearAuthData();
      return {
        authenticated: false,
        user: null,
        error: error && error.message ? error.message : error
      };
    }
  },

  clearAuthData() {
    this.state.authenticated = false;
    this.state.user = null;
    try {
      localStorage.removeItem(this.config.token.storageKey);
    } catch (e) {
      console.warn('Failed to clear cached user state', e);
    }

    try {
      localStorage.removeItem('auth_token');
    } catch (e) {
      // Ignore storage errors
    }

    if (typeof ApiService?.clearAccessToken === 'function') {
      ApiService.clearAccessToken();
    }

    try {
      const tokenService = this.ensureTokenService();
      tokenService?.clear();
    } catch (e) {
      console.warn('Failed to clear token service state', e);
    }
  },

  /**
   * Clear all caches across the application
   * Strategy: Remove only AUTH-RELATED keys from localStorage
   * Default auth keys are always removed, custom keys are merged
   */
  async clearAllCaches() {
    try {
      // 1. Clear API Service cache
      if (window.ApiService && typeof ApiService.clearCache === 'function') {
        ApiService.clearCache();
      }

      // 2. Clear ONLY auth-related localStorage keys
      if (window.StorageManager || window.localStorage) {
        // Default auth keys (always removed for security)
        const defaultAuthKeys = [
          'auth_user',           // User data
          'auth_token',          // Access token
          'refresh_token',       // Refresh token
          'auth_session',        // Session data
          'user_session',        // User session
          'login_data'           // Login data
        ];

        // Custom auth keys from config (optional)
        const customAuthKeys = Array.isArray(this.config.security.clearAuthKeysOnLogout)
          ? this.config.security.clearAuthKeysOnLogout
          : [];

        // Merge default + custom (remove duplicates)
        const allAuthKeys = [...new Set([...defaultAuthKeys, ...customAuthKeys])];

        // Remove all auth-related keys
        allAuthKeys.forEach(key => {
          try {
            localStorage.removeItem(key);
          } catch (e) {
            console.warn(`Failed to remove ${key}:`, e);
          }
        });

        // Also remove storageKey from config
        if (this.config.token?.storageKey) {
          try {
            localStorage.removeItem(this.config.token.storageKey);
          } catch (e) {
            console.warn(`Failed to remove ${this.config.token.storageKey}:`, e);
          }
        }

        // Clear sessionStorage (safe - session data only)
        if (typeof StorageManager?.session?.clear === 'function') {
          StorageManager.session.clear();
        } else {
          try {
            sessionStorage.clear();
          } catch (e) {
            console.warn('Failed to clear sessionStorage:', e);
          }
        }

        // Clear memory cache
        if (typeof StorageManager?.memory?.clear === 'function') {
          StorageManager.memory.clear();
        }

        // Clear StorageManager internal cache
        if (typeof StorageManager?.clearCache === 'function') {
          StorageManager.clearCache();
        }
      }

      // 3. Clear TemplateManager cache
      if (window.TemplateManager && typeof TemplateManager.clearCache === 'function') {
        TemplateManager.clearCache();
      }

      // 4. Clear ApiComponent cache
      if (window.ApiComponent && typeof ApiComponent.clearCache === 'function') {
        ApiComponent.clearCache();
      }

      // 5. Clear AuthGuard cache
      if (window.RouterManager?.authGuard && typeof RouterManager.authGuard.clearCache === 'function') {
        RouterManager.authGuard.clearCache();
      }

      // 6. Clear ServiceWorker cache
      if (window.ServiceWorkerManager && typeof ServiceWorkerManager.clearCache === 'function') {
        await ServiceWorkerManager.clearCache();
      }

      // 7. Clear browser caches (Cache API)
      if (window.caches) {
        const cacheNames = await caches.keys();
        await Promise.all(cacheNames.map(name => caches.delete(name)));
      }

      // 8. Session storage already cleared above
      try {
        sessionStorage.clear();
      } catch (e) {
        console.warn('Failed to clear sessionStorage:', e);
      }

      return true;
    } catch (error) {
      console.error('[AuthManager] Error clearing caches:', error);
      return false;
    }
  },

  async handleAuthCheckError(error) {
    console.warn('Auth check failed:', error.message);
    this.clearAuthData();
    return {
      authenticated: false,
      user: null,
      error: error.message
    };
  },

  setupSecurityIntegration() {
    document.addEventListener('jwt:refreshed', (event) => {
      this.handleJWTRefresh?.(event.detail);
    });

    document.addEventListener('security:error', (event) => {
      this.handleSecurityError?.(event.detail);
    });
  },

  handleSecurityError(detail) {
    try {
      const info = detail || {};
      const message = info.message || info.error || 'A security error occurred';

      this.handleError('Security error', new Error(message));

      if (info.status === 401 || info.code === 'unauthorized' || info.code === 'csrf_invalid') {
        this.clearAuthData();
        const redirectTo = this.config.redirects?.unauthorized || '/login';
        this.redirectTo(redirectTo);
      }
    } catch (err) {
      console.error('Error handling security event', err);
    }
  },

  async authenticate(credentials, options = {}) {
    try {
      // Basic validation
      if (!credentials.email || !credentials.password) {
        throw new Error('Email and password are required');
      }

      if (credentials.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(credentials.email)) {
        throw new Error('Invalid email format');
      }

      if (credentials.password && credentials.password.length < 8) {
        throw new Error('Password must be at least 8 characters');
      }

      this.state.loading = true;
      this.state.error = null;

      const response = await simpleFetch.post(this.config.endpoints.login, credentials, {
        throwOnError: false,
        ...(options.fetchOptions || {})
      });

      if (response.success && response.data?.success) {
        const headerToken = this.extractTokenFromHeaders(response.headers);
        const resolvedToken = this.resolveTokenFromData(response.data) || headerToken;
        if (resolvedToken && !response.data.token) {
          response.data.token = resolvedToken;
        }
        const resolvedUser = this.resolveUserFromData(response.data);
        if (resolvedUser && !response.data.user) {
          response.data.user = resolvedUser;
        }
        return response.data;
      }

      const errorMessage = response.data?.message || response.statusText || 'Authentication failed';
      return {
        success: false,
        message: errorMessage,
        errors: response.data?.errors || {},
        status: response.status
      };
    } catch (error) {
      return {
        success: false,
        message: error.message || 'Authentication failed',
        error
      };
    } finally {
      this.state.loading = false;
    }
  },

  async setAuthenticatedUser(userData, options = {}) {
    try {
      const user = this.resolveUserFromData(userData);
      const token = this.resolveTokenFromData(userData);

      if (!user) {
        throw new Error('No user data found in authentication response');
      }

      if (!token) {
        throw new Error('No access token found in authentication response');
      }

      this.state.authenticated = true;
      this.state.user = user;
      this.state.error = null;

      // Store token in memory and cookie
      const ensuredToken = await this.ensureAccessToken(token, {user});

      // Store user data for persistence
      const userDataToStore = {
        ...user,
        timestamp: Date.now()
      };

      try {
        localStorage.setItem(
          this.config.token.storageKey,
          JSON.stringify(userDataToStore)
        );
      } catch (e) {
        console.warn('AuthManager: Failed to persist user profile', e);
      }

      if (this.config.security.autoRefresh) {
        this.setupAutoRefresh();
      }

      this.emit('auth:login', {
        user: this.state.user,
        timestamp: Date.now()
      });

      return {
        success: true,
        user: this.state.user,
        authenticated: true,
        token: ensuredToken
      };
    } catch (error) {
      console.error('AuthManager: Failed to set authenticated user:', error.message);
      this.state.error = error;
      return {
        success: false,
        message: error.message,
        error
      };
    }
  },

  async login(credentials, options = {}) {
    try {
      const authResult = await this.authenticate(credentials, options);
      if (!authResult.success) {
        return authResult;
      }

      const setUserResult = await this.setAuthenticatedUser(authResult, {
        ensureOptions: options.ensureOptions
      });
      if (!setUserResult.success) {
        return setUserResult;
      }

      if (!options.preventRedirect) {
        // Force deterministic post-login landing route.
        const redirectTo = options.redirectTo || this.config.redirects.afterLogin || '/';
        try {
          sessionStorage.removeItem('intended_url');
          sessionStorage.removeItem('auth_intended_route');
        } catch (e) {}
        this.redirectTo(redirectTo);
      }

      return {
        success: true,
        user: this.state.user,
        authenticated: true,
        ...authResult,
        token: setUserResult.token || authResult.token || null
      };
    } catch (error) {
      this.handleError('Login failed', error);
      return {
        success: false,
        message: error.message || 'Login failed',
        error
      };
    }
  },

  async logout(callServer = true, options = {}) {
    try {
      this.state.loading = true;

      if (this.state.refreshTimer) {
        clearTimeout(this.state.refreshTimer);
        this.state.refreshTimer = null;
      }

      if (callServer) {
        try {
          const includeCreds = (ApiService?.config?.security?.sendCredentials) ? 'include' : 'same-origin';
          let response;

          if (window.ApiService && typeof ApiService.post === 'function') {
            response = await ApiService.post(this.config.endpoints.logout, null, {
              credentials: includeCreds
            });
          } else {
            throw new Error('No HTTP client available for logout request');
          }

          // Check if admin session was restored (impersonation ended)
          // Support wrapped response: { success, message, code, data: { ... } }
          const payload = response?.data?.data ?? response?.data ?? response;
          if (payload?.restored === true) {
            this.state.loading = false;
            this.emit('auth:restored', {user: payload.user, timestamp: Date.now()});

            if (window.ResponseHandler?.process) {
              await ResponseHandler.process(payload, {
                reload: () => window.location.reload()
              });
            }

            const restoreUrl = payload.actions?.find(a => a.type === 'redirect')?.url || '/';
            this.redirectTo(restoreUrl);
            return;
          }
        } catch (error) {
          console.warn('Logout API failed:', error.message);
        }
      }

      this.state.authenticated = false;
      this.state.user = null;
      this.state.error = null;

      // Clear all caches if enabled (default: true)
      const shouldClearAllCaches = options.clearAllCaches !== false &&
        this.config.security.clearAllCachesOnLogout !== false;
      if (shouldClearAllCaches) {
        await this.clearAllCaches();
      }

      this.tokenService?.clear();

      try {
        localStorage.removeItem(this.config.token.storageKey);
      } catch (e) {
        console.warn('Failed to clear cached user data', e);
      }

      try {
        localStorage.removeItem('auth_token');
        if (typeof ApiService?.clearAccessToken === 'function') {
          ApiService.clearAccessToken();
        }
      } catch (e) {
        console.warn('Failed to clear access token', e);
      }

      this.emit('auth:logout', {timestamp: Date.now()});

      if (!options.preventRedirect) {
        const redirectTo = options.redirectTo || this.config.redirects.afterLogout;
        this.redirectTo(redirectTo);
      }
    } catch (error) {
      this.handleError('Logout failed', error);
    } finally {
      this.state.loading = false;
    }
  },

  async refreshToken() {
    try {
      const refreshToken = this.getRefreshToken();
      const payload = refreshToken ? {refresh_token: refreshToken} : null;
      const response = await simpleFetch.post(
        this.config.endpoints.refresh,
        payload,
        this.buildRefreshRequestOptions()
      );

      if (response?.data?.success) {
        const tokenFromData = this.resolveTokenFromData(response.data);
        const tokenFromHeaders = this.extractTokenFromHeaders(response.headers);
        const ensuredToken = await this.ensureAccessToken(tokenFromData || tokenFromHeaders, {
          user: response.data.user || this.state.user,
          allowFallback: false,
          preferVerifyFallback: false
        });

        if (response.data.user) {
          this.state.user = response.data.user;
        }

        if (this.state.user) {
          try {
            localStorage.setItem(
              this.config.token.storageKey,
              JSON.stringify({
                ...this.state.user,
                timestamp: Date.now()
              })
            );
          } catch (e) {
            console.warn('Failed to persist refreshed user', e);
          }
        }

        this.setupAutoRefresh();
        this.emit('auth:token_refreshed', {
          user: this.state.user,
          token: ensuredToken || tokenFromData || tokenFromHeaders || null,
          timestamp: Date.now()
        });

        return true;
      }

      return false;
    } catch (error) {
      console.warn('Token refresh failed:', error.message || error);
      return false;
    }
  },

  setupAutoRefresh() {
    if (this.state.refreshTimer) {
      clearTimeout(this.state.refreshTimer);
    }

    const refreshInterval = this.config.security.refreshBeforeExpiry;
    this.state.refreshTimer = setTimeout(async () => {
      if (this.state.authenticated) {
        await this.refreshToken();
      }
    }, refreshInterval);
  },

  hasPermission(permission) {
    if (!this.state.authenticated || !this.state.user) {
      return false;
    }

    const userPermissions = Array.isArray(this.state.user.permission) ? this.state.user.permission : [];
    return userPermissions.includes(permission) || userPermissions.includes('*');
  },

  hasRole(role) {
    if (!this.state.authenticated || !this.state.user) {
      return false;
    }

    const userRoles = this.state.user.roles || [];
    return userRoles.includes(role) || userRoles.includes('admin');
  },

  requireAuth() {
    if (!this.state.authenticated) {
      this.saveIntendedUrl();
      this.redirectTo(this.config.redirects.unauthorized);
      return false;
    }
    return true;
  },

  requireGuest() {
    if (this.state.authenticated) {
      this.redirectTo(this.config.redirects.afterLogin);
      return false;
    }
    return true;
  },

  saveIntendedUrl(url = null) {
    const authPages = ['/login', '/register', '/forgot-password'];

    // Normalize path by stripping router base so redirects stay within SPA base
    const normalizePath = (pathname) => {
      const routerBase = window.RouterManager?.config?.base || '';
      let normalized = pathname || '/';
      if (routerBase && normalized.startsWith(routerBase)) {
        normalized = normalized.slice(routerBase.length) || '/';
        if (!normalized.startsWith('/')) normalized = '/' + normalized;
      }
      return normalized;
    };

    if (!authPages.includes(window.location.pathname)) {
      const normalizedPath = normalizePath(window.location.pathname);
      const intendedRoute = {
        path: normalizedPath,
        query: window.location.search,
        hash: window.location.hash,
        timestamp: Date.now()
      };

      // Use auth_intended_route to match AuthGuard/RedirectManager format
      sessionStorage.setItem('auth_intended_route', JSON.stringify(intendedRoute));

      // Also keep legacy intended_url for backward compatibility
      const intendedUrl = url || `${normalizedPath}${window.location.search}`;
      sessionStorage.setItem('intended_url', intendedUrl);
    }
  },

  getIntendedUrl() {
    const url = sessionStorage.getItem('intended_url');
    sessionStorage.removeItem('intended_url');
    return url;
  },

  redirectTo(url) {
    if (url === '/login' || url.includes('/login')) {
      const currentUrl = `${window.location.pathname}${window.location.search}`;
      const authPages = ['/login', '/register', '/forgot-password'];

      if (!authPages.includes(window.location.pathname)) {
        const separator = url.includes('?') ? '&' : '?';
        url = `${url}${separator}redirect=${encodeURIComponent(currentUrl)}`;
      }
    }

    if (window.RouterManager?.state?.initialized) {
      RouterManager.navigate(url);
    } else {
      window.location.href = url;
    }
  },

  updateCSRFToken(token) {
    let metaToken = document.querySelector('meta[name="csrf-token"]');
    if (!metaToken) {
      metaToken = document.createElement('meta');
      metaToken.name = 'csrf-token';
      document.head.appendChild(metaToken);
    }
    metaToken.setAttribute('content', token);
  },

  // Read CSRF token from meta tag or cookie fallback.
  getCSRFToken() {
    try {
      const meta = document.querySelector('meta[name="csrf-token"]');
      if (meta) {
        return meta.getAttribute('content');
      }

      const match = document.cookie.match(/(^|;)\s*XSRF-TOKEN=([^;]+)/);
      return match ? decodeURIComponent(match[2]) : null;
    } catch (e) {
      console.warn('Failed to resolve CSRF token', e);
      return null;
    }
  },

  emit(eventName, data = {}) {
    EventManager.emit(eventName, data);

    const event = new CustomEvent(eventName, {
      detail: data,
      bubbles: true,
      cancelable: true
    });

    document.dispatchEvent(event);
  },

  handleError(message, error) {
    this.emit('auth:error', {
      message,
      error: error.message,
      timestamp: Date.now()
    });

    if (window.NotificationManager) {
      NotificationManager.error(error.message || message);
    }
  },

  getUser() {
    return this.state.user;
  },

  isAuthenticated() {
    return this.state.authenticated;
  },

  getUserId() {
    return this.state.user?.id ?? null;
  },

  getRoles() {
    return [];
  },

  getPermissions() {
    return Array.isArray(this.state.user?.permission) ? this.state.user.permission : [];
  },

  async verifyAuthState() {
    try {
      await this.checkAuthStatus();
      return this.state.authenticated;
    } catch {
      return false;
    }
  },

  isLoading() {
    return this.state.loading;
  },

  getError() {
    return this.state.error;
  },

  clearError() {
    this.state.error = null;
  },

  cleanup() {
    if (this.state.refreshTimer) {
      clearTimeout(this.state.refreshTimer);
      this.state.refreshTimer = null;
    }

    this.state.initialized = false;
  },

  async socialLogin(provider, options = {}) {
    try {
      this.state.loading = true;
      this.state.error = null;

      this.emit('auth:social_login_start', {provider});

      const endpointTemplate = this.config.endpoints.social || 'api/auth/social/{provider}';
      const endpoint = (options.endpoint || endpointTemplate).replace('{provider}', provider);

      if (options.popup) {
        return await this.handleSocialPopup(provider, endpoint, options);
      }

      this.redirectTo(`${endpoint}?redirect=${encodeURIComponent(window.location.href)}`);
      return {
        success: true,
        method: 'redirect'
      };
    } catch (error) {
      this.handleError(`Social login (${provider}) failed`, error);
      return {
        success: false,
        message: error.message || `Social login (${provider}) failed`,
        error
      };
    } finally {
      this.state.loading = false;
    }
  },

  async handleSocialPopup(provider, endpoint, options = {}) {
    return new Promise((resolve, reject) => {
      const popup = window.open(
        endpoint,
        `social-login-${provider}`,
        'width=600,height=600,scrollbars=yes,resizable=yes'
      );

      if (!popup) {
        reject(new Error('Unable to open social login popup'));
        return;
      }

      const checkClosed = setInterval(() => {
        if (popup.closed) {
          cleanup();
          reject(new Error('Social login popup was closed'));
        }
      }, 1000);

      const cleanup = () => {
        clearInterval(checkClosed);
        window.removeEventListener('message', messageHandler);
        if (!popup.closed) {
          popup.close();
        }
      };

      function messageHandler(event) {
        if (event.origin !== window.location.origin) {
          return;
        }

        if (event.data?.type === 'social-login-success') {
          cleanup();
          AuthManager.setAuthenticatedUser(event.data.userData)
            .then(resolve)
            .catch(reject);
        } else if (event.data?.type === 'social-login-error') {
          cleanup();
          reject(new Error(event.data.message || 'Social login failed'));
        }
      }

      window.addEventListener('message', messageHandler);
    });
  },

  async handleAuthCallback(callbackData) {
    try {
      if (callbackData.error) {
        throw new Error(callbackData.error_description || callbackData.error);
      }

      if (callbackData.code || callbackData.token) {
        const response = await simpleFetch.post(this.config.endpoints.callback, callbackData);
        if (response.success && response.data?.success) {
          return await this.setAuthenticatedUser(response.data);
        }
        throw new Error(response.data?.message || 'Auth callback failed');
      }

      if (callbackData.user) {
        return await this.setAuthenticatedUser(callbackData);
      }

      throw new Error('Invalid callback data');
    } catch (error) {
      this.handleError('Auth callback failed', error);
      return {
        success: false,
        message: error.message,
        error
      };
    }
  },

  async loginWithToken(token, options = {}) {
    try {
      this.state.loading = true;
      this.state.error = null;

      const response = await simpleFetch.get(this.config.endpoints.me, {
        headers: {
          Authorization: `Bearer ${token}`
        },
        throwOnError: false
      });

      if (response.success && response.data?.success && response.data.user) {
        return await this.setAuthenticatedUser({
          user: response.data.user,
          token
        });
      }

      throw new Error('Invalid or expired token');
    } catch (error) {
      this.handleError('Token login failed', error);
      return {
        success: false,
        message: error.message || 'Token login failed',
        error
      };
    } finally {
      this.state.loading = false;
    }
  }
};

if (window.Now?.registerManager) {
  Now.registerManager('auth', AuthManager);
}

window.AuthManager = AuthManager;

// Ensure a CSRF meta tag exists so subsequent writes succeed.
let metaToken = document.querySelector('meta[name="csrf-token"]');
if (!metaToken) {
  metaToken = document.createElement('meta');
  metaToken.name = 'csrf-token';
  document.head.appendChild(metaToken);
}
