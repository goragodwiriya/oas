const DEFAULT_CLASS_PREFIXES = ['rte-'];

const DEFAULT_TABLE_ATTRIBUTE_ALLOW_MAP = {
    table: new Set(),
    thead: new Set(),
    tbody: new Set(),
    tfoot: new Set(),
    tr: new Set(),
    caption: new Set(),
    th: new Set(['colspan', 'rowspan', 'scope']),
    td: new Set(['colspan', 'rowspan'])
};

const DEFAULT_PASTE_ATTRIBUTE_ALLOW_MAP = {
    iframe: new Set(['src', 'width', 'height', 'allowfullscreen', 'frameborder', 'allow']),
    td: new Set(['colspan', 'rowspan']),
    th: new Set(['colspan', 'rowspan']),
    col: new Set(['span']),
    colgroup: new Set(['span']),
    ol: new Set(['start', 'type'])
};

function normalizeTagSet(tags) {
    if (!tags) {
        return null;
    }

    const values = tags instanceof Set ? Array.from(tags) : (Array.isArray(tags) ? tags : [tags]);
    return new Set(values.map(tag => String(tag || '').trim().toLowerCase()).filter(Boolean));
}

function normalizeClassList(classes) {
    const values = Array.isArray(classes)
        ? classes
        : String(classes || '').split(',');

    return Array.from(new Set(
        values
            .map(item => String(item || '').trim().toLowerCase())
            .filter(Boolean)
    ));
}

function normalizeClassPrefixes(prefixes) {
    const values = Array.isArray(prefixes) ? prefixes : [prefixes];
    return values
        .map(prefix => String(prefix || '').trim().toLowerCase())
        .filter(Boolean);
}

function normalizeAttributeAllowMap(map) {
    const normalized = {};
    Object.entries(map || {}).forEach(([tag, attributes]) => {
        normalized[String(tag).toLowerCase()] = new Set(
            Array.from(attributes || []).map(attribute => String(attribute || '').toLowerCase())
        );
    });

    return normalized;
}

function unwrapElement(element) {
    const parent = element.parentNode;
    if (!parent) {
        return;
    }
    while (element.firstChild) {
        parent.insertBefore(element.firstChild, element);
    }
    parent.removeChild(element);
}

function removeComments(root) {
    const iterator = document.createNodeIterator(root, NodeFilter.SHOW_COMMENT);
    const comments = [];
    let current;
    while (current = iterator.nextNode()) {
        comments.push(current);
    }
    comments.forEach(comment => comment.parentNode?.removeChild(comment));
}

function cleanMsoFormatting(root) {
    root.querySelectorAll('[class^="Mso"], [style*="mso-"]').forEach(element => {
        if (element.hasAttribute('style')) {
            const cleanedStyle = element.getAttribute('style')
                .split(';')
                .filter(style => !style.trim().startsWith('mso-'))
                .join(';');

            if (cleanedStyle.trim()) {
                element.setAttribute('style', cleanedStyle);
            } else {
                element.removeAttribute('style');
            }
        }

        if (element.hasAttribute('class')) {
            const className = element.className
                .split(' ')
                .filter(className => !className.startsWith('Mso'))
                .join(' ');

            if (className) {
                element.className = className;
            } else {
                element.removeAttribute('class');
            }
        }
    });
}

function stripAttributes(element, settings) {
    const tag = element.tagName.toLowerCase();
    const keep = settings.attributeAllowMap[tag] || null;

    if (settings.stripAllAttributes) {
        Array.from(element.attributes).forEach(attribute => {
            if (!keep || !keep.has(attribute.name.toLowerCase())) {
                element.removeAttribute(attribute.name);
            }
        });
        return;
    }

    if (settings.removeIds) {
        element.removeAttribute('id');
    }

    if (settings.removeStyles) {
        element.removeAttribute('style');
    }

    if (settings.cleanClasses) {
        const keptClasses = Array.from(element.classList).filter(className => {
            const normalized = className.toLowerCase();
            return settings.classPrefixes.some(prefix => normalized.startsWith(prefix)) || settings.allowedClasses.has(normalized);
        });

        if (keptClasses.length > 0) {
            element.className = keptClasses.join(' ');
        } else {
            element.removeAttribute('class');
        }
    }

    if (keep) {
        Array.from(element.attributes).forEach(attribute => {
            if (!keep.has(attribute.name.toLowerCase())) {
                element.removeAttribute(attribute.name);
            }
        });
    }
}

