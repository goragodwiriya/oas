/**
 * LineItemsManager - Event-Driven Document Line Items
 *
 * Features:
 * - Event-driven: listens for 'change' on product search input
 * - Detail API: fetches full product data when selected (supports nested data.data)
 * - Flexible API parameters: send multiple parameters (qty, warehouse, batch, etc.)
 * - Flexible columns: defined by <th data-field>
 * - Editable/readonly fields with form submission support
 * - Display-only fields (no input, just text)
 * - Custom buttons per cell (VAT calculation, etc.)
 * - Configurable delete button
 * - Auto-calculation: quantity × price = subtotal
 * - Sum to totals: configurable via data-sum-to
 * - Merge duplicates: optional
 * - External callbacks: data-on-action
 * - Load data via data-attr="data:items" (like TableManager)
 *
 * Usage:
 * <input type="text" name="product" data-role="product"
 *        data-autocomplete="true"
 *        data-source="api/products/search">
 * <input type="number" name="qty" value="1" data-role="qty">
 * <input type="number" name="warehouse" value="1" data-role="warehouse">
 *
 * <table data-line-items="items"
 *        data-detail-api="api/products/get"
 *        data-listen-select="[data-role='product']"
 *        data-api-params="[data-role]"
 *        data-allow-delete="true"
 *        data-merge="true"
 *        data-attr="data:items"
 *        data-on-action="handleAction">
 *   <thead>
 *     <tr>
 *       <th data-field="sku">SKU</th>
 *       <th data-field="name" data-type="text">Name</th>
 *       <th data-field="quantity" data-type="number"
 *           data-role="quantity" data-sum-to="#total_qty">Qty</th>
 *       <th data-field="unit_price" data-type="currency"
 *           data-role="price"
 *           data-button-click="calcTax"
 *           data-button-class="icon-plus">Price</th>
 *       <th data-field="warehouse" data-readonly="true">Warehouse</th>
 *       <th data-field="unit_name">Unit</th>
 *       <th data-field="subtotal" data-type="currency"
 *           data-role="subtotal" data-auto-calc="true"
 *           data-readonly="true"
 *           data-sum-to="#total_amount">Subtotal</th>
 *     </tr>
 *   </thead>
 *   <tbody></tbody>
 * </table>
 *
 * Column Rendering:
 * - data-type="number|text|currency|select" → Uses ElementManager to create input (editable by default)
 * - data-type + data-readonly="true" → Creates readonly input (for form submission)
 * - data-readonly="true" (without data-type) → Creates readonly text input
 * - data-display-only="true" → Displays as text only (no input, no form submission)
 * - No attributes → Displays as text only (default behavior)
 *
 * Input Attributes:
 * - data-type="number|text|currency|select" → Input type (uses ElementManager)
 * - data-min, data-max, data-step → Number/currency constraints
 * - data-maxlength, data-size → Text constraints
 *
 * API Parameters:
 * - All inputs matching data-api-params selector will be sent as parameters
 * - Parameter name is determined by: data-role > data-api-param (name attribute is NOT used)
 * - Example: [data-role="product"] sends product=xxx to API
 * - Flexible: send qty, warehouse, batch, discount, or any custom field
 * - Important: Always use data-role or data-api-param, not name attribute
 *
 * API Response:
 * - Returns {success: true, data: {...}} or {success: true, data: {data: {...}}}
 * - Supports nested data structure (data.data.data)
 * - API can return any fields it wants
 * - All returned fields will be used by LineItemsManager
 *
 * @author Goragod Wiriya <admin@goragod.com>
 * @version 1.0
 */
