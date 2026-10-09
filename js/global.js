
function initForbiddenPage(element, data) {
    const messageElement = element.querySelector('[data-forbidden-message]');
    if (!messageElement) {
        return;
    }

    const message = data?.query?.message;

    if (typeof message === 'string' && message.trim() !== '') {
        messageElement.textContent = message.trim();
        messageElement.hidden = false;
        return;
    }

    messageElement.textContent = '';
    messageElement.hidden = true;
}

/**
 * Format with options status
 */
function formatTableOptionStatus(cell, rawValue, rowData, attributes) {
    const opts = attributes.lookupOptions || attributes.tableDataOptions || attributes.tableFilterOptions;

    // Normalizer: build a map value->text
    const makeMap = (options) => {
        if (!options) return new Map();
        if (Array.isArray(options)) {
            // [{value,text}, ...]
            return new Map(options.map(o => [String(o.value), o.text]));
        }
        // object map {val: label, ...}
        return new Map(Object.entries(options).map(([k, v]) => [String(k), v]));
    };

    const map = makeMap(opts);

    const key = rawValue === null || rawValue === undefined ? '' : String(rawValue);
    const label = map.has(key) ? map.get(key) : (rawValue && rawValue.text) ? rawValue.text : key;
    const index = map.has(key) ? Array.from(map.keys()).indexOf(key) : -1;


    cell.innerHTML = `<span class="status${index}" data-i18n>${label}</span>`;
}

function formatStarStatus(cell, rawValue, rowData, attributes) {
    if (rawValue === 'active' || parseInt(rawValue) === 1) {
        cell.innerHTML = '<span class="icon-star2 color-primary"></span>';
    } else {
        cell.innerHTML = '<span class="icon-star0 color-silver"></span>';
    }
}

function formatActiveStatus(cell, rawValue, rowData, attributes) {
    if (rawValue === 'active' || parseInt(rawValue) === 1) {
        cell.innerHTML = '<span class="icon-valid color-red" title="' + Now.translate('Active') + '"></span>';
    } else {
        cell.innerHTML = '<span class="icon-invalid color-silver" title="' + Now.translate('Inactive') + '"></span>';
    }
}

function formatPublishStatus(cell, rawValue, rowData, attributes) {
    if (rawValue === 'active' || parseInt(rawValue) === 1) {
        cell.innerHTML = '<span class="icon-published1 color-green" title="' + Now.translate('Published') + '"></span>';
    } else {
        cell.innerHTML = '<span class="icon-published0 color-silver" title="' + Now.translate('Draft') + '"></span>';
    }
}

function formatLockStatus(cell, rawValue, rowData, attributes) {
    if (rawValue === 'active' || parseInt(rawValue) === 1) {
        cell.innerHTML = '<span class="icon-lock color-red" title="' + Now.translate('Locked') + '"></span>';
    } else {
        cell.innerHTML = '<span class="icon-unlock color-silver" title="' + Now.translate('Unlocked') + '"></span>';
    }
}

function formatVerifiedStatus(cell, rawValue, rowData, attributes) {
    if (rawValue === 'active' || parseInt(rawValue) === 1) {
        cell.innerHTML = '<span class="icon-verfied color-blue" title="' + Now.translate('Verified') + '"></span>';
    } else {
        cell.innerHTML = '<span class="icon-unverified color-silver" title="' + Now.translate('Unverified') + '"></span>';
    }
}

function formatCheckStatus(cell, rawValue, rowData, attributes) {
    if (rawValue === 'active' || parseInt(rawValue) === 1) {
        cell.innerHTML = '<span class="icon-valid color-green" title="' + Now.translate('Yes') + '"></span>';
    } else {
        cell.innerHTML = '<span class="icon-invalid color-silver" title="' + Now.translate('No') + '"></span>';
    }
}

function formatLink(cell, rawValue, rowData, attributes) {
    if (!rawValue) {
        cell.innerHTML = '-';
        return;
    }

    const value = String(rawValue).trim();

    // Simple recognizers
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    const phoneRegex = /^\+?[0-9()\s\-./]{6,}$/;
    const urlProtocolRegex = /^https?:\/\//i;

    const makeLink = (href, text, iconClass) => {
        const a = document.createElement('a');
        a.href = href;
        // Open http(s) links in new tab, others (mailto/tel) in same
        if (/^https?:\/\//i.test(href)) {
            a.target = '_blank';
            a.rel = 'noopener';
        }
        if (iconClass) a.className = iconClass;
        a.textContent = text;
        cell.innerHTML = '';
        cell.appendChild(a);
    };

    if (/^mailto:/i.test(value)) {
        makeLink(value, value.replace(/^mailto:/i, ''), 'icon-mail');
        return;
    }

    if (/^tel:/i.test(value)) {
        makeLink(value, value.replace(/^tel:/i, ''), 'icon-phone');
        return;
    }

    if (emailRegex.test(value)) {
        makeLink('mailto:' + value, value, 'icon-mail');
        return;
    }

    if (phoneRegex.test(value)) {
        // Normalize phone for href (keep leading + if present)
        const telHref = 'tel:' + value.replace(/[^\d+]/g, '');
        makeLink(telHref, value, 'icon-phone');
        return;
    }

    // Fallback: treat as URL
    let href = value;
    if (!urlProtocolRegex.test(href)) href = 'http://' + href;
    const displayUrl = href.replace(/^https?:\/\//, '').replace(/\/$/, '');
    makeLink(href, displayUrl, 'icon-world');
}

function formatImage(cell, rawValue, rowData, attributes) {
    if (rawValue) {
        cell.innerHTML = '<div class="thumbnail" style="background-image: url(' + rawValue + ')"></div>';
    } else {
        cell.innerHTML = '';
    }
}

function formatFileSize(cell, rawValue, rowData, attributes) {
    if (rawValue) {
        cell.textContent = Utils.number.fileSize(rawValue);
    } else {
        cell.textContent = '';
    }
}

function copyToClipboard(cell, rawValue, rowData, attributes) {
    if (rawValue) {
        const link = document.createElement('a');
        link.className = 'icon-copy';
        link.textContent = rawValue;
        link.style.cursor = 'pointer';
        link.addEventListener('click', () => Utils.dom.copyToClipboard(String(rawValue)));
        cell.innerHTML = '';
        cell.appendChild(link);
    } else {
        cell.textContent = '';
    }
}
