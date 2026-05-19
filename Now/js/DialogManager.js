const DialogManager = {
  config: {
    animation: true,
    duration: 200,
    draggable: true,
    modal: true,
    closeOnEscape: true,
    closeOnBackdrop: true,
    preventScroll: true,
    baseZIndex: 1100,
    focusTrap: true,
    keyboard: true,
    templates: {
      alert: `
        <div class="dialog-header">
          <h2 class="dialog-title"></h2>
          <button class="dialog-close" aria-label="Close"></button>
        </div>
        <div class="dialog-body"></div>
        <div class="dialog-footer"></div>
      `,
      confirm: `
        <div class="dialog-header">
          <h2 class="dialog-title"></h2>
          <button class="dialog-close" aria-label="Close"></button>
        </div>
        <div class="dialog-body"></div>
        <div class="dialog-footer"></div>
      `,
      prompt: `
        <div class="dialog-header">
          <h2 class="dialog-title"></h2>
          <button class="dialog-close" aria-label="Close"></button>
        </div>
        <div class="dialog-body">
          <input type="text" class="dialog-input" />
        </div>
        <div class="dialog-footer"></div>
      `
    }
  },

  state: {
    dialogs: new Map(),
    activeDialogs: [],
    templates: new Map(),
    nextId: 1,
    previousFocus: null,
    initialized: false,
    errorHandlers: new Set()
  },

  /**
   * Resolve a dialog input which may be an HTMLElement or a selector/id string
   * @param {HTMLElement|string} input
   * @returns {HTMLElement|null}
   */
  _resolveDialogElement(input) {
    if (!input) return null;
    if (typeof input === 'string') {
      return document.getElementById(input) || document.querySelector(input) || null;
    }
    if (input instanceof HTMLElement) return input;
    return null;
  },

  _getCssZIndexValue(variableName) {
    const value = getComputedStyle(document.documentElement)
      .getPropertyValue(variableName)
      .trim();
    const parsed = parseInt(value, 10);
    return Number.isFinite(parsed) ? parsed : null;
  },

  _getResolvedBaseZIndex() {
    return this._getCssZIndexValue('--z-index-alert') ?? this.config.baseZIndex;
  },

  async init(options = {}) {
    if (this.state.initialized) return this;

    this.config = {...this.config, ...options};
    this.backdropManager = Now.getManager('backdrop');

    if (this.config.keyboard) {
      this.setupKeyboardEvents();
    }

    if (options.templates) {
      Object.entries(options.templates).forEach(([name, template]) => {
        this.state.templates.set(name, template);
      });
    }

    this.state.initialized = true;
    return this;
  },

  alert(message, title = null, options = {}) {
    title = title || Now.translate('Alert');
    return new Promise(resolve => {
      const dialog = this.createDialog({
        template: 'alert',
        title,
        message,
        buttons: {
          ok: {
            text: Now.translate('OK'),
            class: 'btn-primary',
            callback: () => resolve(true)
          }
        },
        ...options
      });

      this.show(dialog);
    });
  },

  confirm(message, title = null, options = {}) {
    title = title || Now.translate('Confirm');
    return new Promise(resolve => {
      const dialog = this.createDialog({
        template: 'confirm',
        title,
        message,
        buttons: {
          cancel: {
            text: Now.translate('Cancel'),
            class: 'text',
            callback: () => resolve(false)
          },
          confirm: {
            text: Now.translate('Confirm'),
            class: 'btn-primary',
            callback: () => resolve(true)
          }
        },
        ...options
      });

      this.show(dialog);
    });
  },

  prompt(message, defaultValue = '', title = null, options = {}) {
    title = title || Now.translate('Prompt');
    return new Promise(resolve => {
      const dialog = this.createDialog({
        template: 'prompt',
        title,
        message,
        defaultValue,
        buttons: {
          cancel: {
            text: Now.translate('Cancel'),
            class: 'text',
            callback: () => resolve(null)
          },
          ok: {
            text: Now.translate('OK'),
            class: 'btn-primary',
            callback: (dialog) => {
              const input = dialog.querySelector('.dialog-input');
              resolve(input?.value ?? null);
            }
          }
        },
        ...options
      });

      this.show(dialog);

      setTimeout(() => {
        const input = dialog.querySelector('.dialog-input');
        if (input) {
          input.value = defaultValue;
          input.select();
        }
      }, this.config.duration);
    });
  },

  custom(options) {
    const dialog = this.createDialog({
      template: options.template || 'alert',
      ...options
    });

    if (options.onShow) {
      dialog.addEventListener('dialog:shown', options.onShow);
    }

    if (options.onClose) {
      dialog.addEventListener('dialog:closed', options.onClose);
    }

    this.show(dialog);
    return dialog;
  },

  createDialog(options) {
    try {
      const id = this.state.nextId++;
      const dialog = document.createElement('div');

      dialog.className = `dialog ${options.customClass || ''}`;
      dialog.setAttribute('role', 'dialog');
      dialog.setAttribute('aria-modal', 'true');
      dialog.id = `dialog-${id}`;

      const template = this.state.templates.get(options.template) ||
        this.config.templates[options.template] ||
        this.config.templates.alert;

      dialog.innerHTML = template;

      if (options.title) {
        const titleEl = dialog.querySelector('.dialog-title');
        if (titleEl) {
          titleEl.textContent = options.title;
          dialog.setAttribute('aria-labelledby', titleEl.id = `dialog-title-${id}`);
        }
      }

      if (options.message) {
        const bodyEl = dialog.querySelector('.dialog-body');
        if (bodyEl) {
          if (typeof options.message === 'string') {
            // sanitize incoming HTML string if DOMPurify is available,
            // otherwise do a minimal script tag strip fallback
            const safeHtml = (window.DOMPurify && typeof DOMPurify.sanitize === 'function')
              ? DOMPurify.sanitize(options.message)
              : options.message.replace(/<script[\s\S]*?>[\s\S]*?<\/script>/gi, '');
            bodyEl.innerHTML = safeHtml;
          } else if (options.message instanceof HTMLElement) {
            bodyEl.appendChild(options.message);
          }
        }
      }

      // Close button click
      const closeBtns = dialog.querySelectorAll('.dialog-close');
      closeBtns.forEach(btn => {
        btn.addEventListener('click', () => this.close(dialog));
      });

      this.setupButtons(dialog, options.buttons);

      if (this.config.draggable && options.draggable !== false) {
        this.setupDraggable(dialog);
      }

      if (this.config.focusTrap) {
        this.setupFocusTrap(dialog);
      }

      return dialog;

    } catch (error) {
      this.handleError('Create Dialog', error, 'create');
      throw error;
    }
  },

  setupFocusTrap(dialog) {
    const focusableElements = dialog.querySelectorAll(
      'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
    );

    if (focusableElements.length === 0) return;

    const firstFocusable = focusableElements[0];
    const lastFocusable = focusableElements[focusableElements.length - 1];

    dialog.addEventListener('keydown', (e) => {
      if (e.key !== 'Tab') return;

      if (e.shiftKey) {
        if (document.activeElement === firstFocusable) {
          e.preventDefault();
          lastFocusable.focus();
        }
      } else {
        if (document.activeElement === lastFocusable) {
          e.preventDefault();
          firstFocusable.focus();
        }
      }
    });
  },

  setupDraggable(dialog) {
    const header = dialog.querySelector('.dialog-header');
    if (!header) return;

    header.style.cursor = 'move';
    let isDragging = false;
    let startX = 0;
    let startY = 0;
    let startLeft = 0;
    let startTop = 0;

    const startDrag = (e) => {
      if (e.target.closest('button')) return;

      const event = e.type === 'mousedown' ? e : e.touches[0];
      isDragging = true;

      startX = event.clientX;
      startY = event.clientY;
      startLeft = parseInt(dialog.style.left) || 0;
      startTop = parseInt(dialog.style.top) || 0;

      dialog.classList.add('dragging');
    };

    const doDrag = (e) => {
      if (!isDragging) return;
      e.preventDefault();

      const event = e.type === 'mousemove' ? e : e.touches[0];

      const deltaX = event.clientX - startX;
      const deltaY = event.clientY - startY;

      const newLeft = startLeft + deltaX;
      const newTop = startTop + deltaY;

      const maxX = window.innerWidth - dialog.offsetWidth;
      const maxY = window.innerHeight - dialog.offsetHeight;

      dialog.style.left = Math.min(Math.max(0, newLeft), maxX) + 'px';
      dialog.style.top = Math.min(Math.max(0, newTop), maxY) + 'px';
    };

    const stopDrag = () => {
      if (!isDragging) return;
      isDragging = false;
      dialog.classList.remove('dragging');
    };

    header.addEventListener('mousedown', startDrag);
    document.addEventListener('mousemove', doDrag);
    document.addEventListener('mouseup', stopDrag);

    header.addEventListener('touchstart', startDrag, {passive: false});
    document.addEventListener('touchmove', doDrag, {passive: false});
    document.addEventListener('touchend', stopDrag);
    document.addEventListener('touchcancel', stopDrag);
  },

  setupButtons(dialog, buttons = {}) {
    const footer = dialog.querySelector('.dialog-footer');
    if (!footer) return;

    footer.innerHTML = '';

    Object.entries(buttons).forEach(([key, config]) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = `btn ${config.class || 'text'}`;
      button.textContent = config.text;

      if (config.callback) {
        button.onclick = (e) => {
          e.preventDefault();
          config.callback(dialog);
          this.close(dialog);
        };
      }

      if (config.attrs) {
        Object.entries(config.attrs).forEach(([attr, value]) => {
          button.setAttribute(attr, value);
        });
      }

      footer.appendChild(button);
    });
  },

  show(dialog) {
    // normalize: accept id/selector string or HTMLElement
    const resolved = this._resolveDialogElement(dialog);
    if (!resolved) {
      console.warn('DialogManager.show: dialog not found or invalid argument', dialog);
      return null;
    }
    dialog = resolved;

    if (!dialog || this.state.dialogs.has(dialog.id)) return dialog;

    this.state.previousFocus = document.activeElement;

    const width = dialog.offsetWidth || 320;
    const height = dialog.offsetHeight || 200;

    const left = Math.max(0, (window.innerWidth - width) / 2);
    const top = Math.max(0, (window.innerHeight - height) / 2);

    dialog.style.left = left + 'px';
    dialog.style.top = top + 'px';

    const baseZIndex = this._getResolvedBaseZIndex();
    const zIndex = baseZIndex + (this.state.activeDialogs.length * 2);

    const backdropId = this.backdropManager?.show(dialog, () => {
      if (this.config.closeOnBackdrop) {
        this.close(dialog);
      }
    }, {
      zIndex: Math.max(baseZIndex - 1, zIndex - 1)
    });

    const dialogData = {
      element: dialog,
      backdropId,
      options: {
        preventScroll: this.config.preventScroll
      }
    };

    this.state.dialogs.set(dialog.id, dialogData);
    this.state.activeDialogs.push(dialog.id);

    dialog.style.zIndex = zIndex;

    if (this.config.preventScroll) {
      document.body.style.overflow = 'hidden';
    }

    document.body.appendChild(dialog);

    requestAnimationFrame(() => {
      dialog.classList.add('show');

      const primaryButton = dialog.querySelector('.dialog-button-primary');
      const firstFocusable = dialog.querySelector(
        'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
      );

      if (primaryButton) {
        primaryButton.focus();
      } else if (firstFocusable) {
        firstFocusable.focus();
      }

      dialog.dispatchEvent(new CustomEvent('dialog:shown', {
        detail: {dialog}
      }));
    });

    return dialog;
  },

  bringToFront(dialog) {
    try {
      // allow string id/selector as input
      const resolved = this._resolveDialogElement(dialog);
      if (!resolved) return;
      dialog = resolved;

      const maxZ = Math.max(
        ...Array.from(this.state.dialogs.values())
          .map(d => parseInt(d.element.style.zIndex) || 0)
      );

      const newZ = Math.max(this.config.baseZIndex, maxZ + 2);
      dialog.style.zIndex = newZ;

      const dialogData = this.state.dialogs.get(dialog.id);
      if (dialogData?.backdropId) {
        const backdrop = this.backdropManager?.getBackdropById(dialogData.backdropId);
        if (backdrop) {
          backdrop.style.zIndex = newZ - 1;
        }
      }

      const index = this.state.activeDialogs.indexOf(dialog.id);
      if (index > -1) {
        this.state.activeDialogs.splice(index, 1);
        this.state.activeDialogs.push(dialog.id);
      }

    } catch (error) {
      this.handleError('Bring To Front', error, 'bringToFront');
    }
  },

  close(dialog) {
    if (!dialog) return;

    const resolved = this._resolveDialogElement(dialog);
    if (!resolved) return;
    dialog = resolved;

    const dialogData = this.state.dialogs.get(dialog.id);
    if (!dialogData) return;

    dialog.classList.remove('show');
    dialog.classList.add('hiding');

    if (dialogData.backdropId) {
      this.backdropManager?.hide(dialogData.backdropId);
    }

    setTimeout(() => {
      dialog.remove();

      this.state.dialogs.delete(dialog.id);

      const index = this.state.activeDialogs.indexOf(dialog.id);
      if (index > -1) {
        this.state.activeDialogs.splice(index, 1);
      }

      if (this.state.activeDialogs.length === 0 && dialogData.options.preventScroll) {
        document.body.style.overflow = '';
      }

      if (this.state.previousFocus && document.contains(this.state.previousFocus)) {
        this.state.previousFocus.focus();
      }

      dialog.dispatchEvent(new CustomEvent('dialog:closed', {
        detail: {dialog}
      }));

    }, this.config.duration);
  },

  closeAll() {
    [...this.state.activeDialogs].forEach(id => {
      const dialog = document.getElementById(id);
      if (dialog) {
        this.close(dialog);
      }
    });
  },

  setupKeyboardEvents() {
    document.addEventListener('keydown', (e) => {
      if (this.state.activeDialogs.length === 0) return;

      const topDialogId = this.state.activeDialogs[this.state.activeDialogs.length - 1];
      const topDialog = document.getElementById(topDialogId);

      if (!topDialog) return;

      if (e.key === 'Escape' && this.config.closeOnEscape) {
        e.preventDefault();
        this.close(topDialog);
      }
    });
  },

  translate(key, params = {}) {
    const i18n = Now.getManager('i18n');
    return i18n ? i18n.translate(key, params) : key;
  },

  destroy() {
    this.closeAll();

    if (this.config.keyboard) {
      document.removeEventListener('keydown', this.handleKeydown);
    }

    this.state = {
      dialogs: new Map(),
      activeDialogs: [],
      templates: new Map(),
      nextId: 1,
      previousFocus: null,
      initialized: false,
      errorHandlers: new Set()
    };

    this.config = null;
  },

  cleanupDialog(dialog) {
    try {
      const header = dialog.querySelector('.dialog-header');
      if (header) {
        header.removeEventListener('mousedown', header._startDrag);
        header.removeEventListener('touchstart', header._startDrag);
      }

      dialog.removeEventListener('keydown', dialog._keyHandler);

      if (dialog._dragHandlers) {
        Object.entries(dialog._dragHandlers).forEach(([event, handler]) => {
          document.removeEventListener(event, handler);
        });
      }

      dialog._dragHandlers = null;
      dialog._keyHandler = null;
      dialog._startDrag = null;

      dialog.removeAttribute('aria-labelledby');
      dialog.removeAttribute('aria-modal');
      dialog.removeAttribute('role');

      dialog.className = 'dialog';

      dialog.style = '';

    } catch (error) {
      this.handleError('Cleanup Dialog', error, 'cleanup');
    }
  },

  handleError(message, error, type) {
    ErrorManager.handle(message, {
      context: `DialogManager.${type}`,
      type: 'error:dialog',
      data: {
        error: {
          name: error.name,
          message: error.message,
          stack: error.stack
        }
      },
      notify: true
    });

    try {
      this.closeAll();
    } catch (cleanupError) {
      console.error('Dialog cleanup failed:', cleanupError);
    }
  }
};

if (window.Now?.registerManager) {
  Now.registerManager('dialog', DialogManager);
}

window.DialogManager = DialogManager;
