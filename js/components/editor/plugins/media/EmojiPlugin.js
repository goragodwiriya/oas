/**
 * EmojiPlugin - Emoji picker for inserting emojis
 *
 * @author Goragod Wiriya
 * @version 1.0
 */
import PluginBase from '../PluginBase.js';
import EventBus from '../../core/EventBus.js';

class EmojiPlugin extends PluginBase {
  static pluginName = 'emoji';

  init() {
    super.init();

    this.picker = null;
    this.isOpen = false;

    // Listen for toolbar button click
    this.subscribe(EventBus.Events.TOOLBAR_BUTTON_CLICK, (event) => {
      if (event.id === 'emoji') {
        this.toggle();
      }
    });

    // Close on outside click
    this._boundDocumentClick = this.handleDocumentClick.bind(this);
    document.addEventListener('click', this._boundDocumentClick);
  }

  /**
   * Toggle emoji picker
   */
  toggle() {
    if (this.isOpen) {
      this.close();
    } else {
      this.open();
    }
  }

  /**
   * Open emoji picker
   */
  open() {
    this.saveSelection();

    if (!this.picker) {
      this.createPicker();
    }

    // Position near toolbar button
    const btn = this.getToolbarButton('emoji');
    if (btn) {
      const rect = btn.getBoundingClientRect();
      this.picker.style.top = `${rect.bottom + 4}px`;
      this.picker.style.left = `${rect.left}px`;
    }

    this.picker.style.display = 'block';
    this.isOpen = true;
  }

  /**
   * Close emoji picker
   */
  close() {
    if (this.picker) {
      this.picker.style.display = 'none';
    }
    this.isOpen = false;
  }

  /**
   * Handle document click
   * @param {MouseEvent} event
   */
  handleDocumentClick(event) {
    if (this.isOpen && this.picker && !this.picker.contains(event.target)) {
      const btn = this.getToolbarButton('emoji');
      if (!btn || !btn.contains(event.target)) {
        this.close();
      }
    }
  }

  /**
   * Create emoji picker element
   */
  createPicker() {
    this.picker = document.createElement('div');
    this.picker.className = 'rte-emoji-picker';
    this.picker.style.cssText = `
      position: fixed;
      z-index: 10001;
      background: var(--rte-bg-color, #fff);
      border: 1px solid var(--rte-border-color, #ddd);
      border-radius: 8px;
      box-shadow: 0 4px 16px rgba(0,0,0,0.15);
      padding: 12px;
      width: 253px;
      max-height: 350px;
      display: none;
    `;

    // Search input
    const searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.placeholder = this.translate('Search emoji...');
    searchInput.className = 'rte-emoji-search';
    searchInput.style.cssText = `
      width: 100%;
      padding: 8px 12px;
      border: 1px solid var(--rte-border-color, #ddd);
      border-radius: 6px;
      margin-bottom: 12px;
      font-size: 14px;
    `;
    searchInput.addEventListener('input', (e) => this.filterEmojis(e.target.value));
    this.picker.appendChild(searchInput);

    // Categories tabs
    const tabs = document.createElement('div');
    tabs.className = 'rte-emoji-tabs';
    tabs.style.cssText = `
      display: flex;
      gap: 4px;
      margin-bottom: 8px;
      border-bottom: 1px solid var(--rte-border-color, #ddd);
      padding-bottom: 8px;
    `;

    EmojiPlugin.categories.forEach((cat, index) => {
      const tab = document.createElement('button');
      tab.type = 'button';
      tab.className = 'rte-emoji-tab';
      tab.textContent = cat.icon;
      tab.title = this.translate(cat.name);
      tab.style.cssText = `
        width: 32px;
        height: 32px;
        border: none;
        background: transparent;
        font-size: 18px;
        cursor: pointer;
        border-radius: 4px;
        transition: background 0.2s;
      `;
      tab.addEventListener('mouseover', () => tab.style.background = 'var(--rte-bg-hover, #f0f0f0)');
      tab.addEventListener('mouseout', () => tab.style.background = 'transparent');
      tab.addEventListener('click', () => this.showCategory(index));
      tabs.appendChild(tab);
    });
    this.picker.appendChild(tabs);

    // Emoji grid container
    this.emojiGrid = document.createElement('div');
    this.emojiGrid.className = 'rte-emoji-grid';
    this.emojiGrid.style.cssText = `
      display: flex;
      flex-wrap: wrap;
      gap: 4px;
      max-height: 220px;
      overflow-y: auto;
    `;
    this.picker.appendChild(this.emojiGrid);

    // Show first category
    this.showCategory(0);

    document.body.appendChild(this.picker);
  }

