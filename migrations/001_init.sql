PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

-- 施設マスター（1施設1レコード、最新HTMLを raw BLOB で保持）
-- このDBは配布物そのものなので、運用メタ（list_progress / fetch_log）は state DB 側に置く。
CREATE TABLE IF NOT EXISTS facilities (
  kikan_cd        TEXT NOT NULL,
  pref_cd         TEXT NOT NULL,
  kikan_kbn       TEXT NOT NULL,

  -- HTMLスナップショット（生HTML、非圧縮 BLOB）
  html            BLOB,
  content_hash    TEXT,                 -- 正規化後HTMLのsha256（差分判定用）
  bytes           INTEGER,               -- 生HTMLのバイト数
  http_status     INTEGER,

  -- タイムスタンプ
  first_seen_at   TEXT NOT NULL DEFAULT (datetime('now', '+9 hours')),
  last_seen_at    TEXT NOT NULL DEFAULT (datetime('now', '+9 hours')),
  last_scraped_at TEXT,
  last_changed_at TEXT,                  -- content_hash が前回と変わった時刻

  -- スクレイピング状態
  status          TEXT NOT NULL DEFAULT 'pending',  -- pending/done/error/skip
  retry_count     INTEGER NOT NULL DEFAULT 0,
  last_error      TEXT,
  next_attempt_at TEXT,

  PRIMARY KEY (kikan_cd, pref_cd, kikan_kbn)
);
CREATE INDEX IF NOT EXISTS idx_facilities_pref   ON facilities(pref_cd);
CREATE INDEX IF NOT EXISTS idx_facilities_status ON facilities(status, next_attempt_at);
