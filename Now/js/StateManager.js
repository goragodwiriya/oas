const StateManager = {
  config: {
    debug: false,
    persistence: {
      enabled: true,
      key: 'app_state',
      blacklist: ['temp', 'ui'],
      encrypt: false
    },
    history: {
      enabled: true,
      maxSize: 50,
      include: ['user', 'data']
    },
    batch: {
      enabled: true,
      delay: 16
    },
    strict: true
  },

  state: {},
  modules: new Map(),
  computed: new Map(),
  watchers: new Map(),
  subscriptions: new Map(),
  subscriberCount: 0,
  activeSubscriptions: new Set(),
  history: [],
  historyIndex: -1,
  isTimeTraveling: false,
  middleware: [],
  batchQueue: new Map(),
  batchTimeout: null,

  async init(options = {}) {
    this.config = {...this.config, ...options};

    await this.restoreState();
    this.setupReactivity();

    return this;
  },

  setupReactivity() {
    this.state = new Proxy(this.state, {
      set: (target, property, value) => {
        target[property] = value;
        this.notifyWatchers(property);
        return true;
      }
    });
  },

  hasModule(name) {
    return this.modules.has(name);
  },

  registerModule(name, module, options = {}) {
    if (this.modules.has(name)) {
      const oldModule = this.modules.get(name);

      if (options.force) {
        if (oldModule.cleanup) {
          try {
            oldModule.cleanup();
          } catch (error) {
            console.warn(`[StateManager] Error cleaning up module "${name}": ${error.message}`);
          }
        }

        this.modules.delete(name);
        if (this.state[name]) {
          delete this.state[name];
        }

        for (const [key, computed] of this.computed.entries()) {
          if (key.startsWith(`${name}.`)) {
            this.computed.delete(key);
          }
        }

        for (const [path, handlers] of this.watchers.entries()) {
          if (path.startsWith(`${name}.`)) {
            this.watchers.delete(path);
          }
        }

        for (const [path, subs] of this.subscriptions.entries()) {
          if (path.startsWith(`${name}.`)) {
            subs.forEach((sub) => this.unsubscribe(sub.id));
            this.subscriptions.delete(path);
          }
        }

        this.history = this.history.map(entry => {
          if (entry.state && entry.state[name]) {
            const newEntry = {...entry};
            delete newEntry.state[name];
            return newEntry;
          }
          return entry;
        });

        if (this.config.debug) {
          console.warn(`[StateManager] Force registering module "${name}". Module data completely reset.`);
        }
      } else {
        if (this.config.debug) {
          console.warn(`[StateManager] Module "${name}" already exists. Use force:true to replace.`);
        }
        return oldModule;
      }
    }

    const initialState = typeof module.state === 'function'
      ? module.state()
      : module.state;

    const registeredModule = {
      ...module,
      initialState: JSON.parse(JSON.stringify(initialState)),
      state: initialState
    };

    this.modules.set(name, registeredModule);

    if (!this.state[name]) {
      this.state[name] = JSON.parse(JSON.stringify(initialState));
    }

    if (module.computed) {
      Object.entries(module.computed).forEach(([key, getter]) => {
        this.registerComputed(`${name}.${key}`, getter);
      });
    }

    if (module.watch) {
      Object.entries(module.watch).forEach(([path, handler]) => {
        this.watch(path, handler);
      });
    }

    if (registeredModule.init) {
      registeredModule.init(this.getModuleContext(name));
    }

    return this;
  },

  processModule(name, module) {
    return {
      name,
      state: module.state || {},
      mutations: this.processMutations(module.mutations || {}),
      actions: this.processActions(module.actions || {}),
      getters: module.getters || {},
      init: module.init,
      computed: module.computed || {},
      watch: module.watch || {}
    };
  },

  processMutations(mutations) {
    return Object.entries(mutations).reduce((acc, [key, mutation]) => {
      acc[key] = (state, payload) => {
        if (this.config.strict && !this.isTimeTraveling) {
          console.log('[StateManager]', `Mutation: ${key}`, payload);
        }
        mutation(state, payload);
      };
      return acc;
    }, {});
  },

  processActions(actions) {
    return Object.entries(actions).reduce((acc, [key, action]) => {
      acc[key] = async (context, payload) => {
        try {
          return await action(context, payload);
        } catch (error) {
          this.handleError('Action error', {action: key, error}, 'processActions');
          throw error;
        }
      };
      return acc;
    }, {});
  },

  getModuleContext(name) {
    const module = this.modules.get(name);
    if (!module) throw new Error(`Module ${name} not found`);

    return {
      state: this.state[name],
      getters: this.getModuleGetters(name),
      commit: (type, payload) => this.commit(`${name}/${type}`, payload),
      dispatch: (type, payload) => this.dispatch(`${name}/${type}`, payload),
      watch: (path, handler) => this.watch(`${name}.${path}`, handler)
    };
  },

  getModuleGetters(name) {
    const module = this.modules.get(name);
    return Object.entries(module.getters).reduce((acc, [key, getter]) => {
      Object.defineProperty(acc, key, {
        get: () => getter(this.state[name], acc)
      });
      return acc;
    }, {});
  },

  commit(type, payload) {
    try {
      const [moduleName, mutationName] = type.split('/');
      const module = this.modules.get(moduleName);

      if (!module) {
        throw new Error(`Module ${moduleName} not found`);
      }

      const mutation = module.mutations?.[mutationName];
      if (!mutation) {
        throw new Error(`Mutation ${mutationName} not found in module ${moduleName}`);
      }

      mutation(this.state[moduleName], payload);
      this.addToHistory(type, payload);
      this.notifyWatchers(moduleName);
    } catch (error) {
      ErrorManager.handle(error, {
        context: 'StateManager.commit',
        type: 'error:state',
        data: {type, payload}
      });
      throw error;
    }
  },

  async dispatch(type, payload) {
    try {
      const [moduleName, actionName] = type.split('/');
      const module = this.modules.get(moduleName);

      if (!module) {
        throw new Error(`Module ${moduleName} not found`);
      }

      const action = module.actions?.[actionName];
      if (!action) {
        throw new Error(`Action ${actionName} not found in module ${moduleName}`);
      }

      const context = this.getModuleContext(moduleName);
      return await action(context, payload);
    } catch (error) {
      ErrorManager.handle(error, {
        context: 'StateManager.dispatch',
        type: 'error:state',
        data: {type, payload}
      });
      throw error;
    }
  },

  registerComputed(path, getter) {
    this.computed.set(path, {
      getter,
      cache: null,
      deps: new Set()
    });

    let currentComputed = null;
    Object.defineProperty(this.state, path, {
      get: () => {
        const computed = this.computed.get(path);

        if (currentComputed && currentComputed !== path) {
          computed.deps.add(currentComputed);
        }

        if (computed.cache === null) {
          currentComputed = path;
          computed.cache = getter(this.state);
          currentComputed = null;
        }

        return computed.cache;
      }
    });
  },

  watch(path, callback) {
    try {
      if (!this.isValidPath(path)) {
        throw new Error(`Invalid state path: ${path}`);
      }

      if (!this.watchers.has(path)) {
        this.watchers.set(path, new Set());
      }
      this.watchers.get(path).add(callback);

      return () => {
        const handlers = this.watchers.get(path);
        if (handlers) {
          handlers.delete(callback);
        }
      };
    } catch (error) {
      ErrorManager.handle(error, {
        context: 'StateManager.watch',
        type: 'error:state',
        data: {path}
      });
      throw error;
    }
  },

  notifyWatchers(moduleName) {
    this.watchers.forEach((handlers, path) => {
      if (path.startsWith(moduleName)) {
        const value = this.getStateValue(path);
        handlers.forEach(handler => {
          try {
            handler(value);
          } catch (error) {
            this.handleError('Watcher error', {path, error}, 'notifyWatchers');
          }
        });
      }
    });

    this.computed.forEach((computed, path) => {
      if (path.startsWith(moduleName)) {
        computed.cache = null;
        computed.deps.forEach(dep => {
          const dependent = this.computed.get(dep);
          if (dependent) {
            dependent.cache = null;
          }
        });
      }
    });
  },

  getStateValue(path) {
    return path.split('.').reduce((obj, key) => obj[key], this.state);
  },

  use(middleware) {
    this.middleware.push(middleware);
    return this;
  },

  async runMiddleware(hook, context) {
    for (const middleware of this.middleware) {
      if (middleware[hook]) {
        await middleware[hook](context);
      }
    }
  },

  addToHistory(type, payload) {
    if (!this.config.history.enabled || this.isTimeTraveling) return;

    const snapshot = {};
    Object.keys(this.state).forEach(module => {
      if (this.state[module]) {
        snapshot[module] = JSON.parse(JSON.stringify(this.state[module]));
      }
    });

    this.history = this.history.slice(0, this.historyIndex + 1);

    this.history.push({
      type,
      payload: payload !== undefined ? JSON.parse(JSON.stringify(payload)) : null,
      state: snapshot,
      timestamp: Date.now()
    });

    if (this.history.length > this.config.history.maxSize) {
      this.history.shift();
    }

    this.historyIndex = this.history.length - 1;
  },

  subscribe(path, callback, options = {}) {
    if (!path || typeof path !== 'string') {
      throw new Error('Path must be a string');
    }

    if (typeof callback !== 'function') {
      throw new Error('Callback must be a function');
    }

    const subId = `sub_${this.subscriberCount++}`;

    const subscription = {
      id: subId,
      path,
      callback,
      options: {
        immediate: true,
        deep: false,
        ...options
      },
      active: true,
      timestamp: Date.now()
    };

    if (!this.subscriptions.has(path)) {
      this.subscriptions.set(path, new Map());
    }
    this.subscriptions.get(path).set(subId, subscription);

    this.activeSubscriptions.add(subId);

    if (subscription.options.immediate) {
      const value = this.get(path);
      try {
        callback(value);
      } catch (error) {
        this.handleError('Immediate subscription callback', error, 'subscribe');
      }
    }

    return () => this.unsubscribe(subId);
  },

  unsubscribe(subscriptionId) {
    let found = false;
    this.subscriptions.forEach((subs, path) => {
      if (subs.has(subscriptionId)) {
        subs.delete(subscriptionId);
        this.activeSubscriptions.delete(subscriptionId);
        found = true;

        if (subs.size === 0) {
          this.subscriptions.delete(path);
        }
      }
    });

    if (!found && this.config.debug) {
      console.warn(`[StateManager] Subscription ${subscriptionId} not found`);
    }
  },

  notifySubscribers(path, value, oldValue) {
    const subs = this.subscriptions.get(path);
    if (!subs) return;

    subs.forEach(sub => {
      if (!sub.active) return;

      try {
        if (sub.options.deep && typeof value === 'object') {
          if (JSON.stringify(value) === JSON.stringify(oldValue)) {
            return;
          }
        }

        sub.callback(value, oldValue);

      } catch (error) {
        this.handleError(`Subscription callback for ${path}`, error, 'notifySubscribers');
        sub.active = false;
      }
    });
  },

  get(path) {
    return path.split('.').reduce((obj, key) => obj?.[key], this.state);
  },

  set(path, value) {
    const parts = path.split('.');
    const key = parts.pop();
    const target = parts.length ?
      parts.reduce((obj, key) => obj[key], this.state) :
      this.state;

    if (target) {
      target[key] = value;
      this.notifySubscribers(path, value);
    }
  },

  cleanupSubscriptions() {
    const now = Date.now();
    const maxAge = this.config.cleanup.maxSubscriptionAge || 3600000;

    this.subscriptions.forEach((subs, path) => {
      subs.forEach((sub, id) => {
        if (!sub.active || (now - sub.timestamp > maxAge)) {
          this.unsubscribe(id);
        }
      });
    });
  },

  timeTravel(index) {
    try {
      if (!this.config.history.enabled || index < 0 || index >= this.history.length) {
        throw new Error('Invalid history index');
      }

      this.isTimeTraveling = true;
      try {
        const historyEntry = this.history[index];
        if (!historyEntry || !historyEntry.state) {
          throw new Error('Invalid history entry');
        }

        Object.keys(historyEntry.state).forEach(module => {
          if (historyEntry.state[module]) {
            this.state[module] = JSON.parse(JSON.stringify(historyEntry.state[module]));
          }
        });

        this.historyIndex = index;

        Object.keys(this.state).forEach(module => {
          this.notifyWatchers(module);
          if (this.state[module] && this.state[module].count !== undefined) {
            this.notifySubscribers(`${module}.count`, this.state[module].count);
          }
        });
      } catch (error) {
        throw error;
      } finally {
        this.isTimeTraveling = false;
      }
    } catch (error) {
      ErrorManager.handle(error, {
        context: 'StateManager.timeTravel',
        type: 'error:state',
        data: {index}
      });
      throw error;
    }
  },

  persistState() {
    try {
      if (!this.config.persistence.enabled) return;

      const state = JSON.stringify(this.state);
      localStorage.setItem(this.config.persistence.key, state);
    } catch (error) {
      ErrorManager.handle(error, {
        context: 'StateManager.persistState',
        type: 'error:state',
        data: {state: this.state}
      });
      throw error;
    }
  },

  async restoreState() {
    if (!this.config.persistence.enabled) return;

    const data = localStorage.getItem(this.config.persistence.key);
    if (!data) return;

    try {
      const state = this.config.persistence.encrypt
        ? await this.decrypt(data)
        : JSON.parse(data);

      Object.assign(this.state, state);

    } catch (error) {
      this.handleError('State restoration failed', error, 'restoreState');
    }
  },

  batch(callback) {
    if (!this.config.batch.enabled) {
      return callback();
    }

    const execute = async () => {
      const queue = Array.from(this.batchQueue.values());
      this.batchQueue.clear();

      for (const task of queue) {
        await task();
      }
    };

    const id = Date.now();
    this.batchQueue.set(id, callback);

    if (this.batchTimeout) {
      clearTimeout(this.batchTimeout);
    }

    this.batchTimeout = setTimeout(
      execute,
      this.config.batch.delay
    );
  },

  handleError(message, error, type) {
    const errorObj = error || new Error(message);

    const data = {
      message,
      error: error ? {
        name: error.name,
        message: error.message,
        stack: error.stack
      } : null,
      timestamp: new Date().toISOString(),
      moduleStates: {},
      activeSubscriptions: Array.from(this.activeSubscriptions),
      batchQueueSize: this.batchQueue.size
    };

    this.modules.forEach((module, name) => {
      data.moduleStates[name] = {
        hasState: !!this.state[name],
        subscriberCount: this.getModuleSubscriberCount(name)
      };
    });

    ErrorManager.handle(errorObj, {
      context: `StateManager.${type}`,
      type: 'error:state',
      data,
      notify: true
    });
  },

  reset() {
    this.modules.forEach((module, name) => {
      if (module.initialState) {
        this.state[name] = JSON.parse(JSON.stringify(module.initialState));
      }
    });

    this.history = [];
    this.historyIndex = -1;
    this.computed.forEach(computed => computed.cache = null);

    Object.keys(this.state).forEach(name => {
      this.notifyWatchers(name);
      if (this.state[name]?.count !== undefined) {
        this.notifySubscribers(`${name}.count`, this.state[name].count);
      }
    });

    if (this.config.persistence.enabled) {
      this.persistState();
    }
  },

  isValidPath(path) {
    if (!path || typeof path !== 'string') return false;
    const parts = path.split('.');
    return parts.length >= 1 && parts.every(part => part.length > 0);
  }
};

// Register with Now.js framework
if (window.Now?.registerManager) {
  Now.registerManager('state', StateManager);
}

window.StateManager = StateManager;
