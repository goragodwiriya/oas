/**
 * EditInPlaceManager - Simplified Inline Editing
 *
 * Features:
 * - Click to edit text inline
 * - Only saves when value actually changes
 * - Auto-restore on error or use server value
 * - Simple text input only (no complex types)
 * - Blur to save, Escape to cancel
 *
 * Security Features:
 * - Uses HttpClient with automatic CSRF protection (via SecurityManager)
 * - Rate limiting through HttpClient interceptors
 * - XSS protection: uses textContent (not innerHTML)
 * - Sanitizes error messages to prevent information disclosure
 * - Input validation on field/id parameters
 * - Event listener cleanup to prevent memory leaks
 */
const EditInPlaceManager = {
  config: {
    className: 'edit-in-place',
    activeClass: 'editing',
    selectOnEdit: true,
    callbacks: {}
  },

  state: {
    instances: new Map(),
    activeEditor: null,
    initialized: false
  },

  init(options = {}) {
    if (this.state.initialized) return this;

    this.config = {...this.config, ...options};

    // Initialize any existing elements with data-component="editinplace"
    document.querySelectorAll('[data-component="editinplace"]').forEach(element => {
      this.create(element);
    });

    this.state.initialized = true;
    return this;
  },

  create(element, options = {}) {
    if (typeof element === 'string') {
      element = document.getElementById(element);
    }

    if (!element || element.editInPlace) return element?.editInPlace;

    // Extract config from data attributes
    const ajaxUrl = element.dataset.editAjaxUrl || options.ajaxUrl || null;
    const callbackName = element.dataset.callback || options.callback || null;

    // Validate field and id to prevent injection
    const field = (element.dataset.field || element.id || '').replace(/[^a-zA-Z0-9_-]/g, '');

    if (ajaxUrl && !field) {
      console.warn('EditInPlace: field is required when using AJAX save');
    }

    const config = {
      ...this.config,
      ajaxUrl: ajaxUrl,
      callback: callbackName,
      // Auto-enable AJAX if URL is provided, unless explicitly disabled
      ajaxSave: element.dataset.editAjaxSave === 'false'
        ? false
        : (element.dataset.editAjaxSave === 'true' || !!ajaxUrl),
      field: field,
      ...options
    };

    const instance = {
      element,
      config,
      originalValue: element.textContent.trim(),
      editor: null,
      isEditing: false
    };

    element.editInPlace = instance;
    this.state.instances.set(element, instance);
    this.setupElement(instance);

    return instance;
  },

  setupElement(instance) {
    const {element, config} = instance;

    element.classList.add(config.className);
    element.style.cursor = 'pointer';
    element.tabIndex = 0;

    // Store event handlers for cleanup
    instance.handlers = {
      click: (e) => {
        e.stopPropagation();
        this.startEdit(instance);
      },
      keydown: (e) => {
        if (!instance.isEditing && (e.key === 'Enter' || e.key === ' ')) {
          e.preventDefault();
          this.startEdit(instance);
        }
      }
    };

    // Attach event listeners
    element.addEventListener('click', instance.handlers.click);
    element.addEventListener('keydown', instance.handlers.keydown);

    // Accessibility
    element.setAttribute('role', 'button');
  },

  startEdit(instance) {
    if (instance.isEditing) return;

    // Cancel any other active editing
    if (this.state.activeEditor && this.state.activeEditor !== instance) {
      this.endEdit(this.state.activeEditor, false);
    }

    const {element, config} = instance;

    // Store current value as original
    instance.originalValue = element.textContent.trim();

    // Store validation rules that need manual checking
    instance.validation = {
      required: element.hasAttribute('data-required'),
      pattern: element.getAttribute('data-pattern') || null
    };

    // Create simple text input
    const editor = document.createElement('input');
    editor.type = 'text';
    editor.value = instance.originalValue;
    editor.className = `${config.className}-editor`;

    // Set HTML5 attributes that browser can handle
    const placeholder = element.getAttribute('data-placeholder');
    const minlength = element.getAttribute('data-minlength');
    const maxlength = element.getAttribute('data-maxlength');

    if (placeholder) editor.placeholder = placeholder;
    if (minlength) editor.minLength = parseInt(minlength);
    if (maxlength) editor.maxLength = parseInt(maxlength);

    // Match element's styling
    const computedStyle = window.getComputedStyle(element);
    editor.style.fontSize = computedStyle.fontSize;
    editor.style.fontFamily = computedStyle.fontFamily;

    // Replace element with editor (with safety check)
    if (!element.parentNode) {
      console.error('EditInPlace: Element has no parent node');
      return;
    }

    element.style.display = 'none';
    element.parentNode.insertBefore(editor, element);

    // Set state
    instance.isEditing = true;
    instance.editor = editor;
    this.state.activeEditor = instance;

    // Focus and select
    editor.focus();
    if (config.selectOnEdit) {
      editor.select();
    }

    // Event handlers
    const handleBlur = () => {
      if (instance.isEditing) {
        this.finishEdit(instance);
      }
    };

    const handleKeydown = (e) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        this.endEdit(instance, false); // Cancel - restore original
      } else if (e.key === 'Enter') {
        e.preventDefault();
        editor.blur(); // Trigger blur which will save
      }
    };

    // Store handlers for cleanup
    instance.editorHandlers = {blur: handleBlur, keydown: handleKeydown};

    editor.addEventListener('blur', handleBlur);
    editor.addEventListener('keydown', handleKeydown);

    // Emit start event
    this.emitEvent('edit:start', {instance, element, editor});
  },

  async finishEdit(instance) {
    if (!instance.isEditing) return;

    const {element, config, editor, originalValue} = instance;
    const newValue = editor.value.trim();

    // Check if value actually changed
    if (newValue === originalValue) {
      // No change, just close editor silently (no cancel event)
      this.endEdit(instance, {restore: false, emitCancel: false});
      return;
    }

    // Validate before saving
    const validationError = this.validateValue(newValue, instance.validation);
    if (validationError) {
      // Show error and restore original value
      if (window.NotificationManager) {
        NotificationManager.error(validationError);
      } else {
        alert(validationError);
      }
      this.endEdit(instance, {restore: true, emitCancel: true});
      return;
    }

    // Value changed - prepare to save
    let success = true;
    let finalValue = newValue;
    let savedViaAjax = false;

    // Call callback before save if configured
    if (config.callback) {
      try {
        const callbackFn = typeof config.callback === 'function'
          ? config.callback
          : window[config.callback];

        if (typeof callbackFn === 'function') {
          const result = callbackFn(newValue, instance);

          // If callback returns false, cancel the save
          if (result === false) {
            this.endEdit(instance, {restore: true, emitCancel: true});
            return;
          }

          // If callback returns a string, use it as the new value
          if (typeof result === 'string') {
            finalValue = result;
          }
        }
      } catch (error) {
        console.error('EditInPlace: Callback error:', error);
      }
    }

    // AJAX save if configured
    if (config.ajaxSave && config.ajaxUrl) {
      try {
        const formData = new FormData();
        formData.append('value', newValue);
        formData.append('field', config.field);

        let response;

        // Prefer HttpClient (with CSRF + Rate Limiting via SecurityManager)
        if (window.http?.post) {
          response = await http.post(config.ajaxUrl, formData);
        } else if (window.simpleFetch?.post) {
          response = await simpleFetch.post(config.ajaxUrl, formData);
        } else {
          // Fallback to raw fetch (no CSRF protection)
          const requestOptions = Now.applyRequestLanguage({
            method: 'POST',
            body: formData,
            headers: {'X-Requested-With': 'XMLHttpRequest'}
          });
          const fetchResponse = await fetch(config.ajaxUrl, requestOptions);
          response = await fetchResponse.json();
        }

        if (response.success === false || response.error) {
          throw new Error(response.message || 'Save failed');
        }

        savedViaAjax = true;

        // Use server-returned value if provided (for formatting)
        // HttpClient might wrap response in data.data
        let serverValue = response.data?.value || response.data?.data?.value;

        if (serverValue !== undefined) {
          finalValue = serverValue;
        }
      } catch (error) {
        console.error('EditInPlace save error:', error);

        // Show user-friendly error message (don't expose system details)
        const userMessage = error.status === 429
          ? 'Too many requests. Please wait a moment.'
          : error.status === 403
            ? 'Access denied. Please refresh the page.'
            : 'Failed to save changes.';

        if (window.NotificationManager) {
          NotificationManager.error(userMessage);
        }

        success = false;
        finalValue = originalValue; // Restore original on error
      }
    }

    // Update element with final value
    element.textContent = finalValue;

    // Close editor (value already updated, no need to restore)
    this.endEdit(instance, {restore: false, emitCancel: false});

    // Emit save event if value changed (whether via AJAX or local)
    if (success && newValue !== originalValue) {
      this.emitEvent('edit:save', {
        instance,
        element,
        value: finalValue,
        previousValue: originalValue,
        savedViaAjax: savedViaAjax
      });
    }
  },

  endEdit(instance, options = {}) {
    if (!instance.isEditing) return;

    const {restore = false, emitCancel = false} = options;

    instance.isEditing = false;

    const {element, editor} = instance;

    // Restore element visibility
    element.style.display = '';

    // Remove editor event listeners before removing element
    if (editor && instance.editorHandlers) {
      editor.removeEventListener('blur', instance.editorHandlers.blur);
      editor.removeEventListener('keydown', instance.editorHandlers.keydown);
      delete instance.editorHandlers;
    }

    // Remove editor safely
    if (editor && editor.parentNode) {
      try {
        editor.parentNode.removeChild(editor);
      } catch (e) {
        // Already removed
      }
    }

    instance.editor = null;

    if (this.state.activeEditor === instance) {
      this.state.activeEditor = null;
    }

    // Restore original value if requested
    if (restore) {
      element.textContent = instance.originalValue;
    }

    // Emit cancel only if explicitly cancelled
    if (emitCancel) {
      this.emitEvent('edit:cancel', {instance, element});
    }

    // Return focus to element
    element.focus();
  },

  validateValue(value, rules) {
    if (!rules) return null;

    // Check required (manual validation)
    if (rules.required && !value) {
      return 'This field is required';
    }

    // Check pattern (manual validation)
    if (rules.pattern && value) {
      try {
        const regex = new RegExp(`^${rules.pattern}$`);
        if (!regex.test(value)) {
          return 'Invalid format';
        }
      } catch (e) {
        console.error('EditInPlace: Invalid pattern regex:', e);
      }
    }

    return null; // No validation errors
  },

  cancelEdit(instance) {
    this.endEdit(instance, {restore: true, emitCancel: true});
  },

  emitEvent(eventName, data) {
    EventManager.emit(eventName, data);
  },

  getInstance(element) {
    if (typeof element === 'string') {
      element = document.getElementById(element);
    }
    return element?.editInPlace || null;
  },

  destroy(instance) {
    if (typeof instance === 'string') {
      instance = this.getInstance(instance);
    }
    if (!instance) return;

    const {element} = instance;

    if (instance.isEditing) {
      this.endEdit(instance, false);
    }

    // Remove event listeners
    if (instance.handlers) {
      element.removeEventListener('click', instance.handlers.click);
      element.removeEventListener('keydown', instance.handlers.keydown);
      delete instance.handlers;
    }

    element.classList.remove(this.config.className);
    element.style.cursor = '';
    element.removeAttribute('tabIndex');
    element.removeAttribute('role');

    delete element.editInPlace;
    this.state.instances.delete(element);
  }
};

// Register with Now framework
if (window.Now?.registerManager) {
  Now.registerManager('editInPlace', EditInPlaceManager);
}

window.EditInPlaceManager = EditInPlaceManager;

// Auto-initialize on DOM ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => EditInPlaceManager.init());
} else {
  EditInPlaceManager.init();
}