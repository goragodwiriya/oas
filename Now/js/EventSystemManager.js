const EventSystemManager = {
  config: {
    cleanupInterval: 600000,
    maxMemoryUsage: 50 * 1024 * 1024,
    delegation: {
      enabled: true,
      matchingStrategy: 'closestFirst',
      maxDelegationDepth: 10,
      optimizeSelectors: true,
      rootElement: document
    },
    memoryManagement: {
      checkInterval: 30000,
      maxHandlersPerElement: 100,
      maxCacheSize: 1000,
      gcThreshold: 0.8,
      detailedTracking: true
    },
    filtering: {
      enabled: true,
      maxThrottleRate: 60, // events per second
      debounceWait: 100, // ms
      highFrequencyEvents: null
    }
  },

  state: {
    handlers: new Map(),
    elementHandlers: new Map(),
    idCounter: 0,
    initialized: false,
    memoryUsage: 0,
    eventPaths: new WeakMap(),
    cleanupTimer: null,
    delegationCache: new WeakMap(),
    selectorCache: new Map(),
    delegatedEvents: new Map(),
    selectorsByType: new Map(),
    selectorMatchCache: new WeakMap(),
    uiEventQueue: new Map(),
    rafScheduled: false,
    memoryStats: {
      handlerCount: 0,
      cacheSize: 0,
      weakMapSize: 0,
      lastGC: Date.now(),
      peakMemoryUsage: 0,
      memoryWarnings: 0
    },
    elementStats: new WeakMap(),
    gcTimer: null,
    filtering: {
      throttleTimers: new Map(),
      debounceTimers: new Map(),
      lastEventTimes: new Map(),
      filteredCount: 0
    },
    // Action registry for data-action attribute
    actions: new Map()
  },

  windowEvents: new Set([
    'popstate',
    'hashchange',
    'resize',
    'scroll',
    'load',
    'pagehide',
    'visibilitychange',
    'beforeunload',
    'online',
    'offline',
    'message',
    'storage'
  ]),

  // Define events that don't use passive mode and can call preventDefault
  nonPassiveEvents: new Set([
    'click',
    'submit',
    'dragstart',
    'dragenter',
    'dragleave',
    'dragover',
    'drop',
    'touchstart',
    'touchmove',
    'wheel',
    'keydown',
    'paste'
  ]),

  supportedEvents: [
    'click', 'dblclick', 'mousedown', 'mouseup', 'mousemove',
    'mouseenter', 'mouseleave', 'mouseover', 'mouseout',
    'submit', 'change', 'input', 'focus', 'blur',
    'keydown', 'keyup', 'keypress', 'paste',
    'touchstart', 'touchend', 'touchmove', 'touchcancel',
    'dragstart', 'dragend', 'dragenter', 'dragleave', 'dragover', 'drop',
    'scroll', 'resize', 'contextmenu', 'wheel',
    'popstate', 'hashchange'
  ],

  uiEvents: new Set([
    'resize',
    'scroll',
    'mousemove',
    'touchmove',
    'drag',
    'dragover'
  ]),

  /**
   * Installs the global delegated listeners, DOM observer, memory monitor and
   * built-in actions.
   *
   * Safe to call repeatedly; addHandler() calls it lazily on first use.
   *
   * @returns {Object} The manager instance
   */
  init() {
    if (this.state.initialized) return this;

    this.config.filtering.highFrequencyEvents = new Set([
      'mousemove',
      'scroll',
      'resize',
      'touchmove',
      'pointermove'
    ]);

    this.state.filtering = {
      throttleTimers: new Map(),
      debounceTimers: new Map(),
      lastEventTimes: new Map(),
      filteredCount: 0
    };

    this.setupGlobalHandlers();
    this.setupCleanup();
    this.observeDOM();
    this.setupMemoryMonitoring();
    this.setupDataActionListener();
    this.registerBuiltInActions();

    this.state.initialized = true;
    return this;
  },

  /**
   * Attaches one capture-phase listener per supported event on the delegation
   * root, and one per window event on window.
   *
   * Every listener is passive unless the event needs preventDefault().
   *
   * @returns {void}
   */
  setupGlobalHandlers() {
    this.supportedEvents.forEach(type => {
      this.config.delegation.rootElement.addEventListener(type, e => this.handleEvent(e), {
        capture: true,
        passive: !this.nonPassiveEvents.has(type)
      });
    });

    this.windowEvents.forEach(type => {
      window.addEventListener(type, e => this.handleWindowEvent(e), {
        capture: true,
        passive: !this.nonPassiveEvents.has(type)
      });
    });
  },

  /**
   * Registers an event handler in the delegated event system.
   *
   * Window events are rebound to window automatically. When a selector is
   * given the handler is delegated and only runs for matching descendants.
   *
   * @param {Element|Window|Document} element - Element to bind to
   * @param {string} type - Event type, must be in supportedEvents
   * @param {Function} handler - Handler receiving the wrapped event
   * @param {Object} [options={}] - Registration options
   * @param {boolean} [options.capture] - Run in the capture phase
   * @param {boolean} [options.once] - Not implemented by the dispatcher
   * @param {boolean} [options.passive] - Declare the handler passive
   * @param {number} [options.priority] - Higher runs first within a phase
   * @param {string} [options.componentId] - Owner id for removeComponentHandlers()
   * @param {string} [options.selector] - Delegate to descendants matching it
   * @returns {number} Handler id for removeHandler()
   * @throws {Error} When the event type, element or handler is invalid
   */
  addHandler(element, type, handler, options = {}) {
    // Lazy init: ensure global listeners are installed before registering any handler
    if (!this.state.initialized) {
      this.init();
    }

    if (!type || !this.supportedEvents.includes(type)) {
      throw new Error(`Unsupported event: ${type}`);
    }

    const isWindowEvent = this.windowEvents.has(type);
    if (isWindowEvent) {
      element = window;
    }

    const isDocumentEvent = element === document;

    if (!element || !(element instanceof Element || element === window || element === document)) {
      throw new Error('Invalid element provided');
    }

    if (typeof handler !== 'function') {
      throw new Error('Handler must be a function');
    }

    const id = ++this.state.idCounter;
    const entry = {
      id,
      type,
      handler,
      element,
      isWindowEvent,
      isDocumentEvent,
      timestamp: Date.now(),
      options: {
        capture: Boolean(options.capture),
        once: Boolean(options.once),
        passive: Boolean(options.passive),
        priority: Number(options.priority) || 0,
        componentId: options.componentId,
        selector: options.selector
      }
    };

    this.state.handlers.set(id, entry);
    if (!isWindowEvent) {
      if (!this.state.elementHandlers.has(element)) {
        this.state.elementHandlers.set(element, new Map());
      }

      const elementHandlers = this.state.elementHandlers.get(element);
      if (!elementHandlers.has(type)) {
        elementHandlers.set(type, new Set());
      }

      elementHandlers.get(type).add(id);

      if (this.config.delegation.enabled && options.selector) {
        if (!this.state.delegatedEvents.has(type)) {
          this.state.delegatedEvents.set(type, new Map());
        }
        const typeSelectors = this.state.delegatedEvents.get(type);
        if (!typeSelectors.has(options.selector)) {
          typeSelectors.set(options.selector, new Set());
        }
        typeSelectors.get(options.selector).add(id);
      }
    }

    return id;
  },

  /**
   * Removes a single handler and its index entries.
   *
   * @param {number} id - Handler id returned by addHandler()
   * @returns {boolean} True when a handler was removed
   */
  removeHandler(id) {
    const entry = this.state.handlers.get(id);
    if (!entry) return false;

    const {element, type} = entry;
    const elementHandlers = this.state.elementHandlers.get(element);
    if (elementHandlers) {
      const typeHandlers = elementHandlers.get(type);
      if (typeHandlers) {
        typeHandlers.delete(id);
        if (typeHandlers.size === 0) {
          elementHandlers.delete(type);
        }
      }
      if (elementHandlers.size === 0) {
        this.state.elementHandlers.delete(element);
      }
    }

    return this.state.handlers.delete(id);
  },

  /**
   * Entry point for every delegated DOM event on the root element.
   *
   * Filters high-frequency events, enriches keyboard events, and defers UI
   * events such as scroll and resize to the animation frame queue.
   *
   * @param {Event} event - Native DOM event
   * @returns {void}
   */
  handleEvent(event) {
    if (!this.shouldProcessEvent(event)) return;

    try {
      if (event.type === 'keydown' || event.type === 'keyup' || event.type === 'keypress') {
        const enhancedKeyEvent = this.handleKeyboardEvent(event);
        event._enhanced = enhancedKeyEvent;
      }

      if (this.uiEvents.has(event.type)) {
        this.queueUIEvent(event);
        return;
      }

      this.processEvent(event);
    } catch (error) {
      ErrorManager.handle(error, {
        context: 'EventSystemManager.handleEvent',
        data: {event}
      });
    }
  },

  /**
   * Builds an enriched view of a keyboard event with the modifier combinations
   * handlers usually test for, such as isShiftEnter and isArrowKey.
   *
   * @param {KeyboardEvent} event - Native keyboard event
   * @returns {Object} Enriched event exposing preventDefault and stopPropagation
   */
  handleKeyboardEvent(event) {
    const enhancedEvent = {
      originalEvent: event,
      key: event.key,
      code: event.code,
      keyCode: event.keyCode,

      ctrlKey: event.ctrlKey || false,
      altKey: event.altKey || false,
      shiftKey: event.shiftKey || false,
      metaKey: event.metaKey || false,

      isEnter: event.key === 'Enter',
      isShiftEnter: event.key === 'Enter' && event.shiftKey,
      isCtrlEnter: event.key === 'Enter' && event.ctrlKey,
      isAltEnter: event.key === 'Enter' && event.altKey,

      isTab: event.key === 'Tab',
      isShiftTab: event.key === 'Tab' && event.shiftKey,

      isArrowKey: ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(event.key),

      preventDefault() {
        event.preventDefault();
      },
      stopPropagation() {
        event.stopPropagation();
      }
    };

    return enhancedEvent;
  },

  /**
   * Dispatches an event fired on window to the handlers registered for it,
   * highest priority first.
   *
   * @param {Event} event - Native window event
   * @returns {void}
   */
  handleWindowEvent(event) {
    if (!this.shouldProcessEvent(event)) {
      return;
    }

    try {
      const context = {
        stopped: false,
        immediateStopped: false,
        path: [window],
        type: event.type,
        key: event.key,
        shiftKey: event.shiftKey,
        ctrlKey: event.ctrlKey,
        altKey: event.altKey,
        metaKey: event.metaKey,
        originalEvent: event,
        target: window,
        currentTarget: window,
        processedHandlers: new Set()
      };

      const handlers = Array.from(this.state.handlers.entries())
        .filter(([_, entry]) =>
          entry.isWindowEvent &&
          entry.type === event.type
        )
        .sort((a, b) =>
          (b[1].options.priority || 0) - (a[1].options.priority || 0)
        );

      for (const [id, entry] of handlers) {
        if (context.immediateStopped) break;

        if (!context.processedHandlers.has(id)) {
          context.processedHandlers.add(id);
          this.executeHandler(entry, context);
        }
      }

    } catch (error) {
      ErrorManager.handle(error, {
        context: 'EventSystemManager.handleWindowEvent',
        data: {event}
      });
    }
  },

  /**
   * Stores a UI event for the next animation frame, keeping only the latest
   * one of each type.
   *
   * @param {Event} event - Native event such as scroll or resize
   * @returns {void}
   */
  queueUIEvent(event) {
    const type = event.type;
    this.state.uiEventQueue.set(type, event);

    if (!this.state.rafScheduled) {
      this.state.rafScheduled = true;
      requestAnimationFrame(() => {
        this.processUIEventQueue();
      });
    }
  },

  /**
   * Dispatches the UI events collected during the current animation frame and
   * clears the queue.
   *
   * @returns {void}
   */
  processUIEventQueue() {
    this.state.rafScheduled = false;

    for (const [type, event] of this.state.uiEventQueue) {
      this.processEvent(event);
    }

    this.state.uiEventQueue.clear();
  },

  /**
   * Runs an event through the synthetic propagation model: capture phase from
   * the outermost node inward, then bubble phase back out.
   *
   * @param {Event} event - Native DOM event
   * @returns {void}
   */
  processEvent(event) {
    const path = this.getEventPath(event);
    const type = event.type;

    const context = {
      stopped: false,
      immediateStopped: false,
      path: path,
      type: type,
      key: event.key,
      shiftKey: event.shiftKey,
      ctrlKey: event.ctrlKey,
      altKey: event.altKey,
      metaKey: event.metaKey,
      originalEvent: event,
      target: event.target,
      currentTarget: null,
      processedHandlers: new Set()
    };

    for (let i = path.length - 1; i >= 0; i--) {
      if (context.stopped) break;
      context.currentTarget = path[i];
      this.processElementHandlers(context, true);
    }

    if (!context.stopped) {
      for (let i = 0; i < path.length; i++) {
        if (context.stopped) break;
        context.currentTarget = path[i];
        this.processElementHandlers(context, false);
      }
    }
  },

  /**
   * Executes the handlers registered on the current node for one phase,
   * highest priority first, and drops index entries left by removed handlers.
   *
   * @param {Object} context - Propagation context built by processEvent()
   * @param {boolean} capture - True for the capture phase
   * @returns {void}
   */
  processElementHandlers(context, capture) {
    const elementHandlers = this.state.elementHandlers.get(context.currentTarget);
    if (!elementHandlers) return;

    const typeHandlers = elementHandlers.get(context.type);

    if (!typeHandlers || typeHandlers.size === 0) return;

    // Collect stale IDs for cleanup
    const staleIds = [];

    const handlers = Array.from(typeHandlers)
      .map(id => {
        const entry = this.state.handlers.get(id);
        // Track stale IDs for cleanup
        if (!entry) {
          staleIds.push(id);
        }
        return entry;
      })
      .filter(entry => {
        // Check if entry exists first
        if (!entry) {
          return false;
        }
        if (context.processedHandlers.has(entry.id)) {
          return false;
        }
        const captureMatch = entry.options.capture === capture;
        return captureMatch;
      })
      .sort((a, b) => (b.options.priority || 0) - (a.options.priority || 0));

    // Clean up stale IDs from typeHandlers to prevent future lookup failures
    if (staleIds.length > 0) {
      staleIds.forEach(id => typeHandlers.delete(id));
    }

    for (const entry of handlers) {
      if (context.immediateStopped) break;

      const shouldHandle = this.shouldHandleEvent(entry, context);
      if (shouldHandle) {
        context.processedHandlers.add(entry.id);
        this.executeHandler(entry, context);
      }
    }
  },

  /**
   * Decides whether a handler applies to the event, resolving the delegate
   * target when the handler was registered with a selector.
   *
   * @param {Object} entry - Handler entry
   * @param {Object} event - Propagation context; receives delegateTarget
   * @returns {boolean} True when the handler should run
   */
  shouldHandleEvent(entry, event) {
    if (entry.options.selector) {
      const delegateTarget = this.findDelegateTarget(event.target, entry.options.selector);
      if (!delegateTarget) return false;
      event.delegateTarget = delegateTarget;
    }
    return true;
  },

  /**
   * Finds the closest ancestor of the target matching the selector, limited to
   * the delegation root. Results are memoized per target.
   *
   * @param {Element} target - Element the event originated from
   * @param {string} selector - CSS selector of the delegate
   * @returns {Element|null} Matching element, or null
   */
  findDelegateTarget(target, selector) {
    const cacheKey = target;
    const cache = this.state.selectorMatchCache;

    if (!cache.has(cacheKey)) {
      cache.set(cacheKey, new Map());
    }

    const targetCache = cache.get(cacheKey);
    if (targetCache.has(selector)) {
      return targetCache.get(selector);
    }

    try {
      const closest = target.closest(selector);
      const result = closest && this.config.delegation.rootElement.contains(closest)
        ? closest
        : null;

      targetCache.set(selector, result);
      return result;
    } catch (error) {
      ErrorManager.handle(`Invalid selector: ${selector}`, {
        context: 'EventSystemManager.findDelegateTarget',
        data: {target, selector, error}
      });
      targetCache.set(selector, null);
      return null;
    }
  },

  /**
   * Calls a handler with a wrapped event, bound to window, document, the
   * delegate target or the registered element as appropriate.
   *
   * Errors are reported to ErrorManager instead of breaking propagation.
   *
   * @param {Object} entry - Handler entry
   * @param {Object} context - Propagation context
   * @returns {*} Value returned by the handler
   */
  executeHandler(entry, context) {
    try {
      const wrappedEvent = {
        type: context.type,
        key: context.key,
        shiftKey: context.shiftKey,
        ctrlKey: context.ctrlKey,
        altKey: context.altKey,
        metaKey: context.metaKey,
        target: context.target,
        currentTarget: context.currentTarget,
        delegateTarget: context.delegateTarget,
        timestamp: Date.now(),
        originalEvent: context.originalEvent,

        preventDefault() {
          context.originalEvent.preventDefault();
        },

        stopPropagation() {
          context.stopped = true;
          context.originalEvent.stopPropagation();
        },

        stopImmediatePropagation() {
          context.stopped = true;
          context.immediateStopped = true;
          context.originalEvent.stopImmediatePropagation();
        },

        matches(selector) {
          return context.target.matches?.(selector) || false;
        }
      };

      let handlerContext;
      if (entry.isWindowEvent) {
        handlerContext = window;
      } else if (entry.isDocumentEvent) {
        handlerContext = document;
      } else if (entry.options.selector && context.delegateTarget) {
        handlerContext = context.delegateTarget;
      } else {
        handlerContext = entry.element;
      }

      const boundHandler = entry.handler.bind(handlerContext);
      return boundHandler(wrappedEvent);

    } catch (error) {
      ErrorManager.handle(error, {
        context: 'EventSystemManager.executeHandler',
        data: {
          handlerId: entry.id,
          type: context.type,
          target: context.target
        }
      });
    }
  },

  /**
   * Returns the propagation path of an event.
   *
   * Uses composedPath() when available, otherwise walks up from the target and
   * caches the result.
   *
   * @param {Event} event - Native DOM event
   * @returns {Array<EventTarget>} Path from the target up to window
   */
  getEventPath(event) {
    if (event && typeof event.composedPath === 'function') {
      return event.composedPath();
    }

    // Fallback for event.target-based path construction
    const target = event?.target || event;

    if (this.state.eventPaths.has(target)) {
      return this.state.eventPaths.get(target);
    }

    const path = [];
    let current = target;

    while (current && current !== window) {
      path.push(current);
      current = current.parentElement || current.parentNode;
    }

    if (document) path.push(document);
    if (window) path.push(window);

    this.state.eventPaths.set(target, path);
    return path;
  },

  /**
   * Starts the periodic cleanup timer, replacing any timer already running.
   *
   * @returns {void}
   */
  setupCleanup() {
    if (this.state.cleanupTimer) {
      clearInterval(this.state.cleanupTimer);
    }

    this.state.cleanupTimer = setInterval(() => {
      this.cleanup();
    }, this.config.cleanupInterval);
  },

  /**
   * Watches the document for removed nodes and releases the handlers of
   * elements that left the DOM for good.
   *
   * @returns {void}
   */
  observeDOM() {
    const observer = new MutationObserver(mutations => {
      mutations.forEach(mutation => {
        mutation.removedNodes.forEach(node => {
          if (node.nodeType === 1) {
            setTimeout(() => {
              if (!node.isConnected) {
                this.removeElementHandlers(node);
              }
            }, 0);
          }
        });
      });
    });

    observer.observe(document.body, {
      childList: true,
      subtree: true
    });
  },

  /**
   * Removes every handler registered on an element.
   *
   * @param {Element} element - Element to release
   * @returns {void}
   */
  removeElementHandlers(element) {
    const handlers = this.state.elementHandlers.get(element);
    if (handlers) {
      handlers.forEach((typeHandlers, type) => {
        typeHandlers.forEach(id => {
          this.removeHandler(id);
        });
      });
      this.state.elementHandlers.delete(element);
    }
  },

  /**
   * Removes every handler registered with the given componentId, used when a
   * component is destroyed.
   *
   * @param {string} componentId - Owner id passed to addHandler()
   * @returns {void}
   */
  removeComponentHandlers(componentId) {
    const handlersToRemove = [];
    this.state.handlers.forEach((entry, id) => {
      if (entry.options.componentId === componentId) {
        handlersToRemove.push(id);
      }
    });
    handlersToRemove.forEach(id => this.removeHandler(id));
  },

  /**
   * Periodic maintenance: drops handlers whose element left the DOM, resets
   * the WeakMap caches and the filtering timers, and triggers a collection
   * pass when memory use is above the limit.
   *
   * @returns {void}
   */
  cleanup() {
    const now = Date.now();

    this.state.handlers.forEach((entry, id) => {
      if (entry.element === window || entry.element === document) {
        return;
      }

      if (!document.contains(entry.element)) {
        this.removeHandler(id);
      }
    });

    this.state.eventPaths = new WeakMap();
    this.state.delegationCache = new WeakMap();
    this.state.selectorMatchCache = new WeakMap();

    this.state.uiEventQueue.clear();

    this.state.filtering = {
      throttleTimers: new Map(),
      debounceTimers: new Map(),
      lastEventTimes: new Map(),
      filteredCount: 0
    };

    if (this.state.memoryUsage > this.config.maxMemoryUsage) {
      this.performGC();
    }
  },

  /**
   * Starts the timer that samples memory usage at the configured interval.
   *
   * @returns {void}
   */
  setupMemoryMonitoring() {
    this.state.gcTimer = setInterval(() => {
      this.checkMemoryUsage();
    }, this.config.memoryManagement.checkInterval);
  },

  /**
   * Samples memory usage, records it, and triggers a collection pass or logs
   * warnings when the thresholds are exceeded.
   *
   * @returns {void}
   */
  checkMemoryUsage() {
    const stats = this.gatherMemoryStats();
    this.updateMemoryStats(stats);

    if (this.shouldTriggerGC(stats)) {
      this.performGC();
    }

    if (this.config.memoryManagement.detailedTracking) {
      this.logMemoryWarnings(stats);
    }
  },

  /**
   * Counts registered handlers, cached selectors, the estimated WeakMap size
   * and the handler count of each element.
   *
   * @returns {Object} Snapshot with handlerCount, cacheSize, weakMapSize,
   *   timestamp and an elementHandlers map
   */
  gatherMemoryStats() {
    const stats = {
      handlerCount: this.state.handlers.size,
      cacheSize: this.state.selectorCache.size,
      weakMapSize: this.estimateWeakMapSize(),
      timestamp: Date.now(),
      elementHandlers: new Map()
    };

    this.state.elementHandlers.forEach((handlers, element) => {
      let count = 0;
      handlers.forEach(typeHandlers => {
        count += typeHandlers.size;
      });
      if (count > 0) {
        stats.elementHandlers.set(element, count);
      }
    });

    return stats;
  },

  /**
   * Estimates how many entries the WeakMap caches hold.
   *
   * WeakMaps cannot be counted, so a sample of the document is probed and the
   * hit rate is extrapolated over all elements.
   *
   * @returns {number} Estimated number of cached entries
   */
  estimateWeakMapSize() {
    const sampleSize = Math.min(100, Math.max(20, Math.floor(document.querySelectorAll('*').length * 0.1)));

    const start = performance.now();

    const elements = Array.from(document.querySelectorAll('*'));

    const sample = [];
    const step = Math.floor(elements.length / sampleSize);

    for (let i = 0; i < elements.length && sample.length < sampleSize; i += step) {
      sample.push(elements[i]);
    }

    let sampleCount = 0;
    sample.forEach(element => {
      if (this.state.elementHandlers.has(element)) sampleCount++;
      if (this.state.eventPaths.has(element)) sampleCount++;
      if (this.state.delegationCache.has(element)) sampleCount++;
      if (this.state.selectorMatchCache.has(element)) sampleCount++;
    });

    const estimatedSize = Math.round(
      (sampleCount / sample.length) * elements.length
    );

    const duration = performance.now() - start;

    return estimatedSize;
  },

  /**
   * Copies a sample into the running memory statistics and updates the peak.
   *
   * @param {Object} stats - Sample from gatherMemoryStats()
   * @returns {void}
   */
  updateMemoryStats(stats) {
    const memoryStats = this.state.memoryStats;
    memoryStats.handlerCount = stats.handlerCount;
    memoryStats.cacheSize = stats.cacheSize;
    memoryStats.weakMapSize = stats.weakMapSize;
    memoryStats.peakMemoryUsage = Math.max(
      memoryStats.peakMemoryUsage,
      stats.handlerCount + stats.cacheSize
    );
  },

  /**
   * Tests a memory sample against the configured thresholds.
   *
   * @param {Object} stats - Sample from gatherMemoryStats()
   * @returns {boolean} True when a collection pass should run
   */
  shouldTriggerGC(stats) {
    const threshold = this.config.memoryManagement.gcThreshold;
    const maxHandlers = this.config.memoryManagement.maxHandlersPerElement;

    return (
      stats.handlerCount > (this.config.maxMemoryUsage * threshold) ||
      stats.cacheSize > this.config.memoryManagement.maxCacheSize ||
      Array.from(stats.elementHandlers.values()).some(count => count > maxHandlers)
    );
  },

  /**
   * Releases stale handlers and unused caches.
   *
   * @returns {void}
   */
  performGC() {
    const now = Date.now();
    const stats = {
      handlersRemoved: 0,
      cachesCleared: 0
    };

    this.state.handlers.forEach((entry, id) => {
      if (this.isHandlerStale(entry, now)) {
        this.removeHandler(id);
        stats.handlersRemoved++;
      }
    });

    this.clearUnusedCaches();
    stats.cachesCleared = this.state.selectorCache.size;

    this.state.memoryStats.lastGC = now;

    if (this.config.memoryManagement.detailedTracking) {
      console.info('GC Stats:', stats);
    }
  },

  /**
   * Decides whether a handler can be released: too old, detached from the
   * document, or bound to an invisible element.
   *
   * @param {Object} entry - Handler entry
   * @param {number} now - Current timestamp in milliseconds
   * @returns {boolean} True when the handler is stale
   */
  isHandlerStale(entry, now) {
    if (!entry.timestamp) return false;

    const age = now - entry.timestamp;
    const element = entry.element;

    return (
      age > this.config.cleanupInterval * 2 ||
      !document.contains(element) ||
      !this.isElementVisible(element)
    );
  },

  /**
   * Drops expired selector cache entries, and resets the WeakMap caches
   * entirely when they grew past the configured size.
   *
   * @returns {void}
   */
  clearUnusedCaches() {
    for (const [selector, timestamp] of this.state.selectorCache) {
      if (Date.now() - timestamp > this.config.cleanupInterval) {
        this.state.selectorCache.delete(selector);
      }
    }

    if (this.state.memoryStats.weakMapSize > this.config.memoryManagement.maxCacheSize) {
      this.state.eventPaths = new WeakMap();
      this.state.delegationCache = new WeakMap();
      this.state.selectorMatchCache = new WeakMap();
    }
  },

  /**
   * Warns about elements carrying more handlers than the configured maximum,
   * which usually means handlers are being registered without being removed.
   *
   * @param {Object} stats - Sample from gatherMemoryStats()
   * @returns {void}
   */
  logMemoryWarnings(stats) {
    const warnings = [];
    const maxHandlers = this.config.memoryManagement.maxHandlersPerElement;

    stats.elementHandlers.forEach((count, element) => {
      if (count > maxHandlers) {
        warnings.push(`Element has too many handlers (${count}): ${element.tagName}`);
      }
    });

    if (warnings.length > 0) {
      this.state.memoryStats.memoryWarnings++;
      console.warn('Memory warnings:', warnings);
    }
  },

  /**
   * Stops the timers, drops every handler and cache, and marks the manager
   * uninitialized so init() can run again.
   *
   * @returns {void}
   */
  destroy() {
    if (this.state.cleanupTimer) {
      clearInterval(this.state.cleanupTimer);
    }

    this.state.handlers.clear();
    this.state.elementHandlers = new WeakMap();
    this.state.eventPaths = new WeakMap();
    this.state.delegationCache = new WeakMap();
    this.state.selectorCache.clear();
    this.state.selectorMatchCache = new WeakMap();
    this.state.uiEventQueue.clear();
    this.state.initialized = false;

    if (this.state.gcTimer) {
      clearInterval(this.state.gcTimer);
    }
    this.state.memoryStats = {
      handlerCount: 0,
      cacheSize: 0,
      weakMapSize: 0,
      lastGC: 0,
      peakMemoryUsage: 0,
      memoryWarnings: 0
    };
  },

  /**
   * Rate-limits events when filtering is enabled.
   *
   * Events listed as high frequency are handled by the animation frame queue
   * instead and pass through untouched.
   *
   * @param {Event} event - Native DOM event
   * @returns {boolean} True when the event should be dispatched
   */
  shouldProcessEvent(event) {
    if (!this.config.filtering.enabled) return true;

    const type = event.type;

    if (!this.config.filtering.highFrequencyEvents?.has(type)) {
      const now = performance.now();
      const lastTime = this.state.filtering.lastEventTimes.get(type) || 0;
      const minInterval = 1000 / this.config.filtering.maxThrottleRate;

      if (now - lastTime < minInterval) {
        this.state.filtering.filteredCount++;
        return false;
      }

      this.state.filtering.lastEventTimes.set(type, now);
    }

    return true;
  },

  /**
   * Reports whether an event arrived sooner after the previous one of the same
   * type than the throttle rate allows.
   *
   * @param {Event} event - Native DOM event
   * @returns {boolean} True when the event is firing too fast
   */
  isRapidFire(event) {
    const now = performance.now();
    const type = event.type;

    const lastTime = this.state.filtering.lastEventTimes.get(type) || 0;
    const minInterval = 1000 / this.config.filtering.maxThrottleRate;

    if (now - lastTime < minInterval) {
      return true;
    }

    this.state.filtering.lastEventTimes.set(type, now);
    return false;
  },

  /**
   * Calls the handler at most once per throttle interval for this event type.
   *
   * @param {Event} event - Native DOM event
   * @param {Function} handler - Handler to rate-limit
   * @returns {void}
   */
  throttle(event, handler) {
    const type = event.type;
    const now = Date.now();
    const lastTime = this.state.filtering.throttleTimers.get(type) || 0;
    const threshold = 1000 / this.config.filtering.maxThrottleRate;

    if (now - lastTime >= threshold) {
      handler(event);
      this.state.filtering.throttleTimers.set(type, now);
    }
  },

  /**
   * Delays the handler until the event type has been quiet for the configured
   * debounce wait, restarting the timer on every call.
   *
   * @param {Event} event - Native DOM event
   * @param {Function} handler - Handler to defer
   * @returns {void}
   */
  debounce(event, handler) {
    const type = event.type;
    clearTimeout(this.state.filtering.debounceTimers.get(type));

    this.state.filtering.debounceTimers.set(type,
      setTimeout(() => {
        handler(event);
        this.state.filtering.debounceTimers.delete(type);
      }, this.config.filtering.debounceWait)
    );
  },

  // ==================== Action Registry ====================

  /**
   * Register a named action handler
   * @param {string} name - Action name (e.g., 'copyToClipboard')
   * @param {Function} handler - Handler function receiving (event, element, args)
   */
  registerAction(name, handler) {
    if (typeof handler !== 'function') {
      console.error(`[EventSystemManager] Action handler must be a function: ${name}`);
      return;
    }
    this.state.actions.set(name, handler);
  },

  /**
   * Unregister a named action
   * @param {string} name - Action name to unregister
   */
  unregisterAction(name) {
    this.state.actions.delete(name);
  },

  /**
   * Setup global delegated listener for data-action attribute
   * Format: data-action="event:actionName" or "event.modifier:actionName(args)"
   */
  setupDataActionListener() {
    document.addEventListener('click', (event) => {
      this.handleDataAction(event, 'click');
    }, true);

    document.addEventListener('change', (event) => {
      this.handleDataAction(event, 'change');
    }, true);

    document.addEventListener('submit', (event) => {
      this.handleDataAction(event, 'submit');
    }, true);
  },

  /**
   * Handle data-action attribute events
   * @param {Event} event - DOM event
   * @param {string} eventType - Event type (click, change, submit)
   */
  handleDataAction(event, eventType) {
    const element = event.target.closest('[data-action]');
    if (!element) return;

    const dataAction = element.dataset.action;
    if (!dataAction) return;

    // Parse data-action value: "event:actionName" or "event.modifier:actionName(args)"
    const bindings = dataAction.split(',').map(b => b.trim());

    for (const binding of bindings) {
      const colonIndex = binding.indexOf(':');
      if (colonIndex === -1) continue;

      const eventInfo = binding.substring(0, colonIndex).trim();
      const handlerExpr = binding.substring(colonIndex + 1).trim();
      if (!eventInfo || !handlerExpr) continue;

      // Parse event type and modifiers (e.g., "click.prevent.stop")
      const [bindEventType, ...modifiers] = eventInfo.split('.');
      if (bindEventType !== eventType) continue;

      // Apply modifiers
      if (modifiers.includes('prevent')) {
        event.preventDefault();
      }
      if (modifiers.includes('stop')) {
        event.stopPropagation();
      }

      // Parse action name and arguments (e.g., "copyToClipboard(param)")
      const match = handlerExpr.match(/^([\w.]+)(?:\((.*)\))?$/);
      if (!match) continue;

      const actionName = match[1];
      const argsStr = match[2] || '';

      // Parse arguments
      const args = argsStr ? this.parseActionArgs(argsStr, element) : [];

      if (eventType === 'change' && this.isRequestAction(actionName, element) && this.shouldSkipRequestApiChange(event, element)) {
        continue;
      }

      // Execute action
      this.executeAction(actionName, event, element, args);

      // Handle 'once' modifier
      if (modifiers.includes('once')) {
        element.removeAttribute('data-action');
      }

      return; // Only handle first matching binding
    }
  },

  /**
   * Does this binding call an API, whatever the action is named?
   *
   * The programmatic-change guard used to look only for the literal action name
   * `requestApi`. Anything wrapping it — the common case, an app-defined action that
   * calls `requestApi` and then refreshes a table — got no protection at all, and had
   * no way to ask for it short of reaching into internals.
   *
   * The result was a loop that is easy to build and hard to read: a checkbox bound to
   * `change`, the request refreshes the component, the refresh re-binds the checkbox,
   * that assignment fires a synthetic `change`, and round it goes.
   *
   * `data-api-url` is the honest signal, because it is what makes a binding issue a
   * request. Bindings without it are untouched, so no existing change handler starts
   * behaving differently.
   *
   * @param {string} actionName
   * @param {HTMLElement} element
   * @returns {boolean}
   */
  isRequestAction(actionName, element) {
    return actionName === 'requestApi' || Boolean(element?.dataset?.apiUrl);
  },

  /**
   * Reads data-request-api-on-programmatic-change, the opt-in that lets a
   * binding fire on synthetic change events as well as real ones.
   *
   * @param {HTMLElement} element - Element carrying the binding
   * @returns {boolean} True when programmatic changes are allowed
   */
  shouldAllowProgrammaticRequestApiChange(element) {
    const value = String(element?.dataset?.requestApiOnProgrammaticChange || '').trim().toLowerCase();

    return ['true', '1', 'yes', 'on'].includes(value);
  },

  /**
   * Decides whether a change event on an API binding must be ignored.
   *
   * Untrusted events are dropped unless the element opted in, which is what
   * breaks the refresh loop described on isRequestAction().
   *
   * @param {Event} event - Change event
   * @param {HTMLElement} element - Element carrying the binding
   * @returns {boolean} True when the request must not be sent
   */
  shouldSkipRequestApiChange(event, element) {
    if (!element) {
      return false;
    }

    if (!event?.isTrusted && !this.shouldAllowProgrammaticRequestApiChange(element)) {
      return true;
    }

    return this.shouldSkipAutocompleteNativeChange(event, element);
  },

  /**
   * Suppresses the native change event a field emits right after an
   * autocomplete selection already sent its own request.
   *
   * Only applies within 500 ms of the selection and only when neither the
   * submitted nor the displayed value changed since.
   *
   * @param {Event} event - Change event
   * @param {HTMLElement} element - Element carrying the binding
   * @returns {boolean} True when the event duplicates the selection
   */
  shouldSkipAutocompleteNativeChange(event, element) {
    if (!event?.isTrusted || !element) {
      return false;
    }

    const instance = window.ElementManager?.getInstanceByElement?.(element);
    const meta = instance?._lastAutocompleteSelectionChange;
    if (!meta) {
      return false;
    }

    const now = Date.now();
    if (now - meta.timestamp > 500) {
      delete instance._lastAutocompleteSelectionChange;
      return false;
    }

    const currentSubmittedValue = this.getRequestFieldValue(element);
    const currentDisplayValue = 'value' in element ? String(element.value ?? '') : '';
    const sameSubmittedValue = String(currentSubmittedValue ?? '') === String(meta.submittedValue ?? '');
    const sameDisplayValue = currentDisplayValue === String(meta.displayValue ?? '');

    delete instance._lastAutocompleteSelectionChange;

    return sameSubmittedValue && sameDisplayValue;
  },

  /**
   * Parse action arguments from string
   * @param {string} argsStr - Arguments string (e.g., "'#modal', true")
   * @param {HTMLElement} element - The element with data-action
   * @returns {Array} Parsed arguments
   */
  parseActionArgs(argsStr, element) {
    const args = [];
    // Simple parsing - split by comma, handle quotes
    const parts = argsStr.match(/(?:[^,'"]|'[^']*'|"[^"]*")+/g) || [];

    for (let part of parts) {
      part = part.trim();
      if (!part) continue;

      // String literal
      if ((part.startsWith("'") && part.endsWith("'")) ||
        (part.startsWith('"') && part.endsWith('"'))) {
        args.push(part.slice(1, -1));
      }
      // 'this' refers to element
      else if (part === 'this') {
        args.push(element);
      }
      // Boolean
      else if (part === 'true') {
        args.push(true);
      } else if (part === 'false') {
        args.push(false);
      }
      // Number
      else if (!isNaN(part)) {
        args.push(Number(part));
      }
      // Element selector
      else if (part.startsWith('#') || part.startsWith('.')) {
        args.push(document.querySelector(part));
      }
      // Variable reference - try to evaluate
      else {
        args.push(part);
      }
    }

    return args;
  },

  /**
   * Collect request parameters from data-param-* attributes.
   * Supports literal values and placeholder syntax like {customer_id}.
   * @param {HTMLElement} element
   * @returns {Object}
   */
  collectRequestParams(element) {
    const params = {};

    Object.keys(element.dataset).forEach((key) => {
      if (!key.startsWith('param')) {
        return;
      }

      const rawName = key.replace('param', '');
      if (!rawName) {
        return;
      }

      const normalizedName = rawName
        .replace(/([A-Z])/g, '_$1')
        .replace(/[-\s]+/g, '_')
        .replace(/^_+/, '')
        .toLowerCase();

      params[normalizedName] = this.resolveRequestParamValue(element.dataset[key], element);
    });

    return params;
  },

  /**
   * Resolve request parameter values.
   * Placeholder syntax {field_name} is resolved from dataset, closest form, URL, or ancestor dataset.
   * @param {string} value
   * @param {HTMLElement} element
   * @returns {*}
   */
  resolveRequestParamValue(value, element) {
    if (typeof value !== 'string') {
      return value;
    }

    const trimmed = value.trim();
    if (!trimmed) {
      return '';
    }

    if (trimmed.startsWith('{') && trimmed.endsWith('}')) {
      return this.resolveRequestFieldValue(trimmed.slice(1, -1).trim(), element);
    }

    if (trimmed === 'true') {
      return true;
    }

    if (trimmed === 'false') {
      return false;
    }

    if (trimmed === 'null') {
      return null;
    }

    return trimmed;
  },

  /**
   * Resolve placeholder field values for request params.
   * @param {string} fieldName
   * @param {HTMLElement} element
   * @returns {*}
   */
  resolveRequestFieldValue(fieldName, element) {
    if (!fieldName) {
      return '';
    }

    if (element.dataset[fieldName] !== undefined) {
      return element.dataset[fieldName];
    }

    const input = this.findRequestField(element, fieldName);
    if (input) {
      return this.getRequestFieldValue(input);
    }

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has(fieldName)) {
      return urlParams.get(fieldName);
    }

    const dataFieldName = fieldName.replace(/_([a-z])/g, (_, char) => char.toUpperCase());
    const parent = element.closest(`[data-${fieldName.replace(/_/g, '-')}]`);
    if (parent?.dataset?.[dataFieldName] !== undefined) {
      return parent.dataset[dataFieldName];
    }

    return '';
  },

  /**
   * Return the submitted value for a request field.
   * Uses the hidden input from enhanced text fields when available.
   * @param {HTMLElement} field
   * @returns {*}
   */
  getRequestFieldValue(field) {
    if (!field) {
      return '';
    }

    const instance = window.ElementManager?.getInstanceByElement?.(field);
    if (instance?.hiddenInput) {
      return instance.hiddenInput.value;
    }

    if (field.type === 'radio') {
      const form = field.form || field.closest?.('form') || document;
      const checked = field.name
        ? form.querySelector(`[name="${field.name}"]:checked`)
        : field;
      return checked ? checked.value : '';
    }

    if (field.type === 'checkbox') {
      return field.checked ? (field.value || true) : '';
    }

    return field.value;
  },

  /**
   * Check whether the placeholder points to a CSS selector.
   * @param {string} fieldName
   * @returns {boolean}
   */
  isRequestSelector(fieldName) {
    return typeof fieldName === 'string'
      && ['#', '.', '['].includes(fieldName.charAt(0));
  },

  /**
   * Find form control by name or id from the closest form.
   * @param {HTMLElement} element
   * @param {string} fieldName
   * @returns {HTMLElement|null}
   */
  findRequestField(element, fieldName) {
    if (!fieldName) {
      return null;
    }

    const form = element.closest('form');
    const searchRoots = [form, document].filter(Boolean);

    if (this.isRequestSelector(fieldName)) {
      for (const root of searchRoots) {
        try {
          const matched = root.querySelector(fieldName);
          if (matched) {
            return matched;
          }
        } catch (error) {
          console.warn(`[EventSystemManager] Invalid request field selector: ${fieldName}`, error);
          return null;
        }
      }

      return null;
    }

    const escapedFieldName = typeof CSS !== 'undefined' && typeof CSS.escape === 'function'
      ? CSS.escape(fieldName)
      : fieldName.replace(/([ #;?%&,.+*~\':"!^$\[\]()=>|\/@])/g, '\\$1');

    for (const root of searchRoots) {
      const byName = root.querySelector(`[name="${fieldName}"]`);
      if (byName) {
        return byName;
      }

      const byId = root.querySelector(`#${escapedFieldName}`);
      if (byId) {
        return byId;
      }
    }

    return null;
  },

  /**
   * Validate required request fields before sending the API call.
   * @param {HTMLElement} element
   * @returns {boolean}
   */
  validateRequestFields(element) {
    const requiredFields = String(element.dataset.requiredFields || '')
      .split(',')
      .map(field => field.trim())
      .filter(Boolean);

    if (requiredFields.length === 0) {
      return true;
    }

    const invalidField = requiredFields
      .map(fieldName => this.findRequestField(element, fieldName))
      .find(field => field && String(this.getRequestFieldValue(field) || '').trim() === '');

    if (!invalidField) {
      return true;
    }

    if (window.NotificationManager) {
      NotificationManager.error(Now.translate(element.dataset.requiredMessage || 'Please fill in the required fields'));
    }

    if (typeof invalidField.focus === 'function') {
      invalidField.focus();
    }

    return false;
  },

  /**
   * Resolve an optional response target element for requestApi actions.
   * @param {HTMLElement} element
   * @returns {HTMLElement|null}
   */
  resolveRequestResponseTarget(element) {
    const target = String(element.dataset.responseTarget || '').trim();

    if (!target) {
      return null;
    }

    if (['self', 'trigger', 'current', 'element'].includes(target.toLowerCase())) {
      return element;
    }

    if (target.toLowerCase() === 'form') {
      return element.closest('form');
    }

    try {
      return document.querySelector(target);
    } catch (error) {
      console.warn(`[EventSystemManager] Invalid data-response-target selector: ${target}`, error);
      return null;
    }
  },

  /**
   * Extract the API payload that should be passed to ResponseHandler.
   * @param {Object} response
   * @returns {*}
   */
  extractRequestApiPayload(response) {
    if (!response || typeof response !== 'object') {
      return response;
    }

    const body = response.data && typeof response.data === 'object'
      ? response.data
      : null;

    if (!body) {
      return response;
    }

    if (body.data && typeof body.data === 'object') {
      return body.data;
    }

    return body;
  },

  /**
   * Resolve the optional bind mode for a requestApi response target.
   * @param {HTMLElement} element
   * @returns {string}
   */
  getRequestResponseBindMode(element) {
    const mode = String(element?.dataset?.responseBind || '').trim().toLowerCase();

    if (!mode || ['false', 'off', 'none', '0'].includes(mode)) {
      return '';
    }

    if (['true', '1', 'bind', 'template', 'state'].includes(mode)) {
      return 'template';
    }

    return mode;
  },

  /**
   * Resolve a dot/bracket path on an object.
   * @param {*} source
   * @param {string} path
   * @returns {*}
   */
  resolveRequestResponsePath(source, path) {
    if (!path || typeof path !== 'string') {
      return source;
    }

    const normalizedPath = path
      .replace(/\[("([^"]+)"|'([^']+)'|([^\]]+))\]/g, (_, token, doubleQuoted, singleQuoted, bare) => {
        const key = doubleQuoted || singleQuoted || bare || token;
        return `.${String(key).trim()}`;
      })
      .split('.')
      .map(part => part.trim())
      .filter(Boolean);

    return normalizedPath.reduce((value, key) => {
      if (value == null) {
        return undefined;
      }

      return value[key];
    }, source);
  },

  /**
   * Normalize a payload before binding it into a response target.
   * @param {*} payload
   * @returns {Object}
   */
  normalizeRequestResponseBindingPayload(payload) {
    const source = payload ?? {};

    if (Array.isArray(source)) {
      return {
        data: source,
        rows: source,
        meta: {
          page: 1,
          pageSize: source.length || 0,
          total: source.length || 0,
          totalPages: 1
        },
        submitted: true,
        hasData: source.length > 0,
        empty: source.length === 0,
        raw: source
      };
    }

    if (!source || typeof source !== 'object') {
      return {
        data: source,
        meta: {
          page: 1,
          pageSize: source == null ? 0 : 1,
          total: source == null ? 0 : 1,
          totalPages: 1
        },
        submitted: true,
        hasData: !!source,
        empty: !source,
        raw: source
      };
    }

    const hasOwnData = Object.prototype.hasOwnProperty.call(source, 'data');
    const dataValue = hasOwnData ? source.data : source;
    const primaryCollection = Array.isArray(dataValue)
      ? dataValue
      : Array.isArray(source.rows)
        ? source.rows
        : null;
    const metaSource = source.meta && typeof source.meta === 'object' ? source.meta : {};
    const fallbackTotal = primaryCollection
      ? primaryCollection.length
      : (dataValue ? 1 : 0);
    const fallbackPageSize = primaryCollection
      ? (primaryCollection.length || 1)
      : 1;
    const total = Math.max(0, parseInt(metaSource.total ?? source.total ?? fallbackTotal, 10) || 0);
    const pageSize = Math.max(1, parseInt(metaSource.pageSize ?? metaSource.limit ?? source.pageSize ?? source.limit ?? fallbackPageSize, 10) || 1);
    const fallbackTotalPages = Math.ceil(total / pageSize) || 1;
    const totalPages = Math.max(1, parseInt(metaSource.totalPages ?? source.pages ?? source.totalPages ?? fallbackTotalPages, 10) || 1);
    const page = Math.min(totalPages, Math.max(1, parseInt(metaSource.page ?? source.page ?? 1, 10) || 1));

    return {
      ...source,
      data: dataValue,
      meta: {
        ...metaSource,
        page,
        pageSize,
        total,
        totalPages
      },
      submitted: true,
      hasData: primaryCollection ? primaryCollection.length > 0 : !!dataValue,
      empty: primaryCollection ? primaryCollection.length === 0 : !dataValue,
      raw: source
    };
  },

  /**
   * Bind a successful requestApi payload into the configured response target.
   * @param {HTMLElement} element
   * @param {*} payload
   * @param {Object} context
   * @returns {Object|null}
   */
  bindRequestResponseTarget(element, payload, context = {}) {
    const mode = this.getRequestResponseBindMode(element);
    const target = context.responseTarget || this.resolveRequestResponseTarget(element);

    if (!mode || !target) {
      return null;
    }

    if (mode !== 'template') {
      console.warn(`[EventSystemManager] Unsupported data-response-bind mode: ${mode}`);
      return null;
    }

    if (!window.TemplateManager) {
      console.warn('[EventSystemManager] TemplateManager is required for data-response-bind');
      return null;
    }

    const bindPath = String(element.dataset.responseBindPath || '').trim();
    const bindSource = bindPath ? this.resolveRequestResponsePath(payload, bindPath) : payload;
    const normalized = this.normalizeRequestResponseBindingPayload(bindSource);
    const bindContext = {
      ...context,
      state: normalized,
      data: normalized.data,
      computed: {},
      reactive: false
    };

    window.TemplateManager.processTemplate(target, bindContext);

    try {
      if (typeof window.TemplateManager.processDataOnLoad === 'function') {
        window.TemplateManager.processDataOnLoad(target, bindContext);
      }
    } catch (error) {
      console.warn('[EventSystemManager] requestApi response target data-on-load failed', error);
    }

    return normalized;
  },

  /**
   * Check whether a response payload contains any ResponseHandler actions.
   * @param {*} payload
   * @returns {boolean}
   */
  hasResponseActions(payload) {
    if (!payload || typeof payload !== 'object') {
      return false;
    }

    if (Array.isArray(payload.actions)) {
      return payload.actions.length > 0;
    }

    return !!payload.actions?.type;
  },

  /**
   * Check whether a response payload contains a specific action type.
   * @param {*} payload
   * @param {string} type
   * @returns {boolean}
   */
  hasResponseActionType(payload, type) {
    if (!payload || typeof payload !== 'object' || !type) {
      return false;
    }

    const actions = Array.isArray(payload.actions)
      ? payload.actions
      : payload.actions && typeof payload.actions === 'object'
        ? [payload.actions]
        : [];

    return actions.some(action => action?.type === type);
  },

  /**
   * Execute a registered or global action
   * @param {string} actionName - Action name (can be dot notation like 'Utils.dom.copyToClipboard')
   * @param {Event} event - DOM event
   * @param {HTMLElement} element - Element with data-action
   * @param {Array} args - Parsed arguments
   */
  executeAction(actionName, event, element, args) {
    try {
      // Check registered actions first
      if (this.state.actions.has(actionName)) {
        const handler = this.state.actions.get(actionName);
        handler(event, element, ...args);
        return;
      }

      // Try dot notation (e.g., 'Utils.dom.copyToClipboard')
      if (actionName.includes('.')) {
        const parts = actionName.split('.');
        let fn = window;
        for (const part of parts) {
          fn = fn[part];
          if (!fn) break;
        }
        if (typeof fn === 'function') {
          fn(event, element, ...args);
          return;
        }
      }

      // Try global function
      if (typeof window[actionName] === 'function') {
        window[actionName](event, element, ...args);
        return;
      }

      console.warn(`[EventSystemManager] Action not found: ${actionName}`);
    } catch (error) {
      console.error(`[EventSystemManager] Error executing action '${actionName}':`, error);
    }
  },

  /**
   * Registers the actions available to data-action out of the box, such as
   * copyToClipboard, toggleClass and requestApi.
   *
   * @returns {void}
   */
  registerBuiltInActions() {
    // Copy to clipboard
    this.registerAction('copyToClipboard', async (event, element) => {
      let text = '';

      // Get text from data-copy-target or data-copy-value
      const targetSelector = element.dataset.copyTarget;
      if (targetSelector) {
        const target = document.querySelector(targetSelector);
        if (target) {
          text = target.value || target.textContent || target.innerText || '';
        }
      }

      if (!text) {
        text = element.dataset.copyValue || '';
      }

      if (!text) return;

      await Utils.dom.copyToClipboard(text);
    });

    /*
      toggleClass — pi

        data-action="click:toggleClass('#panel','is-open')" ← through parentheses
        data-action="click:toggleClass" ← via attribute
        data-toggle-target="#panel" data-toggle-class="is-open"

      **The attribute has just been added**— previously accepted only through parentheses. button that draws
      So the attribute can actually call an action, but it doesn't have both a target and a class name. Then the condition
      The end of the function causes nothing to happen at all — **Press and it's completely quiet, no errors visible**
      (I actually encountered this in an app that uses this framework: the "Add time-based task" button wouldn't click on the entire page.)

      The framework's own convention is to read `data-*` anyway (`requestApi` read `data-api-url`,
      `data-api-method`, `data-confirm`) Having toggleClass do this makes it
      Be consistent, not add a new style.
    */
    this.registerAction('toggleClass', (event, element, targetOrClass, className) => {
      let selector = targetOrClass;
      let cls = className;

      // Parentheses are not passed — read from the pressed element's attributes.
      if (!selector && !cls) {
        selector = element.dataset.toggleTarget || '';
        cls = element.dataset.toggleClass || '';
      }

      // Short form: toggleClass('is-open') = Toggle class on element itself.
      if (selector && !cls) {
        cls = selector;
        selector = '';
      }

      const target = selector
        ? (typeof selector === 'string' ? document.querySelector(selector) : selector)
        : element;

      if (target && cls) {
        target.classList.toggle(cls);
      }
    });

    // Collect a table's current params minus pagination/sort — so a full,
    // unpaginated server request can be built for export/print.
    const tableAllParams = (tableId) => {
      const params = {};
      if (tableId && window.TableManager && TableManager.state && TableManager.state.tables) {
        const table = TableManager.state.tables.get(tableId);
        if (table && table.config && table.config.params) {
          Object.assign(params, table.config.params);
          ['page', 'pageSize', 'total', 'totalPages', 'totalRecords', 'sort', 'order', 'search', 'loading', 'error'].forEach(k => delete params[k]);
        }
      }
      return params;
    };

    // Print — open a server-rendered, unpaginated page (data-print-url +
    // data-print-table) in a new window; or fall back to printing a target
    // element in the current page (data-print-target).
    this.registerAction('print', (event, element) => {
      const printUrl = element.dataset.printUrl;
      if (printUrl) {
        const qs = new URLSearchParams(tableAllParams(element.dataset.printTable)).toString();
        window.open(printUrl + (printUrl.indexOf('?') === -1 ? '?' : '&') + qs, '_blank');
        return;
      }
      const targetSel = element.dataset.printTarget;
      const target = targetSel ? document.querySelector(targetSel) : null;
      if (target) {
        target.classList.add('print-target');
        document.body.classList.add('printing-target');
        const cleanup = () => {
          target.classList.remove('print-target');
          document.body.classList.remove('printing-target');
          window.removeEventListener('afterprint', cleanup);
        };
        window.addEventListener('afterprint', cleanup);
      }
      window.print();
    });

    // Export a table to CSV. When the table (or button) has an export URL,
    // navigate to it with the table's params minus pagination (all rows) so the
    // browser downloads directly; otherwise fall back to client-side export.
    this.registerAction('exportCsv', (event, element) => {
      const tableId = element.dataset.table || element.dataset.exportTable;
      let exportUrl = element.dataset.exportUrl || '';
      if (!exportUrl && tableId && window.TableManager && TableManager.state && TableManager.state.tables) {
        const table = TableManager.state.tables.get(tableId);
        exportUrl = table && table.element ? (table.element.dataset.exportUrl || table.element.dataset.export || '') : '';
      }
      if (exportUrl) {
        const params = tableAllParams(tableId);
        params.type = element.dataset.exportFormat || 'csv';
        const qs = new URLSearchParams(params).toString();
        window.open(exportUrl + (exportUrl.indexOf('?') === -1 ? '?' : '&') + qs, '_blank');
        return;
      }
      if (tableId && window.TableManager && typeof TableManager.exportData === 'function') {
        TableManager.exportData(tableId, element.dataset.exportFormat || 'csv');
      }
    });

    // Declarative API request with automatic ResponseHandler processing
    this.registerAction('requestApi', async (event, element, requestedUrl = null) => {
      const url = requestedUrl || element.dataset.apiUrl || element.getAttribute('href') || '';
      const method = String(element.dataset.apiMethod || 'get').toLowerCase();
      const client = window.httpAction;
      const canDisable = 'disabled' in element;
      const loadingClass = element.dataset.loadingClass || 'loading';
      const responseLoadingClass = String(element.dataset.responseLoadingClass || '').trim();
      const params = this.collectRequestParams(element);
      const confirmMessage = element.dataset.confirm || '';
      const loadingText = element.dataset.loadingText || '';
      const notifySuccess = element.dataset.notifySuccess === 'true';
      const defaultText = 'value' in element ? element.value : element.textContent;
      const form = element.closest('form');
      const responseTarget = this.resolveRequestResponseTarget(element);

      if (!url) {
        console.warn('[EventSystemManager] requestApi requires data-api-url or an href');
        return;
      }

      if (!client || typeof client[method] !== 'function') {
        console.warn(`[EventSystemManager] requestApi does not support method: ${method}`);
        return;
      }

      if (!this.validateRequestFields(element)) {
        return;
      }

      if (confirmMessage) {
        const confirmed = window.DialogManager && typeof DialogManager.confirm === 'function'
          ? await DialogManager.confirm(Now.translate(confirmMessage))
          : window.confirm(Now.translate(confirmMessage));

        if (!confirmed) {
          return;
        }
      }

      element.classList.add(loadingClass);
      if (canDisable) {
        element.disabled = true;
      }
      if (responseTarget) {
        responseTarget.setAttribute('aria-busy', 'true');
        if (responseLoadingClass) {
          responseTarget.classList.add(responseLoadingClass);
        }
      }
      if (loadingText) {
        if ('value' in element) {
          element.value = Now.translate(loadingText);
        } else {
          element.textContent = Now.translate(loadingText);
        }
      }

      try {
        const context = {
          trigger: element,
          element,
          target: responseTarget || element,
          responseTarget,
          form,
          event,
          request: {
            url,
            method,
            params
          }
        };

        let response;

        if (method === 'get' || method === 'delete') {
          response = await client[method](url, {params}, context);
        } else {
          response = await client[method](url, params, {}, context);
        }

        const body = response?.data && typeof response.data === 'object'
          ? response.data
          : null;
        const success = body?.success ?? response?.success ?? false;
        const message = body?.message || response?.message || response?.statusText || '';
        const payload = this.extractRequestApiPayload(response);
        const hasNotificationAction = this.hasResponseActionType(payload, 'notification');

        if (!success) {
          if (!hasNotificationAction && window.NotificationManager) {
            NotificationManager.error(message || 'Request failed.');
          }
          return;
        }

        if (notifySuccess && message && !hasNotificationAction && window.NotificationManager) {
          NotificationManager.success(message);
        }

        this.bindRequestResponseTarget(element, payload, context);
      } catch (error) {
        console.error('[EventSystemManager] requestApi failed:', error);

        if (window.NotificationManager) {
          NotificationManager.error(error?.message || 'Request failed.');
        }
      } finally {
        element.classList.remove(loadingClass);
        if (canDisable) {
          element.disabled = false;
        }
        if (responseTarget) {
          responseTarget.removeAttribute('aria-busy');
          if (responseLoadingClass) {
            responseTarget.classList.remove(responseLoadingClass);
          }
        }
        if (loadingText) {
          if ('value' in element) {
            element.value = defaultText;
          } else {
            element.textContent = defaultText;
          }
        }
      }
    });
  }
};

if (window.Now?.registerManager) {
  Now.registerManager('eventsystem', EventSystemManager);
}

window.EventSystemManager = EventSystemManager;
