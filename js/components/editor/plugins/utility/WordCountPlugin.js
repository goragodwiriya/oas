/**
 * WordCountPlugin - Word and character count in footer
 *
 * @author Goragod Wiriya
 * @version 1.0
 */
import PluginBase from '../PluginBase.js';
import EventBus from '../../core/EventBus.js';

class WordCountPlugin extends PluginBase {
  static pluginName = 'wordcount';

  init() {
    super.init();

    this.options = {
      showWords: true,
      showCharacters: true,
      showCharactersWithoutSpaces: false,
      ...this.options
    };

    this.element = null;

    // Create counter element
    this.createCounter();

    // Listen for content changes
    this.subscribe(EventBus.Events.CONTENT_CHANGE, () => {
      this.update();
    });

    this.subscribe(EventBus.Events.CONTENT_SET, () => {
      this.update();
    });

    // Initial update
    this.update();
  }

  /**
   * Create counter element
   */
  createCounter() {
    this.element = document.createElement('div');
    this.element.className = 'rte-wordcount';
    this.element.style.cssText = `
      display: flex;
      gap: 16px;
      font-size: 12px;
      color: var(--rte-text-muted);
    `;

    const footer = this.editor.footer;
    if (footer) {
      footer.appendChild(this.element);
    }
  }

  /**
   * Update counts
   */
  update() {
    if (!this.element) return;

    const parts = [];

    if (this.options.showWords) {
      const words = this.editor.getWordCount();
      parts.push(`${words} ${this.translate(words === 1 ? 'word' : 'words')}`);
    }

    if (this.options.showCharacters) {
      const chars = this.editor.getCharacterCount(false);
      parts.push(`${chars} ${this.translate(chars === 1 ? 'character' : 'characters')}`);
    }

    if (this.options.showCharactersWithoutSpaces) {
      const charsNoSpace = this.editor.getCharacterCount(true);
      parts.push(`${charsNoSpace} ${this.translate('characters (no spaces)')}`);
    }

    this.element.textContent = parts.join(' | ');
  }

  /**
   * Get word count
   * @returns {number}
   */
  getWordCount() {
    return this.editor.getWordCount();
  }

  /**
   * Get character count
   * @param {boolean} excludeSpaces
   * @returns {number}
   */
  getCharacterCount(excludeSpaces = false) {
    return this.editor.getCharacterCount(excludeSpaces);
  }

  destroy() {
    if (this.element && this.element.parentNode) {
      this.element.parentNode.removeChild(this.element);
    }
    super.destroy();
  }
}

export default WordCountPlugin;
