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

      // Show notification if configured
      if (config.showNotification && config.message) {
        const notificationType = this.getNotificationType(type);
        AuthErrorHandler.showNotification(config.message, notificationType);
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
    const typeConfig = routerConfig[type] || {};

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

    // Check for intended route (for after_login)
    if (config.checkIntended) {
      const intended = this.getIntendedRoute();
      if (intended) {
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
    const router = window.RouterManager;
    const useRouter = options.useRouter !== false && router;

    if (useRouter) {
      try {
        // Use router for navigation
        const result = await router.navigate(targetUrl, {}, {
          replace: options.replace !== false,
          skipAuthCheck: true,
          source: 'redirect'
        });

        return {
          method: 'router',
          success: result,
          target: targetUrl
        };
      } catch (error) {
        console.warn('[RedirectManager] Router navigation failed, using location:', error);
      }
    }

    // Fallback to location change
    if (options.replace !== false) {
      window.location.replace(targetUrl);
    } else {
      window.location.href = targetUrl;
    }

    return {
      method: 'location',
      success: true,
      target: targetUrl
    };
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

      return route;
    } catch (error) {
      console.warn('[RedirectManager] Failed to get intended route:', error);
      return null;
    }
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
    window.location.href = target;

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

  async forbidden(options = {}) {
    return this.redirect(this.types.FORBIDDEN, options);
  },

  async afterLogin(options = {}) {
    return this.redirect(this.types.AFTER_LOGIN, options);
  },

  async afterLogout(options = {}) {
    return this.redirect(this.types.AFTER_LOGOUT, options);
  },

  async guestOnly(options = {}) {
    return this.redirect(this.types.GUEST_ONLY, options);
  }
};

window.RedirectManager = RedirectManager;
