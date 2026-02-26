<?php
/**
 * ssl.php — SSL 管理 API
 *
 * GET  : SSL 状態（mkcert インストール・CA 登録・各ドメインの SSL 状態）
 * POST : HTTPS 有効化フロー
 */

require_once __DIR__ . '/lib/store.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleGet();
        break;
    case 'POST':
        handlePost();
        break;
    default:
        errorResponse('Method Not Allowed', 405);
}

function handleGet(): void {
    $result = loadState();
    $state = $result['state'];

    $mkcert = checkMkcertStatus();
    $domains = array_map(fn($bd) => [
        'domain' => $bd['domain'],
        'ssl'    => $bd['ssl'],
    ], $state['baseDomains']);

    $certExists = file_exists(ROUTER_HOME . '/ssl/cert.pem')
               && file_exists(ROUTER_HOME . '/ssl/key.pem');

    jsonResponse([
        'mkcert'     => $mkcert,
        'certExists' => $certExists,
        'domains'    => $domains,
    ]);
}

function handlePost(): void {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['domain'])) {
        errorResponse('domain は必須です');
    }

    $domain = strtolower(trim($input['domain']));

    // mkcert チェック
    $mkcert = checkMkcertStatus();
    if ($mkcert['installed'] !== true) {
        errorResponse('mkcert がインストールされていません');
    }
    if ($mkcert['caInstalled'] !== true) {
        errorResponse('mkcert のローカル CA が登録されていません。mkcert -install を実行してください');
    }

    // 1. routes.json の該当ベースドメインを ssl: true に更新
    $result = loadState();
    $state = $result['state'];

    $found = false;
    foreach ($state['baseDomains'] as &$bd) {
        if ($bd['domain'] === $domain) {
            $bd['ssl'] = true;
            $found = true;
        }
    }
    unset($bd);

    if (!$found) {
        errorResponse("ドメイン '{$domain}' は登録されていません", 404);
    }

    saveState($state);

    // 2. 全ベースドメイン（ssl: true）の SAN 一覧を構築
    $sans = [];
    foreach ($state['baseDomains'] as $bd) {
        if ($bd['ssl']) {
            $sans[] = '*.'. $bd['domain'];
        }
    }

    if (empty($sans)) {
        errorResponse('SSL が有効なベースドメインがありません');
    }

    // 3. mkcert で証明書発行
    $certPath = ROUTER_HOME . '/ssl/cert.pem';
    $keyPath = ROUTER_HOME . '/ssl/key.pem';
    $sanArgs = implode(' ', array_map('escapeshellarg', $sans));

    $cmd = "mkcert -cert-file " . escapeshellarg($certPath)
         . " -key-file " . escapeshellarg($keyPath)
         . " " . $sanArgs . " 2>&1";

    exec($cmd, $output, $exitCode);

    if ($exitCode !== 0) {
        errorResponse('証明書の発行に失敗しました: ' . implode("\n", $output));
    }

    // 4. HTTPS VirtualHost 設定を生成（初回のみ）
    $httpsConfigDeployed = deployHttpsVhost();

    // 5. Apache graceful restart
    triggerGracefulRestart();

    jsonResponse([
        'message'       => 'HTTPS を有効化しました',
        'sans'          => $sans,
        'httpsDeployed' => $httpsConfigDeployed,
    ]);
}

function checkMkcertStatus(): array {
    exec('which mkcert 2>/dev/null', $output, $exitCode);
    $installed = ($exitCode === 0);

    $caInstalled = false;
    if ($installed) {
        exec('mkcert -CAROOT 2>/dev/null', $carootOutput, $carootExit);
        if ($carootExit === 0 && !empty($carootOutput[0])) {
            $caroot = trim($carootOutput[0]);
            $caInstalled = file_exists($caroot . '/rootCA.pem');
        }
    }

    return [
        'installed'   => $installed,
        'caInstalled' => $caInstalled,
    ];
}

/**
 * HTTPS VirtualHost 設定をデプロイする（初回のみ）
 *
 * @return bool デプロイが行われたか
 */
function deployHttpsVhost(): bool {
    $os = PHP_OS_FAMILY === 'Darwin' ? 'macos' : 'linux';
    $templatePath = ROUTER_HOME . '/conf/vhost-https.conf.template';

    if (!file_exists($templatePath)) {
        return false;
    }

    $config = file_get_contents($templatePath);
    $config = str_replace('${ROUTER_HOME}', ROUTER_HOME, $config);

    if ($os === 'macos') {
        // Homebrew Apache
        $candidates = [
            '/opt/homebrew/etc/httpd/extra/dev-router-ssl.conf',
            '/usr/local/etc/httpd/extra/dev-router-ssl.conf',
        ];
        foreach ($candidates as $target) {
            $dir = dirname($target);
            if (is_dir($dir)) {
                if (file_exists($target)) {
                    // 上書き（SAN 変更時の証明書再発行を反映）
                    file_put_contents($target, $config);
                    return false; // 既にデプロイ済み
                }
                file_put_contents($target, $config);
                // httpd.conf に Include を追加
                $httpdConf = dirname($dir) . '/httpd.conf';
                if (file_exists($httpdConf) && !str_contains(file_get_contents($httpdConf), 'dev-router-ssl.conf')) {
                    file_put_contents($httpdConf,
                        "\n# DevRouter SSL\nInclude {$target}\n",
                        FILE_APPEND
                    );
                }
                return true;
            }
        }
    } else {
        // Debian/Ubuntu 系
        if (is_dir('/etc/apache2/sites-available')) {
            $target = '/etc/apache2/sites-available/dev-router-ssl.conf';
            $isNew = !file_exists($target);
            file_put_contents($target, $config);
            if ($isNew) {
                exec('a2ensite dev-router-ssl.conf 2>/dev/null');
            }
            return $isNew;
        }
        // RHEL/CentOS 系
        if (is_dir('/etc/httpd/conf.d')) {
            $target = '/etc/httpd/conf.d/dev-router-ssl.conf';
            $isNew = !file_exists($target);
            file_put_contents($target, $config);
            return $isNew;
        }
    }

    return false;
}
