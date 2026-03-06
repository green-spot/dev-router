<?php

use PHPUnit\Framework\TestCase;

class RouteResolverTest extends TestCase
{
  private string $testDir;

  protected function setUp(): void
  {
    $this->testDir = sys_get_temp_dir() . "/route-resolver-test-" . getmypid();
    @mkdir($this->testDir . "/group-a", 0777, true);
    @mkdir($this->testDir . "/group-b", 0777, true);
  }

  protected function tearDown(): void
  {
    $this->removeDir($this->testDir);
  }

  private function removeDir(string $dir): void
  {
    if(!is_dir($dir)) return;
    $files = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
      RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach($files as $file) {
      $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
    }
    rmdir($dir);
  }

  // ================================================================
  //  resolveAllRoutes（明示ルートのみ）
  // ================================================================

  public function testExplicitRoutesOnly(): void
  {
    $state = [
      "routes" => [
        ["slug" => "app", "target" => "/var/www/app", "type" => "directory"],
        ["slug" => "api", "target" => "http://localhost:3000", "type" => "proxy"],
      ],
      "groups" => [],
    ];

    $resolved = resolveAllRoutes($state);

    $this->assertCount(2, $resolved);
    $this->assertSame("app", $resolved[0]["slug"]);
    $this->assertSame("directory", $resolved[0]["type"]);
    $this->assertSame("api", $resolved[1]["slug"]);
    $this->assertSame("proxy", $resolved[1]["type"]);
  }

  public function testResolveAllRoutesIgnoresGroups(): void
  {
    $state = [
      "routes" => [
        ["slug" => "app", "target" => "/var/www/app", "type" => "directory"],
      ],
      "groups" => [
        ["slug" => "projects", "path" => $this->testDir . "/group-a", "ssl" => false],
      ],
    ];

    $resolved = resolveAllRoutes($state);

    // グループはワイルドカード VirtualHost で処理されるため、結果に含まれない
    $this->assertCount(1, $resolved);
    $this->assertSame("app", $resolved[0]["slug"]);
  }

  public function testResolveAllRoutesEmptyState(): void
  {
    $state = [
      "routes" => [],
      "groups" => [],
    ];

    $resolved = resolveAllRoutes($state);

    $this->assertEmpty($resolved);
  }

  // ================================================================
  //  buildGroupsInfo
  // ================================================================

  public function testBuildGroupsInfoBasic(): void
  {
    $state = [
      "routes" => [],
      "groups" => [
        ["slug" => "projects", "path" => $this->testDir . "/group-a", "ssl" => false, "label" => "プロジェクト"],
      ],
    ];

    $info = buildGroupsInfo($state);

    $this->assertCount(1, $info);
    $this->assertSame("projects", $info[0]["slug"]);
    $this->assertSame($this->testDir . "/group-a", $info[0]["path"]);
    $this->assertFalse($info[0]["ssl"]);
    $this->assertSame("プロジェクト", $info[0]["label"]);
    $this->assertTrue($info[0]["exists"]);
    $this->assertIsArray($info[0]["subdirs"]);
  }

  public function testBuildGroupsInfoNonExistentPath(): void
  {
    $state = [
      "routes" => [],
      "groups" => [
        ["slug" => "missing", "path" => "/nonexistent-" . uniqid(), "ssl" => false],
      ],
    ];

    $info = buildGroupsInfo($state);

    $this->assertCount(1, $info);
    $this->assertFalse($info[0]["exists"]);
    $this->assertSame("", $info[0]["label"]);
    $this->assertEmpty($info[0]["subdirs"]);
  }

  public function testBuildGroupsInfoMultipleGroups(): void
  {
    $state = [
      "routes" => [],
      "groups" => [
        ["slug" => "projects", "path" => $this->testDir . "/group-a", "ssl" => false],
        ["slug" => "apps", "path" => $this->testDir . "/group-b", "ssl" => true],
      ],
    ];

    $info = buildGroupsInfo($state);

    $this->assertCount(2, $info);
    $this->assertSame("projects", $info[0]["slug"]);
    $this->assertFalse($info[0]["ssl"]);
    $this->assertSame("apps", $info[1]["slug"]);
    $this->assertTrue($info[1]["ssl"]);
  }

  public function testBuildGroupsInfoSubdirs(): void
  {
    // group-a 配下にサブディレクトリを作成
    @mkdir($this->testDir . "/group-a/site-x", 0777);
    @mkdir($this->testDir . "/group-a/site-y", 0777);
    @mkdir($this->testDir . "/group-a/.hidden", 0777);
    // ファイルは除外される
    file_put_contents($this->testDir . "/group-a/readme.txt", "test");

    $state = [
      "routes" => [],
      "groups" => [
        ["slug" => "projects", "path" => $this->testDir . "/group-a", "ssl" => false, "label" => ""],
      ],
    ];

    $info = buildGroupsInfo($state);

    $this->assertSame(["site-x", "site-y"], $info[0]["subdirs"]);
  }
}
