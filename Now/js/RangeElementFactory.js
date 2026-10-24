/**
 * EmbeddedRangeSlider
 *
 * A custom-built range slider, single or dual-handle, that replaces the native
 * `<input type="range">` for better keyboard and touch UX. Instantiated once
 * per element by `RangeElementFactory`; the constructor moves the original
 * input off-screen and renders the visible slider in its place.
 */
class EmbeddedRangeSlider {
  constructor(originalElement) {
    this._original = originalElement;
    if (!this._original) {
      throw new Error('RangeSlider: Original element is required');
    }

    this.name = this._original.getAttribute('name') || '';
    this.id = this._original.id || ('rangeslider-' + Math.random().toString(36).slice(2, 8));
    const _fc = this._original.closest('.form-control');
    if (_fc) _fc.classList.add('form-control-range');

    // Parse configuration
    this.min = parseFloat(this._original.getAttribute('min')) || 0;
    this.max = parseFloat(this._original.getAttribute('max')) || 100;
    this.step = parseFloat(this._original.getAttribute('step')) || this._calculateStep();
    this.isDualRange = this._original.hasAttribute('range') || this._original.hasAttribute('data-range');
    this.showLabels = this._original.hasAttribute('data-show-labels');
    this.showTooltips = this._original.hasAttribute('data-show-tooltips');
    this.prefix = this._original.getAttribute('data-prefix') || '';
    this.suffix = this._original.getAttribute('data-suffix') || '';

    // Initialize state
    this.disabled = this._original.hasAttribute('disabled');
    this.readonly = this._original.hasAttribute('readonly');
    this.isDragging = false;
    this.activeHandle = null;

    // Parse initial values
    this._parseInitialValues();

    this._initializeElements();
    this._setupEventListeners();
    this._replaceOriginalElement();
    this._updateDisplay();
  }

  _calculateStep() {
    const range = this.max - this.min;
    if (range < 1) return 0.01;
    if (range < 100) return 1;
    return Math.floor((range / 100) * 1000) / 1000;
  }

  _parseInitialValues() {
    const value = this._original.value || this._original.getAttribute('value') || '';

    if (this.isDualRange) {
      if (value.includes(',')) {
        const values = value.split(',').map(v => parseFloat(v.trim()));
        this.values = [
          Math.max(this.min, Math.min(this.max, values[0] || this.min)),
          Math.max(this.min, Math.min(this.max, values[1] || this.max))
        ];
      } else {
        this.values = [this.min, this.max];
      }

      // Ensure proper order
      if (this.values[0] > this.values[1]) {
        [this.values[0], this.values[1]] = [this.values[1], this.values[0]];
      }
    } else {
      this.values = Math.max(this.min, Math.min(this.max, parseFloat(value) || this.min));
    }
  }

