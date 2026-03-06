---
title: "機能仕様"
description: "DevRouter が提供する各機能の仕様と操作フローをまとめる"
status: "review"
created_at: "2026-02-25 00:00:00"
updated_at: "2026-03-05 13:39:21"
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
myapp.projects.127.0.0.1.nip.io
myapp.projects.dev.local
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

グループ登録時に**スラグ**（サブドメインとして使う名前）と**ディレクトリパス**を指定する。グループディレクトリ直下のサブディレクトリが2階層サブドメインとして公開される。

```
グループ登録: slug=projects, path=/Users/me/sites/projects
→ 直下が公開対象（2階層サブドメイン）

sites/projects/
  app/       → app.projects.{base-domain}
  api/       → api.projects.{base-domain}
  landing/   → landing.projects.{base-domain}
```

複数のグループを登録できる。各グループが独自のサブドメイン空間を持つ。

```
グループ1: slug=work, path=/Users/me/sites/companyA
  → app.work.{base-domain}

グループ2: slug=personal, path=/Users/me/work/personal
  → blog.personal.{base-domain}
```

管理 UI でグループの優先順位（順序）を変更できる（ドラッグ&ドロップ）。

### ラベル

グループにはオプションでラベル（表示名）を設定できる。ラベルは管理 UI での識別用であり、URL には含まれない。

### サブディレクトリの動的解決

グループは Apache の `VirtualDocumentRoot` によりサブドメインからディレクトリを動的に解決する。グループディレクトリに新しいサブディレクトリを作成した場合、即座にアクセス可能になる。スキャンや Apache の再起動は不要。

```
ユーザーがグループディレクトリに new-app/ を作成
  ↓
new-app.projects.127.0.0.1.nip.io にアクセス → サイト表示
```

### スラグの衝突

2階層サブドメイン構造により、グループ間でのサブディレクトリ名の衝突は発生しない（各グループが独自のサブドメイン空間を持つため）。

グループスラグ同士の重複は登録時にエラーとして弾く。

---

## 3. スラグ指定公開（明示ルート）

任意のローカルディレクトリまたはリバースプロキシを1階層のサブドメインとして公開する。
グループの2階層サブドメインとは異なり、`slug.{base-domain}` の形式になる。

```
ディレクトリ公開:
  /Users/me/sites/myapp → myapp.{base-domain}

リバースプロキシ:
  http://localhost:3000 → api.{base-domain}
  http://localhost:5173 → vite.{base-domain}
```

明示ルートにもオプションでラベルを設定できる。

### リバースプロキシの対象

- Node（Express / Next.js / Vite）
- Python（Django / Flask）
- PHP Built-in Server
- 任意の HTTP サーバ

### WebSocket 対応

全てのプロキシルートは WebSocket に対応する。`Upgrade: websocket` ヘッダを検出し、プロトコルを `ws://` に変換してプロキシする。Vite / Next.js の HMR を維持する。

### 明示ルートの優先

明示ルートは routes.conf 内でグループのワイルドカード VirtualHost より先に記述されるため、Apache の先勝ちルールにより優先される。

---

## 4. スラグのルール

明示登録スラグおよびグループスラグはすべて以下のルールに従う。

### 許可パターン

```
^[a-z0-9]([a-z0-9-]*[a-z0-9])?$
```

- 小文字英数字で始まり、小文字英数字で終わること
- 中間は小文字英数字およびハイフンを使用可
- 1文字（例: `a`）も有効
- 最大63文字
- 空文字列は不可

---

## 5. 管理 UI

ブラウザから以下を管理する。

- ベースドメイン管理（current 切替）
- グループ登録・管理（スラグ、パス、ラベル、優先順位の変更、SSL 有効化）
- スラグ指定公開（ディレクトリ・リバースプロキシ）
- SSL 証明書管理（ドメイン単位・グループ単位の有効化）
- 環境チェック
- ダッシュボード（全ルートの一覧表示）

### アクセス URL

```
http://localhost
http://127.0.0.1
```

管理 UI は localhost / 127.0.0.1 でのみアクセス可能。

### UI 構成

管理 UI は単一の HTML ページで構成し、Alpine.js でインタラクティブな操作を実現する。機能切替はページ内のタブで行う。Apache の静的ファイル配信のみで動作する。

### 常時表示する警告・エラー

管理 UI の上部には、以下の警告・エラーを常時表示する:

- routes.json のパース失敗によりバックアップから復元した場合
- routes.json とバックアップの両方が失敗し、空の初期状態で起動した場合

### 環境チェック

管理 UI に環境チェック画面を設ける。バックエンドが `apachectl -M` 等で検出した結果をチェックリスト形式で表示する。

**必須モジュール:**

| モジュール | 用途 |
| --- | --- |
| mod_rewrite | リダイレクトルール |
| mod_headers | X-Forwarded-Proto 設定 |
| mod_vhost_alias | グループのワイルドカード VirtualHost（VirtualDocumentRoot） |

**プロキシ関連:**

| モジュール | 用途 |
| --- | --- |
| mod_proxy | リバースプロキシ |
| mod_proxy_http | HTTP プロキシ |
| mod_proxy_wstunnel | WebSocket プロキシ（HMR 等） |

**オプション:**

