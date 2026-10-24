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
--      ยกเว้นคอลัมน์ที่เป็นสำเนาของชื่อกลางล้วน ๆ และไม่มีใครอ่านแล้ว ตัวปรับรุ่น
--      ย้ายค่าเข้าชื่อกลางแล้วลบทิ้ง (inventory.product_no → product_code)
--   3. ตารางที่ระบบไหนไม่ใช้ ก็ยังสร้าง (ว่างเปล่า ไม่กินอะไร) เพื่อให้โมดูล
--      ชุดเดียวทำงานได้ทุกที่โดยไม่ต้องมีเงื่อนไข "ระบบนี้มีตารางนั้นไหม"
--
-- ที่มาของแต่ละคอลัมน์กำกับไว้ท้ายบรรทัด :
--   [oms] [oas] [inv] = inventory/borrow  [กลาง] = ของใหม่ที่ตกลงกันใน Phase 1.2
-- ---------------------------------------------------------------------------

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

-- ---------------------------------------------------------------------------
-- ทะเบียนสินค้า — สี่ชั้น (modules/inventory/DESIGN.md ข้อ 2–3)
--
--   inventory_product   สินค้า (หน้าสินค้าในร้านออนไลน์)          product_code
--     └ inventory       variant = กองสต๊อก (สิ่งที่นับ ขาย รายงาน)  sku
--         └ inventory_items  หน่วยขาย (barcode) หรือ ชิ้น (serial_no)
--         └ inventory_cost_layer  ล็อตที่รับเข้า (lot_no · expiry_date)
--
-- สินค้าธรรมดามี variant เดียวที่ไม่มีตัวเลือก — ledger ชั้นต้นทุน เอกสาร
-- อ้าง inventory_id (= variant) ทุกที่ จึงไม่ต้องรู้ว่าสินค้ามีตัวเลือกหรือไม่
--
-- จำนวน decimal(16,4) · เงิน decimal(14,4) (D3 ของ oms.in.th) — คำสั่งคำนวณใหม่
-- ต้องได้ค่าเดิมเป๊ะ double บวกกันหลายพันแถวแล้วเศษคลาด
-- ---------------------------------------------------------------------------

-- ---------------------------------------------------------------------------
-- inventory_product — สินค้า
--
-- count_stock และ unit (หน่วยฐาน) เป็นของสินค้า ทุก variant ใช้ร่วมกัน และ
-- เปลี่ยนไม่ได้เมื่อมีรายการในสมุดบัญชีแล้ว — ยอดเดิมนับด้วยกติกาเดิม/หน่วยเดิม
-- ---------------------------------------------------------------------------
CREATE TABLE `{prefix}_inventory_product` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `product_code` varchar(150) NOT NULL,                     -- รหัสสินค้า/รุ่น · URL ร้านออนไลน์
  `topic` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `category_id` varchar(10) DEFAULT NULL,                   -- ตารางแกน category
  `model_id` varchar(10) DEFAULT NULL,                      -- ยี่ห้อ/รุ่น (category type=model_id)
  `type_id` varchar(10) DEFAULT NULL,                       -- ประเภท (category type=type_id)
  `count_stock` tinyint(1) NOT NULL DEFAULT 1,              -- 0 ไม่นับ (บริการ) · 1 นับจำนวน · 2 นับรายชิ้น
  `unit` varchar(50) NOT NULL DEFAULT '',                   -- หน่วยฐาน = หน่วยเล็กสุดที่ขาย/นับ
  `vat` tinyint(1) NOT NULL DEFAULT 0,                      -- วิธีคิด VAT ของราคาขาย
  `allow_negative` tinyint(1) NOT NULL DEFAULT 0,
  `option1_name` varchar(50) DEFAULT NULL,                  -- ชื่อตัวเลือก เช่น สี
  `option2_name` varchar(50) DEFAULT NULL,                  -- เช่น ไซซ์
  `option3_name` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `published` tinyint(1) NOT NULL DEFAULT 0,               -- แสดงในร้านออนไลน์
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_code` (`product_code`),
  KEY `category_id` (`category_id`),
  KEY `model_id` (`model_id`),
  KEY `type_id` (`type_id`),
  KEY `is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- inventory — variant = กองสต๊อก
