/**
 * ErrorRenderer - Renders formatted error pages
 * Used to display beautiful error pages with full functionality
 */
class ErrorRenderer {
  /**
   * Render application error
   * @param {Error} error - Error object
   * @param {Object} options - Rendering options
   * @param {string} options.containerId - Container element ID (default: 'main')
   * @param {string} options.title - Error title
   * @param {string} options.message - Error message
   * @param {boolean} options.showStack - Show stack trace (default: false)
   * @param {Array} options.actions - Custom action buttons
   */
  static renderError(error, options = {}) {
    const config = {
      containerId: 'main',
      title: 'Application Error',
      message: 'The application failed to initialize. Please try again later.',
      showStack: false,
      showDetails: true,
      actions: null,
      ...options
    };

    const container = document.getElementById(config.containerId);
    if (!container) {
      console.error(`Container element #${config.containerId} not found`);
      return;
    }

    const errorHtml = this.generateErrorHtml(error, config);
    container.innerHTML = errorHtml;

    // Add event listeners after rendering is complete
    this.attachEventListeners(container, config);
  }

  /**
   * Generate error HTML
   * @private
   */
  static generateErrorHtml(error, config) {
    const errorMessage = error?.message || 'Unknown error occurred';
    const errorStack = error?.stack || '';
    let button = '';
    if (config.showStack && errorStack) {
      button = `<div class="error-details-toggle">
                  <button type="button" class="error-toggle-btn" data-action="toggle-details">
                    <span class="toggle-text">Show Details</span>
                    <svg class="toggle-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                  </button>
                </div>`;
    }

    return `
      <div class="error-container">
        <div class="error-card">
          <div class="error-icon">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="64" height="64">
              <circle cx="12" cy="12" r="10" fill="#fee2e2" />
              <path d="M12 8v5" stroke="#ef4444" stroke-width="2" stroke-linecap="round" />
              <circle cx="12" cy="16" r="1" fill="#ef4444" />
            </svg>
          </div>
          <h1 class="error-title">${config.title}</h1>
          <p class="error-message">${config.message}</p>
          <div class="error-details">
            <div class="error-code-heading"><div>Error Message:</div>${button}</div>
            <pre class="error-code">${Utils.string.escape(errorMessage)}</pre>
            <div class="error-details-content" style="display: none;">
              ${config.showStack && errorStack ? `
                <div class="error-code-heading">Stack Trace:</div>
                <pre class="error-code">${Utils.string.escape(errorStack)}</pre>
              ` : ''}
            </div>
          </div>
          <div class="error-help">
            If the problem persists, please contact your system administrator or try clearing your browser cache.
          </div>
        </div>
      </div>`;
  }

  /**
   * Attach event listeners
   * @private
   */
  static attachEventListeners(container, config) {
    // Toggle details
    const toggleBtn = container.querySelector('[data-action="toggle-details"]');
    if (toggleBtn) {
      toggleBtn.addEventListener('click', () => {
        const content = container.querySelector('.error-details-content');
        const icon = toggleBtn.querySelector('.toggle-icon');
        const text = toggleBtn.querySelector('.toggle-text');

        if (content.style.display === 'none') {
          content.style.display = 'block';
          text.textContent = 'Hide Details';
          icon.style.transform = 'rotate(180deg)';
        } else {
          content.style.display = 'none';
          text.textContent = 'Show Details';
          icon.style.transform = 'rotate(0deg)';
        }
      });
    }

    // Default actions
    const reloadBtn = container.querySelector('[data-action="reload"]');
    if (reloadBtn) {
      reloadBtn.addEventListener('click', () => window.location.reload());
    }

    const homeBtn = container.querySelector('[data-action="home"]');
    if (homeBtn) {
      homeBtn.addEventListener('click', () => window.location.href = '/');
    }

    // Custom actions
    const customBtns = container.querySelectorAll('[data-action]:not([data-action="reload"]):not([data-action="home"]):not([data-action="toggle-details"])');
    customBtns.forEach(btn => {
      const action = btn.dataset.action;
      if (config.customHandlers && config.customHandlers[action]) {
        btn.addEventListener('click', config.customHandlers[action]);
      }
    });
  }

  /**
   * Render 404 error
   */
  static render404(options = {}) {
    return this.renderError(new Error('Page not found'), {
      title: '404 - Page Not Found',
      message: "The page you're looking for doesn't exist.",
      showDetails: false,
      ...options
    });
  }

  /**
   * Render network error
   */
  static renderNetworkError(options = {}) {
    return this.renderError(new Error('Network connection failed'), {
      title: 'Connection Error',
      message: 'Unable to connect to the server. Please check your internet connection.',
      actions: [
        {
          text: 'Try Again',
          type: 'primary',
          action: 'retry'
        },
        {
          text: 'Go Home',
          type: 'secondary',
          action: 'home'
        }
      ],
      customHandlers: {
        retry: () => window.location.reload()
      },
      ...options
    });
  }

  /**
   * Render authentication error
   */
  static renderAuthError(options = {}) {
    return this.renderError(new Error('Authentication failed'), {
      title: 'Authentication Required',
      message: 'You need to log in to access this page.',
      showDetails: false,
      actions: [
        {
          text: 'Go to Login',
          type: 'primary',
          action: 'login'
        }
      ],
      customHandlers: {
        login: () => window.location.href = '/login'
      },
      ...options
    });
  }
}

// Export for module use
if (typeof module !== 'undefined' && module.exports) {
  module.exports = ErrorRenderer;
}

// Expose globally
window.ErrorRenderer = ErrorRenderer;