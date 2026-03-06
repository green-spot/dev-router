<?php

use PHPUnit\Framework\TestCase;

class StoreTest extends TestCase
{
  protected function setUp(): void
  {
    // テストごとにクリーンな状態にする
    @unlink(ROUTES_JSON);
    @unlink(ROUTES_BAK);
  }

  // ================================================================
  //  isValidDomain
  // ================================================================

  #[PHPUnit\Framework\Attributes\DataProvider('validDomainProvider')]
  public function testValidDomains(string $domain): void
  {
    $this->assertTrue(isValidDomain($domain));
  }

  public static function validDomainProvider(): array
  {
    return [
      "nip.io"                 => ["127.0.0.1.nip.io"],
      "シンプルなドメイン"     => ["example.com"],
      "サブドメイン"           => ["sub.example.com"],
      "ハイフン付き"           => ["my-domain.com"],
      "数字のみ"               => ["123.456"],
      "1文字"                  => ["a"],
    ];
  }

  #[PHPUnit\Framework\Attributes\DataProvider('invalidDomainProvider')]
  public function testInvalidDomains(string $domain): void
  {
    $this->assertFalse(isValidDomain($domain));
  }

  public static function invalidDomainProvider(): array
  {
    return [
      "空文字"                   => [""],
      "スペース"                 => ["my domain.com"],
      "スラッシュ"               => ["example.com/path"],
      "先頭ハイフン"             => ["-example.com"],
      "先頭ドット"               => [".example.com"],
      "末尾ハイフン"             => ["example.com-"],
      "末尾ドット"               => ["example.com."],
      "254文字（上限超過）"      => [str_repeat("a", 254)],
    ];
  }

  public function testDomainMaxLength(): void
  {
    // 253文字はOK
    $this->assertTrue(isValidDomain(str_repeat("a", 253)));
    // 254文字はNG
    $this->assertFalse(isValidDomain(str_repeat("a", 254)));
  }

  // ================================================================
  //  loadState
  // ================================================================

  public function testLoadStateCreatesInitialFile(): void
  {
    $result = loadState();

    $this->assertArrayHasKey("state", $result);
    $this->assertArrayHasKey("warning", $result);
    // 初期状態の警告メッセージ
    $this->assertNotNull($result["warning"]);
    // routes.json が作成されている
    $this->assertFileExists(ROUTES_JSON);
    // デフォルトのベースドメイン
    $this->assertSame("127.0.0.1.nip.io", $result["state"]["baseDomains"][0]["domain"]);
  }

  public function testLoadStateReadsExistingFile(): void
  {
    $state = [
      "baseDomains" => [["domain" => "test.local", "current" => true, "ssl" => false]],
      "groups" => [],
      "routes" => [],
    ];
    file_put_contents(ROUTES_JSON, json_encode($state));

    $result = loadState();

    $this->assertNull($result["warning"]);
    $this->assertSame("test.local", $result["state"]["baseDomains"][0]["domain"]);
  }

  public function testLoadStateRestoresFromBackup(): void
  {
    // メインファイルを破損させる
    file_put_contents(ROUTES_JSON, "invalid json{{{");

    // バックアップに正常なデータを設置
    $state = [
      "baseDomains" => [["domain" => "backup.local", "current" => true, "ssl" => false]],
      "groups" => [],
      "routes" => [],
    ];
    file_put_contents(ROUTES_BAK, json_encode($state));

    $result = loadState();

    $this->assertNotNull($result["warning"]);
    $this->assertStringContainsString("バックアップ", $result["warning"]);
    $this->assertSame("backup.local", $result["state"]["baseDomains"][0]["domain"]);
  }

  // ================================================================
  //  atomicWrite
  // ================================================================

  public function testAtomicWrite(): void
  {
    $path = ROUTER_HOME . "/data/test-atomic.txt";
    atomicWrite($path, "test content");

    $this->assertFileExists($path);
    $this->assertSame("test content", file_get_contents($path));

    @unlink($path);
  }

  // ================================================================
  //  triggerGracefulRestart — クールダウン
  // ================================================================

  public function testGracefulRestartSkipsWithoutScript(): void
  {
    $lastRestartFile = ROUTER_HOME . "/data/.last-restart";

    // graceful.sh が存在しないので何もしない
    triggerGracefulRestart();

    $this->assertFileDoesNotExist($lastRestartFile);
  }

  public function testGracefulRestartCooldown(): void
  {
    // ダミーの graceful.sh を作成
    $script = ROUTER_HOME . "/bin/graceful.sh";
    file_put_contents($script, "#!/bin/sh\nexit 0\n");
    chmod($script, 0755);

    $lastRestartFile = ROUTER_HOME . "/data/.last-restart";
    $pendingFile     = ROUTER_HOME . "/data/.restart-pending";

    // 1回目: タイムスタンプが記録される
    triggerGracefulRestart();
    $this->assertFileExists($lastRestartFile);

    // 2回目（クールダウン内）: pending フラグが設定される
    triggerGracefulRestart();
    $this->assertFileExists($pendingFile);

    // クリーンアップ
    @unlink($script);
    @unlink($lastRestartFile);
    @unlink($pendingFile);
  }

  // ================================================================
  //  isValidGroupSlug
  // ================================================================

  #[PHPUnit\Framework\Attributes\DataProvider('validSlugProvider')]
  public function testValidGroupSlugs(string $slug): void
  {
    $this->assertTrue(isValidGroupSlug($slug));
  }

