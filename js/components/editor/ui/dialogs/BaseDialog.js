/**
 * BaseDialog - Base class for modal dialogs
 * Provides common functionality for all editor dialogs
 *
 * @author Goragod Wiriya
 * @version 1.0
 */
import EventBus from '../../core/EventBus.js';

class BaseDialog {
  /**
   * @param {RichTextEditor} editor - Editor instance
   * @param {Object} options - Dialog options
   */
  constructor(editor, options = {}) {
    this.editor = editor;
    this.options = {
      title: 'Dialog',
      width: 450,
      closable: true,
      closeOnOverlay: true,
      closeOnEscape: true,
      ...options
    };

    this.overlay = null;
    this.dialog = null;
    this.isOpen = false;
    this.savedSelection = null;

    this.handleKeyDown = this.handleKeyDown.bind(this);
    this.handleOverlayClick = this.handleOverlayClick.bind(this);
  }

  /**
   * Translate text
   * @param {string} key - Translation key
   * @param {Object} params - Parameters
   * @returns {string}
   */
  translate(key, params = {}) {
    if (window.translate) {
      return window.translate(key, params);
    }
    return key;
  }

  /**
   * Create dialog DOM structure
   * @returns {HTMLElement}
   */
  create() {
    // Overlay
    this.overlay = document.createElement('div');
    this.overlay.className = 'rte-dialog-overlay';

    // Dialog container
    this.dialog = document.createElement('div');
    this.dialog.className = 'rte-dialog';
    this.dialog.style.width = `${this.options.width}px`;
    this.dialog.setAttribute('role', 'dialog');
    this.dialog.setAttribute('aria-modal', 'true');
    this.dialog.setAttribute('aria-labelledby', 'rte-dialog-title');

    // Header
    const header = document.createElement('div');
    header.className = 'rte-dialog-header';

    const title = document.createElement('h3');
    // Use data attribute instead of id to avoid duplicate ids when multiple
    // dialogs exist in the DOM simultaneously.
    title.dataset.rtePart = 'title';
    title.className = 'rte-dialog-title';
    title.textContent = window.translate?.(this.options.title) || this.options.title;
    header.appendChild(title);

    if (this.options.closable) {
      const closeBtn = document.createElement('button');
      closeBtn.type = 'button';
      closeBtn.className = 'rte-dialog-close';
      closeBtn.innerHTML = '&times;';
      closeBtn.setAttribute('aria-label', window.translate?.('Close') || 'Close');
      closeBtn.addEventListener('click', () => this.close());
      header.appendChild(closeBtn);
    }

    // Body (to be filled by subclasses)
    this.body = document.createElement('div');
    this.body.className = 'rte-dialog-body';

    // Footer
    this.footer = document.createElement('div');
    this.footer.className = 'rte-dialog-footer';

    // Assemble dialog
    this.dialog.appendChild(header);
    this.dialog.appendChild(this.body);
    this.dialog.appendChild(this.footer);
    this.overlay.appendChild(this.dialog);

    return this.overlay;
  }

  /**
   * Build dialog body content (override in subclass)
   */
  buildBody() {
    // Override in subclass
  }

  /**
   * Build dialog footer (override in subclass)
   */
  buildFooter() {
    // Default cancel and confirm buttons
    const cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'rte-dialog-btn rte-dialog-btn-cancel';
    cancelBtn.textContent = window.translate?.('Cancel') || 'Cancel';
    cancelBtn.addEventListener('click', () => this.close());

    const confirmBtn = document.createElement('button');
    confirmBtn.type = 'button';
    confirmBtn.className = 'rte-dialog-btn rte-dialog-btn-primary';
    confirmBtn.textContent = window.translate?.('Confirm') || 'Confirm';
    confirmBtn.addEventListener('click', () => this.handleConfirm());

    this.footer.appendChild(cancelBtn);
    this.footer.appendChild(confirmBtn);

    this.confirmBtn = confirmBtn;
    this.cancelBtn = cancelBtn;
  }

