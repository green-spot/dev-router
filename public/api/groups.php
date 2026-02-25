<?php
/**
 * groups.php — グループ API
 *
 * GET    : 全グループのリスト（サブディレクトリ情報・衝突情報を含む）
 * POST   : グループディレクトリの新規登録
 * PUT    : グループの優先順位（順序）を変更
 * DELETE : グループの削除
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
    $state = $result['state'];

    $groupsInfo = buildGroupsInfo($state);

    jsonResponse([
        'groups'  => $groupsInfo,
        'warning' => $result['warning'],
    ]);
}

function handlePost(): void {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['path'])) {
        errorResponse('path は必須です');
    }

    $path = rtrim($input['path'], '/');

    // パスの存在チェック
    if (!is_dir($path)) {
        errorResponse("ディレクトリが存在しません: {$path}");
    }

    $result = loadState();
    $state = $result['state'];

    // 重複チェック
    foreach ($state['groups'] as $group) {
        if ($group['path'] === $path) {
            errorResponse("グループ '{$path}' は既に登録されています");
        }
    }

    $state['groups'][] = ['path' => $path];
    saveState($state);

    $groupsInfo = buildGroupsInfo($state);
    jsonResponse(['groups' => $groupsInfo], 201);
}

function handlePut(): void {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['order']) || !is_array($input['order'])) {
        errorResponse('order（パスの配列）は必須です');
    }

    $result = loadState();
    $state = $result['state'];

    // 既存パスの集合
    $existingPaths = array_column($state['groups'], 'path');

    // order に含まれるパスが全て既存であることを確認
    foreach ($input['order'] as $path) {
        if (!in_array($path, $existingPaths, true)) {
            errorResponse("グループ '{$path}' は登録されていません");
        }
    }

    // order に含まれるパスが既存の全グループをカバーしていることを確認
    if (count($input['order']) !== count($existingPaths)) {
        errorResponse('order には全てのグループパスを含めてください');
    }

    // 順序を更新
    $state['groups'] = array_map(
        fn($path) => ['path' => $path],
        $input['order']
    );

    saveState($state);

    $groupsInfo = buildGroupsInfo($state);
    jsonResponse(['groups' => $groupsInfo]);
}

function handleDelete(): void {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['path'])) {
        errorResponse('path は必須です');
    }

    $path = rtrim($input['path'], '/');

    $result = loadState();
    $state = $result['state'];

    $index = null;
    foreach ($state['groups'] as $i => $group) {
        if ($group['path'] === $path) {
            $index = $i;
            break;
        }
    }

    if ($index === null) {
        errorResponse("グループ '{$path}' は登録されていません", 404);
    }

    array_splice($state['groups'], $index, 1);
    saveState($state);

    $groupsInfo = buildGroupsInfo($state);
    jsonResponse(['groups' => $groupsInfo]);
}
