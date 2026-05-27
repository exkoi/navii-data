# Navii Data Scraper

厚生労働省「医療情報ネット（ナビイ）」 https://www.iryou.teikyouseido.mhlw.go.jp/ から医療機関情報を
定期スクレイピングし、HTMLスナップショットを SQLite に蓄積するシンプルなツール。

- フレームワーク不使用、PHP 8.2+ / Composer / SQLite3 のみ
- レンタルサーバー（Xserver等）の Cron（最短2分）で動かす前提
- 礼儀正しいクロール：UserAgent に連絡先記載、同時接続1、3〜10秒/req、429/403で即停止、差分のみ取得
- 進捗確認は CLI (`php bin/status.php`) と Web (`/index.php`)。Web は Basic 認証で保護

## 出典

このツールが取得するすべての医療機関情報は **「医療情報ネット（厚生労働省）」** を出典とする。
取得データを利用・転載する際は必ず出典明記。

## ディレクトリ

```
.
├── bin/             # CLIエントリポイント
│   ├── init-db.php
│   ├── crawl-list.php       # 一覧ページから施設インデックスを収集
│   ├── crawl-detail.php     # 詳細ページHTMLをスナップショット保存
│   ├── status.php           # 進捗確認（CLI）
│   └── reset-state.php      # テスト用全消し（要 --yes）
├── src/             # PSR-4 (Exp\NaviiData\)
├── config/          # 設定（env優先）
├── migrations/      # 001_init.sql
├── data/            # SQLite DB、ロック、停止フラグ、ログ（gitignore）
│   └── logs/
├── tmp/             # サンプルHTML置き場
├── index.php        # Web進捗ダッシュボード（Basic認証必須）
├── .htaccess        # Basic認証＋機密ファイル deny
└── .htpasswd.example
```

## セットアップ

```bash
composer install
php bin/init-db.php
```

設定は env で上書き可能（`config/config.php` がデフォルト）：

| 環境変数 | デフォルト | 説明 |
|---|---|---|
| `NAVII_TARGET_PREFS` | `13` | 対象都道府県（JIS X 0401、カンマ区切り）|
| `NAVII_TARGET_KBNS` | `2` | 対象 kikanKbn（1=病院, 2=診療所, 3=歯科, 4=助産所）|
| `NAVII_MAX_PER_RUN` | `10` | 1cron 実行で取得する詳細ページ数 |
| `NAVII_SLEEP_MIN` | `3` | リクエスト間スリープ最小秒 |
| `NAVII_SLEEP_MAX` | `10` | リクエスト間スリープ最大秒 |

## 使い方

### 一覧ページのクロール（1回1ページ）

```bash
NAVII_TARGET_PREFS=13 php bin/crawl-list.php
```

各 pref で `page=0` から順に取得。1ページ20件、URLの`page=`は0オリジン。

### 詳細ページのクロール

```bash
NAVII_TARGET_KBNS=2 NAVII_MAX_PER_RUN=10 php bin/crawl-detail.php
```

`detail_state.status='pending'` または `'error' で retry_count < 3 かつ next_attempt_at <= now`
のレコードを最大 `NAVII_MAX_PER_RUN` 件処理する。

### 進捗確認

```bash
php bin/status.php
```

Web からも見たい場合は `/index.php` を Basic 認証経由で開く。

### テスト用にクリーンアップ

```bash
php bin/reset-state.php --yes              # facilities 含めて全消し
php bin/reset-state.php --yes --keep-html  # facilities と html は残してステータスだけ pending に戻す
```

## SQLite スキーマ概要

| テーブル | 役割 |
|---|---|
| `facilities` | 1施設1レコード。詳細ページの **生HTML（非圧縮 BLOB）** + メタ情報 + ステータスをすべてここに集約 |
| `list_progress` | (pref_cd, page) 単位での一覧クロール進捗 |
| `fetch_log` | 全HTTPリクエストの監査ログ |

`facilities` 主要カラム：

| カラム | 用途 |
|---|---|
| `kikan_cd`, `pref_cd`, `kikan_kbn` | 主キー（複合） |
| `html` | 詳細ページの生HTML（BLOB、非圧縮） |
| `content_hash` | 正規化済みHTMLの sha256（差分判定用） |
| `bytes`, `http_status` | 取得時のメタ |
| `first_seen_at` / `last_seen_at` | 一覧で見つけた最初／最新の時刻 |
| `last_scraped_at` | 詳細ページを取得した最新時刻 |
| `last_changed_at` | HTML 内容（hash）が変化した最新時刻 |
| `status` | `pending` / `done` / `error` / `skip` |
| `retry_count`, `last_error`, `next_attempt_at` | エラーバックオフ用 |

