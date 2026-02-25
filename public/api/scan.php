<?php
/**
 * scan.php — 手動スキャン API
 *
 * POST : グループディレクトリを再スキャンし routing.map を再生成
 */

require_once __DIR__ . '/lib/store.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method Not Allowed', 405);
}

// routing.map を再生成（グループディレクトリの再スキャン）
$result = loadState();
$state = $result['state'];
saveState($state);

$groupsInfo = buildGroupsInfo($state);

jsonResponse([
    'message' => 'スキャン完了',
    'groups'  => $groupsInfo,
]);
