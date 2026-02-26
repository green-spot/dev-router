---
title: "意思決定記録の更新・追加"
description: "node-vs-php.md に VirtualHost 方式の列を追加し、RewriteMap 廃止の判断記録を新規作成する"
status: "done"
priority: "P1"
created_at: "2026-02-25"
updated_at: "2026-02-25"
---

# 意思決定記録の更新・追加

## 背景・目的

[ルーティング方式の変更](routing-architecture-change.md)により、実装方式が RewriteMap txt: から VirtualHost 生成に変わった。この判断の経緯を意思決定記録に残す。

## 作業内容

### node-vs-php.md の更新

- 比較表に「PHP 版（現行）」列を追加し、VirtualHost 自動生成方式を記載
- RewriteMap txt: 方式が「PHP 版（初期採用）」として区別されるよう整理
- PHP 版が優れる点に「VirtualHost 生成方式との親和性」を追加
- PHP 版が劣る点の resolve.php リダイレクトを取り消し線にし、VirtualHost 方式での変更点を注記
- 変わらない点から `<Directory />` 全パス許可の記述を削除

### rewritemap-to-vhost.md の新規作成

- RewriteMap txt: + 単一 VirtualHost 方式の具体的な問題点を記録（MAMP 非対応、.htaccess の [END]/[L] フラグ問題）
- 検討した代替案の比較表（RewriteRule [END]、[L] + ループ防止、PHP 配信、VirtualHost 生成）
- 新方式のトレードオフ表（反映速度、.htaccess、環境互換性、mod_proxy、VirtualHost 数等）
- 廃止される要素・新たに導入される要素のリスト

## 完了条件

- [x] node-vs-php.md に PHP 版（現行）の VirtualHost 方式が記載されている
- [x] rewritemap-to-vhost.md が旧方式の問題・代替案・トレードオフを網羅している
- [x] decisions/index.md に rewritemap-to-vhost.md へのリンクが追加されている

## 関連情報

- 対象ファイル: `paved/doc/decisions/node-vs-php.md`, `paved/doc/decisions/rewritemap-to-vhost.md`
- 関連タスク: [routing-architecture-change.md](routing-architecture-change.md)
