<?php
/**
 * store.php — routes.json 読み書き + VirtualHost 定義生成
 *
 * DevRouter のコアデータ管理ライブラリ。
 * 全 API エンドポイントがこのファイルを require して利用する。
 *
 * saveState() は以下を実行する:
 * 1. routes.json にアトミック書き込み
 * 2. routes.conf（HTTP VirtualHost）を生成
 * 3. routes-ssl.conf（HTTPS VirtualHost）を生成（SSL 有効時のみ）
 * 4. Apache graceful restart を実行
 */

// ROUTER_HOME: このファイルから3階層上がリポジトリルート
define("ROUTER_HOME", realpath(__DIR__ . "/../../.."));
define("ROUTES_JSON", ROUTER_HOME . "/data/routes.json");
define("ROUTES_BAK",  ROUTER_HOME . "/data/routes.json.bak");
define("ROUTES_CONF",     ROUTER_HOME . "/data/routes.conf");
define("ROUTES_SSL_CONF", ROUTER_HOME . "/data/routes-ssl.conf");

// スラグの許可パターン
define("SLUG_PATTERN", "/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/");

// ベースドメインの許可パターン（英数字・ハイフン・ドットのみ）
define("DOMAIN_PATTERN", "/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i");

/**
 * routes.json を読み込む。
 * パース失敗時はバックアップから復元、それも失敗なら空の初期状態を返す。
 *
 * @return array ['state' => array, 'warning' => string|null]
 */
function loadState(): array {
  $warning = null;

  // メインファイルから読み込み
  if(file_exists(ROUTES_JSON)) {
    $json = file_get_contents(ROUTES_JSON);
    $state = json_decode($json, true);
    if(is_array($state) && isset($state["baseDomains"])) {
      return ["state" => $state, "warning" => null];
    }
  }

  // バックアップから復元
  if(file_exists(ROUTES_BAK)) {
    $json = file_get_contents(ROUTES_BAK);
    $state = json_decode($json, true);
    if(is_array($state) && isset($state["baseDomains"])) {
      // バックアップをメインとして書き戻す
      file_put_contents(ROUTES_JSON, $json);
      return [
        "state" => $state,
        "warning" => "routes.json のパースに失敗したため、バックアップから復元しました",
      ];
    }
  }

  // 空の初期状態
  $state = getEmptyState();
  $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
  file_put_contents(ROUTES_JSON, $json);

  return [
    "state" => $state,
    "warning" => "routes.json とバックアップの両方が利用できないため、空の初期状態で起動しました",
  ];
}

/**
 * 空の初期状態を返す
 */
function getEmptyState(): array {
  return [
    "baseDomains" => [
      [
        "domain"  => "127.0.0.1.nip.io",
        "current" => true,
        "ssl"     => false,
      ],
    ],
    "groups" => [],
    "routes" => [],
  ];
}

/**
 * ベースドメインのバリデーション。
 * Apache 設定インジェクションを防止するため、ドメイン名として有効な文字のみを許可する。
 *
 * @param string $domain
 * @return bool
 */
function isValidDomain(string $domain): bool {
  if($domain === "" || strlen($domain) > 253) {
    return false;
  }
  return (bool) preg_match(DOMAIN_PATTERN, $domain);
}

/**
 * routes.json を保存し、VirtualHost 定義を再生成する。
 *
 * 1. バックアップ作成
 * 2. routes.json にアトミック書き込み
 * 3. routes.conf 再生成
 * 4. routes-ssl.conf 再生成（SSL 有効ドメインがある場合）
 * 5. Apache graceful restart
 *
 * @param array $state 保存する状態
 * @return void
 */
function saveState(array $state): void {
  $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

  // バックアップ作成（既存ファイルがある場合）
  if(file_exists(ROUTES_JSON)) {
    copy(ROUTES_JSON, ROUTES_BAK);
  }

  // アトミック書き込み（一時ファイル + rename）
  $tmpFile = ROUTES_JSON . ".tmp." . getmypid();
  file_put_contents($tmpFile, $json, LOCK_EX);
  rename($tmpFile, ROUTES_JSON);

  // routes.conf 再生成
  $routesConf = generateRoutesConf($state);
  atomicWrite(ROUTES_CONF, $routesConf);

  // routes-ssl.conf 再生成
  $sslConf = generateRoutesSslConf($state);
  atomicWrite(ROUTES_SSL_CONF, $sslConf);

  // Apache graceful restart
  triggerGracefulRestart();
}

