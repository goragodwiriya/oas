/**
 * CoreObserver - Central MutationObserver for the Now.js framework
 *
 * Provides a single, optimized MutationObserver that managers can register handlers with.
 * This eliminates multiple observers and provides consistent DOM change handling.
 *
 * @example
 * // Register handler for added nodes
 * CoreObserver.onAdd('[data-component]', (element) => {
 *   ComponentManager.mount(element);
 * });
 *
 * // Register handler for removed nodes
 * CoreObserver.onRemove('[data-table]', (element) => {
 *   TableManager.destroyTable(element.dataset.table);
 * });
 */
const CoreObserver = {
  config: {
    debounceMs: 10,       // Debounce mutations for performance
    batchWithRAF: true    // Use requestAnimationFrame for batching
  },

  state: {
    initialized: false,
    processing: false,    // Flag to prevent re-entry during handler execution
    observer: null,
    addHandlers: new Map(),    // selector -> { callback, options }[]
    removeHandlers: new Map(), // selector -> { callback, options }[]
    pendingAdded: new Set(),    // Pending added nodes for batch processing
    pendingRemoved: new Set(),  // Pending removed nodes for batch processing
    processedBySelector: new Map(), // selector -> WeakSet<Element>
    debounceTimer: null,
    rafId: null
  },

  /**
   * Register handler for added nodes matching selector
   * @param {string} selector - CSS selector to match
   * @param {Function} callback - Function to call with matched element
   * @param {Object} options - Options { priority: number, checkConnected: boolean }
   */
  onAdd(selector, callback, options = {}) {
    if (!selector || typeof callback !== 'function') {
      console.warn('[CoreObserver] Invalid onAdd parameters');
      return;
    }

    const handlers = this.state.addHandlers.get(selector) || [];
    handlers.push({
      callback,
      options: {
        priority: options.priority || 0,
        checkConnected: options.checkConnected !== false
      }
    });

    // Sort by priority (higher first)
    handlers.sort((a, b) => b.options.priority - a.options.priority);
    this.state.addHandlers.set(selector, handlers);
  },

  /**
   * Register handler for removed nodes matching selector
   * @param {string} selector - CSS selector to match
   * @param {Function} callback - Function to call with matched element
   * @param {Object} options - Options { priority: number, delay: number }
   */
  onRemove(selector, callback, options = {}) {
    if (!selector || typeof callback !== 'function') {
      console.warn('[CoreObserver] Invalid onRemove parameters');
      return;
    }

    const handlers = this.state.removeHandlers.get(selector) || [];
    handlers.push({
      callback,
      options: {
        priority: options.priority || 0,
        delay: options.delay || 0  // Delay before calling (for DOM settling)
      }
    });

    handlers.sort((a, b) => b.options.priority - a.options.priority);
    this.state.removeHandlers.set(selector, handlers);
  },

  /**
   * Unregister handler
   * @param {string} selector - CSS selector
   * @param {Function} callback - Callback to remove (optional, removes all if not provided)
   */
  off(selector, callback = null) {
    if (callback) {
      // Remove specific callback
      ['addHandlers', 'removeHandlers'].forEach(type => {
        const handlers = this.state[type].get(selector);
        if (handlers) {
          const filtered = handlers.filter(h => h.callback !== callback);
          if (filtered.length > 0) {
            this.state[type].set(selector, filtered);
          } else {
            this.state[type].delete(selector);
            if (!this.state.addHandlers.has(selector) && !this.state.removeHandlers.has(selector)) {
              this.state.processedBySelector.delete(selector);
            }
          }
        }
      });
    } else {
      // Remove all handlers for selector
      this.state.addHandlers.delete(selector);
      this.state.removeHandlers.delete(selector);
      this.state.processedBySelector.delete(selector);
    }
  },

  /**
   * Process pending mutations in batch
   */
  processPending() {
    // Prevent re-entry if already processing
    if (this.state.processing) {
      return;
    }

    this.state.processing = true;

    // Disconnect observer during processing to prevent collecting mutations from handlers
    if (this.state.observer) {
      this.state.observer.disconnect();
    }

    try {
      const added = Array.from(this.state.pendingAdded);
      const removed = Array.from(this.state.pendingRemoved);

      this.state.pendingAdded.clear();
      this.state.pendingRemoved.clear();

      // Process removed nodes
      if (removed.length > 0) {
        this.state.removeHandlers.forEach((handlers, selector) => {
          const processedSet = this.state.processedBySelector.get(selector);
          removed.forEach(node => {
            try {
              // Check if node itself matches
              if (node.matches && node.matches(selector)) {
                if (processedSet) {
                  processedSet.delete(node);
                }
                handlers.forEach(h => {
                  if (h.options.delay > 0) {
                    setTimeout(() => h.callback(node), h.options.delay);
                  } else {
                    h.callback(node);
                  }
                });
              }

              // Check descendants
              if (node.querySelectorAll) {
                node.querySelectorAll(selector).forEach(el => {
                  if (processedSet) {
                    processedSet.delete(el);
                  }
                  handlers.forEach(h => {
                    if (h.options.delay > 0) {
                      setTimeout(() => h.callback(el), h.options.delay);
                    } else {
                      h.callback(el);
                    }
                  });
                });
              }
            } catch (error) {
              console.error('[CoreObserver] Error in remove handler:', error);
            }
          });
        });
      }

      // Process added nodes (after removals to ensure cleanup completes first)
      if (added.length > 0) {
        this.state.addHandlers.forEach((handlers, selector) => {
          let processedSet = this.state.processedBySelector.get(selector);
          if (!processedSet) {
            processedSet = new WeakSet();
            this.state.processedBySelector.set(selector, processedSet);
          }
          added.forEach(node => {
            try {
              // Check if node itself matches and hasn't been processed
              if (node.matches && node.matches(selector)) {
                if (!processedSet.has(node)) {
                  processedSet.add(node);
                  handlers.forEach(h => {
                    if (!h.options.checkConnected || node.isConnected) {
                      h.callback(node);
                    }
                  });
                }
              }

              // Check descendants
              if (node.querySelectorAll) {
                node.querySelectorAll(selector).forEach(el => {
                  if (!processedSet.has(el)) {
                    processedSet.add(el);
                    handlers.forEach(h => {
                      if (!h.options.checkConnected || el.isConnected) {
                        h.callback(el);
                      }
                    });
                  }
                });
              }
            } catch (error) {
              console.error('[CoreObserver] Error in add handler:', error);
            }
          });
        });
      }
    } finally {
      this.state.processing = false;

      // Reconnect observer after processing
      if (this.state.observer && this.state.initialized && document.body) {
        this.state.observer.observe(document.body, {
          childList: true,
          subtree: true
        });
      }
    }
  },

  /**
   * Schedule batch processing
   */
  scheduleBatch() {
    if (this.state.debounceTimer) {
      clearTimeout(this.state.debounceTimer);
    }

    this.state.debounceTimer = setTimeout(() => {
      if (this.config.batchWithRAF) {
        if (this.state.rafId) {
          cancelAnimationFrame(this.state.rafId);
        }
        this.state.rafId = requestAnimationFrame(() => {
          this.processPending();
          this.state.rafId = null;
        });
      } else {
        this.processPending();
      }
      this.state.debounceTimer = null;
    }, this.config.debounceMs);
  },

  /**
   * Handle mutations from MutationObserver
   */
  handleMutations(mutations) {
    mutations.forEach(mutation => {
      // Collect added nodes
      mutation.addedNodes.forEach(node => {
        if (node.nodeType === Node.ELEMENT_NODE) {
          this.state.pendingAdded.add(node);
        }
      });

      // Collect removed nodes
      mutation.removedNodes.forEach(node => {
        if (node.nodeType === Node.ELEMENT_NODE) {
          this.state.pendingRemoved.add(node);
        }
      });
    });

    if (this.state.pendingAdded.size > 0 || this.state.pendingRemoved.size > 0) {
      this.scheduleBatch();
    }
  },

  /**
   * Initialize the observer
   * @param {Object} options - Configuration options
   */
  init(options = {}) {
    if (this.state.initialized) {
      return this;
    }

    if (!document.body) {
      document.addEventListener('DOMContentLoaded', () => this.init(options), {once: true});
      return this;
    }

    // Merge config
    Object.assign(this.config, options);

    // Create observer
    this.state.observer = new MutationObserver((mutations) => {
      this.handleMutations(mutations);
    });

    // Start observing
    this.state.observer.observe(document.body, {
      childList: true,
      subtree: true
    });

    this.state.initialized = true;

    // Emit init event
    if (window.EventManager) {
      EventManager.emit('coreobserver:init', {timestamp: Date.now()});
    }

    return this;
  },

  /**
   * Disconnect and cleanup
   */
  destroy() {
    if (this.state.observer) {
      this.state.observer.disconnect();
      this.state.observer = null;
    }

    if (this.state.debounceTimer) {
      clearTimeout(this.state.debounceTimer);
    }

    if (this.state.rafId) {
      cancelAnimationFrame(this.state.rafId);
    }

    this.state.addHandlers.clear();
    this.state.removeHandlers.clear();
    this.state.pendingAdded.clear();
    this.state.pendingRemoved.clear();
    this.state.processedBySelector.clear();
    this.state.initialized = false;
  },

  /**
   * Get debug info
   */
  getDebugInfo() {
    return {
      initialized: this.state.initialized,
      addHandlerCount: this.state.addHandlers.size,
      removeHandlerCount: this.state.removeHandlers.size,
      pendingAdded: this.state.pendingAdded.size,
      pendingRemoved: this.state.pendingRemoved.size,
      selectors: {
        add: Array.from(this.state.addHandlers.keys()),
        remove: Array.from(this.state.removeHandlers.keys())
      }
    };
  }
};

// Register with Now.js
if (window.Now?.registerManager) {
  Now.registerManager('coreObserver', CoreObserver);
}

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => CoreObserver.init());
} else {
  CoreObserver.init();
}

// Expose globally
window.CoreObserver = CoreObserver;

export default CoreObserver;
