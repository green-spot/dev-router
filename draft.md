# Apache Dev Router（仮称）設計ドキュメント

## 1. 目的

ローカル開発において、以下の問題を解決する。

* VirtualHost を増やすたびに Apache 再起動が必要
* Node / WordPress / 静的サイトを同時に扱いにくい
* ポート番号付き URL（:3000 など）が煩雑
* サブドメイン構成の再現が困難
* チーム・会社単位のディレクトリ分離が難しい

本システムは Apache を「Webサーバ」ではなく
**ローカル開発用のルーティングゲートウェイ（Dev Router）**として動作させる。

---

## 2. ゴール

ユーザは以下の操作のみでローカル公開を完了できる。

1. 初期設定（1回のみ）
2. 管理UIへアクセス
3. フォルダ or アプリケーションURLを登録
4. サブドメインURLへアクセス

ほとんどの操作で Apache の再起動は不要。
ルーティングは Node メインスレッド（Apache サブプロセス）のインメモリ状態で即反映される。
SSL証明書の明示的な発行時のみ graceful が発生する（詳細はセクション8参照）。

---

## 3. ベースドメイン

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
オフライン環境やカスタムDNSが必要な場合は、別のベースドメインを登録して対応する。

### DNS設定

| ベースドメイン | DNS解決方法 |
| --- | --- |
| `127.0.0.1.nip.io` | nip.io（外部サービス、設定不要） |
| `*.local` / `*.test` | dnsmasq / /etc/resolver |
| 任意ドメイン | /etc/hosts または社内DNS |

### current ベースドメイン

管理UIでベースドメインの current（既定）を切り替えられる。
管理UI上のURL表示やコピーは current ベースドメインを使用する。

---

## 4. 提供機能

### 4.1 グループ公開（基本）

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

グループ名はURLに含まれない（フラット名前空間）。
複数グループに同名のサブディレクトリがある場合は、先に登録されたグループが優先される。
管理UIでグループの優先順位（順序）を変更できる。

#### DocumentRoot の自動検出

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

---

### 4.2 スラグ指定公開（オプション）

任意のローカルディレクトリを1階層のサブドメインとして公開する。
グループ階層を使わず、スッキリしたURLにしたい場合に使用する。

例：

```
/Users/me/sites/companyA/app → myapp.{base-domain}
```

---

### 4.3 リバースプロキシ公開（オプション）

ローカルアプリケーションをポート番号無しで公開する。

```
localhost:3000 → app.{base-domain}
localhost:5173 → vite.{base-domain}
localhost:8000 → api.{base-domain}
```

対象：

* Node (Express / Next.js / Vite)
* Python (Django / Flask)
* PHP Built-in Server
* 任意のHTTPサーバ

---

### 4.4 管理UI

ブラウザから以下を管理する。

* ベースドメイン管理（current 切替）
* グループ登録・管理（優先順位の変更）
* スラグ指定公開
* リバースプロキシ追加
* SSL証明書管理（ベースドメインごとの発行ボタン・状態表示）
* 環境チェック（後述）

#### 環境チェック

管理UIに環境チェック画面を設ける。バックエンドが `apachectl -M` 等で検出した結果をチェックリスト形式で表示する。

**必須モジュール:**

| モジュール | 用途 |
| --- | --- |
| mod_rewrite | ルーティングルール・RewriteMap |
| mod_proxy | リバースプロキシ |
| mod_proxy_http | HTTP プロキシ |
| mod_proxy_wstunnel | WebSocket プロキシ（HMR等） |
| mod_headers | X-Forwarded-Proto 設定 |

**オプション:**

| 項目 | 用途 |
| --- | --- |
| mod_ssl | HTTPS 対応 |
| mkcert + ローカルCA | SSL 証明書発行 |

**表示例:**

```
環境チェック:
  ✅ mod_rewrite
  ✅ mod_proxy
  ✅ mod_proxy_http
  ❌ mod_proxy_wstunnel  ← 「a2enmod proxy_wstunnel」を実行してください
  ✅ mod_headers
  ── オプション ──
  ✅ mod_ssl
  ⚠ mkcert 未インストール  ← brew install mkcert && mkcert -install
```

管理UI：

```
http://localhost
http://127.0.0.1
```

管理UIは localhost / 127.0.0.1 でのみアクセス可能。
詳細は「10. セキュリティ」を参照。

---

## 5. アーキテクチャ

本ドキュメント中の `/router/` はインストールディレクトリ `ROUTER_HOME` のデフォルト値である。
ファイル配置の詳細はセクション13を参照。

### 5.1 構成

```
Browser
   ↓
Apache (ルータ / ポート80 / SSL有効時は443も)
   ├ Host: localhost or 127.0.0.1
   │    ├ /api/* → Node Worker Thread へ Unix socket proxy
   │    └ それ以外 → 管理UI 静的ファイル配信（Apache 直接）
   └ Host: *.{base-domain}
        ↓
      RewriteMap (prg: タイプ)
        → Node メインスレッドへ問い合わせ（stdin/stdout）
             ↓
           各アプリケーション / ディレクトリ
```

```
Node プロセス（Apache prg: サブプロセス）
   ├ メインスレッド: Router
   │    stdin/stdout でルーティング応答（最小限のコード）
   └ Worker Thread: Admin API
        Unix socket で管理 API サーバ（/api/*）
        ルート変更時 → MessagePort でメインスレッドに通知

管理UI フロントエンド（静的ファイル）
   {ROUTER_HOME}/app/public/ に配置
   Apache が直接配信（Node 不要）
   JS が /api/* を呼び出して操作を実行
   Node 停止時はエラー検出 → 復旧手順を表示
```

