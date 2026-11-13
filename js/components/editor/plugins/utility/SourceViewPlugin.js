/**
 * SourceViewPlugin - Toggle HTML source view
 *
 * @author Goragod Wiriya
 * @version 1.0
 */
import PluginBase from '../PluginBase.js';
import EventBus from '../../core/EventBus.js';
import {parseInert} from '../../core/HeldContent.js';

class SourceViewPlugin extends PluginBase {
  static pluginName = 'sourceView';

  init() {
    super.init();

    this.sourceEditor = null;
    this.isActive = false;

    // Create source editor element
    this.createSourceEditor();

    // Listen for toolbar button click
    this.subscribe(EventBus.Events.TOOLBAR_BUTTON_CLICK, (event) => {
      if (event.id === 'sourceView') {
        this.toggle();
      }
    });

    // Register command
    this.registerCommand('sourceView', {
      execute: () => this.toggle(),
      isActive: () => this.isActive
    });
  }

  /**
   * Create source editor element
   */
  createSourceEditor() {
    this.sourceEditor = document.createElement('textarea');
    this.sourceEditor.className = 'rte-source-view';
    this.sourceEditor.spellcheck = false;
    this.sourceEditor.setAttribute('aria-label', 'HTML source code');
    // Mark as excluded so FormManager skips it and it is never serialized.
    this.sourceEditor.setAttribute('data-form-exclude', '');

    // Add to container after content area
    const container = this.editor.getContainer();
    const contentWrapper = this.editor.contentArea?.getContainer();

    if (container && contentWrapper) {
      container.insertBefore(this.sourceEditor, contentWrapper.nextSibling);
    }

    // Expose to editor so RichTextEditor.getContent() can read it when in source mode
    this.editor.sourceEditor = this.sourceEditor;

    // Sync on input
    this.sourceEditor.addEventListener('input', () => {
      // Don't record history on every keystroke in source view
      // Will sync when switching back
    });

    this.sourceEditor.addEventListener('keydown', (event) => {
      if (event.key !== 'Tab' || event.ctrlKey || event.metaKey || event.altKey) {
        return;
      }

      const tabToSpaces = Number.isInteger(this.options.tabToSpaces)
        ? Math.max(0, this.options.tabToSpaces)
        : Number.isInteger(this.editor.options?.tabToSpaces)
          ? Math.max(0, this.editor.options.tabToSpaces)
          : 4;
      if (tabToSpaces <= 0) {
        // Soft tabs disabled: keep the browser's default Tab handling.
        return;
      }

      const start = this.sourceEditor.selectionStart ?? 0;
      const end = this.sourceEditor.selectionEnd ?? start;
      const value = this.sourceEditor.value;

      if (event.shiftKey) {
        event.preventDefault();

        if (start !== end) {
          return;
        }

        const indent = tabToSpaces > 0 ? ' '.repeat(tabToSpaces) : '\t';
        const before = value.slice(Math.max(0, start - indent.length), start);
        if (before === indent) {
          this.sourceEditor.value = value.slice(0, start - indent.length) + value.slice(end);
          const cursor = start - indent.length;
          this.sourceEditor.setSelectionRange(cursor, cursor);
        }
        return;
      }

      event.preventDefault();

      const indent = tabToSpaces > 0 ? ' '.repeat(tabToSpaces) : '\t';

      this.sourceEditor.value = value.slice(0, start) + indent + value.slice(end);
      const cursor = start + indent.length;
      this.sourceEditor.setSelectionRange(cursor, cursor);
    });
  }

  /**
   * Toggle source view
   * @param {boolean} enabled - Force enable/disable
   */
  toggle(enabled) {
    this.isActive = enabled !== undefined ? enabled : !this.isActive;

    const container = this.editor.getContainer();
    const contentWrapper = this.editor.contentArea?.getContainer();

    if (this.isActive) {
      // Switch to source view
      const content = this.editor.getContent() || '';
      this.sourceEditor.value = this.formatHtml(content);

      // Match height of content wrapper before hiding it
      const wrapperHeight = contentWrapper.offsetHeight;
      if (wrapperHeight > 0) {
        this.sourceEditor.style.minHeight = wrapperHeight + 'px';
      }

      container.classList.add('source-mode');
      contentWrapper.style.display = 'none';
      this.sourceEditor.style.display = 'block';

      // Focus source editor
      this.sourceEditor.focus();
    } else {
      // Switch back to WYSIWYG
      const html = this.sourceEditor.value;

      // Record a history snapshot so edits made in source view are undoable
      this.editor.history?.record(true);

      // Update editor state BEFORE calling setContent() so that
      // setContent() routes the HTML to contentArea (not sourceEditor).
      this.editor.isSourceMode = false;

      // Use editor.setContent() instead of contentArea.setContent() to ensure
      // the HTML is sanitised before being placed into the editable area.
      this.editor.setContent(html, false);

      // Reset dynamic min-height
      this.sourceEditor.style.minHeight = '';

      container.classList.remove('source-mode');
      contentWrapper.style.display = '';
      this.sourceEditor.style.display = 'none';

      // Focus editor
      this.focusEditor();
    }

    // Update toolbar button state
    this.setButtonActive('sourceView', this.isActive);

    // Disable/enable all toolbar buttons except sourceView when switching modes
    this._setToolbarSourceMode(this.isActive);

    // Emit mode change event
    this.emit(EventBus.Events.MODE_CHANGE, {
      mode: this.isActive ? 'source' : 'wysiwyg'
    });

    // Update editor state (for source→WYSIWYG the flag was already cleared above)
    this.editor.isSourceMode = this.isActive;
  }

