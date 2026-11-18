/**
 * VideoPlugin - Embed videos from YouTube, Vimeo, or custom URLs
 *
 * @author Goragod Wiriya
 * @version 1.0
 */
import PluginBase from '../PluginBase.js';
import BaseDialog from '../../ui/dialogs/BaseDialog.js';
import EventBus from '../../core/EventBus.js';

class VideoDialog extends BaseDialog {
  constructor(editor) {
    super(editor, {
      title: 'Insert Video',
      width: 500
    });
  }

  buildBody() {
    // URL field
    this.urlField = this.createField({
      type: 'url',
      label: 'Video URL',
      id: 'rte-video-url',
      placeholder: 'https://www.youtube.com/watch?v=...',
      help: 'Supports YouTube, Vimeo, or direct video URLs'
    });
    this.body.appendChild(this.urlField);

    // Preview area
    this.previewArea = document.createElement('div');
    this.previewArea.className = 'rte-video-preview';
    this.previewArea.style.cssText = `
      margin-top: 16px;
      padding: 16px;
      background: var(--rte-bg-secondary);
      border-radius: 6px;
      display: none;
    `;
    this.body.appendChild(this.previewArea);

    // width/height row
    const sizeRow = document.createElement('div');
    sizeRow.style.display = 'flex';
    sizeRow.style.gap = '12px';
    sizeRow.style.marginTop = '16px';

    this.widthField = this.createField({
      type: 'text',
      label: 'Width',
      id: 'rte-video-width',
      value: '100%',
      placeholder: '100% or 560px'
    });
    this.widthField.style.flex = '1';

    this.heightField = this.createField({
      type: 'text',
      label: 'Height',
      id: 'rte-video-height',
      value: '315',
      placeholder: '315'
    });
    this.heightField.style.flex = '1';

    sizeRow.appendChild(this.widthField);
    sizeRow.appendChild(this.heightField);
    this.body.appendChild(sizeRow);

    // Responsive checkbox
    this.responsiveField = this.createField({
      type: 'checkbox',
      id: 'rte-video-responsive',
      checkLabel: 'Make video responsive (16:9)',
      checked: true
    });
    this.body.appendChild(this.responsiveField);

    // Auto-preview on URL change
    const urlInput = this.urlField.querySelector('input');
    urlInput.addEventListener('input', () => {
      this.updatePreview();
    });
  }

  updatePreview() {
    const urlInput = this.urlField.querySelector('input');
    const url = urlInput.value.trim();

    if (!url) {
      this.previewArea.style.display = 'none';
      return;
    }

    const embedUrl = this.getEmbedUrl(url);
    if (embedUrl) {
      this.previewArea.style.display = 'block';
      // Use DOM API instead of innerHTML to avoid XSS via malformed embedUrl
      this.previewArea.innerHTML = '';
      const wrapper = document.createElement('div');
      wrapper.style.cssText = 'position:relative;padding-bottom:56.25%;height:0;overflow:hidden;';
      const iframe = document.createElement('iframe');
      iframe.src = embedUrl; // browser sanitizes the URL
      iframe.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;border:0;';
      iframe.allowFullscreen = true;
      iframe.setAttribute('loading', 'lazy');
      wrapper.appendChild(iframe);
      this.previewArea.appendChild(wrapper);
    } else {
      this.previewArea.style.display = 'block';
      this.previewArea.innerHTML = '';
      const msg = document.createElement('p');
      msg.style.cssText = 'color:var(--rte-text-muted);text-align:center;';
      msg.textContent = this.translate('Cannot preview this URL');
      this.previewArea.appendChild(msg);
    }
  }

