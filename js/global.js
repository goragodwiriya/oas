function initForbiddenPage(element, data) {
    const messageElement = element.querySelector('[data-forbidden-message]');
    if (!messageElement) {
        return;
    }

    const message = data?.query?.message;

    if (typeof message === 'string' && message.trim() !== '') {
        // เหตุผลมาจาก API เป็นคีย์ภาษาอังกฤษ (เช่น Permission required) — แปลก่อนแสดง
        messageElement.textContent = window.Now && Now.translate ? Now.translate(message.trim()) : message.trim();
        messageElement.hidden = false;
        return;
    }

    messageElement.textContent = '';
    messageElement.hidden = true;
}

function initProfile(element, data) {
    const input = element.querySelector('#birthday');
    const display = element.querySelector('.dropdown-display');

    const updateAge = () => {
        if (input.value) {
            const birth = new Date(input.value);
            const age = Math.floor((Date.now() - birth) / 31557600000);

            // Format date with standard pattern (YYYY uses locale-based year: BE for Thai, CE for others)
            const formattedDate = Utils.date.format(input.value, 'D MMMM YYYY');

            display.textContent = `${formattedDate} (${age} ${Now.translate('years')})`;
        } else {
            display.textContent = '';
        }
    };

    input.addEventListener('change', updateAge);
    updateAge();

    // Return cleanup function (optional)
    return () => {
        input.removeEventListener('change', updateAge);
    };
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
    if (rawValue === 'active' || parseInt(rawValue) > 0) {
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

function formatOffline(cell, rawValue, rowData, attributes) {
    if (parseInt(rawValue) === 0) {
        cell.innerHTML = '<span class="icon-host color-green" title="' + Now.translate('Online') + '"></span>';
    } else {
        cell.innerHTML = '<span class="icon-host color-silver" title="' + Now.translate('Offline') + '"></span>';
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
        makeLink('mailto:' + value, value, 'icon-email');
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
    cell.innerHTML = '';
    if (!rawValue) return;

    // Build the thumbnail via DOM + style API instead of string concatenation.
    // Reject dangerous schemes and any character that could break out of the
    // CSS url() context (quotes, parens, angle brackets, whitespace, backslash).
    const url = String(rawValue).trim();
    const isDangerousScheme = /^(?:javascript|data|vbscript|file|about):/i.test(url);
    const hasUnsafeChars = /["'()\\\s<>]/.test(url);
    if (isDangerousScheme || hasUnsafeChars) return;

    const thumb = document.createElement('div');
    thumb.className = 'thumbnail';
    thumb.style.backgroundImage = `url("${url}")`;
    cell.appendChild(thumb);
}

function formatFileSize(cell, rawValue, rowData, attributes) {
    if (rawValue) {
        cell.textContent = Utils.number.fileSize(rawValue);
    } else {
        cell.textContent = '';
    }
}

/**
 * Attach a "copy to language" handler to the #copy_menu button.
 * @param {HTMLElement} element - Form root element
 * @param {string} endpoint - API endpoint to POST to
 * @param {string} saveFirstMsg - Translation key shown when id is missing
 * @returns {Function} Cleanup function that removes the listener
 */
function makeCopyButton(element, endpoint, saveFirstMsg) {
    const copyBtn = element.querySelector('#copy_menu');
    const languageSelect = element.querySelector('#language');
    if (!copyBtn || !languageSelect) return () => {};

    const handler = async () => {
        const lang = languageSelect.value;
        if (!lang) {
            NotificationManager.warning(Now.translate('Please select a language to copy to'));
            return;
        }
        const id = element.querySelector('[name="id"]')?.value;
        if (!id) {
            NotificationManager.warning(Now.translate(saveFirstMsg));
            return;
        }
        try {
            const response = await ApiService.post(endpoint, {id, language: lang});
            if (response.success) {
                NotificationManager.success(response.data.message || Now.translate('Copied successfully'));
            } else {
                NotificationManager.error(response.data.message || Now.translate('Copy failed'));
            }
        } catch {
            NotificationManager.error(Now.translate('Copy failed'));
        }
    };

    copyBtn.addEventListener('click', handler);
    return () => copyBtn.removeEventListener('click', handler);
}

/**
 * โลโก้ที่มีคลาส logo-tint และได้รูปจาก data-style="backgroundImage:url(...)"
 * รูปสีเดียวบนพื้นโปร่งใส (svg, png, webp, gif) แสดงเป็น mask ระบายด้วยสีของลิงก์ (is-tinted)
 * รูปพื้นทึบ (jpg) รูปหลายสี หรือรูปที่อ่านพิกเซลไม่ได้ (ต่างโดเมนและไม่มี CORS) แสดงตามปกติ
 */
(() => {
    const results = new Map();

    const isTintable = url => {
        if (!results.has(url)) {
            results.set(url, new Promise(resolve => {
                const img = new Image();
                img.onload = () => {
                    try {
                        const size = 128;
                        const canvas = document.createElement('canvas');
                        canvas.width = canvas.height = size;
                        const ctx = canvas.getContext('2d', {willReadFrequently: true});
                        ctx.drawImage(img, 0, 0, size, size);
                        const px = ctx.getImageData(0, 0, size, size).data;
                        let transparent = 0;
                        const solid = [];
                        for (let i = 0; i < px.length; i += 4) {
                            if (px[i + 3] < 16) {
                                transparent++;
                            } else if (px[i + 3] > 191) {
                                solid.push(i);
                            }
                        }
                        // พื้นโปร่งใสอย่างน้อย 10% ของรูป
                        if (solid.length === 0 || transparent < size * size * 0.1) {
                            resolve(false);
                            return;
                        }
                        // สีเดียว: พิกเซลทึบที่ต่างจากสีเฉลี่ยเกิน 64 (ช่องใดช่องหนึ่ง) มีไม่เกิน 5%
                        const avg = [0, 1, 2].map(c => solid.reduce((sum, i) => sum + px[i + c], 0) / solid.length);
                        const off = solid.filter(i => [0, 1, 2].some(c => Math.abs(px[i + c] - avg[c]) > 64)).length;
                        resolve(off <= solid.length * 0.05);
                    } catch {
                        // canvas ถูก taint จากรูปต่างโดเมน
                        resolve(false);
                    }
                };
                img.onerror = () => resolve(false);
                try {
                    if (new URL(url, location.href).origin !== location.origin) {
                        img.crossOrigin = 'anonymous';
                    }
                    img.src = url;
                } catch {
                    resolve(false);
                }
            }));
        }
        return results.get(url);
    };

    const tint = async el => {
        const match = /url\(["']?(.*?)["']?\)/.exec(el.style.backgroundImage);
        const url = match ? match[1] : '';
        if (el._tintSrc === url) {
            // รูปเดิม แต่มีอะไรเขียน style ใหม่ทั้งก้อนจน --tint-image หาย — is-tinted ยังอยู่
            // จะเหลือแต่กล่องสีของลิงก์ (mask ว่าง) จึงใส่คืนให้
            if (el.classList.contains('is-tinted') && !el.style.getPropertyValue('--tint-image')) {
                el.style.setProperty('--tint-image', el.style.backgroundImage);
            }
            return;
        }
        el._tintSrc = url;
        el.classList.remove('is-tinted');
        el.style.removeProperty('--tint-image');
        if (url === '') {
            el.classList.remove('is-checking');
            return;
        }
        el.classList.add('is-checking');
        const ok = await isTintable(url);
        if (el._tintSrc !== url) {
            return;
        }
        if (ok) {
            el.style.setProperty('--tint-image', el.style.backgroundImage);
        }
        el.classList.toggle('is-tinted', ok);
        el.classList.remove('is-checking');
    };

    const watch = el => {
        if (!el._tintObserver) {
            el._tintObserver = new MutationObserver(() => tint(el));
            el._tintObserver.observe(el, {attributes: true, attributeFilter: ['style']});
            tint(el);
        }
    };

    const scan = node => {
        if (node.nodeType === Node.ELEMENT_NODE) {
            if (node.matches('.logo-tint')) {
                watch(node);
            }
            node.querySelectorAll('.logo-tint').forEach(watch);
        }
    };

    new MutationObserver(records => records.forEach(record => record.addedNodes.forEach(scan)))
        .observe(document.documentElement, {childList: true, subtree: true});
    scan(document.documentElement);
})();

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
