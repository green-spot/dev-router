---
title: "API レスポンスの統一"
description: "全 API エンドポイントのレスポンス形式を共通エンベロープに統一する"
status: "open"
priority: "P1"
created_at: "2026-02-26"
updated_at: "2026-02-26"
---

# API レスポンスの統一

## 背景・目的

現在の API エンドポイントはレスポンス形式が統一されていない:

- 配列を直接返すもの（`routes` 一覧）
- オブジェクトを返すもの（`groups` 操作）
- `warning` フィールドの有無が不統一

フロントエンドでのエラーハンドリングが複雑になっており、統一が必要。

## 作業内容

### 共通エンベロープの定義

```php
// 成功時
["ok" => true, "data" => [...], "warning" => null]

// エラー時
["ok" => false, "error" => "エラーメッセージ"]
```

### バックエンド変更

- `public/api/lib/router.php` にレスポンスヘルパーを追加:
  - `jsonSuccess($data, $warning = null)` — 成功レスポンス
  - `jsonError($message, $code = 400)` — エラーレスポンス
- `public/api/index.php` の全エンドポイントを統一形式に変更

### フロントエンド変更

- `public/js/app.js` の `api()` 関数を新形式に対応
- エラーハンドリングの統一（`ok` フィールドによる成否判定）

## 完了条件

- 全 API エンドポイントが共通エンベロープ形式で応答する
- フロントエンドが新形式を正しくパースしている
- エラー時のトースト表示が統一されている

## 関連情報

- 対象ファイル: `public/api/index.php`, `public/api/lib/router.php`, `public/js/app.js`
