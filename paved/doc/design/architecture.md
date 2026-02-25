---
title: "アーキテクチャ設計"
description: "DevRouter の全体構成・ルーティングメカニズム・Apache 設定・データ管理の設計をまとめる"
status: "draft"
created_at: "2026-02-25"
updated_at: "2026-02-25"
refs:
  - "requirements/overview.md"
  - "requirements/features.md"
---

# アーキテクチャ設計

## 1. 全体構成

```
Browser
   ↓
Apache (ポート 80 / SSL有効時は 443 も)
   ├ Host: localhost or 127.0.0.1
   │    ├ /api/*.php → PHP 実行（mod_php / php-fpm、特別な設定不要）
   │    └ それ以外 → 管理UI 静的ファイル配信（Apache 直接）
   └ Host: *.{base-domain}
        ↓
      RewriteMap (txt: タイプ)
        → routing.map ファイルを参照（ホスト名 → ターゲット）
        ├ マッチ → 各アプリケーション / ディレクトリ
        └ マッチなし → resolve.php（再スキャン → 存在すればリダイレクト / なければ404）
```

Apache はファイルサーバではなく**HTTP ルーティング層**として動作する。
サイトごとに VirtualHost を増やさず、HTTP 用（:80）の1つで全サイトを処理する。
SSL 有効化時は HTTPS 用（:443）を追加し、同じルーティングルールを共有する。

## 2. 核となる技術

| 技術 | 用途 |
| --- | --- |
| mod_rewrite | ルーティングルール |
| RewriteMap（**txt:** タイプ） | ホスト名→ターゲットの静的マッピング |
| mod_proxy / mod_proxy_http / mod_proxy_wstunnel | リバースプロキシ・WebSocket |
| mod_headers | X-Forwarded-Proto 設定 |
| mod_ssl | SSL 有効化時のみ |
| PHP（mod_php / php-fpm） | 管理 API バックエンド + 未登録サブドメインの自動解決 |
| ワイルドカード DNS（nip.io / dnsmasq 等） | サブドメイン解決 |

---

## 3. ルーティングメカニズム

### 3.1 RewriteMap txt: 方式

`txt:` タイプの RewriteMap を使用する。
PHP Admin API がルート変更時に `routing.map` ファイルを再生成し、Apache がファイルの mtime 変更を検知して自動的に再読み込みする。

```apache
RewriteMap lc "int:tolower"
RewriteMap router "txt:{ROUTER_HOME}/data/routing.map"
```

#### routing.map の自動再読み込みの仕組み

Apache の `txt:` RewriteMap は、ルックアップのたびに `stat()` でファイルの mtime を確認する（`mod_rewrite.c` の `lookup_map()` 関数）。mtime が変わっていればキャッシュを全破棄し、ファイルを再読み込みする。ポーリング間隔や TTL は存在せず、**routing.map を書き換えた直後の次のリクエストで即座に新しい内容が使われる**。

`stat()` のコストはカーネルの dentry/inode キャッシュにヒットするため数マイクロ秒程度であり、ローカル開発用途では問題にならない。

#### routing.map の形式

```
# 自動生成 — 手動編集禁止
# ベースドメイン直アクセス → 管理UIへリダイレクト
127.0.0.1.nip.io R:http://localhost
dev.local R:http://localhost

# 明示登録（スラグ指定・リバースプロキシ）
myapp.127.0.0.1.nip.io /Users/me/sites/companyA/app/public
myapp.dev.local /Users/me/sites/companyA/app/public
vite.127.0.0.1.nip.io http://localhost:5173
vite.dev.local http://localhost:5173

# グループ解決（自動スキャン結果）
app.127.0.0.1.nip.io /Users/me/sites/companyA/app/public
app.dev.local /Users/me/sites/companyA/app/public
blog.127.0.0.1.nip.io /Users/me/sites/companyA/blog
blog.dev.local /Users/me/sites/companyA/blog
```

ホスト名とターゲットの1対1マッピング。ベースドメイン × ルートの全組み合わせを列挙する。

#### 大文字小文字の正規化

Apache 組み込みの `int:tolower` で処理する。

```apache
RewriteCond ${router:${lc:%{HTTP_HOST}}} ...
```

### 3.2 routing.map の生成ロジック

PHP の `generateRoutingMap()` 関数が以下の順で map を生成する:

1. ベースドメイン直アクセス → リダイレクトエントリ
2. 明示登録（スラグ指定・リバースプロキシ）→ 全ベースドメインとの組み合わせ
3. グループ解決（登録順に走査、先にマッチしたグループが優先）→ 全ベースドメインとの組み合わせ

