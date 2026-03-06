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
  jsonResponse(["baseDomains" => $result["state"]["baseDomains"]], 200, $result["warning"]);
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
  logInfo("ドメイン追加: {$domain}");

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
  logInfo("ドメイン削除: {$domain}");
  jsonResponse(["baseDomains" => $state["baseDomains"]]);
});

// ============================================================
//  グループ
// ============================================================

get("/groups", function() {
  $result = loadState();
  jsonResponse(["groups" => buildGroupsInfo($result["state"])], 200, $result["warning"]);
});

post("/groups", function() {
  $input = jsonInput();
  $slug = strtolower(trim(requireField($input, "slug")));
  $path = rtrim(requireField($input, "path"), "/");

  if(!isValidGroupSlug($slug)) {
    errorResponse("スラグは英小文字・数字・ハイフンのみ使用可能です（先頭と末尾はハイフン不可、63文字以内）");
  }
  if(!is_dir($path)) {
    errorResponse("ディレクトリが存在しません: {$path}");
  }

  $state = loadState()["state"];

  foreach($state["groups"] as $group) {
    if($group["slug"] === $slug) {
      errorResponse("スラグ '{$slug}' は既に使用されています");
    }
    if($group["path"] === $path) {
      errorResponse("グループ '{$path}' は既に登録されています");
    }
  }

  $label = trim($input["label"] ?? "");
  $state["groups"][] = ["slug" => $slug, "path" => $path, "ssl" => false, "label" => $label];
  saveState($state);
  logInfo("グループ追加: slug={$slug}, path={$path}");
  jsonResponse(["groups" => buildGroupsInfo($state)], 201);
});

put("/groups", function() {
  $input = jsonInput();
  if(!isset($input["order"]) || !is_array($input["order"])) {
    errorResponse("order（スラグの配列）は必須です");
  }

  $state = loadState()["state"];
  $groupBySlug = [];
  foreach($state["groups"] as $group) {
    $groupBySlug[$group["slug"]] = $group;
  }

  foreach($input["order"] as $slug) {
    if(!isset($groupBySlug[$slug])) {
      errorResponse("グループ '{$slug}' は登録されていません");
    }
  }
  if(count($input["order"]) !== count($groupBySlug)) {
    errorResponse("order には全てのグループスラグを含めてください");
  }

  $state["groups"] = array_map(fn($slug) => $groupBySlug[$slug], $input["order"]);
  saveState($state);
  jsonResponse(["groups" => buildGroupsInfo($state)]);
});

delete("/groups", function() {
  $slug = strtolower(trim(requireField(jsonInput(), "slug")));
  $state = loadState()["state"];

  $index = null;
  foreach($state["groups"] as $i => $group) {
    if($group["slug"] === $slug) { $index = $i; break; }
  }

  if($index === null) {
    errorResponse("グループ '{$slug}' は登録されていません", 404);
  }

  array_splice($state["groups"], $index, 1);
  saveState($state);
  logInfo("グループ削除: {$slug}");
  jsonResponse(["groups" => buildGroupsInfo($state)]);
});

// ============================================================
//  ルート
// ============================================================

get("/routes", function() {
  $result = loadState();
  jsonResponse(["routes" => $result["state"]["routes"]], 200, $result["warning"]);
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

  $label = trim($input["label"] ?? "");
  $state["routes"][] = ["slug" => $slug, "target" => $target, "type" => $type, "label" => $label];
  saveState($state);
  logInfo("ルート追加: slug={$slug}, type={$type}, target={$target}");
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
  logInfo("ルート削除: {$slug}");
  jsonResponse(["routes" => $state["routes"]]);
});

// ============================================================
//  SSL
// ============================================================

get("/ssl", function() {
  $state = loadState()["state"];

  $groups = array_map(fn($g) => ["slug" => $g["slug"], "ssl" => $g["ssl"]], $state["groups"]);

  jsonResponse([
    "mkcert"     => checkMkcertStatus(),
    "certExists" => file_exists(ROUTER_HOME . "/ssl/cert.pem") && file_exists(ROUTER_HOME . "/ssl/key.pem"),
    "domains"    => array_map(fn($bd) => ["domain" => $bd["domain"], "ssl" => $bd["ssl"]], $state["baseDomains"]),
    "groups"     => $groups,
  ]);
});

post("/ssl", function() {
  $input = jsonInput();
  $type = $input["type"] ?? "domain";

  $mkcert = checkMkcertStatus();
  if(!$mkcert["installed"])   errorResponse("mkcert がインストールされていません");
  if(!$mkcert["caInstalled"]) errorResponse("mkcert のローカル CA が登録されていません。mkcert -install を実行してください");

  $state = loadState()["state"];

  if($type === "domain") {
    // ベースドメインの SSL 有効化
    $domain = strtolower(trim(requireField($input, "domain")));

    $found = false;
    foreach($state["baseDomains"] as &$bd) {
      if($bd["domain"] === $domain) { $bd["ssl"] = true; $found = true; }
    }
    unset($bd);

    if(!$found) {
      errorResponse("ドメイン '{$domain}' は登録されていません", 404);
    }
  } elseif($type === "group") {
    // グループの SSL 有効化
    $slug = strtolower(trim(requireField($input, "slug")));

    $found = false;
    foreach($state["groups"] as &$group) {
      if($group["slug"] === $slug) { $group["ssl"] = true; $found = true; }
    }
    unset($group);

    if(!$found) {
      errorResponse("グループ '{$slug}' は登録されていません", 404);
    }
  } else {
    errorResponse("type は \"domain\" または \"group\" を指定してください");
  }

  // 全 SANs を収集して証明書を生成
  $sans = collectAllSans($state);
  if(empty($sans)) {
    errorResponse("SSL が有効なドメインまたはグループがありません");
  }

  $mkcertBin = findMkcert();
  $cmd = escapeshellarg($mkcertBin) . " -cert-file " . escapeshellarg(ROUTER_HOME . "/ssl/cert.pem")
       . " -key-file " . escapeshellarg(ROUTER_HOME . "/ssl/key.pem")
       . " " . implode(" ", array_map("escapeshellarg", $sans)) . " 2>&1";

  exec($cmd, $output, $exitCode);
  if($exitCode !== 0) {
    errorResponse("証明書の発行に失敗しました: " . implode("\n", $output));
  }

  saveState($state);
  deployHttpsVhost();
  triggerGracefulRestart();
  logInfo("SSL 証明書生成: " . implode(", ", $sans));

  jsonResponse(["message" => "HTTPS を有効化しました", "sans" => $sans]);
});

// ============================================================
//  ディレクトリブラウズ（オートコンプリート用）
// ============================================================

get("/browse-dirs", function() {
  $path = $_GET["path"] ?? "";
  $showDot = ($_GET["dot"] ?? "") === "1";

  $rootBlacklist = ["System", "bin", "sbin", "usr", "etc", "private", "dev", "proc", "tmp", "Library", "cores"];
  $userHome = getUserHome();

  // パストラバーサル文字列の拒否（末尾 /.. と中間 ../ の両方を対象）
  if(str_contains($path, "../") || str_contains($path, "/..")) {
    errorResponse("パスに不正な文字列が含まれています");
  }

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
    "required" => ["rewrite" => "mod_rewrite", "headers" => "mod_headers", "vhost_alias" => "mod_vhost_alias"],
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
