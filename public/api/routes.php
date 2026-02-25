<?php
/**
 * routes.php — ルート API
 *
 * GET    : 全ルートのリスト
 * POST   : ルートの新規登録（スラグ指定公開 or リバースプロキシ）
 * DELETE : ルートの削除
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
    case 'DELETE':
        handleDelete();
        break;
    default:
        errorResponse('Method Not Allowed', 405);
}

function handleGet(): void {
    $result = loadState();
    jsonResponse([
        'routes'  => $result['state']['routes'],
        'warning' => $result['warning'],
    ]);
}

function handlePost(): void {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        errorResponse('リクエストボディが不正です');
    }

    if (empty($input['slug'])) {
        errorResponse('slug は必須です');
    }
    if (empty($input['target'])) {
        errorResponse('target は必須です');
    }
    if (empty($input['type']) || !in_array($input['type'], ['directory', 'proxy'], true)) {
        errorResponse('type は "directory" または "proxy" を指定してください');
    }

    $slug = strtolower(trim($input['slug']));
    $target = trim($input['target']);
    $type = $input['type'];

    // スラグのバリデーション
    if (!preg_match(SLUG_PATTERN, $slug)) {
        errorResponse('スラグは英小文字・数字・ハイフンのみ使用可能です（先頭と末尾はハイフン不可）');
    }

    // type 別のバリデーション
    if ($type === 'directory') {
        if (!is_dir($target)) {
            errorResponse("ディレクトリが存在しません: {$target}");
        }
    } elseif ($type === 'proxy') {
        if (!preg_match('#^https?://.+#', $target)) {
            errorResponse('proxy の target は http:// または https:// で始まる URL を指定してください');
        }
    }

    $result = loadState();
    $state = $result['state'];

    // 既存スラグとの重複チェック（明示登録同士）
    foreach ($state['routes'] as $route) {
        if ($route['slug'] === $slug) {
            errorResponse("スラグ '{$slug}' は既に登録されています");
        }
    }

    $state['routes'][] = [
        'slug'   => $slug,
        'target' => $target,
        'type'   => $type,
    ];

    saveState($state);

    jsonResponse(['routes' => $state['routes']], 201);
}

function handleDelete(): void {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['slug'])) {
        errorResponse('slug は必須です');
    }

    $slug = strtolower(trim($input['slug']));

    $result = loadState();
    $state = $result['state'];

    $index = null;
    foreach ($state['routes'] as $i => $route) {
        if ($route['slug'] === $slug) {
            $index = $i;
            break;
        }
    }

    if ($index === null) {
        errorResponse("スラグ '{$slug}' は登録されていません", 404);
    }

    array_splice($state['routes'], $index, 1);
    saveState($state);

    jsonResponse(['routes' => $state['routes']]);
}
