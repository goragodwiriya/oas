/**
 * Slideshow -Component for creating customizable slideshows.
 *
 * Support for setting via data-*attributes:
 * -data-effect: type of effect (fade, slide, zoom, flip, blur)
 * -data-duration: How long each slide is displayed (ms)
 * -data-speed: slide change speed (ms)
 * -data-autoplay: Start autoplay (true/false)
 * -data-loop: loop (true/false)
 * -data-controls: show control buttons (true/false)
 * -data-indicators: show status points (true/false)
 * -data-pause-on-hover: Pause when mouse hovers (true/false)
 * -data-fullscreen: support full screen mode (true/false)
 */
const Slideshow = {
  config: {
    effect: 'fade',          //Effect type (fade, slide, zoom, flip, blur)
    duration: 5000,          // Duration of displaying each slide (ms)
    speed: 500,              // Slide change speed (ms)
    autoplay: true,          // Start playing automatically
    loop: true,              // Repeat
    controls: true,          // Show control buttons
    indicators: true,        // Show status points
    pauseOnHover: true,      // Stop when mouse points
    keyboard: true,          // Supports keyboard control
    touch: true,             // Supports touch control
    fullscreen: false,       // Full screen mode supported
    adaptiveHeight: false,   // Adjust height according to slide
    captionPosition: 'bottom', // Description position
    lazyLoad: true,          // Load images when needed.
    preloadImages: 1,        // Number of preloaded images
    a11y: true,              // Supports accessibility
    debug: false             // Debug mode
  },

  state: {
    instances: new Map(),
    initialized: false
  },

  // Register various effects
  effects: {
    // Effect: fade in, fade out
    fade: {
      apply: (instance, currentIndex, nextIndex) => {
        const slides = instance.slides;
        const next = slides[nextIndex];

        // Set transition
        slides.forEach(slide => {
          slide.style.transition = `opacity ${instance.options.speed}ms ease`;
        });

        // Hide all slides
        slides.forEach(slide => {
          slide.style.opacity = 0;
          slide.setAttribute('aria-hidden', 'true');
        });

        // Show next slide
        next.style.opacity = 1;
        next.setAttribute('aria-hidden', 'false');
      }
    },

    // Esliding effect
    slide: {
      apply: (instance, currentIndex, nextIndex) => {
        const slides = instance.slides;
        const current = slides[currentIndex];
        const next = slides[nextIndex];
        const direction = nextIndex > currentIndex ? 1 : -1;
        const offset = direction * 100;

        // Set transition
        slides.forEach(slide => {
          slide.style.transition = `transform ${instance.options.speed}ms ease, opacity 0ms`;
        });

        // Align the next slide
        next.style.transform = `translateX(${offset}%)`;
        next.style.opacity = 1;
        next.setAttribute('aria-hidden', 'false');

        // move slide
        setTimeout(() => {
          current.style.transform = `translateX(${-offset}%)`;
          next.style.transform = 'translateX(0)';

          current.setAttribute('aria-hidden', 'true');

          // Reset state after animation completes.
          setTimeout(() => {
            slides.forEach(slide => {
              if (slide !== next) {
                slide.style.opacity = 0;
                slide.style.transform = '';
              }
            });
          }, instance.options.speed);
        }, 20);
      }
    },

    // Zoom Effect
    zoom: {
      apply: (instance, currentIndex, nextIndex) => {
        const slides = instance.slides;
        const current = slides[currentIndex];
        const next = slides[nextIndex];

        // Set transition
        slides.forEach(slide => {
          slide.style.transition = `opacity ${instance.options.speed}ms ease, transform ${instance.options.speed}ms ease`;
        });

        // Prepare the next slide
        next.style.opacity = 0;
        next.style.transform = 'scale(1.2)';
        next.setAttribute('aria-hidden', 'false');

        // Show next slide with zoom
        setTimeout(() => {
          current.style.opacity = 0;
          current.style.transform = 'scale(0.8)';
          current.setAttribute('aria-hidden', 'true');

          next.style.opacity = 1;
          next.style.transform = 'scale(1)';

          // Reset state after animation completes.
          setTimeout(() => {
            slides.forEach(slide => {
              if (slide !== next) {
                slide.style.opacity = 0;
                slide.style.transform = '';
              }
            });
          }, instance.options.speed);
        }, 20);
      }
    },

    // Flip effect
    flip: {
      apply: (instance, currentIndex, nextIndex) => {
        const slides = instance.slides;
        const current = slides[currentIndex];
        const next = slides[nextIndex];

        // Prepare parent for 3D transform
        if (instance.wrapper) {
          instance.wrapper.style.perspective = '1000px';
        }

        // Set transition
        slides.forEach(slide => {
          slide.style.transition = `opacity 0ms, transform ${instance.options.speed}ms ease`;
          slide.style.backfaceVisibility = 'hidden';
        });

        // Prepare the next slide
        next.style.opacity = 0;
        next.style.transform = 'rotateY(-90deg)';
        next.setAttribute('aria-hidden', 'false');

        // flip slide
        setTimeout(() => {
          current.style.transform = 'rotateY(90deg)';
          current.setAttribute('aria-hidden', 'true');

          // Wait halfway and show the next slide.
          setTimeout(() => {
            next.style.opacity = 1;
            next.style.transform = 'rotateY(0deg)';

            // Reset state after animation completes.
            setTimeout(() => {
              slides.forEach(slide => {
                if (slide !== next) {
                  slide.style.opacity = 0;
                  slide.style.transform = '';
                }
              });
            }, instance.options.speed / 2);
          }, instance.options.speed / 2);
        }, 20);
      }
    },

    // Blur effect
    blur: {
      apply: (instance, currentIndex, nextIndex) => {
        const slides = instance.slides;
        const current = slides[currentIndex];
        const next = slides[nextIndex];

        // Set transition
        slides.forEach(slide => {
          slide.style.transition = `opacity ${instance.options.speed}ms ease, filter ${instance.options.speed}ms ease`;
        });

        // Prepare the next slide
        next.style.opacity = 0;
        next.style.filter = 'blur(0px)';
        next.setAttribute('aria-hidden', 'false');

        // Change slides with blur effect
        setTimeout(() => {
          current.style.opacity = 0;
          current.style.filter = 'blur(20px)';
          current.setAttribute('aria-hidden', 'true');

          next.style.opacity = 1;

          // Reset state after animation completes.
          setTimeout(() => {
            slides.forEach(slide => {
              if (slide !== next) {
                slide.style.opacity = 0;
                slide.style.filter = '';
              }
            });
          }, instance.options.speed);
        }, 20);
      }
    }
  },

  /**
   * Create a new instance of Slideshow.
   */
  create(element, options = {}) {
    // If it's a string, search for element.
    if (typeof element === 'string') {
      element = document.querySelector(element);
    }

    if (!element) {
      if (this.config.debug) {
        console.error('[Slideshow] Element not found');
      }
      return null;
    }

    // Check if the instance already exists.
    const existingInstance = this.getInstance(element);
    if (existingInstance) {
      return existingInstance;
    }

    // Create a new instance
    const instance = {
      id: 'slideshow_' + Math.random().toString(36).substring(2, 11),
      element,
      options: {...this.config, ...this.extractOptionsFromElement(element), ...options},
      slides: [],
      currentIndex: 0,
      isPlaying: false,
      isPaused: false,
      pausedByHover: false,
      touchStartX: 0,
      touchStartY: 0,
      isFullscreen: false,
      timer: null,
      wrapper: null,
      controls: {
        prev: null,
        next: null,
        indicators: [],
        fullscreen: null
      }
    };

    // start work
    this.setup(instance);

    // store instance
    this.state.instances.set(instance.id, instance);
    element.dataset.slideshowId = instance.id;

    return instance;
  },

  /**
   * Set up instance
   */
  setup(instance) {
    try {
      // Find a slide
      this.findSlides(instance);
      if (instance.slides.length === 0) {
        if (instance.options.debug) {
          console.warn('[Slideshow] No slides found');
        }
        return;
      }

      // Prepare the DOM structure
      this.setupDOM(instance);

      // Create a controller
      if (instance.options.controls) {
        this.createControls(instance);
      }

      // Create a status point
      if (instance.options.indicators) {
        this.createIndicators(instance);
      }

      // Create a full screen buttonn button
      if (instance.options.fullscreen) {
        this.createFullscreenButton(instance);
      }

      // Bind event
      this.bindEvents(instance);

      // preload images
      if (instance.options.lazyLoad) {
        this.preloadImages(instance);
      }

      // Show first slide
      this.goToSlide(instance, 0);

      // Start playing automatically
      if (instance.options.autoplay) {
        this.play(instance);
      }

      // Height adjustment
      if (instance.options.adaptiveHeight) {
        this.updateHeight(instance);
      }

      // Initialized event notification
      this.dispatchEvent(instance, 'init', {
        instance
      });

    } catch (error) {
      if (window.ErrorManager) {
        ErrorManager.handle(error, {
          context: 'Slideshow.setup',
          type: 'error:slideshow',
          data: {
            element: instance.element,
            options: instance.options
          }
        });
      } else {
        console.error('[Slideshow] Error setting up slideshow:', error);
      }
    }
  },

  /**
   * Search for slides within an element
   */
  findSlides(instance) {
    const element = instance.element;

    // Find images with slideshow or data-slideshow classes.
    const images = Array.from(
      element.querySelectorAll('img.slideshow, img[data-slideshow]')
    );

    // Find backgrounds that have the slideshow-bg or data-slideshow-bg classes.
    const backgrounds = Array.from(
      element.querySelectorAll('.slideshow-bg, [data-slideshow-bg]')
    );

    instance.slides = [...images, ...backgrounds];

    // If not found, it may be that the slide is in a different format (such as an internal div).
    if (instance.slides.length === 0) {
      // Make sure div.slideshow-slide exists. Already or not?
      const existingSlides = Array.from(
        element.querySelectorAll('.slideshow-slide')
      );

      if (existingSlides.length > 0) {
        instance.slides = existingSlides;
      } else {
        const children = Array.from(element.children).filter(child =>
          child.tagName === 'DIV' &&
          !child.classList.contains('slideshow-controls') &&
          !child.classList.contains('slideshow-indicators')
        );

        if (children.length > 0) {
          instance.slides = children;
        }
      }
    }

    return instance.slides;
  },

  /**
   * Prepare the DOM structure
   */
  setupDOM(instance) {
    const element = instance.element;

    // Add classes and set styles
    element.classList.add('slideshow');
    element.style.position = 'relative';
    element.style.overflow = 'hidden';

    // Create wrapper
    const wrapper = document.createElement('div');
    wrapper.className = 'slideshow-wrapper';
    wrapper.setAttribute('role', 'region');
    wrapper.setAttribute('aria-label', 'Slideshow');
    instance.wrapper = wrapper;

    // If slide structure does not exist yet
    if (element.querySelector('.slideshow-slide') === null) {
      instance.slides.forEach((slide, index) => {
        const slideEl = document.createElement('div');
        slideEl.className = 'slideshow-slide';
        slideEl.setAttribute('role', 'tabpanel');
        slideEl.setAttribute('aria-hidden', index === 0 ? 'false' : 'true');

        // If it's an image
        if (slide instanceof HTMLImageElement) {
          const img = document.createElement('img');

          // If using lazy loading
          if (instance.options.lazyLoad && index > instance.options.preloadImages) {
            img.dataset.src = slide.src;
            img.src = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"%3E%3C/svg%3E';
          } else {
            img.src = slide.src;
          }

          img.alt = slide.alt || '';
          slideEl.appendChild(img);

          // Create caption if any
          if (slide.dataset.caption) {
            const caption = document.createElement('div');
            caption.className = `slideshow-caption slideshow-caption-${instance.options.captionPosition}`;
            caption.innerHTML = slide.dataset.caption;
            slideEl.appendChild(caption);
          }

          // Store reference data
          slideEl.originalElement = slide;
          slide.parentNode.removeChild(slide);

        } else {
          // If it's a background
          slideEl.style.backgroundImage = getComputedStyle(slide).backgroundImage;

          if (slide.innerHTML.trim()) {
            slideEl.innerHTML = slide.innerHTML;
          }

          // Store reference data
          slideEl.originalElement = slide;
          slide.parentNode.removeChild(slide);
        }

        // Set base styles
        slideEl.style.position = 'absolute';
        slideEl.style.top = 0;
        slideEl.style.left = 0;
        slideEl.style.width = '100%';
        slideEl.style.height = '100%';
        slideEl.style.opacity = index === 0 ? 1 : 0;

        wrapper.appendChild(slideEl);
      });

      element.appendChild(wrapper);

      // Update slide references
      instance.slides = Array.from(wrapper.querySelectorAll('.slideshow-slide'));
    } else {
      // If structure already exists (e.g., created directly with HTML)
      element.appendChild(wrapper);

      Array.from(element.querySelectorAll('.slideshow-slide')).forEach(slide => {
        wrapper.appendChild(slide);
      });

      instance.slides = Array.from(wrapper.querySelectorAll('.slideshow-slide'));
    }
  },

  /**
   * Create control buttons
   */
  createControls(instance) {
    const controls = document.createElement('div');
    controls.className = 'slideshow-controls';

    const createButton = (className, label, handler) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = `slideshow-${className}`;
      button.setAttribute('aria-label', label);
      button.innerHTML = `<span class="slideshow-${className}-icon">${label}</span>`;

      button.addEventListener('click', (e) => {
        e.preventDefault();
        handler(instance);
      });

      return button;
    };

    // Previous button
    const prevButton = createButton('prev', '<', this.prev.bind(this));
    controls.appendChild(prevButton);
    instance.controls.prev = prevButton;

    // Next button
    const nextButton = createButton('next', '>', this.next.bind(this));
    controls.appendChild(nextButton);
    instance.controls.next = nextButton;

    instance.element.appendChild(controls);
  },

  /**
   * Create indicators
   */
  createIndicators(instance) {
    const indicators = document.createElement('div');
    indicators.className = 'slideshow-indicators';

    for (let i = 0; i < instance.slides.length; i++) {
      const indicator = document.createElement('button');
      indicator.type = 'button';
      indicator.className = 'slideshow-indicator';
      indicator.setAttribute('aria-label', `Slide ${i + 1}`);

      if (i === 0) {
        indicator.classList.add('active');
        indicator.setAttribute('aria-current', 'true');
      }

      indicator.addEventListener('click', () => this.goToSlide(instance, i));

      indicators.appendChild(indicator);
      instance.controls.indicators.push(indicator);
    }

    instance.element.appendChild(indicators);
  },

  /**
   * Create fullscreen button
   */
  createFullscreenButton(instance) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'slideshow-fullscreen';
    button.setAttribute('aria-label', 'Toggle fullscreen');
    button.innerHTML = '⛶';

    button.addEventListener('click', () => {
      if (instance.isFullscreen) {
        this.exitFullscreen(instance);
      } else {
        this.enterFullscreen(instance);
      }
    });

    instance.element.appendChild(button);
    instance.controls.fullscreen = button;
  },

  /**
   * Bind events
   */
  bindEvents(instance) {
    // Bind keyboard events
    if (instance.options.keyboard) {
      instance.handlers = instance.handlers || {};

      instance.handlers.keydown = (e) => {
        if (!instance.element.contains(document.activeElement) &&
          document.activeElement !== document.body) {
          return;
        }

        switch (e.key) {
          case 'ArrowLeft':
            this.prev(instance);
            break;
          case 'ArrowRight':
            this.next(instance);
            break;
          case ' ':
            e.preventDefault();
            if (instance.isPlaying) {
              this.pause(instance);
            } else {
              this.play(instance);
            }
            break;
          case 'Escape':
            if (instance.isFullscreen) {
              this.exitFullscreen(instance);
            }
            break;
        }
      };

      document.addEventListener('keydown', instance.handlers.keydown);
    }

    // Bind touch events
    if (instance.options.touch) {
      instance.handlers = instance.handlers || {};

      let touchStartX = 0;
      let touchStartY = 0;

      instance.handlers.touchstart = (e) => {
        touchStartX = e.touches[0].clientX;
        touchStartY = e.touches[0].clientY;
      };

      instance.handlers.touchmove = (e) => {
        if (!touchStartX || !touchStartY) return;

        const diffX = touchStartX - e.touches[0].clientX;
        const diffY = touchStartY - e.touches[0].clientY;

        // Check if horizontal swipe is greater than vertical
        if (Math.abs(diffX) > Math.abs(diffY)) {
          e.preventDefault(); // Prevent page scrolling

          if (Math.abs(diffX) > 50) { // Require at least 50px movement
            if (diffX > 0) {
              this.next(instance);
            } else {
              this.prev(instance);
            }

            // Reset starting point
            touchStartX = 0;
            touchStartY = 0;
          }
        }
      };

      instance.element.addEventListener('touchstart', instance.handlers.touchstart, {passive: true});
      instance.element.addEventListener('touchmove', instance.handlers.touchmove, {passive: false});
    }

    // Bind pause on hover
    if (instance.options.pauseOnHover) {
      instance.handlers = instance.handlers || {};

      instance.handlers.mouseenter = () => {
        if (instance.isPlaying) {
          instance.isPaused = true;
          instance.pausedByHover = true;
          clearTimeout(instance.timer);
        }
      };

      instance.handlers.mouseleave = () => {
        if (instance.isPlaying && instance.isPaused && instance.pausedByHover) {
          instance.isPaused = false;
          instance.pausedByHover = false;
          this.setTimer(instance);
        }
      };

      instance.element.addEventListener('mouseenter', instance.handlers.mouseenter);
      instance.element.addEventListener('mouseleave', instance.handlers.mouseleave);
    }

    // Bind resize event for adaptive height
    if (instance.options.adaptiveHeight) {
      instance.handlers = instance.handlers || {};

      instance.handlers.resize = () => {
        this.updateHeight(instance);
      };

      window.addEventListener('resize', instance.handlers.resize);
    }

    // Bind beforeunload event to clean up
    instance.handlers = instance.handlers || {};
    instance.handlers.beforeunload = () => {
      this.destroy(instance.id);
    };

    window.addEventListener('beforeunload', instance.handlers.beforeunload);

    instance.handlers.visibilitychange = () => {
      if (document.visibilityState !== 'visible') return;

      if (instance.options.adaptiveHeight) {
        this.updateHeight(instance);
      }

      if (instance.options.autoplay && instance.isPlaying && instance.pausedByHover) {
        const isHovered = instance.options.pauseOnHover && instance.element.matches(':hover');
        if (!isHovered) {
          instance.isPaused = false;
          instance.pausedByHover = false;
          this.setTimer(instance);
        }
      }
    };

    instance.handlers.pageshow = () => {
      if (instance.options.adaptiveHeight) {
        this.updateHeight(instance);
      }

      if (instance.options.autoplay && instance.isPlaying && instance.pausedByHover) {
        const isHovered = instance.options.pauseOnHover && instance.element.matches(':hover');
        if (!isHovered) {
          instance.isPaused = false;
          instance.pausedByHover = false;
          this.setTimer(instance);
        }
      }
    };

    document.addEventListener('visibilitychange', instance.handlers.visibilitychange);
    window.addEventListener('pageshow', instance.handlers.pageshow);
  },

  /**
   * Preload images
   */
  preloadImages(instance) {
    const preloadCount = Math.min(instance.options.preloadImages, instance.slides.length);

    for (let i = 0; i < preloadCount; i++) {
      this.loadSlideImage(instance, i);
    }
  },

  /**
   * Load slide image
   */
  loadSlideImage(instance, index) {
    const slide = instance.slides[index];
    if (!slide) return;

    const img = slide.querySelector('img[data-src]');
    if (img && img.dataset.src) {
      img.src = img.dataset.src;
      img.removeAttribute('data-src');

      img.onload = () => {
        if (instance.options.adaptiveHeight && index === instance.currentIndex) {
          this.updateHeight(instance);
        }
      };
    }
  },

  /**
   * Update slideshow height
   */
  updateHeight(instance) {
    if (!instance.options.adaptiveHeight) return;

    const currentSlide = instance.slides[instance.currentIndex];
    if (!currentSlide) return;

    const img = currentSlide.querySelector('img');

    if (img && img.complete) {
      const height = img.offsetHeight;
      instance.wrapper.style.height = `${height}px`;
    } else if (img) {
      img.onload = () => {
        const height = img.offsetHeight;
        instance.wrapper.style.height = `${height}px`;
      };
    }
  },

  /**
   * Go to specified slide
   */
  goToSlide(instance, index) {
    if (index === instance.currentIndex) return;

    // Validate index
    if (index < 0) {
      index = instance.options.loop ? instance.slides.length - 1 : 0;
    } else if (index >= instance.slides.length) {
      index = instance.options.loop ? 0 : instance.slides.length - 1;
    }

    // Save previous index value
    const previousIndex = instance.currentIndex;

    // Update indicators
    if (instance.controls.indicators.length) {
      instance.controls.indicators[previousIndex]?.classList.remove('active');
      instance.controls.indicators[previousIndex]?.removeAttribute('aria-current');

      instance.controls.indicators[index]?.classList.add('active');
      instance.controls.indicators[index]?.setAttribute('aria-current', 'true');
    }

    // Update current index
    instance.currentIndex = index;

    // Use configured effect
    const effect = this.effects[instance.options.effect] || this.effects.fade;
    effect.apply(instance, previousIndex, index);

    // Load next images (for lazy loading)
    if (instance.options.lazyLoad) {
      // Load current image
      this.loadSlideImage(instance, index);

      // Load next image
      const nextIndex = (index + 1) % instance.slides.length;
      this.loadSlideImage(instance, nextIndex);
    }

    // Update height (adaptive)
    if (instance.options.adaptiveHeight) {
      this.updateHeight(instance);
    }

    // Dispatch change event
    this.dispatchEvent(instance, 'change', {
      currentIndex: index,
      previousIndex: previousIndex
    });

    // If autoplaying, reset timer
    if (instance.isPlaying && !instance.isPaused) {
      this.setTimer(instance);
    }
  },

  /**
   * Go to next slide
   */
  next(instance) {
    this.goToSlide(instance, instance.currentIndex + 1);
  },

  /**
   * Go to previous slide
   */
  prev(instance) {
    this.goToSlide(instance, instance.currentIndex - 1);
  },

  /**
   * Start autoplay
   */
  play(instance) {
    if (instance.isPlaying) return;

    instance.isPlaying = true;
    instance.isPaused = false;
    instance.pausedByHover = false;

    this.setTimer(instance);

    this.dispatchEvent(instance, 'play');
  },

  /**
   * Set timer
   */
  setTimer(instance) {
    clearTimeout(instance.timer);

    instance.timer = setTimeout(() => {
      if (instance.isPlaying && !instance.isPaused) {
        this.next(instance);
      }
    }, instance.options.duration);
  },

  /**
   * Pause playback
   */
  pause(instance) {
    if (!instance.isPlaying) return;

    instance.isPaused = true;
    instance.pausedByHover = false;
    clearTimeout(instance.timer);

    this.dispatchEvent(instance, 'pause');
  },

  /**
   * Stop playback
   */
  stop(instance) {
    instance.isPlaying = false;
    instance.isPaused = false;
    instance.pausedByHover = false;
    clearTimeout(instance.timer);

    this.dispatchEvent(instance, 'stop');
  },

  /**
   * Enter fullscreen mode
   */
  enterFullscreen(instance) {
    if (instance.isFullscreen) return;

    // Use BackdropManager if available
    if (window.BackdropManager) {
      instance.backdropId = BackdropManager.show(instance.element, () => {
        this.exitFullscreen(instance);
      }, {
        opacity: 1,
        background: 'rgba(0,0,0,0.95)'
      });
    }

    instance.element.classList.add('slideshow-fullscreen');
    instance.isFullscreen = true;

    // Update button text
    if (instance.controls.fullscreen) {
      instance.controls.fullscreen.setAttribute('aria-label', 'Exit fullscreen');
      instance.controls.fullscreen.innerHTML = '⎋';
    }

    this.dispatchEvent(instance, 'fullscreen', {fullscreen: true});
  },

  /**
   * Exit fullscreen mode
   */
  exitFullscreen(instance) {
    if (!instance.isFullscreen) return;

    // Hide backdrop if present
    if (window.BackdropManager && instance.backdropId !== null) {
      BackdropManager.hide(instance.backdropId);
      instance.backdropId = null;
    }

    instance.element.classList.remove('slideshow-fullscreen');
    instance.isFullscreen = false;

    // Update button text
    if (instance.controls.fullscreen) {
      instance.controls.fullscreen.setAttribute('aria-label', 'Enter fullscreen');
      instance.controls.fullscreen.innerHTML = '⛶';
    }

    this.dispatchEvent(instance, 'fullscreen', {fullscreen: false});
  },

  /**
   * Extract options from data attributes
   */
  extractOptionsFromElement(element) {
    const options = {};
    const dataset = element.dataset;

    // Read values from data-* attributes
    if (dataset.effect) options.effect = dataset.effect;
    if (dataset.duration) options.duration = parseInt(dataset.duration, 10);
    if (dataset.speed) options.speed = parseInt(dataset.speed, 10);
    if (dataset.autoplay !== undefined) options.autoplay = dataset.autoplay !== 'false';
    if (dataset.loop !== undefined) options.loop = dataset.loop !== 'false';
    if (dataset.controls !== undefined) options.controls = dataset.controls !== 'false';
    if (dataset.indicators !== undefined) options.indicators = dataset.indicators !== 'false';
    if (dataset.pauseOnHover !== undefined) options.pauseOnHover = dataset.pauseOnHover !== 'false';
    if (dataset.keyboard !== undefined) options.keyboard = dataset.keyboard !== 'false';
    if (dataset.touch !== undefined) options.touch = dataset.touch !== 'false';
    if (dataset.fullscreen !== undefined) options.fullscreen = dataset.fullscreen === 'true';
    if (dataset.adaptiveHeight !== undefined) options.adaptiveHeight = dataset.adaptiveHeight === 'true';
    if (dataset.captionPosition) options.captionPosition = dataset.captionPosition;
    if (dataset.lazyLoad !== undefined) options.lazyLoad = dataset.lazyLoad !== 'false';
    if (dataset.preloadImages) options.preloadImages = parseInt(dataset.preloadImages, 10);

    return options;
  },

  /**
   * Dispatch event
   */
  dispatchEvent(instance, eventName, detail = {}) {
    if (!instance.element) return;

    const event = new CustomEvent(`slideshow:${eventName}`, {
      bubbles: true,
      cancelable: true,
      detail: {
        instance,
        ...detail
      }
    });

    instance.element.dispatchEvent(event);

    EventManager.emit(`slideshow:${eventName}`, {
      instance,
      ...detail
    });

  },

  /**
   * Register new effect
   */
  registerEffect(name, effectHandler) {
    if (!name || typeof name !== 'string') {
      console.error('[Slideshow] Effect name must be a string');
      return;
    }

    if (!effectHandler || typeof effectHandler.apply !== 'function') {
      console.error('[Slideshow] Effect handler must have an apply method');
      return;
    }

    this.effects[name] = effectHandler;
  },

  /**
   * Find instance from element
   */
  getInstance(element) {
    // If element is a selector string, query it
    if (typeof element === 'string') {
      element = document.querySelector(element);
    }

    if (!element) return null;

    // Check by data-slideshow-id
    const id = element.dataset.slideshowId;
    if (id && this.state.instances.has(id)) {
      return this.state.instances.get(id);
    }

    // Search across all instances
    for (const instance of this.state.instances.values()) {
      if (instance.element === element) {
        return instance;
      }
    }

    return null;
  },

  /**
   * Destroy instance
   */
  destroy(instanceOrId) {
    let instance;

    // If an ID is provided, find the instance
    if (typeof instanceOrId === 'string') {
      instance = this.state.instances.get(instanceOrId);
    } else if (instanceOrId instanceof HTMLElement) {
      instance = this.getInstance(instanceOrId);
    } else {
      instance = instanceOrId;
    }

    if (!instance) return false;

    // Stop playback
    this.stop(instance);

    // Remove event listeners
    if (instance.handlers) {
      if (instance.handlers.keydown) {
        document.removeEventListener('keydown', instance.handlers.keydown);
      }

      if (instance.handlers.touchstart) {
        instance.element.removeEventListener('touchstart', instance.handlers.touchstart);
      }

      if (instance.handlers.touchmove) {
        instance.element.removeEventListener('touchmove', instance.handlers.touchmove);
      }

      if (instance.handlers.mouseenter) {
        instance.element.removeEventListener('mouseenter', instance.handlers.mouseenter);
      }

      if (instance.handlers.mouseleave) {
        instance.element.removeEventListener('mouseleave', instance.handlers.mouseleave);
      }

      if (instance.handlers.resize) {
        window.removeEventListener('resize', instance.handlers.resize);
      }

      if (instance.handlers.beforeunload) {
        window.removeEventListener('beforeunload', instance.handlers.beforeunload);
      }

      if (instance.handlers.visibilitychange) {
        document.removeEventListener('visibilitychange', instance.handlers.visibilitychange);
      }

      if (instance.handlers.pageshow) {
        window.removeEventListener('pageshow', instance.handlers.pageshow);
      }
    }

    // Exit fullscreen if active
    if (instance.isFullscreen) {
      this.exitFullscreen(instance);
    }

    // Remove added DOM elements
    if (instance.controls) {
      if (instance.controls.prev && instance.controls.prev.parentNode) {
        instance.controls.prev.parentNode.removeChild(instance.controls.prev);
      }

      if (instance.controls.next && instance.controls.next.parentNode) {
        instance.controls.next.parentNode.removeChild(instance.controls.next);
      }

      if (instance.controls.fullscreen && instance.controls.fullscreen.parentNode) {
        instance.controls.fullscreen.parentNode.removeChild(instance.controls.fullscreen);
      }

      const indicators = instance.element.querySelector('.slideshow-indicators');
      if (indicators) {
        indicators.parentNode.removeChild(indicators);
      }

      const controls = instance.element.querySelector('.slideshow-controls');
      if (controls) {
        controls.parentNode.removeChild(controls);
      }
    }

    // Dispatch destroy event
    this.dispatchEvent(instance, 'destroy');

    // Remove from Map
    if (instance.id) {
      this.state.instances.delete(instance.id);
    }

    // Remove data attribute
    if (instance.element) {
      delete instance.element.dataset.slideshowId;
    }

    return true;
  },

  /**
   * Update options for instance
   */
  updateOptions(instance, newOptions) {
    if (!instance) return false;

    // Save old options
    const oldOptions = {...instance.options};

    // Update with new options
    instance.options = {...instance.options, ...newOptions};

    // Check changes and update accordingly

    // Toggle controls display
    if (oldOptions.controls !== instance.options.controls) {
      if (instance.options.controls) {
        this.createControls(instance);
      } else {
        const controls = instance.element.querySelector('.slideshow-controls');
        if (controls) {
          controls.parentNode.removeChild(controls);
        }
        instance.controls.prev = null;
        instance.controls.next = null;
      }
    }

    // Toggle indicators display
    if (oldOptions.indicators !== instance.options.indicators) {
      if (instance.options.indicators) {
        this.createIndicators(instance);
      } else {
        const indicators = instance.element.querySelector('.slideshow-indicators');
        if (indicators) {
          indicators.parentNode.removeChild(indicators);
        }
        instance.controls.indicators = [];
      }
    }

    // Toggle fullscreen button display
    if (oldOptions.fullscreen !== instance.options.fullscreen) {
      if (instance.options.fullscreen) {
        this.createFullscreenButton(instance);
      } else if (instance.controls.fullscreen) {
        instance.controls.fullscreen.parentNode.removeChild(instance.controls.fullscreen);
        instance.controls.fullscreen = null;
      }
    }

    // Toggle autoplay state
    if (oldOptions.autoplay !== instance.options.autoplay) {
      if (instance.options.autoplay) {
        this.play(instance);
      } else {
        this.stop(instance);
      }
    }

    // Update control states
    this.updateControlsState(instance);

    return true;
  },

  /**
   * Update controls state
   */
  updateControlsState(instance) {
    // Check previous button
    if (instance.controls.prev) {
      if (!instance.options.loop && instance.currentIndex === 0) {
        instance.controls.prev.disabled = true;
        instance.controls.prev.setAttribute('aria-disabled', 'true');
      } else {
        instance.controls.prev.disabled = false;
        instance.controls.prev.removeAttribute('aria-disabled');
      }
    }

    // Check next button
    if (instance.controls.next) {
      if (!instance.options.loop && instance.currentIndex === instance.slides.length - 1) {
        instance.controls.next.disabled = true;
        instance.controls.next.setAttribute('aria-disabled', 'true');
      } else {
        instance.controls.next.disabled = false;
        instance.controls.next.removeAttribute('aria-disabled');
      }
    }
  },

  /**
   * Initialize Slideshow defaults
   */
  async init(options = {}) {
    // Update config values
    this.config = {...this.config, ...options};

    // Begin searching for elements with data-component="slideshow"
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', () => this.initElements());
    } else {
      this.initElements();
    }

    this.state.initialized = true;
    return this;
  },

  /**
   * Initialize elements with data-component="slideshow"
   */
  initElements() {
    document.querySelectorAll('[data-component="slideshow"]').forEach(element => {
      this.create(element);
    });
  }
};

/**
 * Register component with ComponentManager
 */
if (window.ComponentManager) {
  ComponentManager.define('slideshow', {
    template: null,

    validElement(element) {
      return element.classList.contains('slideshow') ||
        element.dataset.component === 'slideshow' ||
        element.querySelectorAll('img.slideshow, img[data-slideshow], .slideshow-bg, [data-slideshow-bg]').length > 0;
    },

    setupElement(element, state) {
      const options = Slideshow.extractOptionsFromElement(element);
      const slideshow = Slideshow.create(element, options);

      element._slideshow = slideshow;
      return element;
    },

    beforeDestroy() {
      if (this.element && this.element._slideshow) {
        Slideshow.destroy(this.element._slideshow);
        delete this.element._slideshow;
      }
    }
  });
}

/**
 * Register Slideshow with Now.js framework
 */
if (window.Now?.registerManager) {
  Now.registerManager('slideshow', Slideshow);
}

/**
 * Expose Slideshow globally
 */
window.Slideshow = Slideshow;
