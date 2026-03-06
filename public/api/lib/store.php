<?php
/**
 * store.php — routes.json 読み書き + ユーティリティ
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

require_once __DIR__ . "/vhost-generator.php";
require_once __DIR__ . "/route-resolver.php";
require_once __DIR__ . "/browse-helpers.php";

// ROUTER_HOME: このファイルから3階層上がリポジトリルート（テスト時は事前定義を優先）
if(!defined("ROUTER_HOME"))     define("ROUTER_HOME", realpath(__DIR__ . "/../../.."));

require_once __DIR__ . "/logger.php";
if(!defined("ROUTES_JSON"))     define("ROUTES_JSON", ROUTER_HOME . "/data/routes.json");
if(!defined("ROUTES_BAK"))      define("ROUTES_BAK",  ROUTER_HOME . "/data/routes.json.bak");
if(!defined("ROUTES_CONF"))     define("ROUTES_CONF",     ROUTER_HOME . "/data/routes.conf");
if(!defined("ROUTES_SSL_CONF")) define("ROUTES_SSL_CONF", ROUTER_HOME . "/data/routes-ssl.conf");

// ベースドメインの許可パターン（英数字・ハイフン・ドットのみ）
if(!defined("DOMAIN_PATTERN"))  define("DOMAIN_PATTERN", "/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i");

// グループスラグの許可パターン（英小文字・数字・ハイフンのみ、先頭末尾ハイフン不可）
if(!defined("SLUG_PATTERN"))    define("SLUG_PATTERN", "/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/");

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
      $migration = migrateState($state);
      if($migration["migrated"]) {
        saveState($migration["state"]);
      }
      return ["state" => $migration["state"], "warning" => null];
    }
  }

  // バックアップから復元
  if(file_exists(ROUTES_BAK)) {
    $json = file_get_contents(ROUTES_BAK);
    $state = json_decode($json, true);
    if(is_array($state) && isset($state["baseDomains"])) {
      $migration = migrateState($state);
      // バックアップをメインとして書き戻す
      file_put_contents(ROUTES_JSON, $json);
      logInfo("routes.json のパース失敗、バックアップから復元");
      if($migration["migrated"]) {
        saveState($migration["state"]);
      }
      return [
        "state" => $migration["state"],
        "warning" => "routes.json のパースに失敗したため、バックアップから復元しました",
      ];
    }
  }

  // 空の初期状態
  $state = getEmptyState();
  $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
  file_put_contents(ROUTES_JSON, $json);
  logInfo("routes.json とバックアップが利用不可、空の初期状態で起動");

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
 * グループスラグのバリデーション。
 *
 * @param string $slug
 * @return bool
 */
function isValidGroupSlug(string $slug): bool {
  if($slug === "" || strlen($slug) > 63) {
    return false;
  }
  return (bool) preg_match(SLUG_PATTERN, $slug);
}

/**
 * 旧形式の state を新形式にマイグレーションする。
 *
 * - グループに slug がなければパス末尾のディレクトリ名を付与
 * - グループに ssl がなければ false を付与
 *
 * @param array $state
 * @return array ['state' => array, 'migrated' => bool]
 */
function migrateState(array $state): array {
  $migrated = false;

  foreach($state["groups"] as &$group) {
    if(!isset($group["slug"])) {
      $group["slug"] = strtolower(basename($group["path"]));
      $migrated = true;
    }
    if(!isset($group["ssl"])) {
      $group["ssl"] = false;
      $migrated = true;
    }
    if(!isset($group["label"])) {
      $group["label"] = "";
      $migrated = true;
    }
  }
  unset($group);

  foreach($state["routes"] as &$route) {
    if(!isset($route["label"])) {
      $route["label"] = "";
      $migrated = true;
    }
  }
  unset($route);

  return ["state" => $state, "migrated" => $migrated];
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
  if(file_put_contents($tmpFile, $json, LOCK_EX) === false) {
    logError("routes.json 書き込み失敗: 一時ファイル書き込みエラー");
    return;
  }
  if(!rename($tmpFile, ROUTES_JSON)) {
    logError("routes.json 書き込み失敗: rename エラー");
    if(file_exists($tmpFile)) {
      unlink($tmpFile);
    }
    return;
  }

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
  if(file_put_contents($tmpFile, $content, LOCK_EX) === false) {
    logError("アトミック書き込み失敗: 一時ファイル書き込みエラー", ["path" => $path]);
    return;
  }
  if(!rename($tmpFile, $path)) {
    logError("アトミック書き込み失敗: rename エラー", ["path" => $path]);
    if(file_exists($tmpFile)) {
      unlink($tmpFile);
    }
  }
}

/**
 * Apache の graceful restart を実行する（クールダウン付き）。
 *
 * setup.sh が生成した bin/graceful.sh を sudo 経由で実行する。
 * 2秒以内の連続実行はスキップし、pending フラグを設定する。
 * 次回呼び出し時にクールダウンが経過していれば実行される。
 *
 * @return void
 */
function triggerGracefulRestart(): void {
  $script = ROUTER_HOME . "/bin/graceful.sh";
  if(!file_exists($script)) {
    return;
  }

  $lastRestartFile = ROUTER_HOME . "/data/.last-restart";
  $pendingFile     = ROUTER_HOME . "/data/.restart-pending";
  $cooldown        = 2; // 秒

  // クールダウンチェック
  if(file_exists($lastRestartFile)) {
    $lastTime = (float) file_get_contents($lastRestartFile);
    if((microtime(true) - $lastTime) < $cooldown) {
      touch($pendingFile);
      logInfo("graceful restart スキップ（クールダウン中、pending 設定）");
      return;
    }
  }

  // restart 実行
  $cmd = "/usr/bin/sudo -n " . escapeshellarg($script) . " 2>&1";
  exec($cmd, $output, $exitCode);

  // タイムスタンプ更新、pending フラグをクリア
  file_put_contents($lastRestartFile, (string) microtime(true));
  if(file_exists($pendingFile)) {
    unlink($pendingFile);
  }

  if($exitCode === 0) {
    logInfo("graceful restart 実行成功");
  } else {
    logError("graceful restart 失敗", ["exitCode" => $exitCode, "output" => implode("\n", $output)]);
  }
}

/**
 * JSON 成功レスポンスを返すヘルパー
 *
 * エンベロープ形式: {"ok": true, "data": {...}, "warning": null|string}
 *
 * @param mixed $data レスポンスデータ
 * @param int $statusCode HTTP ステータスコード
 * @param string|null $warning 警告メッセージ（任意）
 */
function jsonResponse($data, int $statusCode = 200, ?string $warning = null): void {
  http_response_code($statusCode);
  header("Content-Type: application/json; charset=utf-8");
  $envelope = ["ok" => true, "data" => $data, "warning" => $warning];
  echo json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
  exit;
}

/**
 * JSON エラーレスポンスを返すヘルパー
 *
 * エンベロープ形式: {"ok": false, "error": "メッセージ"}
 *
 * @param string $message エラーメッセージ
 * @param int $statusCode HTTP ステータスコード
 */
function errorResponse(string $message, int $statusCode = 400): void {
  http_response_code($statusCode);
  header("Content-Type: application/json; charset=utf-8");
  echo json_encode(["ok" => false, "error" => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
  exit;
}

