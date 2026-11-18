/**
 * DropdownPanel - Singleton panel for displaying dropdowns
 *
 * This panel is attached directly to document.body to avoid z-index
 * and overflow issues with modals and other containers.
 * Only one instance exists and can be shared between multiple components
 * like Calendar and ColorPicker.
 */

class DropdownPanel {
  static instance = null;
  static currentOwner = null;

  constructor() {
    if (DropdownPanel.instance) {
      return DropdownPanel.instance;
    }

    this.panel = null;
    this.contentContainer = null;
    this.isVisible = false;
    this.currentTarget = null;
    this.closeCallback = null;

    this._createPanel();
    this._setupEventListeners();

    DropdownPanel.instance = this;
  }

  _createPanel() {
    // Create panel element
    this.panel = document.createElement('div');
    this.panel.className = 'dropdown-panel';

    // Create content container
    this.contentContainer = document.createElement('div');
    this.contentContainer.className = 'dropdown-panel-content';
    this.panel.appendChild(this.contentContainer);

    // Append to body
    document.body.appendChild(this.panel);
  }

  _setupEventListeners() {
    // Close on outside click
    document.addEventListener('click', (e) => {
      if (!this.isVisible) return;

      // Check if click is outside panel and outside target element
      if (!this.panel.contains(e.target) &&
        (!this.currentTarget || !this.currentTarget.contains(e.target))) {
        this.hide();
      }
    });

    // Close on Escape key
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && this.isVisible) {
        this.hide();
      }
    });

    // Reposition on scroll
    window.addEventListener('scroll', () => {
      if (this.isVisible && this.currentTarget) {
        this._updatePosition();
      }
    }, true);

    // Reposition on resize
    window.addEventListener('resize', () => {
      if (this.isVisible && this.currentTarget) {
        this._updatePosition();
      }
    });
  }

  /**
   * Show the dropdown panel with content
   * @param {HTMLElement} targetElement - The element to position relative to
   * @param {HTMLElement|string} content - The content to display (element or HTML string)
   * @param {Object} options - Configuration options
   * @param {Function} options.onClose - Callback when panel closes
   * @param {string} options.align - Alignment (left|right|center) default: left
   * @param {number} options.offsetY - Vertical offset from target, default: 5
   * @param {number} options.offsetX - Horizontal offset, default: 0
   */
  show(targetElement, content, options = {}) {
    if (!targetElement) {
      console.error('DropdownPanel: targetElement is required');
      return;
    }

    // Close previous if any
    if (this.isVisible) {
      this.hide();
    }

    this.currentTarget = targetElement;
    this.closeCallback = options.onClose || null;
    this.options = {
      align: options.align || 'left',
      offsetY: options.offsetY !== undefined ? options.offsetY : 5,
      offsetX: options.offsetX || 0
    };

    // Set content
    this.contentContainer.innerHTML = '';
    if (typeof content === 'string') {
      this.contentContainer.innerHTML = content;
    } else if (content instanceof HTMLElement) {
      this.contentContainer.appendChild(content);
    } else {
      console.error('DropdownPanel: Invalid content type');
      return;
    }

    // Show panel
    this.panel.style.display = 'block';
    this.isVisible = true;

    // Position panel
    this._updatePosition();

    // Dispatch event
    this.panel.dispatchEvent(new CustomEvent('dropdown:show', {
      detail: {target: targetElement}
    }));
  }

  /**
   * Update panel position relative to target element
   */
  _updatePosition() {
    if (!this.currentTarget) return;

    const targetRect = this.currentTarget.getBoundingClientRect();
    const panelRect = this.panel.getBoundingClientRect();
    const viewportWidth = window.innerWidth;
    const viewportHeight = window.innerHeight;

    let left = 0;
    let top = targetRect.bottom + this.options.offsetY;

    // Calculate horizontal position based on alignment
    switch (this.options.align) {
      case 'right':
        left = targetRect.right - panelRect.width + this.options.offsetX;
        break;
      case 'center':
        left = targetRect.left + (targetRect.width - panelRect.width) / 2 + this.options.offsetX;
        break;
      case 'left':
      default:
        left = targetRect.left + this.options.offsetX;
        break;
    }

    // Adjust if panel goes off-screen horizontally
    if (left + panelRect.width > viewportWidth) {
      left = viewportWidth - panelRect.width - 10;
    }
    if (left < 10) {
      left = 10;
    }

    // Adjust if panel goes off-screen vertically
    if (top + panelRect.height > viewportHeight) {
      // Show above target instead
      top = targetRect.top - panelRect.height - this.options.offsetY;

      // If still doesn't fit, show at top of viewport
      if (top < 10) {
        top = 10;
        // Limit height to fit viewport
        this.panel.style.maxHeight = (viewportHeight - 20) + 'px';
      }
    }

    // Apply position
    this.panel.style.left = left + 'px';
    this.panel.style.top = top + 'px';
  }

  /**
   * Hide the dropdown panel
   */
  hide() {
    if (!this.isVisible) return;

    this.panel.style.display = 'none';
    this.isVisible = false;

    // Clear content
    this.contentContainer.innerHTML = '';

    // Call close callback
    if (this.closeCallback) {
      this.closeCallback();
      this.closeCallback = null;
    }

    const oldTarget = this.currentTarget;
    this.currentTarget = null;

    // Dispatch event
    this.panel.dispatchEvent(new CustomEvent('dropdown:hide', {
      detail: {target: oldTarget}
    }));
  }

  /**
   * Check if panel is currently visible
   */
  isOpen() {
    return this.isVisible;
  }

  /**
   * Get the singleton instance
   */
  static getInstance() {
    if (!DropdownPanel.instance) {
      new DropdownPanel();
    }
    return DropdownPanel.instance;
  }

  /**
   * Set content of the panel (when already visible)
   */
  setContent(content) {
    this.contentContainer.innerHTML = '';
    if (typeof content === 'string') {
      this.contentContainer.innerHTML = content;
    } else if (content instanceof HTMLElement) {
      this.contentContainer.appendChild(content);
    }

    // Update position after content change
    if (this.isVisible) {
      this._updatePosition();
    }
  }

  /**
   * Get the content container element
   */
  getContentContainer() {
    return this.contentContainer;
  }

  /**
   * Destroy the singleton instance (for cleanup)
   */
  static destroy() {
    if (DropdownPanel.instance) {
      if (DropdownPanel.instance.panel) {
        DropdownPanel.instance.panel.remove();
      }
      DropdownPanel.instance = null;
    }
  }
}

// Auto-initialize when DOM is ready
if (typeof window !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      DropdownPanel.getInstance();
    });
  } else {
    DropdownPanel.getInstance();
  }
}

// Expose globally
window.DropdownPanel = DropdownPanel;