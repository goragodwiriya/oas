-- ---------------------------------------------------------------------------
-- Timeline Provider — ตารางเดียวที่โมดูล timeline ต้องใช้
--
-- เก็บผลลัพธ์ของ action ที่ทำไปแล้ว เพื่อให้คำขอที่มี idempotency_key ซ้ำ
-- ได้คำตอบเดิมกลับไปโดยไม่ทำงานซ้ำ — กด "บันทึกว่าติดต่อแล้ว" สองครั้งเพราะ
-- เน็ตช้า ต้องไม่กลายเป็นสองแถวในฐานข้อมูล
--
-- แถวที่เก่ากว่า 24 ชั่วโมงถูกลบทิ้งเองแบบสุ่มโดย Gcms\Timeline\Provider
-- ---------------------------------------------------------------------------

CREATE TABLE `{prefix}_timeline_idempotency` (
  `key_hash` char(64) NOT NULL,
  `uid` varchar(190) NOT NULL DEFAULT '',
  `action` varchar(32) NOT NULL DEFAULT '',
  `response_json` mediumtext DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`key_hash`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
