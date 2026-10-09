/**
 * PageNavigator - loads server-rendered pages into the main element without a full reload
 *
 * For sites whose pages are rendered on the server (a CMS front end) rather than from
 * client templates. A click on an internal link fetches the same URL with the partial-page
 * header; the server answers with JSON holding only what changes, and the navigator swaps
 * the inner HTML of the main element (Now.config.mainSelector), updates the title and the
 * page-specific head tags, and pushes the URL. Opening a URL directly still gets the full
 * server-rendered page, so crawlers and first visits see ordinary pages.
 *
 * Response contract - JSON, and the response must carry the partial-page header with "1":
 *   content {string}   HTML that replaces the inner HTML of the main element
 *   title   {string}   Document title
 *   head    {string}   Page-specific head tags, each marked data-page-meta; they replace the
 *                      current [data-page-meta] tags (description, canonical, og:*, JSON-LD ...)
 *   styles  {string[]} Stylesheet URLs the page needs; added once, never removed
 *   reload  {boolean}  True when the server cannot answer partially; the page loads normally
 * Anything else in the payload is passed through in the page:loaded event.
 *
 * Anything unexpected - a network error, a non-JSON answer, a missing header, reload, a
 * leave guard saying no - falls back to a normal page load, so a link never does nothing.
 *
 * Links opt out with data-navigate="false" on the link or an ancestor; target, download,
 * rel="external", modifier keys, other origins, paths outside Now.basePath, file links and
 * the configured exclude rules are left to the browser. GET forms (a search box) load the
 * same way; POST forms and forms handled by FormManager (data-form) are not touched.
 *
 * While a page loads, a bar along the top of the window runs to 80% and stops there;
 * once the page is in, it fills and fades (styles: Now/css/navigator.css).
 */
