---
title: "ヘルスチェック + スキャン API の実装"
description: "health.php と scan.php を実装する"
status: "done"
priority: "P1"
created_at: "2026-02-25"
updated_at: "2026-02-25"
---

# ヘルスチェック + スキャン API の実装

## 背景・目的

管理 UI のバックエンド疎通確認と、グループディレクトリの手動スキャンを提供する。

## 作業内容

### health.php

`public/api/health.php` に以下を実装:

- **GET** — `{"status": "ok"}` を返す
- SSL 有効化時の graceful 後の復帰確認にも使用される

### scan.php

`public/api/scan.php` に以下を実装:

- **POST** — `saveState(loadState())` で routing.map を再生成し、最新のスキャン結果を返却
- レスポンスにはグループごとのサブディレクトリ一覧と衝突情報を含める

## 完了条件

- health.php が正しい JSON レスポンスを返す
- scan.php が routing.map を再生成し、スキャン結果を返す

## 関連情報

- [アーキテクチャ設計 - Admin API 設計](../../doc/design/architecture.md)
- 依存タスク: [store-php.md](store-php.md)
