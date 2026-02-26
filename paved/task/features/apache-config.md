---
title: "Apache 設定ファイルの作成"
description: "routing-rules.conf と HTTP VirtualHost 設定を作成する"
status: "done"
priority: "P0"
created_at: "2026-02-25"
updated_at: "2026-02-25"
---

# Apache 設定ファイルの作成

> **注意**: [ルーティング方式の変更](routing-architecture-change.md)により、routing-rules.conf の廃止、RewriteMap の廃止、VirtualHost 生成方式への移行が予定されている。

## 背景・目的

DevRouter のルーティングを実現する Apache 設定ファイルを作成する。

## 作業内容

### ~~routing-rules.conf~~（廃止予定）

~~`conf/routing-rules.conf` に以下のルールを記述:~~

1. ~~管理 UI（localhost のみ）→ DocumentRoot 内のファイルを直接配信~~
2. ~~routing.map 照合 → 環境変数 ROUTE に格納~~
3. ~~マッチなし → resolve.php で自動解決~~
4. ~~R: プレフィックス → 302 リダイレクト~~
5. ~~WebSocket プロキシ（Upgrade ヘッダ検出時）~~
6. ~~リバースプロキシ（HTTP URL）~~
7. ~~ディレクトリ公開（ファイルパス）~~
8. ~~フォールバック → 404~~

→ [routing-architecture-change.md](routing-architecture-change.md) により、サブドメインごとに独立した VirtualHost を生成する方式に変更される。routing-rules.conf は廃止され、管理UI の設定は vhost-*.conf.template に直接記載される。

### VirtualHost 設定

HTTP VirtualHost（ポート 80）の設定テンプレート:

- ~~サーバコンフィグレベルで RewriteMap（lc + router）を定義~~（廃止予定）
- デフォルト VirtualHost（フォールバック 404）+ 管理UI VirtualHost + `Include routes.conf` の構成に変更予定
- `RequestHeader set X-Forwarded-Proto "http"`

## 完了条件

- ~~routing-rules.conf が全ルーティングルールを含んでいる~~（廃止予定）
- VirtualHost 設定テンプレートが作成されている
- ROUTER_HOME パスが変数化されている

## 関連情報

- [アーキテクチャ設計 - Apache ルーティングルール](../../doc/design/architecture.md)
- 依存タスク: [scaffolding.md](scaffolding.md)
