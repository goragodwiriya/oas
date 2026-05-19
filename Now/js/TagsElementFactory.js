/**
 * TagsElementFactory - Factory for creating tags/chips input elements
 *
 * Converts a text input into a tags input with:
 * - Multiple value support as visual tags/chips
 * - Keyboard navigation (Enter to add, Backspace to remove)
 * - Autocomplete integration
 * - Hidden inputs for form submission
 * - Click to focus behavior
 */

class TagsElementFactory extends ElementFactory {
  static config = {
    ...ElementFactory.config,
    type: 'text',
    placeholder: 'Add tags...',
    separator: ',', // Character to split pasted values
    maxTags: null, // Maximum number of tags (null = unlimited)
    duplicates: false, // Allow duplicate tags
    autocomplete: {
      enabled: false,
      source: null,
      minLength: 2,
      maxResults: 10,
      delay: 300
    },
    validationMessages: {
      maxTags: 'Maximum number of tags reached',
      duplicate: 'This tag already exists'
    }
  };

  static propertyHandlers = {
    value: {
      get(element) {
        // Read from hidden inputs container
        const wrapper = element.closest('.tags-input-wrapper');
        if (!wrapper) return [];

        const hiddenContainer = wrapper.querySelector('.tags-hidden-inputs');
        if (!hiddenContainer) return [];

        return Array.from(hiddenContainer.querySelectorAll('input[type="hidden"]')).map(input => input.value);
      },
      set(instance, newValue) {
        // Handle undefined/null/empty - clear all tags and input text
        if (newValue == null || newValue === '') {
          this.clearTags(instance);
          if (instance.input) instance.input.value = '';
          return;
        }

        // Guard: if value binding accidentally receives autocomplete/options
        // objects ({value,text}) instead of selected values, treat it as no
        // current selection. Otherwise the UI renders [object Object] tags.
        if (
          Array.isArray(newValue) &&
          newValue.length > 0 &&
          newValue.every(item => item && typeof item === 'object' && !Array.isArray(item) && 'value' in item && 'text' in item)
        ) {
          this.clearTags(instance);
          if (instance.input) instance.input.value = '';
          return;
        }

        // Resolve a single primitive value to {key, value} using autocomplete source
        const resolveTag = (val) => {
          if (typeof val === 'object' && val.key && val.value) return val;
          // Try to look up display text from autocomplete options
          const source = instance?.config?.autocomplete?.source;
          if (source) {
            const normalized = TextElementFactory.normalizeSource(source);
            if (normalized && Array.isArray(normalized)) {
              const found = normalized.find(item => String(item.value) === String(val));
              if (found) return {key: found.value, value: found.text};
            }
          }
          return {key: val, value: val};
        };

        // Handle array values (e.g. from API)
        if (Array.isArray(newValue)) {
          this.clearTags(instance);
          newValue.forEach(val => {
            const tag = resolveTag(val);
            this.addTag(instance, tag.key, tag.value);
          });
          // Clear the text input after setting tags programmatically
          if (instance.input) instance.input.value = '';
        } else if (typeof newValue === 'string') {
          // Try to parse JSON array string
          try {
            const parsed = JSON.parse(newValue);
            if (Array.isArray(parsed)) {
              this.clearTags(instance);
              parsed.forEach(val => {
                const tag = resolveTag(val);
                this.addTag(instance, tag.key, tag.value);
              });
              // Clear the text input after setting tags programmatically
              if (instance.input) instance.input.value = '';
              return;
            }
          } catch (e) {
            // Not a JSON array, treat as single value or split by separator
          }

          // Treat as single value or split by separator
          const values = newValue.split(instance.config.separator).map(v => v.trim()).filter(v => v);
          this.clearTags(instance);
          values.forEach(val => {
            const tag = resolveTag(val);
            this.addTag(instance, tag.key, tag.value);
          });
          // Clear the text input after setting tags programmatically
          if (instance.input) instance.input.value = '';
        }
      }
    }
  };

