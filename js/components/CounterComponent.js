/**
 * CounterComponent -Component for animating numbers.
 * Can work with ScrollManager and supports many animate formats.
 */
const CounterComponent = {
  config: {
    // Basic value
    start: 0,                // Start value
    end: 100,                // End value
    duration: 2000,          // Duration of the animation (ms)
    easing: 'easeOutExpo',   // Animation easing (linear, easeOutExpo, easeInOutCubic)
    format: 'number',        // Display format (number, percentage, currency, time, timer)
    countMode: 'up',         // Counting mode (up, down)
    autostart: false,        // Auto start when loaded
    delay: 0,                // Delay before starting animation (ms)

    // ScrollManager integration
    scrollTrigger: true,     // Start counting when scrolled into view
    scrollThreshold: 0.5,    // Start when 50% of the element is visible
    scrollOffset: 0,         // Distance from the viewport edge to start

    // Display format
    decimalPlaces: 0,        // Decimal places
    prefix: '',              // Prefix text
    suffix: '',              // Suffix text
    separator: ',',          // Thousand separator
    decimal: '.',            // Decimal point
    currencySymbol: '฿',     // Currency symbol (for currency format)

    // Special display
    animation: 'normal',     // Animation type (normal, rollup, odometer)
    formatterFn: null,       // Custom formatter function
    template: null,          // Template HTML for display

    // callbacks
    onStart: null,           // When starting to animate
    onUpdate: null,          // When the value is updated
    onComplete: null,        // When the animate is finished
  },

  state: {
    instances: new Map(),    // Store all instances
    initialized: false
  },

  /**
   * start working CounterComponent
   */
  async init(options = {}) {
    this.config = {...this.config, ...options};
    this.initElements();
    this.state.initialized = true;
    return this;
  },

  /**
   * Initialize elements with data-component="counter"
   */
  initElements() {
    document.querySelectorAll('[data-component="counter"]').forEach(element => {
      this.create(element);
    });
  },

  /**
   * Create a new instance of CounterComponent.
   */
  create(element, options = {}) {
    if (typeof element === 'string') {
      element = document.querySelector(element);
    }

    if (!element) {
      console.error('Element not found');
      return null;
    }

    // Check if the instance already exists.
    const existingInstance = this.getInstance(element);
    if (existingInstance) {
      return existingInstance;
    }

    // Create a new instance
    const instance = {
      id: 'counter_' + Math.random().toString(36).substring(2, 11),
      element,
      options: {...this.config, ...this.extractOptionsFromElement(element), ...options},
      value: 0,
      currentValue: 0,
      targetValue: 0,
      startTime: 0,
      isRunning: false,
      isComplete: false,
      timer: null,
      animationFrame: null,
      elements: {
        container: null,
        wrapper: null,
        value: null
      }
    };

    // Start the instance
    this.setup(instance);

    // Store the instance
    this.state.instances.set(instance.id, instance);
    element.dataset.counterComponentId = instance.id;

    // Store reference on the element for access from HTML
    element.counterInstance = instance;

    return instance;
  },

  /**
   * Setup instance
   */
  setup(instance) {
    try {
      const {element, options} = instance;

      // Prepare DOM
      this.prepareDOM(instance);

      // Set initial values
      instance.value = parseFloat(options.start) || 0;
      instance.currentValue = instance.value;
      instance.targetValue = parseFloat(options.end) || 100;

      // If counting down, swap start and end values
      if (options.countMode === 'down') {
        instance.value = instance.targetValue;
        instance.currentValue = instance.targetValue;
        instance.targetValue = parseFloat(options.start) || 0;
      }

      // Display initial value
      this.updateDisplay(instance);

      // Set up scroll trigger
      if (options.scrollTrigger) {
        this.setupScrollTrigger(instance);
      }

      // Auto start if configured
      if (options.autostart) {
        setTimeout(() => {
          this.start(instance);
        }, options.delay);
      }

      // Add methods for HTML access
      element.start = () => this.start(instance);
      element.stop = () => this.stop(instance);
      element.reset = () => this.reset(instance);
      element.setValue = (value) => this.setValue(instance, value);

      // Dispatch initialized event
      this.dispatchEvent(instance, 'init', {instance});

    } catch (error) {
      console.error('CounterComponent setup error:', error);
    }
  },

  /**
   * Prepare DOM structure
   */
  prepareDOM(instance) {
    const {element, options} = instance;

    // Add class counter-component
    element.classList.add('counter-component');

    // Create container for the number
    const container = document.createElement('div');
    container.className = 'counter-container';

    // Create wrapper
    const wrapper = document.createElement('div');
    wrapper.className = 'counter-wrapper';

    // Create element for displaying value
    const valueElement = document.createElement('span');
    valueElement.className = 'counter-value';

    // If there is an HTML template
    if (options.template) {
      try {
        if (typeof options.template === 'string') {
          wrapper.innerHTML = options.template;
          const valueSlot = wrapper.querySelector('[data-counter-value]');
          if (valueSlot) {
            instance.elements.value = valueSlot;
          } else {
            wrapper.appendChild(valueElement);
            instance.elements.value = valueElement;
          }
        }
      } catch (e) {
        console.error('Error applying template:', e);
        wrapper.appendChild(valueElement);
        instance.elements.value = valueElement;
      }
    } else {
      // If there is no template, use the default structure
      wrapper.appendChild(valueElement);
      instance.elements.value = valueElement;
    }

    // Add prefix and suffix
    if (options.prefix) {
      const prefixElement = document.createElement('span');
      prefixElement.className = 'counter-prefix';
      prefixElement.textContent = options.prefix;
      wrapper.insertBefore(prefixElement, wrapper.firstChild);
    }

    if (options.suffix) {
      const suffixElement = document.createElement('span');
      suffixElement.className = 'counter-suffix';
      suffixElement.textContent = options.suffix;
      wrapper.appendChild(suffixElement);
    }

    // Append to DOM
    container.appendChild(wrapper);

    // If the element is empty, replace its content
    if (element.innerHTML.trim() === '') {
      element.appendChild(container);
    } else {
      // If there is existing content, look for a placeholder for the value
      const placeholder = element.querySelector('[data-counter-value]');
      if (placeholder) {
        instance.elements.value = placeholder;
      } else {
        // If there is no placeholder, insert the element for displaying the value
        instance.elements.value = valueElement;
        element.appendChild(valueElement);
      }
    }

    // Store elements
    instance.elements.container = container;
    instance.elements.wrapper = wrapper;
  },

  /**
   * Start the counting animation
   */
  start(instance) {
    if (!instance || instance.isRunning) return;

    const {options} = instance;
    instance.isRunning = true;
    instance.isComplete = false;
    instance.startTime = performance.now();

    // Dispatch start event
    this.dispatchEvent(instance, 'start', {
      startValue: instance.value,
      targetValue: instance.targetValue
    });

    // Call onStart callback
    if (typeof options.onStart === 'function') {
      options.onStart.call(instance, instance.value);
    }

    // Start animation
    this.animate(instance);

    return instance;
  },

  /**
   * Update animation
   */
  animate(instance) {
    if (!instance || !instance.isRunning) return;

    const {options} = instance;
    const currentTime = performance.now();
    const elapsedTime = currentTime - instance.startTime;

    // Calculate the progress ratio (0-1)
    let progress = Math.min(elapsedTime / options.duration, 1);

    // Apply easing function
    progress = this.applyEasing(progress, options.easing);

    // Calculate the current value
    const startValue = instance.value;
    const changeInValue = instance.targetValue - startValue;
    instance.currentValue = startValue + (changeInValue * progress);

    // Update display
    this.updateDisplay(instance);

    // Call onUpdate callback
    if (typeof options.onUpdate === 'function') {
      options.onUpdate.call(instance, instance.currentValue);
    }

    // Dispatch update event
    this.dispatchEvent(instance, 'update', {
      value: instance.currentValue,
      progress: progress
    });

    // If not complete, continue animation
    if (progress < 1) {
      instance.animationFrame = requestAnimationFrame(() => this.animate(instance));
    } else {
      // If complete
      instance.currentValue = instance.targetValue;
      this.updateDisplay(instance);
      this.complete(instance);
    }
  },

  /**
   * When animation is complete
   */
  complete(instance) {
    if (!instance) return;

    instance.isRunning = false;
    instance.isComplete = true;

    // Dispatch complete event
    this.dispatchEvent(instance, 'complete', {
      value: instance.currentValue
    });

    // Call onComplete callback
    if (typeof instance.options.onComplete === 'function') {
      instance.options.onComplete.call(instance, instance.currentValue);
    }
  },

  /**
   * Stop animation
   */
  stop(instance) {
    if (!instance || !instance.isRunning) return;

    instance.isRunning = false;

    if (instance.animationFrame) {
      cancelAnimationFrame(instance.animationFrame);
      instance.animationFrame = null;
    }

    // Dispatch stop event
    this.dispatchEvent(instance, 'stop', {
      value: instance.currentValue
    });

    return instance;
  },

  /**
   * Reset to initial value
   */
  reset(instance) {
    if (!instance) return;

    this.stop(instance);

    // Reset to initial value
    const {options} = instance;

    if (options.countMode === 'down') {
      instance.currentValue = parseFloat(options.end) || 100;
      instance.value = instance.currentValue;
      instance.targetValue = parseFloat(options.start) || 0;
    } else {
      instance.currentValue = parseFloat(options.start) || 0;
      instance.value = instance.currentValue;
      instance.targetValue = parseFloat(options.end) || 100;
    }

    // Update display
    this.updateDisplay(instance);

    // Dispatch reset event
    this.dispatchEvent(instance, 'reset', {
      value: instance.currentValue
    });

    return instance;
  },

  /**
   * Update display
   */
  updateDisplay(instance) {
    if (!instance || !instance.elements.value) return;

    const {options} = instance;
    let formattedValue = '';

    // Use custom formatter if provided
    if (typeof options.formatterFn === 'function') {
      formattedValue = options.formatterFn(instance.currentValue, options);
    } else {
      // Or use default formatter
      formattedValue = this.formatValue(instance.currentValue, options);
    }

    // Update DOM
    if (options.animation === 'odometer') {
      this.updateOdometerAnimation(instance, formattedValue);
    } else if (options.animation === 'rollup') {
      this.updateRollupAnimation(instance, formattedValue);
    } else {
      // Default animation
      instance.elements.value.textContent = formattedValue;
    }
  },

  /**
   * Animation using odometer (numbers running as a meter)
   */
  updateOdometerAnimation(instance, formattedValue) {
    const valueElement = instance.elements.value;

    // If odometer is not initialized
    if (!valueElement.querySelector('.odometer-digit')) {
      valueElement.innerHTML = '';

      // Create digits
      formattedValue.split('').forEach(char => {
        const digitContainer = document.createElement('span');

        if (/\d/.test(char)) {
          // If character is a digit
          digitContainer.className = 'odometer-digit';

          const digitList = document.createElement('div');
          digitList.className = 'odometer-digit-list';

          // Create digits 0-9
          for (let i = 0; i <= 9; i++) {
            const digitValue = document.createElement('span');
            digitValue.className = 'odometer-digit-value';
            digitValue.textContent = i;
            digitList.appendChild(digitValue);
          }

          digitContainer.appendChild(digitList);
          digitContainer.dataset.value = char;
        } else {
          // If character is not a digit (e.g., comma, decimal point)
          digitContainer.className = 'odometer-separator';
          digitContainer.textContent = char;
        }

        valueElement.appendChild(digitContainer);
      });
    }

    // Update each digit
    const currentChars = formattedValue.split('');
    const digits = valueElement.querySelectorAll('.odometer-digit');

    digits.forEach((digit, index) => {
      if (index < currentChars.length && /\d/.test(currentChars[index])) {
        const value = parseInt(currentChars[index]);
        const digitList = digit.querySelector('.odometer-digit-list');

        if (digitList) {
          // Calculate sliding distance (Each number has a height of 100%)
          const offset = -value * 100;
          digitList.style.transform = `translateY(${offset}%)`;
        }

        digit.dataset.value = value;
      }
    });
  },

  /**
   * Rollup Animation (numbers roll up)
   */
  updateRollupAnimation(instance, formattedValue) {
    const valueElement = instance.elements.value;
    const currentValue = valueElement.textContent;

    // If the value hasn't changed, do nothing
    if (currentValue === formattedValue) return;

    // If there is no container for animation
    if (!valueElement.querySelector('.rollup-container')) {
      valueElement.innerHTML = `
        <span class="rollup-container">
          <span class="rollup-old">${currentValue || '0'}</span>
          <span class="rollup-new">${formattedValue}</span>
        </span>
      `;
    } else {
      // Update existing elements
      const oldValue = valueElement.querySelector('.rollup-old');
      const newValue = valueElement.querySelector('.rollup-new');

      oldValue.textContent = currentValue || '0';
      newValue.textContent = formattedValue;

      // Add class to start animation
      valueElement.querySelector('.rollup-container').classList.remove('animate');

      // Force reflow to restart animation
      void valueElement.offsetWidth;

      valueElement.querySelector('.rollup-container').classList.add('animate');
    }

    // After the animation is complete, use the new value directly
    setTimeout(() => {
      valueElement.textContent = formattedValue;
    }, 300); // Animation duration
  },

  /**
   * Format value according to the specified options
   */
  formatValue(value, options) {
    // Round to the specified number of decimal places
    const rounded = Number(value).toFixed(options.decimalPlaces);

    let result = '';

    switch (options.format) {
      case 'percentage':
        result = this.formatNumber(rounded, options) + '%';
        break;

      case 'currency':
        result = options.currencySymbol + this.formatNumber(rounded, options);
        break;

      case 'time':
        result = this.formatTime(value);
        break;

      case 'timer':
        result = this.formatTimer(value);
        break;

      default:
        result = this.formatNumber(rounded, options);
    }

    return result;
  },

  /**
   * Format number according to the specified options
   */
  formatNumber(value, options) {
    // Split integer and decimal parts
    const parts = value.toString().split('.');
    const integerPart = parts[0];
    const decimalPart = parts[1] || '';

    // Add thousand separator
    let formattedInteger = '';
    if (options.separator) {
      const rgx = /(\d+)(\d{3})/;
      let integer = integerPart;

      while (rgx.test(integer)) {
        integer = integer.replace(rgx, '$1' + options.separator + '$2');
      }

      formattedInteger = integer;
    } else {
      formattedInteger = integerPart;
    }

    // Combine integer and decimal parts
    return decimalPart ? formattedInteger + options.decimal + decimalPart : formattedInteger;
  },

  /**
   * Format time (hours:minutes:seconds)
   */
  formatTime(seconds) {
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = Math.floor(seconds % 60);

    const hDisplay = h > 0 ? String(h).padStart(2, '0') + ':' : '';
    const mDisplay = String(m).padStart(2, '0') + ':';
    const sDisplay = String(s).padStart(2, '0');

    return hDisplay + mDisplay + sDisplay;
  },

  /**
   * Format timer (minutes:seconds.milliseconds)
   */
  formatTimer(seconds) {
    const m = Math.floor(seconds / 60);
    const s = Math.floor(seconds % 60);
    const ms = Math.floor((seconds % 1) * 100);

    const mDisplay = String(m).padStart(2, '0') + ':';
    const sDisplay = String(s).padStart(2, '0');
    const msDisplay = '.' + String(ms).padStart(2, '0');

    return mDisplay + sDisplay + msDisplay;
  },

  /**
   * Apply easing function according to the specified type
   */
  applyEasing(progress, easing) {
    switch (easing) {
      case 'linear':
        return progress;

      case 'easeInQuad':
        return progress * progress;

      case 'easeOutQuad':
        return progress * (2 - progress);

      case 'easeInOutQuad':
        return progress < 0.5 ? 2 * progress * progress : -1 + (4 - 2 * progress) * progress;

      case 'easeInCubic':
        return progress * progress * progress;

      case 'easeOutCubic':
        return (--progress) * progress * progress + 1;

      case 'easeInOutCubic':
        return progress < 0.5 ? 4 * progress * progress * progress : (progress - 1) * (2 * progress - 2) * (2 * progress - 2) + 1;

      case 'easeOutExpo':
        return progress === 1 ? 1 : 1 - Math.pow(2, -10 * progress);

      default:
        return progress;
    }
  },

  /**
   * Setup scroll trigger
   */
  setupScrollTrigger(instance) {
    const {element, options} = instance;

    // If ScrollManager is available
    if (window.ScrollManager) {
      const scrollManager = window.ScrollManager; // Use global ScrollManager object

      try {
        // Check if ScrollManager is ready
        if (!scrollManager.addWaypoint) {
          throw new Error('ScrollManager.addWaypoint is not available');
        }

        // Add waypoint
        const waypointId = 'counter_' + instance.id;

        // Remove old waypoint if exists
        if (instance.waypointId && scrollManager.removeWaypoint) {
          scrollManager.removeWaypoint(instance.waypointId);
        }

        // Set callback to be called when reaching the waypoint
        const triggerCallback = (entry) => {
          if (!instance.isComplete && !instance.isRunning) {
            // setTimeout is used to ensure it runs outside the ScrollManager stack
            setTimeout(() => {
              this.start(instance);
            }, 10);
          }
        };

        // Set new waypoint
        scrollManager.addWaypoint(waypointId, element, {
          offset: options.scrollOffset || 0,
          threshold: options.scrollThreshold || 0.5,
          once: true,
          callback: triggerCallback
        });

        // Store waypoint ID
        instance.waypointId = waypointId;

        // Subscribe to additional scroll events for reliability
        const scrollHandler = () => {
          if (!instance.isRunning && !instance.isComplete) {
            const rect = element.getBoundingClientRect();
            const windowHeight = window.innerHeight;
            const threshold = options.scrollThreshold || 0.5;

            // Calculate how much of the element is visible
            const visibleHeight = Math.min(rect.bottom, windowHeight) - Math.max(rect.top, 0);
            const percentVisible = visibleHeight / rect.height;

            if (percentVisible >= threshold) {
              this.start(instance);
              window.EventManager.off('scroll:progress', scrollHandler);
            }
          }
        };

        window.EventManager.on('scroll:progress', scrollHandler);
        instance.scrollHandler = scrollHandler;

      } catch (error) {
        console.error('Error setting up ScrollManager waypoint:', error);

        // Use IntersectionObserver instead when an error occurs
        this.setupIntersectionObserver(instance);
      }
    } else {
      // If ScrollManager is not available, use IntersectionObserver
      this.setupIntersectionObserver(instance);
    }
  },

  /**
   * Setup IntersectionObserver (used when ScrollManager is not available)
   */
  setupIntersectionObserver(instance) {
    const {element, options} = instance;

    if (!('IntersectionObserver' in window)) {
      // If IntersectionObserver is not supported, start counting immediately
      if (options.autostart !== false) {
        setTimeout(() => this.start(instance), options.delay || 0);
      }
      return;
    }

    // Calculate rootMargin
    let rootMargin = '0px';
    if (options.scrollOffset) {
      const offset = parseInt(options.scrollOffset);
      rootMargin = `${-offset}px 0px ${offset}px 0px`;
    }

    // Create IntersectionObserver
    const observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting && !instance.isComplete && !instance.isRunning) {
          setTimeout(() => {
            this.start(instance);

            // Stop observing after starting the count
            observer.unobserve(element);
          }, 10);
        }
      });
    }, {
      threshold: options.scrollThreshold || 0.5,
      rootMargin: rootMargin
    });

    // Start observing
    observer.observe(element);

    // Store observer
    instance.observer = observer;
  },

  /**
   * Set a new target value
   */
  setValue(instance, value) {
    if (!instance) return;

    // Stop the current animation
    this.stop(instance);

    // Set new start and target values
    instance.value = instance.currentValue;
    instance.targetValue = parseFloat(value);

    // Start a new animation
    this.start(instance);

    return instance;
  },

  /**
   * Extract options from data attributes
   */
  extractOptionsFromElement(element) {
    const options = {};
    const dataset = element.dataset;

    // Try using data-props first (JSON format)
    if (dataset.props) {
      try {
        const props = JSON.parse(dataset.props);
        Object.assign(options, props);
      } catch (e) {
        console.warn('Invalid JSON in data-props:', e);
      }
    }

    // Read values from data-* attributes
    if (dataset.start) options.start = parseFloat(dataset.start);
    if (dataset.end) options.end = parseFloat(dataset.end);
    if (dataset.duration) options.duration = parseInt(dataset.duration);
    if (dataset.easing) options.easing = dataset.easing;
    if (dataset.format) options.format = dataset.format;
    if (dataset.countMode) options.countMode = dataset.countMode;
    if (dataset.autostart !== undefined) options.autostart = dataset.autostart !== 'false';
    if (dataset.delay) options.delay = parseInt(dataset.delay);

    // Integration with ScrollManager
    if (dataset.scrollTrigger !== undefined) options.scrollTrigger = dataset.scrollTrigger !== 'false';
    if (dataset.scrollThreshold) options.scrollThreshold = parseFloat(dataset.scrollThreshold);
    if (dataset.scrollOffset) options.scrollOffset = parseInt(dataset.scrollOffset);

    if (dataset.decimalPlaces) options.decimalPlaces = parseInt(dataset.decimalPlaces);
    if (dataset.prefix) options.prefix = dataset.prefix;
    if (dataset.suffix) options.suffix = dataset.suffix;
    if (dataset.separator) options.separator = dataset.separator;
    if (dataset.decimal) options.decimal = dataset.decimal;
    if (dataset.currencySymbol) options.currencySymbol = dataset.currencySymbol;

    // Special display options
    if (dataset.animation) options.animation = dataset.animation;
    if (dataset.template) options.template = dataset.template;

    return options;
  },

  /**
   * Find instance from element
   */
  getInstance(element) {
    if (typeof element === 'string') {
      element = document.querySelector(element);
    }

    if (!element) return null;

    // Check for counterInstance stored in the element
    if (element.counterInstance) {
      return element.counterInstance;
    }

    // Check data-counter-component-id
    const id = element.dataset.counterComponentId;
    if (id && this.state.instances.has(id)) {
      return this.state.instances.get(id);
    }

    // Search through all instances
    for (const instance of this.state.instances.values()) {
      if (instance.element === element) {
        return instance;
      }
    }

    return null;
  },

  /**
   * Dispatch event
   */
  dispatchEvent(instance, eventName, detail = {}) {
    if (!instance.element) return;

    const event = new CustomEvent(`counter:${eventName}`, {
      bubbles: true,
      cancelable: true,
      detail: {
        instance,
        ...detail
      }
    });

    instance.element.dispatchEvent(event);

    // Emit event through EventManager if available
    EventManager.emit(`counter:${eventName}`, {
      instance,
      ...detail
    });
  },

  /**
   * Destroy instance
   */
  destroy(instance) {
    if (typeof instance === 'string') {
      instance = this.state.instances.get(instance);
    } else if (instance instanceof HTMLElement) {
      instance = this.getInstance(instance);
    }

    if (!instance) return false;

    // Stop animation
    this.stop(instance);

    // Remove waypoint if available
    if (instance.waypointId && window.ScrollManager?.removeWaypoint) {
      try {
        window.ScrollManager.removeWaypoint(instance.waypointId);
      } catch (e) {
        console.error('Error removing waypoint:', e);
      }
    }

    // Remove scroll handler if available
    if (instance.scrollHandler) {
      window.EventManager.off('scroll:progress', instance.scrollHandler);
    }

    // Remove observer if available
    if (instance.observer) {
      instance.observer.disconnect();
      instance.observer = null;
    }

    // Clear data
    if (instance.element) {
      delete instance.element.counterInstance;
      delete instance.element.dataset.counterComponentId;
    }

    // Dispatch destroy event
    this.dispatchEvent(instance, 'destroy');

    // Remove from Map
    if (instance.id) {
      this.state.instances.delete(instance.id);
    }

    return true;
  },

  /**
   * Destroy all instances and clean up
   */
  destroyAll() {
    Array.from(this.state.instances.values()).forEach(instance => {
      this.destroy(instance);
    });

    this.state.instances.clear();
  }
};

/**
* Register Component with ComponentManager
*/
if (window.ComponentManager) {
  const counterComponentDefinition = {
    name: 'counter',
    template: null,

    validElement(element) {
      return element.classList.contains('counter-component') ||
        element.dataset.component === 'counter' ||
        element.hasAttribute('data-start') ||
        element.hasAttribute('data-end');
    },

    setupElement(element, state) {
      const options = CounterComponent.extractOptionsFromElement(element);
      const counterInstance = CounterComponent.create(element, options);

      element._counterComponent = counterInstance;
      return element;
    },

    beforeDestroy() {
      if (this.element && this.element._counterComponent) {
        CounterComponent.destroy(this.element._counterComponent);
        delete this.element._counterComponent;
      }
    }
  };

  ComponentManager.define('counter', counterComponentDefinition);
}

/**
* Register CounterComponent with Now.js framework
*/
if (window.Now?.registerManager) {
  Now.registerManager('counter', CounterComponent);
}

// Expose globally
window.CounterComponent = CounterComponent;
