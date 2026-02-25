---
title: "アンインストールスクリプトの実装"
description: "DevRouter の設定とファイルをクリーンアップするスクリプトを実装する"
status: "done"
priority: "P3"
created_at: "2026-02-25"
updated_at: "2026-02-25"
---

# アンインストールスクリプトの実装

## 背景・目的

DevRouter を完全に除去したい場合に、追加した Apache 設定とファイルをクリーンに削除できるようにする。

## 作業内容

シェルスクリプト（bash）で以下を実行:

1. **確認プロンプト** — 削除対象を表示し、ユーザに確認を求める
2. **Apache 設定の除去** — セットアップスクリプトで追加した VirtualHost 設定を削除
3. **ROUTER_HOME ディレクトリの削除** — 全ファイルを削除（routes.json のバックアップオプション付き）
4. **Apache 再起動**（graceful）
5. **完了メッセージ**

## 完了条件

- スクリプト実行後、Apache がデフォルト状態に戻る
- ROUTER_HOME が削除される（バックアップオプション使用時は routes.json を退避）
- 確認プロンプトなしで削除されない

## 関連情報

- 依存タスク: [setup-script.md](setup-script.md)
