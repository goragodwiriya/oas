/**
 * modules/inventory/admin.js
 *
 * ลงทะเบียน route ของโมดูล inventory กลาง
 * ไฟล์นี้ถูกโหลดอัตโนมัติโดย index.php (scandir modules/) จึงไม่ต้องแก้ไฟล์กลาง
 *
 * เส้นทางตั้งชื่อให้ตรงกับ module= ของผลิตภัณฑ์เดิม เพื่อให้ลิงก์ที่ผู้ใช้คุ้นเคย
 * และที่จดไว้ยังเดาถูก
 */
EventManager.on('router:initialized', () => {
    // ---- สินค้า ----
    RouterManager.register('/inventory-products', {
        template: 'inventory/products.html',
        title: '{LNG_Inventory}',
        requireAuth: true
    });
    // สมุดบัญชีสต๊อกและชั้นต้นทุน — มุมของผู้ตรวจ ไม่ใช่มุมของคนทำงานประจำวัน
    RouterManager.register('/inventory-movements', {
        template: 'inventory/movements.html',
        title: '{LNG_Stock ledger}',
        requireAuth: true
    });
    RouterManager.register('/inventory-cost-layers', {
        template: 'inventory/cost-layers.html',
        title: '{LNG_Cost layers}',
        requireAuth: true
    });
    RouterManager.register('/inventory-setup', {
        template: 'inventory/inventories.html',
        title: '{LNG_List of} {LNG_Inventory}',
        requireAuth: true
    });
    RouterManager.register('/inventory-write', {
        template: 'inventory/product-edit.html',
        title: '{LNG_Inventory}',
        menuPath: '/inventory-setup',
        requireAuth: true
    });

    // ---- หน้าย่อยของสินค้าหนึ่งตัว ----
    // ทั้งสี่หน้าใช้ ?id=<สินค้า> ร่วมกัน และผูก menuPath ไว้ที่รายการสินค้า
    // เพื่อให้เมนูด้านข้างยังชี้ที่ "คลังสินค้า" ตอนเปิดหน้าย่อยเหล่านี้
    RouterManager.register('/inventory-overview', {
        template: 'inventory/product-overview.html',
        title: '{LNG_Product Overview}',
        menuPath: '/inventory-setup',
        requireAuth: true
    });
    RouterManager.register('/inventory-barcode', {
        template: 'inventory/product-items.html',
        title: '{LNG_Barcode}',
        menuPath: '/inventory-setup',
        requireAuth: true
    });
    RouterManager.register('/inventory-detail', {
        template: 'inventory/product-detail.html',
        title: '{LNG_Other details}',
        menuPath: '/inventory-setup',
        requireAuth: true
    });
    RouterManager.register('/inventory-stock', {
        template: 'inventory/product-stock.html',
        title: '{LNG_Inventory}',
        menuPath: '/inventory-setup',
        requireAuth: true
    });

    // ---- ลูกค้า/ผู้ขาย ----
    // ฟอร์มลูกค้าเป็นหน้าต่างซ้อนที่เปิดได้ทั้งจากตารางนี้และจากหน้าเอกสาร
    // ผ่าน api/inventory/customer/modal จึงไม่มี route ของฟอร์มแยก
    RouterManager.register('/inventory-customers', {
        template: 'inventory/customers.html',
        title: '{LNG_Customer list}',
        requireAuth: true
    });

    // ---- เอกสาร ----
    RouterManager.register('/inventory-orders', {
        template: 'inventory/orders.html',
        title: '{LNG_Document}',
        requireAuth: true
    });
    RouterManager.register('/inventory-order', {
        template: 'inventory/order-edit.html',
        title: '{LNG_Document}',
        menuPath: '/inventory-orders',
        requireAuth: true
    });

    // ---- ตั้งค่า ----
    RouterManager.register('/inventory-templates', {
        template: 'inventory/templates.html',
        title: '{LNG_List of} {LNG_Template}',
        requireAuth: true
    });
    RouterManager.register('/inventory-template', {
        template: 'inventory/template-edit.html',
        title: '{LNG_Template}',
        menuPath: '/inventory-templates',
        requireAuth: true
    });
    RouterManager.register('/inventory-categories', {
        template: 'inventory/categories.html',
        title: '{LNG_Category}',
        requireAuth: true
    });
    RouterManager.register('/inventory-settings', {
        template: 'inventory/settings.html',
        title: '{LNG_Accounting settings}',
        requireAuth: true
    });
    RouterManager.register('/inventory-import', {
        template: 'inventory/import.html',
        title: '{LNG_Import}',
        menuPath: '/inventory-setup',
        requireAuth: true
    });
});

