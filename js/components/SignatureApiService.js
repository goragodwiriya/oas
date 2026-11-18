/**
 * SignatureApiService - Backend API integration for signature workflows
 * Handles all server communication for the e-signing system
 *
 * Features:
 * - RESTful API client with authentication
 * - Document upload and management
 * - Workflow creation and management
 * - Signature processing and validation
 * - Real-time status updates
 * - Error handling and retries
 * - Offline support and queue management
 */
class SignatureApiService {
  /**
   * Create SignatureApiService instance
   * @param {Object} options Configuration options
   */
  constructor(options = {}) {
    this.options = {
      // API configuration
      baseUrl: options.baseUrl || 'api/signatures',
      timeout: options.timeout || 30000,
      retries: options.retries || 3,
      retryDelay: options.retryDelay || 1000,

      // Authentication
      authHeader: options.authHeader || 'Authorization',
      authPrefix: options.authPrefix || 'Bearer ',

      // Endpoints
      endpoints: {
        documents: '/documents',
        workflows: '/workflows',
        participants: '/participants',
        signatures: '/signatures',
        templates: '/templates',
        notifications: '/notifications',
        audit: '/audit',
        status: '/status',
        upload: '/upload',
        download: '/download',
        verify: '/verify',
        ...options.endpoints
      },

      // Features
      enableRetries: options.enableRetries !== false,
      enableOfflineQueue: options.enableOfflineQueue !== false,
      enableProgressTracking: options.enableProgressTracking !== false,
      enableCaching: options.enableCaching !== false,

      ...options
    };

    // Initialize state
    this.state = {
      online: navigator.onLine,
      authenticated: false,
      queue: [],
      cache: new Map(),
      activeRequests: new Map()
    };

    // Initialize HTTP client
    this.httpClient = new HttpClient({
      baseURL: this.options.baseUrl,
      timeout: this.options.timeout
    });

    // Initialize managers
    this.authManager = null;
    this.queueManager = null;
    this.notificationManager = null;

    // Initialize service
    this.init();
  }

  /**
   * Initialize API service
   */
  async init() {
    try {
      // Get framework managers
      this.authManager = Now.getManager('AuthManager');
      this.queueManager = Now.getManager('QueueManager');
      this.notificationManager = Now.getManager('NotificationManager');

      // Setup HTTP client interceptors
      this.setupInterceptors();

      // Setup event listeners
      this.setupEventListeners();

      // Process offline queue if online
      if (this.state.online && this.options.enableOfflineQueue) {
        await this.processOfflineQueue();
      }

      if (this.options.debug) console.info('SignatureApiService initialized');

    } catch (error) {
      console.error('Failed to initialize SignatureApiService:', error);
      throw error;
    }
  }

  /**
   * Setup HTTP client interceptors
   */
  setupInterceptors() {
    // Request interceptor - add authentication
    this.httpClient.addInterceptor('request', (config) => {
      // Add authentication token
      if (this.authManager && this.authManager.isAuthenticated()) {
        const token = this.authManager.getToken();
        if (token) {
          config.headers[this.options.authHeader] = this.options.authPrefix + token;
        }
      }

      // Add common headers
      config.headers['Content-Type'] = config.headers['Content-Type'] || 'application/json';
      config.headers['X-Requested-With'] = 'XMLHttpRequest';

      // Add CSRF token if available
      const csrfToken = this.getCsrfToken();
      if (csrfToken) {
        config.headers['X-CSRF-Token'] = csrfToken;
      }

      return config;
    });

    // Response interceptor - handle errors and retries
    this.httpClient.addInterceptor('response', async (response) => {
      // Handle authentication errors
      if (response.status === 401) {
        this.handleAuthenticationError();
        throw new Error('Authentication required');
      }

      // Handle server errors with retries
      if (response.status >= 500 && this.options.enableRetries) {
        const requestConfig = response.config;
        const retryCount = requestConfig.retryCount || 0;

        if (retryCount < this.options.retries) {
          requestConfig.retryCount = retryCount + 1;
          await this.delay(this.options.retryDelay * Math.pow(2, retryCount));
          return this.httpClient.request(requestConfig);
        }
      }

      return response;
    });
  }

  /**
   * Setup event listeners
   */
  setupEventListeners() {
    // Network status
    window.addEventListener('online', () => {
      this.state.online = true;
      this.handleOnline();
    });

    window.addEventListener('offline', () => {
      this.state.online = false;
      this.handleOffline();
    });

    // Authentication events
    if (this.authManager) {
      this.authManager.on('authenticated', () => {
        this.state.authenticated = true;
      });

      this.authManager.on('logout', () => {
        this.state.authenticated = false;
        this.clearCache();
      });
    }
  }

  /**
   * Document Management API
   */

