---
title: "ベースドメイン API の実装"
description: "ベースドメインの CRUD と current 切替を行う domains.php を実装する"
status: "done"
priority: "P1"
created_at: "2026-02-25"
updated_at: "2026-02-25"
---

# ベースドメイン API の実装

## 背景・目的

ベースドメインの登録・削除・current 切替を管理 UI から行えるようにする。

## 作業内容

`public/api/domains.php` に以下を実装:

- **GET** — 全ベースドメインのリストを返却（domain, current, ssl の各フィールド）
- **POST** — ベースドメインを新規登録。初回登録時は自動的に current に設定
- **PUT** — current の切替
- **DELETE** — ベースドメインの削除。current のドメインは削除不可（エラー返却）

すべての変更操作で `saveState()` を呼び出し、routing.map を再生成する。

## 完了条件

- 4つの HTTP メソッドが正しく動作する
- current のドメインを削除しようとするとエラーが返る
- 変更後に routing.map が更新される

## 関連情報

- [機能仕様 - ベースドメイン](../../doc/requirements/features.md)
- 依存タスク: [store-php.md](store-php.md)
