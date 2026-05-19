/**
 * MaxLengthPlugin - Limit content length
 *
 * @author Goragod Wiriya
 * @version 1.0
 */
import PluginBase from '../PluginBase.js';
import EventBus from '../../core/EventBus.js';

class MaxLengthPlugin extends PluginBase {
  static pluginName = 'maxLength';

  init() {
    super.init();

    this.options = {
      maxLength: 10000,
      maxWords: null,
      countMode: 'characters', // 'characters' or 'words'
      showCounter: true,
      showWarning: true,
      warningThreshold: 0.9, // Show warning at 90%
      enforceLimit: true,
      ...this.options
    };

    this.counterElement = null;
    this.isWarning = false;
    this.isOverLimit = false;

    // Create counter
    if (this.options.showCounter) {
      this.createCounter();
    }

    // Listen for content changes
    this.subscribe(EventBus.Events.CONTENT_CHANGE, () => {
      this.checkLimit();
    });

    // Listen for paste events to intercept
    this.subscribe(EventBus.Events.CONTENT_PASTE, (event) => {
      if (this.options.enforceLimit) {
        this.handlePaste(event);
      }
    });

    // Initial check
    this.checkLimit();
  }

  /**
   * Create counter element
   */
  createCounter() {
    this.counterElement = document.createElement('span');
    this.counterElement.className = 'rte-maxlength-counter';
    this.counterElement.style.cssText = `
      padding: 2px 8px;
      font-size: 12px;
      color: var(--rte-text-muted);
      transition: color 0.2s;
    `;

    const footer = this.editor.footer;
    if (footer) {
      footer.appendChild(this.counterElement);
    }

    this.updateCounter();
  }

  /**
   * Update counter display
   */
  updateCounter() {
    if (!this.counterElement) return;

    const current = this.getCurrentCount();
    const max = this.getMaxCount();
    const remaining = max - current;
    const percentage = current / max;

    // Update text
    if (this.options.countMode === 'words') {
      this.counterElement.textContent = `${current} / ${max} ${this.translate('words')}`;
    } else {
      this.counterElement.textContent = `${current} / ${max}`;
    }

    // Update color based on state
    if (current > max) {
      this.counterElement.style.color = 'var(--rte-notification-error)';
      this.counterElement.style.fontWeight = '600';
    } else if (percentage >= this.options.warningThreshold) {
      this.counterElement.style.color = 'var(--rte-notification-warning)';
      this.counterElement.style.fontWeight = '500';
    } else {
      this.counterElement.style.color = 'var(--rte-text-muted)';
      this.counterElement.style.fontWeight = 'normal';
    }
  }

  /**
   * Get current count
   * @returns {number}
   */
  getCurrentCount() {
    if (this.options.countMode === 'words') {
      return this.editor.getWordCount();
    }
    return this.editor.getCharacterCount();
  }

  /**
   * Get max count
   * @returns {number}
   */
  getMaxCount() {
    if (this.options.countMode === 'words') {
      return this.options.maxWords || this.options.maxLength;
    }
    return this.options.maxLength;
  }

  /**
   * Check if over limit
   */
  checkLimit() {
    const current = this.getCurrentCount();
    const max = this.getMaxCount();
    const percentage = current / max;

    // Update counter
    this.updateCounter();

    // Check warning threshold
    const wasWarning = this.isWarning;
    this.isWarning = percentage >= this.options.warningThreshold && current <= max;

    if (this.isWarning && !wasWarning && this.options.showWarning) {
      this.emit('maxLength:warning', {current, max, remaining: max - current});
    }

    // Check over limit
    const wasOverLimit = this.isOverLimit;
    this.isOverLimit = current > max;

    if (this.isOverLimit && !wasOverLimit) {
      this.emit('maxLength:exceeded', {current, max, over: current - max});

      if (this.options.showWarning) {
        this.notify(this.translate('Content exceeds maximum length'), 'warning');
      }

      // Enforce limit if enabled
      if (this.options.enforceLimit) {
        this.trimContent();
      }
    }
  }

  /**
   * Trim content to max length
   */
  trimContent() {
    if (this.options.countMode === 'words') {
      this.trimToWords(this.getMaxCount());
    } else {
      this.trimToCharacters(this.getMaxCount());
    }
  }

