<?php
/**
 * SSL 管理用ヘルパー
 */

function checkMkcertStatus(): array {
  exec("which mkcert 2>/dev/null", $output, $exitCode);
  $installed = ($exitCode === 0);

  $caInstalled = false;
  if($installed) {
    exec("mkcert -CAROOT 2>/dev/null", $carootOutput, $carootExit);
    if($carootExit === 0 && !empty($carootOutput[0])) {
      $caInstalled = file_exists(trim($carootOutput[0]) . "/rootCA.pem");
    }
  }

  return ["installed" => $installed, "caInstalled" => $caInstalled];
}

function deployHttpsVhost(): bool {
  $os = PHP_OS_FAMILY === "Darwin" ? "macos" : "linux";
  $templatePath = ROUTER_HOME . "/conf/vhost-https.conf.template";

  if(!file_exists($templatePath)) {
    return false;
  }

  $config = str_replace("\${ROUTER_HOME}", ROUTER_HOME, file_get_contents($templatePath));

  if($os === "macos") {
    foreach(["/opt/homebrew/etc/httpd/extra", "/usr/local/etc/httpd/extra"] as $dir) {
      if(!is_dir($dir)) continue;
      $target = "{$dir}/dev-router-ssl.conf";
      $isNew = !file_exists($target);
      file_put_contents($target, $config);
      if($isNew) {
        $httpdConf = dirname($dir) . "/httpd.conf";
        if(file_exists($httpdConf) && !str_contains(file_get_contents($httpdConf), "dev-router-ssl.conf")) {
          file_put_contents($httpdConf, "\n# DevRouter SSL\nInclude {$target}\n", FILE_APPEND);
        }
      }
      return $isNew;
    }
  } else {
    $targets = [
      "/etc/apache2/sites-available/dev-router-ssl.conf",
      "/etc/httpd/conf.d/dev-router-ssl.conf",
    ];
    foreach($targets as $target) {
      if(!is_dir(dirname($target))) continue;
      $isNew = !file_exists($target);
      file_put_contents($target, $config);
      if($isNew && str_contains($target, "sites-available")) {
        exec("a2ensite dev-router-ssl.conf 2>/dev/null");
      }
      return $isNew;
    }
  }

  return false;
}
