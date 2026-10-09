/**
 * Now.js – IconPickerComponent
 *
 * Reusable icon-picker: renders a radio button grid for selecting an icomoon icon class.
 * Compatible with the GCMS admin form data-attr binding system.
 *
 * Usage in templates (data-attr binding from form API load):
 *   <div data-component="icon-picker" data-name="icon"></div>
 *
 * Usage with a static pre-selected value:
 *   <div data-component="icon-picker" data-name="icon" data-value="icon-home"></div>
 *
 * Props:
 *   data-name   — radio input `name` attribute (default: 'icon')
 *   data-value  — initially selected icon class; omit when using data-attr form binding
 *
 * Programmatic API:
 *   IconPicker.create(el, { name: 'icon', value: 'icon-home' })
 *   IconPicker.icons()   → string[] of all available icon class names
 *
 * @filesource Now/js/IconPickerComponent.js
 */

/**
 * Centralized icon list — single source of truth for all icon-picker usages.
 * Each entry: { value: 'icon-xxx', label: 'display name' }
 * Add new icons here to make them available everywhere.
 */
// Default (fallback) icon list — used immediately and as a last-resort fallback.
const _DEFAULT_ICON_LIST = [
    {value: 'icon-home', label: 'home'},
    {value: 'icon-dashboard', label: 'dashboard'},
    {value: 'icon-menus', label: 'bars'},
    {value: 'icon-settings', label: 'settings'},
    {value: 'icon-config', label: 'config'},
    {value: 'icon-user', label: 'user'},
    {value: 'icon-users', label: 'users'},
    {value: 'icon-personnel', label: 'personnel'},
    {value: 'icon-profile', label: 'profile'},
    {value: 'icon-customer', label: 'customer'},
    {value: 'icon-document', label: 'document'},
    {value: 'icon-documents', label: 'documents'},
    {value: 'icon-file', label: 'file'},
    {value: 'icon-news', label: 'news'},
    {value: 'icon-image', label: 'image'},
    {value: 'icon-gallery', label: 'gallery'},
    {value: 'icon-video', label: 'video'},
    {value: 'icon-email', label: 'email'},
    {value: 'icon-phone', label: 'phone'},
    {value: 'icon-forum', label: 'forum'},
    {value: 'icon-comments', label: 'comments'},
    {value: 'icon-product', label: 'product'},
    {value: 'icon-cart', label: 'cart'},
    {value: 'icon-category', label: 'category'},
    {value: 'icon-payment', label: 'payment'},
    {value: 'icon-billing', label: 'billing'},
    {value: 'icon-money', label: 'money'},
    {value: 'icon-stats', label: 'stats'},
    {value: 'icon-chart', label: 'chart'},
    {value: 'icon-pie', label: 'pie'},
    {value: 'icon-calendar', label: 'calendar'},
    {value: 'icon-search', label: 'search'},
    {value: 'icon-filter', label: 'filter'},
    {value: 'icon-modules', label: 'modules'},
    {value: 'icon-tools', label: 'tools'},
    {value: 'icon-database', label: 'database'},
    {value: 'icon-code', label: 'code'},
    {value: 'icon-world', label: 'world'},
    {value: 'icon-link', label: 'link'},
    {value: 'icon-share', label: 'share'},
    {value: 'icon-forward', label: 'forward'},
    {value: 'icon-info', label: 'info'},
    {value: 'icon-help', label: 'help'},
    {value: 'icon-question', label: 'question'},
    {value: 'icon-warning', label: 'warning'},
    {value: 'icon-flag', label: 'flag'},
    {value: 'icon-star0', label: 'star'},
    {value: 'icon-star1', label: 'star+'},
    {value: 'icon-star2', label: 'star++'},
    {value: 'icon-heart', label: 'heart'},
    {value: 'icon-favorite', label: 'favorite'},
    {value: 'icon-bookmark', label: 'bookmark'},
    {value: 'icon-edit', label: 'edit'},
    {value: 'icon-new', label: 'new'},
    {value: 'icon-report', label: 'report'},
    {value: 'icon-summary', label: 'summary'},
    {value: 'icon-map', label: 'map'},
    {value: 'icon-location', label: 'location'},
    {value: 'icon-rocket', label: 'rocket'},
    {value: 'icon-gift', label: 'gift'},
    {value: 'icon-ticket', label: 'ticket'},
    {value: 'icon-event', label: 'event'},
    {value: 'icon-elearning', label: 'elearning'},
    {value: 'icon-board', label: 'board'},
    {value: 'icon-ads', label: 'ads'},
    {value: 'icon-template', label: 'template'},
    {value: 'icon-widgets', label: 'widgets'},
    {value: 'icon-leaf', label: 'leaf'},
    {value: 'icon-food', label: 'food'},
    {value: 'icon-hotel', label: 'hotel'},
    {value: 'icon-doctor', label: 'doctor'},
    {value: 'icon-service', label: 'service'},
    {value: 'icon-support', label: 'support'},
];

