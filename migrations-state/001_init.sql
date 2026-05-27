PRAGMA journal_mode = WAL;

-- 運用メタDB。配布物には含めない。
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
