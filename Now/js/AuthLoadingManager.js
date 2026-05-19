/**
 * AuthLoadingManager - Manages loading states for auth operations
 */
const AuthLoadingManager = {
  state: {
    operations: new Map(),
    globalLoading: false,
    loadingElements: new Set(),
    loadingOverlay: null
  },

  /**
   * Loading operation types
   */
  operationTypes: {
    LOGIN: 'login',
    LOGOUT: 'logout',
    VERIFY: 'verify',
    REFRESH: 'refresh',
    ROUTE_CHECK: 'route_check',
    NAVIGATION: 'navigation',
    PROFILE_LOAD: 'profile_load',
    PASSWORD_RESET: 'password_reset',
    REGISTRATION: 'registration'
  },

  /**
   * Default loading configurations
   */
  defaultConfigs: {
    login: {
      showOverlay: true,
      message: 'Signing in...',
      timeout: 30000,
      showProgress: false,
      blockUI: true,
      showSpinner: true
    },
    logout: {
      showOverlay: true,
      message: 'Signing out...',
      timeout: 10000,
      showProgress: false,
      blockUI: true,
      showSpinner: true
    },
    verify: {
      showOverlay: false,
      message: 'Verifying authentication...',
      timeout: 15000,
      showProgress: false,
      blockUI: false,
      showSpinner: false,
      silent: true
    },
    refresh: {
      showOverlay: false,
      message: 'Refreshing session...',
      timeout: 10000,
      showProgress: false,
      blockUI: false,
      showSpinner: false,
      silent: true
    },
    route_check: {
      showOverlay: false,
      message: 'Checking permissions...',
      timeout: 5000,
      showProgress: false,
      blockUI: false,
      showSpinner: true,
      silent: false
    },
    navigation: {
      showOverlay: false,
      message: 'Loading page...',
      timeout: 15000,
      showProgress: true,
      blockUI: false,
      showSpinner: true
    },
    profile_load: {
      showOverlay: false,
      message: 'Loading profile...',
      timeout: 10000,
      showProgress: false,
      blockUI: false,
      showSpinner: true
    },
    password_reset: {
      showOverlay: true,
      message: 'Sending reset email...',
      timeout: 15000,
      showProgress: false,
      blockUI: true,
      showSpinner: true
    },
    registration: {
      showOverlay: true,
      message: 'Creating account...',
      timeout: 30000,
      showProgress: false,
      blockUI: true,
      showSpinner: true
    }
  },

  /**
   * Start loading operation
   */
  startLoading(operationType, options = {}) {
    const operationId = this.generateOperationId(operationType);
    const config = this.getLoadingConfig(operationType, options);

    const operation = {
      id: operationId,
      type: operationType,
      config,
      startTime: Date.now(),
      timeout: null,
      elements: new Set(),
      completed: false
    };

    // Set timeout if specified
    if (config.timeout > 0) {
      operation.timeout = setTimeout(() => {
        this.handleTimeout(operationId);
      }, config.timeout);
    }

    // Store operation
    this.state.operations.set(operationId, operation);

    // Update global loading state
    this.updateGlobalLoadingState();

    // Show loading UI
    this.showLoadingUI(operation);

    // Emit loading start event
    this.emitLoadingEvent('start', operation);

    return operationId;
  },

  /**
   * Update loading operation
   */
  updateLoading(operationId, updates = {}) {
    const operation = this.state.operations.get(operationId);
    if (!operation || operation.completed) {
      return false;
    }

    // Update operation properties
    Object.assign(operation, updates);

    // Update UI if message changed
    if (updates.message || updates.progress !== undefined) {
      this.updateLoadingUI(operation);
    }

    // Emit update event
    this.emitLoadingEvent('update', operation);

    return true;
  },

  /**
   * Complete loading operation
   */
  completeLoading(operationId, result = {}) {
    const operation = this.state.operations.get(operationId);
    if (!operation || operation.completed) {
      return false;
    }

    // Mark as completed
    operation.completed = true;
    operation.endTime = Date.now();
    operation.duration = operation.endTime - operation.startTime;
    operation.result = result;

    // Clear timeout
    if (operation.timeout) {
      clearTimeout(operation.timeout);
      operation.timeout = null;
    }

    // Hide loading UI
    this.hideLoadingUI(operation);

    // Remove from active operations
    this.state.operations.delete(operationId);

    // Update global loading state
    this.updateGlobalLoadingState();

    // Emit completion event
    this.emitLoadingEvent('complete', operation);

    return true;
  },

  /**
   * Cancel loading operation
   */
  cancelLoading(operationId, reason = 'cancelled') {
    const operation = this.state.operations.get(operationId);
    if (!operation || operation.completed) {
      return false;
    }

    operation.cancelled = true;
    operation.cancelReason = reason;

    return this.completeLoading(operationId, {cancelled: true, reason});
  },

  /**
   * Get loading configuration
   */
  getLoadingConfig(operationType, options) {
    const defaultConfig = this.defaultConfigs[operationType] || this.defaultConfigs.navigation;
    const globalConfig = window.RouterManager?.config?.loading || {};
    const typeConfig = globalConfig[operationType] || {};

    return {
      ...defaultConfig,
      ...typeConfig,
      ...options
    };
  },

  /**
   * Generate unique operation ID
   */
  generateOperationId(operationType) {
    return `${operationType}_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
  },

  /**
   * Update global loading state
   */
  updateGlobalLoadingState() {
    const wasLoading = this.state.globalLoading;
    this.state.globalLoading = this.state.operations.size > 0;

    // Update body class
    if (this.state.globalLoading !== wasLoading) {
      if (this.state.globalLoading) {
        document.body.classList.add('auth-loading');
      } else {
        document.body.classList.remove('auth-loading');
      }
    }
  },

  /**
   * Show loading UI for operation
   */
  showLoadingUI(operation) {
    const {config} = operation;

    // Show overlay if configured
    if (config.showOverlay) {
      this.showLoadingOverlay(operation);
    }

    // Show spinner in specific elements
    if (config.showSpinner && !config.silent) {
      this.showSpinners(operation);
    }

    // Show notification if not silent
    if (!config.silent && config.message && window.NotificationManager) {
      const notificationId = `loading_${operation.id}`;
      window.NotificationManager.info(config.message, {
        duration: 0,
        id: notificationId,
        showSpinner: config.showSpinner
      });
      operation.notificationId = notificationId;
    }

    // Block UI if configured
    if (config.blockUI) {
      this.blockUserInterface(operation);
    }
  },

  /**
   * Update loading UI
   */
  updateLoadingUI(operation) {
    // Update overlay message
    if (operation.config.showOverlay && this.state.loadingOverlay) {
      const messageEl = this.state.loadingOverlay.querySelector('.loading-message');
      if (messageEl && operation.config.message) {
        messageEl.textContent = operation.config.message;
      }

      // Update progress if applicable
      if (operation.progress !== undefined) {
        const progressEl = this.state.loadingOverlay.querySelector('.loading-progress');
        if (progressEl) {
          progressEl.style.width = `${operation.progress}%`;
        }
      }
    }

    // Update notification
    if (operation.notificationId && window.NotificationManager) {
      NotificationManager.info(operation.notificationId, {
        message: operation.config.message,
        progress: operation.progress
      });
    }
  },

  /**
   * Hide loading UI for operation
   */
  hideLoadingUI(operation) {
    // Hide notification
    if (operation.notificationId && window.NotificationManager) {
      NotificationManager.dismiss(operation.notificationId);
    }

    // Hide spinners
    this.hideSpinners(operation);

    // Unblock UI
    if (operation.config.blockUI) {
      this.unblockUserInterface(operation);
    }

    // Hide overlay if this was the last operation using it
    if (operation.config.showOverlay) {
      const hasOtherOverlayOperations = Array.from(this.state.operations.values())
        .some(op => op.id !== operation.id && op.config.showOverlay);

      if (!hasOtherOverlayOperations) {
        this.hideLoadingOverlay();
      }
    }
  },

  /**
   * Show loading overlay
   */
  showLoadingOverlay(operation) {
    if (this.state.loadingOverlay) {
      return; // Already showing
    }

    const overlay = document.createElement('div');
    overlay.className = 'auth-loading-overlay';
    overlay.innerHTML = `
      <div class="loading-content">
        <div class="loading-spinner"></div>
        <div class="loading-message">${operation.config.message || 'Loading...'}</div>
        ${operation.config.showProgress ? '<div class="loading-progress-container"><div class="loading-progress"></div></div>' : ''}
      </div>
    `;

    // Add styles
    overlay.style.cssText = `
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.5);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 10000;
      backdrop-filter: blur(2px);
    `;

    const content = overlay.querySelector('.loading-content');
    content.style.cssText = `
      background: white;
      padding: 2rem;
      border-radius: 8px;
      text-align: center;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
      max-width: 300px;
    `;

    document.body.appendChild(overlay);
    this.state.loadingOverlay = overlay;
  },

  /**
   * Hide loading overlay
   */
  hideLoadingOverlay() {
    if (this.state.loadingOverlay) {
      this.state.loadingOverlay.remove();
      this.state.loadingOverlay = null;
    }
  },

  /**
   * Show spinners in UI elements
   */
  showSpinners(operation) {
    // Add spinners to buttons and form elements
    const selectors = [
      'button[type="submit"]',
      '.auth-form button',
      '.login-button',
      '.auth-action'
    ];

    selectors.forEach(selector => {
      document.querySelectorAll(selector).forEach(element => {
        if (!element.dataset.originalText) {
          element.dataset.originalText = element.textContent;
          element.textContent = 'Loading...';
          element.disabled = true;
          element.classList.add('loading');
          operation.elements.add(element);
        }
      });
    });
  },

  /**
   * Hide spinners from UI elements
   */
  hideSpinners(operation) {
    operation.elements.forEach(element => {
      if (element.dataset.originalText) {
        element.textContent = element.dataset.originalText;
        delete element.dataset.originalText;
        element.disabled = false;
        element.classList.remove('loading');
      }
    });
    operation.elements.clear();
  },

  /**
   * Block user interface
   */
  blockUserInterface(operation) {
    // Disable form submissions
    document.addEventListener('submit', this.blockFormSubmission, true);

    // Disable navigation links
    document.addEventListener('click', this.blockNavigation, true);

    // Add visual indicator
    document.body.style.pointerEvents = 'none';
    if (this.state.loadingOverlay) {
      this.state.loadingOverlay.style.pointerEvents = 'all';
    }
  },

  /**
   * Unblock user interface
   */
  unblockUserInterface(operation) {
    // Check if other operations need UI blocked
    const hasBlockingOperations = Array.from(this.state.operations.values())
      .some(op => op.id !== operation.id && op.config.blockUI);

    if (!hasBlockingOperations) {
      document.removeEventListener('submit', this.blockFormSubmission, true);
      document.removeEventListener('click', this.blockNavigation, true);
      document.body.style.pointerEvents = '';
    }
  },

  /**
   * Block form submission during loading
   */
  blockFormSubmission(event) {
    event.preventDefault();
    event.stopPropagation();
    return false;
  },

  /**
   * Block navigation during loading
   */
  blockNavigation(event) {
    const link = event.target.closest('a[href]');
    if (link) {
      event.preventDefault();
      event.stopPropagation();
      return false;
    }
  },

  /**
   * Handle operation timeout
   */
  handleTimeout(operationId) {
    const operation = this.state.operations.get(operationId);
    if (!operation || operation.completed) {
      return;
    }

    console.warn(`[AuthLoading] Operation ${operation.type} timed out after ${operation.config.timeout}ms`);

    // Mark as timed out
    operation.timedOut = true;

    // Show timeout notification
    if (window.NotificationManager) {
      window.NotificationManager.warning(
        `Operation timed out. Please try again.`,
        {duration: 5000}
      );
    }

    // Complete the operation
    this.completeLoading(operationId, {
      timedOut: true,
      reason: 'timeout'
    });
  },

  /**
   * Emit loading event
   */
  emitLoadingEvent(eventType, operation) {
    const eventManager = window.EventManager;
    if (eventManager?.emit) {
      eventManager.emit(`auth:loading:${eventType}`, {
        operation: {
          id: operation.id,
          type: operation.type,
          startTime: operation.startTime,
          endTime: operation.endTime,
          duration: operation.duration,
          config: operation.config,
          result: operation.result
        },
        globalLoading: this.state.globalLoading,
        activeOperations: this.state.operations.size
      });
    }
  },

  /**
   * Get active operations
   */
  getActiveOperations() {
    return Array.from(this.state.operations.values()).map(op => ({
      id: op.id,
      type: op.type,
      startTime: op.startTime,
      duration: Date.now() - op.startTime,
      message: op.config.message
    }));
  },

  /**
   * Check if specific operation type is active
   */
  isLoading(operationType = null) {
    if (operationType) {
      return Array.from(this.state.operations.values())
        .some(op => op.type === operationType);
    }
    return this.state.globalLoading;
  },

  /**
   * Cancel all operations
   */
  cancelAllOperations(reason = 'cancelled') {
    const operationIds = Array.from(this.state.operations.keys());
    operationIds.forEach(id => this.cancelLoading(id, reason));
  },

  /**
   * Cleanup on page unload
   */
  cleanup() {
    this.cancelAllOperations('page_unload');
    this.hideLoadingOverlay();
    document.body.style.pointerEvents = '';
    document.body.classList.remove('auth-loading');
  }
};

window.AuthLoadingManager = AuthLoadingManager;