  /**
   * Upload document
   * @param {File|Blob} file Document file
   * @param {Object} metadata Document metadata
   * @param {Function} onProgress Progress callback
   * @returns {Promise<Object>} Upload result
   */
  async uploadDocument(file, metadata = {}, onProgress = null) {
    try {
      const formData = new FormData();
      formData.append('file', file);
      formData.append('metadata', JSON.stringify(metadata));

      const config = {
        headers: {'Content-Type': 'multipart/form-data'},
        onUploadProgress: onProgress
      };

      const response = await this.httpClient.post(
        this.options.endpoints.upload,
        formData,
        config
      );

      return this.handleResponse(response);

    } catch (error) {
      return this.handleError('Failed to upload document', error);
    }
  }

  /**
   * Get document information
   * @param {String} documentId Document ID
   * @returns {Promise<Object>} Document information
   */
  async getDocument(documentId) {
    try {
      // Check cache first
      const cacheKey = `document:${documentId}`;
      if (this.options.enableCaching && this.state.cache.has(cacheKey)) {
        return this.state.cache.get(cacheKey);
      }

      const response = await this.httpClient.get(
        `${this.options.endpoints.documents}/${documentId}`
      );

      const result = this.handleResponse(response);

      // Cache result
      if (this.options.enableCaching && result.success) {
        this.state.cache.set(cacheKey, result);
      }

      return result;

    } catch (error) {
      return this.handleError('Failed to get document', error);
    }
  }

  /**
   * Update document
   * @param {String} documentId Document ID
   * @param {Object} updates Update data
   * @returns {Promise<Object>} Update result
   */
  async updateDocument(documentId, updates) {
    try {
      const response = await this.httpClient.put(
        `${this.options.endpoints.documents}/${documentId}`,
        updates
      );

      const result = this.handleResponse(response);

      // Update cache
      if (this.options.enableCaching && result.success) {
        const cacheKey = `document:${documentId}`;
        this.state.cache.delete(cacheKey);
      }

      return result;

    } catch (error) {
      return this.handleError('Failed to update document', error);
    }
  }

  /**
   * Delete document
   * @param {String} documentId Document ID
   * @returns {Promise<Object>} Delete result
   */
  async deleteDocument(documentId) {
    try {
      const response = await this.httpClient.delete(
        `${this.options.endpoints.documents}/${documentId}`
      );

      const result = this.handleResponse(response);

      // Clear cache
      if (this.options.enableCaching) {
        const cacheKey = `document:${documentId}`;
        this.state.cache.delete(cacheKey);
      }

      return result;

    } catch (error) {
      return this.handleError('Failed to delete document', error);
    }
  }

  /**
   * Workflow Management API
   */

  /**
   * Create workflow
   * @param {Object} workflowData Workflow configuration
   * @returns {Promise<Object>} Workflow result
   */
  async createWorkflow(workflowData) {
    try {
      const response = await this.httpClient.post(
        this.options.endpoints.workflows,
        workflowData
      );

      return this.handleResponse(response);

    } catch (error) {
      return this.handleError('Failed to create workflow', error);
    }
  }

  /**
   * Get workflow information
   * @param {String} workflowId Workflow ID
   * @returns {Promise<Object>} Workflow information
   */
  async getWorkflow(workflowId) {
    try {
      // Check cache first
      const cacheKey = `workflow:${workflowId}`;
      if (this.options.enableCaching && this.state.cache.has(cacheKey)) {
        return this.state.cache.get(cacheKey);
      }

      const response = await this.httpClient.get(
        `${this.options.endpoints.workflows}/${workflowId}`
      );

      const result = this.handleResponse(response);

      // Cache result
      if (this.options.enableCaching && result.success) {
        this.state.cache.set(cacheKey, result);
      }

      return result;

    } catch (error) {
      return this.handleError('Failed to get workflow', error);
    }
  }

  /**
   * Update workflow
   * @param {String} workflowId Workflow ID
   * @param {Object} updates Update data
   * @returns {Promise<Object>} Update result
   */
  async updateWorkflow(workflowId, updates) {
    try {
      const response = await this.httpClient.put(
        `${this.options.endpoints.workflows}/${workflowId}`,
        updates
      );

      const result = this.handleResponse(response);

      // Update cache
      if (this.options.enableCaching && result.success) {
        const cacheKey = `workflow:${workflowId}`;
        this.state.cache.delete(cacheKey);
      }

      return result;

    } catch (error) {
      return this.handleError('Failed to update workflow', error);
    }
  }

  /**
   * Send signature requests
   * @param {String} workflowId Workflow ID
   * @param {Array} participantIds Participant IDs
   * @returns {Promise<Object>} Send result
   */
  async sendSignatureRequests(workflowId, participantIds = null) {
    try {
      const requestData = participantIds ? {participants: participantIds} : {};

      const response = await this.httpClient.post(
        `${this.options.endpoints.workflows}/${workflowId}/send`,
        requestData
      );

      return this.handleResponse(response);

    } catch (error) {
      return this.handleError('Failed to send signature requests', error);
    }
  }

