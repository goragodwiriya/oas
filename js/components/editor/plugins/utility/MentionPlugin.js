/**
 * MentionPlugin - @mentions with autocomplete
 *
 * @author Goragod Wiriya
 * @version 1.0
 */
import PluginBase from '../PluginBase.js';
import EventBus from '../../core/EventBus.js';

class MentionPlugin extends PluginBase {
  static pluginName = 'mention';

  init() {
    super.init();

    this.options = {
      trigger: '@',
      minChars: 1,
      maxSuggestions: 10,
      debounceTime: 200,
      dataSource: null, // Function that returns Promise<Array>
      renderItem: null, // Custom render function
      insertTemplate: null, // Custom insert template
      linkTemplate: null, // Generate link from item
      ...this.options
    };

    this.dropdown = null;
    this.isOpen = false;
    this.searchQuery = '';
    this.selectedIndex = -1;
    this.suggestions = [];
    this.debounceTimer = null;
    this.triggerPosition = null;

    this.createDropdown();
    this.attachListeners();
  }

  /**
   * Create dropdown element
   */
  createDropdown() {
    this.dropdown = document.createElement('div');
    this.dropdown.className = 'rte-mention-dropdown';
    this.dropdown.style.cssText = `
      position: absolute;
      z-index: 10002;
      background: var(--rte-bg-color, #fff);
      border: 1px solid var(--rte-border-color, #ddd);
      border-radius: 8px;
      box-shadow: 0 4px 16px rgba(0,0,0,0.15);
      max-width: 300px;
      min-width: 200px;
      max-height: 250px;
      overflow-y: auto;
      display: none;
    `;

    document.body.appendChild(this.dropdown);
  }

  /**
   * Attach event listeners
   */
  attachListeners() {
    const content = this.editor.contentArea?.getElement();
    if (!content) return;

    // Listen for input events
    content.addEventListener('input', this.handleInput.bind(this));

    // Listen for keydown for navigation
    content.addEventListener('keydown', this.handleKeyDown.bind(this));

    // Close on outside click
    document.addEventListener('click', this.handleDocumentClick.bind(this));
  }

  /**
   * Handle input event
   * @param {InputEvent} event
   */
  handleInput(event) {
    // Check if trigger character was typed
    if (this.checkTrigger()) {
      this.open();
    } else if (this.isOpen) {
      // Update search if dropdown is open
      const query = this.getSearchQuery();
      if (query !== null) {
        this.search(query);
      } else {
        this.close();
      }
    }
  }

  /**
   * Check if trigger was typed
   * @returns {boolean}
   */
  checkTrigger() {
    const range = this.getRange();
    if (!range || !range.collapsed) return false;

    const text = this.getTextBeforeCursor();
    if (!text) return false;

    // Check for trigger at the end, after whitespace or at start
    const triggerPattern = new RegExp(`(^|\\s)${this.escapeRegex(this.options.trigger)}$`);
    return triggerPattern.test(text);
  }

  /**
   * Get text before cursor
   * @returns {string}
   */
  getTextBeforeCursor() {
    const range = this.getRange();
    if (!range) return '';

    const selection = this.getSelection();
    const content = this.editor.contentArea?.getElement();
    if (!content) return '';

    const preRange = document.createRange();
    preRange.selectNodeContents(content);
    preRange.setEnd(range.startContainer, range.startOffset);

    const text = preRange.toString();
    return text;
  }

  /**
   * Get search query after trigger
   * @returns {string|null}
   */
  getSearchQuery() {
    const text = this.getTextBeforeCursor();

    // Find the trigger in text
    const triggerIndex = text.lastIndexOf(this.options.trigger);
    if (triggerIndex === -1) return null;

    // Check if there's whitespace after trigger start
    const query = text.substring(triggerIndex + this.options.trigger.length);

    // If query contains whitespace, the mention is complete
    if (/\s/.test(query)) return null;

    return query;
  }

  /**
   * Handle keydown for navigation
   * @param {KeyboardEvent} event
   */
  handleKeyDown(event) {
    if (!this.isOpen) return;

    switch (event.key) {
      case 'ArrowDown':
        event.preventDefault();
        this.selectNext();
        break;

      case 'ArrowUp':
        event.preventDefault();
        this.selectPrevious();
        break;

      case 'Enter':
      case 'Tab':
        if (this.selectedIndex >= 0) {
          event.preventDefault();
          this.insertMention(this.suggestions[this.selectedIndex]);
        }
        break;

      case 'Escape':
        event.preventDefault();
        this.close();
        break;
    }
  }