| 項目 | 用途 |
| --- | --- |
| mod_ssl | HTTPS 対応 |
| mkcert + ローカル CA | SSL 証明書発行 |

表示例（有効化コマンドは OS を検出して出し分ける）:

```
環境チェック:
  ✅ mod_rewrite
  ✅ mod_headers
  ✅ mod_vhost_alias
  ── プロキシ ──
  ✅ mod_proxy
  ✅ mod_proxy_http
  ❌ mod_proxy_wstunnel  ← 「a2enmod proxy_wstunnel」を実行してください
  ── オプション ──
  ✅ mod_ssl
  ⚠ mkcert 未インストール  ← brew install mkcert && mkcert -install
```

---

## 6. SSL（オプション機能）

SSL は必須ではなくオプションとする。
ベースドメインまたはグループごとに、管理 UI から明示的に証明書を発行する方式とする。

### 証明書戦略

全 SSL 有効対象の SAN（Subject Alternative Name）を1枚のワイルドカード証明書にまとめる。

- ベースドメインの SSL 有効化: `*.{base-domain}` を SAN に追加（明示ルート用）
- グループの SSL 有効化: `*.{group}.{base-domain}` を全ドメイン分 SAN に追加（グループ用）

一度発行すれば、グループ内のサブディレクトリ追加で証明書の再発行は不要。新しいベースドメインやグループの SSL を有効化する際は、全 SAN を含む証明書を再発行する。

### 明示的発行フロー

```
管理UIで「HTTPS 有効化」押下時:
  1. routes.json の該当ドメインまたはグループの ssl を true に更新
  2. 全 SAN 一覧を構築: *.{bd1}, *.{group1}.{bd1}, ...
  3. mkcert で証明書発行
  4. HTTPS VirtualHost 設定をテンプレートから展開（初回のみ）
  5. routes-ssl.conf を再生成
  6. Apache graceful restart を実行
```

### 管理 UI での表示

ベースドメインごと・グループごとに HTTPS の有効化状態と操作ボタンを表示する。

| 状態 | 表示 |
| --- | --- |
| mkcert 未インストール | ボタンをグレーアウト + OS 別インストールコマンドを表示 |
| mkcert インストール済み・ローカル CA 未登録 | ボタンをグレーアウト + `mkcert -install` の実行を促すメッセージを表示 |
| 準備完了（mkcert + CA 登録済み） | 「HTTPS 有効化」ボタンを有効化 |

---

## 7. PHP 処理

### 対応方式

mod_php および php-fpm の両方に対応する。推奨は php-fpm。

VirtualHost 生成方式では、各ルートが独立した VirtualHost を持ち、`DocumentRoot` が正しく設定されるため、mod_php でも `$_SERVER['DOCUMENT_ROOT']` が正確な値を返す。

| | mod_php | php-fpm（推奨） |
| --- | --- | --- |
| DocumentRoot | VirtualHost ごとに正しく設定される | 同様 |
| `DOCUMENT_ROOT` 正確性 | VirtualHost の DocumentRoot が正確に返る | 同様 |
| .htaccess | `AllowOverride All` で動作 | 同様 |
| サイト別設定 | 不可（全サイト共通） | プール別に設定可能 |

---

## 8. セキュリティ

### 管理 UI 制限

管理 UI は以下の二重チェックで保護する。

1. **Host ヘッダ**: `localhost`、`127.0.0.1`、`[::1]` のみ許可
2. **送信元 IP**: `127.0.0.1` または `::1` のみ許可

以下はすべて拒否:

- `127.0.0.1.nip.io`（ベースドメインとして扱う）
- `192.168.*.*`（LAN 経由）
- 外部ネットワークからのアクセス

### CSRF 対策

管理 API は Origin ヘッダを検証し、`localhost` / `127.0.0.1` / `[::1]` 以外の Origin からのリクエストを拒否する。

### ディレクトリ公開範囲

本システムは VirtualHost ごとに `<Directory>` で対象パスを許可している。
グループ登録時に `/etc` や `~/.ssh` 等のセキュリティ上重要なディレクトリを指定しないこと。

### 公開サイト

LAN 内からの閲覧は許可する。
外部からのアクセスはローカル DNS の性質上、到達しない。

### リモートアクセス（別端末からの利用）

VPN 等の閉じたネットワーク内で、別端末からの管理 UI 利用および開発サイト閲覧を可能にする。
デフォルトは localhost 限定とし、設定で有効化する。詳細は実装時に決定する。

---

## 9. ユーザ操作フロー

### 初期設定（1回）

- `sudo bash setup.sh` を実行（Apache 環境の自動検出 + sudoers 設定 + ファイルデプロイ）
- Apache の httpd.conf に VirtualHost 設定を Include
- Apache モジュール確認
- ベースドメイン登録（デフォルト: 127.0.0.1.nip.io）
- （任意）SSL 有効化

### 通常利用

1. `http://localhost` で管理 UI へアクセス
2. グループ登録（スラグ + ディレクトリパス）or スラグ指定 or リバースプロキシ追加
3. 保存
4. URL クリック

グループ登録後は、グループディレクトリにサブディレクトリを追加するだけで即座にアクセス可能。
明示ルートの変更時は routes.conf の再生成 + graceful restart で反映される（1秒未満、既存接続を中断しない）。