  static extractCustomConfig(element, def, dataset) {
    return {
      separator: dataset.separator || def.separator,
      maxTags: dataset.maxTags ? parseInt(dataset.maxTags) : def.maxTags,
      duplicates: dataset.duplicates === 'true' || def.duplicates,
      autocomplete: {
        enabled: dataset.autocomplete !== undefined ? dataset.autocomplete === 'true' : def.autocomplete?.enabled,
        source: dataset.source || def.autocomplete?.source,
        minLength: this.parseNumeric('minLength', element, def.autocomplete, dataset) || def.autocomplete?.minLength,
        maxResults: this.parseNumeric('maxResults', element, def.autocomplete, dataset) || def.autocomplete?.maxResults,
        delay: this.parseNumeric('delay', element, def.autocomplete, dataset) || def.autocomplete?.delay
      }
    };
  }

  static setupElement(instance) {
    const {element, config} = instance;

    // Store original name before modifying
    const originalName = element.getAttribute('name') || '';
    instance.originalName = originalName;

    // Store parent before moving element
    const originalParent = element.parentNode;

    // Store reference to original input before modifications
    instance.originalInput = element;

    // Change original input name to {name}_text for typing
    element.setAttribute('name', `${originalName}_text`);
    element.className = 'tags-input';

    // Store reference to original input (now used for typing)
    instance.input = element;

    // Create wrapper container
    const tagsWrapper = document.createElement('div');
    tagsWrapper.className = 'tags-input-wrapper';

    // Create tags container (ul)
    const tagsContainer = document.createElement('ul');
    tagsContainer.className = 'tags-container';

    // Create input li wrapper and move element into it
    const inputLi = document.createElement('li');
    inputLi.className = 'tags-input-li';
    inputLi.appendChild(element);

    tagsContainer.appendChild(inputLi);
    tagsWrapper.appendChild(tagsContainer);

    // Create hidden inputs container
    const hiddenInputsContainer = document.createElement('div');
    hiddenInputsContainer.className = 'tags-hidden-inputs';
    hiddenInputsContainer.style.display = 'none';
    tagsWrapper.appendChild(hiddenInputsContainer);

    // Insert wrapper in place of original element
    originalParent.appendChild(tagsWrapper);

    // Store references
    instance.tagsContainer = tagsContainer;
    instance.inputLi = inputLi;
    instance.hiddenInputsContainer = hiddenInputsContainer;
    instance.tags = []; // Array of {key, value} objects
    instance.tagElements = new Map(); // Map of key -> li element

    // Setup locale change handler to re-translate existing tags when locale changes
    instance._localeChangeHandler = () => {
      try {
        const i18n = (window.Now && Now.getManager) ? Now.getManager('i18n') : null;
        instance.tagElements.forEach((li, key) => {
          const tag = instance.tags.find(t => t.key === key);
          if (!tag) return;
          let displayValue = tag.value;

          try {
            if ((displayValue === key || !displayValue) && instance && instance.config && instance.config.autocomplete && instance.config.autocomplete.source) {
              const normalized = TextElementFactory.normalizeSource(instance.config.autocomplete.source);
              if (normalized && Array.isArray(normalized)) {
                const found = normalized.find(item => String(item.value) === String(key));
                if (found) displayValue = found.text;
              }
            }
          } catch (e) {
            displayValue = tag.value;
          }

          try {
            if (i18n && typeof i18n.interpolate === 'function') {
              displayValue = i18n.interpolate(displayValue, {});
            }
          } catch (e) {
            // ignore
          }

          const span = li.querySelector('.tag-text');
          if (span) span.textContent = displayValue;
        });
      } catch (e) {
        // best-effort
      }
    };

    try {
      EventManager.on && EventManager.on('locale:changed', instance._localeChangeHandler);
    } catch (e) {}

    // Setup autocomplete if enabled
    const acConfig = config.autocomplete;
    if (acConfig?.enabled || acConfig?.source) {
      acConfig.enabled = true;
      this.setupAutocomplete(instance);
    }

    // Setup event handlers
    this.setupEventHandlers(instance);

    // Load initial values from original input
    // Support both string and array values
    const initialValue = element.value; // Use 'element' which is now instance.input
    if (initialValue) {
      let values = [];

      // Try to parse as JSON array first
      try {
        const parsed = JSON.parse(initialValue);
        if (Array.isArray(parsed)) {
          values = parsed;
        } else {
          // Single value
          values = [initialValue];
        }
      } catch (e) {
        // Not JSON, split by separator
        values = initialValue.split(config.separator).map(v => v.trim()).filter(v => v);
      }

      values.forEach(value => {
        if (typeof value === 'object' && value.key && value.value) {
          // Object format: {key, value}
          this.addTag(instance, value.key, value.value);
        } else {
          // String format
          this.addTag(instance, value, value);
        }
      });
      // Clear the text input so the raw initial value isn't shown in the typing box
      element.value = '';
    }

    // Add instance methods
    instance.addTag = (key, value) => this.addTag(instance, key, value);
    instance.removeTag = (key) => this.removeTag(instance, key);
    instance.clear = () => this.clearTags(instance);
    instance.getTags = () => this.getTags(instance);
    instance.setTags = (tags) => this.setTags(instance, tags);
    instance.updateOptions = (options) => this.updateOptions(instance, options);

    // Add setValue for FormManager compatibility
    instance.setValue = (value) => {
      if (this.propertyHandlers && this.propertyHandlers.value && this.propertyHandlers.value.set) {
        this.propertyHandlers.value.set.call(this, instance, value);
      }
    };

    return instance;
  }

