/**
 * TaskMonitorComponent - watches long-running server tasks and reports when they end
 *
 * The problem it solves: work that outlives the request that asked for it. Any
 * server operation measured in minutes — archiving files, importing data,
 * generating a report, issuing a certificate — cannot finish inside the request
 * that starts it, so the server starts it in the background and answers "202,
 * under way" straight away. From that point the page that pressed the button is
 * no longer the page that sees the result: the tab gets closed, another person
 * opens the same screen, or the browser is simply left alone for an hour.
 *
 * So the answer cannot live in a browser tab. This component asks the server
 * instead, on an interval, and renders what it says.
 *
 * Features:
 * - Automatic initialization via data-component="task-monitor"
 * - No hard-coded API shape — every field is mapped through data attributes
 * - Polls only while something is running, then backs off to an idle interval
 * - Pauses entirely while the tab is hidden, and refreshes on return
 * - Backs off on network errors instead of hammering a dead endpoint
 * - Fires a DOM event (and optional toast) as each task finishes
 * - Renders a default list, or hands the data to a render callback
 *
 * @example
 * // HTML — the shape below matches {"data":{"active":[…]}}
 * <div data-component="task-monitor"
 *      data-endpoint="/api/v2/jobs"
 *      data-items="data.active"
 *      data-label-field="label"
 *      data-status-field="status"></div>
 *
 * @example
 * // JavaScript API
 * const monitor = TaskMonitorComponent.create(element, {
 *   endpoint: '/api/tasks',
 *   interval: 2000,
 *   onFinish: (task) => console.log('done:', task.label)
 * });
 *
 * monitor && TaskMonitorComponent.refresh(monitor);   // after starting a task
 */
