---
title: "OS 固有パスのハードコード排除"
description: "env.php の macOS 固有パスを設定ファイルに外部化する"
status: "open"
priority: "P3"
created_at: "2026-02-26"
updated_at: "2026-02-26"
---

# OS 固有パスのハードコード排除

## 背景・目的

`public/api/lib/env.php` に macOS 固有のパス（`/opt/homebrew/etc/httpd/extra`, `/usr/local/etc/httpd/extra`）がハードコードされている。
非標準の Apache インストールや将来の Linux 対応時に対応が困難。

## 作業内容

### 設定ファイルへの外部化

`conf/env.conf` に検索パスを追加:

```apache
SetEnv HTTPD_EXTRA_DIRS "/opt/homebrew/etc/httpd/extra:/usr/local/etc/httpd/extra"
```

### env.php の変更

- 環境変数 `HTTPD_EXTRA_DIRS` からパスリストを取得
- 未設定の場合は OS 検出に基づくデフォルト値を使用
- setup.sh が OS に応じた適切なパスを `env.conf` に書き込む

## 完了条件

- env.php にハードコードされた絶対パスが存在しない
- setup.sh が OS 検出結果に基づいて env.conf にパスを書き込む
- 既存の macOS 環境で動作が変わらない

## 関連情報

- 対象ファイル: `public/api/lib/env.php`, `conf/env.conf`, `setup.sh`
