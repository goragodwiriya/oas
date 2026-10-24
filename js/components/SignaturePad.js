/**
 * SignaturePad Component
 * Digital signature capture and drawing functionality
 *
 * Features:
 * - Smooth signature drawing with pen pressure support
 * - Multiple pen types and colors
 * - Signature validation and saving
 * - Responsive canvas sizing
 * - Touch and mouse support
 * - Undo/redo functionality
 * - Export to various formats (PNG, SVG, JSON)
 */
class SignaturePad {
  /**
   * Create SignaturePad instance
   * @param {HTMLElement|string} container - Container element or selector
   * @param {Object} options - Configuration options
   */
  constructor(container, options = {}) {
    this.container = typeof container === 'string'
      ? document.querySelector(container)
      : container;

    if (!this.container) {
      throw new Error('SignaturePad: Container element not found');
    }

    this.options = {
      width: 400,
      height: 200,
      penColor: '#000000',
      penWidth: 2,
      minPenWidth: 1,
      maxPenWidth: 5,
      backgroundColor: '#ffffff',
      dotSize: 1,
      throttle: 16,
      minDistance: 5,
      velocityFilterWeight: 0.7,
      penPressure: true,
      smoothing: true,

      // UI Options
      showControls: true,
      showClearButton: true,
      showUndoButton: true,
      showColorPicker: true,
      showSizeSlider: true,

      // Validation
      minPoints: 10,
      validateOnSave: true,

      // Events
      onBegin: null,
      onEnd: null,
      onChange: null,
      onClear: null,
      onSave: null,

      ...options
    };

    this.state = {
      isDrawing: false,
      isEmpty: true,
      points: [],
      undoStack: [],
      redoStack: [],
      currentStroke: null,
      lastPoint: null,
      velocity: 0,
      lastVelocity: 0,
      lastWidth: 0
    };

    this.setupDOM();
    this.setupCanvas();
    this.bindEvents();
    this.resize();

    if (this.options.showControls) {
      this.renderControls();
    }
  }

  /**
   * Set up DOM structure
   * @private
   */
  setupDOM() {
    this.container.className = `signature-pad ${this.container.className || ''}`.trim();

    this.canvas = document.createElement('canvas');
    this.canvas.className = 'signature-canvas';
    this.canvas.setAttribute('tabindex', '0');
    this.canvas.setAttribute('role', 'img');
    this.canvas.setAttribute('aria-label', 'Signature drawing area');

    this.container.appendChild(this.canvas);

    // Create controls container
    if (this.options.showControls) {
      this.controlsContainer = document.createElement('div');
      this.controlsContainer.className = 'signature-controls';
      this.container.appendChild(this.controlsContainer);
    }
  }

  /**
   * Set up canvas context
   * @private
   */
  setupCanvas() {
    this.ctx = this.canvas.getContext('2d');
    this.ctx.lineCap = 'round';
    this.ctx.lineJoin = 'round';
    this.ctx.imageSmoothingEnabled = true;

    this.updateCanvasStyle();
  }

  /**
   * Update canvas styling
   * @private
   */
  updateCanvasStyle() {
    this.ctx.strokeStyle = this.options.penColor;
    this.ctx.lineWidth = this.options.penWidth;
    this.ctx.fillStyle = this.options.backgroundColor;
  }

  /**
   * Bind event listeners
   * @private
   */
  bindEvents() {
    // Mouse events
    this.canvas.addEventListener('mousedown', this.handleMouseDown.bind(this));
    this.canvas.addEventListener('mousemove', this.handleMouseMove.bind(this));
    this.canvas.addEventListener('mouseup', this.handleMouseUp.bind(this));
    this.canvas.addEventListener('mouseleave', this.handleMouseUp.bind(this));

    // Touch events
    this.canvas.addEventListener('touchstart', this.handleTouchStart.bind(this));
    this.canvas.addEventListener('touchmove', this.handleTouchMove.bind(this));
    this.canvas.addEventListener('touchend', this.handleTouchEnd.bind(this));

    // Keyboard events
    this.canvas.addEventListener('keydown', this.handleKeyDown.bind(this));

    // Window resize
    window.addEventListener('resize', this.throttle(this.resize.bind(this), 100));

    // Prevent scrolling when touching the canvas
    document.addEventListener('touchstart', (e) => {
      if (e.target === this.canvas) {
        e.preventDefault();
      }
    }, {passive: false});

    document.addEventListener('touchend', (e) => {
      if (e.target === this.canvas) {
        e.preventDefault();
      }
    }, {passive: false});

    document.addEventListener('touchmove', (e) => {
      if (e.target === this.canvas) {
        e.preventDefault();
      }
    }, {passive: false});
  }

