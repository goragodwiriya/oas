/**
 * AuthErrorHandler - Handles authentication errors
 */
const AuthErrorHandler = {
  /**
   * Error types and their default handling
   */
  errorTypes: {
    UNAUTHORIZED: 'unauthorized',
    FORBIDDEN: 'forbidden',
    TOKEN_EXPIRED: 'token_expired',
    TOKEN_INVALID: 'token_invalid',
    SESSION_EXPIRED: 'session_expired',
    RATE_LIMITED: 'rate_limited',
    ACCOUNT_LOCKED: 'account_locked',
    INSUFFICIENT_PRIVILEGES: 'insufficient_privileges',
    CUSTOM_VALIDATION: 'custom_validation',
    NETWORK_ERROR: 'network_error',
    SERVER_ERROR: 'server_error'
  },

  /**
   * Default error configurations
   */
  defaultConfigs: {
    unauthorized: {
      action: 'redirect',
      target: '/login',
      preservePath: true,
      message: 'Please login to access this page',
      showNotification: false,
      notificationType: 'warning',
      logLevel: 'info',
      retryable: false
    },
    forbidden: {
      action: 'render',
      target: '/403',
      template: null,
      message: 'You do not have permission to access this page',
      showNotification: true,
      notificationType: 'error',
      logLevel: 'warn',
      retryable: false
    },
    token_expired: {
      action: 'refresh_and_retry',
      fallbackAction: 'redirect',
      target: '/login',
      preservePath: true,
      message: 'Your session has expired',
      showNotification: true,
      notificationType: 'info',
      logLevel: 'info',
      retryable: true,
      maxRetries: 1
    },
    token_invalid: {
      action: 'redirect',
      target: '/login',
      preservePath: false,
      message: 'Invalid authentication token',
      showNotification: true,
      notificationType: 'error',
      logLevel: 'warn',
      retryable: false
    },
    session_expired: {
      action: 'redirect',
      target: '/login',
      preservePath: true,
      message: 'Your session has expired. Please login again',
      showNotification: true,
      notificationType: 'warning',
      logLevel: 'info',
      retryable: false
    },
    rate_limited: {
      action: 'block',
      message: 'Too many requests. Please try again later.',
      showNotification: true,
      notificationType: 'warning',
      logLevel: 'warn',
      retryable: true,
      retryDelay: 5000
    },
    account_locked: {
      action: 'redirect',
      target: '/account-locked',
      message: 'Your account has been temporarily locked',
      showNotification: true,
      notificationType: 'error',
      logLevel: 'warn',
      retryable: false
    },
    insufficient_privileges: {
      action: 'render',
      target: '/403',
      message: 'You do not have sufficient privileges',
      showNotification: true,
      notificationType: 'error',
      logLevel: 'warn',
      retryable: false
    },
    custom_validation: {
      action: 'block',
      message: 'Permission required',
      showNotification: true,
      notificationType: 'error',
      logLevel: 'info',
      retryable: false
    },
    network_error: {
      action: 'retry',
      message: 'Network error. Please check your connection',
      showNotification: true,
      notificationType: 'error',
      logLevel: 'error',
      retryable: true,
      maxRetries: 3,
      retryDelay: 2000
    },
    server_error: {
      action: 'render',
      target: '/500',
      message: 'Server error. Please try again later.',
      showNotification: true,
      notificationType: 'error',
      logLevel: 'error',
      retryable: true,
      maxRetries: 1,
      retryDelay: 5000
    }
  },

  /**
   * Handle authentication error
   */
  async handleError(error, context = {}) {
    try {
      const errorInfo = this.analyzeError(error, context);
      const config = this.getErrorConfig(errorInfo.type, context.customConfig);

      // Log the error
      this.logError(errorInfo, config, context);

      // Show notification if enabled
      if (config.showNotification) {
        this.showNotification(config.message, config.notificationType);
      }

      // Emit error event
      this.emitErrorEvent(errorInfo, config, context);

      // Execute error action
      const result = await this.executeErrorAction(errorInfo, config, context);

      return {
        handled: true,
        action: config.action,
        result,
        errorInfo,
        config
      };

    } catch (handlingError) {
      console.error('[AuthErrorHandler] Error handling failed:', handlingError);

      // Fallback to basic error handling
      return this.handleFallbackError(error, context);
    }
  },

  /**
   * Analyze error to determine type and details
   */
  analyzeError(error, context = {}) {
    const errorInfo = {
      originalError: error,
      type: this.errorTypes.SERVER_ERROR,
      message: 'Unknown error',
      status: null,
      details: {},
      timestamp: Date.now(),
      context
    };

    // Analyze HTTP errors
    if (error.status) {
      errorInfo.status = error.status;

      switch (error.status) {
        case 401:
          errorInfo.type = this.determineUnauthorizedType(error);
          errorInfo.message = this.getUnauthorizedMessage(error);
          break;

        case 403:
          errorInfo.type = this.errorTypes.FORBIDDEN;
          errorInfo.message = error.message || 'Access forbidden';
          break;

        case 429:
          errorInfo.type = this.errorTypes.RATE_LIMITED;
          errorInfo.message = 'Too many requests';
          errorInfo.details.retryAfter = this.parseRetryAfter(error);
          break;

        case 423:
          errorInfo.type = this.errorTypes.ACCOUNT_LOCKED;
          errorInfo.message = 'Account locked';
          break;

        default:
          if (error.status >= 500) {
            errorInfo.type = this.errorTypes.SERVER_ERROR;
            errorInfo.message = 'Server error';
          }
          break;
      }
    }

    // Analyze network errors
    else if (error.name === 'NetworkError' || error.message?.includes('network')) {
      errorInfo.type = this.errorTypes.NETWORK_ERROR;
      errorInfo.message = 'Network connection error';
    }

    // Analyze custom validation errors
    else if (context.validationType === 'custom') {
      errorInfo.type = this.errorTypes.CUSTOM_VALIDATION;
      errorInfo.message = error.message || 'Custom validation failed';
    }

    // Add additional details
    if (error.response?.data) {
      errorInfo.details.serverData = error.response.data;
    }

    return errorInfo;
  },

  /**
   * Determine specific unauthorized error type
   */
  determineUnauthorizedType(error) {
    const headers = error.response?.headers || {};
    const data = error.response?.data || {};

    // Check for token expiry indicators
    if (headers['x-token-expired'] === 'true' ||
      data.code === 'TOKEN_EXPIRED' ||
      error.message?.includes('expired')) {
      return this.errorTypes.TOKEN_EXPIRED;
    }

    // Check for invalid token indicators
    if (data.code === 'TOKEN_INVALID' ||
      error.message?.includes('invalid') ||
      error.message?.includes('malformed')) {
      return this.errorTypes.TOKEN_INVALID;
    }

    // Check for session expiry
    if (data.code === 'SESSION_EXPIRED' ||
      error.message?.includes('session')) {
      return this.errorTypes.SESSION_EXPIRED;
    }

    return this.errorTypes.UNAUTHORIZED;
  },

  /**
   * Get appropriate unauthorized message
   */
  getUnauthorizedMessage(error) {
    const data = error.response?.data || {};

    if (data.message) {
      return data.message;
    }

    const type = this.determineUnauthorizedType(error);
    switch (type) {
      case this.errorTypes.TOKEN_EXPIRED:
        return 'Your session has expired';
      case this.errorTypes.TOKEN_INVALID:
        return 'Invalid authentication token';
      case this.errorTypes.SESSION_EXPIRED:
        return 'Your session has expired';
      default:
        return 'Authentication required';
    }
  },

  /**
   * Parse Retry-After header
   */
  parseRetryAfter(error) {
    const retryAfter = error.response?.headers?.['retry-after'];
    if (retryAfter) {
      const seconds = parseInt(retryAfter);
      return isNaN(seconds) ? 60 : seconds;
    }
    return 60;
  },

  /**
   * Get error configuration
   */
  getErrorConfig(errorType, customConfig = {}) {
    const defaultConfig = this.defaultConfigs[errorType] || this.defaultConfigs.server_error;
    return {
      ...defaultConfig,
      ...customConfig
    };
  },

  /**
   * Execute error action
   */
  async executeErrorAction(errorInfo, config, context) {
    const {action} = config;

    switch (action) {
      case 'redirect':
        return await this.handleRedirect(errorInfo, config, context);

      case 'render':
        return await this.handleRender(errorInfo, config, context);

      case 'refresh_and_retry':
        return await this.handleRefreshAndRetry(errorInfo, config, context);

      case 'retry':
        return await this.handleRetry(errorInfo, config, context);

      case 'block':
        return await this.handleBlock(errorInfo, config, context);

      default:
        console.warn(`[AuthErrorHandler] Unknown action: ${action}`);
        return {success: false, reason: 'Unknown action'};
    }
  },

  /**
   * Handle redirect action
   */
  async handleRedirect(errorInfo, config, context) {
    try {
      const router = context.router || window.RouterManager;

      if (!router) {
        window.location.href = config.target;
        return {success: true, method: 'location'};
      }

      // Store current path if preservePath is enabled
      if (config.preservePath && context.currentPath) {
        this.storeIntendedPath(context.currentPath);
      }

      // Perform navigation
      const result = await router.navigate(config.target, {}, {
        replace: true,
        skipAuthCheck: true
      });

      return {
        success: result,
        method: 'router',
        target: config.target
      };

    } catch (error) {
      console.error('[AuthErrorHandler] Redirect failed:', error);

      // Fallback to location change
      window.location.href = config.target;
      return {success: true, method: 'location_fallback'};
    }
  },

  /**
   * Handle render action
   */
  async handleRender(errorInfo, config, context) {
    try {
      const router = context.router || window.RouterManager;

      if (config.template) {
        // Render custom template
        const templateManager = window.Now?.getManager('template');
        if (templateManager) {
          const content = await templateManager.loadFromServer(config.template);
          const main = document.querySelector(window.Now?.config?.mainSelector || '#main');
          if (main) {
            main.innerHTML = content;
            return {success: true, method: 'template'};
          }
        }
      }

      // Navigate to error page
      if (router && config.target) {
        const result = await router.navigate(config.target, {
          error: errorInfo
        }, {
          replace: true,
          skipAuthCheck: true
        });

        return {
          success: result,
          method: 'navigation',
          target: config.target
        };
      }

      // Fallback to basic error display
      this.renderBasicError(errorInfo, config);
      return {success: true, method: 'basic'};

    } catch (error) {
      console.error('[AuthErrorHandler] Render failed:', error);
      this.renderBasicError(errorInfo, config);
      return {success: true, method: 'basic_fallback'};
    }
  },

  /**
   * Handle refresh and retry action
   */
  async handleRefreshAndRetry(errorInfo, config, context) {
    try {
      const authManager = Now.getManager('auth');
      if (!authManager) {
        // No auth manager, fallback to redirect
        return await this.handleRedirect(errorInfo, {
          ...config,
          action: 'redirect'
        }, context);
      }

      // Attempt token refresh
      const refreshed = await authManager.refreshToken();

      if (refreshed && context.retryOriginalRequest) {
        // Retry original request
        const retryResult = await context.retryOriginalRequest();
        if (retryResult.success) {
          return {
            success: true,
            method: 'refresh_and_retry',
            refreshed: true
          };
        }
      }

      // Refresh failed or retry failed, use fallback action
      const fallbackConfig = {
        ...config,
        action: config.fallbackAction || 'redirect'
      };

      return await this.executeErrorAction(errorInfo, fallbackConfig, context);

    } catch (error) {
      console.error('[AuthErrorHandler] Refresh and retry failed:', error);

      // Use fallback action
      const fallbackConfig = {
        ...config,
        action: config.fallbackAction || 'redirect'
      };

      return await this.executeErrorAction(errorInfo, fallbackConfig, context);
    }
  },

  /**
   * Handle retry action
   */
  async handleRetry(errorInfo, config, context) {
    const retryCount = context.retryCount || 0;
    const maxRetries = config.maxRetries || 3;

    if (retryCount >= maxRetries) {
      // Max retries reached, render error
      return await this.handleRender(errorInfo, {
        ...config,
        action: 'render',
        target: '/error'
      }, context);
    }

    // Wait before retry
    if (config.retryDelay) {
      await new Promise(resolve => setTimeout(resolve, config.retryDelay));
    }

    // Attempt retry
    if (context.retryOriginalRequest) {
      try {
        const retryResult = await context.retryOriginalRequest();
        if (retryResult.success) {
          return {
            success: true,
            method: 'retry',
            attempts: retryCount + 1
          };
        }
      } catch (retryError) {
        // Retry failed, try again if under limit
        return await this.handleRetry(errorInfo, config, {
          ...context,
          retryCount: retryCount + 1
        });
      }
    }

    return {
      success: false,
      method: 'retry',
      attempts: retryCount + 1,
      reason: 'No retry function provided'
    };
  },

  /**
   * Handle block action
   */
  async handleBlock(errorInfo, config, context) {
    // Simply block the action and show message
    // The notification should already be shown

    return {
      success: true,
      method: 'block',
      blocked: true
    };
  },

  /**
   * Store intended path for after login
   */
  storeIntendedPath(path) {
    try {
      sessionStorage.setItem('auth_intended_path', path);
    } catch (error) {
      console.warn('[AuthErrorHandler] Failed to store intended path:', error);
    }
  },

  /**
   * Show notification
   */
  showNotification(message, type) {
    const notificationManager = window.NotificationManager;
    if (notificationManager && typeof notificationManager[type] === 'function') {
      notificationManager[type](message, {duration: 5000});
    }
  },

  /**
   * Log error
   */
  logError(errorInfo, config, context) {
    const logLevel = config.logLevel || 'error';
    const logData = {
      type: errorInfo.type,
      message: errorInfo.message,
      status: errorInfo.status,
      context: context.logContext || {},
      timestamp: errorInfo.timestamp
    };

    if (console[logLevel]) {
      console[logLevel]('[AuthErrorHandler]', logData);
    }
  },

  /**
   * Emit error event
   */
  emitErrorEvent(errorInfo, config, context) {
    const eventManager = window.EventManager;
    if (eventManager?.emit) {
      eventManager.emit('auth:error', {
        errorInfo,
        config,
        context,
        timestamp: Date.now()
      });
    }
  },

  /**
   * Render basic error display
   */
  renderBasicError(errorInfo, config) {
    const main = document.querySelector(window.Now?.config?.mainSelector || '#main');
    if (!main) return;

    main.innerHTML = `
      <div class="auth-error-container">
        <div class="auth-error-content">
            <h2>Access Error</h2>
            ${config.message ? `<p>${config.message}</p>` : ''}
            <div class="auth-error-actions">
              <button onclick="window.history.back()">Go Back</button>
              <button onclick="window.location.reload()">Retry</button>
            </div>
          </div>
      </div>
    `;
  },

  /**
   * Handle fallback error
   */
  handleFallbackError(error, context) {
    console.error('[AuthErrorHandler] Fallback error handling:', error);

    this.showNotification(
      'An error occurred. Please try again.',
      'error'
    );

    return {
      handled: true,
      action: 'fallback',
      result: {success: false, method: 'fallback'}
    };
  }
};

// Expose globally
window.AuthErrorHandler = AuthErrorHandler;