設計方針：
- **1施設1レコード**。最新のHTMLだけを保持（履歴は持たない）
- **生HTML を BLOB のまま保存**（一次ソースとしてダウンロードして使う用途のため、加工しない）
- 差分判定は `?timeAt=...` キャッシュバスタータイムスタンプと `_csrf` トークンを除いた正規化済みHTMLの sha256 を比較（`src/HtmlNormalizer.php`）
- HTML が変化していなくても `last_scraped_at` は毎回更新、変化した時だけ `last_changed_at` を更新

DBダウンロード後の使い方例：
```sql
-- ある施設の最新HTMLを取り出す
SELECT html FROM facilities WHERE kikan_cd = '2131064160';

-- 直近24時間で変化があった施設
SELECT kikan_cd, last_changed_at FROM facilities
WHERE last_changed_at >= datetime('now', '+9 hours', '-1 day');

-- まだ詳細未取得の施設数
SELECT COUNT(*) FROM facilities WHERE html IS NULL;
```

## 礼儀正しいクロールの方針

- **UserAgent**: `NaviiDataBot/0.1 (+mailto:koizumi@hospita.jp; purpose=research; contact for opt-out)`
  サイト管理者が問い合わせ可能なメールアドレスを明示
- **同時接続**: 常に1。`flock` で二重起動防止
- **リクエスト間隔**: 3〜10秒のランダムスリープ
- **429/403**: 即停止。`data/.stop` ファイルを作成し、人手で確認・削除するまで以降の実行は即終了
- **5xx**: 指数バックオフ（1分→3分→9分、最大3回）
- **連続エラー閾値**: 5件連続失敗で `.stop` 自動作成（`CircuitBreaker`）
- **差分のみ取得**: 同一HTMLなら snapshot を追記しない

## レンタルサーバー（Xserver等）への配置

### 1. ファイルをアップロード

`vendor/` も含めて `public_html/navii-data/` 等にアップロード（または SSH で `composer install`）。

### 2. Basic 認証用 .htpasswd を作成

```bash
htpasswd -B -c .htpasswd <ユーザー名>
```

`-B` で bcrypt（推奨）。生成された `.htpasswd` のパスを `.htaccess` の `AuthUserFile` に絶対パスで書く：

```apache
AuthUserFile /home/<アカウント>/<ドメイン>/public_html/navii-data/.htpasswd
```

### 3. DB初期化

```bash
php bin/init-db.php
```

### 4. Web 確認

ブラウザで `https://<ドメイン>/navii-data/` にアクセス → Basic 認証ダイアログ → 認証成功で
進捗ダッシュボードが表示されることを確認。

`/navii-data/data/navii.sqlite` への直アクセスが 403 になることも確認（`.htaccess` の `FilesMatch` が
sqlite ファイルを deny する）。

### 5. cron 設定例

```cron
# 5分おきに一覧を1ページ取得
*/5 * * * * /usr/bin/php /home/<account>/<domain>/public_html/navii-data/bin/crawl-list.php >> /home/<account>/<domain>/public_html/navii-data/data/logs/list.log 2>&1

# 2分おきに詳細を最大10件取得
*/2 * * * * /usr/bin/php /home/<account>/<domain>/public_html/navii-data/bin/crawl-detail.php >> /home/<account>/<domain>/public_html/navii-data/data/logs/detail.log 2>&1
```

実行ペースは初期は控えめにして、`bin/status.php` でエラー率を監視しながら調整する。

## トラブル時の操作

| 状況 | 対処 |
|---|---|
| 即停止したい | `touch data/.stop` する。以降のクロールは即終了する |
| 停止解除 | `rm data/.stop` |
| 連続エラーで進まない | `bin/status.php` で `fetch_log` のエラー内容を確認 → 原因対処 → `.stop` 削除 → 必要に応じて `UPDATE facilities SET status='pending', retry_count=0 WHERE status='error'` |
| 全消ししてやり直し | `php bin/reset-state.php --yes` |

## 利用規約上の留意

- 「医療情報ネット」利用規約に基づき、転載時の出所明記が必須
- 「運営に支障を及ぼす行為」は禁止 → 本ツールの設定（リクエスト間隔・429時即停止）はその準拠を意図したもの
- 設定値を緩める場合はサイト負荷を確認しつつ慎重に
