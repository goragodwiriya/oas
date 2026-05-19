/**
 * StorageManager
 * A comprehensive class for managing data storage in browser
 * with support for IndexedDB, LocalStorage, SessionStorage, and memory storage
 */
const StorageManager = {
  config: {
    defaultDB: 'now_app_storage',
    defaultVersion: 1,
    maxRetries: 3,
    retryDelay: 300,
    autoInit: true,
    defaultStoreConfig: {
      keyPath: 'id',
      autoIncrement: true,
      indexes: []
    },
    storeSizeWarningThreshold: 50 * 1024 * 1024, // 50MB
    cacheLookups: true,
    cacheMaxAge: 60 * 1000, // 1 minute
    defaultAdapter: 'indexeddb',
    statistics: {
      enabled: true,
      trackOperations: true,
      trackTiming: true
    },
    stores: {}
  },

  state: {
    initialized: false,
    databases: new Map(),
    databaseSchemas: new Map(),
    cache: new Map(),
    statistics: {
      operations: {
        reads: 0,
        writes: 0,
        updates: 0,
        deletes: 0,
        queries: 0
      },
      timing: {
        totalTime: 0,
        operationCount: 0
      },
      errors: 0,
      storeSize: {}
    },
    lastError: null
  },

  /**
   * Initializes the StorageManager
   * @param {Object} options - Configuration options
   * @returns {Promise<StorageManager>} - Returns the StorageManager instance
   */
  async init(options = {}) {
    try {
      this.config = {...this.config, ...options};

      if (!this.isSupported()) {
        throw new Error('IndexedDB is not supported in this browser');
      }

      this.state.initialized = true;

      if (this.config.stores && Object.keys(this.config.stores).length > 0) {
        const dbConfig = {
          name: this.config.defaultDB,
          version: this.config.defaultVersion,
          stores: this.config.stores
        };
        await this.createDatabase(dbConfig);
      }

      EventManager.emit('storage:ready', {manager: 'StorageManager'});

      return this;
    } catch (error) {
      this.state.lastError = error;
      this.state.statistics.errors++;

      if (window.ErrorManager) {
        ErrorManager.handle(error, {
          context: 'StorageManager.init',
          type: 'storage:error'
        });
      }

      throw error;
    }
  },

  /**
   * Checks if IndexedDB is supported by the browser
   * @returns {boolean} - True if supported
   */
  isSupported() {
    return !!window.indexedDB;
  },

  /**
   * Creates or opens a database
   * @param {Object} dbConfig - Database configuration
   * @param {string} dbConfig.name - Database name
   * @param {number} dbConfig.version - Database version
   * @param {Object} dbConfig.stores - Object store configurations
   * @returns {Promise<IDBDatabase>} - Returns the database instance
   */
  async createDatabase(dbConfig) {
    const {name, version, stores} = dbConfig;

    return new Promise((resolve, reject) => {
      if (this.state.databases.has(name)) {
        const db = this.state.databases.get(name);
        const currentVersion = db.version;

        if (version > currentVersion) {
          db.close();
          this.state.databases.delete(name);
        } else {
          resolve(db);
          return;
        }
      }

      this.state.databaseSchemas.set(name, {stores});

      const request = indexedDB.open(name, version);

      request.onerror = (event) => {
        const error = new Error(`Failed to open database ${name}: ${event.target.error}`);
        this.state.lastError = error;
        this.state.statistics.errors++;
        reject(error);
      };

      request.onsuccess = (event) => {
        const db = event.target.result;
        this.state.databases.set(name, db);

        db.onerror = (event) => {
          this.state.statistics.errors++;
        };

        resolve(db);
      };

      request.onupgradeneeded = (event) => {
        const db = event.target.result;
        const oldVersion = event.oldVersion;
        const newVersion = event.newVersion;

        for (const [storeName, storeConfig] of Object.entries(stores)) {
          try {
            if (!db.objectStoreNames.contains(storeName)) {
              const config = {...this.config.defaultStoreConfig, ...storeConfig};
              const store = db.createObjectStore(storeName, {
                keyPath: config.keyPath,
                autoIncrement: config.autoIncrement
              });

              if (config.indexes && Array.isArray(config.indexes)) {
                config.indexes.forEach(indexConfig => {
                  const {name, keyPath, options} = indexConfig;
                  store.createIndex(name, keyPath, options || {unique: false});
                });
              }
            } else if (storeConfig.update) {
              const store = event.target.transaction.objectStore(storeName);
              if (storeConfig.indexes && Array.isArray(storeConfig.indexes)) {
                const existingIndexNames = Array.from(store.indexNames);
                storeConfig.indexes.forEach(indexConfig => {
                  const {name, keyPath, options} = indexConfig;
                  if (!existingIndexNames.includes(name)) {
                    store.createIndex(name, keyPath, options || {unique: false});
                  }
                });
              }
            }
          } catch (error) {
            console.error(`StorageManager: Failed to create object store '${storeName}':`, error);
            this.state.statistics.errors++;
          }
        }
      };
    });
  },

  /**
   * Adds data to an object store
   * @param {string} storeName - Object store name
   * @param {Object|Array} data - Data to add (single or array)
   * @param {string} [dbName=this.config.defaultDB] - Database name
   * @returns {Promise<any>} - Returns the added ID or array of IDs
   */
  async add(storeName, data, dbName = this.config.defaultDB) {
    const startTime = this.config.statistics.trackTiming ? performance.now() : 0;
    let retryCount = 0;

    const executeOperation = async () => {
      try {
        const db = await this.getDatabase(dbName);

        if (!db || db.readyState === 'closed') {
          throw new Error(`Database ${dbName} is closed or invalid`);
        }

        if (!db.objectStoreNames.contains(storeName)) {
          throw new Error(`Object store ${storeName} does not exist in database ${dbName}`);
        }

        return new Promise((resolve, reject) => {
          try {
            const transaction = db.transaction(storeName, 'readwrite');
            const store = transaction.objectStore(storeName);
            let results = [];
            const isArray = Array.isArray(data);

            transaction.onerror = (event) => {
              reject(new Error(`Failed to add data to ${storeName}: ${event.target.error}`));
            };

            transaction.oncomplete = () => {
              this.invalidateStoreCache(dbName, storeName);
              if (this.config.statistics.trackOperations) {
                this.state.statistics.operations.writes += isArray ? data.length : 1;
              }
              resolve(isArray ? results : results[0]);
            };

            if (isArray) {
              let counter = 0;
              const addNext = () => {
                if (counter < data.length) {
                  try {
                    const request = store.add(data[counter]);
                    request.onsuccess = (event) => {
                      results.push(event.target.result);
                      counter++;
                      addNext();
                    };
                    request.onerror = (event) => {
                      transaction.abort();
                      reject(new Error(`Failed to add item ${counter}: ${event.target.error}`));
                    };
                  } catch (innerError) {
                    transaction.abort();
                    reject(new Error(`Error in add operation: ${innerError.message}`));
                  }
                }
              };
              addNext();
            } else {
              const request = store.add(data);
              request.onsuccess = (event) => {
                results.push(event.target.result);
              };
            }
          } catch (transactionError) {
            reject(new Error(`Transaction error: ${transactionError.message}`));
          }
        });
      } catch (error) {
        if (retryCount < this.config.maxRetries) {
          retryCount++;
          await new Promise(r => setTimeout(r, this.config.retryDelay));
          return executeOperation();
        }
        throw error;
      }
    };

    try {
      const result = await executeOperation();
      if (this.config.statistics.trackTiming) {
        const endTime = performance.now();
        this.state.statistics.timing.totalTime += (endTime - startTime);
        this.state.statistics.timing.operationCount++;
      }
      await this.updateStoreStats(dbName, storeName);
      return result;
    } catch (error) {
      this.state.lastError = error;
      this.state.statistics.errors++;
      throw error;
    }
  },

  /**
   * Updates data in an object store
   * @param {string} storeName - Object store name
   * @param {Object|Array} data - Data to update (single or array)
   * @param {string} [dbName=this.config.defaultDB] - Database name
   * @returns {Promise<boolean>} - Returns true if successful
   */
  async update(storeName, data, dbName = this.config.defaultDB) {
    const startTime = this.config.statistics.trackTiming ? performance.now() : 0;
    let retryCount = 0;

    const executeOperation = async () => {
      try {
        const db = await this.getDatabase(dbName);

        return new Promise((resolve, reject) => {
          const transaction = db.transaction(storeName, 'readwrite');
          const store = transaction.objectStore(storeName);
          let updates = 0;
          const isArray = Array.isArray(data);
          const items = isArray ? data : [data];

          transaction.onerror = (event) => {
            reject(new Error(`Failed to update data in ${storeName}: ${event.target.error}`));
          };

          transaction.oncomplete = () => {
            this.invalidateStoreCache(dbName, storeName);
            if (this.config.statistics.trackOperations) {
              this.state.statistics.operations.updates += updates;
            }
            resolve(true);
          };

          let counter = 0;
          const updateNext = () => {
            if (counter < items.length) {
              const item = items[counter];
              const request = store.put(item);
              request.onsuccess = () => {
                updates++;
                counter++;
                updateNext();
              };
              request.onerror = (event) => {
                counter++;
                updateNext();
              };
            }
          };
          updateNext();
        });
      } catch (error) {
        if (retryCount < this.config.maxRetries) {
          retryCount++;
          await new Promise(r => setTimeout(r, this.config.retryDelay));
          return executeOperation();
        }
        throw error;
      }
    };

    try {
      const result = await executeOperation();
      if (this.config.statistics.trackTiming) {
        const endTime = performance.now();
        this.state.statistics.timing.totalTime += (endTime - startTime);
        this.state.statistics.timing.operationCount++;
      }
      return result;
    } catch (error) {
      this.state.lastError = error;
      this.state.statistics.errors++;
      throw error;
    }
  },

  /**
   * Retrieves data by ID
   * @param {string} storeName - Object store name
   * @param {any} id - ID to retrieve
   * @param {string} [dbName=this.config.defaultDB] - Database name
   * @returns {Promise<any>} - Returns the data or null if not found
   */
  async getById(storeName, id, dbName = this.config.defaultDB) {
    const startTime = this.config.statistics.trackTiming ? performance.now() : 0;
    const cacheKey = `${dbName}:${storeName}:${id}`;

    if (this.config.cacheLookups && this.state.cache.has(cacheKey)) {
      const cached = this.state.cache.get(cacheKey);
      if (Date.now() - cached.timestamp < this.config.cacheMaxAge) {
        return cached.data;
      } else {
        this.state.cache.delete(cacheKey);
      }
    }

    let retryCount = 0;
    const executeOperation = async () => {
      try {
        const db = await this.getDatabase(dbName);

        if (!db.objectStoreNames.contains(storeName)) {
          return null;
        }

        return new Promise((resolve, reject) => {
          const transaction = db.transaction(storeName, 'readonly');
          const store = transaction.objectStore(storeName);
          const request = store.get(id);

          request.onerror = (event) => {
            reject(new Error(`Failed to retrieve ID ${id} from ${storeName}: ${event.target.error}`));
          };

          request.onsuccess = (event) => {
            const result = event.target.result || null;
            if (this.config.cacheLookups && result) {
              this.state.cache.set(cacheKey, {
                data: result,
                timestamp: Date.now()
              });
            }
            if (this.config.statistics.trackOperations) {
              this.state.statistics.operations.reads++;
            }
            resolve(result);
          };
        });
      } catch (error) {
        if (retryCount < this.config.maxRetries) {
          retryCount++;
          await new Promise(r => setTimeout(r, this.config.retryDelay));
          return executeOperation();
        }
        throw error;
      }
    };

    try {
      const result = await executeOperation();
      if (this.config.statistics.trackTiming) {
        const endTime = performance.now();
        this.state.statistics.timing.totalTime += (endTime - startTime);
        this.state.statistics.timing.operationCount++;
      }
      return result;
    } catch (error) {
      this.state.lastError = error;
      this.state.statistics.errors++;
      throw error;
    }
  },

  /**
   * Retrieves all data from an object store
   * @param {string} storeName - Object store name
   * @param {Object} [options] - Query options
   * @param {number} [options.limit] - Result limit
   * @param {number} [options.offset] - Offset to skip
   * @param {string} [options.index] - Index name to use
   * @param {string} [options.direction] - Sort direction ('next', 'prev', 'nextunique', 'prevunique')
   * @param {IDBKeyRange} [options.range] - Key range for filtering
   * @param {Function} [options.filter] - Filter function
   * @param {string} [dbName=this.config.defaultDB] - Database name
   * @returns {Promise<Array>} - Returns an array of all data
   */
  async getAll(storeName, options = {}, dbName = this.config.defaultDB) {
    const startTime = this.config.statistics.trackTiming ? performance.now() : 0;
    let retryCount = 0;

    const executeOperation = async () => {
      try {
        const db = await this.getDatabase(dbName);

        if (!db.objectStoreNames.contains(storeName)) {
          return [];
        }

        return new Promise((resolve, reject) => {
          const transaction = db.transaction(storeName, 'readonly');
          const store = transaction.objectStore(storeName);
          const source = options.index ? store.index(options.index) : store;
          const direction = options.direction || 'next';
          const results = [];
          let counter = 0;
          let skipped = 0;
          const offset = options.offset || 0;
          const limit = options.limit || Infinity;

          if (source.getAll && !options.filter && offset === 0) {
            const limitParam = limit === Infinity ? undefined : limit;
            const getAllRequest = source.getAll(options.range, limitParam);

            getAllRequest.onsuccess = (event) => {
              if (this.config.statistics.trackOperations) {
                this.state.statistics.operations.reads += event.target.result.length;
                this.state.statistics.operations.queries++;
              }
              resolve(event.target.result);
            };
            getAllRequest.onerror = (event) => {
              reject(new Error(`Failed to retrieve data from ${storeName}: ${event.target.error}`));
            };
            return;
          }

          const request = source.openCursor(options.range, direction);

          request.onerror = (event) => {
            reject(new Error(`Failed to retrieve data from ${storeName}: ${event.target.error}`));
          };

          request.onsuccess = (event) => {
            const cursor = event.target.result;

            if (cursor) {
              if (skipped < offset) {
                skipped++;
                cursor.continue();
                return;
              }

              const value = cursor.value;
              if (!options.filter || options.filter(value)) {
                results.push(value);
                counter++;
              }

              if (counter < limit) {
                cursor.continue();
              } else {
                if (this.config.statistics.trackOperations) {
                  this.state.statistics.operations.reads += counter;
                  this.state.statistics.operations.queries++;
                }
                resolve(results);
              }
            } else {
              if (this.config.statistics.trackOperations) {
                this.state.statistics.operations.reads += counter;
                this.state.statistics.operations.queries++;
              }
              resolve(results);
            }
          };
        });
      } catch (error) {
        if (retryCount < this.config.maxRetries) {
          retryCount++;
          await new Promise(r => setTimeout(r, this.config.retryDelay));
          return executeOperation();
        }
        throw error;
      }
    };

    try {
      const result = await executeOperation();
      if (this.config.statistics.trackTiming) {
        const endTime = performance.now();
        this.state.statistics.timing.totalTime += (endTime - startTime);
        this.state.statistics.timing.operationCount++;
      }
      return result;
    } catch (error) {
      this.state.lastError = error;
      this.state.statistics.errors++;
      throw error;
    }
  },

  /**
   * Queries data with specified conditions
   * @param {string} storeName - Object store name
   * @param {Object} query - Query conditions
   * @param {string} [query.index] - Index name to use
   * @param {any} [query.value] - Value to match (for equality)
   * @param {IDBKeyRange} [query.range] - Key range for filtering
   * @param {Object} [options] - Query options
   * @param {number} [options.limit] - Result limit
   * @param {string} [options.direction] - Sort direction
   * @param {string} [dbName=this.config.defaultDB] - Database name
   * @returns {Promise<Array>} - Returns an array of matching data
   */
  async query(storeName, query, options = {}, dbName = this.config.defaultDB) {
    const startTime = this.config.statistics.trackTiming ? performance.now() : 0;
    let retryCount = 0;

    const executeOperation = async () => {
      try {
        const db = await this.getDatabase(dbName);

        if (!db.objectStoreNames.contains(storeName)) {
          return [];
        }

        return new Promise((resolve, reject) => {
          const transaction = db.transaction(storeName, 'readonly');
          const store = transaction.objectStore(storeName);
          let source = store;
          let range = null;

          if (query.index) {
            if (!store.indexNames.contains(query.index)) {
              reject(new Error(`Index ${query.index} does not exist in ${storeName}`));
              return;
            }
            source = store.index(query.index);
          }

          if (query.range) {
            range = query.range;
          } else if (query.value !== undefined) {
            range = IDBKeyRange.only(query.value);
          }

          const direction = options.direction || 'next';
          const results = [];
          const limit = options.limit || Infinity;

          if (source.getAll && !options.filter && limit === Infinity) {
            const getAllRequest = source.getAll(range);
            getAllRequest.onsuccess = (event) => {
              const items = event.target.result;
              if (this.config.statistics.trackOperations) {
                this.state.statistics.operations.reads += items.length;
                this.state.statistics.operations.queries++;
              }
              resolve(items);
            };
            getAllRequest.onerror = (event) => {
              reject(new Error(`Failed to query data in ${storeName}: ${event.target.error}`));
            };
            return;
          }

          let count = 0;
          const cursorRequest = source.openCursor(range, direction);

          cursorRequest.onerror = (event) => {
            reject(new Error(`Failed to query data in ${storeName}: ${event.target.error}`));
          };

          cursorRequest.onsuccess = (event) => {
            const cursor = event.target.result;

            if (cursor && count < limit) {
              results.push(cursor.value);
              count++;
              cursor.continue();
            } else {
              if (this.config.statistics.trackOperations) {
                this.state.statistics.operations.reads += results.length;
                this.state.statistics.operations.queries++;
              }
              resolve(results);
            }
          };
        });
      } catch (error) {
        if (retryCount < this.config.maxRetries) {
          retryCount++;
          await new Promise(r => setTimeout(r, this.config.retryDelay));
          return executeOperation();
        }
        throw error;
      }
    };

    try {
      const result = await executeOperation();
      if (this.config.statistics.trackTiming) {
        const endTime = performance.now();
        this.state.statistics.timing.totalTime += (endTime - startTime);
        this.state.statistics.timing.operationCount++;
      }
      return result;
    } catch (error) {
      this.state.lastError = error;
      this.state.statistics.errors++;
      throw error;
    }
  },

  /**
   * Counts items in an object store based on conditions
   * @param {string} storeName - Object store name
   * @param {Object} [query] - Query conditions
   * @param {string} [query.index] - Index name to use
   * @param {any} [query.value] - Value to match (for equality)
   * @param {IDBKeyRange} [query.range] - Key range for counting
   * @param {string} [dbName=this.config.defaultDB] - Database name
   * @returns {Promise<number>} - Returns the item count
   */
  async count(storeName, query = {}, dbName = this.config.defaultDB) {
    const startTime = this.config.statistics.trackTiming ? performance.now() : 0;
    let retryCount = 0;

    const executeOperation = async () => {
      try {
        const db = await this.getDatabase(dbName);

        if (!db.objectStoreNames.contains(storeName)) {
          return 0;
        }

        return new Promise((resolve, reject) => {
          const transaction = db.transaction(storeName, 'readonly');
          const store = transaction.objectStore(storeName);
          let source = store;
          let range = null;

          if (query.index) {
            if (!store.indexNames.contains(query.index)) {
              reject(new Error(`Index ${query.index} does not exist in ${storeName}`));
              return;
            }
            source = store.index(query.index);
          }

          if (query.range) {
            range = query.range;
          } else if (query.value !== undefined) {
            range = IDBKeyRange.only(query.value);
          }

          const countRequest = source.count(range);

          countRequest.onerror = (event) => {
            reject(new Error(`Failed to count data in ${storeName}: ${event.target.error}`));
          };

          countRequest.onsuccess = (event) => {
            const count = event.target.result;
            if (this.config.statistics.trackOperations) {
              this.state.statistics.operations.queries++;
            }
            resolve(count);
          };
        });
      } catch (error) {
        if (retryCount < this.config.maxRetries) {
          retryCount++;
          await new Promise(r => setTimeout(r, this.config.retryDelay));
          return executeOperation();
        }
        throw error;
      }
    };

    try {
      const result = await executeOperation();
      if (this.config.statistics.trackTiming) {
        const endTime = performance.now();
        this.state.statistics.timing.totalTime += (endTime - startTime);
        this.state.statistics.timing.operationCount++;
      }
      return result;
    } catch (error) {
      this.state.lastError = error;
      this.state.statistics.errors++;
      throw error;
    }
  },

  /**
   * Deletes data by ID
   * @param {string} storeName - Object store name
   * @param {any} id - ID to delete
   * @param {string} [dbName=this.config.defaultDB] - Database name
   * @returns {Promise<boolean>} - Returns true if successful
   */
  async delete(storeName, id, dbName = this.config.defaultDB) {
    const startTime = this.config.statistics.trackTiming ? performance.now() : 0;
    let retryCount = 0;

    const executeOperation = async () => {
      try {
        const db = await this.getDatabase(dbName);

        return new Promise((resolve, reject) => {
          const transaction = db.transaction(storeName, 'readwrite');
          const store = transaction.objectStore(storeName);
          const request = store.delete(id);

          request.onerror = (event) => {
            reject(new Error(`Failed to delete ID ${id} from ${storeName}: ${event.target.error}`));
          };

          request.onsuccess = () => {
            this.invalidateItemCache(dbName, storeName, id);
            if (this.config.statistics.trackOperations) {
              this.state.statistics.operations.deletes++;
            }
            resolve(true);
          };
        });
      } catch (error) {
        if (retryCount < this.config.maxRetries) {
          retryCount++;
          await new Promise(r => setTimeout(r, this.config.retryDelay));
          return executeOperation();
        }
        throw error;
      }
    };

    try {
      const result = await executeOperation();
      if (this.config.statistics.trackTiming) {
        const endTime = performance.now();
        this.state.statistics.timing.totalTime += (endTime - startTime);
        this.state.statistics.timing.operationCount++;
      }
      await this.updateStoreStats(dbName, storeName);
      return result;
    } catch (error) {
      this.state.lastError = error;
      this.state.statistics.errors++;
      throw error;
    }
  },

  /**
   * Deletes data based on conditions
   * @param {string} storeName - Object store name
   * @param {Object} query - Deletion conditions
   * @param {string} [query.index] - Index name to use
   * @param {any} [query.value] - Value to match (for equality)
   * @param {IDBKeyRange} [query.range] - Key range for deletion
   * @param {string} [dbName=this.config.defaultDB] - Database name
   * @returns {Promise<number>} - Returns the number of deleted items
   */
  async deleteByQuery(storeName, query, dbName = this.config.defaultDB) {
    const startTime = this.config.statistics.trackTiming ? performance.now() : 0;
    let retryCount = 0;

    const executeOperation = async () => {
      try {
        const db = await this.getDatabase(dbName);
        const items = await this.query(storeName, query, {}, dbName);

        if (items.length === 0) {
          return 0;
        }

        return new Promise((resolve, reject) => {
          const transaction = db.transaction(storeName, 'readwrite');
          const store = transaction.objectStore(storeName);
          let count = 0;

          transaction.onerror = (event) => {
            reject(new Error(`Failed to delete data in ${storeName}: ${event.target.error}`));
          };

          transaction.oncomplete = () => {
            if (this.config.statistics.trackOperations) {
              this.state.statistics.operations.deletes += count;
            }
            this.invalidateStoreCache(dbName, storeName);
            resolve(count);
          };

          items.forEach(item => {
            const keyPath = store.keyPath;
            const id = item[keyPath];
            const request = store.delete(id);
            request.onsuccess = () => {
              count++;
            };
          });
        });
      } catch (error) {
        if (retryCount < this.config.maxRetries) {
          retryCount++;
          await new Promise(r => setTimeout(r, this.config.retryDelay));
          return executeOperation();
        }
        throw error;
      }
    };

    try {
      const result = await executeOperation();
      if (this.config.statistics.trackTiming) {
        const endTime = performance.now();
        this.state.statistics.timing.totalTime += (endTime - startTime);
        this.state.statistics.timing.operationCount++;
      }
      await this.updateStoreStats(dbName, storeName);
      return result;
    } catch (error) {
      this.state.lastError = error;
      this.state.statistics.errors++;
      throw error;
    }
  },

  /**
   * Clears all data in an object store
   * @param {string} storeName - Object store name
   * @param {string} [dbName=this.config.defaultDB] - Database name
   * @returns {Promise<boolean>} - Returns true if successful
   */
  async clear(storeName, dbName = this.config.defaultDB) {
    const startTime = this.config.statistics.trackTiming ? performance.now() : 0;
    let retryCount = 0;

    const executeOperation = async () => {
      try {
        const db = await this.getDatabase(dbName);

        return new Promise((resolve, reject) => {
          const transaction = db.transaction(storeName, 'readwrite');
          const store = transaction.objectStore(storeName);
          const request = store.clear();

          request.onerror = (event) => {
            reject(new Error(`Failed to clear data in ${storeName}: ${event.target.error}`));
          };

          request.onsuccess = () => {
            this.invalidateStoreCache(dbName, storeName);
            resolve(true);
          };
        });
      } catch (error) {
        if (retryCount < this.config.maxRetries) {
          retryCount++;
          await new Promise(r => setTimeout(r, this.config.retryDelay));
          return executeOperation();
        }
        throw error;
      }
    };

    try {
      const result = await executeOperation();
      if (this.config.statistics.trackTiming) {
        const endTime = performance.now();
        this.state.statistics.timing.totalTime += (endTime - startTime);
        this.state.statistics.timing.operationCount++;
      }
      this.state.statistics.storeSize[`${dbName}.${storeName}`] = 0;
      EventManager.emit('storage:cleared', {dbName, storeName});
      return result;
    } catch (error) {
      this.state.lastError = error;
      this.state.statistics.errors++;
      throw error;
    }
  },

  /**
   * Checks if an item with the specified ID exists
   * @param {string} storeName - Object store name
   * @param {any} id - ID to check
   * @param {string} [dbName=this.config.defaultDB] - Database name
   * @returns {Promise<boolean>} - Returns true if the item exists
   */
  async exists(storeName, id, dbName = this.config.defaultDB) {
    const startTime = this.config.statistics.trackTiming ? performance.now() : 0;
    let retryCount = 0;

    const executeOperation = async () => {
      try {
        const db = await this.getDatabase(dbName);

        if (!db.objectStoreNames.contains(storeName)) {
          return false;
        }

        return new Promise((resolve, reject) => {
          const transaction = db.transaction(storeName, 'readonly');
          const store = transaction.objectStore(storeName);
          const request = store.count(id);

          request.onerror = (event) => {
            reject(new Error(`Failed to check ID ${id} in ${storeName}: ${event.target.error}`));
          };

          request.onsuccess = (event) => {
            resolve(event.target.result > 0);
          };
        });
      } catch (error) {
        if (retryCount < this.config.maxRetries) {
          retryCount++;
          await new Promise(r => setTimeout(r, this.config.retryDelay));
          return executeOperation();
        }
        throw error;
      }
    };

    try {
      const result = await executeOperation();
      if (this.config.statistics.trackTiming) {
        const endTime = performance.now();
        this.state.statistics.timing.totalTime += (endTime - startTime);
        this.state.statistics.timing.operationCount++;
      }
      return result;
    } catch (error) {
      this.state.lastError = error;
      this.state.statistics.errors++;
      throw error;
    }
  },

  /**
   * Creates a key range for querying
   * @param {string} type - Range type ('only', 'bound', 'upperBound', 'lowerBound')
   * @param {any} value - Value for 'only', 'upperBound', or 'lowerBound'
   * @param {any} [value2] - Second value for 'bound'
   * @param {boolean} [lowerOpen=false] - Lower bound open/closed (for 'bound')
   * @param {boolean} [upperOpen=false] - Upper bound open/closed (for 'bound')
   * @returns {IDBKeyRange} - Returns the key range
   */
  createKeyRange(type, value, value2, lowerOpen = false, upperOpen = false) {
    switch (type.toLowerCase()) {
      case 'only':
        return IDBKeyRange.only(value);
      case 'bound':
        return IDBKeyRange.bound(value, value2, lowerOpen, upperOpen);
      case 'upperbound':
        return IDBKeyRange.upperBound(value, lowerOpen);
      case 'lowerbound':
        return IDBKeyRange.lowerBound(value, lowerOpen);
      default:
        throw new Error(`Invalid range type: ${type}`);
    }
  },

  /**
   * Opens or creates a database if it doesn't exist
   * @param {string} dbName - Database name
   * @returns {Promise<IDBDatabase>} - Returns the database instance
   * @private
   */
  async getDatabase(dbName) {
    if (this.state.databases.has(dbName)) {
      const db = this.state.databases.get(dbName);
      if (db && db.readyState !== 'closed') {
        return db;
      } else {
        this.state.databases.delete(dbName);
      }
    }

    if (!this.state.databaseSchemas.has(dbName)) {
      if (dbName === this.config.defaultDB && this.config.stores) {
        try {
          await this.createDatabase({
            name: dbName,
            version: this.config.defaultVersion,
            stores: this.config.stores
          });

          const db = this.state.databases.get(dbName);
          if (!db) {
            throw new Error(`Failed to create database ${dbName}`);
          }
          return db;
        } catch (error) {
          throw error;
        }
      } else {
        throw new Error(`Database ${dbName} not found and no schema available for creation`);
      }
    }

    try {
      const schema = this.state.databaseSchemas.get(dbName);
      await this.createDatabase({
        name: dbName,
        version: schema.version || 1,
        stores: schema.stores
      });

      const db = this.state.databases.get(dbName);
      if (!db) {
        throw new Error(`Failed to create database ${dbName} from schema`);
      }
      return db;
    } catch (error) {
      throw error;
    }
  },

  /**
   * Invalidates cache for an object store
   * @param {string} dbName - Database name
   * @param {string} storeName - Object store name
   * @private
   */
  invalidateStoreCache(dbName, storeName) {
    if (!this.config.cacheLookups) return;

    for (const key of this.state.cache.keys()) {
      if (key.startsWith(`${dbName}:${storeName}:`)) {
        this.state.cache.delete(key);
      }
    }
  },

  /**
   * Invalidates cache for a specific item
   * @param {string} dbName - Database name
   * @param {string} storeName - Object store name
   * @param {any} id - Item ID
   * @private
   */
  invalidateItemCache(dbName, storeName, id) {
    if (!this.config.cacheLookups) return;

    const cacheKey = `${dbName}:${storeName}:${id}`;
    this.state.cache.delete(cacheKey);
  },

  /**
   * Updates storage size statistics for an object store
   * @param {string} dbName - Database name
   * @param {string} storeName - Object store name
   * @private
   */
  async updateStoreStats(dbName, storeName) {
    if (!this.config.statistics.enabled) return;

    try {
      const count = await this.count(storeName, {}, dbName);
      const sampleSize = Math.min(100, count);
      if (sampleSize <= 0) {
        this.state.statistics.storeSize[`${dbName}.${storeName}`] = 0;
        return;
      }

      const items = await this.getAll(storeName, {limit: sampleSize}, dbName);
      let totalSize = 0;
      items.forEach(item => {
        const json = JSON.stringify(item);
        totalSize += json.length * 2;
      });

      const averageSize = totalSize / items.length;
      const estimatedSize = averageSize * count;

      this.state.statistics.storeSize[`${dbName}.${storeName}`] = estimatedSize;
    } catch (error) {
    }
  },

  /**
   * Formats file size into a human-readable string
   * @param {number} bytes - Size in bytes
   * @returns {string} - Formatted size
   * @private
   */
  formatSize(bytes) {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let size = bytes;
    let unitIndex = 0;

    while (size >= 1024 && unitIndex < units.length - 1) {
      size /= 1024;
      unitIndex++;
    }

    return `${size.toFixed(2)} ${units[unitIndex]}`;
  },

  /**
   * Calculates total storage usage
   * @returns {Promise<Object>} - Storage usage information
   */
  async getStorageUsage() {
    if (!this.config.statistics.enabled) {
      return {totalSize: 0, stores: {}};
    }

    let totalSize = 0;
    const stores = {};

    for (const [key, size] of Object.entries(this.state.statistics.storeSize)) {
      const [dbName, storeName] = key.split('.');
      if (!stores[dbName]) {
        stores[dbName] = {};
      }
      stores[dbName][storeName] = {
        size: size,
        formatted: this.formatSize(size)
      };
      totalSize += size;
    }

    return {
      totalSize: totalSize,
      totalFormatted: this.formatSize(totalSize),
      stores: stores
    };
  },

  /**
   * Retrieves performance statistics
   * @returns {Object} - Operation statistics
   */
  getStatistics() {
    if (!this.config.statistics.enabled) {
      return null;
    }

    let averageOperationTime = 0;
    if (this.state.statistics.timing.operationCount > 0) {
      averageOperationTime = this.state.statistics.timing.totalTime / this.state.statistics.timing.operationCount;
    }

    const databases = {};
    for (const [dbName, schema] of this.state.databaseSchemas.entries()) {
      const storeNames = Object.keys(schema.stores || {});
      databases[dbName] = {
        version: this.state.databases.get(dbName)?.version || 0,
        stores: storeNames
      };
    }

    return {
      operations: this.state.statistics.operations,
      timing: {
        totalTime: this.state.statistics.timing.totalTime.toFixed(2) + ' ms',
        operationCount: this.state.statistics.timing.operationCount,
        averageTime: averageOperationTime.toFixed(2) + ' ms'
      },
      databases: databases,
      cacheSize: this.state.cache.size,
      errors: this.state.statistics.errors,
      lastError: this.state.lastError ? {
        message: this.state.lastError.message,
        timestamp: new Date().toISOString()
      } : null
    };
  },

  /**
   * Deletes a database
   * @param {string} dbName - Database name to delete
   * @returns {Promise<boolean>} - Returns true if successful
   */
  async deleteDatabase(dbName) {
    try {
      if (this.state.databases.has(dbName)) {
        const db = this.state.databases.get(dbName);
        db.close();
        this.state.databases.delete(dbName);
      }

      for (const key of this.state.cache.keys()) {
        if (key.startsWith(`${dbName}:`)) {
          this.state.cache.delete(key);
        }
      }

      this.state.databaseSchemas.delete(dbName);

      return new Promise((resolve, reject) => {
        const request = indexedDB.deleteDatabase(dbName);

        request.onerror = (event) => {
          const error = new Error(`Failed to delete database ${dbName}: ${event.target.error}`);
          this.state.lastError = error;
          this.state.statistics.errors++;
          reject(error);
        };

        request.onsuccess = () => {
          for (const key in this.state.statistics.storeSize) {
            if (key.startsWith(`${dbName}.`)) {
              delete this.state.statistics.storeSize[key];
            }
          }
          EventManager.emit('storage:databaseDeleted', {dbName});
          resolve(true);
        };
      });
    } catch (error) {
      this.state.lastError = error;
      this.state.statistics.errors++;
      throw error;
    }
  },

  /**
   * Processes data in batches (add/update multiple items)
   * @param {string} storeName - Object store name
   * @param {Array} items - Array of items to process
   * @param {string} [operation='update'] - Operation type ('add', 'update', 'upsert')
   * @param {string} [dbName=this.config.defaultDB] - Database name
   * @returns {Promise<Array>} - Returns array of IDs (for 'add') or success count (for 'update', 'upsert')
   */
  async batch(storeName, items, operation = 'update', dbName = this.config.defaultDB) {
    const startTime = this.config.statistics.trackTiming ? performance.now() : 0;
    let retryCount = 0;

    if (!Array.isArray(items) || items.length === 0) {
      return [];
    }

    const executeOperation = async () => {
      try {
        const db = await this.getDatabase(dbName);

        return new Promise((resolve, reject) => {
          const transaction = db.transaction(storeName, 'readwrite');
          const store = transaction.objectStore(storeName);
          const results = [];
          let completed = 0;

          transaction.onerror = (event) => {
            reject(new Error(`Batch operation failed in ${storeName}: ${event.target.error}`));
          };

          transaction.oncomplete = () => {
            this.invalidateStoreCache(dbName, storeName);
            if (this.config.statistics.trackOperations) {
              if (operation === 'add') {
                this.state.statistics.operations.writes += completed;
              } else {
                this.state.statistics.operations.updates += completed;
              }
            }
            resolve(operation === 'add' ? results : completed);
          };

          items.forEach(item => {
            let request;

            switch (operation) {
              case 'add':
                request = store.add(item);
                request.onsuccess = (event) => {
                  results.push(event.target.result);
                  completed++;
                };
                break;

              case 'update':
                request = store.put(item);
                request.onsuccess = () => {
                  completed++;
                };
                break;

              case 'upsert':
                const keyPath = store.keyPath;
                const id = item[keyPath];
                if (id !== undefined) {
                  request = store.put(item);
                  request.onsuccess = () => {
                    completed++;
                  };
                } else {
                  request = store.add(item);
                  request.onsuccess = (event) => {
                    results.push(event.target.result);
                    completed++;
                  };
                }
                break;

              default:
                throw new Error(`Invalid operation type: ${operation}`);
            }

            request.onerror = (event) => {
              event.stopPropagation();
            };
          });
        });
      } catch (error) {
        if (retryCount < this.config.maxRetries) {
          retryCount++;
          await new Promise(r => setTimeout(r, this.config.retryDelay));
          return executeOperation();
        }
        throw error;
      }
    };

    try {
      const result = await executeOperation();
      if (this.config.statistics.trackTiming) {
        const endTime = performance.now();
        this.state.statistics.timing.totalTime += (endTime - startTime);
        this.state.statistics.timing.operationCount++;
      }
      await this.updateStoreStats(dbName, storeName);
      return result;
    } catch (error) {
      this.state.lastError = error;
      this.state.statistics.errors++;
      throw error;
    }
  },

  /**
   * Convenience function for creating transactions
   * @param {string} storeName - Object store name
   * @param {Function} callback - Function to execute with the transaction
   * @param {string} [mode='readonly'] - Transaction mode ('readonly' or 'readwrite')
   * @param {string} [dbName=this.config.defaultDB] - Database name
   * @returns {Promise<any>} - Returns the callback result
   */
  async transaction(storeName, callback, mode = 'readonly', dbName = this.config.defaultDB) {
    try {
      const db = await this.getDatabase(dbName);

      return new Promise((resolve, reject) => {
        const transaction = db.transaction(storeName, mode);
        const store = transaction.objectStore(storeName);

        transaction.onerror = (event) => {
          reject(new Error(`Transaction failed in ${storeName}: ${event.target.error}`));
        };

        transaction.oncomplete = () => {
          resolve(result);
        };

        let result;
        try {
          result = callback(store, transaction);
        } catch (error) {
          transaction.abort();
          reject(error);
        }
      });
    } catch (error) {
      this.state.lastError = error;
      this.state.statistics.errors++;
      throw error;
    }
  },

  /**
   * Storage adapters for different storage types
   */
  adapters: {
    indexeddb: {
      async get(key, storeName, dbName) {
        return StorageManager.getById(storeName, key, dbName || StorageManager.config.defaultDB);
      },
      async set(key, value, storeName, dbName) {
        const db = dbName || StorageManager.config.defaultDB;
        const data = typeof value === 'object' ? value : {id: key, value};
        if (await StorageManager.exists(storeName, key, db)) {
          if (typeof value === 'object' && !value.id) {
            value.id = key;
          }
          return StorageManager.update(storeName, data, db);
        } else {
          return StorageManager.add(storeName, data, db);
        }
      },
      async remove(key, storeName, dbName) {
        return StorageManager.delete(storeName, key, dbName || StorageManager.config.defaultDB);
      },
      async clear(storeName, dbName) {
        return StorageManager.clear(storeName, dbName || StorageManager.config.defaultDB);
      },
      async getAll(storeName, dbName) {
        return StorageManager.getAll(storeName, {}, dbName || StorageManager.config.defaultDB);
      }
    },

    localStorage: {
      get(key) {
        try {
          const value = localStorage.getItem(key);
          return value ? JSON.parse(value) : null;
        } catch (e) {
          return localStorage.getItem(key);
        }
      },
      set(key, value) {
        localStorage.setItem(key, typeof value === 'object' ? JSON.stringify(value) : value);
        return true;
      },
      remove(key) {
        localStorage.removeItem(key);
        return true;
      },
      clear() {
        localStorage.clear();
        return true;
      },
      getAll() {
        const items = {};
        for (let i = 0; i < localStorage.length; i++) {
          const key = localStorage.key(i);
          items[key] = this.get(key);
        }
        return items;
      }
    },

    sessionStorage: {
      get(key) {
        try {
          const value = sessionStorage.getItem(key);
          return value ? JSON.parse(value) : null;
        } catch (e) {
          return sessionStorage.getItem(key);
        }
      },
      set(key, value) {
        sessionStorage.setItem(key, typeof value === 'object' ? JSON.stringify(value) : value);
        return true;
      },
      remove(key) {
        sessionStorage.removeItem(key);
        return true;
      },
      clear() {
        sessionStorage.clear();
        return true;
      },
      getAll() {
        const items = {};
        for (let i = 0; i < sessionStorage.length; i++) {
          const key = sessionStorage.key(i);
          items[key] = this.get(key);
        }
        return items;
      }
    },

    memory: {
      _data: new Map(),
      get(key) {
        return this._data.get(key);
      },
      set(key, value) {
        this._data.set(key, value);
        return true;
      },
      remove(key) {
        return this._data.delete(key);
      },
      clear() {
        this._data.clear();
        return true;
      },
      getAll() {
        return Object.fromEntries(this._data.entries());
      }
    }
  },

  /**
   * Get a specific storage adapter
   * @param {string} type - Storage type ('indexeddb', 'localStorage', 'sessionStorage', 'memory')
   * @returns {Object} - The storage adapter
   */
  store(type = null) {
    const adapterType = type || this.config.defaultAdapter;
    const adapter = this.adapters[adapterType.toLowerCase()];
    if (!adapter) {
      throw new Error(`Storage adapter '${adapterType}' not found`);
    }
    return adapter;
  },

  /**
   * LocalStorage shorthand
   */
  local: {
    get(key) {
      return StorageManager.adapters.localStorage.get(key);
    },
    set(key, value) {
      return StorageManager.adapters.localStorage.set(key, value);
    },
    remove(key) {
      return StorageManager.adapters.localStorage.remove(key);
    },
    clear() {
      return StorageManager.adapters.localStorage.clear();
    },
    getAll() {
      return StorageManager.adapters.localStorage.getAll();
    }
  },

  /**
   * SessionStorage shorthand
   */
  session: {
    get(key) {
      return StorageManager.adapters.sessionStorage.get(key);
    },
    set(key, value) {
      return StorageManager.adapters.sessionStorage.set(key, value);
    },
    remove(key) {
      return StorageManager.adapters.sessionStorage.remove(key);
    },
    clear() {
      return StorageManager.adapters.sessionStorage.clear();
    },
    getAll() {
      return StorageManager.adapters.sessionStorage.getAll();
    }
  },

  /**
   * Memory storage shorthand
   */
  memory: {
    get(key) {
      return StorageManager.adapters.memory.get(key);
    },
    set(key, value) {
      return StorageManager.adapters.memory.set(key, value);
    },
    remove(key) {
      return StorageManager.adapters.memory.remove(key);
    },
    clear() {
      return StorageManager.adapters.memory.clear();
    },
    getAll() {
      return StorageManager.adapters.memory.getAll();
    }
  },

  /**
   * Sync-enabled methods
   */
  async addWithSync(storeName, data, syncOptions = {}) {
    const syncManager = window.Now?.getManager('sync');
    const result = await this.add(storeName, data);

    if (syncManager && syncOptions.sync !== false) {
      const itemToSync = {...data};
      if (!itemToSync.id && typeof result !== 'undefined') {
        itemToSync.id = result;
      }

      await syncManager.addPendingOperation({
        storeName,
        method: 'add',
        data: itemToSync,
        priority: syncOptions.priority || 'medium'
      });
    }

    return result;
  },

  async updateWithSync(storeName, data, syncOptions = {}) {
    const syncManager = window.Now?.getManager('sync');
    await this.update(storeName, data);

    if (syncManager && syncOptions.sync !== false) {
      await syncManager.addPendingOperation({
        storeName,
        method: 'update',
        data,
        priority: syncOptions.priority || 'medium'
      });
    }

    return true;
  },

  async deleteWithSync(storeName, id, syncOptions = {}) {
    const syncManager = window.Now?.getManager('sync');
    const data = await this.getById(storeName, id);
    await this.delete(storeName, id);

    if (syncManager && syncOptions.sync !== false && data) {
      await syncManager.addPendingOperation({
        storeName,
        method: 'delete',
        data,
        priority: syncOptions.priority || 'medium'
      });
    }

    return true;
  },

  /**
   * Clears all cache
   */
  clearCache() {
    this.state.cache.clear();
  },

  /**
   * Closes all database connections
   */
  closeAllConnections() {
    for (const [name, db] of this.state.databases.entries()) {
      try {
        db.close();
      } catch (error) {
      }
    }
    this.state.databases.clear();
  },

  /**
   * Resets all state
   */
  reset() {
    this.closeAllConnections();
    this.clearCache();
    this.state = {
      initialized: false,
      databases: new Map(),
      databaseSchemas: new Map(),
      cache: new Map(),
      statistics: {
        operations: {
          reads: 0,
          writes: 0,
          updates: 0,
          deletes: 0,
          queries: 0
        },
        timing: {
          totalTime: 0,
          operationCount: 0
        },
        errors: 0,
        storeSize: {}
      },
      lastError: null
    };
  }
};

if (window.Now?.registerManager) {
  Now.registerManager('storage', StorageManager);
}

window.StorageManager = StorageManager;