  /**
   * Cancel workflow
   * @param {String} workflowId Workflow ID
   * @param {String} reason Cancellation reason
   * @returns {Promise<Object>} Cancel result
   */
  async cancelWorkflow(workflowId, reason = 'Cancelled by user') {
    try {
      const response = await this.httpClient.post(
        `${this.options.endpoints.workflows}/${workflowId}/cancel`,
        {reason}
      );

      const result = this.handleResponse(response);

      // Update cache
      if (this.options.enableCaching && result.success) {
        const cacheKey = `workflow:${workflowId}`;
        this.state.cache.delete(cacheKey);
      }

      return result;

    } catch (error) {
      return this.handleError('Failed to cancel workflow', error);
    }
  }

  /**
   * Complete workflow
   * @param {String} workflowId Workflow ID
   * @returns {Promise<Object>} Complete result
   */
  async completeWorkflow(workflowId) {
    try {
      const response = await this.httpClient.post(
        `${this.options.endpoints.workflows}/${workflowId}/complete`
      );

      const result = this.handleResponse(response);

      // Update cache
      if (this.options.enableCaching && result.success) {
        const cacheKey = `workflow:${workflowId}`;
        this.state.cache.delete(cacheKey);
      }

      return result;

    } catch (error) {
      return this.handleError('Failed to complete workflow', error);
    }
  }

  /**
   * Signature Processing API
   */

  /**
   * Submit signature
   * @param {String} workflowId Workflow ID
   * @param {Object} signatureData Signature data
   * @returns {Promise<Object>} Signature result
   */
  async submitSignature(workflowId, signatureData) {
    try {
      const response = await this.httpClient.post(
        `${this.options.endpoints.signatures}/${workflowId}`,
        signatureData
      );

      return this.handleResponse(response);

    } catch (error) {
      return this.handleError('Failed to submit signature', error);
    }
  }

  /**
   * Verify signature
   * @param {String} signatureId Signature ID
   * @returns {Promise<Object>} Verification result
   */
  async verifySignature(signatureId) {
    try {
      const response = await this.httpClient.get(
        `${this.options.endpoints.verify}/${signatureId}`
      );

      return this.handleResponse(response);

    } catch (error) {
      return this.handleError('Failed to verify signature', error);
    }
  }

  /**
   * Get signature status
   * @param {String} signatureId Signature ID
   * @returns {Promise<Object>} Status information
   */
  async getSignatureStatus(signatureId) {
    try {
      const response = await this.httpClient.get(
        `${this.options.endpoints.status}/${signatureId}`
      );

      return this.handleResponse(response);

    } catch (error) {
      return this.handleError('Failed to get signature status', error);
    }
  }

  /**
   * Notification API
   */

  /**
   * Send notification
   * @param {Object} notificationData Notification data
   * @returns {Promise<Object>} Send result
   */
  async sendNotification(notificationData) {
    try {
      const response = await this.httpClient.post(
        this.options.endpoints.notifications,
        notificationData
      );

      return this.handleResponse(response);

    } catch (error) {
      return this.handleError('Failed to send notification', error);
    }
  }

  /**
   * Get notification history
   * @param {String} workflowId Workflow ID
   * @returns {Promise<Object>} Notification history
   */
  async getNotificationHistory(workflowId) {
    try {
      const response = await this.httpClient.get(
        `${this.options.endpoints.notifications}/${workflowId}`
      );

      return this.handleResponse(response);

    } catch (error) {
      return this.handleError('Failed to get notification history', error);
    }
  }

  /**
   * Audit Trail API
   */

  /**
   * Get audit trail
   * @param {String} workflowId Workflow ID
   * @returns {Promise<Object>} Audit trail
   */
  async getAuditTrail(workflowId) {
    try {
      const response = await this.httpClient.get(
        `${this.options.endpoints.audit}/${workflowId}`
      );

      return this.handleResponse(response);

    } catch (error) {
      return this.handleError('Failed to get audit trail', error);
    }
  }

  /**
   * Template Management API
   */

  /**
   * Get templates
   * @param {Object} filters Filter options
   * @returns {Promise<Object>} Templates list
   */
  async getTemplates(filters = {}) {
    try {
      const queryString = new URLSearchParams(filters).toString();
      const url = queryString ?
        `${this.options.endpoints.templates}?${queryString}` :
        this.options.endpoints.templates;

      const response = await this.httpClient.get(url);

      return this.handleResponse(response);

    } catch (error) {
      return this.handleError('Failed to get templates', error);
    }
  }

