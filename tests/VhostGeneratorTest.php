<?php

use PHPUnit\Framework\TestCase;

class VhostGeneratorTest extends TestCase
{
  // ================================================================
  //  generateVirtualHost
  // ================================================================

  public function testHttpDirectoryVhost(): void
  {
    $route = ["slug" => "myapp", "target" => "/var/www/myapp", "type" => "directory"];
    $lines = generateVirtualHost("myapp.example.com", $route);

    $this->assertContains("<VirtualHost *:80>", $lines);
    $this->assertContains("    ServerName myapp.example.com", $lines);
    $this->assertContains("    DocumentRoot /var/www/myapp", $lines);
    $this->assertContains("    <Directory /var/www/myapp>", $lines);
    $this->assertContains("        AllowOverride All", $lines);
    $this->assertContains("    DirectoryIndex index.php index.html index.htm", $lines);
    $this->assertContains("</VirtualHost>", $lines);
  }

  public function testHttpProxyVhost(): void
  {
    $route = ["slug" => "api", "target" => "http://localhost:3000", "type" => "proxy"];
    $lines = generateVirtualHost("api.example.com", $route);

    $this->assertContains("<VirtualHost *:80>", $lines);
    $this->assertContains("    ServerName api.example.com", $lines);
    $this->assertContains("    ProxyPreserveHost On", $lines);
    $this->assertContains("    ProxyPass / http://localhost:3000/", $lines);
    $this->assertContains("    ProxyPassReverse / http://localhost:3000/", $lines);
    // WebSocket 対応
    $this->assertContains("    RewriteCond %{HTTP:Upgrade} =websocket [NC]", $lines);
    $this->assertContains('    RewriteRule ^(.*)$ ws://localhost:3000$1 [P,L]', $lines);
  }

  public function testHttpProxyVhostWithHttps(): void
  {
    $route = ["slug" => "api", "target" => "https://localhost:8443", "type" => "proxy"];
    $lines = generateVirtualHost("api.example.com", $route);

    // HTTPS プロキシの場合は wss
    $this->assertContains('    RewriteRule ^(.*)$ wss://localhost:8443$1 [P,L]', $lines);
  }

  public function testHttpUnknownTypeReturnsEmpty(): void
  {
    $route = ["slug" => "x", "target" => "/tmp", "type" => "unknown"];
    $lines = generateVirtualHost("x.example.com", $route);

    $this->assertEmpty($lines);
  }

  // ================================================================
  //  generateVirtualHost（SSL）
  // ================================================================

  public function testHttpsDirectoryVhost(): void
  {
    $route = ["slug" => "myapp", "target" => "/var/www/myapp", "type" => "directory"];
    $lines = generateVirtualHost("myapp.example.com", $route, ssl: true);

    $this->assertContains("<VirtualHost *:443>", $lines);
    $this->assertContains("    ServerName myapp.example.com", $lines);
    $this->assertContains("    SSLEngine on", $lines);
    $this->assertContains("    SSLCertificateFile " . ROUTER_HOME . "/ssl/cert.pem", $lines);
    $this->assertContains("    DocumentRoot /var/www/myapp", $lines);
  }

  public function testHttpsProxyVhost(): void
  {
    $route = ["slug" => "api", "target" => "http://localhost:3000", "type" => "proxy"];
    $lines = generateVirtualHost("api.example.com", $route, ssl: true);

    $this->assertContains("<VirtualHost *:443>", $lines);
    $this->assertContains("    SSLEngine on", $lines);
    $this->assertContains("    ProxyPass / http://localhost:3000/", $lines);
    // HTTPS VirtualHost のプロキシは常に wss
    $this->assertContains('    RewriteRule ^(.*)$ wss://localhost:3000$1 [P,L]', $lines);
  }

  // ================================================================
  //  generateRoutesConf
  // ================================================================

  public function testGenerateRoutesConfWithMultipleDomains(): void
  {
    $state = [
      "baseDomains" => [
        ["domain" => "dev.local", "current" => true, "ssl" => false],
        ["domain" => "test.local", "current" => false, "ssl" => false],
      ],
      "groups" => [],
      "routes" => [
        ["slug" => "app", "target" => "/var/www/app", "type" => "directory"],
      ],
    ];

    $conf = generateRoutesConf($state);

    // ヘッダ
    $this->assertStringContainsString("# 自動生成", $conf);

    // ベースドメインのリダイレクト（各ドメイン）
    $this->assertStringContainsString("ServerName dev.local", $conf);
    $this->assertStringContainsString("ServerName test.local", $conf);

    // ルートの VirtualHost（各ドメインとの組み合わせ）
    $this->assertStringContainsString("ServerName app.dev.local", $conf);
    $this->assertStringContainsString("ServerName app.test.local", $conf);
  }

  // ================================================================
  //  generateRoutesSslConf
  // ================================================================

  public function testGenerateRoutesSslConfNoSslDomains(): void
  {
    $state = [
      "baseDomains" => [
        ["domain" => "dev.local", "current" => true, "ssl" => false],
      ],
      "groups" => [],
      "routes" => [
        ["slug" => "app", "target" => "/var/www/app", "type" => "directory"],
      ],
    ];

    $conf = generateRoutesSslConf($state);

    // SSL 有効ドメインがないので VirtualHost は生成されない
    $this->assertStringContainsString("# 自動生成", $conf);
    $this->assertStringNotContainsString("<VirtualHost", $conf);
  }