  _initializeElements() {
    // Create main wrapper
    this.wrapper = document.createElement('div');
    this.wrapper.className = 'custom-range-slider';
    this.wrapper.id = this.id + '_wrapper';

    // Create labels container if needed
    if (this.showLabels) {
      this.labelsContainer = document.createElement('div');
      this.labelsContainer.className = 'range-labels';

      this.minLabel = document.createElement('span');
      this.minLabel.className = 'range-label min';
      this.minLabel.textContent = this._formatValue(this.min);

      this.maxLabel = document.createElement('span');
      this.maxLabel.className = 'range-label max';
      this.maxLabel.textContent = this._formatValue(this.max);

      this.labelsContainer.appendChild(this.minLabel);
      this.labelsContainer.appendChild(this.maxLabel);
      this.wrapper.appendChild(this.labelsContainer);
    }

    // Create slider container
    this.sliderContainer = document.createElement('div');
    this.sliderContainer.className = 'slider-container';
    this.wrapper.appendChild(this.sliderContainer);

    // Create track
    this.track = document.createElement('div');
    this.track.className = 'slider-track';
    this.sliderContainer.appendChild(this.track);

    // Create range (filled area)
    this.range = document.createElement('div');
    this.range.className = 'slider-range';
    this.track.appendChild(this.range);

    // Create handles
    this.leftHandle = document.createElement('div');
    this.leftHandle.className = 'slider-handle left';
    this.leftHandle.tabIndex = 0;
    this.leftHandle.setAttribute('role', 'slider');
    this.leftHandle.setAttribute('aria-valuemin', this.min);
    this.leftHandle.setAttribute('aria-valuemax', this.max);
    this.sliderContainer.appendChild(this.leftHandle);

    if (this.isDualRange) {
      this.rightHandle = document.createElement('div');
      this.rightHandle.className = 'slider-handle right';
      this.rightHandle.tabIndex = 0;
      this.rightHandle.setAttribute('role', 'slider');
      this.rightHandle.setAttribute('aria-valuemin', this.min);
      this.rightHandle.setAttribute('aria-valuemax', this.max);
      this.sliderContainer.appendChild(this.rightHandle);
    }

    // Create tooltips if needed
    if (this.showTooltips) {
      this.leftTooltip = document.createElement('div');
      this.leftTooltip.className = 'slider-tooltip left';
      this.leftHandle.appendChild(this.leftTooltip);

      if (this.isDualRange) {
        this.rightTooltip = document.createElement('div');
        this.rightTooltip.className = 'slider-tooltip right';
        this.rightHandle.appendChild(this.rightTooltip);
      }
    }

    // Create value display
    this.valueDisplay = document.createElement('div');
    this.valueDisplay.className = 'range-value-display';
    this.wrapper.appendChild(this.valueDisplay);
  }

  _setupEventListeners() {
    // Handle mouse events
    this._setupMouseEvents();

    // Handle keyboard events
    this._setupKeyboardEvents();

    // Handle touch events for mobile
    this._setupTouchEvents();

    // Handle resize
    this._setupResizeObserver();
  }

  _setupMouseEvents() {
    // Track click to jump to position
    this.track.addEventListener('mousedown', (e) => {
      if (this.disabled || this.readonly) return;

      const rect = this.track.getBoundingClientRect();
      const clickX = e.clientX - rect.left;
      const percentage = clickX / rect.width;
      const value = this.min + (percentage * (this.max - this.min));

      if (this.isDualRange) {
        // Determine which handle is closer
        const leftDistance = Math.abs(value - this.values[0]);
        const rightDistance = Math.abs(value - this.values[1]);

        if (leftDistance <= rightDistance) {
          this.values[0] = this._snapToStep(value);
          this.activeHandle = this.leftHandle;
        } else {
          this.values[1] = this._snapToStep(value);
          this.activeHandle = this.rightHandle;
        }

        this._ensureValidRange();
      } else {
        this.values = this._snapToStep(value);
        this.activeHandle = this.leftHandle;
      }

      this._updateDisplay();
      this._dispatchChangeEvent();

      // Start dragging
      this._startDrag(e);
    });

    // Handle dragging
    this.leftHandle.addEventListener('mousedown', (e) => {
      if (this.disabled || this.readonly) return;
      e.preventDefault();
      this.activeHandle = this.leftHandle;
      this._startDrag(e);
    });

    if (this.rightHandle) {
      this.rightHandle.addEventListener('mousedown', (e) => {
        if (this.disabled || this.readonly) return;
        e.preventDefault();
        this.activeHandle = this.rightHandle;
        this._startDrag(e);
      });
    }
  }