明示登録スラグと同名のサブディレクトリがある場合、明示登録が優先される。

### 3.3 routing.map のアトミック書き込み

一時ファイルに書き込んでから `rename()` でアトミック置換する。書き込み中のクラッシュによるファイル破損を防止する。

---

## 4. ルーティング優先順位

リクエスト処理は以下の順で解決する。

1. **管理 UI** — Host が localhost or 127.0.0.1 の場合
   - `/api/*.php` → PHP 実行（Apache 直接）
   - それ以外 → `{ROUTER_HOME}/public/` から静的ファイルを配信
2. **routing.map 照合** — ホスト名を `txt:` RewriteMap で照合
   - マッチ → 環境変数 `ROUTE` に格納し、後続ルールで分岐
   - マッチなし → resolve.php で自動解決（再スキャン→リダイレクト or 404）
3. **ROUTE の分岐処理**:
   - `R:` プレフィックス → HTTP 302 リダイレクト
   - WebSocket（`Upgrade` ヘッダ検出時）→ `ws://` プロトコルでプロキシ
   - HTTP URL → リバースプロキシ
   - ディレクトリパス → ファイル配信
4. **フォールバック** — ROUTE 未設定の場合 404

サブドメインは1階層のみ対応する（フラット名前空間）。
2階層以上（sub.site.base-domain）は routing.map にエントリが存在せず、resolve.php が 404 を返す。

---

## 5. Apache ルーティングルール

```apache
# サーバコンフィグレベル（VirtualHost の外）
RewriteMap lc "int:tolower"
RewriteMap router "txt:{ROUTER_HOME}/data/routing.map"

# --- VirtualHost 共通ルール（routing-rules.conf） ---

DirectoryIndex index.php index.html index.htm
ProxyPreserveHost On
RewriteEngine On

# 1. 管理UI（localhost のみ）
#    API も静的ファイルも DocumentRoot 内のファイルとして直接配信
RewriteCond %{HTTP_HOST} ^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$
RewriteCond %{REMOTE_ADDR} ^(127\.0\.0\.1|::1)$
RewriteRule ^(.*)$ {ROUTER_HOME}/public$1 [L]

# 2. ルーター問い合わせ（txt: map 参照、結果を環境変数に格納）
RewriteCond ${router:${lc:%{HTTP_HOST}}} ^(.+)$
RewriteRule .* - [E=ROUTE:%1,NE]

# 3. マッチなし → resolve.php で自動解決
RewriteCond %{ENV:ROUTE} ^$
RewriteRule ^ {ROUTER_HOME}/public/resolve.php [L]

# 4. リダイレクト（ベースドメイン直アクセス → 管理UIへ）
RewriteCond %{ENV:ROUTE} ^R:(.+)$
RewriteRule ^ %1 [R=302,L]

# 5. WebSocket プロキシ（Upgrade ヘッダ検出時）
RewriteCond %{HTTP:Upgrade} =websocket [NC]
RewriteCond %{ENV:ROUTE} ^https?://(.+)
RewriteRule ^(.*)$ ws://%1$1 [P,L]

# 6. リバースプロキシ（HTTP URL）
RewriteCond %{ENV:ROUTE} ^(https?://.+)
RewriteRule ^(.*)$ %1$1 [P,L]

# 7. ディレクトリ公開（ファイルパス）
RewriteCond %{ENV:ROUTE} ^(/.+)
RewriteRule ^(.*)$ %1$1 [L]

# 8. フォールバック（ROUTE 未設定 — resolve.php が処理するため通常到達しない）
RewriteRule ^ - [R=404,L]
```

### 設計ポイント

- `txt:` RewriteMap でキーが見つからない場合、空文字列が返る。ステップ2の `^(.+)$` パターンにマッチしないため、ROUTE は設定されず、ステップ3の resolve.php にフォールスルーする
- Node への問い合わせと異なり、ファイル参照は必ず完了するため「未応答で Apache 全体ハング」のリスクはない
- VirtualHost コンテキストでは `[L]` フラグがルールの再実行を引き起こさないため、`REDIRECT_` プレフィックス問題は発生しない

---

## 6. ディレクトリアクセス許可

Apache がリライト先のユーザディレクトリを配信するには `<Directory>` による許可が必要である。

本システムはローカル開発専用であり、管理 UI は localhost のみアクセス可能であるため、VirtualHost 内でルートディレクトリに対して全許可を設定する。

```apache
<Directory />
    Options FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```

これにより、グループ登録・スラグ指定のたびに設定ファイルを再生成する必要がなく、**graceful なしで即座にルーティングが有効になる**。

### graceful が不要な根拠

