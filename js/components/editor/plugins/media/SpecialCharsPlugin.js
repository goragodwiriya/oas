/**
 * SpecialCharsPlugin - Insert special characters
 *
 * @author Goragod Wiriya
 * @version 1.0
 */
import PluginBase from '../PluginBase.js';
import BaseDialog from '../../ui/dialogs/BaseDialog.js';
import EventBus from '../../core/EventBus.js';

class SpecialCharsDialog extends BaseDialog {
  constructor(editor, plugin) {
    super(editor, {
      title: 'Special Characters',
      width: 500
    });
    this.plugin = plugin;
  }

  buildBody() {
    // Category tabs
    const tabs = document.createElement('div');
    tabs.className = 'rte-specialchars-tabs';
    tabs.style.cssText = `
      display: flex;
      flex-wrap: wrap;
      gap: 4px;
      margin-bottom: 16px;
      border-bottom: 1px solid var(--rte-border-color);
      padding-bottom: 8px;
    `;

    SpecialCharsPlugin.categories.forEach((cat, index) => {
      const tab = document.createElement('button');
      tab.type = 'button';
      tab.className = `rte-specialchars-tab ${index === 0 ? 'active' : ''}`;
      tab.textContent = this.translate(cat.name);
      tab.style.cssText = `
        padding: 6px 12px;
        border: none;
        background: transparent;
        font-size: 13px;
        cursor: pointer;
        border-radius: 4px;
        transition: all 0.2s;
        color: var(--rte-text-secondary);
      `;
      if (index === 0) {
        tab.style.background = 'var(--rte-primary-light)';
        tab.style.color = 'var(--rte-primary-color)';
      }
      tab.addEventListener('click', () => {
        tabs.querySelectorAll('.rte-specialchars-tab').forEach(t => {
          t.style.background = 'transparent';
          t.style.color = 'var(--rte-text-secondary)';
        });
        tab.style.background = 'var(--rte-primary-light)';
        tab.style.color = 'var(--rte-primary-color)';
        this.showCategory(index);
      });
      tabs.appendChild(tab);
    });
    this.body.appendChild(tabs);

    // Character grid
    this.charGrid = document.createElement('div');
    this.charGrid.className = 'rte-specialchars-grid';
    this.charGrid.style.cssText = `
      display: flex;
      flex-wrap: wrap;
      gap: 4px;
      max-height: 300px;
      overflow-y: auto;
    `;
    this.body.appendChild(this.charGrid);

    // Selected character preview
    this.preview = document.createElement('div');
    this.preview.className = 'rte-specialchars-preview';
    this.preview.style.cssText = `
      margin-top: 16px;
      padding: 12px;
      background: var(--rte-bg-secondary);
      border-radius: 6px;
      display: flex;
      align-items: center;
      gap: 16px;
    `;
    this.preview.innerHTML = `
      <span class="preview-char" style="font-size: 32px; width: 48px; text-align: center;"></span>
      <div class="preview-info">
        <div class="preview-name" style="font-weight: 500; margin-bottom: 4px;"></div>
        <div class="preview-code" style="font-size: 12px; color: var(--rte-text-muted); font-family: monospace;"></div>
      </div>
    `;
    this.body.appendChild(this.preview);

    // Show first category
    this.showCategory(0);
  }

  buildFooter() {
    const insertBtn = document.createElement('button');
    insertBtn.type = 'button';
    insertBtn.className = 'rte-dialog-btn rte-dialog-btn-primary';
    insertBtn.textContent = this.translate('Insert');
    insertBtn.addEventListener('click', () => {
      if (this.selectedChar) {
        this.plugin.insertChar(this.selectedChar);
        this.close();
      }
    });

    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'rte-dialog-btn';
    closeBtn.textContent = this.translate('Close');
    closeBtn.addEventListener('click', () => this.close());

    this.footer.appendChild(closeBtn);
    this.footer.appendChild(insertBtn);
  }