  static setupAutocomplete(instance) {
    const {input, config} = instance;
    const acConfig = config.autocomplete;

    // Use DropdownPanel
    instance.dropdownPanel = DropdownPanel.getInstance();
    instance.dropdown = document.createElement('ul');
    instance.dropdown.className = 'autocomplete-list';

    instance.list = [];
    instance.listIndex = 0;

    // Read from datalist if exists
    const dataFromDatalist = this.readFromDatalist(instance.originalInput);
    if (dataFromDatalist) {
      acConfig.source = dataFromDatalist;
    }

    instance.populate = (data) => {
      if (document.activeElement !== input && !instance._isActive) return;

      instance.dropdown.innerHTML = '';
      instance.list = [];
      const search = input.value.trim();
      const filter = new RegExp(`(${this.escapeRegExp(search)})`, 'gi');
      let count = 0;

      // Normalize data
      const normalized = TextElementFactory.normalizeSource(data);
      if (!normalized) return;

      // Filter and create list items
      for (const item of normalized) {
        if (count >= acConfig.maxResults) break;

        if (!search || filter.test(item.text)) {
          const li = this.createListItem(instance, item.value, item.text, search);
          instance.list.push(li);
          instance.dropdown.appendChild(li);
          count++;
        }
      }

      instance.highlightItem(0);
      if (instance.list.length) {
        instance.show();
      } else {
        instance.hide();
      }
    };

    instance.show = () => {
      instance.dropdownPanel.show(input, instance.dropdown, {
        align: 'left',
        offsetY: 2,
        onClose: () => {}
      });
    };

    instance.hide = () => {
      if (instance.dropdownPanel.isOpen() && instance.dropdownPanel.currentTarget === input) {
        instance.dropdownPanel.hide();
      }
    };

    instance.highlightItem = (index) => {
      instance.listIndex = Math.max(0, Math.min(instance.list.length - 1, index));
      instance.list.forEach((item, i) => item.classList.toggle('active', i === instance.listIndex));
      instance.scrollToItem();
    };

    instance.scrollToItem = () => {
      const item = instance.list[instance.listIndex];
      if (item) {
        const dropdownRect = instance.dropdown.getBoundingClientRect();
        const itemRect = item.getBoundingClientRect();
        if (itemRect.top < dropdownRect.top) {
          instance.dropdown.scrollTop = item.offsetTop;
        } else if (itemRect.bottom > dropdownRect.bottom) {
          instance.dropdown.scrollTop = item.offsetTop - dropdownRect.height + itemRect.height;
        }
      }
    };

    instance.selectItem = (key, value) => {
      this.addTag(instance, key, value);
      input.value = '';
      // Show dropdown again with all options for quick multi-select
      instance.populate(acConfig.source);
    };
  }

