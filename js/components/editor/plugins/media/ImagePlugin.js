/**
 * ImagePlugin - Insert and manage images
 * Integrates with FileBrowser for file selection
 *
 * @author Goragod Wiriya
 * @version 1.0
 */
import PluginBase from '../PluginBase.js';
import BaseDialog from '../../ui/dialogs/BaseDialog.js';
import EventBus from '../../core/EventBus.js';

class ImageDialog extends BaseDialog {
  constructor(editor, options = {}) {
    super(editor, {
      title: 'Insert Image',
      width: 500
    });
    this.pluginOptions = options;
  }

  buildBody() {
    // Tab navigation
    const tabs = document.createElement('div');
    tabs.className = 'rte-dialog-tabs';

    const urlTab = document.createElement('button');
    urlTab.type = 'button';
    urlTab.className = 'rte-dialog-tab active';
    urlTab.textContent = this.translate('URL');
    urlTab.dataset.tab = 'url';

    const uploadTab = document.createElement('button');
    uploadTab.type = 'button';
    uploadTab.className = 'rte-dialog-tab';
    uploadTab.textContent = this.translate('Upload');
    uploadTab.dataset.tab = 'upload';

    tabs.appendChild(urlTab);
    tabs.appendChild(uploadTab);
    this.body.appendChild(tabs);

    // Tab contents
    this.urlContent = document.createElement('div');
    this.urlContent.className = 'rte-dialog-tab-content active';
    this.urlContent.dataset.tab = 'url';

    this.uploadContent = document.createElement('div');
    this.uploadContent.className = 'rte-dialog-tab-content';
    this.uploadContent.dataset.tab = 'upload';

    // URL tab content
    this.urlField = this.createField({
      type: 'url',
      label: 'Image URL',
      id: 'rte-image-url',
      placeholder: 'https://example.com/image.jpg'
    });
    this.urlContent.appendChild(this.urlField);

    // Upload tab content
    const uploadZone = document.createElement('div');
    uploadZone.className = 'rte-upload-zone';
    uploadZone.innerHTML = `
      <div class="rte-upload-icon">📷</div>
      <div class="rte-upload-text">${this.translate('Drop image here or click to browse')}</div>
      <input type="file" accept="image/*" style="display: none;">
    `;

    this.fileInput = uploadZone.querySelector('input[type="file"]');
    uploadZone.addEventListener('click', () => this.fileInput.click());
    uploadZone.addEventListener('dragover', (e) => {
      e.preventDefault();
      uploadZone.classList.add('dragover');
    });
    uploadZone.addEventListener('dragleave', () => {
      uploadZone.classList.remove('dragover');
    });
    uploadZone.addEventListener('drop', (e) => {
      e.preventDefault();
      uploadZone.classList.remove('dragover');
      if (e.dataTransfer.files[0]) {
        this.handleFileSelect(e.dataTransfer.files[0]);
      }
    });
    this.fileInput.addEventListener('change', (e) => {
      if (e.target.files[0]) {
        this.handleFileSelect(e.target.files[0]);
      }
    });

    this.uploadContent.appendChild(uploadZone);

    // Preview area
    this.previewArea = document.createElement('div');
    this.previewArea.className = 'rte-image-preview';
    this.previewArea.style.display = 'none';
    this.uploadContent.appendChild(this.previewArea);

    // FileBrowser button
    if (this.pluginOptions.fileBrowser?.enabled !== false) {
      const browseBtn = document.createElement('button');
      browseBtn.type = 'button';
      browseBtn.className = 'rte-dialog-btn rte-dialog-btn-secondary';
      browseBtn.style.marginTop = '12px';
      browseBtn.style.width = '100%';
      browseBtn.textContent = this.translate('Browse files');
      browseBtn.addEventListener('click', () => this.openFileBrowser());
      this.uploadContent.appendChild(browseBtn);
    }

    this.body.appendChild(this.urlContent);
    this.body.appendChild(this.uploadContent);

    // Common fields (shown for both tabs)
    const commonFields = document.createElement('div');
    commonFields.className = 'rte-dialog-common-fields';
    commonFields.style.marginTop = '16px';
    commonFields.style.paddingTop = '16px';
    commonFields.style.borderTop = '1px solid var(--rte-border-color)';

    // Alt text
    this.altField = this.createField({
      type: 'text',
      label: 'Alt text',
      id: 'rte-image-alt',
      placeholder: 'Description for accessibility'
    });
    commonFields.appendChild(this.altField);

    // Width/Height
    const sizeRow = document.createElement('div');
    sizeRow.style.display = 'flex';
    sizeRow.style.gap = '12px';

    this.widthField = this.createField({
      type: 'text',
      label: 'Width',
      id: 'rte-image-width',
      placeholder: 'Auto'
    });
    this.widthField.style.flex = '1';
    this.widthLabel = this.widthField.querySelector('label');

    this.heightField = this.createField({
      type: 'text',
      label: 'Height',
      id: 'rte-image-height',
      placeholder: 'Auto'
    });
    this.heightField.style.flex = '1';
    this.heightLabel = this.heightField.querySelector('label');

    sizeRow.appendChild(this.widthField);
    sizeRow.appendChild(this.heightField);
    commonFields.appendChild(sizeRow);

    // Alignment
    this.alignField = this.createField({
      type: 'select',
      label: 'Alignment',
      id: 'rte-image-align',
      options: [
        {value: '', label: 'None'},
        {value: 'left', label: 'Left'},
        {value: 'center', label: 'Center'},
        {value: 'right', label: 'Right'}
      ]
    });
    commonFields.appendChild(this.alignField);

    this.body.appendChild(commonFields);

    // Tab switching
    tabs.addEventListener('click', (e) => {
      if (e.target.classList.contains('rte-dialog-tab')) {
        tabs.querySelectorAll('.rte-dialog-tab').forEach(t => t.classList.remove('active'));
        e.target.classList.add('active');

        const tabName = e.target.dataset.tab;
        this.body.querySelectorAll('.rte-dialog-tab-content').forEach(c => {
          c.classList.toggle('active', c.dataset.tab === tabName);
        });
      }
    });
  }

