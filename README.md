# Navii Data Scraper

厚生労働省「医療情報ネット（ナビイ）」 https://www.iryou.teikyouseido.mhlw.go.jp/ から医療機関情報を
定期スクレイピングし、HTMLスナップショットを SQLite に蓄積するシンプルなツール。

- フレームワーク不使用、PHP 8.4+ / Composer / SQLite3 のみ
- レンタルサーバー（Xserver等）の Cron（最短2分）で動かす前提
- 礼儀正しいクロール：UserAgent に連絡先記載、同時接続1、3〜10秒/req、429/403で即停止、差分のみ取得
- **クロールは市区町村単位**（`lo` パラメータ、e-Stat 標準地域コード由来）
- **DB は2ファイル分離**：配布用 `navii.sqlite`（facilities のみ）+ 運用用 `navii-state.sqlite`（市区町村マスタ・進捗・監査ログ）
- 進捗確認は CLI (`php bin/status.php`) と Web (`/index.php`)。Web は Basic 認証で保護

## 出典

このツールが取得するすべての医療機関情報は **「医療情報ネット（厚生労働省）」** を出典とする。
取得データを利用・転載する際は必ず出典明記。

市区町村マスタは **e-Stat 統計LOD（標準地域コード）** を出典とする（CC BY 4.0）。

## ディレクトリ

```
.
├── bin/                       # CLIエントリポイント
│   ├── init-db.php
│   ├── build-municipalities.php  # 市区町村マスタを e-Stat SPARQL から取得
│   ├── crawl-list.php            # 市区町村 (lo) 単位で一覧クロール
│   ├── crawl-detail.php          # 詳細ページHTMLをスナップショット保存
│   ├── status.php                # 進捗確認（CLI）
│   └── reset-state.php           # テスト用全消し（要 --yes）
├── src/                        # PSR-4 (Exp\NaviiData\)
├── config/                     # 設定（env優先）
├── migrations/                 # 配布DB（facilities）のスキーマ
├── migrations-state/           # 運用DB（state）のスキーマ
├── data/                       # SQLite DB、ロック、停止フラグ、ログ（gitignore）
│   ├── navii.sqlite              # 配布対象（facilities のみ）
│   ├── navii-state.sqlite        # 運用専用（municipalities / list_progress / fetch_log）
│   └── logs/
├── tmp/                        # サンプルHTML置き場
├── index.php                   # Web進捗ダッシュボード（Basic認証必須）
├── .htaccess.example           # Basic認証＋機密ファイル deny のテンプレート
└── .htpasswd.example
```

## セットアップ

### ローカル開発

```bash
composer install
php bin/init-db.php
php bin/build-municipalities.php   # 約1900件の市区町村マスタを取得
```

### 環境変数

設定は env で上書き可能（`config/config.php` がデフォルト）。

| 環境変数 | デフォルト | 説明 |
|---|---|---|
| `NAVII_TARGET_PREFS` | `13` | 対象都道府県（JIS X 0401、カンマ区切り）|
| `NAVII_TARGET_KBNS` | `2` | 対象 kikanKbn（1=病院, 2=診療所, 3=歯科, 4=助産所）|
| `NAVII_MAX_PER_RUN` | `10` | 1cron 実行で取得する詳細ページ数 |
| `NAVII_SLEEP_MIN` | `3` | リクエスト間スリープ最小秒 |
| `NAVII_SLEEP_MAX` | `10` | リクエスト間スリープ最大秒 |
| `NAVII_LOCAL_ONLY` | `1` | `lo` の先頭1桁。1=その自治体のみ、0=周辺含む |
| `NAVII_SORT_NO` | `1` | 一覧の並び順。1=施設名称の50音順 |

## 使い方

### 0. 市区町村マスタを取得

```bash
php bin/build-municipalities.php
```

e-Stat 統計LOD（SPARQL）から「現存する市区町村」1918件を取得して `state.municipalities` に格納。
ナビイで `lo=DesignatedCity` を直接叩くとシステムエラーになるため、政令市の親（DesignatedCity）は
クロール時に除外（マスタには参照用に保持）。

マスタは数年に1回の市町村合併時に再実行すれば良い。

### 1. 一覧ページのクロール（1回1ページ）

```bash
NAVII_TARGET_PREFS=13 php bin/crawl-list.php
```

`target_prefs` に該当する市区町村を順に巡回。各 lo で `page=0` を取得 → `total_count` から最大ページ数を
算出 → 残りページを順に取得。

URL: `/znk-web/juminkanja/S2400/initialize?sortNo=1&sjk=3&jc=MC-01&lo=NNNNNN&page=N`

### 2. 詳細ページのクロール

```bash
NAVII_TARGET_KBNS=2 NAVII_MAX_PER_RUN=10 php bin/crawl-detail.php
```