/**
 * เตรียมหน้าเอกสารซื้อ/ขาย — เรียกโดยแกนผ่าน data-on-load ของฟอร์ม
 *
 * ทำสองอย่างที่ต้องผูกกับทั้งเอกสาร ไม่ใช่กับช่องใดช่องหนึ่ง
 *  1. ปุ่มลัด F2/F4/F7/F8/F9/F10 ชุดเดียวกับระบบเดิม
 *  2. กัน Enter ไม่ให้ส่งฟอร์ม — เครื่องอ่านบาร์โค้ดยิง Enter ต่อท้ายทุกครั้ง
 *     ถ้าไม่กัน สแกนสินค้าชิ้นแรกแล้วเอกสารจะถูกบันทึกทันที
 *
 * ค่าที่คืนกลับไปคือฟังก์ชันเก็บกวาด แกนจะเรียกให้ตอนออกจากหน้า
 * (TemplateManager.processDataOnLoad → cleanupScripts) จึงไม่มี listener ค้าง
 *
 * @param {HTMLElement} element ฟอร์มที่ประกาศ data-on-load
 *
 * @return {Function} ฟังก์ชันถอน event listener
 */
function initInventoryOrder(element) {
    const focus = (id) => {
        const el = document.getElementById(id);
        if (el) {
            el.focus();
            if (el.select) {
                el.select();
            }
        }
    };

    const onKeyDown = (e) => {
        // ออกจากหน้าไปแล้วแต่ listener ยังไม่ถูกถอน (กันไว้อีกชั้น)
        if (!element.isConnected) {
            return;
        }
        // หน้าต่างซ้อน (เพิ่มลูกค้า/สินค้า) มีฟอร์มของตัวเอง ปุ่มลัดของเอกสาร
        // ต้องไม่ไปแย่งโฟกัส และ Enter ในนั้นต้องส่งฟอร์มได้ตามปกติ
        if (e.target && e.target.closest && e.target.closest('.modal')) {
            return;
        }
        if (e.key === 'Enter') {
            const el = e.target;
            const tag = (el.tagName || '').toLowerCase();
            if (tag !== 'a' && tag !== 'button' && tag !== 'textarea' && el.type !== 'submit') {
                e.preventDefault();
            }
            return;
        }
        switch (e.key) {
            case 'F2':
                e.preventDefault();
                focus('customer_no');
                break;
            case 'F4':
                e.preventDefault();
                focus('product_code');
                break;
            case 'F7':
                e.preventDefault();
                element.querySelector('[data-modal="inventoryProduct"]')?.click();
                break;
            case 'F8':
                e.preventDefault();
                focus('discount_percent');
                break;
            case 'F9':
                e.preventDefault();
                focus('document_type');
                break;
            case 'F10':
                e.preventDefault();
                element.querySelector('button[type="submit"]')?.click();
                break;
        }
    };

    document.addEventListener('keydown', onKeyDown);

    // ชนิดเอกสารบางชนิดไม่ใช้วันครบกำหนด ซ่อนตั้งแต่เปิดหน้า
    // FormManager เรียก data-on-load หลัง setFormData เสมอ ค่าที่ต้องใช้จึงมาถึงแล้ว
    applyInventoryDueDate();

    return () => document.removeEventListener('keydown', onKeyDown);
}

/**
 * ซ่อน/แสดงวันครบกำหนด ตามชนิดเอกสารที่เลือก
 *
 * แผนที่ status → 0/1 มาจากคอลัมน์ due_date ของตารางแม่แบบ ส่งมาเป็น JSON
 * ในช่อง hidden #due_date_map จึงไม่มีการ hardcode ชนิดเอกสารไว้ใน JS
 */
function applyInventoryDueDate() {
    const group = document.getElementById('due_date_group');
    const status = document.getElementById('document_type');
    const raw = document.getElementById('due_date_map');
    if (!group || !status || !raw) {
        return;
    }
    let map = null;
    try {
        map = JSON.parse(raw.value || 'null');
    } catch (e) {
        map = null;
    }
    // ยังไม่ได้รับแผนที่มา (ถูกเรียกก่อนข้อมูลมาถึง) — อย่าเพิ่งซ่อนอะไร
    if (!map || typeof map !== 'object') {
        return;
    }
    group.hidden = Number(map[status.value] || 0) !== 1;
}

/**
 * ผู้ใช้เปลี่ยนชนิดเอกสาร
 *
 * @param {Event} event
 */
function onInventoryStatusChanged(event) {
    applyInventoryDueDate();
}

