# リファクタリングタスク

## Phase 1: 構造改善（P0）

| ファイル | 概要 | ステータス | 優先度 |
|---|---|---|---|
| [split-store-php.md](split-store-php.md) | store.php の責務分割（4モジュールに分離） | open | P0 |
| [add-unit-tests.md](add-unit-tests.md) | PHPUnit による自動テストの追加 | open | P0 |

## Phase 2: API・UX 改善（P1）

| ファイル | 概要 | ステータス | 優先度 |
|---|---|---|---|
| [unify-api-response.md](unify-api-response.md) | API レスポンス形式の統一（共通エンベロープ） | open | P1 |

## Phase 3: 運用品質（P2）

| ファイル | 概要 | ステータス | 優先度 |
|---|---|---|---|
| [restart-throttle.md](restart-throttle.md) | graceful restart の連続実行制御 | open | P2 |
| [improve-frontend-state.md](improve-frontend-state.md) | フロントエンド状態管理の改善 | open | P2 |
| [add-logging.md](add-logging.md) | ファイルベースのログ機構の追加 | open | P2 |

## Phase 4: 堅牢化（P3）

| ファイル | 概要 | ステータス | 優先度 |
|---|---|---|---|
| [externalize-os-paths.md](externalize-os-paths.md) | OS 固有パスのハードコード排除 | open | P3 |
| [secure-directory-listing.md](secure-directory-listing.md) | ディレクトリ一覧のセキュリティ強化 | open | P3 |
