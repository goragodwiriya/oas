/**
 * PluginBase - Base class for RichTextEditor plugins
 * Provides common functionality and lifecycle methods
 *
 * @author Goragod Wiriya
 * @version 1.0
 */

class PluginBase {
  /**
   * Plugin name (must be overridden)
   * @type {string}
   */
  static pluginName = 'base';

  /**
   * Plugin dependencies
   * @type {string[]}
   */
  static requires = [];

  /**
   * @param {RichTextEditor} editor - Editor instance
   * @param {Object} options - Plugin options
   */
  constructor(editor, options = {}) {
    this.editor = editor;
    this.options = options;
    this.initialized = false;
    this.eventSubscriptions = [];
  }

  /**
   * Initialize the plugin
   * Called when plugin is loaded
   */
  init() {
    this.initialized = true;
  }

  /**
   * Called when editor is ready
   */
  onReady() {
    // Override in subclass
  }

  /**
   * Get plugin name
   * @returns {string}
   */
  getName() {
    return this.constructor.pluginName;
  }

  /**
   * Subscribe to editor event
   * @param {string} event - Event name
   * @param {Function} callback - Callback function
   */
  subscribe(event, callback) {
    const boundCallback = callback.bind(this);
    this.editor.events?.on(event, boundCallback);
    this.eventSubscriptions.push({event, callback: boundCallback});
  }

  /**
   * Execute editor command
   * @param {string} command - Command name
   * @param {...any} args - Command arguments
   */
  execute(command, ...args) {
    return this.editor.commands?.execute(command, ...args);
  }

  /**
   * Register editor command
   * @param {string} name - Command name
   * @param {Object} definition - Command definition
   */
  registerCommand(name, definition) {
    this.editor.commands?.register(name, definition);
  }

  /**
   * Register keyboard shortcut
   * @param {string} shortcut - Shortcut string
   * @param {string|Function} handler - Command name or handler function
   */
  registerShortcut(shortcut, handler) {
    this.editor.keyboard?.register(shortcut, handler);
  }

  /**
   * Get editor selection manager
   * @returns {SelectionManager}
   */
  getSelection() {
    return this.editor.selection;
  }

  /**
   * Get current selection range
   * @returns {Range|null}
   */
  getRange() {
    return this.editor.selection?.getRange();
  }

  /**
   * Save current selection
   */
  saveSelection() {
    this.editor.selection?.saveSelection();
  }

  /**
   * Restore saved selection
   */
  restoreSelection() {
    this.editor.selection?.restoreSelection();
  }

  /**
   * Insert HTML at cursor
   * @param {string} html - HTML to insert
   */
  insertHtml(html) {
    this.editor.selection?.insertHtml(html);
  }

  /**
   * Get editor content
   * @returns {string}
   */
  getContent() {
    return this.editor.getContent();
  }

  /**
   * Set editor content
   * @param {string} html - HTML content
   */
  setContent(html) {
    this.editor.setContent(html);
  }

  /**
   * Emit event
   * @param {string} event - Event name
   * @param {...any} args - Event arguments
   */
  emit(event, ...args) {
    this.editor.events?.emit(event, ...args);
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
   * Show notification
   * @param {string} message - Notification message
   * @param {string} type - Notification type (success, error, warning, info)
   */
  notify(message, type = 'info') {
    this.editor.showNotification?.(message, type);
  }

  /**
   * Get toolbar button element
   * @param {string} id - Button ID
   * @returns {HTMLElement|null}
   */
  getToolbarButton(id) {
    return this.editor.toolbar?.buttons.get(id)?.element;
  }

  /**
   * Update toolbar button state
   * @param {string} id - Button ID
   * @param {boolean} active - Active state
   */
  setButtonActive(id, active) {
    const button = this.getToolbarButton(id);
    if (button) {
      button.classList.toggle('active', active);
      button.setAttribute('aria-pressed', active);
    }
  }

  /**
   * Enable toolbar button
   * @param {string} id - Button ID
   */
  enableButton(id) {
    const button = this.getToolbarButton(id);
    if (button) {
      button.disabled = false;
    }
  }

  /**
   * Disable toolbar button
   * @param {string} id - Button ID
   */
  disableButton(id) {
    const button = this.getToolbarButton(id);
    if (button) {
      button.disabled = true;
    }
  }

  /**
   * Focus editor
   */
  focusEditor() {
    this.editor.focus();
  }

  /**
   * Check if editor has focus
   * @returns {boolean}
   */
  hasFocus() {
    return this.editor.contentArea?.hasFocus() || false;
  }

  /**
   * Record history snapshot
   * @param {boolean} force - Force immediate recording
   */
  recordHistory(force = false) {
    this.editor.history?.record(force);
  }

  /**
   * Start batch history operation
   */
  startBatch() {
    this.editor.history?.startBatch();
  }

  /**
   * End batch history operation
   */
  endBatch() {
    this.editor.history?.endBatch();
  }

  /**
   * Execute function as single history batch
   * @param {Function} fn - Function to execute
   */
  batch(fn) {
    this.editor.history?.batch(fn);
  }

  /**
   * Destroy the plugin
   * Called when plugin is unloaded or editor is destroyed
   */
  destroy() {
    // Unsubscribe from all events
    this.eventSubscriptions.forEach(({event, callback}) => {
      this.editor.events?.off(event, callback);
    });
    this.eventSubscriptions = [];

    this.initialized = false;
  }
}

export default PluginBase;
