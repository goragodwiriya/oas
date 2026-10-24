-- ---------------------------------------------------------------------------
-- install/timeline.sqlite.sql — คู่ของ install/timeline.sql สำหรับ SQLite
--
-- เก็บผลลัพธ์ของ action ที่ทำไปแล้ว เพื่อให้คำขอที่มี idempotency_key ซ้ำ
-- ได้คำตอบเดิมกลับไปโดยไม่ทำงานซ้ำ (ดูคำอธิบายเต็มใน timeline.sql)
-- ---------------------------------------------------------------------------

CREATE TABLE "{prefix}_timeline_idempotency" (
  "key_hash" char(64) NOT NULL PRIMARY KEY,
  "uid" varchar(190) NOT NULL DEFAULT '',
  "action" varchar(32) NOT NULL DEFAULT '',
  "response_json" mediumtext DEFAULT NULL,
  "created_at" datetime NOT NULL
);
CREATE INDEX "{prefix}_timeline_idempotency_created_at" ON "{prefix}_timeline_idempotency" ("created_at");
