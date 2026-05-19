const ReactiveManager = {
  config: {
    debug: false,
    batchUpdates: true,
    cleanupInterval: 60000,
    computed: {
      cache: true
    }
  },

  state: {
    currentEffect: null,
    effects: new Set(),
    dependencies: new Map(),
    pendingUpdates: new Set(),
    updateQueued: false,
    rawToProxy: new WeakMap(),
    proxyToRaw: new WeakMap(),
    cleanedEffects: new WeakSet()
  },

  async init(options = {}) {
    this.config = {...this.config, ...options};

    this.startCleanup();

    return this;
  },

  track(target, prop) {
    if (!this.state.currentEffect) return;

    const id = target.__reactiveId;
    if (!id) return;

    if (!this.state.dependencies.has(id)) {
      this.state.dependencies.set(id, new Map());
    }

    const depsMap = this.state.dependencies.get(id);
    if (!depsMap.has(prop)) {
      depsMap.set(prop, new Set());
    }

    const effects = depsMap.get(prop);
    effects.add(this.state.currentEffect);
  },

  trigger(target, prop) {
    const targetId = target.__reactiveId;
    if (!targetId) return;

    const depsMap = this.state.dependencies.get(targetId);
    if (!depsMap) {
      this.triggerEffects();
      return;
    }

    const effects = depsMap.get(prop);
    if (!effects) {
      this.triggerEffects();
      return;
    }

    this.runEffects(effects);
  },

  triggerEffects() {
    this.state.effects.forEach(effect => {
      if (effect.active) {
        if (this.config.batchUpdates) {
          this.state.pendingUpdates.add(effect);
          this.scheduleUpdate();
        } else {
          this.runEffect(effect);
        }
      }
    });
  },

  runEffects(effects) {
    effects.forEach(effect => {
      if (effect.active) {
        if (this.config.batchUpdates) {
          this.state.pendingUpdates.add(effect);
          this.scheduleUpdate();
        } else {
          this.runEffect(effect);
        }
      }
    });
  },

  runEffect(effect) {
    try {
      effect();
    } catch (error) {
      ErrorManager.handle(error, {
        context: 'ReactiveManager.runEffect',
        data: {effect}
      });
    }
  },

  scheduleUpdate() {
    if (!this.state.updateQueued) {
      this.state.updateQueued = true;
      queueMicrotask(() => {
        this.flushUpdates();
      });
    }
  },

  flushUpdates() {
    if (!this.state.updateQueued) return;

    const effects = Array.from(this.state.pendingUpdates);
    this.state.pendingUpdates.clear();
    this.state.updateQueued = false;

    effects.forEach(effect => {
      if (effect.active) {
        effect();
      }
    });
  },

  reactive(target) {
    if (!target || typeof target !== 'object') {
      return target;
    }

    if (!target.__reactiveId) {
      target.__reactiveId = this.generateId();
    }

    Object.defineProperty(target, '__isReactive', {
      value: true,
      enumerable: false,
      configurable: false
    });

    if (Array.isArray(target)) {
      return this.createArrayProxy(target);
    }

    return new Proxy(target, {
      get: (obj, prop) => {
        if (prop === '__reactiveId' || prop === '__isReactive') {
          return obj[prop];
        }

        if (this.state.currentEffect) {
          this.track(obj, prop);
        }

        return obj[prop];
      },

      set: (obj, prop, value) => {
        const oldValue = obj[prop];
        obj[prop] = value;

        if (oldValue !== value) {
          this.trigger(obj, prop);
        }

        return true;
      }
    });
  },

  effect(fn) {
    const effect = () => {
      if (!effect.active) return;

      const prevEffect = this.state.currentEffect;
      this.state.currentEffect = effect;

      try {
        fn();
      } finally {
        this.state.currentEffect = prevEffect;
      }
    };

    effect.active = true;
    effect.isEffect = true;

    effect();

    this.state.effects.add(effect);

    return () => {
      effect.active = false;
      this.cleanupEffect(effect);
      this.state.effects.delete(effect);
    };
  },

  isReactive(value) {
    return Boolean(value && value.__isReactive);
  },

  computed(getter) {
    let value;
    let dirty = true;

    const effect = () => {
      try {
        if (dirty) {
          value = getter();
          dirty = false;
        }
        return value;
      } catch (error) {
        throw error;
      }
    };

    return {
      get value() {
        const prevEffect = ReactiveManager.state.currentEffect;
        ReactiveManager.state.currentEffect = () => {dirty = true;};
        const result = effect();
        ReactiveManager.state.currentEffect = prevEffect;
        return result;
      }
    };
  },

  watch(arg1, arg2, arg3) {
    if (typeof arg1 === 'function') {
      return this.watchEffect(arg1, arg2);
    }

    return this.watchProp(arg1, arg2, arg3);
  },

  watchEffect(fn, callback) {
    let isActive = true;
    const effect = () => {
      if (!isActive) return;

      try {
        const value = fn();
        if (isActive && typeof callback === 'function') {
          callback(value);
        }
        return value;
      } catch (error) {
        throw error;
      }
    };

    effect.isEffect = true;
    effect.active = true;

    const prevEffect = this.state.currentEffect;
    this.state.currentEffect = effect;

    try {
      effect();
    } finally {
      this.state.currentEffect = prevEffect;
    }

    this.state.effects.add(effect);

    return () => {
      isActive = false;
      effect.active = false;
      this.state.effects.delete(effect);
      this.cleanupEffect(effect);
    };
  },

  watchProp(target, prop, callback, options = {}) {
    if (!target.__reactiveId) {
      target.__reactiveId = this.generateId();
    }

    const effect = () => {
      if (!effect.active) return;

      const currentValue = target[prop];

      callback(currentValue);
    };

    effect.active = true;
    effect.isEffect = true;

    if (!this.state.dependencies.has(target.__reactiveId)) {
      this.state.dependencies.set(target.__reactiveId, new Map());
    }

    const depsMap = this.state.dependencies.get(target.__reactiveId);
    if (!depsMap.has(prop)) {
      depsMap.set(prop, new Set());
    }

    depsMap.get(prop).add(effect);

    effect();

    return () => {
      effect.active = false;
      const depsMap = this.state.dependencies.get(target.__reactiveId);
      if (depsMap && depsMap.has(prop)) {
        depsMap.get(prop).delete(effect);
      }
    };
  },

  isProxy(obj) {
    return Boolean(obj && obj.__isReactive);
  },

  findComponentForTarget(target) {
    for (const [componentId, state] of this.state.componentStates) {
      if (this.isStateTarget(target, state)) {
        return ComponentManager.instances.get(componentId);
      }
    }
    return null;
  },

  isStateTarget(target, state) {
    if (target === state) return true;

    for (const value of Object.values(state)) {
      if (value && typeof value === 'object') {
        if (value === target || this.isStateTarget(target, value)) {
          return true;
        }
      }
    }

    return false;
  },

  queueEffect(effect) {
    if (!effect.active) return;

    if (this.config.batchUpdates) {
      this.state.pendingUpdates.add(effect);
      this.scheduleUpdate();
    } else {
      try {
        effect();
      } catch (error) {
        throw error;
      }
    }
  },

  createComponentState(component) {
    try {
      if (!component.reactive) {
        return component.state;
      }

      const reactiveState = this.reactive(component.state);
      this.state.componentStates.set(component.id, reactiveState);
      return reactiveState;
    } catch (error) {
      ErrorManager.handle(error, {
        context: 'ReactiveManager.createComponentState',
        data: {component}
      });
      return component.state;
    }
  },

  getStateValue(state, path) {
    return path.split('.').reduce((obj, key) => obj?.[key], state);
  },

  watchDeep(target, callback, options = {}) {
    return this.watch(
      () => JSON.parse(JSON.stringify(target)),
      callback,
      {...options, deep: true}
    );
  },

  setStateValue(state, path, value) {
    const clonedValue = this.deepClone(value);
    const parts = path.split('.');
    const lastKey = parts.pop();
    const target = parts.reduce((obj, key) => obj[key], state);
    if (target) {
      target[lastKey] = clonedValue;
    }
  },

  bindComponentEvents(component) {
    if (!component.events) return;

    try {
      const eventManager = Now.getManager('event');
      if (!eventManager) {
        ErrorManager.handle('Event system not available', {
          context: 'ReactiveManager.bindComponentEvents'
        });
        return;
      }

      Object.entries(component.events).forEach(([eventName, handler]) => {
        const boundHandler = handler.bind(component);
        if (!component._eventHandlers) {
          component._eventHandlers = new Map();
        }
        component._eventHandlers.set(eventName, boundHandler);
        eventManager.on(eventName, boundHandler);
      });
    } catch (error) {
      ErrorManager.handle(error, {
        context: 'ReactiveManager.bindComponentEvents',
        data: {component}
      });
    }
  },

  unbindComponentEvents(component) {
    if (!component._eventHandlers) return;

    const eventManager = Now.getManager('event');
    if (!eventManager) return;

    component._eventHandlers.forEach((handler, eventName) => {
      eventManager.off(eventName, handler);
    });

    component._eventHandlers.clear();
    delete component._eventHandlers;
  },

  createStateProxy() {
    return new Proxy(this.state, {
      set: (target, property, value) => {
        if (Array.isArray(target) && /^\d+$/.test(property)) {
          this.notifyArrayChange(target);
        }

        target[property] = value;
        this.notifyChange(property);
        return true;
      }
    });
  },

  notifyArrayChange(array) {
    const watchers = this.state.watchers.get(array);
    if (!watchers) return;

    this.notifyWatchers(array, 'length', array.length);

    watchers.forEach((watcherSet, key) => {
      if (key !== 'length') {
        this.notifyWatchers(array, key, array[key]);
      }
    });
  },

  notifyChange(property) {
    const watchers = this.getWatchers(property);
    watchers.forEach(watcher => {
      try {
        this.state.pendingUpdates.add(() => watcher(this.state[property]));
        this.scheduleUpdate();

        EventManager.emit('reactive:change', {
          property,
          value: this.state[property]
        });
      } catch (error) {
        throw error;
      }
    });
  },

  runWithTracking(fn) {
    const prevEffect = this.state.currentEffect;
    this.state.currentEffect = fn;

    try {
      return fn();
    } finally {
      this.state.currentEffect = prevEffect;
    }
  },

  startCleanup() {
    this.state.cleanupTimer = setInterval(() => {
      this.cleanup();
    }, this.config.cleanupInterval);

    window.addEventListener('beforeunload', () => {
      this.cleanup();
      clearInterval(this.state.cleanupTimer);
    });

    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'hidden') {
        this.cleanup();
        clearInterval(this.state.cleanupTimer);
        this.state.cleanupTimer = null;
      } else if (!this.state.cleanupTimer) {
        this.state.cleanupTimer = setInterval(() => {
          this.cleanup();
        }, this.config.cleanupInterval);
      }
    });
  },

  cleanup() {
    for (const [target, deps] of this.state.dependencies) {
      for (const [key, watchers] of deps) {
        const activeWatchers = new Set(
          Array.from(watchers).filter(watcher => this.isWatcherValid(watcher))
        );

        if (activeWatchers.size === 0) {
          deps.delete(key);
        } else {
          deps.set(key, activeWatchers);
        }
      }

      if (deps.size === 0) {
        this.state.dependencies.delete(target);
      }
    }

    for (const [effect, cached] of this.state.computedCache) {
      if (!this.isWatcherValid(effect)) {
        this.state.computedCache.delete(effect);
      }
    }
  },

  isWatcherValid(watcher) {
    for (const deps of this.state.dependencies.values()) {
      for (const watchers of deps.values()) {
        if (watchers.has(watcher)) {
          return true;
        }
      }
    }
    return false;
  },

  cleanupEffect(effect) {
    if (!effect) return;

    this.state.cleanedEffects.add(effect);

    for (const [targetId, depsMap] of this.state.dependencies) {
      if (!depsMap) continue;

      for (const [prop, effects] of depsMap) {
        if (effects.has(effect)) {
          effects.delete(effect);

          if (effects.size === 0) {
            depsMap.delete(prop);
          }
        }
      }

      if (depsMap.size === 0) {
        this.state.dependencies.delete(targetId);
      }
    }

    this.state.pendingUpdates.delete(effect);
  },

  deepClone(value) {
    if (typeof value !== 'object' || value === null) return value;
    return JSON.parse(JSON.stringify(value));
  },

  validateState(state) {
    if (!state || typeof state !== 'object') {
      throw new Error('Invalid state object');
    }
  },

  createComputation(fn, context) {
    const computation = {
      fn,
      context,
      dependencies: new Set(),
      isComputing: false
    };
    this.state.effects.add(computation);
    return computation;
  },

  runComputation(computation) {
    if (!computation || computation.isComputing) return;

    if (computation.context?._skipReactive) return;

    computation.isComputing = true;
    const previousEffect = this.state.currentEffect;
    this.state.currentEffect = computation;

    try {
      this.cleanup(computation);
      computation.fn.call(computation.context);
    } finally {
      computation.isComputing = false;
      this.state.currentEffect = previousEffect;
    }
  },

  _bindComponentEvents(instance) {
    if (!instance || !instance.reactive) return;

    const computation = this.createComputation(() => {
      if (!instance._skipReactive && typeof instance.render === 'function') {
        instance.render();
      }
    }, instance);

    this.runComputation(computation);

    if (instance.watch) {
      Object.entries(instance.watch).forEach(([prop, handler]) => {
        const watchComputation = this.createComputation(() => {
          if (!instance._skipReactive) {
            handler.call(instance, instance.state[prop]);
          }
        }, instance);

        this.track(prop);

        this.runComputation(watchComputation);
      });
    }
  },

  _addDependency(dep) {
    if (this.state.currentEffect) {
      this.state.currentEffect.dependencies.add(dep);

      const key = `${dep.target.id}:${dep.prop}`;
      if (!this.state.dependencies.has(key)) {
        this.state.dependencies.set(key, new Set());
      }
      this.state.dependencies.get(key).add(this.state.currentEffect);
    }
  },

  createComponentState(instance) {
    const state = {...instance.state};

    return new Proxy(state, {
      get: (target, prop) => {
        this.addDependency({
          target: instance,
          prop: prop
        });
        return target[prop];
      },

      set: (target, prop, value) => {
        const oldValue = target[prop];
        target[prop] = value;

        this.triggerUpdate({
          target: instance,
          prop: prop,
          oldValue,
          newValue: value
        });

        return true;
      }
    });
  },

  _triggerUpdate(change) {
    const key = `${change.target.id}:${change.prop}`;
    const deps = this.state.dependencies.get(key);

    if (deps) {
      deps.forEach(computation => {
        this.runComputation(computation);
      });
    }
  },

  cleanupDependencies(computation) {
    if (!computation) return;
    this.cleanup(computation);
  },

  isIterable(value) {
    return value != null && typeof value[Symbol.iterator] === 'function';
  },

  getDependenciesArray(deps) {
    if (!deps) return [];
    if (this.isIterable(deps)) return Array.from(deps);
    if (Array.isArray(deps)) return deps;
    return [];
  },

  isValidComputation(computation) {
    return computation &&
      typeof computation === 'object' &&
      typeof computation.fn === 'function' &&
      computation.dependencies instanceof Set;
  },

  createComputation(fn, context) {
    if (typeof fn !== 'function') {
      throw new Error('Computation must be a function');
    }

    const computation = {
      fn,
      context,
      dependencies: new Set(),
      isComputing: false
    };

    if (this.isValidComputation(computation)) {
      this.state.effects.add(computation);
      return computation;
    }

    throw new Error('Invalid computation created');
  },

  cleanup(computation) {
    if (!this.isValidComputation(computation)) return;

    const deps = this.getDependenciesArray(computation.dependencies);

    deps.forEach(dep => {
      if (!dep || !dep.target || !dep.prop) return;

      const key = `${dep.target.id}:${dep.prop}`;
      const dependencySet = this.state.dependencies.get(key);

      if (dependencySet instanceof Set) {
        dependencySet.delete(computation);
        if (dependencySet.size === 0) {
          this.state.dependencies.delete(key);
        }
      }
    });

    computation.dependencies.clear();
  },

  addDependency(dep) {
    if (this.state.currentEffect?.context?._skipReactive) {
      return;
    }

    if (!this.state.currentEffect ||
      !this.isValidComputation(this.state.currentEffect)) return;

    if (!dep || !dep.target || !dep.prop) return;

    this.state.currentEffect.dependencies.add(dep);

    const key = `${dep.target.id}:${dep.prop}`;
    if (!this.state.dependencies.has(key)) {
      this.state.dependencies.set(key, new Set());
    }

    const deps = this.state.dependencies.get(key);
    if (deps instanceof Set) {
      deps.add(this.state.currentEffect);
    }
  },

  triggerUpdate(change) {
    if (!change || !change.target || !change.prop) return;

    if (change.target._skipReactive) return;

    const key = `${change.target.id}:${change.prop}`;
    const deps = this.state.dependencies.get(key);

    if (deps instanceof Set) {
      deps.forEach(computation => {
        if (this.isValidComputation(computation)) {
          this.runComputation(computation);
        } else {
          deps.delete(computation);
        }
      });
    }
  },

  runComputation(computation) {
    if (!this.isValidComputation(computation) || computation.isComputing) return;

    computation.isComputing = true;
    const previousEffect = this.state.currentEffect;
    this.state.currentEffect = computation;

    try {
      this.cleanup(computation);
      computation.fn.call(computation.context);
    } catch (error) {
      throw error;
    } finally {
      computation.isComputing = false;
      this.state.currentEffect = previousEffect;
    }
  },

  bindComponentEvents(instance) {
    if (!instance || !instance.reactive) return;

    try {
      const computation = this.createComputation(() => {
        if (typeof instance.render === 'function') {
          instance.render();
        }
      }, instance);

      this.runComputation(computation);

      if (instance.watch && typeof instance.watch === 'object') {
        Object.entries(instance.watch).forEach(([prop, handler]) => {
          if (typeof handler === 'function') {
            const watchComputation = this.createComputation(() => {
              handler.call(instance, instance.state[prop]);
            }, instance);

            this.track(prop);
            this.runComputation(watchComputation);
          }
        });
      }
    } catch (error) {
      throw error;
    }
  },

  _track(fn) {
    if (typeof fn !== 'function') return null;

    const previousEffect = this.state.currentEffect;
    const computation = this.createComputation(fn, null);

    try {
      this.runComputation(computation);
      return computation;
    } catch (error) {
      throw error;
    } finally {
      this.state.currentEffect = previousEffect;
    }
  },

  _createComponentState(instance) {
    if (!instance || !instance.state) return {};

    const state = {...instance.state};

    return new Proxy(state, {
      get: (target, prop) => {
        if (prop in target) {
          if (!instance._skipReactive) {
            this.addDependency({
              target: instance,
              prop: prop
            });
          }
        }
        return target[prop];
      },

      set: (target, prop, value) => {
        const oldValue = target[prop];
        target[prop] = value;

        this.triggerUpdate({
          target: instance,
          prop: prop,
          oldValue,
          newValue: value
        });

        return true;
      }
    });
  },

  validateConfig(config) {
    if (!config || typeof config !== 'object') {
      throw new Error('Invalid configuration object');
    }
  },

  deepClone(value, seen = new WeakMap()) {
    if (!value || typeof value !== 'object') return value;
    if (seen.has(value)) return seen.get(value);

    const clone = Array.isArray(value) ? [] : {};
    seen.set(value, clone);

    Object.entries(value).forEach(([key, val]) => {
      clone[key] = this.deepClone(val, seen);
    });

    return clone;
  },

  createArrayProxy(array) {
    if (!array.__reactiveId) {
      array.__reactiveId = this.generateId();
    }

    const self = this;
    return new Proxy(array, {
      get(target, prop) {
        self.track(target, prop);
        const value = target[prop];

        if (typeof value === 'function') {
          return function(...args) {
            const oldLength = target.length;

            const result = value.apply(target, args);

            if (['push', 'pop', 'shift', 'unshift', 'splice'].includes(prop)) {
              const newLength = target.length;

              if (oldLength !== newLength) {
                self.trigger(target, 'length');
                for (let i = oldLength; i < newLength; i++) {
                  self.trigger(target, i.toString());
                }
              }
            }

            return result;
          };
        }
        return value;
      },

      set(target, prop, value) {
        const oldValue = target[prop];
        const oldLength = target.length;

        target[prop] = value;

        if (oldValue !== value) {
          self.trigger(target, prop);
        }

        const newLength = target.length;
        if (newLength !== oldLength) {
          self.trigger(target, 'length');
        }

        return true;
      }
    });
  },

  enableDebug() {
    DEBUG.enabled = true;
  },

  disableDebug() {
    DEBUG.enabled = false;
  },

  getDebugInfo() {
    return {
      dependencies: Array.from(this.state.dependencies.entries()),
      computedCache: Array.from(this.state.computedCache.entries()),
      pendingUpdates: Array.from(this.state.pendingUpdates),
      componentStates: Array.from(this.state.componentStates.entries())
    };
  },

  handleLifecycle(component, event) {
    if (!component || !event) return;

    switch (event) {
      case 'mount':
        this.setupComponentReactivity(component);
        break;

      case 'unmount':
        this.cleanupComponentReactivity(component);
        break;

      case 'update':
        this.updateComponentReactivity(component);
        break;
    }
  },

  setupComponentReactivity(component) {
    if (!component.reactive) return;

    component.state = this.reactive(component.state || {});

    if (component.watch) {
      Object.entries(component.watch).forEach(([prop, handler]) => {
        this.watch(() => component.state[prop], handler.bind(component));
      });
    }

    if (component.computed) {
      Object.entries(component.computed).forEach(([key, getter]) => {
        Object.defineProperty(component, key, {
          get: () => this.computed(getter.bind(component))(),
          enumerable: true
        });
      });
    }
  },

  cleanupComponentReactivity(component) {
    if (!component.id) return;

    this.state.componentStates.delete(component.id);

    const componentDeps = Array.from(this.state.dependencies.values())
      .filter(deps => this.isComponentDependency(deps, component));

    componentDeps.forEach(deps => {
      this.cleanupDependencies(deps);
    });
  },

  updateComponentReactivity(component) {
    this.cleanupComponentReactivity(component);

    this.setupComponentReactivity(component);
  },

  isComponentDependency(deps, component) {
    return Array.from(deps.values()).some(watchers =>
      Array.from(watchers).some(watcher =>
        watcher.component === component
      )
    );
  },

  notifyWatchers(target, key, value, oldValue) {
    const watchers = this.state.watchers.get(target);
    if (!watchers) return;

    const keyWatchers = watchers.get(key);
    if (!keyWatchers) return;

    keyWatchers.forEach(watcher => {
      try {
        if (this.config.batchUpdates) {
          this.state.pendingUpdates.add(() => watcher(value, oldValue));
          this.scheduleUpdate();
        } else {
          watcher(value, oldValue);
        }
      } catch (error) {
        ErrorManager.handle(error, {
          context: 'ReactiveManager.notifyWatchers',
          data: {target, key, value, oldValue}
        });
      }
    });
  },

  batch(fn) {
    this.state.batchDepth++;
    try {
      const result = fn();
      if (result instanceof Promise) {
        return result.finally(() => {
          this.state.batchDepth--;
          if (this.state.batchDepth === 0) {
            this.flushUpdates();
          }
        });
      } else {
        this.state.batchDepth--;
        if (this.state.batchDepth === 0) {
          this.flushUpdates();
        }
        return result;
      }
    } catch (error) {
      this.state.batchDepth--;
      if (this.state.batchDepth === 0) {
        this.flushUpdates();
      }
      throw error;
    }
  },

  generateId() {
    return 'reactive_' + Math.random().toString(36).substr(2, 9);
  }
};

if (window.Now?.registerManager) {
  Now.registerManager('reactive', ReactiveManager);
}

// Expose globally
window.ReactiveManager = ReactiveManager;
