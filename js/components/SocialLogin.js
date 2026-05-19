/**
 * SocialLoginComponent - Now.js Component for Social Authentication
 * Handles Telegram, Facebook, Google, and LINE social login flows
 *
 * @component SocialLoginComponent
 * @requires HttpClient - For secure API requests with CSRF protection
 * @requires AuthManager - For user authentication state
 * @requires NotificationManager - For user feedback
 * @requires RouterManager - For SPA navigation
 */
const SocialLoginComponent = {
  /**
   * Default configuration
   */
  config: {
    // API endpoint
    endpoint: 'api/index/social',

    // SDK URLs
    sdkUrls: {
      telegram: 'https://telegram.org/js/telegram-widget.js?22',
      facebook: 'https://connect.facebook.net/th_TH/sdk.js',
      google: 'https://accounts.google.com/gsi/client',
      line: null // LINE uses OAuth redirect, no SDK
    },

    // Facebook SDK config
    facebook: {
      version: 'v24.0',
      scope: 'public_profile'
    },

    // LINE OAuth config
    line: {
      authUrl: 'https://access.line.me/oauth2/v2.1/authorize',
      callbackPath: '/line/callback.php',
      scope: 'profile openid email'
    },

    // Redirect after authentication
    redirectDelay: 500
  },

  /**
   * Component state
   */
  state: {
    instances: new Map(),
    sdkLoaded: {
      telegram: false,
      facebook: false,
      google: false
    },
    initialized: false
  },

  /**
   * Google OAuth client id as in Google Console (with or without .apps.googleusercontent.com).
   */
  normalizeGoogleClientId(raw) {
    const s = String(raw || '').trim();
    if (!s) {
      return '';
    }
    const suf = '.apps.googleusercontent.com';
    const lower = s.toLowerCase();
    if (lower.endsWith(suf)) {
      return s;
    }
    return s + suf;
  },

  /**
   * Initialize component
   * @param {Object} options - Configuration options
   * @returns {SocialLoginComponent}
   */
  async init(options = {}) {
    if (this.state.initialized) {
      return this;
    }

    // Merge config
    this.config = {...this.config, ...options};

    // Initialize HttpClient if not exists
    if (!window.http) {
      window.http = new HttpClient({
        throwOnError: false,
        security: {
          csrf: {enabled: true}
        }
      });
    }

    // Auto-discover provider elements
    this.discoverProviders();

    // Listen for SPA route changes
    EventManager.on('route:changed', () => {
      setTimeout(() => this.refresh(), 1000);
    });

    EventManager.on('form:data:set', () => {
      if (typeof document === 'undefined') {
        return;
      }
      if (document.querySelector('[data-social-provider]')) {
        setTimeout(() => this.refresh(), 0);
      }
    });

    this.state.initialized = true;
    return this;
  },

  /**
   * Discover and initialize provider elements
   */
  discoverProviders() {
    document.querySelectorAll('[data-social-provider]').forEach(element => {
      if (!element.dataset.componentId) {
        this.create(element);
      }
    });
  },

  /**
   * Create instance for a provider element
   * @param {HTMLElement} element - Provider container element
   * @param {Object} options - Instance options
   * @returns {Object|null} Instance object or null
   */
  create(element, options = {}) {
    const provider = element.dataset.socialProvider;
    const config = element.dataset.username;

    // No config = hide element
    if (!config || config.trim() === '') {
      element.style.display = 'none';
      return null;
    }

    // Check if instance already exists
    const existingId = element.dataset.componentId;
    if (existingId && this.state.instances.has(existingId)) {
      return this.state.instances.get(existingId);
    }

    // Create new instance
    const instance = {
      id: `social_${provider}_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`,
      element,
      provider: provider.toLowerCase(),
      config,
      options: {...this.config, ...options},
      isActive: false,
      handlers: {}
    };

    // Initialize provider-specific setup
    this.initProvider(instance);

    // Store instance
    this.state.instances.set(instance.id, instance);
    element.dataset.componentId = instance.id;

    // Add instance reference to element for easy access
    element.socialLoginInstance = instance;

    return instance;
  },

  /**
   * Initialize provider based on type
   * @param {Object} instance - Provider instance
   */
  initProvider(instance) {
    switch (instance.provider) {
      case 'telegram':
        this.initTelegramProvider(instance);
        break;
      case 'facebook':
        this.initFacebookProvider(instance);
        break;
      case 'google':
        this.initGoogleProvider(instance);
        break;
      case 'line':
        this.initLineProvider(instance);
        break;
      default:
        console.warn('[SocialLogin] Unknown provider:', instance.provider);
    }
  },

  /**
   * Refresh - re-discover providers after route change
   */
  refresh() {
    this.discoverProviders();
  },

  /**
   * Destroy instance
   * @param {Object} instance - Instance to destroy
   */
  destroy(instance) {
    if (!instance) return;

    // Cleanup event listeners
    if (instance.handlers.click) {
      instance.element.removeEventListener('click', instance.handlers.click);
    }

    // Remove from instances map
    this.state.instances.delete(instance.id);
    delete instance.element.dataset.componentId;
    delete instance.element.socialLoginInstance;
  },

  // ========== TELEGRAM PROVIDER ==========

  /**
   * Initialize Telegram widget
   */
  initTelegramProvider(instance) {
    const {element, config} = instance;

    // Check if widget already rendered
    if (element.querySelector('script[data-telegram-login]')) {
      element.style.display = '';
      return;
    }

    // Define global callback (one-time setup)
    if (!window.onTelegramAuth) {
      window.onTelegramAuth = async (user) => {
        await this.handleTelegramAuth(user);
      };
    }

    // Render widget
    this.renderTelegramWidget(element, config);
  },

  /**
   * Render Telegram widget script
   */
  renderTelegramWidget(container, botUsername) {
    container.innerHTML = '';

    const script = document.createElement('script');
    script.async = true;
    script.src = this.config.sdkUrls.telegram;
    script.setAttribute('data-telegram-login', botUsername);
    script.setAttribute('data-size', 'large');
    script.setAttribute('data-onauth', 'onTelegramAuth(user)');
    script.setAttribute('data-request-access', 'write');

    container.appendChild(script);
    container.style.display = '';
  },

  /**
   * Handle Telegram authentication callback
   */
  async handleTelegramAuth(user) {
    try {
      const response = await this.sendAuthRequest(
        {
          provider: 'telegram',
          id: user.id,
          first_name: user.first_name || '',
          last_name: user.last_name || '',
          username: user.username || '',
          photo_url: user.photo_url || '',
          auth_date: user.auth_date,
          hash: user.hash
        }
      );

      if (response.success) {
        await this.handleAuthSuccess(response.data.data);
      } else {
        throw new Error(response.data.message || 'Authentication failed');
      }
    } catch (error) {
      this.handleAuthError(error);
    }
  },

  // ========== FACEBOOK PROVIDER ==========

  /**
   * Initialize Facebook provider
   */
  initFacebookProvider(instance) {
    const {element, config} = instance;

    // Load Facebook SDK if not loaded
    if (!this.state.sdkLoaded.facebook) {
      this.loadFacebookSDK(config);
    }

    // Show button
    if (element.tagName === 'BUTTON') {
      element.style.display = '';

      // Bind click handler
      const clickHandler = () => this.handleFacebookLogin(instance);
      element.addEventListener('click', clickHandler);
      instance.handlers.click = clickHandler;
    }
  },

  /**
   * Load Facebook SDK
   */
  loadFacebookSDK(appId) {
    if (document.getElementById('facebook-jssdk')) {
      return;
    }

    window.fbAsyncInit = () => {
      FB.init({
        appId: appId,
        cookie: true,
        status: true,
        xfbml: true,
        version: this.config.facebook.version
      });
      this.state.sdkLoaded.facebook = true;
    };

    const script = document.createElement('script');
    script.id = 'facebook-jssdk';
    script.async = true;
    script.src = this.config.sdkUrls.facebook;
    document.head.appendChild(script);
  },

  /**
   * Handle Facebook login
   */
  async handleFacebookLogin(instance) {
    if (!window.FB) {
      NotificationManager.error('Facebook SDK not loaded. Please refresh the page.');
      return;
    }

    FB.login(async (response) => {
      if (!response.authResponse?.accessToken) {
        return;
      }

      try {
        const authResponse = await this.sendAuthRequest(
          {
            provider: 'facebook',
            access_token: response.authResponse.accessToken
          }
        );

        if (authResponse.success) {
          await this.handleAuthSuccess(authResponse.data.data);
        } else {
          throw new Error(authResponse.data.message || 'Authentication failed');
        }
      } catch (error) {
        this.handleAuthError(error, instance);
      }
    }, {scope: this.config.facebook.scope});
  },

  // ========== GOOGLE PROVIDER ==========

  /**
   * Initialize Google provider
   */
  initGoogleProvider(instance) {
    const {element, config} = instance;

    // Load Google SDK if not loaded
    if (!this.state.sdkLoaded.google) {
      this.loadGoogleSDK(config, element);
    } else {
      // SDK already loaded (from previous page), render button immediately
      this.initGoogleButton(config, element);
    }
  },

  /**
   * Load Google Identity Services SDK
   */
  loadGoogleSDK(clientId, container) {
    if (document.getElementById('google-gsi')) {
      return;
    }

    const script = document.createElement('script');
    script.id = 'google-gsi';
    script.async = true;
    script.src = this.config.sdkUrls.google;
    script.onload = () => {
      this.state.sdkLoaded.google = true;
      this.initGoogleButton(clientId, container);
    };
    document.head.appendChild(script);
  },

  /**
   * Initialize Google Sign-In button
   */
  initGoogleButton(clientId, container) {
    if (!window.google) {
      console.error('[Google] SDK not loaded');
      return;
    }

    const cid = this.normalizeGoogleClientId(clientId);
    if (!cid) {
      console.warn('[Google] Missing client ID');
      return;
    }

    google.accounts.id.initialize({
      client_id: cid,
      callback: (response) => this.handleGoogleAuth(response)
    });

    google.accounts.id.renderButton(container, {
      theme: 'outline',
      size: 'large',
      width: container.offsetWidth || 300
    });

    container.style.display = '';
  },

  /**
   * Handle Google authentication
   */
  async handleGoogleAuth(response) {
    try {
      const authResponse = await this.sendAuthRequest(
        {
          provider: 'google',
          access_token: response.credential
        }
      );

      if (authResponse.success) {
        await this.handleAuthSuccess(authResponse.data.data);
      } else {
        throw new Error(authResponse.data.message || 'Authentication failed');
      }
    } catch (error) {
      this.handleAuthError(error);
    }
  },

  // ========== LINE PROVIDER ==========

  /**
   * Initialize LINE provider
   */
  initLineProvider(instance) {
    const {element} = instance;

    if (element.tagName === 'BUTTON') {
      element.style.display = '';

      const clickHandler = () => this.handleLineLogin(instance);
      element.addEventListener('click', clickHandler);
      instance.handlers.click = clickHandler;
    }
  },

  /**
   * Handle LINE OAuth redirect
   */
  handleLineLogin(instance) {
    const {config} = instance;

    try {
      // Get intended redirect URL
      const intendedUrlInput = document.querySelector('[name=intended_url]');
      const intendedPath = intendedUrlInput?.value || '/';

      // Convert to absolute URL
      const currentUrl = new URL(window.location.href);
      const basePath = currentUrl.pathname.substring(0, currentUrl.pathname.lastIndexOf('/') + 1);
      const baseUrl = `${currentUrl.protocol}//${currentUrl.host}${basePath}`;

      const relativePath = intendedPath.startsWith('/') ? intendedPath.substring(1) : intendedPath;
      const redirectAfterLogin = new URL(relativePath, baseUrl).href;

      // Build callback URL
      const callbackUrl = new URL(this.config.line.callbackPath, baseUrl).href;

      // Build LINE OAuth URL
      const params = new URLSearchParams({
        response_type: 'code',
        client_id: config,
        redirect_uri: callbackUrl,
        state: btoa(redirectAfterLogin),
        scope: this.config.line.scope,
        nonce: Math.random().toString(36).substring(2, 15),
        openExternalBrowser: '1'
      });

      const lineAuthUrl = `${this.config.line.authUrl}?${params.toString()}`;

      // Redirect to LINE (page will unload, no need to re-enable button)
      window.location.href = lineAuthUrl;
    } catch (error) {
      NotificationManager.error(error.message || 'LINE login failed');
    }
  },

  // ========== SHARED UTILITIES ==========

  /**
   * Send authentication request to backend
   * @param {Object} data - Authentication data
   * @returns {Promise<Object>} Response data
   */
  async sendAuthRequest(data) {
    // Add intended_url automatically
    const intendedUrl = document.querySelector('[name=intended_url]')?.value || '/';

    return await http.post(this.config.endpoint, {
      ...data,
      intended_url: intendedUrl
    });
  },

  /**
   * Handle successful authentication
   * @param {Object} data - Response data containing user and actions
   */
  async handleAuthSuccess(data) {
    // Set authenticated user
    await AuthManager.setAuthenticatedUser({
      ...data
    });

    // Process response
    await ResponseHandler.process({
      success: true,
      ...data
    });
  },

  /**
   * Handle authentication error
   * @param {Error} error - Error object
   * @param {Object} instance - Provider instance (optional)
   */
  handleAuthError(error, instance = null) {
    NotificationManager.error(error.message || 'Authentication failed');
  }
};

// Auto-initialize when DOM ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    SocialLoginComponent.init();
  }, {once: true});
} else {
  SocialLoginComponent.init();
}