--
-- topic · cost · stock เป็น cache (คำนวณใหม่ได้จากข้อมูลดิบ) อ่านตารางเดียว
-- ได้ว่าขายอะไร ราคาเท่าไร ทุนเท่าไร เหลือเท่าไร — ให้ POS / ร้านออนไลน์อ่านเร็ว
--   topic = ชื่อสินค้า + ค่าตัวเลือก
--   cost  = ทุนต่อหน่วยฐานของชั้นที่จะถูกตัดถัดไป (ไม่มีของ = ชั้นที่รับล่าสุด) แสดงผลเท่านั้น
--   stock = คงเหลือเป็นหน่วยฐาน รวมทุกคลัง
-- ---------------------------------------------------------------------------
CREATE TABLE `{prefix}_inventory` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(11) unsigned NOT NULL,
  `sku` varchar(150) NOT NULL,
  `option1` varchar(100) NOT NULL DEFAULT '',               -- ค่าตัวเลือก เช่น แดง
  `option2` varchar(100) NOT NULL DEFAULT '',               -- เช่น S
  `option3` varchar(100) NOT NULL DEFAULT '',
  `topic` varchar(255) NOT NULL DEFAULT '',                 -- cache
  `price` decimal(14,4) NOT NULL DEFAULT 0.0000,            -- ราคาขายต่อ 1 หน่วยฐาน
  `cost` decimal(14,4) NOT NULL DEFAULT 0.0000,             -- cache
  `stock` decimal(16,4) NOT NULL DEFAULT 0.0000,            -- cache
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sku` (`sku`),
  -- ค่าตัวเลือกว่างเป็น '' ไม่ใช่ NULL — NULL ซ้ำกันได้ในดัชนี UNIQUE ของ MySQL
  UNIQUE KEY `options` (`product_id`,`option1`,`option2`,`option3`),
  KEY `is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- inventory_items — ทุกอย่างที่ยิงได้ของ variant หนึ่งตัว
--
--   หน่วยขาย : barcode (ห้ามว่าง) · serial_no NULL · cut_stock = หน่วยฐานต่อการยิง 1 ครั้ง
--             เช่น ไข่ ฟอง ×1 · โหล ×12 · แผง ×30 — ไม่มียอดของตัวเอง ของอยู่กองเดียวที่ variant
--   ชิ้น     : serial_no (ห้ามว่าง) · barcode NULL · cut_stock = 1 · stock 1 อยู่ในคลัง / 0 ไม่อยู่
--             มีได้เฉพาะสินค้านับรายชิ้น (count_stock = 2)
--
-- แถวที่ถูกอ้างในเอกสารหรือสมุดบัญชีแล้ว ห้ามลบ ห้ามเปลี่ยนรหัส/ตัวคูณ — ปิดด้วย
-- is_active = 0 (บรรทัดเอกสารเก่าต้องอ่านความหมายเดิมได้เสมอ)
-- ---------------------------------------------------------------------------
CREATE TABLE `{prefix}_inventory_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `inventory_id` int(11) unsigned NOT NULL,                 -- variant
  `barcode` varchar(150) DEFAULT NULL,                      -- หน่วยขาย
  `serial_no` varchar(150) DEFAULT NULL,                    -- ชิ้น
  `unit` varchar(50) NOT NULL DEFAULT '',                   -- ชื่อหน่วยขาย (แถวชิ้นใช้หน่วยฐาน)
  `cut_stock` decimal(16,4) NOT NULL DEFAULT 1.0000,
  `price` decimal(14,4) DEFAULT NULL,                       -- NULL = variant.price × cut_stock
  `stock` decimal(16,4) NOT NULL DEFAULT 0.0000,            -- cache · เฉพาะแถวชิ้น
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `barcode` (`barcode`),
  UNIQUE KEY `serial_no` (`serial_no`),
  KEY `inventory_id` (`inventory_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- inventory_meta — ข้อมูลเพิ่มเติมของสินค้าแบบ key/value (ผูกกับสินค้า ไม่ใช่ variant)
