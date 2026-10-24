/**
 * IframePlugin - Embed external content via <iframe>
 * Supports Google Maps, custom embed codes, and any https:// URL
 *
 * @author Goragod Wiriya
 * @version 1.0
 */
import PluginBase from '../PluginBase.js';
import BaseDialog from '../../ui/dialogs/BaseDialog.js';
import EventBus from '../../core/EventBus.js';
import {readEmbed, toPlaceholders} from '../../core/EmbedPlaceholder.js';

class IframeDialog extends BaseDialog {
  constructor(editor) {
    super(editor, {
      title: 'Insert Iframe',
      width: 540
    });
  }

  buildBody() {
    // --- Source type toggle ---
    this.modeField = this.createField({
      type: 'select',
      label: 'Source type',
      id: 'rte-iframe-mode',
      options: [
        {label: 'URL', value: 'url'},
        {label: 'Embed code', value: 'code'}
      ]
    });
    this.body.appendChild(this.modeField);

    // --- URL mode ---
    this.urlWrapper = document.createElement('div');

    this.urlField = this.createField({
      type: 'url',
      label: 'URL (https://)',
      id: 'rte-iframe-url',
      placeholder: 'https://www.google.com/maps/embed?...'
    });
    this.urlWrapper.appendChild(this.urlField);

    this.body.appendChild(this.urlWrapper);

    // --- Embed-code mode ---
    this.codeWrapper = document.createElement('div');
    this.codeWrapper.style.display = 'none';

    this.codeField = this.createField({
      type: 'textarea',
      label: 'Embed code',
      id: 'rte-iframe-code',
      rows: 4,
      placeholder: '<iframe src="https://..." ...></iframe>'
    });
    this.codeWrapper.appendChild(this.codeField);

    this.body.appendChild(this.codeWrapper);

    // --- Size row ---
    const sizeRow = document.createElement('div');
    sizeRow.style.cssText = 'display:flex;gap:12px;margin-top:12px;';

    this.widthField = this.createField({
      type: 'text',
      label: 'Width',
      id: 'rte-iframe-width',
      value: '100%',
      placeholder: '100% or 600px'
    });
    this.widthField.style.flex = '1';

    this.heightField = this.createField({
      type: 'text',
      label: 'Height',
      id: 'rte-iframe-height',
      value: '450',
      placeholder: '450'
    });
    this.heightField.style.flex = '1';

    sizeRow.appendChild(this.widthField);
    sizeRow.appendChild(this.heightField);
    this.body.appendChild(sizeRow);

    // --- Allowfullscreen checkbox ---
    this.fullscreenField = this.createField({
      type: 'checkbox',
      id: 'rte-iframe-fullscreen',
      checkLabel: 'Allow fullscreen',
      checked: true
    });
    this.body.appendChild(this.fullscreenField);

    // --- Scrolling ---
    this.scrollingField = this.createField({
      type: 'checkbox',
      id: 'rte-iframe-scrolling',
      checkLabel: 'Allow scrolling',
      checked: true
    });
    this.body.appendChild(this.scrollingField);

    // --- Border ---
    this.borderField = this.createField({
      type: 'checkbox',
      id: 'rte-iframe-border',
      checkLabel: 'Show border',
      checked: false
    });
    this.body.appendChild(this.borderField);

    // --- Preview area ---
    this.previewArea = document.createElement('div');
    this.previewArea.className = 'rte-video-preview';
    this.previewArea.style.cssText = `
      margin-top:16px;
      padding:12px;
      background:var(--rte-bg-secondary);
      border-radius:6px;
      display:none;
    `;
    this.body.appendChild(this.previewArea);

    // Events
    const modeSelect = this.modeField.querySelector('select');
    modeSelect.addEventListener('change', () => this._onModeChange());

    const urlInput = this.urlField.querySelector('input');
    urlInput.addEventListener('input', () => this._updatePreview());

    this.widthField.querySelector('input').addEventListener('input', () => this._updatePreview());
    this.heightField.querySelector('input').addEventListener('input', () => this._updatePreview());
    this.fullscreenField.querySelector('input').addEventListener('change', () => this._updatePreview());
  }

  _onModeChange() {
    const mode = this.modeField.querySelector('select').value;
    this.urlWrapper.style.display = mode === 'url' ? '' : 'none';
    this.codeWrapper.style.display = mode === 'code' ? '' : 'none';
    this._updatePreview();
  }

