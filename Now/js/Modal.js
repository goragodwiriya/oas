/**
 * Modal Component
 * A reusable modal dialog component
 *
 * Features:
 * - Show/hide with animation
 * - Custom header, body and footer
 * - Close button
 * - Event handling
 * - Backdrop click to close
 * - ESC key to close
 * - Focus trap
 * - Accessibility
 *
 * @requires DialogManager
 */
class Modal {
  /**
   * Create modal instance
   * @param {Object} options Modal options
   */
  constructor (options = {}) {
    this.options = {
      id: null, // Modal ID
      title: '', // Modal title
      content: '', // Modal content
      closeButton: true,
      animation: true,
      backdrop: true,
      keyboard: true,
      focus: true,
      className: '',
      titleClass: '',
      onShow: null,
      onShown: null,
      onHide: null,
      onHidden: null,
      backdropOpacity: 0.5,
      backdropColor: 'rgba(0,0,0,0.5)',
      ...options
    };

    this.id = this.options.id || 'modal_' + Math.random().toString(36).substr(2, 9);
    this.backdropId = null; // Store backdrop ID
    this.visible = false;
    this.boundData = {}; // Store bound data for data binding
    this.createModal();
    this.bindEvents();
  }

  /**
   * Create modal DOM structure
   * @private
   */
  createModal() {
    // Create modal elements
    this.modal = document.createElement('div');
    this.modal.id = this.id;
    this.modal.className = `modal ${this.options.className}`.trim();
    this.modal.setAttribute('role', 'dialog');
    this.modal.setAttribute('aria-modal', 'true');
    this.modal.setAttribute('aria-hidden', 'true');
    this.modal.inert = true; // Initially inert (prevent focus and interactions)

    // Modal dialog
    this.dialog = document.createElement('div');
    this.dialog.className = 'modal-dialog';

    // Modal header
    if (this.options.title) {
      this.header = document.createElement('div');
      this.header.className = 'modal-header';
      this.header.innerHTML = `
        <h4 class="modal-title ${this.options.titleClass}">${this.options.title}</h4>
        ${this.options.closeButton ? '<button type="button" class="modal-close" aria-label="Close"></button>' : ''}
      `;
      this.dialog.appendChild(this.header);
    }

    // Modal body
    this.body = document.createElement('div');
    this.body.className = 'modal-body';
    this.body.innerHTML = this.options.content;
    this.dialog.appendChild(this.body);

    // Add dialog to modal
    this.modal.appendChild(this.dialog);

    // Add to document
    document.body.appendChild(this.modal);
  }

