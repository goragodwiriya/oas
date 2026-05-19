/**
 * FindReplacePlugin - Find and replace functionality
 *
 * @author Goragod Wiriya
 * @version 1.0
 */
import PluginBase from '../PluginBase.js';
import BaseDialog from '../../ui/dialogs/BaseDialog.js';
import EventBus from '../../core/EventBus.js';

class FindReplaceDialog extends BaseDialog {
  constructor(editor, plugin) {
    super(editor, {
      title: 'Find and Replace',
      width: 450,
      closeOnEscape: false // Handle ESC differently
    });
    this.plugin = plugin;
  }

  buildBody() {
    // Find field
    this.findField = this.createField({
      type: 'text',
      label: 'Find',
      id: 'rte-find-text',
      placeholder: 'Text to find'
    });
    this.body.appendChild(this.findField);

    // Replace field
    this.replaceField = this.createField({
      type: 'text',
      label: 'Replace with',
      id: 'rte-replace-text',
      placeholder: 'Replacement text'
    });
    this.body.appendChild(this.replaceField);

    // Options row
    const optionsRow = document.createElement('div');
    optionsRow.style.cssText = 'display: flex; gap: 16px; margin-top: 8px;';

    this.matchCaseField = this.createField({
      type: 'checkbox',
      id: 'rte-find-matchcase',
      checkLabel: 'Match case'
    });
    optionsRow.appendChild(this.matchCaseField);

    this.wholeWordField = this.createField({
      type: 'checkbox',
      id: 'rte-find-wholeword',
      checkLabel: 'Whole word'
    });
    optionsRow.appendChild(this.wholeWordField);

    this.body.appendChild(optionsRow);

    // Results counter
    this.resultsCounter = document.createElement('div');
    this.resultsCounter.className = 'rte-find-results';
    this.resultsCounter.style.cssText = `
      margin-top: 16px;
      padding: 8px 12px;
      background: var(--rte-bg-secondary);
      border-radius: 4px;
      font-size: 13px;
      color: var(--rte-text-secondary);
    `;
    this.resultsCounter.textContent = 'No results';
    this.body.appendChild(this.resultsCounter);

    // Live search on input
    const findInput = this.findField.querySelector('input');
    findInput.addEventListener('input', () => {
      this.plugin.find(findInput.value);
    });

    // Handle Enter key
    findInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        if (e.shiftKey) {
          this.plugin.findPrevious();
        } else {
          this.plugin.findNext();
        }
      }
    });
  }

  buildFooter() {
    // Find buttons
    const findPrevBtn = document.createElement('button');
    findPrevBtn.type = 'button';
    findPrevBtn.className = 'rte-dialog-btn';
    findPrevBtn.textContent = '◀ ' + this.translate('Previous');
    findPrevBtn.addEventListener('click', () => this.plugin.findPrevious());

    const findNextBtn = document.createElement('button');
    findNextBtn.type = 'button';
    findNextBtn.className = 'rte-dialog-btn';
    findNextBtn.textContent = this.translate('Next') + ' ▶';
    findNextBtn.addEventListener('click', () => this.plugin.findNext());

    // Replace buttons
    const replaceBtn = document.createElement('button');
    replaceBtn.type = 'button';
    replaceBtn.className = 'rte-dialog-btn rte-dialog-btn-secondary';
    replaceBtn.textContent = this.translate('Replace');
    replaceBtn.addEventListener('click', () => this.plugin.replace());

    const replaceAllBtn = document.createElement('button');
    replaceAllBtn.type = 'button';
    replaceAllBtn.className = 'rte-dialog-btn rte-dialog-btn-primary';
    replaceAllBtn.textContent = this.translate('Replace All');
    replaceAllBtn.addEventListener('click', () => this.plugin.replaceAll());

    // Close button
    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'rte-dialog-btn';
    closeBtn.textContent = this.translate('Close');
    closeBtn.addEventListener('click', () => this.close());

    this.footer.appendChild(findPrevBtn);
    this.footer.appendChild(findNextBtn);
    this.footer.appendChild(replaceBtn);
    this.footer.appendChild(replaceAllBtn);
    this.footer.appendChild(closeBtn);
  }

  getData() {
    const findInput = this.findField.querySelector('input');
    const replaceInput = this.replaceField.querySelector('input');
    const matchCaseInput = this.matchCaseField.querySelector('input');
    const wholeWordInput = this.wholeWordField.querySelector('input');

    return {
      find: findInput.value,
      replace: replaceInput.value,
      matchCase: matchCaseInput.checked,
      wholeWord: wholeWordInput.checked
    };
  }

  updateResultsCount(current, total) {
    if (total === 0) {
      this.resultsCounter.textContent = 'No results';
    } else {
      this.resultsCounter.textContent = `${current} of ${total} matches`;
    }
  }

  close() {
    this.plugin.clearHighlights();
    super.close();
  }
}