  _updatePreview() {
    const data = this.getData();
    if (!data.src) {
      this.previewArea.style.display = 'none';
      return;
    }
    this.previewArea.style.display = 'block';
    this.previewArea.innerHTML = '';

    const iframe = document.createElement('iframe');
    iframe.src = data.src;
    iframe.style.cssText = `width:${data.width};height:${data.height};border:${data.border ? '1px solid var(--rte-border-color)' : '0'};display:block;max-width:100%;`;
    if (data.allowFullscreen) iframe.allowFullscreen = true;
    if (!data.scrolling) iframe.setAttribute('scrolling', 'no');

    this.previewArea.appendChild(iframe);
  }

  populate(data) {
    const modeSelect = this.modeField.querySelector('select');
    const urlInput = this.urlField.querySelector('input');
    const codeInput = this.codeField.querySelector('textarea');
    const widthInput = this.widthField.querySelector('input');
    const heightInput = this.heightField.querySelector('input');

    modeSelect.value = data.mode || 'url';
    urlInput.value = data.url || '';
    codeInput.value = data.code || '';
    widthInput.value = data.width || '100%';
    heightInput.value = data.height || '450';

    this.fullscreenField.querySelector('input').checked = data.allowFullscreen !== false;
    this.scrollingField.querySelector('input').checked = data.scrolling !== false;
    this.borderField.querySelector('input').checked = data.border === true;

    this._onModeChange();
    if (data.url || data.code) this._updatePreview();
  }

  getData() {
    const mode = this.modeField.querySelector('select').value;
    const url = this.urlField.querySelector('input').value.trim();
    const code = this.codeField.querySelector('textarea').value.trim();
    const width = this.widthField.querySelector('input').value.trim() || '100%';
    const height = this.heightField.querySelector('input').value.trim() || '450';
    const allowFullscreen = this.fullscreenField.querySelector('input').checked;
    const scrolling = this.scrollingField.querySelector('input').checked;
    const border = this.borderField.querySelector('input').checked;

    // Derive src from mode
    let src = '';
    if (mode === 'url') {
      src = url;
    } else {
      // Extract src from embed code
      const match = code.match(/src\s*=\s*["']([^"']+)["']/i);
      src = match ? match[1] : '';
    }

    return {mode, url, code, src, width, height, allowFullscreen, scrolling, border};
  }

  validate() {
    this.clearError();
    const data = this.getData();

    if (!data.src) {
      const field = data.mode === 'url' ? this.urlField : this.codeField;
      this.showError(
        data.mode === 'url' ? 'Please enter a URL' : 'Please enter embed code',
        field
      );
      return false;
    }

    // Must be https://
    if (!/^https:\/\//i.test(data.src.trim())) {
      const field = data.mode === 'url' ? this.urlField : this.codeField;
      this.showError('Only https:// URLs are allowed for security reasons', field);
      return false;
    }

    return true;
  }
}

// ─────────────────────────────────────────────
class IframePlugin extends PluginBase {
  static pluginName = 'iframe';

  init() {
    super.init();

    this.dialog = new IframeDialog(this.editor);
    this.dialog.onConfirm = (data) => this._editing ? this._updateEmbed(data) : this.insertIframe(data);
    this._editing = null;

    // Listen for toolbar button click
    this.subscribe(EventBus.Events.TOOLBAR_BUTTON_CLICK, (event) => {
      if (event.id === 'iframe') {
        this.openDialog();
      }
    });

    // Double-click on an iframe (shown as a placeholder while editing) to edit it
    this.subscribe(EventBus.Events.EMBED_DBLCLICK, (event) => {
      this._editEmbed(event.element);
    });

    // Register command
    this.registerCommand('insertIframe', {
      execute: (data) => this.insertIframe(data)
    });
  }

  openDialog(initialData = {}) {
    this._editing = null;
    this.saveSelection();
    this.dialog.open(initialData);
  }

