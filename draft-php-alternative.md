# PHP版 Dev Router 改善案

本ドキュメントは [draft.md](draft.md) の設計に対し、
Node.js を排除して PHP + Apache ネイティブ機能のみで実現する代替案をまとめたものである。

---

## 1. 動機：現設計の複雑さの大半は「Node を Apache 内で飼う」ことに起因している

draft.md のセクション5〜6の文量の約半分は、以下の問題への対策である:

| 問題 | 対策箇所 | PHP版 |
| --- | --- | --- |
| `prg:` の stdin/stdout プロトコル制約 | 6.1 | **不要** |
| stdout 汚染によるプロトコル破壊 | 5.1, 6.4 | **不要** |
| Worker Threads によるスレッド分離 | 5.1 | **不要** |
| Unix socket で Admin API を提供 | 5.1, 6.5 | **不要** |
| Worker クラッシュ→自動再起動 | 6.4 | **不要** |
| `console.log` 禁止ルール | 5.1 | **不要** |
| プロセスライフサイクル管理（SIGTERM, cleanup） | 6.1 | **不要** |
| readline による行単位読み取り強制 | 6.1 | **不要** |
| 未応答で Apache 全体ハング | 6.1 | **不要** |

これらは全て「Node が Apache にとって異物だから」発生する問題であり、
PHP（mod_php / php-fpm）なら構造的に存在しない。

---

## 2. アーキテクチャ

### 2.1 全体構成

```
Browser
   ↓
Apache (ポート 80 / SSL有効時は 443 も)
   ├ Host: localhost or 127.0.0.1
   │    ├ /api/*.php → PHP 実行（mod_php / php-fpm、特別な設定不要）
   │    └ それ以外 → 管理UI 静的ファイル配信（Apache 直接）
   └ Host: *.{base-domain}
        ↓
      RewriteMap (txt: タイプ)
        → routing.map ファイルを参照（ホスト名 → ターゲット）
        ├ マッチ → 各アプリケーション / ディレクトリ
        └ マッチなし → resolve.php（再スキャン → 存在すればリダイレクト / なければ404）
```

### 2.2 現設計との比較

```
【現設計（Node版）】
Browser → Apache → RewriteMap prg: → Node メインスレッド（stdin/stdout）
                                          ├ インメモリ state
                                          └ Worker Thread（Admin API）← Unix socket ← Apache

【PHP版】
Browser → Apache → RewriteMap txt: → routing.map ファイル（自動再読み込み）
                ├ マッチなし → resolve.php（再スキャン→自動解決）
                └ PHP（Admin API）← Apache が直接実行
```

Node プロセス・Worker Thread・Unix socket・stdin/stdout プロトコルが全て消える。

### 2.3 核となる技術

| 技術 | 用途 |
| --- | --- |
| mod_rewrite | ルーティングルール |
| RewriteMap（**txt:** タイプ） | ホスト名→ターゲットの静的マッピング |
| mod_proxy / mod_proxy_http / mod_proxy_wstunnel | リバースプロキシ・WebSocket |
| mod_headers | X-Forwarded-Proto 設定 |
| mod_ssl | SSL有効化時のみ |
| PHP（mod_php / php-fpm） | 管理 API バックエンド + 未登録サブドメインの自動解決 |
| ワイルドカードDNS（nip.io / dnsmasq 等） | サブドメイン解決 |

---

## 3. ルーティングメカニズム

### 3.1 RewriteMap txt: 方式

`prg:` の代わりに `txt:` タイプの RewriteMap を使用する。
PHP Admin API がルート変更時に `routing.map` ファイルを再生成し、
Apache がファイルの mtime 変更を検知して自動的に再読み込みする。

```apache
RewriteMap lc "int:tolower"
RewriteMap router "txt:{ROUTER_HOME}/data/routing.map"
```

#### routing.map の自動再読み込みの仕組み

