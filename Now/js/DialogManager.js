/**
 * DialogManager
 *
 * Shows modal dialogs: `alert`, `confirm` and `prompt` return a Promise that
 * resolves with the user's answer, and `custom` builds one from arbitrary
 * options. Dialogs stack — `state.activeDialogs` keeps the order, so Escape and
 * `bringToFront` always act on the topmost one.
 *
 * The backdrop is delegated to BackdropManager rather than drawn here.
 */
const DialogManager = {
  config: {
    duration: 200,
    draggable: true,
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
          <div class="dialog-message"></div>
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

  /**
   * Set up the manager: resolve BackdropManager and bind keyboard handling.
   *
   * Runs once; a second call returns immediately.
   *
   * @param {Object} [options={}] - Overrides merged into the module config.
   * @returns {Promise<Object>} - The manager itself, so calls can be chained.
   */
  async init(options = {}) {
    if (this.state.initialized) return this;

    this.config = {
      ...this.config,
      ...options,
      // Added to the built-in layouts: replacing them would leave alert, confirm
      // and prompt with nothing to render
      templates: {...this.config.templates, ...options.templates}
    };
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

  /**
   * Show a message with a single acknowledge button.
   *
   * Both title and message go through `Now.translate`, so callers pass keys or
   * plain text and get the right language either way.
   *
   * @param {string} message - Message to show.
   * @param {string} [title=null] - Dialog title; defaults to the translated "Alert".
   * @param {Object} [options={}] - Overrides for this dialog.
   * @returns {Promise<boolean>} - Resolves with true once the dialog closes, however it is closed.
   */
  alert(message, title = null, options = {}) {
    title = Now.translate(title || 'Alert');
    return new Promise(resolve => {
      const dialog = this.createDialog({
        template: 'alert',
        title,
        message: Now.translate(message),
        buttons: {
          ok: {
            text: Now.translate('OK'),
            class: 'btn-primary',
            callback: () => resolve(true)
          }
        },
        ...options
      });

      this._settleOnClose(dialog, resolve, true);
      this.show(dialog);
    });
  },

  /**
   * Ask the user to confirm or cancel.
   *
   * @param {string} message - Question to ask.
   * @param {string} [title=null] - Dialog title; defaults to the translated "Confirm".
   * @param {Object} [options={}] - Overrides for this dialog.
   * @returns {Promise<boolean>} - True when confirmed; false when cancelled or
   *   closed any other way (Escape, the close button, the backdrop).
   */
  confirm(message, title = null, options = {}) {
    title = Now.translate(title || 'Confirm');
    return new Promise(resolve => {
      const dialog = this.createDialog({
        template: 'confirm',
        title,
        message: Now.translate(message),
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

      this._settleOnClose(dialog, resolve, false);
      this.show(dialog);
    });
  },

  /**
   * Ask the user for a value.
   *
   * @param {string} message - Prompt text.
   * @param {string} [defaultValue=''] - Value the input starts with.
   * @param {string} [title=null] - Dialog title; defaults to the translated "Prompt".
   * @param {Object} [options={}] - Overrides for this dialog.
   * @returns {Promise<string|null>} - The entered value; null when cancelled or
   *   closed any other way (Escape, the close button, the backdrop).
   */
  prompt(message, defaultValue = '', title = null, options = {}) {
    title = Now.translate(title || 'Prompt');
    return new Promise(resolve => {
      const dialog = this.createDialog({
        template: 'prompt',
        title,
        message: Now.translate(message),
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

      this._settleOnClose(dialog, resolve, null);
      this.show(dialog);
    });
  },

  /**
   * Settle a dialog's promise when it closes without a button deciding the
   * answer. A button's callback resolves first, which makes this a no-op; without
   * it, closing with Escape left whatever awaited the dialog waiting forever.
   *
   * @param {HTMLElement} dialog - The dialog.
   * @param {Function} resolve - The promise's resolve function.
   * @param {*} value - The answer when no button gave one.
   * @returns {void}
   * @private
   */
  _settleOnClose(dialog, resolve, value) {
    dialog.addEventListener('dialog:closed', () => resolve(value), {once: true});
  },

  /**
   * Make an HTML message safe to show with SecurityManager.sanitizeHtml()
   * (untrusted level).
   *
   * @param {string} html - The message.
   * @returns {string} - Safe HTML.
   * @private
   */
  _sanitizeHtml(html) {
    return SecurityManager.sanitizeHtml(html);
  },

  /**
   * Build a dialog from arbitrary options.
   *
   * Unlike `alert`/`confirm`/`prompt` this returns the dialog element rather
   * than a Promise, so the caller drives its lifecycle. `options.template`
   * falls back to the alert layout.
   *
   * @param {Object} options - Dialog options, including `template` and `onShow`.
   * @returns {HTMLElement} - The dialog element.
   */
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

  /**
   * Build a dialog element and register it.
   *
   * Marked `role="dialog"` for assistive tech, and given the next id from
   * `state.nextId` so it can be tracked in the active stack.
   *
   * @param {Object} options - Dialog options.
   * @returns {HTMLElement} - The created, not-yet-shown dialog.
   */
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
        // Write the message into its own box when the template has one.
        // `prompt` keeps its <input> inside .dialog-body, so replacing the whole
        // body with the message used to delete the input — the dialog then had
        // nothing to type into and always resolved to null.
        const bodyEl = dialog.querySelector('.dialog-message') || dialog.querySelector('.dialog-body');
        if (bodyEl) {
          if (typeof options.message === 'string') {
            bodyEl.innerHTML = this._sanitizeHtml(options.message);
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

      // The default button has focus from the start, so an Enter still held down
      // from before the dialog opened must not answer it by repeating
      dialog.addEventListener('keydown', (e) => {
        if (e.repeat && (e.key === 'Enter' || e.key === ' ')) {
          e.preventDefault();
          e.stopPropagation();
        }
      }, true);

      // Prompt input: start with the default value, and let Enter press the
      // default button the same way it would on a native prompt.
      const input = dialog.querySelector('.dialog-input');
      if (input) {
        if (options.defaultValue != null) {
          input.value = options.defaultValue;
        }
        input.addEventListener('keydown', (e) => {
          if (e.key !== 'Enter' || e.isComposing) return;
          e.preventDefault();
          this._getDefaultButton(dialog)?.click();
        });
      }

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

  /**
   * Keep Tab focus inside the dialog while it is open.
   *
   * Does nothing when the dialog contains no focusable elements, so an
   * information-only dialog does not trap focus with nowhere to go.
   *
   * @param {HTMLElement} dialog - Dialog to trap focus within.
   * @returns {void}
   */
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

  /**
   * Let the user drag the dialog by its header.
   *
   * Does nothing when the template has no `.dialog-header`. Handles both mouse
   * and touch, and stores the handler on the header so `cleanupDialog` can
   * unbind it later.
   *
   * @param {HTMLElement} dialog - Dialog to make draggable.
   * @returns {void}
   */
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

  /**
   * Render the dialog's footer buttons, replacing any already there.
   *
   * Does nothing when the template has no `.dialog-footer`.
   *
   * @param {HTMLElement} dialog - Dialog to render into.
   * @param {Object} [buttons={}] - Button key to config.
   * @returns {void}
   */
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
          // A second click while it fades out must not run the callback again
          if (dialog.classList.contains('hiding')) return;
          config.callback(dialog);
          this.close(dialog);
        };
      }

      if (config.attrs) {
        Object.entries(config.attrs).forEach(([attr, value]) => {
          button.setAttribute(attr, value);
        });
      }

      if (config.default) {
        button.dataset.dialogDefault = '';
      }

      footer.appendChild(button);
    });
  },

  /**
   * Find the button Enter should press: the one configured with
   * `default: true`, else the first `.btn-primary` in the footer (OK in
   * alert/prompt, Confirm in confirm), else the first footer button.
   *
   * @param {HTMLElement} dialog - Dialog to search.
   * @returns {HTMLButtonElement|null} - The default button, or null when the footer has none.
   * @private
   */
  _getDefaultButton(dialog) {
    const footer = dialog.querySelector('.dialog-footer');
    if (!footer) return null;
    return footer.querySelector('[data-dialog-default]') ||
      footer.querySelector('.btn-primary') ||
      footer.querySelector('button');
  },

  /**
   * Display a dialog and push it onto the active stack.
   *
   * Accepts an element, an id, or a selector; an unresolvable argument logs a
   * warning and returns null rather than throwing.
   *
   * @param {HTMLElement|string} dialog - The dialog, its id, or a selector.
   * @returns {HTMLElement|null} - The shown dialog, or null when not found.
   */
  show(dialog) {
    // normalize: accept id/selector string or HTMLElement
    const resolved = this._resolveDialogElement(dialog);
    if (!resolved) {
      console.warn('DialogManager.show: dialog not found or invalid argument', dialog);
      return null;
    }
    dialog = resolved;

    if (!dialog || this.state.dialogs.has(dialog.id)) return dialog;

    const previousFocus = document.activeElement;

    // It has no size until it is in the page: measured before this, the dialog
    // was centred as if it were 320 wide and sat off to the right
    document.body.appendChild(dialog);

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
      previousFocus,
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

    requestAnimationFrame(() => {
      dialog.classList.add('show');

      // Focus where the user acts first, so Enter works straight away: the
      // prompt input, else the default footer button. The header close button
      // is the last resort — focusing it made Enter close the dialog instead.
      const input = dialog.querySelector('.dialog-input');
      const focusTarget = input ||
        this._getDefaultButton(dialog) ||
        dialog.querySelector(
          'button:not(.dialog-close), [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
        ) ||
        dialog.querySelector('.dialog-close');

      focusTarget?.focus();
      if (input) {
        input.select();
      }

      dialog.dispatchEvent(new CustomEvent('dialog:shown', {
        detail: {dialog}
      }));
    });

    return dialog;
  },

  /**
   * Raise a dialog above the others in the stack.
   *
   * @param {HTMLElement|string} dialog - The dialog, its id, or a selector.
   * @returns {void}
   */
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

  /**
   * Close one dialog and remove it from the active stack.
   *
   * @param {HTMLElement|string} dialog - The dialog, its id, or a selector.
   * @returns {void}
   */
  close(dialog) {
    if (!dialog) return;

    const resolved = this._resolveDialogElement(dialog);
    if (!resolved) return;
    dialog = resolved;

    const dialogData = this.state.dialogs.get(dialog.id);
    if (!dialogData || dialog.classList.contains('hiding')) return;

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

      if (dialogData.previousFocus && document.contains(dialogData.previousFocus)) {
        dialogData.previousFocus.focus();
      }

      dialog.dispatchEvent(new CustomEvent('dialog:closed', {
        detail: {dialog}
      }));

    }, this.config.duration);
  },

  /**
   * Close every open dialog.
   *
   * @returns {void}
   */
  closeAll() {
    [...this.state.activeDialogs].forEach(id => {
      const dialog = document.getElementById(id);
      if (dialog) {
        this.close(dialog);
      }
    });
  },

  /**
   * Bind the document-level key handling for open dialogs.
   *
   * Keys act on the topmost dialog only, so stacked dialogs close one at a
   * time rather than all at once.
   *
   * @returns {void}
   */
  setupKeyboardEvents() {
    if (this._keydownHandler) return;

    this._keydownHandler = (e) => {
      if (this.state.activeDialogs.length === 0) return;

      const topDialogId = this.state.activeDialogs[this.state.activeDialogs.length - 1];
      const topDialog = document.getElementById(topDialogId);

      if (!topDialog) return;

      if (e.key === 'Escape' && this.config.closeOnEscape) {
        e.preventDefault();
        this.close(topDialog);
      }
    };
    document.addEventListener('keydown', this._keydownHandler);
  },

  /**
   * Translate a key, falling back to the key itself when i18n is not loaded.
   *
   * @param {string} key - Translation key.
   * @param {Object} [params={}] - Values interpolated into the result.
   * @returns {string} - Translated text, or the key unchanged.
   */
  translate(key, params = {}) {
    const i18n = Now.getManager('i18n');
    return i18n ? i18n.translate(key, params) : key;
  },

  /**
   * Tear the manager down: close every dialog and unbind the keyboard handler.
   * `init()` sets it up again.
   *
   * @returns {void}
   */
  destroy() {
    this.closeAll();

    if (this._keydownHandler) {
      document.removeEventListener('keydown', this._keydownHandler);
      this._keydownHandler = null;
    }

    this.state = {
      dialogs: new Map(),
      activeDialogs: [],
      templates: new Map(),
      nextId: this.state.nextId,
      initialized: false,
      errorHandlers: new Set()
    };
  },

  /**
   * Unbind a dialog's handlers before it is removed.
   *
   * Removes the drag listeners stored on the header by `setupDraggable`, so a
   * closed dialog does not leave listeners behind.
   *
   * @param {HTMLElement} dialog - Dialog being torn down.
   * @returns {void}
   */
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

  /**
   * Report a dialog failure through ErrorManager.
   *
   * @param {string} message - What went wrong.
   * @param {Error} error - The underlying error.
   * @param {string} type - Which method failed, used as the error context.
   * @returns {void}
   */
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
