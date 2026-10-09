/**
 * Gallery Frontend – GallerySlideshow Component
 * -----------------------------------------------
 * Enhanced slideshow inspired by the Kanchanaburi demo with multiple
 * transition effects, auto-init from DOM attributes, Now.js EventManager
 * integration, full lightbox support and backward-compatible gallery widget.
 *
 * Effects available (data-effect="…"):
 *   fade       – scale + blur + subtle rotate (kanchanaburi style, default)
 *   slide      – horizontal slide
 *   zoom       – zoom-in entrance / zoom-out exit
 *   crossfade  – simple opacity crossfade
 *   flip       – 3-D horizontal flip
 *   cube       – 3-D cube rotation
 *
 * Quick-start (auto-init):
 *   <div data-slideshow>
 *     <div class="now-slide"><img src="…"><div class="now-slide-caption">…</div></div>
 *     …
 *   </div>
 *
 * Full options via data-attributes:
 *   data-effect="fade"
 *   data-interval="6000"
 *   data-autoplay="true"
 *   data-pause-on-hover="true"
 *   data-loop="true"
 *   data-show-nav="true"
 *   data-show-indicators="true"
 *   data-keyboard="true"
 *   data-swipe="true"
 *   data-height="500px" // or "auto" for auto-height based on each slide's image aspect ratio
 *   data-glow="true"
 *
 * Programmatic API:
 *   const ss = new GallerySlideshow('#mySlideshow', { effect: 'cube', interval: 4000 });
 *   ss.next();  ss.prev();  ss.goTo(2);
 *   ss.play();  ss.pause(); ss.destroy();
 *   ss.setEffect('flip');
 */