  /**
   * Create form field
   * @param {Object} config - Field configuration
   * @returns {HTMLElement}
   */
  createField(config) {
    const field = document.createElement('div');
    field.className = 'rte-dialog-field';

    if (config.label) {
      const label = document.createElement('label');
      label.className = 'rte-dialog-label';
      label.textContent = window.translate?.(config.label) || config.label;
      if (config.id) {
        label.setAttribute('for', config.id);
      }
      field.appendChild(label);
    }

    let input;

    switch (config.type) {
      case 'text':
      case 'url':
      case 'email':
      case 'number':
        input = document.createElement('input');
        input.type = config.type;
        input.className = 'rte-dialog-input';
        break;

      case 'textarea':
        input = document.createElement('textarea');
        input.className = 'rte-dialog-textarea';
        input.rows = config.rows || 3;
        break;

      case 'select':
        input = document.createElement('select');
        input.className = 'rte-dialog-select';
        if (config.options) {
          config.options.forEach(opt => {
            const option = document.createElement('option');
            option.value = opt.value;
            option.textContent = window.translate?.(opt.label) || opt.label;
            input.appendChild(option);
          });
        }
        break;

      case 'checkbox':
        const checkWrapper = document.createElement('label');
        checkWrapper.className = 'rte-dialog-checkbox';
        input = document.createElement('input');
        input.type = 'checkbox';
        checkWrapper.appendChild(input);
        const checkLabel = document.createElement('span');
        checkLabel.textContent = window.translate?.(config.checkLabel) || config.checkLabel || '';
        checkWrapper.appendChild(checkLabel);
        field.appendChild(checkWrapper);
        break;

      default:
        input = document.createElement('input');
        input.type = 'text';
        input.className = 'rte-dialog-input';
    }

    if (input && config.type !== 'checkbox') {
      if (config.id) input.id = config.id;
      if (config.name) input.name = config.name;
      if (config.placeholder) input.placeholder = window.translate?.(config.placeholder) || config.placeholder;
      if (config.value !== undefined) input.value = config.value;
      if (config.required) input.required = true;
      if (config.pattern) input.pattern = config.pattern;

      field.appendChild(input);
    } else if (config.type === 'checkbox' && input) {
      if (config.id) input.id = config.id;
      if (config.name) input.name = config.name;
      if (config.checked) input.checked = true;
    }

    if (config.help) {
      const help = document.createElement('div');
      help.className = 'rte-dialog-help';
      help.textContent = window.translate?.(config.help) || config.help;
      field.appendChild(help);
    }

    return field;
  }

  /**
   * Get input element from field
   * @param {HTMLElement} field
   * @returns {HTMLInputElement|HTMLTextAreaElement|HTMLSelectElement}
   */
  getInputFromField(field) {
    return field.querySelector('input, textarea, select');
  }

  /**
   * Open the dialog
   * @param {Object} data - Initial data for the dialog
   */
  open(data = {}) {
    if (this.isOpen) return;

    // Save selection before opening
    this.savedSelection = this.editor.selection?.saveSelection();

    // Create dialog if not exists
    if (!this.overlay) {
      this.create();
      this.buildBody();
      this.buildFooter();
    }

    // Populate with data
    this.populate(data);

    // Add to DOM
    document.body.appendChild(this.overlay);

    // Show with animation
    requestAnimationFrame(() => {
      this.overlay.classList.add('open');
      this.dialog.classList.add('open');
    });

    // Add event listeners
    if (this.options.closeOnOverlay) {
      this.overlay.addEventListener('click', this.handleOverlayClick);
    }
    if (this.options.closeOnEscape) {
      document.addEventListener('keydown', this.handleKeyDown);
    }

    this.isOpen = true;

    // Focus first input
    this.focusFirst();

    // Emit event
    this.editor.events?.emit(EventBus.Events.DIALOG_OPEN, {dialog: this});
  }

