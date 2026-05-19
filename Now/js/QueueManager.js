/**
 * QueueManager
 * A versatile queue management system for handling tasks asynchronously
 * with priority, retry, and rate limiting
 *
 * Features:
 * - Multiple queue types (FIFO, priority, delayed)
 * - Task prioritization
 * - Concurrency control
 * - Retry mechanisms with backoff
 * - Rate limiting
 * - Persistence option (uses StorageManager)
 * - Worker pool management
 * - Enable/disable functionality
 */
const QueueManager = {
  config: {
    debug: false,
    enabled: false,
    defaultConcurrency: 5,
    defaultMaxRetries: 3,
    defaultRetryDelay: 1000,
    defaultBackoffStrategy: 'exponential', // fixed, linear, exponential
    defaultTimeout: 30000,
    persistence: {
      enabled: false,
      storeName: 'queued_tasks',
      syncInterval: 5000,
      maxPersistedTasks: 1000
    },
    rateLimiting: {
      enabled: false,
      limit: 10, // tasks per interval
      interval: 1000, // milliseconds
      fairness: true // distribute rate limits fairly across queues
    },
    workerPool: {
      enabled: true,
      size: navigator.hardwareConcurrency || 4,
      idleTimeout: 60000
    }
  },

  state: {
    initialized: false,
    enabled: false,
    queues: new Map(),
    workers: [],
    activeWorkers: 0,
    runningTasks: new Map(),
    taskCounter: 0,
    persistenceTimer: null,
    rateLimitCounter: 0,
    rateLimitResetTime: 0,
    pausedQueues: new Set(),
    statistics: {
      processed: 0,
      succeeded: 0,
      failed: 0,
      retried: 0,
      avgProcessingTime: 0
    }
  },

  /**
   * Initialize the QueueManager
   * @param {Object} options - Configuration options
   * @returns {Promise<QueueManager>} - Returns the QueueManager instance
   */
  async init(options = {}) {
    try {
      this.config = {...this.config, ...options};

      if (this.config.persistence.enabled) {
        this.storageManager = window.Now?.getManager('storage');

        if (this.storageManager) {
          if (!this.storageManager.state.initialized) {
            try {
              await this.storageManager.init();
            } catch (storageError) {
              console.error('Failed to initialize StorageManager:', storageError);
              console.warn('StorageManager initialization failed, persistence disabled');
              this.config.persistence.enabled = false;
            }
          }

          if (this.config.persistence.enabled) {
            try {
              await this.storageManager.createDatabase({
                name: this.storageManager.config.defaultDB,
                version: this.storageManager.config.defaultVersion,
                stores: {
                  [this.config.persistence.storeName]: {
                    keyPath: 'id',
                    autoIncrement: true,
                    indexes: [
                      {name: 'queueName', keyPath: 'queueName'},
                      {name: 'priority', keyPath: 'priority'},
                      {name: 'scheduledTime', keyPath: 'scheduledTime'}
                    ]
                  }
                }
              });

              await this.loadPersistedTasks();
            } catch (dbError) {
              console.error('Failed to create database for QueueManager:', dbError);
              console.warn('Queue persistence setup failed, persistence disabled');
              this.config.persistence.enabled = false;
            }
          }
        } else {
          console.warn('StorageManager not found, persistence disabled');
          this.config.persistence.enabled = false;
        }
      }

      if (this.config.workerPool.enabled) {
        this.initWorkerPool();
      }

      this.state.initialized = true;

      EventManager.emit('queue:ready', {manager: 'QueueManager'});

      return this;
    } catch (error) {
      console.error('QueueManager initialization failed:', error);
      if (window.ErrorManager) {
        ErrorManager.handle(error, {
          context: 'QueueManager.init',
          type: 'queue:error'
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

    if (this.config.persistence.enabled) {
      this.startPersistenceSync();
    }

    for (const queueName of this.state.queues.keys()) {
      this.processNextTasks(queueName);
    }

    EventManager.emit('queue:enabled');

    return this;
  },

  disable() {
    this.state.enabled = false;
    this.config.enabled = false;

    if (this.state.persistenceTimer) {
      clearInterval(this.state.persistenceTimer);
      this.state.persistenceTimer = null;
    }

    this.state.workers.forEach(worker => {
      worker.busy = false;
      worker.task = null;
    });

    EventManager.emit('queue:disabled');

    return this;
  },

  /**
   * Create a new queue
   * @param {string} name - Queue name
   * @param {Object} options - Queue options
   * @returns {Object} - Queue reference
   */
  createQueue(name, options = {}) {
    if (this.state.queues.has(name)) {
      if (this.config.debug) {
        console.warn(`Queue "${name}" already exists`);
      }
      return this.getQueue(name);
    }

    const queue = {
      name,
      tasks: [],
      options: {
        concurrency: this.config.defaultConcurrency,
        maxRetries: this.config.defaultMaxRetries,
        retryDelay: this.config.defaultRetryDelay,
        backoffStrategy: this.config.defaultBackoffStrategy,
        timeout: this.config.defaultTimeout,
        priority: false,
        fifo: true,
        paused: false,
        ...options
      },
      activeTasks: 0,
      isProcessing: false
    };

    this.state.queues.set(name, queue);

    EventManager.emit('queue:created', {name, options: queue.options});

    return this.getQueueInterface(name);
  },

  /**
   * Get a queue interface
   * @param {string} name - Queue name
   * @returns {Object} - Queue interface
   */
  getQueue(name) {
    if (!this.state.queues.has(name)) {
      throw new Error(`Queue "${name}" does not exist`);
    }

    return this.getQueueInterface(name);
  },

  /**
   * Create a queue interface with methods for specific queue
   * @param {string} name - Queue name
   * @returns {Object} - Queue interface
   * @private
   */
  getQueueInterface(name) {
    return {
      add: (task, options) => this.addTask(name, task, options),
      addBulk: (tasks, options) => this.addBulkTasks(name, tasks, options),
      pause: () => this.pauseQueue(name),
      resume: () => this.resumeQueue(name),
      clear: () => this.clearQueue(name),
      getStats: () => this.getQueueStats(name),
      getLength: () => this.getQueueLength(name),
      setPriority: (priority) => this.setQueuePriority(name, priority),
      setConcurrency: (concurrency) => this.setQueueConcurrency(name, concurrency),
      getName: () => name,
      isPaused: () => this.isQueuePaused(name),
      isEnabled: () => this.config.enabled && this.state.enabled
    };
  },

  /**
   * Add a task to a queue
   * @param {string} queueName - Queue name
   * @param {Function|Object} task - Task function or object
   * @param {Object} options - Task options
   * @returns {string} - Task ID
   */
  addTask(queueName, task, options = {}) {
    if (!this.config.enabled || !this.state.enabled) {
      throw new Error('QueueManager is disabled');
    }

    if (!this.state.initialized) {
      throw new Error('QueueManager is not initialized');
    }

    if (!this.state.queues.has(queueName)) {
      this.createQueue(queueName);
    }

    const queue = this.state.queues.get(queueName);
    const taskId = `task_${++this.state.taskCounter}_${Date.now()}`;

    const isFunction = typeof task === 'function';

    const taskObject = {
      id: taskId,
      queueName,
      callback: isFunction ? task : null,
      data: isFunction ? null : task,
      priority: options.priority !== undefined ? options.priority : 0,
      scheduledTime: options.delay ? Date.now() + options.delay : Date.now(),
      attempts: 0,
      maxRetries: options.maxRetries !== undefined ? options.maxRetries : queue.options.maxRetries,
      retryDelay: options.retryDelay !== undefined ? options.retryDelay : queue.options.retryDelay,
      backoffStrategy: options.backoffStrategy || queue.options.backoffStrategy,
      timeout: options.timeout !== undefined ? options.timeout : queue.options.timeout,
      createdAt: Date.now()
    };

    queue.tasks.push(taskObject);

    if (queue.options.priority) {
      this.sortQueueByPriority(queue);
    }

    if (!options.delay && !queue.options.paused && queue.activeTasks < queue.options.concurrency) {
      this.processNextTasks(queueName);
    }

    if (this.config.persistence.enabled && this.storageManager) {
      this.persistTask(taskObject);
    }

    EventManager.emit('queue:task:added', {
      queueName,
      taskId,
      scheduled: options.delay ? new Date(taskObject.scheduledTime) : new Date()
    });

    return taskId;
  },

  /**
   * Add multiple tasks to a queue
   * @param {string} queueName - Queue name
   * @param {Array} tasks - Array of tasks
   * @param {Object} options - Task options
   * @returns {Array} - Array of task IDs
   */
  addBulkTasks(queueName, tasks, options = {}) {
    if (!Array.isArray(tasks)) {
      throw new Error('Tasks must be an array');
    }

    return tasks.map(task => this.addTask(queueName, task, options));
  },

  /**
   * Sort queue tasks by priority
   * @param {Object} queue - Queue object
   * @private
   */
  sortQueueByPriority(queue) {
    queue.tasks.sort((a, b) => {
      if (a.priority !== b.priority) {
        return a.priority - b.priority;
      }
      return a.scheduledTime - b.scheduledTime;
    });
  },

  /**
   * Process next available tasks from a queue
   * @param {string} queueName - Queue name
   * @private
   */
  async processNextTasks(queueName) {
    if (!this.config.enabled || !this.state.enabled) {
      return;
    }

    if (!this.state.queues.has(queueName)) {
      return;
    }

    const queue = this.state.queues.get(queueName);

    if (queue.options.paused || queue.activeTasks >= queue.options.concurrency) {
      return;
    }

    const now = Date.now();
    const availableTasks = queue.tasks.filter(task => task.scheduledTime <= now);

    if (availableTasks.length === 0) {
      return;
    }

    if (this.config.rateLimiting.enabled) {
      if (now > this.state.rateLimitResetTime) {
        this.state.rateLimitCounter = 0;
        this.state.rateLimitResetTime = now + this.config.rateLimiting.interval;
      }

      if (this.state.rateLimitCounter >= this.config.rateLimiting.limit) {
        setTimeout(() => {
          this.processNextTasks(queueName);
        }, this.state.rateLimitResetTime - now);
        return;
      }
    }

    const tasksToProcess = Math.min(
      availableTasks.length,
      queue.options.concurrency - queue.activeTasks
    );

    if (tasksToProcess <= 0) {
      return;
    }

    if (this.config.rateLimiting.enabled) {
      this.state.rateLimitCounter += tasksToProcess;
    }

    for (let i = 0; i < tasksToProcess; i++) {
      const taskIndex = queue.tasks.findIndex(task => task.scheduledTime <= now);

      if (taskIndex === -1) {
        break;
      }

      const task = queue.tasks.splice(taskIndex, 1)[0];

      if (this.config.workerPool.enabled) {
        queue.activeTasks++;
        this.submitTaskToWorkerPool(task);
      } else {
        this.processTask(task);
      }
    }
  },

  /**
   * Actually process a task
   * @param {Object} task - Task object
   * @returns {Promise<void>}
   * @private
   */
  async processTask(task) {
    const queue = this.state.queues.get(task.queueName);

    if (!queue) {
      return;
    }

    queue.activeTasks++;
    this.state.runningTasks.set(task.id, task);

    const timeoutId = setTimeout(() => {
      this.handleTaskTimeout(task);
    }, task.timeout);

    const startTime = performance.now();

    try {
      EventManager.emit('queue:task:started', {
        queueName: task.queueName,
        taskId: task.id,
        attempt: task.attempts + 1
      });

      task.attempts++;

      let result;
      if (task.callback) {
        result = await Promise.resolve(task.callback(task.data));
      } else if (task.data && task.data.url) {
        result = await this.executeApiTask(task);
      } else {
        result = await this.executeEventTask(task);
      }

      clearTimeout(timeoutId);
      await this.handleTaskSuccess(task, result, performance.now() - startTime);

    } catch (error) {
      clearTimeout(timeoutId);
      await this.handleTaskError(task, error, performance.now() - startTime);
    } finally {
      this.state.runningTasks.delete(task.id);
      queue.activeTasks--;
      this.processNextTasks(task.queueName);
    }
  },

  /**
   * Execute a task that makes an API request
   * @param {Object} task - Task object
   * @returns {Promise<any>} - API response
   * @private
   */
  async executeApiTask(task) {
    const {url, method = 'GET', data, headers = {}} = task.data;
    const methodLower = method.toLowerCase();

    const apiService = window.ApiService || window.Now?.getManager?.('api');
    if (apiService && typeof apiService[methodLower] === 'function') {
      return apiService[methodLower](url, data, {headers});
    }

    if (window.http && typeof window.http[methodLower] === 'function') {
      return window.http[methodLower](url, data, {headers});
    }

    if (window.simpleFetch && typeof window.simpleFetch[methodLower] === 'function') {
      return window.simpleFetch[methodLower](url, data, {headers});
    }

    throw new Error('No HTTP client available to execute API task');
  },

  /**
   * Execute a task that emits an event
   * @param {Object} task - Task object
   * @returns {Promise<any>} - Event result
   * @private
   */
  async executeEventTask(task) {
    const {event, data} = task.data || {};

    if (!event) {
      throw new Error('Event name is required for event tasks');
    }

    return EventManager.emit(event, data || {});
  },

  /**
   * Handle task timeout
   * @param {Object} task - Task object
   * @private
   */
  async handleTaskTimeout(task) {
    const error = new Error(`Task ${task.id} timed out after ${task.timeout}ms`);
    await this.handleTaskError(task, error, task.timeout);
  },

  /**
   * Handle successful task completion
   * @param {Object} task - Task object
   * @param {any} result - Task result
   * @param {number} executionTime - Task execution time
   * @private
   */
  async handleTaskSuccess(task, result, executionTime) {
    this.state.statistics.processed++;
    this.state.statistics.succeeded++;

    const totalProcessed = this.state.statistics.processed;
    this.state.statistics.avgProcessingTime =
      ((this.state.statistics.avgProcessingTime * (totalProcessed - 1)) + executionTime) / totalProcessed;

    if (this.config.persistence.enabled && this.storageManager) {
      await this.removePersistedTask(task);
    }

    EventManager.emit('queue:task:completed', {
      queueName: task.queueName,
      taskId: task.id,
      executionTime,
      result
    });
  },

  /**
   * Handle task error
   * @param {Object} task - Task object
   * @param {Error} error - Error object
   * @param {number} executionTime - Task execution time
   * @private
   */
  async handleTaskError(task, error, executionTime) {
    this.state.statistics.processed++;

    if (task.attempts <= task.maxRetries) {
      let delay = task.retryDelay;

      if (task.backoffStrategy === 'exponential') {
        delay = task.retryDelay * Math.pow(2, task.attempts - 1);
      } else if (task.backoffStrategy === 'linear') {
        delay = task.retryDelay * task.attempts;
      }

      task.scheduledTime = Date.now() + delay;
      const queue = this.state.queues.get(task.queueName);
      queue.tasks.push(task);

      if (queue.options.priority) {
        this.sortQueueByPriority(queue);
      }

      this.state.statistics.retried++;

      EventManager.emit('queue:task:retrying', {
        queueName: task.queueName,
        taskId: task.id,
        attempt: task.attempts,
        nextAttempt: new Date(task.scheduledTime),
        error: {
          message: error.message,
          stack: error.stack
        }
      });
    } else {
      this.state.statistics.failed++;

      if (this.config.persistence.enabled && this.storageManager) {
        await this.removePersistedTask(task);
      }

      EventManager.emit('queue:task:failed', {
        queueName: task.queueName,
        taskId: task.id,
        attempts: task.attempts,
        executionTime,
        error: {
          message: error.message,
          stack: error.stack
        }
      });

      if (window.ErrorManager) {
        ErrorManager.handle(error, {
          context: `QueueManager.processTask(${task.queueName})`,
          type: 'queue:task:error',
          data: {
            taskId: task.id,
            queueName: task.queueName,
            attempts: task.attempts
          }
        });
      } else if (this.config.debug) {
        console.error(`Task ${task.id} in queue ${task.queueName} failed after ${task.attempts} attempts:`, error);
      }
    }
  },

  /**
   * Pause a queue
   * @param {string} queueName - Queue name
   * @returns {boolean} - Success status
   */
  pauseQueue(queueName) {
    if (!this.state.queues.has(queueName)) {
      return false;
    }

    const queue = this.state.queues.get(queueName);
    queue.options.paused = true;
    this.state.pausedQueues.add(queueName);

    EventManager.emit('queue:paused', {queueName});

    return true;
  },

  /**
   * Resume a paused queue
   * @param {string} queueName - Queue name
   * @returns {boolean} - Success status
   */
  resumeQueue(queueName) {
    if (!this.state.queues.has(queueName)) {
      return false;
    }

    const queue = this.state.queues.get(queueName);
    queue.options.paused = false;
    this.state.pausedQueues.delete(queueName);

    if (this.config.enabled && this.state.enabled) {
      this.processNextTasks(queueName);
    }

    EventManager.emit('queue:resumed', {queueName});

    return true;
  },

  /**
   * Check if a queue is paused
   * @param {string} queueName - Queue name
   * @returns {boolean} - Whether queue is paused
   */
  isQueuePaused(queueName) {
    if (!this.state.queues.has(queueName)) {
      return false;
    }

    return this.state.queues.get(queueName).options.paused;
  },

  /**
   * Clear all tasks from a queue
   * @param {string} queueName - Queue name
   * @returns {number} - Number of tasks cleared
   */
  clearQueue(queueName) {
    if (!this.state.queues.has(queueName)) {
      return 0;
    }

    const queue = this.state.queues.get(queueName);
    const taskCount = queue.tasks.length;
    queue.tasks = [];

    if (this.config.persistence.enabled && this.storageManager) {
      this.storageManager.deleteByQuery(
        this.config.persistence.storeName,
        {
          index: 'queueName',
          value: queueName
        }
      ).catch(error => {
        console.error(`Failed to clear persisted tasks for queue ${queueName}:`, error);
      });
    }

    EventManager.emit('queue:cleared', {queueName, taskCount});

    return taskCount;
  },

  /**
   * Get queue statistics
   * @param {string} queueName - Queue name
   * @returns {Object} - Queue statistics
   */
  getQueueStats(queueName) {
    if (!this.state.queues.has(queueName)) {
      return null;
    }

    const queue = this.state.queues.get(queueName);
    const now = Date.now();

    return {
      name: queueName,
      length: queue.tasks.length,
      activeTasks: queue.activeTasks,
      paused: queue.options.paused,
      enabled: this.config.enabled && this.state.enabled,
      ready: queue.tasks.filter(task => task.scheduledTime <= now).length,
      scheduled: queue.tasks.filter(task => task.scheduledTime > now).length,
      concurrency: queue.options.concurrency,
      priority: queue.options.priority,
      oldestTask: queue.tasks.length > 0 ?
        new Date(Math.min(...queue.tasks.map(t => t.createdAt))) : null
    };
  },

  /**
   * Get queue length
   * @param {string} queueName - Queue name
   * @returns {number} - Queue length
   */
  getQueueLength(queueName) {
    if (!this.state.queues.has(queueName)) {
      return 0;
    }

    return this.state.queues.get(queueName).tasks.length;
  },

  /**
   * Set queue priority mode
   * @param {string} queueName - Queue name
   * @param {boolean} priority - Whether queue uses priority
   * @returns {boolean} - Success status
   */
  setQueuePriority(queueName, priority) {
    if (!this.state.queues.has(queueName)) {
      return false;
    }

    const queue = this.state.queues.get(queueName);
    queue.options.priority = !!priority;

    if (queue.options.priority) {
      this.sortQueueByPriority(queue);
    }

    return true;
  },

  /**
   * Set queue concurrency
   * @param {string} queueName - Queue name
   * @param {number} concurrency - Concurrency level
   * @returns {boolean} - Success status
   */
  setQueueConcurrency(queueName, concurrency) {
    if (!this.state.queues.has(queueName)) {
      return false;
    }

    if (typeof concurrency !== 'number' || concurrency < 1) {
      throw new Error('Concurrency must be a positive number');
    }

    const queue = this.state.queues.get(queueName);
    queue.options.concurrency = concurrency;

    if (this.config.enabled && this.state.enabled) {
      this.processNextTasks(queueName);
    }

    return true;
  },

  /**
   * Persist a task to storage
   * @param {Object} task - Task object
   * @returns {Promise<void>}
   * @private
   */
  async persistTask(task) {
    if (!this.config.persistence.enabled || !this.storageManager) {
      return;
    }

    try {
      if (task.callback) {
        return;
      }

      const taskToStore = {
        id: task.id,
        queueName: task.queueName,
        data: task.data,
        priority: task.priority,
        scheduledTime: task.scheduledTime,
        attempts: task.attempts,
        maxRetries: task.maxRetries,
        retryDelay: task.retryDelay,
        backoffStrategy: task.backoffStrategy,
        timeout: task.timeout,
        createdAt: task.createdAt
      };

      await this.storageManager.add(
        this.config.persistence.storeName,
        taskToStore
      );
    } catch (error) {
      console.error('Failed to persist task:', error);
    }
  },

  /**
   * Remove a persisted task from storage
   * @param {Object} task - Task object
   * @returns {Promise<void>}
   * @private
   */
  async removePersistedTask(task) {
    if (!this.config.persistence.enabled || !this.storageManager) {
      return;
    }

    try {
      await this.storageManager.delete(
        this.config.persistence.storeName,
        task.id
      );
    } catch (error) {
      if (this.config.debug) {
        console.warn('Failed to remove persisted task:', error);
      }
    }
  },

  /**
   * Start persistence sync
   * @private
   */
  startPersistenceSync() {
    if (!this.config.persistence.enabled || !this.storageManager) {
      return;
    }

    if (this.state.persistenceTimer) {
      clearInterval(this.state.persistenceTimer);
    }

    this.state.persistenceTimer = setInterval(() => {
      this.syncPersistedTasks();
    }, this.config.persistence.syncInterval);
  },

  /**
   * Synchronize tasks with persistence storage
   * @returns {Promise<void>}
   * @private
   */
  async syncPersistedTasks() {
    if (!this.config.persistence.enabled || !this.storageManager) {
      return;
    }

    try {
      for (const [queueName, queue] of this.state.queues.entries()) {
        const tasksToPersist = queue.tasks.filter(task =>
          !task.callback && !task._persisted
        );

        for (const task of tasksToPersist) {
          await this.persistTask(task);
          task._persisted = true;
        }
      }

      const count = await this.storageManager.count(
        this.config.persistence.storeName
      );

      if (count > this.config.persistence.maxPersistedTasks) {
        const toRemove = count - this.config.persistence.maxPersistedTasks;
        const oldestTasks = await this.storageManager.getAll(
          this.config.persistence.storeName,
          {
            index: 'createdAt',
            direction: 'asc',
            limit: toRemove
          }
        );

        for (const task of oldestTasks) {
          await this.storageManager.delete(
            this.config.persistence.storeName,
            task.id
          );
        }
      }
    } catch (error) {
      console.error('Failed to sync persisted tasks:', error);
    }
  },

  /**
   * Load tasks from persistence storage
   * @returns {Promise<number>} - Number of tasks loaded
   * @private
   */
  async loadPersistedTasks() {
    if (!this.config.persistence.enabled || !this.storageManager) {
      return 0;
    }

    try {
      const tasks = await this.storageManager.getAll(
        this.config.persistence.storeName
      );

      if (tasks.length === 0) {
        return 0;
      }

      let loadedCount = 0;

      for (const task of tasks) {
        if (!this.state.queues.has(task.queueName)) {
          this.createQueue(task.queueName);
        }

        const queue = this.state.queues.get(task.queueName);
        queue.tasks.push({
          ...task,
          _persisted: true
        });
        loadedCount++;
      }

      for (const queue of this.state.queues.values()) {
        if (queue.options.priority) {
          this.sortQueueByPriority(queue);
        }
      }

      if (this.config.enabled && this.state.enabled) {
        for (const queueName of this.state.queues.keys()) {
          this.processNextTasks(queueName);
        }
      }

      EventManager.emit('queue:tasks:loaded', {count: loadedCount});

      return loadedCount;
    } catch (error) {
      console.error('Failed to load persisted tasks:', error);
      return 0;
    }
  },

  /**
   * Initialize worker pool
   * @private
   */
  initWorkerPool() {
    const size = this.config.workerPool.size;
    this.state.workers = [];

    for (let i = 0; i < size; i++) {
      this.state.workers.push({
        id: `worker_${i}`,
        busy: false,
        task: null,
        lastActive: Date.now()
      });
    }
  },

  /**
   * Submit task to worker pool
   * @param {Object} task - Task object
   * @private
   */
  submitTaskToWorkerPool(task) {
    const idleWorker = this.state.workers.find(worker => !worker.busy);

    if (idleWorker) {
      idleWorker.busy = true;
      idleWorker.task = task;
      idleWorker.lastActive = Date.now();

      this.processTaskInWorker(idleWorker);
    } else {
      const queue = this.state.queues.get(task.queueName);
      queue.tasks.unshift(task);
      queue.activeTasks--;
    }
  },

  /**
   * Process task in worker
   * @param {Object} worker - Worker object
   * @private
   */
  async processTaskInWorker(worker) {
    const task = worker.task;

    try {
      await this.processTask(task);
    } finally {
      worker.busy = false;
      worker.task = null;
      worker.lastActive = Date.now();

      for (const queueName of this.state.queues.keys()) {
        this.processNextTasks(queueName);
      }
    }
  },

  /**
   * Get global queue statistics
   * @returns {Object} - Global queue statistics
   */
  getGlobalStats() {
    const queues = {};
    let totalTasks = 0;
    let activeTasks = 0;
    let pausedQueues = 0;

    for (const [name, queue] of this.state.queues.entries()) {
      totalTasks += queue.tasks.length;
      activeTasks += queue.activeTasks;

      if (queue.options.paused) {
        pausedQueues++;
      }

      queues[name] = {
        length: queue.tasks.length,
        active: queue.activeTasks,
        paused: queue.options.paused,
        concurrency: queue.options.concurrency
      };
    }

    return {
      enabled: this.config.enabled && this.state.enabled,
      queues,
      queueCount: this.state.queues.size,
      pausedQueues,
      totalTasks,
      activeTasks,
      processed: this.state.statistics.processed,
      succeeded: this.state.statistics.succeeded,
      failed: this.state.statistics.failed,
      retried: this.state.statistics.retried,
      averageProcessingTime: this.state.statistics.avgProcessingTime,
      workers: {
        total: this.state.workers.length,
        active: this.state.workers.filter(w => w.busy).length
      }
    };
  },

  /**
   * Pause all queues
   * @returns {number} - Number of queues paused
   */
  pauseAll() {
    let count = 0;

    for (const queueName of this.state.queues.keys()) {
      if (!this.isQueuePaused(queueName)) {
        this.pauseQueue(queueName);
        count++;
      }
    }

    EventManager.emit('queue:all:paused', {count});

    return count;
  },

  /**
   * Resume all queues
   * @returns {number} - Number of queues resumed
   */
  resumeAll() {
    let count = 0;

    for (const queueName of this.state.pausedQueues) {
      this.resumeQueue(queueName);
      count++;
    }

    EventManager.emit('queue:all:resumed', {count});

    return count;
  },

  /**
   * Clear all queues
   * @returns {number} - Number of tasks cleared
   */
  clearAll() {
    let count = 0;

    for (const queueName of this.state.queues.keys()) {
      count += this.clearQueue(queueName);
    }

    EventManager.emit('queue:all:cleared', {count});

    return count;
  },

  /**
   * Clean up resources
   */
  destroy() {
    if (this.state.persistenceTimer) {
      clearInterval(this.state.persistenceTimer);
      this.state.persistenceTimer = null;
    }

    this.clearAll();

    this.state = {
      initialized: false,
      enabled: false,
      queues: new Map(),
      workers: [],
      activeWorkers: 0,
      runningTasks: new Map(),
      taskCounter: 0,
      persistenceTimer: null,
      rateLimitCounter: 0,
      rateLimitResetTime: 0,
      pausedQueues: new Set(),
      statistics: {
        processed: 0,
        succeeded: 0,
        failed: 0,
        retried: 0,
        avgProcessingTime: 0
      }
    };

    EventManager.emit('queue:destroyed');
  }
};

if (window.Now?.registerManager) {
  Now.registerManager('queue', QueueManager);
}

// Expose globally
window.QueueManager = QueueManager;
