/**
 * GalleryUploadPlugin - Upload multiple images at once and insert as a grid gallery.
 *
 * Toolbar button opens a multi-file upload dialog. Selected images are uploaded
 * via the same endpoint used by ImagePlugin, then inserted into the editor as a
 * responsive CSS grid using plain HTML — no JS events inside the editor.
 *
 * The output HTML uses data-media-viewer so the Now.js MediaViewer component
 * auto-initialises the gallery (click → slideshow) on the live page.
 *
 * @author Goragod Wiriya
 * @version 1.0
 */
import PluginBase from '../PluginBase.js';
import BaseDialog from '../../ui/dialogs/BaseDialog.js';
import EventBus from '../../core/EventBus.js';

/* ─── Gallery Upload Dialog ─────────────────────────────────────────────── */

class GalleryDialog extends BaseDialog {
  constructor(editor, pluginOptions = {}) {
    super(editor, {
      title: 'Upload Gallery',
      width: 560
    });
    this.pluginOptions = pluginOptions;
    this.pendingFiles = []; // {file, previewUrl}
  }

  buildBody() {
    // Upload zone
    this.uploadZone = document.createElement('div');
    this.uploadZone.className = 'rte-upload-zone rte-gallery-upload-zone';
    this.uploadZone.innerHTML = `
      <div class="rte-upload-icon">🖼️</div>
      <div class="rte-upload-text">${this.translate('Drop images here or click to browse')}</div>
      <input type="file" accept="image/*" multiple style="display:none">
    `;

    this.fileInput = this.uploadZone.querySelector('input[type="file"]');
    this.uploadZone.addEventListener('click', () => this.fileInput.click());
    this.uploadZone.addEventListener('dragover', (e) => {
      e.preventDefault();
      this.uploadZone.classList.add('dragover');
    });
    this.uploadZone.addEventListener('dragleave', () => {
      this.uploadZone.classList.remove('dragover');
    });
    this.uploadZone.addEventListener('drop', (e) => {
      e.preventDefault();
      this.uploadZone.classList.remove('dragover');
      this.addFiles(e.dataTransfer.files);
    });
    this.fileInput.addEventListener('change', (e) => {
      this.addFiles(e.target.files);
      e.target.value = ''; // allow re-selecting same files
    });

    this.body.appendChild(this.uploadZone);

    // Drag-sort hint
    this.dragHint = document.createElement('div');
    this.dragHint.className = 'rte-gallery-drag-hint';
    this.dragHint.textContent = this.translate('Drag to reorder');
    this.dragHint.hidden = true;
    this.body.appendChild(this.dragHint);

    // Preview grid
    this.previewGrid = document.createElement('div');
    this.previewGrid.className = 'rte-gallery-preview-grid';
    this.body.appendChild(this.previewGrid);

    // Drag state
    this._dragSrcIndex = null;

    // Columns selector
    this.columnsField = this.createField({
      type: 'select',
      label: 'Columns',
      id: 'rte-gallery-columns',
      options: [
        {value: '2', label: '2'},
        {value: '3', label: '3'},
        {value: '4', label: '4'},
        {value: '5', label: '5'}
      ]
    });
    const sel = this.columnsField.querySelector('select');
    sel.value = String(this.pluginOptions.defaultColumns || 3);
    this.body.appendChild(this.columnsField);
  }

  /**
   * Add files to the pending list and show previews
   * @param {FileList|File[]} fileList
   */
  addFiles(fileList) {
    const imageFiles = Array.from(fileList).filter(f => f.type.startsWith('image/'));
    if (!imageFiles.length) return;

    imageFiles.forEach(file => {
      const url = URL.createObjectURL(file);
      this.pendingFiles.push({file, previewUrl: url});
    });

    this.renderPreviews();
    this.updateConfirmState();
  }

  /**
   * Remove a pending file by index
   * @param {number} index
   */
  removeFile(index) {
    const item = this.pendingFiles[index];
    if (item) {
      if (!item.isExisting) URL.revokeObjectURL(item.previewUrl);
      this.pendingFiles.splice(index, 1);
    }
    this.renderPreviews();
    this.updateConfirmState();
  }

