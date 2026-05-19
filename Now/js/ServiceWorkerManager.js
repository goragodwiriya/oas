/**
 * ServiceWorkerManager
 *
 * Manages the registration, updates, and lifecycle of a service worker
 * for the Now.js framework, with automatic caching of JavaScript files
 * and push notification support.
 *
 * @example
 * // Initialize with default options
 * ServiceWorkerManager.init();
 *
 * @example
 * // Initialize with custom options
 * ServiceWorkerManager.init({
 *   scope: '/',
 *   version: '1.0.0',
 *   cacheName: 'now-js-cache-v1',
 *   debug: true,
 *   precache: ['/index.html', '/css/styles.css'],
 *   strategies: {'/api/': 'network-first', '/images/': 'cache-first'},
 *   push: {enabled: true, publicKey: 'YOUR_VAPID_PUBLIC_KEY'}
 * });
 */
const ServiceWorkerManager = {
  config: {
    enabled: false,
    serviceWorkerPath: '/service-worker.js',
    scope: '/',
    version: '1.0.0',
    cacheName: 'now-js-cache',
    debug: false,
    updateInterval: 24 * 60 * 60 * 1000, // 24 hours
    precache: [
      '/',
      '/index.html'
    ],
    cacheJavascriptFiles: true,
    cachePatterns: [
      /\.js$/,      // JavaScript files
      /\.css$/,     // CSS files
      /\.html$/,    // HTML files
      /\.json$/,    // JSON files
      /\.png$/,     // PNG images
      /\.jpe?g$/,   // JPEG images
      /\.svg$/,     // SVG images
      /\.woff2?$/,  // Web fonts
      /\.ttf$/      // TrueType fonts
    ],
    networkFirst: [],
    excludeFromCache: [],
    notifyOnUpdate: true,
    offlineContent: null,
    push: {
      enabled: false,
      publicKey: null, // VAPID public key
      userVisibleOnly: true
    },
    strategies: {} // Optional caching strategies
  },

  state: {
    initialized: false,
    registration: null,
    updateFound: false,
    installingWorker: null,
    offlineReady: false,
    lastUpdateCheck: 0,
    status: 'unregistered',
    errors: [],
    pushEnabled: false,
    pushSubscription: null
  },

  /**
   * Initialize the ServiceWorkerManager with provided options
   * @param {Object} options - Configuration options
   * @returns {ServiceWorkerManager} - This instance for chaining
   */
  async init(options = {}) {
    try {
      if (this.state.initialized) return this;

      this.config = {...this.config, ...options};

      if (!this.config.enabled) {
        this.log('Service Worker is disabled in configuration');
        return this;
      }

      if (!this.isSupported()) {
        this.log('Service Worker is not supported in this browser');
        return this;
      }

      await this.registerServiceWorker();
      this.setupUpdateChecks();
      await this.setupPushNotifications();
      await this.updateConfig();

      this.state.initialized = true;
      return this;
    } catch (error) {
      this.handleError(error);
      return this;
    }
  },

  /**
   * Updates the service worker configuration by sending a message to the active service worker.
   * The configuration includes cache name, precache URLs, cache patterns, network-first patterns,
   * exclude patterns, caching strategies (e.g., 'network-first', 'cache-first', 'stale-while-revalidate'),
   * and push notification settings.
   * @async
   * @returns {Promise<void>} - Returns void if successful, or if no registration exists
   * @throws {Error} - Handled internally by handleError method
   */
  async updateConfig() {
    if (!this.state.registration) return;
    try {
      let sw = this.state.registration.active;
      if (!sw) {
        // Wait for the service worker to become active
        await new Promise(resolve => {
          const checkActive = () => {
            if (this.state.registration.active) {
              resolve();
            } else {
              setTimeout(checkActive, 100); // Poll every 100ms
            }
          };
          checkActive();
          this.state.registration.addEventListener('updatefound', () => {
            const worker = this.state.registration.installing;
            if (worker) {
              worker.addEventListener('statechange', () => {
                if (worker.state === 'activated') resolve();
              });
            }
          });
        });
        sw = this.state.registration.active;
      }
      if (!sw) throw new Error('No active service worker after waiting');

      const toPatternSource = arr => arr.map(p => p instanceof RegExp ? p.source : p);
      sw.postMessage({
        type: 'UPDATE_CONFIG',
        payload: {
          cacheName: this.config.cacheName,
          precacheUrls: this.config.precache,
          cachePatterns: toPatternSource(this.config.cachePatterns),
          networkFirstPatterns: toPatternSource(this.config.networkFirst),
          excludeFromCachePatterns: toPatternSource(this.config.excludeFromCache),
          strategies: this.config.strategies || {},
          push: {
            enabled: this.config.push.enabled,
            publicKey: this.config.push.publicKey,
            userVisibleOnly: this.config.push.userVisibleOnly
          }
        }
      });
      this.log('Sent config to service worker');
    } catch (error) {
      this.handleError(error, 'updateConfig');
    }
  },

  /**
   * Check if Service Workers and Push Notifications are supported in the current browser
   * @returns {Boolean}
   */
  isSupported() {
    return 'serviceWorker' in navigator && 'PushManager' in window;
  },

  /**
   * Register the service worker
   * @private
   */
  async registerServiceWorker() {
    try {
      this.state.status = 'registering';
      this.log('Registering service worker');
      const registration = await navigator.serviceWorker.register(
        this.config.serviceWorkerPath,
        {scope: this.config.scope}
      );
      this.state.registration = registration;
      this.state.status = 'registered';
      this.log('Service worker registered successfully');
      this.setupEventListeners(registration);
      this.checkForUpdates(registration);
      EventManager.emit('serviceworker:registered', {registration});
      return registration;
    } catch (error) {
      this.state.status = 'failed';
      this.handleError(error);
      throw error;
    }
  },

  /**
   * Sets up push notifications if they are enabled in the configuration and supported by the browser.
   * This method checks for an existing push subscription and updates the state accordingly.
   * @async
   * @returns {Promise<void>} A promise that resolves when the push notification setup is complete
   * @throws {Error} If there's an error during push notification setup
   */
  async setupPushNotifications() {
    if (!this.config.push.enabled || !this.state.registration) return;
    if (!('PushManager' in window)) {
      this.log('Push Notifications are not supported in this browser');
      return;
    }
    try {
      const subscription = await this.state.registration.pushManager.getSubscription();
      if (subscription) {
        this.state.pushEnabled = true;
        this.state.pushSubscription = subscription;
        this.log('Push notifications already subscribed');
      }
    } catch (error) {
      this.handleError(error, 'pushSetup');
    }
  },

  /**
   * Subscribes to push notifications using the service worker's push manager.
   * Requires a configured service worker and a valid public key for push notifications.
   * @async
   * @throws {Error} If push is not configured or no active service worker exists
   * @returns {Promise<PushSubscription>} The push notification subscription object
   */
  async subscribePush() {
    if (!this.state.registration || !this.config.push.publicKey) {
      throw new Error('Push not configured or no active service worker');
    }
    try {
      const subscription = await this.state.registration.pushManager.subscribe({
        userVisibleOnly: this.config.push.userVisibleOnly,
        applicationServerKey: this.config.push.publicKey
      });
      this.state.pushEnabled = true;
      this.state.pushSubscription = subscription;
      this.log('Subscribed to push notifications');
      EventManager.emit('serviceworker:push-subscribed', {subscription});
      return subscription;
    } catch (error) {
      this.handleError(error, 'pushSubscribe');
      throw error;
    }
  },

  /**
   * Unsubscribes from push notifications by removing the existing push subscription.
   * @async
   * @returns {Promise<boolean>} Returns true if successfully unsubscribed, false if no subscription existed
   * @throws {Error} If unsubscription fails
   */
  async unsubscribePush() {
    try {
      const subscription = await this.state.registration.pushManager.getSubscription();
      if (subscription) {
        await subscription.unsubscribe();
        this.state.pushEnabled = false;
        this.state.pushSubscription = null;
        this.log('Unsubscribed from push notifications');
        EventManager.emit('serviceworker:push-unsubscribed');
        return true;
      }
      return false;
    } catch (error) {
      this.handleError(error, 'pushUnsubscribe');
      throw error;
    }
  },

  /**
   * Checks if push notifications are enabled for this service worker instance.
   * @returns {boolean} True if push notifications are enabled, false otherwise
   */
  isPushEnabled() {
    return this.state.pushEnabled;
  },

  /**
   * Set up event listeners for the service worker
   * @param {ServiceWorkerRegistration} registration
   * @private
   */
  setupEventListeners(registration) {
    registration.addEventListener('updatefound', () => {
      this.state.updateFound = true;
      this.state.installingWorker = registration.installing;
      if (this.state.installingWorker) {
        this.state.installingWorker.addEventListener('statechange', () => {
          this.handleStateChange(this.state.installingWorker);
        });
      }
      this.log('New service worker found and is installing');
      EventManager.emit('serviceworker:update-found', {registration});
    });

    navigator.serviceWorker.addEventListener('controllerchange', () => {
      if (this.state.updateFound) {
        this.log('New service worker activated');
        this.showUpdateNotification();
      }
    });

    navigator.serviceWorker.addEventListener('message', (event) => {
      this.handleWorkerMessage(event);
    });
  },

  /**
   * Handle service worker state changes
   * @param {ServiceWorker} worker - The service worker instance
   * @private
   */
  handleStateChange(worker) {
    this.log(`Service worker state changed to: ${worker.state}`);
    switch (worker.state) {
      case 'installed':
        if (navigator.serviceWorker.controller) {
          this.log('New content is available; please refresh.');
          this.state.status = 'updated';
          if (this.config.notifyOnUpdate) {
            this.showUpdateNotification();
          }
        } else {
          this.log('Content is now cached for offline use.');
          this.state.status = 'installed';
          this.state.offlineReady = true;
        }
        break;
      case 'activated':
        this.state.status = 'activated';
        this.log('Service worker activated and controlling the page');
        break;
      case 'redundant':
        this.state.status = 'redundant';
        this.log('The installing service worker became redundant');
        break;
    }
    EventManager.emit('serviceworker:state-change', {state: worker.state, status: this.state.status});
  },

  /**
   * Notify user about service worker update
   * @private
   */
  showUpdateNotification() {
    if (!this.config.notifyOnUpdate) return;
    if (window.NotificationManager) {
      NotificationManager.info('A new version is available. Refresh the page to update.', {
        duration: 0,
        id: 'sw-update',
        actions: [{
          label: 'Refresh',
          callback: () => window.location.reload()
        }]
      });
    } else {
      this.log('A new version is available. Refresh the page to update.', 'info');
    }
  },

  /**
   * Check for service worker updates
   * @param {ServiceWorkerRegistration} registration
   * @private
   */
  async checkForUpdates(registration) {
    try {
      this.log('Checking for service worker updates');
      this.state.lastUpdateCheck = Date.now();
      await registration.update();
      this.log('Update check completed');
    } catch (error) {
      this.handleError(error);
    }
  },

  /**
   * Set up periodic update checks
   * @private
   */
  setupUpdateChecks() {
    if (!this.config.updateInterval) return;
    window.addEventListener('online', () => {
      this.log('Network connection restored, checking for updates');
      if (this.state.registration) {
        this.checkForUpdates(this.state.registration);
      }
    });
    setInterval(() => {
      if (navigator.onLine && this.state.registration) {
        this.log('Performing scheduled update check');
        this.checkForUpdates(this.state.registration);
      }
    }, this.config.updateInterval);
  },

  /**
   * Handle messages from the service worker
   * @param {MessageEvent} event
   * @private
   */
  handleWorkerMessage(event) {
    const data = event.data;
    if (!data || !data.type) return;
    this.log(`Received message from service worker: ${data.type}`, 'debug');
    switch (data.type) {
      case 'CACHE_UPDATED':
        this.log(`Cache updated: ${data.payload.cacheName}`);
        break;
      case 'CACHE_ERROR':
        this.handleError(new Error(data.payload.message), 'cache');
        break;
      case 'OFFLINE_READY':
        this.state.offlineReady = true;
        this.log('Application is ready for offline use');
        EventManager.emit('serviceworker:offline-ready');
        break;
      case 'LOG':
        this.log(`[SW] ${data.payload.message}`, data.payload.level);
        break;
      case 'PUSH_SUBSCRIPTION':
        this.state.pushSubscription = data.payload;
        this.state.pushEnabled = true;
        this.log('Push subscription updated from worker');
        break;
      case 'CONFIG_UPDATED':
        this.log('Service worker confirmed config update');
        break;
    }
    EventManager.emit('serviceworker:message', data);
  },

  /**
   * Log a message if debug is enabled
   * @param {String} message - The message to log
   * @param {String} level - Log level (default: 'log')
   * @private
   */
  log(message, level = 'log') {
    if (!this.config.debug) return;
    const prefix = '[ServiceWorkerManager]';
    switch (level) {
      case 'error':
        console.error(prefix, message);
        break;
      case 'warn':
        console.warn(prefix, message);
        break;
      case 'info':
        console.info(prefix, message);
        break;
      default:
        console.log(prefix, message);
    }
  },

  /**
   * Handle errors in ServiceWorkerManager
   * @param {Error} error - The error object
   * @param {String} context - Optional context string
   * @private
   */
  handleError(error, context = 'general') {
    const errorInfo = {
      message: error.message,
      stack: error.stack,
      context,
      timestamp: Date.now()
    };
    this.state.errors.push(errorInfo);
    this.log(`Error (${context}): ${error.message}`, 'error');
    if (window.ErrorManager) {
      ErrorManager.handle(error, {
        context: `ServiceWorkerManager.${context}`,
        type: 'error:serviceworker',
        data: errorInfo
      });
    }
    EventManager.emit('serviceworker:error', errorInfo);
  },

  /**
   * Manually request caching of specific URLs
   * @param {Array<String>} urls - Array of URLs to cache
   * @returns {Promise<Boolean>} Success status
   */
  async cacheUrls(urls) {
    if (!this.state.registration || !urls || !Array.isArray(urls)) return false;
    try {
      const sw = this.state.registration.active;
      if (!sw) throw new Error('No active service worker found');
      sw.postMessage({type: 'CACHE_URLS', payload: {urls}});
      return true;
    } catch (error) {
      this.handleError(error, 'cacheUrls');
      return false;
    }
  },

  /**
   * Clear the service worker cache
   * @returns {Promise<Boolean>} Success status
   */
  async clearCache() {
    if (!this.state.registration) return false;
    try {
      const sw = this.state.registration.active;
      if (!sw) throw new Error('No active service worker found');
      sw.postMessage({type: 'CLEAR_CACHE'});
      return true;
    } catch (error) {
      this.handleError(error, 'clearCache');
      return false;
    }
  },

  /**
   * Force update the service worker
   * @returns {Promise<Boolean>} Success status
   */
  async update() {
    if (!this.state.registration) return false;
    try {
      await this.state.registration.update();
      return true;
    } catch (error) {
      this.handleError(error, 'update');
      return false;
    }
  },

  /**
   * Force service worker to skip waiting and activate
   * @returns {Promise<Boolean>} Success status
   */
  async skipWaiting() {
    if (!this.state.registration || !this.state.installingWorker) return false;
    try {
      this.state.installingWorker.postMessage({type: 'SKIP_WAITING'});
      return true;
    } catch (error) {
      this.handleError(error, 'skipWaiting');
      return false;
    }
  },

  /**
   * Check if the application is ready for offline use
   * @returns {Boolean}
   */
  isOfflineReady() {
    return this.state.offlineReady;
  },

  /**
   * Get status information about the service worker
   * @returns {Object} Status information
   */
  getStatus() {
    return {
      supported: this.isSupported(),
      initialized: this.state.initialized,
      status: this.state.status,
      offlineReady: this.state.offlineReady,
      registration: this.state.registration,
      lastUpdateCheck: this.state.lastUpdateCheck,
      errors: this.state.errors.length,
      pushEnabled: this.state.pushEnabled
    };
  },

  /**
   * Unregister the service worker
   * @returns {Promise<Boolean>} Success status
   */
  async unregister() {
    if (!this.state.registration) return false;
    try {
      const success = await this.state.registration.unregister();
      if (success) {
        this.log('Service worker unregistered successfully');
        this.state.registration = null;
        this.state.status = 'unregistered';
        this.state.offlineReady = false;
        EventManager.emit('serviceworker:unregistered');
      }
      return success;
    } catch (error) {
      this.handleError(error, 'unregister');
      return false;
    }
  }
};

if (window.Now?.registerManager) {
  Now.registerManager('serviceWorker', ServiceWorkerManager);
}
window.ServiceWorkerManager = ServiceWorkerManager;