  showCategory(categoryIndex) {
    const category = SpecialCharsPlugin.categories[categoryIndex];
    if (!category) return;

    this.charGrid.innerHTML = '';

    category.chars.forEach(charInfo => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'rte-specialchar-btn';
      btn.textContent = charInfo.char;
      btn.title = charInfo.name;
      btn.style.cssText = `
        width: 36px;
        height: 36px;
        border: 1px solid var(--rte-border-color);
        background: var(--rte-bg-color);
        font-size: 18px;
        cursor: pointer;
        border-radius: 4px;
        transition: all 0.15s;
      `;
      btn.addEventListener('mouseover', () => {
        btn.style.background = 'var(--rte-bg-hover)';
        btn.style.transform = 'scale(1.1)';
      });
      btn.addEventListener('mouseout', () => {
        btn.style.background = 'var(--rte-bg-color)';
        btn.style.transform = 'scale(1)';
        if (this.selectedCharBtn !== btn) {
          btn.style.borderColor = 'var(--rte-border-color)';
        }
      });
      btn.addEventListener('click', () => {
        this.selectChar(charInfo, btn);
      });
      btn.addEventListener('dblclick', () => {
        this.plugin.insertChar(charInfo.char);
        this.close();
      });
      this.charGrid.appendChild(btn);
    });
  }

  selectChar(charInfo, btn) {
    // Update selection style
    if (this.selectedCharBtn) {
      this.selectedCharBtn.style.borderColor = 'var(--rte-border-color)';
    }
    btn.style.borderColor = 'var(--rte-primary-color)';
    this.selectedCharBtn = btn;

    // Update preview
    this.selectedChar = charInfo.char;
    this.preview.querySelector('.preview-char').textContent = charInfo.char;
    this.preview.querySelector('.preview-name').textContent = charInfo.name;
    this.preview.querySelector('.preview-code').textContent = charInfo.code || `U+${charInfo.char.charCodeAt(0).toString(16).toUpperCase().padStart(4, '0')}`;
  }
}

class SpecialCharsPlugin extends PluginBase {
  static pluginName = 'specialChars';

  init() {
    super.init();

    this.dialog = new SpecialCharsDialog(this.editor, this);

    // Listen for toolbar button click
    this.subscribe(EventBus.Events.TOOLBAR_BUTTON_CLICK, (event) => {
      if (event.id === 'specialChars') {
        this.openDialog();
      }
    });
  }

  openDialog() {
    this.saveSelection();
    this.dialog.open();
  }

  insertChar(char) {
    this.restoreSelection();
    this.insertHtml(char);
    this.recordHistory(true);
    this.focusEditor();
  }

  destroy() {
    this.dialog?.destroy();
    super.destroy();
  }
}