  static createListItem(instance, key, value, search) {
    const li = document.createElement('li');
    li.dataset.key = key;

    const span = document.createElement('span');
    // translate {LNG_...} tokens if i18n manager is available
    let displayText = value;
    try {
      if (window.Now && Now.getManager) {
        const i18n = Now.getManager('i18n');
        if (i18n && typeof i18n.interpolate === 'function') {
          displayText = i18n.interpolate(value, {});
        }
      }
    } catch (e) {
      // ignore translation errors and fall back to raw value
      displayText = value;
    }

    if (!search) {
      span.textContent = displayText;
    } else {
      const splitRegex = new RegExp(this.escapeRegExp(search), 'gi');
      const parts = displayText.split(splitRegex);
      const matches = displayText.match(splitRegex) || [];

      parts.forEach((part, index) => {
        if (part) span.appendChild(document.createTextNode(part));
        if (index < matches.length) {
          const em = document.createElement('em');
          em.textContent = matches[index];
          span.appendChild(em);
        }
      });
    }

    li.appendChild(span);
    li.addEventListener('mousedown', () => instance.selectItem(key, value));
    li.addEventListener('mousemove', () => instance.highlightItem(instance.list.indexOf(li)));

    return li;
  }

  static readFromDatalist(element) {
    const listId = element.getAttribute('list');
    if (!listId) return null;

    const datalist = document.getElementById(listId);
    if (!datalist) return null;

    const options = Array.from(datalist.querySelectorAll('option'));
    const data = [];
    options.forEach(option => {
      const text = option.label || option.textContent;
      const key = option.value || text;
      data.push({value: key, text});
    });

    element.removeAttribute('list');
    datalist.remove();
    return data.length > 0 ? data : null;
  }

  static escapeRegExp(str) {
    return str.replace(/[-[\]{}()*+?.,\\^$|#\s]/g, '\\$&');
  }

  static setupEventHandlers(instance) {
    const {input, tagsContainer, config} = instance;
    const acConfig = config.autocomplete;

    // Prevent duplicate event registration
    if (instance._eventHandlersSetup) {
      return;
    }
    instance._eventHandlersSetup = true;

    // Click on container to focus input
    EventSystemManager.addHandler(tagsContainer, 'click', (e) => {
      // Don't steal clicks from remove buttons.
      if (e.target && e.target.closest && e.target.closest('button.tag-remove')) return;
      input.focus();
    });

    // Enter key to add tag
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();

        // Check if autocomplete is open
        const currentAcConfig = instance.config.autocomplete;
        if (currentAcConfig?.enabled && instance.dropdownPanel?.isOpen() && instance.dropdownPanel.currentTarget === input) {
          const item = instance.list[instance.listIndex];
          if (item) {
            const text = item.textContent || item.innerText;
            const key = item.dataset.key;
            instance.selectItem(key, text);
          }
        } else {
          // Add tag from input value
          const value = input.value.trim();
          if (value) {
            this.addTag(instance, value, value);
            input.value = '';
            // Show dropdown if autocomplete is enabled
            if (currentAcConfig?.enabled && currentAcConfig?.source) {
              instance.populate(currentAcConfig.source);
            }
          }
        }
      } else if (e.key === 'Backspace' && input.value === '') {
        // Remove last tag on backspace when input is empty
        e.preventDefault();
        if (instance.tags.length > 0 && !input.readOnly && !input.disabled) {
          const lastTag = instance.tags[instance.tags.length - 1];
          this.removeTag(instance, lastTag.key);
        }
      } else if (instance.config.autocomplete?.enabled && instance.dropdownPanel?.isOpen() && instance.dropdownPanel.currentTarget === input) {
        // Autocomplete keyboard navigation
        if (e.key === 'ArrowDown') {
          instance.highlightItem(instance.listIndex + 1);
          e.preventDefault();
        } else if (e.key === 'ArrowUp') {
          instance.highlightItem(instance.listIndex - 1);
          e.preventDefault();
        } else if (e.key === 'Escape') {
          instance.hide();
          e.preventDefault();
        }
      }
    });