  getEmbedUrl(url) {
    // YouTube
    let match = url.match(/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/ ]{11})/);
    if (match) {
      return `https://www.youtube.com/embed/${match[1]}`;
    }

    // Vimeo
    match = url.match(/(?:vimeo\.com\/)(\d+)/);
    if (match) {
      return `https://player.vimeo.com/video/${match[1]}`;
    }

    // Direct video URL
    if (url.match(/\.(mp4|webm|ogg)$/i)) {
      return null; // Will use video tag instead
    }

    return null;
  }

  populate(data) {
    const urlInput = this.urlField.querySelector('input');
    const widthInput = this.widthField.querySelector('input');
    const heightInput = this.heightField.querySelector('input');
    const responsiveInput = this.responsiveField.querySelector('input');

    urlInput.value = data.url || '';
    widthInput.value = data.width || '100%';
    heightInput.value = data.height || '315';
    responsiveInput.checked = data.responsive !== false;

    if (data.url) {
      this.updatePreview();
    }
  }

  getData() {
    const urlInput = this.urlField.querySelector('input');
    const widthInput = this.widthField.querySelector('input');
    const heightInput = this.heightField.querySelector('input');
    const responsiveInput = this.responsiveField.querySelector('input');

    return {
      url: urlInput.value.trim(),
      width: widthInput.value.trim() || '100%',
      height: heightInput.value.trim() || '315',
      responsive: responsiveInput.checked
    };
  }

  validate() {
    this.clearError();
    const data = this.getData();

    if (!data.url) {
      this.showError('Please enter a video URL', this.urlField);
      return false;
    }

    return true;
  }
}

class VideoPlugin extends PluginBase {
  static pluginName = 'video';

  init() {
    super.init();

    // Create dialog
    this.dialog = new VideoDialog(this.editor);
    this.dialog.onConfirm = (data) => this.insertVideo(data);

    // Listen for toolbar button click
    this.subscribe(EventBus.Events.TOOLBAR_BUTTON_CLICK, (event) => {
      if (event.id === 'video') {
        this.openDialog();
      }
    });

    // Register command
    this.registerCommand('insertVideo', {
      execute: (data) => this.insertVideo(data)
    });
  }

  /**
   * Open video dialog
   */
  openDialog() {
    this.saveSelection();
    this.dialog.open({});
  }

  /**
   * Insert video
   * @param {Object} data - Video data
   */
  insertVideo(data) {
    this.restoreSelection();

    const embedUrl = this.getEmbedUrl(data.url);
    let html;

    if (embedUrl) {
      // YouTube/Vimeo embed
      if (data.responsive) {
        html = `
          <div class="rte-video-wrapper" style="position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; margin: 1em 0;">
            <iframe src="${embedUrl}" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: 0;" allowfullscreen></iframe>
          </div>
        `;
      } else {
        html = `
          <div class="rte-video-wrapper" style="margin: 1em 0;">
            <iframe src="${embedUrl}" width="${data.width}" height="${data.height}" style="border: 0;" allowfullscreen></iframe>
          </div>
        `;
      }
    } else if (data.url.match(/\.(mp4|webm|ogg)$/i)) {
      // Direct video file
      if (data.responsive) {
        html = `
          <div class="rte-video-wrapper" style="margin: 1em 0;">
            <video src="${data.url}" style="width: 100%; height: auto;" controls></video>
          </div>
        `;
      } else {
        html = `
          <div class="rte-video-wrapper" style="margin: 1em 0;">
            <video src="${data.url}" width="${data.width}" height="${data.height}" controls></video>
          </div>
        `;
      }
    } else {
      // Fallback - just create a link
      html = `<a href="${data.url}" target="_blank">${data.url}</a>`;
    }

    this.insertHtml(html);
    this.recordHistory(true);
    this.focusEditor();
  }

  /**
   * Get embed URL from video URL
   * @param {string} url
   * @returns {string|null}
   */
  getEmbedUrl(url) {
    // YouTube
    let match = url.match(/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/ ]{11})/);
    if (match) {
      return `https://www.youtube.com/embed/${match[1]}`;
    }

    // Vimeo
    match = url.match(/(?:vimeo\.com\/)(\d+)/);
    if (match) {
      return `https://player.vimeo.com/video/${match[1]}`;
    }

    return null;
  }

  destroy() {
    this.dialog?.destroy();
    super.destroy();
  }
}

export default VideoPlugin;
