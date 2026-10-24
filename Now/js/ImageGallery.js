/**
 * ImageGallery Component
 *
 * Inline image gallery with a hero image and optional thumbnail strip.
 * Auto-initialises when added to the DOM via ComponentManager (data-component="image-gallery").
 * Compatible with TemplateManager data-for loops, FormManager loads, ApiComponent loads,
 * and server-generated HTML injected via Modal.setContent — zero custom JS required.
 *
 * Declarative usage:
 *   <div data-component="image-gallery"
 *        data-effect="fade"
 *        data-aspect="auto"
 *        data-thumbnails-position="bottom"
 *        data-show-thumbnails="true"
 *        data-enable-zoom="true">
 *     <img src="thumb.jpg" data-src="full.jpg" alt="Caption" data-caption="Caption">
 *     <img src="thumb2.jpg" data-src="full2.jpg" alt="Photo 2">
 *   </div>
 *
 * API-driven usage:
 *   <div data-component="image-gallery" data-api="api/parts/images?id=5"></div>
 *
 * The API endpoint should return:
 *   [ "url1.jpg", "url2.jpg" ]
 *   or
 *   [ { url, thumb, alt, caption }, ... ]
 *   or
 *   { data: [...] } / { items: [...] }
 *
 * Programmatic usage (for runtime construction):
 *   const gallery = ImageGallery.create(element, { images: [...], effect: 'slide' });
 *   gallery.goTo(1);
 *   gallery.update(newItems);
 *   gallery.destroy();
 *
 * Data attributes:
 *   data-effect              fade | slide | none        (default: fade)
 *   data-aspect              square | wide | tall | auto (default: auto)
 *   data-show-thumbnails     true | false               (default: true if > 1 images)
 *   data-thumbnails-position bottom | left | right      (default: bottom)
 *   data-enable-zoom         true | false               (default: true)
 *   data-empty-icon          CSS icon class             (default: icon-picture)
 *   data-api                 API endpoint URL           (optional)
 *   data-api-method          GET | POST                 (default: GET)
 *
 * @author Goragod Wiriya
 * @version 1.0
 */

/* ── Default options ──────────────────────────────────────── */

const _IG_DEFAULTS = {
    effect: 'fade',
    aspect: 'auto',
    showThumbnails: true,
    thumbnailsPosition: 'bottom',
    enableZoom: true,
    emptyIcon: 'icon-picture',
};

/* ── Helpers ──────────────────────────────────────────────── */

/**
 * Collect media items from <img> direct children.
 * Reads data-src for full-size URL, src for thumbnail.
 * @param {HTMLElement} el
 * @returns {Array<{url:string, thumb:string, alt:string, caption:string}>}
 */
function _igCollect(el) {
    const items = [];
    el.querySelectorAll(':scope > img').forEach(img => {
        // img.src gives absolute URL; dataset.src may be relative — both work for display
        const url = img.dataset.src || img.getAttribute('src') || '';
        const thumb = img.getAttribute('src') || url;
        if (!url) return;
        items.push({
            url,
            thumb,
            alt: img.alt || img.dataset.alt || '',
            caption: img.dataset.caption || img.alt || '',
        });
    });
    return items;
}

/**
 * Parse component options from ComponentManager props
 * (props keys are already hyphen-separated strings, booleans already coerced by extractProps)
 * @param {Object} props
 * @returns {Object}
 */
function _igParseOpts(props) {
    return {
        effect: props.effect || _IG_DEFAULTS.effect,
        aspect: props.aspect || _IG_DEFAULTS.aspect,
        showThumbnails: props['show-thumbnails'] !== false,
        thumbnailsPosition: props['thumbnails-position'] || _IG_DEFAULTS.thumbnailsPosition,
        enableZoom: props['enable-zoom'] !== false,
        emptyIcon: props['empty-icon'] || _IG_DEFAULTS.emptyIcon,
        api: props.api || null,
        apiMethod: props['api-method'] || 'GET',
    };
}

/**
 * Build / rebuild gallery DOM inside the component element.
 * Replaces all children with the rendered gallery UI.
 * @param {HTMLElement} el
 * @param {Array} items
 * @param {Object} opts
 */
