<?php
/**
 * DevRouter API — 単一エントリポイント
 */

require_once __DIR__ . "/lib/store.php";
require_once __DIR__ . "/lib/router.php";
require_once __DIR__ . "/lib/env.php";
require_once __DIR__ . "/lib/ssl.php";

// ============================================================
//  ヘルスチェック
// ============================================================

get("/health", function() {
  jsonResponse(["status" => "ok"]);
});

// ============================================================
//  ベースドメイン
// ============================================================

get("/domains", function() {
  $result = loadState();
  jsonResponse([
    "baseDomains" => $result["state"]["baseDomains"],
    "warning"     => $result["warning"],
  ]);
});

post("/domains", function() {
  $domain = strtolower(trim(requireField(jsonInput(), "domain")));

  if(!isValidDomain($domain)) {
    errorResponse("無効なドメイン形式です（英数字・ハイフン・ドットのみ使用可）");
  }

  $state = loadState()["state"];

  foreach($state["baseDomains"] as $bd) {
    if($bd["domain"] === $domain) {
      errorResponse("ドメイン '{$domain}' は既に登録されています");
    }
  }

  $state["baseDomains"][] = [
    "domain"  => $domain,
    "current" => empty($state["baseDomains"]),
    "ssl"     => false,
  ];
  saveState($state);

  jsonResponse(["baseDomains" => $state["baseDomains"]], 201);
});

put("/domains", function() {
  $domain = strtolower(trim(requireField(jsonInput(), "domain")));
  $state = loadState()["state"];

  $found = false;
  foreach($state["baseDomains"] as &$bd) {
    $bd["current"] = ($bd["domain"] === $domain);
    if($bd["current"]) $found = true;
  }
  unset($bd);

  if(!$found) {
    errorResponse("ドメイン '{$domain}' は登録されていません", 404);
  }

  saveState($state);
  jsonResponse(["baseDomains" => $state["baseDomains"]]);
});

delete("/domains", function() {
  $domain = strtolower(trim(requireField(jsonInput(), "domain")));
  $state = loadState()["state"];

  $index = null;
  foreach($state["baseDomains"] as $i => $bd) {
    if($bd["domain"] === $domain) { $index = $i; break; }
  }

  if($index === null) {
    errorResponse("ドメイン '{$domain}' は登録されていません", 404);
  }
  if($state["baseDomains"][$index]["current"]) {
    errorResponse("current に設定されているドメインは削除できません。先に別のドメインを current に設定してください");
  }

  array_splice($state["baseDomains"], $index, 1);
  saveState($state);
  jsonResponse(["baseDomains" => $state["baseDomains"]]);
});

// ============================================================
//  グループ
// ============================================================

get("/groups", function() {
  $result = loadState();
  jsonResponse([
    "groups"  => buildGroupsInfo($result["state"]),
    "warning" => $result["warning"],
  ]);
});

post("/groups", function() {
  $path = rtrim(requireField(jsonInput(), "path"), "/");

  if(!is_dir($path)) {
    errorResponse("ディレクトリが存在しません: {$path}");
  }

  $state = loadState()["state"];

  foreach($state["groups"] as $group) {
    if($group["path"] === $path) {
      errorResponse("グループ '{$path}' は既に登録されています");
    }
  }

  $state["groups"][] = ["path" => $path];
  saveState($state);
  jsonResponse(["groups" => buildGroupsInfo($state)], 201);
});

put("/groups", function() {
  $input = jsonInput();
  if(!isset($input["order"]) || !is_array($input["order"])) {
    errorResponse("order（パスの配列）は必須です");
  }

  $state = loadState()["state"];
  $existingPaths = array_column($state["groups"], "path");

  foreach($input["order"] as $path) {
    if(!in_array($path, $existingPaths, true)) {
      errorResponse("グループ '{$path}' は登録されていません");
    }
  }
  if(count($input["order"]) !== count($existingPaths)) {
    errorResponse("order には全てのグループパスを含めてください");
  }

  $state["groups"] = array_map(fn($path) => ["path" => $path], $input["order"]);
  saveState($state);
  jsonResponse(["groups" => buildGroupsInfo($state)]);
});

