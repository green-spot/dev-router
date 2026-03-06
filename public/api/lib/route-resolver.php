<?php
/**
 * route-resolver.php — ルート解決
 *
 * 明示登録ルートの解決とグループ情報の構築を行う。
 * グループはワイルドカード VirtualHost で Apache が動的解決するため、
 * スキャンや衝突検出は不要。
 */

/**
 * 明示登録ルートを返す。
 * グループはワイルドカード VirtualHost で処理されるため、ここでは対象外。
 *
 * @param array $state
 * @return array [['slug' => string, 'target' => string, 'type' => string], ...]
 */
function resolveAllRoutes(array $state): array {
  $resolved = [];

  foreach($state["routes"] as $route) {
    $resolved[] = [
      "slug"   => $route["slug"],
      "target" => $route["target"],
      "type"   => $route["type"],
    ];
  }

  return $resolved;
}

/**
 * グループ情報を構築する。
 *
 * @param array $state routes.json の状態
 * @return array グループ情報の配列
 */
function buildGroupsInfo(array $state): array {
  $groupsInfo = [];

  foreach($state["groups"] as $group) {
    $path = $group["path"];
    $exists = is_dir($path);

    // サブディレクトリ一覧（ドットディレクトリは除外）
    $subdirs = [];
    if($exists) {
      $items = @scandir($path);
      if($items !== false) {
        foreach($items as $item) {
          if($item === "." || $item === ".." || str_starts_with($item, ".")) continue;
          if(is_dir($path . "/" . $item)) {
            $subdirs[] = $item;
          }
        }
        sort($subdirs, SORT_STRING | SORT_FLAG_CASE);
      }
    }

    $groupsInfo[] = [
      "slug"    => $group["slug"],
      "path"    => $path,
      "ssl"     => $group["ssl"],
      "label"   => $group["label"] ?? "",
      "exists"  => $exists,
      "subdirs" => $subdirs,
    ];
  }

  return $groupsInfo;
}
