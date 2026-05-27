PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

-- 施設マスター（1施設1レコード、最新HTMLを raw BLOB で保持）
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

-- 一覧ページ取得の進捗。URL の page パラメータは 0 オリジン（page=0 が1ページ目）。
CREATE TABLE IF NOT EXISTS list_progress (
  pref_cd        TEXT NOT NULL,
  page           INTEGER NOT NULL,
  http_status    INTEGER,
  total_count    INTEGER,
  facility_count INTEGER,
  fetched_at     TEXT,
  status         TEXT NOT NULL DEFAULT 'pending',
  PRIMARY KEY (pref_cd, page)
);

-- 監査ログ
CREATE TABLE IF NOT EXISTS fetch_log (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  url         TEXT NOT NULL,
  http_status INTEGER,
  bytes       INTEGER,
  duration_ms INTEGER,
  user_agent  TEXT,
  fetched_at  TEXT NOT NULL DEFAULT (datetime('now', '+9 hours')),
  error       TEXT
);
CREATE INDEX IF NOT EXISTS idx_fetch_log_fetched_at ON fetch_log(fetched_at DESC);
