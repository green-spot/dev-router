---
title: "フロントエンド状態管理の改善"
description: "app.js の状態管理を Proxy ベースのリアクティブシステムに移行する"
status: "open"
priority: "P2"
created_at: "2026-02-26"
updated_at: "2026-02-26"
---

# フロントエンド状態管理の改善

## 背景・目的

現在の `public/js/app.js`（992行）はグローバルな `state` オブジェクトを直接変更し、各 `render*()` 関数が個別に DOM を更新している。
状態変更の追跡が困難で、デバッグ時に「いつ・どこで状態が変わったか」が分かりにくい。

## 作業内容

### 1. Proxy ベースの状態管理

```javascript
const state = new Proxy({...}, {
  set(target, key, value) {
    console.debug(`[state] ${key}:`, value);
    target[key] = value;
    scheduleRender(key);
    return true;
  }
});
```

- 状態変更時に自動でログ出力（開発時のみ）
- 変更されたキーに応じた部分レンダリングのスケジューリング

### 2. render 関数のエラーバウンダリ

```javascript
function safeRender(renderFn, name) {
  try {
    renderFn();
  } catch (e) {
    console.error(`[render] ${name} failed:`, e);
    showToast(`表示エラー: ${name}`, "error");
  }
}
```

- render 関数の例外で UI 全体がクラッシュしないようにする

### 3. バリデーション定数の共通化

- slug パターン・ドメインパターンの正規表現を定数として定義
- サーバーサイドと同じルールをクライアントでも適用
- API コール前にバリデーションエラーを表示

## 完了条件

- state への代入が自動でログ出力される（開発モード時）
- render 関数の例外が UI 全体をクラッシュさせない
- slug・ドメインのバリデーションがクライアントサイドでも動作する
- 既存の UI 動作に退行がない

## 関連情報

- 対象ファイル: `public/js/app.js`
