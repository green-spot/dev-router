<?php
/**
 * domains.php — ベースドメイン API
 *
 * GET    : 全ベースドメインのリスト
 * POST   : ベースドメインの新規登録
 * PUT    : current の切替
 * DELETE : ベースドメインの削除
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
    case 'PUT':
        handlePut();
        break;
    case 'DELETE':
        handleDelete();
        break;
    default:
        errorResponse('Method Not Allowed', 405);
}

function handleGet(): void {
    $result = loadState();
    jsonResponse([
        'baseDomains' => $result['state']['baseDomains'],
        'warning'     => $result['warning'],
    ]);
}

function handlePost(): void {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['domain'])) {
        errorResponse('domain は必須です');
    }

    $domain = strtolower(trim($input['domain']));

    // ドメイン形式チェック（Apache 設定インジェクション防止）
    if (!isValidDomain($domain)) {
        errorResponse('無効なドメイン形式です（英数字・ハイフン・ドットのみ使用可）');
    }

    $result = loadState();
    $state = $result['state'];

    // 重複チェック
    foreach ($state['baseDomains'] as $bd) {
        if ($bd['domain'] === $domain) {
            errorResponse("ドメイン '{$domain}' は既に登録されています");
        }
    }

    $newEntry = [
        'domain'  => $domain,
        'current' => false,
        'ssl'     => false,
    ];

    // 初回登録時は自動的に current に設定
    if (empty($state['baseDomains'])) {
        $newEntry['current'] = true;
    }

    $state['baseDomains'][] = $newEntry;
    saveState($state);

    jsonResponse(['baseDomains' => $state['baseDomains']], 201);
}

function handlePut(): void {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['domain'])) {
        errorResponse('domain は必須です');
    }

    $domain = strtolower(trim($input['domain']));

    $result = loadState();
    $state = $result['state'];

    $found = false;
    foreach ($state['baseDomains'] as &$bd) {
        if ($bd['domain'] === $domain) {
            $bd['current'] = true;
            $found = true;
        } else {
            $bd['current'] = false;
        }
    }
    unset($bd);

    if (!$found) {
        errorResponse("ドメイン '{$domain}' は登録されていません", 404);
    }

    saveState($state);

    jsonResponse(['baseDomains' => $state['baseDomains']]);
}

function handleDelete(): void {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['domain'])) {
        errorResponse('domain は必須です');
    }

    $domain = strtolower(trim($input['domain']));

    $result = loadState();
    $state = $result['state'];

    $index = null;
    foreach ($state['baseDomains'] as $i => $bd) {
        if ($bd['domain'] === $domain) {
            $index = $i;
            break;
        }
    }

    if ($index === null) {
        errorResponse("ドメイン '{$domain}' は登録されていません", 404);
    }

    // current のドメインは削除不可
    if ($state['baseDomains'][$index]['current']) {
        errorResponse('current に設定されているドメインは削除できません。先に別のドメインを current に設定してください');
    }

    array_splice($state['baseDomains'], $index, 1);
    saveState($state);

    jsonResponse(['baseDomains' => $state['baseDomains']]);
}