/**
 * ファイルをアトミック書き込みする。
 * 一時ファイルに書き込み → rename で置換。
 *
 * @param string $path ファイルパス
 * @param string $content 書き込む内容
 * @return void
 */
function atomicWrite(string $path, string $content): void {
  $tmpFile = $path . ".tmp." . getmypid();
  file_put_contents($tmpFile, $content, LOCK_EX);
  rename($tmpFile, $path);
}

/**
 * routes.json の内容から routes.conf（HTTP VirtualHost）を生成する。
 *
 * 生成順序:
 * 1. ベースドメイン直アクセス → リダイレクト VirtualHost（管理UIへ 302）
 * 2. 明示登録（routes）→ 全ベースドメインとの組み合わせで VirtualHost 生成
 * 3. グループ解決（登録順走査、先にマッチしたグループ優先）→ 全ベースドメインとの組み合わせ
 *
 * @param array $state
 * @return string routes.conf のテキスト内容
 */
function generateRoutesConf(array $state): string {
  $lines = ["# 自動生成 — 手動編集禁止", ""];
  $domains = array_column($state["baseDomains"], "domain");
  $resolvedRoutes = resolveAllRoutes($state);

  // 1. ベースドメイン → 管理UIへリダイレクト
  foreach($domains as $domain) {
    $lines[] = "# --- ベースドメイン → 管理UIへリダイレクト ---";
    $lines[] = "<VirtualHost *:80>";
    $lines[] = "    ServerName {$domain}";
    $lines[] = "    RewriteEngine On";
    $lines[] = "    RewriteRule ^ http://localhost [R=302,L]";
    $lines[] = "</VirtualHost>";
    $lines[] = "";
  }

  // 2. 解決済みルートから VirtualHost 生成
  foreach($resolvedRoutes as $route) {
    foreach($domains as $domain) {
      $serverName = "{$route["slug"]}.{$domain}";
      $lines = array_merge($lines, generateHttpVirtualHost($serverName, $route));
      $lines[] = "";
    }
  }

  return implode("\n", $lines);
}

/**
 * routes.json の内容から routes-ssl.conf（HTTPS VirtualHost）を生成する。
 * SSL が有効なベースドメインのルートのみ生成する。
 *
 * @param array $state
 * @return string routes-ssl.conf のテキスト内容
 */
function generateRoutesSslConf(array $state): string {
  $lines = ["# 自動生成 — 手動編集禁止", ""];

  // SSL が有効なベースドメインを抽出
  $sslDomains = [];
  foreach($state["baseDomains"] as $bd) {
    if(!empty($bd["ssl"])) {
      $sslDomains[] = $bd["domain"];
    }
  }

  // SSL 有効ドメインがない場合は空ファイル
  if(empty($sslDomains)) {
    return implode("\n", $lines);
  }

  $resolvedRoutes = resolveAllRoutes($state);

  // ベースドメイン → 管理UIへリダイレクト
  foreach($sslDomains as $domain) {
    $lines[] = "# --- ベースドメイン → 管理UIへリダイレクト ---";
    $lines[] = "<VirtualHost *:443>";
    $lines[] = "    ServerName {$domain}";
    $lines[] = "    SSLEngine on";
    $lines[] = "    SSLCertificateFile " . ROUTER_HOME . "/ssl/cert.pem";
    $lines[] = "    SSLCertificateKeyFile " . ROUTER_HOME . "/ssl/key.pem";
    $lines[] = "    RewriteEngine On";
    $lines[] = "    RewriteRule ^ https://localhost [R=302,L]";
    $lines[] = "</VirtualHost>";
    $lines[] = "";
  }

  // 解決済みルートから HTTPS VirtualHost 生成
  foreach($resolvedRoutes as $route) {
    foreach($sslDomains as $domain) {
      $serverName = "{$route["slug"]}.{$domain}";
      $lines = array_merge($lines, generateHttpsVirtualHost($serverName, $route));
      $lines[] = "";
    }
  }

  return implode("\n", $lines);
}

