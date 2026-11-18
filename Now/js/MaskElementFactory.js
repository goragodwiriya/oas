/**
 * MaskElementFactory
 *
 * Formats input as the user types, from either a named mask (tel, date, …) or a
 * raw pattern such as `99-9999-9999`. Keeps the caret in a sensible place while
 * editing and sets `inputMode` so mobile keyboards match the expected characters.
 */
class MaskElementFactory extends ElementFactory {
  static config = {
    maskOnInit: true,
    maskOnChange: true,
    maskOnBlur: true,
    unmaskOnSubmit: false,
    placeholderChar: '_',
    allowPartial: false,
    masks: {
      tel: {
        mask: '99-9999-9999',
        placeholder: '__-____-____',
        inputMode: 'tel',
        validator: '^[0-9]{2}\\-[0-9]{4}\\-[0-9]{3,4}$'
      },
      date: {
        mask: '99/99/9999',
        format: 'xx/xx/xxxx',
        placeholder: '__/__/____',
        inputMode: 'numeric',
        validate: value => MaskElementFactory.isValidDate(value),
        message: 'Please enter a valid date'
      },
      time: {
        mask: '99:99',
        format: 'xx:xx',
        placeholder: '__:__',
        inputMode: 'numeric',
        validate: value => MaskElementFactory.isValidTime(value),
        message: 'Please enter a valid time'
      },
      datetime: {
        mask: '99/99/9999 99:99',
        format: 'xx/xx/xxxx xx:xx',
        placeholder: '__/__/____ __:__',
        inputMode: 'numeric',
        validate: value => {
          const [date, time] = String(value).split(' ');
          return MaskElementFactory.isValidDate(date) && MaskElementFactory.isValidTime(time);
        },
        message: 'Please enter a valid date and time'
      },
      creditcard: {
        mask: '9999-9999-9999-9999',
        format: 'xxxx-xxxx-xxxx-xxxx',
        placeholder: '____-____-____-____',
        inputMode: 'numeric',
        validate: value => MaskElementFactory.isValidCardNumber(value),
        message: 'Please enter a valid card number'
      },
      ip: {
        // Octets are 1-3 digits: the dot the user types closes one, and a fourth
        // digit starts the next. A fixed 999.999.999.999 mask regrouped
        // 192.168.0.1 as 192.168.01 and dropped the typed dots.
        mask: '999.999.999.999',
        format: 'xxx.xxx.xxx.xxx',
        placeholder: '___.___.___.___',
        inputMode: 'decimal',
        allowPartial: true,
        formatValue: value => MaskElementFactory.formatIpv4(value),
        allowedChars: /^[0-9.]$/,
        validate: value => MaskElementFactory.isValidIpv4(value),
        message: 'Please enter a valid IP address'
      },
      idcard: {
        mask: '9-9999-99999-99-9',
        format: 'x-xxxx-xxxxx-xx-x',
        placeholder: '_-____-_____-__-_',
        inputMode: 'numeric',
        validate: value => MaskElementFactory.isValidThaiId(value),
        message: 'Please enter a valid ID card number'
      },
      zipcode: {
        mask: '99999',
        format: 'xxxxx',
        placeholder: '_____',
        inputMode: 'numeric',
        allowPartial: true,
        validator: '^[0-9]{5}$',
        message: 'Please enter a valid zipcode'
      },
      currency: {
        mask: '#,###.##',
        inputMode: 'decimal',
        allowPartial: true
      }
    },
    inputPatterns: {
      '9': /[0-9]/,
      'a': /[a-zA-Z]/,
      'A': /[a-zA-Z]/,
      '*': /[a-zA-Z0-9]/,
      '#': /[0-9.,\-]/,
      'X': /./
    }
  };

  /**
   * Create a masked input from a field definition. A raw `pattern` is the mask
   * — see patternConfig().
   *
   * @param {Object} def - Field definition.
   * @returns {Object} - The element instance.
   */
  static create(def = {}) {
    if (def.pattern && !def.format && !def.maskPattern) {
      def = {...def, ...this.patternConfig(def.pattern, def.allowPartial)};
    }
    return super.create(def);
  }

