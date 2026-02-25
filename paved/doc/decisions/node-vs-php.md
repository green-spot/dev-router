---
title: "Node.js 版 vs PHP 版の技術選定"
description: "ルーティングエンジンと管理 API の実装方式として PHP 版を採用した判断理由とトレードオフ"
status: "draft"
created_at: "2026-02-25"
updated_at: "2026-02-25"
refs:
  - "requirements/overview.md"
  - "design/architecture.md"
---

# Node.js 版 vs PHP 版の技術選定

## 概要

DevRouter のルーティングエンジンと管理 API の実装方式として、2つの案を検討した。

| | Node.js 版 | PHP 版 |
| --- | --- | --- |
| ルーティングエンジン | RewriteMap `prg:` タイプ（stdin/stdout） | RewriteMap `txt:` タイプ（ファイル参照） |
| 管理 API | Worker Thread + Hono + Unix socket | PHP ファイル（Apache 直接実行） |
| データ同期 | MessagePort（インメモリ→インメモリ） | routes.json → routing.map 再生成 |

**PHP 版を採用する。**

> **判断理由**: Node.js 版の設計の複雑さの大半は「Node を Apache 内で飼う」ことに起因している。PHP 版は Apache + PHP 環境のみで完結し、プロセス管理・スレッド分離・socket 通信が全て不要になる。

---

## Node.js 版で必要だった対策（PHP 版では不要）

| 問題 | Node.js 版での対策 | PHP 版 |
| --- | --- | --- |
| `prg:` の stdin/stdout プロトコル制約 | readline モジュールによる行単位読み取り | **不要** |
| 未応答で Apache 全体ハング | try-catch で例外捕捉、必ず NULL を返却 | **不要**（ファイル参照は必ず完了） |
| stdout 汚染によるプロトコル破壊 | Worker Thread の stdout 分離、console.log 禁止 | **不要** |
| クラッシュ時に自動再起動しない | メインスレッド最小化 + Worker 自動再起動 | **不要**（長期プロセスが存在しない） |
| Worker Threads によるスレッド分離 | stdout 汚染防止 + 障害分離 | **不要** |
| Unix socket で Admin API を提供 | Worker Thread 内で HTTP サーバ起動 | **不要**（Apache が直接実行） |
| プロセスライフサイクル管理 | SIGTERM / stdin EOF ハンドラ + cleanup | **不要** |
| mutex によるシリアライズ | ローカル用途で許容 + 将来のキャッシュ最適化 | **不要**（Apache 内部でハッシュ参照） |

---

## PHP 版が優れる点

- **アーキテクチャの大幅な単純化**: プロセス管理・スレッド分離・socket 通信が全て消える
- **外部依存ゼロ**: Apache + PHP のみ。npm install 不要
- **導入障壁の低下**: WordPress / Laravel 開発者が既に持っている環境で完結
- **graceful 時の UX**: php-fpm なら API が途切れない（Node 版は prg: プロセスが kill される）
- **クラッシュリスク排除**: 長期プロセスが存在しないため、クラッシュの概念自体がない
- **登録済みルートのパフォーマンス**: `txt:` のハッシュキャッシュで高速に解決。Node 版の毎リクエスト `fs.existsSync`（グループ数×2回のディスクアクセス）が不要

## PHP 版が劣る点

- **未登録サブドメイン初回アクセス時のリダイレクト**: resolve.php による再スキャン→リダイレクトで1回のラウンドトリップが入る（Node 版は `fs.existsSync` で直接解決）。2回目以降は routing.map にキャッシュされるため差はない

## 変わらない点

- ルーティング優先順位のロジック（明示登録 > グループ解決）
- スラグのルール・バリデーション
- リバースプロキシ・WebSocket 対応
- SSL の仕組み（mkcert + ワイルドカード証明書）
- セキュリティモデル（localhost 限定）
- 管理 UI フロントエンドの設計
- routes.json のデータ構造
- `<Directory />` による全パス許可（graceful 不要の根拠）

---

## 保留事項

- **Cookie 書き換えの動的対応** — 動的ルーティングにおいて `ProxyPassReverseCookieDomain` を動的に設定する方法の検討。多くのローカル開発ではセッション Cookie の domain 属性は未設定であり問題にならないケースが多い。顕在化した場合は `mod_headers` の `Header edit` で対応する方針
- **PHP バージョン要件** — 最低 PHP 7.4 以上（`fn()` アロー関数等）。推奨 PHP 8.0 以上
