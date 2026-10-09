const I18nManager = {
  config: {
    enabled: false,
    defaultLocale: 'en',
    availableLocales: ['en'],
    storageKey: 'app_lang',
    useBrowserLocale: false,
    noTranslateEnglish: true
  },

  state: {
    current: null,
    initialized: false,
    translations: new Map()
  },

  /**
   * Configuration options:
   * - enabled: Whether the i18n system is active
   * - defaultLocale: The default language code to use
   * - availableLocales: List of supported language codes
   * - storageKey: localStorage key to save the selected language
   * - noTranslateEnglish: When true, English translations will not be translated
   *   as the current locale will be used directly without translation lookup.
   *   This is useful for multilingual applications where some content may already
   *   be in the target language and doesn't need translation.
   */

  async init(options = {}) {
    this.config = {...this.config, ...options};

    if (!this.config.enabled) {
      this.state.disabled = true;
      return this;
    }

    await this.loadInitialLocale();

    this.state.initialized = true;

    // Auto-translate newly added DOM nodes
    this.setupDOMObserver();

    EventManager.emit('i18n:initialized');

    return this;
  },

  /**
   * Switch the active locale and re-translate the page.
   *
   * Does nothing when i18n is disabled, and throws when the locale is not in
   * `config.availableLocales`.
   *
   * @param {string} locale - Locale to switch to.
   * @param {boolean} [force=false] - Re-apply even when it is already current.
   * @returns {Promise<void>}
   * @throws {Error} - When the locale is not supported.
   */
  async setLocale(locale, force = false) {
    try {
      if (!this.config.enabled) return;

      if (!this.config.availableLocales.includes(locale)) {
        throw new Error(`Unsupported locale: ${locale}`);
      }

      if (locale === this.state.current && !force) {
        return;
      }

      // Try to load translations, but don't fail if file doesn't exist
      // This allows using data-i18n as fallback for languages without translation files
      if ((!this.config.noTranslateEnglish || locale !== 'en') && !this.state.translations.has(locale)) {
        try {
          await this.loadTranslations(locale);
        } catch (error) {
          // If translation file doesn't exist, that's okay - we'll use data-i18n as fallback
        }
      }

      this.state.current = locale;
      document.documentElement.setAttribute('lang', locale);

      this.setStoredLocale(locale);

      window.setTimeout(() => {
        this.updateTranslations();
      }, 100);

      EventManager.emit('locale:changed', {
        locale,
        forced: force
      });
    } catch (error) {
      ErrorManager.handle(error, {
        context: 'I18nManager.setLocale',
        type: 'error:i18n',
        data: {locale, force},
        notify: true
      });
    }
  },

  /**
   * The locale currently in effect.
   *
   * @returns {string} - Locale code, e.g. `th` or `en`.
   */
  getCurrentLocale() {
    return this.state.current;
  },

  /**
   * Fetch and cache the translation file for a locale.
   *
   * The URL comes from `Now.resolvePath(locale, 'translations')` plus `.json`,
   * and the request is retried on failure.
   *
   * @param {string} locale - Locale to load.
   * @param {number} [retries=2] - Extra attempts after the first failure.
   * @returns {Promise<Object>} - The loaded translation map.
   */
  async loadTranslations(locale, retries = 2) {
    const url = `${Now.resolvePath(locale, 'translations')}.json`;

    for (let attempt = 0; attempt <= retries; attempt++) {
      try {
        // Use native fetch for reliability
        const requestOptions = Now.applyRequestLanguage({
          method: 'GET',
          headers: {
            'Accept': 'application/json',
            'Cache-Control': 'no-cache'
          },
          credentials: 'same-origin'
        });

        const response = await fetch(url, requestOptions);

        // Check HTTP status
        if (!response.ok) {
          if (response.status === 404) {
            // Translation file not found - this is acceptable
            console.warn(`Translation file not found for locale: ${locale}`);

            // Emit event even for 404 so components know to render with fallback
            EventManager.emit('i18n:loaded', {
              locale,
              success: false,
              reason: '404'
            });
            return;
          }
          throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        // Parse JSON
        const translations = await response.json();

        // Validate response structure
        if (!translations || typeof translations !== 'object') {
          throw new Error('Invalid translation format: expected object');
        }

        // Store translations
        this.state.translations.set(locale, translations);

        // Emit event that translations loaded successfully
        EventManager.emit('i18n:loaded', {
          locale,
          success: true,
          translationCount: Object.keys(translations).length
        });

        // Success - exit retry loop
        return;

      } catch (error) {
        const isLastAttempt = attempt === retries;

        // Don't retry on 404 or JSON parse errors
        if (error.name === 'SyntaxError' || error.message.includes('404')) {
          throw error;
        }

        if (isLastAttempt) {
          // Final attempt failed
          ErrorManager.handle(error, {
            context: 'I18nManager.loadTranslations',
            type: 'error:i18n',
            data: {
              locale,
              url,
              attempts: attempt + 1,
              errorType: error.name,
              errorMessage: error.message
            },
            notify: true
          });
          throw new Error(`Failed to load translations for ${locale} after ${attempt + 1} attempts: ${error.message}`);
        }

        // Wait before retry with exponential backoff
        const delay = Math.min(1000 * Math.pow(2, attempt), 3000);
        console.warn(`Translation load failed (attempt ${attempt + 1}/${retries + 1}), retrying in ${delay}ms...`, error);
        await new Promise(resolve => setTimeout(resolve, delay));
      }
    }
  },

  /**
   * Re-translate every `data-i18n` element on the page.
   *
   * Called after the locale changes so already-rendered markup catches up.
   *
   * @returns {void}
   */
  updateTranslations() {
    const translations = this.state.translations.get(this.state.current) || {};

    // Translate elements with data-i18n
    const elements = document.querySelectorAll('[data-i18n]');
    elements.forEach(element => {
      this._translateI18nElement(element, translations);
    });

    // Translate placeholder and title attributes containing {LNG_xxx}
    this.translateAttributes();

    // Emit event to notify components that translations have been applied
    EventManager.emit('i18n:updated', {
      locale: this.state.current,
      elementsUpdated: elements.length
    });
  },

  /**
   * Translate `{LNG_xxx}` markers found in attributes across the whole document.
   *
   * @returns {void}
   */
  translateAttributes() {
    this.translateAttributesIn(document);
  },

  /**
   * Read one entry of a translation map — the map's own entries only.
   *
   * A plain `map[key]` also reaches Object.prototype, so text that happened to
   * be `constructor`, `toString` or `__proto__` (row data shown with data-i18n,
   * or `{LNG___proto__}` typed by a user) resolved to a built-in:
   * `{LNG___proto__}` showed "[object Object]", `{LNG_toString}` showed
   * function source and `translate('constructor')` threw.
   *
   * Keys containing a space are looked up literally rather than split on dots,
   * so plain sentences can be used as keys; others are a dotted path.
   *
   * @param {Object} translations - Map to look in.
   * @param {string} key - Translation key, or literal text.
   * @param {boolean} [literal=false] - Never treat the key as a dotted path.
   * @returns {*} - The entry (text, number, list or section), or undefined.
   */
  lookupEntry(translations, key, literal = false) {
    const own = (obj, k) => (obj !== null && typeof obj === 'object' && Object.prototype.hasOwnProperty.call(obj, k)
      ? obj[k]
      : undefined);
    if (literal || key.includes(' ')) {
      return own(translations, key);
    }
    return key.split('.').reduce((obj, k) => own(obj, k), translations);
  },

  /**
   * Resolve a key inside a given translation map.
   *
   * Only text is a translation: a number is shown as text, while a list or a
   * section (DATE_SHORT, MONTH_LONG) leaves the key as it is.
   *
   * @param {string} key - Translation key, or literal text.
   * @param {Object} translations - Map to look in.
   * @param {Object} [params={}] - Values interpolated into the result.
   * @returns {string} - The translated text, or the key when absent.
   */
  getTranslation(key, translations, params = {}) {
    let value = this.lookupEntry(translations, key);

    if (typeof value === 'number') {
      value = String(value);
    }
    if (typeof value !== 'string' || value === '') {
      value = key;
    }

    return this.interpolate(value, params, translations);
  },

  /**
   * Resolve the locale to start in: the visitor's own choice first.
   *
   * `<html lang>` used to win over everything, which quietly threw away the language
   * the visitor picked: choose another language, reload, and the page is back to the
   * one written into the document. The attribute is the document's *default* — a
   * stored choice is a decision the visitor already made about it.
   *
   * Priority: stored choice → `<html lang>` → `defaultLocale`.
   */
  async loadInitialLocale() {
    let locale = this.config.defaultLocale;

    const htmlLang = document.documentElement.getAttribute('lang');
    if (htmlLang && this.config.availableLocales.includes(htmlLang)) {
      locale = htmlLang;
    }

    const stored = this.getStoredLocale();
    if (stored && this.config.availableLocales.includes(stored)) {
      locale = stored;
    }

    await this.setLocale(locale);
  },

  /**
   * Update translations for specific elements
   */
  async updateElements(container = document) {
    if (!this.config.enabled || !this.state.initialized) {
      return;
    }

    const translations = this.state.translations.get(this.state.current) || {};

    const elements = container.querySelectorAll('[data-i18n]');
    elements.forEach(element => {
      this._translateI18nElement(element, translations);
    });

    // Translate placeholder and title attributes in container
    this.translateAttributesIn(container);

    // Emit event for debugging/monitoring
    EventManager.emit('i18n:elements:updated', {
      container,
      elementsUpdated: elements.length,
      locale: this.state.current
    });
  },

  /**
   * Translate `{LNG_xxx}` markers in attributes inside one container.
   *
   * Used when new markup is inserted, so only the new subtree is walked instead
   * of the whole document.
   *
   * @param {HTMLElement|Document} container - Subtree to walk.
   * @param {Object} [translations] - Map to use; defaults to the current locale.
   * @param {RegExp} [lngPattern] - Pattern matching the markers.
   * @returns {void}
   */
  translateAttributesIn(container, translations, lngPattern) {
    if (!translations) {
      translations = this.state.translations.get(this.state.current) || {};
    }
    if (!lngPattern) {
      lngPattern = /\{LNG_[^}]+\}/;
    }
    const attrs = ['placeholder', 'title', 'alt', 'aria-label', 'aria-placeholder', 'label'];
    const selector = attrs.map(a => `[${a}]`).join(', ');

    container.querySelectorAll(selector).forEach(element => {
      if (this.isExcluded(element)) return;
      attrs.forEach(attr => {
        const value = element.getAttribute(attr);
        if (value && lngPattern.test(value)) {
          element.setAttribute(attr, this.interpolate(value, {}, translations));
        }
      });
    });

    // Re-translate placeholders that were set dynamically via ElementFactory
    // (original template stored in data-i18n-placeholder for language-change support)
    container.querySelectorAll('[data-i18n-placeholder]').forEach(element => {
      const template = element.getAttribute('data-i18n-placeholder');
      if (!template) return;
      const translated = this.interpolate(template, {}, translations);
      if ('placeholder' in element) {
        element.placeholder = translated;
      } else {
        element.setAttribute('placeholder', translated);
      }
      // Also update FileElementFactory's visible UI elements if present
      const dropMsg = element.parentElement?.querySelector('.file-drop-message');
      if (dropMsg) dropMsg.textContent = translated;
      const placeholderEl = element.parentElement?.querySelector('.file-display.placeholder');
      if (placeholderEl) placeholderEl.textContent = translated;
    });
  },

  /**
   * Setup a MutationObserver to automatically translate newly added DOM elements.
   * Watches for childList changes and translates:
   * - [data-i18n] elements (text content)
   * - {LNG_...} patterns in attributes (placeholder, title, alt, etc.)
   * - {LNG_...} patterns in text nodes
   *
   * Uses debounced batch processing for performance.
   */
  setupDOMObserver() {
    if (this._domObserver || !window.MutationObserver) return;

    if (!document.body) {
      document.addEventListener('DOMContentLoaded', () => this.setupDOMObserver(), {once: true});
      return;
    }

    const pendingNodes = new Set();
    let debounceTimer = null;
    const self = this;

    this._domObserver = new MutationObserver((mutations) => {
      for (const mutation of mutations) {
        for (const node of mutation.addedNodes) {
          if (node.nodeType === Node.ELEMENT_NODE) {
            pendingNodes.add(node);
          }
        }
      }

      if (pendingNodes.size > 0) {
        if (debounceTimer) clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
          const nodes = Array.from(pendingNodes);
          pendingNodes.clear();
          debounceTimer = null;
          self._processAddedNodes(nodes);
        }, 50);
      }
    });

    this._domObserver.observe(document.body, {
      childList: true,
      subtree: true
    });
  },

  /**
   * Disconnect the DOM observer.
   */
  destroyDOMObserver() {
    if (this._domObserver) {
      this._domObserver.disconnect();
      this._domObserver = null;
    }
  },

  /**
   * Process newly added DOM nodes for translation.
   * Filters out descendant nodes to avoid double-processing.
   */
  _processAddedNodes(nodes) {
    if (!this.state.initialized || !this.config.enabled) return;

    // Filter: keep only root-level nodes (skip nodes contained within other pending nodes)
    const rootNodes = nodes.filter(node => {
      if (!node.isConnected) return false;
      return !nodes.some(other => other !== node && other.contains?.(node));
    });

    for (const node of rootNodes) {
      this.translateNode(node);
    }
  },

  /**
   * Comprehensive translation for a DOM node and its subtree.
   * Handles the node itself and all descendants:
   * - [data-i18n] elements
   * - {LNG_...} in translatable attributes
   * - {LNG_...} in text nodes
   */
  /**
   * Whether a node sits inside an element marked `translate="no"`.
   *
   * The DOM observer translates every `{LNG_...}` it meets, including ones that
   * arrived as *data* — a document title stored as `{LNG_Documents}` used to be
   * shown as "Documents". The standard HTML `translate="no"` attribute is the
   * opt-out: nothing inside such an element is touched (text nodes, attributes,
   * data-i18n, and the data-text / data-attr `{LNG_...}` pass in TemplateManager).
   * TableManager sets it on plain data cells; a template puts it around any
   * user-supplied content it renders.
   *
   * @param {Node} node - Element or text node
   * @returns {boolean}
   */
  isExcluded(node) {
    if (!node) return false;
    const el = node.nodeType === Node.ELEMENT_NODE ? node : node.parentElement;
    return !!(el && typeof el.closest === 'function' && el.closest('[translate="no"]'));
  },

  translateNode(node) {
    if (!node || !node.isConnected) return;
    if (this.isExcluded(node)) return;

    const translations = this.state.translations.get(this.state.current) || {};
    const lngPattern = /\{LNG_[^}]+\}/;
    const i18nAttrs = ['placeholder', 'title', 'alt', 'aria-label', 'aria-placeholder', 'label'];

    // 1. Translate the node itself if it has data-i18n
    if (node.hasAttribute?.('data-i18n')) {
      this._translateI18nElement(node, translations);
    }

    // 2. Translate the node's own attributes containing {LNG_...}
    if (node.attributes) {
      for (const attr of i18nAttrs) {
        const value = node.getAttribute?.(attr);
        if (value && lngPattern.test(value)) {
          node.setAttribute(attr, this.interpolate(value, {}, translations));
        }
      }
    }

    // 3. Translate descendant [data-i18n] elements
    if (node.querySelectorAll) {
      node.querySelectorAll('[data-i18n]').forEach(el => {
        this._translateI18nElement(el, translations);
      });

      // 4. Translate descendant attributes containing {LNG_...}
      this.translateAttributesIn(node, translations, lngPattern);
    }

    // 5. Translate {LNG_...} in text nodes (covers all remaining cases)
    this.translateTextNodesIn(node, translations);
  },

  /**
   * Translate a single element with [data-i18n] attribute.
   *
   * Smart mode: When the element contains child elements (e.g. <em>, <a>, <span>)
   * AND `data-i18n` is empty (meaning child elements were authored in HTML),
   * the original innerHTML is saved as a template in `data-i18n-tpl`. Only the
   * direct text nodes of the element are translated — child elements are preserved
   * intact. To translate content inside a child element, add `data-i18n` to that
   * child explicitly.
   *
   * Simple mode: When the element has no child elements, `textContent` is used
   * directly (backward compatible).
   *
   * Keyed mode: When the element has a stored key in `data-i18n` (set by a
   * previous Simple mode pass) AND child elements are present, the children
   * were injected at runtime (e.g. by TableManager adding a col-resizer).
   * In this case only the first direct text node is updated — injected child
   * elements are left untouched and never captured into a template.
   *
   * Coexistence: Child elements with `data-text`, `data-attr`, or other directives
   * are preserved in the template and handled by their respective managers.
   */
  _translateI18nElement(element, translations) {
    if (this.isExcluded(element)) return;
    // Skip elements managed by TemplateManager's data-text directive
    // to prevent conflicting translations (data-text handles its own {LNG_} translation)
    if (element._textBinding) return;

    if (!translations) {
      translations = this.state.translations.get(this.state.current) || {};
    }

    const dataI18n = element.getAttribute('data-i18n')?.trim().replace(/\s+/g, ' ');
    const hasChildElements = element.querySelector('*') !== null;

    if (hasChildElements && dataI18n) {
      // ═══ Keyed mode: children were injected after Simple mode stored the key ═══
      // Translate using the stored key, update only the first direct text node
      // so that runtime-injected elements (col-resizer, badges, etc.) stay intact.
      let translation;
      if (Object.keys(translations).length === 0) {
        translation = this.interpolate(dataI18n, {}, {});
      } else {
        translation = this.getTranslation(dataI18n, translations);
        if (!translation || translation === dataI18n) {
          translation = this.interpolate(dataI18n, {}, translations);
        }
      }

      for (const node of element.childNodes) {
        if (node.nodeType === Node.TEXT_NODE && node.textContent.trim()) {
          node.textContent = translation;
          break;
        }
      }
    } else if (hasChildElements) {
      // ═══ Smart mode: preserve authored child elements ═══
      let tpl = element.getAttribute('data-i18n-tpl');
      if (!tpl) {
        // First encounter: save original innerHTML as template
        tpl = element.innerHTML;
        element.setAttribute('data-i18n-tpl', tpl);
      }

      // Interpolate only direct text nodes (not inside child elements)
      const temp = document.createElement(element.tagName);
      temp.innerHTML = tpl;

      for (const node of Array.from(temp.childNodes)) {
        if (node.nodeType === Node.TEXT_NODE && /\{LNG_[^}]+\}/.test(node.textContent)) {
          node.textContent = this.interpolate(node.textContent, {}, translations);
        }
      }

      element.innerHTML = temp.innerHTML;

      // Process any child [data-i18n] elements created from template
      element.querySelectorAll('[data-i18n]').forEach(child => {
        this._translateI18nElement(child, translations);
      });
    } else {
      // ═══ Simple mode: no child elements (backward compatible) ═══
      const key = dataI18n || element.textContent.trim().replace(/\s+/g, ' ');
      if (!key) return;

      let translation;
      if (Object.keys(translations).length === 0) {
        translation = this.interpolate(key, {}, {});
      } else {
        translation = this.getTranslation(key, translations);
        if (!translation || translation === key) {
          translation = this.interpolate(key, {}, translations);
        }
      }

      element.textContent = translation;
      if (!dataI18n) {
        element.setAttribute('data-i18n', key);
      }
    }
  },

  /**
   * Translate text nodes containing {LNG_...} patterns within a container.
   * Uses TreeWalker for efficient traversal. Only processes text nodes
   * that actually contain {LNG_...} patterns.
   */
  translateTextNodesIn(container, translations) {
    if (!container) return;

    const lngPattern = /\{LNG_[^}]+\}/;
    if (!translations) {
      translations = this.state.translations.get(this.state.current) || {};
    }

    const walker = document.createTreeWalker(
      container,
      NodeFilter.SHOW_TEXT,
      {
        acceptNode: (node) =>
          lngPattern.test(node.textContent) && !this.isExcluded(node)
            ? NodeFilter.FILTER_ACCEPT
            : NodeFilter.FILTER_REJECT
      }
    );

    const textNodes = [];
    while (walker.nextNode()) {
      textNodes.push(walker.currentNode);
    }

    for (const textNode of textNodes) {
      textNode.textContent = this.interpolate(textNode.textContent, {}, translations);
    }
  },

  /**
   * Translate a key into the current or a given locale.
   *
   * Keys containing a space are treated as literal text rather than a dotted
   * path. When the locale is English and `config.noTranslateEnglish` is on, the
   * key is returned with `{LNG_xxx}` markers stripped but runtime placeholders
   * such as `{count}` still interpolated.
   *
   * @param {string} key - Translation key, or literal text.
   * @param {Object} [params={}] - Values interpolated into the result.
   * @param {string} [locale=null] - Locale to use; defaults to the current one.
   * @returns {string} - Translated text, or the key when nothing matches.
   */
  translate(key, params = {}, locale = null) {
    if (typeof key !== 'string') return key;

    const currentLocale = locale || this.getCurrentLocale();
    if (currentLocale === 'en' && this.config.noTranslateEnglish) {
      // Remove {LNG_xxx} patterns when not translating English,
      // but still interpolate runtime placeholders such as {count}.
      return this.interpolate(key.replace(/\{LNG_([^}]+)\}/g, '$1'), params);
    }

    const translations = this.state.translations.get(currentLocale);

    if (!translations) {
      return this.getFallbackTranslation(key, params);
    }

    return this.getTranslation(key, translations, params);
  },

  /**
   * Last resort when a key has no translation in the current locale.
   *
   * Without params the key is returned as-is. With params, the default locale is
   * consulted so placeholders still get filled rather than shown raw.
   *
   * @param {string} key - Translation key.
   * @param {Object} params - Values to interpolate.
   * @returns {string} - Best available text.
   */
  getFallbackTranslation(key, params) {
    if (!params || Object.keys(params).length === 0) {
      return key;
    }

    if (this.state.current !== this.config.defaultLocale) {
      const defaultTranslations = this.state.translations.get(this.config.defaultLocale);
      if (defaultTranslations) {
        const value = this.lookupEntry(defaultTranslations, key);
        if (typeof value === 'string' && value !== '') {
          return this.interpolate(value, params);
        }
      }
    }

    return this.interpolate(key, params);
  },

  /**
   * Fill placeholders in a string.
   *
   * `{LNG_xxx}` markers are replaced from the translation map first — an unknown
   * marker falls back to its own name — then runtime `{param}` placeholders are
   * substituted from `params`.
   *
   * `{LNG_xxx}` is the only marker that is searched for. Any other `{...}` is
   * replaced only when the caller passed that exact name in `params`, and is
   * otherwise left as written. The second pass used to look every `{...}` up in
   * the catalog and, when nothing matched, drop the braces — so a value such as
   * `{"a":1}` came back as `"a":1`, `{{7*7}}` as `{7*7}`, and a `{Guest}` that
   * happened to be a catalog key came back translated. Text that carries no
   * `{LNG_...}` and no requested param now passes through untouched, which is
   * what a cell, a tag or a title built from row data needs. This is the same
   * contract as Kotchasan's `Language::replace()`: substitute what was asked
   * for, never scan for more.
   *
   * @param {string} text - Text containing placeholders.
   * @param {Object} [params={}] - Runtime values, keyed by placeholder name.
   * @param {Object} [translations] - Map used for `{LNG_xxx}`; defaults to current.
   * @returns {string} - Text with placeholders filled.
   */
  interpolate(text, params = {}, translations) {
    const trans = translations || this.getTranslations();
    const values = params && typeof params === 'object' ? params : {};

    // 1. {LNG_xxx}: the translation marker. An unknown key shows its own name.
    let result = text.replace(/\{LNG_([^}]+)\}/g, (match, key) => {
      return this.lookupEntry(trans, key, true) ?? key;
    });

    // 2. {name}: a runtime value the caller supplied. Anything else stays as
    //    written — braces are data, not a request to translate. A supplied value
    //    is inserted verbatim; it is not scanned again.
    result = result.replace(/\{([^}]+)\}/g, (match, key) => {
      if (Object.prototype.hasOwnProperty.call(values, key) && values[key] !== undefined) {
        return String(values[key]);
      }
      return match;
    });

    return result;
  },

  /**
   * Build a translate function bound to one locale.
   *
   * Handy when a component must render in a locale other than the current one.
   *
   * @param {string} locale - Locale the returned function translates into.
   * @returns {Function} - `(key, params) => string`.
   */
  getTranslator(locale) {
    return (key, params = {}) => this.translate(key, params, locale);
  },

  /**
   * The whole translation map for a locale.
   *
   * @param {string} [locale=null] - Locale to read; defaults to the current one.
   * @returns {Object} - Translation map, empty when the locale is not loaded.
   */
  getTranslations(locale = null) {
    const targetLocale = locale || this.getCurrentLocale();
    return this.state.translations.get(targetLocale) || {};
  },

  /**
   * Collect one key's translation across every loaded locale.
   *
   * Keys containing a space are looked up literally; others are treated as a
   * dotted path.
   *
   * @param {string} key - Translation key.
   * @returns {Object} - Map of locale code to translated text.
   */
  getKeyTranslations(key) {
    const translations = {};
    this.state.translations.forEach((value, locale) => {
      const translation = this.lookupEntry(value, key);
      if (translation) {
        translations[locale] = translation;
      }
    });
    return translations;
  },

  /**
   * Whether a key has a translation.
   *
   * @param {string} key - Translation key, or literal text.
   * @param {string} [locale=null] - Locale to check; defaults to the current one.
   * @returns {boolean} - True when the key resolves to something.
   */
  hasTranslation(key, locale = null) {
    return this.lookupEntry(this.getTranslations(locale), key) !== undefined;
  }
};