// Exported list used by the picker. We initialize with the default list,
// then attempt to refresh from selection.json or fonts.css on demand.
export let ICON_LIST = _DEFAULT_ICON_LIST.slice();

// Internal flag to avoid multiple concurrent refreshes
let _iconsLoaded = false;

/**
 * Try to load icons from an IcoMoon `selection.json` file.
 * Returns an array of `{value,label}` or null on failure.
 */
function _loadFromSelectionJson() {
    const candidates = ['/Now/css/selection.json', '/Now/fonts/selection.json', '/Now/selection.json', 'Now/css/selection.json'];
    const tryFetch = (url) => fetch(url, {cache: 'no-cache'}).then(r => {
        if (!r.ok) throw new Error('not ok');
        return r.json();
    });

    // Try each candidate in sequence
    let p = Promise.reject();
    candidates.forEach(url => {
        p = p.catch(() => tryFetch(url));
    });

    return p.then(json => {
        if (!json || !Array.isArray(json.icons)) return null;
        const prefix = (json.preferences && json.preferences.fontPref && json.preferences.fontPref.prefix) || 'icon-';
        const list = [];
        json.icons.forEach(ic => {
            const name = ic && ic.properties && ic.properties.name;
            if (name) list.push({value: prefix + name, label: name});
        });
        return list.length ? list : null;
    }).catch(() => null);
}

/**
 * Parse a fonts.css content and extract `.icon-...:before` selectors.
 * Returns array of `{value,label}` or null if none found.
 */
function _parseFontsCss(content) {
    if (!content) return null;
    const re = /\\.icon-([a-z0-9_\\-]+):before/gi;
    const names = new Set();
    let m;
    while ((m = re.exec(content)) !== null) {
        names.add(m[1]);
    }
    if (!names.size) return null;
    const list = Array.from(names).map(n => ({value: 'icon-' + n, label: n}));
    return list;
}

/**
 * Try to load icons by fetching fonts.css and parsing it.
 */
function _loadFromFontsCss() {
    const candidates = ['/Now/css/fonts.css', '/Now/fonts/fonts.css', '/Now/fonts.css', 'Now/css/fonts.css'];
    const tryFetch = (url) => fetch(url, {cache: 'no-cache'}).then(r => {
        if (!r.ok) throw new Error('not ok');
        return r.text();
    });

    let p = Promise.reject();
    candidates.forEach(url => {p = p.catch(() => tryFetch(url));});
    return p.then(txt => _parseFontsCss(txt)).catch(() => null);
}

/**
 * Refresh ICON_LIST using selection.json first, then fonts.css, dedupe results.
 * Returns a Promise resolving to the updated ICON_LIST.
 */
export function refreshIconList() {
    if (_iconsLoaded) return Promise.resolve(ICON_LIST);
    _iconsLoaded = true;
    return _loadFromSelectionJson().then(list => {
        if (!list || !list.length) return _loadFromFontsCss();
        return list;
    }).then(list => {
        if (!list || !list.length) return ICON_LIST; // keep default
        // Deduplicate by `value`
        const seen = new Set();
        const merged = [];
        // prefer incoming list order, then fall back to existing defaults
        list.concat(ICON_LIST).forEach(item => {
            if (!item || !item.value) return;
            if (seen.has(item.value)) return;
            seen.add(item.value);
            merged.push(item);
        });
        ICON_LIST = merged;
        return ICON_LIST;
    }).catch(() => ICON_LIST);
}

