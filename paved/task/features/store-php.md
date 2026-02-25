---
title: "store.php の実装"
description: "routes.json の読み書きと routing.map 生成ロジックを実装する"
status: "done"
priority: "P0"
created_at: "2026-02-25"
updated_at: "2026-02-25"
---

# store.php の実装

## 背景・目的

DevRouter の全 API が依存するコアライブラリ。
routes.json の読み書き、バックアップ、routing.map の生成を一箇所に集約する。

## 作業内容

`public/api/lib/store.php` に以下を実装する:

- `loadState()` — routes.json を読み込み。パース失敗時はバックアップから復元、それも失敗なら空の初期状態を返す
- `saveState($state)` — routes.json のバックアップ作成 → アトミック書き込み → routing.map 再生成
- `generateRoutingMap($state)` — routes.json の内容から routing.map テキストを生成
  - ベースドメイン直アクセス → リダイレクトエントリ
  - 明示登録 → 全ベースドメインとの組み合わせ
  - グループ解決（登録順走査、先にマッチしたグループ優先）
- `scanGroupDirectory($groupPath)` — グループディレクトリのサブディレクトリをスキャン。スラグパターン一致チェック + public/ 自動検出
- `writeRoutingMap($mapPath, $content)` — 一時ファイル + rename によるアトミック書き込み

## 完了条件

- loadState() が routes.json の読み込み・バックアップ復元・空初期状態のフォールバックを正しく行う
- saveState() が routes.json と routing.map を同時に更新する
- generateRoutingMap() が正しい形式の map テキストを生成する
- scanGroupDirectory() がスラグパターン一致と public/ 自動検出を行う
- アトミック書き込みが実装されている

## 関連情報

- [アーキテクチャ設計 - データ管理](../../doc/design/architecture.md)
- 依存タスク: [scaffolding.md](scaffolding.md)
