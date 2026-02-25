---
title: "機能仕様"
description: "DevRouter が提供する各機能の仕様と操作フローをまとめる"
status: "draft"
created_at: "2026-02-25"
updated_at: "2026-02-25"
refs:
  - "requirements/overview.md"
  - "design/architecture.md"
---

# 機能仕様

## 1. ベースドメイン

本システムは複数のベースドメインを登録できる。
すべてのサブドメインはいずれかのベースドメイン配下に生成される。

### 例

```
ベースドメイン:
  127.0.0.1.nip.io   ← インターネット接続あり（デフォルト）
  dev.local           ← dnsmasq 等で運用（オフライン可）
```

```
app.127.0.0.1.nip.io
app.dev.local
```

nip.io は推奨デフォルトであり、ハードコードされた依存ではない。
オフライン環境やカスタム DNS が必要な場合は、別のベースドメインを登録して対応する。

### DNS 設定

| ベースドメイン | DNS 解決方法 |
| --- | --- |
| `127.0.0.1.nip.io` | nip.io（外部サービス、設定不要） |
| `*.local` / `*.test` | dnsmasq / /etc/resolver |
| 任意ドメイン | /etc/hosts または社内 DNS |

### current ベースドメイン

管理 UI でベースドメインの current（既定）を切り替えられる。
管理 UI 上の URL 表示やコピーは current ベースドメインを使用する。

---

## 2. グループ公開（基本）

ディレクトリ構造をそのままサブドメインへ変換する。
これが本システムの基本的な公開方式である。

グループディレクトリを登録すると、直下のサブディレクトリが1階層サブドメインとして公開される。

```
グループ登録: /Users/me/sites/companyA
→ 直下が公開対象

sites/companyA/
  app/       → app.{base-domain}
  api/       → api.{base-domain}
  landing/   → landing.{base-domain}
```

複数のグループを登録できる。

```
グループ1: /Users/me/sites/companyA
グループ2: /Users/me/sites/companyB
グループ3: /Users/me/work/personal
```

グループ名は URL に含まれない（フラット名前空間）。
管理 UI でグループの優先順位（順序）を変更できる。

### スラグ衝突の扱い

**明示登録スラグ**: 既存スラグと重複する場合、登録時にエラーとして弾く。

**サブディレクトリ（自動検出）**: 複数グループに同名のサブディレクトリがある場合、先に登録されたグループが優先される。
管理 UI の上部に衝突の警告を常時表示し、どのスラグがどのグループに解決されるかを明示する。

```
⚠ スラグ衝突:
  app/ → companyA（優先） / companyB（無効）
  blog/ → companyA（優先） / personal（無効）
```

### DocumentRoot の自動検出

サブディレクトリ内に `public/` が存在する場合、自動的にそちらを DocumentRoot とする。

```
sites/companyA/
  app/
    public/    ← public/ があればこちらを DocumentRoot
    ...
  blog/
    index.php  ← public/ がなければディレクトリ直下を DocumentRoot
    ...
```

これにより Laravel 等（`public/` ベース）と WordPress 等（ルート直下）の両方に対応する。

### 新サブディレクトリの自動検出

グループディレクトリに新しいサブディレクトリを作成した場合、routing.map には即座に反映されない。
この問題は **resolve.php** で解決する。

```
ユーザーがグループディレクトリに new-app/ を作成
  ↓
ブラウザで new-app.127.0.0.1.nip.io にアクセス
  ↓
routing.map にマッチなし → Apache が resolve.php を実行
  ↓
resolve.php がグループディレクトリを再スキャン → new-app を発見 → routing.map 更新
  ↓
302 リダイレクト（同じ URL）
  ↓
routing.map にマッチ → サイト表示
```

ユーザーから見ると一瞬リダイレクトが入るだけで、**ディレクトリを作るだけでアクセス可能**になる。
管理 UI での操作は不要。

---

## 3. スラグ指定公開（オプション）

任意のローカルディレクトリを1階層のサブドメインとして公開する。
グループ階層を使わず、スッキリした URL にしたい場合に使用する。

```
/Users/me/sites/companyA/app → myapp.{base-domain}
```

---

## 4. リバースプロキシ公開（オプション）

ローカルアプリケーションをポート番号なしで公開する。