Apache の `txt:` RewriteMap は、ルックアップのたびに `stat()` でファイルの mtime を確認する
（`mod_rewrite.c` の `lookup_map()` 関数）。mtime が変わっていればキャッシュを全破棄し、
ファイルを再読み込みする。ポーリング間隔や TTL は存在せず、
**routing.map を書き換えた直後の次のリクエストで即座に新しい内容が使われる**。

`stat()` のコストはカーネルの dentry/inode キャッシュにヒットするため数マイクロ秒程度であり、
ローカル開発用途では問題にならない。

#### routing.map の形式

```
# 自動生成 — 手動編集禁止
# ベースドメイン直アクセス → 管理UIへリダイレクト
127.0.0.1.nip.io R:http://localhost
dev.local R:http://localhost

# 明示登録（スラグ指定・リバースプロキシ）
myapp.127.0.0.1.nip.io /Users/me/sites/companyA/app/public
myapp.dev.local /Users/me/sites/companyA/app/public
vite.127.0.0.1.nip.io http://localhost:5173
vite.dev.local http://localhost:5173
api.127.0.0.1.nip.io http://localhost:8000
api.dev.local http://localhost:8000

# グループ解決（自動スキャン結果）
app.127.0.0.1.nip.io /Users/me/sites/companyA/app/public
app.dev.local /Users/me/sites/companyA/app/public
blog.127.0.0.1.nip.io /Users/me/sites/companyA/blog
blog.dev.local /Users/me/sites/companyA/blog
```

ホスト名とターゲットの1対1マッピング。
ベースドメイン × ルートの全組み合わせを列挙する。

#### 大文字小文字の正規化

Apache 組み込みの `int:tolower` で処理する。Node 側での正規化は不要。

```apache
# 参照時に小文字化
RewriteCond ${router:${lc:%{HTTP_HOST}}}  ...
```

### 3.2 routing.map の生成ロジック（PHP）

```php
function generateRoutingMap(array $state): string
{
    $lines = ['# 自動生成 — 手動編集禁止'];

    // 全ベースドメインのリスト
    $baseDomains = array_column($state['baseDomains'], 'domain');

    // 1. ベースドメイン直アクセス → リダイレクト
    foreach ($baseDomains as $bd) {
        $lines[] = "$bd R:http://localhost";
    }

    // 2. 明示登録（スラグ指定・リバースプロキシ）
    $mapped = [];  // 登録済みスラグを追跡（衝突検出用）
    foreach ($state['routes'] as $route) {
        $mapped[$route['slug']] = true;
        foreach ($baseDomains as $bd) {
            $lines[] = "{$route['slug']}.$bd {$route['target']}";
        }
    }

    // 3. グループ解決（登録順に走査、先にマッチしたグループが優先）
    foreach ($state['groups'] as $group) {
        $dirs = scanGroupDirectory($group['path']);
        foreach ($dirs as $slug => $target) {
            if (isset($mapped[$slug])) continue;  // 明示登録 or 先行グループが優先
            $mapped[$slug] = true;
            foreach ($baseDomains as $bd) {
                $lines[] = "$slug.$bd $target";
            }
        }
    }

    return implode("\n", $lines) . "\n";
}

function scanGroupDirectory(string $groupPath): array
{
    $results = [];
    if (!is_dir($groupPath)) return $results;

    foreach (scandir($groupPath) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $entryPath = $groupPath . '/' . $entry;
        if (!is_dir($entryPath)) continue;

        // スラグパターンチェック
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $entry)) continue;

        // public/ 自動検出
        $publicPath = $entryPath . '/public';
        $results[$entry] = is_dir($publicPath) ? $publicPath : $entryPath;
    }

    return $results;
}
```

### 3.3 routing.map のアトミック書き込み

```php
function writeRoutingMap(string $mapPath, string $content): void
{
    $tmp = $mapPath . '.tmp';
    file_put_contents($tmp, $content);
    rename($tmp, $mapPath);  // アトミック置換
}
```

