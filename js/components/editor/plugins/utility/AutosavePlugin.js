/**
 * AutosavePlugin - Auto-save content with configurable interval
 *
 * @author Goragod Wiriya
 * @version 1.0
 */
import PluginBase from '../PluginBase.js';
import EventBus from '../../core/EventBus.js';

class AutosavePlugin extends PluginBase {
  static pluginName = 'autosave';

  init() {
    super.init();

    // Derive a unique key for this editor instance so multiple editors
    // on the same page do not overwrite each other's localStorage data.
    const elementId = this.editor.targetElement?.name
      || this.editor.targetElement?.id
      || this.editor.targetElement?.dataset?.rteId
      || null;
    const defaultKey = elementId ? `rte-autosave-${elementId}` : 'rte-autosave';

    this.options = {
      interval: 30000, // 30 seconds
      key: defaultKey,
      saveHandler: null,
      useLocalStorage: true,
      showIndicator: true,
      ...this.options
    };

    this.saveTimer = null;
    this.isDirty = false;
    this.lastSavedContent = '';
    this.indicator = null;

    // Create save indicator
    if (this.options.showIndicator) {
      this.createIndicator();
    }

    // Listen for content changes
    this.subscribe(EventBus.Events.CONTENT_CHANGE, () => {
      this.markDirty();
    });

    // Check for recovered content
    this.checkRecovery();

    // Start autosave timer
    this.startTimer();

    // Save before unload
    this._boundBeforeUnload = this.handleBeforeUnload.bind(this);
    window.addEventListener('beforeunload', this._boundBeforeUnload);
  }

  /**
   * Create save indicator element
   */
  createIndicator() {
    this.indicator = document.createElement('span');
    this.indicator.className = 'rte-autosave-indicator';
    this.indicator.style.cssText = `
      display: none;
      padding: 2px 8px;
      font-size: 11px;
      color: var(--rte-text-muted);
    `;

    const footer = this.editor.footer;
    if (footer) {
      footer.appendChild(this.indicator);
    }
  }

  /**
   * Mark content as dirty (changed)
   */
  markDirty() {
    this.isDirty = true;
    this.updateIndicator('Unsaved changes', 'warning');
  }

  /**
   * Mark content as clean (saved)
   */
  markClean() {
    this.isDirty = false;
    this.lastSavedContent = this.getContent();
  }

  /**
   * Update indicator text
   * @param {string} text
   * @param {string} type - 'success', 'warning', 'error'
   */
  updateIndicator(text, type = 'info') {
    if (!this.indicator) return;

    this.indicator.textContent = text;
    this.indicator.style.display = 'inline';

    switch (type) {
      case 'success':
        this.indicator.style.color = 'var(--rte-notification-success)';
        break;
      case 'warning':
        this.indicator.style.color = 'var(--rte-notification-warning)';
        break;
      case 'error':
        this.indicator.style.color = 'var(--rte-notification-error)';
        break;
      default:
        this.indicator.style.color = 'var(--rte-text-muted)';
    }

    // Auto-hide success message
    if (type === 'success') {
      setTimeout(() => {
        if (this.indicator) {
          this.indicator.style.display = 'none';
        }
      }, 3000);
    }
  }

  /**
   * Start autosave timer
   */
  startTimer() {
    if (this.saveTimer) {
      clearInterval(this.saveTimer);
    }

    this.saveTimer = setInterval(() => {
      this.save();
    }, this.options.interval);
  }

  /**
   * Stop autosave timer
   */
  stopTimer() {
    if (this.saveTimer) {
      clearInterval(this.saveTimer);
      this.saveTimer = null;
    }
  }

  /**
   * Save content
   * @param {boolean} force - Force save even if not dirty
   * @returns {Promise<boolean>}
   */
  async save(force = false) {
    if (!force && !this.isDirty) {
      return true;
    }

    const content = this.getContent();

    // Skip if content hasn't changed
    if (content === this.lastSavedContent) {
      this.isDirty = false;
      return true;
    }

    this.emit(EventBus.Events.AUTOSAVE_START, {content});
    this.updateIndicator('Saving...', 'info');

    try {
      // Custom save handler
      if (typeof this.options.saveHandler === 'function') {
        await this.options.saveHandler(content);
      }

      // LocalStorage backup
      if (this.options.useLocalStorage) {
        this.saveToLocalStorage(content);
      }

      this.markClean();
      this.updateIndicator('Saved', 'success');
      this.emit(EventBus.Events.AUTOSAVE_SUCCESS, {content});

      return true;
    } catch (error) {
      console.error('Autosave failed:', error);
      this.updateIndicator('Save failed', 'error');
      this.emit(EventBus.Events.AUTOSAVE_ERROR, {error});

      // Still save to localStorage as backup
      if (this.options.useLocalStorage) {
        this.saveToLocalStorage(content);
      }

      return false;
    }
  }

  /**
   * Save to localStorage
   * @param {string} content
   */
  saveToLocalStorage(content) {
    try {
      const data = {
        content,
        timestamp: Date.now(),
        key: this.options.key
      };
      localStorage.setItem(this.options.key, JSON.stringify(data));
    } catch (e) {
      console.warn('Failed to save to localStorage:', e);
    }
  }

