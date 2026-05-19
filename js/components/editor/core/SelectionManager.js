/**
 * SelectionManager - Text selection and range management
 * Handles saving, restoring, and manipulating text selections
 *
 * @author Goragod Wiriya
 * @version 1.0
 */
class SelectionManager {
  /**
   * @param {RichTextEditor} editor - Editor instance
   */
  constructor(editor) {
    this.editor = editor;
    this.savedSelection = null;
  }

  /**
   * Get current selection
   * @returns {Selection|null}
   */
  getSelection() {
    return window.getSelection();
  }

  /**
   * Get current range
   * @returns {Range|null}
   */
  getRange() {
    const selection = this.getSelection();
    if (selection && selection.rangeCount > 0) {
      return selection.getRangeAt(0);
    }
    return null;
  }

  /**
   * Check if selection is within editor
   * @returns {boolean}
   */
  isWithinEditor() {
    const range = this.getRange();
    if (!range || !this.editor.contentArea) return false;

    const container = this.editor.contentArea.getElement();
    return container && container.contains(range.commonAncestorContainer);
  }

  /**
   * Check if there is a selection (not collapsed)
   * @returns {boolean}
   */
  hasSelection() {
    const range = this.getRange();
    return range && !range.collapsed;
  }

  /**
   * Get selected text
   * @returns {string}
   */
  getSelectedText() {
    const selection = this.getSelection();
    return selection ? selection.toString() : '';
  }

  /**
   * Get selected HTML
   * @returns {string}
   */
  getSelectedHtml() {
    const range = this.getRange();
    if (!range) return '';

    const fragment = range.cloneContents();
    const div = document.createElement('div');
    div.appendChild(fragment);
    return div.innerHTML;
  }

  /**
   * Save current selection state
   * @returns {Object|null}
   */
  saveSelection() {
    const range = this.getRange();
    if (!range || !this.isWithinEditor()) {
      return null;
    }

    this.savedSelection = {
      startContainer: range.startContainer,
      startOffset: range.startOffset,
      endContainer: range.endContainer,
      endOffset: range.endOffset,
      collapsed: range.collapsed
    };

    return this.savedSelection;
  }

  /**
   * Restore saved selection
   * @returns {boolean} Success
   */
  restoreSelection() {
    if (!this.savedSelection) return false;

    try {
      const selection = this.getSelection();
      const range = document.createRange();

      range.setStart(this.savedSelection.startContainer, this.savedSelection.startOffset);
      range.setEnd(this.savedSelection.endContainer, this.savedSelection.endOffset);

      selection.removeAllRanges();
      selection.addRange(range);

      return true;
    } catch (e) {
      console.warn('Failed to restore selection:', e);
      return false;
    }
  }

  /**
   * Clear saved selection
   */
  clearSavedSelection() {
    this.savedSelection = null;
  }

  /**
   * Select all content in editor
   */
  selectAll() {
    const container = this.editor.contentArea?.getElement();
    if (!container) return;

    const range = document.createRange();
    range.selectNodeContents(container);

    const selection = this.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
  }

  /**
   * Collapse selection to start
   */
  collapseToStart() {
    const selection = this.getSelection();
    if (selection) {
      selection.collapseToStart();
    }
  }

  /**
   * Collapse selection to end
   */
  collapseToEnd() {
    const selection = this.getSelection();
    if (selection) {
      selection.collapseToEnd();
    }
  }

  /**
   * Set cursor position at end of element
   * @param {HTMLElement} element
   */
  setCursorAtEnd(element) {
    const range = document.createRange();
    const selection = this.getSelection();

    range.selectNodeContents(element);
    range.collapse(false);

    selection.removeAllRanges();
    selection.addRange(range);
  }

  /**
   * Set cursor position at start of element
   * @param {HTMLElement} element
   */
  setCursorAtStart(element) {
    const range = document.createRange();
    const selection = this.getSelection();

    range.selectNodeContents(element);
    range.collapse(true);

    selection.removeAllRanges();
    selection.addRange(range);
  }

  /**
   * Insert HTML at cursor position
   * @param {string} html - HTML to insert
   */
  insertHtml(html) {
    const container = this.editor.contentArea?.getElement();
    if (!container) return;

    let range = this.getRange();

    // If no range or not within editor, create one at end of content
    if (!range || !this.isWithinEditor()) {
      // Focus the editor first
      container.focus();

      // Create a range at the end of content
      range = document.createRange();
      if (container.lastChild) {
        range.setStartAfter(container.lastChild);
      } else {
        range.setStart(container, 0);
      }
      range.collapse(true);

      const selection = this.getSelection();
      selection.removeAllRanges();
      selection.addRange(range);
    }

    range.deleteContents();

    const fragment = document.createRange().createContextualFragment(html);
    const lastNode = fragment.lastChild;

    range.insertNode(fragment);

    // Move cursor after inserted content
    if (lastNode) {
      const newRange = document.createRange();
      newRange.setStartAfter(lastNode);
      newRange.collapse(true);

      const selection = this.getSelection();
      selection.removeAllRanges();
      selection.addRange(newRange);
    }
  }

