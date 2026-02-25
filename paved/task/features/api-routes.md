---
title: "ルート API の実装"
description: "スラグ指定公開・リバースプロキシの CRUD を行う routes.php を実装する"
status: "done"
priority: "P1"
created_at: "2026-02-25"
updated_at: "2026-02-25"
---

# ルート API の実装

## 背景・目的

スラグ指定公開とリバースプロキシ登録を管理 UI から行えるようにする。

## 作業内容

`public/api/routes.php` に以下を実装:

- **GET** — 全ルートのリストを返却（slug, target, type）
- **POST** — ルートを新規登録
  - スラグのバリデーション（`^[a-z0-9]([a-z0-9-]*[a-z0-9])?$`）
  - 既存スラグとの重複チェック（明示登録同士の重複はエラー）
  - type: "directory"（スラグ指定公開）または "proxy"（リバースプロキシ）
- **DELETE** — ルートの削除（slug をキーに指定）

## 完了条件

- 3つの HTTP メソッドが正しく動作する
- スラグパターンに一致しない場合にエラーが返る
- 既存スラグとの重複時にエラーが返る
- 変更後に routing.map が更新される

## 関連情報

- [機能仕様 - スラグ指定公開](../../doc/requirements/features.md)
- [機能仕様 - リバースプロキシ公開](../../doc/requirements/features.md)
- 依存タスク: [store-php.md](store-php.md)
