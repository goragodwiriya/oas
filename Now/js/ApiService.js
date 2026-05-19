/**
 * High-level HTTP service for the Now.js framework that handles authentication,
 * caching, retries, tracking, and request deduplication.
 * @namespace ApiService
 */
const ApiService = {
  /**
   * In-memory cache store keyed by request signature.
   * @type {Map<string, {data: *, timestamp: number, expiresAt: number}>}
   */
  cache: new Map(),

  /**
   * Track pending requests to support deduplication.
   * @type {Map<string, Promise>}
   */
  pendingRequests: new Map(),

  /**
   * Abort controllers keyed by cache signature for request cancellation.
   * @type {Map<string, AbortController>}
   */
  abortControllers: new Map(),

  /**
   * In-memory short-lived access token for the hybrid authentication flow.
   * @type {string|null}
   */
  _accessToken: null,

  /**
   * Default configuration used by the service. It can be overridden via {@link ApiService.init}.
   * @type {Object}
   */
  config: {
    baseURL: '',
    debug: false,
    retryCount: 3,
    retryDelay: 1000,
    deduplicate: true,

    // Security settings
    security: {
      csrfProtection: true,
      csrfHeaderName: 'X-CSRF-Token',
      csrfCookieName: 'XSRF-TOKEN',
      csrfTokenSelector: 'meta[name="csrf-token"]',
      csrfIncludeSafeMethods: true,

      bearerAuth: false,
      bearerTokenKey: 'auth_token',
      bearerPrefix: 'Bearer ',

      basicAuth: false,
      basicUsername: '',
      basicPassword: '',

      oauth: false,
      oauthTokenKey: 'oauth_token',

      contentSecurityPolicy: true,
      allowedOrigins: [],

      jwtRefresh: false,
      jwtRefreshEndpoint: (window.Now && Now.DEFAULT_CONFIG && Now.DEFAULT_CONFIG.auth && Now.DEFAULT_CONFIG.auth.endpoints && Now.DEFAULT_CONFIG.auth.endpoints.refresh) ? Now.DEFAULT_CONFIG.auth.endpoints.refresh : 'api/auth/refresh',
      jwtExpireKey: 'exp',
      jwtRefreshBeforeExpirySec: 300, // 5 minutes
      // Authentication strategy for the client
      // 'hybrid' = HttpOnly refresh cookie (server) + access token stored in memory (client)
      // 'cookie' = server authenticates via cookie (no Authorization header from client)
      // 'storage' = legacy localStorage token (not recommended)
      authStrategy: 'hybrid',
      // Whether to send credentials (cookies) with requests (used for refresh/logout)
      sendCredentials: true
    },

    // Connection settings
    connection: {
      timeout: 30000,
      retryOnNetworkError: true,
      maxNetworkRetries: 3,
      backoffFactor: 1.5, // Multiply the time with this number on each retry
      retryStatusCodes: [408, 429, 500, 502, 503, 504],
      exponentialBackoff: true
    },

    // Cache settings
    cache: {
      enabled: true,
      storageType: 'memory', // 'memory', 'session', 'local'
      maxSize: 100, // Maximum number of cache entries
      expiry: {
        default: 60000, // Default TTL (1 minute)
        get: 60000,     // TTL for GET requests (1 minute)
        post: 0,        // Do not cache POST responses
        put: 0,         // Do not cache PUT responses
        delete: 0       // Do not cache DELETE responses
      },
      keyGeneratorFn: null,
      responsePredicate: null // Function to decide whether to cache this response
    },

    // Tracking settings
    tracking: {
      enabled: false,
      errorTracking: true,
      performanceTracking: false,
      analyticsCallback: null,
      excludePaths: []
    },

    // Logging settings
    logging: {
      enabled: false,
      logLevel: 'error', // 'debug', 'info', 'warn', 'error'
      includeRequest: true,
      includeResponse: true,
      logToConsole: true,
      customLogger: null
    }
  },

  /**
   * Initialize the service with runtime options and set up authentication, caching, and interceptors.
   * @param {Object} [options] Partial configuration to merge with the defaults.
   * @returns {Promise<ApiService>} The initialized service instance for chaining.
   */
  async init(options = {}) {
    // Merge default and user-defined values
    this.config = this._mergeDeep(this.config, options);

    // Set BaseURL for HttpClient
    if (this.config.baseURL) {
      http.setBaseURL(this.config.baseURL);
    }

    // Set CSRF protection
    if (this.config.security.csrfProtection) {
      this.setupCsrfProtection();
    }

    // Set Bearer Authentication
    if (this.config.security.bearerAuth) {
      this.setupBearerAuth();
    }

    // Set Basic Authentication
    if (this.config.security.basicAuth) {
      this.setupBasicAuth();
    }

    // Set JWT auto refresh
    if (this.config.security.jwtRefresh) {
      this.setupJwtRefresh();
    }

    // Set interceptors
    this.setupInterceptors();

    // Set up cache if using localStorage or sessionStorage
    if (this.config.cache.enabled) {
      this.setupCache();
    }

    // Set up tracking
    if (this.config.tracking.enabled) {
      this.setupTracking();
    }

    return this;
  },

  /**
   * Configure CSRF protection by injecting the CSRF token into mutating requests.
   * @private
   */
  setupCsrfProtection() {
    // Register request interceptor
    http.addRequestInterceptor(config => {
      const method = (config.method || 'GET').toUpperCase();

      if (!this._shouldAttachCsrf(method)) {
        return config;
      }

      const existingHeaders = config.headers || {};
      config.headers = this._applyCsrfHeader({...existingHeaders}, method);

      return config;
    });
  },

  _shouldAttachCsrf(method = 'GET') {
    if (!this.config.security.csrfProtection) {
      return false;
    }

    const safeMethods = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];
    const normalizedMethod = (method || 'GET').toUpperCase();

    if (this.config.security.csrfIncludeSafeMethods) {
      return true;
    }

    return !safeMethods.includes(normalizedMethod);
  },

  _resolveCsrfToken() {
    try {
      if (typeof window !== 'undefined') {
        if (window.AuthManager?.getCSRFToken) {
          const token = window.AuthManager.getCSRFToken();
          if (token) {
            return token;
          }
        }

        if (window.SecurityManager?.getCSRFToken) {
          const token = window.SecurityManager.getCSRFToken();
          if (token) {
            return token;
          }
        }
      }

      if (typeof document === 'undefined') {
        return null;
      }

      const selector = this.config.security.csrfTokenSelector || 'meta[name="csrf-token"]';
      const metaTag = document.querySelector(selector);
      if (metaTag) {
        const metaValue = metaTag.getAttribute('content');
        if (metaValue) {
          return metaValue;
        }
      }

      if (typeof document.cookie === 'string') {
        const cookieName = this.config.security.csrfCookieName || 'XSRF-TOKEN';
        const cookies = document.cookie.split(';');
        for (const cookie of cookies) {
          const [name, value] = cookie.trim().split('=');
          if (name === cookieName) {
            return decodeURIComponent(value || '');
          }
        }
      }

      const csrfInput = document.querySelector('input[name="_token"], input[name="csrf_token"]');
      if (csrfInput && csrfInput.value) {
        return csrfInput.value;
      }

      return null;
    } catch (error) {
      this.log('warn', 'Failed to resolve CSRF token', error);
      return null;
    }
  },

  _applyCsrfHeader(headers = {}, method = 'GET') {
    if (!this._shouldAttachCsrf(method)) {
      return headers;
    }

    const headerName = this.config.security.csrfHeaderName || 'X-CSRF-Token';

    if (headers && headers[headerName]) {
      return headers;
    }

    const csrfToken = this._resolveCsrfToken();
    if (!csrfToken) {
      return headers;
    }

    return {
      ...headers,
      [headerName]: csrfToken
    };
  },

  /**
   * Attach bearer tokens to outgoing requests when bearer authentication is enabled.
   * @private
   */
  setupBearerAuth() {
    const {bearerTokenKey, bearerPrefix, authStrategy} = this.config.security;

    // Register request interceptor
    http.addRequestInterceptor(config => {
      let token = null;

      // Determine token source based on strategy
      if (authStrategy === 'storage') {
        token = localStorage.getItem(bearerTokenKey);
      } else if (authStrategy === 'hybrid') {
        token = this._accessToken;
      } else {
        // cookie strategy: do not set Authorization header
        token = null;
      }

      // Inject Authorization header when a token is present
      if (token) {
        config.headers = config.headers || {};
        config.headers['Authorization'] = `${bearerPrefix}${token}`;
      }

      return config;
    });
  },

  /**
   * Persist an in-memory access token used by the hybrid authentication strategy.
   * @param {string|null} token Short-lived access token issued by the backend.
   */
  setAccessToken(token) {
    this._accessToken = token;
  },

  /**
   * Remove the in-memory access token.
   */
  clearAccessToken() {
    this._accessToken = null;
  },

  /**
   * Configure HTTP basic authentication headers for each request when enabled.
   * @private
   */
  setupBasicAuth() {
    const {basicUsername, basicPassword} = this.config.security;

    // Register request interceptor
    http.addRequestInterceptor(config => {
      // Encode "username:password" as Base64 per HTTP basic auth specification
      const credentials = btoa(`${basicUsername}:${basicPassword}`);
      config.headers['Authorization'] = `Basic ${credentials}`;
      return config;
    });
  },

  /**
   * Schedule automatic JWT refreshes based on token expiry metadata.
   * @private
   */
  setupJwtRefresh() {
    const {jwtRefreshEndpoint, jwtExpireKey, jwtRefreshBeforeExpirySec, bearerTokenKey, authStrategy, sendCredentials} = this.config.security;

    // Periodically verify whether the access token needs to be refreshed
    const checkAndRefreshToken = async () => {
      try {
        let token = null;

        if (authStrategy === 'storage') {
          token = localStorage.getItem(bearerTokenKey);
        } else if (authStrategy === 'hybrid') {
          token = this._accessToken;
        }

        if (!token) {
          // When no access token is available, attempt a refresh for non-storage strategies
          if (authStrategy !== 'storage') {
            // Continue to refresh flow
          } else {
            return;
          }
        }

        // Parse the expiry timestamp from the JWT if possible
        let timeLeft = Infinity;
        if (token) {
          const parts = token.split('.');
          const payloadBase64 = parts[1];
          if (payloadBase64) {
            const payload = JSON.parse(atob(payloadBase64));
            const expTimestamp = payload && payload[jwtExpireKey];
            if (expTimestamp) {
              const expireDate = new Date(expTimestamp * 1000);
              const now = new Date();
              timeLeft = (expireDate - now) / 1000;
            }
          }
        }

        // Refresh the token when the remaining lifetime is below the threshold
        if (timeLeft <= jwtRefreshBeforeExpirySec) {
          const response = await simpleFetch.post(jwtRefreshEndpoint, null, {credentials: sendCredentials ? 'include' : 'same-origin'});

          if (response && response.data && response.data.token) {
            if (authStrategy === 'storage') {
              localStorage.setItem(bearerTokenKey, response.data.token);
            } else if (authStrategy === 'hybrid') {
              this.setAccessToken(response.data.token);
            }
          }
        }
      } catch (error) {
        // Log refresh failures without interrupting the application flow
        if (this.config.logging.enabled) {
          this.log('error', 'JWT refresh error', error);
        }
      }
    };

    // Check and refresh token every 1 minute
    setInterval(checkAndRefreshToken, 60000);

    // Check and refresh immediately after initialization
    checkAndRefreshToken();
  },

  /**
   * Register response and request interceptors for tracking, retries, and error handling.
   * @private
   */
  setupInterceptors() {
    // Interceptor for performance tracking
    if (this.config.tracking.performanceTracking) {
      http.addRequestInterceptor(config => {
        config.__startTime = performance.now();
        return config;
      });

      http.addResponseInterceptor(
        response => {
          const startTime = response.__startTime;
          if (startTime) {
            const duration = performance.now() - startTime;
            delete response.__startTime;

            // Record performance metrics via the analytics callback
            if (typeof this.config.tracking.analyticsCallback === 'function') {
              this.config.tracking.analyticsCallback({
                type: 'performance',
                url: response.config?.url,
                method: response.config?.method,
                duration,
                status: response.status
              });
            }
          }
          return response;
        },
        error => {
          // Capture performance metrics for failed responses as well
          const startTime = error.config?.__startTime;
          if (startTime) {
            const duration = performance.now() - startTime;

            if (typeof this.config.tracking.analyticsCallback === 'function') {
              this.config.tracking.analyticsCallback({
                type: 'error',
                url: error.config?.url,
                method: error.config?.method,
                duration,
                status: error.status,
                message: error.message
              });
            }
          }
          throw error;
        }
      );
    }

    // Interceptor for error management
    http.addResponseInterceptor(
      response => response,
      error => {
        // Re-issue the request for retry-eligible status codes
        const shouldRetry = this.shouldRetryRequest(error);

        if (shouldRetry) {
          return this.retryRequest(error);
        }

        // Save error
        if (this.config.logging.enabled && this.config.logging.errorTracking) {
          this.log('error', 'API request failed', {
            url: error.config?.url,
            method: error.config?.method,
            status: error.status,
            message: error.message
          });
        }

        throw error;
      }
    );
  },

  /**
   * Initialize cache storage based on the configured persistence layer.
   * @private
   */
  setupCache() {
    const {storageType} = this.config.cache;

    // If using localStorage or sessionStorage
    if (storageType === 'local' || storageType === 'session') {
      try {
        const storage = storageType === 'local' ? localStorage : sessionStorage;
        const cacheData = storage.getItem('api_cache');

        if (cacheData) {
          this.cache = new Map(JSON.parse(cacheData));

          // Remove expired entries before hydrating the cache
          this.clearExpiredCache();
        }

        // Persist cache data to storage when the page unloads
        window.addEventListener('beforeunload', () => {
          this.persistCache();
        });
      } catch (error) {
        // Handle storage access issues gracefully
        this.log('error', 'Failed to setup cache storage', error);
      }
    }
  },

  /**
   * Initialize analytics tracking callbacks when enabled.
   * @private
   */
  setupTracking() {
    // Setting up the tracking system
    if (typeof this.config.tracking.analyticsCallback !== 'function') {
      // Set the starting callback.
      this.config.tracking.analyticsCallback = (data) => {
        if (this.config.logging.enabled) {
          this.log('info', 'Analytics event', data);
        }
      };
    }
  },

  /**
   * Persist the in-memory cache to the configured storage backend.
   * @private
   */
  persistCache() {
    try {
      const {storageType, maxSize} = this.config.cache;

      if (storageType === 'local' || storageType === 'session') {
        const storage = storageType === 'local' ? localStorage : sessionStorage;

        // Clean up expired entries before persisting
        this.clearExpiredCache();

        // Enforce the maximum cache size by removing the oldest entries
        if (this.cache.size > maxSize) {
          const cacheEntries = Array.from(this.cache.entries())
            .sort((a, b) => a[1].timestamp - b[1].timestamp);

          // Continue trimming until the cache size fits within the limit
          while (cacheEntries.length > maxSize) {
            const [key] = cacheEntries.shift();
            this.cache.delete(key);
          }
        }

        // Persist the cache snapshot to storage
        storage.setItem('api_cache', JSON.stringify(Array.from(this.cache.entries())));
      }
    } catch (error) {
      this.log('error', 'Failed to persist cache', error);
    }
  },

  /**
   * Remove cache entries that have exceeded their expiry timestamp.
   * @private
   */
  clearExpiredCache() {
    const now = Date.now();

    for (const [key, entry] of this.cache.entries()) {
      if (now > entry.expiresAt) {
        this.cache.delete(key);
      }
    }
  },

  /**
   * Build a cache key that uniquely identifies a request.
   * @param {string} url Target URL.
   * @param {Object} [params] Query parameters used by the request.
   * @param {string} [method='GET'] HTTP method of the request.
   * @returns {string} Cache key representing the request.
   */
  createCacheKey(url, params = {}, method = 'GET') {
    // If there is a Key function, use that function.
    if (typeof this.config.cache.keyGeneratorFn === 'function') {
      return this.config.cache.keyGeneratorFn(url, params, method);
    }

    // Create a normal key
    let queryString = '';

    if (params instanceof URLSearchParams) {
      queryString = params.toString();
    } else if (typeof params === 'string') {
      queryString = params;
    } else if (params && typeof params === 'object') {
      queryString = JSON.stringify(params);
    }

    return `${method}:${url}:${queryString}`;
  },

  /**
   * Convert ApiService cache options to HTTPClient compatible format
   * @private
   * @param {Object} options - Original options containing cache config
   * @returns {Object} - Options with normalized cache value
   */
  _normalizeOptionsForHttpClient(options) {
    if (!options || typeof options !== 'object') {
      return options;
    }

    const normalizedOptions = {...options};

    // Handle ApiService cache object format
    if (options.cache && typeof options.cache === 'object') {
      // Convert complex cache object to simple HTTPClient format
      if (options.cache.enabled === false) {
        normalizedOptions.cache = 'no-store';
      } else if (options.cache.enabled === true) {
        // Use 'default' for standard caching or 'force-cache' for aggressive caching
        normalizedOptions.cache = options.cache.storageType === 'memory' ? 'default' : 'force-cache';
      } else if (options.cache.storageType === 'no-store' || options.cache.storageType === 'no-cache') {
        normalizedOptions.cache = options.cache.storageType;
      } else {
        // Remove cache from options to prevent conflicts with unknown formats
        delete normalizedOptions.cache;
      }
    }

    return normalizedOptions;
  },

  /**
   * Build a URL string that includes the provided query parameters.
   * @param {string} url Base URL to augment.
   * @param {(Object|URLSearchParams|string)} [params={}] Parameters to append.
   * @returns {string} URL with serialized query parameters.
   */
  buildUrlWithParams(url, params = {}) {
    const hasParams = (input) => {
      if (!input) return false;
      if (Array.isArray(input)) {
        return input.some(item => hasParams(item));
      }
      if (input instanceof URLSearchParams) {
        return Array.from(input.keys()).length > 0;
      }
      if (typeof input === 'string') {
        return input.length > 0;
      }
      return Object.keys(input).length > 0;
    };

    if (!hasParams(params)) {
      return url;
    }

    const [urlWithoutHash, hashFragment = ''] = String(url).split('#');
    const [pathOnly, existingQuery = ''] = urlWithoutHash.split('?');
    const searchParams = new URLSearchParams(existingQuery);

    const appendValue = (key, value) => {
      if (value === undefined || value === null) {
        return;
      }

      if (Array.isArray(value)) {
        value.forEach((item) => appendValue(key, item));
        return;
      }

      if (value instanceof Date) {
        searchParams.append(key, value.toISOString());
        return;
      }

      const isBlob = typeof Blob !== 'undefined' && value instanceof Blob;
      const isFile = typeof File !== 'undefined' && value instanceof File;

      if (typeof value === 'object' && !isBlob && !isFile) {
        searchParams.append(key, JSON.stringify(value));
        return;
      }

      searchParams.append(key, value);
    };

    const processParams = (input) => {
      if (!input) return;

      if (Array.isArray(input)) {
        input.forEach(item => processParams(item));
        return;
      }

      if (input instanceof URLSearchParams) {
        for (const [key, value] of input.entries()) {
          searchParams.append(key, value);
        }
        return;
      }

      if (typeof input === 'string') {
        new URLSearchParams(input).forEach((value, key) => {
          searchParams.append(key, value);
        });
        return;
      }

      Object.entries(input).forEach(([key, value]) => {
        appendValue(key, value);
      });
    };

    processParams(params);

    const queryString = searchParams.toString();
    const hashSuffix = hashFragment ? `#${hashFragment}` : '';

    if (queryString) {
      return `${pathOnly}?${queryString}${hashSuffix}`;
    }

    return `${pathOnly}${hashSuffix}`;
  },

  /**
   * Determine whether the failed request should be retried.
   * @param {Error|Object} error Error object returned by the HTTP client.
   * @returns {boolean} True when the request qualifies for a retry attempt.
   */
  shouldRetryRequest(error) {
    const {retryOnNetworkError, retryStatusCodes} = this.config.connection;

    // In the event of network errors
    if (retryOnNetworkError && (error.message.includes('network') || error.status === 0)) {
      return true;
    }

    // In the event of errors from status codes
    if (retryStatusCodes.includes(error.status)) {
      return true;
    }

    return false;
  },

  /**
   * Retry a failed request with the configured delay and backoff strategy.
   * @param {Error|Object} error Error describing the failed request.
   * @returns {Promise<*>} Promise resolving with the retried response.
   */
  async retryRequest(error) {
    const {maxNetworkRetries, backoffFactor, exponentialBackoff} = this.config.connection;
    const originalRequest = error.config;

    // Check how many times it has been retried
    originalRequest.retryCount = originalRequest.retryCount || 0;

    // If it has retried more than the allowed number
    if (originalRequest.retryCount >= maxNetworkRetries) {
      return Promise.reject(error);
    }

    // Increase the retry count
    originalRequest.retryCount++;

    // Calculate the delay before retrying
    let delayMs = this.config.retryDelay;

    if (exponentialBackoff) {
      // Use exponential backoff
      delayMs = delayMs * Math.pow(backoffFactor, originalRequest.retryCount - 1);
    }

    // Wait for the specified time
    await new Promise(resolve => setTimeout(resolve, delayMs));

    // Try the request again - Keep using http.request for retry mechanism
    return http.request(originalRequest.url, originalRequest);
  },

  /**
   * Execute a GET request with built-in caching, deduplication, and retry handling.
   * @param {string} url Target endpoint.
   * @param {Object} [params] Query parameters to include.
   * @param {Object} [options] Overrides for caching or fetch behavior.
   * @returns {Promise<*>} Response payload from the server.
   */
  async get(url, params = {}, options = {}) {
    // Combine default and provided values
    const opts = {
      ...this.config,
      ...options
    };

    const hasParamValues = (input) => {
      if (!input) return false;
      if (Array.isArray(input)) {
        return input.some(item => hasParamValues(item));
      }
      if (input instanceof URLSearchParams) {
        return Array.from(input.keys()).length > 0;
      }
      if (typeof input === 'string') {
        return input.length > 0;
      }
      return Object.keys(input).length > 0;
    };

    let combinedParams = params;

    if (options?.params) {
      combinedParams = hasParamValues(params) ? [params, options.params] : options.params;
    }

    const requestUrl = this.buildUrlWithParams(url, combinedParams);
    const normalizeForCache = (input) => {
      if (!input) return input;

      if (input instanceof URLSearchParams) {
        return input.toString();
      }

      if (Array.isArray(input)) {
        return input.map(item => normalizeForCache(item));
      }

      if (input instanceof Date) {
        return input.toISOString();
      }

      const isBlobForCache = typeof Blob !== 'undefined' && input instanceof Blob;
      const isFileForCache = typeof File !== 'undefined' && input instanceof File;

      if (isBlobForCache || isFileForCache) {
        return '[binary]';
      }

      if (typeof input === 'object') {
        const normalized = {};
        Object.entries(input).forEach(([key, value]) => {
          normalized[key] = normalizeForCache(value);
        });
        return normalized;
      }

      return input;
    };

    const cacheKey = this.createCacheKey(url, normalizeForCache(combinedParams), 'GET');

    // Check cache (if enabled)
    if (opts.cache.enabled && opts.cache.expiry.get > 0) {
      const cached = this.cache.get(cacheKey);
      if (cached && (Date.now() < cached.expiresAt)) {
        if (this.config.logging.enabled && this.config.logging.logLevel === 'debug') {
          this.log('debug', 'Cache hit', {url: requestUrl, params: combinedParams, cacheKey});
        }
        return {...cached.data, fromCache: true};
      }
    }

    // Check for ongoing requests (to prevent duplicate requests)
    if (opts.deduplicate && this.pendingRequests.has(cacheKey)) {
      return this.pendingRequests.get(cacheKey);
    }

    // Create AbortController for request cancellation
    const controller = new AbortController();
    this.abortControllers.set(cacheKey, controller);

    // Create promise for request
    const requestPromise = (async () => {
      try {
        if (this.config.logging.enabled && this.config.logging.logLevel === 'debug') {
          this.log('debug', 'API request', {url: requestUrl, params: combinedParams, method: 'GET'});
        }

        // Normalize cache options for HTTPClient compatibility
        const normalizedOptions = this._normalizeOptionsForHttpClient({...options});

        // Adjust options (if http has normalizeRequestOptions helper)
        const adjustedOptions = http && http.normalizeRequestOptions ?
          http.normalizeRequestOptions(normalizedOptions) : normalizedOptions;

        const {params: _ignoredParams, signal: externalSignal, ...sanitizedOptions} = adjustedOptions;

        const fetchOptions = {
          ...sanitizedOptions,
          headers: {...(sanitizedOptions.headers || {})}
        };

        if (!('credentials' in fetchOptions)) {
          fetchOptions.credentials = this.config.security.sendCredentials ? 'include' : 'same-origin';
        }

        fetchOptions.signal = controller.signal;

        if (externalSignal) {
          if (externalSignal.aborted) {
            controller.abort(externalSignal.reason);
          } else {
            externalSignal.addEventListener('abort', () => controller.abort(externalSignal.reason), {once: true});
          }
        }

        let response;

        if (http && typeof http.get === 'function') {
          response = await http.get(requestUrl, fetchOptions);
        } else if (simpleFetch && typeof simpleFetch.get === 'function') {
          const simpleFetchOptions = {
            ...fetchOptions,
            headers: this._applyCsrfHeader({...fetchOptions.headers}, 'GET')
          };

          response = await simpleFetch.get(requestUrl, simpleFetchOptions);
        } else {
          throw new Error('No HTTP client available for GET requests');
        }

        // Cache the response
        if (opts.cache.enabled && opts.cache.expiry.get > 0) {
          const shouldCache = typeof opts.cache.responsePredicate === 'function'
            ? opts.cache.responsePredicate(response)
            : true;

          if (shouldCache) {
            this.cache.set(cacheKey, {
              data: response,
              timestamp: Date.now(),
              expiresAt: Date.now() + opts.cache.expiry.get
            });
          }
        }

        return response;
      } catch (error) {
        throw error;
      } finally {
        // Remove from ongoing requests
        this.pendingRequests.delete(cacheKey);
        this.abortControllers.delete(cacheKey);
      }
    })();

    // Store the ongoing request
    this.pendingRequests.set(cacheKey, requestPromise);

    return requestPromise;
  },
  /**
   * Execute a POST request and invalidate caches bound to the target URL.
   * @param {string} url Target endpoint.
   * @param {*} data Payload to send with the request.
   * @param {Object} [options] Additional fetch options.
   * @returns {Promise<*>} Response payload from the server.
   */
  async post(url, data, options = {}) {
    return this._sendNonGetRequest('POST', url, data, options);
  },

  /**
   * Execute a PUT request and invalidate caches bound to the target URL.
   * @param {string} url Target endpoint.
   * @param {*} data Payload to send with the request.
   * @param {Object} [options] Additional fetch options.
   * @returns {Promise<*>} Response payload from the server.
   */
  async put(url, data, options = {}) {
    return this._sendNonGetRequest('PUT', url, data, options);
  },

  /**
   * Execute a DELETE request and invalidate caches bound to the target URL.
   * @param {string} url Target endpoint.
   * @param {Object} [options] Additional fetch options.
   * @returns {Promise<*>} Response payload from the server.
   */
  async delete(url, options = {}) {
    return this._sendNonGetRequest('DELETE', url, null, options);
  },

  /**
   * Execute a non-GET request (POST, PUT, PATCH, DELETE) while applying shared defaults.
   * @private
   * @param {string} method HTTP verb to execute.
   * @param {string} url Target endpoint URL.
   * @param {*} data Payload to send with the request (ignored for DELETE).
   * @param {Object} [options] Additional request options.
   * @returns {Promise<*>} The response returned by the underlying fetch implementation.
   */
  async _sendNonGetRequest(method, url, data, options = {}) {
    if (this.config.logging.enabled && this.config.logging.logLevel === 'debug') {
      const logPayload = {url, method};
      if (typeof data !== 'undefined' && data !== null) {
        logPayload.data = data;
      }
      this.log('debug', 'API request', logPayload);
    }

    // Normalize cache options for HTTPClient compatibility
    const normalizedOptions = this._normalizeOptionsForHttpClient({...options});

    const adjustedOptions = http && http.normalizeRequestOptions ?
      http.normalizeRequestOptions(normalizedOptions) : normalizedOptions;

    const {params: optionParams, ...sanitizedOptions} = adjustedOptions;
    const requestUrl = this.buildUrlWithParams(url, optionParams);

    if (!('credentials' in sanitizedOptions)) {
      sanitizedOptions.credentials = this.config.security.sendCredentials ? 'include' : 'same-origin';
    }

    const methodName = method.toLowerCase();
    let response;

    if (http && typeof http[methodName] === 'function') {
      if (['post', 'put', 'patch'].includes(methodName)) {
        response = await http[methodName](requestUrl, data, sanitizedOptions);
      } else {
        response = await http[methodName](requestUrl, sanitizedOptions);
      }
    } else if (simpleFetch && typeof simpleFetch[methodName] === 'function') {
      const fallbackOptions = {
        ...sanitizedOptions,
        headers: this._applyCsrfHeader({...((sanitizedOptions && sanitizedOptions.headers) || {})}, method)
      };

      const fetchArgs = [requestUrl];
      if (['post', 'put', 'patch'].includes(methodName)) {
        fetchArgs.push(data);
      }
      fetchArgs.push(fallbackOptions);
      response = await simpleFetch[methodName](...fetchArgs);
    } else {
      throw new Error(`Unsupported HTTP method: ${method}`);
    }

    this.invalidateCacheByUrl(url);
    if (requestUrl !== url) {
      this.invalidateCacheByUrl(requestUrl);
    }

    return response;
  },

  /**
   * Abort an in-flight request identified by its URL and parameters.
   * @param {string} url Target endpoint.
   * @param {Object} [params] Parameters used when the request was initiated.
   * @returns {boolean} True when a matching request was found and aborted.
   */
  abort(url, params = {}) {
    const cacheKey = this.createCacheKey(url, params);
    const controller = this.abortControllers.get(cacheKey);

    if (controller) {
      controller.abort();
      this.abortControllers.delete(cacheKey);
      this.pendingRequests.delete(cacheKey);
      return true;
    }

    return false;
  },

  /**
   * Remove all cache entries from memory and storage.
   */
  clearCache() {
    this.cache.clear();
    this.persistCache();
  },

  /**
   * Remove a specific cache entry identified by URL and parameters.
   * @param {string} url Target endpoint.
   * @param {Object} [params] Parameters associated with the cached response.
   * @returns {boolean} True if the cache entry existed and was removed.
   */
  invalidateCache(url, params = {}) {
    const cacheKey = this.createCacheKey(url, params);
    const deleted = this.cache.delete(cacheKey);
    this.persistCache();
    return deleted;
  },

  /**
   * Remove all cache entries whose key includes the provided URL.
   * @param {string} url URL substring to match against cache keys.
   * @returns {number} Number of cache entries removed.
   */
  invalidateCacheByUrl(url) {
    let count = 0;

    for (const key of this.cache.keys()) {
      if (key.includes(url)) {
        this.cache.delete(key);
        count++;
      }
    }

    this.persistCache();
    return count;
  },

  /**
   * Await multiple request promises in parallel.
   * @param {Array<Promise>} requests Collection of request promises.
   * @returns {Promise<Array<*>>} Aggregated responses.
   */
  async all(requests) {
    return Promise.all(requests);
  },

  /**
   * Poll an endpoint at a fixed interval until stopped or the condition is met.
   * @param {string} url Target endpoint.
   * @param {Object} [params] Query parameters to include with each request.
   * @param {number} [interval=5000] Delay between polls in milliseconds.
   * @param {Function} callback Handler receiving (response, error, attemptCount).
   * @param {Object} [options] Polling configuration including maxPolls and condition.
   * @returns {Function} Cleanup function to cancel the polling.
   */
  poll(url, params = {}, interval = 5000, callback, options = {}) {
    const opts = {
      maxPolls: Infinity,
      condition: null, // Function to determine when to stop polling
      ...options
    };

    let count = 0;
    let timerId = null;

    const execute = async () => {
      try {
        count++;
        const response = await this.get(url, params, {cache: {enabled: false}});

        // Call the callback
        callback(response, null, count);

        // Check the stop condition
        const shouldStop = typeof opts.condition === 'function' ? opts.condition(response) : false;

        if (shouldStop || count >= opts.maxPolls) {
          return;
        }

        // Set timeout for next execution
        timerId = setTimeout(execute, interval);
      } catch (error) {
        callback(null, error, count);

        // Retry if not exceeded max polls
        if (count < opts.maxPolls) {
          timerId = setTimeout(execute, interval);
        }
      }
    };

    // Start execution
    execute();

    // Return function to cancel polling
    return () => {
      if (timerId) {
        clearTimeout(timerId);
        timerId = null;
      }
    };
  },

  /**
   * Execute a list of GET requests sequentially and optionally post-process each response.
   * @param {Array<Object>} requests Items containing url, params, and options.
   * @param {Function} [processor] Optional transformer invoked per response.
   * @returns {Promise<Array<*>>} Array of transformed results or raw responses/errors.
   */
  async sequence(requests, processor) {
    const results = [];

    for (const request of requests) {
      try {
        const {url, params, options} = request;
        const response = await this.get(url, params, options);

        // Process the response if a processor function is provided
        if (typeof processor === 'function') {
          const result = await processor(response);
          results.push(result);
        } else {
          results.push(response);
        }
      } catch (error) {
        results.push({error});
      }
    }

    return results;
  },

  /**
   * Fetch paginated data across multiple pages until exhausted or stopped.
   * @param {string} url Target endpoint.
   * @param {Object} [params] Base parameters shared across pages.
   * @param {Object} [options] Pagination behavior overrides.
   * @returns {Promise<{data: Array<*>, pages: number, total: number}>} Aggregated pagination result.
   */
  async paginate(url, params = {}, options = {}) {
    const opts = {
      pageParam: 'page',
      // Use 'pageSize' as the standard parameter name across the project
      // so clients and components send the same key for page size/limit.
      limitParam: 'pageSize',
      startPage: 1,
      // default page size
      limit: 20,
      maxPages: Infinity,
      dataPath: 'data',
      totalPath: 'meta.total',
      stopCondition: null,
      ...options
    };

    const allData = [];
    let currentPage = opts.startPage;
    let hasMore = true;
    let total = null;

    while (hasMore && currentPage <= opts.maxPages) {
      // Create params for the current page
      const pageParams = {
        ...params,
        [opts.pageParam]: currentPage,
        [opts.limitParam]: opts.limit
      };

      // Fetch data
      const response = await this.get(url, pageParams);

      // Extract data based on the specified path and normalize to array
      const rawPageData = this.getValueByPath(response, opts.dataPath);
      let pageData = [];
      if (Array.isArray(rawPageData)) {
        pageData = rawPageData;
      } else if (rawPageData && typeof rawPageData === 'object' && Array.isArray(rawPageData.data)) {
        // Support responses like { data: [...] }
        pageData = rawPageData.data;
      } else {
        // If the path yields a single object or scalar, wrap into array when appropriate
        if (rawPageData !== null && typeof rawPageData !== 'undefined') {
          // If it's an object that's not an array, we avoid spreading it; skip or wrap
          // Prefer wrapping primitives/objects into an array containing the item
          pageData = Array.isArray(rawPageData) ? rawPageData : [rawPageData];
        } else {
          pageData = [];
        }
      }

      // Get total if available
      if (total === null) {
        total = this.getValueByPath(response, opts.totalPath);
      }

      // Add data to the allData array
      if (Array.isArray(pageData)) {
        allData.push(...pageData);
      } else if (pageData !== null && typeof pageData !== 'undefined') {
        allData.push(pageData);
      }

      // Check the stop condition
      if (typeof opts.stopCondition === 'function') {
        const shouldStop = opts.stopCondition(pageData, response, currentPage);
        if (shouldStop) break;
      }

      // Check if there is a next page
      hasMore = pageData.length === opts.limit;

      // If total is known, check if all data has been fetched
      if (total !== null) {
        hasMore = allData.length < total;
      }

      currentPage++;
    }

    return {
      data: allData,
      pages: currentPage - 1,
      total: total || allData.length
    };
  },

  /**
   * Safely read a nested property from an object using dot notation.
   * @param {Object} obj Source object.
   * @param {string} path Dot-notated path.
   * @returns {*} The resolved value or undefined when missing.
   */
  getValueByPath(obj, path) {
    if (!obj || !path) return undefined;

    return path.split('.').reduce((o, p) => o?.[p], obj);
  },

  /**
   * Deep merge two configuration objects without mutating the originals.
   * @private
   * @param {Object} target Base object.
   * @param {Object} source Overrides to merge.
   * @returns {Object} New object containing merged keys.
   */
  _mergeDeep(target, source) {
    const isObject = obj => obj && typeof obj === 'object' && !Array.isArray(obj);

    if (!source) return target;

    const output = {...target};

    if (isObject(target) && isObject(source)) {
      Object.keys(source).forEach(key => {
        if (isObject(source[key])) {
          if (!(key in target)) {
            output[key] = source[key];
          } else {
            output[key] = this._mergeDeep(target[key], source[key]);
          }
        } else {
          output[key] = source[key];
        }
      });
    }

    return output;
  },

  /**
   * Emit a log entry using the configured logger when logging is enabled.
   * @param {'debug'|'info'|'warn'|'error'} level Log level for the message.
   * @param {string} message Short descriptive message.
   * @param {Object} [data={}] Supplementary context to include with the log entry.
   */
  log(level, message, data = {}) {
    if (!this.config.logging.enabled) return;

    const logLevels = {
      debug: 0,
      info: 1,
      warn: 2,
      error: 3
    };

    // Check the log level
    if (logLevels[level] < logLevels[this.config.logging.logLevel]) {
      return;
    }

    // Log data
    const logData = {
      timestamp: new Date().toISOString(),
      level,
      message,
      ...data
    };

    // If a custom logger is provided, use it
    if (typeof this.config.logging.customLogger === 'function') {
      this.config.logging.customLogger(logData);
      return;
    }

    // If log to console
    if (this.config.logging.logToConsole) {
      switch (level) {
        case 'debug':
          console.log(`[API] ${message}`, data);
          break;
        case 'info':
          console.info(`[API] ${message}`, data);
          break;
        case 'warn':
          console.warn(`[API] ${message}`, data);
          break;
        case 'error':
          console.error(`[API] ${message}`, data);
          break;
      }
    }
  }
};

// Register with Now framework
if (window.Now?.registerManager) {
  Now.registerManager('api', ApiService);
}

// Expose globally
window.ApiService = ApiService;