function _igBuild(el, items, opts) {
    // Sync modifier classes
    el.classList.add('ig');
    ['ig--bottom', 'ig--left', 'ig--right', 'ig--top',
        'ig--fade', 'ig--slide', 'ig--none',
        'ig--square', 'ig--wide', 'ig--tall', 'ig--auto',
        'ig-loading', 'ig-empty'].forEach(c => el.classList.remove(c));
    el.classList.add(`ig--${opts.thumbnailsPosition}`);
    el.classList.add(`ig--${opts.effect}`);
    el.classList.add(`ig--${opts.aspect}`);

    if (items.length === 0) {
        el.classList.add('ig-empty');
        el.innerHTML = `<div class="ig-hero ig-hero--empty"><span class="${Utils.string.escape(opts.emptyIcon)}" aria-hidden="true"></span></div>`;
        return;
    }

    const first = items[0];
    const showThumbs = opts.showThumbnails && items.length > 1;

    // Zoom button — only if MediaViewer is available
    const zoomBtn = (opts.enableZoom && typeof window !== 'undefined' && window.MediaViewer)
        ? `<button type="button" class="ig-zoom-btn icon-zoom-in" aria-label="${Utils.string.escape(window.Now?.translate?.('View fullscreen') || 'View fullscreen')}"></button>`
        : '';

    const heroHtml = `<div class="ig-hero" role="img" aria-label="${Utils.string.escape(first.alt || first.caption)}">
  <img class="ig-main-img" src="${Utils.string.escape(first.url)}" alt="${Utils.string.escape(first.alt)}" loading="lazy" data-current-index="0">
  ${zoomBtn}
</div>
<div class="ig-caption">${Utils.string.escape(first.caption)}</div>`;

    let thumbsHtml = '';
    if (showThumbs) {
        const thumbItems = items.map((item, i) => {
            const isActive = i === 0;
            return `<button type="button"
  class="ig-thumb${isActive ? ' is-active' : ''}"
  data-ig-index="${i}"
  aria-label="${Utils.string.escape(item.caption || item.alt || `Image ${i + 1}`)}"
  aria-pressed="${isActive ? 'true' : 'false'}"><img src="${Utils.string.escape(item.thumb)}" alt="${Utils.string.escape(item.alt)}" loading="lazy"></button>`;
        }).join('');
        const posClass = `ig-thumbs--${Utils.string.escape(opts.thumbnailsPosition)}`;
        thumbsHtml = `<div class="ig-thumbs ${posClass}" role="group" aria-label="Thumbnails">${thumbItems}</div>`;
    }

    el.innerHTML = heroHtml + thumbsHtml;
}

/**
 * Swap the displayed image with optional transition effect.
 * @param {HTMLElement} el       - root gallery element
 * @param {Array}       items
 * @param {number}      index    - target item index
 * @param {string}      effect   - 'fade' | 'slide' | 'none'
 * @param {Object}      stateRef - mutable state reference {currentIndex}
 */
function _igGoTo(el, items, index, effect, stateRef) {
    if (index < 0 || index >= items.length) return;

    const img = el.querySelector('.ig-main-img');
    const caption = el.querySelector('.ig-caption');
    const item = items[index];
    if (!img || !item) return;

    const prevIndex = stateRef.currentIndex;
    stateRef.currentIndex = index;

    // Update thumb active states
    el.querySelectorAll('.ig-thumb[data-ig-index]').forEach(btn => {
        const active = parseInt(btn.dataset.igIndex, 10) === index;
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });

    const applyContent = () => {
        img.src = item.url;
        img.alt = item.alt || '';
        img.dataset.currentIndex = String(index);
        if (caption) caption.textContent = item.caption || '';
    };

    if (effect === 'none' || img.src === item.url) {
        applyContent();
        return;
    }

    if (effect === 'slide') {
        const dir = index > prevIndex ? 1 : -1;
        img.style.transition = 'transform 0.28s ease, opacity 0.28s ease';
        img.style.transform = `translateX(${dir * -32}px)`;
        img.style.opacity = '0';
        const t = setTimeout(() => {
            applyContent();
            img.style.transition = 'none';
            img.style.transform = `translateX(${dir * 32}px)`;
            img.style.opacity = '0';
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    img.style.transition = 'transform 0.28s ease, opacity 0.28s ease';
                    img.style.transform = 'translateX(0)';
                    img.style.opacity = '1';
                });
            });
        }, 290);
        el._igSlideTimer = t;
        return;
    }

    // fade (default)
    img.style.transition = 'opacity 0.22s ease';
    img.style.opacity = '0';
    const t = setTimeout(() => {
        applyContent();
        img.style.opacity = '1';
    }, 230);
    el._igFadeTimer = t;
}