  /**
   * Create template
   * @param {Object} templateData Template data
   * @returns {Promise<Object>} Template result
   */
  async createTemplate(templateData) {
    try {
      const response = await this.httpClient.post(
        this.options.endpoints.templates,
        templateData
      );

      return this.handleResponse(response);

    } catch (error) {
      return this.handleError('Failed to create template', error);
    }
  }

  /**
   * Utility Methods
   */

  /**
   * Handle API response
   * @param {Object} response HTTP response
   * @returns {Object} Processed response
   */
  handleResponse(response) {
    if (response.success) {
      return {
        success: true,
        data: response.data,
        status: response.status,
        headers: response.headers
      };
    } else {
      return {
        success: false,
        error: response.data || response.statusText,
        status: response.status,
        headers: response.headers
      };
    }
  }

  /**
   * Handle API error
   * @param {String} message Error message
   * @param {Error} error Original error
   * @returns {Object} Error response
   */
  handleError(message, error) {
    const errorResponse = {
      success: false,
      error: message,
      details: error.message,
      timestamp: new Date().toISOString()
    };

    // Log error
    console.error('SignatureApiService Error:', errorResponse);

    // Queue request if offline
    if (!this.state.online && this.options.enableOfflineQueue) {
      this.queueRequest(error.config);
    }

    // Show notification
    if (this.notificationManager) {
      this.notificationManager.error(message);
    }

    return errorResponse;
  }

  /**
   * Handle authentication error
   */
  handleAuthenticationError() {
    this.state.authenticated = false;

    if (this.authManager) {
      this.authManager.logout();
    }
  }

  /**
   * Handle online event
   */
  async handleOnline() {
    if (this.options.debug) console.info('SignatureApiService: Back online');

    if (this.options.enableOfflineQueue) {
      await this.processOfflineQueue();
    }
  }

  /**
   * Handle offline event
   */
  handleOffline() {
    if (this.options.debug) console.info('SignatureApiService: Gone offline');
  }

  /**
   * Queue request for offline processing
   * @param {Object} requestConfig Request configuration
   */
  queueRequest(requestConfig) {
    if (!this.options.enableOfflineQueue) return;

    this.state.queue.push({
      ...requestConfig,
      timestamp: Date.now()
    });

    if (this.options.debug) console.info('Request queued for offline processing');
  }

  /**
   * Process offline queue
   */
  async processOfflineQueue() {
    if (this.state.queue.length === 0) return;

    if (this.options.debug) console.info(`Processing ${this.state.queue.length} queued requests`);

    const results = [];

    for (const request of this.state.queue) {
      try {
        const response = await this.httpClient.request(request);
        results.push({success: true, response});
      } catch (error) {
        results.push({success: false, error});
      }
    }

    // Clear processed requests
    this.state.queue = [];

    return results;
  }

  /**
   * Get CSRF token
   * @returns {String|null} CSRF token
   */
  getCsrfToken() {
    // Try to get from meta tag
    const metaTag = document.querySelector('meta[name="csrf-token"]');
    if (metaTag) {
      return metaTag.getAttribute('content');
    }

    // Try to get from cookie
    const cookies = document.cookie.split(';');
    for (const cookie of cookies) {
      const [name, value] = cookie.trim().split('=');
      if (name === 'XSRF-TOKEN') {
        return decodeURIComponent(value);
      }
    }

    return null;
  }

  /**
   * Clear cache
   */
  clearCache() {
    this.state.cache.clear();
  }

  /**
   * Delay utility
   * @param {Number} ms Milliseconds to delay
   * @returns {Promise} Delay promise
   */
  delay(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
  }

  /**
   * Get service state
   * @returns {Object} Service state
   */
  getState() {
    return {...this.state};
  }

  /**
   * Check if service is online
   * @returns {Boolean} Online status
   */
  isOnline() {
    return this.state.online;
  }

  /**
   * Check if service is authenticated
   * @returns {Boolean} Authentication status
   */
  isAuthenticated() {
    return this.state.authenticated;
  }

  /**
   * Get queue size
   * @returns {Number} Queue size
   */
  getQueueSize() {
    return this.state.queue.length;
  }

  /**
   * Get cache size
   * @returns {Number} Cache size
   */
  getCacheSize() {
    return this.state.cache.size;
  }

  /**
   * Destroy service
   */
  destroy() {
    // Clear cache and queue
    this.clearCache();
    this.state.queue = [];

    // Remove event listeners
    window.removeEventListener('online', this.handleOnline);
    window.removeEventListener('offline', this.handleOffline);

    // Cancel active requests
    this.state.activeRequests.forEach(request => {
      if (request.cancel) {
        request.cancel();
      }
    });
    this.state.activeRequests.clear();
  }
}

// Register with Now.js framework
if (typeof Now !== 'undefined') {
  Now.registerService('SignatureApiService', SignatureApiService);
}

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
  module.exports = SignatureApiService;
}
