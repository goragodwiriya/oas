/**
 * BackdropManager
 *
 * Shows and stacks the dimmed overlay behind modals, dialogs and dropdowns.
 * Supports several backdrops at once, closes the topmost one on Escape, and
 * resolves its base z-index from the `--z-index-loading` CSS variable so it
 * stays in sync with a page's own stacking scheme.
 */
const BackdropManager = {
  config: {
    baseZIndex: 1070,
    animation: true,
    duration: 200,
    className: 'backdrop',
    background: 'rgba(0,0,0,0.5)',
    opacity: 0.5,
    useTransition: true,
    preventScroll: true,
    debug: false
  },

  state: {
    backdrops: new Map(),
    activeBackdrops: [],
    nextId: 1,
    isInitialized: false
  },

  /**
   * Read a CSS custom property as an integer.
   *
   * @param {string} variableName - CSS variable name, e.g. `--z-index-loading`.
   * @returns {number|null} - The parsed value, or null when it is not a usable integer.
   */
  getCssZIndexValue(variableName) {
    const value = getComputedStyle(document.documentElement)
      .getPropertyValue(variableName)
      .trim();
    const parsed = parseInt(value, 10);
    return Number.isFinite(parsed) ? parsed : null;
  },

  /**
   * The base z-index to stack backdrops from.
   *
   * Read from the `--z-index-loading` CSS variable when present, so backdrops
   * stay above or below other layers the page defines through CSS.
   *
   * @returns {number} - Resolved base z-index.
   */
  getResolvedBaseZIndex() {
    return this.getCssZIndexValue('--z-index-loading') ?? this.config.baseZIndex;
  },

  /**
   * Work out which of `show`'s flexible arguments is the element, the handler
   * and the options.
   *
   * @param {Element|Function|Object} elementOrOnClick - First argument to `show`.
   * @param {Function|Object|null} [onClickOrOptions=null] - Second argument to `show`.
   * @param {Object} [options={}] - Third argument to `show`.
   * @returns {Object} - `{targetElement, onClick, options}` resolved from whatever was passed.
   */
  normalizeShowArgs(elementOrOnClick, onClickOrOptions = null, options = {}) {
    const isTargetElement = (value) => value instanceof Element || value === document.body;
    const isListener = (value) => typeof value === 'function' || (value && typeof value.handleEvent === 'function');
    const isOptionsObject = (value) => value && typeof value === 'object' && !isTargetElement(value) && !isListener(value);

    let targetElement = null;
    let onClick = null;
    let showOptions = {};

    if (isTargetElement(elementOrOnClick)) {
      targetElement = elementOrOnClick;

      if (isListener(onClickOrOptions)) {
        onClick = onClickOrOptions;
      }

      if (isOptionsObject(options)) {
        showOptions = options;
      } else if (isOptionsObject(onClickOrOptions)) {
        showOptions = onClickOrOptions;
      }
    } else {
      if (isListener(elementOrOnClick)) {
        onClick = elementOrOnClick;
      }

      if (isOptionsObject(onClickOrOptions)) {
        showOptions = onClickOrOptions;
      }

      if (isOptionsObject(options)) {
        showOptions = options;
      }
    }

    return {
      targetElement,
      onClick,
      options: showOptions
    };
  },

  /**
   * Set up the manager: resolve the base z-index and bind the Escape handler.
   *
   * Runs once; a second call returns immediately.
   *
   * @param {Object} [options={}] - Overrides merged into the module config.
   * @returns {Promise<Object>} - The manager itself, so calls can be chained.
   */
  async init(options = {}) {
    if (this.state.isInitialized) return this;

    this.config = {...this.config, ...options};
    if (options.baseZIndex == null) {
      this.config.baseZIndex = this.getResolvedBaseZIndex();
    }
    this.handleKeydown = this.handleKeydown.bind(this);

    document.addEventListener('keydown', this.handleKeydown);

    this.state.isInitialized = true;
    return this;
  },

  /**
   * Build the backdrop's DOM element.
   *
   * Marked `aria-hidden="true"` and `role="presentation"`, since a backdrop is
   * purely visual and should not be announced by assistive tech.
   *
   * @param {Element} targetElement - Element the backdrop is shown behind.
   * @param {Object} options - Resolved show options.
   * @returns {HTMLElement} - The created backdrop element.
   */
  createBackdropElement(targetElement, options) {
    const backdrop = document.createElement('div');
    backdrop.className = `${this.config.className} ${options.className || ''}`.trim();
    backdrop.setAttribute('aria-hidden', 'true');
    backdrop.setAttribute('role', 'presentation');

    const styles = {
      position: 'fixed',
      top: 0,
      left: 0,
      width: '100vw',
      height: '100vh',
      background: options.background || this.config.background,
      opacity: 0,
      pointerEvents: 'auto',
      zIndex: options.zIndex ?? this.config.baseZIndex
    };

    if (this.config.useTransition) {
      styles.transition = `opacity ${this.config.duration}ms`;
    }

    Object.assign(backdrop.style, styles);

    if (targetElement?.parentNode) {
      targetElement.parentNode.insertBefore(backdrop, targetElement);
    } else {
      document.body.appendChild(backdrop);
    }

    return backdrop;
  },

  /**
   * Show a backdrop behind an element, or as a bare full-page overlay.
   *
   * Arguments are flexible on purpose — see `normalizeShowArgs` for exactly how
   * they are interpreted — so a caller can pass just a click handler when there
   * is no specific target element.
   *
   * @param {Element|Function|Object} elementOrOnClick - Target element, a click handler, or an options object.
   * @param {Function|Object} [onClick=null] - Click handler, or options when the first argument was the handler.
   * @param {Object} [options={}] - Options, when both earlier arguments were used for element and handler.
   * @returns {number} - Id of the shown backdrop, used with `hide`.
   */
  show(elementOrOnClick, onClick = null, options = {}) {
    try {
      const id = this.state.nextId++;
      const normalized = this.normalizeShowArgs(elementOrOnClick, onClick, options);
      const baseZIndex = this.getResolvedBaseZIndex();

      const backdropOptions = {
        ...this.config,
        ...normalized.options,
        zIndex: normalized.options.zIndex ?? (baseZIndex + (this.state.activeBackdrops.length * 10))
      };

      const backdropData = {
        id,
        element: this.createBackdropElement(normalized.targetElement, backdropOptions),
        targetElement: normalized.targetElement,
        onClick: normalized.onClick,
        options: backdropOptions
      };

      backdropData.element.id = `backdrop-${id}`;

      if (normalized.onClick) {
        backdropData.element.addEventListener('click', normalized.onClick);
      }

      this.state.backdrops.set(id, backdropData);
      this.state.activeBackdrops.push(id);

      if (backdropOptions.preventScroll) {
        document.body.style.overflow = 'hidden';
      }

      requestAnimationFrame(() => {
        backdropData.element.style.opacity = backdropOptions.opacity;
      });

      return id;
    } catch (error) {
      console.error('Error showing backdrop:', error);
      return null;
    }
  },

  /**
   * Fade out and remove one backdrop.
   *
   * @param {number} id - Id returned by `show`.
   * @returns {void}
   */
  hide(id) {
    try {
      const backdropData = this.state.backdrops.get(id);
      if (!backdropData) return;

      backdropData.element.style.opacity = 0;

      const cleanup = () => {
        if (backdropData.onClick) {
          backdropData.element.removeEventListener('click', backdropData.onClick);
        }

        backdropData.element.remove();
        this.state.backdrops.delete(id);

        const index = this.state.activeBackdrops.indexOf(id);
        if (index > -1) {
          this.state.activeBackdrops.splice(index, 1);
        }

        if (this.state.activeBackdrops.length === 0) {
          document.body.style.overflow = '';
        }
      };

      if (this.config.useTransition) {
        setTimeout(cleanup, this.config.duration);
      } else {
        cleanup();
      }
    } catch (error) {
      console.error('Error hiding backdrop:', error);
    }
  },

  /**
   * Change the options of a backdrop already showing behind an element.
   *
   * @param {Element} element - Element the backdrop is attached to.
   * @param {Object} [options={}] - Options to merge into the existing ones.
   * @returns {void}
   */
  update(element, options = {}) {
    const backdropData = this.state.backdrops.get(element);
    if (!backdropData) return;

    const backdrop = backdropData.element;
    const newOptions = {...backdropData.options, ...options};

    backdrop.style.background = newOptions.background;
    backdrop.style.opacity = this.state.activeBackdrops.indexOf(backdropData) !== -1 ?
      newOptions.opacity : '0';
    backdrop.style.zIndex = newOptions.zIndex;

    backdropData.options = newOptions;
  },

  /**
   * Hide every currently active backdrop.
   *
   * @returns {void}
   */
  hideAll() {
    [...this.state.activeBackdrops].forEach(id => this.hide(id));
  },


  /**
   * Hide the topmost backdrop when Escape is pressed.
   *
   * Only the most recently shown backdrop closes per press, so stacked
   * overlays close one at a time rather than all at once.
   *
   * @param {KeyboardEvent} event - The keydown event.
   * @returns {void}
   */
  handleKeydown(event) {
    if (event.key === 'Escape' && this.state.activeBackdrops.length > 0) {
      const lastId = this.state.activeBackdrops[this.state.activeBackdrops.length - 1];
      this.hide(lastId);
    }
  },

  /**
   * The DOM element for a shown backdrop.
   *
   * @param {number} id - Id returned by `show`.
   * @returns {HTMLElement|null} - The backdrop element, or null when unknown.
   */
  getBackdropById(id) {
    return this.state.backdrops.get(id)?.element || null;
  },

  /**
   * Whether an element currently has an active backdrop behind it.
   *
   * @param {Element} element - Element to check.
   * @returns {boolean} - True when its backdrop is active.
   */
  isActive(element) {
    const backdropData = this.state.backdrops.get(element);
    return backdropData && this.state.activeBackdrops.includes(backdropData);
  },

  /**
   * Tear the manager down: hide every backdrop and unbind the Escape handler.
   *
   * @returns {void}
   */
  destroy() {
    this.hideAll();
    document.removeEventListener('keydown', this.handleKeydown);
    this.state.isInitialized = false;
  }
};

if (window.Now?.registerManager) {
  Now.registerManager('backdrop', BackdropManager);
}

window.BackdropManager = BackdropManager;