  /**
   * Show emojis from category
   * @param {number} categoryIndex
   */
  showCategory(categoryIndex) {
    const category = EmojiPlugin.categories[categoryIndex];
    if (!category) return;

    this.currentEmojis = category.emojis;
    this.renderEmojis(category.emojis);
  }

  /**
   * Filter emojis by search term
   * @param {string} term
   */
  filterEmojis(term) {
    if (!term) {
      this.renderEmojis(this.currentEmojis || EmojiPlugin.categories[0].emojis);
      return;
    }

    const lowerTerm = term.toLowerCase();
    const filtered = [];

    EmojiPlugin.categories.forEach(cat => {
      cat.emojis.forEach(emoji => {
        if (emoji.name && emoji.name.toLowerCase().includes(lowerTerm)) {
          filtered.push(emoji);
        }
      });
    });

    this.renderEmojis(filtered);
  }

  /**
   * Render emojis to grid
   * @param {Array} emojis
   */
  renderEmojis(emojis) {
    this.emojiGrid.innerHTML = '';

    emojis.forEach(emoji => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'rte-emoji-btn';
      btn.textContent = emoji.char;
      btn.title = emoji.name || '';
      btn.style.cssText = `
        width: 32px;
        height: 32px;
        border: none;
        background: transparent;
        font-size: 20px;
        cursor: pointer;
        border-radius: 4px;
        transition: all 0.15s;
      `;
      btn.addEventListener('mouseover', () => {
        btn.style.background = 'var(--rte-bg-hover, #f0f0f0)';
        btn.style.transform = 'scale(1.2)';
      });
      btn.addEventListener('mouseout', () => {
        btn.style.background = 'transparent';
        btn.style.transform = 'scale(1)';
      });
      btn.addEventListener('click', () => this.insertEmoji(emoji.char));
      this.emojiGrid.appendChild(btn);
    });
  }

  /**
   * Insert emoji at cursor
   * @param {string} emoji
   */
  insertEmoji(emoji) {
    this.restoreSelection();
    this.insertHtml(emoji);
    this.recordHistory(true);
    this.close();
    this.focusEditor();
  }

  destroy() {
    if (this.picker && this.picker.parentNode) {
      this.picker.parentNode.removeChild(this.picker);
    }
    document.removeEventListener('click', this._boundDocumentClick);
    super.destroy();
  }
}

