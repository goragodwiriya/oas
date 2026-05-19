/**
 * Toolbar - Editor toolbar component
 * Manages toolbar buttons, dropdowns, and state
 *
 * @author Goragod Wiriya
 * @version 1.0
 */
import EventBus from '../core/EventBus.js';

class Toolbar {
  /**
   * @param {RichTextEditor} editor - Editor instance
   * @param {Object} options - Configuration options
   */
  constructor (editor, options = {}) {
    this.editor = editor;
    this.options = {
      items: Toolbar.defaultItems,
      sticky: false,
      ...options
    };

    this.element = null;
    this.buttons = new Map();
    this.dropdowns = new Map();
    this.activeDropdown = null;

    this.handleButtonClick = this.handleButtonClick.bind(this);
    this.handleButtonMousedown = this.handleButtonMousedown.bind(this);
    this.handleDocumentClick = this.handleDocumentClick.bind(this);
    this.updateButtonStates = this.updateButtonStates.bind(this);
  }

  /**
   * Create and return the toolbar element
   * @returns {HTMLElement}
   */
  create() {
    this.element = document.createElement('div');
    this.element.className = 'rte-toolbar';
    this.element.setAttribute('role', 'toolbar');
    this.element.setAttribute('aria-label', 'Text formatting toolbar');

    if (this.options.sticky) {
      this.element.classList.add('sticky');
    }

    // Build toolbar items
    this.buildItems();

    // Listen for toolbar updates
    this.editor.events?.on(EventBus.Events.TOOLBAR_UPDATE, this.updateButtonStates);
    this.editor.events?.on(EventBus.Events.SELECTION_CHANGE, this.updateButtonStates);

    // Close dropdowns on outside click
    document.addEventListener('click', this.handleDocumentClick);

    return this.element;
  }

  /**
   * Build toolbar items from configuration
   */
  buildItems() {
    const items = this.options.items;

    // Handle array of arrays (groups) or flat array
    const groups = Array.isArray(items[0]) ? items : [items];

    groups.forEach((group, groupIndex) => {
      const groupElement = document.createElement('div');
      groupElement.className = 'rte-toolbar-group';

      group.forEach(item => {
        if (item === '|' || item === 'separator') {
          // Add separator
          const separator = document.createElement('div');
          separator.className = 'rte-toolbar-separator';
          groupElement.appendChild(separator);
        } else {
          // Add button or dropdown
          const buttonDef = Toolbar.buttonDefinitions[item];
          if (buttonDef) {
            const buttonEl = this.createButton(item, buttonDef);
            groupElement.appendChild(buttonEl);
          }
        }
      });

      this.element.appendChild(groupElement);
    });
  }

  /**
   * Create a toolbar button
   * @param {string} id - Button ID
   * @param {Object} def - Button definition
   * @returns {HTMLElement}
   */
  createButton(id, def) {
    if (def.type === 'dropdown') {
      return this.createDropdownButton(id, def);
    }

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'rte-toolbar-btn';
    button.dataset.command = id;
    button.setAttribute('aria-label', window.translate?.(def.title) || def.title);
    button.setAttribute('title', this.getButtonTooltip(id, def));

    if (def.icon) {
      const icon = document.createElement('span');
      icon.className = `rte-icon ${def.icon}`;
      icon.setAttribute('aria-hidden', 'true');
      button.appendChild(icon);
    } else if (def.text) {
      button.textContent = window.translate?.(def.text) || def.text;
    }

    // Save selection on mousedown (before focus is lost)
    button.addEventListener('mousedown', this.handleButtonMousedown);
    button.addEventListener('click', (e) => this.handleButtonClick(e, id, def));

    this.buttons.set(id, {element: button, definition: def});

    return button;
  }

