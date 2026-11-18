/**
 * CodeEditor
 *
 * Turns a `<textarea>` into a code editor: syntax colours, line numbers, and
 * the editing keys programmers expect (indent, auto-close brackets, comment
 * toggling).
 *
 * The textarea stays the editing surface. Typing, the caret, selection, undo,
 * input methods (Thai included) and form submission are all the browser's own.
 * A `<pre>` drawn behind it shows the same text in colour, and nothing is ever
 * read back from that layer, so the textarea's value is always exactly the
 * code — no markup or invisible characters mixed in.
 *
 * Every edit the editor makes itself goes through
 * `document.execCommand('insertText')`, so it lands in the browser's undo
 * history like a typed character.
 *
 * Usage:
 *   const editor = new CodeEditor(textarea, {language: 'javascript'});
 *   editor.getValue();
 *
 * In a form, prefer `<textarea data-element="code" data-language="...">`
 * (CodeElementFactory), which adds the framework's validation on top.
 */
class CodeEditor {
  static defaults = {
    language: 'text',        // Language name or alias, see CodeEditor.languages
    lineNumbers: true,       // Show the line-number gutter
    tabSize: 2,              // Columns per indent level
    indentWithTabs: false,   // Indent with a tab character instead of spaces
    autoCloseBrackets: true, // Type the closing bracket or quote together with the opening one
    autoIndent: true,        // Keep (and deepen after an opening bracket) the indent on Enter
    minLines: 3,             // Minimum visible lines
    height: null,            // Fixed height (CSS length or px number); scrolls inside
    maxHeight: null,         // Grow with the content up to this height (CSS length or px number)
    theme: null,             // 'light' | 'dark' | null (follow the page's data-theme)
    onChange: null           // Called with the new value after every change
  };

  /** Keys that only modify another key; pressing one alone is not an action in the editor. */
  static MODIFIER_KEYS = new Set(['Shift', 'Control', 'Alt', 'AltGraph', 'Meta', 'CapsLock', 'Fn', 'FnLock', 'Hyper', 'Super', 'OS']);

  /** Pairs that auto-close: opening character → closing character. */
  static PAIRS = {'(': ')', '[': ']', '{': '}', '"': '"', "'": "'", '`': '`'};

  /** Beyond this many characters the code is shown without colours, to keep typing smooth. */
  static MAX_HIGHLIGHT_LENGTH = 300000;

  /** Files up to this many lines are drawn whole; longer ones only around the visible lines. */
  static DRAW_ALL_LINES = 300;

  /** Lines drawn above and below the visible ones in a long file. */
  static DRAW_MARGIN_LINES = 40;

  /** Registered languages, keyed by name. */
  static languages = {};

  /** Alternative names, alias → language name. */
  static aliases = {};

  static _instances = new WeakMap();

  /**
   * Find the editor attached to a textarea.
   *
   * @param {HTMLTextAreaElement|string} textarea - The textarea or a selector.
   * @returns {CodeEditor|null} - The editor, or null when none is attached.
   */
  static get(textarea) {
    const element = typeof textarea === 'string' ? document.querySelector(textarea) : textarea;
    return (element && this._instances.get(element)) || null;
  }

  /**
   * Add or replace a language.
   *
   * A definition holds `rules` — tried in order at every position, the first
   * match wins — plus optional `lineComment`, `blockComment` ([open, close])
   * and `state()` (a factory for per-scan state that rules may read and change).
   *
   * A rule is either `{type, match}` with a sticky (`y`) RegExp, or
   * `{scan(text, pos, state)}` returning `{tokens, end}` for constructs a
   * single RegExp cannot express. `type` may be a function
   * `(matchedText, state, previousToken, text, end)` returning the type.
   * Optional: `when(previousToken, state)` to enable the rule only in some
   * contexts, `onMatch(matchedText, state)` to update state, and `skip: true`
   * for tokens (spaces, comments) that should not count as the previous token.
   *
   * @param {string} name - Language name.
   * @param {Object} definition - Language definition.
   * @param {string[]} [aliases=[]] - Other names that map to this language.
   * @returns {void}
   */
  static registerLanguage(name, definition, aliases = []) {
    this.languages[name] = definition;
    aliases.forEach(alias => {
      this.aliases[alias] = name;
    });
  }

  /**
   * Resolve a language name or alias to a registered language name.
   *
   * @param {string} language - Name or alias, any case.
   * @returns {string} - The registered name, or 'text' when unknown.
   */
  static resolveLanguage(language) {
    const key = String(language || 'text').toLowerCase();
    if (this.languages[key]) return key;
    if (this.aliases[key]) return this.aliases[key];
    return 'text';
  }

  /**
   * Split code into tokens.
   *
   * @param {string} code - Source text.
   * @param {string} language - Language name or alias.
   * @returns {Array<{type: string|null, text: string}>} - Tokens in order; joining their
   *   text gives back the code exactly. `type` is null for text without a colour.
   */
  static tokenize(code, language) {
    const out = [];
    if (!code) return out;
    const lang = this.languages[this.resolveLanguage(language)];
    if (!lang || !lang.rules) {
      out.push({type: null, text: code});
      return out;
    }
    this._scan(code, lang, out);
    return out;
  }

  /**
   * Render code as HTML with one `<span class="ce-{type}">` per coloured token.
   *
   * The result is escaped and safe to assign to `innerHTML`.
   *
   * @param {string} code - Source text.
   * @param {string} language - Language name or alias.
   * @returns {string} - HTML.
   */
  static highlight(code, language) {
    let html = '';
    for (const token of this.tokenize(code, language)) {
      const text = this.escapeHtml(token.text);
      html += token.type ? `<span class="ce-${token.type}">${text}</span>` : text;
    }
    return html;
  }

