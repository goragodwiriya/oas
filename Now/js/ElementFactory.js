class ElementFactory {
  static config = {
    validationMessages: {
      min: 'Value must be at least {min}',
      max: 'Value must be no more than {max}',
      step: 'Value must be a multiple of {step}',
      decimal: 'Only one decimal separator is allowed',
      negative: 'Negative values are not allowed',
      match: 'Fields do not match',
      fileSize: 'File size exceeds the maximum allowed',
      fileType: 'File type is not supported',
      date: 'Please enter a valid date',
      time: 'Please enter a valid time',
      datetime: 'Please enter a valid date and time',
      phone: 'Please enter a valid phone number',
      postcode: 'Please enter a valid postal code',
      generic: 'Invalid value'
    }
  };

  static coreProperties = [
    'id', 'name', 'disabled', 'readOnly', 'checked',
    'className', 'classList', 'style', 'minLength', 'maxLength', 'size',
    'title', 'type', 'inputMode', 'pattern', 'defaultValue'
  ];

  static extendedProperties = [
    'validation', 'formatter'
  ];

  static _privateState = new WeakMap();

  static propertyHandlers = {
    value: {
      get(element) {
        return element.value;
      },
      set(instance, newValue) {
        instance.element.value = newValue;
      }
    },
    required: {
      get(element) {
        return element.hasAttribute('required');
      },
      set(instance, newValue) {
        const {element} = instance;
        if (newValue) {
          element.setAttribute('required', '');
          element.setAttribute('aria-required', 'true');
        } else {
          element.removeAttribute('required');
          element.setAttribute('aria-required', 'false');
        }
      }
    },
    placeholder: {
      get(element) {
        return 'placeholder' in element ? element.placeholder : element.getAttribute('placeholder') || '';
      },
      set(instance, newValue) {
        const {element} = instance;
        if (typeof newValue === 'string' && newValue.trim()) {
          element.dataset.i18n = newValue;
          // Store original template for re-translation on language change
          element.dataset.i18nPlaceholder = newValue;
          if ('placeholder' in element) {
            element.placeholder = Now.translate(newValue);
          } else {
            element.setAttribute('placeholder', Now.translate(newValue));
          }
        } else {
          delete element.dataset.i18n;
          delete element.dataset.i18nPlaceholder;
          if ('placeholder' in element) {
            element.placeholder = '';
          } else {
            element.removeAttribute('placeholder');
          }
        }
      }
    }
  };

  static extractConfig(element, config = {}, def) {
    const dataset = element.dataset;
    const result = {};

    const parseBool = (key) => {
      return def[key] !== undefined ? def[key] :
        dataset[key] !== undefined ? dataset[key] === 'true' :
          key in element ? element[key] : undefined;
    };

    const parseString = (key) => {
      return def[key] !== undefined ? def[key] :
        dataset[key] !== undefined ? dataset[key] :
          key in element ? element[key] : undefined;
    };

    result.required = parseBool('required');
    result.disabled = parseBool('disabled');
    result.readOnly = parseBool('readOnly');
    result.placeholder = parseString('placeholder');
    result.value = parseString('value');
    result.pattern = parseString('pattern');

    result.minLength = this.parseNumeric('minLength', element, def, dataset);
    result.maxLength = this.parseNumeric('maxLength', element, def, dataset);

    if (typeof this.extractCustomConfig === 'function') {
      Object.assign(result, this.extractCustomConfig(element, def, dataset));
    }

    Object.entries(config).forEach(([key, value]) => {
      if (!(key in result)) {
        result[key] = value;
      }
    });

    Object.entries(def).forEach(([key, value]) => {
      if (!(key in result)) {
        result[key] = value;
      }
    });

    Object.entries(dataset).forEach(([key, value]) => {
      if (!(key in result)) {
        result[key] = value;
      }
    });

    return result;
  }

  static create(def) {
    if (['button', 'submit', 'reset'].includes(def.elementType)) {
      def.tagName = 'button';
      def.type = def.elementType;
    }
    def.tagName = def.tagName || 'input';
    const element = document.createElement(def.tagName);
    if (def.tagName === 'input') {
      element.type = def.type || 'text';
    }
    def.id = def.id && typeof def.id === 'string' ? def.id : `${def.elementType || def.tagName}-${Utils.generateUUID()}`;
    element.id = def.id;

    // Set value before creating wrapper and instance
    if (def.value !== undefined && def.value !== null) {
      element.value = def.value;
      element.setAttribute('value', def.value);
    }

    this.createWrapper(element, def);

    const config = Utils.object.deepClone(Object.assign({}, this.config, def));

    return this.createInstance(element, config);
  }

  static enhance(element, def = {}) {
    if (!element || !(element instanceof HTMLElement)) {
      throw new Error('Invalid element: Must be an HTMLElement');
    }

    element.id = element.id || `${def.type || element.type}-${Utils.generateUUID()}`;

    const config = Utils.object.deepClone(this.extractConfig(element, this.config, def));

    this.parseExistingWrapper(element);

    const instance = this.createInstance(element, config);

    // Skip value initialization for select[multiple] - handled by MultiSelectElementFactory
    const isMultipleSelect = element.tagName === 'SELECT' && element.multiple;
    let shouldInitValue = false;

    if (element.tagName === 'SELECT' && !isMultipleSelect) {
      // Only initialize if an option has explicit 'selected' attribute (not browser default)
      shouldInitValue = Array.from(element.options).some(opt => opt.hasAttribute('selected'));
    } else if (element.value && !isMultipleSelect) {
      shouldInitValue = true;
    }

    if (shouldInitValue) {
      instance.state.formatting = true;
      element.value = instance.initValue(element.value);
      instance.state.formatting = false;
    }

    return instance;
  }

  static createInstance(element, config = {}) {
    if (!this._privateState.has(element)) {
      this._privateState.set(element, {
        validating: false,
        valid: true,
        modified: false,
        error: null,
        originalValue: config.defaultValue || ('value' in element ? element.value : element.getAttribute('value')) || '',
        originalConfig: Utils.object.deepClone(config),
        formatting: false,
        recursionGuard: {}
      });
    }

    if (config.required) element.setAttribute('required', '');
    if (config.placeholder && !!config.placeholder.trim()) {
      element.placeholder = Now.translate(config.placeholder);
    }

    const instance = {
      element,
      config: Utils.object.deepClone(config),
      wrapper: element.wrapper,
      label: element.label,
      container: element.container,
      comment: element.comment,

      isValid() {
        const state = ElementFactory._privateState.get(this.element);
        return state ? state.valid : true;
      },

      isModified() {
        const state = ElementFactory._privateState.get(this.element);
        return state ? state.modified : false;
      },

      getError() {
        const state = ElementFactory._privateState.get(this.element);
        return state ? state.error : null;
      },

      focus() {
        this.element.focus();
        return this;
      },

      blur() {
        this.element.blur();
        return this;
      },

      reset() {
        const state = ElementFactory._privateState.get(this.element);
        if (state) {
          state.formatting = true;

          Object.assign(this.config, state.originalConfig);
          this.element.value = this.initValue(state.originalValue);

          state.valid = true;
          state.modified = false;
          state.error = null;
          state.validating = false;
          state.formatting = false;

          if (typeof FormError !== 'undefined') {
            FormError.clearFieldError(this.element.id);
          }
        }
        return this;
      },

      cleanup() {
        if (typeof EventSystemManager !== 'undefined') {
          EventSystemManager.removeElementHandlers(this.element);
        }
        if (typeof FormError !== 'undefined') {
          FormError.clearFieldError(this.element.id);
        }
        return this;
      },

      initValue(value) {
        const {validatedValue} = instance.validateValue(value, true);
        return validatedValue;
      },

      validate(value, valueChange) {
        const state = ElementFactory._privateState.get(this.element);
        if (!state) return value;

        if (state.validating || typeof value === 'undefined') {
          value = 'value' in this.element ? this.element.value : this.element.getAttribute('value') || '';
        }

        state.validating = true;
        state.modified = true;

        try {
          const {validatedValue, error} = this.validateValue(value, valueChange);

          if (error) {
            state.valid = false;
            state.error = error;
            FormError.showFieldError(this.element.id, error);
          } else {
            state.valid = true;
            state.error = null;
            FormError.clearFieldError(this.element.id);
          }

          return validatedValue;
        } catch (err) {
          console.error('Validation error:', err);
          state.valid = false;
          return value;
        } finally {
          state.validating = false;
        }
      },

      validateValue(value, valueChange) {
        const element = this.element;
        let error = null;
        const checkValue = typeof value === 'number' ? String(value) : String(value).trim();

        if (element.hasAttribute('required') && checkValue === '') {
          error = Now.translate('Please fill in');
          return {validatedValue: value, error};
        }

        if (typeof this.customValidateValue === 'function') {
          const result = this.customValidateValue.call(this, value, valueChange);
          if (result.error) {
            return result;
          }
          value = typeof result.validatedValue === 'number' ? String(result.validatedValue) : result.validatedValue;
        }

        if (checkValue.length > 0) {
          if (valueChange && typeof this.config.formatter === 'function') {
            const result = this.config.formatter(value);
            if (result && typeof result === 'object' && 'formatted' in result) {
              if (result.error) {
                return {validatedValue: result.formatted, error: result.error};
              } else {
                value = result.formatted;
              }
            } else {
              value = result;
            }
          }

          // Ensure value is not null after formatting
          if (value === null || value === undefined) {
            value = '';
          }

          const minLength = element.minLength;
          if (minLength > -1 && value.length < minLength) {
            error = Now.translate('Must be at least {minLength} characters', {minLength});
            return {validatedValue: value, error};
          }

          const maxLength = element.maxLength;
          if (maxLength > 0 && value.length > maxLength) {
            error = Now.translate('Must be no more than {maxLength} characters', {maxLength});
            return {validatedValue: value, error};
          }

          const pattern = element.getAttribute('pattern');
          if (pattern && !new RegExp(pattern).test(value)) {
            error = Now.translate('Please match the requested format');
            return {validatedValue: value, error};
          }

          if (typeof this.validateSpecific === 'function') {
            error = this.validateSpecific.call(this, value);
            if (error) {
              return {validatedValue: value, error};
            }
          }

          if (typeof this.customValidate === 'function') {
            error = this.customValidate(value);
            if (error) {
              return {validatedValue: value, error};
            }
          }
        }

        return {validatedValue: value, error: null};
      }
    };

    Object.defineProperty(instance, 'state', {
      get() {
        const element = this.element;
        return {
          get validating() {
            const state = ElementFactory._privateState.get(element);
            return state ? state.validating : false;
          },
          set validating(value) {
            const state = ElementFactory._privateState.get(element);
            if (state) state.validating = value;
          },

          get valid() {
            const state = ElementFactory._privateState.get(element);
            return state ? state.valid : true;
          },
          set valid(value) {
            const state = ElementFactory._privateState.get(element);
            if (state) state.valid = value;
          },

          get modified() {
            const state = ElementFactory._privateState.get(element);
            return state ? state.modified : false;
          },
          set modified(value) {
            const state = ElementFactory._privateState.get(element);
            if (state) state.modified = value;
          },

          get error() {
            const state = ElementFactory._privateState.get(element);
            return state ? state.error : null;
          },
          set error(value) {
            const state = ElementFactory._privateState.get(element);
            if (state) state.error = value;
          },

          get formatting() {
            const state = ElementFactory._privateState.get(element);
            return state ? state.formatting : false;
          },
          set formatting(value) {
            const state = ElementFactory._privateState.get(element);
            if (state) state.formatting = value;
          }
        };
      }
    });

    let rules = [];
    if (element.dataset.validate) {
      rules = this.parseValidationRules(element.dataset.validate);
    } else if (config.validation) {
      rules = Array.isArray(config.validation) ? config.validation : [config.validation];
    }

    instance.validationRules = rules;

    if (!instance.validateSpecific) {
      instance.validateSpecific = function(value) {
        const validators = {
          ...(window.validators || {}),
          ...(this.validators || {})
        };
        for (const rule of this.validationRules || []) {
          const ruleName = typeof rule === 'string' ? rule : rule.name;
          if (['minLength', 'maxLength', 'pattern', 'required'].includes(ruleName)) {
            continue;
          }
          if (validators[ruleName]) {
            if (!validators[ruleName](value)) {
              return Now.translate(config.validationMessages?.[ruleName] || 'Invalid value');
            }
          }
        }
        return null;
      };
    }

    if (typeof this.setupElement === 'function') {
      this.setupElement(instance);
    }
    this.setupProperties(instance);

    Object.keys(config).forEach(prop => {
      if (!['classList'].includes(prop) && config[prop] !== undefined && !Utils.object.isObject(config[prop])) {
        if (this.extendedProperties.includes(prop)) {
          instance[prop] = config[prop];
        }
        if (prop in instance || this.coreProperties.includes(prop)) {
          if (prop in element && element[prop] !== config[prop] && config[prop] !== null && config[prop] !== undefined) {
            try {
              if (prop === 'defaultValue' && instance.validate) {
                element[prop] = instance.initValue(config[prop]);
              } else if (prop === 'size') {
                const sizeValue = Number(config[prop]);
                if (Number.isFinite(sizeValue) && sizeValue > 0) {
                  element[prop] = sizeValue;
                }
              } else if (!['type', 'options'].includes(prop)) {
                element[prop] = config[prop];
              }
            } catch (e) {
              console.warn(`Could not set property ${prop} on element`, element, e);
            }
          }
        }
      }
    });
    Object.keys(config).forEach(prop => {
      if (!(prop in instance) && !(prop in element)) {
        Object.defineProperty(instance, prop, {
          get() {
            return config[prop];
          },
          set(newValue) {
            config[prop] = newValue;
          }
        });
      }
    });

    // If this is a <button> element, apply `text` or `value` to its visible label
    try {
      const tag = element.tagName && element.tagName.toLowerCase();
      if (tag === 'button') {
        if (typeof config.text !== 'undefined') {
          element.textContent = Now.translate(config.text);
        } else if (typeof config.value !== 'undefined') {
          element.textContent = Now.translate(config.value);
        }
      }
    } catch (e) {
      // non-fatal
    }

    this.setupEventListeners(instance);

    instance.constructor = this;

    return instance;
  }

  static setupProperties(instance) {
    const {element} = instance;

    const getDescriptorOrFallback = (element) => {
      let descriptor;

      if (element instanceof HTMLInputElement) {
        descriptor = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
      } else if (element instanceof HTMLTextAreaElement) {
        descriptor = Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value');
      } else if (element instanceof HTMLSelectElement) {
        descriptor = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
      }

      return descriptor || {
        get: function() {
          if (this instanceof HTMLSelectElement) {
            const selectedOption = this.options[this.selectedIndex];
            return selectedOption ? selectedOption.value : '';
          }
          return this.getAttribute('value') || '';
        },
        set: function(val) {
          if (this instanceof HTMLSelectElement) {
            for (let i = 0; i < this.options.length; i++) {
              if (this.options[i].value === val) {
                this.selectedIndex = i;
                return;
              }
            }
          }
          this.setAttribute('value', val);
        }
      };
    };

    const originalDescriptor = getDescriptorOrFallback(element);

    Object.defineProperty(element, 'value', {
      get() {
        return originalDescriptor.get.call(element);
      },
      set(newValue) {
        const state = ElementFactory._privateState.get(element);
        if (state && !state.formatting) {
          state.formatting = true;
          const result = instance.validate(newValue, true);
          originalDescriptor.set.call(element, result);
          state.formatting = false;
        } else {
          originalDescriptor.set.call(element, newValue);
        }
      },
      enumerable: true,
      configurable: true
    });

    const mergedHandlers = {...ElementFactory.propertyHandlers};

    if (this.propertyHandlers) {
      Object.assign(mergedHandlers, this.propertyHandlers);
    }

    Object.entries(mergedHandlers).forEach(([prop, handlers]) => {
      Object.defineProperty(instance, prop, {
        get() {
          return handlers.get.call(instance.constructor, element);
        },
        set(newValue) {
          const state = ElementFactory._privateState.get(element);
          if (!state || !state.modified || instance[prop] !== newValue) {
            handlers.set.call(instance.constructor, instance, newValue);
          }
        },
        enumerable: true
      });
    });

    this.coreProperties.forEach(prop => {
      if (!(prop in mergedHandlers)) {
        Object.defineProperty(instance, prop, {
          get() {
            return prop in element ? element[prop] : element.getAttribute(prop) || undefined;
          },
          set(value) {
            if (prop in element) {
              element[prop] = value;
            } else {
              element.setAttribute(prop, value);
            }
          },
          enumerable: true
        });
      }
    });

    Object.defineProperty(instance, 'setAttribute', {
      value: function(name, value) {
        const state = ElementFactory._privateState.get(this.element);
        if (!state || !state.recursionGuard?.[name]) {
          state.recursionGuard = state.recursionGuard || {};
          state.recursionGuard[name] = true;
          try {
            if (name in mergedHandlers) {
              this[name] = value;
            } else {
              this.element.setAttribute(name, value);
            }
          } finally {
            state.recursionGuard[name] = false;
          }
        }
      },
      writable: true,
      configurable: true
    });

    Object.defineProperty(instance, 'getAttribute', {
      value: function(name) {
        if (name in mergedHandlers) {
          return this[name];
        }
        return this.element.getAttribute(name);
      },
      writable: true,
      configurable: true
    });

    Object.defineProperty(instance, 'ariaLabel', {
      get() {
        return this.element.getAttribute('aria-label');
      },
      set(newValue) {
        this.element.setAttribute('aria-label', Now.translate(newValue));
      }
    });
  }

  static setupEventListeners(instance) {
    const {element} = instance;
    const debouncedValidate = Utils.function.debounce(() => instance.validate(undefined, false), 300);

    EventSystemManager.addHandler(element, 'input', () => {
      instance.state.modified = true;
      debouncedValidate();
    });

    EventSystemManager.addHandler(element, 'change', () => {
      const validatedValue = instance.validate(element.value, true);
      if ('value' in element && validatedValue !== element.value) {
        element.value = validatedValue;
      }
    });

    EventSystemManager.addHandler(element, 'blur', () => {
      const validatedValue = instance.validate(element.value, true);
      if ('value' in element && validatedValue !== element.value) {
        element.value = validatedValue;
      }
    });
  }

  static parseValidationRules(rules) {
    if (!rules) return {rules: []};
    const ruleList = Array.isArray(rules) ? rules : rules.split(',').map(r => r.trim());
    return {
      rules: ruleList.map(rule => {
        if (typeof rule === 'string' && rule.includes(':')) {
          const [name, param] = rule.split(':');
          return {name, param};
        }
        return {name: rule};
      }),
      validating: false
    };
  }

  static parseExistingWrapper(element) {
    let current = element;
    let level = 0;
    const maxLevel = 2;

    while (current && level < maxLevel) {
      const parent = current.parentElement;
      if (!parent) break;

      if (parent.tagName === 'LABEL') {
        element.wrapper = parent;
        element.label = parent;
        element.container = parent;
        break;
      }

      if (parent.classList.contains('form-control')) {
        element.container = parent;
        const grandParent = parent.parentElement;
        if (grandParent) {
          const label = grandParent.querySelector(`label[for="${element.id}"]`);
          if (label) {
            element.wrapper = grandParent;
            element.label = label;
          }
        }
        break;
      }

      current = parent;
      level++;
    }

    element.comment = document.getElementById(`result_${element.id}`);
  }

  static createWrapper(element, def) {
    if (!def.wrapper) return;

    const wrapper = document.createElement(def.wrapper);
    if (def.wrapper === 'label') {
      wrapper.className = 'form-control';
      if (def.icon) wrapper.classList.add(def.icon);
      if (def.label) {
        wrapper.textContent = Now.translate(def.label);
        wrapper.dataset.i18n = def.label;
      }
      if (element.id) wrapper.htmlFor = element.id;
      wrapper.appendChild(element);
      element.label = wrapper;
      element.container = wrapper;
    } else {
      const label = document.createElement('label');
      if (def.label) {
        label.textContent = Now.translate(def.label);
        label.dataset.i18n = def.label;
      }
      if (element.id) label.htmlFor = element.id;
      wrapper.appendChild(label);
      const container = document.createElement('span');
      container.className = 'form-control';
      if (def.icon) container.classList.add(def.icon);
      container.appendChild(element);
      wrapper.appendChild(container);
      element.label = label;
      element.container = container;
    }

    if (def.comment && element.id) {
      const commentId = `result_${element.id}`;
      element.setAttribute('aria-describedby', commentId);
      const comment = document.createElement('div');
      comment.className = 'comment';
      comment.id = commentId;
      comment.textContent = def.comment;
      if (def.wrapper === 'label') {
        if (wrapper.parentNode) {
          wrapper.parentNode.insertBefore(comment, wrapper.nextSibling);
        } else {
          const originalAppend = wrapper.appendChild;
          wrapper.appendChild = function(child) {
            let counter = 100;
            const timer = window.setInterval(function() {
              if (counter <= 0) {
                window.clearInterval(timer);
              }
              if (wrapper.parentNode) {
                wrapper.parentNode.insertBefore(comment, wrapper.nextSibling);
                wrapper.appendChild = originalAppend;
                window.clearInterval(timer);
              }
              counter--;
            }, 500);
          };
        }
      }
      wrapper.appendChild(comment);
      element.comment = comment;
    }

    if (def.wrapperClass) wrapper.classList.add(def.wrapperClass);
    element.wrapper = wrapper;
  }

  static parseNumeric(key, element, def, dataset) {
    if (key in element) {
      let value;
      if (def && def[key] !== undefined) {
        value = parseFloat(def[key]);
      } else if (dataset[key] !== undefined) {
        value = parseFloat(dataset[key]);
      } else if (element[key]) {
        value = element[key];
      }
      return isNaN(value) ? undefined : value;
    }
    return undefined;
  }
}

window.ElementFactory = ElementFactory;
