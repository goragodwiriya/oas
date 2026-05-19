class PasswordElementFactory extends TextElementFactory {
  static config = {
    ...TextElementFactory.config,
    type: 'password',
    showPasswordToggle: true,
    minLength: 8,
    maxLength: 50,
    passwordCriteriaList: false,
    criteriaListClass: 'password-criteria-list',
    passwordCriteria: {
      minLength: {enabled: true, value: 8, label: 'At least {value} characters'},
      uppercase: {enabled: true, label: 'At least one uppercase letter'},
      lowercase: {enabled: true, label: 'At least one lowercase letter'},
      numbers: {enabled: false, label: 'At least one number'},
      special: {enabled: false, label: 'At least one special character'},
      match: {enabled: false, label: 'Passwords match'}
    }
  };

  // Helper: try to locate an already-created strength container for this element
  static locateStrengthContainer(element) {
    if (!element) return null;

    // 1) Immediate next sibling
    try {
      const next = element.nextElementSibling;
      if (next && next.classList && next.classList.contains('password-strength')) return next;
    } catch (e) {}

    // 2) wrapper or container siblings
    try {
      if (element.wrapper) {
        const found = element.wrapper.querySelector('.password-strength');
        if (found) return found;
      }
    } catch (e) {}

    try {
      if (element.container && element.container.parentNode) {
        const parent = element.container.parentNode;
        const found = parent.querySelector('.password-strength');
        if (found) return found;
      }
    } catch (e) {}

    // 3) comment-based result id
    try {
      const commentId = 'result_' + (element.id || '');
      const commentEl = commentId ? document.getElementById(commentId) : null;
      if (commentEl) {
        const found = commentEl.nextElementSibling;
        if (found && found.classList && found.classList.contains('password-strength')) return found;
      }
    } catch (e) {}

    // 4) form-level search fallback (limited scope)
    try {
      const form = element.form || (element.closest ? element.closest('form') : null);
      if (form) {
        const found = form.querySelector('.password-strength');
        if (found) return found;
      }
    } catch (e) {}

    return null;
  }

  static setupElement(instance) {
    super.setupElement(instance);
    const {element, config} = instance;

    element.setAttribute('autocomplete', 'new-password');
    element.dataset.customPasswordField = 'true';

    if (config.showPasswordToggle) {
      this.setupPasswordToggle(instance);
    }

    instance.checkMatch = function(targetElement) {
      if (!targetElement) return false;
      return element.value === targetElement.value;
    };

    // Helper on instance: check single criterion pass/fail
    instance.passesCriterion = function(key, password) {
      if (!password) return false;
      switch (key) {
        case 'minLength':
          return password.length >= (config.passwordCriteria.minLength?.value || config.minLength || 8);
        case 'uppercase':
          return /[A-Z]/.test(password);
        case 'lowercase':
          return /[a-z]/.test(password);
        case 'numbers':
          return /[0-9]/.test(password);
        case 'special':
          return /[^A-Za-z0-9]/.test(password);
        case 'match':
          // match is handled separately
          return true;
        default:
          return true;
      }
    };

    instance.validateSpecific = function(value) {
      const parentValidation = TextElementFactory.prototype.validateSpecific ?
        TextElementFactory.prototype.validateSpecific.call(this, value) : null;

      if (parentValidation) return parentValidation;

      if (!value && !element.required) return null;

      for (const rule of this.validationRules || []) {
        const ruleName = typeof rule === 'string' ? rule : rule.name;

        if (ruleName === 'password') {
          const failedCriteria = this.getFailedCriteria(value);
          if (failedCriteria.length > 0) {
            return Now.translate('Password must include: {criteria}', {criteria: failedCriteria.join(', ')});
          }
        }
      }

      return null;
    };

    instance.getFailedCriteria = function(password) {
      if (!password || element.dataset.targetPassword) return [];
      const failedCriteria = [];

      Object.entries(config.passwordCriteria).forEach(([key, criterion]) => {
        if (!criterion.enabled || key === 'match') return;
        const passed = instance.passesCriterion(key, password);
        if (!passed) {
          failedCriteria.push(criterion.label.replace('{value}', criterion.value));
        }
      });

      return failedCriteria;
    };

    instance.updateCriteriaList = function() {
      if (!instance.criteriaListElement) return;

      const password = element.value;
      const failedCriteria = this.getFailedCriteria(password);
      const criteriaItems = instance.criteriaListElement.querySelectorAll('li');

      if (!password) {
        instance.criteriaListElement.classList.remove('validating');
        criteriaItems.forEach(item => {
          item.className = 'icon-invalid';
          item.setAttribute('aria-label', Now.translate('Criteria not checked'));
        });
        return;
      }

      criteriaItems.forEach(item => {
        const criteriaLabel = item.textContent;
        const isFailed = failedCriteria.includes(criteriaLabel);
        item.className = isFailed ? 'icon-invalid' : 'icon-valid';
        item.setAttribute('aria-label', Now.translate(!isFailed ? 'Criteria met' : 'Criteria not met'));
      });
    };

    instance.updateMatchStatus = function() {
      const targetId = element.dataset.targetPassword;
      const targetElement = targetId ? document.getElementById(targetId) : null;
      const isConfirmField = !!targetId;
      const mainElement = isConfirmField ? targetElement : element;
      const confirmElement = isConfirmField ? element : null;

      if (!mainElement) return;

      const mainValue = mainElement.value;
      const hasMainValue = mainValue.length > 0;
      const confirmValue = confirmElement?.value || '';
      const isMatch = mainValue === confirmValue && hasMainValue;
      mainElement.classList.toggle('valid', isMatch && hasMainValue);
      mainElement.classList.toggle('invalid', !isMatch && hasMainValue);
      mainElement.container?.classList.toggle('valid', isMatch && hasMainValue);
      mainElement.container?.classList.toggle('invalid', !isMatch && hasMainValue);
      if (confirmElement) {
        const hasConfirmValue = confirmValue.length > 0;
        confirmElement.classList.toggle('valid', isMatch && hasConfirmValue);
        confirmElement.classList.toggle('invalid', !isMatch && hasConfirmValue);
        confirmElement.container?.classList.toggle('valid', isMatch && hasConfirmValue);
        confirmElement.container?.classList.toggle('invalid', !isMatch && hasConfirmValue);
      }
    };

    // If a strength flag or register form indicates strength UI, render a strength bar
    try {
      const form = element.form || (element.closest ? element.closest('form') : null);
      const isRegisterForm = form && (form.dataset?.form === 'register' || form.getAttribute && form.getAttribute('data-form') === 'register');
      // treat explicit 'bar' value or any truthy value as a request for a strength bar
      const wantsStrength = (element.dataset.passwordStrength === 'bar' || element.dataset.passwordStrength === 'true' || Boolean(element.dataset.passwordStrength)) || isRegisterForm;

      if (wantsStrength && !element.dataset.targetPassword) {
        // Avoid duplicate strength UI: check if one already exists
        let existing = PasswordElementFactory.locateStrengthContainer(element);
        if (!existing) {
          // create strength container and bar synchronously so it's present immediately
          const strengthContainer = document.createElement('div');
          strengthContainer.className = 'password-strength';
          const strengthBar = document.createElement('div');
          strengthBar.className = 'password-strength-bar';
          strengthBar.setAttribute('role', 'progressbar');
          strengthBar.setAttribute('aria-valuemin', '0');
          strengthBar.setAttribute('aria-valuemax', '100');
          strengthBar.style.width = '0%';
          strengthContainer.appendChild(strengthBar);

          // Insert strength bar - priority order: commentId > element.comment > wrapper > container
          let inserted = false;

          // Priority 1: Find comment element by ID (result_{element_id})
          // Use form.querySelector for modal support (element may not be in DOM yet)
          try {
            const commentId = 'result_' + (element.id || '');
            if (commentId) {
              const form = element.form || (element.closest ? element.closest('form') : null);
              const commentEl = form ? form.querySelector('#' + commentId) : document.getElementById(commentId);
              if (commentEl && commentEl.parentNode) {
                commentEl.parentNode.insertBefore(strengthContainer, commentEl.nextSibling);
                inserted = true;
              }
            }
          } catch (e) {}

          // Priority 2: Use element.comment property if available
          if (!inserted) {
            try {
              if (element.comment && element.comment.parentNode) {
                element.comment.parentNode.insertBefore(strengthContainer, element.comment.nextSibling);
                inserted = true;
              }
            } catch (e) {}
          }

          // Priority 3: Append to wrapper
          if (!inserted) {
            try {
              if (element.wrapper) {
                element.wrapper.appendChild(strengthContainer);
                inserted = true;
              }
            } catch (e) {}
          }

          // Priority 4: Insert after container
          if (!inserted) {
            try {
              if (element.container && element.container.parentNode) {
                const parent = element.container.parentNode;
                parent.insertBefore(strengthContainer, element.container.nextSibling);
                inserted = true;
              }
            } catch (e) {}
          }

          if (!inserted) {
            try {
              // try to insert after the immediate parent (span.form-control) if possible
              if (element.parentNode && element.parentNode.parentNode) {
                const parent = element.parentNode;
                parent.parentNode.insertBefore(strengthContainer, parent.nextSibling);
                inserted = true;
              }
            } catch (e) {}
          }

          if (!inserted) {
            // deterministic fallback: insert immediately after the input element if possible
            try {
              if (element.insertAdjacentElement) {
                element.insertAdjacentElement('afterend', strengthContainer);
                inserted = true;
              }
            } catch (e) {}
          }

          if (!inserted) {
            // fallback: append to form or document body
            try {
              const formEl = element.form || (element.closest ? element.closest('form') : null);
              if (formEl) formEl.appendChild(strengthContainer);
              else document.body.appendChild(strengthContainer);
            } catch (e) {
              try {document.body.appendChild(strengthContainer);} catch (er) {}
            }
          }

          instance.strengthBarElement = strengthBar;
          try {element.dataset.passwordStrength = 'true';} catch (e) {}
        } else {
          // reuse existing bar
          instance.strengthBarElement = existing.querySelector('.password-strength-bar') || null;
          try {element.dataset.passwordStrength = 'true';} catch (e) {}
        }

        instance.updateStrengthBar = function() {
          if (!instance.strengthBarElement) return;
          const password = element.value || '';
          const allCriteria = Object.entries(config.passwordCriteria).filter(([k, c]) => c.enabled && k !== 'match');
          const total = allCriteria.length || 1;
          let passed = 0;
          allCriteria.forEach(([k]) => {
            if (instance.passesCriterion(k, password)) passed += 1;
          });
          const pct = Math.round((passed / total) * 100);
          instance.strengthBarElement.style.width = pct + '%';
          instance.strengthBarElement.classList.remove('weak', 'medium', 'strong');
          if (pct < 40) instance.strengthBarElement.classList.add('weak');
          else if (pct < 80) instance.strengthBarElement.classList.add('medium');
          else instance.strengthBarElement.classList.add('strong');
          instance.strengthBarElement.setAttribute('aria-valuenow', String(pct));
        };

        // initialize
        instance.updateStrengthBar();
      }
    } catch (e) {
      // ignore DOM availability errors in non-browser environments
    }

    if (config.passwordCriteriaList && !element.dataset.targetPassword) {
      this.setupCriteriaList(instance);
    }

    return instance;
  }

  static setupPasswordToggle(instance) {
    const {element} = instance;

    const toggleButton = document.createElement('button');
    toggleButton.type = 'button';
    toggleButton.className = 'password-toggle icon-published0';
    toggleButton.setAttribute('aria-label', Now.translate('Show password'));
    toggleButton.setAttribute('tabindex', '-1');
    toggleButton.setAttribute('aria-description', Now.translate('Press Alt + S to toggle password visibility'));

    if (element.container) {
      element.container.classList.add('has-password-toggle');
      element.container.appendChild(toggleButton);
    } else if (element.wrapper) {
      const container = document.createElement('div');
      container.className = 'form-control has-password-toggle';
      element.parentNode.insertBefore(container, element);
      container.appendChild(element);
      container.appendChild(toggleButton);
      element.container = container;
    }

    const togglePasswordVisibility = () => {
      if (element.type === 'password') {
        element.type = 'text';
        toggleButton.setAttribute('aria-label', Now.translate('Hide password'));
        toggleButton.className = 'password-toggle icon-published1';
      } else {
        element.type = 'password';
        toggleButton.setAttribute('aria-label', Now.translate('Show password'));
        toggleButton.className = 'password-toggle icon-published0';
      }
      element.focus();
    };

    EventSystemManager.addHandler(toggleButton, 'click', togglePasswordVisibility);
    EventSystemManager.addHandler(element, 'keydown', (event) => {
      if (event.altKey && event.key === 's') {
        event.preventDefault();
        togglePasswordVisibility();
      }
    });

    EventSystemManager.addHandler(element, 'focus', () => {
      toggleButton.classList.remove('hidden');
    });

    instance.toggleButton = toggleButton;
  }

  static setupCriteriaList(instance) {
    const {element, config} = instance;

    const criteriaListElement = document.createElement('ul');
    criteriaListElement.className = config.criteriaListClass;
    criteriaListElement.setAttribute('aria-live', 'polite');

    Object.entries(config.passwordCriteria).forEach(([key, criterion]) => {
      if (!criterion.enabled || key === 'match') return;

      const listItem = document.createElement('li');
      listItem.dataset.criteria = key;
      listItem.className = 'icon-invalid';
      listItem.setAttribute('aria-label', Now.translate('Criteria not met'));
      listItem.textContent = Now.translate(criterion.label.replace('{value}', criterion.value));
      criteriaListElement.appendChild(listItem);
    });

    if (element.comment) {
      element.comment.parentNode.insertBefore(criteriaListElement, element.comment.nextSibling);
    } else if (element.wrapper) {
      element.wrapper.appendChild(criteriaListElement);
    } else if (element.container) {
      const parent = element.container.parentNode;
      parent.insertBefore(criteriaListElement, element.container.nextSibling);
    }

    instance.criteriaListElement = criteriaListElement;
    instance.updateCriteriaList = function() {
      if (!instance.criteriaListElement) return;

      const password = element.value;
      const failedCriteria = this.getFailedCriteria(password);
      const criteriaItems = instance.criteriaListElement.querySelectorAll('li');

      if (!password) {
        instance.criteriaListElement.classList.remove('validating');
        criteriaItems.forEach(item => {
          item.className = 'icon-invalid';
          item.setAttribute('aria-label', Now.translate('Criteria not checked'));
        });
        return;
      }

      criteriaItems.forEach(item => {
        const criteriaLabel = item.textContent;
        const isFailed = failedCriteria.includes(criteriaLabel);
        item.className = isFailed ? 'icon-invalid' : 'icon-valid';
        item.setAttribute('aria-label', Now.translate(!isFailed ? 'Criteria met' : 'Criteria not met'));
      });
    };

    instance.updateCriteriaList();
  }

  static setupEventListeners(instance) {
    super.setupEventListeners(instance);
    const {element, config} = instance;

    // Helper to create strength bar synchronously if the element belongs to the register form
    const ensureStrengthBar = () => {
      try {
        const form = element.form || (element.closest ? element.closest('form') : null);
        const isRegisterForm = form && (form.dataset?.form === 'register' || (form.getAttribute && form.getAttribute('data-form') === 'register'));
        // only create for register form or explicit bar request
        const wantsStrengthLocal = (element.dataset.passwordStrength === 'bar' || element.dataset.passwordStrength === 'true' || Boolean(element.dataset.passwordStrength));
        if (!isRegisterForm && !wantsStrengthLocal) return;
        if (element.dataset.targetPassword) return;
        if (instance.strengthBarElement) return; // already created

        // avoid duplicate bars
        let existing = PasswordElementFactory.locateStrengthContainer(element);
        let strengthContainer = existing;
        let strengthBar = existing ? existing.querySelector('.password-strength-bar') : null;
        if (!existing) {
          strengthContainer = document.createElement('div');
          strengthContainer.className = 'password-strength';
          strengthBar = document.createElement('div');
          strengthBar.className = 'password-strength-bar';
          strengthBar.setAttribute('role', 'progressbar');
          strengthBar.setAttribute('aria-valuemin', '0');
          strengthBar.setAttribute('aria-valuemax', '100');
          strengthBar.style.width = '0%';
          strengthContainer.appendChild(strengthBar);
        }

        // try multiple insertion strategies similar to the synchronous creation above
        let inserted = false;
        try {
          const commentId = 'result_' + (element.id || '');
          if (commentId) {
            const form = element.form || (element.closest ? element.closest('form') : null);
            const commentEl = form ? form.querySelector('#' + commentId) : document.getElementById(commentId);
            if (commentEl && commentEl.parentNode) {
              commentEl.parentNode.insertBefore(strengthContainer, commentEl.nextSibling);
              inserted = true;
            }
          }
        } catch (e) {}

        if (!inserted) {
          try {
            if (element.parentNode && element.parentNode.parentNode) {
              const parent = element.parentNode;
              parent.parentNode.insertBefore(strengthContainer, parent.nextSibling);
              inserted = true;
            }
          } catch (e) {}
        }

        if (!inserted) {
          // deterministic fallback: insert immediately after the input element if possible
          try {
            if (element.insertAdjacentElement) {
              element.insertAdjacentElement('afterend', strengthContainer);
              inserted = true;
            }
          } catch (e) {}
        }

        if (!inserted) {
          try {
            const formEl = element.form || (element.closest ? element.closest('form') : null);
            if (formEl) formEl.appendChild(strengthContainer);
            else document.body.appendChild(strengthContainer);
            inserted = true;
          } catch (e) {}
        }

        instance.strengthBarElement = strengthBar;
        try {element.dataset.passwordStrength = 'true';} catch (e) {}

        instance.updateStrengthBar = function() {
          if (!instance.strengthBarElement) return;
          const password = element.value.trim() || '';
          if (password === '') {
            instance.strengthBarElement.style.width = '0%';
            instance.strengthBarElement.classList.remove('weak', 'medium', 'strong');
            instance.strengthBarElement.setAttribute('aria-valuenow', '0');
            return;
          }
          const allCriteria = Object.entries(config.passwordCriteria).filter(([k, c]) => c.enabled && k !== 'match');
          const total = allCriteria.length || 1;
          const failed = instance.getFailedCriteria(password).length;
          const passed = Math.max(0, total - failed);
          const pct = Math.round((passed / total) * 100);
          instance.strengthBarElement.style.width = pct + '%';
          instance.strengthBarElement.classList.remove('weak', 'medium', 'strong');
          if (pct < 40) instance.strengthBarElement.classList.add('weak');
          else if (pct < 80) instance.strengthBarElement.classList.add('medium');
          else instance.strengthBarElement.classList.add('strong');
          instance.strengthBarElement.setAttribute('aria-valuenow', String(pct));
        };
      } catch (err) {
        // swallow
      }
    };

    const inputHandler = () => {
      if (!element.dataset.targetPassword && config.passwordCriteriaList) {
        instance.updateCriteriaList();
      }
      // ensure strength bar exists for register form and update it
      ensureStrengthBar();
      if (instance.updateStrengthBar) {
        instance.updateStrengthBar();
      }
      instance.updateMatchStatus();
    };

    EventSystemManager.addHandler(element, 'input', inputHandler);
    // Try to create the strength bar immediately during setup so templates that are enhanced
    // after insertion will get the strength UI without waiting for user input.
    try {
      ensureStrengthBar();
    } catch (e) {}
    if (instance.updateStrengthBar) {
      try {instance.updateStrengthBar();} catch (e) {}
    }
    // Also schedule a deferred creation to handle enhancement order/race conditions
    try {
      if (typeof requestAnimationFrame !== 'undefined') {
        requestAnimationFrame(() => {
          try {ensureStrengthBar(); if (instance.updateStrengthBar) instance.updateStrengthBar();} catch (e) {}
        });
      } else {
        setTimeout(() => {
          try {ensureStrengthBar(); if (instance.updateStrengthBar) instance.updateStrengthBar();} catch (e) {}
        }, 0);
      }
    } catch (e) {}

    // Bind form reset to clear strength UI
    try {
      const form = element.form || (element.closest ? element.closest('form') : null);
      if (form) {
        instance._formResetHandler = function() {
          try {
            if (instance.strengthBarElement) {
              instance.strengthBarElement.style.width = '0%';
              instance.strengthBarElement.classList.remove('weak', 'medium', 'strong');
              instance.strengthBarElement.setAttribute('aria-valuenow', '0');
            }
            if (instance.criteriaListElement) {
              instance.criteriaListElement.querySelectorAll('li').forEach(li => {
                li.className = 'icon-invalid';
                li.setAttribute('aria-label', Now.translate('Criteria not checked'));
              });
            }
            try {delete element.dataset.passwordStrength;} catch (er) {}
          } catch (er) {}
        };
        EventSystemManager.addHandler(form, 'reset', instance._formResetHandler);
      }
    } catch (er) {}

    // Provide destroy/cleanup for this instance
    const originalDestroy = instance.destroy;
    instance.destroy = function() {
      try {
        if (instance.strengthBarElement && instance.strengthBarElement.parentNode) {
          const container = instance.strengthBarElement.closest('.password-strength');
          if (container && container.parentNode) container.parentNode.removeChild(container);
        }
        if (instance.criteriaListElement && instance.criteriaListElement.parentNode) {
          instance.criteriaListElement.parentNode.removeChild(instance.criteriaListElement);
        }
        if (instance._formResetHandler) {
          const form = element.form || (element.closest ? element.closest('form') : null);
          try {EventSystemManager.removeHandler(form, 'reset', instance._formResetHandler);} catch (e) {}
          instance._formResetHandler = null;
        }
        try {delete element.dataset.passwordStrength;} catch (e) {}
      } catch (e) {
        // swallow
      }
      if (typeof originalDestroy === 'function') {
        try {originalDestroy.call(instance);} catch (e) {}
      }
    };
  }
}

ElementManager.registerElement('password', PasswordElementFactory);

// Export to global scope
window.PasswordElementFactory = PasswordElementFactory;