  /**
   * Create a dropdown button
   * @param {string} id - Button ID
   * @param {Object} def - Button definition
   * @returns {HTMLElement}
   */
  createDropdownButton(id, def) {
    const container = document.createElement('div');
    container.className = 'rte-toolbar-dropdown';

    // Trigger button
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'rte-toolbar-btn rte-dropdown-trigger';
    button.dataset.command = id;
    button.setAttribute('aria-haspopup', 'true');
    button.setAttribute('aria-expanded', 'false');
    button.setAttribute('title', this.getButtonTooltip(id, def));

    if (def.icon) {
      const icon = document.createElement('span');
      icon.className = `rte-icon ${def.icon}`;
      icon.setAttribute('aria-hidden', 'true');
      button.appendChild(icon);
    }

    // Dropdown arrow
    const arrow = document.createElement('span');
    arrow.className = 'rte-dropdown-arrow';
    arrow.innerHTML = '▼';
    button.appendChild(arrow);

    // Dropdown menu
    const menu = document.createElement('div');
    menu.className = 'rte-dropdown-menu';
    menu.setAttribute('role', 'menu');

    // Build menu items
    if (def.items) {
      def.items.forEach(item => {
        const menuItem = document.createElement('button');
        menuItem.type = 'button';
        menuItem.className = 'rte-dropdown-item';
        menuItem.dataset.value = item.value;
        menuItem.setAttribute('role', 'menuitem');

        if (item.icon) {
          const icon = document.createElement('span');
          icon.className = `rte-icon ${item.icon}`;
          menuItem.appendChild(icon);
        }

        const label = document.createElement('span');
        label.className = 'rte-dropdown-item-label';
        label.textContent = window.translate?.(item.label) || item.label;

        if (item.tag) {
          const tag = document.createElement(item.tag);
          tag.style.margin = '0';
          tag.style.fontSize = item.fontSize || 'inherit';
          tag.textContent = window.translate?.(item.label) || item.label;
          menuItem.appendChild(tag);
        } else {
          menuItem.appendChild(label);
        }

        menuItem.addEventListener('click', (e) => {
          e.stopPropagation();
          this.handleDropdownItemClick(id, item, def);
        });

        menu.appendChild(menuItem);
      });
    }

    // Color picker type
    if (def.colorPicker) {
      const picker = this.createColorPicker(id, def);
      menu.appendChild(picker);
    }

    // Save selection on mousedown
    button.addEventListener('mousedown', this.handleButtonMousedown);
    button.addEventListener('click', (e) => {
      e.stopPropagation();
      this.toggleDropdown(id, container, button);
    });

    container.appendChild(button);
    container.appendChild(menu);

    this.buttons.set(id, {element: button, definition: def});
    this.dropdowns.set(id, {container, button, menu});

    return container;
  }

