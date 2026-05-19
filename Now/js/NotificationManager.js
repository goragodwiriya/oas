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

  async init(options = {}) {
    if (this.state.initialized) return this;

    this.config = {...this.config, ...options};
    this.createContainer();
    this.setupEventListeners();

    this.state.initialized = true;
    return this;
  },

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
  success(message, options = {}) {
    return this.show({
      type: 'success',
      message,
      icon: 'valid',
      ...options
    });
  },

  error(message, options = {}) {
    return this.show({
      type: 'error',
      message,
      icon: 'ban',
      duration: 8000,
      ...options
    });
  },

  warning(message, options = {}) {
    return this.show({
      type: 'warning',
      message,
      icon: 'warning',
      duration: 8000,
      ...options
    });
  },

  info(message, options = {}) {
    return this.show({
      type: 'info',
      message,
      icon: 'info',
      ...options
    });
  },

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

  clear() {
    Array.from(this.state.notifications).forEach(notification => {
      this.dismiss(notification.id);
    });
    this.state.queue = [];
  },

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