Apache はファイルサーバではなく
**HTTPルーティング層**として動作する。

Node プロセスは Apache の **RewriteMap `prg:` サブプロセス**として起動される。
Apache が Node のライフサイクル（起動・停止）を管理するため、
別途プロセスマネージャは不要である。

Node プロセス内部は **Worker Threads** により2つの役割を分離する:

* **メインスレッド（Router）**: stdin/stdout で Apache からのルーティング問い合わせに応答。最小限のコードのみで構成し、クラッシュリスクを極小化する。
* **Worker Thread（Admin API）**: Unix socket 上で管理 API（`/api/*`）を提供。ルート変更時は `MessagePort` 経由でメインスレッドのインメモリ状態を即時更新する。管理UI のフロントエンド（静的ファイル）は Apache が直接配信するため、Node がクラッシュしても管理画面自体は表示でき、復旧手順をユーザに提示できる。

#### スレッド分離の理由

`prg:` プロセスは「1行たりとも不正な出力を許さず、1回たりとも応答を返し損ねてはいけない」という制約を持つ。
管理UIの HTTP フレームワーク・バリデーション・ファイル操作等の複雑なコードをメインスレッドに同居させると、
未キャッチ例外で Router ごと落ちるリスクがある。

Worker Thread を使うことで:

* Worker がクラッシュしてもメインスレッドは影響を受けない
* メインスレッドが Worker を自動再起動する
* Apache から見ると1プロセスのままで、外部プロセスマネージャは不要
* Worker 内の `console.log` がメインの stdout を汚染しない（`{ stdout: true }` オプション）

---

### 5.2 核となる技術

* mod_rewrite
* RewriteMap（prg 形式 — Node.js サブプロセス）
* mod_proxy / mod_proxy_http / mod_proxy_wstunnel
* mod_headers（X-Forwarded-Proto 設定）
* mod_ssl（SSL有効化時のみ）
* Node.js（ルーティングエンジン + 管理UIバックエンド、Worker Threads によるスレッド分離）
* ワイルドカードDNS（nip.io / dnsmasq 等）

サイトごとに VirtualHost を増やさず、HTTP用（:80）の1つで全サイトを処理する。
SSL有効化時はHTTPS用（:443）を追加し、同じルーティングルールを共有する。

---

### 5.3 ルーティング優先順位

リクエスト処理は以下の順で解決する。

1. **管理UI** — Host が localhost or 127.0.0.1 の場合
   - `/api/*` → Unix socket 経由で Worker Thread バックエンドへ proxy
   - それ以外 → `{ROUTER_HOME}/app/public/` から静的ファイルを配信（Apache 直接）
2. **Node ルーター問い合わせ** — ホスト名をメインスレッドに送信し、ルーティング先を取得
   - Node 内部の解決順:
     a. ベースドメイン直アクセス → 管理UIへのリダイレクトURLを返却
     b. 明示登録（スラグ指定・リバースプロキシ）を優先照合
     c. グループ解決（登録ディレクトリ配下のサブディレクトリを自動検出）
     ※ a はスラグを持たないため b/c とは排他的。b と c が同名の場合は b が優先。
   - Apache は返り値を環境変数（`ROUTE`）に格納し、1回の問い合わせ結果でプレフィックス／プロトコルにより処理を分岐:
     - `R:` プレフィックス → HTTP 302 リダイレクト
     - WebSocket（`Upgrade` ヘッダ検出時）→ `ws://` プロトコルでプロキシ
     - HTTP URL → リバースプロキシ（`[P]` フラグ）
     - ディレクトリパス → ファイル配信（`[L]` フラグ）
3. **フォールバック** — Node が NULL を返した場合 404（Apache 側で NULL を明示ハンドリング）

サブドメインは1階層のみ対応する（フラット名前空間）。
2階層以上（sub.site.base-domain）はルーティング対象外となり Node が NULL を返す。

同名サブドメインが衝突した場合は、明示登録（スラグ指定・リバースプロキシ）が
グループ自動解決より常に優先される。

---

## 6. ルーティングエンジン設計

### 6.1 RewriteMap prg 方式

ルーティングの中核は **Node プロセスのメインスレッド**が担う。
Apache の RewriteMap `prg:` タイプにより、Node を Apache のサブプロセスとして起動する。

```apache
RewriteMap router "prg:/usr/local/bin/node /router/app/router.js"
```

#### 通信プロトコル

Apache が stdin でホスト名を送信し、Node メインスレッドが stdout でルーティング先を返す。

```
Apache → stdin:  "app.127.0.0.1.nip.io\n"
Node   → stdout: "/Users/me/sites/companyA/app/public\n"

Apache → stdin:  "vite.127.0.0.1.nip.io\n"
Node   → stdout: "http://localhost:5173\n"

Apache → stdin:  "127.0.0.1.nip.io\n"
Node   → stdout: "R:http://localhost\n"

Apache → stdin:  "unknown.127.0.0.1.nip.io\n"
Node   → stdout: "NULL\n"
```

#### stdin の行単位読み取り

Node の `data` イベントは行単位で呼ばれる保証がない（複数行結合・行途中切れが発生しうる）。
**`readline` モジュールの使用が必須**である。

