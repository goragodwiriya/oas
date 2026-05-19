/**
 * Now.js - Modern JavaScript Framework
 * Version 1.0.0
 *
 * Features:
 * - Integrated Authentication & Routing
 * - Component Management
 * - State Management
 * - Performance Monitoring
 * - Developer Tools
 * - Plugin System
 */
const Now = {
  version: '1.0.0',
  managers: new Map(),
  plugins: new Map(),

  paths: {
    framework: '/Now',
    application: '/',
    components: '/js/components',
    plugins: '/plugins',
    templates: '/templates',
    translations: '/translations'
  },

  resources: {
    core: [
      '/js/Utils.js',
      '/js/NotificationManager.js',
      '/js/ErrorManager.js',
      '/js/ErrorRenderer.js',
      '/js/I18nManager.js',
      '/js/BackdropManager.js',
      '/js/Modal.js',
      '/js/ModalDataBinder.js',
      '/js/EventManager.js',
      '/js/EventSystemManager.js',
      '/js/AppConfigManager.js',
      '/js/StateManager.js',
      '/js/ScrollManager.js',
      '/js/DialogManager.js',
      '/js/TemplateManager.js',
      '/js/ReactiveHelpers.js',
      '/js/ComponentManager.js',
      '/js/ReactiveManager.js',
      '/js/ExpressionEvaluator.js',
      '/js/WatchManager.js',
      '/js/StorageManager.js',
      '/js/SyncManager.js',
      '/js/TokenService.js',
      '/js/AuthManager.js',
      '/js/RouterManager.js',
      '/js/SecurityConfig.js',
      '/js/SecurityManager.js',
      '/js/ResponseHandler.js',
      '/js/HttpClient.js',
      '/js/ApiService.js',
      '/js/ApiComponent.js',
      '/js/ElementManager.js',
      '/js/Autocomplete.js',
      '/js/FormError.js',
      '/js/FormManager.js',
      '/js/ElementFactory.js',
      '/js/DropdownPanel.js',
      '/js/TextElementFactory.js',
      '/js/MaskElementFactory.js',
      '/js/NumberElementFactory.js',
      '/js/SelectElementFactory.js',
      '/js/CascadingSelectManager.js',
      '/js/TextareaElementFactory.js',
      '/js/FileElementFactory.js',
      '/js/SearchElementFactory.js',
      '/js/DateElementFactory.js',
      '/js/ColorElementFactory.js',
      '/js/TagsElementFactory.js',
      '/js/MultiSelectElementFactory.js',
      '/js/RangeElementFactory.js',
      '/js/PasswordElementFactory.js',
      '/js/CalendarManager.js',
      '/js/FilterManager.js',
      '/js/MenuManager.js',
      '/js/Sortable.js',
      '/js/AnimationManager.js',
      '/js/MediaViewer.js'
    ],
    components: [],
    plugins: []
  },

  /**
   * Enhanced configuration with auth and router defaults
   */
  DEFAULT_CONFIG: {
    debug: false,
    environment: 'development',
    strictMode: true,
    mainSelector: '#main, [role="main"]',

    auth: {
      enabled: false,  // ปิดเป็นค่าเริ่มต้น - เปิดเมื่อต้องการใช้งานจริง
      type: 'jwt-httponly',
      autoInit: false,
      endpoints: {
        login: 'api/auth/login',
        logout: 'api/auth/logout',
        verify: 'api/auth/verify',
        refresh: 'api/auth/refresh'
      },

      redirects: {
        afterLogin: '/',
        afterLogout: '/login',
        unauthorized: '/login',
        forbidden: '/403'
      }
    },

    router: {
      enabled: false,
      mode: 'history',
      base: '/',
      auth: {
        enabled: false,  // ปิดเป็นค่าเริ่มต้น
        autoGuard: false,
        defaultRequireAuth: false,
        publicPaths: ['/login', '/register', '/forgot-password'],
        guestOnlyPaths: ['/login', '/register'],
        conventions: {
          '/auth/*': {public: true},
          '/admin/*': {roles: ['admin']},
          '/api/*': {skipRouter: true}
        }
      },
      errorHandling: {
        unauthorized: {
          action: 'redirect',
          target: '/login',
          preservePath: true,
          message: 'Please login to continue'
        },
        forbidden: {
          action: 'render',
          template: '/errors/403.html',
          notify: true
        },
        notFound: {
          action: 'render',
          template: '/errors/404.html',
          title: 'Page Not Found'
        }
      }
    },

    component: {
      reactive: false,
      autoRender: true,
      batchUpdates: true,
      batchDelay: 16,
      devTools: {
        enabled: true,
        logStateChanges: true
      }
    },

    stateManager: {
      persistent: false,
      storage: 'localStorage',
      devTools: true
    },

    security: {
      csrf: {
        enabled: false,  // ปิดเป็นค่าเริ่มต้น - เปิดเมื่อต้องการใช้งาน form submission
        tokenName: '_token',
        headerName: 'X-CSRF-Token'
      },
      xss: {
        enabled: false   // ปิดเป็นค่าเริ่มต้น
      },
      csp: {
        enabled: false   // ปิดเป็นค่าเริ่มต้น
      },
      validateInput: {
        enabled: false   // ปิดเป็นค่าเริ่มต้น
      },
      sanitizeOutput: {
        enabled: false   // ปิดเป็นค่าเริ่มต้น
      }
    },

    devTools: {
      enabled: false,
      performance: true,
      logger: true,
      inspector: true
    },

    performance: {
      monitoring: false,
      metrics: true,
      warnings: true,
      errorReporting: true
    },

    i18n: {
      enabled: false,
      defaultLocale: 'en',
      availableLocales: ['en', 'th'],
      fallbackLocale: 'en'
    },

    serviceWorker: {
      enabled: false,
      debug: false,
      serviceWorkerPath: '/service-worker.js',
      scope: '/'
    },

    http: {
      baseURL: '',
      timeout: 30000,
      retries: 3,
      retryDelay: 1000
    },

    storage: {
      enabled: true,
      type: 'indexeddb',
      name: 'now_app_storage'
    },

    app: {
      name: 'Now.js Application',
      version: '1.0.0',
      description: '',
      author: '',
      homepage: ''
    }
  },

  state: {
    initialized: false,
    loading: false,
    error: null,
    loadedResources: new Set(),
    activeApp: null,
    performance: {
      startTime: null,
      metrics: new Map()
    }
  },

  managers: new Map(),

  async init(options = {}) {
    try {
      this.state.loading = true;

      if (!this.state.loadedResources) {
        this.state.loadedResources = new Set();
      }

      if (!this.state.performance) {
        this.state.performance = {
          startTime: null,
          metrics: new Map()
        };
      }

      this.setupErrorHandling();

      this.config = this.mergeConfig(this.DEFAULT_CONFIG, options);

      if (options.paths) {
        this.paths = {...this.paths, ...options.paths};
      }

      // Auto-detect base path before loading any resources
      const detectedBasePath = await this.detectBasePath();
      // Normalize and store base path for managers to consume
      try {
        let base = detectedBasePath || '';
        if (!base) base = '/';
        if (!base.startsWith('/')) base = '/' + base;
        if (base.length > 1 && base.endsWith('/')) base = base.slice(0, -1);
        this.basePath = base;
        // Mirror into router config if not explicitly provided
        if (!this.config.router) this.config.router = {};
        if (!this.config.router.base || this.config.router.base === '/') {
          this.config.router.base = base;
        }
      } catch (e) {
        console.warn('[Now.js] Failed to normalize detected base path', e);
      }

      if (this.config.environment === 'development') {
        this.setupDevTools();
      }

      // Check if we should use bundle or individual files
      const useBundle = this.config.environment === 'production';

      if (!useBundle) {
        await this.loadResources(this.resources.core, 'framework');
      }

      await this.initializeManagers();

      if (options.resources?.plugins?.length > 0) {
        await this.loadResources(options.resources.plugins, 'plugins');
        await this.initializePlugins(options.resources.plugins);
      }

      if (options.resources?.components?.length > 0) {
        await this.loadResources(options.resources.components, 'components');
        await this.initializeComponents(options.resources.components);
      }

      this.state.initialized = true;

      if (this.config.auth?.enabled) {
        //await this.setupAuth(this.config.auth);
      }

      if (this.config.router?.enabled) {
        //await this.setupRouterWithAuth(this.config.router);
      }

      if (this.config.authTemplate?.enabled || (this.config.auth?.enabled && this.config.template?.auth)) {
        const AuthTemplateManager = window.AuthTemplateManager;
        if (AuthTemplateManager) {
          await AuthTemplateManager.init(this.config.authTemplate || this.config.template?.auth || {});
          this.registerManager('authTemplate', AuthTemplateManager);
        }
      }

      this.state.loading = false;

      this.endPerformanceTracking('initialization');

      return this;

    } catch (error) {
      this.state.loading = false;
      this.handleError(error);
      throw error;
    }
  },

  async createApp(config = {}) {
    if (!this.state.initialized) {
      throw new Error('Framework must be initialized first');
    }

    try {
      this.startPerformanceTracking('app-creation');

      const app = {
        config: this.mergeConfig(this.config.app, config),
        framework: this,
        managers: this.managers,
        plugins: this.plugins,
        state: {},
        mount: async (el) => this.mountApp(app, el)
      };

      if (config.state && this.managers.has('state')) {
        app.state = await this.managers.get('state').init(config.state);
      }

      if (config.i18n && this.managers.has('i18n')) {
        await this.managers.get('i18n').init(config.i18n);
      }

      if (config.plugins) {
        await this.initializeAppPlugins(app, config.plugins);
      }

      this.endPerformanceTracking('app-creation');
      this.state.activeApp = app;

      return app;

    } catch (error) {
      this.handleError(error);
      throw error;
    }
  },

  /**
   * Route Registration Helpers
   */

  publicRoute(path, config) {
    if (!RouterManager.state.initialized) {
      throw new Error('RouterManager must be initialized first');
    }

    return RouterManager.register(path, {
      ...config,
      requireAuth: false,
      isPublic: true
    });
  },

  protectedRoute(path, config) {
    if (!RouterManager.state.initialized) {
      throw new Error('RouterManager must be initialized first');
    }

    return RouterManager.register(path, {
      ...config,
      requireAuth: true
    });
  },

  adminRoute(path, config) {
    if (!RouterManager.state.initialized) {
      throw new Error('RouterManager must be initialized first');
    }

    const AuthGuards = window.AuthGuards;
    return RouterManager.register(path, {
      ...config,
      requireAuth: true,
      roles: ['admin'],
      beforeEnter: AuthGuards ? AuthGuards.adminOnly() : null
    });
  },

  roleRoute(path, roles, config) {
    if (!RouterManager.state.initialized) {
      throw new Error('RouterManager must be initialized first');
    }

    const AuthGuards = window.AuthGuards;
    return RouterManager.register(path, {
      ...config,
      requireAuth: true,
      roles: Array.isArray(roles) ? roles : [roles],
      beforeEnter: AuthGuards ? AuthGuards.requireRole(roles) : null
    });
  },

  guestRoute(path, config) {
    if (!RouterManager.state.initialized) {
      throw new Error('RouterManager must be initialized first');
    }

    const AuthGuards = window.AuthGuards;
    return RouterManager.register(path, {
      ...config,
      requireGuest: true,
      beforeEnter: AuthGuards ? AuthGuards.requireGuest() : null
    });
  },

  /**
   * Template Auth Helpers
   */
  updateAuthTemplates(container = document.body) {
    const authTemplateManager = this.getManager('authTemplate');
    if (authTemplateManager) {
      authTemplateManager.processContainer(container);
    }
  },

  async refreshAuthTemplates() {
    const authTemplateManager = this.getManager('authTemplate');
    if (authTemplateManager) {
      await authTemplateManager.forceUpdate();
    }
  },

  translate(key, params = {}) {
    const i18n = this.getManager('i18n');
    return i18n ? i18n.translate(key, params) : key;
  },

  getLocale() {
    const i18n = this.getManager('i18n');
    if (i18n && typeof i18n.getCurrentLocale === 'function') {
      const locale = i18n.getCurrentLocale();
      if (typeof locale === 'string' && locale.trim() !== '') {
        return locale.trim();
      }

      const defaultLocale = i18n.config?.defaultLocale;
      if (typeof defaultLocale === 'string' && defaultLocale.trim() !== '') {
        return defaultLocale.trim();
      }
    }

    if (typeof document !== 'undefined') {
      const htmlLang = document.documentElement?.getAttribute('lang');
      if (typeof htmlLang === 'string' && htmlLang.trim() !== '') {
        return htmlLang.trim();
      }
    }

    return 'en';
  },

  getRequestLocale() {
    const locale = this.getLocale();
    if (typeof locale === 'string' && locale.trim() !== '') {
      return locale.trim();
    }

    return 'en';
  },

  withRequestLanguage(headers = {}) {
    if (typeof Headers !== 'undefined' && headers instanceof Headers) {
      if (!headers.has('Accept-Language')) {
        headers.set('Accept-Language', this.getRequestLocale());
      }
      return headers;
    }

    const requestHeaders = headers && typeof headers === 'object' && !Array.isArray(headers) ? {...headers} : {};
    const hasLocaleHeader = Object.keys(requestHeaders).some((name) => name.toLowerCase() === 'accept-language');

    if (!hasLocaleHeader) {
      requestHeaders['Accept-Language'] = this.getRequestLocale();
    }

    return requestHeaders;
  },

  applyRequestLanguage(options = {}) {
    if (!options || typeof options !== 'object' || Array.isArray(options)) {
      return options;
    }

    return {
      ...options,
      headers: this.withRequestLanguage(options.headers)
    };
  },

  applyRequestLanguageToXhr(xhr) {
    if (xhr && typeof xhr.setRequestHeader === 'function') {
      xhr.setRequestHeader('Accept-Language', this.getRequestLocale());
    }

    return xhr;
  },

  /**
   * Wait for a global object (window[name]) to become available.
   * Useful when scripts are loaded asynchronously and order is not guaranteed.
   *
   * @param {string}   name               - The global variable name, e.g. 'Cart', 'Carousel'
   * @param {object}   [options]           - Optional settings
   * @param {Function} [options.validator] - Extra check, receives the object, must return truthy
   *                                         e.g. obj => typeof obj.init === 'function'
   * @param {number}   [options.timeout=5000]  - Max wait time in ms
   * @param {number}   [options.interval=50]   - Polling interval in ms
   * @returns {Promise} Resolves with window[name], rejects on timeout
   *
   * @example
   *   const Cart = await Now.waitFor('Cart');
   *   const Carousel = await Now.waitFor('Carousel', { validator: o => typeof o.init === 'function' });
   */
  waitFor(name, options = {}) {
    const {validator, timeout = 5000, interval = 50} = options;
    return new Promise((resolve, reject) => {
      // Check immediately first
      const obj = window[name];
      if (obj && (!validator || validator(obj))) {
        return resolve(obj);
      }
      const start = Date.now();
      const timer = setInterval(() => {
        const obj = window[name];
        if (obj && (!validator || validator(obj))) {
          clearInterval(timer);
          return resolve(obj);
        }
        if (Date.now() - start >= timeout) {
          clearInterval(timer);
          return reject(new Error(`Now.waitFor: "${name}" not available after ${timeout}ms`));
        }
      }, interval);
    });
  },

  emit(event, data = {}) {
    const eventManager = this.getManager('event');
    if (eventManager) {
      return eventManager.emit(event, {
        ...data,
        timestamp: Date.now()
      });
    }
  },

  async mountApp(app, el) {
    try {
      this.startPerformanceTracking('app-mount');

      const mountEl = typeof el === 'string' ?
        document.querySelector(el) : el;

      if (!mountEl) {
        throw new Error('Mount element not found');
      }

      mountEl.innerHTML = '';

      if (this.managers.has('component')) {
        await this.managers.get('component').init(mountEl);
      }

      if (this.managers.has('event')) {
        this.managers.get('event').emit('app:mounted', {app});
      }

      this.endPerformanceTracking('app-mount');

    } catch (error) {
      this.handleError(error);
      throw error;
    }
  },

  use(plugin, config = {}) {
    if (!plugin.name) {
      throw new Error('Plugin must have a name');
    }

    if (this.plugins.has(plugin.name)) {
      return this;
    }

    this.plugins.set(plugin.name, {
      instance: plugin,
      config
    });

    return this;
  },

  /**
   * Register Manager
   */
  registerManager(name, manager) {
    if (this.managers.has(name)) {
      return this;
    }

    this.managers.set(name, manager);
    return this;
  },

  async initializeComponents(components) {
    const componentManager = this.getManager('component');

    if (!componentManager) {
      return;
    }

    for (const component of components) {
      try {
        // Extract filename without extension
        const filename = component.split('/').pop().replace('.js', '');

        // Convert to lowercase and remove 'Component' suffix to match registration name
        const registrationName = filename
          .replace(/Component$/, '')
          .toLowerCase();

        let retries = 0;
        const maxRetries = 5;

        while (retries < maxRetries) {
          // Check if component is registered using the lowercase name
          if (componentManager.has(registrationName)) {
            break;
          }
          await new Promise(resolve => setTimeout(resolve, 100));
          retries++;
        }

        if (retries === maxRetries) {
          const errorMsg =
            `❌ Component initialization failed!\n` +
            `\n` +
            `Expected component name: "${registrationName}"\n` +
            `From file: ${filename}.js\n` +
            `Full path: ${component}\n` +
            `\n` +
            `The component was not registered within ${maxRetries * 100}ms.\n` +
            `\n` +
            `Common causes:\n` +
            `1. Component name mismatch - Check that the component registers as "${registrationName}"\n` +
            `   Example: Now.getManager('component').define('${registrationName}', {...})\n` +
            `\n` +
            `2. Component name conflict - Another component may already use this name\n` +
            `   Check console for "Component name conflict" warnings\n` +
            `\n` +
            `3. JavaScript error in component file - Check browser console for errors\n` +
            `\n` +
            `4. File not loaded - Verify the file path is correct\n` +
            `\n` +
            `Registered components: ${Array.from(componentManager.components.keys()).join(', ') || 'none'}`;

          if (this.config.strictMode) {
            throw new Error(errorMsg);
          } else {
            console.error(errorMsg);
          }
        }

      } catch (error) {
        if (this.config.strictMode) {
          throw error;
        } else {
          console.error('Component initialization error:', error);
        }
      }
    }
  },

  async loadResources(resources, type) {
    if (!resources || resources.length === 0) return;

    for (const resource of resources) {
      if (this.state.loadedResources.has(resource)) continue;

      try {
        const path = this.resolvePath(resource, type);

        await this.loadResource(path);

        this.state.loadedResources.add(resource);

        const match = path.match(/(([^\/]+)Manager).js$/);
        if (match) {
          const manager = window[match[1]];
          if (manager) {
            this.managers.set(match[2].toLowerCase(), manager);
          }
        }
      } catch (error) {
        if (this.config.strictMode) {
          throw error;
        }
      }
    }
  },

  async loadResource(url) {
    const startTime = performance.now();

    try {
      const ext = url.split('.').pop().toLowerCase();
      let element;

      switch (ext) {
        case 'css':
          element = document.createElement('link');
          element.rel = 'stylesheet';
          element.href = url;
          break;

        case 'js':
          element = document.createElement('script');
          element.src = url;
          element.async = true;
          break;

        default:
          throw new Error(`Unsupported resource type: ${ext}`);
      }

      await new Promise((resolve, reject) => {
        element.onload = resolve;
        element.onerror = () => reject(new Error(`Failed to load: ${url}`));
        document.head.appendChild(element);
      });

      const endTime = performance.now();
      this.recordPerformance('resource-load', {
        url,
        duration: endTime - startTime,
        type: ext
      });

      return true;

    } catch (error) {
      const failed = document.querySelector(`[src="${url}"], [href="${url}"]`);
      if (failed) {
        failed.remove();
      }

      this.recordPerformance('resource-error', {
        url,
        error: error.message
      });

      throw error;
    }
  },

  resolvePath(resource, type = 'framework') {
    // Convert to string and handle empty/null
    resource = String(resource || '');

    // 1. Return absolute URLs as-is (http, https, protocol-relative)
    if (/^(?:https?:)?\/\//i.test(resource)) {
      return resource;
    }

    // 2. Allow special schemes to pass through unchanged
    if (/^(?:data:|blob:|mailto:|tel:|javascript:)/i.test(resource)) {
      // Note: javascript: is included but should be blocked by CSP in production
      return resource;
    }

    // === SECURITY HARDENING ===

    // 3. Decode URI components to catch encoded attacks (%2e%2e%2f = ../)
    try {
      // Decode multiple times to handle double-encoding attacks
      let decoded = resource;
      let prevDecoded = '';
      let iterations = 0;
      const maxIterations = 3; // Prevent infinite loops

      while (decoded !== prevDecoded && iterations < maxIterations) {
        prevDecoded = decoded;
        try {
          decoded = decodeURIComponent(decoded);
        } catch (e) {
          break; // Invalid encoding, stop decoding
        }
        iterations++;
      }
      resource = decoded;
    } catch (e) {
      // Invalid encoding, continue with original
    }

    // 4. Remove null bytes (potential injection attack)
    resource = resource.replace(/\0/g, '');

    // 5. Normalize backslashes to forward slashes (Windows path bypass)
    resource = resource.replace(/\\/g, '/');

    // 6. Remove ALL path traversal sequences (../ or ..\) anywhere in the string
    // Loop to handle nested cases like ....//
    let prevResource = '';
    while (resource !== prevResource) {
      prevResource = resource;
      resource = resource.replace(/\.\.+[\/\\]?/g, '');
    }

    // 7. Remove leading ./, ../, / sequences
    resource = resource.replace(/^(?:\.\.\/|\.\/|\/)+/, '');

    // 8. Final security check - block if still contains suspicious patterns
    if (/\.\.|[<>"|*?]/.test(resource)) {
      console.warn('[Now.js Security] Blocked suspicious path:', resource);
      return '/';
    }

    // === PATH BUILDING ===

    const pathMap = {
      framework: this.paths.framework,
      application: this.paths.application,
      components: this.paths.components,
      plugins: this.paths.plugins,
      templates: this.paths.templates,
      translations: this.paths.translations
    };

    const typeBase = String(pathMap[type] || '');

    // If typeBase is an absolute URL, return URL + resource directly
    if (/^(?:https?:)?\/\//i.test(typeBase)) {
      // Clean resource path (no leading slash for URL concatenation)
      const cleanResource = resource.replace(/^\/+/, '');
      // Ensure typeBase has no trailing slash, then add resource
      const baseUrl = typeBase.replace(/\/+$/, '');
      return cleanResource ? `${baseUrl}/${cleanResource}` : baseUrl;
    }

    // Build parts: [basePath (if not '/'), typeBase (if not '/'), resource]
    const parts = [];

    // canonical basePath (Now.basePath) - may be '/'
    let base = String(this.basePath || '').trim();
    if (base && base !== '/') {
      base = base.replace(/^\/+|\/+$/g, '');
      if (base) parts.push(base);
    }

    // canonical type base (from this.paths)
    if (typeBase && typeBase !== '/') {
      const tb = typeBase.replace(/^\/+|\/+$/g, '');
      if (tb) parts.push(tb);
    }

    // resource (no leading slash)
    const res = resource.replace(/^\/+/, '');
    if (res) parts.push(res);

    // join with single slashes; ensure leading slash
    return '/' + parts.join('/');
  },

  /**
   * Get the current base path
   * @returns {string} The base path (e.g., '/nowjs' or '/')
   */
  getBasePath() {
    return this.basePath || '/';
  },

  /**
   * Resolve a URL relative to the application base path
   * @param {string} url - The URL to resolve (can be relative or absolute)
   * @returns {string} The resolved URL with base path applied
   */
  resolveUrl(url) {
    if (!url) return this.getBasePath();

    const u = String(url).trim();

    // Return absolute URLs as-is
    if (/^(https?:)?\/\//i.test(u)) return u;

    // Return root-absolute paths as-is (explicit absolute)
    if (u.startsWith('/')) return u;

    // For relative URLs, prepend base path
    const base = this.getBasePath();
    if (base === '/') {
      return '/' + u;
    }
    return base + '/' + u;
  },

  /**
   * Build a URL with base path applied
   * @param {...string} parts - URL parts to join
   * @returns {string} The complete URL with base path
   */
  buildUrl(...parts) {
    const cleanParts = parts
      .filter(part => part != null && part !== '')
      .map(part => String(part).replace(/^\/+|\/+$/g, ''))
      .filter(part => part !== '');

    const base = this.getBasePath();
    const allParts = [];

    if (base && base !== '/') {
      allParts.push(base.replace(/^\/+|\/+$/g, ''));
    }

    allParts.push(...cleanParts);

    return '/' + allParts.join('/');
  },

  /**
   * Get the current full path including base path (without trailing slash)
   * @returns {string} The current path including base path (e.g., '/nowjs/examples/playground')
   */
  getCurrentPath() {
    const path = window.location.pathname || '/';
    // Remove trailing slash except for root path
    return path === '/' ? '/' : path.replace(/\/+$/, '');
  },

  /**
   * Get the current directory path including base path with trailing slash
   * @returns {string} The current directory path (e.g., '/nowjs/examples/playground/')
   */
  getCurrentDir() {
    const path = this.getCurrentPath();
    return path.endsWith('/') ? path : path + '/';
  },

  /**
   * Get the current relative path (removes base path from current location)
   * @returns {string} The relative path from base (e.g., '/examples/playground/' if base is '/nowjs')
   */
  getCurrentRelativePath() {
    const currentPath = this.getCurrentPath();
    const basePath = this.getBasePath();

    if (basePath === '/' || !currentPath.startsWith(basePath)) {
      return currentPath;
    }

    const relativePath = currentPath.substring(basePath.length);
    return relativePath.startsWith('/') ? relativePath : '/' + relativePath;
  },

  async detectBasePath() {
    // Detect framework path from the current Now.js script tag
    const scripts = document.getElementsByTagName('script');
    const nowScript = Array.from(scripts).find(script =>
      script.src && (script.src.includes('Now.js') || script.src.includes('now.core'))
    );

    let detectedBasePath = '';

    if (nowScript) {
      const scriptSrc = nowScript.src;

      const url = new URL(scriptSrc);
      const pathname = url.pathname;

      if (pathname.endsWith('/Now/Now.js')) {
        const basePathWithTrailing = pathname.replace('/Now/Now.js', '');
        detectedBasePath = basePathWithTrailing || '';
      }
    }

    // Note: We no longer modify this.paths here since resolvePath() handles base path combination
    return detectedBasePath;
  },

  /**
   * Check if the bundled core file is available
   * @returns {Promise<boolean>}
   */
  async isBundleAvailable() {
    try {
      const bundlePath = this.resolvePath('/dist/now.core.min.js', 'framework');
      const response = await fetch(bundlePath, {method: 'HEAD'});
      return response.ok;
    } catch (error) {
      return false;
    }
  },


  async initializeManagers() {
    for (const [name, manager] of this.managers) {
      if (typeof manager.init === 'function') {
        try {
          await manager.init(this.config[name]);
        } catch (error) {
          if (this.config.strictMode) {
            throw error;
          }
        }
      }
    }
  },

  async initializePlugins(plugins) {
    for (const plugin of plugins) {
      try {
        const match = plugin.match(/(([^\/]+)Plugin).js$/);
        if (match) {
          const instance = window[match[1]];
          if (typeof instance?.init === 'function') {
            const config = this.config[match[2].toLowerCase()];
            await instance.init(this, config);
          }
        }
      } catch (error) {
        if (this.config.strictMode) {
          throw error;
        }
        console.warn(`Failed to initialize plugin ${plugin}:`, error);
      }
    }
  },

  async initializeAppPlugins(app, plugins) {
    for (const plugin of plugins) {
      try {
        if (typeof plugin.init === 'function') {
          await plugin.init(app);
        }
      } catch (error) {
        if (this.config.strictMode) {
          throw error;
        }
        console.warn('Failed to initialize app plugin:', error);
      }
    }
  },

  handleEvent(method, element) {
    const componentId = element.closest('[data-component]')?.dataset.componentId;
    if (!componentId) return;

    const componentManager = this.getManager('component');
    if (componentManager) {
      const instance = componentManager.instances.get(componentId);
      if (instance?.methods[method]) {
        instance.methods[method].call(instance);
      }
    }
  },

  setupErrorHandling() {
    window.onerror = (message, source, line, column, error) => {
      // Skip ResizeObserver loop warnings (benign browser warning)
      if (message && message.includes && message.includes('ResizeObserver')) {
        console.warn('ResizeObserver loop warning:', message);
        return true; // Prevent default handling
      }

      this.handleError(error || new Error(message), {
        source, line, column
      });
    };

    window.onunhandledrejection = (event) => {
      // Skip ResizeObserver related rejections
      const reason = event.reason?.message || String(event.reason || '');
      if (reason.includes('ResizeObserver')) {
        console.warn('ResizeObserver rejection:', reason);
        return;
      }

      this.handleError(event.reason, {
        type: 'unhandledrejection'
      });
    };
  },

  handleError(error, context = {}) {
    this.state.error = error;
    this.state.loading = false;

    const errorData = {
      message: error.message,
      stack: error.stack,
      timestamp: Date.now(),
      ...context
    };

    if (this.config.debug) {
      console.error('Framework Error:', errorData);
    }

    try {
      const notification = this.getManager('notification');
      if (notification) {
        notification.error(error.message || 'An error occurred');
      }
    } catch (notificationError) {
      console.error('Failed to show error notification:', notificationError);
    }

    try {
      const eventManager = this.getManager('event');
      if (eventManager) {
        eventManager.emit('error', {error, context});
      }
    } catch (eventError) {
      console.error('Failed to emit error event:', eventError);
    }

    if (this.config.performance?.errorReporting &&
      this.state.performance?.metrics) {
      this.logErrorMetrics(error, context);
    }
  },

  setupDevTools() {
    if (!this.config.devTools.enabled) return;

    window.__NOW_DEV__ = {
      framework: this,
      inspect: () => this.inspect(),
      getManagers: () => Array.from(this.managers.keys()),
      getPlugins: () => Array.from(this.plugins.keys()),
      getResources: () => Array.from(this.state.loadedResources),
      getConfig: () => this.config,
      getState: () => this.state,
      getPerformance: () => this.getPerformanceMetrics(),
      reload: () => window.location.reload(),
      clearCache: () => {
        this.state.loadedResources.clear();
        this.state.performance.metrics.clear();
      }
    };

    console.log('Now.js Dev Tools available at window.__NOW_DEV__');
  },

  startPerformanceTracking(label = 'general') {
    if (!this.config.performance.monitoring) return;

    const metric = {
      start: performance.now(),
      end: null,
      duration: null
    };

    this.state.performance.metrics.set(label, metric);
  },

  endPerformanceTracking(label = 'general') {
    if (!this.config.performance.monitoring) return;

    const metric = this.state.performance.metrics.get(label);
    if (metric) {
      metric.end = performance.now();
      metric.duration = metric.end - metric.start;

      this.recordPerformance(label, {
        duration: metric.duration,
        start: metric.start,
        end: metric.end
      });
    }
  },

  recordPerformance(name, data) {
    if (!this.config.performance.monitoring) return;

    const metric = {
      name,
      timestamp: Date.now(),
      ...data
    };

    this.state.performance.metrics.set(`${name}_${Date.now()}`, metric);

    const event = this.getManager('event');
    if (event) {
      event.emit('performance:recorded', metric);
    }

    if (this.config.performance.warnings) {
      this.checkPerformanceWarning(metric);
    }
  },

  checkPerformanceWarning(metric) {
    const thresholds = {
      'resource-load': 2000,
      'initialization': 5000,
      'app-mount': 1000,
      'component-render': 100,
      'app-creation': 500
    };

    const threshold = thresholds[metric.name];
    if (threshold && metric.duration > threshold) {
      const warning = `Performance warning: ${metric.name} took ${metric.duration.toFixed(2)}ms (threshold: ${threshold}ms)`;

      if (this.config.debug) {
        console.warn(warning);
      }

      const notification = this.getManager('notification');
      if (notification && this.config.devTools.enabled) {
        notification.warning(warning, {duration: 3000});
      }
    }
  },

  getPerformanceMetrics() {
    const metrics = {};
    this.state.performance.metrics.forEach((metric, label) => {
      metrics[label] = {
        duration: metric.duration,
        timestamp: metric.start || metric.timestamp
      };
    });
    return metrics;
  },

  inspect() {
    return {
      version: this.version,
      state: {
        initialized: this.state.initialized,
        loading: this.state.loading,
        error: this.state.error,
        resources: Array.from(this.state.loadedResources),
        performance: this.getPerformanceMetrics()
      },
      config: this.config,
      managers: Array.from(this.managers.keys()),
      plugins: Array.from(this.plugins.keys()),
      activeApp: this.state.activeApp ? {
        name: this.state.activeApp.config.name,
        version: this.state.activeApp.config.version,
        plugins: Object.keys(this.state.activeApp.plugins || {})
      } : null
    };
  },

  logErrorMetrics(error, context = {}) {
    const metric = {
      timestamp: Date.now(),
      type: error.name,
      message: error.message,
      stack: error.stack,
      location: window.location.href,
      context
    };

    this.state.performance.metrics.set(`error_${Date.now()}`, metric);
  },

  mergeConfig(base, override) {
    const merged = {...base};

    for (const [key, value] of Object.entries(override)) {
      if (value && typeof value === 'object' && !Array.isArray(value)) {
        merged[key] = this.mergeConfig(base[key] || {}, value);
      } else {
        merged[key] = value;
      }
    }

    return merged;
  },

  /**
   * Get Manager
   */
  getManager(name) {
    return this.managers.get(name) || null;
  },

  getPlugin(name) {
    return this.plugins.get(name)?.instance || null;
  },

  async cleanup() {
    for (const [name, manager] of this.managers) {
      if (typeof manager.cleanup === 'function') {
        try {
          await manager.cleanup();
        } catch (error) {
          console.warn(`Failed to cleanup manager ${name}:`, error);
        }
      }
    }

    for (const [name, plugin] of this.plugins) {
      if (typeof plugin.instance?.cleanup === 'function') {
        try {
          await plugin.instance.cleanup();
        } catch (error) {
          console.warn(`Failed to cleanup plugin ${name}:`, error);
        }
      }
    }

    this.state.loadedResources.clear();
    this.state.performance.metrics.clear();
    this.state.activeApp = null;
    this.state.error = null;
  },

  async reset() {
    await this.cleanup();

    this.managers.clear();
    this.plugins.clear();
    this.state.initialized = false;
    this.state.loading = false;

    this.config = {...this.DEFAULT_CONFIG};
  }
};

window.Now = Now;
