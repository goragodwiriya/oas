/**
 * SourceViewPlugin - Toggle HTML source view
 *
 * @author Goragod Wiriya
 * @version 1.0
 */
import PluginBase from '../PluginBase.js';
import EventBus from '../../core/EventBus.js';

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
   * Format HTML for better readability using DOM-based pretty-printer.
   * - Block elements get their own indented lines
   * - Inline-only blocks are kept on a single line
   * - <pre> content is preserved verbatim
   * @param {string} html
   * @returns {string}
   */
  formatHtml(html) {
    if (!html) return '';

    const INDENT = '  ';

    // Tags that create their own block/line
    const blockTags = new Set([
      'address', 'article', 'aside', 'blockquote', 'dd', 'details', 'dialog',
      'div', 'dl', 'dt', 'fieldset', 'figcaption', 'figure', 'footer', 'form',
      'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup',
      'li', 'main', 'nav', 'ol', 'p', 'section', 'summary',
      'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
      'style', 'script',
      'iframe'
    ]);

    /**
     * Serialize a single DOM node into indented HTML text.
     * @param {Node} node
     * @param {number} depth
     * @returns {string}
     */
    const serializeNode = (node, depth) => {
      const pad = INDENT.repeat(depth);

      // ── Text node ──────────────────────────────────────────────────────
      if (node.nodeType === Node.TEXT_NODE) {
        const text = node.textContent.replace(/\s+/g, ' ').trim();
        return text ? pad + text + '\n' : '';
      }

      if (node.nodeType !== Node.ELEMENT_NODE) return '';

      const tag = node.tagName.toLowerCase();
      const isBlock = blockTags.has(tag);

      // ── Raw text containers — preserve content exactly ────────────────
      if (tag === 'pre' || tag === 'style' || tag === 'script') {
        return pad + node.outerHTML + '\n';
      }

      // ── Inline / void elements — keep on one line ──────────────────────
      if (!isBlock) {
        return pad + node.outerHTML.trim() + '\n';
      }

      // ── Block element ──────────────────────────────────────────────────
      // Build opening tag string from live attributes (handles re-encoding)
      const attrStr = Array.from(node.attributes)
        .map(a => `${a.name}="${a.value.replace(/"/g, '&quot;')}"`)
        .join(' ');
      const openTag = `<${tag}${attrStr ? ' ' + attrStr : ''}>`;
      const closeTag = `</${tag}>`;

      // Empty element
      if (!node.childNodes.length) {
        return pad + openTag + closeTag + '\n';
      }

      // Check whether any direct child is itself a block element
      const hasBlockChild = Array.from(node.childNodes).some(
        child => child.nodeType === Node.ELEMENT_NODE
          && blockTags.has(child.tagName.toLowerCase())
          && child.tagName.toLowerCase() !== 'pre'
      );

      if (!hasBlockChild) {
        // All content is inline — keep on a single line for readability
        const inner = node.innerHTML.replace(/\s+/g, ' ').trim();
        return pad + openTag + inner + closeTag + '\n';
      }

      // Mixed or block children — expand with indentation
      let out = pad + openTag + '\n';
      for (const child of node.childNodes) {
        out += serializeNode(child, depth + 1);
      }
      out += pad + closeTag + '\n';
      return out;
    };

    // Parse into a temporary div so we get a proper DOM tree
    const temp = document.createElement('div');
    temp.innerHTML = html;

    let result = '';
    for (const child of temp.childNodes) {
      result += serializeNode(child, 0);
    }

    // Collapse runs of 3+ newlines to at most 2
    return result.replace(/\n{3,}/g, '\n\n').trim();
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
