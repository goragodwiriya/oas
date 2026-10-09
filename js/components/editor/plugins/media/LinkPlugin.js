/**
 * LinkPlugin - Insert, edit, and remove links
 *
 * @author Goragod Wiriya
 * @version 1.0
 */
import PluginBase from '../PluginBase.js';
import BaseDialog from '../../ui/dialogs/BaseDialog.js';
import EventBus from '../../core/EventBus.js';
import {getFileBrowserConfig, getUploadUrl, uploadFile} from './upload.js';

class LinkDialog extends BaseDialog {
  /**
   * @param {RichTextEditor} editor
   * @param {Object} options - Link plugin options
   */
  constructor(editor, options = {}) {
    super(editor, {
      title: 'Insert Link',
      width: 440
    });
    this.pluginOptions = options;
    this.uploading = false;
  }

  buildBody() {
    // URL field
    this.urlField = this.createField({
      type: 'url',
      label: 'URL',
      id: 'rte-link-url',
      placeholder: 'https://example.com',
      required: true
    });
    this.body.appendChild(this.urlField);

    // Link to a file: upload one, or pick from the file browser
    const uploadUrl = getUploadUrl(this.editor, this.pluginOptions);
    const fileBrowser = getFileBrowserConfig(this.editor, this.pluginOptions);
    const canBrowse = fileBrowser && fileBrowser.enabled !== false && typeof FileBrowser !== 'undefined';
    if (uploadUrl || canBrowse) {
      this.body.appendChild(this.buildFileRow(uploadUrl, canBrowse));
    }

    // Display text field
    this.textField = this.createField({
      type: 'text',
      label: 'Display text',
      id: 'rte-link-text',
      placeholder: 'Link text'
    });
    this.body.appendChild(this.textField);

    // Title field
    this.titleField = this.createField({
      type: 'text',
      label: 'Title',
      id: 'rte-link-title',
      placeholder: 'Tooltip text (optional)'
    });
    this.body.appendChild(this.titleField);

    // Open in new tab
    this.newTabField = this.createField({
      type: 'checkbox',
      id: 'rte-link-newtab',
      checkLabel: 'Open in new tab'
    });
    this.body.appendChild(this.newTabField);
  }

  /**
   * Upload / browse buttons under the URL field; files can also be dropped
   * anywhere on the dialog.
   * @param {string|null} uploadUrl
   * @param {boolean} canBrowse
   * @returns {HTMLElement}
   */
  buildFileRow(uploadUrl, canBrowse) {
    const row = document.createElement('div');
    row.className = 'rte-dialog-field rte-link-file';

    if (uploadUrl) {
      this.fileInput = document.createElement('input');
      this.fileInput.type = 'file';
      this.fileInput.hidden = true;
      this.fileInput.setAttribute('data-form-exclude', '');
      this.fileInput.addEventListener('change', (e) => {
        if (e.target.files[0]) this.handleFileUpload(e.target.files[0]);
        e.target.value = '';
      });
      row.appendChild(this.fileInput);

      this.uploadBtn = document.createElement('button');
      this.uploadBtn.type = 'button';
      this.uploadBtn.className = 'rte-dialog-btn rte-dialog-btn-secondary';
      this.uploadBtn.textContent = this.translate('Upload file');
      this.uploadBtn.addEventListener('click', () => this.fileInput.click());
      row.appendChild(this.uploadBtn);

      this.body.addEventListener('dragover', (e) => {
        if (e.dataTransfer?.types?.includes('Files')) {
          e.preventDefault();
          row.classList.add('dragover');
        }
      });
      this.body.addEventListener('dragleave', (e) => {
        if (!this.body.contains(e.relatedTarget)) row.classList.remove('dragover');
      });
      this.body.addEventListener('drop', (e) => {
        row.classList.remove('dragover');
        const file = e.dataTransfer?.files?.[0];
        if (file) {
          e.preventDefault();
          this.handleFileUpload(file);
        }
      });
    }

    if (canBrowse) {
      const browseBtn = document.createElement('button');
      browseBtn.type = 'button';
      browseBtn.className = 'rte-dialog-btn rte-dialog-btn-secondary';
      browseBtn.textContent = this.translate('Browse files');
      browseBtn.addEventListener('click', () => this.openFileBrowser());
      row.appendChild(browseBtn);
    }

    this.fileStatus = document.createElement('span');
    this.fileStatus.className = 'rte-link-file-status';
    this.fileStatus.setAttribute('aria-live', 'polite');
    row.appendChild(this.fileStatus);

    return row;
  }

