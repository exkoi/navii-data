-- 市区町村マスタ。e-Stat 統計LOD（標準地域コード）から取得。
-- bin/build-municipalities.php で更新。
CREATE TABLE IF NOT EXISTS municipalities (
  code5         TEXT NOT NULL PRIMARY KEY,  -- 例: '13101' (e-Stat 標準地域コード5桁)
  lo            TEXT NOT NULL,              -- 例: '113101' (厚労省 lo パラメータ = '1' || code5)
  name          TEXT NOT NULL,              -- 例: '千代田区'
  pref_cd       TEXT NOT NULL,              -- 例: '13' (code5 の先頭2桁)
  pref_name     TEXT NOT NULL,              -- 例: '東京都'
  admin_class   TEXT NOT NULL,              -- 'City'/'Town'/'Village'/'Ward'/'SpecialWard'/'CoreCity'/'DesignatedCity'/'SpecialCity'
  fetched_at    TEXT NOT NULL DEFAULT (datetime('now', '+9 hours'))
);
CREATE INDEX IF NOT EXISTS idx_municipalities_pref ON municipalities(pref_cd);
CREATE UNIQUE INDEX IF NOT EXISTS idx_municipalities_lo ON municipalities(lo);
