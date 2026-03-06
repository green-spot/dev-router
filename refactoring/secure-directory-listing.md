---
title: "ディレクトリ一覧のセキュリティ強化"
description: "listSubdirs() に realpath() チェックを追加しディレクトリトラバーサルを防止する"
status: "open"
priority: "P3"
created_at: "2026-02-26"
updated_at: "2026-02-26"
---

# ディレクトリ一覧のセキュリティ強化

## 背景・目的

`public/api/index.php` の `listSubdirs()` 関数はベースネームのチェックのみで、`realpath()` による正規パスの検証を行っていない。
シンボリックリンクを含むパスが渡された場合、意図しないディレクトリの内容が返される可能性がある。

ローカル開発ツールのため実害は限定的だが、防御的プログラミングとして対応する。

## 作業内容

### realpath() チェックの追加

```php
function listSubdirs(string $basePath, string $prefix = ""): array {
    $realBase = realpath($basePath);
    if ($realBase === false) {
        return [];
    }

    $results = [];
    foreach (scandir($realBase) as $entry) {
        if ($entry[0] === '.') continue;
        $fullPath = $realBase . '/' . $entry;
        $realFull = realpath($fullPath);

        // realpath が basePath 配下であることを確認
        if ($realFull === false || strpos($realFull, $realBase) !== 0) {
            continue;
        }

        if (is_dir($realFull)) {
            $results[] = $prefix . $entry;
        }
    }
    return $results;
}
```

### browse-dirs API の入力検証強化

- リクエストされたパスが `$HOME` 配下であることを検証
- パストラバーサル文字列（`../`）の拒否

## 完了条件

- `listSubdirs()` が `realpath()` でパスを正規化している
- ベースディレクトリ外へのトラバーサルが不可能
- 既存のディレクトリ補完機能が正常に動作する

## 関連情報

- 対象ファイル: `public/api/index.php`