### 3.4 現設計の `prg:` 固有制約との比較

`prg:` で必要だった対策が `txt:` では全て不要になる。

| `prg:` の制約 | `txt:` では |
| --- | --- |
| stdin は行単位を保証しない → readline 必須 | **関係なし**（ファイル読み取り） |
| 未応答 = Apache 全体ハング | **関係なし**（ファイル参照は必ず完了） |
| stdout 汚染 = プロトコル破壊 | **関係なし**（stdout を使わない） |
| クラッシュ時に自動再起動しない | **関係なし**（長期プロセスが存在しない） |
| mutex によるシリアライズ | **関係なし**（Apache 内部でハッシュ参照） |

---

## 4. Admin API 設計

### 4.1 方針

Admin API は Apache が直接実行するプレーン PHP ファイルで構成する。
フレームワーク不要。Unix socket 不要。プロセス管理不要。

### 4.2 エンドポイント

| エンドポイント | メソッド | 機能 | アクセス元 |
| --- | --- | --- | --- |
| `/api/health.php` | GET | ヘルスチェック | 管理UI（localhost） |
| `/api/routes.php` | GET / POST / DELETE | スラグ指定・リバースプロキシの CRUD | 管理UI（localhost） |
| `/api/groups.php` | GET / POST / PUT / DELETE | グループの CRUD + 優先順位変更 | 管理UI（localhost） |
| `/api/domains.php` | GET / POST / DELETE | ベースドメインの CRUD + current 切替 | 管理UI（localhost） |
| `/api/ssl.php` | GET / POST | SSL 状態確認・証明書発行 | 管理UI（localhost） |
| `/api/env-check.php` | GET | 環境チェック（apachectl -M 等） | 管理UI（localhost） |
| `/api/scan.php` | POST | グループディレクトリの手動スキャン→map再生成 | 管理UI（localhost） |
| `resolve.php` | — | 未登録サブドメインの自動解決（セクション8参照） | Apache RewriteRule（*.base-domain） |

### 4.3 共通処理

```php
// lib/store.php — routes.json の読み書き

define('ROUTES_PATH', ROUTER_HOME . '/data/routes.json');
define('MAP_PATH', ROUTER_HOME . '/data/routing.map');

function loadState(): array
{
    $path = ROUTES_PATH;

    // routes.json を読み込み（破損時はバックアップから復元）
    $json = @file_get_contents($path);
    $state = $json ? json_decode($json, true) : null;

    if (!is_array($state)) {
        $bak = @file_get_contents($path . '.bak');
        $state = $bak ? json_decode($bak, true) : null;
        if (!is_array($state)) {
            $state = ['baseDomains' => [], 'groups' => [], 'routes' => []];
        }
    }

    return $state;
}

function saveState(array $state): void
{
    $path = ROUTES_PATH;

    // バックアップ
    @copy($path, $path . '.bak');

    // routes.json のアトミック書き込み
    $tmp = $path . '.tmp';
    file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename($tmp, $path);

    // routing.map の再生成（ルート変更の即時反映）
    $map = generateRoutingMap($state);
    writeRoutingMap(MAP_PATH, $map);
}
```

**ポイント**: `saveState()` の中で routing.map も再生成する。
routes.json の更新と map 再生成が常にセットで行われるため、不整合は発生しない。

### 4.4 API の実装例（routes.php）

