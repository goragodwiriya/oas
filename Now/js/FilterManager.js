/**
 * FilterManager
 * Manages filtering and sorting of template-rendered items
 *
 * Features:
 * - Generic filtering by any attribute
 * - Multi-criteria filtering
 * - Sorting by field (string, number, date)
 * - Animated transitions
 * - Works with TemplateManager
 *
 * @requires AnimationManager
 */
const FilterManager = {
  config: {
    defaultAnimation: 'fade',
    defaultDuration: 300,
    defaultFilterAttr: 'data-category',
    debounceDelay: 150,
    debug: true // Enable debug by default
  },

  state: {
    activeFilters: new Map(), // scope => {attr: value}
    activeSorts: new Map(),   // scope => {by, order, type}
    debounceTimers: new Map(),
    initialized: false
  },

  /**
   * Initialize FilterManager
   */
  init() {
    if (this.state.initialized) return;

    this.bindFilterControls();
    this.bindSortControls();

    // Listen for template render completion from EventManager
    if (window.EventManager) {
      EventManager.on('template:render', (data) => {
        setTimeout(() => {
          this.refreshFilters();
        }, 100);
      });
    }

    this.state.initialized = true;
  },

  /**
   * Refresh all active filters after template render
   */
  refreshFilters() {
    this.state.activeFilters.forEach((filter, scope) => {
      const container = document.querySelector(scope);
      if (container) {
        const controlContainer = document.querySelector(`[data-filter-scope="${scope}"]`);
        if (controlContainer) {
          const options = this.getFilterOptions(controlContainer);
          this.performFilter(container, filter.value, options);
        }
      }
    });
  },  /**
   * Bind filter control elements
   */
  bindFilterControls() {
    document.addEventListener('click', (e) => {
      // Support both data-on and data-filter-value
      const trigger = e.target.closest('[data-filter-value]');
      if (!trigger) return;

      e.preventDefault();
      this.handleFilterClick(trigger);
    });
  },

  /**
   * Bind sort control elements
   */
  bindSortControls() {
    document.addEventListener('click', (e) => {
      // Support both data-on and data-sort-by
      const trigger = e.target.closest('[data-sort-by]');
      if (!trigger) return;

      e.preventDefault();
      this.handleSortClick(trigger);
    });
  },

  /**
   * Handle filter button click
   */
  handleFilterClick(trigger) {
    const value = trigger.dataset.filterValue;
    if (!value) return;

    // Find control container
    const controlContainer = trigger.closest('[data-filter-scope]');
    if (!controlContainer) return;


    const options = this.getFilterOptions(controlContainer);
    const scope = controlContainer.dataset.filterScope;

    // Check if target container exists
    const targetContainer = document.querySelector(scope);
    if (!targetContainer) return;

    // Update active button state
    this.updateActiveButton(controlContainer, trigger);

    // Apply filter
    this.filter(value, scope, options);
  },

  /**
   * Handle sort button click
   */
  handleSortClick(trigger) {
    const sortBy = trigger.dataset.sortBy;
    const sortOrder = trigger.dataset.sortOrder || 'asc';
    const sortType = trigger.dataset.sortType || 'string';

    if (!sortBy) return;

    // Find control container
    const controlContainer = trigger.closest('[data-sort-scope]');
    if (!controlContainer) return;

    const scope = controlContainer.dataset.sortScope;

    // Toggle sort order if clicking same button
    const lastSort = this.state.activeSorts.get(scope);
    const newOrder = (lastSort?.by === sortBy && lastSort?.order === 'asc') ? 'desc' : sortOrder;

    // Update active button state
    this.updateActiveButton(controlContainer, trigger);

    // Apply sort
    this.sort({
      scope,
      by: sortBy,
      order: newOrder,
      type: sortType
    });
  },

  /**
   * Get filter options from control container
   */
  getFilterOptions(container) {
    return {
      attr: container.dataset.filterAttr || this.config.defaultFilterAttr,
      animation: container.dataset.filterAnimation || this.config.defaultAnimation,
      duration: parseInt(container.dataset.filterDuration) || this.config.defaultDuration
    };
  },

  /**
   * Update active button state
   */
  updateActiveButton(container, activeButton) {
    // Remove active class from siblings
    const buttons = container.querySelectorAll('[data-filter-value], [data-sort-by]');
    buttons.forEach(btn => btn.classList.remove('active'));

    // Add active class to clicked button
    activeButton.classList.add('active');
  },

  /**
   * Apply filter to items
   */
  filter(value, scope, options) {
    const container = document.querySelector(scope);
    if (!container) return;

    // Store active filter
    this.state.activeFilters.set(scope, {
      value,
      attr: options.attr
    });

    // Debounce filter operation
    this.debounce(`filter-${scope}`, () => {
      this.performFilter(container, value, options);
    }, this.config.debounceDelay);
  },

  /**
   * Perform filter operation
   */
  async performFilter(container, value, options) {
    // Find all items with the filter attribute (support nested structure)
    const items = Array.from(container.querySelectorAll(`[${options.attr}]`)).filter(el =>
      !el.matches('template, script, style')
    );

    if (items.length === 0) return;

    const showAll = value === '*' || value === 'all';

    for (const item of items) {
      const itemValue = item.getAttribute(options.attr);
      const shouldShow = showAll || itemValue === value;

      // Check if item is currently hidden (style.display === 'none' or has 'hidden' class)
      const isHidden = item.style.display === 'none' || item.classList.contains('hidden');

      if (shouldShow && isHidden) {
        // Show item
        await this.animateItem(item, 'show', options.animation, options.duration);
      } else if (!shouldShow && !isHidden) {
        // Hide item now
        this.animateItem(item, 'hide', options.animation, options.duration);
      }
    }
  },

  /**
   * Apply sort to items
   */
  sort(options) {
    const container = document.querySelector(options.scope);
    if (!container) return;

    // Store active sort
    this.state.activeSorts.set(options.scope, {
      by: options.by,
      order: options.order,
      type: options.type
    });

    // Debounce sort operation
    this.debounce(`sort-${options.scope}`, () => {
      this.performSort(container, options);
    }, this.config.debounceDelay);
  },

  /**
   * Perform sort operation
   */
  performSort(container, options) {
    // Find all items with sortable attributes (support nested structure)
    const items = Array.from(container.querySelectorAll(`[data-${options.by}]`)).filter(el =>
      !el.matches('template, script, style')
    );

    if (items.length === 0) return;

    // Sort items
    items.sort((a, b) => {
      const aValue = this.getSortValue(a, options.by, options.type);
      const bValue = this.getSortValue(b, options.by, options.type);

      return this.compare(aValue, bValue, options.type, options.order);
    });

    // Reorder DOM
    const parent = items[0].parentElement;
    items.forEach(item => parent.appendChild(item));
  },

  /**
   * Get sort value from element
   */
  getSortValue(element, field, type) {
    // Try dataset first
    let value = element.dataset[field];

    // Try attribute
    if (!value) {
      value = element.getAttribute(`data-${field}`);
    }

    // Try text content of child element
    if (!value) {
      const childEl = element.querySelector(`[data-${field}]`);
      if (childEl) {
        value = childEl.textContent.trim();
      }
    }

    // Convert based on type
    switch (type) {
      case 'number':
        return parseFloat(value) || 0;
      case 'date':
        return new Date(value).getTime() || 0;
      default:
        return String(value || '').toLowerCase();
    }
  },

  /**
   * Compare two values
   */
  compare(a, b, type, order) {
    let result = 0;

    switch (type) {
      case 'number':
      case 'date':
        result = a - b;
        break;
      default:
        result = a.localeCompare(b);
    }

    return order === 'desc' ? -result : result;
  },

  /**
   * Animate item show/hide
   */
  async animateItem(item, action, animation, duration) {
    const isShow = action === 'show';

    // No animation - instant show/hide
    if (animation === 'none' || duration === 0) {
      item.style.display = isShow ? '' : 'none';
      return;
    }

    switch (animation) {
      case 'fade':
        if (isShow) {
          item.style.display = '';
          item.style.opacity = '0';
          item.style.transition = `opacity ${duration}ms`;
          await this.nextFrame();
          item.style.opacity = '1';
          await this.wait(duration);
          item.style.transition = '';
          item.style.opacity = ''; // Reset to default
        } else {
          item.style.transition = `opacity ${duration}ms`;
          item.style.opacity = '0';
          await this.wait(duration);
          item.style.display = 'none';
          item.style.transition = '';
        }
        break;

      case 'slide':
        if (isShow) {
          item.style.display = '';
          item.style.maxHeight = '0';
          item.style.overflow = 'hidden';
          item.style.transition = `max-height ${duration}ms`;
          await this.nextFrame();
          item.style.maxHeight = item.scrollHeight + 'px';
          await this.wait(duration);
          item.style.maxHeight = '';
          item.style.overflow = '';
          item.style.transition = '';
        } else {
          item.style.maxHeight = item.scrollHeight + 'px';
          item.style.overflow = 'hidden';
          item.style.transition = `max-height ${duration}ms`;
          await this.nextFrame();
          item.style.maxHeight = '0';
          await this.wait(duration);
          item.style.display = 'none';
          item.style.maxHeight = '';
          item.style.overflow = '';
          item.style.transition = '';
        }
        break;

      case 'scale':
        if (isShow) {
          item.style.display = '';
          item.style.transform = 'scale(0)';
          item.style.opacity = '0';
          item.style.transition = `transform ${duration}ms, opacity ${duration}ms`;
          await this.nextFrame();
          item.style.transform = 'scale(1)';
          item.style.opacity = '1';
          await this.wait(duration);
          item.style.transform = '';
          item.style.opacity = '';
          item.style.transition = '';
        } else {
          item.style.transition = `transform ${duration}ms, opacity ${duration}ms`;
          item.style.transform = 'scale(0)';
          item.style.opacity = '0';
          await this.wait(duration);
          item.style.display = 'none';
          item.style.transform = '';
          item.style.opacity = '';
          item.style.transition = '';
        }
        break;

      default:
        // No animation
        item.style.display = isShow ? '' : 'none';
    }
  },

  /**
   * Debounce function execution
   */
  debounce(key, fn, delay) {
    if (this.state.debounceTimers.has(key)) {
      clearTimeout(this.state.debounceTimers.get(key));
    }

    const timer = setTimeout(() => {
      fn();
      this.state.debounceTimers.delete(key);
    }, delay);

    this.state.debounceTimers.set(key, timer);
  },

  /**
   * Wait for next animation frame
   */
  nextFrame() {
    return new Promise(resolve => requestAnimationFrame(resolve));
  },

  /**
   * Wait for specified duration
   */
  wait(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
  },

  /**
   * Get filtered items
   */
  getFilteredItems(scope) {
    const container = document.querySelector(scope);
    if (!container) return [];

    // Get the filter attribute from active filter
    const activeFilter = this.state.activeFilters.get(scope);
    const attr = activeFilter?.attr || this.config.defaultFilterAttr;

    // Find all items with the filter attribute
    return Array.from(container.querySelectorAll(`[${attr}]`)).filter(el =>
      !el.matches('template, script, style') &&
      el.style.display !== 'none'
    );
  },

  /**
   * Clear all filters in scope
   */
  clearFilters(scope) {
    const container = document.querySelector(scope);
    if (!container) return;

    // Get the filter attribute
    const activeFilter = this.state.activeFilters.get(scope);
    const attr = activeFilter?.attr || this.config.defaultFilterAttr;

    // Find and show all items
    const items = Array.from(container.querySelectorAll(`[${attr}]`));
    items.forEach(item => {
      item.style.display = '';
    });

    this.state.activeFilters.delete(scope);
  },

  /**
   * Clear all sorts in scope
   */
  clearSorts(scope) {
    this.state.activeSorts.delete(scope);
  },

  /**
   * Get active filter
   */
  getActiveFilter(scope) {
    return this.state.activeFilters.get(scope);
  },

  /**
   * Get active sort
   */
  getActiveSort(scope) {
    return this.state.activeSorts.get(scope);
  },

  /**
   * Destroy manager
   */
  destroy() {
    // Clear all timers
    this.state.debounceTimers.forEach(timer => clearTimeout(timer));
    this.state.debounceTimers.clear();

    // Clear state
    this.state.activeFilters.clear();
    this.state.activeSorts.clear();
    this.state.initialized = false;
  }
};

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => FilterManager.init());
} else {
  FilterManager.init();
}

// Expose globally
window.FilterManager = FilterManager;