/* ── Helpers ─────────────────────────────────────────────── */

let _ipCounter = 0;

/**
 * Build the icon picker radio grid inside `el`.
 * Adds the `.icon-picker` class (matches existing admin CSS).
 * Each radio gets a `data-attr` binding so the GCMS form system
 * can check the correct radio after the API response loads.
 *
 * @param {HTMLElement} el
 * @param {string} name        — input `name` attribute
 * @param {string|null} value  — pre-selected value (null = use data-attr binding only)
 */
function _ipBuild(el, name, value) {
    el.classList.add('icon-picker');

    const uid = el._ipId;
    const esc = (s) => String(s).replace(/'/g, "\\'").replace(/"/g, '&quot;');

    // "None" option
    const noneId = `ip_${uid}_none`;
    let html = `<input type="radio" name="${esc(name)}" value="" id="${noneId}" data-attr="checked:${esc(name)}==''">`;
    html += `<label class="icon-option icon-none" for="${noneId}"><small data-i18n>None</small></label>`;

    // Icon options
    ICON_LIST.forEach(icon => {
        const id = `ip_${uid}_${icon.value}`;
        html += `<input type="radio" name="${esc(name)}" value="${icon.value}" id="${id}" data-attr="checked:${esc(name)}=='${icon.value}'">`;
        html += `<label class="icon-option ${icon.value}" for="${id}"><small>${icon.label}</small></label>`;
    });

    el.innerHTML = html;

    // Apply immediate selection when value is provided via prop
    if (value !== null && value !== undefined) {
        const target = el.querySelector(`input[value="${CSS.escape(String(value))}"]`);
        if (target) target.checked = true;
    }
}

/* ── ComponentManager registration ──────────────────────── */

if (window.ComponentManager) {
    ComponentManager.define('icon-picker', {
        /*
         * validElement: () => true — skip ComponentManager's own template processing;
         * mounted() builds the DOM imperatively (same pattern as ImageGallery).
         */
        validElement: () => true,

        mounted() {
            const el = this.element;
            el._ipId = ++_ipCounter;
            const name = this.props.name || 'icon';
            // data-value prop provides an immediate selection; omit it when relying on data-attr bindings.
            const value = this.props.value !== undefined ? this.props.value : null;
            // Refresh the icon list (selection.json or fonts.css) then build.
            refreshIconList().finally(() => _ipBuild(el, name, value));
        },

        destroyed() {
            // No async tasks or event listeners to clean up.
        },
    });
}

/* ── Public / programmatic API ───────────────────────────── */

const IconPicker = {
    /**
     * Build an icon picker inside `el` without the ComponentManager.
     * Useful inside the Designer inspector or any JS-rendered panel.
     *
     * @param {HTMLElement} el
     * @param {Object} [opts]
     * @param {string} [opts.name='icon']   — input name attribute
     * @param {string} [opts.value='']      — currently selected icon class
     * @returns {HTMLElement} el (for chaining)
     */
    create(el, {name = 'icon', value = ''} = {}) {
        el._ipId = ++_ipCounter;
        // Ensure the latest icon list is loaded before building.
        refreshIconList().finally(() => _ipBuild(el, name, value));
        return el;
    },

    /**
     * Update the selected value in an already-built picker.
     * @param {HTMLElement} el
     * @param {string} value
     */
    setValue(el, value) {
        const radio = el.querySelector(`input[type="radio"][value="${CSS.escape(String(value))}"]`);
        if (radio) radio.checked = true;
    },

    /**
     * Get the currently selected icon class from a picker element.
     * @param {HTMLElement} el
     * @returns {string}
     */
    getValue(el) {
        const checked = el.querySelector('input[type="radio"]:checked');
        return checked ? checked.value : '';
    },

    /**
     * All available icon class names (excludes the empty "None" option).
     * @returns {string[]}
     */
    icons() {
        return ICON_LIST.map(i => i.value);
    },
};

window.IconPicker = IconPicker;