```php
<?php
require_once __DIR__ . '/lib/store.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$state = loadState();

switch ($method) {
    case 'GET':
        echo json_encode($state['routes']);
        break;

    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);
        $slug = $input['slug'] ?? '';
        $target = $input['target'] ?? '';
        $type = $input['type'] ?? 'directory';

        // バリデーション
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $slug)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid slug']);
            break;
        }

        // 重複チェック
        foreach ($state['routes'] as $r) {
            if ($r['slug'] === $slug) {
                http_response_code(409);
                echo json_encode(['error' => 'Slug already exists']);
                break 2;
            }
        }

        $state['routes'][] = ['slug' => $slug, 'target' => $target, 'type' => $type];
        saveState($state);  // routes.json + routing.map を同時更新
        echo json_encode(['ok' => true]);
        break;

    case 'DELETE':
        $slug = $_GET['slug'] ?? '';
        $state['routes'] = array_values(
            array_filter($state['routes'], fn($r) => $r['slug'] !== $slug)
        );
        saveState($state);
        echo json_encode(['ok' => true]);
        break;
}
```

---

## 5. Apache ルーティングルール

### 5.1 変更箇所

現設計からの変更は最小限:

1. **RewriteMap 定義**: `prg:` → `txt:` + `int:tolower` 追加
2. **管理API ルール**: Unix socket proxy → Apache 直接実行（DocumentRoot 内の PHP）
3. **マッチなし判定**: `NULL` → `resolve.php` による自動解決（再スキャン→存在すればリダイレクト/なければ404）

### 5.2 ルール全体

```apache
# サーバコンフィグレベル（VirtualHost の外）
RewriteMap lc "int:tolower"
RewriteMap router "txt:{ROUTER_HOME}/data/routing.map"

# --- VirtualHost 共通ルール（routing-rules.conf） ---

DirectoryIndex index.php index.html index.htm
ProxyPreserveHost On
RewriteEngine On

# 1. 管理UI（localhost のみ）
#    API も静的ファイルも DocumentRoot 内のファイルとして直接配信
RewriteCond %{HTTP_HOST} ^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$
RewriteCond %{REMOTE_ADDR} ^(127\.0\.0\.1|::1)$
RewriteRule ^(.*)$ {ROUTER_HOME}/public$1 [L]

# 2. ルーター問い合わせ（txt: map 参照、結果を環境変数に格納）
RewriteCond ${router:${lc:%{HTTP_HOST}}} ^(.+)$
RewriteRule .* - [E=ROUTE:%1,NE]

# 3. マッチなし → resolve.php で自動解決
#    txt: map でキーが見つからない場合、空文字列が返る
#    ステップ2の RewriteCond が ^(.+)$ なので、空文字列は ROUTE に設定されない
#    resolve.php がグループディレクトリを再スキャンし、
#    存在すれば routing.map を更新して同じURLへリダイレクト、なければ404を返す
RewriteCond %{ENV:ROUTE} ^$
RewriteRule ^ {ROUTER_HOME}/public/resolve.php [L]

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

# 8. フォールバック（ROUTE 未設定 — resolve.php が処理するため通常到達しない）
RewriteRule ^ - [R=404,L]
```

### 5.3 現設計との差分

| ルール | 現設計（Node版） | PHP版 |
| --- | --- | --- |
| RewriteMap 定義 | `prg:/usr/local/bin/node ...` | `txt:...` + `int:tolower` |
| 管理API (ルール1a) | `unix:/router/run/admin.sock\|http://...` [P] | DocumentRoot 内の PHP に直接アクセス [L] |
| 管理UI (ルール1b) | 静的ファイル配信 [L] | 同左（API もまとめて1ルールに統合） |
| マッチなし (ルール3) | `^NULL$` → 404 | `resolve.php` で自動解決（再スキャン→リダイレクト or 404） |
| ルール4〜8 | — | **変更なし** |

管理 API のルールが「Unix socket proxy」から「DocumentRoot 内の PHP ファイル」に変わることで、
ルール 1a と 1b が1つに統合される。

---

## 6. SSL 対応

### 6.1 証明書発行

現設計と同じ mkcert ベース。PHP の `exec()` で同等に実行可能。