const LineItemsManager = {
  /**
   * Default configuration
   */
  config: {
    // Merge behavior
    mergeOnDuplicate: true,
    mergeKeyField: 'sku', // Field to identify duplicates

    // Row limits
    minRows: 0,
    maxRows: 100,

    // Delete button
    allowDelete: true,
    deleteButtonClass: 'btn btn-sm btn-danger icon-delete',
    deleteButtonTitle: 'Delete',
    deleteConfirm: false,

    // Row styling
    rowClass: 'line-item-row',

    // Field name for form array
    fieldName: 'items',

    // Auto-load from source selector (e.g., PO, Quotation, Order)
    loadFromSelect: null,      // Selector e.g., '#po_id'
    loadFromApi: null,         // API endpoint e.g., 'api/vms/orders/items'
    loadFromParam: 'id',       // Parameter name to send to API
    clearOnLoad: true,         // Clear existing items before loading
    cache: false,
    cacheTime: 60000
    ,
    // When true, after removing a row the manager will reindex remaining rows
    // so input names/ids use continuous indices (useful for servers that expect dense arrays)
    reindexOnRemove: false
  },

  /**
   * State management
   */
  state: {
    initialized: false,
    instances: new Map(),
    cache: new Map()
  },

  /**
   * Initialize manager
   */
  init(options = {}) {
    if (this.state.initialized) return this;

    Object.assign(this.config, options);

    // Setup MutationObserver to detect dynamically added tables
    this.setupDynamicObserver();

    // Scan existing tables
    this.scan();

    this.state.initialized = true;
    return this;
  },

  /**
   * Setup MutationObserver to detect dynamically added line items tables
   */
  setupDynamicObserver() {
    if (!window.MutationObserver) return;

    const observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
          if (node.nodeType === 1) {
            // Check if the node itself is a line items table
            if (node.matches && node.matches('[data-line-items]')) {
              this.create(node);
            }
            // Check for line items tables inside the node
            if (node.querySelectorAll) {
              node.querySelectorAll('[data-line-items]').forEach(table => {
                if (!this.state.instances.has(table)) {
                  this.create(table);
                }
              });
            }
          }
        });
      });
    });

    observer.observe(document.body, {
      childList: true,
      subtree: true
    });

    this._observer = observer;
  },

  /**
   * Scan container for line item components
   */
  scan(container = document) {
    const tables = container.querySelectorAll('[data-line-items]');
    tables.forEach(table => {
      if (!this.state.instances.has(table)) {
        this.create(table);
      }
    });
    return this;
  },

  /**
   * Create line items instance for a table
   */
  create(table, options = {}) {
    if (typeof table === 'string') {
      table = document.querySelector(table);
    }
    if (!table) return null;

    if (this.state.instances.has(table)) {
      return this.state.instances.get(table);
    }

    const instance = this._createInstance(table, options);
    this.state.instances.set(table, instance);
    return instance;
  },

  /**
   * Create instance object
   */
  _createInstance(table, options = {}) {
    const config = this._parseConfig(table, options);
    const form = table.closest('form');

    const instance = {
      table,
      form,
      config,
      tbody: table.querySelector('tbody'),
      thead: table.querySelector('thead'),
      columns: [],
      rows: [],
      rowIndex: 0, // For generating unique row IDs
      _destroyed: false,
      _selectRequestId: 0,
      _handlers: {},

      // Public API
      addItem: (product) => this._addItem(instance, product),
      removeRow: (index) => this._removeRow(instance, index),
      updateRow: (index, data) => this._updateRow(instance, index, data),
      getData: () => this._getData(instance),
      setData: (items) => this._setData(instance, items),
      loadFromSource: (value) => this._loadFromSource(instance, value),
      calculate: () => this._triggerCalculate(instance),
      clear: () => this._clear(instance),
      destroy: () => this._destroy(instance)
    };

    // Parse columns from thead
    instance.columns = this._parseColumns(instance);

    this._ensureDeleteColumnHeader(instance);

    // Setup event listener on product select
    this._setupSelectListener(instance);

    // Setup auto-load from source selector (e.g., PO, Quotation)
    this._setupLoadFromListener(instance);

    // Setup table events
    this._setupTableEvents(instance);

    return instance;
  },

  /**
   * Parse config from data attributes
   */
  _parseConfig(table, options) {
    const ds = table.dataset;

    return Object.assign({}, this.config, {
      // Field name for form submission
      fieldName: ds.lineItems || 'items',

      // API endpoint for fetching product details
      detailApi: ds.detailApi || null,

      // Selector for product search input to listen for changes
      listenSelect: ds.listenSelect || '[data-role="product-search"]',

      // Selector pattern for collecting API parameters from inputs
      // Can be: '[data-role]', '[data-api-param]', or specific selectors
      apiParams: ds.apiParams || '[data-role]',

      // Merge duplicates
      mergeOnDuplicate: ds.merge !== 'false',
      mergeKeyField: ds.mergeKey || 'sku',

      // Delete button
      allowDelete: ds.allowDelete !== 'false',
      deleteConfirm: ds.deleteConfirm === 'true',

      // External calculation callback (handles both calculation and button actions)
      onCalculate: ds.onCalculate ? window[ds.onCalculate] : null,

      // Auto-load from source selector
      loadFromSelect: ds.loadFrom || null,
      loadFromApi: ds.loadApi || null,
      loadFromParam: ds.loadParam || 'id',
      clearOnLoad: ds.loadClear !== 'false',
      cache: ds.cache === 'true',
      cacheTime: ds.cacheTime !== undefined ? parseInt(ds.cacheTime, 10) : this.config.cacheTime
      ,
      // Optional: reindex inputs after a remove to ensure dense array indices
      reindexOnRemove: ds.reindexOnRemove === 'true'
    }, options);
  },

  /**
   * Parse column definitions from thead
   */
  _parseColumns(instance) {
    const columns = [];
    const headers = instance.thead?.querySelectorAll('th[data-field]') || [];

    headers.forEach((th, index) => {
      const field = th.dataset.field;
      if (!field) return;

      let visible = true;
      if (th.dataset.visible !== undefined) {
        visible = th.dataset.visible !== 'false';
      } else if (th.dataset.hidden !== undefined) {
        visible = th.dataset.hidden !== 'true';
      }

      if (!visible) {
        th.style.display = 'none';
      }

      columns.push({
        index,
        field,
        label: th.textContent.trim(),
        readonly: th.dataset.readonly === 'true',
        displayOnly: th.dataset.displayOnly === 'true',
        hidden: !visible,
        type: th.dataset.type || null, // number, text, currency, select, etc.
        min: th.dataset.min !== undefined ? parseFloat(th.dataset.min) : undefined,
        max: th.dataset.max !== undefined ? parseFloat(th.dataset.max) : undefined,
        step: th.dataset.step !== undefined ? parseFloat(th.dataset.step) : undefined,
        precision: th.dataset.precision !== undefined ? parseInt(th.dataset.precision, 10) : undefined,
        decimals: th.dataset.decimals !== undefined ? parseInt(th.dataset.decimals, 10) : undefined,
        maxlength: th.dataset.maxlength !== undefined ? parseInt(th.dataset.maxlength) : undefined,
        size: th.dataset.size !== undefined ? parseInt(th.dataset.size) : undefined,
        className: th.dataset.cellClass || '',
        // Custom button in cell
        buttonClick: th.dataset.buttonClick || null,
        buttonClass: th.dataset.buttonClass || '',
        buttonText: th.dataset.buttonText || '',
        buttonTitle: th.dataset.buttonTitle || ''
      });
    });

    // Add delete column if needed
    if (instance.config.allowDelete && !columns.find(c => c.field === '_delete')) {
      columns.push({
        index: columns.length,
        field: '_delete',
        label: '',
        type: 'action',
        isDeleteColumn: true
      });
    }

    return columns;
  },

  /**
   * Ensure delete column header exists
   */
  _ensureDeleteColumnHeader(instance) {
    const {thead, columns} = instance;
    if (!thead) return;

    const deleteCol = columns.find(c => c.isDeleteColumn);
    if (!deleteCol) return;

    const headerRow = thead.querySelector('tr');
    if (!headerRow) return;

    const existingHeaders = headerRow.querySelectorAll('th');
    const hasDeleteHeader = Array.from(existingHeaders).some(th =>
      th.dataset.field === '_delete' || th.classList.contains('delete-column')
    );

    if (!hasDeleteHeader) {
      const th = document.createElement('th');
      th.dataset.field = '_delete';
      th.className = 'delete-column center';
      th.style.width = '50px';
      headerRow.appendChild(th);
    }
  },

  /**
   * Setup event listener on product search input
   */
  _setupSelectListener(instance) {
    const {config, form} = instance;

    // Find the product search input
    const container = form || document;
    const searchInput = container.querySelector(config.listenSelect);

    if (!searchInput) {
      console.warn('[LineItemsManager] Could not find select input:', config.listenSelect);
      return;
    }

    instance.searchInput = searchInput;

    // Find all inputs that match the apiParams selector
    instance.apiInputs = container.querySelectorAll(config.apiParams);

    // Listen for 'change' event on the search input
    instance._handlers.selectChange = async (e) => {
      if (instance._destroyed || !instance.table?.isConnected) {
        return;
      }

      const value = this._getInputValue(searchInput);
      if (!value) {
        return;
      }

      const requestId = ++instance._selectRequestId;

      // Collect all API parameters from matching inputs
      const apiData = this._collectApiParams(instance);

      await this._handleProductSelect(instance, value, apiData, requestId);

      if (instance._destroyed || instance._selectRequestId !== requestId) {
        return;
      }

      // Reset inputs after adding
      this._resetInput(searchInput);
    };

    searchInput.addEventListener('change', instance._handlers.selectChange);

    // Optional: Listen for Enter key on search input
    instance._handlers.searchKeydown = (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        e.stopPropagation();
        // Trigger change manually if there's a value
        const value = this._getInputValue(searchInput);
        if (value) {
          instance._handlers.selectChange(e);
        }
      }
    };

    searchInput.addEventListener('keydown', instance._handlers.searchKeydown);
  },

  /**
   * Setup listener for auto-loading items from a source selector
   * When the source select changes (e.g., PO selection), load items from API
   */
  _setupLoadFromListener(instance) {
    const {config, form} = instance;

    // Skip if not configured
    if (!config.loadFromSelect) return;

    const container = form || document;
    const sourceSelect = container.querySelector(config.loadFromSelect);

    if (!sourceSelect) {
      console.warn('[LineItemsManager] Load source not found:', config.loadFromSelect);
      return;
    }

    instance.sourceSelect = sourceSelect;

    // Handler for source change
    instance._handlers.sourceChange = async (e) => {
      const value = sourceSelect.value;

      if (!value) {
        // Clear items when source is deselected
        if (config.clearOnLoad) {
          this._clear(instance);
        }
        return;
      }

      await this._loadFromSource(instance, value);
    };

    sourceSelect.addEventListener('change', instance._handlers.sourceChange);

    // Also listen for cascade:loaded event (when CascadingSelectManager populates the select)
    sourceSelect.addEventListener('cascade:loaded', () => {
      // Auto-trigger if there's a pre-selected value after cascade load
      if (sourceSelect.value) {
        instance._handlers.sourceChange();
      }
    });
  },

  /**
   * Load items from source API
   * @param {Object} instance - LineItemsManager instance
   * @param {string|number} value - Source ID (e.g., order_id)
   */
  async _loadFromSource(instance, value) {
    const {config} = instance;

    if (!config.loadFromApi) {
      console.warn('[LineItemsManager] loadFromApi not configured');
      return;
    }

    try {
      // Build URL with parameter
      let url = config.loadFromApi;
      const params = new URLSearchParams();
      params.append(config.loadFromParam, value);
      url += (url.includes('?') ? '&' : '?') + params.toString();

      let items = [];
      const responseData = await this._fetchJson(url, config);

      // Handle various response formats
      items = responseData?.items || responseData || [];

      // Ensure items is an array
      if (!Array.isArray(items)) {
        console.warn('[LineItemsManager] Invalid items response:', items);
        items = [];
      }

      // Clear existing items if configured
      if (config.clearOnLoad) {
        this._clear(instance);
      }

      // Add each item
      items.forEach(item => this._addItem(instance, item));

      // Emit loaded event
      this._emit(instance, 'sourceLoaded', {source: value, items, count: items.length});

    } catch (err) {
      console.error('[LineItemsManager] Load from source failed:', err);
      this._emit(instance, 'sourceError', {source: value, error: err});
    }
  },

  /**
   * Collect API parameters from inputs matching the selector
   * Returns an object with parameter names and values
   */
  _collectApiParams(instance) {
    const {config} = instance;
    const params = {};
    const container = instance.form || document;

    // Find all inputs matching the selector
    const inputs = container.querySelectorAll(config.apiParams);

    inputs.forEach(input => {
      // Get parameter name from data-role or data-api-param only
      // Do not use 'name' attribute as it may be transformed by frameworks
      const paramName = input.dataset.role || input.dataset.apiParam;

      if (paramName) {
        const value = this._getInputValue(input);
        if (value !== null && value !== undefined && value !== '') {
          params[paramName] = value;
        }
      }
    });

    return params;
  },

  /**
   * Get value from input (handles hidden input for autocomplete)
   */
  _getInputValue(input) {
    // Check for hidden input (TextElementFactory creates one)
    const form = input.closest('form') || input.parentNode;
    const originalName = input.name?.replace('_text', '') || input.name;
    const hiddenInput = form?.querySelector(`input[type="hidden"][name="${originalName}"]`);

    return hiddenInput?.value || input.value;
  },

  /**
   * Reset input after selection
   */
  _resetInput(input) {
    input.value = '';

    // Also reset hidden input if exists
    const form = input.closest('form') || input.parentNode;
    const originalName = input.name?.replace('_text', '') || input.name;
    const hiddenInput = form?.querySelector(`input[type="hidden"][name="${originalName}"]`);

    if (hiddenInput) hiddenInput.value = '';
  },

  /**
   * Handle product selection
   */
  async _handleProductSelect(instance, value, apiData, requestId = null) {
    if (instance._destroyed || !instance.table?.isConnected) {
      return;
    }

    const {config} = instance;

    let product;

    if (config.detailApi) {
      // Fetch full product details from API
      product = await this._fetchProductDetail(instance, value, apiData);
    } else {
      // Use apiData as-is
      product = {[config.mergeKeyField]: value, ...apiData};
    }

    if (
      product &&
      !instance._destroyed &&
      instance.table?.isConnected &&
      (requestId === null || instance._selectRequestId === requestId)
    ) {
      this._addItem(instance, product);
    }
  },

  /**
   * Fetch product details from API
   */
  async _fetchProductDetail(instance, value, apiData) {
    const {config} = instance;

    try {
      // Build URL with parameters
      let url = config.detailApi;
      const params = new URLSearchParams();

      // Add the main value with the parameter name from listenSelect
      // Use data-role or data-api-param only, do not use name attribute
      const searchInput = instance.searchInput;
      const mainParamName = searchInput?.dataset.role ||
        searchInput?.dataset.apiParam ||
        'value';
      params.append(mainParamName, value);

      // Add all additional parameters from apiData
      Object.entries(apiData).forEach(([key, val]) => {
        // Skip if it's the same as mainParamName to avoid duplication
        if (key !== mainParamName) {
          params.append(key, val);
        }
      });

      // Add separator
      url += (url.includes('?') ? '&' : '?') + params.toString();

      const responseData = await this._fetchJson(url, config);
      return responseData?.data || responseData || null;
    } catch (err) {
      console.error('[LineItemsManager] Failed to fetch product detail:', err);
      return null;
    }
  },

  normalizeCacheTime(value, fallback = 60000) {
    const parsed = parseInt(value, 10);
    return Number.isFinite(parsed) && parsed >= 0 ? parsed : fallback;
  },

  createCacheKey(url) {
    return String(url || '');
  },

  getCachedResponse(cacheKey) {
    const cached = this.state.cache.get(cacheKey);
    if (!cached) return null;
    if (cached.expiresAt <= Date.now()) {
      this.state.cache.delete(cacheKey);
      return null;
    }
    return cached.data;
  },

  setCachedResponse(cacheKey, data, cacheTime) {
    this.state.cache.set(cacheKey, {
      data,
      timestamp: Date.now(),
      expiresAt: Date.now() + this.normalizeCacheTime(cacheTime, this.config.cacheTime)
    });
  },

  async _fetchJson(url, config = {}, requestOptions = {}) {
    const useCache = config.cache === true && requestOptions.force !== true;
    const cacheKey = this.createCacheKey(url);

    if (useCache) {
      const cachedData = this.getCachedResponse(cacheKey);
      if (cachedData) {
        return cachedData;
      }
    }

    let responseData = null;
    if (window.http && typeof window.http.get === 'function') {
      const resp = await window.http.get(url, {
        throwOnError: false,
        cache: useCache ? 'default' : 'no-store',
        headers: useCache ? {} : {
          'Cache-Control': 'no-cache',
          'Pragma': 'no-cache'
        }
      });
      responseData = resp?.data?.data || resp?.data;
      if (resp?.success === false) {
        return null;
      }
    } else {
      const requestOptions = Now.applyRequestLanguage({
        cache: useCache ? 'default' : 'no-store',
        headers: useCache ? {} : {
          'Cache-Control': 'no-cache',
          'Pragma': 'no-cache'
        }
      });
      const resp = await fetch(url, requestOptions);
      const jsonData = await resp.json();
      responseData = jsonData?.data?.data || jsonData?.data;
      if (jsonData?.success === false) {
        return null;
      }
    }

    if (useCache && responseData !== null && responseData !== undefined) {
      this.setCachedResponse(cacheKey, responseData, config.cacheTime);
    }

    return responseData;
  },

  /**
   * Setup table events (edit, delete, custom actions)
   */
  _setupTableEvents(instance) {
    const {tbody} = instance;
    if (!tbody) return;

    // Handle input changes
    instance._handlers.change = (e) => {
      const input = e.target;
      const row = input.closest('tr');
      if (!row) return;

      let field = input.dataset.field;
      if (!field && input.name) {
        const match = input.name.match(/\[(\w+)\]$/);
        if (match) field = match[1];
      }

      const rowIndex = parseInt(row.dataset.rowIndex);

      if (field && !isNaN(rowIndex)) {
        this._handleFieldChange(instance, rowIndex, field, this._parseValue(input.value));
      }
    };

    // Handle button clicks (delete, custom actions)
    instance._handlers.click = (e) => {
      const button = e.target.closest('button[data-action]');
      if (!button) return;

      e.preventDefault();

      const action = button.dataset.action;
      const row = button.closest('tr');
      const rowIndex = parseInt(row?.dataset.rowIndex);

      if (action === 'delete') {
        this._removeRow(instance, rowIndex);
      } else {
        this._handleCustomAction(instance, action, rowIndex, button);
      }
    };

    tbody.addEventListener('change', instance._handlers.change);
    tbody.addEventListener('input', instance._handlers.change);
    tbody.addEventListener('click', instance._handlers.click);
  },

  /**
   * Handle field change in row
   */
  _handleFieldChange(instance, rowIndex, field, value) {
    const row = instance.rows.find(r => r.index === rowIndex);
    if (!row) return;

    // Update data
    row.data[field] = value;

    // Trigger external calculation
    this._triggerCalculate(instance);
  },

  /**
   * Trigger external calculation callback
   * Collects all items from DOM, calls onCalculate, updates UI
   */
  _triggerCalculate(instance) {
    const {config, columns, rows} = instance;

    if (typeof config.onCalculate !== 'function') {
      return; // No calculation without callback
    }

    // Collect all items data from DOM
    const items = rows.map(row => {
      const data = {};
      columns.forEach(col => {
        if (!col.isDeleteColumn) {
          data[col.field] = this._getCellValue(row.element, col.field);
        }
      });
      return data;
    });

    try {
      const result = config.onCalculate({items, instance});

      if (result && typeof result === 'object') {
        // Update rows
        if (Array.isArray(result.items)) {
          result.items.forEach((rowUpdates, index) => {
            if (rows[index] && rowUpdates) {
              Object.entries(rowUpdates).forEach(([field, value]) => {
                rows[index].data[field] = value;
                this._updateCell(rows[index].element, field, value);
              });
            }
          });
        }

        // Update totals (selectors)
        Object.entries(result).forEach(([key, value]) => {
          if (key !== 'items' && key.startsWith('#')) {
            const el = document.querySelector(key);
            if (el) {
              if (el.tagName === 'INPUT' || el.tagName === 'SELECT' || el.tagName === 'TEXTAREA') {
                this._setElementValue(el, value);
              } else {
                el.textContent = value;
              }
            }
          }
        });
      }
    } catch (err) {
      console.error('[LineItemsManager] onCalculate error:', err);
    }

    this._emit(instance, 'calculate', {rows: rows.length});
  },

  /**
   * Handle custom action (button click inside cell)
   * 1. Call action function with current value → get new value
   * 2. Update cell
   * 3. Trigger calculateItems for subtotals/totals
   */
  _handleCustomAction(instance, action, rowIndex, button) {
    const row = instance.rows.find(r => r.index === rowIndex);
    if (!row) return;

    // Find the field this button belongs to
    const cell = button.closest('td');
    const input = cell?.querySelector('input, select');
    const field = input?.dataset.field || this._getFieldFromName(input?.name);

    if (!field) {
      console.warn('[LineItemsManager] Cannot find field for button action:', action);
      return;
    }

    // Get current value
    const currentValue = this._getCellValue(row.element, field);

    // Call the action function if exists
    const actionFn = typeof action === 'function' ? action : window[action];
    if (typeof actionFn === 'function') {
      const newValue = actionFn(currentValue, button, row.data, instance);

      // If action returns a value, update the cell
      if (newValue !== undefined && newValue !== null) {
        row.data[field] = newValue;
        this._updateCell(row.element, field, newValue);
      }
    }

    // After action, trigger calculation for subtotals/totals
    this._triggerCalculate(instance);

    // Emit event for listeners
    this._emit(instance, 'action', {action, field, rowIndex, rowData: row.data, button});
  },

  /**
   * Extract field name from input name like "items[0][field_name]"
   */
  _getFieldFromName(name) {
    if (!name) return null;
    const match = name.match(/\[(\w+)\]$/);
    return match ? match[1] : null;
  },

  /**
   * Add item to table
   */
  _addItem(instance, product) {
    const {config, rows, tbody, columns} = instance;

    // Check max rows
    if (rows.length >= config.maxRows) {
      console.warn('[LineItemsManager] Max rows reached:', config.maxRows);
      return null;
    }

    // Check for duplicate
    if (config.mergeOnDuplicate) {
      const keyField = config.mergeKeyField;
      const keyValue = product[keyField];
      const existing = rows.find(r => r.data[keyField] === keyValue);

      if (existing) {
        return this._mergeItem(instance, existing, product);
      }
    }

    // Create row
    const rowIndex = instance.rowIndex++;
    const tr = document.createElement('tr');
    tr.className = config.rowClass;
    tr.dataset.rowIndex = rowIndex;

    const rowData = {...product};

    // Create cells
    columns.forEach(col => {
      const td = document.createElement('td');
      td.className = col.className;

      if (col.isDeleteColumn) {
        // Delete button
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = config.deleteButtonClass;
        btn.title = config.deleteButtonTitle;
        btn.dataset.action = 'delete';
        td.appendChild(btn);
      } else if (col.hidden) {
        // Hidden input
        const input = this._createInput(instance, col, rowData, rowIndex);
        input.type = 'hidden';
        td.appendChild(input);
        td.style.display = 'none';
      } else if (col.displayOnly) {
        td.textContent = rowData[col.field] || '';
        td.dataset.field = col.field;
      } else if (col.type) {
        let container;
        if (col.buttonClick) {
          const label = document.createElement('label');
          label.className = 'has-button';
          td.appendChild(label);
          container = label;
        } else {
          container = td;
        }
        // Has data-type → use ElementManager to create input
        const element = this._createElement(instance, col, rowData, rowIndex);
        if (element) {
          if (element.wrapper) {
            container.appendChild(element.wrapper);
          } else if (element.element) {
            // Add custom button if configured
            container.appendChild(element.element);
          }
        } else {
          // Fallback if ElementManager not available
          const input = this._createInput(instance, col, rowData, rowIndex);
          if (col.readonly) {
            input.readOnly = true;
          }
          container.appendChild(input);
        }
        if (col.buttonClick) {
          const btn = this._createCellButton(col);
          container.appendChild(btn);
        }
      } else if (col.readonly) {
        // Only data-readonly (no data-type) → readonly input for form submission
        const input = this._createInput(instance, col, rowData, rowIndex);
        input.readOnly = true;
        td.appendChild(input);
      } else {
        td.textContent = rowData[col.field] || '';
        td.dataset.field = col.field;
      }

      tr.appendChild(td);
    });

    // Add to DOM and state
    tbody.appendChild(tr);

    const row = {index: rowIndex, element: tr, data: rowData};
    rows.push(row);

    // Trigger calculation
    this._triggerCalculate(instance);

    this._emit(instance, 'add', {row: rowData, rowIndex});

    return row;
  },

  /**
   * Create input element (fallback when ElementManager unavailable)
   */
  _createInput(instance, col, rowData, rowIndex) {
    const {config} = instance;
    const input = document.createElement('input');
    const value = rowData[col.field] !== undefined ? rowData[col.field] : '';

    let inputType = 'text';
    if (col.type === 'number' || col.type === 'currency') {
      inputType = 'number';
    } else if (col.type === 'checkbox') {
      inputType = 'checkbox';
    }

    input.type = inputType;
    input.name = `${config.fieldName}[${rowIndex}][${col.field}]`;
    input.id = `${config.fieldName}_${col.field}_${rowIndex}`;
    input.dataset.field = col.field;
    input.className = 'form-input';

    if (col.type === 'checkbox') {
      input.checked = value === true || value === 'true' || value === '1' || value === 1;
      input.value = '1';
    } else {
      input.value = value;
    }

    if (col.min !== undefined) input.min = col.min;
    if (col.max !== undefined) input.max = col.max;
    if (col.step !== undefined) input.step = col.step;
    if (col.size !== undefined) input.size = col.size;

    if ((col.type === 'number' || col.type === 'currency') && col.step === undefined) {
      const precision = Number.isInteger(col.precision) ? col.precision : (Number.isInteger(col.decimals) ? col.decimals : null);
      input.step = precision !== null && precision > 0 ? (10 ** -precision).toFixed(precision) : (col.type === 'currency' ? '0.01' : '1');
    }

    return input;
  },

  /**
   * Create cell button
   */
  _createCellButton(col) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = col.buttonClass || 'btn btn-sm';
    btn.dataset.action = col.buttonClick;
    if (col.buttonText) btn.textContent = col.buttonText;
    if (col.buttonTitle) btn.title = col.buttonTitle;
    return btn;
  },

  /**
   * Create element using ElementManager (like TableManager)
   */
  _createElement(instance, col, rowData, rowIndex) {
    const elementManager = window.Now?.getManager ? Now.getManager('element') : null;
    if (!elementManager || !elementManager.state?.initialized) return null;

    const {config} = instance;
    const value = rowData[col.field] !== undefined ? rowData[col.field] : '';

    const elementConfig = {
      type: col.type,
      name: `${config.fieldName}[${rowIndex}][${col.field}]`,
      id: `${config.fieldName}_${col.field}_${rowIndex}`,
      value: value,
      className: col.className || 'form-input',
      readOnly: col.readonly,
      required: col.required,
      placeholder: col.placeholder,
      autocomplete: 'off'
    };

    // Type-specific configurations
    switch (col.type) {
      case 'number':
      case 'currency':
        if (col.min !== undefined) elementConfig.min = col.min;
        if (col.max !== undefined) elementConfig.max = col.max;
        if (col.step !== undefined) elementConfig.step = col.step;
        if (Number.isInteger(col.precision)) {
          elementConfig.precision = col.precision;
        } else if (Number.isInteger(col.decimals)) {
          elementConfig.precision = col.decimals;
        }
        if (col.size !== undefined) elementConfig.size = col.size;
        break;

      case 'text':
        if (col.maxlength !== undefined) elementConfig.maxLength = col.maxlength;
        if (col.size !== undefined) elementConfig.size = col.size;
        break;

      case 'select':
        elementConfig.options = col.options || {};
        elementConfig.multiple = col.multiple;
        break;

      case 'checkbox':
        // For checkbox, value determines checked state
        elementConfig.checked = value === true || value === 'true' || value === '1' || value === 1;
        elementConfig.value = '1'; // Value when checked
        break;
    }

    try {
      return elementManager.create(col.type, elementConfig);
    } catch (error) {
      console.error('[LineItemsManager] Failed to create element:', error);
      return null;
    }
  },

  /**
   * Merge item into existing row
   */
  _mergeItem(instance, existingRow, product) {
    // Find quantity field and add
    const qtyFields = ['quantity', 'qty', 'received_qty', 'issued_qty'];
    for (const qtyField of qtyFields) {
      if (product[qtyField] !== undefined && existingRow.data[qtyField] !== undefined) {
        const currentQty = parseFloat(existingRow.data[qtyField]) || 0;
        const newQty = parseFloat(product[qtyField]) || 0;
        existingRow.data[qtyField] = currentQty + newQty;
        this._updateCell(existingRow.element, qtyField, existingRow.data[qtyField]);
        break;
      }
    }

    this._triggerCalculate(instance);
    this._emit(instance, 'merge', {row: existingRow.data, rowIndex: existingRow.index});

    return existingRow;
  },

  /**
   * Check whether an element is currently being edited.
   */
  _isElementFocused(element) {
    if (!element || typeof document === 'undefined') {
      return false;
    }

    const activeElement = document.activeElement;
    return activeElement === element || Boolean(element.contains && element.contains(activeElement));
  },

  /**
   * Set value without clobbering the field currently being edited.
   */
  _setElementValue(element, value) {
    if (!element || this._isElementFocused(element)) {
      return;
    }

    const elementManager = window.ElementManager;
    const instance = elementManager?.getInstanceByElement?.(element);
    if (instance?.setValue) {
      instance.setValue(value);
      return;
    }

    element.value = value ?? '';
  },

  /**
   * Update cell value
   */
  _updateCell(tr, field, value) {
    let cell = tr.querySelector(`[data-field="${field}"]`);
    if (!cell) {
      cell = tr.querySelector(`input[name*="[${field}]"]`);
    }
    if (!cell) return;

    if (cell.tagName === 'INPUT' || cell.tagName === 'SELECT' || cell.tagName === 'TEXTAREA') {
      this._setElementValue(cell, value);
    } else if (cell.tagName === 'TD') {
      const input = cell.querySelector('input, select, textarea');
      if (input) {
        this._setElementValue(input, value);
      } else {
        cell.textContent = value;
      }
    }
  },

  /**
   * Reindex rows and update input names/ids to use continuous indices
   * This helps server-side frameworks that expect arrays without gaps
   */
  _reindexRows(instance) {
    const {config, rows} = instance;

    rows.forEach((r, newIndex) => {
      const tr = r.element;
      const oldIndex = r.index;
      // update stored index
      r.index = newIndex;
      // update DOM row index
      if (tr && tr.dataset) tr.dataset.rowIndex = newIndex;

      // Update inputs/selects/textareas inside the row
      tr.querySelectorAll('input, select, textarea').forEach((input) => {
        // Determine field name
        const field = input.dataset.field || this._getFieldFromName(input.name);

        if (field) {
          input.name = `${config.fieldName}[${newIndex}][${field}]`;
          input.id = `${config.fieldName}_${field}_${newIndex}`;
          input.dataset.field = field;
        } else {
          // Fallback: replace first numeric index occurrence
          if (input.name) {
            input.name = input.name.replace(/\[\d+\]/, `[${newIndex}]`);
          }
          if (input.id) {
            input.id = input.id.replace(/_(\d+)$/, `_${newIndex}`);
          }
        }
      });

      // Update labels for attributes
      tr.querySelectorAll('label[for]').forEach(label => {
        const forAttr = label.getAttribute('for');
        if (forAttr) {
          label.setAttribute('for', forAttr.replace(/_(\d+)$/, `_${newIndex}`));
        }
      });
    });

    // reset rowIndex counter
    instance.rowIndex = rows.length;
  },

  /**
   * Get cell value from DOM
   */
  _getCellValue(tr, field) {
    let input = tr.querySelector(`input[data-field="${field}"]`);
    if (!input) input = tr.querySelector(`input[name*="[${field}]"]`);
    if (!input) input = tr.querySelector(`select[name*="[${field}]"]`);

    if (!input) {
      const td = tr.querySelector(`td[data-field="${field}"]`);
      if (td) {
        input = td.querySelector('input, select');
        if (!input) {
          return td.textContent.trim();
        }
      }
    }

    if (input) {
      // Handle checkbox - return checked state
      if (input.type === 'checkbox') {
        return input.checked;
      }
      return input.value;
    }

    return '';
  },

  /**
   * Parse value (remove commas for numbers)
   */
  _parseValue(value) {
    if (typeof value === 'string') {
      const cleanValue = value.replace(/,/g, '');
      const num = parseFloat(cleanValue);
      return isNaN(num) ? value : num;
    }
    return value;
  },

  /**
   * Update row data
   */
  _updateRow(instance, rowIndex, newData) {
    const row = instance.rows.find(r => r.index === rowIndex);
    if (!row) return;

    Object.assign(row.data, newData);

    Object.keys(newData).forEach(field => {
      this._updateCell(row.element, field, newData[field]);
    });

    this._triggerCalculate(instance);
    this._emit(instance, 'update', {row: row.data, rowIndex});
  },

  /**
   * Remove row
   */
  _removeRow(instance, rowIndex) {
    const {config, rows} = instance;
    const rowIdx = rows.findIndex(r => r.index === rowIndex);

    if (rowIdx === -1) return false;
    if (rows.length <= config.minRows) return false;

    const row = rows[rowIdx];

    if (config.deleteConfirm && !confirm('Delete this item?')) {
      return false;
    }

    row.element.remove();
    rows.splice(rowIdx, 1);

    // Optionally reindex remaining rows to produce dense indices for form names
    if (config.reindexOnRemove) {
      try {
        this._reindexRows(instance);
      } catch (err) {
        console.error('[LineItemsManager] Reindex on remove failed:', err);
      }
    }

    this._triggerCalculate(instance);
    this._emit(instance, 'remove', {rowIndex});

    return true;
  },

  /**
   * Get all data
   */
  _getData(instance) {
    return instance.rows.map(row => ({...row.data}));
  },

  /**
   * Set data (clear and add items)
   */
  _setData(instance, items) {
    this._clear(instance);

    if (Array.isArray(items)) {
      items.forEach(item => this._addItem(instance, item));
    }
  },

  /**
   * Clear all rows
   */
  _clear(instance) {
    instance.rows.forEach(row => row.element.remove());
    instance.rows = [];
    instance.rowIndex = 0;
    this._triggerCalculate(instance);
    this._emit(instance, 'clear', {});
  },

  /**
   * Destroy instance
   */
  _destroy(instance) {
    const {tbody, searchInput} = instance;

    instance._destroyed = true;

    if (tbody) {
      if (instance._handlers.change) {
        tbody.removeEventListener('change', instance._handlers.change);
        tbody.removeEventListener('input', instance._handlers.change);
      }
      if (instance._handlers.click) {
        tbody.removeEventListener('click', instance._handlers.click);
      }
    }

    if (searchInput) {
      if (instance._handlers.selectChange) {
        searchInput.removeEventListener('change', instance._handlers.selectChange);
      }
      if (instance._handlers.searchKeydown) {
        searchInput.removeEventListener('keydown', instance._handlers.searchKeydown);
      }
    }

    // Cleanup source select listener
    if (instance.sourceSelect && instance._handlers.sourceChange) {
      instance.sourceSelect.removeEventListener('change', instance._handlers.sourceChange);
    }

    delete instance.table._lineItemsInstance;
    this._clear(instance);
    this.state.instances.delete(instance.table);
  },

  /**
   * Emit custom event
   */
  _emit(instance, eventName, detail) {
    const event = new CustomEvent(`lineitems:${eventName}`, {
      bubbles: true,
      detail: {...detail, instance}
    });
    instance.table.dispatchEvent(event);
  },
  /**
   * Get instance by table
   */
  getInstance(table) {
    if (typeof table === 'string') {
      table = document.querySelector(table);
    }
    return this.state.instances.get(table);
  },

  /**
   * Public method to trigger recalculation from external events
   * Can be called as LineItemsManager.recalculate() from data-on="input:LineItemsManager.recalculate"
   *
   * @param {HTMLElement|Event} elementOrEvent - Element inside form, Event object, or null for all instances
   */
  recalculate(elementOrEvent = null) {
    // Handle different input types
    let element = null;

    if (elementOrEvent) {
      // TemplateManager custom event format: {type, target, originalEvent, ...}
      if (elementOrEvent.originalEvent && elementOrEvent.originalEvent.target) {
        element = elementOrEvent.originalEvent.target;
      }
      // Native Event object
      else if (elementOrEvent instanceof Event) {
        element = elementOrEvent.target;
      }
      // Direct HTMLElement
      else if (elementOrEvent instanceof HTMLElement) {
        element = elementOrEvent;
      }
    }

    if (element) {
      // Find form context
      const form = element.closest('form');

      if (form) {
        // Find LineItemsManager instance within this form
        for (const [table, instance] of this.state.instances) {
          if (form.contains(table)) {
            this._triggerCalculate(instance);
            return;
          }
        }
      }
    }

    // Fallback: recalculate all instances
    for (const [, instance] of this.state.instances) {
      this._triggerCalculate(instance);
    }
  }
};

// Auto-initialize
if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => LineItemsManager.init());
  } else {
    LineItemsManager.init();
  }
}

// Expose globally
window.LineItemsManager = LineItemsManager;
