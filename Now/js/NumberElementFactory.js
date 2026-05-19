class NumberElementFactory extends ElementFactory {
  static config = {
    type: 'text',
    inputMode: 'decimal',
    step: 1,
    min: null,
    max: null,
    precision: 0,                // Number of decimal places
    padToPrecision: false,       // Pad with zeros to match precision
    useGrouping: false,          // Use thousand separators
    groupingSeparator: ',',      // Thousand separator character
    decimalSeparator: '.',       // Decimal point character
    allowNegative: false,        // Allow negative values
    roundValues: false,          // Round to precision
    selectOnFocus: null,         // Auto-select text on focus (default true for currency)
    showSymbol: false,           // Show currency/unit symbol
    symbol: '',                  // Symbol to display (e.g., '$')
    symbolPosition: 'before',    // Symbol position ('before' or 'after')
    negativeWithParentheses: false, // Use parentheses for negative values
    validationMessages: {
      required: 'Please fill in this field',
      min: 'Value must be at least {min}',
      max: 'Value must be no more than {max}',
      step: 'Value must be a multiple of {step}',
      number: 'Please enter a valid number',
      decimal: 'Only one decimal separator is allowed',
      negative: 'Negative sign must be at the start'
    }
  };

  static propertyHandlers = {
    value: {
      get(element) {
        return element.value;
      },
      set(instance, newValue) {
        if (newValue === null || newValue === undefined || newValue === '') {
          instance.element.value = '';
          return;
        }

        if (typeof instance.setValue === 'function') {
          instance.setValue(newValue);
        } else {
          instance.element.value = newValue;
        }
      }
    },
    min: {
      get(element) {
        const min = element.getAttribute('min');
        return min !== null ? parseFloat(min) : null;
      },
      set(instance, newValue) {
        const parsedValue = newValue === null || newValue === undefined || newValue === '' ? null : parseFloat(newValue);

        instance.config.min = Number.isFinite(parsedValue) ? parsedValue : null;
        if (newValue === null || newValue === undefined) {
          instance.element.removeAttribute('min');
        } else {
          instance.element.setAttribute('min', newValue);
        }
      }
    },
    max: {
      get(element) {
        const max = element.getAttribute('max');
        return max !== null ? parseFloat(max) : null;
      },
      set(instance, newValue) {
        const parsedValue = newValue === null || newValue === undefined || newValue === '' ? null : parseFloat(newValue);

        instance.config.max = Number.isFinite(parsedValue) ? parsedValue : null;
        if (newValue === null || newValue === undefined) {
          instance.element.removeAttribute('max');
        } else {
          instance.element.setAttribute('max', newValue);
        }
      }
    },
    step: {
      get(element) {
        const step = element.getAttribute('step');
        return step !== null ? parseFloat(step) : 1;
      },
      set(instance, newValue) {
        const parsedValue = newValue === null || newValue === undefined || newValue === '' ? null : parseFloat(newValue);

        instance.config.step = Number.isFinite(parsedValue) ? parsedValue : 1;
        if (newValue === null || newValue === undefined || newValue === '') {
          instance.element.removeAttribute('step');
        } else {
          instance.element.setAttribute('step', newValue);
        }
      }
    },
    precision: {
      get(element) {
        const precision = element.getAttribute('precision');
        return precision !== null ? parseFloat(precision) : 0;
      },
      set(instance, newValue) {
        if (newValue === null || newValue === undefined || newValue === '') {
          return;
        }

        const parsedValue = parseInt(newValue, 10);
        if (isNaN(parsedValue) || parsedValue < 0) {
          return;
        }

        const precision = parsedValue;

        if (instance.config.precision !== precision) {
          instance.config.precision = precision;
          instance.element.setAttribute('precision', String(precision));
          instance.element.dataset.decimals = String(precision);
          this.applyFormatting(instance);
        }
      }
    },
    decimals: {
      get(element) {
        const decimals = element.dataset.decimals;
        return decimals !== undefined ? parseFloat(decimals) : 0;
      },
      set(instance, newValue) {
        this.propertyHandlers.precision.set.call(this, instance, newValue);
      }
    },
    'data-decimals': {
      get(element) {
        const decimals = element.dataset.decimals;
        return decimals !== undefined ? parseFloat(decimals) : 0;
      },
      set(instance, newValue) {
        this.propertyHandlers.precision.set.call(this, instance, newValue);
      }
    }
  };

  static extractCustomConfig(element, def, dataset) {
    // Use this.config as fallback instead of def (which may be empty from enhance())
    const baseConfig = {...this.config, ...def};
    const originalType = element.getAttribute('type');
    const precision = dataset.precision !== undefined
      ? parseInt(dataset.precision, 10)
      : (dataset.decimals !== undefined ? parseInt(dataset.decimals, 10) : baseConfig.precision);
    return {
      min: this.parseNumeric('min', element, def, dataset),
      max: this.parseNumeric('max', element, def, dataset),
      step: this.parseNumeric('step', element, def, dataset),
      size: this.parseNumeric('size', element, def, dataset),
      precision,
      decimalSeparator: dataset.decimalSeparator || baseConfig.decimalSeparator || '.',
      groupingSeparator: dataset.groupingSeparator || baseConfig.groupingSeparator || ',',
      useGrouping: dataset.useGrouping !== undefined ? dataset.useGrouping === 'true' : baseConfig.useGrouping,
      allowNegative: dataset.allowNegative !== undefined ? dataset.allowNegative === 'true' : baseConfig.allowNegative,
      roundValues: dataset.roundValues !== undefined ? dataset.roundValues === 'true' : baseConfig.roundValues,
      padToPrecision: dataset.padToPrecision !== undefined ? dataset.padToPrecision === 'true' : baseConfig.padToPrecision,
      selectOnFocus: dataset.selectOnFocus !== undefined
        ? dataset.selectOnFocus === 'true'
        : (baseConfig.selectOnFocus ?? originalType === 'currency')
    };
  }

  static setupElement(instance) {
    const {element, config} = instance;

    // Change type to text to allow formatting (thousand separators, etc.)
    // Browser's native number input doesn't support formatted values
    // Use getAttribute('type') because browser normalizes unknown types to 'text'
    // Only <input> elements support setting the type property; <select> and <textarea> have read-only type.
    const originalType = element.getAttribute('type');
    if ((originalType === 'number' || originalType === 'currency') && element.tagName === 'INPUT') {
      element.type = 'text';
      element.setAttribute('inputmode', config.inputMode || 'decimal');
    }

    // Set size attribute if provided (must be a finite positive number)
    if (config.size !== undefined && config.size !== null) {
      const sizeValue = Number(config.size);
      if (Number.isFinite(sizeValue) && sizeValue > 0) {
        element.size = sizeValue;
      }
    }

    instance.initValue = (value) => {
      return NumberElementFactory.formatNumber(value, config);
    };

    instance.parseNumber = function(value) {
      return NumberElementFactory.parseNumber(value, config);
    };

    instance.formatNumber = function(value) {
      return NumberElementFactory.formatNumber(value, config);
    };

    // Add setValue for FormManager compatibility
    instance.setValue = (value) => {
      if (value === undefined || value === null || value === '') {
        element.value = '';
        return;
      }
      const formatted = NumberElementFactory.formatNumber(value, config);
      element.value = formatted;
    };

    instance.validateValue = (value, valueChange) => {
      const {formatted, error} = this.formatValue(value, element, config);
      return {validatedValue: formatted, error};
    };

    config.formatter = (value) => {
      return this.formatValue(value, element, config);
    };

    return instance;
  }

  static setupEventListeners(instance) {
    const {element, config} = instance;

    const debouncedValidate = Utils.function.debounce(() => instance.validate(undefined, false), 300);

    const handlers = {
      input: () => {
        instance.state.modified = true;
        debouncedValidate();
      },

      change: () => {
        this.applyFormatting(instance);
      },

      blur: () => {
        instance._selectOnFocusByPointer = false;
        instance._preventSelectOnMouseUp = false;
        this.applyFormatting(instance);
      },

      mousedown: () => {
        instance._selectOnFocusByPointer = document.activeElement !== element;
      },

      focus: () => {
        FormError.clearFieldError(element.id);

        const state = ElementFactory._privateState.get(element);
        if (state) {
          state.formatting = true;

          const parsedValue = instance.parseNumber(element.value);
          if (!isNaN(parsedValue)) {
            element.value = parsedValue.toString().replace('.', config.decimalSeparator);
          }

          state.formatting = false;
        }

        if (config.selectOnFocus && typeof element.select === 'function' && element.value !== '') {
          instance._preventSelectOnMouseUp = instance._selectOnFocusByPointer === true;
          requestAnimationFrame(() => {
            if (document.activeElement === element) {
              element.select();
            }
          });
        }
      },

      mouseup: (e) => {
        if (instance._preventSelectOnMouseUp) {
          e.preventDefault();
          instance._preventSelectOnMouseUp = false;
        }
      },

      keydown: (e) => {
        if (this.handleSpecialKeys(e, instance)) {
          return;
        }

        if (!this.isAllowedKey(e, config)) {
          e.preventDefault();
          return false;
        }
      },

      paste: (e) => {
        if (!this.handlePaste(e, instance)) {
          e.preventDefault();
          return false;
        }
      }
    };

    Object.entries(handlers).forEach(([event, handler]) => {
      EventSystemManager.addHandler(element, event, handler);
    });

    return handlers;
  }

  static applyFormatting(instance) {
    const {element, config} = instance;
    const value = element.value;

    if (value === '') return;

    const parsedValue = instance.parseNumber(value);
    if (isNaN(parsedValue)) return;

    const formatted = this.formatNumber(parsedValue, config);
    element.value = formatted;
  }

  static handleSpecialKeys(e, instance) {
    const {element, config} = instance;

    if ([
      'Tab', 'Enter', 'Escape', 'ArrowLeft', 'ArrowRight', 'Home', 'End',
      'Delete', 'Backspace'
    ].includes(e.key)) {
      return true;
    }

    if (e.ctrlKey && ['a', 'c', 'v', 'x', 'z'].includes(e.key.toLowerCase())) {
      return true;
    }

    if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
      e.preventDefault();

      const currentValue = instance.parseNumber(element.value) || 0;
      const step = parseFloat(config.step) || 1;

      let newValue;
      if (e.key === 'ArrowUp') {
        newValue = Utils.number.round(currentValue + step, config.precision);
      } else {
        newValue = Utils.number.round(currentValue - step, config.precision);
      }

      if (config.min !== null && newValue < config.min) {
        newValue = config.min;
      }
      if (config.max !== null && newValue > config.max) {
        newValue = config.max;
      }

      const state = ElementFactory._privateState.get(element);
      if (state) {
        state.formatting = true;
        element.value = String(newValue).replace('.', config.decimalSeparator);
        state.formatting = false;
      }

      return true;
    }

    return false;
  }

  static isAllowedKey(e, config) {
    const key = e.key;

    if (/^\d$/.test(key)) {
      return true;
    }

    if (key === config.decimalSeparator &&
      e.target.value.indexOf(config.decimalSeparator) === -1 &&
      config.precision !== 0) {
      return true;
    }

    if (key === '-' && config.allowNegative && e.target.selectionStart === 0 &&
      e.target.value.indexOf('-') === -1) {
      return true;
    }

    return false;
  }

  static handlePaste(e, instance) {
    const {element, config} = instance;

    const clipboardData = e.clipboardData || window.clipboardData;
    const pastedText = clipboardData.getData('text');

    const currentValue = element.value;
    const selectionStart = element.selectionStart;
    const selectionEnd = element.selectionEnd;

    const newValue = currentValue.substring(0, selectionStart) +
      pastedText +
      currentValue.substring(selectionEnd);

    const decimalSeparatorCount = (newValue.match(new RegExp(`\\${config.decimalSeparator}`, 'g')) || []).length;
    if (decimalSeparatorCount > 1 || (config.precision === 0 && decimalSeparatorCount > 0)) {
      return false;
    }

    if (config.allowNegative) {
      const minusCount = (newValue.match(/-/g) || []).length;
      if (minusCount > 1 || (minusCount === 1 && newValue.indexOf('-') !== 0)) {
        return false;
      }
    } else if (newValue.includes('-')) {
      return false;
    }

    const validPattern = config.allowNegative ?
      new RegExp(`^-?\\d*\\${config.decimalSeparator}?\\d*$`) :
      new RegExp(`^\\d*\\${config.decimalSeparator}?\\d*$`);

    if (!validPattern.test(newValue)) {
      return false;
    }

    return true;
  }

  static formatNumber(value, config) {
    if (value === '' || value === null || value === undefined) {
      return '';
    }

    let number = typeof value === 'number' ? value : this.parseNumber(value, config);
    if (isNaN(number)) {
      return typeof value === 'string' ? value : '';
    }

    const isNegative = number < 0;
    number = Math.abs(number);

    // Only round if roundValues is explicitly true
    if (config.roundValues === true && config.precision >= 0) {
      number = Utils.number.round(number, config.precision);
    }

    // Use toFixed only when roundValues is true, otherwise preserve full number
    let numStr = (config.roundValues === true && config.precision >= 0)
      ? number.toFixed(config.precision)
      : number.toString();
    let [intPart, decPart = ''] = numStr.split('.');

    if (config.roundValues !== true && config.precision > 0 && decPart) {
      decPart = decPart.substring(0, config.precision);
    } else if (config.precision === 0) {
      decPart = '';
    }

    if (config.padToPrecision && config.precision > 0) {
      decPart = (decPart || '').padEnd(config.precision, '0');
    }

    let formattedIntPart = intPart;
    if (config.useGrouping) {
      const separator = config.groupingSeparator || ',';
      let grouped = '';
      for (let i = 0; i < formattedIntPart.length; i++) {
        if (i > 0 && (formattedIntPart.length - i) % 3 === 0) {
          grouped += separator;
        }
        grouped += formattedIntPart[i];
      }
      formattedIntPart = grouped;
    }

    let formatted = formattedIntPart;
    if ((decPart || config.padToPrecision) && config.precision > 0) {
      const decSeparator = config.decimalSeparator || '.';
      formatted += decSeparator + decPart;
    }
    if (config.showSymbol && config.symbol) {
      formatted = config.symbolPosition === 'before' ? `${config.symbol}${formatted}` : `${formatted}${config.symbol}`;
    }

    if (isNegative) {
      formatted = config.negativeWithParentheses ? `(${formatted})` : `-${formatted}`;
    }

    return formatted;
  }

  static formatValue(value, element, config) {
    let error = null;

    if (value === '' || value === null || value === undefined) {
      if (element.hasAttribute('required')) {
        error = Now.translate('Please fill in');
      }
      return {formatted: '', error};
    }

    let number = this.parseNumber(value, config);
    if (isNaN(number)) {
      return {formatted: value, error: Now.translate('Please enter a valid number')};
    }

    if (!config.allowNegative && number < 0) {
      error = Now.translate('Unable to have negative values');
      number = Math.abs(number);
    }

    const min = parseFloat(element.min);
    const max = parseFloat(element.max);

    if (min !== null && number < min) {
      error = Now.translate('Value must be at least {min}', {min});
      number = min;
    }
    if (max !== null && number > max) {
      error = Now.translate('Value must be no more than {max}', {max});
      number = max;
    }


    const formatted = this.formatNumber(number, config);
    return {formatted, error};
  }

  static parseNumber(value, config) {
    if (typeof value === 'number') {
      return isNaN(value) ? NaN : value;
    }
    if (value === '' || value === null || value === undefined) return NaN;

    let strValue = String(value);
    strValue = strValue.replace(config.decimalSeparator, '.')
      .replace(/[^0-9\.\-\(\)]+/g, '');

    if (strValue.startsWith('(') && strValue.endsWith(')')) {
      strValue = '-' + strValue.substring(1, strValue.length - 1);
    }

    return Number(strValue);
  }
}

class CurrencyElementFactory extends NumberElementFactory {
  static config = {
    ...NumberElementFactory.config,
    precision: 2,                // 2 decimal places for currency
    padToPrecision: true,        // Always show 2 decimal places
    roundValues: true,           // Round to 2 decimal places
    selectOnFocus: true,         // Select all text on focus for editable money fields
    showSymbol: false,           // Don't show currency symbol by default
    symbol: '',                  // No default currency symbol
    symbolPosition: 'before',    // Symbol before number
    allowNegative: true,         // Allow negative values
    useGrouping: true,           // Use thousand separators
    groupingSeparator: ',',      // Thousand separator
    decimalSeparator: '.',       // Decimal separator
    negativeWithParentheses: false, // Don't use parentheses for negative
    inputMode: 'decimal'         // Use decimal keyboard on mobile
  };
}

ElementManager.registerElement('number', NumberElementFactory);
ElementManager.registerElement('currency', CurrencyElementFactory);

// Export to global scope
window.NumberElementFactory = NumberElementFactory;