delete("/groups", function() {
  $path = rtrim(requireField(jsonInput(), "path"), "/");
  $state = loadState()["state"];

  $index = null;
  foreach($state["groups"] as $i => $group) {
    if($group["path"] === $path) { $index = $i; break; }
  }

  if($index === null) {
    errorResponse("グループ '{$path}' は登録されていません", 404);
  }

  array_splice($state["groups"], $index, 1);
  saveState($state);
  jsonResponse(["groups" => buildGroupsInfo($state)]);
});

// ============================================================
//  ルート
// ============================================================

get("/routes", function() {
  $result = loadState();
  jsonResponse([
    "routes"  => $result["state"]["routes"],
    "warning" => $result["warning"],
  ]);
});

post("/routes", function() {
  $input = jsonInput();
  $slug   = strtolower(trim(requireField($input, "slug")));
  $target = trim(requireField($input, "target"));
  $type   = requireField($input, "type");

  if(!in_array($type, ["directory", "proxy"], true)) {
    errorResponse("type は \"directory\" または \"proxy\" を指定してください");
  }
  if(!preg_match(SLUG_PATTERN, $slug)) {
    errorResponse("スラグは英小文字・数字・ハイフンのみ使用可能です（先頭と末尾はハイフン不可）");
  }
  if($type === "directory" && !is_dir($target)) {
    errorResponse("ディレクトリが存在しません: {$target}");
  }
  if($type === "proxy" && !preg_match("#^https?://.+#", $target)) {
    errorResponse("proxy の target は http:// または https:// で始まる URL を指定してください");
  }

  $state = loadState()["state"];

  foreach($state["routes"] as $route) {
    if($route["slug"] === $slug) {
      errorResponse("スラグ '{$slug}' は既に登録されています");
    }
  }

  $state["routes"][] = ["slug" => $slug, "target" => $target, "type" => $type];
  saveState($state);
  jsonResponse(["routes" => $state["routes"]], 201);
});

delete("/routes", function() {
  $slug = strtolower(trim(requireField(jsonInput(), "slug")));
  $state = loadState()["state"];

  $index = null;
  foreach($state["routes"] as $i => $route) {
    if($route["slug"] === $slug) { $index = $i; break; }
  }

  if($index === null) {
    errorResponse("スラグ '{$slug}' は登録されていません", 404);
  }

  array_splice($state["routes"], $index, 1);
  saveState($state);
  jsonResponse(["routes" => $state["routes"]]);
});

// ============================================================
//  スキャン
// ============================================================

post("/scan", function() {
  $state = loadState()["state"];
  saveState($state);
  jsonResponse(["message" => "スキャン完了", "groups" => buildGroupsInfo($state)]);
});

// ============================================================
//  SSL
// ============================================================

get("/ssl", function() {
  $state = loadState()["state"];

  jsonResponse([
    "mkcert"     => checkMkcertStatus(),
    "certExists" => file_exists(ROUTER_HOME . "/ssl/cert.pem") && file_exists(ROUTER_HOME . "/ssl/key.pem"),
    "domains"    => array_map(fn($bd) => ["domain" => $bd["domain"], "ssl" => $bd["ssl"]], $state["baseDomains"]),
  ]);
});