/**
 * คำนวณยอดของเอกสารซื้อ/ขาย
 *
 * LineItemsManager เรียกผ่าน data-on-calculate ทุกครั้งที่เพิ่ม/แก้/ลบแถว
 * ค่าที่คืน: `items[i]` = ค่าที่จะทับในแถวที่ i · คีย์ที่ขึ้นต้นด้วย # = selector ของช่องสรุป
 *
 * ⚠️ `data-auto-calc` / `data-sum-to` ที่เขียนไว้ในคอมเมนต์ของ LineItemsManager.js
 * ไม่มีโค้ดรองรับจริง ทั้งในซอร์สและใน Now/dist — data-on-calculate คือกลไกเดียวที่ใช้ได้
 *
 * สูตรถอดมาจากระบบเดิม (modules/inventory/script.js บรรทัด 160-215) ทั้งหมด:
 *   - ส่วนลดของบรรทัดเป็น **เปอร์เซ็นต์** ไม่ใช่จำนวนเงิน
 *       มีส่วนลด: (ราคา − ราคา×ส่วนลด/100) × จำนวน
 *       ไม่มี:     ราคา × จำนวน
 *   - VAT คิดเฉพาะบรรทัดที่ติ๊กช่อง VAT ไว้เท่านั้น (ยอดรวมของบรรทัดพวกนั้นคือฐานภาษี)
 *   - ส่วนลดท้ายบิลหักออกจากฐานภาษีด้วย
 *   - vat_status 1 = ราคายังไม่รวม VAT (บวกเพิ่ม) · 2 = รวมแล้ว (ถอดออก แล้วหักออกจากยอดรวม)
 *   - ภาษีหัก ณ ที่จ่าย คิดจาก (ยอดรวม − ส่วนลด)
 *   - จำนวนเงินรวมทั้งสิ้น = ยอดรวม − ส่วนลด + VAT
 *   - ยอดชำระ = จำนวนเงินรวมทั้งสิ้น − ภาษีหัก ณ ที่จ่าย
 *
 * @param {Object} ctx
 * @param {Array} ctx.items
 *
 * @return {Object}
 */
function calculateInventoryOrder(ctx) {
    const num = (v) => parseFloat(String(v == null ? 0 : v).replace(/,/g, '')) || 0;
    const el = (id) => document.getElementById(id);
    const val = (id) => (el(id) ? num(el(id).value) : 0);

    const vatRate = num(el('vat_rate') ? el('vat_rate').value : 0) || 7;
    const vatStatus = parseInt(el('vat_status') ? el('vat_status').value : 0, 10) || 0;
    const taxStatus = val('tax_status');

    let total = 0;
    let vatBase = 0;   // ฐานภาษี = เฉพาะบรรทัดที่ติ๊ก VAT

    const items = (ctx.items || []).map((item) => {
        const price = num(item.price);
        const quantity = num(item.quantity);
        const discount = num(item.discount);
        // ส่วนลดรายบรรทัดเป็นเปอร์เซ็นต์ (พฤติกรรมเดิม)
        const lineTotal = discount > 0
            ? (price - (discount * price) / 100) * quantity
            : price * quantity;

        if (inventoryOrderLineHasVat(item)) {
            vatBase += lineTotal;
        }
        total += lineTotal;

        return {total: lineTotal.toFixed(2)};
    });

    // ---- ส่วนลดท้ายบิล: ใส่ % จะคิดให้ ถ้าไม่ใส่ใช้จำนวนเงินที่พิมพ์เอง ----
    const discountPercent = val('discount_percent');
    let discount;
    if (discountPercent > 0) {
        discount = (discountPercent * total) / 100;
        vatBase -= (discountPercent * vatBase) / 100;
    } else {
        discount = val('total_discount');
        if (discount > 0) {
            vatBase -= discount;
        }
    }

    // ---- VAT ----
    let vat = 0;
    let netTotal = total;
    if (vatStatus > 0) {
        vat = inventoryCalcVat(vatBase, vatRate, vatStatus === 1);
        if (vatStatus === 2) {
            // ราคารวม VAT อยู่แล้ว ต้องถอดออกจากยอดรวม
            netTotal -= vat;
        }
    }

    const tax = taxStatus > 0 ? ((netTotal - discount) * taxStatus) / 100 : 0;
    const amount = netTotal - discount;

    return {
        items: items,
        '#sub_total': total.toFixed(2),
        '#total_discount': discount.toFixed(2),
        '#amount': amount.toFixed(2),
        '#vat_total': vat.toFixed(2),
        '#grand_total': (amount + vat).toFixed(2),
        '#tax_total': tax.toFixed(2),
        '#payment_amount': (amount + vat - tax).toFixed(2)
    };
}