  /**
   * Upload a file and link to it
   * @param {File} file
   */
  async handleFileUpload(file) {
    if (this.uploading) return;

    this.clearError();
    const maxSize = this.pluginOptions.maxFileSize;
    if (maxSize && file.size > maxSize) {
      this.showError('File too large', this.urlField);
      return;
    }

    this.uploading = true;
    this.setLoading(true);
    if (this.uploadBtn) this.uploadBtn.disabled = true;
    this.fileStatus.textContent = this.translate('Uploading…');

    try {
      const uploaded = await uploadFile(
        file,
        getUploadUrl(this.editor, this.pluginOptions),
        this.pluginOptions.uploadPath || '/',
        this.getStorage()
      );
      this.setFile(uploaded.url, file.name);
      this.fileStatus.textContent = file.name;
    } catch (error) {
      this.fileStatus.textContent = '';
      this.showError(`${this.translate('Failed to upload file')}: ${this.translate(error.message)}`, this.urlField);
    } finally {
      this.uploading = false;
      this.setLoading(false);
      if (this.uploadBtn) this.uploadBtn.disabled = false;
    }
  }

  /**
   * FileBrowser storage for linked files (server config 'storages'); the server
   * falls back to its default storage when it has no such storage
   * @returns {string}
   */
  getStorage() {
    return this.pluginOptions.storage || 'file';
  }

  /**
   * Pick an existing file (any type) from the file browser — opens on the
   * files storage, the other storages stay a tab away
   */
  openFileBrowser() {
    const fileBrowser = getFileBrowserConfig(this.editor, this.pluginOptions);
    const fb = new FileBrowser({
      ...fileBrowser?.options,
      allowedFileTypes: this.pluginOptions.allowedFileTypes || '*',
      storage: this.getStorage(),
      activeTab: 2,
      multiSelect: false,
      onSelect: (file) => {
        if (file?.url) {
          this.clearError();
          this.setFile(file.url, file.name);
          this.fileStatus.textContent = file.name || '';
        }
      }
    });
    fb.open();
  }

  /**
   * Put a file URL in the dialog; its name becomes the link text when none is set
   * @param {string} url
   * @param {string} name
   */
  setFile(url, name) {
    this.urlField.querySelector('input').value = url;
    const textInput = this.textField.querySelector('input');
    if (!textInput.value.trim() && name) {
      textInput.value = name;
    }
  }

  handleConfirm() {
    // Wait for the upload to finish before inserting
    if (this.uploading) return;
    super.handleConfirm();
  }

  buildFooter() {
    // Remove link button (only when editing)
    this.removeBtn = document.createElement('button');
    this.removeBtn.type = 'button';
    this.removeBtn.className = 'rte-dialog-btn rte-dialog-btn-danger';
    this.removeBtn.textContent = this.translate('Remove link');
    this.removeBtn.style.marginRight = 'auto';
    this.removeBtn.style.display = 'none';
    this.removeBtn.addEventListener('click', () => {
      this.onRemove();
      this.close();
    });
    this.footer.appendChild(this.removeBtn);

    // Default cancel/confirm
    super.buildFooter();
  }

  populate(data) {
    const urlInput = this.urlField.querySelector('input');
    const textInput = this.textField.querySelector('input');
    const titleInput = this.titleField.querySelector('input');
    const newTabInput = this.newTabField.querySelector('input');

    urlInput.value = data.url || '';
    textInput.value = data.text || '';
    titleInput.value = data.title || '';
    newTabInput.checked = data.newTab || false;

    // Show remove button if editing existing link
    this.removeBtn.style.display = data.isEdit ? 'block' : 'none';

    if (this.fileStatus) this.fileStatus.textContent = '';

    // Store editing state
    this.isEdit = data.isEdit || false;
    this.existingLink = data.element || null;
  }

  getData() {
    const urlInput = this.urlField.querySelector('input');
    const textInput = this.textField.querySelector('input');
    const titleInput = this.titleField.querySelector('input');
    const newTabInput = this.newTabField.querySelector('input');

    return {
      url: urlInput.value.trim(),
      text: textInput.value.trim(),
      title: titleInput.value.trim(),
      newTab: newTabInput.checked,
      isEdit: this.isEdit,
      existingLink: this.existingLink
    };
  }