  /**
   * Format HTML for reading: block elements on their own indented lines, runs
   * of inline content on one line.
   *
   * Only white space that makes no difference to the page is touched: around
   * block elements, and runs of spaces between words. Nothing is added where
   * there was none ("foo<b>bar</b>" stays joined), <pre>, <textarea>, <script>,
   * <style> and comments are written exactly as they are, and the markup is
   * parsed where nothing loads or runs.
   *
   * @param {string} html
   * @returns {string}
   */
  formatHtml(html) {
    if (!html) return '';

    const INDENT = '  ';
    const HTML_NS = 'http://www.w3.org/1999/xhtml';

    // Tags whose children are laid out one per line
    const blockTags = new Set([
      'address', 'article', 'aside', 'blockquote', 'dd', 'details', 'dialog',
      'div', 'dl', 'dt', 'fieldset', 'figcaption', 'figure', 'footer', 'form',
      'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup',
      'li', 'main', 'nav', 'ol', 'p', 'section', 'summary',
      'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul'
    ]);

    // Written as they are, on a line of their own when white space sets them apart
    const ownLineTags = new Set([
      'pre', 'script', 'style', 'iframe', 'noscript', 'link', 'meta', 'base',
      'object', 'embed', 'textarea', 'template', 'hr'
    ]);

    const escapeText = (text) => text
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/ /g, '&nbsp;');

    // A block whose own style decides how white space or flow works is left alone
    const isBlock = (node) => node.nodeType === Node.ELEMENT_NODE
      && node.namespaceURI === HTML_NS
      && blockTags.has(node.localName)
      && !/white-space\s*:|display\s*:\s*inline/i.test(node.getAttribute('style') || '');

    const startsWithSpace = (node) => !node
      || isBlock(node)
      || (node.nodeType === Node.TEXT_NODE && /^[ \t\r\n\f]/.test(node.data));

    /**
     * @param {ParentNode} parent
     * @param {number} depth
     * @returns {string} lines, each ending in a newline
     */
    const serializeChildren = (parent, depth) => {
      const pad = INDENT.repeat(depth);
      const nodes = Array.from(parent.childNodes);
      let out = '';
      let line = '';
      let spaceBefore = false;

      const flush = () => {
        if (line) out += pad + line + '\n';
        line = '';
        spaceBefore = false;
      };

      nodes.forEach((node, index) => {
        if (node.nodeType === Node.TEXT_NODE) {
          const text = node.data.replace(/[ \t\r\n\f]+/g, ' ');
          if (text === '' || text === ' ') {
            spaceBefore = spaceBefore || text === ' ';
            return;
          }
          if (line && (spaceBefore || text.startsWith(' '))) line += ' ';
          line += escapeText(text.replace(/^ | $/g, ''));
          spaceBefore = text.endsWith(' ');
          return;
        }

        let piece;
        let ownLine = false;
        if (node.nodeType === Node.COMMENT_NODE) {
          piece = `<!--${node.data}-->`;
          ownLine = true;
        } else if (node.nodeType === Node.ELEMENT_NODE) {
          if (isBlock(node)) {
            flush();
            out += serializeBlock(node, depth);
            return;
          }
          piece = node.outerHTML;
          ownLine = ownLineTags.has(node.localName);
        } else {
          return;
        }

        if (ownLine && (!line || spaceBefore) && startsWithSpace(nodes[index + 1])) {
          flush();
          out += pad + piece + '\n';
          return;
        }
        if (line && spaceBefore) line += ' ';
        line += piece;
        spaceBefore = false;
      });

      flush();
      return out;
    };

    /**
     * @param {Element} node - a block element
     * @param {number} depth
     * @returns {string}
     */
    const serializeBlock = (node, depth) => {
      const pad = INDENT.repeat(depth);
      // The browser writes the start tag, so attribute values are escaped its way
      const shell = node.cloneNode(false).outerHTML;
      const closeTag = `</${node.localName}>`;
      const openTag = shell.endsWith(closeTag) ? shell.slice(0, -closeTag.length) : shell;

      if (!node.childNodes.length) {
        return pad + openTag + closeTag + '\n';
      }

      const inner = serializeChildren(node, depth + 1);
      const lines = inner.split('\n').filter(Boolean);
      const hasBlockChild = Array.from(node.childNodes).some(isBlock);

      if (!hasBlockChild && lines.length <= 1) {
        // All content is inline — keep on a single line for readability
        return pad + openTag + (lines[0] || '').replace(/^ +| +$/g, '') + closeTag + '\n';
      }

      return pad + openTag + '\n' + inner + pad + closeTag + '\n';
    };

    return serializeChildren(parseInert(html).content, 0).replace(/\n+$/, '');
  }

  /**
   * Get source content
   * @returns {string}
   */
  getSource() {
    return this.sourceEditor?.value || '';
  }

  /**
   * Set source content
   * @param {string} html
   */
  setSource(html) {
    if (this.sourceEditor) {
      this.sourceEditor.value = this.formatHtml(html);
    }
  }

  /**
   * Check if source view is active
   * @returns {boolean}
   */
  isSourceViewActive() {
    return this.isActive;
  }

  /**
   * Disable or enable all toolbar buttons except sourceView itself.
   * @param {boolean} disabled
   */
  _setToolbarSourceMode(disabled) {
    const toolbar = this.editor.toolbar;
    if (!toolbar) return;

    // Buttons that should remain usable in source mode
    const alwaysEnabled = new Set(['sourceView', 'fullscreen', 'print']);

    toolbar.buttons.forEach(({element}, id) => {
      if (alwaysEnabled.has(id)) return;
      element.disabled = disabled;
    });
  }

  destroy() {
    if (this.sourceEditor && this.sourceEditor.parentNode) {
      this.sourceEditor.parentNode.removeChild(this.sourceEditor);
    }
    super.destroy();
  }
}

export default SourceViewPlugin;