const PageNavigator = {
  DEFAULT_CONFIG: {
    enabled: false,
    // Request header asking for the partial page; the server echoes it on a partial answer
    header: 'X-Partial-Page',
    // Paths (relative to Now.basePath) loaded normally: string prefixes or RegExps
    exclude: [],
    scrollToTop: true,
    focusContent: true,
    // Progress bar along the top of the window while a page loads
    progress: true,
    // Cross-fade between pages with the View Transitions API, where the browser has it
    transition: false
  },

  config: {},

  state: {
    initialized: false,
    controller: null,
    // Address (without #fragment) of the page currently shown
    url: null,
    loadedScripts: new Set(),
    // A normal page load was started - the loading state stays until the page goes
    leaving: false,
    leaveTimer: null
  },

  guards: [],

  progressBar: null,

  /**
   * Starts intercepting links when enabled.
   *
   * @param {Object} [options] - Overrides for DEFAULT_CONFIG
   * @returns {Object} PageNavigator
   */
  init(options = {}) {
    this.config = {...this.DEFAULT_CONFIG, ...(options || {})};
    if (this.state.initialized || !this.config.enabled || !window.history?.pushState || !window.fetch) {
      return this;
    }

    this.state.url = this.withoutHash(location.href);
    document.querySelectorAll('script[src]').forEach(script => this.state.loadedScripts.add(script.src));

    // The page arrives after an async fetch, so the browser's own restoration would
    // scroll the outgoing page; positions are kept in history.state instead
    if ('scrollRestoration' in history) {
      history.scrollRestoration = 'manual';
    }
    history.replaceState({...(history.state || {}), pageNavigator: true}, '', location.href);
    this.restoreScrollAfterReload();

    document.addEventListener('click', event => this.handleClick(event));
    document.addEventListener('submit', event => this.handleSubmit(event));
    window.addEventListener('popstate', event => this.handlePopState(event));
    window.addEventListener('pagehide', () => this.saveScroll());

    this.state.initialized = true;
    return this;
  },

  /**
   * Registers a guard asked before leaving the current page. Returning false (or a
   * promise of false) makes that navigation a normal page load instead, so the
   * browser's beforeunload handling applies (e.g. an editor with unsaved work).
   *
   * @param {Function} guard - Called with the destination URL
   * @returns {Function} Unregister function
   */
  beforeLeave(guard) {
    if (typeof guard !== 'function') {
      throw new Error('Guard must be a function');
    }
    this.guards.push(guard);
    return () => {
      const index = this.guards.indexOf(guard);
      if (index > -1) {
        this.guards.splice(index, 1);
      }
    };
  },

  /**
   * @param {MouseEvent} event
   */
  handleClick(event) {
    const link = event.target.closest?.('a[href]');
    if (!link || !this.shouldIntercept(event, link)) {
      return;
    }
    event.preventDefault();
    this.navigate(link.href);
  },

  /**
   * A GET form (a search box) loads its result page the same way as a link.
   *
   * @param {SubmitEvent} event
   */
  handleSubmit(event) {
    const form = event.target;
    if (!this.state.initialized || event.defaultPrevented || !(form instanceof HTMLFormElement)
      || (form.getAttribute('method') || 'get').toLowerCase() !== 'get'
      || form.hasAttribute('data-form') || form.closest('[data-navigate="false"]')) {
      return;
    }
    const target = form.getAttribute('target');
    if (target && target !== '_self') {
      return;
    }
    // A GET submission replaces the query of the action with the form's fields
    const url = new URL(form.action, location.href);
    url.search = new URLSearchParams(new FormData(form, event.submitter || null)).toString();
    url.hash = '';
    if (!this.isNavigable(url.href)) {
      return;
    }
    event.preventDefault();
    this.navigate(url.href);
  },

  /**
   * @param {MouseEvent} event
   * @param {HTMLAnchorElement} link
   * @returns {boolean}
   */
  shouldIntercept(event, link) {
    if (!this.state.initialized || event.defaultPrevented || event.button !== 0
      || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return false;
    }
    if (link.isContentEditable || link.hasAttribute('download') || link.closest('[data-navigate="false"]')) {
      return false;
    }
    const target = link.getAttribute('target');
    if (target && target !== '_self') {
      return false;
    }
    if ((link.getAttribute('rel') || '').split(/\s+/).includes('external')) {
      return false;
    }

    return this.isNavigable(link.href);
  },

  /**
   * Whether a URL is a page of this site that can be loaded partially.
   *
   * @param {string} href
   * @returns {boolean}
   */
  isNavigable(href) {
    let url;
    try {
      url = new URL(href, location.href);
    } catch (e) {
      return false;
    }
    if (url.origin !== location.origin || !/^https?:$/.test(url.protocol)) {
      return false;
    }
    // Same page, only the #fragment differs - the browser scrolls to it
    if (url.hash && url.pathname === location.pathname && url.search === location.search) {
      return false;
    }
    const base = (window.Now?.basePath || '/').replace(/\/?$/, '/');
    const pathname = url.pathname === base.slice(0, -1) ? base : url.pathname;
    if (!pathname.startsWith(base)) {
      return false;
    }
    const path = pathname.slice(base.length);
    // Files (pdf, zip, images ...) are downloads or shown by the browser, not pages
    const extension = path.match(/\.([a-z0-9]+)$/i);
    if (extension && !['php', 'html', 'htm'].includes(extension[1].toLowerCase())) {
      return false;
    }

    return !this.config.exclude.some(rule => rule instanceof RegExp ? rule.test(path) : path.startsWith(rule));
  },

  /**
   * Loads a page partially; any failure becomes a normal page load.
   *
   * @param {string} url - Destination
   * @param {Object} [options]
   * @param {boolean} [options.fromHistory] - Back/Forward: the address is already the destination
   * @param {number} [options.scrollY] - Position to restore (Back/Forward)
   * @returns {Promise<boolean>} True when the page was swapped in
   */
  async navigate(url, options = {}) {
    const target = new URL(url, location.href);
    const fromHistory = options.fromHistory === true;
    let pushed = false;

    if (!(await this.canLeave(target.href))) {
      this.fullLoad(target.href, fromHistory);
      return false;
    }

    // Still here after a normal load was started (it was a download): this one takes over
    clearTimeout(this.state.leaveTimer);
    this.state.leaving = false;
    this.state.controller?.abort();
    const controller = new AbortController();
    this.state.controller = controller;
    this.setLoading(true);

    try {
      const response = await fetch(target.href, {
        headers: {[this.config.header]: '1', Accept: 'application/json'},
        credentials: 'same-origin',
        signal: controller.signal
      });
      if (response.headers.get(this.config.header) !== '1') {
        // Not a page (a download, another application): stop reading it, the browser loads it
        controller.abort();
        throw new Error('Not a partial page response');
      }
      const data = await response.json();
      if (!data || data.reload || typeof data.content !== 'string') {
        throw new Error('Server asked for a full page load');
      }

      // A redirect the server followed (e.g. to a login page) is where the reader is now;
      // fetch drops the #fragment, so it is carried over
      const destination = new URL(response.redirected ? response.url : target.href);
      destination.hash = target.hash;
      if (fromHistory) {
        if (response.redirected) {
          history.replaceState({...(history.state || {}), pageNavigator: true}, '', destination.href);
        }
      } else {
        this.saveScroll();
        history.pushState({pageNavigator: true, scrollY: 0}, '', destination.href);
        pushed = true;
      }

      await this.apply(data, destination, {status: response.status, fromHistory, scrollY: options.scrollY});
      return true;
    } catch (error) {
      if (error.name === 'AbortError') {
        return false;
      }
      this.fullLoad(target.href, fromHistory || pushed);
      return false;
    } finally {
      if (this.state.controller === controller) {
        this.state.controller = null;
        if (!this.state.leaving) {
          this.setLoading(false);
        }
      }
    }
  },

  /**
   * Puts a partial page response on screen.
   *
   * @param {Object} data - Response payload
   * @param {URL} url - Address now shown
   * @param {Object} info - {status, fromHistory, scrollY}
   */
  async apply(data, url, info) {
    const previous = this.state.url;
    this.state.url = this.withoutHash(url.href);

    const update = async () => {
      if (typeof data.title === 'string') {
        document.title = data.title;
      }
      this.replaceHead(data.head);
      this.ensureStyles(data.styles);
      // Same swap the client-side router uses: destroys the components, elements and
      // forms of the outgoing page, then initializes the incoming one
      await RouterManager.render(data.content);
      this.position(url, info);
    };
    if (this.config.transition && typeof document.startViewTransition === 'function'
      && !window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
      await document.startViewTransition(update).updateCallbackDone;
    } else {
      await update();
    }

    const main = document.querySelector(Now.config.mainSelector);
    await this.runScripts(main);

    if (!info.fromHistory && this.config.focusContent && main) {
      // Screen readers continue from the new content, not from the link clicked
      if (!main.hasAttribute('tabindex')) {
        main.setAttribute('tabindex', '-1');
      }
      main.focus({preventScroll: true});
    }

    // The event the client-side router emits - menus, calendars and other components
    // already refresh on it. scroll: false keeps ScrollManager from moving the page
    // positioned above
    await EventManager.emit('route:changed', {
      path: url.pathname,
      params: {},
      query: Object.fromEntries(url.searchParams),
      hash: url.hash.slice(1),
      route: null,
      previous,
      isNotFoundPage: info.status === 404,
      scroll: false
    });
    const detail = {url: url.href, previous, status: info.status, data};
    await EventManager.emit('page:loaded', detail);
    // For plain scripts that do not use EventManager
    document.dispatchEvent(new CustomEvent('page:loaded', {detail}));
  },

  /**
   * Scrolls the new page: Back/Forward to where the reader was, otherwise to the
   * #fragment or the top.
   *
   * @param {URL} url
   * @param {Object} info - {fromHistory, scrollY}
   */
  position(url, info) {
    if (info.fromHistory) {
      window.scrollTo(0, info.scrollY || 0);
      return;
    }
    const anchor = url.hash ? document.getElementById(decodeURIComponent(url.hash.slice(1))) : null;
    if (anchor) {
      anchor.scrollIntoView();
    } else if (this.config.scrollToTop) {
      window.scrollTo(0, 0);
    }
  },

  /**
   * Runs the scripts of the new content (innerHTML never executes a script). External
   * scripts already on the page are not loaded again. Listeners the scripts add for
   * DOMContentLoaded or load are called once they have all run - those events will not
   * fire again on this document.
   *
   * @param {HTMLElement} root
   */
  async runScripts(root) {
    const scripts = root ? Array.from(root.querySelectorAll('script')).filter(script => this.isJavaScript(script)) : [];
    if (scripts.length === 0) {
      return;
    }
    const flush = this.captureReadyListeners();
    try {
      for (const inert of scripts) {
        if (inert.src && this.state.loadedScripts.has(inert.src)) {
          continue;
        }
        const script = document.createElement('script');
        for (const {name, value} of Array.from(inert.attributes)) {
          script.setAttribute(name, value);
        }
        if (inert.src) {
          script.async = false;
          this.state.loadedScripts.add(script.src);
          await new Promise(resolve => {
            script.onload = resolve;
            script.onerror = resolve;
            inert.replaceWith(script);
          });
        } else {
          script.textContent = inert.textContent;
          inert.replaceWith(script);
        }
      }
    } finally {
      flush();
    }
  },

  /**
   * @param {HTMLScriptElement} script
   * @returns {boolean}
   */
  isJavaScript(script) {
    const type = (script.getAttribute('type') || '').trim().toLowerCase();
    return type === '' || type === 'module' || /^(text|application)\/(x-)?(java|ecma)script$/.test(type);
  },

  /**
   * Holds back DOMContentLoaded/load listeners added while new scripts run.
   *
   * @returns {Function} Restores addEventListener and calls the held listeners
   */
  captureReadyListeners() {
    const held = [];
    const restores = [document, window].map(target => {
      const own = Object.prototype.hasOwnProperty.call(target, 'addEventListener');
      const original = target.addEventListener;
      target.addEventListener = function(type, listener, options) {
        if (listener && (type === 'DOMContentLoaded' || (type === 'load' && this === window))) {
          held.push({target: this, type, listener});
          return;
        }
        return original.call(this, type, listener, options);
      };
      return () => {
        if (own) {
          target.addEventListener = original;
        } else {
          delete target.addEventListener;
        }
      };
    });

    return () => {
      restores.forEach(restore => restore());
      held.forEach(({target, type, listener}) => {
        try {
          const event = new Event(type);
          if (typeof listener === 'function') {
            listener.call(target, event);
          } else {
            listener.handleEvent(event);
          }
        } catch (error) {
          console.error('[PageNavigator] page script failed', error);
        }
      });
    };
  },

  /**
   * @param {string} [html] - Tags marked data-page-meta
   */
  replaceHead(html) {
    if (typeof html !== 'string') {
      return;
    }
    document.head.querySelectorAll('[data-page-meta]').forEach(element => element.remove());
    const template = document.createElement('template');
    template.innerHTML = html;
    document.head.append(...Array.from(template.content.children));
  },

  /**
   * @param {string[]} [styles]
   */
  ensureStyles(styles) {
    if (!Array.isArray(styles)) {
      return;
    }
    const loaded = new Set(Array.from(document.querySelectorAll('link[rel="stylesheet"]')).map(link => link.href));
    styles.forEach(href => {
      const url = new URL(href, location.href).href;
      if (!loaded.has(url)) {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = url;
        document.head.appendChild(link);
        loaded.add(url);
      }
    });
  },

  /**
   * @param {PopStateEvent} event
   */
  handlePopState(event) {
    // Only the #fragment changed (an in-page anchor, a tab) - the page stays
    if (this.withoutHash(location.href) === this.state.url) {
      return;
    }
    this.navigate(location.href, {fromHistory: true, scrollY: event.state?.scrollY});
  },

  /**
   * @param {string} url
   * @returns {Promise<boolean>}
   */
  async canLeave(url) {
    for (const guard of this.guards) {
      try {
        if ((await guard(url)) === false) {
          return false;
        }
      } catch (error) {
        console.error('[PageNavigator] leave guard failed', error);
      }
    }
    return true;
  },

  /**
   * @param {string} url
   * @param {boolean} addressIsCurrent - The address bar already shows url (Back/Forward, or pushed)
   */
  fullLoad(url, addressIsCurrent) {
    // The loading state stays until the page goes; a download never unloads it, so it
    // ends on its own after a while
    this.state.leaving = true;
    this.state.leaveTimer = setTimeout(() => {
      this.state.leaving = false;
      this.setLoading(false);
    }, 3000);
    if (addressIsCurrent) {
      location.reload();
    } else {
      location.assign(url);
    }
  },

  saveScroll() {
    history.replaceState({...(history.state || {}), pageNavigator: true, scrollY: window.scrollY}, '', location.href);
  },

  /**
   * scrollRestoration is manual, so a reload or a Back into a reloaded document
   * restores the position saved on pagehide.
   */
  restoreScrollAfterReload() {
    const type = performance.getEntriesByType?.('navigation')?.[0]?.type;
    const y = history.state?.scrollY;
    if ((type === 'reload' || type === 'back_forward') && typeof y === 'number') {
      if (document.readyState === 'complete') {
        window.scrollTo(0, y);
      } else {
        window.addEventListener('load', () => window.scrollTo(0, y), {once: true});
      }
    }
  },

  /**
   * @param {boolean} loading
   */
  setLoading(loading) {
    document.body.classList.toggle('loading', loading);
    if (loading) {
      this.showProgress();
    } else {
      this.hideProgress();
    }
    const main = document.querySelector(Now.config.mainSelector);
    if (main) {
      if (loading) {
        main.setAttribute('aria-busy', 'true');
      } else {
        main.removeAttribute('aria-busy');
      }
    }
  },

  /**
   * First step of the progress bar: from empty to 80%, where it waits for the page.
   */
  showProgress() {
    if (!this.config.progress) {
      return;
    }
    let bar = this.progressBar;
    if (!bar) {
      bar = document.createElement('div');
      bar.className = 'page-progress';
      bar.setAttribute('aria-hidden', 'true');
      // Back to empty once it has faded out, ready for the next page
      bar.addEventListener('transitionend', event => {
        if (event.propertyName === 'opacity' && bar.classList.contains('is-done')) {
          bar.classList.remove('is-done');
        }
      });
      document.body.appendChild(bar);
      this.progressBar = bar;
    }
    if (bar.classList.contains('is-loading')) {
      return;
    }
    this.fitProgressColor(bar);
    // Restart from empty without animating back, then run
    bar.classList.remove('is-done');
    void bar.offsetWidth;
    bar.classList.add('is-loading');
  },

  /**
   * The bar takes --page-progress-color, but a header in that same colour (the
   * primary colour, often) would swallow it: then it turns white or dark, whichever
   * stands out against what is at the top of the window right now.
   *
   * @param {HTMLElement} bar
   */
  fitProgressColor(bar) {
    bar.style.removeProperty('background');
    const color = this.parseColor(getComputedStyle(bar).backgroundColor);
    let under = null;
    for (let el = document.elementFromPoint(window.innerWidth / 2, 1); el && !under; el = el.parentElement) {
      const background = this.parseColor(getComputedStyle(el).backgroundColor);
      if (background && background.a > 0.5) {
        under = background;
      }
    }
    under = under || {r: 255, g: 255, b: 255};
    if (color && this.contrast(color, under) < 2) {
      bar.style.background = this.luminance(under) > 0.4 ? 'rgba(0, 0, 0, 0.6)' : '#fff';
    }
  },

  /**
   * @param {string} value - Computed colour, rgb() or rgba()
   * @returns {Object|null} {r, g, b, a}, null when not in that form
   */
  parseColor(value) {
    const m = /^rgba?\(([\d.]+),\s*([\d.]+),\s*([\d.]+)(?:,\s*([\d.]+))?\)$/.exec(value || '');
    return m ? {r: +m[1], g: +m[2], b: +m[3], a: m[4] === undefined ? 1 : +m[4]} : null;
  },

  /**
   * WCAG relative luminance
   *
   * @param {Object} c - {r, g, b}
   * @returns {number}
   */
  luminance(c) {
    const channel = v => {
      v /= 255;
      return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    };
    return 0.2126 * channel(c.r) + 0.7152 * channel(c.g) + 0.0722 * channel(c.b);
  },

  /**
   * WCAG contrast ratio, 1 (none) to 21
   *
   * @param {Object} a
   * @param {Object} b
   * @returns {number}
   */
  contrast(a, b) {
    const [high, low] = [this.luminance(a), this.luminance(b)].sort((x, y) => y - x);
    return (high + 0.05) / (low + 0.05);
  },

  /**
   * Second step: the page is in - fill to 100% and fade out.
   */
  hideProgress() {
    const bar = this.progressBar;
    if (bar?.classList.contains('is-loading')) {
      bar.classList.replace('is-loading', 'is-done');
    }
  },

  /**
   * @param {string} url
   * @returns {string}
   */
  withoutHash(url) {
    return url.split('#')[0];
  }
};

if (window.Now?.registerManager) {
  Now.registerManager('navigator', PageNavigator);
}

window.PageNavigator = PageNavigator;
