/**
 * TextElementFactory - class to manage text inputs with autocomplete and hidden input support
 */
class TextElementFactory extends ElementFactory {
  static config = {
    type: 'text',
    autocomplete: {
      enabled: false,
      source: null,
      minLength: 2,
      maxResults: 10,
      delay: 300,
      level: null,
      dependent: null,
      callback: null
    },
    validationMessages: {
      email: 'Please enter a valid email address',
      url: 'Please enter a valid URL',
      number: 'Please enter a valid number',
      integer: 'Please enter a whole number',
      alpha: 'Only letters are allowed',
      alphanumeric: 'Only letters and numbers are allowed',
      usernameOrEmail: 'Please enter a valid username or email'
    },
    formatter: null
  };

  static propertyHandlers = {
    value: {
      get(element) {
        return element.value;
      },
      set(instance, newValue) {
        const {element} = instance;

        // Hierarchy inputs: delegate to HierarchicalTextFactory which resolves code → display name
        if (element.hasAttribute('data-hierarchy') && window.HierarchicalTextFactory) {
          HierarchicalTextFactory.setValueByCode(instance, newValue);
          return;
        }

        const acConfig = instance.config?.autocomplete;

        const optionValue = TextElementFactory.normalizeOptionValue(newValue);
        if (optionValue) {
          element.value = optionValue.text;
          if (instance.hiddenInput) {
            instance.hiddenInput.value = optionValue.value;
          }
          instance.selectedValue = optionValue.value !== '' ? optionValue.value : null;
          return;
        }

        // If autocomplete is configured with a source, resolve ID → display text
        if (acConfig?.source && newValue != null && newValue !== '') {
          const normalized = TextElementFactory.normalizeSource(acConfig.source);
          if (normalized && Array.isArray(normalized)) {
            const found = normalized.find(item =>
              String(item.value) === String(newValue) || String(item.text) === String(newValue)
            );
            if (found) {
              element.value = found.text;
              if (instance.hiddenInput) {
                instance.hiddenInput.value = found.value;
              }
              instance.selectedValue = found.value;
              return;
            }
          }
        }

        // Fallback: set raw value (source not resolvable, e.g. hierarchy URL or unmatched item)
        element.value = newValue ?? '';
        if (instance.hiddenInput) {
          instance.hiddenInput.value = newValue ?? '';
        }
        instance.selectedValue = (newValue != null && newValue !== '') ? newValue : null;
      }
    }
  };

  static normalizeOptionValue(value) {
    if (!value || Array.isArray(value) || typeof value !== 'object') {
      return null;
    }

    const normalizedValue = value.value ?? value.id ?? value.key;
    const normalizedText = value.text ?? value.label ?? value.name;

    if (normalizedValue === undefined && normalizedText === undefined) {
      return null;
    }

    const resolvedValue = normalizedValue != null ? String(normalizedValue) : '';
    const resolvedText = normalizedText != null ? String(normalizedText) : resolvedValue;

    return {
      value: resolvedValue,
      text: resolvedText
    };
  }

  static validators = {
    email: value => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value),
    url: value => /^(https?|ftp):\/\/[^\s\/$.?#].[^\s]*$|^www\.[^\s\/$.?#].[^\s]*$|^[^\s\/$.?#].[^\s]*\.[a-z]{2,}(\/[^\s]*)?$/.test(value),
    number: value => /^-?\d*\.?\d+$/.test(value),
    integer: value => /^-?\d+$/.test(value),
    alpha: value => /^[a-zA-Z]+$/.test(value),
    alphanumeric: value => /^[a-zA-Z0-9]+$/.test(value),
    usernameOrEmail: value => {
      const usernamePattern = /^[a-zA-Z0-9_]+$/;
      const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      return usernamePattern.test(value) || emailPattern.test(value);
    }
  };

  static extractCustomConfig(element, def, dataset) {
    return {
      autocomplete: {
        enabled: dataset.autocomplete !== undefined ? dataset.autocomplete === 'true' : def.autocomplete?.enabled,
        source: dataset.source || def.autocomplete?.source,
        minLength: this.parseNumeric('minLength', element, def.autocomplete, dataset) || def.autocomplete?.minLength,
        maxResults: this.parseNumeric('maxResults', element, def.autocomplete, dataset) || def.autocomplete?.maxResults,
        delay: this.parseNumeric('delay', element, def.autocomplete, dataset) || def.autocomplete?.delay,
        level: dataset.hierarchy || dataset.level || def.autocomplete?.level,
        dependent: dataset.dependent || def.autocomplete?.dependent,
        depends: dataset.depends || def.autocomplete?.depends || null, // comma-separated field names for dependent filtering
        callback: dataset.callback ? window[dataset.callback] : def.autocomplete?.callback
      },
      formatter: dataset.formatter ? window[dataset.formatter] : def.formatter,
      // Hierarchical search configuration
      searchApi: dataset.searchApi || null,
      searchField: dataset.searchField || null
    };
  }