```
localhost:3000 → app.{base-domain}
localhost:5173 → vite.{base-domain}
localhost:8000 → api.{base-domain}
```

対象:

- Node（Express / Next.js / Vite）
- Python（Django / Flask）
- PHP Built-in Server
- 任意の HTTP サーバ

### WebSocket 対応

Vite / Next.js の HMR を維持するため、mod_proxy_wstunnel による WebSocket トンネルを有効化する。
`Upgrade: websocket` ヘッダを検出し、プロトコルを `ws://` に変換してプロキシする。

---

## 5. スラグのルール

明示登録スラグおよびグループ内サブディレクトリ名はすべて以下のルールに従う。

### 許可パターン

```
^[a-z0-9]([a-z0-9-]*[a-z0-9])?$
```

- 小文字英数字で始まり、小文字英数字で終わること
- 中間は小文字英数字およびハイフンを使用可
- 1文字（例: `a`）も有効
- 空文字列は不可

### グループ内サブディレクトリの扱い

ディレクトリ名がスラグパターンに一致しない場合（大文字、スペース、特殊文字を含む等）、そのディレクトリは自動公開の対象外となる。管理 UI で警告を表示する。

```
sites/companyA/
  app/           → app.{base-domain}       ✅ パターン一致
  my-site/       → my-site.{base-domain}   ✅ パターン一致
  My Project/    → （公開対象外、管理UIで警告） ❌ 大文字・スペース
```

### バリデーションタイミング

| タイミング | チェック内容 |
| --- | --- |
| 管理 UI 登録時（明示登録） | パターン一致 + 既存スラグとの重複 |
| routing.map 生成時（グループスキャン） | パターン一致 |

---

## 6. 管理 UI

ブラウザから以下を管理する。

- ベースドメイン管理（current 切替）
- グループ登録・管理（優先順位の変更）
- スラグ指定公開
- リバースプロキシ追加
- SSL 証明書管理（ベースドメインごとの発行ボタン・状態表示）
- 環境チェック

### アクセス URL

```
http://localhost
http://127.0.0.1
```

管理 UI は localhost / 127.0.0.1 でのみアクセス可能。

### UI 構成

管理 UI は単一の HTML ページで構成し、機能切替はページ内のタブ等で行う（クライアントサイドルーティング不使用）。
Apache の静的ファイル配信のみで動作し、SPA フォールバック設定は不要。

### 常時表示する警告・エラー

管理 UI の上部には、以下の警告・エラーを常時表示する:

- スラグ衝突（サブディレクトリの重複）が存在する場合
- routes.json のパース失敗によりバックアップから復元した場合
- routes.json とバックアップの両方が失敗し、空の初期状態で起動した場合

### 環境チェック

管理 UI に環境チェック画面を設ける。バックエンドが `apachectl -M` 等で検出した結果をチェックリスト形式で表示する。

**必須モジュール:**

| モジュール | 用途 |
| --- | --- |
| mod_rewrite | ルーティングルール・RewriteMap |
| mod_proxy | リバースプロキシ |
| mod_proxy_http | HTTP プロキシ |
| mod_proxy_wstunnel | WebSocket プロキシ（HMR 等） |
| mod_headers | X-Forwarded-Proto 設定 |
| PHP（mod_php or php-fpm） | 管理 API + 自動解決 |

**オプション:**

| 項目 | 用途 |
| --- | --- |
| mod_ssl | HTTPS 対応 |
| mkcert + ローカル CA | SSL 証明書発行 |

**表示例（有効化コマンドは OS を検出して出し分ける）:**

```
環境チェック:
  ✅ mod_rewrite
  ✅ mod_proxy
  ✅ mod_proxy_http
  ❌ mod_proxy_wstunnel  ← 「a2enmod proxy_wstunnel」を実行してください
  ✅ mod_headers
  ✅ PHP 8.x
  ── オプション ──
  ✅ mod_ssl
  ⚠ mkcert 未インストール  ← brew install mkcert && mkcert -install
```

---

## 7. SSL（オプション機能）

SSL は必須ではなくオプションとする。
ベースドメインごとに、管理 UI から明示的に証明書を発行する方式とする。

