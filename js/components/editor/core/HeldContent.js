/**
 * HeldContent - keeps everything the author wrote while it is being edited,
 * without letting any of it run
 *
 * The editor posts content as it was written: the server decides what to keep.
 * So nothing is dropped when content is loaded — but a <script>, a form, an
 * onclick or an iframe must not come alive inside the admin page either.
 * While editing, each of those is *held*:
 *
 * - an element that would run, load or submit (script, style, link, meta, base,
 *   noscript, iframe, object, embed, form and its controls, active svg/math) is
 *   swapped for a placeholder <img>, like CKEditor's fake objects: an <img> is
 *   atomic, so it can be clicked, moved and deleted as one piece;
 * - an attribute that would run (on*, a javascript: URL, autofocus) is taken off
 *   its element, which stays editable.
 *
 * What was taken away lives in a registry in this module, and the page only
 * carries the id of the entry. getContent() puts every piece back exactly as it
 * was. An id cannot be forged: HTML that comes from outside (paste, drop) has no
 * way to add an entry, so the worst a copied id can do is put back something the
 * author already had.
 *
 * All parsing happens in a document that has no browsing context, where nothing
 * loads and no handler fires.
 *
 * @author Goragod Wiriya
 * @version 1.0
 */

/** On a placeholder <img>: the id of the element it stands for */
export const EMBED_ATTR = 'data-rte-embed';
/** On a placeholder <img>: the tag name of that element (script, style, iframe, form …) */
export const KIND_ATTR = 'data-rte-kind';
/** On an element that stays editable: the id of the attributes held back from it */
export const HELD_ATTR = 'data-rte-h';

export const EMBED_SELECTOR = `img[${EMBED_ATTR}]`;
export const MARKER_SELECTOR = `[${EMBED_ATTR}], [${HELD_ATTR}]`;

/**
 * Elements that must never be live inside the editing area. Whatever reaches it
 * some other way than setContent()/insertHtml() is caught by ContentArea's observer.
 */
export const LIVE_SELECTOR = 'iframe, script, style, link, meta, base, object, embed, form';

const HTML_NS = 'http://www.w3.org/1999/xhtml';

const PLACEHOLDER_TAGS = new Set([
  'script', 'style', 'link', 'meta', 'base', 'noscript', 'iframe', 'object', 'embed', 'applet',
  'form', 'input', 'button', 'select', 'textarea', 'frame', 'frameset', 'portal', 'fencedframe'
]);

const URL_ATTRIBUTES = new Set(['href', 'src', 'action', 'formaction', 'data', 'poster', 'background', 'xlink:href']);

// id → markup of a held element, and the reverse so the same markup is held once
const markups = new Map();
const markupIds = new Map();
// id → {order: [name], held: [[name, value]]} of held attributes, and the reverse
const attributeSets = new Map();
const attributeIds = new Map();

let inertDocument = null;

function newId() {
  const bytes = new Uint8Array(12);
  (window.crypto || window.msCrypto).getRandomValues(bytes);
  return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
}

function registerMarkup(markup) {
  let id = markupIds.get(markup);
  if (!id) {
    id = newId();
    markupIds.set(markup, id);
    markups.set(id, markup);
  }
  return id;
}

function registerAttributes(record) {
  const key = JSON.stringify(record);
  let id = attributeIds.get(key);
  if (!id) {
    id = newId();
    attributeIds.set(key, id);
    attributeSets.set(id, record);
  }
  return id;
}

/**
 * Parse HTML where nothing loads and nothing runs.
 * The document has no browsing context: images are not fetched, handlers do not
 * fire, and <noscript> is parsed as markup rather than as text.
 * @param {string} html
 * @returns {HTMLTemplateElement} read the nodes from .content, the HTML from .innerHTML
 */
export function parseInert(html) {
  if (!inertDocument) inertDocument = document.implementation.createHTMLDocument('');
  const template = inertDocument.createElement('template');
  template.innerHTML = html ?? '';
  return template;
}

/**
 * A copy of live nodes in the inert document, to restore and serialize
 * @param {Node} node
 * @returns {Node}
 */
export function inertCopy(node) {
  if (!inertDocument) inertDocument = document.implementation.createHTMLDocument('');
  return inertDocument.importNode(node, true);
}

/**
 * Does this URL run script when it is followed or loaded?
 * Spaces and control characters are ignored the way a browser ignores them.
 * @param {string} value
 * @param {boolean} image - a data:image/... URL is fine here (src, poster)
 * @returns {boolean}
 */