/**
 * Open MediaViewer fullscreen lightbox.
 * Lazily creates one instance per gallery element.
 * @param {HTMLElement} el
 * @param {Array}       items
 * @param {number}      startIndex
 */
function _igOpenViewer(el, items, startIndex) {
    if (!window.MediaViewer) return;
    if (!el._igViewer) {
        el._igViewer = new MediaViewer({
            showThumbnails: true,
            enableZoom: true,
            loop: true,
        });
    }
    // MediaViewer accepts {url, caption} items
    const viewerItems = items.map(i => ({
        type: 'image',
        url: i.url,
        caption: i.caption || i.alt || '',
        thumbnail: i.thumb,
    }));
    el._igViewer.show(viewerItems, startIndex);
}

/**
 * Bind all interaction events to the gallery element.
 * Stores a cleanup function at el._igCleanup.
 * @param {HTMLElement} el
 * @param {Object}      stateRef  - {items, currentIndex}
 * @param {Object}      opts
 */
function _igBind(el, stateRef, opts) {
    if (el._igCleanup) el._igCleanup();

    const go = (index) => _igGoTo(el, stateRef.items, index, opts.effect, stateRef);

    // Thumbnail click
    const onThumbClick = (e) => {
        const btn = e.target.closest('.ig-thumb[data-ig-index]');
        if (!btn || !el.contains(btn)) return;
        go(parseInt(btn.dataset.igIndex, 10));
    };

    // Hero click → zoom (but not on the zoom button itself)
    const onHeroClick = (e) => {
        if (!opts.enableZoom || !window.MediaViewer) return;
        const hero = e.target.closest('.ig-hero');
        if (!hero || !el.contains(hero) || e.target.closest('.ig-zoom-btn')) return;
        _igOpenViewer(el, stateRef.items, stateRef.currentIndex);
    };

    // Zoom button click
    const onZoomClick = (e) => {
        const btn = e.target.closest('.ig-zoom-btn');
        if (!btn || !el.contains(btn)) return;
        _igOpenViewer(el, stateRef.items, stateRef.currentIndex);
    };

    // Keyboard nav (←/→) when element or child is focused
    const onKeydown = (e) => {
        if (!el.contains(document.activeElement) && document.activeElement !== el) return;
        if (e.key === 'ArrowLeft') {e.preventDefault(); go(stateRef.currentIndex - 1);}
        if (e.key === 'ArrowRight') {e.preventDefault(); go(stateRef.currentIndex + 1);}
    };

    // Touch swipe
    let _touchX = 0;
    const onTouchStart = (e) => {_touchX = e.touches[0].clientX;};
    const onTouchEnd = (e) => {
        const dx = _touchX - e.changedTouches[0].clientX;
        if (Math.abs(dx) > 44) go(stateRef.currentIndex + (dx > 0 ? 1 : -1));
    };

    el.addEventListener('click', onThumbClick);
    el.addEventListener('click', onHeroClick);
    el.addEventListener('click', onZoomClick);
    el.addEventListener('keydown', onKeydown);
    el.addEventListener('touchstart', onTouchStart, {passive: true});
    el.addEventListener('touchend', onTouchEnd, {passive: true});

    el._igCleanup = () => {
        el.removeEventListener('click', onThumbClick);
        el.removeEventListener('click', onHeroClick);
        el.removeEventListener('click', onZoomClick);
        el.removeEventListener('keydown', onKeydown);
        el.removeEventListener('touchstart', onTouchStart);
        el.removeEventListener('touchend', onTouchEnd);
        clearTimeout(el._igFadeTimer);
        clearTimeout(el._igSlideTimer);
        delete el._igCleanup;
        delete el._igFadeTimer;
        delete el._igSlideTimer;
    };
}

/**
 * Load items from a REST API endpoint.
 * @param {HTMLElement} el
 * @param {string}      apiUrl
 * @param {Object}      stateRef  - mutable {items, currentIndex}
 * @param {Object}      opts
 */
