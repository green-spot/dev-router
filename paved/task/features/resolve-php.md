---
title: "resolve.php の実装"
description: "未登録サブドメインの自動解決メカニズムを実装する"
status: "done"
priority: "P1"
created_at: "2026-02-25"
updated_at: "2026-02-25"
---

# resolve.php の実装（廃止予定）

> **注意**: [ルーティング方式の変更](routing-architecture-change.md)により、resolve.php は廃止予定。デフォルト VirtualHost がフォールバック 404 を返すため、PHP によるフォールバック処理は不要になる。「ディレクトリを作るだけでアクセス可能」は scan API によるグループ再スキャン → routes.conf 再生成 → graceful restart で実現する。

## 背景・目的

~~routing.map に登録されていないサブドメインへのアクセス時に、グループディレクトリを再スキャンして自動解決する。~~
~~これにより「ディレクトリを作るだけでアクセス可能」を実現する。~~

VirtualHost 生成方式への移行に伴い廃止される。

## 作業内容（実装済み → 廃止予定）

~~`public/resolve.php` に以下を実装:~~

1. ~~リクエストのホスト名を取得（小文字化）~~
2. ~~`saveState(loadState())` で routing.map を再生成（グループディレクトリの再スキャン）~~
3. ~~再生成後の routing.map にこのホストが存在するか確認~~
4. ~~存在する → 同じ URL へ 302 リダイレクト（次のリクエストで更新済み map にヒット）~~
5. ~~存在しない → 404 ページを返す（管理 UI へのリンクを含む）~~

廃止対象: `lookupRoute()`, `serveDirectory()`, resolve.php 本体

## 完了条件

- ~~新規サブディレクトリ作成後の初回アクセスでリダイレクト→サイト表示される~~
- ~~存在しないサブドメインへのアクセスで 404 が返る~~
- ~~404 ページに管理 UI へのリンクが含まれる~~

## 関連情報

- [機能仕様 - 新サブディレクトリの自動検出](../../doc/requirements/features.md)
- [アーキテクチャ設計 - ルーティング優先順位](../../doc/design/architecture.md)
- 依存タスク: [store-php.md](store-php.md)
