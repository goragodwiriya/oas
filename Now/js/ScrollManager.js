const ScrollManager = {
  config: {
    enabled: false,

    core: {
      offset: 0,
      duration: 500,
      easing: 'easeInOutCubic'
    },

    selectors: {
      container: 'body',
      content: '#main',
      tracked: '[data-scroll-track]',
      anchors: '[data-scroll-anchor]',
      waypoints: '[data-scroll-waypoint]',
      ignore: '[data-scroll-ignore]',
      reveal: '[data-scroll-reveal]',
      nav: '[data-scroll-nav]',
      sections: '[data-scroll-section]',
      parallax: '[data-parallax]',
      progress: '.scroll-progress'
    },

    animation: {
      enabled: true,
      types: {
        linear: t => t,
        easeInOutCubic: t => t < .5 ? 4 * t * t * t : (t - 1) * (2 * t - 2) * (2 * t - 2) + 1,
        easeOutQuart: t => 1 - (--t) * t * t * t,
        easeInQuad: t => t * t
      }
    },

    performance: {
      throttle: 16,
      debounce: 100,
      passive: true,
      observer: true,
      requestAnimationFrame: {
        enabled: true,
        maxFPS: 60,
        skipThreshold: 16
      },
      batchSize: 5,
      maxQueue: 100
    },

    restoration: {
      enabled: true,
      key: 'scroll_positions',
      ttl: 24 * 60 * 60 * 1000
    },

    mobile: {
      enabled: true,
      touchAction: 'pan-y',
      momentumScroll: true,
      pullToRefresh: false,
      touchThreshold: 5
    },

    accessibility: {
      enabled: true,
      announceChanges: true,
      smoothFocus: true,
      ariaLabels: true,
      focusableSelectors: 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
    },

    waypoints: {
      offset: 0,
      threshold: 0.5,
      once: false,
      delay: 100
    },

    scroll: {
      infinite: {
        enabled: false,
        threshold: 100,
        loadMore: null
      },
      parallax: {
        enabled: false,
        speed: 0.5
      },
      progress: {
        enabled: false,
        color: 'var(--color-primary)',
        height: '3px',
        zIndex: 1000
      },
      snap: {
        enabled: false,
        type: 'y',
        stop: true,
        align: 'start'
      },
      section: {
        highlight: true,
        threshold: 0.5,
        activeClass: 'active'
      },
      nav: {
        updateHash: true,
        highlightClass: 'active',
        smoothScroll: true
      }
    },

    smoothScroll: {
      enabled: false,
      autoScroll: false,
      selector: 'a[href^="#"]',
      excludeSelector: '.no-smooth',
      hashChangeEnabled: true,
    }
  },

  state: {
    initialized: false,
    isScrolling: false,
    isLoading: false,
    activeAnimation: null,
    positions: new Map(),
    waypoints: new Map(),
    observers: new Map(),
    events: new Map(),
    history: [],
    lastPosition: null,
    scrollRestoration: new Map(),
    rafId: null,
    lastFrameTime: 0,
    touchStartY: null,
    touchStartX: null,
    scrollQueue: [],
    activeSection: null,
    scrollDirection: null,
    scrollQueueLock: false
  },

  async init(options = {}) {
    if (this.state.initialized) return this;

    this.config = Now.mergeConfig(this.config, options);

    if (!this.config.enabled) {
      this.state.disabled = true;
      return this;
    }

    this.setupSmoothScroll();

    if (!window.requestAnimationFrame) {
      console.warn('requestAnimationFrame not supported');
      this.config.animation.enabled = false;
    }

    if (!window.IntersectionObserver) {
      console.warn('IntersectionObserver not supported');
      this.config.performance.observer = false;
    }

    try {
      this.setupErrorHandling();
      await this.initializeCore();
      if (this.config.performance.observer) {
        this.initializeObservers();
      }
      this.setupScrollTracking();
      this.setupWaypoints();
      if (this.config.mobile.enabled) {
        this.initializeMobileSupport();
      }
      if (this.config.accessibility.enabled) {
        this.initializeAccessibility();
      }
      if (this.config.restoration.enabled) {
        this.initScrollRestoration();
      }
      await this.initializeScrollFeatures();
      this.setupEventListeners();
      this.state.initialized = true;
      this.emit('scroll:initialized');

      return this;

    } catch (error) {
      throw ErrorManager.handle('Initialization failed', {
        context: 'ScrollManager.init',
        type: 'scroll:error',
        data: {error}
      });
    }
  },

  async initializeCore() {
    this.contentArea = document.querySelector(this.config.selectors.content);
    if (!this.contentArea) {
      console.warn(`Main content area not found: ${this.config.selectors.content} in ${document.location.href}`);
    }

    this.state.scrollQueue = [];

    if ('scrollRestoration' in history) {
      history.scrollRestoration = 'manual';
    }

    if (this.config.animation.enabled) {
      this.initializeAnimationSystem();
    }
  },

  setupSmoothScroll() {
    if (!this.config.smoothScroll.enabled) return;

    if (this.config.smoothScroll.selector) {
      document.addEventListener('click', (e) => {
        const anchor = e.target.closest(this.config.smoothScroll.selector);
        if (!anchor || anchor.matches(this.config.smoothScroll.excludeSelector)) return;

        const targetId = anchor.getAttribute('href').slice(1);
        if (!targetId) return;

        const target = document.getElementById(targetId);
        if (!target) return;

        e.preventDefault();
        this.scrollTo(target, {
          offset: this.config.core.offset,
          duration: this.config.core.duration,
          easing: this.config.core.easing
        });
      });
    }

    if (this.config.smoothScroll.hashChangeEnabled) {
      window.addEventListener('hashchange', () => {
        const hash = window.location.hash;
        if (!hash) return;

        const target = document.querySelector(hash);
        if (target) {
          this.scrollTo(target, {
            offset: this.config.core.offset,
            duration: this.config.core.duration,
            easing: this.config.core.easing
          });
        }
      });
    }

    if (this.config.smoothScroll.autoScroll && window.location.hash) {
      setTimeout(() => {
        const target = document.querySelector(window.location.hash);
        if (target) {
          this.scrollTo(target, {
            offset: this.config.core.offset,
            duration: this.config.core.duration,
            easing: this.config.core.easing
          });
        }
      }, 100);
    }
  },

  async initializeScrollFeatures() {
    const {scroll} = this.config;

    if (scroll.infinite.enabled) {
      this.initInfiniteScroll(scroll.infinite.loadMore);
    }

    if (scroll.parallax.enabled) {
      this.initParallax();
    }

    if (scroll.progress.enabled) {
      this.initProgressBar();
    }

    if (scroll.snap.enabled) {
      this.initScrollSnap();
    }

    if (scroll.section.highlight) {
      this.initSectionHighlight();
    }

    if (scroll.nav.updateHash) {
      this.initScrollNav();
    }
  },

  initializeObservers() {
    if ('IntersectionObserver' in window) {
      this.state.observers.set('intersection', new IntersectionObserver(
        entries => this.handleIntersection(entries),
        {
          threshold: [0, 0.25, 0.5, 0.75, 1],
          rootMargin: '20px'
        }
      ));
    }

    if ('MutationObserver' in window) {
      this.state.observers.set('mutation', new MutationObserver(
        mutations => this.handleMutations(mutations)
      ));
    }

    if ('ResizeObserver' in window) {
      this.state.observers.set('resize', new ResizeObserver(
        entries => this.handleResize(entries)
      ));
    }

    this.startObservers();
  },

  handleIntersection(entries) {
    entries.forEach(entry => {
      this.state.waypoints.forEach((waypoint, id) => {
        if (waypoint.element === entry.target) {
          const isVisible = entry.isIntersecting;

          if (isVisible && !waypoint.triggered) {
            if (waypoint.options.callback) {
              waypoint.options.callback(entry);
            }
            this.emit('waypoint:trigger', {id, entry});

            if (waypoint.options.once) {
              waypoint.triggered = true;
            }
          }
        }
      });

      if (entry.target.hasAttribute('data-scroll-reveal') && entry.isIntersecting) {
        entry.target.classList.add('revealed');
        this.emit('scroll:reveal', {element: entry.target});
      }

      if (entry.target.hasAttribute('data-scroll-section') && entry.isIntersecting) {
        const id = entry.target.id;
        this.state.activeSection = id;
        this.emit('section:active', {id});

        if (this.config.scroll.nav.updateHash && id) {
          history.replaceState(null, null, `#${id}`);
        }

        document.querySelectorAll(this.config.selectors.nav).forEach(nav => {
          nav.querySelectorAll('a').forEach(link => {
            link.classList.toggle(
              this.config.scroll.nav.highlightClass,
              link.getAttribute('href') === `#${id}`
            );
          });
        });
      }
    });
  },

  handleMutations(mutations) {
    let shouldUpdateWaypoints = false;

    mutations.forEach(mutation => {
      mutation.addedNodes.forEach(node => {
        if (node.nodeType === 1) {
          if (node.matches?.(this.config.selectors.waypoints)) {
            shouldUpdateWaypoints = true;
          }
          if (node.matches?.(this.config.selectors.reveal)) {
            this.state.observers.get('intersection')?.observe(node);
          }
        }
      });

      mutation.removedNodes.forEach(node => {
        if (node.nodeType === 1 && node.matches?.(this.config.selectors.waypoints)) {
          shouldUpdateWaypoints = true;
        }
      });
    });

    if (shouldUpdateWaypoints) {
      this.setupWaypoints();
    }
  },

  handleResize(entries) {
    this.emit('scroll:resize', {entries});
  },

  startObservers() {
    const container = document.querySelector(this.config.selectors.container);

    const mutation = this.state.observers.get('mutation');
    if (mutation && container) {
      mutation.observe(container, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['data-scroll-track', 'data-scroll-waypoint']
      });
    }

    const intersection = this.state.observers.get('intersection');
    if (intersection) {
      document.querySelectorAll(this.config.selectors.waypoints).forEach(el => {
        intersection.observe(el);
      });

      document.querySelectorAll(this.config.selectors.reveal).forEach(el => {
        intersection.observe(el);
      });

      document.querySelectorAll(this.config.selectors.sections).forEach(el => {
        intersection.observe(el);
      });
    }

    const resize = this.state.observers.get('resize');
    if (resize && container) {
      resize.observe(container);
    }
  },

  initScrollNav() {
    const navigation = document.querySelector(this.config.selectors.nav);
    if (!navigation) return;

    navigation.addEventListener('click', e => {
      const link = e.target.closest('a');
      if (!link) return;

      const id = link.getAttribute('href')?.slice(1);
      if (!id) return;

      e.preventDefault();

      const target = document.getElementById(id);
      if (target) {
        this.scrollTo(target, {
          duration: this.config.scroll.nav.smoothScroll ?
            this.config.core.duration : 0
        });
      }
    });
  },

  setupEventListeners() {
    this.on('route:changed', (context) => {
      this.handleRouteChange(context.data);
    });

    document.addEventListener('keydown', e => {
      if (e.key === 'Home') {
        e.preventDefault();
        this.scrollToTop();
      } else if (e.key === 'End') {
        e.preventDefault();
        this.scrollToBottom();
      } else if (e.key === 'PageUp') {
        e.preventDefault();
        window.scrollBy({
          top: -window.innerHeight * 0.9,
          behavior: 'smooth'
        });
      } else if (e.key === 'PageDown') {
        e.preventDefault();
        window.scrollBy({
          top: window.innerHeight * 0.9,
          behavior: 'smooth'
        });
      }
    });
  },

  removeAllListeners() {
    const eventManager = Now.getManager('event');

    this.state.events.forEach((handlers, eventName) => {
      handlers.forEach(handler => {
        handlers.delete(handler);

        if (eventManager?.off) {
          eventManager.off(eventName, handler);
        }
      });
    });

    this.state.events.clear();

    if (eventManager?.emit) {
      eventManager.emit('scroll:cleanup', {
        timestamp: Date.now(),
        source: 'ScrollManager'
      });
    }
  },

  cleanup() {
    this.cancelScroll();

    this.removeAllListeners();

    this.state.observers.forEach(observer => {
      if (observer?.disconnect) {
        observer.disconnect();
      }
    });
    this.state.observers.clear();

    if (this.debounceTimers) {
      Object.values(this.debounceTimers).forEach(timer => {
        clearTimeout(timer);
      });
      this.debounceTimers = {};
    }

    this.state.positions.clear();
    this.state.waypoints.clear();
    this.state.scrollQueue = [];
    this.state.history = [];
    this.state.events.clear();

    this.state.initialized = false;
    this.state.isScrolling = false;
    this.state.isProcessingEvents = false;

    if (this.announcer) {
      this.announcer.remove();
      this.announcer = null;
    }
  },

  cleanSelector(selector) {
    return selector.replace(/[^\w\s\-_]/g, '');
  },

  async scrollTo(target, options = {}) {
    if (!this.config.enabled || !target) return;

    try {
      let element = null;
      if (typeof target === 'string') {
        target = this.cleanSelector(target);
        element = document.querySelector(target);
      } else if (target instanceof HTMLElement) {
        element = target;
      }

      if (!element) {
        throw new Error('Invalid scroll target');
      }

      if (element.matches(this.config.selectors.ignore)) {
        return;
      }

      options = {
        offset: Number(options.offset) || 0,
        duration: Math.max(0, Number(options.duration)) || 500,
        easing: typeof options.easing === 'string' ?
          options.easing : 'easeInOutCubic'
      };

      const finalOptions = {
        offset: this.config.core.offset,
        duration: this.config.core.duration,
        easing: this.config.core.easing,
        ...options
      };

      this.cancelScroll();

      const elementRect = element.getBoundingClientRect();
      const scrollTop = window.pageYOffset;
      const targetY = elementRect.top + scrollTop - finalOptions.offset;

      if (this.state.isScrolling) {
        this.queueScroll(targetY, finalOptions);
        return;
      }

      this.emit('scroll:start', {element, options: finalOptions});

      this.state.isScrolling = true;

      if (this.config.animation.enabled && finalOptions.duration > 0) {
        await this.animateScroll(targetY, finalOptions);
      } else {
        window.scrollTo({
          top: targetY,
          behavior: 'auto'
        });
      }

      if (finalOptions.focus !== false && this.config.accessibility.smoothFocus) {
        element.focus({preventScroll: true});
      }

      this.state.isScrolling = false;

      this.processScrollQueue();

      this.updateScrollPosition();

      this.emit('scroll:complete', {
        element,
        position: this.getScrollPosition()
      });

    } catch (error) {
      ErrorManager.handle('Scroll failed', {
        context: 'ScrollManager.scrollTo',
        type: 'scroll:error',
        data: {error}
      });
      this.state.isScrolling = false;
    }
  },

  cancelScroll() {
    if (this.state.activeAnimation) {
      cancelAnimationFrame(this.state.activeAnimation);
      this.state.activeAnimation = null;
      this.state.isScrolling = false;
      this.emit('scroll:cancel');
    }
  },

  async scrollToContent(options = {}) {
    if (!this.contentArea) return;
    await this.scrollTo(this.contentArea, options);
  },

  async scrollToTop(options = {}) {
    await this.scrollTo(document.documentElement, options);
  },

  async scrollToBottom(options = {}) {
    const finalElement = Array.from(document.body.children).pop();
    await this.scrollTo(finalElement, options);
  },

  addWaypoint(id, element, options = {}) {
    if (!id || typeof id !== 'string') {
      throw new Error('Invalid waypoint ID');
    }
    if (!(element instanceof HTMLElement)) {
      throw new Error('Invalid waypoint element');
    }
    if (this.state.waypoints.has(id)) {
      throw new Error(`Waypoint ${id} already exists`);
    }

    const waypoint = {
      element,
      options: {
        offset: options.offset || this.config.waypoints.offset,
        threshold: options.threshold || this.config.waypoints.threshold,
        once: options.once || this.config.waypoints.once,
        callback: options.callback
      },
      triggered: false
    };

    this.state.waypoints.set(id, waypoint);

    if (this.state.observers.has('intersection')) {
      const observer = this.state.observers.get('intersection');
      observer.observe(element);
    }
  },

  removeWaypoint(id) {
    const waypoint = this.state.waypoints.get(id);
    if (waypoint) {
      if (this.state.observers.has('intersection')) {
        const observer = this.state.observers.get('intersection');
        observer.unobserve(waypoint.element);
      }
      this.state.waypoints.delete(id);
    }
  },

  handleScrollDirection() {
    const currentScroll = window.pageYOffset;
    const direction = currentScroll > this.state.lastPosition?.y ? 'down' : 'up';

    if (direction !== this.state.scrollDirection) {
      this.state.scrollDirection = direction;
      this.emit('scroll:direction', {direction});
      document.body.setAttribute('data-scroll-direction', direction);
    }
  },

  queueScroll(targetY, options) {
    if (this.state.scrollQueue.length >= this.config.performance.maxQueue) {
      this.state.scrollQueue.shift();
    }

    this.state.scrollQueue.push({targetY, options});
  },

  async processScrollQueue() {
    if (this.scrollQueueLock) return;

    try {
      this.scrollQueueLock = true;

      while (this.state.scrollQueue.length > 0) {
        const item = this.state.scrollQueue.shift();
        await this.animateScroll(item.targetY, item.options);
      }

    } finally {
      this.scrollQueueLock = false;
    }
  },

  getScrollPosition() {
    return {
      x: window.pageXOffset,
      y: window.pageYOffset,
      direction: this.state.scrollDirection,
      timestamp: Date.now()
    };
  },

  updateScrollPosition() {
    const position = this.getScrollPosition();
    this.state.lastPosition = position;
    this.handleScrollDirection();

    this.state.history.push(position);
    if (this.state.history.length > 50) {
      this.state.history.shift();
    }

    if (this.config.restoration.enabled) {
      this.state.scrollRestoration.set(
        window.location.pathname,
        position
      );
    }
  },

  setupErrorHandling() {
    window.addEventListener('error', (event) => {
      if (event.error) {
        ErrorManager.handle(event.error, {
          context: 'ScrollManager.setupErrorHandling',
          type: 'scroll:error'
        });
      }
    });

    window.addEventListener('unhandledrejection', (event) => {
      if (event.reason) {
        ErrorManager.handle(event.reason, {
          context: 'ScrollManager.setupErrorHandling',
          type: 'scroll:error'
        });
      }
    });
  },

  initializeAnimationSystem() {
    this.easingFunctions = {
      linear: t => t,
      easeInOutCubic: t => t < .5 ? 4 * t * t * t : (t - 1) * (2 * t - 2) * (2 * t - 2) + 1,
      easeOutQuart: t => 1 - (--t) * t * t * t,
      easeInQuad: t => t * t,
      easeOutBack: t => {
        const c1 = 1.70158;
        const c3 = c1 + 1;
        return 1 + c3 * Math.pow(t - 1, 3) + c1 * Math.pow(t - 1, 2);
      }
    };
  },

  animateScroll(targetY, options = {}) {
    return new Promise((resolve) => {
      const startY = window.pageYOffset;
      const startTime = performance.now();
      let lastFrame = null;
      const duration = options.duration || this.config.core.duration;
      const easingFn = this.easingFunctions[options.easing] ||
        this.easingFunctions[this.config.core.easing];

      let lastFrameTime = performance.now();
      let framesToSkip = 0;

      const animate = (currentTime) => {
        try {
          if (lastFrame && currentTime - lastFrame < 16) {
            this.state.rafId = requestAnimationFrame(animate);
            return;
          }

          lastFrame = currentTime;

          const delta = currentTime - lastFrameTime;
          lastFrameTime = currentTime;

          if (delta > this.config.performance.requestAnimationFrame.skipThreshold) {
            framesToSkip = Math.floor(delta / 16) - 1;
          }

          if (framesToSkip > 0) {
            framesToSkip--;
            this.state.rafId = requestAnimationFrame(animate);
            return;
          }

          const elapsed = currentTime - startTime;
          const progress = Math.min(elapsed / duration, 1);

          if (progress < 1) {
            const eased = easingFn(progress);
            const currentY = startY + (targetY - startY) * eased;

            window.scrollTo(0, currentY);

            this.state.rafId = requestAnimationFrame(animate);
          } else {
            window.scrollTo(0, targetY);
            this.state.rafId = null;

            this.emit('scroll:animationComplete', {
              startY,
              targetY,
              duration: elapsed
            });

            resolve();
          }

        } catch (error) {
          ErrorManager.handle(error, {
            context: 'ScrollManager.animateScroll',
            type: 'scroll:error'
          });
          window.scrollTo(0, targetY);
          this.state.rafId = null;
          resolve();
        }
      };

      this.state.rafId = requestAnimationFrame(animate);
    });
  },

  easingFunctions: {
    linear: t => t,
    easeInOutCubic: t => t < .5 ?
      4 * t * t * t :
      (t - 1) * (2 * t - 2) * (2 * t - 2) + 1,
    easeOutQuart: t => 1 - (--t) * t * t * t,
    easeInQuad: t => t * t,
    easeOutBack: t => {
      const c1 = 1.70158;
      const c3 = c1 + 1;
      return 1 + c3 * Math.pow(t - 1, 3) + c1 * Math.pow(t - 1, 2);
    }
  },

  onVirtualDOMUpdate(callback) {
    this.virtualDOMHooks.push(callback);
  },

  notifyVirtualDOM() {
    this.virtualDOMHooks.forEach(hook => {
      requestIdleCallback(() => hook());
    });
  },

  throttle(func, limit) {
    let inThrottle;
    return (...args) => {
      if (!inThrottle) {
        func.apply(this, args);
        inThrottle = true;
        setTimeout(() => inThrottle = false, limit);
      }
    };
  },

  debounce(func, wait) {
    let timeout;
    return (...args) => {
      clearTimeout(timeout);
      timeout = setTimeout(() => func.apply(this, args), wait);
    };
  },

  setupScrollTracking() {
    const handleScroll = this.throttle(() => {
      if (this.state.isScrolling) return;

      const position = this.getScrollPosition();
      this.updateScrollState(position);
      this.handleScrollDirection();
      this.emit('scroll:progress', position);
    }, this.config.performance.throttle);

    const handleScrollEnd = this.debounce(() => {
      if (!this.state.isScrolling) {
        const position = this.getScrollPosition();
        this.state.lastPosition = position;
        this.emit('scroll:end', position);

        this.processScrollQueue();
      }
    }, this.config.performance.debounce);

    window.addEventListener('scroll', handleScroll, {
      passive: this.config.performance.passive
    });

    window.addEventListener('scroll', handleScrollEnd, {
      passive: this.config.performance.passive
    });
  },

  updateScrollState(position) {
    this.state.lastPosition = position;
    this.state.history.push(position);

    if (this.state.history.length > 50) {
      this.state.history.shift();
    }
  },

  setupWaypoints() {
    const waypoints = document.querySelectorAll(this.config.selectors.waypoints);
    waypoints.forEach(element => {
      const id = element.dataset.scrollWaypoint;
      const offset = parseInt(element.dataset.scrollOffset) || this.config.waypoints.offset;
      const callback = element.dataset.scrollCallback && window[element.dataset.scrollCallback];

      this.addWaypoint(id || Utils.generateUUID(), element, {
        offset,
        callback,
        threshold: this.config.waypoints.threshold,
        once: this.config.waypoints.once
      });
    });

    if (this.state.observers.has('intersection')) {
      const observer = this.state.observers.get('intersection');
      waypoints.forEach(element => {
        observer.observe(element);
      });
    }
  },

  addWaypoint(id, element, options = {}) {
    const waypoint = {
      element,
      options: {
        offset: options.offset || this.config.waypoints.offset,
        threshold: options.threshold || this.config.waypoints.threshold,
        once: options.once || this.config.waypoints.once,
        callback: options.callback
      },
      triggered: false
    };

    this.state.waypoints.set(id, waypoint);

    // Observe the element if IntersectionObserver is available
    if (this.state.observers.has('intersection')) {
      const observer = this.state.observers.get('intersection');
      observer.observe(element);
    }
  },

  initializeMobileSupport() {
    if (!this.config.mobile.enabled) return;

    const container = document.querySelector(this.config.selectors.container);
    if (!container) return;

    container.style.touchAction = this.config.mobile.touchAction;

    container.addEventListener('touchstart', (e) => {
      this.state.touchStartY = e.touches[0].clientY;
      this.state.touchStartX = e.touches[0].clientX;
    }, {passive: true});

    container.addEventListener('touchmove', (e) => {
      if (!this.state.touchStartY) return;

      const touchY = e.touches[0].clientY;
      const touchX = e.touches[0].clientX;
      const diffY = this.state.touchStartY - touchY;
      const diffX = this.state.touchStartX - touchX;

      const scrollTop = window.pageYOffset;
      const scrollHeight = document.documentElement.scrollHeight;
      const clientHeight = document.documentElement.clientHeight;

      if ((scrollTop <= 0 && diffY < 0) ||
        (scrollTop + clientHeight >= scrollHeight && diffY > 0)) {
        e.preventDefault();
      }

      this.emit('touch:move', {
        diffY,
        diffX,
        originalEvent: e
      });

    }, {passive: false});

    container.addEventListener('touchend', () => {
      this.state.touchStartY = null;
      this.state.touchStartX = null;
    }, {passive: true});

    if (this.config.mobile.momentumScroll) {
      container.style.WebkitOverflowScrolling = 'touch';
    }

    if (!this.config.mobile.pullToRefresh) {
      document.body.style.overscrollBehavior = 'none';
    }
  },

  initializeAccessibility() {
    if (!this.config.accessibility.enabled) return;

    if (this.config.accessibility.announceChanges) {
      this.announcer = document.createElement('div');
      this.announcer.setAttribute('aria-live', 'polite');
      this.announcer.className = 'sr-only';
      this.announcer.style.cssText = `
      position: absolute;
      width: 1px;
      height: 1px;
      padding: 0;
      margin: -1px;
      overflow: hidden;
      clip: rect(0, 0, 0, 0);
      white-space: nowrap;
      border: 0;
    `;
      document.body.appendChild(this.announcer);
    }

    if (this.config.accessibility.smoothFocus) {
      document.addEventListener('focus', (e) => {
        const target = e.target;
        if (target.matches(this.config.accessibility.focusableSelectors)) {
          const rect = target.getBoundingClientRect();
          const isInView = (
            rect.top >= 0 &&
            rect.left >= 0 &&
            rect.bottom <= window.innerHeight &&
            rect.right <= window.innerWidth
          );

          if (!isInView) {
            this.scrollTo(target, {
              offset: this.config.core.offset,
              duration: this.config.core.duration
            });
          }
        }
      }, true);
    }

    if (this.config.accessibility.ariaLabels) {
      document.querySelectorAll(this.config.selectors.waypoints).forEach(el => {
        if (!el.getAttribute('aria-label')) {
          el.setAttribute('aria-label', 'Scroll waypoint');
        }
      });
    }

    this.on('scroll:complete', (data) => {
      if (this.announcer && data.element) {
        const label = data.element.getAttribute('aria-label') ||
          data.element.innerText ||
          'Scrolled content';
        this.announcer.textContent = `Scrolled to ${label}`;
      }
    });
  },

  on(event, handler) {
    if (!this.state.events.has(event)) {
      this.state.events.set(event, new Set());
    }
    this.state.events.get(event).add(handler);

    const eventManager = Now.getManager('event');
    if (eventManager?.on) {
      eventManager.on(event, handler);
    }

    return this;
  },

  off(event, handler) {
    const handlers = this.state.events.get(event);
    if (handlers) {
      handlers.delete(handler);
    }

    const eventManager = Now.getManager('event');
    if (eventManager?.off) {
      eventManager.off(event, handler);
    }

    return this;
  },

  emit(eventName, data) {
    const handlers = this.state.events.get(eventName);
    if (handlers) {
      handlers.forEach(handler => {
        try {
          handler(data);
        } catch (error) {
          ErrorManager.handle(`Error in scroll event handler for ${eventName}`, {
            context: 'ScrollManager.emit',
            data: {error}
          });
        }
      });
    }

    EventManager.emit(eventName, {
      ...data,
      source: 'ScrollManager'
    });
  },

  initScrollRestoration() {
    if (!this.config.restoration.enabled) return;

    const stored = localStorage.getItem(this.config.restoration.key);
    if (stored) {
      try {
        const positions = JSON.parse(stored);
        Object.entries(positions).forEach(([key, value]) => {
          if (Date.now() - value.timestamp < this.config.restoration.ttl) {
            this.state.scrollRestoration.set(key, value);
          }
        });
      } catch (error) {
        ErrorManager.handle('Failed to restore scroll positions', {
          context: 'ScrollManager.initScrollRestoration',
          data: {error}
        });
      }
    }

    window.addEventListener('beforeunload', () => {
      const positions = {};
      this.state.scrollRestoration.forEach((value, key) => {
        positions[key] = value;
      });
      localStorage.setItem(this.config.restoration.key, JSON.stringify(positions));
    });

    window.addEventListener('popstate', () => {
      const position = this.state.scrollRestoration.get(window.location.pathname);
      if (position) {
        window.scrollTo(0, position.y);
      }
    });
  },

  initializeScrollFeatures() {
    const {scroll} = this.config;

    if (scroll.section.highlight) {
      this.initSectionHighlight();
    }

    if (scroll.nav.updateHash) {
      this.initScrollNav();
    }

    if (scroll.infinite.enabled) {
      this.initInfiniteScroll(scroll.infinite.loadMore);
    }

    if (scroll.parallax.enabled) {
      this.initParallax();
    }
  },

  initSectionHighlight() {
    const sections = document.querySelectorAll(this.config.selectors.sections);

    const observer = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          const id = entry.target.id;
          this.state.activeSection = id;
          this.emit('section:active', {id});

          if (this.config.scroll.nav.updateHash && id) {
            history.replaceState(null, null, `#${id}`);
          }

          document.querySelectorAll(this.config.selectors.nav).forEach(nav => {
            nav.querySelectorAll('a').forEach(link => {
              link.classList.toggle(
                this.config.scroll.nav.highlightClass,
                link.getAttribute('href') === `#${id}`
              );
            });
          });
        }
      });
    }, {
      threshold: this.config.scroll.section.threshold
    });

    sections.forEach(section => observer.observe(section));
  },

  initParallax() {
    const elements = document.querySelectorAll(this.config.selectors.parallax);

    const updateParallax = () => {
      const scrolled = window.scrollY;
      elements.forEach(el => {
        const speed = el.dataset.parallaxSpeed || this.config.scroll.parallax.speed;
        const yPos = -(scrolled * speed);
        el.style.transform = `translate3d(0, ${yPos}px, 0)`;
      });
    };

    window.addEventListener('scroll', updateParallax, {passive: true});
  },

  initInfiniteScroll(callback) {
    if (!callback) return;

    const handleInfinite = this.throttle(() => {
      if (this.state.isLoading) return;

      const scrollHeight = document.documentElement.scrollHeight;
      const scrollTop = window.scrollY;
      const clientHeight = window.innerHeight;

      if (scrollHeight - (scrollTop + clientHeight) < this.config.scroll.infinite.threshold) {
        this.state.isLoading = true;

        Promise.resolve(callback())
          .finally(() => {
            this.state.isLoading = false;
          });
      }
    }, 100);

    window.addEventListener('scroll', handleInfinite, {passive: true});
  },

  handleRouteChange(data) {
    if (!data || !data.path) return;

    if (data.path.includes('#')) {
      const [, hash] = data.path.split('#');
      if (hash) {
        setTimeout(() => {
          const element = document.getElementById(hash);
          if (element) {
            this.scrollTo(element, {
              offset: this.config.core.offset,
              duration: this.config.core.duration
            });
          }
        }, 100);
        return;
      }
    }

    const contentEl = document.querySelector(this.config.selectors.content);
    if (contentEl) {
      this.scrollTo(contentEl, {
        offset: this.config.core.offset,
        duration: this.config.core.duration
      });
    } else {
      window.scrollTo({
        top: 0,
        behavior: this.config.animation.enabled ? 'smooth' : 'auto'
      });
    }

    this.state.activeSection = null;
  },

  handleScrollProgress(position) {
    if (!this.config.scroll.progress.enabled) return;

    const docHeight = document.documentElement.scrollHeight - window.innerHeight;
    const progress = (position.y / docHeight) * 100;

    const progressBar = document.querySelector(this.config.selectors.progress);
    if (progressBar) {
      progressBar.style.width = `${progress}%`;
    }

    this.emit('scroll:progress', {
      position,
      progress,
      total: docHeight
    });
  },

  handleResize(entries) {
    entries.forEach(entry => {
      this.updateScrollPosition();

      const position = this.getScrollPosition();
      this.handleScrollProgress(position);
    });

    this.emit('scroll:resize', {
      entries,
      timestamp: Date.now()
    });
  }
};

if (window.Now?.registerManager) {
  Now.registerManager('scroll', ScrollManager);
}

window.ScrollManager = ScrollManager;
