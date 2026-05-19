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

  processUIEventQueue() {
    this.state.rafScheduled = false;

    for (const [type, event] of this.state.uiEventQueue) {
      this.processEvent(event);
    }

    this.state.uiEventQueue.clear();
  },

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

  shouldHandleEvent(entry, event) {
    if (entry.options.selector) {
      const delegateTarget = this.findDelegateTarget(event.target, entry.options.selector);
      if (!delegateTarget) return false;
      event.delegateTarget = delegateTarget;
    }
    return true;
  },

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

  setupCleanup() {
    if (this.state.cleanupTimer) {
      clearInterval(this.state.cleanupTimer);
    }

    this.state.cleanupTimer = setInterval(() => {
      this.cleanup();
    }, this.config.cleanupInterval);
  },

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

  removeComponentHandlers(componentId) {
    const handlersToRemove = [];
    this.state.handlers.forEach((entry, id) => {
      if (entry.options.componentId === componentId) {
        handlersToRemove.push(id);
      }
    });
    handlersToRemove.forEach(id => this.removeHandler(id));
  },

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

  setupMemoryMonitoring() {
    this.state.gcTimer = setInterval(() => {
      this.checkMemoryUsage();
    }, this.config.memoryManagement.checkInterval);
  },

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

  shouldTriggerGC(stats) {
    const threshold = this.config.memoryManagement.gcThreshold;
    const maxHandlers = this.config.memoryManagement.maxHandlersPerElement;

    return (
      stats.handlerCount > (this.config.maxMemoryUsage * threshold) ||
      stats.cacheSize > this.config.memoryManagement.maxCacheSize ||
      Array.from(stats.elementHandlers.values()).some(count => count > maxHandlers)
    );
  },

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

      if (actionName === 'requestApi' && eventType === 'change' && this.shouldSkipRequestApiChange(event, element)) {
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

  shouldAllowProgrammaticRequestApiChange(element) {
    const value = String(element?.dataset?.requestApiOnProgrammaticChange || '').trim().toLowerCase();

    return ['true', '1', 'yes', 'on'].includes(value);
  },

  shouldSkipRequestApiChange(event, element) {
    if (!element) {
      return false;
    }

    if (!event?.isTrusted && !this.shouldAllowProgrammaticRequestApiChange(element)) {
      return true;
    }

    return this.shouldSkipAutocompleteNativeChange(event, element);
  },

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

    // Toggle class
    this.registerAction('toggleClass', (event, element, targetOrClass, className) => {
      let target = element;
      let cls = targetOrClass;

      if (className) {
        target = typeof targetOrClass === 'string' ?
          document.querySelector(targetOrClass) : targetOrClass;
        cls = className;
      }

      if (target && cls) {
        target.classList.toggle(cls);
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