  /**
   * Close the dialog
   */
  close() {
    if (!this.isOpen) return;

    // Hide with animation
    this.overlay.classList.remove('open');
    this.dialog.classList.remove('open');

    // Remove from DOM after animation
    setTimeout(() => {
      if (this.overlay && this.overlay.parentNode) {
        this.overlay.parentNode.removeChild(this.overlay);
      }
    }, 200);

    // Remove event listeners
    this.overlay.removeEventListener('click', this.handleOverlayClick);
    document.removeEventListener('keydown', this.handleKeyDown);

    this.isOpen = false;

    // Restore selection
    if (this.savedSelection) {
      this.editor.selection.savedSelection = this.savedSelection;
      this.editor.selection.restoreSelection();
      this.savedSelection = null;
    }

    // Emit event
    this.editor.events?.emit(EventBus.Events.DIALOG_CLOSE, {dialog: this});
  }

  /**
   * Set the dialog title text
   * @param {string} text
   */
  setTitle(text) {
    const titleEl = this.dialog?.querySelector('[data-rte-part="title"]');
    if (titleEl) titleEl.textContent = text;
  }

  /**
   * Populate dialog with data (override in subclass)
   * @param {Object} data
   */
  populate(data) {
    // Override in subclass
  }

  /**
   * Get data from dialog (override in subclass)
   * @returns {Object}
   */
  getData() {
    // Override in subclass
    return {};
  }

  /**
   * Validate dialog data (override in subclass)
   * @returns {boolean}
   */
  validate() {
    // Override in subclass
    return true;
  }

  /**
   * Handle confirm button click
   */
  handleConfirm() {
    if (!this.validate()) return;

    const data = this.getData();
    this.onConfirm(data);
    this.close();
  }

  /**
   * Called when dialog is confirmed (override in subclass)
   * @param {Object} data
   */
  onConfirm(data) {
    // Override in subclass
  }

  /**
   * Handle overlay click
   * @param {MouseEvent} event
   */
  handleOverlayClick(event) {
    if (event.target === this.overlay) {
      this.close();
    }
  }

  /**
   * Handle keydown
   * @param {KeyboardEvent} event
   */
  handleKeyDown(event) {
    if (event.key === 'Escape') {
      event.preventDefault();
      this.close();
    } else if (event.key === 'Enter' && !event.shiftKey) {
      // Submit on Enter (unless in textarea)
      if (event.target.tagName !== 'TEXTAREA') {
        event.preventDefault();
        this.handleConfirm();
      }
    }
  }

  /**
   * Focus first input in dialog
   */
  focusFirst() {
    const firstInput = this.body.querySelector('input, textarea, select');
    if (firstInput) {
      firstInput.focus();
      if (firstInput.select) {
        firstInput.select();
      }
    }
  }

  /**
   * Show error message
   * @param {string} message
   * @param {HTMLElement} field - Field to highlight
   */
  showError(message, field = null) {
    // Remove existing error
    this.clearError();

    // Create error element
    const error = document.createElement('div');
    error.className = 'rte-dialog-error';
    error.textContent = window.translate?.(message) || message;

    if (field) {
      field.classList.add('error');
      field.appendChild(error);
    } else {
      this.body.insertBefore(error, this.body.firstChild);
    }
  }

  /**
   * Clear error messages
   */
  clearError() {
    const errors = this.body.querySelectorAll('.rte-dialog-error');
    errors.forEach(e => e.remove());

    const errorFields = this.body.querySelectorAll('.error');
    errorFields.forEach(f => f.classList.remove('error'));
  }

  /**
   * Set button loading state
   * @param {boolean} loading
   */
  setLoading(loading) {
    if (this.confirmBtn) {
      this.confirmBtn.disabled = loading;
      this.confirmBtn.classList.toggle('loading', loading);
    }
  }

  /**
   * Destroy dialog
   */
  destroy() {
    this.close();
    this.overlay = null;
    this.dialog = null;
    this.body = null;
    this.footer = null;
  }
}

export default BaseDialog;
