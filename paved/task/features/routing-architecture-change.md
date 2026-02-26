---
title: "ルーティング方式の変更: RewriteMap → VirtualHost 生成"
description: "RewriteMap txt: + 単一 VirtualHost 方式を廃止し、サブドメインごとに独立した VirtualHost を生成する方式に変更する"
status: "done"
priority: "P0"
created_at: "2026-02-25"
updated_at: "2026-02-25"
---

# ルーティング方式の変更: RewriteMap → VirtualHost 生成

## 背景・目的

現在の RewriteMap txt: + 単一 VirtualHost 方式には以下の問題がある:

- **MAMP 環境で RewriteMap が機能しない**（原因未特定だが再現性あり）
- **1つの VirtualHost で複数サブドメインを処理する設計上の限界**:
  - RewriteRule でファイルパスを書き換えても DocumentRoot は変わらない
  - `[END]` フラグは .htaccess の RewriteRule も停止してしまう
  - `[L]` フラグはリライトループを引き起こす
  - 結果として、ターゲットディレクトリの .htaccess が正常に適用されない

サブドメインごとに独立した VirtualHost を生成する方式に変更することで:

- **環境非依存**: RewriteMap 非対応環境でも動作する
- **.htaccess 完全対応**: DocumentRoot が正しく設定されるため Apache がネイティブに処理する
- **DirectoryIndex 対応**: Apache 標準の DirectoryIndex が適用される
- **mod_proxy 分離**: プロキシ用 VirtualHost が独立するため、mod_proxy 未導入でも他のルートに影響しない

## トレードオフ

| 項目 | 旧（RewriteMap + 単一 VirtualHost） | 新（VirtualHost 生成） |
|---|---|---|
| ルート変更時の反映 | 即時（mtime 検知） | graceful restart 必要（< 1秒） |
| .htaccess サポート | 不完全（RewriteRule が動かない） | 完全 |
| 環境互換性 | MAMP で動作しない | どの Apache でも動作 |
| mod_proxy 未導入時 | 500 エラー | プロキシルートのみ無効、他は正常 |
| VirtualHost 数 | HTTP/HTTPS 各 1 | ルート数 × プロトコル数 |

## アーキテクチャ概要

### Apache 設定構造

```
httpd.conf
├─ Include ${ROUTER_HOME}/conf/vhost-http.conf    # 常時
│   ├─ デフォルト VirtualHost *:80（フォールバック 404）
│   ├─ 管理UI VirtualHost *:80（ServerName localhost）
│   └─ Include ${ROUTER_HOME}/data/routes.conf
│
└─ Include ${ROUTER_HOME}/conf/vhost-https.conf   # SSL 有効時
    ├─ デフォルト VirtualHost *:443（フォールバック 404）
    ├─ 管理UI VirtualHost *:443（ServerName localhost）
    └─ Include ${ROUTER_HOME}/data/routes-ssl.conf
```

### routes.conf / routes-ssl.conf の生成イメージ

store.php が `data/routes.conf`（HTTP）と `data/routes-ssl.conf`（HTTPS）を自動生成する。

```apache
# data/routes.conf — 自動生成、手動編集禁止

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

`data/routes-ssl.conf` は同一構造で `*:443` + SSL ディレクティブが付与される:

```apache
# data/routes-ssl.conf — 自動生成、手動編集禁止

<VirtualHost *:443>
    ServerName test1.127.0.0.1.nip.io
    SSLEngine on
    SSLCertificateFile ${ROUTER_HOME}/ssl/cert.pem
    SSLCertificateKeyFile ${ROUTER_HOME}/ssl/key.pem
    DocumentRoot /private/var/vh/sites/dev/test1
    <Directory /private/var/vh/sites/dev/test1>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    DirectoryIndex index.php index.html index.htm
</VirtualHost>
```

## 作業内容

### 1. conf/vhost-http.conf.template の変更

RewriteMap 定義を削除。デフォルト VirtualHost + 管理UI VirtualHost + routes.conf の Include に変更する。

```apache
# デフォルト VirtualHost（名前ベース VirtualHost のフォールバック）
# 最初に定義された VirtualHost がデフォルトになる
<VirtualHost *:80>
    DocumentRoot ${ROUTER_HOME}/public/default
    <Directory ${ROUTER_HOME}/public/default>
        Require all granted
    </Directory>
</VirtualHost>

# 管理UI（localhost のみ）
<VirtualHost *:80>
    ServerName localhost
    ServerAlias 127.0.0.1 [::1]
    DocumentRoot ${ROUTER_HOME}/public
    <Directory ${ROUTER_HOME}/public>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    DirectoryIndex index.php index.html index.htm
</VirtualHost>

