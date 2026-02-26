---
title: "Graceful Restart 機構の選定"
description: "PHP から Apache graceful restart を実行する方式として、sudoers + 専用ラッパースクリプト方式を採用した判断理由"
status: "draft"
created_at: "2026-02-26"
updated_at: "2026-02-26"
refs:
  - "design/architecture.md"
  - "decisions/rewritemap-to-vhost.md"
---

# Graceful Restart 機構の選定

## 概要

VirtualHost 生成方式では、ルーティング変更のたびに Apache graceful restart が必要になる。PHP（Apache ワーカープロセス）は一般ユーザ権限で動作しており、root 権限の Apache マスタープロセスにシグナルを送信するための特権昇格が必要である。

## 課題

1. **特権昇格が必要** — `kill -USR1` は root のマスタープロセスに対して行うため、一般ユーザ権限では実行できない
2. **Apache バイナリパスが環境依存** — MAMP / Homebrew / apt 等で異なる。ハードコードは不可
3. **macOS + Linux の両対応** — プラットフォーム固有の仕組みに依存しない
4. **MAMP 固有の問題** — MAMP の Apache ワーカーは無効な GID（4294967295 = -1）を持つ場合があり、sudo が動作しない

## 検討した代替案

| 方式 | 評価 | 不採用理由 |
|---|---|---|
| `apachectl graceful` | 不可 | 環境に複数の Apache がある場合、正しい apachectl が PATH 上にある保証がない。MAMP では Homebrew の apachectl が優先されるケースを確認 |
| `httpd -k graceful` | 不可 | 非 root 実行時に「Address already in use」エラー。新インスタンスの起動を試みてしまう |
| launchd（macOS）+ systemd（Linux）| 可能だが不採用 | ファイル監視デーモンの常駐が必要。バックグラウンドプロセスを増やしたくない |
| setuid C バイナリ | 可能だが不採用 | C コンパイラがデフォルトで入っていない環境が多い。セットアップの依存が増える |
| `pgrep` + `kill -USR1` | 部分的に不可 | macOS で sudo 経由実行時に `pgrep -f` がプロセスを検出できないケースを確認 |
| **sudoers + ラッパースクリプト** | **採用** | 最小権限で cross-platform 動作。追加の依存なし |

## 採用した方式

### 構成

```
PHP (store.php)
   → /usr/bin/sudo -n bin/graceful.sh
       → conf/env.conf から HTTPD_BIN を読み込み
       → ps + awk で Apache マスタープロセスを特定
       → kill -USR1 で graceful restart
```

### setup.sh の役割

1. 実行中の Apache プロセスからバイナリパスと実行ユーザを自動検出
2. `conf/env.conf` に Apache バイナリパスを書き出し
3. `bin/graceful.sh` をデプロイ
4. `/etc/sudoers.d/dev-router` に PHP 実行ユーザへの NOPASSWD 許可を設定

### セキュリティ

- sudoers は graceful.sh 1ファイルのみに限定（任意コマンドの実行不可）
- env.conf と graceful.sh は root 所有（改ざん防止）
- 送信シグナルは USR1 のみ

## 実装時に発見した問題

### MAMP の無効な GID

MAMP の Apache ワーカーは `Group` ディレクティブ未設定時に GID が 4294967295（符号なし -1）になる。この状態で sudo を実行すると `sudo: gid=4294967295: invalid value` エラーで失敗する。

対処: MAMP の httpd.conf に `Group staff` を追加して有効な GID を設定する。

### pgrep -f が sudo 経由で動作しない（macOS）

`pgrep -u root -f "^/path/to/httpd"` はターミナルからの直接実行では動作するが、PHP → sudo 経由で実行すると結果が空になる。

対処: `ps -eo pid,user,command | awk` による検索をメインの方式として採用。

> **判断理由**: pgrep の動作しない原因は macOS のプロセス可視性の制約と推測されるが、正確な原因は未特定。ps + awk は同一条件で安定して動作することを確認済みのため、信頼性を優先してこちらを採用した。

## トレードオフ

| 項目 | 評価 |
|---|---|
| 追加依存 | なし（sudo / ps / awk / kill は標準コマンド） |
| セットアップ手順 | setup.sh が自動設定。手動作業不要 |
| 権限範囲 | graceful.sh のみに限定。最小権限 |
| 環境互換性 | macOS / Linux 両対応。Apache バイナリパスは env.conf で吸収 |
| MAMP 対応 | GID 修正が必要（httpd.conf に `Group staff` を追加） |
