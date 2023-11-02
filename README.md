# ระบบบัญชีออนไลน์ Online Accounting System (OAS)

เป็นระบบบัญชีออนไลน์ ที่สร้างจาก Kotchasan Web Framework มีความสามารถในการ จัดการสต๊อกสินค้า
ทำรายการ ซื้อ ขาย ออกใบเสร็จรับเงิน และ รายงานยอดขาย

รายละเอียดเพิ่มเติม https://www.kotchasan.com/index.php?module=knowledge&id=90

## ความต้องการของระบบ

- PHP 5.3 ขึ้นไป
- ext-mbstring
- PDO Mysql

## การติดตั้งและการอัปเกรด

1.  ให้อัปโหลดโค้ดทั้งหมดจากที่ดาวน์โหลด ขึ้นไปบน Server
2.  เรียกตัวติดตั้ง http://domain.tld/install/ (เปลี่ยน domain.tld เป็นโดเมนรวมพาธที่ทำการติดตั้งไว้) และดำเนินการตามขั้นตอนการติดตั้งหรืออัปเกรดจนกว่าจะเสร็จสิ้น
3.  ลบไดเร็คทอรี่ install/ ออก

## การใช้งาน

- เข้าระบบเป็นผู้ดูแลระบบ : `admin@localhost` และ Password : `admin`
- เข้าระบบเป็นพนักงาน : `demo@localhost` และ Password : `demo`

## API

คือการขอข้อมูลรายละเอียดของสินค้าที่มีอยู่ในระบบผ่าน RESTApi คืนค่าเป็นฟอร์แมต JSON สามารถนำไปใช้เชื่อมต่อกับระบบหน้าร้านค้าได้

- api.php/v1/product/categories หมวดหมู่ของสินค้าทั้งหมด
- api.php/v1/product/products/<category_id>/<page> รายการสินค้าตามหมวดหมู่ที่เลือก (category_id) แบบแบ่งหน้า คราวละ 30 รายการ และหน้าที่เลือก (page)
- api.php/v1/product/get/<id> ข้อมูลสินค้ารายการที่เลือก (id)
- api.php/v1/product/search/<q>/<page> ค้นหาสินค้าจาก q แสดงหน้าที่เลือก (page) (ถ้าไม่ระบุหน้าจะแสดงหน้าแรก)

ในการใช้งาน API หากเป็นการเรียกใช้งานจากนอก Server อาจต้องเปิดการใช้งาน Access-Control-Allow-Origin ด้วย โดยการสร้างไฟล์ .htaccess (หรือถ้ามีอยู่แล้วให้เปิดไฟล์มาแก้ไข) โดยการใส่โค้ดเหล่านี้ลงไป

```
RewriteEngine On

# Cross domain access for API
Header add Access-Control-Allow-Origin "*"
Header add Access-Control-Allow-Headers "origin, x-requested-with, content-type"
Header add Access-Control-Allow-Methods "PUT, GET, POST, DELETE, OPTIONS"

RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^(.*)$ index.php [QSA,L]
```

## ข้อตกลงการนำไปใช้งาน

- สามารถนำไปใช้งานส่วนตัวได้
- สามารถพัฒนาต่อยอดได้
- มีข้อสงสัยสามารถสอบถามได้ที่บอร์ดของคชสาร https://www.kotchasan.com
- ต้องการให้ผู้เขียนพัฒนาเพิ่มเติม ติดต่อผู้เขียนได้โดยตรง (อาจมีค่าใช้จ่าย)
- ผู้เขียนไม่รับผิดชอบข้อผิดพลาดใดๆในการใช้งาน
- ห้ามขาย ถ้าต้องการนำไปพัฒนาต่อเพื่อขายให้ติดต่อผู้เขียนก่อน (เพื่อบริจาค)

## หากต้องการสนับสนุนผู้เขียน สามารถบริจาคช่วยเหลือค่า Server ได้ที่

```
ธนาคาร กสิกรไทย สาขากาญจนบุรี
เลขที่บัญชี 221-2-78341-5
ชื่อบัญชี กรกฎ วิริยะ
```
