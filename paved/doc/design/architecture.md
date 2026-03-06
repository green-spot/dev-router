---
title: "アーキテクチャ設計"
description: "DevRouter の全体構成・VirtualHost 生成方式・Apache 設定・データ管理の設計をまとめる"
status: "review"
created_at: "2026-02-25 00:00:00"
updated_at: "2026-03-05 13:39:21"
refs:
  - "requirements/overview.md"
  - "requirements/features.md"
  - "decisions/graceful-restart-mechanism.md"
  - "decisions/rewritemap-to-vhost.md"
---

# アーキテクチャ設計

## 1. 全体構成

```
Browser
   ↓
Apache (ポート 80 / SSL有効時は 443 も)
   ├ デフォルト VirtualHost（マッチしないホスト → 404）
   ├ 管理UI VirtualHost（ServerName localhost）
   │    ├ /api/*.php → PHP 実行
   │    └ それ以外 → 管理UI 静的ファイル配信
   ├ 明示ルート VirtualHost（ServerName {slug}.{base-domain}）← 自動生成
   │    ├ ディレクトリ公開 → DocumentRoot 設定
   │    └ リバースプロキシ → ProxyPass + WebSocket 対応
   └ グループ ワイルドカード VirtualHost（ServerAlias *.{group}.{base-domain}）← 自動生成
        └ VirtualDocumentRoot でサブドメインからディレクトリを動的解決
```

明示登録ルートは個別の VirtualHost として生成し、グループはワイルドカード VirtualHost + `VirtualDocumentRoot` で Apache がサブドメインからディレクトリを動的に解決する。

store.php がルーティングデータ（routes.json）から VirtualHost 定義ファイル（routes.conf / routes-ssl.conf）を自動生成し、graceful restart で反映する。

## 2. 核となる技術

| 技術 | 用途 |
| --- | --- |
| 名前ベース VirtualHost | サブドメインごとのルーティング |
| mod_vhost_alias / VirtualDocumentRoot | グループ内サブドメインの動的ディレクトリ解決 |
| mod_rewrite | リダイレクト（ベースドメイン → 管理UI）|
| mod_proxy / mod_proxy_http / mod_proxy_wstunnel | リバースプロキシ・WebSocket |
| mod_headers | X-Forwarded-Proto 設定 |
| mod_ssl | SSL 有効化時のみ |
| PHP（mod_php / php-fpm） | 管理 API バックエンド + VirtualHost 定義の自動生成 |
| ワイルドカード DNS（nip.io / dnsmasq 等） | サブドメイン解決 |

設計経緯は [RewriteMap 廃止の判断記録](../decisions/rewritemap-to-vhost.md) を参照。

---

## 3. ルーティングメカニズム

### 3.1 VirtualHost 生成方式

PHP の `saveState()` がルーティングデータの変更時に以下を実行する:

1. routes.json をアトミック書き込み
2. routes.conf（HTTP VirtualHost）を生成
3. routes-ssl.conf（HTTPS VirtualHost）を生成
4. Apache graceful restart を実行

### 3.2 VirtualHost 生成ロジック

`generateRoutesConf()` 関数が以下の順で VirtualHost を生成する:

1. **ベースドメイン直アクセス** → リダイレクト VirtualHost（管理UIへ 302）
2. **明示登録ルート**（スラグ指定・リバースプロキシ）× 全ベースドメイン → 個別 VirtualHost（ワイルドカードより先に記述 = 優先）
3. **グループ** × 全ベースドメイン → ワイルドカード VirtualHost（`ServerAlias *.{group}.{domain}` + `VirtualDocumentRoot {path}/%1`）

グループ内のサブディレクトリは `VirtualDocumentRoot` により Apache が動的に解決するため、プロジェクト追加時に routes.conf の再生成は不要。

### 3.3 VirtualHost の種別