/**
 * 明示登録ルートとグループ解決ルートを統合して返す。
 * 明示登録スラグと同名のサブディレクトリがある場合、明示登録が優先される。
 *
 * @param array $state
 * @return array [['slug' => string, 'target' => string, 'type' => string], ...]
 */
function resolveAllRoutes(array $state): array {
  $resolved = [];
  $usedSlugs = [];

  // 明示登録（routes）
  foreach($state["routes"] as $route) {
    $slug = $route["slug"];
    $usedSlugs[$slug] = true;
    $resolved[] = [
      "slug"   => $slug,
      "target" => $route["target"],
      "type"   => $route["type"],
    ];
  }

  // グループ解決（登録順走査、先にマッチしたグループ優先）
  foreach($state["groups"] as $group) {
    $path = $group["path"];
    if(!is_dir($path)) {
      continue;
    }
    $entries = scanGroupDirectory($path);
    foreach($entries as $entry) {
      $slug = $entry["slug"];
      if(isset($usedSlugs[$slug])) {
        continue;
      }
      $usedSlugs[$slug] = true;
      $resolved[] = [
        "slug"   => $slug,
        "target" => $entry["target"],
        "type"   => "directory",
      ];
    }
  }

  return $resolved;
}

/**
 * HTTP VirtualHost のディレクティブ行を生成する。
 *
 * @param string $serverName ServerName に設定する値
 * @param array $route ['slug', 'target', 'type']
 * @return array Apache 設定の行配列
 */
function generateHttpVirtualHost(string $serverName, array $route): array {
  $type = $route["type"];
  $target = $route["target"];

  if($type === "directory") {
    return [
      "# --- ディレクトリ公開 ---",
      "<VirtualHost *:80>",
      "    ServerName {$serverName}",
      "    DocumentRoot {$target}",
      "    <Directory {$target}>",
      "        Options FollowSymLinks Indexes",
      "        AllowOverride All",
      "        Require all granted",
      "    </Directory>",
      "    DirectoryIndex index.php index.html index.htm",
      "</VirtualHost>",
    ];
  }

  if($type === "proxy") {
    $parsed = parse_url($target);
    $port = $parsed["port"] ?? ($parsed["scheme"] === "https" ? 443 : 80);
    $wsScheme = $parsed["scheme"] === "https" ? "wss" : "ws";
    $host = $parsed["host"] ?? "localhost";
    $wsTarget = "{$wsScheme}://{$host}:{$port}";

    return [
      "# --- リバースプロキシ（WebSocket 対応）---",
      "<IfModule mod_proxy.c>",
      "<VirtualHost *:80>",
      "    ServerName {$serverName}",
      "    ProxyPreserveHost On",
      "    RewriteEngine On",
      "    RewriteCond %{HTTP:Upgrade} =websocket [NC]",
      "    RewriteRule ^(.*)\$ {$wsTarget}\$1 [P,L]",
      "    ProxyPass / {$target}/",
      "    ProxyPassReverse / {$target}/",
      "    RequestHeader set X-Forwarded-Proto \"http\"",
      "</VirtualHost>",
      "</IfModule>",
    ];
  }

  // 不明な type はスキップ
  return [];
}

/**
 * HTTPS VirtualHost のディレクティブ行を生成する。
 *
 * @param string $serverName ServerName に設定する値
 * @param array $route ['slug', 'target', 'type']
 * @return array Apache 設定の行配列
 */
