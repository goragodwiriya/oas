/**
 * CookieBanner - PDPA cookie consent bar
 *
 * Shown when the site turns on "Show Cookie Policy" (settings → Cookie Policy,
 * config cookie_policy) and the visitor has not answered yet:
 * - a card at the bottom centre of the screen; full width on a phone
 * - links to the privacy policy (WEB_URL/privacy) and the terms of service
 *   (WEB_URL/terms), and the e-mail of the Data Protection Officer
 *   (config data_controller) when it is set
 * - Accept / Refuse are kept in localStorage (timestamp, version) for 365 days;
 *   the cookies the system needs to work are not affected by the answer
 *
 * The settings come from api/index/config/frontend-settings: through
 * AppConfigManager when the page uses it, else straight from the API (a page
 * without AppConfigManager used to wait for its event and never showed the bar).
 *
 * Events (EventManager): cookie:accepted, cookie:declined
 */
const CookieBanner = {
  /**
   * localStorage key of the answer (kept out of cookies on purpose: it is read by
   * JavaScript only and holds nothing personal)
   */
  STORAGE_KEY: 'cookie_consent',

  /**
   * Bump when the policy changes, so every visitor is asked again
   */
  POLICY_VERSION: '1',

  /**
   * Days an answer is kept before the bar is shown again
   */
  CONSENT_DAYS: 365,

  /**
   * How long (ms) to wait for AppConfigManager before asking the API directly
   */
  CONFIG_WAIT: 3000,

  state: {
    initialized: false,
    bannerEl: null,
    cookiePolicyEnabled: false,
    dataController: ''
  },

  /**
   * Show the bar if the site asks for it and the visitor has not answered.
   * Runs when the script loads.
   */
  async init() {
    if (this.state.initialized) return;
    this.state.initialized = true;

    if (this._hasValidConsent()) return;

    await this._loadConfig();
    if (!this.state.cookiePolicyEnabled) return;

    if (document.body) {
      this._render();
    } else {
      document.addEventListener('DOMContentLoaded', () => this._render(), {once: true});
    }
  },

  /**
   * Where the site's pages are: WEB_URL of a theme (gcms), the router base of the
   * SPA (adminframework and its projects), else the root. Ends with '/'.
   *
   * @returns {string}
   */
  siteUrl() {
    let base = '/';
    if (typeof WEB_URL === 'string' && WEB_URL !== '') {
      base = WEB_URL;
    } else if (window.RouterManager?.config?.base) {
      base = RouterManager.config.base;
    }
    return base.endsWith('/') ? base : base + '/';
  },

  /**
   * Read cookie_policy and data_controller from the site settings
   * @private
   */
  async _loadConfig() {
    const apply = (cfg) => {
      cfg = cfg || {};
      const flag = cfg.cookie_policy;
      this.state.cookiePolicyEnabled = flag === true || flag === 1 || flag === '1';
      this.state.dataController = typeof cfg.data_controller === 'string' ? cfg.data_controller.trim() : '';
    };

    // 1. Settings AppConfigManager already loaded
    if (window.AppConfigManager?.state?.lastConfig) {
      apply(AppConfigManager.state.lastConfig);
      return;
    }

    // 2. AppConfigManager is still loading them: wait for its answer (a short while).
    // It is in every page of the core bundle, but loads the settings only when
    // Now.init() turned it on with an API url and it has not finished yet.
    const manager = window.AppConfigManager;
    const loading = manager && manager.config?.enabled && manager.config?.api?.enabled
      && manager.config?.api?.configUrl && !manager.state?.disabled && !manager.state?.initialized;
    if (loading && window.EventManager?.once) {
      const cfg = await new Promise((resolve) => {
        const timer = setTimeout(() => resolve(null), this.CONFIG_WAIT);
        EventManager.once('theme:api-loaded', (data) => {
          clearTimeout(timer);
          resolve(data?.config || null);
        });
        EventManager.once('theme:api-error', () => {
          clearTimeout(timer);
          resolve(null);
        });
      });
      const loaded = cfg || window.AppConfigManager?.state?.lastConfig;
      if (loaded) {
        apply(loaded);
        return;
      }
    }

    // 3. No AppConfigManager, or it did not answer: ask the API
    try {
      const response = await fetch(`${this.siteUrl()}api/index/config/frontend-settings`, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {'Accept': 'application/json'}
      });
      if (response.ok) {
        const json = await response.json();
        apply(json?.data || json);
      }
    } catch (e) {
      // Settings not reachable: no bar
    }
  },

  /**
   * @returns {boolean} Whether an answer of the current version, younger than CONSENT_DAYS, is kept
   * @private
   */
  _hasValidConsent() {
    try {
      const raw = localStorage.getItem(this.STORAGE_KEY);
      if (!raw) return false;
      const data = JSON.parse(raw);
      if (data.version !== this.POLICY_VERSION) return false;
      const ageMs = Date.now() - (data.timestamp || 0);
      return ageMs < this.CONSENT_DAYS * 24 * 60 * 60 * 1000;
    } catch (e) {
      return false;
    }
  },

  /**
   * Keep the answer (timestamp, version, accepted)
   * @param {boolean} accepted
   * @private
   */
  _saveConsent(accepted) {
    try {
      localStorage.setItem(this.STORAGE_KEY, JSON.stringify({
        version: this.POLICY_VERSION,
        timestamp: Date.now(),
        accepted: accepted
      }));
    } catch (e) {
      // localStorage not available (private browsing)
    }
  },

  /**
   * Forget the answer, so the bar is shown again
   */
  clearConsent() {
    try {
      localStorage.removeItem(this.STORAGE_KEY);
    } catch (e) {}
  },

  /**
   * @returns {{accepted: boolean, timestamp: number, version: string}|null} The kept answer
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
   * An element with text in the visitor's language. data-i18n keeps the English
   * key, so a language switch translates it again.
   *
   * @param {string} tag
   * @param {string} key - English text (language key)
   * @param {string} [className]
   * @returns {HTMLElement}
   * @private
   */
  _text(tag, key, className) {
    const el = document.createElement(tag);
    if (className) el.className = className;
    el.setAttribute('data-i18n', key);
    el.textContent = window.Now?.translate ? Now.translate(key) : key;
    return el;
  },

  /**
   * Build and show the bar (DOM only: the DPO e-mail comes from the settings)
   * @private
   */
  _render() {
    if (this.state.bannerEl) return;
    this._injectStyles();

    const site = this.siteUrl();
    const banner = document.createElement('section');
    banner.id = 'cookie-consent-banner';
    banner.className = 'cookie-banner';
    banner.setAttribute('role', 'region');
    banner.setAttribute('aria-label', window.Now?.translate ? Now.translate('Cookie Policy') : 'Cookie Policy');

    const inner = document.createElement('div');
    inner.className = 'cookie-banner__inner';

    const icon = document.createElement('div');
    icon.className = 'cookie-banner__icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = '🍪';

    const content = document.createElement('div');
    content.className = 'cookie-banner__content';
    content.append(
      this._text('h2', 'Cookie Policy', 'cookie-banner__title'),
      this._text('p', 'This website uses cookies that are necessary for the system to function. These cookies do not store personal information and cannot be turned off. Your continued use of the website constitutes your acknowledgment.', 'cookie-banner__text')
    );

    const links = document.createElement('p');
    links.className = 'cookie-banner__links';
    const privacy = this._text('a', 'Privacy Policy');
    privacy.href = `${site}privacy`;
    privacy.setAttribute('data-cookie-more', '');
    const terms = this._text('a', 'Terms of Service');
    terms.href = `${site}terms`;
    links.append(privacy, terms);
    content.appendChild(links);

    if (this.state.dataController !== '') {
      const dpo = document.createElement('p');
      dpo.className = 'cookie-banner__dpo';
      const mail = document.createElement('a');
      mail.href = `mailto:${this.state.dataController}`;
      mail.textContent = this.state.dataController;
      dpo.append(this._text('span', 'Data Protection Officer'), document.createTextNode(' (DPO): '), mail);
      content.appendChild(dpo);
    }

    const actions = document.createElement('div');
    actions.className = 'cookie-banner__actions';
    const accept = this._text('button', 'Accept', 'cookie-banner__btn cookie-banner__btn--accept');
    accept.type = 'button';
    accept.setAttribute('data-cookie-accept', '');
    accept.addEventListener('click', () => this._accept());
    const decline = this._text('button', 'Refuse', 'cookie-banner__btn cookie-banner__btn--decline');
    decline.type = 'button';
    decline.setAttribute('data-cookie-decline', '');
    decline.addEventListener('click', () => this._decline());
    actions.append(accept, decline);

    inner.append(icon, content, actions);
    banner.appendChild(inner);
    document.body.appendChild(banner);
    this.state.bannerEl = banner;

    requestAnimationFrame(() => banner.classList.add('cookie-banner--visible'));
  },

  /**
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
   * Slide the bar out and remove it
   * @private
   */
  _dismiss() {
    const el = this.state.bannerEl;
    if (!el) return;
    this.state.bannerEl = null;
    el.classList.remove('cookie-banner--visible');
    el.classList.add('cookie-banner--hiding');
    el.addEventListener('transitionend', () => el.remove(), {once: true});
    // transitionend does not fire with transitions off (prefers-reduced-motion)
    setTimeout(() => el.remove(), 500);
  },

  /**
   * Styles of the bar, with the Now.js CSS variables
   * @private
   */
  _injectStyles() {
    if (document.getElementById('cookie-banner-styles')) return;

    const style = document.createElement('style');
    style.id = 'cookie-banner-styles';
    style.textContent = `
      .cookie-banner {
        position: fixed;
        left: 50%;
        bottom: var(--space-4, 1rem);
        width: min(760px, calc(100% - 2 * var(--space-4, 1rem)));
        box-sizing: border-box;
        z-index: calc(var(--z-index-toast, 1090) + 10);
        background: var(--dialog-bg, #ffffff);
        color: var(--dialog-text, #1e293b);
        border: 1px solid var(--color-border, rgba(0, 0, 0, 0.1));
        border-radius: var(--border-radius-lg, 0.75rem);
        box-shadow: var(--shadow-xl, 0 10px 30px rgba(0, 0, 0, 0.18));
        font-family: var(--font-family-base, inherit);
        font-size: var(--font-size-base, 1rem);
        padding: var(--space-4, 1rem) var(--space-5, 1.25rem);
        transform: translate(-50%, calc(100% + var(--space-6, 1.5rem)));
        opacity: 0;
        transition: transform 0.35s var(--transition-timing, cubic-bezier(0.4, 0, 0.2, 1)),
                    opacity 0.35s var(--transition-timing, cubic-bezier(0.4, 0, 0.2, 1));
      }
      .cookie-banner--visible {
        transform: translate(-50%, 0);
        opacity: 1;
      }
      .cookie-banner__inner {
        display: flex;
        align-items: center;
        gap: var(--space-4, 1rem);
      }
      .cookie-banner__icon {
        font-size: 2rem;
        line-height: 1;
        flex-shrink: 0;
      }
      .cookie-banner__content {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: var(--space-1, 0.25rem);
      }
      .cookie-banner__title {
        margin: 0;
        font-size: var(--font-size-lg, 1.125rem);
        font-weight: var(--font-weight-semibold, 600);
        color: var(--color-text, inherit);
      }
      .cookie-banner__text,
      .cookie-banner__links,
      .cookie-banner__dpo {
        margin: 0;
        line-height: var(--line-height-base, 1.5);
      }
      .cookie-banner__links {
        display: flex;
        flex-wrap: wrap;
        gap: var(--space-1, 0.25rem) var(--space-4, 1rem);
      }
      .cookie-banner a {
        color: var(--color-primary, #2563eb);
        text-decoration: underline;
      }
      .cookie-banner a:hover {
        color: var(--color-primary-hover, #1d4ed8);
      }
      .cookie-banner__dpo {
        font-size: var(--font-size-sm, 0.875rem);
        color: var(--color-text-muted, #64748b);
      }
      .cookie-banner__actions {
        display: flex;
        flex-direction: column;
        gap: var(--space-2, 0.5rem);
        flex-shrink: 0;
      }
      .cookie-banner__btn {
        padding: var(--space-2, 0.5rem) var(--space-4, 1rem);
        border-radius: var(--button-border-radius, 5px);
        border: 1px solid transparent;
        font-family: inherit;
        font-size: var(--font-size-sm, 0.875rem);
        font-weight: var(--font-weight-medium, 500);
        line-height: 1.5;
        white-space: nowrap;
        cursor: pointer;
        transition: background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease;
      }
      .cookie-banner__btn--accept {
        background: var(--color-primary, #2563eb);
        border-color: var(--color-primary, #2563eb);
        color: var(--color-primary-text, #ffffff);
      }
      .cookie-banner__btn--accept:hover {
        background: var(--color-primary-hover, #1d4ed8);
        border-color: var(--color-primary-hover, #1d4ed8);
      }
      .cookie-banner__btn--decline {
        background: transparent;
        border-color: var(--color-border, rgba(0, 0, 0, 0.2));
        color: var(--color-text-muted, #64748b);
      }
      .cookie-banner__btn--decline:hover {
        background: var(--color-surface-hover, rgba(0, 0, 0, 0.05));
        color: var(--color-text, #1e293b);
      }
      @media (max-width: 640px) {
        .cookie-banner {
          left: 0;
          right: 0;
          bottom: 0;
          width: 100%;
          border-radius: 0;
          border-left: 0;
          border-right: 0;
          border-bottom: 0;
          padding: var(--space-3, 0.75rem) var(--space-4, 1rem) calc(var(--space-3, 0.75rem) + env(safe-area-inset-bottom, 0px));
          transform: translateY(100%);
        }
        .cookie-banner--visible {
          transform: translateY(0);
        }
        .cookie-banner__inner {
          flex-wrap: wrap;
          align-items: flex-start;
        }
        .cookie-banner__icon {
          display: none;
        }
        .cookie-banner__actions {
          width: 100%;
          flex-direction: row;
        }
        .cookie-banner__btn {
          flex: 1;
        }
      }
      @media (prefers-reduced-motion: reduce) {
        .cookie-banner {
          transition: none;
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
