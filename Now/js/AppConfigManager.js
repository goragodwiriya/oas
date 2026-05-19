/**
 * Application Configuration Manager
 * Manages theme switching, CSS variables, and Backend API integration
 *
 * Features:
 * - Light/Dark theme support with system preference detection
 * - Theme persistence (localStorage)
 * - Apply CSS variables from Backend API
 * - Pass full API payload to TemplateManager (ApiComponent-style)
 * - CSS Variables management with security sanitization
 * - data-on-load event execution support (compatible with ApiComponent)
 * - Auto registration with Now framework
 * - Component mode: data-component="config" or data-component="config" for auto-init
 * - Automatic scope isolation: Skips elements managed by other data-loading systems
 *
 * Scope Isolation:
 * AppConfigManager will NOT process data-* directives inside:
 * - [data-component="api"] - ApiComponent manages its own data context
 * - form[data-load-api] - FormManager with API loading manages its own data
 * - [data-load-api] - Any container with API loading (tables, lists, etc.)
 *
 * This prevents conflicts when multiple systems try to process the same data-attr directives.
 *
 * Example Usage:
 * ```html
 * <!-- AppConfigManager WILL process this (global config data) -->
 * <header>
 *   <span data-text="company">Loading...</span>
 * </header>
 *
 * <!-- AppConfigManager WILL NOT process this (FormManager handles it) -->
 * <form data-load-api="/api/user/profile">
 *   <input name="company" data-attr="value:company">
 * </form>
 *
 * <!-- AppConfigManager WILL NOT process this (ApiComponent handles it) -->
 * <div data-component="api" data-api-url="/api/products">
 *   <span data-text="name">Product Name</span>
 * </div>
 * ```
 *
 * API Response Format:
 * {
 *   variables: { '--color-primary': '#29336b', ... },
 *   company: 'My Company',
 *   // ...any other keys returned by backend
 * }
 *
 * @version 1.0
 * @requires EventManager (optional)
 */
