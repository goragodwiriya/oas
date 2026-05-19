/**
* GraphRenderer for Now.js Framework
* Core rendering engine for GraphComponent
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
class GraphRenderer {
  /**
   * Themes for the graph
   */
  themes = {
    light: {
      backgroundColor: '#ffffff',
      textColor: '#333333',
      gridColor: '#e0e0e0',
      axisColor: '#666666'
    },
    dark: {
      backgroundColor: '#2d2d2d',
      textColor: '#ffffff',
      gridColor: '#404040',
      axisColor: '#808080'
    }
  }

  /**
   * Creates a new instance of GraphRenderer
   * @param {HTMLElement} container - The container element for the graph
   * @param {Object} options - Configuration options for the graph
   */
  constructor(container, options = {}) {
    if (!container) {
      throw new Error('Container element is required');
    }

    this.container = container;
    this.width = container.clientWidth;
    this.height = container.clientHeight;

    const containerStyles = window.getComputedStyle(this.container);
    const defaultFontSize = parseInt(containerStyles.fontSize, 10);
    const defaultFontFamily = containerStyles.fontFamily || 'Arial, sans-serif';
    const defaultTextColor = containerStyles.color || '#000000';
    let defaultBackgroundColor = containerStyles.backgroundColor;
    if (!defaultBackgroundColor || defaultBackgroundColor === 'transparent' || defaultBackgroundColor === 'rgba(0, 0, 0, 0)') {
      defaultBackgroundColor = '#ffffff';
    }

    this.defaultConfig = {
      colors: ['#FF6B6B', '#4ECDC4', '#45B7D1', '#FFA07A', '#98D8C8', '#F9ED69', '#F08A5D', '#B83B5E', '#6A2C70', '#00B8A9', '#F8F3D4', '#3F72AF'],
      backgroundColor: defaultBackgroundColor,
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
      fontFamily: defaultFontFamily,
      textColor: defaultTextColor,
      fontSize: isNaN(defaultFontSize) ? 16 : defaultFontSize,
      showAxisLabels: true,
      showAxis: true,
      animationDuration: 1000,
      donutThickness: 30,
      gaugeCurveWidth: 20,
      showLegend: true,
      legendPosition: 'bottom',
      showTooltip: true,
      tooltipFormatter: null,
      showDataLabels: true,
      showValueInsteadOfPercent: true,
      animation: false,
      maxDataPoints: 20,
      type: 'line', // line, bar, pie, donut, gauge
      table: null,
      data: null,
      onClick: null,
      fontUrl: null
    };

    this.readConfigFromDataAttributes();

    this.config = {...this.defaultConfig, ...options, ...this.configFromData};

    this.data = [];
    this.minValue = 0;
    this.maxValue = 0;
    this.currentChartType = this.config.type;
    this.legend = null;
    this.state = {
      initialized: false,
    };

    this.validateOptions(this.config);

    this.calculateFontSize();
    this.setMargins();
    this.visibleDataCount = this.calculateVisibleDataCount();

    if (this.config.table) {
      this.createSVG();
      this.initialize();
    } else if (this.config.data) {
      this.createSVG();
      this.setData(this.config.data);
      this.renderGraph();
    }

    if (this.svg) {
      this.handleResize = this.debounce(this.handleResize.bind(this), 200);
      window.addEventListener('resize', this.handleResize);
    }

    this.state.initialized = true;
  }

  /**
   * Reads configuration options from data-* attributes in the container
   */
  readConfigFromDataAttributes() {
    const dataAttributes = this.container.dataset;
    this.configFromData = {};

    for (const key in dataAttributes) {
      if (dataAttributes.hasOwnProperty(key)) {
        const configKey = this.kebabToCamel(key);

        const value = this.parseConfigValue(dataAttributes[key]);

        this.configFromData[configKey] = value;
      }
    }
  }

  /**
   * Converts a kebab-case string to camelCase
   * @param {string} str - The string in kebab-case
   * @returns {string} The converted camelCase string
   */
  kebabToCamel(str) {
    return str.replace(/-([a-z])/g, (match, letter) => letter.toUpperCase());
  }

  /**
   * Parses a configuration value from a data attribute string
   * @param {string} value - The value to parse
   * @returns {*} The parsed value in the appropriate data type
   */
  parseConfigValue(value) {
    if (value === 'true') return true;
    if (value === 'false') return false;

    if (!isNaN(value)) return Number(value);

    try {
      return JSON.parse(value);
    } catch (e) {
      return value;
    }
  }

  /**
   * Validates the provided configuration object
   * @param {Object} config - The configuration object to validate
   */
  validateOptions(config) {
    if (config.colors && !Array.isArray(config.colors)) {
      throw new TypeError('Option "colors" must be an array.');
    }
    if (typeof config.showGrid !== 'boolean') {
      throw new TypeError('Option "showGrid" must be a boolean.');
    }
    if (typeof config.legendPosition !== 'string') {
      throw new TypeError('Option "legendPosition" must be a string.');
    }
    if (typeof config.maxGaugeValue !== 'number') {
      throw new TypeError('Option "maxGaugeValue" must be a number.');
    }
  }

  /**
   * Creates an SVG element and appends it to the container
   */
  createSVG() {
    if (this.width > 0 && this.height > 0) {
      this.svg = this.createSVGElement('svg', {
        width: '100%',
        height: '100%',
        viewBox: `0 0 ${this.width} ${this.height}`,
        role: 'img',
        'aria-label': 'Data Visualization Graph',
        tabindex: '0'
      });

      this.svg.addEventListener('keydown', this.handleKeyboardNavigation.bind(this));

      const desc = this.createSVGElement('desc');
      desc.textContent = this.getAccessibilityDescription();
      this.svg.appendChild(desc);
      this.container.appendChild(this.svg);
    }
  }

  /**
   * Handles keyboard navigation for the graph
   * @param {KeyboardEvent} event - The keyboard event
   */
  handleKeyboardNavigation(event) {
    switch (event.key) {
      case 'ArrowRight':
        break;
      case 'ArrowLeft':
        break;
    }
  }

  /**
   * Generates a meaningful description for the graph
   * @returns {string} The description of the graph
   */
  getAccessibilityDescription() {
    let description = Now.translate('Graph showing {seriesCount} data series', {
      seriesCount: this?.data?.length || 0,
    });
    if (this.data && this.data.length > 0) {
      description += ' ' + Now.translate('with values ranging from {minValue} to {maxValue}', {
        minValue: this.minValue,
        maxValue: this.maxValue
      });
    }
    return description;
  }

  /**
   * Creates an SVG element with the specified attributes
   * @param {string} type - The type of SVG element to create
   * @param {Object} [attributes={}] - The attributes to set on the SVG element
   * @returns {SVGElement} The created SVG element
   */
  createSVGElement(type, attributes = {}) {
    const elem = document.createElementNS('http://www.w3.org/2000/svg', type);
    Object.keys(attributes).forEach(attr => elem.setAttribute(attr, attributes[attr]));
    return elem;
  }

  /**
   * Clears the existing SVG content and creates a new SVG element
   */
  clear() {
    if (this.svg && this.container.contains(this.svg)) {
      this.container.removeChild(this.svg);
    }
    this.createSVG();
  }

  /**
   * Creates a debounced version of the provided function
   * @param {Function} func - The function to debounce
   * @param {number} wait - The debounce interval in milliseconds
   * @returns {Function} The debounced function
   */
  debounce(func, wait) {
    let timeout;
    return (...args) => {
      clearTimeout(timeout);
      timeout = setTimeout(() => func.apply(this, args), wait);
    };
  }

  /**
   * Calculates and sets the font size based on the container dimensions
   */
  calculateFontSize() {
    if (this.config?.fontSize) {
      if (this.config?.type === 'gauge') {
        this.config.labelFontSize = this.config.fontSize * 0.5;
      } else {
        const minDimension = Math.min(this.width, this.height);
        if (minDimension > 0) {
          this.config.fontSize = Math.max(10, Math.min(this.config.fontSize, minDimension / 20));
          this.config.labelFontSize = this.config.fontSize * 0.8;
        }
      }
    }
  }

  /**
   * Calculates the number of visible data points based on the configuration
   * @returns {number} The number of visible data points
   */
  calculateVisibleDataCount() {
    if (!this.data || this.data.length === 0 || !this.data[0].data) {
      return 0;
    }
    if (this.config.maxDataPoints === 0) {
      return this.data[0].data.length;
    }
    return Math.min(this.config.maxDataPoints, this.data[0].data.length);
  }

  /**
   * Handles window resize events by updating dimensions and redrawing the graph
   */
  handleResize() {
    const newWidth = this.container.clientWidth;
    const newHeight = this.container.clientHeight;

    // Only redraw if container has actual dimensions
    if (newWidth === 0 || newHeight === 0) {
      if (!this._waitingForSize) {
        this._waitingForSize = true;
        setTimeout(() => {
          this._waitingForSize = false;
          this.handleResize();
        }, 100);
      }
      return;
    }

    this.width = newWidth;
    this.height = newHeight;

    if (this.svg && this.width > 0 && this.height > 0) {
      this.svg.setAttribute('viewBox', `0 0 ${this.width} ${this.height}`);
      this.calculateFontSize();
      this.setMargins();
      this.visibleDataCount = this.calculateVisibleDataCount();
      this.redrawGraph();
    }
  }

  /**
   * Loads and processes data from an HTML table
   * @param {HTMLTableElement} table - The table element to load data from
   * @returns {Array} The processed series data
   */
  loadAndProcessTableData(table) {
    const tableData = this.loadFromTable(table);
    return this.processTableData(tableData);
  }

  /**
   * Loads raw data from an HTML table
   * @param {HTMLTableElement} table - The table element to load data from
   * @returns {Object} The raw table data
   */
  loadFromTable(table) {
    const rawData = {
      headers: {
        title: '',
        items: []
      },
      rows: []
    };

    const headerCells = table.querySelectorAll('thead > tr:first-child > th');
    headerCells.forEach((cell, index) => {
      if (index === 0) {
        rawData.headers.title = cell.textContent.trim();
      } else {
        rawData.headers.items.push(cell.innerHTML);
      }
    });

    const bodyRows = table.querySelectorAll('tbody > tr');
    bodyRows.forEach(tr => {
      const row = {
        title: '',
        items: []
      };
      const cells = tr.querySelectorAll('th, td');
      cells.forEach((cell, index) => {
        if (cell.tagName === 'TH') {
          row.title = cell.textContent.trim();
        } else {
          const value = parseFloat(cell.textContent.replace(/,/g, ''));
          row.items.push(value);
        }
      });
      rawData.rows.push(row);
    });

    return rawData;
  }

  /**
   * Processes raw table data into a series data format suitable for the graph
   * @param {Object} rawData - The raw table data
   * @returns {Array} The processed series data
   */
  processTableData(rawData) {
    const processedData = [];

    rawData.rows.forEach((row, rowIndex) => {
      const series = {
        name: row.title,
        data: []
      };

      rawData.headers.items.forEach((header, colIndex) => {
        const value = row.items[colIndex];
        series.data.push({
          label: header,
          value: value,
          tooltip: `${row.title} ${header}: ${this.formatValue(value)}`
        });
      });

      processedData.push(series);
    });

    return processedData;
  }

  /**
   * Sets the data for the graph and updates the range
   * @param {Array} data - The data to set
   */
  setData(data) {
    try {
      this.validateData(data);
      if (!Array.isArray(data)) {
        throw new Error('Data must be an array of series.');
      }
      this.data = data;
      const allValues = data.flatMap(series => series.data.map(point => point.value));
      this.minValue = Math.min(...allValues);
      this.maxValue = Math.max(...allValues);
      this.calculateNiceRange();
      this.visibleDataCount = this.calculateVisibleDataCount();
      this.redrawGraph();
    } catch (error) {
      console.error('Error setting data:', error);
      EventManager.emit('graph:error', {
        type: 'data',
        error
      });
      throw error;
    }
  }

  /**
   * Validates the structure of input data
   * @param {Object} data - Data to validate
   * @returns {boolean} True if data structure is valid
   */
  validateData(data) {
    if (!Array.isArray(data)) {
      throw new Error('Data must be an array of series');
    }

    data.forEach((series, index) => {
      if (!series.name || !Array.isArray(series.data)) {
        throw new Error(`Invalid series at index ${index}`);
      }

      series.data.forEach((point, pointIndex) => {
        if (!point.hasOwnProperty('value') || !point.hasOwnProperty('label')) {
          throw new Error(`Invalid data point in series "${series.name}" at index ${pointIndex}`);
        }
      });
    });

    return true;
  }

  /**
   * Calculates a "nice" range for the y-axis based on the data
   */
  calculateNiceRange() {
    const range = this.maxValue - this.minValue;
    if (range === 0) {
      this.minNice = this.minValue - 1;
      this.maxNice = this.maxValue + 1;
      return;
    }
    const roughStep = range / 5;
    const magnitude = Math.pow(10, Math.floor(Math.log10(roughStep)));
    const niceStep = Math.ceil(roughStep / magnitude) * magnitude;

    this.minNice = Math.floor(this.minValue / niceStep) * niceStep;
    this.maxNice = Math.ceil(this.maxValue / niceStep) * niceStep;

    if (this.minValue > 0) {
      if (this.minValue === this.minNice) {
        this.minNice = Math.max(0, this.minNice - niceStep);
      }
      if (this.maxValue === this.maxNice) {
        this.maxNice += niceStep;
      }
    }

    if (this.maxValue < 0) {
      if (this.maxValue === this.maxNice) {
        this.maxNice = Math.min(0, this.maxNice + niceStep);
      }
      if (this.minValue === this.minNice) {
        this.minNice -= niceStep;
      }
    }
  }

  /**
   * Draws the axes on the graph
   */
  drawAxes() {
    const axesGroup = this.createSVGElement('g', {class: 'axes'});

    let yBase = 0;
    if (this.minNice > 0) {
      yBase = this.minNice;
    } else if (this.maxNice < 0) {
      yBase = this.maxNice;
    }

    const xAxis = this.createSVGElement('line', {
      x1: this.margin.left,
      y1: this.getPointY(yBase),
      x2: this.width - this.margin.right,
      y2: this.getPointY(yBase),
      stroke: this.config.axisColor,
      'stroke-width': '2'
    });
    axesGroup.appendChild(xAxis);

    const yAxis = this.createSVGElement('line', {
      x1: this.margin.left,
      y1: this.margin.top,
      x2: this.margin.left,
      y2: this.height - this.margin.bottom,
      stroke: this.config.axisColor,
      'stroke-width': '2'
    });
    axesGroup.appendChild(yAxis);

    if (this.config.showAxisLabels) {
      this.drawYAxisLabels(axesGroup);
    }

    this.svg.appendChild(axesGroup);
  }

  /**
   * Draws the Y-axis labels
   * @param {SVGElement} axesGroup - The group element to append the labels to
   */
  drawYAxisLabels(axesGroup) {
    const steps = 5;
    for (let i = 0; i <= steps; i++) {
      const value = this.minNice + (i / steps) * (this.maxNice - this.minNice);
      const y = this.getPointY(value);

      const label = this.createSVGElement('text', {
        x: this.margin.left - 10,
        y: y,
        'text-anchor': 'end',
        'alignment-baseline': 'middle',
        'font-size': this.config.labelFontSize,
        'font-family': this.config.fontFamily,
        fill: this.config.textColor
      });

      label.textContent = this.formatValue(value);
      axesGroup.appendChild(label);
    }
  }

  /**
   * Draws vertical grid lines at the specified x positions
   * @param {Array<number>} xPositions - The x positions for the grid lines
   */
  drawVerticalGridLines(xPositions) {
    const gridGroup = this.createSVGElement('g', {class: 'vertical-grid'});

    xPositions.forEach(x => {
      const line = this.createSVGElement('line', {
        x1: x,
        y1: this.margin.top,
        x2: x,
        y2: this.height - this.margin.bottom,
        stroke: this.config.gridColor,
        'stroke-dasharray': '5,5'
      });
      gridGroup.appendChild(line);
    });

    this.svg.appendChild(gridGroup);
  }

  /**
   * Draws a horizontal grid line at the specified y position
   * @param {number} y - The y position for the grid line
   */
  drawHorizontalGridLines(y) {
    const gridLine = this.createSVGElement('line', {
      x1: this.margin.left,
      y1: y,
      x2: this.width - this.margin.right,
      y2: y,
      stroke: this.config.gridColor,
      'stroke-width': '1',
      'stroke-dasharray': '5,5'
    });
    this.svg.appendChild(gridLine);
  }

  /**
   * Draws a label at the specified position with optional rotation
   * @param {number} x - The x position of the label
   * @param {number} y - The y position of the label
   * @param {string} text - The text content of the label
   * @param {boolean} rotate - Whether to rotate the label by 45 degrees
   */
  drawLabel(x, y, text, rotate) {
    const label = this.createSVGElement('text', {
      x: x,
      y: y,
      'text-anchor': 'middle',
      'font-size': this.config.labelFontSize,
      'font-family': this.config.fontFamily,
      fill: this.config.textColor
    });
    label.textContent = text;
    if (rotate) {
      label.setAttribute('transform', `rotate(45, ${x}, ${y})`);
    }
    this.svg.appendChild(label);
  }

  /**
   * Adds an animation to an SVG element
   * @param {SVGElement} element - The SVG element to animate
   * @param {Object} attributes - The attributes to animate
   */
  addAnimation(element, attributes) {
    if (this.config.animation) {
      const animate = this.createSVGElement('animate');
      for (const [key, value] of Object.entries(attributes)) {
        animate.setAttribute(key, value);
      }
      element.appendChild(animate);
    }
  }

  /**
   * Renders the graph based on the current chart type
   * @param {boolean} [animation=this.config.animation] - Whether to animate the rendering
   */
  renderGraph(animation = this.config.animation) {
    const svg = this.ensureSVG();
    if (!svg) {
      // SVG isn't ready yet, the retry is scheduled in ensureSVG()
      return;
    }

    try {
      // Update dimensions based on current container size
      this.width = this.container.clientWidth;
      this.height = this.container.clientHeight;

      // Don't render if dimensions are too small
      if (this.width < 50 || this.height < 50) {
        setTimeout(() => this.redrawGraph(), 100);
        return;
      }

      // Clear existing content
      while (svg.firstChild) {
        svg.removeChild(svg.firstChild);
      }

      const previousAnimation = this.config.animation;
      this.config.animation = animation;
      this.clear();
      this.setMargins();
      switch (this.currentChartType) {
        case 'line':
          this.drawLineGraph();
          break;
        case 'bar':
          this.drawBarGraph();
          break;
        case 'pie':
          this.drawPieChart(false);
          break;
        case 'donut':
          this.drawPieChart(true);
          break;
        case 'gauge':
          this.drawGauge();
          break;
        default:
          throw new Error(`Unknown chart type: ${this.currentChartType}`);
      }
      this.drawLegend();
      this.config.animation = previousAnimation;
    } catch (error) {
      console.error('Error in renderGraph():', error);
    }
  }

  /**
   * Redraws the graph without animation
   * @param {boolean} [animation=this.config.animation] - Whether to animate the redrawing
   */
  redrawGraph(animation = this.config.animation) {
    this.renderGraph(animation);
  }

  /**
   * Calculates the x-coordinate for a data point based on its index
   * @param {number} index - The index of the data point
   * @returns {number} The x-coordinate
   */
  getPointX(index) {
    if (this.visibleDataCount <= 1) {
      return this.margin.left + (this.width - this.margin.left - this.margin.right) / 2;
    }
    const availableWidth = this.width - this.margin.left - this.margin.right;
    return this.margin.left + (index / (this.visibleDataCount - 1)) * availableWidth;
  }

  /**
   * Calculates the y-coordinate for a data value
   * @param {number} value - The data value
   * @returns {number} The y-coordinate
   */
  getPointY(value) {
    const availableHeight = this.height - this.margin.top - this.margin.bottom;
    if (this.maxNice === this.minNice) {
      return this.margin.top + availableHeight / 2;
    }
    return this.margin.top + ((this.maxNice - value) / (this.maxNice - this.minNice)) * availableHeight;
  }

  /**
   * Generates a linear path string for a series of data points
   * @param {Array<Object>} data - The data points
   * @returns {string} The path string
   */
  getLinearPath(data) {
    return data.map((point, index) =>
      `${index === 0 ? 'M' : 'L'}${this.getPointX(index)},${this.getPointY(point.value)}`
    ).join(' ');
  }

  /**
   * Generates a curved path string for a series of data points
   * @param {Array<Object>} data - The data points
   * @returns {string} The curved path string
   */
  getCurvePath(data) {
    if (data.length === 0) return '';
    let path = `M${this.getPointX(0)},${this.getPointY(data[0].value)}`;

    for (let i = 1; i < data.length; i++) {
      const x1 = this.getPointX(i - 1);
      const y1 = this.getPointY(data[i - 1].value);
      const x2 = this.getPointX(i);
      const y2 = this.getPointY(data[i].value);

      const controlX1 = x1 + (x2 - x1) / 3;
      const controlX2 = x2 - (x2 - x1) / 3;

      path += ` C${controlX1},${y1} ${controlX2},${y2} ${x2},${y2}`;
    }

    return path;
  }

  /**
   * Describes an arc path
   * @param {number} x - The x-coordinate of the center
   * @param {number} y - The y-coordinate of the center
   * @param {number} radius - The radius of the arc
   * @param {number} startAngle - The start angle in radians
   * @param {number} endAngle - The end angle in radians
   * @returns {string} The SVG path data for the arc
   */
  describeArc(x, y, radius, startAngle, endAngle) {
    const start = this.polarToCartesian(x, y, radius, endAngle);
    const end = this.polarToCartesian(x, y, radius, startAngle);
    const largeArcFlag = endAngle - startAngle <= Math.PI ? "0" : "1";
    return [
      "M", start.x, start.y,
      "A", radius, radius, 0, largeArcFlag, 0, end.x, end.y
    ].join(" ");
  }

  /**
   * Converts polar coordinates to Cartesian coordinates
   * @param {number} centerX - The x-coordinate of the center
   * @param {number} centerY - The y-coordinate of the center
   * @param {number} radius - The radius
   * @param {number} angleInRadians - The angle in radians
   * @returns {Object} The Cartesian coordinates
   */
  polarToCartesian(centerX, centerY, radius, angleInRadians) {
    return {
      x: centerX + (radius * Math.cos(angleInRadians)),
      y: centerY + (radius * Math.sin(angleInRadians))
    };
  }

  /**
   * Adds a single data point to a specific series
   * @param {Object} newData - The new data point to add
   * @param {number} [seriesIndex=0] - The index of the series to add the data point to
   */
  addDataPoint(newData, seriesIndex = 0) {
    if (seriesIndex >= this.data.length) {
      console.error('Series index out of range.');
      return;
    }

    this.data[seriesIndex].data.push(newData);
    if (this.config.maxDataPoints !== 0 && this.data[seriesIndex].data.length > this.config.maxDataPoints) {
      const removed = this.data[seriesIndex].data.shift();
      if (removed.value === this.minValue || removed.value === this.maxValue) {
        const allValues = this.data.flatMap(series => series.data.map(point => point.value));
        this.minValue = Math.min(...allValues);
        this.maxValue = Math.max(...allValues);
      }
    } else {
      this.minValue = Math.min(this.minValue, newData.value);
      this.maxValue = Math.max(this.maxValue, newData.value);
    }

    this.calculateNiceRange();
    this.visibleDataCount = this.calculateVisibleDataCount();
    this.redrawGraph();
  }

  /**
   * Draws a line graph based on the current data
   */
  drawLineGraph() {
    const visibleDataCount = this.visibleDataCount;
    if (visibleDataCount === 0) {
      return;
    }

    const seriesCount = this.data.length;
    const margin = this.margin;
    const availableWidth = this.width - margin.left - margin.right;

    if (this.config.showGrid) {
      const steps = 5;
      for (let i = 0; i <= steps; i++) {
        const y = this.getPointY(this.minNice + (i / steps) * (this.maxNice - this.minNice));
        this.drawHorizontalGridLines(y);
      }

      const xPositionsSet = new Set();
      for (let i = 0; i < visibleDataCount; i++) {
        const x = this.getPointX(i);
        xPositionsSet.add(x);
      }

      const xPositions = Array.from(xPositionsSet);
      this.drawVerticalGridLines(xPositions);
    }

    if (this.config.showAxisLabels && seriesCount > 0) {
      const labels = this.data[0].data.slice(0, this.visibleDataCount).map(point => this.stripTags(point.label));
      const labelText = labels.join(' ');
      const estimatedWidth = this.estimateTextWidth(labelText);
      const totalLabelWidth = estimatedWidth + (visibleDataCount * 10);
      const rotate = availableWidth < totalLabelWidth;

      labels.forEach((label, i) => {
        const x = this.getPointX(i);
        this.drawLabel(x, this.height - this.margin.bottom + 20, label, rotate);
      });
    }

    if (this.config.showAxis) {
      this.drawAxes();
    }

    const lineGroup = this.createSVGElement('g', {class: 'lines'});

    const clipPathId = `clipPath-${Date.now()}`;
    const clipPath = this.createSVGElement('clipPath', {id: clipPathId});

    if (this.config.animation) {
      const clipRect = this.createSVGElement('rect', {
        x: margin.left,
        y: margin.top,
        width: '0',
        height: this.height - margin.top - margin.bottom
      });

      const animateClip = this.createSVGElement('animate', {
        attributeName: 'width',
        from: '0',
        to: availableWidth,
        dur: `${this.config.animationDuration}ms`,
        fill: 'freeze'
      });
      clipRect.appendChild(animateClip);
      clipPath.appendChild(clipRect);
      this.svg.appendChild(clipPath);
    }

    this.data.forEach((series, seriesIndex) => {
      const color = series.color || this.config.colors[seriesIndex % this.config.colors.length];
      const linePath = this.createSVGElement('path', {
        d: this.config.curve
          ? this.getCurvePath(series.data.slice(0, this.visibleDataCount))
          : this.getLinearPath(series.data.slice(0, this.visibleDataCount)),
        stroke: color,
        fill: 'none',
        'stroke-width': this.config.lineWidth
      });

      if (this.config.fillArea) {
        const fillPath = this.createSVGElement('path', {fill: color, 'fill-opacity': this.config.fillOpacity, 'clip-path': `url(#${clipPathId})`});
        const fillY = this.minNice >= 0
          ? this.getPointY(this.minNice)
          : this.maxNice <= 0
            ? this.getPointY(this.maxNice)
            : this.getPointY(0);

        const finalD = `${linePath.getAttribute('d')} L${this.getPointX(this.visibleDataCount - 1)},${fillY} L${this.getPointX(0)},${fillY} Z`;
        let initialD = '';

        if (this.config.animation) {
          linePath.setAttribute('clip-path', `url(#${clipPathId})`);
          initialD = series.data.slice(0, this.visibleDataCount).map((point, index) =>
            `${index === 0 ? 'M' : 'L'}${this.getPointX(index)},${fillY}`
          ).join(' ') + ' Z';
          fillPath.setAttribute('d', initialD);
          const animateFill = this.createSVGElement('animate', {
            attributeName: 'd',
            from: initialD,
            to: finalD,
            dur: `${this.config.animationDuration}ms`,
            fill: 'freeze'
          });
          fillPath.appendChild(animateFill);
        } else {
          fillPath.setAttribute('d', finalD);
        }

        lineGroup.appendChild(fillPath);
      }

      if (this.config.animation) {
        const length = linePath.getTotalLength();
        linePath.setAttribute('stroke-dasharray', length);
        linePath.setAttribute('stroke-dashoffset', length);

        const animate = this.createSVGElement('animate', {
          attributeName: 'stroke-dashoffset',
          from: length,
          to: '0',
          dur: `${this.config.animationDuration}ms`,
          fill: 'freeze'
        });
        linePath.appendChild(animate);
      }

      if (typeof this.config.onClick === 'function') {
        linePath.style.cursor = 'pointer';
        linePath.addEventListener('click', () => {
          this.config.onClick({
            type: 'line',
            series: series,
            data: series.data.slice(0, this.visibleDataCount)
          });
        });
      }

      lineGroup.appendChild(linePath);
    });

    this.svg.appendChild(lineGroup);

    const pointsGroup = this.createSVGElement('g', {class: 'points'});

    this.data.forEach((series, seriesIndex) => {
      const color = series.color || this.config.colors[seriesIndex % this.config.colors.length];
      series.data.slice(0, this.visibleDataCount).forEach((point, index) => {
        const x = this.getPointX(index);
        const y = this.getPointY(point.value);

        if (this.config.showDataLabels) {
          const verticalLineHeight = -15;
          const horizontalLineLength = 5;

          const verticalLineXEnd = x + horizontalLineLength;
          const verticalLineYEnd = y + verticalLineHeight;

          const lineVertical = this.createSVGElement('line', {
            x1: x,
            y1: y,
            x2: this.config.animation ? x : verticalLineXEnd,
            y2: this.config.animation ? y : verticalLineYEnd,
            stroke: color,
            'stroke-width': '1'
          });

          if (this.config.animation) {
            const animateVerticalLine = this.createSVGElement('animate', {
              attributeName: 'y2',
              from: y,
              to: verticalLineYEnd,
              dur: '0.5s',
              fill: 'freeze'
            });
            lineVertical.appendChild(animateVerticalLine);
            const animateHorizontalLine = this.createSVGElement('animate', {
              attributeName: 'x2',
              from: x,
              to: verticalLineXEnd,
              dur: '0.5s',
              fill: 'freeze'
            });
            lineVertical.appendChild(animateHorizontalLine);
          }

          pointsGroup.appendChild(lineVertical);

          const horizontalLineXEnd = verticalLineXEnd + horizontalLineLength;
          const lineHorizontal = this.createSVGElement('line', {
            x1: verticalLineXEnd,
            y1: verticalLineYEnd,
            y2: verticalLineYEnd,
            stroke: color,
            'stroke-width': '1'
          });

          if (this.config.animation) {
            lineHorizontal.setAttribute('x2', verticalLineXEnd);
            const animateHorizontalLine = this.createSVGElement('animate', {
              attributeName: 'x2',
              from: verticalLineXEnd,
              to: horizontalLineXEnd,
              dur: '0.5s',
              begin: '0.5s',
              fill: 'freeze'
            });
            lineHorizontal.appendChild(animateHorizontalLine);
          } else {
            lineHorizontal.setAttribute('x2', horizontalLineXEnd);
          }

          pointsGroup.appendChild(lineHorizontal);

          const label = this.createSVGElement('text', {
            x: horizontalLineXEnd,
            y: verticalLineYEnd,
            'text-anchor': horizontalLineXEnd > x ? 'start' : 'end',
            'alignment-baseline': 'middle',
            'font-size': this.config.labelFontSize,
            'font-family': this.config.fontFamily,
            fill: color
          });
          label.textContent = this.getLabelContent(series, point);

          if (this.config.animation) {
            label.setAttribute('opacity', '0');

            const animateOpacity = this.createSVGElement('animate', {
              attributeName: 'opacity',
              from: '0',
              to: '1',
              dur: '0.5s',
              begin: '0.5s',
              fill: 'freeze'
            });
            label.appendChild(animateOpacity);

            const animatePosition = this.createSVGElement('animateTransform', {
              attributeName: 'transform',
              type: 'translate',
              from: `0,0`,
              to: `${horizontalLineLength},0`,
              dur: '0.5s',
              begin: '0.5s',
              fill: 'freeze'
            });
            label.appendChild(animatePosition);
          }

          pointsGroup.appendChild(label);
        }

        const circle = this.createSVGElement('circle', {
          cx: x,
          cy: y,
          r: this.config.animation ? '0' : this.config.pointRadius,
          fill: this.config.backgroundColor,
          stroke: color,
          'stroke-width': 2
        });

        if (this.config.showTooltip) {
          const title = this.createSVGElement('title');
          title.textContent = this.getTooltipContent(series, point);
          circle.appendChild(title);
          circle.setAttribute('cursor', 'pointer');
        }

        if (typeof this.config.onClick === 'function') {
          circle.style.cursor = 'pointer';
          circle.addEventListener('click', () => {
            this.config.onClick({
              type: 'point',
              series: series,
              data: point
            });
          });
        }

        if (this.config.animation) {
          const animateRadius = this.createSVGElement('animate', {
            attributeName: 'r',
            from: '0',
            to: this.config.pointRadius,
            dur: `${this.config.animationDuration}ms`,
            fill: 'freeze'
          });
          circle.appendChild(animateRadius);

          const animateOpacity = this.createSVGElement('animate', {
            attributeName: 'opacity',
            from: '0',
            to: '1',
            dur: `${this.config.animationDuration}ms`,
            fill: 'freeze'
          });
          circle.appendChild(animateOpacity);
        }

        pointsGroup.appendChild(circle);
      });
    });

    this.svg.appendChild(pointsGroup);

    if (this.config.showCenterText) {
      const centerText = this.createSVGElement('text', {
        x: this.width / 2,
        y: this.height / 2,
        'text-anchor': 'middle',
        'font-size': this.config.fontSize,
        'font-family': this.config.fontFamily,
        fill: this.config.textColor,
        'font-weight': 'bold'
      });
      centerText.textContent = this.config.centerText || '';
      this.svg.appendChild(centerText);
    }
  }

  /**
   * Draws a bar graph based on the current data with shadow effects on top and sides
   */
  drawBarGraph() {
    const visibleDataCount = this.visibleDataCount;
    if (visibleDataCount === 0) {
      return;
    }

    const seriesCount = this.data.length;
    const margin = this.margin;
    const availableWidth = this.width - margin.left - margin.right;
    const groupWidth = availableWidth / visibleDataCount;
    const barWidth = groupWidth / (seriesCount + 1);
    const barGap = (groupWidth - seriesCount * barWidth) / (seriesCount + 1);
    const zeroY = this.getPointY(0);

    const defs = this.createSVGElement('defs');
    const filter = this.createSVGElement('filter', {
      id: 'barShadow',
      x: '-20%',
      y: '-20%',
      width: '140%',
      height: '140%'
    });
    const feOffset = this.createSVGElement('feOffset', {
      dx: '2',
      dy: '-2',
      result: 'offsetBlur'
    });
    const feGaussianBlur = this.createSVGElement('feGaussianBlur', {
      in: 'offsetBlur',
      stdDeviation: '2',
      result: 'blur'
    });
    const feFlood = this.createSVGElement('feFlood', {
      'flood-color': '#000000',
      'flood-opacity': '0.3',
      result: 'color'
    });
    const feComposite = this.createSVGElement('feComposite', {
      in: 'color',
      in2: 'blur',
      operator: 'in',
      result: 'shadow'
    });
    const feMerge = this.createSVGElement('feMerge');
    const feMergeNode1 = this.createSVGElement('feMergeNode', {in: 'shadow'});
    const feMergeNode2 = this.createSVGElement('feMergeNode', {in: 'SourceGraphic'});

    filter.appendChild(feOffset);
    filter.appendChild(feGaussianBlur);
    filter.appendChild(feFlood);
    filter.appendChild(feComposite);
    filter.appendChild(feMerge);
    feMerge.appendChild(feMergeNode1);
    feMerge.appendChild(feMergeNode2);
    defs.appendChild(filter);
    this.svg.appendChild(defs);

    if (this.config.showGrid) {
      const steps = 5;
      for (let i = 0; i <= steps; i++) {
        const y = this.getPointY(this.minNice + (i / steps) * (this.maxNice - this.minNice));
        this.drawHorizontalGridLines(y);
      }
    }

    const xPositions = [];
    for (let i = 0; i < visibleDataCount; i++) {
      const x = margin.left + i * groupWidth;
      xPositions.push(x);
    }

    if (this.config.showGrid) {
      this.drawVerticalGridLines(xPositions.map(x => x + groupWidth));
    }

    if (this.config.showAxisLabels && this.data.length > 0) {
      const labels = this.data[0].data.slice(0, this.visibleDataCount).map(point => this.stripTags(point.label));
      const labelText = labels.join(' ');
      const estimatedWidth = this.estimateTextWidth(labelText);
      const totalLabelWidth = estimatedWidth + (visibleDataCount * 10);
      const rotate = availableWidth < totalLabelWidth;

      labels.forEach((label, i) => {
        const x = xPositions[i] + groupWidth / 2;
        this.drawLabel(x, this.height - margin.bottom + 20, label, rotate);
      });
    }

    this.data.forEach((series, seriesIndex) => {
      series.data.slice(0, visibleDataCount).forEach((point, index) => {
        const x = xPositions[index] + barGap + seriesIndex * (barWidth + barGap);
        let y, height;
        const yValue = this.getPointY(point.value);
        if (this.minNice >= 0 && this.maxNice >= 0) {
          y = yValue;
          height = this.getPointY(this.minNice) - yValue;
        } else if (this.minNice <= 0 && this.maxNice <= 0) {
          y = this.getPointY(this.maxNice);
          height = yValue - y;
        } else {
          y = Math.min(zeroY, yValue);
          height = Math.abs(zeroY - yValue);
        }

        const color = point.color || series.color || this.config.colors[seriesIndex % this.config.colors.length];

        const bar = this.createSVGElement('rect', {
          x: x,
          width: barWidth,
          height: this.config.animation ? '0' : height,
          fill: color,
          filter: 'url(#barShadow)'
        });

        if (this.config.borderColor) {
          bar.setAttribute('stroke', this.config.borderColor === 'auto' ? this.darkenColor(color) : this.config.borderColor);
          bar.setAttribute('stroke-width', this.config.borderWidth);
        }

        if (this.config.showTooltip) {
          const title = this.createSVGElement('title');
          title.textContent = this.getTooltipContent(series, point);
          bar.appendChild(title);
          bar.setAttribute('cursor', 'pointer');
        }

        if (typeof this.config.onClick === 'function') {
          bar.style.cursor = 'pointer';
          bar.addEventListener('click', () => {
            this.config.onClick({
              type: 'bar',
              series: series,
              data: point
            });
          });
        }

        let yForm;
        if (this.minNice >= 0 && this.maxNice >= 0) {
          yForm = this.getPointY(this.minNice);
        } else if (this.minNice < 0 && this.maxNice < 0) {
          yForm = this.getPointY(this.maxNice);
        } else {
          yForm = this.getPointY(0);
        }

        if (this.config.animation) {
          bar.setAttribute('y', y);
          const animHeight = this.createSVGElement('animate', {
            attributeName: 'height',
            from: '0',
            to: height,
            dur: `${this.config.animationDuration}ms`,
            fill: 'freeze'
          });
          bar.appendChild(animHeight);

          const animY = this.createSVGElement('animate', {
            attributeName: 'y',
            from: yForm,
            to: y,
            dur: `${this.config.animationDuration}ms`,
            fill: 'freeze'
          });
          bar.appendChild(animY);
        } else {
          bar.setAttribute('y', y);
          bar.setAttribute('height', height);
        }

        this.svg.appendChild(bar);

        if (this.config.showDataLabels) {
          const label = this.createSVGElement('text', {
            x: x + barWidth / 2,
            'text-anchor': 'middle',
            'font-size': this.config.labelFontSize,
            'font-family': this.config.fontFamily,
            fill: color
          });
          label.textContent = this.getLabelContent(series, point);

          if (point.value >= 0) {
            label.setAttribute('y', y - 5);
            if (this.config.animation) {
              const animLabelY = this.createSVGElement('animate', {
                attributeName: 'y',
                from: yForm - 5,
                to: y - 5,
                dur: `${this.config.animationDuration}ms`,
                fill: 'freeze'
              });
              label.appendChild(animLabelY);
            }
          } else {
            label.setAttribute('y', y + height + 15);
            if (this.config.animation) {
              const animLabelY = this.createSVGElement('animate', {
                attributeName: 'y',
                from: yForm + 15,
                to: y + height + 15,
                dur: `${this.config.animationDuration}ms`,
                fill: 'freeze'
              });
              label.appendChild(animLabelY);
            }
          }

          this.svg.appendChild(label);
        }
      });
    });

    if (this.config.showAxis) {
      this.drawAxes();
    }
  }

  /**
   * Draws a pie or donut chart based on the current data
   * @param {boolean} isDonut - Whether to draw a donut chart instead of a pie chart
   */
  drawPieChart(isDonut = false) {
    if (!this.data || !this.data.length) return;

    const radius = Math.min(
      this.width - this.margin.left - this.margin.right,
      this.height - this.margin.top - this.margin.bottom
    ) / 2.0;
    const centerX = this.margin.left + (this.width - this.margin.left - this.margin.right) / 2;
    const centerY = this.margin.top + (this.height - this.margin.top - this.margin.bottom) / 2;

    let startAngle = -Math.PI / 2;

    const total = this.data.reduce((sum, series) => sum + series.data.reduce((s, p) => s + p.value, 0), 0);
    if (total === 0) {
      console.warn('Total value of pie chart is 0. Cannot draw pie chart.');
      return;
    }
    const pieGroup = this.createSVGElement('g', {class: 'pie-group'});

    this.data.forEach((series, seriesIndex) => {
      series.data.forEach((point, index) => {
        const sliceAngle = (point.value / total) * 2 * Math.PI;
        const endAngle = startAngle + sliceAngle;
        const midAngle = startAngle + sliceAngle / 2;
        const color = point.color || series.color || this.config.colors[index % this.config.colors.length];

        const x1 = centerX + radius * Math.cos(startAngle);
        const y1 = centerY + radius * Math.sin(startAngle);
        const x2 = centerX + radius * Math.cos(endAngle);
        const y2 = centerY + radius * Math.sin(endAngle);

        const largeArcFlag = sliceAngle > Math.PI ? "1" : "0";

        const pathData = [
          `M ${centerX} ${centerY}`,
          `L ${x1} ${y1}`,
          `A ${radius} ${radius} 0 ${largeArcFlag} 1 ${x2} ${y2}`,
          'Z'
        ].join(' ');

        const borderColor = this.config.borderColor === 'auto' ? this.darkenColor(color) : this.config.borderColor;

        const slice = this.createSVGElement('path', {
          d: pathData,
          fill: color,
          stroke: this.config.gap > 0 ? this.config.backgroundColor : (this.config.borderWidth > 0 ? (borderColor || '#000') : null),
          'stroke-width': this.config.gap > 0 || this.config.borderWidth > 0 ? this.config.gap > 0 ? this.config.gap : this.config.borderWidth : null
        });

        if (this.config.showTooltip) {
          const title = this.createSVGElement('title');
          title.textContent = this.getTooltipContent(series, point);
          slice.appendChild(title);
          slice.setAttribute('cursor', 'pointer');
        }

        if (typeof this.config.onClick === 'function') {
          slice.style.cursor = 'pointer';
          slice.addEventListener('click', () => {
            this.config.onClick({
              type: 'pie',
              series: series,
              data: point
            });
          });
        }

        if (this.config.animation) {
          const pathLength = slice.getTotalLength();
          slice.setAttribute('stroke-dasharray', pathLength);
          slice.setAttribute('stroke-dashoffset', pathLength);

          const animateSlice = this.createSVGElement('animate', {
            attributeName: 'stroke-dashoffset',
            from: pathLength,
            to: '0',
            dur: `${this.config.animationDuration}ms`,
            fill: 'freeze'
          });
          slice.appendChild(animateSlice);
        }

        pieGroup.appendChild(slice);

        if (this.config.showDataLabels) {
          const labelRadius = radius * 1.2;
          const labelX = centerX + labelRadius * Math.cos(midAngle);
          const labelY = centerY + labelRadius * Math.sin(midAngle);

          const x = centerX + radius * Math.cos(midAngle);
          const y = centerY + radius * Math.sin(midAngle);

          const lineVertical = this.createSVGElement('line', {
            x1: x,
            y1: y,
            x2: this.config.animation ? x : labelX,
            y2: this.config.animation ? y : labelY,
            stroke: color,
            'stroke-width': '1'
          });

          if (this.config.animation) {
            const animateVerticalLineX = this.createSVGElement('animate', {
              attributeName: 'x2',
              from: x,
              to: labelX,
              dur: '0.5s',
              fill: 'freeze'
            });
            lineVertical.appendChild(animateVerticalLineX);

            const animateVerticalLine = this.createSVGElement('animate', {
              attributeName: 'y2',
              from: y,
              to: labelY,
              dur: '0.5s',
              fill: 'freeze'
            });
            lineVertical.appendChild(animateVerticalLine);
          }
          pieGroup.appendChild(lineVertical);

          const horizontalLineLength = 5;
          const horizontalLineXEnd = labelX > centerX ? labelX + horizontalLineLength : labelX - horizontalLineLength;

          const lineHorizontal = this.createSVGElement('line', {
            x1: labelX,
            y1: labelY,
            y2: labelY,
            stroke: color,
            'stroke-width': '1'
          });

          if (this.config.animation) {
            lineHorizontal.setAttribute('x2', labelX);
            const animateHorizontalLine = this.createSVGElement('animate', {
              attributeName: 'x2',
              from: labelX,
              to: horizontalLineXEnd,
              dur: '0.5s',
              begin: `${this.config.animationDuration / 2}ms`,
              fill: 'freeze'
            });
            lineHorizontal.appendChild(animateHorizontalLine);
          } else {
            lineHorizontal.setAttribute('x2', horizontalLineXEnd);
          }
          pieGroup.appendChild(lineHorizontal);

          const label = this.createSVGElement('text', {
            x: horizontalLineXEnd + (labelX > centerX ? 5 : -5),
            y: labelY,
            'text-anchor': labelX > centerX ? 'start' : 'end',
            'alignment-baseline': 'middle',
            'font-size': this.config.labelFontSize,
            fill: color
          });
          label.textContent = this.getLabelContent(series, point);

          if (this.config.animation) {
            label.setAttribute('opacity', '0');

            const animateOpacity = this.createSVGElement('animate', {
              attributeName: 'opacity',
              from: '0',
              to: '1',
              dur: '0.5s',
              begin: `${this.config.animationDuration / 2}ms`,
              fill: 'freeze'
            });
            label.appendChild(animateOpacity);

            const animatePosition = this.createSVGElement('animateTransform', {
              attributeName: 'transform',
              type: 'translate',
              to: `0,0`,
              from: labelX > centerX ? `-${horizontalLineLength + 5},0` : `${horizontalLineLength + 5},0`,
              dur: '0.5s',
              begin: `${this.config.animationDuration / 2}ms`,
              fill: 'freeze'
            });
            label.appendChild(animatePosition);
          }

          pieGroup.appendChild(label);
        }

        startAngle = endAngle;
      });
    });

    if (isDonut) {
      const donutHole = this.createSVGElement('circle', {
        cx: centerX,
        cy: centerY,
        r: Math.max(0, radius - this.config.donutThickness),
        fill: this.config.backgroundColor
      });

      if (this.config.gap <= 0 && this.config.borderWidth > 0) {
        const borderColor = this.config.borderColor === 'auto' ? this.darkenColor(this.config.colors[0]) : this.config.borderColor;
        donutHole.setAttribute('stroke', borderColor || '#000');
        donutHole.setAttribute('stroke-width', this.config.borderWidth);
      }

      pieGroup.appendChild(donutHole);
    }

    if (this.config.showCenterText) {
      const centerText = this.createSVGElement('text', {
        x: centerX,
        y: centerY,
        'text-anchor': 'middle',
        'alignment-baseline': 'middle',
        'font-size': this.config.fontSize,
        fill: this.config.textColor,
        'font-weight': 'bold'
      });
      centerText.textContent = this.config.centerText !== null
        ? this.config.centerText
        : isDonut ? `${Now.translate('Total')}: ${total}` : '';
      this.svg.appendChild(centerText);
    }

    this.svg.appendChild(pieGroup);
  }

  /**
   * Draws a gauge chart based on the current data
   */
  drawGauge() {
    const centerX = this.margin.left + (this.width - this.margin.left - this.margin.right) / 2;
    const centerY = this.margin.top + (this.height - this.margin.top - this.margin.bottom) / 2;

    const radius = Math.min(
      this.width - this.margin.left - this.margin.right,
      this.height - this.margin.top - this.margin.bottom
    ) / 2.0;
    const startAngle = -Math.PI * 0.75;
    const endAngle = Math.PI * 0.75;

    const background = this.createSVGElement('path', {
      d: this.describeArc(centerX, centerY, radius, startAngle, endAngle),
      fill: 'none',
      stroke: this.config.gridColor,
      'stroke-width': this.config.gaugeCurveWidth
    });
    this.svg.appendChild(background);

    const value = this.data[0].data[0].value;
    const maxValue = this.config.maxGaugeValue || 100;
    const percentage = (value / maxValue) * 100;
    const valueAngle = startAngle + (percentage / 100) * (endAngle - startAngle);

    const valuePath = this.createSVGElement('path', {
      d: this.describeArc(centerX, centerY, radius, startAngle, valueAngle),
      fill: 'none',
      stroke: this.data[0].color || this.config.colors[0],
      'stroke-width': this.config.gaugeCurveWidth,
      'stroke-linecap': 'round'
    });

    if (typeof this.config.onClick === 'function') {
      valuePath.style.cursor = 'pointer';
      valuePath.addEventListener('click', () => {
        this.config.onClick({
          type: 'gauge',
          series: this.data[0],
          data: this.data[0].data[0]
        });
      });
    }

    if (this.config.animation) {
      const length = valuePath.getTotalLength();
      valuePath.setAttribute('stroke-dasharray', length);
      valuePath.setAttribute('stroke-dashoffset', length);

      const animateGauge = this.createSVGElement('animate', {
        attributeName: 'stroke-dashoffset',
        from: length,
        to: '0',
        dur: `${this.config.animationDuration}ms`,
        fill: 'freeze'
      });
      valuePath.appendChild(animateGauge);
    }

    this.svg.appendChild(valuePath);

    if (this.config.showCenterText) {
      const centerText = this.createSVGElement('text', {
        x: centerX,
        y: centerY,
        'text-anchor': 'middle',
        'dominant-baseline': 'middle',
        'font-size': this.config.fontSize,
        'font-family': this.config.fontFamily,
        fill: this.config.textColor,
        'font-weight': 'bold'
      });
      centerText.textContent = this.config.centerText !== null
        ? this.config.centerText
        : `${this.formatValue(percentage)}%`;
      this.svg.appendChild(centerText);

      const label = this.createSVGElement('text', {
        x: centerX,
        y: centerY + 30,
        'text-anchor': 'middle',
        'font-size': this.config.fontSize * 0.8,
        'font-family': this.config.fontFamily,
        fill: this.config.textColor
      });
      label.textContent = this.data[0].data[0].label || '';
      this.svg.appendChild(label);
    }
  }

  /**
   * Draws the legend for the graph using SVG with circular color indicators.
   */
  drawLegend() {
    if (!this.config.showLegend) return;

    if (this.legend) {
      this.container.parentElement.removeChild(this.legend);
    }

    this.legend = document.createElement('div');
    this.legend.className = 'ggraphs-legend';
    this.legend.style.display = 'flex';
    this.legend.style.justifyContent = 'center';
    this.legend.style.flexWrap = 'wrap';
    this.legend.style.columnGap = '20px';

    if (this.currentChartType === 'pie' || this.currentChartType === 'donut') {
      this.data.forEach((series) => {
        series.data.forEach((point, index) => {
          const color = point.color || series.color || this.config.colors[index % this.config.colors.length];
          const item = this.createLegendItem(color, point.label);
          this.legend.appendChild(item);
        });
      });
    } else {
      this.data.forEach((series, index) => {
        const color = series.color || this.config.colors[index % this.config.colors.length];
        const item = this.createLegendItem(color, series.name || `Series ${index + 1}`);
        this.legend.appendChild(item);
      });
    }

    if (this.config.legendPosition === 'top') {
      this.container.parentElement.insertBefore(this.legend, this.container);
    } else {
      this.container.parentElement.appendChild(this.legend);
    }
  }

  /**
   * Creates a single legend item with a colored circle and text.
   * @param {string} color - The color for the circle.
   * @param {string} text - The text to display.
   * @returns {HTMLElement} The legend item element.
   */
  createLegendItem(color, text) {
    const item = document.createElement('div');
    item.style.display = '-webkit-box';
    item.style.webkitBoxOrient = 'vertical';
    item.style.webkitLineClamp = 1;
    item.style.overflow = 'hidden';

    const colorBox = document.createElement('span');
    colorBox.style.backgroundColor = color;
    colorBox.style.width = '1em';
    colorBox.style.height = '1em';
    colorBox.style.backgroundColor = color;
    colorBox.style.borderRadius = '9999px';
    colorBox.style.display = 'inline-block';
    colorBox.style.marginRight = '5px';

    const label = document.createElement('span');
    label.innerHTML = text;

    item.appendChild(colorBox);
    item.appendChild(label);
    return item;
  }

  /**
   * Retrieves the tooltip content for a data point.
   * @param {Object} series - The data series.
   * @param {Object} point - The data point.
   * @returns {string} The tooltip content.
   */
  getTooltipContent(series, point) {
    if (this.config.tooltipFormatter) {
      return this.config.tooltipFormatter(series, point);
    }
    return `${series.name}: ${this.stripTags(point.label)} - ${this.formatValue(point.value)}`;
  }

  /**
   * Retrieves the label content for a data point, using a custom formatter if provided, with length limitation.
   * @param {Object} series - The data series.
   * @param {Object} point - The data point.
   * @returns {string} The label content, truncated if necessary.
   */
  getLabelContent(series, point) {
    if (this.currentChartType === 'pie' || this.currentChartType === 'donut') {
      return this.config.showValueInsteadOfPercent ?
        this.formatValue(point.value) :
        `${this.formatValue((point.value / this.getTotal(series)) * 100)}%`;
    } else {
      return this.formatValue(point.value);
    }
  }

  /**
   * Removes HTML tags from a string, returning plain text.
   * @param {string} html - The string containing HTML tags.
   * @returns {string} The plain text without HTML tags.
   */
  stripTags(html) {
    const div = document.createElement('div');
    div.innerHTML = html;
    return div.textContent || div.innerText || '';
  }

  /**
   * Formats a numeric value with commas as thousand separators.
   * @param {number} value - The value to format.
   * @returns {string} The formatted value with commas.
   */
  formatValue(value) {
    if (typeof value !== 'number' || isNaN(value)) return value;

    value = Number(value.toFixed(1));
    const parts = value.toString().split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return parts.join('.');
  }

  /**
   * Estimates the width of a given text string.
   * @param {string} text - The text to measure.
   * @returns {number} The estimated width in pixels.
   */
  estimateTextWidth(text) {
    const tempText = this.createSVGElement('text', {
      'font-size': this.config.labelFontSize,
      'font-family': this.config.fontFamily
    });
    tempText.textContent = text;
    this.svg.appendChild(tempText);
    const bbox = tempText.getBBox();
    this.svg.removeChild(tempText);
    return bbox.width;
  }

  /**
   * Retrieves the total value of a series.
   * @param {Object} series - The data series.
   * @returns {number} The total value.
   */
  getTotal(series) {
    return series.data.reduce((sum, point) => sum + point.value, 0);
  }

  /**
   * Sets the margins of the graph based on the presence of the legend and other options.
   */
  setMargins() {
    let margin = {top: 50, right: 50, bottom: 50, left: 50};

    if (this.config.type === 'gauge') {
      margin = {top: 66, right: 66, bottom: 66, left: 66};
    } else if (!this.config.showDataLabels) {
      if (['pie', 'donut'].includes(this.config.type)) {
        margin = {top: 30, right: 30, bottom: 30, left: 30};
      } else if (!this.config.showAxisLabels) {
        margin = {top: 10, right: 10, bottom: 10, left: 10};
      }
    }

    this.margin = margin;
  }

  /**
   * Darkens a HEX color by a specified factor.
   * @param {string} hex - The HEX color code (e.g., '#FF6B6B').
   * @param {number} factor - The darkening factor (0 to 1, default 0.2).
   * @returns {string} The darkened HEX color code.
   */
  darkenColor(hex, factor = 0.2) {
    let color = hex.startsWith('#') ? hex.slice(1) : hex;
    if (color.length === 3) {
      color = color.split('').map(c => c + c).join('');
    }
    const r = Math.max(0, Math.min(255, Math.round(parseInt(color.slice(0, 2), 16) * (1 - factor))));
    const g = Math.max(0, Math.min(255, Math.round(parseInt(color.slice(2, 4), 16) * (1 - factor))));
    const b = Math.max(0, Math.min(255, Math.round(parseInt(color.slice(4, 6), 16) * (1 - factor))));
    return `#${r.toString(16).padStart(2, '0')}${g.toString(16).padStart(2, '0')}${b.toString(16).padStart(2, '0')}`;
  }

  /**
   * Loads and processes data from an HTML table.
   * @param {HTMLTableElement} table - The table element to load data from.
   * @returns {Array} The processed series data.
   */
  loadAndProcessTableData(table) {
    const tableData = this.loadFromTable(table);
    return this.processTableData(tableData);
  }

  /**
   * Calculates a nice range for the y-axis and updates the internal state.
   */
  calculateNiceRange() {
    const range = this.maxValue - this.minValue;
    if (range === 0) {
      this.minNice = this.minValue - 1;
      this.maxNice = this.maxValue + 1;
      return;
    }
    const roughStep = range / 5;
    const magnitude = Math.pow(10, Math.floor(Math.log10(roughStep)));
    const niceStep = Math.ceil(roughStep / magnitude) * magnitude;

    this.minNice = Math.floor(this.minValue / niceStep) * niceStep;
    this.maxNice = Math.ceil(this.maxValue / niceStep) * niceStep;

    if (this.minValue > 0) {
      if (this.minValue === this.minNice) {
        this.minNice = Math.max(0, this.minNice - niceStep);
      }
      if (this.maxValue === this.maxNice) {
        this.maxNice += niceStep;
      }
    }

    if (this.maxValue < 0) {
      if (this.maxValue === this.maxNice) {
        this.maxNice = Math.min(0, this.maxNice + niceStep);
      }
      if (this.minValue === this.minNice) {
        this.minNice -= niceStep;
      }
    }
  }

  /**
   * Loads data from a table and renders the graph.
   */
  initialize() {
    this.clear();
    this.calculateFontSize();
    this.setMargins();

    if (this.config.table) {
      const table = document.getElementById(this.config.table);
      if (table) {
        const processedData = this.loadAndProcessTableData(table);
        this.setData(processedData);
      } else {
        console.warn(`Table with ID "${this.config.table}" not found.`);
      }
    } else if (this.config.data) {
      this.setData(this.config.data);
    }

    this.renderGraph();
  }

  /**
   * Destroys the graph instance, removing event listeners and observers.
   */
  destroy() {
    window.removeEventListener('resize', this.handleResize);
    if (this.container && this.svg && this.container.contains(this.svg)) {
      this.container.removeChild(this.svg);
    }
  }

  /**
   * Throttle helper
   * @param {Function} func - The function to throttle.
   * @param {number} limit - The throttle limit in milliseconds.
   * @returns {Function} The throttled function.
   */
  throttle(func, limit) {
    let inThrottle;
    return function(...args) {
      if (!inThrottle) {
        func.apply(this, args);
        inThrottle = true;
        setTimeout(() => inThrottle = false, limit);
      }
    }
  }

  /**
   * Add animation system
   * @param {SVGElement} element - The SVG element to animate.
   * @param {Object} properties - The properties to animate.
   * @param {number} duration - The duration of the animation in milliseconds.
   * @returns {Promise} A promise that resolves when the animation finishes.
   */
  animate(element, properties, duration = 1000) {
    if (!this.config.animation) return;

    const animations = [];

    Object.entries(properties).forEach(([prop, value]) => {
      const animation = element.animate([
        {[prop]: element.getAttribute(prop)},
        {[prop]: value}
      ], {
        duration,
        easing: 'ease-in-out',
        fill: 'forwards'
      });

      animations.push(animation);
    });

    return Promise.all(animations.map(a => a.finished));
  }

  /**
   * Helper function to get path length
   * @param {string} pathData - The path data.
   * @returns {number} The length of the path.
   */
  getPathLength(pathData) {
    const tempPath = this.createSVGElement('path', {
      d: pathData
    });
    const length = tempPath.getTotalLength();
    tempPath.remove();
    return length;
  }

  /**
   * Handles touch start events.
   * @param {TouchEvent} event - The touch event.
   */
  handleTouchStart(event) {
    this.touchStartX = event.touches[0].clientX;
    this.touchStartY = event.touches[0].clientY;
  }

  /**
   * Handles touch move events.
   * @param {TouchEvent} event - The touch event.
   */
  handleTouchMove(event) {
    if (!this.touchStartX || !this.touchStartY) return;

    const xDiff = this.touchStartX - event.touches[0].clientX;
    const yDiff = this.touchStartY - event.touches[0].clientY;

    if (Math.abs(xDiff) > Math.abs(yDiff)) {
      this.pan(xDiff);
    } else {
      this.zoom(yDiff);
    }
  }

  /**
   * Handles touch end events.
   */
  handleTouchEnd() {
    this.touchStartX = 0;
    this.touchStartY = 0;
  }

  /**
   * Exports the current graph as an image file (PNG, JPEG, or SVG).
   * @param {string} [filename='graph'] - The base name of the exported file.
   * @param {string} [format='png'] - The image format ('png', 'jpeg', or 'svg').
   * @param {number} [width=this.width] - The width of the exported image (for PNG/JPEG).
   * @param {number} [height=null] - The height of the exported image (for PNG/JPEG).
   * @param {number} [quality=0.8] - The quality for JPEG format (0 to 1, for JPEG only).
   * @param {number} [scale=1] - The scale factor for the exported image (for PNG/JPEG).
   */
  exportToImage(filename = 'graph', format = 'png', width = this.width, height = null, quality = 0.8, scale = 1) {
    const validFormats = ['png', 'jpeg', 'svg'];
    if (!validFormats.includes(format)) {
      console.warn(`Invalid format "${format}". Defaulting to "png".`);
      format = 'png';
    }

    if (!this.svg) {
      console.error('No SVG content to export.');
      return;
    }

    const originalAnimations = this.disableAnimations();

    const aspectRatio = this.height / this.width;
    const exportWidth = format === 'svg' ? this.width : width * scale;
    const exportHeight = format === 'svg' ? this.height : (height !== null ? height : width * aspectRatio) * scale;

    const svgClone = this.svg.cloneNode(true);
    svgClone.setAttribute('width', exportWidth);
    svgClone.setAttribute('height', exportHeight);
    svgClone.setAttribute('viewBox', `0 0 ${this.width} ${this.height}`);
    svgClone.insertAdjacentHTML('afterbegin', `<desc>${this.getAccessibilityDescription()}</desc>`);

    const svgString = new XMLSerializer().serializeToString(svgClone);

    this.restoreAnimations(originalAnimations);

    if (format === 'svg') {
      try {
        const svgBlob = new Blob([svgString], {type: 'image/svg+xml;charset=utf-8'});
        const url = URL.createObjectURL(svgBlob);
        const link = document.createElement('a');
        link.download = `${filename}.svg`;
        link.href = url;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
      } catch (error) {
        console.error('SVG export failed:', error);
        throw error;
      }
      return;
    }

    const svgDataUrl = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svgString);

    const canvas = document.createElement('canvas');
    canvas.width = exportWidth;
    canvas.height = exportHeight;

    try {
      const ctx = canvas.getContext('2d');
      if (!ctx) throw new Error('Failed to get canvas context');

      const img = new Image();
      img.onload = () => {
        ctx.fillStyle = this.config.backgroundColor;
        ctx.fillRect(0, 0, exportWidth, exportHeight);
        ctx.drawImage(img, 0, 0, exportWidth, exportHeight);

        const mimeType = format === 'jpeg' ? 'image/jpeg' : 'image/png';
        const imageDataUrl = format === 'jpeg' ? canvas.toDataURL(mimeType, quality) : canvas.toDataURL(mimeType);

        const link = document.createElement('a');
        link.download = `${filename}.${format}`;
        link.href = imageDataUrl;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
      };
      img.onerror = () => {
        console.error('Failed to load SVG for export.');
      };
      img.src = svgDataUrl;
    } catch (error) {
      console.error('Export failed:', error);
      throw error;
    }
  }

  /**
   * Disables animations in the SVG temporarily and returns their original states.
   * @returns {Array} Array of animation elements with their original attributes.
   */
  disableAnimations() {
    const animations = [];
    const animateElements = this.svg.getElementsByTagName('animate');
    for (let i = animateElements.length - 1; i >= 0; i--) {
      const animate = animateElements[i];
      const parent = animate.parentElement;
      const attributeName = animate.getAttribute('attributeName');
      const toValue = animate.getAttribute('to');

      animations.push({element: animate.cloneNode(true), parent});
      if (attributeName && toValue && parent) {
        parent.setAttribute(attributeName, toValue);
        parent.removeChild(animate);
      }
    }

    const animateTransforms = this.svg.getElementsByTagName('animateTransform');
    for (let i = animateTransforms.length - 1; i >= 0; i--) {
      const animate = animateTransforms[i];
      const parent = animate.parentElement;
      const toValue = animate.getAttribute('to');

      animations.push({element: animate.cloneNode(true), parent});
      if (animate.getAttribute('type') === 'translate' && toValue && parent) {
        const [x, y] = toValue.split(',');
        parent.setAttribute('transform', `translate(${x}, ${y})`);
        parent.removeChild(animate);
      }
    }

    return animations;
  }

  /**
   * Restores animations to the SVG from their original states.
   * @param {Array} animations - Array of animation elements with their parents.
   */
  restoreAnimations(animations) {
    animations.forEach(({element, parent}) => {
      parent.appendChild(element);
    });
  }

  /**
   * Exports the graph to a CSV file.
   * @returns {string} The CSV data.
   */
  exportToCSV() {
    const rows = [['Series', 'Label', 'Value']];

    this.data.forEach(series => {
      series.data.forEach(point => {
        rows.push([series.name, point.label, point.value]);
      });
    });

    return rows.map(row => row.join(',')).join('\n');
  }

  /**
   * Sets the theme for the graph.
   * @param {string} theme - The theme name.
   */
  setTheme(theme) {
    this.config = {
      ...this.config,
      ...this.themes[theme]
    };
    this.redrawGraph();
  }

  /**
   * Checks and manages SVG element
   * @private
   * @returns {SVGElement} Ready-to-use SVG element
   */
  ensureSVG() {
    try {
      if (this.container.clientWidth === 0 || this.container.clientHeight === 0) {
        // Container is not properly sized yet, schedule a retry
        if (!this._waitingForSize) {
          this._waitingForSize = true;
          setTimeout(() => {
            this._waitingForSize = false;
            this.redrawGraph();
          }, 100);
        }
        return null;
      }

      if (this.svg && this.container.contains(this.svg)) {
        return this.svg;
      }

      // Create new SVG if it doesn't exist
      this.createSVG();

      return this.svg;

    } catch (error) {
      console.error('[GraphRenderer] Error ensuring SVG:', error);
      throw error;
    }
  }
};

// Expose globally
window.GraphRenderer = GraphRenderer;