`facilities.status='pending'` または `'error' で retry_count < 3 かつ next_attempt_at <= now`
のレコードを最大 `NAVII_MAX_PER_RUN` 件処理する。

### 3. 進捗確認

```bash
php bin/status.php
```

Web からも見たい場合は `/index.php` を Basic 認証経由で開く。

### 4. テスト用にクリーンアップ

```bash
php bin/reset-state.php --yes              # facilities 含めて全消し
php bin/reset-state.php --yes --keep-html  # facilities と html は残してステータスだけ pending に戻す
```

## SQLite スキーマ概要

### 配布DB: `data/navii.sqlite`

| テーブル | 役割 |
|---|---|
| `facilities` | 1施設1レコード。詳細ページの **生HTML（gzip圧縮 BLOB）** + メタ情報 + ステータスをすべてここに集約。これだけが配布対象 |

`facilities` 主要カラム：

| カラム | 用途 |
|---|---|
| `kikan_cd`, `pref_cd`, `kikan_kbn` | 主キー（複合） |
| `code5` | e-Stat 標準地域コード（5桁、クロール起点の lo の後5桁） |
| `html` | 詳細ページの生HTML（gzip圧縮 BLOB）。`HtmlCodec::decode()` で展開して使う。構造は可逆に完全保持 |
| `content_hash` | 正規化済みHTMLの sha256（差分判定用・圧縮前に算出） |
| `bytes`, `http_status` | 取得時のメタ |
| `first_seen_at` / `last_seen_at` | 一覧で見つけた最初／最新の時刻 |
| `last_scraped_at` | 詳細ページを取得した最新時刻 |
| `last_changed_at` | HTML 内容（hash）が変化した最新時刻 |
| `status` | `pending` / `done` / `error` / `skip` |
| `retry_count`, `last_error`, `next_attempt_at` | エラーバックオフ用 |

### 運用DB: `data/navii-state.sqlite`（配布しない）

| テーブル | 役割 |
|---|---|
| `municipalities` | 市区町村マスタ。`code5`, `lo`, `name`, `pref_cd`, `pref_name`, `admin_class`。e-Stat SPARQL から生成 |
| `list_progress` | (lo, page) 単位での一覧クロール進捗 |
| `fetch_log` | 全HTTPリクエストの監査ログ |

PHP からは main DB を開く時に `ATTACH DATABASE ... AS state` で両方を1接続で扱う。SQL では `state.list_progress` のようにスキーマ修飾。

### 設計方針

- **1施設1レコード**。最新のHTMLだけを保持（履歴は持たない）
- **生HTML を BLOB のまま保存**（一次ソースとしてダウンロードして使う用途のため、加工しない）
- 差分判定は `?timeAt=...` キャッシュバスタータイムスタンプと `_csrf` トークンを除いた正規化済みHTMLの sha256 を比較（`src/HtmlNormalizer.php`）
- HTML が変化していなくても `last_scraped_at` は毎回更新、変化した時だけ `last_changed_at` を更新
- **配布物の軽量化のため facilities だけを別DBに**。`navii-state.sqlite` は配布時にコピー不要

### DBダウンロード後の使い方例

```sql
-- ある施設の最新HTMLを取り出す
SELECT html FROM facilities WHERE kikan_cd = '2131064160';

-- 直近24時間で変化があった施設
SELECT kikan_cd, code5, last_changed_at FROM facilities
WHERE last_changed_at >= datetime('now', '+9 hours', '-1 day');

-- 特定の市区町村（code5）の施設一覧
SELECT kikan_cd, html FROM facilities WHERE code5 = '13101';

-- まだ詳細未取得の施設数
SELECT COUNT(*) FROM facilities WHERE html IS NULL;
```

## クロール戦略（lo パラメータ仕様）

`lo` は **6桁** = 先頭1桁（localOnly フラグ）+ 5桁（e-Stat 標準地域コード）。

- `1` + `13101` = `113101` → 千代田区のみ（localOnly ON）
- `0` + `13101` = `013101` → 千代田区を中心に周辺含む

ナビイの S2400 検索エンドポイントは `administrativeClass` ごとに挙動が異なる：

| 行政区分 | S2400 挙動 | クロール |
|---|---|---|
| `SpecialWard` (東京23区) / `Ward` (政令市の区) / `City` / `CoreCity` / `SpecialCity` / `Town` / `Village` | 200 OK | ✅ |
| `DesignatedCity` (政令市の親、例: lo=114100=横浜市) | 302 → systemerror | ❌ 除外 |

このため `crawl-list.php` は `admin_class != 'DesignatedCity'` でフィルタする。
全1918件のうちクロール対象は **1898件**。

## 礼儀正しいクロールの方針

- **UserAgent**: `NaviiDataBot/0.1 (+mailto:koizumi@hospita.jp; purpose=research; contact for opt-out)`
  サイト管理者が問い合わせ可能なメールアドレスを明示
