---
title: "ログ機構の追加"
description: "操作履歴・エラー・restart 結果を記録するファイルベースのログを追加する"
status: "open"
priority: "P2"
created_at: "2026-02-26"
updated_at: "2026-02-26"
---

# ログ機構の追加

## 背景・目的

現在、graceful restart の失敗や設定生成のエラーがサイレントに捨てられている。
問題発生時の原因調査が困難であるため、簡易ログ機構を追加する。

## 作業内容

### ログライブラリの実装

`public/api/lib/logger.php` を新規作成:

- `logInfo($message)` — 通常の操作ログ
- `logError($message, $context = [])` — エラーログ
- ログファイル: `data/logs/devrouter.log`
- ローテーション: 1MB 超過で `.1` にリネーム（最大3世代）

### ログ出力対象

| イベント | レベル | 内容 |
|---------|--------|------|
| ルート追加・削除 | info | slug, type, target |
| グループ追加・削除 | info | path |
| ドメイン追加・削除 | info | domain |
| VirtualHost 設定生成 | info | 生成件数 |
| graceful restart 実行 | info | 実行結果 |
| graceful restart 失敗 | error | エラー出力 |
| routes.json パース失敗 | error | バックアップ復元の成否 |
| SSL 証明書生成 | info | ドメイン名 |

### ログビューア（オプション）

管理 UI にログ閲覧タブを追加（最新100行表示）。

## 完了条件

- `data/logs/devrouter.log` にログが記録される
- graceful restart の成功・失敗がログに残る
- ログローテーションが動作する
- ログファイルが `.gitignore` に追加されている

## 関連情報

- 対象ファイル: `public/api/lib/logger.php`（新規）, `public/api/lib/store.php`
