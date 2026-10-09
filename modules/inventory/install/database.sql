-- ---------------------------------------------------------------------------
-- modules/inventory/install/database.sql — ตารางของโมดูล inventory กลาง
--
-- ตัวติดตั้ง (install/cli-fresh.php, install/step4.php) และตัวปรับรุ่น
-- (install/upgrade_core.php → ensureTable) อ่านไฟล์นี้เอง ผ่าน schemaFiles()
-- ซึ่งกวาด modules/*/install/database.sql ให้ครบทุกโมดูลที่ติดตั้งอยู่
-- ถอดโฟลเดอร์โมดูลออก = ไม่มีตารางเหล่านี้ · วางกลับ = ได้ครบเหมือนเดิม
--
-- การพาฐานเดิมของแต่ละผลิตภัณฑ์มาถึงหน้าตานี้อยู่ใน install/upgrade.php ข้าง ๆ
--
-- กติกาที่ยึดตลอดทั้งไฟล์
--   1. ชื่อกลางเลือกจาก "คำที่แกนของเฟรมเวิร์กใช้อยู่แล้ว" ก่อนเสมอ
--      (category.is_active, user.provinceID) แล้วจึงดูว่าใครใช้เยอะกว่ากัน
--   2. คอลัมน์เดิมของทุกระบบ "คงไว้" ไม่ลบ ไม่เปลี่ยนชื่อ — เพิ่มคอลัมน์กลาง
--      แล้วคัดลอกค่า โมดูลเขียนคู่กันไว้ระหว่างเปลี่ยนผ่าน
--   3. ตารางที่ระบบไหนไม่ใช้ ก็ยังสร้าง (ว่างเปล่า ไม่กินอะไร) เพื่อให้โมดูล
--      ชุดเดียวทำงานได้ทุกที่โดยไม่ต้องมีเงื่อนไข "ระบบนี้มีตารางนั้นไหม"
--
-- ที่มาของแต่ละคอลัมน์กำกับไว้ท้ายบรรทัด :
--   [oms] [oas] [inv] = inventory/borrow  [กลาง] = ของใหม่ที่ตกลงกันใน Phase 1.2
-- ---------------------------------------------------------------------------

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

-- ---------------------------------------------------------------------------
-- inventory — Product Master
--
-- รหัสสินค้าระดับ master ใช้ชื่อ product_code ไม่ใช่ product_no เพราะ
-- product_no ถูกใช้ไปแล้วในความหมายอื่น (เลขครุภัณฑ์รายชิ้น) ที่ตาราง
-- inventory_items ของ inventory/borrow — ใช้ชื่อเดียวกันสองความหมายในโมดูล
-- ชุดเดียวคือการวางกับดักไว้ให้ตัวเอง
--
-- is_active คือชื่อกลาง ไม่ใช่ inuse : ตารางแกน category ใช้ is_active อยู่แล้ว
-- (upgrade_core แปลง published → is_active ให้ทุกโปรเจ็ค) และ inventory/borrow
-- ซึ่งเป็นสองผลิตภัณฑ์ที่มีผู้ใช้จริงมากที่สุดก็ใช้ is_active — เหลือ oas/oms
-- ที่ใช้ inuse ซึ่งทั้งคู่แก้ได้อิสระกว่า
--
-- category_id เป็น varchar(10) ตามตารางแกน category ไม่ใช่ int แบบ oms
-- ---------------------------------------------------------------------------
CREATE TABLE `{prefix}_inventory` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `product_code` varchar(150) DEFAULT NULL,                 -- [กลาง] oms:product_no · oas:product_code · inv:ไม่มี
  `product_no` varchar(150) DEFAULT NULL,                   -- [oms] คงไว้ เขียนคู่กับ product_code
  `topic` varchar(150) NOT NULL,                            -- ทุกระบบ
  `description` text DEFAULT NULL,                          -- [oms varchar(255)] ขยายเป็น text ตาม oas
  `category_id` varchar(10) DEFAULT NULL,                   -- ตามตารางแกน category
  `model_id` varchar(10) DEFAULT NULL,                      -- [inv]
  `type_id` varchar(10) DEFAULT NULL,                       -- [inv]
  `unit` varchar(20) DEFAULT NULL,                          -- [oms]
  `price` double NOT NULL DEFAULT 0,                        -- [oms] ราคาขายตั้งต้น
  `vat` double NOT NULL DEFAULT 0,                          -- [oms]
  `cost` double NOT NULL DEFAULT 0,                         -- [oms][oas] ต้นทุนตั้งต้น (ต้นทุนจริงมาจาก cost layer)
  `count_stock` tinyint(1) NOT NULL DEFAULT 1,              -- 0=ไม่นับ 1=นับรวม 2=นับแยกรายชิ้น
  `stockable` tinyint(1) NOT NULL DEFAULT 1,                -- [oas] = (count_stock > 0) เขียนคู่กัน
  `allow_negative` tinyint(1) NOT NULL DEFAULT 0,           -- [oas] ยอมให้ยอดติดลบไหม
  `stock` double NOT NULL DEFAULT 0,                        -- [oms] cache ของยอดรวม (ความจริงอยู่ที่ ledger)
  `is_active` tinyint(1) NOT NULL DEFAULT 1,                -- [กลาง] ชื่อเดียวกับตารางแกน
  `inuse` tinyint(1) DEFAULT NULL,                          -- [oms][oas] คงไว้ เขียนคู่กับ is_active
  `last_update` int(11) unsigned NOT NULL DEFAULT 0,        -- [oms]
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_code` (`product_code`),
  KEY `product_no` (`product_no`),
  KEY `category_id` (`category_id`),
  KEY `model_id` (`model_id`),
  KEY `type_id` (`type_id`),
  KEY `is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- inventory_items — หน่วยย่อยของสินค้า (SKU / เลขครุภัณฑ์ / ซีเรียล)
--
-- ⚠️ inventory/borrow/oms ใช้ product_no เป็น PRIMARY KEY อยู่ ตัวปรับรุ่นต้อง
-- เพิ่ม id แล้วย้าย PK มาที่ id ในคำสั่งเดียว (ไม่ได้ลบข้อมูล แค่ย้ายคีย์) :
--   ALTER TABLE t ADD COLUMN `id` int(11) NOT NULL FIRST;
--   SET @i = 0; UPDATE t SET `id` = (@i := @i + 1) ORDER BY `product_no`;
--   ALTER TABLE t DROP PRIMARY KEY, ADD PRIMARY KEY (`id`),
--                 MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,
--                 ADD UNIQUE KEY `product_no` (`product_no`);
-- ต้องย้าย เพราะ ledger/cost layer อ้างถึงหน่วยย่อยด้วย inventory_item_id
-- และเพราะฐานที่ติดตั้งใหม่กับฐานที่ปรับรุ่นมาต้องได้สคีมาเท่ากันเป๊ะ
-- ---------------------------------------------------------------------------
CREATE TABLE `{prefix}_inventory_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sku` varchar(150) NOT NULL,                              -- [กลาง] oas:sku · oms/inv:product_no
  `product_no` varchar(150) DEFAULT NULL,                   -- [oms][inv] คงไว้ เดิมเป็น PK
  `barcode` varchar(150) DEFAULT NULL,                      -- [oas]
  `inventory_id` int(11) NOT NULL,
  `topic` varchar(150) DEFAULT NULL,                        -- [oms]
  `unit` varchar(50) DEFAULT NULL,
  `price` double DEFAULT NULL,
  `cut_stock` double NOT NULL DEFAULT 1,                    -- จำนวนที่ตัดจากสต๊อกหลักต่อ 1 หน่วยของรายการนี้
  `stock` double NOT NULL DEFAULT 0,                        -- [inv][oas] cache (ความจริงอยู่ที่ ledger)
  `instock` tinyint(1) NOT NULL DEFAULT 0,                  -- [oms]
  `url` varchar(255) DEFAULT NULL,                          -- [oms]
  `last_update` int(11) NOT NULL DEFAULT 0,                 -- [oms]
  PRIMARY KEY (`id`),
  UNIQUE KEY `sku` (`sku`),
  UNIQUE KEY `product_no` (`product_no`),
  KEY `barcode` (`barcode`),
  KEY `inventory_id` (`inventory_id`),
  KEY `instock` (`instock`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- inventory_meta — ข้อมูลเพิ่มเติมของสินค้าแบบ key/value
-- value เป็น mediumtext ตาม oas (ของ oms/inv เป็น text — ขยายได้ ไม่เสียข้อมูล)
-- ---------------------------------------------------------------------------
CREATE TABLE `{prefix}_inventory_meta` (
  `inventory_id` int(11) NOT NULL,
  `name` varchar(20) NOT NULL,
  `value` mediumtext NOT NULL,
  KEY `idx_inventory_meta` (`inventory_id`,`name`),
  KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- inventory_stock — ยอดคงเหลือปัจจุบัน (cache ที่คำนวณจาก ledger)
--
-- ⚠️ ของ oas ไม่มีดัชนี unique เลย ยอดของ SKU เดียวจึงมีได้หลายแถวโดยไม่มีอะไร
-- ฟ้อง ตรงนี้บังคับ UNIQUE (inventory_id, inventory_item_id) และให้สินค้าที่นับ
-- รวม (ไม่มีหน่วยย่อย) ใช้ inventory_item_id = 0 ไม่ใช่ NULL เพราะ NULL ซ้ำกัน
-- ได้ในดัชนี unique ของ MySQL ซึ่งทำให้ข้อบังคับนี้ไม่มีผลกับสินค้ากลุ่มนั้นเลย
-- ---------------------------------------------------------------------------
CREATE TABLE `{prefix}_inventory_stock` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `inventory_id` int(11) NOT NULL,
  `inventory_item_id` int(11) NOT NULL DEFAULT 0,           -- 0 = ยอดระดับสินค้า (count_stock = 1)
  `sku` varchar(150) NOT NULL DEFAULT '',
  `qty` double NOT NULL DEFAULT 0,
  `reserved_qty` double NOT NULL DEFAULT 0,                 -- [oas] จองไว้แล้วแต่ยังไม่ตัด
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_balance` (`inventory_id`,`inventory_item_id`),
  KEY `sku` (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- inventory_stock_movement — สมุดบัญชีเดินสต๊อก (ledger)
--
-- นี่คือ "ความจริง" ของยอดคงเหลือ ทุกการเปลี่ยนยอดต้องผ่านตารางนี้เท่านั้น
-- ผ่าน Posting API จุดเดียว ห้ามโมดูลไหนไปแก้ inventory_stock.qty ตรง ๆ
--
-- movement_direction เป็นเครื่องหมายที่เชื่อถือได้ (in/out) ส่วน movement_type
-- บอกว่าเป็นการเคลื่อนไหวประเภทไหน — ดูรายการที่อนุญาตในหัวข้อ 4.6 ของแผน
-- ---------------------------------------------------------------------------
CREATE TABLE `{prefix}_inventory_stock_movement` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `inventory_id` int(11) NOT NULL,
  `inventory_item_id` int(11) NOT NULL DEFAULT 0,
  `sku` varchar(150) NOT NULL DEFAULT '',
  `movement_direction` enum('in','out') NOT NULL DEFAULT 'in',
  `movement_type` varchar(20) NOT NULL,                     -- ทะเบียนกลาง (4.6)
  `reference_type` varchar(20) DEFAULT NULL,                -- order | borrow | repair | adjustment | opening
  `reference_id` int(11) DEFAULT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `reference_item_id` int(11) DEFAULT NULL,
  `source_movement_id` int(11) DEFAULT NULL,                -- การเคลื่อนไหวที่กลับรายการของแถวนี้
  `quantity` double NOT NULL DEFAULT 0,                     -- เป็นบวกเสมอ ทิศทางอยู่ที่ movement_direction
  `unit_cost` double DEFAULT NULL,
  `total_cost` double DEFAULT NULL,
  `note` text DEFAULT NULL,
  `occurred_at` datetime NOT NULL,                          -- เวลาที่เกิดรายการจริง (ใช้เรียง FIFO)
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_item_time` (`inventory_id`,`inventory_item_id`,`occurred_at`),
  KEY `idx_reference` (`reference_type`,`reference_id`),
  KEY `movement_type` (`movement_type`),
  KEY `sku` (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- inventory_cost_layer / inventory_cost_allocation — ต้นทุนแบบ FIFO
--
-- ⚠️ ทั้งสองตารางนี้ยังไม่เคยเดินจริงที่ไหนเลย (oas = 0 แถวจริง, oms ไม่มีตาราง)
-- จึงเป็นของใหม่ที่ต้องมีชุดทดสอบของตัวเอง ไม่ใช่การย้ายของที่พิสูจน์แล้ว
-- โครงตารางยกจาก oas เพราะรองรับต้นทุนต่อ lot และการคืนของเข้า layer เดิมได้
-- ---------------------------------------------------------------------------
CREATE TABLE `{prefix}_inventory_cost_layer` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `inventory_id` int(11) NOT NULL,
  `inventory_item_id` int(11) NOT NULL DEFAULT 0,
  `sku` varchar(150) NOT NULL DEFAULT '',
  `movement_id` int(11) DEFAULT NULL,                       -- [กลาง] แถวใน ledger ที่ทำให้เกิด layer นี้
  `reference_type` varchar(20) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `reference_item_id` int(11) DEFAULT NULL,
  `source_allocation_id` int(11) DEFAULT NULL,              -- layer ที่เกิดจากการคืนของ
  `received_qty` double NOT NULL DEFAULT 0,
  `remaining_qty` double NOT NULL DEFAULT 0,
  `unit_cost` double NOT NULL DEFAULT 0,
  `currency` varchar(3) NOT NULL DEFAULT 'THB',
  `note` text DEFAULT NULL,
  `received_at` datetime NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fifo` (`inventory_id`,`inventory_item_id`,`received_at`),
  KEY `idx_remaining` (`remaining_qty`),
  KEY `movement_id` (`movement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `{prefix}_inventory_cost_allocation` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `layer_id` int(11) NOT NULL,
  `inventory_id` int(11) NOT NULL,
  `inventory_item_id` int(11) NOT NULL DEFAULT 0,
  `sku` varchar(150) NOT NULL DEFAULT '',
  `movement_id` int(11) DEFAULT NULL,
  `source_allocation_id` int(11) DEFAULT NULL,
  `reference_type` varchar(20) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `reference_item_id` int(11) DEFAULT NULL,
  `quantity` double NOT NULL DEFAULT 0,
  `unit_cost` double NOT NULL DEFAULT 0,
  `total_cost` double NOT NULL DEFAULT 0,
  `note` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `layer_id` (`layer_id`),
  KEY `movement_id` (`movement_id`),
  KEY `idx_reference` (`reference_type`,`reference_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- inventory_template — แม่แบบเอกสาร (ยกมาจาก oms)
--
-- ชนิดเอกสารเป็น "ข้อมูล" ไม่ใช่ "โค้ด" — แต่ละผลิตภัณฑ์จึงมีชุดของตัวเองได้
-- โดยไม่ต้องแก้โมดูล ตรงนี้เพิ่ม document_type เป็นชื่อกลาง (เดิม oms ใช้ status)
-- ---------------------------------------------------------------------------
CREATE TABLE `{prefix}_inventory_template` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `document_type` varchar(10) DEFAULT NULL,                 -- [กลาง] oms:status
  `status` varchar(3) DEFAULT NULL,                         -- [oms] คงไว้ เขียนคู่กัน
  `mode` enum('sell','buy','borrow','repair') NOT NULL,     -- [กลาง] ขยายจาก oms (sell/buy)
  `topic` varchar(150) DEFAULT NULL,
  `comment` text NOT NULL,
  `due_date` tinyint(1) NOT NULL DEFAULT 0,
  `signature_1` varchar(150) DEFAULT NULL,
  `signature_2` varchar(150) DEFAULT NULL,
  `signature_3` varchar(150) DEFAULT NULL,
  `published` tinyint(1) NOT NULL DEFAULT 1,
  `in_stock` tinyint(1) NOT NULL,                           -- 1 = เอกสารนี้รับเข้าสต๊อก
  `cut_stock` tinyint(1) NOT NULL,                          -- 1 = เอกสารนี้ตัดออกจากสต๊อก
  `reserve_stock` tinyint(1) NOT NULL DEFAULT 0,            -- 1 = เอกสารนี้ "จอง" ของไว้ ยังไม่ตัด (ใบสั่งขาย)
  `movement_type` varchar(20) DEFAULT NULL,                 -- [กลาง] ชนิดการเคลื่อนไหวที่เอกสารนี้สร้าง (4.6)
  `prefix` varchar(20) NOT NULL DEFAULT '',                 -- คำนำหน้าเลขที่เอกสาร รับ token %Y %M (เดิมอยู่ใน config เป็น <ชนิด>_prefix)
  `number_format` varchar(20) NOT NULL DEFAULT '',          -- รูปแบบเลขรัน (ว่าง = %04d · เดิมอยู่ใน config เป็น <ชนิด>_NO)
  `columns` varchar(255) NOT NULL DEFAULT '',               -- คอลัมน์ของตารางรายการตอนพิมพ์ คั่นด้วย , (ว่าง = ชุดมาตรฐาน)
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_type` (`document_type`),
  KEY `status` (`status`),
  KEY `mode` (`mode`,`published`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- customer — ลูกค้า/คู่ค้า (คนละตารางกับ user ซึ่งเป็นผู้ใช้ระบบ)
--
-- ฐานเป็นของ oms (มีข้อมูลจริง 83 ราย) แล้วเติมคอลัมน์ของ oas ที่ oms ไม่มี
-- ชื่อที่ชนกันเลือกตามแกน : provinceID (เหมือน user) ไม่ใช่ province_id ของ oas
-- ---------------------------------------------------------------------------
CREATE TABLE `{prefix}_customer` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_no` varchar(20) DEFAULT NULL,                   -- [oms] oas:code
  `code` varchar(50) DEFAULT NULL,                          -- [oas] คงไว้ เขียนคู่กัน
  `type` varchar(20) DEFAULT NULL,                          -- [oas]
  `is_customer` tinyint(1) NOT NULL DEFAULT 1,              -- [oas] เป็นลูกค้า
  `is_supplier` tinyint(1) NOT NULL DEFAULT 0,              -- [oas] เป็นผู้ขาย
  `is_active` tinyint(1) NOT NULL DEFAULT 1,                -- [oas]
  `company` varchar(64) NOT NULL DEFAULT '',
  `branch` varchar(50) NOT NULL DEFAULT '',
  `name` varchar(50) NOT NULL DEFAULT '',
  `contact` varchar(150) DEFAULT NULL,                      -- [oas] ผู้ติดต่อ
  `idcard` varchar(13) NOT NULL DEFAULT '',
  `tax_id` varchar(13) NOT NULL DEFAULT '',
  `phone` varchar(20) NOT NULL DEFAULT '',
  `fax` varchar(20) NOT NULL DEFAULT '',
  `email` varchar(255) NOT NULL DEFAULT '',
  `line_id` varchar(50) DEFAULT NULL,                       -- [oas]
  `line_name` varchar(100) DEFAULT NULL,                    -- [oas]
  `address` varchar(255) NOT NULL DEFAULT '',
  `provinceID` smallint(3) unsigned NOT NULL DEFAULT 0,     -- ชื่อเดียวกับตาราง user ของแกน
  `province` varchar(64) NOT NULL DEFAULT '',
  `zipcode` varchar(5) NOT NULL DEFAULT '',
  `country` varchar(2) NOT NULL DEFAULT 'TH',
  `website` varchar(150) NOT NULL DEFAULT '',
  `bank` varchar(100) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `bank_no` varchar(20) DEFAULT NULL,
  `bank_branch` varchar(100) DEFAULT NULL,                  -- [oas]
  `price_group` varchar(20) DEFAULT NULL,                   -- [oas]
  `payment_type` varchar(20) DEFAULT NULL,                  -- [oas]
  `payment_terms` int(11) DEFAULT NULL,                     -- [oas]
  `discount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `expense_due_date` int(11) NOT NULL DEFAULT 0,
  `invoice_due_date` int(11) NOT NULL DEFAULT 0,
  `note` text DEFAULT NULL,                                 -- [oas]
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `customer_no` (`customer_no`),
  KEY `code` (`code`),
  KEY `company` (`company`),
  KEY `name` (`name`),
  KEY `tax_id` (`tax_id`),
  KEY `phone` (`phone`),
  KEY `email` (`email`),
  KEY `idx_partner_roles` (`is_active`,`is_customer`,`is_supplier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- orders — หัวเอกสาร
--
-- ชื่อตารางใช้ orders (พหูพจน์) ตาม oms ไม่ใช่ `order` ของ oas ด้วยสองเหตุผล
--   1. order เป็นคำสงวนของ SQL ต้องใส่ backquote ทุกครั้งที่เอ่ยถึงตลอดไป
--   2. ข้อมูลจริงชุดเดียวที่มีคือของ oms (246 ใบ ต่อเนื่อง 8 ปี) การเปลี่ยนชื่อ
--      ฝั่ง oas กระทบแค่ข้อมูลทดสอบ 16 ใบในระบบที่ยังไม่เผยแพร่
--
-- ชื่อคอลัมน์ยึดของ oms เป็นหลัก (ข้อมูลจริงอยู่ที่นั่น) แล้วเติมของ oas เฉพาะ
-- ที่ oms ไม่มีจริง ๆ — snapshot ลูกค้า, สายเอกสาร, สถานะเอกสาร/การชำระเงิน
-- ---------------------------------------------------------------------------
CREATE TABLE `{prefix}_orders` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `order_no` varchar(50) NOT NULL,
  `document_type` varchar(10) NOT NULL DEFAULT '',          -- [กลาง] oms:status · oas:document_type
  `status` varchar(3) DEFAULT NULL,                         -- [oms] คงไว้ เขียนคู่กัน
  `document_status` enum('draft','issued','cancelled') NOT NULL DEFAULT 'issued', -- [oas]
  `payment_status` varchar(20) NOT NULL DEFAULT '',         -- [oas]
  -- สายเอกสาร : เอกสารนี้แปลงมาจากใบไหน และต้นสายคือใบไหน
  `source_document_id` int(11) DEFAULT NULL,                -- [oas]
  `root_document_id` int(11) DEFAULT NULL,                  -- [oas]
  `reference_document_no` varchar(50) DEFAULT NULL,         -- [oas]
  `order` varchar(32) DEFAULT NULL,                         -- [oms] กุญแจสายเอกสาร/ลิงก์สาธารณะ คงไว้
  `public_key` varchar(32) DEFAULT NULL,                    -- [กลาง] ชื่อใหม่ของ `order` เขียนคู่กัน
  -- ลูกค้า : id ไว้เชื่อม + snapshot ไว้พิมพ์ (ที่อยู่ ณ วันออกเอกสาร)
  `customer_id` int(11) unsigned DEFAULT NULL,
  `customer_name` varchar(150) DEFAULT NULL,                -- [oas] snapshot
  `customer_company` varchar(150) DEFAULT NULL,             -- [oas]
  `customer_contact` varchar(150) DEFAULT NULL,             -- [oas]
  `customer_phone` varchar(20) DEFAULT NULL,                -- [oas]
  `customer_email` varchar(255) DEFAULT NULL,               -- [oas]
  `customer_tax_id` varchar(13) DEFAULT NULL,               -- [oas]
  `customer_address` text DEFAULT NULL,                     -- [oas]
  `customer_province` varchar(64) DEFAULT NULL,             -- [oas]
  `customer_zipcode` varchar(5) DEFAULT NULL,               -- [oas]
  `member_id` int(11) unsigned DEFAULT NULL,
  `sales_channel` varchar(20) DEFAULT NULL,                 -- ใบนี้เกิดจากทางไหน : office | pos | web
  -- ยอดเงิน — ชื่อของ oms เป็นหลัก
  `subtotal` double NOT NULL DEFAULT 0,                     -- [oas] oms คำนวณสด ตอนนี้เก็บไว้เลย
  `discount` decimal(10,2) DEFAULT NULL,                    -- [oms] ส่วนลดท้ายบิลเป็นจำนวนเงิน
  `discount_percent` decimal(10,2) NOT NULL DEFAULT 0.00,   -- [oms]
  `vat` decimal(10,2) NOT NULL DEFAULT 0.00,                -- [oms] ยอดภาษีมูลค่าเพิ่ม
  `vat_status` tinyint(1) unsigned NOT NULL DEFAULT 0,      -- [oms] 1=ยังไม่รวม 2=รวมแล้ว
  `tax` decimal(10,2) NOT NULL DEFAULT 0.00,                -- [oms] ภาษีหัก ณ ที่จ่าย
  `tax_status` decimal(10,2) NOT NULL DEFAULT 0.00,         -- [oms] อัตราหัก ณ ที่จ่าย (%)
  `shipping_cost` double NOT NULL DEFAULT 0,                -- [oas]
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,              -- [oms]
  `paid` decimal(10,2) NOT NULL DEFAULT 0.00,               -- [oms] oas:paid_amount
  `change_amount` double NOT NULL DEFAULT 0,                -- [oas] เงินทอน (POS)
  `currency` varchar(3) NOT NULL DEFAULT 'THB',             -- [oas]
  -- วันที่และการชำระเงิน
  `order_date` datetime NOT NULL,                           -- [oms] oas:issued_at
  `due_date` date DEFAULT NULL,
  `payment_date` datetime DEFAULT NULL,                     -- [oms] oas:paid_at
  `payment_method` varchar(255) DEFAULT NULL,
  `payment_ref` varchar(100) DEFAULT NULL,                  -- [oas]
  `completed_at` datetime DEFAULT NULL,                     -- [oas]
  `cancelled_at` datetime DEFAULT NULL,                     -- [oas]
  `comment` text DEFAULT NULL,                              -- [oms] หมายเหตุที่พิมพ์ลงเอกสาร
  `remark` text DEFAULT NULL,                               -- [oms]
  `internal_note` text DEFAULT NULL,                        -- [oas] ไม่พิมพ์ลงเอกสาร
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_no` (`order_no`),
  KEY `customer_id` (`customer_id`),
  KEY `member_id` (`member_id`),
  KEY `idx_type_date` (`document_type`,`order_date`),
  KEY `status_date` (`status`,`order_date`),
  KEY `order` (`order`),
  KEY `public_key` (`public_key`),
  KEY `source_document_id` (`source_document_id`),
  KEY `root_document_id` (`root_document_id`),
  KEY `sales_channel` (`sales_channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- order_items — บรรทัดรายการของเอกสาร
--
-- ⚠️ ตารางนี้ "ไม่ใช่" การเดินสต๊อก — บรรทัดในใบเสนอราคาไม่ได้ตัดสต๊อกอะไรเลย
-- ของเดิมของ oms รวมสองเรื่องไว้ในตาราง stock ตารางเดียวแล้วใช้ธง cut_stock
-- แยกเอา ซึ่งทำให้ "ยอดคงเหลือ" กับ "รายการในเอกสาร" แก้กันไปมาไม่ได้
--
-- ตาราง stock เดิมของ oms คงไว้ทั้ง 323 แถว ไม่ลบ — Phase 4 คัดลอกเป็น
-- order_items เพื่อให้เอกสารเก่าพิมพ์ได้เหมือนเดิม แล้วเริ่มเดิน ledger จากศูนย์
-- ---------------------------------------------------------------------------
CREATE TABLE `{prefix}_order_items` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `source_item_id` int(11) DEFAULT NULL,                    -- [oas] บรรทัดต้นทางที่แปลงมา
  `root_item_id` int(11) DEFAULT NULL,                      -- [oas]
  `inventory_id` int(11) unsigned NOT NULL DEFAULT 0,       -- [oms] oas:product_id
  `inventory_item_id` int(11) DEFAULT NULL,                 -- [oas] หน่วยย่อยที่อ้างถึง
  `product_code` varchar(150) DEFAULT NULL,                 -- [oas]
  `product_no` varchar(50) DEFAULT NULL,                    -- [oms] คงไว้
  `topic` varchar(255) DEFAULT NULL,                        -- [oms] oas:name — ชื่อสินค้า ณ วันออกเอกสาร
  `quantity` double NOT NULL DEFAULT 1,                     -- [oms int → double] รองรับทศนิยม
  `unit` varchar(50) DEFAULT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,              -- [oms] oas:unit_price
  `cost_price` double DEFAULT NULL,                         -- [oas] ต้นทุน ณ วันขาย (มาจาก FIFO)
  `discount` decimal(10,2) NOT NULL DEFAULT 0.00,           -- [oms] เป็นเปอร์เซ็นต์รายบรรทัด
  `vat` decimal(10,2) NOT NULL DEFAULT 0.00,                -- [oms] > 0 = บรรทัดนี้คิด VAT
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `cut_stock` double NOT NULL DEFAULT 0,                    -- [oms] จำนวนที่ตัดจากสต๊อกหลัก
  `note` text DEFAULT NULL,                                 -- [oas]
  `member_id` int(11) unsigned DEFAULT NULL,                -- [oms]
  `create_date` datetime DEFAULT NULL,                      -- [oms]
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `inventory_id` (`inventory_id`),
  KEY `inventory_item_id` (`inventory_item_id`),
  KEY `product_no` (`product_no`),
  KEY `product_code` (`product_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- stock — บรรทัดเอกสารของระบบรุ่นก่อน (oms) · ไม่มีโค้ดของโมดูลอ่าน/เขียนแล้ว
--
-- ⚠️ **ห้ามลบ** ไซต์ที่ย้ายมาจาก oms มีข้อมูลจริง 323 แถวอยู่ในนี้ และแผนข้อ 4.2
-- กำหนดให้คงไว้ทั้งหมด — install/upgrade.php ข้าง ๆ คัดลอกออกไปที่ order_items
-- (คัดลอก ไม่ได้ย้าย) ตารางนี้จึงยังเป็นหลักฐานของข้อมูลเดิมที่ตรวจย้อนได้
--
-- ที่ยังต้องสร้างบนไซต์ติดตั้งใหม่ด้วย เพราะฐานที่ติดตั้งใหม่กับฐานที่ปรับรุ่นมา
-- ต้องได้สคีมาเท่ากันเป๊ะ (cli-verify --fresh) และชุดทดสอบ F2-F7 ของ
-- install/testdb-module.php ใส่บรรทัดเอกสารแบบเดิมลงตารางนี้
-- ---------------------------------------------------------------------------
CREATE TABLE `{prefix}_stock` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `member_id` int(11) unsigned NOT NULL,
  `inventory_id` int(11) unsigned NOT NULL,
  `product_no` varchar(50) NOT NULL,
  `status` varchar(3) DEFAULT NULL,
  `create_date` datetime NOT NULL,
  `topic` varchar(150) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `vat` decimal(10,2) NOT NULL,
  `discount` decimal(10,2) NOT NULL,
  `total` decimal(10,2) NOT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `cut_stock` double NOT NULL DEFAULT 0,
  `used` double NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `inventory_id` (`inventory_id`,`status`),
  KEY `product_no` (`product_no`),
  KEY `fifo` (`inventory_id`,`status`,`create_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- หน่วยนับตั้งต้น — unit เป็นหมวดหมู่ที่มีแค่โมดูล inventory ใช้
-- (department เป็นหมวดหมู่ของระบบกลาง อยู่ใน install/database.sql ของโปรเจ็ค)
-- ---------------------------------------------------------------------------
INSERT INTO `{prefix}_category` (`type`, `category_id`, `topic`, `color`, `is_active`) VALUES
('unit', '1', 'ชิ้น', NULL, 1),
('unit', '2', 'อัน', NULL, 1),
('unit', '3', 'งาน', NULL, 1);

-- ---------------------------------------------------------------------------
-- ข้อมูลอ้างอิงที่ระบบต้องมี — แม่แบบเอกสารตั้งต้น
--
-- ถ้าไม่มีแถวเหล่านี้ โมดูลสร้างเอกสารไม่ได้เลยสักใบ และเมนูจะว่างเปล่า
-- รหัสและพฤติกรรมตรงกับทะเบียนกลาง (หัวข้อ 4.6 ของแผน)
-- แต่ละไซต์แก้/เพิ่ม/ปิดได้เองจากหน้าแม่แบบเอกสาร โดยไม่ต้องแก้โค้ด
--
-- in_stock = 1 รับของเข้าสต๊อก · cut_stock = 1 ตัดของออก · ว่างทั้งคู่ = ไม่แตะสต๊อก
-- ---------------------------------------------------------------------------
INSERT INTO `{prefix}_inventory_template`
  (`document_type`, `status`, `mode`, `topic`, `comment`, `due_date`, `published`, `in_stock`, `cut_stock`, `reserve_stock`, `movement_type`, `prefix`, `number_format`, `columns`) VALUES
('PO', 'PO', 'buy', 'ใบสั่งซื้อ', '', 0, 1, 0, 0, 0, NULL, 'PO%Y%M-', '%04d', 'item,topic,quantity,price,discount,amount'),
('IN', 'IN', 'buy', 'ใบรับสินค้า', '', 1, 1, 1, 0, 0, 'purchase', 'IN%Y%M-', '%04d', 'item,topic,quantity,price,discount,amount'),
('RET', 'RET', 'buy', 'ใบคืนสินค้า', '', 0, 1, 1, 0, 0, 'return_in', 'RET%Y%M-', '%04d', 'item,topic,quantity,price,discount,amount'),
('QUO', 'QUO', 'sell', 'ใบเสนอราคา', '', 1, 1, 0, 0, 0, NULL, 'QUO%Y%M-', '%04d', 'item,topic,quantity,price,discount,amount'),
('OUT', 'OUT', 'sell', 'ใบเสร็จรับเงิน', '', 0, 1, 0, 1, 0, 'sale', 'OUT%Y%M-', '%04d', 'item,topic,quantity,price,discount,amount');