post("/ssl", function() {
  $domain = strtolower(trim(requireField(jsonInput(), "domain")));

  $mkcert = checkMkcertStatus();
  if(!$mkcert["installed"])   errorResponse("mkcert がインストールされていません");
  if(!$mkcert["caInstalled"]) errorResponse("mkcert のローカル CA が登録されていません。mkcert -install を実行してください");

  $state = loadState()["state"];

  $found = false;
  foreach($state["baseDomains"] as &$bd) {
    if($bd["domain"] === $domain) { $bd["ssl"] = true; $found = true; }
  }
  unset($bd);

  if(!$found) {
    errorResponse("ドメイン '{$domain}' は登録されていません", 404);
  }

  saveState($state);

  $sans = array_map(fn($bd) => "*." . $bd["domain"], array_filter($state["baseDomains"], fn($bd) => $bd["ssl"]));
  if(empty($sans)) {
    errorResponse("SSL が有効なベースドメインがありません");
  }

  $cmd = "mkcert -cert-file " . escapeshellarg(ROUTER_HOME . "/ssl/cert.pem")
       . " -key-file " . escapeshellarg(ROUTER_HOME . "/ssl/key.pem")
       . " " . implode(" ", array_map("escapeshellarg", $sans)) . " 2>&1";

  exec($cmd, $output, $exitCode);
  if($exitCode !== 0) {
    errorResponse("証明書の発行に失敗しました: " . implode("\n", $output));
  }

  $httpsDeployed = deployHttpsVhost();
  triggerGracefulRestart();

  jsonResponse(["message" => "HTTPS を有効化しました", "sans" => $sans, "httpsDeployed" => $httpsDeployed]);
});

// ============================================================
//  ディレクトリブラウズ（オートコンプリート用）
// ============================================================

get("/browse-dirs", function() {
  $path = $_GET["path"] ?? "";
  $showDot = ($_GET["dot"] ?? "") === "1";

  $rootBlacklist = ["System", "bin", "sbin", "usr", "etc", "private", "dev", "proc", "tmp", "Library", "cores"];
  $userHome = getUserHome();

  // パスが空の場合はホームディレクトリ情報のみ返す
  if($path === "") {
    jsonResponse(["userHome" => $userHome]);
  }

  // 末尾スラッシュの有無で動作を分岐
  if(str_ends_with($path, "/")) {
    // 末尾 / あり → そのディレクトリの中身を一覧
    $trimmed = rtrim($path, "/");
    $realPath = $trimmed === "" ? "/" : realpath($trimmed);
    if($realPath === false || !is_dir($realPath)) {
      jsonResponse(["dirs" => [], "current" => $path, "userHome" => $userHome]);
    }
    $dirs = listSubdirs($realPath, "", $showDot, $rootBlacklist);
    jsonResponse(["dirs" => $dirs, "current" => $realPath, "userHome" => $userHome]);
  }

  // 末尾 / なし → 親ディレクトリ内をプレフィックスで絞り込み
  $parentPath = dirname($path);
  $prefix = basename($path);
  $realParent = realpath($parentPath);

  if($realParent === false || !is_dir($realParent)) {
    jsonResponse(["dirs" => [], "current" => $path, "userHome" => $userHome]);
  }

  $dirs = listSubdirs($realParent, $prefix, $showDot, $rootBlacklist);
  jsonResponse(["dirs" => $dirs, "current" => $realParent, "prefix" => $prefix, "userHome" => $userHome]);
});

// ============================================================
//  環境チェック
// ============================================================

get("/env-check", function() {
  $os = detectOS();
  $modules = getLoadedModules();
  $checks = [];

  $moduleChecks = [
    "required" => ["rewrite" => "mod_rewrite", "headers" => "mod_headers"],
    "proxy"    => ["proxy" => "mod_proxy", "proxy_http" => "mod_proxy_http"],
    "websocket" => ["proxy_wstunnel" => "mod_proxy_wstunnel"],
    "ssl"      => ["ssl" => "mod_ssl"],
  ];

  foreach($moduleChecks as $category => $defs) {
    foreach($defs as $key => $label) {
      $enabled = in_array($key, $modules, true);
      $checks[] = [
        "category" => $category,
        "name"     => $label,
        "status"   => $enabled ? "ok" : "missing",
        "command"  => $enabled ? null : getEnableCommand($key, $os),
      ];
    }
  }

  $mkcertStatus = checkMkcertForEnv();
  $checks[] = ["category" => "ssl", "name" => "mkcert", "status" => $mkcertStatus["status"], "command" => $mkcertStatus["command"]];

  jsonResponse(["os" => $os, "checks" => $checks]);
});

// ============================================================
//  実行
// ============================================================

dispatch();
