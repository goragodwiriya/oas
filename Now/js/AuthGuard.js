/**
 * AuthGuard - Router authentication guard system
 */
const AuthGuard = {
  state: {
    checking: false,
    lastCheck: null,
    checkPromise: null
  },

  /**
   * Main guard function - checks authentication before navigation
   */
  async checkRoute(to, from, router) {
    try {
      // Start route check loading if not silent route
      let loadingId = null;
      if (!to.metadata?.silent) {
        loadingId = AuthLoadingManager.startLoading(
          AuthLoadingManager.operationTypes.ROUTE_CHECK,
          {
            message: 'Verifying access...',
            silent: to.metadata?.silentAuth || false
          }
        );
      }

      const result = await this.performCheck(to, from, router);

      // Complete loading
      if (loadingId) {
        AuthLoadingManager.completeLoading(loadingId, {
          success: result.allowed,
          reason: result.reason
        });
      }

      return result;

    } catch (error) {
      console.error('[AuthGuard] Check failed:', error);

      // Complete loading with error
      if (loadingId) {
        AuthLoadingManager.completeLoading(loadingId, {
          success: false,
          error: error.message
        });
      }

      return {
        allowed: false,
        action: 'redirect',
        target: router.config.auth?.redirects?.unauthorized || '/login',
        reason: 'Guard check failed',
        error
      };
    }
  },

  /**
   * Perform the actual authentication check
   */
  async performCheck(to, from, router) {
    const metadata = to.metadata;
    const authManager = window.Now?.getManager('auth');

    // Skip check if auth is disabled
    if (!router.config.auth?.enabled) {
      return {allowed: true, reason: 'Auth disabled'};
    }

    // Skip check for non-auth routes (API, assets, etc.)
    if (this.shouldSkipRoute(to.path, router.config.auth)) {
      return {allowed: true, reason: 'Route skipped'};
    }

    // Check if route is public
    if (metadata.public) {
      return {allowed: true, reason: 'Public route'};
    }

    // Get authentication status
    const authState = await this.getAuthState(authManager);

    // Handle guest-only routes
    if (metadata.guestOnly && authState.isAuthenticated) {
      const redirectTarget = metadata.redirectOnAuth ||
        router.config.auth?.redirects?.afterLogin ||
        '/';
      return {
        allowed: false,
        action: 'redirect',
        target: redirectTarget,
        reason: 'Guest-only route, user is authenticated'
      };
    }

    // Check authentication requirement
    const requiresAuth = RouteMetadataHandler.getAuthRequirement(
      metadata,
      router.config.auth?.defaultRequireAuth || false
    );

    if (requiresAuth && !authState.isAuthenticated) {
      // Store intended route for after login
      this.storeIntendedRoute(to, router);

      return {
        allowed: false,
        action: 'redirect',
        target: router.config.auth?.redirects?.unauthorized || '/login',
        reason: 'Authentication required',
        preserveQuery: metadata.preserveQuery
      };
    }

    // Check role requirements
    if (metadata.roles.length > 0 && authState.isAuthenticated) {
      const hasRole = this.checkRoles(metadata.roles, authState.roles);
      if (!hasRole) {
        return {
          allowed: false,
          action: 'forbidden',
          target: router.config.auth?.redirects?.forbidden || '/403',
          reason: `Required roles: ${metadata.roles.join(', ')}`
        };
      }
    }

    // Check permission requirements
    if (metadata.permissions.length > 0 && authState.isAuthenticated) {
      const hasPermission = this.checkPermissions(metadata.permissions, authState.permissions);
      if (!hasPermission) {
        return {
          allowed: false,
          action: 'forbidden',
          target: router.config.auth?.redirects?.forbidden || '/403',
          reason: `Required permissions: ${metadata.permissions.join(', ')}`
        };
      }
    }

    // Run custom validation if exists
    if (metadata.validate && typeof metadata.validate === 'function') {
      try {
        const customResult = await metadata.validate(authState, to, from);
        if (customResult === false) {
          return {
            allowed: false,
            action: 'forbidden',
            target: router.config.auth?.redirects?.forbidden || '/403',
            reason: 'Custom validation failed'
          };
        }

        // Handle custom result object
        if (typeof customResult === 'object' && customResult.allowed === false) {
          return customResult;
        }
      } catch (error) {
        console.error('[AuthGuard] Custom validation error:', error);
        return {
          allowed: false,
          action: 'forbidden',
          target: router.config.auth?.redirects?.forbidden || '/403',
          reason: 'Custom validation error'
        };
      }
    }

    // All checks passed
    return {allowed: true, reason: 'All checks passed'};
  },

  /**
   * Get current authentication state
   */
  async getAuthState(authManager) {
    if (!authManager) {
      return {
        isAuthenticated: false,
        userId: null,
        roles: [],
        permissions: []
      };
    }

    // Prevent multiple simultaneous checks
    if (this.state.checking && this.state.checkPromise) {
      return await this.state.checkPromise;
    }

    this.state.checking = true;

    // Start auth verification loading (silent)
    const loadingId = AuthLoadingManager.startLoading(
      AuthLoadingManager.operationTypes.VERIFY,
      {
        message: 'Verifying session...',
        silent: true
      }
    );

    this.state.checkPromise = this.performAuthStateCheck(authManager, loadingId);

    try {
      const result = await this.state.checkPromise;
      this.state.lastCheck = Date.now();

      AuthLoadingManager.completeLoading(loadingId, {
        success: result.isAuthenticated
      });

      return result;
    } finally {
      this.state.checking = false;
      this.state.checkPromise = null;
    }
  },

  /**
   * Perform authentication state check
   */
  async performAuthStateCheck(authManager, loadingId) {
    try {
      // Check if we need to verify with server
      const shouldVerify = this.shouldVerifyAuth();

      if (shouldVerify) {
        AuthLoadingManager.updateLoading(loadingId, {
          message: 'Checking with server...'
        });

        const verified = await authManager.verifyAuthState();
        if (!verified) {
          return {
            isAuthenticated: false,
            userId: null,
            roles: [],
            permissions: []
          };
        }
      }

      return {
        isAuthenticated: authManager.isAuthenticated(),
        userId: authManager.getUserId(),
        roles: authManager.getRoles(),
        permissions: authManager.getPermissions(),
        user: authManager.getUser()
      };

    } catch (error) {
      console.error('[AuthGuard] Auth state check failed:', error);
      return {
        isAuthenticated: false,
        userId: null,
        roles: [],
        permissions: []
      };
    }
  },

  /**
   * Check if we should verify auth state with server
   */
  shouldVerifyAuth() {
    const verifyInterval = 5 * 60 * 1000; // 5 minutes
    return !this.state.lastCheck ||
      (Date.now() - this.state.lastCheck) > verifyInterval;
  },

  /**
   * Check if route should be skipped
   */
  shouldSkipRoute(path, authConfig) {
    const skipPatterns = authConfig.skipPatterns || [
      '/api/*',
      '/assets/*',
      '/static/*',
      '*.js',
      '*.css',
      '*.png',
      '*.jpg',
      '*.svg'
    ];

    return skipPatterns.some(pattern => {
      const regex = new RegExp(pattern.replace(/\*/g, '.*'));
      return regex.test(path);
    });
  },

  /**
   * Check if user has required roles
   */
  checkRoles(requiredRoles, userRoles) {
    if (!requiredRoles || requiredRoles.length === 0) return true;
    if (!userRoles || userRoles.length === 0) return false;

    // Check if user has any of the required roles
    return requiredRoles.some(role => userRoles.includes(role));
  },

  /**
   * Check if user has required permissions
   */
  checkPermissions(requiredPermissions, userPermissions) {
    if (!requiredPermissions || requiredPermissions.length === 0) return true;
    if (!userPermissions || userPermissions.length === 0) return false;

    // Check if user has all required permissions
    return requiredPermissions.every(permission =>
      userPermissions.includes(permission)
    );
  },

  /**
   * Store intended route for redirect after login
   */
  storeIntendedRoute(route, router) {
    try {
      const intendedRoute = {
        path: route.path,
        params: route.params || {},
        query: router.getQuery(),
        timestamp: Date.now()
      };

      sessionStorage.setItem('auth_intended_route', JSON.stringify(intendedRoute));
    } catch (error) {
      console.warn('[AuthGuard] Failed to store intended route:', error);
    }
  },

  /**
   * Get and clear stored intended route
   */
  getIntendedRoute() {
    try {
      const stored = sessionStorage.getItem('auth_intended_route');
      if (stored) {
        sessionStorage.removeItem('auth_intended_route');
        const route = JSON.parse(stored);

        // Check if route is not too old (1 hour)
        const maxAge = 60 * 60 * 1000;
        if (Date.now() - route.timestamp < maxAge) {
          return route;
        }
      }
    } catch (error) {
      console.warn('[AuthGuard] Failed to get intended route:', error);
    }

    return null;
  },

  // Improved in AuthGuard.handleGuardFailure
  async handleGuardFailure(guardResult, route, options, router) {
    try {
      const {action, target, reason} = guardResult;

      // Create error context
      const errorContext = {
        router,
        currentPath: route.path,
        guardResult,
        retryOriginalRequest: options.retryCallback,
        logContext: {
          route: route.path,
          reason,
          timestamp: Date.now()
        }
      };

      // Map guard result to error type
      const errorType = this.mapGuardResultToErrorType(guardResult);

      // Create mock error for handler
      const mockError = {
        message: reason,
        status: action === 'forbidden' ? 403 : 401,
        type: 'auth_guard',
        guardResult
      };

      // Use AuthErrorHandler for comprehensive error handling
      const handlingResult = await AuthErrorHandler.handleError(mockError, {
        ...errorContext,
        customConfig: {
          action,
          target,
          preservePath: guardResult.preserveQuery
        }
      });

      return handlingResult.result;

    } catch (error) {
      console.error('[AuthGuard] Guard failure handling error:', error);

      // Fallback to simple redirect
      return RedirectManager.performFallbackRedirect(
        guardResult.action === 'forbidden' ? 'forbidden' : 'auth_required',
        {target: guardResult.target}
      );
    }
  },

  /**
  * Map guard result to error type
  */
  mapGuardResultToErrorType(guardResult) {
    const {action, reason} = guardResult;

    if (reason?.includes('token') && reason?.includes('expired')) {
      return AuthErrorHandler.errorTypes.TOKEN_EXPIRED;
    }

    if (reason?.includes('token') && reason?.includes('invalid')) {
      return AuthErrorHandler.errorTypes.TOKEN_INVALID;
    }

    if (reason?.includes('session')) {
      return AuthErrorHandler.errorTypes.SESSION_EXPIRED;
    }

    if (reason?.includes('roles') || reason?.includes('permissions')) {
      return AuthErrorHandler.errorTypes.INSUFFICIENT_PRIVILEGES;
    }

    if (reason?.includes('custom validation')) {
      return AuthErrorHandler.errorTypes.CUSTOM_VALIDATION;
    }

    switch (action) {
      case 'forbidden':
        return AuthErrorHandler.errorTypes.FORBIDDEN;
      case 'redirect':
        return AuthErrorHandler.errorTypes.UNAUTHORIZED;
      default:
        return AuthErrorHandler.errorTypes.UNAUTHORIZED;
    }
  },

  /**
   * Clear auth state cache
   */
  clearCache() {
    this.state.lastCheck = null;
    this.state.checking = false;
    this.state.checkPromise = null;
  }
};

// Expose globally
window.AuthGuard = AuthGuard;