```javascript
const readline = require('readline');
const rl = readline.createInterface({ input: process.stdin, terminal: false });
rl.on('line', (line) => {
  try {
    process.stdout.write(resolve(line.trim(), state) + '\n');
  } catch (e) {
    process.stderr.write(`resolve error: ${e.message}\n`);
    process.stdout.write('NULL\n');  // 例外時も必ず応答を返す
  }
});
```

#### 設計上の理由

| 観点 | 説明 |
| --- | --- |
| プロセス管理不要 | Apache がライフサイクルを管理 |
| 即時反映 | インメモリ状態の更新で即座に反映 |
| 柔軟なロジック | public/ 検出・衝突検出・ベースドメイン解析を Node 側で実行 |
| 管理UIとの統合 | Worker Thread 経由で同一プロセス内に管理UIを収容 |
| 障害分離 | Worker がクラッシュしてもメインスレッドの stdin/stdout 応答は継続 |

#### `prg:` サブプロセスの固有制約と対策

Apache の `prg:` タイプは「stdin で問い合わせ、stdout で応答」という単純なプロトコルだが、
以下の固有制約がある。本設計ではすべて対策済みである。

| 制約 | リスク | 本設計での対策 | 対策箇所 |
| --- | --- | --- | --- |
| **stdin は行単位を保証しない** | Node の `data` イベントは複数行結合・行途中切れが発生しうる。負荷時にリクエストが混線する再現困難なバグになる | `readline` モジュールで行単位に分割（`data` イベント直接使用は禁止） | 6.1 stdin の行単位読み取り |
| **未応答 = Apache 全体ハング** | `prg:` は同期応答を前提としており、1行でも応答が欠けると Apache がリクエストをブロックし続ける | `try-catch` で例外を確実に捕捉し、必ず `NULL\n` を返す | 6.1 コード例、6.4 resolve 呼び出し |
| **stdout 汚染 = プロトコル破壊** | ルーティング応答以外の出力が stdout に混入すると、Apache がそれをルーティング先として解釈する | メインスレッドは `console.log` 禁止。Worker Thread は `{ stdout: true }` で stdout を分離し、stderr に転送 | 5.1 スレッド分離、6.4 Worker 起動 |
| **クラッシュ時に管理UIが全滅** | Node が死ぬとユーザは状況を把握できず復旧方法もわからない | 管理UIフロントエンド（静的ファイル）を Apache が直接配信。Node 停止時も管理画面は表示され、JS が API エラーを検出して復旧手順を表示する | 5.1 構成、6.5 ルール 1a/1b |
| **クラッシュ時に自動再起動しない** | `prg:` プロセスが死ぬと Apache は壊れたパイプに書き込み続け、全リクエストがハングする | メインスレッドは最小限のコード + try-catch で絶対に落とさない設計。Worker がクラッシュしてもメインは影響を受けない | 5.1 スレッド分離の理由 |
| **mutex によるシリアライズ** | 同時リクエストは直列処理される | ローカル開発用途では問題なし。resolve 関数は軽量に保つ | — |
| **インスタンスは1つのみ** | 水平スケール不可 | 同上 | — |

> **設計上の注意**: 上記の制約は `prg:` アーキテクチャに固有のものであり、本設計で既に対策されている。
> レビュー時にこれらを「問題」として指摘する前に、対策箇所を確認すること。

#### プロセスのライフサイクル管理

Apache の graceful restart 時、`prg:` プロセスの stdin が閉じられ、SIGTERM が送信される。
メインスレッドは以下のハンドラで確実にクリーンアップする:

```javascript
// stdin EOF（Apache がプロセスを停止しようとしている）
rl.on('close', () => {
  cleanup();
});

// SIGTERM（Apache graceful restart）
process.on('SIGTERM', () => {
  cleanup();
});

function cleanup() {
  if (worker) worker.terminate();
  try { fs.unlinkSync(SOCKET_PATH); } catch (e) {}
  process.exit(0);
}
```

Worker Thread がアクティブなハンドル（HTTP サーバ）を保持していると、
stdin EOF だけではプロセスが終了しない。明示的な `worker.terminate()` が必須である。

---

### 6.2 データ管理

ルーティングデータは **メインスレッドのインメモリ状態**として保持し、
**JSON ファイル**で永続化する。

```
/router/data/routes.json
```

#### 状態同期フロー

管理UI（Worker Thread）からの変更は以下の順で反映される:

1. Worker が routes.json にアトミック書き込み（一時ファイル + rename）
2. Worker が `parentPort.postMessage()` でメインスレッドに新しい状態を送信
3. メインスレッドが `state = msg.state` でインメモリ状態を即時更新

```javascript
// Worker 側（admin.js）
function updateRoutes(newState) {
  // アトミック書き込み（書き込み中のクラッシュによる JSON 破損を防止）
  const tmp = routesPath + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify(newState, null, 2));
  fs.renameSync(tmp, routesPath);
  // メインスレッドに即時通知
  parentPort.postMessage({ type: 'routes-updated', state: newState });
}
```

Node.js のシングルスレッド EventLoop により、`message` コールバックと `line` コールバックは
同一スレッド上で順番に実行される。コールバック間の割り込みは構造的に発生しないため、
`state` の競合は起きない。

#### ルーティング変更の即時反映

グループ登録・スラグ指定・リバースプロキシ追加等のルーティング変更は、
アトミック書き込み + MessagePort 通知のみで即時反映される。graceful は不要。

