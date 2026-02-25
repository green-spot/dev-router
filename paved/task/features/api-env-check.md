---
title: "環境チェック API の実装"
description: "Apache モジュールと外部ツールの状態を検出する env-check.php を実装する"
status: "done"
priority: "P1"
created_at: "2026-02-25"
updated_at: "2026-02-25"
---

# 環境チェック API の実装

## 背景・目的

管理 UI の環境チェック画面にデータを提供する。
必須モジュールの有効状態、PHP バージョン、mkcert の有無等を検出する。

## 作業内容

`public/api/env-check.php` に以下を実装:

- **GET** — 以下の項目をチェックし、結果を JSON で返却

**必須モジュール:**
- mod_rewrite
- mod_proxy
- mod_proxy_http
- mod_proxy_wstunnel
- mod_headers

**PHP:**
- PHP バージョン

**オプション:**
- mod_ssl
- mkcert のインストール状態
- mkcert のローカル CA 登録状態

検出方法:
- `apachectl -M` の出力をパースして有効モジュールを判定
- OS を検出し、モジュール有効化コマンドを出し分け（Debian: `a2enmod` / macOS: `httpd.conf` の `LoadModule`）

## 完了条件

- 全チェック項目の結果が JSON で返却される
- 各項目に状態（ok / missing / warning）と対処コマンドが含まれる
- macOS と Linux の両方で正しいコマンドが返される

## 関連情報

- [機能仕様 - 環境チェック](../../doc/requirements/features.md)
- 依存タスク: [scaffolding.md](scaffolding.md)