  renderPreviews() {
    this.previewGrid.innerHTML = '';
    const hasPending = this.pendingFiles.length > 0;
    this.dragHint.hidden = !hasPending;

    this.pendingFiles.forEach((item, i) => {
      const card = document.createElement('div');
      card.className = 'rte-gallery-preview-card';
      card.draggable = true;
      card.dataset.index = i;

      // ── Drag source ──────────────────────────────────────────
      card.addEventListener('dragstart', (e) => {
        this._dragSrcIndex = i;
        e.dataTransfer.effectAllowed = 'move';
        // Encode index as text so Firefox accepts the drag
        e.dataTransfer.setData('text/plain', String(i));
        requestAnimationFrame(() => card.classList.add('dragging'));
      });
      card.addEventListener('dragend', () => {
        card.classList.remove('dragging');
        this.previewGrid.querySelectorAll('.rte-gallery-preview-card')
          .forEach(c => c.classList.remove('drag-over'));
      });

      // ── Drop target ──────────────────────────────────────────
      card.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        if (this._dragSrcIndex !== i) {
          this.previewGrid.querySelectorAll('.rte-gallery-preview-card')
            .forEach(c => c.classList.remove('drag-over'));
          card.classList.add('drag-over');
        }
      });
      card.addEventListener('dragleave', () => {
        card.classList.remove('drag-over');
      });
      card.addEventListener('drop', (e) => {
        e.preventDefault();
        e.stopPropagation();
        card.classList.remove('drag-over');
        const src = this._dragSrcIndex;
        if (src === null || src === i) return;
        // Reorder pendingFiles
        const moved = this.pendingFiles.splice(src, 1)[0];
        this.pendingFiles.splice(i, 0, moved);
        this._dragSrcIndex = null;
        this.renderPreviews();
      });

      const img = document.createElement('img');
      img.src = item.previewUrl;
      img.alt = item.file.name;
      // Prevent img from hijacking the drag of the card
      img.draggable = false;
      card.appendChild(img);

      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'rte-gallery-preview-remove';
      removeBtn.innerHTML = '&times;';
      removeBtn.title = this.translate('Remove');
      removeBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        this.removeFile(i);
      });
      card.appendChild(removeBtn);

      const name = document.createElement('span');
      name.className = 'rte-gallery-preview-name';
      name.textContent = item.file.name;
      card.appendChild(name);

      this.previewGrid.appendChild(card);
    });
  }

  updateConfirmState() {
    if (this.confirmBtn) {
      this.confirmBtn.disabled = this.pendingFiles.length === 0;
    }
  }

  populate() {
    // Reset
    this.pendingFiles.forEach(item => {
      if (!item.isExisting) URL.revokeObjectURL(item.previewUrl);
    });
    this.pendingFiles = [];
    this._dragSrcIndex = null;
    this.previewGrid.innerHTML = '';
    this.dragHint.hidden = true;
    this.fileInput.value = '';

    // Populate from existing gallery if in edit mode
    const titleEl = this.dialog?.querySelector('[data-rte-part="title"]');
    if (this.editTarget) {
      this._loadFromElement(this.editTarget);
      if (titleEl) titleEl.textContent = window.translate?.('Edit Gallery') || 'Edit Gallery';
    } else {
      if (titleEl) titleEl.textContent = window.translate?.('Upload Gallery') || 'Upload Gallery';
    }

    this.updateConfirmState();
  }

  /**
   * Load existing images from a gallery DOM element into pendingFiles.
   * Items loaded this way carry isExisting:true and skip the upload step.
   * @param {HTMLElement} galleryEl
   * @private
   */
  _loadFromElement(galleryEl) {
    galleryEl.querySelectorAll('img').forEach(img => {
      const url = img.dataset.src || img.getAttribute('src') || '';
      const alt = img.alt || '';
      this.pendingFiles.push({file: null, previewUrl: url, url, alt, isExisting: true});
    });
    const match = (galleryEl.getAttribute('style') || '').match(/--rte-gallery-columns:\s*(\d+)/);
    if (match) {
      const sel = this.columnsField?.querySelector('select');
      if (sel) sel.value = match[1];
    }
  }

  getData() {
    return {
      items: this.pendingFiles.slice(),
      columns: parseInt(this.columnsField.querySelector('select').value, 10) || 3
    };
  }

  validate() {
    this.clearError();
    if (this.pendingFiles.length === 0) {
      this.showError(this.translate('Please select at least one image'));
      return false;
    }
    return true;
  }

  destroy() {
    this.pendingFiles.forEach(item => {
      if (!item.isExisting) URL.revokeObjectURL(item.previewUrl);
    });
    this.pendingFiles = [];
    super.destroy();
  }
}

/* ─── Gallery Upload Plugin ─────────────────────────────────────────────── */

class GalleryUploadPlugin extends PluginBase {
  static pluginName = 'galleryUpload';

  init() {
    super.init();

    this.dialog = new GalleryDialog(this.editor, this.options);
    this.dialog.onConfirm = (data) => this.handleConfirm(data);

    // Toolbar button
    this.subscribe(EventBus.Events.TOOLBAR_BUTTON_CLICK, (event) => {
      if (event.id === 'galleryUpload') {
        this.openDialog();
      }
    });

    // Register command
    this.registerCommand('galleryUpload', {
      execute: () => this.openDialog()
    });
  }

