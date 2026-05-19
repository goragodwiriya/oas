class SelectElementFactory extends ElementFactory {
  static config = {
    ...ElementFactory.config,
    cache: false,
    cacheTime: 60000,
    multiple: false,
    size: 1,
    typeToFilter: true,
    searchMethod: 'prefix',     //'Prefix' or 'Contains'
    searchResetDelay: 1000,     //When resetting the search term (MS)
    showSearchStatus: false,    //Show search status
    useOptGroups: false,        //Do you use OPTGRUP?
    allowPlaceholderSelection: false, //Is it allowed to select Placeholder?
    optimizeRendering: true,    //Improve the performance of many options
    ariaLabelledBy: null,       //ID of Element that will be used as Aria-labelledby
    emptyMessage: 'No options available', // Message when there is no option
    customTemplate: null,       // Functions for determining the display form, options
    validationMessages: {
      required: 'Please select an option'
    }
  };

  static propertyHandlers = {
    value: {
      get(element) {
        return element.multiple
          ? Array.from(element.selectedOptions).map(o => o.value)
          : element.value;
      },
      set(instance, newValue) {
        const {element} = instance;
        if (newValue == null || newValue === '') {
          const emptyOption = element.querySelector('option[value=""]');
          if (emptyOption) {
            emptyOption.selected = true;
          } else {
            element.selectedIndex = -1;
          }
        } else if (element.multiple && Array.isArray(newValue)) {
          Array.from(element.options).forEach(opt => {
            opt.selected = newValue.includes(opt.value);
          });
        } else {
          element.value = String(newValue);
        }
        // Sync custom dropdown if exists
        instance.constructor.syncCustomDropdown?.(instance);
      }
    },

    options: {
      get(element) {
        return Array.from(element.options).map(opt => ({
          value: opt.value,
          text: opt.textContent,
          selected: opt.selected,
          disabled: opt.disabled
        }));
      },
      set(instance, newValue) {
        this.updateOptions(instance.element, newValue, instance.config.useOptGroups);
      }
    },

    selectedOption: {
      get(element) {
        if (element.multiple) {
          return Array.from(element.selectedOptions).map(opt => ({
            value: opt.value,
            text: opt.textContent,
            index: opt.index
          }));
        }
        const option = element.options[element.selectedIndex];
        return option ? {value: option.value, text: option.textContent, index: element.selectedIndex} : null;
      },
      set(instance, newValue) {
        if (!newValue) return;

        const {element} = instance;
        if (Array.isArray(newValue) && element.multiple) {
          // Reset all selections
          Array.from(element.options).forEach(opt => opt.selected = false);

          // Set new selections
          newValue.forEach(item => {
            const value = typeof item === 'object' ? item.value : item;
            const option = Array.from(element.options).find(opt => opt.value === value);
            if (option) option.selected = true;
          });
        } else {
          const value = typeof newValue === 'object' ? newValue.value : newValue;
          element.value = value;
        }

        // Trigger change event
        element.dispatchEvent(new Event('change', {bubbles: true}));
      }
    }
  };

  static responseCache = new Map();

  static setupElement(instance) {
    const {element, config} = instance;

    // Store initial value before any manipulation
    // Priority: element.value > data-value attribute > empty string
    const initialValue = element.value || element.dataset.value || '';
    const initialMultipleValues = element.multiple
      ? Array.from(element.selectedOptions).map(opt => opt.value).filter(v => v !== '')
      : null;

    // Set basic values for select element
    if (config.multiple) element.multiple = true;
    if (config.size > 1) element.size = config.size;

    // Add accessibility attributes
    if (!element.hasAttribute('aria-label') && !element.hasAttribute('aria-labelledby')) {
      if (config.ariaLabelledBy) {
        element.setAttribute('aria-labelledby', config.ariaLabelledBy);
      } else if (config.label) {
        element.setAttribute('aria-label', Now.translate(config.label));
        element.dataset.ariaI18n = config.label;
      }
    }

    // Step 1: Add options from provided data FIRST (before placeholder)
    if (config.options) {
      this.updateOptions(element, config.options, config.useOptGroups, config.placeholder);
    }

    // Step 2: Add placeholder option only if explicitly provided AND no options were added
    // (updateOptions handles placeholder internally when options exist)
    if (!config.options && typeof config.placeholder === 'string' && config.placeholder.trim() !== '' && (!config.multiple || config.allowPlaceholderSelection)) {
      const placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = Now.translate(config.placeholder);
      placeholder.dataset.i18n = config.placeholder;
      placeholder.dataset.placeholder = 'true';

      if (!config.allowPlaceholderSelection) {
        placeholder.disabled = true;
      }

      // Insert placeholder at the beginning
      element.insertBefore(placeholder, element.firstChild);
    }

    // Step 3: Restore value LAST (after all options are in place)
    if (element.multiple && initialMultipleValues && initialMultipleValues.length > 0) {
      Array.from(element.options).forEach(opt => {
        opt.selected = initialMultipleValues.includes(opt.value);
      });
    } else if (initialValue !== null && initialValue !== undefined && initialValue !== '') {
      // Check if option exists
      const optionExists = Array.from(element.options).some(opt =>
        opt.value == initialValue || String(opt.value) === String(initialValue)
      );
      if (optionExists) {
        element.value = initialValue;
      }
    }

    // Load options from URL if specified
    if (config.url) {
      this.loadOptions(instance, config.url, config.params);
    }

    // Add type-to-filter capability
    if (config.typeToFilter) {
      this.setupTypeToFilter(instance);
    }

    // Add method for updating options (pass placeholder from config)
    instance.updateOptions = function(options) {
      SelectElementFactory.updateOptions(element, options, config.useOptGroups, config.placeholder);
    };

    // Add method for loading options from URL
    instance.loadOptions = function(url, params) {
      return SelectElementFactory.loadOptions(instance, url, params);
    };

    // Add method for clearing options
    instance.clearOptions = function() {
      SelectElementFactory.clearOptions(element, config.placeholder);
    };

    // Add method for selecting option by value
    instance.selectByValue = function(value) {
      element.value = value;
      element.dispatchEvent(new Event('change', {bubbles: true}));
    };

    // Add method for selecting option by index
    instance.selectByIndex = function(index) {
      if (index >= 0 && index < element.options.length) {
        element.selectedIndex = index;
        element.dispatchEvent(new Event('change', {bubbles: true}));
      }
    };

    // Wire up change -> config.onChange so factories and manual listeners behave consistently
    try {
      this.setupChangeHandler(instance);
    } catch (e) {
      // ignore
    }

    // Add method for validating
    instance.validateSpecific = function(value) {
      if (this.element.required && (!value || value === '')) {
        return Now.translate(this.config.validationMessages?.required || 'This field is required');
      }
      return null;
    };

    // Add method for updating specific options
    instance.updateOptionAt = function(index, properties) {
      if (index >= 0 && index < element.options.length) {
        const option = element.options[index];
        if (properties.text) {
          option.textContent = properties.text;
        }
        if (typeof properties.disabled !== 'undefined') {
          option.disabled = properties.disabled;
        }
        if (typeof properties.selected !== 'undefined') {
          option.selected = properties.selected;
        }
        if (properties.value) {
          option.value = properties.value;
        }
      }
    };

    return instance;
  }

  static setupTypeToFilter(instance) {
    const {element, config} = instance;

    let searchTerm = '';
    let searchTimeout;
    let searchStatusElement = null;

    // If showing search status is enabled, create a DOM element to display it
    if (config.showSearchStatus) {
      searchStatusElement = document.createElement('div');
      searchStatusElement.className = 'select-search-status';
      searchStatusElement.style.display = 'none';
      searchStatusElement.setAttribute('aria-live', 'polite');
      searchStatusElement.setAttribute('role', 'status');

      // Insert after select element
      if (element.parentNode) {
        element.parentNode.insertBefore(searchStatusElement, element.nextSibling);
      }

      instance.searchStatusElement = searchStatusElement;
    }

    const handleSearch = (e) => {
      // Ignore special keys like Arrow, Enter, Tab
      if (e.key.length === 1 || e.key === 'Backspace') {
        if (e.key === 'Backspace') {
          // Remove last character
          searchTerm = searchTerm.slice(0, -1);
        } else {
          // Add typed character
          searchTerm += e.key.toLowerCase();
        }

        // Reset search timeout
        clearTimeout(searchTimeout);

        // Find options matching searchTerm
        const options = Array.from(element.options).filter(opt =>
          !opt.disabled &&
          opt.parentElement.tagName !== 'OPTGROUP' ||
          !opt.parentElement.disabled
        );

        if (options.length === 0) return;

        // Choose search method: prefix, contains, or fuzzy
        const searchMethod = config.searchMethod || 'prefix';

        const matchingOption = options.find(opt => {
          const text = String(opt.text ?? opt.textContent ?? '').toLowerCase();
          if (searchMethod === 'prefix') {
            return text.startsWith(searchTerm);
          } else if (searchMethod === 'contains') {
            return text.includes(searchTerm);
          } else if (searchMethod === 'fuzzy') {
            // Fuzzy matching algorithm: find characters appearing in order
            let j = 0;
            for (let i = 0; i < text.length && j < searchTerm.length; i++) {
              if (text[i] === searchTerm[j]) j++;
            }
            return j === searchTerm.length;
          }
          return text.startsWith(searchTerm); // default
        });

        if (matchingOption) {
          // Select the option matching the search
          element.value = matchingOption.value;

          // Scroll the option into view (for multiple or size > 1 select)
          if (element.size > 1 || element.multiple) {
            const index = Array.from(element.options).indexOf(matchingOption);
            if (matchingOption.scrollIntoView) {
              matchingOption.scrollIntoView({block: 'nearest'});
            }
          }

          // Dispatch change event
          element.dispatchEvent(new Event('change', {bubbles: true}));

          // Show search status (if enabled)
          if (config.showSearchStatus && searchStatusElement) {
            searchStatusElement.textContent = `${Now.translate('Searching')}: ${searchTerm}`;
            searchStatusElement.style.display = 'block';
          }
        }

        // Clear searchTerm after a period of no key presses
        searchTimeout = setTimeout(() => {
          searchTerm = '';

          if (config.showSearchStatus && searchStatusElement) {
            searchStatusElement.style.display = 'none';
          }
        }, config.searchResetDelay);
      }
    };

    // Register event handler appropriately
    EventSystemManager.addHandler(element, 'keydown', handleSearch);

    // Store reference to handler for later removal
    instance._typeToFilterHandler = handleSearch;
  }

  static normalizeCacheTime(value, fallback = 60000) {
    const parsed = parseInt(value, 10);
    return Number.isFinite(parsed) && parsed >= 0 ? parsed : fallback;
  }

  static createCacheKey(url, params = {}) {
    const queryString = new URLSearchParams(params).toString();
    return `${url}${queryString ? '?' + queryString : ''}`;
  }

  static getCachedResponse(cacheKey) {
    const cached = this.responseCache.get(cacheKey);
    if (!cached) return null;
    if (cached.expiresAt <= Date.now()) {
      this.responseCache.delete(cacheKey);
      return null;
    }
    return cached.data;
  }

  static setCachedResponse(cacheKey, data, cacheTime) {
    this.responseCache.set(cacheKey, {
      data,
      timestamp: Date.now(),
      expiresAt: Date.now() + this.normalizeCacheTime(cacheTime, this.config.cacheTime)
    });
  }

  static async loadOptions(instance, url, params = {}, requestOptions = {}) {
    const {element, config} = instance;

    try {
      const useCache = config.cache === true && requestOptions.force !== true;
      const cacheKey = this.createCacheKey(url, params);

      // Add loading class and disable element during loading
      element.disabled = true;
      if (instance.wrapper) {
        instance.wrapper.classList.add('loading');
      }

      // Show loading message
      if (element.parentNode && config.loadingMessage) {
        const loadingOption = document.createElement('option');
        loadingOption.disabled = true;
        loadingOption.selected = true;
        loadingOption.textContent = Now.translate(config.loadingMessage || 'Loading...');

        // Save original options
        const originalHTML = element.innerHTML;
        element.innerHTML = '';
        element.appendChild(loadingOption);

        // Restore original options on error
        instance._originalOptions = originalHTML;
      }

      if (useCache) {
        const cachedData = this.getCachedResponse(cacheKey);
        if (cachedData) {
          this.updateOptions(element, cachedData, config.useOptGroups, config.placeholder);

          const initialValue = element.dataset.value;
          if (initialValue) {
            element.value = initialValue;
            element.dispatchEvent(new Event('change', {bubbles: true}));
          }

          return cachedData;
        }
      }

      // Specify desired response type and send parameters
      const requestConfig = {
        params,
        headers: {
          'Accept': 'application/json',
          ...(useCache ? {} : {
            'Cache-Control': 'no-cache',
            'Pragma': 'no-cache'
          })
        },
        cache: useCache ? 'default' : 'no-store'
      };

      const apiService = window.ApiService || window.Now?.getManager?.('api');
      let response;
      if (apiService?.get) {
        const apiOptions = useCache
          ? {
            headers: requestConfig.headers,
            cache: {
              ...(apiService.config?.cache || {}),
              enabled: true,
              expiry: {
                ...((apiService.config?.cache && apiService.config.cache.expiry) || {}),
                get: this.normalizeCacheTime(config.cacheTime, this.config.cacheTime)
              }
            }
          }
          : {
            headers: requestConfig.headers,
            deduplicate: false,
            cache: {
              ...(apiService.config?.cache || {}),
              enabled: false,
              storageType: 'no-store',
              expiry: {
                ...((apiService.config?.cache && apiService.config.cache.expiry) || {}),
                get: 0
              }
            }
          };
        response = await apiService.get(url, params, apiOptions);
      } else if (typeof simpleFetch !== 'undefined' && simpleFetch.get) {
        const queryParams = new URLSearchParams(params).toString();
        const fullUrl = queryParams ? `${url}?${queryParams}` : url;
        response = await simpleFetch.get(fullUrl, {
          headers: requestConfig.headers,
          cache: requestConfig.cache
        });
      } else if (typeof http !== 'undefined' && http.get) {
        response = await http.get(url, requestConfig);
      } else {
        throw new Error('ApiService is not available');
      }

      // Update options from received data (pass placeholder from config)
      if (response.data) {
        this.updateOptions(element, response.data, config.useOptGroups, config.placeholder);
        if (useCache) {
          this.setCachedResponse(cacheKey, response.data, config.cacheTime);
        }
      }

      // Set initial value (if any)
      const initialValue = element.dataset.value;
      if (initialValue) {
        element.value = initialValue;
        element.dispatchEvent(new Event('change', {bubbles: true}));
      }

      return response.data;
    } catch (error) {
      // Show error message
      if (typeof ErrorManager !== 'undefined') {
        ErrorManager.handle(error, {
          context: 'SelectElementFactory.loadOptions',
          type: 'error:select',
          data: {
            elementId: element.id,
            url,
            params
          },
          notify: true
        });
      } else if (window.NotificationManager) {
        NotificationManager.error('Failed to load options');
      }

      // Restore original options
      if (instance._originalOptions) {
        element.innerHTML = instance._originalOptions;
        delete instance._originalOptions;
      }

      return null;
    } finally {
      // Enable element and remove loading class
      element.disabled = false;
      if (instance.wrapper) {
        instance.wrapper.classList.remove('loading');
      }
    }
  }

  static updateOptions(element, options, useOptGroups = false, placeholder = null) {
    // Store current value - for multiple select, get array of selected values
    const isMultiple = element.multiple;
    const currentValue = element.value || element.getAttribute('value') || element.dataset.value || '';
    const normalizedValue = currentValue !== null && currentValue !== undefined ? String(currentValue) : '';

    // For multiple select, store selected values as array
    const currentMultipleValues = isMultiple
      ? Array.from(element.selectedOptions).map(opt => opt.value).filter(v => v !== '')
      : null;

    // Check for existing placeholder (only check data-placeholder attribute to avoid false positives)
    const existingPlaceholder = element.querySelector('option[data-placeholder="true"]');
    const placeholderHTML = existingPlaceholder ? existingPlaceholder.outerHTML : '';

    // Clear existing options
    element.innerHTML = '';

    // Add placeholder first if provided or existing
    if (typeof placeholder === 'string' && placeholder.trim() !== '') {
      const placeholderOpt = document.createElement('option');
      placeholderOpt.value = '';
      placeholderOpt.textContent = Now.translate(placeholder);
      placeholderOpt.dataset.i18n = placeholder;
      placeholderOpt.dataset.placeholder = 'true';
      placeholderOpt.disabled = true;
      element.appendChild(placeholderOpt);
    } else if (placeholderHTML) {
      // Restore existing placeholder
      element.insertAdjacentHTML('afterbegin', placeholderHTML);
    }

    // Handle case with no data
    if (!options || (Array.isArray(options) && options.length === 0) ||
      (typeof options === 'object' && Object.keys(options).length === 0)) {
      const emptyOption = document.createElement('option');
      emptyOption.disabled = true;
      emptyOption.textContent = Now.translate('No options available');

      if (!placeholder && !placeholderHTML) {
        element.appendChild(emptyOption);
      }

      return;
    }

    // Accept Array, Map or plain Object for options.
    // Prefer array form to preserve ordering. If a plain object is provided,
    // convert it into an ordered array. Ensure that an empty-string key ('')
    // (commonly used for "All"/placeholder) appears first to avoid JS
    // integer-like key ordering quirks.
    const normalizedOptions = Utils.options?.normalizeSource
      ? Utils.options.normalizeSource(options)
      : (Array.isArray(options) ? options : []);

    if (normalizedOptions.length === 0) {
      // no options -> show empty message / placeholder preserved earlier
      const emptyOption = document.createElement('option');
      emptyOption.disabled = true;
      emptyOption.textContent = Now.translate('No options available');

      if (!placeholder && !placeholderHTML) {
        element.appendChild(emptyOption);
      }
      return;
    }

    // If normalizedOptions looks like groups (objects with .options), reuse createOptGroups
    if (useOptGroups && this.hasOptGroups(normalizedOptions)) {
      this.createOptGroups(element, normalizedOptions);
    } else {
      normalizedOptions.forEach(opt => this.createOption(element, opt));
    }

    // Restore original value LAST (after all options are created)
    if (isMultiple && currentMultipleValues && currentMultipleValues.length > 0) {
      // Handle multiple select case - use stored array of values
      currentMultipleValues.forEach(value => {
        // Use loose equality to handle type coercion
        const option = Array.from(element.options).find(opt =>
          opt.value == value || String(opt.value) === String(value)
        );
        if (option) {
          option.selected = true;
        }
      });
    } else if (normalizedValue && !isMultiple) {
      // Check if the option still exists - use loose equality to handle type coercion
      const optionExists = Array.from(element.options).some(opt =>
        opt.value == normalizedValue || String(opt.value) === normalizedValue
      );
      if (optionExists) {
        element.value = normalizedValue;
        // Also set the selected property directly for consistency
        Array.from(element.options).forEach(opt => {
          opt.selected = opt.value == normalizedValue || String(opt.value) === normalizedValue;
        });
      }
    }

    // Emit event to notify that options have changed
    element.dispatchEvent(new CustomEvent('optionschanged', {
      bubbles: true,
      detail: {options}
    }));
  }

  static clearOptions(element, keepPlaceholder = true) {
    if (keepPlaceholder) {
      // Use data-placeholder attribute to identify placeholder option
      const placeholder = element.querySelector('option[data-placeholder="true"]');
      element.innerHTML = placeholder ? placeholder.outerHTML : '';
    } else {
      element.innerHTML = '';
    }

    // Emit event
    element.dispatchEvent(new CustomEvent('optionschanged', {
      bubbles: true,
      detail: {options: []}
    }));
  }

  static hasOptGroups(options) {
    return options.some(opt => typeof opt === 'object' && opt.options);
  }

  static createOptGroups(element, groups) {
    let fragment;

    // Use DocumentFragment for performance with many options
    if (groups.length > 50) {
      fragment = document.createDocumentFragment();
    }

    // Create optgroups and options
    groups.forEach(group => {
      if (typeof group === 'object' && group.options) {
        // Create optgroup
        const optgroup = document.createElement('optgroup');
        optgroup.label = Now.translate(group.label);
        if (group.disabled) optgroup.disabled = true;

        // Create options in optgroup
        if (Array.isArray(group.options)) {
          group.options.forEach(opt => {
            this.createOption(optgroup, opt);
          });
        }

        if (fragment) {
          fragment.appendChild(optgroup);
        } else {
          element.appendChild(optgroup);
        }
      } else {
        // Handle case where it's not an optgroup, create regular option
        if (fragment) {
          this.createOption(fragment, group);
        } else {
          this.createOption(element, group);
        }
      }
    });

    // Append fragment to element (if any)
    if (fragment) {
      element.appendChild(fragment);
    }
  }

  static createOption(parent, opt) {
    const option = document.createElement('option');

    if (typeof opt === 'object') {
      const optionLabel = opt.text ?? opt.label ?? opt.name ?? opt.value ?? '';
      option.value = opt.value !== undefined ? opt.value : '';
      option.textContent = Now.translate(typeof optionLabel === 'string' ? optionLabel : String(optionLabel));

      if (typeof optionLabel === 'string' && optionLabel !== '') option.dataset.i18n = optionLabel;
      if (opt.disabled) option.disabled = true;
      if (opt.selected) option.selected = true;

      // Copy opt.data properties to dataset
      if (opt.data) {
        Object.entries(opt.data).forEach(([key, value]) => {
          option.dataset[key] = value;
        });
      }

      // Copy any custom properties as data attributes (excluding standard option properties)
      const standardProps = ['value', 'text', 'label', 'disabled', 'selected', 'data', 'className'];
      Object.entries(opt).forEach(([key, value]) => {
        if (!standardProps.includes(key) && value !== undefined && value !== null) {
          // Convert snake_case to camelCase for dataset (e.g., area_rai -> areaRai)
          const camelKey = key.replace(/_([a-z])/g, (_, letter) => letter.toUpperCase());
          option.dataset[camelKey] = value;
        }
      });

      if (opt.className) option.className = opt.className;
    } else {
      option.value = opt;
      option.textContent = Now.translate(opt);
      option.dataset.i18n = opt;
    }

    parent.appendChild(option);
  }

  static setupEventListeners(instance) {
    const {element, config} = instance;

    // Get parent's event handlers
    const parentHandlers = super.setupEventListeners(instance);

    // Add event handler for locale change (to update placeholder and options with i18n)
    const updateTranslations = () => {
      Array.from(element.options).forEach(option => {
        if (option.dataset.i18n) {
          option.textContent = Now.translate(option.dataset.i18n);
        }
      });

      // Update aria-label
      if (element.dataset.ariaI18n) {
        element.setAttribute('aria-label', Now.translate(element.dataset.ariaI18n));
      }
    };

    // Register event handler
    if (window.EventManager) {
      EventManager.on('locale:changed', updateTranslations);
    }

    // Store handler for cleanup
    instance._localeChangeHandler = updateTranslations;

    return parentHandlers;
  }

  // Ensure onChange config is invoked when the select value changes
  static setupChangeHandler(instance) {
    const {element, config} = instance;
    try {
      EventSystemManager.addHandler(element, 'change', (e) => {
        try {
          if (config && typeof config.onChange === 'function') {
            const value = element.multiple ? Array.from(element.selectedOptions).map(o => o.value) : element.value;
            config.onChange(element, value);
          }
        } catch (err) {
          console.warn('SelectElementFactory onChange handler error', err);
        }
      });
    } catch (err) {
      // non-fatal
    }
  }

  static customValidateValue(value, valueChange) {
    const element = this.element;

    // If it is a multiple select
    if (element.multiple) {
      const selectedOptions = Array.from(element.selectedOptions);
      if (element.required && selectedOptions.length === 0) {
        return {
          validatedValue: value,
          error: Now.translate(this.config.validationMessages.required)
        };
      }
    } else if (element.required && (!value || value === '')) {
      return {
        validatedValue: value,
        error: Now.translate(this.config.validationMessages.required)
      };
    }

    return {
      validatedValue: value,
      error: null
    };
  }

  static create(def) {
    def.tagName = 'select';

    // Create select element via parent
    const instance = super.create(def);
    const element = instance.element;

    // Add options if provided
    if (def.options && Array.isArray(def.options)) {
      // Clear any existing options
      element.innerHTML = '';

      // Add placeholder option if specified
      if (def.placeholder) {
        const placeholderOpt = document.createElement('option');
        placeholderOpt.value = '';
        placeholderOpt.textContent = def.placeholder;
        placeholderOpt.disabled = true;
        placeholderOpt.selected = true;
        placeholderOpt.dataset.placeholder = 'true';  // Mark as placeholder
        element.appendChild(placeholderOpt);
      }

      // Add options
      def.options.forEach(opt => {
        const option = document.createElement('option');
        if (typeof opt === 'object') {
          const optionLabel = opt.text ?? opt.label ?? opt.name ?? opt.value ?? '';
          option.value = opt.value !== undefined ? opt.value : opt.id;
          option.textContent = typeof optionLabel === 'string' ? optionLabel : String(optionLabel);
          if (opt.selected) option.selected = true;
          if (opt.disabled) option.disabled = true;
        } else {
          option.value = opt;
          option.textContent = opt;
        }
        element.appendChild(option);
      });
    }

    return instance;
  }

  static cleanup(instance) {
    if (!instance) return;

    const {element} = instance;

    if (instance._typeToFilterHandler) {
      EventSystemManager.removeHandler(element, 'keydown', instance._typeToFilterHandler);
    }

    if (instance._localeChangeHandler && window.EventManager) {
      EventManager.off('locale:changed', instance._localeChangeHandler);
    }

    if (instance.searchStatusElement && instance.searchStatusElement.parentNode) {
      instance.searchStatusElement.parentNode.removeChild(instance.searchStatusElement);
    }

    super.cleanup?.(instance);
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

    let options = Utils.options?.normalizeSource
      ? Utils.options.normalizeSource(optionsData[optionsKey])
      : optionsData[optionsKey];
    if (!options) return;

    if (!Array.isArray(options)) {
      console.warn('Please update your API to return options in Array, Map, or Object option format.');
      return;
    }

    // Save current value before updating options
    const currentValue = element.value;

    // Update element's options using existing updateOptions method
    this.updateOptions(element, options, element.dataset.useOptGroups === 'true');

    // Restore value after populating options
    if (currentValue) {
      // Use loose equality (==) to handle type coercion (e.g., 10 == "10")
      // or convert to string for strict comparison to avoid type mismatch issues
      const optionExists = Array.from(element.options).some(opt =>
        opt.value == currentValue || String(opt.value) === String(currentValue)
      );
      if (optionExists) {
        element.value = currentValue;
      }
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

    // Select only single selects (not multiple)
    const selectsWithOptionsKey = container.querySelectorAll('select[data-options-key]:not([multiple])');

    selectsWithOptionsKey.forEach(select => {
      const optionsKey = select.dataset.optionsKey;
      this.populateFromOptions(select, optionsData, optionsKey);
    });
  }
}

// Register element with ElementManager
ElementManager.registerElement('select', SelectElementFactory);

// Expose globally
window.SelectElementFactory = SelectElementFactory;
