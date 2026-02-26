<?php
/**
 * 環境チェック用ヘルパー
 */

function detectOS(): string {
  if(PHP_OS_FAMILY === "Darwin") return "macos";
  if(PHP_OS_FAMILY === "Linux") {
    if(file_exists("/proc/version") && stripos(file_get_contents("/proc/version"), "microsoft") !== false) {
      return "wsl2";
    }
    return "linux";
  }
  return "unknown";
}

function getLoadedModules(): array {
  if(function_exists("apache_get_modules")) {
    return array_map(fn($name) => preg_replace("/^mod_/", "", $name), apache_get_modules());
  }

  exec("apachectl -M 2>/dev/null", $lines, $exitCode);
  if($exitCode !== 0) {
    exec("httpd -M 2>/dev/null", $lines, $exitCode);
  }

  $modules = [];
  foreach($lines as $line) {
    if(preg_match("/^\s*(\w+)_module/", $line, $m)) {
      $modules[] = $m[1];
    }
  }
  return $modules;
}

function getEnableCommand(string $module, string $os): string {
  return match($os) {
    "macos"          => "httpd.conf に LoadModule {$module}_module modules/mod_{$module}.so を追加してください",
    "linux", "wsl2"  => "sudo a2enmod {$module} && sudo systemctl restart apache2",
    default          => "Apache の設定で {$module} モジュールを有効化してください",
  };
}

function checkMkcertForEnv(): array {
  $mkcert = checkMkcertStatus();
  $os = detectOS();

  if(!$mkcert["installed"]) {
    return [
      "status"  => "missing",
      "command" => match($os) {
        "macos"         => "brew install mkcert && mkcert -install",
        "linux", "wsl2" => "sudo apt install mkcert && mkcert -install",
        default         => "mkcert をインストールしてください",
      },
    ];
  }

  if(!$mkcert["caInstalled"]) {
    return ["status" => "warning", "command" => "mkcert -install"];
  }

  return ["status" => "ok", "command" => null];
}
