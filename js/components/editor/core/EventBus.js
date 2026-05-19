/**
 * EventBus - Central event system for RichTextEditor
 * Provides pub/sub communication between editor components and plugins
 *
 * @author Goragod Wiriya
 * @version 1.0
 */
class EventBus {
  constructor() {
    this.listeners = new Map();
    this.onceListeners = new Map();
  }

  /**
   * Subscribe to an event
   * @param {string} event - Event name
   * @param {Function} callback - Callback function
   * @param {Object} context - Context for callback (optional)
   * @returns {Function} Unsubscribe function
   */
  on(event, callback, context = null) {
    if (typeof callback !== 'function') {
      throw new Error('Callback must be a function');
    }

    if (!this.listeners.has(event)) {
      this.listeners.set(event, []);
    }

    const listener = {callback, context};
    this.listeners.get(event).push(listener);

    // Return unsubscribe function
    return () => this.off(event, callback);
  }

  /**
   * Subscribe to an event (fires only once)
   * @param {string} event - Event name
   * @param {Function} callback - Callback function
   * @param {Object} context - Context for callback (optional)
   * @returns {Function} Unsubscribe function
   */
  once(event, callback, context = null) {
    if (typeof callback !== 'function') {
      throw new Error('Callback must be a function');
    }

    if (!this.onceListeners.has(event)) {
      this.onceListeners.set(event, []);
    }

    const listener = {callback, context};
    this.onceListeners.get(event).push(listener);

    return () => {
      const listeners = this.onceListeners.get(event);
      if (listeners) {
        const index = listeners.findIndex(l => l.callback === callback);
        if (index !== -1) {
          listeners.splice(index, 1);
        }
      }
    };
  }

  /**
   * Unsubscribe from an event
   * @param {string} event - Event name
   * @param {Function} callback - Callback function to remove
   */
  off(event, callback) {
    const listeners = this.listeners.get(event);
    if (listeners) {
      const index = listeners.findIndex(l => l.callback === callback);
      if (index !== -1) {
        listeners.splice(index, 1);
      }
    }
  }

  /**
   * Emit an event
   * @param {string} event - Event name
   * @param {...any} args - Arguments to pass to listeners
   */
  emit(event, ...args) {
    // Regular listeners
    const listeners = this.listeners.get(event);
    if (listeners) {
      listeners.forEach(({callback, context}) => {
        try {
          callback.apply(context, args);
        } catch (error) {
          console.error(`Error in event listener for "${event}":`, error);
        }
      });
    }

    // Once listeners
    const onceListeners = this.onceListeners.get(event);
    if (onceListeners && onceListeners.length > 0) {
      const listenersToCall = [...onceListeners];
      this.onceListeners.set(event, []);

      listenersToCall.forEach(({callback, context}) => {
        try {
          callback.apply(context, args);
        } catch (error) {
          console.error(`Error in once listener for "${event}":`, error);
        }
      });
    }
  }

  /**
   * Remove all listeners for an event or all events
   * @param {string} event - Event name (optional, removes all if not specified)
   */
  removeAllListeners(event = null) {
    if (event) {
      this.listeners.delete(event);
      this.onceListeners.delete(event);
    } else {
      this.listeners.clear();
      this.onceListeners.clear();
    }
  }

  /**
   * Get listener count for an event
   * @param {string} event - Event name
   * @returns {number} Number of listeners
   */
  listenerCount(event) {
    const regular = this.listeners.get(event)?.length || 0;
    const once = this.onceListeners.get(event)?.length || 0;
    return regular + once;
  }

  /**
   * Check if event has listeners
   * @param {string} event - Event name
   * @returns {boolean}
   */
  hasListeners(event) {
    return this.listenerCount(event) > 0;
  }

  /**
   * Destroy event bus
   */
  destroy() {
    this.removeAllListeners();
  }
}

// Event name constants
EventBus.Events = {
  // Editor lifecycle
  EDITOR_INIT: 'editor:init',
  EDITOR_READY: 'editor:ready',
  EDITOR_DESTROY: 'editor:destroy',
  EDITOR_FOCUS: 'editor:focus',
  EDITOR_BLUR: 'editor:blur',

  // Content events
  CONTENT_CHANGE: 'content:change',
  CONTENT_BEFORE_CHANGE: 'content:beforeChange',
  CONTENT_PASTE: 'content:paste',
  CONTENT_SET: 'content:set',

  // Command events
  COMMAND_EXECUTE: 'command:execute',
  COMMAND_BEFORE_EXECUTE: 'command:beforeExecute',
  COMMAND_UNDO: 'command:undo',
  COMMAND_REDO: 'command:redo',

  // Selection events
  SELECTION_CHANGE: 'selection:change',

  // Toolbar events
  TOOLBAR_UPDATE: 'toolbar:update',
  TOOLBAR_BUTTON_CLICK: 'toolbar:buttonClick',

  // Plugin events
  PLUGIN_LOAD: 'plugin:load',
  PLUGIN_UNLOAD: 'plugin:unload',

  // Dialog events
  DIALOG_OPEN: 'dialog:open',
  DIALOG_CLOSE: 'dialog:close',

  // Image events
  IMAGE_CLICK: 'image:click',
  IMAGE_DBLCLICK: 'image:dblclick',

  // Mode events
  MODE_CHANGE: 'mode:change',

  // History events
  HISTORY_CHANGE: 'history:change',
  HISTORY_SNAPSHOT: 'history:snapshot',

  // Autosave events
  AUTOSAVE_START: 'autosave:start',
  AUTOSAVE_SUCCESS: 'autosave:success',
  AUTOSAVE_ERROR: 'autosave:error'
};

export default EventBus;
