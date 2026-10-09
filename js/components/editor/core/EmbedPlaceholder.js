/**
 * EmbedPlaceholder - Shows <iframe> embeds as placeholder images while editing
 *
 * A live iframe inside contenteditable cannot be clicked, selected or edited, and
 * whatever it loads — a blocked page's error text included — shows in its place.
 * Like CKEditor's fake objects, each iframe is swapped for an <img> that carries the
 * original markup: an <img> is atomic, so it can be clicked, deleted and
 * double-clicked to edit, and ContentArea.getContent() puts the real iframe back.
 *
 * @author Goragod Wiriya
 * @version 1.0
 */

export const EMBED_ATTR = 'data-rte-embed';
export const EMBED_SELECTOR = `img[${EMBED_ATTR}]`;

const escapeXml = (text) => text.replace(/[<>&"']/g, c => `&#${c.charCodeAt(0)};`);

/**
 * Placeholder picture: a frame icon with the embed's host name, drawn at the centre
 * of whatever size the iframe had (the SVG has no intrinsic size of its own)
 * @param {string} label
 * @returns {string} data: URI
 */
function placeholderSrc(label) {
  const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="100%" height="100%">'
    + '<svg x="50%" y="50%" overflow="visible"><g transform="translate(-20 -34)" fill="none"'
    + ' stroke="#94a3b8" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">'
    + '<rect x="1" y="1" width="38" height="30" rx="4"/><path d="M1 9h38M16 15l-5 5 5 5M24 15l5 5-5 5"/></g></svg>'
    + '<text x="50%" y="50%" dy="22" text-anchor="middle" font-family="system-ui,sans-serif"'
    + ` font-size="13" fill="#64748b">${escapeXml(label)}</text></svg>`;
  return `data:image/svg+xml,${encodeURIComponent(svg)}`;
}

/**
 * @param {HTMLIFrameElement} iframe
 * @returns {HTMLImageElement} placeholder, in the iframe's own document
 */
export function createPlaceholder(iframe) {
  const src = iframe.getAttribute('src') || '';
  let host = '';
  try {
    host = src ? new URL(src, window.location.href).hostname : '';
  } catch (e) {
    // Unparseable src — label it as a plain iframe
  }

  const img = iframe.ownerDocument.createElement('img');
  img.setAttribute('src', placeholderSrc(host ? `iframe · ${host}` : 'iframe'));
  img.setAttribute('alt', '');
  if (src) img.setAttribute('title', src);
  // Same box as the iframe, so the layout does not jump
  ['width', 'height', 'style'].forEach(name => {
    if (iframe.hasAttribute(name)) img.setAttribute(name, iframe.getAttribute(name));
  });
  img.setAttribute(EMBED_ATTR, iframe.outerHTML);
  return img;
}

/**
 * Replace every <iframe> inside root with a placeholder.
 * Pass a root that is not in the page yet, so the iframes never start loading.
 * @param {ParentNode} root
 */
export function toPlaceholders(root) {
  root.querySelectorAll('iframe').forEach(iframe => iframe.replaceWith(createPlaceholder(iframe)));
}

/**
 * The iframe a placeholder stands for, parsed in an inert document (nothing loads)
 * @param {HTMLImageElement} placeholder
 * @returns {HTMLIFrameElement|null}
 */
export function readEmbed(placeholder) {
  const template = placeholder.ownerDocument.createElement('template');
  template.innerHTML = placeholder.getAttribute(EMBED_ATTR) || '';
  return template.content.querySelector('iframe');
}

/**
 * Put the real iframe back in place of every placeholder inside root.
 * Only an iframe is restored, and it goes through sanitize, so a placeholder
 * pasted in from outside cannot smuggle other markup past the sanitizer.
 * @param {ParentNode} root
 * @param {function(string): string} sanitize
 */
export function fromPlaceholders(root, sanitize) {
  root.querySelectorAll(EMBED_SELECTOR).forEach(placeholder => {
    const iframe = readEmbed(placeholder);
    if (!iframe) {
      placeholder.remove();
      return;
    }
    const template = placeholder.ownerDocument.createElement('template');
    template.innerHTML = sanitize(iframe.outerHTML);
    placeholder.replaceWith(template.content);
  });
}
