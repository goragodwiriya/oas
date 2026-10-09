/**
 * NotificationManager
 *
 * Shows toast notifications in a fixed-position container. When more than
 * `config.maxVisible` are shown at once, the rest wait in a queue and are
 * displayed as earlier ones dismiss. `success`, `error`, `warning`, `info` and
 * `loading` are thin wrappers over `show` with a type-appropriate icon and
 * duration already filled in.
 */
const NotificationManager = {
  config: {
    position: 'top-right',    // top-right, top-left, bottom-right, bottom-left
    duration: 3000,
    maxVisible: 5,
    animation: true,
    dismissible: true,
    rtl: document.dir === 'rtl',
    progressBar: false,
    pauseOnHover: false,
    closeButton: true,
    icons: true,
    aria: true
  },

  state: {
    notifications: new Set(),
    queue: [],
    isProcessing: false,
    container: null,
    initialized: false
  },

  /**
   * Set up the manager: create the container and bind hover-to-pause.
   *
   * Runs once; a second call returns immediately.
   *
   * @param {Object} [options={}] - Overrides merged into the module config.
   * @returns {Promise<Object>} - The manager itself, so calls can be chained.
   */
  async init(options = {}) {
    if (this.state.initialized) return this;

    this.config = {...this.config, ...options};
    this.createContainer();
    this.setupEventListeners();

    this.state.initialized = true;
    return this;
  },

  /**
   * Build the fixed-position container notifications render into.
   *
   * @returns {void}
   */
  createContainer() {
    const container = document.createElement('div');
    container.className = `notification-container notification-${this.config.position}`;
    if (this.config.rtl) {
      container.setAttribute('dir', 'rtl');
    }
    if (this.config.aria) {
      container.setAttribute('role', 'alert');
      container.setAttribute('aria-live', 'polite');
    }
    document.body.appendChild(container);
    this.state.container = container;
  },

  /**
   * Change which corner of the screen notifications appear in.
   *
   * An invalid position is rejected with a console warning rather than applied.
   *
   * @param {string} position - One of `top-right`, `top-left`, `bottom-right`, `bottom-left`.
   * @returns {void}
   */
  setPosition(position) {
    const validPositions = ['top-right', 'top-left', 'bottom-right', 'bottom-left'];
    if (!validPositions.includes(position)) {
      console.warn(`Invalid position: ${position}. Valid positions are: ${validPositions.join(', ')}`);
      return;
    }

    this.config.position = position;

    if (this.state.container) {
      // Remove all position classes
      validPositions.forEach(pos => {
        this.state.container.classList.remove(`notification-${pos}`);
      });
      // Add new position class
      this.state.container.classList.add(`notification-${position}`);
    } else {
      this.createContainer();
    }
  },

  /**
   * Show a notification, initialising the manager first if needed.
   *
   * Re-initialises automatically when the container was destroyed, so `show`
   * still works after a `destroy()`.
   *
   * @param {Object|string} options - Notification options, or a plain message string.
   * @returns {string} - Id of the notification, used with `dismiss`.
   */
  show(options = {}) {
    // Auto-init if not initialized or container was destroyed
    if (!this.state.initialized || !this.state.container) {
      this.state.initialized = false;
      this.init();
    }

    const notification = this.createNotification(options);

    // Auto-dismiss existing notifications of the same type to prevent overflow
    if (notification.type && notification.type !== 'loading') {
      const existingOfSameType = Array.from(this.state.notifications)
        .filter(n => n.type === notification.type);

      if (existingOfSameType.length > 0) {
        // Dismiss existing notifications with animation
        existingOfSameType.forEach(n => {
          this.dismiss(n.id);
        });
      }
    }

    if (this.state.notifications.size >= this.config.maxVisible) {
      this.state.queue.push(notification);
      return notification.id;
    }

    this.renderNotification(notification);
    return notification.id;
  },

  /**
   * Build a notification record from options.
   *
   * The message is translated through I18nManager when it is available.
   *
   * @param {Object|string} options - Notification options, or a plain message string.
   * @returns {Object} - The notification record.
   */
  createNotification(options) {
    if (typeof options === 'string') {
      options = {message: options};
    }

    // Translate message if I18nManager is available
    if (options.message && window.I18nManager?.translate) {
      options.message = I18nManager.translate(options.message);
    }

    return {
      id: Utils.generateUUID(),
      type: 'info',
      duration: this.config.duration,
      dismissible: this.config.dismissible,
      animation: this.config.animation,
      progressBar: this.config.progressBar,
      timestamp: Date.now(),
      ...options
    };
  },

  /**
   * Render a notification record into the DOM.
   *
   * @param {Object} notification - Record from `createNotification`.
   * @returns {HTMLElement} - The rendered notification element.
   */
  renderNotification(notification) {
    const element = document.createElement('div');
    element.className = `notification notification-${notification.type}`;
    element.id = notification.id;

    const content = document.createElement('div');
    let className = 'notification-content';
    if (this.config.icons && notification.icon) {
      className += ` icon-${notification.icon}`;
    }
    content.className = className;

    const wrapper = document.createElement('div');

    let title;
    if (notification.title) {
      title = document.createElement('div');
      title.className = 'notification-title';
      title.textContent = notification.title;
      wrapper.appendChild(title);
    }

    const message = document.createElement('div');
    message.className = 'notification-message';
    const messageText = (notification.message || '').toString();
    if (messageText.includes('\n')) {
      // If has line breaks, split and create text nodes with <br> elements
      const lines = messageText.split(/\r\n|\r|\n/);
      lines.forEach((line, index) => {
        message.appendChild(document.createTextNode(line));
        if (index < lines.length - 1) {
          message.appendChild(document.createElement('br'));
        }
      });
    } else {
      // Single line
      message.textContent = messageText;
    }

    wrapper.appendChild(message);
    content.appendChild(wrapper);

    if (notification.dismissible && this.config.closeButton) {
      const closeBtn = document.createElement('button');
      closeBtn.className = 'btn-close';
      closeBtn.onclick = () => this.dismiss(notification.id);
      content.appendChild(closeBtn);
    }

    element.appendChild(content);

    if (notification.progressBar && notification.duration > 0) {
      this.addProgressBar(element, notification);
    }

    // Container should always exist due to auto-init in show()
    // But add safety check just in case
    if (!this.state.container) {
      this.createContainer();
    }
    this.state.container.appendChild(element);
    this.state.notifications.add(notification);

    requestAnimationFrame(() => {
      element.classList.add('notification-show');
    });

    if (notification.duration > 0) {
      this.setupAutoDismiss(notification);
    }
  },

  /**
   * Attach the countdown bar shown while a timed notification is visible.
   *
   * @param {HTMLElement} element - Rendered notification element.
   * @param {Object} notification - The notification record.
   * @returns {void}
   */
  addProgressBar(element, notification) {
    const progress = document.createElement('div');
    progress.className = 'notification-progress';
    const progressBar = document.createElement('div');
    progressBar.className = 'notification-progress-bar';
    progress.appendChild(progressBar);
    element.appendChild(progress);

    notification.progressBar = {
      element: progressBar,
      startTime: Date.now(),
      duration: notification.duration,
      paused: false
    };

    requestAnimationFrame(() => {
      progressBar.style.width = '0%';
      progressBar.style.transition = `width ${notification.duration}ms linear`;
    });
  },

  /**
   * Start the timer that dismisses a notification after its duration.
   *
   * Ticks against the progress bar's paused state, so hovering (when
   * `pauseOnHover` is on) actually pauses the countdown rather than only the
   * visual bar.
   *
   * @param {Object} notification - The notification record.
   * @returns {void}
   */
  setupAutoDismiss(notification) {
    let timeLeft = notification.duration;
    let lastUpdate = Date.now();

    const checkDismiss = () => {
      if (notification.progressBar?.paused) {
        lastUpdate = Date.now();
        requestAnimationFrame(checkDismiss);
        return;
      }

      const now = Date.now();
      timeLeft -= (now - lastUpdate);
      lastUpdate = now;

      if (timeLeft <= 0) {
        this.dismiss(notification.id);
      } else {
        requestAnimationFrame(checkDismiss);
      }
    };

    requestAnimationFrame(checkDismiss);
  },

  /**
   * Remove one notification.
   *
   * @param {string} id - Id returned by `show`.
   * @returns {void}
   */
  dismiss(id) {
    const notification = Array.from(this.state.notifications)
      .find(n => n.id === id);

    if (!notification) return;

    const element = document.getElementById(id);
    if (!element) return;

    element.classList.remove('notification-show');
    element.classList.add('notification-hide');

    let removed = false;
    const cleanUp = () => {
      if (removed) return;
      removed = true;
      try {element.remove();} catch (e) {}
      this.state.notifications.delete(notification);
      this.processQueue();
    };

    element.addEventListener('transitionend', () => {
      cleanUp();
    }, {once: true});

    window.setTimeout(() => cleanUp(), 3000);
  },

  /**
   * Show the next queued notification, when one is waiting and the manager is idle.
   *
   * @returns {void}
   */
  processQueue() {
    if (this.state.isProcessing || this.state.queue.length === 0) return;

    this.state.isProcessing = true;
    const notification = this.state.queue.shift();

    this.renderNotification(notification);
    this.state.isProcessing = false;

    if (this.state.queue.length > 0) {
      this.processQueue();
    }
  },

  /**
   * Bind hover-to-pause on the container, when `config.pauseOnHover` is on.
   *
   * @returns {void}
   */
  setupEventListeners() {
    if (this.config.pauseOnHover) {
      this.state.container.addEventListener('mouseenter', (e) => {
        const notification = e.target.closest('.notification');
        if (notification) {
          const id = notification.id;
          const notificationObj = Array.from(this.state.notifications)
            .find(n => n.id === id);
          if (notificationObj?.progressBar) {
            notificationObj.progressBar.paused = true;
          }
        }
      });

      this.state.container.addEventListener('mouseleave', (e) => {
        const notification = e.target.closest('.notification');
        if (notification) {
          const id = notification.id;
          const notificationObj = Array.from(this.state.notifications)
            .find(n => n.id === id);
          if (notificationObj?.progressBar) {
            notificationObj.progressBar.paused = false;
          }
        }
      });
    }

    window.addEventListener('beforeunload', () => {
      this.destroy();
    });
  },

  // Utility methods for different notification types
  /**
   * Show a success notification.
   *
   * @param {string} message - Message to show.
   * @param {Object} [options={}] - Overrides merged into the defaults for this type.
   * @returns {string} - Id of the notification.
   */
  success(message, options = {}) {
    return this.show({
      type: 'success',
      message,
      icon: 'valid',
      ...options
    });
  },

  /**
   * Show an error notification. Stays visible longer than the default (8s).
   *
   * @param {string} message - Message to show.
   * @param {Object} [options={}] - Overrides merged into the defaults for this type.
   * @returns {string} - Id of the notification.
   */
  error(message, options = {}) {
    return this.show({
      type: 'error',
      message,
      icon: 'ban',
      duration: 8000,
      ...options
    });
  },

  /**
   * Show a warning notification. Stays visible longer than the default (8s).
   *
   * @param {string} message - Message to show.
   * @param {Object} [options={}] - Overrides merged into the defaults for this type.
   * @returns {string} - Id of the notification.
   */
  warning(message, options = {}) {
    return this.show({
      type: 'warning',
      message,
      icon: 'warning',
      duration: 8000,
      ...options
    });
  },

  /**
   * Show an info notification.
   *
   * @param {string} message - Message to show.
   * @param {Object} [options={}] - Overrides merged into the defaults for this type.
   * @returns {string} - Id of the notification.
   */
  info(message, options = {}) {
    return this.show({
      type: 'info',
      message,
      icon: 'info',
      ...options
    });
  },

  /**
   * Show a loading notification with no auto-dismiss timer.
   *
   * Duration is forced to 0, so the caller is responsible for calling
   * `dismiss` once the operation finishes.
   *
   * @param {string} message - Message to show.
   * @param {Object} [options={}] - Overrides merged into the defaults for this type.
   * @returns {string} - Id of the notification.
   */
  loading(message, options = {}) {
    return this.show({
      type: 'loading',
      message,
      icon: 'loader',
      duration: 0,
      dismissible: false,
      progressBar: false,
      ...options
    });
  },

  /**
   * Dismiss every visible notification and empty the queue.
   *
   * @returns {void}
   */
  clear() {
    Array.from(this.state.notifications).forEach(notification => {
      this.dismiss(notification.id);
    });
    this.state.queue = [];
  },

  /**
   * Tear the manager down: clear all notifications and remove the container.
   *
   * @returns {void}
   */
  destroy() {
    this.clear();
    if (this.state.container) {
      this.state.container.remove();
      this.state.container = null;
    }
    // Reset initialized flag so init() can be called again
    this.state.initialized = false;
  }
};

if (window.Now?.registerManager) {
  Now.registerManager('notification', NotificationManager);
}

// Expose globally
window.NotificationManager = NotificationManager;
