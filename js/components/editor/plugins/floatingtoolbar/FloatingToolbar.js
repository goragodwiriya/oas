class FloatingToolbar {
  // Keyed by RichTextEditor instance so each editor gets its own FloatingToolbar.
  static _instances = new WeakMap();
  // Parallel Set for iterability (WeakMap has no forEach)
  static _activeInstances = new Set();

  constructor(options = {}) {
    const editor = options.plugin?.editor;

    // Return existing instance for the same editor if already created
    if (editor && FloatingToolbar._instances.has(editor)) {
      const existing = FloatingToolbar._instances.get(editor);
      if (options.plugin) {
        existing.plugin = options.plugin;
        existing.editor = editor;
      }
      return existing;
    }

    this.options = {
      plugin: null,
      ...options
    };

    this.toolbar = null;
    this.buttons = {};

    this.activeElement = null;
    this.visible = false;
    this.plugin = this.options.plugin;
    this.editor = editor || null;

    this.handleButtonClick = this.handleButtonClick.bind(this);
    this.hide = this.hide.bind(this);

    this.createToolbar();

    // Register in per-editor map
    if (editor) {
      FloatingToolbar._instances.set(editor, this);
    }
    FloatingToolbar._activeInstances.add(this);
  }

  createToolbar() {
    if (this.toolbar) return;

    this.toolbar = document.createElement('div');
    this.toolbar.className = 'floating-toolbar';
    this.toolbar.style.display = 'none';

    // Add ARIA attributes for accessibility
    this.toolbar.setAttribute('role', 'toolbar');
    this.toolbar.setAttribute('aria-label', 'Block editing toolbar');

    document.body.appendChild(this.toolbar);
  }

  setButtons(buttonDefs) {
    this.toolbar.innerHTML = '';
    this.buttons = {};

    buttonDefs.forEach(def => {
      if (def.divider === 'before') {
        const divider = document.createElement('div');
        divider.className = 'toolbar-divider';
        this.toolbar.appendChild(divider);
      }

      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'toolbar-btn';
      button.dataset.buttonId = def.id;
      button.title = window.translate(def.title) || '';

      // Add ARIA label for accessibility
      button.setAttribute('aria-label', window.translate(def.title) || def.id);

      // Make button keyboard navigable
      button.setAttribute('tabindex', '0');

      if (def.className) {
        button.className += ` ${def.className}`;
      }

      if (def.active) {
        button.classList.add('active');
        button.setAttribute('aria-pressed', 'true');
      } else {
        button.setAttribute('aria-pressed', 'false');
      }

      if (def.icon) {
        const icon = document.createElement('span');
        icon.className = `notext ${def.icon}`;
        icon.setAttribute('aria-hidden', 'true');
        button.appendChild(icon);
      } else if (def.text) {
        button.textContent = window.translate(def.text);
      }

      button.addEventListener('click', (e) => this.handleButtonClick(e, def.id, def.method));

      this.toolbar.appendChild(button);

      this.buttons[def.id] = button;

      if (def.divider === 'after') {
        const divider = document.createElement('div');
        divider.className = 'toolbar-divider';
        this.toolbar.appendChild(divider);
      }
    });
  }

  show(element, event = null) {
    this.activeElement = element;
    this.visible = true;
    this.toolbar.style.display = 'flex';

    if (event && event.clientX && event.clientY) {
      this.showAtPosition(event.clientX, event.clientY);
    } else {
      this.positionAtElement(element);
    }
  }

  hide() {
    if (!this.visible) return;

    if (this.activeElement && this.activeElement.isContentEditable && this.plugin && this.plugin.saveContentEditableChanges) {
      this.plugin.saveContentEditableChanges();
    }

    this.visible = false;
    this.toolbar.style.display = 'none';
    this.activeElement = null;
  }

  static hideAll() {
    FloatingToolbar._activeInstances.forEach(instance => instance.hide());
  }

  static resetInstance(editor = null) {
    if (editor && FloatingToolbar._instances.has(editor)) {
      FloatingToolbar._instances.get(editor).destroy();
    }
  }

  positionAtElement(element) {
    const rect = element.getBoundingClientRect();
    const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
    const scrollLeft = window.pageXOffset || document.documentElement.scrollLeft;

    setTimeout(() => {
      let top = rect.top + scrollTop - this.toolbar.offsetHeight - 10;
      let left = rect.left + scrollLeft + (rect.width / 2) - (this.toolbar.offsetWidth / 2);

      if (top < scrollTop) {
        top = rect.bottom + scrollTop + 10;
      }

      if (left < scrollLeft) {
        left = scrollLeft + 10;
      }

      const rightEdge = left + this.toolbar.offsetWidth;
      const windowRight = scrollLeft + window.innerWidth;
      if (rightEdge > windowRight) {
        left = windowRight - this.toolbar.offsetWidth - 10;
      }

      this.toolbar.style.position = 'absolute';
      this.toolbar.style.left = `${left}px`;
      this.toolbar.style.top = `${top}px`;
    }, 0);
  }

  showAtPosition(clientX, clientY) {
    this.visible = true;
    this.toolbar.style.display = 'flex';

    const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
    const scrollLeft = window.pageXOffset || document.documentElement.scrollLeft;

    setTimeout(() => {
      let top = clientY + scrollTop - this.toolbar.offsetHeight - 10;
      let left = clientX + scrollLeft - (this.toolbar.offsetWidth / 2);

      if (top < scrollTop) {
        top = clientY + scrollTop + 10;
      }

      if (left < scrollLeft) {
        left = scrollLeft + 10;
      }

      const rightEdge = left + this.toolbar.offsetWidth;
      const windowRight = scrollLeft + window.innerWidth;
      if (rightEdge > windowRight) {
        left = windowRight - this.toolbar.offsetWidth - 10;
      }

      this.toolbar.style.position = 'absolute';
      this.toolbar.style.left = `${left}px`;
      this.toolbar.style.top = `${top}px`;
    }, 0);
  }

  setButtonState(id, active) {
    const button = this.buttons[id];
    if (button) {
      if (active) {
        button.classList.add('active');
        button.setAttribute('aria-pressed', 'true');
      } else {
        button.classList.remove('active');
        button.setAttribute('aria-pressed', 'false');
      }
    }
  }

  handleButtonClick(event, buttonId, method) {
    event.stopPropagation();
    if (this.plugin && typeof method === 'function') {
      method.call(this.plugin, buttonId);
    }
  }

  isVisible() {
    return this.visible;
  }

  destroy() {
    if (this.toolbar && this.toolbar.parentNode) {
      this.toolbar.parentNode.removeChild(this.toolbar);
    }

    // Remove from per-editor map
    if (this.editor) {
      FloatingToolbar._instances.delete(this.editor);
    }
    FloatingToolbar._activeInstances.delete(this);

    this.toolbar = null;
    this.buttons = {};
    this.activeElement = null;
    this.visible = false;
  }
}

window.FloatingToolbar = FloatingToolbar;