class FindReplacePlugin extends PluginBase {
  static pluginName = 'findReplace';

  init() {
    super.init();

    this.dialog = new FindReplaceDialog(this.editor, this);
    this.matches = [];
    this.currentMatchIndex = -1;
    this.highlightClass = 'rte-find-highlight';
    this.currentHighlightClass = 'rte-find-current';

    // Add highlight styles
    this.addStyles();

    // Listen for toolbar button click
    this.subscribe(EventBus.Events.TOOLBAR_BUTTON_CLICK, (event) => {
      if (event.id === 'findReplace') {
        this.openDialog();
      }
    });

    // Register shortcut
    this.registerShortcut('ctrl+f', () => this.openDialog());
    this.registerShortcut('ctrl+h', () => this.openDialog());
  }

  /**
   * Add highlight styles
   */
  addStyles() {
    if (document.getElementById('rte-find-styles')) return;

    const style = document.createElement('style');
    style.id = 'rte-find-styles';
    style.textContent = `
      .${this.highlightClass} {
        background-color: #fff3cd;
        padding: 1px 0;
      }
      .${this.currentHighlightClass} {
        background-color: #ffc107;
      }
    `;
    document.head.appendChild(style);
  }

  /**
   * Open find/replace dialog
   */
  openDialog() {
    const selectedText = this.getSelection().getSelectedText();
    this.dialog.open({find: selectedText});

    if (selectedText) {
      this.find(selectedText);
    }
  }

  /**
   * Find text and highlight matches
   * @param {string} searchText
   */
  find(searchText) {
    this.clearHighlights();
    this.matches = [];
    this.currentMatchIndex = -1;

    if (!searchText) {
      this.dialog.updateResultsCount(0, 0);
      return;
    }

    const options = this.dialog.getData();
    const content = this.editor.contentArea?.getElement();
    if (!content) return;

    // Build regex
    let flags = 'g';
    if (!options.matchCase) flags += 'i';

    let pattern = searchText.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    if (options.wholeWord) {
      pattern = `\\b${pattern}\\b`;
    }

    const regex = new RegExp(pattern, flags);

    // Find text nodes and highlight matches
    this.highlightMatches(content, regex);

    // Update counter
    this.dialog.updateResultsCount(
      this.matches.length > 0 ? 1 : 0,
      this.matches.length
    );

    // Go to first match
    if (this.matches.length > 0) {
      this.currentMatchIndex = 0;
      this.highlightCurrentMatch();
    }
  }

  /**
   * Highlight matches in content
   * @param {HTMLElement} container
   * @param {RegExp} regex
   */
  highlightMatches(container, regex) {
    const walker = document.createTreeWalker(
      container,
      NodeFilter.SHOW_TEXT,
      null,
      false
    );

    const textNodes = [];
    while (walker.nextNode()) {
      textNodes.push(walker.currentNode);
    }

    textNodes.forEach(node => {
      const text = node.textContent;
      const matches = [];
      let match;

      regex.lastIndex = 0;
      while ((match = regex.exec(text)) !== null) {
        matches.push({
          index: match.index,
          length: match[0].length,
          text: match[0]
        });
      }

      if (matches.length > 0) {
        this.replaceWithHighlights(node, matches);
      }
    });
  }

