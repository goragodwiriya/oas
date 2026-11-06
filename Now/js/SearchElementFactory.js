class SearchElementFactory extends TextElementFactory {
  static config = {
    ...TextElementFactory.config,
    type: 'search',
    inputMode: 'search',
    searchDelay: 300,
    minLength: 2,
    maxLength: 100,
    placeholder: 'Search',
    wrapperClass: 'search',
    inputClass: 'search-input',
    clearClass: 'search-clear',
    loadingClass: 'search-loading',
    resultsClass: 'search-results',
    activeClass: 'active',
    maxResults: 10,
    highlightMatches: true,
    formIntegration: true, // Increase the combination with Formmanager.
    keyboard: {
      selectKeys: ['Enter', ' '],
      closeKeys: ['Escape'],
      navigateUpKeys: ['ArrowUp'],
      navigateDownKeys: ['ArrowDown']
    }
  };

  /**
   * Create a search field from a field definition.
   *
   * A field built with a `wrapper` gets the factory's `wrapperClass` ('search')
   * unless the definition names its own.
   *
   * @param {Object} def - Field definition.
   * @returns {Object} - The element instance.
   */
  static create(def = {}) {
    if (def.wrapper && def.wrapperClass === undefined) {
      def = {...def, wrapperClass: this.config.wrapperClass};
    }
    return super.create(def);
  }

  /**
   * Turn a text field into a search field.
   *
   * Extends the text element setup, then adds the search-specific pieces: the
   * results container, the clear button, and (when the field lives inside a
   * `data-form`) registration with FormManager.
   *
   * @param {Object} instance - Element instance carrying `element` and `config`.
   * @returns {void}
   */
  static setupElement(instance) {
    // Basic settings from Textelementfactory
    super.setupElement(instance);
    const {element, config} = instance;

    // Specify the type and specific qualifications of Search Input.
    element.type = 'search';
    element.inputMode = 'search';

    // Add search button
    this.setupClearButton(instance);

    // Add the search box (if specified onsearch)
    if (typeof config.onSearch === 'function') {
      this.setupResultsContainer(instance);
    } else if (window.Autocomplete && config.useAutocomplete) {
      // Use AutoComplete from the main system if there is
      instance.autocomplete = Autocomplete.create(element, {
        minLength: config.minLength,
        delay: config.searchDelay,
        limit: config.maxResults
      });
    }

    // Register with Formmanager if you want
    if (config.formIntegration) {
      this.registerWithFormManager(instance);
    }

    // Add methon for creating results.
    instance.createSuggestionList = function(items) {
      return SearchElementFactory.showResults(instance, items);
    };

    return instance;
  }

  // Provide cleanup for instances created by this factory
  /**
   * Remove the clear button and results container added by this factory.
   *
   * Wrapped in try/catch so a DOM that has already been partially torn down
   * does not throw while cleaning up the rest.
   *
   * @param {Object} instance - Element instance being torn down.
   * @returns {void}
   */
  static cleanup(instance) {
    if (!instance) return;
    try {
      const {element} = instance;
      if (element.clearButton && element.clearButton.parentNode) {
        element.clearButton.parentNode.removeChild(element.clearButton);
        element.clearButton = null;
      }
      if (element.resultsContainer && element.resultsContainer.parentNode) {
        element.resultsContainer.parentNode.removeChild(element.resultsContainer);
        element.resultsContainer = null;
      }

      // If registered with FormManager, attempt to unregister validator
      try {
        const formManager = Now.getManager && Now.getManager('form');
        const form = element.closest ? element.closest('form[data-form]') : null;
        if (form && formManager && typeof formManager.unregisterValidator === 'function') {
          formManager.unregisterValidator(element.id);
        }
      } catch (e) {}
    } catch (err) {
      console.warn('SearchElementFactory.cleanup error', err);
    }

    // Call parent cleanup
    if (typeof super.cleanup === 'function') super.cleanup(instance);
  }

  /**
   * Tell FormManager about a search field inside a `data-form`.
   *
   * Does nothing when FormManager is not loaded, or the field is not inside a
   * declarative form, so a standalone search input works without it.
   *
   * @param {Object} instance - Element instance to register.
   * @returns {void}
   */
  static registerWithFormManager(instance) {
    const formManager = Now.getManager('form');
    if (!formManager) return;

    const form = instance.element.closest('form[data-form]');
    if (!form) return;

    const formId = form.dataset.form;
    const formInstance = formManager.getInstance(formId);
    if (formInstance) {
      // Register how to check the accuracy specifically for Search.
      formManager.registerValidator(instance.element.id, (value) => {
        return instance.validate(value, true);
      });
    }
  }

  /**
   * Bind typing to the search and the arrow, Enter and Escape keys to the
   * results, on top of the text field's own handlers.
   *
   * The search runs `searchDelay` ms after the last keystroke, so a burst of
   * typing makes one request. Only fields with an `onSearch` callback search.
   *
   * @param {Object} instance - Element instance to bind.
   * @returns {void}
   */
  static setupEventListeners(instance) {
    super.setupEventListeners(instance);
    const {element, config} = instance;
    if (typeof config.onSearch !== 'function' || instance._searchListenersSetup) return;
    instance._searchListenersSetup = true;

    const delay = Math.max(0, parseInt(config.searchDelay, 10) || 0);
    let timer = null;
    EventSystemManager.addHandler(element, 'input', () => {
      clearTimeout(timer);
      timer = setTimeout(() => this.handleSearch(instance, element.value), delay);
    });
    EventSystemManager.addHandler(element, 'keydown', (event) => this.handleResultsKey(instance, event));

    const previousCleanup = instance.cleanup;
    instance.cleanup = function() {
      clearTimeout(timer);
      return typeof previousCleanup === 'function' ? previousCleanup.call(this) : this;
    };
  }

  /**
   * Move through, pick or close the results with the keys in `config.keyboard`.
   *
   * @param {Object} instance - Element instance.
   * @param {Object} event - The keydown event (EventSystemManager's wrapper).
   * @returns {void}
   */
  static handleResultsKey(instance, event) {
    const {element, config} = instance;
    const container = element.resultsContainer;
    if (!container || container.style.display === 'none') return;

    const keys = config.keyboard || {};
    const items = Array.from(container.querySelectorAll('.search-result-item'));
    if (!items.length) return;
    const activeClass = config.activeClass || 'active';
    const current = items.findIndex(item => item.classList.contains(activeClass));

    const highlight = (index) => {
      items.forEach((item, i) => {
        item.classList.toggle(activeClass, i === index);
        item.setAttribute('aria-selected', i === index ? 'true' : 'false');
      });
      items[index].scrollIntoView?.({block: 'nearest'});
    };

    if ((keys.navigateDownKeys || []).includes(event.key)) {
      event.preventDefault();
      highlight(current < 0 ? 0 : Math.min(items.length - 1, current + 1));
    } else if ((keys.navigateUpKeys || []).includes(event.key)) {
      event.preventDefault();
      highlight(current <= 0 ? 0 : current - 1);
    } else if ((keys.selectKeys || []).includes(event.key) && current >= 0) {
      event.preventDefault();
      items[current].click();
    } else if ((keys.closeKeys || []).includes(event.key)) {
      event.preventDefault();
      this.showResults(instance, []);
    }
  }

  /**
   * Run a search for the current value and render the results.
   *
   * Shows or hides the clear button based on whether there is a value, and
   * toggles the loading state around the request.
   *
   * @param {Object} instance - Element instance searching.
   * @param {string} value - Current input value.
   * @returns {Promise<void>}
   */
  static async handleSearch(instance, value) {
    // Increase error management
    const {element, config} = instance;

    // Show or hide the washing button
    if (element.clearButton) {
      element.clearButton.style.display = value ? 'block' : 'none';
    }

    // If there is no value or length less than the minimum To hide results
    if (!value || value.length < config.minLength) {
      if (element.resultsContainer) {
        element.resultsContainer.style.display = 'none';
        element.setAttribute('aria-expanded', 'false');
      }
      if (config.onChange) config.onChange(element, '');
      return;
    }

    try {
      // Show the status to load
      this.setLoadingState(instance, true);

      // If there is an onsearch function, use
      if (config.onSearch) {
        // A slower answer to an earlier query must not replace a newer one
        const sequence = (instance._searchSequence = (instance._searchSequence || 0) + 1);
        const results = await config.onSearch(value);
        if (sequence !== instance._searchSequence) return;
        this.showResults(instance, results, value);
      }

      // Call the onhange function if there is
      if (config.onChange) config.onChange(element, value);

      // Send searches to Eventmanager.
      EventManager.emit('search:performed', {
        elementId: element.id,
        query: value
      });

    } catch (error) {
      ErrorManager.handle(error, {
        context: 'SearchElementFactory.handleSearch',
        type: 'error:search'
      });

      // Show mistakes under the search box.
      if (typeof FormError !== 'undefined') {
        FormError.showFieldError(element.id, error.message || 'Search error occurred');
      }
    } finally {
      // Hide the status is loading.
      this.setLoadingState(instance, false);
    }
  }

  // Create a Clear button and tie an event.
  /**
   * Add the button that clears the field and hides results.
   *
   * @param {Object} instance - Element instance to attach the button to.
   * @returns {void}
   */
  static setupClearButton(instance) {
    const {element, config} = instance;
    try {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = config.clearClass || 'search-clear';
      btn.setAttribute('aria-label', Now.translate('Clear'));
      btn.style.display = 'none';

      // Must attach to the element's own wrapper (or an already-connected
      // parent) — never fall back to document.body: this factory usually
      // runs via ElementFactory.create() while the element is still
      // detached, so a body fallback would silently leak an orphaned button
      // on every call instead of surfacing the missing `wrapper` config.
      const target = element.wrapper || element.parentNode;
      if (!target) {
        console.warn('SearchElementFactory: no wrapper/parentNode to attach clear button to (pass `wrapper` in the element config)');
        return;
      }
      target.appendChild(btn);

      btn.addEventListener('click', (e) => {
        e.preventDefault();
        element.value = '';
        element.dispatchEvent(new Event('input', {bubbles: true}));
        element.dispatchEvent(new Event('change', {bubbles: true}));
        element.focus();
        if (typeof config.onChange === 'function') config.onChange(element, '');
      });

      element.clearButton = btn;
    } catch (error) {
      // non-fatal
      console.warn('SearchElementFactory: failed to create clear button', error);
    }
  }

  // Create Container for showing search results.
  /**
   * Create the dropdown that search results render into.
   *
   * Starts hidden; failures are swallowed so a broken results container does
   * not stop the rest of the field from working.
   *
   * @param {Object} instance - Element instance to attach the container to.
   * @returns {void}
   */
  static setupResultsContainer(instance) {
    const {element, config} = instance;
    try {
      const container = document.createElement('div');
      container.className = config.resultsClass || 'search-results';
      container.style.display = 'none';
      container.setAttribute('role', 'listbox');
      container.setAttribute('aria-hidden', 'true');

      // Same rule as setupClearButton: never fall back to document.body.
      const target = element.wrapper || element.parentNode;
      if (!target) {
        console.warn('SearchElementFactory: no wrapper/parentNode to attach results container to (pass `wrapper` in the element config)');
        return;
      }
      target.appendChild(container);

      element.resultsContainer = container;
      element.setAttribute('aria-haspopup', 'listbox');
      element.setAttribute('aria-expanded', 'false');
    } catch (error) {
      console.warn('SearchElementFactory: failed to create results container', error);
    }
  }

  // Show results
  /**
   * Render search results into the dropdown.
   *
   * @param {Object} instance - Element instance to render for.
   * @param {Array} [items=[]] - Result records.
   * @param {string} [query=''] - The query the results are for.
   * @returns {void}
   */
  static showResults(instance, items = [], query = '') {
    const {element, config} = instance;
    const container = element.resultsContainer;
    if (!container) return;

    container.innerHTML = '';
    if (!items || items.length === 0) {
      container.style.display = 'none';
      element.setAttribute('aria-expanded', 'false');
      container.setAttribute('aria-hidden', 'true');
      return;
    }

    const list = document.createElement('ul');
    list.className = 'search-results-list';

    // Matched against the escaped text, so the query is escaped the same way first
    const escapedQuery = query ? String(query).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;') : '';
    const safeQuery = escapedQuery ? escapedQuery.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') : '';
    const re = safeQuery ? new RegExp(`(${safeQuery})`, 'ig') : null;

    items.slice(0, config.maxResults || 10).forEach(item => {
      const li = document.createElement('li');
      li.className = 'search-result-item';
      li.setAttribute('role', 'option');

      const label = typeof item === 'object' ? (item.text || item.label || item.value || String(item)) : String(item);
      // Results usually come from the server: escape them, then mark the matches
      let text = label.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
      if (config.highlightMatches && re) {
        text = text.replace(re, '<em>$1</em>');
      }

      li.innerHTML = `<span class="result-text">${text}</span>`;
      li.addEventListener('click', () => {
        element.value = typeof item === 'object' ? (item.value || item.text || item.label || '') : item;
        if (instance.hiddenInput) instance.hiddenInput.value = typeof item === 'object' ? (item.key || item.value || '') : element.value;
        element.dispatchEvent(new Event('change', {bubbles: true}));
        container.style.display = 'none';
        element.setAttribute('aria-expanded', 'false');
      });

      list.appendChild(li);
    });

    container.appendChild(list);
    container.style.display = 'block';
    container.setAttribute('aria-hidden', 'false');
    element.setAttribute('aria-expanded', 'true');
  }

  /**
   * Toggle the loading class on the field's wrapper.
   *
   * @param {Object} instance - Element instance to update.
   * @param {boolean} isLoading - Whether a search is in flight.
   * @returns {void}
   */
  static setLoadingState(instance, isLoading) {
    const {element, config} = instance;
    const wrapper = [instance.wrapper, element.wrapper, element.parentNode].find(node => node instanceof Element);
    if (wrapper) {
      if (isLoading) wrapper.classList.add(config.loadingClass || 'search-loading');
      else wrapper.classList.remove(config.loadingClass || 'search-loading');
    }
  }
}

// Register with Elementmanager
ElementManager.registerElement('search', SearchElementFactory);

// Export to global scope
window.SearchElementFactory = SearchElementFactory;
ElementManager.registerElement('search', SearchElementFactory);