const AppConfigManager = {
  /**
   * Default configuration
   * @type {Object}
   */
  config: {
    enabled: false,
    /** @type {string} Default theme to use when no preference is set */
    defaultTheme: 'light',

    /** @type {string} LocalStorage key for theme persistence */
    storageKey: 'app_theme',

    /** @type {boolean} Whether to use system color scheme preference */
    systemPreference: false,

    /** @type {Object} Backend API configuration for loading theme config */
    api: {
      /** @type {boolean} Whether to load theme config from backend API on init */
      enabled: false,
      /** @type {string|null} URL to load theme configuration from */
      configUrl: null,
      /** @type {Object} Custom headers for API requests */
      headers: {},
      /** @type {number} Request timeout in milliseconds */
      timeout: 5000,
      /** @type {boolean} Cache API responses (per URL) */
      cacheResponse: true
    },

    /** @type {Object} Transition/Animation configuration */
    transition: {
      /** @type {boolean} Enable smooth transitions */
      enabled: true,
      /** @type {number} Transition duration in milliseconds */
      duration: 300,
      /** @type {boolean} Hide content during theme switch (anti-FOUC) */
      hideOnSwitch: true,
      /** @type {string} CSS class added to body when theme is loading */
      loadingClass: 'theme-loading',
      /** @type {string} CSS class added to body when theme is ready */
      readyClass: 'theme-ready',
      /** @type {string} CSS class added during theme transition */
      transitionClass: 'theme-transitioning'
    }
  },

  /**
   * Security configuration for CSS variable validation
   * @type {Object}
   */
  security: {
    /** @type {RegExp} Only allow CSS custom properties (--variable-name) */
    allowedPropertyPattern: /^--[\w-]+$/,

    /** @type {RegExp[]} Dangerous patterns to remove from values (XSS prevention) */
    dangerousPatterns: [
      /url\s*\([^)]*\)/gi,             // url() - block pre-wrapped urls (we wrap safe ones ourselves)
      /expression\s*\([^)]*\)/gi,      // expression() - IE specific, can execute JS
      /@import/gi,                      // @import - can load external stylesheets
      /javascript\s*:/gi,              // javascript: protocol
      /data\s*:/gi,                     // data: protocol (can embed scripts)
      /<script/gi,                      // <script tags
      /<\/script/gi,                    // </script closing tags
      /on\w+\s*=/gi,                    // event handlers (onclick, onerror, etc.)
      /behavior\s*:/gi,                // behavior: - IE specific
      /-moz-binding/gi                 // -moz-binding - Firefox specific XBL
    ],

    /** @type {number} Maximum value length to prevent DoS */
    maxValueLength: 500,

    /** @type {Object} URL/Image security settings */
    url: {
      /** @type {boolean} Enable URL detection and auto-wrapping with url() */
      enabled: true,
      /** @type {string[]} Allowed image extensions (lowercase, with dot) */
      allowedExtensions: ['.jpg', '.jpeg', '.png', '.gif', '.webp', '.avif', '.ico'],
      /** @type {boolean} Block path traversal (../) */
      blockPathTraversal: true,
      /** @type {boolean} Remove query strings from URLs */
      stripQueryString: true,
      /** @type {string[]} Allowed origins for absolute URLs (default: same origin) */
      allowedOrigins: [window.location.origin]
    }
  },

  /**
   * Manager state
   * @type {Object}
   */
  state: {
    /** @type {string|null} Current active theme */
    current: null,

    /** @type {boolean} Whether manager is initialized */
    initialized: false,

    /** @type {boolean} Whether theme is ready (for anti-FOUC) */
    ready: false,

    /** @type {boolean} Whether theme is currently transitioning */
    transitioning: false,

    /** @type {Set} Registered toggle elements */
    toggles: new Set(),

    /** @type {{mediaQuery: MediaQueryList, handleChange: Function}|null} System preference listener */
    systemPreference: null,

    /** @type {Object} Cached API responses (keyed by URL) */
    apiCache: {},

    /** @type {Object} Applied CSS variables (inline on :root only) */
    appliedVariables: {},

    /** @type {Object} Variables applied via light-only stylesheet (see LIGHT_THEME_SCOPED_VARS) */
    appliedLightScopedVariables: {},

    /** @type {Object|null} Last API config payload (for diagnostics) */
    lastConfig: null
  },

  /**
   * Custom properties that theme-dark.css overrides on [data-theme="dark"].
   * If these are set as inline styles on :root they win over the stylesheet
   * and break dark mode — apply them only when not in dark mode instead.
   * @type {Set<string>}
   */
  LIGHT_THEME_SCOPED_VARS: new Set(['--color-background', '--color-text']),

  /** @type {string} */
  LIGHT_SCOPED_STYLE_ID: 'app-config-light-theme-vars',

  /**
   * Safe emitter wrapper to avoid runtime errors when EventManager is absent
   * @private
   */
  emit(event, payload) {
    if (window.EventManager?.emit) {
      EventManager.emit(event, payload);
    }
  },

  /**
   * Initialize theme manager
   * @async
   * @param {Object} options - Configuration options
   * @param {string} [options.defaultTheme='light'] - Default theme
   * @param {string} [options.storageKey='app_theme'] - Storage key
   * @param {boolean} [options.systemPreference=true] - Use system preference
   * @returns {Promise<AppConfigManager>} This instance
   */
  async init(options = {}) {
    this.config = {...this.config, ...options};

    // Validate API config early to avoid unsafe fetch targets
    if (this.config.api?.configUrl) {
      const urlIsSafe = this.isSafeApiUrl(this.config.api.configUrl);
      if (!urlIsSafe) {
        console.warn(`AppConfigManager: configUrl "${this.config.api.configUrl}" rejected (only HTTPS same-origin allowed by default)`);
        this.config.api.enabled = false;
      }
    }

    if (!this.config.enabled) {
      this.state.disabled = true;
      return this;
    }

    document.body.classList.add(this.config.transition.loadingClass);

    if (this.config.systemPreference) {
      this.setupSystemPreference();
    }

    await this.loadInitialTheme();

    this.state.initialized = true;

    // Mark theme as ready (anti-FOUC)
    this.markReady();

    this.emit('theme:initialized', {theme: this.state.current});

    return this;
  },

  /**
   * Enhance a theme toggle element (called by ComponentManager)
   * @param {HTMLElement} element - Element to enhance
   */
  enhance(element) {
    if (!element || this.state.toggles.has(element)) return element;

    // Add click handler
    element.addEventListener('click', () => this.toggle());

    // Update state attribute
    this.updateElementState(element);

    // Store reference
    this.state.toggles.add(element);
    element._themeToggle = true;

    return element;
  },

  /**
   * Update element's data-theme-state attribute
   * @param {HTMLElement} element
   */
  updateElementState(element) {
    if (element && this.state.current) {
      element.setAttribute('data-theme-state', this.state.current);
    }
  },

  /**
   * Update all toggle elements state
   */
  updateAllToggles() {
    this.state.toggles.forEach(element => {
      this.updateElementState(element);
    });
  },

  /**
   * Setup system color scheme preference listener
   * @private
   */
  setupSystemPreference() {
    // Clean any existing listener to avoid duplicates on re-init
    this.cleanupSystemPreferenceListener();

    const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');

    const handleChange = (e) => {
      if (!this.getStoredTheme()) {
        this.setTheme(e.matches ? 'dark' : 'light');
      }
    };

    mediaQuery.addEventListener('change', handleChange);
    this.state.systemPreference = {mediaQuery, handleChange};
    handleChange(mediaQuery);
  },

  /**
   * Remove system preference listener if registered
   */
  cleanupSystemPreferenceListener() {
    const watcher = this.state.systemPreference;
    if (watcher?.mediaQuery && watcher.handleChange) {
      watcher.mediaQuery.removeEventListener('change', watcher.handleChange);
    }
    this.state.systemPreference = null;
  },

  /**
   * Toggle between light and dark theme
   */
  toggle() {
    const newTheme = this.state.current === 'dark' ? 'light' : 'dark';
    this.setTheme(newTheme);
  },

  /**
   * Set active theme with optional transition animation
   * @async
   * @param {string} theme - Theme to set ('light' or 'dark' or custom)
   * @param {Object} [options] - Options for this theme change
   * @param {boolean} [options.transition=true] - Whether to animate the transition
   * @fires theme:changed
   */
  async setTheme(theme, options = {}) {
    const shouldTransition = options.transition !== false &&
      this.config.transition.enabled &&
      this.config.transition.hideOnSwitch &&
      this.state.ready; // Only transition if already ready

    if (shouldTransition) {
      await this.startTransition();
    }

    // Apply theme
    document.documentElement.setAttribute('data-theme', theme);
    this.state.current = theme;

    if (this.config.storageKey) {
      this.setStoredTheme(theme);
    }

    // Update all toggle elements
    this.updateAllToggles();

    if (shouldTransition) {
      await this.endTransition();
    }

    this.emit('theme:changed', {theme});
  },

  /**
   * Get current active theme
   * @returns {string} Current theme
   */
  getCurrentTheme() {
    return this.state.current;
  },

  // ============ Transition Methods (Anti-FOUC) ============

  /**
   * Mark theme as ready - adds ready class to body (used for anti-FOUC)
   * Call this after theme is fully loaded and applied
   */
  markReady() {
    if (this.state.ready) return;

    // Ensure body exists before adding class
    const addReadyClass = () => {
      if (document.body) {
        this.state.ready = true;
        document.body.classList.add(this.config.transition.readyClass);
        document.body.classList.remove(this.config.transition.transitionClass);
        document.body.classList.remove(this.config.transition.loadingClass);
        this.emit('theme:ready');
      } else {
        // Body not ready yet, try again
        requestAnimationFrame(addReadyClass);
      }
    };

    addReadyClass();
  },

  /**
   * Start theme transition (fade out)
   * @private
   * @returns {Promise<void>}
   */
  async startTransition() {
    if (!this.config.transition.enabled) return;

    this.state.transitioning = true;
    document.body.classList.add(this.config.transition.transitionClass);
    document.body.classList.remove(this.config.transition.readyClass);
    document.body.classList.remove(this.config.transition.loadingClass);

    // Wait for fade out
    await this.wait(this.config.transition.duration);
  },

  /**
   * End theme transition (fade in)
   * @private
   * @returns {Promise<void>}
   */
  async endTransition() {
    if (!this.config.transition.enabled) return;

    document.body.classList.remove(this.config.transition.transitionClass);
    document.body.classList.add(this.config.transition.readyClass);
    document.body.classList.remove(this.config.transition.loadingClass);
    this.state.transitioning = false;

    // Wait for fade in
    await this.wait(this.config.transition.duration);
  },

  /**
   * Utility: wait for specified milliseconds
   * @private
   * @param {number} ms - Milliseconds to wait
   * @returns {Promise<void>}
   */
  wait(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
  },

  /**
   * Load and apply initial theme
   * @private
   * @async
   */
  async loadInitialTheme() {
    // Load config from API if enabled (variables and stylesheet only)
    if (this.config.api.enabled && this.config.api.configUrl) {
      try {
        await this.loadFromAPI();
      } catch (error) {
        console.warn('AppConfigManager: Failed to load from API', error);
      }
    }

    // Determine theme mode (light/dark) from user preference
    let theme = this.config.defaultTheme;

    const stored = this.getStoredTheme();
    if (stored) {
      theme = stored;
    } else if (this.config.systemPreference) {
      const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
      theme = prefersDark ? 'dark' : 'light';
    }

    await this.setTheme(theme);
  },

  // ============ Security Methods ============

  /**
   * Validate if a CSS property name is allowed (security whitelist)
   * Only CSS custom properties (--*) are allowed to prevent style injection
   * @param {string} property - CSS property name to validate
   * @returns {boolean} True if property is allowed
   */
  isValidProperty(property) {
    if (typeof property !== 'string') return false;
    return this.security.allowedPropertyPattern.test(property);
  },

  /**
   * Check if a value looks like a URL/image path
   * @param {string} value - Value to check
   * @returns {boolean} True if value appears to be a URL path
   */
  isUrlValue(value) {
    if (typeof value !== 'string') return false;
    // Allow local-ish paths (/, ./, or bare relative without protocol)
    const trimmed = value.split('?')[0];
    return /^(https?:\/\/|\/|\.\/)?[\w\-\.\/]+$/.test(trimmed);
  },

  /**
   * Sanitize and validate a URL for use in CSS url()
   * Only allows local paths with valid image extensions
   * @param {string} url - URL to sanitize
   * @returns {string|null} Sanitized URL wrapped in url(), or null if invalid
   */
  sanitizeUrl(url) {
    if (typeof url !== 'string') return null;

    const config = this.security.url;
    let sanitized = url.trim();

    // Handle absolute URLs with protocol
    if (/^[a-z]+:/i.test(sanitized)) {
      try {
        const urlObj = new URL(sanitized);
        const isAllowed = config.allowedOrigins?.some(
          origin => urlObj.origin === origin
        );
        if (!isAllowed) {
          console.warn(`AppConfigManager: Rejected URL "${url}" - origin "${urlObj.origin}" not in allowedOrigins`);
          return null;
        }
        // Use only pathname for allowed origins
        sanitized = urlObj.pathname;
      } catch (e) {
        console.warn(`AppConfigManager: Rejected URL "${url}" - invalid URL format`);
        return null;
      }
    }

    // Normalize bare relative paths to ./
    if (!/^(\/|\.\/)/i.test(sanitized)) {
      sanitized = `./${sanitized}`;
    }

    // Strip query string if configured
    if (config.stripQueryString) {
      sanitized = sanitized.split('?')[0].split('#')[0];
    }

    // Block path traversal (..)
    if (config.blockPathTraversal && sanitized.includes('..')) {
      console.warn(`AppConfigManager: Rejected URL "${url}" - path traversal not allowed`);
      return null;
    }

    // Check file extension
    const ext = sanitized.substring(sanitized.lastIndexOf('.')).toLowerCase();
    if (!config.allowedExtensions.includes(ext)) {
      console.warn(`AppConfigManager: Rejected URL "${url}" - extension "${ext}" not allowed. Allowed: ${config.allowedExtensions.join(', ')}`);
      return null;
    }

    // Wrap in url() and return
    return `url('${sanitized}')`;
  },

  /**
   * Sanitize a CSS value to prevent XSS attacks
   * Removes dangerous patterns like expression(), javascript:, etc.
   * Auto-detects image URLs and wraps them with url()
   * @param {*} value - Value to sanitize
   * @returns {string} Sanitized value
   */
  sanitizeValue(value) {
    // Convert to string if not already
    if (value === null || value === undefined) return '';
    if (typeof value !== 'string') value = String(value);

    let sanitized = value.trim();

    // Check if this looks like an image URL path
    if (this.security.url.enabled && this.isUrlValue(sanitized)) {
      const urlResult = this.sanitizeUrl(sanitized);
      if (urlResult) {
        return urlResult;  // Returns url('/path/to/image.jpg')
      }
      // If URL validation failed, return empty to prevent invalid value
      return '';
    }

    // For non-URL values, apply standard sanitization
    // Remove all dangerous patterns
    for (const pattern of this.security.dangerousPatterns) {
      sanitized = sanitized.replace(pattern, '');
    }

    // Trim to max length to prevent DoS
    if (sanitized.length > this.security.maxValueLength) {
      sanitized = sanitized.substring(0, this.security.maxValueLength);
    }

    return sanitized.trim();
  },

  /**
   * Validate API response format
   * @param {*} response - API response to validate
   * @throws {Error} If response format is invalid
   * @returns {boolean} True if valid
   */
  validateApiResponse(response) {
    if (!response || typeof response !== 'object') {
      throw new Error('AppConfigManager: Invalid API response format - expected object');
    }

    // Validate variables if present
    if (response.variables !== undefined) {
      if (typeof response.variables !== 'object' || Array.isArray(response.variables)) {
        throw new Error('AppConfigManager: Invalid variables format - expected object');
      }
    }

    return true;
  },

  // ============ CSS Variables Methods ============

  /**
   * Escape a fragment for use inside a CSS custom property value in a <style> block.
   * @param {string} value
   * @returns {string}
   * @private
   */
  escapeCssCustomPropertyValue(value) {
    if (typeof value !== 'string') return '';
    return value.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
  },

  /**
   * Inject or update a stylesheet so light-only variables do not override [data-theme="dark"].
   * @param {Object<string, string>} scopedVars property -> sanitized value
   * @private
   */
  applyLightScopedVariables(scopedVars) {
    const keys = Object.keys(scopedVars);
    if (keys.length === 0) {
      this.removeLightScopedStyleElement();
      this.state.appliedLightScopedVariables = {};
      return;
    }

    const declarations = keys
      .map((prop) => `  ${prop}: ${this.escapeCssCustomPropertyValue(scopedVars[prop])};`)
      .join('\n');

    const css = `:root:not([data-theme="dark"]) {\n${declarations}\n}`;

    let el = document.getElementById(this.LIGHT_SCOPED_STYLE_ID);
    if (!el) {
      el = document.createElement('style');
      el.id = this.LIGHT_SCOPED_STYLE_ID;
      el.setAttribute('type', 'text/css');
      (document.head || document.documentElement).appendChild(el);
    }
    el.textContent = css;
    this.state.appliedLightScopedVariables = {...scopedVars};
  },

  /**
   * Remove light-scoped theme <style> element
   * @private
   */
  removeLightScopedStyleElement() {
    const el = document.getElementById(this.LIGHT_SCOPED_STYLE_ID);
    if (el && el.parentNode) {
      el.parentNode.removeChild(el);
    }
    this.state.appliedLightScopedVariables = {};
  },

  /**
   * Apply CSS variables to document root with security validation
   * Only applies CSS custom properties (--*) to prevent style injection
   * @param {Object} variables - Object of CSS variable name -> value pairs
   * @returns {Object} Applied variables (after sanitization)
   */
  applyVariables(variables) {
    if (!variables || typeof variables !== 'object') {
      console.warn('AppConfigManager: applyVariables requires an object');
      return {};
    }

    const MAX_KEYS = 200;
    const entries = Object.entries(variables);
    if (entries.length > MAX_KEYS) {
      console.warn(`AppConfigManager: Too many variables (${entries.length}). Applying first ${MAX_KEYS} only.`);
    }

    const limitedEntries = entries.slice(0, MAX_KEYS);

    const appliedInline = {};
    const appliedLightScoped = {};
    const root = document.documentElement;
    let rejectedCount = 0;

    // Never keep these on inline :root — they would override [data-theme="dark"] rules
    for (const property of this.LIGHT_THEME_SCOPED_VARS) {
      root.style.removeProperty(property);
    }

    for (const [property, value] of limitedEntries) {
      // Security: Only allow CSS custom properties
      if (!this.isValidProperty(property)) {
        rejectedCount++;
        console.warn(`AppConfigManager: Rejected invalid property "${property}" - only CSS custom properties (--*) are allowed`);
        continue;
      }

      // Security: Sanitize value
      const sanitizedValue = this.sanitizeValue(value);

      // Skip setting empty/cleared values to avoid overwriting safe existing values
      if (!sanitizedValue) {
        console.warn(`AppConfigManager: Skipped empty/unsafe value for "${property}"`);
        continue;
      }

      if (this.LIGHT_THEME_SCOPED_VARS.has(property)) {
        appliedLightScoped[property] = sanitizedValue;
      } else {
        root.style.setProperty(property, sanitizedValue);
        appliedInline[property] = sanitizedValue;
      }
    }

    this.applyLightScopedVariables(appliedLightScoped);

    // Replace inline set each call: drop old inline keys we no longer send
    const prevInline = this.state.appliedVariables;
    for (const property of Object.keys(prevInline)) {
      if (!appliedInline[property]) {
        root.style.removeProperty(property);
      }
    }

    this.state.appliedVariables = appliedInline;

    if (rejectedCount > 0) {
      console.warn(`AppConfigManager: Rejected ${rejectedCount} invalid properties`);
    }

    const appliedAll = {...appliedLightScoped, ...appliedInline};
    this.emit('theme:variables-applied', {variables: appliedAll});

    return appliedAll;
  },

  /**
   * Clear all applied CSS variables
   */
  clearVariables() {
    const root = document.documentElement;

    for (const property of Object.keys(this.state.appliedVariables)) {
      root.style.removeProperty(property);
    }

    this.removeLightScopedStyleElement();
    this.state.appliedVariables = {};
    this.emit('theme:variables-cleared');
  },

  /**
   * Get all currently applied CSS variables
   * @returns {Object} Applied variables
   */
  getAppliedVariables() {
    return {...this.state.appliedLightScopedVariables, ...this.state.appliedVariables};
  },

  /**
   * Check if element is in an isolated data-loading scope
   * Elements in these scopes are managed by other systems and should be skipped
   * @param {HTMLElement} element - Element to check
   * @returns {boolean} True if element is in isolated scope
   * @private
   */
  isInIsolatedScope(element) {
    // Skip elements managed by other data-loading components/systems
    if (element.closest('[data-component="api"]')) return true;       // ApiComponent
    if (element.closest('form[data-load-api]')) return true;          // FormManager with API loading
    if (element.closest('[data-load-api]')) return true;              // Any container with API loading
    return false;
  },

  /**
   * Process template bindings using full API payload (ApiComponent-style)
   * Automatically skips elements in isolated scopes (FormManager, ApiComponent, etc.)
   * @param {Object} config - Full API response payload
   * @private
   */
  processTemplateBindings(config) {
    const templateManager = window.TemplateManager;
    if (!templateManager || typeof templateManager.processDataDirectives !== 'function') {
      return;
    }

    const context = {
      state: config || {},
      data: config || {},
      computed: {}
    };

    try {
      const containers = new Set();
      const selector = '[data-text], [data-html], [data-if], [data-class], [data-attr], [data-style], [data-checked], [data-model], [data-for], [data-on], [data-container], [data-on-load]';

      document.querySelectorAll(selector).forEach(el => {
        // Use helper method to check if element is in isolated scope
        if (this.isInIsolatedScope(el)) return;

        const container = el.closest('footer, header, nav, section, aside, main') || el.parentElement || document.body;
        if (container) {
          containers.add(container);
        }
      });

      containers.forEach(container => {
        templateManager.processDataDirectives(container, context);
        if (typeof templateManager.processDataOnLoad === 'function') {
          templateManager.processDataOnLoad(container, context);
        }
      });
    } catch (error) {
      console.error('[AppConfigManager] Error processing template bindings:', error);
    }
  },

  /**
   * Load theme configuration from Backend API
   * Applies CSS variables and optionally loads a stylesheet
   * Note: Does NOT affect light/dark mode (user controls that via toggle)
   * @param {string} [url] - Optional URL override (uses config.api.configUrl if not provided)
   * @returns {Promise<Object>} Theme configuration from API
   * @throws {Error} If request fails or response is invalid
   */
  async loadFromAPI(url) {
    const configUrl = url || this.config.api.configUrl;

    if (!configUrl) {
      throw new Error('AppConfigManager: No API config URL specified');
    }

    if (!this.isSafeApiUrl(configUrl)) {
      throw new Error('AppConfigManager: Unsafe API config URL');
    }

    // Return cached response if available and caching is enabled
    // But still apply variables and stylesheet (cache saves HTTP request only)
    if (this.config.api.cacheResponse && this.state.apiCache[configUrl]) {
      const cached = this.state.apiCache[configUrl];
      await this.applyApiConfig(cached);
      this.emit('theme:api-loaded', {config: cached, fromCache: true});
      return cached;
    }

    try {
      // Use HttpClient if available (includes security features)
      const httpClient = window.http || window.HttpClient;

      let response = await httpClient.get(configUrl, {
        timeout: this.config.api.timeout,
        headers: this.config.api.headers
      });

      // Handle HttpClient response format
      if (response.success === false) {
        throw new Error(response.error || 'API request failed');
      }

      response = response.data?.data || response.data || response;

      // Validate response format
      this.validateApiResponse(response);

      // Cache the response (per URL)
      if (this.config.api.cacheResponse) {
        this.state.apiCache[configUrl] = response;
      }

      // Apply the configuration
      await this.applyApiConfig(response);

      this.emit('theme:api-loaded', {config: response});

      return response;

    } catch (error) {
      this.emit('theme:api-error', {error: error.message, url: configUrl});
      throw error;
    }
  },

  /**
   * Apply configuration from API response
   * Expected format: { variables: {...}, ...any other backend keys }
   * @private
   * @param {Object} config - Full API response payload
   */
  async applyApiConfig(config) {
    // Apply CSS variables if present
    if (config.variables) {
      this.applyVariables(config.variables);
    }

    // Store last config for diagnostics
    this.state.lastConfig = config || null;

    // Process template bindings using the full API payload
    this.processTemplateBindings(config);
  },

  isSafeApiUrl(configUrl) {
    try {
      const urlObj = new URL(configUrl, window.location.origin);
      const isHttp = urlObj.protocol === 'https:' || urlObj.protocol === 'http:';
      const isSameOrigin = urlObj.origin === window.location.origin;

      // Default policy: allow only https same-origin; allow http only if same-origin and current origin is http
      const originProtocol = window.location.protocol;
      const httpAllowed = originProtocol === 'http:' && urlObj.origin === window.location.origin;

      if (!isHttp) return false;
      if (urlObj.protocol === 'https:' && isSameOrigin) return true;
      if (httpAllowed) return true;
      return false;
    } catch (e) {
      return false;
    }
  },

  /**
   * Reload theme configuration from API (clears cache first)
   * @returns {Promise<Object>} Fresh theme configuration
   */
  async refreshFromAPI() {
    this.state.apiCache = {};
    return this.loadFromAPI();
  },

  /**
   * Destroy the AppConfigManager instance and clean up all resources
   * Call this when unmounting the app or cleaning up
   */
  destroy() {
    // Remove system preference listener
    this.cleanupSystemPreferenceListener();

    // Remove all toggle event listeners
    this.state.toggles.forEach(element => {
      if (element._themeToggle) {
        // Clone to remove all listeners (simple approach)
        const parent = element.parentNode;
        if (parent) {
          const clone = element.cloneNode(true);
          delete clone._themeToggle;
          parent.replaceChild(clone, element);
        }
      }
    });
    this.state.toggles.clear();

    // Clear applied CSS variables
    this.clearVariables();

    // Remove ready/transition classes from body
    document.body.classList.remove(this.config.transition.readyClass);
    document.body.classList.remove(this.config.transition.transitionClass);
    document.body.classList.remove(this.config.transition.loadingClass);

    // Clear cache
    this.state.apiCache = {};

    // Reset state
    this.state.current = null;
    this.state.initialized = false;
    this.state.ready = false;
    this.state.transitioning = false;
    this.state.appliedVariables = {};
    this.state.lastConfig = null;

    this.emit('theme:destroyed');
  },

  /**
   * Reset AppConfigManager to default state without full destroy
   * Useful for re-initialization with different config
   */
  reset() {
    // Remove system preference listener
    this.cleanupSystemPreferenceListener();

    // Clear variables but don't remove listeners
    this.clearVariables();

    // Clear cache
    this.state.apiCache = {};

    // Reset state flags
    this.state.current = null;
    this.state.initialized = false;
    this.state.ready = false;
    this.state.lastConfig = null;

    // Remove ready class
    document.body.classList.remove(this.config.transition.readyClass);
    document.body.classList.remove(this.config.transition.loadingClass);

    this.emit('theme:reset');
  }
};

