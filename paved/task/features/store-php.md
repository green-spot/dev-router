---
title: "store.php の実装"
description: "routes.json の読み書きと routing.map 生成ロジックを実装する"
status: "done"
priority: "P0"
created_at: "2026-02-25"
updated_at: "2026-02-26"
---

# store.php の実装

> **注意**: [ルーティング方式の変更](routing-architecture-change.md)により、routing.map 生成は routes.conf / routes-ssl.conf（VirtualHost 定義）生成に置き換えられる予定。

## 背景・目的

DevRouter の全 API が依存するコアライブラリ。
routes.json の読み書き、バックアップ、ルーティング設定の生成を一箇所に集約する。

## 作業内容

`public/api/lib/store.php` に以下を実装する:

- `loadState()` — routes.json を読み込み。パース失敗時はバックアップから復元、それも失敗なら空の初期状態を返す
- `saveState($state)` — routes.json のバックアップ作成 → アトミック書き込み → ルーティング設定再生成
- ~~`generateRoutingMap($state)`~~ → `generateRoutesConf($state)` に変更予定。routes.json の内容からサブドメインごとの VirtualHost 定義を生成する
  - ベースドメイン直アクセス → リダイレクト VirtualHost
  - 明示登録 → ディレクトリ公開 / プロキシ VirtualHost
  - グループ解決（登録順走査、先にマッチしたグループ優先）
- `scanGroupDirectory($groupPath)` — グループディレクトリのサブディレクトリをスキャン。スラグパターン一致チェック + public/ 自動検出
- ~~`writeRoutingMap($mapPath, $content)`~~（廃止）→ routes.conf / routes-ssl.conf のアトミック書き込み + graceful restart
- `triggerGracefulRestart()` — `/usr/bin/sudo -n bin/graceful.sh` で Apache graceful restart を実行。graceful.sh は setup.sh がデプロイしたラッパースクリプト

## 完了条件

- loadState() が routes.json の読み込み・バックアップ復元・空初期状態のフォールバックを正しく行う
- saveState() が routes.json とルーティング設定を同時に更新する
- ルーティング設定生成が正しい VirtualHost 定義を出力する
- scanGroupDirectory() がスラグパターン一致と public/ 自動検出を行う
- アトミック書き込みが実装されている

## 関連情報

- [アーキテクチャ設計 - データ管理](../../doc/design/architecture.md)
- [意思決定記録 - Graceful Restart 機構の選定](../../doc/decisions/graceful-restart-mechanism.md)
- 依存タスク: [scaffolding.md](scaffolding.md)