  /**
   * Handle document click
   * @param {MouseEvent} event
   */
  handleDocumentClick(event) {
    if (this.isOpen && !this.dropdown.contains(event.target)) {
      this.close();
    }
  }

  /**
   * Open dropdown
   */
  open() {
    this.isOpen = true;
    this.selectedIndex = -1;
    this.triggerPosition = this.getCaretPosition();
    this.search('');
  }

  /**
   * Close dropdown
   */
  close() {
    this.isOpen = false;
    this.dropdown.style.display = 'none';
    this.selectedIndex = -1;
    this.suggestions = [];
  }

  /**
   * Search for suggestions
   * @param {string} query
   */
  search(query) {
    this.searchQuery = query;

    // Cancel previous debounce
    if (this.debounceTimer) {
      clearTimeout(this.debounceTimer);
    }

    // Debounce search
    this.debounceTimer = setTimeout(async () => {
      await this.performSearch(query);
    }, this.options.debounceTime);
  }

  /**
   * Perform the search
   * @param {string} query
   */
  async performSearch(query) {
    if (query.length < this.options.minChars && query.length > 0) {
      this.renderSuggestions([]);
      return;
    }

    let results = [];

    if (typeof this.options.dataSource === 'function') {
      try {
        results = await this.options.dataSource(query);
      } catch (e) {
        console.error('Mention search error:', e);
      }
    } else if (Array.isArray(this.options.dataSource)) {
      // Filter local data
      results = this.options.dataSource.filter(item => {
        const name = item.name || item.label || item;
        return name.toLowerCase().includes(query.toLowerCase());
      });
    }

    // Limit results
    results = results.slice(0, this.options.maxSuggestions);

    this.suggestions = results;
    this.renderSuggestions(results);
  }

  /**
   * Render suggestions
   * @param {Array} items
   */
  renderSuggestions(items) {
    this.dropdown.innerHTML = '';

    if (items.length === 0) {
      this.dropdown.style.display = 'none';
      return;
    }

    items.forEach((item, index) => {
      const el = document.createElement('div');
      el.className = 'rte-mention-item';
      el.style.cssText = `
        padding: 10px 14px;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 10px;
        transition: background 0.15s;
      `;

      // Custom render or default
      if (typeof this.options.renderItem === 'function') {
        el.innerHTML = this.options.renderItem(item);
      } else {
        el.innerHTML = this.defaultRenderItem(item);
      }

      // Hover effect
      el.addEventListener('mouseover', () => {
        el.style.background = 'var(--rte-bg-hover, #f0f0f0)';
        this.selectedIndex = index;
        this.updateSelection();
      });
      el.addEventListener('mouseout', () => {
        if (this.selectedIndex !== index) {
          el.style.background = 'transparent';
        }
      });

      // Click to insert
      el.addEventListener('click', () => {
        this.insertMention(item);
      });

      this.dropdown.appendChild(el);
    });

    // Position and show dropdown
    this.positionDropdown();
    this.dropdown.style.display = 'block';

    // Select first item
    if (items.length > 0) {
      this.selectedIndex = 0;
      this.updateSelection();
    }
  }

  /**
   * Default item render
   * @param {*} item
   * @returns {string}
   */
  defaultRenderItem(item) {
    if (typeof item === 'string') {
      return `<span>${this.escapeHtml(item)}</span>`;
    }

    let html = '';

    // Avatar
    if (item.avatar || item.image) {
      const avatarUrl = this.escapeHtml(item.avatar || item.image);
      html += `<img src="${avatarUrl}" style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover;">`;
    }

    // Name and description
    html += '<div style="flex: 1; min-width: 0;">';
    html += `<div style="font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${this.escapeHtml(item.name || item.label)}</div>`;

    if (item.description || item.username) {
      html += `<div style="font-size: 12px; color: var(--rte-text-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${this.escapeHtml(item.description || '@' + item.username)}</div>`;
    }

    html += '</div>';

    return html;
  }

