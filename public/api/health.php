<?php
/**
 * health.php — ヘルスチェック API
 *
 * GET : {"status": "ok"} を返す
 */

require_once __DIR__ . '/lib/store.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method Not Allowed', 405);
}

jsonResponse(['status' => 'ok']);