  _setupKeyboardEvents() {
    const handleKeydown = (e, isRight = false) => {
      if (this.disabled || this.readonly) return;

      let handled = false;
      const currentValue = this.isDualRange ?
        (isRight ? this.values[1] : this.values[0]) : this.values;

      switch (e.key) {
        case 'ArrowLeft':
        case 'ArrowDown':
          e.preventDefault();
          this._adjustValue(isRight, -this.step);
          handled = true;
          break;
        case 'ArrowRight':
        case 'ArrowUp':
          e.preventDefault();
          this._adjustValue(isRight, this.step);
          handled = true;
          break;
        case 'PageDown':
          e.preventDefault();
          this._adjustValue(isRight, -this.step * 10);
          handled = true;
          break;
        case 'PageUp':
          e.preventDefault();
          this._adjustValue(isRight, this.step * 10);
          handled = true;
          break;
        case 'Home':
          e.preventDefault();
          if (this.isDualRange) {
            if (isRight) {
              this.values[1] = this.values[0];
            } else {
              this.values[0] = this.min;
            }
          } else {
            this.values = this.min;
          }
          this._updateDisplay();
          this._dispatchChangeEvent();
          handled = true;
          break;
        case 'End':
          e.preventDefault();
          if (this.isDualRange) {
            if (isRight) {
              this.values[1] = this.max;
            } else {
              this.values[0] = this.values[1];
            }
          } else {
            this.values = this.max;
          }
          this._updateDisplay();
          this._dispatchChangeEvent();
          handled = true;
          break;
      }

      if (handled) {
        e.stopPropagation();
      }
    };

    this.leftHandle.addEventListener('keydown', (e) => handleKeydown(e, false));
    if (this.rightHandle) {
      this.rightHandle.addEventListener('keydown', (e) => handleKeydown(e, true));
    }
  }

  _setupTouchEvents() {
    // Touch support for mobile
    this.leftHandle.addEventListener('touchstart', (e) => {
      if (this.disabled || this.readonly) return;
      e.preventDefault();
      this.activeHandle = this.leftHandle;
      this._startDrag(e.touches[0]);
    });

    if (this.rightHandle) {
      this.rightHandle.addEventListener('touchstart', (e) => {
        if (this.disabled || this.readonly) return;
        e.preventDefault();
        this.activeHandle = this.rightHandle;
        this._startDrag(e.touches[0]);
      });
    }
  }

  _setupResizeObserver() {
    // Update display when container resizes
    if (window.ResizeObserver) {
      this.resizeObserver = new ResizeObserver(() => {
        this._updateDisplay();
      });
      this.resizeObserver.observe(this.sliderContainer);
    }
  }

  _startDrag(e) {
    this.isDragging = true;
    this.wrapper.classList.add('dragging');

    const handleMouseMove = (e) => {
      if (!this.isDragging) return;

      const clientX = e.clientX || (e.touches && e.touches[0].clientX);
      if (!clientX) return;

      const rect = this.track.getBoundingClientRect();
      const percentage = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
      const value = this.min + (percentage * (this.max - this.min));

      if (this.isDualRange) {
        if (this.activeHandle === this.leftHandle) {
          this.values[0] = this._snapToStep(value);
        } else {
          this.values[1] = this._snapToStep(value);
        }
        this._ensureValidRange();
      } else {
        this.values = this._snapToStep(value);
      }

      this._updateDisplay();
      this._dispatchInputEvent();
    };

    const handleMouseUp = () => {
      if (this.isDragging) {
        this.isDragging = false;
        this.wrapper.classList.remove('dragging');
        this.activeHandle = null;
        this._dispatchChangeEvent();
      }

      document.removeEventListener('mousemove', handleMouseMove);
      document.removeEventListener('mouseup', handleMouseUp);
      document.removeEventListener('touchmove', handleMouseMove);
      document.removeEventListener('touchend', handleMouseUp);
    };

    document.addEventListener('mousemove', handleMouseMove);
    document.addEventListener('mouseup', handleMouseUp);
    document.addEventListener('touchmove', handleMouseMove);
    document.addEventListener('touchend', handleMouseUp);
  }

  _adjustValue(isRight, delta) {
    if (this.isDualRange) {
      if (isRight) {
        this.values[1] = this._snapToStep(this.values[1] + delta);
      } else {
        this.values[0] = this._snapToStep(this.values[0] + delta);
      }
      this._ensureValidRange();
    } else {
      this.values = this._snapToStep(this.values + delta);
    }

    this._updateDisplay();
    this._dispatchChangeEvent();
  }

  _snapToStep(value) {
    const snapped = Math.round((value - this.min) / this.step) * this.step + this.min;
    return Math.max(this.min, Math.min(this.max, snapped));
  }

  _ensureValidRange() {
    if (this.isDualRange) {
      if (this.values[0] > this.values[1]) {
        [this.values[0], this.values[1]] = [this.values[1], this.values[0]];
      }
    }
  }

