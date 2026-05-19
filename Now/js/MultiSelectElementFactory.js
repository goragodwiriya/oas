/**
 * MultiSelectElementFactory - Factory for creating custom multiple select elements
 *
 * Converts <select multiple> into a custom DIV-based UI with:
 * - Custom trigger showing selected items (max 2, then "+n items")
 * - DropdownPanel for options list with check/uncheck icons
 */

class MultiSelectElementFactory extends ElementFactory {
  static config = {
    ...ElementFactory.config,
    maxDisplayItems: 2,
    emptyMessage: 'No options available',
    validationMessages: {
      required: 'Please select at least one option'
    }
  };

  static propertyHandlers = {
    value: {
      get(element) {
        // Get values from select element's selectedOptions
        if (!element || element.tagName !== 'SELECT') return [];
        return Array.from(element.selectedOptions).map(opt => opt.value);
      },
      set(instance, newValue) {
        // Support both array and single value
        if (!Array.isArray(newValue)) {
          // Try to parse as JSON array
          if (typeof newValue === 'string' && newValue.startsWith('[')) {
            try {
              newValue = JSON.parse(newValue);
            } catch (e) {
              // Not JSON, treat as single value
              newValue = newValue ? [newValue] : [];
            }
          } else {
            newValue = newValue ? [newValue] : [];
          }
        }

        this.setSelectedValues(instance.element, newValue);
      }
    },

    selectedOptions: {
      get(element) {
        if (!element || element.tagName !== 'SELECT') return [];

        return Array.from(element.selectedOptions).map(opt => ({
          value: opt.value,
          text: opt.textContent,
          data: opt.dataset
        }));
      }
    }
  };

  static setupElement(instance) {
    const {element, config} = instance;

    // Verify this is a select[multiple]
    if (element.tagName !== 'SELECT' || !element.multiple) {
      console.error('MultiSelectElementFactory: Element must be a <select multiple>');
      return instance;
    }

    // Preserve original option text for locale re-translation
    Array.from(element.options).forEach(opt => {
      if (!opt.dataset.i18n) {
        opt.dataset.i18n = opt.textContent;
      }
    });

    // Get placeholder
    const placeholder = element.dataset.placeholder ||
      element.getAttribute('placeholder') ||
      config.placeholder || '';

    // Hide original select
    element.style.display = 'none';
    element.style.height = '100px';
    element.style.width = '100px';
    element.setAttribute('aria-hidden', 'true');

    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'dropdown-button';
    if (element.required) trigger.dataset.required = 'true';
    if (element.disabled) trigger.classList.add('disabled');
    trigger.setAttribute('role', 'button');
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');
    element.parentNode.insertBefore(trigger, element.nextSibling);

    // Create selected items display
    const selectedDisplay = document.createElement('span');
    selectedDisplay.className = 'dropdown-display';
    selectedDisplay.textContent = placeholder;
    if (placeholder) {
      selectedDisplay.classList.add('placeholder');
    }
    trigger.appendChild(selectedDisplay);

    // Create dropdown icon
    const icon = document.createElement('span');
    icon.className = 'dropdown-arrow';
    trigger.appendChild(icon);

    // Update instance - keep element as original select
    instance.element = element;
    instance.trigger = trigger;
    instance.selectedDisplay = selectedDisplay;
    instance.placeholder = placeholder;

    // Use DropdownPanel instead of creating dropdown element
    instance.dropdownPanel = DropdownPanel.getInstance();
    instance.dropdown = document.createElement('ul');
    instance.dropdown.className = 'autocomplete-list';
    instance.dropdown.setAttribute('tabindex', '0');

    // Initialize keyboard navigation state
    instance.highlightedIndex = -1;

    // Setup dropdown keyboard navigation (once only)
    this.setupDropdownKeyboardNavigation(instance);

    // Setup event handlers
    this.setupEventHandlers(instance);

    // Setup locale change handler to retranslate options and display text
    instance._localeChangeHandler = () => {
      try {
        this.retranslate(instance);
      } catch (e) {
        // best-effort
      }
    };

    try {
      EventManager.on && EventManager.on('locale:changed', instance._localeChangeHandler);
    } catch (e) {}

    // Initial translation pass to ensure tokens render in current locale
    this.retranslate(instance);

    // Initialize display
    this.updateDisplay(instance);

    // Add instance methods
    instance.updateOptions = (options) => {
      this.updateOptions(element, options);
      this.updateDisplay(instance);
    };

    instance.setValue = (values) => {
      this.setSelectedValues(element, values);
    };

    instance.getValue = () => {
      return this.getSelectedValues(element);
    };

    instance.clear = () => {
      this.clearSelection(instance);
    };

    return instance;
  }