| 種別 | 生成される設定 |
| --- | --- |
| ディレクトリ公開（明示ルート） | `DocumentRoot` + `<Directory>` + `AllowOverride All` |
| リバースプロキシ（明示ルート） | `ProxyPass` + `ProxyPassReverse` + WebSocket 対応（RewriteCond による ws:// 切り替え） |
| グループ（ワイルドカード） | `VirtualDocumentRoot {path}/%1` + `<Directory>` + `AllowOverride All` |
| リダイレクト（ベースドメイン） | `RewriteRule ^ {url} [R=302,L]` |

### 3.4 アトミック書き込み

routes.conf / routes-ssl.conf は一時ファイルに書き込んでから `rename()` でアトミック置換する。書き込み中のクラッシュによるファイル破損を防止する。

---

## 4. ルーティング優先順位

Apache の名前ベース VirtualHost マッチングにより以下の順で解決する。

1. **管理 UI** — `ServerName localhost`（`ServerAlias 127.0.0.1 [::1]`）
   - `/api/*.php` → PHP 実行（Apache 直接）
   - それ以外 → `{ROUTER_HOME}/public/` から静的ファイルを配信
2. **明示ルート** — `ServerName {slug}.{base-domain}` にマッチ（1階層サブドメイン）
   - ディレクトリ公開 → DocumentRoot からファイル配信（.htaccess 完全対応）
   - リバースプロキシ → ProxyPass でバックエンドに転送
3. **グループ** — `ServerAlias *.{group}.{base-domain}` にマッチ（2階層サブドメイン）
   - VirtualDocumentRoot でサブドメインからディレクトリを動的解決
4. **デフォルト VirtualHost** — マッチなし → 404 ページを返す

明示ルートは1階層（`slug.base-domain`）、グループは2階層（`subdir.group.base-domain`）のサブドメイン構造を持つ。

---

## 5. Apache 設定構造

### 5.1 vhost-http.conf（常時有効）

```apache
# デフォルト VirtualHost（名前ベース VirtualHost のフォールバック）
<VirtualHost *:80>
    ServerName devrouter-fallback.internal
    DocumentRoot {ROUTER_HOME}/public/default
    <Directory {ROUTER_HOME}/public/default>
        Require all granted
    </Directory>
    FallbackResource /index.html
    ErrorDocument 404 /index.html
</VirtualHost>

# 管理UI（localhost のみ）
<VirtualHost *:80>
    ServerName localhost
    ServerAlias 127.0.0.1 [::1]
    DocumentRoot {ROUTER_HOME}/public
    <Directory {ROUTER_HOME}/public>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    DirectoryIndex index.php index.html index.htm
    RequestHeader set X-Forwarded-Proto "http"
</VirtualHost>

# 自動生成ルート
Include {ROUTER_HOME}/data/routes.conf

# HTTPS（SSL 有効化時に自動展開される）
Include {ROUTER_HOME}/conf/vhost-https.conf
```

### 5.2 vhost-https.conf（SSL 有効化時に自動展開）

ssl.php の `deployHttpsVhost()` がテンプレートから `{ROUTER_HOME}` を実パスに置換して配置する。

```apache
Listen 443

# デフォルト VirtualHost（HTTPS）
<VirtualHost *:443>
    ServerName devrouter-fallback.internal
    SSLEngine on
    SSLCertificateFile {ROUTER_HOME}/ssl/cert.pem
    SSLCertificateKeyFile {ROUTER_HOME}/ssl/key.pem
    DocumentRoot {ROUTER_HOME}/public/default
    ...
</VirtualHost>

# 管理UI（HTTPS）
<VirtualHost *:443>
    ServerName localhost
    ServerAlias 127.0.0.1 [::1]
    SSLEngine on
    ...
</VirtualHost>

# 自動生成ルート（HTTPS）
Include {ROUTER_HOME}/data/routes-ssl.conf
```

### 5.3 routes.conf の生成例