```javascript
// Worker 側（updateRoutes を使用）
function registerGroup(group) {
  const newState = { ...currentState, groups: [...currentState.groups, group] };
  updateRoutes(newState);
  // 完了。graceful 不要、即座にルーティング有効
}
```

#### routes.json の構造（例）

```json
{
  "baseDomains": [
    {
      "domain": "127.0.0.1.nip.io",
      "current": true,
      "ssl": true
    },
    {
      "domain": "dev.local",
      "current": false,
      "ssl": false
    }
  ],
  "groups": [
    {
      "path": "/Users/me/sites/companyA"
    },
    {
      "path": "/Users/me/sites/companyB"
    }
  ],
  "routes": [
    {
      "slug": "myapp",
      "target": "/Users/me/sites/companyA/app/public",
      "type": "directory"
    },
    {
      "slug": "vite",
      "target": "http://localhost:5173",
      "type": "proxy"
    },
    {
      "slug": "api",
      "target": "http://localhost:8000",
      "type": "proxy"
    }
  ]
}
```

JSON 形式のため、パスにスペースや特殊文字を含む場合でも制約なく扱える。

---

### 6.3 大文字小文字の正規化

DNS は大文字小文字を区別しないため、Node プロセス内でホスト名を小文字に正規化してからルーティングを解決する。

```javascript
const hostname = input.trim().toLowerCase();
```

---

### 6.4 Node ルーターのロジック

#### メインスレッド（router.js）全体構成

```javascript
const { Worker } = require('worker_threads');
const readline = require('readline');
const fs = require('fs');
const path = require('path');

const ROUTES_PATH = path.join(__dirname, '../data/routes.json');
const SOCKET_PATH = path.join(__dirname, '../run/admin.sock');

// 1. 起動時に routes.json を同期読み込み（stdin 応答前に完了させる）
let state = JSON.parse(fs.readFileSync(ROUTES_PATH, 'utf8'));

// 2. Worker 起動（Admin UI）
let restartCount = 0;
const MAX_RESTARTS = 5;
let worker = spawnWorker();

function spawnWorker() {
  try { fs.unlinkSync(SOCKET_PATH); } catch (e) {}

  const w = new Worker(path.join(__dirname, 'admin.js'), {
    stdout: true,   // Worker の stdout をメインから分離（Apache 汚染防止）
    workerData: { socketPath: SOCKET_PATH, routesPath: ROUTES_PATH }
  });

  // Worker の stdout を stderr に転送（Apache error log で確認可能）
  w.stdout.on('data', (data) => {
    process.stderr.write(`[admin] ${data}`);
  });

  w.on('message', (msg) => {
    if (msg.type === 'routes-updated') state = msg.state;
  });

  w.on('error', (err) => {
    process.stderr.write(`Admin crashed: ${err.message}\n`);
  });

  w.on('exit', (code) => {
    if (code !== 0 && restartCount < MAX_RESTARTS) {
      restartCount++;
      const delay = Math.min(1000 * restartCount, 5000);
      process.stderr.write(`Admin exited ${code}, restarting in ${delay}ms (${restartCount}/${MAX_RESTARTS})...\n`);
      setTimeout(() => { worker = spawnWorker(); }, delay);
    } else if (restartCount >= MAX_RESTARTS) {
      process.stderr.write(`Admin exceeded max restarts, giving up.\n`);
    }
  });

  return w;
}

// 3. ルーティング応答（メインスレッドの責務はここだけ）
const rl = readline.createInterface({ input: process.stdin, terminal: false });
rl.on('line', (line) => {
  try {
    process.stdout.write(resolve(line.trim(), state) + '\n');
  } catch (e) {
    process.stderr.write(`resolve error: ${e.message}\n`);
    process.stdout.write('NULL\n');
  }
});

// 4. ライフサイクル管理
rl.on('close', () => cleanup());
process.on('SIGTERM', () => cleanup());

function cleanup() {
  if (worker) worker.terminate();
  try { fs.unlinkSync(SOCKET_PATH); } catch (e) {}
  process.exit(0);
}
```

#### resolve 関数

メインスレッドの `resolve` 関数がホスト名に対して以下の順で解決する。

```javascript
function resolve(hostname, state) {
  hostname = hostname.toLowerCase();

  // 1. ベースドメインを特定し、サブドメイン部分を抽出
  const parsed = parseHostname(hostname, state.baseDomains);
  if (!parsed) return 'NULL';

  const { slug, isBare } = parsed;

  // 2. ベースドメイン直アクセス → 管理UIへリダイレクト
  if (isBare) return 'R:http://localhost';

  // 3. スラグのバリデーション（ディレクトリトラバーサル防止）
  const SLUG_PATTERN = /^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/;
  if (!SLUG_PATTERN.test(slug)) return 'NULL';

  // 4. 明示登録（スラグ指定・リバースプロキシ）を優先照合
  const route = state.routes.find(r => r.slug === slug);
  if (route) return route.target;

  // 5. グループ解決（登録順に走査、最初にマッチしたグループが優先）
  for (const group of state.groups) {
    const sitePath = path.join(group.path, slug);
    const publicPath = path.join(sitePath, 'public');
    if (fs.existsSync(publicPath)) return publicPath;
    if (fs.existsSync(sitePath)) return sitePath;
  }

  // 6. 未マッチ
  return 'NULL';
}
```

#### ホスト名解析

登録済みベースドメインをラベル数の降順でソートし、長い方から順にマッチングする。
サブドメインは1階層のみ（単一ラベル）を受け付ける。2階層以上は NULL を返す。

