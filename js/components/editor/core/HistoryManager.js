/**
 * HistoryManager - Undo/Redo stack management
 * Records content snapshots and allows navigation through history
 *
 * @author Goragod Wiriya
 * @version 1.0
 */
import EventBus from './EventBus.js';

class HistoryManager {
  /**
   * @param {RichTextEditor} editor - Editor instance
   * @param {Object} options - Configuration options
   */
  constructor(editor, options = {}) {
    this.editor = editor;
    this.options = {
      maxHistory: 100,
      debounceDelay: 300,
      ...options
    };

    this.undoStack = [];
    this.redoStack = [];
    this.currentState = null;
    this.debounceTimer = null;
    this.isRecording = true;
    this.batchLevel = 0;
    this.batchState = null;
  }

  /**
   * Initialize history with current content
   */
  init() {
    const content = this.editor.contentArea?.getContent() || '';
    this.currentState = this.createSnapshot(content);
    this.undoStack = [];
    this.redoStack = [];
  }

  /**
   * Create a snapshot of the current state
   * @param {string} content - HTML content
   * @returns {Object} Snapshot object
   */
  createSnapshot(content) {
    return {
      content,
      timestamp: Date.now(),
      selection: this.editor.selection?.saveSelection()
    };
  }

  /**
   * Record current state (debounced)
   * @param {boolean} force - Force immediate recording
   */
  record(force = false) {
    if (!this.isRecording || this.batchLevel > 0) return;

    if (this.debounceTimer) {
      clearTimeout(this.debounceTimer);
    }

    if (force) {
      this.recordImmediate();
    } else {
      this.debounceTimer = setTimeout(() => {
        this.recordImmediate();
      }, this.options.debounceDelay);
    }
  }

  /**
   * Record state immediately
   */
  recordImmediate() {
    const content = this.editor.contentArea?.getContent() || '';

    // Don't record if content hasn't changed
    if (this.currentState && this.currentState.content === content) {
      return;
    }

    // Push current state to undo stack
    if (this.currentState) {
      // Don't push an empty baseline state (initial empty state before data loads).
      // This prevents Ctrl+Z from reverting to blank when content is loaded
      // programmatically after initialisation (e.g. data-binding / form load).
      // Once the undo stack already has entries the user deliberately cleared the
      // editor, so an empty state IS a valid undo target and must be preserved.
      const isEmptyBaseline = this.currentState.content === '' && this.undoStack.length === 0;
      if (!isEmptyBaseline) {
        this.undoStack.push(this.currentState);

        // Limit stack size
        if (this.undoStack.length > this.options.maxHistory) {
          this.undoStack.shift();
        }
      }
    }

    // Create new current state
    this.currentState = this.createSnapshot(content);

    // Clear redo stack on new action
    this.redoStack = [];

    // Emit event
    this.editor.events?.emit(EventBus.Events.HISTORY_CHANGE, {
      canUndo: this.canUndo(),
      canRedo: this.canRedo()
    });

    this.editor.events?.emit(EventBus.Events.HISTORY_SNAPSHOT, this.currentState);
  }

  /**
   * Start batch operation (changes won't be recorded individually)
   */
  startBatch() {
    if (this.batchLevel === 0) {
      this.batchState = this.currentState;
    }
    this.batchLevel++;
  }

  /**
   * End batch operation
   */
  endBatch() {
    this.batchLevel--;
    if (this.batchLevel === 0) {
      this.recordImmediate();
    }
  }

  /**
   * Execute function as batch (single undo point)
   * @param {Function} fn - Function to execute
   */
  batch(fn) {
    this.startBatch();
    try {
      fn();
    } finally {
      this.endBatch();
    }
  }

  /**
   * Check if undo is available
   * @returns {boolean}
   */
  canUndo() {
    return this.undoStack.length > 0;
  }

  /**
   * Check if redo is available
   * @returns {boolean}
   */
  canRedo() {
    return this.redoStack.length > 0;
  }

  /**
   * Undo last action
   * @returns {boolean} Success
   */
  undo() {
    if (!this.canUndo()) return false;

    // Record current state if changed
    const currentContent = this.editor.contentArea?.getContent() || '';
    if (this.currentState && this.currentState.content !== currentContent) {
      this.currentState = this.createSnapshot(currentContent);
    }

    // Push current state to redo stack
    if (this.currentState) {
      this.redoStack.push(this.currentState);
    }

    // Pop from undo stack
    this.currentState = this.undoStack.pop();

    // Restore content
    this.isRecording = false;
    this.editor.contentArea?.setContent(this.currentState.content);

    // Restore selection
    if (this.currentState.selection) {
      this.editor.selection.savedSelection = this.currentState.selection;
      this.editor.selection.restoreSelection();
    }
    this.isRecording = true;

    // Emit events
    this.editor.events?.emit(EventBus.Events.COMMAND_UNDO, this.currentState);
    this.editor.events?.emit(EventBus.Events.HISTORY_CHANGE, {
      canUndo: this.canUndo(),
      canRedo: this.canRedo()
    });

    return true;
  }

  /**
   * Redo last undone action
   * @returns {boolean} Success
   */
  redo() {
    if (!this.canRedo()) return false;

    // Push current state to undo stack
    if (this.currentState) {
      this.undoStack.push(this.currentState);
    }

    // Pop from redo stack
    this.currentState = this.redoStack.pop();

    // Restore content
    this.isRecording = false;
    this.editor.contentArea?.setContent(this.currentState.content);

    // Restore selection
    if (this.currentState.selection) {
      this.editor.selection.savedSelection = this.currentState.selection;
      this.editor.selection.restoreSelection();
    }
    this.isRecording = true;

    // Emit events
    this.editor.events?.emit(EventBus.Events.COMMAND_REDO, this.currentState);
    this.editor.events?.emit(EventBus.Events.HISTORY_CHANGE, {
      canUndo: this.canUndo(),
      canRedo: this.canRedo()
    });

    return true;
  }

  /**
   * Clear all history
   */
  clear() {
    this.undoStack = [];
    this.redoStack = [];
    this.currentState = this.createSnapshot(this.editor.contentArea?.getContent() || '');

    this.editor.events?.emit(EventBus.Events.HISTORY_CHANGE, {
      canUndo: false,
      canRedo: false
    });
  }

  /**
   * Pause recording
   */
  pause() {
    this.isRecording = false;
  }

  /**
   * Resume recording
   */
  resume() {
    this.isRecording = true;
  }

  /**
   * Get history info
   * @returns {Object}
   */
  getInfo() {
    return {
      undoCount: this.undoStack.length,
      redoCount: this.redoStack.length,
      canUndo: this.canUndo(),
      canRedo: this.canRedo()
    };
  }

  /**
   * Destroy history manager
   */
  destroy() {
    if (this.debounceTimer) {
      clearTimeout(this.debounceTimer);
    }
    this.undoStack = [];
    this.redoStack = [];
    this.currentState = null;
  }
}

export default HistoryManager;
