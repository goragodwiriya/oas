/**
 * Carousel Component - Generic Content Slider
 * Framework: Now.js
 *
 * Features:
 * - Content-agnostic (works with any HTML)
 * - Responsive (mobile/tablet/desktop)
 * - Touch/swipe support
 * - Auto-scroll with pause on hover
 * - Keyboard navigation
 * - Uses system CSS patterns (nav-button, icon-prev, icon-next)
 *
 * Usage:
 * ```javascript
 * Carousel.init('#container', '.item', {
 *   itemsPerView: {mobile: 1, tablet: 2, desktop: 4}
 * });
 * ```
 */

const Carousel = {
  /**
   * Default configuration
   */
  config: {
    itemsPerView: {mobile: 1, tablet: 2, desktop: 4},
    gap: 18,
    autoplay: true,
    autoplayInterval: 5000,
    pauseOnHover: true,
    loop: true,
    speed: 400,
    easing: 'cubic-bezier(0.25, 0.8, 0.25, 1)',
    touchEnabled: true,
    touchThreshold: 50,
    showArrows: true,
    breakpoints: {mobile: 600, tablet: 1024}
  },

  /**
   * Global state
   */
  state: {instances: new Map()},

  /**
   * Initialize carousel instance
   * @param {string|HTMLElement} container - Container element or selector
   * @param {string} itemSelector - Selector for items to carousel
   * @param {Object} options - Configuration options
   * @returns {Object|null} Carousel instance
   */
  init(container, itemSelector = '.carousel-item', options = {}) {
    const element = typeof container === 'string' ? document.querySelector(container) : container;
    if (!element) {
      console.error('[Carousel] Container not found');
      return null;
    }

    const items = Array.from(element.querySelectorAll(itemSelector));
    if (items.length === 0) {
      console.warn('[Carousel] No items found with selector:', itemSelector);
      return null;
    }

    const instanceId = 'carousel_' + Math.random().toString(36).substr(2, 9);
    const instance = {
      id: instanceId,
      element,
      items,
      options: {...this.config, ...options},
      currentIndex: 0,
      isAnimating: false,
      autoplayTimer: null,
      visibilityHandler: null,
      pageshowHandler: null
    };

    this.state.instances.set(instanceId, instance);
    element.dataset.carouselId = instanceId;

    this.wrapItems(instance);
    this.setupCarousel(instance);

    return instance;
  },

  /**
   * Wrap items in carousel structure
   * @param {Object} instance - Carousel instance
   * @private
   */
  wrapItems(instance) {
    const {element, items} = instance;
    const itemsArray = Array.from(items);

    // Clear container
    element.innerHTML = '';

    // Create carousel wrapper
    const carousel = document.createElement('div');
    carousel.className = 'carousel';

    // Add navigation buttons (using system pattern from MediaViewer)
    if (instance.options.showArrows) {
      const prevBtn = document.createElement('button');
      prevBtn.className = 'nav-button prev icon-prev';
      prevBtn.setAttribute('aria-label', 'Previous');

      const nextBtn = document.createElement('button');
      nextBtn.className = 'nav-button next icon-next';
      nextBtn.setAttribute('aria-label', 'Next');

      carousel.appendChild(prevBtn);
      carousel.appendChild(nextBtn);
    }

    // Create viewport
    const viewport = document.createElement('div');
    viewport.className = 'carousel-viewport';

    // Create track
    const track = document.createElement('div');
    track.className = 'carousel-track';

    // Add items to track
    itemsArray.forEach(item => {
      item.classList.add('carousel-item');
      track.appendChild(item);
    });

    viewport.appendChild(track);
    carousel.appendChild(viewport);
    element.appendChild(carousel);

    // Store references
    instance.carousel = carousel;
    instance.track = track;
    instance.viewport = viewport;
  },

  /**
   * Setup carousel behavior
   * @param {Object} instance - Carousel instance
   * @private
   */
  setupCarousel(instance) {
    const {carousel, options} = instance;

    // Calculate items per view based on viewport
    this.updateItemsPerView(instance);

    // Bind navigation buttons
    const prevBtn = carousel.querySelector('.nav-button.prev');
    const nextBtn = carousel.querySelector('.nav-button.next');

    if (prevBtn) prevBtn.addEventListener('click', () => this.prev(instance));
    if (nextBtn) nextBtn.addEventListener('click', () => this.next(instance));

    // Setup touch/drag
    if (options.touchEnabled) this.setupTouchEvents(instance);

    // Setup autoplay
    if (options.autoplay) {
      this.startAutoplay(instance);
      if (options.pauseOnHover) {
        carousel.addEventListener('mouseenter', () => this.stopAutoplay(instance));
        carousel.addEventListener('mouseleave', () => this.startAutoplay(instance));
      }

      instance.visibilityHandler = () => {
        if (document.visibilityState === 'visible' && document.contains(carousel)) {
          const isHovered = options.pauseOnHover && carousel.matches(':hover');
          if (!isHovered) {
            this.startAutoplay(instance);
          }
        }
      };

      instance.pageshowHandler = () => {
        if (document.contains(carousel)) {
          const isHovered = options.pauseOnHover && carousel.matches(':hover');
          if (!isHovered) {
            this.startAutoplay(instance);
          }
        }
      };

      document.addEventListener('visibilitychange', instance.visibilityHandler);
      window.addEventListener('pageshow', instance.pageshowHandler);
    }

    // Keyboard navigation
    carousel.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowLeft') this.prev(instance);
      if (e.key === 'ArrowRight') this.next(instance);
    });

    // Responsive resize
    const resizeObserver = new ResizeObserver(() => {
      this.updateItemsPerView(instance);
      // Clamp currentIndex so it stays within valid range after breakpoint change
      const maxIndex = Math.max(0, instance.items.length - instance.itemsPerView);
      if (instance.currentIndex > maxIndex) instance.currentIndex = maxIndex;
      this.updatePosition(instance, false); // snap without animation on resize
    });
    resizeObserver.observe(carousel);
    instance.resizeObserver = resizeObserver;

    // Initial position
    this.updatePosition(instance);
  },

  /**
   * Setup touch/drag events
   * @param {Object} instance - Carousel instance
   * @private
   */
  setupTouchEvents(instance) {
    const {carousel, track} = instance;
    let startX = 0, currentX = 0, startTranslateX = 0, isDragging = false, hasMoved = false;

    const handleStart = (e) => {
      const clientX = e.type.includes('mouse') ? e.clientX : e.touches[0].clientX;
      startX = currentX = clientX;
      startTranslateX = this.getCurrentTranslateX(track);
      isDragging = true;
      hasMoved = false;
      track.style.transition = 'none';
      if (e.type.includes('mouse')) {
        carousel.style.cursor = 'grabbing';
        e.preventDefault();
      }
    };

    const handleMove = (e) => {
      if (!isDragging) return;
      const clientX = e.type.includes('mouse') ? e.clientX : e.touches[0].clientX;
      currentX = clientX;
      const diff = currentX - startX;

      if (Math.abs(diff) > 5) {
        hasMoved = true;
        track.classList.add('dragging');
      }

      track.style.transform = `translateX(${startTranslateX + diff}px)`;
    };

    const handleEnd = () => {
      if (!isDragging) return;
      isDragging = false;
      track.style.transition = '';
      carousel.style.cursor = 'grab';

      setTimeout(() => track.classList.remove('dragging'), 50);

      if (hasMoved) {
        instance.preventClick = true;
        setTimeout(() => instance.preventClick = false, 300);
      }

      const diff = currentX - startX;
      if (Math.abs(diff) > instance.options.touchThreshold) {
        diff > 0 ? this.prev(instance) : this.next(instance);
      } else {
        this.updatePosition(instance, true);
      }
    };

    const handleClick = (e) => {
      if (instance.preventClick) {
        e.preventDefault();
        e.stopPropagation();
        return false;
      }
    };

    carousel.addEventListener('click', handleClick, true);
    carousel.addEventListener('touchstart', handleStart, {passive: true});
    carousel.addEventListener('touchmove', handleMove, {passive: true});
    carousel.addEventListener('touchend', handleEnd);
    carousel.addEventListener('touchcancel', handleEnd);
    carousel.addEventListener('mousedown', handleStart);
    document.addEventListener('mousemove', handleMove);
    document.addEventListener('mouseup', handleEnd);
    carousel.style.userSelect = 'none';

    instance.touchHandlers = {handleStart, handleMove, handleEnd, handleClick};
  },

  /**
   * Get current translateX value
   * @param {HTMLElement} element
   * @returns {number}
   * @private
   */
  getCurrentTranslateX(element) {
    const style = window.getComputedStyle(element);
    const matrix = style.transform;
    if (matrix === 'none') return 0;
    const values = matrix.match(/matrix.*\((.+)\)/)[1].split(', ');
    return parseInt(values[4]) || 0;
  },

  /**
   * Update items per view based on viewport
   * @param {Object} instance
   * @private
   */
  updateItemsPerView(instance) {
    const width = window.innerWidth;
    const {breakpoints, itemsPerView} = instance.options;

    if (width < breakpoints.mobile) instance.itemsPerView = itemsPerView.mobile;
    else if (width < breakpoints.tablet) instance.itemsPerView = itemsPerView.tablet;
    else instance.itemsPerView = itemsPerView.desktop;
  },

  /**
   * Update carousel position
   * @param {Object} instance
   * @param {boolean} animate - Whether to animate
   * @private
   */
  updatePosition(instance, animate = true) {
    const {track, currentIndex, options} = instance;
    const items = track.querySelectorAll('.carousel-item');
    if (items.length === 0) return;

    const itemWidth = items[0].offsetWidth;
    // Read actual CSS gap (handles breakpoint changes: desktop=18px, mobile=16px)
    const gap = parseFloat(window.getComputedStyle(track).columnGap) || options.gap;
    const offset = -(currentIndex * (itemWidth + gap));

    track.style.transition = animate ? `transform ${options.speed}ms ${options.easing}` : 'none';
    track.style.transform = `translateX(${offset}px)`;
  },

  /**
   * Go to next item
   * @param {Object} instance
   */
  next(instance) {
    if (instance.isAnimating) return;
    const maxIndex = Math.max(0, instance.items.length - instance.itemsPerView);

    if (instance.currentIndex >= maxIndex) {
      instance.currentIndex = instance.options.loop ? 0 : instance.currentIndex;
    } else {
      instance.currentIndex++;
    }
    if (instance.currentIndex <= maxIndex) this.slide(instance);
  },

  /**
   * Go to previous item
   * @param {Object} instance
   */
  prev(instance) {
    if (instance.isAnimating) return;
    const maxIndex = Math.max(0, instance.items.length - instance.itemsPerView);

    if (instance.currentIndex <= 0) {
      instance.currentIndex = instance.options.loop ? maxIndex : 0;
    } else {
      instance.currentIndex--;
    }
    this.slide(instance);
  },

  /**
   * Animate slide
   * @param {Object} instance
   * @private
   */
  slide(instance) {
    instance.isAnimating = true;
    this.updatePosition(instance);
    setTimeout(() => instance.isAnimating = false, instance.options.speed);
  },

  /**
   * Start autoplay
   * @param {Object} instance
   */
  startAutoplay(instance) {
    if (!instance.options.autoplay) return;
    this.stopAutoplay(instance);
    instance.autoplayTimer = setInterval(() => this.next(instance), instance.options.autoplayInterval);
  },

  /**
   * Stop autoplay
   * @param {Object} instance
   */
  stopAutoplay(instance) {
    if (instance.autoplayTimer) {
      clearInterval(instance.autoplayTimer);
      instance.autoplayTimer = null;
    }
  },

  /**
   * Destroy carousel instance
   * @param {string|HTMLElement} container
   */
  destroy(container) {
    const element = typeof container === 'string' ? document.querySelector(container) : container;
    if (!element || !element.dataset.carouselId) return;

    const instanceId = element.dataset.carouselId;
    const instance = this.state.instances.get(instanceId);
    if (!instance) return;

    this.stopAutoplay(instance);
    if (instance.resizeObserver) instance.resizeObserver.disconnect();
    if (instance.visibilityHandler) {
      document.removeEventListener('visibilitychange', instance.visibilityHandler);
    }
    if (instance.pageshowHandler) {
      window.removeEventListener('pageshow', instance.pageshowHandler);
    }
    this.state.instances.delete(instanceId);
    delete element.dataset.carouselId;
  }
};

// Export globally for Now.js
window.Carousel = Carousel;
