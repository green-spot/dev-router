---
title: "アーキテクチャ設計"
description: "DevRouter の全体構成・VirtualHost 生成方式・Apache 設定・データ管理の設計をまとめる"
status: "draft"
created_at: "2026-02-25"
updated_at: "2026-02-26"
refs:
  - "requirements/overview.md"
  - "requirements/features.md"
  - "decisions/graceful-restart-mechanism.md"
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
   └ ルート VirtualHost（ServerName {slug}.{base-domain}）← 自動生成
        ├ ディレクトリ公開 → DocumentRoot 設定
        ├ リバースプロキシ → ProxyPass
        └ リダイレクト → RewriteRule [R=302]
```

Apache はサブドメインごとに独立した VirtualHost を生成し、名前ベースの VirtualHost マッチングでルーティングを行う。

store.php がルーティングデータ（routes.json）から VirtualHost 定義ファイル（routes.conf / routes-ssl.conf）を自動生成し、graceful restart で反映する。

## 2. 核となる技術

| 技術 | 用途 |
| --- | --- |
| 名前ベース VirtualHost | サブドメインごとのルーティング |
| mod_rewrite | リダイレクト（ベースドメイン → 管理UI）|
| mod_proxy / mod_proxy_http / mod_proxy_wstunnel | リバースプロキシ・WebSocket |
| mod_headers | X-Forwarded-Proto 設定 |
| mod_ssl | SSL 有効化時のみ |
| PHP（mod_php / php-fpm） | 管理 API バックエンド + VirtualHost 定義の自動生成 |
| ワイルドカード DNS（nip.io / dnsmasq 等） | サブドメイン解決 |

> **判断理由**: 旧方式（RewriteMap txt: + 単一 VirtualHost）は MAMP 環境で RewriteMap が動作しない問題と、1つの VirtualHost ではターゲットの .htaccess が正常に適用されない設計上の限界があった。VirtualHost 生成方式はこれらを根本的に解決する。詳細は [RewriteMap 廃止の判断記録](../decisions/rewritemap-to-vhost.md) を参照。

---

## 3. ルーティングメカニズム

### 3.1 VirtualHost 生成方式

PHP の `saveState()` がルーティングデータの変更時に以下を実行する:

1. routes.json をアトミック書き込み
2. routes.conf（HTTP VirtualHost）を生成
3. routes-ssl.conf（HTTPS VirtualHost）を生成（SSL 有効時のみ）
4. Apache graceful restart を実行

### 3.2 VirtualHost 生成ロジック

`generateRoutesConf()` 関数が以下の順で VirtualHost を生成する:

1. **ベースドメイン直アクセス** → リダイレクト VirtualHost（管理UIへ 302）
2. **明示登録**（スラグ指定・リバースプロキシ）→ 全ベースドメインとの組み合わせで VirtualHost 生成
3. **グループ解決**（登録順に走査、先にマッチしたグループが優先）→ 全ベースドメインとの組み合わせで VirtualHost 生成

明示登録スラグと同名のサブディレクトリがある場合、明示登録が優先される。

### 3.3 VirtualHost の種別

| 種別 | 生成される設定 |
| --- | --- |
| ディレクトリ公開 | `DocumentRoot` + `<Directory>` + `AllowOverride All` |
| リバースプロキシ | `ProxyPass` + `ProxyPassReverse` + `ProxyPreserveHost On` |
| WebSocket 対応プロキシ | RewriteRule による ws:// プロキシ + HTTP フォールバック |
| リダイレクト | `RewriteRule ^ {url} [R=302,L]` |

### 3.4 アトミック書き込み

routes.conf / routes-ssl.conf は一時ファイルに書き込んでから `rename()` でアトミック置換する。書き込み中のクラッシュによるファイル破損を防止する。

---

## 4. ルーティング優先順位

Apache の名前ベース VirtualHost マッチングにより以下の順で解決する。

1. **管理 UI** — `ServerName localhost`（`ServerAlias 127.0.0.1 [::1]`）
   - `/api/*.php` → PHP 実行（Apache 直接）
   - それ以外 → `{ROUTER_HOME}/public/` から静的ファイルを配信
2. **ルート VirtualHost** — `ServerName {slug}.{base-domain}` にマッチ
   - ディレクトリ公開 → DocumentRoot からファイル配信（.htaccess 完全対応）
   - リバースプロキシ → ProxyPass でバックエンドに転送
   - リダイレクト → 302 リダイレクト
3. **デフォルト VirtualHost** — マッチなし → 404 ページを返す

サブドメインは1階層のみ対応する（フラット名前空間）。
2階層以上（sub.site.base-domain）は VirtualHost が存在せず、デフォルト VirtualHost が 404 を返す。

---

## 5. Apache 設定構造

### 5.1 vhost-http.conf（常時有効）

```apache
# デフォルト VirtualHost（名前ベース VirtualHost のフォールバック）
# 最初に定義された VirtualHost がデフォルトになる
<VirtualHost *:80>
    DocumentRoot {ROUTER_HOME}/public/default
    <Directory {ROUTER_HOME}/public/default>
        Require all granted
    </Directory>
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
```

### 5.2 vhost-https.conf（SSL 有効時のみ）

```apache
# デフォルト VirtualHost（HTTPS）
<VirtualHost *:443>
    SSLEngine on
    SSLCertificateFile {ROUTER_HOME}/ssl/cert.pem
    SSLCertificateKeyFile {ROUTER_HOME}/ssl/key.pem
    DocumentRoot {ROUTER_HOME}/public/default
    <Directory {ROUTER_HOME}/public/default>
        Require all granted
    </Directory>
</VirtualHost>

# 管理UI（HTTPS）
<VirtualHost *:443>
    ServerName localhost
    ServerAlias 127.0.0.1 [::1]
    SSLEngine on
    SSLCertificateFile {ROUTER_HOME}/ssl/cert.pem
    SSLCertificateKeyFile {ROUTER_HOME}/ssl/key.pem
    DocumentRoot {ROUTER_HOME}/public
    <Directory {ROUTER_HOME}/public>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    DirectoryIndex index.php index.html index.htm
    RequestHeader set X-Forwarded-Proto "https"
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

# --- ディレクトリ公開 ---
<VirtualHost *:80>
    ServerName test1.127.0.0.1.nip.io
    DocumentRoot /private/var/vh/sites/dev/test1
    <Directory /private/var/vh/sites/dev/test1>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    DirectoryIndex index.php index.html index.htm
</VirtualHost>

# --- リバースプロキシ ---
<IfModule mod_proxy.c>
<VirtualHost *:80>
    ServerName api.127.0.0.1.nip.io
    ProxyPreserveHost On
    ProxyPass / http://localhost:3000/
    ProxyPassReverse / http://localhost:3000/
    RequestHeader set X-Forwarded-Proto "http"
</VirtualHost>
</IfModule>

# --- WebSocket 対応プロキシ ---
<IfModule mod_proxy.c>
<VirtualHost *:80>
    ServerName vite.127.0.0.1.nip.io
    ProxyPreserveHost On
    RewriteEngine On
    RewriteCond %{HTTP:Upgrade} =websocket [NC]
    RewriteRule ^(.*)$ ws://localhost:5173$1 [P,L]
    RewriteRule ^(.*)$ http://localhost:5173$1 [P,L]
    ProxyPassReverse / http://localhost:5173/
    RequestHeader set X-Forwarded-Proto "http"
</VirtualHost>
</IfModule>
```

> **mod_proxy 未導入時の安全性**: プロキシ VirtualHost を `<IfModule mod_proxy.c>` で囲むことで、mod_proxy が未導入の環境でも Apache の起動エラーを防ぐ。プロキシルートのみが無効化され、ディレクトリ公開ルートは正常に動作する。

---

## 6. Graceful Restart 機構

### 6.1 概要

VirtualHost 生成方式では、設定変更のたびに Apache graceful restart が必要になる。PHP（Apache ワーカープロセス）から root 権限の Apache マスタープロセスに USR1 シグナルを送信するため、専用のラッパースクリプトと sudoers 設定を使用する。

### 6.2 コンポーネント

```
PHP (triggerGracefulRestart)
   ↓ /usr/bin/sudo -n
bin/graceful.sh（root で実行）
   ↓ conf/env.conf から HTTPD_BIN を読み込み
   ↓ ps + awk で root の Apache マスタープロセスを特定
   ↓ kill -USR1
Apache マスタープロセス
   → graceful restart（設定再読み込み）
```

| ファイル | 役割 | 生成元 |
| --- | --- | --- |
| `conf/env.conf` | Apache バイナリパス（`HTTPD_BIN`）の設定 | setup.sh が自動生成 |
| `bin/graceful.sh` | env.conf を読み込み、Apache マスタープロセスに USR1 を送信 | setup.sh がデプロイ |
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

> **判断理由**: `pgrep -u root -f "^${HTTPD_BIN}"` はターミナルからの直接実行では動作するが、PHP → sudo 経由で実行した場合に結果が空になる現象を確認した（macOS）。`ps + awk` による検索は同一条件で安定して動作するため、こちらをメインの検索方式として採用した。

### 6.5 セキュリティ設計

- sudoers は graceful.sh **1ファイルのみ**に NOPASSWD を限定
- graceful.sh が `source` する env.conf は root 所有（一般ユーザ書き込み不可）
- graceful.sh 自体も root 所有（setup.sh がデプロイ）
- 送信シグナルは USR1 のみ（graceful restart 専用）

---

## 7. ディレクトリアクセス許可

VirtualHost 生成方式では、各ルートの VirtualHost に `<Directory>` ディレクティブを個別に設定する。

```apache
<VirtualHost *:80>
    ServerName test1.127.0.0.1.nip.io
    DocumentRoot /path/to/test1
    <Directory /path/to/test1>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

`AllowOverride All` により、ターゲットディレクトリの .htaccess（WordPress のフロントコントローラ等）が正常に動作する。

### graceful restart が必要なタイミング

| 操作 | graceful 必要 |
| --- | --- |
| ルート変更（追加・削除・編集） | **必要** — routes.conf の再生成 + graceful |
| グループ配下へのサブディレクトリ追加 | **必要** — scan → routes.conf 再生成 + graceful |
| SSL 証明書の発行 | **必要** — routes-ssl.conf の生成 + graceful |

> **判断理由**: 旧方式（RewriteMap txt:）は routing.map の mtime 変更で即時反映されたが、VirtualHost 生成方式では Apache 設定ファイルの変更に graceful restart が必要になる。ただし graceful restart は 1 秒未満で完了し、既存の接続を中断しないため、ローカル開発用途では問題にならない。

---

## 8. リバースプロキシ設計

VirtualHost 生成方式では ProxyPass / ProxyPassReverse を静的に設定できる。

### ディレクティブ

```apache
<IfModule mod_proxy.c>
<VirtualHost *:80>
    ServerName api.127.0.0.1.nip.io
    ProxyPreserveHost On
    ProxyPass / http://localhost:3000/
    ProxyPassReverse / http://localhost:3000/
    RequestHeader set X-Forwarded-Proto "http"
</VirtualHost>
</IfModule>
```

### WebSocket 対応

```apache
<IfModule mod_proxy.c>
<VirtualHost *:80>
    ServerName vite.127.0.0.1.nip.io
    ProxyPreserveHost On
    RewriteEngine On
    RewriteCond %{HTTP:Upgrade} =websocket [NC]
    RewriteRule ^(.*)$ ws://localhost:5173$1 [P,L]
    RewriteRule ^(.*)$ http://localhost:5173$1 [P,L]
    ProxyPassReverse / http://localhost:5173/
    RequestHeader set X-Forwarded-Proto "http"
</VirtualHost>
</IfModule>
```

`Upgrade: websocket` ヘッダの有無で ws:// と http:// を切り替える。HMR（Hot Module Replacement）での WebSocket と通常 HTTP リクエストを同一ドメインで処理する。

> **判断理由**: 旧方式（単一 VirtualHost + RewriteRule でプロキシ）では `ProxyPassReverse` を静的に設定できなかった。VirtualHost 生成方式では各ルートが独立した VirtualHost を持つため、`ProxyPassReverse` を正しく設定でき、Location ヘッダの書き換えが正常に動作する。

---

## 9. データ管理

### routes.json の構造

```json
{
  "baseDomains": [
    {
      "domain": "127.0.0.1.nip.io",
      "current": true,
      "ssl": true
    },
    {
      "domain": "dev.local",
      "current": false,
      "ssl": false
    }
  ],
  "groups": [
    {
      "path": "/Users/me/sites/companyA"
    },
    {
      "path": "/Users/me/sites/companyB"
    }
  ],
  "routes": [
    {
      "slug": "myapp",
      "target": "/Users/me/sites/companyA/app/public",
      "type": "directory"
    },
    {
      "slug": "vite",
      "target": "http://localhost:5173",
      "type": "proxy"
    }
  ]
}
```

### 状態同期フロー

PHP Admin API からの変更は以下の順で反映される:

1. routes.json のバックアップ作成
2. routes.json にアトミック書き込み（一時ファイル + rename）
3. routes.conf を再生成（HTTP VirtualHost 定義）
4. routes-ssl.conf を再生成（HTTPS VirtualHost 定義、SSL 有効時のみ）
5. Apache graceful restart を実行
6. 次のリクエストから新しい VirtualHost 定義が有効になる

`saveState()` 内で routes.json と routes.conf / routes-ssl.conf の更新を常にセットで行うため、不整合は発生しない。

### ルーティング設定の再生成タイミング

| タイミング | トリガー |
| --- | --- |
| ルート変更時 | `saveState()` 内で常に再生成 + graceful |
| 管理 UI からの手動スキャン | `/api/scan.php` → `saveState()` → 再生成 + graceful |

---

## 10. Admin API 設計

Admin API は Apache が直接実行するプレーン PHP ファイルで構成する。
フレームワーク不要。Unix socket 不要。プロセス管理不要。

### エンドポイント

| エンドポイント | メソッド | 機能 |
| --- | --- | --- |
| `/api/health.php` | GET | ヘルスチェック |
| `/api/routes.php` | GET / POST / DELETE | スラグ指定・リバースプロキシの CRUD |
| `/api/groups.php` | GET / POST / PUT / DELETE | グループの CRUD + 優先順位変更 |
| `/api/domains.php` | GET / POST / DELETE | ベースドメインの CRUD + current 切替 |
| `/api/ssl.php` | GET / POST | SSL 状態確認・証明書発行 |
| `/api/env-check.php` | GET | 環境チェック（apachectl -M 等） |
| `/api/scan.php` | POST | グループディレクトリの手動スキャン → routes.conf 再生成 |

すべての API は管理 UI（localhost）からのみアクセス可能。

---

## 11. SSL 設定

### 構造

SSL 有効化時は vhost-https.conf と routes-ssl.conf の2ファイルが使用される。

- **vhost-https.conf** — setup.sh 実行後、ユーザーが手動で httpd.conf に Include を追加
- **routes-ssl.conf** — store.php が自動生成。SSL 有効なベースドメインの HTTPS VirtualHost を含む

### routes-ssl.conf の生成例

```apache
# 自動生成 — 手動編集禁止

<VirtualHost *:443>
    ServerName test1.127.0.0.1.nip.io
    SSLEngine on
    SSLCertificateFile {ROUTER_HOME}/ssl/cert.pem
    SSLCertificateKeyFile {ROUTER_HOME}/ssl/key.pem
    DocumentRoot /private/var/vh/sites/dev/test1
    <Directory /private/var/vh/sites/dev/test1>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    DirectoryIndex index.php index.html index.htm
</VirtualHost>

<IfModule mod_proxy.c>
<VirtualHost *:443>
    ServerName api.127.0.0.1.nip.io
    SSLEngine on
    SSLCertificateFile {ROUTER_HOME}/ssl/cert.pem
    SSLCertificateKeyFile {ROUTER_HOME}/ssl/key.pem
    ProxyPreserveHost On
    ProxyPass / http://localhost:3000/
    ProxyPassReverse / http://localhost:3000/
    RequestHeader set X-Forwarded-Proto "https"
</VirtualHost>
</IfModule>
```

証明書ファイルが存在しない状態で VirtualHost を記述すると Apache が起動エラーとなるため、SSL 有効化前には vhost-https.conf を Include しない。

---

## 12. ファイル構成

```
{ROUTER_HOME}/
  public/                  ← DocumentRoot（管理UI + API）
    index.html             ← 管理UI フロントエンド
    css/
    js/
    default/               ← デフォルト VirtualHost 用 404 ページ
    api/                   ← PHP Admin API
      health.php
      routes.php
      groups.php
      domains.php
      ssl.php
      env-check.php
      scan.php
      lib/
        store.php          ← routes.json 読み書き + routes.conf 生成 + graceful restart
  bin/
    graceful.sh            ← Apache graceful restart ラッパー（root 所有、sudoers で許可）
  conf/
    vhost-http.conf        ← HTTP VirtualHost 設定（デフォルト + 管理UI + Include routes.conf）
    vhost-https.conf       ← HTTPS VirtualHost 設定（SSL 有効時のみ使用）
    env.conf               ← Apache 環境設定（HTTPD_BIN 等、setup.sh が自動生成）
  data/
    routes.json            ← ルーティングデータ（永続化）
    routes.json.bak        ← バックアップ
    routes.conf            ← HTTP VirtualHost 定義（自動生成）
    routes-ssl.conf        ← HTTPS VirtualHost 定義（自動生成、SSL 有効時のみ）
  ssl/                     ← SSL 証明書（オプション）
    cert.pem
    key.pem
```

本システムは DB アプリではなく**設定ファイルオーケストレータ**として設計する。
ルーティングの真実は routes.conf / routes-ssl.conf（VirtualHost 定義）であり、routes.json は管理用のバックストアである。