  validate() {
    this.clearError();
    const data = this.getData();

    if (!data.url) {
      this.showError('Please enter a URL', this.urlField);
      return false;
    }

    // Block script URIs
    if (/^\s*(javascript|vbscript|data):/i.test(data.url)) {
      this.showError('Invalid link URL', this.urlField);
      return false;
    }

    // Basic URL validation — keep absolute, mail/phone, anchor and site-relative links
    if (!data.url.match(/^(https?:\/\/|mailto:|tel:|#|\/|\.\.?\/|\?)/i)) {
      // Auto-add https://
      const urlInput = this.urlField.querySelector('input');
      urlInput.value = 'https://' + data.url;
    }

    return true;
  }

  onRemove() {
    // Override in plugin
  }
}

class LinkPlugin extends PluginBase {
  static pluginName = 'link';

  init() {
    super.init();

    // Create dialog
    this.dialog = new LinkDialog(this.editor, this.options);
    this.dialog.onConfirm = (data) => this.insertLink(data);
    this.dialog.onRemove = () => this.removeLink();

    // Register command
    this.registerCommand('insertLink', {
      execute: (data) => this.insertLink(data),
      isActive: () => this.isInLink()
    });

    this.registerCommand('removeLink', {
      execute: () => this.removeLink()
    });

    // Register shortcut
    this.registerShortcut('ctrl+k', () => this.openDialog());

    // Listen for toolbar button click
    this.subscribe(EventBus.Events.TOOLBAR_BUTTON_CLICK, (event) => {
      if (event.id === 'link') {
        this.openDialog();
      }
    });
  }

  /**
   * Open link dialog
   */
  openDialog() {
    // Selection is already saved by toolbar's mousedown handler
    // Restore it temporarily to check if we're editing existing link
    this.restoreSelection();

    const link = this.getSelection().getAncestor('a');
    const selectedText = this.getSelection().getSelectedText();

    const data = {
      url: '',
      text: selectedText,
      title: '',
      newTab: false,
      isEdit: false,
      element: null
    };

    if (link) {
      data.url = link.href;
      data.text = link.textContent;
      data.title = link.title || '';
      data.newTab = link.target === '_blank';
      data.isEdit = true;
      data.element = link;
    }

    this.dialog.open(data);
  }

  /**
   * Insert or update link
   * @param {Object} data - Link data
   */
  insertLink(data) {
    this.restoreSelection();

    if (data.isEdit && data.existingLink) {
      // Update existing link
      data.existingLink.href = data.url;
      data.existingLink.title = data.title || '';

      if (data.newTab) {
        data.existingLink.target = '_blank';
        data.existingLink.rel = 'noopener noreferrer';
      } else {
        data.existingLink.removeAttribute('target');
        data.existingLink.removeAttribute('rel');
      }

      if (data.text) {
        data.existingLink.textContent = data.text;
      }
    } else {
      // Create new link
      const text = data.text || data.url;
      let html = `<a href="${this.escapeHtml(data.url)}"`;

      if (data.title) {
        html += ` title="${this.escapeHtml(data.title)}"`;
      }

      if (data.newTab) {
        html += ' target="_blank" rel="noopener noreferrer"';
      }

      html += `>${this.escapeHtml(text)}</a>`;

      // If there's a selection, wrap it
      if (this.getSelection().hasSelection()) {
        this.execute('createLink', data.url);
        // Update the created link with additional attributes
        const newLink = this.getSelection().getAncestor('a');
        if (newLink) {
          if (data.title) newLink.title = data.title;
          if (data.newTab) {
            newLink.target = '_blank';
            newLink.rel = 'noopener noreferrer';
          }
        }
      } else {
        this.insertHtml(html);
      }
    }

    this.recordHistory(true);
    this.focusEditor();
  }

  /**
   * Remove link
   */
  removeLink() {
    this.restoreSelection();
    this.execute('unlink');
    this.recordHistory(true);
    this.focusEditor();
  }

  /**
   * Check if cursor is in a link
   * @returns {boolean}
   */
  isInLink() {
    return this.getSelection().containsElement('a');
  }

  /**
   * Escape HTML entities
   * @param {string} str
   * @returns {string}
   */
  escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  destroy() {
    this.dialog?.destroy();
    super.destroy();
  }
}

export default LinkPlugin;
