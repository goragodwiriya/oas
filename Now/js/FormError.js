class FormError {
  static config = {
    errorClass: 'invalid',
    errorMessageClass: 'error',
    autoFocus: true,
    autoScroll: true,
    scrollOffset: 100,
    showErrorsInline: true,
    showErrorsInNotification: false,
    autoClearErrors: true,
    autoClearErrorsDelay: 5000,
    defaultErrorContainer: 'form-message',
    defaultSuccessContainer: 'form-message'
  };

  static state = {
    errors: new Map(),
    lastFocusedElement: null,
    originalMessages: new Map(),
    originalGeneralMessages: new Map(),
  };

  static configure(options = {}) {
    this.config = {
      ...this.config,
      ...options
    };
  }

  static getFormConfig(form) {
    const formInstance = FormManager.getInstanceByElement(form);
    if (formInstance) {
      return {
        ...this.config,
        ...formInstance.config
      };
    }
    return this.config;
  }

  static resolveFieldElement(field, form = null) {
    if (field instanceof HTMLElement) {
      return field;
    }

    const root = form instanceof HTMLElement ? form : document;

    return document.getElementById(field)
      || root.querySelector(`[name="${field}"]`)
      || document.querySelector(`[name="${field}"]`);
  }

  /**
   * True when showFieldError can render text into a dedicated result node
   * (data-result or #result_{fieldKey}), matching showFieldError resolution rules.
   */
  static hasInlineErrorContainer(field, form = null) {
    const element = this.resolveFieldElement(field, form);
    if (!element) {
      return false;
    }
    const fieldKey = typeof field === 'string' ? field : (element.id || element.name || '');
    const resultId = element.dataset?.result || `result_${fieldKey}`;
    return !!document.getElementById(resultId);
  }

  static getFieldLabel(element, form = null) {
    if (!element) return '';

    if (window.FormManager && typeof FormManager.getFieldLabel === 'function') {
      return FormManager.getFieldLabel(element, form);
    }

    return element.name || element.id || '';
  }

  static normalizeMessageTokenValue(value) {
    if (value === null || value === undefined) return '';
    return String(value).trim();
  }

  static getMessageTokenMap(element, form = null) {
    if (!element) {
      return {};
    }

    return {
      label: this.normalizeMessageTokenValue(this.getFieldLabel(element, form)),
      placeholder: this.normalizeMessageTokenValue(element.getAttribute('placeholder') || element.placeholder || ''),
      title: this.normalizeMessageTokenValue(element.getAttribute('title') || element.title || ''),
      name: this.normalizeMessageTokenValue(element.name || ''),
      id: this.normalizeMessageTokenValue(element.id || ''),
      value: this.normalizeMessageTokenValue('value' in element ? element.value : element.getAttribute('value') || '')
    };
  }

  static getDataTokenValue(element, token) {
    if (!element || !token) return '';

    const normalizedToken = token.startsWith('data-') ? token.slice(5) : token;
    const datasetKey = normalizedToken.replace(/-([a-z])/g, (_, char) => char.toUpperCase());
    const datasetValue = element.dataset?.[datasetKey];
    if (datasetValue !== undefined && datasetValue !== null && datasetValue !== '') {
      return this.normalizeMessageTokenValue(datasetValue);
    }

    const attributeName = token.startsWith('data-') ? token : `data-${normalizedToken}`;
    const attributeValue = element.getAttribute?.(attributeName);
    if (attributeValue !== undefined && attributeValue !== null && attributeValue !== '') {
      return this.normalizeMessageTokenValue(attributeValue);
    }

    return '';
  }

  static resolveMessageTokens(message, element = null, form = null) {
    const translated = Now.translate(message);
    if (!element || typeof translated !== 'string' || translated.indexOf(':') === -1) {
      return translated;
    }

    const tokenMap = this.getMessageTokenMap(element, form);

    return translated.replace(/:([A-Za-z0-9_-]+)/g, (match, token) => {
      if (Object.prototype.hasOwnProperty.call(tokenMap, token)) {
        const value = tokenMap[token];
        return value !== '' ? value : match;
      }

      const dataValue = this.getDataTokenValue(element, token);
      return dataValue !== '' ? dataValue : match;
    });
  }

  static resolveFieldMessages(field, message, form = null) {
    const element = this.resolveFieldElement(field, form);
    const messages = Array.isArray(message) ? message : [message];

    return messages
      .map(item => this.resolveMessageTokens(item, element, form))
      .filter(item => item !== null && item !== undefined && item !== '');
  }

  static showSuccess(message, form = null, options = {}) {
    let config = this.config;
    let container = null;

    if (form instanceof HTMLElement) {
      config = this.getFormConfig(form);

      if (config.successContainer) {
        container = document.getElementById(config.successContainer);
      } else {
        container = form.querySelector('[data-success-container], .form-message, .success-message, .login-message');
      }
    } else if (typeof form === 'string') {
      container = document.getElementById(form);
    }

    if (!container) {
      container = document.getElementById(config.defaultSuccessContainer);
    }

    if (!container) return;

    const containerId = container.id || Utils.generateUUID();
    container.id = containerId;
    if (!this.state.originalGeneralMessages.get(containerId)) {
      // Store original content
      this.state.originalGeneralMessages.set(containerId, {
        html: container.innerHTML || '',
        className: container.className || ''
      });
    }

    container.textContent = Now.translate(message);
    container.classList.add('success');

    if (config.autoClearErrors && config.autoClearErrorsDelay > 0) {
      setTimeout(() => {
        // restore (clear) using container id
        this.clearGeneralError(containerId);
      }, config.autoClearErrorsDelay);
    }

    EventManager.emit('form:generalSuccess', {message, containerId, config});
  }

  static showGeneralError(message, form = null, options = {}) {
    let config = this.config;
    let container = null;

    if (form instanceof HTMLElement) {
      config = this.getFormConfig(form);

      if (config.errorContainer) {
        container = document.getElementById(config.errorContainer);
      } else {
        container = form.querySelector('[data-error-container], .form-message, .error-message, .login-message');
      }
    } else if (typeof form === 'string') {
      container = document.getElementById(form);
    }

    if (!container) {
      container = document.getElementById(config.defaultErrorContainer);
    }

    if (!container) return;

    const containerId = container.id || Utils.generateUUID();
    container.id = containerId;
    if (!this.state.originalGeneralMessages.get(containerId)) {
      // Store original content
      this.state.originalGeneralMessages.set(containerId, {
        html: container.innerHTML || '',
        className: container.className || ''
      });
    }

    const errorClass = config.errorMessageClass || 'error';
    container.classList.add(errorClass);
    container.textContent = Now.translate(message);

    if (config.autoClearErrors && config.autoClearErrorsDelay > 0) {
      setTimeout(() => {
        // restore (clear) using container id
        this.clearGeneralError(containerId);
      }, config.autoClearErrorsDelay);
    }

    EventManager.emit('form:generalError', {message, containerId, config});
  }

  static showFieldError(field, message, form = null, options = {}) {
    const element = this.resolveFieldElement(field, form);
    const fieldKey = typeof field === 'string' ? field : (element?.id || element?.name || '');
    const messages = this.resolveFieldMessages(field, message, form);

    if (!element) return messages;

    let config = this.config;
    if (form instanceof HTMLElement) {
      config = this.getFormConfig(form);
    }

    if (messages.length === 0) return;

    // Determine result element ID - check data-result attribute first, fallback to result_{field}
    const resultId = element.dataset.result || `result_${fieldKey}`;

    if (!this.state.errors.has(fieldKey)) {
      const comment = document.getElementById(resultId);
      if (comment && !this.state.originalMessages.has(fieldKey)) {
        // Store original message info
        const hasI18n = comment.hasAttribute('data-i18n');
        const i18nValue = comment.dataset?.i18n;
        let i18nKey = null;

        if (hasI18n) {
          // If data-i18n has value, use it; otherwise textContent is the key
          i18nKey = i18nValue && i18nValue.trim() !== '' ? i18nValue.trim() : comment.textContent.trim();
        }

        this.state.originalMessages.set(fieldKey, {
          text: comment.textContent,
          i18nKey: i18nKey,
          hasI18nAttr: hasI18n
        });
      }
    }

    this.state.errors.set(fieldKey, {
      element,
      messages
    });

    const errorClass = config.errorClass || 'invalid';

    // Add error class to element
    element.classList.add(errorClass);

    // Add error class to element.container (from ElementFactory)
    element.container?.classList.add(errorClass);

    // Add error class to element.wrapper (from ElementFactory)
    if (element.wrapper && element.wrapper !== element.container) {
      element.wrapper.classList?.add(errorClass);
    }

    // Also check for form-control parent (fallback for non-enhanced elements)
    try {
      let formControlParent = null;
      if (element.parentElement && element.parentElement.classList && element.parentElement.classList.contains('form-control')) {
        formControlParent = element.parentElement;
      } else if (element.closest) {
        formControlParent = element.closest('.form-control');
      }

      if (formControlParent && formControlParent !== element.container && formControlParent !== element.wrapper) {
        formControlParent.classList.add(errorClass);
      }
    } catch (e) {}

    // Show error message in result element if it exists
    const comment = document.getElementById(resultId);
    if (comment) {
      comment.textContent = messages[0];
      comment.classList.add(config.errorMessageClass || 'error');
      element.setAttribute('aria-errormessage', comment.id);
    }

    element.setAttribute('aria-invalid', 'true');

    EventManager.emit('form:error', {field: fieldKey, messages, element, config});

    return messages;
  }

  static clearFieldError(field) {
    const element = typeof field === 'string'
      ? document.getElementById(field) || document.querySelector(`[name="${field}"]`)
      : field;

    if (!element) return;

    const elementId = element.id || element.name;
    const errorClass = this.config.errorClass;

    // Remove error class from element
    element.classList.remove(errorClass);

    // Remove error class from element.container (from ElementFactory)
    element.container?.classList.remove(errorClass);

    // Remove error class from element.wrapper (from ElementFactory)
    if (element.wrapper && element.wrapper !== element.container) {
      element.wrapper.classList?.remove(errorClass);
    }

    // Also check for form-control parent (fallback for non-enhanced elements)
    try {
      let formControlParent = null;
      if (element.parentElement && element.parentElement.classList && element.parentElement.classList.contains('form-control')) {
        formControlParent = element.parentElement;
      } else if (element.closest) {
        formControlParent = element.closest('.form-control');
      }

      if (formControlParent && formControlParent !== element.container && formControlParent !== element.wrapper) {
        formControlParent.classList.remove(errorClass);
      }
    } catch (e) {}

    // Determine result element ID - check data-result attribute first, fallback to result_{elementId}
    const resultId = element.dataset?.result || `result_${elementId}`;
    const comment = document.getElementById(resultId);
    if (comment) {
      comment.classList.remove(this.config.errorMessageClass);

      // Only restore original message if this field actually had an error
      // Don't clear text if there was no error to clear (prevents data loss)
      if (this.state.errors.has(elementId) && this.state.originalMessages.has(elementId)) {
        const original = this.state.originalMessages.get(elementId);
        // Restore both i18n attribute and translated text
        if (original.hasI18nAttr && original.i18nKey) {
          // Restore data-i18n attribute (always use the stored i18nKey)
          comment.dataset.i18n = original.i18nKey;
          comment.textContent = Now.translate(original.i18nKey);
        } else if (original.hasI18nAttr) {
          // Had data-i18n but no key (shouldn't happen but handle it)
          comment.dataset.i18n = '';
          comment.textContent = original.text || '';
        } else {
          // No data-i18n originally
          delete comment.dataset.i18n;
          comment.textContent = original.text || '';
        }
        this.state.originalMessages.delete(elementId);
      }
    }

    element.removeAttribute('aria-invalid');
    element.removeAttribute('aria-errormessage');

    // Clean up state if error was tracked
    if (this.state.errors.has(elementId)) {
      this.state.errors.delete(elementId);
    }
    if (this.state.lastFocusedElement === element) {
      this.state.lastFocusedElement = null;
    }

    EventManager.emit('form:clearError', {field: elementId, element});
  }

  /**
   * Clear general error message
   * @param {string|HTMLElement} containerOrForm - Container ID, container element, or form element
   */
  static clearGeneralError(containerOrForm = null) {
    let container = null;
    let config = this.config;

    if (typeof containerOrForm === 'string') {
      container = document.getElementById(containerOrForm);
    } else if (containerOrForm instanceof HTMLElement) {
      if (containerOrForm.tagName === 'FORM') {
        config = this.getFormConfig(containerOrForm);

        if (config.errorContainer) {
          container = document.getElementById(config.errorContainer);
        }
      } else {
        container = document.getElementById(containerOrForm.id);
      }
    } else {
      container = document.getElementById(config.defaultErrorContainer);
    }

    if (!container) {
      const containers = document.querySelectorAll('[data-error-container], .form-message, .error-message, .login-message');
      if (containers.length > 0) {
        container = containers[0];
      }
    }

    if (!container) return false;

    const containerId = container.id || Utils.generateUUID();
    container.id = containerId;
    const original = this.state.originalGeneralMessages.get(containerId);
    if (original) {
      // Restore original content
      container.innerHTML = original.html || '';
      container.className = original.className || '';
    } else {
      // Store original content
      this.state.originalGeneralMessages.set(containerId, {
        html: container.innerHTML || '',
        className: container.className || ''
      });
    }

    container.classList.remove('show', 'visible', 'active');

    EventManager.emit('form:clearGeneralError', {containerId, config});

    return true;
  }

  /**
   * Clear success message (similar to clearGeneralError)
   * @param {string|HTMLElement} containerOrForm - Container ID, container element, or form element
   */
  static clearSuccess(containerOrForm = null) {
    let container = null;
    let config = this.config;

    if (typeof containerOrForm === 'string') {
      container = document.getElementById(containerOrForm);
    } else if (containerOrForm instanceof HTMLElement) {
      if (containerOrForm.tagName === 'FORM') {
        config = this.getFormConfig(containerOrForm);

        if (config.successContainer) {
          container = document.getElementById(config.successContainer);
        }
      } else {
        container = document.getElementById(containerOrForm.id);
      }
    } else {
      container = document.getElementById(config.defaultSuccessContainer);
    }

    if (!container) {
      const containers = document.querySelectorAll('[data-success-container], .form-message, .success-message, .login-message');
      if (containers.length > 0) {
        container = containers[0];
      }
    }

    if (!container) return false;

    const containerId = container.id || Utils.generateUUID();
    container.id = containerId;
    const original = this.state.originalGeneralMessages.get(containerId);
    if (original) {
      // Restore original content
      container.innerHTML = original.html || '';
      container.className = original.className || '';
    } else {
      // Store original content
      this.state.originalGeneralMessages.set(containerId, {
        html: container.innerHTML || '',
        className: container.className || ''
      });
    }

    container.classList.remove('show', 'visible', 'active');

    EventManager.emit('form:clearSuccess', {containerId, config});

    return true;
  }

  /**
   * Clear all messages (errors, success, and field errors)
   * @param {HTMLElement} form - Form element (optional)
   */
  static clearAll(form = null) {
    this.state.errors.forEach((error, field) => {
      this.clearFieldError(field);
    });
    this.state.lastFocusedElement = null;

    if (form instanceof HTMLElement) {
      this.clearGeneralError(form);
      this.clearSuccess(form);
    } else {
      this.clearGeneralError();
      this.clearSuccess();

      const allContainers = document.querySelectorAll('[data-error-container], [data-success-container], .form-message, .error-message, .success-message, .login-message');
      allContainers.forEach(container => {
        container.classList.remove('show', 'visible', 'active');
      });
    }

    EventManager.emit('form:clearAllErrors', {form});
  }

  /**
   * Clear all messages for a specific form
   * @param {HTMLElement|string} form - Form element or form ID
   */
  static clearFormMessages(form) {
    let formElement = form;

    if (typeof form === 'string') {
      formElement = document.getElementById(form) || document.querySelector(`[data-form="${form}"]`);
    }

    if (!formElement || formElement.tagName !== 'FORM') {
      console.warn('FormError.clearFormMessages: Invalid form element');
      return false;
    }

    const fields = formElement.querySelectorAll('[name], [id]');
    fields.forEach(field => {
      const fieldName = field.name || field.id;
      if (fieldName && this.state.errors.has(fieldName)) {
        this.clearFieldError(fieldName);
      }
    });

    this.clearGeneralError(formElement);
    this.clearSuccess(formElement);

    return true;
  }

  static showErrors(errors, options = {}) {
    this.clearAll();

    Object.entries(errors).forEach(([field, message], index) => {
      this.showFieldError(field, message, {
        focus: index === 0,
        scroll: index === 0,
        ...options
      });
    });

    EventManager.emit('form:errors', {errors});
  }

  static scrollToElement(element) {
    const rect = element.getBoundingClientRect();
    const windowHeight = window.innerHeight || document.documentElement.clientHeight;

    const visibleThreshold = rect.height * 0.3;
    const isPartiallyVisible =
      (rect.top + visibleThreshold >= 0 && rect.top < windowHeight) ||
      (rect.bottom > 0 && rect.bottom - visibleThreshold <= windowHeight);

    if (!isPartiallyVisible) {
      element.scrollIntoView({
        behavior: 'smooth',
        block: 'nearest'
      });
    }
  }

  static hasErrors() {
    return this.state.errors.size > 0;
  }

  static getErrors() {
    return Array.from(this.state.errors.entries()).map(([field, error]) => ({
      field,
      ...error
    }));
  }

  /**
   * Return number of current field errors
   */
  static getErrorsCount() {
    return this.state.errors.size;
  }

  static reset() {
    this.clearAll();
    this.state = {
      errors: new Map(),
      lastFocusedElement: null,
      originalMessages: new Map(),
      originalGeneralMessages: new Map()
    };
  }
}

window.FormError = FormError;