```php
// api/ssl.php（POST: HTTPS 有効化）

$state = loadState();

// 1. ベースドメインを ssl: true に更新
foreach ($state['baseDomains'] as &$bd) {
    if ($bd['domain'] === $targetDomain) $bd['ssl'] = true;
}
saveState($state);

// 2. SAN 一覧を構築
$sans = [];
foreach ($state['baseDomains'] as $bd) {
    if ($bd['ssl']) $sans[] = '*.' . $bd['domain'];
}

// 3. mkcert 実行
$certFile = ROUTER_HOME . '/ssl/cert.pem';
$keyFile = ROUTER_HOME . '/ssl/key.pem';
$cmd = sprintf(
    'mkcert -cert-file %s -key-file %s %s 2>&1',
    escapeshellarg($certFile),
    escapeshellarg($keyFile),
    implode(' ', array_map('escapeshellarg', $sans))
);
exec($cmd, $output, $exitCode);

if ($exitCode !== 0) {
    http_response_code(500);
    echo json_encode(['error' => 'mkcert failed', 'output' => $output]);
    exit;
}

// 4. HTTPS VirtualHost 設定を生成（初回のみ）
generateHttpsVhostIfNeeded();

// 5. graceful 実行
exec('apachectl graceful > /dev/null 2>&1 &');

echo json_encode(['ok' => true]);
```

### 6.2 graceful 時の挙動改善

現設計では graceful により Node プロセスが再起動され、
API レスポンスが確実に切断されるため、ポーリングによる復帰検出が必要だった。

PHP版（特に php-fpm）では:

| | Node版 | PHP版（php-fpm） |
| --- | --- | --- |
| graceful の影響 | `prg:` プロセスが kill される | FPM プールは独立、影響なし |
| API 切断 | 確実に発生 | 発生しない可能性が高い |
| 復帰検出 | `/api/health` ポーリング必要 | レスポンスがそのまま返る |
| UX | 「数秒お待ちください」表示 | 即座に「完了」表示 |

これは PHP 版の明確な UX 上の改善点である。

---

## 7. 管理UI フロントエンド

**変更なし。** 現設計と同じく静的 HTML/CSS/vanilla JS。
Apache が直接配信する。API エンドポイントのパスが変わるだけ:

```
// 現設計
fetch('/api/routes')

// PHP版
fetch('/api/routes.php')
```

あるいは `.htaccess` や Apache 設定で `.php` を隠蔽してもよい。

Node 停止時の復旧手順表示は不要になる（PHPは独立プロセスではないため「停止」しない）。
ただし routes.json 破損時のエラー表示は引き続き必要。

---

## 8. 新サブディレクトリの自動検出

### 8.1 自動解決メカニズム（resolve.php）

`txt:` RewriteMap は静的ファイルであるため、グループディレクトリに新しいサブディレクトリを作成しても
routing.map には即座に反映されない。

この問題を **resolve.php** で解決する。routing.map にマッチしないサブドメインへのアクセス時、
Apache が resolve.php を実行する。resolve.php はグループディレクトリを再スキャンし、
該当するサブディレクトリが存在すれば routing.map を更新して同じURLへリダイレクトする。

```
ユーザーがグループディレクトリに new-app/ を作成
  ↓
ブラウザで new-app.127.0.0.1.nip.io にアクセス
  ↓
routing.map にマッチなし → Apache が resolve.php を実行
  ↓
resolve.php がグループディレクトリを再スキャン → new-app を発見 → routing.map 更新
  ↓
302 リダイレクト（同じURL）
  ↓
routing.map にマッチ → サイト表示
```

ユーザーから見ると一瞬リダイレクトが入るだけで、
**Node版と同等の「ディレクトリを作るだけでアクセス可能」を実現**する。

### 8.2 resolve.php の実装