  handleFileSelect(file) {
    if (!file.type.startsWith('image/')) {
      this.showError('Please select an image file');
      return;
    }

    // Store file for upload
    this.selectedFile = file;

    // Show local preview (base64 is only for preview display, NOT inserted into editor)
    const reader = new FileReader();
    reader.onload = (e) => {
      this.previewArea.innerHTML = `<img src="${e.target.result}" alt="Preview">`;
      this.previewArea.style.display = 'block';
    };
    reader.readAsDataURL(file);
  }

  openFileBrowser() {
    if (typeof FileBrowser !== 'undefined') {
      const fb = new FileBrowser({
        ...this.pluginOptions.fileBrowser?.options,
        allowedFileTypes: 'image/*',
        multiSelect: false,
        onSelect: (file) => {
          const urlInput = this.urlField.querySelector('input');
          urlInput.value = file.url;

          // Switch to URL tab
          this.body.querySelectorAll('.rte-dialog-tab').forEach(t => {
            t.classList.toggle('active', t.dataset.tab === 'url');
          });
          this.body.querySelectorAll('.rte-dialog-tab-content').forEach(c => {
            c.classList.toggle('active', c.dataset.tab === 'url');
          });
        }
      });
      fb.open();
    }
  }

  populate(data) {
    const urlInput = this.urlField.querySelector('input');
    const altInput = this.altField.querySelector('input');
    const widthInput = this.widthField.querySelector('input');
    const heightInput = this.heightField.querySelector('input');
    const alignSelect = this.alignField.querySelector('select');

    urlInput.value = data.src || '';
    altInput.value = data.alt || '';
    widthInput.value = data.width || '';
    heightInput.value = data.height || '';
    alignSelect.value = data.align || '';

    this.selectedFile = null;
    this.previewArea.style.display = 'none';
    this.previewArea.innerHTML = '';

    // Store editing state
    this.isEdit = data.isEdit || false;
    this.existingImage = data.element || null;

    // Adapt labels and placeholders for edit mode (responsive sizing)
    if (this.isEdit) {
      this.setTitle(this.translate('Edit Image'));
      if (this.widthLabel) this.widthLabel.textContent = this.translate('Max width');
      if (this.heightLabel) this.heightLabel.textContent = this.translate('Max height');
      widthInput.placeholder = '100%, 600px, …';
      heightInput.placeholder = '400px, …';
    } else {
      this.setTitle(this.translate('Insert Image'));
      if (this.widthLabel) this.widthLabel.textContent = this.translate('Width');
      if (this.heightLabel) this.heightLabel.textContent = this.translate('Height');
      widthInput.placeholder = 'Auto';
      heightInput.placeholder = 'Auto';
    }
  }