  /**
   * Create color picker
   * @param {string} id - Picker ID
   * @param {Object} def - Definition
   * @returns {HTMLElement}
   */
  createColorPicker(id, def) {
    const picker = document.createElement('div');
    picker.className = 'rte-color-picker';

    const colors = def.colors || Toolbar.defaultColors;

    // Color grid
    const grid = document.createElement('div');
    grid.className = 'rte-color-grid';

    colors.forEach(color => {
      const colorBtn = document.createElement('button');
      colorBtn.type = 'button';
      colorBtn.className = 'rte-color-btn';
      colorBtn.style.backgroundColor = color;
      colorBtn.dataset.color = color;
      colorBtn.setAttribute('title', color);

      colorBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        this.handleColorSelect(id, color, def);
      });

      grid.appendChild(colorBtn);
    });

    picker.appendChild(grid);

    // Custom color input
    const customRow = document.createElement('div');
    customRow.className = 'rte-color-custom';

    const customInput = document.createElement('input');
    customInput.type = 'color';
    customInput.className = 'rte-color-input';
    customInput.name = `rte-color-${id}`;
    customInput.setAttribute('data-form-exclude', '');
    customInput.value = '#000000';

    const customLabel = document.createElement('span');
    customLabel.textContent = window.translate?.('Custom color') || 'Custom color';

    customInput.addEventListener('change', (e) => {
      this.handleColorSelect(id, e.target.value, def);
    });

    customRow.appendChild(customInput);
    customRow.appendChild(customLabel);
    picker.appendChild(customRow);

    // Clear color button
    if (def.allowClear) {
      const clearBtn = document.createElement('button');
      clearBtn.type = 'button';
      clearBtn.className = 'rte-color-clear';
      clearBtn.textContent = window.translate?.('Remove color') || 'Remove color';
      clearBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        this.handleColorSelect(id, null, def);
      });
      picker.appendChild(clearBtn);
    }

    return picker;
  }

  /**
   * Get button tooltip with shortcut
   * @param {string} id - Button ID
   * @param {Object} def - Button definition
   * @returns {string}
   */
  getButtonTooltip(id, def) {
    let tooltip = window.translate?.(def.title) || def.title;
    const shortcut = this.editor.keyboard?.getHandler(def.command || id);

    if (typeof shortcut === 'string') {
      const formatted = this.editor.keyboard?.formatForDisplay(shortcut);
      if (formatted) {
        tooltip += ` (${formatted})`;
      }
    }

    return tooltip;
  }

  /**
   * Handle button mousedown - save selection before focus is lost
   * @param {MouseEvent} event
   */
  handleButtonMousedown(event) {
    // Prevent default to avoid editor losing focus
    event.preventDefault();

    // Save selection immediately on mousedown (before focus changes)
    if (this.editor.selection?.isWithinEditor()) {
      this.editor.selection.saveSelection();
    }
  }

  /**
   * Handle button click
   * @param {MouseEvent} event
   * @param {string} id - Button ID
   * @param {Object} def - Button definition
   */
  handleButtonClick(event, id, def) {
    event.preventDefault();
    event.stopPropagation();

    // Restore selection before executing command
    this.editor.selection?.restoreSelection();

    if (def.command) {
      this.editor.commands?.execute(def.command, def.value);
    } else {
      // Emit event for plugins to handle
      this.editor.events?.emit(EventBus.Events.TOOLBAR_BUTTON_CLICK, {id, def});
    }
  }

  /**
   * Handle dropdown item click
   * @param {string} id - Dropdown ID
   * @param {Object} item - Item clicked
   * @param {Object} def - Dropdown definition
   */
  handleDropdownItemClick(id, item, def) {
    this.closeAllDropdowns();

    // Restore selection
    this.editor.selection?.restoreSelection();

    if (item.command) {
      this.editor.commands?.execute(item.command, item.value);
    } else if (def.command) {
      this.editor.commands?.execute(def.command, item.value);
    } else {
      this.editor.events?.emit(EventBus.Events.TOOLBAR_BUTTON_CLICK, {id, item, def});
    }
  }

  /**
   * Handle color selection
   * @param {string} id - Picker ID
   * @param {string|null} color - Selected color
   * @param {Object} def - Definition
   */
  handleColorSelect(id, color, def) {
    this.closeAllDropdowns();

    // Restore selection
    this.editor.selection?.restoreSelection();

    if (def.command) {
      if (color) {
        this.editor.commands?.execute(def.command, color);
      } else {
        // Remove color
        this.editor.commands?.execute('removeFormat');
      }
    }
  }

  /**
   * Toggle dropdown visibility
   * @param {string} id - Dropdown ID
   * @param {HTMLElement} container - Dropdown container
   * @param {HTMLElement} button - Trigger button
   */
  toggleDropdown(id, container, button) {
    const isOpen = container.classList.contains('open');

    // Close all dropdowns first
    this.closeAllDropdowns();

    if (!isOpen) {
      // Save selection before opening dropdown
      this.editor.selection?.saveSelection();

      container.classList.add('open');
      button.setAttribute('aria-expanded', 'true');
      this.activeDropdown = id;
    }
  }

  /**
   * Close all dropdowns
   */
  closeAllDropdowns() {
    this.dropdowns.forEach(({container, button}) => {
      container.classList.remove('open');
      button.setAttribute('aria-expanded', 'false');
    });
    this.activeDropdown = null;
  }

  /**
   * Handle document click (close dropdowns)
   * @param {MouseEvent} event
   */
  handleDocumentClick(event) {
    if (this.activeDropdown) {
      const dropdown = this.dropdowns.get(this.activeDropdown);
      if (dropdown && !dropdown.container.contains(event.target)) {
        this.closeAllDropdowns();
      }
    }
  }

  /**
   * Update button states based on current selection
   */
  updateButtonStates() {
    if (!this.editor.contentArea?.hasFocus()) return;

    this.buttons.forEach(({element, definition}, id) => {
      if (definition.command) {
        const isActive = this.editor.commands?.isActive(definition.command, definition.value);
        element.classList.toggle('active', isActive);
        element.setAttribute('aria-pressed', isActive);
      }

      // Handle canExecute
      if (definition.canExecute) {
        const canExecute = typeof definition.canExecute === 'function'
          ? definition.canExecute(this.editor)
          : this.editor.commands?.canExecute(definition.command);
        element.disabled = !canExecute;
      }
    });
  }

  /**
   * Get toolbar element
   * @returns {HTMLElement}
   */
  getElement() {
    return this.element;
  }

  /**
   * Set toolbar items
   * @param {Array} items - Toolbar items
   */
  setItems(items) {
    this.options.items = items;
    if (this.element) {
      this.element.innerHTML = '';
      this.buttons.clear();
      this.dropdowns.clear();
      this.buildItems();
    }
  }

  /**
   * Enable a button
   * @param {string} id - Button ID
   */
  enableButton(id) {
    const btn = this.buttons.get(id);
    if (btn) {
      btn.element.disabled = false;
    }
  }

  /**
   * Disable a button
   * @param {string} id - Button ID
   */
  disableButton(id) {
    const btn = this.buttons.get(id);
    if (btn) {
      btn.element.disabled = true;
    }
  }

  /**
   * Show a button
   * @param {string} id - Button ID
   */
  showButton(id) {
    const btn = this.buttons.get(id);
    if (btn) {
      btn.element.style.display = '';
    }
  }

  /**
   * Hide a button
   * @param {string} id - Button ID
   */
  hideButton(id) {
    const btn = this.buttons.get(id);
    if (btn) {
      btn.element.style.display = 'none';
    }
  }

  /**
   * Destroy toolbar
   */
  destroy() {
    document.removeEventListener('click', this.handleDocumentClick);

    this.editor.events?.off(EventBus.Events.TOOLBAR_UPDATE, this.updateButtonStates);
    this.editor.events?.off(EventBus.Events.SELECTION_CHANGE, this.updateButtonStates);

    if (this.element && this.element.parentNode) {
      this.element.parentNode.removeChild(this.element);
    }

    this.buttons.clear();
    this.dropdowns.clear();
    this.element = null;
  }
}

