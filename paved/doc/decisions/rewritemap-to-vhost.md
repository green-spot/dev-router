---
title: "RewriteMap 廃止 → VirtualHost 生成方式への移行"
description: "RewriteMap txt: + 単一 VirtualHost 方式の限界と、サブドメインごとの VirtualHost 生成方式を採用した判断理由"
status: "draft"
created_at: "2026-02-25 00:00:00"
updated_at: "2026-02-26 00:00:00"
refs:
  - "design/architecture.md"
  - "decisions/node-vs-php.md"
---

# RewriteMap 廃止 → VirtualHost 生成方式への移行

## 概要

RewriteMap `txt:` + 単一 VirtualHost 方式を廃止し、サブドメインごとに独立した VirtualHost を自動生成する方式に移行する。

## 旧方式の問題

### 1. MAMP 環境で RewriteMap が動作しない

MAMP 同梱の Apache では `RewriteMap txt:` が機能しない。原因は未特定だが再現性がある。ローカル開発ツールとして MAMP ユーザーを排除するのは許容できない。

### 2. 単一 VirtualHost では .htaccess の RewriteRule が動かない

1つの VirtualHost で複数サブドメインを処理する設計には根本的な限界がある。

RewriteRule でファイルパスを書き換えても `DocumentRoot` は変わらない。この状態で Apache がターゲットディレクトリの .htaccess を処理する際:

- **`[END]` フラグ**: Apache ドキュメントに明記されている通り、per-directory（.htaccess）の RewriteRule 処理も停止する。WordPress 等のフロントコントローラパターンが動作しない
- **`[L]` フラグ**: 現在のラウンドのリライトを停止するが、internal redirect として再度リライトループが走る。同じ RewriteRule に再マッチし無限ループになる

いずれの方法でも、ターゲットディレクトリの .htaccess 内 RewriteRule を正常に動作させることはできない。

> **判断理由**: これは Apache の設計上の制約であり、ワークアラウンドでは解決できない。DocumentRoot を正しく設定するには VirtualHost を分ける以外に方法がない。

## 検討した代替案

| 方式 | 評価 | 不採用理由 |
|---|---|---|
| RewriteRule `[END]` | 不可 | .htaccess の RewriteRule が停止する |
| RewriteRule `[L]` + ループ防止 | 不可 | 環境変数やパスパターンによる防止は脆弱で、全ルートのパスプレフィックスが統一されている保証がない |
| PHP での配信（resolve.php 方式） | 可能だが不完全 | DirectoryIndex、.htaccess、mod_rewrite 等の Apache ネイティブ機能が使えない。PHP でこれらを模倣するのは不完全かつ保守コストが高い |
| **VirtualHost 生成** | **採用** | DocumentRoot が正しく設定されるため、Apache の全機能がネイティブに動作する |

## 新方式のトレードオフ

| 項目 | 旧方式 | 新方式 |
|---|---|---|
| ルート変更の反映 | 即時（mtime 検知） | graceful restart 必要（< 1秒） |
| .htaccess サポート | RewriteRule が動作しない | 完全対応 |
| 環境互換性 | MAMP で動作しない | どの Apache でも動作 |
| mod_proxy 未導入時 | 500 エラー | プロキシルートのみ無効、他は正常 |
| ProxyPassReverse | 動的ルーティングで設定不可 | VirtualHost ごとに静的設定可能 |
| VirtualHost 数 | HTTP/HTTPS 各 1 | ルート数 × プロトコル数 |
| 未登録サブドメイン | resolve.php で自動検出 → リダイレクト | デフォルト VirtualHost が 404。手動スキャンで検出 |

graceful restart のコスト（< 1秒、既存接続を中断しない）はローカル開発用途で許容範囲内。.htaccess の完全対応と環境互換性の利益が大きく上回る。

## 廃止される要素

- `RewriteMap lc` / `RewriteMap router` 定義
- `conf/routing-rules.conf`（共通ルーティングルール）
- `data/routing.map`（RewriteMap 用テキストファイル）
- `public/resolve.php`（PHP フォールバック配信）
- `generateRoutingMap()` / `writeRoutingMap()` / `lookupRoute()` / `serveDirectory()`

## 新たに導入される要素

- `data/routes.conf`（HTTP VirtualHost 定義、自動生成）
- `data/routes-ssl.conf`（HTTPS VirtualHost 定義、自動生成）
- `public/default/`（デフォルト VirtualHost 用 404 ページ）
- `generateRoutesConf()` / `generateRoutesSslConf()`
- `saveState()` 内での graceful restart トリガー