  openDialog() {
    this._editTarget = this._getGalleryAtCursor();
    this.dialog.editTarget = this._editTarget;
    this.saveSelection();
    this.dialog.open();
  }

  /**
   * Find a .rte-gallery-grid element at the current cursor position.
   * Returns it only when the cursor is inside the editor content area.
   * @returns {HTMLElement|null}
   * @private
   */
  _getGalleryAtCursor() {
    const selection = window.getSelection();
    if (!selection || selection.rangeCount === 0) return null;
    let node = selection.getRangeAt(0).commonAncestorContainer;
    if (node.nodeType === Node.TEXT_NODE) node = node.parentElement;
    if (!node.closest?.('.rte-content')) return null;
    return node.closest('.rte-gallery-grid') || null;
  }

  /**
   * Upload all files, then insert a grid gallery into the editor
   * @param {Object} data - {files: File[], columns: number}
   */
  async handleConfirm(data) {
    const {items, columns} = data;
    if (!items.length) return;

    // Show loading state on confirm button
    const btn = this.dialog.confirmBtn;
    btn?.classList.add('loading');
    btn && (btn.disabled = true);

    const urls = [];
    for (const item of items) {
      if (item.isExisting) {
        urls.push({url: item.url, alt: item.alt || ''});
        continue;
      }
      try {
        const url = await this._uploadFile(item.file);
        urls.push({url, alt: item.file.name.replace(/\.[^.]+$/, '')});
      } catch (err) {
        this.notify(
          this.translate('Failed to upload image') + `: ${item.file?.name} — ${err.message}`,
          'error'
        );
      }
    }

    btn?.classList.remove('loading');
    btn && (btn.disabled = false);

    if (urls.length === 0) {
      this.notify(this.translate('No images were uploaded'), 'error');
      return;
    }

    // Capture edit target before closing dialog (close resets editTarget on next open)
    const editTarget = this._editTarget;
    this._editTarget = null;
    this.dialog.close();

    const html = this._buildGalleryHtml(urls, columns);
    if (editTarget) {
      // Replace existing gallery in place without disturbing cursor position
      const tempDiv = document.createElement('div');
      tempDiv.innerHTML = html;
      const newNode = tempDiv.firstElementChild;
      if (newNode) editTarget.replaceWith(newNode);
    } else {
      this.restoreSelection();
      this.insertHtml(html);
    }
    this.recordHistory(true);
    this.focusEditor();
  }

  /**
   * Upload a single file (reuses ImagePlugin's upload mechanism)
   * @param {File} file
   * @returns {Promise<string>}
   */
  async _uploadFile(file) {
    // Try to delegate to ImagePlugin's upload endpoint config
    const imagePlugin = this.editor.getPlugin('image');
    const imageOpts = imagePlugin?.options || {};
    const uploadUrl = this.options.uploadUrl
      || imageOpts.fileBrowser?.options?.apiActions?.upload
      || imageOpts.uploadUrl;

    if (!uploadUrl) {
      throw new Error('Upload URL not configured');
    }

    const formData = new FormData();
    formData.append('file', file);
    formData.append('path', this.options.uploadPath || imageOpts.uploadPath || '/');

    const requestOptions = typeof Now !== 'undefined' && Now.applyRequestLanguage
      ? Now.applyRequestLanguage({method: 'POST', body: formData, credentials: 'include'})
      : {method: 'POST', body: formData, credentials: 'include'};

    const response = await fetch(uploadUrl, requestOptions);
    if (!response.ok) throw new Error(`HTTP ${response.status}`);

    const result = await response.json();
    if (!result.success) throw new Error(result.error || 'Upload failed');

    const url = result.file?.url || result.data?.url;
    if (!url) throw new Error('Server did not return file URL');
    return url;
  }

  /**
   * Build gallery grid HTML.
   * Uses data-media-viewer so Now.js MediaViewer auto-inits on the live page.
   * Each <img> uses data-src for the full-size URL (picked up by MediaViewer)
   * and src as the displayed thumbnail (same URL — no separate thumb generation).
   * @param {{url: string, alt: string}[]} images
   * @param {number} columns
   * @returns {string}
   */
  _buildGalleryHtml(images, columns) {
    const items = images.map(img => {
      const alt = img.alt.replace(/"/g, '&quot;');
      return `<img src="${img.url}" data-src="${img.url}" alt="${alt}" loading="lazy">`;
    }).join('\n');

    return `<div class="rte-gallery-grid" data-media-viewer style="--rte-gallery-columns:${columns}">\n${items}\n</div>\n<p><br></p>`;
  }

  destroy() {
    this.dialog?.destroy();
    super.destroy();
  }
}

export default GalleryUploadPlugin;