    // Autocomplete support - Always bind events, check config dynamically
    const debounce = Utils.function.debounce((value) => {
      const currentAcConfig = instance.config.autocomplete;
      if (!currentAcConfig?.enabled || !currentAcConfig?.source) return;

      if (value.length < (currentAcConfig.minLength || 2)) {
        instance.hide();
        return;
      }
      instance.populate(currentAcConfig.source);
    }, acConfig?.delay || 300);

    input.addEventListener('input', (e) => {
      const value = e.target.value.trim();
      const currentAcConfig = instance.config.autocomplete;

      if (currentAcConfig?.enabled) {
        debounce(value);
      }
    });

    const handleFocus = () => {
      instance._isActive = true;
      const currentAcConfig = instance.config.autocomplete;

      // Show all options on focus if autocomplete is enabled
      if (currentAcConfig?.enabled && currentAcConfig?.source && typeof instance.populate === 'function') {
        // Force show-all on focus (ignore minLength / current input value)
        const prev = input.value;
        input.value = '';
        try {
          instance.populate(currentAcConfig.source);
        } finally {
          input.value = prev;
        }
      }
    };

    const handleBlur = () => {
      instance._isActive = false;
      setTimeout(() => {
        const panel = instance.dropdownPanel?.panel;
        if (!instance._isActive && (!panel || !panel.contains(document.activeElement))) {
          if (typeof instance.hide === 'function') {
            instance.hide();
          } else if (instance.dropdownPanel && typeof instance.dropdownPanel.hide === 'function') {
            // Fallback to calling dropdownPanel.hide directly if instance.hide wasn't set up
            instance.dropdownPanel.hide();
          }
        }
      }, 200);
    };

    // Use EventSystemManager for focus/blur (cleanup handled automatically)
    EventSystemManager.addHandler(input, 'focus', handleFocus, {capture: true});
    EventSystemManager.addHandler(input, 'blur', handleBlur, {capture: true});

    // Handle paste with separator
    EventSystemManager.addHandler(input, 'paste', (e) => {
      setTimeout(() => {
        const value = input.value;
        if (value.includes(config.separator)) {
          const values = value.split(config.separator).map(v => v.trim()).filter(v => v);
          values.forEach(v => this.addTag(instance, v, v));
          input.value = '';
        }
      }, 0);
    });