  /**
   * Replace text node with highlighted spans
   * @param {Text} textNode
   * @param {Array} matches
   */
  replaceWithHighlights(textNode, matches) {
    const text = textNode.textContent;
    const fragment = document.createDocumentFragment();
    let lastIndex = 0;

    matches.forEach((match, i) => {
      // Add text before match
      if (match.index > lastIndex) {
        fragment.appendChild(
          document.createTextNode(text.substring(lastIndex, match.index))
        );
      }

      // Add highlighted span
      const span = document.createElement('span');
      span.className = this.highlightClass;
      span.textContent = match.text;
      span.dataset.matchIndex = this.matches.length;

      fragment.appendChild(span);
      this.matches.push(span);

      lastIndex = match.index + match.length;
    });

    // Add remaining text
    if (lastIndex < text.length) {
      fragment.appendChild(
        document.createTextNode(text.substring(lastIndex))
      );
    }

    textNode.parentNode.replaceChild(fragment, textNode);
  }

  /**
   * Highlight current match
   */
  highlightCurrentMatch() {
    // Remove current highlight from all
    this.matches.forEach(span => {
      span.classList.remove(this.currentHighlightClass);
    });

    // Add to current
    if (this.currentMatchIndex >= 0 && this.matches[this.currentMatchIndex]) {
      const current = this.matches[this.currentMatchIndex];
      current.classList.add(this.currentHighlightClass);

      // Scroll into view
      current.scrollIntoView({behavior: 'smooth', block: 'center'});
    }

    this.dialog.updateResultsCount(
      this.currentMatchIndex + 1,
      this.matches.length
    );
  }

  /**
   * Find next match
   */
  findNext() {
    if (this.matches.length === 0) return;

    this.currentMatchIndex = (this.currentMatchIndex + 1) % this.matches.length;
    this.highlightCurrentMatch();
  }

  /**
   * Find previous match
   */
  findPrevious() {
    if (this.matches.length === 0) return;

    this.currentMatchIndex--;
    if (this.currentMatchIndex < 0) {
      this.currentMatchIndex = this.matches.length - 1;
    }
    this.highlightCurrentMatch();
  }

  /**
   * Replace current match
   */
  replace() {
    if (this.currentMatchIndex < 0 || !this.matches[this.currentMatchIndex]) return;

    const options = this.dialog.getData();
    const current = this.matches[this.currentMatchIndex];

    // Replace the text
    current.textContent = options.replace;
    current.classList.remove(this.highlightClass, this.currentHighlightClass);

    // Remove from matches array
    this.matches.splice(this.currentMatchIndex, 1);

    // Record history
    this.recordHistory(true);

    // Move to next match
    if (this.matches.length > 0) {
      if (this.currentMatchIndex >= this.matches.length) {
        this.currentMatchIndex = 0;
      }
      this.highlightCurrentMatch();
    } else {
      this.dialog.updateResultsCount(0, 0);
    }
  }

  /**
   * Replace all matches
   */
  replaceAll() {
    if (this.matches.length === 0) return;

    const options = this.dialog.getData();
    let count = 0;

    this.matches.forEach(span => {
      span.textContent = options.replace;
      span.classList.remove(this.highlightClass, this.currentHighlightClass);
      count++;
    });

    this.matches = [];
    this.currentMatchIndex = -1;

    this.recordHistory(true);
    this.dialog.updateResultsCount(0, 0);
    this.notify(`Replaced ${count} occurrences`, 'success');
  }

  /**
   * Clear all highlights
   */
  clearHighlights() {
    const content = this.editor.contentArea?.getElement();
    if (!content) return;

    const highlights = content.querySelectorAll(`.${this.highlightClass}`);
    highlights.forEach(span => {
      const text = document.createTextNode(span.textContent);
      span.parentNode.replaceChild(text, span);
    });

    // Normalize text nodes
    content.normalize();

    this.matches = [];
    this.currentMatchIndex = -1;
  }

  destroy() {
    this.clearHighlights();
    this.dialog?.destroy();
    super.destroy();
  }
}

export default FindReplacePlugin;