  public static function validSlugProvider(): array
  {
    return [
      "英小文字"       => ["projects"],
      "数字のみ"       => ["123"],
      "ハイフン付き"   => ["my-app"],
      "1文字"          => ["a"],
      "英数字混合"     => ["app1"],
    ];
  }

  #[PHPUnit\Framework\Attributes\DataProvider('invalidSlugProvider')]
  public function testInvalidGroupSlugs(string $slug): void
  {
    $this->assertFalse(isValidGroupSlug($slug));
  }

  public static function invalidSlugProvider(): array
  {
    return [
      "空文字"           => [""],
      "大文字"           => ["MyApp"],
      "先頭ハイフン"     => ["-app"],
      "末尾ハイフン"     => ["app-"],
      "ドット含む"       => ["my.app"],
      "スラッシュ含む"   => ["my/app"],
      "スペース含む"     => ["my app"],
      "アンダースコア"   => ["my_app"],
      "64文字（上限超過）" => [str_repeat("a", 64)],
    ];
  }

  public function testSlugMaxLength(): void
  {
    $this->assertTrue(isValidGroupSlug(str_repeat("a", 63)));
    $this->assertFalse(isValidGroupSlug(str_repeat("a", 64)));
  }

  // ================================================================
  //  migrateState
  // ================================================================

  public function testMigrateStateAddsSlugAndSsl(): void
  {
    $state = [
      "baseDomains" => [["domain" => "test.local", "current" => true, "ssl" => false]],
      "groups" => [
        ["path" => "/home/user/projects"],
        ["path" => "/home/user/my-apps"],
      ],
      "routes" => [],
    ];

    $result = migrateState($state);

    $this->assertTrue($result["migrated"]);
    $this->assertSame("projects", $result["state"]["groups"][0]["slug"]);
    $this->assertFalse($result["state"]["groups"][0]["ssl"]);
    $this->assertSame("", $result["state"]["groups"][0]["label"]);
    $this->assertSame("my-apps", $result["state"]["groups"][1]["slug"]);
    $this->assertFalse($result["state"]["groups"][1]["ssl"]);
    $this->assertSame("", $result["state"]["groups"][1]["label"]);
  }

  public function testMigrateStateAddsLabelToGroupsAndRoutes(): void
  {
    $state = [
      "baseDomains" => [["domain" => "test.local", "current" => true, "ssl" => false]],
      "groups" => [
        ["slug" => "proj", "path" => "/home/user/projects", "ssl" => false],
      ],
      "routes" => [
        ["slug" => "app", "target" => "/tmp", "type" => "directory"],
      ],
    ];

    $result = migrateState($state);

    $this->assertTrue($result["migrated"]);
    $this->assertSame("", $result["state"]["groups"][0]["label"]);
    $this->assertSame("", $result["state"]["routes"][0]["label"]);
  }

  public function testMigrateStatePreservesExistingFields(): void
  {
    $state = [
      "baseDomains" => [["domain" => "test.local", "current" => true, "ssl" => false]],
      "groups" => [
        ["slug" => "proj", "path" => "/home/user/projects", "ssl" => true, "label" => "プロジェクト"],
      ],
      "routes" => [
        ["slug" => "app", "target" => "/tmp", "type" => "directory", "label" => "メインアプリ"],
      ],
    ];

    $result = migrateState($state);

    $this->assertFalse($result["migrated"]);
    $this->assertSame("proj", $result["state"]["groups"][0]["slug"]);
    $this->assertTrue($result["state"]["groups"][0]["ssl"]);
    $this->assertSame("プロジェクト", $result["state"]["groups"][0]["label"]);
    $this->assertSame("メインアプリ", $result["state"]["routes"][0]["label"]);
  }

  public function testMigrateStateEmptyGroups(): void
  {
    $state = getEmptyState();

    $result = migrateState($state);

    $this->assertFalse($result["migrated"]);
    $this->assertEmpty($result["state"]["groups"]);
  }

  public function testLoadStateMigratesOldFormat(): void
  {
    $state = [
      "baseDomains" => [["domain" => "test.local", "current" => true, "ssl" => false]],
      "groups" => [["path" => "/tmp/test-group"]],
      "routes" => [],
    ];
    file_put_contents(ROUTES_JSON, json_encode($state));

    // テスト用のディレクトリを確保
    @mkdir("/tmp/test-group", 0755, true);

    $result = loadState();

    $this->assertNull($result["warning"]);
    // マイグレーション済み: slug が付与されている
    $this->assertSame("test-group", $result["state"]["groups"][0]["slug"]);
    $this->assertFalse($result["state"]["groups"][0]["ssl"]);
  }

  // ================================================================
  //  triggerGracefulRestart — クールダウン
  // ================================================================

  public function testGracefulRestartAfterCooldown(): void
  {
    $script = ROUTER_HOME . "/bin/graceful.sh";
    file_put_contents($script, "#!/bin/sh\nexit 0\n");
    chmod($script, 0755);

    $lastRestartFile = ROUTER_HOME . "/data/.last-restart";
    $pendingFile     = ROUTER_HOME . "/data/.restart-pending";

    // クールダウン経過済みのタイムスタンプを書き込み
    file_put_contents($lastRestartFile, (string)(microtime(true) - 10));
    touch($pendingFile);

    // クールダウン経過後の呼び出し: 実行され、pending がクリアされる
    triggerGracefulRestart();
    $this->assertFileDoesNotExist($pendingFile);

    // タイムスタンプが更新されている
    $newTime = (float) file_get_contents($lastRestartFile);
    $this->assertGreaterThan(microtime(true) - 2, $newTime);

    // クリーンアップ
    @unlink($script);
    @unlink($lastRestartFile);
  }
}