```apache
# 自動生成 — 手動編集禁止

# --- ベースドメイン → 管理UIへリダイレクト ---
<VirtualHost *:80>
    ServerName 127.0.0.1.nip.io
    RewriteEngine On
    RewriteRule ^ http://localhost [R=302,L]
</VirtualHost>

# --- ディレクトリ公開（明示ルート）---
<VirtualHost *:80>
    ServerName myapp.127.0.0.1.nip.io
    DocumentRoot /Users/me/sites/myapp
    <Directory /Users/me/sites/myapp>
        Options FollowSymLinks Indexes
        AllowOverride All
        Require all granted
    </Directory>
    DirectoryIndex index.php index.html index.htm
</VirtualHost>

# --- リバースプロキシ（WebSocket 対応）---
<IfModule mod_proxy.c>
<VirtualHost *:80>
    ServerName api.127.0.0.1.nip.io
    ProxyPreserveHost On
    RewriteEngine On
    RewriteCond %{HTTP:Upgrade} =websocket [NC]
    RewriteRule ^(.*)$ ws://localhost:3000$1 [P,L]
    ProxyPass / http://localhost:3000/
    ProxyPassReverse / http://localhost:3000/
    RequestHeader set X-Forwarded-Proto "http"
</VirtualHost>
</IfModule>

# --- グループ "projects" ワイルドカード VirtualHost ---
<VirtualHost *:80>
    ServerAlias *.projects.127.0.0.1.nip.io
    VirtualDocumentRoot /Users/me/sites/projects/%1
    <Directory /Users/me/sites/projects>
        Options FollowSymLinks Indexes
        AllowOverride All
        Require all granted
    </Directory>
    DirectoryIndex index.php index.html index.htm
</VirtualHost>
```

> **mod_proxy 未導入時の安全性**: プロキシ VirtualHost を `<IfModule mod_proxy.c>` で囲むことで、mod_proxy が未導入の環境でも Apache の起動エラーを防ぐ。プロキシルートのみが無効化され、他のルートは正常に動作する。

---

## 6. Graceful Restart 機構

### 6.1 概要

VirtualHost 生成方式では、設定変更のたびに Apache graceful restart が必要になる。PHP（Apache ワーカープロセス）から root 権限の Apache マスタープロセスに USR1 シグナルを送信するため、専用のラッパースクリプトと sudoers 設定を使用する。

### 6.2 コンポーネント

```
PHP (triggerGracefulRestart)
   ↓ /usr/bin/sudo -n
bin/graceful.sh（root で実行）
   ↓ conf/env.conf から HTTPD_BIN を安全に抽出（grep）
   ↓ ps + awk で root の Apache マスタープロセスを特定
   ↓ kill -USR1
Apache マスタープロセス
   → graceful restart（設定再読み込み）
```

| ファイル | 役割 | 生成元 |
| --- | --- | --- |
| `conf/env.conf` | Apache バイナリパス（`HTTPD_BIN`）の設定 | setup.sh が自動生成 |
| `bin/graceful.sh` | env.conf から安全にパースし、Apache マスタープロセスに USR1 を送信 | setup.sh がデプロイ |
| `/etc/sudoers.d/dev-router` | PHP 実行ユーザに graceful.sh の NOPASSWD 実行を許可 | setup.sh が設定 |

### 6.3 setup.sh の自動検出

setup.sh は以下の情報を実行中の Apache プロセスから自動検出する。Apache が起動していない場合は対話式で入力を求める。

| 検出対象 | 検出方法 | 用途 |
| --- | --- | --- |
| Apache バイナリパス | root の httpd/apache2 プロセスのコマンドから取得 | env.conf に書き出し |
| Apache ワーカーユーザ | 非 root の httpd/apache2 プロセスのユーザから取得 | sudoers に設定 |

環境変数 `APACHE_USER` で手動指定も可能。

### 6.4 プロセス検索方式

`pgrep -f` は macOS で sudo 経由実行時にプロセスを検出できないケースがあるため、`ps -eo pid,user,command | awk` を使用する。

### 6.5 セキュリティ設計

- sudoers は graceful.sh **1ファイルのみ**に NOPASSWD を限定
- graceful.sh は env.conf を `source` せず、`grep` で `HTTPD_BIN` のみを安全に抽出する
- conf/env.conf と graceful.sh は root 所有（一般ユーザ書き込み不可）
- 送信シグナルは USR1 のみ（graceful restart 専用）

### 6.6 クールダウン

`triggerGracefulRestart()` は2秒のクールダウンを設け、連続呼び出し時はスキップして pending フラグを設定する。次回クールダウン経過後に実行される。

