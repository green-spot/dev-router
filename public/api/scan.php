<?php
/**
 * scan.php — 手動スキャン API
 *
 * POST : グループディレクトリを再スキャンし routes.conf を再生成
 */

require_once __DIR__ . '/lib/store.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method Not Allowed', 405);
}

// routes.conf / routes-ssl.conf を再生成（グループディレクトリの再スキャン + graceful restart）
$result = loadState();
$state = $result['state'];
saveState($state);

$groupsInfo = buildGroupsInfo($state);

jsonResponse([
    'message' => 'スキャン完了',
    'groups'  => $groupsInfo,
]);