  /**
   * Read `data-pattern` of an enhanced element as its mask — see patternConfig().
   *
   * @param {HTMLElement} element - Element being enhanced.
   * @param {Object} def - Field definition.
   * @param {DOMStringMap} dataset - The element's data attributes.
   * @returns {Object} - Config entries that override the base ones.
   */
  static extractCustomConfig(element, def, dataset) {
    let custom = {};
    let maskText = null;
    const mask = def.pattern ?? dataset.pattern;
    if (mask && !def.format && !dataset.format) {
      const allowPartial = def.allowPartial ?? (dataset.allowPartial !== undefined ? dataset.allowPartial === 'true' : undefined);
      custom = this.patternConfig(mask, allowPartial);
      maskText = mask;
    } else {
      const format = def.format || dataset.format || this.config.format;
      maskText = (def.mask && typeof def.mask === 'object' ? def.mask.mask : null)
        || this.config.masks[format]?.mask
        || null;
    }

    // A fixed mask adds its own separators, so a maxlength written for the raw
    // value (maxlength="10" for a 10-digit phone) is shorter than the masked
    // value (08-1234-5678): the browser stops typing part-way and validation
    // rejects the complete value. The mask already limits the length, so
    // maxlength grows to fit it. Variable masks (#) keep their maxlength.
    const maxLength = this.parseNumeric('maxLength', element, def, dataset);
    if (typeof maskText === 'string' && !maskText.includes('#')
      && maxLength > 0 && maxLength < maskText.length) {
      custom.maxLength = maskText.length;
    }

    return custom;
  }

  /**
   * A raw pattern such as `99-9999` is the mask, not a regular expression. The
   * base factory copies `config.pattern` into the native `pattern` attribute
   * that form validation tests, so a value could never match it and a required
   * field could not be submitted. The mask moves to `maskPattern` and `pattern`
   * becomes the expression the mask stands for — none when a partial value is
   * allowed.
   *
   * @param {string} mask - Mask pattern.
   * @param {boolean} [allowPartial] - Accept an incomplete value.
   * @returns {Object} - {maskPattern, pattern}
   */
  static patternConfig(mask, allowPartial) {
    const partial = allowPartial ?? this.config.allowPartial;
    return {maskPattern: mask, pattern: partial ? '' : this.maskPattern(mask)};
  }

  /**
   * Apply the resolved mask to the element.
   *
   * Sets `inputMode` so mobile keyboards match the mask, fills in a
   * placeholder from the mask when the element does not already declare one,
   * and with `maskOnInit` shows the value the field starts with masked.
   * Elements with no usable mask are left untouched.
   *
   * @param {Object} instance - Element instance carrying `element` and `config`.
   * @returns {Object} - The same instance.
   */
  static setupElement(instance) {
    const {element, config} = instance;

    this.setupMask(instance);

    if (!instance.mask) return instance;

    if (instance.mask.inputMode) {
      element.inputMode = instance.mask.inputMode;
    }

    if (!element.placeholder && instance.mask.placeholder) {
      element.placeholder = instance.mask.placeholder;
    }

    // maskOnInit: a value the field starts with is shown masked too
    // (ElementManager.create() sets it before the mask exists)
    if ((config.maskOnInit ?? this.config.maskOnInit) && element.value) {
      const {formatted} = this.formatValue(element.value, instance.mask);
      if (formatted !== element.value) element.value = formatted;
    }


    return instance;
  }