  getData() {
    const widthVal = this.widthField.querySelector('input').value.trim();
    const heightVal = this.heightField.querySelector('input').value.trim();

    return {
      // Only return a real URL — never base64; file upload handled by insertImage()
      src: this.urlField.querySelector('input').value.trim(),
      alt: this.altField.querySelector('input').value.trim(),
      width: this.isEdit ? '' : widthVal,
      height: this.isEdit ? '' : heightVal,
      maxWidth: this.isEdit ? widthVal : '',
      maxHeight: this.isEdit ? heightVal : '',
      align: this.alignField.querySelector('select').value,
      file: this.selectedFile,
      isEdit: this.isEdit,
      existingImage: this.existingImage
    };
  }

  validate() {
    this.clearError();
    const data = this.getData();

    if (!data.src && !data.file) {
      this.showError('Please provide an image URL or upload a file');
      return false;
    }

    // Block dangerous URI schemes — only allow http, https, relative paths, and data:image/*
    if (data.src && /^\s*(javascript:|vbscript:|data:(?!image\/))/i.test(data.src)) {
      this.showError('Invalid image URL');
      return false;
    }

    return true;
  }
}

class ImagePlugin extends PluginBase {
  static pluginName = 'image';

  init() {
    super.init();

    // Single dialog for both insert and edit modes
    this.dialog = new ImageDialog(this.editor, this.options);
    this.dialog.onConfirm = (data) => this.insertImage(data);

    // Track last single-clicked image for toolbar button usage
    this.selectedImageElement = null;

    // Register command
    this.registerCommand('insertImage', {
      execute: (data) => this.insertImage(data)
    });

    // Listen for toolbar button click
    this.subscribe(EventBus.Events.TOOLBAR_BUTTON_CLICK, (event) => {
      if (event.id === 'image') {
        this.openDialog();
      }
    });

    // Single click on image — select it visually (handled by browser) and mark as selected
    this.subscribe(EventBus.Events.IMAGE_CLICK, (event) => {
      this.selectedImageElement = event.element;
    });

    // Double-click on image — open edit dialog immediately
    this.subscribe(EventBus.Events.IMAGE_DBLCLICK, (event) => {
      this.openDialog(event.element);
    });

    // Listen for file drops
    this.subscribe('content:drop', (event) => {
      const imageFiles = event.files.filter(f => f.type.startsWith('image/'));
      if (imageFiles.length > 0) {
        event.event.preventDefault();
        this.handleDroppedFiles(imageFiles);
      }
    });
  }

  /**
   * Open image dialog
   * @param {HTMLImageElement|null} imgElement - Existing image to edit (optional)
   */
  openDialog(imgElement = null) {
    // Restore selection (saved by toolbar's mousedown) to check if on image
    this.restoreSelection();

    // Priority: explicit imgElement > single-clicked image > selection-based detection
    const img = imgElement || this.selectedImageElement || this.getSelectedImage();
    this.selectedImageElement = null; // clear after use

    if (img) {
      // Edit mode — open dialog pre-populated with existing image data
      this.dialog.open({
        src: img.src || '',
        alt: img.alt || '',
        // Read max-width/max-height first; fall back to HTML attributes for legacy content
        width: img.style.maxWidth || img.getAttribute('width') || '',
        height: img.style.maxHeight || img.getAttribute('height') || '',
        align: this.getImageAlignment(img),
        isEdit: true,
        element: img
      });
    } else {
      // Insert mode — blank dialog
      this.dialog.open({src: '', alt: '', width: '', height: '', align: '', isEdit: false, element: null});
    }
  }

  /**
   * Get selected image
   * @returns {HTMLImageElement|null}
   */
  getSelectedImage() {
    const selection = this.getSelection();
    const range = selection.getRange();

    if (range && range.collapsed) {
      const node = range.startContainer;
      if (node.nodeType === Node.ELEMENT_NODE && node.tagName === 'IMG') {
        return node;
      }
      if (node.parentElement && node.parentElement.tagName === 'IMG') {
        return node.parentElement;
      }
    }

    return null;
  }

  /**
   * Get image alignment
   * @param {HTMLImageElement} img
   * @returns {string}
   */
  getImageAlignment(img) {
    const style = img.style;
    if (style.float === 'left') return 'left';
    if (style.float === 'right') return 'right';
    if (style.display === 'block' && style.marginLeft === 'auto' && style.marginRight === 'auto') {
      return 'center';
    }
    return '';
  }

