/**
 * VariantPicker — product variant selection UI
 *
 * Renders variant attribute selectors (e.g., size, color) and
 * updates price/stock/image when a variant is selected.
 */
const VariantPicker = {
  config: {
    container: '.variant-picker',
    priceSelector: '.product-price strong',
    stockSelector: '.product-stock',
    imageSelector: '.product-gallery figure:first-child img',
    currency: '฿'
  },

  state: {
    initialized: false,
    variants: [],
    attributes: [],
    selected: {},
    currentVariant: null
  },

  /**
   * Initialize variant picker
   *
   * @param {Object} options
   * @param {Array}  options.variants   Array of variant objects {id, sku, price, stock, option_values: {attr_name: value}}
   * @param {Array}  options.attributes Array of attribute objects {id, name, values: [string]}
   */
  init(options = {}) {
    if (this.state.initialized) return this;

    this.config = {...this.config, ...options};
    this.state.variants = options.variants || [];
    this.state.attributes = options.attributes || [];

    if (this.state.variants.length === 0) return this;

    this.render();
    this.bindEvents();
    this.state.initialized = true;

    return this;
  },

  /**
   * Render attribute selectors
   */
  render() {
    const container = document.querySelector(this.config.container);
    if (!container) return;

    let html = '';
    this.state.attributes.forEach(attr => {
      html += '<div class="variant-attribute" data-attr="' + attr.name + '">';
      html += '<label>' + attr.name + '</label>';
      html += '<div class="variant-options">';
      (attr.values || []).forEach(val => {
        html += '<button type="button" class="variant-option" data-attr="' + attr.name + '" data-value="' + val + '">' + val + '</button>';
      });
      html += '</div></div>';
    });

    container.innerHTML = html;
  },

  /**
   * Bind click events on variant option buttons
   */
  bindEvents() {
    const container = document.querySelector(this.config.container);
    if (!container) return;

    container.addEventListener('click', (e) => {
      const btn = e.target.closest('.variant-option');
      if (!btn) return;

      const attr = btn.dataset.attr;
      const value = btn.dataset.value;

      // Toggle selection
      this.state.selected[attr] = value;

      // Update active state
      container.querySelectorAll('.variant-option[data-attr="' + attr + '"]').forEach(b => {
        b.classList.toggle('active', b.dataset.value === value);
      });

      // Find matching variant
      this.findMatchingVariant();
    });
  },

  /**
   * Find variant matching all selected attributes
   */
  findMatchingVariant() {
    const selectedKeys = Object.keys(this.state.selected);

    // Need all attributes selected
    if (selectedKeys.length < this.state.attributes.length) {
      return;
    }

    const match = this.state.variants.find(v => {
      const opts = v.option_values || {};
      return selectedKeys.every(key => opts[key] === this.state.selected[key]);
    });

    if (match) {
      this.state.currentVariant = match;
      this.updateUI(match);
    } else {
      this.state.currentVariant = null;
    }
  },

  /**
   * Update price, stock, and add-to-cart button with variant data
   */
  updateUI(variant) {
    // Update price
    const priceEl = document.querySelector(this.config.priceSelector);
    if (priceEl) {
      priceEl.textContent = new Intl.NumberFormat('th-TH', {minimumFractionDigits: 2}).format(variant.price) + ' ' + this.config.currency;
    }

    // Update stock
    const stockEl = document.querySelector(this.config.stockSelector);
    if (stockEl) {
      if (variant.stock !== null && variant.stock !== undefined) {
        stockEl.textContent = variant.stock > 0
          ? variant.stock + ' {LNG_in stock}'
          : '{LNG_Out of stock}';
      }
    }

    // Update add-to-cart button variant data
    const cartBtn = document.querySelector('.add-to-cart');
    if (cartBtn) {
      cartBtn.dataset.variant = variant.id;
      cartBtn.disabled = variant.stock !== null && variant.stock <= 0;
    }

    // Update image if variant has image
    if (variant.image) {
      const imgEl = document.querySelector(this.config.imageSelector);
      if (imgEl) {
        imgEl.src = variant.image;
      }
    }
  },

  /**
   * Get currently selected variant
   */
  getSelected() {
    return this.state.currentVariant;
  }
};

// Export for Now.js
if (typeof window !== 'undefined') {
  window.VariantPicker = VariantPicker;
}

export default VariantPicker;