  /**
   * Insert text at cursor position
   * @param {string} text - Text to insert
   */
  insertText(text) {
    const range = this.getRange();
    if (!range || !this.isWithinEditor()) return;

    range.deleteContents();

    const textNode = document.createTextNode(text);
    range.insertNode(textNode);

    // Move cursor after inserted text
    const newRange = document.createRange();
    newRange.setStartAfter(textNode);
    newRange.collapse(true);

    const selection = this.getSelection();
    selection.removeAllRanges();
    selection.addRange(newRange);
  }

  /**
   * Wrap selection with element
   * @param {string} tagName - Tag name to wrap with
   * @param {Object} attributes - Attributes for the element
   * @returns {HTMLElement|null} Created element
   */
  wrapSelection(tagName, attributes = {}) {
    const range = this.getRange();
    if (!range || range.collapsed || !this.isWithinEditor()) return null;

    const wrapper = document.createElement(tagName);
    Object.entries(attributes).forEach(([key, value]) => {
      wrapper.setAttribute(key, value);
    });

    try {
      range.surroundContents(wrapper);
      return wrapper;
    } catch (e) {
      // surroundContents fails if selection spans multiple elements
      // Use alternative approach
      const fragment = range.extractContents();
      wrapper.appendChild(fragment);
      range.insertNode(wrapper);
      return wrapper;
    }
  }

  /**
   * Unwrap selection from element
   * @param {string} tagName - Tag name to unwrap
   */
  unwrapSelection(tagName) {
    const range = this.getRange();
    if (!range || !this.isWithinEditor()) return;

    const container = range.commonAncestorContainer;
    const element = container.nodeType === Node.ELEMENT_NODE
      ? container
      : container.parentElement;

    const target = element.closest(tagName);
    if (target && this.editor.contentArea?.getElement().contains(target)) {
      const parent = target.parentNode;
      while (target.firstChild) {
        parent.insertBefore(target.firstChild, target);
      }
      parent.removeChild(target);
    }
  }

  /**
   * Get parent block element of selection
   * @returns {HTMLElement|null}
   */
  getParentBlock() {
    const range = this.getRange();
    if (!range) return null;

    const blockTags = ['P', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'DIV', 'LI', 'BLOCKQUOTE', 'PRE'];
    let node = range.commonAncestorContainer;

    if (node.nodeType === Node.TEXT_NODE) {
      node = node.parentElement;
    }

    while (node && node !== this.editor.contentArea?.getElement()) {
      if (node.nodeType === Node.ELEMENT_NODE && blockTags.includes(node.tagName)) {
        return node;
      }
      node = node.parentElement;
    }

    return null;
  }

  /**
   * Get nearest ancestor matching selector
   * @param {string} selector - CSS selector
   * @returns {HTMLElement|null}
   */
  getAncestor(selector) {
    const range = this.getRange();
    if (!range) return null;

    let node = range.commonAncestorContainer;
    if (node.nodeType === Node.TEXT_NODE) {
      node = node.parentElement;
    }

    if (node && typeof node.closest === 'function') {
      const ancestor = node.closest(selector);
      if (ancestor && this.editor.contentArea?.getElement().contains(ancestor)) {
        return ancestor;
      }
    }

    return null;
  }

  /**
   * Check if selection contains element matching selector
   * @param {string} selector - CSS selector
   * @returns {boolean}
   */
  containsElement(selector) {
    return this.getAncestor(selector) !== null;
  }

  /**
   * Check if current style is applied
   * @param {string} command - Style command (bold, italic, etc)
   * @returns {boolean}
   */
  isStyleApplied(command) {
    try {
      return document.queryCommandState(command);
    } catch (e) {
      return false;
    }
  }

  /**
   * Focus editor and restore selection
   */
  focus() {
    const container = this.editor.contentArea?.getElement();
    if (container) {
      container.focus();
      this.restoreSelection();
    }
  }

  /**
   * Destroy selection manager
   */
  destroy() {
    this.savedSelection = null;
  }
}

export default SelectionManager;
