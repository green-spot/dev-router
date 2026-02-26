# 実装タスク

## Phase 1: 基盤（P0）

| ファイル | 概要 | ステータス | 優先度 |
|---|---|---|---|
| [scaffolding.md](scaffolding.md) | ディレクトリ構造のスキャフォールディング | done | P0 |
| [store-php.md](store-php.md) | store.php の実装（コアデータ管理 + ルーティング設定生成） | done | P0 |
| [apache-config.md](apache-config.md) | Apache 設定ファイルの作成 | done | P0 |
| [setup-script.md](setup-script.md) | セットアップスクリプトの実装 | done | P0 |

## Phase 2: API + 自動解決（P1）

| ファイル | 概要 | ステータス | 優先度 |
|---|---|---|---|
| [api-domains.md](api-domains.md) | ベースドメイン API の実装 | done | P1 |
| [api-groups.md](api-groups.md) | グループ API の実装 | done | P1 |
| [api-routes.md](api-routes.md) | ルート API の実装 | done | P1 |
| [resolve-php.md](resolve-php.md) | resolve.php の実装（廃止予定） | done | P1 |
| [api-health-scan.md](api-health-scan.md) | ヘルスチェック + スキャン API の実装 | done | P1 |
| [api-env-check.md](api-env-check.md) | 環境チェック API の実装 | done | P1 |

## Phase 3: フロントエンド（P1）

| ファイル | 概要 | ステータス | 優先度 |
|---|---|---|---|
| [frontend-ui.md](frontend-ui.md) | 管理 UI フロントエンドの実装 | done | P1 |

## Phase 4: SSL + その他（P2-P3）

| ファイル | 概要 | ステータス | 優先度 |
|---|---|---|---|
| [ssl-support.md](ssl-support.md) | SSL 対応の実装 | done | P2 |
| [smoke-test.md](smoke-test.md) | スモークテストスクリプトの実装 | done | P2 |
| [uninstall-script.md](uninstall-script.md) | アンインストールスクリプトの実装 | done | P3 |

## アーキテクチャ変更（P0）

| ファイル | 概要 | ステータス | 優先度 |
|---|---|---|---|
| [routing-architecture-change.md](routing-architecture-change.md) | RewriteMap → VirtualHost 生成方式への変更 | done | P0 |

## ドキュメント更新（P1）

| ファイル | 概要 | ステータス | 優先度 |
|---|---|---|---|
| [update-architecture-doc.md](update-architecture-doc.md) | 設計ドキュメントの VirtualHost 方式への更新 | done | P1 |
| [update-requirements-doc.md](update-requirements-doc.md) | 要件ドキュメントの VirtualHost 方式への更新 | done | P1 |
| [update-decisions-doc.md](update-decisions-doc.md) | 意思決定記録の更新・追加 | done | P1 |