  /**
   * Open the dialog pre-filled from the iframe a placeholder stands for
   * @param {HTMLImageElement} placeholder
   */
  _editEmbed(placeholder) {
    const iframe = readEmbed(placeholder);
    if (!iframe) return;
    this.saveSelection();

    const data = {
      mode: 'url',
      url: iframe.getAttribute('src') || '',
      width: iframe.style.width || iframe.getAttribute('width') || '100%',
      height: iframe.style.height || iframe.getAttribute('height') || '450',
      allowFullscreen: iframe.hasAttribute('allowfullscreen'),
      scrolling: iframe.getAttribute('scrolling') !== 'no',
      border: this._hasBorder(iframe)
    };

    this._editing = {placeholder, iframe, data};
    this.dialog.open(data);
  }

  /**
   * @param {HTMLIFrameElement} iframe
   * @returns {boolean}
   */
  _hasBorder(iframe) {
    const border = iframe.style.border || iframe.style.borderWidth;
    if (border) return !/^(0|none)\b/.test(border);
    return parseInt(iframe.getAttribute('frameborder') ?? '0', 10) !== 0;
  }

  /**
   * Apply the dialog to the iframe being edited. In URL mode the existing iframe is
   * changed in place, so styling the dialog does not know about (e.g. the absolute
   * positioning of a responsive video) is kept; embed code replaces it outright.
   * @param {Object} data
   */
  _updateEmbed(data) {
    const {placeholder, iframe, data: before} = this._editing;
    this._editing = null;
    if (!placeholder.isConnected) {
      this.insertIframe(data);
      return;
    }

    let html;
    if (data.mode === 'code' && data.code) {
      html = data.code;
    } else {
      iframe.setAttribute('src', data.src);
      if (data.width !== before.width) this._applySize(iframe, 'width', data.width);
      if (data.height !== before.height) this._applySize(iframe, 'height', data.height);
      iframe.toggleAttribute('allowfullscreen', data.allowFullscreen);
      if (data.scrolling) {
        iframe.removeAttribute('scrolling');
      } else {
        iframe.setAttribute('scrolling', 'no');
      }
      if (data.border !== before.border) {
        iframe.removeAttribute('frameborder');
        iframe.style.border = data.border ? '1px solid #ccc' : '0';
      }
      html = iframe.outerHTML;
    }

    if (this.editor.options.sanitize) html = this.editor.sanitizeHtml(html);
    const fragment = document.createRange().createContextualFragment(html);
    toPlaceholders(fragment);
    placeholder.replaceWith(fragment);

    this.recordHistory(true);
    this.focusEditor();
  }

  /**
   * Set a size where the iframe already keeps it (width/height attribute or style)
   * @param {HTMLIFrameElement} iframe
   * @param {'width'|'height'} prop
   * @param {string} value
   */
  _applySize(iframe, prop, value) {
    if (!iframe.style[prop] && iframe.hasAttribute(prop)) {
      iframe.setAttribute(prop, value);
    } else {
      iframe.style[prop] = this._cssSize(value);
    }
  }

  /**
   * A bare number is pixels ("450" → "450px"); anything else is used as given
   * @param {string} value
   * @returns {string}
   */
  _cssSize(value) {
    return /^\d+(\.\d+)?$/.test(value) ? `${value}px` : value;
  }

  /**
   * Build the iframe HTML string from data
   * @param {Object} data
   * @returns {string}
   */
  _buildHtml(data) {
    if (data.mode === 'code' && data.code) {
      // Wrap raw embed code in a div for consistent styling
      return `<div class="rte-iframe-wrapper" style="margin:1em 0;">${data.code}</div>`;
    }

    const fullscreen = data.allowFullscreen ? ' allowfullscreen' : '';
    const scrolling = data.scrolling ? '' : ' scrolling="no"';
    const size = `width:${this._cssSize(data.width)};height:${this._cssSize(data.height)}`;
    const border = data.border
      ? ` style="${size};border:1px solid #ccc;display:block;"`
      : ` style="${size};border:0;display:block;"`;
    const loading = ' loading="lazy"';

    return `<div class="rte-iframe-wrapper" style="margin:1em 0;"><iframe src="${data.src}"${border}${fullscreen}${scrolling}${loading}></iframe></div>`;
  }

  /**
   * Insert iframe into editor
   * @param {Object} data
   */
  insertIframe(data) {
    this.restoreSelection();
    this.insertHtml(this._buildHtml(data));
    this.recordHistory(true);
    this.focusEditor();
  }

  destroy() {
    this.dialog?.destroy();
    super.destroy();
  }
}

export default IframePlugin;