// Default toolbar items
Toolbar.defaultItems = [
  'bold', 'italic', 'underline', 'strikethrough', 'superscript', 'subscript', '|',
  'heading', '|',
  'bulletList', 'numberedList', '|',
  'alignLeft', 'alignCenter', 'alignRight', 'alignJustify', '|',
  'indent', 'outdent', '|',
  'blockquote', 'horizontalRule', '|',
  'link', 'image', '|',
  'textColor', 'backgroundColor', '|',
  'undo', 'redo', '|',
  'removeFormat', 'sourceView'
];

// Default colors
Toolbar.defaultColors = [
  '#000000', '#434343', '#666666', '#999999', '#b7b7b7', '#cccccc', '#d9d9d9', '#efefef', '#f3f3f3', '#ffffff',
  '#980000', '#ff0000', '#ff9900', '#ffff00', '#00ff00', '#00ffff', '#4a86e8', '#0000ff', '#9900ff', '#ff00ff',
  '#e6b8af', '#f4cccc', '#fce5cd', '#fff2cc', '#d9ead3', '#d0e0e3', '#c9daf8', '#cfe2f3', '#d9d2e9', '#ead1dc',
  '#dd7e6b', '#ea9999', '#f9cb9c', '#ffe599', '#b6d7a8', '#a2c4c9', '#a4c2f4', '#9fc5e8', '#b4a7d6', '#d5a6bd'
];

