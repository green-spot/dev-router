<?php
/**
 * SSL 管理用ヘルパー
 */

function findMkcert(): ?string {
  // Apache の PHP 環境では PATH が制限されているため、一般的なパスを補完して検索する
  $basePath = getenv("PATH") ?: "/usr/bin:/bin";
  $extraDirs = "/opt/homebrew/bin:/usr/local/bin:/usr/local/go/bin";
  $fullPath = "{$extraDirs}:{$basePath}";

  exec("PATH=" . escapeshellarg($fullPath) . " which mkcert 2>/dev/null", $output, $exitCode);
  if($exitCode === 0 && !empty($output[0])) {
    return trim($output[0]);
  }

  return null;
}

function checkMkcertStatus(): array {
  $mkcertPath = findMkcert();
  $installed = ($mkcertPath !== null);

  $caInstalled = false;
  if($installed) {
    exec(escapeshellarg($mkcertPath) . " -CAROOT 2>/dev/null", $carootOutput, $carootExit);
    if($carootExit === 0 && !empty($carootOutput[0])) {
      $caroot = trim($carootOutput[0]);
      // パストラバーサル防止: 絶対パスかつ実在ディレクトリのみ許可
      if(str_starts_with($caroot, "/") && is_dir($caroot)) {
        $caInstalled = file_exists($caroot . "/rootCA.pem");
      }
    }
  }

  return ["installed" => $installed, "caInstalled" => $caInstalled];
}

/**
 * 全 SANs（Subject Alternative Names）を収集する。
 *
 * - baseDomains.ssl=true → *.basedomain（明示ルート用）
 * - groups.ssl=true → *.group.basedomain（グループ用、全ドメイン分）
 *
 * @param array $state routes.json の状態
 * @return array SANs の配列
 */
function collectAllSans(array $state): array {
  $sans = [];

  foreach($state["baseDomains"] as $bd) {
    if(!empty($bd["ssl"])) {
      $sans[] = "*." . $bd["domain"];
    }
  }

  $allDomains = array_column($state["baseDomains"], "domain");
  foreach($state["groups"] as $group) {
    if(!empty($group["ssl"])) {
      foreach($allDomains as $domain) {
        $sans[] = "*.{$group["slug"]}.{$domain}";
      }
    }
  }

  return $sans;
}

function deployHttpsVhost(): bool {
  $templatePath = ROUTER_HOME . "/conf/vhost-https.conf.template";
  $targetPath   = ROUTER_HOME . "/conf/vhost-https.conf";

  if(!file_exists($templatePath)) {
    return false;
  }

  $template = file_get_contents($templatePath);
  if($template === false) {
    return false;
  }

  $config = str_replace('${ROUTER_HOME}', ROUTER_HOME, $template);
  return file_put_contents($targetPath, $config) !== false;
}