  static setupDropdownKeyboardNavigation(instance) {
    if (instance._dropdownKeyboardSetup) return;
    instance._dropdownKeyboardSetup = true;

    const {dropdown, element} = instance;

    // Setup keyboard navigation on dropdown
    const handleDropdownKeydown = (e) => {
      const items = Array.from(dropdown.querySelectorAll('li[data-index]'));
      if (items.length === 0) return;

      switch (e.key) {
        case 'ArrowDown':
          e.preventDefault();
          instance.highlightedIndex = Math.min(instance.highlightedIndex + 1, items.length - 1);
          this.updateHighlight(instance, items);
          break;

        case 'ArrowUp':
          e.preventDefault();
          instance.highlightedIndex = Math.max(instance.highlightedIndex - 1, 0);
          this.updateHighlight(instance, items);
          break;

        case ' ':
        case 'Spacebar':
          e.preventDefault();
          if (instance.highlightedIndex >= 0 && instance.highlightedIndex < items.length) {
            const highlightedItem = items[instance.highlightedIndex];
            const value = highlightedItem.dataset.value;
            const icon = highlightedItem.querySelector('span');

            const currentlySelected = this.getSelectedValues(element);
            const isCurrentlySelected = currentlySelected.includes(value);

            if (isCurrentlySelected) {
              this.removeValue(element, value);
              highlightedItem.setAttribute('aria-selected', 'false');
              icon.className = 'icon-uncheck';
            } else {
              this.addValue(element, value, highlightedItem.querySelector('span:last-child').textContent);
              highlightedItem.setAttribute('aria-selected', 'true');
              icon.className = 'icon-check';
            }

            this.updateDisplay(instance);
            this.triggerChange(instance);
          }
          break;

        case 'Tab':
        case 'Escape':
          e.preventDefault();
          const escPanel = instance.dropdownPanel;
          if (escPanel && escPanel.isOpen() && escPanel.currentTarget === instance.trigger) {
            escPanel.hide();
            instance.trigger.setAttribute('aria-expanded', 'false');
            instance.highlightedIndex = -1;
            instance.trigger.focus();
          }
          break;
      }
    };

    // Handle blur on dropdown
    const handleDropdownBlur = (e) => {
      setTimeout(() => {
        const panel = instance.dropdownPanel;
        if (!panel) return;

        const activeElement = document.activeElement;
        const isDropdownFocused = dropdown.contains(activeElement) || activeElement === dropdown;
        const isTriggerFocused = activeElement === instance.trigger;

        if (!isDropdownFocused && !isTriggerFocused && panel.isOpen() && panel.currentTarget === instance.trigger) {
          panel.hide();
          instance.trigger.setAttribute('aria-expanded', 'false');
          instance.highlightedIndex = -1;
        }
      }, 100);
    };

    dropdown.addEventListener('keydown', handleDropdownKeydown);
    dropdown.addEventListener('blur', handleDropdownBlur);

    // Store cleanup for dropdown listeners
    instance._dropdownCleanup = () => {
      dropdown.removeEventListener('keydown', handleDropdownKeydown);
      dropdown.removeEventListener('blur', handleDropdownBlur);
    };
  }

  static setupEventHandlers(instance) {
    const {trigger} = instance;

    // Handle trigger click
    const handleTriggerClick = (e) => {
      e.preventDefault();
      e.stopPropagation();

      if (trigger.classList.contains('disabled')) return;

      const panel = instance.dropdownPanel;
      if (!panel) return;

      if (panel.isOpen() && panel.currentTarget === trigger) {
        panel.hide();
        trigger.setAttribute('aria-expanded', 'false');
        instance.highlightedIndex = -1;
      } else {
        // populate dropdown with options
        this.createOptionsList(instance);

        // Show dropdown in DropdownPanel
        panel.show(trigger, instance.dropdown, {
          align: 'left',
          offsetY: 5,
          onClose: () => {
            trigger.setAttribute('aria-expanded', 'false');
            instance.highlightedIndex = -1;
          }
        });

        trigger.setAttribute('aria-expanded', 'true');
        instance.highlightedIndex = -1;

        // Focus on dropdown for keyboard navigation
        setTimeout(() => {
          instance.dropdown.focus();
        }, 50);
      }
    };

    trigger.addEventListener('click', handleTriggerClick);

    // Keyboard navigation on trigger
    const handleTriggerKeydown = (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        handleTriggerClick(e);
      }
    };

