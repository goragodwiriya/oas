function getRequestLanguage() {
  const locale = Now.getRequestLocale();
  return typeof locale === 'string' && locale.trim() !== '' ? locale.trim() : 'en';
}

function applyRequestLanguage(options = {}) {
  return Now.applyRequestLanguage(options);
}

/**
 * A modern, promise-based HTTP client for making API requests with security features.
 *
 * @class HttpClient
 * @description Provides a flexible and extensible way to make HTTP requests with support for
 * interceptors, CSRF protection, response handling, and more.
 *
 * @example
 *
 * const http = new HttpClient({
 *   baseURL: 'https://api.example.com'
 * });
 *
 *
 * const response = await http.get('/users');
 *
 *
 * const createResponse = await http.post('/users', { name: 'John Doe' });
 *
 * @example
 *
 * http.addRequestInterceptor(config => {
 *   config.headers['Authorization'] = `Bearer ${getToken()}`;
 *   return config;
 * });
 *
 * @property {string} baseURL - Base URL for all requests
 * @property {Object} defaultHeaders - Default headers sent with every request
 * @property {number} timeout - Request timeout in milliseconds
 * @property {Function} responseHandler - Custom response handler function
 * @property {Object} interceptors - Request and response interceptors
 * @property {boolean} throwOnError - Whether to throw errors on non-2xx responses
 * @property {string} csrfToken - CSRF token for request protection
 * @property {string} csrfHeaderName - Name of the CSRF header
 * @property {string} csrfCookieName - Name of the CSRF cookie
 * @property {string} csrfTokenSelector - CSS selector for the CSRF meta tag
 * @property {Object} security - Security configuration options
 */
class HttpClient {
  constructor(options = {}) {
    this.baseURL = options.baseURL || '';
    this.defaultHeaders = {
      'X-Requested-With': 'XMLHttpRequest',
      ...options.headers
    };
    this.timeout = options.timeout || 30000;
    this.responseHandler = options.responseHandler;
    this.interceptors = {
      request: [],
      response: []
    };

    this.throwOnError = options.throwOnError !== false;
    this.csrfToken = null;

    this.security = options.security || {
      csrf: {
        enabled: false,
        required: true,
        tokenName: '_token',
        headerName: 'X-CSRF-Token'
      },
      validation: {
        enabled: false
      }
    };

    // Use CSRF config from security or use default.
    this.csrfHeaderName = this.security.csrf.headerName || 'X-CSRF-Token';
    this.csrfCookieName = options.csrfCookieName || 'XSRF-TOKEN';
    this.csrfTokenSelector = options.csrfTokenSelector || 'meta[name="csrf-token"]';

    // Always attempt to capture CSRF token so it's ready when needed
    this.setupCsrfToken();

    this.setupSecurity();
  }

  /**
   * Setup CSRF token from meta tag or cookie.
   * This method checks for a meta tag with the CSRF token or retrieves it from cookies.
   * If found, it sets the csrfToken property.
   */
  setupCsrfToken() {
    const metaToken = document.querySelector(this.csrfTokenSelector);
    if (metaToken) {
      this.csrfToken = metaToken.getAttribute('content');
      return;
    }

    const cookies = document.cookie.split(';');
    for (const cookie of cookies) {
      const [name, value] = cookie.trim().split('=');
      if (name === this.csrfCookieName) {
        this.csrfToken = decodeURIComponent(value);
        return;
      }
    }

    console.warn('CSRF token not found in meta tag or cookie');
  }

  /**
   * Setup security features like CSRF, rate limiting, and validation.
   * This method checks if SecurityManager is available and sets up interceptors accordingly.
   */
  setupSecurity() {
    this.addRequestInterceptor(async (config) => {
      try {
        return SecurityManager.addCSRFToRequest(config);
      } catch (error) {
        console.warn('CSRF interceptor error:', error);
        return config;
      }
    });



    this.addRequestInterceptor(async (config) => {
      try {
        if (this.security.validation.enabled && config.body) {
          config.body = SecurityManager.sanitizeRequestData(config.body);
        }
        return config;
      } catch (error) {
        console.warn('Validation interceptor error:', error);
      }
      return config;
    });
  }

