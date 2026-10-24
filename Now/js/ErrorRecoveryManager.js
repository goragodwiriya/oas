/**
 * ErrorRecoveryManager - manages error recovery strategies
 */
const ErrorRecoveryManager = {
  /**
   * Recovery strategies
   */
  strategies: {
    RETRY: 'retry',
    REFRESH_TOKEN: 'refresh_token',
    RELOAD_PAGE: 'reload_page',
    CLEAR_CACHE: 'clear_cache',
    FALLBACK_ROUTE: 'fallback_route',
    OFFLINE_MODE: 'offline_mode'
  },

  /**
   * Error recovery configurations
   */
  recoveryConfigs: {
    network_error: [
      {strategy: 'retry', maxAttempts: 3, delay: 2000},
      {strategy: 'offline_mode', condition: () => !navigator.onLine},
      {strategy: 'reload_page', delay: 5000}
    ],
    token_expired: [
      {strategy: 'refresh_token', maxAttempts: 1},
      {strategy: 'fallback_route', target: '/login'}
    ],
    server_error: [
      {strategy: 'retry', maxAttempts: 2, delay: 3000},
      {strategy: 'fallback_route', target: '/error'}
    ],
    rate_limited: [
      {strategy: 'retry', maxAttempts: 1, delay: 'header'}
    ]
  },

  /**
   * Attempt error recovery
   */
  async attemptRecovery(errorInfo, context = {}) {
    const configs = this.recoveryConfigs[errorInfo.type] || [];

    for (const config of configs) {
      try {
        // Inspection if specified
        if (config.condition && !config.condition()) {
          continue;
        }

        const result = await this.executeRecoveryStrategy(config, errorInfo, context);

        if (result.success) {
          return {
            recovered: true,
            strategy: config.strategy,
            result
          };
        }

      } catch (error) {
        console.warn(`[ErrorRecovery] Strategy ${config.strategy} failed:`, error);
      }
    }

    return {recovered: false};
  },

  /**
   * Execute recovery strategy
   */
  async executeRecoveryStrategy(config, errorInfo, context) {
    const {strategy} = config;

    switch (strategy) {
      case this.strategies.RETRY:
        return await this.retryOperation(config, errorInfo, context);

      case this.strategies.REFRESH_TOKEN:
        return await this.refreshToken(config, errorInfo, context);

      case this.strategies.RELOAD_PAGE:
        return await this.reloadPage(config, errorInfo, context);

      case this.strategies.CLEAR_CACHE:
        return await this.clearCache(config, errorInfo, context);

      case this.strategies.FALLBACK_ROUTE:
        return await this.navigateToFallback(config, errorInfo, context);

      case this.strategies.OFFLINE_MODE:
        return await this.enableOfflineMode(config, errorInfo, context);

      default:
        return {success: false, reason: 'Unknown strategy'};
    }
  },

  /**
   * Retry operation strategy
   */
  async retryOperation(config, errorInfo, context) {
    const maxAttempts = config.maxAttempts || 3;
    const currentAttempt = context.retryCount || 0;

    if (currentAttempt >= maxAttempts) {
      return {success: false, reason: 'Max retry attempts reached'};
    }

    // Calculate delay
    let delay = config.delay || 1000;
    if (delay === 'header' && errorInfo.details?.retryAfter) {
      delay = errorInfo.details.retryAfter * 1000;
    }

    // Wait before retry
    await new Promise(resolve => setTimeout(resolve, delay));

    // Retry original request if available
    if (context.retryOriginalRequest) {
      try {
        const result = await context.retryOriginalRequest();
        return {success: result.success || false, attempts: currentAttempt + 1};
      } catch (error) {
        return {success: false, error, attempts: currentAttempt + 1};
      }
    }

    return {success: false, reason: 'No retry function available'};
  },

  /**
   * Refresh token strategy
   */
  async refreshToken(config, errorInfo, context) {
    const authManager = Now.getManager('auth');
    if (!authManager) {
      return {success: false, reason: 'No auth manager available'};
    }

    try {
      const refreshed = await authManager.refreshToken();
      if (refreshed && context.retryOriginalRequest) {
        const retryResult = await context.retryOriginalRequest();
        return {success: retryResult.success || false, refreshed: true};
      }

      return {success: refreshed, refreshed};
    } catch (error) {
      return {success: false, error};
    }
  },

  /**
   * Reload page strategy
   */
  async reloadPage(config, errorInfo, context) {
    const delay = config.delay || 0;

    if (delay > 0) {
      await new Promise(resolve => setTimeout(resolve, delay));
    }

    window.location.reload();
    return {success: true, reloaded: true};
  },

  /**
   * Clear cache strategy
   */
  async clearCache(config, errorInfo, context) {
    try {
      // Clear various caches
      if (window.caches) {
        const cacheNames = await caches.keys();
        await Promise.all(cacheNames.map(name => caches.delete(name)));
      }

      // Clear auth cache
      if (window.RouterManager?.authGuard) {
        window.RouterManager.authGuard.clearCache();
      }

      // Clear API cache
      if (window.ApiService) {
        window.ApiService.clearCache();
      }

      return {success: true, cleared: true};
    } catch (error) {
      return {success: false, error};
    }
  },

  /**
   * Navigate to fallback route
   */
  async navigateToFallback(config, errorInfo, context) {
    const target = config.target || '/';

    try {
      const result = await RedirectManager.redirect('not_found', {
        target,
        message: `Redirected due to ${errorInfo.type}`
      });

      return {success: result.success, target};
    } catch (error) {
      return {success: false, error};
    }
  },

  /**
   * Enable offline mode
   */
  async enableOfflineMode(config, errorInfo, context) {
    try {
      // Show offline notification
      if (window.NotificationManager) {
        NotificationManager.warning('You are offline. Some features may be limited.', {
          duration: 0,
          id: 'offline-mode'
        });
      }

      // Add offline class to body
      document.body.classList.add('offline-mode');

      // Emit offline event
      if (window.EventManager) {
        EventManager.emit('app:offline', {reason: errorInfo.type});
      }

      return {success: true, offline: true};
    } catch (error) {
      return {success: false, error};
    }
  }
};

window.ErrorRecoveryManager = ErrorRecoveryManager;