function generateHttpsVirtualHost(string $serverName, array $route): array {
  $type = $route["type"];
  $target = $route["target"];
  $sslLines = [
    "    SSLEngine on",
    "    SSLCertificateFile " . ROUTER_HOME . "/ssl/cert.pem",
    "    SSLCertificateKeyFile " . ROUTER_HOME . "/ssl/key.pem",
  ];

  if($type === "directory") {
    return [
      "# --- ディレクトリ公開 ---",
      "<VirtualHost *:443>",
      "    ServerName {$serverName}",
      ...$sslLines,
      "    DocumentRoot {$target}",
      "    <Directory {$target}>",
      "        Options FollowSymLinks Indexes",
      "        AllowOverride All",
      "        Require all granted",
      "    </Directory>",
      "    DirectoryIndex index.php index.html index.htm",
      "</VirtualHost>",
    ];
  }

  if($type === "proxy") {
    $parsed = parse_url($target);
    $port = $parsed["port"] ?? ($parsed["scheme"] === "https" ? 443 : 80);
    $host = $parsed["host"] ?? "localhost";
    $wsTarget = "wss://{$host}:{$port}";

    return [
      "# --- リバースプロキシ（WebSocket 対応）---",
      "<IfModule mod_proxy.c>",
      "<VirtualHost *:443>",
      "    ServerName {$serverName}",
      ...$sslLines,
      "    ProxyPreserveHost On",
      "    RewriteEngine On",
      "    RewriteCond %{HTTP:Upgrade} =websocket [NC]",
      "    RewriteRule ^(.*)\$ {$wsTarget}\$1 [P,L]",
      "    ProxyPass / {$target}/",
      "    ProxyPassReverse / {$target}/",
      "    RequestHeader set X-Forwarded-Proto \"https\"",
      "</VirtualHost>",
      "</IfModule>",
    ];
  }

  return [];
}

/**
 * Apache の graceful restart を実行する。
 *
 * setup.sh が生成した bin/graceful.sh を sudo 経由で実行する。
 * graceful.sh は conf/env.conf から Apache バイナリパスを読み込み、
 * root マスタープロセスに USR1 シグナルを送信する。
 *
 * sudoers で PHP 実行ユーザに graceful.sh の NOPASSWD 実行を許可済みの前提。
 *
 * @return void
 */
function triggerGracefulRestart(): void {
  $script = ROUTER_HOME . "/bin/graceful.sh";

  if(!file_exists($script)) {
    return;
  }

  // フルパスで sudo を実行（Apache 環境の PATH 問題を回避）
  $cmd = "/usr/bin/sudo -n " . escapeshellarg($script) . " 2>&1";
  exec($cmd, $output, $exitCode);
}

/**
 * グループディレクトリのサブディレクトリをスキャンする。
 * スラグパターン一致チェック + public/ 自動検出を行う。
 *
 * @param string $groupPath グループディレクトリのパス
 * @return array [['slug' => string, 'target' => string], ...]
 */
function scanGroupDirectory(string $groupPath): array {
  $result = scanGroupDirectoryFull($groupPath);
  return $result["valid"];
}

/**
 * グループディレクトリの全サブディレクトリをスキャンする。
 * パターン一致するものと非対応のものを分けて返す。
 *
 * @param string $groupPath グループディレクトリのパス
 * @return array ['valid' => [...], 'skipped' => [...]]
 */
function scanGroupDirectoryFull(string $groupPath): array {
  $valid = [];
  $skipped = [];

  $items = @scandir($groupPath);
  if($items === false) {
    return ["valid" => [], "skipped" => []];
  }

  foreach($items as $item) {
    // . と .. をスキップ
    if($item === "." || $item === "..") {
      continue;
    }

    $fullPath = $groupPath . "/" . $item;

    // ディレクトリのみ対象
    if(!is_dir($fullPath)) {
      continue;
    }

    // スラグパターン一致チェック
    if(!preg_match(SLUG_PATTERN, $item)) {
      $skipped[] = [
        "name"   => $item,
        "reason" => "スラグパターンに一致しません（英小文字・数字・ハイフンのみ使用可）",
      ];
      continue;
    }

    // public/ 自動検出: public/ があればそちらを DocumentRoot
    $target = $fullPath;
    if(is_dir($fullPath . "/public")) {
      $target = $fullPath . "/public";
    }

    $valid[] = [
      "slug"   => $item,
      "target" => $target,
    ];
  }

  return ["valid" => $valid, "skipped" => $skipped];
}

/**
 * JSON レスポンスを返すヘルパー
 *
 * @param mixed $data レスポンスデータ
 * @param int $statusCode HTTP ステータスコード
 */
