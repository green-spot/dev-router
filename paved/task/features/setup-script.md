---
title: "セットアップスクリプトの実装"
description: "初期設定を自動化するインストーラスクリプトを実装する"
status: "done"
priority: "P0"
created_at: "2026-02-25"
updated_at: "2026-02-25"
---

# セットアップスクリプトの実装

## 背景・目的

ユーザが1コマンドで DevRouter の初期設定を完了できるようにする。
ユーザ操作フローの「初期設定（1回）」を自動化する。

## 作業内容

シェルスクリプト（bash）で以下を実行:

1. **OS 検出** — macOS / Linux / WSL2 を判定し、パスやコマンドを出し分け
2. **前提条件チェック**
   - Apache 2.4 以上がインストールされているか
   - PHP 7.4 以上がインストールされているか
   - 必須 Apache モジュールが有効か（mod_rewrite, mod_proxy, mod_proxy_http, mod_proxy_wstunnel, mod_headers）
   - 不足モジュールがあれば有効化コマンドを案内
3. **ROUTER_HOME ディレクトリ作成** — スキャフォールディングと同等
4. **初期 routes.json 生成** — デフォルトベースドメイン（127.0.0.1.nip.io）を含む
5. **初期 routing.map 生成**
6. **Apache 設定の注入**
   - VirtualHost 設定を Apache の設定ディレクトリに配置
   - OS ごとの設定パスに対応（macOS Homebrew / Linux /etc/apache2/ 等）
7. **Apache 再起動**（graceful）
8. **完了メッセージ** — `http://localhost` で管理 UI にアクセスできる旨を表示

## 完了条件

- スクリプト実行後、`http://localhost` で管理 UI にアクセスできる
- macOS（Homebrew Apache）と Linux（apt/yum）の両方で動作する
- エラー時に分かりやすいメッセージが表示される
- 冪等性がある（再実行しても問題ない）

## 関連情報

- [機能仕様 - ユーザ操作フロー](../../doc/requirements/features.md)
- [機能仕様 - 環境チェック](../../doc/requirements/features.md)
- 依存タスク: [scaffolding.md](scaffolding.md), [apache-config.md](apache-config.md)