  _updateDisplay() {
    const range = this.max - this.min;

    if (this.isDualRange) {
      const leftPercent = ((this.values[0] - this.min) / range) * 100;
      const rightPercent = ((this.values[1] - this.min) / range) * 100;

      this.leftHandle.style.left = `${leftPercent}%`;
      this.rightHandle.style.left = `${rightPercent}%`;
      this.range.style.left = `${leftPercent}%`;
      this.range.style.width = `${rightPercent - leftPercent}%`;

      // Update ARIA values
      this.leftHandle.setAttribute('aria-valuenow', this.values[0]);
      this.rightHandle.setAttribute('aria-valuenow', this.values[1]);

      // Update tooltips
      if (this.showTooltips) {
        this.leftTooltip.textContent = this._formatValue(this.values[0]);
        this.rightTooltip.textContent = this._formatValue(this.values[1]);
      }

      // Update value display
      this.valueDisplay.textContent = `${this._formatValue(this.values[0])} - ${this._formatValue(this.values[1])}`;

      // Update hidden input
      this._setHiddenValue(`${this.values[0]},${this.values[1]}`);
    } else {
      const percent = ((this.values - this.min) / range) * 100;

      this.leftHandle.style.left = `${percent}%`;
      this.range.style.width = `${percent}%`;

      // Update ARIA values
      this.leftHandle.setAttribute('aria-valuenow', this.values);

      // Update tooltip
      if (this.showTooltips) {
        this.leftTooltip.textContent = this._formatValue(this.values);
      }

      // Update value display
      this.valueDisplay.textContent = this._formatValue(this.values);

      // Update hidden input
      this._setHiddenValue(this.values.toString());
    }
  }

  // Write the canonical value onto the reused original element. The _syncingValue
  // guard prevents the value-proxy setter from re-applying the value back into the
  // slider (which would cause infinite recursion via setValue → _updateDisplay).
  _setHiddenValue(str) {
    if (!this.hiddenInput) return;
    this._syncingValue = true;
    try {
      this.hiddenInput.value = str;
    } finally {
      this._syncingValue = false;
    }
  }

  _formatValue(value) {
    const decimals = this.step < 1 ? 2 : 0;
    const formatted = Utils.number.format(value, decimals);
    return `${this.prefix}${formatted}${this.suffix}`;
  }

  _dispatchInputEvent() {
    const inputEvent = new Event('input', {bubbles: true});
    this.hiddenInput.dispatchEvent(inputEvent);

    this.wrapper.dispatchEvent(new CustomEvent('rangeslider:input', {
      detail: {
        values: this.isDualRange ? [...this.values] : this.values,
        min: this.min,
        max: this.max
      }
    }));
  }

  _dispatchChangeEvent() {
    const changeEvent = new Event('change', {bubbles: true});
    this.hiddenInput.dispatchEvent(changeEvent);

    this.wrapper.dispatchEvent(new CustomEvent('rangeslider:change', {
      detail: {
        values: this.isDualRange ? [...this.values] : this.values,
        min: this.min,
        max: this.max
      }
    }));
  }

  // Public methods
  /**
   * The slider's current value.
   *
   * @returns {number|Array<number>} - A single value, or `[low, high]` for a dual-range slider.
   */
  getValue() {
    return this.isDualRange ? [...this.values] : this.values;
  }

  /**
   * Set the slider's value, snapping each number to the nearest step.
   *
   * @param {number|Array<number>} value - A single value, or `[low, high]` for dual-range.
   * @returns {void}
   */
  setValue(value) {
    if (this.isDualRange) {
      if (Array.isArray(value) && value.length >= 2) {
        this.values = [
          this._snapToStep(value[0]),
          this._snapToStep(value[1])
        ];
        this._ensureValidRange();
      }
    } else {
      this.values = this._snapToStep(value);
    }

    this._updateDisplay();
  }

  /**
   * Change the slider's minimum and update the handles' ARIA attributes to match.
   *
   * @param {number} min - New minimum.
   * @returns {void}
   */
  setMin(min) {
    this.min = min;
    this.leftHandle.setAttribute('aria-valuemin', min);
    if (this.rightHandle) {
      this.rightHandle.setAttribute('aria-valuemin', min);
    }
    if (this.showLabels) {
      this.minLabel.textContent = this._formatValue(min);
    }
    this._updateDisplay();
  }

