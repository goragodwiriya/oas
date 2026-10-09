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

  /**
   * Returns the TokenService, creating it on first use with the cookie options
   * this manager is configured with.
   *
   * secure is off on localhost so development over http still works.
   *
   * @param {boolean} [force=false] - Rebuild the service even if one exists
   * @returns {Object} The token service
   */
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

  /**
   * Builds the cookie options for storing a token, deriving maxAge from the
   * configured value or, failing that, from the expiry inside the token.
   *
   * @param {string} token - Token whose expiry may set the lifetime
   * @returns {Object} Cookie options, with maxAge omitted when unknown
   */
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

  /**
   * Clears any access token ApiService still holds in memory.
   *
   * There is nothing to restore under cookie-based auth: the access token is an
   * httpOnly cookie JavaScript cannot read, so the session is recovered from
   * the server through checkAuthStatus() instead.
   *
   * @returns {null} Always null
   */
  rehydrateAccessToken() {
    // No-op under cookie-based auth: the access token is an httpOnly cookie that
    // JavaScript cannot (and must not) read. Session state is restored from the
    // server via checkAuthStatus() -> /verify, which relies on that cookie.
    try {
      if (typeof ApiService?.clearAccessToken === 'function') {
        ApiService.clearAccessToken();
      }
    } catch (error) {
      // ignore
    }
    return null;
  },

  /**
   * Resolves the auth strategy in force, from ApiService, then this config,
   * then the configured type.
   *
   * Unknown values and the legacy 'jwt-httponly' both map to 'hybrid'.
   *
   * @returns {string} 'hybrid', 'storage' or 'cookie'
   */
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

  /**
   * Pulls the access token out of a response body, accepting it at data.token
   * or data.data.token.
   *
   * @param {Object} data - Response body
   * @returns {string|null} Token, or null when the body carries none
   */
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

  /**
   * Pulls the user object out of a response body, accepting data.user,
   * data.data.user, or a data.data that carries the user fields itself.
   *
   * @param {Object} data - Response body
   * @returns {Object|null} User, or null when the body carries none
   */
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

  /**
   * Reads an access token from response headers: the Authorization header with
   * or without the Bearer prefix, or one of the x-access-token variants.
   *
   * @param {Object} [headers={}] - Response headers
   * @returns {string|null} Token, or null when no header carries one
   */
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

  /**
   * Builds the fetch options for a refresh request: the credentials mode
   * ApiService is configured for, and the CSRF header when a token is available.
   *
   * @returns {Object} Fetch options
   */
  buildRefreshRequestOptions() {
    const includeCreds = (ApiService?.config?.security?.sendCredentials) ? 'include' : 'same-origin';
    const options = {
      throwOnError: false,
      credentials: includeCreds
    };

    try {
      const csrfToken = this.getCSRFToken?.();
      if (csrfToken) {
        // Same spelling as simpleFetch's own header: it merges these first and
        // a differently-cased duplicate reaches the server as "tok, tok".
        options.headers = {
          ...(options.headers || {}),
          'X-CSRF-Token': csrfToken
        };
      }
    } catch (e) {
      console.warn('Failed to attach CSRF token to refresh request', e);
    }

    return options;
  },

  /**
   * Obtains an access token by trying the refresh endpoint, then the verify
   * endpoint, and returns the first attempt that yields one.
   *
   * A failing attempt is logged and the next one is tried.
   *
   * @param {Object} [options={}] - Fetch options
   * @param {Object|null} [options.fallbackUser=null] - User to return when the
   *   response carries none
   * @param {boolean} [options.preferRefresh=true] - Try the refresh endpoint
   * @param {boolean} [options.preferVerifyFallback=true] - Try the verify endpoint
   * @returns {Promise<Object|null>} {token, user}, or null when every attempt failed
   */
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

  /**
   * Reports whether init() has completed.
   *
   * @returns {boolean} True when the manager is initialized
   */
  isInitialized() {
    return this.state?.initialized === true;
  },

  /**
   * Initializes authentication: merges the config with the shared Now.js auth
   * settings, prepares the token service, tells ApiService to rely on the
   * httpOnly cookie rather than an Authorization header, installs the HTTP
   * interceptors, checks the current session and starts the refresh timer.
   *
   * Returns early when auth is disabled.
   *
   * @param {Object} [options={}] - Configuration merged over the defaults
   * @returns {Promise<Object>} The manager instance
   * @throws {Error} When initialization fails
   */
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

    // Cookie-based auth: the token lives only in the server-set httpOnly cookie
    // (auto-sent same-origin). ApiService must NOT attach an Authorization header
    // or read the token from JS storage.
    if (window.ApiService && typeof ApiService.config === 'object') {
      ApiService.config.security = ApiService.config.security || {};
      ApiService.config.security.bearerAuth = false;
      ApiService.config.security.authStrategy = 'cookie';
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

  /**
   * Installs the auth interceptors on the HTTP client.
   *
   * Requests get the CSRF header; responses adopt a rotated token. A 401
   * triggers one refresh-and-retry, then a logout and a redirect to the login
   * route through RedirectManager, and a 403 redirects to the forbidden route.
   *
   * @returns {void}
   */
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

          // Session no longer valid: clear it (without its own redirect) and send
          // the user to login through the single redirect authority, which stores
          // the current route so they return here after re-authenticating.
          await this.logout(false, {preventRedirect: true});
          if (window.RedirectManager?.requireAuth) {
            await RedirectManager.requireAuth();
          } else {
            this.redirectTo(this.config.redirects.unauthorized);
          }

          // Return the response to prevent further processing
          return errorOrResponse;
        }

        if (status === 403) {
          if (window.RedirectManager?.forbidden) {
            await RedirectManager.forbidden();
          } else {
            this.redirectTo(this.config.redirects.forbidden);
          }
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

  /**
   * Asks the verify endpoint who the current user is and updates the session
   * state, caching the user profile in local storage for display.
   *
   * A rejection by the server clears the session rather than leaving it
   * half-set. An unreachable server does not: the cached profile is restored
   * instead and the result carries offline:true, so that losing the connection
   * does not throw the user back to the login page. See restoreCachedUser().
   *
   * @returns {Promise<Object>} {authenticated, user}, plus offline:true when
   *                            answered from cache, or error when it failed
   */
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

      // Could not reach the server, which is not the same as being rejected
      if (this.isUnreachable(response)) {
        const cached = this.restoreCachedUser();
        if (cached) {
          return {authenticated: true, user: cached, offline: true};
        }
      }

      this.clearAuthData();
      return {
        authenticated: false,
        user: null,
        error: response?.data?.message || response?.statusText || 'Authentication failed'
      };
    } catch (error) {
      const cached = navigator.onLine === false ? this.restoreCachedUser() : null;
      if (cached) {
        return {authenticated: true, user: cached, offline: true};
      }
      this.clearAuthData();
      return {
        authenticated: false,
        user: null,
        error: error && error.message ? error.message : error
      };
    }
  },

  /**
   * Tell "the server could not be reached" apart from "the server said no".
   *
   * simpleFetch returns status 0 when the fetch itself fails (offline, DNS
   * failure and the like) and 408 on timeout. A genuine rejection always
   * carries its own HTTP status (401/403).
   *
   * @param {Object} response - Result of simpleFetch
   * @returns {boolean} True when the server was unreachable
   */
  isUnreachable(response) {
    if (navigator.onLine === false) {
      return true;
    }
    const status = response && typeof response.status !== 'undefined' ? response.status : null;
    return status === 0 || status === 408;
  },

  /**
   * Return the cached user profile and keep the session marked authenticated.
   *
   * Used ONLY when the server could not be reached, so that a dropped
   * connection does not throw the user back to the login page - which would
   * make an offline-capable app unusable.
   *
   * Trade-off worth knowing: if the session is revoked server-side while the
   * device is offline, the UI keeps showing the user as logged in until it is
   * back online. Nothing can be read or written in that state, because every
   * API call is still authorised by the server as usual - only the screen is
   * stale. The next successful verify clears the session.
   *
   * @returns {Object|null} The cached user, or null when there is none
   */
  restoreCachedUser() {
    let cached = null;
    try {
      const raw = localStorage.getItem(this.config.token.storageKey);
      cached = raw ? JSON.parse(raw) : null;
    } catch (e) {
      cached = null;
    }
    if (!cached || typeof cached !== 'object' || !cached.id) {
      return null;
    }
    this.state.authenticated = true;
    this.state.user = cached;
    return cached;
  },

  /**
   * Drops the local session: the state, the cached user profile, the access
   * token ApiService holds, and everything the token service stores.
   *
   * @returns {void}
   */
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

  /**
   * Turns a failed auth check into an unauthenticated result and clears the
   * session.
   *
   * @param {Error} error - Error from the check
   * @returns {Promise<Object>} {authenticated: false, user: null, error}
   */
  async handleAuthCheckError(error) {
    console.warn('Auth check failed:', error.message);
    this.clearAuthData();
    return {
      authenticated: false,
      user: null,
      error: error.message
    };
  },

  /**
   * Listens for the events SecurityManager emits, so a refreshed JWT or a
   * security error reaches this manager.
   *
   * The jwt:refreshed listener calls handleJWTRefresh, which this manager does
   * not define; the optional call makes that a no-op rather than an error.
   *
   * @returns {void}
   */
  setupSecurityIntegration() {
    document.addEventListener('jwt:refreshed', (event) => {
      this.handleJWTRefresh?.(event.detail);
    });

    document.addEventListener('security:error', (event) => {
      this.handleSecurityError?.(event.detail);
    });
  },

  /**
   * Handles a security error: reports it, and on an unauthorized or invalid
   * CSRF result clears the session and redirects to the login route.
   *
   * @param {Object} detail - Event detail from SecurityManager
   * @returns {void}
   */
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

  /**
   * Posts credentials to the login endpoint and normalizes the response so the
   * token and user sit where the caller expects them.
   *
   * Validates the email format and the password length before sending. Never
   * throws: failures come back as a result with success false.
   *
   * @param {Object} credentials - Login credentials
   * @param {string} credentials.email - Email address
   * @param {string} credentials.password - Password, at least 8 characters
   * @param {Object} [options={}] - Request options
   * @param {Object} [options.fetchOptions] - Extra options for the fetch
   * @returns {Promise<Object>} Response body, or {success: false, message}
   */
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

  /**
   * Puts the manager into the authenticated state for a user, caches the
   * profile for display, starts the refresh timer and emits auth:login.
   *
   * No token is stored: the server delivered it as an httpOnly cookie, and the
   * browser sends it back on its own.
   *
   * @param {Object} userData - Response body carrying the user
   * @param {Object} [options={}] - Reserved for callers passing ensureOptions
   * @returns {Promise<Object>} {success, user, authenticated} or {success: false, message}
   */
  async setAuthenticatedUser(userData, options = {}) {
    try {
      const user = this.resolveUserFromData(userData);

      if (!user) {
        throw new Error('No user data found in authentication response');
      }

      this.state.authenticated = true;
      this.state.user = user;
      this.state.error = null;

      // The access token is delivered only as an httpOnly cookie by the server —
      // there is nothing to store client-side. Authentication is proven by the
      // presence of a valid `user` plus the cookie the browser now holds.

      // Store user data for persistence (non-sensitive display cache only)
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
        token: null
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

  /**
   * Full login flow: authenticate, set the session, then redirect through
   * RedirectManager so the user lands on the route they were heading for.
   *
   * @param {Object} credentials - Login credentials
   * @param {Object} [options={}] - Login options
   * @param {boolean} [options.preventRedirect] - Stay on the current page
   * @param {string} [options.redirectTo] - Explicit destination
   * @returns {Promise<Object>} {success, user, authenticated} or {success: false, message}
   */
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
        // Post-login redirect via the single authority (intended route, else the
        // app's configured home; correct for SPA and normal modes). Falls back
        // to redirectTo if RedirectManager is unavailable.
        if (window.RedirectManager?.afterLogin) {
          await RedirectManager.afterLogin(options.redirectTo ? {target: options.redirectTo, checkIntended: false} : {});
        } else {
          this.redirectTo(options.redirectTo || this.config.redirects.afterLogin || '/');
        }
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

  /**
   * Logs the user out: stops the refresh timer, tells the server, clears the
   * caches and the local session, emits auth:logout and redirects.
   *
   * When the server reports that an impersonation ended, the restored admin
   * session is kept and the response's own redirect is followed instead.
   *
   * @param {boolean} [callServer=true] - Call the logout endpoint
   * @param {Object} [options={}] - Logout options
   * @param {boolean} [options.preventRedirect] - Stay on the current page
   * @param {string} [options.redirectTo] - Explicit destination
   * @param {boolean} [options.clearAllCaches] - Set false to keep the caches
   * @returns {Promise<void>}
   */
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
        // Single redirect authority (RedirectManager); falls back to redirectTo.
        if (options.redirectTo) {
          this.redirectTo(options.redirectTo);
        } else if (window.RedirectManager?.afterLogout) {
          await RedirectManager.afterLogout();
        } else {
          this.redirectTo(this.config.redirects.afterLogout);
        }
      }
    } catch (error) {
      this.handleError('Logout failed', error);
    } finally {
      this.state.loading = false;
    }
  },

  /**
   * Refreshes the session against the refresh endpoint and schedules the next
   * refresh.
   *
   * The new access token arrives as an httpOnly cookie, so only the user
   * profile is updated here.
   *
   * @returns {Promise<boolean>} True when the session was refreshed
   */
  async refreshToken() {
    try {
      const refreshToken = this.getRefreshToken();
      const payload = refreshToken ? {refresh_token: refreshToken} : null;
      let response = await simpleFetch.post(
        this.config.endpoints.refresh,
        payload,
        this.buildRefreshRequestOptions()
      );

      // 419 = the CSRF token no longer belongs to the server session (the
      // session was recreated after idle/GC or a sleep). simpleFetch bypasses
      // the http interceptors, so SecurityManager's own 419 recovery never
      // runs for this request: fetch a token for the current session and
      // retry once before giving up.
      if (response?.status === 419) {
        if (window.SecurityManager?.refreshCSRFToken) {
          await SecurityManager.refreshCSRFToken();
        }
        response = await simpleFetch.post(
          this.config.endpoints.refresh,
          payload,
          this.buildRefreshRequestOptions()
        );
      }

      if (response?.data?.success) {
        // The refreshed access token is set by the server as an httpOnly cookie;
        // the client neither receives nor stores it.
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
          token: null,
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

  /**
   * Schedules the next token refresh, replacing any timer already pending.
   *
   * @returns {void}
   */
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

  /**
   * Reports whether the current user holds a permission, '*' granting all.
   *
   * @param {string} permission - Permission to test
   * @returns {boolean} True when the user holds it
   */
  hasPermission(permission) {
    if (!this.state.authenticated || !this.state.user) {
      return false;
    }

    const userPermissions = Array.isArray(this.state.user.permission) ? this.state.user.permission : [];
    return userPermissions.includes(permission) || userPermissions.includes('*');
  },

  /**
   * Reports whether the current user holds a role, 'admin' granting all.
   *
   * @param {string} role - Role to test
   * @returns {boolean} True when the user holds it
   */
  hasRole(role) {
    if (!this.state.authenticated || !this.state.user) {
      return false;
    }

    const userRoles = this.state.user.roles || [];
    return userRoles.includes(role) || userRoles.includes('admin');
  },

  /**
   * Guards a page for signed-in users: remembers the current URL and redirects
   * to login when nobody is signed in.
   *
   * @returns {boolean} True when the user may proceed
   */
  requireAuth() {
    if (!this.state.authenticated) {
      this.saveIntendedUrl();
      this.redirectTo(this.config.redirects.unauthorized);
      return false;
    }
    return true;
  },

  /**
   * Guards a page for signed-out users, redirecting a signed-in one to the
   * post-login route.
   *
   * @returns {boolean} True when the user may proceed
   */
  requireGuest() {
    if (this.state.authenticated) {
      this.redirectTo(this.config.redirects.afterLogin);
      return false;
    }
    return true;
  },

  /**
   * Remembers where the user was heading so login can send them back, storing
   * it both as auth_intended_route for AuthGuard and RedirectManager and as the
   * legacy intended_url.
   *
   * The router base is stripped so the redirect stays inside the SPA, and auth
   * pages are never remembered.
   *
   * @param {string} [url=null] - Explicit URL for the legacy key
   * @returns {void}
   */
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

  /**
   * Reads and consumes the remembered destination.
   *
   * @returns {string|null} URL, or null when none was stored
   */
  getIntendedUrl() {
    const url = sessionStorage.getItem('intended_url');
    sessionStorage.removeItem('intended_url');
    return url;
  },

  /**
   * Navigates to a URL, through the router when it is running and with a full
   * page load otherwise.
   *
   * A login destination gains a redirect parameter pointing back to the current
   * page.
   *
   * @param {string} url - Destination
   * @returns {void}
   */
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

  /**
   * Writes the token into the csrf-token meta tag, creating it when absent.
   *
   * @param {string} token - CSRF token
   * @returns {void}
   */
  updateCSRFToken(token) {
    let metaToken = document.querySelector('meta[name="csrf-token"]');
    if (!metaToken) {
      metaToken = document.createElement('meta');
      metaToken.name = 'csrf-token';
      document.head.appendChild(metaToken);
    }
    metaToken.setAttribute('content', token);
  },

  /**
   * Reads the CSRF token from the meta tag, falling back to the XSRF-TOKEN
   * cookie.
   *
   * @returns {string|null} Token, or null when neither is present
   */
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

  /**
   * Emits an event through EventManager and as a DOM CustomEvent, so listeners
   * can use either channel.
   *
   * @param {string} eventName - Event name
   * @param {Object} [data={}] - Event payload
   * @returns {void}
   */
  emit(eventName, data = {}) {
    EventManager.emit(eventName, data);

    const event = new CustomEvent(eventName, {
      detail: data,
      bubbles: true,
      cancelable: true
    });

    document.dispatchEvent(event);
  },

  /**
   * Emits auth:error and shows the message to the user.
   *
   * @param {string} message - Description of what failed
   * @param {Error} error - Error that was caught
   * @returns {void}
   */
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

  /**
   * Returns the current user.
   *
   * @returns {Object|null} User, or null when nobody is signed in
   */
  getUser() {
    return this.state.user;
  },

  /**
   * Reports whether a user is signed in.
   *
   * @returns {boolean} True when authenticated
   */
  isAuthenticated() {
    return this.state.authenticated;
  },

  /**
   * Returns the id of the current user.
   *
   * @returns {*} User id, or null when nobody is signed in
   */
  getUserId() {
    return this.state.user?.id ?? null;
  },

  /**
   * Returns the roles of the current user.
   *
   * @returns {Array} Roles, empty when nobody is signed in or the user has none
   */
  getRoles() {
    if (!this.state.authenticated || !this.state.user) {
      return [];
    }

    return Array.isArray(this.state.user.roles) ? this.state.user.roles : [];
  },

  /**
   * Returns the permissions of the current user.
   *
   * @returns {Array} Permissions, empty when there are none
   */
  getPermissions() {
    return Array.isArray(this.state.user?.permission) ? this.state.user.permission : [];
  },

  /**
   * Re-checks the session against the server and reports the result.
   *
   * @returns {Promise<boolean>} True when the session is still valid
   */
  async verifyAuthState() {
    try {
      await this.checkAuthStatus();
      return this.state.authenticated;
    } catch {
      return false;
    }
  },

  /**
   * Reports whether an auth request is in flight.
   *
   * @returns {boolean} True while loading
   */
  isLoading() {
    return this.state.loading;
  },

  /**
   * Returns the last auth error.
   *
   * @returns {Error|null} Error, or null
   */
  getError() {
    return this.state.error;
  },

  /**
   * Clears the stored auth error.
   *
   * @returns {void}
   */
  clearError() {
    this.state.error = null;
  },

  /**
   * Stops the refresh timer and marks the manager uninitialized, leaving the
   * session itself alone.
   *
   * @returns {void}
   */
  cleanup() {
    if (this.state.refreshTimer) {
      clearTimeout(this.state.refreshTimer);
      this.state.refreshTimer = null;
    }

    this.state.initialized = false;
  },

  /**
   * Starts a social login, either in a popup or by redirecting to the
   * provider's endpoint.
   *
   * @param {string} provider - Provider name substituted into the endpoint
   * @param {Object} [options={}] - Login options
   * @param {boolean} [options.popup] - Use a popup instead of a redirect
   * @param {string} [options.endpoint] - Endpoint template to use instead
   * @returns {Promise<Object>} Result of the popup flow, or {success, method: 'redirect'}
   */
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

  /**
   * Runs a social login in a popup and waits for it to report back.
   *
   * Only messages from this origin are accepted; the promise rejects when the
   * popup is blocked, is closed by the user, or reports an error.
   *
   * @param {string} provider - Provider name, used to name the window
   * @param {string} endpoint - URL to open
   * @param {Object} [options={}] - Reserved for future options
   * @returns {Promise<Object>} Result of setAuthenticatedUser()
   */
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

  /**
   * Completes an OAuth callback: exchanges a code or token at the callback
   * endpoint, or accepts a user the provider already returned.
   *
   * @param {Object} callbackData - Query parameters or payload from the provider
   * @returns {Promise<Object>} {success, user} or {success: false, message}
   */
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

  /**
   * Signs a user in from an existing token by fetching their profile with it.
   *
   * @param {string} token - Access token to present as a Bearer token
   * @param {Object} [options={}] - Reserved for future options
   * @returns {Promise<Object>} {success, user} or {success: false, message}
   */
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