  /**
   * Handle mouse down
   * @private
   */
  handleMouseDown(e) {
    if (e.button !== 0) return; // Only left click

    const point = this.getPointFromEvent(e);
    this.strokeBegin(point);
  }

  /**
   * Handle mouse move
   * @private
   */
  handleMouseMove(e) {
    if (!this.state.isDrawing) return;

    const point = this.getPointFromEvent(e);
    this.strokeUpdate(point);
  }

  /**
   * Handle mouse up
   * @private
   */
  handleMouseUp(e) {
    if (this.state.isDrawing) {
      const point = this.getPointFromEvent(e);
      this.strokeEnd(point);
    }
  }

  /**
   * Handle touch start
   * @private
   */
  handleTouchStart(e) {
    if (e.touches.length === 1) {
      const touch = e.touches[0];
      const point = this.getPointFromEvent(touch);
      this.strokeBegin(point);
    }
  }

  /**
   * Handle touch move
   * @private
   */
  handleTouchMove(e) {
    if (e.touches.length === 1 && this.state.isDrawing) {
      const touch = e.touches[0];
      const point = this.getPointFromEvent(touch);
      this.strokeUpdate(point);
    }
  }

  /**
   * Handle touch end
   * @private
   */
  handleTouchEnd(e) {
    if (e.changedTouches.length === 1 && this.state.isDrawing) {
      const touch = e.changedTouches[0];
      const point = this.getPointFromEvent(touch);
      this.strokeEnd(point);
    }
  }

  /**
   * Handle keyboard events
   * @private
   */
  handleKeyDown(e) {
    if (e.ctrlKey || e.metaKey) {
      switch (e.key.toLowerCase()) {
        case 'z':
          e.preventDefault();
          if (e.shiftKey) {
            this.redo();
          } else {
            this.undo();
          }
          break;
        case 'y':
          e.preventDefault();
          this.redo();
          break;
      }
    }
  }

  /**
   * Get point coordinates from event
   * @private
   */
  getPointFromEvent(e) {
    const rect = this.canvas.getBoundingClientRect();
    const scaleX = this.canvas.width / rect.width;
    const scaleY = this.canvas.height / rect.height;

    return {
      x: (e.clientX - rect.left) * scaleX,
      y: (e.clientY - rect.top) * scaleY,
      pressure: e.pressure || 0.5,
      time: Date.now()
    };
  }

  /**
   * Begin stroke
   * @private
   */
  strokeBegin(point) {
    this.state.isDrawing = true;
    this.state.isEmpty = false;
    this.state.currentStroke = {
      points: [point],
      color: this.options.penColor,
      width: this.options.penWidth
    };
    this.state.lastPoint = point;
    this.state.velocity = 0;
    this.state.lastVelocity = 0;
    this.state.lastWidth = this.options.penWidth;

    // Save state for undo
    this.saveState();

    this.ctx.beginPath();
    this.drawPoint(point);

    if (this.options.onBegin) {
      this.options.onBegin(point);
    }

    this.emitChange();
  }

  /**
   * Update stroke
   * @private
   */
  strokeUpdate(point) {
    if (!this.state.isDrawing || !this.state.lastPoint) return;

    const distance = this.calculateDistance(this.state.lastPoint, point);

    if (distance < this.options.minDistance) return;

    this.state.currentStroke.points.push(point);

    if (this.options.smoothing) {
      this.drawSmoothCurve(this.state.lastPoint, point);
    } else {
      this.drawLine(this.state.lastPoint, point);
    }

    this.state.lastPoint = point;
    this.emitChange();
  }

  /**
   * End stroke
   * @private
   */
  strokeEnd(point) {
    if (!this.state.isDrawing) return;

    this.state.isDrawing = false;

    if (point) {
      this.strokeUpdate(point);
    }

    // Add stroke to points array
    if (this.state.currentStroke) {
      this.state.points.push(this.state.currentStroke);
      this.state.currentStroke = null;
    }

    if (this.options.onEnd) {
      this.options.onEnd(point);
    }

    this.emitChange();
  }

