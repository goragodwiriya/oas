const ElementManager = {
  state: {
    elements: new Map(),
    instances: new Map(),
    elementIndex: new WeakMap(),
    containerMap: new WeakMap(), // Track elements by container
    initialized: false,
    privateStates: new WeakMap()
  },

  init(options = {}) {
    if (this.state.initialized) return this;

    this.config = Object.assign({}, this.config, options);

    if (window.ElementFactory && ElementFactory._privateState) {
      this.state.privateStates = ElementFactory._privateState;
    }

    this.state.initialized = true;
    return this;
  },

  registerElement(type, implementation) {
    if (!type || typeof type !== 'string') {
      throw new Error('Element type must be a string');
    }

    if (!implementation) {
      throw new Error('Element implementation must be an object');
    }

    implementation.manager = this;
    if (typeof implementation.init === 'function') {
      implementation.init(this);
    }
    this.state.elements.set(type, implementation);

    return this;
  },

  unRegisterElement(type) {
    this.state.elements.delete(type);
    return this;
  },

  create(type, config = {}) {
    if (!this.state.initialized) {
      throw ErrorManager.handle('ElementManager must be initialized first', {
        context: 'ElementManager.create',
      });
    }

    try {
      const elementClass = this.state.elements.get(type);
      config.elementType = type;

      let instance;
      if (elementClass) {
        instance = elementClass.create(config);
      } else if (window.ElementFactory) {
        instance = ElementFactory.create(config);
      } else {
        throw new Error(`No implementation found for element type: ${type}`);
      }

      if (instance && instance.element && instance.element.id) {
        this.state.instances.set(instance.element.id, instance);
      }

      instance.wasCreated = true;
      return instance;

    } catch (error) {
      ErrorManager.handle(error, {
        context: 'ElementManager.create',
        data: {type, config}
      });
      return null;
    }
  },

  enhance(element, config = {}) {
    try {
      if (!element || !(element instanceof HTMLElement)) {
        throw new Error('Invalid element');
      }

      // Already enhanced: prefer element identity (WeakMap) over id-based lookup
      try {
        const byElement = this.state.elementIndex.get(element);
        if (byElement) {
          // instance already associated with this DOM node
          return byElement;
        }
      } catch (e) {
        // ignore weakmap errors
      }

      // Fallback: if element has an id, check id-based registry
      if (element.id) {
        const existingInstance = this.getInstance(element.id);
        if (existingInstance) {
          // If the instance's element is different or no longer connected, treat it as stale and destroy it
          const instEl = existingInstance.element;
          const isSameNode = instEl === element;
          const isConnected = instEl && instEl.isConnected;

          if (!isSameNode || !isConnected) {
            // stale instance — destroy to allow re-enhancement
            try {
              this.destroy(element.id);
            } catch (err) {
              // best effort
              console.warn('Failed to destroy stale element instance for id', element.id, err);
            }
          } else {
            // same element node: already enhanced
            return existingInstance;
          }
        }
      }

      // Opt-in: require data-element for auto-enhance when in auto mode
      // Determine the factory type for this element.
      // For <select> and <textarea>, the HTML "type" attribute is not valid / meaningful,
      // so only data-element should override the tag-based factory.
      // For <input> and <button>, getAttribute('type') is the standard way to get the type.
      const tagName = element.tagName.toLowerCase();
      let type;
      if (tagName === 'select' || tagName === 'textarea') {
        type = element.getAttribute('data-element') || tagName;
      } else {
        type = element.getAttribute('data-element') || element.getAttribute('type') || element.type || tagName;
      }

      // Special handling for select elements - they report type as "select-one" or "select-multiple"
      // but are registered as "select" or handle multiple via MultiSelectElementFactory.
      // Also guard against data-element routing a <select> to an incompatible input-only factory
      // (e.g., data-element="number" on a <select> would crash NumberElementFactory).
      if (tagName === 'select') {
        const selectTypes = ['select', 'select-multiple', 'tags', 'search'];
        if (!selectTypes.includes(type) || !this.state.elements.has(type)) {
          type = element.multiple ? 'select-multiple' : 'select';
        } else if (element.multiple && type === 'select') {
          // <select multiple> without explicit data-element should use MultiSelectElementFactory
          type = 'select-multiple';
        }
      }

      // Similarly guard <textarea> from being routed to incompatible factories
      if (tagName === 'textarea' && type !== 'textarea' && type !== 'richtext') {
        type = 'textarea';
      }

      config.elementType = type;

      const elementClass = this.state.elements.get(type);

      if (!elementClass) {
        if (window.ElementFactory) {
          const instance = ElementFactory.enhance(element, config);
          if (instance) {
            instance.wasCreated = false;  // Mark as enhanced, not created
            // index by element weak map
            try {this.state.elementIndex.set(element, instance);} catch (e) {}
            if (element.id) this.state.instances.set(element.id, instance);
          }
          return instance;
        } else {
          ErrorManager.handle(`No implementation found for ${type}`, {
            context: 'ElementManager.enhance',
            data: {element, config}
          });
          return null;
        }
      }

      const instance = elementClass.enhance(element, config);

      if (instance) {
        // index by element weak map
        try {this.state.elementIndex.set(element, instance);} catch (e) {}
        if (element.id) this.state.instances.set(element.id, instance);
      }

      instance.wasCreated = false;
      return instance;

    } catch (error) {
      ErrorManager.handle(error, {
        context: 'ElementManager.enhance',
        data: {element, config}
      });
      return null;
    }
  },

  getInstance(id) {
    return this.state.instances.get(id);
  },

  getInstanceByElement(element) {
    if (!element) return null;
    const inst = this.state.elementIndex.get(element);
    if (inst) {
      return inst;
    }
    const byId = element?.id ? this.state.instances.get(element.id) : null;

    return byId;
  },

  removeInstance(id) {
    const instance = this.state.instances.get(id);
    if (!instance) return false;

    if (window.ElementFactory && ElementFactory._privateState) {
      ElementFactory._privateState.delete(instance.element);
    }

    try {this.state.elementIndex.delete(instance.element);} catch (e) {}
    return this.state.instances.delete(id);
  },

  hasElement(type) {
    return this.state.elements.has(type);
  },

  // Determine whether element should be enhanced (opt-in via data-element or registered type)
  shouldEnhance(element) {
    if (!element) return false;

    // Check for explicit data-element attribute
    if (element.dataset && element.dataset.element) return true;

    // Check if element type is registered
    // Use getAttribute('type') to capture custom types like "currency" that browsers normalize to "text"
    const type = element.getAttribute('type') || element.type || element.tagName.toLowerCase();
    if (this.state.elements.has(type)) return true;

    return false;
  },

  // Scan container for elements with data-element and enhance them
  scan(container = document) {
    if (!container || !container.querySelectorAll) return [];

    // Query for data-element AND standard form elements that might match registered types
    const selector = '[data-element], input, select, textarea';
    const found = Array.from(container.querySelectorAll(selector));

    // Track elements by container
    const elementIds = [];

    found.forEach(el => {
      if (!this.state.elementIndex.has(el)) {
        const instance = this.enhance(el);
        if (instance && instance.element && instance.element.id) {
          elementIds.push(instance.element.id);
        }
      }
    });

    // Store container -> elementIds mapping
    if (elementIds.length > 0 && container !== document) {
      try {
        const existing = this.state.containerMap.get(container) || [];
        this.state.containerMap.set(container, [...existing, ...elementIds]);
      } catch (e) {
        // WeakMap error, ignore
      }
    }

    return found;
  },

  // Destroy all elements in a specific container
  destroyContainer(container) {
    if (!container) return false;

    try {
      const elementIds = this.state.containerMap.get(container);
      if (elementIds && Array.isArray(elementIds)) {
        elementIds.forEach(id => {
          try {
            this.destroy(id);
          } catch (err) {
            // Element might already be destroyed, ignore
          }
        });

        // Clear container mapping
        this.state.containerMap.delete(container);
        return true;
      }
    } catch (e) {
      // WeakMap error or other issues
    }

    return false;
  },

  // Destroy element by element reference
  destroyByElement(el) {
    if (!el) return false;
    const inst = this.getInstanceByElement(el);
    if (!inst) return false;
    const id = inst.element?.id;
    if (id) return this.destroy(id);
    // if no id, try to find corresponding id in instances map
    for (const [key, value] of this.state.instances.entries()) {
      if (value === inst) return this.destroy(key);
    }
    return false;
  },

  destroy(id) {
    const instance = this.state.instances.get(id);
    if (!instance) return false;

    try {
      const {element} = instance;

      // Call instance.destroy but don't let it abort manager-level cleanup
      if (typeof instance.destroy === 'function') {
        try {
          instance.destroy();
        } catch (err) {
          console.warn('Instance destroy threw an error, continuing manager cleanup', err);
        }
      }

      // Remove any event handlers attached to the element (manager-level)
      try {
        EventSystemManager.removeElementHandlers(element);
      } catch (err) {
        // swallow to continue cleanup
        console.warn('Failed to remove element handlers during destroy', err);
      }

      if (window.ElementFactory && ElementFactory._privateState) {
        ElementFactory._privateState.delete(instance.element);
      }

      ['comment', 'label', 'container', 'wrapper'].forEach(prop => {
        if (element[prop]) {
          if (instance.wasCreated === false) {
            delete element[prop];
          } else if (element[prop].parentNode) {
            element[prop].parentNode.removeChild(element[prop]);
          }
        }
      });
      if (instance.wasCreated === true) {
        if (element.parentNode) {
          element.parentNode.removeChild(element);
        }
      }

      try {this.state.elementIndex.delete(instance.element);} catch (e) {}
      this.state.instances.delete(id);
      return true;

    } catch (error) {
      ErrorManager.handle(error, {
        context: 'ElementManager.destroy',
        data: {id}
      });
      return false;
    }
  },

  cleanup() {
    Array.from(this.state.instances.keys()).forEach(id => {
      this.destroy(id);
    });

    this.state.elements.clear();
    this.state.instances.clear();
  }
};

if (window.Now?.registerManager) {
  Now.registerManager('element', ElementManager);
}

// Expose globally
window.ElementManager = ElementManager;
