/**
 * ApiComponent - Component for declarative API data loading and rendering
 * Provides HTML-based configuration for API calls with automatic rendering via TemplateManager
 *
 * Security: Uses window.http exclusively (no fallbacks) for consistent CSRF/interceptor protection
 *
 * @example
 * <div data-component="api" data-endpoint="/api/users" data-method="GET">
 *   <template>
 *     <ul><li data-for="user of data">{user.name}</li></ul>
 *   </template>
 * </div>
 */
const ApiComponent = {
  config: {
    // HTTP configuration
    method: 'GET',        // HTTP method (GET, POST, PUT, DELETE)
    baseURL: '',          // Base URL for API endpoints
    autoload: true,       // Auto-load data when instance is created
    timeout: 30000,       // Request timeout (ms)
    cache: false,         // Enable response caching
    cacheTime: 60000,     // Cache duration (ms)
    retry: 3,             // Number of retry attempts on failure

    // Event handlers
    onSuccess: null,      // Callback on successful load
    onError: null,        // Callback on error
    onEmpty: null        // Callback when no data returned
  },

  state: {
    instances: new Map(),  // Store all active instances
    initialized: false,
    cache: new Map()       // API response cache
  },

  /**
   * Create a new ApiComponent instance
   * @param {HTMLElement|string} element - DOM element or selector
   * @param {Object} options - Configuration options
   * @returns {Object|null} Instance object or null on error
   */
  create(element, options = {}) {
    // Verify HttpClient is available (fail-fast)
    if (!window.http || typeof window.http.get !== 'function') {
      const errorMsg = 'HttpClient (window.http) is required for ApiComponent';
      console.error('[ApiComponent]', errorMsg);
      const translatedMsg = window.Now?.translate ? Now.translate(errorMsg) : errorMsg;
      alert(translatedMsg);
      return null;
    }

    // Find element if selector string provided
    if (typeof element === 'string') {
      element = document.querySelector(element);
    }

    if (!element) {
      console.error('[ApiComponent] Element not found');
      return null;
    }

    // Check if instance already exists
    const existingInstance = this.getInstance(element);
    if (existingInstance) {
      return existingInstance;
    }

    // Create new instance
    const resolvedOptions = {...this.config, ...this.extractOptionsFromElement(element), ...options};

    const instance = {
      id: 'api_' + Math.random().toString(36).substring(2, 11),
      element,
      options: resolvedOptions,
      data: null,
      loading: false,
      error: null,
      timestamp: null,
      page: 1,
      totalPages: 1,
      pageSize: 10,
      totalItems: 0,
      polling: false,
      timer: null,
      abortController: null
    };

    // Initialize instance
    this.setup(instance);

    // Store instance
    this.state.instances.set(instance.id, instance);
    element.dataset.apiComponentId = instance.id;

    // Store reference on element for easy access from HTML
    element.apiInstance = instance;

    return instance;
  },

  /**
   * Setup and initialize an instance
   * @param {Object} instance - Instance object
   */
  setup(instance) {
    try {
      // Add api-component class without changing existing structure
      instance.element.classList.add('api-component');

      // Store template if present
      const templateEl = instance.element.querySelector('template');
      if (templateEl) {
        instance.templateContent = templateEl.innerHTML;
      } else if (instance.options.template) {
        // External template by ID
        const externalTemplate = document.getElementById(instance.options.template);
        if (externalTemplate && externalTemplate.tagName === 'TEMPLATE') {
          instance.templateContent = externalTemplate.innerHTML;
        }
      }

      // Bind events
      this.bindEvents(instance);

      // Auto-load data if enabled
      if (instance.options.autoload) {
        this.loadData(instance);
      }

      // Start polling if configured
      if (instance.options.polling && instance.options.pollingInterval) {
        this.startPolling(instance);
      }

      // Dispatch init event
      this.dispatchEvent(instance, 'init', {
        instance
      });

    } catch (error) {
      console.error('[ApiComponent] Setup error:', error);
      instance.error = error.message;
      this.renderError(instance);
    }
  },

  /**
   * Bind event handlers to instance
   * @param {Object} instance - Instance object
   */
  bindEvents(instance) {
    // Bind refresh events if configured
    if (instance.options.refreshEvent) {
      const events = instance.options.refreshEvent.split(',').map(e => e.trim());

      events.forEach(eventName => {
        if (!eventName) return;

        const handler = () => this.refresh(instance);

        // Store handlers for cleanup
        if (!instance.eventHandlers) {
          instance.eventHandlers = new Map();
        }

        instance.eventHandlers.set(eventName, handler);

        // Register event
        if (window.EventManager) {
          EventManager.on(eventName, handler);
        } else {
          document.addEventListener(eventName, handler);
        }
      });
    }

    // Add methods to instance for HTML access
    instance.refresh = () => {
      this.refresh(instance);
    };

    instance.loadMore = () => {
      this.loadMore(instance);
    };
  },

  /**
   * Load data from API
   * @param {Object} instance - Instance object
   * @param {boolean} append - Append to existing data (for pagination)
   */
  async loadData(instance, append = false, requestOptions = {}) {
    try {
      // Cancel previous request if exists
      if (instance.abortController) {
        instance.abortController.abort();
      }

      // Create new AbortController
      instance.abortController = new AbortController();

      instance.loading = false;
      instance.error = null;

      // Validate endpoint
      if (!instance.options.endpoint) {
        throw new Error('API endpoint is required');
      }

      // Prepare URL
      let url = instance.options.endpoint;
      if (instance.options.baseURL && !url.startsWith('http://') && !url.startsWith('https://')) {
        url = instance.options.baseURL + url;
      }

      // Prepare parameters
      const params = {...instance.options.params};

      // Add URL params if enabled
      if (instance.options.urlParams) {
        const urlParamsData = this.collectUrlParams(instance);

        // Check required params
        if (instance.options.urlParamsRequired) {
          const fields = instance.options.urlParamsFields?.split(',').map(f => f.trim()) || [];
          const missing = fields.filter(f => !urlParamsData[f]);

          if (missing.length > 0 || (fields.length > 0 && Object.keys(urlParamsData).length === 0)) {
            const errorMsg = window.Now?.translate
              ? Now.translate('Missing required URL parameters')
              : 'Missing required URL parameters';
            instance.error = errorMsg;
            instance.loading = false;
            this.renderError(instance);
            this.dispatchEvent(instance, 'error', {error: errorMsg, missingParams: missing});
            return;
          }
        }

        Object.assign(params, urlParamsData);
      }

      // Add pagination params
      if (instance.options.pagination !== false) {
        if (instance.options.pageParam) {
          params[instance.options.pageParam] = instance.page;
        }
        if (instance.options.limitParam) {
          params[instance.options.limitParam] = instance.options.pageSize || instance.pageSize || 10;
        }
      }

      const method = instance.options.method.toUpperCase();
      const requestBody = method === 'GET' ? null : (instance.options.data || params);

      if (method === 'GET' && Object.keys(params).length > 0) {
        const queryParams = new URLSearchParams(params).toString();
        url = `${url}${url.includes('?') ? '&' : '?'}${queryParams}`;
      }

      const useCache = this.shouldUseCache(instance, requestOptions, method);
      const cacheTime = this.normalizeCacheTime(instance.options.cacheTime, this.config.cacheTime);
      const cacheKey = this.createCacheKey({method, url, data: requestBody});

      if (useCache) {
        const cachedResponse = this.getCachedResponse(cacheKey);
        if (cachedResponse) {
          this.updateDataFromResponse(instance, cachedResponse, append);
          instance.error = null;

          this.dispatchEvent(instance, 'loaded', {
            data: instance.data,
            response: cachedResponse,
            fromCache: true
          });

          this.renderContent(instance);

          if (typeof instance.options.onSuccess === 'function') {
            instance.options.onSuccess.call(instance, instance.data, cachedResponse);
          }
          return;
        }
      }

      // Show loading state only when a network request is required.
      instance.loading = true;
      this.renderLoading(instance);

      // Prepare options
      const reqOptions = this.buildRequestOptions(instance, useCache, requestOptions);

      // Call API using HttpClient ONLY - no fallbacks
      if (!window.http || typeof window.http.request !== 'function') {
        throw new Error('HttpClient (window.http) is required but not available');
      }

      let response;

      // Execute request based on method
      switch (method) {
        case 'POST':
          response = await window.http.post(url, requestBody, reqOptions);
          break;
        case 'PUT':
          response = await window.http.put(url, requestBody, reqOptions);
          break;
        case 'DELETE':
          response = await window.http.delete(url, reqOptions);
          break;
        default: // GET
          response = await window.http.get(url, reqOptions);
      }

      // Handle 403 Forbidden - redirect to 403 page
      if (response?.status === 403) {
        console.warn('ApiComponent: Access forbidden (403) for API data');

        const forbiddenMessage = response?.data?.message || response?.data?.data?.message || '';
        const forbiddenParams = forbiddenMessage ? {message: forbiddenMessage} : {};
        const forbiddenUrl = forbiddenMessage ? `/403?message=${encodeURIComponent(forbiddenMessage)}` : '/403';

        if (window.RouterManager?.navigate) {
          window.RouterManager.navigate('/403', forbiddenParams);
          return;
        }

        if (window.LocationManager?.redirect) {
          window.LocationManager.redirect(forbiddenUrl);
          return;
        }

        window.location.href = forbiddenUrl;
        return;
      }

      // Handle 401 Unauthorized - redirect to login
      if (response?.status === 401) {
        console.warn('ApiComponent: Unauthorized (401) for API data');

        if (window.RouterManager?.navigate) {
          window.RouterManager.navigate('/login');
          return;
        }

        if (window.LocationManager?.redirect) {
          window.LocationManager.redirect('/login');
          return;
        }

        window.location.href = '/login';
        return;
      }

      if (!response.success) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }

      // Update data from response
      this.updateDataFromResponse(instance, response, append);

      if (useCache && response?.success) {
        this.setCachedResponse(cacheKey, response, cacheTime);
      }

      // Clear error
      instance.error = null;

      // Dispatch loaded event
      this.dispatchEvent(instance, 'loaded', {
        data: instance.data,
        response,
        fromCache: false
      });

      // Render content
      this.renderContent(instance);

      // Call success callback if provided
      if (typeof instance.options.onSuccess === 'function') {
        instance.options.onSuccess.call(instance, instance.data, response);
      }

    } catch (error) {
      // Handle 403 Forbidden - redirect to 403 page
      if (error?.status === 403 || error?.response?.status === 403) {
        console.warn('ApiComponent: Access forbidden (403) for API data');

        const forbiddenMessage = error?.response?.data?.message || error?.response?.data?.data?.message || error?.message || '';
        const forbiddenParams = forbiddenMessage ? {message: forbiddenMessage} : {};
        const forbiddenUrl = forbiddenMessage ? `/403?message=${encodeURIComponent(forbiddenMessage)}` : '/403';

        if (window.RouterManager?.navigate) {
          window.RouterManager.navigate('/403', forbiddenParams);
          return;
        }

        if (window.LocationManager?.redirect) {
          window.LocationManager.redirect(forbiddenUrl);
          return;
        }

        window.location.href = forbiddenUrl;
        return;
      }

      // Handle 401 Unauthorized - redirect to login
      if (error?.status === 401 || error?.response?.status === 401) {
        console.warn('ApiComponent: Unauthorized (401) for API data');

        if (window.RouterManager?.navigate) {
          window.RouterManager.navigate('/login');
          return;
        }

        if (window.LocationManager?.redirect) {
          window.LocationManager.redirect('/login');
          return;
        }

        window.location.href = '/login';
        return;
      }

      // Handle errors
      instance.error = error.message;
      console.error('[ApiComponent] Load error:', error);

      // Dispatch error event
      this.dispatchEvent(instance, 'error', {
        error: instance.error,
        message: error.message
      });

      // Render error
      this.renderError(instance);

      // Call error callback if provided
      if (typeof instance.options.onError === 'function') {
        instance.options.onError.call(instance, error);
      }

    } finally {
      // Update state
      instance.loading = false;
      instance.abortController = null;
    }
  },

  /**
   * Create cache key for request
   * @param {Object} instance - Instance object
   * @returns {string} Cache key
   */
  createCacheKey({method = 'GET', url = '', data = null} = {}) {
    const dataString = data ? JSON.stringify(data) : '';
    return `${method}:${url}:${dataString}`;
  },

  normalizeCacheTime(value, fallback = 60000) {
    const parsed = parseInt(value, 10);
    return Number.isFinite(parsed) && parsed >= 0 ? parsed : fallback;
  },

  shouldUseCache(instance, requestOptions = {}, method = 'GET') {
    if (requestOptions.force === true) {
      return false;
    }
    return method === 'GET' && instance?.options?.cache === true;
  },

  getCachedResponse(cacheKey) {
    const cached = this.state.cache.get(cacheKey);
    if (!cached) return null;

    if (cached.expiresAt <= Date.now()) {
      this.state.cache.delete(cacheKey);
      return null;
    }

    return cached.response;
  },

  setCachedResponse(cacheKey, response, cacheTime) {
    this.state.cache.set(cacheKey, {
      response,
      timestamp: Date.now(),
      expiresAt: Date.now() + cacheTime
    });
  },

  buildRequestOptions(instance, useCache, requestOptions = {}) {
    const headers = {
      ...(instance.options.headers || {})
    };

    const options = {
      throwOnError: false,
      ...requestOptions,
      headers
    };

    delete options.force;

    if (useCache) {
      options.cache = 'default';
      return options;
    }

    options.cache = 'no-store';
    options.headers = {
      'Cache-Control': 'no-cache',
      'Pragma': 'no-cache',
      ...headers
    };

    return options;
  },

  /**
   * Collect URL query parameters
   * Supports both regular query string and hash-based SPA routing
   * @param {Object} instance - Instance object
   * @returns {Object} URL parameters object
   */
  collectUrlParams(instance) {
    const urlParams = new URLSearchParams(window.location.search);

    // Also check hash fragment for SPA hash-mode routing
    // e.g., /#/activate?id=abc becomes location.hash = "#/activate?id=abc"
    const hashPart = window.location.hash;
    const hashQueryIndex = hashPart.indexOf('?');
    if (hashQueryIndex !== -1) {
      const hashParams = new URLSearchParams(hashPart.substring(hashQueryIndex));
      // Merge hash params into urlParams (hash params take precedence)
      for (const [key, value] of hashParams.entries()) {
        urlParams.set(key, value);
      }
    }

    // Filter by specified fields if configured
    const fields = instance.options.urlParamsFields;
    if (fields) {
      const allowedFields = fields.split(',').map(f => f.trim());
      const filtered = {};
      allowedFields.forEach(field => {
        if (urlParams.has(field)) {
          filtered[field] = urlParams.get(field);
        }
      });
      return filtered;
    }

    return Object.fromEntries(urlParams.entries());
  },

  /**
   * Update instance data from API response
   * @param {Object} instance - Instance object
   * @param {Object} response - HTTP response
   * @param {boolean} append - Append mode flag
   */
  updateDataFromResponse(instance, response, append = false) {
    // Extract data from response using configured path
    const dataPath = instance.options.dataPath || 'data';
    let responseData;

    // response.data is the HTTP response body (e.g., {success: true, data: {...}})
    const responseBody = response.data;

    // Get data by path from response body
    if (dataPath === 'data') {
      // Default path: extract responseBody.data if it exists (standard API response format)
      // Otherwise use the whole response body
      responseData = responseBody?.data ?? responseBody;
    } else {
      // Custom dataPath: extract from response body using path
      responseData = this.getValueByPath(responseBody, dataPath);
    }

    // Handle empty data
    if (!responseData) {
      instance.data = [];

      // Dispatch empty event
      this.dispatchEvent(instance, 'empty');

      // Call empty callback if provided
      if (typeof instance.options.onEmpty === 'function') {
        instance.options.onEmpty.call(instance);
      }

      return;
    }

    // Append or replace data
    if (append && Array.isArray(responseData) && Array.isArray(instance.data)) {
      instance.data = [...instance.data, ...responseData];
    } else {
      instance.data = responseData;
    }

    // Update pagination info
    if (instance.options.pagination !== false) {
      const metaPath = instance.options.metaPath || 'meta';
      const meta = this.getValueByPath(response, metaPath) || {};

      instance.totalItems = meta.total || 0;
      instance.totalPages = meta.last_page || Math.ceil(instance.totalItems / instance.pageSize) || 1;
    }

    // Record timestamp
    instance.timestamp = Date.now();
  },

  /**
   * Get value from object by dot-notation path
   * @param {Object} obj - Source object
   * @param {string} path - Dot-notation path (e.g., 'data.users')
   * @returns {*} Value at path
   */
  getValueByPath(obj, path) {
    return path.split('.').reduce((o, p) => o?.[p], obj);
  },

  /**
   * Render loading state
   * @param {Object} instance - Instance object
   */
  renderLoading(instance) {
    const element = instance.element;

    // Clear other state classes
    element.classList.remove('api-error', 'api-empty', 'api-content');

    // Add loading class
    element.classList.add('api-loading');
  },

  /**
   * Render error state
   * @param {Object} instance - Instance object
   */
  renderError(instance) {
    const element = instance.element;

    // Clear other state classes
    element.classList.remove('api-loading', 'api-empty', 'api-content');

    // Add error class
    element.classList.add('api-error');

    // Log error
    console.error('[ApiComponent] Error:', instance.error);
  },

  /**
   * Render content with data
   * @param {Object} instance - Instance object
   */
  renderContent(instance) {
    const element = instance.element;

    // Clear state classes
    element.classList.remove('api-loading', 'api-error', 'api-empty');

    // Handle empty data
    if (!instance.data || (Array.isArray(instance.data) && instance.data.length === 0)) {
      element.classList.add('api-empty');
      return;
    }

    // Add content class
    element.classList.add('api-content');

    // Create context for template processing - flatten data for direct field access
    const fieldData = instance.data?.data || instance.data;
    const context = {
      state: fieldData,
      data: fieldData,
      computed: {}
    };

    // Use TemplateManager to process data-text, data-attr, data-if, etc. bindings
    // This provides full expression support (e.g., data-attr="href:'...' + product_id")
    if (window.TemplateManager) {
      if (typeof TemplateManager.processDataDirectives === 'function') {
        TemplateManager.processDataDirectives(element, context);
      }

      // Run data-on-load hooks inside the component with the normalized data payload
      try {
        TemplateManager.processDataOnLoad(element, context);
      } catch (e) {
        console.warn('ApiComponent: data-on-load after load failed', e);
      }
    }

    // Handle data-attr="data:fieldName" for TableManager and LineItemsManager
    // Use retry mechanism with setInterval to wait for components to be ready
    const optionsData = instance.data?.options || fieldData?.options;
    const elementsWithDataAttr = element.querySelectorAll('[data-attr]');
    elementsWithDataAttr.forEach(el => {
      const attrValue = el.getAttribute('data-attr');
      const dataMatch = attrValue?.match(/data:(\w+)/);

      if (dataMatch && dataMatch[1]) {
        const dataFieldName = dataMatch[1];
        const boundData = fieldData?.[dataFieldName];

        if (boundData !== undefined) {
          // Determine which manager handles this element
          const isTable = el.matches('table[data-table]');
          const isLineItems = el.matches('table[data-line-items]');

          if (isTable && window.TableManager) {
            const tableId = el.getAttribute('data-table');
            const getRegisteredTable = () => TableManager.state?.tables?.get(tableId) || null;

            const ensureTableReady = () => {
              const registeredTable = getRegisteredTable();
              if (registeredTable && registeredTable.element === el && registeredTable.element?.isConnected) {
                return true;
              }

              if (typeof TableManager.initTable === 'function') {
                try {
                  TableManager.initTable(el);
                } catch (error) {
                  console.warn(`[ApiComponent] Failed to initialize table '${tableId}' before binding`, error);
                }
              }

              const nextTable = getRegisteredTable();
              return Boolean(nextTable && nextTable.element === el && nextTable.element?.isConnected);
            };

            const setTableData = () => {
              const tableObj = getRegisteredTable();
              if (optionsData && tableObj) {
                if (!tableObj.dataOptions) tableObj.dataOptions = {};
                Object.assign(tableObj.dataOptions, optionsData);
              }
              if (boundData.columns && boundData.data) {
                TableManager.setData(tableId, boundData);
              } else if (Array.isArray(boundData)) {
                TableManager.setData(tableId, boundData);
              }
            };

            if (ensureTableReady()) {
              setTableData();
            } else {
              let retryCount = 0;
              const maxRetries = 30;
              const checkInterval = setInterval(() => {
                retryCount++;
                if (ensureTableReady()) {
                  clearInterval(checkInterval);
                  setTableData();
                } else if (retryCount >= maxRetries) {
                  clearInterval(checkInterval);
                  console.warn(`[ApiComponent] TableManager table '${tableId}' not found after ${maxRetries} retries`);
                }
              }, 100);
            }
          } else if (isLineItems && window.LineItemsManager) {
            const extractLineItems = () => {
              if (Array.isArray(boundData)) return boundData;
              if (Array.isArray(boundData?.data)) return boundData.data;
              if (dataFieldName && Array.isArray(boundData?.[dataFieldName])) return boundData[dataFieldName];
              if (dataFieldName && Array.isArray(fieldData?.[dataFieldName]?.data)) {
                return fieldData[dataFieldName].data;
              }
              return null;
            };

            const setLineItemsData = () => {
              const lineItemsInstance = LineItemsManager.getInstance(el);
              const items = extractLineItems();
              if (lineItemsInstance && Array.isArray(items)) {
                lineItemsInstance.setData(items);
              }
            };

            if (LineItemsManager.state?.instances?.has(el)) {
              setLineItemsData();
            } else {
              let retryCount = 0;
              const maxRetries = 10;
              const checkInterval = setInterval(() => {
                retryCount++;
                if (LineItemsManager.state?.instances?.has(el)) {
                  clearInterval(checkInterval);
                  setLineItemsData();
                } else if (retryCount >= maxRetries) {
                  clearInterval(checkInterval);
                  console.warn(`[ApiComponent] LineItemsManager table not found after ${maxRetries} retries`);
                }
              }, 100);
            }
          }
        }
      }
    });

    // Dispatch content-rendered event
    this.dispatchEvent(instance, 'content-rendered', {
      data: instance.data
    });
  },

  /**
   * Apply simple template with {key} placeholders
   * @param {string} template - Template string
   * @param {Object} data - Data object
   * @returns {string} Processed template
   */
  applySimpleTemplate(template, data) {
    return template.replace(/\{([^}]+)\}/g, (match, path) => {
      return this.getValueByPath(data, path.trim()) ?? '';
    });
  },

  /**
   * Refresh instance data (reset to page 1)
   * @param {Object|string|HTMLElement} instance - Instance, ID, or element
   */
  refresh(instance) {
    // Resolve instance
    if (typeof instance === 'string') {
      instance = this.state.instances.get(instance);
    } else if (instance instanceof HTMLElement) {
      instance = this.getInstance(instance);
    }

    if (!instance) return;

    // Reset pagination
    instance.page = 1;

    // Reload data
    this.loadData(instance, false, {force: true});
  },

  /**
   * Load next page (pagination)
   * @param {Object|string|HTMLElement} instance - Instance, ID, or element
   */
  loadMore(instance) {
    // Resolve instance
    if (typeof instance === 'string') {
      instance = this.state.instances.get(instance);
    } else if (instance instanceof HTMLElement) {
      instance = this.getInstance(instance);
    }

    if (!instance) return;

    // Skip if already loading
    if (instance.loading) return;

    // Skip if no more pages
    if (instance.page >= instance.totalPages) return;

    // Increment page
    instance.page++;

    // Load data in append mode
    this.loadData(instance, true);
  },

  /**
   * Start polling for automatic data refresh
   * @param {Object|string|HTMLElement} instance - Instance, ID, or element
   */
  startPolling(instance) {
    // Resolve instance
    if (typeof instance === 'string') {
      instance = this.state.instances.get(instance);
    } else if (instance instanceof HTMLElement) {
      instance = this.getInstance(instance);
    }

    if (!instance) return;

    // Skip if already polling
    if (instance.polling) return;

    // Enable polling
    instance.polling = true;

    // Set interval
    const interval = parseInt(instance.options.pollingInterval) || 30000;
    instance.timer = setInterval(() => {
      // Load data if not currently loading
      if (!instance.loading) {
        this.loadData(instance, false, {force: true});
      }
    }, interval);

    // Dispatch polling:start event
    this.dispatchEvent(instance, 'polling:start');
  },

  /**
   * Stop polling
   * @param {Object|string|HTMLElement} instance - Instance, ID, or element
   */
  stopPolling(instance) {
    // Resolve instance
    if (typeof instance === 'string') {
      instance = this.state.instances.get(instance);
    } else if (instance instanceof HTMLElement) {
      instance = this.getInstance(instance);
    }

    if (!instance) return;

    // Skip if not polling
    if (!instance.polling) return;

    // Clear timer
    if (instance.timer) {
      clearInterval(instance.timer);
      instance.timer = null;
    }

    // Disable polling
    instance.polling = false;

    // Dispatch polling:stop event
    this.dispatchEvent(instance, 'polling:stop');
  },

  /**
   * Submit data (POST request)
   * @param {Object|string|HTMLElement} instance - Instance, ID, or element
   * @param {Object} data - Data to submit
   */
  async submit(instance, data) {
    // Resolve instance
    if (typeof instance === 'string') {
      instance = this.state.instances.get(instance);
    } else if (instance instanceof HTMLElement) {
      instance = this.getInstance(instance);
    }

    if (!instance) return;

    // Set method to POST
    instance.options.method = 'POST';

    // Set data
    if (data) {
      instance.options.data = data;
    }

    // Load data
    return this.loadData(instance);
  },

  /**
   * Format date value
   * @param {string|Date} value - Date value
   * @param {string} format - Format string (Y-m-d H:i:s)
   * @returns {string} Formatted date
   */
  formatDate(value, format) {
    if (!value) return '';

    try {
      const date = new Date(value);

      // Validate date
      if (isNaN(date.getTime())) {
        return value;
      }

      // Replace format tokens
      return format
        .replace('Y', date.getFullYear())
        .replace('m', String(date.getMonth() + 1).padStart(2, '0'))
        .replace('d', String(date.getDate()).padStart(2, '0'))
        .replace('H', String(date.getHours()).padStart(2, '0'))
        .replace('i', String(date.getMinutes()).padStart(2, '0'))
        .replace('s', String(date.getSeconds()).padStart(2, '0'));
    } catch (e) {
      return value;
    }
  },

  /**
   * Format number value
   * @param {number} value - Number value
   * @param {number} decimals - Decimal places
   * @returns {string} Formatted number
   */
  formatNumber(value, decimals) {
    if (value === null || value === undefined) return '';

    const num = parseFloat(value);
    if (isNaN(num)) return value;

    return num.toLocaleString(undefined, {
      minimumFractionDigits: parseInt(decimals),
      maximumFractionDigits: parseInt(decimals)
    });
  },

  /**
   * Format currency value
   * @param {number} value - Number value
   * @param {string} currency - Currency symbol
   * @param {number} decimals - Decimal places
   * @returns {string} Formatted currency
   */
  formatCurrency(value, currency, decimals) {
    if (value === null || value === undefined) return '';

    const num = parseFloat(value);
    if (isNaN(num)) return value;

    return currency + ' ' + this.formatNumber(num, decimals);
  },

  /**
   * Clear all cached responses
   */
  clearCache() {
    this.state.cache.clear();
  },

  /**
   * Dispatch custom event
   * @param {Object} instance - Instance object
   * @param {string} eventName - Event name (without 'api:' prefix)
   * @param {Object} detail - Event detail data
   */
  dispatchEvent(instance, eventName, detail = {}) {
    if (!instance.element) return;

    const event = new CustomEvent(`api:${eventName}`, {
      bubbles: true,
      cancelable: true,
      detail: {
        instance,
        element: instance.element,
        ...detail
      }
    });

    // Dispatch on element if it's still in the document (for bubbling)
    // Otherwise dispatch on document directly (for SPA navigation scenarios)
    if (document.contains(instance.element)) {
      instance.element.dispatchEvent(event);
    } else {
      // Element is disconnected from DOM, dispatch on document directly
      document.dispatchEvent(event);
    }

    EventManager.emit(`api:${eventName}`, {
      instance,
      ...detail
    });
  },

  /**
   * Extract options from element data attributes
   * @param {HTMLElement} element - DOM element
   * @returns {Object} Options object
   */
  extractOptionsFromElement(element) {
    const options = {};
    const dataset = element.dataset;

    // Try data-props first (JSON format)
    if (dataset.props) {
      try {
        const props = JSON.parse(dataset.props);
        Object.assign(options, props);
      } catch (e) {
        console.warn('[ApiComponent] Invalid JSON in data-props:', e);
      }
    }

    // Read from individual data-* attributes
    if (!options.endpoint && dataset.endpoint) options.endpoint = dataset.endpoint;
    if (!options.method && dataset.method) options.method = dataset.method.toUpperCase();
    if (!options.baseURL && dataset.baseUrl) options.baseURL = dataset.baseUrl;
    if (options.autoload === undefined && dataset.autoload !== undefined)
      options.autoload = dataset.autoload !== 'false';
    if (options.cache === undefined && dataset.cache !== undefined)
      options.cache = dataset.cache === 'true';
    if (!options.cacheTime && dataset.cacheTime)
      options.cacheTime = parseInt(dataset.cacheTime);
    if (!options.timeout && dataset.timeout)
      options.timeout = parseInt(dataset.timeout);
    if (!options.retry && dataset.retry)
      options.retry = parseInt(dataset.retry);
    if (!options.template && dataset.template)
      options.template = dataset.template;
    if (!options.refreshEvent && dataset.refreshEvent)
      options.refreshEvent = dataset.refreshEvent;

    // Pagination options
    if (!options.pagination && dataset.pagination)
      options.pagination = dataset.pagination !== 'false';
    if (!options.pageParam && dataset.pageParam)
      options.pageParam = dataset.pageParam;
    if (!options.limitParam && dataset.limitParam)
      options.limitParam = dataset.limitParam;
    if (!options.pageSize && dataset.pageSize)
      options.pageSize = parseInt(dataset.pageSize);

    // Parse JSON strings
    if (!options.params && dataset.params) {
      try {
        options.params = JSON.parse(dataset.params);
      } catch (e) {
        console.warn('[ApiComponent] Invalid JSON in data-params');
      }
    }

    if (!options.data && dataset.data) {
      try {
        options.data = JSON.parse(dataset.data);
      } catch (e) {
        console.warn('[ApiComponent] Invalid JSON in data-data');
      }
    }

    if (!options.headers && dataset.headers) {
      try {
        options.headers = JSON.parse(dataset.headers);
      } catch (e) {
        console.warn('[ApiComponent] Invalid JSON in data-headers');
      }
    }

    // Path options
    if (!options.dataPath && dataset.dataPath) options.dataPath = dataset.dataPath;
    if (!options.metaPath && dataset.metaPath) options.metaPath = dataset.metaPath;

    // URL parameter options
    if (options.urlParams === undefined && dataset.urlParams !== undefined)
      options.urlParams = dataset.urlParams === 'true';
    if (!options.urlParamsFields && dataset.urlParamsFields)
      options.urlParamsFields = dataset.urlParamsFields;
    if (options.urlParamsRequired === undefined && dataset.urlParamsRequired !== undefined)
      options.urlParamsRequired = dataset.urlParamsRequired === 'true';

    return options;
  },

  /**
   * Get instance from element
   * @param {HTMLElement|string} element - Element or selector
   * @returns {Object|null} Instance or null
   */
  getInstance(element) {
    if (typeof element === 'string') {
      element = document.querySelector(element);
    }

    if (!element) return null;

    // Check for stored reference
    if (element.apiInstance) {
      return element.apiInstance;
    }

    // Check by ID
    const id = element.dataset.apiComponentId;
    if (id && this.state.instances.has(id)) {
      return this.state.instances.get(id);
    }

    // Search all instances
    for (const instance of this.state.instances.values()) {
      if (instance.element === element) {
        return instance;
      }
    }

    return null;
  },

  /**
   * Destroy instance and cleanup
   * @param {Object|string|HTMLElement} instance - Instance, ID, or element
   * @returns {boolean} Success flag
   */
  destroy(instance) {
    // Resolve instance
    if (typeof instance === 'string') {
      instance = this.state.instances.get(instance);
    } else if (instance instanceof HTMLElement) {
      instance = this.getInstance(instance);
    }

    if (!instance) return false;

    // Stop polling if active
    if (instance.polling) {
      clearInterval(instance.timer);
    }

    // Cancel active request
    if (instance.abortController) {
      instance.abortController.abort();
    }

    // Remove event handlers
    if (instance.eventHandlers) {
      instance.eventHandlers.forEach((handler, eventName) => {
        if (window.EventManager) {
          EventManager.off(eventName, handler);
        } else {
          document.removeEventListener(eventName, handler);
        }
      });
    }

    // Clear data
    instance.data = null;
    instance.loading = false;
    instance.error = null;

    // Clean DOM
    if (instance.element) {
      delete instance.element.apiInstance;
      delete instance.element.dataset.apiComponentId;

      // Remove api-component class
      instance.element.classList.remove('api-component');
    }

    // Dispatch destroy event
    this.dispatchEvent(instance, 'destroy');

    // Remove from Map
    if (instance.id) {
      this.state.instances.delete(instance.id);
    }

    return true;
  },

  /**
   * Initialize ApiComponent
   * @param {Object} options - Configuration options
   * @returns {Object} ApiComponent instance
   */
  async init(options = {}) {
    // Update config
    this.config = {...this.config, ...options};

    // Find and initialize elements with data-component="api"
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', () => this.initElements());
    } else {
      this.initElements();
    }

    this.state.initialized = true;
    return this;
  },

  /**
   * Initialize all elements with data-component="api"
   */
  initElements() {
    document.querySelectorAll('[data-component="api"]').forEach(element => {
      this.create(element);
    });
  }
};

/**
 * Register Component with ComponentManager
 */
if (window.ComponentManager) {
  const apiComponentDefinition = {
    template: null,

    validElement(element) {
      return element.classList.contains('api-component') ||
        element.dataset.component === 'api' ||
        element.dataset.endpoint;
    },

    setupElement(element, state) {
      const options = ApiComponent.extractOptionsFromElement(element);
      const apiComponent = ApiComponent.create(element, options);

      element._apiComponent = apiComponent;
      return element;
    },

    beforeDestroy() {
      if (this.element && this.element._apiComponent) {
        ApiComponent.destroy(this.element._apiComponent);
        delete this.element._apiComponent;
      }
    }
  };

  ComponentManager.define('api', apiComponentDefinition);
}

/**
 * Register ApiComponent with Now.js framework
 */
if (window.Now?.registerManager) {
  Now.registerManager('api', ApiComponent);
}

// Auto-initialize
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => ApiComponent.init());
} else {
  ApiComponent.init();
}

// Expose globally
window.ApiComponent = ApiComponent;