```
入力: app.127.0.0.1.nip.io

ベースドメイン照合（降順）:
  127.0.0.1.nip.io（5ラベル） → マッチ
  サブドメイン部分: app
    → slug: app, isBare: false

入力: 127.0.0.1.nip.io
  → slug: null, isBare: true

入力: sub.app.127.0.0.1.nip.io
  → サブドメインが2階層 → NULL（ルーティング対象外）
```

---

### 6.5 Apache ルーティングルール

Apache 側のルールは Node に問い合わせて返り値で分岐するだけのシンプルな構成となる。

```apache
RewriteEngine On
RewriteMap router "prg:/usr/local/bin/node /router/app/router.js"

# 1a. 管理API（Node Worker Thread へプロキシ）
RewriteCond %{HTTP_HOST} ^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$
RewriteCond %{REMOTE_ADDR} ^(127\.0\.0\.1|::1)$
RewriteRule ^/api/(.*)$ unix:/router/run/admin.sock|http://localhost/api/$1 [P,L]

# 1b. 管理UI 静的ファイル（Apache 直接配信）
RewriteCond %{HTTP_HOST} ^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$
RewriteCond %{REMOTE_ADDR} ^(127\.0\.0\.1|::1)$
RewriteRule ^(.*)$ /router/app/public/$1 [L]

# 2. ルーター問い合わせ（1回だけ、結果を環境変数に格納）
RewriteCond ${router:%{HTTP_HOST}} ^(.+)$
RewriteRule .* - [E=ROUTE:%1,NE]

# 3. NULL — ルーティング対象なし（明示ハンドリング）
#    NULL は RewriteMap prg: の慣例的な「マッチなし」応答。
#    公式には空文字列が「マッチなし」であるため、明示的にルール化して堅牢性を確保する。
RewriteCond %{ENV:ROUTE} ^NULL$
RewriteRule ^ - [R=404,L]

# 4. リダイレクト（ベースドメイン直アクセス → 管理UIへ）
RewriteCond %{ENV:ROUTE} ^R:(.+)$
RewriteRule ^ %1 [R=302,L]

# 5. WebSocket プロキシ（Upgrade ヘッダ検出時）
RewriteCond %{HTTP:Upgrade} =websocket [NC]
RewriteCond %{ENV:ROUTE} ^https?://(.+)
RewriteRule ^(.*)$ ws://%1$1 [P,L]

# 6. リバースプロキシ（HTTP URL）
RewriteCond %{ENV:ROUTE} ^(https?://.+)
RewriteRule ^(.*)$ %1$1 [P,L]

# 7. ディレクトリ公開（ファイルパス）
RewriteCond %{ENV:ROUTE} ^(/.+)
RewriteRule ^(.*)$ %1$1 [L]

# 8. フォールバック（ROUTE 未設定 — ルーター未応答等）
RewriteRule ^ - [R=404,L]
```

#### 設計ポイント

`RewriteMap prg:` は同一リクエスト内でも結果をキャッシュしない。
そのため Node への問い合わせは1回だけ行い、結果を環境変数 `ROUTE` に格納して後続ルールで参照する。
VirtualHost コンテキストでは `[L]` フラグがルールの再実行を引き起こさないため、
`REDIRECT_` プレフィックス問題は発生しない。

Node が「マッチなし」として返す `NULL` は `prg:` の慣例的な応答であり、
RewriteMap の公式な「マッチなし」表現（空文字列）とは異なる。
Apache バージョン間の挙動差異を防ぐため、ルール3で `NULL` を明示的にハンドリングし、
他のパターンとの誤マッチに依存しない堅牢な構成としている。

ベースドメイン直アクセス時は `R:` プレフィックス付きURLを返し、
Apache 側で HTTP 302 リダイレクトとして処理する。
これによりプロキシ経由での管理UIアクセスを防ぎ、セキュリティ要件（セクション10）を維持する。

旧設計（txt 方式）と比較して、以下が不要になった:

* `base-domains.conf`（自動生成 RewriteCond パターン）→ Node が動的に解析
* `hosts.map` / `groups.map`（テキストファイル）→ Node のインメモリ + JSON
* `int:tolower`（大文字小文字正規化）→ Node 側で処理
* 複数の環境変数（`SITE_SLUG` / `GROUP_SLUG` / `BASE_DOMAIN`）→ Node 内部で完結し、単一の `ROUTE` 変数のみ使用

---

### 6.6 ディレクトリアクセス許可

Apache がリライト先のユーザディレクトリを配信するには `<Directory>` による許可が必要である。

本システムはローカル開発専用であり、管理UIは localhost のみアクセス可能（セクション10参照）であるため、
VirtualHost 内でルートディレクトリに対して全許可を設定する。

```apache
# メイン VirtualHost 内（初期設定時に固定）
<Directory />
    Options FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```

これにより、ファイルシステム上のどのパスでも Apache が配信可能となる。
グループ登録・スラグ指定のたびに設定ファイルを再生成する必要がなく、
**graceful なしで即座にルーティングが有効になる**。

#### graceful が不要な根拠

Apache の graceful restart（`apachectl graceful`）は Apache 設定ファイルの再読み込みを伴う。
本システムでは、以下の設計により **SSL 証明書の発行時を除き graceful は一切不要**である。

