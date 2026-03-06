<?php
/**
 * browse-helpers.php — ディレクトリブラウズ用ユーティリティ
 *
 * browse-dirs API（オートコンプリート）で使用するヘルパー関数。
 */

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
  $realBase = realpath($dir);
  if($realBase === false) {
    return [];
  }

  $items = @scandir($realBase);
  if($items === false) {
    return [];
  }

  $isRoot = ($realBase === "/");
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

    $fullPath = $realBase === "/" ? "/{$item}" : "{$realBase}/{$item}";
    $realFull = realpath($fullPath);

    // realpath がベースディレクトリ配下であることを確認（シンボリックリンク経由の脱出を防止）
    // 末尾スラッシュを付けて比較し、プレフィックス誤マッチを防ぐ（例: /var/www と /var/www-evil）
    if($realFull === false || !str_starts_with($realFull . "/", $realBase . "/")) {
      continue;
    }

    if(!is_dir($realFull)) {
      continue;
    }

    $dirs[] = $item;
  }

  sort($dirs, SORT_STRING | SORT_FLAG_CASE);
  return $dirs;
}