const TaskMonitorComponent = {
  /**
   * Default configuration options
   *
   * Every one of these can also be set per element as a data attribute, in
   * kebab-case: `interval` is `data-interval`, `labelField` is
   * `data-label-field`.
   */
  config: {
    // Where to ask
    endpoint: '',

    /**
     * Dot path to the array of tasks inside the response body
     *
     * A path rather than a fixed key, because no two APIs agree on where a
     * list lives — `data.active`, `result.items`, or the body itself (`''`).
     */
    items: 'data.active',

    // Field names inside one task object · nothing about the server's own
    // vocabulary is assumed, only that these five things exist somewhere
    idField: 'id',
    labelField: 'label',
    statusField: 'status',
    messageField: 'message',
    progressField: 'progress',

    /**
     * Status values that mean "still working"
     *
     * Anything not in this list counts as finished, which is deliberate: a
     * status nobody anticipated (`cancelled`, `lost`, a typo in a new server
     * version) stops the poll rather than keeping it running forever.
     */
    runningValues: 'queued,running,pending,active',

    /** Status values that mean it ended badly — used only to colour the row and the toast */
    failedValues: 'failed,error,lost,cancelled',

    // How often to ask, in milliseconds
    interval: 3000,

    /**
     * How often to ask when nothing is running · 0 = stop asking entirely
     *
     * Not the same question as `interval`. A page left open all day with
     * nothing running should not poll every three seconds, but it should
     * notice when somebody *else* starts a task on the same machine.
     */
    idleInterval: 30000,

    /**
     * Give up after this many consecutive failures · 0 = never give up
     *
     * The interval doubles on each failure up to `maxInterval` first, so a
     * server that comes back is picked up again without a reload.
     */
    maxErrors: 0,
    maxInterval: 120000,

    // Rendering
    autoRender: true,          // false = the callbacks do the rendering
    emptyText: '',             // shown when nothing is running · empty = hide the element
    hideWhenIdle: true,        // add [hidden] to the element while nothing is running

    // Event callbacks
    onInit: null,              // (instance) after the first successful poll
    onUpdate: null,            // (tasks, instance) after every successful poll
    onFinish: null,            // (task, instance) once per task, as it stops running
    onError: null,             // (error, instance)
    onRender: null,            // (tasks, instance) replaces the built-in rendering
    onDestroy: null,

    /** Show a toast as each task finishes, when NotificationManager is present */
    notify: true
  },

  /**
   * Component state
   */
  state: {
    instances: new Map(),
    initialized: false,
    observing: false,
    visibilityBound: false
  },

  /**
   * Initialize the component and mount every matching element
   *
   * @param {Object} options - Global configuration overrides
   */
  init(options = {}) {
    if (this.state.initialized) return;

    Object.assign(this.config, options);

    this.initElements();
    this.setupObserver();
    this.setupVisibility();

    this.state.initialized = true;
  },

  /**
   * Mount every `[data-component="task-monitor"]` already in the document
   */
  initElements() {
    document.querySelectorAll('[data-component="task-monitor"]').forEach(element => {
      if (!this.state.instances.has(element)) this.create(element);
    });
  },

  /**
   * Create a monitor on one element
   *
   * @param {HTMLElement} element - The container to render into
   * @param {Object} options - Instance-specific options
   * @returns {Object|null} The instance, or null when it cannot be created
   */
  create(element, options = {}) {
    if (!element) {
      console.error('TaskMonitorComponent.create: element is required');
      return null;
    }

    if (this.state.instances.has(element)) return this.state.instances.get(element);

    const instanceConfig = {
      ...this.config,
      ...this.extractOptionsFromElement(element),
      ...options
    };

    if (!instanceConfig.endpoint) {
      console.error('TaskMonitorComponent: data-endpoint is required');
      return null;
    }

    const instance = {
      element,
      config: instanceConfig,
      tasks: [],
      /**
       * Tasks seen running on the previous poll, by id
       *
       * This is the whole reason the component can say "finished": a task that
       * was in this map and is not in the new list has ended, whether the
       * server still lists it as finished or has dropped it altogether. Without
       * remembering, a task that simply disappears is indistinguishable from
       * one that was never there.
       */
      running: new Map(),
      timer: null,
      errors: 0,
      delay: instanceConfig.interval,
      started: false,
      destroyed: false
    };

    this.state.instances.set(element, instance);

    this.poll(instance);

    return instance;
  },

  /**
   * Read `data-*` attributes into config values
   *
   * Only keys that exist in {@link TaskMonitorComponent.config} are read, so a
   * stray attribute cannot inject a setting the component does not have.
   *
   * @param {HTMLElement} element
   * @returns {Object}
   */
  extractOptionsFromElement(element) {
    const options = {};

    Object.keys(this.config).forEach(key => {
      const attribute = 'data-' + key.replace(/[A-Z]/g, m => '-' + m.toLowerCase());

      if (!element.hasAttribute(attribute)) return;

      const raw = element.getAttribute(attribute);
      const fallback = this.config[key];

      if (typeof fallback === 'number') {
        const value = parseInt(raw, 10);
        if (!Number.isNaN(value)) options[key] = value;
      } else if (typeof fallback === 'boolean') {
        options[key] = raw !== 'false' && raw !== '0';
      } else if (typeof fallback !== 'function') {
        options[key] = raw;
      }
    });

    return options;
  },

  /**
   * Ask the server once, then schedule the next ask
   *
   * @param {Object} instance
   */
  async poll(instance) {
    if (instance.destroyed) return;

    instance.timer = null;

    try {
      const response = await this.request(instance);
      const tasks = this.readTasks(response, instance.config.items);

      instance.errors = 0;
      instance.tasks = tasks;

      this.reconcile(instance, tasks);
      this.render(instance);

      if (typeof instance.config.onUpdate === 'function') {
        instance.config.onUpdate.call(instance, tasks, instance);
      }

      if (!instance.started) {
        instance.started = true;
        if (typeof instance.config.onInit === 'function') {
          instance.config.onInit.call(instance, instance);
        }
      }

      /*
       * Ask again sooner while something is running and later when nothing is.
       * `idleInterval` of 0 means stop: a page that only ever reacts to its own
       * button has no reason to keep asking once that button's work is done.
       */
      const busy = tasks.length > 0;
      instance.delay = busy ? instance.config.interval : instance.config.idleInterval;

      if (instance.delay > 0) this.schedule(instance, instance.delay);
    } catch (error) {
      this.handleError(instance, error);
    }
  },

  /**
   * One HTTP request, through the framework client when it is present
   *
   * `window.http` carries the session, CSRF token and error handling the rest
   * of the application already relies on. `fetch` is the fallback for a page
   * that loaded this file on its own.
   *
   * @param {Object} instance
   * @returns {Promise<Object>} the parsed response body
   */
  async request(instance) {
    const url = instance.config.endpoint;

    if (typeof window !== 'undefined' && window.http && typeof window.http.get === 'function') {
      const response = await window.http.get(url);

      if (response && response.success === false) {
        throw new Error(response.statusText || 'Request failed');
      }

      return response ? response.data : null;
    }

    const response = await fetch(url, {
      headers: {Accept: 'application/json'},
      credentials: 'same-origin'
    });

    if (!response.ok) throw new Error('HTTP ' + response.status);

    return response.json();
  },

  /**
   * Pull the task array out of a response body
   *
   * A body that holds no array at the given path yields an empty list rather
   * than an error: "nothing is running" is a perfectly ordinary answer, and
   * treating it as a failure would start the error backoff on a healthy server.
   *
   * @param {*} body
   * @param {string} path - dot path · empty string = the body itself
   * @returns {Array<Object>}
   */
  readTasks(body, path) {
    let value = body;

    if (path) {
      for (const key of String(path).split('.')) {
        if (value === null || typeof value !== 'object') return [];
        value = value[key];
      }
    }

    return Array.isArray(value) ? value : [];
  },

  /**
   * Compare this poll against the last one and announce what ended
   *
   * @param {Object} instance
   * @param {Array<Object>} tasks
   */
  reconcile(instance, tasks) {
    const running = new Map();
    const seen = new Map();

    tasks.forEach(task => {
      const id = this.field(task, instance.config.idField);
      if (id === null || id === undefined) return;

      seen.set(String(id), task);
      if (this.isRunning(instance, task)) running.set(String(id), task);
    });

    instance.running.forEach((previous, id) => {
      if (running.has(id)) return;

      // Either the server now reports it finished, or it dropped off the list
      // entirely · both mean the same thing to whoever is watching
      this.finished(instance, seen.get(id) || previous);
    });

    instance.running = running;
  },

  /**
   * One task has stopped running
   *
   * @param {Object} instance
   * @param {Object} task
   */
  finished(instance, task) {
    const label = String(this.field(task, instance.config.labelField) ?? '');
    const message = String(this.field(task, instance.config.messageField) ?? '');
    const failed = this.isFailed(instance, task);

    /*
     * A DOM event rather than only a callback, so a page can react without
     * having a reference to the instance — refreshing a table, closing a
     * dialog, or re-enabling a button somewhere else on the screen entirely.
     */
    instance.element.dispatchEvent(new CustomEvent('task-monitor:finished', {
      bubbles: true,
      detail: {task, label, message, failed, instance}
    }));

    if (instance.config.notify && typeof window !== 'undefined' && window.NotificationManager) {
      const text = label && message ? `${label} — ${message}` : (label || message);

      if (text) {
        window.NotificationManager[failed ? 'error' : 'success'](text);
      }
    }

    if (typeof instance.config.onFinish === 'function') {
      instance.config.onFinish.call(instance, task, instance);
    }
  },

  /**
   * Render the current tasks into the element
   *
   * @param {Object} instance
   */
  render(instance) {
    if (typeof instance.config.onRender === 'function') {
      instance.config.onRender.call(instance, instance.tasks, instance);
      return;
    }

    if (!instance.config.autoRender) return;

    const element = instance.element;
    const tasks = instance.tasks;

    if (tasks.length === 0) {
      element.innerHTML = instance.config.emptyText
        ? `<div class="task-monitor-empty">${this.escape(instance.config.emptyText)}</div>`
        : '';

      // `hidden` rather than a style, so a page's own CSS decides how it looks
      if (instance.config.hideWhenIdle) element.hidden = true;

      return;
    }

    element.hidden = false;
    element.innerHTML = '<ul class="task-monitor-list">' + tasks.map(task => {
      const label = this.escape(String(this.field(task, instance.config.labelField) ?? ''));
      const status = this.escape(String(this.field(task, instance.config.statusField) ?? ''));
      const message = this.escape(String(this.field(task, instance.config.messageField) ?? ''));
      const progress = this.field(task, instance.config.progressField);
      const failed = this.isFailed(instance, task);

      /*
       * State lives in a modifier class of this component's own, never in a
       * class borrowed from an application's stylesheet — a framework component
       * that renders `pill-danger` looks correct in the one project that
       * happens to define it and unstyled everywhere else.
       */
      const tone = failed ? 'is-failed' : (this.isRunning(instance, task) ? 'is-running' : 'is-done');

      return `<li class="task-monitor-item ${tone}">`
        + `<span class="task-monitor-status">${status}</span>`
        + `<span class="task-monitor-label">${label}</span>`
        + (Number.isFinite(Number(progress)) && progress !== null && progress !== ''
          ? `<progress class="task-monitor-progress" max="100" value="${Number(progress)}"></progress>`
          : '')
        + (message ? `<span class="task-monitor-message">${message}</span>` : '')
        + '</li>';
    }).join('') + '</ul>';
  },

  /**
   * Ask again right now, whatever the schedule said
   *
   * What a page calls straight after starting a task, so the new one appears
   * without waiting out an idle interval.
   *
   * @param {Object|HTMLElement} target - an instance or the element it is on
   */
  refresh(target) {
    const instance = target && target.element ? target : this.state.instances.get(target);

    if (!instance || instance.destroyed) return;

    if (instance.timer) {
      clearTimeout(instance.timer);
      instance.timer = null;
    }

    instance.delay = instance.config.interval;
    this.poll(instance);
  },

  /**
   * Stop polling and forget the instance
   *
   * @param {Object|HTMLElement} target
   */
  destroy(target) {
    const instance = target && target.element ? target : this.state.instances.get(target);

    if (!instance) return;

    if (instance.timer) clearTimeout(instance.timer);

    instance.timer = null;
    instance.destroyed = true;

    this.state.instances.delete(instance.element);

    if (typeof instance.config.onDestroy === 'function') {
      instance.config.onDestroy.call(instance, instance);
    }
  },

  /**
   * A failed poll — back off, and give up only if told to
   *
   * Doubling the wait matters more than it looks: a monitor left open against a
   * server that is restarting would otherwise send a request every few seconds
   * for as long as the tab stays open, which is the shape of a self-inflicted
   * denial of service on the very machine that is already struggling.
   *
   * @param {Object} instance
   * @param {Error} error
   */
  handleError(instance, error) {
    instance.errors++;

    if (typeof instance.config.onError === 'function') {
      instance.config.onError.call(instance, error, instance);
    }

    if (instance.config.maxErrors > 0 && instance.errors >= instance.config.maxErrors) {
      this.destroy(instance);
      return;
    }

    instance.delay = Math.min(
      Math.max(instance.delay, instance.config.interval) * 2,
      instance.config.maxInterval
    );

    this.schedule(instance, instance.delay);
  },

  /**
   * @param {Object} instance
   * @param {number} delay
   */
  schedule(instance, delay) {
    if (instance.destroyed || instance.timer) return;

    instance.timer = setTimeout(() => this.poll(instance), delay);
  },

  /**
   * Mount and unmount as elements come and go
   *
   * Uses the framework's shared observer when it is loaded, which batches every
   * component's DOM work into one pass instead of each one running its own
   * MutationObserver over the whole document.
   */
  setupObserver() {
    if (this.state.observing) return;

    const selector = '[data-component="task-monitor"]';

    if (typeof window !== 'undefined' && window.CoreObserver) {
      window.CoreObserver.onAdd(selector, element => this.create(element));
      window.CoreObserver.onRemove(selector, element => this.destroy(element));
      this.state.observing = true;

      return;
    }

    const observer = new MutationObserver(mutations => {
      mutations.forEach(mutation => {
        mutation.addedNodes.forEach(node => {
          if (node.nodeType !== 1) return;
          if (node.matches && node.matches(selector)) this.create(node);
          if (node.querySelectorAll) node.querySelectorAll(selector).forEach(el => this.create(el));
        });

        mutation.removedNodes.forEach(node => {
          if (node.nodeType !== 1) return;
          if (node.matches && node.matches(selector)) this.destroy(node);
          if (node.querySelectorAll) node.querySelectorAll(selector).forEach(el => this.destroy(el));
        });
      });
    });

    observer.observe(document.body, {childList: true, subtree: true});
    this.state.observing = true;
  },

  /**
   * Stop polling while the tab is hidden, and catch up when it comes back
   *
   * A background tab kept polling is work nobody can see: multiplied by every
   * open tab and every user, it is a load the server pays for continuously in
   * exchange for nothing. Coming back to the tab asks immediately, so the
   * screen is never stale for longer than it takes to answer one request.
   */
  setupVisibility() {
    if (this.state.visibilityBound || typeof document === 'undefined') return;

    document.addEventListener('visibilitychange', () => {
      this.state.instances.forEach(instance => {
        if (instance.destroyed) return;

        if (document.hidden) {
          if (instance.timer) {
            clearTimeout(instance.timer);
            instance.timer = null;
          }

          return;
        }

        this.refresh(instance);
      });
    });

    this.state.visibilityBound = true;
  },

  /**
   * @param {Object} instance
   * @param {Object} task
   * @returns {boolean}
   */
  isRunning(instance, task) {
    return this.inList(instance.config.runningValues, this.field(task, instance.config.statusField));
  },

  /**
   * @param {Object} instance
   * @param {Object} task
   * @returns {boolean}
   */
  isFailed(instance, task) {
    return this.inList(instance.config.failedValues, this.field(task, instance.config.statusField));
  },

  /**
   * @param {string} list - comma-separated values
   * @param {*} value
   * @returns {boolean}
   */
  inList(list, value) {
    if (value === null || value === undefined) return false;

    return String(list).split(',').some(entry => entry.trim() === String(value).trim());
  },

  /**
   * Read one field out of a task, by dot path
   *
   * @param {Object} task
   * @param {string} path
   * @returns {*}
   */
  field(task, path) {
    if (!task || !path) return undefined;

    let value = task;

    for (const key of String(path).split('.')) {
      if (value === null || typeof value !== 'object') return undefined;
      value = value[key];
    }

    return value;
  },

  /**
   * Escape text before it reaches innerHTML
   *
   * Every string rendered here comes from the server, and a task label is very
   * often a filename or a domain somebody else chose — exactly the values a
   * customer can control. Rendered raw, one of them is a stored XSS in an
   * administrator's own screen.
   *
   * @param {string} value
   * @returns {string}
   */
  escape(value) {
    return String(value).replace(/[&<>"']/g, character => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;'
    })[character]);
  }
};

// Auto-initialize when DOM is ready
if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => TaskMonitorComponent.init());
  } else {
    TaskMonitorComponent.init();
  }
}

// Expose globally
if (typeof window !== 'undefined') {
  window.TaskMonitorComponent = TaskMonitorComponent;
}
