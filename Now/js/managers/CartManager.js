/**
 * CartManager — frontend cart logic
 *
 * Manages add-to-cart, qty updates, remove, and cart badge count.
 * Communicates with backend API via fetch.
 */
const CartManager = {
  config: {
    apiBase: '',
    currency: '฿'
  },

  state: {
    initialized: false,
    count: 0,
    subtotal: 0
  },

  init(options = {}) {
    if (this.state.initialized) return this;

    this.config = {...this.config, ...options};
    this.config.apiBase = this.config.apiBase || (window.WEB_URL || '/') + 'api.php';

    this.bindEvents();
    this.updateBadge();
    this.state.initialized = true;

    return this;
  },

  /**
   * Bind click events for add-to-cart, qty, and remove buttons
   */
  bindEvents() {
    document.addEventListener('click', (e) => {
      const target = e.target.closest('[data-id]') || e.target;

      // Add to cart button
      if (target.classList.contains('add-to-cart')) {
        e.preventDefault();
        const productId = target.dataset.id;
        const variantId = target.dataset.variant || 0;
        const qty = parseInt(target.dataset.qty || 1, 10);
        this.addToCart(productId, variantId, qty);
      }

      // Quantity buttons on cart page
      if (target.classList.contains('btn-qty')) {
        const key = target.dataset.key;
        const input = document.querySelector('.qty-input[data-key="' + key + '"]');
        if (input) {
          let val = parseInt(input.value, 10);
          if (target.classList.contains('plus')) {
            val++;
          } else if (target.classList.contains('minus') && val > 1) {
            val--;
          }
          input.value = val;
          this.updateQty(key, val);
        }
      }

      // Remove button
      if (target.classList.contains('btn-remove')) {
        const key = target.dataset.key;
        this.removeItem(key);
      }
    });
  },

  /**
   * Add product to cart via API
   */
  async addToCart(productId, variantId, qty) {
    try {
      const res = await fetch(this.config.apiBase + '/cart/add', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          product_id: productId,
          variant_id: variantId,
          qty: qty
        })
      });
      const data = await res.json();
      if (data.success) {
        this.state.count = data.count || 0;
        this.state.subtotal = data.subtotal || 0;
        this.updateBadge();
        this.showNotification(data.message || 'Added to cart');
      }
    } catch (err) {
      console.error('CartManager.addToCart error:', err);
    }
  },

  /**
   * Update item quantity via API
   */
  async updateQty(key, qty) {
    try {
      const res = await fetch(this.config.apiBase + '/cart/update', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({key, qty})
      });
      const data = await res.json();
      if (data.success) {
        this.state.count = data.count || 0;
        this.state.subtotal = data.subtotal || 0;
        this.updateBadge();

        // Update line total on page
        const row = document.querySelector('.cart-item[data-key="' + key + '"]');
        if (row) {
          const lineEl = row.querySelector('.line-total');
          if (lineEl && data.line_total !== undefined) {
            lineEl.textContent = new Intl.NumberFormat('th-TH', {minimumFractionDigits: 2}).format(data.line_total) + ' ' + this.config.currency;
          }
        }

        // Update subtotal on page
        const subtotalEl = document.querySelector('.cart-summary .total strong');
        if (subtotalEl) {
          subtotalEl.textContent = new Intl.NumberFormat('th-TH', {minimumFractionDigits: 2}).format(this.state.subtotal) + ' ' + this.config.currency;
        }
      }
    } catch (err) {
      console.error('CartManager.updateQty error:', err);
    }
  },

  /**
   * Remove item from cart via API
   */
  async removeItem(key) {
    try {
      const res = await fetch(this.config.apiBase + '/cart/remove', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({key})
      });
      const data = await res.json();
      if (data.success) {
        this.state.count = data.count || 0;
        this.state.subtotal = data.subtotal || 0;
        this.updateBadge();

        // Remove row from DOM
        const row = document.querySelector('.cart-item[data-key="' + key + '"]');
        if (row) {
          row.remove();
        }

        // If cart empty, reload page to show empty state
        if (this.state.count === 0) {
          location.reload();
        }
      }
    } catch (err) {
      console.error('CartManager.removeItem error:', err);
    }
  },

  /**
   * Update cart badge count in header
   */
  updateBadge() {
    const badge = document.querySelector('.cart-badge');
    if (badge) {
      badge.textContent = this.state.count;
      badge.style.display = this.state.count > 0 ? '' : 'none';
    }
  },

  /**
   * Show a temporary notification toast
   */
  showNotification(message) {
    // Use Now.js notification if available, otherwise simple alert
    if (window.NotificationManager && NotificationManager.show) {
      NotificationManager.show({message, type: 'success', duration: 2000});
    } else {
      const toast = document.createElement('div');
      toast.className = 'cart-toast';
      toast.textContent = message;
      document.body.appendChild(toast);
      setTimeout(() => toast.remove(), 2500);
    }
  }
};

// Auto-init when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => CartManager.init());
} else {
  CartManager.init();
}

// Export for Now.js
if (typeof window !== 'undefined') {
  window.CartManager = CartManager;
}

export default CartManager;