  addRequestInterceptor(fn) {
    this.interceptors.request.push(fn);
    return () => {
      const index = this.interceptors.request.indexOf(fn);
      if (index !== -1) this.interceptors.request.splice(index, 1);
    };
  }

  addResponseInterceptor(onFulfilled, onRejected) {
    this.interceptors.response.push({onFulfilled, onRejected});
    return () => {
      const index = this.interceptors.response.findIndex(i =>
        i.onFulfilled === onFulfilled && i.onRejected === onRejected);
      if (index !== -1) this.interceptors.response.splice(index, 1);
    };
  }

  async runRequestInterceptors(config) {
    let resultConfig = {...config};
    for (const interceptor of this.interceptors.request) {
      resultConfig = await interceptor(resultConfig);
    }
    return resultConfig;
  }

  /**
   * Run response interceptors after receiving a response.
   *
   * @param {Response} response - The fetch response object.
   * @param {boolean} [isError=false] - Whether this is an error response.
   * @returns {Promise<Object>} - The processed response object.
   */
  async runResponseInterceptors(response, isError = false) {
    let result = response;

    for (const interceptor of this.interceptors.response) {
      try {
        if (isError && interceptor.onRejected) {
          result = await interceptor.onRejected(result);
        } else if (!isError && interceptor.onFulfilled) {
          result = await interceptor.onFulfilled(result);
        } else if (!isError && !response.success && interceptor.onRejected) {
          result = await interceptor.onRejected(result);
        }
      } catch (error) {

        result = {
          success: false,
          status: 500,
          statusText: 'Interceptor Error',
          data: null,
          headers: {},
          error: error
        };
        break;
      }
    }

    return result;
  }

  async request(url, options = {}) {
    const controller = new AbortController();
    let timeoutId;

    if (this.timeout) {
      timeoutId = setTimeout(() => controller.abort(), this.timeout);
    }

    try {
      const baseUrl = /^(https?:)?\/\//.test(url) ? url : `${this.baseURL}${url}`;
      const finalUrl = this.appendQueryParams(baseUrl, options.params);

      let config = applyRequestLanguage({
        method: options.method || 'GET',
        ...options,
        headers: {
          ...this.defaultHeaders,
          ...options.headers
        },
        signal: controller.signal,
        credentials: options.credentials || 'same-origin'
      });

      delete config.params;

      config = await this.runRequestInterceptors(config);
      config = this.ensureCsrf(config);
      config.body = this.processRequestBody(config);
      config = this.normalizeRequestOptions(config);

      const response = await fetch(finalUrl, config);
      clearTimeout(timeoutId);

      // Update CSRF token from response headers (before custom handler may process)
      const newCsrfToken = response.headers.get(this.csrfHeaderName);
      if (newCsrfToken) {
        this.csrfToken = newCsrfToken;
      }

      let result;
      if (this.responseHandler) {
        result = await this.responseHandler(response);
      } else {
        result = await this.handleResponse(response);
      }

      return await this.runResponseInterceptors(result);

    } catch (error) {
      clearTimeout(timeoutId);

      const errorResponse = {
        success: false,
        status: error.name === 'AbortError' ? 408 : (error.status || 500),
        statusText: error.name === 'AbortError' ? 'Request timeout' : (error.message || 'Unknown error'),
        data: null,
        headers: {},
        error: error,
        url: url
      };

      try {
        const processedError = await this.runResponseInterceptors(errorResponse, true);
        return processedError;
      } catch (interceptorError) {
        return errorResponse;
      }
    }
  }

  appendQueryParams(url, params) {
    if (!params) {
      return url;
    }

    const searchParams = new URLSearchParams();
    const appendValue = (key, value) => {
      if (value === undefined || value === null) {
        return;
      }

      if (Array.isArray(value)) {
        value.forEach(item => appendValue(key, item));
        return;
      }

      if (value instanceof Date) {
        searchParams.append(key, value.toISOString());
        return;
      }

      if (typeof value === 'object') {
        searchParams.append(key, JSON.stringify(value));
        return;
      }

      searchParams.append(key, String(value));
    };

    if (params instanceof URLSearchParams) {
      params.forEach((value, key) => appendValue(key, value));
    } else if (typeof params === 'object') {
      Object.entries(params).forEach(([key, value]) => appendValue(key, value));
    } else {
      return url;
    }

    const queryString = searchParams.toString();
    if (!queryString) {
      return url;
    }

    return `${url}${url.includes('?') ? '&' : '?'}${queryString}`;
  }