    trigger.addEventListener('keydown', handleTriggerKeydown);

    // Handle blur on trigger to close dropdown
    const handleTriggerBlur = (e) => {
      // Delay to check if focus moved to dropdown
      setTimeout(() => {
        const panel = instance.dropdownPanel;
        if (!panel) return;

        const activeElement = document.activeElement;
        const isDropdownFocused = instance.dropdown.contains(activeElement) ||
          activeElement === instance.dropdown;
        const isTriggerFocused = activeElement === trigger;

        if (!isDropdownFocused && !isTriggerFocused && panel.isOpen() && panel.currentTarget === trigger) {
          panel.hide();
          trigger.setAttribute('aria-expanded', 'false');
          instance.highlightedIndex = -1;
        }
      }, 100);
    };

    trigger.addEventListener('blur', handleTriggerBlur);

    // Store cleanup
    instance._cleanup = () => {
      trigger.removeEventListener('click', handleTriggerClick);
      trigger.removeEventListener('keydown', handleTriggerKeydown);
      trigger.removeEventListener('blur', handleTriggerBlur);
    };

    return super.setupEventListeners?.(instance) || {};
  }

  static createOptionsList(instance) {
    const {element, dropdown, config} = instance;

    // clear dropdown
    dropdown.innerHTML = '';

    const options = Array.from(element.options).filter(opt =>
      opt.value !== '' && !opt.disabled
    );

    if (options.length === 0) {
      const emptyMsg = document.createElement('li');
      emptyMsg.className = 'multi-select-empty';
      emptyMsg.textContent = Now.translate(config.emptyMessage);
      dropdown.appendChild(emptyMsg);
      return;
    }

    // Get current selected values
    const selectedValues = this.getSelectedValues(element);

    options.forEach((option, index) => {
      const item = document.createElement('li');
      item.dataset.value = option.value;
      item.dataset.index = index;
      item.setAttribute('role', 'option');
      item.setAttribute('tabindex', '-1');

      const icon = document.createElement('span');
      item.appendChild(icon);

      const isSelected = selectedValues.includes(option.value);

      if (isSelected) {
        icon.className = 'icon-check';
        item.setAttribute('aria-selected', 'true');
      } else {
        icon.className = 'icon-uncheck';
      }

      const label = document.createElement('span');
      // option.textContent already translated when options were created/updated
      label.textContent = option.textContent;
      if (option.dataset.i18n) {
        label.setAttribute('data-i18n', option.dataset.i18n);
      }
      item.appendChild(label);

      // Handle selection
      const handleSelect = (e) => {
        e.stopPropagation();

        const currentlySelected = this.getSelectedValues(element);
        const isCurrentlySelected = currentlySelected.includes(option.value);

        if (isCurrentlySelected) {
          // Unselect
          this.removeValue(element, option.value);
          item.setAttribute('aria-selected', 'false');
          icon.className = 'icon-uncheck';
        } else {
          // Select
          this.addValue(element, option.value, option.textContent);
          item.setAttribute('aria-selected', 'true');
          icon.className = 'icon-check';
        }

        this.updateDisplay(instance);
        this.triggerChange(instance);
      };

      item.addEventListener('click', handleSelect);

      dropdown.appendChild(item);
    });

    // Make dropdown focusable
    dropdown.setAttribute('tabindex', '0');
  }

  static updateHighlight(instance, items) {
    // Remove previous highlight
    items.forEach(item => item.classList.remove('active'));

    // Add highlight to current item
    if (instance.highlightedIndex >= 0 && instance.highlightedIndex < items.length) {
      const highlightedItem = items[instance.highlightedIndex];
      highlightedItem.classList.add('active');

      // Scroll into view if needed
      highlightedItem.scrollIntoView({
        block: 'nearest',
        behavior: 'smooth'
      });
    }
  }

  static getSelectedValues(element) {
    // Get values from original select element
    if (!element || element.tagName !== 'SELECT') return [];

    return Array.from(element.selectedOptions).map(opt => opt.value);
  }

  static addValue(element, value, text) {
    if (!element || element.tagName !== 'SELECT') return;

    // Find option and select it - use loose equality for type coercion
    const option = Array.from(element.options).find(opt =>
      opt.value == value || String(opt.value) === String(value)
    );
    if (option) {
      option.selected = true;
    }
  }

  static removeValue(element, value) {
    if (!element || element.tagName !== 'SELECT') return;

    // Find option and deselect it - use loose equality for type coercion
    const option = Array.from(element.options).find(opt =>
      opt.value == value || String(opt.value) === String(value)
    );
    if (option) {
      option.selected = false;
    }
  }

  static setSelectedValues(element, values) {
    if (!Array.isArray(values)) values = [];
    if (!element || element.tagName !== 'SELECT') return;

    // Convert values to strings for comparison (handles number/string mismatch)
    const valueStrings = values.map(v => String(v));

    // Clear all selections
    Array.from(element.options).forEach(opt => opt.selected = false);

    // Set new selections
    Array.from(element.options).forEach(opt => {
      if (valueStrings.includes(opt.value)) {
        opt.selected = true;
      }
    });

    // Update display
    const instance = ElementManager.getInstanceByElement(element);
    if (instance) {
      this.updateDisplay(instance);
    }
  }

  static updateDisplay(instance) {
    const {element, selectedDisplay, placeholder, config} = instance;
    if (!element || element.tagName !== 'SELECT') return;

    const selectedOptions = Array.from(element.selectedOptions);
    const selectedCount = selectedOptions.length;

    if (selectedCount === 0) {
      let translatedPlaceholder = placeholder || '';
      try {
        translatedPlaceholder = Now.translate ? Now.translate(placeholder || '') : (placeholder || '');
      } catch (e) {
        translatedPlaceholder = placeholder || '';
      }
      selectedDisplay.textContent = translatedPlaceholder;
      selectedDisplay.classList.add('placeholder');
      return;
    }

    selectedDisplay.classList.remove('placeholder');

    const maxDisplay = config.maxDisplayItems || 2;

    if (selectedCount <= maxDisplay) {
      const texts = selectedOptions.map(opt => opt.textContent);
      selectedDisplay.textContent = texts.join(', ');
    } else {
      const firstItems = selectedOptions
        .slice(0, maxDisplay)
        .map(opt => opt.textContent);
      const remaining = selectedCount - maxDisplay;
      selectedDisplay.textContent = `${firstItems.join(', ')} +${remaining} ${Now.translate('items')}`;
    }
  }

  static triggerChange(instance) {
    const {element} = instance;

    // Trigger change on original select
    if (element && element.tagName === 'SELECT') {
      element.dispatchEvent(new Event('change', {bubbles: true}));
    }

    // Call onChange callback if exists
    if (instance.config && typeof instance.config.onChange === 'function') {
      const values = this.getSelectedValues(element);
      instance.config.onChange(element, values);
    }
  }

  static retranslate(instance) {
    if (!instance || !instance.element || instance.element.tagName !== 'SELECT') return;

    const translate = (text) => {
      try {
        return Now.translate ? Now.translate(text || '') : (text || '');
      } catch (e) {
        return text || '';
      }
    };

    Array.from(instance.element.options).forEach(opt => {
      const sourceText = (opt.dataset && opt.dataset.i18n) ? opt.dataset.i18n : opt.textContent;
      if (sourceText !== undefined) {
        opt.textContent = translate(sourceText);
      }
    });

    this.updateDisplay(instance);
  }

  static clearSelection(instance) {
    const {element} = instance;

    // Clear all selections in original select
    if (element && element.tagName === 'SELECT') {
      Array.from(element.options).forEach(opt => opt.selected = false);
    }

    this.updateDisplay(instance);
    this.triggerChange(instance);
  }

  static updateOptions(selectElement, options) {
    if (!selectElement || selectElement.tagName !== 'SELECT') return;

    const normalizedOptions = Utils.options?.flattenGroups
      ? Utils.options.flattenGroups(Utils.options.normalizeSource(options))
      : (Array.isArray(options) ? options : []);

    // Store current selected values
    const currentValues = Array.from(selectElement.selectedOptions).map(opt => opt.value);

    // Clear existing options (except placeholder)
    const placeholder = selectElement.querySelector('option[value=""][disabled]');
    selectElement.innerHTML = '';
    if (placeholder) {
      selectElement.appendChild(placeholder);
    }

    // Add new options (translate text and preserve i18n key)
    if (Array.isArray(normalizedOptions)) {
      normalizedOptions.forEach(opt => {
        const option = document.createElement('option');
        option.value = opt.value || '';
        option.textContent = Now.translate(opt.text || opt.label || opt.value || '');
        if (opt.text || opt.label) option.dataset.i18n = opt.text || opt.label;
        if (opt.disabled) option.disabled = true;
        if (opt.data) {
          Object.entries(opt.data).forEach(([key, value]) => {
            option.dataset[key] = value;
          });
        }
        selectElement.appendChild(option);
      });
    }

    // Restore selected values if they still exist
    const currentValueStrings = currentValues.map(v => String(v));

    Array.from(selectElement.options).forEach(option => {
      if (currentValueStrings.includes(option.value)) {
        option.selected = true;
      }
    });
  }

  static customValidateValue(value) {
    const element = this.element;
    const isRequired = element.required || element.dataset.required === 'true';

    if (isRequired) {
      const selectedOptions = element.selectedOptions;
      if (!selectedOptions || selectedOptions.length === 0) {
        return {
          validatedValue: value,
          error: Now.translate(this.config.validationMessages.required)
        };
      }
    }

    return {
      validatedValue: value,
      error: null
    };
  }

  static cleanup(instance) {
    if (!instance) return;

    if (instance._cleanup) {
      instance._cleanup();
    }

    // Cleanup dropdown listeners
    if (instance._dropdownCleanup) {
      instance._dropdownCleanup();
    }

    // Remove locale change handler
    if (instance._localeChangeHandler && window.EventManager) {
      EventManager.off('locale:changed', instance._localeChangeHandler);
      delete instance._localeChangeHandler;
    }

    // Close dropdown if open
    const panel = window.DropdownPanel?.getInstance();
    if (panel && panel.currentTarget === instance.trigger) {
      panel.hide();
    }

    // Remove trigger button
    if (instance.trigger && instance.trigger.parentNode) {
      instance.trigger.parentNode.removeChild(instance.trigger);
    }

    // Restore original select
    if (instance.element && instance.element.tagName === 'SELECT') {
      instance.element.style.display = '';
      instance.element.removeAttribute('aria-hidden');
    }

    super.cleanup?.(instance);
  }

  static create(def) {
    // Create select element from definition
    const select = document.createElement('select');
    select.multiple = true;
    select.id = def.id || `multiselect-${Math.random().toString(36).substr(2, 9)}`;

    if (def.name) select.name = def.name;
    if (def.className) select.className = def.className;
    if (def.required) select.required = true;
    if (def.disabled) select.disabled = true;

    // Add options if provided
    if (def.options && Array.isArray(def.options)) {
      def.options.forEach(opt => {
        const option = document.createElement('option');
        if (typeof opt === 'object') {
          option.value = opt.value !== undefined ? opt.value : opt.id;
          option.textContent = opt.text || opt.label || opt.name || opt.value;
          if (opt.selected) option.selected = true;
        } else {
          option.value = opt;
          option.textContent = opt;
        }
        select.appendChild(option);
      });
    }

    // Create wrapper if specified
    if (def.wrapper) {
      this.createWrapper(select, def);
    }

    // Enhance the select element
    return this.enhance(select, def);
  }

  /**
   * Populate select element from provided options object
   * Used when options are provided by modal response or parent context
   * @param {HTMLSelectElement} element - Select element
   * @param {Object} optionsData - Options data object (e.g., {provinces: [...], genders: [...]})
   * @param {String} optionsKey - Key to extract from optionsData (e.g., 'provinces')
   * @static
   */
  static populateFromOptions(element, optionsData, optionsKey) {
    if (!element || !optionsData || !optionsKey) return;

    let options = Utils.options?.flattenGroups
      ? Utils.options.flattenGroups(Utils.options.normalizeSource(optionsData[optionsKey]))
      : optionsData[optionsKey];
    if (!options) return;

    if (!Array.isArray(options)) {
      console.warn('Please update your API to return options in Array, Map, or Object option format.');
      return;
    }

    // Save current selected values before updating options
    const currentSelectedValues = Array.from(element.selectedOptions).map(opt => opt.value);

    // Update element's options using existing updateOptions method
    this.updateOptions(element, options, element.dataset.useOptGroups === 'true');

    // Restore selected values after populating options
    if (currentSelectedValues.length > 0) {
      this.setSelectedValues(element, currentSelectedValues);
    }
  }

  /**
 * Populate all select elements with data-options-key attribute from provided options object
 * @param {HTMLElement} container - Container element (form, modal, etc.)
 * @param {Object} optionsData - Options data object
 * @static
 */
  static populateFromOptionsInContainer(container, optionsData) {
    if (!container || !optionsData) return;

    const selectsWithOptionsKey = container.querySelectorAll('select[data-options-key][multiple]');

    selectsWithOptionsKey.forEach(select => {
      const optionsKey = select.dataset.optionsKey;
      this.populateFromOptions(select, optionsData, optionsKey);
    });
  }
}

// Register with ElementManager
ElementManager.registerElement('select-multiple', MultiSelectElementFactory);

// Expose globally
window.MultiSelectElementFactory = MultiSelectElementFactory;