---
title: "Apache 設定ファイルの作成"
description: "routing-rules.conf と HTTP VirtualHost 設定を作成する"
status: "done"
priority: "P0"
created_at: "2026-02-25"
updated_at: "2026-02-25"
---

# Apache 設定ファイルの作成

## 背景・目的

DevRouter のルーティングを実現する Apache 設定ファイルを作成する。
RewriteMap 定義、ルーティングルール、VirtualHost 設定が対象。

## 作業内容

### routing-rules.conf

`conf/routing-rules.conf` に以下のルールを記述:

1. 管理 UI（localhost のみ）→ DocumentRoot 内のファイルを直接配信
2. routing.map 照合 → 環境変数 ROUTE に格納
3. マッチなし → resolve.php で自動解決
4. R: プレフィックス → 302 リダイレクト
5. WebSocket プロキシ（Upgrade ヘッダ検出時）
6. リバースプロキシ（HTTP URL）
7. ディレクトリ公開（ファイルパス）
8. フォールバック → 404

### VirtualHost 設定

HTTP VirtualHost（ポート 80）の設定テンプレート:

- サーバコンフィグレベルで RewriteMap（lc + router）を定義
- `<Directory />` による全パス許可
- routing-rules.conf の Include
- `RequestHeader set X-Forwarded-Proto "http"`

## 完了条件

- routing-rules.conf が全ルーティングルールを含んでいる
- VirtualHost 設定テンプレートが作成されている
- ROUTER_HOME パスが変数化されている

## 関連情報

- [アーキテクチャ設計 - Apache ルーティングルール](../../doc/design/architecture.md)
- 依存タスク: [scaffolding.md](scaffolding.md)
