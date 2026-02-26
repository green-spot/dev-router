---
title: "セットアップスクリプトの実装"
description: "初期設定を自動化するインストーラスクリプトを実装する"
status: "done"
priority: "P0"
created_at: "2026-02-25"
updated_at: "2026-02-26"
---

# セットアップスクリプトの実装

## 背景・目的

ユーザが1コマンドで DevRouter の初期設定を完了できるようにする。
ユーザ操作フローの「初期設定（1回）」を自動化する。

## 作業内容

シェルスクリプト（bash）で以下を実行:

1. **ROUTER_HOME ディレクトリ作成** — public/, conf/, data/, ssl/, bin/ のサブディレクトリを含む
2. **ファイルデプロイ** — rsync で public/ 配下を配置、conf/ テンプレートを `${ROUTER_HOME}` 置換して配置
3. **初期データ配置** — routes.json、routes.conf、routes-ssl.conf の初期ファイル（既存があれば保持）
4. **Apache 環境検出 + env.conf 生成**
   - 実行中の Apache プロセスからバイナリパス（`HTTPD_BIN`）を自動検出
   - Apache ワーカーの実行ユーザを自動検出
   - 起動していない場合は対話式で入力を求める
   - `APACHE_USER` 環境変数での手動指定にも対応
   - `conf/env.conf` に `HTTPD_BIN` を書き出し
5. **bin/graceful.sh デプロイ** — Apache graceful restart ラッパースクリプトを配置
6. **sudoers 設定** — `/etc/sudoers.d/dev-router` に Apache ワーカーユーザへの NOPASSWD 許可（graceful.sh のみ）を設定
7. **完了メッセージ** — httpd.conf への Include 行の追加手順を案内

## 完了条件

- [x] スクリプト実行後、ROUTER_HOME にファイルがデプロイされている
- [x] Apache バイナリパスが自動検出され env.conf に書き出される
- [x] Apache ワーカーユーザが自動検出され sudoers に設定される
- [x] graceful.sh がデプロイされ実行権限が付与されている
- [x] 冪等性がある（再実行しても問題ない）
- [x] Apache 未起動時は対話式フォールバックが動作する

## 関連情報

- [機能仕様 - ユーザ操作フロー](../../doc/requirements/features.md)
- [アーキテクチャ設計 - Graceful Restart 機構](../../doc/design/architecture.md)
- [意思決定記録 - Graceful Restart 機構の選定](../../doc/decisions/graceful-restart-mechanism.md)
- 依存タスク: [scaffolding.md](scaffolding.md), [apache-config.md](apache-config.md)
