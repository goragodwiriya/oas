/**
 * CodeElementFactory
 *
 * Connects CodeEditor to ElementManager, so a code field works like any other
 * form field: it submits with the form, takes `required` and `data-validate`,
 * shows FormError messages, and fills from `data-attr="value:..."`.
 *
 * Usage:
 *   <textarea name="script" data-element="code" data-language="javascript"></textarea>
 *
 * Options (data-* attributes):
 *   data-language="javascript"       - javascript | json | html | css | php | sql | text (and their aliases)
 *   data-line-numbers="false"        - hide the line numbers
 *   data-tab-size="4"                - columns per indent level (default 2)
 *   data-indent-with-tabs="true"     - indent with tab characters
 *   data-auto-close-brackets="false" - do not type closing brackets and quotes
 *   data-auto-indent="false"         - plain Enter, no indent kept
 *   data-min-lines="10"              - minimum visible lines
 *   data-height="400"                - fixed height (px or any CSS length), scrolls inside
 *   data-max-height="60vh"           - grow with the content up to this height
 *   data-theme="dark"                - light | dark (default: follow the page)
 *
 * The CodeEditor instance is `instance.editor`.
 */
class CodeElementFactory extends ElementFactory {
  static config = {
    ...ElementFactory.config,
    language: 'text',
    lineNumbers: true,
    tabSize: 2,
    indentWithTabs: false,
    autoCloseBrackets: true,
    autoIndent: true,
    minLines: 5,
    height: null,
    maxHeight: null,
    theme: null
  };

  static propertyHandlers = {
    language: {
      get(element) {
        return CodeEditor.get(element)?.getLanguage() || element.dataset.language || 'text';
      },
      set(instance, newValue) {
        instance.element.dataset.language = newValue;
        instance.editor?.setLanguage(newValue);
      }
    }
  };

  /**
   * Build a textarea from a field definition.
   *
   * @param {Object} def - Field definition.
   * @returns {HTMLTextAreaElement} - The created textarea.
   */
  static create(def) {
    const cfg = Object.assign({}, def, {tagName: 'textarea', type: 'textarea'});
    return super.create(cfg);
  }

  /**
   * Read the editor options a single element declares through `data-*` attributes.
   *
   * Only options actually present are returned, so the defaults in `config`
   * fill the rest.
   *
   * @param {HTMLElement} element - The textarea being set up.
   * @param {Object} def - Field definition passed to the factory.
   * @param {DOMStringMap} dataset - The element's `data-*` attributes.
   * @returns {Object} - Editor options for this element.
   */
  static extractCustomConfig(element, def, dataset) {
    const options = {};
    const pick = (key, value) => {
      if (value !== undefined && value !== null && value !== '') options[key] = value;
    };
    const bool = value => (value === undefined ? undefined : value === 'true' || value === '1' || value === '');
    const int = value => (value === undefined || isNaN(parseInt(value, 10)) ? undefined : parseInt(value, 10));

    pick('language', def.language ?? dataset.language);
    pick('lineNumbers', def.lineNumbers ?? bool(dataset.lineNumbers));
    pick('tabSize', def.tabSize ?? int(dataset.tabSize));
    pick('indentWithTabs', def.indentWithTabs ?? bool(dataset.indentWithTabs));
    pick('autoCloseBrackets', def.autoCloseBrackets ?? bool(dataset.autoCloseBrackets));
    pick('autoIndent', def.autoIndent ?? bool(dataset.autoIndent));
    pick('minLines', def.minLines ?? int(dataset.minLines));
    pick('height', def.height ?? dataset.height);
    pick('maxHeight', def.maxHeight ?? dataset.maxHeight);
    pick('theme', def.theme ?? dataset.theme);
    return options;
  }

  /**
   * Attach the editor once the base factory has finished with the element.
   *
   * Runs after `setupProperties`, so the editor wraps the framework's own
   * `value` accessor and values set by FormManager or `instance.value` are
   * recoloured too.
   *
   * @param {Object} instance - Element instance carrying `element` and `config`.
   * @returns {void}
   */
  static setupEventListeners(instance) {
    super.setupEventListeners(instance);

    const {element, config} = instance;
    instance.editor = new CodeEditor(element, {
      language: config.language,
      lineNumbers: config.lineNumbers !== false,
      tabSize: config.tabSize,
      indentWithTabs: !!config.indentWithTabs,
      autoCloseBrackets: config.autoCloseBrackets !== false,
      autoIndent: config.autoIndent !== false,
      minLines: config.minLines,
      height: config.height,
      maxHeight: config.maxHeight,
      theme: config.theme,
      onChange: typeof config.onChange === 'function' ? config.onChange : null
    });

    const previousDestroy = instance.destroy;
    instance.destroy = function() {
      this.editor?.destroy();
      this.editor = null;
      if (typeof previousDestroy === 'function') return previousDestroy.call(this);
      return undefined;
    };
  }
}

ElementManager.registerElement('code', CodeElementFactory);

window.CodeElementFactory = CodeElementFactory;
