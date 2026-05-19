/**
* GraphComponent for Now.js Framework
* Pure JavaScript implementation of basic charts with modular architecture
*
* Features:
* - Line charts
* - Bar charts
* - Pie charts
* - Donut charts
* - Gauge charts
* - SVG based rendering
* - Tooltips
* - Legends
* - Responsive design
* - Animation support
*/
const GraphComponent = {
  config: {
    // Base options
    autoload: true,
    debug: false,

    // Appearance options
    colors: ['#FF6B6B', '#4ECDC4', '#45B7D1', '#FFA07A', '#98D8C8', '#F9ED69', '#F08A5D', '#B83B5E', '#6A2C70', '#00B8A9'],
    backgroundColor: '#ffffff',
    showGrid: true,
    gridColor: '#E0E0E0',
    axisColor: '#333333',
    curve: true,
    maxGaugeValue: 100,
    centerText: null,
    showCenterText: true,
    gap: 2,
    borderWidth: 1,
    borderColor: 'auto',
    pointRadius: 4,
    lineWidth: 2,
    fillArea: false,
    fillOpacity: 0.1,

    // Font options
    fontFamily: 'Arial, sans-serif',
    textColor: '#333333',
    fontSize: 16,

    // Component behavior
    showAxisLabels: true,
    showAxis: true,
    animationDuration: 1000,
    donutThickness: 30,
    gaugeCurveWidth: 30,
    showLegend: true,
    legendPosition: 'bottom',
    showTooltip: true,
    tooltipFormatter: null,
    showDataLabels: true,
    showValueInsteadOfPercent: true,
    animation: false,
    maxDataPoints: 20,

    // Data loading
    type: 'line',
    table: null,
    url: null,
    data: null,
    cache: false,
    cacheTime: 60000,

    // Event handlers
    onClick: null,
    onLoad: null,
    onRender: null,
    onDataChange: null,

    // Refresh options
    pollingInterval: 0,
    refreshEvent: null
  },

  state: {
    instances: new Map(),
    initialized: false,
    renderer: null,
    pendingLoads: new Map(),
    timers: new Map(),
    cache: new Map()
  },

  /**
   * Initialize GraphComponent
   * @param {Object} options - Configuration options
   * @returns {Object} - GraphComponent instance
   */
  async init(options = {}) {
    if (this.state.initialized) return this;

    this.config = {...this.config, ...options};

    // Initialize existing components
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', () => this.initElements());
    } else {
      this.initElements();
    }

    // Setup mutation observer to detect new graph components
    this.setupObserver();

    // Listen for locale changes to redraw graphs with new translations
    if (window.EventManager?.on) {
      this.state.localeChangeHandler = () => this.refreshAll();
      EventManager.on('locale:changed', this.state.localeChangeHandler);
    }

    this.state.initialized = true;
    return this;
  },

  /**
   * Initialize all graph components in the document
   */
  initElements() {
    document.querySelectorAll('[data-component="graph"]').forEach(element => {
      this.create(element);
    });
  },

  /**
   * Setup mutation observer to detect new graph components
   */
  setupObserver() {
    const observer = new MutationObserver(mutations => {
      mutations.forEach(mutation => {
        mutation.addedNodes.forEach(node => {
          if (node.nodeType === 1) {
            // Check if the node is a graph component
            if (node.dataset && node.dataset.component === 'graph') {
              this.create(node);
            }

            // Check for graph components within the added node
            const graphElements = node.querySelectorAll('[data-component="graph"]');
            graphElements.forEach(element => {
              this.create(element);
            });
          }
        });

        // Handle removed nodes to clean up instances
        mutation.removedNodes.forEach(node => {
          if (node.nodeType === 1) {
            // Wait for DOM operations to settle before cleanup
            // This prevents premature cleanup when nodes are moved in DOM
            setTimeout(() => {
              if (node.dataset && node.dataset.component === 'graph') {
                if (!node.isConnected) {
                  this.destroy(node);
                }
              }

              const graphElements = node.querySelectorAll('[data-component="graph"]');
              graphElements.forEach(element => {
                if (!element.isConnected) {
                  this.destroy(element);
                }
              });
            }, 0);
          }
        });
      });
    });

    observer.observe(document.body, {
      childList: true,
      subtree: true
    });
  },

  /**
   * Create a new graph instance
   * @param {HTMLElement|string} element - The container element or selector
   * @param {Object} options - Configuration options
   * @returns {Object} - Graph instance
   */
  async create(element, options = {}) {
    // Handle selector strings
    if (typeof element === 'string') {
      element = document.querySelector(element);
    }

    if (!element) {
      console.error('GraphComponent: Element not found');
      return null;
    }

    // Check for existing instance
    const existingInstance = this.getInstance(element);
    if (existingInstance) {
      return existingInstance;
    }

    // Extract options from data attributes
    const dataOptions = this.extractOptionsFromElement(element);

    // Merge options (priority: default < data attributes < passed options)
    const mergedOptions = {...this.config, ...dataOptions, ...options};

    // Create instance ID
    const id = 'graph_' + Math.random().toString(36).substring(2, 11);

    // Create instance object
    const instance = {
      id,
      element,
      options: mergedOptions,
      data: null,
      state: {
        loading: false,
        error: null,
        rendered: false,
        width: element.clientWidth,
        height: element.clientHeight
      },
      renderer: null,
      timer: null
    };

    // Mark element with ID
    element.dataset.graphComponentId = id;
    element.classList.add('graph-component');

    // Store instance
    this.state.instances.set(id, instance);

    // Ensure renderer is loaded
    await this.ensureRenderer();

    // Setup the instance
    this.setupInstance(instance);

    // Load data if autoload is enabled
    if (instance.options.autoload) {
      this.loadData(instance);
    }

    // Setup polling if configured
    if (instance.options.pollingInterval > 0 && instance.options.url) {
      this.startPolling(instance);
    }

    // Setup refresh event if configured
    if (instance.options.refreshEvent) {
      this.bindRefreshEvent(instance);
    }

    // Store reference on element
    element.graphInstance = instance;

    // Dispatch created event
    this.dispatchEvent(instance, 'created', {
      instance
    });

    return instance;
  },

  /**
   * Setup a graph instance with required methods and initial state
   * @param {Object} instance - Graph instance to setup
   */
  setupInstance(instance) {
    // Add methods to instance
    instance.setData = (data) => this.setData(instance, data);
    instance.loadData = (url) => this.loadData(instance, url);
    instance.refresh = () => this.refresh(instance);
    instance.addDataPoint = (point, seriesIndex) => this.addDataPoint(instance, point, seriesIndex);
    instance.setType = (type) => this.setType(instance, type);
    instance.exportToImage = (filename, format, width, height, quality, scale) =>
      this.exportToImage(instance, filename, format, width, height, quality, scale);
    instance.destroy = () => this.destroy(instance);

    // Initialize renderer
    instance.renderer = new this.state.renderer(instance.element, instance.options);

    // Set initial data if provided
    if (instance.options.data) {
      this.setData(instance, instance.options.data);
    } else if (instance.options.table) {
      const table = document.getElementById(instance.options.table);
      if (table) {
        const data = this.loadAndProcessTableData(instance, table);
        this.setData(instance, data);
      }
    }

    // Setup resize handler
    const resizeHandler = this.debounce(() => {
      if (!instance.element) return;

      const width = instance.element.clientWidth;
      const height = instance.element.clientHeight;

      if (width !== instance.state.width || height !== instance.state.height) {
        instance.state.width = width;
        instance.state.height = height;

        if (instance.renderer) {
          instance.renderer.handleResize();
        }
      }
    }, 200);

    window.addEventListener('resize', resizeHandler);
    instance.resizeHandler = resizeHandler;

    // Observe element size changes (helps when element becomes visible after navigation)
    if (typeof ResizeObserver !== 'undefined') {
      const resizeObserver = new ResizeObserver(entries => {
        for (const entry of entries) {
          if (!instance.element) return;
          const width = entry.contentRect.width;
          const height = entry.contentRect.height;

          if (width !== instance.state.width || height !== instance.state.height) {
            instance.state.width = width;
            instance.state.height = height;

            if (instance.renderer) {
              instance.renderer.handleResize();
            }
          }
        }
      });

      resizeObserver.observe(instance.element);
      instance.resizeObserver = resizeObserver;
    }
  },

  /**
   * Ensure the renderer is loaded
   * @returns {Promise} - Resolves when renderer is loaded
   */
  async ensureRenderer() {
    if (this.state.renderer) {
      return this.state.renderer;
    }

    // If GraphRenderer is already available globally
    if (window.GraphRenderer) {
      this.state.renderer = window.GraphRenderer;
      return this.state.renderer;
    }

    // Otherwise, create a simplified renderer internally
    this.state.renderer = this.createInternalRenderer();
    return this.state.renderer;
  },

  /**
   * Create internal renderer if external renderer is not available
   * @returns {Class} - Graph renderer class
   */
  createInternalRenderer() {
    return class InternalGraphRenderer {
      constructor(element, options) {
        this.element = element;
        this.options = options;
        this.svg = null;
        this.width = element.clientWidth;
        this.height = element.clientHeight;

        // Create SVG
        this.createSVG();
      }

      createSVG() {
        if (this.width <= 0 || this.height <= 0) return;

        this.svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        this.svg.setAttribute('width', '100%');
        this.svg.setAttribute('height', '100%');
        this.svg.setAttribute('viewBox', `0 0 ${this.width} ${this.height}`);
        this.svg.setAttribute('role', 'img');
        this.svg.setAttribute('aria-label', 'Data Visualization Graph');

        this.element.appendChild(this.svg);
      }

      setData(data) {
        this.data = data;
        this.renderGraph();
      }

      renderGraph() {
        if (!this.svg) this.createSVG();
        this.svg.innerHTML = '';

        // Display placeholder text indicating this is a simplified renderer
        const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
        text.setAttribute('x', this.width / 2);
        text.setAttribute('y', this.height / 2);
        text.setAttribute('text-anchor', 'middle');
        text.setAttribute('dominant-baseline', 'middle');
        text.setAttribute('font-family', this.options.fontFamily);
        text.setAttribute('font-size', '14px');
        text.textContent = 'Graph Rendering (Full implementation required)';

        this.svg.appendChild(text);
      }

      handleResize() {
        if (!this.element) return;

        this.width = this.element.clientWidth;
        this.height = this.element.clientHeight;

        if (this.svg) {
          this.svg.setAttribute('viewBox', `0 0 ${this.width} ${this.height}`);
          this.renderGraph();
        }
      }

      destroy() {
        if (this.svg && this.element.contains(this.svg)) {
          this.element.removeChild(this.svg);
        }
      }
    };
  },

  /**
   * Set data for a graph instance
   * @param {Object} instance - Graph instance
   * @param {Array} data - Data to set
   */
  setData(instance, data) {
    if (!instance || !data) return;

    instance.data = data;

    if (instance.renderer) {
      instance.renderer.setData(data);
      instance.state.rendered = true;

      this.dispatchEvent(instance, 'data-changed', {
        data: data
      });

      if (typeof instance.options.onDataChange === 'function') {
        instance.options.onDataChange.call(instance, data);
      }
    }
  },

  /**
   * Load data for a graph instance
   * @param {Object} instance - Graph instance
   * @param {string} [url] - URL to load data from (optional)
   * @returns {Promise} - Resolves when data is loaded
   */
  async loadData(instance, url, requestOptions = {}) {
    if (!instance) return;

    // Use provided URL or fallback to instance options
    const dataUrl = url || instance.options.url;

    // If we have direct data, use that instead
    if (!dataUrl && instance.options.data) {
      this.setData(instance, instance.options.data);
      return;
    }

    // If we have a table, use that
    if (!dataUrl && instance.options.table) {
      const table = document.getElementById(instance.options.table);
      if (table) {
        const data = this.loadAndProcessTableData(instance, table);
        this.setData(instance, data);
        return;
      }
    }

    // If no data source is available, return
    if (!dataUrl) {
      console.warn('GraphComponent: No data source specified');
      return;
    }

    const useCache = this.shouldUseCache(instance, requestOptions);
    const cacheKey = this.createCacheKey(dataUrl);

    if (useCache) {
      const cachedData = this.getCachedData(cacheKey);
      if (cachedData) {
        this.setData(instance, cachedData);
        instance.state.loading = false;
        instance.state.error = null;
        this.dispatchEvent(instance, 'loaded', {
          data: cachedData,
          fromCache: true
        });
        if (typeof instance.options.onLoad === 'function') {
          instance.options.onLoad.call(instance, cachedData);
        }
        return cachedData;
      }
    }

    // Set loading state
    instance.state.loading = true;
    instance.state.error = null;
    this.dispatchEvent(instance, 'loading');

    try {
      // Use window.http if available (preferred)
      let response;
      const requestConfig = useCache
        ? {cache: 'default'}
        : {
          cache: 'no-store',
          headers: {
            'Cache-Control': 'no-cache',
            'Pragma': 'no-cache'
          }
        };
      if (window.http && typeof window.http.get === 'function') {
        response = await window.http.get(dataUrl, requestConfig);
      } else if (window.simpleFetch && typeof window.simpleFetch.get === 'function') {
        const requestHeaders = {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          ...(useCache ? {} : {
            'Cache-Control': 'no-cache',
            'Pragma': 'no-cache'
          })
        };
        response = await simpleFetch.get(dataUrl, {
          headers: requestHeaders,
          cache: useCache ? 'default' : 'no-store'
        });
      } else {
        throw new Error('HttpClient (window.http) or simpleFetch is required');
      }

      // Handle response data
      // window.http returns the full response object, simpleFetch might return data directly
      let data = response.data || response;

      // Unwrap 'data' property if present (common in API responses)
      if (data && data.data) {
        data = data.data;
      }

      // Unwrap another 'data' property if present (nested structure from dashboard.php)
      // structure: { success: true, data: { data: [...] } } -> we are at { data: [...] }
      if (data && data.data && Array.isArray(data.data)) {
        data = data.data;
      }

      // If there's a path option, extract nested data
      if (instance.options.path) {
        const path = instance.options.path;
        const keys = path.split('.');
        for (const key of keys) {
          if (data && typeof data === 'object' && key in data) {
            data = data[key];
          } else {
            console.warn(`GraphComponent: Path "${path}" not found in response data`);
            data = null;
            break;
          }
        }
      }

      // Set the data
      if (data) {
        this.setData(instance, data);
        if (useCache) {
          this.setCachedData(cacheKey, data, instance.options.cacheTime);
        }
      }

      // Update state
      instance.state.loading = false;

      // Dispatch loaded event
      this.dispatchEvent(instance, 'loaded', {
        data: data,
        fromCache: false
      });

      // Call onLoad callback if provided
      if (typeof instance.options.onLoad === 'function') {
        instance.options.onLoad.call(instance, data);
      }

      return data;

    } catch (error) {
      // Ignore AbortError as it's not a real error
      if (error.name === 'AbortError') {
        return;
      }

      // Handle other errors
      instance.state.loading = false;
      instance.state.error = error.message;

      this.dispatchEvent(instance, 'error', {
        error: error
      });

      console.error('GraphComponent: Error loading data', error);
    }
  },

  /**
   * Add a single data point to a graph
   * @param {Object} instance - Graph instance
   * @param {Object} point - Data point to add
   * @param {number} [seriesIndex=0] - Index of the series to add the point to
   */
  addDataPoint(instance, point, seriesIndex = 0) {
    if (!instance || !instance.renderer) return;

    instance.renderer.addDataPoint(point, seriesIndex);

    this.dispatchEvent(instance, 'data-point-added', {
      point,
      seriesIndex
    });
  },

  /**
   * Change the chart type
   * @param {Object} instance - Graph instance
   * @param {string} type - New chart type (line, bar, pie, donut, gauge)
   */
  setType(instance, type) {
    if (!instance || !instance.renderer) return;

    // Update the options
    instance.options.type = type;

    // If we can update the renderer's type directly
    if (instance.renderer.currentChartType !== undefined) {
      instance.renderer.currentChartType = type;
      instance.renderer.redrawGraph();
    } else {
      // Otherwise, recreate the renderer
      instance.renderer.destroy();
      instance.renderer = new this.state.renderer(instance.element, instance.options);

      if (instance.data) {
        instance.renderer.setData(instance.data);
      }
    }

    this.dispatchEvent(instance, 'type-changed', {
      type
    });
  },

  /**
   * Export the graph to an image file
   * @param {Object} instance - Graph instance
   * @param {string} [filename='graph'] - The base name of the exported file
   * @param {string} [format='png'] - The image format ('png', 'jpeg', or 'svg')
   * @param {number} [width] - The width of the exported image
   * @param {number} [height] - The height of the exported image
   * @param {number} [quality=0.8] - The quality for JPEG format
   * @param {number} [scale=1] - The scale factor for the exported image
   */
  exportToImage(instance, filename = 'graph', format = 'png', width, height, quality = 0.8, scale = 1) {
    if (!instance || !instance.renderer) return;

    if (typeof instance.renderer.exportToImage === 'function') {
      return instance.renderer.exportToImage(filename, format, width, height, quality, scale);
    } else {
      console.warn('GraphComponent: Export to image not supported by this renderer');
    }
  },

  /**
   * Refresh a graph instance
   * @param {Object|string} instance - Graph instance or ID
   */
  refresh(instance) {
    // Handle string ID
    if (typeof instance === 'string') {
      instance = this.state.instances.get(instance);
    } else if (instance instanceof HTMLElement) {
      instance = this.getInstance(instance);
    }

    if (!instance) return;

    this.loadData(instance, undefined, {force: true});
  },

  /**
   * Refresh all graph instances
   * Used when language changes to update labels and translations
   */
  refreshAll() {
    this.state.instances.forEach(instance => {
      this.loadData(instance);
    });
  },

  /**
   * Start polling for data updates
   * @param {Object} instance - Graph instance
   */
  startPolling(instance) {
    if (!instance || !instance.options.url || instance.options.pollingInterval <= 0) return;

    // Clear existing timer if any
    this.stopPolling(instance);

    // Create new timer
    const timer = setInterval(() => {
      if (!instance.state.loading) {
        this.loadData(instance, undefined, {force: true});
      }
    }, instance.options.pollingInterval);

    // Store timer
    this.state.timers.set(instance.id, timer);
    instance.timer = timer;

    this.dispatchEvent(instance, 'polling-started', {
      interval: instance.options.pollingInterval
    });
  },

  /**
   * Stop polling for data updates
   * @param {Object} instance - Graph instance
   */
  stopPolling(instance) {
    if (!instance) return;

    const timer = this.state.timers.get(instance.id);
    if (timer) {
      clearInterval(timer);
      this.state.timers.delete(instance.id);
      instance.timer = null;

      this.dispatchEvent(instance, 'polling-stopped');
    }
  },

  /**
   * Bind refresh event to the instance
   * @param {Object} instance - Graph instance
   */
  bindRefreshEvent(instance) {
    if (!instance || !instance.options.refreshEvent) return;

    const events = instance.options.refreshEvent.split(',').map(e => e.trim());
    instance.eventHandlers = [];

    events.forEach(eventName => {
      const handler = () => this.refresh(instance);

      // Store handler for cleanup
      instance.eventHandlers.push({eventName, handler});

      // Register with event manager if available
      EventManager.on(eventName, handler);
    });
  },

  /**
   * Unbind refresh events from an instance
   * @param {Object} instance - Graph instance
   */
  unbindRefreshEvent(instance) {
    if (!instance || !instance.eventHandlers) return;

    instance.eventHandlers.forEach(({eventName, handler}) => {
      EventManager.off(eventName, handler);
    });

    instance.eventHandlers = [];
  },

  /**
   * Get a graph instance by element or ID
   * @param {HTMLElement|string} element - Element or ID
   * @returns {Object|null} - Graph instance or null if not found
   */
  getInstance(element) {
    // Handle direct ID lookup
    if (typeof element === 'string') {
      return this.state.instances.get(element);
    }

    // Handle element lookup
    if (element instanceof HTMLElement) {
      // Check for direct reference
      if (element.graphInstance) {
        return element.graphInstance;
      }

      // Check for ID in dataset
      const id = element.dataset.graphComponentId;
      if (id && this.state.instances.has(id)) {
        return this.state.instances.get(id);
      }

      // Scan all instances
      for (const [id, instance] of this.state.instances.entries()) {
        if (instance.element === element) {
          return instance;
        }
      }
    }

    return null;
  },

  /**
   * Extract options from an element's data attributes
   * @param {HTMLElement} element - Element to extract options from
   * @returns {Object} - Extracted options
   */
  extractOptionsFromElement(element) {
    const options = {};
    const dataset = element.dataset;

    // Try to parse data-props first (JSON format)
    if (dataset.props) {
      try {
        const props = JSON.parse(dataset.props);
        Object.assign(options, props);
      } catch (e) {
        console.warn('GraphComponent: Invalid JSON in data-props', e);
      }
    }

    // Process individual data-* attributes
    for (const [key, value] of Object.entries(dataset)) {
      if (key === 'component' || key === 'props' || key === 'graphComponentId') continue;

      const optionKey = this.kebabToCamel(key);
      options[optionKey] = this.parseDataValue(value);
    }

    return options;
  },

  /**
   * Convert kebab-case to camelCase
   * @param {string} str - Kebab-case string
   * @returns {string} - CamelCase string
   */
  kebabToCamel(str) {
    return str.replace(/-([a-z])/g, (match, letter) => letter.toUpperCase());
  },

  /**
   * Parse a data attribute value to the appropriate type
   * @param {string} value - Data attribute value
   * @returns {*} - Parsed value
   */
  parseDataValue(value) {
    // Boolean values
    if (value === 'true') return true;
    if (value === 'false') return false;

    // Numeric values
    if (!isNaN(value) && value.trim() !== '') return Number(value);

    // Array or object (JSON)
    try {
      if ((value.startsWith('[') && value.endsWith(']')) ||
        (value.startsWith('{') && value.endsWith('}'))) {
        return JSON.parse(value);
      }
    } catch (e) {
      // If parsing fails, return as string
    }

    // Default: return as string
    return value;
  },

  normalizeCacheTime(value, fallback = 60000) {
    const parsed = parseInt(value, 10);
    return Number.isFinite(parsed) && parsed >= 0 ? parsed : fallback;
  },

  shouldUseCache(instance, requestOptions = {}) {
    if (requestOptions.force === true) {
      return false;
    }
    return instance?.options?.cache === true;
  },

  createCacheKey(url) {
    return String(url || '');
  },

  getCachedData(cacheKey) {
    const cached = this.state.cache.get(cacheKey);
    if (!cached) return null;
    if (cached.expiresAt <= Date.now()) {
      this.state.cache.delete(cacheKey);
      return null;
    }
    return cached.data;
  },

  setCachedData(cacheKey, data, cacheTime) {
    this.state.cache.set(cacheKey, {
      data,
      timestamp: Date.now(),
      expiresAt: Date.now() + this.normalizeCacheTime(cacheTime, this.config.cacheTime)
    });
  },

  /**
   * Dispatch an event from an instance
   * @param {Object} instance - Graph instance
   * @param {string} eventName - Event name
   * @param {Object} detail - Event details
   */
  dispatchEvent(instance, eventName, detail = {}) {
    if (!instance || !instance.element) return;

    // Create and dispatch DOM event
    const event = new CustomEvent(`graph:${eventName}`, {
      bubbles: true,
      cancelable: true,
      detail: {
        instance,
        ...detail
      }
    });

    instance.element.dispatchEvent(event);

    EventManager.emit(`graph:${eventName}`, {
      instance,
      ...detail
    });
  },

  /**
   * Load and process data from an HTML table
   * @param {Object} instance - Graph instance
   * @param {HTMLTableElement} table - Table element
   * @returns {Array} - Processed data
   */
  loadAndProcessTableData(instance, table) {
    if (!table) return [];

    // If renderer has this method, use it
    if (instance.renderer && typeof instance.renderer.loadAndProcessTableData === 'function') {
      return instance.renderer.loadAndProcessTableData(table);
    }

    // Otherwise use our implementation
    const headerRow = table.querySelector('thead > tr');
    const dataRows = table.querySelectorAll('tbody > tr');

    if (!headerRow || !dataRows.length) return [];

    // Get column headers (skip first which is usually row labels)
    const headers = Array.from(headerRow.querySelectorAll('th')).slice(1).map(th => th.innerHTML);

    // Process each data row
    const result = [];
    dataRows.forEach(row => {
      const seriesName = row.querySelector('th')?.textContent.trim() || '';
      const cells = row.querySelectorAll('td');

      const seriesData = [];
      cells.forEach((cell, index) => {
        if (index < headers.length) {
          seriesData.push({
            label: headers[index],
            value: parseFloat(cell.textContent.replace(/,/g, '')) || 0
          });
        }
      });

      if (seriesData.length > 0) {
        result.push({
          name: seriesName,
          data: seriesData
        });
      }
    });

    return result;
  },

  /**
   * Debounce a function
   * @param {Function} func - Function to debounce
   * @param {number} wait - Debounce wait time in milliseconds
   * @returns {Function} - Debounced function
   */
  debounce(func, wait) {
    let timeout;
    return function(...args) {
      clearTimeout(timeout);
      timeout = setTimeout(() => func.apply(this, args), wait);
    };
  },

  /**
   * Destroy a graph instance
   * @param {Object|string|HTMLElement} instance - Graph instance, ID, or element
   * @returns {boolean} - Success status
   */
  destroy(instance) {
    // Handle string ID
    if (typeof instance === 'string') {
      instance = this.state.instances.get(instance);
    } else if (instance instanceof HTMLElement) {
      instance = this.getInstance(instance);
    }

    if (!instance) return false;

    // Stop polling if active
    this.stopPolling(instance);

    // Unbind event handlers
    this.unbindRefreshEvent(instance);

    // Cancel any pending loads
    const pendingLoad = this.state.pendingLoads.get(instance.id);
    if (pendingLoad) {
      const apiService = window.ApiService || window.Now?.getManager?.('api');
      if (pendingLoad.type === 'api' && apiService?.abort) {
        apiService.abort(pendingLoad.url, pendingLoad.params || {});
      }
      this.state.pendingLoads.delete(instance.id);
    }

    // Remove resize handler
    if (instance.resizeHandler) {
      window.removeEventListener('resize', instance.resizeHandler);
    }

    if (instance.resizeObserver) {
      instance.resizeObserver.disconnect();
      instance.resizeObserver = null;
    }

    // Clean up renderer
    if (instance.renderer) {
      instance.renderer.destroy();
    }

    // Clean up DOM
    if (instance.element) {
      delete instance.element.graphInstance;
      delete instance.element.dataset.graphComponentId;
      instance.element.classList.remove('graph-component');
    }

    // Dispatch destroy event
    this.dispatchEvent(instance, 'destroyed');

    // Remove from instances map
    this.state.instances.delete(instance.id);

    return true;
  },

  /**
   * Destroy all graph instances
   */
  destroyAll() {
    for (const id of this.state.instances.keys()) {
      this.destroy(id);
    }
  }
};

// Register with Now.js framework
if (window.Now?.registerManager) {
  Now.registerManager('graph', GraphComponent);
}

// Register with ComponentManager if available
if (window.ComponentManager) {
  ComponentManager.define('graph', {
    template: null,

    validElement(element) {
      return element.classList.contains('graph-component') ||
        element.dataset.component === 'graph';
    },

    setupElement(element, state) {
      const options = {};

      // Extract options from element
      if (element.dataset.props) {
        try {
          const props = JSON.parse(element.dataset.props);
          Object.assign(options, props);
        } catch (e) {
          console.warn('Invalid props JSON', e);
        }
      }

      // Create graph component
      const graph = GraphComponent.create(element, options);

      // Store reference
      element._graphComponent = graph;
      return element;
    },

    beforeDestroy() {
      if (this.element && this.element._graphComponent) {
        GraphComponent.destroy(this.element._graphComponent);
        delete this.element._graphComponent;
      }
    }
  });
}

// Expose globally
window.GraphComponent = GraphComponent;
