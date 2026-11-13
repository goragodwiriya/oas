/**
 * SecurityManager - Central security management system
 * Manage CSRF, JWT, and other security.
 * Note: Rate limiting is handled by backend.
 */
const SecurityManager = {
  config: {
    // CSRF Configuration
    csrf: {
      enabled: false,
      tokenName: '_token',
      headerName: 'X-CSRF-Token',
      cookieName: 'XSRF-TOKEN',
      tokenUrl: 'api/auth/csrf-token',
      metaName: 'csrf-token',
      autoRefresh: true,
      refreshInterval: 30 * 60 * 1000, // 30 minutes
      validateOnSubmit: true,
      requireForMethods: ['POST', 'PUT', 'PATCH', 'DELETE'],
      excludePaths: ['api/public/*', '/auth/verify'],
      tokenLength: 40
    },

    // JWT Configuration
    jwt: {
      enabled: true,
      cookieName: 'auth_token',
      refreshCookieName: 'refresh_token',
      // MUST NOT be 'auth_user': that key belongs to AuthManager, which stores
      // the user PROFILE there (a JSON object) for display. This one expects a
      // JWT STRING. Sharing the key made initJWT() read the profile, fail to
      // validate it as a JWT, and clearJWTToken() delete it on every page load,
      // so the profile cache never survived and the app could not work offline.
      // (Real tokens here are httpOnly cookies, so this key is rarely used.)
      storageKey: 'auth_jwt',
      // Where a token handed to JavaScript (refreshJWTToken) is kept:
      // 'memory' (default) — only in state, gone on reload and unreadable from
      // storage by injected script; 'localStorage' — survives reloads under
      // storageKey, for backends that do not use httpOnly cookies.
      // Kotchasan/Gcms deliver tokens as httpOnly cookies, so neither is used there.
      storage: 'memory',
      autoRefresh: true,
      refreshBeforeExpiry: 5 * 60 * 1000, // 5 minutes
      refreshEndpoint: 'api/auth/refresh',
      validateSignature: true,
      algorithm: 'HS256',
      issuer: window.location.hostname,
      audience: window.location.hostname
    },



    // Origins other than the page's own that may receive the CSRF token and the
    // Authorization header, and whose responses may hand us a new CSRF token,
    // e.g. ['https://api.example.com']. Empty: this origin only.
    trustedOrigins: [],

    // Client-side cleaning of request bodies. Off: the server is the one place
    // that filters input (a browser-side filter can be skipped by anyone).
    // Turned on, only string values that contain HTML are cleaned; arrays,
    // Date, File/Blob and plain strings go as they are.
    sanitization: {
      enabled: false,
      removeScripts: true,
      removeEvents: true,
      allowedTags: ['b', 'i', 'u', 'strong', 'em']
    },

    // The HTML sanitizer every render path uses (sanitizeHtml / sanitizeTree).
    // Two levels:
    // - untrusted (default): data, server responses, table {html} cells,
    //   data-html, dialogs, MediaViewer — the tags, attributes and style
    //   properties below, iframes only from iframeHosts.
    // - trusted: templates from our own origin (TemplateManager) — templateTags,
    //   every attribute except on* handlers, srcdoc, http-equiv, URLs whose
    //   scheme is not in urlSchemes and styles that carry script.
    html: {
      // Schemes a URL may carry; a URL without a scheme (path, ../, ?q, #id, //host) is always kept.
      // data:image/... is also kept where an image is expected.
      urlSchemes: ['http:', 'https:', 'mailto:', 'tel:'],
      // host + path prefixes an untrusted <iframe src> must start with
      iframeHosts: ['www.youtube.com/embed/', 'www.youtube-nocookie.com/embed/', 'player.vimeo.com/video/'],
      iframeSandbox: 'allow-scripts allow-same-origin allow-presentation allow-popups',
      tags: [
        'a', 'abbr', 'address', 'article', 'aside', 'audio', 'b', 'bdi', 'bdo', 'big', 'blockquote', 'br',
        'caption', 'center', 'cite', 'code', 'col', 'colgroup', 'dd', 'del', 'details', 'dfn', 'div', 'dl',
        'dt', 'em', 'figcaption', 'figure', 'font', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header',
        'hr', 'i', 'iframe', 'img', 'ins', 'kbd', 'li', 'main', 'mark', 'nav', 'ol', 'p', 'picture', 'pre',
        'q', 'rp', 'rt', 'ruby', 's', 'samp', 'section', 'small', 'source', 'span', 'strike', 'strong',
        'sub', 'summary', 'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'time', 'tr', 'track',
        'tt', 'u', 'ul', 'var', 'video', 'wbr'
      ],
      // '*' applies to every tag; a trailing * matches a prefix (aria-*, data-*)
      attributes: {
        // id is kept unless it names something window or document already has (DOM clobbering)
        // data-* is not here: framework directives are data-*, see dataAttributes
        '*': ['id', 'class', 'title', 'lang', 'dir', 'role', 'translate', 'align', 'style', 'aria-*'],
        a: ['href', 'target', 'rel', 'hreflang'],
        img: ['src', 'srcset', 'sizes', 'alt', 'width', 'height', 'loading', 'decoding'],
        video: ['src', 'poster', 'controls', 'width', 'height', 'preload', 'loop', 'muted', 'playsinline'],
        audio: ['src', 'controls', 'preload', 'loop', 'muted'],
        source: ['src', 'srcset', 'sizes', 'type', 'media'],
        track: ['src', 'kind', 'srclang', 'label', 'default'],
        iframe: ['src', 'width', 'height', 'allow', 'allowfullscreen', 'frameborder', 'loading', 'referrerpolicy'],
        table: ['border', 'cellpadding', 'cellspacing', 'width'],
        td: ['colspan', 'rowspan', 'headers', 'width', 'height', 'valign'],
        th: ['colspan', 'rowspan', 'headers', 'scope', 'abbr', 'width', 'height', 'valign'],
        tr: ['valign'],
        col: ['span', 'width'],
        colgroup: ['span', 'width'],
        ol: ['start', 'type', 'reversed'],
        ul: ['type'],
        li: ['value'],
        time: ['datetime'],
        del: ['cite', 'datetime'],
        ins: ['cite', 'datetime'],
        q: ['cite'],
        blockquote: ['cite'],
        font: ['color', 'face', 'size'],
        details: ['open']
      },
      // data-* attributes untrusted HTML may keep (a trailing * matches a prefix).
      // Empty by default: data-on, data-action, data-component, data-api-* and the
      // other directives would turn data into behaviour, so every data-* goes.
      dataAttributes: [],
      // Inline style properties untrusted HTML may keep. A value with url(),
      // expression(), a script scheme, @import, a backslash, < > or a comment is dropped.
      styleProperties: [
        'color', 'background-color', 'font', 'font-family', 'font-size', 'font-style', 'font-weight',
        'font-variant', 'line-height', 'letter-spacing', 'word-spacing', 'text-align', 'text-decoration',
        'text-decoration-line', 'text-decoration-color', 'text-decoration-style', 'text-indent',
        'text-transform', 'vertical-align', 'white-space', 'word-break', 'overflow-wrap', 'direction',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'border', 'border-top', 'border-right', 'border-bottom', 'border-left', 'border-width',
        'border-style', 'border-color', 'border-radius', 'border-collapse', 'border-spacing',
        'width', 'height', 'max-width', 'max-height', 'min-width', 'min-height', 'aspect-ratio',
        'float', 'clear', 'display', 'list-style-type', 'list-style-position', 'table-layout',
        'caption-side', 'empty-cells', 'object-fit'
      ],
      // Trusted templates: tags outside this list are removed with their content.
      // Names are lowercase (SVG's linearGradient is 'lineargradient'). SVG keeps
      // its drawing tags only: no script, foreignObject, animate, set or
      // animateTransform, and <use> may point at #id only.
      templateTags: [
        'html', 'head', 'body', 'title', 'meta', 'link', 'style',
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
        'big', 'strike', 'marquee',
        'optgroup', 'del', 'ins', 'q', 'address', 'dfn', 'bdi', 'bdo', 'data', 'hgroup', 'search',
        'g', 'path', 'circle', 'rect', 'line', 'polyline', 'polygon', 'ellipse', 'text', 'tspan',
        'defs', 'lineargradient', 'radialgradient', 'stop', 'use', 'symbol', 'clippath', 'mask'
      ]
    },

    // Content Security Policy
    csp: {
      enabled: true,
      directives: {
        'default-src': ["'self'"],
        // Social SDK hosts; no 'unsafe-inline' (framework markup has no inline
        // handlers) and no 'unsafe-eval' (add it when ResponseHandler.allowEval is on)
        'script-src': ["'self'", "https://accounts.google.com", "https://apis.google.com", "https://connect.facebook.net", "https://telegram.org", "https://*.telegram.org"],
        'style-src': ["'self'", "'unsafe-inline'"],
        'img-src': ["'self'", "data:", "https:"],
        'font-src': ["'self'"],
        'connect-src': ["'self'"],
        'frame-src': ["'self'", "https://accounts.google.com", "https://www.google.com", "https://maps.google.com"],
        'object-src': ["'none'"]
      },
      // Endpoint that receives violation reports (POST JSON); '' = do not report
      reportUri: '',
      // A violation is recorded and reported once however often it repeats
      // (same directive, blocked URI, source and line); distinct violations are
      // kept up to maxViolations and posted to reportUri up to maxReports per page
      maxViolations: 50,
      maxReports: 10
    }
  },

  state: {
    initialized: false,
    // config.html is fixed after the first init()
    htmlLocked: false,
    csrfToken: null,
    jwtToken: null,
    lastTokenRefresh: null,
    securityHeaders: new Map(),
    // Distinct CSP violations by key (see handleCSPViolation), each with a count
    violations: new Map(),
    cspReports: 0,
    cspListener: null
  },

  /**
   * Initializes the security layer: CSRF tokens, JWT handling, CSP and the
   * HTTP interceptors, each according to its own enabled flag.
   *
   * The HTML rules (config.html: tags, attributes, URL schemes, data-*
   * attributes, iframe hosts, ...) are set by the first init() — the one
   * Now.init() runs with its `security` section — and are fixed from then on:
   * a later init() keeps them, so nothing that can call init() again can widen
   * what sanitizeHtml() lets through.
   *
   * @param {Object} [options={}] - Configuration merged over the defaults
   * @returns {Promise<Object>} The manager instance
   * @throws {Error} When initialization fails
   */
  async init(options = {}) {
    try {
      if (this.state.htmlLocked && options && typeof options === 'object' && Object.hasOwn(options, 'html')) {
        const {html, ...rest} = options;
        console.warn('SecurityManager: config.html is set by the first init() and is not changed by a later one');
        options = rest;
      }
      this.config = this.mergeDeep(this.config, options);
      if (!this.state.htmlLocked) {
        this.state.htmlLocked = true;
        this.config.html = this.freezeDeep(this.config.html);
      }

      // Initialize CSRF protection
      if (this.config.csrf.enabled) {
        await this.initCSRF();
      }

      // Initialize JWT management
      if (this.config.jwt.enabled) {
        await this.initJWT();
      }



      // Initialize CSP
      if (this.config.csp.enabled) {
        this.initCSP();
      }

      // Setup HTTP interceptors
      this.setupHttpInterceptors();

      this.state.initialized = true;

      this.emit('security:initialized', {
        csrf: this.config.csrf.enabled,
        jwt: this.config.jwt.enabled
      });

      return this;

    } catch (error) {
      this.handleError('Security initialization failed', error);
      throw error;
    }
  },

  // ============ CSRF Management ============
  /**
   * Prepares CSRF protection: reads or fetches the token, starts the refresh
   * timer, and injects the token into the forms already on the page.
   *
   * @returns {Promise<void>}
   */
  async initCSRF() {
    try {
      // Get existing token
      this.state.csrfToken = this.getCSRFToken();

      // Generate new token if needed
      if (!this.state.csrfToken) {
        await this.refreshCSRFToken();
      }

      // Setup auto-refresh
      if (this.config.csrf.autoRefresh) {
        this.startCSRFRefresh();
      }

      // Inject into existing forms
      this.injectCSRFIntoForms();
    } catch (error) {
      this.handleError('CSRF initialization failed', error);
    }
  },

  /**
   * Reads the current CSRF token, trying the cookie, then the meta tag, then
   * a hidden input.
   *
   * @returns {string|null} Token, or null when none is present
   */
  getCSRFToken() {
    // Priority: Cookie > Meta tag > Input field
    let token = null;

    // Try cookie first
    if (this.config.csrf.cookieName) {
      token = this.getCookie(this.config.csrf.cookieName);
    }

    // Try meta tag
    if (!token && this.config.csrf.metaName) {
      const meta = document.querySelector(`meta[name="${this.config.csrf.metaName}"]`);
      token = meta?.getAttribute('content');
    }

    // Try hidden input
    if (!token) {
      const input = document.querySelector(`input[name="${this.config.csrf.tokenName}"]`);
      token = input?.value;
    }

    return token;
  },

  /**
   * Fetches a new CSRF token from the server and propagates it to the meta tag
   * and every form on the page.
   *
   * @returns {Promise<string|null>} New token, or null when the request failed
   */
  async refreshCSRFToken() {
    try {
      if (!this.config.csrf.enabled) return null;

      const apiService = window.ApiService || window.Now?.getManager?.('api');
      const headers = {
        'X-Requested-With': 'XMLHttpRequest'
      };

      let response;
      if (apiService?.get) {
        response = await apiService.get(this.config.csrf.tokenUrl, {}, {headers});
      } else if (window.simpleFetch?.get) {
        response = await simpleFetch.get(this.config.csrf.tokenUrl, {headers});
      } else {
        throw new Error('ApiService is not available');
      }

      if (!response.success) {
        // The API's own message says what actually went wrong (a missing endpoint,
        // a permission problem); the status alone says almost nothing.
        const reason = response.data?.message || response.statusText || response.status;
        throw new Error(`Failed to get CSRF token: ${reason}`);
      }

      const data = response.data || {};
      this.state.csrfToken = data.data?.csrf_token || null;

      if (!this.state.csrfToken) {
        throw new Error('Failed to get CSRF token: response carried no csrf_token');
      }

      // Update meta tag
      this.updateCSRFMeta(this.state.csrfToken);

      // Update all forms
      this.updateCSRFInForms(this.state.csrfToken);

      this.state.lastTokenRefresh = Date.now();
      this.emit('csrf:refreshed', {token: this.state.csrfToken});

      return this.state.csrfToken;

    } catch (error) {
      this.handleError('CSRF token refresh failed', error);
      return null;
    }
  },

  /**
   * Writes the token into the CSRF meta tag, creating the tag when absent.
   *
   * @param {string} token - CSRF token
   * @returns {void}
   */
  updateCSRFMeta(token) {
    let meta = document.querySelector(`meta[name="${this.config.csrf.metaName}"]`);
    if (!meta) {
      meta = document.createElement('meta');
      meta.name = this.config.csrf.metaName;
      document.head.appendChild(meta);
    }
    meta.setAttribute('content', token);
  },

  /**
   * Injects the current CSRF token into every form in the document.
   *
   * @returns {void}
   */
  injectCSRFIntoForms() {
    if (!this.state.csrfToken) return;

    document.querySelectorAll('form').forEach(form => {
      this.injectCSRFIntoForm(form, this.state.csrfToken);
    });
  },

  /**
   * Adds or updates the hidden CSRF input of one form.
   *
   * Forms whose method needs no token, whose action is excluded, or which
   * carry data-csrf="false" are skipped.
   *
   * @param {HTMLFormElement} form - Form to protect
   * @param {string} [token=null] - Token to use; defaults to the current one
   * @returns {void}
   */
  injectCSRFIntoForm(form, token = null) {
    if (!this.config.csrf.enabled) return;

    token = token || this.state.csrfToken;
    if (!token) return;

    // Check if form should be excluded
    const action = form.getAttribute('action') || '';
    const method = (form.getAttribute('method') || 'GET').toUpperCase();

    if (!this.config.csrf.requireForMethods.includes(method)) {
      return;
    }

    if (this.isPathExcluded(action)) {
      return;
    }

    // A form that posts to another origin never carries our token
    if (!this.isTrustedOrigin(action)) {
      return;
    }

    // Check data-csrf attribute
    const csrfSetting = form.dataset.csrf;
    if (csrfSetting === 'false' || csrfSetting === 'disabled') {
      return;
    }

    // Find or create CSRF input
    let csrfInput = form.querySelector(`input[name="${this.config.csrf.tokenName}"]`);
    if (!csrfInput) {
      csrfInput = document.createElement('input');
      csrfInput.type = 'hidden';
      csrfInput.name = this.config.csrf.tokenName;
      form.appendChild(csrfInput);
    }

    csrfInput.value = token;
  },

  /**
   * Rewrites the value of every CSRF input in the document.
   *
   * @param {string} token - New CSRF token
   * @returns {void}
   */
  updateCSRFInForms(token) {
    document.querySelectorAll(`input[name="${this.config.csrf.tokenName}"]`).forEach(input => {
      input.value = token;
    });
  },

  /**
   * Starts the CSRF refresh timer, replacing any timer already running.
   *
   * @returns {void}
   */
  startCSRFRefresh() {
    if (this.csrfRefreshTimer) {
      clearInterval(this.csrfRefreshTimer);
    }

    this.csrfRefreshTimer = setInterval(async () => {
      await this.refreshCSRFToken();
    }, this.config.csrf.refreshInterval);
  },

  // ============ JWT Management ============
  /**
   * Prepares JWT handling: loads the stored token, drops it when it fails the
   * structural check, and starts the refresh timer when it is still usable.
   *
   * @returns {Promise<void>}
   */
  async initJWT() {
    try {
      // In memory mode, drop a token an earlier version left in localStorage
      if (this.config.jwt.storage !== 'localStorage') {
        try {
          localStorage.removeItem(this.config.jwt.storageKey);
        } catch (e) {
          // storage unavailable
        }
      }

      // Get existing token
      this.state.jwtToken = this.getJWTToken();

      // Validate token if exists
      if (this.state.jwtToken) {
        const isValid = this.validateJWTToken(this.state.jwtToken);
        if (!isValid) {
          await this.clearJWTToken();
        }
      }

      // Setup auto-refresh
      if (this.config.jwt.autoRefresh && this.state.jwtToken) {
        this.startJWTRefresh();
      }
    } catch (error) {
      this.handleError('JWT initialization failed', error);
    }
  },

  /**
   * Reads the JWT held client-side: from memory (jwt.storage 'memory', the
   * default) or from localStorage under jwt.storageKey ('localStorage').
   *
   * Tokens delivered as httpOnly cookies are invisible here by design; this
   * only covers a token the application was handed in a response body.
   *
   * @returns {string|null} Token, or null
   */
  getJWTToken() {
    // Tokens delivered as httpOnly cookies never reach JavaScript; this covers
    // a token the application was handed (jwt.storage decides where it lives)
    if (this.config.jwt.storage === 'localStorage') {
      try {
        return localStorage.getItem(this.config.jwt.storageKey);
      } catch (e) {
        return null;
      }
    }
    return this.state.jwtToken || null;
  },

  /**
   * Structural pre-check for a JWT held client-side.
   *
   * SECURITY: This does NOT and CANNOT verify the token signature — the secret
   * lives only on the server. A `true` result means "this token is well-formed,
   * unexpired, and claims our issuer/audience", NOT "this token is authentic".
   * The server `/auth/verify` endpoint (see AuthManager.checkAuthStatus) is the
   * sole authority for authentication/authorization. Use this only to decide
   * whether to keep or drop a locally cached token before hitting the server.
   */
  validateJWTToken(token) {
    if (!token) return false;

    try {
      const parts = token.split('.');
      if (parts.length !== 3) return false;

      // Reject forged "alg: none" tokens and any algorithm we do not expect.
      // (Defence-in-depth: the server enforces this too.)
      const header = JSON.parse(this.base64UrlDecode(parts[0]));
      const alg = String(header.alg || '').toUpperCase();
      if (alg === 'NONE' || alg !== String(this.config.jwt.algorithm).toUpperCase()) {
        return false;
      }

      const payload = JSON.parse(this.base64UrlDecode(parts[1]));

      // Check expiration (with small clock-skew leeway)
      if (payload.exp && Date.now() >= (payload.exp * 1000) + 0) {
        return false;
      }

      // Reject tokens that are not yet valid
      if (payload.nbf && Date.now() < payload.nbf * 1000) {
        return false;
      }

      // Check issuer
      if (this.config.jwt.issuer && payload.iss !== this.config.jwt.issuer) {
        return false;
      }

      // Check audience
      if (this.config.jwt.audience && payload.aud !== this.config.jwt.audience) {
        return false;
      }

      return true;

    } catch (error) {
      return false;
    }
  },

  /**
   * Decodes a base64url segment. JWT uses base64url, not standard base64, so
   * the alphabet is translated and the padding restored before decoding.
   *
   * @param {string} segment - Base64url encoded segment
   * @returns {string} Decoded string
   */
  base64UrlDecode(segment) {
    const padded = segment.replace(/-/g, '+').replace(/_/g, '/');
    const pad = padded.length % 4 ? '='.repeat(4 - (padded.length % 4)) : '';
    return atob(padded + pad);
  },

  /**
   * Drops the stored JWT, stops the refresh timer and emits jwt:cleared.
   *
   * @returns {Promise<void>}
   */
  async clearJWTToken() {
    this.state.jwtToken = null;
    try {
      localStorage.removeItem(this.config.jwt.storageKey);
    } catch (e) {
      // storage unavailable
    }

    if (this.jwtRefreshTimer) {
      clearInterval(this.jwtRefreshTimer);
    }

    this.emit('jwt:cleared');
  },

  /**
   * Schedules the next JWT refresh for refreshBeforeExpiry milliseconds before
   * the token expires. Does nothing when the token already expired.
   *
   * @returns {void}
   */
  startJWTRefresh() {
    if (this.jwtRefreshTimer) {
      clearInterval(this.jwtRefreshTimer);
    }

    // Calculate refresh time based on token expiry
    const token = this.state.jwtToken;
    if (!token) return;

    try {
      const payload = JSON.parse(this.base64UrlDecode(token.split('.')[1]));
      const exp = payload.exp;
      const now = Math.floor(Date.now() / 1000);
      const timeUntilRefresh = (exp - now) * 1000 - this.config.jwt.refreshBeforeExpiry;

      if (timeUntilRefresh > 0) {
        this.jwtRefreshTimer = setTimeout(async () => {
          await this.refreshJWTToken();
        }, timeUntilRefresh);
      }

    } catch (error) {
    }
  },

  /**
   * Requests a fresh JWT from the refresh endpoint, stores it and schedules
   * the following refresh.
   *
   * @returns {Promise<void>}
   */
  async refreshJWTToken() {
    try {
      const refreshUrl = this.config.jwt.refreshEndpoint || 'api/auth/refresh';
      const apiService = window.ApiService || window.Now?.getManager?.('api');
      const headers = {
        'X-Requested-With': 'XMLHttpRequest'
      };

      let response;
      if (apiService?.post) {
        response = await apiService.post(refreshUrl, null, {headers});
      } else if (window.simpleFetch?.post) {
        response = await simpleFetch.post(refreshUrl, null, {headers});
      } else {
        throw new Error('ApiService is not available');
      }

      if (response.success) {
        const data = response.data || {};
        if (data.token) {
          this.state.jwtToken = data.token;
          if (this.config.jwt.storage === 'localStorage') {
            try {
              localStorage.setItem(this.config.jwt.storageKey, data.token);
            } catch (e) {
              // storage unavailable: the token stays in memory
            }
          }
          this.startJWTRefresh(); // Setup next refresh
          this.emit('jwt:refreshed', {token: data.token});
        }
      }

    } catch (error) {
      this.handleError('JWT refresh failed', error);
    }
  },

  // ============ Input Sanitization (no validation) ============
  /**
   * Strips script tags, inline event handlers and javascript: URLs from a
   * string. Everything else stays as written, whatever its length: how long a
   * value may be is for the server to decide, and to refuse with an error.
   *
   * base64 image data URLs are passed through untouched.
   *
   * @param {*} value - Value to clean; non-strings are returned unchanged
   * @returns {*} Cleaned value
   */
  sanitizeInput(value) {
    if (!this.config.sanitization.enabled) return value;
    if (typeof value !== 'string') return value;

    // Only a string that contains HTML is cleaned: plain text, a URL or a
    // data:image value goes as it is (the server validates what it stores)
    if (!/<[a-zA-Z!\/?]/.test(value)) return value;

    let sanitized = value;

    // Remove script tags
    if (this.config.sanitization.removeScripts) {
      sanitized = sanitized.replace(/<script[^>]*>.*?<\/script>/gi, '');
    }

    // A value that is itself a javascript: URL, e.g. from a website field
    sanitized = sanitized.slice(this.javascriptUrlPrefix(sanitized));

    return this.cleanTagAttributes(sanitized);
  },

  /**
   * Measures the javascript: scheme a value starts with, repeats included.
   *
   * @param {string} value - Whole value or attribute value
   * @returns {number} Length of the prefix; 0 when there is none
   */
  javascriptUrlPrefix(value) {
    const match = /^(?:[\s\x00-\x1f]*javascript:)+/i.exec(value);
    return match ? match[0].length : 0;
  },

  /**
   * Removes inline event handlers and javascript: URLs from the attributes of
   * the tags in a string, and leaves the rest exactly as written.
   *
   * Attributes are read the way a browser reads them, so only a real attribute
   * counts: text outside a tag and values such as href="report?month=12" are not
   * touched. A tag that never closes is no tag to a browser, so it stays too.
   * The scan only moves forward, which keeps very long text fast.
   *
   * @param {string} text - Text that may contain tags
   * @returns {string} Cleaned text
   */
  cleanTagAttributes(text) {
    const {removeEvents} = this.config.sanitization;
    const isSpace = (char) => char === ' ' || char === '\n' || char === '\t' || char === '\r' || char === '\f';
    const tagStart = /<[a-z]/gi;
    const end = text.length;
    let cleaned = '';
    let copied = 0;

    while (tagStart.exec(text)) {
      const edits = [];
      let i = tagStart.lastIndex;

      // Tag name
      while (i < end && !isSpace(text[i]) && text[i] !== '/' && text[i] !== '>') i++;

      while (i < end) {
        const attributeStart = i;
        while (i < end && (isSpace(text[i]) || text[i] === '/')) i++;
        if (i >= end || text[i] === '>') break;

        // Attribute name; a leading = belongs to it, as it does in a browser
        const nameStart = i++;
        while (i < end && !isSpace(text[i]) && text[i] !== '/' && text[i] !== '>' && text[i] !== '=') i++;
        const name = text.slice(nameStart, i);

        // Attribute value, if any
        let valueStart = -1;
        let valueEnd = -1;
        let next = i;
        while (next < end && isSpace(text[next])) next++;
        if (text[next] === '=') {
          next++;
          while (next < end && isSpace(text[next])) next++;
          const quote = text[next];
          if (quote === '"' || quote === "'") {
            const close = text.indexOf(quote, next + 1);
            valueStart = next + 1;
            valueEnd = close < 0 ? end : close;
            i = close < 0 ? end : close + 1;
          } else {
            valueStart = next;
            while (next < end && !isSpace(text[next]) && text[next] !== '>') next++;
            valueEnd = next;
            i = next;
          }
        }

        if (removeEvents && /^on\w/i.test(name)) {
          edits.push([attributeStart, i]);
        } else if (valueStart >= 0) {
          const prefix = this.javascriptUrlPrefix(text.slice(valueStart, valueEnd));
          if (prefix) edits.push([valueStart, valueStart + prefix]);
        }
      }

      // Never closed, so the rest of the text is not inside any tag
      if (i >= end) break;

      edits.forEach(([from, to]) => {
        cleaned += text.slice(copied, from);
        copied = to;
      });
      tagStart.lastIndex = i + 1;
    }

    return cleaned + text.slice(copied);
  },

  // ============ Content Security Policy ============
  /**
   * Applies the Content Security Policy: adds the meta tag when the document
   * has none, and starts listening for violation reports.
   *
   * @returns {void}
   */
  initCSP() {
    // Add CSP meta tag if not exists
    if (!document.querySelector('meta[http-equiv="Content-Security-Policy"]')) {
      this.addCSPMeta();
    }

    // Setup CSP violation reporting — once, however often init() runs
    if (!this.state.cspListener) {
      this.state.cspListener = this.handleCSPViolation.bind(this);
      document.addEventListener('securitypolicyviolation', this.state.cspListener);
    }
  },

  /**
   * Builds the CSP header value from config.csp.directives and adds it to the
   * document as a meta tag.
   *
   * @returns {void}
   */
  addCSPMeta() {
    const meta = document.createElement('meta');
    meta.setAttribute('http-equiv', 'Content-Security-Policy');

    const directives = [];
    for (const [key, values] of Object.entries(this.config.csp.directives)) {
      directives.push(`${key} ${values.join(' ')}`);
    }

    meta.setAttribute('content', directives.join('; '));
    document.head.appendChild(meta);
  },

  /**
   * Records a CSP violation, emits csp:violation, and reports it to the server
   * when a report URI is configured.
   *
   * A violation that repeats (same directive, blocked URI, source file and
   * line) only increases the count of the one recorded; it is emitted and
   * reported the first time only. At most csp.maxViolations distinct
   * violations are kept and csp.maxReports reported per page, so a page that
   * violates its policy in a loop cannot flood the server.
   *
   * @param {SecurityPolicyViolationEvent} event - Violation event
   * @returns {void}
   */
  handleCSPViolation(event) {
    const violation = {
      directive: event.violatedDirective,
      uri: event.blockedURI,
      source: event.sourceFile,
      line: event.lineNumber,
      timestamp: Date.now(),
      count: 1
    };
    const key = [violation.directive, violation.uri, violation.source, violation.line].join('|');

    const known = this.state.violations.get(key);
    if (known) {
      known.count++;
      return;
    }

    const maxViolations = this.config.csp.maxViolations ?? 50;
    if (this.state.violations.size >= maxViolations) return;

    this.state.violations.set(key, violation);
    this.emit('csp:violation', violation);

    // Report to server if configured
    const maxReports = this.config.csp.maxReports ?? 10;
    if (this.config.csp.reportUri && this.state.cspReports < maxReports) {
      this.state.cspReports++;
      this.reportCSPViolation(violation);
    }
  },

  /**
   * Posts a CSP violation to the configured report endpoint.
   *
   * Failures are swallowed: reporting must never break the page.
   *
   * @param {Object} violation - Violation record
   * @returns {Promise<void>}
   */
  async reportCSPViolation(violation) {
    try {
      const apiService = window.ApiService || window.Now?.getManager?.('api');
      const headers = {
        'Content-Type': 'application/json'
      };

      if (apiService?.post) {
        await apiService.post(this.config.csp.reportUri, violation, {headers});
      } else if (window.simpleFetch?.post) {
        await simpleFetch.post(this.config.csp.reportUri, violation, {headers});
      } else {
        throw new Error('ApiService is not available');
      }
    } catch (error) {
    }
  },

  // ============ HTTP Interceptors ============
  /**
   * Installs the request and response interceptors on the HTTP client.
   *
   * Requests get the CSRF header and sanitized bodies; responses adopt a
   * rotated token, and status 419 triggers the CSRF recovery flow.
   *
   * @returns {void}
   */
  setupHttpInterceptors() {
    if (!window.http) return;

    // Request interceptor
    window.http.addRequestInterceptor(async (config) => {
      // Add CSRF token (state-changing methods, trusted origins only)
      if (this.config.csrf.enabled && this.state.csrfToken && this.shouldAddCSRF(config)) {
        config.headers = config.headers || {};
        config.headers[this.config.csrf.headerName] = this.state.csrfToken;
      }

      // The one place a request body may be cleaned on the client — off by
      // default; X-Skip-Sanitize (data-sanitize-input="false") opts out
      config.body = this.prepareRequestBody(config.body, config.headers);

      return config;
    });

    // Response interceptor
    window.http.addResponseInterceptor(
      (response) => {
        // Only our own (or a trusted) origin may hand us a new token
        const newToken = this.isTrustedOrigin(response?.url)
          ? response.headers?.[this.config.csrf.headerName.toLowerCase()]
          : null;
        if (newToken && newToken !== this.state.csrfToken) {
          this.state.csrfToken = newToken;
          this.updateCSRFMeta(newToken);
          this.updateCSRFInForms(newToken);
        }

        return response;
      },
      (error) => {
        if (error.status === 419) { // CSRF token mismatch
          this.handleCSRFError();
        }

        throw error;
      }
    );
  },

  /**
   * Decides whether a request needs the CSRF header, based on its method and
   * whether its URL is excluded.
   *
   * @param {Object} config - Request configuration
   * @returns {boolean} True when the header must be added
   */
  shouldAddCSRF(config) {
    if (!config.method) return false;

    const method = config.method.toUpperCase();
    if (!this.config.csrf.requireForMethods.includes(method)) {
      return false;
    }

    if (!this.isTrustedOrigin(config.url)) {
      return false;
    }

    return !this.isPathExcluded(config.url);
  },

  /**
   * Whether a request to `url` may carry this app's credentials (the CSRF
   * token, an Authorization header) and whether a token in its response may be
   * adopted: this page's origin, or one listed in config.trustedOrigins.
   * A missing URL means the page itself.
   *
   * @param {string} [url] - Request URL, absolute or relative to the page
   * @returns {boolean}
   */
  isTrustedOrigin(url) {
    if (url === undefined || url === null || url === '') return true;
    try {
      const origin = new URL(String(url), window.location.href).origin;
      return origin === window.location.origin || (this.config.trustedOrigins || []).includes(origin);
    } catch (e) {
      return false;
    }
  },

  /**
   * The body a request is sent with. With config.sanitization.enabled off (the
   * default), or for a request carrying `X-Skip-Sanitize: true`
   * (data-sanitize-input="false"), it is the body as given. Turned on, it is a
   * cleaned copy (sanitizeRequestData). The caller's object is never changed.
   *
   * @param {*} body - Request body
   * @param {Object} [headers] - Request headers
   * @returns {*} The body to send
   */
  prepareRequestBody(body, headers) {
    if (!body || !this.config.sanitization.enabled) return body;
    if (headers && (headers['X-Skip-Sanitize'] === 'true' || headers['x-skip-sanitize'] === 'true')) return body;
    return this.sanitizeRequestData(body);
  },

  /**
   * Cleans a request body, handling JSON strings, FormData and plain objects.
   *
   * @param {*} data - Request body
   * @returns {*} Cleaned body of the same shape
   */
  sanitizeRequestData(data) {
    if (typeof data === 'string') {
      try {
        const parsed = JSON.parse(data);
        return JSON.stringify(this.sanitizeObject(parsed));
      } catch {
        return this.sanitizeInput(data);
      }
    }

    if (data instanceof FormData) {
      const sanitized = new FormData();
      for (const [key, value] of data) {
        // A File/Blob entry goes as it is, with its file name
        if (typeof value !== 'string') {
          sanitized.append(key, value, value.name);
        } else {
          sanitized.append(key, this.sanitizeInput(value));
        }
      }
      return sanitized;
    }

    return this.sanitizeObject(data);
  },

  /**
   * Cleans every string value of an object or array, recursing into plain
   * objects and arrays. An array stays an array; a Date, File, Blob or any
   * other non-plain object is kept as it is. Returns a copy.
   *
   * @param {*} obj - Object or value to clean
   * @returns {*} Cleaned copy
   */
  sanitizeObject(obj) {
    if (Array.isArray(obj)) {
      return obj.map(value => this.sanitizeObject(value));
    }
    if (typeof obj !== 'object' || obj === null) {
      return this.sanitizeInput(obj);
    }
    const proto = Object.getPrototypeOf(obj);
    if (proto !== Object.prototype && proto !== null) {
      return obj;
    }

    const sanitized = {};
    for (const [key, value] of Object.entries(obj)) {
      if (this.isUnsafeKey(key)) continue;
      sanitized[key] = this.sanitizeObject(value);
    }

    return sanitized;
  },

  // ============ Form Interceptors ============
  /**
   * Enhances the forms already in the document and watches for new ones, so
   * dynamically added forms are protected too.
   *
   * @returns {void}
   */
  setupFormInterceptors() {
    // Intercept form creation
    const observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
          if (node.nodeType === 1) {
            if (node.tagName === 'FORM') {
              this.enhanceForm(node);
            } else {
              const forms = node.querySelectorAll('form');
              forms.forEach(form => this.enhanceForm(form));
            }
          }
        });
      });
    });

    observer.observe(document.body, {
      childList: true,
      subtree: true
    });

    // Enhance existing forms
    document.querySelectorAll('form').forEach(form => {
      this.enhanceForm(form);
    });
  },

  /**
   * Applies the security configuration of one form, currently CSRF injection,
   * and marks it so the work is not repeated.
   *
   * @param {HTMLFormElement} form - Form to enhance
   * @returns {void}
   */
  enhanceForm(form) {
    // Skip if already enhanced
    if (form.dataset.securityEnhanced) return;

    // Read security configuration from data attributes
    const config = this.extractFormSecurityConfig(form);

    // Apply CSRF protection
    if (config.csrf !== false) {
      this.injectCSRFIntoForm(form);
    }

    form.dataset.securityEnhanced = 'true';
  },

  /**
   * Reads the security settings a form declares through data attributes.
   *
   * @param {HTMLFormElement} form - Form to inspect
   * @returns {Object} Settings, currently {csrf}
   */
  extractFormSecurityConfig(form) {
    return {
      csrf: this.getDataBool(form, 'csrf', this.config.csrf.enabled)
    };
  },

  // ============ Error Handlers ============
  /**
   * Recovers from a rejected CSRF token (HTTP 419).
   *
   * Emits csrf:retry first so a caller can re-send the failed request — the
   * server may only consume a pending token on success — then refreshes the
   * token as a fallback.
   *
   * @returns {Promise<void>}
   */
  async handleCSRFError() {
    // Skip CSRF error handling if CSRF is disabled
    if (!this.config.csrf.enabled) {
      return;
    }

    // If we have a token, try a single automatic retry before refreshing the token.
    // This helps when the server marks tokens as pending and only consumes them on success.
    try {
      if (this.state.csrfToken) {
        // Emit an event so callers can retry their last request if they want.
        // Consumers can listen to 'csrf:retry' to re-send the failed request.
        this.emit('csrf:retry', {token: this.state.csrfToken});

        // Notify user that the token will be retried automatically
        this.showNotification(Now.translate('Security token present. Attempting a retry...'), 'info');

        // Give consumers a short window to retry; after that, refresh token as fallback
        await new Promise(resolve => setTimeout(resolve, 800));

        // If token is unchanged and no consumer retried, refresh as fallback
        await this.refreshCSRFToken();
        this.showNotification(Now.translate('Security token updated. Please try again.'), 'warning');
        return;
      }

      // No token available -> refresh
      await this.refreshCSRFToken();

      this.showNotification(Now.translate('Security token updated. Please try again.'), 'warning');

    } catch (error) {
      this.showNotification(Now.translate('Security error. Please refresh the page.'), 'error');
    }
  },

  // ============ Utility Methods ============
  /**
   * Tests a path against config.csrf.excludePaths, where a trailing '*' makes
   * the entry a prefix match.
   *
   * Both the path and each entry are resolved the way the browser resolves the
   * request URL (against this page, dot segments removed) and compared by
   * pathname, so 'api/public/../index/save' is not under 'api/public/*', and
   * '/auth/verify?id=1' or the same path written as a full URL matches
   * '/auth/verify'. A URL on another origin is compared in full and never
   * matches a path entry.
   *
   * @param {string} path - URL or form action to test
   * @returns {boolean} True when the path is excluded from CSRF
   */
  isPathExcluded(path) {
    if (!path) return false;

    const resolve = value => {
      try {
        const url = new URL(String(value), window.location.href);
        return url.origin === window.location.origin ? url.pathname : url.origin + url.pathname;
      } catch (e) {
        return null;
      }
    };

    const target = resolve(path);
    if (target === null) return false;

    return (this.config.csrf.excludePaths || []).some(pattern => {
      const prefix = String(pattern).endsWith('*');
      const entry = resolve(prefix ? String(pattern).slice(0, -1) : pattern);
      if (entry === null) return false;
      return prefix ? target.startsWith(entry) : target === entry;
    });
  },

  /**
   * Reads a boolean data attribute, accepting 'true' and '1'.
   *
   * @param {HTMLElement} element - Element carrying the attribute
   * @param {string} attribute - Dataset key
   * @param {boolean} [defaultValue=false] - Value when the attribute is absent
   * @returns {boolean} Parsed value
   */
  getDataBool(element, attribute, defaultValue = false) {
    const value = element.dataset[attribute];
    if (value === undefined) return defaultValue;
    return value === 'true' || value === '1';
  },

  /**
   * Reads a cookie by name, URL-decoded.
   *
   * When the same name is set on more than one path, the browser lists the
   * most specific path first, and that value is returned.
   *
   * @param {string} name - Cookie name
   * @returns {string|null} Cookie value, or null when not set
   */
  getCookie(name) {
    if (!name || !document.cookie) return null;

    for (const part of document.cookie.split(';')) {
      const index = part.indexOf('=');
      if (index === -1) continue;
      if (part.slice(0, index).trim() !== name) continue;

      const value = part.slice(index + 1).trim();
      try {
        return decodeURIComponent(value);
      } catch (e) {
        return value;
      }
    }
    return null;
  },

  /**
   * Shows a notification when NotificationManager is available.
   *
   * @param {string} message - Message to display
   * @param {string} [type='info'] - Notification type
   * @returns {void}
   */
  showNotification(message, type = 'info') {
    if (window.NotificationManager) {
      window.NotificationManager[type](message);
    }
  },

  /**
   * Emits an event through EventManager and as a DOM CustomEvent, so listeners
   * can use either channel.
   *
   * @param {string} event - Event name
   * @param {Object} [data={}] - Event payload
   * @returns {void}
   */
  emit(event, data = {}) {
    if (window.EventManager) {
      window.EventManager.emit(event, data);
    }

    // Also emit as DOM event
    const customEvent = new CustomEvent(event, {
      detail: data,
      bubbles: true,
      cancelable: true
    });
    document.dispatchEvent(customEvent);
  },

  /**
   * Reports an error to ErrorManager and emits security:error.
   *
   * @param {string} message - Description of what failed
   * @param {Error} error - Error that was caught
   * @returns {void}
   */
  handleError(message, error) {
    if (window.ErrorManager) {
      window.ErrorManager.handle(error, {
        context: 'SecurityManager',
        message
      });
    }

    this.emit('security:error', {message, error});
  },

  /**
   * Merges source into target recursively, skipping the keys isUnsafeKey()
   * rejects. Arrays are joined without duplicates: the source adds its
   * entries to the target's, so running init() again with the same options
   * leaves the config as it was.
   *
   * @param {Object} target - Object to merge into; mutated
   * @param {Object} source - Object to merge from
   * @returns {Object} The merged target
   */
  /**
   * Freezes an object and everything in it (config.html after the first init).
   * @private
   */
  freezeDeep(value) {
    if (value && typeof value === 'object' && !Object.isFrozen(value)) {
      Object.values(value).forEach(item => this.freezeDeep(item));
      Object.freeze(value);
    }
    return value;
  },

  mergeDeep(target, source) {
    const isObject = (obj) => obj && typeof obj === 'object' && !Array.isArray(obj);

    if (!isObject(target) || !isObject(source)) {
      return source;
    }

    Object.keys(source).forEach(key => {
      // Prototype-pollution guard: never copy __proto__/constructor/prototype
      if (this.isUnsafeKey(key)) return;

      const targetValue = target[key];
      const sourceValue = source[key];

      if (Array.isArray(targetValue) && Array.isArray(sourceValue)) {
        target[key] = Array.from(new Set([...targetValue, ...sourceValue]));
      } else if (isObject(targetValue) && isObject(sourceValue)) {
        target[key] = this.mergeDeep(Object.assign({}, targetValue), sourceValue);
      } else {
        target[key] = sourceValue;
      }
    });

    return target;
  },

  // ============ Centralized sanitization & safe-object API ============
  // This is the single security layer the rest of the framework routes through.
  // Render paths should call setSafeHtml/sanitizeHtml/escapeHtml/sanitizeUrl
  // instead of touching innerHTML directly; object merges/path-sets should use
  // safeMerge/safeSetByPath to stay free of prototype pollution.

  /**
   * Reports whether a key must never be written through merge, clone or
   * path-set operations, which is what keeps them free of prototype pollution.
   *
   * @param {string} key - Key about to be written
   * @returns {boolean} True when the key is unsafe
   */
  isUnsafeKey(key) {
    return key === '__proto__' || key === 'constructor' || key === 'prototype';
  },

  /**
   * Entity-encode a value for safe insertion as HTML text. Self-contained so it
   * works regardless of Utils load order.
   */
  escapeHtml(value) {
    const s = value == null ? '' : String(value);
    return s
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  },

  /**
   * Returns HTML that is safe to assign to innerHTML.
   *
   * The markup is parsed inside a `<template>`, so nothing in it loads or runs
   * while it is cleaned. See config.html for what each level keeps.
   *
   * @param {string} html - Markup to clean
   * @param {Object} [options={}]
   * @param {boolean} [options.trusted=false] - true for a template from our own
   *   origin; anything else (data, responses, user content) is untrusted
   * @returns {string} Clean markup
   */
  sanitizeHtml(html, options = {}) {
    if (html == null) return '';
    const template = document.createElement('template');
    template.innerHTML = String(html);
    this.sanitizeTree(template.content, options);
    return template.innerHTML;
  },

  /**
   * Cleans a subtree in place, at the same levels as sanitizeHtml(). The root
   * itself stays; its attributes are cleaned like any other element's.
   *
   * Markup that is already in a live document has been parsed there, so its
   * images may have loaded; prefer sanitizeHtml() for markup not inserted yet.
   *
   * @param {Element|DocumentFragment} root - Subtree to clean
   * @param {Object} [options={}]
   * @param {boolean} [options.trusted=false] - Trusted template level
   * @returns {Element|DocumentFragment} The same root
   */
  sanitizeTree(root, options = {}) {
    if (!root) return root;
    const trusted = options.trusted === true;
    const rules = this.htmlRules();
    const tags = trusted ? rules.templateTags : rules.tags;
    const dropWhole = rules.dropWhole;

    const walk = (parent) => {
      let node = parent.firstChild;
      while (node) {
        const next = node.nextSibling;
        // Untrusted HTML keeps element and text nodes only; a template keeps its
        // comments too (never a processing instruction or CDATA)
        if (node.nodeType !== 1 && node.nodeType !== 3 && !(trusted && node.nodeType === 8)) {
          node.remove();
        } else if (node.nodeType === 1) {
          const tag = node.localName.toLowerCase();
          if (!tags.has(tag)) {
            if (trusted || dropWhole.has(tag)) {
              node.remove();
            } else {
              const children = Array.from(node.childNodes);
              node.replaceWith(...children);
              if (children.length) {
                node = children[0];
                continue;
              }
            }
          } else if (this.cleanElement(node, tag, trusted)) {
            if (tag === 'template' && node.content) walk(node.content);
            walk(node);
          }
        }
        node = next;
      }
    };

    if (root.nodeType === 1) {
      this.cleanElement(root, root.localName.toLowerCase(), trusted);
    }
    walk(root);
    return root;
  },

  /**
   * The config.html lists as lookup tables, rebuilt when a list is replaced
   * (init merges options into config).
   * @private
   */
  htmlRules() {
    const cfg = this.config.html;
    const signature = [cfg.tags.length, cfg.templateTags.length,
      ...Object.entries(cfg.attributes).map(([tag, list]) => tag + list.length)].join(',');
    const cached = this._htmlRules;
    if (cached && cached.cfg === cfg && cached.tagsList === cfg.tags && cached.templateList === cfg.templateTags &&
      cached.attributesMap === cfg.attributes && cached.signature === signature) {
      return cached;
    }
    const attributes = new Map();
    const global = cfg.attributes['*'] || [];
    const exactGlobal = global.filter(a => !a.endsWith('*'));
    const prefixes = global.filter(a => a.endsWith('*')).map(a => a.slice(0, -1));
    Object.keys(cfg.attributes).forEach(tag => {
      if (tag !== '*') attributes.set(tag, new Set([...exactGlobal, ...cfg.attributes[tag]]));
    });
    this._htmlRules = {
      cfg,
      signature,
      tagsList: cfg.tags,
      templateList: cfg.templateTags,
      attributesMap: cfg.attributes,
      tags: new Set(cfg.tags),
      templateTags: new Set(cfg.templateTags),
      globalAttributes: new Set(exactGlobal),
      attributes,
      prefixes,
      // Untrusted tags that are dropped with their content; other unknown tags are unwrapped
      dropWhole: new Set([
        'script', 'style', 'template', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet',
        'noscript', 'noembed', 'noframes', 'xmp', 'plaintext', 'title', 'head', 'meta', 'link', 'base',
        'svg', 'math', 'select', 'textarea', 'option', 'datalist', 'canvas'
      ]),
      urlAttributes: new Set(['href', 'src', 'action', 'formaction', 'poster', 'cite', 'background', 'xlink:href', 'data', 'codebase', 'ping', 'manifest']),
      imageTags: new Set(['img', 'source', 'video', 'input', 'picture'])
    };
    return this._htmlRules;
  },

  // Directives that turn markup into behaviour; data never sets them (isForbiddenAttribute)
  directiveAttributes: ['data-on', 'data-action', 'data-script', 'data-on-load', 'data-html', 'data-component',
    'data-attr', 'data-if', 'data-for', 'data-model', 'data-bind'],

  /**
   * Whether a value from data (a server response, a binding) may never be
   * written to this attribute, whatever the value: on* handlers, srcdoc,
   * http-equiv, and the directives that turn markup into behaviour
   * (directiveAttributes and data-api-*). ResponseHandler (update attr/attrs,
   * attribute) and TemplateManager.safeAttributeValue() (data-attr, {{ }} in an
   * attribute) both use it.
   *
   * @param {string} name - Attribute name
   * @returns {boolean}
   */
  isForbiddenAttribute(name) {
    const attr = String(name || '').trim().toLowerCase();
    if (attr.startsWith('on') || attr === 'srcdoc' || attr === 'http-equiv') return true;
    return this.directiveAttributes.includes(attr) || attr.startsWith('data-api-');
  },

  /**
   * Whether an attribute holds a URL (href, src, action, formaction, poster,
   * cite, background, xlink:href, data, codebase, ping, manifest) — the list
   * the sanitizer checks, used by every other place that writes a URL from data.
   * srcset is a list of URLs: see sanitizeSrcset().
   *
   * @param {string} name - Attribute name
   * @returns {boolean}
   */
  isUrlAttribute(name) {
    return this.htmlRules().urlAttributes.has(String(name || '').trim().toLowerCase());
  },

  /**
   * A CSS url() for a URL: quoted, with every character CSS gives a meaning
   * escaped, so a `)`, a quote or a backslash in the URL stays part of it.
   * The URL itself is not checked — pass one sanitizeUrl() accepted.
   *
   * @param {string} url
   * @returns {string} url("...")
   */
  cssUrl(url) {
    const value = String(url ?? '');
    const escaped = typeof CSS !== 'undefined' && typeof CSS.escape === 'function'
      ? CSS.escape(value)
      : value.replace(/["'()\\\s]/g, c => '\\' + c.charCodeAt(0).toString(16) + ' ');
    return `url("${escaped}")`;
  },

  /**
   * Whether a tag shows an image, the only tags whose URL may be data:image
   * (img, source, video, input, picture).
   *
   * @param {string} tag - Tag name
   * @returns {boolean}
   */
  isImageTag(tag) {
    return this.htmlRules().imageTags.has(String(tag || '').toLowerCase());
  },

  /**
   * Whether untrusted content may carry a data-* attribute: only the names in
   * config.html.dataAttributes (a trailing * matches a prefix). Every directive
   * (data-on, data-action, data-component, data-api-*, ...) is a data-*, so by
   * default none is allowed.
   *
   * @param {string} name - Attribute name, e.g. 'data-badge'
   * @returns {boolean}
   */
  isAllowedDataAttribute(name) {
    const attr = String(name || '').toLowerCase();
    if (!attr.startsWith('data-')) return false;
    return (this.config.html.dataAttributes || []).some(p => p.endsWith('*') ? attr.startsWith(p.slice(0, -1)) : p === attr);
  },

  /**
   * Cleans one element's attributes for sanitizeTree(). Returns false when the
   * element itself was removed (an untrusted iframe from a host not allowed).
   * @private
   */
  cleanElement(el, tag, trusted) {
    const cfg = this.config.html;
    const rules = this.htmlRules();
    const allowed = trusted ? null : (rules.attributes.get(tag) || rules.globalAttributes);
    const isAllowed = (name) => name.startsWith('data-')
      ? this.isAllowedDataAttribute(name)
      : allowed.has(name) || rules.prefixes.some(prefix => name.startsWith(prefix));
    const attrs = el.attributes;

    for (let i = attrs.length - 1; i >= 0; i--) {
      const attr = attrs[i];
      const name = attr.name.toLowerCase();
      const value = attr.value;
      let keep = value;

      if (name.startsWith('on') || name === 'srcdoc' || name === 'http-equiv') {
        keep = null;
      } else if (!trusted && !isAllowed(name)) {
        keep = null;
      } else if (tag === 'use' && (name === 'href' || name === 'xlink:href')) {
        // An SVG <use> shows a symbol of this document, never another file
        keep = value.trim().startsWith('#') ? value : null;
      } else if (rules.urlAttributes.has(name)) {
        // data:image loads on image tags only (an iframe, embed or object would run an SVG's script)
        keep = this.sanitizeUrl(value, {image: rules.imageTags.has(tag), svg: trusted}) || null;
      } else if (name === 'srcset' || name === 'imagesrcset') {
        keep = this.sanitizeSrcset(value, trusted);
      } else if (name === 'style') {
        keep = trusted ? (/expression\s*\(|javascript:|vbscript:|-moz-binding|behavior\s*:/i.test(value) ? null : value)
          : this.sanitizeStyle(value);
      } else if (!trusted && name === 'id') {
        keep = value && !(value in window) && !(value in document) ? value : null;
      } else if (!trusted && name === 'target') {
        keep = ['_blank', '_self'].includes(value.toLowerCase()) ? value : null;
      } else if (!trusted && name === 'allow') {
        const features = ['accelerometer', 'autoplay', 'clipboard-write', 'encrypted-media', 'fullscreen', 'gyroscope', 'picture-in-picture', 'web-share'];
        keep = value.split(';').map(f => f.trim()).filter(f => features.includes(f.split(/\s/)[0])).join('; ') || null;
      }

      if (keep === null) {
        el.removeAttribute(attr.name);
      } else if (keep !== value) {
        el.setAttribute(attr.name, keep);
      }
    }

    if (trusted) return true;

    if (tag === 'iframe') {
      if (!this.isAllowedIframe(el.getAttribute('src'))) {
        el.remove();
        return false;
      }
      el.setAttribute('sandbox', cfg.iframeSandbox);
    }
    if (tag === 'a' && (el.getAttribute('target') || '').toLowerCase() === '_blank') {
      const rel = new Set((el.getAttribute('rel') || '').split(/\s+/).filter(Boolean));
      rel.add('noopener');
      rel.add('noreferrer');
      el.setAttribute('rel', [...rel].join(' '));
    }
    return true;
  },

  /**
   * Whether an untrusted iframe may load `src`: http(s) and a host + path that
   * starts with one of config.html.iframeHosts.
   *
   * @param {string} src - The iframe's src
   * @returns {boolean}
   */
  isAllowedIframe(src) {
    if (!src || !this.sanitizeUrl(src)) return false;
    try {
      const url = new URL(src, window.location.href);
      if (url.protocol !== 'https:' && url.protocol !== 'http:') return false;
      const where = url.host + url.pathname;
      return this.config.html.iframeHosts.some(prefix => where.startsWith(prefix));
    } catch (e) {
      return false;
    }
  },

  /**
   * Keeps an inline style only to the properties config.html.styleProperties
   * lists, and only values that cannot load or run anything.
   *
   * @param {string} style - A style attribute value
   * @returns {string|null} The kept declarations, or null when none survive
   */
  sanitizeStyle(style) {
    if (typeof style !== 'string') return null;
    const properties = this.config.html.styleProperties;
    const kept = [];
    style.split(';').forEach(declaration => {
      const colon = declaration.indexOf(':');
      if (colon === -1) return;
      const property = declaration.slice(0, colon).trim().toLowerCase();
      const value = declaration.slice(colon + 1).trim();
      if (!value || !properties.includes(property)) return;
      if (/url\s*\(|expression\s*\(|image-set\s*\(|javascript:|vbscript:|@import|\\|[<>]|\/\*/i.test(value)) return;
      kept.push(`${property}: ${value}`);
    });
    return kept.length ? kept.join('; ') : null;
  },

  /**
   * Checks every URL of a srcset (or imagesrcset); one bad candidate drops the
   * whole attribute.
   *
   * @param {string} value - The srcset value
   * @param {boolean} [trusted=false] - Allow data:image/svg+xml too
   * @returns {string|null} The value, or null when a candidate was rejected
   */
  sanitizeSrcset(value, trusted) {
    const candidates = String(value).split(',').map(c => c.trim()).filter(Boolean);
    const ok = candidates.every(candidate => this.sanitizeUrl(candidate.split(/\s+/)[0], {image: true, svg: trusted}));
    return ok && candidates.length ? value : null;
  },

  /**
   * Assign sanitized HTML to an element. Preferred over `el.innerHTML = ...`
   * anywhere the markup is dynamic / data-derived.
   *
   * @param {Element} element - Target
   * @param {string} html - Untrusted markup
   * @returns {Element} The element
   */
  setSafeHtml(element, html) {
    if (element) {
      element.innerHTML = this.sanitizeHtml(html);
    }
    return element;
  },

  /**
   * Cleans a URL for href/src/action. A URL without a scheme (a path, ../,
   * ?query, #hash, //host) is kept as is; one with a scheme is kept only when
   * the scheme is in config.html.urlSchemes, or when it is data:image/... and
   * `options.image` says an image is expected.
   *
   * The scheme is read the way a browser reads it: leading and trailing control
   * characters and spaces are ignored and tab/CR/LF inside are dropped, so
   * "java\tscript:" counts as javascript:.
   *
   * @param {string} url - URL to check
   * @param {Object} [options={}]
   * @param {boolean} [options.image=false] - Allow raster data:image/... URLs
   * @param {boolean} [options.svg=false] - With image, also allow data:image/svg+xml
   * @returns {string} The URL (trimmed), or '' when it was rejected
   */
  sanitizeUrl(url, options = {}) {
    if (url == null) return '';
    const value = String(url).replace(/^[\u0000- ]+|[\u0000- ]+$/g, '');
    const probe = value.replace(/[\t\n\r]/g, '');
    const match = /^([a-z][a-z0-9+.\-]*):/i.exec(probe);
    if (!match) return value;

    const scheme = match[1].toLowerCase() + ':';
    if (this.config.html.urlSchemes.includes(scheme)) return value;
    if (scheme === 'data:' && options.image === true) {
      const image = options.svg === true
        ? /^data:image\/[a-z0-9.+\-]+[;,]/i
        : /^data:image\/(?:png|gif|jpe?g|webp|avif|bmp|x-icon|vnd\.microsoft\.icon)[;,]/i;
      if (image.test(probe)) return value;
    }
    return '';
  },

  /**
   * Prototype-pollution-safe recursive merge. Use instead of ad-hoc deep merges
   * when `source` may originate from untrusted JSON.
   */
  safeMerge(target, source) {
    const isObject = (obj) => obj && typeof obj === 'object' && !Array.isArray(obj);
    if (!isObject(target) || !isObject(source)) return source;

    Object.keys(source).forEach(key => {
      if (this.isUnsafeKey(key)) return;
      const targetValue = target[key];
      const sourceValue = source[key];
      if (Array.isArray(targetValue) && Array.isArray(sourceValue)) {
        target[key] = targetValue.concat(sourceValue);
      } else if (isObject(targetValue) && isObject(sourceValue)) {
        target[key] = this.safeMerge(Object.assign({}, targetValue), sourceValue);
      } else {
        target[key] = sourceValue;
      }
    });
    return target;
  },

  /**
   * Prototype-pollution-safe "set nested property by path". Refuses to traverse
   * or write __proto__/constructor/prototype. Returns the (possibly unchanged)
   * root object.
   */
  safeSetByPath(obj, path, value) {
    if (!obj || typeof obj !== 'object') return obj;
    const parts = Array.isArray(path) ? path : String(path).split('.');
    let cur = obj;
    for (let i = 0; i < parts.length - 1; i++) {
      const key = parts[i];
      if (this.isUnsafeKey(key)) return obj;
      if (typeof cur[key] !== 'object' || cur[key] === null) {
        cur[key] = {};
      }
      cur = cur[key];
    }
    const last = parts[parts.length - 1];
    if (this.isUnsafeKey(last)) return obj;
    cur[last] = value;
    return obj;
  },

  // ============ Public API ============
  /**
   * Returns the CSRF token a form should submit.
   *
   * @param {HTMLFormElement} form - Form asking for the token
   * @returns {string|null} Current CSRF token
   */
  getCSRFTokenForForm(form) {
    return this.state.csrfToken;
  },

  /**
   * Refreshes the CSRF and JWT tokens together.
   *
   * @returns {Promise<Array>} Results of both refreshes
   */
  refreshTokens() {
    return Promise.all([
      this.refreshCSRFToken(),
      this.refreshJWTToken()
    ]);
  },

  // Rate limiting is enforced by the backend (see class header). The former
  // isRateLimited()/getRateLimitStatus() helpers referenced a checkRateLimit()
  // that never existed and were removed as dead, throw-on-call code.

  /**
   * Adds the CSRF header to a request configuration when the request needs it.
   *
   * @param {Object} config - Request configuration; mutated
   * @returns {Object} The same configuration
   */
  addCSRFToRequest(config) {
    if (this.config.csrf.enabled && this.shouldAddCSRF(config) && this.state.csrfToken) {
      config.headers = config.headers || {};
      config.headers[this.config.csrf.headerName] = this.state.csrfToken;
    }
    return config;
  },

  // ============ Cleanup ============
  /**
   * Stops the refresh timers, clears the recorded violations and marks the
   * manager uninitialized.
   *
   * @returns {void}
   */
  destroy() {
    if (this.csrfRefreshTimer) {
      clearInterval(this.csrfRefreshTimer);
    }

    if (this.jwtRefreshTimer) {
      clearInterval(this.jwtRefreshTimer);
      clearTimeout(this.jwtRefreshTimer);
    }

    this.state.initialized = false;
    this.state.violations.clear();
    this.state.cspReports = 0;
    if (this.state.cspListener) {
      document.removeEventListener('securitypolicyviolation', this.state.cspListener);
      this.state.cspListener = null;
    }

    this.emit('security:destroyed');
  }
};

// Register with Now.js framework
if (window.Now?.registerManager) {
  Now.registerManager('security', SecurityManager);
}

// Expose globally
window.SecurityManager = SecurityManager;
