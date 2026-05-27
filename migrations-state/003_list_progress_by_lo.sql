-- list_progress を (pref_cd, page) PK から (lo, page) PK に再構築。
-- pref ベース巡回から lo (市区町村) ベース巡回への切り替えに伴うもの。
-- 既存データは捨てて作り直す（PK構造が変わるので alter は不可）。

DROP TABLE IF EXISTS list_progress;

CREATE TABLE list_progress (
  lo             TEXT NOT NULL,          -- 6桁: localOnlyフラグ(1桁) + 標準地域コード(5桁)
  page           INTEGER NOT NULL,       -- 0オリジン
  http_status    INTEGER,
  total_count    INTEGER,
  facility_count INTEGER,
  fetched_at     TEXT,
  status         TEXT NOT NULL DEFAULT 'pending',  -- pending/done/error
  PRIMARY KEY (lo, page)
);
CREATE INDEX IF NOT EXISTS idx_list_progress_lo ON list_progress(lo);
