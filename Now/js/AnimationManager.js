/**
 * Animation Management System
 * Handles CSS animations and transitions with JavaScript control
 *
 * Features:
 * - Predefined animations (fade, slide, scale etc)
 * - Custom animation support
 * - Animation queueing and chaining
 * - Stagger animations for lists
 * - Parallel animations
 * - Pause/Resume support
 * - Performance optimization
 * - Cross-browser compatibility
 * - Cleanup and memory management
 */
const AnimationManager = {
  /**
   * Configuration
   */
  config: {
    defaultDuration: 300,
    defaultEasing: 'ease',
    // Default animations
    animations: {
      fade: {
        in: {
          from: {opacity: 0},
          to: {opacity: 1}
        },
        out: {
          from: {opacity: 1},
          to: {opacity: 0}
        }
      },
      // Aliases for common naming conventions
      'fade-up': {
        in: {
          from: {transform: 'translateY(20px)', opacity: 0},
          to: {transform: 'translateY(0)', opacity: 1}
        },
        out: {
          from: {transform: 'translateY(0)', opacity: 1},
          to: {transform: 'translateY(-20px)', opacity: 0}
        }
      },
      'fade-down': {
        in: {
          from: {transform: 'translateY(-20px)', opacity: 0},
          to: {transform: 'translateY(0)', opacity: 1}
        },
        out: {
          from: {transform: 'translateY(0)', opacity: 1},
          to: {transform: 'translateY(20px)', opacity: 0}
        }
      },
      'fade-left': {
        in: {
          from: {transform: 'translateX(20px)', opacity: 0},
          to: {transform: 'translateX(0)', opacity: 1}
        },
        out: {
          from: {transform: 'translateX(0)', opacity: 1},
          to: {transform: 'translateX(20px)', opacity: 0}
        }
      },
      'fade-right': {
        in: {
          from: {transform: 'translateX(-20px)', opacity: 0},
          to: {transform: 'translateX(0)', opacity: 1}
        },
        out: {
          from: {transform: 'translateX(0)', opacity: 1},
          to: {transform: 'translateX(-20px)', opacity: 0}
        }
      },
      slideUp: {
        in: {
          from: {transform: 'translateY(20px)', opacity: 0},
          to: {transform: 'translateY(0)', opacity: 1}
        },
        out: {
          from: {transform: 'translateY(0)', opacity: 1},
          to: {transform: 'translateY(-20px)', opacity: 0}
        }
      },
      slideDown: {
        in: {
          from: {transform: 'translateY(-20px)', opacity: 0},
          to: {transform: 'translateY(0)', opacity: 1}
        },
        out: {
          from: {transform: 'translateY(0)', opacity: 1},
          to: {transform: 'translateY(20px)', opacity: 0}
        }
      },
      slideLeft: {
        in: {
          from: {transform: 'translateX(-20px)', opacity: 0},
          to: {transform: 'translateX(0)', opacity: 1}
        },
        out: {
          from: {transform: 'translateX(0)', opacity: 1},
          to: {transform: 'translateX(-20px)', opacity: 0}
        }
      },
      slideRight: {
        in: {
          from: {transform: 'translateX(20px)', opacity: 0},
          to: {transform: 'translateX(0)', opacity: 1}
        },
        out: {
          from: {transform: 'translateX(0)', opacity: 1},
          to: {transform: 'translateX(20px)', opacity: 0}
        }
      },
      scale: {
        in: {
          from: {transform: 'scale(0.95)', opacity: 0},
          to: {transform: 'scale(1)', opacity: 1}
        },
        out: {
          from: {transform: 'scale(1)', opacity: 1},
          to: {transform: 'scale(0.95)', opacity: 0}
        }
      },
      scaleUp: {
        in: {
          from: {transform: 'scale(0.5)', opacity: 0},
          to: {transform: 'scale(1)', opacity: 1}
        },
        out: {
          from: {transform: 'scale(1)', opacity: 1},
          to: {transform: 'scale(1.5)', opacity: 0}
        }
      },
      rotate: {
        in: {
          from: {transform: 'rotate(-180deg)', opacity: 0},
          to: {transform: 'rotate(0)', opacity: 1}
        },
        out: {
          from: {transform: 'rotate(0)', opacity: 1},
          to: {transform: 'rotate(180deg)', opacity: 0}
        }
      },
      bounce: {
        in: {
          from: {transform: 'scale(0.3)', opacity: 0},
          steps: [
            {transform: 'scale(1.05)', opacity: 0.8, offset: 0.5},
            {transform: 'scale(0.95)', opacity: 0.9, offset: 0.7},
            {transform: 'scale(1.02)', opacity: 1, offset: 0.85}
          ],
          to: {transform: 'scale(1)', opacity: 1}
        },
        out: {
          from: {transform: 'scale(1)', opacity: 1},
          steps: [
            {transform: 'scale(1.05)', opacity: 0.9, offset: 0.2},
            {transform: 'scale(0.95)', opacity: 0.7, offset: 0.5}
          ],
          to: {transform: 'scale(0.3)', opacity: 0}
        }
      },
      shake: {
        in: {
          from: {transform: 'translateX(-10px)'},
          steps: [
            {transform: 'translateX(10px)', offset: 0.2},
            {transform: 'translateX(-10px)', offset: 0.4},
            {transform: 'translateX(10px)', offset: 0.6},
            {transform: 'translateX(-5px)', offset: 0.8}
          ],
          to: {transform: 'translateX(0)'}
        },
        out: {
          from: {transform: 'translateX(0)'},
          steps: [
            {transform: 'translateX(-10px)', offset: 0.2},
            {transform: 'translateX(10px)', offset: 0.4},
            {transform: 'translateX(-10px)', offset: 0.6},
            {transform: 'translateX(5px)', offset: 0.8}
          ],
          to: {transform: 'translateX(0)', opacity: 0}
        }
      },
      pulse: {
        in: {
          from: {transform: 'scale(1)', opacity: 1},
          steps: [
            {transform: 'scale(1.05)', opacity: 1, offset: 0.5}
          ],
          to: {transform: 'scale(1)', opacity: 1}
        },
        out: {
          from: {transform: 'scale(1)', opacity: 1},
          steps: [
            {transform: 'scale(1.1)', opacity: 0.8, offset: 0.5}
          ],
          to: {transform: 'scale(1)', opacity: 0}
        }
      },
      flip: {
        in: {
          from: {transform: 'perspective(400px) rotateY(90deg)', opacity: 0},
          to: {transform: 'perspective(400px) rotateY(0)', opacity: 1}
        },
        out: {
          from: {transform: 'perspective(400px) rotateY(0)', opacity: 1},
          to: {transform: 'perspective(400px) rotateY(90deg)', opacity: 0}
        }
      },
      flipX: {
        in: {
          from: {transform: 'perspective(400px) rotateX(90deg)', opacity: 0},
          to: {transform: 'perspective(400px) rotateX(0)', opacity: 1}
        },
        out: {
          from: {transform: 'perspective(400px) rotateX(0)', opacity: 1},
          to: {transform: 'perspective(400px) rotateX(90deg)', opacity: 0}
        }
      },
      zoomIn: {
        in: {
          from: {transform: 'scale(0)', opacity: 0},
          to: {transform: 'scale(1)', opacity: 1}
        },
        out: {
          from: {transform: 'scale(1)', opacity: 1},
          to: {transform: 'scale(0)', opacity: 0}
        }
      },
      swing: {
        in: {
          from: {transform: 'rotate(-10deg)'},
          steps: [
            {transform: 'rotate(10deg)', offset: 0.25},
            {transform: 'rotate(-5deg)', offset: 0.5},
            {transform: 'rotate(5deg)', offset: 0.75}
          ],
          to: {transform: 'rotate(0)'}
        },
        out: {
          from: {transform: 'rotate(0)', opacity: 1},
          steps: [
            {transform: 'rotate(10deg)', opacity: 0.8, offset: 0.25},
            {transform: 'rotate(-10deg)', opacity: 0.6, offset: 0.5}
          ],
          to: {transform: 'rotate(0)', opacity: 0}
        }
      }
    },
    // Easing presets
    easings: {
      linear: 'linear',
      ease: 'ease',
      easeIn: 'ease-in',
      easeOut: 'ease-out',
      easeInOut: 'ease-in-out',
      // Cubic bezier presets
      easeInQuad: 'cubic-bezier(0.55, 0.085, 0.68, 0.53)',
      easeOutQuad: 'cubic-bezier(0.25, 0.46, 0.45, 0.94)',
      easeInOutQuad: 'cubic-bezier(0.455, 0.03, 0.515, 0.955)',
      easeInCubic: 'cubic-bezier(0.55, 0.055, 0.675, 0.19)',
      easeOutCubic: 'cubic-bezier(0.215, 0.61, 0.355, 1)',
      easeInOutCubic: 'cubic-bezier(0.645, 0.045, 0.355, 1)',
      easeInQuart: 'cubic-bezier(0.895, 0.03, 0.685, 0.22)',
      easeOutQuart: 'cubic-bezier(0.165, 0.84, 0.44, 1)',
      easeInOutQuart: 'cubic-bezier(0.77, 0, 0.175, 1)',
      easeInBack: 'cubic-bezier(0.6, -0.28, 0.735, 0.045)',
      easeOutBack: 'cubic-bezier(0.175, 0.885, 0.32, 1.275)',
      easeInOutBack: 'cubic-bezier(0.68, -0.55, 0.265, 1.55)',
      easeInElastic: 'cubic-bezier(0.5, -0.5, 0.75, 1.5)',
      easeOutElastic: 'cubic-bezier(0.25, -0.5, 0.5, 1.5)',
      easeInBounce: 'cubic-bezier(0.6, 0.04, 0.98, 0.335)',
      easeOutBounce: 'cubic-bezier(0.175, 0.885, 0.32, 1.275)',
      spring: 'cubic-bezier(0.175, 0.885, 0.32, 1.275)'
    },
    // Performance options
    performance: {
      useRAF: true,
      batchSize: 100,
      debounceInterval: 100
    }
  },

  /**
   * Animation state
   */
  state: {
    running: new Map(),
    queue: new Map(),
    cache: new Map(),
    paused: new Map()
  },

  /**
   * Initialize animation manager
   */
  async init(options = {}) {
    this.config = {...this.config, ...options};

    // Setup performance monitoring
    if (window.PerformanceObserver) {
      this.setupPerformanceObserver();
    }

    return this;
  },

  /**
   * Animate element
   * @param {HTMLElement} element Element to animate
   * @param {string|Object} animation Animation name or custom keyframes
   * @param {Object} options Animation options
   * @returns {Promise} Animation promise
   */
  animate(element, animation, options = {}) {
    return new Promise((resolve, reject) => {
      try {
        if (!element) {
          throw new Error('Element is required');
        }

        // Get animation config
        const config = this.getAnimationConfig(animation, options);

        // Check cache first
        const cacheKey = this.getCacheKey(animation, options);
        let keyframes = this.state.cache.get(cacheKey);

        if (!keyframes) {
          keyframes = this.createKeyframes(config);
          // Cache keyframes for reuse
          this.state.cache.set(cacheKey, keyframes);
        }

        // Setup animation
        const animationId = Utils.generateUUID();
        const timing = this.createTiming(options);

        // Start animation
        const anim = element.animate(keyframes, timing);

        // Store running animation
        const cleanup = () => {
          this.state.running.delete(animationId);
          this.state.paused.delete(animationId);
          element.style.animation = '';
        };

        this.state.running.set(animationId, {
          id: animationId,
          element,
          animation: anim,
          cleanup,
          options
        });

        // Handle events
        anim.onfinish = () => {
          cleanup();
          // Call onComplete callback if provided
          if (typeof options.onComplete === 'function') {
            options.onComplete(element);
          }
          resolve(element);
        };

        anim.oncancel = () => {
          cleanup();
          reject(new Error('Animation cancelled'));
        };

        // Handle errors
        anim.onerror = (error) => {
          cleanup();
          reject(error);
        };

      } catch (error) {
        reject(error);
      }
    });
  },

  /**
   * Chain multiple animations sequentially
   * @param {HTMLElement} element Element to animate
   * @param {Array} animations Array of animations to chain
   * @returns {Promise} Animation chain promise
   */
  chain(element, animations) {
    return animations.reduce((promise, animation) => {
      return promise.then(() => {
        const name = typeof animation === 'string' ? animation : animation.name;
        const opts = typeof animation === 'object' ? animation.options || {} : {};
        return this.animate(element, name, opts);
      });
    }, Promise.resolve());
  },

  /**
   * Run multiple animations in parallel on the same element
   * @param {HTMLElement} element Element to animate
   * @param {Array} animations Array of animation names or configs
   * @param {Object} options Shared options
   * @returns {Promise} Promise that resolves when all animations complete
   */
  parallel(element, animations, options = {}) {
    const promises = animations.map(animation => {
      const name = typeof animation === 'string' ? animation : animation.name;
      const opts = typeof animation === 'object'
        ? {...options, ...animation.options}
        : options;
      return this.animate(element, name, opts);
    });
    return Promise.all(promises);
  },

  /**
   * Animate multiple elements with staggered delay
   * @param {NodeList|Array} elements Elements to animate
   * @param {string|Object} animation Animation name or config
   * @param {Object} options Animation options with stagger property
   * @returns {Promise} Promise that resolves when all animations complete
   */
  stagger(elements, animation, options = {}) {
    const staggerDelay = options.stagger || 50;
    const elementsArray = Array.from(elements);

    const promises = elementsArray.map((element, index) => {
      const staggeredOptions = {
        ...options,
        delay: (options.delay || 0) + (index * staggerDelay)
      };
      return this.animate(element, animation, staggeredOptions);
    });

    return Promise.all(promises);
  },

  /**
   * Queue animation to run after current animations complete
   * @param {HTMLElement} element Element to animate
   * @param {string|Object} animation Animation name or config
   * @param {Object} options Animation options
   * @returns {Promise} Promise that resolves when animation completes
   */
  queue(element, animation, options = {}) {
    return new Promise((resolve, reject) => {
      // Get or create queue for this element
      if (!this.state.queue.has(element)) {
        this.state.queue.set(element, []);
      }

      const elementQueue = this.state.queue.get(element);

      // Add to queue
      elementQueue.push({
        animation,
        options,
        resolve,
        reject
      });

      // Process queue if this is the first item
      if (elementQueue.length === 1) {
        this.processQueue(element);
      }
    });
  },

  /**
   * Process animation queue for an element
   * @private
   */
  async processQueue(element) {
    const elementQueue = this.state.queue.get(element);
    if (!elementQueue || elementQueue.length === 0) {
      this.state.queue.delete(element);
      return;
    }

    const {animation, options, resolve, reject} = elementQueue[0];

    try {
      await this.animate(element, animation, options);
      resolve(element);
    } catch (error) {
      reject(error);
    }

    // Remove processed item and continue
    elementQueue.shift();
    this.processQueue(element);
  },

  /**
   * Clear animation queue for an element
   * @param {HTMLElement} element Element to clear queue for (or null for all)
   */
  clearQueue(element = null) {
    if (element) {
      const queue = this.state.queue.get(element);
      if (queue) {
        queue.forEach(item => item.reject(new Error('Queue cleared')));
        this.state.queue.delete(element);
      }
    } else {
      for (const [el, queue] of this.state.queue) {
        queue.forEach(item => item.reject(new Error('Queue cleared')));
      }
      this.state.queue.clear();
    }
  },

  /**
   * Pause running animations on an element
   * @param {HTMLElement} element Element to pause (or null for all)
   */
  pause(element = null) {
    for (const [id, data] of this.state.running) {
      if (!element || data.element === element) {
        if (data.animation.playState === 'running') {
          data.animation.pause();
          this.state.paused.set(id, data);
        }
      }
    }
  },

  /**
   * Resume paused animations on an element
   * @param {HTMLElement} element Element to resume (or null for all)
   */
  resume(element = null) {
    for (const [id, data] of this.state.paused) {
      if (!element || data.element === element) {
        if (data.animation.playState === 'paused') {
          data.animation.play();
          this.state.paused.delete(id);
        }
      }
    }
  },

  /**
   * Reverse running animations on an element
   * @param {HTMLElement} element Element to reverse (or null for all)
   */
  reverse(element = null) {
    for (const [id, data] of this.state.running) {
      if (!element || data.element === element) {
        data.animation.reverse();
      }
    }
  },

  /**
   * Get animation configuration
   * @private
   */
  getAnimationConfig(animation, options) {
    // Handle predefined animations
    if (typeof animation === 'string') {
      const preset = this.config.animations[animation];
      if (!preset) {
        throw new Error(`Animation "${animation}" not found`);
      }
      return preset[options.direction || 'in'];
    }

    // Handle custom keyframes
    return animation;
  },

  /**
   * Get cache key for animation
   * @private
   */
  getCacheKey(animation, options) {
    const name = typeof animation === 'string' ? animation : JSON.stringify(animation);
    const direction = options.direction || 'in';
    return `${name}_${direction}`;
  },

  /**
   * Create keyframes from config
   * @private
   */
  createKeyframes(config) {
    const keyframes = [];

    // Add 'from' state
    if (config.from) {
      keyframes.push({...config.from, offset: 0});
    }

    // Add intermediate steps
    if (config.steps && Array.isArray(config.steps)) {
      config.steps.forEach(step => {
        keyframes.push(step);
      });
    }

    // Add 'to' state
    if (config.to) {
      keyframes.push({...config.to, offset: 1});
    }

    return keyframes;
  },

  /**
   * Create timing options
   * @private
   */
  createTiming(options) {
    // Resolve easing preset if needed
    let easing = options.easing || this.config.defaultEasing;
    if (this.config.easings[easing]) {
      easing = this.config.easings[easing];
    }

    return {
      duration: options.duration || this.config.defaultDuration,
      easing: easing,
      delay: options.delay || 0,
      iterations: options.iterations || 1,
      direction: options.animationDirection || 'normal',
      fill: options.fill || 'both'
    };
  },

  /**
   * Monitor animation performance
   * @private
   */
  setupPerformanceObserver() {
    try {
      // Check if 'animation' entry type is supported
      if (typeof PerformanceObserver === 'undefined') return;

      const supportedTypes = PerformanceObserver.supportedEntryTypes || [];
      if (!supportedTypes.includes('animation')) return;

      const observer = new PerformanceObserver((list) => {
        for (const entry of list.getEntries()) {
          // Report metrics
          EventManager.emit('animation:performance', {
            animation: entry.name,
            duration: entry.duration,
            startTime: entry.startTime
          });
        }
      });

      observer.observe({entryTypes: ['animation']});
    } catch (e) {
      // Animation entry type may not be supported
    }
  },

  /**
   * Stop running animations
   * @param {HTMLElement} element Optional element to stop animations for
   */
  stop(element = null) {
    for (const [id, data] of this.state.running) {
      if (!element || data.element === element) {
        data.animation.cancel();
        data.cleanup();
        this.state.running.delete(id);
      }
    }
  },

  /**
   * Finish running animations immediately
   * @param {HTMLElement} element Optional element to finish animations for
   */
  finish(element = null) {
    for (const [id, data] of this.state.running) {
      if (!element || data.element === element) {
        data.animation.finish();
      }
    }
  },

  /**
   * Check if element has running animations
   * @param {HTMLElement} element Element to check
   * @returns {boolean} True if element has running animations
   */
  isAnimating(element) {
    for (const [id, data] of this.state.running) {
      if (data.element === element && data.animation.playState === 'running') {
        return true;
      }
    }
    return false;
  },

  /**
   * Get running animations count
   * @param {HTMLElement} element Optional element to check
   * @returns {number} Number of running animations
   */
  getRunningCount(element = null) {
    if (!element) {
      return this.state.running.size;
    }
    let count = 0;
    for (const [id, data] of this.state.running) {
      if (data.element === element) count++;
    }
    return count;
  },

  /**
   * Register a custom animation preset
   * @param {string} name Animation name
   * @param {Object} config Animation configuration with 'in' and/or 'out' states
   */
  registerAnimation(name, config) {
    if (!name || !config) {
      throw new Error('Animation name and config are required');
    }
    this.config.animations[name] = config;
  },

  /**
   * Register a custom easing preset
   * @param {string} name Easing name
   * @param {string} value CSS easing value
   */
  registerEasing(name, value) {
    if (!name || !value) {
      throw new Error('Easing name and value are required');
    }
    this.config.easings[name] = value;
  },

  /**
   * Clear animation cache
   */
  clearCache() {
    this.state.cache.clear();
  },

  /**
   * Destroy animation manager
   */
  destroy() {
    this.stop();
    this.clearQueue();
    this.clearCache();
    this.state.paused.clear();
    this.stopAutoInit();
  },

  // ============================================
  // Auto-Initialization System
  // ============================================

  /**
   * Auto-init state
   */
  _autoInit: {
    initialized: false,
    enterObserver: null,
    leaveObserver: null,
    mutationObserver: null,
    animateHandlers: new WeakMap()
  },

  /**
   * Initialize all animation data attributes automatically
   * Call this to enable declarative animations without JavaScript
   */
  autoInit(container = document) {
    if (this._autoInit.initialized && container === document) return;

    // Initialize enter animations (scroll reveal)
    this._initEnterAnimations(container);

    // Initialize leave animations
    this._initLeaveAnimations(container);

    // Initialize click-triggered animations
    this._initAnimateTriggers(container);

    // Setup mutation observer for dynamic content (only once for document)
    if (container === document && !this._autoInit.mutationObserver) {
      this._setupMutationObserver();
    }

    if (container === document) {
      this._autoInit.initialized = true;
    }
  },

  /**
   * Initialize data-enter animations (scroll reveal)
   */
  _initEnterAnimations(container) {
    // Create single shared observer if not exists
    if (!this._autoInit.enterObserver) {
      this._autoInit.enterObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            const el = entry.target;
            const animation = el.dataset.enter;
            const duration = parseInt(el.dataset.enterDuration) || this.config.defaultDuration;

            this.animate(el, animation, {
              direction: 'in',
              duration: duration,
              onComplete: () => {
                EventManager.emit('animation:enter:complete', {element: el, animation});
              }
            });

            // Unobserve unless repeat is set
            if (!el.dataset.enterRepeat) {
              this._autoInit.enterObserver.unobserve(el);
            }
          }
        });
      }, {
        threshold: 0.1,
        rootMargin: '0px'
      });
    }

    // Find and observe elements
    const elements = container.querySelectorAll('[data-enter]:not([data-anim-initialized])');
    elements.forEach(el => {
      // Skip if already processed by TemplateManager
      if (el._animBinding) return;

      el.dataset.animInitialized = 'true';
      this._autoInit.enterObserver.observe(el);
    });
  },

  /**
   * Initialize data-leave animations
   */
  _initLeaveAnimations(container) {
    if (!this._autoInit.leaveObserver) {
      this._autoInit.leaveObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
          if (!entry.isIntersecting) {
            const el = entry.target;
            // Only trigger if element was previously visible
            if (el._wasVisible) {
              const animation = el.dataset.leave;
              const duration = parseInt(el.dataset.leaveDuration) || this.config.defaultDuration;

              this.animate(el, animation, {
                direction: 'out',
                duration: duration,
                onComplete: () => {
                  EventManager.emit('animation:leave:complete', {element: el, animation});
                }
              });
            }
          } else {
            entry.target._wasVisible = true;
          }
        });
      }, {
        threshold: 0.1
      });
    }

    const elements = container.querySelectorAll('[data-leave]:not([data-anim-initialized])');
    elements.forEach(el => {
      if (el._animBinding) return;
      el.dataset.animInitialized = 'true';
      this._autoInit.leaveObserver.observe(el);
    });
  },

  /**
   * Initialize data-animate click triggers
   * Usage: <button data-animate="targetId" data-animation="bounce">
   */
  _initAnimateTriggers(container) {
    const triggers = container.querySelectorAll('[data-animate]:not([data-anim-trigger-initialized])');

    triggers.forEach(trigger => {
      trigger.dataset.animTriggerInitialized = 'true';

      const handler = () => {
        const targetId = trigger.dataset.animate;
        const animation = trigger.dataset.animation || 'fade';
        const duration = parseInt(trigger.dataset.animationDuration) || this.config.defaultDuration;
        const direction = trigger.dataset.animationDirection || 'in';
        const target = document.getElementById(targetId);

        if (target) {
          this.animate(target, animation, {
            direction: direction,
            duration: duration
          });
        }
      };

      trigger.addEventListener('click', handler);
      this._autoInit.animateHandlers.set(trigger, handler);
    });
  },

  /**
   * Setup MutationObserver for dynamic content
   */
  _setupMutationObserver() {
    this._autoInit.mutationObserver = new MutationObserver((mutations) => {
      let hasNewElements = false;

      mutations.forEach(mutation => {
        mutation.addedNodes.forEach(node => {
          if (node.nodeType === 1) { // Element node
            // Check if node itself has animation attributes
            if (node.dataset?.enter || node.dataset?.leave || node.dataset?.animate) {
              hasNewElements = true;
            }
            // Check children
            if (node.querySelectorAll) {
              const animElements = node.querySelectorAll('[data-enter], [data-leave], [data-animate]');
              if (animElements.length > 0) {
                hasNewElements = true;
              }
            }
          }
        });
      });

      // Re-init if new elements found
      if (hasNewElements) {
        // Debounce to avoid excessive calls
        clearTimeout(this._autoInit.reinitTimeout);
        this._autoInit.reinitTimeout = setTimeout(() => {
          this.autoInit(document);
        }, 50);
      }
    });

    this._autoInit.mutationObserver.observe(document.body, {
      childList: true,
      subtree: true
    });
  },

  /**
   * Stop auto-initialization and cleanup
   */
  stopAutoInit() {
    if (this._autoInit.enterObserver) {
      this._autoInit.enterObserver.disconnect();
      this._autoInit.enterObserver = null;
    }
    if (this._autoInit.leaveObserver) {
      this._autoInit.leaveObserver.disconnect();
      this._autoInit.leaveObserver = null;
    }
    if (this._autoInit.mutationObserver) {
      this._autoInit.mutationObserver.disconnect();
      this._autoInit.mutationObserver = null;
    }
    this._autoInit.initialized = false;
  }
};