(function(global) {
  'use strict';

  /* ============================================================
     Helpers
     ============================================================ */
  function parseBool(val, fallback) {
    if (val === undefined || val === null || val === '') return fallback;
    if (val === true || val === 'true') return true;
    if (val === false || val === 'false') return false;
    return fallback;
  }

  const SVG_PREV = `<svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>`;
  const SVG_NEXT = `<svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>`;
  const SVG_CLOSE = `<svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>`;
  const SVG_EXPAND = `<svg viewBox="0 0 24 24"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>`;

  /* ============================================================
     GallerySlideshow
     ============================================================ */
  class GallerySlideshow {
    /**
     * Available transition effects.
     * @type {string[]}
     */
    static EFFECTS = ['fade', 'slide', 'zoom', 'crossfade', 'flip', 'cube'];

    /**
     * Default configuration.
     * @type {Object}
     */
    static defaults = {
      /** Transition effect: 'fade' | 'slide' | 'zoom' | 'crossfade' | 'flip' | 'cube' */
      effect: 'fade',
      /** Auto-play interval in ms */
      interval: 6000,
      /** Enable auto-play on init */
      autoplay: true,
      /** Pause on mouse hover */
      pauseOnHover: true,
      /** Loop back to first after last */
      loop: true,
      /** Show prev/next buttons */
      showNav: true,
      /** Show dot indicators (appended after container) */
      showIndicators: true,
      /** Enable keyboard arrow navigation */
      keyboard: true,
      /** Enable touch-swipe navigation */
      swipe: true,
      /** Override container height (e.g. '400px') */
      height: '500px', // or "auto" for auto-height based on each slide's image aspect ratio
      /** Enable the animated glow border */
      glow: true,
      /** Enable lightbox on slide click */
      lightbox: true,
      /** Show expand button to open lightbox */
      showExpand: true,
      /** Called on every slide change: fn(newIndex, oldIndex, instance) */
      onChange: null
    };

    /**
     * @param {HTMLElement|string} container – element or CSS selector
     * @param {Object}             [options] – override defaults
     */
    constructor (container, options = {}) {
      if (typeof container === 'string') {
        container = document.querySelector(container);
      }
      if (!container || container._ssInstance) return;

      this.container = container;
      this.options = Object.assign({}, GallerySlideshow.defaults,
        this._parseDataset(container.dataset), options);

      this._current = 0;
      this._autoTimer = null;
      this._animating = false;
      this._slides = [];
      this._indicators = [];
      this._lightbox = null;
      this._visibilityHandler = null;
      this._pageshowHandler = null;

      this._build();
      this._bindEvents();
      this._bindLifecycleRecovery();
      this._goTo(0, true);
      if (this.options.autoplay) this._startAuto();

      container._ssInstance = this;
    }

    /* ── Dataset parser ─────────────────────────────────────── */
    _parseDataset(ds) {
      const o = {};
      if (ds.effect) o.effect = ds.effect;
      if (ds.interval) o.interval = parseInt(ds.interval, 10);
      if (ds.autoplay !== undefined) o.autoplay = parseBool(ds.autoplay, true);
      if (ds.pauseOnHover !== undefined) o.pauseOnHover = parseBool(ds.pauseOnHover, true);
      if (ds.loop !== undefined) o.loop = parseBool(ds.loop, true);
      if (ds.showNav !== undefined) o.showNav = parseBool(ds.showNav, true);
      if (ds.showIndicators !== undefined) o.showIndicators = parseBool(ds.showIndicators, true);
      if (ds.keyboard !== undefined) o.keyboard = parseBool(ds.keyboard, true);
      if (ds.swipe !== undefined) o.swipe = parseBool(ds.swipe, true);
      if (ds.height) o.height = ds.height;
      if (ds.glow !== undefined) o.glow = parseBool(ds.glow, true);
      if (ds.lightbox !== undefined) o.lightbox = parseBool(ds.lightbox, true);
      if (ds.showExpand !== undefined) o.showExpand = parseBool(ds.showExpand, true);
      return o;
    }

    /* ── Build DOM ──────────────────────────────────────────── */
    _build() {
      const {container, options} = this;

      /* Validate effect */
      if (!GallerySlideshow.EFFECTS.includes(options.effect)) options.effect = 'fade';

      /* Classes */
      container.classList.add('now-slideshow', `now-slideshow--${options.effect}`);
      if (options.glow) container.classList.add('now-slideshow--glow');
      if (options.height) {
        if (options.height === 'auto') {
          container.style.height = '0px';
        } else {
          container.style.height = options.height;
        }
      }

      /* Collect slides */
      this._slides = Array.from(container.querySelectorAll('.now-slide'));
      /* Normalise class name */
      this._slides.forEach(s => s.classList.add('now-slide'));

      if (!this._slides.length) return;

      /* Nav buttons */
      if (options.showNav) {
        if (!container.querySelector('.now-slide-prev')) {
          container.appendChild(this._makeBtn('now-slide-prev', SVG_PREV, 'Previous'));
          container.appendChild(this._makeBtn('now-slide-next', SVG_NEXT, 'Next'));
        }
      }

      /* Expand button – opens lightbox at current slide */
      if (options.lightbox && options.showExpand) {
        if (!container.querySelector('.now-slide-expand')) {
          const expandBtn = this._makeBtn('now-slide-expand', SVG_EXPAND, 'Open fullscreen');
          expandBtn.addEventListener('click', () => this._openLightbox(this._current));
          container.appendChild(expandBtn);
        }
      }

      /* Thumbnails – wire legacy .gallery-thumb clicks */
      const thumbs = Array.from(container.querySelectorAll('.gallery-thumb'));
      thumbs.forEach((t, i) => t.addEventListener('click', () => this.goTo(i)));

      /* Dot indicators – appended after the container */
      if (options.showIndicators) {
        let wrap = container.parentElement
          ? container.parentElement.querySelector('.now-slide-indicators')
          : null;
        if (!wrap) {
          wrap = document.createElement('div');
          wrap.className = 'now-slide-indicators';
          container.insertAdjacentElement('afterend', wrap);
        }
        wrap.innerHTML = '';
        this._indicators = this._slides.map((_, i) => {
          const dot = document.createElement('button');
          dot.type = 'button';
          dot.className = 'now-slide-dot';
          dot.setAttribute('aria-label', `Slide ${i + 1}`);
          dot.addEventListener('click', () => this.goTo(i));
          wrap.appendChild(dot);
          return dot;
        });
      }

      /* Auto-height: resize listener */
      if (options.height === 'auto') {
        this._resizeHandler = () => this._autoSizeToSlide(this._current);
        window.addEventListener('resize', this._resizeHandler, {passive: true});
      }

      /* Lightbox */
      if (options.lightbox) this._buildLightbox();
    }

    _makeBtn(cls, svgHtml, label) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = cls;
      btn.setAttribute('aria-label', label);
      btn.innerHTML = svgHtml;
      return btn;
    }

    /* ── Lightbox ───────────────────────────────────────────── */
    _buildLightbox() {
      /* Reuse any existing now-lightbox in DOM, otherwise create one */
      let lb = document.querySelector('.now-lightbox[data-ss-lb]');
      if (!lb) {
        lb = document.createElement('div');
        lb.className = 'now-lightbox';
        lb.setAttribute('data-ss-lb', '');
        lb.setAttribute('role', 'dialog');
        lb.setAttribute('aria-modal', 'true');
        lb.innerHTML =
          `<button class="now-lightbox-close" aria-label="Close">${SVG_CLOSE}</button>` +
          `<button class="now-lightbox-prev"  aria-label="Previous">${SVG_PREV}</button>` +
          `<div class="now-lightbox-content">` +
          `<img class="now-lightbox-img" src="" alt="">` +
          `<div class="now-lightbox-caption"></div>` +
          `</div>` +
          `<button class="now-lightbox-next" aria-label="Next">${SVG_NEXT}</button>`;
        document.body.appendChild(lb);
      }

      const img = lb.querySelector('.now-lightbox-img');
      const caption = lb.querySelector('.now-lightbox-caption');
      const btnPrev = lb.querySelector('.now-lightbox-prev');
      const btnNext = lb.querySelector('.now-lightbox-next');
      const btnClose = lb.querySelector('.now-lightbox-close');
      let lbIdx = 0;

      const show = (i) => {
        const len = this._slides.length;
        lbIdx = ((i % len) + len) % len;
        const slide = this._slides[lbIdx];
        const imgEl = slide ? slide.querySelector('img') : null;
        const capEl = slide
          ? slide.querySelector('.now-slide-caption, .slide-caption, [class*="caption"]')
          : null;
        const newCap = capEl ? capEl.textContent.trim() : (imgEl ? imgEl.alt : '');
        // Fade out → swap src → fade in
        img.classList.add('lb-changing');
        setTimeout(() => {
          if (imgEl) {img.src = imgEl.src; img.alt = imgEl.alt || '';}
          caption.textContent = newCap;
          img.onload = () => img.classList.remove('lb-changing');
          if (img.complete) img.classList.remove('lb-changing');
        }, 220);
      };
      const open = (i) => {show(i); lb.classList.add('is-open'); document.body.style.overflow = 'hidden';};
      const close = () => {lb.classList.remove('is-open'); document.body.style.overflow = '';};

      btnClose && btnClose.addEventListener('click', close);
      btnPrev && btnPrev.addEventListener('click', () => show(lbIdx - 1));
      btnNext && btnNext.addEventListener('click', () => show(lbIdx + 1));
      lb.addEventListener('click', (e) => {if (e.target === lb) close();});
      document.addEventListener('keydown', (e) => {
        if (!lb.classList.contains('is-open')) return;
        if (e.key === 'Escape') close();
        if (e.key === 'ArrowLeft') show(lbIdx - 1);
        if (e.key === 'ArrowRight') show(lbIdx + 1);
      });

      /* Wire click on each slide image */
      this._slides.forEach((slide, i) => {
        const imgEl = slide.querySelector('img');
        if (imgEl) {imgEl.style.cursor = 'zoom-in'; imgEl.addEventListener('click', () => open(i));}
      });

      this._lightbox = {open, close};
    }

    _openLightbox(idx) {
      this._lightbox && this._lightbox.open(idx);
    }

    /* ── Event bindings ─────────────────────────────────────── */
    _bindEvents() {
      const {container, options} = this;

      /* Buttons built by _build() */
      const prev = container.querySelector('.now-slide-prev');
      const next = container.querySelector('.now-slide-next');
      prev && prev.addEventListener('click', () => this.prev());
      next && next.addEventListener('click', () => this.next());

      /* Hover pause */
      if (options.pauseOnHover) {
        container.addEventListener('mouseenter', () => this.pause());
        container.addEventListener('mouseleave', () => {if (options.autoplay) this.play();});
      }

      /* Keyboard */
      if (options.keyboard) {
        this._kbHandler = (e) => {
          if (e.key === 'ArrowLeft') this.prev();
          if (e.key === 'ArrowRight') this.next();
        };
        document.addEventListener('keydown', this._kbHandler);
      }

      /* Touch swipe */
      if (options.swipe) this._initSwipe();
    }

    _initSwipe() {
      let sx = 0, sy = 0;
      this.container.addEventListener('touchstart', (e) => {
        sx = e.changedTouches[0].clientX;
        sy = e.changedTouches[0].clientY;
      }, {passive: true});
      this.container.addEventListener('touchend', (e) => {
        const dx = sx - e.changedTouches[0].clientX;
        const dy = sy - e.changedTouches[0].clientY;
        if (Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 40) {
          dx > 0 ? this.next() : this.prev();
        }
      }, {passive: true});
    }

    _bindLifecycleRecovery() {
      this._visibilityHandler = () => {
        if (document.visibilityState === 'visible') {
          this._recoverAfterVisibility();
        }
      };

      this._pageshowHandler = () => {
        this._recoverAfterVisibility();
      };

      document.addEventListener('visibilitychange', this._visibilityHandler);
      window.addEventListener('pageshow', this._pageshowHandler);
    }

    _recoverAfterVisibility() {
      if (!this.container || !document.contains(this.container)) return;

      if (this.options.height === 'auto') {
        this._autoSizeToSlide(this._current);
      }

      if (this.options.autoplay) {
        const isHovered = this.options.pauseOnHover && this.container.matches(':hover');
        if (!isHovered) {
          this.play();
        }
      }
    }

    /* ── Core transition ────────────────────────────────────── */
    _goTo(index, instant = false) {
      const {_slides, _indicators, options} = this;
      if (!_slides.length) return;

      const len = _slides.length;
      const newIndex = options.loop
        ? ((index % len) + len) % len
        : Math.max(0, Math.min(len - 1, index));

      if (newIndex === this._current && !instant) return;
      if (this._animating && !instant) return;

      const oldIndex = this._current;
      this._current = newIndex;

      if (!instant) {
        this._animating = true;
        const leaving = _slides[oldIndex];
        leaving.classList.remove('is-active');
        leaving.classList.add('is-leaving');
        setTimeout(() => {
          leaving.classList.remove('is-leaving');
          this._animating = false;
        }, 900);
      } else {
        _slides.forEach(s => s.classList.remove('is-active', 'is-leaving'));
      }

      _slides[newIndex].classList.add('is-active');

      /* Dot indicators */
      _indicators.forEach((dot, i) => dot.classList.toggle('is-active', i === newIndex));

      /* Counter */
      const curEl = document.getElementById('currentSlide');
      if (curEl) curEl.textContent = newIndex + 1;

      /* Thumb highlight */
      const thumbs = this.container.querySelectorAll('.gallery-thumb');
      thumbs.forEach((t, i) => t.classList.toggle('active', i === newIndex));
      const activeThumb = thumbs[newIndex];
      if (activeThumb) activeThumb.scrollIntoView({behavior: 'smooth', block: 'nearest', inline: 'center'});

      if (!instant && options.onChange) options.onChange(newIndex, oldIndex, this);

      if (!instant && window.EventManager) {
        EventManager.emit('slideshow:change', {index: newIndex, oldIndex, instance: this});
      }

      /* Auto-height: resize container to match the new slide's image */
      if (this.options.height === 'auto') {
        this._autoSizeToSlide(newIndex);
      }
    }

    /* ── Public API ─────────────────────────────────────────── */

    /** Go to next slide */
    next() {this._goTo(this._current + 1); this._restartAuto();}

    /** Go to previous slide */
    prev() {this._goTo(this._current - 1); this._restartAuto();}

    /**
     * Jump to a specific slide (0-based).
     * @param {number} index
     */
    goTo(index) {this._goTo(index); this._restartAuto();}

    /** Start auto-play */
    play() {this._stopAuto(); this._startAuto();}

    /** Pause auto-play */
    pause() {this._stopAuto();}

    /**
     * Switch transition effect at runtime.
     * @param {'fade'|'slide'|'zoom'|'crossfade'|'flip'|'cube'} effect
     */
    setEffect(effect) {
      if (!GallerySlideshow.EFFECTS.includes(effect)) return;
      this.container.classList.remove(`now-slideshow--${this.options.effect}`);
      this.options.effect = effect;
      this.container.classList.add(`now-slideshow--${effect}`);
    }

    /**
     * Resize the container to match the natural aspect ratio of the image
     * in the given slide, scaled to the container's current width.
     * @param {number} idx
     */
    _autoSizeToSlide(idx) {
      const slide = this._slides[idx];
      if (!slide) return;
      const imgEl = slide.querySelector('img');
      if (!imgEl) return;
      const applyHeight = () => {
        const w = this.container.offsetWidth || this.container.getBoundingClientRect().width;
        if (!w) return;
        const natW = imgEl.naturalWidth;
        const natH = imgEl.naturalHeight;
        if (!natW || !natH) return;
        this.container.style.height = Math.round(w * natH / natW) + 'px';
      };
      if (imgEl.complete && imgEl.naturalWidth) {
        applyHeight();
      } else {
        imgEl.addEventListener('load', applyHeight, {once: true});
      }
    }

    /** Total slide count */
    get length() {return this._slides.length;}

    /** Current 0-based index */
    get current() {return this._current;}

    /** Tear down the instance */
    destroy() {
      this._stopAuto();
      if (this._kbHandler) document.removeEventListener('keydown', this._kbHandler);
      if (this._resizeHandler) window.removeEventListener('resize', this._resizeHandler);
      if (this._visibilityHandler) document.removeEventListener('visibilitychange', this._visibilityHandler);
      if (this._pageshowHandler) window.removeEventListener('pageshow', this._pageshowHandler);
      this.container.classList.remove(
        'now-slideshow', `now-slideshow--${this.options.effect}`, 'now-slideshow--glow'
      );
      this._slides.forEach(s => s.classList.remove('now-slide', 'is-active', 'is-leaving'));
      delete this.container._ssInstance;
    }

    /* ── Auto-play ──────────────────────────────────────────── */
    _startAuto() {
      if (this._slides.length <= 1) return;
      this._autoTimer = setInterval(() => this._goTo(this._current + 1), this.options.interval);
    }
    _stopAuto() {clearInterval(this._autoTimer); this._autoTimer = null;}
    _restartAuto() {if (this.options.autoplay) {this._stopAuto(); this._startAuto();} }
  }

  /* ============================================================
     Gallery Albums Widget (frontend listing)
     ============================================================ */
  function initGalleryWidget() {
    document.querySelectorAll('[data-gallery-widget]').forEach(widget => {
      const limit = widget.dataset.limit || 6;
      const url = (window.WEB_URL || '/') + 'api/gallery/lists?limit=' + limit;
      fetch(url)
        .then(r => r.json())
        .then(res => {
          const albums = res.data || res || [];
          if (!albums.length) return;
          widget.classList.add('now-gallery-widget');
          widget.innerHTML = albums.map(a =>
            `<a class="now-gallery-card" href="${a.url}">` +
            `<img class="now-gallery-card-img" src="${a.cover_url}" alt="${a.topic}" loading="lazy">` +
            `<div class="now-gallery-card-info">${a.topic}</div></a>`
          ).join('');
        })
        .catch(() => {});
    });
  }

  /* ============================================================
     Auto-init
     ============================================================ */
  function autoInit() {
    /* [data-slideshow] */
    document.querySelectorAll('[data-slideshow]').forEach(el => {
      if (!el._ssInstance) new GallerySlideshow(el);
    });

    /* Albums widget */
    initGalleryWidget();
  }

  /* ============================================================
     Bootstrap
     ============================================================ */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', autoInit);
  } else {
    autoInit();
  }

  /* Now.js SPA lifecycle */
  if (window.EventManager) {
    EventManager.on('page:loaded', autoInit);
    EventManager.on('modal:shown', autoInit);
  } else {
    document.addEventListener('page:loaded', autoInit);
    document.addEventListener('modal:shown', autoInit);
  }

  /* ── Expose globally ────────────────────────────────────── */
  global.GallerySlideshow = GallerySlideshow;

}(window));