async function _igLoadApi(el, apiUrl, stateRef, opts) {
    el.classList.add('ig-loading');
    el.innerHTML = `<div class="ig-hero ig-hero--empty ig-loading-indicator"><span class="${Utils.string.escape(opts.emptyIcon)}" aria-hidden="true"></span></div>`;

    try {
        const method = opts.apiMethod;
        const headers = {'Content-Type': 'application/json'};

        if (window.AuthManager?.getToken) {
            const token = AuthManager.getToken();
            if (token) headers['Authorization'] = `Bearer ${token}`;
        }

        const reqOpts = window.Now?.applyRequestLanguage
            ? Now.applyRequestLanguage({method, headers})
            : {method, headers};

        const response = await fetch(apiUrl, reqOpts);
        if (!response.ok) throw new Error(`HTTP ${response.status}`);

        const data = await response.json();

        const raw = Array.isArray(data) ? data
            : Array.isArray(data.items) ? data.items
                : Array.isArray(data.data) ? data.data
                    : [];

        const items = raw.map(item => {
            if (typeof item === 'string') {
                return {url: item, thumb: item, alt: '', caption: ''};
            }
            const url = item.url || item.src || item.path || '';
            return {
                url,
                thumb: item.thumbnail || item.thumb || url,
                alt: item.alt || item.name || '',
                caption: item.caption || item.title || item.alt || '',
            };
        }).filter(i => !!i.url);

        stateRef.items = items;
        stateRef.currentIndex = 0;

        el.classList.remove('ig-loading');
        _igBuild(el, items, opts);
        _igBind(el, stateRef, opts);

    } catch (err) {
        el.classList.remove('ig-loading');
        el.classList.add('ig-empty');
        el.innerHTML = `<div class="ig-hero ig-hero--empty"><span class="${Utils.string.escape(opts.emptyIcon)}" aria-hidden="true"></span></div>`;
        if (window.console) console.error('[ImageGallery] API load failed:', err);
    }
}

/* ── ComponentManager registration ───────────────────────── */

if (window.ComponentManager) {
    ComponentManager.define('image-gallery', {
        /*
         * validElement: () => true tells ComponentManager NOT to replace the element's
         * innerHTML via template processing. The mounted() hook builds the UI instead,
         * reading the original <img> children before rebuilding.
         */
        validElement: () => true,

        mounted() {
            const el = this.element;
            const opts = _igParseOpts(this.props);

            if (opts.api) {
                this.state.items = [];
                this.state.currentIndex = 0;
                _igLoadApi(el, opts.api, this.state, opts);
                return;
            }

            // Collect items from <img> children (resolved by TemplateManager before mount)
            const items = _igCollect(el);
            this.state.items = items;
            this.state.currentIndex = 0;

            _igBuild(el, items, opts);
            _igBind(el, this.state, opts);
        },

        destroyed() {
            if (this.element._igCleanup) {
                this.element._igCleanup();
            }
            if (this.element._igViewer) {
                try {this.element._igViewer.hide();} catch (_) {}
                delete this.element._igViewer;
            }
        },
    });
}

/* ── Public / programmatic API ────────────────────────────── */

const ImageGallery = {
    /**
     * Create or replace a gallery on an element programmatically.
     *
     * @param {HTMLElement} element
     * @param {Object}      options
     * @param {Array}       [options.images]            - Override items (skips DOM collection)
     * @param {string}      [options.effect]            - 'fade' | 'slide' | 'none'
     * @param {string}      [options.aspect]            - 'square' | 'wide' | 'tall' | 'auto'
     * @param {boolean}     [options.showThumbnails]
     * @param {string}      [options.thumbnailsPosition]
     * @param {boolean}     [options.enableZoom]
     * @param {string}      [options.emptyIcon]
     * @returns {{goTo, update, destroy}}
     */
    create(element, options = {}) {
        const opts = Object.assign({}, _IG_DEFAULTS, options, {
            apiMethod: options.apiMethod || 'GET',
            api: options.api || null,
        });

        const stateRef = {
            items: options.images || _igCollect(element),
            currentIndex: 0,
        };

        _igBuild(element, stateRef.items, opts);
        _igBind(element, stateRef, opts);
        element._igInstance = stateRef;

        return {
            goTo(index) {
                _igGoTo(element, stateRef.items, index, opts.effect, stateRef);
            },
            update(newItems) {
                stateRef.items = newItems;
                stateRef.currentIndex = 0;
                _igBuild(element, newItems, opts);
                _igBind(element, stateRef, opts);
            },
            destroy() {
                if (element._igCleanup) element._igCleanup();
                if (element._igViewer) {
                    try {element._igViewer.hide();} catch (_) {}
                    delete element._igViewer;
                }
                delete element._igInstance;
            },
        };
    },
};

window.ImageGallery = ImageGallery;