  processRequestBody(config) {
    const {body, headers} = config;
    if (!body) return null;

    if (body instanceof FormData || body instanceof URLSearchParams ||
      body instanceof Blob || body instanceof ArrayBuffer) {
      delete headers['Content-Type'];
      return body;
    }

    if (typeof body === 'object') {
      if (!headers['Content-Type']) {
        headers['Content-Type'] = 'application/json';
      }
      if (headers['Content-Type']?.includes('application/json')) {
        return JSON.stringify(body);
      }
    }

    return body;
  }

  normalizeRequestOptions(options) {
    const result = {...options};

    // Remove JSON content-type for requests without body to avoid cache/CDN issues
    if (!result.body && result.headers?.['Content-Type'] === 'application/json') {
      delete result.headers['Content-Type'];
    }

    if (result.cache === true) {
      result.cache = 'default';
    } else if (result.cache === false) {
      result.cache = 'no-store';
    }

    return result;
  }

  ensureCsrf(config) {
    const method = (config.method || 'GET').toUpperCase();
    if (!this.csrfToken && this.security.csrf.required && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
      throw new HttpError('Missing CSRF token', 400);
    }

    if (this.csrfToken) {
      config.headers = config.headers || {};
      config.headers[this.csrfHeaderName] = this.csrfToken;

      if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
        const body = config.body;
        if (typeof body === 'object' && body !== null && !(body instanceof FormData) && !body._token) {
          body[this.security.csrf.tokenName || '_token'] = this.csrfToken;
          config.body = body;
        } else if (body instanceof FormData && !body.has(this.security.csrf.tokenName || '_token')) {
          body.append(this.security.csrf.tokenName || '_token', this.csrfToken);
          config.body = body;
        }
      }
    }

    return config;
  }

  async handleResponse(response) {
    const newCsrfToken = response.headers.get(this.csrfHeaderName);
    if (newCsrfToken) {
      this.csrfToken = newCsrfToken;
    }

    const contentType = response.headers.get('content-type');
    const isJSON = contentType?.includes('application/json');
    const isText = contentType?.includes('text/');
    const isBlob = contentType?.includes('application/octet-stream');

    let data;
    try {
      if (isJSON) {
        data = await response.json();
      } else if (isBlob) {
        data = await response.blob();
      } else if (isText) {
        data = await response.text();
      } else {
        data = response;
      }
    } catch (parseError) {
      data = await response.text();
    }

    const result = {
      success: response.ok,  // HTTP success mapped to success
      status: response.status,
      statusText: response.statusText,
      data: data,
      headers: this.getHeadersObject(response),
      url: response.url
    };

    return result;
  }

  getHeadersObject(response) {
    const headers = {};
    response.headers.forEach((value, key) => {
      headers[key] = value;
    });
    return headers;
  }

  createHttpError(response) {
    return new HttpError(
      response.statusText || 'HTTP Error',
      response.status,
      response
    );
  }

  createErrorResponse(error) {
    const isTimeout = error.name === 'AbortError';
    const status = isTimeout ? 408 : error.status || 500;
    const message = isTimeout ? 'Request timeout' : error.message || 'Unknown error';

    return new HttpError(message, status, error.response);
  }

  async get(url, options = {}) {
    const response = await this.request(url, {...options, method: 'GET'});

    if (options.throwOnError !== false && this.throwOnError && !response.success) {
      throw new HttpError(response.statusText || 'HTTP Error', response.status, response);
    }

    return response;
  }

  async post(url, data, options = {}) {
    const response = await this.request(url, {...options, method: 'POST', body: data});

    if (options.throwOnError !== false && this.throwOnError && !response.success) {
      throw new HttpError(response.statusText || 'HTTP Error', response.status, response);
    }

    return response;
  }

  async put(url, data, options = {}) {
    const response = await this.request(url, {...options, method: 'PUT', body: data});

    if (options.throwOnError !== false && this.throwOnError && !response.success) {
      throw new HttpError(response.statusText || 'HTTP Error', response.status, response);
    }

    return response;
  }

  async delete(url, options = {}) {
    const response = await this.request(url, {...options, method: 'DELETE'});

    if (options.throwOnError !== false && this.throwOnError && !response.success) {
      throw new HttpError(response.statusText || 'HTTP Error', response.status, response);
    }

    return response;
  }

  patch(url, data, options = {}) {
    return this.request(url, {...options, method: 'PATCH', body: data});
  }

  head(url, options = {}) {
    return this.request(url, {...options, method: 'HEAD'});
  }

  options(url, options = {}) {
    return this.request(url, {...options, method: 'OPTIONS'});
  }

  upload(url, files, options = {}) {
    const formData = new FormData();

    if (Array.isArray(files)) {
      files.forEach((file, i) => formData.append(`file${i}`, file));
    } else {
      formData.append('file', files);
    }

    if (options.data) {
      Object.entries(options.data).forEach(([key, value]) => {
        formData.append(key, value);
      });
    }

    return this.request(url, {
      ...options,
      method: 'POST',
      body: formData
    });
  }

  async getSafe(url, options = {}) {
    return this.request(url, {...options, method: 'GET'});
  }

  async postSafe(url, data, options = {}) {
    return this.request(url, {...options, method: 'POST', body: data});
  }

  async putSafe(url, data, options = {}) {
    return this.request(url, {...options, method: 'PUT', body: data});
  }

  async deleteSafe(url, options = {}) {
    return this.request(url, {...options, method: 'DELETE'});
  }

  setBaseURL(url) {
    this.baseURL = url;
  }

  setDefaultHeader(name, value) {
    this.defaultHeaders[name] = value;
  }

  setDefaultHeaders(headers) {
    this.defaultHeaders = {
      ...this.defaultHeaders,
      ...headers
    };
  }

  setTimeout(timeout) {
    this.timeout = timeout;
  }

  setCsrfToken(token) {
    this.csrfToken = token;
  }
}

