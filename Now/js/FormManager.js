const FormManager = {
  config: {
    ajaxSubmit: true,
    autoValidate: true,
    resetAfterSubmit: false,
    autoEnhance: true,
    autoInitialize: true,
    autoGenerateFormId: true,
    submitButtonSelector: '[type="submit"]',
    resetButtonSelector: '[type="reset"]',
    fieldSelector: 'input, select, textarea, [data-element]',
    preventDoubleSubmit: true,
    doubleSubmitTimeout: 2000,
    showLoadingOnSubmit: true,
    autoClearErrors: true,
    autoClearErrorsDelay: 5000,
    loadCache: false,
    loadCacheTime: 60000,
    loadOptionsCache: false,
    loadOptionsCacheTime: 300000,
    watchApi: '',
    watchMethod: 'GET',
    watchFields: [],
    watchTrigger: [],
    watchDebounce: 150,
    watchOnLoad: true,
    validateOnInput: true,
    validateOnBlur: true,
    validateOnlyDirty: true,
    validatorRegistry: new Map(),
    formatterRegistry: new Map(),
    loadingClass: 'submitting',
    validClass: 'valid',
    invalidClass: 'invalid',
    dirtyClass: 'modified',
    successMessage: 'Form submitted successfully',
    errorMessage: 'There was an error submitting the form',
    actionAttribute: 'data-action',
    methodAttribute: 'data-method',
    redirectAttribute: 'data-redirect',
    showErrorsInline: true,
    showErrorsInNotification: true,
    submitTarget: null,
    submitPaginationTarget: null,
    submitQueryParams: false,
    submitQueryFields: [],
    submitPageField: 'page',
    submitPaginationWindow: 5,
    uploadProgressTemplate: `
      <div class="upload-progress" style="display:none">
        <div class="progress">
          <div class="progress-bar" role="progressbar" style="width:0%"></div>
        </div>
        <div class="progress-text"></div>
      </div>
    `
  },

  // observer config defaults (can be overridden by options.observerConfig)
  observerConfig: {
    observerDelay: 40,
    observeRoot: null,
    autoStartObserver: true
  },

  state: {
    forms: new Map(),
    // element -> instance mapping (weak reference to avoid leaks)
    elementIndex: new WeakMap(),
    initialized: false,
    activeSubmit: null,
    validators: new Map()
  },

  async init(options = {}) {
    if (this.state.initialized) return this;

    this.config = {...this.config, ...options};
    if (options.observerConfig) this.observerConfig = {...this.observerConfig, ...options.observerConfig};

    if (window.SecurityManager && window.SecurityManager.state.initialized) {
      this.setupSecurityIntegration();
    }

    if (this.observerConfig.autoStartObserver) this.setupFormObserver();

    if (this.config.autoInitialize) {
      document.querySelectorAll('form[data-form]').forEach(form => {
        if (this.shouldEnhance(form)) this.initForm(form);
      });
    }

    this.registerStandardValidators();

    this.state.initialized = true;
    return this;
  },

  registerStandardValidators() {
    this.registerValidator('required', (value, element) => {
      if (element.type === 'checkbox' || element.type === 'radio') {
        return element.checked;
      }
      return value !== null && value !== undefined && value.toString().trim() !== '';
    }, 'Please fill in');

    this.registerValidator('email', (value) => {
      return !value || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
    }, 'Please enter a valid email address');

    this.registerValidator('url', (value) => {
      return !value || /^(https?|ftp):\/\/[^\s\/$.?#].[^\s]*$/.test(value);
    }, 'Please enter a valid URL');

    this.registerValidator('number', (value) => {
      return !value || /^-?\d*\.?\d+$/.test(value);
    }, 'Please enter a valid number');

    this.registerValidator('integer', (value) => {
      return !value || /^-?\d+$/.test(value);
    }, 'Please enter a whole number');

    this.registerValidator('min', (value, element, param) => {
      return !value || parseFloat(value) >= parseFloat(param);
    }, 'Value must be at least {min}');

    this.registerValidator('max', (value, element, param) => {
      return !value || parseFloat(value) <= parseFloat(param);
    }, 'Value must be no more than {max}');

    this.registerValidator('minlength', (value, element, param) => {
      return !value || value.length >= parseInt(param);
    }, 'Please enter at least {minlength} characters');

    this.registerValidator('maxlength', (value, element, param) => {
      const maxLength = parseInt(param);
      return !value || isNaN(maxLength) || maxLength <= 0 || value.length <= maxLength;
    }, 'Please enter no more than {maxlength} characters');

    this.registerValidator('pattern', (value, element, param) => {
      return !value || new RegExp(param).test(value);
    }, 'Please match the requested format');

    this.registerValidator('match', (value, element, param) => {
      const target = document.getElementById(param) ||
        document.getElementsByName(param)[0] ||
        element.form.querySelector(`[name="${param}"]`);
      return !value || !target || value === target.value;
    }, 'Fields do not match');
  },

  registerValidator(name, fn, defaultMessage) {
    this.state.validators.set(name, {
      validate: fn,
      message: defaultMessage
    });
  },

  registerFormatter(name, fn) {
    this.config.formatterRegistry.set(name, fn);
  },

  async initForm(form) {
    if (!form) return null;

    // Require explicit opt-in: form must have data-form attribute
    if (!form.dataset || !form.dataset.form) return null;

    const formId = form.dataset.form;

    // Defensive: avoid double-initialization. Prefer element identity (WeakMap) lookup
    // to handle cases where templates are re-rendered and new DOM nodes replace
    // old ones. If an instance exists for this exact element, return it.
    try {
      const existingByElement = this.state.elementIndex.get(form);
      if (existingByElement) {
        return existingByElement;
      }

      // If there's an instance registered by id, ensure it's not stale. If the
      // mapped instance targets a different element or the element is no longer
      // connected, destroy it so we can re-init cleanly.
      const existingById = this.state.forms.get(formId);
      if (existingById) {
        // Check if existing element is still connected to DOM
        if (existingById.element && !existingById.element.isConnected) {
          // Old element disconnected - destroy stale instance
          try {
            this.destroyForm(existingById);
          } catch (e) {
            // swallow; we'll continue to init
          }
        } else if (existingById.element === form) {
          // Same element, already initialized
          return existingById;
        } else if (existingById.element && existingById.element.isConnected) {
          // Different element but old one still connected - this is unusual
          // Could be duplicate IDs in DOM (invalid) - destroy old instance
          try {
            this.destroyForm(existingById);
          } catch (e) {
            // swallow; we'll continue to init
          }
        }
      }
    } catch (e) {
      // Non-fatal - continue to init
    }

    try {
      const instance = {
        id: formId,
        element: form,
        elements: new Map(),
        state: {
          modified: false,
          valid: true,
          submitting: false,
          data: {},
          originalData: {},
          errors: {},
          apiFieldErrors: {},
          invalidFieldDetails: [],
          submitCount: 0,
          lastSubmitTime: 0
        },
        resetTimeout: null
      };

      // Register in maps immediately to prevent double-initialization race condition
      this.state.forms.set(formId, instance);
      try {this.state.elementIndex.set(form, instance);} catch (e) {}

      const formConfig = this.extractFormConfig(form);
      instance.config = {...this.config, ...formConfig};

      if (form.dataset.autoFillIntendedUrl === 'true') {
        this.autoFillIntendedUrl(form);
      }

      // Restore persisted values for fields marked with data-persist
      try {
        this.restorePersistedValues && this.restorePersistedValues(instance);
      } catch (e) {}

      if (form.querySelector('input[type="file"]')) {
        form.enctype = 'multipart/form-data';
      }

      try {
        this.populateSelectsFromModalOptions(instance);
      } catch (e) {
        console.warn('FormManager: Failed to populate select options from modal:', e);
      }

      // Load form options BEFORE initFormElements so select elements have options
      try {
        await this.loadFormOptionsIfNeeded(instance);
      } catch (e) {
        console.warn('FormManager: Failed to load form options:', e);
      }

      // Load form data if data-load-api is specified
      // Errors (403, 401) are handled inside loadFormDataIfNeeded with redirects
      await this.loadFormDataIfNeeded(instance);


      // Initialize form elements AFTER options are loaded
      this.initFormElements(instance);

      // Initialize cascading selects if CascadingSelectManager is available
      if (window.CascadingSelectManager?.initInContainer) {
        CascadingSelectManager.initInContainer(form);
      }

      // Restore remembered username/email for login forms (localStorage only)
      try {
        this.restoreRememberedCredentials && this.restoreRememberedCredentials(instance);
      } catch (e) {}

      // Populate fields from URL query parameters (data-url-param attribute)
      try {
        this.populateFromUrlParams(instance);
      } catch (e) {
        console.warn('FormManager: Failed to populate from URL params:', e);
      }

      try {
        if (instance.config.watchApi && instance.config.watchOnLoad !== false) {
          await this.syncWatchedData(instance, {force: true});
        }
      } catch (e) {
        console.warn('FormManager: Failed to load watched form data:', e);
      }

      this.setupFormEvents(instance);

      instance.state.originalData = this.getFormData(instance, true);

      EventManager.emit('form:init', {formId, instance});

      if (!instance.state.dataOnLoadCalled && window.TemplateManager && typeof TemplateManager.processDataOnLoad === 'function') {
        const fallbackData = this.getFormData(instance, true) || {};
        const data = window._currentModalData && typeof window._currentModalData === 'object'
          ? window._currentModalData
          : (instance.state.originalData || fallbackData);
        const context = {
          state: {data},
          data
        };
        TemplateManager.processDataOnLoad(form, context);
        instance.state.dataOnLoadCalled = true;
      }

      return instance;

    } catch (error) {
      ErrorManager.handle(error, {
        context: 'FormManager.initForm',
        type: 'error:form',
        data: {formId}
      });
      return null;
    }
  },

  /**
   * Load form data from API if data-load-api is specified
   * Supports URL query parameters if data-load-query-params="true"
   * @param {Object} instance - Form instance
   * @async
   */
  normalizeCacheTime(value, fallback = 60000) {
    const parsed = parseInt(value, 10);
    return Number.isFinite(parsed) && parsed >= 0 ? parsed : fallback;
  },

  getLoadRequestCacheSettings(instance, type = 'data') {
    const enabledKey = type === 'options' ? 'loadOptionsCache' : 'loadCache';
    const timeKey = type === 'options' ? 'loadOptionsCacheTime' : 'loadCacheTime';

    return {
      enabled: instance?.config?.[enabledKey] === true,
      time: this.normalizeCacheTime(instance?.config?.[timeKey], this.config[timeKey])
    };
  },

  buildApiGetOptions(options = {}, cacheSettings = {}) {
    const baseCache = window.ApiService?.config?.cache && typeof window.ApiService.config.cache === 'object'
      ? window.ApiService.config.cache
      : {};

    if (cacheSettings.enabled === true) {
      return {
        ...options,
        headers: {
          ...(options.headers || {})
        },
        cache: {
          ...baseCache,
          ...(options.cache || {}),
          enabled: true,
          storageType: (options.cache && options.cache.storageType) || baseCache.storageType || 'memory',
          expiry: {
            ...(baseCache.expiry || {}),
            ...((options.cache && options.cache.expiry) || {}),
            get: cacheSettings.time
          }
        }
      };
    }

    return {
      ...options,
      deduplicate: false,
      headers: {
        'Cache-Control': 'no-cache',
        'Pragma': 'no-cache',
        ...(options.headers || {})
      },
      cache: {
        ...baseCache,
        ...(options.cache || {}),
        enabled: false,
        storageType: 'no-store',
        expiry: {
          ...(baseCache.expiry || {}),
          ...((options.cache && options.cache.expiry) || {}),
          get: 0
        }
      }
    };
  },

  buildFetchOptions(options = {}, cacheSettings = {}) {
    if (cacheSettings.enabled === true) {
      return {
        ...options,
        cache: 'default',
        headers: {
          ...(options.headers || {})
        }
      };
    }

    return {
      ...options,
      cache: 'no-store',
      headers: {
        'Cache-Control': 'no-cache',
        'Pragma': 'no-cache',
        ...(options.headers || {})
      }
    };
  },

  async loadFormDataIfNeeded(instance) {
    const {element} = instance;
    const loadApi = element.dataset.loadApi;

    if (!loadApi) return;

    // Response is declared outside try to be available in catch blocks
    let response;

    try {
      let url = loadApi;
      let params = {};
      const cacheSettings = this.getLoadRequestCacheSettings(instance, 'data');

      // Extract query params from URL if data-load-query-params="true"
      if (element.dataset.loadQueryParams === 'true') {
        const urlParams = new URLSearchParams(window.location.search);
        const paramObj = Object.fromEntries(urlParams);

        if (Object.keys(paramObj).length > 0) {
          params = paramObj;
        }
      }

      // Make API request
      if (window.ApiService?.get) {
        response = await window.ApiService.get(url, params, this.buildApiGetOptions({}, cacheSettings));
      } else if (window.simpleFetch?.get) {
        const queryStr = new URLSearchParams(params).toString();
        const fullUrl = queryStr ? `${url}?${queryStr}` : url;
        response = await window.simpleFetch.get(fullUrl, this.buildFetchOptions({}, cacheSettings));
      } else {
        console.warn('FormManager: ApiService or simpleFetch not available');
        return;
      }

      // Handle 403 Forbidden - redirect to 403 page
      if (response.status === 403) {
        console.warn('FormManager: Access forbidden (403) for form data API');

        const forbiddenMessage = response?.data?.message || response?.data?.data?.message || '';
        const forbiddenParams = forbiddenMessage ? {message: forbiddenMessage} : {};
        const forbiddenUrl = forbiddenMessage ? `/403?message=${encodeURIComponent(forbiddenMessage)}` : '/403';

        // Use RouterManager if available (SPA mode)
        if (window.RouterManager?.navigate) {
          window.RouterManager.navigate('/403', forbiddenParams);
          return;
        }

        // Fallback to LocationManager
        if (window.LocationManager?.redirect) {
          window.LocationManager.redirect(forbiddenUrl);
          return;
        }

        // Last resort: direct navigation
        window.location.href = forbiddenUrl;
        return;
      }

      // Handle 401 Unauthorized - redirect to login
      if (response?.status === 401) {
        console.warn('FormManager: Unauthorized (401) for form data API');

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


      // Extract options from standard format
      const data = response.data.data || response.data;

      // Extract formOptions: handle nested data.data structures from HttpClient
      // HttpClient wraps: {success, status, data: <json_body>}
      // API may return: {data: {data: {type, options: ...}}} or {data: {type, options: ...}}
      // After extraction, `data` might still have a nested .data, so check both levels
      const innerData = data.data || data;
      instance.state.formOptions = innerData.options || data.options || null;

      // Check if API returned actions (e.g., redirect, notification)
      // This handles cases where data is not found or other error conditions
      if (data && data.actions && Array.isArray(data.actions)) {
        try {
          await ResponseHandler.process(data, {
            formId: instance.id,
            form: element,
            instance: instance,
            source: 'form-load-api'
          });
          // If actions were processed, stop further processing
          return response;
        } catch (error) {
          console.error('FormManager: Error processing load-api response actions', error);
        }
      }

      // Populate form fields with loaded data.
      // setFormData handles ALL data-* bindings through TemplateManager:
      //   data-options-key → populate select/tags/text options
      //   data-attr        → set values (via ExpressionEvaluator + propertyHandlers)
      //   data-bind / data-attr="data:xxx" → table/LineItems binding
      //   data-files       → file input existing files
      //   data-text/data-if/data-for/etc.
      this.setFormData(instance, data);

      const context = {
        ...data
      };
      // Call data-on-load handlers (normalized payload)
      TemplateManager.processDataOnLoad(element, context);
      instance.state.dataOnLoadCalled = true;

      return response;
    } catch (error) {
      // Handle 403 Forbidden - redirect to 403 page
      if (error.status === 403 || error.response?.status === 403) {
        console.warn('FormManager: Access forbidden (403) for form data API');

        const forbiddenMessage = error?.response?.data?.message || error?.response?.data?.data?.message || error?.message || '';
        const forbiddenParams = forbiddenMessage ? {message: forbiddenMessage} : {};
        const forbiddenUrl = forbiddenMessage ? `/403?message=${encodeURIComponent(forbiddenMessage)}` : '/403';

        // Use RouterManager if available (SPA mode)
        if (window.RouterManager?.navigate) {
          window.RouterManager.navigate('/403', forbiddenParams);
          return;
        }

        // Fallback to LocationManager
        if (window.LocationManager?.redirect) {
          window.LocationManager.redirect(forbiddenUrl);
          return;
        }

        // Last resort: direct navigation
        window.location.href = forbiddenUrl;
        return;
      }

      // Handle 401 Unauthorized - redirect to login
      if (response?.status === 401 || error?.response?.status === 401) {
        console.warn('FormManager: Unauthorized (401) for form data API');

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

      console.error('FormManager: Error loading form data:', error);
      throw error;
    }
  },

  /**
   * Load form options from API if data-load-options-api is specified
   * Stores options in instance for later use
   * Standard format: {data: {data: {...}, options: {...}}}
   * @param {Object} instance - Form instance
   * @async
   */
  async loadFormOptionsIfNeeded(instance) {
    const {element} = instance;
    const loadOptionsApi = element.dataset.loadOptionsApi;

    if (!loadOptionsApi) return;

    try {
      const cacheSettings = this.getLoadRequestCacheSettings(instance, 'options');

      // Wait for SecurityManager to be ready (CSRF token available)
      if (window.SecurityManager) {
        const maxWaitTime = 5000; // 5 seconds max
        const startTime = Date.now();

        while (!window.SecurityManager.state?.csrfToken && (Date.now() - startTime < maxWaitTime)) {
          // If SecurityManager is initializing, wait for it
          if (window.SecurityManager.state?.initialized === false) {
            await new Promise(resolve => setTimeout(resolve, 50));
          } else {
            // SecurityManager initialized but no token - try to refresh
            if (typeof window.SecurityManager.refreshCSRFToken === 'function') {
              try {
                await window.SecurityManager.refreshCSRFToken();
              } catch (e) {
                console.warn('FormManager: Failed to refresh CSRF token', e);
              }
            }
            break;
          }
        }
      }

      let response;
      if (window.ApiService?.get) {
        response = await window.ApiService.get(loadOptionsApi, {}, this.buildApiGetOptions({}, cacheSettings));
      } else if (window.simpleFetch?.get) {
        response = await window.simpleFetch.get(loadOptionsApi, this.buildFetchOptions({}, cacheSettings));
      } else {
        return;
      }

      // Extract options from standard format: response.data.data.options
      const responseData = response.data || response;
      const optionsData = responseData?.data?.options || null;

      if (!optionsData) {
        console.warn('FormManager: Invalid options format. Expected {data: {data: {...}, options: {...}}}');
        return;
      }

      // Store options in instance for use by options or datalist
      instance.state.formOptions = optionsData;

      // Populate options data into elements
      this.setFormOptions(element, instance.state.formOptions);
      return response;
    } catch (error) {
      console.error('FormManager: Error loading form options:', error);
      throw error;
    }
  },

  /**
   * Populate select elements with data-options-key from stored modal options.
   * This is called after modal template is initialized with form data.
   * Uses TemplateManager.processDataDirectives for unified processing.
   *
   * @param {Object} instance - Form instance
   */
  populateSelectsFromModalOptions(instance) {
    const {element} = instance;

    // Get stored modal options from window scope (set by ResponseHandler)
    const storedOptions = window._currentModalOptions || element.dataset.modalOptions;
    const storedData = window._currentModalData || {};

    if (!storedOptions) return;

    const optionsData = typeof storedOptions === 'string' ? JSON.parse(storedOptions) : storedOptions;
    if (optionsData && typeof optionsData === 'object') {
      // Store options for later use by setFormData
      instance.state.formOptions = optionsData;

      // Populate options into elements via unified method
      this.setFormOptions(element, optionsData);

      // Set values from modal data using processDataDirectives
      // This handles data-attr="value:xxx" for selects, tags, text autocomplete
      // through ExpressionEvaluator + propertyHandlers
      if (storedData && typeof storedData === 'object' && window.TemplateManager) {
        const fieldData = storedData.data || storedData;
        const context = {
          state: fieldData,
          data: fieldData,
          options: optionsData,
          computed: {}
        };
        TemplateManager.processDataDirectives(element, context);
      }
    }
  },

  /**
   * Populate options data into elements.
   * Uses TemplateManager.processDataOptionsKey for unified processing.
   *
   * @param {HTMLElement} element - Container element (form)
   * @param {Object} optionsData - Options data keyed by optionsKey
   */
  setFormOptions(element, optionsData) {
    if (!element || !optionsData) return;

    if (window.TemplateManager && typeof TemplateManager.processDataOptionsKey === 'function') {
      const context = {options: optionsData};
      element.querySelectorAll('[data-options-key]').forEach(el => {
        TemplateManager.processDataOptionsKey(el, el.dataset.optionsKey, context);
      });
    }
  },

  /**
   * Populate form fields from URL query parameters
   * Uses setFormData() which handles data-attr="value:paramName" bindings
   * Same pattern as loading data from API
   *
   * @param {Object} instance - Form instance
   * @example
   * <input type="hidden" name="token" data-attr="value:token">
   * <input type="hidden" name="uid" data-attr="value:uid">
   *
   * URL: /reset-password?token=abc123&uid=1
   * Result: setFormData receives {token: 'abc123', uid: '1'}
   *         Fields with data-attr="value:token" get populated automatically
   */
  populateFromUrlParams(instance) {
    const {element} = instance;

    // Check if form wants URL params (data-load-url-params="true")
    if (element.dataset.loadUrlParams !== 'true') return;

    // Get URL search params from multiple sources (support SPA routing)
    const urlParams = new URLSearchParams(window.location.search);

    // Also check hash fragment for SPA hash-mode routing
    // e.g., /#/reset-password?token=abc becomes location.hash = "#/reset-password?token=abc"
    const hashPart = window.location.hash;
    const hashQueryIndex = hashPart.indexOf('?');
    if (hashQueryIndex !== -1) {
      const hashParams = new URLSearchParams(hashPart.substring(hashQueryIndex));
      // Merge hash params into urlParams (hash params take precedence)
      for (const [key, value] of hashParams.entries()) {
        urlParams.set(key, value);
      }
    }

    // Build data object from all URL params
    const data = Object.fromEntries(urlParams.entries());

    if (Object.keys(data).length === 0) {
      // No URL params found
      if (element.dataset.urlParamsRequired === 'true') {
        const errorMessage = Now.translate('Invalid or missing URL parameters');
        if (window.NotificationManager) {
          NotificationManager.error(errorMessage);
        }
        // Disable form submission
        const submitBtn = element.querySelector('[type="submit"]');
        if (submitBtn) {
          submitBtn.disabled = true;
        }
      }
      return;
    }

    // Use setFormData to populate fields (handles data-attr bindings automatically)
    this.setFormData(instance, data);

    // Check for required params that are missing
    const requiredParams = element.dataset.urlParamsRequiredFields;
    if (requiredParams) {
      const required = requiredParams.split(',').map(p => p.trim());
      const missing = required.filter(p => !data[p]);

      if (missing.length > 0) {
        this.emitEvent('form:urlParamsMissing', {
          formId: instance.id,
          missingParams: missing
        });

        if (element.dataset.urlParamsRequired === 'true') {
          const errorMessage = Now.translate('Invalid or missing URL parameters');
          if (window.NotificationManager) {
            NotificationManager.error(errorMessage);
          }
          const submitBtn = element.querySelector('[type="submit"]');
          if (submitBtn) {
            submitBtn.disabled = true;
          }
        }
      }
    }
  },

  /**
   * Sets up the form observer to automatically initialize forms
   */
  setupSecurityIntegration() {
    document.addEventListener('security:error', (event) => {
      this.handleSecurityError(event.detail);
    });

    document.addEventListener('csrf:refreshed', (event) => {
      this.updateFormsWithNewCSRF(event.detail.token);
    });
  },

  /**
   * Handle security errors emitted by SecurityManager
   * @param {Object} detail
   */
  handleSecurityError(detail) {
    try {
      const info = detail || {};
      const message = info.message || info.error || 'A security error occurred';

      // Prefer centralized AuthManager.handleError when available
      const authManager = window.Now?.getManager ? Now.getManager('auth') : window.AuthManager;
      if (authManager && typeof authManager.handleError === 'function') {
        try {
          authManager.handleError('Security error', new Error(message), {detail: info});
        } catch (e) {
          // swallow errors from centralized handler to avoid cascading failures
          console.warn('FormManager: AuthManager.handleError threw an error', e);
        }
      } else if (window.ErrorManager && typeof ErrorManager.handle === 'function') {
        // Fallback to ErrorManager for logging if AuthManager isn't available
        try {
          ErrorManager.handle(new Error(message), {context: 'FormManager.handleSecurityError', data: info});
        } catch (e) {
          console.warn('FormManager: ErrorManager.handle threw an error', e);
        }
      } else {
        // Last-resort: show a user notification or console warning
        if (window.NotificationManager) {
          NotificationManager.error(message);
        } else {
          console.warn('FormManager security error:', message, info);
        }
      }

      // If this looks like an authentication/CSRF issue, clear form-related state
      if (info.status === 401 || info.code === 'unauthorized' || info.code === 'csrf_invalid') {
        // Clear any client-side tokens to avoid further failures
        try {localStorage.removeItem('auth_token');} catch (e) {}
        try {localStorage.removeItem(this.config.token?.storageKey || 'auth_user');} catch (e) {}

        // Optionally redirect to login if AuthManager is available
        if (authManager && typeof authManager.redirectTo === 'function') {
          authManager.redirectTo(authManager.config?.redirects?.unauthorized || '/login');
        }
      }
    } catch (err) {
      // If our new delegation fails, ensure we still surface something useful
      try {
        console.error('Error in FormManager.handleSecurityError', err);
      } catch (ee) {
        // swallow
      }
    }
  },

  /**
   * Handles security errors by showing a notification or redirecting
   * @param {Object} error - The security error object
   * @return {void}
   */


  /**
   * Updates all forms with the new CSRF token
   * @param {string} token - The new CSRF token to set
   * @return {void}
   */
  updateFormsWithNewCSRF(token) {
    this.state.forms.forEach((instance) => {
      const csrfInput = instance.element.querySelector('input[name="_token"]');
      if (csrfInput) {
        csrfInput.value = token;
      }
    });
  },

  /**
   * Handles security errors by showing a notification or redirecting
   * @param {Object} error - The security error object
   * @return {void}
   */
  extractFormConfig(form) {
    const config = {};
    const dataAttrs = form.dataset;

    if (dataAttrs.csrf !== undefined) {
      config.csrf = dataAttrs.csrf === 'true';
    }
    if (dataAttrs.validation !== undefined) {
      config.validation = dataAttrs.validation === 'true';
    }

    if (dataAttrs.csrfToken) {
      config.csrfToken = dataAttrs.csrfToken;
    }
    if (dataAttrs.csrfHeader) {
      config.csrfHeader = dataAttrs.csrfHeader;
    }

    if (dataAttrs.validateOnSubmit !== undefined) {
      config.validateOnSubmit = dataAttrs.validateOnSubmit === 'true';
    }
    if (dataAttrs.sanitizeInput !== undefined) {
      config.sanitizeInput = dataAttrs.sanitizeInput === 'true';
    }

    ['ajaxSubmit', 'autoValidate', 'resetAfterSubmit', 'preventDoubleSubmit', 'showLoadingOnSubmit', 'showErrorsInline', 'showErrorsInNotification'].forEach(attr => {
      if (dataAttrs[attr] !== undefined) {
        config[attr] = dataAttrs[attr] === 'true';
      }
    });

    // Support data-validate as an alias for data-auto-validate
    if (dataAttrs.validate !== undefined) {
      config.autoValidate = dataAttrs.validate === 'true';
    }

    if (dataAttrs.errorContainer) {
      config.errorContainer = dataAttrs.errorContainer;
    }

    if (dataAttrs.showErrorsInline !== undefined) {
      config.showErrorsInline = dataAttrs.showErrorsInline === 'true';
    }

    if (dataAttrs.showErrorsInNotification !== undefined) {
      config.showErrorsInNotification = dataAttrs.showErrorsInNotification === 'true';
    }

    if (dataAttrs.errorClass) {
      config.errorClass = dataAttrs.errorClass;
    }

    if (dataAttrs.errorMessageClass) {
      config.errorMessageClass = dataAttrs.errorMessageClass;
    }

    if (dataAttrs.autoClearErrors !== undefined) {
      config.autoClearErrors = dataAttrs.autoClearErrors === 'true';
    }

    if (dataAttrs.autoClearErrorsDelay) {
      config.autoClearErrorsDelay = parseInt(dataAttrs.autoClearErrorsDelay);
    }

    if (dataAttrs.autoFocusError !== undefined) {
      config.autoFocusError = dataAttrs.autoFocusError === 'true';
    }

    if (dataAttrs.autoScrollToError !== undefined) {
      config.autoScrollToError = dataAttrs.autoScrollToError === 'true';
    }

    if (dataAttrs.successContainer) {
      config.successContainer = dataAttrs.successContainer;
    }

    if (dataAttrs.showSuccessInline !== undefined) {
      config.showSuccessInline = dataAttrs.showSuccessInline === 'true';
    }

    if (dataAttrs.showSuccessInNotification !== undefined) {
      config.showSuccessInNotification = dataAttrs.showSuccessInNotification === 'true';
    }

    // Extract success-specific data attributes
    if (dataAttrs.successRedirect) {
      config.successRedirect = dataAttrs.successRedirect;
    }

    if (dataAttrs.successMessage) {
      config.successMessage = dataAttrs.successMessage;
    }

    if (dataAttrs.loadingText) {
      config.loadingText = dataAttrs.loadingText;
    }

    if (dataAttrs.loadCache !== undefined) {
      config.loadCache = dataAttrs.loadCache === 'true';
    }

    if (dataAttrs.loadCacheTime) {
      config.loadCacheTime = parseInt(dataAttrs.loadCacheTime, 10);
    }

    if (dataAttrs.loadOptionsCache !== undefined) {
      config.loadOptionsCache = dataAttrs.loadOptionsCache === 'true';
    }

    if (dataAttrs.loadOptionsCacheTime) {
      config.loadOptionsCacheTime = parseInt(dataAttrs.loadOptionsCacheTime, 10);
    }

    if (dataAttrs.watchApi) {
      config.watchApi = dataAttrs.watchApi;
    }

    if (dataAttrs.watchMethod) {
      config.watchMethod = dataAttrs.watchMethod.toUpperCase();
    }

    if (dataAttrs.watchFields) {
      config.watchFields = this.normalizeFieldList(dataAttrs.watchFields);
    }

    if (dataAttrs.watchTrigger) {
      config.watchTrigger = this.normalizeFieldList(dataAttrs.watchTrigger);
    }

    if (dataAttrs.watchDebounce) {
      config.watchDebounce = parseInt(dataAttrs.watchDebounce, 10);
    }

    if (dataAttrs.watchOnLoad !== undefined) {
      config.watchOnLoad = dataAttrs.watchOnLoad === 'true';
    }

    if (dataAttrs.submitTarget) {
      config.submitTarget = dataAttrs.submitTarget;
    }

    if (dataAttrs.submitPaginationTarget) {
      config.submitPaginationTarget = dataAttrs.submitPaginationTarget;
    }

    if (dataAttrs.submitQueryParams !== undefined) {
      config.submitQueryParams = dataAttrs.submitQueryParams === 'true';
    }

    if (dataAttrs.submitQueryFields) {
      config.submitQueryFields = this.normalizeFieldList(dataAttrs.submitQueryFields);
    }

    if (dataAttrs.submitPageField) {
      config.submitPageField = dataAttrs.submitPageField;
    }

    if (dataAttrs.submitPaginationWindow) {
      config.submitPaginationWindow = parseInt(dataAttrs.submitPaginationWindow, 10);
    }

    return config;
  },

  initFormElements(instance) {
    const {element, elements} = instance;

    const fields = element.querySelectorAll(this.config.fieldSelector);

    fields.forEach(field => {
      if (field.hasAttribute('data-form-exclude')) {
        return;
      }

      if (!field.name && !field.id) {
        console.warn('FormManager: Field without name or id found:', field);
        return;
      }

      const fieldName = field.name || field.id;
      const fieldType = this.getFieldType(field);

      if (fieldType === 'radio') {
        if (!elements.has(fieldName)) {
          elements.set(fieldName, []);
        }
        elements.get(fieldName).push(field);

        if (field.checked) {
          instance.state.data[fieldName] = field.value;
        }
        return;
      }

      if (fieldType === 'checkbox' && !fieldName.endsWith('[]')) {
        elements.set(fieldName, field);
        instance.state.data[fieldName] = field.checked;
        return;
      }

      if (fieldType === 'checkbox' && fieldName.endsWith('[]')) {
        const baseName = fieldName.slice(0, -2);
        if (!elements.has(baseName)) {
          elements.set(baseName, []);
          instance.state.data[baseName] = [];
        }
        elements.get(baseName).push(field);
        if (field.checked) {
          instance.state.data[baseName].push(field.value);
        }
        return;
      }

      elements.set(fieldName, field);

      if (fieldType === 'file') {
        instance.state.data[fieldName] = null;
      } else if (fieldType === 'select-multiple' || field.tagName.toLowerCase() === 'select') {
        // Handle select-multiple with name[] format
        const baseName = fieldName.endsWith('[]') ? fieldName.slice(0, -2) : fieldName;

        // Store with base name for easier lookup
        if (baseName !== fieldName) {
          elements.set(baseName, field);
        }

        // For select elements, only store value if there's an explicitly selected option
        // or if the select has a value attribute set
        if (field.multiple) {
          // For multiple selects, check if any options have selected attribute
          const selectedOptions = field.querySelectorAll('option[selected]');
          if (selectedOptions.length > 0) {
            instance.state.data[baseName] = Array.from(selectedOptions).map(opt => opt.value);
          } else if (field.selectedOptions.length > 0) {
            // Fallback to selectedOptions property
            instance.state.data[baseName] = Array.from(field.selectedOptions).map(opt => opt.value);
          } else {
            instance.state.data[baseName] = [];
          }
        } else {
          // For single selects
          const selectedOption = field.querySelector('option[selected]');
          if (selectedOption) {
            instance.state.data[baseName] = selectedOption.value;
          } else if (field.value && field.options.length > 0) {
            // If no explicit selected attribute but has value, use it
            instance.state.data[baseName] = field.value;
          } else {
            instance.state.data[baseName] = '';
          }
        }
      } else {
        instance.state.data[fieldName] = field.value;
      }

      if (instance.config.autoEnhance && window.ElementManager) {
        window.ElementManager.enhance(field);
      }
    });

    return elements;
  },

  /**
   * Determines the type of a form field element
   * @param {HTMLElement} field - The form field element to check
   * @returns {string} The field type - either from data-element attribute, input type, or lowercase tag name
   */
  getFieldType(field) {
    if (field.dataset.element) {
      return field.dataset.element;
    }

    if (field.tagName.toLowerCase() === 'input') {
      return field.type || 'text';
    }

    return field.tagName.toLowerCase();
  },

  /**
   * Sets up event listeners for form submission and field changes
   * @param {Object} instance - The form instance to set up events for
   * @returns {void}
   */
  setupFormEvents(instance) {
    const {element} = instance;

    element.addEventListener('submit', async (e) => {
      e.preventDefault();
      e.stopPropagation();

      // Clear any previous errors before processing new submission
      FormError.clearFormMessages(element);
      instance.state.errors = {};
      instance.state.apiFieldErrors = {};

      // Check for confirmation dialog
      const confirmMessage = element.getAttribute('data-confirm');
      if (confirmMessage) {
        const confirmed = await DialogManager.confirm(Now.translate(confirmMessage));
        if (!confirmed) return;
      }

      if (instance.config.preventDoubleSubmit) {
        const now = Date.now();
        if (instance.state.submitting ||
          (now - instance.state.lastSubmitTime < instance.config.doubleSubmitTimeout)) {
          console.warn('FormManager: Double submit prevented');
          return false;
        }
        instance.state.lastSubmitTime = now;
      }

      try {
        instance.state.submitting = true;
        instance.state.submitCount++;

        if (instance.config.showLoadingOnSubmit) {
          element.classList.add(instance.config.loadingClass);
        }

        const submitButton = element.querySelector(instance.config.submitButtonSelector);
        if (submitButton) {
          submitButton.disabled = true;
          this.state.activeSubmit = submitButton;

          if (submitButton.textContent.trim()) {
            submitButton._originalText = submitButton.textContent;
            submitButton.textContent = instance.config.loadingText || 'Operating';
          }
        }

        this.emitEvent('form:submitting', {
          formId: instance.id,
          form: instance
        });

        if (instance.config.autoValidate) {
          const isValid = await this.validateForm(instance);
          if (!isValid) {
            this.handleInvalidSubmit(instance);
            return false;
          }
        }

        this.prepareSubmitRequest(instance);

        const data = this.getFormData(instance);

        // Check if CSRF token is present
        if (instance.config.csrf !== false && data.jsonData && !data.jsonData._token) {
          // Check if we're in development mode (suppress warnings for localhost/dev)
          const isDevelopment = window.location.hostname === 'localhost' ||
            window.location.hostname === '127.0.0.1' ||
            window.location.hostname.includes('dev') ||
            window.location.hostname.includes('local');

          if (!isDevelopment) {
            console.warn('CSRF token is missing from form data');
          }

          // Try to get CSRF token from meta tag or cookie
          const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ||
            document.cookie.split(';').find(c => c.trim().startsWith('XSRF-TOKEN='))?.split('=')[1];

          if (csrfToken) {
            data.jsonData._token = csrfToken;
            if (data.formData) {
              data.formData.append('_token', csrfToken);
            }
          } else if (!isDevelopment) {
            console.warn('No CSRF token found in meta tag or cookies');
          }
        }

        if (!data.jsonData || Object.keys(data.jsonData).length === 0) {
          throw new Error('No form data to submit');
        }

        let response;
        if (instance.config.ajaxSubmit) {
          response = await this.submitAjax(instance, data);
        } else {
          element.submit();
          response = {success: true};
        }

        // Enhanced success checking - NORMALIZED
        // After normalization, response structure is always:
        // { success: true/false, message: "...", data: {...}, status: 201 }
        const isSuccess = response && response.success === true;

        if (isSuccess) {
          this.handleSuccessfulSubmit(instance, response);
          // After successful submit, save any persisted fields (triggered on submit)
          try {
            this.savePersistedValues && this.savePersistedValues(instance);
          } catch (e) {}
        } else {
          this.handleFailedSubmit(instance, response);
        }

        return response;

      } catch (error) {
        this.handleSubmitError(instance, error);
        return false;
      } finally {
        const submitButton = element.querySelector(instance.config.submitButtonSelector);
        if (submitButton) {
          submitButton.disabled = false;

          if (submitButton._originalText) {
            submitButton.textContent = submitButton._originalText;
            delete submitButton._originalText;
          }
        }

        element.classList.remove(instance.config.loadingClass);

        clearTimeout(instance.resetTimeout);
        instance.resetTimeout = setTimeout(() => {
          instance.state.submitting = false;
          this.state.activeSubmit = null;
        }, 100);
      }
    });

    element.addEventListener('reset', (e) => {
      this.resetForm(instance);
    });

    element.addEventListener('change', async (e) => {
      const field = e.target;
      const name = field.name;

      if (!name) return;

      switch (field.type) {
        case 'checkbox':
          if (name.endsWith('[]')) {
            const baseName = name.slice(0, -2);
            instance.state.data[baseName] = this.getCheckboxGroupValues(element, name);
          } else {
            instance.state.data[name] = field.checked;
          }
          break;

        case 'radio':
          if (field.checked) {
            instance.state.data[name] = field.value;
          }
          break;

        case 'file':
          instance.state.data[name] = field.files.length > 0 ? true : null;
          break;

        default:
          instance.state.data[name] = field.value;
      }

      instance.state.modified = true;

      field.classList.add(instance.config.dirtyClass);

      this.emitEvent('form:field:change', {
        formId: instance.id,
        field: field,
        name: name,
        value: instance.state.data[name]
      });

      // Handle cascade if this field has data-cascade-source
      if (field.dataset.cascadeSource) {
        await this.handleCascade(instance, field);
      }

      await this.handleWatchedFieldChange(instance, field);
    });

    if (instance.config.validateOnInput) {
      element.addEventListener('input', Utils.function.debounce((e) => {
        const field = e.target;
        if (field.name) {
          this.validateField(instance, field);
        }
      }, 300));
    }

    if (instance.config.validateOnBlur) {
      element.addEventListener('blur', (e) => {
        const field = e.target;
        if (field.name) {
          this.validateField(instance, field);
        }
      }, true);
    }
  },

  normalizeFieldList(value) {
    if (Array.isArray(value)) {
      return value
        .map(item => String(item || '').trim())
        .filter(Boolean);
    }

    return String(value || '')
      .split(',')
      .map(item => item.trim())
      .filter(Boolean);
  },

  shouldWatchField(instance, fieldName) {
    if (!instance?.config?.watchApi || !fieldName) {
      return false;
    }

    const triggerFields = this.normalizeFieldList(instance.config.watchTrigger);
    const watchFields = this.normalizeFieldList(instance.config.watchFields);
    const allowedFields = triggerFields.length > 0 ? triggerFields : watchFields;

    if (allowedFields.length === 0) {
      return true;
    }

    return allowedFields.includes(fieldName);
  },

  getWatchedFieldValue(instance, fieldName) {
    if (!instance?.element || !fieldName) {
      return '';
    }

    const {element} = instance;
    const escapedFieldName = typeof CSS !== 'undefined' && typeof CSS.escape === 'function'
      ? CSS.escape(fieldName)
      : fieldName.replace(/([ #;?%&,.+*~\':"!^$\[\]()=>|\/@])/g, '\\$1');

    const namedFields = Array.from(element.querySelectorAll(`[name="${fieldName}"], [name="${fieldName}[]"]`));
    const targetField = namedFields[0] || element.querySelector(`#${escapedFieldName}`);

    if (!targetField) {
      return instance.state?.data?.[fieldName] ?? '';
    }

    const elementInstance = window.ElementManager?.getInstanceByElement?.(targetField);
    if (elementInstance?.hiddenInput) {
      return elementInstance.hiddenInput.value;
    }

    if (targetField.type === 'radio') {
      const checked = element.querySelector(`[name="${fieldName}"]:checked`);
      return checked ? checked.value : '';
    }

    if (targetField.type === 'checkbox') {
      const checkboxName = targetField.name || fieldName;
      const checkboxGroup = Array.from(element.querySelectorAll(`[name="${checkboxName}"]`));

      if (checkboxGroup.length > 1 || checkboxName.endsWith('[]')) {
        return checkboxGroup
          .filter(input => input.checked)
          .map(input => input.value || '');
      }

      return targetField.checked ? (targetField.value || '1') : '';
    }

    if (targetField.tagName === 'SELECT' && targetField.multiple) {
      return Array.from(targetField.selectedOptions).map(option => option.value);
    }

    return targetField.value ?? '';
  },

  getWatchedFieldData(instance) {
    const watchFields = this.normalizeFieldList(instance?.config?.watchFields);

    if (watchFields.length === 0) {
      const {jsonData} = this.getFormData(instance, true);
      return jsonData;
    }

    return watchFields.reduce((result, fieldName) => {
      result[fieldName] = this.getWatchedFieldValue(instance, fieldName);
      return result;
    }, {});
  },

  handleWatchedFieldChange(instance, field) {
    const fieldName = field?.name || field?.id || '';
    if (!this.shouldWatchField(instance, fieldName)) {
      return Promise.resolve();
    }

    this.scheduleWatchedDataSync(instance);
    return Promise.resolve();
  },

  scheduleWatchedDataSync(instance, options = {}) {
    if (!instance?.config?.watchApi) {
      return;
    }

    clearTimeout(instance.state.watchTimer);

    const debounce = options.immediate
      ? 0
      : Math.max(0, parseInt(instance.config.watchDebounce, 10) || 0);

    instance.state.watchTimer = setTimeout(() => {
      instance.state.watchTimer = null;
      this.syncWatchedData(instance, options).catch((error) => {
        console.warn('FormManager: Watched data sync failed', error);
      });
    }, debounce);
  },

  applyWatchedData(instance, payload) {
    if (!instance || !payload || typeof payload !== 'object') {
      return;
    }

    const actualData = payload.data && typeof payload.data === 'object' && !Array.isArray(payload.data)
      ? payload.data
      : payload;

    if (actualData.options && typeof actualData.options === 'object' && !Array.isArray(actualData.options)) {
      instance.state.formOptions = {
        ...(instance.state.formOptions || {}),
        ...actualData.options
      };
      this.setFormOptions(instance.element, instance.state.formOptions);
    }

    const bindData = {...actualData};
    delete bindData.options;

    this.setFormData(instance, bindData, true);
  },

  async syncWatchedData(instance, options = {}) {
    if (!instance?.config?.watchApi) {
      return null;
    }

    const params = this.getWatchedFieldData(instance);
    const signature = JSON.stringify(params);

    if (!options.force && signature === instance.state.watchLastAppliedSignature) {
      return null;
    }

    if (!options.force && signature === instance.state.watchPendingSignature) {
      return null;
    }

    instance.state.watchPendingSignature = signature;
    instance.state.watchRequestId = (instance.state.watchRequestId || 0) + 1;
    const requestId = instance.state.watchRequestId;
    const method = String(instance.config.watchMethod || 'GET').toUpperCase();

    this.emitEvent('form:watch:loading', {
      formId: instance.id,
      params
    });

    try {
      let response;

      if (window.ApiService) {
        if (method === 'GET' && typeof window.ApiService.get === 'function') {
          response = await window.ApiService.get(instance.config.watchApi, params, this.buildApiGetOptions({}, {enabled: false, time: 0}));
        } else if (method !== 'GET' && typeof window.ApiService[method.toLowerCase()] === 'function') {
          response = await window.ApiService[method.toLowerCase()](instance.config.watchApi, params);
        }
      }

      if (!response && window.simpleFetch) {
        if (method === 'GET' && typeof window.simpleFetch.get === 'function') {
          response = await window.simpleFetch.get(instance.config.watchApi, params);
        } else if (method !== 'GET' && typeof window.simpleFetch[method.toLowerCase()] === 'function') {
          response = await window.simpleFetch[method.toLowerCase()](instance.config.watchApi, params);
        }
      }

      if (!response) {
        throw new Error(`FormManager: Unsupported watched API method ${method}`);
      }

      if (requestId !== instance.state.watchRequestId) {
        return null;
      }

      const responseData = response?.data?.data ?? response?.data ?? response ?? {};
      this.applyWatchedData(instance, responseData);

      instance.state.watchLastAppliedSignature = signature;
      instance.state.watchPendingSignature = null;

      this.emitEvent('form:watch:loaded', {
        formId: instance.id,
        params,
        response: responseData
      });

      return response;
    } catch (error) {
      if (requestId === instance.state.watchRequestId) {
        instance.state.watchPendingSignature = null;
      }

      this.emitEvent('form:watch:error', {
        formId: instance.id,
        params,
        error
      });

      throw error;
    }
  },

  getElementValidationError(field, elementInstance) {
    if (!field) return null;

    const message =
      (elementInstance && typeof elementInstance.getError === 'function' && elementInstance.getError()) ||
      elementInstance?.state?.error ||
      (window.ElementFactory && ElementFactory._privateState && ElementFactory._privateState.get(field)?.error) ||
      field.validationMessage ||
      field.dataset.errorValidate ||
      field.dataset.errorValidation ||
      null;

    if (Array.isArray(message)) {
      return message[0] || null;
    }

    return typeof message === 'string' && message.trim() !== '' ? message : null;
  },

  getFieldValidationFallbackMessage(field) {
    if (!field) return 'Validation failed';

    return Now.translate(
      field.dataset.errorValidate ||
      field.dataset.errorValidation ||
      'Validation failed'
    );
  },

  getFieldLabel(field, form = null) {
    if (!field) return '';

    const normalize = (value) => {
      if (typeof value !== 'string') return '';
      return value
        .replace(/\*/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
    };

    const labelFromNode = (node) => {
      if (!node) return '';
      return normalize(node.textContent || node.innerText || '');
    };

    const root = form instanceof HTMLElement ? form : document;
    const labelTargets = [field.id, field.name]
      .filter(value => typeof value === 'string' && value.trim() !== '');

    for (const target of labelTargets) {
      const linkedLabel = root.querySelector(`label[for="${target}"]`) || document.querySelector(`label[for="${target}"]`);
      const labelText = labelFromNode(linkedLabel);
      if (labelText) return labelText;
    }

    if (field.name) {
      const fieldName = normalize(field.name);
      if (fieldName) return fieldName;
    }

    if (field.id) {
      const fieldId = normalize(field.id);
      if (fieldId) return fieldId;
    }

    return normalize(field.getAttribute('aria-label') || field.placeholder || '');
  },

  getFieldDebugSelector(field) {
    if (!field) return '';

    if (field.id) {
      return `#${field.id}`;
    }

    if (field.name) {
      return `[name="${field.name}"]`;
    }

    if (field.dataset?.element) {
      return `[data-element="${field.dataset.element}"]`;
    }

    return field.tagName ? field.tagName.toLowerCase() : '';
  },

  getInvalidFieldDetail(instance, field) {
    if (!field) return null;

    const key = field.name || field.id || this.getFieldDebugSelector(field);
    const message = (key && instance?.state?.errors && instance.state.errors[key]) ||
      this.getElementValidationError(field) ||
      this.getFieldValidationFallbackMessage(field);

    return {
      key,
      name: field.name || null,
      id: field.id || null,
      label: this.getFieldLabel(field, instance?.element || null),
      message,
      selector: this.getFieldDebugSelector(field),
      type: field.type || field.dataset?.element || (field.tagName ? field.tagName.toLowerCase() : 'unknown'),
      visible: (() => {
        try {
          return field.offsetParent !== null && field.getClientRects().length > 0;
        } catch (e) {
          return false;
        }
      })(),
      required: !!field.required
    };
  },

  logInvalidFieldDetails(instance, invalidFieldDetails = []) {
    if (!invalidFieldDetails || invalidFieldDetails.length === 0) {
      return;
    }

    const formId = instance?.id || instance?.element?.dataset?.form || instance?.element?.id || 'unknown';
    const rows = invalidFieldDetails.map(detail => ({
      field: detail.label || detail.name || detail.id || detail.selector,
      message: detail.message,
      selector: detail.selector,
      type: detail.type,
      visible: detail.visible,
      required: detail.required
    }));

    if (console.groupCollapsed) {
      console.groupCollapsed(`Form validation failed: ${formId}`);
    }

    console.warn('Form validation details:', rows);

    if (console.table) {
      console.table(rows);
    }

    if (console.debug) {
      console.debug('Raw validation errors:', instance?.state?.errors || {});
    }

    if (console.groupEnd) {
      console.groupEnd();
    }
  },

  async validateField(instance, field, forceValidate = false) {
    const fieldName = field.name;
    if (!fieldName) return true;

    if (field.classList.contains(instance.config.dirtyClass) && instance.state.apiFieldErrors) {
      delete instance.state.apiFieldErrors[fieldName];
    }

    const apiMessage = instance.state.apiFieldErrors && instance.state.apiFieldErrors[fieldName];
    const preserveApiError = apiMessage != null && apiMessage !== ''
      && !field.classList.contains(instance.config.dirtyClass);

    if (!preserveApiError) {
      FormError.clearFieldError(fieldName);
      delete instance.state.errors[fieldName];
    }

    const isDisabledField = field.disabled || (typeof field.matches === 'function' && field.matches(':disabled'));
    if (isDisabledField) {
      if (instance.state.apiFieldErrors) {
        delete instance.state.apiFieldErrors[fieldName];
      }
      FormError.clearFieldError(field);
      delete instance.state.errors[fieldName];
      return true;
    }

    if (preserveApiError) {
      FormError.showFieldError(fieldName, apiMessage, instance.element);
      instance.state.errors[fieldName] = apiMessage;
      return false;
    }

    // Skip validation for unmodified fields unless forced (e.g., on form submit)
    if (!forceValidate && instance.config.validateOnlyDirty &&
      !field.classList.contains(instance.config.dirtyClass)) {
      return true;
    }

    const isFileInput = field.type === 'file';
    let fileState = null;
    if (isFileInput && window.ElementFactory && ElementFactory._privateState) {
      fileState = ElementFactory._privateState.get(field);
      if (fileState?.error) {
        FormError.showFieldError(fieldName, fileState.error, instance.element);
        instance.state.errors[fieldName] = fileState.error;
        return false;
      }
    }

    FormError.clearFieldError(fieldName);
    delete instance.state.errors[fieldName];

    // Prefer element identity lookup to avoid stale id-only matches when DOM nodes are recreated
    let elementInstance = null;
    try {
      const em = window.ElementManager;
      if (em && typeof em.getInstanceByElement === 'function') {
        elementInstance = em.getInstanceByElement(field) || null;
      }
      // fallback to id-based lookup if no instance found by element
      if (!elementInstance && field.id && em && typeof em.getInstance === 'function') {
        elementInstance = em.getInstance(field.id) || null;
        // if id-mapped instance is stale (element different or disconnected), ignore it
        if (elementInstance && elementInstance.element && elementInstance.element !== field) {
          if (!elementInstance.element.isConnected) {
            try {em.destroy(field.id);} catch (e) {}
            elementInstance = null;
          }
        }
      }
    } catch (e) {
      elementInstance = null;
    }
    if (!isFileInput && elementInstance && typeof elementInstance.validate === 'function') {
      try {
        elementInstance.validate(field.value, true);
        const isValid = typeof elementInstance.isValid === 'function'
          ? elementInstance.isValid()
          : !this.getElementValidationError(field, elementInstance);

        if (!isValid) {
          const message = this.getElementValidationError(field, elementInstance) ||
            this.getFieldValidationFallbackMessage(field);

          FormError.showFieldError(fieldName, message, instance.element);
          instance.state.errors[fieldName] = message;
          return false;
        }

        return true;
      } catch (error) {
        FormError.showFieldError(fieldName, error.message || 'Validation error', instance.element);
        instance.state.errors[fieldName] = error.message || 'Validation error';
        return false;
      }
    }

    const value = this.getFieldValue(field);

    if (field.required && (value === null || value === undefined || value === '')) {
      const message = Now.translate(field.dataset.errorRequired || 'Please fill in');
      FormError.showFieldError(fieldName, message, instance.element);
      instance.state.errors[fieldName] = message;
      return false;
    }

    if (value === null || value === '' || value === undefined) {
      return true;
    }

    if (field.pattern) {
      const pattern = new RegExp(field.pattern);
      if (!pattern.test(value)) {
        const message = Now.translate(field.dataset.errorPattern || 'Please enter a valid format');
        FormError.showFieldError(fieldName, message, instance.element);
        instance.state.errors[fieldName] = message;
        return false;
      }
    }

    if (field.type === 'number' || field.type === 'range') {
      const numValue = parseFloat(value);

      if (!isNaN(numValue)) {
        if (field.min !== undefined && field.min !== '' && numValue < parseFloat(field.min)) {
          const message = Now.translate(field.dataset.errorMin || 'Value must be at least {min}', {min: field.min});
          FormError.showFieldError(fieldName, message, instance.element);
          instance.state.errors[fieldName] = message;
          return false;
        }

        if (field.max !== undefined && field.max !== '' && numValue > parseFloat(field.max)) {
          const message = Now.translate(field.dataset.errorMax || 'Value must be no more than {max}', {max: field.max});
          FormError.showFieldError(fieldName, message, instance.element);
          instance.state.errors[fieldName] = message;
          return false;
        }
      }
    }

    if (typeof value === 'string') {
      if (field.minLength && value.length < parseInt(field.minLength)) {
        const message = Now.translate(field.dataset.errorMinlength || 'Please enter at least {minlength} characters', {minlength: field.minLength});
        FormError.showFieldError(fieldName, message, instance.element);
        instance.state.errors[fieldName] = message;
        return false;
      }

      if (field.maxLength && field.maxLength > 0 && value.length > parseInt(field.maxLength)) {
        const message = Now.translate(field.dataset.errorMaxlength || 'Please enter no more than {maxlength} characters', {maxlength: field.maxLength});
        FormError.showFieldError(fieldName, message, instance.element);
        instance.state.errors[fieldName] = message;
        return false;
      }
    }

    for (const [name, validator] of this.state.validators) {
      if (field.dataset[`validate${name.charAt(0).toUpperCase() + name.slice(1)}`] !== undefined) {
        const param = field.dataset[`validate${name.charAt(0).toUpperCase() + name.slice(1)}`] || '';

        if (!validator.validate(value, field, param)) {
          let message = field.dataset[`error${name.charAt(0).toUpperCase() + name.slice(1)}`] ||
            validator.message;

          // Only replace parameter if it's valid (not empty and not NaN for numeric validators)
          if (param && param.trim() !== '') {
            if (name === 'maxlength' || name === 'minlength') {
              const numParam = parseInt(param);
              if (!isNaN(numParam) && numParam > 0) {
                message = message.replace('{0}', param);
              }
            } else {
              message = message.replace('{0}', param);
            }
          }

          FormError.showFieldError(fieldName, message, instance.element);
          instance.state.errors[fieldName] = message;
          return false;
        }
      }
    }

    if (field.dataset.validateFn && window[field.dataset.validateFn]) {
      const validationFn = window[field.dataset.validateFn];
      try {
        const result = await validationFn(value, field, instance);
        if (result !== true) {
          const message = typeof result === 'string' ? result : 'Validation failed';
          FormError.showFieldError(fieldName, message, instance.element);
          instance.state.errors[fieldName] = message;
          return false;
        }
      } catch (error) {
        FormError.showFieldError(fieldName, error.message || 'Validation error', instance.element);
        instance.state.errors[fieldName] = error.message || 'Validation error';
        return false;
      }
    }

    field.classList.add(instance.config.validClass);
    field.classList.remove(instance.config.invalidClass);
    return true;
  },

  async validateForm(instance) {
    FormError.clearAll();
    instance.state.errors = {};
    instance.state.apiFieldErrors = {};
    instance.state.invalidFieldDetails = [];

    let isValid = true;
    const invalidFields = [];

    const promises = [];

    // Force validate all fields on form submit (bypass validateOnlyDirty)
    for (const [name, field] of instance.elements) {
      if (Array.isArray(field)) {
        if (field.length > 0) {
          promises.push(this.validateField(instance, field[0], true).then(
            valid => {
              if (!valid) {
                isValid = false;
                invalidFields.push(field[0]);
              }
            }
          ));
        }
      } else {
        promises.push(this.validateField(instance, field, true).then(
          valid => {
            if (!valid) {
              isValid = false;
              invalidFields.push(field);
            }
          }
        ));
      }
    }

    await Promise.all(promises);

    instance.state.invalidFieldDetails = invalidFields
      .map(field => this.getInvalidFieldDetail(instance, field))
      .filter(Boolean);

    instance.state.valid = isValid;

    if (!isValid && invalidFields.length > 0) {
      // Focus the first invalid field that is focusable/visible. Some fields
      // may be hidden by UI logic (display:none) or disabled; attempting to
      // focus them causes the browser error: "An invalid form control is
      // not focusable." Select the first invalid field that can receive
      // focus, otherwise skip focusing.
      const isFocusable = (el) => {
        if (!el) return false;
        try {
          if (el.disabled) return false;
          // offsetParent is null for display:none elements (and for elements
          // not in the document); also check visibility via getClientRects
          if (el.offsetParent === null) return false;
          const rects = el.getClientRects();
          if (!rects || rects.length === 0) return false;
          // tabindex -1 is usually not focusable via script
          if (typeof el.tabIndex === 'number' && el.tabIndex < 0) return false;
          return typeof el.focus === 'function';
        } catch (e) {
          return false;
        }
      };

      let target = null;
      for (let i = 0; i < invalidFields.length; i++) {
        if (isFocusable(invalidFields[i])) {
          target = invalidFields[i];
          break;
        }
      }

      if (target) {
        try {
          target.focus();
        } catch (e) {
          // ignore focus errors
        }

        try {
          target.scrollIntoView({
            behavior: 'smooth',
            block: 'center'
          });
        } catch (e) {
          // ignore scroll errors
        }
      }
    }

    this.emitEvent('form:validate', {
      formId: instance.id,
      isValid,
      errors: instance.state.errors,
      invalidFields,
      invalidFieldDetails: instance.state.invalidFieldDetails
    });

    return isValid;
  },

  getFieldValue(field) {
    switch (field.type) {
      case 'checkbox':
        if (field.name.endsWith('[]')) {
          return this.getCheckboxGroupValues(field.form, field.name);
        }
        return field.checked;

      case 'radio':
        const checkedRadio = field.form.querySelector(`input[name="${field.name}"]:checked`);
        return checkedRadio ? checkedRadio.value : null;

      case 'select-multiple':
        return Array.from(field.selectedOptions).map(opt => opt.value);

      case 'file': {
        if (window.ElementFactory && ElementFactory._privateState) {
          const privateState = ElementFactory._privateState.get(field);
          if (privateState?.files instanceof Map && privateState.files.size > 0) {
            return Array.from(privateState.files.values());
          }
        }
        return field.files.length > 0 ? field.files : null;
      }

      default:
        return field.value;
    }
  },

  getCheckboxGroupValues(form, name) {
    const checkboxes = form.querySelectorAll(`input[name="${name}"]:checked`);
    return Array.from(checkboxes).map(cb => cb.value);
  },

  getFormData(instance, loading = false) {
    const {element} = instance;
    const formData = new FormData();
    const jsonData = {};

    // Read directly from native form.elements like a real form submit would.
    // This ensures AJAX submit matches native submit behavior exactly and
    // automatically handles dynamically created inputs without registration.
    const processedNames = new Set();

    try {
      const nativeElements = element.elements; // HTMLFormControlsCollection
      if (nativeElements && nativeElements.length) {
        for (const nativeEl of Array.from(nativeElements)) {
          const name = nativeEl.name;
          if (!name) continue; // Skip unnamed controls

          // Skip elements marked with data-form-exclude (e.g. RTE color picker inputs)
          if (nativeEl.hasAttribute('data-form-exclude')) continue;

          // Disabled controls are excluded from native form submission.
          if (nativeEl.disabled || (typeof nativeEl.matches === 'function' && nativeEl.matches(':disabled'))) {
            continue;
          }

          // Skip if we already processed this name (for radio/checkbox groups)
          if (processedNames.has(name)) continue;

          // Handle different input types
          if (nativeEl.type === 'radio') {
            // For radio groups, only include the checked one
            const checked = element.querySelector(`input[name="${name}"]:checked`);
            if (checked) {
              formData.append(name, checked.value || '');
              jsonData[name] = checked.value || '';
            }
            processedNames.add(name);
            continue;
          }

          if (nativeEl.type === 'checkbox') {
            // Handle checkbox groups (name[])
            if (name.endsWith('[]')) {
              const baseName = name.slice(0, -2);
              if (!processedNames.has(baseName)) {
                const checked = element.querySelectorAll(`input[name="${name}"]:checked`);
                const vals = Array.from(checked).map(cb => cb.value || '');
                vals.forEach(v => formData.append(name, v));
                jsonData[baseName] = vals;
                processedNames.add(baseName);
              }
            } else {
              // Single checkbox
              const val = nativeEl.checked ? '1' : '0';
              formData.append(name, val);
              jsonData[name] = nativeEl.checked;
              processedNames.add(name);
            }
            continue;
          }

          if (nativeEl.type === 'file') {
            // Handle file inputs - check FileElementFactory privateState first
            let files = [];

            // Try to get files from FileElementFactory private state
            if (window.ElementFactory && ElementFactory._privateState) {
              const privateState = ElementFactory._privateState.get(nativeEl);
              if (privateState?.files instanceof Map && privateState.files.size > 0) {
                files = Array.from(privateState.files.values());
              }
            }

            // Fallback to native element.files
            if (files.length === 0 && nativeEl.files.length > 0) {
              files = Array.from(nativeEl.files);
            }

            // Append files to FormData
            if (files.length > 0) {
              if (nativeEl.multiple) {
                files.forEach(file => formData.append(`${name}[]`, file));
                jsonData[name] = files.map(f => f.name);
              } else {
                formData.append(name, files[0]);
                jsonData[name] = files[0].name;
              }
            }
            processedNames.add(name);
            continue;
          }

          if (nativeEl.tagName.toLowerCase() === 'select' && nativeEl.multiple) {
            // Handle multi-select
            const values = Array.from(nativeEl.selectedOptions).map(opt => opt.value);
            values.forEach(v => formData.append(`${name}[]`, v));
            jsonData[name] = values;
            processedNames.add(name);
            continue;
          }

          // Handle other array inputs (e.g. hidden inputs with name="skills[]")
          if (name.endsWith('[]')) {
            if (!processedNames.has(name)) {
              const inputs = element.querySelectorAll(`[name="${name}"]`);
              const values = Array.from(inputs).map(input => input.value);

              values.forEach(v => formData.append(name, v));

              // For JSON, use the base name without [] to be consistent with checkboxes
              const baseName = name.slice(0, -2);
              jsonData[baseName] = values;

              processedNames.add(name);
            }
            continue;
          }

          // Default: text, hidden, textarea, select (single), etc.
          const value = nativeEl.value || '';
          formData.append(name, value);
          jsonData[name] = value;
          processedNames.add(name);
        }
      }
    } catch (e) {
      console.warn('FormManager: Error reading form.elements', e);
    }

    if (!loading && instance.config.csrf !== false) {
      // Check for existing CSRF token in the form
      const existingCsrfInput = element.querySelector('input[name="_token"]');
      let csrfToken = existingCsrfInput ? existingCsrfInput.value : null;

      // If not found in form, try meta tag
      if (!csrfToken) {
        csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      }

      // If still not found, try cookies
      if (!csrfToken) {
        const cookies = document.cookie.split(';');
        for (const cookie of cookies) {
          const [name, value] = cookie.trim().split('=');
          if (name === 'XSRF-TOKEN') {
            csrfToken = decodeURIComponent(value);
            break;
          }
        }
      }

      if (csrfToken) {
        // Only add if not already present in form data
        if (!jsonData._token) {
          formData.append('_token', csrfToken);
          jsonData._token = csrfToken;
        }
      } else {
        // Try to fetch a new CSRF token
        if (window.simpleFetch) {
          setTimeout(() => {
            const csrfEndpoint = window.SecurityManager?.config?.csrf?.tokenUrl ||
              window.Now?.config?.security?.csrf?.tokenUrl ||
              'api/auth/csrf-token';
            simpleFetch.get(csrfEndpoint)
              .then(response => {
                if (response.data && response.data.token) {
                  // Update meta tag
                  let metaToken = document.querySelector('meta[name="csrf-token"]');
                  if (!metaToken) {
                    metaToken = document.createElement('meta');
                    metaToken.name = 'csrf-token';
                    document.head.appendChild(metaToken);
                  }
                  metaToken.setAttribute('content', response.data.token);
                }
              })
              .catch(error => {
                console.error('Failed to fetch CSRF token:', error);
              });
          }, 0);
        }
      }
    }

    instance.state.data = jsonData;
    return {formData, jsonData};
  },

  /**
   * Get a plain values object for a form.
   * Identifier can be a formId (string), an HTMLFormElement, or an instance object.
   * Returns null if the form/instance cannot be found.
   */
  getValues(identifier) {
    let instance = null;
    if (!identifier) return null;

    if (typeof identifier === 'string') {
      instance = this.getInstance(identifier);
      if (!instance) {
        // try to resolve by DOM id or data-form attr
        const el = document.getElementById(identifier) || document.querySelector(`form[data-form="${identifier}"]`);
        if (el) instance = this.getInstanceByElement(el);
      }
    } else if (identifier instanceof HTMLFormElement) {
      instance = this.getInstanceByElement(identifier);
    } else if (identifier && identifier.element) {
      instance = identifier;
    }

    if (!instance) return null;

    // Ensure we have fresh values by rebuilding form data
    try {
      const {jsonData} = this.getFormData(instance);
      return jsonData;
    } catch (e) {
      // Fallback to stored state data
      return instance.state && instance.state.data ? instance.state.data : null;
    }
  },

  /**
   * Set form field values from a data object.
   *
   * 1. Name-matching pass — sets values on fields found by name in the
   *    form instance's elements map or DOM.
   * 2. TemplateManager.processDataDirectives — handles all data-* bindings
   *    (data-attr, data-options-key, data-bind, data-files, data-text, …)
   *    through ExpressionEvaluator in one unified pass.
   *
   * @param {Object} instance - Form instance
   * @param {Object} data - Object with field values {fieldName: value}
   */
  setFormData(instance, data, silent = false) {
    if (!instance || !data) return;

    const {element, elements} = instance;
    const fieldData = data.data || data;

    // --- Pass 1: name-matching (radio, checkbox, simple fields) ---

    for (const [fieldName, value] of Object.entries(fieldData)) {
      const field = elements.get(fieldName);

      if (!field) {
        // Try to find by name in DOM if not in elements map
        const domField = element.querySelector(`[name="${fieldName}"], [name="${fieldName}[]"]`);
        if (domField) {
          this.setFieldValue(domField, value, silent);
        }
        continue;
      }

      // Handle array of fields (radio buttons, checkbox groups)
      if (Array.isArray(field)) {
        field.forEach(f => {
          if (f.type === 'radio') {
            f.checked = (f.value === value || f.value === String(value));
          } else if (f.type === 'checkbox') {
            if (Array.isArray(value)) {
              f.checked = value.includes(f.value);
            } else {
              f.checked = (f.value === value || f.value === String(value));
            }
          }
        });
      } else {
        this.setFieldValue(field, value, silent);
      }

      // Update instance state
      instance.state.data[fieldName] = value;
    }

    // --- Pass 2: TemplateManager unified processing ---
    // Handles data-options-key, data-attr (value:xxx), data-bind (data:xxx),
    // data-files, data-text, data-if, data-for, etc.
    // Options are passed in context so processDataOptionsKey can populate
    // dropdown/autocomplete sources BEFORE processDataAttr sets values.
    if (window.TemplateManager) {
      // Merge existing instance state (e.g., languages, default tab) with incoming fieldData
      // so data-for loops have their array available and interpolations keep loop vars.
      const mergedState = {
        ...(instance.state?.data || {}),
        ...fieldData
      };

      // Ensure languages are present for data-for="lng in languages" even if missing in fieldData
      mergedState.languages = mergedState.languages
        || instance.state?.languages
        || instance.state?.data?.languages
        || (typeof window !== 'undefined' && (window.languages || window.LANGUAGES || window.Config?.languages))
        || [];

      const context = {
        state: mergedState,
        data: mergedState,
        options: instance.state.formOptions || fieldData.options || data.options || {},
        computed: {}
      };
      TemplateManager.processDataDirectives(element, context);

      // After data-for may re-clone content, enhance new elements (e.g., tags) so property handlers run
      try {
        const elementManager = Now.getManager('element');
        if (elementManager && typeof elementManager.scan === 'function') {
          elementManager.scan(element);
        }
        const formManager = Now.getManager('form');
        if (formManager && typeof formManager.scan === 'function') {
          formManager.scan(element);
        }
      } catch (e) {
        console.warn('FormManager: post-process scan failed', e);
      }
    }

    // Trigger change event to update any dependent elements
    this.emitEvent('form:data:set', {
      formId: instance.id,
      data
    });
  },

  /**
   * Set value for a single field element
   * @param {HTMLElement} field - Form field element
   * @param {*} value - Value to set
   * @param {Boolean} silent - If true, don't dispatch change event
   */
  isEditableFieldFocused(field) {
    if (!field || typeof document === 'undefined' || field.disabled || field.readOnly) {
      return false;
    }

    if (document.activeElement !== field) {
      return false;
    }

    if (field.tagName === 'TEXTAREA' || field.tagName === 'SELECT') {
      return true;
    }

    return ['text', 'search', 'email', 'number', 'password', 'tel', 'url'].includes(field.type || 'text');
  },

  setFieldValue(field, value, silent = false) {
    if (!field) return;

    if (this.isEditableFieldFocused(field)) {
      return;
    }

    switch (field.type) {
      case 'checkbox':
        field.checked = Boolean(value);
        break;

      case 'radio':
        field.checked = (field.value === value || field.value === String(value));
        break;

      case 'select-multiple':
        if (Array.isArray(value)) {
          // Convert values to strings for comparison (handles number/string mismatch)
          const valueStrings = value.map(v => String(v));

          Array.from(field.options).forEach(opt => {
            opt.selected = valueStrings.includes(opt.value);
          });
        }
        break;

      case 'file':
        // Cannot programmatically set file input value for security reasons
        // But we can update data-files attribute to show existing files
        if (value && (Array.isArray(value) || typeof value === 'object')) {
          const files = Array.isArray(value) ? value : [value];
          field.setAttribute('data-files', JSON.stringify(files));

          // Reinitialize FileElementFactory to show existing files
          if (window.ElementManager && window.FileElementFactory) {
            const instance = ElementManager.getInstanceByElement(field);
            if (instance) {
              // Update config with new existing files
              instance.config.existingFiles = files;

              // Clear and show existing files
              if (instance.previewContainer) {
                // Remove old existing files previews
                const oldPreviews = instance.previewContainer.querySelectorAll('.preview-item[data-existing="true"]');
                oldPreviews.forEach(preview => preview.remove());

                // Show new existing files
                FileElementFactory.showExistingFiles(instance, files);
              }
            }
          }
        }
        break;

      default:
        // For single select: delegate to SelectElementFactory which handles empty values correctly
        if (field.tagName === 'SELECT' && !field.multiple) {
          const instance = window.ElementManager?.getInstanceByElement(field);
          if (instance?.setValue) {
            instance.setValue(value);
          } else {
            // Fallback when ElementManager is not available
            const valueStr = value != null ? String(value) : '';
            const opt = Array.from(field.options).find(o =>
              (o.hasAttribute('value') ? o.getAttribute('value') : o.text) === valueStr
            );
            if (opt) opt.selected = true;
          }
        } else {
          field.value = value ?? '';
          const instance = window.ElementManager?.getInstanceByElement(field);
          instance?.setValue?.(value);
        }
        break;
    }

    // Dispatch change event (unless silent mode)
    if (!silent) {
      field.dispatchEvent(new Event('change', {bubbles: true}));
    }
  },

  async submitAjax(instance, data) {
    const {element, config} = instance;
    const hasFiles = Array.from(element.elements).some(el => {
      if (el.type !== 'file') return false;

      // Check native element.files
      if (el.files?.length > 0) return true;

      // Check FileElementFactory private state for files (drag-drop, etc.)
      if (window.ElementFactory && ElementFactory._privateState) {
        const privateState = ElementFactory._privateState.get(el);
        if (privateState?.files instanceof Map && privateState.files.size > 0) return true;
      }

      return false;
    });
    try {
      const formMethodAttr = element.getAttribute('method');
      const formActionAttr = element.getAttribute('action');
      const formMethodProp = typeof element.method === 'string' ? element.method : '';
      const formActionProp = typeof element.action === 'string' ? element.action : '';

      const method = element.getAttribute('data-method') ||
        formMethodAttr ||
        formMethodProp ||
        config.method ||
        'POST';

      const url = element.getAttribute('data-action') ||
        formActionAttr ||
        formActionProp ||
        config.action ||
        window.location.href;

      let response;

      if (hasFiles) {
        response = await this.submitWithProgress(instance, url, data.formData);
      } else {
        const methodLower = method.toLowerCase();

        const buildHeaders = () => {
          if (instance.config.csrf === false) {
            return {'X-Skip-CSRF': 'true'};
          }
          return undefined;
        };

        // Use HttpClient (window.http) as primary client with CSRF protection
        const requestOptions = {
          throwOnError: false
        };

        const headers = buildHeaders();
        if (headers) {
          requestOptions.headers = headers;
        }

        if (methodLower === 'get') {
          response = await window.http.get(url, {
            ...requestOptions,
            params: data.jsonData
          });
        } else if (methodLower === 'delete') {
          response = await window.http.delete(url, {
            ...requestOptions,
            params: data.jsonData
          });
        } else {
          response = await window.http[methodLower](url, data.jsonData, requestOptions);
        }
      }

      // Check for success using response.success (unified standard)
      // Both HttpClient and XHR now return response.success
      if (response.success) {
        const apiResponse = response.data || {};

        return {
          success: true,  // Normalized success flag
          message: Now.translate(apiResponse.message || config.successMessage),
          data: apiResponse.data || apiResponse,  // Extract data field from API response
          status: response.status,
          code: apiResponse.code
        };
      } else {
        return {
          success: false,
          message: Now.translate(response.data?.message || response.statusText || config.errorMessage),
          errors: response.data?.errors || {},
          status: response.status,
          data: response.data
        };
      }

    } catch (error) {
      return {
        success: false,
        message: Now.translate(error.message || config.errorMessage),
        error: error
      };
    }
  },

  submitWithProgress(instance, url, formData) {
    return new Promise((resolve, reject) => {
      const {element, config} = instance;

      const xhr = new XMLHttpRequest();

      const progressContainer = this.createProgressElement(element);
      progressContainer.style.display = 'block';

      xhr.upload.addEventListener('progress', (e) => {
        if (e.lengthComputable) {
          this.updateProgress(progressContainer, e.loaded, e.total);
        }
      });

      xhr.addEventListener('load', () => {
        progressContainer.style.display = 'none';

        const buildXhrResult = (success, data = null) => ({
          success,
          status: xhr.status,
          statusText: xhr.statusText,
          data,
          rawResponse: xhr.responseText
        });

        if (xhr.status >= 200 && xhr.status < 300) {
          try {
            // XHR gets full API response: { success: true, message: "...", code: 201, data: {...} }
            const apiResponse = JSON.parse(xhr.responseText);

            resolve(buildXhrResult(apiResponse.success !== false, apiResponse));
          } catch (e) {
            resolve(buildXhrResult(true, {
              message: Now.translate(config.successMessage),
              rawResponse: xhr.responseText
            }));
          }
        } else {
          let errorData = null;

          try {
            errorData = JSON.parse(xhr.responseText);
          } catch (e) {
            errorData = {
              message: Now.translate(config.errorMessage)
            };
          }

          resolve(buildXhrResult(false, errorData));
        }
      });

      xhr.addEventListener('error', () => {
        progressContainer.style.display = 'none';
        resolve({
          success: false,
          status: 0,
          statusText: 'Network Error',
          data: {
            message: 'Network error occurred'
          }
        });
      });

      xhr.addEventListener('abort', () => {
        progressContainer.style.display = 'none';
        resolve({
          success: false,
          status: 0,
          statusText: 'Aborted',
          data: {
            message: 'Request was aborted'
          }
        });
      });

      xhr.open('POST', url);
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      Now.applyRequestLanguageToXhr(xhr);

      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      if (csrfToken) {
        xhr.setRequestHeader('X-CSRF-Token', csrfToken);
      }

      xhr.send(formData);
    });
  },

  createProgressElement(form) {
    let progress = form.querySelector('.upload-progress');

    if (!progress) {
      const template = document.createElement('div');
      template.innerHTML = this.config.uploadProgressTemplate.trim();
      progress = template.firstChild;
      form.appendChild(progress);
    }

    return progress;
  },

  updateProgress(container, loaded, total) {
    const percent = Math.round((loaded / total) * 100);

    const bar = container.querySelector('.progress-bar');
    if (bar) {
      bar.style.width = percent + '%';
      bar.setAttribute('aria-valuenow', percent);
    }

    const text = container.querySelector('.progress-text');
    if (text) {
      const loadedSize = this.formatFileSize(loaded);
      const totalSize = this.formatFileSize(total);
      text.textContent = `${percent}% (${loadedSize} / ${totalSize})`;
    }

    this.emitEvent('form:upload-progress', {
      loaded,
      total,
      percent
    });
  },

  resolveTargetElement(target, instance = null) {
    if (!target) {
      return null;
    }

    if (target instanceof HTMLElement) {
      return target;
    }

    if (instance?.element) {
      if (target === 'self' || target === 'current') {
        return instance.element;
      }

      const localMatch = instance.element.querySelector(target);
      if (localMatch) {
        return localMatch;
      }
    }

    if (typeof target === 'string') {
      if (target.startsWith('#')) {
        return document.getElementById(target.substring(1));
      }
      return document.querySelector(target);
    }

    return null;
  },

  ensureHiddenField(instance, fieldName, defaultValue = '') {
    if (!instance?.element || !fieldName) {
      return null;
    }

    let field = instance.element.querySelector(`[name="${fieldName}"]`);
    if (!field) {
      field = document.createElement('input');
      field.type = 'hidden';
      field.name = fieldName;
      field.value = defaultValue;
      instance.element.appendChild(field);
    } else if (defaultValue !== '' && (field.value === '' || field.value == null)) {
      field.value = defaultValue;
    }

    try {
      instance.state.data[fieldName] = field.value;
    } catch (e) {
    }

    return field;
  },

  prepareSubmitRequest(instance) {
    if (!instance?.config?.submitTarget) {
      return;
    }

    const pageFieldName = instance.config.submitPageField || this.config.submitPageField;
    if (!pageFieldName) {
      return;
    }

    const pageField = this.ensureHiddenField(instance, pageFieldName, '1');
    if (!pageField) {
      return;
    }

    if (instance.state.paginationSubmitPending === true) {
      instance.state.paginationSubmitPending = false;
      return;
    }

    pageField.value = '1';
    instance.state.data[pageFieldName] = '1';
  },

  buildSubmitPagination(meta, windowSize = null) {
    const currentPage = Math.max(1, parseInt(meta?.page, 10) || 1);
    const totalPages = Math.max(1, parseInt(meta?.totalPages, 10) || 1);
    const total = Math.max(0, parseInt(meta?.total, 10) || 0);
    const pageSize = Math.max(1, parseInt(meta?.pageSize, 10) || total || 1);
    const visibleWindow = Math.max(3, parseInt(windowSize, 10) || this.config.submitPaginationWindow || 5);

    let startPage = Math.max(1, currentPage - Math.floor(visibleWindow / 2));
    let endPage = Math.min(totalPages, startPage + visibleWindow - 1);

    if (endPage - startPage + 1 < visibleWindow) {
      startPage = Math.max(1, endPage - visibleWindow + 1);
    }

    const pages = [];
    for (let page = startPage; page <= endPage; page++) {
      pages.push({
        page,
        label: String(page),
        current: page === currentPage
      });
    }

    const from = total === 0 ? 0 : ((currentPage - 1) * pageSize) + 1;
    const to = total === 0 ? 0 : Math.min(total, currentPage * pageSize);

    return {
      currentPage,
      totalPages,
      total,
      pageSize,
      pages,
      hasPrev: currentPage > 1,
      hasNext: currentPage < totalPages,
      prevPage: currentPage > 1 ? currentPage - 1 : 1,
      nextPage: currentPage < totalPages ? currentPage + 1 : totalPages,
      from,
      to
    };
  },

  normalizeSubmitBindingPayload(payload, instance = null) {
    const source = payload ?? {};

    if (Array.isArray(source)) {
      const meta = {
        page: 1,
        pageSize: source.length || 0,
        total: source.length || 0,
        totalPages: 1
      };

      return {
        data: source,
        meta,
        filters: {},
        options: {},
        columns: undefined,
        submitted: true,
        hasData: source.length > 0,
        empty: source.length === 0,
        pagination: this.buildSubmitPagination(meta, instance?.config?.submitPaginationWindow)
      };
    }

    if (!source || typeof source !== 'object') {
      const meta = {page: 1, pageSize: 1, total: 0, totalPages: 1};
      return {
        data: [],
        meta,
        filters: {},
        options: {},
        columns: undefined,
        submitted: true,
        hasData: false,
        empty: true,
        pagination: this.buildSubmitPagination(meta, instance?.config?.submitPaginationWindow),
        raw: source
      };
    }

    const hasOwnData = Object.prototype.hasOwnProperty.call(source, 'data');
    const dataValue = hasOwnData ? source.data : [];
    const rows = Array.isArray(dataValue) ? dataValue : [];
    const metaSource = source.meta && typeof source.meta === 'object' ? source.meta : {};
    const fallbackTotal = Array.isArray(dataValue) ? dataValue.length : (dataValue ? 1 : 0);
    const total = Math.max(0, parseInt(metaSource.total ?? source.total ?? fallbackTotal, 10) || 0);
    const fallbackPageSize = Array.isArray(dataValue) ? (dataValue.length || 1) : 1;
    const pageSize = Math.max(1, parseInt(metaSource.pageSize ?? metaSource.limit ?? source.pageSize ?? source.limit ?? fallbackPageSize, 10) || 1);
    const fallbackTotalPages = Math.ceil(total / pageSize) || 1;
    const totalPagesValue = metaSource.totalPages ?? source.pages ?? source.totalPages ?? fallbackTotalPages;
    const totalPages = Math.max(1, parseInt(totalPagesValue, 10) || 1);
    const page = Math.min(totalPages, Math.max(1, parseInt(metaSource.page ?? source.page ?? 1, 10) || 1));
    const meta = {
      ...metaSource,
      page,
      pageSize,
      total,
      totalPages
    };

    return {
      ...source,
      data: dataValue,
      meta,
      filters: source.filters || {},
      options: source.options || {},
      columns: source.columns || undefined,
      submitted: true,
      hasData: Array.isArray(dataValue) ? dataValue.length > 0 : !!dataValue,
      empty: Array.isArray(dataValue) ? dataValue.length === 0 : !dataValue,
      pagination: this.buildSubmitPagination(meta, instance?.config?.submitPaginationWindow),
      raw: source
    };
  },

  bindSubmitTarget(instance, payload) {
    if (!instance?.config?.submitTarget || !window.TemplateManager) {
      return null;
    }

    const target = this.resolveTargetElement(instance.config.submitTarget, instance);
    if (!target) {
      return null;
    }

    const normalized = this.normalizeSubmitBindingPayload(payload, instance);
    const context = {
      state: normalized,
      data: normalized.data,
      computed: {}
    };

    instance.state.submitBinding = normalized;

    TemplateManager.processTemplate(target, context);

    try {
      if (typeof TemplateManager.processDataOnLoad === 'function') {
        TemplateManager.processDataOnLoad(target, context);
      }
    } catch (error) {
      console.warn('FormManager: submit target data-on-load failed', error);
    }

    this.renderSubmitPagination(instance, normalized);
    return normalized;
  },

  handleSubmitPaginationClick(instance, page) {
    if (!instance?.element) {
      return;
    }

    const pageFieldName = instance.config.submitPageField || this.config.submitPageField;
    if (!pageFieldName) {
      return;
    }

    const pageField = this.ensureHiddenField(instance, pageFieldName, String(page));
    if (!pageField) {
      return;
    }

    pageField.value = String(page);
    instance.state.data[pageFieldName] = String(page);
    instance.state.paginationSubmitPending = true;

    if (typeof instance.element.requestSubmit === 'function') {
      instance.element.requestSubmit();
    } else {
      instance.element.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
    }
  },

  renderSubmitPagination(instance, binding) {
    const targetRef = instance?.config?.submitPaginationTarget;
    if (!targetRef) {
      return;
    }

    const target = this.resolveTargetElement(targetRef, instance);
    if (!target) {
      return;
    }

    target.innerHTML = '';

    const pagination = binding?.pagination;
    if (!pagination || pagination.totalPages <= 1) {
      return;
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'pagination';

    const addButton = (page, label, isCurrent = false, className = '') => {
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = label;
      if (className) {
        button.className = className;
      }

      if (isCurrent) {
        button.disabled = true;
        button.classList.add('current');
        button.setAttribute('aria-current', 'page');
      } else {
        button.classList.add('pagination-button');
        button.setAttribute('aria-label', Now.translate('Go to page {page}', {page}));
        button.addEventListener('click', (event) => {
          event.preventDefault();
          this.handleSubmitPaginationClick(instance, page);
        });
      }

      wrapper.appendChild(button);
    };

    if (pagination.hasPrev) {
      addButton(pagination.prevPage, '«', false, 'btn');
    }

    pagination.pages.forEach(item => {
      addButton(item.page, item.label, item.current);
    });

    if (pagination.hasNext) {
      addButton(pagination.nextPage, '»', false, 'btn');
    }

    target.appendChild(wrapper);
  },

  syncSubmitQueryParams(instance) {
    if (!instance?.config?.submitQueryParams || !window.history?.replaceState) {
      return;
    }

    const queryFields = this.normalizeFieldList(instance.config.submitQueryFields);
    let params = {};

    try {
      params = this.getFormData(instance, true)?.jsonData || {};
    } catch (error) {
      params = instance.state?.data || {};
    }

    const entries = queryFields.length > 0
      ? queryFields.reduce((result, fieldName) => {
        result[fieldName] = params[fieldName];
        return result;
      }, {})
      : params;

    const url = new URL(window.location.href);

    Object.entries(entries).forEach(([key, value]) => {
      url.searchParams.delete(key);

      if (Array.isArray(value)) {
        value.forEach((item) => {
          if (item !== undefined && item !== null && item !== '') {
            url.searchParams.append(key, String(item));
          }
        });
        return;
      }

      if (value !== undefined && value !== null && value !== '') {
        url.searchParams.set(key, String(value));
      }
    });

    window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
  },

  formatFileSize(bytes) {
    const units = ['B', 'KB', 'MB', 'GB'];
    let size = bytes;
    let unit = 0;

    while (size >= 1024 && unit < units.length - 1) {
      size /= 1024;
      unit++;
    }

    return `${Math.round(size * 10) / 10} ${units[unit]}`;
  },

  autoFillIntendedUrl(form) {
    const urlParams = new URLSearchParams(window.location.search);
    // prefer explicit `redirect` or `return_to` in query
    const redirectParam = urlParams.get('redirect') || urlParams.get('return_to') || null;

    // prefer sessionStorage key set by RedirectManager (auth_intended_route)
    let sessionIntended = null;
    const normalizePath = (pathname) => {
      const routerBase = window.RouterManager?.config?.base || '';
      let normalized = pathname || '/';
      if (routerBase && normalized.startsWith(routerBase)) {
        normalized = normalized.slice(routerBase.length) || '/';
        if (!normalized.startsWith('/')) normalized = '/' + normalized;
      }
      return normalized;
    };

    try {
      const stored = sessionStorage.getItem('auth_intended_route');

      if (stored) {
        const parsed = JSON.parse(stored);

        if (parsed && parsed.path) {
          const normalizedPath = normalizePath(parsed.path);
          sessionIntended = normalizedPath + (parsed.query || '') + (parsed.hash || '');
        }
      }
    } catch (e) {
      sessionIntended = null;
    }

    let intendedUrl = null;

    if (redirectParam && this.isValidRedirectUrl(redirectParam)) {
      intendedUrl = redirectParam;
    } else if (sessionIntended) {
      const isValid = this.isValidRedirectUrl(sessionIntended);

      if (isValid) {
        intendedUrl = sessionIntended;
      }
    }

    // Find or create hidden input for intended_url
    let intendedInput = form.querySelector('input[name="intended_url"]');

    if (!intendedInput) {
      try {
        intendedInput = document.createElement('input');
        intendedInput.type = 'hidden';
        intendedInput.name = 'intended_url';
        form.appendChild(intendedInput);
      } catch (e) {
        return;
      }
    }

    // Set the value (empty string if no intended URL)
    if (intendedUrl) {
      intendedInput.value = intendedUrl;
      form.dataset.redirectAfterLogin = intendedUrl;
    } else {
      intendedInput.value = '';
    }
  },

  /**
   * Restore remembered username/email into the login form if present in localStorage.
   * This only restores the username/email and never stores passwords.
   */
  restoreRememberedCredentials(instance) {
    try {
      if (!instance || !instance.element) return;
      const form = instance.element;

      // Only restore for login forms
      const formType = form.dataset && form.dataset.form ? form.dataset.form : null;
      if (formType !== 'login') return;

      const stored = localStorage.getItem('remember_username');
      if (!stored) return;

      const usernameInput = form.querySelector('input[name="username"]') || form.querySelector('input[type="email"]') || form.querySelector('input[type="text"]');
      const rememberCheckbox = form.querySelector('input[name="remember"], input#remember');

      if (usernameInput) {
        usernameInput.value = stored;
        try {instance.state.data[usernameInput.name || 'username'] = stored;} catch (e) {}
      }

      if (rememberCheckbox) {
        rememberCheckbox.checked = true;
      }
    } catch (e) {}
  },

  /**
   * Restore persisted values for fields with data-persist attribute.
   * Supports localStorage (default) and cookie via data-persist="cookie".
   */
  restorePersistedValues(instance) {
    try {
      if (!instance || !instance.element) return;
      const form = instance.element;

      const items = form.querySelectorAll('[data-persist]');
      if (!items || items.length === 0) return;

      items.forEach(el => {
        try {
          const persistAttr = el.dataset.persist;
          if (!persistAttr || persistAttr === 'false') return;

          const storage = (persistAttr === 'cookie') ? 'cookie' : 'local';
          const key = el.dataset.persistKey || (`nowjs_persist:${form.dataset.form || window.location.pathname}:${el.name || el.id}`);

          // skip passwords unless explicitly allowed
          if ((el.type === 'password' || el.dataset.element === 'password') && el.dataset.persistAllowPassword !== 'true') return;

          let value = null;
          if (storage === 'cookie') {
            value = this._getCookie(key);
          } else {
            const raw = localStorage.getItem(key);
            if (raw) {
              try {
                const parsed = JSON.parse(raw);
                if (!parsed || !parsed.value) return;
                if (parsed.expires && Date.now() > parsed.expires) {
                  localStorage.removeItem(key);
                  return;
                }
                value = parsed.value;
              } catch (e) {
                value = raw;
              }
            }
          }

          if (value === null || value === undefined) return;
          // Apply the value depending on field type
          if (el.type === 'checkbox') {
            el.checked = value === '1' || value === true || value === 'true';
          } else if (el.type === 'radio') {
            const radios = form.querySelectorAll(`input[name="${el.name}"]`);
            radios.forEach(r => {r.checked = (r.value === value);});
          } else if (el.tagName.toLowerCase() === 'select') {
            try {el.value = value;} catch (e) {}
          } else {
            el.value = value;
          }

          try {instance.state.data[el.name || el.id] = (el.type === 'checkbox' ? el.checked : el.value);} catch (e) {}
        } catch (e) {}
      });
    } catch (e) {}
  },

  /**
   * Save persisted values for fields with data-persist attribute. Called on successful submit.
   */
  savePersistedValues(instance) {
    try {
      if (!instance || !instance.element) return;
      const form = instance.element;
      const items = form.querySelectorAll('[data-persist]');
      if (!items || items.length === 0) return;

      items.forEach(el => {
        try {
          const persistAttr = el.dataset.persist;
          if (!persistAttr || persistAttr === 'false') return;
          const persistOn = el.dataset.persistOn || 'submit';
          if (persistOn !== 'submit') return; // currently only support submit-triggered saves

          const storage = (persistAttr === 'cookie') ? 'cookie' : 'local';
          const key = el.dataset.persistKey || (`nowjs_persist:${form.dataset.form || window.location.pathname}:${el.name || el.id}`);
          const ttlDays = parseInt(el.dataset.persistTtlDays || el.dataset.persistTtl || '0');

          // skip passwords unless explicitly allowed
          if ((el.type === 'password' || el.dataset.element === 'password') && el.dataset.persistAllowPassword !== 'true') return;

          let value = null;
          if (el.type === 'checkbox') {
            value = el.checked ? '1' : '0';
          } else if (el.type === 'radio') {
            if (el.checked) value = el.value;
            else return; // don't save if not the checked radio
          } else if (el.tagName.toLowerCase() === 'select') {
            value = el.value;
          } else {
            value = el.value;
          }

          if (storage === 'cookie') {
            const days = isNaN(ttlDays) ? 0 : ttlDays;
            this._setCookie(key, value, days);
          } else {
            const obj = {value: value};
            if (!isNaN(ttlDays) && ttlDays > 0) {
              obj.expires = Date.now() + ttlDays * 24 * 60 * 60 * 1000;
            }
            try {localStorage.setItem(key, JSON.stringify(obj));} catch (e) {localStorage.setItem(key, value);}
          }
        } catch (e) {}
      });
    } catch (e) {}
  },

  // cookie helpers
  _setCookie(name, value, days) {
    try {
      if (!name || typeof name !== 'string') return;

      let expires = '';
      if (days && days > 0) {
        const date = new Date();
        date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
        expires = '; expires=' + date.toUTCString();
      }
      document.cookie = encodeURIComponent(name) + '=' + encodeURIComponent(value == null ? '' : value) + expires + '; path=/';
    } catch (e) {}
  },

  _getCookie(name) {
    try {
      const cookies = document.cookie ? document.cookie.split(';') : [];
      for (const c of cookies) {
        const [k, ...rest] = c.trim().split('=');
        if (decodeURIComponent(k) === name) return decodeURIComponent(rest.join('='));
      }
      return null;
    } catch (e) {return null;}
  },

  performRedirect(url) {
    // Try using RouterManager first if available and properly initialized
    if (window.RouterManager?.state?.initialized && typeof window.RouterManager.navigate === 'function') {
      try {
        RouterManager.navigate(url);
        return;
      } catch (error) {
        console.warn('RouterManager.navigate failed, falling back to window.location.href:', error);
      }
    }

    // Fallback to direct browser navigation
    window.location.href = url;
  },

  async handleSuccessfulSubmit(instance, response) {
    const {element, config} = instance;

    FormError.clearAll();

    instance.state.apiFieldErrors = {};

    if (config.resetAfterSubmit) {
      this.resetForm(instance);
    }

    const data = this.normalizeSubmitBindingPayload(response.data, instance);

    // Handle login form authentication OR register form with auto-login
    if (data && data.user && data.token &&
      (element.dataset.form === 'login' || element.dataset.form === 'register')) {
      const authManager = Now.getManager('auth');

      if (authManager && typeof authManager.setAuthenticatedUser === 'function') {
        // Use proper AuthManager method instead of direct state manipulation
        // Pass the standardized API response structure to setAuthenticatedUser
        try {
          const setUserResult = await authManager.setAuthenticatedUser(data, {
            preventRedirect: true // FormManager will handle redirect
          });

          if (!(setUserResult && setUserResult.success)) {
            console.warn('Failed to set authenticated user:', setUserResult?.message || 'Unknown error');
          }
        } catch (error) {
          console.error('Error setting authenticated user:', error);
        }
      } else {
        console.warn('AuthManager not available or setAuthenticatedUser method not found');
      }
      // Persist "remember me" username locally if requested on login forms
      try {
        const formEl = element;
        const formType = formEl.dataset && formEl.dataset.form ? formEl.dataset.form : null;
        if (formType === 'login') {
          const rememberCheckbox = formEl.querySelector('input[name="remember"], input#remember');
          const usernameInput = formEl.querySelector('input[name="username"]') || formEl.querySelector('input[type="email"]') || formEl.querySelector('input[type="text"]');

          if (rememberCheckbox && rememberCheckbox.checked && usernameInput && usernameInput.value) {
            try {localStorage.setItem('remember_username', usernameInput.value);} catch (e) {}
          } else {
            try {localStorage.removeItem('remember_username');} catch (e) {}
          }
        }
      } catch (e) {}
    }

    // For login forms, force redirect to configured after-login path.
    if (element.dataset.form === 'login' && data && data.actions) {
      const forcedAfterLogin = element.dataset.redirectAfterLogin
        || window.RouterManager?.config?.auth?.redirects?.afterLogin
        || '/';
      const redirectAction = data.actions.find(a => a.type === 'redirect');
      if (redirectAction) {
        redirectAction.url = forcedAfterLogin;
      }
      sessionStorage.removeItem('intended_url');
      sessionStorage.removeItem('auth_intended_route');
    }

    // Process API response actions using ResponseHandler
    try {
      await ResponseHandler.process(data, {
        formId: instance.id,
        form: element,
        instance: instance
      });
    } catch (error) {
      console.error('FormManager: Error processing response actions', error);
    }

    if (config.submitTarget) {
      this.bindSubmitTarget(instance, response.data);
    }

    if (config.submitQueryParams) {
      this.syncSubmitQueryParams(instance);
    }

    // Show success message (if not handled by ResponseHandler actions)
    if (response.message && !(data && data.actions)) {
      if (config.showSuccessInline !== false) {
        FormError.showSuccess(response.message, element);
      }
      if (config.showSuccessInNotification !== false && window.NotificationManager) {
        NotificationManager.success(response.message);
      }
    }

    // Handle redirect (if not handled by ResponseHandler actions)
    if (!(data && data.actions && data.actions.some(a => a.type === 'redirect'))) {
      const redirectUrl = this.determineRedirectUrl(element, response, config);

      if (redirectUrl) {
        // Emit redirect start event
        this.emitEvent('redirect:start', {
          formId: instance.id,
          url: redirectUrl,
          delay: 1000
        });

        setTimeout(() => {
          this.performRedirect(redirectUrl);
        }, 1000);
      }
    }

    this.emitEvent('form:submitted', {
      formId: instance.id,
      response: response
    });
  },

  determineRedirectUrl(element, response, config) {
    // Use intended URL only when explicitly enabled.
    const shouldUseIntendedUrl = element.dataset.useIntendedUrl === 'true';

    // Force login forms to land on after-login route.
    if (element.dataset.form === 'login') {
      return element.dataset.redirectAfterLogin
        || window.RouterManager?.config?.auth?.redirects?.afterLogin
        || '/';
    }

    if (shouldUseIntendedUrl) {
      // Check hidden input, dataset, or sessionStorage
      let intendedUrl = element.querySelector('input[name="intended_url"]')?.value
        || element.dataset.redirectAfterLogin
        || sessionStorage.getItem('intended_url');

      // Also check auth_intended_route from sessionStorage
      if (!intendedUrl) {
        try {
          const stored = sessionStorage.getItem('auth_intended_route');
          if (stored) {
            const route = JSON.parse(stored);
            if (route && route.path) {
              intendedUrl = route.path + (route.query || '') + (route.hash || '');
            }
          }
        } catch (e) {
          // ignore JSON parse errors
        }
      }

      if (intendedUrl && this.isValidRedirectUrl(intendedUrl)) {
        sessionStorage.removeItem('intended_url');
        sessionStorage.removeItem('auth_intended_route');
        return intendedUrl;
      }
    }

    // Check response for redirect URL
    if (response.data?.redirectUrl || response.data?.redirect?.url) {
      const url = response.data.redirectUrl || response.data.redirect.url;
      return url;
    }

    // Check for role-based redirect
    if (response.data?.user?.role) {
      const role = response.data.user.role;
      const roleRedirect = element.dataset[`redirect${role.charAt(0).toUpperCase() + role.slice(1)}`];
      if (roleRedirect) {
        return roleRedirect;
      }
    }

    // Check for success-specific redirect first
    if (element.dataset.successRedirect) {
      return element.dataset.successRedirect;
    }

    // Fall back to general redirect
    if (element.dataset.redirect) {
      return element.dataset.redirect;
    }

    // Use config redirect (from extracted form config)
    if (config.successRedirect) {
      return config.successRedirect;
    }

    // Default redirect
    if (config.redirect) {
      return config.redirect;
    }

    return null;
  },

  isValidRedirectUrl(url) {
    if (!url || typeof url !== 'string') return false;

    try {

      if (url.startsWith('/') && !url.startsWith('//')) {
        return true;
      }

      const urlObj = new URL(url);
      return urlObj.origin === window.location.origin;
    } catch {
      return false;
    }
  },

  async handleFailedSubmit(instance, response) {
    const {element, config} = instance;
    const hasFieldErrorMap = !!(response?.errors
      && typeof response.errors === 'object'
      && !Array.isArray(response.errors)
      && Object.keys(response.errors).length > 0);

    // Process API response actions using ResponseHandler
    try {
      await ResponseHandler.process(response, {
        formId: instance.id,
        form: element,
        instance: instance
      });
    } catch (error) {
      console.error('FormManager: Error processing response actions', error);
    }

    // Show error message (if not handled by ResponseHandler actions)
    if (response.message && !response.actions && !hasFieldErrorMap) {
      if (config.showErrorsInline !== false) {
        FormError.showGeneralError(response.message, element);
      }

      if (config.showErrorsInNotification !== false && window.NotificationManager) {
        NotificationManager.error(response.message);
      }
    }

    // Show field errors (if not handled by ResponseHandler actions)
    if (response.errors && !response.actions) {
      instance.state.apiFieldErrors = {};
      const errorMessages = [];
      let firstResolvedField = null;

      // Check if errors is a string or array instead of an object
      if (typeof response.errors === 'string') {
        errorMessages.push(response.errors);
      } else if (Array.isArray(response.errors)) {
        errorMessages.push(...response.errors);
      } else if (typeof response.errors === 'object') {
        Object.entries(response.errors).forEach(([field, messages]) => {
          const message = Array.isArray(messages) ? messages[0] : messages;
          instance.state.apiFieldErrors[field] = message;
          const resolvedMessages = FormError.showFieldError(field, message, element) || [];
          const resolvedMessage = resolvedMessages[0] || message;
          const resolvedField = typeof FormError.resolveFieldElement === 'function'
            ? FormError.resolveFieldElement(field, element)
            : null;

          // Always highlight the field
          instance.state.errors[field] = resolvedMessage;

          if (resolvedField) {
            if (!firstResolvedField) {
              firstResolvedField = resolvedField;
            }
          }

          const hasInlineTarget = typeof FormError.hasInlineErrorContainer === 'function'
            && FormError.hasInlineErrorContainer(field, element);
          if (!resolvedField || !hasInlineTarget) {
            let toastLine = resolvedMessage;
            if (resolvedField) {
              const label = this.getFieldLabel(resolvedField, element).trim();
              if (label) {
                toastLine = `${label}: ${resolvedMessage}`;
              }
            }
            errorMessages.push(toastLine);
          }
        });
      }

      if (firstResolvedField) {
        const shouldFocusField = !(firstResolvedField.dataset?.autocomplete === 'true');

        if (shouldFocusField) {
          try {
            firstResolvedField.focus();
          } catch (e) {
            // ignore focus errors on custom widgets
          }
        }

        try {
          firstResolvedField.scrollIntoView({
            behavior: 'smooth',
            block: 'center'
          });
        } catch (e) {
          // ignore scroll errors
        }
      }

      // Toast when a field has no DOM node and/or no inline result container (#result_* / data-result).
      if (config.showErrorsInNotification !== false && window.NotificationManager && errorMessages.length > 0) {
        const combinedMessage = errorMessages.length === 1
          ? errorMessages[0]
          : errorMessages.map(m => `• ${m}`).join('\n');
        NotificationManager.error(combinedMessage);
      }
    }

    this.emitEvent('form:error', {
      formId: instance.id,
      response: response,
      errors: response.errors
    });
  },

  handleInvalidSubmit(instance) {
    const invalidFieldDetails = Array.isArray(instance?.state?.invalidFieldDetails)
      ? instance.state.invalidFieldDetails
      : [];

    this.logInvalidFieldDetails(instance, invalidFieldDetails);

    if (window.NotificationManager) {
      const firstDetail = invalidFieldDetails[0] || null;
      const firstError = Object.values(instance.state.errors)[0];
      const message = firstDetail?.label && firstDetail?.message
        ? `${firstDetail.label}: ${firstDetail.message}`
        : (firstDetail?.message || firstError || Now.translate('Please correct the errors in the form before submitting'));
      NotificationManager.error(message);
    }

    this.emitEvent('form:validation:failed', {
      formId: instance.id,
      errors: instance.state.errors,
      invalidFieldDetails
    });
  },

  handleSubmitError(instance, error) {
    if (window.NotificationManager) {
      NotificationManager.error(error.message || 'An unexpected error occurred');
    }

    ErrorManager.handle(error, {
      context: 'FormManager.submitForm',
      type: 'error:form',
      data: {
        formId: instance.id
      }
    });

    this.emitEvent('form:error', {
      formId: instance.id,
      error: error
    });
  },

  resetForm(instance) {
    const {element, elements} = instance;

    element.reset();

    FormError.clearAll();

    instance.state.errors = {};
    instance.state.apiFieldErrors = {};
    instance.state.invalidFieldDetails = [];
    instance.state.valid = true;

    instance.state.data = {...instance.state.originalData};

    elements.forEach((field, name) => {
      if (Array.isArray(field)) {
        field.forEach(el => {
          el.classList.remove(instance.config.dirtyClass);
          el.classList.remove(instance.config.validClass);
          el.classList.remove(instance.config.invalidClass);
        });
      } else {
        field.classList.remove(instance.config.dirtyClass);
        field.classList.remove(instance.config.validClass);
        field.classList.remove(instance.config.invalidClass);
      }
    });

    this.emitEvent('form:reset', {
      formId: instance.id
    });
  },

  /**
   * Handle cascade when a field with data-cascade-source changes
   * @param {Object} instance - Form instance
   * @param {HTMLElement} sourceField - The field that triggered the cascade
   */
  async handleCascade(instance, sourceField) {
    const {element} = instance;
    const cascadeApi = element.dataset.cascadeApi;

    if (!cascadeApi) {
      console.warn('FormManager: data-cascade-api not found on form');
      return;
    }

    // Prevent cascade loop - check if we're already processing a cascade
    if (instance.state.cascading) return;

    try {
      // Mark as cascading to prevent loops
      instance.state.cascading = true;

      // Show loading state on source field
      sourceField.classList.add('loading');

      // Collect all fields with data-cascade-source
      const cascadeFields = Array.from(element.querySelectorAll('[data-cascade-source]'));

      // Build FormData with all cascade field values
      const formData = new FormData();
      cascadeFields.forEach(field => {
        const fieldName = field.name || field.id;
        const fieldValue = this.getFieldValue(field);
        if (fieldValue !== null && fieldValue !== undefined && fieldValue !== '') {
          formData.append(fieldName, fieldValue);
        }
      });

      // Add source parameter (which field triggered the change)
      const sourceName = sourceField.dataset.cascadeSource;
      formData.append('source', sourceName);

      // Call cascade API using framework's HTTP client (includes CSRF token)
      let response;
      if (window.ApiService && typeof window.ApiService.post === 'function') {
        response = await window.ApiService.post(cascadeApi, formData);
      } else if (window.simpleFetch && typeof window.simpleFetch.post === 'function') {
        response = await window.simpleFetch.post(cascadeApi, formData);
      } else {
        throw new Error('FormManager: No HTTP client available (ApiService or simpleFetch required)');
      }

      // Process response
      if (response && response.data) {
        this.processCascadeResponse(instance, response.data, sourceField);
      }

    } catch (error) {
      console.error('FormManager: Error handling cascade:', error);
    } finally {
      sourceField.classList.remove('loading');
      // Release cascade lock
      instance.state.cascading = false;
    }
  },

  /**
   * Process cascade API response
   * Format: {data: {...}, options: {...}}
   * @param {Object} instance - Form instance
   * @param {Object} responseData - Response data from cascade API
   * @param {HTMLElement} sourceField - The field that triggered the cascade (optional)
   */
  processCascadeResponse(instance, responseData, sourceField = null) {
    const {element} = instance;

    // Handle nested structure from API wrapper (e.g., {data: {data: {...}, options: {...}}})
    let actualData = responseData;
    if (responseData.data && responseData.data.data && responseData.data.options) {
      actualData = responseData.data;
    }

    // Process data fields (auto-fill)
    if (actualData.data) {
      Object.keys(actualData.data).forEach(fieldName => {
        const fieldValue = actualData.data[fieldName];

        // Find field by name or data-attr
        let field = element.querySelector(`[name="${fieldName}"]`);

        if (!field) {
          field = element.querySelector(`[data-attr*="${fieldName}"]`);
        }

        if (field) {
          // Use silent mode to prevent triggering cascade loop
          this.setFieldValue(field, fieldValue, true);
        } else {
          console.warn(`FormManager: Field "${fieldName}" not found in form`);
        }
      });
    }

    // Process options (populate selects)
    if (actualData.options) {
      Object.keys(actualData.options).forEach(optionsKey => {
        const optionsArray = actualData.options[optionsKey];

        // Find all fields with matching data-options-key
        const targetFields = element.querySelectorAll(`[data-options-key="${optionsKey}"]`);

        targetFields.forEach(field => {
          if (field.tagName === 'SELECT') {
            // Use appropriate factory based on select type
            if (field.multiple) {
              // For multiple selects, use MultiSelectElementFactory only
              MultiSelectElementFactory.updateOptions(field, optionsArray);
            } else {
              // For single selects, use SelectElementFactory only
              SelectElementFactory.updateOptions(field, optionsArray);
            }
          } else if (field.tagName === 'INPUT' && field.dataset.autocomplete) {
            // For autocomplete inputs, update via TextElementFactory if available
            const elementInstance = window.ElementManager?.getInstance(field);
            if (elementInstance && typeof elementInstance.updateOptions === 'function') {
              elementInstance.updateOptions(optionsArray);
            } else {
              // Store options in dataset for later use
              try {
                field.dataset.cachedOptions = JSON.stringify(optionsArray);
              } catch (e) {
                console.warn('FormManager: Failed to cache options for autocomplete', e);
              }
            }
          }
        });
      });
    }
  },

  /**
   * Fallback method to update select options manually
   * @param {HTMLSelectElement} select - Select element
   * @param {Array} options - Array of options
   */
  updateSelectOptions(select, options) {
    // Store current value
    const currentValue = select.value;
    const hasPlaceholder = select.querySelector('option[value=""]');

    // Clear options except placeholder
    if (hasPlaceholder) {
      const placeholderHTML = hasPlaceholder.outerHTML;
      select.innerHTML = placeholderHTML;
    } else {
      select.innerHTML = '';
    }

    // Add new options
    if (Array.isArray(options)) {
      options.forEach(opt => {
        const option = document.createElement('option');

        if (typeof opt === 'object') {
          option.value = opt.value || opt.id || '';
          option.textContent = opt.text || opt.label || opt.name || option.value;
        } else {
          option.value = opt;
          option.textContent = opt;
        }

        select.appendChild(option);
      });
    }

    // Restore value if still exists
    if (currentValue) {
      select.value = currentValue;
    }
  },

  // Cleanly destroy a form instance: remove from maps and perform any cleanup
  destroyForm(instance) {
    try {
      if (!instance) return;

      clearTimeout(instance.state?.watchTimer);
      if (instance.state) {
        instance.state.watchRequestId = (instance.state.watchRequestId || 0) + 1;
      }

      // Cleanup all form elements first
      if (instance.elements && window.ElementManager) {
        instance.elements.forEach((field, fieldName) => {
          if (Array.isArray(field)) {
            // Radio/checkbox groups
            field.forEach(f => {
              if (f.id) {
                try {
                  window.ElementManager.destroy(f.id);
                } catch (err) {
                  // Element might not be enhanced, ignore
                }
              }
            });
          } else {
            // Single field
            if (field.id) {
              try {
                window.ElementManager.destroy(field.id);
              } catch (err) {
                // Element might not be enhanced, ignore
              }
            }
          }
        });
      }

      // remove from elementIndex (WeakMap) and forms map
      try {this.state.elementIndex.delete(instance.element);} catch (e) {}
      try {this.state.forms.delete(instance.id);} catch (e) {}

      this.emitEvent('form:destroy', {formId: instance.id});
    } catch (e) {
      console.warn('FormManager.destroyForm error', e);
    }
  },

  // Determine whether an element should be enhanced by FormManager
  shouldEnhance(element) {
    if (!element) return false;
    // Opt-in only: must have data-form or data-element
    if (element.dataset && (element.dataset.form || element.dataset.element)) return true;
    return false;
  },

  // Destroy forms in a specific container
  destroyContainer(container) {
    if (!container) return;

    const forms = container.querySelectorAll('form');
    forms.forEach(form => {
      const instance = this.state.elementIndex.get(form);
      if (instance) {
        this.destroyForm(instance);
      }
    });
  },

  // Scan a container for forms (opt-in via data-form) and init them
  scan(container = document) {
    if (!container || !container.querySelectorAll) return [];
    const found = Array.from(container.querySelectorAll('form[data-form]'));
    found.forEach(f => {
      if (!this.state.elementIndex.has(f)) this.initForm(f);
    });
    return found;
  },

  // Destroy form by element reference (convenience wrapper)
  destroyFormByElement(el) {
    if (!el) return;
    const inst = this.state.elementIndex.get(el);
    if (inst) return this.destroyForm(inst);
    const id = el.dataset && el.dataset.form;
    if (id && this.state.forms.has(id)) this.destroyForm(this.state.forms.get(id));
  },

  // Start/stop observer control (wrappers around setup/stop functions)
  startObserver(root) {
    if (root) this.observerConfig.observeRoot = root;
    this.setupFormObserver();
  },

  stopObserver() {
    this._stopFormObserver && this._stopFormObserver();
  },

  // Setup a scoped, batched MutationObserver to auto-init/destroy forms when necessary.
  // This observer is selective: it only processes added/removed nodes that match
  // form[data-form] or contain such descendants. It batches processing to reduce CPU.
  setupFormObserver() {
    // If already created, do nothing
    if (this._formObserver) return;

    this._addedQueue = new Set();
    this._removedQueue = new Set();
    this._processTimeout = null;

    const processQueues = () => {
      // process removals first to avoid double-init on re-render
      if (this._removedQueue.size) {
        const removed = Array.from(this._removedQueue);
        this._removedQueue.clear();
        removed.forEach(node => {
          try {
            // direct lookup via WeakMap
            const inst = this.state.elementIndex.get(node);
            if (inst) {
              // if the instance element is no longer connected, destroy
              if (!inst.element.isConnected) this.destroyForm(inst);
            } else if (node.dataset && node.dataset.form) {
              const maybe = this.state.forms.get(node.dataset.form);
              if (maybe && !maybe.element.isConnected) this.destroyForm(maybe);
            }
          } catch (e) {
            console.warn('FormManager.process removed error', e);
          }
        });
      }

      if (this._addedQueue.size) {
        const added = Array.from(this._addedQueue);
        this._addedQueue.clear();
        added.forEach(node => {
          try {
            if (!node || node.nodeType !== 1) return;
            // Skip if node is not connected to DOM yet
            if (!node.isConnected) return;

            // if node itself is a form
            if (node.matches && node.matches('form[data-form]')) {
              // avoid double init
              if (!this.state.elementIndex.has(node)) this.initForm(node);
            } else if (node.querySelector) {
              const found = node.querySelectorAll('form[data-form]');
              found.forEach(f => {
                // Skip if form is not connected
                if (f.isConnected && !this.state.elementIndex.has(f)) this.initForm(f);
              });
            }
          } catch (e) {
            console.warn('FormManager.process added error', e);
          }
        });
      }

      // auto-stop observer if nothing to manage
      if (this.state.forms.size === 0) {
        this._stopFormObserver();
      }
    };

    const observer = new MutationObserver((mutations) => {
      for (const m of mutations) {
        if (m.removedNodes && m.removedNodes.length) {
          for (const n of m.removedNodes) {
            if (!n || n.nodeType !== 1) continue;
            if (n.matches && n.matches('form[data-form]')) this._removedQueue.add(n);
            else if (n.querySelector) {
              const hit = n.querySelector('form[data-form]');
              if (hit) this._removedQueue.add(hit);
            }
          }
        }

        if (m.addedNodes && m.addedNodes.length) {
          for (const n of m.addedNodes) {
            if (!n || n.nodeType !== 1) continue;
            if (n.matches && n.matches('form[data-form]')) this._addedQueue.add(n);
            else if (n.querySelector) {
              const hit = n.querySelector('form[data-form]');
              if (hit) this._addedQueue.add(hit);
            }
          }
        }
      }

      if (this._processTimeout) return;
      const delay = this.observerConfig?.observerDelay || 40;
      this._processTimeout = setTimeout(() => {
        this._processTimeout = null;
        processQueues();
      }, delay);
    });

    // choose root - prefer a managed mount point if available, else fallback to body
    const root = this.observerConfig?.observeRoot || document.body;
    observer.observe(root, {childList: true, subtree: true});

    this._formObserver = observer;
  },

  _stopFormObserver() {
    try {
      if (!this._formObserver) return;
      this._formObserver.disconnect();
      this._formObserver = null;
      this._addedQueue && this._addedQueue.clear();
      this._removedQueue && this._removedQueue.clear();
      if (this._processTimeout) {
        clearTimeout(this._processTimeout);
        this._processTimeout = null;
      }
    } catch (e) {
      console.warn('FormManager._stopFormObserver error', e);
    }
  },

  getInstance(id) {
    return this.state.forms.get(id);
  },

  getInstanceByElement(form) {
    for (const [id, instance] of this.state.forms.entries()) {
      if (instance.element === form) {
        return instance;
      }
    }

    if (form.dataset && form.dataset.form) {
      return this.state.forms.get(form.dataset.form);
    }

    return null;
  },

  emitEvent(eventName, data) {
    EventManager.emit(eventName, data);
  },

  destroy(formId) {
    const instance = this.state.forms.get(formId);
    if (!instance) return;

    instance.element.removeEventListener('submit', instance.handlers?.submit);
    instance.element.removeEventListener('reset', instance.handlers?.reset);
    instance.element.removeEventListener('change', instance.handlers?.change);
    instance.element.removeEventListener('input', instance.handlers?.input);
    instance.element.removeEventListener('blur', instance.handlers?.blur, true);

    clearTimeout(instance.resetTimeout);

    this.state.forms.delete(formId);

    this.emitEvent('form:destroy', {formId});
  },

  reset() {
    this.state.forms.forEach((instance, id) => {
      this.resetForm(instance);
    });
  },

  cleanup() {
    for (const [id] of this.state.forms) {
      this.destroy(id);
    }

    this.state.forms.clear();
    this.state.validators.clear();
    this.state.initialized = false;
  }
};

if (window.Now?.registerManager) {
  Now.registerManager('form', FormManager);
}

window.FormManager = FormManager;