// LocalStorage helpers with safety (quota/blocked storage)
AppConfigManager.getStoredTheme = function() {
  if (!this.config.storageKey) return null;
  try {
    return localStorage.getItem(this.config.storageKey);
  } catch (e) {
    console.warn('AppConfigManager: Unable to read from localStorage', e);
    return null;
  }
};

AppConfigManager.setStoredTheme = function(theme) {
  if (!this.config.storageKey) return;
  try {
    localStorage.setItem(this.config.storageKey, theme);
  } catch (e) {
    console.warn('AppConfigManager: Unable to write to localStorage', e);
  }
};

// Register with Now framework
if (window.Now?.registerManager) {
  Now.registerManager('config', AppConfigManager);
}

// Register with ComponentManager for data-component="config" support
if (window.ComponentManager) {
  // Register as 'config' (primary)
  ComponentManager.define('config', {
    template: null,

    // Called when component is mounted
    mounted() {
      AppConfigManager.enhance(this.element);
    },

    // Called when component is destroyed
    destroyed() {
      if (this.element?._themeToggle) {
        AppConfigManager.state.toggles.delete(this.element);
      }
    }
  });
}

// Auto-initialize components on DOM ready (fallback if ComponentManager didn't init during load)
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-component="config"]').forEach(el => {
      if (!el._themeToggle) {
        AppConfigManager.enhance(el);
      }
    });
  });
}

// Export to window
window.AppConfigManager = AppConfigManager;