function walkAndClean(root, settings) {
    Array.from(root.childNodes).forEach(child => {
        if (child.nodeType !== Node.ELEMENT_NODE) {
            return;
        }

        const tag = child.tagName.toLowerCase();
        if (settings.allowedTags && !settings.allowedTags.has(tag)) {
            walkAndClean(child, settings);
            unwrapElement(child);
            return;
        }

        stripAttributes(child, settings);
        walkAndClean(child, settings);
    });
}

function unwrapPlainSpans(root) {
    root.querySelectorAll('span').forEach(span => {
        if (!span.hasAttribute('style') && !span.hasAttribute('class')) {
            unwrapElement(span);
        }
    });
}

function normalizeTable(table) {
    const directRows = Array.from(table.children).filter(child => child.tagName === 'TR');
    if (directRows.length === 0) {
        return;
    }

    let tbody = Array.from(table.children).find(child => child.tagName === 'TBODY');
    if (!tbody) {
        tbody = document.createElement('tbody');
        const firstSection = Array.from(table.children).find(child => ['THEAD', 'TFOOT'].includes(child.tagName));
        if (firstSection?.nextSibling) {
            table.insertBefore(tbody, firstSection.nextSibling);
        } else {
            table.appendChild(tbody);
        }
    }

    directRows.forEach(row => tbody.appendChild(row));
}

export function cleanupHtmlFragment(html, options = {}) {
    if (!html) {
        return '';
    }

    const settings = {
        removeSelectors: [],
        removeComments: true,
        unwrapSelectors: [],
        removeEmptySelectors: [],
        stripWordFormatting: false,
        unwrapPlainSpans: false,
        allowedTags: null,
        stripAllAttributes: false,
        attributeAllowMap: {},
        removeIds: false,
        removeStyles: false,
        cleanClasses: false,
        allowedClasses: new Set(),
        classPrefixes: DEFAULT_CLASS_PREFIXES,
        normalizeTables: false,
        collapseBreaks: false,
        ...options
    };

    settings.allowedTags = normalizeTagSet(settings.allowedTags);
    settings.allowedClasses = new Set(normalizeClassList(settings.allowedClasses));
    settings.classPrefixes = normalizeClassPrefixes(settings.classPrefixes);
    settings.attributeAllowMap = normalizeAttributeAllowMap(settings.attributeAllowMap);

    const temp = document.createElement('div');
    temp.innerHTML = html;

    if (settings.stripWordFormatting) {
        cleanMsoFormatting(temp);
    }

    if (settings.removeSelectors.length > 0) {
        temp.querySelectorAll(settings.removeSelectors.join(', ')).forEach(element => element.remove());
    }

    if (settings.removeComments) {
        removeComments(temp);
    }

    if (settings.removeEmptySelectors.length > 0) {
        temp.querySelectorAll(settings.removeEmptySelectors.join(', ')).forEach(element => element.remove());
    }

    if (settings.unwrapSelectors.length > 0) {
        temp.querySelectorAll(settings.unwrapSelectors.join(', ')).forEach(element => unwrapElement(element));
    }

    walkAndClean(temp, settings);

    if (settings.unwrapPlainSpans) {
        unwrapPlainSpans(temp);
    }

    if (settings.normalizeTables) {
        temp.querySelectorAll('table').forEach(table => normalizeTable(table));
    }

    let result = temp.innerHTML.trim();
    if (settings.collapseBreaks) {
        result = result.replace(/(<br\s*\/?>[\s]*){3,}/gi, '<br><br>');
    }

    return result;
}

export {DEFAULT_CLASS_PREFIXES, DEFAULT_TABLE_ATTRIBUTE_ALLOW_MAP, DEFAULT_PASTE_ATTRIBUTE_ALLOW_MAP};