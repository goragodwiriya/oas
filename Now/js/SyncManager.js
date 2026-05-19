/**
 * SyncManager
 * A manager for synchronizing offline data with a server
 * Provides queue functionality, priorities, and automatic retry
 *
 * Features:
 * - Offline queue management
 * - Priority-based synchronization
 * - Automatic retry with backoff strategies
 * - Integration with ApiService and HttpClient
 * - Network status monitoring
 * - Event-based system
 * - Enable/disable functionality
 */
const SyncManager = {
  config: {
    enabled: false,
    autoSync: false,
    syncInterval: 60000, // 1 minute
    retryStrategy: 'exponential', // exponential, linear, fixed
    maxRetries: 5,
    pendingOperationsStore: 'sync_pending_operations',
    onSyncComplete: null,
    onSyncError: null,
    debounceInterval: 5000, // Debounce multiple sync requests
    batchSize: 10, // How many operations to process in one batch
    concurrentRequests: 3, // How many concurrent API requests to make
    priorityMapping: {
      high: 0,
      medium: 1,
      low: 2
    },
    endpoints: {
      // Default endpoint mapping
      // If not specified, will try to use storeName as endpoint path
    },
    minRetryDelay: 1000, // Minimum delay between retries in ms
    maxRetryDelay: 30000, // Maximum delay between retries in ms
    defaultIdField: 'id', // Default field name used for IDs
    defaultHeaders: {
      'Content-Type': 'application/json'
    },
    tokens: {
      enabled: false,
      headerName: 'Authorization',
      prefix: 'Bearer ',
      tokenKey: 'auth_token',
      storage: 'localStorage' // localStorage, sessionStorage
    }
  },

  state: {
    initialized: false,
    enabled: false,
    online: navigator.onLine,
    syncing: false,
    pendingSync: new Map(),
    lastSyncTime: null,
    syncTimerId: null,
    syncPromise: null,
    debounceTimer: null,
    activeRequests: 0,
    operationsProcessed: 0,
    syncQueue: [],
    failedOperations: []
  },

  /**
   * Initialize the SyncManager
   * @param {Object} options - Configuration options
   * @returns {Promise<SyncManager>} - Returns the SyncManager instance
   */
  async init(options = {}) {
    try {
      this.config = {...this.config, ...options};

      this.storageManager = window.Now?.getManager('storage');

      if (!this.storageManager) {
        throw new Error('StorageManager is required but not found');
      }

      if (!this.storageManager.state.initialized) {
        try {
          await this.storageManager.init();
        } catch (storageError) {
          console.error('Failed to initialize StorageManager:', storageError);
          throw new Error(`StorageManager initialization failed: ${storageError.message}`);
        }
      }

      try {
        await this.storageManager.createDatabase({
          name: this.storageManager.config.defaultDB,
          version: this.storageManager.config.defaultVersion,
          stores: {
            [this.config.pendingOperationsStore]: {
              keyPath: 'id',
              autoIncrement: true,
              indexes: [
                {name: 'storeName', keyPath: 'storeName'},
                {name: 'operation', keyPath: 'operation'},
                {name: 'priority', keyPath: 'priority'},
                {name: 'status', keyPath: 'status'},
                {name: 'timestamp', keyPath: 'timestamp'}
              ]
            }
          }
        });
      } catch (dbError) {
        console.error('Failed to create database for SyncManager:', dbError);
        throw new Error(`Failed to create sync storage: ${dbError.message}`);
      }

      window.addEventListener('online', this.handleOnline.bind(this));
      window.addEventListener('offline', this.handleOffline.bind(this));

      if (typeof document.hidden !== 'undefined') {
        document.addEventListener('visibilitychange', () => {
          if (!document.hidden && navigator.onLine && this.config.autoSync && this.state.enabled) {
            this.debouncedSync();
          }
        });
      }

      this.state.initialized = true;

      EventManager.emit('sync:initialized');

      return this;
    } catch (error) {
      console.error('SyncManager initialization failed:', error);
      if (window.ErrorManager) {
        ErrorManager.handle(error, {
          context: 'SyncManager.init',
          type: 'sync:error'
        });
      }
      throw error;
    }
  },

  async enable() {
    if (!this.state.initialized) {
      await this.init();
    }

    this.state.enabled = true;
    this.config.enabled = true;

    if (this.config.autoSync && navigator.onLine) {
      this.startAutoSync();
      this.debouncedSync();
    }

    EventManager.emit('sync:enabled');

    return this;
  },

  disable() {
    this.state.enabled = false;
    this.config.enabled = false;

    this.stopAutoSync();

    if (this.state.debounceTimer) {
      clearTimeout(this.state.debounceTimer);
      this.state.debounceTimer = null;
    }


    EventManager.emit('sync:disabled');

    return this;
  },

  /**
   * Add a pending operation to be synced later
   * @param {Object} operation - The operation details
   * @param {string} operation.storeName - Store name
   * @param {string} operation.method - HTTP method ('add', 'update', 'delete')
   * @param {Object} operation.data - Data to send
   * @param {string} operation.priority - Priority ('high', 'medium', 'low')
   * @returns {Promise<Object>} - The pending operation
   */
  async addPendingOperation(operation) {
    if (!this.state.initialized) {
      await this.init();
    }

    const result = await this._storePendingOperation(operation);

    if (!this.config.enabled || !this.state.enabled) {
      EventManager.emit('sync:operation:stored', {
        operation: result,
        message: 'Stored but sync is disabled'
      });
      return result;
    }

    if (this.config.autoSync && navigator.onLine) {
      this.debouncedSync();
    }

    return result;
  },

  async _storePendingOperation(operation) {
    const {storeName, data, method, priority = 'medium'} = operation;

    if (!storeName || !data || !method) {
      throw new Error('Missing required fields for pending operation');
    }

    const pendingOp = {
      storeName,
      operation: method, // 'add', 'update', 'delete'
      data,
      priority: this.config.priorityMapping[priority] || 1,
      timestamp: Date.now(),
      attempts: 0,
      status: 'pending',
      nextRetry: Date.now()
    };

    const id = await this.storageManager.add(
      this.config.pendingOperationsStore,
      pendingOp
    );

    EventManager.emit('sync:operation:added', {
      operation: pendingOp,
      id
    });

    return {
      ...pendingOp,
      id
    };
  },

  /**
   * Debounce multiple sync requests
   */
  debouncedSync() {
    if (!this.config.enabled || !this.state.enabled) {
      return;
    }

    clearTimeout(this.state.debounceTimer);
    this.state.debounceTimer = setTimeout(() => {
      this.syncAll();
    }, this.config.debounceInterval);
  },

  /**
   * Sync all pending operations
   * @returns {Promise<Object>} - Sync results
   */
  async syncAll() {
    if (!this.config.enabled || !this.state.enabled) {
      return Promise.resolve({
        successful: 0,
        failed: 0,
        total: 0,
        disabled: true,
        message: 'Sync is disabled'
      });
    }

    if (this.state.syncing) {
      return this.state.syncPromise;
    }

    if (!navigator.onLine) {
      return Promise.resolve({
        successful: 0,
        failed: 0,
        total: 0,
        offline: true
      });
    }

    this.state.syncing = true;
    EventManager.emit('sync:started');

    this.state.syncPromise = new Promise(async (resolve, reject) => {
      try {
        const pendingOps = await this.storageManager.getAll(
          this.config.pendingOperationsStore,
          {
            index: 'priority',
            direction: 'asc'
          }
        );

        if (pendingOps.length === 0) {
          this.state.syncing = false;
          const result = {
            successful: 0,
            failed: 0,
            total: 0,
            timestamp: Date.now()
          };
          this.state.lastSyncTime = Date.now();
          EventManager.emit('sync:completed', result);
          if (typeof this.config.onSyncComplete === 'function') {
            this.config.onSyncComplete(result);
          }
          resolve(result);
          return;
        }

        let successful = 0;
        let failed = 0;
        this.state.operationsProcessed = 0;
        this.state.syncQueue = [...pendingOps];
        this.state.failedOperations = [];

        const workers = Array(this.config.concurrentRequests).fill(null).map(() =>
          this.processOperationsWorker()
        );

        await Promise.all(workers);

        successful = this.state.operationsProcessed;
        failed = this.state.failedOperations.length;

        this.state.lastSyncTime = Date.now();

        const result = {
          successful,
          failed,
          total: pendingOps.length,
          timestamp: Date.now()
        };

        EventManager.emit('sync:completed', result);

        if (typeof this.config.onSyncComplete === 'function') {
          this.config.onSyncComplete(result);
        }

        resolve(result);
      } catch (error) {
        EventManager.emit('sync:error', {error});

        if (typeof this.config.onSyncError === 'function') {
          this.config.onSyncError(error);
        }

        if (window.ErrorManager) {
          ErrorManager.handle(error, {
            context: 'SyncManager.syncAll',
            type: 'sync:error'
          });
        }

        reject(error);
      } finally {
        this.state.syncing = false;
      }
    });

    return this.state.syncPromise;
  },

  /**
   * Worker function to process operations in parallel
   * @returns {Promise<void>}
   * @private
   */
  async processOperationsWorker() {
    while (this.state.syncQueue.length > 0) {
      const operation = this.state.syncQueue.shift();

      if (operation.nextRetry && operation.nextRetry > Date.now()) {
        this.state.syncQueue.push(operation);
        await new Promise(resolve => setTimeout(resolve, 100));
        continue;
      }

      try {
        await this.processOperation(operation);
        this.state.operationsProcessed++;
      } catch (error) {
        this.state.failedOperations.push({
          operation,
          error: {
            message: error.message,
            status: error.status || 0,
            timestamp: Date.now()
          }
        });
      }
    }
  },

  /**
   * Process a single operation
   * @param {Object} operation - The operation to process
   * @returns {Promise<void>}
   * @private
   */
  async processOperation(operation) {
    try {
      const endpoint = this.getEndpoint(operation.storeName);
      if (!endpoint) {
        throw new Error(`No endpoint configured for store: ${operation.storeName}`);
      }

      let apiMethod;
      switch (operation.operation) {
        case 'add':
          apiMethod = 'post';
          break;
        case 'update':
          apiMethod = 'put';
          break;
        case 'delete':
          apiMethod = 'delete';
          break;
        default:
          apiMethod = 'post';
      }

      this.state.activeRequests++;

      const apiService = window.Now?.getManager('api');
      const httpClient = window.http;

      const headers = {...this.config.defaultHeaders};

      if (this.config.tokens.enabled) {
        const storage = this.config.tokens.storage === 'sessionStorage' ?
          sessionStorage : localStorage;
        const token = storage.getItem(this.config.tokens.tokenKey);
        if (token) {
          headers[this.config.tokens.headerName] = `${this.config.tokens.prefix}${token}`;
        }
      }

      let response;
      if (apiService) {
        response = await apiService[apiMethod](endpoint, operation.data);
      } else if (httpClient) {
        response = await httpClient[apiMethod](endpoint, operation.data, {headers});
      } else if (window.simpleFetch && typeof window.simpleFetch[apiMethod] === 'function') {
        if (apiMethod === 'delete') {
          response = await window.simpleFetch.delete(endpoint, {headers});
        } else {
          response = await window.simpleFetch[apiMethod](endpoint, operation.data, {headers});
        }
      } else {
        throw new Error('No HTTP client available');
      }

      await this.storageManager.delete(this.config.pendingOperationsStore, operation.id);

      this.state.activeRequests--;

      EventManager.emit('sync:operation:success', {
        operation,
        response
      });

      return response;
    } catch (error) {
      this.state.activeRequests--;
      return this.handleSyncError(operation, error);
    }
  },

  /**
   * Handle synchronization errors
   * @param {Object} operation - The operation that failed
   * @param {Error} error - The error object
   * @returns {Promise<void>}
   * @private
   */
  async handleSyncError(operation, error) {
    operation.attempts += 1;
    operation.lastError = {
      message: error.message || 'Unknown error',
      status: error.status || 0,
      timestamp: Date.now()
    };

    if (operation.attempts < this.config.maxRetries) {
      let delay = this.config.minRetryDelay;

      if (this.config.retryStrategy === 'exponential') {
        delay = Math.min(
          Math.pow(2, operation.attempts) * this.config.minRetryDelay,
          this.config.maxRetryDelay
        );
      } else if (this.config.retryStrategy === 'linear') {
        delay = Math.min(
          operation.attempts * this.config.minRetryDelay,
          this.config.maxRetryDelay
        );
      }

      operation.nextRetry = Date.now() + delay;
      operation.status = 'pending';

      await this.storageManager.update(
        this.config.pendingOperationsStore,
        operation
      );

      EventManager.emit('sync:operation:retry', {
        operation,
        error: operation.lastError,
        nextRetry: new Date(operation.nextRetry)
      });
    } else {
      operation.status = 'failed';
      await this.storageManager.update(
        this.config.pendingOperationsStore,
        operation
      );

      EventManager.emit('sync:operation:failed', {
        operation,
        error: operation.lastError
      });
    }

    throw error;
  },

  /**
   * Handle device coming online
   */
  handleOnline() {
    this.state.online = true;

    EventManager.emit('sync:online');

    if (this.config.autoSync && this.config.enabled && this.state.enabled) {
      this.debouncedSync();
      this.startAutoSync();
    }
  },

  /**
   * Handle device going offline
   */
  handleOffline() {
    this.state.online = false;

    EventManager.emit('sync:offline');

    if (this.state.syncTimerId) {
      clearInterval(this.state.syncTimerId);
      this.state.syncTimerId = null;
    }
  },

  /**
   * Start automatic synchronization
   */
  startAutoSync() {
    if (this.state.syncTimerId) {
      clearInterval(this.state.syncTimerId);
    }

    this.state.syncTimerId = setInterval(() => {
      if (navigator.onLine && !this.state.syncing && this.config.enabled && this.state.enabled) {
        this.syncAll();
      }
    }, this.config.syncInterval);
  },

  /**
   * Stop automatic synchronization
   */
  stopAutoSync() {
    if (this.state.syncTimerId) {
      clearInterval(this.state.syncTimerId);
      this.state.syncTimerId = null;
    }
  },

  /**
   * Get endpoint for a store
   * @param {string} storeName - The store name
   * @returns {string} The endpoint URL
   */
  getEndpoint(storeName) {
    if (this.config.endpoints[storeName]) {
      return this.config.endpoints[storeName];
    }
    return `api/${storeName}`;
  },

  /**
   * Get all failed operations
   * @returns {Promise<Array>} - Failed operations
   */
  async getFailedOperations() {
    return this.storageManager.query(
      this.config.pendingOperationsStore,
      {
        index: 'status',
        value: 'failed'
      }
    );
  },

  /**
   * Get count of pending operations
   * @returns {Promise<number>} - Count of pending operations
   */
  async getPendingCount() {
    return this.storageManager.count(this.config.pendingOperationsStore);
  },

  /**
   * Get all pending operations
   * @returns {Promise<Array>} - All pending operations
   */
  async getPendingOperations() {
    return this.storageManager.getAll(this.config.pendingOperationsStore);
  },

  /**
   * Reset failed operations to pending
   * @returns {Promise<number>} - Number of operations reset
   */
  async resetFailedOperations() {
    const failed = await this.getFailedOperations();

    for (const op of failed) {
      op.attempts = 0;
      op.status = 'pending';
      op.nextRetry = Date.now();
      delete op.lastError;
      await this.storageManager.update(
        this.config.pendingOperationsStore,
        op
      );
    }

    if (navigator.onLine && failed.length > 0 && this.config.enabled && this.state.enabled) {
      this.debouncedSync();
    }

    return failed.length;
  },

  /**
   * Clear all pending operations
   * @returns {Promise<boolean>} - Success status
   */
  async clearPendingOperations() {
    await this.storageManager.clear(this.config.pendingOperationsStore);

    EventManager.emit('sync:cleared');

    return true;
  },

  /**
   * Delete a specific pending operation
   * @param {number} id - Operation ID
   * @returns {Promise<boolean>} - Success status
   */
  async deletePendingOperation(id) {
    await this.storageManager.delete(this.config.pendingOperationsStore, id);

    EventManager.emit('sync:operation:deleted', {id});

    return true;
  },

  /**
   * Manually update an operation
   * @param {Object} operation - The operation to update
   * @returns {Promise<boolean>} - Success status
   */
  async updateOperation(operation) {
    await this.storageManager.update(
      this.config.pendingOperationsStore,
      operation
    );

    EventManager.emit('sync:operation:updated', {operation});

    return true;
  },

  /**
   * Get sync status
   * @returns {Object} - Current sync status information
   */
  getSyncStatus() {
    return {
      enabled: this.config.enabled && this.state.enabled,
      online: this.state.online,
      syncing: this.state.syncing,
      lastSyncTime: this.state.lastSyncTime,
      activeRequests: this.state.activeRequests,
      autoSyncEnabled: this.config.autoSync && this.state.syncTimerId !== null
    };
  },

  /**
   * Clean up resources
   */
  destroy() {
    if (this.state.syncTimerId) {
      clearInterval(this.state.syncTimerId);
      this.state.syncTimerId = null;
    }

    if (this.state.debounceTimer) {
      clearTimeout(this.state.debounceTimer);
      this.state.debounceTimer = null;
    }

    window.removeEventListener('online', this.handleOnline);
    window.removeEventListener('offline', this.handleOffline);

    this.state = {
      initialized: false,
      enabled: false,
      online: navigator.onLine,
      syncing: false,
      pendingSync: new Map(),
      lastSyncTime: null,
      syncTimerId: null,
      syncPromise: null,
      debounceTimer: null,
      activeRequests: 0,
      operationsProcessed: 0,
      syncQueue: [],
      failedOperations: []
    };
  }
};

if (window.Now?.registerManager) {
  Now.registerManager('sync', SyncManager);
}

window.SyncManager = SyncManager;
