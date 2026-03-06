---
title: "store.php の責務分割"
description: "639行の God File を4モジュールに分割し、責務を明確化する"
status: "open"
priority: "P0"
created_at: "2026-02-26"
updated_at: "2026-02-26"
---

# store.php の責務分割

## 背景・目的

現在の `public/api/lib/store.php` は639行の God File となっており、以下の責務が混在している:

- JSON データの読み書き（永続化）
- VirtualHost 設定ファイルの生成
- グループディレクトリのスキャン・slug 検証
- ルート競合検出・マージ
- Apache graceful restart のトリガー

責務を分割することで、可読性・テスタビリティ・保守性を向上させる。

## 作業内容

`public/api/lib/store.php` を以下の4モジュールに分割する:

### store.php（データ永続化のみ）

- `loadState()` — routes.json の読み込み・バックアップ復元
- `saveState($state)` — アトミック書き込み + 設定再生成のオーケストレーション
- `atomicWrite()` — アトミックファイル書き込み

### vhost-generator.php（VirtualHost 設定生成）

- `generateRoutesConf($state)` — HTTP VirtualHost 定義の生成
- `generateRoutesSslConf($state)` — HTTPS VirtualHost 定義の生成
- VirtualHost テンプレートのフォーマッティング関数

### route-resolver.php（ルート解決・競合検出）

- `resolveAllRoutes($state)` — 明示ルート + グループルートのマージ
- 競合検出（同一 slug の shadowing 判定）
- 優先度に基づくルートの順序決定

### group-scanner.php（グループスキャン）

- `scanGroupDirectory($groupPath)` — ディレクトリスキャン
- slug パターン検証（`SLUG_PATTERN`）
- `public/` サブディレクトリの自動検出

## 完了条件

- store.php が200行以下になっている
- 各モジュールが単一責務を持っている
- 既存の API エンドポイントが変更なしで動作する
- `require_once` による依存関係が明確に定義されている

## 関連情報

- 対象ファイル: `public/api/lib/store.php`
- 依存タスク: なし（他のリファクタリングの土台）
- 後続タスク: [自動テストの追加](add-unit-tests.md)
