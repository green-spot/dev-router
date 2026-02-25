---
title: "管理 UI フロントエンドの実装"
description: "HTML / CSS / vanilla JS で管理 UI を実装する"
status: "done"
priority: "P1"
created_at: "2026-02-25"
updated_at: "2026-02-25"
---

# 管理 UI フロントエンドの実装

## 背景・目的

ブラウザからベースドメイン・グループ・ルートの管理操作を行える UI を提供する。

## 作業内容

`public/index.html` + `public/css/` + `public/js/` に以下を実装:

### ページ構成（単一 HTML、タブ切替）

1. **ダッシュボード** — 登録済みルートの一覧、URL のコピー、衝突警告の表示
2. **グループ管理** — グループの追加・削除・優先順位変更
3. **ルート管理** — スラグ指定公開・リバースプロキシの追加・削除
4. **ベースドメイン** — ドメインの追加・削除・current 切替・SSL 状態表示
5. **環境チェック** — Apache モジュール・PHP・mkcert の状態表示

### 共通要素

- 上部に常時表示する警告エリア（スラグ衝突、routes.json 破損等）
- 各操作は API を呼び出し、レスポンスに応じて UI を更新
- フレームワーク・ビルドステップ不使用

### API 呼び出し

各タブから対応する API エンドポイントを呼び出す:
- `/api/domains.php`
- `/api/groups.php`
- `/api/routes.php`
- `/api/scan.php`
- `/api/env-check.php`
- `/api/ssl.php`
- `/api/health.php`

## 完了条件

- 全タブが機能し、API と正しく連携する
- スラグ衝突の警告が表示される
- URL のコピー機能が動作する
- SPA フォールバック設定なしで動作する（単一 HTML + タブ切替）

## 関連情報

- [機能仕様 - 管理 UI](../../doc/requirements/features.md)
- 依存タスク: 全 API タスク
