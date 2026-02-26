---
title: "要件ドキュメントの VirtualHost 方式への更新"
description: "features.md と overview.md の RewriteMap 前提の記述を VirtualHost 生成方式に更新する"
status: "done"
priority: "P1"
created_at: "2026-02-25"
updated_at: "2026-02-25"
---

# 要件ドキュメントの VirtualHost 方式への更新

## 背景・目的

[ルーティング方式の変更](routing-architecture-change.md)により、要件ドキュメント内の RewriteMap 前提の記述が実態と合わなくなった。VirtualHost 生成方式に合わせて更新する。

## 作業内容

### features.md の更新

- **新サブディレクトリの検出**: resolve.php による自動検出フローを、管理 UI スキャン → routes.conf 再生成 → graceful restart のフローに書き換え
- **スラグ重複チェック**: routing.map 生成時 → routes.conf 生成時に変更
- **Apache モジュール**: mod_rewrite の用途を「ルーティングルール・RewriteMap」→「リダイレクトルール」に修正。PHP の用途を「管理 API + 自動解決」→「管理 API + VirtualHost 定義生成」に修正
- **PHP 処理**: VirtualHost ごとに DocumentRoot が正しく設定される旨を追記。mod_php の制約セクションを削除
- **セキュリティ**: `<Directory />` 全パス許可 → VirtualHost ごとの `<Directory>` 許可に修正
- **操作性**: routing.map 即時反映 → graceful restart（1秒未満）に修正

### overview.md の更新

- **操作フロー**: RewriteMap のファイル更新で即反映 → graceful restart で反映に修正
- **技術スタック表**: ルーティングエンジンを RewriteMap → 名前ベース VirtualHost に変更。resolve.php の行を削除。データ永続化を routes.json + routes.conf / routes-ssl.conf に変更

## 完了条件

- [x] features.md の自動検出フローがスキャン + graceful restart になっている
- [x] features.md のモジュール用途が VirtualHost 方式を反映している
- [x] features.md の PHP 処理が VirtualHost 方式の利点を反映している
- [x] overview.md の技術スタック表が VirtualHost 方式になっている
- [x] 両ファイルから RewriteMap・routing.map への依存記述が除去されている

## 関連情報

- 対象ファイル: `paved/doc/requirements/features.md`, `paved/doc/requirements/overview.md`
- 関連タスク: [routing-architecture-change.md](routing-architecture-change.md)