class HttpError extends Error {
  constructor(message, status, response = null) {
    super(message);
    this.name = 'HttpError';
    this.status = status;
    this.response = response;
    this.timestamp = Date.now();
  }
}

const http = new HttpClient({
  throwOnError: false,
  responseHandler: async (response) => {
    const contentType = response.headers.get('content-type');
    let data;

    try {
      if (contentType?.includes('application/json')) {
        data = await response.json();
      } else if (contentType?.includes('text/')) {
        data = await response.text();
      } else if (contentType?.includes('application/octet-stream')) {
        data = await response.blob();
      } else {
        data = await response.text();
      }
    } catch (e) {
      data = null;
    }

    return {
      success: response.ok,
      status: response.status,
      statusText: response.statusText,
      data,
      headers: Object.fromEntries(response.headers.entries()),
      url: response.url,
      timestamp: Date.now(),
      requestId: response.headers.get('X-Request-ID')
    };
  }
});

const httpThrow = new HttpClient({
  throwOnError: true,
  responseHandler: async (response) => {
    const contentType = response.headers.get('content-type');
    let data;

    if (contentType?.includes('application/json')) {
      data = await response.json();
    } else if (contentType?.includes('text/')) {
      data = await response.text();
    } else if (contentType?.includes('application/octet-stream')) {
      data = await response.blob();
    } else {
      data = await response.text();
    }

    const result = {
      status: response.status,
      data,
      message: data?.message || response.statusText,
      meta: data?.meta,
      timestamp: Date.now(),
      headers: Object.fromEntries(response.headers.entries()),
      requestId: response.headers.get('X-Request-ID')
    };

    if (!response.ok) {
      throw new HttpError(
        result.message || 'Server Error',
        result.status,
        result
      );
    }

    return result;
  }
});

/**
 * SimpleFetch - A lightweight fetch wrapper for easy HTTP requests
 * Zero dependencies, auto-parsing, and convenient methods
 *
 * @example
 * // Get JSON data directly
 * const users = await simpleFetch.json('/api/users');
 *
 * // Post data easily
 * const result = await simpleFetch.post('/api/users', {name: 'John'});
 *
 * // Get full response object
 * const response = await simpleFetch('/api/users');
 * console.log(response.data, response.success, response.status);
 */
