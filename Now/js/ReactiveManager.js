/**
 * ReactiveManager
 *
 * The reactivity core of Now.js — objects are wrapped in a Proxy that records which
 * effect read which property (track), and a write wakes only the effects concerned
 * (trigger). It provides `reactive`, `computed`, `effect` and `watch`, and ties
 * component state to the lifecycle.
 *
 * Updates are batched through `queueMicrotask` and flushed once, so many writes
 * in the same tick render a single time.
 *
 * Garbage collection works at two levels with separate jobs:
 * - `sweepDeadWatchers()` sweeps dead watchers system-wide, on a timer
 * - `cleanup(computation)` releases one computation’s dependencies before it reruns
 */
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
    cleanedEffects: new WeakSet(),
    batchDepth: 0,
    cleanupTimer: null
  },

  /**
   * Set up the manager and start the periodic sweeper.
   *
   * @param {Object} [options={}] - Values merged over the config.
   * @returns {Promise<Object>} - The manager itself, for chaining.
   */
  async init(options = {}) {
    this.config = {...this.config, ...options};

    this.startCleanup();

    return this;
  },

  /**
   * Record that the running effect read this property.
   *
   * Does nothing when no effect is running, so reading outside an effect leaves no
   * dependency behind.
   *
   * @param {Object} target - The object being read.
   * @param {string} prop - The property being read.
   * @returns {void}
   */
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

  /**
   * Wake every effect that depends on the property just written.
   *
   * @param {Object} target - The object being written.
   * @param {string} prop - The property being written.
   * @returns {void}
   */
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

  /**
   * Wake every registered effect that is still active.
   *
   * @returns {void}
   */
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

  /**
   * Run several effects, queued or immediately depending on the config.
   *
   * @param {Iterable<Function>} effects - The effects to run.
   * @returns {void}
   */
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

  /**
   * Run one effect, routing any error into ErrorManager.
   *
   * @param {Function} effect - The effect to run.
   * @returns {void}
   */
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

  /**
   * Schedule the update queue to flush on the next microtask.
   *
   * A guard flag prevents double scheduling, so many writes in one tick flush once.
   *
   * @returns {void}
   */
  scheduleUpdate() {
    if (!this.state.updateQueued) {
      this.state.updateQueued = true;
      queueMicrotask(() => {
        this.flushUpdates();
      });
    }
  },

  /**
   * Run every effect waiting in the queue, then clear it.
   *
   * @returns {void}
   */
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

  /**
   * Wrap an object to make it reactive.
   *
   * Non-objects are returned unchanged; objects get a `__reactiveId` used as the key
   * in the dependency table.
   *
   * @param {Object} target - The object to make reactive.
   * @returns {Proxy|*} - The wrapping Proxy, or the value itself when not an object.
   */
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

  /**
   * Run a function, and run it again whenever a value it read changes.
   *
   * @param {Function} fn - The function to track.
   * @returns {Function} - The effect; set `.active = false` to stop it.
   */
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

  /**
   * Check whether a value is reactive.
   *
   * @param {*} value - The value to check.
   * @returns {boolean} - true when it is reactive.
   */
  isReactive(value) {
    return Boolean(value && value.__isReactive);
  },

  /**
   * Derive a value from state and cache it until a source value changes.
   *
   * Computed lazily — recalculated only on the first read after being marked dirty.
   *
   * @param {Function} getter - The function returning the value.
   * @returns {Object} - An object with `.value`; read it for the latest value.
   */
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

  /**
   * Watch a value. Two shapes are accepted.
   *
   * A function as the first argument becomes `watchEffect(fn, callback)`;
   * an object becomes `watchProp(target, prop, callback)`.
   *
   * @param {Function|Object} arg1 - The function to track, or the target object.
   * @param {Function|string} arg2 - The callback, or the property name.
   * @param {Function|Object} [arg3] - The callback, or options.
   * @returns {Function} - A function that stops watching.
   */
  watch(arg1, arg2, arg3) {
    if (typeof arg1 === 'function') {
      return this.watchEffect(arg1, arg2);
    }

    return this.watchProp(arg1, arg2, arg3);
  },

  /**
   * Watch a function’s result and call the callback when it changes.
   *
   * @param {Function} fn - The function returning the watched value.
   * @param {Function} callback - Called when the result changes.
   * @returns {Function} - A function that stops watching.
   */
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

  /**
   * Watch a single property of an object.
   *
   * @param {Object} target - The target object.
   * @param {string} prop - The property to watch.
   * @param {Function} callback - Called when the value changes.
   * @param {Object} [options={}] - Extra options.
   * @returns {Function} - A function that stops watching.
   */
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

  /**
   * Check whether an object is already wrapped by this manager’s Proxy.
   *
   * @param {*} obj - The value to check.
   * @returns {boolean} - true when it is a reactive Proxy.
   */
  isProxy(obj) {
    return Boolean(obj && obj.__isReactive);
  },

  /**
   * Queue an effect, or run it immediately when batchUpdates is off.
   *
   * @param {Function} effect - The effect to run.
   * @returns {void}
   */
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

  /**
   * Read a value from state by dot path.
   *
   * @param {Object} state - The state to read.
   * @param {string} path - A dot path such as `user.name`.
   * @returns {*} - The value at that path, or undefined.
   */
  getStateValue(state, path) {
    return path.split('.').reduce((obj, key) => obj?.[key], state);
  },

  /**
   * Watch a whole object, nested values included.
   *
   * Comparison snapshots through `JSON.parse(JSON.stringify())` every time, so it
   * **cannot be used on objects with circular references**, and is expensive on
   * large structures.
   *
   * @param {Object} target - The object to watch.
   * @param {Function} callback - Called when anything inside changes.
   * @param {Object} [options={}] - Extra options.
   * @returns {Function} - A function that stops watching.
   */
  watchDeep(target, callback, options = {}) {
    return this.watch(
      () => JSON.parse(JSON.stringify(target)),
      callback,
      {...options, deep: true}
    );
  },

  /**
   * Write a value into state by dot path.
   *
   * The whole path is refused when any segment is `__proto__`, `constructor` or
   * `prototype`, to block prototype pollution. The value is deep-cloned first, so a
   * caller mutating the original afterwards does not touch the state.
   *
   * @param {Object} state - The state to write into.
   * @param {string} path - The dot path.
   * @param {*} value - The value to write.
   * @returns {void}
   */
  setStateValue(state, path, value) {
    const clonedValue = this.deepClone(value);
    const parts = path.split('.');
    // Prototype-pollution guard: refuse paths that traverse/write dangerous keys.
    if (parts.some(key => key === '__proto__' || key === 'constructor' || key === 'prototype')) {
      return;
    }
    const lastKey = parts.pop();
    const target = parts.reduce((obj, key) => obj[key], state);
    if (target) {
      target[lastKey] = clonedValue;
    }
  },

  /**
   * Detach the events bound to a component.
   *
   * @param {Object} component - The component to unbind.
   * @returns {void}
   */
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

  /**
   * Run a function as the current effect, so `track` can collect dependencies.
   *
   * The previous effect is saved and restored afterwards, so calls can nest.
   *
   * @param {Function} fn - The function to run while collecting dependencies.
   * @returns {*} - Whatever fn returned.
   */
  runWithTracking(fn) {
    const prevEffect = this.state.currentEffect;
    this.state.currentEffect = fn;

    try {
      return fn();
    } finally {
      this.state.currentEffect = prevEffect;
    }
  },

  /**
   * Start the periodic sweeper and hook it to beforeunload and visibilitychange.
   *
   * All three call sites go to `sweepDeadWatchers()`. The timer stops while the
   * page is hidden and starts again when it becomes visible.
   *
   * @returns {void}
   */
  startCleanup() {
    this.state.cleanupTimer = setInterval(() => {
      this.sweepDeadWatchers();
    }, this.config.cleanupInterval);

    window.addEventListener('beforeunload', () => {
      this.sweepDeadWatchers();
      clearInterval(this.state.cleanupTimer);
    });

    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'hidden') {
        this.sweepDeadWatchers();
        clearInterval(this.state.cleanupTimer);
        this.state.cleanupTimer = null;
      } else if (!this.state.cleanupTimer) {
        this.state.cleanupTimer = setInterval(() => {
          this.sweepDeadWatchers();
        }, this.config.cleanupInterval);
      }
    });
  },

  /**
   * Sweep every dead watcher across the whole dependency table in one pass.
   *
   * Keys whose watchers have all gone are dropped, and a target left with no
   * keys is dropped too, so dead dependencies do not pile up for the lifetime
   * of the page.
   *
   * @returns {void}
   */
  sweepDeadWatchers() {
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
  },

  /**
   * Check whether a watcher is still referenced in the dependency table.
   *
   * @param {Function} watcher - The watcher to check.
   * @returns {boolean} - true while it is still referenced.
   */
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

  /**
   * Mark an effect as cleaned up.
   *
   * @param {Function} effect - The effect to clean up.
   * @returns {void}
   */
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

  /**
   * Check that the state is a usable object.
   *
   * @param {*} state - The value to check.
   * @returns {void}
   * @throws {Error} - When it is not an object.
   */
  validateState(state) {
    if (!state || typeof state !== 'object') {
      throw new Error('Invalid state object');
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

  /**
   * Create a component’s reactive state with a Proxy.
   *
   * The state is copied before wrapping, so the instance’s original is untouched.
   *
   * @param {Object} instance - component instance
   * @returns {Proxy} - The Proxy-wrapped state.
   */
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

  /**
   * Release a computation’s dependencies.
   *
   * A thin wrapper around `cleanup(computation)`.
   *
   * @param {Object} computation - The computation to release.
   * @returns {void}
   */
  cleanupDependencies(computation) {
    if (!computation) return;
    this.cleanup(computation);
  },

  /**
   * Check whether a value can be iterated with for...of.
   *
   * @param {*} value - The value to check.
   * @returns {boolean} - true when it has Symbol.iterator.
   */
  isIterable(value) {
    return value != null && typeof value[Symbol.iterator] === 'function';
  },

  /**
   * Turn dependencies into an array, whether the source is a Set, an array or empty.
   *
   * @param {Set|Array|null} deps - The source dependencies.
   * @returns {Array} - The dependencies as an array.
   */
  getDependenciesArray(deps) {
    if (!deps) return [];
    if (this.isIterable(deps)) return Array.from(deps);
    if (Array.isArray(deps)) return deps;
    return [];
  },

  /**
   * Check whether this is a usable computation.
   *
   * Must be an object whose `fn` is a function and whose `dependencies` is a Set.
   *
   * @param {*} computation - The value to check.
   * @returns {boolean} - true when it is usable.
   */
  isValidComputation(computation) {
    return computation &&
      typeof computation === 'object' &&
      typeof computation.fn === 'function' &&
      computation.dependencies instanceof Set;
  },

  /**
   * Create a computation record, validating the arguments.
   *
   *
   * @param {Function} fn - The computation’s function.
   * @param {Object} context - The context bound to the computation.
   * @returns {Object} - computation record
   * @throws {Error} - When fn is not a function.
   */
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

  /**
   * Release one computation from every dependency table it ever read.
   *
   * Call it before rerunning a computation so stale dependencies do not linger.
   * Sweeping dead watchers system-wide lives in `sweepDeadWatchers()`.
   *
   * @param {Object} computation - The computation to release.
   * @returns {void}
   */
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

  /**
   * Add a dependency to the running effect.
   *
   * Skipped when the context sets `_skipReactive`.
   *
   * @param {*} dep - The dependency to add.
   * @returns {void}
   */
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

  /**
   * Process a single change record.
   *
   * Skipped when the target sets `_skipReactive`, which avoids a loop while the
   * system writes back into the state itself.
   *
   * @param {Object} change - The change record; must carry `target` and `prop`.
   * @returns {void}
   */
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

  /**
   * Run a computation, collecting the dependencies it reads along the way.
   *
   * It does not consult `context._skipReactive`.
   *
   * @param {Object} computation - The computation to run.
   * @returns {void}
   */
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

  /**
   * Bind a component’s events into the reactive system.
   *
   * Does nothing when the instance has not enabled reactive.
   *
   * @param {Object} instance - component instance
   * @returns {void}
   */
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

  /**
   * Check that the config is a usable object.
   *
   * @param {*} config - The value to check.
   * @returns {void}
   * @throws {Error} - When it is not an object.
   */
  validateConfig(config) {
    if (!config || typeof config !== 'object') {
      throw new Error('Invalid configuration object');
    }
  },

  /**
   * Deep-copy a value, circular references included.
   *
   * Objects already seen are remembered in `seen`, so recursion never runs away.
   *
   * @param {*} value - The value to clone.
   * @param {WeakMap} [seen=new WeakMap()] - Objects already cloned; internal use.
   * @returns {*} - The cloned value.
   */
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

  /**
   * Wrap an array reactively, mutating methods such as push and splice included.
   *
   * @param {Array} array - The array to wrap.
   * @returns {Proxy} - The wrapped array.
   */
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

  /**
   * Turn debug mode on.
   *
   * @returns {void}
   */
  enableDebug() {
    DEBUG.enabled = true;
  },

  /**
   * Turn debug mode off.
   *
   * @returns {void}
   */
  disableDebug() {
    DEBUG.enabled = false;
  },

  /**
   * Summarize the internals for debugging — dependencies, pending queue and effect count.
   *
   * @returns {Object} - The internal state.
   */
  getDebugInfo() {
    return {
      dependencies: Array.from(this.state.dependencies.entries()),
      pendingUpdates: Array.from(this.state.pendingUpdates),
      effects: this.state.effects.size
    };
  },

  /**
   * React to a component lifecycle event such as mount or unmount.
   *
   * @param {Object} component - The component the event belongs to.
   * @param {string} event - The lifecycle event name.
   * @returns {void}
   */
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

  /**
   * Make a component’s state reactive and bind the watchers it declares.
   *
   * Does nothing when the component has not set `reactive`.
   *
   * @param {Object} component - The component to set up.
   * @returns {void}
   */
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

  /**
   * Release a component’s state and dependencies from the tables.
   *
   * @param {Object} component - The component being destroyed.
   * @returns {void}
   */
  cleanupComponentReactivity(component) {
    if (!component.id) return;

    const componentDeps = Array.from(this.state.dependencies.values())
      .filter(deps => this.isComponentDependency(deps, component));

    componentDeps.forEach(deps => {
      this.cleanupDependencies(deps);
    });
  },

  /**
   * Clear and rebuild a component’s reactivity from scratch.
   *
   * @param {Object} component - The component to rebuild.
   * @returns {void}
   */
  updateComponentReactivity(component) {
    this.cleanupComponentReactivity(component);

    this.setupComponentReactivity(component);
  },

  /**
   * Check whether any watcher in a dependency set belongs to this component.
   *
   * @param {Map} deps - The dependency table.
   * @param {Object} component - The component to check for.
   * @returns {boolean} - true when found.
   */
  isComponentDependency(deps, component) {
    return Array.from(deps.values()).some(watchers =>
      Array.from(watchers).some(watcher =>
        watcher.component === component
      )
    );
  },

  /**
   * Batch several writes so effects wake once at the end.
   *
   * Both plain and Promise-returning functions work — the latter decrements the batch
   * depth in `finally`, so awaiting never leaves a batch stuck open.
   *
   * @param {Function} fn - The function performing the writes.
   * @returns {*} - Whatever fn returned.
   */
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

  /**
   * Generate an id for a reactive object.
   *
   * @returns {string} - An id prefixed with `reactive_`.
   */
  generateId() {
    return 'reactive_' + Math.random().toString(36).substr(2, 9);
  }
};

if (window.Now?.registerManager) {
  Now.registerManager('reactive', ReactiveManager);
}

// Expose globally
window.ReactiveManager = ReactiveManager;