  /**
   * Position dropdown near caret
   */
  positionDropdown() {
    if (!this.triggerPosition) return;

    const content = this.editor.contentArea?.getContainer();
    if (!content) return;

    const contentRect = content.getBoundingClientRect();

    // Position below caret
    let top = this.triggerPosition.top + this.triggerPosition.height + 4;
    let left = this.triggerPosition.left;

    // Adjust if would go off screen
    const dropdownRect = this.dropdown.getBoundingClientRect();

    if (left + 300 > window.innerWidth) {
      left = window.innerWidth - 310;
    }

    if (top + 250 > window.innerHeight) {
      top = this.triggerPosition.top - 260;
    }

    this.dropdown.style.top = `${top}px`;
    this.dropdown.style.left = `${left}px`;
  }

  /**
   * Get caret position
   * @returns {Object}
   */
  getCaretPosition() {
    const range = this.getRange();
    if (!range) return {top: 0, left: 0, height: 20};

    const rect = range.getBoundingClientRect();
    return {
      top: rect.bottom || rect.top + 20,
      left: rect.left,
      height: rect.height || 20
    };
  }

  /**
   * Select next suggestion
   */
  selectNext() {
    if (this.suggestions.length === 0) return;

    this.selectedIndex = (this.selectedIndex + 1) % this.suggestions.length;
    this.updateSelection();
  }

  /**
   * Select previous suggestion
   */
  selectPrevious() {
    if (this.suggestions.length === 0) return;

    this.selectedIndex--;
    if (this.selectedIndex < 0) {
      this.selectedIndex = this.suggestions.length - 1;
    }
    this.updateSelection();
  }

  /**
   * Update selection visual
   */
  updateSelection() {
    const items = this.dropdown.querySelectorAll('.rte-mention-item');
    items.forEach((item, index) => {
      if (index === this.selectedIndex) {
        item.style.background = 'var(--rte-bg-hover, #f0f0f0)';
        item.scrollIntoView({block: 'nearest'});
      } else {
        item.style.background = 'transparent';
      }
    });
  }

  /**
   * Insert mention
   * @param {*} item
   */
  insertMention(item) {
    this.close();

    // Delete the trigger and query from content
    this.deleteTriggerAndQuery();

    // Generate mention HTML
    let html;

    if (typeof this.options.insertTemplate === 'function') {
      html = this.options.insertTemplate(item);
    } else {
      const name = item.name || item.label || item;
      const link = typeof this.options.linkTemplate === 'function'
        ? this.options.linkTemplate(item)
        : null;

      if (link) {
        html = `<a href="${this.escapeHtml(link)}" class="rte-mention" contenteditable="false">@${this.escapeHtml(name)}</a>&nbsp;`;
      } else {
        html = `<span class="rte-mention" contenteditable="false" data-mention-id="${this.escapeHtml(item.id || '')}">@${this.escapeHtml(name)}</span>&nbsp;`;
      }
    }

    // Insert mention
    this.insertHtml(html);
    this.recordHistory(true);

    // Emit event
    this.emit('mention:insert', {item});
  }

  /**
   * Delete the trigger character and search query
   */
  deleteTriggerAndQuery() {
    const range = this.getRange();
    if (!range) return;

    const text = this.getTextBeforeCursor();
    const triggerIndex = text.lastIndexOf(this.options.trigger);
    if (triggerIndex === -1) return;

    const deleteLength = text.length - triggerIndex;

    // Create range to delete
    const deleteRange = document.createRange();
    deleteRange.setStart(range.startContainer, range.startOffset - deleteLength);
    deleteRange.setEnd(range.startContainer, range.startOffset);
    deleteRange.deleteContents();
  }

  /**
   * Escape HTML
   * @param {string} str
   * @returns {string}
   */
  escapeHtml(str) {
    if (typeof str !== 'string') return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  /**
   * Escape regex special characters
   * @param {string} str
   * @returns {string}
   */
  escapeRegex(str) {
    return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }

  destroy() {
    if (this.dropdown && this.dropdown.parentNode) {
      this.dropdown.parentNode.removeChild(this.dropdown);
    }
    if (this.debounceTimer) {
      clearTimeout(this.debounceTimer);
    }
    super.destroy();
  }
}

export default MentionPlugin;