// Character categories
SpecialCharsPlugin.categories = [
  {
    name: 'Currency',
    chars: [
      {char: '$', name: 'Dollar', code: 'U+0024'},
      {char: '€', name: 'Euro', code: 'U+20AC'},
      {char: '£', name: 'Pound', code: 'U+00A3'},
      {char: '¥', name: 'Yen', code: 'U+00A5'},
      {char: '¢', name: 'Cent', code: 'U+00A2'},
      {char: '₹', name: 'Rupee', code: 'U+20B9'},
      {char: '₩', name: 'Won', code: 'U+20A9'},
      {char: '₽', name: 'Ruble', code: 'U+20BD'},
      {char: '฿', name: 'Baht', code: 'U+0E3F'},
      {char: '₿', name: 'Bitcoin', code: 'U+20BF'},
      {char: '₫', name: 'Dong', code: 'U+20AB'},
      {char: '₱', name: 'Peso', code: 'U+20B1'}
    ]
  },
  {
    name: 'Math',
    chars: [
      {char: '±', name: 'Plus Minus', code: 'U+00B1'},
      {char: '×', name: 'Multiplication', code: 'U+00D7'},
      {char: '÷', name: 'Division', code: 'U+00F7'},
      {char: '≠', name: 'Not Equal', code: 'U+2260'},
      {char: '≈', name: 'Approximately', code: 'U+2248'},
      {char: '≤', name: 'Less or Equal', code: 'U+2264'},
      {char: '≥', name: 'Greater or Equal', code: 'U+2265'},
      {char: '∞', name: 'Infinity', code: 'U+221E'},
      {char: '√', name: 'Square Root', code: 'U+221A'},
      {char: '∑', name: 'Sum', code: 'U+2211'},
      {char: '∏', name: 'Product', code: 'U+220F'},
      {char: '∫', name: 'Integral', code: 'U+222B'},
      {char: '∂', name: 'Partial Derivative', code: 'U+2202'},
      {char: 'π', name: 'Pi', code: 'U+03C0'},
      {char: 'Ω', name: 'Omega', code: 'U+03A9'},
      {char: 'µ', name: 'Micro', code: 'U+00B5'},
      {char: '∆', name: 'Delta', code: 'U+2206'},
      {char: '∇', name: 'Nabla', code: 'U+2207'},
      {char: '∈', name: 'Element Of', code: 'U+2208'},
      {char: '∉', name: 'Not Element Of', code: 'U+2209'},
      {char: '∅', name: 'Empty Set', code: 'U+2205'},
      {char: '∩', name: 'Intersection', code: 'U+2229'},
      {char: '∪', name: 'Union', code: 'U+222A'},
      {char: '⊂', name: 'Subset', code: 'U+2282'}
    ]
  },
  {
    name: 'Greek',
    chars: [
      {char: 'α', name: 'Alpha'}, {char: 'β', name: 'Beta'}, {char: 'γ', name: 'Gamma'},
      {char: 'δ', name: 'Delta'}, {char: 'ε', name: 'Epsilon'}, {char: 'ζ', name: 'Zeta'},
      {char: 'η', name: 'Eta'}, {char: 'θ', name: 'Theta'}, {char: 'ι', name: 'Iota'},
      {char: 'κ', name: 'Kappa'}, {char: 'λ', name: 'Lambda'}, {char: 'μ', name: 'Mu'},
      {char: 'ν', name: 'Nu'}, {char: 'ξ', name: 'Xi'}, {char: 'ο', name: 'Omicron'},
      {char: 'π', name: 'Pi'}, {char: 'ρ', name: 'Rho'}, {char: 'σ', name: 'Sigma'},
      {char: 'τ', name: 'Tau'}, {char: 'υ', name: 'Upsilon'}, {char: 'φ', name: 'Phi'},
      {char: 'χ', name: 'Chi'}, {char: 'ψ', name: 'Psi'}, {char: 'ω', name: 'Omega'},
      {char: 'Α', name: 'Alpha (Upper)'}, {char: 'Β', name: 'Beta (Upper)'}, {char: 'Γ', name: 'Gamma (Upper)'},
      {char: 'Δ', name: 'Delta (Upper)'}, {char: 'Σ', name: 'Sigma (Upper)'}, {char: 'Ω', name: 'Omega (Upper)'}
    ]
  },
  {
    name: 'Punctuation',
    chars: [
      {char: '—', name: 'Em Dash', code: 'U+2014'},
      {char: '–', name: 'En Dash', code: 'U+2013'},
      {char: '…', name: 'Ellipsis', code: 'U+2026'},
      {char: '‹', name: 'Single Left Quote'},
      {char: '›', name: 'Single Right Quote'},
      {char: '«', name: 'Double Left Quote'},
      {char: '»', name: 'Double Right Quote'},
      {char: '"', name: 'Left Double Quote'},
      {char: '"', name: 'Right Double Quote'},
      {char: '\'', name: 'Single Quote'},
      {char: '•', name: 'Bullet', code: 'U+2022'},
      {char: '·', name: 'Middle Dot', code: 'U+00B7'},
      {char: '†', name: 'Dagger', code: 'U+2020'},
      {char: '‡', name: 'Double Dagger', code: 'U+2021'},
      {char: '§', name: 'Section', code: 'U+00A7'},
      {char: '¶', name: 'Paragraph', code: 'U+00B6'},
      {char: '©', name: 'Copyright', code: 'U+00A9'},
      {char: '®', name: 'Registered', code: 'U+00AE'},
      {char: '™', name: 'Trademark', code: 'U+2122'},
      {char: '°', name: 'Degree', code: 'U+00B0'},
      {char: '′', name: 'Prime', code: 'U+2032'},
      {char: '″', name: 'Double Prime', code: 'U+2033'},
      {char: '№', name: 'Numero', code: 'U+2116'}
    ]
  },
  {
    name: 'Arrows',
    chars: [
      {char: '←', name: 'Left Arrow'}, {char: '→', name: 'Right Arrow'},
      {char: '↑', name: 'Up Arrow'}, {char: '↓', name: 'Down Arrow'},
      {char: '↔', name: 'Left Right Arrow'}, {char: '↕', name: 'Up Down Arrow'},
      {char: '↖', name: 'Up Left Arrow'}, {char: '↗', name: 'Up Right Arrow'},
      {char: '↘', name: 'Down Right Arrow'}, {char: '↙', name: 'Down Left Arrow'},
      {char: '⇐', name: 'Double Left Arrow'}, {char: '⇒', name: 'Double Right Arrow'},
      {char: '⇑', name: 'Double Up Arrow'}, {char: '⇓', name: 'Double Down Arrow'},
      {char: '⇔', name: 'Double Left Right'}, {char: '⇕', name: 'Double Up Down'},
      {char: '↩', name: 'Left Hook'}, {char: '↪', name: 'Right Hook'},
      {char: '↺', name: 'Anticlockwise'}, {char: '↻', name: 'Clockwise'},
      {char: '⟵', name: 'Long Left'}, {char: '⟶', name: 'Long Right'},
      {char: '▲', name: 'Triangle Up'}, {char: '▼', name: 'Triangle Down'}
    ]
  },
  {
    name: 'Shapes',
    chars: [
      {char: '■', name: 'Black Square'}, {char: '□', name: 'White Square'},
      {char: '▪', name: 'Small Black Square'}, {char: '▫', name: 'Small White Square'},
      {char: '●', name: 'Black Circle'}, {char: '○', name: 'White Circle'},
      {char: '◆', name: 'Black Diamond'}, {char: '◇', name: 'White Diamond'},
      {char: '★', name: 'Black Star'}, {char: '☆', name: 'White Star'},
      {char: '▶', name: 'Right Triangle'}, {char: '◀', name: 'Left Triangle'},
      {char: '▲', name: 'Up Triangle'}, {char: '▼', name: 'Down Triangle'},
      {char: '►', name: 'Right Pointer'}, {char: '◄', name: 'Left Pointer'},
      {char: '♠', name: 'Spade'}, {char: '♣', name: 'Club'},
      {char: '♥', name: 'Heart'}, {char: '♦', name: 'Diamond'},
      {char: '♪', name: 'Music Note'}, {char: '♫', name: 'Music Notes'},
      {char: '✓', name: 'Check Mark'}, {char: '✗', name: 'Cross Mark'}
    ]
  },
  {
    name: 'Latin',
    chars: [
      {char: 'À', name: 'A Grave'}, {char: 'Á', name: 'A Acute'}, {char: 'Â', name: 'A Circumflex'},
      {char: 'Ã', name: 'A Tilde'}, {char: 'Ä', name: 'A Umlaut'}, {char: 'Å', name: 'A Ring'},
      {char: 'Æ', name: 'AE'}, {char: 'Ç', name: 'C Cedilla'}, {char: 'È', name: 'E Grave'},
      {char: 'É', name: 'E Acute'}, {char: 'Ê', name: 'E Circumflex'}, {char: 'Ë', name: 'E Umlaut'},
      {char: 'Ñ', name: 'N Tilde'}, {char: 'Ö', name: 'O Umlaut'}, {char: 'Ü', name: 'U Umlaut'},
      {char: 'ß', name: 'Sharp S'}, {char: 'à', name: 'a Grave'}, {char: 'á', name: 'a Acute'},
      {char: 'â', name: 'a Circumflex'}, {char: 'ã', name: 'a Tilde'}, {char: 'ä', name: 'a Umlaut'},
      {char: 'å', name: 'a Ring'}, {char: 'æ', name: 'ae'}, {char: 'ç', name: 'c Cedilla'},
      {char: 'è', name: 'e Grave'}, {char: 'é', name: 'e Acute'}, {char: 'ê', name: 'e Circumflex'},
      {char: 'ë', name: 'e Umlaut'}, {char: 'ñ', name: 'n Tilde'}, {char: 'ö', name: 'o Umlaut'},
      {char: 'ü', name: 'u Umlaut'}, {char: 'ÿ', name: 'y Umlaut'}, {char: 'œ', name: 'OE'}
    ]
  }
];

export default SpecialCharsPlugin;