const simpleFetch = {
  // Configuration
  config: {
    baseURL: '',
    defaultHeaders: {
      'X-Requested-With': 'XMLHttpRequest'
    },
    timeout: 15000
  },

  /**
   * Core fetch method with auto-parsing and consistent response format
   */
  async fetch(url, options = {}) {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), this.config.timeout);

    try {
      // Build final URL
      const finalUrl = /^(https?:)?\/\//.test(url) ? url : `${this.config.baseURL}${url}`;

      // Prepare request config
      const config = applyRequestLanguage({
        method: 'GET',
        headers: {
          ...this.config.defaultHeaders
        },
        credentials: 'same-origin',
        signal: controller.signal,
        ...options
      });

      // Merge headers
      if (options.headers) {
        config.headers = {...config.headers, ...options.headers};
      }

      // CSRF support (share logic with HttpClient defaults)
      const csrfToken = getCsrfToken(this.config.csrfCookieName || 'XSRF-TOKEN', this.config.csrfSelector || 'meta[name="csrf-token"]');
      if (csrfToken) {
        config.headers[this.config.csrfHeaderName || 'X-CSRF-Token'] = csrfToken;
        if (['POST', 'PUT', 'PATCH', 'DELETE'].includes((config.method || 'GET').toUpperCase())) {
          if (config.body instanceof FormData) {
            if (!config.body.has('_token')) config.body.append('_token', csrfToken);
          } else if (config.body && typeof config.body === 'object') {
            if (!config.body._token) config.body._token = csrfToken;
          }
        }
      }

      // Attach Authorization header from localStorage token if present
      try {
        const token = localStorage.getItem('auth_token');
        if (token) {
          config.headers['Authorization'] = `Bearer ${token}`;
        }
      } catch (e) {}

      // Handle body
      if (config.body && typeof config.body === 'object' && !(config.body instanceof FormData)) {
        config.headers['Content-Type'] = config.headers['Content-Type'] || 'application/json';
        if (config.headers['Content-Type'].includes('application/json')) {
          config.body = JSON.stringify(config.body);
        }
      }

      if (config.headers && (config.headers['Content-Type'] === undefined || config.headers['Content-Type'] === null)) {
        delete config.headers['Content-Type'];
      }

      if (!config.body && config.headers) {
        delete config.headers['Content-Type'];
      }

      if (config.body instanceof FormData && config.headers) {
        delete config.headers['Content-Type'];
      }

      // Make request
      const response = await fetch(finalUrl, config);
      clearTimeout(timeoutId);

      // Auto-parse response
      const contentType = response.headers.get('content-type') || '';
      let data;

      try {
        if (contentType.includes('application/json')) {
          data = await response.json();
        } else if (contentType.includes('text/')) {
          data = await response.text();
        } else if (contentType.includes('application/octet-stream') || contentType.includes('application/pdf')) {
          data = await response.blob();
        } else {
          // Try JSON first, fallback to text
          const text = await response.text();
          try {
            data = JSON.parse(text);
          } catch {
            data = text;
          }
        }
      } catch (parseError) {
        data = null;
      }

      return {
        success: response.ok,
        status: response.status,
        statusText: response.statusText,
        data,
        headers: Object.fromEntries(response.headers.entries()),
        url: response.url
      };

    } catch (error) {
      clearTimeout(timeoutId);

      return {
        success: false,
        status: error.name === 'AbortError' ? 408 : 0,
        statusText: error.name === 'AbortError' ? 'Request Timeout' : error.message,
        data: null,
        headers: {},
        url: url,
        error: error
      };
    }
  },

  /**
   * GET request - returns full response object
   */
  async get(url, options = {}) {
    return this.fetch(url, {...options, method: 'GET'});
  },

  /**
   * POST request - returns full response object
   */
  async post(url, data = null, options = {}) {
    return this.fetch(url, {
      ...options,
      method: 'POST',
      body: data
    });
  },

  /**
   * PUT request - returns full response object
   */
  async put(url, data = null, options = {}) {
    return this.fetch(url, {
      ...options,
      method: 'PUT',
      body: data
    });
  },

  /**
   * DELETE request - returns full response object
   */
  async delete(url, options = {}) {
    return this.fetch(url, {...options, method: 'DELETE'});
  },

  /**
   * PATCH request - returns full response object
   */
  async patch(url, data = null, options = {}) {
    return this.fetch(url, {
      ...options,
      method: 'PATCH',
      body: data
    });
  },

  // ========== CONVENIENCE METHODS (return data directly) ==========

  /**
   * GET request - returns parsed JSON data directly
   * @param {string} url - Request URL
   * @param {object} options - Fetch options
   * @returns {Promise<any>} Parsed data or null on error
   */
  async json(url, options = {}) {
    const response = await this.get(url, options);
    return response.success ? response.data : null;
  },

  /**
   * GET request - returns text data directly
   * @param {string} url - Request URL
   * @param {object} options - Fetch options
   * @returns {Promise<string>} Text data or null on error
   */
  async text(url, options = {}) {
    const response = await this.get(url, options);
    return response.success ? response.data : null;
  },

  /**
   * POST JSON data - returns data directly
   * @param {string} url - Request URL
   * @param {object} data - Data to send
   * @param {object} options - Fetch options
   * @returns {Promise<any>} Response data or null on error
   */
  async postJson(url, data, options = {}) {
    const response = await this.post(url, data, options);
    return response.success ? response.data : null;
  },

  /**
   * Upload files using FormData
   * @param {string} url - Upload URL
   * @param {File|File[]|FormData} files - Files to upload
   * @param {object} data - Additional form data
   * @param {object} options - Fetch options
   * @returns {Promise<object>} Response object
   */
  async upload(url, files, data = {}, options = {}) {
    let formData;

    if (files instanceof FormData) {
      formData = files;
    } else {
      formData = new FormData();

      if (Array.isArray(files)) {
        files.forEach((file, index) => {
          formData.append(`file${index}`, file);
        });
      } else if (files) {
        formData.append('file', files);
      }
    }

    // Add additional data
    Object.entries(data).forEach(([key, value]) => {
      formData.append(key, value);
    });

    // Remove content-type header to let browser set it with boundary
    const headers = {...options.headers};
    delete headers['Content-Type'];

    return this.post(url, formData, {...options, headers});
  },

  // ========== CONFIGURATION METHODS ==========

  /**
   * Set base URL for all requests
   * @param {string} url - Base URL
   */
  setBaseURL(url) {
    this.config.baseURL = url.endsWith('/') ? url.slice(0, -1) : url;
    return this;
  },

  /**
   * Set default headers
   * @param {object} headers - Headers object
   */
  setHeaders(headers) {
    this.config.defaultHeaders = {...this.config.defaultHeaders, ...headers};
    return this;
  },

  /**
   * Set single header
   * @param {string} name - Header name
   * @param {string} value - Header value
   */
  setHeader(name, value) {
    this.config.defaultHeaders[name] = value;
    return this;
  },

  /**
   * Set request timeout
   * @param {number} timeout - Timeout in milliseconds
   */
  setTimeout(timeout) {
    this.config.timeout = timeout;
    return this;
  },

  /**
   * Remove a default header
   * @param {string} name - Header name to remove
   */
  removeHeader(name) {
    delete this.config.defaultHeaders[name];
    return this;
  },

  /**
   * Reset configuration to defaults
   */
  resetConfig() {
    this.config = {
      baseURL: '',
      defaultHeaders: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      timeout: 15000
    };
    return this;
  }
};