  /**
   * Change the slider's maximum and update the handles' ARIA attributes to match.
   *
   * @param {number} max - New maximum.
   * @returns {void}
   */
  setMax(max) {
    this.max = max;
    this.leftHandle.setAttribute('aria-valuemax', max);
    if (this.rightHandle) {
      this.rightHandle.setAttribute('aria-valuemax', max);
    }
    if (this.showLabels) {
      this.maxLabel.textContent = this._formatValue(max);
    }
    this._updateDisplay();
  }

  /**
   * Change the slider's step and refresh the display to match.
   *
   * @param {number} step - New step size.
   * @returns {void}
   */
  setStep(step) {
    this.step = step;
    this._updateDisplay();
  }

  /**
   * Enable or disable the slider, including removing its handles from the tab order.
   *
   * @param {boolean} disabled - Whether the slider is disabled.
   * @returns {void}
   */
  setDisabled(disabled) {
    this.disabled = disabled;
    this.wrapper.classList.toggle('disabled', disabled);
    this.leftHandle.tabIndex = disabled ? -1 : 0;
    if (this.rightHandle) {
      this.rightHandle.tabIndex = disabled ? -1 : 0;
    }
  }

  /**
   * Toggle read-only presentation without removing the slider from the tab order.
   *
   * @param {boolean} readonly - Whether the slider is read-only.
   * @returns {void}
   */
  setReadonly(readonly) {
    this.readonly = readonly;
    this.wrapper.classList.toggle('readonly', readonly);
  }

  /**
   * Tear the slider down: disconnect its resize observer and remove its DOM.
   *
   * @returns {void}
   */
  destroy() {
    if (this.resizeObserver) {
      this.resizeObserver.disconnect();
    }

    if (this.wrapper && this.wrapper.parentNode) {
      this.wrapper.parentNode.removeChild(this.wrapper);
    }
  }

  /**
   * The wrapper element this slider rendered into.
   *
   * @returns {HTMLElement} - The wrapper.
   */
  getElement() {
    return this.wrapper;
  }

  _replaceOriginalElement() {
    if (this._original.parentNode) {
      // Keep the original element in the DOM as the hidden value holder so that
      // document.getElementById(id) and id.value keep working like a native input.
      // (Same approach as DateElementFactory / ColorElementFactory.) Using type
      // "hidden" also lets it store comma-separated dual-range values.
      this._original.type = 'hidden';
      this._original.style.display = 'none';
      this.hiddenInput = this._original;

      // Insert the custom widget right before the (now hidden) original element.
      if (this._original.parentNode !== this.wrapper) {
        this._original.parentNode.insertBefore(this.wrapper, this._original);
      }

      this._installValueProxy();

      // Seed the canonical value now that hiddenInput is available.
      this._setHiddenValue(this.isDualRange ? `${this.values[0]},${this.values[1]}` : this.values.toString());
    }
  }

  // Make the original element behave like a native range input: reading .value
  // returns the current value, and assigning .value moves the slider thumb.
  _installValueProxy() {
    const slider = this;
    const proto = (typeof HTMLInputElement !== 'undefined') ? HTMLInputElement.prototype : null;
    const nativeDesc = proto ? Object.getOwnPropertyDescriptor(proto, 'value') : null;
    if (!nativeDesc || !nativeDesc.get || !nativeDesc.set) return;

    Object.defineProperty(this._original, 'value', {
      configurable: true,
      enumerable: true,
      get() {
        return nativeDesc.get.call(this);
      },
      set(v) {
        nativeDesc.set.call(this, v == null ? '' : String(v));
        if (!slider._syncingValue) {
          slider._applyExternalValue(v);
        }
      }
    });
  }

  // Apply a value set externally via element.value = x onto the slider UI.
  _applyExternalValue(v) {
    if (this.isDualRange) {
      const parts = String(v).split(',').map(p => parseFloat(p.trim()));
      if (parts.length >= 2 && parts.every(n => !isNaN(n))) {
        this.setValue([parts[0], parts[1]]);
      }
    } else {
      const num = parseFloat(v);
      if (!isNaN(num)) {
        this.setValue(num);
      }
    }
  }
}