```php
<?php
require_once __DIR__ . '/api/lib/store.php';

$host = strtolower($_SERVER['HTTP_HOST']);
$state = loadState();

// routing.map を再生成（グループディレクトリを再スキャン）
saveState($state);

// 再生成後の routing.map にこのホストがあるか確認
$map = file_get_contents(MAP_PATH);
$target = null;
foreach (explode("\n", $map) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    $parts = preg_split('/\s+/', $line, 2);
    if (count($parts) === 2 && $parts[0] === $host) {
        $target = $parts[1];
        break;
    }
}

if ($target !== null) {
    // 存在する → 同じURLへリダイレクト（次のリクエストで更新済みmapにヒット）
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $uri = $_SERVER['REQUEST_URI'];
    header("Location: {$scheme}://{$host}{$uri}", true, 302);
    exit;
}

// 存在しない → 404
http_response_code(404);
?>
<!DOCTYPE html>
<html>
<head><title>404 Not Found</title></head>
<body>
<h1>サイトが見つかりません</h1>
<p><code><?= htmlspecialchars($host) ?></code> は登録されていません。</p>
<p><a href="http://localhost">管理UI</a> でサイトを登録してください。</p>
</body>
</html>
```

### 8.3 現設計との比較

| | 現設計（Node版） | PHP版 |
| --- | --- | --- |
| 検出方法 | リクエストごとに `fs.existsSync` | マッチなし時に resolve.php が再スキャン |
| 新ディレクトリ追加後 | 即アクセス可能 | 初回アクセス時にリダイレクト1回を挟んでアクセス可能 |
| 管理UIでの操作 | 不要 | **不要** |
| パフォーマンスコスト | 毎リクエストで `fs.existsSync`（グループ数×2回） | マッチ済みルートはファイルI/Oなし。未登録時のみ再スキャン |

### 8.4 その他のスキャン実行タイミング

resolve.php による自動解決に加え、以下のタイミングでも routing.map が再生成される:

1. **ルート変更時**: `saveState()` 内で常に再生成（グループ配下も含む）
2. **管理UI読み込み時**: フロントエンドが `/api/scan.php` を呼び出し、最新のスキャン結果を取得
3. **手動スキャン**: 管理UI上の「スキャン」ボタンで任意のタイミングで実行

---

## 9. ファイル構成

```
{ROUTER_HOME}/
  public/                  ← DocumentRoot（管理UI + API + 自動解決）
    index.html             ← 管理UI フロントエンド
    resolve.php            ← 未登録サブドメインの自動解決（セクション8）
    css/
    js/
    api/                   ← PHP Admin API
      health.php
      routes.php
      groups.php
      domains.php
      ssl.php
      env-check.php
      scan.php
      lib/
        store.php          ← routes.json 読み書き + routing.map 生成
  conf/
    routing-rules.conf     ← Apache 共通ルーティングルール
  data/
    routes.json            ← ルーティングデータ（永続化）
    routes.json.bak        ← バックアップ
    routing.map            ← RewriteMap 用（自動生成）
  ssl/                     ← SSL 証明書（オプション）
    cert.pem
    key.pem
```

現設計から削除されるもの:

| 削除 | 理由 |
| --- | --- |
| `app/router.js` | `txt:` RewriteMap + resolve.php で代替 |
| `app/admin.js` | PHP Admin API で代替 |
| `run/admin.sock` | Unix socket 不要 |
| `run/` ディレクトリ自体 | 不要 |
| `node_modules/` | Node 依存なし |
| `package.json` | Node 依存なし |

---

## 10. 環境チェックの変更

### 10.1 必須モジュール

| モジュール | 用途 | Node版との差分 |
| --- | --- | --- |
| mod_rewrite | ルーティングルール | 変更なし |
| mod_proxy | リバースプロキシ | 変更なし |
| mod_proxy_http | HTTP プロキシ | 変更なし |
| mod_proxy_wstunnel | WebSocket プロキシ | 変更なし |
| mod_headers | X-Forwarded-Proto | 変更なし |
| **PHP（mod_php or php-fpm）** | **管理 API + 自動解決** | **追加（Node の代替）** |