  /**
   * Insert a new image or update an existing one
   * @param {Object} data - Image data from dialog
   */
  async insertImage(data) {
    // ── EDIT MODE: update existing image in-place ──────────────────────────
    if (data.isEdit && data.existingImage) {
      const img = data.existingImage;

      if (data.file) {
        try {
          img.src = await this.uploadImageToFileBrowser(data.file);
        } catch (error) {
          this.notify(this.translate('Failed to upload image') + ': ' + error.message, 'error');
          return;
        }
      } else if (data.src) {
        img.src = data.src;
      }

      img.alt = data.alt;
      // Switch from HTML size attributes to CSS max-width/max-height for responsive sizing
      img.removeAttribute('width');
      img.removeAttribute('height');
      img.style.maxWidth = data.maxWidth || '';
      img.style.maxHeight = data.maxHeight || '';
      this.applyImageAlignment(img, data.align);
      this.recordHistory(true);
      this.focusEditor();
      return;
    }

    // ── INSERT MODE: create a new image ───────────────────────────────────
    let src = data.src;

    // Upload file if provided — always upload, never insert base64 into the editor
    if (data.file) {
      try {
        src = await this.uploadImageToFileBrowser(data.file);
      } catch (error) {
        this.notify(this.translate('Failed to upload image') + ': ' + error.message, 'error');
        return;
      }
    }

    if (!src) {
      this.notify(this.translate('Please provide an image URL or upload a file'), 'error');
      return;
    }

    this.restoreSelection();

    // Create new image (responsive — no fixed width/height attributes)
    const img = document.createElement('img');
    img.src = src;
    img.alt = data.alt || '';
    this.applyImageAlignment(img, data.align);
    this.insertHtml(img.outerHTML);

    this.recordHistory(true);
    this.focusEditor();
  }

  /**
   * Apply alignment to image
   * @param {HTMLImageElement} img
   * @param {string} align
   */
  applyImageAlignment(img, align) {
    img.style.float = '';
    img.style.display = '';
    img.style.marginLeft = '';
    img.style.marginRight = '';

    switch (align) {
      case 'left':
        img.style.float = 'left';
        img.style.marginRight = '1em';
        break;
      case 'right':
        img.style.float = 'right';
        img.style.marginLeft = '1em';
        break;
      case 'center':
        img.style.display = 'block';
        img.style.marginLeft = 'auto';
        img.style.marginRight = 'auto';
        break;
    }
  }

  /**
   * Upload image to server via FileBrowser API (with JWT auth)
   * @param {File} file
   * @returns {Promise<string>} Absolute URL of uploaded image
   */
  async uploadImageToFileBrowser(file) {
    // Prefer the FileBrowser's configured upload endpoint
    const uploadUrl = this.options.fileBrowser?.options?.apiActions?.upload
      || this.options.uploadUrl;

    if (!uploadUrl) {
      throw new Error('Upload URL not configured');
    }

    const formData = new FormData();
    formData.append('file', file);
    // Target folder — use configured uploadPath or root
    formData.append('path', this.options.uploadPath || '/');

    // Use credentials: 'include' so the auth_token cookie is sent automatically
    // — same mechanism as all other API calls in this project
    const requestOptions = Now.applyRequestLanguage({
      method: 'POST',
      body: formData,
      credentials: 'include'
    });

    const response = await fetch(uploadUrl, requestOptions);

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    const result = await response.json();

    if (!result.success) {
      throw new Error(result.error || 'Upload failed');
    }

    // Return the public URL of the uploaded file
    const url = result.file?.url || result.data?.url || null;
    if (!url) {
      throw new Error('Server did not return file URL');
    }
    return url;
  }

  /**
   * Handle dropped files
   * @param {File[]} files
   */
  handleDroppedFiles(files) {
    files.forEach(async (file) => {
      try {
        const url = await this.uploadImageToFileBrowser(file);
        this.insertImage({
          src: url,
          alt: file.name.replace(/\.[^.]+$/, ''),
          width: '',
          height: '',
          align: ''
        });
      } catch (error) {
        this.notify(this.translate('Failed to upload image') + ': ' + error.message, 'error');
      }
    });
  }

  destroy() {
    this.dialog?.destroy();
    super.destroy();
  }
}

export default ImagePlugin;
