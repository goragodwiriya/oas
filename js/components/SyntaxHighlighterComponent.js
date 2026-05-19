/**
* SyntaxHighlighterComponent - Component for Syntax Highlighting
* The usage format is consistent with Apicomponent for consistent use.
*
* feature:
* - Automatic language detection
* - Supports multiple languages ​​(HTML, CSS, Javascript, PHP, Bash)
* - Supports the theme (Light/Dark)
* - Automatic code formatting
* - Show line number
* - There is a copy button.
* - Code Folding
* - Supports multiple languages ​​(i18n)
*/
const SyntaxHighlighterComponent = {
  config: {
    autoDetect: true,       // Automatic language detection
    language: null,         // Language that needs highlights such as 'JavaScript', 'PHP', 'HTML'
    lineNumbers: true,      // Show the line number
    copyButton: true,       // Show the copy button
    highlightLines: true,   // Emphasize the selected line color
    autoIndent: true,       // Automatic code formatting
    themeName: 'light',     // The theme 'Light' or 'Dark' theme
    codeFolding: false,     // Code folding

    loadingText: 'Loading',
    errorText: 'Error',
    copyText: 'Code',
    copiedText: 'Copied!',

    languages: ['html', 'css', 'javascript', 'php', 'bash', 'json', 'xml', 'sql', 'python', 'ruby'],

    languagePatterns: {
      html: /^</,
      css: /^(\.|#|\*|@media|@keyframes|body|html)/,
      javascript: /^(?:import|export|class|function|const|let|var|if|for|while)/,
      php: /^(?:<\?|namespace|use)/,
      bash: /^(?:#!\s*\/bin\/|\$|sudo|apt|yum|npm|git)/,
      json: /^[\{\[]/,
      xml: /^<\?xml/,
      sql: /^(?:SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP)/i,
      python: /^(?:import|from|def|class|if|for|while|print)/,
      ruby: /^(?:require|class|module|def|if|unless|puts)/
    },

    indentation: {
      size: 2,
      useTabs: false,
      automaticIndent: true
    },

    tokenPatterns: {
      html: {
        tag: /<\/?[a-z0-9-]+|>/gi,
        attribute: /\s+([a-z0-9-]+)(?:=(?:["'](?:\\.|[^\\"])*["']|[^\s"'>]+))?/gi,
        string: /"[^"]*"|'[^']*'/g,
        comment: /<!--[\s\S]*?-->/g,
        entity: /&[a-z0-9#]+;/gi
      },
      css: {
        selector: /[.#][a-z0-9-_:]+|[a-z0-9-]+(?=\s*\{)/gi,
        property: /[a-z-]+(?=\s*:)/gi,
        value: /:\s*[^;]+/g,
        unit: /\d+(?:px|em|rem|vh|vw|%)/gi,
        color: /#[a-f0-9]{3,8}|rgba?\([^)]+\)/gi,
        comment: /\/\*[\s\S]*?\*\//g,
        punctuation: /[{};:]/g
      },
      javascript: {
        keyword: /\b(?:const|let|var|if|else|for|while|do|break|continue|switch|case|default|function|return|try|catch|finally|throw|class|extends|new|this|super|import|export|default|null|undefined|true|false|in|of|instanceof|typeof|void|delete|async|await|from|as|yield|static|get|set|constructor)\b/g,
        builtin: /\b(?:Array|Object|String|Number|Boolean|RegExp|Math|Date|JSON|Promise|Map|Set|WeakMap|WeakSet|Symbol|BigInt|Infinity|NaN|undefined|null|console|window|document|global|process)\b/g,
        string: /(['"`])(?:\\[\s\S]|(?!\1)[^\\])*\1/g,
        number: /-?\b(?:0[xX][\dA-Fa-f]+|0[bB][01]+|0[oO][0-7]+|\d*\.?\d+(?:[Ee][+-]?\d+)?)\b/g,
        comment: /\/\/.*$|\/\*[\s\S]*?\*\//gm,
        operator: /=>|[+\-*/%=<>!&|^~?:]+/g,
        punctuation: /[{}[\]();,.]/g,
        function: /\b[a-zA-Z_$][\w$]*(?=\s*\()/g,
        variable: /\b[a-zA-Z_$][\w$]*\b/g
      },
      php: {
        keyword: /\b(?:namespace|use|class|extends|implements|public|private|protected|static|function|return|if|else|elseif|foreach|for|while|do|switch|case|break|default|continue|try|catch|throw|finally|as|array|new|echo|print|require|include|require_once|include_once)\b/g,
        variable: /\$[a-z_]\w*/gi,
        string: /(['"])(?:\\[\s\S]|(?!\1)[^\\])*\1|<<<['"]?\w+['"]?[\s\S]+?\w+[;"']?/g,
        number: /-?\b\d+(?:\.\d+)?\b/g,
        operator: /[+\-*\/%=<>!&|^~?:]+|->|::/g,
        punctuation: /[{}[\]();,.]/g,
        comment: /\/\/.*$|#.*$|\/\*[\s\S]*?\*\//gm,
        phpTag: /<\?(?:php)?|\?>/g
      },
      bash: {
        command: /\b(?:apt|yum|npm|git|docker|systemctl|service|cd|ls|cp|mv|rm|mkdir|chmod|chown|ssh|curl|wget)\b/g,
        parameter: /--?[a-z-]+/g,
        string: /(['"`])(?:\\[\s\S]|(?!\1)[^\\])*\1/g,
        variable: /\$[a-zA-Z0-9_]+|\${[^}]+}/g,
        comment: /#.*/g,
        path: /(?:\/[a-zA-Z0-9_.-]+)+/g,
        operator: /[|>&;]/g
      },
      json: {
        string: /"(?:\\.|[^"\\])*"(?=\s*:)/g,
        value: /"(?:\\.|[^"\\])*"/g,
        number: /-?\b\d+(?:\.\d+)?\b/g,
        punctuation: /[{}\[\]:,]/g,
        boolean: /\b(?:true|false|null)\b/g
      }
    },

    // Event handlers
    onHighlighted: null,    // When Highlight is successful
    onCopied: null,         // When copying the code
    onError: null,          // When an error occurs
    onLineClick: null,      // When clicking on the line
    onFoldToggle: null,     // When opening/off the code folding

    debug: false
  },

  state: {
    instances: new Map(),
    initialized: false,
    observer: null
  },

  /**
   * Create a new Instance of SyntaxHighlightercomponent.
   */
  create(element, options = {}) {
    if (typeof element === 'string') {
      element = document.querySelector(element);
    }

    if (!element) {
      console.error('Element not found');
      return null;
    }

    const existingInstance = this.getInstance(element);
    if (existingInstance) {
      return existingInstance;
    }

    const instance = {
      id: 'syntax_' + Math.random().toString(36).substring(2, 11),
      element,
      options: {...this.config, ...this.extractOptionsFromElement(element), ...options},
      originalContent: null,
      language: null,
      highlighted: false,
      wrapper: null,
      tokens: [],
      lineCount: 0,
      foldedLines: new Set(),
      markedLines: new Set()
    };

    this.setup(instance);

    this.state.instances.set(instance.id, instance);
    element.dataset.syntaxComponentId = instance.id;

    element.syntaxInstance = instance;

    return instance;
  },

  /**
   * Instance setting
   */
  setup(instance) {
    try {
      if (instance.element.tagName === 'CODE') {
        instance.codeElement = instance.element;
        instance.preElement = instance.element.parentNode.tagName === 'PRE' ?
          instance.element.parentNode : null;

        if (!instance.preElement) {
          instance.preElement = document.createElement('pre');
          instance.element.parentNode.insertBefore(instance.preElement, instance.element);
          instance.preElement.appendChild(instance.element);
        }
      } else if (instance.element.tagName === 'PRE') {
        instance.preElement = instance.element;
        instance.codeElement = instance.element.querySelector('code');

        if (!instance.codeElement) {
          instance.codeElement = document.createElement('code');
          instance.codeElement.textContent = instance.element.textContent;
          instance.element.textContent = '';
          instance.element.appendChild(instance.codeElement);
        }
      } else {
        instance.preElement = document.createElement('pre');
        instance.codeElement = document.createElement('code');
        instance.codeElement.textContent = instance.element.textContent;
        instance.preElement.appendChild(instance.codeElement);
        instance.element.textContent = '';
        instance.element.appendChild(instance.preElement);
      }

      instance.originalContent = instance.codeElement.textContent;

      instance.element.classList.add('syntax-highlighter-component');

      instance.language = this.detectLanguage(instance);

      this.highlight(instance);

      instance.refresh = () => {
        this.refresh(instance);
      };

      instance.setCode = (code) => {
        this.setCode(instance, code);
      };

      instance.setLanguage = (language) => {
        this.setLanguage(instance, language);
      };

      instance.copyCode = () => {
        this.copyCode(instance);
      };

      instance.highlightLine = (lineNumber) => {
        this.highlightLine(instance, lineNumber);
      };

      instance.foldCode = (startLine, endLine) => {
        this.foldCode(instance, startLine, endLine);
      };

      instance.unfoldCode = (lineNumber) => {
        this.unfoldCode(instance, lineNumber);
      };

      this.dispatchEvent(instance, 'init', {
        instance
      });
    } catch (error) {
      console.error('SyntaxHighlighterComponent setup error:', error);
      instance.error = error.message;
      this.renderError(instance);
    }
  },

  /**
   * Check the language
   */
  detectLanguage(instance) {
    if (instance.options.language) {
      return instance.options.language;
    }

    const langClass = Array.from(instance.codeElement.classList)
      .find(cls => cls.startsWith('language-'));

    if (langClass) {
      const lang = langClass.replace('language-', '');
      if (instance.options.languages.includes(lang)) {
        return lang;
      }
    }

    const dataLang = instance.codeElement.dataset.language;
    if (dataLang && instance.options.languages.includes(dataLang)) {
      return dataLang;
    }

    if (instance.options.autoDetect) {
      const code = instance.originalContent.trim();

      for (const [lang, pattern] of Object.entries(instance.options.languagePatterns)) {
        if (pattern.test(code)) {
          return lang;
        }
      }
    }

    return 'plain';
  },

  /**
   * Do syntax highlighting
   */
  highlight(instance) {
    try {
      if (!instance.language) {
        throw new Error('Language not detected');
      }

      const processedCode = this.preprocessCode(instance.originalContent, instance.language, instance.options);

      instance.tokens = this.tokenize(processedCode, instance.language);

      instance.lineCount = processedCode.split('\n').length;

      const wrapper = this.createHighlightedWrapper(instance);

      instance.wrapper = wrapper;

      instance.preElement.style.display = 'none';
      instance.preElement.parentNode.insertBefore(wrapper, instance.preElement.nextSibling);

      instance.highlighted = true;

      this.dispatchEvent(instance, 'highlighted', {
        language: instance.language,
        code: processedCode
      });

      if (typeof instance.options.onHighlighted === 'function') {
        instance.options.onHighlighted.call(instance, {
          language: instance.language,
          code: processedCode
        });
      }

      this.applyTheme(instance);

    } catch (error) {
      console.error('SyntaxHighlighterComponent highlight error:', error);
      instance.error = error.message;
      this.renderError(instance);

      this.dispatchEvent(instance, 'error', {
        error: error.message
      });

      if (typeof instance.options.onError === 'function') {
        instance.options.onError.call(instance, error);
      }
    }
  },

  /**
   * Format the code before highlighting.
   */
  preprocessCode(code, language, options) {
    code = code.replace(/^\uFEFF/, '').trim();

    code = code.replace(/^[\r\n]+|[\r\n]+$/g, '');

    if (options.autoIndent) {
      code = this.autoIndentCode(code, language, options.indentation);
    }

    return code;
  },

  /**
   * Automatically format code
   */
  autoIndentCode(code, language, indentOptions) {
    const lines = code.split('\n');
    const indentSize = indentOptions.size || 2;
    const indentChar = indentOptions.useTabs ? '\t' : ' '.repeat(indentSize);

    let indentLevel = 0;
    let inComment = false;

    // HTML void elements that don't require closing tags
    const voidElements = [
      'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
      'link', 'meta', 'param', 'source', 'track', 'wbr',
      // Common self-closing elements (XHTML style)
      'command', 'keygen', 'menuitem'
    ];
    const voidElementsPattern = new RegExp(`^<(${voidElements.join('|')})(\\s|>|/>|$)`, 'i');

    const patterns = {
      html: {
        // Match opening tags, but we'll check for void elements separately
        indent: /<[^/!][^>]*>$/,
        outdent: /^<\//,
        ignore: /^(<!--|-->)/,
        // Self-closing tags with /> at the end
        selfClosing: /\/>$/
      },
      css: {
        indent: /{$/,
        outdent: /^}/
      },
      javascript: {
        indent: /{$|\($|\[$|=>$/,
        outdent: /^}|^\)|^\]/,
        ignore: /^(\/\/|\/\*|\*\/)/
      },
      php: {
        indent: /{$/,
        outdent: /^}/,
        ignore: /^(\/\/|#|\*)/
      },
      bash: {
        indent: /\\$/,
        ignore: /^#/
      },
      json: {
        indent: /[\{\[]$/,
        outdent: /^[\}\]]/
      }
    };

    const pattern = patterns[language] || {
      indent: /{$|\($|\[$|=>$/,
      outdent: /^}|^\)|^\]/
    };

    return lines.map((line, index) => {
      const trimmedLine = line.trim();

      if (!trimmedLine) return '';

      if (pattern.ignore && pattern.ignore.test(trimmedLine)) {
        return indentChar.repeat(indentLevel) + trimmedLine;
      }

      if (trimmedLine.includes('/*')) inComment = true;
      if (trimmedLine.includes('*/')) {
        inComment = false;
        return indentChar.repeat(indentLevel) + trimmedLine;
      }
      if (inComment) {
        return indentChar.repeat(indentLevel) + trimmedLine;
      }

      if (pattern.outdent && pattern.outdent.test(trimmedLine)) {
        indentLevel = Math.max(0, indentLevel - 1);
      }

      const indentedLine = indentChar.repeat(indentLevel) + trimmedLine;

      // Check if we should increase indent
      if (pattern.indent && pattern.indent.test(trimmedLine)) {
        // For HTML, skip void elements and self-closing tags
        if (language === 'html') {
          const isSelfClosing = pattern.selfClosing && pattern.selfClosing.test(trimmedLine);
          const isVoidElement = voidElementsPattern.test(trimmedLine);
          if (!isSelfClosing && !isVoidElement) {
            indentLevel++;
          }
        } else {
          indentLevel++;
        }
      }

      return indentedLine;
    }).join('\n');
  },

  /**
   * Split the code into tokens
   */
  tokenize(code, language) {
    const patterns = this.config.tokenPatterns[language];
    const tokens = [];

    // If no token patterns for this language, return each line as a single text token
    if (!patterns) {
      const lines = code.split('\n');
      return lines.map(line => [{
        type: 'text',
        content: Utils.string.escape(line)
      }]);
    }

    const lines = code.split('\n');
    const result = [];

    lines.forEach(line => {
      const lineTokens = this.tokenizeLine(line, patterns);
      result.push(lineTokens);
    });

    return result;
  },

  /**
   * Split lines into tokens
   */
  tokenizeLine(line, patterns) {
    const tokens = [];
    let remaining = line;

    while (remaining.length > 0) {
      let found = false;

      for (const [type, pattern] of Object.entries(patterns)) {
        if (pattern instanceof RegExp) {
          pattern.lastIndex = 0;

          const match = pattern.exec(remaining);
          if (match && match.index === 0) {
            tokens.push({
              type,
              content: Utils.string.escape(match[0])
            });

            remaining = remaining.substring(match[0].length);
            found = true;
            break;
          }
        }
      }

      if (!found) {
        tokens.push({
          type: 'text',
          content: Utils.string.escape(remaining.charAt(0))
        });
        remaining = remaining.substring(1);
      }
    }

    return tokens;
  },

  /**
   * Create a wrapper for displaying code.
   */
  createHighlightedWrapper(instance) {
    const wrapper = document.createElement('div');
    wrapper.className = `highlighted-code ${instance.options.themeName}`;
    wrapper.setAttribute('data-language', instance.language);

    const header = document.createElement('div');
    header.className = 'code-header';

    const langLabel = document.createElement('div');
    langLabel.className = 'language-label';
    langLabel.textContent = instance.language;
    header.appendChild(langLabel);

    if (instance.options.copyButton) {
      const copyBtn = document.createElement('button');
      copyBtn.className = 'copy-button';
      copyBtn.title = Now.translate('Copy code');
      copyBtn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16"><path d="M16 1H4C2.9 1 2 1.9 2 3v14h2V3h12V1zm3 4H8C6.9 5 6 5.9 6 7v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></svg> ${Now.translate('Copy code')}`;
      copyBtn.addEventListener('click', () => this.copyCode(instance));
      header.appendChild(copyBtn);
    }

    wrapper.appendChild(header);

    const content = document.createElement('div');
    content.className = 'code-content';

    if (instance.options.lineNumbers) {
      const lineNumbers = document.createElement('div');
      lineNumbers.className = 'line-numbers';

      for (let i = 0; i < instance.lineCount; i++) {
        const lineNumber = document.createElement('a');
        lineNumber.className = 'line-number';
        lineNumber.textContent = i + 1;
        lineNumber.setAttribute('data-line', i + 1);
        lineNumber.href = `#line-${i + 1}`;

        lineNumber.addEventListener('click', (e) => {
          this.handleLineClick(instance, i + 1, e);
        });

        lineNumbers.appendChild(lineNumber);
      }

      content.appendChild(lineNumbers);
    }

    const codeBody = document.createElement('div');
    codeBody.className = 'code-body';

    if (instance.tokens && instance.tokens.length > 0) {
      instance.tokens.forEach((lineTokens, lineIndex) => {
        // Defensive: ensure lineTokens is an array of token objects
        if (!Array.isArray(lineTokens)) {
          lineTokens = [{type: 'text', content: Utils.string.escape(String(lineTokens))}];
        }
        const lineElement = document.createElement('div');
        lineElement.className = 'line';
        lineElement.setAttribute('data-line', lineIndex + 1);

        lineElement.addEventListener('click', (e) => {
          if (e.target === lineElement) {
            this.handleLineClick(instance, lineIndex + 1, e);
          }
        });

        lineTokens.forEach(token => {
          const span = document.createElement('span');
          span.className = `token ${token.type}`;
          span.innerHTML = token.content;
          lineElement.appendChild(span);
        });

        codeBody.appendChild(lineElement);
      });
    } else {
      const lines = instance.originalContent.split('\n');
      lines.forEach((line, lineIndex) => {
        const lineElement = document.createElement('div');
        lineElement.className = 'line';
        lineElement.setAttribute('data-line', lineIndex + 1);
        lineElement.textContent = line;
        codeBody.appendChild(lineElement);
      });
    }

    content.appendChild(codeBody);
    wrapper.appendChild(content);

    if (instance.options.codeFolding) {
      this.setupCodeFolding(instance, codeBody);
    }

    return wrapper;
  },

  /**
   * Handle line clicks
   */
  handleLineClick(instance, lineNumber, event) {
    if (instance.options.highlightLines) {
      this.toggleLineHighlight(instance, lineNumber);
    }

    this.dispatchEvent(instance, 'lineClick', {
      lineNumber,
      event
    });

    if (typeof instance.options.onLineClick === 'function') {
      instance.options.onLineClick.call(instance, lineNumber, event);
    }
  },

  /**
   * Toggle line highlighting
   */
  toggleLineHighlight(instance, lineNumber) {
    const lineElements = instance.wrapper.querySelectorAll(`.line[data-line="${lineNumber}"], .line-number[data-line="${lineNumber}"]`);

    if (instance.markedLines.has(lineNumber)) {
      lineElements.forEach(el => el.classList.remove('highlighted'));
      instance.markedLines.delete(lineNumber);
    } else {
      lineElements.forEach(el => el.classList.add('highlighted'));
      instance.markedLines.add(lineNumber);
    }
  },

  /**
   * Highlight the specified line.
   */
  highlightLine(instance, lineNumber) {
    if (typeof instance === 'string') {
      instance = this.state.instances.get(instance);
    } else if (instance instanceof HTMLElement) {
      instance = this.getInstance(instance);
    }

    if (!instance) return;

    const lineElements = instance.wrapper.querySelectorAll(`.line[data-line="${lineNumber}"], .line-number[data-line="${lineNumber}"]`);

    lineElements.forEach(el => el.classList.add('highlighted'));
    instance.markedLines.add(lineNumber);
  },

  /**
   * Set up code folding
   */
  setupCodeFolding(instance, codeBody) {
    const lines = codeBody.querySelectorAll('.line');
    lines.forEach((line, index) => {
      const content = line.textContent;
      if (content.includes('{') || content.includes('}') || content.includes('function') || content.includes('class')) {
        const foldMarker = document.createElement('span');
        foldMarker.className = 'fold-marker';
        foldMarker.textContent = '+';
        foldMarker.setAttribute('title', 'Fold code block');

        foldMarker.addEventListener('click', (e) => {
          e.stopPropagation();

          const startLine = index + 1;
          let endLine = this.findMatchingBracket(instance, content, startLine);

          if (endLine > startLine) {
            this.foldCode(instance, startLine, endLine);
            foldMarker.textContent = '-';
            foldMarker.setAttribute('title', 'Unfold code block');
          }
        });

        line.insertBefore(foldMarker, line.firstChild);
      }
    });
  },

  /**
   * Find the matching closing parenthesis.
   */
  findMatchingBracket(instance, content, startLine) {
    const lines = instance.wrapper.querySelectorAll('.line');
    let bracketCount = 0;

    for (let i = 0; i < content.length; i++) {
      if (content[i] === '{') bracketCount++;
    }

    for (let i = startLine; i < lines.length; i++) {
      const lineContent = lines[i].textContent;

      for (let j = 0; j < lineContent.length; j++) {
        if (lineContent[j] === '{') bracketCount++;
        if (lineContent[j] === '}') {
          bracketCount--;
          if (bracketCount === 0) {
            return i + 1;
          }
        }
      }
    }

    return startLine;
  },

  /**
   * fold code
   */
  foldCode(instance, startLine, endLine) {
    if (typeof instance === 'string') {
      instance = this.state.instances.get(instance);
    } else if (instance instanceof HTMLElement) {
      instance = this.getInstance(instance);
    }

    if (!instance) return;

    for (let i = startLine; i < endLine; i++) {
      const lineElements = instance.wrapper.querySelectorAll(`.line[data-line="${i}"], .line-number[data-line="${i}"]`);
      lineElements.forEach(el => el.classList.add('folded'));
      instance.foldedLines.add(i);
    }

    const lineBeforeFold = instance.wrapper.querySelector(`.line[data-line="${startLine - 1}"]`);
    if (lineBeforeFold) {
      const foldIndicator = document.createElement('div');
      foldIndicator.className = 'fold-indicator';
      foldIndicator.textContent = `... ${endLine - startLine} lines folded ...`;
      foldIndicator.setAttribute('data-fold-start', startLine);
      foldIndicator.setAttribute('data-fold-end', endLine);

      foldIndicator.addEventListener('click', () => {
        this.unfoldCode(instance, startLine);
      });

      lineBeforeFold.after(foldIndicator);
    }

    this.dispatchEvent(instance, 'foldToggle', {
      startLine,
      endLine,
      folded: true
    });

    if (typeof instance.options.onFoldToggle === 'function') {
      instance.options.onFoldToggle.call(instance, startLine, endLine, true);
    }
  },

  /**
   * Unfold code
   */
  unfoldCode(instance, startLine) {
    if (typeof instance === 'string') {
      instance = this.state.instances.get(instance);
    } else if (instance instanceof HTMLElement) {
      instance = this.getInstance(instance);
    }

    if (!instance) return;

    const foldIndicator = instance.wrapper.querySelector(`.fold-indicator[data-fold-start="${startLine}"]`);
    if (!foldIndicator) return;

    const endLine = parseInt(foldIndicator.getAttribute('data-fold-end'));

    for (let i = startLine; i < endLine; i++) {
      const lineElements = instance.wrapper.querySelectorAll(`.line[data-line="${i}"], .line-number[data-line="${i}"]`);
      lineElements.forEach(el => el.classList.remove('folded'));
      instance.foldedLines.delete(i);
    }

    foldIndicator.remove();

    const foldMarker = instance.wrapper.querySelector(`.line[data-line="${startLine - 1}"] .fold-marker`);
    if (foldMarker) {
      foldMarker.textContent = '+';
      foldMarker.setAttribute('title', 'Fold code block');
    }

    this.dispatchEvent(instance, 'foldToggle', {
      startLine,
      endLine,
      folded: false
    });

    if (typeof instance.options.onFoldToggle === 'function') {
      instance.options.onFoldToggle.call(instance, startLine, endLine, false);
    }
  },

  /**
   * Apply theme
   */
  applyTheme(instance) {
    if (!instance.options.themeName) return;

    if (instance.wrapper) {
      instance.wrapper.className = `highlighted-code ${instance.options.themeName}`;
    }
  },

  /**
   * show error
   */
  renderError(instance) {
    const errorDiv = document.createElement('div');
    errorDiv.className = 'syntax-error';
    errorDiv.textContent = Now.translate('Error') + ': ' + instance.error;
    errorDiv.style.cssText = 'color: #e74c3c; background-color: #fceae9; padding: 10px; border: 1px solid #e74c3c; border-radius: 4px; margin: 10px 0;';

    if (instance.wrapper) {
      instance.wrapper.parentNode.replaceChild(errorDiv, instance.wrapper);
      instance.wrapper = errorDiv;
    } else {
      instance.preElement.style.display = 'none';
      instance.preElement.parentNode.insertBefore(errorDiv, instance.preElement.nextSibling);
      instance.wrapper = errorDiv;
    }
  },

  /**
   * Copy the code to the clipboard.
   */
  copyCode(instance) {
    if (typeof instance === 'string') {
      instance = this.state.instances.get(instance);
    } else if (instance instanceof HTMLElement) {
      instance = this.getInstance(instance);
    }

    if (!instance) return;

    try {
      const text = instance.originalContent;

      navigator.clipboard.writeText(text).then(() => {
        const copyBtn = instance.wrapper.querySelector('.copy-button');
        if (copyBtn) {
          copyBtn.textContent = Now.translate('Copied!');

          setTimeout(() => {
            copyBtn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16"><path d="M16 1H4C2.9 1 2 1.9 2 3v14h2V3h12V1zm3 4H8C6.9 5 6 5.9 6 7v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></svg> ${Now.translate('Copy code')}`;
          }, 2000);
        }

        this.dispatchEvent(instance, 'copied', {code: text});

        if (typeof instance.options.onCopied === 'function') {
          instance.options.onCopied.call(instance, text);
        }
      }).catch(err => {
        console.error('Copy failed:', err);
      });
    } catch (error) {
      console.error('Copy error:', error);
    }
  },

  /**
   * Refresh the display
   */
  refresh(instance) {
    if (typeof instance === 'string') {
      instance = this.state.instances.get(instance);
    } else if (instance instanceof HTMLElement) {
      instance = this.getInstance(instance);
    }

    if (!instance) return;

    if (instance.wrapper) {
      instance.wrapper.remove();
      instance.wrapper = null;
    }

    instance.preElement.style.display = '';

    this.highlight(instance);
  },

  /**
   * Change code
   */
  setCode(instance, code) {
    if (typeof instance === 'string') {
      instance = this.state.instances.get(instance);
    } else if (instance instanceof HTMLElement) {
      instance = this.getInstance(instance);
    }

    if (!instance) return;

    instance.originalContent = code;
    instance.codeElement.textContent = code;

    this.refresh(instance);

    return instance;
  },

  /**
   * Change language
   */
  setLanguage(instance, language) {
    if (typeof instance === 'string') {
      instance = this.state.instances.get(instance);
    } else if (instance instanceof HTMLElement) {
      instance = this.getInstance(instance);
    }

    if (!instance) return;

    instance.language = language;
    instance.options.language = language;

    instance.codeElement.className = '';
    instance.codeElement.classList.add(`language-${language}`);

    this.refresh(instance);

    return instance;
  },

  /**
   * Change theme
   */
  setTheme(instance, themeName) {
    if (typeof instance === 'string') {
      instance = this.state.instances.get(instance);
    } else if (instance instanceof HTMLElement) {
      instance = this.getInstance(instance);
    }

    if (!instance) return;

    instance.options.themeName = themeName;

    if (instance.wrapper) {
      instance.wrapper.className = `highlighted-code ${themeName}`;
    }

    return instance;
  },

  /**
   * Extract options from data attributes
   */
  extractOptionsFromElement(element) {
    const options = {};
    const dataset = element.dataset;

    if (dataset.props) {
      try {
        const props = JSON.parse(dataset.props);
        Object.assign(options, props);
      } catch (e) {
        console.warn('Invalid JSON in data-props:', e);
      }
    }

    if (dataset.language) options.language = dataset.language;
    if (dataset.autoDetect !== undefined) options.autoDetect = dataset.autoDetect === 'true';
    if (dataset.lineNumbers !== undefined) options.lineNumbers = dataset.lineNumbers === 'true';
    if (dataset.copyButton !== undefined) options.copyButton = dataset.copyButton === 'true';
    if (dataset.highlightLines !== undefined) options.highlightLines = dataset.highlightLines === 'true';
    if (dataset.autoIndent !== undefined) options.autoIndent = dataset.autoIndent === 'true';
    if (dataset.themeName) options.themeName = dataset.themeName;
    if (dataset.codeFolding !== undefined) options.codeFolding = dataset.codeFolding === 'true';

    if (dataset.indentSize) options.indentation = {...(options.indentation || {}), size: parseInt(dataset.indentSize)};
    if (dataset.indentTabs !== undefined) options.indentation = {...(options.indentation || {}), useTabs: dataset.indentTabs === 'true'};

    return options;
  },

  /**
   * Find instance from element
   */
  getInstance(element) {
    if (typeof element === 'string') {
      element = document.querySelector(element);
    }

    if (!element) return null;

    if (element.syntaxInstance) {
      return element.syntaxInstance;
    }

    const id = element.dataset.syntaxComponentId;
    if (id && this.state.instances.has(id)) {
      return this.state.instances.get(id);
    }

    for (const instance of this.state.instances.values()) {
      if (instance.element === element) {
        return instance;
      }
    }

    return null;
  },

  /**
   * Send event
   */
  dispatchEvent(instance, eventName, detail = {}) {
    if (!instance.element) return;

    const event = new CustomEvent(`syntax:${eventName}`, {
      bubbles: true,
      cancelable: true,
      detail: {
        instance,
        ...detail
      }
    });

    instance.element.dispatchEvent(event);

    EventManager.emit(`syntax:${eventName}`, {
      instance,
      ...detail
    });
  },

  /**
   * Delete instance
   */
  destroy(instance) {
    if (typeof instance === 'string') {
      instance = this.state.instances.get(instance);
    } else if (instance instanceof HTMLElement) {
      instance = this.getInstance(instance);
    }

    if (!instance) return false;

    if (instance.wrapper) {
      instance.wrapper.remove();
    }

    instance.preElement.style.display = '';

    instance.highlighted = false;
    instance.wrapper = null;
    instance.tokens = [];
    instance.markedLines.clear();
    instance.foldedLines.clear();

    if (instance.element) {
      delete instance.element.syntaxInstance;
      delete instance.element.dataset.syntaxComponentId;

      instance.element.classList.remove('syntax-highlighter-component');
    }

    this.dispatchEvent(instance, 'destroy');

    if (instance.id) {
      this.state.instances.delete(instance.id);
    }

    return true;
  },

  /**
   * Set up observer
   */
  setupObserver() {
    if (this.state.observer) {
      this.state.observer.disconnect();
    }

    this.state.observer = new MutationObserver(mutations => {
      mutations.forEach(mutation => {
        mutation.addedNodes.forEach(node => {
          if (node.nodeType === 1) {
            if (node.tagName === 'CODE' && node.parentNode.tagName === 'PRE') {
              this.create(node);
            } else if (node.tagName === 'PRE') {
              const codeElement = node.querySelector('code');
              if (codeElement) {
                this.create(codeElement);
              }
            } else {
              const codeElements = node.querySelectorAll('pre > code');
              codeElements.forEach(code => this.create(code));

              const syntaxElements = node.querySelectorAll('[data-component="syntaxhighlighter"]');
              syntaxElements.forEach(el => this.create(el));
            }
          }
        });
      });
    });

    this.state.observer.observe(document.body, {
      childList: true,
      subtree: true
    });
  },

  /**
   * Initialize elements with data-component="syntaxhighlighter"
   */
  initElements() {
    document.querySelectorAll('[data-component="syntaxhighlighter"]').forEach(element => {
      this.create(element);
    });

    document.querySelectorAll('pre > code:not(.highlighted)').forEach(element => {
      const hasLanguageClass = Array.from(element.classList).some(cls => cls.startsWith('language-'));

      if (hasLanguageClass || this.config.autoDetect) {
        this.create(element);
      }
    });
  },

  /**
   * Default settings SyntaxHighlighterComponent
   */
  async init(options = {}) {
    this.config = {...this.config, ...options};

    EventManager.on('locale:changed', (event) => {
      this.updateUIText();
    });

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', () => this.initElements());
    } else {
      this.initElements();
    }

    this.setupObserver();

    this.state.initialized = true;
    return this;
  },

  /**
   * Updated UI according to current language.
   */
  updateUIText() {
    document.querySelectorAll('.highlighted-code .copy-button').forEach(button => {
      const copyText = Now.translate('Copy code');
      button.title = copyText;
      button.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16"><path d="M16 1H4C2.9 1 2 1.9 2 3v14h2V3h12V1zm3 4H8C6.9 5 6 5.9 6 7v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></svg> ${copyText}`;
    });
  },

  /**
   * Clean when not in use.
   */
  cleanup() {
    if (this.state.observer) {
      this.state.observer.disconnect();
      this.state.observer = null;
    }

    Now.off('locale:changed');

    this.state.instances.forEach(instance => {
      this.destroy(instance);
    });

    this.state.instances.clear();
    this.state.initialized = false;
  }
};

/**
* Register Component with ComponentManager
*/
if (window.ComponentManager) {
  const syntaxHighlighterComponentDefinition = {
    template: null,

    validElement(element) {
      return element.classList.contains('syntax-highlighter-component') ||
        element.dataset.component === 'syntaxhighlighter' ||
        (element.tagName === 'CODE' && element.parentNode.tagName === 'PRE');
    },

    setupElement(element, state) {
      const options = SyntaxHighlighterComponent.extractOptionsFromElement(element);
      const syntaxComponent = SyntaxHighlighterComponent.create(element, options);

      element._syntaxComponent = syntaxComponent;
      return element;
    },

    beforeDestroy() {
      if (this.element && this.element._syntaxComponent) {
        SyntaxHighlighterComponent.destroy(this.element._syntaxComponent);
        delete this.element._syntaxComponent;
      }
    }
  };

  ComponentManager.define('syntaxhighlighter', syntaxHighlighterComponentDefinition);
}

/**
* Register with Now.js framework
*/
if (window.Now?.registerManager) {
  Now.registerManager('syntax', SyntaxHighlighterComponent);
}

// Expose globally
window.SyntaxHighlighterComponent = SyntaxHighlighterComponent;