/**
 * httpAction - HTTP client that automatically processes responses through ResponseHandler.
 *
 * Combines `window.http` with `ResponseHandler.process()` so the caller gets the full
 * response object back AND all server-driven actions (notifications, redirects, modals, etc.)
 * are executed automatically — without needing manual ResponseHandler calls.
 *
 * @example
 * // Post an action and let ResponseHandler handle notifications/redirects automatically
 * const response = await httpAction.post(`${WEB_URL}api/board/action`, {
 *   module_id: moduleId,
 *   action: 'delete',
 *   id: id
 * });
 * if (response.success) { ... }
 *
 * @example
 * // Pass extra context to ResponseHandler (e.g. for modal or reload callbacks)
 * const response = await httpAction.get(url, {}, { reload: () => loadData() });
 */
const httpAction = {
  normalizeQueryOptions(options = {}) {
    if (!options || typeof options !== 'object' || Array.isArray(options)) {
      return options;
    }

    if (options.params !== undefined) {
      return options;
    }

    const fetchOptionKeys = new Set([
      'method',
      'headers',
      'body',
      'credentials',
      'signal',
      'cache',
      'redirect',
      'mode',
      'referrer',
      'referrerPolicy',
      'integrity',
      'keepalive',
      'priority',
      'throwOnError'
    ]);

    const optionKeys = Object.keys(options);
    const looksLikeFetchOptions = optionKeys.some(key => fetchOptionKeys.has(key));

    if (looksLikeFetchOptions) {
      return options;
    }

    return {
      params: options
    };
  },

  /**
   * Internal dispatcher: runs http[method], then ResponseHandler.process() on the result.
   * @param {string} method - HTTP method name on window.http
   * @param {Array} args - Arguments forwarded to http[method]
   * @param {object} [context={}] - Extra context forwarded to ResponseHandler.process()
   * @returns {Promise<object>} The full response object from HttpClient
   */
  async _dispatch(method, args, context = {}) {
    const response = await window.http[method](...args);

    // ResponseHandler.process() expects the inner `data` payload, not the outer HttpClient wrapper.
    // The inner payload is response.data when the server returns JSON, otherwise skip processing.
    if (window.ResponseHandler && response) {
      const payload = response?.data?.data ?? response?.data ?? response;
      await window.ResponseHandler.process(payload, context);
    }

    return response;
  },

  /**
   * GET request with automatic ResponseHandler processing.
   * @param {string} url
   * @param {object} [options={}]
   * @param {object} [context={}]
   * @returns {Promise<object>}
   */
  async get(url, options = {}, context = {}) {
    return this._dispatch('get', [url, this.normalizeQueryOptions(options)], context);
  },

  /**
   * POST request with automatic ResponseHandler processing.
   * @param {string} url
   * @param {*} data
   * @param {object} [options={}]
   * @param {object} [context={}]
   * @returns {Promise<object>}
   */
  async post(url, data, options = {}, context = {}) {
    return this._dispatch('post', [url, data, options], context);
  },

  /**
   * PUT request with automatic ResponseHandler processing.
   * @param {string} url
   * @param {*} data
   * @param {object} [options={}]
   * @param {object} [context={}]
   * @returns {Promise<object>}
   */
  async put(url, data, options = {}, context = {}) {
    return this._dispatch('put', [url, data, options], context);
  },

  /**
   * PATCH request with automatic ResponseHandler processing.
   * @param {string} url
   * @param {*} data
   * @param {object} [options={}]
   * @param {object} [context={}]
   * @returns {Promise<object>}
   */
  async patch(url, data, options = {}, context = {}) {
    return this._dispatch('patch', [url, data, options], context);
  },

  /**
   * DELETE request with automatic ResponseHandler processing.
   * @param {string} url
   * @param {object} [options={}]
   * @param {object} [context={}]
   * @returns {Promise<object>}
   */
  async delete(url, options = {}, context = {}) {
    return this._dispatch('delete', [url, this.normalizeQueryOptions(options)], context);
  },

  /**
   * File upload with automatic ResponseHandler processing.
   * @param {string} url
   * @param {File|File[]|FormData} files
   * @param {object} [options={}]
   * @param {object} [context={}]
   * @returns {Promise<object>}
   */
  async upload(url, files, options = {}, context = {}) {
    return this._dispatch('upload', [url, files, options], context);
  }
};

function getCsrfToken(cookieName = 'XSRF-TOKEN', selector = 'meta[name="csrf-token"]') {
  const metaToken = document.querySelector(selector);
  if (metaToken) return metaToken.getAttribute('content');

  const cookies = document.cookie.split(';');
  for (const cookie of cookies) {
    const [name, value] = cookie.trim().split('=');
    if (name === cookieName) return decodeURIComponent(value);
  }
  return null;
}

window.HttpClient = HttpClient;
window.http = http;
window.httpThrow = httpThrow;
window.simpleFetch = simpleFetch;
window.httpAction = httpAction;