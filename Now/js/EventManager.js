/**
 * EventManager
 *
 * The app-wide event bus. Beyond plain publish/subscribe it supports:
 * - **Namespaced events** — emitting `a.b.c` also notifies `a.b` and `a`, so a
 *   listener can subscribe at whatever level of detail it cares about.
 * - **Pattern listeners** — wildcards like `user.*` match many event names.
 * - **Groups** — related listeners registered under one name, emitted together.
 * - **Middleware** — `beforeEmit` hooks that can inspect or veto an emission.
 * - **Async listeners** — awaited with a timeout, so one slow handler cannot
 *   stall the bus indefinitely.
 *
 * A periodic cleanup pass drops stale listeners in batches, keeping long-lived
 * pages from accumulating dead subscriptions.
 */
const EventManager = {
  config: {
    asyncTimeout: 5000,
    maxListeners: 2048,
    traceEvents: false,
    cleanup: {
      enabled: true,
      interval: 60000,
      maxAge: 1800000,
      batchSize: 100
    }
  },

  state: {
    events: new Map(),
    groups: new Map(),
    patterns: new Set(),
    listenerCounts: new Map(),
    middleware: [],
    history: [],
    cleanupTimer: null,
    lastCleanup: Date.now()
  },

  /**
   * Set up the bus and start the periodic cleanup timer when enabled.
   *
   * @param {Object} [options={}] - Overrides merged into the module config.
   * @returns {Promise<Object>} - The manager itself, so calls can be chained.
   */
  async init(options = {}) {
    this.config = {...this.config, ...options};

    if (this.config.cleanup.enabled) {
      this.startCleanup();
    }

    return this;
  },

  /**
   * Remove pattern listeners.
   *
   * Without an identifier every listener on that pattern is removed.
   *
   * @param {string} pattern - The pattern, as its string form.
   * @param {Function|string} [identifier=null] - Specific listener to remove.
   * @returns {boolean} - True when at least one was removed.
   */
  removePatternListener(pattern, identifier = null) {
    let removed = false;

    if (identifier === null) {
      this.state.patterns.forEach((config, regex) => {
        if (regex.toString() === pattern) {
          this.state.patterns.delete(regex);
          removed = true;
        }
      });
      return removed;
    }

    for (const [regex, config] of this.state.patterns.entries()) {
      if (regex.toString() === pattern) {
        if (
          (typeof identifier === 'string' && config.id === identifier) ||
          (typeof identifier === 'function' && config.callback === identifier)
        ) {
          this.state.patterns.delete(regex);
          removed = true;
        }
      }
    }

    return removed;
  },

  /**
   * Subscribe to an event for one emission only.
   *
   * @param {string} event - Event name.
   * @param {Function} callback - Handler, may be async.
   * @param {Object} [options={}] - Same options as `on`, with `once` forced on.
   * @returns {Function} - Unsubscribe function.
   */
  once(event, callback, options = {}) {
    return this.on(event, callback, {...options, once: true});
  },

  /**
   * Every namespace level an event should notify, most specific first.
   *
   * `a.b.c` yields `['a.b.c', 'a.b', 'a']`, which is what makes a listener on
   * `a` hear everything beneath it.
   *
   * @param {string} event - Event name.
   * @returns {Array<string>} - The namespace chain.
   */
  getEventPath(event) {
    const parts = event.split('.');
    const paths = [];

    while (parts.length > 0) {
      paths.push(parts.join('.'));
      parts.pop();
    }

    return paths;
  },

  /**
   * Settle every async listener and report the ones that rejected.
   *
   * Uses `allSettled`, so one failing listener does not prevent the others from
   * completing.
   *
   * @param {Array<Promise>} promises - Listener promises from one emission.
   * @param {Object} context - Emission context, for error reporting.
   * @returns {Promise<void>}
   */
  async processPromises(promises, context) {
    if (promises.length === 0) return;

    try {
      const results = await Promise.allSettled(promises);
      results.forEach(result => {
        if (result.status === 'fulfilled' && result.value !== undefined) {
          context.results.push(result.value);
        } else if (result.status === 'rejected') {
          this.handleListenerError(result.reason, context.event);
        }
      });
    } catch (error) {
      this.handleAsyncError(error, context.event);
    }
  },

  /**
   * Emit an event to its listeners, its parent namespaces, and matching patterns.
   *
   * `beforeEmit` middleware runs first and can veto the whole emission by
   * returning false, in which case no listener is called.
   *
   * @param {string} event - Event name to emit.
   * @param {Object} [data={}] - Payload handed to listeners.
   * @returns {Promise<Array>} - Results from the listeners that ran.
   */
  async emit(event, data = {}) {
    try {
      const shouldContinue = await this.runMiddleware('beforeEmit', {event, data});
      if (shouldContinue === false) return [];

      const eventPath = this.getEventPath(event);

      const context = {
        event,
        data,
        timestamp: Date.now(),
        preventDefault: false,
        stopPropagation: false,
        stopImmediatePropagation: false,
        results: [],
        source: null,
        path: eventPath,
        currentPath: null
      };

      if (this.config.traceEvents) {
        this.addToHistory(context);
      }

      for (const currentEvent of eventPath) {
        if (context.stopPropagation) break;

        await this.emitPatternMatches(currentEvent, context);

        const listeners = this.state.events.get(currentEvent);
        if (listeners && listeners.size > 0) {
          const promises = [];

          for (const listener of listeners) {
            if (context.stopImmediatePropagation) break;

            try {
              if (listener.options.once) {
                listeners.delete(listener);
                this.updateListenerCount(currentEvent, -1);
              }

              const result = await listener.callback(context);

              if (listener.options.async || result instanceof Promise) {
                promises.push(this.handleAsyncListener(
                  Promise.resolve(result),
                  listener,
                  context
                ));
              } else if (result !== undefined) {
                context.results.push(result);
              }

            } catch (error) {
              this.handleListenerError(error, currentEvent, listener);
            }
          }

          await this.processPromises(promises, context);
        }

        for (const [groupName, groupEvents] of this.state.groups) {
          if (groupEvents.has(currentEvent)) {
            const groupListeners = groupEvents.get(currentEvent);
            const promises = [];

            for (const listener of groupListeners) {
              if (context.stopImmediatePropagation) break;

              try {
                if (listener.options.once) {
                  groupListeners.delete(listener);
                  this.updateListenerCount(currentEvent, -1);
                }

                const result = await listener.callback(context);

                if (listener.options.async || result instanceof Promise) {
                  promises.push(this.handleAsyncListener(
                    Promise.resolve(result),
                    listener,
                    context
                  ));
                } else if (result !== undefined) {
                  context.results.push(result);
                }

              } catch (error) {
                this.handleListenerError(error, currentEvent, listener);
              }
            }

            await this.processPromises(promises, context);
          }
        }
      }

      await this.runMiddleware('afterEmit', {event, data, context});

      return context.results;

    } catch (error) {
      this.handleEmitError(error, event);
      return [];
    }
  },

  /**
   * Compile a wildcard pattern into an anchored RegExp.
   *
   * Dots are escaped so they match namespace separators literally; `*` becomes
   * `.*` and `?` becomes `.`.
   *
   * @param {string} pattern - Wildcard pattern, e.g. `user.*`.
   * @returns {RegExp} - The compiled pattern.
   */
  createPattern(pattern) {
    return new RegExp(
      '^' +
      pattern
        .replace(/\./g, '\\.')
        .replace(/\*/g, '.*')
        .replace(/\?/g, '.')
      + '$'
    );
  },

  /**
   * Subscribe to an event.
   *
   * @param {string} event - Event name; may be namespaced (`a.b.c`) or a pattern.
   * @param {Function} callback - Handler, may be async.
   * @param {Object} [options={}] - `priority` orders listeners, `once` auto-removes,
   *   `group` files it under a named group.
   * @returns {Function} - Unsubscribe function.
   * @throws {Error} - When `event` is not a non-empty string or `callback` is not a function.
   */
  on(event, callback, options = {}) {
    try {
      if (!event || typeof event !== 'string') {
        throw new Error('Event name must be a non-empty string');
      }

      if (typeof callback !== 'function') {
        throw new Error('Callback must be a function');
      }

      event = event.trim();

      const listenerOptions = {
        priority: parseInt(options.priority) || 0,
        once: Boolean(options.once),
        async: Boolean(options.async),
        group: options.group || null,
        timeout: options.timeout || this.config.asyncTimeout,
        maxRetries: options.maxRetries || 1,
        onError: options.onError || null
      };

      if (this.isPattern(event)) {
        const pattern = this.createPattern(event);
        const patternListener = {
          pattern,
          callback,
          options: listenerOptions,
          id: Utils.generateUUID(),
          timestamp: Date.now()
        };

        this.state.patterns.add(patternListener);

        return () => {
          this.state.patterns.delete(patternListener);
        };
      }

      const listener = {
        id: Utils.generateUUID(),
        callback,
        options: listenerOptions,
        timestamp: Date.now(),
        retryCount: 0,
        active: true
      };

      if (!this.state.events.has(event)) {
        this.state.events.set(event, new Set());
      }

      const listeners = this.state.events.get(event);

      if (listeners.size >= this.config.maxListeners) {
        if (options.force) {
          this.cleanupStaleListeners(event);
        } else {
          ErrorManager.handle(`Max listeners exceeded for event: ${event}`, {
            context: 'EventManager.on',
            type: 'error:event',
            notify: true
          }
          );
          return null;
        }
      }

      listeners.add(listener);
      this.updateListenerCount(event, 1);

      if (listenerOptions.group) {
        this.addToGroup(listenerOptions.group, event, listener);
      }

      this.sortListeners(event);

      return () => {
        this.off(event, listener.id);
      };

    } catch (error) {
      ErrorManager.handle(error, {
        context: 'EventManager.on',
        type: 'error:event',
        notify: true
      });
      return null;
    }
  },

  /**
   * Unsubscribe from an event.
   *
   * Without an identifier every listener on the event is removed.
   *
   * @param {string} event - Event name.
   * @param {Function|string} [identifier=null] - The callback or its id; omit to remove all.
   * @returns {boolean} - True when at least one listener was removed.
   * @throws {Error} - When `event` is not a non-empty string.
   */
  off(event, identifier = null) {
    try {
      if (!event || typeof event !== 'string') {
        throw new Error('Event name must be a non-empty string');
      }

      event = event.trim();

      if (this.isPattern(event)) {
        return this.removePatternListener(event, identifier);
      }

      const listeners = this.state.events.get(event);
      if (!listeners || listeners.size === 0) {
        return false;
      }

      let removed = false;

      if (identifier === null) {
        const count = listeners.size;
        listeners.clear();
        this.updateListenerCount(event, -count);

        this.state.groups.forEach(groupEvents => {
          if (groupEvents.has(event)) {
            groupEvents.delete(event);
          }
        });

        if (listeners.size === 0) {
          this.state.events.delete(event);
        }

        removed = true;
      } else {
        for (const listener of listeners) {
          if (identifier === listener.id || identifier === listener.callback) {
            listeners.delete(listener);
            this.updateListenerCount(event, -1);

            this.state.groups.forEach(groupEvents => {
              const groupListeners = groupEvents.get(event);
              if (groupListeners?.has(listener)) {
                groupListeners.delete(listener);
                if (groupListeners.size === 0) {
                  groupEvents.delete(event);
                }
              }
            });

            removed = true;
          }
        }

        if (listeners.size === 0) {
          this.state.events.delete(event);
        }
      }

      this.state.groups.forEach((groupEvents, group) => {
        if (groupEvents.size === 0) {
          this.state.groups.delete(group);
        }
      });

      return removed;

    } catch (error) {
      ErrorManager.handle(error, {
        context: 'EventManager.off',
        type: 'error:event',
        notify: true
      });
      return false;
    }
  },

  /**
   * Notify every pattern listener whose pattern matches an event name.
   *
   * @param {string} event - Event name being emitted.
   * @param {Object} context - Emission context handed to listeners.
   * @returns {Promise<void>}
   */
  async emitPatternMatches(event, context) {
    if (!this.state.patterns || this.state.patterns.size === 0) {
      return;
    }

    const promises = [];

    for (const pattern of this.state.patterns.values()) {
      if (context.stopImmediatePropagation) break;

      if (pattern.pattern.test(event)) {
        try {
          if (pattern.options?.once) {
            this.state.patterns.delete(pattern);
          }

          const result = await pattern.callback(context);

          if (pattern.options?.async) {
            promises.push(this.handleAsyncListener(
              Promise.resolve(result),
              pattern,
              context
            ));
          } else if (result instanceof Promise) {
            promises.push(this.handleAsyncListener(result, pattern, context));
          } else if (result !== undefined) {
            context.results.push(result);
          }

        } catch (error) {
          this.handleListenerError(error, event, pattern);
        }
      }
    }

    if (promises.length > 0) {
      try {
        const asyncResults = await Promise.race([
          Promise.all(promises),
          new Promise((_, reject) =>
            setTimeout(() => reject(new Error('Pattern matching timeout')),
              this.config.asyncTimeout)
          )
        ]);
        context.results.push(...asyncResults.filter(r => r !== undefined));
      } catch (error) {
        this.handleAsyncError(error, event);
      }
    }
  },

  /**
   * Subscribe to every event matching a pattern.
   *
   * @param {string|RegExp} pattern - Pattern to match event names against.
   * @param {Function} callback - Handler, may be async.
   * @param {Object} options - Listener options.
   * @returns {Function} - Unsubscribe function.
   */
  addPatternListener(pattern, callback, options) {
    const regex = new RegExp(pattern);
    this.state.patterns.set(regex, {callback, options});
    return () => this.state.patterns.delete(regex);
  },

  /**
   * File a listener under a named group.
   *
   * @param {string} group - Group name.
   * @param {string} event - Event the listener is on.
   * @param {Object} listener - The listener record.
   * @returns {void}
   */
  addToGroup(group, event, listener) {
    if (!this.state.groups.has(group)) {
      this.state.groups.set(group, new Map());
    }
    const groupEvents = this.state.groups.get(group);
    if (!groupEvents.has(event)) {
      groupEvents.set(event, new Set());
    }
    groupEvents.get(event).add(listener);
  },

  /**
   * Remove a listener from a group.
   *
   * @param {string} group - Group name.
   * @param {string} event - Event the listener is on.
   * @param {Object} listener - The listener record.
   * @returns {void}
   */
  removeFromGroup(group, event, listener) {
    const groupEvents = this.state.groups.get(group);
    if (!groupEvents) return;

    const listeners = groupEvents.get(event);
    if (listeners) {
      listeners.delete(listener);
      if (listeners.size === 0) {
        groupEvents.delete(event);
      }
    }

    if (groupEvents.size === 0) {
      this.state.groups.delete(group);
    }
  },

  /**
   * Emit every event registered under a group.
   *
   * @param {string} group - Group name.
   * @param {Object} [data={}] - Payload handed to listeners.
   * @returns {Promise<Array>} - Results from the listeners that ran.
   */
  async emitGroup(group, data = {}) {
    const groupEvents = this.state.groups.get(group);
    if (!groupEvents) return [];

    const results = [];

    for (const [event, listeners] of groupEvents) {
      const context = {
        event,
        data,
        timestamp: Date.now(),
        preventDefault: false,
        stopPropagation: false,
        stopImmediatePropagation: false,
        results: [],
        source: null,
        path: [event],
        currentPath: event
      };

      const promises = [];

      for (const listener of listeners) {
        if (context.stopImmediatePropagation) break;

        try {
          if (listener.options.once) {
            listeners.delete(listener);
            this.updateListenerCount(event, -1);
          }

          const result = await listener.callback(context);

          if (listener.options.async || result instanceof Promise) {
            promises.push(this.handleAsyncListener(
              Promise.resolve(result),
              listener,
              context
            ));
          } else if (result !== undefined) {
            context.results.push(result);
          }

        } catch (error) {
          this.handleListenerError(error, event, listener);
        }
      }

      await this.processPromises(promises, context);
      results.push(...context.results);
    }

    return results;
  },

  /**
   * Register middleware, as a function or an object of hooks.
   *
   * A plain function is treated as a `beforeEmit` hook; an object may implement
   * any of the named hooks.
   *
   * @param {Function|Object} middleware - Middleware to add.
   * @returns {Object} - The manager itself, so calls can be chained.
   */
  use(middleware) {
    if (typeof middleware === 'function') {
      this.state.middleware.push(middleware);
    } else if (typeof middleware === 'object') {
      this.state.middleware.push(middleware);
    }
    return this;
  },

  /**
   * Run every middleware for one hook, stopping at the first veto.
   *
   * A middleware returning false halts the chain and the operation it guards.
   *
   * @param {string} hook - Hook name, e.g. `beforeEmit`.
   * @param {Object} context - Context passed to each middleware.
   * @returns {Promise<boolean>} - False when a middleware vetoed.
   */
  async runMiddleware(hook, context) {
    for (const middleware of this.state.middleware) {
      try {
        if (typeof middleware === 'function') {
          const result = await middleware(context);
          if (result === false) return false;
        } else if (middleware[hook]) {
          const result = await middleware[hook](context);
          if (result === false) return false;
        }
      } catch (error) {
        this.handleMiddlewareError(error, hook);
      }
    }
    return true;
  },

  /**
   * Start the periodic cleanup timer, replacing any already running.
   *
   * @returns {void}
   */
  startCleanup() {
    if (this.state.cleanupTimer) {
      clearInterval(this.state.cleanupTimer);
    }

    this.state.cleanupTimer = setInterval(() => {
      this.cleanup();
    }, this.config.cleanup.interval);

    window.addEventListener('beforeunload', () => {
      this.cleanup();
      clearInterval(this.state.cleanupTimer);
    });

    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'hidden') {
        this.cleanup();
        clearInterval(this.state.cleanupTimer);
      }
    });
  },

  /**
   * Sweep stale listeners, a batch at a time.
   *
   * Stops after `config.cleanup.batchSize` events so a large registry is swept
   * across several passes instead of blocking one long tick.
   *
   * @returns {void}
   */
  cleanup() {
    const now = Date.now();
    let processed = 0;

    for (const [event, listeners] of this.state.events) {
      if (processed >= this.config.cleanup.batchSize) break;

      this.cleanupStaleListeners(event);

      if (listeners.size === 0) {
        this.state.events.delete(event);
        this.state.listenerCounts.delete(event);
      }

      processed++;
    }

    for (const pattern of this.state.patterns) {
      if (processed >= this.config.cleanup.batchSize) break;

      if (pattern.timestamp && now - pattern.timestamp > this.config.cleanup.maxAge) {
        this.state.patterns.delete(pattern);
      }

      processed++;
    }

    while (
      this.state.history.length > 0 &&
      now - this.state.history[0].timestamp > this.config.cleanup.maxAge
    ) {
      this.state.history.shift();
    }

    this.state.lastCleanup = now;
  },

  /**
   * Drop listeners on one event that have gone stale.
   *
   * @param {string} event - Event to sweep.
   * @returns {number} - How many listeners were removed.
   */
  cleanupStaleListeners(event) {
    const listeners = this.state.events.get(event);
    if (!listeners) return;

    const now = Date.now();
    let removed = 0;

    for (const listener of listeners) {
      if (now - listener.timestamp > this.config.cleanup.maxAge) {
        listeners.delete(listener);
        removed++;
      }
    }

    if (removed > 0) {
      this.updateListenerCount(event, -removed);
    }
  },

  /**
   * Adjust the tracked listener count for an event.
   *
   * The entry is dropped entirely at zero, so the count map does not grow with
   * events that no longer have listeners.
   *
   * @param {string} event - Event name.
   * @param {number} delta - Change to apply, positive or negative.
   * @returns {void}
   */
  updateListenerCount(event, delta) {
    const current = this.state.listenerCounts.get(event) || 0;
    const newCount = Math.max(0, current + delta);

    if (newCount === 0) {
      this.state.listenerCounts.delete(event);
    } else {
      this.state.listenerCounts.set(event, newCount);
    }
  },

  /**
   * Record an emission in the debug history, with a captured stack.
   *
   * @param {Object} context - Emission context.
   * @returns {void}
   */
  addToHistory(context) {
    this.state.history.push({
      ...context,
      stack: new Error().stack
    });

    while (this.state.history.length > 100) {
      this.state.history.shift();
    }
  },

  /**
   * Re-sort an event's listeners so higher `priority` runs first.
   *
   * @param {string} event - Event whose listeners should be sorted.
   * @returns {void}
   */
  sortListeners(event) {
    const listeners = this.state.events.get(event);
    if (!listeners) return;

    const sorted = Array.from(listeners).sort((a, b) =>
      b.options.priority - a.options.priority
    );

    this.state.events.set(event, new Set(sorted));
  },

  /**
   * The immediate parent namespace of an event.
   *
   * @param {string} event - Event name.
   * @returns {string|null} - The parent, or null for a top-level name.
   */
  getParentEvent(event) {
    const parts = event.split('.');
    if (parts.length > 1) {
      return parts.slice(0, -1).join('.');
    }
    return null;
  },

  /**
   * Whether an event name is a wildcard pattern rather than a literal name.
   *
   * Recognises `*`, `?`, `+` and `|`.
   *
   * @param {string} str - Event name to test.
   * @returns {boolean} - True when it should be treated as a pattern.
   */
  isPattern(str) {
    return str.includes('*') || str.includes('?') ||
      str.includes('+') || str.includes('|');
  },

  /**
   * Await an async listener, failing it if it exceeds `config.asyncTimeout`.
   *
   * Uses `Promise.race` against a timeout, so one hung handler cannot block the
   * emission forever.
   *
   * @param {Promise} promise - The listener's returned promise.
   * @param {Object} listener - The listener record, for error reporting.
   * @param {Object} context - Emission context.
   * @returns {Promise<*>} - The listener's result.
   */
  async handleAsyncListener(promise, listener, context) {
    try {
      const result = await Promise.race([
        promise,
        new Promise((_, reject) =>
          setTimeout(() => reject(new Error('Async handler timeout')),
            listener.options.timeout || this.config.asyncTimeout)
        )
      ]);

      return result;

    } catch (error) {
      this.handleListenerError(error, context.event, listener);
      return undefined;
    }
  },

  /**
   * Report a listener that threw through ErrorManager.
   *
   * @param {Error} error - The error, if any.
   * @param {string} event - Event being emitted.
   * @param {Object} listener - The listener that failed.
   * @returns {void}
   */
  handleListenerError(error, event, listener) {
    const errorObj = error || new Error('Listener execution failed');

    ErrorManager.handle(errorObj, {
      context: 'EventManager.listener',
      type: 'error:event',
      data: {
        event,
        listenerId: listener.id,
        listenerOptions: listener.options
      },
      notify: true
    });

    return false;
  },

  /**
   * Report a failed or timed-out async listener through ErrorManager.
   *
   * @param {Error} error - The error, if any.
   * @param {string} event - Event being emitted.
   * @returns {void}
   */
  handleAsyncError(error, event) {
    const errorObj = error || new Error('Async execution failed');

    ErrorManager.handle(errorObj, {
      context: 'EventManager.async',
      type: 'error:event',
      data: {
        event,
        timestamp: Date.now()
      },
      notify: true
    });

    return false;
  },

  /**
   * Report a failure during emission through ErrorManager.
   *
   * @param {Error} error - The error, if any.
   * @param {string} event - Event being emitted.
   * @returns {void}
   */
  handleEmitError(error, event) {
    const errorObj = error || new Error('Event emission failed');

    ErrorManager.handle(errorObj, {
      context: 'EventManager.emit',
      type: 'error:event',
      data: {
        event,
        timestamp: Date.now()
      },
      notify: true
    });

    return false;
  },

  /**
   * Report a middleware that threw through ErrorManager.
   *
   * @param {Error} error - The error, if any.
   * @param {string} hook - Hook being run.
   * @returns {void}
   */
  handleMiddlewareError(error, hook) {
    const errorObj = error || new Error('Middleware execution failed');

    ErrorManager.handle(errorObj, {
      context: 'EventManager.middleware',
      type: 'error:event',
      data: {
        hook,
        timestamp: Date.now()
      },
      notify: true
    });

    return false;
  },

  /**
   * Listener count and group membership for one event.
   *
   * @param {string} event - Event name.
   * @returns {Object} - `{name, listenerCount, groups}`.
   */
  getEventInfo(event) {
    const listeners = this.state.events.get(event);
    return {
      name: event,
      listenerCount: listeners?.size || 0,
      groups: Array.from(this.state.groups.entries())
        .filter(([_, events]) => events.has(event))
        .map(([name]) => name),
      patterns: Array.from(this.state.patterns.keys())
        .filter(pattern => pattern.test(event))
        .map(pattern => pattern.toString())
    };
  },

  /**
   * A snapshot of every registered event, for debugging.
   *
   * @returns {Object} - Events with their listener counts and groups.
   */
  getDebugInfo() {
    return {
      events: Array.from(this.state.events.keys()).map(event => ({
        name: event,
        ...this.getEventInfo(event)
      })),

      patterns: Array.from(this.state.patterns.entries()).map(([pattern, config]) => ({
        pattern: pattern.toString(),
        options: config.options
      })),

      groups: Array.from(this.state.groups.entries()).map(([name, events]) => ({
        name,
        eventCount: events.size,
        events: Array.from(events.keys())
      })),

      listenerCounts: Object.fromEntries(this.state.listenerCounts),

      history: this.state.history.slice(-20).map(event => ({
        name: event.event,
        timestamp: event.timestamp,
        hasData: !!event.data,
        prevented: event.preventDefault,
        propagationStopped: event.stopPropagation
      })),

      middleware: {
        count: this.state.middleware.length,
        types: this.state.middleware.map(m =>
          typeof m === 'function' ? 'function' : 'object'
        )
      },

      state: {
        lastCleanup: this.state.lastCleanup,
        historyLength: this.state.history.length
      },

      config: {
        ...this.config,
        onError: this.config.onError ? 'function configured' : 'not configured'
      },

      system: {
        timestamp: Date.now(),
        memoryUsage: performance?.memory ? {
          usedJSHeapSize: performance.memory.usedJSHeapSize,
          totalJSHeapSize: performance.memory.totalJSHeapSize
        } : 'not available'
      }
    };
  },

  /**
   * Remove every listener, group, pattern and history entry.
   *
   * @returns {void}
   */
  clear() {
    this.state.events.clear();
    this.state.groups.clear();
    this.state.patterns.clear();
    this.state.listenerCounts.clear();
    this.state.history = [];
    this.state.middleware = [];
  },

  /**
   * Tear the bus down: stop the cleanup timer and clear everything.
   *
   * @returns {void}
   */
  destroy() {
    if (this.state.cleanupTimer) {
      clearInterval(this.state.cleanupTimer);
    }

    this.clear();

    window.removeEventListener('unload', this.cleanup);
  }
};

if (window.Now?.registerManager) {
  Now.registerManager('event', EventManager);
}

window.EventManager = EventManager;
