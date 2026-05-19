class TextareaElementFactory extends ElementFactory {
  static create(def) {
    // Always render as a real <textarea>, not <input type="textarea">
    const cfg = Object.assign({}, def, {tagName: 'textarea', type: 'textarea'});
    return super.create(cfg);
  }

  static config = {
    autoResize: true,            // Activate automatic adjustment
    minRows: 2,                  // Minimum number of rows
    maxRows: 10,                 // Number of rows
    charCount: false,             // Show the number of characters
    charCountClass: 'char-count', // Class for letters
    charCountFormat: '{used}/{max} characters', // Display format
    wordCount: false,            // Show the number of words (closed by default)
    wordCountClass: 'word-count', // Class for words
    wordCountFormat: '{used}/{max} words', // Display format
    enableTabIndent: false,       // Allow to use Tab for Indent.
    tabToSpaces: 4,              // Number of gaps instead of Tab
    debounceDelay: 300,          // Delay Debound (MS)
    enterBehavior: 'newline',    // The behavior of Enter ('Newline', 'Prevent', 'Callback')
    enterCallback: null         // Callback when pressing Enter (if using callback)
  };

  static propertyHandlers = {
    rows: {
      get(element) {
        return element.rows || null;
      },
      set(instance, newValue) {
        if (typeof newValue === 'number' && newValue > 0) {
          instance.element.rows = newValue;
        }
      }
    },
    cols: {
      get(element) {
        return element.cols || null;
      },
      set(instance, newValue) {
        if (typeof newValue === 'number' && newValue > 0) {
          instance.element.cols = newValue;
        }
      }
    }
  };

  static extractCustomConfig(element, def, dataset) {
    return {
      autoResize: dataset.autoResize !== undefined ? dataset.autoResize === 'true' : def.autoResize,
      minRows: this.parseNumeric('minRows', element, def, dataset) || def.minRows,
      maxRows: this.parseNumeric('maxRows', element, def, dataset) || def.maxRows,
      charCount: dataset.charCount !== undefined ? dataset.charCount === 'true' : def.charCount,
      wordCount: dataset.wordCount !== undefined ? dataset.wordCount === 'true' : def.wordCount,
      enableTabIndent: dataset.enableTabIndent !== undefined ? dataset.enableTabIndent === 'true' : def.enableTabIndent,
      tabToSpaces: this.parseNumeric('tabToSpaces', element, def, dataset) || def.tabToSpaces,
      enterBehavior: dataset.enterBehavior || def.enterBehavior
    };
  }

  static setupElement(instance) {
    const {element, config} = instance;

    config.minRows = Math.max(1, typeof config.minRows === 'number' ? config.minRows : this.config.minRows);
    config.maxRows = Math.max(config.minRows, typeof config.maxRows === 'number' ? config.maxRows : this.config.maxRows);
    config.debounceDelay = Math.max(0, typeof config.debounceDelay === 'number' ? config.debounceDelay : this.config.debounceDelay);
    config.tabToSpaces = Math.max(0, typeof config.tabToSpaces === 'number' ? config.tabToSpaces : this.config.tabToSpaces);
    if (!['newline', 'prevent', 'callback'].includes(config.enterBehavior)) {
      config.enterBehavior = this.config.enterBehavior;
    }
    if (config.enterBehavior === 'callback' && typeof config.enterCallback !== 'function') {
      console.warn('enterCallback must be a function when enterBehavior is "callback". Falling back to "newline".');
      config.enterBehavior = 'newline';
    }

    element.setAttribute('aria-multiline', 'true');

    instance.countWords = function(text) {
      return text.trim() ? text.trim().split(/\s+/).length : 0;
    };

    instance.validateSpecific = function(value) {
      if (this.config.minWords) {
        const wordCount = this.countWords(value);
        if (wordCount < this.config.minWords) {
          return Now.translate('Must be at least {min} words', {min: this.config.minWords});
        }
      }
      if (this.config.maxWords) {
        const wordCount = this.countWords(value);
        if (wordCount > this.config.maxWords) {
          return Now.translate('Must be no more than {max} words', {max: this.config.maxWords});
        }
      }
      return null;
    };

    if (config.autoResize) {
      this.setupAutoResize(instance);
    }
    if (config.charCount || config.wordCount) {
      this.setupCounter(instance);
    }

    const originalCleanup = instance.cleanup;
    instance.cleanup = function() {
      if (this.debounceTimer) clearTimeout(this.debounceTimer);
      return originalCleanup.call(this);
    };
  }

  static setupAutoResize(instance) {
    const {element, config} = instance;
    element._originalResize = element.style.resize || 'both';
    element._originalOverflow = element.style.overflowY || 'auto';

    element.style.resize = 'none';
    element.style.overflowY = 'hidden';

    setTimeout(() => this.adjustHeight(element, config), 0);
  }

  static adjustHeight(element, config) {
    const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
    element.style.height = 'auto';

    const style = window.getComputedStyle(element);
    const lineHeight = parseFloat(style.lineHeight) || parseFloat(style.fontSize) * 1.2 || 16;
    const paddingTop = parseFloat(style.paddingTop) || 0;
    const paddingBottom = parseFloat(style.paddingBottom) || 0;
    const padding = paddingTop + paddingBottom;

    const minHeight = (config.minRows * lineHeight) + padding;
    const maxHeight = config.maxRows ? (config.maxRows * lineHeight) + padding : Infinity;

    const newHeight = Math.min(Math.max(element.scrollHeight, minHeight), maxHeight);
    element.style.height = `${newHeight}px`;

    window.scrollTo(0, scrollTop);
    element.style.overflowY = element.scrollHeight > newHeight ? 'auto' : 'hidden';
  }

  static setupCounter(instance) {
    const {element, config, wrapper} = instance;

    const counterWrapper = document.createElement('div');
    counterWrapper.className = 'textarea-counter';
    counterWrapper.style.textAlign = 'right';
    counterWrapper.style.fontSize = 'smaller';
    counterWrapper.style.color = 'var(--color-text-muted, #666)';
    counterWrapper.setAttribute('aria-live', 'polite');

    if (config.charCount) {
      const charCounter = document.createElement('span');
      charCounter.className = config.charCountClass;
      counterWrapper.appendChild(charCounter);
      element.charCounter = charCounter;
    }

    if (config.wordCount) {
      if (config.charCount) counterWrapper.appendChild(document.createTextNode(' | '));
      const wordCounter = document.createElement('span');
      wordCounter.className = config.wordCountClass;
      counterWrapper.appendChild(wordCounter);
      element.wordCounter = wordCounter;
    }

    if (wrapper) {
      wrapper.appendChild(counterWrapper);
    } else {
      const div = document.createElement('div');
      div.appendChild(element.cloneNode(true));
      element.parentNode?.replaceChild(div, element);
      instance.element = div.querySelector('textarea');
      div.appendChild(counterWrapper);
      instance.wrapper = div;
    }

    this.updateCounters(instance);
  }

  static updateCounters(instance) {
    const {element, config} = instance;
    const value = element.value;

    if (element.charCounter) {
      const used = value.length;
      const max = element.maxLength > 0 ? element.maxLength : Now.translate('unlimited');
      element.charCounter.textContent = Now.translate(config.charCountFormat, {used, max});
    }

    if (element.wordCounter) {
      const used = instance.countWords(value);
      const max = config.maxWords > 0 ? config.maxWords : Now.translate('unlimited');
      element.wordCounter.textContent = Now.translate(config.wordCountFormat, {used, max});
    }
  }

  static setupEventListeners(instance) {
    const {element, config} = instance;

    let debounceTimer;
    const debouncedUpdate = Utils.function.debounce((e) => {
      if (config.autoResize) this.adjustHeight(element, config);
      if (config.charCount || config.wordCount) this.updateCounters(instance);
    }, config.debounceDelay);

    const handlers = {
      input: (e) => {
        instance.state.modified = true;
        instance.debounceTimer = debounceTimer;
        debouncedUpdate(e);
      },
      keydown: (e) => {
        if (e.key === 'Enter') {
          if (e.shiftKey) {
            const start = element.selectionStart;
            const end = element.selectionEnd;
            element.value = element.value.substring(0, start) + '\n' + element.value.substring(end);
            element.selectionStart = element.selectionEnd = start + 1;
            e.preventDefault();
          } else if (!e.ctrlKey && !e.altKey) {
            if (config.enterBehavior === 'prevent') {
              e.preventDefault();
            } else if (config.enterBehavior === 'callback' && config.enterCallback) {
              e.preventDefault();
              config.enterCallback(e, element.value);
            }
          }
        }

        if (config.enableTabIndent && e.key === 'Tab' && !e.shiftKey && !e.ctrlKey && !e.altKey) {
          e.preventDefault();
          const start = element.selectionStart;
          const end = element.selectionEnd;
          const indent = config.tabToSpaces > 0 ? ' '.repeat(config.tabToSpaces) : '\t';
          element.value = element.value.substring(0, start) + indent + element.value.substring(end);
          element.selectionStart = element.selectionEnd = start + indent.length;
        }
      }
    };

    EventSystemManager.addHandler(element, 'input', handlers.input);
    EventSystemManager.addHandler(element, 'keydown', handlers.keydown);
  }

  static cleanup(instance) {
    if (!instance) return;
    try {
      const {element} = instance;
      if (instance.debounceTimer) {
        clearTimeout(instance.debounceTimer);
        instance.debounceTimer = null;
      }
      // restore original styles
      if (element._originalResize !== undefined) {
        element.style.resize = element._originalResize;
        delete element._originalResize;
      }
      if (element._originalOverflow !== undefined) {
        element.style.overflowY = element._originalOverflow;
        delete element._originalOverflow;
      }

      // remove counters
      try {
        if (element.charCounter && element.charCounter.parentNode) element.charCounter.parentNode.removeChild(element.charCounter);
      } catch (e) {}
      try {
        if (element.wordCounter && element.wordCounter.parentNode) element.wordCounter.parentNode.removeChild(element.wordCounter);
      } catch (e) {}
    } catch (err) {
      console.warn('TextareaElementFactory.cleanup error', err);
    }

    if (typeof super.cleanup === 'function') super.cleanup(instance);
  }
}

ElementManager.registerElement('textarea', TextareaElementFactory);

// Export to global scope
window.TextareaElementFactory = TextareaElementFactory;