  /**
   * Escape text for use inside HTML.
   *
   * @param {string} text - Text to escape.
   * @returns {string} - Escaped text.
   */
  static escapeHtml(text) {
    return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  /**
   * Run a language's rules over a whole text, appending tokens to `out`.
   *
   * Characters no rule matches are kept as uncoloured text, so the tokens
   * always join back to the original text.
   *
   * With `resume`, scanning starts part-way through the text and records a
   * checkpoint (position, previous token, language state, token index) at the
   * first token of every line, which is what lets `_lex` re-scan only around
   * an edit. `resume.onCheckpoint(point)` returning true stops the scan there.
   *
   * @param {string} text - Text to scan.
   * @param {Object} lang - Language definition.
   * @param {Array} out - Token list to append to.
   * @param {Object} [resume] - `{pos, prev, state, checkpoints, onCheckpoint}`.
   * @returns {boolean} - True when `onCheckpoint` stopped the scan.
   * @private
   */
  static _scan(text, lang, out, resume = null) {
    const rules = lang.rules;
    const state = resume && resume.state ? {...resume.state} : (typeof lang.state === 'function' ? lang.state() : {});
    const length = text.length;
    const checkpoints = resume ? resume.checkpoints : null;
    let pos = resume ? resume.pos : 0;
    let plainStart = pos;
    let prev = resume ? resume.prev : null;
    let lineBreak = false;
    // A checkpoint must start a token of its own: no merging across it
    let barrier = !!resume;

    const push = (type, str) => {
      if (!str) return;
      const last = out[out.length - 1];
      if (!barrier && last && last.type === type) {
        last.text += str;
      } else {
        out.push({type, text: str});
      }
      barrier = false;
    };

    while (pos < length) {
      if (lineBreak && checkpoints && pos === plainStart) {
        lineBreak = false;
        const point = {pos, prev, state: {...state}, token: out.length};
        if (resume.onCheckpoint && resume.onCheckpoint(point)) return true;
        checkpoints.push(point);
        barrier = true;
      }

      let matched = false;

      for (let i = 0; i < rules.length; i++) {
        const rule = rules[i];
        if (rule.when && !rule.when(prev, state)) continue;

        if (rule.scan) {
          const result = rule.scan(text, pos, state);
          if (!result || result.end <= pos) continue;
          if (pos > plainStart) push(null, text.slice(plainStart, pos));
          result.tokens.forEach(token => push(token.type, token.text));
          prev = result.prev || {type: rule.type || 'string', text: text.slice(pos, result.end)};
          if (checkpoints && text.lastIndexOf('\n', result.end - 1) >= pos) lineBreak = true;
          pos = plainStart = result.end;
          matched = true;
          break;
        }

        rule.match.lastIndex = pos;
        const m = rule.match.exec(text);
        if (!m || !m[0]) continue;

        const end = pos + m[0].length;
        const type = typeof rule.type === 'function' ? rule.type(m[0], state, prev, text, end) : rule.type;
        if (pos > plainStart) push(null, text.slice(plainStart, pos));
        push(type || null, m[0]);
        if (rule.onMatch) rule.onMatch(m[0], state);
        if (!rule.skip) prev = {type: type || null, text: m[0]};
        if (checkpoints && m[0].includes('\n')) lineBreak = true;
        pos = plainStart = end;
        matched = true;
        break;
      }

      if (!matched) {
        prev = {type: null, text: text[pos]};
        pos++;
      }
    }

    if (length > plainStart) push(null, text.slice(plainStart));
    return false;
  }

  /**
   * Tokenize a value, reusing the tokens of the previous value where the two
   * are the same.
   *
   * Re-scans from a checkpoint well before the first changed character and
   * stops at the first checkpoint after the change whose scanner state matches
   * the old one there; the old tokens after it are reused as they are. Typing
   * in a long file therefore re-scans a few lines, not the whole file. An edit
   * that changes how the rest is read (opening a comment) re-scans to the end.
   *
   * @param {string} value - New text.
   * @param {string} language - Registered language name.
   * @param {Object|null} previous - The result of the previous `_lex` call, or null.
   * @returns {{value: string, language: string, tokens: Array, checkpoints: Array}} - Lexing result.
   * @private
   */
  static _lex(value, language, previous) {
    const lang = this.languages[language];
    if (!lang || !lang.rules || !value) {
      return {value, language, tokens: value ? [{type: null, text: value}] : [], checkpoints: null};
    }

    const initialState = typeof lang.state === 'function' ? lang.state() : {};
    if (!previous || previous.language !== language || !previous.checkpoints) {
      const tokens = [];
      const checkpoints = [{pos: 0, prev: null, state: initialState, token: 0}];
      this._scan(value, lang, tokens, {pos: 0, prev: null, state: initialState, checkpoints});
      return {value, language, tokens, checkpoints};
    }

    const before = previous.value;
    const shortest = Math.min(before.length, value.length);
    let prefix = 0;
    while (prefix < shortest && before.charCodeAt(prefix) === value.charCodeAt(prefix)) prefix++;
    let suffix = 0;
    while (suffix < shortest - prefix &&
      before.charCodeAt(before.length - 1 - suffix) === value.charCodeAt(value.length - 1 - suffix)) {
      suffix++;
    }
    const delta = value.length - before.length;
    const changeEnd = value.length - suffix;
    const old = previous.checkpoints;

    // Restart well before the change: a token just before it may have looked ahead into it
    let index = 0;
    let low = 0;
    let high = old.length - 1;
    while (low <= high) {
      const mid = (low + high) >> 1;
      if (old[mid].pos < prefix) {
        index = mid;
        low = mid + 1;
      } else {
        high = mid - 1;
      }
    }
    while (index > 0 && prefix - old[index].pos < 1000) index--;

    const start = old[index];
    const tokens = previous.tokens.slice(0, start.token);
    const checkpoints = old.slice(0, index + 1);
    let joined = null;

    const sameState = (a, b) => {
      for (const key in a) if (a[key] !== b[key]) return false;
      for (const key in b) if (!(key in a)) return false;
      return true;
    };
    const samePrev = (a, b) => a === b || (!!a && !!b && a.type === b.type && a.text === b.text);
    const findOld = (pos) => {
      let lo = index + 1;
      let hi = old.length - 1;
      while (lo <= hi) {
        const mid = (lo + hi) >> 1;
        if (old[mid].pos === pos) return mid;
        if (old[mid].pos < pos) lo = mid + 1;
        else hi = mid - 1;
      }
      return -1;
    };

    this._scan(value, lang, tokens, {
      pos: start.pos,
      prev: start.prev,
      state: start.state,
      checkpoints,
      onCheckpoint: point => {
        if (point.pos < changeEnd) return false;
        const match = findOld(point.pos - delta);
        if (match === -1) return false;
        const was = old[match];
        if (!samePrev(was.prev, point.prev) || !sameState(was.state, point.state)) return false;
        joined = {point, match};
        return true;
      }
    });

    if (joined) {
      const {point, match} = joined;
      const was = old[match];
      const tokenShift = point.token - was.token;
      for (let i = was.token; i < previous.tokens.length; i++) tokens.push(previous.tokens[i]);
      checkpoints.push(point);
      for (let i = match + 1; i < old.length; i++) {
        const c = old[i];
        checkpoints.push({pos: c.pos + delta, prev: c.prev, state: c.state, token: c.token + tokenShift});
      }
    }

    return {value, language, tokens, checkpoints};
  }

  /**
   * Attach an editor to a textarea.
   *
   * A textarea that already has an editor returns that editor instead of
   * getting a second one. Options not passed are read from the textarea's
   * `data-*` attributes (`data-language`, `data-line-numbers`, `data-tab-size`,
   * ...), then from `CodeEditor.defaults`.
   *
   * @param {HTMLTextAreaElement|string} textarea - The textarea or a selector.
   * @param {Object} [options={}] - Options, see `CodeEditor.defaults`.
   */
  constructor(textarea, options = {}) {
    const element = typeof textarea === 'string' ? document.querySelector(textarea) : textarea;
    if (!(element instanceof HTMLTextAreaElement)) {
      throw new Error('CodeEditor: a <textarea> element is required');
    }

    const existing = CodeEditor._instances.get(element);
    if (existing) return existing;

    this.textarea = element;
    this.options = {...CodeEditor.defaults, ...CodeEditor._optionsFromDataset(element), ...options};
    this.language = CodeEditor.resolveLanguage(this.options.language);
    this._lineCount = 0;
    this._lexed = null;
    this._window = null;
    this._viewportFrame = null;
    this._extraWidth = 0;
    this._widest = 0;
    this._renderedValue = null;
    this._renderedLanguage = null;
    this._frame = null;
    this._tabEscapes = null; // Caret position at Escape, while the next Tab may leave the editor
    this._metrics = null;

    this._build();
    this._hookValue();
    this._bind();
    this._render();

    CodeEditor._instances.set(element, this);
  }

  /**
   * Current code.
   *
   * @returns {string} - The textarea's value.
   */
  getValue() {
    return this.textarea.value;
  }

  /**
   * Replace the whole code from script.
   *
   * By default this is the same as assigning `textarea.value`: not in the undo
   * history and no `input` event. With `{history: true}` it is an edit the user
   * can undo with Ctrl+Z (it focuses the editor and fires `input`), which suits
   * actions such as loading an example or clearing the editor.
   *
   * @param {string} value - New code.
   * @param {Object} [options={}] - `{history: true}` to make it undoable.
   * @returns {CodeEditor} - This editor.
   */
  setValue(value, options = {}) {
    const text = value == null ? '' : String(value);
    if (options.history && !this.textarea.readOnly && !this.textarea.disabled) {
      if (text !== this.textarea.value) this._replace(0, this.textarea.value.length, text, 0, 0);
      this.scroller.scrollTop = 0;
      this.scroller.scrollLeft = 0;
      return this;
    }
    this.textarea.value = text;
    this._notifyChange();
    return this;
  }

  /**
   * Language currently used for colours and comment toggling.
   *
   * @returns {string} - Registered language name.
   */
  getLanguage() {
    return this.language;
  }

  /**
   * Switch the language and recolour the code.
   *
   * @param {string} language - Language name or alias.
   * @returns {CodeEditor} - This editor.
   */
  setLanguage(language) {
    this.language = CodeEditor.resolveLanguage(language);
    this.root.dataset.language = this.language;
    this._render();
    return this;
  }

  /**
   * Move keyboard focus into the editor.
   *
   * @returns {CodeEditor} - This editor.
   */
  focus() {
    this.textarea.focus();
    return this;
  }

  /**
   * Replace the selection with text, as if it had been typed.
   *
   * The change is undoable with Ctrl+Z and fires the textarea's `input` event.
   *
   * @param {string} text - Text to insert.
   * @returns {CodeEditor} - This editor.
   */
  insertText(text) {
    const {selectionStart, selectionEnd} = this.textarea;
    this._replace(selectionStart, selectionEnd, String(text));
    return this;
  }

  /**
   * Indent the selected lines one level, or outdent them.
   *
   * With a caret and no multi-line selection, indenting inserts spaces (or a
   * tab) up to the next indent stop instead.
   *
   * @param {boolean} [outdent=false] - Remove one level instead of adding one.
   * @returns {CodeEditor} - This editor.
   */
  indent(outdent = false) {
    const ta = this.textarea;
    const value = ta.value;
    const start = ta.selectionStart;
    const end = ta.selectionEnd;
    const multiLine = value.slice(start, end).includes('\n');
    const size = this._tabSize();

    if (!outdent && !multiLine) {
      const column = start - (value.lastIndexOf('\n', start - 1) + 1);
      const text = this.options.indentWithTabs ? '\t' : ' '.repeat(size - (column % size));
      this._replace(start, end, text);
      return this;
    }

    const block = this._lineBlock(start, end);
    const unit = this._indentUnit();
    const lines = value.slice(block.start, block.end).split('\n');
    let firstShift = 0;
    let totalShift = 0;
    const outdentPattern = new RegExp(`^(?:\\t| {1,${size}})`);

    const changed = lines.map((line, index) => {
      if (outdent) {
        const removed = (outdentPattern.exec(line) || [''])[0].length;
        if (index === 0) firstShift = -Math.min(removed, start - block.start);
        totalShift -= removed;
        return line.slice(removed);
      }
      if (!line.trim() && lines.length > 1) return line;
      if (index === 0) firstShift = unit.length;
      totalShift += unit.length;
      return unit + line;
    }).join('\n');

    if (changed === value.slice(block.start, block.end)) return this;

    const newStart = Math.max(block.start, start + firstShift);
    const newEnd = Math.max(newStart, end + totalShift);
    this._replace(block.start, block.end, changed, newStart, newEnd);
    return this;
  }

  /**
   * Comment or uncomment the selected lines.
   *
   * Uses the language's `lineComment` on every line when it has one, otherwise
   * wraps the lines in its `blockComment`. Does nothing for languages with
   * neither.
   *
   * @returns {CodeEditor} - This editor.
   */
  toggleComment() {
    const lang = CodeEditor.languages[this.language] || {};
    const ta = this.textarea;
    const value = ta.value;
    const block = this._lineBlock(ta.selectionStart, ta.selectionEnd);
    const original = value.slice(block.start, block.end);
    let changed;

    if (lang.lineComment) {
      const marker = lang.lineComment;
      const lines = original.split('\n');
      const filled = lines.filter(line => line.trim());
      if (!filled.length) return this;
      const commented = filled.every(line => line.trimStart().startsWith(marker));
      if (commented) {
        const pattern = new RegExp(`^(\\s*)${CodeEditor._escapeRegExp(marker)} ?`);
        changed = lines.map(line => line.replace(pattern, '$1')).join('\n');
      } else {
        const indent = Math.min(...filled.map(line => /^\s*/.exec(line)[0].length));
        changed = lines.map(line => (line.trim() ? `${line.slice(0, indent)}${marker} ${line.slice(indent)}` : line)).join('\n');
      }
    } else if (lang.blockComment) {
      const [open, close] = lang.blockComment;
      const indent = /^\s*/.exec(original)[0];
      const body = original.slice(indent.length);
      if (body.startsWith(open) && body.trimEnd().endsWith(close)) {
        let inner = body.slice(open.length).trimEnd();
        inner = inner.slice(0, inner.length - close.length);
        changed = indent + inner.replace(/^ /, '').replace(/ $/, '');
      } else {
        if (!body.trim()) return this;
        changed = `${indent}${open} ${body} ${close}`;
      }
    } else {
      return this;
    }

    if (changed !== original) {
      this._replace(block.start, block.end, changed, block.start, block.start + changed.length);
    }
    return this;
  }

  /**
   * Recolour the code and recount the lines from the textarea's value.
   *
   * Only needed after changing the textarea in a way the editor cannot see,
   * such as replacing it inside a shadow DOM; typing, `setValue()` and
   * assigning `textarea.value` already refresh it.
   *
   * @returns {CodeEditor} - This editor.
   */
  refresh() {
    this._renderedValue = null;
    this._metrics = null;
    this._extraWidth = 0;
    this._render();
    return this;
  }

  /**
   * Detach the editor and put the textarea back as it was.
   *
   * @returns {void}
   */
  destroy() {
    const ta = this.textarea;
    if (!ta || !CodeEditor._instances.has(ta)) return;

    if (this._frame) cancelAnimationFrame(this._frame);
    if (this._viewportFrame) cancelAnimationFrame(this._viewportFrame);
    this._listeners.forEach(([target, type, handler, options]) => target.removeEventListener(type, handler, options));
    this._listeners = [];

    if (this._ownValueDescriptor) {
      Object.defineProperty(ta, 'value', this._ownValueDescriptor);
    } else {
      delete ta.value;
    }

    if (this.root.parentNode) {
      this.root.parentNode.insertBefore(ta, this.root);
      this.root.remove();
    }

    ta.classList.remove('code-editor-input');
    Object.entries(this._savedAttributes).forEach(([name, saved]) => {
      if (saved === null) ta.removeAttribute(name);
      else ta.setAttribute(name, saved);
    });

    CodeEditor._instances.delete(ta);
  }

  /**
   * Read options a textarea declares through `data-*` attributes.
   *
   * @param {HTMLTextAreaElement} element - The textarea.
   * @returns {Object} - Options found; absent attributes are left out.
   * @private
   */
  static _optionsFromDataset(element) {
    const data = element.dataset;
    const options = {};
    const bool = value => value === 'true' || value === '1' || value === '';
    if (data.language) options.language = data.language;
    if (data.lineNumbers !== undefined) options.lineNumbers = bool(data.lineNumbers);
    if (data.tabSize && !isNaN(parseInt(data.tabSize, 10))) options.tabSize = parseInt(data.tabSize, 10);
    if (data.indentWithTabs !== undefined) options.indentWithTabs = bool(data.indentWithTabs);
    if (data.autoCloseBrackets !== undefined) options.autoCloseBrackets = bool(data.autoCloseBrackets);
    if (data.autoIndent !== undefined) options.autoIndent = bool(data.autoIndent);
    if (data.minLines && !isNaN(parseInt(data.minLines, 10))) options.minLines = parseInt(data.minLines, 10);
    if (data.height) options.height = data.height;
    if (data.maxHeight) options.maxHeight = data.maxHeight;
    if (data.theme) options.theme = data.theme;
    return options;
  }

  /**
   * Build the editor around the textarea, moving the textarea inside it.
   *
   * @returns {void}
   * @private
   */
  _build() {
    const ta = this.textarea;
    const make = (tag, className) => {
      const node = document.createElement(tag);
      node.className = className;
      return node;
    };

    const root = make('div', 'code-editor');
    const scroller = make('div', 'code-editor-scroller');
    const gutter = make('div', 'code-editor-gutter');
    const gutterActive = make('div', 'code-editor-gutter-active');
    const gutterNumbers = make('div', 'code-editor-gutter-numbers');
    const gutterLines = make('div', 'code-editor-gutter-lines');
    const body = make('div', 'code-editor-body');
    const activeLine = make('div', 'code-editor-active-line');
    const pre = make('pre', 'code-editor-highlight');
    const code = document.createElement('code');

    gutter.setAttribute('aria-hidden', 'true');
    activeLine.setAttribute('aria-hidden', 'true');
    pre.setAttribute('aria-hidden', 'true');
    pre.appendChild(code);
    gutterNumbers.appendChild(gutterLines);
    gutter.append(gutterActive, gutterNumbers);

    this._savedAttributes = {};
    const setAttribute = (name, value) => {
      this._savedAttributes[name] = ta.getAttribute(name);
      ta.setAttribute(name, value);
    };
    setAttribute('wrap', 'off');
    setAttribute('spellcheck', 'false');
    setAttribute('autocomplete', 'off');
    setAttribute('autocorrect', 'off');
    setAttribute('autocapitalize', 'off');
    setAttribute('translate', 'no');
    // Without data-element="code" the textarea is ours alone: keep ElementManager's
    // plain-textarea handling (auto-resize, its own value hook) off it.
    if (!ta.dataset.element && ta.dataset.enhance === undefined) {
      setAttribute('data-enhance', 'false');
    }

    if (ta.parentNode) ta.parentNode.insertBefore(root, ta);
    ta.classList.add('code-editor-input');
    body.append(activeLine, pre, ta);
    scroller.append(gutter, body);
    root.appendChild(scroller);

    root.dataset.language = this.language;
    if (this.options.theme) root.dataset.theme = this.options.theme;
    if (!this.options.lineNumbers) root.classList.add('no-line-numbers');

    const toLength = value => (typeof value === 'number' || /^\d+(\.\d+)?$/.test(String(value)) ? `${value}px` : String(value));
    root.style.setProperty('--code-editor-min-lines', String(Math.max(1, parseInt(this.options.minLines, 10) || 1)));
    root.style.setProperty('--code-editor-tab-size', String(this._tabSize()));
    if (this.options.height) {
      scroller.style.height = toLength(this.options.height);
      root.classList.add('has-fixed-height');
    } else if (this.options.maxHeight) {
      scroller.style.maxHeight = toLength(this.options.maxHeight);
    }

    this.root = root;
    this.scroller = scroller;
    this.gutter = gutter;
    this.gutterActive = gutterActive;
    this.gutterNumbers = gutterNumbers;
    this.gutterLines = gutterLines;
    this.body = body;
    this.activeLine = activeLine;
    this.pre = pre;
    this.code = code;
  }

  /**
   * Make `textarea.value = ...` from any script refresh the colours.
   *
   * Wraps whatever `value` accessor the textarea has now (its prototype's, or
   * an own one another library installed), so that one keeps working.
   *
   * @returns {void}
   * @private
   */
  _hookValue() {
    const ta = this.textarea;
    const own = Object.getOwnPropertyDescriptor(ta, 'value');
    const base = own || Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value');
    const editor = this;
    this._ownValueDescriptor = own;

    Object.defineProperty(ta, 'value', {
      configurable: true,
      enumerable: true,
      get() {
        return base.get.call(this);
      },
      set(newValue) {
        base.set.call(this, newValue);
        editor._render();
      }
    });
  }

  /**
   * Bind the editor's event handlers.
   *
   * @returns {void}
   * @private
   */
  _bind() {
    this._listeners = [];
    const on = (target, type, handler, options) => {
      target.addEventListener(type, handler, options);
      this._listeners.push([target, type, handler, options]);
    };
    const ta = this.textarea;

    on(ta, 'input', () => {
      // Text arrived (typed, pasted, from an input method): back to editing
      this._tabEscapes = null;
      this._scheduleRender();
      this._notifyChange();
    });
    on(ta, 'keydown', event => this._onKeydown(event));
    on(ta, 'focus', () => {
      this._tabEscapes = null;
      this.root.classList.add('is-focused');
      this._updateActiveLine();
    });
    on(ta, 'blur', () => {
      this._tabEscapes = null;
      this.root.classList.remove('is-focused');
    });
    // A click in the code or the gutter is editing again, even where the caret already was
    on(this.root, 'pointerdown', () => {
      this._tabEscapes = null;
    });
    // The textarea is sized to its content and must never scroll itself,
    // or it would drift away from the coloured layer under it.
    on(ta, 'scroll', () => {
      if (ta.scrollTop || ta.scrollLeft) {
        ta.scrollTop = 0;
        ta.scrollLeft = 0;
      }
    });
    on(document, 'selectionchange', () => {
      if (document.activeElement === ta) {
        this._updateActiveLine();
        this._revealCaret();
      }
    });
    on(this.gutter, 'mousedown', event => this._onGutterMousedown(event));
    // Long files draw only the lines in view: redraw when the view moves
    const viewportChange = () => this._onViewportChange();
    on(this.scroller, 'scroll', viewportChange, {passive: true});
    on(document, 'scroll', viewportChange, {capture: true, passive: true});
    on(window, 'resize', viewportChange, {passive: true});
    if (ta.form) {
      // form.reset() restores the value without going through the value setter
      on(ta.form, 'reset', () => setTimeout(() => this._render(), 0));
    }
  }

  /**
   * Handle the editing keys.
   *
   * Escape followed by Tab (or Shift+Tab) moves focus out of the editor instead
   * of indenting, so keyboard users are never trapped inside it. Only the very
   * next Tab leaves: any other key, typing, a click, leaving the editor or a
   * caret moved by script in between returns to editing, and Tab indents again.
   * Modifier keys pressed alone (the Shift of Shift+Tab) do not count.
   *
   * @param {KeyboardEvent} event - The keydown event.
   * @returns {void}
   * @private
   */
  _onKeydown(event) {
    const ta = this.textarea;
    if (event.defaultPrevented || event.isComposing || event.keyCode === 229) return;
    if (ta.readOnly || ta.disabled) return;

    const mod = event.ctrlKey || event.metaKey;

    if (event.key === 'Escape') {
      this._tabEscapes = {start: ta.selectionStart, end: ta.selectionEnd};
      return;
    }

    if (CodeEditor.MODIFIER_KEYS.has(event.key)) return;

    if (event.key === 'Tab' && !mod && !event.altKey) {
      const escape = this._tabEscapes;
      this._tabEscapes = null;
      // The browser moves focus on; the caret must not have moved since Escape
      if (escape && escape.start === ta.selectionStart && escape.end === ta.selectionEnd) return;
      event.preventDefault();
      this.indent(event.shiftKey);
      return;
    }
    this._tabEscapes = null;

    if (mod && !event.altKey && !event.shiftKey && (event.key === '/' || event.code === 'Slash')) {
      event.preventDefault();
      this.toggleComment();
      return;
    }

    if (mod || event.altKey) return;

    if (event.key === 'Enter') {
      if (this.options.autoIndent) {
        event.preventDefault();
        this._newline();
      }
      return;
    }

    if (event.key === 'Backspace') {
      if (this._backspace()) event.preventDefault();
      return;
    }

    if (this.options.autoCloseBrackets && event.key.length === 1 && this._autoClose(event.key)) {
      event.preventDefault();
    }
  }

  /**
   * Insert a line break that keeps the current indent.
   *
   * After an opening bracket (or an HTML opening tag) the new line is indented
   * one level deeper, and when the matching closer sits right after the caret
   * it moves to a line of its own.
   *
   * @returns {void}
   * @private
   */
  _newline() {
    const ta = this.textarea;
    const value = ta.value;
    const start = ta.selectionStart;
    const end = ta.selectionEnd;
    const lineStart = value.lastIndexOf('\n', start - 1) + 1;
    const before = value.slice(lineStart, start);
    const indent = /^[ \t]*/.exec(before)[0];
    const lastChar = before.trimEnd().slice(-1);
    const after = value.slice(end);
    const unit = this._indentUnit();

    let opens = Object.prototype.hasOwnProperty.call(CodeEditor.PAIRS, lastChar) && '([{'.includes(lastChar);
    let closesNext = opens && after.trimStart().startsWith(CodeEditor.PAIRS[lastChar]) && /^[ \t]*[)\]}]/.test(after);

    const markup = this.language === 'html' || this.language === 'php';
    if (!opens && markup && lastChar === '>' && /<[A-Za-z][^<>]*[^/]>\s*$/.test(before) && !/<\/[^<>]*>\s*$/.test(before)) {
      opens = true;
      closesNext = /^[ \t]*<\//.test(after);
    }

    if (opens) {
      const inner = `\n${indent}${unit}`;
      if (closesNext) {
        this._replace(start, end, `${inner}\n${indent}`, start + inner.length);
      } else {
        this._replace(start, end, inner);
      }
      return;
    }

    this._replace(start, end, `\n${indent}`);
  }

  /**
   * Backspace that deletes an empty bracket pair or a whole indent step.
   *
   * @returns {boolean} - True when it handled the key.
   * @private
   */
  _backspace() {
    const ta = this.textarea;
    const start = ta.selectionStart;
    if (start !== ta.selectionEnd || start === 0) return false;
    const value = ta.value;
    const prev = value[start - 1];

    if (this.options.autoCloseBrackets && CodeEditor.PAIRS[prev] && CodeEditor.PAIRS[prev] === value[start]) {
      this._replace(start - 1, start + 1, '');
      return true;
    }

    if (!this.options.indentWithTabs) {
      const head = value.slice(value.lastIndexOf('\n', start - 1) + 1, start);
      if (head && /^ +$/.test(head)) {
        const count = ((head.length - 1) % this._tabSize()) + 1;
        if (count > 1) {
          this._replace(start - count, start, '');
          return true;
        }
      }
    }
    return false;
  }

  /**
   * Type a bracket or quote together with its closer, wrap a selection in a
   * pair, or step over a closer that is already there.
   *
   * @param {string} key - The character being typed.
   * @returns {boolean} - True when it handled the key.
   * @private
   */
  _autoClose(key) {
    if (this.language === 'text') return false;
    const ta = this.textarea;
    const value = ta.value;
    const start = ta.selectionStart;
    const end = ta.selectionEnd;
    const closer = CodeEditor.PAIRS[key];
    const isQuote = key === '"' || key === "'" || key === '`';

    if (start === end && (')]}'.includes(key) || isQuote) && value[start] === key && value[start - 1] !== '\\') {
      ta.setSelectionRange(start + 1, start + 1);
      return true;
    }

    if (!closer) return false;

    if (start !== end) {
      const selected = value.slice(start, end);
      if (isQuote && selected.includes('\n')) return false;
      this._replace(start, end, key + selected + closer, start + 1, end + 1);
      return true;
    }

    const next = value[start] || '';
    const prev = value[start - 1] || '';
    if (next && !/[\s)\]};,:>]/.test(next)) return false;
    if (isQuote && (/[\w\\]/.test(prev) || prev === key)) return false;

    this._replace(start, end, key + closer, start + 1);
    return true;
  }

  /**
   * Select a whole line when its number is clicked.
   *
   * @param {MouseEvent} event - The mousedown event on the gutter.
   * @returns {void}
   * @private
   */
  _onGutterMousedown(event) {
    if (event.button !== 0) return;
    const metrics = this._measure();
    const rect = this.gutterNumbers.getBoundingClientRect();
    const line = Math.floor((event.clientY - rect.top - metrics.padTop) / metrics.lineHeight);
    if (line < 0 || line >= this._lineCount) return;

    event.preventDefault();
    const value = this.textarea.value;
    let start = 0;
    for (let i = 0; i < line; i++) start = value.indexOf('\n', start) + 1;
    let end = value.indexOf('\n', start);
    end = end === -1 ? value.length : end + 1;
    this.textarea.focus();
    this.textarea.setSelectionRange(start, end);
  }

  /**
   * Replace a range of the value as if it had been typed.
   *
   * Falls back to `setRangeText` plus a synthetic `input` event where
   * `execCommand` is unavailable; that path cannot be undone with Ctrl+Z.
   *
   * @param {number} start - Range start.
   * @param {number} end - Range end.
   * @param {string} text - Replacement text.
   * @param {number} [selectionStart] - Caret or selection start afterwards; defaults to the end of `text`.
   * @param {number} [selectionEnd] - Selection end afterwards; defaults to `selectionStart`.
   * @returns {void}
   * @private
   */
  _replace(start, end, text, selectionStart, selectionEnd) {
    const ta = this.textarea;
    const caretStart = selectionStart ?? start + text.length;
    const caretEnd = selectionEnd ?? caretStart;
    const expected = ta.value.slice(0, start) + text + ta.value.slice(end);

    if (document.activeElement !== ta) ta.focus();
    ta.setSelectionRange(start, end);

    let done = false;
    try {
      done = text ? document.execCommand('insertText', false, text) : document.execCommand('delete', false);
    } catch (error) {
      done = false;
    }

    if (!done || ta.value !== expected) {
      ta.value = expected;
      ta.dispatchEvent(new InputEvent('input', {bubbles: true, inputType: 'insertText', data: text}));
    }

    ta.setSelectionRange(caretStart, caretEnd);
  }

  /**
   * Start and end of the whole lines a selection touches.
   *
   * A selection that ends right at the start of a line does not include that line.
   *
   * @param {number} start - Selection start.
   * @param {number} end - Selection end.
   * @returns {{start: number, end: number}} - Range covering those lines, without the final line break.
   * @private
   */
  _lineBlock(start, end) {
    const value = this.textarea.value;
    const blockStart = value.lastIndexOf('\n', start - 1) + 1;
    const last = end > start && value[end - 1] === '\n' ? end - 1 : end;
    let blockEnd = value.indexOf('\n', last);
    if (blockEnd === -1) blockEnd = value.length;
    return {start: blockStart, end: blockEnd};
  }

  /**
   * One indent level as text.
   *
   * @returns {string} - A tab, or `tabSize` spaces.
   * @private
   */
  _indentUnit() {
    return this.options.indentWithTabs ? '\t' : ' '.repeat(this._tabSize());
  }

  /**
   * Columns per indent level, clamped to 1–8.
   *
   * @returns {number} - Tab size.
   * @private
   */
  _tabSize() {
    const size = parseInt(this.options.tabSize, 10);
    return Math.min(8, Math.max(1, isNaN(size) ? 2 : size));
  }

  /**
   * Re-render on the next animation frame, coalescing a burst of input events.
   *
   * @returns {void}
   * @private
   */
  _scheduleRender() {
    if (this._frame) return;
    this._frame = requestAnimationFrame(() => {
      this._frame = null;
      this._render();
      this._revealCaret();
    });
  }

  /**
   * Bring the coloured copy and the line numbers up to date with the value.
   *
   * Only the lines in view (plus a margin) are drawn; the layers get their full
   * height and width from the line count and the widest line, so the browser
   * never has to lay out the whole file — that is what kept typing fast in
   * files of thousands of lines.
   *
   * @returns {void}
   * @private
   */
  _render() {
    if (!this.code) return;
    const value = this.textarea.value;
    if (value !== this._renderedValue || this.language !== this._renderedLanguage) {
      this._analyse(value);
      this._renderedValue = value;
      this._renderedLanguage = this.language;
      this._window = null;
    }
    this._renderWindow();
    this._updateActiveLine();
  }

  /**
   * Tokenize the value and record where every line starts and how wide the
   * widest one is.
   *
   * @param {string} value - The code.
   * @returns {void}
   * @private
   */
  _analyse(value) {
    let tokens;
    if (value.length > CodeEditor.MAX_HIGHLIGHT_LENGTH) {
      this._lexed = null;
      tokens = value ? [{type: null, text: value}] : [];
    } else {
      this._lexed = CodeEditor._lex(value, this.language, this._lexed);
      tokens = this._lexed.tokens;
    }
    const tokenStarts = new Array(tokens.length);
    let offset = 0;
    for (let i = 0; i < tokens.length; i++) {
      tokenStarts[i] = offset;
      offset += tokens[i].text.length;
    }

    const lineStarts = [0];
    const size = this._tabSize();
    let widest = 0;
    let column = 0;
    for (let i = 0; i < value.length; i++) {
      const code = value.charCodeAt(i);
      if (code === 10) {
        if (column > widest) widest = column;
        column = 0;
        lineStarts.push(i + 1);
      } else if (code === 9) {
        column += size - (column % size);
      } else {
        column += CodeEditor._columns(code);
      }
    }
    if (column > widest) widest = column;

    this._tokens = tokens;
    this._tokenStarts = tokenStarts;
    this._lineStarts = lineStarts;

    const lines = lineStarts.length;
    const height = `calc(${Math.max(lines, this._minLines())} * var(--code-editor-line-height) + 2 * var(--code-editor-pad-y))`;
    this.pre.style.height = height;
    this.gutterNumbers.style.height = height;
    this._widest = widest;
    this._applyWidth();

    if (lines !== this._lineCount) {
      this._lineCount = lines;
      this.root.style.setProperty('--code-editor-gutter-digits', String(Math.max(2, String(lines).length)));
    }
  }

  /**
   * Size the code layers to the widest line (plus room for the caret).
   *
   * @returns {void}
   * @private
   */
  _applyWidth() {
    const extra = this._extraWidth ? ` + ${this._extraWidth}px` : '';
    this.body.style.flexBasis = `calc(${this._widest || 0}ch + 2 * var(--code-editor-pad-x) + 4ch${extra})`;
  }

  /**
   * Draw the lines in view, unless the ones already drawn cover them.
   *
   * @returns {void}
   * @private
   */
  _renderWindow() {
    const lines = this._lineCount;
    const all = lines <= CodeEditor.DRAW_ALL_LINES;
    let first = 0;
    let last = lines;

    if (!all) {
      [first, last] = this._visibleLines();
      if (this._window && first >= this._window[0] && last <= this._window[1]) return;
      first = Math.max(0, first - CodeEditor.DRAW_MARGIN_LINES);
      last = Math.min(lines, last + CodeEditor.DRAW_MARGIN_LINES);
    } else if (this._window) {
      return;
    }

    this._window = [first, last];
    this.code.innerHTML = this._linesHtml(first, last);
    const shift = first ? `translateY(calc(${first} * var(--code-editor-line-height)))` : '';
    this.code.style.transform = shift;
    this.gutterLines.style.transform = shift;

    let numbers = String(first + 1);
    for (let i = first + 2; i <= last; i++) numbers += `\n${i}`;
    this.gutterLines.textContent = numbers;

    // Characters wider than one column (some fonts draw Thai or CJK wider) can
    // make a line longer than estimated: grow to fit what is actually drawn.
    const overflow = this.code.scrollWidth - this.code.clientWidth;
    if (overflow > 0) {
      this._extraWidth = (this._extraWidth || 0) + Math.ceil(overflow);
      this._applyWidth();
    }
  }

  /**
   * HTML of lines `first` up to (not including) `last`, clipped from the tokens.
   *
   * @param {number} first - First line index.
   * @param {number} last - Line index to stop before.
   * @returns {string} - HTML.
   * @private
   */
  _linesHtml(first, last) {
    const value = this._renderedValue ?? this.textarea.value;
    const starts = this._tokenStarts;
    const tokens = this._tokens;
    const from = this._lineStarts[first] ?? 0;
    const to = last < this._lineStarts.length ? this._lineStarts[last] - 1 : value.length;
    if (to <= from) return '';

    let low = 0;
    let high = starts.length - 1;
    while (low < high) {
      const mid = (low + high + 1) >> 1;
      if (starts[mid] <= from) low = mid;
      else high = mid - 1;
    }

    let html = '';
    for (let i = low; i < tokens.length && starts[i] < to; i++) {
      const start = Math.max(from, starts[i]);
      const end = Math.min(to, starts[i] + tokens[i].text.length);
      if (end <= start) continue;
      const text = CodeEditor.escapeHtml(value.slice(start, end));
      html += tokens[i].type ? `<span class="ce-${tokens[i].type}">${text}</span>` : text;
    }
    return html;
  }

  /**
   * Indexes of the first and last line inside the visible part of the editor.
   *
   * Takes both the editor's own scrolling and the page's into account. An
   * editor that is hidden or scrolled out of sight reports its first lines.
   *
   * @returns {[number, number]} - First line, line to stop before.
   * @private
   */
  _visibleLines() {
    const metrics = this._measure();
    const scrollerRect = this.scroller.getBoundingClientRect();
    const bodyRect = this.body.getBoundingClientRect();
    const viewport = window.innerHeight || document.documentElement.clientHeight;
    const top = Math.max(scrollerRect.top, 0) - bodyRect.top - metrics.padTop;
    const bottom = Math.min(scrollerRect.bottom, viewport) - bodyRect.top - metrics.padTop;
    const lines = this._lineCount;

    if (!scrollerRect.height || bottom <= top) return [0, Math.min(lines, 60)];
    const first = Math.min(lines, Math.max(0, Math.floor(top / metrics.lineHeight)));
    const last = Math.min(lines, Math.max(first, Math.ceil(bottom / metrics.lineHeight)));
    return [first, last];
  }

  /**
   * Redraw after the editor or the page scrolled or resized.
   *
   * @returns {void}
   * @private
   */
  _onViewportChange() {
    if (this._lineCount <= CodeEditor.DRAW_ALL_LINES || this._viewportFrame) return;
    this._viewportFrame = requestAnimationFrame(() => {
      this._viewportFrame = null;
      this._renderWindow();
    });
  }

  /**
   * Minimum visible lines from the options.
   *
   * @returns {number} - At least 1.
   * @private
   */
  _minLines() {
    return Math.max(1, parseInt(this.options.minLines, 10) || 1);
  }

  /**
   * Columns a character takes: 0 for combining marks (Thai vowels and tone
   * marks above or below a letter), 2 for wide CJK characters, 1 otherwise.
   *
   * @param {number} code - UTF-16 code unit.
   * @returns {number} - Columns.
   * @private
   */
  static _columns(code) {
    if (code < 0x0300) return 1;
    if ((code >= 0x0300 && code <= 0x036F) || code === 0x0E31 || (code >= 0x0E34 && code <= 0x0E3A) ||
      (code >= 0x0E47 && code <= 0x0E4E) || (code >= 0xDC00 && code <= 0xDFFF) || code === 0x200B ||
      (code >= 0xFE00 && code <= 0xFE0F)) {
      return 0;
    }
    if ((code >= 0x1100 && code <= 0x115F) || (code >= 0x2E80 && code <= 0xA4CF) ||
      (code >= 0xAC00 && code <= 0xD7A3) || (code >= 0xF900 && code <= 0xFAFF) ||
      (code >= 0xFE30 && code <= 0xFE4F) || (code >= 0xFF00 && code <= 0xFF60) ||
      (code >= 0xFFE0 && code <= 0xFFE6) || (code >= 0xD800 && code <= 0xDBFF)) {
      return 2;
    }
    return 1;
  }

  /**
   * Tell listeners the code changed.
   *
   * @returns {void}
   * @private
   */
  _notifyChange() {
    if (typeof this.options.onChange === 'function') {
      try {
        this.options.onChange.call(this, this.textarea.value, this);
      } catch (error) {
        console.error('CodeEditor onChange failed:', error);
      }
    }
  }

  /**
   * Line height, character width and padding, measured once and cached.
   *
   * @returns {{lineHeight: number, charWidth: number, padTop: number, padLeft: number}} - Metrics in px.
   * @private
   */
  _measure() {
    if (this._metrics) return this._metrics;
    const style = getComputedStyle(this.pre);
    const probe = document.createElement('span');
    probe.textContent = 'x'.repeat(40);
    probe.style.cssText = 'position:absolute;visibility:hidden;white-space:pre;';
    this.pre.appendChild(probe);
    const charWidth = probe.getBoundingClientRect().width / 40;
    probe.remove();
    const metrics = {
      lineHeight: parseFloat(style.lineHeight) || 20,
      charWidth: charWidth || 8,
      padTop: parseFloat(style.paddingTop) || 0,
      padLeft: parseFloat(style.paddingLeft) || 0
    };
    // A hidden editor (closed tab or modal) measures zero width: try again next time
    if (charWidth) this._metrics = metrics;
    return metrics;
  }

  /**
   * Line and column of the caret.
   *
   * @returns {{line: number, column: number}} - Zero-based line, column with tabs expanded.
   * @private
   */
  _caretPosition() {
    const ta = this.textarea;
    const offset = ta.selectionDirection === 'backward' ? ta.selectionStart : ta.selectionEnd;
    const value = ta.value;
    let line = 0;
    for (let i = value.indexOf('\n'); i !== -1 && i < offset; i = value.indexOf('\n', i + 1)) line++;
    const lineText = value.slice(value.lastIndexOf('\n', offset - 1) + 1, offset);
    const size = this._tabSize();
    let column = 0;
    for (const ch of lineText) column = ch === '\t' ? column + size - (column % size) : column + 1;
    return {line, column};
  }

  /**
   * Move the current-line highlight to the caret's line.
   *
   * @returns {void}
   * @private
   */
  _updateActiveLine() {
    if (document.activeElement !== this.textarea) return;
    const metrics = this._measure();
    const top = metrics.padTop + this._caretPosition().line * metrics.lineHeight;
    this.activeLine.style.transform = `translateY(${top}px)`;
    this.gutterActive.style.transform = `translateY(${top}px)`;
  }

  /**
   * Scroll the editor so the caret stays in view while typing or moving it.
   *
   * @returns {void}
   * @private
   */
  _revealCaret() {
    const scroller = this.scroller;
    const canScrollY = scroller.scrollHeight > scroller.clientHeight + 1;
    const canScrollX = scroller.scrollWidth > scroller.clientWidth + 1;
    if (!canScrollY && !canScrollX) return;

    const metrics = this._measure();
    const {line, column} = this._caretPosition();

    if (canScrollY) {
      const top = metrics.padTop + line * metrics.lineHeight;
      const bottom = top + metrics.lineHeight;
      if (top < scroller.scrollTop) {
        scroller.scrollTop = Math.max(0, top - metrics.padTop);
      } else if (bottom > scroller.scrollTop + scroller.clientHeight) {
        scroller.scrollTop = bottom - scroller.clientHeight + metrics.padTop;
      }
    }

    if (canScrollX) {
      const gutterWidth = this.options.lineNumbers ? this.gutter.offsetWidth : 0;
      const x = gutterWidth + metrics.padLeft + column * metrics.charWidth;
      const margin = metrics.charWidth * 4;
      if (x - margin < scroller.scrollLeft + gutterWidth) {
        scroller.scrollLeft = Math.max(0, x - gutterWidth - margin);
      } else if (x + margin > scroller.scrollLeft + scroller.clientWidth) {
        scroller.scrollLeft = x + margin - scroller.clientWidth;
      }
    }
  }

  /**
   * Escape text for use inside a RegExp.
   *
   * @param {string} text - Text to escape.
   * @returns {string} - Escaped text.
   * @private
   */
  static _escapeRegExp(text) {
    return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }
}

