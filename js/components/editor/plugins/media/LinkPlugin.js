/**
 * LinkPlugin - Insert, edit, and remove links
 *
 * @author Goragod Wiriya
 * @version 1.0
 */
import PluginBase from '../PluginBase.js';
import BaseDialog from '../../ui/dialogs/BaseDialog.js';
import EventBus from '../../core/EventBus.js';

class LinkDialog extends BaseDialog {
  constructor(editor) {
    super(editor, {
      title: 'Insert Link',
      width: 400
    });
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

    // Basic URL validation
    if (!data.url.match(/^(https?:\/\/|mailto:|tel:|#)/i)) {
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
    this.dialog = new LinkDialog(this.editor);
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