- **同時接続**: 常に1。`flock` で二重起動防止（`crawl-list.php` と `crawl-detail.php` は同じ lock を共有）
- **リクエスト間隔**: 3〜10秒のランダムスリープ
- **429/403**: 即停止。`data/.stop` ファイルを作成し、人手で確認・削除するまで以降の実行は即終了
- **5xx**: 指数バックオフ（1分→3分→9分、最大3回）
- **連続エラー閾値**: 5件連続失敗で `.stop` 自動作成（`CircuitBreaker`）
- **差分のみ取得**: 同一HTMLなら snapshot を追記しない

## レンタルサーバー（Xserver等）への配置

### 1. アップロード

`vendor/` も含めて `public_html/navii-data/` 等にアップロード（または SSH で `composer install`）。

### 2. Basic 認証セットアップ

`.htaccess` 実体は git 管理外。テンプレート `.htaccess.example` から作る：

```bash
cd ~/<ドメイン>/public_html/navii-data
cp .htaccess.example .htaccess
sed -i "s|/CHANGE_ME/navii-data/.htpasswd|$(pwd)/.htpasswd|" .htaccess
htpasswd -B -c .htpasswd <ユーザー名>
# -B = bcrypt 推奨
```

### 3. DB初期化＋マスタ取得

```bash
php bin/init-db.php
php bin/build-municipalities.php
```

### 4. Web 動作確認

ブラウザで `https://<ドメイン>/navii-data/` にアクセス → Basic 認証ダイアログ → 認証成功で
進捗ダッシュボードが表示されることを確認。

`/navii-data/data/navii.sqlite` への直アクセスが 403 になることも確認（`.htaccess` の `FilesMatch` が
sqlite ファイルを deny する）。

### 5. cron 設定

Xserver のサーバーパネル「Cron設定」または `crontab -e` で次の2行を追加。最短2分間隔。
偶数分に一覧、奇数分に詳細を回すことで lock 衝突を避ける。

```cron
# Navii Data Scraper - 一覧クロール（偶数分）
*/2 * * * * cd /home/<account>/<domain>/public_html/navii-data && /usr/bin/php8.5 bin/crawl-list.php >> data/logs/cron-list.log 2>&1

# Navii Data Scraper - 詳細クロール（奇数分）
1-59/2 * * * * cd /home/<account>/<domain>/public_html/navii-data && /usr/bin/php8.5 bin/crawl-detail.php >> data/logs/cron-detail.log 2>&1
```

Xserver では PHP CLI のフルパスを明示するのが安全（`/usr/bin/php` のデフォルトバージョン変更時に
影響を受けないため）。`/usr/bin/php8.5` は PHP 8.5 系の最新を指す symlink。

実行ペースは初期は控えめにして、`bin/status.php` でエラー率を監視しながら調整する。

## トラブル時の操作

| 状況 | 対処 |
|---|---|
| 即停止したい | `touch data/.stop` する。以降のクロールは即終了する |
| 停止解除 | `rm data/.stop` |
| 連続エラーで進まない | `bin/status.php` で `fetch_log` のエラー内容を確認 → 原因対処 → `.stop` 削除 → 必要に応じて `UPDATE facilities SET status='pending', retry_count=0 WHERE status='error'` |
| 市町村合併でマスタが古くなった | `php bin/build-municipalities.php` を再実行（UPSERTなので安全） |
| 全消ししてやり直し | `php bin/reset-state.php --yes` |
| 既存の非圧縮HTMLを圧縮したい | `.stop` でクロール停止 → DBバックアップ → `php bin/compress-html.php`（gzip圧縮 + VACUUM、再実行可）|

## HTML の取り出し（解析側）

`html` カラムは gzip 圧縮 BLOB。**SQLite の組み込み関数だけでは展開できない**ので、解析側で展開する：

| 言語 | 展開 |
|---|---|
| PHP | `Exp\NaviiData\HtmlCodec::decode($blob)`（圧縮/非圧縮を自動判別）または `gzdecode($blob)` |
| Python | `gzip.decompress(blob)` |
| Node.js | `require('zlib').gunzipSync(blob)` |

先頭2バイトが gzip マジック（`0x1f 0x8b`）なら圧縮済み。移行前の非圧縮データが混在しても `HtmlCodec::decode()` はそのまま返す。

## 利用規約上の留意

- 「医療情報ネット」利用規約に基づき、転載時の出所明記が必須
- 「運営に支障を及ぼす行為」は禁止 → 本ツールの設定（リクエスト間隔・429時即停止）はその準拠を意図したもの
- 設定値を緩める場合はサイト負荷を確認しつつ慎重に
- e-Stat 統計LOD は CC BY 4.0、出典明記の上で再配布可