  /**
   * Bind the input, change and blur handlers that keep the masked value formatted.
   *
   * Which are bound depends on `maskOnChange` and `maskOnBlur` in the factory
   * config; `maskOnInit` is applied by setupElement().
   *
   * @param {Object} instance - Element instance to bind to.
   * @returns {void}
   */
  static setupEventListeners(instance) {
    const {element, config} = instance;

    const handlers = {
      input: (e) => {
        const cursorPosition = element.selectionStart;
        const value = element.value;

        if (instance.mask && config.maskOnChange) {
          // Check if we have a preserved cursor position from Backspace FIRST
          const privateState = this._privateState.get(element);
          const hasPreservedCursor = privateState && privateState.preserveCursorPos !== undefined;

          // Get previous value for cursor tracking
          const previousValue = privateState?.previousValue || '';
          const previousCursor = privateState?.previousCursor || 0;

          const result = this.formatValue(value, instance.mask, previousValue, cursorPosition);

          if (result.formatted !== value) {
            element.value = result.formatted;
          }

          // Store current value and cursor for next time
          if (!privateState) {
            this._privateState.set(element, {previousValue: result.formatted, previousCursor: cursorPosition});
          } else {
            privateState.previousValue = result.formatted;
            privateState.previousCursor = cursorPosition;
            this._privateState.set(element, privateState);
          }

          // Handle cursor positioning
          if (hasPreservedCursor) {
            // Preserved cursor has absolute priority
            const finalCursorPos = privateState.preserveCursorPos;
            // Clear the preserved position after using it
            delete privateState.preserveCursorPos;
            this._privateState.set(element, privateState);

            // Set cursor immediately, no setTimeout needed
            element.setSelectionRange(finalCursorPos, finalCursorPos);
          } else if (result.formatted !== value && result.newCursorPos !== undefined) {
            // Place the caret now: a deferred setSelectionRange ran after the next key
            // of a fast typist (or a scanner) and pulled the caret back, so that key
            // landed before the previous one (29022024 became 29/02/2402)
            element.setSelectionRange(result.newCursorPos, result.newCursorPos);

            // Auto-separator filling logic
            if (instance.mask.groupSizes && instance.mask.separators) {
              let totalChars = 0;

              for (let i = 0; i < instance.mask.groupSizes.length - 1; i++) {
                totalChars += instance.mask.groupSizes[i];

                if (cursorPosition === totalChars) {
                  const allFilledChars = element.value.substring(0, cursorPosition).replace(/[^0-9a-zA-Z]/g, '').length;
                  const expectedFilledChars = instance.mask.groupSizes.slice(0, i + 1).reduce((a, b) => a + b, 0);

                  if (allFilledChars === expectedFilledChars) {
                    const valueNow = element.value;
                    setTimeout(() => {
                      // Only if nothing was typed in between (see above)
                      if (element.value === valueNow) {
                        element.setSelectionRange(cursorPosition + 1, cursorPosition + 1);
                      }
                    }, 0);
                    break;
                  }
                }
              }
            }
          }

          const currentPrivateState = this._privateState.get(element);
          if (currentPrivateState) {
            currentPrivateState.modified = true;
          }

          instance.validate(result.formatted, false);
        }
      },
      change: (e) => {
        const validatedValue = instance.validate(element.value, true);
        if (validatedValue !== element.value) {
          element.value = validatedValue;
        }
      },
      focus: (e) => {
        if (instance.mask) {
          // Typing may start before the timer fires (fast typist, focus by
          // tapping and typing). Selecting the first group then would put the
          // characters already typed under the selection, and the next key
          // replaced them — 0812345678 came out as 81-2345-678. Only place
          // the caret when the value is still what it was on focus.
          const valueOnFocus = element.value;
          setTimeout(() => {
            if (element.value !== valueOnFocus) {
              return;
            }
            if (!element.value) {
              element.setSelectionRange(0, 0);
              return;
            }

            if (instance.mask.placeholder) {
              const placeholderChar = config.placeholderChar;
              const pos = element.value.indexOf(placeholderChar);
              if (pos > -1) {
                element.setSelectionRange(pos, pos);
                return;
              }
            }

            if (instance.mask.groupSizes && instance.mask.groupSizes.length) {
              element.setSelectionRange(0, instance.mask.groupSizes[0]);
            }
          }, 10);
        }

        FormError.clearFieldError(element.id);
      },
      blur: (e) => {
        if (instance.mask && config.maskOnBlur) {
          const result = this.formatValue(element.value, instance.mask);
          if (result.formatted !== element.value) {
            element.value = result.formatted;
          }

          instance.validate(result.formatted, true);
        }
      },
      keydown: (e) => {
        if (!instance.mask) return;

        // A mask with its own formatter (ip) has no fixed group sizes: deleting a
        // separator together with the digit before it, as below, merged octets
        // (192.|168 → 191.68). Let the browser delete one character; the input
        // handler lays the rest out again.
        if ((e.key === 'Backspace' || e.key === 'Delete') && typeof instance.mask.formatValue === 'function') {
          return;
        }

        // Handle Backspace key
        if (e.key === 'Backspace') {
          const curPos = element.selectionStart;
          const selEnd = element.selectionEnd;
          const value = element.value;
          const {separators} = instance.mask;

          // If there's a selection, let default behavior handle it
          if (curPos !== selEnd) {
            return;
          }

          // If cursor is at the beginning, nothing to delete
          if (curPos === 0) {
            return;
          }

          // Check if the character before cursor is a separator
          const charBeforeCursor = value[curPos - 1];
          const isSeparator = separators && separators.some(sep => sep === charBeforeCursor);

          if (isSeparator && curPos >= 2) {
            // Delete both the separator and the character before it
            e.preventDefault();
            const targetCursorPos = curPos - 2;
            const newValue = value.substring(0, targetCursorPos) + value.substring(curPos);
            element.value = newValue;

            // Store cursor position before triggering input event
            const privateState = this._privateState.get(element) || {};
            privateState.preserveCursorPos = targetCursorPos;
            this._privateState.set(element, privateState);

            // Trigger input event to update validation and formatting
            element.dispatchEvent(new Event('input', {bubbles: true}));
            return;
          } else {
            // For non-separator characters, preserve cursor position after formatting
            e.preventDefault();
            const targetCursorPos = curPos - 1;
            const newValue = value.substring(0, targetCursorPos) + value.substring(curPos);
            element.value = newValue;

            // Store cursor position before triggering input event
            const privateState = this._privateState.get(element) || {};
            privateState.preserveCursorPos = targetCursorPos;
            this._privateState.set(element, privateState);

            // Trigger input event to update validation and formatting
            element.dispatchEvent(new Event('input', {bubbles: true}));
            return;
          }
        }

        // Handle Delete key (forward delete)
        if (e.key === 'Delete') {
          const curPos = element.selectionStart;
          const selEnd = element.selectionEnd;
          const value = element.value;
          const {separators} = instance.mask;

          // If there's a selection, let default behavior handle it
          if (curPos !== selEnd) {
            return;
          }

          // If cursor is at the end, nothing to delete
          if (curPos >= value.length) {
            return;
          }

          // Check if the character after cursor is a separator
          const charAfterCursor = value[curPos];
          const isSeparator = separators && separators.some(sep => sep === charAfterCursor);

          if (isSeparator && curPos + 1 < value.length) {
            // Delete both the separator and the character after it
            e.preventDefault();
            const newValue = value.substring(0, curPos) + value.substring(curPos + 2);
            element.value = newValue;

            // Store cursor position before triggering input event
            const privateState = this._privateState.get(element) || {};
            privateState.preserveCursorPos = curPos;
            this._privateState.set(element, privateState);

            // Trigger input event to update validation and formatting
            element.dispatchEvent(new Event('input', {bubbles: true}));
            return;
          } else {
            // For non-separator characters, preserve cursor position after formatting
            e.preventDefault();
            const newValue = value.substring(0, curPos) + value.substring(curPos + 1);
            element.value = newValue;

            // Store cursor position before triggering input event
            const privateState = this._privateState.get(element) || {};
            privateState.preserveCursorPos = curPos;
            this._privateState.set(element, privateState);

            // Trigger input event to update validation and formatting
            element.dispatchEvent(new Event('input', {bubbles: true}));
            return;
          }
        }


        if (e.key.length !== 1 || e.ctrlKey || e.altKey || e.metaKey ||
          ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Tab', 'Escape', 'Enter'].includes(e.key)) {
          return;
        }

        // A mask with its own formatter decides the layout; only filter the characters
        if (typeof instance.mask.formatValue === 'function') {
          if (instance.mask.allowedChars && !instance.mask.allowedChars.test(e.key)) {
            e.preventDefault();
          }
          return;
        }

        const curPos = element.selectionStart;
        const value = element.value;
        const {mask, separators} = instance.mask;

        // Find the mask position by counting non-separator characters
        let maskPos = 0;
        let charCount = 0;

        for (let i = 0; i < curPos && maskPos < mask.length; i++) {
          const isSep = separators && separators.some(sep => value[i] === sep);
          if (!isSep) {
            charCount++;
          }
        }

        // Find the corresponding position in mask
        let maskCharPos = 0;
        let nonSepCount = 0;
        while (maskCharPos < mask.length && nonSepCount < charCount) {
          const maskChar = mask[maskCharPos];
          const pattern = this.config.inputPatterns[maskChar];
          if (pattern) {
            nonSepCount++;
          }
          maskCharPos++;
        }

        // Get the current mask character we're trying to fill
        while (maskCharPos < mask.length) {
          const maskChar = mask[maskCharPos];
          const pattern = this.config.inputPatterns[maskChar];
          if (pattern) {
            // Check if the key matches this pattern
            if (!pattern.test(e.key)) {
              e.preventDefault();
            }
            return;
          }
          maskCharPos++;
        }
      },

    };

    EventSystemManager.addHandler(element, 'input', handlers.input);
    EventSystemManager.addHandler(element, 'change', handlers.change);
    EventSystemManager.addHandler(element, 'focus', handlers.focus);
    EventSystemManager.addHandler(element, 'blur', handlers.blur);
    EventSystemManager.addHandler(element, 'keydown', handlers.keydown);
  }