export function isScriptUrl(value, image = false) {
  const probe = String(value ?? '').replace(/[\u0000- \u007f]+/g, '');
  const match = /^([a-z][a-z0-9+.-]*):/i.exec(probe);
  if (!match) return false;
  const scheme = match[1].toLowerCase();
  if (scheme === 'data') return !(image && /^data:image\//i.test(probe));
  return scheme === 'javascript' || scheme === 'vbscript';
}

function isHeldAttribute(name, value) {
  const lower = name.toLowerCase();
  // Event handler attributes are plain names; "on:click" or "on-x" is not one
  if (/^on[a-z]+$/.test(lower)) return true;
  if (lower === 'autofocus' || lower === 'srcdoc') return true;
  return URL_ATTRIBUTES.has(lower) && isScriptUrl(value, lower === 'src' || lower === 'poster');
}

/**
 * Inline svg/math is shown as it is unless something in it can run
 * @param {Element} element
 * @returns {boolean}
 */
function isActiveForeign(element) {
  // (a <style> inside svg styles the whole page, like any other)
  if (element.querySelector('script, style, foreignObject, animate, set, animateTransform, animateMotion, handler')) return true;
  const nodes = [element, ...element.querySelectorAll('*')];
  return nodes.some(node => node.getAttributeNames().some(name => isHeldAttribute(name, node.getAttribute(name))));
}

const escapeXml = (text) => text.replace(/[<>&"']/g, c => `&#${c.charCodeAt(0)};`);

/**
 * Placeholder picture of an iframe: a frame icon with the embed's host name, drawn
 * at the centre of whatever size the iframe had (the SVG has no intrinsic size)
 * @param {string} label
 * @returns {string} data: URI
 */
function framePicture(label) {
  const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="100%" height="100%">'
    + '<svg x="50%" y="50%" overflow="visible"><g transform="translate(-20 -34)" fill="none"'
    + ' stroke="#94a3b8" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">'
    + '<rect x="1" y="1" width="38" height="30" rx="4"/><path d="M1 9h38M16 15l-5 5 5 5M24 15l5 5-5 5"/></g></svg>'
    + '<text x="50%" y="50%" dy="22" text-anchor="middle" font-family="system-ui,sans-serif"'
    + ` font-size="13" fill="#64748b">${escapeXml(label)}</text></svg>`;
  return `data:image/svg+xml,${encodeURIComponent(svg)}`;
}

/**
 * Placeholder picture of anything else: a small labelled chip
 * @param {string} label
 * @returns {{src: string, width: number, height: number}}
 */
function chipPicture(label) {
  const width = Math.round(18 + [...label].length * 7.3);
  const height = 22;
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}">`
    + `<rect x=".5" y=".5" width="${width - 1}" height="${height - 1}" rx="4" fill="#eef2ff" stroke="#94a3b8"/>`
    + `<text x="9" y="15" font-family="ui-monospace,Menlo,Consolas,monospace" font-size="12" fill="#475569">${escapeXml(label)}</text></svg>`;
  return {src: `data:image/svg+xml,${encodeURIComponent(svg)}`, width, height};
}

function hostOf(url) {
  try {
    return url ? new URL(url, window.location.href).hostname : '';
  } catch (e) {
    return '';
  }
}

function shorten(text, length = 24) {
  const clean = String(text ?? '').replace(/\s+/g, ' ').trim();
  return [...clean].length > length ? [...clean].slice(0, length - 1).join('') + '…' : clean;
}

function chipLabel(element) {
  const tag = element.localName;
  let detail = '';
  if (tag === 'script') detail = hostOf(element.getAttribute('src'));
  else if (tag === 'link') detail = element.getAttribute('rel') || '';
  else if (tag === 'button') detail = element.textContent;
  else if (tag === 'input') detail = element.getAttribute('name') || element.getAttribute('type') || '';
  else if (tag === 'select' || tag === 'textarea') detail = element.getAttribute('name') || '';
  else if (tag === 'meta') detail = element.getAttribute('name') || element.getAttribute('http-equiv') || element.getAttribute('itemprop') || '';
  detail = shorten(detail);
  return detail ? `${tag} · ${detail}` : tag;
}

/**
 * The placeholder that stands for an element while editing
 * @param {Element} element
 * @returns {HTMLImageElement} in the element's own document
 */
export function createPlaceholder(element) {
  const tag = element.localName;
  const markup = element.outerHTML;
  const img = element.ownerDocument.createElement('img');
  img.setAttribute('alt', '');

  if (tag === 'iframe') {
    const src = element.getAttribute('src') || '';
    const host = hostOf(src);
    img.setAttribute('src', framePicture(host ? `iframe · ${host}` : 'iframe'));
    if (src) img.setAttribute('title', src);
    // Same box as the iframe, so the layout does not jump
    ['width', 'height', 'style'].forEach(name => {
      if (element.hasAttribute(name)) img.setAttribute(name, element.getAttribute(name));
    });
  } else {
    const chip = chipPicture(chipLabel(element));
    img.setAttribute('src', chip.src);
    img.setAttribute('width', chip.width);
    img.setAttribute('height', chip.height);
    img.setAttribute('title', shorten(markup, 300));
  }

  img.setAttribute(EMBED_ATTR, registerMarkup(markup));
  img.setAttribute(KIND_ATTR, tag);
  return img;
}

/**
 * Take the attributes that would run off an element, leaving a marker
 * @param {Element} element
 */
function holdAttributes(element) {
  let known = null;
  if (element.hasAttribute(HELD_ATTR)) {
    known = attributeSets.get(element.getAttribute(HELD_ATTR)) || null;
    // An id this page never handed out: not ours, and nothing to put back
    element.removeAttribute(HELD_ATTR);
  }
  element.removeAttribute(KIND_ATTR);
  element.removeAttribute(EMBED_ATTR);

  const names = element.getAttributeNames();
  const held = names.filter(name => isHeldAttribute(name, element.getAttribute(name)));
  if (held.length === 0 && !known) return;

  const record = {
    order: known ? [...known.order, ...names.filter(name => !known.order.includes(name))] : names,
    held: [
      ...(known ? known.held.filter(([name]) => !held.includes(name)) : []),
      ...held.map(name => [name, element.getAttribute(name)])
    ]
  };
  held.forEach(name => element.removeAttribute(name));
  element.setAttribute(HELD_ATTR, registerAttributes(record));
}

function holdChildren(parent) {
  Array.from(parent.children).forEach(element => {
    const tag = element.localName;

    if (tag === 'img' && element.hasAttribute(EMBED_ATTR)) {
      // Already held. With an id that is not ours it is an ordinary image
      if (markups.has(element.getAttribute(EMBED_ATTR))) return;
      element.removeAttribute(EMBED_ATTR);
      element.removeAttribute(KIND_ATTR);
    }

    const foreign = element.namespaceURI !== HTML_NS;
    if ((!foreign && PLACEHOLDER_TAGS.has(tag)) || (foreign && isActiveForeign(element))) {
      element.replaceWith(createPlaceholder(element));
      return;
    }

    holdAttributes(element);
    // A <template> keeps its content in an inert fragment of its own: left as it is
    holdChildren(element);
  });
}

/**
 * Hold everything under root that would run, load or submit.
 * Safe to run again on content that is already held.
 * @param {ParentNode} root - preferably not in the page yet
 */
export function hold(root) {
  holdChildren(root);
}

/**
 * Swap elements that are already in the page for placeholders (safety net)
 * @param {Iterable<Element>} elements
 */
export function holdElements(elements) {
  Array.from(elements).forEach(element => {
    if (element.isConnected) element.replaceWith(createPlaceholder(element));
  });
}

/**
 * HTML ready for the editing area: parsed inertly, held, and brought into the page
 * @param {string} html
 * @returns {DocumentFragment}
 */
export function toEditable(html) {
  const template = parseInert(html);
  hold(template.content);
  return document.importNode(template.content, true);
}

/**
 * The markup a placeholder stands for
 * @param {Element} placeholder
 * @returns {string|null} null when the id is not one of ours
 */
export function heldMarkup(placeholder) {
  const markup = markups.get(placeholder?.getAttribute?.(EMBED_ATTR));
  return markup === undefined ? null : markup;
}

/**
 * The iframe a placeholder stands for, parsed inertly (nothing loads)
 * @param {HTMLImageElement} placeholder
 * @returns {HTMLIFrameElement|null} null for a placeholder of anything else
 */
export function readEmbed(placeholder) {
  const markup = heldMarkup(placeholder);
  if (markup === null) return null;
  const element = parseInert(markup).content.firstElementChild;
  return element && element.localName === 'iframe' ? element : null;
}

function restoreAttributes(element) {
  const record = attributeSets.get(element.getAttribute(HELD_ATTR));
  element.removeAttribute(HELD_ATTR);
  if (!record) return;

  const held = new Map(record.held);
  const current = Array.from(element.attributes);
  const byName = new Map(current.map(attribute => [attribute.name, attribute]));
  const rebuilt = [];
  const placed = new Set();

  record.order.forEach(name => {
    if (byName.has(name)) {
      // Still on the element (possibly edited since): that value stays
      rebuilt.push(byName.get(name));
    } else if (held.has(name)) {
      rebuilt.push([name, held.get(name)]);
    } else {
      return;
    }
    placed.add(name);
  });
  current.forEach(attribute => {
    if (!placed.has(attribute.name)) rebuilt.push(attribute);
  });

  // Attributes go back in their original order. Existing ones are moved as nodes:
  // the parser accepts names that setAttribute() refuses
  current.forEach(attribute => element.removeAttributeNode(attribute));
  rebuilt.forEach(item => {
    if (Array.isArray(item)) {
      element.setAttribute(item[0], item[1]);
    } else {
      element.setAttributeNode(item);
    }
  });
}

/**
 * Put back everything that was held, exactly as it was.
 * Run it on an inert copy (see inertCopy), never on the page itself.
 * @param {ParentNode} root
 */
export function restore(root) {
  // Attributes first: what a placeholder brings back is already whole
  root.querySelectorAll(`[${HELD_ATTR}]`).forEach(restoreAttributes);
  root.querySelectorAll(EMBED_SELECTOR).forEach(placeholder => {
    const markup = heldMarkup(placeholder);
    if (markup === null) {
      placeholder.remove();
      return;
    }
    placeholder.replaceWith(parseInert(markup).content);
  });
}

/**
 * Remove every editor marker from HTML that came from outside (paste, AI, a
 * dialog). Nothing outside this page can refer to a held piece, so a marker in
 * such HTML is either forged or stale. A placeholder written the old way, with
 * the iframe markup in the attribute, keeps its iframe — and only that; the
 * caller's sanitizer then cleans it like any pasted iframe.
 * @param {ParentNode} root - in an inert document
 */
export function stripMarkers(root) {
  root.querySelectorAll(EMBED_SELECTOR).forEach(placeholder => {
    const value = placeholder.getAttribute(EMBED_ATTR) || '';
    const iframe = value.includes('<') ? parseInert(value).content.querySelector('iframe') : null;
    if (iframe) {
      placeholder.replaceWith(iframe);
    } else {
      placeholder.remove();
    }
  });
  root.querySelectorAll(`[${HELD_ATTR}], [${KIND_ATTR}]`).forEach(element => {
    element.removeAttribute(HELD_ATTR);
    element.removeAttribute(KIND_ATTR);
  });
}

/**
 * What is held under root, by kind: {script: 2, style: 1, onclick: 3 …}
 * (held attributes are counted under "handlers")
 * @param {ParentNode} root
 * @returns {Object<string, number>}
 */
export function countHeld(root) {
  const counts = {};
  root.querySelectorAll(EMBED_SELECTOR).forEach(placeholder => {
    const kind = placeholder.getAttribute(KIND_ATTR) || 'element';
    counts[kind] = (counts[kind] || 0) + 1;
  });
  const handlers = root.querySelectorAll(`[${HELD_ATTR}]`).length;
  if (handlers) counts.handlers = handlers;
  return counts;
}

/**
 * The CSS of every held <style> under root, in document order
 * @param {ParentNode} root
 * @returns {string[]}
 */
export function heldStyles(root) {
  return Array.from(root.querySelectorAll(`img[${KIND_ATTR}="style"]`))
    .map(placeholder => {
      const markup = heldMarkup(placeholder);
      return markup === null ? '' : (parseInert(markup).content.firstElementChild?.textContent || '');
    })
    .filter(Boolean);
}

// --- Copy and paste inside the page ------------------------------------------
//
// Copy puts the real markup on the clipboard (not placeholders), so it pastes
// properly anywhere. Pasting it back here must not treat it as outside HTML —
// that would strip the scripts and handlers the author is only moving. What was
// copied is remembered here, and a paste that carries the same thing uses the
// remembered copy, never the clipboard's.

const COPY_TYPE = 'application/x-rte-copy';
let lastCopy = null;

/**
 * Remember what a copy/cut from an editor put on the clipboard
 * @param {DataTransfer} clipboardData
 * @param {string} html - restored markup
 */
export function rememberCopy(clipboardData, html) {
  lastCopy = {html, token: newId()};
  try {
    clipboardData.setData(COPY_TYPE, lastCopy.token);
  } catch (e) {
    // No custom types: recallCopy() compares the HTML instead
  }
}

/**
 * Is this paste the thing an editor on this page copied last?
 * @param {DataTransfer} clipboardData
 * @returns {string|null} the remembered markup, or null for anything else
 */
export function recallCopy(clipboardData) {
  if (!lastCopy || !clipboardData) return null;

  let token = '';
  try {
    token = clipboardData.getData(COPY_TYPE);
  } catch (e) {
    token = '';
  }
  if (token && token === lastCopy.token) return lastCopy.html;

  // Browsers hand the HTML back inside a wrapper (<meta charset>, <html><body>,
  // <!--StartFragment-->); anything else around it and it is not our copy
  const html = clipboardData.getData('text/html') || '';
  const at = html.indexOf(lastCopy.html);
  if (at === -1) return null;
  const around = html.slice(0, at) + html.slice(at + lastCopy.html.length);
  return /^(?:\s|<meta[^>]*>|<\/?(?:html|head|body)[^>]*>|<!--(?:Start|End)Fragment-->)*$/i.test(around)
    ? lastCopy.html
    : null;
}
