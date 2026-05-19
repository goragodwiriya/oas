/**
 * KeyboardManager - Keyboard shortcuts management
 * Handles keyboard shortcuts for editor commands
 *
 * @author Goragod Wiriya
 * @version 1.0
 */
import EventBus from './EventBus.js';

class KeyboardManager {
  /**
   * @param {RichTextEditor} editor - Editor instance
   */
  constructor(editor) {
    this.editor = editor;
    this.shortcuts = new Map();
    this.enabled = true;

    this.handleKeyDown = this.handleKeyDown.bind(this);
  }

  /**
   * Initialize keyboard manager
   */
  init() {
    this.registerDefaultShortcuts();
    this.attachListeners();
  }

  /**
   * Register default keyboard shortcuts
   */
  registerDefaultShortcuts() {
    // Text formatting
    this.register('ctrl+b', 'bold');
    this.register('ctrl+i', 'italic');
    this.register('ctrl+u', 'underline');

    // Undo/Redo
    this.register('ctrl+z', 'undo');
    this.register('ctrl+y', 'redo');
    this.register('ctrl+shift+z', 'redo');

    // Lists
    this.register('ctrl+shift+l', 'insertUnorderedList');
    this.register('ctrl+shift+o', 'insertOrderedList');

    // Link
    this.register('ctrl+k', () => {
      this.editor.events?.emit('toolbar:buttonClick', {id: 'link'});
    });

    // Select all
    this.register('ctrl+a', 'selectAll');

    // Tab for indent (prevent default tab behavior)
    this.register('tab', (e) => {
      e.preventDefault();
      this.editor.commands?.execute('indent');
    });

    this.register('shift+tab', (e) => {
      e.preventDefault();
      this.editor.commands?.execute('outdent');
    });

    // Escape to blur/close dialogs
    this.register('escape', () => {
      this.editor.events?.emit(EventBus.Events.DIALOG_CLOSE);
    });
  }

  /**
   * Attach event listeners
   */
  attachListeners() {
    const container = this.editor.contentArea?.getElement();
    if (container) {
      container.addEventListener('keydown', this.handleKeyDown);
    }
  }

  /**
   * Detach event listeners
   */
  detachListeners() {
    const container = this.editor.contentArea?.getElement();
    if (container) {
      container.removeEventListener('keydown', this.handleKeyDown);
    }
  }

  /**
   * Handle keydown event
   * @param {KeyboardEvent} event
   */
  handleKeyDown(event) {
    if (!this.enabled) return;

    const shortcut = this.normalizeShortcut(event);
    const handler = this.shortcuts.get(shortcut);

    if (handler) {
      if (typeof handler === 'string') {
        // Handler is command name
        event.preventDefault();
        this.editor.commands?.execute(handler);
      } else if (typeof handler === 'function') {
        // Handler is custom function
        handler(event);
      }
    }
  }

  /**
   * Normalize keyboard event to shortcut string
   * @param {KeyboardEvent} event
   * @returns {string}
   */
  normalizeShortcut(event) {
    const parts = [];

    if (event.ctrlKey || event.metaKey) parts.push('ctrl');
    if (event.shiftKey) parts.push('shift');
    if (event.altKey) parts.push('alt');

    // Get key name
    let key = event.key.toLowerCase();

    // Handle special keys
    const keyMap = {
      ' ': 'space',
      'control': null, // Skip modifier keys
      'shift': null,
      'alt': null,
      'meta': null,
      'arrowup': 'up',
      'arrowdown': 'down',
      'arrowleft': 'left',
      'arrowright': 'right',
      'enter': 'enter',
      'escape': 'escape',
      'backspace': 'backspace',
      'delete': 'delete',
      'tab': 'tab'
    };

    if (keyMap.hasOwnProperty(key)) {
      key = keyMap[key];
    }

    if (key) {
      parts.push(key);
    }

    return parts.join('+');
  }

  /**
   * Parse shortcut string to normalized form
   * @param {string} shortcut
   * @returns {string}
   */
  parseShortcut(shortcut) {
    const parts = shortcut.toLowerCase().split('+').map(p => p.trim());

    // Sort modifiers in consistent order
    const modifiers = [];
    let key = '';

    parts.forEach(part => {
      if (['ctrl', 'cmd', 'meta'].includes(part)) {
        modifiers.push('ctrl');
      } else if (['shift'].includes(part)) {
        modifiers.push('shift');
      } else if (['alt', 'option'].includes(part)) {
        modifiers.push('alt');
      } else {
        key = part;
      }
    });

    // Sort modifiers
    modifiers.sort();

    // Combine
    return [...modifiers, key].join('+');
  }

  /**
   * Register a keyboard shortcut
   * @param {string} shortcut - Shortcut string (e.g., 'ctrl+b', 'ctrl+shift+z')
   * @param {string|Function} handler - Command name or handler function
   */
  register(shortcut, handler) {
    const normalized = this.parseShortcut(shortcut);
    this.shortcuts.set(normalized, handler);
  }

  /**
   * Unregister a keyboard shortcut
   * @param {string} shortcut - Shortcut string
   */
  unregister(shortcut) {
    const normalized = this.parseShortcut(shortcut);
    this.shortcuts.delete(normalized);
  }

  /**
   * Check if shortcut is registered
   * @param {string} shortcut - Shortcut string
   * @returns {boolean}
   */
  hasShortcut(shortcut) {
    const normalized = this.parseShortcut(shortcut);
    return this.shortcuts.has(normalized);
  }

  /**
   * Get handler for shortcut
   * @param {string} shortcut - Shortcut string
   * @returns {string|Function|null}
   */
  getHandler(shortcut) {
    const normalized = this.parseShortcut(shortcut);
    return this.shortcuts.get(normalized) || null;
  }

  /**
   * Get all registered shortcuts
   * @returns {Map}
   */
  getAllShortcuts() {
    return new Map(this.shortcuts);
  }

  /**
   * Format shortcut for display
   * @param {string} shortcut - Shortcut string
   * @returns {string}
   */
  formatForDisplay(shortcut) {
    const isMac = navigator.platform.toUpperCase().indexOf('MAC') >= 0;

    const parts = shortcut.split('+');
    const formatted = parts.map(part => {
      switch (part.toLowerCase()) {
        case 'ctrl':
          return isMac ? '⌘' : 'Ctrl';
        case 'shift':
          return isMac ? '⇧' : 'Shift';
        case 'alt':
          return isMac ? '⌥' : 'Alt';
        case 'enter':
          return isMac ? '↩' : 'Enter';
        case 'escape':
          return 'Esc';
        case 'tab':
          return 'Tab';
        case 'backspace':
          return isMac ? '⌫' : 'Backspace';
        case 'delete':
          return isMac ? '⌦' : 'Del';
        case 'up':
          return '↑';
        case 'down':
          return '↓';
        case 'left':
          return '←';
        case 'right':
          return '→';
        default:
          return part.toUpperCase();
      }
    });

    return isMac ? formatted.join('') : formatted.join('+');
  }

  /**
   * Enable keyboard shortcuts
   */
  enable() {
    this.enabled = true;
  }

  /**
   * Disable keyboard shortcuts
   */
  disable() {
    this.enabled = false;
  }

  /**
   * Check if keyboard manager is enabled
   * @returns {boolean}
   */
  isEnabled() {
    return this.enabled;
  }

  /**
   * Destroy keyboard manager
   */
  destroy() {
    this.detachListeners();
    this.shortcuts.clear();
  }
}

export default KeyboardManager;
