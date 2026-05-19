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
    this.dialog.onConfirm = (data) => this.insertIframe(data);

    // Listen for toolbar button click
    this.subscribe(EventBus.Events.TOOLBAR_BUTTON_CLICK, (event) => {
      if (event.id === 'iframe') {
        this.openDialog();
      }
    });

    // Listen for double-click on existing iframe wrappers to edit
    this.editor.events?.on(EventBus.Events.CONTENT_DBLCLICK, (event) => {
      const wrapper = event.target?.closest?.('.rte-iframe-wrapper');
      if (wrapper) {
        this._editWrapper(wrapper);
      }
    });

    // Register command
    this.registerCommand('insertIframe', {
      execute: (data) => this.insertIframe(data)
    });
  }

  openDialog(initialData = {}) {
    this.saveSelection();
    this.dialog.open(initialData);
  }

  _editWrapper(wrapper) {
    const iframe = wrapper.querySelector('iframe');
    if (!iframe) return;
    this.saveSelection();

    const data = {
      mode: 'url',
      url: iframe.getAttribute('src') || '',
      src: iframe.getAttribute('src') || '',
      width: iframe.style.width || iframe.getAttribute('width') || '100%',
      height: iframe.style.height || iframe.getAttribute('height') || '450',
      allowFullscreen: iframe.hasAttribute('allowfullscreen'),
      scrolling: iframe.getAttribute('scrolling') !== 'no',
      border: parseInt(iframe.getAttribute('frameborder') || '0') !== 0
    };

    // Mark wrapper for replacement
    this._editingWrapper = wrapper;
    this.dialog.open(data);

    // Override onConfirm to replace existing
    this.dialog.onConfirm = (newData) => {
      const newHtml = this._buildHtml(newData);
      this._editingWrapper?.insertAdjacentHTML('afterend', newHtml);
      this._editingWrapper?.remove();
      this._editingWrapper = null;
      this.dialog.onConfirm = (d) => this.insertIframe(d);
      this.recordHistory(true);
      this.focusEditor();
    };
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
    const border = data.border
      ? ` style="width:${data.width};height:${data.height};border:1px solid #ccc;display:block;"`
      : ` style="width:${data.width};height:${data.height};border:0;display:block;"`;
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