  public function testGenerateRoutesSslConfWithSslDomain(): void
  {
    $state = [
      "baseDomains" => [
        ["domain" => "dev.local", "current" => true, "ssl" => true],
        ["domain" => "no-ssl.local", "current" => false, "ssl" => false],
      ],
      "groups" => [],
      "routes" => [
        ["slug" => "app", "target" => "/var/www/app", "type" => "directory"],
      ],
    ];

    $conf = generateRoutesSslConf($state);

    // SSL 有効ドメインのみ VirtualHost が生成される
    $this->assertStringContainsString("ServerName app.dev.local", $conf);
    $this->assertStringNotContainsString("app.no-ssl.local", $conf);
    $this->assertStringContainsString("SSLEngine on", $conf);
  }

  // ================================================================
  //  generateWildcardVirtualHost
  // ================================================================

  public function testWildcardVhostHttp(): void
  {
    $lines = generateWildcardVirtualHost("projects", "dev.local", "/home/user/projects");

    $this->assertContains("<VirtualHost *:80>", $lines);
    $this->assertContains("    ServerAlias *.projects.dev.local", $lines);
    $this->assertContains("    VirtualDocumentRoot /home/user/projects/%1", $lines);
    $this->assertContains("    <Directory /home/user/projects>", $lines);
    $this->assertContains("        AllowOverride All", $lines);
    $this->assertContains("    DirectoryIndex index.php index.html index.htm", $lines);
    $this->assertContains("</VirtualHost>", $lines);
    // HTTP なので SSL ディレクティブなし
    $this->assertNotContains("SSLEngine on", $lines);
  }

  public function testWildcardVhostHttps(): void
  {
    $lines = generateWildcardVirtualHost("projects", "dev.local", "/home/user/projects", ssl: true);

    $this->assertContains("<VirtualHost *:443>", $lines);
    $this->assertContains("    ServerAlias *.projects.dev.local", $lines);
    $this->assertContains("    SSLEngine on", $lines);
    $this->assertContains("    SSLCertificateFile " . ROUTER_HOME . "/ssl/cert.pem", $lines);
    $this->assertContains("    VirtualDocumentRoot /home/user/projects/%1", $lines);
  }

  // ================================================================
  //  generateRoutesConf — ワイルドカードとの統合
  // ================================================================

  public function testRoutesConfIncludesWildcardVhosts(): void
  {
    $state = [
      "baseDomains" => [
        ["domain" => "dev.local", "current" => true, "ssl" => false],
      ],
      "groups" => [
        ["slug" => "projects", "path" => "/home/user/projects", "ssl" => false],
      ],
      "routes" => [
        ["slug" => "app", "target" => "/var/www/app", "type" => "directory"],
      ],
    ];

    $conf = generateRoutesConf($state);

    // 明示ルートの VirtualHost
    $this->assertStringContainsString("ServerName app.dev.local", $conf);
    // グループのワイルドカード VirtualHost
    $this->assertStringContainsString("ServerAlias *.projects.dev.local", $conf);
    $this->assertStringContainsString("VirtualDocumentRoot /home/user/projects/%1", $conf);
  }

  public function testRoutesConfExplicitBeforeWildcard(): void
  {
    $state = [
      "baseDomains" => [
        ["domain" => "dev.local", "current" => true, "ssl" => false],
      ],
      "groups" => [
        ["slug" => "projects", "path" => "/home/user/projects", "ssl" => false],
      ],
      "routes" => [
        ["slug" => "app", "target" => "/var/www/app", "type" => "directory"],
      ],
    ];

    $conf = generateRoutesConf($state);

    // 明示ルートがワイルドカードより先に記述される
    $explicitPos = strpos($conf, "ServerName app.dev.local");
    $wildcardPos = strpos($conf, "ServerAlias *.projects.dev.local");
    $this->assertNotFalse($explicitPos);
    $this->assertNotFalse($wildcardPos);
    $this->assertLessThan($wildcardPos, $explicitPos);
  }

  // ================================================================
  //  generateRoutesSslConf — グループ SSL
  // ================================================================

  public function testRoutesSslConfWithGroupSsl(): void
  {
    $state = [
      "baseDomains" => [
        ["domain" => "dev.local", "current" => true, "ssl" => false],
      ],
      "groups" => [
        ["slug" => "projects", "path" => "/home/user/projects", "ssl" => true],
      ],
      "routes" => [],
    ];

    $conf = generateRoutesSslConf($state);

    // グループ SSL のワイルドカード HTTPS VirtualHost が生成される
    $this->assertStringContainsString("ServerAlias *.projects.dev.local", $conf);
    $this->assertStringContainsString("SSLEngine on", $conf);
    $this->assertStringContainsString("VirtualDocumentRoot /home/user/projects/%1", $conf);
  }

  public function testRoutesSslConfNoSslAtAll(): void
  {
    $state = [
      "baseDomains" => [
        ["domain" => "dev.local", "current" => true, "ssl" => false],
      ],
      "groups" => [
        ["slug" => "projects", "path" => "/home/user/projects", "ssl" => false],
      ],
      "routes" => [],
    ];

    $conf = generateRoutesSslConf($state);

    // SSL 有効なドメインもグループもないので VirtualHost なし
    $this->assertStringNotContainsString("<VirtualHost", $conf);
  }
}