-- ---------------------------------------------------------------------------
CREATE TABLE `{prefix}_inventory_meta` (
  `product_id` int(11) unsigned NOT NULL,
  `name` varchar(20) NOT NULL,
  `value` mediumtext NOT NULL,
  KEY `idx_product_meta` (`product_id`,`name`),
  KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- inventory_warehouse — คลังเก็บของ
--
-- ⚠️ ยอดคงเหลือ · สมุดบัญชี · ชั้นต้นทุน ทุกแถวผูกกับคลังเสมอ ไม่มีแถวไหน
-- "ไม่มีคลัง" ได้ — ของต้องอยู่ที่ไหนสักที่จริง ๆ ถ้ายอมให้ว่างได้ วันหนึ่งจะมี
-- ของกองหนึ่งที่ไม่มีใครรู้ว่าอยู่คลังไหน แล้วยอดรวมกับยอดรายคลังจะไม่ตรงกัน
--
-- ไซต์ที่มีคลังเดียวไม่ต้องรู้เรื่องนี้เลย — ตัวติดตั้งสร้าง "คลังหลัก" ให้
-- และทุกอย่างลงคลังนั้นเองโดยอัตโนมัติ
-- ---------------------------------------------------------------------------
CREATE TABLE `{prefix}_inventory_warehouse` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,               -- คลังที่ใช้เมื่อไม่ได้ระบุ
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_active_sort` (`is_active`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ไซต์ที่ติดตั้งใหม่ต้องมีคลังอย่างน้อยหนึ่งแห่งตั้งแต่วินาทีแรก ไม่งั้นบันทึก
-- การเคลื่อนไหวอะไรไม่ได้เลย
INSERT INTO `{prefix}_inventory_warehouse` (`code`, `name`, `description`, `is_default`, `is_active`, `sort_order`) VALUES
('MAIN', 'คลังหลัก', 'คลังตั้งต้นของระบบ', 1, 1, 1);

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
  `warehouse_id` int(11) NOT NULL DEFAULT 0,                -- ของกองนี้อยู่คลังไหน
  `sku` varchar(150) NOT NULL DEFAULT '',
  `qty` decimal(16,4) NOT NULL DEFAULT 0.0000,
  `reserved_qty` decimal(16,4) NOT NULL DEFAULT 0.0000,     -- [oas] จองไว้แล้วแต่ยังไม่ตัด
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  -- ⚠️ คลังต้องอยู่ในกุญแจด้วย ไม่งั้นของสินค้าเดียวกันในสองคลังจะชนกันเป็นแถวเดียว
  -- แล้วยอดของคลังหลังจะเขียนทับยอดของคลังแรกทิ้งไปเงียบ ๆ
  UNIQUE KEY `idx_balance` (`inventory_id`,`inventory_item_id`,`warehouse_id`),
  KEY `warehouse_id` (`warehouse_id`),
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
  `warehouse_id` int(11) NOT NULL DEFAULT 0,                -- รายการนี้เกิดที่คลังไหน
  `sku` varchar(150) NOT NULL DEFAULT '',
  `movement_direction` enum('in','out') NOT NULL DEFAULT 'in',
  `movement_type` varchar(20) NOT NULL,                     -- ทะเบียนกลาง (4.6)
  `reference_type` varchar(20) DEFAULT NULL,                -- order | borrow | repair | adjustment | opening
  `reference_id` int(11) DEFAULT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `reference_item_id` int(11) DEFAULT NULL,
  `source_movement_id` int(11) DEFAULT NULL,                -- การเคลื่อนไหวที่กลับรายการของแถวนี้
  `quantity` decimal(16,4) NOT NULL DEFAULT 0.0000,         -- เป็นบวกเสมอ ทิศทางอยู่ที่ movement_direction
  `unit_cost` decimal(14,4) DEFAULT NULL,
  `total_cost` decimal(14,4) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `occurred_at` datetime NOT NULL,                          -- เวลาที่เกิดรายการจริง (ใช้เรียง FIFO)
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_item_time` (`inventory_id`,`inventory_item_id`,`occurred_at`),
  KEY `idx_reference` (`reference_type`,`reference_id`),
  KEY `movement_type` (`movement_type`),
  KEY `warehouse_id` (`warehouse_id`),
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
  `warehouse_id` int(11) NOT NULL DEFAULT 0,                -- ของชั้นนี้อยู่คลังไหน
  `sku` varchar(150) NOT NULL DEFAULT '',
  `movement_id` int(11) DEFAULT NULL,                       -- [กลาง] แถวใน ledger ที่ทำให้เกิด layer นี้
  `reference_type` varchar(20) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `reference_item_id` int(11) DEFAULT NULL,
  `source_allocation_id` int(11) DEFAULT NULL,              -- layer ที่เกิดจากการคืนของ
  `received_qty` decimal(16,4) NOT NULL DEFAULT 0.0000,
  `remaining_qty` decimal(16,4) NOT NULL DEFAULT 0.0000,
  `unit_cost` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `currency` varchar(3) NOT NULL DEFAULT 'THB',
  `lot_no` varchar(50) DEFAULT NULL,                        -- ล็อตที่รับเข้า (ว่างได้)
  `expiry_date` date DEFAULT NULL,                          -- วันหมดอายุ (ว่างได้) · ใช้เรียงเมื่อตัดแบบ FEFO
  `note` text DEFAULT NULL,
  `received_at` datetime NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  -- ⚠️ ลำดับ FIFO ต้องแยกตามคลัง — ของที่ซื้อเข้าคลัง ก ไม่ควรถูกตัดตอนขายจากคลัง ข
  KEY `idx_fifo` (`inventory_id`,`inventory_item_id`,`warehouse_id`,`received_at`),
  KEY `idx_fefo` (`inventory_id`,`inventory_item_id`,`warehouse_id`,`expiry_date`),
  KEY `idx_remaining` (`remaining_qty`),
  KEY `movement_id` (`movement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `{prefix}_inventory_cost_allocation` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `layer_id` int(11) NOT NULL,
  `inventory_id` int(11) NOT NULL,
  `inventory_item_id` int(11) NOT NULL DEFAULT 0,
  `warehouse_id` int(11) NOT NULL DEFAULT 0,
  `sku` varchar(150) NOT NULL DEFAULT '',
  `movement_id` int(11) DEFAULT NULL,
  `source_allocation_id` int(11) DEFAULT NULL,
  `reference_type` varchar(20) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `reference_item_id` int(11) DEFAULT NULL,
  `quantity` decimal(16,4) NOT NULL DEFAULT 0.0000,
  `unit_cost` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `total_cost` decimal(14,4) NOT NULL DEFAULT 0.0000,
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
  `stock_in` tinyint(1) NOT NULL DEFAULT 0,                 -- 1 = เอกสารนี้รับเข้าสต๊อก
  `stock_out` tinyint(1) NOT NULL DEFAULT 0,                -- 1 = เอกสารนี้ตัดออกจากสต๊อก
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
  `warehouse_id` int(11) DEFAULT NULL,                      -- เอกสารนี้รับเข้า/ตัดออกจากคลังไหน
  `sales_channel` varchar(20) DEFAULT NULL,                 -- ใบนี้เกิดจากทางไหน : office | pos | web
  -- ยอดเงิน — ชื่อของ oms เป็นหลัก
  `subtotal` decimal(14,4) NOT NULL DEFAULT 0.0000,         -- [oas] oms คำนวณสด ตอนนี้เก็บไว้เลย
  `discount` decimal(14,4) DEFAULT NULL,                    -- [oms] ส่วนลดท้ายบิลเป็นจำนวนเงิน
  `discount_percent` decimal(10,2) NOT NULL DEFAULT 0.00,   -- [oms]
  `vat` decimal(14,4) NOT NULL DEFAULT 0.0000,              -- [oms] ยอดภาษีมูลค่าเพิ่ม
  `vat_status` tinyint(1) unsigned NOT NULL DEFAULT 0,      -- [oms] 1=ยังไม่รวม 2=รวมแล้ว
  `tax` decimal(14,4) NOT NULL DEFAULT 0.0000,              -- [oms] ภาษีหัก ณ ที่จ่าย
  `tax_status` decimal(10,2) NOT NULL DEFAULT 0.00,         -- [oms] อัตราหัก ณ ที่จ่าย (%)
  `shipping_cost` decimal(14,4) NOT NULL DEFAULT 0.0000,    -- [oas]
  `total` decimal(14,4) NOT NULL DEFAULT 0.0000,            -- [oms]
  `paid` decimal(14,4) NOT NULL DEFAULT 0.0000,             -- [oms] oas:paid_amount
  `change_amount` decimal(14,4) NOT NULL DEFAULT 0.0000,    -- [oas] เงินทอน (POS)
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
  KEY `warehouse_id` (`warehouse_id`),
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
  `inventory_id` int(11) unsigned NOT NULL DEFAULT 0,       -- variant (0 = บรรทัดที่ไม่ใช่สินค้า)
  `inventory_item_id` int(11) DEFAULT NULL,                 -- แถวที่ยิงจริง : หน่วยขาย หรือ ชิ้น (DESIGN.md ข้อ 3.6)
  `sku` varchar(150) NOT NULL DEFAULT '',                   -- sku ของ variant ณ วันออกเอกสาร
  `code` varchar(150) NOT NULL DEFAULT '',                  -- รหัสที่ยิงจริง : barcode หรือ serial_no
  `lot_no` varchar(50) DEFAULT NULL,                        -- ขาเข้า = ล็อตที่รับ · ขาออก = ตัดจากล็อตนี้ (ว่าง = ตามวิธีตัด)
  `expiry_date` date DEFAULT NULL,                          -- วันหมดอายุของล็อตที่รับเข้า
  `topic` varchar(255) DEFAULT NULL,                        -- ชื่อสินค้า ณ วันออกเอกสาร
  `quantity` decimal(16,4) NOT NULL DEFAULT 1.0000,         -- จำนวน "หน่วยขาย"
  `unit` varchar(50) DEFAULT NULL,                          -- ชื่อหน่วยขาย ณ วันออกเอกสาร
  `unit_factor` decimal(16,4) NOT NULL DEFAULT 1.0000,      -- หน่วยฐานต่อ 1 หน่วยขาย ณ วันออกเอกสาร
  `base_qty` decimal(16,4) NOT NULL DEFAULT 0.0000,         -- quantity × unit_factor = หน่วยฐานที่รับ/ตัด/จอง
  `price` decimal(14,4) NOT NULL DEFAULT 0.0000,            -- ราคาต่อหน่วยขาย
  `cost_price` decimal(14,4) DEFAULT NULL,                  -- cache : ทุนรวมของบรรทัดจากสมุดบัญชี (ขาออก = Σ allocation)
  `discount` decimal(10,2) NOT NULL DEFAULT 0.00,           -- [oms] เป็นเปอร์เซ็นต์รายบรรทัด
  `vat` decimal(10,2) NOT NULL DEFAULT 0.00,                -- [oms] > 0 = บรรทัดนี้คิด VAT
  `total` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `note` text DEFAULT NULL,                                 -- [oas]
  `member_id` int(11) unsigned DEFAULT NULL,                -- [oms]
  `create_date` datetime DEFAULT NULL,                      -- [oms]
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `source_item_id` (`source_item_id`),
  KEY `root_item_id` (`root_item_id`),
  KEY `inventory_id` (`inventory_id`),
  KEY `inventory_item_id` (`inventory_item_id`),
  KEY `sku` (`sku`),
  KEY `code` (`code`)
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
  `quantity` double NOT NULL,                               -- [oms int · oas float] ห้ามเป็น int ทศนิยมจะหาย
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
-- หมวดหมู่สินค้าและหน่วยนับตั้งต้น — สองชนิดนี้มีแค่โมดูล inventory ใช้
-- (department เป็นหมวดหมู่ของระบบกลาง อยู่ใน install/database.sql ของโปรเจ็ค)
--
-- ลงเฉพาะการติดตั้งใหม่ ตัวปรับรุ่นสร้างแต่ตาราง (ensureTable) ไม่รัน INSERT ของไฟล์นี้
-- ไฟล์สินค้าตัวอย่าง (sample-products.csv) ใช้ชื่อหมวดหมู่และหน่วยจากรายการนี้
-- ---------------------------------------------------------------------------
INSERT INTO `{prefix}_category` (`type`, `category_id`, `topic`, `color`, `is_active`) VALUES
('category_id', '1', 'สินค้าทั่วไป', NULL, 1),
('category_id', '2', 'อาหาร', NULL, 1),
('category_id', '3', 'เครื่องดื่ม', NULL, 1),
('category_id', '4', 'ของใช้ในบ้าน', NULL, 1),
('category_id', '5', 'ของใช้ส่วนตัว', NULL, 1),
('category_id', '6', 'เสื้อผ้าและเครื่องแต่งกาย', NULL, 1),
('category_id', '7', 'เครื่องเขียนและอุปกรณ์สำนักงาน', NULL, 1),
('category_id', '8', 'อุปกรณ์ไอทีและอิเล็กทรอนิกส์', NULL, 1),
('category_id', '9', 'วัตถุดิบ', NULL, 1),
('category_id', '10', 'บริการ', NULL, 1),
('unit', '1', 'ชิ้น', NULL, 1),
('unit', '2', 'อัน', NULL, 1),
('unit', '3', 'งาน', NULL, 1),
('unit', '4', 'ตัว', NULL, 1),
('unit', '5', 'คู่', NULL, 1),
('unit', '6', 'ชุด', NULL, 1),
('unit', '7', 'เครื่อง', NULL, 1),
('unit', '8', 'กล่อง', NULL, 1),
('unit', '9', 'แพ็ค', NULL, 1),
('unit', '10', 'โหล', NULL, 1),
('unit', '11', 'ลัง', NULL, 1),
('unit', '12', 'ขวด', NULL, 1),
('unit', '13', 'กระป๋อง', NULL, 1),
('unit', '14', 'ถุง', NULL, 1),
('unit', '15', 'ซอง', NULL, 1),
('unit', '16', 'แผง', NULL, 1),
('unit', '17', 'ฟอง', NULL, 1),
('unit', '18', 'ก้อน', NULL, 1),
('unit', '19', 'หลอด', NULL, 1),
('unit', '20', 'ม้วน', NULL, 1),
('unit', '21', 'แผ่น', NULL, 1),
('unit', '22', 'เล่ม', NULL, 1),
('unit', '23', 'รีม', NULL, 1),
('unit', '24', 'ด้าม', NULL, 1),
('unit', '25', 'กิโลกรัม', NULL, 1),
('unit', '26', 'ลิตร', NULL, 1),
('unit', '27', 'เมตร', NULL, 1),
('unit', '28', 'ครั้ง', NULL, 1),
('unit', '29', 'ชั่วโมง', NULL, 1),
('unit', '30', 'เดือน', NULL, 1);

-- ---------------------------------------------------------------------------
-- คู่ค้าตั้งต้น — ขายหน้าร้าน/ซื้อของไม่ระบุชื่อ ใช้รายการเงินสดแทนการเว้นว่าง
-- (เอกสารฝั่งซื้อบังคับระบุผู้ขาย) · รหัสไม่อยู่ในลำดับ CU%04d เลขรันของลูกค้าใหม่จึงเริ่มที่ CU0001
-- ที่อยู่ "-" เพราะฟอร์มลูกค้าบังคับกรอก ไม่งั้นแก้รายการนี้ไม่ได้
-- ---------------------------------------------------------------------------
INSERT INTO `{prefix}_customer` (`customer_no`, `code`, `company`, `address`, `is_customer`, `is_supplier`, `is_active`) VALUES
('CASH-C', 'CASH-C', 'ลูกค้าเงินสด', '-', 1, 0, 1),
('CASH-S', 'CASH-S', 'ผู้ขายเงินสด', '-', 0, 1, 1);

-- ---------------------------------------------------------------------------
-- ข้อมูลอ้างอิงที่ระบบต้องมี — แม่แบบเอกสารตั้งต้น
--
-- ถ้าไม่มีแถวเหล่านี้ โมดูลสร้างเอกสารไม่ได้เลยสักใบ และเมนูจะว่างเปล่า
-- รหัสและพฤติกรรมตรงกับทะเบียนกลาง (หัวข้อ 4.6 ของแผน)
-- แต่ละไซต์แก้/เพิ่ม/ปิดได้เองจากหน้าแม่แบบเอกสาร โดยไม่ต้องแก้โค้ด
--
-- stock_in = 1 รับของเข้าสต๊อก · stock_out = 1 ตัดของออก · ว่างทั้งคู่ = ไม่แตะสต๊อก
-- ---------------------------------------------------------------------------
INSERT INTO `{prefix}_inventory_template`
  (`document_type`, `status`, `mode`, `topic`, `comment`, `due_date`, `published`, `stock_in`, `stock_out`, `reserve_stock`, `movement_type`, `prefix`, `number_format`, `columns`) VALUES
('PO', 'PO', 'buy', 'ใบสั่งซื้อ', '', 0, 1, 0, 0, 0, NULL, 'PO%Y%M-', '%04d', 'item,topic,quantity,price,discount,amount'),
('IN', 'IN', 'buy', 'ใบรับสินค้า', '', 1, 1, 1, 0, 0, 'purchase', 'IN%Y%M-', '%04d', 'item,topic,quantity,price,discount,amount'),
('QUO', 'QUO', 'sell', 'ใบเสนอราคา', '', 1, 1, 0, 0, 0, NULL, 'QUO%Y%M-', '%04d', 'item,topic,quantity,price,discount,amount'),
('OUT', 'OUT', 'sell', 'ใบเสร็จรับเงิน', '', 0, 1, 0, 1, 0, 'sale', 'OUT%Y%M-', '%04d', 'item,topic,quantity,price,discount,amount'),
('RET', 'RET', 'sell', 'ใบคืนสินค้าจากลูกค้า', '', 0, 1, 1, 0, 0, 'return_in', 'RET%Y%M-', '%04d', 'item,topic,quantity,price,discount,amount');