// Emoji categories with common emojis
EmojiPlugin.categories = [
  {
    name: 'Smileys',
    icon: '😀',
    emojis: [
      {char: '😀', name: 'grinning'}, {char: '😃', name: 'smiley'}, {char: '😄', name: 'smile'},
      {char: '😁', name: 'grin'}, {char: '😆', name: 'laughing'}, {char: '😅', name: 'sweat smile'},
      {char: '🤣', name: 'rofl'}, {char: '😂', name: 'joy'}, {char: '🙂', name: 'slightly smiling'},
      {char: '😉', name: 'wink'}, {char: '😊', name: 'blush'}, {char: '😇', name: 'innocent'},
      {char: '🥰', name: 'smiling hearts'}, {char: '😍', name: 'heart eyes'}, {char: '🤩', name: 'star struck'},
      {char: '😘', name: 'kissing heart'}, {char: '😗', name: 'kissing'}, {char: '😚', name: 'kissing closed eyes'},
      {char: '😙', name: 'kissing smiling'}, {char: '🥲', name: 'smiling tear'}, {char: '😋', name: 'yum'},
      {char: '😛', name: 'stuck out tongue'}, {char: '😜', name: 'winking tongue'}, {char: '🤪', name: 'zany'},
      {char: '😝', name: 'squinting tongue'}, {char: '🤑', name: 'money mouth'}, {char: '🤗', name: 'hugging'},
      {char: '🤭', name: 'hand over mouth'}, {char: '🤫', name: 'shushing'}, {char: '🤔', name: 'thinking'},
      {char: '🤐', name: 'zipper mouth'}, {char: '🤨', name: 'raised eyebrow'}, {char: '😐', name: 'neutral'},
      {char: '😑', name: 'expressionless'}, {char: '😶', name: 'no mouth'}, {char: '😏', name: 'smirk'},
      {char: '😒', name: 'unamused'}, {char: '🙄', name: 'rolling eyes'}, {char: '😬', name: 'grimacing'},
      {char: '😮‍💨', name: 'exhaling'}, {char: '🤥', name: 'lying'}, {char: '😌', name: 'relieved'},
      {char: '😔', name: 'pensive'}, {char: '😪', name: 'sleepy'}, {char: '🤤', name: 'drooling'},
      {char: '😴', name: 'sleeping'}, {char: '😷', name: 'mask'}, {char: '🤒', name: 'thermometer'},
      {char: '🤕', name: 'bandage'}, {char: '🤢', name: 'nauseated'}, {char: '🤮', name: 'vomiting'},
      {char: '😵', name: 'dizzy'}, {char: '🤯', name: 'exploding head'}, {char: '🥳', name: 'partying'},
      {char: '🥸', name: 'disguised'}, {char: '😎', name: 'sunglasses'}, {char: '🤓', name: 'nerd'},
      {char: '😕', name: 'confused'}, {char: '😟', name: 'worried'}, {char: '🙁', name: 'frowning'},
      {char: '😮', name: 'open mouth'}, {char: '😯', name: 'hushed'}, {char: '😲', name: 'astonished'},
      {char: '😳', name: 'flushed'}, {char: '🥺', name: 'pleading'}, {char: '😦', name: 'frowning open'},
      {char: '😧', name: 'anguished'}, {char: '😨', name: 'fearful'}, {char: '😰', name: 'cold sweat'},
      {char: '😥', name: 'sad relieved'}, {char: '😢', name: 'crying'}, {char: '😭', name: 'loudly crying'},
      {char: '😱', name: 'screaming'}, {char: '😖', name: 'confounded'}, {char: '😣', name: 'persevering'},
      {char: '😞', name: 'disappointed'}, {char: '😓', name: 'sweat'}, {char: '😩', name: 'weary'},
      {char: '😫', name: 'tired'}, {char: '🥱', name: 'yawning'}, {char: '😤', name: 'triumph'},
      {char: '😡', name: 'pouting'}, {char: '😠', name: 'angry'}, {char: '🤬', name: 'cursing'},
      {char: '😈', name: 'smiling devil'}, {char: '👿', name: 'angry devil'}, {char: '💀', name: 'skull'},
      {char: '☠️', name: 'skull crossbones'}, {char: '💩', name: 'poop'}, {char: '🤡', name: 'clown'},
      {char: '👹', name: 'ogre'}, {char: '👺', name: 'goblin'}, {char: '👻', name: 'ghost'},
      {char: '👽', name: 'alien'}, {char: '👾', name: 'alien monster'}, {char: '🤖', name: 'robot'}
    ]
  },
  {
    name: 'Gestures',
    icon: '👍',
    emojis: [
      {char: '👍', name: 'thumbs up'}, {char: '👎', name: 'thumbs down'}, {char: '👌', name: 'ok'},
      {char: '✌️', name: 'victory'}, {char: '🤞', name: 'crossed fingers'}, {char: '🤟', name: 'love you'},
      {char: '🤘', name: 'rock on'}, {char: '🤙', name: 'call me'}, {char: '👋', name: 'waving'},
      {char: '🤚', name: 'raised back'}, {char: '🖐️', name: 'raised hand'}, {char: '✋', name: 'raised palm'},
      {char: '🖖', name: 'vulcan'}, {char: '👏', name: 'clapping'}, {char: '🙌', name: 'raised hands'},
      {char: '🤲', name: 'palms up'}, {char: '🤝', name: 'handshake'}, {char: '🙏', name: 'pray'},
      {char: '✍️', name: 'writing'}, {char: '💪', name: 'strong'}, {char: '🦾', name: 'mechanical arm'},
      {char: '🖕', name: 'middle finger'}, {char: '☝️', name: 'point up'}, {char: '👆', name: 'pointing up'},
      {char: '👇', name: 'pointing down'}, {char: '👈', name: 'pointing left'}, {char: '👉', name: 'pointing right'},
      {char: '✊', name: 'raised fist'}, {char: '👊', name: 'punch'}, {char: '🤛', name: 'left fist'},
      {char: '🤜', name: 'right fist'}
    ]
  },
  {
    name: 'Hearts',
    icon: '❤️',
    emojis: [
      {char: '❤️', name: 'red heart'}, {char: '🧡', name: 'orange heart'}, {char: '💛', name: 'yellow heart'},
      {char: '💚', name: 'green heart'}, {char: '💙', name: 'blue heart'}, {char: '💜', name: 'purple heart'},
      {char: '🖤', name: 'black heart'}, {char: '🤍', name: 'white heart'}, {char: '🤎', name: 'brown heart'},
      {char: '💔', name: 'broken heart'}, {char: '❤️‍🔥', name: 'heart fire'}, {char: '❤️‍🩹', name: 'mending heart'},
      {char: '💕', name: 'two hearts'}, {char: '💞', name: 'revolving hearts'}, {char: '💓', name: 'beating heart'},
      {char: '💗', name: 'growing heart'}, {char: '💖', name: 'sparkling heart'}, {char: '💘', name: 'heart arrow'},
      {char: '💝', name: 'heart ribbon'}, {char: '💟', name: 'heart decoration'}, {char: '💌', name: 'love letter'}
    ]
  },
  {
    name: 'Objects',
    icon: '📦',
    emojis: [
      {char: '⌚', name: 'watch'}, {char: '📱', name: 'phone'}, {char: '💻', name: 'laptop'},
      {char: '⌨️', name: 'keyboard'}, {char: '🖥️', name: 'computer'}, {char: '🖨️', name: 'printer'},
      {char: '🖱️', name: 'mouse'}, {char: '💾', name: 'floppy disk'}, {char: '💿', name: 'cd'},
      {char: '📷', name: 'camera'}, {char: '📹', name: 'video camera'}, {char: '🎥', name: 'movie camera'},
      {char: '📺', name: 'tv'}, {char: '📻', name: 'radio'}, {char: '🎙️', name: 'microphone'},
      {char: '🔋', name: 'battery'}, {char: '🔌', name: 'plug'}, {char: '💡', name: 'light bulb'},
      {char: '🔦', name: 'flashlight'}, {char: '📚', name: 'books'}, {char: '📖', name: 'book'},
      {char: '📝', name: 'memo'}, {char: '✏️', name: 'pencil'}, {char: '📎', name: 'paperclip'},
      {char: '📌', name: 'pushpin'}, {char: '✂️', name: 'scissors'}, {char: '📁', name: 'folder'},
      {char: '🗂️', name: 'folders'}, {char: '📅', name: 'calendar'}, {char: '📆', name: 'tear calendar'}
    ]
  },
  {
    name: 'Symbols',
    icon: '✅',
    emojis: [
      {char: '✅', name: 'check mark'}, {char: '❌', name: 'cross'}, {char: '❓', name: 'question'},
      {char: '❗', name: 'exclamation'}, {char: '💯', name: '100'}, {char: '🔥', name: 'fire'},
      {char: '⭐', name: 'star'}, {char: '🌟', name: 'glowing star'}, {char: '✨', name: 'sparkles'},
      {char: '💫', name: 'dizzy'}, {char: '🎯', name: 'target'}, {char: '🏆', name: 'trophy'},
      {char: '🎉', name: 'party'}, {char: '🎊', name: 'confetti'}, {char: '🎁', name: 'gift'},
      {char: '🔔', name: 'bell'}, {char: '🔕', name: 'no bell'}, {char: '🔒', name: 'lock'},
      {char: '🔓', name: 'unlock'}, {char: '🔑', name: 'key'}, {char: '⚙️', name: 'gear'},
      {char: '🔧', name: 'wrench'}, {char: '🔨', name: 'hammer'}, {char: '⚡', name: 'lightning'},
      {char: '💎', name: 'gem'}, {char: '🔗', name: 'link'}, {char: '📍', name: 'pin'},
      {char: '🏷️', name: 'tag'}, {char: '⏰', name: 'alarm'}, {char: '⏳', name: 'hourglass'}
    ]
  },
  {
    name: 'Arrows',
    icon: '➡️',
    emojis: [
      {char: '⬆️', name: 'up'}, {char: '⬇️', name: 'down'}, {char: '⬅️', name: 'left'},
      {char: '➡️', name: 'right'}, {char: '↗️', name: 'up right'}, {char: '↘️', name: 'down right'},
      {char: '↙️', name: 'down left'}, {char: '↖️', name: 'up left'}, {char: '↕️', name: 'up down'},
      {char: '↔️', name: 'left right'}, {char: '🔄', name: 'arrows'}, {char: '🔃', name: 'clockwise'},
      {char: '🔀', name: 'shuffle'}, {char: '🔁', name: 'repeat'}, {char: '🔂', name: 'repeat one'},
      {char: '▶️', name: 'play'}, {char: '⏸️', name: 'pause'}, {char: '⏹️', name: 'stop'},
      {char: '⏺️', name: 'record'}, {char: '⏭️', name: 'next'}, {char: '⏮️', name: 'previous'},
      {char: '⏩', name: 'fast forward'}, {char: '⏪', name: 'rewind'}, {char: '🔼', name: 'up button'},
      {char: '🔽', name: 'down button'}
    ]
  }
];

export default EmojiPlugin;
