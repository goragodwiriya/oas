const CascadingSelectManager = {
  config: {
    loadingClass: 'loading',
    ajaxDelay: 300,
    defaultOption: true,
    defaultOptionText: 'Please select...',
    autoLoad: true,
    autoDisable: true,
    placeholders: {},
    debug: false
  },

  state: {
    instances: new Map(),
    requests: new Map(),
    initialized: false
  },

  init(options = {}) {
    if (this.state.initialized) return this;

    this.config = {...this.config, ...options};

    // Initialize any existing cascading selects with data-cascade attribute
    document.querySelectorAll('[data-cascade]').forEach(select => {
      this.create(select);
    });

    this.state.initialized = true;
    return this;
  },

  /**
   * Initialize cascading selects within a form container
   * Called by FormManager after form initialization
   * @param {HTMLElement} container - Form element or container
   */
  initInContainer(container) {
    if (!container) return;

    // Method 1: Find selects with data-cascade-group and group them
    const groups = new Map();
    container.querySelectorAll('select[data-cascade-group]').forEach(select => {
      const groupName = select.dataset.cascadeGroup;
      if (!groups.has(groupName)) {
        groups.set(groupName, []);
      }
      groups.get(groupName).push(select);
    });

    // Create cascade instances for each group
    groups.forEach((selects, groupName) => {
      // Sort by data-cascade-order if present, otherwise by DOM order
      selects.sort((a, b) => {
        const orderA = parseInt(a.dataset.cascadeOrder || '0');
        const orderB = parseInt(b.dataset.cascadeOrder || '0');
        return orderA - orderB;
      });

      // Extract options from first select with URL
      const firstWithUrl = selects.find(s => s.dataset.cascadeUrl || s.dataset.cascadeAction);
      if (firstWithUrl) {
        const dataOptions = this.extractDataOptions(firstWithUrl);
        this.create(selects, dataOptions);
      }
    });

    // Method 2: Find selects with data-cascade-parent (linked cascade)
    // Build cascade chains from parent-child relationships
    const chainedSelects = container.querySelectorAll('select[data-cascade-parent]');
    if (chainedSelects.length > 0) {
      // Find all roots (selects that are parents but have no parent themselves)
      const childNames = new Set();
      const parentNames = new Set();

      chainedSelects.forEach(select => {
        const parentName = select.dataset.cascadeParent;
        childNames.add(select.name || select.id);
        parentNames.add(parentName);
      });

      // Root parents are those in parentNames but not in childNames
      const rootParentNames = [...parentNames].filter(name => !childNames.has(name));

      // For each root, build the cascade chain
      rootParentNames.forEach(rootName => {
        const chain = [];
        const rootSelect = container.querySelector(`select[name="${rootName}"], select#${rootName}`);
        if (!rootSelect) return;

        chain.push(rootSelect);

        // Build chain by following parent-child relationships
        let currentName = rootName;
        let safetyCounter = 0;
        while (safetyCounter++ < 10) { // Prevent infinite loops
          const child = container.querySelector(`select[data-cascade-parent="${currentName}"]`);
          if (!child) break;
          chain.push(child);
          currentName = child.name || child.id;
        }

        // Get URL from first child with data-cascade-url
        const firstWithUrl = chain.find(s => s.dataset.cascadeUrl || s.dataset.cascadeAction);
        if (firstWithUrl && chain.length > 1) {
          const dataOptions = this.extractDataOptions(firstWithUrl);
          this.create(chain, dataOptions);
        }
      });
    }
  },

  create(selects, options = {}) {
    // Allow array of selects, single select element, or string selector
    let selectElements = [];

    if (Array.isArray(selects)) {
      // Array of IDs or elements
      selectElements = selects.map(item =>
        typeof item === 'string' ? document.getElementById(item) : item
      ).filter(el => el);
    } else if (typeof selects === 'string') {
      // Single ID or selector
      if (selects.includes(',')) {
        // Comma-separated list of IDs
        selectElements = selects.split(',')
          .map(id => document.getElementById(id.trim()))
          .filter(el => el);
      } else if (selects.startsWith('#')) {
        // Single ID selector
        const element = document.getElementById(selects.substring(1));
        if (element) selectElements.push(element);
      } else {
        // Query selector for multiple elements
        selectElements = Array.from(document.querySelectorAll(selects));
      }
    } else if (selects instanceof HTMLElement) {
      // Single element
      selectElements.push(selects);
    }

    if (selectElements.length === 0) {
      if (this.config.debug) {
        console.warn('CascadingSelectManager: No select elements found');
      }
      return null;
    }

    // Extract data attributes from first select
    const dataOptions = this.extractDataOptions(selectElements[0]);

    // Combine configs: default < data attributes < options parameter
    const config = {...this.config, ...dataOptions, ...options};

    // Ensure we have an action URL
    if (!config.action && !config.url) {
      if (this.config.debug) {
        console.warn('CascadingSelectManager: No action URL specified');
      }
      return null;
    }

    // Create group ID for this set of selects
    const groupId = config.groupId || `cascade_${Date.now()}_${Math.floor(Math.random() * 1000)}`;

    // Create cascade instance
    const instance = {
      id: groupId,
      elements: selectElements,
      config: config,
      state: {
        loading: false,
        initialized: false,
        values: {}
      }
    };

    // Store instance
    this.state.instances.set(groupId, instance);

    // Setup each select element
    selectElements.forEach((select, index) => {
      // Store original options for the first select
      if (index === 0) {
        this.storeOriginalOptions(select);
      }

      // Set element properties
      select.dataset.cascadeGroup = groupId;
      select.dataset.cascadeIndex = index;

      // Store reference on the element
      select.cascadeInstance = instance;

      // Clear all options except the first select
      if (index > 0 && config.autoLoad) {
        this.clearOptions(select, config);

        // Disable non-first selects initially
        if (config.autoDisable) {
          select.disabled = true;
        }
      }

      // Setup event handlers
      this.setupSelectEvents(select, instance);
    });

    // Mark instance as initialized
    instance.state.initialized = true;

    // Auto-trigger the first select if it has a value
    const firstSelect = selectElements[0];
    if (config.autoLoad && firstSelect.value) {
      this.loadOptions(firstSelect);
    }

    return instance;
  },

  extractDataOptions(select) {
    const options = {};
    const dataset = select.dataset;

    if (dataset.cascadeAction) {
      options.action = dataset.cascadeAction;
    } else if (dataset.cascadeUrl) {
      options.url = dataset.cascadeUrl;
    }

    if (dataset.cascadeParam) {
      options.paramName = dataset.cascadeParam;
    }

    if (dataset.cascadeMethod) {
      options.method = dataset.cascadeMethod.toUpperCase();
    }

    if (dataset.cascadeDefault !== undefined) {
      options.defaultOption = dataset.cascadeDefault === 'true';
    }

    if (dataset.cascadeDefaultText) {
      options.defaultOptionText = dataset.cascadeDefaultText;
    }

    if (dataset.cascadeAutoLoad !== undefined) {
      options.autoLoad = dataset.cascadeAutoLoad === 'true';
    }

    if (dataset.cascadeDisable !== undefined) {
      options.autoDisable = dataset.cascadeDisable === 'true';
    }

    if (dataset.cascadeCallback) {
      if (typeof window[dataset.cascadeCallback] === 'function') {
        options.onchange = window[dataset.cascadeCallback];
      }
    }

    return options;
  },

  storeOriginalOptions(select) {
    select._originalOptions = Array.from(select.options).map(option => {
      return {
        value: option.value,
        text: option.textContent,
        selected: option.selected,
        disabled: option.disabled
      };
    });
  },

  setupSelectEvents(select, instance) {
    // Clean up any existing event handlers
    if (select._changeHandler) {
      select.removeEventListener('change', select._changeHandler);
    }

    // Create change event handler
    const changeHandler = (event) => {
      const index = parseInt(select.dataset.cascadeIndex);

      // Save current value
      instance.state.values[index] = select.value;

      // Only load options for the next select if this is not the last one
      if (index < instance.elements.length - 1) {
        this.loadOptions(select);
      }

      // Call onchange callback if defined
      if (typeof instance.config.onchange === 'function') {
        instance.config.onchange.call(select, instance);
      }

      // Emit change event
      this.emitEvent('cascade:change', {
        instance,
        element: select,
        index: index,
        value: select.value
      });
    };

    // Save reference to handler for cleanup
    select._changeHandler = changeHandler;

    // Add event listener
    select.addEventListener('change', changeHandler);
  },

  clearOptions(select, config) {
    // Remove all options except the default option if enabled
    while (select.options.length > (config.defaultOption ? 1 : 0)) {
      select.remove(config.defaultOption ? 1 : 0);
    }

    // Add or update default option
    if (config.defaultOption) {
      if (select.options.length === 0) {
        const defaultOption = document.createElement('option');
        defaultOption.value = '';
        defaultOption.textContent = config.defaultOptionText;
        defaultOption.disabled = true;
        defaultOption.selected = true;
        select.appendChild(defaultOption);
      } else {
        select.options[0].textContent = config.placeholders[select.id] ||
          select.dataset.placeholder ||
          config.defaultOptionText;
      }
    }

    // Reset value
    select.value = '';
  },

  async loadOptions(select) {
    const index = parseInt(select.dataset.cascadeIndex);
    const instance = select.cascadeInstance;
    const {config} = instance;

    // Get next select in the cascade
    const nextIndex = index + 1;
    if (nextIndex >= instance.elements.length) {
      return; // No more selects to populate
    }

    const nextSelect = instance.elements[nextIndex];

    // Don't proceed if next select doesn't exist
    if (!nextSelect) return;

    // Clear subsequent selects
    for (let i = nextIndex; i < instance.elements.length; i++) {
      const subsequentSelect = instance.elements[i];
      this.clearOptions(subsequentSelect, config);

      // Disable subsequent selects
      if (config.autoDisable) {
        subsequentSelect.disabled = true;
      }

      // Reset saved values
      instance.state.values[i] = '';

      // Emit clear event
      this.emitEvent('cascade:clear', {
        instance,
        element: subsequentSelect,
        index: i
      });
    }

    // If the select has no value, don't fetch options
    if (!select.value) return;

    // Mark as loading
    instance.state.loading = true;
    nextSelect.classList.add(config.loadingClass);

    // Cancel any pending request for this select
    if (this.state.requests.has(nextSelect)) {
      const previousRequest = this.state.requests.get(nextSelect);
      if (previousRequest.abort) {
        previousRequest.abort();
      }
    }

    try {
      // Build request data
      const formData = new FormData();

      // Add current values from all previous selects
      for (let i = 0; i <= index; i++) {
        const paramName = instance.elements[i].name ||
          instance.elements[i].id ||
          `select${i}`;

        formData.append(paramName, instance.elements[i].value);
      }

      // Add target parameter - which select we're loading for
      const targetParamName = nextSelect.name || nextSelect.id || `select${nextIndex}`;
      formData.append('target', targetParamName);

      // Add custom parameters if any
      if (config.params) {
        Object.entries(config.params).forEach(([key, value]) => {
          formData.append(key, value);
        });
      }

      // Prepare request options
      const url = config.action || config.url;
      const method = config.method || 'GET';

      // Create fetch options
      const fetchOptions = Now.applyRequestLanguage({
        method,
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      });

      // Add body based on method
      if (method === 'GET') {
        // For GET, convert FormData to query string
        const params = new URLSearchParams();
        for (const [key, value] of formData.entries()) {
          params.append(key, value);
        }

        // Append query string to URL
        const fullUrl = `${url}${url.includes('?') ? '&' : '?'}${params.toString()}`;

        // Make the request
        const response = await fetch(fullUrl, {
          method: 'GET',
          headers: fetchOptions.headers
        });

        // Store request for potential abort
        this.state.requests.set(nextSelect, response);

        await this.processResponse(response, nextSelect, instance);
      } else {
        // For POST, use FormData as body
        fetchOptions.body = formData;

        // Make the request
        const controller = new AbortController();
        fetchOptions.signal = controller.signal;

        // Store controller for potential abort
        this.state.requests.set(nextSelect, controller);

        const response = await fetch(url, fetchOptions);
        await this.processResponse(response, nextSelect, instance);
      }
    } catch (error) {
      // Ignore aborted requests
      if (error.name !== 'AbortError') {
        console.error('Error loading options:', error);

        // Emit error event
        this.emitEvent('cascade:error', {
          instance,
          element: nextSelect,
          error
        });
      }
    } finally {
      // Remove loading state
      instance.state.loading = false;
      nextSelect.classList.remove(config.loadingClass);
      this.state.requests.delete(nextSelect);
    }
  },

  async processResponse(response, select, instance) {
    if (!response.ok) {
      throw new Error(`HTTP error ${response.status}`);
    }

    let data;
    const contentType = response.headers.get('Content-Type');

    if (contentType && contentType.includes('application/json')) {
      data = await response.json();
    } else {
      // Try to parse as JSON anyway
      try {
        const text = await response.text();
        data = JSON.parse(text);
      } catch (e) {
        throw new Error('Invalid response format, expected JSON');
      }
    }

    // Handle different response formats
    let options = [];

    data = data?.data || data;

    if (Array.isArray(data)) {
      // Simple array of options
      options = data;
    } else if (typeof data === 'object') {
      // Check if data contains options for multiple selects
      const selectId = select.id || select.name;

      if (data[selectId]) {
        // Data contains options specifically for this select
        options = data[selectId];
      } else if (data.options) {
        // Data has an options property
        options = data.options;
      } else {
        // Assume data itself is a key-value object of options
        options = data;
      }

      // Also update other selects if data provides options for them
      if (typeof data === 'object' && !Array.isArray(data)) {
        for (const [key, value] of Object.entries(data)) {
          // Skip if this is not an array or object (likely to be options)
          if (!value || typeof value !== 'object') continue;

          // Find the select by ID or name
          const targetSelect = instance.elements.find(el =>
            el.id === key || el.name === key
          );

          // Update the select if found
          if (targetSelect && targetSelect !== select) {
            this.updateSelectOptions(targetSelect, value, instance);
          }
        }
      }
    }

    // Update options in the select
    this.updateSelectOptions(select, options, instance);

    // Enable the select
    if (instance.config.autoDisable) {
      select.disabled = false;
    }

    // Emit load event
    this.emitEvent('cascade:loaded', {
      instance,
      element: select,
      options
    });

    return options;
  },

  updateSelectOptions(select, options, instance) {
    const {config} = instance;

    // Clear existing options
    this.clearOptions(select, config);

    // Convert options to consistent format
    let optionsArray = [];

    if (Array.isArray(options)) {
      // Array format: [{value: '1', text: 'Option 1'}, ...]
      // Or simple array: ['Option 1', 'Option 2', ...]
      optionsArray = options.map(option => {
        if (typeof option === 'object') {
          return {
            value: option.value !== undefined ? option.value : option.id,
            text: option.text !== undefined ? option.text : option.name,
            selected: option.selected || false,
            disabled: option.disabled || false
          };
        } else {
          return {
            value: option,
            text: option,
            selected: false,
            disabled: false
          };
        }
      });
    } else if (typeof options === 'object') {
      // Object format: {'1': 'Option 1', '2': 'Option 2', ...}
      optionsArray = Object.entries(options).map(([value, text]) => {
        return {
          value: value,
          text: text,
          selected: false,
          disabled: false
        };
      });
    }

    // Add options to select
    optionsArray.forEach(opt => {
      const option = document.createElement('option');
      option.value = opt.value;
      option.textContent = opt.text;
      option.selected = opt.selected;
      option.disabled = opt.disabled;
      select.appendChild(option);
    });

    // If there's a stored value for this select, try to restore it
    const index = parseInt(select.dataset.cascadeIndex);
    if (instance.state.values[index]) {
      const storedValue = instance.state.values[index];

      // Check if the stored value exists in the new options
      const optionExists = Array.from(select.options).some(option =>
        option.value === storedValue
      );

      if (optionExists) {
        select.value = storedValue;

        // Trigger change to load next level
        const event = new Event('change', {bubbles: true});
        select.dispatchEvent(event);
      }
    }

    // If we have a default selection specified
    if (config.defaultSelection && config.defaultSelection[index]) {
      const defaultValue = config.defaultSelection[index];

      // Check if the default value exists in the options
      const optionExists = Array.from(select.options).some(option =>
        option.value === defaultValue
      );

      if (optionExists) {
        select.value = defaultValue;

        // Trigger change to load next level
        const event = new Event('change', {bubbles: true});
        select.dispatchEvent(event);
      }
    }
  },

  emitEvent(eventName, data) {
    EventManager.emit(eventName, data);
  },

  getInstance(id) {
    return this.state.instances.get(id) || null;
  },

  getInstanceForElement(element) {
    if (typeof element === 'string') {
      element = document.getElementById(element);
    }

    if (!element) return null;

    return element.cascadeInstance || null;
  },

  reset(instance) {
    if (typeof instance === 'string') {
      instance = this.getInstance(instance);
    }

    if (!instance) return;

    // Reset all selects to initial state
    instance.elements.forEach((select, index) => {
      // First select gets original options
      if (index === 0 && select._originalOptions) {
        // Clear current options
        select.innerHTML = '';

        // Restore original options
        select._originalOptions.forEach(opt => {
          const option = document.createElement('option');
          option.value = opt.value;
          option.textContent = opt.text;
          option.selected = opt.selected;
          option.disabled = opt.disabled;
          select.appendChild(option);
        });
      } else {
        // Other selects just get cleared
        this.clearOptions(select, instance.config);

        // Disable subsequent selects
        if (instance.config.autoDisable) {
          select.disabled = true;
        }
      }

      // Reset saved values
      instance.state.values[index] = '';
    });

    // Emit reset event
    this.emitEvent('cascade:reset', {
      instance
    });
  },

  destroy(instance) {
    if (typeof instance === 'string') {
      instance = this.getInstance(instance);
    }

    if (!instance) return;

    // Cancel any pending requests
    instance.elements.forEach(select => {
      // Remove event listener
      if (select._changeHandler) {
        select.removeEventListener('change', select._changeHandler);
      }

      // Cancel request if pending
      if (this.state.requests.has(select)) {
        const request = this.state.requests.get(select);
        if (request.abort) {
          request.abort();
        }
        this.state.requests.delete(select);
      }

      // Remove instance reference
      delete select.cascadeInstance;

      // Remove data attributes
      delete select.dataset.cascadeGroup;
      delete select.dataset.cascadeIndex;
    });

    // Remove from instances map
    this.state.instances.delete(instance.id);

    // Emit destroy event
    this.emitEvent('cascade:destroy', {
      instanceId: instance.id
    });
  }
};

// Register with Now framework if available
if (window.Now?.registerManager) {
  Now.registerManager('cascadingSelect', CascadingSelectManager);
}

window.CascadingSelectManager = CascadingSelectManager;