/**
 * บรรทัดนี้ถูกติ๊กให้คิด VAT ไหม
 *
 * ค่าที่อ่านจากช่องสวิตช์ในตารางมาได้หลายรูป (true/1/"1"/"on") จึงตรวจให้ครบ
 *
 * @param {Object} item
 *
 * @return {boolean}
 */
function inventoryOrderLineHasVat(item) {
    const v = item ? item.vat : 0;
    return v === true || v === 1 || v === '1' || v === 'on';
}

/**
 * คำนวณ VAT — สูตรเดียวกับ Kotchasan\Currency::calcVat และของระบบเดิม
 *
 * @param {number} amount
 * @param {number} rate   อัตรา VAT เป็นเปอร์เซ็นต์
 * @param {boolean} exclusive true = ราคายังไม่รวม VAT (บวกเพิ่ม)
 *
 * @return {number}
 */
function inventoryCalcVat(amount, rate, exclusive) {
    if (exclusive) {
        return (rate * amount) / 100;
    }
    return amount - amount * (100 / (100 + rate));
}

/**
 * ปุ่มในคอลัมน์ราคา — บวกภาษีหัก ณ ที่จ่ายเข้าไปในราคา
 *
 * ใช้ตอนตกลงกับลูกค้าเป็น "ยอดที่ต้องได้รับจริง" แต่เอกสารถูกหักภาษี ณ ที่จ่าย
 * จึงต้องตั้งราคาให้สูงขึ้นเพื่อชดเชยส่วนที่ถูกหัก
 *
 *   ราคาใหม่ = ราคา + ราคา × tax / (100 − tax)
 *
 * เช่น ต้องการรับ 4,000 หัก ณ ที่จ่าย 3% → ตั้งราคา 4,123.71 (ถูกหัก 123.71 เหลือ 4,000)
 * สูตรและปุ่มนี้ยกมาจากระบบเดิมทั้งดุ้น (script.js — <a class="tax">)
 * ถ้ายังไม่ได้เลือกอัตราภาษีหัก ณ ที่จ่าย ปุ่มจะไม่ทำอะไร เหมือนเดิม
 *
 * LineItemsManager._handleCustomAction() ส่งค่าปัจจุบันของช่องที่ปุ่มอยู่มาให้
 * และเอาค่าที่คืนไปเขียนลงช่องพร้อมสั่งคำนวณใหม่เอง คืน undefined = ไม่เปลี่ยนอะไร
 *
 * @param {*} currentValue ราคาปัจจุบันของบรรทัดนั้น
 *
 * @return {string|undefined} ราคาใหม่
 */
function calcInventoryLineTax(currentValue) {
    const taxEl = document.getElementById('tax_status');
    const tax = parseFloat(taxEl ? taxEl.value : 0) || 0;
    if (tax <= 0 || tax >= 100) {
        return undefined;
    }

    const price = parseFloat(String(currentValue == null ? 0 : currentValue).replace(/,/g, '')) || 0;
    if (price <= 0) {
        return undefined;
    }

    return (price + (price * tax) / (100 - tax)).toFixed(2);
}

/**
 * สั่งคำนวณใหม่เมื่อผู้ใช้แก้ส่วนลด / VAT / ภาษีหัก ณ ที่จ่าย
 *
 * @param {Event} e
 */
function reCalculateInventoryOrder(e) {
    if (window.LineItemsManager) {
        LineItemsManager.recalculate(e);
    }
}

/**
 * เขียนค่าลงช่อง autocomplete ให้ทั้งข้อความที่แสดงและค่าที่ส่งตรงกัน
 *
 * ช่อง autocomplete มีสองส่วน: input ที่เห็น (ถือข้อความ) กับ hidden ที่ชื่อเดียวกัน
 * (ถือค่าจริง) การเซ็ต .value ตรง ๆ จะได้แค่ส่วนที่เห็น ค่าที่ส่งไปยังว่าง
 * ต้องผ่าน instance ของ ElementManager ซึ่งรับ {value, text} แล้วลงให้ครบทั้งคู่
 *
 * @param {string} id
 * @param {*} value ค่าที่จะส่งไปกับฟอร์ม
 * @param {string} text ข้อความที่ผู้ใช้เห็น
 */
function setInventoryAutocomplete(id, value, text) {
    const el = document.getElementById(id);
    if (!el) {
        return;
    }
    const instance = window.ElementManager?.getInstanceByElement?.(el);
    if (instance) {
        instance.value = {value: value, text: text === undefined ? value : text};
        return;
    }
    el.value = text === undefined ? value : text;
}

