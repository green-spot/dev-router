<?php
/**
 * テスト用ブートストラップ
 *
 * ROUTER_HOME をテスト用一時ディレクトリに設定してからライブラリを読み込む。
 * 実データに影響しない。
 */

// テスト用の ROUTER_HOME を定義（実際のデータに影響しないようにする）
define("ROUTER_HOME", sys_get_temp_dir() . "/dev-router-test-" . getmypid());

// テスト用ディレクトリを作成
@mkdir(ROUTER_HOME . "/data", 0777, true);
@mkdir(ROUTER_HOME . "/conf", 0777, true);
@mkdir(ROUTER_HOME . "/ssl", 0777, true);
@mkdir(ROUTER_HOME . "/bin", 0777, true);

// ライブラリ読み込み（ROUTER_HOME は既に定義済みなので store.php の define はスキップされる）
require_once __DIR__ . "/../public/api/lib/store.php";

/**
 * テスト終了時のクリーンアップ
 */
register_shutdown_function(function(): void {
  $dir = ROUTER_HOME;
  if(!is_dir($dir)) return;

  $files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
  );
  foreach($files as $file) {
    if($file->isDir()) {
      rmdir($file->getRealPath());
    } else {
      unlink($file->getRealPath());
    }
  }
  rmdir($dir);
});
