/**
 * modules/inventory/tests/calc.cjs — ทดสอบสูตรคำนวณยอดของเอกสาร
 *
 * โหลด admin.js ตัวจริงมารัน แล้วเทียบกับผลที่คำนวณมือไว้ล่วงหน้า
 * สูตรอ้างอิงจากระบบเดิม modules/inventory/script.js บรรทัด 160-215
 *
 * ใช้:  node modules/inventory/tests/calc.cjs
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const adminJs = fs.readFileSync(path.join(__dirname, '..', 'admin.js'), 'utf8');

// ค่าของช่องสรุปที่หน้าเว็บมี — จำลองเป็น object ธรรมดา
const fields = {};

const sandbox = {
    // admin.js ลงทะเบียน route ตอนโหลด จึงต้องมีตัวหลอกไว้
    EventManager: {on() {}},
    RouterManager: {register() {}},
    window: {},
    console,
    document: {
        getElementById(id) {
            return Object.prototype.hasOwnProperty.call(fields, id)
                ? {value: String(fields[id])}
                : null;
        },
        // admin.js ลงทะเบียนตัวดัก click ระดับ document ตอนโหลด (ปุ่ม PDF ฝั่งไคลเอนต์)
        addEventListener() {}
    }
};
sandbox.window.LineItemsManager = null;
vm.createContext(sandbox);
vm.runInContext(adminJs, sandbox);

let ok = 0;
let fail = 0;

/**
 * ยืนยันค่าตัวเลข (ทศนิยม 2 ตำแหน่ง)
 *
 * @param {string} label
 * @param {*} actual
 * @param {number} expected
 */
function eq(label, actual, expected) {
    const a = parseFloat(actual);
    if (Math.abs(a - expected) < 0.005) {
        ++ok;
        console.log('  [ok]   ' + label + ' = ' + a.toFixed(2));
    } else {
        ++fail;
        console.log('  [FAIL] ' + label + ' = ' + a + ' (ควรเป็น ' + expected.toFixed(2) + ')');
    }
}

/**
 * ตั้งค่าช่องสรุปแล้วเรียกตัวคำนวณ
 *
 * @param {Object} form
 * @param {Array} items
 *
 * @return {Object}
 */
function run(form, items) {
    Object.keys(fields).forEach((k) => delete fields[k]);
    Object.assign(fields, {vat_rate: 7, vat_status: 0, tax_status: 0, discount_percent: 0, total_discount: 0}, form);

    return sandbox.calculateInventoryOrder({items: items});
}

console.log('\n== ยอดต่อบรรทัด (ส่วนลดเป็นเปอร์เซ็นต์ ตามระบบเดิม)');
let r = run({}, [
    {quantity: 2, price: 100, discount: 0, vat: 0},
    {quantity: 3, price: 50, discount: 10, vat: 0}
]);
// 2×100 = 200 · (50 − 50×10/100) × 3 = 45 × 3 = 135
eq('บรรทัดที่ 1', r.items[0].total, 200);
eq('บรรทัดที่ 2 (ส่วนลด 10%)', r.items[1].total, 135);
eq('รวมเป็นเงิน', r['#sub_total'], 335);
eq('ยอดรวม', r['#amount'], 335);
eq('ไม่มี VAT', r['#vat_total'], 0);

console.log('\n== ส่วนลดท้ายบิลเป็นเปอร์เซ็นต์');
r = run({discount_percent: 10}, [{quantity: 1, price: 1000, discount: 0, vat: 0}]);
eq('ส่วนลด 10% ของ 1000', r['#total_discount'], 100);
eq('ยอดรวมหลังหักส่วนลด', r['#amount'], 900);

console.log('\n== ส่วนลดท้ายบิลเป็นจำนวนเงิน (เมื่อไม่ใส่ %)');
r = run({total_discount: 250}, [{quantity: 1, price: 1000, discount: 0, vat: 0}]);
eq('ใช้จำนวนเงินที่พิมพ์', r['#total_discount'], 250);
eq('ยอดรวม', r['#amount'], 750);

console.log('\n== VAT คิดเฉพาะบรรทัดที่ติ๊ก (ราคายังไม่รวม VAT)');
r = run({vat_status: 1}, [
    {quantity: 1, price: 1000, discount: 0, vat: 1},
    {quantity: 1, price: 500, discount: 0, vat: 0}
]);
eq('รวมเป็นเงิน', r['#sub_total'], 1500);
eq('VAT 7% จากเฉพาะบรรทัดที่ติ๊ก (1000)', r['#vat_total'], 70);
eq('ยอดรวมไม่ถูกหัก', r['#amount'], 1500);
eq('ยอดสุทธิ', r['#grand_total'], 1570);

