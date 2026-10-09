-- ---------------------------------------------------------------------------
-- install/core.sql — ตารางแกนของ Gcms
--
-- ไฟล์นี้ต้อง "เหมือนกันทุกไบต์" ในทุกโปรเจ็คของ now.js เพราะเป็นตารางที่
-- Kotchasan/Gcms ใช้เอง (สมาชิก สิทธิ บันทึกกิจกรรม เซสชัน ภาษา หมวดหมู่)
-- ตารางของแต่ละโปรเจ็คอยู่ใน install/database.sql แยกต่างหาก
--
-- ทั้งการติดตั้งใหม่ (install/step4.php) และการปรับรุ่น (install/upgrade2.php)
-- อ่านนิยามตารางจากไฟล์นี้ไฟล์เดียว จะได้ไม่มีนิยามเดียวกันสองชุดที่ค่อย ๆ
-- ต่างกันจนเครื่องที่ติดตั้งใหม่กับเครื่องที่ปรับรุ่นมีสคีมาคนละหน้าตา
--
-- ข้อกำหนด: InnoDB + utf8mb4 ทุกตาราง และเขียน PRIMARY KEY/KEY ไว้ในคำสั่ง
-- CREATE TABLE เลย (ไม่แยกไปเป็น ALTER TABLE ท้ายไฟล์แบบที่ phpMyAdmin dump มา)
-- เพื่อให้ตารางที่ตัวปรับรุ่นสร้างจากไฟล์นี้ได้ index ครบตั้งแต่แรก
-- ---------------------------------------------------------------------------

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