| 操作 | graceful 不要の理由 |
| --- | --- |
| グループ登録 | ルーティングは `RewriteMap prg:` で毎リクエスト Node に問い合わせる動的解決であり、Apache 設定の変更を伴わない。Node のインメモリ状態を更新するだけで即時反映される。 |
| スラグ指定・リバースプロキシ追加 | 同上。routes.json + MessagePort 通知でインメモリ更新のみ。 |
| グループ配下へのサブディレクトリ追加 | resolve 関数が `fs.existsSync()` で都度検出するため、管理UIでの操作すら不要。 |
| ディレクトリアクセス許可 | `<Directory />` で全パスを許可済み。サイトごとの `<Directory>` 追加は不要。 |
| VirtualHost | 単一 VirtualHost で全サブドメインを処理する設計であり、サイト追加で VirtualHost は増えない。 |

graceful が必要なのは **SSL 証明書の発行時のみ**である（Apache が新しい証明書ファイルを読み込む必要があるため）。
詳細はセクション8を参照。

> **設計上の注意**: 上記の根拠を崩す変更（サイト別 `<Directory>` の動的生成、VirtualHost の動的追加等）は
> graceful の頻発を招き、`prg:` サブプロセスの再起動問題を引き起こす。
> ルーティングの動的解決は Node 側に集約し、Apache 設定は固定に保つこと。

---

### 6.7 スラグのルール

明示登録スラグおよびグループ内サブディレクトリ名はすべて以下のルールに従う。

#### 許可パターン

```
^[a-z0-9]([a-z0-9-]*[a-z0-9])?$
```

* 小文字英数字で始まり、小文字英数字で終わること
* 中間は小文字英数字およびハイフンを使用可
* 1文字（例: `a`）も有効
* 空文字列は不可

#### グループ内サブディレクトリの扱い

グループ配下のサブディレクトリ名がそのままサブドメインのスラグとなる。
ディレクトリ名がスラグパターンに一致しない場合（大文字、スペース、特殊文字を含む等）、
そのディレクトリは自動公開の対象外となる。管理UIで警告を表示する。

```
sites/companyA/
  app/           → app.{base-domain}       ✅ パターン一致
  my-site/       → my-site.{base-domain}   ✅ パターン一致
  My Project/    → （公開対象外、管理UIで警告） ❌ 大文字・スペース
```

#### バリデーションタイミング

| タイミング | チェック内容 |
| --- | --- |
| 管理UI登録時（明示登録） | パターン一致 + 既存スラグとの重複 |
| Node ルーター（resolve 関数内） | パターン一致（ディレクトリトラバーサル防止） |

---

## 7. リバースプロキシ設計

Apache は単なる転送ではなく
**アプリケーション環境の仮想化**を行う。

### 必須設定

* Hostヘッダ保持
* X-Forwarded-Proto
* Cookie書換（動的対応は保留事項 14 参照）
* WebSocket対応

### 重要設定

```apache
ProxyPreserveHost On

# HTTP VirtualHost（ポート80）
RequestHeader set X-Forwarded-Proto "http"

# HTTPS VirtualHost（ポート443）— SSL有効時のみ
RequestHeader set X-Forwarded-Proto "https"
```

---

### Location 書換（ProxyPassReverse）

動的ルーティングでは `ProxyPassReverse` を静的に設定できないため、本システムでは設定しない。

`ProxyPreserveHost On` により、バックエンドは正しい Host ヘッダ（例: `app.dev.local`）を受け取る。
大多数のフレームワーク（Express / Next.js / Django / Laravel 等）は Host ヘッダを基にリダイレクト URL を生成するため、
**Location ヘッダは自動的に正しいドメインを含む**。

問題になるケース:
* バックエンドが `localhost:3000` 等のオリジンをハードコードしてリダイレクトを返す場合
* この場合、ブラウザは Dev Router を経由せず `localhost:3000` に直接アクセスする

これはアプリ側の問題（Host ヘッダの不尊重）であり、ローカル開発では `localhost:3000` でも動作するため実害は小さい。

---

### Cookie対応

動的ルーティングでは `ProxyPassReverseCookieDomain` を静的に設定できないため、
動的な Cookie 書き換え方法を別途検討する（保留事項 14 参照）。

---

### WebSocket対応

Vite / Next.js の HMR を維持するため
mod_proxy_wstunnel による ws トンネルを有効化する。

ルーティングルール（6.5）で `Upgrade: websocket` ヘッダを検出し、
プロトコルを `ws://` に変換してプロキシする。

```apache
# 6.5 ルール 4（再掲）
RewriteCond %{HTTP:Upgrade} =websocket [NC]
RewriteCond %{ENV:ROUTE} ^https?://(.+)
RewriteRule ^(.*)$ ws://%1$1 [P,L]
```

Node ルーターがルーティングを統一的に処理するため、
スラグ指定・グループ内を問わず、リバースプロキシ先のアプリケーションで
WebSocket が利用できる。

必要モジュール: `mod_proxy_wstunnel`

---

## 8. SSL（オプション機能）

SSLは必須ではなくオプションとする。
ベースドメインごとに、管理UIから明示的に証明書を発行する方式とする。

理由：

* 初期導入障壁が高い
* mkcert の理解が必要
* 環境依存トラブルを避ける
* 自動発行にすると登録操作の副作用が増え、エラー時の切り分けが難しくなる

### 有効化時

ローカル認証局を利用する。

ツール：

```
mkcert
```

### 役割

* ログイン系検証
* OAuth
* ServiceWorker
* Webhook受信

### 証明書戦略

