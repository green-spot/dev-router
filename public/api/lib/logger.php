<?php
/**
 * logger.php — ファイルベースのログ機構
 *
 * ログファイル: data/logs/dev-router.log
 * ローテーション: 1MB 超過で .1 にリネーム（最大3世代）
 */

if(!defined("LOG_DIR"))             define("LOG_DIR", ROUTER_HOME . "/data/logs");
if(!defined("LOG_FILE"))            define("LOG_FILE", LOG_DIR . "/dev-router.log");
if(!defined("LOG_MAX_SIZE"))        define("LOG_MAX_SIZE", 1024 * 1024); // 1MB
if(!defined("LOG_MAX_GENERATIONS")) define("LOG_MAX_GENERATIONS", 3);

/**
 * 情報ログを記録する
 */
function logInfo(string $message): void {
  writeLog("INFO", $message);
}

/**
 * エラーログを記録する
 */
function logError(string $message, array $context = []): void {
  $line = $message;
  if(!empty($context)) {
    $line .= " " . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }
  writeLog("ERROR", $line);
}

/**
 * ログ行を書き込む
 */
function writeLog(string $level, string $message): void {
  if(!is_dir(LOG_DIR)) {
    @mkdir(LOG_DIR, 0750, true);
  }

  $size = file_exists(LOG_FILE) ? filesize(LOG_FILE) : 0;
  if($size !== false && $size > LOG_MAX_SIZE) {
    rotateLog();
  }

  $timestamp = date("Y-m-d H:i:s");
  $line = "[{$timestamp}] [{$level}] {$message}\n";
  @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

/**
 * ログファイルをローテーションする
 *
 * dev-router.log → .1 → .2 → .3（削除）
 */
function rotateLog(): void {
  for($i = LOG_MAX_GENERATIONS; $i >= 1; $i--) {
    $file = LOG_FILE . "." . $i;
    if($i === LOG_MAX_GENERATIONS) {
      @unlink($file);
    } else if(file_exists($file)) {
      rename($file, LOG_FILE . "." . ($i + 1));
    }
  }
  if(file_exists(LOG_FILE)) {
    rename(LOG_FILE, LOG_FILE . ".1");
  }
}
