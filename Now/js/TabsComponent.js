/**
 * TabsComponent - Production-ready tabs component for Now.js
 *
 * Features:
 * - Automatic initialization via data-component="tabs"
 * - Full ARIA accessibility support
 * - Keyboard navigation (Arrow keys, Home, End)
 * - Configurable through data attributes
 * - Event callbacks for lifecycle hooks
 * - Animation support
 * - Dynamic tab management
 * - URL hash navigation (deep linking, back/forward support)
 *
 * @example
 * // HTML Usage
 * <div data-component="tabs" data-default-tab="tab2">
 *   <div class="tab-buttons" role="tablist">
 *     <button class="tab-button" data-tab="tab1" role="tab">Tab 1</button>
 *     <button class="tab-button" data-tab="tab2" role="tab">Tab 2</button>
 *   </div>
 *   <div class="tab-content">
 *     <div class="tab-pane" data-tab="tab1" role="tabpanel">Content 1</div>
 *     <div class="tab-pane" data-tab="tab2" role="tabpanel">Content 2</div>
 *   </div>
 * </div>
 *
 * @example
 * // JavaScript API
 * const tabs = TabsComponent.create(element, {
 *   defaultTab: 'tab2',
 *   keyboard: true,
 *   onTabChange: (tabId) => console.log('Changed to:', tabId)
 * });
 */
