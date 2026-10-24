/**
 * WatchManager
 *
 * Runs the `watch` half of Now.js reactivity: given a component instance and its
 * reactive state, it tracks dotted paths and calls back when the value at a path
 * changes. Comparison is structural, so replacing state with an equivalent object
 * does not fire watchers.
 */
const WatchManager = {
  /**
   * Register watcher callbacks against a component's reactive state.
   *
   * Each key in `watchers` is a dotted path into `state`; the callback runs when
   * the value at that path changes. Missing arguments are reported through
   * ErrorManager rather than thrown, so one bad component cannot stop a page.
   *
   * @param {Object} instance - Component instance the watchers belong to.
   * @param {Object} state - Reactive state object being observed.
   * @param {Object} [watchers={}] - Map of dotted path to callback.
   * @returns {void}
   */
  setupWatchers(instance, state, watchers = {}) {
    if (!instance || !state || !watchers) {
      const error = new Error('Missing required parameters: component instance, state object, or watchers');
      ErrorManager.handle(error, {
        context: 'WatchManager.setupWatchers',
        data: {instance, state, watchers}
      });
      return;
    }

    if (!instance._watchers) {
      instance._watchers = new Map();
    }

    Object.entries(watchers).forEach(([path, config]) => {
      try {
        const watchConfig = typeof config === 'function' ? {
          handler: config,
          immediate: false,
          deep: false
        } : {
          immediate: false,
          deep: false,
          ...config
        };

        if (typeof watchConfig.handler !== 'function') {
          throw new Error(`Invalid watcher handler: expected function for path "${path}"`);
        }

        if (instance._watchers.has(path)) return;

        const initialValue = this.getDeepValue(path, state);

        let finalHandler;
        if (watchConfig.debounce > 0) {
          finalHandler = this.debounce(
            function(newVal, oldVal) {
              try {
                watchConfig.handler.call(this, newVal, oldVal);
              } catch (error) {
                ErrorManager.handle(error, {
                  context: 'WatchManager.handler',
                  source: instance.id,
                  notify: true,
                  data: {path}
                });
              }
            },
            watchConfig.debounce
          );
        } else {
          finalHandler = function(newVal, oldVal) {
            try {
              watchConfig.handler.call(this, newVal, oldVal);
            } catch (error) {
              ErrorManager.handle(error, {
                context: 'WatchManager.handler',
                source: instance.id,
                notify: true,
                data: {path}
              });
            }
          };
        }

        const watcher = {
          path,
          handler: finalHandler.bind(instance),
          lastValue: this.deepClone(initialValue),
          config: watchConfig,
          active: true
        };

        instance._watchers.set(path, watcher);

        if (watchConfig.immediate) {
          watcher.handler(initialValue, undefined);
        }

      } catch (error) {
        const newError = new Error(`Failed to setup watcher for "${path}": ${error.message}`);
        ErrorManager.handle(newError, {
          context: 'WatchManager.setupWatchers',
          data: {source: instance.id, state, watchers}
        });
      }
    });

    return true;
  },

  /**
   * Read a nested value by dotted path, including array indexes.
   *
   * Supports `a.b.c` and `list[0].name`. Any missing link in the chain yields
   * `undefined` instead of throwing.
   *
   * @param {string} path - Dotted path, e.g. `user.roles[0].name`.
   * @param {Object} obj - Object to read from.
   * @returns {*} - The value at that path, or `undefined`.
   */
  getDeepValue(path, obj) {
    return path.split('.').reduce((value, key) => {
      if (key.includes('[') && key.includes(']')) {
        const arrayKey = key.split('[')[0];
        const index = parseInt(key.split('[')[1].split(']')[0]);
        return value?.[arrayKey]?.[index];
      }
      return value?.[key];
    }, obj);
  },

  /**
   * Copy a value deeply, tolerating circular references.
   *
   * Primitives are returned as-is. Objects already visited are returned from
   * `seen`, so a structure that points back at itself does not recurse forever.
   *
   * @param {*} obj - Value to clone.
   * @param {WeakMap} [seen=new WeakMap()] - Objects already cloned, used internally.
   * @returns {*} - The cloned value.
   */
  deepClone(obj, seen = new WeakMap()) {
    if (obj === null || typeof obj !== 'object') {
      return obj;
    }

    if (seen.has(obj)) {
      return seen.get(obj);
    }

    if (obj instanceof Date) {
      return new Date(obj);
    }

    if (Array.isArray(obj)) {
      const clone = [];
      seen.set(obj, clone);

      obj.forEach((item, index) => {
        clone[index] = this.deepClone(item, seen);
      });

      return clone;
    }

    const clone = {};
    seen.set(obj, clone);

    Object.entries(obj).forEach(([key, value]) => {
      clone[key] = this.deepClone(value, seen);
    });

    return clone;
  },

  /**
   * Compare two values structurally.
   *
   * Used to decide whether a watched value actually changed, so watchers do not
   * fire when a state object was replaced with an equivalent one.
   *
   * @param {*} a - First value.
   * @param {*} b - Second value.
   * @returns {boolean} - True when the two are structurally equal.
   */
  deepEqual(a, b) {
    if (a === b) return true;

    if (typeof a !== 'object' || typeof b !== 'object') {
      return false;
    }

    if (Array.isArray(a)) {
      if (!Array.isArray(b) || a.length !== b.length) return false;
      return a.every((item, index) => this.deepEqual(item, b[index]));
    }

    const keysA = Object.keys(a);
    const keysB = Object.keys(b);

    if (keysA.length !== keysB.length) return false;

    return keysA.every(key =>
      Object.prototype.hasOwnProperty.call(b, key) &&
      this.deepEqual(a[key], b[key])
    );
  },

  /**
   * Re-read every watched path and run the callbacks whose value changed.
   *
   * @param {Object} instance - Component instance whose watchers should run.
   * @returns {void}
   */
  triggerWatchers(instance) {
    if (!instance?._watchers) return;

    instance._watchers.forEach(watcher => {
      if (!watcher.active) return;

      try {
        let currentValue;
        try {
          currentValue = this.getDeepValue(watcher.path, instance.state);
        } catch (error) {
          ErrorManager.handle(error, {
            context: 'WatchManager.getter',
            data: {source: instance.id, path: watcher.path},
            notify: true
          });
          watcher.active = false;
          return;
        }

        const hasChanged = watcher.config.deep ?
          !this.deepEqual(currentValue, watcher.lastValue) :
          currentValue !== watcher.lastValue;

        if (hasChanged) {
          watcher.handler(
            this.deepClone(currentValue),
            this.deepClone(watcher.lastValue)
          );
          watcher.lastValue = this.deepClone(currentValue);
        }

      } catch (error) {
        ErrorManager.handle(error, {
          context: 'WatchManager.triggerWatchers',
          data: {source: instance.id, path: watcher.path},
          notify: true
        });
        watcher.active = false;
      }
    });
  },

  /**
   * Remove every watcher registered for a component.
   *
   * Call this when the component is destroyed, otherwise its callbacks keep
   * running against state it no longer owns.
   *
   * @param {Object} instance - Component instance being torn down.
   * @returns {void}
   */
  cleanupWatchers(instance) {
    if (!instance?._watchers) return;

    instance._watchers.forEach(watcher => {
      if (watcher.handler.cancel) {
        watcher.handler.cancel();
      }
      watcher.active = false;
    });

    instance._watchers.clear();
  },

  /**
   * Wrap a function so rapid calls collapse into one.
   *
   * @param {Function} func - Function to wrap.
   * @param {number} [wait=100] - Quiet period in milliseconds before it runs.
   * @returns {Function} - The debounced function.
   */
  debounce(func, wait = 100) {
    let timeoutId = null;
    const context = this;

    const debouncedFn = function(...args) {
      if (timeoutId) {
        clearTimeout(timeoutId);
      }
      timeoutId = setTimeout(() => {
        func.apply(context, args);
      }, wait);
    };

    debouncedFn.cancel = function() {
      if (timeoutId) {
        clearTimeout(timeoutId);
        timeoutId = null;
      }
    };

    return debouncedFn;
  }
};

window.WatchManager = WatchManager;