| 操作 | graceful 不要の理由 |
| --- | --- |
| グループ登録 | routing.map の更新のみ。Apache 設定の変更を伴わない |
| スラグ指定・リバースプロキシ追加 | 同上 |
| グループ配下へのサブディレクトリ追加 | resolve.php が再スキャンし routing.map を更新 |
| ディレクトリアクセス許可 | `<Directory />` で全パスを許可済み |
| VirtualHost | 単一 VirtualHost で全サブドメインを処理する設計 |

graceful が必要なのは **SSL 証明書の発行時のみ**。

---

## 7. リバースプロキシ設計

Apache は単なる転送ではなく**アプリケーション環境の仮想化**を行う。

### 必須設定

```apache
ProxyPreserveHost On

# HTTP VirtualHost（ポート80）
RequestHeader set X-Forwarded-Proto "http"

# HTTPS VirtualHost（ポート443）— SSL有効時のみ
RequestHeader set X-Forwarded-Proto "https"
```

### Location 書換（ProxyPassReverse）

動的ルーティングでは `ProxyPassReverse` を静的に設定できないため、本システムでは設定しない。

`ProxyPreserveHost On` により、バックエンドは正しい Host ヘッダを受け取る。大多数のフレームワークは Host ヘッダを基にリダイレクト URL を生成するため、Location ヘッダは自動的に正しいドメインを含む。

### Cookie 対応

動的ルーティングでは `ProxyPassReverseCookieDomain` を静的に設定できないため、動的な Cookie 書き換え方法を別途検討する（保留事項）。

---

## 8. データ管理

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
3. routing.map を再生成（グループディレクトリの再スキャンを含む）
4. 次のリクエストで Apache が mtime 変更を検知し、新しい routing.map を自動読み込み

`saveState()` 内で routes.json と routing.map の更新を常にセットで行うため、不整合は発生しない。

### routing.map の再生成タイミング

| タイミング | トリガー |
| --- | --- |
| ルート変更時 | `saveState()` 内で常に再生成 |
| 未登録サブドメインアクセス時 | resolve.php が再スキャン→再生成 |
| 管理 UI 読み込み時 | フロントエンドが `/api/scan.php` を呼び出し |
| 手動スキャン | 管理 UI 上の「スキャン」ボタン |

---

## 9. Admin API 設計

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
| `/api/scan.php` | POST | グループディレクトリの手動スキャン→map 再生成 |
| `resolve.php` | — | 未登録サブドメインの自動解決 |

すべての API は管理 UI（localhost）からのみアクセス可能。

---

## 10. SSL 設定

### HTTPS VirtualHost

```apache
# サーバコンフィグレベル（VirtualHost の外）
RewriteMap lc "int:tolower"
RewriteMap router "txt:{ROUTER_HOME}/data/routing.map"

# --- HTTP VirtualHost（初期設定時に固定） ---
<VirtualHost *:80>
    Include {ROUTER_HOME}/conf/routing-rules.conf
    RequestHeader set X-Forwarded-Proto "http"
</VirtualHost>

# --- HTTPS VirtualHost（SSL 有効化時に追加） ---
<VirtualHost *:443>
    SSLEngine on
    SSLCertificateFile {ROUTER_HOME}/ssl/cert.pem
    SSLCertificateKeyFile {ROUTER_HOME}/ssl/key.pem

    Include {ROUTER_HOME}/conf/routing-rules.conf
    RequestHeader set X-Forwarded-Proto "https"
</VirtualHost>
```

HTTPS VirtualHost の設定は、初回の証明書発行時に自動追加し graceful で有効化する。
証明書ファイルが存在しない状態で VirtualHost を記述すると Apache が起動エラーとなるため、SSL 有効化前には含めない。

---

## 11. ファイル構成

```
{ROUTER_HOME}/
  public/                  ← DocumentRoot（管理UI + API + 自動解決）
    index.html             ← 管理UI フロントエンド
    resolve.php            ← 未登録サブドメインの自動解決
    css/
    js/
    api/                   ← PHP Admin API
      health.php
      routes.php
      groups.php
      domains.php
      ssl.php
      env-check.php
      scan.php
      lib/
        store.php          ← routes.json 読み書き + routing.map 生成
  conf/
    routing-rules.conf     ← Apache 共通ルーティングルール
  data/
    routes.json            ← ルーティングデータ（永続化）
    routes.json.bak        ← バックアップ
    routing.map            ← RewriteMap 用（自動生成）
  ssl/                     ← SSL 証明書（オプション）
    cert.pem
    key.pem
```

本システムは DB アプリではなく**設定ファイルオーケストレータ**として設計する。
ルーティングの真実は routing.map であり、routes.json は管理用のバックストアである。
