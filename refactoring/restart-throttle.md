---
title: "graceful restart の連続実行制御"
description: "短期間の連続操作による過剰な Apache restart を抑制する"
status: "open"
priority: "P2"
created_at: "2026-02-26"
updated_at: "2026-02-26"
---

# graceful restart の連続実行制御

## 背景・目的

ルートの追加・削除のたびに即座に `kill -USR1` が発行される。
連続操作（複数ルートの一括登録など）時に過剰な graceful restart が発生し、Apache に不要な負荷がかかる可能性がある。

## 作業内容

### クールダウン機構の実装

`triggerGracefulRestart()` にクールダウンロジックを追加:

- 最後の restart 実行時刻をファイル（`data/.last-restart`）に記録
- 前回から2秒以内の場合はスキップし、設定変更だけ保存
- スキップした場合は「pending restart」フラグを立てる
- 次回の `saveState()` 呼び出し時にフラグを確認し、必要なら restart を実行

### 代替案: バッチ API の提供

複数操作を1回の API コールにまとめるバッチエンドポイントの追加も検討:

```
POST /api/batch
[
  {"action": "addRoute", "data": {...}},
  {"action": "addRoute", "data": {...}}
]
```

## 完了条件

- 2秒以内の連続 restart が発生しない
- 設定変更は確実に反映される（遅延はあっても欠落はない）
- 単発操作時の体感速度が低下しない

## 関連情報

- 対象ファイル: `public/api/lib/store.php`
- 関連: [意思決定記録 - Graceful Restart 機構の選定](../../doc/decisions/graceful-restart-mechanism.md)