  /**
   * Work out which mask an element should use and attach it to the instance.
   *
   * Resolved in order: an explicit `config.mask` object, a named mask from
   * `config.format` (tel, date and the rest of `config.masks`), then a raw
   * pattern (`data-pattern`, kept as `config.maskPattern` — see
   * patternConfig()). A pattern without a placeholder gets one generated by
   * replacing its input characters with `placeholderChar`.
   *
   * @param {Object} instance - Element instance to configure.
   * @returns {void}
   */
  static setupMask(instance) {
    const {element, config} = instance;
    let maskConfig = null;

    if (config.mask && typeof config.mask === 'object') {
      maskConfig = {...config.mask};
    } else if (config.format && this.config.masks[config.format]) {
      maskConfig = {...this.config.masks[config.format]};
    } else if (config.maskPattern) {
      maskConfig = {
        mask: config.maskPattern,
        placeholder: config.placeholder || config.maskPattern.replace(/[9aA*#X]/g, this.config.placeholderChar),
        inputMode: config.inputMode || 'text',
      };
    }

    if (!maskConfig || !maskConfig.mask) return;

    const structure = this.calculateMaskStructure(maskConfig.mask);
    maskConfig.separators = structure.separators;
    maskConfig.groups = structure.groups;
    maskConfig.groupSizes = structure.groupSizes;

    maskConfig.allowPartial = maskConfig.allowPartial ?? this.config.allowPartial;
    maskConfig.format = config.format;

    if (config.placeholder) {
      maskConfig.placeholder = config.placeholder;
    }
    instance.mask = maskConfig;

    config.formatter = (value) => {
      const {formatted, newCursorPos} = this.formatValue(value, maskConfig);
      element.setSelectionRange(newCursorPos, newCursorPos);
      return formatted;
    };

    instance.validateSpecific = function(value) {
      const message = () => Now.translate(maskConfig.message || 'Invalid value');
      if (maskConfig.validator && !new RegExp(maskConfig.validator).test(value)) {
        return message();
      }
      if (typeof maskConfig.validate === 'function' && !maskConfig.validate(value)) {
        return message();
      }
      return null;
    };
  }

  /**
   * The anchored regular expression a mask stands for: `X-99` becomes
   * `^.-[0-9][0-9]$` — each input character by its `inputPatterns` class,
   * every other character as itself.
   *
   * @param {string} mask - Mask pattern such as `99/99/9999`.
   * @returns {string} - Expression source for the native `pattern` attribute.
   */
  static maskPattern(mask) {
    const classes = {'9': '[0-9]', 'a': '[a-zA-Z]', 'A': '[a-zA-Z]', '*': '[a-zA-Z0-9]', '#': '[0-9.,\\-]', 'X': '.'};
    return '^' + Array.from(String(mask)).map(char => classes[char] || char.replace(/[\\^$.*+?()[\]{}|\/]/g, '\\$&')).join('') + '$';
  }

  /**
   * Split a mask pattern into its input groups and the separators between them.
   *
   * For `99-9999-9999` this yields groups of 2, 4 and 4 with `-` as separator,
   * which is what the formatter needs to place characters and move the cursor.
   *
   * @param {string} maskPattern - Mask string, e.g. `99/99/9999`.
   * @returns {Object} - `{groups, groupSizes, separators}` describing the mask.
   */
  static calculateMaskStructure(maskPattern) {
    const inputCharacters = Object.keys(this.config.inputPatterns);
    let groups = [];
    let currentGroup = '';
    let separators = [];

    for (let i = 0; i < maskPattern.length; i++) {
      const char = maskPattern[i];
      if (inputCharacters.includes(char)) {
        currentGroup += char;
      } else {
        groups.push(currentGroup);
        currentGroup = '';
        if (i < maskPattern.length) {
          separators.push(char);
        }
      }
    }
    if (currentGroup) {
      groups.push(currentGroup);
    }

    const groupSizes = groups.map(group => group.length);

    return {
      separators,
      groups,
      groupSizes
    };
  }

  /**
   * Lay typed text out as an IPv4 address: digits fill an octet of up to three,
   * a typed dot (or a fourth digit) starts the next one, at most four octets.
   * A trailing dot is kept while the user is typing the next octet.
   *
   * @param {string} value - Raw input.
   * @returns {string} - `192.168.0.1`, or a partial address such as `192.168.`.
   */
  static formatIpv4(value) {
    const octets = [''];
    for (const char of String(value)) {
      const last = octets.length - 1;
      if (char === '.') {
        if (octets[last] !== '' && octets.length < 4) octets.push('');
      } else if (/[0-9]/.test(char)) {
        if (octets[last].length === 3) {
          if (octets.length === 4) break;
          octets.push(char);
        } else {
          octets[last] += char;
        }
      }
    }
    return octets.join('.');
  }

  /**
   * Whether the value is a complete IPv4 address, each octet 0-255.
   *
   * @param {string} value - `192.168.0.1`.
   * @returns {boolean}
   */
  static isValidIpv4(value) {
    const parts = String(value).split('.');
    return parts.length === 4 && parts.every(part => /^[0-9]{1,3}$/.test(part) && Number(part) <= 255);
  }

  /**
   * Whether the value is a time of day `HH:MM`, 00:00-23:59.
   *
   * @param {string} value - `23:59`.
   * @returns {boolean}
   */
  static isValidTime(value) {
    return /^([01][0-9]|2[0-3]):[0-5][0-9]$/.test(String(value ?? ''));
  }

  /**
   * Whether the value is a real calendar date `DD/MM/YYYY` (31/02 is not). A
   * year above 2400 is read as Buddhist Era (minus 543), so 29/02/2567 is valid.
   *
   * @param {string} value - `29/02/2024`.
   * @returns {boolean}
   */
  static isValidDate(value) {
    const match = /^([0-9]{2})\/([0-9]{2})\/([0-9]{4})$/.exec(String(value ?? ''));
    if (!match) return false;
    const day = Number(match[1]);
    const month = Number(match[2]);
    let year = Number(match[3]);
    if (year > 2400) year -= 543;
    if (month < 1 || month > 12 || day < 1) return false;
    return day <= new Date(year, month, 0).getDate();
  }

  /**
   * Thai national ID check digit: the 13th digit is
   * (11 - sum(digit[i] * (13 - i)) mod 11) mod 10 over the first 12.
   *
   * @param {string} value - `1-1017-00230-70-5` or 13 digits.
   * @returns {boolean}
   */
  static isValidThaiId(value) {
    const digits = String(value ?? '').replace(/[^0-9]/g, '');
    if (digits.length !== 13) return false;
    let sum = 0;
    for (let i = 0; i < 12; i++) sum += Number(digits[i]) * (13 - i);
    return (11 - (sum % 11)) % 10 === Number(digits[12]);
  }

  /**
   * Card number check (Luhn), 13-19 digits.
   *
   * @param {string} value - `4111-1111-1111-1111`.
   * @returns {boolean}
   */
  static isValidCardNumber(value) {
    const digits = String(value ?? '').replace(/[^0-9]/g, '');
    if (digits.length < 13 || digits.length > 19) return false;
    let sum = 0;
    for (let i = 0; i < digits.length; i++) {
      let digit = Number(digits[digits.length - 1 - i]);
      if (i % 2 === 1) {
        digit *= 2;
        if (digit > 9) digit -= 9;
      }
      sum += digit;
    }
    return sum % 10 === 0;
  }

  /**
   * Format raw input against a mask and work out where the caret should land.
   *
   * Separators are stripped from the incoming value first, so the result is
   * stable whether the user typed them or not. The previous value and caret
   * position are used to keep the caret sensible while editing mid-string.
   *
   * @param {string} value - Current raw value from the input.
   * @param {Object} maskConfig - Mask description from `calculateMaskStructure`.
   * @param {string} [previousValue=''] - Value before this edit.
   * @param {number} [inputCursorPos=0] - Caret position before this edit.
   * @returns {Object} - `{formatted, newCursorPos}`.
   */
  static formatValue(value, maskConfig, previousValue = '', inputCursorPos = 0) {
    const {mask, allowPartial, separators, groupSizes} = maskConfig;

    if (!value || value.trim() === '') {
      return {formatted: '', newCursorPos: 0};
    }

    // A mask with its own formatter (ip): the caret lands where the text before
    // it ends once formatted
    if (typeof maskConfig.formatValue === 'function') {
      const formatted = maskConfig.formatValue(value);
      const before = inputCursorPos > 0 && inputCursorPos < value.length
        ? maskConfig.formatValue(value.slice(0, inputCursorPos)).length
        : formatted.length;
      return {formatted, newCursorPos: Math.min(before, formatted.length)};
    }

    // First, extract only the valid characters (remove separators)
    let cleanValue = '';
    let charsBeforeCursor = 0;

    for (let i = 0; i < value.length; i++) {
      const char = value[i];
      const isSep = separators && separators.some(sep => sep === char);
      if (!isSep) {
        cleanValue += char;
        // Count how many actual characters are before the cursor position
        if (i < inputCursorPos) {
          charsBeforeCursor++;
        }
      }
    }

    // Now format the clean value according to the mask
    let formatted = '';
    let markPosition = 0;
    let cleanPos = 0;
    let newCursorPos = 0;

    let maskChar = mask[markPosition];
    while (maskChar && cleanPos < cleanValue.length) {
      const char = cleanValue[cleanPos];
      const pattern = this.config.inputPatterns[maskChar];

      if (pattern) {
        // This is a character position in the mask
        if (char && pattern.test(char)) {
          formatted += char;
          cleanPos++;

          // If we've processed all characters that were before cursor, set cursor here
          if (cleanPos === charsBeforeCursor) {
            newCursorPos = formatted.length;
          }
        } else {
          // Character doesn't match pattern, skip it
          cleanPos++;
          continue;
        }
      } else {
        // This is a separator in the mask
        formatted += maskChar;

        // If we just passed the cursor position, update it to after separator
        if (cleanPos === charsBeforeCursor && newCursorPos < formatted.length) {
          newCursorPos = formatted.length;
        }
      }

      markPosition++;
      maskChar = mask[markPosition];
    }

    // If cursor wasn't set (e.g., at the end), put it at the end
    if (newCursorPos === 0 || charsBeforeCursor >= cleanValue.length) {
      newCursorPos = formatted.length;
    }

    return {formatted, newCursorPos};
  }
}

class TelElementFactory extends MaskElementFactory {
  static config = {
    ...MaskElementFactory.config,
    type: 'text',
    format: 'tel',
    inputMode: 'tel'
  };
}

ElementManager.registerElement('mask', MaskElementFactory);

// Export to global scope
window.MaskElementFactory = MaskElementFactory;
ElementManager.registerElement('tel', TelElementFactory);