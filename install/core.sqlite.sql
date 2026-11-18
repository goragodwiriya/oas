-- ---------------------------------------------------------------------------
-- install/core.sqlite.sql — ตารางแกนของ Gcms สำหรับ SQLite
--
-- คู่ของ install/core.sql (MySQL ต้นฉบับ) ต้องมีตาราง คอลัมน์ ชนิด NULL/DEFAULT
-- และ index ตรงกันทุกตัว — install/cli-testdrivers.php ติดตั้งทั้งสองแบบแล้วเทียบให้
-- แก้ core.sql เมื่อไร ต้องแก้ไฟล์นี้ (และ core.pgsql.sql) ในงานเดียวกัน
--
-- SQLite รับชื่อชนิดอะไรก็ได้ (กำหนดแค่ affinity) จึงประกาศชนิดเดียวกับ MySQL ไว้
-- ให้อ่านเทียบกันได้ ยกเว้น
--   int(11) AUTO_INCREMENT → INTEGER PRIMARY KEY AUTOINCREMENT (ต้องเขียนแบบนี้เท่านั้น)
--   enum(...)              → varchar + CHECK
--   username               → COLLATE NOCASE — collation _ci ของ MySQL ทำให้ชื่อผู้ใช้ไม่สน
--                            ตัวพิมพ์ทั้งการเข้าระบบและ UNIQUE ส่วน = ของ SQLite สนตัวพิมพ์
--                            (ชื่อผู้ใช้เป็น ASCII เสมอ — Text::username() ตัดอักขระอื่นทิ้ง)
-- LIKE ของ SQLite ไม่สนตัวพิมพ์ของ ASCII อยู่แล้ว
-- ชื่อ index ของ SQLite ห้ามซ้ำทั้งฐาน จึงขึ้นต้นด้วยชื่อตาราง ({prefix}_user_username)
-- ---------------------------------------------------------------------------

-- ---------------------------------------------------------------------------
-- category — หมวดหมู่ทั่วไป ข้อมูลตัวอย่างอยู่ใน database.sqlite.sql ของแต่ละโปรเจ็ค
-- ---------------------------------------------------------------------------
CREATE TABLE "{prefix}_category" (
  "type" varchar(20) NOT NULL,
  "category_id" varchar(10) NOT NULL DEFAULT '0',
  "language" varchar(2) NOT NULL DEFAULT '',
  "topic" varchar(150) NOT NULL,
  "color" varchar(16) DEFAULT NULL,
  "is_active" tinyint(1) NOT NULL DEFAULT 1
);
CREATE INDEX "{prefix}_category_type" ON "{prefix}_category" ("type");
CREATE INDEX "{prefix}_category_category_id" ON "{prefix}_category" ("category_id");
CREATE INDEX "{prefix}_category_language" ON "{prefix}_category" ("language");

