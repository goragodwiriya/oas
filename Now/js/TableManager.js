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
    // Bring the table back as the user left it (page size, sort, filters; page
    // and search for the rest of the tab session) when the address carries no
    // table parameters. data-remember-state="false" turns it off per table.
    rememberState: true,
    rowSortable: false,
    allowRowModification: false,
    confirmDelete: true,
    dynamicColumns: false, // Enable dynamic column generation from API response
    source: '',
    loadTarget: '',
    method: 'GET', // HTTP method for API calls (GET or POST)
    cache: false,
    cacheTime: 60000,
    refreshInterval: 0, // Seconds between automatic reloads from data-source. 0 disables it.
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

  /**
   * Reports whether a table renders its own rows through data-bind or a
   * data-attr binding, in which case this manager must not render them.
   *
   * @param {HTMLTableElement} table - Table element
   * @returns {boolean} True when the markup owns the rendering
   */
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

  /**
   * Reports whether ElementManager has finished initializing, since cells that
   * hold form elements cannot be built before it has.
   *
   * @returns {boolean} True when it is ready
   */
  isElementManagerReady() {
    const manager = window.Now?.getManager ? Now.getManager('element') : window.ElementManager;
    return Boolean(manager?.state?.initialized);
  },

  /**
   * Retries a render on the next frame while its dependency is still starting
   * up, giving up after 40 attempts so a missing dependency does not spin
   * forever.
   *
   * @param {string} tableId - Table id
   * @param {string} [reason='element-manager-not-ready'] - What is being waited for
   * @returns {boolean} True when a retry was scheduled
   */
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

  /**
   * Initializes the manager: merges the configuration and starts watching the
   * DOM for tables, through CoreObserver when it exists and its own observer
   * otherwise.
   *
   * Calling it again once initialized is a no-op.
   *
   * @param {Object} [options={}] - Configuration merged over the defaults
   * @returns {Promise<Object>} The manager instance
   */
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

    // Several tables on one page keep their parameters apart as tableId.key;
    // a lone table uses the plain names
    const prefix = this.getUrlPrefix(tableId);
    const tableIds = prefix ? this.getUrlTableIds() : [];

    for (const [rawKey, value] of urlParams.entries()) {
      let key = rawKey;
      if (prefix) {
        const dot = rawKey.indexOf('.');
        if (rawKey.startsWith(prefix)) {
          key = rawKey.substring(prefix.length);
        } else if (dot > 0 && tableIds.includes(rawKey.substring(0, dot))) {
          continue; // another table's parameter
        } else if (['page', 'pageSize', 'search', 'sort'].includes(rawKey)) {
          continue; // unprefixed table state is ambiguous here
        }
      }
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

  /**
   * Writes table parameters into the address bar, in history or hash mode as
   * the router is configured, leaving the other query parameters alone.
   *
   * Does nothing when the table has URL parameters turned off.
   *
   * @param {string} tableId - Table id
   * @param {Object} [newParams={}] - Parameters to write; empty ones are removed
   * @returns {void}
   */
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

    // With several tables on the page each one writes tableId.key, and only its
    // own state — the page's other parameters (module_id ...) stay as they are
    const prefix = this.getUrlPrefix(tableId);
    const ownKeys = prefix ? this.getTableStateKeys(table) : null;
    if (ownKeys) {
      columnFields.forEach(field => ownKeys.add(field));
    }

    // Remove all table-related parameters (but not internal ones - they shouldn't be there anyway)
    const keysToRemove = ownKeys ? [...ownKeys] : [...commonTableParams, ...columnFields];
    keysToRemove.forEach(key => {
      // Remove all instances of the key (important for array params like sort[])
      while (urlParams.has(prefix + key)) {
        urlParams.delete(prefix + key);
      }
    });

    // Add new parameters (plain names, or tableId.key beside other tables)
    Object.entries(newParams).forEach(([key, value]) => {
      if (value === undefined || value === null || value === '') return;

      // Skip internal parameters that shouldn't appear in URLs
      if (internalParams.includes(key)) return;
      if (ownKeys && !ownKeys.has(key)) return;

      if (Array.isArray(value)) {
        // Handle array parameters (for multi-sort)
        value.forEach(v => {
          if (v !== undefined && v !== null && v !== '') {
            urlParams.append(prefix + key, v);
          }
        });
      } else {
        urlParams.set(prefix + key, value);
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
      window.history.replaceState(window.history.state, '', newUrl);
    } catch (e) {
      console.warn('Failed to update URL parameters:', e);
    }
  },

  /**
   * Mirrors the current sort, page and filters of a table into the address bar,
   * so the view survives a reload and can be shared as a link.
   *
   * Internal parameters such as the record totals are not published.
   *
   * @param {string} tableId - Table id
   * @returns {void}
   */
  syncStateToUrl(tableId) {
    const table = this.state.tables.get(tableId);
    if (!table) return;

    // Every sort, filter, page and page-size change comes through here
    this.saveRememberedState(tableId);

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

  /**
   * Applies the table parameters found in the address bar to a table, so a
   * shared link opens on the same page, sort and filters.
   *
   * @param {string} tableId - Table id
   * @returns {boolean} True when parameters were applied
   */
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

  /**
   * The route part of the address: the path, or the route inside the hash.
   *
   * @returns {string}
   */
  getRoutePath() {
    if (window.location.href.includes('#')) {
      const hash = window.location.hash;
      const queryIndex = hash.indexOf('?');
      return queryIndex === -1 ? hash : hash.substring(0, queryIndex);
    }
    return window.location.pathname;
  },

  /**
   * The query string of the route (history or hash mode), without the "?".
   *
   * @returns {string}
   */
  getRouteQuery() {
    if (window.location.href.includes('#')) {
      const hash = window.location.hash;
      const queryIndex = hash.indexOf('?');
      return queryIndex === -1 ? '' : hash.substring(queryIndex + 1);
    }
    return window.location.search.substring(1);
  },

  /**
   * Ids of the tables on the page that keep their state in the address.
   *
   * @returns {string[]}
   */
  getUrlTableIds() {
    return Array.from(document.querySelectorAll('table[data-table]'))
      .filter(el => {
        const setting = el.dataset.urlParams;
        return setting !== undefined ? setting !== 'false' : this.config.urlParams !== false;
      })
      .map(el => el.dataset.table);
  },

  /**
   * "tableId." when the page shows more than one table with URL state (their
   * page, sort and filters would otherwise overwrite each other), else "".
   *
   * @param {string} tableId - Table id
   * @returns {string}
   */
  getUrlPrefix(tableId) {
    const ids = this.getUrlTableIds();
    return ids.length > 1 && ids.includes(tableId) ? `${tableId}.` : '';
  },

  /**
   * The parameters that are a table's own state: page, page size, search,
   * sort and every filter control.
   *
   * @param {Object} table - Table instance
   * @returns {Set<string>}
   */
  getTableStateKeys(table) {
    const keys = new Set(['page', 'pageSize', 'search', 'sort']);
    if (table?.filterElements) {
      table.filterElements.forEach((entry, key) => keys.add(key));
    }
    return keys;
  },

  /**
   * Where a table's remembered state is stored: the route, the address
   * parameters that are not the table's own (the list of module_id=5 is not
   * the list of module_id=6) and the table id.
   *
   * @param {Object} table - Table instance
   * @returns {string}
   */
  getRememberKey(table) {
    const own = this.getTableStateKeys(table);
    const tableIds = this.getUrlTableIds();
    const context = [];
    new URLSearchParams(this.getRouteQuery()).forEach((value, key) => {
      const dot = key.indexOf('.');
      if (own.has(key) || (dot > 0 && tableIds.includes(key.substring(0, dot)))) return;
      context.push(`${key}=${value}`);
    });
    context.sort();
    return `now.table:${this.getRoutePath()}${context.length ? '?' + context.join('&') : ''}:${table.id}`;
  },

  /**
   * Reads a JSON value from web storage; null when absent, unreadable or when
   * storage is unavailable (private mode, blocked cookies).
   *
   * @param {string} area - 'local' or 'session'
   * @param {string} key - Storage key
   * @returns {*}
   */
  readStorage(area, key) {
    try {
      const raw = window[`${area}Storage`].getItem(key);
      return raw === null ? null : JSON.parse(raw);
    } catch (error) {
      return null;
    }
  },

  /**
   * Writes a JSON value to web storage (null removes it); failures are ignored
   * because remembering is a convenience.
   *
   * @param {string} area - 'local' or 'session'
   * @param {string} key - Storage key
   * @param {*} value - Value to store
   * @returns {void}
   */
  writeStorage(area, key, value) {
    try {
      const storage = window[`${area}Storage`];
      if (value === null || value === undefined) {
        storage.removeItem(key);
      } else {
        storage.setItem(key, JSON.stringify(value));
      }
    } catch (error) {
      // storage full or unavailable
    }
  },

  /**
   * Parses a compact sort ("name asc,status desc") keeping only the columns
   * the table can sort by now.
   *
   * A table with data-dynamic-columns has no header yet when its state is
   * restored — the columns arrive with the first response. Its sort is taken
   * on trust then (the server allow-lists sort columns anyway), marked
   * unverified, and checked against the header once it is built
   * (see pruneUnverifiedSort).
   *
   * @param {Object} table - Table instance
   * @param {string} sort - Compact sort
   * @returns {Object} {field: 'asc'|'desc'}
   */
  parseRememberedSort(table, sort) {
    const sortable = new Set(Array.from(table.element.querySelectorAll('thead th[data-sort]'))
      .map(th => th.dataset.sort));
    const headerPending = table.config.dynamicColumns && sortable.size === 0;
    const state = {};
    String(sort).split(',').forEach(pair => {
      const [field, direction = 'asc'] = pair.trim().split(/\s+/);
      const dir = direction.toLowerCase();
      const known = headerPending ? /^[A-Za-z_][\w.]*$/.test(field || '') : sortable.has(field);
      if (field && known && ['asc', 'desc'].includes(dir)) {
        state[field] = dir;
      }
    });
    table.unverifiedSort = headerPending ? Object.keys(state) : null;
    return state;
  },

  /**
   * Drops the sort columns restored before a dynamic header existed that the
   * header, now built, does not offer — a column removed since the state was
   * remembered — and stores the corrected state.
   *
   * @param {Object} table - Table instance
   * @returns {void}
   */
  pruneUnverifiedSort(table) {
    const fields = table.unverifiedSort;
    table.unverifiedSort = null;
    if (!fields || fields.length === 0) return;

    const sortable = new Set(Array.from(table.element.querySelectorAll('thead th[data-sort]'))
      .map(th => th.dataset.sort));
    const stale = fields.filter(field => !sortable.has(field) && table.sortState?.[field]);
    if (stale.length === 0) return;

    stale.forEach(field => delete table.sortState[field]);
    this.syncStateToUrl(table.id);
  },

  /**
   * Stores what the user chose for a table: page size, sort and filters for
   * good (localStorage), page and search for the tab session
   * (sessionStorage) — coming back from an edit form lands on the same page,
   * a new visit starts at page one without a stale search.
   *
   * @param {string} tableId - Table id
   * @returns {void}
   */
  saveRememberedState(tableId) {
    const table = this.state.tables.get(tableId);
    if (!table || table.config.rememberState === false) return;

    const params = table.config.params;
    const key = this.getRememberKey(table);

    const kept = {};
    if (table.filterElements.has('pageSize') && params.pageSize > 0) {
      kept.pageSize = params.pageSize;
    }
    const sortPairs = Object.entries(table.sortState || {}).map(([field, dir]) => `${field} ${dir}`);
    if (sortPairs.length > 0) {
      kept.sort = sortPairs.join(',');
    }
    const filters = {};
    table.filterElements.forEach((entry, name) => {
      if (name === 'pageSize' || name === 'search') return;
      const value = params[name];
      filters[name] = value === undefined || value === null ? '' : value;
    });
    if (Object.keys(filters).length > 0) {
      kept.filters = filters;
    }
    this.writeStorage('local', key, Object.keys(kept).length > 0 ? kept : null);

    const session = {};
    if (parseInt(params.page, 10) > 1) {
      session.page = parseInt(params.page, 10);
    }
    if (typeof params.search === 'string' && params.search !== '') {
      session.search = params.search;
    }
    this.writeStorage('session', key, Object.keys(session).length > 0 ? session : null);
  },

  /**
   * Applies the remembered state of a table (see saveRememberedState). Only
   * what still fits is used: a page size the selector offers, filters that
   * still exist and columns that can still be sorted.
   *
   * @param {string} tableId - Table id
   * @returns {boolean} True when something was applied
   */
  loadRememberedState(tableId) {
    const table = this.state.tables.get(tableId);
    if (!table || table.config.rememberState === false) return false;

    const key = this.getRememberKey(table);
    const kept = this.readStorage('local', key);
    const session = this.readStorage('session', key);
    let applied = false;

    if (kept && typeof kept === 'object') {
      if (kept.pageSize !== undefined && table.filterElements.has('pageSize')) {
        const sizes = (table.config.pageSizes || []).map(size => String(size).trim());
        if (sizes.includes(String(kept.pageSize))) {
          table.config.params.pageSize = parseInt(kept.pageSize, 10);
          applied = true;
        }
      }
      if (kept.filters && typeof kept.filters === 'object') {
        Object.entries(kept.filters).forEach(([name, value]) => {
          if (name !== 'pageSize' && name !== 'search' && table.filterElements.has(name)) {
            table.config.params[name] = value;
            applied = true;
          }
        });
      }
      if (typeof kept.sort === 'string' && kept.sort !== '') {
        const sortState = this.parseRememberedSort(table, kept.sort);
        if (Object.keys(sortState).length > 0) {
          table.sortState = sortState;
          applied = true;
        }
      }
    }

    if (session && typeof session === 'object') {
      if (typeof session.search === 'string' && table.filterElements.has('search')) {
        table.config.params.search = session.search;
        applied = true;
      }
      const page = parseInt(session.page, 10);
      if (page > 1) {
        table.config.params.page = page;
        applied = true;
      }
    }

    return applied;
  },

  /**
   * Forgets the remembered state of a table.
   *
   * @param {string} tableId - Table id
   * @returns {void}
   */
  forgetRememberedState(tableId) {
    const table = this.state.tables.get(tableId);
    if (!table) return;
    const key = this.getRememberKey(table);
    this.writeStorage('local', key, null);
    this.writeStorage('session', key, null);
  },

  /**
   * Writes the loaded filter values back into the filter controls, so the UI
   * shows what the table is actually filtered by.
   *
   * @param {string} tableId - Table id
   * @returns {void}
   */
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

  /**
   * Returns the tfoot of a table, building one that mirrors the header when the
   * markup has none and the footer is enabled.
   *
   * @param {Object} table - Table instance
   * @param {HTMLTableRowElement} [headerRow=null] - Row to mirror; the first header row by default
   * @returns {HTMLTableSectionElement|null} The tfoot, or null when disabled
   */
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

  /**
   * Fills the footer cells with their aggregates, reading each cell's
   * data-aggregate and data-field, and formatting the result like the column.
   *
   * @param {Object} table - Table instance
   * @param {Map} columns - Column definitions
   * @param {HTMLTableSectionElement} tfoot - Footer to fill
   * @returns {void}
   */
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

  /**
   * Maps the cells of a table section to their real column positions, following
   * the colspan and rowspan of the cells above and beside them.
   *
   * @param {HTMLTableSectionElement} section - thead or tfoot
   * @returns {Array[]} Per row, entries of {cell, columnIndex, colspan}
   */
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

  /**
   * Remembers the markup and classes a footer cell started with, so an
   * aggregate can be undone without losing what the page author wrote.
   *
   * @param {HTMLTableCellElement} cell - Footer cell
   * @returns {void}
   */
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

  /**
   * Restores a footer cell to the markup and classes it started with.
   *
   * @param {HTMLTableCellElement} cell - Footer cell
   * @returns {void}
   */
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

  /**
   * Reads the aggregate a footer cell asks for from its data attributes.
   *
   * @param {HTMLTableCellElement} cell - Footer cell
   * @returns {Object} {type, field, customFn}, all null when the cell asks for none
   */
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

  /**
   * Parses a cell value as a number for aggregation, tolerating thousands
   * separators.
   *
   * @param {*} value - Value to parse
   * @returns {number|null} The number, or null when it is not one
   */
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

  /**
   * Computes an aggregate over a column, ignoring the rows whose value is
   * empty.
   *
   * @param {Object[]} data - Rows to aggregate
   * @param {string} field - Column to aggregate
   * @param {string} type - 'sum', 'avg', 'count', 'min' or 'max'
   * @returns {Object} {processable, value}, processable false when the column
   *   holds nothing that can be aggregated
   */
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

  /**
   * Applies the classes and alignment of a column to its footer cell, so the
   * total lines up with the values above it.
   *
   * @param {HTMLTableCellElement} cell - Footer cell
   * @param {Object} column - Column definition
   * @returns {void}
   */
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
    cell.style.textAlign = cell.dataset.footerOriginalAlign || '';
  },

  /**
   * Initializes a table: reads its configuration from the data attributes,
   * builds its structure, filters, actions and accessibility, restores the
   * state from the URL and loads its data.
   *
   * @param {HTMLTableElement} table - Table carrying data-table
   * @param {Object} [options={}] - Configuration merged over the attributes
   * @returns {Object|undefined} The instance, or the result of handleError()
   */
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
      tableObj = this.state.tables.get(tableId);
      const urlParamsNow = this.getUrlParams(tableId);
      const ownKeys = this.getTableStateKeys(tableObj);
      const urlHasTableState = Object.keys(urlParamsNow).some(key => ownKeys.has(key));
      const urlStateLoaded = this.loadStateFromUrl(tableId);
      // An address with table parameters (a reload, a shared link) is shown as
      // it is; without them the table comes back as the user left it, and the
      // address then says so
      const rememberedStateLoaded = !urlHasTableState && this.loadRememberedState(tableId);
      if (urlStateLoaded || rememberedStateLoaded) {
        // Restore filter UI values from loaded URL state
        this.restoreFilterUIFromState(tableId);
      }
      if (rememberedStateLoaded) {
        this.syncStateToUrl(tableId);
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

      // Off unless data-refresh-interval / config asked for it, so existing tables
      // behave exactly as before
      this.startAutoRefresh(tableId);

      return tableId;

    } catch (error) {
      return this.handleError('Failed to initialize table', error, 'init');
    }
  },

  /**
   * Start reloading a table from its source on a timer.
   *
   * Reads `refreshInterval` (seconds) from the table config, which comes from
   * `data-refresh-interval` or from options passed to `initTable()`. Zero, negative
   * and non-numeric values leave the table alone, so this is safe to call on every
   * table unconditionally.
   *
   * Deliberate behaviour, learned from screens people leave open all day:
   *
   *  - **Chained `setTimeout`, never `setInterval`.** The next tick is scheduled only
   *    after the previous reload settles, so a slow endpoint can never stack requests.
   *  - **Hidden tabs do not poll.** A page left open overnight would otherwise fire
   *    thousands of requests nobody ever sees. When the tab comes back and a tick was
   *    missed, it reloads once immediately because the data on screen is stale.
   *  - **A tick is skipped while rows are selected.** Reloading calls `clearSelection`,
   *    so refreshing under someone who is halfway through picking rows would silently
   *    throw their selection away.
   *
   * @param {string} tableId
   * @returns {boolean} true when a timer is now running
   */
  startAutoRefresh(tableId) {
    const table = this.state.tables.get(tableId);
    if (!table) return false;

    this.stopAutoRefresh(tableId);

    const seconds = Number(table.config?.refreshInterval) || 0;
    if (!Number.isFinite(seconds) || seconds <= 0) return false;

    // Nothing to reload from: declarative/state-bound tables get their data pushed in
    if (!table.element?.dataset?.source) return false;

    table._autoRefresh = {
      seconds,
      timer: null,
      missedWhileHidden: false,
      onVisibility: null
    };

    const tick = async () => {
      const current = this.state.tables.get(tableId);
      if (!current?._autoRefresh) return;

      const hidden = typeof document !== 'undefined' && document.visibilityState === 'hidden';
      const busy = Array.isArray(current.selectedRows) && current.selectedRows.length > 0;

      if (hidden) {
        current._autoRefresh.missedWhileHidden = true;
      } else if (!busy) {
        try {
          await this.loadTableData(tableId, {force: true});

          EventManager.emit('table:refreshed', {
            tableId,
            automatic: true,
            timestamp: Date.now()
          });
        } catch (error) {
          // A failed poll must not kill the timer — the endpoint may just be
          // briefly unavailable, and stopping here would leave the screen frozen
          // with no sign that refreshing has given up
          this.handleError('Auto refresh failed', error, 'refresh');
        }
      }

      schedule();
    };

    const schedule = () => {
      const current = this.state.tables.get(tableId);
      if (!current?._autoRefresh) return;

      clearTimeout(current._autoRefresh.timer);
      current._autoRefresh.timer = setTimeout(tick, seconds * 1000);
    };

    if (typeof document !== 'undefined') {
      table._autoRefresh.onVisibility = () => {
        const current = this.state.tables.get(tableId);
        if (!current?._autoRefresh) return;

        if (document.visibilityState !== 'visible') return;
        if (!current._autoRefresh.missedWhileHidden) return;

        current._autoRefresh.missedWhileHidden = false;
        clearTimeout(current._autoRefresh.timer);
        tick();
      };

      document.addEventListener('visibilitychange', table._autoRefresh.onVisibility);
    }

    schedule();

    return true;
  },

  /**
   * Stop the auto refresh timer for a table. Safe to call when none is running.
   * @param {string} tableId
   */
  stopAutoRefresh(tableId) {
    const table = this.state.tables.get(tableId);
    if (!table?._autoRefresh) return;

    clearTimeout(table._autoRefresh.timer);

    if (table._autoRefresh.onVisibility && typeof document !== 'undefined') {
      document.removeEventListener('visibilitychange', table._autoRefresh.onVisibility);
    }

    table._autoRefresh = null;
  },

  /**
   * Change the refresh rate at runtime — the hook a UI control binds to.
   *
   * Pass 0 to turn refreshing off. The new value is written back to the table config
   * so a later `startAutoRefresh()` keeps it.
   *
   * @param {string} tableId
   * @param {number} seconds Seconds between reloads, 0 to stop
   * @returns {boolean} true when a timer is now running
   */
  setRefreshInterval(tableId, seconds) {
    const table = this.state.tables.get(tableId);
    if (!table) return false;

    const value = Number(seconds);
    table.config.refreshInterval = Number.isFinite(value) && value > 0 ? value : 0;

    return this.startAutoRefresh(tableId);
  },

  /**
   * Seconds between automatic reloads, 0 when refreshing is off.
   * @param {string} tableId
   * @returns {number}
   */
  getRefreshInterval(tableId) {
    const table = this.state.tables.get(tableId);

    return Number(table?.config?.refreshInterval) || 0;
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

  /**
   * Reads the configuration an element declares through data attributes,
   * coercing each value to the type of its default.
   *
   * Attributes with no matching default are ignored.
   *
   * @param {HTMLElement} element - Element to read
   * @param {Object} defaultConfig - Defaults, which set the expected types
   * @returns {Object} Configuration from the attributes
   */
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

  /**
   * Resolves a target given as an element or a selector, searching around the
   * table first and then the document.
   *
   * @param {HTMLElement|string} target - Element or selector
   * @param {Object} [table=null] - Table instance to search around
   * @returns {HTMLElement|null} The element, or null
   */
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

  /**
   * Normalizes a response into the {data, meta} shape the load target expects,
   * treating a bare array as a single unpaginated page.
   *
   * @param {*} payload - Response body
   * @param {Object} [table=null] - Table instance, for its configuration
   * @returns {Object} {data, meta}
   */
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

  /**
   * Renders a loaded response into the element named by data-load-target,
   * through TemplateManager, so a table can drive a summary panel beside it.
   *
   * @param {string|Object} tableId - Table id or instance
   * @param {*} payload - Response body
   * @returns {Object|null} The binding, or null when there is no target
   */
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

  /**
   * Adds the select-all checkboxes to the header and footer of a table whose
   * rows are selectable.
   *
   * @param {Object} table - Table instance
   * @param {string} tableId - Table id
   * @returns {void}
   */
  setupCheckboxes(table, tableId) {
    if (!table.config.showCheckbox) return;

    ['thead', 'tfoot'].forEach(section => {
      const tr = table.element.querySelector(`${section} tr:first-child`);
      if (tr) {
        let checkboxId = `select-all-${tableId}-${section}`;
        let checkbox = tr.querySelector('.select-all');
        // Reuse the cell the markup or an earlier pass already put in place,
        // whether or not it holds a checkbox yet, so the row never gains a
        // second one and drifts out of step with the other sections.
        let cell = checkbox
          ? checkbox.closest('th, td')
          : tr.querySelector('.check-column');

        if (!cell) {
          cell = document.createElement(section === 'thead' ? 'th' : 'td');
          const rowspan = table.element.querySelectorAll(`${section} tr:first-child`).length;
          if (rowspan > 1) {
            cell.setAttribute('rowspan', rowspan);
          }
          tr.insertBefore(cell, tr.firstChild);
        }
        cell.classList.add('check-column');

        if (!checkbox) {
          const label = document.createElement('label');
          label.htmlFor = checkboxId;

          checkbox = document.createElement('input');
          checkbox.type = 'checkbox';

          label.appendChild(checkbox);
          cell.appendChild(label);
        } else {
          if (checkbox.id) {
            checkboxId = checkbox.id;
          }
          const label = checkbox.closest('label');
          if (label) {
            label.htmlFor = checkboxId;
          }
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

  /**
   * Checks or clears every row checkbox and keeps the select-all boxes in the
   * header and footer in step.
   *
   * @param {Object} table - Table instance
   * @param {string} tableId - Table id
   * @param {boolean} checked - New state
   * @returns {void}
   */
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

  /**
   * Recomputes the selection after a row checkbox changed: updates the
   * select-all boxes, including their indeterminate state, and the count.
   *
   * @param {Object} table - Table instance
   * @param {string} tableId - Table id
   * @returns {void}
   */
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

  /**
   * Clears the row selection and the highlight that goes with it.
   *
   * @param {Object} table - Table instance
   * @param {string} tableId - Table id
   * @param {Object} [options={}] - Clear options
   * @param {boolean} [options.emit=true] - Emit the selection event
   * @returns {void}
   */
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

  /**
   * Removes the listeners bound to the bulk-action controls, so rebinding them
   * does not stack a second copy.
   *
   * @param {Object} table - Table instance
   * @returns {void}
   */
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

  /**
   * Binds the bulk-action controls: the action select and the submit button
   * that applies it to the selected rows.
   *
   * @param {Object} table - Table instance
   * @param {string} tableId - Table id
   * @returns {void}
   */
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

  /**
   * Builds the parts of a table around its data: the header, the footer, the
   * checkbox column, the filter area, the action area and the pagination.
   *
   * @param {string} tableId - Table id
   * @returns {void}
   */
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

  /**
   * Binds the events of a table: sorting on the headers, and delegated click
   * and change handlers on the table itself, so rows re-rendered later need no
   * rebinding.
   *
   * @param {string} tableId - Table id
   * @returns {void}
   */
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
        // Ignore the click that ends a column-resize drag.
        // Use tableEl-level flag (data-col-resizing) because th.dataset.resizing
        // may be on a different th instance when headers are re-rendered.
        if (tableEl.dataset.colResizing || e.target.classList.contains('col-resizer')) {
          return;
        }
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

  /**
   * Destroys the filter controls of a table through ElementManager, before the
   * filter area is rebuilt.
   *
   * @param {Object} table - Table instance
   * @returns {void}
   */
  cleanupFilterEvents(table) {
    if (!table.filterElements) return;

    table.filterElements.forEach((element, elementId) => {
      if (elementId) {
        ElementManager.destroy(elementId);
      }
    });

    table.filterElements.clear();
  },

  /**
   * Sorts by the column of a header: cycles its direction, and adds it to the
   * existing sort rather than replacing it when the modifier key is held.
   *
   * @param {Object} table - Table instance
   * @param {string} tableId - Table id
   * @param {HTMLTableCellElement} th - Header that was activated
   * @param {Event} [event=null] - Originating event, read for the modifier key
   * @returns {void}
   * @throws {Error} When the arguments are not usable
   */
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

  /**
   * Handles an edit made in a cell: writes the value back into the row data and
   * posts it to the action endpoint.
   *
   * @param {Object} table - Table instance
   * @param {string} tableId - Table id
   * @param {string} field - Column that changed
   * @param {*} value - New value
   * @param {Object} rowData - Row that changed
   * @param {HTMLElement} element - Control that changed
   * @param {Object} [options={send: true}] - Set send false to update locally only
   * @returns {void}
   */
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

  /**
   * Builds the filter controls of a table from the filterable columns.
   *
   * An external filter form keeps its own markup and only its metadata is read;
   * an internal one is rebuilt from the headers.
   *
   * @param {Object} table - Table instance
   * @param {string} tableId - Table id
   * @returns {void}
   */
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

    // Add page size selector if configured (internal UI only).
    // Editable tables (data-editable-rows) load all rows for inline editing and
    // save them together, so pagination/per-page makes no sense — skip the
    // selector there (a bound-data table otherwise defaults pageSize to the row
    // count, which would surface a spurious "Show N entries" control).
    if (!isExternalFilter && !config.allowRowModification && config.params.pageSize > 0 && Array.isArray(config.pageSizes) && config.pageSizes.length > 0) {
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
        // Prepare options - when showAll is enabled (default), prepend an "All"
        // option so every dropdown filter can be reset to "no filter".
        // Build an ARRAY (not object): an empty-string "All" value must stay
        // first, but JS orders integer-like object keys ahead of '' — arrays
        // preserve insertion order, objects don't.
        options: (() => {
          const provided = attributes.options || {};
          const arr = Array.isArray(provided)
            ? provided.slice()
            : Object.keys(provided).map(k => ({value: k, text: provided[k]}));
          if (attributes.showAll === false) {
            return arr;
          }
          const allValue = attributes.allValue !== undefined ? attributes.allValue : '';
          const allValueStr = String(allValue);
          // Dedup: skip if the provided options already contain the "All" value
          // (mirrors setFilters() so the two filter paths behave the same).
          if (arr.some(o => String(o && o.value !== undefined ? o.value : o) === allValueStr)) {
            return arr;
          }
          const allLabel = window.Now && window.Now.translate
            ? window.Now.translate(attributes.allLabel || 'All items')
            : (attributes.allLabel || 'All items');
          // Prepend "All" so it is always first and always present.
          return [{value: allValueStr, text: allLabel}, ...arr];
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
        wrapper: 'div',
        wrapperClass: 'search',
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

    // Prevent default form submission (only for actual <form> elements)
    if (form instanceof HTMLFormElement) {
      const submitHandler = (e) => {
        e.preventDefault();
        e.stopPropagation();

        // Collect all form data and trigger reload
        this.handleExternalFilterSubmit(table, tableId);
      };
      form.addEventListener('submit', submitHandler);
      table._externalFilterCleanup.push(() => form.removeEventListener('submit', submitHandler));
    }

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

    const params = {};

    if (form instanceof HTMLFormElement) {
      // Real <form>: use FormData to capture submitted values correctly
      const formData = new FormData(form);
      for (const [key, value] of formData.entries()) {
        params[key] = value;
      }
      // Handle unchecked checkboxes / radios not present in FormData
      form.querySelectorAll('input, select, textarea').forEach(element => {
        const name = element.name || element.id;
        if (!name) return;
        if ((element.type === 'checkbox' || element.type === 'radio') && !formData.has(name)) {
          params[name] = '';
        }
      });
    } else {
      // Non-form container (e.g. <div data-table-filter>): read values directly
      form.querySelectorAll('input, select, textarea').forEach(element => {
        const name = element.name || element.id;
        if (!name) return;
        if (element.type === 'checkbox' || element.type === 'radio') {
          params[name] = element.checked ? (element.value || '1') : '';
        } else {
          params[name] = element.value;
        }
      });
    }

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

      // Store current value to restore after population.
      // An external filter form is authored by hand, so its <select> is empty
      // until this runs — the value that came from the URL was applied to an
      // option list that did not exist yet and was dropped. Fall back to the
      // param the table is actually filtering by, or the filter comes back
      // showing the first option while the list shows something else.
      const currentValue = selectElement.value
        || (table.config?.params?.[fieldName] ?? '');

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

  /**
   * Applies a filter change: stores the value, returns to the first page,
   * clears the selection, updates the URL and reloads.
   *
   * @param {Object} table - Table instance
   * @param {string} tableId - Table id
   * @param {string} filterKey - Parameter name of the filter
   * @param {*} value - New value
   * @param {HTMLElement} element - Control that changed
   * @returns {void}
   */
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

  /**
   * Applies the grid ARIA roles, the table label, the column headers and the
   * live region used to announce row changes.
   *
   * @param {HTMLTableElement} table - Table element
   * @param {string} tableId - Table id
   * @param {Object} config - Table configuration
   * @returns {void}
   */
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

  /**
   * Makes the rows draggable through Sortable, and posts the new order to the
   * action endpoint when a row is dropped.
   *
   * @param {string} tableId - Table id
   * @returns {void}
   */
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

  /**
   * Turns row dragging on: adds the handle column and starts Sortable.
   *
   * @param {string} tableId - Table id
   * @returns {void}
   */
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

  /**
   * Turns row dragging off: removes the handles and stops Sortable.
   *
   * @param {string} tableId - Table id
   * @returns {void}
   */
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

  /**
   * Drops the cached responses for the data source of a table, so the next load
   * goes to the server.
   *
   * @param {string} tableId - Table id
   * @param {string} [sourceOverride=null] - URL to invalidate instead
   * @returns {number} How many cache entries were dropped
   */
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

  /**
   * Loads the data of a table from whichever source it declares: an API, a JSON
   * file, the markup already in the table, or the state manager.
   *
   * @param {string} tableId - Table id
   * @param {Object} [options={}] - Load options
   * @param {boolean} [options.force] - Bypass the cache
   * @returns {Promise<void>}
   */
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

  /**
   * Reports whether a table gets its rows from a URL, which is what decides
   * whether sorting, filtering and paging run here or on the server.
   *
   * @param {Object} table - Table instance
   * @returns {boolean} True for a server-side table
   */
  isServerSideTable(table) {
    if (!table) return false;
    const source = table?.element?.dataset?.source || table?.config?.source || '';
    return this.isUrlSource(source);
  },

  /**
   * Returns the next synthetic row key, used to identify rows of an editable
   * table that the server has not given an id yet.
   *
   * @param {Object} table - Table instance
   * @returns {string} New row key
   */
  nextEditableRowKey(table) {
    if (!table) return '';

    if (!table._rowIdentityCounter) {
      table._rowIdentityCounter = 0;
    }

    table._rowIdentityCounter++;
    return `row_${table._rowIdentityCounter}`;
  },

  /**
   * Gives a row of an editable table a synthetic key when it has none, so it
   * stays identifiable across renders.
   *
   * @param {Object} table - Table instance
   * @param {Object} rowData - Row to key; mutated
   * @returns {string} The row key, empty when the table is not editable
   */
  ensureEditableRowKey(table, rowData) {
    if (!table?.config?.allowRowModification || !rowData || typeof rowData !== 'object') {
      return '';
    }

    if (rowData.__rowKey === undefined || rowData.__rowKey === null || rowData.__rowKey === '') {
      rowData.__rowKey = this.nextEditableRowKey(table);
    }

    return String(rowData.__rowKey);
  },

  /**
   * Returns the identity of a row: its synthetic key in an editable table, its
   * id column otherwise, and its position as a last resort.
   *
   * @param {Object} table - Table instance
   * @param {Object} rowData - Row to identify
   * @param {number} [index=0] - Position, used when the row has no id
   * @returns {string} Row identity
   */
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

  /**
   * Finds the position of a row in the data by its identity.
   *
   * @param {Object} table - Table instance
   * @param {Object} rowData - Row to find
   * @returns {number} Index, or -1 when not found
   */
  findRowIndex(table, rowData) {
    if (!table || !Array.isArray(table.data) || !rowData) {
      return -1;
    }

    const rowIdentity = this.getRowIdentity(table, rowData);
    const index = table.data.findIndex(row => this.getRowIdentity(table, row) === rowIdentity);

    return index !== -1 ? index : table.data.indexOf(rowData);
  },

  /**
   * Builds a blank row for an editable table, with a counter-based id so the
   * element ids of its cells stay predictable.
   *
   * @param {Object} table - Table instance
   * @returns {Object} The new row
   */
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

  /**
   * Reads the current values out of the controls in a rendered row, so edits
   * the user has not committed yet are not lost.
   *
   * @param {Object} table - Table instance
   * @param {string} tableId - Table id
   * @param {Object} item - Row data to update
   * @param {number} [index=0] - Position of the row
   * @returns {Object} The row with the values from the DOM
   */
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

  /**
   * Loads rows from an API endpoint, sending the current filters, sort and page
   * as query parameters.
   *
   * @param {Object} table - Table instance
   * @param {string} tableId - Table id
   * @param {string} source - Endpoint URL
   * @param {Object} [options={}] - Load options
   * @returns {Promise<void>}
   */
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

  /**
   * Loads rows from a static JSON file, which is then filtered, sorted and
   * paged here rather than on the server.
   *
   * @param {string} tableId - Table id
   * @param {string} source - File URL
   * @param {Object} [options={}] - Load options
   * @param {boolean} [options.force] - Bypass the cache
   * @returns {Promise<void>}
   */
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

  /**
   * Parses a cache lifetime, falling back when the value is missing or not a
   * non-negative number.
   *
   * @param {*} value - Configured lifetime in milliseconds
   * @param {number} [fallback=60000] - Value to use instead
   * @returns {number} Lifetime in milliseconds
   */
  normalizeCacheTime(value, fallback = 60000) {
    const parsed = parseInt(value, 10);
    return Number.isFinite(parsed) && parsed >= 0 ? parsed : fallback;
  },

  /**
   * Works out whether a request may be cached and for how long.
   *
   * Only GET is ever cached; an explicit override wins over the table
   * configuration.
   *
   * @param {Object} table - Table instance
   * @param {string} [method='GET'] - HTTP method
   * @param {boolean|Object} [cacheOverride] - Explicit override
   * @returns {Object} {enabled, time, override}
   */
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

  /**
   * Builds the request options for a table request: headers, credentials and
   * the cache settings for the method.
   *
   * @param {Object} table - Table instance
   * @param {string} method - HTTP method
   * @param {Object} [options={}] - Options to extend
   * @returns {Object} Options for the HTTP client
   */
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

  /**
   * Whether a table is still a live, mounted part of the page
   *
   * A response can arrive after its `<table>` was already removed — most often
   * a `data-if` that hid it the moment the page rendered, well before the
   * network round-trip finished (see ApiComponent.isAlive() for the same
   * reasoning, which applies identically here — a table is a separate
   * component system with its own request path, not something ApiComponent's
   * guard covers). A table the user was never even shown has no business
   * hijacking navigation over its own failed, moot request.
   */
  isAlive(tableId) {
    const table = this.state.tables.get(tableId);
    return !!table && !!table.element && document.body.contains(table.element);
  },

  /**
   * Where a 403 response should send the user — see ApiComponent.getForbiddenTarget()
   */
  getForbiddenTarget() {
    return window.RouterManager?.config?.auth?.redirects?.forbidden || '/forbidden';
  },

  /**
   * Requests rows and installs the response into the table, handling the
   * loading state, the errors and the not-authorized redirect.
   *
   * A response arriving after the table was destroyed is discarded.
   *
   * @param {string} tableId - Table id
   * @param {string} url - Endpoint URL
   * @param {Object} [options={}] - Request options
   * @param {string} [options.method='GET'] - HTTP method
   * @param {Object} [options.headers] - Extra headers
   * @param {Object} [options.params] - Query parameters
   * @param {*} [options.body] - Request body
   * @returns {Promise<void>}
   */
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

      // Handle 403 Forbidden - redirect to the forbidden page, but only if this
      // table is still actually part of the page (see isAlive())
      if (response?.status === 403) {
        if (!this.isAlive(tableId)) {
          return;
        }

        console.warn('TableManager: Access forbidden (403) for table data');

        const forbiddenMessage = response?.data?.message || response?.data?.data?.message || '';
        const forbiddenParams = forbiddenMessage ? {message: forbiddenMessage} : {};
        const forbiddenTarget = this.getForbiddenTarget();
        const forbiddenUrl = forbiddenMessage ? `${forbiddenTarget}?message=${encodeURIComponent(forbiddenMessage)}` : forbiddenTarget;

        if (window.RouterManager?.navigate) {
          window.RouterManager.navigate(forbiddenTarget, forbiddenParams);
          return;
        }

        if (window.LocationManager?.redirect) {
          window.LocationManager.redirect(forbiddenUrl);
          return;
        }

        window.location.href = forbiddenUrl;
        return;
      }

      // Handle 401 Unauthorized - redirect to login, same liveness guard as 403
      if (response?.status === 401) {
        if (!this.isAlive(tableId)) {
          return;
        }

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

  /**
   * Reads the rows a table already has in its markup into its data, so a
   * server-rendered table gains sorting, filtering and paging without a
   * request.
   *
   * @param {Object|HTMLTableElement} table - Table instance or element
   * @param {string} tableId - Table id
   * @returns {void}
   */
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

  /**
   * Loads rows from a path in the state manager and follows it, so the table
   * re-renders whenever that state changes.
   *
   * @param {string} tableId - Table id
   * @param {string} stateKey - State path holding the rows
   * @returns {void}
   * @throws {Error} When the state manager is unavailable
   */
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

  /**
   * Installs a set of rows into a table and renders it.
   *
   * Accepts a bare array or a response envelope carrying data, meta and column
   * metadata; dynamic headers are rebuilt when the columns changed.
   *
   * @param {string} tableId - Table id
   * @param {Object[]|Object} data - Rows, or a response envelope
   * @returns {void}
   * @throws {Error} When the table id is missing
   */
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

          // A remembered sort was restored before these headers existed
          this.pruneUnverifiedSort(table);

          // Setup sortable headers with event listeners
          newThead.querySelectorAll('th[data-sort]').forEach(th => {
            th.classList.add('sortable');
            th.setAttribute('role', 'columnheader');
            th.setAttribute('tabindex', '0');
            th.setAttribute('aria-sort', 'none');

            // Bind sort event listeners
            const sortHandler = (e) => {
              // Ignore the click that ends a column-resize drag.
              if (e.currentTarget.closest('table')?.dataset.colResizing || e.target.classList.contains('col-resizer')) {
                return;
              }
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

          // Derive searchColumns from API metadata BEFORE setupFilter so the
          // built-in search box renders on this same pass (API-driven search).
          // Stored as an array to match data-search-columns parsing and the
          // consumers at searchFilter()/placeholder rendering.
          if (!table.config.searchColumns || table.config.searchColumns.length === 0) {
            const searchableFields = columnsMetadata
              .filter(col => col.searchable === true)
              .map(col => col.field);
            if (searchableFields.length > 0) {
              table.config.searchColumns = searchableFields;
            }
          }

          // Setup filters for new headers
          this.setupFilter(table, tableId);

          // Setup column resizing for dynamic headers
          this.setupColumnResizing(tableId);

          // Setup accessibility after all components are created
          this.setupAccessibility(table.element, tableId, table.config);
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
        ...metaRest
      } = normalized.meta || {};

      /*
       * Only scalars come back out of meta as request parameters.
       *
       * Every key of meta used to be spread straight into config.params, and
       * getFilterParams() then put all of them in the query string. An endpoint
       * whose meta carried its own payload — a findings array, a nested options
       * object — therefore wrote that payload into the URL, one
       * `key=[object Object]` per element, on every single request. The URL grew
       * without bound and the table's search, filters and paging all stopped
       * working, because the parameters they set were buried in thousands of
       * characters of stringified objects (reported on a findings page carrying
       * ~200 rows).
       *
       * meta is what the server says *about* the response. A value the client can
       * legitimately send back is a string, a number or a boolean; an array or an
       * object is data that leaked into the wrong half of the envelope, and
       * forwarding it can only ever corrupt the next request.
       */
      const metaParams = {};

      Object.keys(metaRest).forEach(key => {
        const value = metaRest[key];

        if (value === null || value === undefined) {
          return;
        }

        if (typeof value !== 'object') {
          metaParams[key] = value;
        }
      });

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
      table.lastApiResponse = normalized;
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

  /**
   * Reports whether the headers already in the DOM match the column metadata of
   * a response, so unchanged headers can be left in place.
   *
   * @param {HTMLTableSectionElement} thead - Current header
   * @param {Object[]} columnsMetadata - Columns from the response
   * @returns {boolean} True when they match
   */
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

  /**
   * Removes the sort listeners from the headers, optionally only those inside a
   * subtree that is about to be replaced.
   *
   * @param {Object} table - Table instance
   * @param {HTMLElement} [root=null] - Limit to headers inside this element
   * @returns {void}
   */
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

  /**
   * Sets the options offered by the select filters of a table.
   *
   * @param {string} tableId - Table id
   * @param {Object} filters - Options keyed by filter name
   * @returns {void}
   */
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

  /**
   * Builds the options of the select filters from the distinct values in the
   * data, for the filterable columns that were given none.
   *
   * @param {Object} table - Table instance
   * @returns {Object} Options keyed by filter name
   */
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

  /**
   * Renders a table: filters, sorts and pages the data for a client-side table,
   * writes the rows, then updates the footer, caption and pagination.
   *
   * The render is deferred while ElementManager is still starting up.
   *
   * @param {string} tableId - Table id
   * @returns {void}
   */
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
            ...(table.lastApiResponse || {}),
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

  /**
   * Puts the sort classes and aria-sort of the headers back in step with the
   * sort state, after the header was re-rendered.
   *
   * @param {Object} table - Table instance
   * @returns {void}
   */
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

  /**
   * Builds one table row: its identity, its checkbox, its cells and its action
   * cell.
   *
   * @param {Object} table - Table instance
   * @param {string} tableId - Table id
   * @param {Object} item - Row data
   * @param {number} index - Position on the current page
   * @returns {HTMLTableRowElement} The row
   */
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

  /**
   * Returns the running number of a row across pages, so an auto-number column
   * continues counting on page two.
   *
   * @param {Object} table - Table instance
   * @param {number} index - Position on the current page
   * @returns {number} Row number, starting at 1
   */
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

  /**
   * Duplicates a row: captures the values currently in its controls, inserts
   * the copy after it, and posts it to the action endpoint on a server-side
   * table.
   *
   * @param {Object} table - Table instance
   * @param {string} tableId - Table id
   * @param {Object} item - Row to copy
   * @returns {Promise<void>}
   */
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

  /**
   * Asks the user to confirm, then deletes the row.
   *
   * The confirmation is skipped when the table sets data-confirm-delete to
   * false.
   *
   * @param {Object} table - Table instance
   * @param {string} tableId - Table id
   * @param {Object} item - Row to delete
   * @returns {Promise<void>}
   */
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

  /**
   * Deletes a row: tells the action endpoint on a server-side table, and
   * removes it from the data and the DOM.
   *
   * @param {Object} table - Table instance
   * @param {string} tableId - Table id
   * @param {Object} item - Row to delete
   * @returns {Promise<void>}
   */
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

  /**
   * Reads the column definitions from the header cells: field, label, sort,
   * filter, format, cell element and the merge information of grouped headers.
   *
   * @param {Object} table - Table instance
   * @returns {Map} Definitions keyed by field, empty when there is no header
   */
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
            checkedValue: '',
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
      if (col.checkedValue !== undefined) th.dataset.checkedValue = col.checkedValue;
      if (col.optionsKey !== undefined) th.dataset.optionsKey = col.optionsKey;
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

  /**
   * Builds the footer of a table: its checkbox cell and the aggregate cells,
   * lined up with the columns above.
   *
   * @param {Object} table - Table instance
   * @returns {void}
   */
  setupFooter(table) {
    const tfoot = this.ensureFooterStructure(table);
    if (!tfoot) return;

    // The checkbox cell of the footer belongs to setupCheckboxes, which builds
    // it for the header and the footer in one place. Adding one here as well
    // gave the footer a column the header did not have.

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

  /**
   * Computes an aggregate over a column, returning 0 when the column holds
   * nothing that can be aggregated.
   *
   * @param {Object[]} data - Rows to aggregate
   * @param {string} field - Column to aggregate
   * @param {string} type - 'sum', 'avg', 'count', 'min' or 'max'
   * @returns {number} The aggregate
   */
  calculateAggregate(data, field, type) {
    const aggregateResult = this.getAggregateResult(data, field, type);
    return aggregateResult.processable ? aggregateResult.value : 0;
  },

  /**
   * Computes an aggregate over the sum of several columns per row, used by a
   * grouped header that totals its own columns.
   *
   * @param {Object[]} data - Rows to aggregate
   * @param {string[]} fields - Columns summed within each row
   * @param {string} type - 'sum', 'avg', 'count', 'min' or 'max'
   * @returns {number} The aggregate
   */
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

  /**
   * Reads the grouped header structure, mapping each group to the columns its
   * colspan covers.
   *
   * @param {Object} table - Table instance
   * @param {HTMLTableRowElement} [referenceRow=null] - Row to read instead of the header
   * @returns {Object[]} Groups with their columns
   */
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
          cellClass: cell.dataset.cellClass || null
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
        cellClass: entry.cell.dataset.cellClass || null
      };

      for (let offset = 0; offset < entry.colspan; offset++) {
        groups[entry.columnIndex + offset] = {...column};
      }
    });

    return groups.filter(column => column !== undefined);
  },

  /**
   * Writes the caption of a table: the range shown, the total, and the search
   * term when one is active.
   *
   * @param {Object} table - Table instance
   * @param {number} totalRecords - Total number of rows
   * @param {number} totalPages - Total number of pages
   * @returns {void}
   */
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

    // The caption is HTML (the term is wrapped in <strong>), and the search term
    // is whatever the visitor typed — it went in raw, so `<img onerror>` in the
    // search box ran. Escape it here; the numbers need nothing. Now.translate()
    // fills every {param} itself, and a second interpolate pass over the result
    // would substitute a {count} the visitor typed, so there is none.
    const params = {
      count: totalRecords,
      start: (page - 1) * pageSize + 1,
      end: Math.min(page * pageSize, totalRecords),
      page: page,
      total: totalPages,
      search: this.escapeCellValue(search)
    };

    caption.innerHTML = Now.getManager?.('i18n')
      ? Now.translate(searchText, params)
      : searchText.replace(/\{(\w+)\}/g, (m, k) => (params[k] !== undefined ? params[k] : m));
  },

  /**
   * Filters rows for a client-side table: the search term against the
   * searchable columns, and each filter parameter against its own column.
   *
   * @param {Object} table - Table instance
   * @param {Object[]} data - Rows to filter
   * @returns {Object[]} Rows that matched
   */
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

  /**
   * Sorts a copy of the rows by the sort state, applying each column in the
   * order it was added.
   *
   * @param {Object} table - Table instance
   * @param {Object[]} data - Rows to sort
   * @returns {Object[]} Sorted copy
   */
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

  /**
   * Cuts the rows down to the current page.
   *
   * A page size of zero or less means no paging, and every row is returned.
   *
   * @param {Object} table - Table instance
   * @param {Object[]} data - Rows to page
   * @returns {Object} {pageData, totalPages, totalRecords}
   */
  paginateData(table, data) {
    if (!table || table.config.params.pageSize <= 0) {
      return {pageData: data, totalPages: 1, totalRecords: data.length};
    }

    const {pageSize} = table.config.params;

    // If server provided a total (via meta), use it; otherwise derive from data length
    const totalRecords = parseInt(table.config.params.total || data.length || 0);
    const totalPages = pageSize > 0 ? Math.max(1, Math.ceil(totalRecords / pageSize)) : 1;

    let pageData;
    if (table.serverSide) {
      // Assume server returned the page's rows already
      pageData = data;
    } else {
      // A remembered or linked page past the end (fewer rows now) shows the last page
      if (table.config.params.page > totalPages) {
        table.config.params.page = totalPages;
      }
      const start = (table.config.params.page - 1) * pageSize;
      pageData = data.slice(start, start + pageSize);
    }

    return {pageData, totalPages, totalRecords};
  },

  /**
   * Rebuilds the pagination controls: a window of page numbers centred on the
   * current page, plus the first, previous, next and last links.
   *
   * Nothing is rendered when everything fits on one page.
   *
   * @param {Object} table - Table instance
   * @param {string} tableId - Table id
   * @param {number} totalRecords - Total number of rows
   * @param {number} totalPages - Total number of pages
   * @returns {void}
   */
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

  /**
   * Adds one button to the pagination bar, rendering the current page as a
   * marked, non-interactive item.
   *
   * @param {Object} table - Table instance
   * @param {string} tableId - Table id
   * @param {number} page - Page the button leads to
   * @param {string} text - Button label
   * @param {number} currentPage - Page currently shown
   * @returns {void}
   */
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

  // Entity-encode an untrusted scalar cell value before inlining it into a
  // template HTML string. Uses the central SecurityManager when available.
  /**
   * Escapes a cell value for insertion as HTML, through SecurityManager where
   * it is available and with its own encoding otherwise.
   *
   * @param {*} value - Value to escape
   * @returns {string} Escaped text
   */
  escapeCellValue(value) {
    const sm = window.SecurityManager;
    if (sm && typeof sm.escapeHtml === 'function') return sm.escapeHtml(value);
    const s = value == null ? '' : String(value);
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  },

  // Sanitize HTML that a column explicitly opted into (a {html} cell value)
  // before it reaches innerHTML. Falls back to full escaping if no sanitizer.
  /**
   * Cleans markup meant to be rendered inside a cell.
   *
   * Falls back to escaping the markup entirely when SecurityManager is absent,
   * so unsanitized HTML never reaches the DOM.
   *
   * @param {string} html - Markup to clean
   * @returns {string} Cleaned markup, or escaped text
   */
  /**
   * Marks a cell whose content is a plain row value (not a template) so that
   * I18nManager's DOM observer leaves it alone: a stored value such as
   * `{LNG_Documents}` must show exactly as stored, not translated. Uses the
   * standard HTML `translate="no"` attribute, which I18nManager honours on an
   * element and all of its descendants. Template cells are marked once their
   * own {LNG_...} tokens have been resolved. Formatter cells are not marked —
   * a formatter owns its cell: it calls Now.translate() or renders `data-i18n`
   * for labels, and sets translate="no" itself around user-supplied text.
   *
   * @param {HTMLTableCellElement} cell
   */
  markDataCell(cell) {
    cell.setAttribute('translate', 'no');
  },

  sanitizeCellHtml(html) {
    const sm = window.SecurityManager;
    if (sm && typeof sm.sanitizeHtml === 'function') return sm.sanitizeHtml(html);
    return this.escapeCellValue(html);
  },

  /**
   * Builds one cell: resolves its value, applies the column format, and renders
   * it as text, as markup, as a link or as a form element according to the
   * column definition.
   *
   * @param {Object} table - Table instance
   * @param {string} tableId - Table id
   * @param {HTMLTableRowElement} row - Row being built
   * @param {string} field - Column
   * @param {Object} attributes - Column definition
   * @param {Object} rowData - Row data
   * @param {number} index - Position on the current page
   * @returns {HTMLTableCellElement} The cell
   */
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

    // An `{html}` value is server-composed markup. With `i18n: true` the server
    // is saying "this carries {LNG_...} markers, resolve them here" — so the
    // catalog is applied on every render (the table re-renders from the raw
    // value on locale change, so the cell follows the UI language without a
    // request, unlike markup translated once by PHP). Only the marker is
    // touched; any other brace in the markup stays. Without the flag the
    // markup is inserted as it came.
    const renderHtmlValue = (v) => this.sanitizeCellHtml(v.i18n === true ? this.translateValue(v.html) : v.html);

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

      if (isDisplayOnly) {
        this.markDataCell(cell);
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
        this.markDataCell(cell);
        cell.textContent = this.formatValue(primValue, attributes.format, formatOptions);
      } else if (attributes.template) {
        // Template-based rendering
        // 1) Replace ${key} placeholders with opaque slot tokens.
        //
        // The template markup is developer-authored (trusted); the row values are
        // untrusted server data. They used to be pasted straight into the template
        // string, which then went through i18n.interpolate and TemplateManager —
        // so a stored value of `{{7*7}}` rendered as 49 and `{LNG_Delete}` came
        // out translated (template injection through table data). The values are
        // now put back only into the *final* HTML, after every template pass, so
        // neither engine ever sees them. Scalars are escaped; explicit {html}
        // opt-in values are sanitized. The token contains no brace, quote or
        // angle bracket, so it survives every pass and every attribute context.
        // A `${...}` that is not a plain field name (an expression such as
        // `${id != 1}` meant for data-if) is kept verbatim behind a token too, or
        // i18n.interpolate would strip its braces on the way through.
        //
        // `{LNG_${key}}` is the author asking for the row's value to be looked up
        // as a catalog key (a status such as `active` shown as its translation).
        // Slotting `${key}` alone would hand i18n the key `%%NOWCELL0%%`, so that
        // pattern is resolved here first — the value is only ever a lookup key,
        // never parsed, and the result is escaped like any other value.
        const slots = [];
        const lookupI18n = window.Now && Now.getManager ? Now.getManager('i18n') : null;
        const translateKey = (key) => {
          if (lookupI18n && typeof lookupI18n.getTranslations === 'function') {
            // own entries only: a row value of `constructor` is not a catalog key
            const map = lookupI18n.getTranslations();
            return Object.prototype.hasOwnProperty.call(map, key) ? map[key] ?? key : key;
          }
          return window.Now && typeof Now.translate === 'function' ? Now.translate(key) : key;
        };
        let template = attributes.template.replace(/\{LNG_\$\{(\w+)\}\}/g, (match, key) => {
          const v = rowData[key];
          if (v === undefined || (v !== null && typeof v === 'object' && v.html !== undefined)) {
            return match;
          }
          const text = String(normalizeValue(v) ?? '');
          slots.push(this.escapeCellValue(text === '' ? '' : translateKey(text)));
          return '%%NOWCELL' + (slots.length - 1) + '%%';
        });
        template = template.replace(/\$\{([^}]*)\}/g, (match, key) => {
          const v = /^\w+$/.test(key) ? rowData[key] : undefined;
          if (v === undefined) {
            slots.push(match);
          } else {
            slots.push(v && typeof v === 'object' && v.html !== undefined
              ? renderHtmlValue(v)
              : this.escapeCellValue(normalizeValue(v)));
          }
          return '%%NOWCELL' + (slots.length - 1) + '%%';
        });
        const fillSlots = (html) => html.replace(/%%NOWCELL(\d+)%%/g, (m, i) => slots[Number(i)] ?? '');

        // 2) Run i18n interpolation on the resulting HTML string so tokens like
        //    {LNG_*} (including patterns formed like {LNG_${status_text}}) are resolved
        try {
          const i18n = window.Now && Now.getManager ? Now.getManager('i18n') : null;
          if (i18n && typeof i18n.interpolate === 'function') {
            template = i18n.interpolate(template, {});
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

        // 2.4) data-if inside a cell is decided against the row in step 3.5. Keep
        //      it away from TemplateManager: it would evaluate the expression
        //      against the slot tokens (never the row) and, since it removes a
        //      false non-animated element synchronously, drop it on every row.
        //      The template here is author markup plus opaque tokens only.
        template = template.replace(/(\s)data-if=/g, '$1data-cell-if=');

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

        // 3) Put the row values back (already escaped) and insert the HTML
        cell.innerHTML = fillSlots(template);

        // 3.5) data-if inside a cell (renamed data-cell-if in step 2.4 so
        //      TemplateManager never decides it against the wrong data). Decide
        //      it here, synchronously, against the row. `${field}` values have
        //      been substituted by now, so both `data-if="${id != 1}"` and
        //      `data-if="can_edit"` work.
        cell.querySelectorAll('[data-cell-if]').forEach((el) => {
          // A `${flag}` that held null/'' has become an empty expression by now:
          // that is a falsy row value, not "no condition" — hide.
          const expression = (el.getAttribute('data-cell-if') || '').trim();
          el.removeAttribute('data-cell-if');
          if (expression === '' || !this.evaluateTableCondition(expression, rowData)) {
            el.remove();
          }
        });

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
            // data-i18n opts the whole element in, not just its text: a tooltip
            // such as title='${topic}' next to ${topic} must show the same words.
            // Same attribute set as I18nManager.translateAttributesIn(). Elements
            // without data-i18n keep row data verbatim in their attributes too.
            if (typeof Now !== 'undefined' && typeof Now.translate === 'function') {
              ['placeholder', 'title', 'alt', 'aria-label', 'aria-placeholder', 'label'].forEach(name => {
                const value = el.getAttribute(name);
                if (value) el.setAttribute(name, Now.translate(value));
              });
            }
          });
        } catch (e) {
          // ignore translation errors for cell post-processing
        }

        // 5) Everything the template authored is translated by now (step 2 and
        //    step 4). What remains inside the cell is row data, which the i18n
        //    DOM observer must not touch — the table re-renders on locale change.
        this.markDataCell(cell);
      } else {
        // Default rendering - prefer HTML when original value had an html property.
        // Sanitize the opt-in HTML before it reaches innerHTML.
        if (rawValue && typeof rawValue === 'object' && rawValue.html !== undefined) {
          // Translated here means the observer has nothing left to do; mark the
          // cell so it re-renders on locale change instead of being re-scanned.
          if (rawValue.i18n === true) this.markDataCell(cell);
          cell.innerHTML = renderHtmlValue(rawValue);
        } else {
          this.markDataCell(cell);
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

  /**
   * Translates a cell value, using the i18n interpolation when it is available
   * so embedded placeholders are resolved too.
   *
   * @param {string} value - Value to translate
   * @returns {string} Translated value
   */
  translateValue(value) {
    if (typeof value !== 'string' || !value.includes('{')) return value;
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

  /**
   * Retranslates the filter controls of a table after the locale changed, both
   * the internal ones and an external filter form.
   *
   * @param {Object} table - Table instance
   * @returns {void}
   */
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

  /**
   * Renders a cell as a form element through ElementManager, so the column can
   * be edited in place.
   *
   * Falls back to plain text when ElementManager is unavailable.
   *
   * @param {HTMLTableCellElement} cell - Cell to fill
   * @param {Object} table - Table instance
   * @param {string} tableId - Table id
   * @param {string} field - Column
   * @param {Object} attributes - Column definition
   * @param {Object} rowData - Row data
   * @param {number} index - Position on the current page
   * @returns {void}
   */
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

  /**
   * Builds the ElementManager configuration for an editable cell: its type, id,
   * value, options and change handler.
   *
   * data-type is accepted as a shorthand for data-cell-element.
   *
   * @param {Object} table - Table instance
   * @param {string} tableId - Table id
   * @param {string} field - Column
   * @param {Object} attributes - Column definition
   * @param {Object} rowData - Row data
   * @param {number} index - Position on the current page
   * @returns {Object|null} Element configuration, or null when the column is not editable
   */
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
      case 'switch':
        // Checkbox/radio/switch. The value arrives as it was stored, so a flag
        // saved as the number 1 has to tick the box just like the string '1'
        // does — the same truthiness rule the element factories apply.
        config.checked = !['', '0', 'false', 'null', 'undefined'].includes(String(value).toLowerCase());
        if (attributes.options) {
          config.options = attributes.options;
        }
        // `size` describes a text box, not a box that is ticked.
        delete config.size;
        if (attributes.cellElement === 'switch') {
          // The toggle carries no visible text in a cell, so the column header
          // is what a screen reader announces for it.
          config.ariaLabel = attributes.ariaLabel || attributes.label;
        }
        if (attributes.cellElement !== 'radio') {
          // What gets submitted when the box is ticked. Keeping the stored
          // value here would post the off value ('0') of a row the user just
          // switched on, so the on value is what the input carries.
          config.value = attributes.checkedValue !== undefined && attributes.checkedValue !== null && attributes.checkedValue !== ''
            ? attributes.checkedValue
            : '1';
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

  /**
   * Releases everything a table holds: its refresh timer first, then its
   * listeners, its enhanced cells, its filter controls and its state binding.
   *
   * The timer goes first because one outliving its table would keep requesting
   * data for a screen the user has already left.
   *
   * @param {string} tableId - Table id
   * @returns {void}
   */
  cleanupTableResources(tableId) {
    try {
      const table = this.state.tables.get(tableId);
      if (!table) return;

      // Stop polling first — a timer that outlives its table would keep firing
      // requests for a screen the user has already navigated away from
      this.stopAutoRefresh(tableId);

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
  /**
   * Handles every click inside a table: pagination, row actions, the select-all
   * and row checkboxes, and the bulk-action submit.
   *
   * Elements carrying an EventSystemManager data-action are left alone, so the
   * declarative binding stays in charge of them.
   *
   * @param {MouseEvent} e - Click event
   * @param {string} tableId - Table id
   * @returns {void}
   */
  _handleDelegatedClick(e, tableId) {
    const table = this.state.tables.get(tableId);
    if (!table) return;

    const isDeclarativeEventAction = (action) => {
      if (!action || typeof action !== 'string') return false;
      // EventSystemManager format: event(.modifier)*:actionName
      return /^[a-z]+(?:\.[a-z]+)*:/i.test(action.trim());
    };

    const btn = e.target.closest('[data-action]');
    if (btn && table.element.contains(btn)) {
      const action = btn.dataset.action;
      if (isDeclarativeEventAction(action)) {
        // Let EventSystemManager handle declarative actions (e.g. click.prevent:requestApi)
        return;
      }
      e.preventDefault();
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
        if (isDeclarativeEventAction(action)) {
          // Let EventSystemManager handle declarative actions (e.g. click.prevent:requestApi)
          return;
        }
        e.preventDefault();
        const tr = iconBtn.closest('tr');
        const id = tr?.dataset?.id;
        const row = id ? this.getRowObjectById(tableId, id) : null;
        const actionUrl = table.element.dataset.actionUrl || table.config.actionUrl;
        this._executeRowAction(tableId, actionUrl, row || {}, action, {});
      }
    }
  },

  /**
   * Handles every change inside a table: the selection checkboxes, the filter
   * controls and the editable cells.
   *
   * @param {Event} e - Change event
   * @param {string} tableId - Table id
   * @returns {void}
   */
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
  /**
   * Returns the ids of the selected rows.
   *
   * @param {Object} table - Table instance
   * @returns {string[]} Selected row ids
   */
  getSelectedRowIds(table) {
    if (!table || !table.element) return [];
    const checked = table.element.querySelectorAll('tbody .select-row:checked');
    return Array.from(checked).map(cb => cb.value);
  },

  /**
   * Applies the chosen bulk action to the selected rows by posting them to the
   * action endpoint.
   *
   * @param {string} tableId - Table id
   * @returns {void}
   */
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

  /**
   * Where a table's column widths are stored: the route and the table id.
   *
   * @param {Object} table - Table instance
   * @returns {string}
   */
  getColumnWidthsKey(table) {
    return `now.table.columns:${this.getRoutePath()}:${table.id}`;
  },

  /**
   * Lets the user resize the columns by dragging the header borders (mouse,
   * touch or pen), or with the arrow keys on a focused border, and remembers
   * the widths per column field for the next visit. Double-clicking a border
   * returns every column to its automatic width.
   *
   * On by default; data-persist-column-widths="false" turns it off.
   *
   * @param {string} tableId - Table id
   * @returns {void}
   */
  setupColumnResizing(tableId) {
    const tableObj = this.state.tables.get(tableId);
    if (!tableObj || !tableObj.element) return;
    if (!tableObj.config.persistColumnWidths) return;

    const tableEl = tableObj.element;
    const thead = tableEl.querySelector('thead');
    if (!thead) return;

    const headerRow = thead.querySelector('tr:last-child') || thead.querySelector('tr');
    if (!headerRow) return;

    const headers = Array.from(headerRow.querySelectorAll('th'));
    if (headers.length <= 1) return;

    const storageKey = this.getColumnWidthsKey(tableObj);
    // Widths follow the column, not its position: hiding or moving a column
    // must not hand its width to a neighbour
    const columnKey = (th, idx) => th.dataset.field || th.dataset.sort || `#${idx}`;
    const isWidth = (value) => typeof value === 'string' && /^\d+(\.\d+)?px$/.test(value);

    let saved = this.readStorage('local', storageKey);
    // Older releases kept an array in table order under table_<id>_columns:
    // converted once when nothing newer is stored, dropped either way
    const legacyKey = `table_${tableId}_columns`;
    const legacy = this.readStorage('local', legacyKey);
    if (legacy !== null) {
      if (!saved && Array.isArray(legacy) && legacy.length === headers.length) {
        saved = {};
        headers.forEach((th, idx) => {
          if (isWidth(legacy[idx])) saved[columnKey(th, idx)] = legacy[idx];
        });
        this.writeStorage('local', storageKey, saved);
      }
      this.writeStorage('local', legacyKey, null);
    }
    if (saved && typeof saved === 'object') {
      let restored = 0;
      headers.forEach((th, idx) => {
        const width = saved[columnKey(th, idx)];
        if (isWidth(width)) {
          th.style.width = width;
          restored++;
        }
      });
      // Every width known = the fixed layout the user left
      if (restored === headers.length) {
        tableEl.style.tableLayout = 'fixed';
      }
    }

    const freezeColumns = () => {
      headers.forEach(c => {
        if (!c.style.width) c.style.width = `${c.offsetWidth}px`;
      });
      tableEl.style.tableLayout = 'fixed';
    };

    const saveWidths = () => {
      const widths = {};
      headers.forEach((c, idx) => {
        if (c.style.width) widths[columnKey(c, idx)] = c.style.width;
      });
      this.writeStorage('local', storageKey, Object.keys(widths).length > 0 ? widths : null);
    };

    const resetWidths = () => {
      headers.forEach(c => {
        c.style.width = '';
      });
      tableEl.style.tableLayout = '';
      this.writeStorage('local', storageKey, null);
      headers.forEach(c => {
        const handle = c.querySelector(':scope > .col-resizer');
        if (handle) handle.setAttribute('aria-valuenow', String(c.offsetWidth));
      });
    };

    const setWidth = (th, width, handle) => {
      const px = Math.max(30, Math.round(width));
      th.style.width = `${px}px`;
      if (handle) handle.setAttribute('aria-valuenow', String(px));
    };

    // Attach resizer handles. Remove any existing ones first so re-calling is safe.
    headers.forEach((th, idx) => {
      th.querySelectorAll('.col-resizer').forEach(el => el.remove());

      // Handlers live on the TH (not on the handle) so they survive innerHTML
      // rewrites of the header by translation/template layers
      ['click', 'pointerdown', 'dblclick', 'keydown'].forEach(type => {
        const handler = th[`_colResize_${type}`];
        if (handler) th.removeEventListener(type, handler);
      });
      if (th._colResizeClickHandler) th.removeEventListener('click', th._colResizeClickHandler);
      if (th._colResizeMouseDownHandler) th.removeEventListener('mousedown', th._colResizeMouseDownHandler);

      // Skip last column: there is no boundary to drag on the right side.
      if (idx === headers.length - 1) return;

      // Guarantee the th is a positioned container for the absolute resizer.
      th.style.position = 'relative';

      const label = (th.textContent || '').trim() || columnKey(th, idx);
      const resizer = document.createElement('div');
      resizer.className = 'col-resizer';
      resizer.setAttribute('role', 'separator');
      resizer.setAttribute('aria-orientation', 'vertical');
      resizer.setAttribute('aria-label', `${Now.translate('Resize column')}: ${label}`);
      resizer.setAttribute('aria-valuenow', String(th.offsetWidth || 0));
      resizer.tabIndex = 0;
      th.appendChild(resizer);

      const isHandle = (e) => e.target?.classList?.contains('col-resizer');

      // The click that ends a drag must not sort the column
      const onClick = (e) => {
        if (isHandle(e)) e.stopPropagation();
      };

      const onPointerDown = (e) => {
        if (!isHandle(e) || (e.button !== undefined && e.button !== 0)) return;
        e.preventDefault();
        e.stopPropagation();
        freezeColumns();

        const handle = e.target;
        const startX = e.clientX;
        const startWidth = th.offsetWidth;

        // Flag on table element for all sort handlers.
        tableEl.dataset.colResizing = '1';
        th.dataset.resizing = '';
        document.body.style.cursor = 'col-resize';

        const onMove = (ev) => {
          if (ev.pointerId !== e.pointerId) return;
          setWidth(th, startWidth + (ev.clientX - startX), handle);
        };

        const onUp = (ev) => {
          if (ev.pointerId !== e.pointerId) return;
          document.removeEventListener('pointermove', onMove);
          document.removeEventListener('pointerup', onUp);
          document.removeEventListener('pointercancel', onUp);
          document.body.style.cursor = '';
          delete th.dataset.resizing;

          // Keep flag set until AFTER the synthetic post-pointerup click fires.
          setTimeout(() => {delete tableEl.dataset.colResizing;}, 0);

          saveWidths();
        };

        document.addEventListener('pointermove', onMove);
        document.addEventListener('pointerup', onUp);
        document.addEventListener('pointercancel', onUp);
      };

      const onDblClick = (e) => {
        if (!isHandle(e)) return;
        e.preventDefault();
        e.stopPropagation();
        resetWidths();
      };

      const onKeyDown = (e) => {
        if (!isHandle(e) || (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight')) return;
        e.preventDefault();
        e.stopPropagation();
        freezeColumns();
        const step = (e.shiftKey ? 50 : 10) * (e.key === 'ArrowRight' ? 1 : -1);
        setWidth(th, th.offsetWidth + step, e.target);
        saveWidths();
      };

      th.addEventListener('click', onClick);
      th.addEventListener('pointerdown', onPointerDown);
      th.addEventListener('dblclick', onDblClick);
      th.addEventListener('keydown', onKeyDown);
      th._colResize_click = onClick;
      th._colResize_pointerdown = onPointerDown;
      th._colResize_dblclick = onDblClick;
      th._colResize_keydown = onKeyDown;
    });
  },

  /**
   * Finds the row data behind a row id.
   *
   * @param {string} tableId - Table id
   * @param {string|number} id - Row identity
   * @returns {Object|null} The row, or null when not found
   */
  getRowObjectById(tableId, id) {
    const table = this.state.tables.get(tableId);
    if (!table || !table.data) return null;
    return table.data.find((row, index) => this.getRowIdentity(table, row, index) === String(id)) || null;
  },

  /**
   * Drops what a table persisted: its column widths and its remembered
   * page size, sort, filters, page and search.
   *
   * @param {string} tableId - Table id
   * @returns {void}
   */
  clearTableCache(tableId) {
    try {
      const table = this.state.tables.get(tableId);
      if (!table) return;

      this.writeStorage('local', this.getColumnWidthsKey(table), null);
      this.forgetRememberedState(tableId);

      // Keys of older releases
      ['columns', 'filters', 'sort', 'page'].forEach(name => {
        this.writeStorage('local', `table_${tableId}_${name}`, null);
      });
    } catch (error) {
      this.handleError('Clear table cache', error, 'clearCache');
    }
  },

  /**
   * Destroys one table, releases its resources and emits table:destroyed.
   *
   * @param {string} tableId - Table id
   * @returns {void}
   */
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

  /**
   * Destroys every registered table and clears the registry.
   *
   * @returns {void}
   */
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

  /**
   * Binds a table to a path in the state manager, so it re-renders whenever
   * that state changes.
   *
   * @param {string} tableId - Table id
   * @param {string} statePath - State path holding the rows
   * @returns {void|*} The result of handleError() on bad input
   */
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

  /**
   * Watches the document for tables added later and initializes them, the
   * fallback used when CoreObserver is not present.
   *
   * @returns {void}
   */
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

  /**
   * Reports a table error to ErrorManager, tagged with the method it came from.
   *
   * @param {string} message - Description of what failed
   * @param {Error|null} error - Error that was caught, if there was one
   * @param {string} type - Method name, used to build the context
   * @returns {*} Result of ErrorManager.handle
   */
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

  /**
   * Adds the touch gestures of a table: a long press to select a row, and a
   * horizontal swipe to page.
   *
   * Does nothing on a device without touch.
   *
   * @param {HTMLTableElement} table - Table element
   * @returns {void}
   */
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

  /**
   * Builds the bulk-action area of a table: the action select, the submit
   * button and the selected-row counter.
   *
   * @param {Object} table - Table instance
   * @param {string} tableId - Table id
   * @returns {void}
   */
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
            // แกะให้ถึงชั้นที่มี actions จริง — ชั้นที่ลึกไปหนึ่งขั้นทำให้ modal/redirect ที่ API สั่งมาหายเงียบ ๆ
            const responseData = window.ResponseHandler
              ? ResponseHandler.payloadOf(resp)
              : (resp?.data?.data ?? resp?.data ?? resp);
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

  /**
   * Collects the action list out of one or more response shapes, so a response
   * that nests its payload is handled the same as a flat one.
   *
   * @param {...Object} payloads - Response bodies to search
   * @returns {Object[]} The actions found
   */
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

  /**
   * Shows the message of a response as a notification, but only when the
   * response did not already carry a notification or alert action of its own,
   * so the user never sees the same message twice.
   *
   * @param {Object} responseData - Response body
   * @param {Object} [rawResponse=null] - Full response, searched as well
   * @param {boolean} [success=true] - Show it as a success rather than an error
   * @returns {void}
   */
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

  /**
   * Tells the user that the button they just pressed is currently active. and prevent pressing it again during that time
   *
   * **Why do I need to prevent repeated presses? It doesn't just show the status:** Button that is pressed and nothing happens.
   * Make people click again naturally · With commands like "Restart Service" pressing five times
   * This is five consecutive restarts. Which is more dangerous than not having status.
   *
   * Use `disabled` for actual buttons (browser prevents clicking them for you) Accessible with keyboard)
   * and `aria-busy` to be recognized by screen readers. It's not just people who see spinners.
   *
   * @param {HTMLElement|null} el
   * @param {boolean} busy
   */
  setActionBusy(el, busy) {
    if (!el || !el.classList) return;

    el.classList.toggle('loading', !!busy);
    el.setAttribute?.('aria-busy', busy ? 'true' : 'false');

    if ('disabled' in el) {
      el.disabled = !!busy;
    } else if (busy) {
      el.setAttribute?.('aria-disabled', 'true');
    } else {
      el.removeAttribute?.('aria-disabled');
    }
  },

  /**
   * Posts a table action to its endpoint and applies the response: the actions
   * it carries, its message, and a reload of the table when it asks for one.
   *
   * The submit control is held busy for the duration, so a double click cannot
   * send the action twice.
   *
   * @param {string} actionUrl - Endpoint URL
   * @param {Object} data - Action payload, sent with the table id added
   * @param {string} tableId - Table id
   * @param {HTMLElement} submitEl - Control that triggered the action
   * @param {Object} [context={}] - Extra context for the response handling
   * @returns {Promise<Object|undefined>} Response body, or undefined on failure
   */
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
      this.setActionBusy(submitButton, true);

      const requestData = {
        ...data,
        tableId
      };

      // Use HttpClient (window.http) with CSRF protection, fallback to simpleFetch
      //
      // The verb comes from the action config so a row action can call a REST
      // resource directly (`DELETE /api/users/12`). Anything unknown — or no
      // method at all — stays on POST, which is what every existing table uses.
      const verb = String(context.method || 'post').toLowerCase();
      const send = typeof window.http[verb] === 'function' ? window.http[verb] : window.http.post;

      // GET/DELETE take (url, options) — anything to send has to go in the query
      // string, so only send what was explicitly asked for. `tableId` alone is
      // never worth putting in a URL.
      const resp = (verb === 'get' || verb === 'delete')
        ? await send.call(window.http, actionUrl, Object.keys(data).length ? {params: data} : {})
        : await send.call(window.http, actionUrl, requestData);

      // Handle 403 Forbidden - let ResponseHandler show the error message
      if (resp?.status === 403) {

        const responseData = resp?.data?.data ?? resp?.data ?? resp;
        NotificationManager.error(responseData?.message || 'Access forbidden');

        this.setActionBusy(submitButton, false);

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

        this.setActionBusy(submitButton, false);
        return false;
      }

      const rawResponse = resp?.data ?? resp;
      // เช่นเดียวกับ action ในแถว — ต้องได้ชั้นที่ถือ actions ไม่ใช่ชั้นข้อมูล
      const responseData = window.ResponseHandler
        ? ResponseHandler.payloadOf(resp)
        : (resp?.data?.data ?? resp?.data ?? resp);
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

      this.setActionBusy(submitButton, false);

      return success;

    } catch (error) {
      console.error('Table action error:', error);

      this.setActionBusy(submitEl, false);

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

  /**
   * Removes rows from the DOM by id, used after a bulk delete succeeded.
   *
   * @param {Object} table - Table instance
   * @param {Array<string|number>} ids - Row identities to remove
   * @returns {void}
   */
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

  /**
   * Refreshes what depends on the number of rows on screen, and puts the empty
   * message in place when the last row is gone.
   *
   * @param {Object} table - Table instance
   * @returns {void}
   */
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

  /**
   * Adds keyboard navigation to a table: the arrow keys move between rows and
   * headers, Enter sorts, and Space selects.
   *
   * @param {HTMLTableElement} table - Table element
   * @param {string} tableId - Table id
   * @returns {void}
   */
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

  /**
   * Moves focus to the next or previous sortable header, wrapping at the ends.
   *
   * @param {HTMLTableElement} table - Table element
   * @param {HTMLTableCellElement} currentHeader - Header holding focus
   * @param {boolean} forward - Move forward rather than back
   * @returns {void}
   */
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

  /**
   * Announces the row that gained focus to screen readers, through the live
   * region named by data-announcer.
   *
   * @param {HTMLTableElement} table - Table element
   * @param {number} rowIndex - Position of the row
   * @returns {void}
   */
  announceRowChange(table, rowIndex) {
    const announcer = document.getElementById(table.dataset.announcer);
    if (announcer) {
      const row = table.querySelectorAll('tbody tr')[rowIndex];
      const firstCell = row.querySelector('td');
      announcer.textContent = Now.translate('Row') + ` ${rowIndex + 1}: ${firstCell?.textContent || ''}`;
    }
  },

  /**
   * Loads rows from a source with explicit parameters, guarding against a
   * second load starting while one is still running.
   *
   * @param {string} tableId - Table id
   * @param {string} source - Endpoint URL
   * @param {Object} [params={}] - Query parameters
   * @returns {Promise<void>}
   */
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

  /**
   * Replaces the body of a table with a loading row.
   *
   * @param {Object} table - Table instance
   * @returns {void}
   */
  showLoading(table) {
    const tbody = table.element.querySelector('tbody');
    tbody.innerHTML = `<tr><td colspan="100%" class="text-center">${Now.translate('Loading')}...</td></tr>`;
  },

  /**
   * Collects the rows that are currently selected.
   *
   * @param {HTMLTableElement} table - Table element
   * @returns {Array} The selected rows
   */
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

  /**
   * Reads the visible text of the cells in a row.
   *
   * @param {HTMLTableRowElement} row - Row to read
   * @returns {string[]} Cell text, in column order
   */
  getRowData(row) {
    const cells = row.querySelectorAll('td');
    return Array.from(cells).map(cell => cell.textContent.trim());
  },

  /**
   * Parses the data-row-actions attribute, accepting a JSON object or array as
   * well as the shorthand list 'print,edit,delete'.
   *
   * @param {string|Object} raw - Attribute value
   * @returns {Object|Array|null} Parsed actions, or null when the attribute is empty
   */
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

  /**
   * Works out the visible label and the tooltip of a row action.
   *
   * An action given a title but no label renders as an icon with a tooltip; one
   * given neither falls back to its key for both.
   *
   * @param {string} key - Action key
   * @param {Object} [cfg={}] - Action configuration
   * @returns {Object} {label, title}
   */
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

  /**
   * Writes the label and tooltip of a row action onto its button, translating
   * both.
   *
   * @param {HTMLElement} element - Action button
   * @param {Object} actionText - Result of getRowActionTextConfig()
   * @returns {void}
   */
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
  /**
   * Builds the action cell of a row: one button per action the row allows,
   * after its condition has been evaluated against the row data.
   *
   * @param {Object} table - Table instance
   * @param {string} tableId - Table id
   * @param {Object} item - Row data
   * @param {Object} actions - Actions declared for the table
   * @returns {HTMLTableCellElement} The action cell
   */
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
            this._executeRowAction(tableId, actionUrl, item, subKey, subObj, e.currentTarget);
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
          this._executeRowAction(tableId, actionUrl, item, key, cfg, e.currentTarget);
        });
        wrapper.appendChild(btnEl);
      }
    });

    /*
     * **A cell is returned even when nothing goes in it.**
     *
     * The header column is added whenever `data-row-actions` is present, without
     * asking any row whether it will use it — so a row that returned nothing here
     * simply ended one `<td>` early. The browser does not leave a hole where the
     * missing cell was: it stops the row short, and with borders or zebra striping
     * on, that row visibly fails to reach the right-hand edge of the table.
     *
     * That is the ordinary case, not a rare one. `condition` exists precisely so a
     * button appears only on the rows it applies to, and any table whose actions all
     * depend on state has rows where none of them do.
     *
     * The `btn-group` wrapper is left out of an empty cell rather than added empty,
     * so it contributes no spacing of its own.
     */
    if (wrapper.childNodes.length) {
      td.appendChild(wrapper);
    }

    return td;
  },

  /**
   * Evaluates the condition that decides whether an action applies to a row,
   * accepting a boolean, a field name or a comparison expression.
   *
   * An empty condition means the action always applies.
   *
   * @param {boolean|string} condition - Condition to evaluate
   * @param {Object} item - Row data to evaluate against
   * @returns {boolean} True when the action applies
   */
  evaluateTableCondition(condition, item) {
    if (condition === undefined || condition === null || condition === '') {
      return true;
    }
    if (typeof condition === 'boolean') {
      return condition;
    }

    let expression = String(condition).trim();

    // `${...}` is a wrapper the author may write once around the whole condition
    // (`${can_manage && enabled}`) or once around each operand
    // (`${can_manage} && ${enabled}`) — both mean the same thing · stripping only
    // an outermost pair turned the second form into the nonsense `can_manage} &&
    // ${enabled`, which evaluated to undefined and silently hid the button
    if (expression.includes('${')) {
      expression = expression.replace(/\$\{([\s\S]*?)\}/g, '$1').trim();
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

  /**
   * Internal executor for per-row actions
   *
   * @param {HTMLElement|null} triggerEl The button actually pressed by the user — used to show the running status.
   * This parameter was not originally available. Everywhere creates a floating button that isn't in the DOM.
   * The `loading` class goes to an element that no one can see = Press it and the screen is completely still.
   */
  async _executeRowAction(tableId, actionUrl, item, actionKey, cfg = null, triggerEl = null) {
    const table = this.state.tables.get(tableId);
    if (!table) return;

    // Extract modal config if defined
    const modalConfig = (cfg && cfg.modal) ? cfg.modal : null;

    try {
      let confirmMsg = null;
      if (cfg && cfg.confirm) {
        // A custom message is authored the same way every other label in
        // `data-row-actions` is — as an English source string that the language
        // file translates. Passing it through untranslated left one raw English
        // sentence in the middle of an otherwise translated confirm dialog.
        confirmMsg = typeof cfg.confirm === 'string'
          ? Now.translate(cfg.confirm)
          : Now.translate('Are you sure you want to perform this action?');
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
    //
    // The `{action, id, row}` envelope exists for the table-wide `data-action-url`
    // style, where one endpoint receives every action and branches on `action`.
    // A per-action `url` already names the resource and the verb, so it carries
    // only what the action explicitly asked for — sending the whole row to a REST
    // endpoint puts it in the query string (and the access log) for GET/DELETE.
    const restStyle = !!(cfg && typeof cfg.url === 'string' && cfg.url !== '');
    let payload = restStyle ? {} : {action: actionKey, id: item.id, row: item};

    // Reads {field} out of the row, supporting nested keys like user.id.
    //
    // Used by both `params` and `url` so a row action can target a REST resource
    // (`/api/users/{id}/password-reset`) instead of one action endpoint that
    // receives `{action, id}` and branches server-side.
    const readField = (key) => {
      const parts = String(key).split('.');
      let cur = item;
      for (let p of parts) {
        if (cur == null) return null;
        cur = cur[p];
      }
      return cur;
    };

    // Into a URL.
    //
    // A placeholder that is *part* of a URL is one component of it —
    // `/api/users/{id}/reset` — so it is escaped on the way in, or a value holding
    // a `/` would silently invent a path segment.
    //
    // **A placeholder that is the whole value is not a component, it is the URL.**
    // A row that carries a ready-made link (`"url": "{evidence_url}"`, holding
    // something like `/logs?source=site:3:access`) had every one of its slashes,
    // its `?` and its `&` escaped, turning the address into one opaque word — which
    // no longer even starts with `/`, so the SPA router declined it and the browser
    // resolved the wreckage against the current page. There is no reading of
    // "escape this" that is right for a value that is already a URL.
    const interpolateUrl = (val) => {
      if (typeof val !== 'string') return val;

      const whole = val.match(/^\{([^}]+)\}$/);

      if (whole) {
        const cur = readField(whole[1]);

        return cur == null ? '' : String(cur);
      }

      return val.replace(/\{([^}]+)\}/g, (m, key) => {
        const cur = readField(key);
        return cur != null ? encodeURIComponent(cur) : '';
      });
    };

    // Into a param, where it must stay raw.
    //
    // **Params used to be escaped here too, whatever they were about to travel in.**
    // A POST therefore carried the escaped text inside its JSON body, where nothing
    // ever unescapes it, and a GET or DELETE had it escaped a second time on the way
    // out (by URLSearchParams when navigating, by the HTTP client's query serializer
    // otherwise). A value with no reserved characters in it survived both routes
    // unharmed — `"confirm_domain": "{domain}"` is why this went unnoticed — but
    // anything holding a `/`, a space or an `&` arrived as `%2F`, `%20`, `%26`, and a
    // file path sent that way names no file that exists.
    //
    // Escaping belongs to whoever builds the string, and for a param that is never
    // this function.
    const interpolateValue = (val) => {
      if (typeof val !== 'string') return val;

      // A lone `{field}` hands back the value itself, so a number stays a number
      // and a boolean stays a boolean rather than becoming "true"
      const whole = val.match(/^\{([^}]+)\}$/);

      if (whole) {
        const cur = readField(whole[1]);
        return cur == null ? '' : cur;
      }

      return val.replace(/\{([^}]+)\}/g, (m, key) => {
        const cur = readField(key);
        return cur != null ? String(cur) : '';
      });
    };

    // If action config provides params, interpolate templates against row data
    if (cfg && cfg.params && typeof cfg.params === 'object') {
      const resolved = {};
      Object.entries(cfg.params).forEach(([k, v]) => {
        if (v && typeof v === 'object') {
          resolved[k] = JSON.stringify(v); // simple fallback
        } else {
          resolved[k] = interpolateValue(v);
        }
      });

      payload = {...payload, ...resolved};
    }

    // Allow overriding method (GET will perform navigation with query string)
    const method = (cfg && cfg.method) ? cfg.method.toUpperCase() : 'POST';

    // A per-action `url` wins over the table-wide `data-action-url`.
    // Keeping the fallback means existing tables that post every action to one
    // endpoint keep working exactly as before.
    const targetUrl = (cfg && typeof cfg.url === 'string' && cfg.url !== '')
      ? interpolateUrl(cfg.url)
      : actionUrl;

    // A GET row action navigates by default — that is how "open the editor page" tables
    // have always worked, and changing it would break every one of them. `"navigate": false`
    // asks for the opposite: fetch the resource and let ResponseHandler run whatever the
    // server sends back. That is what a shared Add/Edit form needs — the row hands its id
    // to the API, the API answers with `actions: [{type:'modal', template}]`, and the same
    // form that Add opens comes up filled in.
    const navigates = !(cfg && cfg.navigate === false);

    if (targetUrl) {
      if (method === 'GET' && navigates) {
        const params = new URLSearchParams();
        Object.entries(payload).forEach(([k, v]) => {
          if (typeof v === 'object') params.append(k, JSON.stringify(v));
          else params.append(k, v == null ? '' : v);
        });
        const query = params.toString();
        const dest = query === ''
          ? targetUrl
          : targetUrl + (targetUrl.indexOf('?') === -1 ? '?' : '&') + query;
        const target = cfg?.target || '_self';
        const isDownload = !!cfg?.download && cfg.download !== 'false';

        if (isDownload) {
          // A bare `<a download>` click looked right, but Chrome/Edge fetch a
          // download-attributed anchor as a different request kind than a
          // normal navigation — an expired/invalid session redirects it to
          // the HTML login page same as any other request, and the browser
          // saves *that* under the requested filename with no way for us to
          // notice. Fetching explicitly lets us check the response actually
          // succeeded (and wasn't redirected somewhere else) before saving it,
          // the same way exportData() already does for CSV/JSON.
          try {
            const resp = await fetch(dest, {credentials: 'same-origin'});
            if (!resp.ok || resp.redirected) {
              this.handleError(
                `Download failed for action "${actionKey}" (status ${resp.status}${resp.redirected ? ', redirected to ' + resp.url : ''})`,
                null,
                'rowActionDownload'
              );
              return;
            }
            const blob = await resp.blob();
            let filename = typeof cfg.download === 'string' ? String(interpolateValue(cfg.download)) : '';
            if (!filename) {
              const disposition = resp.headers.get('content-disposition') || '';
              const m = disposition.match(/filename\*=UTF-8''([^;\n\r]+)/i) || disposition.match(/filename="?([^";\n\r]+)"?/i);
              if (m && m[1]) filename = decodeURIComponent(m[1]);
            }
            this.downloadBlob(blob, filename || 'download');
          } catch (err) {
            this.handleError(`Download request failed for action "${actionKey}"`, err, 'rowActionDownload');
          }
          return;
        }

        if (target === '_blank') {
          window.open(dest, '_blank', 'noopener,noreferrer');
          return;
        }

        // A same-origin app route should not reload the whole page
        if (window.RouterManager && typeof RouterManager.navigate === 'function' && dest.startsWith('/')) {
          RouterManager.navigate(dest);
        } else {
          window.location.href = dest;
        }
        return;
      }

      // use existing sendAction helper which handles response
      try {
        await this.sendAction(targetUrl, payload, tableId, triggerEl, {
          modalConfig: modalConfig,
          table: table,
          row: item,
          action: actionKey,
          method: method
        });
      } catch (err) {
        console.error('Row action error:', err);
      }
    } else {
      // No actionUrl: emit an event for external handling, include payload
      EventManager.emit('table:rowAction', {tableId, action: actionKey, payload, row: item});
    }
  },

  /**
   * Writes the number of selected rows into the counter and hides it when
   * nothing is selected.
   *
   * @param {HTMLTableElement} table - Table element
   * @param {number} count - Rows selected
   * @returns {void}
   */
  updateSelectedCount(table, count) {
    const selectedCountElement = table.closest('.table-container')
      ?.querySelector('.selected-count');

    if (selectedCountElement) {
      selectedCountElement.textContent = Now.translate('Selected {count} rows', {count: count});

      selectedCountElement.style.display = count > 0 ? 'block' : 'none';
    }
  },

  /**
   * Formats a cell value by the format its column declares: number, currency,
   * percent, date, datetime, time, boolean, file size and the text transforms.
   *
   * @param {*} value - Value to format
   * @param {string} format - Format name, with its arguments after a colon
   * @param {Object} [options={}] - Format options
   * @param {string} [options.emptyText] - Shown when the value is null or undefined
   * @returns {string|*} Formatted value, or the value itself when no format applies
   */
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

        case 'lookup': {
          // For select options display - support multiple formats
          // options can be: object {value: label}, array [{value, text/label}], or Map
          if (!options) {
            return value; // No options provided, return raw value
          }

          // A label comes from the option list (a catalog the developer or the
          // API wrote), so a `{LNG_...}` in it is a request to translate — the
          // same option shows translated in the column's filter select. The
          // cell itself is translate="no", so it is resolved here. Only the
          // marker is touched; a plain label passes through as written. A raw
          // row value that matched nothing is returned untranslated.
          const label = (text) => (typeof text === 'string' ? this.translateValue(text) : text);

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
              return label(found.label || found.text || String(found.value || found));
            }
            return value; // No data available, return raw value
          }

          // Handle Map format
          if (options instanceof Map) {
            const mapped = options.get(value);
            return mapped ? label(mapped) : value;
          }

          // Handle object format: {value: label, ...}
          if (typeof options === 'object') {
            return options[value] !== undefined ? label(options[value]) : value;
          }

          return value;
        }

        case 'number':
          const numberDecimals = getDecimals(0);
          // Accept empty string locale by falling back to undefined (uses runtime default)
          const nfLocale = options && options.locale ? options.locale : undefined;
          return new Intl.NumberFormat(nfLocale, {
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
          const pctLocale = options && options.locale ? options.locale : undefined;
          return new Intl.NumberFormat(pctLocale, {
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
  /**
   * Builds the sort query parameters from the sort state, in the compact
   * 'name asc,status desc' form the URL uses.
   *
   * @param {Object} table - Table instance
   * @returns {Object} Sort parameters
   */
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
  /**
   * Builds the page and pageSize query parameters.
   *
   * @param {Object} table - Table instance
   * @returns {Object} Pagination parameters
   */
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

  /**
   * Builds the filter query parameters, leaving out the pagination, sort and
   * total keys that are not filters.
   *
   * @param {Object} table - Table instance
   * @returns {Object} Filter parameters
   */
  getFilterParams(table) {
    const params = {};
    const excludeKeys = ['page', 'pageSize', 'search', 'total', 'totalPages', 'sort', 'order'];

    // Include all filter parameters from config.params except pagination/meta keys
    Object.keys(table.config.params).forEach(key => {
      if (!excludeKeys.includes(key)) {
        const value = table.config.params[key];
        // Only include non-null/undefined values (allow empty strings)
        if (value !== null && value !== undefined) {
          // A filter is a scalar. An array or an object here is response data
          // that found its way into params, and stringifying it produces
          // `key=[object Object]` in the query string — which is how a URL grows
          // until the parameters that matter can no longer be seen or set.
          // Filtered at the source too (see the meta merge in updateTableData);
          // this is the second gate, because params can be written from several places.
          if (typeof value !== 'object') {
            params[key] = value;
          }
        }
      }
    });

    return params;
  },

  /**
   * Exports the rows of a table as a file.
   *
   * A server-side table asks its endpoint for the export so every page is
   * included; a client-side one builds the file from the data it holds.
   *
   * @param {string} tableId - Table id
   * @param {string} [format='csv'] - Export format
   * @param {Object} [options={}] - Export options
   * @returns {Promise<void|*>} The result of handleError() when the table is unknown
   */
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

  /**
   * Hands a blob to the browser as a download and releases the object URL
   * afterwards.
   *
   * @param {Blob} blob - Content to download
   * @param {string} filename - Name to save it as
   * @returns {void}
   */
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

  /**
   * Clears the registry and configuration, returning the manager to its
   * pre-init state. Used by the tests.
   *
   * @returns {void}
   */
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
