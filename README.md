# OAS

OAS (Online Accounting System) คือระบบหลังบ้านแบบเว็บสำหรับจัดการลูกค้า สินค้าคงคลัง และเอกสารซื้อขายในที่เดียว โดยโค้ดชุดนี้ใช้ PHP + Kotchasan/GCMS ฝั่งเซิร์ฟเวอร์ และ Now.js เป็น SPA frontend สำหรับหน้าใช้งานหลัก

รีโปนี้เหมาะกับการนำไปต่อยอดเป็นระบบบริหารงานขาย ระบบสต็อกสินค้า ระบบจัดซื้อ หรือระบบแอดมินภายในองค์กรที่ต้องการหน้าเว็บใช้งานจริงพร้อม API, export, ผู้ใช้หลายสิทธิ์ และเมนูตั้งค่าระบบ

## จุดเด่นของระบบ

- จัดการลูกค้าและซัพพลายเออร์ในโมดูล `customer`
- จัดการสินค้า หมวดหมู่ สต็อก การเคลื่อนไหวสินค้า และต้นทุนในโมดูล `inventory`
- จัดการเอกสารสำคัญในโมดูล `order` เช่น Quotation, Receipt, Purchase Order, Returned และ Goods Receipt
- มีระบบสมาชิก สิทธิ์ผู้ใช้ ภาษา อีเมล API และการตั้งค่า LINE, Telegram, SMS
- รองรับงาน export ผ่าน `export.php`
- มี installer สำหรับติดตั้งระบบผ่านเว็บในโฟลเดอร์ `install/`
- มี frontend bundle ที่ build มาแล้วใน `Now/dist/` จึงสามารถติดตั้งเพื่อใช้งานได้ทันทีโดยไม่ต้อง build ใหม่ทุกครั้ง

## Tech Stack

| Layer | รายละเอียด |
| --- | --- |
| Frontend | Now.js SPA, route หลักอยู่ใน `js/main.js` |
| Backend | PHP + Kotchasan + GCMS |
| Database | MySQL / MariaDB ผ่าน PDO MySQL |
| Build tools | Vite, npm scripts ใน `package.json` |
| Entry points | `index.html`, `api.php`, `export.php`, `line/webhook.php` |

## โมดูลหลัก

### `modules/order`

- หน้า dashboard และรายการเอกสาร
- สร้างและแก้ไขเอกสารซื้อขาย
- จัดการช่องทางชำระเงินและวิธีจัดส่ง
- รองรับประเภทเอกสาร `QT`, `RCP`, `PO`, `RET`, `GR`

### `modules/inventory`

- จัดการสินค้าและข้อมูลสินค้า
- ดู stock movement และ cost layers
- จัดการหมวดหมู่สินค้าและค่าตั้งต้นของโมดูล

### `modules/customer`

- จัดการข้อมูลลูกค้า
- ใช้รูปแบบเดียวกันกับ supplier โดยอิงประเภทข้อมูลในระบบ

### `modules/index`

- ระบบ auth, profile, users, permissions
- หน้าตั้งค่าทั่วไปของระบบ
- ภาษา, email, API, theme, LINE, Telegram, SMS

## ความต้องการของระบบ

- PHP `7.4` ขึ้นไป
- MySQL หรือ MariaDB
- PHP extensions ที่ installer ตรวจจริง
  - PDO MySQL
  - mbstring
  - zlib
  - JSON
  - XML
  - OpenSSL
  - GD
  - cURL
- สิทธิ์เขียนไฟล์/โฟลเดอร์สำหรับ
  - `datas/`
  - `datas/cache/`
  - `datas/logs/`
  - `datas/images/`
  - `settings/`
  - `settings/config.php`
  - `settings/database.php`
- Node.js และ npm หากต้องการ build frontend ใหม่

## ติดตั้งแบบย่อ

1. คัดลอกโปรเจ็กต์ไปไว้ใน web root ของ Apache, Nginx หรือ local PHP stack
2. ตรวจสอบสิทธิ์เขียนของโฟลเดอร์ `datas/` และ `settings/`
3. หากต้องการ build frontend ใหม่ ให้รันคำสั่งด้านล่าง

```bash
npm install
npm run build
```

4. เปิดหน้า `install/` ผ่านเบราว์เซอร์
5. กรอกข้อมูลผู้ดูแลระบบและข้อมูลฐานข้อมูลตามขั้นตอนบนหน้าจอ
6. เมื่อติดตั้งเสร็จ ให้ลบโฟลเดอร์ `install/`
7. ปรับ `DEBUG` ใน `load.php` ให้เหมาะกับ production
8. เข้าระบบแล้วตรวจสอบบัญชีตัวอย่างที่ installer สร้างไว้ และลบหรือปิดใช้งานหากไม่ต้องใช้

## คำสั่งสำหรับพัฒนา

```bash
npm run dev
npm run build
npm run build:core
npm run build:table
npm run build:graph
npm run build:serviceworker
npm run build:queue
npm run build:eventcalendar
npm run build:editor
npm run build:formbuilder
npm run build:syntaxhighlighter
```

หมายเหตุ: หากไม่ได้แก้ frontend code โดยตรง สามารถใช้ bundle ที่มีอยู่ใน `Now/dist/` ได้ทันที

## โครงสร้างสำคัญของโปรเจ็กต์

```text
index.html          SPA shell หลัก
js/main.js          ตั้งค่า Now.js, auth, i18n และ router
api.php             จุดเข้า API หลัก
export.php          จุดเข้า export/print
install/            ระบบติดตั้งผ่านเว็บ
modules/order/      เอกสารซื้อขายและ dashboard
modules/inventory/  สินค้า สต็อก และต้นทุน
modules/customer/   ลูกค้าและซัพพลายเออร์
templates/          HTML templates ของหน้าใช้งาน
Now/                frontend framework และ build sources
```

## Deployment Note

ระบบ frontend ใช้ router mode แบบ `history` ใน `js/main.js` ดังนั้นถ้า deploy บน Apache หรือ Nginx ควรตั้งค่า fallback ให้ route ฝั่งหน้าเว็บอย่าง `/orders` หรือ `/inventory-products` กลับมาเปิด `index.html` ได้ โดยไม่ไป rewrite asset files, `api.php`, `export.php` และ `line/`

## Security Checklist หลังติดตั้ง

- ลบโฟลเดอร์ `install/`
- ปรับ `DEBUG` ใน `load.php` ให้ไม่แสดง error บน production
- ตรวจสอบบัญชีตัวอย่างที่ installer เพิ่มให้อัตโนมัติ เช่น `demo`
- ตั้งค่าอีเมลและ API secrets ให้ตรงกับสภาพแวดล้อมจริง
- สำรองโฟลเดอร์ `datas/` และไฟล์ใน `settings/`

## License

โปรเจ็กต์นี้ใช้สัญญาอนุญาตแบบ MIT ดูรายละเอียดได้ที่ [LICENSE](LICENSE)