/* ------------------------------------------------------------------------ *
 * Languages
 * ------------------------------------------------------------------------ */
(() => {
  const words = list => new Set(list.split(/\s+/).filter(Boolean));
  const followedByParen = /\s*\(/y;
  const isCall = (text, end) => {
    followedByParen.lastIndex = end;
    return followedByParen.test(text);
  };
  const whitespace = {type: null, match: /\s+/y, skip: true};

  // JavaScript -------------------------------------------------------------
  const jsKeywords = words(`async await break case catch class const continue debugger default delete do else export
    extends finally for from function get if import in instanceof let new of return set static super switch this throw
    try typeof var void while with yield as`);
  const jsConstants = words('true false null undefined NaN Infinity');
  const jsBuiltins = words(`Array ArrayBuffer BigInt Boolean console Date document Error EvalError globalThis Intl JSON Map
    Math Number Object Promise Proxy RangeError Reflect RegExp Set String Symbol TypeError URL URLSearchParams WeakMap
    WeakSet window`);
  // After these, a "/" starts a regular expression rather than a division
  const regexAfterKeyword = words('return typeof instanceof in of new delete void throw case do else yield await');
  const regexAllowed = prev => !prev ||
    prev.type === 'operator' ||
    (prev.type === 'punctuation' && !/[)\]}]$/.test(prev.text)) ||
    (prev.type === 'keyword' && regexAfterKeyword.has(prev.text));

  const jsWordType = (word, state, prev, text, end) => {
    if (prev && prev.text === '.' || prev && prev.text === '?.') return isCall(text, end) ? 'function' : 'property';
    if (jsKeywords.has(word)) return 'keyword';
    if (jsConstants.has(word)) return 'constant';
    if (isCall(text, end)) return 'function';
    if (jsBuiltins.has(word)) return 'builtin';
    return 'word';
  };

  /**
   * Skip a quoted string starting at `pos`, returning the index after it.
   */
  const skipQuoted = (text, pos) => {
    const quote = text[pos];
    let i = pos + 1;
    while (i < text.length) {
      const ch = text[i];
      if (ch === '\\') {
        i += 2;
        continue;
      }
      if (ch === quote) return i + 1;
      if (ch === '\n' && quote !== '`') return i;
      i++;
    }
    return text.length;
  };

  // Template literal with ${...} expressions tokenized as JavaScript
  const scanTemplate = (text, pos) => {
    if (text[pos] !== '`') return null;
    const tokens = [];
    let i = pos + 1;
    let segment = pos;
    while (i < text.length) {
      const ch = text[i];
      if (ch === '\\') {
        i += 2;
        continue;
      }
      if (ch === '`') {
        i++;
        tokens.push({type: 'string', text: text.slice(segment, i)});
        return {tokens, end: i, prev: {type: 'string', text: '`'}};
      }
      if (ch === '$' && text[i + 1] === '{') {
        tokens.push({type: 'string', text: text.slice(segment, i)});
        let depth = 1;
        let j = i + 2;
        while (j < text.length && depth > 0) {
          const c = text[j];
          if (c === '"' || c === "'" || c === '`') {
            j = skipQuoted(text, j);
            continue;
          }
          if (c === '{') depth++;
          else if (c === '}') depth--;
          if (depth > 0) j++;
        }
        tokens.push({type: 'punctuation', text: '${'});
        CodeEditor._scan(text.slice(i + 2, j), CodeEditor.languages.javascript, tokens);
        if (j < text.length) tokens.push({type: 'punctuation', text: '}'});
        i = Math.min(text.length, j + 1);
        segment = i;
        continue;
      }
      i++;
    }
    tokens.push({type: 'string', text: text.slice(segment)});
    return {tokens, end: text.length, prev: {type: 'string', text: '`'}};
  };

  CodeEditor.registerLanguage('javascript', {
    lineComment: '//',
    blockComment: ['/*', '*/'],
    rules: [
      whitespace,
      // Rules start on different characters, so their order only changes speed:
      // words, the commonest tokens, are tried first.
      {type: (w, s, p, t, e) => {
        const type = jsWordType(w, s, p, t, e);
        return type === 'word' ? null : type;
      }, match: /[A-Za-z_$\u00C0-\uFFFF][\w$\u00C0-\uFFFF]*/y},
      {type: 'comment', match: /\/\/[^\n]*/y, skip: true},
      {type: 'comment', match: /\/\*[\s\S]*?(?:\*\/|$)/y, skip: true},
      {scan: scanTemplate},
      {type: 'string', match: /"(?:[^"\\\n]|\\[\s\S])*"?|'(?:[^'\\\n]|\\[\s\S])*'?/y},
      {type: 'regex', when: regexAllowed, match: /\/(?![*/])(?:[^\\/[\n]|\\.|\[(?:[^\]\\\n]|\\.)*\])+\/[dgimsuyv]*/y},
      {type: 'number', match: /(?:0[xX][\da-fA-F_]+|0[bB][01_]+|0[oO][0-7_]+|(?:\d[\d_]*\.?[\d_]*|\.\d[\d_]*)(?:[eE][+-]?\d+)?)n?/y},
      {type: 'operator', match: /=>|\.\.\.|\?\.|[+\-*/%=&|^!~<>?:]+/y},
      {type: 'punctuation', match: /[{}()[\];,.]/y},
      {type: 'meta', match: /[@#][A-Za-z_$][\w$]*/y}
    ]
  }, ['js', 'mjs', 'cjs', 'jsx', 'ts', 'typescript']);

  // JSON -------------------------------------------------------------------
  CodeEditor.registerLanguage('json', {
    rules: [
      whitespace,
      {type: 'property', match: /"(?:[^"\\\n]|\\.)*"(?=\s*:)/y},
      {type: 'string', match: /"(?:[^"\\\n]|\\.)*"?/y},
      {type: 'number', match: /-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/y},
      {type: 'constant', match: /\b(?:true|false|null)\b/y},
      {type: 'punctuation', match: /[{}[\],:]/y},
      {type: 'comment', match: /\/\/[^\n]*|\/\*[\s\S]*?(?:\*\/|$)/y}
    ]
  });

  // CSS --------------------------------------------------------------------
  const cssValue = state => state.value;
  const cssSelector = state => !state.value;
  CodeEditor.registerLanguage('css', {
    blockComment: ['/*', '*/'],
    state: () => ({depth: 0, value: false, expectValue: false}),
    rules: [
      whitespace,
      {type: 'comment', match: /\/\*[\s\S]*?(?:\*\/|$)/y, skip: true},
      {type: 'string', match: /"(?:[^"\\\n]|\\.)*"?|'(?:[^'\\\n]|\\.)*'?/y},
      {type: 'punctuation', match: /\{/y, onMatch: (m, s) => {
        s.depth++;
        s.value = false;
        s.expectValue = false;
      }},
      {type: 'punctuation', match: /\}/y, onMatch: (m, s) => {
        s.depth = Math.max(0, s.depth - 1);
        s.value = false;
        s.expectValue = false;
      }},
      {type: 'punctuation', match: /;/y, onMatch: (m, s) => {
        s.value = false;
        s.expectValue = false;
      }},
      {type: 'keyword', match: /@[\w-]+/y},
      {
        type: 'property',
        when: (p, s) => s.depth > 0 && !s.value,
        match: /(?:--)?[A-Za-z_-][\w-]*(?=\s*:(?:[^{};"']|"[^"]*"|'[^']*')*(?:;|\}|$))/y,
        onMatch: (m, s) => {
          s.expectValue = true;
        }
      },
      {type: 'punctuation', when: (p, s) => s.expectValue, match: /:/y, onMatch: (m, s) => {
        s.value = true;
        s.expectValue = false;
      }},
      {type: 'keyword', when: (p, s) => cssValue(s), match: /!important\b/y},
      {type: 'number', when: (p, s) => cssValue(s), match: /#[\da-fA-F]{3,8}\b/y},
      {type: 'number', when: (p, s) => cssValue(s), match: /-?(?:\d+\.?\d*|\.\d+)(?:%|[A-Za-z]+)?/y},
      {type: 'function', match: /[A-Za-z_-][\w-]*(?=\()/y},
      {type: 'constant', when: (p, s) => cssValue(s), match: /[A-Za-z_-][\w-]*/y},
      {type: 'selector', when: (p, s) => cssSelector(s), match: /[.#][\w-]+/y},
      {type: 'builtin', when: (p, s) => cssSelector(s), match: /::?[\w-]+/y},
      {type: 'attr-name', when: (p, s) => cssSelector(s), match: /\[[^\]\n]*\]?/y},
      {type: 'tag', when: (p, s) => cssSelector(s), match: /[A-Za-z][\w-]*|\*/y},
      {type: 'operator', match: /[>+~=*/,-]/y},
      {type: 'punctuation', match: /[():,]/y}
    ]
  }, ['scss', 'less']);

  // PHP --------------------------------------------------------------------
  const phpKeywords = words(`abstract and array as break callable case catch class clone const continue declare default
    do echo else elseif empty enddeclare endfor endforeach endif endswitch endwhile enum extends final finally fn for
    foreach function global goto if implements include include_once instanceof insteadof interface isset list match
    namespace new or print private protected public readonly require require_once return static switch throw trait try
    unset use var while xor yield self parent`);
  const phpConstants = words('true false null TRUE FALSE NULL __CLASS__ __DIR__ __FILE__ __FUNCTION__ __LINE__ __METHOD__ __NAMESPACE__');
  CodeEditor.registerLanguage('php', {
    lineComment: '//',
    blockComment: ['/*', '*/'],
    rules: [
      whitespace,
      {type: (word, s, prev, text, end) => {
        if (prev && (prev.text === '->' || prev.text === '?->' || prev.text === '::')) {
          return isCall(text, end) ? 'function' : 'property';
        }
        if (phpKeywords.has(word.toLowerCase())) return 'keyword';
        if (phpConstants.has(word)) return 'constant';
        if (isCall(text, end)) return 'function';
        if (/^[A-Z]/.test(word)) return 'builtin';
        return null;
      }, match: /[A-Za-z_\u00C0-\uFFFF\\][\w\u00C0-\uFFFF\\]*/y},
      {type: 'meta', match: /<\?(?:php|=)?|\?>/y},
      {type: 'comment', match: /(?:\/\/|#(?!\[))[^\n]*/y, skip: true},
      {type: 'comment', match: /\/\*[\s\S]*?(?:\*\/|$)/y, skip: true},
      {type: 'variable', match: /\$[A-Za-z_\u00C0-\uFFFF][\w\u00C0-\uFFFF]*/y},
      {type: 'string', match: /"(?:[^"\\]|\\[\s\S])*"?|'(?:[^'\\]|\\[\s\S])*'?/y},
      {type: 'number', match: /(?:0[xX][\da-fA-F_]+|0[bB][01_]+|\d[\d_]*\.?[\d_]*(?:[eE][+-]?\d+)?)/y},
      {type: 'operator', match: /\?->|->|=>|::|[+\-*/%=&|^!~<>?:.@]+/y},
      {type: 'punctuation', match: /[{}()[\];,]/y},
      {type: 'meta', match: /#\[/y}
    ]
  });

  // SQL --------------------------------------------------------------------
  const sqlKeywords = words(`add all alter and any as asc auto_increment between by case check column constraint create
    cross database default delete desc distinct drop else end engine exists foreign from full group having if in index
    inner insert into is join key left like limit not null offset on or order outer primary references replace right
    select set table then truncate union unique update using values view when where with`);
  CodeEditor.registerLanguage('sql', {
    lineComment: '--',
    blockComment: ['/*', '*/'],
    rules: [
      whitespace,
      {type: 'comment', match: /(?:--|#)[^\n]*/y, skip: true},
      {type: 'comment', match: /\/\*[\s\S]*?(?:\*\/|$)/y, skip: true},
      {type: 'string', match: /'(?:[^'\\]|''|\\[\s\S])*'?|"(?:[^"\\]|""|\\[\s\S])*"?/y},
      {type: 'property', match: /`[^`\n]*`?/y},
      {type: 'variable', match: /[:@][A-Za-z_]\w*|\?/y},
      {type: 'number', match: /\d+(?:\.\d+)?/y},
      {type: (word, s, p, text, end) => {
        if (sqlKeywords.has(word.toLowerCase())) return 'keyword';
        if (isCall(text, end)) return 'function';
        if (/^(?:true|false|null)$/i.test(word)) return 'constant';
        return null;
      }, match: /[A-Za-z_][\w$]*/y},
      {type: 'operator', match: /[<>=!]=?|[+\-*/%|&]/y},
      {type: 'punctuation', match: /[(),;.]/y}
    ]
  }, ['mysql']);

  // HTML -------------------------------------------------------------------
  // A tag with its attributes; the body of <script> and <style> is coloured
  // as JavaScript and CSS.
  const scanTag = (text, pos) => {
    const open = /<\/?([A-Za-z][\w:-]*)/y;
    open.lastIndex = pos;
    const m = open.exec(text);
    if (!m) return null;
    const tokens = [{type: 'tag', text: m[0]}];
    const name = m[1].toLowerCase();
    const closing = m[0][1] === '/';
    let i = pos + m[0].length;
    const attr = /\s+|[^\s"'>/=]+|=|"[^"]*"?|'[^']*'?|\/?>|\//y;
    let expectValue = false;

    while (i < text.length) {
      attr.lastIndex = i;
      const a = attr.exec(text);
      if (!a) break;
      const part = a[0];
      i += part.length;
      if (/^\s+$/.test(part)) {
        tokens.push({type: null, text: part});
      } else if (part === '>' || part === '/>') {
        tokens.push({type: 'tag', text: part});
        if (!closing && part === '>' && (name === 'script' || name === 'style')) {
          const close = text.toLowerCase().indexOf(`</${name}`, i);
          const bodyEnd = close === -1 ? text.length : close;
          const inner = text.slice(i, bodyEnd);
          if (inner) CodeEditor._scan(inner, CodeEditor.languages[name === 'script' ? 'javascript' : 'css'], tokens);
          i = bodyEnd;
        }
        return {tokens, end: i, prev: {type: 'tag', text: part}};
      } else if (part === '=') {
        tokens.push({type: 'operator', text: part});
        expectValue = true;
        continue;
      } else if (part === '/') {
        tokens.push({type: 'tag', text: part});
      } else if (expectValue || part[0] === '"' || part[0] === "'") {
        tokens.push({type: 'attr-value', text: part});
      } else {
        tokens.push({type: 'attr-name', text: part});
      }
      expectValue = false;
    }
    return {tokens, end: i, prev: {type: 'tag', text: ''}};
  };

  const scanPhpBlock = (text, pos) => {
    if (!text.startsWith('<?', pos) || !CodeEditor.languages.php) return null;
    const close = text.indexOf('?>', pos + 2);
    const end = close === -1 ? text.length : close + 2;
    const tokens = [];
    CodeEditor._scan(text.slice(pos, end), CodeEditor.languages.php, tokens);
    return {tokens, end};
  };

  CodeEditor.registerLanguage('html', {
    blockComment: ['<!--', '-->'],
    rules: [
      {type: null, match: /[^<&]+/y, skip: true},
      {type: 'comment', match: /<!--[\s\S]*?(?:-->|$)/y},
      {type: 'meta', match: /<![^>]*>?/y},
      {scan: scanPhpBlock},
      {scan: scanTag},
      {type: 'entity', match: /&(?:#\d+|#[xX][\da-fA-F]+|[A-Za-z][\w]*);/y}
    ]
  }, ['htm', 'xml', 'svg', 'xhtml', 'vue']);

  // Plain text — no colours
  CodeEditor.registerLanguage('text', {rules: null}, ['plain', 'txt', 'plaintext']);
})();

window.CodeEditor = CodeEditor;