> **判断理由**: 自動発行にすると登録操作の副作用が増え、エラー時の切り分けが難しくなる。mkcert の理解が必要であり、初期導入障壁を上げないためオプションとした。

### 証明書戦略

1階層サブドメインにより、ベースドメインごとに `*.{base-domain}` のワイルドカード1枚で全サブドメインをカバーできる。一度発行すれば、以降のグループ登録・スラグ追加・サブディレクトリ追加で証明書の再発行は不要。

SSL が有効なベースドメインが複数ある場合は、全ワイルドカードを SAN として1枚の証明書にまとめ、単一 VirtualHost を維持する。

### 明示的発行フロー

```
管理UIで「HTTPS 有効化」押下時:
  1. routes.json の該当ベースドメインを ssl: true に更新
  2. 全ベースドメイン（ssl: true）の SAN 一覧を構築: *.{bd1}, *.{bd2}, ...
  3. mkcert で証明書発行
  4. HTTPS VirtualHost 設定を生成（初回のみ）
  5. apachectl graceful を実行
```

### 管理 UI での表示

ベースドメインごとに HTTPS の有効化状態と操作ボタンを表示する。

| 状態 | 表示 |
| --- | --- |
| mkcert 未インストール | ボタンをグレーアウト + OS 別インストールコマンドを表示 |
| mkcert インストール済み・ローカル CA 未登録 | ボタンをグレーアウト + `mkcert -install` の実行を促すメッセージを表示 |
| 準備完了（mkcert + CA 登録済み） | 「HTTPS 有効化」ボタンを有効化 |

### graceful 時の UX

php-fpm 使用時は graceful による API 切断が発生しない可能性が高く、レスポンスがそのまま返る。
即座に「完了」表示が可能。

---

## 8. PHP 処理

### 対応方式

mod_php および php-fpm の両方に対応する。推奨は php-fpm。

| | mod_php | php-fpm（推奨） |
| --- | --- | --- |
| 動的 DocumentRoot | RewriteRule でファイルパス書き換え | FastCGI パラメータで指定 |
| `DOCUMENT_ROOT` 正確性 | VirtualHost の固定値が返る | リクエスト毎に正確な値を設定可能 |
| .htaccess | `AllowOverride All` で動作 | 同様 |
| サイト別設定 | 不可（全サイト共通） | プール別に設定可能 |

### mod_php の制約

`$_SERVER['DOCUMENT_ROOT']` が実際のディレクトリと異なる固定値を返す。
主要フレームワーク（WordPress / Laravel 等）は `__DIR__` / `__FILE__` ベースでパスを解決するため実害は少ない。

---

## 9. セキュリティ

### 管理 UI 制限

管理 UI は以下の二重チェックで保護する。

1. **Host ヘッダ**: `localhost`、`127.0.0.1`、`[::1]` のみ許可
2. **送信元 IP**: `127.0.0.1` または `::1` のみ許可

以下はすべて拒否:

- `127.0.0.1.nip.io`（ベースドメインとして扱う）
- `192.168.*.*`（LAN 経由）
- 外部ネットワークからのアクセス

### ディレクトリ公開範囲

本システムは graceful 不要を実現するため `<Directory />` で全パスを許可している。
グループ登録時に `/etc` や `~/.ssh` 等のセキュリティ上重要なディレクトリを指定しないこと。

### 公開サイト

LAN 内からの閲覧は許可する。
外部からのアクセスはローカル DNS の性質上、到達しない。

### リモートアクセス（別端末からの利用）

VPN 等の閉じたネットワーク内で、別端末からの管理 UI 利用および開発サイト閲覧を可能にする。
デフォルトは localhost 限定とし、設定で有効化する。詳細は実装時に決定する。

---

## 10. ユーザ操作フロー

### 初期設定（1回）

- Apache モジュール確認
- ルーティング VirtualHost 追加
- ベースドメイン登録（デフォルト: 127.0.0.1.nip.io）
- （任意）SSL 有効化

### 通常利用

1. `http://localhost` で管理 UI へアクセス
2. グループ登録 or スラグ指定 or リバースプロキシ追加
3. 保存
4. URL クリック

すべてのルーティング操作は再起動不要（routing.map の更新で即時反映）。
SSL 証明書の発行時のみ graceful が発生する。