  /**
   * Get from localStorage
   * @returns {Object|null}
   */
  getFromLocalStorage() {
    try {
      const data = localStorage.getItem(this.options.key);
      if (data) {
        return JSON.parse(data);
      }
    } catch (e) {
      console.warn('Failed to get from localStorage:', e);
    }
    return null;
  }

  /**
   * Clear localStorage
   */
  clearLocalStorage() {
    try {
      localStorage.removeItem(this.options.key);
    } catch (e) {
      console.warn('Failed to clear localStorage:', e);
    }
  }

  /**
   * Check for recovered content
   */
  checkRecovery() {
    const saved = this.getFromLocalStorage();
    if (!saved || !saved.content) return;

    const currentContent = this.getContent();

    // If saved content is different and newer
    if (saved.content !== currentContent && saved.content.trim()) {
      const timeAgo = this.formatTimeAgo(saved.timestamp);
      this.showRecoveryBar(saved.content, timeAgo);
    }
  }

  /**
   * Show non-blocking recovery notification bar
   * @param {string} savedContent
   * @param {string} timeAgo
   */
  showRecoveryBar(savedContent, timeAgo) {
    // Remove any existing recovery bar
    this.removeRecoveryBar();

    const bar = document.createElement('div');
    bar.className = 'rte-recovery-bar';
    bar.style.cssText = `
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 8px 12px;
      background: var(--rte-notification-info-bg, #e8f4fd);
      border-bottom: 1px solid var(--rte-notification-info-border, #b3d9f2);
      font-size: 13px;
      color: var(--rte-text-color, #333);
    `;

    const msg = document.createElement('span');
    msg.style.flex = '1';
    msg.textContent = `${this.translate('Recovered content found from')} ${timeAgo}. ${this.translate('Do you want to restore this content?')}`;
    bar.appendChild(msg);

    const restoreBtn = document.createElement('button');
    restoreBtn.type = 'button';
    restoreBtn.textContent = this.translate('Restore');
    restoreBtn.style.cssText = `
      padding: 4px 12px;
      border: none;
      border-radius: 4px;
      background: var(--rte-primary-color, #2196f3);
      color: #fff;
      cursor: pointer;
      font-size: 12px;
    `;
    restoreBtn.addEventListener('click', () => {
      this.setContent(savedContent);
      this.recordHistory(true);
      this.notify('Content restored', 'success');
      this.removeRecoveryBar();
    });
    bar.appendChild(restoreBtn);

    const dismissBtn = document.createElement('button');
    dismissBtn.type = 'button';
    dismissBtn.textContent = this.translate('Dismiss');
    dismissBtn.style.cssText = `
      padding: 4px 12px;
      border: 1px solid var(--rte-border-color, #ccc);
      border-radius: 4px;
      background: transparent;
      cursor: pointer;
      font-size: 12px;
      color: var(--rte-text-color, #333);
    `;
    dismissBtn.addEventListener('click', () => {
      this.clearLocalStorage();
      this.removeRecoveryBar();
    });
    bar.appendChild(dismissBtn);

    this._recoveryBar = bar;

    // Insert at top of editor container
    const container = this.editor.getContainer();
    if (container) {
      container.insertBefore(bar, container.firstChild);
    }
  }

  /**
   * Remove recovery notification bar
   */
  removeRecoveryBar() {
    if (this._recoveryBar && this._recoveryBar.parentNode) {
      this._recoveryBar.parentNode.removeChild(this._recoveryBar);
    }
    this._recoveryBar = null;
  }

  /**
   * Format timestamp to relative time
   * @param {number} timestamp
   * @returns {string}
   */
  formatTimeAgo(timestamp) {
    const seconds = Math.floor((Date.now() - timestamp) / 1000);

    if (seconds < 60) return `${seconds} seconds ago`;
    if (seconds < 3600) return `${Math.floor(seconds / 60)} minutes ago`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)} hours ago`;
    return `${Math.floor(seconds / 86400)} days ago`;
  }

  /**
   * Handle before unload
   * @param {BeforeUnloadEvent} event
   */
  handleBeforeUnload(event) {
    if (this.isDirty) {
      // Save before leaving
      this.save(true);

      // Show warning
      event.preventDefault();
      event.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
      return event.returnValue;
    }
  }

  /**
   * Check if content is dirty
   * @returns {boolean}
   */
  isDirtyContent() {
    return this.isDirty;
  }

  /**
   * Force save now
   * @returns {Promise<boolean>}
   */
  saveNow() {
    return this.save(true);
  }

  /**
   * Set save interval
   * @param {number} interval - Interval in ms
   */
  setInterval(interval) {
    this.options.interval = interval;
    this.startTimer();
  }

  destroy() {
    // Final save before destroy
    if (this.isDirty) {
      this.save(true);
    }

    this.stopTimer();
    this.removeRecoveryBar();
    window.removeEventListener('beforeunload', this._boundBeforeUnload);

    if (this.indicator && this.indicator.parentNode) {
      this.indicator.parentNode.removeChild(this.indicator);
    }

    super.destroy();
  }
}

export default AutosavePlugin;
