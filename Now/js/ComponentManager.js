const ComponentManager = {
  config: {
    reactive: false,
    templateCache: true,
    performance: {
      monitoring: false,
      batchUpdates: false
    },
  },

  components: new Map(),
  instances: new Map(),
  templateCache: new Map(),

  /**
   * Initializes the manager: merges configuration, starts the i18n listeners
   * and the observer that mounts components added to the DOM later.
   *
   * @param {Object} [options={}] - Configuration merged over the defaults
   * @returns {Promise<Object>} The manager instance
   */
  async init(options = {}) {
    this.config = {...this.config, ...options};
    this.setupI18nListeners();
    this.setupCoreObserver();
    return this;
  },

  /**
   * Setup CoreObserver handlers for auto-init/cleanup of components
   */
  setupCoreObserver() {
    if (!window.CoreObserver) return;

    // Auto-init components when added to DOM
    CoreObserver.onAdd('[data-component]', (element) => {
      // Skip if already initialized
      if (this.instances.has(element)) return;

      const name = element.dataset.component;

      // Handle api components separately
      if (name === 'api' && window.ApiComponent) {
        if (!element._apiComponent) {
          ApiComponent.create(element);
        }
        return;
      }

      // Mount other components
      if (this.components.has(name)) {
        const props = this.extractProps(element);
        this.mount(element, name, props).catch(error => {
          console.error(`[ComponentManager] Failed to auto-init component ${name}:`, error);
        });
      }
    }, {priority: 10});

    // Auto-cleanup components when removed from DOM
    CoreObserver.onRemove('[data-component]', (element) => {
      const instance = this.instances.get(element);
      if (instance && !instance._destroyed) {
        this.destroy(element);
      }

      // Handle api components
      if (element.dataset.component === 'api' && element._apiComponent && window.ApiComponent) {
        ApiComponent.destroy(element._apiComponent);
      }
    }, {priority: 10, delay: 0});
  },

  /**
   * Setup i18n listeners for automatic translation updates
   * All components will automatically re-render when:
   * - Translations are loaded for the first time
   * - User changes locale
   */
  setupI18nListeners() {
    // Re-render all components when translations loaded
    EventManager.on('i18n:loaded', (event) => {
      if (event.success) {
        this.updateAllComponents();
      }
    });

    // Re-render all components when locale changed
    EventManager.on('locale:changed', () => {
      this.updateAllComponents();
    });

    // Check if I18nManager already initialized and has translations
    // This handles the case where ComponentManager loads after I18nManager
    if (window.I18nManager?.state?.initialized) {
      // Trigger initial component update
      this.updateAllComponents();
    }
  },

  /**
   * Update all component instances with new translations
   * This triggers a re-render for each mounted component
   */
  updateAllComponents() {
    this.instances.forEach(instance => {
      if (instance._mounted && !instance._destroyed && !instance._updating) {
        try {
          this.renderInstance(instance);
        } catch (error) {
          this.handleError('Error updating component translations', 'updateAllComponents', {
            componentId: instance.id,
            error
          });
        }
      }
    });
  },

  /**
   * Registers a component definition under a name and mounts any matching
   * elements already in the document.
   *
   * Redefining an existing name overwrites it and logs a warning naming both
   * source files, because the conflict is otherwise invisible.
   *
   * @param {string} name - Component name used by data-component
   * @param {Object} definition - Component definition
   * @param {string|null} [definition.template] - Template markup
   * @param {Object} [definition.state] - Initial state
   * @param {Object} [definition.methods] - Methods bound to the instance
   * @param {Object} [definition.computed] - Computed properties
   * @param {Object} [definition.watch] - State watchers
   * @param {Object} [definition.events] - Event map keyed by "event selector"
   * @param {boolean} [definition.reactive] - Wrap state in a reactive proxy
   * @returns {Object} The processed definition
   * @throws {Error} When the name is not a string
   */
  define(name, definition) {
    if (!name || typeof name !== 'string') {
      throw new Error('Component name must be a string');
    }

    // Check for duplicate component names
    if (this.components.has(name)) {
      const existing = this.components.get(name);
      console.warn(
        `⚠️ Component name conflict detected!\n` +
        `Component "${name}" is already registered.\n` +
        `This will overwrite the existing component definition.\n` +
        `\n` +
        `Existing component: ${existing._registeredFrom || 'unknown source'}\n` +
        `New component: ${this._getCurrentScriptSource() || 'current script'}\n` +
        `\n` +
        `To fix this:\n` +
        `1. Use a unique component name (e.g., "${name}2", "${name}_custom")\n` +
        `2. Or remove/rename the conflicting component\n` +
        `3. Check your component registration in both files`
      );
    }

    const config = {
      reactive: false,
      renderStrategy: 'auto',
      template: null,
      state: {},
      methods: {},
      computed: {},
      watch: {},
      events: {},
      ...definition,
      _registeredFrom: this._getCurrentScriptSource() || 'unknown'
    };

    const processedDefinition = this.processDefinition(name, config);
    this.components.set(name, processedDefinition);

    if (document.readyState !== 'loading') {
      this.initializeExistingElements(name);
    }

    return processedDefinition;
  },

  /**
   * Reads the file name of the script currently registering a component, taken
   * from a throwaway stack trace, so name conflicts can name their sources.
   *
   * @returns {string|null} Script file name, or null when unavailable
   */
  _getCurrentScriptSource() {
    try {
      const error = new Error();
      const stack = error.stack;
      if (!stack) return null;

      const lines = stack.split('\n');
      for (let i = 0; i < lines.length; i++) {
        const match = lines[i].match(/https?:\/\/[^)]+\.js/);
        if (match && !match[0].includes('ComponentManager.js')) {
          return match[0].split('/').pop();
        }
      }
      return null;
    } catch (e) {
      return null;
    }
  },

  /**
   * Normalizes a raw definition into the shape instances rely on, filling in
   * the defaults for every option and lifecycle hook.
   *
   * @param {string} name - Component name
   * @param {Object} definition - Raw definition passed to define()
   * @returns {Object} Normalized definition
   */
  processDefinition(name, definition) {
    const processed = {
      name,
      reactive: definition.reactive || false,
      template: definition.template || null,
      state: definition.state || {},
      methods: definition.methods || {},
      computed: definition.computed || {},
      watch: definition.watch || {},
      validElement: definition.validElement || null,
      aria: definition.aria || {},
      events: definition.events || {},
      beforeCreate: definition.beforeCreate,
      created: definition.created,
      beforeMount: definition.beforeMount,
      mounted: definition.mounted,
      beforeUpdate: definition.beforeUpdate,
      updated: definition.updated,
      beforeDestroy: definition.beforeDestroy,
      destroyed: definition.destroyed,
      errorCaptured: definition.errorCaptured,
      renderStrategy: definition.renderStrategy || 'auto',
      errorBoundary: definition.errorBoundary || false,
      setupElement: definition.setupElement || null
    };

    return processed;
  },

  /**
   * Mounts the components present in the document, skipping instances that are
   * already initialized.
   *
   * api components are left to ApiComponent, which renders after its data
   * arrives.
   *
   * @param {string|null} [componentName=null] - Limit to this component name
   * @returns {void}
   */
  initializeExistingElements(componentName = null) {
    try {
      const selector = componentName
        ? `[data-component="${componentName}"]`
        : '[data-component]';

      document.querySelectorAll(selector).forEach(element => {
        const name = element.getAttribute('data-component');

        // Skip api components - they are initialized separately below
        if (name === 'api') {
          return;
        }

        const existingInstance = this.instances.get(element);
        if (existingInstance &&
          existingInstance.state.initialized &&
          !existingInstance._destroyed) {
          // skip initialized components quietly
          return;
        }

        if (this.components.has(name)) {
          const props = this.extractProps(element);
          this.mount(element, name, props).catch(error => {
            console.error(`Failed to initialize component ${name}:`, error);
          });
        }
      });

      // Initialize api components separately - they handle their own rendering after data loads
      if (window.ApiComponent && typeof ApiComponent.initElements === 'function') {
        ApiComponent.initElements();
      }
    } catch (error) {
      this.handleError('Error initializing existing elements', 'initializeExistingElements', {error});
    }
  },

  /**
   * Builds the props of a component from the data attributes of its element.
   *
   * JSON arrays, numbers and booleans are parsed; the inner HTML, when there is
   * any, becomes the template prop.
   *
   * @param {HTMLElement} element - Element carrying data-component
   * @returns {Object} Props for the instance
   */
  extractProps(element) {
    const props = {};

    Array.from(element.attributes).forEach(attr => {
      let propName = attr.name;
      let value = attr.value;

      if (propName === 'data-component') return;

      propName = propName.replace(/^data-/, '');

      if (value.startsWith('[') && value.endsWith(']')) {
        try {
          value = JSON.parse(value);
        } catch (error) {
          this.handleError(`Failed to parse array value for ${propName}:`, 'extractProps', {propName, value, error});
        }
        props[propName] = value;
        return;
      }

      if (!isNaN(value)) {
        value = parseFloat(value);
      }

      if (value === 'true' || value === 'false') {
        value = value === 'true';
      }

      props[propName] = value;
    });

    const template = element.innerHTML.trim();
    if (template) {
      props.template = template;
    }

    return props;
  },

  /**
   * Creates a component instance: state, bound methods, lifecycle flags, and
   * the refs proxy that resolves [data-ref] lazily.
   *
   * @param {HTMLElement} element - Host element
   * @param {Object} definition - Processed definition
   * @param {Object} [props={}] - Props extracted from the element
   * @returns {Object} New instance, not yet mounted
   * @throws {Error} When the instance cannot be created
   */
  initializeComponent(element, definition, props = {}) {
    try {
      const instance = {
        id: Utils.generateUUID(),
        element,
        props: {...props},
        reactive: definition.reactive || false,
        renderStrategy: definition.renderStrategy || 'auto',
        template: definition.template || null,
        state: {...(definition.state || {})},
        methods: {},
        computed: definition.computed || {},
        watch: definition.watch || {},
        events: definition.events || {},
        _definition: definition,
        _mounted: false,
        _updating: false,
        _destroyed: false,
        _vnode: null,
        _events: new Map(),
        _updateQueue: new Set(),
        _childComponents: new Set(),
        _parentComponent: null,
        _errorBoundary: definition.errorBoundary
          ? {
            handler: (typeof definition.errorBoundary === 'object' && definition.errorBoundary.handler)
              || definition.errorCaptured
              || null,
            fallback: (typeof definition.errorBoundary === 'object' && definition.errorBoundary.fallback)
              || null
          }
          : null,
        render: function() {
          ComponentManager.renderInstance(this);
        },
        refs: new Proxy({}, {
          get: (target, key) => {
            return instance.element.querySelector(`[data-ref="${key}"]`);
          }
        })
      };

      if (definition.methods) {
        Object.entries(definition.methods).forEach(([name, method]) => {
          instance.methods[name] = method.bind(instance);
        });
      }

      if (this.config.performance.monitoring) {
        this.setupPerformanceMonitoring(instance);
      }

      return instance;
    } catch (error) {
      throw ErrorManager.handle(error, {
        context: 'ComponentManager.initializeComponent',
        data: {element, definition, props}
      });
    }
  },

  /**
   * Splits an event map key into its event name and optional delegate selector.
   *
   * @param {string} selector - Key such as 'click .btn'
   * @returns {Array} [eventName, targetSelector], the selector being null when absent
   */
  parseEventSelector(selector) {
    const parts = selector.trim().split(/\s+/);
    const eventName = parts[0];
    const targetSelector = parts.slice(1).join(' ') || null;

    return [eventName, targetSelector];
  },

  /**
   * Binds the event map of a component.
   *
   * DOM events are bound to the host element and delegated to the selector when
   * one is given; anything else is registered on EventManager as a named event.
   *
   * @param {Object} context - Component instance
   * @param {Object} events - Handlers keyed by "event selector"
   * @returns {void}
   */
  setupEvents(context, events) {
    const eventSystem = Now.getManager('eventsystem');
    const eventManager = Now.getManager('event');

    if (!context._events) {
      context._events = new Map();
    }

    Object.entries(events).forEach(([selector, handler]) => {
      const [eventName, targetSelector] = this.parseEventSelector(selector);
      const isDOMEvent = eventSystem?.supportedEvents.includes(eventName);

      if (isDOMEvent && eventSystem) {
        const wrappedHandler = (event) => {
          const target = targetSelector ?
            event.target.closest(targetSelector) :
            context.element;

          if (target && context.element.contains(target)) {
            event.delegateTarget = target;
            const result = handler.call(context, event);
            return result;
          }
        };

        context.element.addEventListener(eventName, wrappedHandler, {
          capture: false,
          passive: false
        });

        context._events.set(eventName, {
          originalHandler: handler,
          wrappedHandler: wrappedHandler,
          selector: targetSelector
        });
      } else if (!isDOMEvent && eventManager) {
        const boundHandler = handler.bind(context);
        context._events.set(selector, {
          originalHandler: handler,
          wrappedHandler: boundHandler,
          system: 'eventmanager'
        });
        eventManager.on(selector, boundHandler);
      }
    });
  },

  /**
   * Removes every listener setupEvents() registered on the instance.
   *
   * @param {Object} context - Component instance
   * @returns {void}
   */
  cleanupEvents(context) {
    try {
      if (!context._events || !(context._events instanceof Map)) {
        context._events = new Map();
        return;
      }

      context._events.forEach((eventConfig, eventName) => {
        if (eventConfig.wrappedHandler) {
          context.element.removeEventListener(eventName, eventConfig.wrappedHandler);
        }
      });

      context._events.clear();

    } catch (error) {
      this.handleError('Error cleaning up events', 'cleanupEvents', {error});
    }
  },

  /**
   * Mounts a component onto an element, running the lifecycle in order:
   * beforeCreate, template processing, created, beforeMount, first render,
   * mounted, then event binding and reactive state.
   *
   * @param {HTMLElement} element - Element to mount onto
   * @param {string} name - Registered component name
   * @param {Object} [props={}] - Props for the instance
   * @returns {Promise<Object|undefined>} The instance, or undefined on bad input
   * @throws {Error} When a lifecycle hook fails
   */
  async mount(element, name, props = {}) {
    if (!element || !name) {
      this.handleError('Invalid mount parameters', 'mount', {element, name, props});
      return;
    }

    const definition = this.components.get(name);
    if (!definition) {
      this.handleError(`Component ${name} not found`, 'mount', {element, name, props});
      return;
    }

    try {
      const instance = this.initializeComponent(element, definition, props);
      this.instances.set(element, instance);

      if (instance._definition.beforeCreate) {
        await instance._definition.beforeCreate.call(instance);
      }

      instance.element = this.processTemplate(element, definition, instance);

      if (instance._definition.created) {
        await instance._definition.created.call(instance);
      }

      if (instance._definition.beforeMount) {
        await instance._definition.beforeMount.call(instance);
      }

      await this.renderInstance(instance);

      instance._mounted = true;

      if (instance._definition.mounted) {
        await instance._definition.mounted.call(instance);
      }

      if (instance._definition.events) {
        this.setupEvents(instance, instance._definition.events);
      }

      if (instance.reactive) {
        instance.state = ReactiveManager.createComponentState(instance);
        ReactiveManager.bindComponentEvents(instance);
      }

      return instance;

    } catch (error) {
      throw this.handleError(error, 'mount');
    }
  },

  /**
   * Re-keys an instance onto the new element after a template replaced its host.
   *
   * Bug this fixes: mount() keys `instances` by the original host element, but
   * a component whose template has its own root replaces that host. Cleanup on
   * page transition (RouterManager) looks up `[data-component]` elements in the
   * DOM and asks `instances` for them, so it found nothing and never called
   * `destroyed()` for any component that uses a template.
   *
   * The effect was that on every page change the old component kept its timers
   * and listeners while a new one was stacked on top: in an app polling an API
   * component every 15 seconds, ten page views meant ten times the requests
   * with nothing to show for it.
   *
   * @param {HTMLElement} oldElement - Host element that was replaced
   * @param {HTMLElement} newElement - Template root now living in the DOM
   * @returns {void}
   */
  rekeyInstance(oldElement, newElement) {
    const instance = this.instances.get(oldElement);
    if (!instance || instance === this.instances.get(newElement)) return;

    this.instances.delete(oldElement);
    this.instances.set(newElement, instance);

    // Let `this.element` in a component's method point to the element actually in the DOM.
    instance.element = newElement;

    // The sweeper also searched. `[data-component]` — root without this attribute will be ignored.
    if (!newElement.hasAttribute('data-component') && oldElement.getAttribute) {
      const name = oldElement.getAttribute('data-component');
      if (name) newElement.setAttribute('data-component', name);
    }
  },

  /**
   * Renders the template of a component and puts the result in the DOM.
   *
   * The host element is replaced by the template root, which inherits its
   * attributes; the instance is re-keyed onto it. Element and form instances of
   * the old subtree are destroyed first, then the new subtree is scanned so its
   * elements and forms are enhanced.
   *
   * @param {HTMLElement} element - Host element
   * @param {Object} definition - Processed definition
   * @param {Object} context - Component instance
   * @returns {HTMLElement} Element the component now lives on
   */
  processTemplate(element, definition, context) {
    try {
      if (this.isValidComponentElement(element, definition)) {
        return this.setupExistingElement(element, definition, context.state);
      }

      const template = this.getTemplate(element, definition);
      if (!template) {
        return element;
      }

      const container = document.createElement('div');
      container.innerHTML = template.trim();

      // Check if template contains api components - if so, skip processTemplateString
      // because ApiComponent will handle its own rendering after data loads
      const hasApiComponent = container.querySelector('[data-component="api"]');
      if (!hasApiComponent) {
        TemplateManager.processTemplateString(template, context, container);
      }

      let newElement = container.firstElementChild;
      if (!newElement) {
        throw new Error('Template must contain a root element');
      }

      Array.from(element.attributes).forEach(attr => {
        if (!newElement.hasAttribute(attr.name)) {
          newElement.setAttribute(attr.name, attr.value);
        }
      });

      const childComponents = newElement.querySelectorAll('[data-component]');
      childComponents.forEach(async (child) => {
        const componentName = child.getAttribute('data-component');
        if (this.has(componentName)) {
          const childInstance = await this.mount(child, componentName);
          if (childInstance && childInstance !== context) {
            childInstance._parentComponent = context;
            context._childComponents?.add(childInstance);
          }
        }
      });

      // lifecycle-first cleanup: destroy any element/form instances inside the old element
      try {
        const elementManager = Now.getManager('element');
        const formManager = Now.getManager('form');

        if (elementManager && typeof elementManager.destroyByElement === 'function') {
          elementManager.destroyByElement(element);
        }

        if (formManager && typeof formManager.destroyFormByElement === 'function') {
          formManager.destroyFormByElement(element);
        }
      } catch (err) {
        this.handleError('Error during pre-replace cleanup', 'processTemplate', {error: err});
      }

      if (element.parentNode) {
        element.parentNode.replaceChild(newElement, element);
        this.rekeyInstance(element, newElement);
      }

      context.element = newElement;

      // After insertion, scan the new subtree to initialize elements/forms that require enhancement
      try {
        const elementManager = Now.getManager('element');
        const formManager = Now.getManager('form');

        if (elementManager && typeof elementManager.scan === 'function') {
          elementManager.scan(newElement);
        }

        if (formManager && typeof formManager.scan === 'function') {
          formManager.scan(newElement);
        }
      } catch (err) {
        this.handleError('Error during post-replace scan', 'processTemplate', {error: err});
      }

      return newElement;

    } catch (error) {
      this.handleError(error, 'processTemplate', {element, definition, context});
      return element;
    }
  },

  /**
   * Asks the definition whether the existing element can be used as-is instead
   * of being replaced by the template.
   *
   * @param {HTMLElement} element - Host element
   * @param {Object} definition - Processed definition
   * @returns {boolean} True when the element is acceptable
   */
  isValidComponentElement(element, definition) {
    return definition.validElement ?
      definition.validElement(element) : false;
  },

  /**
   * Prepares an element the component keeps instead of replacing: applies its
   * class and ARIA attributes, then runs the setupElement hook.
   *
   * @param {HTMLElement} element - Host element
   * @param {Object} definition - Processed definition
   * @param {Object} state - Instance state
   * @returns {HTMLElement} The same element
   */
  setupExistingElement(element, definition, state) {
    this.setupElementAttributes(element, definition);

    if (definition.setupElement) {
      definition.setupElement(element, state);
    }

    return element;
  },

  /**
   * Resolves the template of a component from the definition, data-template, or
   * the element referenced by data-template-id, in that order.
   *
   * @param {HTMLElement} element - Host element
   * @param {Object} definition - Processed definition
   * @returns {string|null} Template markup, or null when there is none
   */
  getTemplate(element, definition) {
    if (definition.template) {
      return definition.template;
    }

    const inlineTemplate = element.getAttribute('data-template');
    if (inlineTemplate) {
      return inlineTemplate;
    }

    const templateId = element.getAttribute('data-template-id');
    if (templateId) {
      const templateEl = document.getElementById(templateId);
      return templateEl?.innerHTML || null;
    }

    return null;
  },

  /**
   * Lets a definition supply its own host element; returns the original when it
   * does not.
   *
   * @param {HTMLElement} element - Host element
   * @param {Object} definition - Processed definition
   * @param {Object} state - Instance state
   * @returns {HTMLElement} Element to use
   */
  createDefaultElement(element, definition, state) {
    return definition.createDefaultElement ?
      definition.createDefaultElement(element, state) : element;
  },

  /**
   * Renders a template string through TemplateManager, reusing the cached
   * processor when template caching is enabled.
   *
   * @param {string} template - Template markup
   * @param {Object} state - Data to interpolate
   * @returns {*} Result from TemplateManager
   */
  processTemplateString(template, state) {
    if (this.config.templateCache) {
      let processor = this.templateCache.get(template);
      if (!processor) {
        processor = TemplateManager.processTemplateString(template, state);
        this.templateCache.set(template, processor);
      }
      return processor;
    }

    return TemplateManager.processTemplateString(template, state);
  },

  /**
   * Adds the component-<name> class and the ARIA attributes the definition
   * declares.
   *
   * @param {HTMLElement} element - Host element
   * @param {Object} definition - Processed definition
   * @returns {void}
   */
  setupElementAttributes(element, definition) {
    element.classList.add(`component-${definition.name}`);

    if (definition.aria) {
      Object.entries(definition.aria).forEach(([key, value]) => {
        element.setAttribute(`aria-${key}`, value);
      });
    }
  },

  /**
   * Reports an error to ErrorManager, tagged with the method it came from.
   *
   * @param {string|Error} message - Message or error
   * @param {string} type - Method name, used to build the context
   * @param {Object} [data={}] - Extra data for the report
   * @returns {*} Result of ErrorManager.handle
   */
  handleError(message, type, data = {}) {
    return ErrorManager.handle(message, {
      context: `ComponentManager.${type}`,
      type: 'error:component',
      data
    });
  },

  /**
   * Parses the template of a component into a virtual node tree.
   *
   * Entry point of the virtual-DOM path (createVNode, patch, createRealNode),
   * which nothing currently calls: renderInstance() renders through
   * TemplateManager instead.
   *
   * @param {Object} instance - Component instance
   * @returns {Object|null} Root virtual node, or null when parsing failed
   */
  createVNode(instance) {
    try {
      const parser = new DOMParser();
      const doc = parser.parseFromString(instance._definition.template, 'text/html');
      const templateElement = doc.body.firstChild;

      return this.elementToVNode(templateElement, instance.state);
    } catch (error) {
      this.handleError('Failed to create virtual node', 'createVNode', {error});
      return null;
    }
  },

  /**
   * Converts a DOM element and its children into virtual nodes, interpolating
   * {{ }} expressions in text against the state.
   *
   * @param {Element} element - Element to convert
   * @param {Object} state - Data to interpolate
   * @returns {Object|null} Virtual node, or null when the element is missing
   */
  elementToVNode(element, state) {
    if (!element) return null;

    const vnode = {
      tag: element.tagName.toLowerCase(),
      props: this.getElementProps(element),
      children: [],
      text: null
    };

    if (element.childNodes.length === 0 ||
      (element.childNodes.length === 1 && element.childNodes[0].nodeType === 3)) {
      vnode.text = this.processTextContent(element.textContent, state);
      return vnode;
    }

    Array.from(element.childNodes).forEach(child => {
      if (child.nodeType === 3) {
        const text = this.processTextContent(child.textContent, state);
        if (text.trim()) {
          vnode.children.push({
            tag: null,
            props: null,
            children: [],
            text
          });
        }
      } else if (child.nodeType === 1) {
        vnode.children.push(this.elementToVNode(child, state));
      }
    });

    return vnode;
  },

  /**
   * Reads the attributes of an element as virtual node props, mapping class to
   * className and for to htmlFor.
   *
   * @param {Element} element - Element to read
   * @returns {Object} Props map
   */
  getElementProps(element) {
    const props = {};

    Array.from(element.attributes).forEach(attr => {
      let name = attr.name;
      let value = attr.value;

      if (name === 'class') {
        name = 'className';
        props[name] = value;
        return;
      }

      if (name === 'for') {
        name = 'htmlFor';
        props[name] = value;
        return;
      }

      if (!value) {
        props[name] = name;
        return;
      }

      props[name] = value;
    });

    return props;
  },

  /**
   * Replaces {{ path }} expressions in a string with values from the state,
   * leaving unresolved ones untouched.
   *
   * @param {string} text - Text to interpolate
   * @param {Object} state - Data source
   * @returns {string} Interpolated text
   */
  processTextContent(text, state) {
    return text.replace(/\{\{([^}]+)\}\}/g, (match, key) => {
      key = key.trim();
      const value = key.split('.').reduce((obj, k) => obj?.[k], state);
      return value !== undefined ? value : match;
    });
  },

  /**
   * Applies the difference between two virtual nodes to a real element,
   * recursing through the children.
   *
   * @param {Node} element - Element the old node rendered to
   * @param {Object|null} oldVNode - Previous virtual node
   * @param {Object|null} newVNode - Next virtual node; null removes the element
   * @returns {void}
   */
  patch(element, oldVNode, newVNode) {
    if (!oldVNode) {
      element.innerHTML = '';
      element.appendChild(this.createRealNode(newVNode));
      return;
    }

    if (!newVNode) {
      element.parentNode.removeChild(element);
      return;
    }

    if (this.shouldReplace(oldVNode, newVNode)) {
      const newElement = this.createRealNode(newVNode);
      element.parentNode.replaceChild(newElement, element);
      return;
    }

    this.updateProps(element, oldVNode.props, newVNode.props);

    const oldChildren = oldVNode.children || [];
    const newChildren = newVNode.children || [];
    const max = Math.max(oldChildren.length, newChildren.length);

    for (let i = 0; i < max; i++) {
      this.patch(
        element.childNodes[i],
        oldChildren[i],
        newChildren[i]
      );
    }
  },

  /**
   * Reports whether two virtual nodes differ enough that the element must be
   * replaced rather than updated.
   *
   * @param {Object} oldVNode - Previous virtual node
   * @param {Object} newVNode - Next virtual node
   * @returns {boolean} True when the element must be replaced
   */
  shouldReplace(oldVNode, newVNode) {
    return oldVNode.tag !== newVNode.tag ||
      oldVNode.text !== newVNode.text;
  },

  /**
   * Applies changed props to an element: removes what disappeared, assigns
   * className and on* handlers directly, and sets the rest as attributes.
   *
   * @param {Element} element - Element to update
   * @param {Object} [oldProps={}] - Props currently applied
   * @param {Object} [newProps={}] - Props to apply
   * @returns {void}
   */
  updateProps(element, oldProps = {}, newProps = {}) {
    Object.keys(oldProps).forEach(key => {
      if (!(key in newProps)) {
        element.removeAttribute(key);
      }
    });

    Object.entries(newProps).forEach(([key, value]) => {
      if (oldProps[key] === value) return;

      if (key === 'className') {
        element.className = value;
        return;
      }

      if (key.startsWith('on')) {
        element[key] = value;
        return;
      }

      if (value === false || value === null || value === undefined) {
        element.removeAttribute(key);
      } else {
        element.setAttribute(key, value);
      }
    });
  },

  /**
   * Builds a real DOM node from a virtual node, including its children.
   *
   * @param {Object|null} vnode - Virtual node
   * @returns {Node|null} Element or text node, null when the node is missing
   */
  createRealNode(vnode) {
    if (!vnode) return null;

    if (vnode.text !== null) {
      return document.createTextNode(vnode.text);
    }

    const element = document.createElement(vnode.tag);

    if (vnode.props) {
      Object.entries(vnode.props).forEach(([key, value]) => {
        if (key === 'className') {
          element.className = value;
          return;
        }

        if (key.startsWith('on')) {
          element[key] = value;
          return;
        }

        if (value === false || value === null || value === undefined) {
          element.removeAttribute(key);
        } else {
          element.setAttribute(key, value);
        }
      });
    }

    if (vnode.children) {
      vnode.children.forEach(child => {
        const childNode = this.createRealNode(child);
        if (childNode) {
          element.appendChild(childNode);
        }
      });
    }

    return element;
  },

  /**
   * Finds the instance whose path or templatePath matches.
   *
   * @param {string} path - Route or template path
   * @returns {Object|null} Matching instance, or null
   */
  getContextForPath(path) {
    for (const instance of this.instances.values()) {
      if (instance.path === path || instance.templatePath === path) {
        return instance;
      }
    }
    return null;
  },

  /**
   * Renders an instance through TemplateManager, wrapped in the beforeUpdate
   * and updated hooks.
   *
   * Does nothing while the instance is already updating or destroyed.
   *
   * @param {Object} instance - Component instance
   * @returns {Promise<void>}
   */
  async renderInstance(instance) {
    if (instance._updating || instance._destroyed) return;

    try {
      instance._updating = true;

      if (instance._definition.beforeUpdate) {
        await instance._definition.beforeUpdate.call(instance);
      }

      TemplateManager.processTemplate(instance.element, instance);

      if (instance._definition.updated) {
        await instance._definition.updated.call(instance);
      }

    } catch (error) {
      this.handleError('Component render error', 'renderInstance', {error});
    } finally {
      instance._updating = false;
    }
  },

  /**
   * Destroys the component mounted on an element: runs beforeDestroy, removes
   * its listeners, drops it from the registry, then runs destroyed and lets
   * TemplateManager release what it holds.
   *
   * @param {HTMLElement} element - Element the component is mounted on
   * @returns {Promise<void>}
   */
  async destroy(element) {
    const instance = this.instances.get(element);
    if (!instance || instance._destroyed) return;

    try {
      instance._destroying = true;

      if (instance._definition.beforeDestroy) {
        await instance._definition.beforeDestroy.call(instance);
      }

      if (!instance._events) {
        instance._events = new Map();
      }

      this.cleanupEvents(instance);

      this.instances.delete(element);

      instance._destroyed = true;
      instance._destroying = false;

      if (instance._definition.destroyed && !instance._destroyedCalled) {
        instance._destroyedCalled = true;
        await instance._definition.destroyed.call(instance);
      }

      const templateManager = Now.getManager('template');
      if (templateManager) {
        templateManager.onComponentDestroy(instance);
      }

    } catch (error) {
      this.handleError('Component destroy error', 'destroy', {error});

      instance._destroyed = true;
      instance._destroying = false;
      this.instances.delete(element);

    } finally {
      instance._events?.clear();
      instance._updateQueue?.clear();
      instance.element = null;
    }
  },

  /**
   * Cleanup components in a container element
   */
  async cleanup(container) {
    if (!container) return;

    try {
      const componentElements = container.querySelectorAll('[data-component]');

      for (const element of componentElements) {
        const instance = this.instances.get(element);
        if (instance && instance.state.initialized) {
          await this.destroy(element);
        }
      }

      for (const [element, instance] of this.instances.entries()) {
        if (container.contains(element) && instance.state.initialized) {
          await this.destroy(element);
        }
      }

    } catch (error) {
      this.handleError('Container cleanup error', 'cleanup', {error, container});
    }
  },

  /**
   * Reports whether a component name is registered.
   *
   * @param {string} name - Component name
   * @returns {boolean} True when the component exists
   */
  has(name) {
    return this.components.has(name);
  },

  /**
   * Returns a registered component definition.
   *
   * @param {string} name - Component name
   * @returns {Object|null} Processed definition, or null
   */
  get(name) {
    return this.components.get(name) || null;
  },

  /**
   * Reports whether an element carries a live, initialized instance.
   *
   * @param {HTMLElement} element - Element to check
   * @returns {boolean} True when the instance exists and was not destroyed
   */
  isComponentInitialized(element) {
    const instance = this.instances.get(element);
    return instance &&
      instance.state.initialized &&
      !instance._destroyed;
  }
  ,
  /**
   * Lists the live components inside a container.
   *
   * @param {ParentNode} [container=document] - Subtree to search
   * @returns {Object[]} Entries of {element, name, id}
   */
  getInitializedComponents(container = document) {
    const elements = container.querySelectorAll('[data-component]');
    const initialized = [];

    elements.forEach(element => {
      if (this.isComponentInitialized(element)) {
        const instance = this.instances.get(element);
        initialized.push({
          element,
          name: instance._definition.name,
          id: instance.id
        });
      }
    });

    return initialized;
  },

  /**
   * Reports the lifecycle stage of the component on an element.
   *
   * @param {HTMLElement} element - Element to inspect
   * @returns {string} 'not-found', 'destroyed', 'not-initialized', 'mounted'
   *   or 'initializing'
   */
  getComponentStatus(element) {
    const instance = this.instances.get(element);
    if (!instance) {
      return 'not-found';
    }

    if (instance._destroyed) {
      return 'destroyed';
    }

    if (!instance.state.initialized) {
      return 'not-initialized';
    }

    if (instance._mounted) {
      return 'mounted';
    }

    return 'initializing';
  },

  /**
   * Drops the cached virtual node of an instance and renders it again.
   *
   * @param {Object} instance - Component instance
   * @returns {Promise<void>|undefined} The render, or undefined when destroyed
   */
  forceUpdate(instance) {
    if (!instance || instance._destroyed) return;
    instance._vnode = null;
    return this.renderInstance(instance);
  },

  /**
   * Empties the template cache.
   *
   * @returns {void}
   */
  clearCache() {
    this.templateCache.clear();
  },

  /**
   * Attaches a render timing recorder to an instance, warning when a render
   * takes longer than one frame.
   *
   * @param {Object} instance - Component instance
   * @returns {void}
   */
  setupPerformanceMonitoring(instance) {
    const performance = {
      renders: 0,
      totalRenderTime: 0,
      lastRenderTime: 0,
      averageRenderTime: 0,
      slowRenderThreshold: 16,

      recordRender(startTime) {
        const renderTime = performance.now() - startTime;
        this.renders++;
        this.totalRenderTime += renderTime;
        this.lastRenderTime = renderTime;
        this.averageRenderTime = this.totalRenderTime / this.renders;

        if (renderTime > this.slowRenderThreshold) {
          console.warn(`Slow render detected for component ${instance.id}:`, {
            renderTime,
            averageRenderTime: this.averageRenderTime
          });
        }
      }
    };

    instance._performance = performance;
  },

  /**
   * Routes a component error to the nearest error boundary, walking up the
   * parent chain, and emits component:error when none handles it.
   *
   * @param {Error} error - Error that was thrown
   * @param {Object} instance - Instance the error came from
   * @param {string} [phase='unknown'] - Lifecycle phase, such as 'update'
   * @returns {Promise<void>}
   */
  async handleComponentError(error, instance, phase = 'unknown') {
    try {
      if (instance._errorBoundary?.handler) {
        await instance._errorBoundary.handler.call(instance, error);
        if (instance._errorBoundary.fallback && instance.element) {
          instance.element.innerHTML = await TemplateManager.loadFromServer(
            instance._errorBoundary.fallback
          );
        }
        return;
      }

      let parent = instance._parentComponent;
      while (parent) {
        if (parent._errorBoundary?.handler) {
          await parent._errorBoundary.handler.call(parent, error);
          return;
        }
        parent = parent._parentComponent;
      }

      EventManager.emit('component:error', {
        error,
        instance,
        phase
      });

    } catch (error) {
      this.handleError('Component error handling error', 'handleComponentError', {error});
    }
  },

  /**
   * Queues a re-render, batched into the next animation frame when batching is
   * enabled, and warns when an instance updates unusually often.
   *
   * @param {Object} instance - Component instance
   * @returns {void}
   */
  scheduleUpdate(instance) {
    if (instance._updating || instance._destroyed) return;

    instance._updateQueue.add(Date.now());

    if (instance._updateQueue.size > 10) {
      const updates = Array.from(instance._updateQueue);
      const timeSpan = updates[updates.length - 1] - updates[0];
      if (timeSpan < 1000) {
        console.warn('Rapid updates detected:', {
          component: instance.id,
          updates: instance._updateQueue.size,
          timeSpan
        });
      }
    }

    if (this.config.performance.batchUpdates) {
      if (!instance._updateScheduled) {
        instance._updateScheduled = true;
        requestAnimationFrame(() => {
          this.processUpdate(instance);
          instance._updateScheduled = false;
        });
      }
    } else {
      this.processUpdate(instance);
    }
  },

  /**
   * Runs one queued update: beforeUpdate, render, timing record, updated.
   *
   * Errors go to handleComponentError() so an error boundary can catch them.
   *
   * @param {Object} instance - Component instance
   * @returns {Promise<void>}
   */
  async processUpdate(instance) {
    if (instance._updating || instance._destroyed) return;

    try {
      instance._updating = true;
      const startTime = performance.now();

      if (instance._definition.beforeUpdate) {
        await instance._definition.beforeUpdate.call(instance);
      }

      await this.renderInstance(instance);

      if (instance._performance) {
        instance._performance.recordRender(startTime);
      }

      if (instance._definition.updated) {
        await instance._definition.updated.call(instance);
      }

    } catch (error) {
      await this.handleComponentError(error, instance, 'update');
    } finally {
      instance._updating = false;
      instance._updateQueue.clear();
    }
  },

  /**
   * Releases everything an instance holds: child components first, then its
   * listeners, reactive state, virtual node, queues and refs.
   *
   * @param {Object} instance - Component instance
   * @returns {Promise<void>}
   */
  async cleanupComponent(instance) {
    if (!instance || instance._destroyed) return;

    try {
      for (const child of Array.from(instance._childComponents || [])) {
        await this.cleanupComponent(child);
      }

      if (instance._parentComponent) {
        instance._parentComponent._childComponents?.delete(instance);
      }

      instance._events.forEach((handler, event) => {
        instance.element.removeEventListener(event, handler);
      });
      instance._events.clear();

      if (window.ReactiveManager) {
        ReactiveManager.cleanupComponentReactivity(instance);
      }

      instance._vnode = null;
      instance._errorBoundary = null;
      instance._childComponents.clear();
      instance._parentComponent = null;
      instance._updateQueue.clear();
      instance.refs = null;

      instance._destroyed = true;

    } catch (error) {
      this.handleError('Component cleanup error', 'cleanupComponent', error);
    }
  },

  /**
   * Resolves once an element matching the selector exists in the document,
   * immediately when it already does.
   *
   * @param {string} selector - CSS selector to wait for
   * @returns {Promise<Element>} The matching element
   */
  waitForElement(selector) {
    return new Promise(resolve => {
      if (document.querySelector(selector)) {
        return resolve(document.querySelector(selector));
      }

      const observer = new MutationObserver(mutations => {
        if (document.querySelector(selector)) {
          observer.disconnect();
          resolve(document.querySelector(selector));
        }
      });

      observer.observe(document.body, {
        childList: true,
        subtree: true
      });
    });
  },

  /**
   * Compares two values structurally, including symbol keys, and treats
   * accessor properties as equal only when they share the same getter and
   * setter.
   *
   * @param {*} obj1 - First value
   * @param {*} obj2 - Second value
   * @returns {boolean} True when the values are equivalent
   */
  deepCompare(obj1, obj2) {
    if (obj1 === obj2) return true;

    if (typeof obj1 !== "object" || typeof obj2 !== "object" || obj1 === null || obj2 === null) {
      return obj1 === obj2;
    }

    const keys1 = Reflect.ownKeys(obj1);
    const keys2 = Reflect.ownKeys(obj2);

    if (keys1.length !== keys2.length) return false;

    for (const key of keys1) {
      if (!keys2.includes(key)) return false;

      const desc1 = Object.getOwnPropertyDescriptor(obj1, key);
      const desc2 = Object.getOwnPropertyDescriptor(obj2, key);

      if (desc1.get || desc1.set || desc2.get || desc2.set) {
        if (desc1.get !== desc2.get || desc1.set !== desc2.set) return false;
      } else if (!ComponentManager.deepCompare(obj1[key], obj2[key])) {
        return false;
      }
    }

    return true;
  },

  /**
   * Computes the shared keys, the differing keys and the deep equality of two
   * objects.
   *
   * @param {Object} obj1 - First object
   * @param {Object} obj2 - Second object
   * @returns {Object} {isEqual, sameKeys, diffKeys}
   */
  compareObjects(obj1, obj2) {
    const obj1Keys = Reflect.ownKeys(obj1);
    const obj2Keys = Reflect.ownKeys(obj2);

    const sameKeys = obj1Keys.filter(key => obj2Keys.includes(key));
    const diffKeys = [
      ...obj1Keys.filter(key => !obj2Keys.includes(key)),
      ...obj2Keys.filter(key => !obj1Keys.includes(key))
    ];

    return {
      isEqual: ComponentManager.deepCompare(obj1, obj2),
      sameKeys,
      diffKeys
    };
  },

  /**
   * Compares the manager itself with an instance, for debugging.
   *
   * @param {Object} instance - Object to compare against
   * @returns {Object} {isEqual, sameKeys, diffKeys}
   */
  _compareObjects(instance) {
    const thisKeys = Reflect.ownKeys(this);
    const instanceKeys = Reflect.ownKeys(instance);

    const sameKeys = thisKeys.filter(key => instanceKeys.includes(key));
    const diffKeys = [
      ...thisKeys.filter(key => !instanceKeys.includes(key)),
      ...instanceKeys.filter(key => !thisKeys.includes(key))
    ];

    return {
      isEqual: ComponentManager.deepCompare(this, instance),
      sameKeys,
      diffKeys
    };
  },

  /**
   * Summarizes the live instances: their elements, component names, lifecycle
   * status, and the count per status.
   *
   * @returns {Object} {totalInstances, stateCounts, instances, components}
   */
  getDebugInfo() {
    const instances = Array.from(this.instances.entries()).map(([element, instance]) => ({
      element: element.tagName + (element.id ? `#${element.id}` : ''),
      component: instance._definition?.name,
      id: instance.id,
      initialized: instance.state.initialized,
      mounted: instance._mounted,
      destroyed: instance._destroyed,
      status: this.getComponentStatus(element)
    }));

    const stateCounts = instances.reduce((acc, inst) => {
      acc[inst.status] = (acc[inst.status] || 0) + 1;
      return acc;
    }, {});

    return {
      totalInstances: this.instances.size,
      stateCounts,
      instances,
      components: Array.from(this.components.keys())
    };
  }
};

if (window.Now?.registerManager) {
  Now.registerManager('component', ComponentManager);
}

window.ComponentManager = ComponentManager;
