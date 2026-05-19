const Autocomplete = {
  config: {
    minLength: 2,
    delay: 300,
    limit: 10,
    listClass: 'autocomplete-list',
    activeClass: 'active',
    loadingClass: 'loading'
  },

  create(element, options = {}) {
    if (!element) return null;

    const instance = {
      element,
      options: {...this.config, ...options},
      state: {
        loading: false,
        value: element.value
      }
    };

    // Check existing datalist
    const listId = element.getAttribute('list');
    if (listId) {
      instance.datalist = document.getElementById(listId);
      return instance;
    }

    // Create suggestion list
    const list = document.createElement('ul');
    list.className = instance.options.listClass;
    list.style.display = 'none';
    element.parentNode.appendChild(list);
    instance.list = list;

    // Bind events
    instance.handlers = this.bindEvents(instance);

    return instance;
  },

  bindEvents(instance) {
    const {element, list, options} = instance;
    const handlers = {};

    // Input handling with debounce
    handlers.input = Utils.function.debounce(async (e) => {
      const value = e.target.value;
      if (value.length < options.minLength) {
        this.hideSuggestions(instance);
        return;
      }

      // If has datalist, filter it
      if (instance.datalist) {
        const filtered = this.filterDatalist(instance, value);
        this.showSuggestions(instance, filtered);
        return;
      }

      // If has URL, fetch from server
      if (options.url) {
        await this.fetchSuggestions(instance, value);
      }
    }, options.delay);

    // Keyboard navigation
    handlers.keydown = (e) => {
      if (list.style.display === 'none') return;

      const items = list.querySelectorAll('li');
      const current = list.querySelector(`.${options.activeClass}`);
      let next;

      switch (e.key) {
        case 'ArrowDown':
          e.preventDefault();
          next = !current ? items[0] : (current.nextElementSibling || items[0]);
          break;

        case 'ArrowUp':
          e.preventDefault();
          next = !current ? items[items.length - 1] : (current.previousElementSibling || items[items.length - 1]);
          break;

        case 'Enter':
          e.preventDefault();
          if (current) {
            this.selectItem(instance, current);
          }
          return;

        case 'Escape':
          this.hideSuggestions(instance);
          return;
      }

      if (next) {
        current?.classList.remove(options.activeClass);
        next.classList.add(options.activeClass);
        next.scrollIntoView({block: 'nearest'});
      }
    };

    // Hide on blur
    handlers.blur = () => {
      setTimeout(() => {
        this.hideSuggestions(instance);
      }, 200);
    };

    EventSystemManager.addHandler(element, 'input', handlers.input);
    EventSystemManager.addHandler(element, 'keydown', handlers.keydown);
    EventSystemManager.addHandler(element, 'blur', handlers.blur);

    return handlers;
  },

  async fetchSuggestions(instance, value) {
    const {element, options} = instance;

    if (instance.state.loading) return;
    instance.state.loading = true;
    element.classList.add(options.loadingClass);

    try {
      const response = await simpleFetch.get(options.url, {
        params: {
          q: value,
          limit: options.limit
        }
      });

      this.showSuggestions(instance, response.data);
    } catch (error) {
      console.error('Autocomplete fetch error:', error);
      this.hideSuggestions(instance);
    } finally {
      instance.state.loading = false;
      element.classList.remove(options.loadingClass);
    }
  },

  filterDatalist(instance, value) {
    const options = Array.from(instance.datalist.options);
    return options
      .filter(opt => opt.value.toLowerCase().includes(value.toLowerCase()))
      .slice(0, instance.options.limit)
      .map(opt => ({
        value: opt.value,
        label: opt.label || opt.value
      }));
  },

  showSuggestions(instance, items) {
    const {list, options} = instance;
    list.innerHTML = '';

    if (!Array.isArray(items) || items.length === 0) {
      this.hideSuggestions(instance);
      return;
    }

    items.forEach(item => {
      const li = document.createElement('li');
      li.dataset.value = typeof item === 'object' ? item.value : item;
      li.textContent = typeof item === 'object' ? (item.label || item.value) : item;

      li.addEventListener('click', () => {
        this.selectItem(instance, li);
      });

      li.addEventListener('mouseenter', () => {
        list.querySelector(`.${options.activeClass}`)?.classList.remove(options.activeClass);
        li.classList.add(options.activeClass);
      });

      list.appendChild(li);
    });

    list.style.display = 'block';
  },

  hideSuggestions(instance) {
    if (instance.list) {
      instance.list.style.display = 'none';
      instance.list.innerHTML = '';
    }
  },

  selectItem(instance, item) {
    const {element} = instance;
    element.value = item.dataset.value;
    this.hideSuggestions(instance);
    element.focus();
    element.dispatchEvent(new Event('change', {bubbles: true}));
  },

  destroy(instance) {
    if (!instance) return;

    const {element, handlers, list} = instance;

    // Remove event handlers
    if (handlers) {
      Object.entries(handlers).forEach(([event, handler]) => {
        EventSystemManager.removeHandler(handler);
      });
    }

    // Remove suggestion list
    if (list && list.parentNode) {
      list.parentNode.removeChild(list);
    }
  }
};

// Register as plugin
ElementManager.plugins = ElementManager.plugins || {};
ElementManager.plugins.autocomplete = Autocomplete;