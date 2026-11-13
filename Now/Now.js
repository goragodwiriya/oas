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
// The <script> this file runs from (now.core.min.js, or Now.js on its own): the
// feature bundles are loaded from its folder, with its ?v= query. Read now, as
// document.currentScript is only set while the script runs.
const NOW_CORE_SCRIPT = document.currentScript || null;

const Now = {
  version: '1.0.0',
  managers: new Map(),
  plugins: new Map(),
  // Functions directives (data-on, data-script, data-on-load, data-action) and
  // server actions may call by name — see registerFunction()/resolveFunction().
  // Two of the framework's own are registered here because apps call them by name
  // (a callback action that emits an event, data-on on a line-items input); they
  // take no HTML and change no configuration.
  functions: new Map([
    ['EventManager.emit', (...args) => window.EventManager.emit(...args)],
    ['LineItemsManager.recalculate', async (...args) => {
      if (!window.LineItemsManager) await Now.require('LineItemsManager');
      return window.LineItemsManager.recalculate(...args);
    }]
  ]),

  // The globals Now.js puts on window, read from its sources at build time
  // (vite.config.js). resolveFunction() never resolves a name starting at one.
  frameworkGlobals: Object.freeze(typeof __NOW_FRAMEWORK_GLOBALS__ !== 'undefined' ? __NOW_FRAMEWORK_GLOBALS__ : []),

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
      '/js/TokenService.js',
      '/js/RedirectManager.js',
      '/js/AuthManager.js',
      '/js/RouterManager.js',
      '/js/PageNavigator.js',
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
      '/js/FilterManager.js',
      '/js/MenuManager.js',
      '/js/AnimationManager.js',
      '/js/MediaViewer.js'
    ],
    components: [],
    plugins: []
  },

  /**
   * Feature bundles: parts of Now.js that are not in now.core.min.js. Each is
   * built into Now/dist as now.<name>.min.js (and now.<name>.min.css when it has
   * styles) from Now/features/<name>.js, and loaded the first time a page needs
   * it — see require().
   *
   * - globals:  what the bundle defines; require() takes these names as well
   * - selector: markup that loads it, on the page at start or added later
   * - elements: ElementManager types (data-element) whose factory it holds
   * - config:   the Now.init() section whose `enabled: true` loads it at init
   */
  features: {
    code: {js: 'now.code.min.js', css: 'now.code.min.css', globals: ['CodeEditor', 'CodeElementFactory'], elements: ['code']},
    richtext: {js: 'now.richtext.min.js', globals: ['RichTextElementFactory'], elements: ['richtext']},
    sync: {js: 'now.sync.min.js', globals: ['SyncManager'], config: 'sync'},
    editinplace: {js: 'now.editinplace.min.js', globals: ['EditInPlaceManager'], selector: '[data-component="editinplace"]'},
    taskmonitor: {js: 'now.taskmonitor.min.js', css: 'now.taskmonitor.min.css', globals: ['TaskMonitorComponent'], selector: '[data-component="task-monitor"]'},
    securityconfig: {js: 'now.securityconfig.min.js', globals: ['SecurityConfig']},
    calendar: {js: 'now.calendar.min.js', globals: ['CalendarManager'], selector: '[data-calendar]'},
    gallery: {js: 'now.gallery.min.js', css: 'now.gallery.min.css', globals: ['ImageGallery'], selector: '[data-component="image-gallery"]'},
    iconpicker: {js: 'now.iconpicker.min.js', globals: ['IconPicker'], selector: '[data-component="icon-picker"]'},
    tabs: {js: 'now.tabs.min.js', globals: ['TabsComponent'], selector: '[data-component="tabs"]'},
    lineitems: {js: 'now.lineitems.min.js', globals: ['LineItemsManager'], selector: '[data-line-items]'},
    sortable: {js: 'now.sortable.min.js', globals: ['Sortable'], selector: '[data-component="sortable"]'},
    imagecrop: {js: 'now.imagecrop.min.js', css: 'now.imagecrop.min.css', globals: ['ImageCropper'], selector: '[data-crop="true"]'}
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
      errorReporting: true,
      // Entries kept in state.performance.metrics; the oldest go first
      maxMetrics: 200
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

      // A feature bundle turned on in the config (sync.enabled) is loaded first,
      // so its manager starts with the others
      await this.requireConfiguredFeatures();

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
   * Registers a function that templates (data-on, data-script, data-on-load,
   * data-action) and server actions may call by name. A registered name is
   * found before anything on window.
   *
   * @param {string} name - Name used in the directive, e.g. 'initOrderPage' or 'orders.reload'
   * @param {Function} fn - The function
   * @returns {Object} Now
   */
  registerFunction(name, fn) {
    if (typeof name !== 'string' || !name.trim() || typeof fn !== 'function') {
      throw new TypeError('Now.registerFunction(name, fn) needs a name and a function');
    }
    this.functions.set(name.trim(), fn);
    return this;
  },

  /**
   * Removes a function registered with registerFunction().
   *
   * @param {string} name - Registered name
   * @returns {boolean} True when it was registered
   */
  unregisterFunction(name) {
    return this.functions.delete(String(name || '').trim());
  },

  /**
   * Finds the function a directive or a server action names.
   *
   * A registered function (registerFunction) comes first. Otherwise the name is
   * looked up from window as a dotted path ('initPage', 'Cart.clear'),
   * with these limits so that a name can never reach code it should not:
   * - every segment is a plain identifier and an own property of the object
   *   before it (nothing inherited, no brackets, no constructor/prototype/__proto__)
   * - the path does not start at window, self, globalThis, top, parent, frames,
   *   opener or document
   * - the path does not start at one of the framework's globals (frameworkGlobals:
   *   Now, ResponseHandler, SecurityManager, http, TableManager, ...) and does not
   *   pass through Now or a manager Now has registered, and the function is not
   *   one of their methods under another name (App.init = SecurityManager.init) — an app that wants a
   *   response or a template to call one of them registers it with registerFunction()
   *   (EventManager.emit and LineItemsManager.recalculate are registered already)
   * - the function is not built in (native), not a class, and a one-segment name
   *   is not one of eval, Function, setTimeout, setInterval, setImmediate,
   *   requestAnimationFrame, queueMicrotask, execScript, open, alert, confirm,
   *   prompt, fetch, importScripts, postMessage
   *
   * @param {string} path - Function name or dotted path
   * @returns {{fn: Function, thisArg: *}|null} The function and the object to call
   *   it on (null for a top-level name), or null when nothing may be called
   */
  resolveFunction(path) {
    if (typeof path !== 'string') return null;
    const name = path.trim();
    if (!name) return null;

    if (this.functions.has(name)) {
      return {fn: this.functions.get(name), thisArg: null};
    }

    const parts = name.split('.');
    const identifier = /^[A-Za-z_$][\w$]*$/;
    const forbiddenSegments = ['constructor', 'prototype', '__proto__', '__defineGetter__', '__defineSetter__', '__lookupGetter__', '__lookupSetter__'];
    const forbiddenRoots = ['window', 'self', 'globalThis', 'top', 'parent', 'frames', 'opener', 'document'];
    const forbiddenNames = ['eval', 'Function', 'setTimeout', 'setInterval', 'setImmediate', 'requestAnimationFrame',
      'queueMicrotask', 'execScript', 'open', 'alert', 'confirm', 'prompt', 'fetch', 'importScripts', 'postMessage'];

    if (!parts.every(part => identifier.test(part) && !forbiddenSegments.includes(part))) return null;
    if (forbiddenRoots.includes(parts[0])) return null;
    if (this.frameworkGlobals.includes(parts[0])) return null;
    if (parts.length === 1 && forbiddenNames.includes(name)) return null;

    // The framework's objects and their functions, found by identity as well: an
    // app object may hold one (App.rh = ResponseHandler, App.init = SecurityManager.init),
    // and Now.js loaded without the build has no list
    const reach = this.frameworkReach();
    const framework = reach.objects;

    let owner = null;
    let value = window;
    for (const part of parts) {
      if (value == null || (typeof value !== 'object' && typeof value !== 'function') ||
        !Object.prototype.hasOwnProperty.call(value, part)) {
        return null;
      }
      owner = value;
      value = value[part];
      if (framework.has(value)) return null;
    }

    if (typeof value !== 'function') return null;
    if (reach.functions.has(value)) return null;
    const source = Function.prototype.toString.call(value);
    if (/\{\s*\[native code\]\s*\}\s*$/.test(source) || /^class[\s{]/.test(source)) return null;

    return {fn: value, thisArg: parts.length > 1 ? owner : null};
  },

  /**
   * The framework's objects (Now, its managers, the values of frameworkGlobals)
   * and the functions they hold (own methods, and the methods of a class or of
   * an instance's class), for resolveFunction(). Kept until another of these
   * objects appears (a bundle or a feature loads).
   *
   * @private
   * @returns {{objects: Set<Object>, functions: WeakSet<Function>}}
   */
  frameworkReach() {
    const objects = [this, ...this.managers.values()];
    for (const global of this.frameworkGlobals) {
      const value = window[global];
      if (value && (typeof value === 'object' || typeof value === 'function')) objects.push(value);
    }
    const cached = this._frameworkReach;
    if (cached && cached.count === objects.length) return cached;

    const functions = new WeakSet();
    const collect = (holder) => {
      let names;
      try {
        names = Object.getOwnPropertyNames(holder);
      } catch (e) {
        return;
      }
      for (const key of names) {
        const descriptor = Object.getOwnPropertyDescriptor(holder, key);
        if (descriptor && typeof descriptor.value === 'function') functions.add(descriptor.value);
      }
    };
    for (const object of objects) {
      collect(object);
      const proto = typeof object === 'function' ? object.prototype : Object.getPrototypeOf(object);
      if (proto && proto !== Object.prototype && proto !== Function.prototype) collect(proto);
    }
    this._frameworkReach = {count: objects.length, objects: new Set(objects), functions};
    return this._frameworkReach;
  },

  /**
   * The function resolveFunction() finds, ready to call (bound to its object
   * for a dotted path), or null. For the attributes that name a callback —
   * data-formatter, data-sorter, data-filter-fn, data-validate-fn,
   * data-on-calculate, data-callback, ... — so they follow the same rules as
   * data-on and server callbacks.
   *
   * @param {string} name - Function name or dotted path
   * @returns {Function|null}
   */
  getFunction(name) {
    const resolved = this.resolveFunction(name);
    if (!resolved) return null;
    return resolved.thisArg ? resolved.fn.bind(resolved.thisArg) : resolved.fn;
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

  /**
   * Load feature bundles (see `features`) and resolve once they are ready.
   *
   * Markup and the core's own code load what they need by themselves; app code
   * that uses one of them directly calls this first:
   *
   *   await Now.require('Sortable');
   *   new Sortable(list, {...});
   *
   * A bundle is loaded once, from the folder now.core.min.js came from with the
   * same ?v= query (Now.init({paths: {dist}}) sets another folder). A feature
   * whose globals are already there (a page that loads the bundle with its own
   * <script>) resolves without loading anything. A bundle that fails to load
   * rejects, and the next require() tries again.
   *
   * @param {...(string|string[])} names - Feature names (`sortable`) or the
   *   globals they define (`Sortable`, `SyncManager`, `CodeEditor`)
   * @returns {Promise<void>}
   */
  require(...names) {
    return Promise.all(names.flat().map(name => this.loadFeature(name))).then(() => undefined);
  },

  /**
   * The feature a name stands for: a key of `features`, or one of the globals
   * a feature defines.
   *
   * @param {string} name
   * @returns {string|null}
   */
  findFeature(name) {
    const text = String(name || '');
    if (Object.hasOwn(this.features, text)) return text;
    const key = text.toLowerCase();
    if (Object.hasOwn(this.features, key)) return key;
    return Object.keys(this.features).find(k => this.features[k].globals.includes(text)) || null;
  },

  /**
   * The feature holding the ElementManager factory of a type, if any.
   *
   * @param {string} type - data-element type
   * @returns {string|null}
   */
  featureForElement(type) {
    return Object.keys(this.features).find(k => this.features[k].elements?.includes(type)) || null;
  },

  /**
   * Whether a feature is loaded (its globals are defined).
   *
   * @param {string} name - Feature name or one of its globals
   * @returns {boolean}
   */
  hasFeature(name) {
    const key = this.findFeature(name);
    // A browser's own global of the same name is not the feature: Chrome has a
    // native window.SyncManager (Background Sync) on every secure page
    const defined = global => {
      const value = window[global];
      return value !== undefined &&
        !(typeof value === 'function' && /\{\s*\[native code\]\s*\}\s*$/.test(Function.prototype.toString.call(value)));
    };
    return !!key && this.features[key].globals.every(defined);
  },

  /**
   * The feature bundles that markup inside a root uses and that are not
   * loaded yet (TemplateManager waits for them before a data-on-load handler).
   *
   * @param {Element} root
   * @returns {string[]} Feature names
   */
  featuresUsedBy(root) {
    if (!root?.querySelector) return [];
    return Object.entries(this.features).filter(([key, feature]) => {
      const selector = [feature.selector, ...(feature.elements || []).map(type => `[data-element="${type}"]`)].filter(Boolean).join(', ');
      return selector && !this.hasFeature(key) && (root.matches?.(selector) || root.querySelector(selector));
    }).map(([key]) => key);
  },

  /**
   * The URL of a file in the feature bundles' folder.
   *
   * @param {string} file - now.<name>.min.js or .css
   * @returns {string}
   */
  featureUrl(file) {
    const script = NOW_CORE_SCRIPT?.src ? NOW_CORE_SCRIPT : document.querySelector('script[src*="now.core"]');
    let base = null;
    let query = '';
    if (script?.src) {
      const src = new URL(script.src, document.baseURI);
      query = src.search;
      // now.core.min.js sits in Now/dist with the bundles; Now.js on its own sits one level up
      base = new URL(/(^|\/)Now\.js$/.test(src.pathname) ? 'dist/' : './', src).href;
    }
    if (this.paths.dist) {
      base = new URL(String(this.paths.dist).replace(/\/?$/, '/'), document.baseURI).href;
    }
    if (!base) {
      base = new URL(String(this.paths.framework || '/Now').replace(/\/?$/, '/') + 'dist/', document.baseURI).href;
    }
    return base + file + query;
  },

  /**
   * Every file of every feature bundle (ServiceWorkerManager precaches them).
   *
   * @returns {string[]}
   */
  featureUrls() {
    return Object.values(this.features).flatMap(feature => [feature.js, feature.css].filter(Boolean)).map(file => this.featureUrl(file));
  },

  /**
   * Load one feature bundle — see require().
   *
   * A manager the bundle registers (CalendarManager, SyncManager, …) gets its
   * Now.init() section the way initializeManagers() gives one to the core's
   * managers; before initializeManagers() has run, it starts the manager itself.
   *
   * @param {string} name - Feature name or one of its globals
   * @returns {Promise<void>}
   */
  loadFeature(name) {
    const key = this.findFeature(name);
    if (!key) {
      return Promise.reject(new Error(`Now.require(): there is no feature "${name}"`));
    }
    const loading = this.state.features || (this.state.features = new Map());
    if (loading.has(key)) {
      return loading.get(key);
    }

    const feature = this.features[key];
    if (this.hasFeature(key)) {
      const done = Promise.resolve();
      loading.set(key, done);
      return done;
    }

    const registered = new Set(this.managers.keys());
    const promise = Promise.all([
      this.loadFeatureFile(this.featureUrl(feature.js)),
      // The feature works without its styles, so a stylesheet that fails is only reported
      feature.css ? this.loadFeatureFile(this.featureUrl(feature.css)).catch(error => console.warn(error.message)) : null
    ]).then(async () => {
      if (!this.hasFeature(key)) {
        throw new Error(`Now.require(): ${feature.js} did not define ${feature.globals.join(', ')}`);
      }
      if (!this.state.managersReady) return;
      const globals = feature.globals.map(global => window[global]);
      for (const [managerName, manager] of this.managers) {
        if (!registered.has(managerName) && globals.includes(manager) && typeof manager.init === 'function') {
          await manager.init(this.config?.[managerName]);
        }
      }
    });
    loading.set(key, promise);
    promise.then(() => this.watchFeatures(), () => {
      // Not loaded: the next require(), or the next markup that needs it, tries again
      if (loading.get(key) === promise) loading.delete(key);
      this.watchFeatures();
    });
    return promise;
  },

  /**
   * Add one script or stylesheet of a feature bundle to the page.
   *
   * @param {string} url
   * @returns {Promise<void>}
   */
  loadFeatureFile(url) {
    const css = /\.css(\?|#|$)/.test(url);
    // A stylesheet is added once: when the feature's script failed, the next
    // try reuses it (loaded or still loading) instead of adding another
    const files = this.state.featureFiles || (this.state.featureFiles = new Map());
    if (css && files.has(url)) {
      return files.get(url);
    }
    const promise = new Promise((resolve, reject) => {
      const element = document.createElement(css ? 'link' : 'script');
      if (NOW_CORE_SCRIPT?.nonce) element.nonce = NOW_CORE_SCRIPT.nonce;
      if (css) {
        element.rel = 'stylesheet';
        element.href = url;
      } else {
        element.src = url;
      }
      element.onload = () => resolve();
      element.onerror = () => {
        element.remove();
        reject(new Error(`Now.require(): could not load ${url}`));
      };
      document.head.appendChild(element);
    });
    if (css) {
      files.set(url, promise);
      promise.catch(() => files.delete(url));
    }
    return promise;
  },

  /**
   * Load the features whose Now.init() section is enabled (sync.enabled).
   *
   * @returns {Promise<void>}
   */
  async requireConfiguredFeatures() {
    for (const [key, feature] of Object.entries(this.features)) {
      if (!feature.config || this.config?.[feature.config]?.enabled !== true) continue;
      try {
        await this.require(key);
      } catch (error) {
        console.error(`Now.js: "${feature.config}" is enabled but its bundle could not be loaded.`, error);
        if (this.config.strictMode) throw error;
      }
    }
  },

  /**
   * Load a feature as soon as markup that uses it is on the page — at start
   * (scan) or added later (CoreObserver). One observer handler covers every
   * feature not requested yet; it is replaced as they load and removed when
   * none is left.
   *
   * @param {Object} [options]
   * @param {boolean} [options.scan=false] - Also load what the page uses already
   * @returns {void}
   */
  watchFeatures({scan = false} = {}) {
    const loading = this.state.features || (this.state.features = new Map());
    const pending = Object.entries(this.features).filter(([key, feature]) => feature.selector && !loading.has(key) && !this.hasFeature(key));
    const present = scan ? pending.filter(([, feature]) => document.querySelector(feature.selector)) : [];
    const waiting = pending.filter(entry => !present.includes(entry));
    const load = key => this.require(key).catch(error => console.error(`Now.js: the "${key}" bundle could not be loaded.`, error));

    const selector = waiting.map(([, feature]) => feature.selector).join(', ');
    const watch = this.state.featureWatch;
    if (watch?.selector !== selector && window.CoreObserver) {
      if (watch) CoreObserver.off(watch.selector, watch.callback);
      this.state.featureWatch = null;
      if (selector) {
        const callback = element => {
          for (const [key, feature] of waiting) {
            if (!loading.has(key) && !this.hasFeature(key) && element.matches(feature.selector)) load(key);
          }
        };
        CoreObserver.onAdd(selector, callback);
        this.state.featureWatch = {selector, callback};
      }
    }
    present.forEach(([key]) => load(key));
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


  /**
   * Runs init() of every registered manager with its config section.
   *
   * A manager that fails is logged by name and the others still start, unless
   * strictMode is on, in which case the error is rethrown. A failing
   * SecurityManager is reported as such: CSRF tokens and HTML sanitizing may
   * not be set up.
   *
   * @returns {Promise<void>}
   * @throws {Error} The first failure, when config.strictMode is true
   */
  async initializeManagers() {
    for (const [name, manager] of this.managers) {
      if (typeof manager.init === 'function') {
        try {
          await manager.init(this.config[name]);
        } catch (error) {
          if (name === 'security') {
            console.error('Now.js: SecurityManager failed to initialize — CSRF protection and HTML sanitizing may not be set up.', error);
          } else {
            console.error(`Now.js: manager "${name}" failed to initialize.`, error);
          }
          if (this.config.strictMode) {
            throw error;
          }
        }
      }
    }
    // From here on a manager that arrives in a feature bundle is started by
    // loadFeature() instead
    this.state.managersReady = true;
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

    this.storeMetric(label, metric);
  },

  /**
   * Keep a metric in state.performance.metrics, dropping the oldest entries
   * beyond config.performance.maxMetrics. The map would otherwise grow for as
   * long as the page is open.
   *
   * @param {string} key
   * @param {Object} metric
   * @returns {void}
   */
  storeMetric(key, metric) {
    const metrics = this.state.performance.metrics;
    // A key set again moves to the newest position
    metrics.delete(key);
    metrics.set(key, metric);

    const max = Math.max(1, Number(this.config.performance?.maxMetrics) || 200);
    while (metrics.size > max) {
      metrics.delete(metrics.keys().next().value);
    }
  },

  /**
   * A key that two metrics recorded in the same millisecond do not share
   *
   * @param {string} name
   * @returns {string}
   */
  metricKey(name) {
    this.state.performance.sequence = (this.state.performance.sequence || 0) + 1;
    return `${name}_${Date.now()}_${this.state.performance.sequence}`;
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

    this.storeMetric(this.metricKey(name), metric);

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

    this.storeMetric(this.metricKey('error'), metric);
  },

  mergeConfig(base, override) {
    const merged = {...base};

    for (const [key, value] of Object.entries(override || {})) {
      // JSON.parse makes "__proto__" an own key: assigned here it would set what
      // the config inherits. Never options, so skipped with its two relatives.
      if (key === '__proto__' || key === 'constructor' || key === 'prototype') continue;
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

// Feature bundles the page's markup needs start loading once the DOM is there
// and the rest of the core (CoreObserver among it) has run
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => Now.watchFeatures({scan: true}), {once: true});
} else {
  Promise.resolve().then(() => Now.watchFeatures({scan: true}));
}
