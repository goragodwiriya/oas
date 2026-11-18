/**
 * RichTextEditor - Font families
 * Built-in catalog of Thai-capable Google Fonts plus the helpers that turn the
 * `fontFamily.fonts` option into dropdown items and load Google Fonts.
 *
 * Option (defaults.js → fontFamily):
 *   fonts: null                       // null = defaultFonts below
 *   fonts: ['Sarabun', 'Kanit', 'Tahoma', 'Georgia, serif',
 *           {label: 'Roboto', google: true},
 *           {label: 'Brand', value: "'Brand Font', sans-serif"}]
 *   loadGoogleFonts: true             // false = the page loads the fonts itself
 *   googleFontsUrl: 'https://fonts.googleapis.com/css2'
 *
 * A plain name found in `googleFonts` is loaded from Google Fonts; any other
 * string is used as a CSS font stack (label = its first family).
 * The same list can be given per textarea: data-rte-fonts="Sarabun|Kanit|Tahoma"
 *
 * @author Goragod Wiriya
 * @version 1.0
 */

/**
 * Thai-capable families on Google Fonts → generic fallback family.
 * Keep in step with the catalog the site front end uses to load these fonts
 * for published content (GCMS: Gcms/GoogleFonts.php).
 */
export const googleFonts = {
  'Sarabun': 'sans-serif',
  'Prompt': 'sans-serif',
  'Kanit': 'sans-serif',
  'Mitr': 'sans-serif',
  'Noto Sans Thai': 'sans-serif',
  'Noto Sans Thai Looped': 'sans-serif',
  'Noto Serif Thai': 'serif',
  'IBM Plex Sans Thai': 'sans-serif',
  'IBM Plex Sans Thai Looped': 'sans-serif',
  'Anuphan': 'sans-serif',
  'Chakra Petch': 'sans-serif',
  'Bai Jamjuree': 'sans-serif',
  'K2D': 'sans-serif',
  'Krub': 'sans-serif',
  'KoHo': 'sans-serif',
  'Kodchasan': 'sans-serif',
  'Niramit': 'sans-serif',
  'Fahkwang': 'sans-serif',
  'Thasadith': 'sans-serif',
  'Athiti': 'sans-serif',
  'Pattaya': 'sans-serif',
  'Pridi': 'serif',
  'Taviraj': 'serif',
  'Trirong': 'serif',
  'Maitree': 'serif',
  'Chonburi': 'serif',
  'Mali': 'cursive',
  'Itim': 'cursive',
  'Sriracha': 'cursive',
  'Charm': 'cursive',
  'Charmonman': 'cursive',
  'Srisakdi': 'cursive'
};

/**
 * Dropdown list used when `fontFamily.fonts` is not configured:
 * the commonly used Thai Google Fonts, then fonts every system has.
 */
export const defaultFonts = [
  'Sarabun', 'Prompt', 'Kanit', 'Mitr', 'Noto Sans Thai', 'Noto Serif Thai',
  'IBM Plex Sans Thai', 'Anuphan', 'Chakra Petch', 'Bai Jamjuree', 'K2D', 'Krub',
  'Niramit', 'Athiti', 'Pridi', 'Taviraj', 'Trirong', 'Maitree',
  'Mali', 'Itim', 'Sriracha', 'Charm', 'Chonburi', 'Pattaya',
  'Tahoma, sans-serif', 'Arial, Helvetica, sans-serif', 'Georgia, serif',
  "'Times New Roman', Times, serif", "'Courier New', Courier, monospace"
];

/**
 * Styles requested for every Google font — regular and bold, upright and italic.
 * Google Fonts skips the styles a family does not have instead of failing.
 */
const GOOGLE_FONT_AXES = 'ital,wght@0,400;0,700;1,400;1,700';

const catalogIndex = new Map(Object.keys(googleFonts).map(name => [name.toLowerCase(), name]));

/**
 * First family of a CSS font-family value, unquoted and lower-cased
 * ("'Noto Sans Thai', sans-serif" → "noto sans thai").
 * @param {string} value
 * @returns {string}
 */
