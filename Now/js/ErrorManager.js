const ErrorManager = {
  config: {
    debug: true,
    notificationDuration: 5000
  },

  lastError: null,

  init(options = {}) {
    this.config = {...this.config, ...options};
    return this;
  },

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

  normalizeError(error) {
    if (typeof error === 'string') {
      return this.createError(error);
    }
    return error;
  },

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
