<?php

use PHPUnit\Framework\TestCase;

class LoggerTest extends TestCase
{
  protected function setUp(): void
  {
    $this->cleanLogDir();
    @mkdir(LOG_DIR, 0777, true);
  }

  protected function tearDown(): void
  {
    $this->cleanLogDir();
  }

  private function cleanLogDir(): void
  {
    if(!is_dir(LOG_DIR)) return;
    foreach(glob(LOG_DIR . "/*") as $file) {
      @unlink($file);
    }
  }

  // ================================================================
  //  logInfo
  // ================================================================

  public function testLogInfoWritesToFile(): void
  {
    logInfo("テストメッセージ");

    $this->assertFileExists(LOG_FILE);
    $content = file_get_contents(LOG_FILE);
    $this->assertStringContainsString("[INFO]", $content);
    $this->assertStringContainsString("テストメッセージ", $content);
  }

  public function testLogInfoTimestampFormat(): void
  {
    logInfo("タイムスタンプテスト");

    $content = file_get_contents(LOG_FILE);
    // [YYYY-MM-DD HH:MM:SS] 形式
    $this->assertMatchesRegularExpression('/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/', $content);
  }

  public function testMultipleLogEntries(): void
  {
    logInfo("メッセージ1");
    logInfo("メッセージ2");

    $content = file_get_contents(LOG_FILE);
    $this->assertStringContainsString("メッセージ1", $content);
    $this->assertStringContainsString("メッセージ2", $content);
    // 2行あるはず
    $lines = array_filter(explode("\n", trim($content)));
    $this->assertCount(2, $lines);
  }

  // ================================================================
  //  logError
  // ================================================================

  public function testLogErrorWritesToFile(): void
  {
    logError("エラー発生");

    $content = file_get_contents(LOG_FILE);
    $this->assertStringContainsString("[ERROR]", $content);
    $this->assertStringContainsString("エラー発生", $content);
  }

  public function testLogErrorWithContext(): void
  {
    logError("処理失敗", ["exitCode" => 1, "output" => "permission denied"]);

    $content = file_get_contents(LOG_FILE);
    $this->assertStringContainsString("[ERROR]", $content);
    $this->assertStringContainsString("処理失敗", $content);
    $this->assertStringContainsString("exitCode", $content);
    $this->assertStringContainsString("permission denied", $content);
  }

  // ================================================================
  //  ログローテーション
  // ================================================================

  public function testLogRotation(): void
  {
    // LOG_MAX_SIZE 超のダミーデータを書き込み
    file_put_contents(LOG_FILE, str_repeat("x", LOG_MAX_SIZE + 1));

    // 次の書き込みでローテーション発生
    logInfo("ローテーション後");

    $this->assertFileExists(LOG_FILE . ".1");
    $this->assertFileExists(LOG_FILE);

    $newContent = file_get_contents(LOG_FILE);
    $this->assertStringContainsString("ローテーション後", $newContent);

    $oldContent = file_get_contents(LOG_FILE . ".1");
    $this->assertStringContainsString("xxx", $oldContent);
  }

  public function testLogRotationMaxGenerations(): void
  {
    // 既存の世代ファイルを作成
    file_put_contents(LOG_FILE . ".1", "gen1");
    file_put_contents(LOG_FILE . ".2", "gen2");
    file_put_contents(LOG_FILE . ".3", "gen3");

    // 超過サイズのログを作成
    file_put_contents(LOG_FILE, str_repeat("x", LOG_MAX_SIZE + 1));

    logInfo("新しいログ");

    // gen3 は削除され、gen2→.3, gen1→.2, current→.1
    $this->assertSame("gen2", file_get_contents(LOG_FILE . ".3"));
    $this->assertSame("gen1", file_get_contents(LOG_FILE . ".2"));
    $this->assertStringContainsString("xxx", file_get_contents(LOG_FILE . ".1"));
    $this->assertStringContainsString("新しいログ", file_get_contents(LOG_FILE));
  }

  public function testLogCreatesDirectoryIfMissing(): void
  {
    $this->cleanLogDir();
    @rmdir(LOG_DIR);

    logInfo("ディレクトリ自動作成テスト");

    $this->assertDirectoryExists(LOG_DIR);
    $this->assertFileExists(LOG_FILE);
  }

  // ================================================================
  //  rotateLog 直接テスト
  // ================================================================

  public function testRotateLogWithNoExistingGenerations(): void
  {
    file_put_contents(LOG_FILE, "current");

    rotateLog();

    $this->assertFileDoesNotExist(LOG_FILE);
    $this->assertFileExists(LOG_FILE . ".1");
    $this->assertSame("current", file_get_contents(LOG_FILE . ".1"));
  }
}