// Auto-register with Now framework
if (window.Now?.registerManager) {
  Now.registerManager('animation', AnimationManager);
}

// Auto-init when DOM is ready (opt-in via data-animation-auto on body or html)
document.addEventListener('DOMContentLoaded', () => {
  // Check if auto-init is enabled
  const autoInitEnabled = document.body.dataset.animationAuto !== undefined ||
    document.documentElement.dataset.animationAuto !== undefined;

  if (autoInitEnabled) {
    AnimationManager.autoInit();
  }
});

// Expose globally
window.AnimationManager = AnimationManager;

/**
 * Animation directive system
 * Adds animation support via data attributes and properties
 */
Object.assign(TemplateManager, {
  /**
   * Process animation directives
   * @param {HTMLElement} element Element to process
   * @param {Object} context Component context
   */
  processDataAnimation(element, context) {
    if (!element || !context) return;

    // Store initial animation binding if not exists
    if (!element._animBinding) {
      element._animBinding = {
        animations: new Map(),
        observers: new Map(),
        originalState: this.deepClone(context.state),
        originalContext: {...context},
        originalDisplay: element.style.display
      };
    } else {
      this.syncDirectiveBinding(element._animBinding, context);
      if (!(element._animBinding.observers instanceof Map)) {
        element._animBinding.observers = new Map();
      }
    }

    // Process animation directives
    this.processAnimationShow(element, context);
    this.processAnimationEnter(element, context);
    this.processAnimationLeave(element, context);
    this.processAnimationTransition(element, context);
  },

  /**
   * Process data-show animation
   * Shows/hides element with animation
   */
  processAnimationShow(element, context) {
    const value = element.dataset.show;
    if (!value) {
      this.cleanupReactiveUpdate(element, 'Show');
      return;
    }

    try {
      const updateShow = () => {
        const binding = element._animBinding;
        const ctx = this.getDirectiveContext(binding, context);
        const evalState = this.getDirectiveEvalState(binding, context, {
          mergeLiveState: true
        });

        // Get current value
        let isVisible = ExpressionEvaluator.evaluate(value, evalState, ctx);

        // Get animation options
        const animation = element.dataset.showAnimation || 'fade';
        const duration = parseInt(element.dataset.showDuration) || 300;

        // Stop any running animations on this element
        AnimationManager.stop(element);

        if (isVisible) {
          // Show: Remove display: none first
          if (element.style.display === 'none') {
            element.style.display = element._animBinding.originalDisplay || '';
          }

          // Animate in
          AnimationManager.animate(element, animation, {
            direction: 'in',
            duration
          });
        } else {
          // Hide: Animate out, then set display: none
          AnimationManager.animate(element, animation, {
            direction: 'out',
            duration,
            onComplete: () => {
              element.style.display = 'none';
            }
          }).catch(() => {
            // If cancelled (e.g. by quick toggle), don't hide
          });
        }
      };

      // Initial check - if false, hide immediately without animation
      const ctx = this.getDirectiveContext(element._animBinding, context);
      const evalState = this.getDirectiveEvalState(element._animBinding, context, {
        mergeLiveState: true
      });
      let initialVisible = ExpressionEvaluator.evaluate(value, evalState, ctx);

      if (!initialVisible) {
        element.style.display = 'none';
      } else if (element.style.display === 'none') {
        // Restore display for elements that were hidden by an earlier evaluation pass.
        element.style.display = element._animBinding.originalDisplay || '';
      }

      // Setup reactive update
      this.setupReactiveUpdate(element, context, 'Show', updateShow);

    } catch (error) {
      console.error('Error processing animation show:', error);
    }
  },

  /**
   * Process data-enter animation
   * Animates element when entering view
   */
  processAnimationEnter(element, context) {
    const animation = element.dataset.enter;
    const previousObserver = element._animBinding?.observers?.get('enter');
    if (previousObserver) {
      previousObserver.disconnect();
      element._animBinding.observers.delete('enter');
    }

    if (!animation) return;

    try {
      // Create intersection observer
      const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            // Animate when entering view
            AnimationManager.animate(element, animation, {
              duration: parseInt(element.dataset.enterDuration) || 300,
              onComplete: () => {
                // Emit event when enter animation completes
                EventManager.emit('animation:enter:complete', {element, animation});
              }
            });
            // Only animate once unless data-enter-repeat is set
            if (!element.dataset.enterRepeat) {
              observer.unobserve(element);
            }
          }
        });
      }, {
        threshold: parseFloat(element.dataset.enterThreshold) || 0.1
      });

      // Start observing
      observer.observe(element);

      // Store for cleanup
      element._animBinding.observers.set('enter', observer);

    } catch (error) {
      console.error('Error processing animation enter:', error);
    }
  },

  /**
   * Process data-leave animation
   * Animates element when leaving view
   */
  processAnimationLeave(element, context) {
    const animation = element.dataset.leave;
    const previousObserver = element._animBinding?.observers?.get('leave');
    if (previousObserver) {
      previousObserver.disconnect();
      element._animBinding.observers.delete('leave');
    }

    if (!animation) return;

    try {
      // Create intersection observer
      const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
          if (!entry.isIntersecting) {
            // Animate when leaving view
            AnimationManager.animate(element, animation, {
              duration: parseInt(element.dataset.leaveDuration) || 300,
              onComplete: () => {
                // Emit event when leave animation completes

                EventManager.emit('animation:leave:complete', {element, animation});
              }
            });
          }
        });
      }, {
        threshold: parseFloat(element.dataset.leaveThreshold) || 0.1
      });

      // Start observing
      observer.observe(element);

      // Store for cleanup
      element._animBinding.observers.set('leave', observer);

    } catch (error) {
      console.error('Error processing animation leave:', error);
    }
  },

  /**
   * Process data-transition animation
   * Animates between states
   */
  processAnimationTransition(element, context) {
    const value = element.dataset.transition;
    if (!value) {
      this.cleanupReactiveUpdate(element, 'Transition');
      return;
    }

    try {
      const updateTransition = () => {
        const binding = element._animBinding;
        const ctx = this.getDirectiveContext(binding, context);
        const evalState = this.getDirectiveEvalState(binding, context, {
          mergeLiveState: true
        });

        // Get new value
        let newValue = ExpressionEvaluator.evaluate(value, evalState, ctx);

        // Get previous value
        const prevValue = element._animBinding.prevValue;

        // Skip if no change
        if (newValue === prevValue) return;

        // Get animation options
        const animation = element.dataset.transitionAnimation || 'fade';
        const duration = parseInt(element.dataset.transitionDuration) || 300;

        // Animate transition
        AnimationManager.animate(element, animation, {
          duration,
          onComplete: () => {
            // Update previous value after animation completes
            element._animBinding.prevValue = newValue;
            // Emit event
            EventManager.emit('animation:transition:complete', {
              element,
              animation,
              prevValue,
              newValue
            });
          }
        });
      };

      // Initial update
      updateTransition();

      // Setup reactive update
      this.setupReactiveUpdate(element, context, 'Transition', updateTransition);

    } catch (error) {
      console.error('Error processing animation transition:', error);
    }
  },

  /**
   * Clean up animation bindings
   */
  cleanupAnimationBinding(element) {
    if (!element._animBinding) return;

    this.cleanupReactiveUpdate(element, 'Show');
    this.cleanupReactiveUpdate(element, 'Transition');

    // Stop running animations
    AnimationManager.stop(element);

    // Disconnect observers
    if (element._animBinding.observers instanceof Map) {
      element._animBinding.observers.forEach(observer => {
        observer.disconnect();
      });
      element._animBinding.observers.clear();
    } else {
      element._animBinding.observers?.forEach(observer => {
        observer.disconnect();
      });
    }

    // Remove binding
    delete element._animBinding;
  }
});
