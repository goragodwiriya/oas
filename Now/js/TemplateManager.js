const TemplateManager = {
  config: {
    cache: true,
    cacheDuration: 5 * 60 * 1000,
    security: {
      allowedOrigins: [],
      allowedExtensions: ['.html', '.htm'],
      maxContentSize: 2 * 1024 * 1024,
      sanitize: true,
      validateMarkup: true,
      allowedTags: [
        'html', 'head', 'body', 'title', 'meta', 'link', 'style', 'base',
        'header', 'nav', 'main', 'section', 'article', 'aside', 'footer',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'br', 'hr', 'pre',
        'blockquote', 'ol', 'ul', 'li', 'dl', 'dt', 'dd', 'a', 'em',
        'strong', 'small', 's', 'cite', 'code', 'sub', 'sup', 'span',
        'mark', 'b', 'i', 'u', 'var', 'time', 'img', 'figure',
        'figcaption', 'audio', 'video', 'source', 'track', 'iframe',
        'embed', 'object', 'param', 'picture',
        'template', 'canvas', 'svg', 'math', 'table', 'caption',
        'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'colgroup',
        'col', 'summary', 'form', 'input', 'textarea', 'button',
        'select', 'option', 'label', 'fieldset', 'legend', 'datalist',
        'output', 'div', 'details', 'dialog', 'meter', 'progress',
        'ruby', 'rt', 'rp', 'abbr', 'kbd', 'samp', 'font', 'center',
        'big', 'strike', 'marquee'
      ],

      allowedAttributes: {
        'all': [
          'id', 'class', 'title', 'lang', 'dir', 'hidden', 'tabindex', 'translate',
          'draggable', 'aria-*', 'role', 'data-*', 'accesskey', 'contenteditable',
          'contextmenu'
        ],
        'img': [
          'src', 'alt', 'width', 'height', 'loading', 'srcset', 'sizes',
          'decoding', 'referrerpolicy', 'crossorigin'
        ],
        'a': [
          'href', 'target', 'rel', 'download', 'hreflang', 'type'
        ],
        'input': [
          'type', 'value', 'placeholder', 'required', 'checked', 'disabled',
          'readonly', 'maxlength', 'minlength', 'pattern', 'size', 'step', 'multiple',
          'min', 'max', 'list', 'inputmode', 'autocomplete', 'autofocus', 'name'
        ],
        'select': ['value', 'placeholder', 'multiple', 'required', 'size', 'autofocus', 'name'],
        'option': ['value', 'selected', 'disabled', 'label'],
        'textarea': [
          'placeholder', 'required', 'rows', 'cols', 'maxlength', 'minlength', 'readonly', 'autofocus', 'name'
        ],
        'button': [
          'type', 'disabled', 'name', 'value', 'autofocus', 'form'
        ],
        'form': [
          'action', 'method', 'enctype', 'autocomplete', 'novalidate', 'target', 'name', 'accept-charset'
        ],
        'label': ['for'],
        'time': ['datetime'],
        'iframe': [
          'src', 'width', 'height', 'allow', 'loading', 'sandbox', 'referrerpolicy', 'name'
        ],
        'meta': ['name', 'content', 'http-equiv', 'charset']
      }
    },

    events: {
      cleanupInterval: 60000
    },

    cleanup: {
      interval: 60 * 1000,
      maxCacheAge: 30 * 60 * 1000,
      maxHandlerAge: 5 * 60 * 1000,
      batchSize: 100
    }
  },

  state: {
    cache: new Map(),
    initialized: false,
    serverPaths: new Set(),
    handlers: new Map(),
    cleanupInterval: null,
    lastCleanup: Date.now(),
    itemTimestamps: new Map(),
    performanceObserver: null,
    activeScripts: new Map()
  },

  /**
   * Initializes the manager: defaults the allowed origins to this one, merges
   * the configuration, starts performance monitoring and the cleanup timer.
   *
   * Calling it again once initialized is a no-op.
   *
   * @param {Object} [options={}] - Configuration merged over the defaults
   * @param {string[]} [options.serverPaths] - Paths templates may be loaded from
   * @returns {Promise<Object>} The manager instance
   */
  async init(options = {}) {
    if (this.state.initialized) return this;

    if (this.config.security.allowedOrigins.length === 0) {
      this.config.security.allowedOrigins.push(window.location.origin);
    }

    this.config = Now.mergeConfig(this.config, options);

    if (options.serverPaths) {
      this.state.serverPaths = new Set(options.serverPaths);
    }

    this.setupPerformanceMonitoring();

    this.state.initialized = true;

    window.Now.handleEvent = (handlerId, element, event) => {
      const handler = this.state.handlers.get(handlerId);
      if (handler) {
        handler.call(event);
      } else {
        ErrorManager.handle(`Handler with ID ${handlerId} not found`, {
          context: 'TemplateManager.window.Now.handleEvent',
          data: {handlerId, element, event}
        });
      }
    };

    this.startCleanupInterval();

    // Re-translate data-text / data-attr bindings when locale changes
    EventManager.on('locale:changed', () => {
      this._retranslateBindings();
    });

    // Cleanup data-scripts before route navigation
    EventManager.on('route:before', () => {
      this.cleanupScripts();
    }, {priority: -100}); // Run early to cleanup before navigation

    return this;
  },

  /**
   * Fetches a template from the server, resolving the name against the
   * configured templates path and serving it from the cache when it is warm.
   *
   * @param {string} path - Template name, path or absolute URL
   * @returns {Promise<string>} Template markup
   * @throws {Error} When the manager is not initialized, the path is rejected,
   *   or the request fails
   */
  async loadFromServer(path) {
    if (!this.state.initialized) {
      throw new Error('TemplateManager is not initialized')
    }

    // Prefer using Now.resolvePath to build canonical template URLs when available
    try {
      if (window.Now && typeof window.Now.resolvePath === 'function' && !path.trim().startsWith('<') && !/^(https?:)?\/\//i.test(path)) {
        path = window.Now.resolvePath(path, 'templates');
      } else if (Now.config?.paths?.templates && !path.trim().startsWith('<') && !/^(https?:)?\/\//i.test(path) && !path.startsWith(Now.config.paths.templates)) {
        path = Now.config.paths.templates + path;
      }
    } catch (e) {
      if (Now.config?.paths?.templates && !path.trim().startsWith('<') && !/^(https?:)?\/\//i.test(path) && !path.startsWith(Now.config.paths.templates)) {
        path = Now.config.paths.templates + path;
      }
    }

    const startTime = performance.now();

    if (!this.isValidPath(path)) {
      throw new Error(`Invalid template path format: ${path}`);
    }

    const cacheKey = `template:${path}`;
    if (this.config.cache) {
      const cached = this.state.cache.get(cacheKey);
      if (cached && cached.expires > Date.now()) {
        return cached.content;
      }
    }

    const url = new URL(path, window.location.origin);

    await this.validateRequest(url);

    // Use HttpClient only - no fallback
    if (!window.http || typeof window.http.get !== 'function') {
      throw new Error('HttpClient (window.http) is required but not available');
    }

    const response = await window.http.get(url.toString(), {
      throwOnError: false,
      headers: {
        'Accept': 'text/html'
      }
    });

    if (!response.success) {
      throw new Error(`Failed to fetch template: ${path}`);
    }

    const contentType = response.headers['content-type'];
    if (!contentType?.includes('text/html')) {
      throw new Error('Invalid content type: Expected HTML');
    }

    const contentLength = response.headers['content-length'];
    if (contentLength && parseInt(contentLength) > this.config.security.maxContentSize) {
      throw new Error('Template size exceeds limit');
    }

    const content = typeof response.data === 'string' ? response.data : await response.data.text();

    const processedContent = await this.processContent(content, path);

    if (this.config.cache) {
      this.state.cache.set(cacheKey, {
        content: processedContent,
        expires: Date.now() + this.config.cacheDuration
      });
      this.state.itemTimestamps.set(cacheKey, Date.now());
    }

    const endTime = performance.now();
    this.recordPerformance('template-load', {
      path,
      duration: endTime - startTime,
      size: content.length
    });

    return processedContent;
  },

  /**
   * Checks that a template URL points at an allowed origin and ends in .html.
   *
   * @param {URL} url - URL about to be fetched
   * @returns {Promise<boolean>} True when the request may proceed
   * @throws {Error} When the origin or the file type is rejected
   */
  async validateRequest(url) {
    if (!this.config.security.allowedOrigins.includes(url.origin)) {
      throw new Error('Invalid origin');
    }

    if (!url.pathname.toLowerCase().endsWith('.html')) {
      throw new Error('Invalid file type');
    }

    return true;
  },

  /**
   * Turns fetched markup into a processed subtree: applies the directives and
   * interpolation of the component registered for that path, then sanitizes it.
   *
   * @param {string} content - Raw template markup
   * @param {string} path - Path it was loaded from, used to find the context
   * @returns {Promise<string>} Processed markup
   */
  async processContent(content, path) {
    try {
      const container = document.createElement('div');
      container.innerHTML = content.trim();

      const componentManager = Now.getManager('component');
      const context = componentManager?.getContextForPath(path);

      if (context) {
        this.processDataDirectives(container, context);
        this.processInterpolation(container, context);
      }

      if (this.config.security.sanitize) {
        this.sanitizeElement(container);
      }

      if (this.config.security.validateMarkup) {
        await this.validateMarkup(container);
      }

      return container.innerHTML;

    } catch (error) {
      throw ErrorManager.handle(error, {
        context: 'TemplateManager.processContent',
        data: {content, path}
      });
    }
  },

  /**
   * Strips anything the security configuration disallows from a subtree: tags,
   * attributes, URL protocols and inline styles.
   *
   * Uses DOMPurify when the page provides it, and its own walk otherwise.
   *
   * @param {HTMLElement|string} element - Subtree or markup to clean
   * @returns {HTMLElement|string} The cleaned subtree or markup
   */
  sanitizeElement(element) {
    try {
      // Accept both HTMLElement and raw HTML string; normalize to element for traversal
      let root = element;
      if (typeof element === 'string') {
        const container = document.createElement('div');
        container.innerHTML = element;
        root = container;
      }

      if (window.DOMPurify) {
        DOMPurify.sanitize(root, {
          ALLOWED_TAGS: this.config.security.allowedTags,
          ALLOWED_ATTR: Object.values(this.config.security.allowedAttributes).flat(),
          IN_PLACE: true
        });
      } else {
        // Collect elements to remove and attributes to clean BEFORE modifying DOM
        // This prevents TreeWalker issues when removing nodes during traversal
        const elementsToRemove = [];
        const attributesToRemove = [];

        const walker = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT, null, false);
        let el = walker.currentNode;
        while (el) {
          const tagName = el.tagName.toLowerCase();

          if (!this.config.security.allowedTags.includes(tagName)) {
            elementsToRemove.push(el);
          } else if (el.attributes) {
            Array.from(el.attributes).forEach(attr => {
              const attrName = attr.name.toLowerCase();

              const isGlobalAllowed = this.config.security.allowedAttributes.all
                .some(pattern => {
                  if (pattern.endsWith('*')) {
                    return attrName.startsWith(pattern.slice(0, -1));
                  }
                  return pattern === attrName;
                });

              const isTagAllowed = this.config.security.allowedAttributes[tagName]?.includes(attrName);

              const isUrlAttr = ['href', 'src', 'action', 'formaction', 'poster'].includes(attrName);
              const isStyleAttr = attrName === 'style';

              // Remove if not allowed by config
              if (!isGlobalAllowed && !isTagAllowed) {
                attributesToRemove.push({el, attrName});
                return;
              }

              // Value-level sanitization for URL attributes
              if (isUrlAttr) {
                const safe = this.sanitizeUrlAttribute(attr.value);
                if (!safe) {
                  attributesToRemove.push({el, attrName});
                  return;
                }
                if (safe !== attr.value) {
                  el.setAttribute(attrName, safe);
                }
              }

              // Inline style sanitization (manual path only)
              if (isStyleAttr) {
                const safeStyle = this.sanitizeInlineStyle(attr.value);
                if (safeStyle === null) {
                  attributesToRemove.push({el, attrName});
                  return;
                }
                el.setAttribute('style', safeStyle);
              }
            });
          }
          el = walker.nextNode();
        }

        // Now safely remove elements after traversal is complete
        elementsToRemove.forEach(el => {
          if (el.parentNode) {
            el.parentNode.removeChild(el);
          }
        });

        // Remove disallowed attributes
        attributesToRemove.forEach(({el, attrName}) => {
          if (el.isConnected) {
            el.removeAttribute(attrName);
          }
        });
      }

      // Return sanitized HTML when caller passed a string
      if (typeof element === 'string') {
        return root.innerHTML;
      }
    } catch (error) {
      throw ErrorManager.handle(error, {
        context: 'TemplateManager.sanitizeElement',
        data: {element}
      });
    }
  },

  /**
   * Cleans a URL used in an attribute, rejecting the javascript:, data:,
   * vbscript:, file: and about: protocols.
   *
   * @param {string} value - Attribute value
   * @returns {string|null} The URL, or null when it was rejected
   */
  sanitizeUrlAttribute(value) {
    if (typeof value !== 'string') return null;

    const trimmed = value.trim();
    const lower = trimmed.toLowerCase();

    const blockedProtocols = ['javascript:', 'data:', 'vbscript:', 'file:', 'about:'];
    if (blockedProtocols.some(proto => lower.startsWith(proto))) {
      ErrorManager.handle('Blocked dangerous protocol in attribute URL', {
        context: 'TemplateManager.sanitizeUrlAttribute',
        data: {value: trimmed},
        logLevel: 'warn'
      });
      return null;
    }

    // Allow http/https absolute URLs
    if (lower.startsWith('http://') || lower.startsWith('https://')) {
      try {
        const urlObj = new URL(trimmed, window.location.origin);
        if (urlObj.protocol === 'http:' || urlObj.protocol === 'https:') {
          return urlObj.toString();
        }
      } catch (e) {
        return null;
      }
      return null;
    }

    // Allow relative URLs without traversal
    if (!lower.includes(':')) {
      if (trimmed.includes('../') || trimmed.includes('..\\')) {
        return null;
      }
      return trimmed;
    }

    return null;
  },

  /**
   * Cleans an inline style attribute, keeping only the properties and values
   * sanitizeStyles() allows.
   *
   * Splits each declaration on its first colon so URLs survive intact.
   *
   * @param {string} styleText - Value of a style attribute
   * @returns {string|null} Cleaned style text, or null when nothing survived
   */
  sanitizeInlineStyle(styleText) {
    if (typeof styleText !== 'string' || !styleText.trim()) return null;

    const styles = styleText.split(';').reduce((acc, style) => {
      // Split only on first colon to preserve URLs (e.g., http://)
      const colonIndex = style.indexOf(':');
      if (colonIndex === -1) return acc;
      const prop = style.substring(0, colonIndex).trim();
      const val = style.substring(colonIndex + 1).trim();
      if (prop && val) {
        const camelProp = prop.replace(/-([a-z])/g, m => m[1].toUpperCase());
        acc[camelProp] = val;
      }
      return acc;
    }, {});

    const safe = this.sanitizeStyles(styles);
    const safeEntries = Object.entries(safe)
      .filter(([, v]) => v !== undefined && v !== null && v !== '');

    if (safeEntries.length === 0) return '';

    return safeEntries.map(([k, v]) => `${k.replace(/([A-Z])/g, '-$1').toLowerCase()}: ${v}`).join('; ');
  },

  /**
   * Checks that markup parses and contains nothing the configuration forbids.
   *
   * Already-parsed elements and fragments are accepted as-is.
   *
   * @param {string|HTMLElement|DocumentFragment} content - Markup to validate
   * @returns {Promise<boolean>} True when the markup is acceptable
   * @throws {Error} When the input is neither a string nor a node
   */
  async validateMarkup(content) {
    return new Promise((resolve, reject) => {
      try {
        // Accept HTMLElement (already parsed) — just resolve
        if (content instanceof HTMLElement || content instanceof DocumentFragment) {
          resolve(true);
          return;
        }

        if (typeof content !== 'string') {
          reject(new Error('validateMarkup expects a string or HTMLElement'));
          return;
        }

        const parser = new DOMParser();
        const doc = parser.parseFromString(content, 'text/html');

        // text/html parser never creates <parsererror> — instead check for
        // structural issues: unclosed tags leave unexpected body content
        const bodyHtml = doc.body?.innerHTML || '';
        const headHtml = doc.head?.innerHTML || '';

        // Check if parser produced empty output for non-empty input
        if (content.trim().length > 0 && bodyHtml.length === 0 && headHtml.length === 0) {
          reject(new Error('HTML parsing produced empty output'));
          return;
        }

        resolve(true);

      } catch (error) {
        ErrorManager.handle(error, {
          context: 'TemplateManager.validateMarkup',
          data: {content}
        });
        reject(error);
      }
    });
  },

  /**
   * Checks a template path: an absolute URL must pass isValidUrl(), and either
   * form must end in an allowed extension and stay clear of traversal.
   *
   * @param {string} path - Template path or URL
   * @returns {boolean} True when the path may be loaded
   */
  isValidPath(path) {
    if (typeof path !== 'string' || !path) return false;

    // Allow absolute URLs (http:// or https://) - validate URL + extension
    if (/^https?:\/\//i.test(path)) {
      if (!this.isValidUrl(path)) {
        return false;
      }

      try {
        const parsed = new URL(path, window.location.origin);
        const pathname = parsed.pathname.toLowerCase();

        return this.config.security.allowedExtensions.some(ext => pathname.endsWith(ext));
      } catch {
        return false;
      }
    }

    if (!path.startsWith('/')) return false;

    const cleanPath = path.split(/[?#]/, 1)[0];
    const pathLower = cleanPath.toLowerCase();
    if (!this.config.security.allowedExtensions.some(ext => pathLower.endsWith(ext))) {
      return false;
    }

    if (pathLower.includes('../') || pathLower.includes('./')) {
      return false;
    }

    if (this.state.serverPaths.size > 0) {
      return this.state.serverPaths.has(this.normalizePath(path));
    }

    return true;
  },

  /**
   * Checks that a URL parses and uses http or https.
   *
   * @param {string} url - URL to check
   * @returns {boolean} True when the URL is usable
   */
  isValidUrl(url) {
    try {
      const parsed = new URL(url, window.location.origin);
      return ['http:', 'https:'].includes(parsed.protocol);
    } catch {
      return false;
    }
  },

  /**
   * Strips the leading and trailing slashes of a path.
   *
   * @param {string} path - Path to normalize
   * @returns {string} Normalized path
   */
  normalizePath(path) {
    return path.replace(/^\/+|\/+$/g, '');
  },

  /**
   * Observes the performance measures named template-*, forwarding each to
   * recordPerformance().
   *
   * Does nothing where PerformanceObserver is unavailable.
   *
   * @returns {void}
   */
  setupPerformanceMonitoring() {
    if (!window.PerformanceObserver) return;

    this.state.performanceObserver = new PerformanceObserver((list) => {
      list.getEntries().forEach(entry => {
        if (entry.entryType === 'measure' && entry.name.startsWith('template-')) {
          this.recordPerformance(entry.name, {
            duration: entry.duration,
            startTime: entry.startTime,
            detail: entry.detail
          });
        }
      });
    });

    this.state.performanceObserver.observe({
      entryTypes: ['measure']
    });

    performance.getEntriesByType('measure').forEach(entry => {
      if (entry.name.startsWith('template-')) {
        this.recordPerformance(entry.name, {
          duration: entry.duration,
          startTime: entry.startTime,
          detail: entry.detail
        });
      }
    });
  },

  /**
   * Emits template:performance with a timing sample.
   *
   * @param {string} name - Measure name
   * @param {Object} data - Timing data
   * @returns {void}
   */
  recordPerformance(name, data) {
    const event = Now.getManager('event');
    if (event) {
      event.emit('template:performance', {
        name,
        ...data,
        timestamp: Date.now()
      });
    }
  },

  /**
   * Emits template:error with the template that failed.
   *
   * @param {Error} error - Error that was caught
   * @param {string} path - Template path
   * @returns {void}
   */
  recordError(error, path) {
    const event = Now.getManager('event');
    if (event) {
      event.emit('template:error', {
        error,
        path,
        timestamp: Date.now()
      });
    }
  },

  /**
   * Empties the template cache.
   *
   * @returns {void}
   */
  clearCache() {
    this.state.cache.clear();
  },

  /**
   * Process data-script directive
   * Calls a global function after template processing is complete
   * @param {HTMLElement} element - Element with data-script attribute
   * @param {Object} context - Template context with state/data
   */
  processDataScript(element, context) {
    const scriptFn = element.dataset.script;
    if (!scriptFn) return;

    if (typeof window[scriptFn] !== 'function') {
      console.warn(`TemplateManager: Function "${scriptFn}" not found in global scope`);
      return;
    }

    const previous = this.state.activeScripts.get(element);
    if (previous && typeof previous.cleanupFn === 'function') {
      try {
        previous.cleanupFn();
      } catch (error) {
        console.warn('TemplateManager: Previous data-script cleanup error for', previous.fn, error);
      }
    }

    // Store for cleanup
    this.state.activeScripts.set(element, {fn: scriptFn});

    // Call the function with element and context data
    try {
      const data = context.state?.data || context.data || {};
      const result = window[scriptFn](element, data);

      // If function returns a cleanup function, store it
      if (typeof result === 'function') {
        this.state.activeScripts.get(element).cleanupFn = result;
      }
    } catch (error) {
      ErrorManager.handle(error, {
        context: 'TemplateManager.processDataScript',
        data: {scriptFn, element}
      });
    }
  },

  /**
   * Process data-on-load directive
   * Works like data-script but uses data-on-load attribute and is intended to run
   * after API/form data has been loaded into a container. The handler receives
   * the normalized data payload (context.state?.data || context.data || context).
   * @param {HTMLElement} element - Element with data-on-load attribute
   * @param {Object} context - Template context with state/data
   */
  processDataOnLoad(element, context) {
    const initFn = element.dataset.onLoad; // corresponds to data-on-load
    if (!initFn) return;

    if (typeof window[initFn] !== 'function') {
      console.warn(`TemplateManager: Function "${initFn}" not found in global scope`);
      return;
    }

    const previous = this.state.activeScripts.get(element);
    if (previous && typeof previous.cleanupFn === 'function') {
      try {
        previous.cleanupFn();
      } catch (error) {
        console.warn('TemplateManager: Previous data-on-load cleanup error for', previous.fn, error);
      }
    }

    // Store for cleanup (reuse activeScripts map)
    this.state.activeScripts.set(element, {fn: initFn});

    try {
      const result = window[initFn](element, context);

      // If function returns a cleanup function, store it
      if (typeof result === 'function') {
        this.state.activeScripts.get(element).cleanupFn = result;
      }
    } catch (error) {
      ErrorManager.handle(error, {
        context: 'TemplateManager.processDataOnLoad',
        data: {initFn, element}
      });
    }
  },

  /**
   * Cleanup all active scripts before page navigation
   * Calls cleanup functions if provided by the script
   */
  cleanupScripts() {
    for (const [element, scriptInfo] of this.state.activeScripts) {
      // Call cleanup function if exists
      if (typeof scriptInfo.cleanupFn === 'function') {
        try {
          scriptInfo.cleanupFn();
        } catch (e) {
          console.warn('TemplateManager: Script cleanup error for', scriptInfo.fn, e);
        }
      }
    }
    this.state.activeScripts.clear();
  },

  /**
   * Applies the directives and interpolation of a context to an element that is
   * already in the DOM.
   *
   * Data an ApiComponent already loaded onto the element is carried into the
   * context, so SPA navigation back to a page does not blank it out.
   *
   * @param {HTMLElement} element - Subtree to process
   * @param {Object} context - {state, methods, ...} the directives read from
   * @returns {void}
   */
  processTemplate(element, context) {
    if (!element || !context) return;

    // Check if element has ApiComponent with existing data - preserve it for SPA navigation
    if (element._apiComponent && element._apiComponent.data && !context.state?.data) {
      context = {
        ...context,
        state: {
          ...context.state,
          data: element._apiComponent.data
        },
        data: element._apiComponent.data
      };
    }

    this.config.reactive = context.reactive;

    try {
      if (this.config.security.validateMarkup) {
        this.validateMarkup(element.innerHTML);
      }

      if (this.config.security.sanitize) {
        this.sanitizeElement(element);
      }

      this.processDataDirectives(element, context);
      this.processInterpolation(element, context);

      // After processing template directives, scan the element subtree so element/form managers can enhance
      // Skip scan if element is not connected to DOM (e.g., in DocumentFragment or temporary container)
      // This prevents MutationObserver from triggering premature cleanup when nodes are moved
      const shouldScan = context.skipScan !== true && element.isConnected;

      if (shouldScan) {
        try {
          const elementManager = Now.getManager('element');
          const formManager = Now.getManager('form');

          if (elementManager && typeof elementManager.scan === 'function') {
            elementManager.scan(element);
          }

          if (formManager && typeof formManager.scan === 'function') {
            formManager.scan(element);
          }
        } catch (err) {
          ErrorManager.handle('TemplateManager.postProcessScan failed', {
            context: 'TemplateManager.processTemplate',
            data: {error: err}
          });
        }
      }

    } catch (error) {
      ErrorManager.handle(error, {
        context: 'TemplateManager.processTemplate',
        data: {element, context}
      });
      return this.renderError(error);
    }
  },

  /**
   * Renders a template string into a container, applies its directives and
   * interpolation, then scans the result so its elements and forms are
   * enhanced.
   *
   * @param {string} template - Template markup
   * @param {Object} context - {state, methods, ...} the directives read from
   * @param {HTMLElement} [container] - Container to render into; created when omitted
   * @returns {HTMLElement} The container holding the rendered subtree
   */
  processTemplateString(template, context, container) {
    try {
      if (!container) {
        container = document.createElement('div');
      }

      container.innerHTML = template;

      this.processDataDirectives(container, context);
      this.processInterpolation(container, context);

      // After creating DOM from template string, run element/form scans for the new nodes
      try {
        const elementManager = Now.getManager('element');
        const formManager = Now.getManager('form');

        if (elementManager && typeof elementManager.scan === 'function') {
          elementManager.scan(container);
        }

        if (formManager && typeof formManager.scan === 'function') {
          formManager.scan(container);
        }
      } catch (err) {
        ErrorManager.handle('TemplateManager.processTemplateString.postScan', {
          context: 'TemplateManager.processTemplateString',
          data: {error: err}
        });
      }

      // Translate any new nodes after rendering template string
      this.scheduleI18nUpdate(container);

    } catch (error) {
      ErrorManager.handle(error, {
        context: 'TemplateManager.processTemplateString',
        data: {template, context, container}
      });
      container.innerHTML = this.renderError(error);
    }
  },

  /**
   * Replaces the {{ }} expressions in the text nodes of a subtree with their
   * evaluated values.
   *
   * @param {HTMLElement} element - Subtree to process
   * @param {Object} context - {state, methods, ...} to evaluate against
   * @returns {void}
   */
  processInterpolation(element, context) {
    const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT, null, false);
    let node = walker.nextNode();
    while (node) {
      if (node.textContent) {
        const matches = node.textContent.match(/\{\{(.+?)\}\}/g);
        if (matches) {
          matches.forEach(match => {
            const expr = match.slice(2, -2).trim();
            const value = ExpressionEvaluator.evaluate(expr, {...context.state, ...context.methods}, context);
            node.textContent = node.textContent.replace(match, value ?? '');
          });
        }
      }
      node = walker.nextNode();
    }

    // Also process attribute interpolation for non-data attributes, e.g. name="prefix[{{type.code}}]"
    try {
      const elWalker = document.createTreeWalker(element, NodeFilter.SHOW_ELEMENT, null, false);
      let el = elWalker.currentNode;
      while (el) {
        if (el.attributes && el.attributes.length > 0) {
          const attrUpdates = [];

          Array.from(el.attributes).forEach(attr => {
            if (!attr.value) return;
            // Skip data-* except mustache on data-options-key / data-attr (paths must resolve before directives run)
            if (attr.name.startsWith('data-')) {
              const allowDataAttrInterpolation =
                (attr.name === 'data-options-key' || attr.name === 'data-attr') &&
                /\{\{/.test(String(attr.value));
              if (!allowDataAttrInterpolation) return;
            }

            const attrMatches = String(attr.value).match(/\{\{(.+?)\}\}/g);
            if (!attrMatches) return;

            // Prepare a binding for this attribute
            const interpolationBinding = {
              originalState: this.deepClone(context.state),
              originalContext: {...context}
            };
            const attrName = attr.name;
            const templateStr = attr.value;

            const updateAttr = () => {
              try {
                const ctx = this.getDirectiveContext(interpolationBinding, context);
                const evalScope = this.getDirectiveEvalState(interpolationBinding, context, {
                  mergeLiveState: true,
                  includeMethods: true
                });
                // data-options-key / data-attr: build paths (attribute_options.5, value:foo[3]) with plain String segments
                const replacer = (_m, expr) => {
                  const val = ExpressionEvaluator.evaluate(expr.trim(), evalScope, ctx);
                  return val == null || val === undefined ? '' : String(val);
                };
                let newValue = String(templateStr).replace(/\{\{(.+?)\}\}/g, replacer);

                if (newValue === null || newValue === undefined || newValue === '') {
                  el.removeAttribute(attrName);
                } else {
                  el.setAttribute(attrName, newValue);
                }
              } catch (e) {
                ErrorManager.handle(e, {
                  context: 'TemplateManager.processInterpolation.attr',
                  data: {el, attr, context}
                });
              }
            };

            // Initial update
            updateAttr();
            attrUpdates.push(updateAttr);
          });

          if (attrUpdates.length > 0) {
            this.setupReactiveUpdate(el, context, 'Attr', () => {
              attrUpdates.forEach(update => update());
            });
          }
        }
        el = elWalker.nextNode();
      }
    } catch (err) {
      ErrorManager.handle(err, {context: 'TemplateManager.processInterpolation.attr', data: {element, context}});
    }
  },

  /**
   * Debounces an i18n pass over a container, so a burst of directive updates
   * retranslates it once instead of every time.
   *
   * The pass is skipped when the container has left the DOM.
   *
   * @param {HTMLElement} container - Subtree to retranslate
   * @returns {void}
   */
  scheduleI18nUpdate(container) {
    if (!container || !window.I18nManager || typeof I18nManager.updateElements !== 'function') return;

    if (!this._i18nUpdateTimers) {
      this._i18nUpdateTimers = new WeakMap();
    }

    if (this._i18nUpdateTimers.has(container)) {
      clearTimeout(this._i18nUpdateTimers.get(container));
    }

    const timer = setTimeout(() => {
      try {
        if (container.isConnected) {
          I18nManager.updateElements(container);
        }
      } catch (e) {
        // ignore translation errors
      }
      this._i18nUpdateTimers.delete(container);
    }, 50);

    this._i18nUpdateTimers.set(container, timer);
  },

  /**
   * Re-run all data-text and data-attr bindings that contain {LNG_} patterns.
   * Called on locale:changed to ensure translated expressions update with new locale.
   * Uses a single global listener (no per-element listeners) for performance.
   */
  _retranslateBindings() {
    // Re-evaluate data-text bindings containing {LNG_} patterns
    document.querySelectorAll('[data-text]').forEach(el => {
      if (el._textBinding?.hasLng && el._textBinding.update && el.isConnected) {
        try {
          el._textBinding.update();
        } catch (e) {
          // ignore individual binding errors
        }
      }
    });

    // Re-evaluate data-attr bindings containing {LNG_} patterns
    document.querySelectorAll('[data-attr]').forEach(el => {
      if (el._attrBinding?.hasLng && el._attrBinding.updates && el.isConnected) {
        el._attrBinding.updates.forEach(fn => {
          try {
            fn();
          } catch (e) {
            // ignore individual binding errors
          }
        });
      }
    });
  },

  /**
   * Splits a data-text expression into the expression itself and the formatters
   * piped after it.
   *
   * Splits only on pipes at the top level: pipes inside quotes, template
   * literals, parentheses, brackets or braces belong to the expression.
   *
   * @param {string} expression - Expression, possibly with | formatters
   * @returns {string[]} The expression followed by each formatter
   */
  _splitTextExpressionAndFormatters(expression) {
    const parts = [];
    let current = '';
    let inSingle = false;
    let inDouble = false;
    let inTemplate = false;
    let escapeNext = false;
    let depthParen = 0;
    let depthBracket = 0;
    let depthBrace = 0;

    for (let i = 0; i < expression.length; i++) {
      const ch = expression[i];
      const prev = i > 0 ? expression[i - 1] : '';
      const next = i + 1 < expression.length ? expression[i + 1] : '';

      if (escapeNext) {
        current += ch;
        escapeNext = false;
        continue;
      }

      if (ch === '\\' && (inSingle || inDouble || inTemplate)) {
        current += ch;
        escapeNext = true;
        continue;
      }

      if (ch === "'" && !inDouble && !inTemplate) {
        inSingle = !inSingle;
        current += ch;
        continue;
      }

      if (ch === '"' && !inSingle && !inTemplate) {
        inDouble = !inDouble;
        current += ch;
        continue;
      }

      if (ch === '`' && !inSingle && !inDouble) {
        inTemplate = !inTemplate;
        current += ch;
        continue;
      }

      if (!inSingle && !inDouble && !inTemplate) {
        if (ch === '(') {
          depthParen++;
        } else if (ch === ')' && depthParen > 0) {
          depthParen--;
        } else if (ch === '[') {
          depthBracket++;
        } else if (ch === ']' && depthBracket > 0) {
          depthBracket--;
        } else if (ch === '{') {
          depthBrace++;
        } else if (ch === '}' && depthBrace > 0) {
          depthBrace--;
        }

        if (
          ch === '|'
          && prev !== '|'
          && next !== '|'
          && depthParen === 0
          && depthBracket === 0
          && depthBrace === 0
        ) {
          const trimmed = current.trim();
          if (trimmed) {
            parts.push(trimmed);
          }
          current = '';
          continue;
        }
      }

      current += ch;
    }

    const trimmed = current.trim();
    if (trimmed) {
      parts.push(trimmed);
    }

    return parts;
  },

  /**
   * Reports whether a string contains any operator or grouping character, so a
   * bare property path can be told apart from an expression.
   *
   * @param {string} str - String to test
   * @returns {boolean} True when the string is more than a path
   */
  hasOperators(str) {
    return /[\+\-\*\/\(\)\[\]\{\}\!\?\:\<\>\&\|\=\,]/.test(str);
  },

  /**
   * Builds the markup shown in place of a template that failed to render.
   *
   * @param {Error} error - Error to display
   * @returns {string} Error boundary markup
   */
  renderError(error) {
    return `
        <div class="error-boundary">
          <p>Template Error: ${error.message}</p>
          <button class="btn close-modal">Close</button>
        </div>
      `;
  },

  /**
   * Apply every `data-*` directive found in a subtree
   *
   * @param {HTMLElement} element - Subtree root
   * @param {Object} context - `{state, data, ...}` the directives read from
   * @param {Object} [options] - `skipNested: true` leaves the inside of any
   *   nested `data-component="api"` alone · that component renders its own
   *   subtree from its own (merged) scope, and painting it here with the outer
   *   scope answers its conditions from data that does not describe it — a
   *   `data-if` reading a field the outer response has never heard of resolves
   *   to undefined and removes the element for good
   */
  processDataDirectives(element, context, options = {}) {
    if (!context || !context.state) {
      ErrorManager.handle('Invalid context for data directives processing', {
        context: 'TemplateManager.processDataDirectives',
        data: {element, context}
      });
      return;
    }

    if (context.reactive && !context._updateQueue) {
      context._updateQueue = new Set();
    }

    if (context.parentId) {
      this.cleanupComponentHandlers(context.parentId);
    }

    // The nested component's own element is still processed here — its
    // `data-if`/`data-attr` belong to the parent that placed it · only what is
    // INSIDE it is left to it
    const filter = options.skipNested
      ? {
        acceptNode(node) {
          const owner = node.parentElement?.closest('[data-component="api"]');

          // `element.contains` keeps a component ABOVE the walk root from
          // rejecting the whole subtree — only a nested one may prune
          return owner && owner !== element && element.contains(owner)
            ? NodeFilter.FILTER_REJECT
            : NodeFilter.FILTER_ACCEPT;
        }
      }
      : null;

    const walker = document.createTreeWalker(element, NodeFilter.SHOW_ELEMENT, filter, false);
    let el = walker.currentNode;

    // Collect data-for elements to process after other directives
    const dataForElements = [];
    // Collect data-attr directives to process AFTER other directives per element
    // This ensures data-options-key populates options BEFORE data-attr sets values
    const deferredAttrDirectives = [];

    while (el) {
      if (el.attributes && this.config.security.allowedTags.includes(el.tagName.toLowerCase())) {
        Array.from(el.attributes).forEach(attr => {
          if (!attr.name.startsWith('data-')) return;

          const directive = attr.name.substring(5);
          const value = attr.value;

          // Collect data-for elements for later processing
          if (directive === 'for') {
            dataForElements.push({el, value});
            return;
          }

          // Skip directives inside template tags (they will be processed by data-for)
          if (el.closest && el.closest('template')) {
            return;
          }

          switch (directive) {
            case 'text':
              this.processDataText(el, value, context);
              break;
            case 'html':
              this.processDataHtml(el, value, context);
              break;
            case 'if':
              this.processDataIf(el, value, context);
              break;
            case 'class':
              this.processDataClass(el, value, context);
              break;
            case 'attr':
              // Defer data-attr processing so options/files are populated first
              deferredAttrDirectives.push({el, value});
              break;
            case 'style':
              this.processDataStyle(el, value, context);
              break;
            case 'on':
              this.processDataOn(el, value, context);
              break;
            case 'container':
              this.processDataContainer(el, value, context);
              break;
            case 'model':
              this.processDataModel(el, value, context);
              break;
            case 'checked':
              this.processDataChecked(el, value, context);
              break;
            case 'files':
              this.processDataFiles(el, value, context);
              break;
            case 'options-key':
              this.processDataOptionsKey(el, value, context);
              break;
            case 'bind':
              this.processDataBind(el, value, context);
              break;
            // Animation directives
            case 'show':
            case 'enter':
            case 'leave':
            case 'transition':
              // Process all animation directives together
              if (typeof this.processDataAnimation === 'function') {
                this.processDataAnimation(el, context);
              }
              break;
          }
        });
      }

      // A false data-if detaches its (non-animated) element synchronously and
      // leaves a comment in its place. A TreeWalker cannot climb out of a
      // detached node, so the walk would stop here and every element after it
      // would be skipped — resume from the comment, which also skips the
      // hidden subtree (processDataIf renders it when it is shown again)
      const ifBinding = el._ifBinding;
      if (ifBinding && ifBinding.condition === false && !el.parentNode) {
        if (el === element || !ifBinding.comment?.parentNode || !element.contains(ifBinding.comment)) {
          break;
        }
        walker.currentNode = ifBinding.comment;
      }
      el = walker.nextNode();
    }

    // Process deferred data-attr directives AFTER options-key, files, etc.
    // This ensures select options are populated before values are set
    deferredAttrDirectives.forEach(({el, value}) => {
      this.processDataAttr(el, value, context);
    });

    // Process data-for elements AFTER other directives
    // This ensures template content is not pre-processed with wrong context
    dataForElements.forEach(({el, value}) => {
      this.processDataFor(el, value, context);
    });

    if (context.id && element.dataset?.parentContext) {
      element.dataset.parentContext = context.id;
    }
  },

  /**
   * Applies data-text: evaluates the expression, runs it through the formatters
   * from the expression and from data-format, data-formatter and
   * data-formatters, and writes the result as the element's text.
   *
   * @param {HTMLElement} el - Element carrying data-text
   * @param {string} expression - Expression, possibly with | formatters
   * @param {Object} context - {state, methods, ...} to evaluate against
   * @returns {void}
   */
  processDataText(el, expression, context) {
    if (!el || !expression || !context) return;

    try {
      const parts = this._splitTextExpressionAndFormatters(expression);
      const parsedExpression = parts.length > 0 ? parts[0] : expression;
      const parsedFormatters = parts.length > 1 ? parts.slice(1) : [];

      const attrFormatters = [];
      if (el.dataset.format) {
        attrFormatters.push(el.dataset.format);
      }
      if (el.dataset.formatter) {
        attrFormatters.push(el.dataset.formatter);
      }
      if (el.dataset.formatters) {
        attrFormatters.push(
          ...el.dataset.formatters
            .split(',')
            .map(f => f.trim())
            .filter(Boolean)
        );
      }

      if (!el._textBinding) {
        el._textBinding = {
          expression: parsedExpression,
          originalState: this.deepClone(context.state),
          originalContext: {...context},
          formatters: [...parsedFormatters, ...attrFormatters],
          lastValue: null
        };
      } else {
        el._textBinding.expression = parsedExpression;
        el._textBinding.formatters = [...parsedFormatters, ...attrFormatters];
        this.syncDirectiveBinding(el._textBinding, context);
      }

      const updateText = () => {
        if (!el) return;

        try {
          const currentText = el.textContent?.trim();
          const binding = el._textBinding;
          const ctx = this.getDirectiveContext(binding, context);
          const evalState = this.getDirectiveEvalState(binding, context, {
            mergeLiveState: true
          });

          let value = ExpressionEvaluator.evaluate(binding.expression, evalState, ctx);

          if (binding.formatters.length > 0) {
            value = Utils.string.applyFormatters(value, binding.formatters, ctx);
          }

          // Translate {LNG_xxx} patterns if present — unless the element opts out
          // with translate="no" (the value is user data that must show verbatim)
          if (typeof value === 'string' && value.includes('{LNG_') && window.I18nManager?.translate
            && !(typeof I18nManager.isExcluded === 'function' && I18nManager.isExcluded(el))) {
            value = I18nManager.translate(value);
          }

          const newText = this.valueToString(value);

          if (newText === currentText) {
            return;
          }

          el.textContent = newText;
          el._textBinding.lastValue = newText;

        } catch (error) {
          ErrorManager.handle(error, {
            context: 'TemplateManager.processDataText',
            data: {el, expression, context}
          });
          el.textContent = '';
        }
      };

      updateText();

      // Store update function and {LNG_} flag for locale:changed re-translation
      el._textBinding.update = updateText;
      el._textBinding.hasLng = expression.includes('{LNG_');

      this.setupReactiveUpdate(el, context, 'Text', updateText);

    } catch (error) {
      ErrorManager.handle(error, {
        context: 'TemplateManager.processDataText',
        data: {el, expression, context}
      });
      el.textContent = '';
    }
  },

  /**
   * Applies data-html: evaluates the expression and writes the result as the
   * element's markup, sanitized first.
   *
   * The binding is kept on the element so a later state change can update it.
   *
   * @param {HTMLElement} el - Element carrying data-html
   * @param {string} value - Expression producing the markup
   * @param {Object} context - {state, methods, ...} to evaluate against
   * @returns {void}
   */
  processDataHtml(el, value, context) {
    if (!el || !value || !context) return;

    try {
      if (!el._htmlBinding) {
        el._htmlBinding = {
          value,
          originalState: this.deepClone(context.state),
          originalContext: {...context},
          lastHtml: null
        };
      } else {
        el._htmlBinding.value = value;
        this.syncDirectiveBinding(el._htmlBinding, context);
      }
      const updateDataHtml = () => {
        const getHtmlContent = () => {
          const binding = el._htmlBinding;
          const ctx = this.getDirectiveContext(binding, context);
          const evalState = this.getDirectiveEvalState(binding, context, {
            mergeLiveState: true
          });

          return ExpressionEvaluator.evaluate(binding.value, evalState, ctx);
        };

        const updateHtml = (content) => {
          if (content === null || content === undefined) {
            el.innerHTML = '';
            return;
          }

          const bindingContext = this.getDirectiveContext(el._htmlBinding, context);

          content = String(content);

          if (content === el._htmlBinding.lastHtml) return;

          if (this.config.security.sanitize) {
            const sanitized = this.sanitizeElement(content);
            if (typeof sanitized === 'string') {
              content = sanitized;
            }
          }

          el.innerHTML = content;
          el._htmlBinding.lastHtml = content;

          this.processDataDirectives(el, bindingContext);
          this.processInterpolation(el, bindingContext);
          this.scheduleI18nUpdate(el);
        };

        updateHtml(getHtmlContent());
      };

      updateDataHtml();

      this.setupReactiveUpdate(el, context, 'Html', updateDataHtml);

    } catch (error) {
      ErrorManager.handle(error, {
        context: 'TemplateManager.processDataHtml',
        data: {el, value, context}
      });
      el.innerHTML = '';
    }
  },

  /**
   * Applies data-if: keeps the element in the DOM while the expression is
   * truthy and replaces it with a placeholder comment when it is not, so it can
   * be put back in the same place.
   *
   * @param {HTMLElement} el - Element carrying data-if
   * @param {string} expression - Condition to evaluate
   * @param {Object} context - {state, methods, ...} to evaluate against
   * @returns {void}
   */
  processDataIf(el, expression, context) {
    if (!el || !expression || !context) return;

    try {
      if (!el._ifBinding) {
        el._ifBinding = {
          expression,
          originalState: this.deepClone(context.state),
          originalContext: {...context},
          parent: el.parentNode,
          nextSibling: el.nextSibling,
          comment: document.createComment(`if: ${expression}`),
          condition: true,
          isAnimating: false,
          listeners: new Set(),
          cleanup: null
        };

        el._ifBinding.originalStyles = {
          display: window.getComputedStyle(el).display,
          animationName: window.getComputedStyle(el).animationName
        };

        if (el.classList.contains('animated')) {
          el._ifBinding.animated = true;
        }
      } else {
        el._ifBinding.expression = expression;
        this.syncDirectiveBinding(el._ifBinding, context);
      }

      const binding = el._ifBinding;

      const evaluateCondition = () => {
        const ctx = this.getDirectiveContext(binding, context);
        const evalState = this.getDirectiveEvalState(binding, context, {
          mergeLiveState: true
        });
        return !!ExpressionEvaluator.evaluate(binding.expression, evalState, ctx);
      };

      const cleanupElement = (element, preserveIfBinding = false) => {
        this.cleanupElementBindings(element, {
          skipBindings: preserveIfBinding ? ['_ifBinding'] : [],
          skipReactiveTypes: preserveIfBinding ? ['If'] : []
        });

        /*
         * `data-component="api"` tracks its own instances under `_apiComponent`
         * (`ApiComponent.destroy`), never under `component.instances` — a
         * `data-if` that hides one must abort its in-flight request through
         * that same path, not a `componentManager.destroyComponent(componentId)`
         * call that never matched anything (`dataset.componentId` is never set
         * by anything in this codebase, and `destroyComponent` isn't even a
         * method `ComponentManager` has — this whole block used to be a no-op
         * for every component type, api or not). Mirrors the equivalent
         * `CoreObserver.onRemove` cleanup in `ComponentManager.setupCoreObserver`.
         */
        if (element.dataset?.component === 'api' && element._apiComponent && window.ApiComponent) {
          window.ApiComponent.destroy(element._apiComponent);
        } else if (element.dataset?.table && window.TableManager) {
          // `data-table` is a separate component system with its own instance
          // registry (`TableManager.state.tables`, keyed by `dataset.table`) —
          // not `[data-component]` at all, so neither branch above ever touches it
          const registeredTable = window.TableManager.state?.tables?.get(element.dataset.table);
          if (registeredTable && registeredTable.element === element) {
            window.TableManager.destroyTable(element.dataset.table);
          }
        } else {
          const componentManager = Now.getManager('component');
          if (componentManager?.instances?.has(element)) {
            componentManager.destroy(element);
          }
        }

        Array.from(element.children).forEach(child => {
          cleanupElement(child, false);
        });
      };

      const handleAnimation = (element, showing) => {
        return new Promise(resolve => {
          if (!binding.animated) {
            resolve();
            return;
          }

          binding.isAnimating = true;

          const animationClasses = showing ?
            ['fade-in', 'animate-in'] :
            ['fade-out', 'animate-out'];

          element.classList.add(...animationClasses);

          const cleanup = () => {
            element.classList.remove(...animationClasses);
            element.removeEventListener('animationend', onAnimationEnd);
            binding.isAnimating = false;
            resolve();
          };

          const onAnimationEnd = (e) => {
            if (e.target === element) {
              cleanup();
            }
          };

          element.addEventListener('animationend', onAnimationEnd);

          setTimeout(cleanup, 1000);
        });
      };

      const updateVisibility = async () => {
        const shouldShow = evaluateCondition();

        if (shouldShow === binding.condition && !binding.isAnimating) {
          return;
        }

        try {
          if (shouldShow) {
            if (!el.parentNode) {
              if (!binding.parent) {
                binding.parent = document.body;
              }

              if (binding.comment && binding.nextSibling) {
                binding.parent.insertBefore(binding.comment, binding.nextSibling);
              } else {
                binding.parent.appendChild(binding.comment);
              }

              if (binding.nextSibling) {
                binding.parent.insertBefore(el, binding.comment);
              } else {
                binding.parent.appendChild(el);
              }

              // Only animated elements wait — see the hide branch below
              if (binding.animated) {
                await handleAnimation(el, true);
              }

              el.style.display = binding.originalStyles.display;

              const renderContext = this.getDirectiveContext(binding, context);
              this.processDataDirectives(el, renderContext);
              this.processInterpolation(el, renderContext);

              const componentManager = Now.getManager('component');
              if (componentManager) {
                componentManager.initializeExistingElements();
              }

              // Scan newly inserted node subtree for element/form enhancements
              try {
                const elementManager = Now.getManager('element');
                const formManager = Now.getManager('form');

                if (elementManager && typeof elementManager.scan === 'function') {
                  elementManager.scan(el);
                }

                if (formManager && typeof formManager.scan === 'function') {
                  formManager.scan(el);
                }
              } catch (err) {
                ErrorManager.handle('processDataIf.postInsertScan failed', {
                  context: 'TemplateManager.processDataIf',
                  data: {error: err}
                });
              }

              if (binding.comment && binding.comment.parentNode) {
                binding.comment.parentNode.removeChild(binding.comment);
              }
            }
          } else {
            if (el.parentNode) {
              /*
               * Remove a non-animated element synchronously. data-for appends the
               * clone to the document *before* it processes data-if, which queues
               * ComponentManager's MutationObserver callback; awaiting even an
               * already-resolved promise here let that callback run first, so a
               * `data-component` inside a false data-if (e.g. a dashboard graph
               * slot in a table or calendar block) was created and fetched its
               * data-url before being removed ("Invalid series" in the console).
               */
              if (binding.animated) {
                await handleAnimation(el, false);
              }

              cleanupElement(el, true);

              binding.parent = el.parentNode;
              binding.nextSibling = el.nextSibling;

              if (binding.parent) {
                if (binding.comment) {
                  try {
                    binding.parent.insertBefore(binding.comment, el.nextSibling);
                  } catch (err) {
                    binding.parent.appendChild(binding.comment);
                  }
                }
                el.remove();
              }
            }
          }

          binding.condition = shouldShow;
          EventManager.emit('if:updated', {
            element: el,
            condition: shouldShow,
            expression: expression
          });

        } catch (error) {
          ErrorManager.handle(error, {
            context: 'TemplateManager.processDataIf',
            type: 'error:template',
            data: {expression, el}
          });
        }
      };

      updateVisibility();

      this.setupReactiveUpdate(el, context, 'If', updateVisibility);

      binding.cleanup = () => {
        this.cleanupElementBindings(el);
      };

    } catch (error) {
      ErrorManager.handle(error, {
        context: 'TemplateManager.processDataIf',
        type: 'error:template',
        data: {expression, el}
      });
    }
  },

  /**
   * Applies data-class: evaluates the expression and toggles the classes it
   * names, leaving the classes written in the markup alone.
   *
   * @param {HTMLElement} el - Element carrying data-class
   * @param {string} value - Expression producing a string, array or condition map
   * @param {Object} context - {state, methods, ...} to evaluate against
   * @returns {void}
   */
  processDataClass(el, value, context) {
    if (!el || !value || !context) return;

    try {
      if (!el._classBinding) {
        el._classBinding = {
          value,
          originalState: this.deepClone(context.state),
          originalContext: {...context}
        };
      } else {
        el._classBinding.value = value;
        this.syncDirectiveBinding(el._classBinding, context);
      }

      // Detect mode based on value format:
      // 1. Ternary mode: "condition ? 'classA' : 'classB'" - switch between two classes
      // 2. Binding mode: "className:expression" - toggle class based on condition
      // 3. Expression mode: "data.icon" or "data.icon + ' extra'" - add class from expression

      const hasTernaryClassSwitch = /[\s]+\?[\s]*['"][^'"]+['"][\s]*:[\s]*['"][^'"]+['"]/.test(value);
      const hasBindingColon = value.includes(':') && !hasTernaryClassSwitch;

      // Mode 1: Ternary class switch - "condition ? 'classTrue' : 'classFalse'"
      if (hasTernaryClassSwitch) {
        const ternary = /(.+?)[\s]+\?[\s]*['"]([^'"]+)['"][\s]*:[\s]*['"]([^'"]+)['"]/.exec(value);
        if (ternary) {
          const updateClassTernary = () => {
            try {
              const ctx = this.getDirectiveContext(el._classBinding, context);
              const evalState = this.getDirectiveEvalState(el._classBinding, context, {
                mergeLiveState: true
              });
              let shouldApply = ExpressionEvaluator.evaluate(ternary[1].trim(), evalState, ctx);

              if (shouldApply) {
                el.classList.add(ternary[2]);
                el.classList.remove(ternary[3]);
              } else {
                el.classList.remove(ternary[2]);
                el.classList.add(ternary[3]);
              }

            } catch (error) {
              ErrorManager.handle(`Error evaluating class ternary "${value}"`, {
                context: 'TemplateManager.processDataClass',
                data: {el, value, context}
              });
            }
          };

          updateClassTernary();
          this.setupReactiveUpdate(el, context, 'Class', updateClassTernary);
          return;
        }
      }

      // Mode 2: Binding mode - "className:expression, className2:expression2"
      if (hasBindingColon) {
        const bindings = this._splitAttrBindings(value);
        const classUpdates = [];

        bindings.forEach(binding => {
          try {
            const updateClass = () => {
              const parts = this._splitBinding(binding);
              if (!parts) return;

              const {attrName: className, expression} = parts;

              if (!className || !expression) {
                ErrorManager.handle(`Invalid data-class binding: '${binding}'. Expected format "className:expression"`, {
                  context: 'TemplateManager.processDataClass',
                  data: {el, value, context},
                  logLevel: 'warn'
                });
                return;
              }

              const ctx = this.getDirectiveContext(el._classBinding, context);
              const evalState = this.getDirectiveEvalState(el._classBinding, context, {
                mergeLiveState: true
              });
              let shouldApply = ExpressionEvaluator.evaluate(expression, evalState, ctx);

              if (shouldApply) {
                el.classList.add(className);
              } else {
                el.classList.remove(className);
              }
            };

            updateClass();
            classUpdates.push(updateClass);

          } catch (error) {
            ErrorManager.handle(error, {
              context: 'TemplateManager.processDataClass',
              data: {el, value, context, binding}
            });
          }
        });

        if (classUpdates.length > 0) {
          this.setupReactiveUpdate(el, context, 'Class', () => {
            classUpdates.forEach(update => update());
          });
        }

        return;
      }

      // Mode 3: Expression mode - "data.icon" or "data.icon + ' extra-class'"
      // Evaluates expression and adds resulting class(es) to element
      const updateClassExpression = () => {
        try {
          const ctx = this.getDirectiveContext(el._classBinding, context);
          const evalState = this.getDirectiveEvalState(el._classBinding, context, {
            mergeLiveState: true
          });
          let classValue = ExpressionEvaluator.evaluate(el._classBinding.value || value, evalState, ctx);

          // Remove previously added classes from this expression
          if (el._classBinding.addedClasses) {
            el._classBinding.addedClasses.forEach(cls => {
              if (cls) el.classList.remove(cls);
            });
          }

          // Add new classes if value exists
          if (classValue) {
            const classes = String(classValue).split(/\s+/).filter(cls => cls);
            classes.forEach(cls => el.classList.add(cls));
            el._classBinding.addedClasses = classes;
          } else {
            el._classBinding.addedClasses = [];
          }

        } catch (error) {
          ErrorManager.handle(`Error evaluating class expression "${value}"`, {
            context: 'TemplateManager.processDataClass',
            data: {el, value, context}
          });
        }
      };

      updateClassExpression();
      this.setupReactiveUpdate(el, context, 'Class', updateClassExpression);

    } catch (error) {
      ErrorManager.handle(error, {
        context: 'TemplateManager.processDataClass',
        data: {el, value, context},
      });
    }
  },

  /**
   * Split a data-attr / data-class binding string on top-level commas only,
   * skipping commas and colons that appear inside single or double-quoted string literals.
   * e.g. "placeholder:'{LNG_Hello, world}' + x, href:'http://x.com', value:y"
   *   → ["placeholder:'{LNG_Hello, world}' + x", "href:'http://x.com'", "value:y"]
   */
  _splitAttrBindings(value) {
    const result = [];
    let current = '';
    let inSingle = false;
    let inDouble = false;
    for (let i = 0; i < value.length; i++) {
      const ch = value[i];
      if (ch === "'" && !inDouble) {
        inSingle = !inSingle;
        current += ch;
      } else if (ch === '"' && !inSingle) {
        inDouble = !inDouble;
        current += ch;
      } else if (ch === ',' && !inSingle && !inDouble) {
        const trimmed = current.trim();
        if (trimmed) result.push(trimmed);
        current = '';
      } else {
        current += ch;
      }
    }
    const trimmed = current.trim();
    if (trimmed) result.push(trimmed);
    return result;
  },

  /**
   * Split a single binding "attrName:expression" on the FIRST colon
   * that is outside any quoted string literal, so URLs like 'http://x'
   * inside the expression are not broken.
   * Returns {attrName, expression} or null if no valid colon found.
   */
  _splitBinding(binding) {
    let inSingle = false;
    let inDouble = false;
    for (let i = 0; i < binding.length; i++) {
      const ch = binding[i];
      if (ch === "'" && !inDouble) {inSingle = !inSingle; continue;}
      if (ch === '"' && !inSingle) {inDouble = !inDouble; continue;}
      if (ch === ':' && !inSingle && !inDouble) {
        return {
          attrName: binding.substring(0, i).trim(),
          expression: binding.substring(i + 1).trim()
        };
      }
    }
    return null;
  },

  /**
   * Snapshots the state a directive binding was created with, falling back to a
   * shallow clone when a deep one fails, such as on a value with cycles the
   * cloner cannot follow.
   *
   * @param {Object} state - State to snapshot
   * @param {string} [mode='deep'] - 'deep' or 'shallow'
   * @returns {Object} The snapshot
   */
  cloneDirectiveState(state, mode = 'deep') {
    if (!state || typeof state !== 'object') {
      return state;
    }

    if (mode === 'shallow') {
      return this.cloneState(state);
    }

    try {
      return this.deepClone(state);
    } catch (error) {
      return this.cloneState(state);
    }
  },

  /**
   * Merges the snapshot a binding holds with a newer one, keeping either whole
   * when one is missing and when either is an array or not an object.
   *
   * @param {Object} previousState - Snapshot the binding holds
   * @param {Object} nextState - Newer snapshot
   * @returns {Object} Merged state
   */
  mergeDirectiveState(previousState, nextState) {
    if (nextState === undefined) {
      return previousState;
    }

    if (previousState === undefined) {
      return nextState;
    }

    if (
      Array.isArray(previousState) ||
      Array.isArray(nextState) ||
      !previousState ||
      !nextState ||
      typeof previousState !== 'object' ||
      typeof nextState !== 'object'
    ) {
      return nextState;
    }

    return {
      ...previousState,
      ...nextState
    };
  },

  /**
   * Merges the context a binding was created with into the current one, so a
   * re-render keeps the methods and computed values the binding was written
   * against.
   *
   * @param {Object} previousContext - Context the binding holds
   * @param {Object} context - Current context
   * @returns {Object} Merged context
   */
  mergeDirectiveContext(previousContext, context) {
    if (!previousContext) {
      return {...context};
    }

    const merged = {
      ...previousContext,
      ...context
    };

    if (previousContext.computed || context.computed) {
      merged.computed = {
        ...(previousContext.computed || {}),
        ...(context.computed || {})
      };
    }

    if (previousContext.methods || context.methods) {
      merged.methods = {
        ...(previousContext.methods || {}),
        ...(context.methods || {})
      };
    }

    if (
      (previousContext.options && typeof previousContext.options === 'object' && !Array.isArray(previousContext.options)) ||
      (context.options && typeof context.options === 'object' && !Array.isArray(context.options))
    ) {
      merged.options = {
        ...(previousContext.options || {}),
        ...(context.options || {})
      };
    } else if (context.options !== undefined || previousContext.options !== undefined) {
      merged.options = context.options !== undefined ? context.options : previousContext.options;
    }

    return merged;
  },

  /**
   * Brings the snapshots stored on a binding up to date with the current
   * context, so a directive re-evaluated later sees current data.
   *
   * @param {Object} binding - Binding stored on the element
   * @param {Object} context - Current context
   * @param {Object} [options={}] - Sync options
   * @param {string} [options.stateMode='deep'] - Clone mode for the state
   * @param {boolean} [options.preserveState=true] - Merge rather than replace the state
   * @param {boolean} [options.preserveContext=true] - Merge rather than replace the context
   * @returns {Object} The binding
   */
  syncDirectiveBinding(binding, context, options = {}) {
    if (!binding || !context) {
      return binding;
    }

    const {
      stateMode = 'deep',
      preserveState = true,
      preserveContext = true
    } = options;

    const nextState = this.cloneDirectiveState(context.state, stateMode);
    binding.originalState = preserveState
      ? this.mergeDirectiveState(binding.originalState, nextState)
      : nextState;
    binding.originalContext = preserveContext
      ? this.mergeDirectiveContext(binding.originalContext, context)
      : {...context};

    return binding;
  },

  /**
   * Returns the context a binding should be evaluated in.
   *
   * @param {Object} binding - Binding stored on the element
   * @param {Object} context - Current context
   * @returns {Object} Merged context
   */
  getDirectiveContext(binding, context) {
    return this.mergeDirectiveContext(binding?.originalContext, context || {});
  },

  /**
   * Builds the object a binding's expression is evaluated against: its snapshot
   * merged with the live state, plus the methods and options when asked for.
   *
   * @param {Object} binding - Binding stored on the element
   * @param {Object} context - Current context
   * @param {Object} [options={}] - Build options
   * @param {boolean} [options.mergeLiveState=true] - Merge the current state in
   * @param {boolean} [options.includeMethods=false] - Expose the context methods
   * @param {boolean} [options.includeOptions=false] - Expose the context options
   * @returns {Object} Evaluation scope
   */
  getDirectiveEvalState(binding, context, options = {}) {
    const {
      mergeLiveState = true,
      includeMethods = false,
      includeOptions = false
    } = options;

    const ctx = this.getDirectiveContext(binding, context);
    const originalState = binding?.originalState && typeof binding.originalState === 'object'
      ? binding.originalState
      : {};
    const liveState = mergeLiveState && context?.state && typeof context.state === 'object'
      ? context.state
      : {};
    const computed = ctx.computed && typeof ctx.computed === 'object'
      ? ctx.computed
      : {};
    const methods = includeMethods && ctx.methods && typeof ctx.methods === 'object'
      ? ctx.methods
      : {};
    const extraOptions = includeOptions && ctx.options && typeof ctx.options === 'object' && !Array.isArray(ctx.options)
      ? {options: ctx.options}
      : {};

    return {
      ...originalState,
      ...liveState,
      ...computed,
      ...methods,
      ...extraOptions
    };
  },

  /**
   * Parses a data-model value into its path and its trailing modifiers, so
   * 'form.email.trim' binds to form.email with trim on.
   *
   * @param {Object} binding - Binding to fill in
   * @param {string} value - Value of data-model
   * @returns {Object} The binding, with path and modifiers set
   */
  applyModelBindingDefinition(binding, value) {
    if (!binding) return binding;

    binding.modifiers = {
      lazy: false,
      number: false,
      trim: false
    };

    const knownModifiers = Object.keys(binding.modifiers);
    const parts = String(value).split('.');

    while (parts.length > 1 && knownModifiers.includes(parts[parts.length - 1])) {
      const mod = parts.pop();
      binding.modifiers[mod] = true;
    }

    binding.value = parts.join('.');
    return binding;
  },

  /**
   * Applies data-attr: evaluates each binding and writes it as an attribute,
   * removing the attribute when the value is false, null or undefined.
   *
   * URL attributes go through sanitizeUrlAttribute() first.
   *
   * @param {HTMLElement} el - Element carrying data-attr
   * @param {string} value - Bindings as "name: expression" pairs
   * @param {Object} context - {state, methods, ...} to evaluate against
   * @returns {void}
   */
  processDataAttr(el, value, context) {
    if (!el || !value || !context) return;

    try {
      if (!el._attrBinding) {
        el._attrBinding = {
          value,
          originalState: this.deepClone(context.state),
          originalContext: {...context},
          hasLng: value.includes('{LNG_'),
          updates: [],
        };
      } else {
        el._attrBinding.value = value;
        el._attrBinding.hasLng = value.includes('{LNG_');
        this.syncDirectiveBinding(el._attrBinding, context);
      }

      el._attrBinding.updates = [];

      const bindings = this._splitAttrBindings(value);
      const attrUpdates = [];
      bindings.forEach(binding => {
        try {
          const updateAttr = () => {
            // Split on first top-level colon (skipping colons inside string literals, e.g. URLs)
            const parts = this._splitBinding(binding);
            if (!parts) {
              ErrorManager.handle(`Invalid attribute binding: ${binding}`, {
                context: 'TemplateManager.processDataAttr',
                data: {el, value, context},
                logLevel: 'warn'
              });
              return;
            }
            const {attrName, expression} = parts;

            if (!attrName || !expression) {
              ErrorManager.handle(`Invalid attribute binding: ${binding}`, {
                context: 'TemplateManager.processDataAttr',
                data: {el, value, context},
                logLevel: 'warn'
              });
              return;
            }

            if (!/^[a-zA-Z0-9\-_]+$/.test(attrName)) {
              ErrorManager.handle(`Invalid attribute name: ${attrName}`, {
                context: 'TemplateManager.processDataAttr',
                data: {el, value, context},
                logLevel: 'warn'
              });
              return;
            }

            // Delegate "data:fieldName" to processDataBind (table/LineItems binding)
            if (attrName === 'data') {
              this.processDataBind(el, expression, this.getDirectiveContext(el._attrBinding, context));
              return;
            }

            const ctx = this.getDirectiveContext(el._attrBinding, context);
            const evalScope = this.getDirectiveEvalState(el._attrBinding, context, {
              mergeLiveState: true,
              includeMethods: true
            });

            // Normalize expressions containing template placeholders (e.g., attribute_options[{{attr.id}}])
            // Use String() for all segments so paths stay valid (JSON.stringify breaks numeric keys in brackets)
            let normalizedExpression = expression;
            if (normalizedExpression.includes('{{')) {
              normalizedExpression = normalizedExpression.replace(/\{\{(.+?)\}\}/g, (match, expr) => {
                try {
                  const evaluated = ExpressionEvaluator.evaluate(expr.trim(), evalScope, ctx);
                  return evaluated == null ? '' : String(evaluated);
                } catch (e) {
                  return '';
                }
              });
            }

            let attrValue;

            if ((attrName === 'value' || attrName === 'data') && typeof normalizedExpression === 'string') {
              const trimmedExpression = normalizedExpression.trim();
              const isPureBracketPath =
                ExpressionEvaluator.bracketAccessRegex.test(trimmedExpression) &&
                !/[+\-*/%()?:]|===|!==|==|!=|&&|\|\|/.test(trimmedExpression);

              if (isPureBracketPath) {
                attrValue = ExpressionEvaluator.resolveBracketAccess(trimmedExpression, evalScope, ctx);

                if (attrValue === undefined && ctx?.options && typeof ctx.options === 'object') {
                  attrValue = ExpressionEvaluator.resolveBracketAccess(trimmedExpression, ctx.options, ctx);
                }
              } else {
                attrValue = ExpressionEvaluator.evaluate(trimmedExpression, evalScope, ctx);
              }
            } else {
              attrValue = ExpressionEvaluator.evaluate(normalizedExpression, evalScope, ctx);
            }

            // Translate {LNG_xxx} patterns in string attribute values (not inside translate="no")
            if (typeof attrValue === 'string' && attrValue.includes('{LNG_') && window.I18nManager?.translate
              && !(typeof I18nManager.isExcluded === 'function' && I18nManager.isExcluded(el))) {
              attrValue = I18nManager.translate(attrValue);
            }

            // Check if element has ElementFactory instance with property handlers
            const elementManager = Now.getManager('element');
            const instance = elementManager?.getInstanceByElement(el);

            if (instance && instance.constructor.propertyHandlers && instance.constructor.propertyHandlers[attrName]) {
              // Use ElementFactory property handler
              const handler = instance.constructor.propertyHandlers[attrName];
              if (handler.set) {
                handler.set.call(instance.constructor, instance, attrValue);
              } else {
                console.error(`Property handler for '${attrName}' has no setter`);
              }
            } else {
              // Fallback to standard attribute setting
              const booleanAttrs = [
                'disabled', 'checked', 'readonly', 'required', 'autofocus', 'multiple',
                'novalidate', 'formnovalidate', 'selected', 'hidden', 'open', 'controls',
                'loop', 'muted', 'playsinline', 'reversed', 'scoped', 'async', 'defer'
              ];
              const isBooleanAttr = booleanAttrs.includes(attrName.toLowerCase());

              // Handle 'value' attribute specially for form elements
              if (attrName === 'value' && (el.tagName === 'INPUT' || el.tagName === 'SELECT' || el.tagName === 'TEXTAREA')) {
                // Skip file inputs - browser security prohibits setting value
                // Use data-files attribute instead for existing files
                if (el.type === 'file') {
                  ErrorManager.handle('Cannot set value on file input. Use data-files attribute for existing files.', {
                    context: 'TemplateManager.processDataAttr',
                    data: {el, attrName, expression},
                    logLevel: 'warn'
                  });
                  return;
                }
                if (el.tagName === 'SELECT') {
                  if (attrValue != null) {
                    const valueStr = String(attrValue);
                    el.value = valueStr;
                    // Fallback if direct value set failed (e.g., normalized value="" attribute)
                    if (el.value !== valueStr) {
                      const opt = Array.from(el.options).find(o =>
                        (o.hasAttribute('value') ? o.getAttribute('value') : o.text) === valueStr
                      );
                      if (opt) opt.selected = true;
                    }
                  }
                } else {
                  el.value = attrValue ?? '';
                }
              } else if (isBooleanAttr && Utils.string.toBoolean(attrValue)) {
                el.setAttribute(attrName, attrName);
              } else if (!isBooleanAttr && attrValue != null) {
                el.setAttribute(attrName, attrValue);
              } else {
                el.removeAttribute(attrName);
              }
            }
          };

          updateAttr();
          attrUpdates.push(updateAttr);

          // Store update function for locale:changed re-translation
          if (el._attrBinding.hasLng) {
            el._attrBinding.updates.push(updateAttr);
          }

        } catch (error) {
          ErrorManager.handle(error, {
            context: 'TemplateManager.processDataAttr',
            data: {el, value, context, binding}
          });
        }
      });

      if (attrUpdates.length > 0) {
        this.setupReactiveUpdate(el, context, 'Attr', () => {
          attrUpdates.forEach(update => update());
        });
      }

    } catch (error) {
      ErrorManager.handle(error, {
        context: 'TemplateManager.processDataAttr',
        data: {el, value, context}
      });
    }
  },

  /**
   * Applies data-style: evaluates the expression and writes the properties it
   * yields, limited to the ones sanitizeStyles() allows.
   *
   * @param {HTMLElement} el - Element carrying data-style
   * @param {string} value - Expression producing a style object or string
   * @param {Object} context - {state, methods, ...} to evaluate against
   * @returns {void}
   */
  processDataStyle(el, value, context) {
    if (!el || !value || !context) return;
    try {
      if (!el._styleBinding) {
        el._styleBinding = {
          value,
          originalState: this.cloneDirectiveState(context.state, 'shallow'),
          originalContext: {...context},
          lastStyles: null
        };
      } else {
        el._styleBinding.value = value;
        this.syncDirectiveBinding(el._styleBinding, context, {stateMode: 'shallow'});
      }

      const updateStyles = () => {
        try {
          const binding = el._styleBinding;
          const ctx = this.getDirectiveContext(binding, context);
          const evalState = this.getDirectiveEvalState(binding, context, {
            mergeLiveState: true
          });
          const rawValue = binding?.value || value;
          let styles = {};
          let hasUndefined = false;

          if (rawValue.startsWith('{') && rawValue.endsWith('}')) {
            const styleObj = ExpressionEvaluator.evaluate(rawValue, evalState, ctx);
            if (typeof styleObj === 'object') {
              styles = styleObj;
            }
          } else {
            const styleStr = rawValue.replace(/\{\{(.+?)\}\}/g, (match, expr) => {
              const val = ExpressionEvaluator.evaluate(expr, evalState, ctx);
              if (val == null) {
                hasUndefined = true;
                return '';
              }
              return val;
            });

            styles = styleStr.split(';').reduce((acc, style) => {
              // Split only on first colon to preserve URLs (e.g., http://)
              const colonIndex = style.indexOf(':');
              if (colonIndex === -1) return acc;
              const prop = style.substring(0, colonIndex).trim();
              const val = style.substring(colonIndex + 1).trim();
              if (prop && val) {
                const camelProp = prop.replace(/-([a-z])/g, m => m[1].toUpperCase());
                acc[camelProp] = val;
              }
              return acc;
            }, {});
          }

          const safeStyles = this.sanitizeStyles(styles);

          if (hasUndefined && el._styleBinding.lastSafeStyles) {
            const shouldReapply = Object.entries(el._styleBinding.lastSafeStyles).some(([key, val]) => {
              return String(el.style[key] || '') !== String(val);
            });

            if (shouldReapply) {
              Object.assign(el.style, el._styleBinding.lastSafeStyles);
            }
            return;
          }

          const previousSafeStyles = el._styleBinding.lastSafeStyles || {};
          Object.keys(previousSafeStyles).forEach((key) => {
            if (!Object.prototype.hasOwnProperty.call(safeStyles, key)) {
              el.style[key] = '';
            }
          });

          const stylesJson = JSON.stringify(safeStyles);
          if (stylesJson === el._styleBinding.lastStyles) {
            const needsReapply = Object.entries(safeStyles).some(([key, val]) => {
              return String(el.style[key] || '') !== String(val);
            });

            if (!needsReapply) {
              return;
            }
          }

          Object.assign(el.style, safeStyles);

          el._styleBinding.lastStyles = stylesJson;
          el._styleBinding.lastSafeStyles = safeStyles;
        } catch (error) {
          ErrorManager.handle(error, {
            context: 'TemplateManager.processDataStyle',
            data: {el, value: el._styleBinding?.value || value, context: el._styleBinding?.originalContext || context}
          });
        }
      };

      updateStyles();

      this.setupReactiveUpdate(el, context, 'Style', updateStyles);

    } catch (error) {
      ErrorManager.handle(error, {
        context: 'TemplateManager.processDataStyle',
        data: {el, value, context}
      });
    }
  },

  /**
   * Keeps only the style properties on the allow list and drops values that
   * carry an expression or a script URL.
   *
   * @param {Object} styles - Property map, camelCased
   * @returns {Object} The properties that survived
   */
  sanitizeStyles(styles) {
    const allowedProperties = [
      'display', 'position', 'top', 'right', 'bottom', 'left', 'float', 'clear',
      'visibility', 'opacity', 'zIndex', 'overflow', 'clip',
      'width', 'height', 'margin', 'padding', 'border',
      'borderWidth', 'borderStyle', 'borderColor', 'borderRadius',
      'boxShadow', 'boxSizing',
      'textOverflow', 'whiteSpace',
      'maxWidth', 'maxHeight', 'minWidth', 'minHeight',
      'color', 'background', 'backgroundColor', 'backgroundImage',
      'fontSize', 'fontFamily', 'fontWeight', 'textAlign', 'lineHeight',
      'letterSpacing', 'textTransform', 'textDecoration', 'textIndent',
      'flex', 'flexDirection', 'flexWrap', 'flexGrow', 'flexShrink', 'flexBasis',
      'justifyContent', 'alignItems', 'alignContent', 'gap',
      'gridTemplateColumns', 'gridTemplateRows', 'gridColumn', 'gridRow',
      'transform', 'transformOrigin', 'perspective',
      'transition', 'animation',
      'cursor', 'pointerEvents'
    ];

    const safeStyles = {};
    for (const [key, value] of Object.entries(styles)) {
      if (allowedProperties.includes(key)) {
        if (typeof value === 'string' && value.includes('url(')) {
          const urlValue = this.sanitizeUrlValue(value);
          if (urlValue) {
            safeStyles[key] = urlValue;
          }
        } else {
          safeStyles[key] = value;
        }
      }
    }

    return safeStyles;
  },

  /**
   * Sanitize URL values in CSS to prevent XSS attacks
   * Supports: http://, https://, relative paths, and data:image/* (including base64)
   * Blocks: javascript:, data:text/html, and other dangerous protocols
   */
  sanitizeUrlValue(value) {
    if (!value || typeof value !== 'string') return null;

    // Extract URL from url() function
    const urlMatch = /url\(['"]?([^'"]*)['"]?\)/.exec(value);
    if (!urlMatch || !urlMatch[1]) return null;

    const url = urlMatch[1].trim();
    if (!url) return null;

    // Check for dangerous protocols
    const dangerousProtocols = ['javascript:', 'data:text/', 'vbscript:', 'file:', 'about:'];
    const lowerUrl = url.toLowerCase();

    for (const protocol of dangerousProtocols) {
      if (lowerUrl.startsWith(protocol)) {
        ErrorManager.handle(`Blocked dangerous protocol in URL: ${protocol}`, {
          context: 'TemplateManager.sanitizeUrlValue',
          data: {url},
          logLevel: 'warn'
        });
        return null;
      }
    }

    // Allow data:image/* URLs (including base64)
    if (lowerUrl.startsWith('data:')) {
      // Validate that it's an image mime type
      const imageMimeTypes = ['data:image/png', 'data:image/jpeg', 'data:image/jpg',
        'data:image/gif', 'data:image/svg+xml', 'data:image/webp',
        'data:image/bmp', 'data:image/ico', 'data:image/x-icon'];

      const isValidImage = imageMimeTypes.some(mime => lowerUrl.startsWith(mime));

      if (!isValidImage) {
        ErrorManager.handle('Blocked non-image data URL', {
          context: 'TemplateManager.sanitizeUrlValue',
          data: {url: url.substring(0, 50) + '...'},
          logLevel: 'warn'
        });
        return null;
      }

      // Validate base64 encoding if present
      if (lowerUrl.includes(';base64,')) {
        const base64Part = url.split(';base64,')[1];
        if (base64Part) {
          // Basic validation: check if it looks like valid base64
          if (!/^[A-Za-z0-9+/]+=*$/.test(base64Part.substring(0, Math.min(100, base64Part.length)))) {
            ErrorManager.handle('Invalid base64 encoding in data URL', {
              context: 'TemplateManager.sanitizeUrlValue',
              data: {url: url.substring(0, 50) + '...'},
              logLevel: 'warn'
            });
            return null;
          }
        }
      }

      return `url(${url})`;
    }

    // Allow http:// and https:// URLs
    if (lowerUrl.startsWith('http://') || lowerUrl.startsWith('https://')) {
      try {
        const urlObj = new URL(url);
        // Additional validation: ensure it's really http/https
        if (urlObj.protocol !== 'http:' && urlObj.protocol !== 'https:') {
          return null;
        }
        return `url(${url})`;
      } catch (e) {
        ErrorManager.handle('Invalid absolute URL', {
          context: 'TemplateManager.sanitizeUrlValue',
          data: {url},
          logLevel: 'warn'
        });
        return null;
      }
    }

    // Allow relative URLs (no protocol)
    if (!lowerUrl.includes(':')) {
      // Block path traversal attempts
      if (url.includes('../') || url.includes('..\\')) {
        ErrorManager.handle('Blocked path traversal in URL', {
          context: 'TemplateManager.sanitizeUrlValue',
          data: {url},
          logLevel: 'warn'
        });
        return null;
      }

      // Basic validation: should look like a valid path
      if (!/^[a-zA-Z0-9\/_\-\.]+(\?[a-zA-Z0-9=&_\-]*)?$/.test(url)) {
        ErrorManager.handle('Invalid relative URL format', {
          context: 'TemplateManager.sanitizeUrlValue',
          data: {url},
          logLevel: 'warn'
        });
        return null;
      }

      return `url(${url})`;
    }

    // Block everything else
    ErrorManager.handle('Blocked unrecognized URL format', {
      context: 'TemplateManager.sanitizeUrlValue',
      data: {url},
      logLevel: 'warn'
    });
    return null;
  },

  /**
   * Applies data-for: renders the element once per item of the collection, each
   * clone getting the loop variable in its own scope.
   *
   * @param {HTMLElement} el - Element carrying data-for
   * @param {string} value - Expression of the form "item in collection"
   * @param {Object} context - {state, methods, ...} to evaluate against
   * @returns {void}
   */
  processDataFor(el, value, context) {
    if (!el || !value || !context) return;

    const matches = value.match(/(\w+)\s+(?:in|of)\s+(.+)/);
    if (!matches) {
      ErrorManager.handle(`Invalid data-for expression: ${value}`, {
        context: 'TemplateManager.processDataFor',
        data: {el, value, context},
        logLevel: 'warn'
      });
      return;
    }

    try {
      const [_, itemName, arrayExpr] = matches;

      if (!el._forBinding) {
        el._forBinding = {
          value,
          itemName,
          arrayExpr,
          originalState: this.deepClone(context.state),
          originalContext: {...context},
          lastHash: null
        };
      } else {
        el._forBinding.value = value;
        el._forBinding.itemName = itemName;
        el._forBinding.arrayExpr = arrayExpr;
        this.syncDirectiveBinding(el._forBinding, context);
      }

      const updateList = () => {
        try {
          const binding = el._forBinding;
          const ctx = this.getDirectiveContext(binding, context);
          const evalState = this.getDirectiveEvalState(binding, context, {
            mergeLiveState: true
          });
          let array = ExpressionEvaluator.evaluate(binding.arrayExpr, evalState, ctx);

          if (!Array.isArray(array)) return;

          let currentHash = null;
          try {
            currentHash = JSON.stringify(array);
          } catch (_) {
            currentHash = null;
          }

          if (currentHash !== null && el._forBinding.lastHash === currentHash) {
            return;
          }

          const templateElement = el.querySelector('template');
          if (!templateElement) {
            ErrorManager.handle('No template found in data-for element', {
              context: 'TemplateManager.processDataFor',
              data: {el, value, context},
              logLevel: 'warn'
            });
            return;
          }

          // Cleanup bindings/handlers on child nodes before removing them to prevent memory leaks
          Array.from(el.childNodes).forEach(child => {
            if (child !== templateElement) {
              if (child.nodeType === Node.ELEMENT_NODE) {
                this.cleanupElement(child);
              }
              el.removeChild(child);
            }
          });

          const elementMgr = window.Now?.getManager?.('element');

          array.forEach((item, index) => {
            const childContext = {
              ...ctx,
              state: {
                ...(ctx.state || context.state),
                [binding.itemName]: item,
                index
              },
              computed: ctx.computed,
              methods: ctx.methods,
              reactive: ctx.reactive ?? context.reactive,
              _updateQueue: context._updateQueue
            };

            const clone = templateElement.content.cloneNode(true);

            // Interpolate FIRST so element ids/names are resolved (e.g. {{lng}} → "en")
            // before processDataAttr runs. This ensures getInstanceByElement can find the
            // enhanced instance, so value.set is used directly — same as the non-data-for path.
            this.processInterpolation(clone, childContext);

            // Capture references to fragment children before appendChild empties the fragment
            const cloneChildren = Array.from(clone.childNodes);

            // Append to DOM so elements are connected
            el.appendChild(clone);

            // Enhance newly added elements immediately so property handlers are available
            if (elementMgr?.scan) {
              cloneChildren.forEach(node => {
                if (node.nodeType === Node.ELEMENT_NODE) elementMgr.scan(node);
              });
            }

            // Now process directives: elements are in DOM and enhanced,
            // so getInstanceByElement succeeds and value.set is called directly.
            cloneChildren.forEach(node => {
              if (node.nodeType === Node.ELEMENT_NODE) this.processDataDirectives(node, childContext);
            });
          });

          // Re-translate newly injected nodes after data-for renders (debounced)
          this.scheduleI18nUpdate(el);

          el._forBinding.lastHash = currentHash;
        } catch (error) {
          ErrorManager.handle(error, {
            context: 'TemplateManager.processDataFor',
            type: 'error:template',
            data: {el, value}
          });
        }
      };

      updateList();

      this.setupReactiveUpdate(el, context, 'For', updateList);

    } catch (error) {
      ErrorManager.handle(error, {
        context: 'TemplateManager.processDataFor',
        type: 'error:template',
        data: {el, value}
      });
    }
  },

  /**
   * Applies data-on: binds the listed events to methods of the context, honouring
   * the modifiers appended to each event, such as prevent, stop, once, self and
   * the key filters.
   *
   * @param {HTMLElement} el - Element carrying data-on
   * @param {string} value - Bindings as "event.modifiers: handler" pairs
   * @param {Object} context - {state, methods, ...} the handlers come from
   * @returns {void}
   */
  processDataOn(el, value, context) {
    if (!el || !value || !context) return;

    const modifiers = {
      prevent: (e) => {e.preventDefault(); return true;},
      stop: (e) => {e.stopPropagation(); return true;},
      once: () => true,
      capture: () => true,
      self: (e, el) => e.target === el,
      trusted: (e) => e.isTrusted,
      enter: (e) => e.key === 'Enter',
      tab: (e) => e.key === 'Tab',
      esc: (e) => e.key === 'Escape',
      space: (e) => e.key === ' ',
      left: (e) => e.button === 0,
      right: (e) => e.button === 2,
      middle: (e) => e.button === 1,
      ctrl: (e) => e.ctrlKey,
      alt: (e) => e.altKey,
      shift: (e) => e.shiftKey,
      meta: (e) => e.metaKey
    };

    try {
      if (!el._eventBinding) {
        el._eventBinding = {
          value,
          originalState: this.deepClone(context.state),
          originalContext: {...context},
          handlers: new Map(),
          lastTriggered: {}
        };
      } else {
        el._eventBinding.value = value;
        this.syncDirectiveBinding(el._eventBinding, context);
      }

      const bindings = String(el._eventBinding.value || value).split(',').map(b => b.trim());
      const nextEventTypes = new Set();

      bindings.forEach(binding => {
        // Split on first colon only to preserve handler expressions containing colons (e.g. ternary args)
        const colonIndex = binding.indexOf(':');
        if (colonIndex === -1) {
          ErrorManager.handle(`Invalid event binding: ${binding}`, {
            context: 'TemplateManager.processDataOn',
            data: {el, value, context},
            logLevel: 'warn'
          });
          return;
        }
        const eventInfo = binding.substring(0, colonIndex).trim();
        const handlerExpr = binding.substring(colonIndex + 1).trim();
        if (!eventInfo || !handlerExpr) {
          ErrorManager.handle(`Invalid event binding: ${binding}`, {
            context: 'TemplateManager.processDataOn',
            data: {el, value, context},
            logLevel: 'warn'
          });
          return;
        }

        const [eventType, ...modifierList] = eventInfo.split('.');
        nextEventTypes.add(eventType);

        if (el.tagName === 'BUTTON' && eventType === 'click') {
          if (!modifierList.includes('stop')) {
            modifierList.push('stop');
          }
        }

        const oldHandler = el._eventBinding.handlers.get(eventType);
        if (oldHandler) {
          EventSystemManager.removeHandler(oldHandler);
        }

        const methodMatch = handlerExpr.match(/(\w+)(?:\((.*?)\))?/);
        if (!methodMatch) {
          ErrorManager.handle(`Invalid method expression: ${handlerExpr}`, {
            context: 'TemplateManager.processDataOn',
            data: {el, value, context},
            logLevel: 'warn'
          });
          return;
        }

        const [_, methodName, argsStr] = methodMatch;
        const bindingContext = this.getDirectiveContext(el._eventBinding, context);

        // Try to find method in component first, then fallback to global scope
        let method = bindingContext.methods?.[methodName];
        let isGlobalFunction = false;

        if (typeof method !== 'function') {
          // Fallback to global function
          if (typeof window[methodName] === 'function') {
            method = window[methodName];
            isGlobalFunction = true;
          } else {
            ErrorManager.handle(`Method "${methodName}" not found in component or global scope`, {
              context: 'TemplateManager.processDataOn',
              data: {el, value, context},
              logLevel: 'warn'
            });
            return;
          }
        }

        const parseArgument = (arg) => {
          arg = arg?.trim();
          if (!arg) return null;

          if (arg.startsWith("'") || arg.startsWith('"')) {
            return arg.slice(1, -1);
          }

          return () => {
            const ctx = this.getDirectiveContext(el._eventBinding, context);
            const evalState = this.getDirectiveEvalState(el._eventBinding, context, {
              mergeLiveState: true
            });
            return ExpressionEvaluator.evaluate(arg, {...evalState, $event: null}, ctx);
          };
        };

        const args = argsStr ? argsStr.split(',').map(parseArgument).filter(Boolean) : [];

        const handler = (event) => {
          try {
            const now = Date.now();
            const lastTrigger = el._eventBinding.lastTriggered[eventType] || 0;
            const minInterval = 100;

            if (now - lastTrigger < minInterval) {
              return;
            }

            if (!el.contains(event.target) && el !== event.target) {
              return;
            }

            const modifiersPassed = modifierList.every(modifier => {
              const modifierFn = modifiers[modifier];
              return modifierFn ? modifierFn(event, el) : true;
            });

            if (!modifiersPassed) return;

            el._eventBinding.lastTriggered[eventType] = now;
            const invocationContext = this.getDirectiveContext(el._eventBinding, context);

            const evaluatedArgs = args.map(arg =>
              typeof arg === 'function' ? arg() : arg
            );

            method.apply(isGlobalFunction ? null : invocationContext, [...evaluatedArgs, event]);

            if (modifierList.includes('once')) {
              EventSystemManager.removeHandler(handlerId);
              el._eventBinding.handlers.delete(eventType);
            }

          } catch (error) {
            ErrorManager.handle(error, {
              context: 'TemplateManager.processDataOn',
              type: 'template:error',
              data: {methodName, el, value, context}
            });
          }
        };

        const handlerId = EventSystemManager.addHandler(
          el,
          eventType,
          handler,
          {
            componentId: context.id,
            capture: modifierList.includes('capture'),
            passive: eventType === 'scroll' || eventType === 'touchmove'
          }
        );

        el._eventBinding.handlers.set(eventType, handlerId);

      });

      el._eventBinding.handlers.forEach((handlerId, eventType) => {
        if (!nextEventTypes.has(eventType)) {
          EventSystemManager.removeHandler(handlerId);
          el._eventBinding.handlers.delete(eventType);
        }
      });

    } catch (error) {
      ErrorManager.handle(error, {
        context: 'TemplateManager.processDataOn',
        type: 'template:error',
        data: {el, value, context}
      });
    }
  },

  /**
   * Applies data-container: loads the template the expression names into the
   * element and re-loads it when the expression resolves to a different one.
   *
   * A render counter guards against a slow load overwriting a newer one.
   *
   * @param {HTMLElement} el - Element carrying data-container
   * @param {string} value - Expression producing a template path
   * @param {Object} context - {state, methods, ...} to evaluate against
   * @returns {void}
   */
  processDataContainer(el, value, context) {
    if (!el || !value || !context) return;

    try {
      if (!el._containerBinding) {
        el._containerBinding = {
          value,
          originalState: this.deepClone(context.state),
          originalContext: {...context},
          currentPath: null,
          renderVersion: 0
        };
      } else {
        el._containerBinding.value = value;
        this.syncDirectiveBinding(el._containerBinding, context);
      }
      const updateContainer = () => {
        const binding = el._containerBinding;
        const ctx = this.getDirectiveContext(binding, context);

        const loadComponent = async (componentRef) => {
          try {
            if (typeof componentRef === 'string') {
              const template = await this.loadFromServer(componentRef);
              if (!template) throw new Error(`Failed to load template: ${componentRef}`);

              const childContext = {
                ...ctx,
                parentId: ctx.id,
                state: {...(ctx.state || context.state)},
                path: componentRef
              };

              return {template, context: childContext};

            } else if (componentRef && typeof componentRef === 'object') {
              if (!componentRef.template) {
                throw new Error('Component definition missing template');
              }

              const childContext = {
                ...ctx,
                ...componentRef,
                parentId: ctx.id,
                state: {...(ctx.state || context.state), ...componentRef.state}
              };

              return {template: componentRef.template, context: childContext};
            }

            throw new Error('Invalid component reference');

          } catch (error) {
            ErrorManager.handle('Component load failed', {
              context: 'TemplateManager.processDataContainer',
              data: {el, value, context, error}
            });
            return null;
          }
        };

        const renderComponent = async (componentRef, renderVersion) => {
          try {
            const component = await loadComponent(componentRef);
            if (!component) return;

            if (renderVersion !== el._containerBinding?.renderVersion) {
              return;
            }

            Array.from(el.children).forEach(child => {
              this.cleanupElement(child);
            });

            el.innerHTML = '';

            const container = document.createElement('div');
            container.innerHTML = component.template;

            this.processDataDirectives(container, component.context);
            this.processInterpolation(container, component.context);

            if (renderVersion !== el._containerBinding?.renderVersion) {
              return;
            }

            el.appendChild(container);

            el._containerBinding.currentPath = typeof componentRef === 'string' ?
              componentRef : null;

          } catch (error) {
            ErrorManager.handle('Component render failed', {
              context: 'TemplateManager.processDataContainer',
              data: {el, value, context, error}
            });
            el.innerHTML = this.renderError(error);
          }
        };

        const evalState = this.getDirectiveEvalState(binding, context, {
          mergeLiveState: true,
          includeMethods: true
        });
        const componentRef = ExpressionEvaluator.evaluate(binding.value, evalState, ctx);

        if (!componentRef) {
          Array.from(el.children).forEach(child => {
            this.cleanupElement(child);
          });
          el.innerHTML = '';
          binding.currentPath = null;
          binding.renderVersion += 1;
          return;
        }

        const renderVersion = ++binding.renderVersion;
        renderComponent(componentRef, renderVersion);
      };

      updateContainer();

      this.setupReactiveUpdate(el, context, 'Container', updateContainer);

    } catch (error) {
      ErrorManager.handle(error, {
        context: 'TemplateManager.processDataContainer',
        data: {el, value, context}
      });
      el.innerHTML = this.renderError(error);
    }
  },

  /**
   * Applies data-model: binds a form control to a state path in both
   * directions, honouring the lazy, number and trim modifiers.
   *
   * @param {HTMLElement} el - Form control carrying data-model
   * @param {string} value - State path, plus any modifiers
   * @param {Object} context - {state, methods, ...} holding the bound value
   * @returns {void}
   */
  processDataModel(el, value, context) {
    if (!el || !value || !context) return;

    try {
      if (!el._modelBinding) {
        el._modelBinding = {
          value,
          originalState: this.deepClone(context.state),
          originalContext: {...context},
          lastValue: null,
          initialValue: null,
          isActive: false,
          selectionStart: null,
          selectionEnd: null,
          modifiers: {
            lazy: false,
            number: false,
            trim: false
          }
        };
      } else {
        el._modelBinding.value = value;
        this.syncDirectiveBinding(el._modelBinding, context);
      }

      this.applyModelBindingDefinition(el._modelBinding, value);

      const updateModel = () => {
        const binding = el._modelBinding;

        const getModelValue = () => {
          const ctx = this.getDirectiveContext(binding, context);
          const evalState = this.getDirectiveEvalState(binding, context, {
            mergeLiveState: true
          });
          let modelValue = ExpressionEvaluator.evaluate(binding.value, evalState, ctx);

          if (binding.modifiers.number) {
            modelValue = modelValue === '' ? null : Number(modelValue);
          }

          if (binding.modifiers.trim && typeof modelValue === 'string') {
            modelValue = modelValue.trim();
          }

          return modelValue;
        };

        const updateModel = (newValue) => {
          try {
            const parts = binding.value.split('.');
            const prop = parts.pop();
            const liveState = context.state || binding.originalContext?.state || {};
            const target = parts.length ?
              parts.reduce((obj, key) => obj?.[key], liveState) :
              liveState;

            if (!target || typeof prop !== 'string') return;

            if (binding.modifiers.number) {
              newValue = newValue === '' ? null : Number(newValue);
            }
            if (binding.modifiers.trim && typeof newValue === 'string') {
              newValue = newValue.trim();
            }

            if (newValue !== binding.lastValue) {
              binding.lastValue = newValue;
              target[prop] = newValue;
            }
          } catch (error) {
            ErrorManager.handle(error, {
              context: 'TemplateManager.processDataModel',
              data: {el, value, context}
            });
          }
        };

        if (el._modelHandlers) {
          Object.entries(el._modelHandlers).forEach(([event, handler]) => {
            el.removeEventListener(event, handler);
          });
        }

        // Only add focus/blur listeners once (not on every reactive update)
        if (!el._modelFocusHandler) {
          el._modelFocusHandler = () => {
            binding.isActive = true;
            binding.selectionStart = el.selectionStart;
            binding.selectionEnd = el.selectionEnd;
          };
          el._modelBlurHandler = () => {
            binding.isActive = false;
            binding.selectionStart = null;
            binding.selectionEnd = null;
          };
          el.addEventListener('focus', el._modelFocusHandler);
          el.addEventListener('blur', el._modelBlurHandler);
        }

        el._modelHandlers = {};

        switch (el.type) {
          case 'checkbox':
            el._modelHandlers.change = e => updateModel(e.target.checked);
            break;

          case 'radio':
            el._modelHandlers.change = e => {
              if (e.target.checked) {
                updateModel(e.target.value);
              }
            };
            break;

          case 'file':
            el._modelHandlers.change = e => updateModel(e.target.files);
            break;

          case 'select-multiple':
            el._modelHandlers.change = e => {
              const selected = Array.from(e.target.selectedOptions)
                .map(opt => opt.value);
              updateModel(selected);
            };
            break;

          default:
            const eventType = binding.modifiers.lazy ? 'change' : 'input';
            el._modelHandlers[eventType] = e => updateModel(e.target.value);

            el._modelHandlers.keydown = e => {
              if (e.key === 'Enter') {
                updateModel(e.target.value);
              }
            };
        }

        Object.entries(el._modelHandlers).forEach(([event, handler]) => {
          el.addEventListener(event, handler);
        });

        const initialValue = getModelValue();
        binding.initialValue = initialValue;

        if (el.type === 'checkbox') {
          el.checked = !!initialValue;
        } else if (el.type === 'radio') {
          el.checked = el.value === String(initialValue);
        } else if (el.type === 'select-multiple' && Array.isArray(initialValue)) {
          Array.from(el.options).forEach(option => {
            option.selected = initialValue.includes(option.value);
          });
        } else if (el.tagName === 'SELECT') {
          el.value = initialValue ?? '';
          if (el.value !== String(initialValue)) {
            Array.from(el.options).forEach(option => {
              option.selected = option.value === String(initialValue);
            });
          }
        } else {
          el.value = initialValue ?? '';
        }
      };

      updateModel();

      this.setupReactiveUpdate(el, context, 'Model', updateModel);

    } catch (error) {
      ErrorManager.handle(error, {
        context: 'TemplateManager.processDataModel',
        data: {el, value, context}
      });
    }
  },

  /**
   * Applies data-checked: keeps the checked state of a checkbox or radio in
   * step with the expression.
   *
   * Elements of any other type are rejected with a warning.
   *
   * @param {HTMLInputElement} el - Checkbox or radio carrying data-checked
   * @param {string} expression - Condition to evaluate
   * @param {Object} context - {state, methods, ...} to evaluate against
   * @returns {void}
   */
  processDataChecked(el, expression, context) {
    if (!el || !expression || !context) return;

    if (!['checkbox', 'radio'].includes(el.type)) {
      ErrorManager.handle('data-checked only works with checkbox/radio inputs', {
        context: 'TemplateManager.processDataChecked',
        data: {el, expression, context},
        logLevel: 'warn'
      });
      return;
    }

    try {
      if (!el._checkedBinding) {
        el._checkedBinding = {
          expression,
          originalState: this.deepClone(context.state),
          originalContext: {...context},
          lastValue: null
        };
      } else {
        el._checkedBinding.expression = expression;
        this.syncDirectiveBinding(el._checkedBinding, context);
      }

      const updateChecked = () => {
        if (!el) return;

        try {
          const currentChecked = el.checked;

          const binding = el._checkedBinding;
          const ctx = this.getDirectiveContext(binding, context);
          const evalState = this.getDirectiveEvalState(binding, context, {
            mergeLiveState: true
          });

          const isChecked = Utils.string.toBoolean(
            ExpressionEvaluator.evaluate(binding.expression, evalState, ctx)
          );

          if (isChecked === currentChecked) {
            return;
          }

          el.checked = isChecked;
          el._checkedBinding.lastValue = !!isChecked;

          // Programmatic assignment does NOT fire a native 'change' event.
          // Dispatch one manually so that reactive components (e.g. TabsComponent)
          // that listen for 'change' can react without needing DOM interceptors or
          // arbitrary setTimeout delays.
          el.dispatchEvent(new Event('change', {bubbles: true}));

        } catch (error) {
          ErrorManager.handle(error, {
            context: 'TemplateManager.processDataChecked',
            data: {el, expression, context}
          });
          el.checked = false;
        }
      };

      updateChecked();

      this.setupReactiveUpdate(el, context, 'Checked', updateChecked);

    } catch (error) {
      ErrorManager.handle(error, {
        context: 'TemplateManager.processDataChecked',
        data: {el, expression, context}
      });
      el.checked = false;
    }
  },

  /**
   * Process data-options-key directive for select, multi-select, tags, and
   * text-autocomplete elements.
   *
   * Detects the element type and delegates to the appropriate Factory's
   * populateFromOptions method.  The options data is retrieved from
   * context.options (set by FormManager from instance.state.formOptions).
   *
   * Resolves {{}} against context.state/computed (for data-for loop vars), then
   * looks up option arrays by flat key, dot path, or bracket path on options only
   * (e.g. attribute_options[{{attr.id}}]).
   *
   * Usage:
   *   data-options-key="categories"
   *   data-options-key="attr_{{attr.id}}_options"
   *   data-options-key="attribute_options[{{attr.id}}]"
   *
   * @param {HTMLElement} el
   * @param {string} expression  - The options key (or expression resolving to one)
   * @param {Object} context
   */
  processDataOptionsKey(el, expression, context) {
    if (!el || !expression || !context) return;

    try {
      if (!el._optionsBinding) {
        el._optionsBinding = {
          expression,
          originalState: this.deepClone(context.state),
          originalContext: {...context},
          lastSignature: null
        };
      } else {
        el._optionsBinding.expression = expression;
        this.syncDirectiveBinding(el._optionsBinding, context);
      }

      const updateOptions = () => {
        const binding = el._optionsBinding;
        const ctx = this.getDirectiveContext(binding, context);
        const optionsData = ctx.options;
        if (!optionsData || typeof optionsData !== 'object') return;

        const evalState = this.getDirectiveEvalState(binding, context, {
          mergeLiveState: true
        });
        let lookup = String(binding.expression).trim();
        if (lookup.includes('{{')) {
          lookup = lookup.replace(/\{\{(.+?)\}\}/g, (_, inner) => {
            const val = ExpressionEvaluator.evaluate(inner.trim(), evalState, ctx);
            return val == null || val === undefined ? '' : String(val);
          }).trim();
        }
        if (!lookup) return;

        let options;
        if (ExpressionEvaluator.bracketAccessRegex.test(lookup)) {
          options = ExpressionEvaluator.resolveBracketAccess(lookup, optionsData, ctx);
        } else if (lookup.includes('.')) {
          options = ExpressionEvaluator.getPropertyPath(lookup, optionsData, ctx);
        } else {
          options = optionsData[lookup];
        }

        const normalizedOptions = Utils.options?.normalizeSource
          ? Utils.options.normalizeSource(options)
          : options;
        if (!Array.isArray(normalizedOptions)) return;

        const signature = `${lookup}:${JSON.stringify(normalizedOptions)}`;
        if (binding.lastSignature === signature) {
          return;
        }

        const INLINE_KEY = '__templateManagerResolvedOptions__';
        const wrappedOptions = Object.assign({}, optionsData, {[INLINE_KEY]: normalizedOptions});

        const tag = el.tagName?.toUpperCase();
        const dataElement = el.getAttribute('data-element');

        if (tag === 'SELECT') {
          if (el.multiple && window.MultiSelectElementFactory) {
            MultiSelectElementFactory.populateFromOptions(el, wrappedOptions, INLINE_KEY);
          } else if (window.SelectElementFactory) {
            SelectElementFactory.populateFromOptions(el, wrappedOptions, INLINE_KEY);
          }
        } else if (tag === 'INPUT') {
          if (dataElement === 'tags' && window.TagsElementFactory) {
            TagsElementFactory.populateFromOptions(el, wrappedOptions, INLINE_KEY);
          } else if (window.TextElementFactory) {
            TextElementFactory.populateFromOptions(el, wrappedOptions, INLINE_KEY);
          }
        }

        binding.lastSignature = signature;
      };

      updateOptions();
      this.setupReactiveUpdate(el, context, 'Options', updateOptions);
    } catch (error) {
      ErrorManager.handle(error, {
        context: 'TemplateManager.processDataOptionsKey',
        data: {el, expression, context}
      });
    }
  },

  /**
   * Process data-bind directive (or data-attr="data:fieldName") for binding
   * complex data to TableManager or LineItemsManager components.
   *
   * Uses ExpressionEvaluator so it supports bracket access and {{}}
   * interpolation — works correctly inside data-for loops.
   *
   * Includes a retry mechanism to wait for the component to be initialized
   * (TableManager / LineItemsManager may initialize asynchronously).
   *
   * Usage:
   *   data-bind="items"                        → state.items
   *   data-attr="data:items"                   → same (backward compatible)
   *   data-bind="order.lines"                  → state.order.lines
   *
   * @param {HTMLElement} el
   * @param {string} expression
   * @param {Object} context
   */
  processDataBind(el, expression, context) {
    if (!el || !expression || !context) return;

    try {
      if (!el._bindBinding) {
        el._bindBinding = {
          expression,
          originalState: this.deepClone(context.state),
          originalContext: {...context},
          retryTimer: null,
          cleanup() {
            if (this.retryTimer) {
              clearInterval(this.retryTimer);
              this.retryTimer = null;
            }
          }
        };
      } else {
        el._bindBinding.expression = expression;
        this.syncDirectiveBinding(el._bindBinding, context);
      }

      const clearRetryTimer = () => {
        if (el._bindBinding?.retryTimer) {
          clearInterval(el._bindBinding.retryTimer);
          el._bindBinding.retryTimer = null;
        }
      };

      const updateBoundData = () => {
        const binding = el._bindBinding;
        const ctx = this.getDirectiveContext(binding, context);
        const evalState = this.getDirectiveEvalState(binding, context, {
          mergeLiveState: true
        });
        const boundData = ExpressionEvaluator.evaluate(binding.expression, evalState, ctx);
        if (boundData === undefined || boundData === null) return;

        const state = ctx.state || context.state || {};
        const isTable = el.matches('table[data-table]');
        const isLineItems = el.matches('table[data-line-items]');

        clearRetryTimer();

        if (isTable && window.TableManager) {
          const tableId = el.getAttribute('data-table');
          const getRegisteredTable = () => TableManager.state?.tables?.get(tableId) || null;

          const ensureTableReady = () => {
            const registeredTable = getRegisteredTable();
            if (registeredTable && registeredTable.element === el && registeredTable.element?.isConnected) {
              return true;
            }

            if (typeof TableManager.initTable === 'function') {
              try {
                TableManager.initTable(el);
              } catch (error) {
                console.warn(`[TemplateManager] Failed to initialize table '${tableId}' before binding`, error);
              }
            }

            const nextTable = getRegisteredTable();
            return Boolean(nextTable && nextTable.element === el && nextTable.element?.isConnected);
          };

          const setTableData = () => {
            const tableObj = TableManager.state?.tables?.get(tableId);
            const optionsData = ctx.options || state.options;
            if (optionsData && tableObj) {
              if (!tableObj.dataOptions) tableObj.dataOptions = {};
              Object.assign(tableObj.dataOptions, optionsData);
            }

            const rows = Array.isArray(boundData)
              ? boundData
              : Array.isArray(boundData?.data)
                ? boundData.data
                : [boundData];

            const payload = {
              data: rows,
              meta: boundData?.meta || state?.meta || {page: 1, pageSize: rows.length || 20, total: rows.length || 0},
              filters: boundData?.filters || state?.filters || {},
              options: boundData?.options || optionsData || {},
              columns: boundData?.columns || state?.columns
            };

            if (boundData.columns && boundData.data) {
              TableManager.setData(tableId, boundData);
            } else {
              TableManager.setData(tableId, payload);
            }
          };

          if (ensureTableReady()) {
            setTableData();
          } else {
            let retryCount = 0;
            const maxRetries = 30;
            binding.retryTimer = setInterval(() => {
              retryCount++;
              if (!el.isConnected) {
                clearRetryTimer();
                return;
              }
              if (ensureTableReady()) {
                clearRetryTimer();
                setTableData();
              } else if (retryCount >= maxRetries) {
                clearRetryTimer();
                console.warn(`[TemplateManager] TableManager table '${tableId}' not found after ${maxRetries} retries`);
              }
            }, 100);
          }
        } else if (isLineItems && window.LineItemsManager) {
          const extractLineItems = () => {
            if (Array.isArray(boundData)) return boundData;
            if (Array.isArray(boundData?.data)) return boundData.data;
            return null;
          };

          const setLineItemsData = () => {
            const lineItemsInstance = LineItemsManager.getInstance(el);
            const items = extractLineItems();
            if (lineItemsInstance && Array.isArray(items)) {
              lineItemsInstance.setData(items);
            }
          };

          if (LineItemsManager.state?.instances?.has(el)) {
            setLineItemsData();
          } else {
            let retryCount = 0;
            const maxRetries = 10;
            binding.retryTimer = setInterval(() => {
              retryCount++;
              if (!el.isConnected) {
                clearRetryTimer();
                return;
              }
              if (LineItemsManager.state?.instances?.has(el)) {
                clearRetryTimer();
                setLineItemsData();
              } else if (retryCount >= maxRetries) {
                clearRetryTimer();
                console.warn(`[TemplateManager] LineItemsManager table not found after ${maxRetries} retries`);
              }
            }, 100);
          }
        }
      };

      updateBoundData();
      this.setupReactiveUpdate(el, context, 'Bind', updateBoundData);
    } catch (error) {
      ErrorManager.handle(error, {
        context: 'TemplateManager.processDataBind',
        data: {el, expression, context}
      });
    }
  },

  /**
   * Process data-files directive for file inputs.
   *
   * Resolves an expression to obtain existing file data and sets it on
   * the file input element.  Uses ExpressionEvaluator so all expression
   * syntax (dot notation, bracket access, mustache interpolation) is
   * supported — exactly the same as data-attr or data-text.
   *
   * Usage:
   *   data-files="avatar"                      → state.avatar
   *   data-files="config.csv"                  → state.config.csv
   *   data-files="default_icon[{{lng.value}}]" → state.default_icon['th']
   *
   * The resolved value must be an array of file objects [{url, name}].
   *
   * @param {HTMLElement} el
   * @param {string} expression
   * @param {Object} context
   */
  processDataFiles(el, expression, context) {
    if (!el || !expression || !context) return;

    // Only meaningful on file inputs
    if (el.type !== 'file') return;

    // Skip if already resolved to JSON by a previous pass
    try {
      JSON.parse(expression);
      return;
    } catch (_) { /* not JSON — treat as expression */}

    try {
      if (!el._filesBinding) {
        el._filesBinding = {
          expression,
          originalState: this.deepClone(context.state),
          originalContext: {...context},
          lastFilesJson: null
        };
      } else {
        el._filesBinding.expression = expression;
        this.syncDirectiveBinding(el._filesBinding, context);
      }

      const updateFiles = () => {
        if (!el) return;

        try {
          const binding = el._filesBinding;
          const ctx = this.getDirectiveContext(binding, context);
          const evalState = this.getDirectiveEvalState(binding, context, {
            mergeLiveState: true
          });

          const value = ExpressionEvaluator.evaluate(
            binding.expression,
            evalState,
            ctx
          );

          const instance = window.ElementManager && window.FileElementFactory
            ? ElementManager.getInstanceByElement(el)
            : null;

          if (!value || (typeof value !== 'object')) {
            if (binding.lastFilesJson) {
              el.removeAttribute('data-files');
              binding.lastFilesJson = null;
              if (instance) {
                instance.config.existingFiles = [];
                if (instance.previewContainer) {
                  const oldPreviews = instance.previewContainer.querySelectorAll('.preview-item[data-existing="true"]');
                  oldPreviews.forEach(p => p.remove());
                }
              }
            }
            return;
          }

          const files = Array.isArray(value) ? value : [value];
          const filesJson = JSON.stringify(files);
          if (filesJson === binding.lastFilesJson) {
            return;
          }

          // Write JSON so FileElementFactory can pick it up during init
          el.setAttribute('data-files', filesJson);
          binding.lastFilesJson = filesJson;

          // If FileElementFactory already initialised, update live preview
          if (instance) {
            instance.config.existingFiles = files;

            if (instance.previewContainer) {
              const oldPreviews = instance.previewContainer.querySelectorAll(
                '.preview-item[data-existing="true"]'
              );
              oldPreviews.forEach(p => p.remove());
              FileElementFactory.showExistingFiles(instance, files);
            }
          }
        } catch (error) {
          ErrorManager.handle(error, {
            context: 'TemplateManager.processDataFiles',
            data: {el, expression, context}
          });
        }
      };

      updateFiles();

      this.setupReactiveUpdate(el, context, 'Files', updateFiles);

    } catch (error) {
      ErrorManager.handle(error, {
        context: 'TemplateManager.processDataFiles',
        data: {el, expression, context}
      });
    }
  },

  /**
   * Clones a value deeply, tracking what it has seen so cycles and shared
   * references survive as the same object in the copy.
   *
   * @param {*} obj - Value to clone
   * @param {WeakMap} [seen=new WeakMap()] - Objects already cloned
   * @returns {*} The clone
   */
  deepClone(obj, seen = new WeakMap()) {
    if (obj === null || typeof obj !== 'object') {
      return obj;
    }

    if (seen.has(obj)) {
      return seen.get(obj);
    }

    const clone = Array.isArray(obj) ? [] : {};
    seen.set(obj, clone);

    Object.entries(obj).forEach(([key, value]) => {
      clone[key] = this.deepClone(value, seen);
    });

    return clone;
  },

  /**
   * Clones a state object recursively without cycle tracking, used as the
   * fallback when deepClone() cannot handle a value.
   *
   * @param {Object} state - State to clone
   * @returns {Object} The clone
   */
  cloneState(state) {
    if (!state || typeof state !== 'object') return state;
    const clone = Array.isArray(state) ? [] : {};

    for (const key in state) {
      if (Object.prototype.hasOwnProperty.call(state, key)) {
        const value = state[key];
        clone[key] = typeof value === 'object' ? this.cloneState(value) : value;
      }
    }

    return clone;
  },

  /**
   * Runs and forgets the cleanup function a data-script left on an element.
   *
   * @param {HTMLElement} element - Element to release
   * @returns {void}
   */
  cleanupElementScripts(element) {
    if (!element) return;

    const scriptInfo = this.state.activeScripts.get(element);
    if (!scriptInfo) return;

    if (typeof scriptInfo.cleanupFn === 'function') {
      try {
        scriptInfo.cleanupFn();
      } catch (error) {
        console.warn('TemplateManager: Element script cleanup error for', scriptInfo.fn, error);
      }
    }

    this.state.activeScripts.delete(element);
  },

  /**
   * Removes the input, focus and blur listeners a data-model binding installed.
   *
   * @param {HTMLElement} element - Element to release
   * @returns {void}
   */
  cleanupModelListeners(element) {
    if (!element) return;

    if (element._modelHandlers) {
      Object.entries(element._modelHandlers).forEach(([event, handler]) => {
        element.removeEventListener(event, handler);
      });
      delete element._modelHandlers;
    }

    if (element._modelFocusHandler) {
      element.removeEventListener('focus', element._modelFocusHandler);
      delete element._modelFocusHandler;
    }

    if (element._modelBlurHandler) {
      element.removeEventListener('blur', element._modelBlurHandler);
      delete element._modelBlurHandler;
    }
  },

  /**
   * Releases the directive bindings of one element: its scripts, model
   * listeners, reactive subscriptions and the binding objects themselves.
   *
   * @param {HTMLElement} element - Element to release
   * @param {Object} [options={}] - Release options
   * @param {string[]} [options.skipBindings] - Binding names to leave in place
   * @param {string[]} [options.skipReactiveTypes] - Reactive types to leave subscribed
   * @returns {void}
   */
  cleanupElementBindings(element, options = {}) {
    if (!element) return;

    const skipBindings = new Set(options.skipBindings || []);
    const skipReactiveTypes = new Set(options.skipReactiveTypes || []);
    const reactiveTypes = [
      'Text', 'Html', 'If', 'For', 'Class', 'Attr', 'Style',
      'Model', 'Container', 'Checked', 'Files', 'Options', 'Bind',
      'Show', 'Transition'
    ];

    this.cleanupElementScripts(element);

    if (!skipBindings.has('_eventBinding') && element._eventBinding) {
      element._eventBinding.handlers.forEach(handlerId => {
        EventSystemManager.removeHandler(handlerId);
      });
      delete element._eventBinding;
    }

    this.cleanupModelListeners(element);

    reactiveTypes.forEach(type => {
      if (!skipReactiveTypes.has(type)) {
        this.cleanupReactiveUpdate(element, type);
      }
    });

    if (!skipBindings.has('_animBinding') && typeof this.cleanupAnimationBinding === 'function') {
      this.cleanupAnimationBinding(element);
    }

    if (!skipBindings.has('_ifBinding') && element._ifBinding) {
      if (element._ifBinding.comment?.parentNode) {
        element._ifBinding.comment.parentNode.removeChild(element._ifBinding.comment);
      }
      element._ifBinding.listeners?.clear?.();
      delete element._ifBinding;
    }

    [
      '_textBinding',
      '_htmlBinding',
      '_forBinding',
      '_classBinding',
      '_attrBinding',
      '_styleBinding',
      '_modelBinding',
      '_containerBinding',
      '_checkedBinding',
      '_filesBinding',
      '_optionsBinding',
      '_bindBinding'
    ].forEach(type => {
      if (skipBindings.has(type) || !element[type]) {
        return;
      }

      if (typeof element[type].cleanup === 'function') {
        element[type].cleanup();
      }

      delete element[type];
    });
  },

  /**
   * Releases an element and everything below it: its bindings, its update queue
   * and the handlers of the component it belongs to.
   *
   * @param {HTMLElement} element - Subtree root to release
   * @returns {void}
   */
  cleanupElement(element) {
    if (!element) return;

    try {
      this.cleanupElementBindings(element);

      if (element._updateQueue) {
        element._updateQueue.clear();
        delete element._updateQueue;
      }

      const componentId = element.dataset?.componentId;
      if (componentId) {
        delete element.dataset.componentId;
        delete element.dataset.parentContext;
      }

      Array.from(element.children).forEach(child => {
        this.cleanupElement(child);
      });

      Object.keys(element).forEach(key => {
        if (key.startsWith('_')) {
          delete element[key];
        }
      });

    } catch (error) {
      ErrorManager.handle(error, {
        context: 'TemplateManager.cleanupElement',
        data: {element}
      });
    }
  },

  /**
   * Releases what a component left here: its event handlers and its cached
   * template.
   *
   * @param {string} componentId - Component id
   * @returns {void}
   */
  cleanupComponent(componentId) {
    if (!componentId) return;

    try {
      this.cleanupComponentHandlers(componentId);

      const cacheKey = `template:${componentId}`;
      if (this.state.cache.has(cacheKey)) {
        this.state.cache.delete(cacheKey);
        this.state.itemTimestamps.delete(cacheKey);
      }

      const elements = document.querySelectorAll(`[data-component-id="${componentId}"]`);
      elements.forEach(element => {
        this.cleanupElement(element);
      });

      EventManager.emit('template:cleanup', {
        componentId,
      });

    } catch (error) {
      ErrorManager.handle(error, {
        context: 'TemplateManager.cleanupComponent',
        data: {componentId}
      });
    }
  },

  /**
   * Releases everything the manager holds: the cache, the registered handlers
   * and the bindings under the main content area.
   *
   * @returns {void}
   */
  cleanup() {
    try {
      this.state.cache.clear();
      this.state.itemTimestamps.clear();

      this.cleanupHandlers();

      const root = document.querySelector(Now.config.mainSelector);
      if (root) {
        this.cleanupElement(root);
      }

      this.state.currentComputation = null;
      this.state.handlers = new Map();
      this.state.pendingUpdates = new Set();

      EventManager.emit('template:cleanup:all');

    } catch (error) {
      ErrorManager.handle(error, {
        context: 'TemplateManager.cleanup'
      });
    }
  },

  /**
   * Renders a value as text, JSON-encoding objects and turning null and
   * undefined into an empty string.
   *
   * @param {*} value - Value to render
   * @returns {string} Text form of the value
   */
  valueToString(value) {
    if (value === null || value === undefined) {
      return '';
    }
    if (typeof value === 'object') {
      try {
        return JSON.stringify(value);
      } catch {
        return String(value);
      }
    }
    return String(value);
  },

  /**
   * Writes a value at a dotted path in the state, creating the objects along
   * the way.
   *
   * The path is not checked for __proto__ or constructor, so it must come from
   * the template author rather than from request data.
   *
   * @param {string} path - Dotted path, such as 'form.email'
   * @param {*} value - Value to write
   * @param {Object} state - Object to write into
   * @returns {void}
   */
  setStateValue(path, value, state) {
    const parts = path.split('.');
    const key = parts.pop();
    const target = parts.reduce((obj, k) => obj[k] = obj[k] || {}, state);
    target[key] = value;
  },

  /**
   * Applies the directives and then the interpolation of a context to a
   * subtree.
   *
   * @param {HTMLElement} element - Subtree to process
   * @param {Object} context - {state, methods, ...} the directives read from
   * @returns {void}
   */
  processTemplateContent(element, context) {
    this.processDataDirectives(element, context);
    this.processInterpolation(element, context);
  },

  /**
   * Renders the template of a context into a container.
   *
   * @param {Object} context - Context carrying the template and its data
   * @param {HTMLElement} container - Element to render into
   * @returns {void}
   */
  render(context, container) {
    if (!context || typeof context !== 'object') {
      ErrorManager.handle('Invalid context provided to render.', {
        context: 'TemplateManager.render',
        data: {context, container}
      });
      return;
    }

    if (!(container instanceof HTMLElement)) {
      ErrorManager.handle('Container must be a valid DOM element.', {
        context: 'TemplateManager.render',
        data: {context, container}
      });
      return;
    }

    container.innerHTML = '';

    const processedTemplate = this.processTemplate(context.template, context);

    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = processedTemplate;

    while (tempDiv.firstChild) {
      container.appendChild(tempDiv.firstChild);
    }

    const eventManager = Now.getManager('event');
    if (eventManager) {
      eventManager.emit('template:render', {
        componentId: context.id,
        timestamp: Date.now(),
        container: container
      });
    }
  },

  /**
   * Drops the cache entries that have passed their expiry.
   *
   * @returns {void}
   */
  cleanupCache() {
    const now = Date.now();
    for (const [key, entry] of this.state.cache) {
      if (entry.expires < now) {
        this.state.cache.delete(key);
        this.state.itemTimestamps.delete(key);
      }
    }
  },

  /**
   * Registers a handler for a template event binding and returns the id the
   * element stores in data-handler-id.
   *
   * The expression names a method of the context and may carry literal
   * arguments, which are passed ahead of the event.
   *
   * @param {string} handlerExpr - Expression such as "save('draft')"
   * @param {Object} context - Context the method comes from
   * @returns {string} Handler id
   */
  registerEventHandler(handlerExpr, context) {
    const handlerId = `evt_${Utils.generateUUID()}`;
    const [methodName, argsString] = handlerExpr.split('(');
    const args = argsString ? argsString.replace(')', '').split(',').map(arg => arg.trim()) : [];
    this.state.handlers = this.state.handlers || new Map();
    this.state.handlers.set(handlerId, (event) => {
      const method = context.methods[methodName.trim()];
      if (typeof method === 'function') {
        method.apply(context, args.concat(event));
      } else {
        ErrorManager.handle(`Method ${methodName.trim()} not found in the component`, {
          context: 'TemplateManager.registerEventHandler',
          data: {methodName, context}
        });
      }
    });

    this.state.itemTimestamps.set(handlerId, Date.now());
    return handlerId;
  },

  /**
   * Drops the registered handlers whose component no longer has a live
   * instance.
   *
   * @returns {void}
   */
  cleanupHandlers() {
    const componentManager = Now.getManager('component');
    if (!componentManager) return;

    const activeIds = new Set(Array.from(componentManager.instances.keys())
      .map(el => el.dataset.componentId)
      .filter(Boolean));

    for (const [handlerId] of this.state.handlers) {
      const [componentId] = handlerId.split('_');
      if (!activeIds.has(componentId)) {
        this.state.handlers.delete(handlerId);
      }
    }
  },

  /**
   * Removes the event listeners and handler records belonging to one component.
   *
   * @param {string} componentId - Component id
   * @returns {void}
   */
  cleanupComponentHandlers(componentId) {
    if (!componentId) return;

    const selector = `[data-handler-id^="${componentId}_"]`;
    document.querySelectorAll(selector).forEach(element => {
      const handlerId = element.dataset.handlerId;
      const handler = this.state.handlers.get(handlerId);
      if (handler) {
        const eventType = handlerId.split('_')[2];
        element.removeEventListener(eventType, handler, true);
        this.state.handlers.delete(handlerId);
        delete element.dataset.handlerId;
      }
    });
  },

  /**
   * Starts the periodic cleanup timer and stops it when the page is unloaded or
   * hidden.
   *
   * @returns {void}
   */
  startCleanupInterval() {
    this.stopCleanupInterval();

    this.state.cleanupInterval = setInterval(() => {
      this.performCleanup();
    }, this.config.cleanup.interval);

    window.addEventListener('beforeunload', () => {
      this.stopCleanupInterval();
    });

    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'hidden') {
        this.stopCleanupInterval();
      }
    });
  },

  /**
   * Stops the periodic cleanup timer.
   *
   * @returns {void}
   */
  stopCleanupInterval() {
    if (this.state.cleanupInterval) {
      clearInterval(this.state.cleanupInterval);
      this.state.cleanupInterval = null;
    }
  },

  /**
   * Releases what a destroyed component left here: its handlers and its cached
   * template.
   *
   * @param {Object} component - Component instance being destroyed
   * @returns {void}
   */
  onComponentDestroy(component) {
    if (component.id) {
      this.cleanupComponentHandlers(component.id);

      const cacheKey = `template:${component.id}`;
      if (this.state.cache.has(cacheKey)) {
        this.state.cache.delete(cacheKey);
        this.state.itemTimestamps.delete(cacheKey);
      }
    }
  },

  /**
   * Runs one maintenance pass: drops cache entries older than the maximum age,
   * in batches so a large cache does not block the frame.
   *
   * @returns {void}
   */
  performCleanup() {
    const now = Date.now();
    const maxBatch = this.config.cleanup.batchSize;
    let processed = 0;

    EventManager.emit('app:cleanup:start', {timestamp: now});

    for (const [key, entry] of this.state.cache) {
      if (now - this.state.itemTimestamps.get(key) > this.config.cleanup.maxCacheAge) {
        this.state.cache.delete(key);
        this.state.itemTimestamps.delete(key);
        processed++;
      }
      if (processed >= maxBatch) break;
    }

    processed = 0;
    for (const [key, value] of this.state.handlers?.entries() || []) {
      if (now - this.state.itemTimestamps.get(key) > this.config.cleanup.maxHandlerAge) {
        this.state.handlers.delete(key);
        this.state.itemTimestamps.delete(key);
        processed++;
      }
      if (processed >= maxBatch) break;
    }

    this.state.lastCleanup = now;

    EventManager.emit('app:cleanup:end', {timestamp: now});
  }
};

if (window.Now?.registerManager) {
  Now.registerManager('template', TemplateManager);
}

// Expose globally
window.TemplateManager = TemplateManager;