1階層サブドメインにより、ベースドメインごとに `*.{base-domain}` のワイルドカード1枚で
全サブドメインをカバーできる。**一度発行すれば、以降のグループ登録・スラグ追加・サブディレクトリ追加で
証明書の再発行は不要**である（ワイルドカードが全サブドメインに適用されるため）。

| 対象 | 方法 |
| --- | --- |
| ベースドメイン配下の全サブドメイン | `*.{base-domain}` ワイルドカード証明書 |

SSL が有効なベースドメインが複数ある場合は、全ワイルドカードを SAN として1枚の証明書にまとめ、
単一 VirtualHost を維持する。

### 明示的発行フロー

ユーザが管理UIでベースドメインの「HTTPS 有効化」ボタンを押した時のみ証明書を発行する。
ベースドメイン登録・グループ登録・サイト追加では証明書操作は一切行わない。

```
管理UIで「HTTPS 有効化」押下時:
  1. updateRoutes で該当ベースドメインを ssl: true に更新（routes.json + メインスレッド通知）
  2. 全ベースドメイン（ssl: true）の SAN 一覧を構築: *.{bd1}, *.{bd2}, ...
  3. execFileSync('mkcert', ['-cert-file', 'cert.pem', '-key-file', 'key.pem', ...SANs])
  4. spawn('apachectl', ['graceful']).unref()  ← fire-and-forget（ステップ3完了後に実行）

グループ登録・スラグ指定・リバースプロキシ追加時:
  routes.json 更新 + メインスレッド通知（即時反映、graceful 不要）

グループ配下への新規サブディレクトリ追加:
  操作不要（resolve 関数がファイルシステムを都度検出する）
```

mkcert はローカル CA の署名のみのためミリ秒オーダーで完了する。
証明書ファイルパスを固定（`{ROUTER_HOME}/ssl/cert.pem`, `key.pem`）し、
Apache の `SSLCertificateFile` / `SSLCertificateKeyFile` を固定パスで指定する。

#### graceful の実行方法

ワイルドカード証明書により、graceful が発生するのは「ベースドメインに HTTPS を有効化する瞬間」のみである。
通常のルーティング操作（グループ登録・スラグ指定・リバースプロキシ追加・サブディレクトリ追加）では一切発生しない。

Node プロセスは Apache の `prg:` サブプロセスであるため、`execSync('apachectl graceful')` は
自プロセスの kill を招く。代わりに **fire-and-forget** で実行する:

```javascript
const { spawn } = require('child_process');
spawn('apachectl', ['graceful'], { detached: true, stdio: 'ignore' }).unref();
```

graceful 後の流れ:
1. Apache が `prg:` プロセス（Node）を停止し、新しいプロセスを起動する
2. 新プロセスが `routes.json` から状態を復元する（ステップ1で既に更新済み）
3. 数秒のダウンタイム後、HTTPS を含む全ルーティングが有効になる

### 管理UIでの表示

ベースドメインごとに HTTPS の有効化状態と操作ボタンを表示する。

管理UIは起動時に `/api/ssl/status` でバックエンドに mkcert の状態を問い合わせ、
結果に応じて表示を切り替える:

| 状態 | 表示 |
| --- | --- |
| mkcert 未インストール | ボタンをグレーアウト + OS別インストールコマンドを表示（例: `brew install mkcert && mkcert -install`） |
| mkcert インストール済み・ローカルCA 未登録 | ボタンをグレーアウト + `mkcert -install` の実行を促すメッセージを表示 |
| 準備完了（mkcert + CA 登録済み） | 「HTTPS 有効化」ボタンを有効化 |

```
ベースドメイン一覧:
  ★ 127.0.0.1.nip.io  [HTTPS: ✅ 有効]    ← current
    dev.local          [HTTPS 有効化する]
```

mkcert 未インストール時の表示例:
```
  ⚠ HTTPS を利用するには mkcert のインストールが必要です
  $ brew install mkcert && mkcert -install
```

HTTPS が有効なベースドメインでは「証明書を再発行」ボタンも表示する（トラブル時の手動再発行用）。

#### HTTPS 有効化時の UX フィードバック

HTTPS 有効化は graceful を伴い、Node プロセスが再起動されるため API レスポンスが途切れる。
管理UI のフロントエンドは Apache が直接配信しているため画面は表示されたままであり、
この特性を活かして以下の UX フローを実装する:

1. ユーザが「HTTPS 有効化」ボタンを押下
2. フロントエンドは即座に「HTTPS 有効化中...数秒お待ちください」のフィードバックを表示し、ボタンを無効化
3. API リクエスト送信（Worker Thread が証明書発行 → graceful を実行）
4. graceful により Node が再起動され、API レスポンスは接続リセットとなる
5. フロントエンドは接続エラーを「想定内」として処理し、フィードバック表示を維持
6. 一定間隔（例: 1秒ごと）で `/api/health` をポーリングし、バックエンド復帰を検出
7. 復帰確認後「HTTPS が有効になりました」を表示

```
[HTTPS 有効化する] ← ボタン押下
    ↓
[HTTPS 有効化中...数秒お待ちください ⏳] ← 即座に表示
    ↓（API 送信 → graceful → 接続リセット）
[HTTPS 有効化中...数秒お待ちください ⏳] ← エラーを想定内として処理、表示維持
    ↓（ポーリングで復帰検出）
[✅ HTTPS が有効になりました]
```

---

## 9. PHP処理

### 対応方式

