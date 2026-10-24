/**
 * ErrorManager
 *
 * Central place for turning anything thrown or returned as an error into a real
 * Error object, logging it, and optionally surfacing it to the user. Keeps the
 * most recent error on `lastError` so callers can inspect it after the fact.
 */
const ErrorManager = {
  config: {
    debug: true,
    notificationDuration: 5000
  },

  lastError: null,

  /**
   * Merge options into the module configuration.
   *
   * @param {Object} [options={}] - Overrides for `config`, e.g. `debug` and `notificationDuration`.
   * @returns {Object} - The manager itself, so calls can be chained.
   */
  init(options = {}) {
    this.config = {...this.config, ...options};
    return this;
  },

  /**
   * Build an Error from a message template, substituting positional placeholders.
   *
   * Placeholders are written as `{0}`, `{1}` and are replaced by the matching
   * entry in `additional`. Entries that are `undefined` or `null` are left alone.
   *
   * @param {string} message - Message template.
   * @param {Array} [additional] - Values substituted into the placeholders. Non-arrays are ignored.
   * @returns {Error} - The constructed error.
   */
  createError(message, additional) {
    if (!Array.isArray(additional)) {
      additional = [];
    }
    additional.forEach((param, index) => {
      if (param !== undefined && param !== null) {
        message = message.replace(`{${index}}`, param.toString());
      }
    });

    return new Error(message);
  },

  /**
   * Ensure a value is an Error instance.
   *
   * @param {Error|string} error - An Error, or a message to wrap in one.
   * @returns {Error} - The value unchanged, or a new Error built from the string.
   */
  normalizeError(error) {
    if (typeof error === 'string') {
      return this.createError(error);
    }
    return error;
  },

  /**
   * Normalise, record, log and optionally announce an error.
   *
   * Logs to the console when `config.debug` or `options.debug` is set, shows a
   * notification when `notify` is true and NotificationManager is available, and
   * emits an event through EventManager when `options.type` is given. The error
   * is stored on `lastError` either way.
   *
   * @param {Error|string|Object} error - The error to handle. Required.
   * @param {Object|string} [options={}] - Options, or a string used as `context`.
   * @param {string} [options.context=''] - Where the error came from.
   * @param {boolean} [options.notify=false] - Show a notification to the user.
   * @param {string} [options.logLevel='error'] - Console and notification level.
   * @param {Array} [options.data=[]] - Extra data included in the log and event.
   * @param {string} [options.type] - Event name emitted through EventManager.
   * @returns {Error} - The normalised error.
   * @throws {Error} - When `error` is empty.
   */
  handle(error, options = {}) {
    if (!error) {
      throw new Error('Error parameter is required');
    }

    if (typeof options === 'string') {
      options = {context: options};
    }

    let normalizedError;
    if (error instanceof Error) {
      normalizedError = error;
    } else if (options.error && options.error instanceof Error) {
      normalizedError = options.error;
      normalizedError.message = error.toString();
    } else {
      normalizedError = new Error(error.toString());
    }

    this.lastError = normalizedError;

    const {
      context = '',
      notify = false,
      logLevel = 'error',
      data = []
    } = options;

    const message = normalizedError.message;

    if (this.config.debug || options.debug) {
      if (this.config.debug) {
        console[logLevel](normalizedError.stack, {
          message,
          context,
          ...data,
        });
      } else {
        console[logLevel](`[${context}] ${message}`);
      }
    }

    if (notify && typeof window !== 'undefined' && window.NotificationManager) {
      const level = NotificationManager[logLevel] ? logLevel : 'error';
      NotificationManager[level](message, {
        duration: this.config.notificationDuration
      });
    }

    if (options.type) {
      EventManager.emit(options.type, {
        message,
        context,
        data
      });
    }

    return normalizedError;
  }
};

if (window.Now?.registerManager) {
  Now.registerManager('error', ErrorManager);
}

window.ErrorManager = ErrorManager;