/**
 * รับลูกค้าที่เพิ่งเพิ่มจาก modal มาใส่ในเอกสารทันที
 *
 * ฝั่งเซิร์ฟเวอร์เรียกผ่าน action ชนิด callback หลังบันทึกสำเร็จ
 *
 * @param {Object} customer {id, customer_no, company}
 */
function setInventoryCustomer(customer) {
    if (!customer) {
        return;
    }
    setInventoryAutocomplete('customer_id', customer.id, customer.company);
    setInventoryAutocomplete('customer_no', customer.customer_no, customer.customer_no);
}

/**
 * รับสินค้าที่เพิ่งเพิ่มจาก modal มาใส่เป็นบรรทัดใหม่ทันที
 *
 * เขียนรหัสลงช่องค้นหาแล้วยิง change ให้ LineItemsManager ทำงานตามทางปกติ
 * (ยิง data-detail-api เอง) จึงไม่ต้องมีเส้นทางเพิ่มแถวสายที่สอง
 *
 * @param {Object} product {product_code}
 */
function setInventoryProduct(product) {
    if (!product || !product.product_code) {
        return;
    }
    const el = document.getElementById('product_code');
    if (!el) {
        return;
    }
    setInventoryAutocomplete('product_code', product.product_code, product.product_code);
    el.dispatchEvent(new Event('change', {bubbles: true}));
}

/**
 * แสดงจำนวนคงเหลือของสินค้าในตาราง (data-formatter)
 *
 * ใช้ data-formatter ไม่ใช่ data-format เพราะมีเงื่อนไข ไม่ใช่แค่รูปแบบตัวเลข:
 * สินค้าที่ไม่นับสต๊อก (count_stock = 0) ต้องขึ้นขีด ไม่ใช่เลข 0
 * ไม่งั้นผู้ใช้จะเข้าใจว่าของหมด ทั้งที่เป็นงานบริการซึ่งไม่มีสต๊อกตั้งแต่ต้น
 *
 * ⚠️ TableManager เรียกแบบ window[fn](cell, rawValue, rowData, attributes)
 * แล้ว **ไม่ได้ใช้ค่าที่ return** — ต้องเขียนลง cell เอง ถ้า return เฉย ๆ ช่องจะว่าง
 *
 * @param {HTMLElement} cell
 * @param {*} rawValue
 * @param {Object} rowData
 */
