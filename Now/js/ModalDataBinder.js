/**
 * ModalDataBinder
 * Extends Modal functionality with data binding from template loops
 *
 * Features:
 * - Bind dynamic data to modal elements
 * - Support for data-modal-bind attribute
 * - Gallery mode with prev/next navigation
 * - Safe content sanitization
 * - Works with TemplateManager
 *
 * @requires Modal
 * @requires DialogManager
 */
const ModalDataBinder = {
  config: {
    galleryClass: 'modal-gallery',
    bindAttribute: 'data-modal-bind',
    targetAttribute: 'data-modal-target',
    contentAttribute: 'data-gallery-content', // Mark which element should animate
    sanitize: true,
    defaultEffect: 'fade',
    effectDuration: 300,
    swipeThreshold: 50 // Minimum swipe distance in pixels
  },

  state: {
    initialized: false,
    modalInstances: new Map(), // modalId => Modal instance
    galleryData: new Map()      // modalId => gallery config
  },

  /**
   * Initialize ModalDataBinder
   */
  init() {
    if (this.state.initialized) return;

    this.bindModalTriggers();
    this.bindGalleryNavigation();
    this.bindTouchNavigation();
    this.state.initialized = true;
  },

  /**
   * Bind modal trigger elements
   */
  bindModalTriggers() {
    document.addEventListener('click', (e) => {
      const trigger = e.target.closest('[data-modal]');
      if (!trigger) return;

      e.preventDefault();
      this.handleModalTrigger(trigger);
    });
  },

  /**
   * Bind gallery navigation
   */
  bindGalleryNavigation() {
    document.addEventListener('click', (e) => {
      const navBtn = e.target.closest('[data-modal-nav]');
      if (!navBtn) return;

      e.preventDefault();
      const direction = navBtn.dataset.modalNav;
      const modal = navBtn.closest('.modal');

      if (modal && direction) {
        this.navigateGallery(modal.id, direction);
      }
    });
  },

  /**
   * Bind touch/swipe navigation for galleries
   */
  bindTouchNavigation() {
    let touchStartX = 0;
    let touchStartY = 0;
    let touchEndX = 0;
    let touchEndY = 0;
    let currentModal = null;

    document.addEventListener('touchstart', (e) => {
      const modal = e.target.closest('.modal.show');
      if (!modal) return;

      // Only enable swipe for galleries
      if (!this.state.galleryData.has(modal.id)) return;

      currentModal = modal;
      touchStartX = e.changedTouches[0].screenX;
      touchStartY = e.changedTouches[0].screenY;
    }, {passive: true});

    document.addEventListener('touchend', (e) => {
      if (!currentModal) return;

      touchEndX = e.changedTouches[0].screenX;
      touchEndY = e.changedTouches[0].screenY;

      const deltaX = touchEndX - touchStartX;
      const deltaY = touchEndY - touchStartY;

      // Check if horizontal swipe is dominant
      if (Math.abs(deltaX) > Math.abs(deltaY) && Math.abs(deltaX) > this.config.swipeThreshold) {
        if (deltaX < 0) {
          // Swipe left = next
          this.navigateGallery(currentModal.id, 'next');
        } else {
          // Swipe right = prev
          this.navigateGallery(currentModal.id, 'prev');
        }
      }

      currentModal = null;
    }, {passive: true});
  },

  /**
   * Handle modal trigger click
   * Supports:
   * - data-modal: modal ID for local data binding
   * - data-modal-api: API URL to fetch modal content/data
   * - data-modal-bind: local data binding expressions
   * - data-param-*: parameters to send with API request
   */
  async handleModalTrigger(trigger) {
    const modalId = trigger.dataset.modal;
    const modalApi = trigger.dataset.modalApi;

    // If API is specified, load from server
    if (modalApi) {
      await this.loadModalFromApi(trigger, modalApi, modalId);
      return;
    }

    // Otherwise, use local data binding
    if (!modalId) return;

    // Parse bind data
    const bindData = this.parseBindData(trigger.dataset.modalBind);

    // Evaluate expressions in current context
    const data = this.evaluateBindData(bindData, trigger);

    // Check if gallery mode
    const isGallery = trigger.dataset.modalGallery === 'true';

    if (isGallery) {
      this.setupGallery(modalId, trigger, data);
    }

    // Open modal with data
    this.openModalWithData(modalId, data, {
      gallery: isGallery,
      trigger
    });
  },

  /**
   * Load modal content from API
   * @param {HTMLElement} trigger - The trigger element
   * @param {string} apiUrl - API URL to fetch from
   * @param {string} modalId - Optional modal ID for reference
   */
  async loadModalFromApi(trigger, apiUrl, modalId) {
    try {
      // Show loading state on trigger
      trigger.classList.add('loading');
      trigger.disabled = true;

      // Collect params from data-param-* attributes and data-modal-bind
      const params = this.collectApiParams(trigger);

      // Make API request
      let client = window.http;
      if (!client || typeof client.post !== 'function') {
        if (window.HttpClient) {
          console.warn('[ModalDataBinder] window.http missing, creating new HttpClient');
          client = new window.HttpClient();
        } else {
          throw new Error('HttpClient is not available');
        }
      }

      const response = await client.post(apiUrl, params);
      const responseData = response?.data?.data || response?.data || response;

      // Remove loading state
      trigger.classList.remove('loading');
      trigger.disabled = false;

      // Pass response to ResponseHandler
      if (window.ResponseHandler) {
        await ResponseHandler.process(responseData, {
          trigger,
          modalId
        });
      } else {
        console.warn('[ModalDataBinder] ResponseHandler not available');
      }

    } catch (error) {
      console.error('[ModalDataBinder] API load error:', error);

      // Remove loading state
      trigger.classList.remove('loading');
      trigger.disabled = false;

      // Show error notification
      if (window.NotificationManager) {
        NotificationManager.error(error.message || 'Failed to load modal');
      }
    }
  },

  /**
   * Collect parameters from trigger element
   * Supports: data-param-*, data-modal-bind, and template context
   * @param {HTMLElement} trigger - The trigger element
   * @returns {Object} Parameters object
   */
  collectApiParams(trigger) {
    const params = {};
    const dataset = trigger.dataset;

    // Collect data-param-* attributes
    Object.keys(dataset).forEach(key => {
      if (key.startsWith('param')) {
        // Convert paramUserId -> user_id
        const rawName = key.replace('param', '');
        if (!rawName) return;

        const normalizedName = rawName
          .replace(/([A-Z])/g, '_$1')
          .replace(/[-\s]+/g, '_')
          .replace(/^_+/, '')
          .toLowerCase();

        let value = dataset[key];

        // Check if value is a template expression {fieldName}
        if (value.startsWith('{') && value.endsWith('}')) {
          const fieldName = value.slice(1, -1);
          value = this.resolveTemplateValue(fieldName, trigger);
        }

        params[normalizedName] = value;
      }
    });

    // Also check data-modal-bind for additional params
    if (dataset.modalBind) {
      const bindData = this.parseBindData(dataset.modalBind);
      const evaluated = this.evaluateBindData(bindData, trigger);
      Object.assign(params, evaluated);
    }

    return params;
  },

  /**
   * Resolve template value from context or form
   * @param {string} fieldName - Field name to resolve
   * @param {HTMLElement} trigger - Trigger element for context
   * @returns {*} Resolved value
   */
  resolveTemplateValue(fieldName, trigger) {
    // Try from trigger's dataset
    if (trigger.dataset[fieldName] !== undefined) {
      return trigger.dataset[fieldName];
    }

    // Try from closest form
    const form = trigger.closest('form');
    if (form) {
      const input = form.querySelector(`[name="${fieldName}"], #${fieldName}`);
      if (input) {
        return input.value;
      }
    }

    // Try from URL query params
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has(fieldName)) {
      return urlParams.get(fieldName);
    }

    // Try from parent element with data attribute
    const parent = trigger.closest(`[data-${fieldName}]`);
    if (parent) {
      return parent.dataset[fieldName];
    }

    // Return empty string if not found
    return '';
  },


  /**
   * Parse bind data string
   */
  parseBindData(bindStr) {
    if (!bindStr) return {};

    const pairs = bindStr.split(',').map(s => s.trim());
    const data = {};

    pairs.forEach(pair => {
      const [key, expr] = pair.split(':').map(s => s.trim());
      if (key && expr) {
        data[key] = expr;
      }
    });

    return data;
  },

  /**
   * Evaluate bind data expressions
   */
  evaluateBindData(bindData, trigger) {
    const result = {};

    // Find template context (data-for loop)
    const templateContext = this.findTemplateContext(trigger);

    for (const [key, expr] of Object.entries(bindData)) {
      try {
        const value = this.evaluateExpression(expr, templateContext, trigger);
        result[key] = value;
      } catch (error) {
        result[key] = '';
      }
    }

    return result;
  },

  /**
   * Find template context for element
   */
  findTemplateContext(element) {
    // Look for closest element with data attributes from template
    let current = element;

    while (current && current !== document.body) {
      // Check if element has data attributes that look like template bindings
      const dataset = current.dataset;

      // Common template data attributes
      if (dataset.category || dataset.title || dataset.id || dataset.index) {
        return current;
      }

      current = current.parentElement;
    }

    return element;
  },

  /**
   * Evaluate expression in context
   */
  evaluateExpression(expr, context, trigger) {
    // Handle special variables
    if (expr === '$index') {
      return this.getElementIndex(context);
    }

    if (expr === '$item') {
      return context.dataset;
    }

    // Handle simple data attribute access: item.property
    if (expr.startsWith('item.')) {
      const prop = expr.substring(5); // Remove 'item.'
      const attrName = prop.replace(/([A-Z])/g, '-$1').toLowerCase();
      return context.dataset[prop] || context.getAttribute(`data-${attrName}`) || '';
    }

    // Handle direct values
    if (expr.startsWith('"') || expr.startsWith("'")) {
      return expr.slice(1, -1);
    }

    // Try to get from dataset
    const value = context.dataset[expr] || context.getAttribute(`data-${expr}`);
    if (value !== null) {
      return value;
    }

    // Return as-is if can't evaluate
    return expr;
  },

  /**
   * Get element index in parent
   */
  getElementIndex(element) {
    if (!element.parentElement) return 0;

    const siblings = Array.from(element.parentElement.children);
    return siblings.indexOf(element);
  },

  /**
   * Setup gallery mode
   */
  setupGallery(modalId, trigger, data) {
    // Find all gallery items in same container
    const container = trigger.closest('[data-for]')?.parentElement || trigger.parentElement;
    const items = Array.from(container.querySelectorAll('[data-modal="' + modalId + '"]'));

    const currentIndex = items.indexOf(trigger);

    this.state.galleryData.set(modalId, {
      items,
      currentIndex,
      loop: trigger.dataset.modalGalleryLoop !== 'false',
      effect: trigger.dataset.modalEffect || this.config.defaultEffect
    });
  },

  /**
   * Navigate gallery
   */
  navigateGallery(modalId, direction) {
    const gallery = this.state.galleryData.get(modalId);
    if (!gallery) return;

    const {items, currentIndex, loop, effect} = gallery;
    let newIndex = currentIndex;

    if (direction === 'prev') {
      newIndex = currentIndex - 1;
      if (newIndex < 0) {
        newIndex = loop ? items.length - 1 : 0;
      }
    } else if (direction === 'next') {
      newIndex = currentIndex + 1;
      if (newIndex >= items.length) {
        newIndex = loop ? 0 : items.length - 1;
      }
    }

    if (newIndex !== currentIndex) {
      gallery.currentIndex = newIndex;
      const trigger = items[newIndex];

      // Get new data
      const bindData = this.parseBindData(trigger.dataset.modalBind);
      const data = this.evaluateBindData(bindData, trigger);

      // Apply transition effect
      this.applyGalleryEffect(modalId, data, effect, direction);
    }
  },

  /**
   * Apply gallery transition effect
   * @param {string} modalId Modal ID
   * @param {Object} data New data to display
   * @param {string} effect Effect type (fade, slide-left, slide-right, slide-up, slide-down, zoom)
   * @param {string} direction Navigation direction (prev, next)
   */
  applyGalleryEffect(modalId, data, effect, direction) {
    const modalRef = this.state.modalInstances.get(modalId);
    if (!modalRef) return;

    const modalElement = modalRef.isPreExisting ? modalRef.element : modalRef.modal;
    if (!modalElement) return;

    // Find the content element to animate (not the whole modal)
    // Priority: 1. [data-gallery-content] 2. .lightbox-body 3. .modal-body 4. first data-modal-target img
    let content = modalElement.querySelector(`[${this.config.contentAttribute}]`);
    if (!content) {
      content = modalElement.querySelector('.lightbox-body, .modal-body');
    }
    if (!content) {
      // Fallback: find the first image with data-modal-target
      content = modalElement.querySelector('img[data-modal-target]');
    }
    if (!content) {
      // No content wrapper, just update data
      this.updateModalData(modalId, data);
      return;
    }

    // Determine animation classes based on effect and direction
    let exitClass, enterClass;

    switch (effect) {
      case 'slide':
      case 'slide-horizontal':
        exitClass = direction === 'next' ? 'gallery-slide-out-left' : 'gallery-slide-out-right';
        enterClass = direction === 'next' ? 'gallery-slide-in-right' : 'gallery-slide-in-left';
        break;
      case 'slide-left':
        exitClass = 'gallery-slide-out-left';
        enterClass = 'gallery-slide-in-left';
        break;
      case 'slide-right':
        exitClass = 'gallery-slide-out-right';
        enterClass = 'gallery-slide-in-right';
        break;
      case 'slide-up':
      case 'slide-vertical':
        exitClass = direction === 'next' ? 'gallery-slide-out-up' : 'gallery-slide-out-down';
        enterClass = direction === 'next' ? 'gallery-slide-in-up' : 'gallery-slide-in-down';
        break;
      case 'slide-down':
        exitClass = 'gallery-slide-out-down';
        enterClass = 'gallery-slide-in-down';
        break;
      case 'zoom':
        exitClass = 'gallery-zoom-out';
        enterClass = 'gallery-zoom-in';
        break;
      case 'fade':
      default:
        exitClass = 'gallery-fade-out';
        enterClass = 'gallery-fade-in';
        break;
    }

    // Apply exit animation
    content.classList.add(exitClass);

    // Wait for exit animation to complete
    setTimeout(() => {
      content.classList.remove(exitClass);

      // Update data
      this.updateModalData(modalId, data);

      // Apply enter animation
      content.classList.add(enterClass);

      // Clean up enter animation
      setTimeout(() => {
        content.classList.remove(enterClass);
      }, this.config.effectDuration);
    }, this.config.effectDuration / 2);
  },

  /**
   * Open modal with data
   */
  openModalWithData(modalId, data, options = {}) {
    const modalElement = document.getElementById(modalId);
    if (!modalElement) {
      console.warn(`Modal element with id "${modalId}" not found`);
      return;
    }

    // Check if this is a pre-existing modal element (has modal class already)
    const isPreExistingModal = modalElement.classList.contains('modal');

    if (isPreExistingModal) {
      // Bind data directly to the existing modal
      this._bindDataToElement(modalElement, data);

      // Show the modal using simple class toggle
      this._showPreExistingModal(modalElement);

      // Store reference
      this.state.modalInstances.set(modalId, {element: modalElement, isPreExisting: true});
    } else {
      // Get or create Modal instance for dynamic modals
      let modal = this.state.modalInstances.get(modalId);

      if (!modal || modal.isPreExisting) {
        // Extract existing content if any
        const existingContent = modalElement.innerHTML;
        const existingTitle = modalElement.querySelector('.modal-title')?.textContent || '';

        // Remove existing element
        modalElement.remove();

        // Create Modal instance
        modal = new Modal({
          id: modalId,
          title: existingTitle,
          content: existingContent,
          closeButton: true,
          backdrop: true,
          keyboard: true,
          animation: true
        });

        // Store instance
        this.state.modalInstances.set(modalId, modal);
      }

      // Bind data to modal
      modal.bindData(data);

      // Show modal
      modal.show();
    }
  },

  /**
   * Bind data to a modal element (for pre-existing modals)
   * @private
   */
  _bindDataToElement(modalElement, data) {
    for (const [key, value] of Object.entries(data)) {
      const targets = modalElement.querySelectorAll(`[${this.config.targetAttribute}="${key}"]`);
      targets.forEach(target => {
        this._setElementContent(target, value, key);
      });
    }
  },

  /**
   * Set element content (for pre-existing modals)
   * @private
   */
  _setElementContent(element, value, key) {
    const tagName = element.tagName.toLowerCase();

    // Image elements
    if (tagName === 'img') {
      element.src = value || '';
      if (!element.alt) element.alt = key;
      return;
    }

    // Link elements
    if (tagName === 'a') {
      element.href = value || '#';
      return;
    }

    // Input elements
    if (tagName === 'input' || tagName === 'textarea') {
      element.value = value || '';
      return;
    }

    // Default: set text content
    element.textContent = value || '';
  },

  /**
   * Show pre-existing modal element
   * @private
   */
  _showPreExistingModal(modalElement) {
    // Initialize events if not already done
    if (!modalElement.dataset.modalInitialized) {
      this._bindPreExistingModalEvents(modalElement);
      modalElement.dataset.modalInitialized = 'true';
    }

    // Show backdrop
    if (window.BackdropManager) {
      modalElement._backdropId = BackdropManager.show(modalElement, () => this.closeModal(modalElement.id));
    }

    // Store trigger element so focus can be returned on close
    modalElement._triggerElement = document.activeElement;

    // Show modal
    modalElement.classList.add('show');
    modalElement.setAttribute('aria-hidden', 'false');
    modalElement.removeAttribute('inert');
    document.body.classList.add('modal-open');

    // Focus management
    requestAnimationFrame(() => {
      const focusable = modalElement.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
      if (focusable) {
        focusable.focus();
      }
    });
  },

  /**
   * Bind events for pre-existing modal
   * @private
   */
  _bindPreExistingModalEvents(modalElement) {
    // Close buttons
    const closeBtns = modalElement.querySelectorAll('.modal-close, [data-close], [data-dismiss="modal"]');
    closeBtns.forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        this.closeModal(modalElement.id);
      });
    });

    // Backdrop click
    modalElement.addEventListener('click', (e) => {
      if (e.target === modalElement) {
        this.closeModal(modalElement.id);
      }
    });

    // ESC key
    const escHandler = (e) => {
      if (e.key === 'Escape' && modalElement.classList.contains('show')) {
        this.closeModal(modalElement.id);
      }
    };
    document.addEventListener('keydown', escHandler);
    modalElement._escHandler = escHandler;
  },



  /**
   * Update modal data
   */
  updateModalData(modalId, data) {
    const modalRef = this.state.modalInstances.get(modalId);
    if (!modalRef) {
      console.warn(`Modal "${modalId}" not found`);
      return;
    }

    if (modalRef.isPreExisting) {
      // Update pre-existing modal element
      this._bindDataToElement(modalRef.element, data);
    } else {
      // Update Modal instance
      modalRef.updateData(data);
    }
  },



  /**
   * Close modal
   */
  closeModal(modalId) {
    const modalRef = this.state.modalInstances.get(modalId);

    if (modalRef) {
      if (modalRef.isPreExisting) {
        // Hide pre-existing modal element
        const modalElement = modalRef.element;

        // Hide backdrop
        if (modalElement._backdropId && window.BackdropManager) {
          BackdropManager.hide(modalElement._backdropId);
          modalElement._backdropId = null;
        }

        // Move focus out of modal before hiding to prevent aria-hidden warning
        if (modalElement.contains(document.activeElement)) {
          document.activeElement.blur();
          (modalElement._triggerElement || document.body).focus();
        }

        // Hide modal
        modalElement.classList.remove('show');
        modalElement.setAttribute('aria-hidden', 'true');
        modalElement.setAttribute('inert', '');
        document.body.classList.remove('modal-open');
      } else {
        // Hide Modal instance
        modalRef.hide();
      }
    }

    // Clean up gallery data
    this.state.galleryData.delete(modalId);
  },

  /**
   * Get modal data
   */
  getModalData(modalId) {
    const modalRef = this.state.modalInstances.get(modalId);
    if (!modalRef) return null;

    if (modalRef.isPreExisting) {
      // For pre-existing modals, we don't track data
      return null;
    }

    return modalRef.getData();
  },

  /**
   * Destroy binder
   */
  destroy() {
    // Destroy all modal instances
    this.state.modalInstances.forEach(modal => modal.destroy());
    this.state.modalInstances.clear();
    this.state.galleryData.clear();
    this.state.initialized = false;
  }
};

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => ModalDataBinder.init());
} else {
  ModalDataBinder.init();
}

// Export for use in modules
if (typeof module !== 'undefined' && module.exports) {
  module.exports = ModalDataBinder;
}
