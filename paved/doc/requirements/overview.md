---
title: "要件概要"
description: "DevRouter の背景・目的・解決する課題・ゴール・動作環境・技術スタックをまとめる"
status: "draft"
created_at: "2026-02-25"
updated_at: "2026-02-25"
refs: []
---

# 要件概要

## 背景

ローカル開発において、以下の問題が存在する。

- VirtualHost を増やすたびに Apache 再起動が必要
- Node / WordPress / 静的サイトを同時に扱いにくい
- ポート番号付き URL（:3000 など）が煩雑
- サブドメイン構成の再現が困難
- チーム・会社単位のディレクトリ分離が難しい

## 目的

Apache を「Web サーバ」ではなく**ローカル開発用のルーティングゲートウェイ（DevRouter）**として動作させる。

## ゴール

ユーザは以下の操作のみでローカル公開を完了できる。

1. 初期設定（1回のみ）
2. 管理 UI へアクセス
3. フォルダ or アプリケーション URL を登録
4. サブドメイン URL へアクセス

ほとんどの操作で Apache の再起動は不要。
ルーティングは `txt:` RewriteMap のファイル更新で即反映される。
SSL 証明書の明示的な発行時のみ graceful が発生する。

## 動作環境

| OS                     | 対応   |
| ---------------------- | ------ |
| macOS                  | 推奨   |
| Linux                  | 推奨   |
| Windows + WSL2         | 推奨   |
| Windows ネイティブ Apache | 非対応 |

Windows ネイティブ環境では Apache の `mod_proxy` が Unix socket を未サポートであり、`apachectl graceful` も存在しないため動作しない。Windows では WSL2 を使用すること。

**Apache 2.4 以上を必須とする。** 本設計は `R=404` フラグ等の 2.4 固有機能に依存している。Apache 2.2 は 2018 年に EOL であり、対応しない。

**PHP 8.0 以上を推奨。** 最低 PHP 7.4 以上（`fn()` アロー関数等）。

## 技術スタック

| レイヤ | 技術 | 理由 |
| --- | --- | --- |
| ルーティングエンジン | `txt:` RewriteMap（ファイル参照） | Apache ネイティブ機能。長期プロセス不要でクラッシュリスクなし |
| 未登録サブドメイン解決 | resolve.php | マッチなし時にグループディレクトリを再スキャンし自動解決 |
| 管理 API バックエンド | PHP（Apache 直接実行） | フレームワーク不要・Unix socket 不要・プロセス管理不要 |
| フロントエンド（管理 UI） | HTML / CSS / vanilla JS | フレームワーク・ビルドステップ不要。Apache が直接配信する静的ファイルとして完結 |
| データ永続化 | routes.json + routing.map（自動生成） | JSON で管理し、変更時に RewriteMap 用テキストファイルを再生成 |

> **判断理由**: Node.js 版（RewriteMap `prg:` 方式）も検討したが、stdin/stdout プロトコル制約・Worker Thread によるスレッド分離・プロセスクラッシュ対策など、複雑さの大半が「Node を Apache 内で飼う」ことに起因していた。PHP 版は Apache + PHP 環境のみで完結し、外部依存ゼロでアーキテクチャが大幅に簡素化される。詳細は[意思決定記録](../decisions/index.md)を参照。

## 位置付け

本ソフトウェアは以下に相当する。

- Laravel Valet
- Traefik（開発用途）
- ローカル ngrok 代替

ただし Apache 環境に最適化された**ローカル開発ネットワークレイヤ**を提供することを目的とする。

Apache + PHP は DevRouter の主要ターゲットである WordPress / Laravel 開発者が既に持っている可能性が高く、追加の依存が減ることは導入障壁の低下に直結する。