function jsonResponse($data, int $statusCode = 200): void {
  http_response_code($statusCode);
  header("Content-Type: application/json; charset=utf-8");
  echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
  exit;
}

/**
 * エラーレスポンスを返すヘルパー
 *
 * @param string $message エラーメッセージ
 * @param int $statusCode HTTP ステータスコード
 */
function errorResponse(string $message, int $statusCode = 400): void {
  jsonResponse(["error" => $message], $statusCode);
}

/**
 * conf/env.conf からユーザのホームディレクトリを取得する。
 * 取得できない場合は null を返す。
 *
 * @return string|null ホームディレクトリのパス
 */
function getUserHome(): ?string {
  $envConf = ROUTER_HOME . "/conf/env.conf";
  if(!file_exists($envConf)) {
    return null;
  }

  $lines = file($envConf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  foreach($lines as $line) {
    $line = trim($line);
    if(str_starts_with($line, "#")) continue;
    if(str_starts_with($line, "USER_HOME=")) {
      return substr($line, strlen("USER_HOME="));
    }
  }

  return null;
}

/**
 * 指定ディレクトリのサブディレクトリ一覧を返す。
 * ファイルは除外し、ディレクトリのみ返す。
 *
 * @param string $dir           スキャン対象ディレクトリ
 * @param string $prefix        名前のプレフィックスフィルタ（部分入力時）
 * @param bool   $showDot       ドットディレクトリを含めるか
 * @param array  $rootBlacklist ルート直下の除外ディレクトリ名
 * @return array ディレクトリ名の配列
 */
function listSubdirs(string $dir, string $prefix, bool $showDot, array $rootBlacklist): array {
  $items = @scandir($dir);
  if($items === false) {
    return [];
  }

  $isRoot = ($dir === "/");
  $dirs = [];

  foreach($items as $item) {
    if($item === "." || $item === "..") {
      continue;
    }

    if(!$showDot && str_starts_with($item, ".")) {
      continue;
    }

    if($isRoot && in_array($item, $rootBlacklist, true)) {
      continue;
    }

    if($prefix !== "" && !str_starts_with(strtolower($item), strtolower($prefix))) {
      continue;
    }

    $fullPath = $dir === "/" ? "/{$item}" : "{$dir}/{$item}";
    if(!is_dir($fullPath)) {
      continue;
    }

    $dirs[] = $item;
  }

  sort($dirs, SORT_STRING | SORT_FLAG_CASE);
  return $dirs;
}

/**
 * 衝突検出・スキップ情報を含むグループ情報を構築する。
 * groups.php と scan.php で共有。
 *
 * @param array $state routes.json の状態
 * @return array グループ情報の配列
 */
function buildGroupsInfo(array $state): array {
  $groupsInfo = [];
  $seenSlugs = [];

  // 明示登録スラグを先に収集
  foreach($state["routes"] as $route) {
    $seenSlugs[$route["slug"]] = ["type" => "explicit"];
  }

  foreach($state["groups"] as $group) {
    $path = $group["path"];
    $scanResult = is_dir($path) ? scanGroupDirectoryFull($path) : ["valid" => [], "skipped" => []];
    $subdirs = [];

    foreach($scanResult["valid"] as $entry) {
      $slug = $entry["slug"];
      $status = "active";
      $conflictWith = null;

      if(isset($seenSlugs[$slug])) {
        $status = "shadowed";
        $conflictWith = $seenSlugs[$slug]["type"] === "explicit"
          ? "明示登録"
          : $seenSlugs[$slug]["group"];
      } else {
        $seenSlugs[$slug] = ["type" => "group", "group" => $path];
      }

      $subdirs[] = [
        "slug"         => $slug,
        "target"       => $entry["target"],
        "status"       => $status,
        "conflictWith" => $conflictWith,
      ];
    }

    $groupsInfo[] = [
      "path"    => $path,
      "exists"  => is_dir($path),
      "subdirs" => $subdirs,
      "skipped" => $scanResult["skipped"],
    ];
  }

  return $groupsInfo;
}
