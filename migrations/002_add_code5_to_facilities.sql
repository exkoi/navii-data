-- 施設に市区町村コード（e-Stat 標準地域コード 5桁）を追加。
-- crawl-list.php がクロール起点の lo (6桁) から先頭1桁を除いて入れる。
-- 既存レコード（旧 pref ベースクロール由来）は次回 lo ベースで再クロールされた時に埋まる。
ALTER TABLE facilities ADD COLUMN code5 TEXT;
CREATE INDEX IF NOT EXISTS idx_facilities_code5 ON facilities(code5);
