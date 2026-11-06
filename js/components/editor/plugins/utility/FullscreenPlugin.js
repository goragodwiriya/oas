/**
 * FullscreenPlugin - Fullscreen editing mode
 *
 * @author Goragod Wiriya
 * @version 1.0
 */
import PluginBase from '../PluginBase.js';
import EventBus from '../../core/EventBus.js';

class FullscreenPlugin extends PluginBase {
  static pluginName = 'fullscreen';

  init() {
    super.init();

    this.isActive = false;
    this.originalStyles = null;

    // Listen for toolbar button click
    this.subscribe(EventBus.Events.TOOLBAR_BUTTON_CLICK, (event) => {
      if (event.id === 'fullscreen') {
        this.toggle();
      }
    });

    // Register command
    this.registerCommand('fullscreen', {
      execute: () => this.toggle(),
      isActive: () => this.isActive
    });

    // Register ESC shortcut to exit fullscreen
    this.registerShortcut('escape', () => {
      if (this.isActive) {
        this.toggle(false);
      }
    });

    // Listen for F11 key
    this._boundKeyDown = this.handleKeyDown.bind(this);
    document.addEventListener('keydown', this._boundKeyDown);
  }

  /**
   * Handle keydown event
   * @param {KeyboardEvent} event
   */
  handleKeyDown(event) {
    if (event.key === 'F11') {
      event.preventDefault();
      this.toggle();
    }
  }

  /**
   * Toggle fullscreen mode
   * @param {boolean} enabled - Force enable/disable
   */
  toggle(enabled) {
    this.isActive = enabled !== undefined ? enabled : !this.isActive;

    const container = this.editor.getContainer();
    if (!container) return;

    if (this.isActive) {
      this.enterFullscreen(container);
    } else {
      this.exitFullscreen(container);
    }

    // Update toolbar button state
    this.setButtonActive('fullscreen', this.isActive);

    // Emit mode change event
    this.emit(EventBus.Events.MODE_CHANGE, {
      fullscreen: this.isActive
    });

    // Update editor state
    this.editor.isFullscreen = this.isActive;
  }

  /**
   * Enter fullscreen mode
   * @param {HTMLElement} container
   */
  enterFullscreen(container) {
    // Save original styles
    this.originalStyles = {
      position: container.style.position,
      top: container.style.top,
      left: container.style.left,
      right: container.style.right,
      bottom: container.style.bottom,
      width: container.style.width,
      height: container.style.height,
      borderRadius: container.style.borderRadius
    };

    // Apply fullscreen styles
    container.style.position = 'fixed';
    container.style.top = '0';
    container.style.left = '0';
    container.style.right = '0';
    container.style.bottom = '0';
    container.style.width = '100%';
    container.style.height = '100%';
    container.style.borderRadius = '0';

    container.classList.add('fullscreen');
    document.body.classList.add('rte-fullscreen-active');

    // Expand content area
    const contentWrapper = this.editor.contentArea?.getContainer();
    if (contentWrapper) {
      contentWrapper.style.flex = '1';
      contentWrapper.style.maxHeight = 'none';
    }

    // Update content area height
    const content = this.editor.contentArea?.getElement();
    if (content) {
      content.style.minHeight = 'auto';
      content.style.maxHeight = 'none';
    }
  }

  /**
   * Exit fullscreen mode
   * @param {HTMLElement} container
   */
  exitFullscreen(container) {
    // Restore original styles
    if (this.originalStyles) {
      Object.entries(this.originalStyles).forEach(([key, value]) => {
        container.style[key] = value;
      });
    }

    container.classList.remove('fullscreen');
    document.body.classList.remove('rte-fullscreen-active');

    // Restore content area
    const contentWrapper = this.editor.contentArea?.getContainer();
    if (contentWrapper) {
      contentWrapper.style.flex = '';
      contentWrapper.style.maxHeight = '';
    }

    const content = this.editor.contentArea?.getElement();
    if (content) {
      content.style.minHeight = '';
      content.style.maxHeight = '';
    }
  }

  /**
   * Check if fullscreen is active
   * @returns {boolean}
   */
  isFullscreenActive() {
    return this.isActive;
  }

  destroy() {
    // Make sure to exit fullscreen
    if (this.isActive) {
      this.toggle(false);
    }

    document.removeEventListener('keydown', this._boundKeyDown);
    super.destroy();
  }
}

export default FullscreenPlugin;