    return super.setupEventListeners?.(instance) || {};
  }

  static addTag(instance, key, value) {
    const {config, tags, tagElements, tagsContainer, inputLi, hiddenInputsContainer, originalName} = instance;

    // Check max tags
    if (config.maxTags && tags.length >= config.maxTags) {
      NotificationManager.warning(config.validationMessages.maxTags);
      return false;
    }

    // Check duplicates
    if (!config.duplicates && tags.some(tag => tag.key === key)) {
      NotificationManager.warning(config.validationMessages.duplicate);
      return false;
    }

    // Check readonly/disabled
    if (instance.input.readOnly || instance.input.disabled) {
      return false;
    }

    // If the key looks like a template/data expression (e.g. attribute_options[1])
    // log a clear warning and store it on the instance for investigation.
    if (typeof key === 'string' && key.indexOf('[') > -1 && key.indexOf(']') > -1) {
      console.warn('[Tags] addTag received expression-like key — likely template evaluation issue', {instanceId: instance.element?.id || null, key, value});
      instance._pendingExpressionTags = instance._pendingExpressionTags || [];
      instance._pendingExpressionTags.push({key, value});
    }

    // Create tag element
    const li = document.createElement('li');
    li.className = 'tag-item';
    li.dataset.key = key;

    const span = document.createElement('span');
    span.className = 'tag-text';
    // If value equals key (no readable text provided), try to resolve from options source
    let displayValue = value;
    try {
      if ((value === key || !value) && instance && instance.config && instance.config.autocomplete && instance.config.autocomplete.source) {
        // Normalize source to standard format -> Array of {value, text}
        const normalized = TextElementFactory.normalizeSource(instance.config.autocomplete.source);
        if (normalized && Array.isArray(normalized)) {
          let found = normalized.find(item => String(item.value) === String(key));
          // Fallbacks: try matching common fields or numeric indices
          if (!found) {
            // match by id or key field if present
            found = normalized.find(item => String(item.id) === String(key) || String(item.key) === String(key));
          }
          if (!found && !Number.isNaN(Number(key))) {
            const n = Number(key);
            // try zero-based index
            if (normalized[n]) found = normalized[n];
            // try one-based index
            if (!found && normalized[n - 1]) found = normalized[n - 1];
          }
          if (found) {
            displayValue = found.text;
          }
        }
      }
    } catch (e) {
      // ignore and fallback to raw value
      displayValue = value;
    }

    // translate display value (if contains {LNG_...}) using i18n manager
    try {
      if (window.Now && Now.getManager) {
        const i18n = Now.getManager('i18n');
        if (i18n && typeof i18n.interpolate === 'function') {
          displayValue = i18n.interpolate(displayValue, {});
        }
      }
    } catch (e) {
      // ignore translation errors and fall back to raw value
      displayValue = displayValue;
    }

    span.textContent = displayValue;
    li.appendChild(span);

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'tag-remove';
    button.innerHTML = '×';
    button.setAttribute('aria-label', `Remove ${value}`);
    li.appendChild(button);

    // Create hidden input in hiddenInputsContainer
    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = `${originalName}[]`;
    hidden.value = key;
    hidden.dataset.tagKey = key;
    hiddenInputsContainer.appendChild(hidden);

    // Add tag to container before input
    tagsContainer.insertBefore(li, inputLi);

    // Store references
    tags.push({key, value});
    tagElements.set(key, li);

    // Add remove handler
    EventSystemManager.addHandler(button, 'click', (e) => {
      e.stopPropagation();
      this.removeTag(instance, key);
    });

    // Dispatch change event
    instance.input.dispatchEvent(new Event('change', {bubbles: true}));

    return true;
  }

  static removeTag(instance, key) {
    const {tags, tagElements, hiddenInputsContainer, input} = instance;

    // Check readonly/disabled
    if (input.readOnly || input.disabled) {
      return false;
    }

    const index = tags.findIndex(tag => tag.key === key);
    if (index === -1) return false;

    // Remove tag element
    const li = tagElements.get(key);
    if (li && li.parentNode) {
      li.parentNode.removeChild(li);
    }

    // Remove hidden input
    const hidden = hiddenInputsContainer.querySelector(`[data-tag-key="${key}"]`);
    if (hidden) {
      hidden.remove();
    }

    tags.splice(index, 1);
    tagElements.delete(key);

    // Dispatch change event
    input.dispatchEvent(new Event('change', {bubbles: true}));

    return true;
  }

  static clearTags(instance) {
    const {tags} = instance;
    const keys = tags.map(tag => tag.key);
    keys.forEach(key => this.removeTag(instance, key));
  }

  static getTags(instance) {
    return instance.tags.map(tag => ({...tag}));
  }

  static setTags(instance, tags) {
    this.clearTags(instance);
    if (Array.isArray(tags)) {
      tags.forEach(tag => {
        if (typeof tag === 'string') {
          this.addTag(instance, tag, tag);
        } else if (tag.key && tag.value) {
          this.addTag(instance, tag.key, tag.value);
        }
      });
    }
  }

  static updateOptions(instance, options) {
    const {config} = instance;
    const normalizedOptions = Utils.options?.flattenGroups
      ? Utils.options.flattenGroups(Utils.options.normalizeSource(options))
      : options;

    // Update autocomplete source
    if (config.autocomplete) {
      config.autocomplete.source = normalizedOptions;
      config.autocomplete.enabled = true;

      // If autocomplete not setup yet, set it up
      if (!instance.dropdownPanel) {
        this.setupAutocomplete(instance);
      }
    }

    // Options often load after value binding — re-resolve label text for existing tags
    this.refreshTagLabels(instance);
  }

  /**
   * Update visible tag text from autocomplete source (value → text) when source arrives late
   * or tags were added with key===value before options existed.
   */
  static refreshTagLabels(instance) {
    if (!instance?.tags?.length || !instance.config?.autocomplete?.source) return;

    let normalized;
    try {
      normalized = TextElementFactory.normalizeSource(instance.config.autocomplete.source);
    } catch (e) {
      return;
    }
    if (!normalized || !Array.isArray(normalized)) return;

    instance.tags.forEach(tag => {
      let found = normalized.find(item => String(item.value) === String(tag.key));
      if (!found) {
        // try other common fields
        found = normalized.find(item => String(item.id) === String(tag.key) || String(item.key) === String(tag.key));
      }
      if (!found && !Number.isNaN(Number(tag.key))) {
        const n = Number(tag.key);
        if (normalized[n]) found = normalized[n];
        if (!found && normalized[n - 1]) found = normalized[n - 1];
      }
      if (!found) return;

      const oldValue = tag.value;
      tag.value = found.text;
      const li = instance.tagElements.get(tag.key);
      const span = li?.querySelector('.tag-text');
      if (span) span.textContent = found.text;
    });
  }

  /**
   * Populate tags input from provided options object
   * @param {HTMLElement} element - Input element
   * @param {Object} optionsData - Options data object
   * @param {String} optionsKey - Key to extract from optionsData
   */
  static populateFromOptions(element, optionsData, optionsKey) {
    if (!element || !optionsData || !optionsKey) return;

    const options = Utils.options?.flattenGroups
      ? Utils.options.flattenGroups(Utils.options.normalizeSource(optionsData[optionsKey]))
      : optionsData[optionsKey];

    if (!options || !Array.isArray(options)) return;

    // Get instance
    let instance = ElementManager?.getInstanceByElement(element);

    // If not enhanced yet, try to enhance it
    if (!instance && window.ElementManager) {
      instance = ElementManager.enhance(element);
    }

    if (!instance) {
      console.warn('[TagsElementFactory] Element not enhanced yet', {elementId: element.id || null, optionsKey});
      return;
    }

    this.updateOptions(instance, options);
  }

  /**
   * Populate all tags inputs with data-options-key attribute
   * @param {HTMLElement} container - Container element
   * @param {Object} optionsData - Options data object
   */
  static populateFromOptionsInContainer(container, optionsData) {
    if (!container || !optionsData) return;

    const inputsWithOptionsKey = container.querySelectorAll('input[data-options-key][data-element="tags"]');

    inputsWithOptionsKey.forEach(input => {
      const optionsKey = input.dataset.optionsKey;
      this.populateFromOptions(input, optionsData, optionsKey);
    });
  }

  static cleanup(instance) {
    if (!instance) return;

    // Hide dropdown if open
    if (instance.dropdownPanel?.isOpen() && instance.dropdownPanel.currentTarget === instance.input) {
      instance.dropdownPanel.hide();
    }

    // Remove wrapper and restore original input
    if (instance.wrapper && instance.wrapper.parentNode) {
      instance.wrapper.parentNode.removeChild(instance.wrapper);
    }

    if (instance.originalInput) {
      instance.originalInput.style.display = '';
      instance.originalInput.removeAttribute('aria-hidden');
    }

    // Remove locale change listener to prevent leaks/double-handling
    try {
      if (instance._localeChangeHandler && EventManager.off) {
        EventManager.off('locale:changed', instance._localeChangeHandler);
      }
    } catch (e) {
      // ignore
    }

    super.cleanup?.(instance);
  }
}

// Register with ElementManager
ElementManager.registerElement('tags', TagsElementFactory);

// Expose globally
window.TagsElementFactory = TagsElementFactory;