  /**
   * Draw a point (for single clicks)
   * @private
   */
  drawPoint(point) {
    const radius = this.options.dotSize;
    this.ctx.fillStyle = this.options.penColor;
    this.ctx.beginPath();
    this.ctx.arc(point.x, point.y, radius, 0, 2 * Math.PI);
    this.ctx.fill();
  }

  /**
   * Draw a line between two points
   * @private
   */
  drawLine(from, to) {
    this.ctx.beginPath();
    this.ctx.moveTo(from.x, from.y);
    this.ctx.lineTo(to.x, to.y);
    this.ctx.stroke();
  }

  /**
   * Draw smooth curve with velocity-based width
   * @private
   */
  drawSmoothCurve(from, to) {
    const distance = this.calculateDistance(from, to);
    const time = to.time - from.time;

    // Calculate velocity
    this.state.velocity = time > 0 ? distance / time : 0;
    this.state.velocity = (this.options.velocityFilterWeight * this.state.velocity) +
      ((1 - this.options.velocityFilterWeight) * this.state.lastVelocity);

    // Calculate pen width based on velocity and pressure
    let width = this.options.penWidth;

    if (this.options.penPressure) {
      const pressure = to.pressure || 0.5;
      const velocityFactor = Math.max(0.1, 1 - (this.state.velocity * 0.01));
      width = this.options.penWidth * pressure * velocityFactor;
      width = Math.max(this.options.minPenWidth, Math.min(this.options.maxPenWidth, width));
    }

    // Smooth width transition
    width = (this.state.lastWidth + width) / 2;
    this.state.lastWidth = width;

    this.ctx.lineWidth = width;
    this.ctx.beginPath();
    this.ctx.moveTo(from.x, from.y);
    this.ctx.lineTo(to.x, to.y);
    this.ctx.stroke();

    this.state.lastVelocity = this.state.velocity;
  }

  /**
   * Calculate distance between two points
   * @private
   */
  calculateDistance(point1, point2) {
    const dx = point1.x - point2.x;
    const dy = point1.y - point2.y;
    return Math.sqrt(dx * dx + dy * dy);
  }

  /**
   * Save current state for undo
   * @private
   */
  saveState() {
    this.state.undoStack.push(this.getImageData());
    this.state.redoStack = []; // Clear redo stack when new action is performed

    // Limit undo stack size
    if (this.state.undoStack.length > 50) {
      this.state.undoStack.shift();
    }
  }

  /**
   * Render control buttons
   * @private
   */
  renderControls() {
    let controlsHTML = '<div class="signature-controls-group">';

    if (this.options.showClearButton) {
      controlsHTML += `
        <button type="button" class="btn-signature-clear" title="Clear signature">
          <i class="icon-clear"></i> Clear
        </button>
      `;
    }

    if (this.options.showUndoButton) {
      controlsHTML += `
        <button type="button" class="btn-signature-undo" title="Undo last stroke">
          <i class="icon-undo"></i> Undo
        </button>
        <button type="button" class="btn-signature-redo" title="Redo last stroke">
          <i class="icon-redo"></i> Redo
        </button>
      `;
    }

    controlsHTML += '</div>';

    if (this.options.showColorPicker) {
      controlsHTML += `
        <div class="signature-controls-group">
          <label for="signature-color-${this.getId()}">Color:</label>
          <input type="color" id="signature-color-${this.getId()}"
                 class="signature-color-picker" value="${this.options.penColor}">
        </div>
      `;
    }

    if (this.options.showSizeSlider) {
      controlsHTML += `
        <div class="signature-controls-group">
          <label for="signature-size-${this.getId()}">Size:</label>
          <input type="range" id="signature-size-${this.getId()}"
                 class="signature-size-slider"
                 min="${this.options.minPenWidth}"
                 max="${this.options.maxPenWidth}"
                 step="0.5"
                 value="${this.options.penWidth}">
          <span class="signature-size-value">${this.options.penWidth}</span>
        </div>
      `;
    }

    this.controlsContainer.innerHTML = controlsHTML;
    this.bindControlEvents();
  }