  static setupElement(instance) {
    const {config, element} = instance;
    const acConfig = config.autocomplete;

    // Check for pending options (stored before element was enhanced)
    if (element.dataset.pendingOptions) {
      try {
        const pendingOptions = JSON.parse(element.dataset.pendingOptions);
        acConfig.source = pendingOptions;
        delete element.dataset.pendingOptions;
      } catch (e) {
        console.error('Failed to parse pending options:', e);
      }
    }

    // Read information from <Datalist> (if any)
    const dataFromDatalist = this.readFromDatalist(element);
    if (dataFromDatalist) {
      acConfig.source = dataFromDatalist;
    }

    // Auto-enable autocomplete if source is available
    if (acConfig.source) acConfig.enabled = true;

    // Auto-enable autocomplete for hierarchical inputs
    if (element.hasAttribute('data-hierarchy')) acConfig.enabled = true;

    // Auto-enable autocomplete for inputs with options-key (options loaded later)
    if (element.hasAttribute('data-options-key')) acConfig.enabled = true;

    // Save the original name and create a hidden input only when necessary
    const originalName = element.getAttribute('name') || '';
    const shouldCreateHidden = !!originalName && (acConfig.enabled || !!acConfig.source || !!dataFromDatalist || element.hasAttribute('data-hierarchy') || element.hasAttribute('data-options-key'));
    if (shouldCreateHidden) {
      instance.hiddenInput = document.createElement('input');
      instance.hiddenInput.type = 'hidden';
      instance.hiddenInput.setAttribute('name', originalName);
      element.parentNode.insertBefore(instance.hiddenInput, element.nextSibling);
      element.setAttribute('name', `${originalName}_text`);
      // If this input is inside a form that is managed by FormManager, register
      // the hidden input so FormManager will include it in form data collection.
      try {
        const formEl = element.form || element.closest && element.closest('form');
        if (formEl && window.FormManager && typeof FormManager.getInstanceByElement === 'function') {
          const formInstance = FormManager.getInstanceByElement(formEl);
          if (formInstance) {
            // Use the original name as the key so getFormData will append the hidden value
            try {
              formInstance.elements.set(originalName, instance.hiddenInput);
              formInstance.state.data[originalName] = instance.hiddenInput.value || '';
            } catch (e) {
              // best-effort: ignore if we cannot set
            }
          }
        }
      } catch (e) {}
    }

    // Initialize from existing value (if any)
    const initialValue = element.value;
    if (initialValue && acConfig.source) {
      this.setInitialValue(instance, initialValue);
    }

    // Default stubs for autocomplete methods (will be overwritten by setupAutocomplete)
    instance.hide = () => {};
    instance.highlightItem = () => {};
    instance.populate = () => {};
    instance.selectItem = () => {};
    instance.escapeRegExp = (str) => str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

    // Initialize autocomplete if configured
    if (acConfig.enabled) {
      this.setupAutocomplete(instance);
    }

    // Validation
    instance.validateSpecific = function(value) {
      const validators = {
        ...TextElementFactory.validators,
        ...(window.validators || {}),
        ...(this.validators || {})
      };
      for (const rule of this.validationRules || []) {
        const ruleName = typeof rule === 'string' ? rule : rule.name;
        if (['minLength', 'maxLength', 'pattern', 'required'].includes(ruleName)) {
          console.warn(`"${ruleName}" should be set as a DOM property`);
          continue;
        }
        if (validators[ruleName] && !validators[ruleName](value)) {
          return config.validationMessages?.[ruleName] || 'Invalid value';
        }
      }
      return null;
    };

    // Formatter
    if (config.formatter) {
      EventSystemManager.addHandler(element, 'change', () => {
        element.value = config.formatter(element.value);
      });
    }

    // Register with HierarchicalTextFactory when applicable
    if (element.hasAttribute('data-hierarchy')) {
      HierarchicalTextFactory.register(instance);
    }

    this.setupEventListeners(instance);

    // Provide a destroy hook to clean up DOM nodes created by this factory
    const originalDestroy = instance.destroy;
    instance.destroy = function() {
      try {
        // Hide dropdown panel if open
        if (this.dropdownPanel?.isOpen() && this.dropdownPanel.currentTarget === element) {
          this.dropdownPanel.hide();
        }
        // Clean up dropdown element (no need to remove from DOM, it's in DropdownPanel)
        if (this.dropdown) {
          this.dropdown = null;
        }
        if (this.hiddenInput && this.hiddenInput.parentNode) {
          const hiddenName = this.hiddenInput.getAttribute('name');
          if (hiddenName && element.getAttribute('name') === `${hiddenName}_text`) {
            element.setAttribute('name', hiddenName);
          }
          // Try to unregister hidden input from FormManager if present
          try {
            const formEl = element.form || element.closest && element.closest('form');
            if (formEl && window.FormManager && typeof FormManager.getInstanceByElement === 'function') {
              const formInstance = FormManager.getInstanceByElement(formEl);
              if (formInstance && formInstance.elements && formInstance.elements.has(hiddenName)) {
                try {formInstance.elements.delete(hiddenName);} catch (e) {}
              }
            }
          } catch (e) {}

          this.hiddenInput.parentNode.removeChild(this.hiddenInput);
          this.hiddenInput = null;
        }
        // If HierarchicalTextFactory registered this instance, try to unregister
        try {if (window.HierarchicalTextFactory && typeof HierarchicalTextFactory.unregister === 'function') HierarchicalTextFactory.unregister(this);} catch (e) {}
      } catch (err) {
        console.warn('Error during TextElementFactory.destroy', err);
      }

      if (typeof originalDestroy === 'function') {
        try {originalDestroy.call(this);} catch (e) {console.warn('Original destroy threw', e);}
      }

      return this;
    };

    return instance;
  }

