/**
 * ClockComponent - Modern Real-time Clock Component
 * Supports multiple clock types, timezones, and formats
 */
const ClockComponent = {
  config: {
    updateInterval: 1000, // 1 second
    defaultFormat: 'HH:mm:ss',
    defaultLocale: 'th-TH',
    autoStart: true
  },

  state: {
    clocks: new Map(),
    globalTimer: null,
    initialized: false
  },

  init(options = {}) {
    if (this.state.initialized) return this;

    this.config = {...this.config, ...options};

    // Initialize any existing elements with data-clock attribute
    document.querySelectorAll('[data-clock]').forEach(element => {
      this.create(element);
    });

    this.state.initialized = true;
    return this;
  },

  create(element, options = {}) {
    // Allow both element reference and ID string
    if (typeof element === 'string') {
      element = document.getElementById(element);
    }

    if (!element) return null;

    // Skip if already initialized
    if (element.clockInstance) {
      return element.clockInstance;
    }

    // Extract config from data attributes
    const dataOptions = this.extractDataOptions(element);

    // Combine configs: default < data attributes < options parameter
    const config = {...this.config, ...dataOptions, ...options};

    // Initialize the instance
    const instance = {
      element,
      config,
      type: config.type || 'realtime',
      isRunning: false,
      startTime: null,
      currentTime: null,
      callbacks: {
        onTick: config.onTick || null,
        onComplete: config.onComplete || null
      }
    };

    // Store on element for easy access
    element.clockInstance = instance;

    // Store in manager
    this.state.clocks.set(element, instance);

    // Setup initial display
    this.setupClock(instance);

    // Start if auto-start is enabled
    if (config.autoStart) {
      this.start(instance);
    }

    return instance;
  },

  extractDataOptions(element) {
    const options = {};
    const dataset = element.dataset;

    if (dataset.clock) {
      options.type = dataset.clock;
    }

    if (dataset.clockFormat) {
      options.format = dataset.clockFormat;
    }

    if (dataset.clockLocale) {
      options.locale = dataset.clockLocale;
    }

    if (dataset.clockTimezone) {
      options.timezone = dataset.clockTimezone;
    }

    if (dataset.clockCountdown) {
      options.countdown = parseInt(dataset.clockCountdown);
    }

    if (dataset.clockAutoStart !== undefined) {
      options.autoStart = dataset.clockAutoStart === 'true';
    }

    if (dataset.clockInterval) {
      options.updateInterval = parseInt(dataset.clockInterval);
    }

    // Callbacks
    if (dataset.clockOnTick && typeof window[dataset.clockOnTick] === 'function') {
      options.onTick = window[dataset.clockOnTick];
    }

    if (dataset.clockOnComplete && typeof window[dataset.clockOnComplete] === 'function') {
      options.onComplete = window[dataset.clockOnComplete];
    }

    return options;
  },

  setupClock(instance) {
    const {config, type} = instance;

    switch (type) {
      case 'realtime':
        instance.currentTime = new Date();
        break;
      case 'countdown':
        if (config.countdown) {
          instance.currentTime = config.countdown * 1000; // Convert to milliseconds
        } else {
          console.warn('ClockComponent: countdown value is required for countdown type');
          return;
        }
        break;
      case 'stopwatch':
        instance.currentTime = 0;
        break;
      case 'server':
        // Get server time (could be from API or config)
        instance.currentTime = config.serverTime ? new Date(config.serverTime) : new Date();
        break;
      default:
        instance.currentTime = new Date();
    }

    this.updateDisplay(instance);
  },

  start(instance) {
    if (instance.isRunning) return;

    instance.isRunning = true;
    instance.startTime = Date.now();

    // Start global timer if not running
    if (!this.state.globalTimer) {
      this.startGlobalTimer();
    }

    // Emit event
    this.emitEvent('clock:start', {
      instance,
      element: instance.element
    });
  },

  stop(instance) {
    if (!instance.isRunning) return;

    instance.isRunning = false;

    // Stop global timer if no clocks are running
    if (!Array.from(this.state.clocks.values()).some(clock => clock.isRunning)) {
      this.stopGlobalTimer();
    }

    // Emit event
    this.emitEvent('clock:stop', {
      instance,
      element: instance.element
    });
  },

  reset(instance) {
    const wasRunning = instance.isRunning;

    if (wasRunning) {
      this.stop(instance);
    }

    this.setupClock(instance);

    if (wasRunning && instance.config.autoStart) {
      this.start(instance);
    }

    // Emit event
    this.emitEvent('clock:reset', {
      instance,
      element: instance.element
    });
  },

  startGlobalTimer() {
    if (this.state.globalTimer) return;

    this.state.globalTimer = setInterval(() => {
      this.updateAllClocks();
    }, this.config.updateInterval);
  },

  stopGlobalTimer() {
    if (this.state.globalTimer) {
      clearInterval(this.state.globalTimer);
      this.state.globalTimer = null;
    }
  },

  updateAllClocks() {
    this.state.clocks.forEach(instance => {
      if (instance.isRunning) {
        this.updateClock(instance);
      }
    });
  },

  updateClock(instance) {
    const {type, config} = instance;
    let shouldComplete = false;

    switch (type) {
      case 'realtime':
      case 'server':
        instance.currentTime = new Date();
        break;

      case 'countdown':
        instance.currentTime -= config.updateInterval;
        if (instance.currentTime <= 0) {
          instance.currentTime = 0;
          shouldComplete = true;
        }
        break;

      case 'stopwatch':
        instance.currentTime += config.updateInterval;
        break;
    }

    this.updateDisplay(instance);

    // Call onTick callback
    if (instance.callbacks.onTick) {
      instance.callbacks.onTick.call(instance.element, instance.currentTime, instance);
    }

    // Handle completion for countdown
    if (shouldComplete) {
      this.stop(instance);

      if (instance.callbacks.onComplete) {
        instance.callbacks.onComplete.call(instance.element, instance);
      }

      this.emitEvent('clock:complete', {
        instance,
        element: instance.element
      });
    }
  },

  updateDisplay(instance) {
    const {element, config, type, currentTime} = instance;
    let displayText = '';

    try {
      if (type === 'countdown' || type === 'stopwatch') {
        // Format milliseconds to time
        displayText = this.formatDuration(currentTime, config.format);
      } else {
        // Format date/time
        displayText = this.formatDateTime(currentTime, config.format, config.locale, config.timezone);
      }

      // Update element
      if (element.tagName === 'INPUT' || element.tagName === 'TEXTAREA') {
        element.value = displayText;
      } else {
        element.textContent = displayText;
      }

      // Add CSS class for styling
      element.classList.add('clock-display', `clock-${type}`);

    } catch (error) {
      console.error('ClockComponent: Error updating display:', error);
      element.textContent = 'Clock Error';
    }
  },

  formatDateTime(date, format = 'HH:mm:ss', locale = 'th-TH', timezone = null) {
    if (!(date instanceof Date)) {
      date = new Date(date);
    }

    const options = {
      hour12: false,
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit'
    };

    if (timezone) {
      options.timeZone = timezone;
    }

    // Handle custom formats
    if (format.includes('YYYY') || format.includes('MM') || format.includes('DD')) {
      options.year = 'numeric';
      options.month = '2-digit';
      options.day = '2-digit';
    }

    try {
      return date.toLocaleString(locale, options);
    } catch (error) {
      // Fallback to simple format
      const h = date.getHours().toString().padStart(2, '0');
      const m = date.getMinutes().toString().padStart(2, '0');
      const s = date.getSeconds().toString().padStart(2, '0');
      return `${h}:${m}:${s}`;
    }
  },

  formatDuration(milliseconds, format = 'HH:mm:ss') {
    const totalSeconds = Math.floor(Math.abs(milliseconds) / 1000);
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;

    const h = hours.toString().padStart(2, '0');
    const m = minutes.toString().padStart(2, '0');
    const s = seconds.toString().padStart(2, '0');

    if (format.includes('HH') || hours > 0) {
      return `${h}:${m}:${s}`;
    } else {
      return `${m}:${s}`;
    }
  },

  // Public API methods
  getInstance(element) {
    if (typeof element === 'string') {
      element = document.getElementById(element);
    }

    if (!element) return null;
    return this.state.clocks.get(element) || null;
  },

  startClock(elementOrId) {
    const instance = this.getInstance(elementOrId);
    if (instance) {
      this.start(instance);
    }
  },

  stopClock(elementOrId) {
    const instance = this.getInstance(elementOrId);
    if (instance) {
      this.stop(instance);
    }
  },

  resetClock(elementOrId) {
    const instance = this.getInstance(elementOrId);
    if (instance) {
      this.reset(instance);
    }
  },

  setCountdown(elementOrId, seconds) {
    const instance = this.getInstance(elementOrId);
    if (instance && instance.type === 'countdown') {
      instance.currentTime = seconds * 1000;
      this.updateDisplay(instance);
    }
  },

  destroy(instance) {
    if (typeof instance === 'string') {
      instance = this.getInstance(instance);
    }

    if (!instance) return;

    const {element} = instance;

    // Stop if running
    if (instance.isRunning) {
      this.stop(instance);
    }

    // Remove classes
    element.classList.remove('clock-display', `clock-${instance.type}`);

    // Remove references
    delete element.clockInstance;
    this.state.clocks.delete(element);

    // Stop global timer if no clocks remain
    if (this.state.clocks.size === 0) {
      this.stopGlobalTimer();
    }
  },

  emitEvent(eventName, data) {
    EventManager.emit(eventName, data);
  },

  // Cleanup method
  cleanup() {
    // Stop all clocks
    this.state.clocks.forEach(instance => {
      this.destroy(instance);
    });

    // Stop global timer
    this.stopGlobalTimer();

    // Reset state
    this.state.clocks.clear();
    this.state.initialized = false;
  }
};

// Register with Now framework if available
if (window.Now?.registerManager) {
  Now.registerManager('clock', ClockComponent);
}

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    ClockComponent.init();
  });
} else {
  ClockComponent.init();
}

// Expose for backward compatibility
if (!window.Clock) {
  window.Clock = ClockComponent;
}
