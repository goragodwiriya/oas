/**
 * CookieBanner - PDPA-Compliant Cookie Consent Banner
 *
 * Displays a cookie consent banner compliant with Thailand's Personal Data Protection Act (PDPA).
 *
 * - Reads the `cookie_policy` setting from AppConfigManager (frontend-settings API)
 * - Shows only when `cookie_policy === true` and consent has not been given/declined
 * - Stores minimal consent information (timestamp, version) in localStorage
 * - Consent is valid for 365 days; after that the banner will be shown again
 * - Supports localization via the app's i18n system
 *
 * @requires AppConfigManager (optional - falls back to direct API call)
 * @requires EventManager (optional)
 */
const CookieBanner = {
  /**
   * Storage key for localStorage (we intentionally use localStorage rather than cookies
   * to keep the stored consent information accessible from JavaScript and minimal)
   */
  STORAGE_KEY: 'cookie_consent',

  /**
   * Policy version - bump this when the policy changes so users are asked again
   */
  POLICY_VERSION: '1',

  /**
   * Consent lifetime (days)
   */
  CONSENT_DAYS: 365,

  /**
   * State
   */
  state: {
    initialized: false,
    bannerEl: null,
    cookiePolicyEnabled: false,
    dataController: ''
  },

  /**
   * Initialize CookieBanner
   * Called automatically when the script loads
   */
  async init() {
    if (this.state.initialized) return;
    this.state.initialized = true;

    // If a valid consent record exists (accepted or declined), do not show the banner
    if (this._hasValidConsent()) return;

    // Wait for config to finish loading, then check the setting
    await this._loadConfig();

    if (!this.state.cookiePolicyEnabled) return;

    this._render();
  },

  /**
   * Load cookie_policy setting from AppConfigManager or call the API directly as a fallback
   * @private
   */
  async _loadConfig() {
    // 1. Use the config already loaded by AppConfigManager (if available)
    if (window.AppConfigManager?.state?.lastConfig) {
      const cfg = AppConfigManager.state.lastConfig;
      this.state.cookiePolicyEnabled = !!cfg.cookie_policy;
      this.state.dataController = cfg.data_controller || '';
      return;
    }

    // 2. Wait for 'theme:api-loaded' event from AppConfigManager
    if (window.EventManager) {
      await new Promise((resolve) => {
        const timeout = setTimeout(resolve, 5000); // 5 second timeout
        EventManager.once('theme:api-loaded', (data) => {
          clearTimeout(timeout);
          const cfg = data?.config || {};
          this.state.cookiePolicyEnabled = !!cfg.cookie_policy;
          this.state.dataController = cfg.data_controller || '';
          resolve();
        });
      });

      // If lastConfig is still empty (timeout), try the fallback again
      if (window.AppConfigManager?.state?.lastConfig) {
        const cfg = AppConfigManager.state.lastConfig;
        this.state.cookiePolicyEnabled = !!cfg.cookie_policy;
        this.state.dataController = cfg.data_controller || '';
      }
      return;
    }

    // 3. Fallback: call the API directly
    try {
      const baseUrl = window.WEB_URL || '/';
      const url = `${baseUrl}api/index/config/frontend-settings`;
      const res = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {'Accept': 'application/json'}
      });
      if (res.ok) {
        const json = await res.json();
        const cfg = json.data || json;
        this.state.cookiePolicyEnabled = !!cfg.cookie_policy;
        this.state.dataController = cfg.data_controller || '';
      }
    } catch (e) {
      // Do not show the banner if we can't load configuration
    }
  },

  /**
   * Check whether a valid consent record exists
   * @returns {boolean}
   * @private
   */
  _hasValidConsent() {
    try {
      const raw = localStorage.getItem(this.STORAGE_KEY);
      if (!raw) return false;
      const data = JSON.parse(raw);
      // Check version
      if (data.version !== this.POLICY_VERSION) return false;
      // Check age
      const ageMs = Date.now() - (data.timestamp || 0);
      const maxMs = this.CONSENT_DAYS * 24 * 60 * 60 * 1000;
      return ageMs < maxMs;
    } catch (e) {
      return false;
    }
  },

  /**
   * Save consent (minimal data: timestamp + version)
   * @param {boolean} accepted - true = accepted, false = declined
   * @private
   */
  _saveConsent(accepted) {
    try {
      const data = {
        version: this.POLICY_VERSION,
        timestamp: Date.now(),
        accepted: accepted
      };
      localStorage.setItem(this.STORAGE_KEY, JSON.stringify(data));
    } catch (e) {
      // localStorage not available (e.g. private browsing)
    }
  },

  /**
   * Clear stored consent (useful for testing)
   */
  clearConsent() {
    try {
      localStorage.removeItem(this.STORAGE_KEY);
    } catch (e) {}
  },

  /**
   * Return stored consent
   * @returns {{accepted: boolean, timestamp: number}|null}
   */
  getConsent() {
    try {
      const raw = localStorage.getItem(this.STORAGE_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch (e) {
      return null;
    }
  },

  /**
   * Build and display the banner
   * @private
   */
  _render() {
    if (this.state.bannerEl) return;

    const dpo = this.state.dataController
      ? `<span class="cookie-banner__dpo">${Utils.string.escape(this.state.dataController)}</span>`
      : '';

    const banner = document.createElement('div');
    banner.id = 'cookie-consent-banner';
    banner.className = 'cookie-banner';
    banner.setAttribute('role', 'dialog');
    banner.setAttribute('aria-label', 'Cookie Consent');
    banner.setAttribute('aria-live', 'polite');

    banner.innerHTML = `
      <article class="cookie-banner__inner">
        <div class="cookie-banner__icon" aria-hidden="true">🍪</div>
        <div class="cookie-banner__content">
          <h1 data-i18n>Cookie Policy</h1>
          <p>
            <span data-i18n>This website uses cookies that are necessary for the system to function. These cookies do not store personal information and cannot be turned off. Your continued use of the website constitutes your acknowledgment.</span>
            <a href="${WEB_URL}privacy" data-cookie-more data-i18n>Privacy Policy</a>
          </p>
          <p class="cookie-banner__dpo">
            ${dpo ? `<span data-i18n>{LNG_Data Protection Officer} (DPO):&nbsp;${dpo}</span>` : ''}
          </p>
        </div>
        <div class="cookie-banner__actions">
          <button type="button" class="cookie-banner__btn cookie-banner__btn--accept" data-cookie-accept data-i18n>Accept</button>
          <button type="button" class="cookie-banner__btn cookie-banner__btn--decline" data-cookie-decline data-i18n>Refuse</button>
        </div>
      </article>
    `;

    // Inject CSS inline immediately (no build pipeline required)
    this._injectStyles();

    document.body.appendChild(banner);
    this.state.bannerEl = banner;

    // Event: accept
    banner.querySelectorAll('[data-cookie-accept]').forEach(btn => {
      btn.addEventListener('click', () => this._accept());
    });

    // Event: decline / close
    banner.querySelectorAll('[data-cookie-decline]').forEach(btn => {
      btn.addEventListener('click', () => this._decline());
    });

    // Animation: slide up
    requestAnimationFrame(() => {
      banner.classList.add('cookie-banner--visible');
    });
  },

  /**
   * User accepted
   * @private
   */
  _accept() {
    this._saveConsent(true);
    this._dismiss();
    if (window.EventManager?.emit) {
      EventManager.emit('cookie:accepted', {timestamp: Date.now()});
    }
  },

  /**
   * User declined / closed
   * @private
   */
  _decline() {
    this._saveConsent(false);
    this._dismiss();
    if (window.EventManager?.emit) {
      EventManager.emit('cookie:declined', {timestamp: Date.now()});
    }
  },

  /**
   * Hide and remove the banner
   * @private
   */
  _dismiss() {
    const el = this.state.bannerEl;
    if (!el) return;

    el.classList.remove('cookie-banner--visible');
    el.classList.add('cookie-banner--hiding');

    el.addEventListener('transitionend', () => {
      el.remove();
      this.state.bannerEl = null;
    }, {once: true});

    // fallback if transitionend does not fire
    setTimeout(() => {
      if (this.state.bannerEl) {
        el.remove();
        this.state.bannerEl = null;
      }
    }, 500);
  },

  /**
   * Inject CSS for the banner using Now.js CSS variables
   * @private
   */
  _injectStyles() {
    if (document.getElementById('cookie-banner-styles')) return;

    const style = document.createElement('style');
    style.id = 'cookie-banner-styles';
    style.textContent = `
      .cookie-banner {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        z-index: calc(var(--z-index-toast, 1090) + 10);
        background: var(--dialog-bg, #ffffff);
        border-top: 1px solid var(--color-border, rgba(0,0,0,0.1));
        box-shadow: var(--shadow-xl, 0 -4px 20px rgba(0,0,0,0.12));
        font-family: var(--font-family-base, THSarabunNew, Tahoma, sans-serif);
        font-size: var(--font-size-base, 1rem);
        color: var(--dialog-text, #1e293b);
        transform: translateY(100%);
        opacity: 0;
        transition: transform 0.35s var(--transition-timing, cubic-bezier(0.4,0,0.2,1)),
                    opacity 0.35s var(--transition-timing, cubic-bezier(0.4,0,0.2,1));
        padding: var(--space-4, 1rem) var(--space-6, 1.5rem);
      }
      .cookie-banner--visible {
        transform: translateY(0);
        opacity: 1;
      }
      .cookie-banner--hiding {
        transform: translateY(100%);
        opacity: 0;
      }
      .cookie-banner__inner {
        display: flex;
        align-items: flex-start;
        gap: var(--space-4, 1rem);
        max-width: 1200px;
        margin: 0 auto;
        position: relative;
      }
      .cookie-banner__icon {
        font-size: 1.75rem;
        line-height: 1;
        flex-shrink: 0;
        margin-top: 2px;
      }
      .cookie-banner__content {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: var(--space-2, 0.5rem);
      }
      .cookie-banner__inner h1 {
        font-weight: var(--font-weight-semibold, 600);
        font-size: var(--font-size-lg, 1.125rem);
        color: var(--color-text);
      }
      .cookie-banner__inner a {
        color: var(--color-primary);
      }
      .cookie-banner__inner a:hover {
        color: var(--color-primary-hover);
      }
      .cookie-banner__dpo {
        font-size: var(--font-size-sm);
        color: var(--color-text-muted);
      }
      .cookie-banner__actions {
        display: flex;
        gap: var(--space-2, 0.5rem);
        align-items: center;
        flex-shrink: 0;
      }
      .cookie-banner__btn {
        padding: var(--space-2, 0.5rem) var(--space-4, 1rem);
        border-radius: var(--button-border-radius, 5px);
        font-family: inherit;
        font-size: var(--font-size-sm, 0.875rem);
        font-weight: var(--font-weight-medium, 500);
        cursor: pointer;
        border: 1px solid transparent;
        transition: background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease;
        white-space: nowrap;
        line-height: 1.5;
      }
      .cookie-banner__btn--accept {
        background: var(--color-primary, #2563eb);
        color: var(--color-primary-text, #ffffff);
        border-color: var(--color-primary, #2563eb);
      }
      .cookie-banner__btn--accept:hover {
        background: var(--color-primary-hover, #1d4ed8);
        border-color: var(--color-primary-hover, #1d4ed8);
      }
      .cookie-banner__btn--decline {
        background: transparent;
        color: var(--color-text-muted, #64748b);
        border-color: var(--color-border, rgba(0,0,0,0.2));
      }
      .cookie-banner__btn--decline:hover {
        background: var(--color-surface-hover, rgba(0,0,0,0.05));
        color: var(--color-text, #1e293b);
      }
      .cookie-banner__detail {
        max-width: 1200px;
        margin: var(--space-3, 0.75rem) auto 0;
        padding: var(--space-3, 0.75rem) var(--space-4, 1rem);
        background: var(--color-surface, rgba(0,0,0,0.03));
        border-radius: var(--border-radius-md, 0.375rem);
        font-size: var(--font-size-sm, 0.875rem);
        color: var(--color-text-muted, #64748b);
        line-height: var(--line-height-loose, 1.75);
      }
      .cookie-banner__detail p {
        margin: 0 0 var(--space-2, 0.5rem);
      }
      .cookie-banner__detail p:last-child {
        margin-bottom: 0;
      }
      @media (max-width: 640px) {
        .cookie-banner {
          padding: var(--space-3, 0.75rem) var(--space-4, 1rem);
        }
        .cookie-banner__inner {
          flex-wrap: wrap;
        }
        .cookie-banner__icon {
          display: none;
        }
        .cookie-banner__actions {
          width: 100%;
          justify-content: flex-end;
          margin-top: var(--space-2, 0.5rem);
        }
      }
    `;

    document.head.appendChild(style);
  }
};

// Auto-init when the script loads
CookieBanner.init();

// Expose globally
window.CookieBanner = CookieBanner;