export function firstFamily(value) {
  return String(value || '')
    .split(',')[0]
    .trim()
    .replace(/^["']|["']$/g, '')
    .trim()
    .toLowerCase();
}

/**
 * Catalog name of a Google font, whatever the case it is written in.
 * @param {string} name
 * @returns {string|null}
 */
export function findGoogleFont(name) {
  return catalogIndex.get(String(name || '').trim().toLowerCase()) || null;
}

/**
 * Quote a family name for CSS when it is not a single identifier.
 * @param {string} name
 * @returns {string}
 */
function quoteFamily(name) {
  return /^[a-z][a-z0-9-]*$/i.test(name) ? name : `'${name.replace(/'/g, '')}'`;
}

/**
 * Normalize one `fontFamily.fonts` entry into a dropdown item.
 * @param {string|Object} entry
 * @returns {{label: string, value: string, fontFamily: string, google: string|null}|null}
 */
export function normalizeFont(entry) {
  if (typeof entry === 'string') {
    entry = entry.trim();
    if (!entry) return null;
    const google = findGoogleFont(entry);
    if (google) {
      entry = {label: google, google: true};
    } else {
      const label = entry.split(',')[0].trim().replace(/^["']|["']$/g, '');
      return {label, value: entry, fontFamily: entry, google: null};
    }
  }

  if (!entry || typeof entry !== 'object') return null;

  const label = String(entry.label || firstFamily(entry.value) || '').trim();
  if (!label) return null;

  let google = null;
  if (entry.google === true) {
    google = findGoogleFont(label) || label;
  } else if (typeof entry.google === 'string' && entry.google.trim()) {
    google = entry.google.trim();
  }

  const generic = (google && googleFonts[google]) || 'sans-serif';
  const value = entry.value
    ? String(entry.value)
    : `${quoteFamily(google || label)}, ${generic}`;

  return {label, value, fontFamily: value, google};
}

/**
 * Dropdown items for the configured fonts, led by a "Default" item that
 * removes the font from the selection.
 * @param {Object} config - editor.options.fontFamily
 * @returns {Array<Object>}
 */
export function resolveFonts(config = {}) {
  const source = Array.isArray(config?.fonts) && config.fonts.length ? config.fonts : defaultFonts;
  const seen = new Set();
  const items = [{label: 'Default', value: '', google: null}];

  source.forEach(entry => {
    const item = normalizeFont(entry);
    if (!item) return;
    const key = firstFamily(item.value);
    if (seen.has(key)) return;
    seen.add(key);
    items.push(item);
  });

  return items;
}

/**
 * Parse a data-rte-fonts attribute: a JSON array, or names separated by "|".
 * @param {string} text
 * @returns {Array|null}
 */
export function parseFontList(text) {
  if (!text || !String(text).trim()) return null;
  const raw = String(text).trim();
  if (raw.startsWith('[')) {
    try {
      const list = JSON.parse(raw);
      return Array.isArray(list) ? list : null;
    } catch (e) {
      return null;
    }
  }
  return raw.split('|').map(s => s.trim()).filter(Boolean);
}

/**
 * Google Fonts stylesheet URL for the given families.
 * @param {string[]} families
 * @param {string} baseUrl
 * @returns {string}
 */
export function googleFontsHref(families, baseUrl = 'https://fonts.googleapis.com/css2') {
  const params = families
    .map(name => `family=${encodeURIComponent(name).replace(/%20/g, '+')}:${GOOGLE_FONT_AXES}`)
    .join('&');
  return `${baseUrl}?${params}&display=swap`;
}

const loadedFamilies = new Set();

/**
 * Add a stylesheet for the Google fonts not loaded yet by any editor on the page.
 * @param {string[]} families
 * @param {Object} config - editor.options.fontFamily
 */
export function loadGoogleFonts(families, config = {}) {
  if (config?.loadGoogleFonts === false || typeof document === 'undefined') return;

  const pending = [...new Set((families || []).filter(Boolean))]
    .filter(name => !loadedFamilies.has(name.toLowerCase()));
  if (!pending.length) return;
  pending.forEach(name => loadedFamilies.add(name.toLowerCase()));

  const baseUrl = config?.googleFontsUrl || 'https://fonts.googleapis.com/css2';
  const head = document.head || document.documentElement;

  if (!document.querySelector('link[data-rte-fonts-preconnect]')) {
    try {
      const origin = new URL(baseUrl, window.location.href).origin;
      const preconnect = document.createElement('link');
      preconnect.rel = 'preconnect';
      preconnect.href = origin;
      preconnect.setAttribute('data-rte-fonts-preconnect', '');
      head.appendChild(preconnect);
    } catch (e) {
      // Relative or invalid URL — skip the hint
    }
  }

  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.href = googleFontsHref(pending, baseUrl);
  link.setAttribute('data-rte-fonts', '');
  head.appendChild(link);
}

/**
 * Load the catalog Google fonts that content inside `root` uses, so text saved
 * with a font that is not in the dropdown still shows in that font.
 * @param {HTMLElement} root
 * @param {Object} config - editor.options.fontFamily
 */
export function loadFontsUsedIn(root, config = {}) {
  if (!root || config?.loadGoogleFonts === false) return;
  const families = [];
  root.querySelectorAll('[style*="font-family"], font[face]').forEach(el => {
    const google = findGoogleFont(firstFamily(el.style?.fontFamily || el.getAttribute('face')));
    if (google) families.push(google);
  });
  loadGoogleFonts(families, config);
}

export default {
  googleFonts,
  defaultFonts,
  firstFamily,
  findGoogleFont,
  normalizeFont,
  resolveFonts,
  parseFontList,
  googleFontsHref,
  loadGoogleFonts,
  loadFontsUsedIn
};
