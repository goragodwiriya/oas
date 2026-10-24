/**
 * ContentArea - Content editable area component
 * Handles the main editing area with paste handling and content management
 *
 * @author Goragod Wiriya
 * @version 1.0
 */
import EventBus from '../core/EventBus.js';
import {cleanupHtmlFragment} from '../core/HtmlCleanup.js';
import {EMBED_ATTR, EMBED_SELECTOR, toPlaceholders, fromPlaceholders} from '../core/EmbedPlaceholder.js';

// Editor-only marker on an <img> whose src failed to load (see handleImageState)
const BROKEN_IMAGE_ATTR = 'data-rte-broken';

// Block wrappers that exist only to hold one embed (see selectImage)
const EMBED_WRAPPER_SELECTOR = '.rte-iframe-wrapper, .rte-video-wrapper';

class ContentArea {
  /**
   * @param {RichTextEditor} editor - Editor instance
   * @param {Object} options - Configuration options
   */
  constructor(editor, options = {}) {
    this.editor = editor;
    this.options = {
      minHeight: 200,
      maxHeight: null,
      autoResize: true,
      placeholder: '',
      readOnly: false,
      ...options
    };

    this.element = null;
    this.focused = false;

    this.handleInput = this.handleInput.bind(this);
    this.handleFocus = this.handleFocus.bind(this);
    this.handleBlur = this.handleBlur.bind(this);
    this.handlePaste = this.handlePaste.bind(this);
    this.handleDrop = this.handleDrop.bind(this);
    this.handleDragOver = this.handleDragOver.bind(this);
    this.handleImageState = this.handleImageState.bind(this);
    this.handleCopy = this.handleCopy.bind(this);
    this.handleKeyDown = this.handleKeyDown.bind(this);
    this.sanitizeEmbed = this.sanitizeEmbed.bind(this);
  }

  /**
   * Create and return the content area element
   * @returns {HTMLElement}
   */
  create() {
    // Container
    this.container = document.createElement('div');
    this.container.className = 'rte-content-wrapper';

    // Editable area
    this.element = document.createElement('div');
    this.element.className = 'rte-content';
    this.element.contentEditable = !this.options.readOnly;
    this.element.setAttribute('role', 'textbox');
    this.element.setAttribute('aria-multiline', 'true');
    this.element.setAttribute('aria-label', 'Rich text editor content');
    this.element.setAttribute('data-placeholder', this.options.placeholder ?? '');
    if (this.editor?.container?.dataset?.rteScopeId) {
      this.element.setAttribute('data-rte-scope', this.editor.container.dataset.rteScopeId);
    }

    // Set initial styles
    if (this.options.minHeight) {
      this.element.style.minHeight = `${this.options.minHeight}px`;
    }
    if (this.options.maxHeight) {
      this.element.style.maxHeight = `${this.options.maxHeight}px`;
      this.element.style.overflowY = 'auto';
    }

    this.container.appendChild(this.element);

    // Attach listeners
    this.attachListeners();

    return this.container;
  }

