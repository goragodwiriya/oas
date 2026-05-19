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

  parseEventSelector(selector) {
    const parts = selector.trim().split(/\s+/);
    const eventName = parts[0];
    const targetSelector = parts.slice(1).join(' ') || null;

    return [eventName, targetSelector];
  },

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
          await this.mount(child, componentName);
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

  isValidComponentElement(element, definition) {
    return definition.validElement ?
      definition.validElement(element) : false;
  },

  setupExistingElement(element, definition, state) {
    this.setupElementAttributes(element, definition);

    if (definition.setupElement) {
      definition.setupElement(element, state);
    }

    return element;
  },

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

  createDefaultElement(element, definition, state) {
    return definition.createDefaultElement ?
      definition.createDefaultElement(element, state) : element;
  },

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

  setupElementAttributes(element, definition) {
    element.classList.add(`component-${definition.name}`);

    if (definition.aria) {
      Object.entries(definition.aria).forEach(([key, value]) => {
        element.setAttribute(`aria-${key}`, value);
      });
    }
  },

  handleError(message, type, data = {}) {
    return ErrorManager.handle(message, {
      context: `ComponentManager.${type}`,
      type: 'error:component',
      data
    });
  },

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

  processTextContent(text, state) {
    return text.replace(/\{\{([^}]+)\}\}/g, (match, key) => {
      key = key.trim();
      const value = key.split('.').reduce((obj, k) => obj?.[k], state);
      return value !== undefined ? value : match;
    });
  },

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

  shouldReplace(oldVNode, newVNode) {
    return oldVNode.tag !== newVNode.tag ||
      oldVNode.text !== newVNode.text;
  },

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

  getContextForPath(path) {
    for (const instance of this.instances.values()) {
      if (instance.path === path || instance.templatePath === path) {
        return instance;
      }
    }
    return null;
  },

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

  has(name) {
    return this.components.has(name);
  },

  get(name) {
    return this.components.get(name) || null;
  },

  isComponentInitialized(element) {
    const instance = this.instances.get(element);
    return instance &&
      instance.state.initialized &&
      !instance._destroyed;
  }
  ,
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

  forceUpdate(instance) {
    if (!instance || instance._destroyed) return;
    instance._vnode = null;
    return this.renderInstance(instance);
  },

  clearCache() {
    this.templateCache.clear();
  },

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

  async handleComponentError(error, instance, phase = 'unknown') {
    try {
      if (instance._errorBoundary) {
        await instance._errorBoundary.handler.call(instance, error);
        if (instance._errorBoundary.fallback) {
          instance.element.innerHTML = await this.loadTemplate(
            instance._errorBoundary.fallback
          );
        }
        return;
      }

      let parent = instance._parentComponent;
      while (parent) {
        if (parent._errorBoundary) {
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

  async cleanupComponent(instance) {
    if (!instance || instance._destroyed) return;

    try {
      for (const child of instance._childComponents) {
        await this.cleanupComponent(child);
      }

      if (instance._parentComponent) {
        instance._parentComponent._childComponents.delete(instance);
      }

      instance._events.forEach((handler, event) => {
        instance.element.removeEventListener(event, handler);
      });
      instance._events.clear();

      if (window.ReactiveManager) {
        ReactiveManager.cleanup(instance.state);
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

  compareObjects(obj1, obj2) {
    const obj1Keys = Reflect.ownKeys(obj1);
    const obj2Keys = Reflect.ownKeys(obj2);

    const sameKeys = obj1Keys.filter(key => obj2Keys.includes(key));
    const diffKeys = [
      ...obj1Keys.filter(key => !obj2Keys.includes(key)),
      ...obj2Keys.filter(key => !obj1Keys.includes(key))
    ];

    const isEqual = ComponentManager.deepCompare(obj1, obj2);
  },

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
