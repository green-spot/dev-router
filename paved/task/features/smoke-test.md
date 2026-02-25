---
title: "スモークテストスクリプトの実装"
description: "セットアップ後の動作確認を自動化するテストスクリプトを実装する"
status: "done"
priority: "P2"
created_at: "2026-02-25"
updated_at: "2026-02-25"
---

# スモークテストスクリプトの実装

## 背景・目的

セットアップ後に DevRouter が正しく動作しているかを自動で確認する。
手動での動作確認の手間を省き、問題の早期発見を可能にする。

## 作業内容

シェルスクリプト（bash）で以下をチェック:

1. **管理 UI アクセス** — `curl http://localhost` で HTML が返るか
2. **API ヘルスチェック** — `curl http://localhost/api/health.php` で `{"status":"ok"}` が返るか
3. **環境チェック API** — `curl http://localhost/api/env-check.php` で全必須モジュールが ok か
4. **ベースドメイン直アクセス** — `curl -I http://127.0.0.1.nip.io` で 302 リダイレクトが返るか
5. **未登録サブドメイン** — `curl -I http://nonexistent.127.0.0.1.nip.io` で 404 が返るか

結果をチェックリスト形式で表示:

```
DevRouter スモークテスト:
  ✅ 管理 UI アクセス
  ✅ API ヘルスチェック
  ✅ 環境チェック
  ✅ ベースドメインリダイレクト
  ✅ 未登録サブドメイン 404
```

## 完了条件

- 全チェック項目が自動で実行される
- 結果がわかりやすく表示される
- 失敗時に原因の手がかりが表示される

## 関連情報

- 依存タスク: [setup-script.md](setup-script.md), [api-health-scan.md](api-health-scan.md)