  static readFromDatalist(element) {
    const listId = element.getAttribute('list');
    if (!listId) return null;

    const datalist = document.getElementById(listId);
    if (!datalist) return null;

    const options = Array.from(datalist.querySelectorAll('option'));
    // Preserve DOM order by returning an array of entries: [{value: key, text: value}, ...]
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

  static setInitialValue(instance, initialValue) {
    const {config, element, hiddenInput} = instance;
    const acConfig = config.autocomplete;

    if (acConfig.source) {
      // Normalize source to standard format
      const normalized = this.normalizeSource(acConfig.source);
      if (!normalized) return;

      // Find matching entry by value or text
      for (const item of normalized) {
        if (item.value === initialValue || item.text === initialValue) {
          element.value = item.text;
          hiddenInput.value = item.value;
          instance.selectedValue = item.value;
          return;
        }
      }
    }

    // If not found in list, hidden will be empty (not selected)
    hiddenInput.value = '';
  }

  static setupAutocomplete(instance) {
    const {element, config, hiddenInput} = instance;
    const acConfig = config.autocomplete;

    instance.selectedValue = null;

    // Use DropdownPanel instead of creating dropdown element
    instance.dropdownPanel = DropdownPanel.getInstance();
    instance.dropdown = document.createElement('ul');
    instance.dropdown.className = 'autocomplete-list';

    instance.list = [];
    instance.listIndex = 0;

    instance.populate = (data) => {
      if (document.activeElement !== element && !instance._isActive) return;

      instance.dropdown.innerHTML = '';
      instance.list = [];
      const search = element.value.trim();
      const filter = new RegExp(`(${instance.escapeRegExp(search)})`, 'i');
      let count = 0;

      // Normalize data to standard format
      const normalized = TextElementFactory.normalizeSource(data);
      if (!normalized) return;

      // Filter and create list items
      for (const item of normalized) {
        if (count >= acConfig.maxResults) break;

        // Apply search filter
        if (!search || filter.test(item.text)) {
          const li = instance.createListItem(item.value, item.text, search);
          instance.list.push(li);
          instance.dropdown.appendChild(li);
          count++;
        }
      }

      instance.highlightItem(0);
      if (instance.list.length) instance.show();
      else instance.hide();
    };

    // Show dropdown using DropdownPanel
    instance.show = () => {
      instance.dropdownPanel.show(element, instance.dropdown, {
        align: 'left',
        offsetY: 2,
        onClose: () => {
          // Cleanup when panel closes
        }
      });
    };

    // Hide dropdown
    instance.hide = () => {
      if (instance.dropdownPanel.isOpen() &&
        instance.dropdownPanel.currentTarget === element) {
        instance.dropdownPanel.hide();
      }
    };

    instance.escapeRegExp = (str) => str.replace(/[-[\]{}()*+?.,\\^$|#\s]/g, '\\$&');

    // Create list item with safe DOM construction (no innerHTML XSS risk)
    instance.createListItem = (key, value, search) => {
      const li = document.createElement('li');
      li.dataset.key = key;

      if (typeof acConfig.callback === 'function') {
        // If callback provided, accept a few return types safely:
        // - DOM Node -> append directly
        // - string -> if it contains HTML tags, set innerHTML, otherwise set textContent
        // - array -> append each item (string or Node)
        try {
          const result = acConfig.callback({key, value, search, level: acConfig.level});

          const appendStringSafely = (str) => {
            const wrapper = document.createElement('div');
            // If the string appears to contain HTML tags, allow innerHTML; otherwise use textContent
            if (/<[a-z][\s\S]*>/i.test(str)) wrapper.innerHTML = str;
            else wrapper.textContent = str;
            li.appendChild(wrapper);
          };

          if (result instanceof Node) {
            li.appendChild(result);
          } else if (Array.isArray(result)) {
            result.forEach(item => {
              if (item instanceof Node) li.appendChild(item);
              else appendStringSafely(String(item));
            });
          } else if (typeof result === 'string') {
            appendStringSafely(result);
          } else if (result != null) {
            // Fallback: stringify other types
            appendStringSafely(String(result));
          }
        } catch (cbErr) {
          console.warn('Autocomplete callback error:', cbErr);
          // Fallback to default rendering when callback fails
          const spanFallback = document.createElement('span');
          spanFallback.textContent = value;
          li.appendChild(spanFallback);
        }
      } else {
        // Safe rendering: create text nodes and <em> for highlights
        const span = document.createElement('span');

        if (!search) {
          // No search term: simple text node
          span.textContent = value;
        } else {
          // Highlight matches safely using DOM nodes
          // Use a non-capturing regex for splitting (remove capturing group)
          const splitRegex = new RegExp(instance.escapeRegExp(search), 'gi');
          const parts = value.split(splitRegex);
          const matches = value.match(splitRegex) || [];

          parts.forEach((part, index) => {
            if (part) {
              span.appendChild(document.createTextNode(part));
            }
            if (index < matches.length) {
              const em = document.createElement('em');
              em.textContent = matches[index];
              span.appendChild(em);
            }
          });
        }

        li.appendChild(span);
      }

      li.addEventListener('mousedown', () => instance.selectItem(key, value));
      li.addEventListener('mousemove', () => instance.highlightItem(instance.list.indexOf(li)));
      return li;
    };

    // Highlight item
    instance.highlightItem = (index) => {
      instance.listIndex = Math.max(0, Math.min(instance.list.length - 1, index));
      instance.list.forEach((item, i) => item.classList.toggle('active', i === instance.listIndex));
      instance.scrollToItem();
    };

    // Scroll to item
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

    // Select item
    instance.selectItem = (key, value) => {
      // Check if this is a hierarchical selection
      const selectedLi = instance.list.find(li => li.dataset.key === key);
      if (selectedLi && selectedLi.dataset.hierarchicalData) {
        // Use hierarchical selection
        TextElementFactory.selectHierarchicalItem(instance, key, value);
        return;
      }

      // Check if this is a reverse search selection from HierarchicalTextFactory
      if (instance.manager && typeof instance.manager.isReverseSearchSelection === 'function' && instance.manager.isReverseSearchSelection(instance)) {
        instance.manager.handleReverseSelection(instance, key, value);
        instance.hide();
        instance._lastAutocompleteSelectionChange = {
          timestamp: Date.now(),
          submittedValue: instance.hiddenInput ? instance.hiddenInput.value : key,
          displayValue: element.value
        };
        // Dispatch change on visible input for UI listeners
        element.dispatchEvent(new Event('change', {bubbles: true}));
        try {
          instance.hiddenInput && instance.hiddenInput.dispatchEvent(new Event('change', {bubbles: true}));
        } catch (err) {}
        // Call onChange callback if provided
        try {
          if (config && typeof config.onChange === 'function') {
            config.onChange(element, value);
          }
        } catch (err) {
          console.warn('Error in onChange handler (selectItem):', err);
        }
        return;
      }

      // Normal selection
      element.value = value;
      hiddenInput.value = key;
      instance.selectedValue = key;
      instance.hide();
      instance._lastAutocompleteSelectionChange = {
        timestamp: Date.now(),
        submittedValue: hiddenInput.value,
        displayValue: element.value
      };
      // Dispatch change on visible input for UI listeners
      element.dispatchEvent(new Event('change', {bubbles: true}));
      // Also dispatch change on hidden input so FormManager detects the actual
      // submitted field name (the hidden input holds the real value/key)
      try {
        instance.hiddenInput && instance.hiddenInput.dispatchEvent(new Event('change', {bubbles: true}));
      } catch (err) {}
      if (instance.manager) instance.manager.onSelectionChange(instance);
      // Call onChange callback if provided
      try {
        if (config && typeof config.onChange === 'function') {
          config.onChange(element, value);
        }
      } catch (err) {
        console.warn('Error in onChange handler (selectItem):', err);
      }
    };
  }

  /**
   * Get dependent field values as URL parameters
   * @param {Object} instance - Element instance
   * @returns {string} URL parameters string (e.g., '&warehouse_id=1&category_id=2')
   */
  static getDependsParams(instance) {
    const depends = instance.config.autocomplete.depends;
    if (!depends) return '';

    const form = instance.element.closest('form') || document.body;
    const fields = depends.split(',').map(s => s.trim());
    const params = [];

    for (const fieldName of fields) {
      // Try to find by name attribute first, then by ID
      let field = form.querySelector(`[name="${fieldName}"]`);
      if (!field) field = form.querySelector(`#${fieldName}`);

      if (field && field.value) {
        params.push(`${fieldName}=${encodeURIComponent(field.value)}`);
      }
    }

    return params.length ? '&' + params.join('&') : '';
  }

  static loadFromAjax(instance, url, query = '') {
    // Use HttpClient only - no fallback
    if (!window.http || typeof window.http.get !== 'function') {
      console.error('[TextElementFactory] HttpClient (window.http) is required but not available');
      alert('System error: HttpClient not loaded. Please refresh the page.');
      return;
    }

    // Get dependent field values
    const dependsParams = this.getDependsParams(instance);

    // Build URL with query parameter and depends params
    let requestUrl = url;
    if (query) {
      const separator = url.includes('?') ? '&' : '?';
      requestUrl = `${url}${separator}q=${encodeURIComponent(query)}${dependsParams}`;
    } else if (dependsParams) {
      const separator = url.includes('?') ? '&' : '?';
      requestUrl = `${url}${separator}${dependsParams.substring(1)}`;
    }

    window.http.get(requestUrl, {throwOnError: false})
      .then(resp => {
        if (resp && resp.success) {
          instance.populate(resp.data);
        } else {
          console.error('[TextElementFactory] Failed to load data:', requestUrl, resp);
        }
      })
      .catch(err => {
        console.error('[TextElementFactory] Ajax error:', err);
      });
  }

  /**
   * Hierarchical search using API endpoint
   * Searches across all related hierarchical data and displays in format: "province => district => subdistrict"
   */
  static searchHierarchical(instance, query) {
    const {config, element} = instance;

    if (!config.searchApi || !config.searchField) {
      console.warn('[TextElementFactory] searchApi or searchField not configured');
      return;
    }

    if (!window.http || typeof window.http.post !== 'function') {
      console.error('[TextElementFactory] HttpClient (window.http) is required but not available');
      return;
    }

    // Call search API with query and field
    const formData = new FormData();
    formData.append('query', query);
    formData.append('field', config.searchField);

    window.http.post(config.searchApi, formData, {throwOnError: false})
      .then(resp => {
        if (resp && resp.success && resp.data) {
          // Handle nested API response structure: {success, message, code, data: {results: [...]}}
          const results = resp.data.data?.results || resp.data.results;

          if (results && results.length > 0) {
            // Store hierarchical data for later use
            instance._hierarchicalData = results;

            // Populate dropdown with formatted results
            TextElementFactory.populateHierarchical(instance, results);
          } else {
            instance.hide();
          }
        } else {
          instance.hide();
        }
      })
      .catch(err => {
        console.error('[TextElementFactory] Hierarchical search error:', err);
        instance.hide();
      });
  }

  /**
   * Populate dropdown with hierarchical search results
   */
  static populateHierarchical(instance, data) {
    const {element, config} = instance;
    const acConfig = config.autocomplete;

    if (document.activeElement !== element && !instance._isActive) return;

    instance.dropdown.innerHTML = '';
    instance.list = [];

    // Create list items without filtering (already filtered by API)
    data.forEach((item, index) => {
      if (index >= acConfig.maxResults) return;

      // Use province value as the key (first field in hierarchy)
      const key = item.data?.province?.value || index;
      const li = instance.createListItem(key, item.text, ''); // No search highlight (already formatted)
      li.dataset.hierarchicalData = JSON.stringify(item); // Store full item data
      instance.list.push(li);
      instance.dropdown.appendChild(li);
    });

    instance.highlightItem(0);
    if (instance.list.length) instance.show();
    else instance.hide();
  }

  /**
   * Override selectItem for hierarchical selection
   * Populates all related fields (province, district, subdistrict, zipcode) at once
   */
  static selectHierarchicalItem(instance, value, text) {
    const {element, config, hiddenInput} = instance;

    // Find the hierarchical data from the selected item
    const selectedItem = instance.list.find(li => li.dataset.key === value);
    if (!selectedItem || !selectedItem.dataset.hierarchicalData) {
      console.warn('[TextElementFactory] No hierarchical data found for selection');
      return;
    }

    try {
      const item = JSON.parse(selectedItem.dataset.hierarchicalData);
      const data = item.data; // Extract nested data object

      // Extract field name from element name (remove "_text" suffix if present)
      const fieldName = element.getAttribute('name').replace('_text', '');

      // Set current field value and text from {value, text} structure
      if (data[fieldName]) {
        element.value = data[fieldName].text || text;
        hiddenInput.value = data[fieldName].value || value;
        instance.selectedValue = data[fieldName].value || value;
      }

      // Find form and populate all related fields
      const form = element.closest('form');
      if (form) {
        // Find all other hierarchical search inputs in the same form
        const relatedInputs = form.querySelectorAll('input[data-search-api][data-autocomplete="true"]');

        relatedInputs.forEach(input => {
          if (input === element) return; // Skip current field

          const relatedFieldName = input.getAttribute('name').replace('_text', '');
          const relatedData = data[relatedFieldName];

          if (relatedData && relatedData.value && relatedData.text) {
            // Set visible text
            input.value = relatedData.text;

            // Set hidden value if exists
            const relatedHiddenName = input.getAttribute('name').includes('_text')
              ? relatedFieldName
              : relatedFieldName;
            const relatedHidden = form.querySelector(`input[type="hidden"][name="${relatedHiddenName}"]`);
            if (relatedHidden) {
              relatedHidden.value = relatedData.value;
            }
          }
        });

        // Populate zipcode (readonly field)
        if (data.zipcode) {
          const zipcodeInput = form.querySelector('input[name="zipcode"]');
          if (zipcodeInput) {
            zipcodeInput.value = data.zipcode.value || data.zipcode.text;
          }
        }
      }

      instance.hide();

      instance._lastAutocompleteSelectionChange = {
        timestamp: Date.now(),
        submittedValue: hiddenInput ? hiddenInput.value : element.value,
        displayValue: element.value
      };

      // Dispatch change event
      element.dispatchEvent(new Event('change', {bubbles: true}));
      hiddenInput && hiddenInput.dispatchEvent(new Event('change', {bubbles: true}));

    } catch (err) {
      console.error('[TextElementFactory] Error parsing hierarchical data:', err);
    }
  }

  static setupEventListeners(instance) {
    const {element, config, hiddenInput} = instance;
    const acConfig = config.autocomplete;

    super.setupEventListeners(instance);

    // Prevent duplicate event registration
    if (instance._autocompleteListenersSetup) return;
    instance._autocompleteListenersSetup = true;

    const debounce = Utils.function.debounce((value) => {
      if (!acConfig.enabled) return;
      if (value.length < acConfig.minLength) {
        instance.hide();
        return;
      }

      // Check if this is a hierarchical search field
      if (config.searchApi && config.searchField) {
        // Use hierarchical search API
        TextElementFactory.searchHierarchical(instance, value);
      } else if (instance.manager) {
        // Use HierarchicalTextFactory manager
        instance.manager.search(instance, value);
      } else if (typeof acConfig.source === 'string' && (acConfig.source.startsWith('http') || acConfig.source.startsWith('/') || acConfig.source.startsWith('api/'))) {
        // URL source - load from API with query parameter
        TextElementFactory.loadFromAjax(instance, acConfig.source, value);
      } else {
        // Normal populate from static source (array/object/global variable)
        instance.populate(acConfig.source);
      }
    }, acConfig.delay);

    EventSystemManager.addHandler(element, 'input', (e) => {
      const value = e.target.value.trim();

      // When user types (not selected from list), set hidden input to text value
      // This allows fallback to text if no selection is made
      if (hiddenInput) {
        hiddenInput.value = value;
      }
      instance.selectedValue = null;
      delete instance._lastAutocompleteSelectionChange;

      debounce(value);

      // If autocomplete is not enabled, call onChange to notify listeners
      try {
        if (!acConfig.enabled && config && typeof config.onChange === 'function') {
          config.onChange(element, value);
        }
      } catch (err) {
        console.warn('Error in onChange handler (input):', err);
      }
    });

    // Focus event - show dropdown when input gets focus (if autocomplete enabled)
    EventSystemManager.addHandler(element, 'focus', () => {
      // Mark this instance as active
      instance._isActive = true;

      // Only populate if autocomplete is enabled
      if (!acConfig.enabled) return;

      // For hierarchical inputs, use HierarchicalTextFactory manager
      if (instance.manager && typeof instance.manager.search === 'function') {
        instance.manager.search(instance, element.value.trim());
        return;
      }

      if (acConfig.source) {
        if (typeof acConfig.source === 'string' && window[acConfig.source]) {
          // Global variable reference
          instance.populate(window[acConfig.source]);
        } else if (typeof acConfig.source === 'string' && (acConfig.source.startsWith('http') || acConfig.source.startsWith('/') || acConfig.source.startsWith('api/'))) {
          // URL (absolute, root-relative, or relative path starting with 'api/')
          // Only load on focus if there's already a value to search for
          const currentValue = element.value.trim();
          if (currentValue.length >= acConfig.minLength) {
            this.loadFromAjax(instance, acConfig.source, currentValue);
          }
          // Otherwise wait for user to type (handled by input event)
        } else {
          // Populate from existing array/object
          instance.populate(acConfig.source);
        }
      }
    });

    // Keydown event - navigate dropdown list
    EventSystemManager.addHandler(element, 'keydown', (e) => {
      if (!instance.dropdownPanel?.isOpen() ||
        instance.dropdownPanel.currentTarget !== element) return;

      switch (e.key) {
        case 'ArrowDown':
          instance.highlightItem(instance.listIndex + 1);
          e.preventDefault();
          e.stopPropagation();
          break;
        case 'ArrowUp':
          instance.highlightItem(instance.listIndex - 1);
          e.preventDefault();
          e.stopPropagation();
          break;
        case 'Enter':
          const item = instance.list[instance.listIndex];
          if (item) {
            // Get text from item (remove HTML tags)
            const text = item.textContent || item.innerText;
            const key = item.dataset.key;
            instance.selectItem(key, text);
          }
          e.preventDefault();
          e.stopPropagation();
          break;
        case 'Escape':
          if (typeof instance.hide === 'function') {
            instance.hide();
          }
          e.preventDefault();
          e.stopPropagation();
          break;
      }
    });

    // Blur event - hide dropdown with delay to allow click events
    EventSystemManager.addHandler(element, 'blur', (e) => {
      // Mark as not active
      instance._isActive = false;

      setTimeout(() => {
        // Only hide if not clicking inside DropdownPanel and still not active
        const panel = instance.dropdownPanel?.panel;
        if (!instance._isActive && (!panel || !panel.contains(document.activeElement))) {
          if (typeof instance.hide === 'function') {
            instance.hide();
          }
        }
      }, 200);
    });
  }

  static updateOptions(element, options) {
    const normalizedOptions = Utils.options?.flattenGroups
      ? Utils.options.flattenGroups(Utils.options.normalizeSource(options))
      : options;

    // Get element instance from ElementManager
    const instance = ElementManager?.getInstanceByElement(element);

    if (!instance || !instance.config) {
      // Element not yet enhanced - store options in dataset for later use
      element.dataset.pendingOptions = JSON.stringify(normalizedOptions);
      return;
    }

    const acConfig = instance.config.autocomplete;

    // Set options as autocomplete source directly (no datalist needed!)
    acConfig.source = normalizedOptions;

    // Auto-enable autocomplete if not already enabled
    if (!acConfig.enabled) {
      acConfig.enabled = true;
      // Need to setup autocomplete UI
      this.setupAutocomplete(instance);
    }
  }

  static populateFromOptions(element, optionsData, optionsKey) {
    if (!element || !optionsData || !optionsKey) return;

    let options = Utils.options?.flattenGroups
      ? Utils.options.flattenGroups(Utils.options.normalizeSource(optionsData[optionsKey]))
      : optionsData[optionsKey];
    if (!options) return;

    if (!Array.isArray(options)) {
      console.warn('[TextElementFactory] Options must be in Array, Map, or Object option format.');
      return;
    }

    // Save current value before updating options
    const currentValue = element.value;

    // Update element's options using existing updateOptions method
    this.updateOptions(element, options);

    // Restore value after populating options (important for modal forms)
    if (currentValue) {
      // Find the option that matches the current value (could be ID/key or display text)
      // Check both value and text fields to handle cases where setFormData already converted ID to text
      // Use loose equality (==) to handle type coercion (e.g., 10 == "10")
      const matchedOption = options.find(opt =>
        opt.value == currentValue || opt.text == currentValue ||
        String(opt.value) === String(currentValue) || String(opt.text) === String(currentValue)
      );

      if (matchedOption) {
        // Set the display text in the visible input
        element.value = matchedOption.text;

        // Also update hidden input with the ID value (not currentValue!)
        const instance = ElementManager?.getInstanceByElement(element);
        if (instance?.hiddenInput) {
          instance.hiddenInput.value = matchedOption.value;
          instance.selectedValue = matchedOption.value;
        }
      } else {
        // If no match found, keep the original value
        element.value = currentValue;
      }
    }
  }

  static populateFromOptionsInContainer(container, optionsData) {
    if (!container || !optionsData) return;

    const inputsWithOptionsKey = container.querySelectorAll('input[data-options-key]');

    inputsWithOptionsKey.forEach(input => {
      const optionsKey = input.dataset.optionsKey;
      this.populateFromOptions(input, optionsData, optionsKey);
    });
  }

  /**
   * After form data is loaded (values set from server), convert any inputs
   * that have an ID value into display text by looking up the source.
   * This is needed because options may have been set before form data was
   * applied, or vice-versa.
   * @param {HTMLElement} container - form or container element
   */
  static applyInitialValuesInContainer(container) {
    if (!container) return;
    const inputsWithOptionsKey = container.querySelectorAll('input[data-options-key], select[data-options-key]');
    inputsWithOptionsKey.forEach(input => {
      const instance = ElementManager?.getInstanceByElement(input);
      if (instance && instance.config && instance.config.autocomplete && instance.config.autocomplete.source) {
        const val = input.value;
        if (val) {
          this.setInitialValue(instance, val);
        }
      }
    });
  }

  /**
   * Normalize source data into standard format: Array<{value: string, text: string}>
   * Accepts: Array (objects or primitives), Map, plain Object, or null
   * Returns: Array<{value: string, text: string}> or null
   */
  static normalizeSource(source) {
    return Utils.options?.normalizeSource ? Utils.options.normalizeSource(source) : null;
  }
}

/**
 * TextElementFactory Export
 */
window.TextElementFactory = TextElementFactory;

/**
 * HierarchicalTextFactory
 * Manages hierarchical/cascading inputs grouped by form.
 *
 * Supports two JSON formats:
 *
 * Old (name-as-key):
 *   { "กาญจนบุรี": { "เมืองกาญจนบุรี": { "710107": "ลาดหญ้า" } } }
 *   → submitted value = the display name itself
 *
 * New (code-as-key):  ← recommended
 *   { "71": { "name": "กาญจนบุรี", "7101": { "name": "เมืองกาญจนบุรี", "710107": "ลาดหญ้า" } } }
 *   → visible input = display name, hidden input (submitted) = code
 *
 * Format is auto-detected on load. Both formats are backward-compatible.
 */
class HierarchicalTextFactory extends ElementFactory {
  static groups = new Map();  // form -> { instances: [], dataCache: null }
  static dataCache = null;    // Shared data cache
  static dataFormat = 'name'; // 'name' | 'code'

  // ---------------------------------------------------------------------------
  // Format helpers
  // ---------------------------------------------------------------------------

  /**
   * Detect whether the loaded data uses the new code-as-key format.
   * Code format: top-level values are objects that carry a "name" property.
   */
  static detectFormat(data) {
    if (!data) return 'name';
    const firstVal = Object.values(data)[0];
    return (firstVal && typeof firstVal === 'object' && 'name' in firstVal) ? 'code' : 'name';
  }

  /**
   * Return the children of a node, stripping the reserved "name" metadata key.
   * Only needed in code format where each non-leaf node has a {name, ...children} shape.
   */
  static getChildren(node) {
    if (!node || typeof node !== 'object') return node;
    const children = {};
    for (const k in node) {
      if (k !== 'name') children[k] = node[k];
    }
    return children;
  }

  /**
   * Resolve the display name for a code at a given tree level.
   * Returns the code itself when the data is in name format or the code is not found.
   */
  static _resolveDisplayName(code, level) {
    if (!this.dataCache || this.dataFormat !== 'code') return code;
    if (level === 0) {
      const node = this.dataCache[code];
      return (node && node.name) ? node.name : code;
    }
    return this._findName(this.dataCache, code, level, 0) ?? code;
  }

  static _findName(data, code, targetLevel, currentLevel) {
    if (!data || typeof data !== 'object') return null;
    for (const key in data) {
      if (key === 'name') continue;
      const child = data[key];
      if (currentLevel + 1 < targetLevel) {
        // Need to descend deeper before reaching the target level
        if (typeof child === 'object' && child !== null) {
          const result = this._findName(child, code, targetLevel, currentLevel + 1);
          if (result !== null) return result;
        }
      } else {
        // currentLevel + 1 === targetLevel: look for 'code' as a key inside 'child'
        if (typeof child === 'object' && child !== null && code in child) {
          const target = child[code];
          return typeof target === 'string' ? target
            : (target && target.name) ? target.name
              : code;
        }
      }
    }
    return null;
  }

  // ---------------------------------------------------------------------------
  // Value binding: code → display name  (called by TextElementFactory.propertyHandlers)
  // ---------------------------------------------------------------------------

  /**
   * Set a hierarchy input by code.
   * - In code format: resolves code → display name for the visible field;
   *   stores code in the hidden input (the submitted value).
   * - In name format (legacy): treats the value as a display name and sets both fields.
   * - If data has not loaded yet the value is queued and applied once data arrives.
   */
  static setValueByCode(instance, code) {
    if (code == null || code === '') {
      instance.element.value = '';
      if (instance.hiddenInput) instance.hiddenInput.value = '';
      instance.selectedValue = null;
      return;
    }
    const strCode = String(code);

    if (!this.dataCache) {
      // Data not yet loaded — queue and show raw code as placeholder
      instance._pendingCodeValue = strCode;
      instance.element.value = strCode;
      if (instance.hiddenInput) instance.hiddenInput.value = strCode;
      instance.selectedValue = strCode;
      return;
    }

    if (this.dataFormat === 'code') {
      const level = this.getLevelInGroup(instance);
      const name = this._resolveDisplayName(strCode, level);
      instance.element.value = name;
      if (instance.hiddenInput) instance.hiddenInput.value = strCode;
      instance.selectedValue = strCode;
      // Auto-fill zipcode when loading the last level (subdistrict)
      const instances = this.getInstancesInGroup(instance);
      if (level === instances.length - 1) {
        this._fillZipcode(instance, strCode);
      }
    } else {
      // Legacy name format: the "code" IS the display name
      instance.element.value = strCode;
      if (instance.hiddenInput) instance.hiddenInput.value = strCode;
      instance.selectedValue = strCode;
    }
  }

  /**
   * Returns true when the value is a leaf node (subdistrict):
   * either a plain string or an object with only name/zip metadata keys.
   */
  static _isLeaf(value) {
    if (typeof value === 'string') return true;
    if (!value || typeof value !== 'object') return false;
    return Object.keys(value).every(k => k === 'name' || k === 'zip');
  }

  /**
   * Find the zipcode for a subdistrict code by traversing the cached data.
   */
  static _getZipcode(subCode) {
    if (!this.dataCache || this.dataFormat !== 'code') return null;
    for (const pKey in this.dataCache) {
      if (pKey === 'name') continue;
      const prov = this.dataCache[pKey];
      if (!prov || typeof prov !== 'object') continue;
      for (const dKey in prov) {
        if (dKey === 'name') continue;
        const dist = prov[dKey];
        if (!dist || typeof dist !== 'object') continue;
        if (subCode in dist) {
          const sub = dist[subCode];
          return (sub && sub.zip) ? sub.zip : null;
        }
      }
    }
    return null;
  }

  /**
   * Fill the zipcode input in the same form as instance.
   */
  static _fillZipcode(instance, subCode) {
    const zip = this._getZipcode(subCode);
    if (!zip) return;
    const form = instance.element.closest('form') || document.body;
    const zipcodeInput = form.querySelector('input[name="zipcode"], input[name="zipcode_text"]');
    if (zipcodeInput) zipcodeInput.value = zip;
  }

  // ---------------------------------------------------------------------------
  // Group management
  // ---------------------------------------------------------------------------

  static getGroup(instance) {
    const form = instance.element.closest('form') || document.body;
    if (!this.groups.has(form)) {
      this.groups.set(form, {instances: [], dataCache: null});
    }
    return this.groups.get(form);
  }

  static register(instance) {
    instance.manager = this;
    const group = this.getGroup(instance);
    group.instances.push(instance);

    const source = instance.config.autocomplete.source;
    if (source && !this.dataCache) {
      this.loadData(source);
    } else if (this.dataCache) {
      group.dataCache = this.dataCache;
      // Data already loaded — apply any pending value immediately
      if (instance._pendingCodeValue != null) {
        this.setValueByCode(instance, instance._pendingCodeValue);
        delete instance._pendingCodeValue;
      }
    }
  }

  static loadData(source) {
    if (typeof source === 'string' && window[source]) {
      this.dataCache = window[source];
      this.dataFormat = this.detectFormat(this.dataCache);
      this.syncDataToGroups();
      return;
    }

    const isUrl = typeof source === 'string' && (
      source.startsWith('http') ||
      source.startsWith('/') ||
      source.endsWith('.json') ||
      source.includes('/')
    );

    if (isUrl) {
      const onLoaded = (data) => {
        this.dataCache = data;
        this.dataFormat = this.detectFormat(data);
        this.syncDataToGroups();
      };

      if (window.http && typeof window.http.get === 'function') {
        window.http.get(source, {throwOnError: false})
          .then(resp => {
            const data = (resp && resp.success && resp.data) ? resp.data
              : (resp && !resp.success && resp.data) ? resp.data : null;
            if (data) onLoaded(data);
            else console.error('[HierarchicalTextFactory] Failed to load:', source, resp);
          })
          .catch(err => console.error('[HierarchicalTextFactory] Error loading:', err));
      } else {
        const requestOptions = Now.applyRequestLanguage({method: 'GET'});
        fetch(source, requestOptions)
          .then(r => {if (!r.ok) throw new Error(`HTTP ${r.status}`); return r.json();})
          .then(onLoaded)
          .catch(err => console.error('[HierarchicalTextFactory] Error loading:', err));
      }
    }
  }

  static syncDataToGroups() {
    this.groups.forEach(group => {
      group.dataCache = this.dataCache;
      // Flush any pending code values now that data is available
      group.instances.forEach(inst => {
        if (inst._pendingCodeValue != null) {
          this.setValueByCode(inst, inst._pendingCodeValue);
          delete inst._pendingCodeValue;
        }
      });
    });
  }

  static getInstancesInGroup(instance) {
    return this.getGroup(instance).instances;
  }

  static getLevelInGroup(instance) {
    return this.getInstancesInGroup(instance).findIndex(inst => inst === instance);
  }

  static getInstanceByLevelInGroup(instance, level) {
    return this.getInstancesInGroup(instance)[level];
  }

  static onSelectionChange(changedInstance) {
    const instances = this.getInstancesInGroup(changedInstance);
    const level = instances.findIndex(inst => inst === changedInstance);
    if (level === -1) return;
    // Clear child levels
    for (let i = level + 1; i < instances.length; i++) {
      instances[i].element.value = '';
      instances[i].selectedValue = null;
      if (instances[i].hiddenInput) instances[i].hiddenInput.value = '';
    }
    // Auto-fill zipcode when the last level (subdistrict) is selected
    if (level === instances.length - 1 && this.dataFormat === 'code') {
      this._fillZipcode(changedInstance, changedInstance.selectedValue);
    }
  }

  // ---------------------------------------------------------------------------
  // Search
  // ---------------------------------------------------------------------------

  static search(instance, query) {
    const instances = this.getInstancesInGroup(instance);
    const level = instances.findIndex(inst => inst === instance);
    const totalLevels = instances.length;
    if (!this.dataCache) return;

    if (level === 0) {
      // Top level: normalizeSource already uses v.name for display when code format
      instance.populate(this.dataCache);
    } else {
      let data = this.dataCache;
      let allParentsSelected = true;

      for (let i = 0; i < level; i++) {
        const parentValue = instances[i]?.selectedValue;
        if (parentValue && data && data[parentValue]) {
          data = data[parentValue];
        } else {
          allParentsSelected = false;
          break;
        }
      }

      if (allParentsSelected && data) {
        // In code format each child node has a 'name' key — strip it before populate
        // so it does not appear as a dropdown option.
        instance.populate(this.dataFormat === 'code' ? this.getChildren(data) : data);
      } else if (query && query.length >= 1) {
        this.reverseSearch(instance, query, level, totalLevels);
      } else {
        instance.populate({});
      }
    }
  }

  // ---------------------------------------------------------------------------
  // Reverse search (type in child field without selecting parents first)
  // ---------------------------------------------------------------------------

  static reverseSearch(instance, query, targetLevel, totalLevels) {
    if (!query || !this.dataCache) return;

    const isCode = this.dataFormat === 'code';
    const filter = new RegExp(instance.escapeRegExp(query), 'i');
    const results = [];

    const traverse = (data, path = [], depth = 0) => {
      if (depth === targetLevel) {
        for (const key in data) {
          if (key === 'name') continue; // skip metadata in code format
          const value = data[key];
          const isLeaf = this._isLeaf(value);
          const displayName = isLeaf
            ? (typeof value === 'string' ? value : value.name)
            : ((isCode && value.name) ? value.name : key);
          if (filter.test(displayName)) {
            results.push({
              path: [...path],
              key,
              value: displayName,
              isLeaf,
              zip: (isLeaf && value && value.zip) ? value.zip : null
            });
          }
        }
      } else if (depth < targetLevel && typeof data === 'object' && data !== null) {
        for (const key in data) {
          if (key === 'name') continue;
          traverse(data[key], [...path, key], depth + 1);
        }
      }
    };

    traverse(this.dataCache);

    // Build flat map for the dropdown: uniqueKey → display text with parent context
    const flatResults = {};
    results.forEach(item => {
      const parents = isCode
        ? item.path.map((code, i) => this._resolveDisplayName(code, i)).reverse().join(', ')
        : item.path.slice().reverse().join(', ');

      const displayValue = item.value; // already resolved in traverse

      const uniqueKey = item.isLeaf
        ? item.key
        : [...item.path, item.key].join('||');

      flatResults[uniqueKey] = parents ? `${displayValue} (${parents})` : displayValue;
    });

    instance._pendingReverseData = results;
    instance._pendingReverseLevel = targetLevel;
    instance._pendingReverseTotalLevels = totalLevels;

    instance.populate(flatResults);
  }

  // ---------------------------------------------------------------------------
  // Handle selection from reverse search
  // ---------------------------------------------------------------------------

  static handleReverseSelection(instance, key, value) {
    const instances = this.getInstancesInGroup(instance);
    const results = instance._pendingReverseData;
    if (!results || !Array.isArray(results)) return;

    const isCode = this.dataFormat === 'code';

    let matchedResult = null;
    for (const item of results) {
      const itemKey = item.isLeaf ? item.key : [...item.path, item.key].join('||');
      if (itemKey === key) {matchedResult = item; break;}
    }
    if (!matchedResult) return;

    // Auto-fill parent levels
    matchedResult.path.forEach((pathValue, i) => {
      if (!instances[i]) return;
      const displayName = isCode ? this._resolveDisplayName(pathValue, i) : pathValue;
      instances[i].element.value = displayName;
      instances[i].selectedValue = pathValue;
      if (instances[i].hiddenInput) instances[i].hiddenInput.value = pathValue;
    });

    // Set current field — matchedResult.value is already the display name
    instance.element.value = matchedResult.value;
    instance.selectedValue = matchedResult.key;
    if (instance.hiddenInput) instance.hiddenInput.value = matchedResult.key;

    // Clear child levels
    const currentLevel = instances.findIndex(inst => inst === instance);
    for (let i = currentLevel + 1; i < instances.length; i++) {
      if (instances[i]) {
        instances[i].element.value = '';
        instances[i].selectedValue = null;
        if (instances[i].hiddenInput) instances[i].hiddenInput.value = '';
      }
    }

    // Auto-fill zipcode if the selected item (or the last filled level) has one
    if (matchedResult.zip) {
      const form = instance.element.closest('form') || document.body;
      const zipcodeInput = form.querySelector('input[name="zipcode"], input[name="zipcode_text"]');
      if (zipcodeInput) zipcodeInput.value = matchedResult.zip;
    } else if (matchedResult.isLeaf && this.dataFormat === 'code') {
      this._fillZipcode(instance, matchedResult.key);
    }

    instance._pendingReverseData = null;
    instance._pendingReverseLevel = null;
    instance._pendingReverseTotalLevels = null;
  }

  static isReverseSearchSelection(instance) {
    return instance._pendingReverseData != null;
  }

  /**
   * Unregister an instance from its group (called on destroy).
   */
  static unregister(instance) {
    const group = this.getGroup(instance);
    const idx = group.instances.indexOf(instance);
    if (idx !== -1) group.instances.splice(idx, 1);
  }
}

window.HierarchicalTextFactory = HierarchicalTextFactory;

class EmailElementFactory extends TextElementFactory {
  static config = {
    ...TextElementFactory.config,
    type: 'text',
    inputMode: 'email',
    validation: ['email'],
    formatter: (value) => {
      return value.toLowerCase();
    }
  };
}

class UrlElementFactory extends TextElementFactory {
  static config = {
    ...TextElementFactory.config,
    type: 'text',
    inputMode: 'url',
    validation: ['url']
  };
}

class UsernameElementFactory extends TextElementFactory {
  static config = {
    ...TextElementFactory.config,
    type: 'text',
    inputMode: 'text',
    validation: ['usernameOrEmail'],
    formatter: (value) => {
      return value.toLowerCase();
    }
  };
}

ElementManager.registerElement('text', TextElementFactory);
ElementManager.registerElement('email', EmailElementFactory);
ElementManager.registerElement('url', UrlElementFactory);
ElementManager.registerElement('username', UsernameElementFactory);
