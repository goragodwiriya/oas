const RouterManager = {
  MODES: {
    HISTORY: 'history',
    HASH: 'hash'
  },

  DEFAULT_CONFIG: {
    enabled: false,
    mode: 'history', // 'hash' or 'history'
    base: '/',
    autoDetectBase: true,  // Auto-detect base path
    fallback: 'index.html',

    auth: {
      enabled: false,
      autoGuard: true,
      defaultRequireAuth: false,
      publicPaths: ['/login', '/register', '/forgot-password'],
      guestOnlyPaths: ['/login', '/register'],
      conventions: {
        '/auth/*': {public: true},
        '/admin/*': {roles: ['admin']},
        '/api/*': {skipRouter: true}
      },
      redirects: {
        unauthorized: '/login',
        forbidden: '/403',
        afterLogin: '/',
        afterLogout: '/login'
      }
    },

    notFound: {
      path: '/404',
      template: '',
      title: 'Page Not Found',
      behavior: 'render',
      preserveUrl: false,
      preserveQuery: true,
      customHandler: null
    },

    trailingSlash: {
      mode: 'remove',
      redirect: false,
      ignorePaths: ['/'],
    },

    showLoadingNotification: true,
    initialLoad: {
      enabled: true,
      preserveContent: false,
      skipIfContent: true,
      contentSelector: '#main',
      forceRoute: false,
    },
  },

  routes: new Map(),
  params: new Map(),

  globalGuards: {
    beforeEach: [],
    afterEach: []
  },

  state: {
    current: null,
    previous: null,
    initialized: false,
    loading: false,
    error: null,
    disabled: false
  },

  beforeEach(guard) {
    if (typeof guard !== 'function') {
      throw new Error('Guard must be a function');
    }
    this.globalGuards.beforeEach.push(guard);
    return () => {
      const index = this.globalGuards.beforeEach.indexOf(guard);
      if (index > -1) {
        this.globalGuards.beforeEach.splice(index, 1);
      }
    };
  },

  afterEach(guard) {
    if (typeof guard !== 'function') {
      throw new Error('Guard must be a function');
    }
    this.globalGuards.afterEach.push(guard);
    return () => {
      const index = this.globalGuards.afterEach.indexOf(guard);
      if (index > -1) {
        this.globalGuards.afterEach.splice(index, 1);
      }
    };
  },

  async runBeforeEachGuards(to, from) {
    for (const guard of this.globalGuards.beforeEach) {
      try {
        const result = await guard(to, from);
        if (result === false) {
          return false;
        }
        if (typeof result === 'string') {
          return result; // Redirect path
        }
      } catch (error) {
        console.error('[ROUTER] Global beforeEach guard error:', error);
        return false;
      }
    }
    return true;
  },

  async runAfterEachGuards(to, from) {
    for (const guard of this.globalGuards.afterEach) {
      try {
        await guard(to, from);
      } catch (error) {
        console.error('Global afterEach guard error:', error);
      }
    }
  },

  async init(options = {}) {
    try {
      if (this.state.initialized && options.force) {
        EventSystemManager.removeComponentHandlers('router');
      } else if (this.state.initialized) {
        return this;
      }

      this.state = {
        current: null,
        previous: null,
        initialized: false,
        loading: false,
        error: null,
        disabled: false
      };

      this.routes.clear();
      this.params.clear();

      // Prefer base path provided by Now.js if available
      if (options.autoDetectBase !== false) {
        try {
          const nowBase = (window.Now && (window.Now.basePath || (window.Now.config && window.Now.config.router && window.Now.config.router.base))) || null;
          if (nowBase) {
            options.base = options.base || nowBase;
          } else {
            const detectedBase = await this.detectBasePath();
            options.base = options.base || detectedBase;
          }
        } catch (e) {
          const detectedBase = await this.detectBasePath();
          options.base = options.base || detectedBase;
        }
      }

      // Normalize base if provided
      if (options.base) {
        let b = options.base;
        if (!b.startsWith('/')) b = '/' + b;
        if (b.length > 1 && b.endsWith('/')) b = b.slice(0, -1);
        options.base = b;
      }

      this.config = Now.mergeConfig(this.DEFAULT_CONFIG, options);

      if (!this.config.enabled) {
        this.state.disabled = true;
        return this;
      }

      if (!window.TemplateManager) {
        throw new Error('TemplateManager is required but not found');
      }

      if (this.config.auth?.enabled) {
        await this.initAuthIntegration();
      }

      if (this.config.initialLoad?.preserveContent) {
        this.storeInitialContent();
      }

      if (this.config.notFound?.path) {
        this.register(this.config.notFound.path, {
          template: this.config.notFound.template,
          title: this.config.notFound.title
        });
      }

      if (options.routes) {
        Object.entries(options.routes).forEach(([path, config]) => {
          this.register(path, config);
        });
      }

      if (this.config.auth?.enabled) {
        this.applyAuthConventions();
      }

      // Smart base-path inference: run AFTER routes are registered so we can
      // compare registered route patterns against window.location.pathname.
      // Only kicks in when base is still '/' (not explicitly set by user / Now.js).
      if ((this.config.base === '/' || !this.config.base) && this.routes.size > 0) {
        const inferredBase = this.inferBaseFromRoutes();
        if (inferredBase && inferredBase !== '/') {
          this.config.base = inferredBase;
          console.info(`[RouterManager] Auto-detected base path from routes: "${inferredBase}"`);
        }
      }

      this.setupEventListeners();

      this.state.initialized = true;

      await EventManager.emit('router:initialized');

      const currentPath = this.getPath();

      if (this.shouldHandleInitialRoute()) {
        // For initial load, preserve existing query parameters
        await this.navigate(currentPath, {}, {replace: true, isInitialLoad: true, preserveQuery: true});
      } else {
        const match = this.matchRoute(currentPath);

        if (match) {
          this.state.current = {
            path: currentPath,
            ...match.route,
            params: match.params || {}
          };

          if (match.route.title) {
            document.title = this.translateTitle(match.route.title);
          }

          const componentManager = Now.getManager('component');
          if (componentManager) {
            await componentManager.initializeExistingElements();
          }
        } else if (currentPath !== '/' && currentPath !== '') {
          // If no match but we have a path, try to navigate anyway
          await this.navigate(currentPath, {}, {replace: true, isInitialLoad: true});
        }
      }

      return this;

    } catch (error) {
      this.state.error = ErrorManager.handle('Router initialization failed', {
        context: 'RouterManager.init',
        type: 'router:error',
        data: {error}
      });
      throw error;
    }
  },

  /**
   * Initialize Auth Integration
   */
  async initAuthIntegration() {
    const authManager = Now.getManager('auth');

    if (!authManager) {
      console.warn('RouterManager: Auth enabled but AuthManager not found');
      this.config.auth.enabled = false;
      return;
    }

    if (!authManager.state) {
      console.warn('RouterManager: AuthManager state not available');
      this.config.auth.enabled = false;
      return;
    }

    let attempts = 0;
    const maxAttempts = 100;

    while ((!authManager.state || !authManager.state.initialized) && attempts < maxAttempts) {
      await new Promise(resolve => setTimeout(resolve, 100));
      attempts++;
    }

    if (!authManager.state || !authManager.state.initialized) {
      console.error('RouterManager: AuthManager failed to initialize within timeout');
      console.error('Final AuthManager state:', authManager.state);
      this.config.auth.enabled = false;
      return;
    }
  },

  /**
   * Apply Convention-based Route Protection
   */
  applyAuthConventions() {
    if (!this.config.auth?.enabled) return;

    const defaultRequireAuth = this.config.auth.defaultRequireAuth !== false;

    for (const [path, route] of this.routes) {
      if (route.authProcessed) continue;

      let authRequired = defaultRequireAuth;
      let isPublic = false;
      let isGuestOnly = false;


      if (this.config.auth.publicPaths?.includes(path)) {
        authRequired = false;
        isPublic = true;
      }

      if (this.config.auth.guestOnlyPaths?.includes(path)) {
        isGuestOnly = true;
        authRequired = false;
      }

      route.requireAuth = route.requireAuth !== undefined ? route.requireAuth : authRequired;
      route.requireGuest = route.requireGuest !== undefined ? route.requireGuest : isGuestOnly;
      route.isPublic = isPublic;
      route.authProcessed = true;
    }
  },

  /**
   * Match Convention Pattern
   */
  matchConvention(path, pattern) {
    const regexPattern = pattern
      .replace(/\*/g, '[^/]*')
      .replace(/\*\*/g, '.*')
      .replace(/\//g, '\\/');

    const regex = new RegExp(`^${regexPattern}$`);
    return regex.test(path);
  },

  /**
   * Enhanced Navigate with Auth Guards
   */
  async navigate(path, params = {}, options = {}) {
    try {
      if (this.state.loading && !options.force) return false;

      // Parse URL: separate path, query string, and hash
      let cleanPath = path;
      let queryString = '';
      let hashFragment = '';

      // Separate the hash first (if there is #)
      if (path.includes('#')) {
        const parts = path.split('#');
        cleanPath = parts[0];
        hashFragment = parts[1] || '';
      }

      // Separate the query string (in case there is ?)
      if (cleanPath.includes('?')) {
        const parts = cleanPath.split('?');
        cleanPath = parts[0];
        queryString = parts[1] || '';
      }

      // If preserveQuery is true, merge with current URL query params
      if (options.preserveQuery && !queryString) {
        queryString = window.location.search.slice(1); // Remove '?'
      }

      // Parse query string into object
      const queryParams = {};
      if (queryString) {
        const searchParams = new URLSearchParams(queryString);
        searchParams.forEach((value, key) => {
          queryParams[key] = value;
        });
      }

      // Merge params (query params + submitted params)
      const mergedParams = {...queryParams, ...params};

      const normalizedPath = this.handleTrailingSlash(cleanPath);

      // Skip navigation if already on the same path (unless forced)
      // But handle hash changes - update URL and dispatch hashchange event
      if (!options.force && !options.isInitialLoad &&
        this.state.current && this.state.current.path === normalizedPath) {
        // Check if query params changed - if changed, allow re-navigation
        const currentQuerySorted = new URLSearchParams(
          [...new URLSearchParams(window.location.search.slice(1)).entries()].sort()
        ).toString();
        const newQuerySorted = new URLSearchParams(
          [...new URLSearchParams(queryString).entries()].sort()
        ).toString();

        if (currentQuerySorted !== newQuerySorted) {
          // Query string changed on same path - fall through to normal navigation
        } else {
          // Check if hash changed
          const currentHash = window.location.hash.slice(1);
          if (hashFragment !== currentHash) {
            // Update URL with new hash (don't append # if empty)
            const hashPart = hashFragment ? '#' + hashFragment : '';
            const newUrl = window.location.pathname + window.location.search + hashPart;
            history.pushState({params: this.state.current.params, hash: hashFragment}, '', newUrl);
            // Dispatch hashchange event so TabsComponent and other listeners can respond
            window.dispatchEvent(new HashChangeEvent('hashchange'));
          }
          return true;
        }
      }

      const match = this.matchRoute(normalizedPath);
      if (!match) {
        return await this.handleNotFound(normalizedPath, mergedParams);
      }

      const to = {
        path: normalizedPath,
        params: match.params,
        route: match.route,
        query: queryParams,
        hash: hashFragment
      };

      const from = this.state.current ? {
        path: this.state.current.path,
        params: this.state.current.params,
        route: this.state.current
      } : null;

      // Run global beforeEach guards
      const globalGuardResult = await this.runBeforeEachGuards(to, from);
      if (globalGuardResult === false) return false;

      if (typeof globalGuardResult === 'string') {
        return this.navigate(globalGuardResult, {}, {replace: true});
      }

      // Auth check - only if autoGuard is enabled
      if (this.config.auth?.enabled && this.config.auth?.autoGuard !== false) {
        const guardResult = await this.checkRouteAuth(cleanPath, params);

        if (!guardResult.allowed) {
          return this.handleAuthRedirect(guardResult, cleanPath, params);
        }
      }

      // Route-level beforeEnter guard
      if (match.route.beforeEnter && typeof match.route.beforeEnter === 'function') {
        try {
          const authManager = Now.getManager('auth');
          const guardResult = await match.route.beforeEnter(match.params, this.state.current, authManager);
          if (guardResult === false) {
            return false;
          }
          if (typeof guardResult === 'string') {
            return this.navigate(guardResult, {}, {replace: true});
          }
        } catch (error) {
          console.error('Route guard error:', error);
          return false;
        }
      }

      // Route-level beforeLeave guard
      if (this.state.current && this.state.current.beforeLeave &&
        typeof this.state.current.beforeLeave === 'function') {
        try {
          const guardResult = await this.state.current.beforeLeave(match.params, this.state.current);
          if (guardResult === false) {
            return false;
          }
        } catch (error) {
          console.error('Route leave guard error:', error);
          return false;
        }
      }

      // Event-based guards
      const results = await EventManager.emit('route:before', {
        path: match.route.path,
        params: match.params,
      });

      let shouldContinue = true;
      if (Array.isArray(results)) {
        shouldContinue = !results.includes(false);
      }
      if (!shouldContinue) {
        this.state.current = null;
        this.hideLoading();
        return false;
      }

      // Continue with navigation...
      this.params.set(match.route.path, match.params);
      this.state.previous = this.state.current;

      // Resolve route params in menuPath (e.g. '/widgets/:module' -> '/widgets/facebook')
      let resolvedMenuPath = match.route.menuPath;
      if (resolvedMenuPath && match.params) {
        Object.entries(match.params).forEach(([key, value]) => {
          resolvedMenuPath = resolvedMenuPath.replace(new RegExp(`:${key}(?=/|$)`), value);
        });
      }

      this.state.current = {
        ...match.route,
        ...(resolvedMenuPath ? {menuPath: resolvedMenuPath} : {}),
        params: match.params,
        path: normalizedPath,
        query: mergedParams,
        hash: hashFragment
      };

      // Rebuild query string from merged params
      const finalQueryString = Object.keys(mergedParams).length > 0
        ? new URLSearchParams(mergedParams).toString()
        : '';

      // Build the full URL with query parameters
      let fullUrl = this.resolvePath(cleanPath, match.params, finalQueryString, hashFragment);

      // Update history
      if (options.replace) {
        window.history.replaceState({params: match.params}, '', fullUrl);
      } else {
        window.history.pushState({params: match.params}, '', fullUrl);
      }

      this.showLoading();

      let template = this.resolveTemplate(match.route.template, match.params);
      let content = await this.loadTemplate(template);

      if (!content) {
        return await this.handleNotFound(normalizedPath, params);
      }

      const container = document.createElement('div');
      container.innerHTML = content;

      // Priority: 1. data-title from template, 2. <title> from template, 3. title from route config
      let title = this.extractText(container, '[data-title]') ||
        this.extractText(container, 'title');
      const mainContent = container.querySelector(Now.config.mainSelector) || container;

      // Use route config title as fallback, with translation support for {LNG_...}
      if (!title && match.route.title) {
        title = this.translateTitle(match.route.title);
      }

      if (title) {
        document.title = title;
      }

      await this.render(mainContent.innerHTML);

      const shouldScroll = options.scroll !== false;
      if (shouldScroll) {
        window.scrollTo(0, 0);
      }

      const hash = window.location.hash.slice(1);
      if (hash && shouldScroll) {
        setTimeout(() => {
          const element = document.getElementById(hash);
          if (element && window.ScrollManager) {
            ScrollManager.scrollTo(element, {
              offset: ScrollManager.config.core.offset,
              behavior: 'smooth'
            });
          }
        }, 100);
      }

      // Run global afterEach guards
      await this.runAfterEachGuards(to, from);

      await EventManager.emit('route:changed', {
        path: match.route.path,
        params: match.params,
        query: queryParams,
        hash: hashFragment,
        route: match.route,
        previous: this.state.previous,
        isNotFoundPage: normalizedPath === this.config.notFound.path
      });

      // Execute data-script after all processing is complete
      setTimeout(() => {
        this.executePageScripts();
      }, 0);

      return true;

    } catch (error) {
      this.state.current = null;
      this.handleError('Navigation failed', error);
      return false;
    } finally {
      this.hideLoading();
    }
  },

  /**
   * Execute page scripts after navigation
   * Finds the first element with data-script and calls TemplateManager.processDataScript
   */
  executePageScripts() {
    const mainContent = document.querySelector(Now.config.mainSelector);
    if (!mainContent) return;

    // Find first data-script in page (only one per page)
    const scriptElement = mainContent.querySelector('[data-script]');
    if (scriptElement && window.TemplateManager?.processDataScript) {
      const routeParams = this.state.current?.params || {};
      const routeQuery = this.state.current?.query || {};
      const scriptData = {
        ...routeParams,
        params: routeParams,
        query: routeQuery,
        route: this.state.current
      };

      const context = {
        state: {
          data: scriptData,
          route: this.state.current
        },
        data: scriptData
      };
      TemplateManager.processDataScript(scriptElement, context);
    }
  },

  /**
   * Check Route Authentication
   */
  async checkRouteAuth(path, params) {
    const authManager = Now.getManager('auth');
    if (!authManager) {
      console.warn('AuthManager not found during auth check');
      return {allowed: true};
    }


    if (!authManager.state) {
      console.warn('AuthManager state not available during auth check');
      return {allowed: true};
    }

    if (!authManager.state.initialized) {
      console.warn('AuthManager not initialized during auth check');
      return {allowed: true};
    }


    if (typeof authManager.isAuthenticated !== 'function') {
      console.warn('AuthManager methods not available');
      return {allowed: true};
    }

    const normalizedPath = this.handleTrailingSlash(path);
    const match = this.matchRoute(normalizedPath);

    if (!match) {
      return {allowed: true};
    }

    const route = match.route;
    const result = {
      allowed: true,
      reason: null,
      redirectTo: null
    };

    try {
      const isAuthenticated = authManager.isAuthenticated();

      if (route.requireGuest) {
        if (isAuthenticated) {
          result.allowed = false;
          result.reason = 'already_authenticated';
          result.redirectTo = this.config.auth?.redirects?.afterLogin || '/';
          return result;
        }
      }

      if (route.requireAuth) {
        if (!isAuthenticated) {
          result.allowed = false;
          result.reason = 'authentication_required';
          result.redirectTo = this.config.auth?.redirects?.unauthenticated || this.config.auth?.redirects?.unauthorized || '/login';

          if (typeof authManager.saveIntendedUrl === 'function') {
            authManager.saveIntendedUrl();
          }
          return result;
        }
      }

      if (route.roles && route.roles.length > 0) {
        if (typeof authManager.hasRole === 'function') {
          const hasRequiredRole = route.roles.some(role => authManager.hasRole(role));
          if (!hasRequiredRole) {
            result.allowed = false;
            result.reason = 'insufficient_role';
            result.redirectTo = this.config.auth?.redirects?.forbidden || '/403';
            return result;
          }
        }
      }

      return result;

    } catch (error) {
      console.error('Error in auth check:', error);
      return {allowed: true};
    }
  },

  /**
   * Handle Auth Redirect
   */
  async handleAuthRedirect(guardResult, originalPath, params) {
    const {reason, redirectTo} = guardResult;

    // Persist intended route before redirecting to login/other auth page
    if (reason === 'authentication_required') {
      try {
        if (window.RedirectManager?.storeIntendedRoute) {
          await RedirectManager.storeIntendedRoute();
        } else {
          const authManager = Now.getManager('auth');
          if (authManager?.saveIntendedUrl) {
            authManager.saveIntendedUrl();
          }
        }
      } catch (error) {
        console.warn('[RouterManager] Failed to store intended route before auth redirect:', error);
      }
    }

    await EventManager.emit('route:guard:rejected', {
      reason,
      originalPath,
      redirectTo,
      timestamp: Date.now()
    });

    const messages = {
      authentication_required: 'Please login to access this page',
      already_authenticated: 'You are already logged in',
      insufficient_role: 'You do not have permission to access this page',
      insufficient_permission: 'You do not have permission to access this page'
    };

    const message = messages[reason] || 'Permission required';
    NotificationManager.warning(message);

    if (redirectTo) {
      return this.navigate(redirectTo, {}, {replace: true});
    }

    return false;
  },

  createRoutePattern(path, ignoreTrailingSlash = true) {
    let pattern = path
      .replace(/:[^\s/]+/g, '([^/]+)')
      .replace(/\//g, '\\/');

    if (ignoreTrailingSlash) {
      pattern = `^${pattern}\\/?$`;
    } else {
      pattern = `^${pattern}$`;
    }

    return new RegExp(pattern);
  },

  matchRoute(path) {
    const normalizedPath = this.config.trailingSlash.mode === 'preserve'
      ? path
      : this.handleTrailingSlash(path);

    for (const [routePath, route] of this.routes) {
      const pattern = this.createRoutePattern(routePath);
      const matches = normalizedPath.match(pattern);

      if (matches) {
        const params = {};
        route.paramNames.forEach((name, index) => {
          params[name] = matches[index + 1];
        });
        return {route, params};
      }
    }

    return null;
  },

  register(path, config) {
    if (!path) {
      throw new Error('Route path is required');
    }

    const paramNames = this.extractParamNames(path);
    const pattern = this.createRoutePattern(path);

    if (typeof config === 'string') {
      config = {template: config};
    }

    this.routes.set(path, {
      path,
      pattern,
      paramNames,
      template: config.template || path,
      title: config.title,
      ...config
    });

    if (config.children && typeof config.children === 'object') {
      Object.entries(config.children).forEach(([childPath, childConfig]) => {
        const fullChildPath = childPath.startsWith('/')
          ? childPath
          : path + (path.endsWith('/') ? '' : '/') + childPath;

        this.register(fullChildPath, childConfig);
      });
    }
  },

  extractParamNames(path) {
    const paramNames = [];
    const segments = path.split('/');

    segments.forEach(segment => {
      if (segment.startsWith(':')) {
        paramNames.push(segment.substring(1));
      }
    });

    return paramNames;
  },

  handleTrailingSlash(url) {
    if (url === '/') return url;

    if (this.config.trailingSlash.ignorePaths?.includes(url)) {
      return url;
    }

    switch (this.config.trailingSlash.mode) {
      case 'add':
        return url.endsWith('/') ? url : `${url}/`;
      case 'remove':
        return url.endsWith('/') ? url.slice(0, -1) : url;
      case 'preserve':
      default:
        return url;
    }
  },

  /**
   * Detect base path automatically using multiple strategies
   *
   * Priority order:
   *  1. <base href="..."> element
   *  2. <meta name="base-path" content="..."> or <meta name="router-base">
   *  3. data-base attribute on <html> or <body>
   *  4. data-base attribute on the RouterManager/now.core script tag
   *  5. Script src pattern analysis (Now.js, main.js, app.js …)
   *  6. Current URL index-file pattern  (e.g. /myapp/index.php → base /myapp)
   *  7. Fallback → '/'
   *
   * A smarter post-registration pass (inferBaseFromRoutes) is run in init()
   * after routes are registered.
   */
  async detectBasePath() {
    const normalize = (p) => {
      if (!p) return '/';
      if (!p.startsWith('/')) p = '/' + p;
      if (p.length > 1 && p.endsWith('/')) p = p.slice(0, -1);
      return p || '/';
    };

    // 1. Explicit <base href="...">
    try {
      const baseEl = document.querySelector('base[href]');
      if (baseEl) {
        const href = baseEl.getAttribute('href');
        if (href) {
          const resolved = new URL(href, window.location.href);
          return normalize(resolved.pathname);
        }
      }
    } catch (e) {}

    // 2. <meta name="base-path"> or <meta name="router-base">
    try {
      const metaBase = document.querySelector(
        'meta[name="base-path"], meta[name="router-base"], meta[name="app-base"]'
      );
      if (metaBase) {
        const content = metaBase.getAttribute('content');
        if (content) return normalize(content);
      }
    } catch (e) {}

    // 3. data-base on <html> or <body>
    try {
      const dataBase =
        document.documentElement?.getAttribute('data-base') ||
        document.body?.getAttribute('data-base');
      if (dataBase) return normalize(dataBase);
    } catch (e) {}

    // 4. data-base on the script tag that loads RouterManager or now.core
    try {
      const routerScript = document.querySelector(
        'script[src*="RouterManager"], script[src*="now.core"], script[src*="Now.js"]'
      );
      if (routerScript) {
        const dataBase = routerScript.getAttribute('data-base');
        if (dataBase) return normalize(dataBase);
      }
    } catch (e) {}

    // 5. Script src pattern analysis
    try {
      const knownPatterns = [
        /^(.*?)\/Now\/Now\.js/,
        /^(.*?)\/Now\/js\//,
        /^(.*?)\/Now\/dist\//,
        /^(.*?)\/dist\/now\.core/,
        /^(.*?)\/js\/main\.js/,
        /^(.*?)\/js\/app\.js/,
        /^(.*?)\/assets\/index\.[^/]+\.js/, // Vite-style bundles
      ];

      for (const script of document.getElementsByTagName('script')) {
        const src = script.getAttribute('src');
        if (!src) continue;
        // Skip cross-origin scripts
        try {
          const u = new URL(src, window.location.href);
          if (u.origin !== window.location.origin) continue;
          const pathname = u.pathname;
          for (const pattern of knownPatterns) {
            const m = pathname.match(pattern);
            if (m && m[1] !== undefined) {
              const base = normalize(m[1]);
              if (base !== '/') return base;
            }
          }
        } catch (e) {}
      }
    } catch (e) {}

    // 6. Current URL index-file heuristic
    //    /myapp/index.php  →  /myapp
    //    /myapp/index.html →  /myapp
    try {
      const pathname = window.location.pathname;
      const indexMatch = pathname.match(
        /^(.+?)\/(index\.(html?|php\d?)|app\.(html?|php\d?))$/i
      );
      if (indexMatch && indexMatch[1]) {
        return normalize(indexMatch[1]);
      }
    } catch (e) {}

    return '/';
  },

  /**
   * Smart base-path inference from registered routes vs current pathname.
   * Called by init() AFTER routes are registered, as a last-resort correction.
   *
   * Example: routes has '/dashboard', URL is '/myapp/dashboard'
   *          → infers base = '/myapp'
   *
   * @returns {string} inferred base path, or '/' if not determinable
   */
  inferBaseFromRoutes() {
    const pathname = window.location.pathname;
    if (!pathname || pathname === '/') return '/';

    // If at least one registered route already matches → base is correct (keep '/')
    if (this.matchRoute(pathname)) return '/';

    // Try progressively shorter prefixes
    const segments = pathname.split('/').filter(Boolean);

    for (let i = 1; i < segments.length; i++) {
      const prefix = '/' + segments.slice(0, i).join('/');
      const suffix = '/' + segments.slice(i).join('/');

      if (this.matchRoute(suffix)) {
        return prefix;
      }
    }

    return '/';
  },

  getPath(includeQuery = false) {
    let path;

    if (this.config.mode === 'hash') {
      const hashPath = window.location.hash.slice(1);
      if (hashPath) {
        path = hashPath.startsWith('/') ? hashPath : '/' + hashPath;
      } else {
        // No hash found - check if we have a pathname that should be converted to hash
        const pathname = window.location.pathname || '/';
        const base = this.config.base && this.config.base !== '/' ? this.config.base : '';

        let pathToConvert = pathname;
        if (base && pathname.startsWith(base)) {
          pathToConvert = pathname.slice(base.length) || '/';
        }

        // If we have a meaningful path and no hash, this might be a direct URL access
        if (pathToConvert !== '/' && pathToConvert !== '') {
          path = pathToConvert.startsWith('/') ? pathToConvert : '/' + pathToConvert;
        } else {
          path = '/';
        }
      }
    } else {
      // History mode
      const pathname = window.location.pathname || '/';
      const base = this.config.base && this.config.base !== '/' ? this.config.base : '';

      let result = pathname;
      if (base && pathname.startsWith(base)) {
        result = pathname.slice(base.length) || '/';
      }

      path = result.startsWith('/') ? result : '/' + result;
    }

    // Include query parameters if requested
    if (includeQuery) {
      const query = this.getQuery();
      if (query) {
        path += query;
      }
    }

    return path;
  },

  getQuery() {
    return window.location.search || '';
  },


  shouldHandleInitialRoute() {
    const config = this.config.initialLoad;

    if (!config.enabled) {
      return false;
    }

    if (config.forceRoute) {
      return true;
    }

    // Handle hash mode direct URL conversion
    if (this.config.mode === 'hash') {
      const hasHash = window.location.hash;
      const pathname = window.location.pathname || '/';
      const base = this.config.base && this.config.base !== '/' ? this.config.base : '';

      let pathToCheck = pathname;
      if (base && pathname.startsWith(base)) {
        pathToCheck = pathname.slice(base.length) || '/';
      }

      // If no hash but we have a meaningful pathname, redirect to hash URL
      if (!hasHash && pathToCheck !== '/' && pathToCheck !== '') {
        // Ensure we maintain the full URL structure for proper API base path
        const currentUrl = window.location.href;
        const baseUrl = currentUrl.split('#')[0];
        const properBaseUrl = baseUrl.endsWith('/') ? baseUrl : baseUrl + '/';
        const hashUrl = properBaseUrl + '#' + (pathToCheck.startsWith('/') ? pathToCheck : '/' + pathToCheck);
        // Preserve query parameters during hash mode redirect
        const queryString = window.location.search || '';
        window.location.replace(hashUrl + queryString);
        return false; // Don't handle initial route, let the redirect happen
      }
    }

    // Always handle initial route for direct URL access or page reload
    const currentPath = this.getPath();
    if (currentPath !== '/' && currentPath !== '') {
      return true;
    }

    if (config.skipIfContent) {
      const mainContent = document.querySelector(config.contentSelector);
      if (mainContent && mainContent.children.length > 0) {
        return false;
      }
    }

    return true;
  },

  storeInitialContent() {
    const mainContent = document.querySelector(this.config.initialLoad.contentSelector);
    if (mainContent) {
      this.state.initialContent = {
        html: mainContent.innerHTML,
        path: window.location.pathname
      };
    }
  },

  setupEventListeners() {
    document.addEventListener('click', (event) => this.handleClick(event));

    window.addEventListener('popstate', (event) => this.handlePopState(event));

    if (this.config.mode === 'hash') {
      window.addEventListener('hashchange', (event) => this.handleHashChange(event));
    }

    // Handle page reload and direct URL access
    window.addEventListener('load', () => {
      // Special handling for hash mode direct URLs
      if (this.config.mode === 'hash') {
        this.ensureHashModeUrl();
      }

      const currentPath = this.getPath();
      if (currentPath && currentPath !== '/' && !this.state.current) {
        // Handle initial route normally
        this.navigate(currentPath, {}, {replace: true, isInitialLoad: true});
      }
    });
  },

  /**
   * Ensure URL is in correct hash format for hash mode
   */
  ensureHashModeUrl() {
    const hasHash = window.location.hash;
    const pathname = window.location.pathname || '/';
    const base = this.config.base && this.config.base !== '/' ? this.config.base : '';

    let pathToCheck = pathname;
    if (base && pathname.startsWith(base)) {
      pathToCheck = pathname.slice(base.length) || '/';
    }

    // If no hash but we have a meaningful pathname, redirect to hash URL
    if (!hasHash && pathToCheck !== '/' && pathToCheck !== '') {
      const hashUrl = window.location.origin +
        (base || '') +
        '/' + // Always include trailing slash before hash
        (window.location.search || '') +
        '#' + (pathToCheck.startsWith('/') ? pathToCheck : '/' + pathToCheck);
      window.location.replace(hashUrl);
      return false;
    } return true;
  },

  handleClick(event) {
    const link = event.target.closest('a');
    if (!link) return;

    // Skip if external link, download, or has target
    if (link.hasAttribute('download') ||
      link.hasAttribute('target') ||
      link.getAttribute('rel') === 'external') return;

    // Priority 1: Use data-route if present
    let routePath = link.getAttribute('data-route');

    if (routePath) {
      // Handle relative data-route paths
      if (routePath.startsWith('../')) {
        // Convert relative to absolute based on current location
        routePath = this.extractRouteFromHref(routePath);
      } else if (!routePath.startsWith('/')) {
        // Simple relative path, prepend /
        routePath = '/' + routePath;
      }
    } else {
      // Priority 2: Extract route from href
      const href = link.getAttribute('href');

      if (!href) return;

      // mailto, tel, hash-only -> let browser handle
      if (/^(mailto:|tel:|#)/.test(href)) return;

      // If it's an absolute URL (http/https), check origin
      if (/^https?:\/\//i.test(href)) {
        try {
          const url = new URL(href, window.location.href);
          const isSameOrigin = url.origin === window.location.origin;

          if (!isSameOrigin) {
            // External redirect: if target specified, allow browser to follow target
            if (link.hasAttribute('target')) {
              return; // do not intercept
            }
            // No target: let browser handle navigation (default behavior)
            return;
          }

          // Same-origin absolute URL: convert to route path by removing base
          routePath = url.pathname + url.search + url.hash;
          // Remove base if configured
          const base = this.config.base && this.config.base !== '/' ? this.config.base : '';
          if (base && routePath.startsWith(base)) {
            routePath = routePath.substring(base.length) || '/';
          }
        } catch (e) {
          // Malformed URL fallback - treat as relative
          routePath = this.extractRouteFromHref(href);
        }
      } else {
        // Relative href -> convert to route path
        routePath = this.extractRouteFromHref(href);
      }
    }

    // Collect params from data attributes and optionally current URL
    const params = {};
    const dataset = link.dataset;

    // data-params="*" copies all current query params; comma list copies only those keys
    if (dataset.params) {
      const currentQuery = new URLSearchParams(window.location.search);
      const requested = dataset.params.trim();

      if (requested === '*' || requested.toLowerCase() === 'all') {
        currentQuery.forEach((value, key) => {
          params[key] = value;
        });
      } else {
        requested.split(',').map(p => p.trim()).filter(Boolean).forEach(key => {
          const value = currentQuery.get(key);
          if (value !== null) {
            params[key] = value;
          }
        });
      }
    }

    Object.keys(dataset).forEach(key => {
      if (key === 'params') return; // already processed above

      if (key.startsWith('param')) {
        const rawName = key.replace('param', '');
        if (!rawName) return;

        // Convert camelCase or dashed names to snake_case for consistency
        const normalizedName = rawName
          .replace(/([A-Z])/g, '_$1')
          .replace(/[-\s]+/g, '_')
          .replace(/^_+/, '')
          .toLowerCase();

        params[normalizedName || rawName.toLowerCase()] = dataset[key];
      }
    });

    event.preventDefault();
    this.navigate(routePath, params);
  },

  /**
   * Extract route path from href attribute
   * Examples:
   * "./docs" -> "/docs"
   * "../components" -> "/components"
   * "/docs/api" -> "/docs/api"
   * "docs/getting-started" -> "/docs/getting-started"
   */
  extractRouteFromHref(href) {
    if (!href) return '/';

    // If already absolute path, use as is
    if (href.startsWith('/')) {
      return href;
    }

    // Handle relative paths by resolving against current location
    try {
      const resolvedUrl = new URL(href, window.location.href);
      let pathname = resolvedUrl.pathname;

      // Remove base path if present
      const basePath = this.config.base || '/';
      if (basePath !== '/' && pathname.startsWith(basePath)) {
        pathname = pathname.substring(basePath.length) || '/';
      }

      // Include query string and hash
      return pathname + resolvedUrl.search + resolvedUrl.hash;
    } catch (error) {
      // Fallback: simple conversion
      if (href.startsWith('./')) {
        return href.substring(1); // "./docs" -> "/docs"
      } else if (href.startsWith('../')) {
        // Simple assumption: "../components" -> "/components"
        const parts = href.split('/').filter(part => part !== '..' && part !== '.' && part !== '');
        return '/' + parts.join('/');
      } else {
        // Regular relative: "docs" -> "/docs"
        return '/' + href;
      }
    }
  },

  handlePopState(event) {
    const path = this.getPath(); // Get path without query
    const params = event.state?.params || {};

    // During popstate, the URL already contains the correct query parameters
    // Parse them from the current URL
    const queryString = window.location.search.slice(1); // Remove '?'
    const queryParams = {};
    if (queryString) {
      const searchParams = new URLSearchParams(queryString);
      searchParams.forEach((value, key) => {
        queryParams[key] = value;
      });
    }

    const hash = window.location.hash.slice(1); // Remove '#'

    // We just need to process the route without modifying the URL
    this.processRoute(path, params, queryParams, hash);
  },

  async processRoute(path, params = {}, queryParams = {}, hash = '') {
    // Internal method to process route without URL manipulation
    // Used by handlePopState to avoid double URL updates
    const normalizedPath = this.handleTrailingSlash(path);
    const match = this.matchRoute(normalizedPath);

    if (!match) {
      return await this.handleNotFound(normalizedPath, params);
    }

    try {
      this.params.set(match.route.path, params);
      this.state.previous = this.state.current;

      // Resolve route params in menuPath (e.g. '/widgets/:module' -> '/widgets/facebook')
      let resolvedMenuPath = match.route.menuPath;
      if (resolvedMenuPath && params) {
        Object.entries(params).forEach(([key, value]) => {
          resolvedMenuPath = resolvedMenuPath.replace(new RegExp(`:${key}(?=/|$)`), value);
        });
      }

      this.state.current = {
        ...match.route,
        ...(resolvedMenuPath ? {menuPath: resolvedMenuPath} : {}),
        params: params,
        path: normalizedPath,
        query: queryParams,
        hash: hash
      };

      this.showLoading();

      let template = this.resolveTemplate(match.route.template, params);
      let content = await this.loadTemplate(template);

      if (!content) {
        return await this.handleNotFound(normalizedPath, params);
      }

      if (match.route.title) {
        document.title = this.translateTitle(match.route.title);
      }

      await this.render(content);

      const to = {
        path: normalizedPath,
        params: params,
        route: match.route,
        query: queryParams,
        hash: hash
      };
      const from = this.state.previous ? {
        path: this.state.previous.path,
        params: this.state.previous.params,
        route: this.state.previous
      } : null;

      await this.runAfterEachGuards(to, from);

      await EventManager.emit('route:changed', {
        path: match.route.path,
        params: params,
        query: queryParams,
        hash: hash,
        route: match.route,
        previous: this.state.previous,
        isNotFoundPage: normalizedPath === this.config.notFound.path
      });

      setTimeout(() => {
        this.executePageScripts();
      }, 0);

      return true;
    } catch (error) {
      console.error('Route processing failed:', error);
      return false;
    } finally {
      this.hideLoading();
    }
  },

  handleHashChange(event) {
    const path = this.getPath();
    const params = event.state?.params || {};
    // Process route directly without URL manipulation to preserve query params
    this.processRoute(path, params);
  },

  resolvePath(path, params, queryString, hashFragment) {
    let resolvedPath = path;

    Object.entries(params).forEach(([key, value]) => {
      const paramPattern = new RegExp(`:${key}(?=/|$)`);
      resolvedPath = resolvedPath.replace(paramPattern, encodeURIComponent(value));
    });

    resolvedPath = resolvedPath.replace(/:[^/]+/g, '');

    if (!resolvedPath.startsWith('/')) {
      resolvedPath = '/' + resolvedPath;
    }

    if (resolvedPath.length > 1 && resolvedPath.endsWith('/')) {
      resolvedPath = resolvedPath.slice(0, -1);
    }

    // Apply base path and mode-specific formatting
    const base = this.config.base && this.config.base !== '/' ? this.config.base : '';

    if (this.config.mode === 'hash') {
      // For hash mode, return base + /#path?query (ensure trailing slash)
      const basePart = base || '';
      const separator = basePart && !basePart.endsWith('/') ? '/' : '';
      resolvedPath = basePart + separator + '#' + resolvedPath;
      // Append query string inside the hash fragment: #/path?query
      if (queryString) {
        resolvedPath += '?' + queryString;
      }
      // hashFragment is not appended in hash mode (# is already used for the path)
    } else {
      // For history mode, apply base path normally
      if (base && !resolvedPath.startsWith(base)) {
        resolvedPath = base + resolvedPath;
      }
      if (queryString) {
        resolvedPath += '?' + queryString;
      }
      if (hashFragment) {
        resolvedPath += '#' + hashFragment;
      }
    }
    return resolvedPath;
  },

  resolveTemplate(template, params) {
    if (!template) return '';

    return template.replace(/:([\w]+)/g, (match, key) => {
      const value = params[key];
      return value != null ? value : match;
    });
  },

  // Keep template path as logical name - do not apply base path here
  // Base path will be applied later in TemplateManager.loadFromServer()
  applyBaseToTemplate(template) {
    if (!template) return template;

    const t = String(template).trim();
    // inline template - return as-is
    if (t.startsWith('<')) return template;
    // absolute URLs - return as-is
    if (/^(https?:)?\/\//i.test(t)) return template;
    // root-absolute path - return as-is (explicit absolute path)
    if (t.startsWith('/')) return t;

    // For relative template names, return as-is to keep them logical
    // TemplateManager.loadFromServer() will handle base path + templates path resolution
    return t;
  },

  async loadTemplate(template) {
    try {
      if (!template) return '';
      const trimmed = String(template).trim();
      if (trimmed.startsWith('<')) return template;

      const fetchPath = this.applyBaseToTemplate(trimmed);
      return await TemplateManager.loadFromServer(fetchPath);
    } catch (error) {
      throw new Error('Failed to load template');
    }
  },

  async render(content) {
    try {
      const main = document.querySelector(Now.config.mainSelector);
      if (!main) {
        throw new Error(`Main content area not found: ${Now.config.mainSelector} in ${document.location.href}`);
      }

      const componentManager = Now.getManager('component');
      const templateManager = Now.getManager('template');

      if (templateManager) {
        templateManager.cleanup();
      }

      if (componentManager) {
        const componentElements = main.querySelectorAll('[data-component]');
        for (const element of componentElements) {
          const instance = componentManager.instances.get(element);
          if (instance) {
            await componentManager.destroy(element);
          }
        }
      }

      // Destroy element/form instances inside main before replacing content
      try {
        const elementManager = Now.getManager('element') || window.ElementManager;
        const formManager = Now.getManager('form') || window.FormManager;

        if (window.LineItemsManager && typeof LineItemsManager.destroyAll === 'function') {
          LineItemsManager.destroyAll(main);
        }

        if (elementManager) {
          const els = main.querySelectorAll('[data-element]');
          els.forEach(el => {
            try {elementManager.destroyByElement && elementManager.destroyByElement(el);} catch (e) {}
          });
        }

        if (formManager) {
          const forms = main.querySelectorAll('form[data-form]');
          forms.forEach(f => {
            try {formManager.destroyFormByElement && formManager.destroyFormByElement(f);} catch (e) {}
          });
        }
      } catch (e) {
        console.warn('RouterManager: cleanup before render failed', e);
      }

      main.innerHTML = '';
      main.innerHTML = content;

      if (componentManager) {
        await componentManager.initializeExistingElements();
      }

      // After components initialized, scan for elements/forms to enhance (lifecycle-first)
      try {
        const elementManager = Now.getManager('element') || window.ElementManager;
        const formManager = Now.getManager('form') || window.FormManager;

        if (elementManager && typeof elementManager.scan === 'function') {
          elementManager.scan(main);
        }

        if (formManager && typeof formManager.scan === 'function') {
          formManager.scan(main);
        }
      } catch (e) {
        console.warn('RouterManager: post-render scan failed', e);
      }

      const i18n = Now.getManager('i18n');
      if (i18n && i18n.config.enabled) {
        const currentLocale = i18n.getCurrentLocale();
        await i18n.setLocale(currentLocale, true);
      }

      await EventManager.emit('content:rendered', {
        path: this.getPath(),
        main
      });
    } catch (error) {
      ErrorManager.handle('Failed to render content', {
        context: 'RouterManager.render',
        data: {content}
      });
      throw error;
    }
  },

  showLoading() {
    this.state.loading = true;
    document.body.classList.add('loading');
  },

  hideLoading() {
    this.state.loading = false;
    document.body.classList.remove('loading');
  },

  extractText(container, tag) {
    try {
      const node = container.querySelector(tag);
      if (!node) return null;

      const cloned = node.cloneNode(true);
      cloned.querySelectorAll('.icon, .badge, .hidden').forEach(el => {
        el.remove();
      });

      const text = cloned.textContent.trim();

      const i18n = Now?.getManager('i18n');
      if (i18n?.enabled) {
        return i18n.translate(text) || text;
      }

      return text;
    } catch (error) {
      this.handleError('Text extraction failed', error);
      return null;
    }
  },

  async handleNotFound(path, params = {}) {
    this.state.current = null;
    this.hideLoading();
    this.state.error = new Error('Page not found');

    const config = this.config.notFound;
    if (config.title) {
      document.title = this.translateTitle(config.title);
    }

    await EventManager.emit('route:notFound', {
      path,
      params,
      redirectTo: this.config.notFound.path
    });

    if (typeof config.customHandler === 'function') {
      const result = await config.customHandler(path, params);
      if (result !== undefined) return result;
    }

    switch (config.behavior) {
      case 'redirect':
        if (!config.preserveUrl) {
          const currentQuery = window.location.search;
          const targetPath = config.preserveQuery && currentQuery
            ? `${config.path}${currentQuery}`
            : config.path;

          return await this.navigate(targetPath, {
            originalPath: path,
            ...params
          }, {replace: true});
        }
        break;

      case 'render':
        try {
          const content = await this.loadTemplate(config.template);
          if (content) {
            await this.render(content);
            await EventManager.emit('route:changed', {
              path,
              params,
              isError: true
            });
            return true;
          }
        } catch (error) {
          await ErrorManager.handle('Failed to render 404 template page', {
            context: 'RouterManager.handleNotFound',
            type: 'route:changed',
            data: {
              path,
              params
            }
          });
        }
        break;

      case 'preserve':
        NotificationManager.error('Page not found');
        return false;
    }

    return this.renderDefault404Page(path);
  },

  renderDefault404Page(path) {
    const mainElement = document.querySelector(Now.config.mainSelector);
    if (!mainElement) return false;

    const errorPageHTML = `
        <div class="error-container">
            <div class="error-card">
              <h1 class="error-code-number">404</h1>
              <p class="error-message" data-i18n>The page you're looking for doesn't exist.</p>

              <div class="error-details">
                <div class="error-code-heading">Path:</div>
                <pre class="error-code">${this.escapeHtml(path)}</pre>
              </div>

              <div class="error-actions">
                <button class="btn text icon-reset" onclick="window.location.reload()">
                  Reload Page
                </button>
                <button class="btn btn-primary icon-home" onclick="window.location.href='/'">
                  Go to Homepage
                </button>
              </div>

              <div class="error-help">
                If the problem persists, please contact your system administrator or try clearing your browser cache.
              </div>
            </div>
          </div>
        `;

    mainElement.innerHTML = errorPageHTML;
    return false;
  },

  escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  },

  handleError(message, error) {
    console.error(`[RouterManager] ${message}:`, error);

    if (window.ErrorManager) {
      return ErrorManager.handle(error, {
        context: 'RouterManager',
        type: 'router:error',
        data: {message}
      });
    }

    return error;
  },

  getParams() {
    const path = this.getPath();
    return this.params.get(path) || {};
  },

  getParam(name) {
    const params = this.getParams();
    return params[name] || null;
  },

  hasRoute(path) {
    return this.routes.has(path);
  },

  getRoute(path) {
    return this.routes.get(path) || null;
  },

  getRoutes() {
    return Array.from(this.routes.entries()).map(([path, config]) => ({
      path,
      ...config
    }));
  },

  /**
   * Translate title with {LNG_...} pattern support
   * @param {string} title - Title string that may contain {LNG_...} patterns
   * @returns {string} Translated title
   */
  translateTitle(title) {
    if (!title || typeof title !== 'string') return title;

    // Check if title contains {LNG_...} pattern
    if (/{LNG_[^}]+}/.test(title)) {
      const i18n = Now.getManager('i18n');
      if (i18n?.interpolate) {
        return i18n.interpolate(title, {});
      }
    }

    return title;
  }
};

if (window.Now?.registerManager) {
  Now.registerManager('router', RouterManager);
}

// Expose globally
window.RouterManager = RouterManager;