### 10.2 不要になるもの

| 項目 | 理由 |
| --- | --- |
| Node.js v18+ | 不要 |
| npm / node_modules | 不要 |
| Hono フレームワーク | 不要 |

Apache + PHP 環境は本システムの主要ターゲットである
WordPress / Laravel 開発者が既に持っている可能性が高く、
追加の依存が減ることは導入障壁の低下に直結する。

---

## 11. 技術スタック比較

| レイヤ | 現設計（Node版） | PHP版 |
| --- | --- | --- |
| ルーティングエンジン | Node `prg:` サブプロセス（stdin/stdout） | `txt:` RewriteMap（ファイル参照） |
| 未登録サブドメインの解決 | `fs.existsSync` で毎リクエスト検出 | resolve.php で初回アクセス時に自動解決 |
| 管理 API | Node Worker Thread + Hono + Unix socket | PHP ファイル（Apache 直接実行） |
| フロントエンド | HTML / CSS / vanilla JS | **変更なし** |
| データ永続化 | routes.json | **変更なし** |
| 状態同期 | MessagePort（インメモリ→インメモリ） | routes.json → routing.map 再生成 |
| 大文字小文字正規化 | Node `toLowerCase()` | Apache `int:tolower` |
| プロセス管理 | Apache prg: + Worker auto-restart | **不要** |

---

## 12. トレードオフまとめ

### PHP版が優れる点

- **アーキテクチャの大幅な単純化**: プロセス管理・スレッド分離・socket通信が全て消える
- **外部依存ゼロ**: Apache + PHP のみ。npm install 不要
- **導入障壁の低下**: PHP 開発者が既に持っている環境で完結
- **graceful 時の UX**: php-fpm なら API が途切れない
- **Node クラッシュリスク排除**: 長期プロセスが存在しないため、クラッシュの概念自体がない
- **設計ドキュメントの大幅な簡素化**: セクション5〜6の約半分が不要になる
- **登録済みルートのパフォーマンス**: `txt:` のハッシュキャッシュで高速に解決。Node版の毎リクエスト `fs.existsSync`（グループ数×2回のディスクアクセス）が不要

### PHP版が劣る点

- **未登録サブドメイン初回アクセス時のリダイレクト**: resolve.php による再スキャン→リダイレクトで1回のラウンドトリップが入る（Node版は直接解決）。2回目以降は routing.map にキャッシュされるため差はない

### 変わらない点

- ルーティング優先順位のロジック（明示登録 > グループ解決）
- スラグのルール・バリデーション
- リバースプロキシ・WebSocket 対応
- SSL の仕組み（mkcert + ワイルドカード証明書）
- セキュリティモデル（localhost 限定）
- ディレクトリアクセス許可の方針
- 管理UI フロントエンドの設計
- routes.json のデータ構造
- `<Directory />` による全パス許可（graceful 不要の根拠）
- **新サブディレクトリの自動検出**: Node版は `fs.existsSync` で即時、PHP版は resolve.php で初回リダイレクト1回。いずれも管理UIでの操作は不要

---

## 13. 保留事項

現設計の保留事項に加え、以下を検討:

- **PHP バージョン要件**: 最低 PHP 7.4 以上（`fn()` アロー関数等）。推奨 PHP 8.0 以上

### 解決済み

- ~~**routing.map の再読み込みタイミング**~~ — Apache の `txt:` RewriteMap はルックアップのたびに `stat()` で mtime を確認する（`mod_rewrite.c` の `lookup_map()` 関数）。ファイル変更後の次のリクエストで即座に反映される。ポーリング間隔や TTL は存在しない。
- ~~**新サブディレクトリの検出遅延**~~ — resolve.php による自動解決メカニズム（セクション8）で解消。未登録サブドメインへのアクセス時にグループディレクトリを再スキャンし、存在すればリダイレクトする。