console.log('\n== ไม่ติ๊กบรรทัดไหนเลย ต้องไม่มี VAT');
r = run({vat_status: 1}, [{quantity: 1, price: 1000, discount: 0, vat: 0}]);
eq('VAT', r['#vat_total'], 0);

console.log('\n== ราคารวม VAT อยู่แล้ว (vat_status = 2) ต้องถอดออก');
r = run({vat_status: 2}, [{quantity: 1, price: 1070, discount: 0, vat: 1}]);
// 1070 − 1070×(100/107) = 70
eq('VAT ที่ถอดออกมา', r['#vat_total'], 70);
eq('ยอดรวมถูกหัก VAT ออก', r['#amount'], 1000);
eq('ยอดสุทธิกลับมาเท่าราคาที่ตกลง', r['#grand_total'], 1070);

console.log('\n== ส่วนลดท้ายบิลลดฐานภาษีด้วย');
r = run({vat_status: 1, discount_percent: 10}, [{quantity: 1, price: 1000, discount: 0, vat: 1}]);
eq('ส่วนลด', r['#total_discount'], 100);
eq('VAT คิดจากฐานหลังหักส่วนลด (900)', r['#vat_total'], 63);
eq('ยอดรวม', r['#amount'], 900);
eq('ยอดสุทธิ', r['#grand_total'], 963);

console.log('\n== ภาษีหัก ณ ที่จ่าย คิดจากยอดรวมหลังหักส่วนลด');
r = run({vat_status: 1, tax_status: 3}, [{quantity: 1, price: 1000, discount: 0, vat: 1}]);
eq('หัก ณ ที่จ่าย 3% ของ 1000', r['#tax_total'], 30);
eq('จำนวนเงินรวมทั้งสิ้น = 1000 + 70', r['#grand_total'], 1070);
eq('ยอดชำระ = 1070 − 30', r['#payment_amount'], 1040);

console.log('\n== เอกสารว่าง');
r = run({}, []);
eq('รวมเป็นเงิน', r['#sub_total'], 0);
eq('ยอดสุทธิ', r['#grand_total'], 0);
eq('ยอดชำระ', r['#payment_amount'], 0);

// ปุ่มในคอลัมน์ราคา — บวกภาษีหัก ณ ที่จ่ายกลับเข้าไปในราคา
// ตัวเลขอ้างอิงจากหน้าจอของระบบเดิม: ต้องได้รับ 4,000 หัก 3% → ตั้งราคา 4,123.71
console.log('\n== ปุ่มบวกภาษีหัก ณ ที่จ่ายเข้าไปในราคา');
fields.tax_status = 3;
eq('4000 + หัก ณ ที่จ่าย 3%', sandbox.calcInventoryLineTax(4000), 4123.71);
eq('รับค่าที่มีลูกน้ำได้', sandbox.calcInventoryLineTax('4,000.00'), 4123.71);

// ราคาใหม่ต้องถูกหัก 3% แล้วเหลือเท่าเดิมพอดี
const grossed = parseFloat(sandbox.calcInventoryLineTax(4000));
eq('หัก 3% จากราคาใหม่แล้วได้ยอดเดิม', grossed - (grossed * 3) / 100, 4000);

fields.tax_status = 0;
if (sandbox.calcInventoryLineTax(4000) === undefined) {
    ++ok;
    console.log('  [ok]   ยังไม่เลือกอัตราภาษี ปุ่มไม่เปลี่ยนราคา');
} else {
    ++fail;
    console.log('  [FAIL] ยังไม่เลือกอัตราภาษี แต่ปุ่มเปลี่ยนราคา');
}

fields.tax_status = 3;
if (sandbox.calcInventoryLineTax(0) === undefined) {
    ++ok;
    console.log('  [ok]   ราคา 0 ปุ่มไม่ทำอะไร');
} else {
    ++fail;
    console.log('  [FAIL] ราคา 0 แต่ปุ่มเปลี่ยนค่า');
}

console.log('\n== ตัวช่วยอ่านค่า checkbox ของบรรทัด');
[[true, true], [1, true], ['1', true], ['on', true], [0, false], ['', false], [undefined, false]].forEach(([v, want]) => {
    const got = sandbox.inventoryOrderLineHasVat({vat: v});
    if (got === want) {
        ++ok;
        console.log('  [ok]   vat=' + JSON.stringify(v) + ' → ' + got);
    } else {
        ++fail;
        console.log('  [FAIL] vat=' + JSON.stringify(v) + ' → ' + got + ' (ควรเป็น ' + want + ')');
    }
});

console.log('\n' + '-'.repeat(60));
console.log('ผ่าน ' + ok + ' / ล้มเหลว ' + fail);
process.exit(fail ? 1 : 0);