  /**
   * Attach event listeners
   */
  attachListeners() {
    this.element.addEventListener('input', this.handleInput);
    this.element.addEventListener('focus', this.handleFocus);
    this.element.addEventListener('blur', this.handleBlur);
    this.element.addEventListener('paste', this.handlePaste);
    this.element.addEventListener('drop', this.handleDrop);
    this.element.addEventListener('dragover', this.handleDragOver);
    this.element.addEventListener('copy', this.handleCopy);
    this.element.addEventListener('cut', this.handleCopy);
    this.element.addEventListener('keydown', this.handleKeyDown);

    // Safety net: an iframe that reaches the content some other way than
    // setContent()/insertHtml() (drag and drop, execCommand, another plugin)
    this.embedObserver = new MutationObserver(() => {
      if (this.element.querySelector('iframe')) toPlaceholders(this.element);
    });
    this.embedObserver.observe(this.element, {childList: true, subtree: true});

    // Selection change on mouseup and keyup
    this.element.addEventListener('mouseup', () => {
      this.editor.events?.emit(EventBus.Events.SELECTION_CHANGE);
    });

    // Image click / dblclick — open image edit dialog (iframe placeholders go to the iframe plugin)
    this.element.addEventListener('click', (e) => {
      const img = e.target.closest('img');
      if (img) {
        this.selectImage(img);
        const type = img.hasAttribute(EMBED_ATTR) ? 'EMBED_CLICK' : 'IMAGE_CLICK';
        this.editor.events?.emit(EventBus.Events[type], {element: img, event: e});
      }
    });

    this.element.addEventListener('dblclick', (e) => {
      const img = e.target.closest('img');
      if (img) {
        e.preventDefault();
        const type = img.hasAttribute(EMBED_ATTR) ? 'EMBED_DBLCLICK' : 'IMAGE_DBLCLICK';
        this.editor.events?.emit(EventBus.Events[type], {element: img, event: e});
      }
    });

    // Image load state — load/error do not bubble, so listen in the capture phase
    this.element.addEventListener('error', this.handleImageState, true);
    this.element.addEventListener('load', this.handleImageState, true);

    this.element.addEventListener('keyup', (e) => {
      // Emit selection change for navigation keys
      if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) {
        this.editor.events?.emit(EventBus.Events.SELECTION_CHANGE);
      }
    });
  }

  /**
   * Detach event listeners
   */
  detachListeners() {
    if (!this.element) return;

    this.element.removeEventListener('input', this.handleInput);
    this.element.removeEventListener('focus', this.handleFocus);
    this.element.removeEventListener('blur', this.handleBlur);
    this.element.removeEventListener('paste', this.handlePaste);
    this.element.removeEventListener('drop', this.handleDrop);
    this.element.removeEventListener('dragover', this.handleDragOver);
    this.element.removeEventListener('error', this.handleImageState, true);
    this.element.removeEventListener('load', this.handleImageState, true);
    this.element.removeEventListener('copy', this.handleCopy);
    this.element.removeEventListener('cut', this.handleCopy);
    this.element.removeEventListener('keydown', this.handleKeyDown);
    this.embedObserver?.disconnect();
  }

  /**
   * Select a clicked image as a whole, so Delete/Backspace removes it.
   * Chrome only moves the caret when an image is clicked, which leaves Delete
   * erasing text elsewhere. A link or embed wrapper that holds nothing but the
   * image is selected with it, so deleting does not leave an empty box behind.
   * @param {HTMLImageElement} img
   */
  selectImage(img) {
    if (!this.element.isContentEditable) return;

    let node = img;
    let parent = node.parentElement;
    while (parent && parent !== this.element
      && parent.matches(`a, ${EMBED_WRAPPER_SELECTOR}`)
      && parent.children.length === 1 && parent.textContent.trim() === '') {
      node = parent;
      parent = node.parentElement;
    }

    const range = document.createRange();
    range.selectNode(node);
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
  }

  /**
   * Delete/Backspace on a selected embed wrapper removes the whole block.
   * Chrome would delete only its content and leave the empty <div> (with its
   * margins or 16:9 padding) behind.
   * @param {KeyboardEvent} event
   */
  handleKeyDown(event) {
    if (event.key !== 'Delete' && event.key !== 'Backspace') return;

    const range = this.editor.selection?.getRange();
    const node = range && range.startContainer === range.endContainer
      && range.endOffset - range.startOffset === 1
      ? range.startContainer.childNodes[range.startOffset]
      : null;
    if (!node?.matches?.(EMBED_WRAPPER_SELECTOR) || !this.element.contains(node)) return;

    event.preventDefault();
    const next = node.nextElementSibling;
    const previous = node.previousElementSibling;
    node.remove();
    if (next) {
      this.editor.selection.setCursorAtStart(next);
    } else if (previous) {
      this.editor.selection.setCursorAtEnd(previous);
    }
    this.handleInput();
  }

  /**
   * Copy/cut the real iframes rather than their editor-only placeholders
   * @param {ClipboardEvent} event
   */
  handleCopy(event) {
    const range = this.editor.selection?.getRange();
    if (!range || range.collapsed || !event.clipboardData) return;

    const fragment = range.cloneContents();
    if (!fragment.querySelector(EMBED_SELECTOR)) return;

    const holder = document.implementation.createHTMLDocument('').createElement('div');
    holder.appendChild(holder.ownerDocument.importNode(fragment, true));
    fromPlaceholders(holder, this.sanitizeEmbed);

    event.preventDefault();
    event.clipboardData.setData('text/html', holder.innerHTML);
    event.clipboardData.setData('text/plain', window.getSelection().toString());

    if (event.type === 'cut' && this.element.isContentEditable) {
      range.deleteContents();
      this.handleInput();
    }
  }

  /**
   * Run restored iframe markup through the editor's sanitizer (when enabled)
   * @param {string} html
   * @returns {string}
   */
  sanitizeEmbed(html) {
    return this.editor?.options?.sanitize && this.editor.sanitizeHtml
      ? this.editor.sanitizeHtml(html)
      : html;
  }

  /**
   * Mark an <img> whose src failed to load so CSS can draw it as a clickable placeholder.
   * Otherwise the browser renders only its alt text, which looks like ordinary text and
   * cannot be recognised as an image to click, replace or delete. The <img> itself is
   * never changed; the marker is editor-only and is stripped by getContent().
   * @param {Event} event
   */
  handleImageState(event) {
    const img = event.target;
    if (img?.tagName === 'IMG') {
      img.toggleAttribute(BROKEN_IMAGE_ATTR, event.type === 'error');
    }
  }

  /**
   * Handle input event
   */
  handleInput() {
    this.editor.history?.record();
    this.editor.events?.emit(EventBus.Events.CONTENT_CHANGE, this.getContent());
  }

  /**
   * Handle focus event
   */
  handleFocus() {
    this.focused = true;
    this.container?.classList.add('focused');
    this.editor.events?.emit(EventBus.Events.EDITOR_FOCUS);
  }

  /**
   * Handle blur event
   */
  handleBlur() {
    this.focused = false;
    this.container?.classList.remove('focused');
    this.editor.events?.emit(EventBus.Events.EDITOR_BLUR);
  }

  /**
   * Handle paste event
   * @param {ClipboardEvent} event
   */
  handlePaste(event) {
    const clipboardData = event.clipboardData || window.clipboardData;

    // Ctrl+Shift+V → paste as plain text (strip all HTML)
    if (event.shiftKey && (event.ctrlKey || event.metaKey)) {
      event.preventDefault();
      const text = clipboardData?.getData('text/plain') || '';
      if (text) {
        // Convert newlines to <br> so they are visible in the editor
        const safeHtml = text
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/\n/g, '<br>');
        this.editor.selection?.insertHtml(safeHtml);
        this.handleInput();
      }
      return;
    }

    // Emit event to allow plugins to handle
    this.editor.events?.emit(EventBus.Events.CONTENT_PASTE, event);

    // If event not prevented by plugin, do default clean paste
    if (!event.defaultPrevented) {
      const clipboardData = event.clipboardData || window.clipboardData;

      if (clipboardData) {
        // Try to get HTML first
        const html = clipboardData.getData('text/html');
        const text = clipboardData.getData('text/plain');

        if (html) {
          event.preventDefault();
          const cleanedHtml = this.cleanPastedHtml(html);
          // Run security sanitizer after cleaning Word-specific formatting
          const safeHtml = this.editor.options?.sanitize !== false
            ? this.editor.sanitizeHtml(cleanedHtml)
            : cleanedHtml;
          this.editor.selection?.insertHtml(safeHtml);
          this.handleInput();
        } else if (text) {
          // Let browser handle plain text paste
          // or convert to paragraphs if needed
        }
      }
    }
  }

  /**
   * Clean pasted HTML (remove Word-specific styles, etc.)
   * @param {string} html - Raw HTML
   * @returns {string} Cleaned HTML
   */
  cleanPastedHtml(html) {
    const removableTags = ['meta', 'link'];
    if (!this.editor?.options?.allowScript) removableTags.unshift('script');
    if (!this.editor?.options?.allowStyle) removableTags.unshift('style');

    return cleanupHtmlFragment(html, {
      stripWordFormatting: true,
      removeSelectors: removableTags,
      removeEmptySelectors: ['span:empty'],
      unwrapSelectors: ['font'],
      unwrapPlainSpans: true
    });
  }

  /**
   * Handle drop event
   * @param {DragEvent} event
   */
  handleDrop(event) {
    const files = event.dataTransfer?.files;
    if (files && files.length > 0) {
      // Let image plugin handle file drops
      this.editor.events?.emit('content:drop', {
        event,
        files: Array.from(files)
      });
    }
  }

  /**
   * Handle dragover event
   * @param {DragEvent} event
   */
  handleDragOver(event) {
    // Allow drop
    event.preventDefault();
    event.dataTransfer.dropEffect = 'copy';
  }

  /**
   * Check if content is empty
   * @returns {boolean}
   */
  isEmpty() {
    if (!this.element) return true;

    if (this.editor?.embeddedAssets?.styles?.length || this.editor?.embeddedAssets?.scripts?.length) {
      return false;
    }

    const text = this.element.textContent?.trim();
    const html = this.element.innerHTML?.trim();

    // Check for truly empty content
    return !text && (
      !html ||
      html === '<br>' ||
      html === '<p></p>' ||
      html === '<p><br></p>' ||
      html === '<div></div>' ||
      html === '<div><br></div>'
    );
  }

  /**
   * Get element
   * @returns {HTMLElement}
   */
  getElement() {
    return this.element;
  }

  /**
   * Get container
   * @returns {HTMLElement}
   */
  getContainer() {
    return this.container;
  }

  /**
   * Get content as HTML
   * @returns {string}
   */
  getContent() {
    if (!this.element) return '';

    // Don't return placeholder content
    if (this.isEmpty()) return '';

    if (!this.element.querySelector(`img[${BROKEN_IMAGE_ATTR}], ${EMBED_SELECTOR}`)) {
      return this.element.innerHTML;
    }

    // Strip the broken-image marker and restore iframes on a copy in an inert
    // document, so the copied <img>/<iframe> elements load nothing
    const copy = document.implementation.createHTMLDocument('').importNode(this.element, true);
    copy.querySelectorAll(`img[${BROKEN_IMAGE_ATTR}]`).forEach(img => img.removeAttribute(BROKEN_IMAGE_ATTR));
    fromPlaceholders(copy, this.sanitizeEmbed);
    return copy.innerHTML;
  }

  /**
   * Set content
   * @param {string} html - HTML content
   * @param {boolean} recordHistory - Whether to record in history
   */
  setContent(html, recordHistory = false) {
    if (!this.element) return;

    html = html ?? '';
    if (/<iframe/i.test(html)) {
      // Swap iframes for placeholders in an inert template, before they can load
      const template = document.createElement('template');
      template.innerHTML = html;
      toPlaceholders(template.content);
      this.element.replaceChildren(template.content);
    } else {
      this.element.innerHTML = html;
    }

    if (recordHistory) {
      this.editor.history?.record(true);
    }

    this.editor.events?.emit(EventBus.Events.CONTENT_SET, html);
  }

  /**
   * Clear content
   */
  clear() {
    this.setContent('', true);
  }

  /**
   * Focus the content area
   */
  focus() {
    if (this.element) {
      this.element.focus();
    }
  }

  /**
   * Blur the content area
   */
  blur() {
    if (this.element) {
      this.element.blur();
    }
  }

  /**
   * Check if content area has focus
   * @returns {boolean}
   */
  hasFocus() {
    return this.focused;
  }

  /**
   * Set read-only mode
   * @param {boolean} readOnly
   */
  setReadOnly(readOnly) {
    this.options.readOnly = readOnly;
    if (this.element) {
      this.element.contentEditable = !readOnly;
    }
  }

  /**
   * Check if read-only
   * @returns {boolean}
   */
  isReadOnly() {
    return this.options.readOnly;
  }

  /**
   * Set placeholder
   * @param {string} placeholder
   */
  setPlaceholder(placeholder) {
    this.options.placeholder = placeholder;
    if (this.element) {
      this.element.setAttribute('data-placeholder', placeholder);
    }
  }

  /**
   * Get text content (no HTML)
   * @returns {string}
   */
  getTextContent() {
    return this.element?.textContent || '';
  }

  /**
   * Get word count
   * @returns {number}
   */
  getWordCount() {
    const text = this.getTextContent().trim();
    if (!text) return 0;

    return text.split(/\s+/).filter(word => word.length > 0).length;
  }

  /**
   * Get character count
   * @param {boolean} excludeSpaces - Exclude spaces from count
   * @returns {number}
   */
  getCharacterCount(excludeSpaces = false) {
    const text = this.getTextContent();
    if (excludeSpaces) {
      return text.replace(/\s/g, '').length;
    }
    return text.length;
  }

  /**
   * Destroy content area
   */
  destroy() {
    this.detachListeners();

    if (this.container && this.container.parentNode) {
      this.container.parentNode.removeChild(this.container);
    }

    this.element = null;
    this.container = null;
  }
}

export default ContentArea;
