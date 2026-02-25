<?php
/**
 * env-check.php — 環境チェック API
 *
 * GET : Apache モジュール・PHP・mkcert 等の環境状態を返す
 */

require_once __DIR__ . '/lib/store.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method Not Allowed', 405);
}

$os = detectOS();
$modules = getLoadedModules();

$checks = [];

// 必須モジュール
$requiredModules = [
    'rewrite'          => 'mod_rewrite',
    'proxy'            => 'mod_proxy',
    'proxy_http'       => 'mod_proxy_http',
    'proxy_wstunnel'   => 'mod_proxy_wstunnel',
    'headers'          => 'mod_headers',
];

foreach ($requiredModules as $key => $label) {
    $enabled = in_array($key, $modules, true);
    $checks[] = [
        'category' => 'required',
        'name'     => $label,
        'status'   => $enabled ? 'ok' : 'missing',
        'command'  => $enabled ? null : getEnableCommand($key, $os),
    ];
}

// PHP
$checks[] = [
    'category' => 'required',
    'name'     => 'PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
    'status'   => 'ok',
    'command'  => null,
];

// オプション: mod_ssl
$sslEnabled = in_array('ssl', $modules, true);
$checks[] = [
    'category' => 'optional',
    'name'     => 'mod_ssl',
    'status'   => $sslEnabled ? 'ok' : 'missing',
    'command'  => $sslEnabled ? null : getEnableCommand('ssl', $os),
];

// オプション: mkcert
$mkcertStatus = checkMkcert();
$checks[] = [
    'category' => 'optional',
    'name'     => 'mkcert',
    'status'   => $mkcertStatus['status'],
    'command'  => $mkcertStatus['command'],
];

jsonResponse([
    'os'     => $os,
    'checks' => $checks,
]);

// --- ヘルパー関数 ---

function detectOS(): string {
    if (PHP_OS_FAMILY === 'Darwin') {
        return 'macos';
    }
    if (PHP_OS_FAMILY === 'Linux') {
        if (file_exists('/proc/version')) {
            $version = file_get_contents('/proc/version');
            if (stripos($version, 'microsoft') !== false) {
                return 'wsl2';
            }
        }
        return 'linux';
    }
    return 'unknown';
}

function getLoadedModules(): array {
    $output = '';
    // apachectl -M の出力をパース
    exec('apachectl -M 2>/dev/null', $lines, $exitCode);
    if ($exitCode !== 0) {
        exec('httpd -M 2>/dev/null', $lines, $exitCode);
    }

    $modules = [];
    foreach ($lines as $line) {
        // 例: " rewrite_module (shared)" → "rewrite"
        if (preg_match('/^\s*(\w+)_module/', $line, $m)) {
            $modules[] = $m[1];
        }
    }

    return $modules;
}

function getEnableCommand(string $module, string $os): string {
    switch ($os) {
        case 'macos':
            return "httpd.conf で LoadModule {$module}_module の行をアンコメントしてください";
        case 'linux':
        case 'wsl2':
            return "sudo a2enmod {$module} && sudo systemctl restart apache2";
        default:
            return "Apache の設定で {$module} モジュールを有効化してください";
    }
}

function checkMkcert(): array {
    // mkcert がインストールされているか
    exec('which mkcert 2>/dev/null', $output, $exitCode);
    if ($exitCode !== 0) {
        $os = detectOS();
        $installCmd = match ($os) {
            'macos' => 'brew install mkcert && mkcert -install',
            'linux', 'wsl2' => 'sudo apt install mkcert && mkcert -install',
            default => 'mkcert をインストールしてください',
        };
        return [
            'status'  => 'missing',
            'command' => $installCmd,
        ];
    }

    // ローカル CA が登録されているか（CAROOT が存在するかで判定）
    exec('mkcert -CAROOT 2>/dev/null', $carootOutput, $exitCode);
    if ($exitCode === 0 && !empty($carootOutput[0])) {
        $caroot = trim($carootOutput[0]);
        if (file_exists($caroot . '/rootCA.pem')) {
            return [
                'status'  => 'ok',
                'command' => null,
            ];
        }
    }

    return [
        'status'  => 'warning',
        'command' => 'mkcert -install',
    ];
}