  /**
   * Bind control event listeners
   * @private
   */
  bindControlEvents() {
    const clearBtn = this.controlsContainer.querySelector('.btn-signature-clear');
    if (clearBtn) {
      clearBtn.addEventListener('click', () => this.clear());
    }

    const undoBtn = this.controlsContainer.querySelector('.btn-signature-undo');
    if (undoBtn) {
      undoBtn.addEventListener('click', () => this.undo());
    }

    const redoBtn = this.controlsContainer.querySelector('.btn-signature-redo');
    if (redoBtn) {
      redoBtn.addEventListener('click', () => this.redo());
    }

    const colorPicker = this.controlsContainer.querySelector('.signature-color-picker');
    if (colorPicker) {
      colorPicker.addEventListener('change', (e) => {
        this.setPenColor(e.target.value);
      });
    }

    const sizeSlider = this.controlsContainer.querySelector('.signature-size-slider');
    const sizeValue = this.controlsContainer.querySelector('.signature-size-value');
    if (sizeSlider) {
      sizeSlider.addEventListener('input', (e) => {
        const size = parseFloat(e.target.value);
        this.setPenWidth(size);
        if (sizeValue) {
          sizeValue.textContent = size;
        }
      });
    }
  }

  /**
   * Get unique ID for this instance
   * @private
   */
  getId() {
    if (!this._id) {
      this._id = Math.random().toString(36).substr(2, 9);
    }
    return this._id;
  }

  /**
   * Resize canvas to fit container
   */
  resize() {
    const computedStyle = getComputedStyle(this.container);
    const containerWidth = parseInt(computedStyle.width) || this.options.width;
    const containerHeight = parseInt(computedStyle.height) || this.options.height;

    // Store current image data if canvas has content
    let imageData = null;
    if (!this.isEmpty()) {
      imageData = this.getImageData();
    }

    // Set canvas dimensions
    this.canvas.width = containerWidth;
    this.canvas.height = containerHeight;

    // Update canvas style after resize
    this.updateCanvasStyle();

    // Restore image data if it existed
    if (imageData) {
      this.fromDataURL(imageData);
    } else {
      this.clear();
    }
  }

  /**
   * Clear the signature
   */
  clear() {
    this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);

