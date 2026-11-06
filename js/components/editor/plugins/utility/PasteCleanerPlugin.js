/**
 * PasteCleanerPlugin - Clean paste: strip all formatting, keep only text
 * Preserves line breaks (<br>), tables, and iframes.
 * Removes all class and id attributes.
 *
 * When active, every paste operation is intercepted and cleaned.
 * Toggle via toolbar button or Ctrl+Shift+V shortcut (always available via ContentArea).
 *
 * @author Goragod Wiriya
 * @version 1.0
 */
import PluginBase from '../PluginBase.js';
import EventBus from '../../core/EventBus.js';
import {cleanupHtmlFragment, DEFAULT_PASTE_ATTRIBUTE_ALLOW_MAP} from '../../core/HtmlCleanup.js';

class PasteCleanerPlugin extends PluginBase {
  static pluginName = 'pasteCleaner';

  /** Tags whose **structure** we keep (content is preserved for all tags). */
  static ALLOWED_TAGS = new Set([
    // text-level
    'br', 'p', 'div',
    // table
    'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col',
    // iframe / embedded
    'iframe',
    // lists (structural, often needed)
    'ul', 'ol', 'li',
    // basic semantics
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    'blockquote', 'pre', 'code', 'hr'
  ]);

  init() {
    super.init();

    this.isActive = false;

    // Merge user-provided allow list
    const extra = this.options.allowTags || [];
    this._allowedTags = new Set([...PasteCleanerPlugin.ALLOWED_TAGS, ...extra.map(t => t.toLowerCase())]);

    // Listen for toolbar button
    this.subscribe(EventBus.Events.TOOLBAR_BUTTON_CLICK, (event) => {
      if (event.id === 'pasteCleaner') {
        this.toggle();
      }
    });

    // Register command so toolbar can query isActive
    this.registerCommand('pasteCleaner', {
      execute: () => this.toggle(),
      isActive: () => this.isActive
    });

    // Intercept paste when active
    this.subscribe(EventBus.Events.CONTENT_PASTE, (event) => {
      if (this.isActive) {
        this.handlePaste(event);
      }
    });
  }

  /**
   * Toggle clean-paste mode
   * @param {boolean} [force] - Force on/off
   */
  toggle(force) {
    this.isActive = typeof force === 'boolean' ? force : !this.isActive;
    this.setButtonActive('pasteCleaner', this.isActive);

    const key = this.isActive ? 'Clean paste ON' : 'Clean paste OFF';
    this.notify(this.translate(key), 'info');
  }

  /**
   * Intercept paste and clean HTML
   * @param {ClipboardEvent} event
   */
  handlePaste(event) {
    const clipboardData = event.clipboardData || window.clipboardData;
    if (!clipboardData) return;

    // Skip if Ctrl+Shift+V — ContentArea already handles that as plain text
    if (event.shiftKey && (event.ctrlKey || event.metaKey)) return;

    event.preventDefault();

    const html = clipboardData.getData('text/html');
    const text = clipboardData.getData('text/plain');

    let cleaned;
    if (html) {
      cleaned = this.cleanHtml(html);
    } else if (text) {
      // Plain text — escape and convert newlines to <br>
      cleaned = text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/\n/g, '<br>');
    }

    if (cleaned) {
      this.editor.selection?.insertHtml(cleaned);
      this.emit(EventBus.Events.CONTENT_CHANGE);
    }
  }

  /**
   * Strip all formatting from HTML, keeping only allowed structural tags.
   * All class / id attributes are removed. Inline styles are removed.
   * @param {string} html
   * @returns {string}
   */
  cleanHtml(html) {
    return cleanupHtmlFragment(html, {
      removeSelectors: ['script', 'style', 'meta', 'link', 'title', 'head', 'noscript', 'object', 'embed', 'applet'],
      allowedTags: this._allowedTags,
      stripAllAttributes: true,
      attributeAllowMap: DEFAULT_PASTE_ATTRIBUTE_ALLOW_MAP,
      collapseBreaks: true,
      classPrefixes: []
    });
  }

  destroy() {
    super.destroy();
  }
}

export default PasteCleanerPlugin;