function formatInventoryStock(cell, rawValue, rowData) {
    if (rowData && Number(rowData.count_stock) === 0) {
        cell.textContent = '-';
        return;
    }
    cell.textContent = Number(rawValue || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

/**
 * จำนวนในสมุดบัญชี — แสดงพร้อมเครื่องหมายและสี
 *
 * ⚠️ ตาราง inventory_stock_movement เก็บจำนวนเป็นบวกเสมอ ทิศทางอยู่ที่คอลัมน์
 * movement_direction ถ้าโชว์เลขดิบ คนอ่านจะบวกทุกแถวแล้วได้ยอดที่ไม่มีความหมาย
 * (ของเข้า 100 ของออก 100 จะกลายเป็น 200 แทนที่จะเป็น 0)
 *
 * @param {HTMLElement} cell
 * @param {*} rawValue
 * @param {Object} rowData
 */
function formatLedgerQuantity(cell, rawValue, rowData) {
    const value = Number(rawValue || 0);
    cell.textContent = (value > 0 ? '+' : '') + value.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
    cell.classList.add(value < 0 ? 'ledger-out' : 'ledger-in');
}

/**
 * เลขที่เอกสารในสมุดบัญชี — กดกลับไปที่เอกสารต้นทางได้
 *
 * สมุดบัญชีที่ตามกลับไปหาเอกสารไม่ได้ ตรวจสอบไม่จบ — เห็นว่าของหายไป 5 ชิ้น
 * แต่เปิดดูไม่ได้ว่าใบไหนเอาไป ก็ยังไม่รู้อะไรเพิ่ม
 *
 * @param {HTMLElement} cell
 * @param {*} rawValue
 * @param {Object} rowData
 */
function formatLedgerReference(cell, rawValue, rowData) {
    const text = rawValue === null || rawValue === undefined ? '' : String(rawValue);
    if (text === '') {
        cell.textContent = '-';
        return;
    }
    if (rowData && rowData.reference_url) {
        const link = document.createElement('a');
        link.href = rowData.reference_url;
        link.textContent = text;
        cell.textContent = '';
        cell.appendChild(link);
        return;
    }
    cell.textContent = text;
}

/**
 * ยอดคงเหลือของชั้นต้นทุน — ชั้นที่ตัดหมดแล้วต้องดูออกทันทีว่าปิดแล้ว
 *
 * @param {HTMLElement} cell
 * @param {*} rawValue
 * @param {Object} rowData
 */
function formatCostLayerRemaining(cell, rawValue, rowData) {
    const value = Number(rawValue || 0);
    cell.textContent = value.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
    if (value <= 0) {
        cell.classList.add('layer-closed');
    }
}

/**
 * เครื่องหมายถูก/ขีด สำหรับคอลัมน์ true/false ทั่วไป — templates.html ใช้กับ
 * in_stock / cut_stock (แม่แบบเอกสารนี้รับของ/ตัดของไหม)
 *
 * @param {HTMLElement} cell
 * @param {*} rawValue
 * @param {Object} rowData
 */
function formatCheckStatus(cell, rawValue, rowData) {
    cell.textContent = Number(rawValue) ? '✓' : '—';
}

/**
 * เตรียมหน้าภาพรวมของสินค้า — เรียกโดยแกนผ่าน data-on-load ของ data-component="api"
 *
 * GraphComponent อ่าน data-url ตอนสร้าง instance ซึ่งเกิด "ก่อน" ที่ data-attr
 * จะเติม URL (ที่มี id กับปี) ให้ ผลคือกราฟขึ้นว่างพร้อมคำเตือน
 * "No data source specified" ที่นี่จึงสั่งให้โหลดอีกครั้งเมื่อ URL พร้อมแล้ว
 *
 * @param {HTMLElement} element กล่อง data-component="api" ของหน้านี้
 */
function initInventoryOverview(element) {
    const graph = element.querySelector('[data-component="graph"]');
    if (!graph || !window.GraphComponent) {
        return;
    }
    const url = graph.dataset.url || graph.getAttribute('data-url');
    if (!url) {
        return;
    }
    const instance = GraphComponent.getInstance(graph);
    if (instance) {
        instance.options.url = url;
        GraphComponent.loadData(instance, url);
    }
}

/**
 * ตัวกรองที่ต้อง "เปลี่ยนหน้า" ไม่ใช่แค่กรองตาราง
 *
 * ใช้กับตัวเลือกที่มีผลกับทั้งหน้า เช่น ปีของหน้าภาพรวม และชนิดเอกสารของหน้ารายการ
 * เพราะหัวข้อหน้า ปุ่มเพิ่ม กราฟ และตาราง อ่านค่าจาก query ของหน้าเดียวกัน
 * ถ้ากรองเฉพาะตาราง ส่วนอื่นจะค้างอยู่ที่ค่าเดิมและขัดกันเอง
 *
 * @param {Event} event เหตุการณ์ change ของ <select> ที่มี name ตรงกับชื่อพารามิเตอร์
 */
function navigateInventoryFilter(event) {
    const select = event.target;
    const params = new URLSearchParams(window.location.search);
    if (select.value === '') {
        params.delete(select.name);
    } else {
        params.set(select.name, select.value);
    }
    const query = params.toString();
    const url = window.location.pathname + (query ? '?' + query : '');
    if (window.RouterManager && typeof RouterManager.navigate === 'function') {
        RouterManager.navigate(url);
    } else {
        window.location.href = url;
    }
}

/**
 * เปิดหน้าส่งออก/พิมพ์ของเอกสารที่เลือกไว้ในแท็บใหม่
 *
 * ตารางส่งคำสั่งของแถวที่ติ๊กไว้เป็น POST ตอบกลับเป็นหน้าเว็บไม่ได้
 * ฝั่งเซิร์ฟเวอร์จึงตอบเป็น callback มาให้เปิดลิงก์เอง
 *
 * เปิดด้วย window.open ตรง ๆ ไม่ผ่านเราเตอร์ของ SPA เพราะเป็นหน้าพิมพ์
 * คนละระบบกัน (ยืนยันตัวตนด้วยคุกกี้ auth_token)
 *
 * @param {{url: string}} payload ลิงก์ที่จะเปิด
 */
function openInventoryExport(payload) {
    const url = payload && payload.url;
    if (!url) {
        return;
    }
    const opened = window.open(url, '_blank');
    if (!opened) {
        // เบราว์เซอร์บล็อกป๊อปอัป บอกให้รู้ ดีกว่ากดแล้วเงียบ
        if (window.Now && typeof Now.translate === 'function' && window.NotificationManager) {
            NotificationManager.show(Now.translate('Please allow pop-ups for this site'), 'warning');
        } else {
            window.location.href = url;
        }
    }
}

/**
 * ปุ่ม PDF ของเอกสารซื้อ/ขายรายฉบับ — แปลงหน้าพิมพ์เป็น PDF จริงในเบราว์เซอร์ผู้ใช้
 *
 * เครื่องเซิร์ฟเวอร์นี้ปิด exec()/proc_open() ไว้ทั้งแผงโฮสติ้ง (กันไม่ให้ช่องโหว่บนเว็บ
 * กลายเป็น shell) Export\Export\Controller::toPdf() จึงเรียก headless Chrome ฝั่ง
 * เซิร์ฟเวอร์ไม่ได้เสมอ แล้ว fallback เป็นหน้าพิมพ์ธรรมดาที่รอให้ผู้ใช้กด Ctrl+P เอง
 * (printDialogFallback) — ปุ่มนี้จึงแปลงแทนด้วย html2canvas + jsPDF ในเบราว์เซอร์
 * ผู้ใช้เอง ได้ไฟล์ .pdf ดาวน์โหลดตรง ๆ โดยไม่ต้องพึ่งความสามารถของเซิร์ฟเวอร์เลย
 *
 * ดักด้วย capture phase ที่ document ก่อนที่ปุ่มจะไปถึง listener ของ TableManager เอง
 * (ซึ่งจะ fetch URL ตรง ๆ แล้วเซฟไฟล์ดิบที่ได้ตรง ๆ — กลายเป็นหน้าพิมพ์ HTML แทน PDF
 * จริงเพราะเซิร์ฟเวอร์ไม่มี PDF จริงให้) stopImmediatePropagation ตัดไม่ให้ listener
 * นั้นทำงานต่อ
 */
document.addEventListener('click', function (event) {
    const table = event.target.closest('table[data-table="orders"]');
    if (!table) {
        return;
    }
    const button = event.target.closest('.icon-pdf');
    if (!button || !table.contains(button)) {
        return;
    }
    const row = button.closest('tr[data-id]');
    if (!row) {
        return;
    }
    event.preventDefault();
    event.stopImmediatePropagation();
    generateInventoryOrderPdf(row.dataset.id, button);
}, true);

/**
 * แปลงหน้าพิมพ์ของเอกสารหนึ่งใบเป็น PDF แล้วดาวน์โหลด
 *
 * ใช้ HTML ชุดเดียวกับปุ่ม "พิมพ์" (typ=billing) ไม่ใช่ typ=pdf — typ=pdf จะลองเรียก
 * headless Chrome ฝั่งเซิร์ฟเวอร์ก่อนเสมอ (พังบนเครื่องนี้แน่ ๆ) แล้วค่อย fallback
 * กลับมาเป็นหน้าพิมพ์อยู่ดี ข้ามการลองที่รู้อยู่แล้วว่าไม่ผ่านไปเลยเร็วกว่า
 *
 * หนึ่ง .sheet ในหน้าพิมพ์ = หนึ่งหน้ากระดาษเสมอ (ดู modules/export/views/sheet.html)
 * จึงแปลงทีละ .sheet เป็นหนึ่งหน้าของ PDF ขนาดหน้าอ่านจากกล่องจริงที่เรนเดอร์ (px)
 * แปลงเป็น มม. ด้วยอัตราส่วนมาตรฐานของเบราว์เซอร์ (96px = 1in = 25.4mm) แทนที่จะ
 * ต้องรู้ล่วงหน้าว่าเป็นกระดาษขนาดไหน (A4/A5/ม้วนใบเสร็จ) ใช้ได้กับทุกขนาดเหมือนกัน
 *
 * @param {string|number} id     รหัสเอกสาร
 * @param {HTMLElement}   button ปุ่มที่กด (ใช้แสดงสถานะกำลังทำงาน)
 */
async function generateInventoryOrderPdf(id, button) {
    button.disabled = true;
    button.classList.add('loading');

    let iframe = null;
    try {
        await ensureInventoryPdfVendors();

        const response = await fetch('export.php?module=inventory&typ=billing&id=' + encodeURIComponent(id), {
            credentials: 'same-origin'
        });
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }
        const html = await response.text();

        // srcdoc แทน src=blob: เพราะต้องการเอกสารเดียวกับที่ผู้ใช้เห็นตอนพิมพ์เป๊ะ ๆ
        // (ฟอนต์/CSS ของหน้าพิมพ์เอง) โดยไม่ต้องเก็บกวาด object URL ทีหลัง
        iframe = document.createElement('iframe');
        iframe.style.cssText = 'position:fixed;left:-10000px;top:0;width:1200px;height:100px;border:0;visibility:hidden;';
        document.body.appendChild(iframe);
        await new Promise((resolve, reject) => {
            iframe.onload = resolve;
            iframe.onerror = () => reject(new Error('iframe load failed'));
            iframe.srcdoc = html;
        });

        const doc = iframe.contentDocument;
        // แถบเลือกกระดาษ/ปุ่มพิมพ์ ฯลฯ (.noprint) ไม่ควรติดไปในภาพที่ capture
        doc.querySelectorAll('.noprint').forEach((el) => el.remove());

        if (doc.fonts && doc.fonts.ready) {
            await doc.fonts.ready;
        }
        await Promise.all(Array.from(doc.images).map((img) => (img.complete
            ? null
            : new Promise((resolve) => { img.onload = img.onerror = resolve; }))));

        const sheets = Array.from(doc.querySelectorAll('.sheet'));
        if (sheets.length === 0) {
            throw new Error('No .sheet content in the print page');
        }

        const jsPDFCtor = window.jspdf && window.jspdf.jsPDF;
        if (!jsPDFCtor || !window.html2canvas) {
            throw new Error('html2canvas/jsPDF not loaded');
        }

        const MM_PER_PX = 25.4 / 96;
        let pdf = null;
        for (const sheet of sheets) {
            const rect = sheet.getBoundingClientRect();
            const widthMm = rect.width * MM_PER_PX;
            const heightMm = rect.height * MM_PER_PX;
            const canvas = await window.html2canvas(sheet, {
                scale: 2,
                useCORS: true,
                backgroundColor: '#ffffff'
            });
            const imageData = canvas.toDataURL('image/jpeg', 0.95);
            if (!pdf) {
                pdf = new jsPDFCtor({
                    unit: 'mm',
                    format: [widthMm, heightMm],
                    orientation: widthMm > heightMm ? 'landscape' : 'portrait'
                });
            } else {
                pdf.addPage([widthMm, heightMm]);
            }
            pdf.addImage(imageData, 'JPEG', 0, 0, widthMm, heightMm);
        }

        const orderNoEl = doc.querySelector('.doc-value');
        const orderNo = (orderNoEl ? orderNoEl.textContent.trim() : '') || String(id);
        const blob = pdf.output('blob');

        if (window.TableManager && typeof TableManager.downloadBlob === 'function') {
            TableManager.downloadBlob(blob, orderNo + '.pdf');
        } else {
            const blobUrl = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = blobUrl;
            a.download = orderNo + '.pdf';
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(() => URL.revokeObjectURL(blobUrl), 1000);
        }
    } catch (err) {
        console.error('generateInventoryOrderPdf failed:', err);
        if (window.NotificationManager) {
            NotificationManager.show(Now.translate('Failed to create the PDF file'), 'error');
        }
    } finally {
        if (iframe) {
            iframe.remove();
        }
        button.disabled = false;
        button.classList.remove('loading');
    }
}

let inventoryPdfVendorsPromise = null;

/**
 * โหลด html2canvas + jsPDF แบบขี้เกียจ — โหลดครั้งแรกที่กดปุ่ม PDF จริงเท่านั้น
 * ไม่ต้องเสียเวลาโหลดล่วงหน้าให้ทุกหน้าที่ไม่ได้ใช้ปุ่มนี้
 *
 * เก็บไฟล์ไว้ในโปรเจ็คเอง (modules/export/views/vendor/) ไม่พึ่ง CDN ภายนอก
 * ตามธรรมเนียมเดียวกับฟอนต์ของหน้าพิมพ์ — ใช้งานได้แม้เซิร์ฟเวอร์ไม่มีอินเทอร์เน็ต
 *
 * @return {Promise<void>}
 */
function ensureInventoryPdfVendors() {
    if (!inventoryPdfVendorsPromise) {
        const base = 'modules/export/views/vendor/';
        inventoryPdfVendorsPromise = Promise.all([
            loadInventoryScriptOnce(base + 'html2canvas.min.js'),
            loadInventoryScriptOnce(base + 'jspdf.umd.min.js')
        ]);
    }
    return inventoryPdfVendorsPromise;
}

/**
 * โหลดสคริปต์หนึ่งไฟล์ ถ้าเคยแทรกไปแล้ว (สำเร็จหรือกำลังโหลดอยู่) จะไม่แทรกซ้ำ
 *
 * @param {string} src
 *
 * @return {Promise<void>}
 */
function loadInventoryScriptOnce(src) {
    const existing = document.querySelector('script[data-src="' + src + '"]');
    if (existing) {
        return existing.dataset.loaded === '1'
            ? Promise.resolve()
            : new Promise((resolve, reject) => {
                existing.addEventListener('load', () => resolve());
                existing.addEventListener('error', () => reject(new Error('Failed to load ' + src)));
            });
    }
    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = src;
        script.dataset.src = src;
        script.onload = () => {
            script.dataset.loaded = '1';
            resolve();
        };
        script.onerror = () => reject(new Error('Failed to load ' + src));
        document.head.appendChild(script);
    });
}
