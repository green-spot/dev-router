---
title: "アンインストールスクリプトの実装"
description: "DevRouter の設定とファイルをクリーンアップするスクリプトを実装する"
status: "done"
priority: "P3"
created_at: "2026-02-25"
updated_at: "2026-02-26"
---

# アンインストールスクリプトの実装

## 背景・目的

DevRouter を完全に除去したい場合に、追加した Apache 設定とファイルをクリーンに削除できるようにする。

## 作業内容

シェルスクリプト（bash）で以下を実行:

1. **確認プロンプト** — 削除対象を表示し、ユーザに確認を求める
2. **routes.json のバックアップ** — オプションでホームディレクトリに退避
3. **ROUTER_HOME ディレクトリの削除** — 全ファイルを削除
4. **sudoers 設定の削除** — `/etc/sudoers.d/dev-router` を削除
5. **完了メッセージ** — httpd.conf の Include 行を手動削除する旨を案内

## 完了条件

- [x] ROUTER_HOME が削除される（バックアップオプション使用時は routes.json を退避）
- [x] sudoers 設定（`/etc/sudoers.d/dev-router`）が削除される
- [x] 確認プロンプトなしで削除されない
- [x] httpd.conf の Include 行の手動削除を案内している

## 関連情報

- 依存タスク: [setup-script.md](setup-script.md)