-- ---------------------------------------------------------------------------
-- language — ข้อความหลายภาษา นำเข้าจาก language/*.json โดย install/language.php
-- ---------------------------------------------------------------------------
CREATE TABLE "{prefix}_language" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "key" text NOT NULL,
  "type" varchar(5) NOT NULL,
  "th" text DEFAULT NULL,
  "en" text DEFAULT NULL
);

-- ---------------------------------------------------------------------------
-- logs — บันทึกกิจกรรมทั้งระบบ มักเป็นตารางที่ใหญ่ที่สุด
-- ---------------------------------------------------------------------------
CREATE TABLE "{prefix}_logs" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "src_id" int(11) NOT NULL,
  "module" varchar(20) NOT NULL,
  "action" varchar(20) NOT NULL,
  "created_at" datetime NOT NULL,
  "reason" text DEFAULT NULL,
  "member_id" int(11) NOT NULL,
  "topic" text NOT NULL,
  "datas" text DEFAULT NULL
);
CREATE INDEX "{prefix}_logs_src_id" ON "{prefix}_logs" ("src_id");
CREATE INDEX "{prefix}_logs_module" ON "{prefix}_logs" ("module");
CREATE INDEX "{prefix}_logs_action" ON "{prefix}_logs" ("action");
CREATE INDEX "{prefix}_logs_created_at" ON "{prefix}_logs" ("created_at");

-- ---------------------------------------------------------------------------
-- login_attempt — ประวัติการพยายามเข้าระบบ ใช้จำกัดการเดารหัสผ่าน
-- ---------------------------------------------------------------------------
CREATE TABLE "{prefix}_login_attempt" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "username" varchar(255) NOT NULL DEFAULT '',
  "ip_address" varchar(45) NOT NULL DEFAULT '',
  "user_agent" varchar(500) NOT NULL DEFAULT '',
  "attempted_at" datetime NOT NULL
);
CREATE INDEX "{prefix}_login_attempt_idx_ip_time" ON "{prefix}_login_attempt" ("ip_address", "attempted_at");
CREATE INDEX "{prefix}_login_attempt_idx_user_time" ON "{prefix}_login_attempt" ("username", "attempted_at");

-- ---------------------------------------------------------------------------
-- migration — ประวัติการปรับรุ่นของไซต์นี้เอง
-- ---------------------------------------------------------------------------
CREATE TABLE "{prefix}_migration" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "module" varchar(50) NOT NULL DEFAULT 'core',
  "version" varchar(20) NOT NULL,
  "applied_at" datetime DEFAULT NULL,
  "note" varchar(255) DEFAULT NULL
);
CREATE INDEX "{prefix}_migration_idx_module" ON "{prefix}_migration" ("module", "id");

-- ---------------------------------------------------------------------------
-- number — running number กลาง (Index\Number\Model)
-- ---------------------------------------------------------------------------
CREATE TABLE "{prefix}_number" (
  "type" varchar(20) NOT NULL,
  "prefix" varchar(20) NOT NULL DEFAULT '',
  "auto_increment" int(11) NOT NULL DEFAULT 0,
  "updated_at" date DEFAULT NULL,
  PRIMARY KEY ("type", "prefix")
);

-- ---------------------------------------------------------------------------
-- user — สมาชิก
--
-- username, id_card, phone เป็น UNIQUE ที่ยอมให้เป็น NULL ได้ (NULL ซ้ำกันได้
-- แต่ '' ซ้ำไม่ได้) ค่าว่างของสามคอลัมน์นี้ต้องเก็บเป็น NULL เสมอ
-- ---------------------------------------------------------------------------
CREATE TABLE "{prefix}_user" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "username" varchar(50) DEFAULT NULL COLLATE NOCASE,
  "salt" varchar(32) NOT NULL DEFAULT '',
  "password" varchar(255) NOT NULL,
  "token_expires" datetime DEFAULT NULL,
  "status" tinyint(1) DEFAULT 0,
  "permission" text DEFAULT NULL,
  "name" varchar(150) NOT NULL,
  "sex" varchar(1) DEFAULT NULL,
  "id_card" varchar(13) DEFAULT NULL,
  "tax_id" varchar(13) DEFAULT NULL,
  "birthday" date DEFAULT NULL,
  "address" varchar(64) DEFAULT NULL,
  "address2" varchar(64) DEFAULT NULL,
  "phone" varchar(20) DEFAULT NULL,
  "phone1" varchar(20) DEFAULT NULL,
  "provinceID" smallint(3) DEFAULT NULL,
  "province" varchar(64) DEFAULT NULL,
  "zipcode" varchar(5) DEFAULT NULL,
  "country" varchar(2) DEFAULT 'TH',
  "created_at" datetime DEFAULT NULL,
  "active" tinyint(1) DEFAULT 1,
  "social" varchar(8) DEFAULT 'user' CHECK ("social" IN ('user', 'facebook', 'google', 'line', 'telegram')),
  "line_uid" varchar(33) DEFAULT NULL,
  "telegram_id" varchar(20) DEFAULT NULL,
  "activatecode" varchar(64) DEFAULT NULL,
  "visited" int(11) NOT NULL DEFAULT 0,
  "website" varchar(255) DEFAULT NULL,
  "company" varchar(64) DEFAULT NULL
);
CREATE UNIQUE INDEX "{prefix}_user_username" ON "{prefix}_user" ("username");
CREATE UNIQUE INDEX "{prefix}_user_id_card" ON "{prefix}_user" ("id_card");
CREATE UNIQUE INDEX "{prefix}_user_phone" ON "{prefix}_user" ("phone");
CREATE INDEX "{prefix}_user_activatecode" ON "{prefix}_user" ("activatecode");
CREATE INDEX "{prefix}_user_line_uid" ON "{prefix}_user" ("line_uid");
CREATE INDEX "{prefix}_user_telegram_id" ON "{prefix}_user" ("telegram_id");
CREATE INDEX "{prefix}_user_idx_status" ON "{prefix}_user" ("active", "status");

-- ---------------------------------------------------------------------------
-- user_meta — ข้อมูลเพิ่มเติมของสมาชิกแบบ key/value
-- ---------------------------------------------------------------------------
CREATE TABLE "{prefix}_user_meta" (
  "value" varchar(10) NOT NULL,
  "name" varchar(20) NOT NULL,
  "member_id" int(11) NOT NULL
);
CREATE INDEX "{prefix}_user_meta_member_id" ON "{prefix}_user_meta" ("member_id", "name");

-- ---------------------------------------------------------------------------
-- user_session — ทะเบียนเซสชันที่เปิดอยู่ หนึ่งบัญชีมีได้หลายแถว
-- Index\Auth\Model::getUserByToken() ตัดสินจากตารางนี้ว่า token ยังใช้ได้อยู่ไหม
-- ---------------------------------------------------------------------------
CREATE TABLE "{prefix}_user_session" (
  "sid" varchar(32) NOT NULL PRIMARY KEY,
  "member_id" int(11) NOT NULL,
  "expires_at" int(11) NOT NULL DEFAULT 0,
  "ip" varchar(45) DEFAULT NULL,
  "user_agent" varchar(255) DEFAULT NULL,
  "fingerprint" varchar(64) DEFAULT NULL,
  "last_event" varchar(20) DEFAULT NULL,
  "last_seen" datetime DEFAULT NULL
);
CREATE INDEX "{prefix}_user_session_member_id" ON "{prefix}_user_session" ("member_id");
CREATE INDEX "{prefix}_user_session_expires_at" ON "{prefix}_user_session" ("expires_at");
