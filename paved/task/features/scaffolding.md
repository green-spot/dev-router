---
title: "ディレクトリ構造のスキャフォールディング"
description: "ROUTER_HOME 配下のディレクトリ構造と初期ファイルを作成する"
status: "done"
priority: "P0"
created_at: "2026-02-25"
updated_at: "2026-02-25"
---

# ディレクトリ構造のスキャフォールディング

## 背景・目的

DevRouter の全コンポーネントが配置される ROUTER_HOME ディレクトリ構造を作成する。
他のすべてのタスクの前提となる基盤作業。

## 作業内容

以下のディレクトリ構造を作成する:

```
{ROUTER_HOME}/
  public/
    api/
      lib/
    css/
    js/
  conf/
  data/
  ssl/
```

初期ファイル:
- `data/routes.json` — デフォルトのベースドメイン（127.0.0.1.nip.io）を含む初期状態
- `data/routing.map` — 初期 routes.json から生成した空の map

## 完了条件

- ディレクトリ構造が作成されている
- 初期 routes.json が有効な JSON として存在する
- 初期 routing.map が生成されている

## 関連情報

- [アーキテクチャ設計 - ファイル構成](../../doc/design/architecture.md)
