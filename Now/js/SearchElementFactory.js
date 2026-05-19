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
        const results = await config.onSearch(value);
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
  static setupClearButton(instance) {
    const {element, config} = instance;
    try {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = config.clearClass || 'search-clear';
      btn.setAttribute('aria-label', Now.translate('Clear'));
      btn.style.display = 'none';

      const target = element.wrapper || element.parentNode || document.body;
      if (target) target.appendChild(btn);

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
  static setupResultsContainer(instance) {
    const {element, config} = instance;
    try {
      const container = document.createElement('div');
      container.className = config.resultsClass || 'search-results';
      container.style.display = 'none';
      container.setAttribute('role', 'listbox');
      container.setAttribute('aria-hidden', 'true');

      const target = element.wrapper || element.parentNode || document.body;
      if (target) target.appendChild(container);

      element.resultsContainer = container;
      element.setAttribute('aria-haspopup', 'listbox');
      element.setAttribute('aria-expanded', 'false');
    } catch (error) {
      console.warn('SearchElementFactory: failed to create results container', error);
    }
  }

  // Show results
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

    const safeQuery = query ? String(query).replace(/[.*+?^${}()|[\]\\]/g, '\\$&') : '';
    const re = safeQuery ? new RegExp(`(${safeQuery})`, 'ig') : null;

    items.slice(0, config.maxResults || 10).forEach(item => {
      const li = document.createElement('li');
      li.className = 'search-result-item';
      li.setAttribute('role', 'option');

      let text = typeof item === 'object' ? (item.text || item.label || item.value || String(item)) : String(item);
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

  static setLoadingState(instance, isLoading) {
    const {element, config} = instance;
    const wrapper = element.wrapper || element.parentNode;
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