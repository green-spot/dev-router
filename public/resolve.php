<?php
/**
 * resolve.php — 未登録サブドメインの自動解決
 *
 * routing.map にマッチしないサブドメインへのアクセス時に Apache から呼び出される。
 * グループディレクトリを再スキャンし、該当スラグが見つかればリダイレクト、
 * 見つからなければ 404 を返す。
 */

require_once __DIR__ . '/api/lib/store.php';

// リクエストのホスト名を取得（小文字化）
$host = strtolower($_SERVER['HTTP_HOST'] ?? '');

// routing.map を再生成（グループディレクトリの再スキャン）
$result = loadState();
saveState($result['state']);

// 再生成後の routing.map にこのホストが存在するか確認
$mapContent = file_get_contents(ROUTING_MAP);
$found = false;

foreach (explode("\n", $mapContent) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') {
        continue;
    }
    $parts = preg_split('/\s+/', $line, 2);
    if (count($parts) === 2 && $parts[0] === $host) {
        $found = true;
        break;
    }
}

if ($found) {
    // 同じ URL へ 302 リダイレクト（次のリクエストで更新済み map にヒット）
    $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    $scheme = ($proto === 'https' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) ? 'https' : 'http';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    header("Location: {$scheme}://{$host}{$uri}", true, 302);
    exit;
}

// 404 ページを返す
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 Not Found — DevRouter</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background: #f5f5f5;
            color: #333;
        }
        .container {
            text-align: center;
            padding: 2rem;
        }
        h1 {
            font-size: 4rem;
            margin: 0;
            color: #ccc;
        }
        p {
            font-size: 1.1rem;
            margin: 1rem 0;
        }
        code {
            background: #e8e8e8;
            padding: 0.2em 0.5em;
            border-radius: 3px;
            font-size: 0.95em;
        }
        a {
            color: #2563eb;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>404</h1>
        <p><code><?= htmlspecialchars($host, ENT_QUOTES, 'UTF-8') ?></code> は登録されていません</p>
        <p><a href="http://localhost">管理 UI を開く</a></p>
    </div>
</body>
</html>