---

## 7. ディレクトリアクセス許可

各ルートの VirtualHost に `<Directory>` ディレクティブを個別に設定する。

```apache
# 明示ルート
<VirtualHost *:80>
    ServerName myapp.127.0.0.1.nip.io
    DocumentRoot /path/to/myapp
    <Directory /path/to/myapp>
        Options FollowSymLinks Indexes
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>

# グループ（ワイルドカード）
<VirtualHost *:80>
    ServerAlias *.projects.127.0.0.1.nip.io
    VirtualDocumentRoot /path/to/projects/%1
    <Directory /path/to/projects>
        Options FollowSymLinks Indexes
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

`AllowOverride All` により、ターゲットディレクトリの .htaccess（WordPress のフロントコントローラ等）が正常に動作する。

### graceful restart が必要なタイミング

| 操作 | graceful 必要 |
| --- | --- |
| 明示ルート変更（追加・削除・編集） | **必要** — routes.conf の再生成 + graceful |
| グループ変更（追加・削除） | **必要** — routes.conf の再生成 + graceful |
| グループ配下へのサブディレクトリ追加 | **不要** — VirtualDocumentRoot が動的に解決 |
| SSL 証明書の発行 | **必要** — routes-ssl.conf の生成 + graceful |

---

## 8. リバースプロキシ設計

明示ルートの VirtualHost に ProxyPass / ProxyPassReverse を静的に設定する。全てのプロキシルートは WebSocket 対応（RewriteCond による ws:// 切り替え）を含む。

```apache
<IfModule mod_proxy.c>
<VirtualHost *:80>
    ServerName api.127.0.0.1.nip.io
    ProxyPreserveHost On
    RewriteEngine On
    RewriteCond %{HTTP:Upgrade} =websocket [NC]
    RewriteRule ^(.*)$ ws://localhost:3000$1 [P,L]
    ProxyPass / http://localhost:3000/
    ProxyPassReverse / http://localhost:3000/
    RequestHeader set X-Forwarded-Proto "http"