    // Fill with background color
    if (this.options.backgroundColor) {
      this.ctx.fillStyle = this.options.backgroundColor;
      this.ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);
    }

    this.state.isEmpty = true;
    this.state.points = [];
    this.state.undoStack = [];
    this.state.redoStack = [];

    if (this.options.onClear) {
      this.options.onClear();
    }

    this.emitChange();
  }

  /**
   * Undo last stroke
   */
  undo() {
    if (this.state.undoStack.length === 0) return;

    this.state.redoStack.push(this.getImageData());
    const previousState = this.state.undoStack.pop();

    if (previousState) {
      this.fromDataURL(previousState);
    } else {
      this.clear();
    }

    this.emitChange();
  }

  /**
   * Redo last undone stroke
   */
  redo() {
    if (this.state.redoStack.length === 0) return;

    this.state.undoStack.push(this.getImageData());
    const nextState = this.state.redoStack.pop();
    this.fromDataURL(nextState);

    this.emitChange();
  }

  /**
   * Set pen color
   */
  setPenColor(color) {
    this.options.penColor = color;
    this.updateCanvasStyle();
  }

  /**
   * Set pen width
   */
  setPenWidth(width) {
    this.options.penWidth = Math.max(this.options.minPenWidth,
      Math.min(this.options.maxPenWidth, width));
    this.updateCanvasStyle();
  }

  /**
   * Check if signature is empty
   */
  isEmpty() {
    return this.state.isEmpty;
  }

  /**
   * Validate signature
   */
  isValid() {
    if (this.isEmpty()) return false;

    if (this.options.validateOnSave) {
      const totalPoints = this.state.points.reduce((sum, stroke) => sum + stroke.points.length, 0);
      return totalPoints >= this.options.minPoints;
    }

    return true;
  }

  /**
   * Get signature as image data URL
   */
  getImageData(type = 'image/png', quality = 1) {
    return this.canvas.toDataURL(type, quality);
  }

  /**
   * Load signature from data URL
   */
  fromDataURL(dataURL) {
    return new Promise((resolve, reject) => {
      const image = new Image();
      image.onload = () => {
        this.clear();
        this.ctx.drawImage(image, 0, 0);
        this.state.isEmpty = false;
        resolve();
      };
      image.onerror = reject;
      image.src = dataURL;
    });
  }

  /**
   * Get signature as SVG
   */
  toSVG() {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('width', this.canvas.width);
    svg.setAttribute('height', this.canvas.height);
    svg.setAttribute('viewBox', `0 0 ${this.canvas.width} ${this.canvas.height}`);

    // Add background
    if (this.options.backgroundColor) {
      const bg = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
      bg.setAttribute('width', '100%');
      bg.setAttribute('height', '100%');
      bg.setAttribute('fill', this.options.backgroundColor);
      svg.appendChild(bg);
    }

    // Add strokes
    this.state.points.forEach(stroke => {
      if (stroke.points.length > 1) {
        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        let d = `M ${stroke.points[0].x} ${stroke.points[0].y}`;

        for (let i = 1; i < stroke.points.length; i++) {
          d += ` L ${stroke.points[i].x} ${stroke.points[i].y}`;
        }

        path.setAttribute('d', d);
        path.setAttribute('stroke', stroke.color);
        path.setAttribute('stroke-width', stroke.width);
        path.setAttribute('stroke-linecap', 'round');
        path.setAttribute('stroke-linejoin', 'round');
        path.setAttribute('fill', 'none');
        svg.appendChild(path);
      }
    });

    return svg.outerHTML;
  }

  /**
   * Get signature data as JSON
   */
  toJSON() {
    return {
      points: this.state.points,
      options: {
        width: this.canvas.width,
        height: this.canvas.height,
        penColor: this.options.penColor,
        backgroundColor: this.options.backgroundColor
      },
      metadata: {
        created: new Date().toISOString(),
        isEmpty: this.isEmpty(),
        isValid: this.isValid()
      }
    };
  }

  /**
   * Load signature from JSON data
   */
  fromJSON(data) {
    this.clear();

    if (data.points && data.points.length > 0) {
      this.state.points = data.points;
      this.state.isEmpty = false;

      // Redraw all strokes
      data.points.forEach(stroke => {
        if (stroke.points && stroke.points.length > 0) {
          this.ctx.strokeStyle = stroke.color;
          this.ctx.lineWidth = stroke.width;

          this.ctx.beginPath();
          this.ctx.moveTo(stroke.points[0].x, stroke.points[0].y);

          for (let i = 1; i < stroke.points.length; i++) {
            this.ctx.lineTo(stroke.points[i].x, stroke.points[i].y);
          }

          this.ctx.stroke();
        }
      });
    }

    this.emitChange();
  }

  /**
   * Save signature with validation
   */
  save() {
    if (!this.isValid()) {
      throw new Error('Signature is not valid');
    }

    const signatureData = {
      imageData: this.getImageData(),
      svg: this.toSVG(),
      json: this.toJSON()
    };

    if (this.options.onSave) {
      this.options.onSave(signatureData);
    }

    return signatureData;
  }

  /**
   * Emit change event
   * @private
   */
  emitChange() {
    if (this.options.onChange) {
      this.options.onChange({
        isEmpty: this.isEmpty(),
        isValid: this.isValid(),
        pointCount: this.state.points.reduce((sum, stroke) => sum + stroke.points.length, 0)
      });
    }
  }

  /**
   * Throttle function calls
   * @private
   */
  throttle(func, limit) {
    let inThrottle;
    return function() {
      const args = arguments;
      const context = this;
      if (!inThrottle) {
        func.apply(context, args);
        inThrottle = true;
        setTimeout(() => inThrottle = false, limit);
      }
    }
  }

  /**
   * Destroy the signature pad
   */
  destroy() {
    // Remove event listeners
    this.canvas.removeEventListener('mousedown', this.handleMouseDown);
    this.canvas.removeEventListener('mousemove', this.handleMouseMove);
    this.canvas.removeEventListener('mouseup', this.handleMouseUp);
    this.canvas.removeEventListener('mouseleave', this.handleMouseUp);
    this.canvas.removeEventListener('touchstart', this.handleTouchStart);
    this.canvas.removeEventListener('touchmove', this.handleTouchMove);
    this.canvas.removeEventListener('touchend', this.handleTouchEnd);
    this.canvas.removeEventListener('keydown', this.handleKeyDown);

    // Clear container
    if (this.container) {
      this.container.innerHTML = '';
    }

    // Clear state
    this.state = null;
    this.canvas = null;
    this.ctx = null;
    this.container = null;
    this.controlsContainer = null;
  }
}

// Register with Now.js framework if available
if (window.Now?.registerComponent) {
  Now.registerComponent('SignaturePad', SignaturePad);
}

window.SignaturePad = SignaturePad;
