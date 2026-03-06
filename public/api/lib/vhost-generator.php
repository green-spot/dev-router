<?php
/**
 * vhost-generator.php — VirtualHost 設定生成
 *
 * routes.json の状態から Apache VirtualHost 設定ファイルの内容を生成する。
 */

require_once __DIR__ . "/route-resolver.php";

/**
 * routes.json の内容から routes.conf（HTTP VirtualHost）を生成する。
 *
 * 生成順序:
 * 1. ベースドメイン直アクセス → リダイレクト VirtualHost（管理UIへ 302）
 * 2. 明示登録ルート × 全ベースドメイン の個別 VirtualHost（ワイルドカードより先=優先）
 * 3. グループ × 全ベースドメイン のワイルドカード VirtualHost
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
    $lines = array_merge($lines, generateRedirectVirtualHost($domain));
    $lines[] = "";
  }

  // 2. 明示登録ルート（個別 VirtualHost、ワイルドカードより先=優先）
  foreach($resolvedRoutes as $route) {
    foreach($domains as $domain) {
      $serverName = "{$route["slug"]}.{$domain}";
      $lines = array_merge($lines, generateVirtualHost($serverName, $route));
      $lines[] = "";
    }
  }

  // 3. グループのワイルドカード VirtualHost
  foreach($state["groups"] as $group) {
    foreach($domains as $domain) {
      $lines = array_merge(
        $lines,
        generateWildcardVirtualHost($group["slug"], $domain, $group["path"])
      );
      $lines[] = "";
    }
  }

  return implode("\n", $lines);
}

/**
 * routes.json の内容から routes-ssl.conf（HTTPS VirtualHost）を生成する。
 *
 * - baseDomains.ssl=true のドメインに対して: リダイレクト + 明示ルートの HTTPS VirtualHost
 * - groups.ssl=true のグループに対して: 全ドメインでワイルドカード HTTPS VirtualHost
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

  // SSL が有効なグループを抽出
  $sslGroups = [];
  foreach($state["groups"] as $group) {
    if(!empty($group["ssl"])) {
      $sslGroups[] = $group;
    }
  }

  // SSL 有効なドメインもグループもない場合は空ファイル
  if(empty($sslDomains) && empty($sslGroups)) {
    return implode("\n", $lines);
  }

  // baseDomains.ssl=true に対して: リダイレクト + 明示ルート
  if(!empty($sslDomains)) {
    $resolvedRoutes = resolveAllRoutes($state);

    foreach($sslDomains as $domain) {
      $lines = array_merge($lines, generateRedirectVirtualHost($domain, ssl: true));
      $lines[] = "";
    }

    foreach($resolvedRoutes as $route) {
      foreach($sslDomains as $domain) {
        $serverName = "{$route["slug"]}.{$domain}";
        $lines = array_merge($lines, generateVirtualHost($serverName, $route, ssl: true));
        $lines[] = "";
      }
    }
  }

  // groups.ssl=true に対して: 全ドメインでワイルドカード HTTPS VirtualHost
  $allDomains = array_column($state["baseDomains"], "domain");
  foreach($sslGroups as $group) {
    foreach($allDomains as $domain) {
      $lines = array_merge(
        $lines,
        generateWildcardVirtualHost($group["slug"], $domain, $group["path"], ssl: true)
      );
      $lines[] = "";
    }
  }

  return implode("\n", $lines);
}

/**
 * ベースドメイン → 管理UIへのリダイレクト VirtualHost を生成する。
 *
 * @param string $domain ベースドメイン名
 * @param bool $ssl true なら HTTPS
 * @return array Apache 設定の行配列
 */
