-- ---------------------------------------------------------------------------
-- install/legacy/pre-module.sql — สคีมาของ oas ระบบเดิม (GCMS11 · ก่อนรับโมดูล inventory กลาง)
--
-- ไม่ใช่ไฟล์ที่ระบบใช้ตอนติดตั้งหรือปรับรุ่น — ใช้เฉพาะชุดทดสอบ F3 ของ install/testdb-module.php
-- คัดจาก install/database.sql ของ oas ระบบเดิม (/mnt/Server/htdocs/GCMS11/kotchasan/oas/oas)
--   inventory        ไม่มีรหัสและราคา (อยู่ที่หน่วยย่อย) · unit = id ของหมวดหน่วยนับ · stock = ยอดคงเหลือ
--   inventory_items  คีย์หลัก product_no · ราคา หน่วย ตัวคูณ (cut_stock) อยู่ที่นี่
--   orders           ชนิดเอกสารอยู่ที่ status
--   stock            บรรทัดเอกสาร (order_id > 0) และการตั้งยอด (order_id = 0)
-- ---------------------------------------------------------------------------

CREATE TABLE `{prefix}_inventory` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` varchar(10) DEFAULT '0',
  `topic` varchar(150) DEFAULT NULL,
  `cost` double DEFAULT 0,
  `vat` double DEFAULT 0,
  `stock` double DEFAULT 0,
  `count_stock` tinyint(1) DEFAULT 1,
  `inuse` tinyint(1) NOT NULL DEFAULT 1,
  `create_date` date DEFAULT NULL,
  `unit` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`),
  KEY `count_stock` (`count_stock`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

CREATE TABLE `{prefix}_inventory_items` (
  `product_no` varchar(150) NOT NULL,
  `inventory_id` int(11) NOT NULL,
  `topic` varchar(100) NOT NULL,
  `price` double DEFAULT 0,
  `cut_stock` double DEFAULT 1,
  `unit` varchar(50) DEFAULT NULL,
  `instock` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`product_no`),
  KEY `inventory_id` (`inventory_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

CREATE TABLE `{prefix}_inventory_meta` (
  `inventory_id` int(11) NOT NULL,
  `name` varchar(20) NOT NULL,
  `value` text NOT NULL,
  KEY `inventory_id` (`inventory_id`),
  KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

CREATE TABLE `{prefix}_customer` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_no` varchar(20) DEFAULT NULL,
  `company` varchar(150) DEFAULT NULL,
  `branch` varchar(50) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `idcard` varchar(13) DEFAULT NULL,
  `tax_id` varchar(13) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `fax` varchar(20) DEFAULT NULL,
  `email` varchar(50) DEFAULT NULL,
  `address` varchar(64) DEFAULT NULL,
  `provinceID` smallint(3) UNSIGNED NOT NULL,
  `province` varchar(64) DEFAULT NULL,
  `zipcode` varchar(5) DEFAULT NULL,
  `country` varchar(2) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `bank` varchar(100) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `bank_no` varchar(20) DEFAULT NULL,
  `discount` double NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `customer_no` (`customer_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

CREATE TABLE `{prefix}_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_no` varchar(20) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `order_date` date NOT NULL,
  `member_id` int(11) UNSIGNED NOT NULL,
  `discount` double NOT NULL,
  `vat` double NOT NULL,
  `tax` double NOT NULL,
  `total` double NOT NULL,
  `status` varchar(3) NOT NULL,
  `paid` double NOT NULL DEFAULT 0,
  `discount_percent` double NOT NULL,
  `tax_status` double NOT NULL,
  `vat_status` tinyint(1) NOT NULL,
  `order` varchar(32) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `payment_date` datetime DEFAULT NULL,
  `payment_method` varchar(255) DEFAULT NULL,
  `comment` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

CREATE TABLE `{prefix}_stock` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `inventory_id` int(11) DEFAULT NULL,
  `product_no` varchar(50) DEFAULT NULL,
  `status` varchar(3) DEFAULT NULL,
  `create_date` datetime NOT NULL,
  `topic` varchar(150) DEFAULT NULL,
  `quantity` float DEFAULT 0,
  `cut_stock` double DEFAULT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `used` double NOT NULL,
  `price` double NOT NULL,
  `vat` double NOT NULL,
  `discount` double NOT NULL,
  `total` double NOT NULL,
  PRIMARY KEY (`id`),
  KEY `owner_id` (`order_id`),
  KEY `status` (`status`),
  KEY `product_no` (`product_no`),
  KEY `inventory_id` (`inventory_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