# 自動生成ルート
Include ${ROUTER_HOME}/data/routes.conf
```

### 2. conf/vhost-https.conf.template の変更

同様の VirtualHost 構成に変更。SSL ディレクティブ付き。`data/routes-ssl.conf` を Include する。

### 3. conf/routing-rules.conf の廃止

管理UIの設定は vhost-*.conf.template に直接記載するため不要になる。

### 4. store.php: routes.conf / routes-ssl.conf 生成ロジックの追加

`saveState()` 内で VirtualHost 定義を生成する。ルート種別ごとの生成:

- **ディレクトリ公開**: `DocumentRoot` + `<Directory>` + `AllowOverride All`
- **リバースプロキシ**: `ProxyPass` + `ProxyPassReverse`（WebSocket 対応含む）。mod_proxy 未導入時の Apache 起動エラーを防ぐため `<IfModule mod_proxy.c>` で囲む
- **リダイレクト**: `RewriteRule [R=302,L]`

SSL が有効な場合は `routes-ssl.conf` にも HTTPS 版の VirtualHost を生成する。

### 4a. store.php: ベースドメインのバリデーション追加

ベースドメイン登録時にパターンチェックを追加する。スラグは `SLUG_PATTERN` で守られているが、ベースドメインにはバリデーションがない。Apache 設定インジェクションを防止するため、ドメイン名として有効な文字（英数字・ハイフン・ドット）のみを許可する。

### 5. store.php: graceful restart トリガーの追加

`saveState()` の最後で `triggerGracefulRestart()` を呼び出し、Apache graceful restart を実行する。`/usr/bin/sudo -n bin/graceful.sh` 経由で root のマスタープロセスに USR1 シグナルを送信する。詳細は [Graceful Restart 機構の選定](../../doc/decisions/graceful-restart-mechanism.md) を参照。

### 6. routing.map の廃止

`generateRoutingMap()`, `writeRoutingMap()`, 定数 `ROUTING_MAP` を削除する。

### 7. resolve.php の廃止

デフォルト VirtualHost がフォールバック 404 を返すため、resolve.php のフォールバック機能は不要になる。`lookupRoute()`, `serveDirectory()` を含めて削除する。

### 8. 初期ファイルの作成

- `data/routes.conf` — 空の初期ファイル。setup.sh のデプロイ対象に含める
- `data/routes-ssl.conf` — 空の初期ファイル。同上
- `public/default/` — デフォルト VirtualHost 用の 404 ページ

### 9. setup.sh の変更

- `data/routing.map` のデプロイを削除
- `data/routes.conf`, `data/routes-ssl.conf` の初期デプロイを追加
- `conf/routing-rules.conf` の置換処理を削除
- 必須モジュール案内から RewriteMap 関連を削除

### 10. smoke-test.sh の更新

ルーティング方式が変わるためテストシナリオを更新する。`routing.map` のファイルチェックを `routes.conf` / `routes-ssl.conf` に変更。

### 11. uninstall.sh の更新

クリーンアップ対象ファイルを更新する。`routing.map` → `routes.conf` / `routes-ssl.conf`、`routing-rules.conf` の削除を追加。

### 12. env-check.php の更新

必須モジュールの用途説明を更新する。`mod_rewrite` の説明を「ルーティングルール・RewriteMap」から「リダイレクトと WebSocket 判定」に変更。

### 13. ドキュメント更新

以下のドキュメントを新しいアーキテクチャに合わせて更新する:

- `paved/doc/design/architecture.md` — ルーティングメカニズムの全面書き換え
- `paved/doc/decisions/` — VirtualHost 生成方式への変更の判断記録を追加
- `paved/doc/requirements/features.md` — セクション8「PHP処理」の旧方式前提の記述を修正
- その他の関連ドキュメント（要件定義、タスク）の RewriteMap 前提の記述を修正

## 完了条件

### コア実装（完了）

- [x] vhost-http.conf.template がデフォルト + 管理UI + Include 構成に変更されている
- [x] vhost-https.conf.template が同様に変更されている
- [x] routing-rules.conf が廃止されている
- [x] store.php が routes.conf（HTTP VirtualHost）を生成する
- [x] store.php が routes-ssl.conf（HTTPS VirtualHost）を生成する（SSL 有効時）
- [x] store.php が graceful restart をトリガーする
- [x] routing.map が廃止されている
- [x] resolve.php が廃止されている
- [x] ディレクトリ公開で .htaccess（RewriteRule 含む）が正常に動作する
- [x] mod_proxy 未導入環境でディレクトリ公開が正常に動作する（プロキシ VirtualHost が `<IfModule>` で囲まれている）
- [x] デフォルト VirtualHost が未登録サブドメインに 404 を返す
- [x] ベースドメイン登録時のバリデーションが追加されている

### スクリプト・周辺コード（完了）

- [x] smoke-test.sh が新方式に対応している
- [x] uninstall.sh が新方式のファイルを削除する
- [x] env-check.php のモジュール説明が更新されている
- [x] setup.sh が簡素化されている

### ドキュメント（完了）

- [x] architecture.md が新しい設計を反映している
- [x] requirements/features.md が VirtualHost 方式に更新されている
- [x] requirements/overview.md が更新されている
- [x] decisions/node-vs-php.md に VirtualHost 方式の列が追加されている
- [x] 判断記録（decisions/rewritemap-to-vhost.md）が追加されている
- [x] 既存タスク（store-php, apache-config, resolve-php, setup-script, ssl-support）に変更注記が追加されている

## 関連情報

- 関連ドキュメント: `paved/doc/design/architecture.md`
- 関連タスク: `apache-config.md`, `store-php.md`, `resolve-php.md`, `ssl-support.md`, `setup-script.md`