mod_php および php-fpm の両方に対応する。推奨は php-fpm。

| | mod_php | php-fpm（推奨） |
| --- | --- | --- |
| 動的DocumentRoot | RewriteRule でファイルパス書き換え | FastCGI パラメータで指定 |
| `DOCUMENT_ROOT` 正確性 | VirtualHost の固定値が返る | リクエスト毎に正確な値を設定可能 |
| .htaccess | `AllowOverride All` で動作 | 同様 |
| サイト別設定 | 不可（全サイト共通） | プール別に設定可能 |

### mod_php の制約

`$_SERVER['DOCUMENT_ROOT']` が実際のディレクトリと異なる固定値を返す。
主要フレームワーク（WordPress / Laravel 等）は `__DIR__` / `__FILE__` ベースでパスを解決するため実害は少ない。
`$_SERVER['DOCUMENT_ROOT']` を直接参照するレガシーコードでは問題が生じる可能性がある。

---

## 10. セキュリティ

### 管理UI制限

管理UIは以下の二重チェックで保護する。

1. **Host ヘッダ**: `localhost`、`127.0.0.1`、`[::1]` のみ許可
2. **送信元IP**: `127.0.0.1` または `::1` のみ許可

```apache
RewriteCond %{HTTP_HOST} ^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$
RewriteCond %{REMOTE_ADDR} ^(127\.0\.0\.1|::1)$
```

以下はすべて拒否:

* `127.0.0.1.nip.io`（ベースドメインとして扱う）
* `192.168.*.*`（LAN経由）
* 外部ネットワークからのアクセス

---

### 公開サイト

LAN内からの閲覧は許可する。
外部からのアクセスはローカルDNSの性質上、到達しない。

---

## 11. ユーザ操作フロー

### 初期設定（1回）

* Apacheモジュール確認
* ルーティング VirtualHost 追加
* ベースドメイン登録（デフォルト: 127.0.0.1.nip.io）
* （任意）SSL有効化

### 通常利用

1. `http://localhost` で管理UIへアクセス
2. グループ登録 or スラグ指定 or リバースプロキシ追加
3. 保存
4. URLクリック

すべてのルーティング操作は再起動不要（メインスレッドのインメモリ更新のみで即時反映）。
SSL証明書の発行時のみ graceful が発生する。

---

## 12. 動作環境

| OS                 | 対応  |
| ------------------ | --- |
| macOS              | 推奨  |
| Linux              | 推奨  |
| Windows + WSL2     | 推奨  |
| WindowsネイティブApache | 非推奨 |

Node は **ルーティングエンジン兼管理 API バックエンド**として使用する。
管理UI のフロントエンドは静的ファイルとして Apache が直接配信する。
Apache の RewriteMap `prg:` サブプロセスとして動作するため、
Apache が起動すれば Node も自動的に起動する。
Node.js v18 LTS 以降を推奨（Worker Threads + 安定した構造化クローン）。

---

## 13. データ管理方針

### インストールパス

本システムのファイルは `ROUTER_HOME` を基点に配置する。
本ドキュメント中の `/router/` はすべて `ROUTER_HOME` のデフォルト値である。

| パス | 用途 |
| --- | --- |
| `{ROUTER_HOME}/app/` | Node アプリケーション（router.js, admin.js） |
| `{ROUTER_HOME}/app/public/` | 管理UI フロントエンド（静的ファイル、Apache 直接配信） |
| `{ROUTER_HOME}/data/` | routes.json 等のデータ |
| `{ROUTER_HOME}/ssl/` | SSL証明書（オプション） |
| `{ROUTER_HOME}/run/` | Unix ソケット |

`ROUTER_HOME` は Apache の環境変数または設定ファイルで変更可能とする。

### ファイル構成

* ベースドメイン設定: JSON（routes.json 内）
* ルーティング情報: JSON（routes.json 内）+ メインスレッド インメモリ
* グループ情報: JSON（routes.json 内）+ メインスレッド インメモリ
* Apache設定: 初期設定時に固定（動的生成なし）
* 管理UIソケット: `{ROUTER_HOME}/run/admin.sock`
* UI状態: 任意でSQLite可
* 証明書: ファイル管理

本システムはDBアプリではなく
**設定ファイルオーケストレータ**として設計する。
ルーティングの真実はメインスレッドのインメモリ状態であり、
JSON ファイルは永続化のためのバックストアである。
Worker Thread（管理UI）からの変更は MessagePort 経由でメインスレッドに即時反映される。

---

## 14. 保留事項（TODO）

* **Cookie書き換えの動的対応** — 動的ルーティングにおいて `ProxyPassReverseCookieDomain` を動的に設定する方法の検討。多くのローカル開発ではセッション Cookie の domain 属性は未設定であり問題にならないケースが多い。顕在化した場合は `mod_headers` の `Header edit` で対応する方針。

### 解決済み

* ~~**SSL証明書再生成フロー**~~ — 1階層サブドメイン + 明示的発行方式を採用。ベースドメインごとに `*.{bd}` のワイルドカード1枚で全サブドメインをカバー。管理UIの「HTTPS 有効化」ボタンで明示的に発行し、グループ登録時の証明書操作は不要。

---

## 15. 本システムの位置付け

本ソフトウェアは以下に相当する。

* Laravel Valet
* Traefik（開発用途）
* ローカルngrok代替

ただし Apache 環境に最適化された
**ローカル開発ネットワークレイヤ**を提供することを目的とする。
