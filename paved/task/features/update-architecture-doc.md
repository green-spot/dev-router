---
title: "設計ドキュメントの VirtualHost 方式への更新"
description: "architecture.md のルーティングメカニズム・技術スタック・データ管理を VirtualHost 生成方式に全面書き換えする"
status: "done"
priority: "P1"
created_at: "2026-02-25"
updated_at: "2026-02-25"
---

# 設計ドキュメントの VirtualHost 方式への更新

## 背景・目的

[ルーティング方式の変更](routing-architecture-change.md)により、アーキテクチャが RewriteMap txt: + 単一 VirtualHost からサブドメインごとの VirtualHost 生成方式に変更された。設計ドキュメントを現行アーキテクチャに合わせて更新する。

## 作業内容

### architecture.md の更新

- **全体構成図**: RewriteMap ルックアップのフローを、デフォルト VirtualHost + 管理UI VirtualHost + ルート VirtualHost の構成に書き換え
- **核となる技術**: RewriteMap → 名前ベース VirtualHost に変更。判断理由（MAMP 非対応・.htaccess 適用不全）の注記を追加
- **ルーティングメカニズム**: RewriteMap txt: 方式のセクションを VirtualHost 生成方式に全面書き換え。routes.conf / routes-ssl.conf の生成イメージを記載
- **データ管理**: routing.map → routes.conf / routes-ssl.conf に変更。graceful restart による反映フローを記載
- **PHP 処理**: VirtualHost ごとに DocumentRoot が正しく設定される旨に修正。mod_php の制約セクションを削除
- **セキュリティ**: `<Directory />` 全パス許可から VirtualHost ごとの `<Directory>` 許可に修正

## 完了条件

- [x] 全体構成図が VirtualHost 生成方式を反映している
- [x] 核となる技術から RewriteMap が除去され、名前ベース VirtualHost に置き換えられている
- [x] ルーティングメカニズムが routes.conf / routes-ssl.conf の生成方式で記述されている
- [x] データ管理が graceful restart フローを含んでいる
- [x] PHP 処理が VirtualHost 方式の利点を反映している

## 関連情報

- 対象ファイル: `paved/doc/design/architecture.md`
- 関連タスク: [routing-architecture-change.md](routing-architecture-change.md)