if (window.Now?.registerManager) {
  Now.registerManager('i18n', I18nManager);
}

window.I18nManager = I18nManager;

// LocalStorage helpers (safe)
I18nManager.getStoredLocale = function() {
  if (!this.config.storageKey) return null;
  try {
    return localStorage.getItem(this.config.storageKey);
  } catch (e) {
    console.warn('I18nManager: Unable to read from localStorage', e);
    return null;
  }
};

I18nManager.setStoredLocale = function(locale) {
  if (!this.config.storageKey) return;
  try {
    localStorage.setItem(this.config.storageKey, locale);
  } catch (e) {
    console.warn('I18nManager: Unable to write to localStorage', e);
  }
};

// Component: lang-toggle
// Provides an easy declarative language switcher. Usage:
// <button data-component="lang-toggle" data-locales="en,th" data-theme-map="en:light,th:dark">EN</button>
(() => {
  const registerLangToggle = () => {
    if (I18nManager._langToggleRegistered) return true;

    const compManager = (Now.getManager ? Now.getManager('component') : null) || window.ComponentManager;
    if (!compManager || typeof compManager.define !== 'function') return false;

    compManager.define('lang-toggle', {
      mounted() {
        const el = this.element;

        // Determine locales (attribute or i18n config availableLocales)
        const i18n = Now.getManager ? Now.getManager('i18n') : null;
        const avail = (i18n && i18n.config && Array.isArray(i18n.config.availableLocales))
          ? i18n.config.availableLocales : ['en'];

        const localesAttr = (el.getAttribute('data-locales') || avail.join(',')).trim();
        let locales = localesAttr.split(',').map(s => s.trim()).filter(Boolean);

        // Optional theme map: 'en:light,th:dark'
        const themeMapAttr = el.getAttribute('data-theme-map') || '';
        const themeMap = {};
        themeMapAttr.split(',').map(p => p.trim()).forEach(pair => {
          if (!pair) return;
          const [k, v] = pair.split(':').map(s => s.trim());
          if (k && v) themeMap[k] = v;
        });

        const labelEl = el.querySelector('[data-lang-label]') || el.querySelector('span');
        const menuEl = el.querySelector('ul');
        const menuItems = menuEl ? Array.from(menuEl.querySelectorAll('a')) : [];

        // If menu anchors provided, use them to derive locales
        const menuLocales = menuItems
          .map(a => (a.getAttribute('data-locale') || a.textContent || '').trim())
          .filter(Boolean);
        if (menuLocales.length) {
          locales = menuLocales;
        }

        const updateLabel = () => {
          try {
            const cur = (i18n && typeof i18n.getCurrentLocale === 'function') ? i18n.getCurrentLocale() : document.documentElement.lang || locales[0];
            const display = (cur || locales[0] || '').toUpperCase();
            if (labelEl) {
              labelEl.textContent = display;
              labelEl.setAttribute('aria-label', `Language: ${cur}`);
            } else {
              el.setAttribute('data-lang', display);
            }

            // Mark active item in menu
            menuItems.forEach(a => {
              const l = (a.getAttribute('data-locale') || a.textContent || '').trim();
              const isActive = l === cur;
              if (isActive) {
                a.classList.add('active');
                a.setAttribute('aria-current', 'true');
              } else {
                a.classList.remove('active');
                a.removeAttribute('aria-current');
              }
            });
          } catch (e) { /* ignore */}
        };

        const clickHandler = (event) => {
          const link = event.target.closest('a');
          if (link) {
            event.preventDefault();
            const next = (link.getAttribute('data-locale') || link.textContent || '').trim();
            if (i18n && typeof i18n.setLocale === 'function' && next) {
              i18n.setLocale(next);
              // Apply theme mapping if configured and AppConfigManager available
              try {
                const configMgr = Now.getManager ? Now.getManager('config') : null;
                if (configMgr && typeof configMgr.setTheme === 'function' && themeMap[next]) {
                  configMgr.setTheme(themeMap[next]);
                }
              } catch (err) { /* ignore */}
            }
            return;
          }

          // Fallback: cycle locales when clicking button body
          if (!i18n || typeof i18n.setLocale !== 'function') return;
          const cur = i18n.getCurrentLocale() || locales[0];
          const idx = locales.indexOf(cur);
          const nextLocale = locales[(idx + 1) % locales.length] || locales[0];
          i18n.setLocale(nextLocale);
          try {
            const configMgr = Now.getManager ? Now.getManager('config') : null;
            if (configMgr && typeof configMgr.setTheme === 'function' && themeMap[nextLocale]) {
              configMgr.setTheme(themeMap[nextLocale]);
            }
          } catch (err) { /* ignore */}
        };

        // Register listeners
        el.__langToggleClick = clickHandler;
        el.addEventListener('click', clickHandler);

        // Update on locale change
        el.__langToggleUpdate = updateLabel;
        try {
          EventManager.on('locale:changed', updateLabel);
        } catch (e) {
          // best-effort
        }

        // Initial label
        updateLabel();
      },
      destroyed() {
        const el = this.element;
        try {
          if (el.__langToggleClick) el.removeEventListener('click', el.__langToggleClick);
        } catch (e) {}
        try {
          if (el.__langToggleUpdate) EventManager.off && EventManager.off('locale:changed', el.__langToggleUpdate);
        } catch (e) {}
      }
    });

    I18nManager._langToggleRegistered = true;
    return true;
  };

  // Try immediate registration; if ComponentManager isn't ready yet, retry after DOM ready
  if (!registerLangToggle()) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', registerLangToggle, {once: true});
    } else {
      setTimeout(registerLangToggle, 0);
    }
  }
})();