function generateRedirectVirtualHost(string $domain, bool $ssl = false): array {
  $vhostPort = $ssl ? 443 : 80;
  $proto = $ssl ? "https" : "http";
  $sslLines = $ssl ? [
    "    SSLEngine on",
    "    SSLCertificateFile " . ROUTER_HOME . "/ssl/cert.pem",
    "    SSLCertificateKeyFile " . ROUTER_HOME . "/ssl/key.pem",
  ] : [];

  return [
    "# --- ベースドメイン → 管理UIへリダイレクト ---",
    "<VirtualHost *:{$vhostPort}>",
    "    ServerName {$domain}",
    ...$sslLines,
    "    RewriteEngine On",
    "    RewriteRule ^ {$proto}://localhost [R=302,L]",
    "</VirtualHost>",
  ];
}

/**
 * VirtualHost のディレクティブ行を生成する。
 *
 * @param string $serverName ServerName に設定する値
 * @param array $route ['slug', 'target', 'type']
 * @param bool $ssl true なら HTTPS（ポート 443 + SSL ディレクティブ）
 * @return array Apache 設定の行配列
 */
function generateVirtualHost(string $serverName, array $route, bool $ssl = false): array {
  $type = $route["type"];
  $target = $route["target"];
  $vhostPort = $ssl ? 443 : 80;
  $proto = $ssl ? "https" : "http";
  $sslLines = $ssl ? [
    "    SSLEngine on",
    "    SSLCertificateFile " . ROUTER_HOME . "/ssl/cert.pem",
    "    SSLCertificateKeyFile " . ROUTER_HOME . "/ssl/key.pem",
  ] : [];

  if($type === "directory") {
    return [
      "# --- ディレクトリ公開 ---",
      "<VirtualHost *:{$vhostPort}>",
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
    $backendPort = $parsed["port"] ?? ($parsed["scheme"] === "https" ? 443 : 80);
    $host = $parsed["host"] ?? "localhost";
    $wsScheme = ($ssl || $parsed["scheme"] === "https") ? "wss" : "ws";
    $wsTarget = "{$wsScheme}://{$host}:{$backendPort}";

    return [
      "# --- リバースプロキシ（WebSocket 対応）---",
      "<IfModule mod_proxy.c>",
      "<VirtualHost *:{$vhostPort}>",
      "    ServerName {$serverName}",
      ...$sslLines,
      "    ProxyPreserveHost On",
      "    RewriteEngine On",
      "    RewriteCond %{HTTP:Upgrade} =websocket [NC]",
      "    RewriteRule ^(.*)\$ {$wsTarget}\$1 [P,L]",
      "    ProxyPass / {$target}/",
      "    ProxyPassReverse / {$target}/",
      "    RequestHeader set X-Forwarded-Proto \"{$proto}\"",
      "</VirtualHost>",
      "</IfModule>",
    ];
  }

  // 不明な type はスキップ
  return [];
}

/**
 * グループ用ワイルドカード VirtualHost を生成する。
 * Apache の VirtualDocumentRoot でサブドメインからディレクトリを動的解決する。
 *
 * @param string $groupSlug グループスラグ
 * @param string $domain ベースドメイン名
 * @param string $groupPath グループのディレクトリパス
 * @param bool $ssl true なら HTTPS
 * @return array Apache 設定の行配列
 */
function generateWildcardVirtualHost(string $groupSlug, string $domain, string $groupPath, bool $ssl = false): array {
  $vhostPort = $ssl ? 443 : 80;
  $serverAlias = "*.{$groupSlug}.{$domain}";
  $sslLines = $ssl ? [
    "    SSLEngine on",
    "    SSLCertificateFile " . ROUTER_HOME . "/ssl/cert.pem",
    "    SSLCertificateKeyFile " . ROUTER_HOME . "/ssl/key.pem",
  ] : [];

  return [
    "# --- グループ \"{$groupSlug}\" ワイルドカード VirtualHost ---",
    "<VirtualHost *:{$vhostPort}>",
    "    ServerAlias {$serverAlias}",
    ...$sslLines,
    "    VirtualDocumentRoot {$groupPath}/%1",
    "    <Directory {$groupPath}>",
    "        Options FollowSymLinks Indexes",
    "        AllowOverride All",
    "        Require all granted",
    "    </Directory>",
    "    DirectoryIndex index.php index.html index.htm",
    "</VirtualHost>",
  ];
}