  /**
   * Trim to max characters
   * @param {number} maxChars
   */
  trimToCharacters(maxChars) {
    const content = this.editor.contentArea?.getElement();
    if (!content) return;

    const text = content.textContent;
    if (text.length <= maxChars) return;

    // Find the position in the HTML where we need to cut
    let charCount = 0;
    const walker = document.createTreeWalker(content, NodeFilter.SHOW_TEXT, null, false);

    while (walker.nextNode()) {
      const node = walker.currentNode;
      const nodeLength = node.textContent.length;

      if (charCount + nodeLength >= maxChars) {
        const cutAt = maxChars - charCount;
        node.textContent = node.textContent.substring(0, cutAt);

        // Remove remaining nodes
        let next = walker.nextNode();
        while (next) {
          const toRemove = next;
          next = walker.nextNode();
          toRemove.parentNode.removeChild(toRemove);
        }
        break;
      }

      charCount += nodeLength;
    }

    this.recordHistory(true);
  }

  /**
   * Trim to max words
   * @param {number} maxWords
   */
  trimToWords(maxWords) {
    const content = this.editor.contentArea?.getElement();
    if (!content) return;

    let wordCount = 0;
    const walker = document.createTreeWalker(content, NodeFilter.SHOW_TEXT, null, false);

    while (walker.nextNode()) {
      const node = walker.currentNode;
      const words = node.textContent.split(/\s+/).filter(w => w.length > 0);

      if (wordCount + words.length >= maxWords) {
        const remainingWords = maxWords - wordCount;
        const trimmedWords = words.slice(0, remainingWords);
        node.textContent = trimmedWords.join(' ');

        // Remove remaining nodes
        let next = walker.nextNode();
        while (next) {
          const toRemove = next;
          next = walker.nextNode();
          toRemove.parentNode.removeChild(toRemove);
        }
        break;
      }

      wordCount += words.length;
    }

    this.recordHistory(true);
  }

  /**
   * Handle paste to prevent exceeding limit
   * @param {ClipboardEvent} event
   */
  handlePaste(event) {
    const clipboardData = event.clipboardData || window.clipboardData;
    if (!clipboardData) return;

    const pastedText = clipboardData.getData('text/plain');
    if (!pastedText) return;

    const currentCount = this.getCurrentCount();
    const maxCount = this.getMaxCount();
    const remaining = maxCount - currentCount;

    if (this.options.countMode === 'words') {
      const pastedWords = pastedText.split(/\s+/).filter(w => w.length > 0);
      if (pastedWords.length > remaining) {
        event.preventDefault();

        // Insert only allowed words
        const allowedText = pastedWords.slice(0, remaining).join(' ');
        this.insertHtml(allowedText);

        this.notify(this.translate('Pasted content was trimmed to fit limit'), 'warning');
      }
    } else {
      if (pastedText.length > remaining) {
        event.preventDefault();

        // Insert only allowed characters
        const allowedText = pastedText.substring(0, remaining);
        this.insertHtml(allowedText);

        this.notify(this.translate('Pasted content was trimmed to fit limit'), 'warning');
      }
    }
  }

  /**
   * Get remaining count
   * @returns {number}
   */
  getRemainingCount() {
    return this.getMaxCount() - this.getCurrentCount();
  }

  /**
   * Check if content is at limit
   * @returns {boolean}
   */
  isAtLimit() {
    return this.getCurrentCount() >= this.getMaxCount();
  }

  /**
   * Check if content exceeds limit
   * @returns {boolean}
   */
  isExceeded() {
    return this.isOverLimit;
  }

  /**
   * Set max length
   * @param {number} max
   */
  setMaxLength(max) {
    this.options.maxLength = max;
    this.checkLimit();
  }

  /**
   * Set max words
   * @param {number} max
   */
  setMaxWords(max) {
    this.options.maxWords = max;
    this.options.countMode = 'words';
    this.checkLimit();
  }

  destroy() {
    if (this.counterElement && this.counterElement.parentNode) {
      this.counterElement.parentNode.removeChild(this.counterElement);
    }
    super.destroy();
  }
}

export default MaxLengthPlugin;