// Button definitions
Toolbar.buttonDefinitions = {
  bold: {
    icon: 'icon-bold',
    title: 'Bold',
    command: 'bold'
  },
  italic: {
    icon: 'icon-italic',
    title: 'Italic',
    command: 'italic'
  },
  underline: {
    icon: 'icon-underline',
    title: 'Underline',
    command: 'underline'
  },
  strikethrough: {
    icon: 'icon-strikethrough',
    title: 'Strikethrough',
    command: 'strikethrough'
  },
  superscript: {
    icon: 'icon-superscript',
    title: 'Superscript',
    command: 'superscript'
  },
  subscript: {
    icon: 'icon-subscript',
    title: 'Subscript',
    command: 'subscript'
  },
  heading: {
    icon: 'icon-heading',
    title: 'Heading',
    type: 'dropdown',
    items: [
      {label: 'Paragraph', value: 'p', tag: 'p', command: 'paragraph'},
      {label: 'Heading 1', value: 1, tag: 'h1', fontSize: '2em', command: 'heading'},
      {label: 'Heading 2', value: 2, tag: 'h2', fontSize: '1.5em', command: 'heading'},
      {label: 'Heading 3', value: 3, tag: 'h3', fontSize: '1.17em', command: 'heading'},
      {label: 'Heading 4', value: 4, tag: 'h4', fontSize: '1em', command: 'heading'},
      {label: 'Heading 5', value: 5, tag: 'h5', fontSize: '0.83em', command: 'heading'},
      {label: 'Heading 6', value: 6, tag: 'h6', fontSize: '0.67em', command: 'heading'}
    ]
  },
  bulletList: {
    icon: 'icon-listview',
    title: 'Bullet list',
    command: 'insertUnorderedList'
  },
  numberedList: {
    icon: 'icon-listnumber',
    title: 'Numbered list',
    command: 'insertOrderedList'
  },
  alignLeft: {
    icon: 'icon-align-left',
    title: 'Align left',
    command: 'justifyLeft'
  },
  alignCenter: {
    icon: 'icon-align-center',
    title: 'Align center',
    command: 'justifyCenter'
  },
  alignRight: {
    icon: 'icon-align-right',
    title: 'Align right',
    command: 'justifyRight'
  },
  alignJustify: {
    icon: 'icon-align-justify',
    title: 'Justify',
    command: 'justifyFull'
  },
  indent: {
    icon: 'icon-indent-increase',
    title: 'Increase indent',
    command: 'indent'
  },
  outdent: {
    icon: 'icon-indent-decrease',
    title: 'Decrease indent',
    command: 'outdent'
  },
  link: {
    icon: 'icon-link',
    title: 'Insert link'
  },
  image: {
    icon: 'icon-image',
    title: 'Insert image'
  },
  video: {
    icon: 'icon-video',
    title: 'Insert video'
  },
  iframe: {
    icon: 'icon-index',
    title: 'Insert iframe'
  },
  table: {
    icon: 'icon-table',
    title: 'Insert table'
  },
  horizontalRule: {
    icon: 'icon-minus',
    title: 'Horizontal line',
    command: 'insertHorizontalRule'
  },
  blockquote: {
    icon: 'icon-quote',
    title: 'Blockquote',
    command: 'blockquote'
  },
  codeBlock: {
    icon: 'icon-code',
    title: 'Code block',
    command: 'pre'
  },
  textColor: {
    icon: 'icon-fontcolor',
    title: 'Text color',
    type: 'dropdown',
    colorPicker: true,
    command: 'foreColor'
  },
  backgroundColor: {
    icon: 'icon-bgcolor',
    title: 'Background color',
    type: 'dropdown',
    colorPicker: true,
    allowClear: true,
    command: 'backColor'
  },
  specialChars: {
    icon: 'icon-omega',
    title: 'Special characters'
  },
  emoji: {
    icon: 'icon-smile',
    title: 'Emoji'
  },
  undo: {
    icon: 'icon-undo',
    title: 'Undo',
    command: 'undo',
    canExecute: (editor) => editor.history?.canUndo()
  },
  redo: {
    icon: 'icon-redo',
    title: 'Redo',
    command: 'redo',
    canExecute: (editor) => editor.history?.canRedo()
  },
  removeFormat: {
    icon: 'icon-clear-format',
    title: 'Clear formatting',
    command: 'removeFormat'
  },
  sourceView: {
    icon: 'icon-file',
    title: 'Source code'
  },
  fullscreen: {
    icon: 'icon-fullscreen',
    title: 'Fullscreen'
  },
  findReplace: {
    icon: 'icon-search',
    title: 'Find and replace'
  },
  print: {
    icon: 'icon-print',
    title: 'Print'
  },
  pasteCleaner: {
    icon: 'icon-copy',
    title: 'Clean paste'
  },
  aiGenerate: {
    text: 'AI+',
    title: 'Generate content with AI'
  },
  aiRewrite: {
    icon: 'icon-edit',
    title: 'Rewrite with AI'
  },
  aiImage: {
    icon: 'icon-image',
    title: 'Generate image with AI'
  },
  cleanContent: {
    icon: 'icon-reset',
    title: 'Clean HTML'
  },
  galleryUpload: {
    icon: 'icon-gallery',
    title: 'Upload gallery'
  },
  dirLtr: {
    icon: 'icon-ltr',
    title: 'Left to right',
    command: 'dirLtr'
  },
  dirRtl: {
    icon: 'icon-rtl',
    title: 'Right to left',
    command: 'dirRtl'
  }
};

export default Toolbar;