  /**
   * Bind modal events
   * @private
   */
  bindEvents() {
    window.setTimeout(() => {
      // Close button click
      this._bindCloseButtons(this.modal);
    }, 100);

    // ESC key
    if (this.options.keyboard) {
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && this.visible) {
          this.hide();
        }
      });
    }

    // Focus trap
    if (this.options.focus) {
      this.modal.addEventListener('keydown', (e) => {
        if (e.key === 'Tab') {
          const focusable = this.modal.querySelectorAll(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
          );
          const firstFocusable = focusable[0];
          const lastFocusable = focusable[focusable.length - 1];

          if (e.shiftKey) {
            if (document.activeElement === firstFocusable) {
              lastFocusable.focus();
              e.preventDefault();
            }
          } else {
            if (document.activeElement === lastFocusable) {
              firstFocusable.focus();
              e.preventDefault();
            }
          }
        }
      });
    }
  }

  /**
   * Show modal
   */
  show() {
    if (this.visible) return;

    // Show backdrop first
    this.backdropId = BackdropManager.show(this.modal, () => {
      // Close on backdrop click if enabled
      if (this.options.backdrop) {
        this.hide();
      }
    }, {
      opacity: this.options.backdropOpacity,
      background: this.options.backdropColor
    });

    // Call onShow callback
    if (typeof this.options.onShow === 'function') {
      this.options.onShow.call(this);
    }

    // Show modal and remove inert
    this.modal.setAttribute('aria-hidden', 'false');
    this.modal.inert = false; // Allow interactions
    document.body.classList.add('modal-open');

    if (this.options.animation) {
      this.modal.classList.add('fade-in');
      setTimeout(() => {
        this.modal.classList.add('show');

        // Call onShown callback
        if (typeof this.options.onShown === 'function') {
          this.options.onShown.call(this);
        }
      }, 150);
    } else {
      this.modal.classList.add('show');
      if (typeof this.options.onShown === 'function') {
        this.options.onShown.call(this);
      }
    }

    this.visible = true;

    // Focus first focusable element
    if (this.options.focus) {
      const focusable = this.modal.querySelector(
        'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
      );
      focusable?.focus();
    }
  }

  /**
   * Hide modal
   */
  hide() {
    if (!this.visible) return;

    // Hide backdrop
    if (this.backdropId !== null) {
      BackdropManager.hide(this.backdropId);
      this.backdropId = null;
    }

    // Call onHide callback
    if (typeof this.options.onHide === 'function') {
      this.options.onHide.call(this);
    }

    // Remove focus from any element inside modal before hiding
    const focusedElement = this.modal.querySelector(':focus');
    if (focusedElement) {
      focusedElement.blur();
    }

    // Hide modal and make it inert
    this.modal.setAttribute('aria-hidden', 'true');
    this.modal.inert = true; // Prevent all interactions
    document.body.classList.remove('modal-open');

    if (this.options.animation) {
      this.modal.classList.remove('fade-in');
      this.modal.classList.add('fade-out');
    } else {
      this.modal.classList.remove('show');
      if (typeof this.options.onHidden === 'function') {
        this.options.onHidden.call(this);
      }
    }

    this.visible = false;

    // Cleanup elements and clear content after hide animation
    setTimeout(() => {
      this._cleanupModalElements();
      this.body.innerHTML = '';

      if (this.options.animation) {
        this.modal.classList.remove('show', 'fade-out');

        // Call onHidden callback
        if (typeof this.options.onHidden === 'function') {
          this.options.onHidden.call(this);
        }
      }
    }, 150);
  }

  /**
   * Cleanup modal elements before clearing content
   * @private
   */
  _cleanupModalElements() {
    // Phase 1: Cleanup forms (form will cleanup its elements)
    if (window.FormManager && typeof window.FormManager.destroyContainer === 'function') {
      window.FormManager.destroyContainer(this.body);
    }

    // Phase 2: Cleanup any standalone elements (not in forms)
    if (window.ElementManager && typeof window.ElementManager.destroyContainer === 'function') {
      window.ElementManager.destroyContainer(this.body);
    }
  }

  /**
   * Update modal content
   * @param {string} content New content
   */
  setContent(content) {
    // Cleanup existing elements only if there's existing content
    if (this.body.children.length > 0) {
      this._cleanupModalElements();
      this.body.innerHTML = '';
    }

    // Handle different content types
    if (content instanceof DocumentFragment) {
      // DocumentFragment: append it directly
      this.body.appendChild(content);
    } else if (content instanceof Node) {
      // DOM Node: append it directly
      this.body.appendChild(content);
    } else {
      // String: set as innerHTML
      this.body.innerHTML = content;
    }

    // Re-bind close buttons for new content
    this._bindCloseButtons(this.body);

    // Scan for elements and forms AFTER content is in DOM
    // This is needed because template processing skips scan for non-connected elements
    this._scanModalElements();
  }

  /**
   * Scan modal body for elements and forms that need enhancement
   * @private
   */
  _scanModalElements() {
    try {
      const elementManager = window.ElementManager || window.Now?.getManager?.('element');
      const formManager = window.FormManager || window.Now?.getManager?.('form');

      if (elementManager && typeof elementManager.scan === 'function') {
        elementManager.scan(this.body);
      }

      if (formManager && typeof formManager.scan === 'function') {
        formManager.scan(this.body);
      }
    } catch (err) {
      console.warn('Modal: Failed to scan elements', err);
    }
  }

  /**
   * Update modal title
   * @param {string} title New title
   */
  setTitle(title) {
    const titleEl = this.modal.querySelector('.modal-title');
    if (titleEl) {
      titleEl.innerHTML = title;
    }
  }

  /**
   * Bind data to modal elements
   * @param {Object} data Data object to bind
   * @returns {Modal} Returns this for chaining
   */
  bindData(data) {
    if (!data || typeof data !== 'object') return this;

    // Store bound data
    this.boundData = {...this.boundData, ...data};

    // Bind data to elements with data-modal-target attribute
    for (const [key, value] of Object.entries(data)) {
      const targets = this.modal.querySelectorAll(`[data-modal-target="${key}"]`);
      targets.forEach(target => {
        this._setElementContent(target, value, key);
      });
    }

    return this;
  }

  /**
   * Update existing bound data
   * @param {Object} data New data to merge and bind
   * @returns {Modal} Returns this for chaining
   */
  updateData(data) {
    return this.bindData(data);
  }

  /**
   * Get currently bound data
   * @returns {Object} Bound data object
   */
  getData() {
    return {...this.boundData};
  }

  /**
   * Set element content based on type
   * @param {HTMLElement} element Target element
   * @param {*} value Value to set
   * @param {string} key Data key for context
   * @private
   */
  _setElementContent(element, value, key) {
    const tagName = element.tagName.toLowerCase();
    const sanitizedValue = this._sanitizeValue(value, key);

    // Image elements
    if (tagName === 'img') {
      element.src = sanitizedValue;
      if (!element.alt) {
        element.alt = key;
      }
      return;
    }

    // Link elements
    if (tagName === 'a') {
      element.href = sanitizedValue;
      return;
    }

    // Input elements
    if (tagName === 'input' || tagName === 'textarea') {
      element.value = sanitizedValue;
      return;
    }

    // Video/Audio
    if (tagName === 'video' || tagName === 'audio') {
      element.src = sanitizedValue;
      return;
    }

    // Source elements
    if (tagName === 'source') {
      element.src = sanitizedValue;
      return;
    }

    // Check for data-modal-html attribute for HTML content
    if (element.hasAttribute('data-modal-html')) {
      element.innerHTML = this._sanitizeHTML(sanitizedValue);
      return;
    }

    // Default: set text content
    element.textContent = sanitizedValue;
  }

  /**
   * Sanitize value based on context
   * @param {*} value Value to sanitize
   * @param {string} context Context key
   * @returns {string} Sanitized value
   * @private
   */
  _sanitizeValue(value, context) {
    if (value === null || value === undefined) return '';

    // Convert to string
    const strValue = String(value);

    // URL validation for URL-like contexts
    if (context.includes('url') || context.includes('src') || context.includes('href')) {
      return this._sanitizeURL(strValue);
    }

    // Text sanitization (escape HTML)
    return this._sanitizeText(strValue);
  }

  /**
   * Sanitize text content (escape HTML entities)
   * @param {string} text Text to sanitize
   * @returns {string} Sanitized text
   * @private
   */
  _sanitizeText(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  /**
   * Sanitize HTML content
   * @param {string} html HTML to sanitize
   * @returns {string} Sanitized HTML
   * @private
   */
  _sanitizeHTML(html) {
    // Use DOMPurify if available
    if (window.DOMPurify) {
      return DOMPurify.sanitize(html);
    }

    // Basic sanitization
    const div = document.createElement('div');
    div.innerHTML = html;

    // Remove script tags
    div.querySelectorAll('script').forEach(el => el.remove());

    // Remove event handlers
    div.querySelectorAll('*').forEach(el => {
      Array.from(el.attributes).forEach(attr => {
        if (attr.name.startsWith('on')) {
          el.removeAttribute(attr.name);
        }
      });
    });

    return div.innerHTML;
  }

  /**
   * Sanitize URL
   * @param {string} url URL to sanitize
   * @returns {string} Sanitized URL
   * @private
   */
  _sanitizeURL(url) {
    if (!url) return '';

    try {
      const parsed = new URL(url, window.location.origin);

      // Allow only safe protocols
      const safeProtocols = ['http:', 'https:', 'mailto:', 'tel:', 'data:'];
      if (!safeProtocols.includes(parsed.protocol)) {
        return '#';
      }

      return parsed.href;
    } catch (error) {
      // Invalid URL - check if it's a relative path
      if (url.startsWith('/') || url.startsWith('./') || url.startsWith('../')) {
        return url;
      }
      return '#';
    }
  }

  /**
   * Bind close buttons in container
   * @param {HTMLElement} container Container to search for close buttons
   * @private
   */
  _bindCloseButtons(container) {
    if (!container) return;

    const closeBtns = container.querySelectorAll('.modal-close');
    closeBtns.forEach(btn => {
      // Remove existing listener to prevent duplicates if called multiple times
      // Note: This only works if we had a reference to the exact function,
      // but since we use an arrow function in addEventListener, we can't easily remove it.
      // However, for this specific case, we can check if we already processed it
      // or just rely on the fact that we are replacing content usually.

      // Better approach: Clone and replace to strip listeners, or just add new one
      // Since we are mostly calling this on new content, simple add is fine.
      // But to be safe against double-binding on static parts:

      const newBtn = btn.cloneNode(true);
      if (btn.parentNode) {
        btn.parentNode.replaceChild(newBtn, btn);
        newBtn.addEventListener('click', (e) => {
          e.preventDefault(); // Prevent form submission if it's a submit button
          this.hide();
        });
      }
    });
  }

  /**
   * Remove modal from DOM
   */
  destroy() {
    this.modal.remove();
    this.boundData = {};
  }
}

// Register with framework
if (window.Now?.registerManager) {
  Now.registerManager('modal', Modal);
}

// Expose globally
window.Modal = Modal;