const TabsComponent = {
  /**
   * Default configuration options
   */
  config: {
    // Basic settings
    defaultTab: null,        // Default active tab (null = first tab)
    keyboard: true,          // Enable keyboard navigation
    animation: true,         // Enable transition animations
    animationDuration: 300,  // Animation duration in ms

    // Selectors (customizable for different CSS styles)
    buttonSelector: '.tab-button',  // Selector for tab buttons
    panelSelector: '.tab-pane',     // Selector for tab panels

    // Behavior
    hash: true,             // Sync active tab with URL hash
    hashPrefix: '',          // Prefix for hash (e.g., 'tab-' for #tab-settings)
    destroyOnHidden: false,  // Destroy instance when element is removed
    lazy: false,             // Lazy load tab content

    // Accessibility
    ariaLabel: 'Tabs',       // ARIA label for tablist

    // Event callbacks
    onInit: null,            // Called when initialized
    onTabChange: null,       // Called when tab changes (after)
    beforeTabChange: null,   // Called before tab changes (can cancel)
    onDestroy: null,         // Called when destroyed

    // Advanced
    orientation: 'horizontal' // 'horizontal' or 'vertical'
  },

  /**
   * Component state
   */
  state: {
    instances: new Map(),    // All tab instances
    initialized: false,      // Whether component is initialized
    observer: null           // MutationObserver for dynamic tabs
  },

  /**
   * Initialize the TabsComponent
   * @param {Object} options - Global configuration options
   */
  init(options = {}) {
    if (this.state.initialized) return;

    // Merge options with defaults
    Object.assign(this.config, options);

    // Initialize existing elements
    this.initElements();

    // Setup MutationObserver for dynamically added tabs
    this.setupObserver();

    this.state.initialized = true;
  },

  /**
   * Initialize all elements with data-component="tabs"
   */
  initElements() {
    document.querySelectorAll('[data-component="tabs"]').forEach(element => {
      if (!this.state.instances.has(element)) {
        this.create(element);
      }
    });
  },

  /**
   * Create a new tabs instance
   * @param {HTMLElement} element - The tabs container element
   * @param {Object} options - Instance-specific options
   * @returns {Object} The tabs instance
   */
  create(element, options = {}) {
    if (!element) {
      console.error('TabsComponent.create: element is required');
      return null;
    }

    // Check if already initialized
    if (this.state.instances.has(element)) return this.state.instances.get(element);

    // Extract options from data attributes
    const dataOptions = this.extractOptionsFromElement(element);

    // Merge options: defaults < data attributes < passed options
    const instanceConfig = {
      ...this.config,
      ...dataOptions,
      ...options
    };

    // Find tab buttons and panels using configurable selectors
    const buttonSelector = instanceConfig.buttonSelector;
    const panelSelector = instanceConfig.panelSelector;
    const buttons = element.querySelectorAll(buttonSelector);
    const panels = element.querySelectorAll(panelSelector);

    if (buttons.length === 0) {
      console.error(`TabsComponent: No tab buttons found (${buttonSelector})`);
      return null;
    }

    if (panels.length === 0) {
      console.error(`TabsComponent: No tab panels found (${panelSelector})`);
      return null;
    }

    // Create instance
    const instance = {
      element,
      config: instanceConfig,
      buttons: Array.from(buttons),
      panels: Array.from(panels),
      activeTab: null,
      tabIds: [],
      destroyed: false
    };

    // Setup the instance
    this.setup(instance);

    // Store instance
    this.state.instances.set(element, instance);

    // Call onInit callback
    if (typeof instance.config.onInit === 'function') {
      instance.config.onInit.call(instance);
    }

    return instance;
  },

  /**
   * Setup a tabs instance
   * @param {Object} instance - The tabs instance
   */
  setup(instance) {
    // Resolve radio-linked labels: sets data-tab on <label> buttons that lack it,
    // and returns a Map<button, radioInput> for later use.
    instance.radioMap = this.resolveRadioButtons(instance.element, instance.buttons);

    // Extract tab IDs
    instance.tabIds = instance.buttons.map(btn => btn.dataset.tab).filter(Boolean);

    if (instance.tabIds.length === 0) {
      console.error('TabsComponent: No valid tab IDs found (data-tab attribute)');
      return;
    }

    // Setup ARIA attributes
    this.setupAccessibility(instance);

    // Setup event handlers
    this.setupEventHandlers(instance);

    // Setup hash navigation if enabled
    if (instance.config.hash) {
      this.setupHashNavigation(instance);
    }

    // Determine initial active tab
    let initialTab = this.getInitialTab(instance);

    // Activate initial tab
    this.switchTab(instance, initialTab, true);

    // Add public methods to instance
    instance.switchTab = (tabId) => this.switchTab(instance, tabId);
    instance.getActiveTab = () => instance.activeTab;
    instance.destroy = () => this.destroy(instance);
    instance.refresh = () => this.refresh(instance);
  },

  /**
   * Resolve radio-linked tab buttons.
   *
   * Logic (driven by radio inputs, NOT by the button list):
   *  1. Scan every <input type="radio"> inside the container.
   *  2. From each radio → find its <label> via label[for="<radioId>"].
   *  3. From each radio → find its panel via the next sibling with class "tab-pane".
   *  4. Derive tabId by stripping the `name + "_"` prefix from the radio id
   *     (e.g. id="tab_th", name="tab"  →  tabId="th").
   *  5. Set data-tab on both the label and the panel so the rest of the
   *     component (setupAccessibility, switchTab, …) works unchanged.
   *  6. Return a Map<labelElement, radioInputElement> used by setupEventHandlers
   *     and switchTab.
   *
   * @param {HTMLElement}   element - The tabs container element
   * @param {HTMLElement[]} buttons - The resolved .tab-button elements (unused
   *                                  here but kept for API consistency)
   * @returns {Map<HTMLElement, HTMLInputElement>}
   */
  resolveRadioButtons(element, buttons) {
    const radioMap = new Map();

    element.querySelectorAll('input[type="radio"]').forEach(radio => {
      if (!radio.id) return;

      // 1. Find the label that controls this radio (anywhere in the container)
      const label = element.querySelector(`label[for="${CSS.escape(radio.id)}"]`);
      if (!label) return;

      // 2. Derive tabId: strip `name + "_"` prefix when present
      //    e.g.  id="tab_th"  name="tab"  →  tabId="th"
      const prefix = radio.name ? radio.name + '_' : '';
      const tabId = (prefix && radio.id.startsWith(prefix))
        ? radio.id.slice(prefix.length)
        : radio.id;

      // 3. Tag the label so buttonSelector → tabId mapping works
      if (!label.dataset.tab) label.dataset.tab = tabId;

      // 4. Find the panel: first sibling with class "tab-pane" after the radio
      let panel = radio.nextElementSibling;
      while (panel && !panel.classList.contains('tab-pane')) {
        panel = panel.nextElementSibling;
      }
      if (panel && !panel.dataset.tab) panel.dataset.tab = tabId;

      radioMap.set(label, radio);
    });

    return radioMap;
  },

  /**
   * Setup accessibility (ARIA) attributes
   * @param {Object} instance - The tabs instance
   */
  setupAccessibility(instance) {
    const {element, buttons, panels, config} = instance;
    const isRadioMode = instance.radioMap && instance.radioMap.size > 0;

    // Find or create tablist container
    let tablist = element.querySelector('[role="tablist"]');
    if (!tablist) {
      tablist = buttons[0].parentElement;
      tablist.setAttribute('role', 'tablist');
    }

    // Set orientation
    tablist.setAttribute('aria-orientation', config.orientation);

    if (config.ariaLabel) {
      tablist.setAttribute('aria-label', config.ariaLabel);
    }

    // Setup each tab button
    buttons.forEach((button, index) => {
      const tabId = button.dataset.tab;
      const panel = panels.find(p => p.dataset.tab === tabId);

      if (!panel) {
        console.warn(`TabsComponent: No panel found for tab "${tabId}"`);
        return;
      }

      // Generate unique IDs if not present
      const buttonId = button.id || `tab-${tabId}-${Date.now()}-${index}`;
      const panelId = panel.id || `panel-${tabId}-${Date.now()}-${index}`;

      button.id = buttonId;
      panel.id = panelId;

      // Set ARIA attributes on button
      button.setAttribute('role', 'tab');
      button.setAttribute('aria-controls', panelId);
      button.setAttribute('aria-selected', 'false');
      button.setAttribute('tabindex', '-1');

      // Set ARIA attributes on panel
      panel.setAttribute('role', 'tabpanel');
      panel.setAttribute('aria-labelledby', buttonId);
      panel.setAttribute('tabindex', '0');

      // In radio mode CSS handles panel visibility — don't set hidden
      if (!isRadioMode) {
        panel.hidden = true;
      }
    });
  },

  /**
   * Setup event handlers
   * @param {Object} instance - The tabs instance
   */
  setupEventHandlers(instance) {
    const {buttons, config} = instance;

    buttons.forEach(button => {
      const radioInput = instance.radioMap && instance.radioMap.get(button);

      if (radioInput) {
        // Radio-input mode: react to the native <input type="radio"> change event.
        // The label's click naturally checks the radio, so we don't need (or want)
        // a click handler that calls e.preventDefault().
        const changeHandler = () => {
          if (radioInput.checked) {
            this.switchTab(instance, button.dataset.tab);
          }
        };
        radioInput.addEventListener('change', changeHandler);

        // Store handler reference for cleanup in destroy()
        if (!instance._radioHandlers) instance._radioHandlers = new Map();
        instance._radioHandlers.set(radioInput, changeHandler);
      } else {
        // Standard button mode: handle click
        button.addEventListener('click', (e) => {
          e.preventDefault();
          const tabId = button.dataset.tab;
          this.switchTab(instance, tabId);
        });
      }

      // Keyboard navigation (applies to both modes)
      if (config.keyboard) {
        button.addEventListener('keydown', (e) => {
          this.handleKeydown(instance, e, button);
        });
      }
    });
  },

  /**
   * Handle keyboard navigation
   * @param {Object} instance - The tabs instance
   * @param {KeyboardEvent} event - The keyboard event
   * @param {HTMLElement} currentButton - Current focused button
   */
  handleKeydown(instance, event, currentButton) {
    const {buttons} = instance;
    const currentIndex = buttons.indexOf(currentButton);
    let targetIndex = currentIndex;

    switch (event.key) {
      case 'ArrowLeft':
      case 'ArrowUp':
        event.preventDefault();
        targetIndex = currentIndex > 0 ? currentIndex - 1 : buttons.length - 1;
        break;

      case 'ArrowRight':
      case 'ArrowDown':
        event.preventDefault();
        targetIndex = currentIndex < buttons.length - 1 ? currentIndex + 1 : 0;
        break;

      case 'Home':
        event.preventDefault();
        targetIndex = 0;
        break;

      case 'End':
        event.preventDefault();
        targetIndex = buttons.length - 1;
        break;

      default:
        return; // Don't handle other keys
    }

    // Switch to target tab and focus
    const targetButton = buttons[targetIndex];
    const tabId = targetButton.dataset.tab;
    this.switchTab(instance, tabId);
    targetButton.focus();
  },

  /**
   * Switch to a specific tab
   * @param {Object} instance - The tabs instance
   * @param {string} tabId - The tab ID to switch to
   * @param {boolean} initial - Whether this is the initial activation
   * @returns {boolean} Whether the switch was successful
   */
  switchTab(instance, tabId, initial = false) {
    if (instance.destroyed) {
      console.warn('TabsComponent: Cannot switch tab on destroyed instance');
      return false;
    }

    // Check if tab exists
    if (!instance.tabIds.includes(tabId)) {
      console.error(`TabsComponent: Tab "${tabId}" not found`);
      return false;
    }

    // Don't switch if already active (unless initial)
    if (!initial && instance.activeTab === tabId) {
      return false;
    }

    // Call beforeTabChange callback (can cancel)
    if (!initial && typeof instance.config.beforeTabChange === 'function') {
      const result = instance.config.beforeTabChange.call(instance, tabId, instance.activeTab);
      if (result === false) return false;
    }

    const previousTab = instance.activeTab;
    const isRadioMode = instance.radioMap && instance.radioMap.size > 0;

    // Deactivate all tab buttons
    instance.buttons.forEach(button => {
      button.classList.remove('active');
      button.setAttribute('aria-selected', 'false');
      button.setAttribute('tabindex', '-1');
    });

    // In standard mode, JS manages panel visibility.
    // In radio mode, CSS (input:checked + .tab-pane) handles it — skip panels.
    if (!isRadioMode) {
      instance.panels.forEach(panel => {
        panel.classList.remove('active');
        panel.hidden = true;
      });
    }

    // Activate target tab
    const targetButton = instance.buttons.find(btn => btn.dataset.tab === tabId);
    const targetPanel = instance.panels.find(panel => panel.dataset.tab === tabId);

    if (targetButton && (isRadioMode || targetPanel)) {
      targetButton.classList.add('active');
      targetButton.setAttribute('aria-selected', 'true');
      targetButton.setAttribute('tabindex', '0');

      if (!isRadioMode && targetPanel) {
        targetPanel.classList.add('active');
        targetPanel.hidden = false;
      }

      instance.activeTab = tabId;

      // Sync the associated radio input (if using the radio-input tab pattern).
      // activeTab is already set above so the resulting 'change' event will be
      // a no-op when it calls switchTab again.
      if (isRadioMode) {
        const radioInput = instance.radioMap.get(targetButton);
        if (radioInput && !radioInput.checked) {
          radioInput.checked = true;
        }
      }

      // Update URL hash if enabled
      if (!initial && instance.config.hash) {
        this.updateHash(instance, tabId);
      }

      // Dispatch custom event
      this.dispatchEvent(instance, 'tabchange', {
        tabId,
        previousTab,
        button: targetButton,
        panel: targetPanel
      });

      // Call onTabChange callback
      if (!initial && typeof instance.config.onTabChange === 'function') {
        instance.config.onTabChange.call(instance, tabId, previousTab);
      }

      return true;
    }

    return false;
  },

  /**
   * Refresh the tabs instance (re-scan buttons and panels)
   * @param {Object} instance - The tabs instance
   */
  refresh(instance) {
    if (instance.destroyed) return;

    // Re-query buttons and panels using instance config selectors
    const buttonSelector = instance.config.buttonSelector;
    const panelSelector = instance.config.panelSelector;
    instance.buttons = Array.from(instance.element.querySelectorAll(buttonSelector));
    instance.panels = Array.from(instance.element.querySelectorAll(panelSelector));

    // Re-resolve radio buttons for any newly added labels
    if (instance._radioHandlers) {
      instance._radioHandlers.forEach((handler, radioInput) => {
        radioInput.removeEventListener('change', handler);
      });
      delete instance._radioHandlers;
    }
    instance.radioMap = this.resolveRadioButtons(instance.element, instance.buttons);

    instance.tabIds = instance.buttons.map(btn => btn.dataset.tab).filter(Boolean);

    // Re-setup
    this.setupAccessibility(instance);
    this.setupEventHandlers(instance);

    // Sync visual state with the radio that is currently checked.
    // This covers the case where data-checked bindings ran while the radioMap
    // was still empty (e.g. radios were added to the DOM by data-for after
    // TabsComponent.setup() ran), so the change event fired before any
    // listener was attached and was silently lost.
    const currentTab = this.getInitialTab(instance);
    if (currentTab && currentTab !== instance.activeTab) {
      this.switchTab(instance, currentTab);
    }
  },

  /**
   * Destroy a tabs instance
   * @param {Object} instance - The tabs instance
   */
  destroy(instance) {
    if (instance.destroyed) return;

    // Remove hash navigation event listeners
    if (instance._hashHandler) {
      window.removeEventListener('popstate', instance._hashHandler);
      window.removeEventListener('hashchange', instance._hashHandler);
      delete instance._hashHandler;
    }

    // Remove radio change event listeners
    if (instance._radioHandlers) {
      instance._radioHandlers.forEach((handler, radioInput) => {
        radioInput.removeEventListener('change', handler);
      });
      delete instance._radioHandlers;
    }

    // Clean up ARIA attributes
    instance.buttons.forEach(button => {
      button.removeAttribute('role');
      button.removeAttribute('aria-controls');
      button.removeAttribute('aria-selected');
      button.removeAttribute('tabindex');
    });

    instance.panels.forEach(panel => {
      panel.removeAttribute('role');
      panel.removeAttribute('aria-labelledby');
      panel.removeAttribute('tabindex');
      panel.hidden = false;
    });

    // Call onDestroy callback
    if (typeof instance.config.onDestroy === 'function') {
      instance.config.onDestroy.call(instance);
    }

    // Remove from instances map
    this.state.instances.delete(instance.element);

    // Mark as destroyed
    instance.destroyed = true;
  },

  /**
   * Setup MutationObserver for dynamically added tabs
   */
  setupObserver() {
    if (this.state.observer) return;

    // Debounce helper to avoid calling refresh() many times in a single render pass
    const refreshPending = new Set();
    const scheduleRefresh = (instance) => {
      if (refreshPending.has(instance)) return;
      refreshPending.add(instance);
      setTimeout(() => {
        refreshPending.delete(instance);
        if (!instance.destroyed) this.refresh(instance);
      }, 0);
    };

    this.state.observer = new MutationObserver((mutations) => {
      mutations.forEach(mutation => {
        mutation.addedNodes.forEach(node => {
          if (node.nodeType === 1) { // Element node
            // Check if the node itself is a tabs component
            if (node.matches && node.matches('[data-component="tabs"]')) {
              this.create(node);
            }
            // Check for tabs components within the added node
            if (node.querySelectorAll) {
              node.querySelectorAll('[data-component="tabs"]').forEach(element => {
                this.create(element);
              });
            }

            // Check if the new node was added inside an existing tabs instance.
            // This handles dynamically rendered tab buttons / panels (e.g. data-for).
            const tabsContainer = node.closest && node.closest('[data-component="tabs"]');
            if (tabsContainer) {
              const instance = this.state.instances.get(tabsContainer);
              if (instance && !instance.destroyed) {
                const btnSel = instance.config.buttonSelector;
                const panSel = instance.config.panelSelector;
                const isRelevant = (
                  (node.matches && (node.matches(btnSel) || node.matches(panSel))) ||
                  (node.querySelector && (node.querySelector(btnSel) || node.querySelector(panSel)))
                );
                if (isRelevant) scheduleRefresh(instance);
              }
            }
          }
        });

        // Handle removed nodes
        mutation.removedNodes.forEach(node => {
          if (node.nodeType === 1) {
            const instance = this.state.instances.get(node);
            if (instance && instance.config.destroyOnHidden) {
              // Wait for DOM operations to settle before cleanup
              // This prevents premature cleanup when nodes are moved in DOM
              setTimeout(() => {
                if (!node.isConnected) {
                  this.destroy(instance);
                }
              }, 0);
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
   * Extract configuration options from element's data attributes
   * @param {HTMLElement} element - The element to extract options from
   * @returns {Object} Extracted options
   */
  extractOptionsFromElement(element) {
    const options = {};

    // String options
    if (element.dataset.defaultTab) options.defaultTab = element.dataset.defaultTab;
    if (element.dataset.orientation) options.orientation = element.dataset.orientation;
    if (element.dataset.ariaLabel) options.ariaLabel = element.dataset.ariaLabel;

    // Boolean options
    if (element.dataset.keyboard !== undefined) {
      options.keyboard = element.dataset.keyboard !== 'false';
    }
    if (element.dataset.animation !== undefined) {
      options.animation = element.dataset.animation !== 'false';
    }
    if (element.dataset.hash !== undefined) {
      options.hash = element.dataset.hash === 'true';
    }
    if (element.dataset.lazy !== undefined) {
      options.lazy = element.dataset.lazy === 'true';
    }

    // String options from data attributes
    if (element.dataset.hashPrefix) options.hashPrefix = element.dataset.hashPrefix;

    // Selector options
    if (element.dataset.buttonSelector) options.buttonSelector = element.dataset.buttonSelector;
    if (element.dataset.panelSelector) options.panelSelector = element.dataset.panelSelector;

    // Number options
    if (element.dataset.animationDuration) {
      options.animationDuration = parseInt(element.dataset.animationDuration, 10);
    }

    return options;
  },

  /**
   * Get instance from element
   * @param {HTMLElement} element - The tabs container element
   * @returns {Object|null} The tabs instance or null
   */
  getInstance(element) {
    return this.state.instances.get(element) || null;
  },

  /**
   * Dispatch custom event
   * @param {Object} instance - The tabs instance
   * @param {string} eventName - Event name
   * @param {Object} detail - Event detail data
   */
  dispatchEvent(instance, eventName, detail = {}) {
    const event = new CustomEvent(`tabs:${eventName}`, {
      detail: {
        instance,
        ...detail
      },
      bubbles: true,
      cancelable: true
    });

    instance.element.dispatchEvent(event);
  },

  /**
   * Get initial tab based on URL hash or default configuration
   * @param {Object} instance - The tabs instance
   * @returns {string} The initial tab ID
   */
  getInitialTab(instance) {
    const {config, tabIds} = instance;
    let initialTab = config.defaultTab || tabIds[0];

    // Radio mode: if a radio is already checked (e.g. via data-checked binding),
    // use that as the initial tab.
    if (instance.radioMap && instance.radioMap.size > 0) {
      for (const [label, radio] of instance.radioMap) {
        if (radio.checked && label.dataset.tab) {
          initialTab = label.dataset.tab;
          break;
        }
      }
    }

    // Check URL hash if hash navigation is enabled
    if (config.hash) {
      const hashTab = this.getTabFromHash(instance);
      if (hashTab && tabIds.includes(hashTab)) {
        initialTab = hashTab;
      }
    }

    // Validate tab exists
    if (!tabIds.includes(initialTab)) {
      initialTab = tabIds[0];
    }

    return initialTab;
  },

  /**
   * Extract tab ID from URL hash
   * @param {Object} instance - The tabs instance
   * @returns {string|null} The tab ID or null
   */
  getTabFromHash(instance) {
    const hash = window.location.hash.slice(1); // Remove #
    if (!hash) return null;

    const prefix = instance.config.hashPrefix || '';
    if (prefix && hash.startsWith(prefix)) {
      return hash.slice(prefix.length);
    }

    return prefix ? null : hash;
  },

  /**
   * Update URL hash when tab changes
   * @param {Object} instance - The tabs instance
   * @param {string} tabId - The new tab ID
   */
  updateHash(instance, tabId) {
    const prefix = instance.config.hashPrefix || '';
    const newHash = `#${prefix}${tabId}`;

    // Use replaceState to avoid creating history entry for every tab click
    // Use pushState only if hash actually changed (for back button support)
    if (window.location.hash !== newHash) {
      history.pushState({tabId, component: 'tabs'}, '', newHash);
    }
  },

  /**
   * Setup hash navigation listeners
   * @param {Object} instance - The tabs instance
   */
  setupHashNavigation(instance) {
    // Store handler reference for cleanup
    instance._hashHandler = () => {
      const tabId = this.getTabFromHash(instance);
      if (tabId && instance.tabIds.includes(tabId) && instance.activeTab !== tabId) {
        this.switchTab(instance, tabId);
      }
    };

    // Listen to popstate for back/forward button
    window.addEventListener('popstate', instance._hashHandler);

    // Also listen to hashchange for direct URL changes
    window.addEventListener('hashchange', instance._hashHandler);

    // Update hash on initial load if not already set
    if (instance.config.hash && !window.location.hash) {
      const initialTab = this.getInitialTab(instance);
      if (initialTab) {
        // Use replaceState to set initial hash without creating history entry
        const prefix = instance.config.hashPrefix || '';
        history.replaceState({tabId: initialTab, component: 'tabs'}, '', `#${prefix}${initialTab}`);
      }
    }
  }
};

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    TabsComponent.init();
  });
} else {
  TabsComponent.init();
}

// Expose globally
window.TabsComponent = TabsComponent;
