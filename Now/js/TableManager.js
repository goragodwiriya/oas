const TableManager = {
  config: {
    urlParams: true, // Enable URL parameter persistence
    pageSizes: [10, 25, 50, 100],
    showCaption: true,
    showCheckbox: false,
    checkboxCondition: '',
    showFooter: false,
    footerAggregates: {}, // e.g. {price: 'sum', quantity: 'sum', rating: 'avg'}
    searchColumns: [],
    persistColumnWidths: true,
    rowSortable: false,
    allowRowModification: false,
    confirmDelete: true,
    dynamicColumns: false, // Enable dynamic column generation from API response
    source: '',
    loadTarget: '',
    method: 'GET', // HTTP method for API calls (GET or POST)
    cache: false,
    cacheTime: 60000,
    actions: {}, // e.g. {delete:"Delete",activate:"Activate"}
    actionUrl: '',
    actionButton: 'Process',
    actionParams: {}, // e.g. {"module_id": 5} or resolved from URL keys via data-action-params="module_id,other_key"
    rowActions: {}, // e.g. {print:"Print",edit:{label:"Edit",submenu:{inline:"Inline Edit",page:"Open Editor"}},delete:"Delete"}
    params: {
      search: '',
      pageSize: 0,
      page: 1,
    }
  },

  state: {
    initialized: false,
    tables: new Map()
  },

  hasDeclarativeDataBinding(table) {
    if (!table?.dataset) return false;

    if (table.dataset.bind) {
      return true;
    }

    const attr = table.dataset.attr || '';
    return attr
      .split(',')
      .map(binding => binding.trim())
      .some(binding => binding.startsWith('data:'));
  },

  isElementManagerReady() {
    const manager = window.Now?.getManager ? Now.getManager('element') : window.ElementManager;
    return Boolean(manager?.state?.initialized);
  },

  deferRenderUntilReady(tableId, reason = 'element-manager-not-ready') {
    const table = this.state.tables.get(tableId);
    if (!table) return false;

    table._renderRetryCount = (table._renderRetryCount || 0) + 1;
    if (table._renderRetryCount > 40) {
      return false;
    }

    if (table._renderRetryTimer) {
      clearTimeout(table._renderRetryTimer);
    }

    table._renderRetryTimer = setTimeout(() => {
      const current = this.state.tables.get(tableId);
      if (!current) return;
      current._renderRetryTimer = null;
      this.renderTable(tableId);
    }, 50);

    return true;
  },

  async init(options = {}) {
    if (this.state.initialized) return this;

    this.config = {...this.config, ...options};

    // Use CoreObserver if available, otherwise fall back to own observer
    if (window.CoreObserver) {
      this.setupCoreObserverHandlers();
    } else {
      this.setupDynamicTableObserver();
    }

    document.querySelectorAll('table[data-table]').forEach(table => {
      this.initTable(table);
    });

    try {
      EventManager.on('locale:changed', (data) => {
        this.state.tables.forEach((table, tableId) => {
          this.retranslateFilter(table);
          this.renderTable(tableId);
        });
      });
    } catch (e) {
      // ignore listener setup errors
    }

    this.state.initialized = true;
    return this;
  },

  /**
   * URL Parameter Management for State Persistence
   */
  getUrlParams(tableId) {
    const table = this.state.tables.get(tableId);
    const urlParamsEnabled = table?.config?.urlParams !== undefined ? table.config.urlParams : this.config.urlParams;

    if (!urlParamsEnabled) return {};

    // Get URL parameters from current location, supporting both history and hash modes
    let searchParams = '';
    const currentUrl = window.location.href;

    if (currentUrl.includes('#')) {
      // Hash mode: extract query params from hash fragment
      const hashPart = window.location.hash;
      const queryIndex = hashPart.indexOf('?');
      if (queryIndex !== -1) {
        searchParams = hashPart.substring(queryIndex + 1);
      }
    } else {
      // History mode: use standard search params
      searchParams = window.location.search.substring(1);
    }

    const urlParams = new URLSearchParams(searchParams);
    const params = {};

    // Parse URL parameters (simple format without table prefix)
    for (const [key, value] of urlParams.entries()) {
      // Parse numeric parameters
      if (key === 'page' || key === 'pageSize') {
        params[key] = parseInt(value) || (key === 'page' ? 1 : 10);
      }
      // Handle sort parameter in compact format (e.g., 'name asc,status desc')
      else if (key === 'sort') {
        params[key] = value;
      }
      // Handle other filter parameters
      else {
        params[key] = value;
      }
    }

    return params;
  },

  updateUrlParams(tableId, newParams = {}) {
    const table = this.state.tables.get(tableId);
    const urlParamsEnabled = table?.config?.urlParams !== undefined ? table.config.urlParams : this.config.urlParams;

    if (!urlParamsEnabled) return;

    // Get current URL parameters, supporting both history and hash modes
    let searchParams = '';
    const currentUrl = window.location.href;
    const isHashMode = currentUrl.includes('#');

    if (isHashMode) {
      // Hash mode: extract query params from hash fragment
      const hashPart = window.location.hash;
      const queryIndex = hashPart.indexOf('?');
      if (queryIndex !== -1) {
        searchParams = hashPart.substring(queryIndex + 1);
      }
    } else {
      // History mode: use standard search params
      searchParams = window.location.search.substring(1);
    }

    const urlParams = new URLSearchParams(searchParams);

    // Clear all existing table-related parameters
    const commonTableParams = ['page', 'pageSize', 'search', 'sort'];

    // Internal parameters that should NOT be synced to URL
    const internalParams = ['total', 'totalPages', 'totalRecords', 'loading', 'error'];

    // Get all column field names for filtering
    const columnFields = [];
    if (table.columns && table.columns.size > 0) {
      for (const [field] of table.columns) {
        columnFields.push(field);
      }
    }

    // Remove all table-related parameters (but not internal ones - they shouldn't be there anyway)
    const keysToRemove = [...commonTableParams, ...columnFields];
    keysToRemove.forEach(key => {
      // Remove all instances of the key (important for array params like sort[])
      while (urlParams.has(key)) {
        urlParams.delete(key);
      }
    });

    // Add new parameters (simple format without table prefix)
    Object.entries(newParams).forEach(([key, value]) => {
      if (value === undefined || value === null || value === '') return;

      // Skip internal parameters that shouldn't appear in URLs
      if (internalParams.includes(key)) return;

      if (Array.isArray(value)) {
        // Handle array parameters (for multi-sort)
        value.forEach(v => {
          if (v !== undefined && v !== null && v !== '') {
            urlParams.append(key, v);
          }
        });
      } else {
        urlParams.set(key, value);
      }
    });

    // Build new URL supporting both modes
    let newUrl;
    const queryString = urlParams.toString();

    if (isHashMode) {
      // Hash mode: put params in hash fragment
      const hashBase = window.location.hash.split('?')[0] || '#/';
      newUrl = `${window.location.pathname}${hashBase}${queryString ? '?' + queryString : ''}`;
    } else {
      // History mode: standard query params
      newUrl = `${window.location.pathname}${queryString ? '?' + queryString : ''}${window.location.hash}`;
    }

    try {
      window.history.replaceState({}, '', newUrl);
    } catch (e) {
      console.warn('Failed to update URL parameters:', e);
    }
  },

  syncStateToUrl(tableId) {
    const table = this.state.tables.get(tableId);
    if (!table) return;

    // Check if URL params are enabled for this table
    const urlParamsEnabled = table.config.urlParams !== undefined ? table.config.urlParams : this.config.urlParams;
    if (!urlParamsEnabled) return;

    // Internal parameters that should NOT be synced to URL
    const internalParams = ['total', 'totalPages', 'totalRecords', 'loading', 'error'];

    const params = {};
    // Only include user-facing parameters, exclude internal ones
    Object.entries(table.config.params).forEach(([key, value]) => {
      if (!internalParams.includes(key) && !['sort', 'order'].includes(key)) {
        params[key] = value;
      }
    });

    // Add sort state in compact format (e.g., 'name asc,status desc')
    if (table.sortState && Object.keys(table.sortState).length > 0) {
      const sortPairs = Object.entries(table.sortState).map(([field, direction]) => `${field} ${direction}`);

      if (sortPairs.length > 0) {
        params.sort = sortPairs.join(',');
      }
    }

    // Clean up empty values
    Object.keys(params).forEach(key => {
      const value = params[key];

      // Handle regular parameters
      if (value === undefined) {
        delete params[key];
      } else if (value === null) {
        params[key] = '';
      }

      // Remove default page value
      if (key === 'page' && value === 1) {
        delete params[key];
      }
    });

    this.updateUrlParams(tableId, params);
  },

  loadStateFromUrl(tableId) {
    const urlParams = this.getUrlParams(tableId);
    const table = this.state.tables.get(tableId);
    if (!table || Object.keys(urlParams).length === 0) return false;

    // Update table config params
    Object.keys(urlParams).forEach(key => {
      if (['sort', 'order'].includes(key)) return; // Handle separately
      table.config.params[key] = urlParams[key];
    });

    // Restore sort state from compact format (e.g., 'name asc,status desc')
    if (urlParams.sort) {
      table.sortState = {};

      // Parse compact sort format: 'name asc,status desc'
      const sortPairs = urlParams.sort.split(',').map(pair => pair.trim());

      sortPairs.forEach(pair => {
        const parts = pair.split(/\s+/); // Split by whitespace
        if (parts.length >= 2) {
          const field = parts[0];
          const direction = parts[1].toLowerCase();

          if (['asc', 'desc'].includes(direction)) {
            table.sortState[field] = direction;
          }
        } else if (parts.length === 1) {
          // Default to 'asc' if no direction specified
          table.sortState[parts[0]] = 'asc';
        }
      });
    }

    return true;
  },

  restoreFilterUIFromState(tableId) {
    const table = this.state.tables.get(tableId);
    if (!table || !table.filterElements) return;

    // Update filter element values to match loaded state
    Object.entries(table.config.params).forEach(([key, value]) => {
      const filterElement = table.filterElements.get(key);
      if (filterElement && filterElement.element && value !== undefined && value !== null) {
        try {
          const element = filterElement.element;

          // Always use the guarded setter so active fields are not overwritten
          // by refreshed state coming back from API/meta.
          if (element.tagName === 'SELECT') {
            const options = Array.from(element.options);
            const hasOption = options.some(opt => opt.value === String(value));
            if (!hasOption && !table.externalFilterForm) {
              return;
            }
          }

          this.setFormElementValue(element, value);
        } catch (error) {
          console.warn('Failed to restore filter UI value:', {key, value, error});
        }
      }
    });
  },

  ensureFooterStructure(table, headerRow = null) {
    if (!table?.element) return null;

    let tfoot = table.element.querySelector('tfoot');
    if (!tfoot) {
      if (!table.config?.showFooter) {
        return null;
      }

      tfoot = document.createElement('tfoot');
      table.element.appendChild(tfoot);
    }

    if (!table.config?.showFooter) {
      return tfoot;
    }

    const columnCount = this.getColumnGroups(table, headerRow).length;

    if (columnCount === 0) {
      return tfoot;
    }

    const footerLayout = this.getSectionCellLayout(tfoot);
    if (footerLayout.length === 0) {
      const tr = document.createElement('tr');
      for (let i = 0; i < columnCount; i++) {
        tr.appendChild(document.createElement('td'));
      }
      tfoot.appendChild(tr);
      return tfoot;
    }

    footerLayout.forEach((rowEntries, rowIndex) => {
      const tr = tfoot.rows[rowIndex];
      const occupiedColumns = rowEntries.reduce((max, entry) => {
        return Math.max(max, entry.columnIndex + entry.colspan);
      }, 0);

      for (let i = occupiedColumns; i < columnCount; i++) {
        tr.appendChild(document.createElement('td'));
      }
    });

    return tfoot;
  },

  applyFooterAggregates(table, columns, tfoot) {
    const aggregates = table?.config?.footerAggregates;
    if (!tfoot || !aggregates || typeof aggregates !== 'object') return;

    const aggregateTypes = ['sum', 'avg', 'count', 'min', 'max', 'custom'];

    this.getSectionCellLayout(tfoot).forEach(rowEntries => {
      rowEntries.forEach(({cell, columnIndex, colspan}) => {
        if (cell.dataset.autoAggregate === 'true') {
          aggregateTypes.forEach(type => {
            delete cell.dataset[type];
          });
          delete cell.dataset.aggregate;
          delete cell.dataset.field;
          delete cell.dataset.autoAggregate;
          cell.textContent = '';
        }

        const aggregateConfig = this.getFooterAggregateConfig(cell);
        const hasStaticContent = cell.textContent.trim() !== '' || cell.children.length > 0;
        if (aggregateConfig.type || hasStaticContent || colspan !== 1) {
          return;
        }

        const field = columns[columnIndex]?.field;
        const aggregateType = field ? aggregates[field] : null;
        if (!field || !aggregateTypes.includes(aggregateType)) {
          return;
        }

        cell.dataset.field = field;
        cell.dataset.aggregate = aggregateType;
        cell.dataset.autoAggregate = 'true';
      });
    });
  },

  getSectionCellLayout(section) {
    if (!section?.rows?.length) return [];

    const occupied = new Map();

    return Array.from(section.rows).map((tr, rowIndex) => {
      let currentCol = 0;

      return Array.from(tr.cells).map(cell => {
        while (occupied.has(`${rowIndex}-${currentCol}`)) {
          currentCol++;
        }

        const colspan = parseInt(cell.getAttribute('colspan') || 1, 10);
        const rowspan = parseInt(cell.getAttribute('rowspan') || 1, 10);

        for (let rowOffset = 0; rowOffset < rowspan; rowOffset++) {
          for (let colOffset = 0; colOffset < colspan; colOffset++) {
            occupied.set(`${rowIndex + rowOffset}-${currentCol + colOffset}`, true);
          }
        }

        const entry = {
          cell,
          rowIndex,
          columnIndex: currentCol,
          colspan,
          rowspan
        };

        currentCol += colspan;
        return entry;
      });
    });
  },

  captureFooterCellState(cell) {
    if (!cell) return;

    if (cell.dataset.footerOriginalHtml === undefined) {
      cell.dataset.footerOriginalHtml = cell.innerHTML;
    }

    if (cell.dataset.footerOriginalClass === undefined) {
      cell.dataset.footerOriginalClass = cell.className;
    }

    if (cell.dataset.footerOriginalAlign === undefined) {
      cell.dataset.footerOriginalAlign = cell.style.textAlign || '';
    }
  },

  resetFooterCellState(cell) {
    if (!cell) return;

    if (cell.dataset.footerOriginalHtml !== undefined) {
      cell.innerHTML = cell.dataset.footerOriginalHtml;
    }

    if (cell.dataset.footerOriginalClass !== undefined) {
      cell.className = cell.dataset.footerOriginalClass;
    }

    cell.style.textAlign = cell.dataset.footerOriginalAlign || '';
    delete cell.dataset.processed;
  },

  getFooterAggregateConfig(cell) {
    if (!cell) {
      return {type: null, field: null, customFn: null};
    }

    const aggregateTypes = ['sum', 'avg', 'count', 'min', 'max', 'custom'];
    const aggregateType = cell.dataset.aggregate;

    if (aggregateTypes.includes(aggregateType)) {
      return {
        type: aggregateType,
        field: cell.dataset.field || null,
        customFn: aggregateType === 'custom' ? (cell.dataset.custom || null) : null
      };
    }

    const legacyType = aggregateTypes.find(type => cell.dataset[type] !== undefined);
    if (!legacyType) {
      return {type: null, field: null, customFn: null};
    }

    return {
      type: legacyType,
      field: legacyType === 'custom' ? (cell.dataset.field || null) : (cell.dataset[legacyType] || null),
      customFn: legacyType === 'custom' ? (cell.dataset.custom || null) : null
    };
  },

  parseAggregateNumber(value) {
    if (typeof value === 'number') {
      return Number.isFinite(value) ? value : null;
    }

    if (typeof value === 'string') {
      const normalized = value.trim().replace(/,/g, '');
      if (normalized === '') return null;

      const parsed = Number(normalized);
      return Number.isFinite(parsed) ? parsed : null;
    }

    return null;
  },

  getAggregateResult(data, field, type) {
    if (!Array.isArray(data) || !field) {
      return {processable: false, value: null};
    }

    const values = data
      .map(row => row?.[field])
      .filter(value => value !== null && value !== undefined && !(typeof value === 'string' && value.trim() === ''));

    if (type === 'count') {
      return {
        processable: true,
        value: values.length
      };
    }

    const numericValues = values
      .map(value => this.parseAggregateNumber(value))
      .filter(value => value !== null);

    const hasInvalidValue = values.length !== numericValues.length;
    if (hasInvalidValue || numericValues.length === 0) {
      return {processable: false, value: null};
    }

    switch (type) {
      case 'sum':
        return {processable: true, value: numericValues.reduce((sum, value) => sum + value, 0)};
      case 'avg':
        return {processable: true, value: numericValues.reduce((sum, value) => sum + value, 0) / numericValues.length};
      case 'min':
        return {processable: true, value: Math.min(...numericValues)};
      case 'max':
        return {processable: true, value: Math.max(...numericValues)};
      default:
        return {processable: false, value: null};
    }
  },

  applyFooterCellPresentation(cell, column) {
    const classNames = new Set(
      (cell.dataset.footerOriginalClass || '')
        .split(/\s+/)
        .filter(Boolean)
    );

    [cell.dataset.class, cell.dataset.cellClass, column?.cellClass, column?.class]
      .filter(Boolean)
      .forEach(className => {
        className.split(/\s+/).filter(Boolean).forEach(name => classNames.add(name));
      });

    classNames.add('aggregate-cell');
    cell.className = Array.from(classNames).join(' ');

    const align = cell.dataset.align || column?.align;
    cell.style.textAlign = cell.dataset.footerOriginalAlign || '';
    if (align) {
      cell.style.textAlign = align;
    }
  },

  initTable(table, options = {}) {
    if (!table) {
      return this.handleError('Table element is required', null, 'init');
    }

    const tableId = table.dataset.table;
    if (!tableId) {
      return this.handleError('Table ID is required', null, 'init');
    }

    if (this.state.tables.has(tableId)) {
      // Check if existing table element is still in DOM and is the same element
      const existingTable = this.state.tables.get(tableId);
      const existingElement = existingTable?.element;

      // Only skip if it's the exact same DOM element AND still connected
      if (existingElement === table && existingElement.isConnected) {
        return; // Same element already initialized
      }

      // Different element or old element disconnected - cleanup and reinitialize
      this.destroyTable(tableId);
    }

    try {
      const config = {
        ...this.config,
        ...options,
        ...this.extractDataAttributes(table, this.config),
        params: {
          ...this.config.params,
          ...this.extractDataAttributes(table, this.config.params)
        }
      };

      // data-editable-rows is an alias for allowing row modification (add/delete)
      if (table.dataset.editableRows === 'true') {
        config.allowRowModification = true;
        // Auto-disable caption for editable tables (unless explicitly overridden)
        // Editable tables typically show all data without pagination, so caption is misleading
        if (table.dataset.showCaption === undefined) {
          config.showCaption = false;
        }
      }

      // data-row-sortable can be enabled independently for drag-and-drop row reordering
      if (table.dataset.rowSortable === 'true') {
        config.rowSortable = true;
      }

      // Detect external filter form
      const externalFilterForm = document.querySelector(`[data-table-filter="${tableId}"]`);

      this.state.tables.set(tableId, {
        id: tableId,
        element: table,
        config,
        data: [],
        sortState: {},
        filterWrapper: null,
        actionWrapper: null,
        paginationWrapper: null,
        filterElements: new Map(),
        filterData: new Map(),
        columns: new Map(),
        filterOptions: new Map(),
        elementInstances: new Map(),
        initializing: true, // Flag to prevent redundant renders during setup
        externalFilterForm: externalFilterForm || null // Store reference to external filter form
      });

      if (config.source) {
        this.bindToState(tableId, config.source);
      }

      this.setupTableStructure(tableId);
      this.setupAccessibility(table, tableId, config);
      this.setupEventListeners(tableId);

      // Apply data-default-sort if provided (before URL state is loaded)
      let tableObj = this.state.tables.get(tableId);
      if (table.dataset.defaultSort && tableObj) {
        this.applyDefaultSort(tableObj, table.dataset.defaultSort);
      }

      // Load state from URL after setup so filter elements exist
      // URL sort takes precedence over data-default-sort
      const urlStateLoaded = this.loadStateFromUrl(tableId);
      if (urlStateLoaded) {
        // Restore filter UI values from loaded URL state
        this.restoreFilterUIFromState(tableId);
      }

      // Load table data once (preventing redundant renders during init)
      // Only load if not already bound to state
      try {
        tableObj = this.state.tables.get(tableId);
        const hasDeclarativeBinding = this.hasDeclarativeDataBinding(table);
        const needsDataLoad = config.source
          ? !config.source.startsWith('state.')
          : !hasDeclarativeBinding;

        if (needsDataLoad) {
          const loadPromise = this.loadTableData(tableId);

          // Handle async completion
          if (loadPromise && typeof loadPromise.then === 'function') {
            loadPromise.finally(() => {
              const currentTableObj = this.state.tables.get(tableId);
              if (currentTableObj) {
                currentTableObj.initializing = false;
                this.renderTable(tableId);
              }
            });
          } else {
            // Synchronous completion
            if (tableObj) {
              tableObj.initializing = false;
              this.renderTable(tableId);
            }
          }
        } else {
          // Declarative bindings will call setData shortly after init.
          // Avoid rendering an empty editable row here because that can mask
          // or overwrite the later bound data during route transitions.
          if (tableObj) {
            tableObj.initializing = false;
            if (!hasDeclarativeBinding) {
              this.renderTable(tableId);
            }
          }
        }
      } catch (err) {
        console.warn(`TableManager: failed to load initial data for table ${tableId}:`, err);

        // Ensure initialization completes even on error
        tableObj = this.state.tables.get(tableId);
        if (tableObj) {
          tableObj.initializing = false;
          this.renderTable(tableId);
        }
      }

      return tableId;

    } catch (error) {
      return this.handleError('Failed to initialize table', error, 'init');
    }
  },

  /**
   * Apply default sort from data-default-sort attribute
   * Format: "field direction" or "field1 direction1,field2 direction2"
   * Example: "created_at desc" or "status asc,name desc"
   */
  applyDefaultSort(table, sortString) {
    if (!table || !sortString) return;

    // Parse compact sort format: 'name asc,status desc'
    const sortPairs = sortString.split(',').map(pair => pair.trim());

    sortPairs.forEach(pair => {
      const parts = pair.split(/\s+/); // Split by whitespace
      if (parts.length >= 2) {
        const field = parts[0];
        const direction = parts[1].toLowerCase();

        if (['asc', 'desc'].includes(direction)) {
          table.sortState[field] = direction;

          // Apply visual indicator to th
          const th = table.element.querySelector(`th[data-sort="${field}"]`);
          if (th) {
            th.classList.remove('sort_asc', 'sort_desc');
            th.classList.add(direction === 'asc' ? 'sort_asc' : 'sort_desc');
            th.setAttribute('aria-sort', direction === 'asc' ? 'ascending' : 'descending');
          }
        }
      } else if (parts.length === 1 && parts[0]) {
        // Default to 'asc' if no direction specified
        table.sortState[parts[0]] = 'asc';

        const th = table.element.querySelector(`th[data-sort="${parts[0]}"]`);
        if (th) {
          th.classList.remove('sort_asc', 'sort_desc');
          th.classList.add('sort_asc');
          th.setAttribute('aria-sort', 'ascending');
        }
      }
    });

    // Update sort order indicators for multi-column sort
    this.updateSortOrderIndicators(table);
  },

  /**
   * Update sort order indicators for multi-column sorting
   * Uses data-sort-order attribute with CSS ::after to avoid i18n translation issues
   * @param {Object} table - Table instance
   */
  updateSortOrderIndicators(table) {
    if (!table?.element) return;

    // Clear all existing sort order indicators
    table.element.querySelectorAll('th[data-sort]').forEach(th => {
      delete th.dataset.sortOrder;
    });

    // Add indicators only for multi-column sort
    if (table.sortState && Object.keys(table.sortState).length > 1) {
      Object.keys(table.sortState).forEach((column, index) => {
        const th = table.element.querySelector(`th[data-sort="${column}"]`);
        if (th) {
          th.dataset.sortOrder = index + 1;
        }
      });
    }
  },

  extractDataAttributes(element, defaultConfig) {
    const config = {};
    Object.keys(element.dataset).forEach(key => {
      const defaultValue = defaultConfig[key];

      if (defaultValue === undefined) return;

      try {
        if (Array.isArray(defaultValue)) {
          config[key] = element.dataset[key].split(',').map(item => item.trim());
        } else if (typeof defaultValue === 'object' && defaultValue !== null) {
          const raw = element.dataset[key].trim();
          // Only JSON-parse if it looks like a JSON object/array; otherwise leave default
          // (e.g. data-action-params="module_id" is resolved later as URL query key list)
          if (raw.startsWith('{') || raw.startsWith('[')) {
            config[key] = JSON.parse(raw);
          }
        } else if (typeof defaultValue === 'boolean') {
          config[key] = element.dataset[key] === 'true';
        } else if (typeof defaultValue === 'number') {
          config[key] = parseInt(element.dataset[key]);
        } else {
          config[key] = element.dataset[key];
        }
      } catch (error) {
        console.warn(`Unable to parse value for ${key}:`, error);
      }
    });
    return {...defaultConfig, ...config};
  },

  resolveTargetElement(target, table = null) {
    if (!target) {
      return null;
    }

    if (typeof HTMLElement !== 'undefined' && target instanceof HTMLElement) {
      return target;
    }

    if (table?.element) {
      if (target === 'self' || target === 'current') {
        return table.element;
      }

      const localMatch = table.element.querySelector(target);
      if (localMatch) {
        return localMatch;
      }
    }

    if (typeof target === 'string') {
      if (target.startsWith('#')) {
        return document.getElementById(target.substring(1));
      }
      return document.querySelector(target);
    }

    return null;
  },

  normalizeLoadBindingPayload(payload, table = null) {
    const source = Array.isArray(payload)
      ? {data: payload}
      : (payload && typeof payload === 'object' ? {...payload} : {data: []});

    const hasOwnData = Object.prototype.hasOwnProperty.call(source, 'data');
    const dataValue = hasOwnData ? source.data : [];
    const rows = Array.isArray(dataValue) ? dataValue : [];
    const metaSource = source.meta && typeof source.meta === 'object' ? source.meta : {};
    const currentParams = table?.config?.params || {};
    const fallbackTotal = Array.isArray(dataValue) ? dataValue.length : (dataValue ? 1 : 0);
    const total = Math.max(0, parseInt(metaSource.total ?? source.total ?? fallbackTotal, 10) || 0);
    const fallbackPageSize = Math.max(1, parseInt(currentParams.pageSize || rows.length || 1, 10) || 1);
    const pageSize = Math.max(
      1,
      parseInt(metaSource.pageSize ?? metaSource.limit ?? source.pageSize ?? source.limit ?? fallbackPageSize, 10) || fallbackPageSize
    );
    const fallbackTotalPages = Math.ceil(total / pageSize) || 1;
    const totalPagesValue = metaSource.totalPages ?? source.pages ?? source.totalPages ?? fallbackTotalPages;
    const totalPages = Math.max(1, parseInt(totalPagesValue, 10) || 1);
    const page = Math.min(totalPages, Math.max(1, parseInt(metaSource.page ?? source.page ?? currentParams.page ?? 1, 10) || 1));
    const meta = {
      ...metaSource,
      page,
      pageSize,
      total,
      totalPages
    };

    return {
      ...source,
      data: dataValue,
      meta,
      filters: source.filters || {},
      options: source.options || {},
      columns: source.columns || undefined,
      params: {...currentParams},
      tableId: table?.id || null,
      tableSource: table?.element?.dataset?.source || table?.config?.source || '',
      isServerSide: this.isServerSideTable(table),
      hasData: Array.isArray(dataValue) ? dataValue.length > 0 : !!dataValue,
      empty: Array.isArray(dataValue) ? dataValue.length === 0 : !dataValue,
      raw: payload
    };
  },

  bindLoadTarget(tableId, payload) {
    const table = typeof tableId === 'string' ? this.state.tables.get(tableId) : tableId;
    if (!table?.config?.loadTarget || !window.TemplateManager) {
      return null;
    }

    const target = this.resolveTargetElement(table.config.loadTarget, table);
    if (!target) {
      return null;
    }

    const normalized = this.normalizeLoadBindingPayload(payload, table);
    const context = {
      state: normalized,
      data: normalized.data,
      computed: {}
    };

    table.loadBinding = normalized;

    TemplateManager.processTemplate(target, context);

    try {
      if (typeof TemplateManager.processDataOnLoad === 'function') {
        TemplateManager.processDataOnLoad(target, context);
      }
    } catch (error) {
      console.warn('TableManager: load target data-on-load failed', error);
    }

    return normalized;
  },

  setupCheckboxes(table, tableId) {
    if (!table.config.showCheckbox) return;

    ['thead', 'tfoot'].forEach(section => {
      const tr = table.element.querySelector(`${section} tr:first-child`);
      if (tr) {
        let checkboxId = `select-all-${tableId}-${section}`;
        let checkbox = tr.querySelector('.select-all');
        if (!checkbox) {
          const label = document.createElement('label');
          label.htmlFor = checkboxId;

          checkbox = document.createElement('input');
          checkbox.type = 'checkbox';

          const cell = document.createElement(section === 'thead' ? 'th' : 'td');
          const rowspan = table.element.querySelectorAll(`${section} tr:first-child`).length;
          if (rowspan > 1) {
            cell.setAttribute('rowspan', rowspan);
          }
          cell.className = 'check-column';

          label.appendChild(checkbox);
          cell.appendChild(label);
          tr.insertBefore(cell, tr.firstChild);
        } else {
          if (checkbox.id) {
            checkboxId = checkbox.id;
          }
          const label = checkbox.closest('label');
          if (label) {
            label.htmlFor = checkboxId;
          }
          checkbox.closest('th, td').classList.add('check-column');
        }
        checkbox.className = 'select-all';
        checkbox.id = checkboxId;
        checkbox.setAttribute('aria-label', Now.translate('Select all'));

        // Remove old event listener if exists to prevent duplicate handlers
        const oldHandler = checkbox._selectAllHandler;
        if (oldHandler) {
          checkbox.removeEventListener('change', oldHandler);
        }

        // Create and store new handler
        const newHandler = (e) => {
          this.handleSelectAll(table, tableId, e.target.checked);
        };
        checkbox._selectAllHandler = newHandler;
        checkbox.addEventListener('change', newHandler);
      }
    });
  },

  handleSelectAll(table, tableId, checked) {
    if (!table?.element) return;

    const checkboxes = table.element.querySelectorAll('tbody .select-row');
    checkboxes.forEach(cb => {
      cb.checked = checked;
    });

    const allCheckboxes = table.element.querySelectorAll('.select-all');
    allCheckboxes.forEach(cb => {
      cb.checked = checked;
    });

    this.handleRowSelection(table, tableId);
  },

  handleRowSelection(table, tableId) {
    const checkboxes = table.element.querySelectorAll('tbody .select-row');
    const checkedBoxes = table.element.querySelectorAll('tbody .select-row:checked');

    const allChecked = checkboxes.length > 0 && checkboxes.length === checkedBoxes.length;
    const someChecked = checkedBoxes.length > 0 && checkedBoxes.length < checkboxes.length;

    const allCheckboxes = table.element.querySelectorAll('.select-all');
    allCheckboxes.forEach(cb => {
      cb.checked = allChecked;
      cb.indeterminate = someChecked;
    });

    table.selectedRows = Array.from(checkedBoxes).map(cb => cb.value);

    if (table.config.onSelectionChange) {
      table.config.onSelectionChange(table.selectedRows);
    }

    EventManager.emit('table:selectionChange', {
      tableId: tableId,
      selectedRows: table.selectedRows
    });
  },

  clearSelection(table, tableId, options = {}) {
    if (!table?.element || !table.config.showCheckbox) return;

    const {emit = true} = options;

    const rowCheckboxes = table.element.querySelectorAll('tbody .select-row');
    rowCheckboxes.forEach(cb => {
      cb.checked = false;
      cb.closest('tr')?.classList.remove('selected-row');
    });

    const masterCheckboxes = table.element.querySelectorAll('.select-all');
    masterCheckboxes.forEach(cb => {
      cb.checked = false;
      cb.indeterminate = false;
    });

    const hadSelection = Array.isArray(table.selectedRows) && table.selectedRows.length > 0;
    table.selectedRows = [];

    if (table.actionElements?.submit?.element) {
      table.actionElements.submit.element.disabled = true;
    }

    if (typeof this.updateSelectedCount === 'function') {
      this.updateSelectedCount(table.element, 0);
    }

    if (emit && hadSelection) {
      if (typeof table.config.onSelectionChange === 'function') {
        table.config.onSelectionChange([]);
      }

      EventManager.emit('table:selectionChange', {
        tableId,
        selectedRows: []
      });
    }
  },

  cleanupActionBindings(table) {
    if (!table?.eventHandlers) return;

    if (table.eventHandlers.actionButton && table.eventHandlers.actionButtonElement) {
      table.eventHandlers.actionButtonElement.removeEventListener('click', table.eventHandlers.actionButton);
      delete table.eventHandlers.actionButton;
      delete table.eventHandlers.actionButtonElement;
    }

    if (table.eventHandlers.actionSelectionChange) {
      EventManager.off('table:selectionChange', table.eventHandlers.actionSelectionChange);
      delete table.eventHandlers.actionSelectionChange;
    }
  },

  bindActionWrapper(table, tableId) {
    table.eventHandlers ||= {};
    this.cleanupActionBindings(table);

    if (!table?.actionElements?.submit?.element || !table?.actionElements?.select?.element) {
      return;
    }

    const submitBtn = table.actionElements.submit.element;
    const selectEl = table.actionElements.select.element;

    const onActionButtonClick = async (e) => {
      e.preventDefault();

      if (!selectEl.value || selectEl.value === '') {
        NotificationManager.warning('Please select an action');
        return;
      }

      const selectedCount = this.getSelectedRowIds(table)?.length || 0;
      if (selectedCount === 0) {
        NotificationManager.warning('Please select at least one row');
        return;
      }

      const message = Now.translate('You want to {action} the selected items ?', {
        action: selectEl.options[selectEl.selectedIndex].textContent
      });
      const confirmed = await DialogManager.confirm(message);
      if (confirmed) {
        this._performActionWrapperSubmission(tableId);
      }
    };

    const onSelectionChange = (event) => {
      if (event.data.tableId === tableId) {
        submitBtn.disabled = !event.data.selectedRows || event.data.selectedRows.length === 0;
      }
    };

    submitBtn.addEventListener('click', onActionButtonClick);
    EventManager.on('table:selectionChange', onSelectionChange);

    table.eventHandlers.actionButton = onActionButtonClick;
    table.eventHandlers.actionButtonElement = submitBtn;
    table.eventHandlers.actionSelectionChange = onSelectionChange;

    submitBtn.disabled = (this.getSelectedRowIds(table)?.length || 0) === 0;
  },

  setupTableStructure(tableId) {
    const table = this.state.tables.get(tableId);
    if (!table) return;

    const {element: tableEl, config} = table;
    const thead = tableEl.querySelector('thead');

    let tfoot = this.ensureFooterStructure(table);

    if (thead) {
      thead.querySelectorAll('th[data-sort]').forEach(th => {
        th.classList.add('sortable');
        th.setAttribute('role', 'columnheader');
        th.setAttribute('tabindex', '0');
      });

      // If row modification is enabled, ensure header has an actions column
      if (config.allowRowModification) {
        thead.querySelectorAll('tr').forEach(tr => {
          if (tr.querySelector('th.icons')) return;
          const th = document.createElement('th');
          th.className = 'icons';
          tr.appendChild(th);
        });

        if (tfoot) {
          tfoot.querySelectorAll('tr').forEach(tr => {
            if (tr.querySelector('td.icons')) return;
            const td = document.createElement('td');
            td.className = 'icons';
            tr.appendChild(td);
          });
        }
      }

      const rowActionsRaw = tableEl.dataset.rowActions || tableEl.dataset.rowActionsJson;
      if (rowActionsRaw) {
        thead.querySelectorAll('tr').forEach(tr => {
          if (tr.querySelector('th.row-actions')) return;
          const th = document.createElement('th');
          th.className = 'row-actions';
          tr.appendChild(th);
        });

        if (tfoot) {
          tfoot.querySelectorAll('tr').forEach(tr => {
            if (tr.querySelector('td.row-actions')) return;
            const td = document.createElement('td');
            td.className = 'row-actions';
            tr.appendChild(td);
          });
        }
      }
    }

    // Create filter wrapper only if no external form exists
    if (!table.externalFilterForm) {
      table.filterWrapper = document.createElement('div');
      table.filterWrapper.className = 'table_nav';
      tableEl.parentNode.insertBefore(table.filterWrapper, tableEl);
    } else {
      // Use external form as filter wrapper
      table.filterWrapper = table.externalFilterForm;
    }

    table.actionWrapper = document.createElement('div');
    table.actionWrapper.className = 'table_nav';
    tableEl.parentNode.appendChild(table.actionWrapper);

    table.paginationWrapper = document.createElement('div');
    table.paginationWrapper.className = 'splitpage';
    tableEl.parentNode.appendChild(table.paginationWrapper);

    // Setup filters (always run to read column definitions). When an external
    // filter form exists, setupFilter will skip building UI but still parse
    // <th> attributes, defaults, and derived filters. External form wiring is
    // handled separately.
    this.setupFilter(table, tableId);
    if (table.externalFilterForm) {
      this.setupExternalFilter(table, tableId);
    }
    this.setupFooter(table);
    this.setupCheckboxes(table, tableId);
    this.setupActions(table, tableId);
    if (config?.pageSize > 0) {
      this.setupPagination(table, tableId);
    }
  },

  setupEventListeners(tableId) {
    const table = this.state.tables.get(tableId);
    if (!table) return;

    const {element: tableEl, config} = table;

    // Setup handler registry used by delegation and sort setup
    const handlers = table.eventHandlers || {};
    handlers.sort = new Map();
    handlers.filter = new Map();
    handlers.keyboard = null;
    table.eventHandlers = handlers;

    if (config.touchEnabled) {
      this.setupTouchEvents(table);
    }

    // Delegated event handlers on tbody to avoid per-element listeners
    try {
      const tbody = table.element.querySelector('tbody');
      if (tbody) {
        const delegatedClick = (e) => this._handleDelegatedClick(e, tableId);
        const delegatedChange = (e) => this._handleDelegatedChange(e, tableId);

        tbody.addEventListener('click', delegatedClick);
        tbody.addEventListener('change', delegatedChange);

        // store references for cleanup
        table.eventHandlers.delegation = {
          click: delegatedClick,
          change: delegatedChange
        };
      }

      // delegate filter controls (pageSize, selects, search) from filterWrapper
      if (table.filterWrapper) {
        const fwChange = (e) => {
          // rely on existing data-field or element ids from elementFactory
          const target = e.target;
          if (!target) return;

          // If the input was created/managed by ElementManager, it already
          // has its own change handler. Skip delegated wrapper handling to
          // avoid double-processing (and double API calls).
          if (target.closest && target.closest('[data-filter-managed]')) {
            return;
          }

          // For external filter forms, always sync ALL fields on any change
          // This ensures that we capture the current state of all inputs, including
          // those that might not have triggered individual updates correctly or
          // interrelated fields (e.g. date ranges).
          if (table.externalFilterForm) {
            this.handleExternalFilterSubmit(table, tableId);
            return;
          }

          // pageSize handled by attribute 'pageSize' name or element id starting with pageSize_
          const id = target.id || '';
          if (id.startsWith(`pageSize_${tableId}`) || target.dataset.field === 'pageSize') {
            const value = parseInt(target.value);
            this.handleFilterChange(table, tableId, 'pageSize', value, target);
            return;
          }

          // generic field-based filters
          const field = target.dataset.name || target.dataset.field || target.name;
          if (field) {
            const value = (target.type === 'checkbox') ? target.checked : target.value;
            this.handleFilterChange(table, tableId, field, value, target);
          }
        };

        table.filterWrapper.addEventListener('change', fwChange);
        table.eventHandlers.filterWrapperChange = fwChange;
      }
    } catch (err) {
      console.warn('Delegation setup failed', err);
    }

    // Setup column resizing if enabled
    this.setupColumnResizing(tableId);

    this.bindActionWrapper(table, tableId);


    tableEl.querySelectorAll('thead th[data-sort]').forEach(th => {
      const sortHandler = (e) => {
        e.preventDefault();
        e.stopPropagation();
        // Prevent text selection when Shift+clicking
        if (e.shiftKey) {
          window.getSelection()?.removeAllRanges();
        }
        this.handleSort(table, tableId, th, e);
      };

      const keyHandler = (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          this.handleSort(table, tableId, th, e);
        }
      };

      th.addEventListener('click', sortHandler);
      th.addEventListener('keydown', keyHandler);

      handlers.sort.set(th, {
        sort: sortHandler,
        key: keyHandler
      });

      th.setAttribute('role', 'columnheader');
      th.setAttribute('tabindex', '0');
      th.setAttribute('aria-sort', 'none');
    });

    // finalize event handler registry
    table.eventHandlers = handlers;
  },

  cleanupFilterEvents(table) {
    if (!table.filterElements) return;

    table.filterElements.forEach((element, elementId) => {
      if (elementId) {
        ElementManager.destroy(elementId);
      }
    });

    table.filterElements.clear();
  },

  handleSort(table, tableId, th, event = null) {
    if (!table || !tableId || !(th instanceof HTMLElement)) {
      throw new Error('Invalid parameters');
    }

    const {config} = table;
    const column = th.dataset.sort;

    const currentSort = table.sortState[column] || 'none';

    const newSort = currentSort === 'asc' ? 'desc' :
      currentSort === 'desc' ? 'none' : 'asc';

    // Check if multi-sort is enabled (either via config or Shift key)
    const isMultiSort = config.multiSort || (event && event.shiftKey);

    if (!isMultiSort) {
      // Single sort: clear other columns
      Object.keys(table.sortState).forEach(key => {
        if (key !== column) {
          delete table.sortState[key];
          const otherTh = table.element.querySelector(`th[data-sort="${key}"]`);
          if (otherTh) {
            otherTh.classList.remove('sort_asc', 'sort_desc');
            otherTh.setAttribute('aria-sort', 'none');
          }
        }
      });
    }

    if (newSort === 'none') {
      delete table.sortState[column];
      th.classList.remove('sort_asc', 'sort_desc');
      th.setAttribute('aria-sort', 'none');
    } else {
      table.sortState[column] = newSort;
      th.classList.remove('sort_asc', 'sort_desc');
      th.classList.add(newSort === 'asc' ? 'sort_asc' : 'sort_desc');
      th.setAttribute('aria-sort', newSort === 'asc' ? 'ascending' : 'descending');
    }

    // Update sort order indicators for all columns
    this.updateSortOrderIndicators(table);

    this.syncStateToUrl(tableId);

    this.clearSelection(table, tableId);

    // If table source is remote (URL), reload from API with sort params
    const source = table.element.dataset.source || table.config.source || '';
    if (this.isUrlSource(source)) {
      // Reset to page 1 when sorting changes
      table.config.params.page = 1;

      // Reload data from API using current sort and filter params
      this.loadFromApi(table, tableId, source).catch(err => {
        console.error('Sort load error', err);
        // On error, still render with client-side sort
        this.renderTable(tableId);
      });
      return;
    }

    // Local data: re-render using client-side sorting
    this.renderTable(tableId);
  },

  handleFieldChange(table, tableId, field, value, rowData, element, options = {send: true}) {
    try {
      if (options.send !== false) {
        if (rowData && typeof rowData === 'object') {
          try {rowData[field] = value;} catch (e) { /* ignore */}
        }
      }

      // If there's no action URL, or caller asked not to send, just update the local data
      const actionUrl = table?.element?.dataset?.actionUrl || table?.config?.actionUrl;
      if (!actionUrl || options.send === false) {
        // Emit event for local handling
        EventManager.emit('table:fieldChange', {
          tableId,
          field,
          value,
          rowData,
          element
        });
        return;
      }

      // Prepare data for server update
      const data = {
        action: 'update',
        field,
        ids: [rowData?.id],
        value
      };

      // Add loading state (guarding for missing element)
      const submitEl = (element && element.classList) ? element : (table?.element || null);
      if (submitEl && submitEl.classList) {
        submitEl.classList.add('loading');
      }

      // Send the update to the server
      this.sendAction(actionUrl, data, tableId, submitEl)
        .then(success => {
          if (success) {
            // Highlight success if element present
            if (element && element.classList) {
              element.classList.add('update-success');
              setTimeout(() => {
                if (element && element.classList) element.classList.remove('update-success');
              }, 1000);
            }
          } else {
            // Revert changes on error
            this.renderTable(tableId);
          }
        })
        .catch(error => {
          console.error('Field update error:', error);
          // Revert changes on error
          this.renderTable(tableId);
        })
        .finally(() => {
          // Remove loading state (guarded)
          if (submitEl && submitEl.classList) {
            submitEl.classList.remove('loading');
          }
          if (element && element.wrapper && element.wrapper.classList) {
            element.wrapper.classList.remove('loading');
          }
        });
    } catch (error) {
      this.handleError('Error updating field', error, 'handleFieldChange');

      // Remove loading state
      if (element) {
        element.classList.remove('loading');
        if (element.wrapper) {
          element.wrapper.classList.remove('loading');
        }
      }
    }
  },

  setupFilter(table, tableId) {
    if (!table?.filterWrapper) return;

    const isExternalFilter = !!table.externalFilterForm;

    // For external filters, do NOT clear or rebuild the form UI. We still need
    // column metadata from <th>. For internal filters, clear and rebuild UI.
    if (isExternalFilter) {
      table.filterElements = table.filterElements || new Map();
      table.filterData = table.filterData || new Map();
    } else {
      table.filterWrapper.innerHTML = '';
      table.filterElements = new Map();
      table.filterData = new Map();
    }

    const {config} = table;
    const elementManager = Now.getManager('element');
    if (!elementManager && !isExternalFilter) return;

    // Create a debounced filter change handler
    const debouncedFilterChange = Utils.function.debounce((table, tableId, key, value, element) => {
      this.handleFilterChange(table, tableId, key, value, element);
    }, 1000);

    // Add page size selector if configured (internal UI only)
    if (!isExternalFilter && config.params.pageSize > 0 && Array.isArray(config.pageSizes) && config.pageSizes.length > 0) {
      const pageSizeOptions = {};
      config.pageSizes.forEach(size => {
        pageSizeOptions[size] = `${size} {LNG_entries}`;
      });

      const select = elementManager.create('select', {
        id: `pageSize_${tableId}`,
        options: pageSizeOptions,
        value: config.params.pageSize,
        label: 'Show',
        wrapper: 'div',
        onChange: (element, value) => {
          debouncedFilterChange(table, tableId, 'pageSize', parseInt(value), element);
        }
      });

      if (select?.element) {
        table.filterWrapper.appendChild(select.wrapper);
        table.filterElements.set('pageSize', select);
        table.filterData.set('pageSize', select);
      }
    }

    // Get column definitions for filter setup
    table.columns = this.getColumnDefinitions(table);

    // Add column-specific filters
    table.columns.forEach((attributes, field) => {
      if (!attributes.filter) return;

      // Set initial filter value if defined
      if (attributes.value !== '') {
        config.params[field] = attributes.value;
      }

      // Persist options for later use (e.g., formatting/lookup)
      if (attributes.options) {
        if (!table.filterOptions) table.filterOptions = new Map();
        table.filterOptions.set(field, attributes.options);
      }

      // External form: skip UI creation, just keep metadata/defaults
      if (isExternalFilter) {
        return;
      }

      // Configure element based on column attributes
      let elementConfig = {
        id: `filter_${field}_${tableId}`,
        name: field,
        label: attributes.label || field,
        placeholder: attributes.placeholder,
        value: config.params[field] || attributes.value || '',
        // Prepare options - if showAll is enabled, ensure an "All" option is present at the start
        options: (() => {
          // Keep provided options as-is. Do not insert an "All" option automatically.
          return attributes.options || {};
        })(),
        datalist: attributes.datalist || null,
        autocomplete: attributes.autocomplete || null,
        wrapper: 'div',
        wrapperClass: 'filter-control',
        onChange: (element, value) => {
          debouncedFilterChange(table, tableId, field, value, element);
        }
      };

      const elementObj = elementManager.create(attributes.type, elementConfig);

      if (elementObj?.element) {
        // Mark elements created by ElementManager so the delegated wrapper
        // change handler can ignore them and avoid double-processing.
        try {
          elementObj.element.dataset.filterManaged = '1';
        } catch (err) {
          // ignore if dataset not writable
        }

        table.filterWrapper.appendChild(elementObj.wrapper);
        table.filterElements.set(field, elementObj);
        table.filterData.set(field, elementObj);
      }
    });

    // Add search box if search columns defined (internal UI only)
    if (!isExternalFilter && config.searchColumns?.length > 0) {
      const search = elementManager.create('search', {
        id: `search_${tableId}`,
        itemClass: 'search',
        value: config.params.search || '',
        placeholder: `${Now.translate('Search in')}: ${config.searchColumns.join(', ')}`,
        minLength: 2,
        onChange: (element, value) => {
          debouncedFilterChange(table, tableId, 'search', value, element);
        }
      });

      if (search?.element) {
        table.filterWrapper.appendChild(search.wrapper || search.element);
        table.filterElements.set('search', search);
        table.filterData.set('search', search);
      }
    }

    // If data arrived before filter setup and derived filters were stored, apply them now
    if (table.pendingDerivedFilters && Object.keys(table.pendingDerivedFilters).length) {
      try {
        this.setFilters(tableId, table.pendingDerivedFilters);
      } catch (err) {
        console.warn('Failed to apply pending derived filters:', err);
      }
      // clear pending
      table.pendingDerivedFilters = null;
    }
  },

  /**
   * Setup external filter form (when data-table-filter attribute exists)
   * @param {Object} table - Table instance object
   * @param {string} tableId - Table identifier
   */
  setupExternalFilter(table, tableId) {
    if (!table?.externalFilterForm) return;

    const form = table.externalFilterForm;
    const {config} = table;

    // Clear existing filter elements/data
    table.filterElements = new Map();
    table.filterData = new Map();

    // Store cleanup function for later
    if (!table._externalFilterCleanup) {
      table._externalFilterCleanup = [];
    }

    // Prevent default form submission
    const submitHandler = (e) => {
      e.preventDefault();
      e.stopPropagation();

      // Collect all form data and trigger reload
      this.handleExternalFilterSubmit(table, tableId);
    };
    form.addEventListener('submit', submitHandler);
    table._externalFilterCleanup.push(() => form.removeEventListener('submit', submitHandler));

    // Find all form elements and register them
    const formElements = form.querySelectorAll('input, select, textarea');

    formElements.forEach(element => {
      const name = element.name || element.id;
      if (!name) return;

      // Store reference to the raw element
      table.filterElements.set(name, {element});

      // Initialize config params if not set
      if (config.params[name] === undefined) {
        const value = this.getFormElementValue(element);
        if (value !== '' && value !== null && value !== undefined) {
          config.params[name] = value;
        }
      }
    });

    // Apply existing config params (including URL state) to form elements
    Object.entries(config.params || {}).forEach(([key, value]) => {
      const entry = table.filterElements.get(key);
      if (entry?.element && value !== undefined && value !== null && value !== '') {
        this.setFormElementValue(entry.element, value);
      }
    });

    // Load initial state from URL if available
    const urlParams = this.getUrlParams(tableId);
    if (urlParams && Object.keys(urlParams).length > 0) {
      // Update form elements with URL values
      Object.entries(urlParams).forEach(([key, value]) => {
        const filterData = table.filterElements.get(key);
        if (filterData?.element) {
          this.setFormElementValue(filterData.element, value);
          config.params[key] = value;
        }
      });
    }
  },

  /**
   * Handle external filter form submission
   * @param {Object} table - Table instance
   * @param {string} tableId - Table identifier
   */
  handleExternalFilterSubmit(table, tableId) {
    const form = table.externalFilterForm;
    if (!form) return;

    // Collect all form values
    const formData = new FormData(form);
    const params = {};

    // Process all form fields, including range filters
    for (const [key, value] of formData.entries()) {
      params[key] = value;
    }

    // Also handle unchecked checkboxes and empty fields
    const formElements = form.querySelectorAll('input, select, textarea');
    formElements.forEach(element => {
      const name = element.name || element.id;
      if (!name) return;

      // For checkboxes/radios not in formData, set to empty/false
      if ((element.type === 'checkbox' || element.type === 'radio') && !formData.has(name)) {
        params[name] = '';
      }
    });

    // Update table config params
    Object.assign(table.config.params, params);

    // Reset to page 1 when filtering
    table.config.params.page = 1;

    // Clear selection
    this.clearSelection(table, tableId);

    // Sync to URL
    this.syncStateToUrl(tableId);

    // Reload table data
    this.loadTableData(tableId);
  },

  /**
   * Get value from a form element
   * @param {HTMLElement} element - Form element
   * @returns {*} Element value
   */
  getFormElementValue(element) {
    if (!element) return '';

    switch (element.type) {
      case 'checkbox':
        return element.checked ? (element.value || '1') : '';
      case 'radio':
        return element.checked ? element.value : '';
      case 'select-multiple':
        return Array.from(element.selectedOptions).map(opt => opt.value);
      default:
        return element.value || '';
    }
  },

  /**
   * Skip overwriting a filter while the user is actively editing it.
   *
   * @param {HTMLElement} element
   * @returns {boolean}
   */
  isEditableElementFocused(element) {
    if (!element || typeof document === 'undefined' || element.disabled || element.readOnly) {
      return false;
    }

    if (document.activeElement !== element) {
      return false;
    }

    if (element.tagName === 'TEXTAREA' || element.tagName === 'SELECT') {
      return true;
    }

    return ['text', 'search', 'email', 'number', 'password', 'tel', 'url'].includes(element.type || 'text');
  },

  /**
   * Set value for a form element
   * @param {HTMLElement} element - Form element
   * @param {*} value - Value to set
   */
  setFormElementValue(element, value) {
    if (!element) return;

    if (this.isEditableElementFocused(element)) {
      return;
    }

    // If element is enhanced by ElementManager (e.g., DateElementFactory), prefer its setter
    try {
      const elementManager = (typeof ElementManager !== 'undefined' && typeof ElementManager.getInstanceByElement === 'function')
        ? ElementManager
        : (typeof Now !== 'undefined' && Now.getManager ? Now.getManager('element') : null);

      const instance = elementManager?.getInstanceByElement?.(element);
      if (instance && typeof instance.setValue === 'function') {
        instance.setValue(value);
        return;
      }
    } catch (err) {
      // fall back to direct assignment
    }

    switch (element.type) {
      case 'checkbox':
        element.checked = (value === '1' || value === 'true' || value === true || value === element.value);
        break;
      case 'radio':
        element.checked = (element.value === value);
        break;
      case 'select-multiple':
        const values = Array.isArray(value) ? value : [value];
        Array.from(element.options).forEach(opt => {
          opt.selected = values.includes(opt.value);
        });
        break;
      default:
        element.value = value;
        break;
    }
  },

  /**
   * Populate external filter form select elements with options from API
   * @param {Object} table - Table instance
   * @param {Object} options - Options object from API response
   * Format: {fieldName: [{value: '1', text: 'Option 1'}, ...]} or {fieldName: {1: 'Option 1', ...}}
   */
  populateExternalFilterOptions(table, options) {
    if (!table.externalFilterForm || !options) return;

    const form = table.externalFilterForm;

    Object.entries(options).forEach(([fieldName, fieldOptions]) => {
      // Find select element by name
      const selectElement = form.querySelector(`select[name="${fieldName}"]`);
      if (!selectElement) return;

      // Store current value to restore after population
      const currentValue = selectElement.value;

      // Clear existing options (except first if it's a placeholder/all option)
      const firstOption = selectElement.options[0];
      const keepFirst = firstOption && (
        firstOption.value === '' ||
        firstOption.textContent.toLowerCase().includes('all') ||
        firstOption.textContent.toLowerCase().includes('ทั้งหมด')
      );

      if (keepFirst) {
        // Keep first option, remove rest
        while (selectElement.options.length > 1) {
          selectElement.remove(1);
        }
      } else {
        // Clear all options
        selectElement.innerHTML = '';
      }

      // Add new options
      if (Array.isArray(fieldOptions)) {
        // Array format: [{value: '1', text: 'Label'}, ...]
        fieldOptions.forEach(opt => {
          const option = document.createElement('option');
          option.value = opt.value !== undefined ? opt.value : (opt.key || opt.id || '');
          option.textContent = opt.text || opt.label || opt.name || option.value;
          selectElement.appendChild(option);
        });
      } else if (typeof fieldOptions === 'object') {
        // Object format: {value: 'Label', ...}
        Object.entries(fieldOptions).forEach(([value, label]) => {
          const option = document.createElement('option');
          option.value = value;
          option.textContent = label;
          selectElement.appendChild(option);
        });
      }

      // Restore previous value if it exists in new options
      if (currentValue) {
        selectElement.value = currentValue;
      }
    });
  },

  handleFilterChange(table, tableId, filterKey, value, element) {
    // Update filter data, reset to page 1
    table.config.params['page'] = 1;
    table.config.params[filterKey] = value;

    this.clearSelection(table, tableId);

    // Update URL with new parameters
    this.syncStateToUrl(tableId);

    // If table source is remote (URL), reload from API with params
    const source = table.element.dataset.source || table.config.source || '';
    if (this.isUrlSource(source)) {
      // reload data from API using current params
      this.loadFromApi(table, tableId, source).catch(err => console.error('Filter load error', err));
      return;
    }

    // local data: re-render using client-side filtering
    this.renderTable(tableId);
  },

  setupAccessibility(table, tableId, config) {
    if (!table) return;

    try {
      table.setAttribute('role', 'grid');
      table.setAttribute('aria-label', config?.label || table.dataset.label || 'Data Table');

      const thead = table.querySelector('thead');
      if (thead) {
        thead.setAttribute('role', 'rowgroup');

        thead.querySelectorAll('th[data-sort]').forEach(th => {
          th.setAttribute('role', 'columnheader');
          th.setAttribute('tabindex', '0');
          th.setAttribute('aria-sort', 'none');
          th.setAttribute('aria-label', `${th.textContent.trim()}, sortable column`);
        });

        thead.querySelectorAll('th:not([data-sort])').forEach(th => {
          th.setAttribute('role', 'columnheader');
        });
      }

      const tbody = table.querySelector('tbody');
      if (tbody) {
        tbody.setAttribute('role', 'rowgroup');

        tbody.querySelectorAll('tr').forEach((tr, index) => {
          tr.setAttribute('role', 'row');
          tr.setAttribute('tabindex', '0');
          tr.setAttribute('aria-rowindex', index + 1);

          tr.querySelectorAll('td').forEach((td, cellIndex) => {
            td.setAttribute('role', 'gridcell');
            td.setAttribute('aria-colindex', cellIndex + 1);
          });
        });
      }

      this.setupKeyboardNavigation(table, tableId);

      const announcer = document.createElement('div');
      announcer.setAttribute('role', 'status');
      announcer.setAttribute('aria-live', 'polite');
      announcer.className = 'sr-only';
      table.parentNode.insertBefore(announcer, table.nextSibling);

      table.dataset.announcer = announcer.id = `table-announcer-${Date.now()}`;

    } catch (error) {
      this.handleError('Error setting up table accessibility', error, 'setupAccessibility');
    }
  },

  setupSortableRows(tableId) {
    const instance = this.state.tables.get(tableId);
    if (!instance?.element) return;

    const tbody = instance.element.querySelector('tbody');
    if (!tbody) return;

    if (typeof Sortable === 'undefined') {
      console.warn('Sortable library is required for drag-and-drop rows');
      return;
    }

    instance.sortable = new Sortable(tbody, {
      draggable: 'tr',
      handle: '.drag-handle',
      animation: 150,
      onStart: () => {
        tbody.classList.add('sorting');
      },
      onEnd: async (evt) => {
        tbody.classList.remove('sorting');

        const rows = Array.from(tbody.children);

        // Get new order
        const order = rows.map((row, index) => ({
          id: row.dataset.id,
          position: index
        }));

        const newData = [];
        order.forEach(item => {
          const data = instance.data.find(d => d.id === item.id);
          if (data) {
            newData.push(data);
          }
        });
        instance.data = newData;

        const sortUrl = instance.element.dataset.sortUrl;
        const actionUrl = sortUrl || instance.element.dataset.actionUrl || instance.config.actionUrl;
        if (!actionUrl) return;

        try {
          let success = false;

          if (sortUrl) {
            const resp = await http.post(sortUrl, {order});
            success = resp && resp.success !== false;
          } else {
            const fakeButton = document.createElement('button');
            fakeButton.className = 'loading';
            success = await this.sendAction(actionUrl, {action: 'reorder', order}, tableId, fakeButton);
          }

          if (success) {
            NotificationManager.success('Saved successfully');
          } else {
            throw new Error('Failed to process request');
          }

        } catch (error) {
          console.error('Sort error:', error);
          NotificationManager.error('Failed to process request');
          this.renderTable(tableId); // Revert to original order
        }
      }
    });

    return instance.sortable;
  },

  enableRowSort(tableId) {
    const instance = this.state.tables.get(tableId);
    if (!instance?.element) return;

    const theadRow = instance.element.querySelector('thead tr');
    if (theadRow && !theadRow.querySelector('th.drag-handle')) {
      const th = document.createElement('th');
      th.className = 'drag-handle';
      th.style.width = '2rem';

      const insertBefore = theadRow.querySelector('.check-column')?.nextSibling || theadRow.firstChild;
      theadRow.insertBefore(th, insertBefore);
    }

    instance.element.querySelectorAll('tbody tr').forEach(row => {
      if (!row.querySelector('.drag-handle')) {
        const handle = document.createElement('td');
        handle.className = 'drag-handle';
        handle.innerHTML = '⋮⋮';
        handle.style.cursor = 'move';
        handle.style.width = '2rem';
        handle.style.textAlign = 'center';
        const insertBefore = row.querySelector('.check-column')?.nextSibling || row.firstChild;
        row.insertBefore(handle, insertBefore);
      }
    });

    if (!instance.sortable) {
      this.setupSortableRows(tableId);
    }
  },

  disableRowSort(tableId) {
    const instance = this.state.tables.get(tableId);
    if (!instance?.element) return;

    // Remove drag handles
    instance.element.querySelectorAll('.drag-handle').forEach(handle => {
      handle.remove();
    });

    const dragHead = instance.element.querySelector('th.drag-handle');
    if (dragHead) dragHead.remove();

    if (instance.sortable) {
      instance.sortable.destroy();
      instance.sortable = null;
    }
  },

  /**
   * Toggle column visibility
   * @param {string} tableId - Table identifier
   * @param {string} fieldName - Field name of the column
   * @param {boolean} visible - True to show, false to hide
   */
  toggleColumnVisibility(tableId, fieldName, visible) {
    const table = this.state.tables.get(tableId);
    if (!table?.element || !table.columns) return;

    const columnAttr = table.columns.get(fieldName);
    if (!columnAttr) return;

    // Update column attribute
    columnAttr.visible = visible;

    // Update th element
    const th = table.element.querySelector(`thead th[data-field="${fieldName}"]`);
    if (th) {
      th.style.display = visible ? '' : 'none';
    }

    // Update all td elements in tbody
    table.element.querySelectorAll(`tbody td[data-field="${fieldName}"]`).forEach(td => {
      td.style.display = visible ? '' : 'none';
    });

    // Update empty row colspan if needed
    const emptyRow = table.element.querySelector('tbody tr td.empty-table');
    if (emptyRow) {
      emptyRow.colSpan = table.element.querySelectorAll('thead th:not([style*="display: none"])').length;
    }
  },

  invalidateTableDataSourceCache(tableId, sourceOverride = null) {
    try {
      const table = this.state.tables.get(tableId);
      const source = sourceOverride || table?.element?.dataset?.source || table?.config?.source || '';
      if (!source || !this.isUrlSource(source)) return 0;

      if (window.ApiService && typeof ApiService.invalidateCacheByUrl === 'function') {
        return ApiService.invalidateCacheByUrl(source);
      }
    } catch (error) {
      this.handleError('Invalidate table data source cache', error, 'invalidateCache');
    }
    return 0;
  },

  async loadTableData(tableId, options = {}) {
    const table = this.state.tables.get(tableId);
    if (!table?.element) return;

    const forceReload = options.force === true;

    this.clearSelection(table, tableId);

    try {
      const source = table.element.dataset.source;

      if (!source) {
        this.loadFromHtml(table);
        return;
      }

      this.showLoading(table);

      if (forceReload) {
        this.invalidateTableDataSourceCache(tableId, source);
      }

      if (source.endsWith('.json')) {
        await this.loadFromJson(tableId, source, options);
      } else if (this.isUrlSource(source)) {
        await this.loadFromApi(table, tableId, source, options);
      } else {
        this.loadFromState(tableId, source);
      }

    } catch (error) {
      this.handleError(`Error loading table data`, error, 'loadData');
    }
  },

  /**
   * Check if source is a URL/API endpoint (not a state key or JSON file)
   * URL patterns: contains '/', starts with 'http', has protocol
   * State keys: no slashes, dot-separated like 'state.users' or simple names
   * @param {string} source - The data source string
   * @returns {boolean}
   */
  isUrlSource(source) {
    if (!source || typeof source !== 'string') return false;
    // JSON files are handled separately
    if (source.endsWith('.json')) return false;
    // HTTP/HTTPS URLs
    if (source.startsWith('http://') || source.startsWith('https://')) return true;
    // Has path separator = URL path (relative or absolute)
    if (source.includes('/')) return true;
    return false;
  },

  isServerSideTable(table) {
    if (!table) return false;
    const source = table?.element?.dataset?.source || table?.config?.source || '';
    return this.isUrlSource(source);
  },

  nextEditableRowKey(table) {
    if (!table) return '';

    if (!table._rowIdentityCounter) {
      table._rowIdentityCounter = 0;
    }

    table._rowIdentityCounter++;
    return `row_${table._rowIdentityCounter}`;
  },

  ensureEditableRowKey(table, rowData) {
    if (!table?.config?.allowRowModification || !rowData || typeof rowData !== 'object') {
      return '';
    }

    if (rowData.__rowKey === undefined || rowData.__rowKey === null || rowData.__rowKey === '') {
      rowData.__rowKey = this.nextEditableRowKey(table);
    }

    return String(rowData.__rowKey);
  },

  getRowIdentity(table, rowData, index = 0) {
    if (!rowData || typeof rowData !== 'object') {
      return String(index);
    }

    if (table?.config?.allowRowModification) {
      return this.ensureEditableRowKey(table, rowData);
    }

    if (rowData.id !== undefined && rowData.id !== null) {
      return String(rowData.id);
    }

    return String(index);
  },

  findRowIndex(table, rowData) {
    if (!table || !Array.isArray(table.data) || !rowData) {
      return -1;
    }

    const rowIdentity = this.getRowIdentity(table, rowData);
    const index = table.data.findIndex(row => this.getRowIdentity(table, row) === rowIdentity);

    return index !== -1 ? index : table.data.indexOf(rowData);
  },

  createEmptyRow(table) {
    // Use counter instead of timestamp for predictable IDs
    // This ensures element IDs like tableId_field_new_1 are consistent
    if (!table._newRowCounter) {
      table._newRowCounter = 0;
    }
    table._newRowCounter++;

    const row = {id: table._newRowCounter};
    if (table?.columns instanceof Map) {
      table.columns.forEach((_, field) => {
        row[field] = '';
      });
    }
    this.ensureEditableRowKey(table, row);
    return row;
  },

  captureRowValues(table, tableId, item, index = 0) {
    if (!table?.element) return item;

    let tr = null;
    const rowIdentity = this.getRowIdentity(table, item, index);
    if (rowIdentity) {
      tr = table.element.querySelector(`tbody tr[data-id="${rowIdentity}"]`);
    }
    if (!tr) {
      tr = table.element.querySelectorAll('tbody tr')[index] || null;
    }
    if (!tr) return item;

    const updated = {...item};

    table.columns.forEach((_, field) => {
      const cell = tr.querySelector(`td[data-field="${field}"]`);
      if (!cell) return;

      let el = null;
      if (cell.dataset.elementId) {
        el = document.getElementById(cell.dataset.elementId);
      }
      if (!el) {
        el = cell.querySelector('input, select, textarea, [contenteditable="true"]');
      }

      if (el) {
        updated[field] = this.getFormElementValue(el);
      } else {
        updated[field] = cell.textContent;
      }
    });

    return updated;
  },

  async loadFromApi(table, tableId, source, options = {}) {
    const params = {
      ...this.getFilterParams(table),
      ...this.getSortParams(table),
      ...this.getPaginationParams(table)
    };

    await this.fetchAndSet(tableId, source, {
      method: table.config.method || 'GET',
      headers: {
        'Accept': 'application/json'
      },
      params,
      cache: options.force === true ? false : options.cache
    });
  },

  async loadFromJson(tableId, source, options = {}) {
    const requestOptions = {
      method: 'GET',
      headers: {
        'Accept': 'application/json'
      },
      cache: options.force === true ? false : options.cache
    };

    await this.fetchAndSet(tableId, source, requestOptions);
  },

  normalizeCacheTime(value, fallback = 60000) {
    const parsed = parseInt(value, 10);
    return Number.isFinite(parsed) && parsed >= 0 ? parsed : fallback;
  },

  getRequestCacheSettings(table, method = 'GET', cacheOverride) {
    if (method !== 'GET') {
      return {enabled: false, time: 0, override: cacheOverride};
    }

    if (typeof cacheOverride === 'boolean') {
      return {
        enabled: cacheOverride,
        time: this.normalizeCacheTime(table?.config?.cacheTime, this.config.cacheTime),
        override: null
      };
    }

    if (cacheOverride && typeof cacheOverride === 'object') {
      const enabled = cacheOverride.enabled !== undefined
        ? cacheOverride.enabled === true
        : table?.config?.cache === true;
      const overrideTime = cacheOverride.expiry?.get ?? cacheOverride.cacheTime;

      return {
        enabled,
        time: this.normalizeCacheTime(overrideTime, table?.config?.cacheTime ?? this.config.cacheTime),
        override: cacheOverride
      };
    }

    return {
      enabled: table?.config?.cache === true,
      time: this.normalizeCacheTime(table?.config?.cacheTime, this.config.cacheTime),
      override: null
    };
  },

  buildApiRequestOptions(table, method, options = {}) {
    const {
      headers = {},
      credentials,
      cache: cacheOverride,
      ...restOptions
    } = options;

    const defaultCredentials = credentials || (window.ApiService?.config?.security?.sendCredentials ? 'include' : 'same-origin');
    const baseCache = window.ApiService?.config?.cache && typeof window.ApiService.config.cache === 'object'
      ? window.ApiService.config.cache
      : {};
    const cacheSettings = this.getRequestCacheSettings(table, method, cacheOverride);

    if (cacheSettings.enabled) {
      return {
        ...restOptions,
        headers: {
          ...headers
        },
        credentials: defaultCredentials,
        cache: {
          ...baseCache,
          ...(cacheSettings.override || {}),
          enabled: true,
          storageType: cacheSettings.override?.storageType || baseCache.storageType || 'memory',
          expiry: {
            ...(baseCache.expiry || {}),
            ...((cacheSettings.override && cacheSettings.override.expiry) || {}),
            get: cacheSettings.time
          }
        }
      };
    }

    return {
      ...restOptions,
      headers: {
        'Cache-Control': 'no-cache',
        'Pragma': 'no-cache',
        ...headers
      },
      credentials: defaultCredentials,
      deduplicate: false,
      cache: {
        ...baseCache,
        ...(cacheSettings.override || {}),
        enabled: false,
        storageType: 'no-store',
        expiry: {
          ...(baseCache.expiry || {}),
          ...((cacheSettings.override && cacheSettings.override.expiry) || {}),
          get: 0
        }
      }
    };
  },

  async fetchAndSet(tableId, url, options = {}) {
    try {
      const {
        method = 'GET',
        headers = {},
        params,
        body,
        data,
        credentials,
        cache: requestCache,
        ...restOptions
      } = options;

      const upperMethod = method.toUpperCase();
      const payload = typeof body !== 'undefined' ? body : data;
      let response;
      const tableInstance = this.state.tables.get(tableId);
      const apiOptions = this.buildApiRequestOptions(tableInstance, upperMethod, {
        ...restOptions,
        headers,
        credentials,
        cache: requestCache
      });

      const methodName = upperMethod.toLowerCase();

      if (upperMethod === 'GET') {
        response = await ApiService.get(url, params || {}, apiOptions);
      } else if (typeof ApiService[methodName] === 'function') {
        response = await ApiService[methodName](url, payload, {
          ...apiOptions,
          params
        });
      } else {
        throw new Error(`Unsupported request method: ${upperMethod}`);
      }

      // Handle 403 Forbidden - redirect to 403 page
      if (response?.status === 403) {
        console.warn('TableManager: Access forbidden (403) for table data');

        const forbiddenMessage = response?.data?.message || response?.data?.data?.message || '';
        const forbiddenParams = forbiddenMessage ? {message: forbiddenMessage} : {};
        const forbiddenUrl = forbiddenMessage ? `/403?message=${encodeURIComponent(forbiddenMessage)}` : '/403';

        if (window.RouterManager?.navigate) {
          window.RouterManager.navigate('/403', forbiddenParams);
          return;
        }

        if (window.LocationManager?.redirect) {
          window.LocationManager.redirect(forbiddenUrl);
          return;
        }

        window.location.href = forbiddenUrl;
        return;
      }

      // Handle 401 Unauthorized - redirect to login
      if (response?.status === 401) {
        console.warn('TableManager: Unauthorized (401) for table data');

        if (window.RouterManager?.navigate) {
          window.RouterManager.navigate('/login');
          return;
        }

        if (window.LocationManager?.redirect) {
          window.LocationManager.redirect('/login');
          return;
        }

        window.location.href = '/login';
        return;
      }
      if (response?.success === false) {
        throw new Error(response?.message || response?.statusText || `{LNG_Request failed.} (${response?.status || 'unknown'})`);
      }

      let responseData;
      if (response?.data) {
        if (response.data.columns || response.data.meta || response.data.filters) {
          // Has metadata alongside data - use the whole object
          responseData = response.data;
        } else if (response.data.data) {
          // No metadata, but has nested data - unwrap it
          responseData = response.data.data;
        } else {
          // Just use response.data as is
          responseData = response.data;
        }
      } else {
        responseData = response;
      }

      if (tableInstance && responseData && responseData.meta && typeof responseData.meta === 'object') {
        tableInstance.serverSide = true;
      }

      this.setData(tableId, responseData);
    } catch (error) {
      this.handleError('Fetch error', error, 'fetchAndSet');
    }
  },

  loadFromHtml(table, tableId) {
    // Support being passed either the internal table state object or a DOM element.
    let tableObj = null;
    let tableEl = null;
    let finalTableId = tableId;

    if (!table) return;

    if (table.element) {
      // table is the internal state object
      tableObj = table;
      tableEl = table.element;
      finalTableId = finalTableId || tableObj.id || (tableEl && tableEl.dataset && tableEl.dataset.table);
    } else if (table instanceof Element) {
      // table is a DOM element
      tableEl = table;
      finalTableId = finalTableId || (tableEl.dataset && tableEl.dataset.table);
      tableObj = this.state.tables.get(finalTableId) || {element: tableEl, id: finalTableId};
    } else {
      // unknown input
      return;
    }

    if (!tableEl) return;

    // Prevent race condition: Skip if table already has data from API or is loading
    // This stops loadFromHtml from overwriting API data with empty array
    if (tableObj) {
      // Skip if data already loaded from API
      if (tableObj.data && tableObj.data.length > 0) return;
      // Skip if currently loading from API
      if (tableObj.loading) return;
    }

    const headers = [...tableEl.querySelectorAll('th')]
      .map(th => ({
        field: th.dataset.field || th.textContent.trim().toLowerCase(),
        sort: th.dataset.sort,
        formatter: th.dataset.formatter
      }));

    const rawRows = [...tableEl.querySelectorAll('tbody tr')]
      .map(tr => {
        const row = {};
        [...tr.cells].forEach((cell, index) => {
          const header = headers[index] || {field: `col_${index}`};
          row[header.field] = {
            text: cell.textContent.trim(),
            html: cell.innerHTML
          };
        });
        return row;
      });

    // Build normalized rows where each cell is a primitive (string) for filtering/sorting
    const normalizedRows = rawRows.map(r => {
      const nr = {};
      Object.entries(r).forEach(([k, v]) => {
        if (v === null || v === undefined) {
          nr[k] = v;
        } else if (typeof v === 'object') {
          nr[k] = v.text !== undefined ? v.text : (v.html !== undefined ? v.html : JSON.stringify(v));
        } else {
          nr[k] = v;
        }
      });
      return nr;
    });

    // store originalData on the internal table object if available
    try {
      if (tableObj) tableObj.originalData = rawRows;
    } catch (e) {
      // ignore
    }

    // Ensure we pass a valid tableId into setData using normalized primitive data
    if (finalTableId) {
      this.setData(finalTableId, normalizedRows);
    } else {
      // fallback: attempt to find tableId by matching element reference
      for (const [tid, t] of this.state.tables.entries()) {
        if (t.element === tableEl) {
          this.setData(tid, normalizedRows);
          return;
        }
      }
      // If still not found, log and skip
      console.warn('TableManager: unable to determine tableId for HTML data load');
    }
  },

  loadFromState(tableId, stateKey) {
    // Prefer using the registered state manager if available
    const stateManager = Now.getManager ? Now.getManager('state') : null;
    if (!stateManager || typeof stateManager.get !== 'function') {
      throw new Error('Now.js state manager not available');
    }

    // Try to read current value first
    const data = stateManager.get(stateKey);
    if (data) {
      this.setData(tableId, data);
      return;
    }

    // If no data yet, try to subscribe for future updates (StateManager should provide subscribe)
    if (typeof stateManager.subscribe === 'function') {
      const handler = (newValue) => {
        if (!newValue) return;
        try {
          this.setData(tableId, newValue);
        } catch (err) {
          console.error('Error applying state update to table:', err);
        } finally {
          // Unsubscribe after first successful set if possible
          if (typeof stateManager.unsubscribe === 'function') {
            stateManager.unsubscribe(stateKey, handler);
          } else if (typeof stateManager.off === 'function') {
            stateManager.off(stateKey, handler);
          }
        }
      };

      try {
        stateManager.subscribe(stateKey, handler);
      } catch (err) {
        console.warn(`Unable to subscribe to state key ${stateKey}:`, err);
      }
      return;
    }

    // Fallback: no data and no subscribe method — leave table empty but do not throw
    console.warn(`State key ${stateKey} not found and state manager has no subscribe; table ${tableId} will remain empty until data is set`);
  },

  setData(tableId, data) {
    if (!tableId) {
      throw new Error('Invalid parameters');
    }

    let normalized = null;
    if (Array.isArray(data)) {
      normalized = {
        data,
        meta: (data && data.meta) ? {...data.meta} : {total: data.length},
        filters: (data && data.filters) ? data.filters : {},
        options: (data && data.options) ? data.options : {},
        columns: (data && data.columns) ? data.columns : undefined,
        raw: data
      };
    } else if (data && Array.isArray(data.data)) {
      normalized = {
        ...data,
        data: data.data,
        meta: data.meta ? {...data.meta} : {total: data.data.length},
        filters: data.filters || {},
        options: data.options || {},
        columns: data.columns || undefined,
        raw: data
      };
    } else {
      throw new Error('Invalid data payload: expected canonical schema {data:[], meta:{page: 1, pageSize: 20, total: 0}, filters:{}, options:{}}');
    }

    const table = this.state.tables.get(tableId);
    if (!table) return;

    try {
      // Handle dynamic columns from API response
      let columnsMetadata = null;

      if (table.config.dynamicColumns) {
        // Check normalized.columns (from API response)
        if (normalized.columns && Array.isArray(normalized.columns)) {
          columnsMetadata = normalized.columns;
        }
        // If using nested data (data-attr), check in normalized.data.columns
        else if (normalized.data && normalized.data.columns && Array.isArray(normalized.data.columns)) {
          columnsMetadata = normalized.data.columns;
        }
      }

      if (columnsMetadata) {
        const existingThead = table.element.querySelector('thead');
        const shouldRebuildHeaders = !existingThead
          || existingThead.querySelectorAll('th[data-field]').length === 0
          || !this.dynamicHeadersMatch(existingThead, columnsMetadata);

        if (shouldRebuildHeaders) {
          // Create new thead from column metadata
          const newThead = this.createDynamicHeaders(table, columnsMetadata);

          // Remove old thead if exists
          if (existingThead) {
            this.cleanupSortHandlers(table, existingThead);
            existingThead.remove();
          }

          // Insert new thead before tbody
          const tbody = table.element.querySelector('tbody');
          table.element.insertBefore(newThead, tbody);

          // Translate headers if i18n is available
          if (window.Now && window.Now.translate) {
            newThead.querySelectorAll('[data-i18n]').forEach(el => {
              const original = el.textContent.trim();
              if (original) {
                el.textContent = window.Now.translate(original);
              }
            });
          }

          // Update column definitions from new thead
          table.columns = this.getColumnDefinitions(table);

          // Setup sortable headers with event listeners
          newThead.querySelectorAll('th[data-sort]').forEach(th => {
            th.classList.add('sortable');
            th.setAttribute('role', 'columnheader');
            th.setAttribute('tabindex', '0');
            th.setAttribute('aria-sort', 'none');

            // Bind sort event listeners
            const sortHandler = (e) => {
              e.preventDefault();
              e.stopPropagation();
              if (e.shiftKey) {
                window.getSelection()?.removeAllRanges();
              }
              this.handleSort(table, tableId, th, e);
            };

            const keyHandler = (e) => {
              if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                this.handleSort(table, tableId, th, e);
              }
            };

            th.addEventListener('click', sortHandler);
            th.addEventListener('keydown', keyHandler);

            // Store handlers for cleanup
            if (!table.eventHandlers) table.eventHandlers = {sort: new Map()};
            if (!table.eventHandlers.sort) table.eventHandlers.sort = new Map();
            table.eventHandlers.sort.set(th, {
              sort: sortHandler,
              key: keyHandler
            });
          });



          // Create tfoot for dynamic headers if showFooter is enabled
          if (table.config.showFooter) {
            this.ensureFooterStructure(
              table,
              newThead.querySelector('tr:last-child') || newThead.querySelector('tr')
            );
          }

          // Setup checkboxes for dynamic headers (both thead and tfoot)
          this.setupCheckboxes(table, tableId);

          // Setup filters for new headers
          this.setupFilter(table, tableId);

          // Setup column resizing for dynamic headers
          this.setupColumnResizing(tableId);

          // Setup accessibility after all components are created
          this.setupAccessibility(table.element, tableId, table.config);

          // Update search columns from column metadata if not already set
          if (!table.config.searchColumns || table.config.searchColumns.length === 0) {
            const searchableFields = columnsMetadata
              .filter(col => col.searchable === true)
              .map(col => col.field);

            if (searchableFields.length > 0) {
              table.config.searchColumns = searchableFields.join(',');
            }
          }
        }
      }

      // Store options from API response for data formatting (e.g., lookup)
      if (normalized.options && Object.keys(normalized.options).length) {
        if (!table.dataOptions) table.dataOptions = {};
        Object.assign(table.dataOptions, normalized.options);

        const runtimeTableOptions = normalized.options._table;
        if (runtimeTableOptions && typeof runtimeTableOptions === 'object') {
          const previousShowCheckbox = !!table.config.showCheckbox;
          const previousCheckboxCondition = table.config.checkboxCondition || '';
          const previousActions = JSON.stringify(table.config.actions || {});
          const previousActionButton = table.config.actionButton;
          const previousActionSelectLabel = table.config.actionSelectLabel;

          if (Object.prototype.hasOwnProperty.call(runtimeTableOptions, 'showCheckbox')) {
            table.config.showCheckbox = runtimeTableOptions.showCheckbox === true;
          }
          if (Object.prototype.hasOwnProperty.call(runtimeTableOptions, 'checkboxCondition')) {
            table.config.checkboxCondition = runtimeTableOptions.checkboxCondition || '';
          }
          if (Object.prototype.hasOwnProperty.call(runtimeTableOptions, 'actions')) {
            table.config.actions = runtimeTableOptions.actions && typeof runtimeTableOptions.actions === 'object'
              ? {...runtimeTableOptions.actions}
              : {};
          }
          if (Object.prototype.hasOwnProperty.call(runtimeTableOptions, 'actionButton')) {
            table.config.actionButton = runtimeTableOptions.actionButton;
          }
          if (Object.prototype.hasOwnProperty.call(runtimeTableOptions, 'actionSelectLabel')) {
            table.config.actionSelectLabel = runtimeTableOptions.actionSelectLabel;
          }

          const checkboxChanged = previousShowCheckbox !== table.config.showCheckbox
            || previousCheckboxCondition !== table.config.checkboxCondition;
          const actionsChanged = previousActions !== JSON.stringify(table.config.actions || {})
            || previousActionButton !== table.config.actionButton
            || previousActionSelectLabel !== table.config.actionSelectLabel;

          if (checkboxChanged && table.config.showCheckbox) {
            this.setupCheckboxes(table, tableId);
          }
          if (checkboxChanged || actionsChanged) {
            this.setupActions(table, tableId);
          }
        }

        // Populate external filter form options if available
        if (table.externalFilterForm) {
          this.populateExternalFilterOptions(table, normalized.options);
        }
      }

      // Also check if filters contain option arrays and store them as dataOptions
      if (normalized.filters && Object.keys(normalized.filters).length) {
        this.setFilters(tableId, normalized.filters);

        // Populate external filter form options from filters
        if (table.externalFilterForm) {
          this.populateExternalFilterOptions(table, normalized.filters);
        }

        // Extract options from filters for data formatting
        if (!table.dataOptions) table.dataOptions = {};
        Object.entries(normalized.filters).forEach(([key, value]) => {
          if (Array.isArray(value) && value.length > 0) {
            // Convert array to object for lookup
            const optionsMap = {};
            value.forEach(item => {
              if (item && typeof item === 'object') {
                const val = item.value !== undefined ? item.value : (item.key !== undefined ? item.key : item);
                const label = item.label || item.text || String(item);
                optionsMap[val] = label;
              } else {
                optionsMap[item] = String(item);
              }
            });
            table.dataOptions[key] = optionsMap;
          }
        });
      }

      // Parse meta values
      const metaPage = parseInt(normalized.meta.page || table.config.params.page);
      const metaPageSize = parseInt(normalized.meta.pageSize || table.config.params.pageSize);
      const metaTotal = parseInt(normalized.meta.total || 0);
      const metaTotalPages = parseInt(normalized.meta.totalPages || Math.max(1, Math.ceil(metaTotal / metaPageSize)));

      // Auto-correct page if it exceeds totalPages (e.g., from stale URL params)
      const correctedPage = metaPage > metaTotalPages ? metaTotalPages : metaPage;

      const {
        sort: _metaSort,
        order: _metaOrder,
        ...metaParams
      } = normalized.meta || {};

      const {
        sort: _currentSort,
        order: _currentOrder,
        ...currentParams
      } = table.config.params || {};

      table.config.params = {
        ...currentParams,
        ...metaParams,
        page: correctedPage,
        pageSize: metaPageSize,
        total: metaTotal,
        totalPages: metaTotalPages
      };

      // Ensure filter UI matches the updated params (e.g. from/to dates from meta)
      this.restoreFilterUIFromState(tableId);


      // Extract table data - handle both array and nested object structures
      let tableContent = normalized.data || [];

      // If normalized.data is an object with a 'data' property, use that instead
      if (!Array.isArray(tableContent) && tableContent.data && Array.isArray(tableContent.data)) {
        tableContent = tableContent.data;
      }

      if (!Array.isArray(tableContent)) {
        throw new Error('Invalid data format: expected array');
      }
      if (table.config.allowRowModification) {
        tableContent.forEach(row => {
          this.ensureEditableRowKey(table, row);
        });
      }

      table.data = tableContent;
      this.bindLoadTarget(tableId, normalized);

      // If API/state did not provide filter option lists, derive them from data for select filters
      try {
        const derived = this.deriveFiltersFromData(table);
        if (derived && Object.keys(derived).length) {
          // If filter elements already exist, apply immediately; otherwise store for setupFilter to apply
          if (table.filterElements && (typeof table.filterElements.size === 'number' ? table.filterElements.size > 0 : Object.keys(table.filterElements).length > 0)) {
            this.setFilters(tableId, derived);
          } else {
            table.pendingDerivedFilters = derived;
          }
        }
      } catch (err) {
        console.warn('Failed to derive filter options from data:', err);
      }

      // Sync sortState from API response if sortState is empty (initial load)
      // This ensures TableManager knows the actual sort order used by API
      if (normalized.meta.sort && Object.keys(table.sortState).length === 0) {
        const sortString = normalized.meta.sort;
        const sortPairs = sortString.split(',').map(pair => pair.trim());

        sortPairs.forEach(pair => {
          const parts = pair.split(/\s+/);
          if (parts.length >= 2) {
            const field = parts[0];
            const direction = parts[1].toLowerCase();
            if (['asc', 'desc'].includes(direction)) {
              table.sortState[field] = direction;
            }
          } else if (parts.length === 1 && parts[0]) {
            table.sortState[parts[0]] = 'asc';
          }
        });
      }

      // Don't reset sortState - preserve user's sort selection
      // Only reset if explicitly requested via options
      if (normalized.resetSort === true) {
        table.sortState = {};
      }

      // Only render if not initializing to prevent redundant renders
      if (!table.initializing) {
        this.renderTable(tableId);
      }
    } catch (error) {
      this.handleError('Setting table data', error, 'setData');
      table.data = [];

      // Only render if not initializing
      if (!table.initializing) {
        this.renderTable(tableId);
      }
    }
  },

  dynamicHeadersMatch(thead, columnsMetadata) {
    if (!thead || !Array.isArray(columnsMetadata)) {
      return false;
    }

    const headerCells = Array.from(thead.querySelectorAll('th[data-field]'));
    if (headerCells.length !== columnsMetadata.length) {
      return false;
    }

    return columnsMetadata.every((column, index) => {
      const headerCell = headerCells[index];
      if (!headerCell) {
        return false;
      }

      const expectedField = String(column?.field || '');
      const expectedLabel = String(column?.label || column?.field || '').trim();
      const expectedSort = column?.sort !== undefined ? String(column.sort) : '';
      const expectedFilter = column?.filter !== undefined ? String(column.filter) : '';
      const expectedType = column?.type !== undefined ? String(column.type) : '';
      const expectedCellElement = column?.cellElement !== undefined ? String(column.cellElement) : '';
      const expectedFormatter = column?.formatter !== undefined ? String(column.formatter) : '';
      const expectedVisible = column?.visible === false || column?.hidden === true ? 'false' : 'true';

      const actualField = String(headerCell.dataset.field || '');
      const actualLabel = headerCell.textContent.trim();
      const actualSort = headerCell.dataset.sort || '';
      const actualFilter = headerCell.dataset.filter || '';
      const actualType = headerCell.dataset.type || '';
      const actualCellElement = headerCell.dataset.cellElement || '';
      const actualFormatter = headerCell.dataset.formatter || '';
      const actualVisible = headerCell.style.display === 'none' ? 'false' : 'true';

      return actualField === expectedField
        && actualLabel === expectedLabel
        && actualSort === expectedSort
        && actualFilter === expectedFilter
        && actualType === expectedType
        && actualCellElement === expectedCellElement
        && actualFormatter === expectedFormatter
        && actualVisible === expectedVisible;
    });
  },

  cleanupSortHandlers(table, root = null) {
    if (!table?.eventHandlers?.sort || !(table.eventHandlers.sort instanceof Map)) {
      return;
    }

    table.eventHandlers.sort.forEach((handlers, th) => {
      if (root && !root.contains(th)) {
        return;
      }

      th.removeEventListener('click', handlers.sort);
      th.removeEventListener('keydown', handlers.key);
      table.eventHandlers.sort.delete(th);
    });
  },

  setFilters(tableId, filters) {
    const table = this.state.tables.get(tableId);
    if (!table) return;

    try {
      table.filterOptions = filters;
      Object.entries(filters).forEach(([key, values]) => {
        const filterElement = table.filterData.get(key);
        const attributes = table.columns.get(key);
        if (filterElement && filterElement.element) {
          // Build ordered array of options: [{value, text}, ...]
          // Normalize values (can be Map, Array, or plain object)
          const optionsArray = [];
          const existingKeys = new Set();

          if (values instanceof Map) {
            for (const [v, label] of values.entries()) {
              const sval = String(v);
              optionsArray.push({value: v, text: label});
              existingKeys.add(sval);
            }
          } else if (Array.isArray(values)) {
            values.forEach(item => {
              if (item && typeof item === 'object') {
                const val = item.value !== undefined ? item.value : (item.key !== undefined ? item.key : item);
                const txt = item.text || item.label || String(item);
                optionsArray.push({value: val, text: txt});
                existingKeys.add(String(val));
              } else {
                optionsArray.push({value: item, text: String(item)});
                existingKeys.add(String(item));
              }
            });
          } else if (typeof values === 'object' && values !== null) {
            // plain object: iterate entries in insertion order
            Object.entries(values).forEach(([v, label]) => {
              optionsArray.push({value: v, text: label});
              existingKeys.add(String(v));
            });
          }

          // If configured to show an "All" option, add it first only if the
          // API response doesn't already include that value. DOM options are
          // intentionally NOT checked here: updateOptions() always clears
          // innerHTML before rebuilding, so including stale DOM values in
          // existingKeys would incorrectly suppress the All option on every
          // subsequent setFilters call (e.g. when a URL param triggers a
          // second fetch after the first render already added the All option).
          if (attributes && attributes.showAll) {
            const allVal = (attributes.allValue !== undefined) ? attributes.allValue : '';
            const allValStr = String(allVal);
            if (!existingKeys.has(allValStr)) {
              optionsArray.unshift({value: allVal, text: (attributes.allLabel || 'All')});
            }
          }

          // Prefer element-based lookup to avoid stale id-only instances
          let emInstance = null;
          try {
            if (ElementManager && typeof ElementManager.getInstanceByElement === 'function') {
              emInstance = ElementManager.getInstanceByElement(filterElement.element) || null;
            }
            if (!emInstance && filterElement.element?.id && typeof ElementManager.getInstance === 'function') {
              emInstance = ElementManager.getInstance(filterElement.element.id) || null;
              if (emInstance && emInstance.element && emInstance.element !== filterElement.element && !emInstance.element.isConnected) {
                try {ElementManager.destroy(filterElement.element.id);} catch (e) {}
                emInstance = null;
              }
            }
          } catch (e) {
            emInstance = null;
          }

          if (emInstance?.updateOptions && typeof emInstance.updateOptions === 'function') {
            emInstance.updateOptions(optionsArray);

            // Restore selected value from config params (URL state) after updating options
            let valueToRestore = null;
            if (table.config.params[key] !== undefined && table.config.params[key] !== null && table.config.params[key] !== '') {
              valueToRestore = table.config.params[key];
            } else if (filterElement.element.value !== undefined) {
              valueToRestore = filterElement.element.value;
            }

            if (valueToRestore !== null) {
              // Set value through instance if available
              if (emInstance.setValue && typeof emInstance.setValue === 'function') {
                emInstance.setValue(valueToRestore);
              } else if (filterElement.element) {
                filterElement.element.value = valueToRestore;
              }
            }
          } else {
            // Fallback: try to update DOM select element directly
            try {
              const el = filterElement.element;
              // If wrapper contains select, prefer that
              const selectEl = (el && el.tagName === 'SELECT') ? el : (el && el.querySelector ? el.querySelector('select') : null);
              if (selectEl) {
                selectEl.innerHTML = '';
                optionsArray.forEach(opt => {
                  const option = document.createElement('option');
                  option.value = opt.value;
                  option.textContent = opt.text;
                  selectEl.appendChild(option);
                });

                // Restore selected value from config params (URL state) or current element value
                let valueToRestore = null;
                if (table.config.params[key] !== undefined && table.config.params[key] !== null && table.config.params[key] !== '') {
                  // Prioritize URL/config params
                  valueToRestore = table.config.params[key];
                } else if (filterElement.element.value !== undefined) {
                  // Fallback to current element value
                  valueToRestore = filterElement.element.value;
                }

                if (valueToRestore !== null) {
                  // Check if the value exists in options
                  const hasOption = Array.from(selectEl.options).some(opt => opt.value === String(valueToRestore));
                  if (hasOption) {
                    selectEl.value = valueToRestore;
                  }
                }
              } else if (el && el.updateOptions && typeof el.updateOptions === 'function') {
                el.updateOptions(optionsArray);
              } else {
                console.warn('No method found to update filter element options for', key);
              }
            } catch (err) {
              console.warn('Failed DOM fallback for updating filter options', err);
            }
          }
        }
      });

      // After updating all filter options, restore UI state from config params
      // This ensures URL parameters are applied after API options are loaded
      this.restoreFilterUIFromState(tableId);

    } catch (error) {
      console.error('Error setting filters:', error);
    }
  },

  deriveFiltersFromData(table) {
    if (!table || !Array.isArray(table.data)) return {};

    const result = {};
    // Only derive for columns configured as filterable and of select type
    table.columns.forEach((attributes, field) => {
      if (!attributes.filter) return;
      // If options already provided via attributes or filterOptions, skip deriving
      if ((attributes.options && Object.keys(attributes.options).length) || (table.filterOptions && table.filterOptions[field])) return;

      const values = new Map();
      table.data.forEach(row => {
        const val = row[field];
        if (val === undefined || val === null) return;
        const key = typeof val === 'object' ? (val.value !== undefined ? val.value : (val.text !== undefined ? val.text : JSON.stringify(val))) : val;
        if (!values.has(key)) values.set(key, String(key));
      });

      // Convert Map to array of {value,text} for deterministic ordering
      if (values.size > 0) {
        result[field] = Array.from(values.entries()).map(([value, text]) => ({value, text}));
      }
    });

    return result;
  },

  renderTable(tableId) {
    const table = this.state.tables.get(tableId);
    if (!table) return;

    if (!this.isElementManagerReady()) {
      this.deferRenderUntilReady(tableId);
      return;
    }

    table._renderRetryCount = 0;
    if (table._renderRetryTimer) {
      clearTimeout(table._renderRetryTimer);
      table._renderRetryTimer = null;
    }

    const {element: tableEl, config} = table;

    const tbody = tableEl.querySelector('tbody');
    if (!tbody) return;

    try {
      tbody.innerHTML = '';

      // Check if data comes from API (server-side)
      const source = tableEl.dataset.source || config.source || '';
      const hasApiSource = this.isUrlSource(source);

      // Also check if response has meta.total which indicates server-side pagination
      const hasServerMeta = table.serverSide || (config.params.total !== undefined && config.params.total !== (table.data || []).length);

      const isServerSide = hasApiSource || hasServerMeta;

      // Ensure at least one editable row exists so add/copy controls are visible
      if (config.allowRowModification && (!Array.isArray(table.data) || table.data.length === 0)) {
        table.data = [this.createEmptyRow(table)];
      }

      const baseData = table.data || [];

      let filteredData = baseData;
      let pageData = baseData;
      let totalPages = 1;
      let totalRecords = baseData.length;

      if (isServerSide) {
        // Server-side: API already filtered, sorted, and paginated
        // Just display the data as-is
        pageData = baseData;
        totalRecords = config.params.total || baseData.length;
        totalPages = config.params.totalPages || Math.ceil(totalRecords / (config.params.pageSize || baseData.length || 1));
      } else {
        // Client-side: apply filtering, sorting, and pagination
        filteredData = this.filterData(table, baseData);
        filteredData = this.sortData(table, filteredData);
        const paginationResult = this.paginateData(table, filteredData);
        pageData = paginationResult.pageData;
        totalPages = paginationResult.totalPages;
        totalRecords = paginationResult.totalRecords;
      }

      if (pageData.length === 0) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        // Count only visible columns
        td.colSpan = tableEl.querySelectorAll('thead th:not([style*="display: none"])').length;
        td.className = 'empty-table';
        td.textContent = Now.translate('No data available');
        tr.appendChild(td);
        tbody.appendChild(tr);
      } else {
        pageData.forEach((item, index) => {
          const tr = this.renderRow(table, tableId, item, index);
          tbody.appendChild(tr);
        });
      }

      if (config.rowSortable === true) {
        this.enableRowSort(tableId);
      } else {
        this.disableRowSort(tableId);
      }

      // Restore sort UI state on headers
      this.restoreSortUI(table);

      this.setupFooter(table);
      if (config.showCheckbox) {
        this.setupCheckboxes(table, tableId);
      }

      if (config.params.pageSize > 0) {
        this.updatePagination(table, tableId, totalRecords, totalPages);
      }

      EventManager.emit('table:render', {
        tableId,
        data: pageData,
        totalRecords: isServerSide ? totalRecords : baseData.length,
        filteredRecords: isServerSide ? totalRecords : filteredData.length,
        isServerSide
      });
      // Run data-on-load handlers on the table element (only for API/server-side loads)
      // We call this once after the table has finished loading and rendering from the API.
      if (window.TemplateManager && typeof TemplateManager.processDataOnLoad === 'function' && isServerSide) {
        try {
          const context = {
            state: {data: baseData},
            data: baseData
          };
          TemplateManager.processDataOnLoad(tableEl, context);
        } catch (err) {
          console.warn('TableManager: data-on-load after render failed', err);
        }
      }
    } catch (error) {
      this.handleError('Rendering table', error, 'render');
    }
  },

  restoreSortUI(table) {
    if (!table || !table.element) return;

    // First, clear all sort classes and aria-sort
    table.element.querySelectorAll('th[data-sort]').forEach(th => {
      th.classList.remove('sort_asc', 'sort_desc');
      th.setAttribute('aria-sort', 'none');
    });

    // Then, apply current sort state
    if (table.sortState && Object.keys(table.sortState).length > 0) {
      Object.entries(table.sortState).forEach(([column, direction]) => {
        const th = table.element.querySelector(`th[data-sort="${column}"]`);
        if (th) {
          th.classList.add(direction === 'asc' ? 'sort_asc' : 'sort_desc');
          th.setAttribute('aria-sort', direction === 'asc' ? 'ascending' : 'descending');
        }
      });
    }

    // Update sort order indicators for multi-column sort
    this.updateSortOrderIndicators(table);
  },

  renderRow(table, tableId, item, index) {
    const tr = document.createElement('tr');
    const rowIdentity = this.getRowIdentity(table, item, index);

    if (rowIdentity !== '') {
      tr.dataset.id = rowIdentity;
    }

    if (table.config.showCheckbox && this.evaluateTableCondition(table.config.checkboxCondition, item)) {
      const td = document.createElement('td');
      td.className = 'check-column';

      const checkboxId = `select-row-${tableId}-${rowIdentity || index}`;
      const checkboxValue = table.config.allowRowModification
        ? rowIdentity
        : (item && item.id !== undefined && item.id !== null ? item.id : index);

      const label = document.createElement('label');
      label.htmlFor = checkboxId;

      const checkbox = document.createElement('input');
      checkbox.type = 'checkbox';
      checkbox.className = 'select-row';
      checkbox.id = checkboxId;
      checkbox.value = checkboxValue;
      checkbox.addEventListener('change', () => {
        this.handleRowSelection(table, tableId);
      });

      label.appendChild(checkbox);
      td.appendChild(label);
      tr.appendChild(td);
    }

    if (table.config.rowSortable === true) {
      const handle = document.createElement('td');
      handle.className = 'drag-handle';
      handle.innerHTML = '⋮⋮';
      handle.style.cursor = 'move';
      handle.style.width = '2rem';
      handle.style.textAlign = 'center';
      tr.appendChild(handle);
    }

    table.columns.forEach((attributes, field) => {
      // Editable rows still need hidden cells rendered so their form inputs submit.
      if (attributes.visible === false && table.config.allowRowModification !== true) return;

      this.renderCell(table, tableId, tr, field, attributes, item, index);
    });

    if (table.config.allowRowModification) {
      const td = document.createElement('td');
      td.className = 'icons';
      const div = document.createElement('div');
      const buttons = [
        {
          action: 'copy',
          title: Now.translate('Copy'),
          text: '+',
          className: 'plus',
          handler: async () => this.handleCopyRow(table, tableId, item)
        },
        {
          action: 'delete',
          title: Now.translate('Delete'),
          text: '-',
          className: 'minus',
          handler: async () => this.handleDeleteRow(table, tableId, item)
        }
      ];

      buttons.forEach(btn => {
        const button = document.createElement('button');
        const disabledKey = `_row${btn.action.charAt(0).toUpperCase() + btn.action.slice(1)}Disabled`;
        const disabledReasonKey = `${disabledKey}Reason`;
        const isDisabled = item && item[disabledKey] === true;
        const disabledReason = isDisabled ? (item[disabledReasonKey] || btn.title) : '';
        button.type = 'button';
        button.className = btn.className;
        button.title = isDisabled && disabledReason ? disabledReason : btn.title;
        button.innerText = btn.text;
        button.setAttribute('aria-label', isDisabled && disabledReason ? disabledReason : btn.title);
        button.disabled = isDisabled;
        if (isDisabled) {
          button.setAttribute('aria-disabled', 'true');
        }

        button.addEventListener('click', async (e) => {
          e.preventDefault();
          if (isDisabled) {
            return;
          }
          await btn.handler();
        });

        div.appendChild(button);
      });

      td.appendChild(div);
      tr.appendChild(td);
    }

    // If table defines data-row-actions, render action cell (uses data-row-actions JSON)
    const rowActionsRaw = table.element.dataset.rowActions || table.element.dataset.rowActionsJson;
    if (rowActionsRaw) {
      try {
        const actions = this.parseRowActions(rowActionsRaw);
        const actionCell = this.createActionCell(table, tableId, item, actions);
        if (actionCell) {
          tr.appendChild(actionCell);
        }
      } catch (err) {
        console.warn('Invalid data-row-actions for table', tableId, err);
      }
    }

    return tr;
  },

  getRowSequence(table, index) {
    const params = table?.config?.params || {};
    const page = Math.max(1, parseInt(params.page, 10) || 1);
    const pageSize = Math.max(0, parseInt(params.pageSize, 10) || 0);

    return pageSize > 0
      ? ((page - 1) * pageSize) + index + 1
      : index + 1;
  },

  /**
   * Insert a freshly rendered row into the DOM after a specific row (by source ID),
   * without re-rendering the entire table. This preserves unsaved edits in other rows.
   *
   * @param {Object} table     - Table instance from state
   * @param {string} tableId   - Table identifier
   * @param {Object} newItem   - Data object for the new row (must have .id set)
   * @param {string} afterId   - data-id of the row to insert after; appends if not found
   */
  insertRowAfterInDom(table, tableId, newItem, afterId) {
    const tbody = table.element.querySelector('tbody');
    if (!tbody) {
      // Fallback: full re-render if tbody is missing
      this.renderTable(tableId);
      return;
    }

    // Determine the display index of the new item inside table.data
    const newRowIdentity = this.getRowIdentity(table, newItem);
    const dataIdx = Array.isArray(table.data)
      ? table.data.findIndex(r => this.getRowIdentity(table, r) === newRowIdentity)
      : -1;
    const displayIndex = dataIdx >= 0 ? dataIdx : tbody.children.length;

    // Build the new <tr> using the existing renderRow pipeline
    const newTr = this.renderRow(table, tableId, newItem, displayIndex);

    // Find the source row and insert immediately after it
    const normalizedAfterId = afterId !== undefined && afterId !== null ? String(afterId) : '';
    const sourceRow = normalizedAfterId
      ? Array.from(tbody.querySelectorAll('tr')).find(tr => tr.dataset.id === normalizedAfterId) || null
      : null;
    if (sourceRow) {
      sourceRow.insertAdjacentElement('afterend', newTr);
    } else {
      tbody.appendChild(newTr);
    }

    // Let Now.js scan the new row so any sub-components/elements initialise
    if (window.Now && typeof Now.scan === 'function') {
      Now.scan(newTr);
    }
  },

  async handleCopyRow(table, tableId, item) {
    try {
      // Capture current DOM values to ensure edits are preserved
      const updatedItem = this.captureRowValues(table, tableId, item);
      const sourceRowIdentity = this.getRowIdentity(table, updatedItem);

      // Persist updated item back into table.data
      if (Array.isArray(table.data)) {
        const idx = this.findRowIndex(table, item);
        if (idx !== -1) {
          table.data[idx] = updatedItem;
        }
      }

      const newItem = structuredClone ? structuredClone(updatedItem) : JSON.parse(JSON.stringify(updatedItem));
      delete newItem.__rowKey;

      if (updatedItem && updatedItem._copyResetData && typeof updatedItem._copyResetData === 'object') {
        Object.entries(updatedItem._copyResetData).forEach(([key, value]) => {
          newItem[key] = value;
        });
      }

      // Use counter for predictable IDs instead of random timestamp
      if (!table._newRowCounter) {
        table._newRowCounter = 0;
      }
      table._newRowCounter++;
      newItem.id = `new_${table._newRowCounter}`;
      this.ensureEditableRowKey(table, newItem);

      const actionUrl = table.element.dataset.actionUrl || table.config.actionUrl;
      const isServerSide = this.isServerSideTable(table);

      if (actionUrl) {
        const fakeButton = document.createElement('button');
        fakeButton.className = 'loading';

        const success = await this.sendAction(actionUrl, {action: 'copy', row: item}, tableId, fakeButton);
        if (!success) return;

        if (isServerSide) {
          await this.loadTableData(tableId, {force: true});
        } else {
          const idx = this.findRowIndex(table, updatedItem);
          const insertAt = idx >= 0 ? idx + 1 : table.data.length;
          table.data.splice(insertAt, 0, newItem);
          this.insertRowAfterInDom(table, tableId, newItem, sourceRowIdentity);
        }
      } else {
        const idx = this.findRowIndex(table, updatedItem);
        const insertAt = idx >= 0 ? idx + 1 : table.data.length;
        table.data.splice(insertAt, 0, newItem);
        this.insertRowAfterInDom(table, tableId, newItem, sourceRowIdentity);
      }

      EventManager.emit('table:rowCopied', {
        tableId,
        originalItem: item,
        newItem
      });
    } catch (error) {
      this.handleError('Copy row', error, 'copyRow');
    }
  },

  async handleDeleteRow(table, tableId, item) {
    try {
      if (window.DialogManager && table.config.confirmDelete !== false) {
        const confirmed = await DialogManager.confirm(
          Now.translate('Are you sure you want to delete this item?'),
          Now.translate('Confirm Delete')
        );

        if (confirmed) {
          await this.deleteRow(table, tableId, item);
        }
      } else {
        await this.deleteRow(table, tableId, item);
      }

    } catch (error) {
      this.handleError('Delete row', error, 'deleteRow');
    }
  },

  async deleteRow(table, tableId, item) {
    try {
      const actionUrl = table.element.dataset.actionUrl || table.config.actionUrl;
      const isServerSide = this.isServerSideTable(table);

      const removeLocalRow = () => {
        if (!Array.isArray(table.data)) return;
        const rowIdentity = this.getRowIdentity(table, item);
        const index = table.data.findIndex(row => this.getRowIdentity(table, row) === rowIdentity);
        const idx = index !== -1 ? index : table.data.indexOf(item);
        if (idx !== -1) {
          table.data.splice(idx, 1);
        }
        // Keep at least one empty row for editable tables
        if (table.config.allowRowModification && table.data.length === 0) {
          table.data.push(this.createEmptyRow(table));
        }
        this.renderTable(tableId);
      };

      if (actionUrl) {
        const fakeButton = document.createElement('button');
        fakeButton.className = 'loading';
        const success = await this.sendAction(actionUrl, {action: 'delete', id: item.id, row: item}, tableId, fakeButton);
        if (!success) return;

        if (isServerSide) {
          await this.loadTableData(tableId, {force: true});
        } else {
          removeLocalRow();
        }
      } else {
        removeLocalRow();
      }

      NotificationManager.success('Deleted successfully');

      EventManager.emit('table:rowDeleted', {
        tableId,
        item
      });
    } catch (error) {
      this.handleError('Delete row', error, 'deleteRow');
    }
  },

  getColumnDefinitions(table) {
    const columns = new Map();
    const mergeInfo = new Map();
    const thead = table.element.querySelector('thead');

    // Return empty Map if thead doesn't exist (e.g., dynamic columns not yet loaded)
    if (!thead) {
      return columns;
    }

    thead.querySelectorAll('tr').forEach((tr, rowIndex) => {
      let currentCol = 0;
      tr.querySelectorAll('th').forEach((th, colIndex) => {
        const autoNumber = th.dataset.autoNumber === 'true';
        const field = th.dataset.field || (autoNumber ? `__autoNumber_${rowIndex}_${currentCol}` : '');
        const rowspan = parseInt(th.getAttribute('rowspan') || 1);
        const colspan = parseInt(th.getAttribute('colspan') || 1);

        while (mergeInfo.has(`${rowIndex}-${currentCol}`)) {
          currentCol++;
        }

        if (rowspan > 1 || colspan > 1) {
          for (let r = 0; r < rowspan; r++) {
            for (let c = 0; c < colspan; c++) {
              mergeInfo.set(`${rowIndex + r}-${currentCol + c}`, {
                field,
                rowspan,
                colspan,
                startRow: rowIndex,
                startCol: currentCol
              });
            }
          }
        }

        if ((rowspan === thead.rows.length || rowIndex === thead.rows.length - 1) && field) {
          const attributes = this.extractDataAttributes(th, {
            field: '',
            filter: '',
            value: '',
            type: '',
            locale: '',
            currency: '',
            decimals: '',
            placeholder: '',
            options: {},
            optionsKey: '',
            datalist: {},
            label: '',
            min: '',
            max: '',
            step: '',
            pattern: '',
            size: '',
            maxLength: 0,
            showAll: true,
            allLabel: 'All items',
            allValue: '',
            formatter: '',
            format: '',
            class: '',
            cellClass: '',
            cellElement: '',
            autocomplete: 'off',
            template: '',
            autoNumber: false,
            visible: true
          });

          attributes.field = field;

          // Use header text as label fallback when data-label is not set
          if (!attributes.label) {
            attributes.label = th.textContent.trim();
          }

          // Parse visibility aliases (default true)
          if (th.dataset.visible !== undefined) {
            attributes.visible = th.dataset.visible !== 'false';
          } else if (th.dataset.hidden !== undefined) {
            attributes.visible = th.dataset.hidden === 'true' ? false : true;
          }

          // Apply visibility to the th element
          if (attributes.visible === false) {
            th.style.display = 'none';
          }

          columns.set(field, {
            ...attributes,
            index: currentCol,
            mergeInfo: mergeInfo.get(`${rowIndex}-${currentCol}`) || null
          });
        }

        currentCol += colspan;
      });
    });

    return new Map([...columns.entries()].sort((a, b) => a[1].index - b[1].index));
  },

  /**
   * Create dynamic table headers from column metadata
   * @param {Object} table - Table instance
   * @param {Array} columns - Column metadata from API
   * @returns {HTMLElement} thead element
   */
  createDynamicHeaders(table, columns) {
    const thead = document.createElement('thead');
    const tr = document.createElement('tr');

    // Add checkbox column if enabled
    if (table.config.showCheckbox) {
      const th = document.createElement('th');
      th.className = 'check-column';

      const checkboxId = `select-all-${table.id}`;
      const label = document.createElement('label');
      label.htmlFor = checkboxId;

      const checkbox = document.createElement('input');
      checkbox.type = 'checkbox';
      checkbox.className = 'select-all';
      checkbox.id = checkboxId;
      checkbox.setAttribute('aria-label', 'Select all');

      label.appendChild(checkbox);
      th.appendChild(label);
      tr.appendChild(th);
    }

    // Add drag handle column if row sortable is enabled
    if (table.config.rowSortable === true) {
      const th = document.createElement('th');
      th.className = 'drag-handle';
      th.style.width = '2rem';
      tr.appendChild(th);
    }

    // Add data columns from API metadata
    columns.forEach(col => {
      const th = document.createElement('th');

      // Required attributes
      th.dataset.field = col.field;
      th.textContent = col.label || col.field;

      // Optional attributes - map column metadata to data attributes
      if (col.sort !== undefined) th.dataset.sort = col.sort;
      if (col.filter !== undefined) th.dataset.filter = col.filter;
      if (col.type !== undefined) th.dataset.type = col.type;
      if (col.formatter !== undefined) th.dataset.formatter = col.formatter;
      if (col.format !== undefined) th.dataset.format = col.format;
      if (col.locale !== undefined) th.dataset.locale = col.locale;
      if (col.currency !== undefined) th.dataset.currency = col.currency;
      if (col.decimals !== undefined) th.dataset.decimals = col.decimals;
      // Apply class to th only
      if (col.class !== undefined) {
        th.className = col.class;
      }

      // Store cellClass in dataset for td rendering
      if (col.cellClass !== undefined) {
        th.dataset.cellClass = col.cellClass;
      }
      if (col.i18n !== undefined) th.dataset.i18n = '';
      if (col.placeholder !== undefined) th.dataset.placeholder = col.placeholder;
      if (col.template !== undefined) th.dataset.template = col.template;
      if (col.cellElement !== undefined) th.dataset.cellElement = col.cellElement;
      if (col.optionsKey !== undefined) th.dataset.optionsKey = col.optionsKey;
      if (col.align !== undefined) th.dataset.align = col.align;
      if (col.emptyText !== undefined) th.dataset.emptyText = col.emptyText;
      if (col.autoNumber !== undefined) th.dataset.autoNumber = col.autoNumber ? 'true' : 'false';

      // Keep generated headers aligned with the same visibility contract used by parsed templates.
      const isHidden = col.visible === false || col.hidden === true;
      if (isHidden) {
        th.dataset.visible = 'false';
        th.style.display = 'none';
      }

      // Handle options (for filter dropdowns)
      if (col.options !== undefined) {
        if (typeof col.options === 'object' && !Array.isArray(col.options)) {
          th.dataset.options = JSON.stringify(col.options);
        } else if (typeof col.options === 'string') {
          th.dataset.options = col.options;
        } else {
          th.dataset.options = JSON.stringify(col.options);
        }
      }

      // Handle datalist
      if (col.datalist !== undefined) {
        th.dataset.datalist = JSON.stringify(col.datalist);
      }

      // Numeric constraints
      if (col.min !== undefined) th.dataset.min = col.min;
      if (col.max !== undefined) th.dataset.max = col.max;
      if (col.step !== undefined) th.dataset.step = col.step;
      if (col.pattern !== undefined) th.dataset.pattern = col.pattern;
      if (col.size !== undefined) th.dataset.size = col.size;
      if (col.maxLength !== undefined) th.dataset.maxLength = col.maxLength;

      // Filter specific options
      if (col.showAll !== undefined) th.dataset.showAll = col.showAll;
      if (col.allLabel !== undefined) th.dataset.allLabel = col.allLabel;
      if (col.allValue !== undefined) th.dataset.allValue = col.allValue;

      // Autocomplete
      if (col.autocomplete !== undefined) th.dataset.autocomplete = col.autocomplete;

      tr.appendChild(th);
    });

    // Add row modification actions column if enabled
    if (table.config.allowRowModification) {
      const th = document.createElement('th');
      th.className = 'icons';
      tr.appendChild(th);
    }

    // Add row actions column if defined
    const rowActionsRaw = table.element.dataset.rowActions || table.element.dataset.rowActionsJson;
    if (rowActionsRaw) {
      const th = document.createElement('th');
      th.className = 'row-actions';
      tr.appendChild(th);
    }

    thead.appendChild(tr);
    return thead;
  },

  setupFooter(table) {
    const tfoot = this.ensureFooterStructure(table);
    if (!tfoot) return;

    // Add checkbox column to footer if enabled
    if (table.config.showCheckbox) {
      tfoot.querySelectorAll('tr').forEach(tr => {
        // Check if checkbox column already exists
        if (!tr.querySelector('.check-column')) {
          const td = document.createElement('td');
          td.className = 'check-column';

          // Add checkbox if specified
          if (tr.dataset.showCheckbox === 'true') {
            const checkboxId = `select-all-${table.id}-footer`;
            const label = document.createElement('label');
            label.htmlFor = checkboxId;

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'select-all';
            checkbox.id = checkboxId;
            checkbox.setAttribute('aria-label', Now.translate('Select all'));
            checkbox.addEventListener('change', (e) => {
              this.handleSelectAll(table, table.id, e.target.checked);
            });

            label.appendChild(checkbox);
            td.appendChild(label);
          }

          tr.insertBefore(td, tr.firstChild);
        }
      });
    }

    const columns = this.getColumnGroups(table);
    const footerLayout = this.getSectionCellLayout(tfoot);

    footerLayout.flat().forEach(({cell}) => {
      this.captureFooterCellState(cell);
      this.resetFooterCellState(cell);

      try {
        const i18nManager = (typeof Now !== 'undefined' && Now.getManager)
          ? Now.getManager('i18n')
          : window.I18nManager;
        if (i18nManager && typeof i18nManager.translateNode === 'function') {
          i18nManager.translateNode(cell);
        }
      } catch (error) {
        // ignore footer translation errors during reset
      }
    });

    this.applyFooterAggregates(table, columns, tfoot);

    // Process each footer row
    footerLayout.forEach(rowEntries => {
      rowEntries.forEach(({cell, columnIndex}) => {
        // Skip if already processed (has data-processed attribute)
        if (cell.dataset.processed === 'true') return;

        const column = columns[columnIndex] || null;
        const aggregateConfig = this.getFooterAggregateConfig(cell);
        const aggregateType = aggregateConfig.type;

        if (!aggregateType) return;

        // Handle custom aggregate function
        if (aggregateType === 'custom') {
          const customFn = aggregateConfig.customFn;
          if (customFn && window[customFn] && typeof window[customFn] === 'function') {
            try {
              const footerAttributes = {
                isFooter: true,
                field: aggregateConfig.field || column?.field || null,
                aggregateType,
                tableId: table.id,
                column,
                table
              };
              const value = window[customFn](table.data, cell, table, footerAttributes);
              if (value !== undefined) {
                cell.textContent = value;
              }
              this.applyFooterCellPresentation(cell, column);
            } catch (error) {
              console.error(`Error executing custom footer function ${customFn}:`, error);
            }
          }
          cell.dataset.processed = 'true';
          return;
        }

        const field = aggregateConfig.field || column?.field || null;
        if (!field) return;

        const aggregateResult = this.getAggregateResult(table.data, field, aggregateType);
        if (!aggregateResult.processable) {
          return;
        }

        const value = aggregateResult.value;

        const formatter = cell.dataset.formatter || column?.formatter;
        const footerAttributes = {
          isFooter: true,
          field,
          aggregateType,
          tableId: table.id,
          column,
          table,
          aggregate: aggregateResult
        };

        if (formatter && typeof window[formatter] === 'function') {
          try {
            window[formatter](cell, value, table.data, footerAttributes);
            this.applyFooterCellPresentation(cell, column);
            cell.dataset.processed = 'true';
            return;
          } catch (error) {
            console.error(`Error executing footer formatter ${formatter}:`, error);
          }
        }

        // Format the value
        const format = cell.dataset.format || (aggregateType === 'count' ? 'number' : column?.format);
        const formattedValue = this.formatValue(value, format);

        // Add prefix/suffix if specified
        const prefix = cell.dataset.prefix || '';
        const suffix = cell.dataset.suffix || '';
        cell.textContent = prefix + formattedValue + suffix;

        this.applyFooterCellPresentation(cell, column);

        // Mark as processed
        cell.dataset.processed = 'true';
      });
    });

    // Emit event for custom processing
    EventManager.emit('table:footerSetup', {
      tableId: table.id,
      tfoot: tfoot,
      data: table.data
    });
  },

  calculateAggregate(data, field, type) {
    const aggregateResult = this.getAggregateResult(data, field, type);
    return aggregateResult.processable ? aggregateResult.value : 0;
  },

  calculateGroupAggregate(data, fields, type) {
    if (!data?.length || !fields?.length) return 0;

    const values = data.map(row =>
      fields.reduce((sum, field) => {
        const val = parseFloat(row[field]);
        return sum + (isNaN(val) ? 0 : val);
      }, 0)
    );

    return this.calculateAggregate(values, 'value', type);
  },

  getColumnGroups(table, referenceRow = null) {
    const groups = [];

    if (referenceRow) {
      Array.from(referenceRow.cells || []).forEach(cell => {
        const colspan = parseInt(cell.getAttribute('colspan') || 1, 10);
        const column = {
          field: cell.dataset.field || null,
          format: cell.dataset.format || null,
          formatter: cell.dataset.formatter || null,
          class: cell.dataset.class || null,
          cellClass: cell.dataset.cellClass || null,
          align: cell.dataset.align || null
        };

        for (let i = 0; i < colspan; i++) {
          groups.push({...column});
        }
      });

      return groups;
    }

    const thead = table.element.querySelector('thead');
    const headerLayout = this.getSectionCellLayout(thead);
    if (!headerLayout.length) return groups;

    const totalRows = headerLayout.length;
    headerLayout.flat().forEach(entry => {
      if (entry.rowIndex + entry.rowspan !== totalRows) {
        return;
      }

      const column = {
        field: entry.cell.dataset.field || null,
        format: entry.cell.dataset.format || null,
        formatter: entry.cell.dataset.formatter || null,
        class: entry.cell.dataset.class || null,
        cellClass: entry.cell.dataset.cellClass || null,
        align: entry.cell.dataset.align || null
      };

      for (let offset = 0; offset < entry.colspan; offset++) {
        groups[entry.columnIndex + offset] = {...column};
      }
    });

    return groups.filter(column => column !== undefined);
  },

  updateTableCaption(table, totalRecords, totalPages) {
    if (!table?.element || !table.config.showCaption) return;

    const {pageSize, page, search} = table.config.params;

    let caption = table.element.querySelector('caption');

    if (!caption) {
      caption = document.createElement('caption');
      table.element.appendChild(caption);
    }

    let searchText = '';
    if (search && search.length > 0) {
      searchText = "Search <strong>{search}</strong> found {count} entries, displayed {start} to {end}, page {page} of {total} pages";
    } else {
      searchText = "All {count} entries, displayed {start} to {end}, page {page} of {total} pages";
    }

    const params = {
      count: totalRecords,
      start: (page - 1) * pageSize + 1,
      end: Math.min(page * pageSize, totalRecords),
      page: page,
      total: totalPages,
      search: search
    };

    let translated = Now.translate(searchText, params);

    if (typeof translated === 'string' && /\{[^}]+\}/.test(translated)) {
      try {
        const i18n = Now.getManager ? Now.getManager('i18n') : window.I18nManager;
        if (i18n && typeof i18n.interpolate === 'function') {
          translated = i18n.interpolate(translated, params, i18n.getTranslations?.());
        } else {
          // Fallback simple interpolation
          translated = String(translated).replace(/\{([^}]+)\}/g, (m, k) => {
            return params[k] !== undefined ? params[k] : m;
          });
        }
      } catch (err) {}
    }

    caption.innerHTML = translated;
  },

  filterData(table, data) {
    if (!table || !table.config.params || Object.keys(table.config.params).length === 0) {
      return data;
    }

    const filters = table.config.params;
    const searchColumns = table.config.searchColumns || [];

    return data.filter(item => {
      const generalFilters = Object.entries(filters).every(([field, filterValue]) => {
        if (['search', 'pageSize', 'page', 'total', 'summary'].includes(field)) return true;

        if (filterValue === '') return true;

        const value = item[field];
        // If there's no corresponding column for this param (e.g., 'totalPages'), skip it
        const attributes = table.columns.get(field);
        if (!attributes) return true;

        if (value === undefined || value === null) return false;

        if (attributes.showAll && filterValue.toString() === attributes.allValue.toString()) return true;

        const filterFn = table.element.querySelector(`th[data-field="${field}"]`)?.dataset.filterFn;
        if (filterFn && typeof window[filterFn] === 'function') {
          return window[filterFn](value, filterValue, item);
        }

        // For text-type filters, use partial match (contains)
        if (attributes.type === 'text') {
          return value.toString().toLowerCase().includes(filterValue.toString().toLowerCase());
        }

        // For select and other types, use exact match
        return value.toString() === filterValue.toString();
      });

      const searchFilter = !filters.search || searchColumns.some(column => {
        const value = item[column];
        if (value === undefined || value === null) return false;

        return value.toString().toLowerCase()
          .includes(filters.search.toLowerCase());
      });

      return generalFilters && searchFilter;
    });
  },

  sortData(table, data) {
    if (!table || !table.sortState || Object.keys(table.sortState).length === 0) {
      return data;
    }

    return [...data].sort((a, b) => {
      for (const [field, direction] of Object.entries(table.sortState)) {
        const aVal = a[field];
        const bVal = b[field];

        const sorter = table.element.querySelector(`th[data-field="${field}"]`)?.dataset.sorter;
        if (sorter && typeof window[sorter] === 'function') {
          const result = window[sorter](aVal, bVal, a, b);
          if (result !== 0) return direction === 'asc' ? result : -result;
          continue;
        }

        if (aVal === bVal) continue;

        const result = aVal > bVal ? 1 : -1;
        return direction === 'asc' ? result : -result;
      }
      return 0;
    });
  },

  paginateData(table, data) {
    if (!table || table.config.params.pageSize <= 0) {
      return {pageData: data, totalPages: 1, totalRecords: data.length};
    }

    const {pageSize, page} = table.config.params;
    const start = (page - 1) * pageSize;
    let pageData;
    if (table.serverSide) {
      // Assume server returned the page's rows already
      pageData = data;
    } else {
      pageData = data.slice(start, start + pageSize);
    }

    // If server provided a total (via meta), use it; otherwise derive from data length
    const totalRecords = parseInt(table.config.params.total || data.length || 0);
    const totalPages = pageSize > 0 ? Math.max(1, Math.ceil(totalRecords / pageSize)) : 1;

    return {pageData, totalPages, totalRecords};
  },

  updatePagination(table, tableId, totalRecords, totalPages) {
    if (!table) return;

    table.paginationWrapper.innerHTML = '';

    if (totalPages > 1) {
      let startPage = Math.max(1, table.config.params.page - 2);
      let endPage = Math.min(totalPages, startPage + 4);

      if (endPage - startPage < 4) {
        startPage = Math.max(1, endPage - 4);
      }

      if (startPage > 1) {
        this.addPaginationButton(table, tableId, 1, '1', table.config.params.page);
      }

      for (let i = startPage; i <= endPage; i++) {
        this.addPaginationButton(table, tableId, i, i, table.config.params.page);
      }

      if (endPage < totalPages) {
        this.addPaginationButton(table, tableId, totalPages, totalPages, table.config.params.page);
      }
    }

    this.updateTableCaption(table, totalRecords, totalPages);
  },

  addPaginationButton(table, tableId, page, text, currentPage) {
    const button = document.createElement('button');
    button.textContent = text;
    button.setAttribute('type', 'button');

    if (page !== currentPage) {
      button.classList.add('pagination-button');
      button.setAttribute('aria-label', Now.translate('Go to page {page}', {page}));
      button.onclick = (e) => {
        e.preventDefault();
        this.handleFilterChange(table, tableId, 'page', parseInt(page));
      };
    } else {
      button.disabled = true;
      button.setAttribute('aria-current', 'page');
    }

    table.paginationWrapper.appendChild(button);
  },

  renderCell(table, tableId, row, field, attributes, rowData, index) {
    const cell = document.createElement('td');
    cell.dataset.field = field;
    let value = attributes.autoNumber ? this.getRowSequence(table, index) : rowData[field];

    const normalizeValue = (v) => {
      if (v === null || v === undefined) return v;
      if (typeof v === 'object') {
        if (v.text !== undefined) return v.text;
        if (v.html !== undefined) return v.html;
        if (v.value !== undefined) return v.value;
        return JSON.stringify(v);
      }
      return v;
    };

    const rawValue = value;
    const primValue = normalizeValue(value);
    const displayOnlyFields = rowData && typeof rowData._displayOnlyFields === 'object' && rowData._displayOnlyFields !== null
      ? rowData._displayOnlyFields
      : null;
    const isDisplayOnly = !!(displayOnlyFields && displayOnlyFields[field] === true);
    const displayValues = rowData && typeof rowData._displayValues === 'object' && rowData._displayValues !== null
      ? rowData._displayValues
      : null;
    const displayValue = displayValues && Object.prototype.hasOwnProperty.call(displayValues, field)
      ? normalizeValue(displayValues[field])
      : primValue;

    // Resolve lookup options for this field from table/API/filter or fallback to attributes.options
    // Expose these to custom formatters by creating a cloned attributes object (avoid mutating the stored attributes)
    const tableDataOptions = table?.dataOptions?.[field];
    const tableFilterOptions = table?.filterOptions?.[field];
    const lookupOptions = tableDataOptions || tableFilterOptions || attributes.options;
    const fmtAttributes = Object.assign({}, attributes, {
      lookupOptions,
      tableDataOptions,
      tableFilterOptions,
      tableId
    });

    try {
      // Setup cell attributes
      if (attributes.cellClass) {
        cell.className = attributes.cellClass;
      }

      if (attributes.visible === false) {
        cell.style.display = 'none';
      }

      if (attributes.align) {
        cell.style.textAlign = attributes.align;
      }

      if (isDisplayOnly) {
        cell.textContent = displayValue ?? (attributes.emptyText || '');
        row.appendChild(cell);
        return;
      }

      // Handle null or undefined values
      // If the column has a cellElement (interactive element like color, text, etc.),
      // still render the element even when value is null/undefined
      if (primValue === null || primValue === undefined) {
        if (attributes.cellElement && attributes.cellElement !== '') {
          this.renderElementCell(cell, table, tableId, field, attributes, rowData, index);
          row.appendChild(cell);
          return;
        }
        cell.textContent = attributes.emptyText || '';
        row.appendChild(cell);
        return;
      }

      // Handle different render types
      if (attributes.cellElement && attributes.cellElement !== '') {
        // Render as interactive element
        this.renderElementCell(cell, table, tableId, field, attributes, rowData, index);
      } else if (attributes.formatter && typeof window[attributes.formatter] === 'function') {
        // Custom formatter function - pass rawValue and primValue for flexibility
        // Provide resolved lookup options and table info via fmtAttributes
        try {
          window[attributes.formatter](cell, rawValue, rowData, fmtAttributes);
        } catch (err) {
          console.error('Formatter', attributes.formatter, 'threw an error for field', field, err);
        }
      } else if (attributes.format) {
        // Built-in formatter - look up options in priority order:
        // 1. API response options (table.dataOptions)
        // 2. Filter options (table.filterOptions)
        // 3. HTML data-options (attributes.options)
        const formatOptions = attributes.format === 'lookup' ? lookupOptions : fmtAttributes;
        cell.textContent = this.formatValue(primValue, attributes.format, formatOptions);
      } else if (attributes.template) {
        // Template-based rendering
        // 1) Replace ${key} placeholders with row values
        let template = attributes.template.replace(/\${(\w+)}/g, (match, key) => {
          const v = rowData[key];
          return v !== undefined ? normalizeValue(v) : match;
        });

        // 2) Run i18n interpolation on the resulting HTML string so tokens like
        //    {LNG_*} (including patterns formed like {LNG_${status_text}}) are resolved
        try {
          const i18n = window.Now && Now.getManager ? Now.getManager('i18n') : null;
          if (i18n && typeof i18n.interpolate === 'function') {
            template = i18n.interpolate(template);
          } else if (window.Now && typeof Now.translate === 'function') {
            // Fallback: if translate exists but no interpolate, attempt a best-effort
            // Translate plain tokens that match exactly one token inside the template
            // (not ideal for full HTML, but better than nothing)
            // Only translate when the template is simple text
            const plain = template.replace(/^\s+|\s+$/g, '');
            if (!plain.includes('<') && plain.startsWith('{LNG_') && plain.endsWith('}')) {
              template = Now.translate(plain.slice(1, -1));
            }
          }
        } catch (e) {
          // Ignore i18n errors and fall back to raw template
        }

        // 2.5) If TemplateManager is available, process the template string so it can use
        // its directives/interpolation and sanitization with the current row context
        try {
          const tm = window.TemplateManager;
          if (!tm || typeof tm.processTemplateString !== 'function') {
            throw new Error('TemplateManager.processTemplateString not available');
          }

          const container = document.createElement('div');
          const context = {
            state: {data: rowData},
            data: rowData,
            skipScan: true // avoid re-scanning element/form managers inside table cells
          };
          tm.processTemplateString(template, context, container);
          template = container.innerHTML;
        } catch (e) {
          const message = `Template error: ${e.message || e}`;
          // Surface visibly in cell and log for developers
          template = `<span class="template-error" title="${message}">${message}</span>`;
          try {
            ErrorManager.handle(message, {
              context: 'TableManager.renderCell.template',
              data: {field, template: attributes.template, error: e}
            });
          } catch (logErr) {
            console.error(message, e);
          }
        }

        // 3) Insert HTML
        cell.innerHTML = template;

        // 4) Immediately translate any elements inside the inserted HTML that carry data-i18n
        try {
          const i18n = window.Now && Now.getManager ? Now.getManager('i18n') : null;
          // Query for elements with data-i18n attribute inside this cell
          const els = cell.querySelectorAll('[data-i18n]');
          els.forEach(el => {
            const originalAttr = el.getAttribute('data-i18n');
            const attr = originalAttr;
            // Preserve original i18n key for later re-translation
            if (originalAttr !== null && originalAttr !== undefined) {
              try {el.dataset.i18n = originalAttr;} catch (e) { /* ignore */}
            }
            // If attribute is empty (just presence), translate current textContent
            if (attr === '' || attr === null) {
              if (typeof Now !== 'undefined' && typeof Now.translate === 'function') {
                el.textContent = Now.translate(el.textContent || '');
              }
            } else {
              // Attribute has a value (possibly produced by ${...}); use it as the key
              // If i18n.interpolate exists, run it so tokens are resolved; otherwise use Now.translate
              let key = attr;
              if (i18n && typeof i18n.interpolate === 'function') {
                try {key = i18n.interpolate(key);} catch (e) { /* ignore */}
              }
              if (typeof Now !== 'undefined' && typeof Now.translate === 'function') {
                el.textContent = Now.translate(key);
              } else {
                // Fallback: set to interpolated value
                el.textContent = key;
              }
            }
          });
        } catch (e) {
          // ignore translation errors for cell post-processing
        }
      } else {
        // Default rendering - prefer HTML when original value had an html property
        if (rawValue && typeof rawValue === 'object' && rawValue.html !== undefined) {
          cell.innerHTML = rawValue.html;
        } else {
          cell.textContent = primValue;
        }
      }

      // Add any data attributes
      if (attributes.dataAttributes) {
        Object.entries(attributes.dataAttributes).forEach(([attr, val]) => {
          const attrValue = typeof val === 'function' ? val(rowData) : val;
          cell.dataset[attr] = attrValue;
        });
      }

      // Add any event handlers directly to the cell
      if (attributes.events) {
        Object.entries(attributes.events).forEach(([event, handler]) => {
          cell.addEventListener(event, (e) => handler(e, rowData, cell, table));
        });
      }

      row.appendChild(cell);
    } catch (error) {
      this.handleError('Rendering cell failed', error, 'renderCell');
      // Fallback to simple text rendering
      cell.textContent = value !== undefined ? value : '';
      row.appendChild(cell);
    }
  },

  translateValue(value) {
    try {
      const i18n = window.Now && Now.getManager ? Now.getManager('i18n') : null;
      if (i18n && typeof i18n.interpolate === 'function') {
        return i18n.interpolate(value);
      }
      if (typeof Now !== 'undefined' && typeof Now.translate === 'function') {
        return Now.translate(value);
      }
    } catch (e) {}
    return value;
  },

  retranslateFilter(table) {
    if (!table) return;

    const config = table.config || {};

    // Internal filter UI (ElementManager-built)
    if (table.filterElements && table.filterElements instanceof Map) {
      // Search placeholder
      const searchObj = table.filterElements.get('search');
      if (searchObj?.element && Array.isArray(config.searchColumns) && config.searchColumns.length) {
        searchObj.element.placeholder = `${Now.translate('Search in')}: ${config.searchColumns.join(', ')}`;
      }
    }
  },

  renderElementCell(cell, table, tableId, field, attributes, rowData, index) {
    const elementManager = Now.getManager('element');
    const value = rowData[field];

    if (!elementManager) {
      cell.textContent = value || '';
      return;
    }

    let elementType = attributes.cellElement;
    let config = this.getElementConfig(table, tableId, field, attributes, rowData, index);

    if (!config) {
      cell.textContent = value;
      return;
    }

    try {
      // Create the element using ElementManager
      const element = elementManager.create(elementType, config);

      if (element && element.element) {
        // Store element ID for later reference
        cell.dataset.elementId = element.element.id;

        // Append the element or its wrapper to the cell
        if (element.wrapper) {
          cell.appendChild(element.wrapper);
        } else {
          cell.appendChild(element.element);
        }

        if (element.element.tagName === 'SELECT' && config.value !== undefined && config.value !== null && config.value !== '') {
          try {
            this.setFormElementValue(element.element, config.value);
            Array.from(element.element.options).forEach(opt => {
              opt.selected = String(opt.value) === String(config.value);
            });
          } catch (e) {
            // ignore
          }
        }

        // Track the element in table state for cleanup
        if (!table.elementInstances) {
          table.elementInstances = new Map();
        }
        table.elementInstances.set(element.element.id, element);

      } else {
        // Fallback if element creation failed
        cell.textContent = value;
      }
    } catch (error) {
      console.error('Failed to create element in cell:', error);
      cell.textContent = value;
    }
  },

  getElementConfig(table, tableId, field, attributes, rowData, index) {
    // Allow using data-type as a shorthand for data-cell-element.
    if (!attributes.cellElement && attributes.type) {
      attributes.cellElement = attributes.type;
    }
    if (!attributes.cellElement) return null;

    // Base configuration common to all elements
    const rawValue = rowData[field];
    const normalizeValue = (v) => {
      if (v === null || v === undefined) return v;
      if (typeof v === 'object') {
        if (v.value !== undefined) return v.value;
        if (v.text !== undefined) return v.text;
        if (v.html !== undefined) return v.html;
        return JSON.stringify(v);
      }
      return v;
    };
    const value = normalizeValue(rawValue);
    const rowIdentity = this.getRowIdentity(table, rowData, index);
    const config = {
      type: attributes.cellElement,
      name: `${field}[${rowIdentity}]`,
      id: `${tableId}_${field}_${rowIdentity}`, // Predictable format for error highlighting
      wrapper: attributes.wrapper || 'div',
      value: value,
      autocomplete: attributes.autocomplete || 'off',
      className: attributes.class,
      readOnly: attributes.readOnly,
      disabled: attributes.disabled,
      required: attributes.required,
      size: attributes.size,
      placeholder: attributes.placeholder
    };

    // Element-specific configurations
    switch (attributes.cellElement) {
      case 'select':
        // Handle select element. options can come from several places:
        // 1. data-options-key from API response (table.dataOptions)
        // 2. table.filterOptions[field]
        // 3. attributes.options (HTML data-options)
        // 4. window[optionString] if string
        if (attributes.optionsKey && table.dataOptions && table.dataOptions[attributes.optionsKey]) {
          config.options = table.dataOptions[attributes.optionsKey];
        } else {
          config.options = (table.filterOptions && table.filterOptions[field]) || attributes.options || {};
        }
        if (typeof config.options === 'string' && window[config.options]) {
          config.options = window[config.options];
        }
        config.multiple = attributes.multiple;
        config.allowEmpty = attributes.allowEmpty !== false;
        break;

      case 'text':
      case 'email':
      case 'url':
      case 'password':
        // Text-like elements
        if (attributes.minLength !== undefined && attributes.minLength !== null && attributes.minLength !== '') {
          const parsedMin = parseInt(attributes.minLength, 10);
          if (!isNaN(parsedMin) && parsedMin >= 0) config.minLength = parsedMin;
        }
        if (attributes.maxLength !== undefined && attributes.maxLength !== null && attributes.maxLength !== '') {
          const parsedMax = parseInt(attributes.maxLength, 10);
          if (!isNaN(parsedMax) && parsedMax > 0) config.maxLength = parsedMax;
        }
        config.pattern = attributes.pattern;
        config.autocomplete = attributes.autocomplete || config.type;
        // Add datalist if provided
        if (attributes.datalist) {
          config.datalist = attributes.datalist;
        }
        break;

      case 'number':
      case 'currency': {
        // Numeric inputs used in editable cells.
        config.min = attributes.min;
        config.max = attributes.max;

        const precisionValue = attributes.precision ?? attributes.decimals;
        let parsedPrecision = null;

        if (precisionValue !== undefined && precisionValue !== null && precisionValue !== '') {
          parsedPrecision = parseInt(precisionValue, 10);
          if (!isNaN(parsedPrecision) && parsedPrecision >= 0) {
            config.precision = parsedPrecision;
          } else {
            parsedPrecision = null;
          }
        }

        if (attributes.step !== undefined && attributes.step !== null && attributes.step !== '') {
          config.step = attributes.step;
        } else if (parsedPrecision !== null) {
          config.step = parsedPrecision > 0 ? Math.pow(10, -parsedPrecision) : 1;
        } else {
          config.step = attributes.cellElement === 'currency' ? 0.01 : 1;
        }
        break;
      }

      case 'textarea':
        // Textarea
        config.rows = attributes.rows || 3;
        config.cols = attributes.cols;
        config.resizable = attributes.resizable !== false;
        break;

      case 'date':
      case 'time':
      case 'datetime-local':
        // Date/time inputs
        config.min = attributes.min;
        config.max = attributes.max;
        config.format = attributes.format;
        break;

      case 'color':
        // Color input - normalize null/undefined/empty to null so no value is forced
        // (prevents browser from defaulting <input type="color"> to #000000)
        if (!config.value) {
          config.value = null;
          break;
        }
        // Ensure value is in #RRGGBB format
        if (!config.value.startsWith('#')) {
          config.value = '#' + config.value;
        }
        // Ensure uppercase for consistency
        config.value = config.value.toUpperCase();
        break;

      case 'file':
        // File upload
        config.accept = attributes.accept;
        config.multiple = attributes.multiple;
        config.maxFileSize = attributes.maxFileSize;
        config.preview = attributes.preview;
        break;

      case 'checkbox':
      case 'radio':
        // Checkbox/radio
        config.checked = value === true || value === '1' || value === 'true';
        if (attributes.options) {
          config.options = attributes.options;
        }
        break;

      case 'search':
        // Search element
        config.minLength = attributes.minLength || 2;
        config.delay = attributes.delay || 300;
        if (attributes.source) {
          config.source = attributes.source;
        }
        config.onSearch = attributes.onSearch;
        break;
    }

    // Add validators if specified
    if (attributes.validate) {
      config.validate = attributes.validate;
    }

    // Add formatter if specified
    if (attributes.formatter) {
      config.formatter = attributes.formatter;
    }

    return config;
  },

  cleanupTableResources(tableId) {
    try {
      const table = this.state.tables.get(tableId);
      if (!table) return;

      // Cleanup external filter form handlers
      if (table._externalFilterCleanup && Array.isArray(table._externalFilterCleanup)) {
        table._externalFilterCleanup.forEach(cleanup => {
          try {
            cleanup();
          } catch (err) {
            console.warn('External filter cleanup error:', err);
          }
        });
        table._externalFilterCleanup = [];
      }

      // Cleanup event handlers
      if (table.eventHandlers) {
        // Cleanup sort handlers
        if (table.eventHandlers.sort instanceof Map) {
          table.eventHandlers.sort.forEach((handlers, th) => {
            th.removeEventListener('click', handlers.sort);
            th.removeEventListener('keydown', handlers.key);
          });
        }

        // Cleanup other handlers
        if (table.eventHandlers.keyboard) {
          table.element.removeEventListener('keydown', table.eventHandlers.keyboard);
        }

        // Remove delegation listeners if present
        if (table.eventHandlers.delegation && table.element) {
          const tbody = table.element.querySelector('tbody');
          if (tbody) {
            tbody.removeEventListener('click', table.eventHandlers.delegation.click);
            tbody.removeEventListener('change', table.eventHandlers.delegation.change);
          }
        }

        if (table.eventHandlers.filterWrapperChange && table.filterWrapper) {
          table.filterWrapper.removeEventListener('change', table.eventHandlers.filterWrapperChange);
        }

        this.cleanupActionBindings(table);

        table.eventHandlers = {};
      }

      // Cleanup elements created by the table
      if (table.elementInstances) {
        if (table.elementInstances instanceof Map) {
          table.elementInstances.forEach((instance, id) => {
            const elementManager = Now.getManager('element');
            if (elementManager) {
              elementManager.destroy(id);
            }
          });
          table.elementInstances.clear();
        }
      }

      // Cleanup filter elements
      if (table.filterElements) {
        if (table.filterElements instanceof Map) {
          table.filterElements.forEach((element) => {
            if (element && element.element && element.element.id) {
              const elementManager = Now.getManager('element');
              if (elementManager) {
                elementManager.destroy(element.element.id);
              }
            }
          });
          table.filterElements.clear();
        }
      }

      // Remove DOM elements
      ['filterWrapper', 'actionWrapper', 'paginationWrapper'].forEach(wrapper => {
        if (table[wrapper]) {
          table[wrapper].remove();
          table[wrapper] = null;
        }
      });

      // Clear data
      table.data = null;
      if (table.columns && table.columns.clear) table.columns.clear();
      if (table.filterData && table.filterData.clear) table.filterData.clear();

      // Clear filterOptions (can be Map or Object)
      if (table.filterOptions) {
        if (table.filterOptions instanceof Map) {
          table.filterOptions.clear();
        } else {
          table.filterOptions = {};
        }
      }

      // Clear dataOptions (Object)
      if (table.dataOptions) {
        table.dataOptions = {};
      }

      // Clear sort state
      table.sortable = null;

      // Clear cache
      this.clearTableCache(tableId);

      // Remove table attributes
      if (table.element) {
        table.element.removeAttribute('data-table-initialized');
        table.element.classList.remove('table-initialized');
      }

      // Remove table state binding
      if (table.unsubscribe) {
        table.unsubscribe();
        table.unsubscribe = null;
      }

    } catch (error) {
      this.handleError('Failed to cleanup table resources', error, 'cleanup');
    }
  },

  // Delegated event handlers
  _handleDelegatedClick(e, tableId) {
    const table = this.state.tables.get(tableId);
    if (!table) return;

    const btn = e.target.closest('[data-action]');
    if (btn && table.element.contains(btn)) {
      e.preventDefault();
      const action = btn.dataset.action;
      const params = btn.dataset.params ? JSON.parse(btn.dataset.params) : null;
      const tr = btn.closest('tr');
      const id = tr?.dataset?.id;
      const row = id ? this.getRowObjectById(tableId, id) : null;
      const actionUrl = table.element.dataset.actionUrl || table.config.actionUrl;

      // always route data operations to table actionUrl
      this._executeRowAction(tableId, actionUrl, row || {}, action, {...(params || {})});
      return;
    }

    // handle buttons inside action cell without data-action (icons etc.)
    const iconBtn = e.target.closest('button, a');
    if (iconBtn && table.element.contains(iconBtn)) {
      // if it has data-field or data-action via other attrs, treat accordingly
      const action = iconBtn.dataset.action;
      if (action) {
        e.preventDefault();
        const tr = iconBtn.closest('tr');
        const id = tr?.dataset?.id;
        const row = id ? this.getRowObjectById(tableId, id) : null;
        const actionUrl = table.element.dataset.actionUrl || table.config.actionUrl;
        this._executeRowAction(tableId, actionUrl, row || {}, action, {});
      }
    }
  },

  _handleDelegatedChange(e, tableId) {
    const table = this.state.tables.get(tableId);
    if (!table) return;

    const target = e.target;
    if (!table.element.contains(target)) return;

    // Find field attribute
    const fieldEl = target.closest('[data-field]') || target;
    const field = fieldEl.dataset.field || fieldEl.name || fieldEl.dataset.name;

    if (field) {
      const tr = target.closest('tr');
      const id = tr?.dataset?.id;
      const row = id ? this.getRowObjectById(tableId, id) : null;

      // Do not treat header/select-all checkboxes as row-level field updates
      if (target.classList && target.classList.contains('select-all')) return;

      let value;
      if (target.type === 'checkbox') value = target.checked ? '1' : '0';
      else if (target.type === 'radio') {
        const checked = target.closest('tr')?.querySelector(`input[name="${target.name}"]:checked`);
        value = checked ? checked.value : null;
      } else {
        value = target.value;
      }

      const shouldSend = true;

      this.handleFieldChange(table, tableId, field, value, row, target, {send: shouldSend});
    }
  },

  // Helper to get selected row ids (from tbody .select-row checkboxes)
  getSelectedRowIds(table) {
    if (!table || !table.element) return [];
    const checked = table.element.querySelectorAll('tbody .select-row:checked');
    return Array.from(checked).map(cb => cb.value);
  },

  _performActionWrapperSubmission(tableId) {
    const table = this.state.tables.get(tableId);
    if (!table) return;

    const selectInfo = table.actionElements?.select;
    const submitInfo = table.actionElements?.submit;
    const actionUrl = table.element.dataset.actionUrl || table.config.actionUrl;

    if (!selectInfo || !submitInfo) return;

    const selectEl = selectInfo.element;
    const selectedAction = selectEl ? selectEl.value : null;

    // Validate that an action is selected (not empty value)
    if (!selectedAction || selectedAction === '') {
      NotificationManager.warning('Please select an action');
      return;
    }

    const ids = this.getSelectedRowIds(table);
    if (!ids || ids.length === 0) {
      NotificationManager.warning('Please select at least one row');
      return;
    }

    // Parse action key with pipe separator (e.g., "stage|lead" -> action=stage, stage=lead)
    const data = {ids: ids};
    if (selectedAction.includes('|')) {
      const [actionName, actionValue] = selectedAction.split('|', 2);
      data.action = actionName;
      data[actionName] = actionValue;
    } else {
      data.action = selectedAction;
    }

    // Merge extra action params from data-action-params attribute
    // Supports two formats:
    //   JSON object:  data-action-params='{"module_id": 5}'
    //   URL key list: data-action-params="module_id,other_key"  (reads values from current URL query)
    const rawActionParams = table.element.dataset.actionParams;
    if (rawActionParams) {
      const trimmed = rawActionParams.trim();
      if (trimmed.startsWith('{')) {
        // Already parsed as JSON object via extractDataAttributes -> table.config.actionParams
        Object.assign(data, table.config.actionParams || {});
      } else {
        // Treat as comma-separated URL query param keys
        const currentQuery = new URLSearchParams(window.location.search);
        trimmed.split(',').map(k => k.trim()).filter(Boolean).forEach(key => {
          const value = currentQuery.get(key);
          if (value !== null) data[key] = value;
        });
      }
    }

    // sendAction will handle UI feedback
    this.sendAction(actionUrl, data, tableId, submitInfo.element, {
      autoReloadOnSuccess: this.isServerSideTable(table)
    }).catch(err => console.error('Action submit error', err));
  },

  setupColumnResizing(tableId) {
    const tableObj = this.state.tables.get(tableId);
    if (!tableObj || !tableObj.element) return;
    if (!tableObj.config.persistColumnWidths) return;

    const tableEl = tableObj.element;
    const thead = tableEl.querySelector('thead');
    if (!thead) return;

    // Load saved widths if present
    try {
      const saved = localStorage.getItem(`table_${tableId}_columns`);
      if (saved) {
        const widths = JSON.parse(saved);
        const cols = thead.querySelectorAll('th');
        cols.forEach((th, idx) => {
          if (widths[idx]) th.style.width = widths[idx];
        });
      }
    } catch (err) {
      // ignore
    }

    // Add simple resizer handles to last row headers
    const headers = thead.querySelectorAll('th');
    headers.forEach((th, idx) => {
      // Skip if already has resizer
      if (th.querySelector('.col-resizer')) return;

      const resizer = document.createElement('div');
      resizer.className = 'col-resizer';
      resizer.setAttribute('role', 'separator');
      resizer.style.position = 'absolute';
      resizer.style.top = '0';
      resizer.style.right = '0';
      resizer.style.width = '6px';
      resizer.style.cursor = 'col-resize';
      resizer.style.userSelect = 'none';
      resizer.style.height = '100%';
      resizer.style.zIndex = '5';

      th.appendChild(resizer);

      let startX = 0;
      let startWidth = 0;

      const onMouseDown = (e) => {
        e.preventDefault();
        startX = e.clientX;
        startWidth = th.offsetWidth;
        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', onMouseUp);
      };

      const onMouseMove = (e) => {
        const dx = e.clientX - startX;
        const newWidth = Math.max(30, startWidth + dx);
        th.style.width = `${newWidth}px`;
      };

      const onMouseUp = (e) => {
        document.removeEventListener('mousemove', onMouseMove);
        document.removeEventListener('mouseup', onMouseUp);

        // save widths
        try {
          const cols = Array.from(thead.querySelectorAll('th'));
          const widths = cols.map(c => c.style.width || window.getComputedStyle(c).width);
          localStorage.setItem(`table_${tableId}_columns`, JSON.stringify(widths));
        } catch (err) {
          console.warn('Failed to persist column widths', err);
        }
      };

      resizer.addEventListener('mousedown', onMouseDown);
    });
  },

  getRowObjectById(tableId, id) {
    const table = this.state.tables.get(tableId);
    if (!table || !table.data) return null;
    return table.data.find((row, index) => this.getRowIdentity(table, row, index) === String(id)) || null;
  },

  clearTableCache(tableId) {
    try {
      const table = this.state.tables.get(tableId);
      if (!table) return;

      if (table.config.persistColumnWidths) {
        localStorage.removeItem(`table_${tableId}_columns`);
      }

      localStorage.removeItem(`table_${tableId}_filters`);

      localStorage.removeItem(`table_${tableId}_sort`);

      localStorage.removeItem(`table_${tableId}_page`);

    } catch (error) {
      this.handleError('Clear table cache', error, 'clearCache');
    }
  },

  destroyTable(tableId) {
    try {
      this.cleanupTableResources(tableId);

      this.state.tables.delete(tableId);

      EventManager.emit('table:destroyed', {
        tableId,
        timestamp: Date.now()
      });
    } catch (error) {
      this.handleError('Destroy table', error, 'destroy');
    }
  },

  destroy() {
    try {
      this.state.tables.forEach((_, tableId) => {
        this.destroyTable(tableId);
      });

      this.state.tables.clear();

      if (this.dynamicObserver) {
        this.dynamicObserver.disconnect();
        this.dynamicObserver = null;
      }

      EventManager.off('locale:changed');

      if (this.globalEventHandlers) {
        Object.entries(this.globalEventHandlers).forEach(([event, handler]) => {
          window.removeEventListener(event, handler);
        });
        this.globalEventHandlers = null;
      }

      this.resetState();

      EventManager.emit('tablemanager:destroyed', {
        timestamp: Date.now()
      });
    } catch (error) {
      this.handleError('Error during TableManager destruction', error, 'destroy');
    }
  },

  bindToState(tableId, statePath) {
    if (!tableId || !statePath) {
      return this.handleError('Invalid parameters for bindToState', null, 'bindToState');
    }

    try {
      const table = this.state.tables.get(tableId);
      if (!table) {
        return this.handleError(`Table ${tableId} not found`, null, 'bindToState');
      }

      if (!statePath.startsWith('state.')) {
        // Skip loading data during initialization - will be handled by initTable
        if (!table.initializing) {
          this.loadTableData(tableId);
        }
        return true;
      }

      const stateManager = Now.getManager('state');
      if (!stateManager) {
        console.warn('[TableManager] StateManager not found, loading data from other source');
        // Skip loading data during initialization - will be handled by initTable
        if (!table.initializing) {
          this.loadTableData(tableId);
        }
        return true;
      }

      if (!stateManager.get(statePath)) {
        console.warn(`[TableManager] Data not found at path "${statePath}"`);
      }

      if (table.unsubscribe) {
        table.unsubscribe();
        table.unsubscribe = null;
      }

      const unsubscribe = stateManager.subscribe(statePath, (newData) => {
        const data = Array.isArray(newData) ? newData : [];
        this.setData(tableId, data);
        EventManager.emit('table:stateUpdate', {
          tableId,
          statePath,
          recordCount: data.length,
          timestamp: Date.now()
        });
      });

      table.unsubscribe = unsubscribe;
      table.statePath = statePath;

      const initialData = stateManager.get(statePath);
      if (initialData) {
        this.setData(tableId, Array.isArray(initialData) ? initialData : []);
      }

      EventManager.emit('table:bound', {
        tableId,
        statePath,
        timestamp: Date.now()
      });

      return true;

    } catch (error) {
      return this.handleError(`Error binding table ${tableId}`, error, 'bindToState');
    }
  },

  /**
   * Setup CoreObserver handlers for dynamic table detection
   * Uses central observer for better performance
   */
  setupCoreObserverHandlers() {
    // Auto-init tables when added to DOM
    CoreObserver.onAdd('table[data-table]', (table) => {
      const tableId = table.dataset.table;
      if (!tableId) return;
      // Let initTable decide whether to reuse or reinitialize
      this.initTable(table);
    }, {priority: 5});

    // Auto-cleanup tables when removed from DOM
    CoreObserver.onRemove('table[data-table]', (table) => {
      const tableId = table.dataset.table;
      if (!tableId) return;

      // Only destroy if the registered table element matches the removed node
      const registeredTable = this.state.tables.get(tableId);
      if (registeredTable && registeredTable.element === table) {
        this.destroyTable(tableId);
      }
    }, {priority: 5, delay: 0});
  },

  setupDynamicTableObserver() {
    if (!window.MutationObserver) return;

    const observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
          if (node.nodeType === 1) {
            if (node.matches('table[data-table]')) {
              this.initTable(node);
            }
            if (node.querySelectorAll) {
              node.querySelectorAll('table[data-table]').forEach(table => {
                this.initTable(table);
              });
            }
          }
        });

        mutation.removedNodes.forEach((node) => {
          if (node.nodeType === 1) {
            // Wait for DOM operations to settle before cleanup
            // This prevents premature cleanup when nodes are moved in DOM
            setTimeout(() => {
              if (node.matches && node.matches('table[data-table]')) {
                if (!node.isConnected) {
                  const tableId = node.dataset.table;
                  if (tableId) {
                    // Only destroy if the registered table element is the same as the removed node
                    const registeredTable = this.state.tables.get(tableId);
                    if (registeredTable && registeredTable.element === node) {
                      this.destroyTable(tableId);
                    }
                  }
                }
              }
              if (node.querySelectorAll) {
                node.querySelectorAll('table[data-table]').forEach(table => {
                  if (!table.isConnected) {
                    const tableId = table.dataset.table;
                    if (tableId) {
                      // Only destroy if the registered table element is the same as the removed node
                      const registeredTable = this.state.tables.get(tableId);
                      if (registeredTable && registeredTable.element === table) {
                        this.destroyTable(tableId);
                      }
                    }
                  }
                });
              }
            }, 0);
          }
        });
      });
    });

    observer.observe(document.body, {
      childList: true,
      subtree: true
    });

    this.dynamicObserver = observer;
  },

  handleError(message, error, type) {
    const errorObj = error || new Error(message);

    return ErrorManager.handle(errorObj, {
      context: `TableManager.${type}`,
      type: 'error:table',
      data: {
        message,
        error: error ? {
          name: error.name,
          message: error.message,
          stack: error.stack
        } : null,
        tableId: this.currentTableId
      },
      notify: true
    });
  },

  setupTouchEvents(table) {
    if (!('ontouchstart' in window)) return;

    let startX, startY, touchStartTime;
    let longPressTimer;

    table.addEventListener('touchstart', (e) => {
      const touch = e.touches[0];
      startX = touch.clientX;
      startY = touch.clientY;
      touchStartTime = Date.now();

      longPressTimer = setTimeout(() => {
        const row = e.target.closest('tr');
        if (row) {
          const checkbox = row.querySelector('.select-all');
          if (checkbox) {
            checkbox.checked = !checkbox.checked;
            this.updateSelectedRows(table);
          }
        }
      }, 500);
    });

    table.addEventListener('touchmove', (e) => {
      const touch = e.touches[0];
      const deltaX = Math.abs(touch.clientX - startX);
      const deltaY = Math.abs(touch.clientY - startY);

      if (deltaX > 10 || deltaY > 10) {
        clearTimeout(longPressTimer);
      }
    });

    table.addEventListener('touchend', (e) => {
      clearTimeout(longPressTimer);

      const touchDuration = Date.now() - touchStartTime;
      const touch = e.changedTouches[0];
      const deltaX = Math.abs(touch.clientX - startX);
      const deltaY = Math.abs(touch.clientY - startY);

      if (touchDuration < 300 && deltaX < 10 && deltaY < 10) {
        const row = e.target.closest('tr');
        if (row && !e.target.closest('.select-all')) {
          const checkbox = row.querySelector('.select-all');
          if (checkbox) {
            checkbox.checked = !checkbox.checked;
            this.updateSelectedRows(table);
          }
        }
      }
    });

    table.addEventListener('touchcancel', () => {
      clearTimeout(longPressTimer);
    });
  },

  setupActions(table, tableId) {
    if (!table?.actionWrapper) return;

    this.cleanupActionBindings(table);
    table.actionElements = null;

    // Clear existing actions
    table.actionWrapper.innerHTML = '';

    const elementManager = Now.getManager('element');
    if (!elementManager) return;

    // If no bulk row actions defined, return
    if (table.config.actionUrl !== '' && Object.keys(table.config.actions).length > 0) {
      // Create the action select dropdown with placeholder as first option
      const selectLabel = table.element.dataset.actionSelectLabel || table.config.actionSelectLabel || 'Please select';
      const actionOptions = {
        '': selectLabel, // Empty value with translatable label
        ...table.config.actions
      };

      const select = elementManager.create('select', {
        id: `select_action_${tableId}`,
        options: actionOptions,
        value: '', // Set default to empty
        wrapper: null
      });

      // Create the action button data-action-button="Process|btn-success" (Text|Class)
      let actionButtonConfig = table.element.dataset.actionButton || table.config.actionButton || 'Process';
      if (typeof actionButtonConfig === 'string') {
        const parsed = actionButtonConfig.split('|');
        if (parsed.length) {
          actionButtonConfig = {
            value: parsed[0],
            className: parsed[1] || 'btn-info'
          };
        }
      }

      const button = elementManager.create('button', {
        id: `action_button_${tableId}`,
        type: 'button',
        value: actionButtonConfig.value || 'Process',
        className: `btn ${actionButtonConfig.className || 'btn-info'}`,
        wrapper: null,
        disabled: true
      });
      button.element.dataset.i18n = actionButtonConfig.value || 'Process';

      // Add elements to action wrapper
      if (select && button) {
        table.actionWrapper.appendChild(select.element);
        table.actionWrapper.appendChild(button.element);

        // Store references to action elements
        table.actionElements = {
          select: {
            element: select.element,
            id: select.element.id
          },
          submit: {
            element: button.element,
            id: button.element.id
          }
        };

        this.bindActionWrapper(table, tableId);
      }
    }

    // Setup filter actions first (table-level actions with filters)
    // This runs regardless of whether bulk row actions are defined
    this.setupFilterActions(table, tableId);
  },

  /**
   * Setup filter-based actions (buttons/links that include current filters)
   * Configuration via data-filter-actions attribute:
   * data-filter-actions='{
   *   "export": {"label": "Export", "url": "api/export", "type": "button", "className": "btn-primary"},
   *   "report": {"label": "View Report", "url": "/reports", "type": "link", "className": "btn-info"}
   * }'
   */
  setupFilterActions(table, tableId) {
    const filterActionsRaw = table.element.dataset.filterActions;
    if (!filterActionsRaw) return;

    let filterActions;
    try {
      filterActions = JSON.parse(filterActionsRaw);
    } catch (e) {
      console.warn('Invalid data-filter-actions JSON:', e);
      return;
    }

    if (!filterActions || typeof filterActions !== 'object') return;

    Object.entries(filterActions).forEach(([key, config]) => {
      const {
        label = key,
        url,
        type = 'button',
        className = '',
        method = 'POST',
        target = '_self',
        confirm: confirmMessage
      } = config;

      if (!url) {
        console.warn(`Filter action "${key}" missing URL`);
        return;
      }

      if (type === 'link') {
        // Create link element
        const link = document.createElement('a');
        link.className = `btn ${className}`.trim();
        link.textContent = Now.translate(label);
        link.href = '#';
        link.target = target;
        link.dataset.action = key;

        link.addEventListener('click', async (e) => {
          e.preventDefault();

          if (confirmMessage) {
            const confirmed = await DialogManager?.confirm?.(
              Now.translate(confirmMessage),
              Now.translate('Confirm')
            );
            if (!confirmed) return;
          }

          // Build URL with filter params
          const params = this.getFilterParams(table);
          const queryString = new URLSearchParams(params).toString();
          const finalUrl = url.includes('?')
            ? `${url}&${queryString}`
            : `${url}?${queryString}`;

          if (target === '_blank') {
            window.open(finalUrl, '_blank');
          } else {
            window.location.href = finalUrl;
          }

          EventManager.emit('table:filterAction', {
            tableId,
            action: key,
            type: 'link',
            url: finalUrl,
            params
          });
        });

        table.actionWrapper.appendChild(link);

      } else {
        // Create button element
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `btn ${className}`.trim();
        button.textContent = Now.translate(label);
        button.dataset.action = key;

        button.addEventListener('click', async (e) => {
          e.preventDefault();

          if (confirmMessage) {
            const confirmed = await DialogManager?.confirm?.(
              Now.translate(confirmMessage),
              Now.translate('Confirm')
            );
            if (!confirmed) return;
          }

          button.classList.add('loading');

          try {
            const params = this.getFilterParams(table);
            const sortParams = this.getSortParams(table);

            const requestData = {
              action: key,
              tableId,
              filters: params,
              sort: sortParams
            };

            // Send POST request
            const resp = await window.http.post(url, requestData);

            // Handle 403 Forbidden - let ResponseHandler show the error message
            if (resp?.status === 403) {
              const responseData = resp?.data?.data ?? resp?.data ?? resp;

              NotificationManager.error(responseData?.message || 'Access forbidden');

              button.classList.remove('loading');
              return;
            }

            // Handle 401 Unauthorized
            if (resp?.status === 401) {
              console.warn('TableManager: Unauthorized (401) for filter action');

              const error = new Error(resp.statusText || 'Unauthorized');
              error.status = 401;
              error.response = resp;

              await AuthErrorHandler.handleError(error, {
                currentPath: window.location.pathname
              });

              button.classList.remove('loading');
              return;
            }

            const rawResponse = resp?.data ?? resp;
            const responseData = resp?.data?.data ?? resp?.data ?? resp;
            const success = responseData?.success !== false;
            if (success) {
              this.invalidateTableDataSourceCache(tableId);
            }

            // Handle response via ResponseHandler if available
            await ResponseHandler.process(responseData, {
              reloadPage: async () => window.location.reload(),
              reloadTable: async () => this.loadTableData(tableId, {force: true}),
              reloadComponent: async () => this.loadTableData(tableId, {force: true})
            });

            this.showResponseMessageFallback(responseData, rawResponse, success);

            EventManager.emit('table:filterAction', {
              tableId,
              action: key,
              type: 'button',
              params,
              response: responseData
            });

          } catch (error) {
            console.error('Filter action error:', error);
            NotificationManager?.error?.(error.message || 'Action failed');
          } finally {
            button.classList.remove('loading');
          }
        });

        table.actionWrapper.appendChild(button);
      }
    });
  },

  extractResponseActions(...payloads) {
    const actions = [];

    payloads.forEach(payload => {
      if (!payload || typeof payload !== 'object') {
        return;
      }

      if (Array.isArray(payload.actions)) {
        actions.push(...payload.actions.filter(action => action && typeof action === 'object'));
      } else if (payload.actions && typeof payload.actions === 'object') {
        actions.push(payload.actions);
      }
    });

    return actions;
  },

  showResponseMessageFallback(responseData, rawResponse = null, success = true) {
    if (!window.NotificationManager) {
      return;
    }

    const actions = this.extractResponseActions(responseData, rawResponse, rawResponse?.data);
    if (actions.some(action => ['notification', 'alert'].includes(action?.type))) {
      return;
    }

    const message = responseData?.message || rawResponse?.message || '';
    if (typeof message !== 'string' || message.trim() === '') {
      return;
    }

    const level = success ? 'success' : 'error';
    if (typeof NotificationManager[level] === 'function') {
      NotificationManager[level](message);
    }
  },

  async sendAction(actionUrl, data, tableId, submitEl, context = {}) {
    try {
      if (!actionUrl) {
        throw new Error('Action URL is required');
      }

      if (!data || typeof data !== 'object') {
        throw new Error('Invalid action data');
      }

      const table = this.state.tables.get(tableId);
      if (!table) {
        throw new Error(`Table ${tableId} not found`);
      }

      const submitButton = submitEl || document.createElement('button');
      submitButton.classList.add?.('loading');

      const requestData = {
        ...data,
        tableId
      };

      // Use HttpClient (window.http) with CSRF protection, fallback to simpleFetch
      const resp = await window.http.post(actionUrl, requestData);

      // Handle 403 Forbidden - let ResponseHandler show the error message
      if (resp?.status === 403) {

        const responseData = resp?.data?.data ?? resp?.data ?? resp;
        NotificationManager.error(responseData?.message || 'Access forbidden');

        submitButton.classList.remove?.('loading');

        EventManager.emit('table:error', {
          tableId,
          action: data.action,
          error: {message: responseData?.message || 'Access forbidden', status: 403},
          timestamp: Date.now()
        });

        return false;
      }

      // Handle 401 Unauthorized - redirect to login
      if (resp?.status === 401) {
        console.warn('TableManager: Unauthorized (401) for table action');

        const error = new Error(resp.statusText || 'Unauthorized');
        error.status = 401;
        error.response = resp;

        await AuthErrorHandler.handleError(error, {
          currentPath: window.location.pathname
        });

        submitButton.classList.remove?.('loading');
        return false;
      }

      const rawResponse = resp?.data ?? resp;
      const responseData = resp?.data?.data ?? resp?.data ?? resp;
      const success = responseData.success !== false;
      let didReloadTable = false;

      if (success) {
        this.invalidateTableDataSourceCache(tableId);
      }

      // Use ResponseHandler to process API response
      try {
        // Merge actions properly - preserve Array structure
        if (context.modalConfig && responseData.actions) {
          // If both are arrays, concatenate them
          if (Array.isArray(responseData.actions)) {
            responseData.actions = [
              ...(Array.isArray(context.modalConfig) ? context.modalConfig : [context.modalConfig]),
              ...responseData.actions
            ];
          } else {
            // If actions is object, merge as objects
            responseData.actions = {...context.modalConfig, ...responseData.actions};
          }
        } else if (context.modalConfig) {
          responseData.actions = context.modalConfig;
        }

        await ResponseHandler.process(responseData, {
          ...context,
          data: responseData,
          reloadPage: async () => window.location.reload(),
          reloadTable: async () => {
            didReloadTable = true;
            await this.loadTableData(tableId, {force: true});
          },
          reloadComponent: async () => {
            didReloadTable = true;
            await this.loadTableData(tableId, {force: true});
          }
        });
      } catch (error) {
        console.error('[TableManager] ResponseHandler error:', error);
      }

      this.showResponseMessageFallback(responseData, rawResponse, success);

      if (success && context.autoReloadOnSuccess && !didReloadTable) {
        await this.loadTableData(tableId, {force: true});
      }

      EventManager.emit('table:action', {
        tableId,
        action: data.action,
        success,
        response: responseData,
        timestamp: Date.now()
      });

      submitButton.classList.remove?.('loading');

      return success;

    } catch (error) {
      console.error('Table action error:', error);

      const submitButton = submitEl || {classList: {remove: () => {}}};
      submitButton.classList.remove?.('loading');

      if (window.NotificationManager) {
        NotificationManager.clear();
        NotificationManager.error(error.message || 'An error occurred');
      }

      EventManager.emit('table:error', {
        tableId,
        action: data.action,
        error,
        timestamp: Date.now()
      });

      return false;
    }
  },

  removeTableRows(table, ids) {
    if (!table?.element || !ids?.length) return;

    ids.forEach(id => {
      const row = table.element.querySelector(`tr[data-id="${id}"]`);
      if (!row) return;

      row.style.transition = 'opacity 0.3s';
      row.style.opacity = '0';

      setTimeout(() => {
        row.remove();

        this.updateTableInfo(table);

        EventManager.emit('table:rowRemoved', {
          tableId: table.id,
          rowId: id
        });
      }, 300);
    });
  },

  updateTableInfo(table) {
    const rowCount = table.element.querySelectorAll('tbody tr').length;

    if (rowCount === 0) {
      const tbody = table.element.querySelector('tbody');
      const tr = document.createElement('tr');
      const td = document.createElement('td');
      // Count only visible columns
      td.colSpan = table.element.querySelectorAll('thead th:not([style*="display: none"])').length;
      td.className = 'empty-table';
      td.textContent = Now.translate('No data available');
      tr.appendChild(td);
      tbody.appendChild(tr);
    }

    if (table.config.pagination) {
      const total = parseInt(table.config.params.total) - 1;
      table.config.params.total = total;

      if (table.paginationWrapper) {
        this.updatePagination(table, table.id, total,
          Math.ceil(total / table.config.params.pageSize));
      }
    }
  },

  setupKeyboardNavigation(table, tableId) {
    if (!table) return;

    const tableData = this.state.tables.get(tableId);
    if (!tableData) return;

    const handleKeydown = (event) => {
      const target = event.target;

      if (target.matches('th[data-sort]')) {
        switch (event.key) {
          case 'Enter':
          case ' ':
            event.preventDefault();
            this.handleSort(tableData, tableId, target);
            break;
          case 'ArrowLeft':
          case 'ArrowRight':
            event.preventDefault();
            this.navigateHeaders(table, target, event.key === 'ArrowRight');
            break;
        }
      }

      if (target.matches('tbody tr')) {
        const rows = Array.from(table.querySelectorAll('tbody tr'));
        const currentIndex = rows.indexOf(target);

        switch (event.key) {
          case 'ArrowUp':
            event.preventDefault();
            if (currentIndex > 0) {
              rows[currentIndex - 1].focus();
              this.announceRowChange(table, currentIndex - 1);
            }
            break;

          case 'ArrowDown':
            event.preventDefault();
            if (currentIndex < rows.length - 1) {
              rows[currentIndex + 1].focus();
              this.announceRowChange(table, currentIndex + 1);
            }
            break;

          case 'Home':
            event.preventDefault();
            rows[0].focus();
            this.announceRowChange(table, 0);
            break;

          case 'End':
            event.preventDefault();
            rows[rows.length - 1].focus();
            this.announceRowChange(table, rows.length - 1);
            break;
        }
      }
    };

    table.addEventListener('keydown', handleKeydown);

    if (!table.eventHandlers) table.eventHandlers = {};
    table.eventHandlers.keyboard = handleKeydown;
  },

  navigateHeaders(table, currentHeader, forward) {
    const headers = Array.from(table.querySelectorAll('th[data-sort]'));
    const currentIndex = headers.indexOf(currentHeader);
    let nextIndex;

    if (forward) {
      nextIndex = currentIndex < headers.length - 1 ? currentIndex + 1 : 0;
    } else {
      nextIndex = currentIndex > 0 ? currentIndex - 1 : headers.length - 1;
    }

    headers[nextIndex].focus();
  },

  announceRowChange(table, rowIndex) {
    const announcer = document.getElementById(table.dataset.announcer);
    if (announcer) {
      const row = table.querySelectorAll('tbody tr')[rowIndex];
      const firstCell = row.querySelector('td');
      announcer.textContent = Now.translate('Row') + ` ${rowIndex + 1}: ${firstCell?.textContent || ''}`;
    }
  },

  async loadData(tableId, source, params = {}) {
    const table = this.state.tables.get(tableId);
    if (!table) return;

    if (table.isLoading) return;
    table.isLoading = true;

    try {
      this.showLoading(table);

      const queryParams = new URLSearchParams({
        ...table.config.params,
        ...this.getSortParams(table),
        ...this.getPaginationParams(table),
        ...params
      });

      const finalUrl = `${source}?${queryParams}`;
      const resp = await http.get(finalUrl, {headers: {'Accept': 'application/json'}});
      const data = resp?.data || resp;


      this.setData(tableId, data.data);

      if (data.meta?.total) {
        this.updatePagination(table, tableId, data.meta);
      }

    } catch (error) {
      return this.handleError('Error loading table data', error, 'loadData');
    } finally {
      table.isLoading = false;
    }
  },

  showLoading(table) {
    const tbody = table.element.querySelector('tbody');
    tbody.innerHTML = `<tr><td colspan="100%" class="center">${Now.translate('Loading')}...</td></tr>`;
  },

  updateSelectedRows(table) {
    const checkboxes = table.querySelectorAll('.select-all');

    const selectedRows = [];

    checkboxes.forEach((checkbox, index) => {
      const row = checkbox.closest('tr');

      if (checkbox.checked) {
        selectedRows.push({
          index: index,
          row: row,
          data: this.getRowData(row)
        });

        row.classList.add('selected-row');
      } else {
        row.classList.remove('selected-row');
      }
    });

    this.updateSelectedCount(table, selectedRows.length);

    if (this.onSelectionChange) {
      this.onSelectionChange(selectedRows);
    }

    return selectedRows;
  },

  getRowData(row) {
    const cells = row.querySelectorAll('td');
    return Array.from(cells).map(cell => cell.textContent.trim());
  },

  // Parse data-row-actions attribute. Accepts JSON object or shorthand string 'print,edit,delete'
  parseRowActions(raw) {
    if (!raw) return null;
    if (typeof raw === 'object') return raw;
    try {
      // Try JSON first
      if (/^[\s]*\{/.test(raw) || /^[\s]*\[/.test(raw)) {
        return JSON.parse(raw);
      }
    } catch (e) {
      // fallthrough to shorthand
    }

    // Shorthand comma separated: 'print,edit,delete'
    return raw.split(',').map(s => s.trim()).reduce((acc, key) => {
      acc[key] = key.charAt(0).toUpperCase() + key.slice(1);
      return acc;
    }, {});
  },

  getRowActionTextConfig(key, cfg = {}) {
    const hasLabel = Object.prototype.hasOwnProperty.call(cfg, 'label');
    const explicitTitle = Object.prototype.hasOwnProperty.call(cfg, 'title')
      ? cfg.title
      : cfg?.attrs?.title;

    const label = hasLabel ? cfg.label : (explicitTitle !== undefined ? '' : key);
    const title = explicitTitle !== undefined ? explicitTitle : (hasLabel ? cfg.label : key);

    return {
      label: label == null ? '' : String(label),
      title: title == null ? '' : String(title)
    };
  },

  applyRowActionText(element, actionText) {
    if (!element) {
      return;
    }

    const visibleLabel = actionText.label ? Now.translate(actionText.label) : '';
    element.textContent = visibleLabel;

    const titleText = actionText.title ? Now.translate(actionText.title) : visibleLabel;
    if (titleText) {
      element.title = titleText;
      element.setAttribute('aria-label', titleText);
    } else {
      element.removeAttribute('title');
      element.removeAttribute('aria-label');
    }
  },

  // Create action cell for a row. actions can be:
  // { key: "Label" }
  // { key: { label: "Label", title: "Tooltip", params: { id: "{id}" }, method: 'POST', submenu: { ... } } }
  createActionCell(table, tableId, item, actions) {
    const td = document.createElement('td');
    td.className = 'row-actions-cell';

    const wrapper = document.createElement('div');
    wrapper.className = 'btn-group';

    const actionUrl = table.element.dataset.actionUrl || table.config.actionUrl;

    const elementManager = Now.getManager('element');

    Object.entries(actions).forEach(([key, cfg]) => {
      // normalize cfg to object
      cfg = (typeof cfg === 'string') ? {label: cfg} : (cfg || {});
      if (!this.evaluateTableCondition(cfg.condition, item)) {
        return;
      }
      const actionText = this.getRowActionTextConfig(key, cfg);

      // If submenu is provided, create a dropdown
      if (cfg && typeof cfg === 'object' && cfg.submenu) {
        const dropdown = document.createElement('div');
        dropdown.className = 'action-dropdown';

        const mainBtn = document.createElement('button');
        mainBtn.type = 'button';
        mainBtn.className = cfg.className || `action-btn action-${key}`;
        this.applyRowActionText(mainBtn, actionText);

        dropdown.appendChild(mainBtn);

        const menu = document.createElement('div');
        menu.className = 'action-submenu';
        Object.entries(cfg.submenu).forEach(([subKey, subCfg]) => {
          // submenu value can be string or object
          const subObj = (typeof subCfg === 'string') ? {label: subCfg} : subCfg || {};
          const subActionText = this.getRowActionTextConfig(subKey, subObj);
          let m;
          if (elementManager && subObj.element) {
            m = elementManager.create(subObj.element, {
              id: subObj.id || `row_action_${subKey}_${item.id || ''}`,
              type: subObj.type || 'button',
              value: subActionText.label,
              text: subActionText.label,
              className: subObj.className || `action-sub action-${subKey}`,
              wrapper: null,
              attrs: subObj.attrs || {}
            }).element;
          } else {
            m = document.createElement('button');
            m.type = 'button';
            m.className = subObj.className || `action-sub action-${subKey}`;
          }
          this.applyRowActionText(m, subActionText);
          m.addEventListener('click', (e) => {
            e.preventDefault();
            this._executeRowAction(tableId, actionUrl, item, subKey, subObj);
          });
          menu.appendChild(m);
        });

        dropdown.appendChild(menu);
        wrapper.appendChild(dropdown);
      } else {
        // Single action button
        let btnEl;
        if (elementManager && (cfg.element || cfg.className || cfg.attrs)) {
          // use ElementManager to create a richer element
          const cfgEl = {
            id: cfg.id || `row_action_${key}_${item.id || ''}`,
            type: cfg.type || 'button',
            value: actionText.label,
            text: actionText.label,
            className: cfg.className || `action-btn action-${key}`,
            wrapper: null,
            attrs: cfg.attrs || {}
          };
          btnEl = elementManager.create(cfg.element || 'button', cfgEl).element;
        } else {
          btnEl = document.createElement('button');
          btnEl.type = 'button';
          btnEl.className = cfg.className || `action-btn action-${key}`;
        }
        this.applyRowActionText(btnEl, actionText);
        btnEl.addEventListener('click', (e) => {
          e.preventDefault();
          this._executeRowAction(tableId, actionUrl, item, key, cfg);
        });
        wrapper.appendChild(btnEl);
      }
    });

    if (!wrapper.childNodes.length) {
      return null;
    }

    td.appendChild(wrapper);
    return td;
  },

  evaluateTableCondition(condition, item) {
    if (condition === undefined || condition === null || condition === '') {
      return true;
    }
    if (typeof condition === 'boolean') {
      return condition;
    }

    let expression = String(condition).trim();
    if (expression.startsWith('${') && expression.endsWith('}')) {
      expression = expression.slice(2, -1).trim();
    }

    try {
      if (window.ExpressionEvaluator && typeof ExpressionEvaluator.evaluate === 'function') {
        return !!ExpressionEvaluator.evaluate(expression, item || {}, {
          state: item || {},
          data: item || {},
          row: item || {},
          item: item || {}
        });
      }
    } catch (error) {
      console.warn('TableManager: Failed to evaluate condition', condition, error);
    }

    return !!(item && item[expression]);
  },

  // Internal executor for per-row actions
  async _executeRowAction(tableId, actionUrl, item, actionKey, cfg = null) {
    const table = this.state.tables.get(tableId);
    if (!table) return;

    // Extract modal config if defined
    const modalConfig = (cfg && cfg.modal) ? cfg.modal : null;

    try {
      let confirmMsg = null;
      if (cfg && cfg.confirm) {
        confirmMsg = typeof cfg.confirm === 'string' ? cfg.confirm : Now.translate('Are you sure you want to perform this action?');
      } else if (actionKey === 'delete' && table.config.confirmDelete !== false) {
        confirmMsg = Now.translate('Are you sure you want to delete this item?');
      }

      if (confirmMsg) {
        let confirmed = false;
        if (window.DialogManager && typeof DialogManager.confirm === 'function') {
          try {
            confirmed = await DialogManager.confirm(confirmMsg, Now.translate('Confirm'));
          } catch (err) {
            confirmed = false;
          }
        } else if (window.Modal) {
          // Use Modal as a nicer fallback for confirmation when DialogManager isn't available
          try {
            const modal = new Modal({
              title: Now.translate('Confirm'),
              content: `<div class="confirm-body">${confirmMsg}</div><div style="margin-top:1rem;text-align:right"><button class="modal-cancel">${Now.translate('Cancel')}</button> <button class="modal-confirm primary">${Now.translate('Confirm')}</button></div>`,
              className: 'modal-compact',
              backdrop: true
            });

            modal.show();

            confirmed = await new Promise(resolve => {
              const listener = (e) => {
                if (e.target.classList.contains('modal-confirm')) {
                  cleanup();
                  resolve(true);
                } else if (e.target.classList.contains('modal-cancel')) {
                  cleanup();
                  resolve(false);
                }
              };

              function cleanup() {
                try {modal.hide();} catch (err) { /* ignore */}
                setTimeout(() => {try {modal.modal?.remove();} catch (_) {} }, 300);
                document.removeEventListener('click', listener);
              }

              document.addEventListener('click', listener);
            });
          } catch (err) {
            console.error('Modal confirm error', err);
            confirmed = false;
          }
        } else {
          confirmed = confirm(confirmMsg);
        }

        if (!confirmed) return; // user canceled
      }
    } catch (err) {
      console.error('Confirmation error:', err);
      return;
    }

    // Build base payload
    let payload = {action: actionKey, id: item.id, row: item};

    // If action config provides params, interpolate templates against row data
    if (cfg && cfg.params && typeof cfg.params === 'object') {
      const interpolate = (val) => {
        if (typeof val !== 'string') return val;
        return val.replace(/\{([^}]+)\}/g, (m, key) => {
          // support nested keys like user.id
          const parts = key.split('.');
          let cur = item;
          for (let p of parts) {
            if (cur == null) return '';
            cur = cur[p];
          }
          return cur != null ? cur : '';
        });
      };

      const resolved = {};
      Object.entries(cfg.params).forEach(([k, v]) => {
        if (typeof v === 'object') {
          resolved[k] = JSON.stringify(v); // simple fallback
        } else {
          resolved[k] = interpolate(v);
        }
      });

      payload = {...payload, ...resolved};
    }

    // Allow overriding method (GET will perform navigation with query string)
    const method = (cfg && cfg.method) ? cfg.method.toUpperCase() : 'POST';

    if (actionUrl) {
      if (method === 'GET') {
        const params = new URLSearchParams();
        Object.entries(payload).forEach(([k, v]) => {
          if (typeof v === 'object') params.append(k, JSON.stringify(v));
          else params.append(k, v == null ? '' : v);
        });
        const dest = actionUrl + (actionUrl.indexOf('?') === -1 ? '?' : '&') + params.toString();
        window.location.href = dest;
        return;
      }

      // use existing sendAction helper which handles response (POST)
      try {
        const fakeButton = document.createElement('button');
        fakeButton.className = 'loading';
        await this.sendAction(actionUrl, payload, tableId, fakeButton, {
          modalConfig: modalConfig,
          table: table,
          row: item,
          action: actionKey
        });
      } catch (err) {
        console.error('Row action error:', err);
      }
    } else {
      // No actionUrl: emit an event for external handling, include payload
      EventManager.emit('table:rowAction', {tableId, action: actionKey, payload, row: item});
    }
  },

  updateSelectedCount(table, count) {
    const selectedCountElement = table.closest('.table-container')
      ?.querySelector('.selected-count');

    if (selectedCountElement) {
      selectedCountElement.textContent = Now.translate('Selected {count} rows', {count: count});

      selectedCountElement.style.display = count > 0 ? 'block' : 'none';
    }
  },

  formatValue(value, format, options = {}) {
    if (value === null || value === undefined) {
      return options.emptyText || '';
    }

    if (!format) return value;

    const getDecimals = (defaultValue) => {
      const parsed = parseInt(options.decimals, 10);
      return Number.isNaN(parsed) ? defaultValue : Math.max(0, parsed);
    };

    // If format is a function, use it
    if (typeof format === 'function') {
      return format(value, options);
    }

    try {
      switch (format) {
        case 'text':
          return String(value);

        case 'html':
          // Be cautious with HTML content - ensure it's sanitized
          return value;

        case 'boolean':
          return value ? (options.trueText || 'Yes') : (options.falseText || 'No');

        case 'lookup':
          // For select options display - support multiple formats
          // options can be: object {value: label}, array [{value, text/label}], or Map
          if (!options) {
            return value; // No options provided, return raw value
          }

          // Handle array format from API: [{value: "active", label: "Active"}, ...]
          if (Array.isArray(options)) {
            const found = options.find(opt => {
              if (opt && typeof opt === 'object') {
                const optVal = opt.value !== undefined ? opt.value : opt.key;
                return String(optVal) === String(value);
              }
              return String(opt) === String(value);
            });
            if (found) {
              return found.label || found.text || String(found.value || found);
            }
            return value; // No data available, return raw value
          }

          // Handle Map format
          if (options instanceof Map) {
            return options.get(value) || value;
          }

          // Handle object format: {value: label, ...}
          if (typeof options === 'object') {
            return options[value] !== undefined ? options[value] : value;
          }

          return value;

        case 'number':
          const numberDecimals = getDecimals(0);
          return new Intl.NumberFormat(options.locale, {
            minimumFractionDigits: numberDecimals,
            maximumFractionDigits: numberDecimals,
            useGrouping: options.useGrouping !== false
          }).format(value);

        case 'currency':
          return Utils.string.builtinFormatters.currency(
            value,
            options.currency || null,
            options.locale || 'en-US',
            getDecimals(2)
          );

        case 'percent':
          const percentDecimals = getDecimals(0);
          return new Intl.NumberFormat(options.locale, {
            style: 'percent',
            minimumFractionDigits: percentDecimals,
            maximumFractionDigits: percentDecimals
          }).format(value / (options.base || 100));

        case 'date':
          // Format as date (locale-aware by default)
          return Utils.date.format(value, options.pattern || 'D MMM YYYY');

        case 'time':
          // Format as time (locale-aware by default)
          return Utils.date.format(value, options.pattern || 'HH:mm');

        case 'datetime':
          // Format as datetime (locale-aware by default)
          return Utils.date.format(value, options.pattern || 'D MMM YYYY HH:mm');

        case 'bytes':
          return Utils.number.fileSize(value);

        case 'duration':
          // Format duration in seconds to readable format
          const seconds = Number(value);
          if (isNaN(seconds)) return value;

          const h = Math.floor(seconds / 3600);
          const m = Math.floor((seconds % 3600) / 60);
          const s = Math.floor(seconds % 60);

          const parts = [];
          if (h > 0) parts.push(`${h}${options.hourLabel || 'h'}`);
          if (m > 0 || h > 0) parts.push(`${m}${options.minuteLabel || 'm'}`);
          parts.push(`${s}${options.secondLabel || 's'}`);

          return parts.join(' ');

        case 'humanize':
          return Utils.string.humanize(value);

        case 'truncate':
          return Utils.string.truncate(String(value), options.length || 50, options.end || '...');

        default:
          // Try to use a global formatter if available
          if (window.formatters && typeof window.formatters[format] === 'function') {
            return window.formatters[format](value, options);
          }
          return value;
      }
    } catch (error) {
      console.warn('Format error:', error);
      return value;
    }
  },

  // Get sort parameters for API
  getSortParams(table) {
    const params = {};

    if (table.sortState && Object.keys(table.sortState).length > 0) {
      // Use compact format for API consistency with URL parameters
      // Convert to 'name asc,status desc' format
      const sortPairs = Object.entries(table.sortState).map(([field, direction]) => `${field} ${direction}`);

      if (sortPairs.length > 0) {
        params.sort = sortPairs.join(',');
      }
    }

    return params;
  },

  // Get pagination parameters for API
  getPaginationParams(table) {
    const params = {};

    if (table.config.params.page) {
      params.page = table.config.params.page;
    }

    if (table.config.params.pageSize) {
      params.pageSize = table.config.params.pageSize;
    }

    if (table.config.params.search) {
      params.search = table.config.params.search;
    }

    return params;
  },

  getFilterParams(table) {
    const params = {};
    const excludeKeys = ['page', 'pageSize', 'search', 'total', 'totalPages', 'sort', 'order'];

    // Include all filter parameters from config.params except pagination/meta keys
    Object.keys(table.config.params).forEach(key => {
      if (!excludeKeys.includes(key)) {
        const value = table.config.params[key];
        // Only include non-null/undefined values (allow empty strings)
        if (value !== null && value !== undefined) {
          params[key] = value;
        }
      }
    });

    return params;
  },

  async exportData(tableId, format = 'csv', options = {}) {
    const table = this.state.tables.get(tableId);
    if (!table) {
      return this.handleError('Table not found for export', null, 'exportData');
    }

    format = (format || 'csv').toLowerCase();

    // If server-side and an export URL is provided, delegate to the server
    const exportUrl = table.element.dataset.exportUrl || table.element.dataset.export || table.config.exportUrl;
    if (table.serverSide && exportUrl) {
      try {
        const currentLocale = Now.getRequestLocale();
        const params = new URLSearchParams({
          ...table.config.params,
          format,
          lang: currentLocale
        });
        const url = exportUrl.indexOf('?') === -1 ? `${exportUrl}?${params}` : `${exportUrl}&${params}`;
        const resp = await http.get(url, {method: 'GET'});
        // http.get returns parsed response, but for blobs we need to check response type
        if (resp?.data instanceof Blob) {
          const blob = resp.data;
          const disposition = resp.headers['content-disposition'] || '';
          let filename = options.filename || `${tableId}.${format}`;
          const m = disposition.match(/filename\*=UTF-8''([^;\n\r]+)/i) || disposition.match(/filename="?([^";\n\r]+)"?/i);
          if (m && m[1]) filename = decodeURIComponent(m[1]);
          this.downloadBlob(blob, filename);
          EventManager.emit('table:export', {tableId, format, success: true});
          return true;
        }

        // If http.get didn't return blob, request again with explicit blob handling
        if (!window.http || typeof window.http.get !== 'function') {
          throw new Error('HttpClient (window.http) is required but not available');
        }

        const blobResp = await window.http.get(url, {
          throwOnError: false,
          headers: {'Accept': 'application/octet-stream'}
        });

        if (!blobResp.success) throw new Error('Export request failed');
        const blob = blobResp.data instanceof Blob ? blobResp.data : new Blob([blobResp.data]);
        const disposition = blobResp.headers['content-disposition'] || '';
        let filename = options.filename || `${tableId}.${format}`;
        const m = disposition.match(/filename\*=UTF-8''([^;\n\r]+)/i) || disposition.match(/filename="?([^";\n\r]+)"?/i);
        if (m && m[1]) filename = decodeURIComponent(m[1]);
        this.downloadBlob(blob, filename);
        EventManager.emit('table:export', {tableId, format, success: true});
        return true;
      } catch (err) {
        console.error('Export error (server):', err);
        EventManager.emit('table:export', {tableId, format, success: false, error: err});
        return false;
      }
    }

    // Client-side export: apply filtering and sorting first
    let rawData = Array.isArray(table.data) ? table.data : [];

    // Apply filtering and sorting (same as what user sees)
    let exportData = this.filterData(table, rawData);
    exportData = this.sortData(table, exportData);

    try {
      // Determine columns order and get column attributes
      let columns = [];
      let columnLabels = {};
      let columnAttributes = {};

      try {
        if (table.columns && table.columns.size) {
          columns = Array.from(table.columns.keys());
          // Get column headers and attributes for formatting
          table.columns.forEach((attrs, field) => {
            columnAttributes[field] = attrs;
            // Get header label from th element
            const th = table.element.querySelector(`th[data-field="${field}"]`);
            columnLabels[field] = th ? th.textContent.trim() : field;
          });
        }
      } catch (e) {
        columns = [];
      }

      if (!columns.length && exportData.length) {
        columns = Object.keys(exportData[0]);
        columns.forEach(col => {
          columnLabels[col] = col;
          columnAttributes[col] = {};
        });
      }

      // Helper function to format cell value for export
      const formatExportValue = (value, field) => {
        const attrs = columnAttributes[field] || {};

        // Use formatValue with lookup to get rendered text
        if (attrs.format || attrs.options) {
          const formatted = this.formatValue(value, attrs.format || 'lookup', {
            options: attrs.options || {},
            ...attrs
          });
          return formatted;
        }

        return value;
      };

      if (format === 'json') {
        // For JSON export, format the values as well
        const formattedData = exportData.map(row => {
          const formattedRow = {};
          columns.forEach(col => {
            formattedRow[columnLabels[col] || col] = formatExportValue(row[col], col);
          });
          return formattedRow;
        });
        const json = JSON.stringify(formattedData, null, 2);
        const blob = new Blob([json], {type: 'application/json;charset=utf-8'});
        this.downloadBlob(blob, options.filename || `${tableId}.json`);
        EventManager.emit('table:export', {tableId, format, success: true, count: exportData.length});
        return true;
      }

      // default: csv
      // Build CSV string
      const escape = (v) => {
        if (v === null || v === undefined) return '';
        let s = v;
        if (typeof s === 'object') s = JSON.stringify(s);
        s = String(s);
        if (s.indexOf('"') !== -1) s = s.replace(/"/g, '""');
        if (/[",\n\r]/.test(s)) s = `"${s}"`;
        return s;
      };

      // Use column labels (header text) instead of field names
      const header = columns.map(col => escape(columnLabels[col] || col)).join(',');

      // Format each cell value before escaping
      const rows = exportData.map(row =>
        columns.map(col => escape(formatExportValue(row[col], col))).join(',')
      );

      const csv = [header, ...rows].join('\r\n');
      const blob = new Blob([csv], {type: 'text/csv;charset=utf-8'});
      this.downloadBlob(blob, options.filename || `${tableId}.csv`);
      EventManager.emit('table:export', {tableId, format: 'csv', success: true, count: exportData.length});
      return true;
    } catch (err) {
      console.error('Export error (client):', err);
      EventManager.emit('table:export', {tableId, format, success: false, error: err});
      return false;
    }
  },

  downloadBlob(blob, filename) {
    try {
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      setTimeout(() => {
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
      }, 1000);
    } catch (err) {
      console.error('Failed to initiate download:', err);
    }
  },

  resetState() {
    this.state = {
      initialized: false,
      tables: new Map()
    };
    this.config = {};
  }
};

if (window.Now?.registerManager) {
  Now.registerManager('table', TableManager);
}

// Expose globally
window.TableManager = TableManager;