// Expose for backward compatibility
if (!window.GRange) {
  window.GRange = EmbeddedRangeSlider;
}

/**
 * RangeElementFactory - Factory for creating range slider elements
 */
class RangeElementFactory extends ElementFactory {
  static config = {
    ...ElementFactory.config,
    type: 'range'
  };

  /**
   * Get or create the element's instance state, same as the base ElementFactory.
   *
   * @param {HTMLElement} element - Element the state belongs to.
   * @param {Object} [config={}] - Config merged in when first created.
   * @returns {Object} - The element's state object.
   */
  static createInstance(element, config = {}) {
    const instance = super.createInstance(element, config);
    return instance;
  }

  /**
   * Replace the native range input with an EmbeddedRangeSlider.
   *
   * @param {Object} instance - Element instance carrying `element` and `config`.
   * @returns {void}
   */
  static setupElement(instance) {
    const {element} = instance;

    try {
      const rangeSlider = new EmbeddedRangeSlider(element);

      instance.wrapper = rangeSlider.getElement();
      instance.rangeSlider = rangeSlider;

      // Bind methods
      instance.getValue = () => rangeSlider.getValue();
      instance.setValue = (value) => rangeSlider.setValue(value);
      instance.setMin = (min) => rangeSlider.setMin(min);
      instance.setMax = (max) => rangeSlider.setMax(max);
      instance.setStep = (step) => rangeSlider.setStep(step);
      instance.setDisabled = (disabled) => rangeSlider.setDisabled(disabled);
      instance.setReadonly = (readonly) => rangeSlider.setReadonly(readonly);

      // Update element reference
      element.wrapper = instance.wrapper;

      // Register hidden input in ElementManager so FormManager.setFieldValue
      // can find this instance and call setValue() to update the slider UI.
      // This is needed because ElementManager.scan() runs before form data is
      // loaded; when setFormData later queries the DOM it finds the hidden input
      // (not the original range input), and without this registration the slider
      // UI is never updated with the saved value.
      if (rangeSlider.hiddenInput && window.ElementManager) {
        try {
          ElementManager.state.elementIndex.set(rangeSlider.hiddenInput, instance);
        } catch (e) {
          // ignore — ElementManager may not expose state in all builds
        }
      }

      // The original element is reused as the value holder (rangeSlider.hiddenInput
      // === element), so input/change events already fire on it natively via
      // _dispatchInputEvent()/_dispatchChangeEvent(). We must NOT re-dispatch those
      // same events on the element here — that would cause infinite recursion.
      // Just bridge change into the app event system.
      if (rangeSlider.hiddenInput) {
        rangeSlider.hiddenInput.addEventListener('change', () => {
          EventManager.emit('element:change', {
            elementId: element.id,
            values: rangeSlider.getValue(),
            type: 'range'
          });
        });
      }

      // Forward custom events
      rangeSlider.wrapper.addEventListener('rangeslider:change', (e) => {
        element.dispatchEvent(new CustomEvent('rangeslider:change', {
          detail: e.detail
        }));
      });

      rangeSlider.wrapper.addEventListener('rangeslider:input', (e) => {
        element.dispatchEvent(new CustomEvent('rangeslider:input', {
          detail: e.detail
        }));
      });

      // Cleanup override
      const originalCleanup = instance.cleanup;
      instance.cleanup = function() {
        if (this.rangeSlider) {
          this.rangeSlider.destroy();
          this.rangeSlider = null;
        }
        if (originalCleanup) {
          originalCleanup.call(this);
        }
        return this;
      };

      return instance;

    } catch (error) {
      console.error('RangeElementFactory: Failed to setup element:', error);
      return instance;
    }
  }
}

// Register with ElementManager
if (typeof ElementManager !== 'undefined') {
  ElementManager.registerElement('range', RangeElementFactory);
} else {
  console.warn('RangeElementFactory: ElementManager not found');
}

// Export to global scope
window.RangeElementFactory = RangeElementFactory;