-- ---------------------------------------------------------------------------
-- category — หมวดหมู่ทั่วไป ข้อมูลตัวอย่างอยู่ใน database.sql ของแต่ละโปรเจ็ค
-- ---------------------------------------------------------------------------
CREATE TABLE `{prefix}_category` (
  `type` varchar(20) NOT NULL,
  `category_id` varchar(10) NOT NULL DEFAULT '0',
  `language` varchar(2) NOT NULL DEFAULT '',
  `topic` varchar(150) NOT NULL,
  `color` varchar(16) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  KEY `type` (`type`),
  KEY `category_id` (`category_id`),
  KEY `language` (`language`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- language — ข้อความหลายภาษา นำเข้าจาก language/*.json โดย install/language.php
-- ---------------------------------------------------------------------------
CREATE TABLE `{prefix}_language` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `key` text NOT NULL,
  `type` varchar(5) NOT NULL,
  `th` text DEFAULT NULL,
  `en` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- logs — บันทึกกิจกรรมทั้งระบบ มักเป็นตารางที่ใหญ่ที่สุด
-- ---------------------------------------------------------------------------
CREATE TABLE `{prefix}_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `src_id` int(11) NOT NULL,
  `module` varchar(20) NOT NULL,
  `action` varchar(20) NOT NULL,
  `created_at` datetime NOT NULL,
  `reason` text DEFAULT NULL,
  `member_id` int(11) NOT NULL,
  `topic` text NOT NULL,
  `datas` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `src_id` (`src_id`),
  KEY `module` (`module`),
  KEY `action` (`action`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- login_attempt — ประวัติการพยายามเข้าระบบ ใช้จำกัดการเดารหัสผ่าน
-- ---------------------------------------------------------------------------
CREATE TABLE `{prefix}_login_attempt` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL DEFAULT '',
  `ip_address` varchar(45) NOT NULL DEFAULT '',
  `user_agent` varchar(500) NOT NULL DEFAULT '',
  `attempted_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ip_time` (`ip_address`,`attempted_at`),
  KEY `idx_user_time` (`username`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- migration — ประวัติการปรับรุ่นของไซต์นี้เอง
--
-- ไซต์ที่ติดตั้งไปแล้วเราเข้าไม่ถึง ตัวไซต์จึงต้องรู้สถานะของตัวเองได้ว่าอยู่รุ่น
-- ไหนและปรับรุ่นครั้งล่าสุดเมื่อไร (install/preflight.php : stampMigration)
-- module = 'core' หรือชื่อโมดูล เพื่อให้โมดูลที่ปรับรุ่นแยกจากแกนบันทึกของตัวเองได้
-- ---------------------------------------------------------------------------
CREATE TABLE `{prefix}_migration` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `module` varchar(50) NOT NULL DEFAULT 'core',
  `version` varchar(20) NOT NULL,
  `applied_at` datetime DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_module` (`module`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- number — running number กลาง (Index\Number\Model)
-- ---------------------------------------------------------------------------
CREATE TABLE `{prefix}_number` (
  `type` varchar(20) NOT NULL,
  `prefix` varchar(20) NOT NULL DEFAULT '',
  `auto_increment` int(11) NOT NULL DEFAULT 0,
  `updated_at` date DEFAULT NULL,
  PRIMARY KEY (`type`,`prefix`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- user — สมาชิก
--
-- username, id_card, phone เป็น UNIQUE ที่ยอมให้เป็น NULL ได้ (NULL ซ้ำกันได้
-- แต่ '' ซ้ำไม่ได้) ค่าว่างของสามคอลัมน์นี้ต้องเก็บเป็น NULL เสมอ
-- ---------------------------------------------------------------------------
CREATE TABLE `{prefix}_user` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) DEFAULT NULL,
  `salt` varchar(32) NOT NULL DEFAULT '',
  `password` varchar(255) NOT NULL,
  `token_expires` datetime DEFAULT NULL,
  `status` tinyint(1) DEFAULT 0,
  `permission` text DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `sex` varchar(1) DEFAULT NULL,
  `id_card` varchar(13) DEFAULT NULL,
  `tax_id` varchar(13) DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `address` varchar(64) DEFAULT NULL,
  `address2` varchar(64) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `phone1` varchar(20) DEFAULT NULL,
  `provinceID` smallint(3) DEFAULT NULL,
  `province` varchar(64) DEFAULT NULL,
  `zipcode` varchar(5) DEFAULT NULL,
  `country` varchar(2) DEFAULT 'TH',
  `created_at` datetime DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  `social` enum('user','facebook','google','line','telegram') DEFAULT 'user',
  `line_uid` varchar(33) DEFAULT NULL,
  `telegram_id` varchar(20) DEFAULT NULL,
  `activatecode` varchar(64) DEFAULT NULL,
  `visited` int(11) NOT NULL DEFAULT 0,
  `website` varchar(255) DEFAULT NULL,
  `company` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `id_card` (`id_card`),
  UNIQUE KEY `phone` (`phone`),
  KEY `activatecode` (`activatecode`),
  KEY `line_uid` (`line_uid`),
  KEY `telegram_id` (`telegram_id`),
  KEY `idx_status` (`active`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- user_meta — ข้อมูลเพิ่มเติมของสมาชิกแบบ key/value
-- ---------------------------------------------------------------------------
CREATE TABLE `{prefix}_user_meta` (
  `value` varchar(10) NOT NULL,
  `name` varchar(20) NOT NULL,
  `member_id` int(11) NOT NULL,
  KEY `member_id` (`member_id`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- user_session — ทะเบียนเซสชันที่เปิดอยู่ หนึ่งบัญชีมีได้หลายแถว ซึ่งเป็นสิ่งที่
-- ทำให้เข้าระบบจากหลายเครื่องพร้อมกันได้
-- Index\Auth\Model::getUserByToken() ตัดสินจากตารางนี้ว่า token ยังใช้ได้อยู่ไหม
-- ถ้าไม่มีตารางนี้จะเข้าระบบไม่ได้เลยทั้งระบบ
-- ---------------------------------------------------------------------------
CREATE TABLE `{prefix}_user_session` (
  `sid` varchar(32) NOT NULL,
  `member_id` int(11) NOT NULL,
  `expires_at` int(11) NOT NULL DEFAULT 0,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `fingerprint` varchar(64) DEFAULT NULL,
  `last_event` varchar(20) DEFAULT NULL,
  `last_seen` datetime DEFAULT NULL,
  PRIMARY KEY (`sid`),
  KEY `member_id` (`member_id`),
  KEY `expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
