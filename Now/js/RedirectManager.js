/**
 * RedirectManager - Manage redirects across the application
 */
const RedirectManager = {
  /**
   * Redirect types
   */
  types: {
    AUTH_REQUIRED: 'auth_required',
    FORBIDDEN: 'forbidden',
    AFTER_LOGIN: 'after_login',
    AFTER_LOGOUT: 'after_logout',
    GUEST_ONLY: 'guest_only',
    MAINTENANCE: 'maintenance',
    NOT_FOUND: 'not_found'
  },

  /**
   * Default redirect configurations
   */
  defaults: {
    auth_required: {
      target: '/login',
      preserveQuery: true,
      preserveHash: true,
      storeIntended: true,
      message: 'Please login to continue',
      showNotification: true
    },
    forbidden: {
      target: '/403',
      preserveQuery: false,
      preserveHash: false,
      storeIntended: false,
      message: 'Permission required',
      showNotification: true
    },
    after_login: {
      target: '/',
      preserveQuery: false,
      preserveHash: false,
      storeIntended: false,
      message: null,
      showNotification: false,
      checkIntended: true
    },
    after_logout: {
      target: '/login',
      preserveQuery: false,
      preserveHash: false,
      storeIntended: false,
      message: 'You have been logged out',
      showNotification: true
    },
    guest_only: {
      target: '/',
      preserveQuery: false,
      preserveHash: false,
      storeIntended: false,
      message: 'You are already logged in',
      showNotification: false
    },
    maintenance: {
      target: '/maintenance',
      preserveQuery: false,
      preserveHash: false,
      storeIntended: true,
      message: 'Site is under maintenance',
      showNotification: true
    },
    not_found: {
      target: '/404',
      preserveQuery: false,
      preserveHash: false,
      storeIntended: false,
      message: null,
      showNotification: false
    }
  },

  /**
   * Perform redirect with configuration
   */
  async redirect(type, options = {}) {
    try {
      const config = this.getRedirectConfig(type, options);

      // Log redirect for debugging
      if (window.RouterManager?.config?.debug) {
        if (this.config?.debug) console.info(`[RedirectManager] Redirecting:`, {
          type,
          config,
          from: window.location.pathname
        });
      }

      // Store intended route if needed (client-side or optionally server-side)
      if (config.storeIntended) {
        this.storeIntendedRoute();
      }

      // Show notification if configured (use whichever notifier is available)
      if (config.showNotification && config.message) {
        const notificationType = this.getNotificationType(type);
        if (window.AuthErrorHandler?.showNotification) {
          AuthErrorHandler.showNotification(config.message, notificationType);
        } else if (window.NotificationManager?.[notificationType]) {
          NotificationManager[notificationType](config.message);
        }
      }

      // Determine target URL
      const targetUrl = await this.resolveTarget(config, options);

      // Emit redirect event
      this.emitRedirectEvent(type, config, targetUrl);

      // Perform the redirect
      const result = await this.performRedirect(targetUrl, config, options);

      return {
        success: true,
        type,
        target: targetUrl,
        config,
        result
      };

    } catch (error) {
      console.error(`[RedirectManager] Redirect failed:`, error);

      // Fallback to basic redirect
      return this.performFallbackRedirect(type, options);
    }
  },

  /**
   * Get redirect configuration
   */
  getRedirectConfig(type, options) {
    const defaultConfig = this.defaults[type] || this.defaults.not_found;
    const routerConfig = window.RouterManager?.config?.auth?.redirects || {};

    // App configs declare redirect targets with camelCase keys and plain string
    // values (e.g. afterLogin: '/projects/'). Map our internal snake_case types
    // onto those keys so a single config drives every redirect.
    const keyMap = {
      auth_required: 'unauthenticated',
      forbidden: 'forbidden',
      after_login: 'afterLogin',
      after_logout: 'afterLogout',
      guest_only: 'afterLogin'
    };
    // auth_required: RouterManager reads `unauthenticated`, then `unauthorized`
    const configured = type === 'auth_required'
      ? (routerConfig.unauthenticated || routerConfig.unauthorized)
      : routerConfig[keyMap[type]];
    const typeConfig = (typeof configured === 'string' && configured !== '')
      ? {target: configured}
      : (configured && typeof configured === 'object' ? configured : {});

    return {
      ...defaultConfig,
      ...typeConfig,
      ...options
    };
  },

  /**
   * Resolve target URL with parameters and query
   */
  async resolveTarget(config, options) {
    let target = config.target;

    // Check for intended route (for after_login). Consume it (clear) so a later
    // login doesn't reuse a stale destination.
    if (config.checkIntended) {
      const intended = this.getIntendedRoute();
      if (intended) {
        this.clearIntendedRoute();
        target = intended.path;

        // Add query parameters if preserved
        if (intended.query && config.preserveQuery) {
          target += intended.query;
        }

        // Add hash if preserved
        if (intended.hash && config.preserveHash) {
          target += intended.hash;
        }

        return target;
      }
    }

    // Add current query if preserved
    if (config.preserveQuery) {
      const currentQuery = window.location.search;
      if (currentQuery) {
        target += (target.includes('?') ? '&' : '?') + currentQuery.slice(1);
      }
    }

    // Add current hash if preserved
    if (config.preserveHash) {
      const currentHash = window.location.hash;
      if (currentHash) {
        target += currentHash;
      }
    }

    // Add custom parameters
    if (options.params && Object.keys(options.params).length > 0) {
      const paramString = new URLSearchParams(options.params).toString();
      target += (target.includes('?') ? '&' : '?') + paramString;
    }

    return target;
  },

  /**
   * Perform the actual redirect
   */
  async performRedirect(targetUrl, config, options) {
    const result = await this.go(targetUrl, {
      replace: options.replace !== false,
      useRouter: options.useRouter
    });

    return {...result, target: targetUrl};
  },

  /**
   * The router base without a trailing slash ('' when the app sits at the root).
   *
   * Read from RouterManager.config.base, which RouterManager.init() normalizes
   * and keeps even when the router is disabled, then from Now.getBasePath().
   *
   * @returns {string}
   */
  getBase() {
    const base = window.RouterManager?.config?.base || window.Now?.getBasePath?.() || '/';
    const b = String(base).replace(/\/+$/, '');
    return b === '' ? '' : (b.startsWith('/') ? b : '/' + b);
  },

  /**
   * Turns a route path into the URL the address bar needs.
   *
   * Redirect targets are route paths ('/login', '/403'), relative to the router
   * base. The router adds the base itself; a full page load does not, so a
   * root-absolute path gets the base in front of it unless it already starts
   * with the base. Absolute URLs and relative paths are returned unchanged.
   *
   * @param {string} target - Route path, path under the base, or URL
   * @returns {string} URL to assign to window.location
   */
  resolveUrl(target) {
    const t = String(target ?? '');
    if (!t.startsWith('/') || t.startsWith('//')) return t;

    const base = this.getBase();
    if (!base) return t;

    const rest = t.slice(base.length);
    if (t.startsWith(base) && (rest === '' || /^[/?#]/.test(rest))) return t;

    return base + t;
  },

  /**
   * Navigates to a route path: through the router when it is running (SPA),
   * otherwise with a full page load to resolveUrl(target).
   *
   * @param {string} target - Route path such as '/login' or '/403?message=x'
   * @param {Object} [options={}]
   * @param {boolean} [options.replace=false] - Replace the current history entry
   * @param {boolean} [options.useRouter] - false forces a full page load
   * @returns {Promise<{method: string, success: boolean}>} method is 'router'
   *   or 'location'; success is what RouterManager.navigate() returned
   */
  async go(target, options = {}) {
    const router = window.RouterManager;

    if (options.useRouter !== false && router?.state?.initialized && typeof router.navigate === 'function') {
      try {
        // The router navigates by route path (relative to its base); a target
        // that already carries the base is accepted by navigate() as well.
        const success = await router.navigate(target, {}, {
          replace: !!options.replace,
          source: 'redirect'
        });
        return {method: 'router', success};
      } catch (error) {
        console.warn('[RedirectManager] Router navigation failed, using location:', error);
      }
    }

    this.assignLocation(target, options.replace);
    return {method: 'location', success: true};
  },

  /**
   * Full page navigation to a route path, with the base applied.
   *
   * @param {string} target - Route path or URL
   * @param {boolean} [replace=false] - Replace the current history entry
   * @returns {void}
   */
  assignLocation(target, replace = false) {
    const url = this.resolveUrl(target);
    if (replace) {
      window.location.replace(url);
    } else {
      window.location.href = url;
    }
  },

  /**
   * Leaves the page after a data request was answered 401 or 403: the login
   * page for 401 (the current page is stored as the intended route) and the
   * forbidden page for 403, with the server's message as ?message=.
   *
   * FormManager, ApiComponent and TableManager all go through here, so the
   * targets come from router.auth.redirects and the base is applied once.
   *
   * @param {number} status - HTTP status of the response
   * @param {Object} [options={}]
   * @param {string} [options.message=''] - Message from the server (403)
   * @returns {boolean} True when the status was 401 or 403 and a redirect started
   */
  redirectForStatus(status, options = {}) {
    if (status === 403) {
      const message = options.message ? String(options.message) : '';
      this.forbidden({
        showNotification: false,
        params: message ? {message} : {}
      });
      return true;
    }

    if (status === 401) {
      this.requireAuth({
        showNotification: false,
        preserveQuery: false,
        preserveHash: false
      });
      return true;
    }

    return false;
  },

  /**
   * Store current route as intended
   */
  async storeIntendedRoute() {
    try {
      const currentPath = window.location.pathname || '/';

      // avoid storing pages that shouldn't be intended (login/register/etc.)
      try {
        if (!this.shouldStoreAsIntended(currentPath)) return;
      } catch (e) {
        // fall back to storing if shouldStoreAsIntended fails
      }

      const intendedRoute = {
        path: window.location.pathname,
        query: window.location.search,
        hash: window.location.hash,
        timestamp: Date.now()
      };
      // Store client-side by default
      try {
        sessionStorage.setItem('auth_intended_route', JSON.stringify(intendedRoute));
      } catch (e) {
        // ignore
      }
    } catch (error) {
      console.warn('[RedirectManager] Failed to store intended route:', error);
    }
  },

  /**
   * Get stored intended route
   */
  getIntendedRoute() {
    // 1) Explicit ?redirect= / ?return_to= on the current URL (deep links, or a
    //    user sent to /login?redirect=... directly).
    try {
      const params = new URLSearchParams(window.location.search);
      const q = params.get('redirect') || params.get('return_to');
      if (q && this.isSafeIntendedPath(q)) {
        return {path: q, query: '', hash: ''};
      }
    } catch (e) { /* ignore */ }

    // 2) Route stored by the auth guard / login flow when the user was bounced.
    try {
      const stored = sessionStorage.getItem('auth_intended_route');
      if (!stored) return null;

      const route = JSON.parse(stored);

      // Check if route is not too old (1 hour)
      const maxAge = 60 * 60 * 1000;
      if (Date.now() - route.timestamp > maxAge) {
        this.clearIntendedRoute();
        return null;
      }

      return (route && this.isSafeIntendedPath(route.path)) ? route : null;
    } catch (error) {
      console.warn('[RedirectManager] Failed to get intended route:', error);
      return null;
    }
  },

  /**
   * A path is safe to redirect to only if it is a same-origin path and is not
   * itself an auth page (prevents open-redirects and login loops).
   *
   * The path is resolved the way the browser resolves it: a backslash or a
   * control character (tab, newline) is refused outright — `/\evil.example`
   * and `/<tab>/evil.example` both lead to another host — and the resolved URL
   * must keep this page's origin with a path that does not start with `//`
   * (`/..//evil.example` resolves to the path `//evil.example`).
   *
   * @param {string} path - Path such as '/orders?id=1'
   * @returns {boolean}
   */
  isSafeIntendedPath(path) {
    if (typeof path !== 'string' || path === '') return false;
    if (/[\\\u0000-\u001f\u007f]/.test(path)) return false; // backslash or control character
    if (!path.startsWith('/') || path.startsWith('//')) return false; // a path on this site
    try {
      const url = new URL(path, window.location.origin);
      if (url.origin !== window.location.origin) return false;
      // /..//evil.example stays here but lands on the path //evil.example, which
      // anything using location.pathname as a URL would take to another site
      if (url.pathname.startsWith('//')) return false;
    } catch (e) {
      return false;
    }
    return this.shouldStoreAsIntended(path);              // not /login, /register, /logout, ...
  },

  /**
   * Clear stored intended route
   */
  clearIntendedRoute() {
    try {
      sessionStorage.removeItem('auth_intended_route');
    } catch (error) {
      console.warn('[RedirectManager] Failed to clear intended route:', error);
    }
  },

  /**
   * Get notification type for redirect type
   */
  getNotificationType(redirectType) {
    const typeMap = {
      auth_required: 'warning',
      forbidden: 'error',
      after_login: 'success',
      after_logout: 'info',
      guest_only: 'info',
      maintenance: 'warning',
      not_found: 'error'
    };

    return typeMap[redirectType] || 'info';
  },

  /**
   * Emit redirect event
   */
  emitRedirectEvent(type, config, targetUrl) {
    const eventManager = window.EventManager;
    if (eventManager?.emit) {
      eventManager.emit('auth:redirect', {
        type,
        config,
        target: targetUrl,
        from: window.location.pathname,
        timestamp: Date.now()
      });
    }
  },

  /**
   * Perform fallback redirect
   */
  performFallbackRedirect(type, options) {
    const fallbackTargets = {
      auth_required: '/login',
      forbidden: '/403',
      after_login: '/',
      after_logout: '/login',
      guest_only: '/',
      maintenance: '/maintenance',
      not_found: '/404'
    };

    const target = fallbackTargets[type] || '/';
    window.location.href = this.resolveUrl(target);

    return {
      success: true,
      type,
      target,
      method: 'fallback'
    };
  },

  /**
   * Check if current route should be stored as intended
   */
  shouldStoreAsIntended(currentPath) {
    const skipPatterns = [
      '/login',
      '/register',
      '/logout',
      '/auth/',
      '/403',
      '/404',
      '/500',
      '/maintenance'
    ];

    return !skipPatterns.some(pattern => currentPath.includes(pattern));
  },

  /**
   * Redirect shortcuts for common scenarios
   */
  async requireAuth(options = {}) {
    return this.redirect(this.types.AUTH_REQUIRED, options);
  },

  /**
   * Redirect for a forbidden resource.
   *
   * @param {Object} [options={}] - Passed through to `redirect`.
   * @returns {Promise<*>} - Result of the redirect.
   */
  async forbidden(options = {}) {
    return this.redirect(this.types.FORBIDDEN, options);
  },

  /**
   * Redirect to wherever the user belongs after signing in.
   *
   * @param {Object} [options={}] - Passed through to `redirect`.
   * @returns {Promise<*>} - Result of the redirect.
   */
  async afterLogin(options = {}) {
    return this.redirect(this.types.AFTER_LOGIN, options);
  },

  /**
   * Redirect to wherever the user belongs after signing out.
   *
   * @param {Object} [options={}] - Passed through to `redirect`.
   * @returns {Promise<*>} - Result of the redirect.
   */
  async afterLogout(options = {}) {
    return this.redirect(this.types.AFTER_LOGOUT, options);
  },

  /**
   * Redirect a signed-in user away from a guest-only page.
   *
   * @param {Object} [options={}] - Passed through to `redirect`.
   * @returns {Promise<*>} - Result of the redirect.
   */
  async guestOnly(options = {}) {
    return this.redirect(this.types.GUEST_ONLY, options);
  }
};

window.RedirectManager = RedirectManager;