</VirtualHost>
</IfModule>
```

`Upgrade: websocket` ヘッダの有無で ws:// と http:// を切り替える。HMR（Hot Module Replacement）での WebSocket と通常 HTTP リクエストを同一ドメインで処理する。

---

## 9. データ管理

### routes.json の構造

```json
{
  "baseDomains": [
    {
      "domain": "127.0.0.1.nip.io",
      "current": true,
      "ssl": false
    }
  ],
  "groups": [
    {
      "slug": "projects",
      "path": "/Users/me/sites/projects",
      "ssl": false,
      "label": ""
    }
  ],
  "routes": [
    {
      "slug": "myapp",
      "target": "/Users/me/sites/myapp",
      "type": "directory",
      "label": ""
    },
    {
      "slug": "api",
      "target": "http://localhost:3000",
      "type": "proxy",
      "label": "開発用 API"
    }
  ]
}
```

旧形式のデータは `migrateState()` が自動的に新形式にマイグレーションする（groups に `slug`/`ssl`/`label`、routes に `label` を付与）。

### 状態同期フロー

PHP Admin API からの変更は以下の順で反映される:

1. routes.json のバックアップ作成
2. routes.json にアトミック書き込み（一時ファイル + rename）
3. routes.conf を再生成（HTTP VirtualHost 定義）
4. routes-ssl.conf を再生成（HTTPS VirtualHost 定義）
5. Apache graceful restart を実行
6. 次のリクエストから新しい VirtualHost 定義が有効になる

`saveState()` 内で routes.json と routes.conf / routes-ssl.conf の更新を常にセットで行うため、不整合は発生しない。

---

## 10. Admin API 設計

Admin API は単一エントリポイント（`index.php`）+ 最小ルーターで構成する。
`PATH_INFO` ベースのルーティングにより、`.htaccess` の RewriteRule でリクエストを `index.php` に集約する。

### エンドポイント

| エンドポイント | メソッド | 機能 |
| --- | --- | --- |
| `/api/health` | GET | ヘルスチェック |
| `/api/routes` | GET / POST / DELETE | スラグ指定・リバースプロキシの CRUD |
| `/api/groups` | GET / POST / PUT / DELETE | グループの CRUD + 優先順位変更 |
| `/api/domains` | GET / POST / PUT / DELETE | ベースドメインの CRUD + current 切替 |
| `/api/ssl` | GET / POST | SSL 状態確認・証明書発行（type: "domain" / "group"） |
| `/api/env-check` | GET | 環境チェック（apachectl -M 等） |
| `/api/browse-dirs` | GET | ディレクトリブラウズ（オートコンプリート用） |

すべての API は管理 UI（localhost）からのみアクセス可能。Origin ヘッダ検証による CSRF 対策を実装済み。

---

## 11. SSL 設定

### 構造

SSL 有効化時は vhost-https.conf と routes-ssl.conf の2ファイルが使用される。

- **vhost-https.conf** — SSL 初回有効化時に ssl.php の `deployHttpsVhost()` がテンプレートから自動展開。vhost-http.conf から Include される
- **routes-ssl.conf** — store.php が自動生成。SSL 有効なドメイン・グループの HTTPS VirtualHost を含む

### SAN 構成

SSL 証明書は mkcert でワイルドカード証明書を1枚発行し、全サブドメインをカバーする。

- `baseDomains.ssl=true` → `*.{base-domain}`（明示ルート用）
- `groups.ssl=true` → `*.{group}.{base-domain}`（グループ用、全ドメイン分）

全 SAN を1枚の証明書にまとめるため、SSL 有効化の追加時には証明書を再発行する。

### routes-ssl.conf の生成ロジック

`generateRoutesSslConf()` は以下の順で HTTPS VirtualHost を生成する:

1. SSL 有効なベースドメインに対して: リダイレクト + 明示ルートの HTTPS VirtualHost
2. SSL 有効なグループに対して: 全ドメインでワイルドカード HTTPS VirtualHost

---

## 12. ファイル構成

```
{ROUTER_HOME}/
  public/                  ← DocumentRoot（管理UI + API）
    index.html             ← 管理UI フロントエンド
    css/
    js/
    default/               ← デフォルト VirtualHost 用 404 ページ
    api/                   ← PHP Admin API（単一エントリポイント）
      .htaccess            ← RewriteRule で index.php に集約
      index.php            ← 全エンドポイントのルーティング + ハンドラ定義
      lib/
        router.php         ← 最小ルーター（PATH_INFO ベース）+ リクエストヘルパー
        store.php          ← routes.json 読み書き + routes.conf 生成 + graceful restart
        route-resolver.php ← 明示ルート解決 + グループ情報構築
        vhost-generator.php← VirtualHost 設定生成（ワイルドカード対応）
        browse-helpers.php ← ディレクトリブラウズ用ユーティリティ
        env.php            ← 環境チェック用ヘルパー
        ssl.php            ← SSL 関連ヘルパー
        logger.php         ← ファイルベースのログ機構
  bin/
    graceful.sh            ← Apache graceful restart ラッパー（root 所有、sudoers で許可）
    smoke-test.sh          ← スモークテスト
  conf/
    vhost-http.conf        ← HTTP VirtualHost 設定テンプレート
    vhost-https.conf       ← HTTPS VirtualHost 設定（SSL 有効化時に自動展開）
    env.conf               ← Apache 環境設定（HTTPD_BIN 等、setup.sh が自動生成）
  data/
    routes.json            ← ルーティングデータ（永続化）
    routes.json.bak        ← バックアップ
    routes.conf            ← HTTP VirtualHost 定義（自動生成）
    routes-ssl.conf        ← HTTPS VirtualHost 定義（自動生成）
    logs/                  ← ログファイル
  ssl/                     ← SSL 証明書（オプション）
    cert.pem
    key.pem
  setup.sh                 ← 初期セットアップスクリプト
```

本システムは DB アプリではなく**設定ファイルオーケストレータ**として設計する。
ルーティングの真実は routes.conf / routes-ssl.conf（VirtualHost 定義）であり、routes.json は管理用のバックストアである。
