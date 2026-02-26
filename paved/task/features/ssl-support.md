---
title: "SSL 対応の実装"
description: "ssl.php と mkcert 連携、HTTPS VirtualHost 自動生成を実装する"
status: "done"
priority: "P2"
created_at: "2026-02-25"
updated_at: "2026-02-26"
---

# SSL 対応の実装

## 背景・目的

ベースドメインごとに HTTPS を有効化できるようにする。
mkcert でワイルドカード証明書を発行し、HTTPS VirtualHost を自動生成する。

## 作業内容

### ssl.php

`public/api/ssl.php` に以下を実装:

- **GET** — SSL 状態を返却
  - mkcert のインストール状態
  - ローカル CA の登録状態
  - 各ベースドメインの SSL 有効/無効
- **POST** — HTTPS 有効化フロー
  1. routes.json の該当ベースドメインを `ssl: true` に更新
  2. 全ベースドメイン（ssl: true）の SAN 一覧を構築
  3. `mkcert` で証明書発行（`{ROUTER_HOME}/ssl/cert.pem`, `key.pem`）
  4. HTTPS VirtualHost 設定を生成（初回のみ）
  5. `triggerGracefulRestart()` で Apache graceful restart を実行

### HTTPS VirtualHost 設定の自動生成

> **注意**: [ルーティング方式の変更](routing-architecture-change.md)により、HTTPS VirtualHost の構造が変更予定。routing-rules.conf の Include ではなく、vhost-https.conf.template（デフォルト + 管理UI VirtualHost + `Include routes-ssl.conf`）の構成になる。store.php が routes-ssl.conf にサブドメインごとの HTTPS VirtualHost を自動生成する。

初回の証明書発行時に VirtualHost 設定を追加:
- SSLEngine on
- SSLCertificateFile / SSLCertificateKeyFile の固定パス
- ~~routing-rules.conf の Include~~（→ `Include routes-ssl.conf` に変更予定）
- `RequestHeader set X-Forwarded-Proto "https"`

### mkcert 状態検出

- `which mkcert` でインストール確認
- `mkcert -CAROOT` + CA ファイルの存在確認でローカル CA 登録状態を検出

## 完了条件

- mkcert 未インストール時に適切なエラーメッセージが返る
- HTTPS 有効化後にワイルドカード証明書が発行される
- 複数ベースドメインの SAN がまとめて1枚の証明書に含まれる
- HTTPS VirtualHost が自動生成され、graceful 後に HTTPS アクセスが可能になる
- 証明書の再発行が動作する

## 関連情報

- [機能仕様 - SSL](../../doc/requirements/features.md)
- [アーキテクチャ設計 - SSL 設定](../../doc/design/architecture.md)
- 依存タスク: [apache-config.md](apache-config.md), [api-domains.md](api-domains.md)
