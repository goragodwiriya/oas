/**
 * Helper functions for managing reactive updates and cleanup
 */
const ReactiveHelpers = {
  /**
   * Setup reactive update for an element
   * @param {HTMLElement} el - Target element
   * @param {Object} context - Component context
   * @param {string} updateType - Type of update (e.g., 'text', 'html', 'for')
   * @param {Function} updateFn - Update function to run
   */
  setupReactiveUpdate(el, context, updateType, updateFn) {
    if (!el || !context || !updateType || !updateFn) return;
    if (typeof updateFn !== 'function') {
      console.error(`Invalid update function for ${updateType}`);
      return;
    }

    // Remove existing update if any
    this.cleanupReactiveUpdate(el, updateType);

    if (context.reactive && context._updateQueue) {
      el[`_has${updateType}Update`] = true;

      // Store context reference per update type for cleanup
      el[`_${updateType}ReactiveContext`] = context;

      const wrappedUpdate = async () => {
        try {
          await updateFn();
        } catch (error) {
          console.error(`Error in reactive update (${updateType}):`, error);
          EventManager.emit('reactive:error', {
            type: updateType,
            element: el,
            error
          });
        }
      };

      // Store reference to wrapped update for cleanup
      el[`_${updateType}UpdateFn`] = wrappedUpdate;
      context._updateQueue.add(wrappedUpdate);
    }
  },

  /**
   * Clean up reactive update for an element
   * @param {HTMLElement} el - Target element
   * @param {string} updateType - Type of update to clean
   */
  cleanupReactiveUpdate(el, updateType) {
    if (!el || !updateType) return;

    const updateKey = `_has${updateType}Update`;
    const updateFnKey = `_${updateType}UpdateFn`;
    const contextKey = `_${updateType}ReactiveContext`;

    if (el[updateKey]) {
      // Remove from update queue if exists
      if (el[updateFnKey] && el[contextKey]?._updateQueue) {
        el[contextKey]._updateQueue.delete(el[updateFnKey]);
      }
      delete el[updateKey];
      delete el[updateFnKey];
      delete el[contextKey];
    }
  },

  /**
   * Clean up all reactive updates for an element
   * @param {HTMLElement} el - Target element
   */
  cleanupAllReactiveUpdates(el) {
    if (!el) return;

    Object.keys(el).forEach(key => {
      const match = key.match(/^_has(.+)Update$/);
      if (match) {
        this.cleanupReactiveUpdate(el, match[1]);
      }
    });
  }
};

// Add to TemplateManager
Object.assign(TemplateManager, ReactiveHelpers);
