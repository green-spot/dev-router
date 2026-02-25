<?php
/**
 * store.php — routes.json 読み書き + routing.map 生成
 *
 * DevRouter のコアデータ管理ライブラリ。
 * 全 API エンドポイントがこのファイルを require して利用する。
 */

// ROUTER_HOME: このファイルから3階層上がリポジトリルート
define('ROUTER_HOME', realpath(__DIR__ . '/../../..'));
define('ROUTES_JSON', ROUTER_HOME . '/data/routes.json');
define('ROUTES_BAK',  ROUTER_HOME . '/data/routes.json.bak');
define('ROUTING_MAP',  ROUTER_HOME . '/data/routing.map');

// スラグの許可パターン
define('SLUG_PATTERN', '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/');

/**
 * routes.json を読み込む。
 * パース失敗時はバックアップから復元、それも失敗なら空の初期状態を返す。
 *
 * @return array ['state' => array, 'warning' => string|null]
 */
function loadState(): array {
    $warning = null;

    // メインファイルから読み込み
    if (file_exists(ROUTES_JSON)) {
        $json = file_get_contents(ROUTES_JSON);
        $state = json_decode($json, true);
        if (is_array($state) && isset($state['baseDomains'])) {
            return ['state' => $state, 'warning' => null];
        }
    }

    // バックアップから復元
    if (file_exists(ROUTES_BAK)) {
        $json = file_get_contents(ROUTES_BAK);
        $state = json_decode($json, true);
        if (is_array($state) && isset($state['baseDomains'])) {
            // バックアップをメインとして書き戻す
            file_put_contents(ROUTES_JSON, $json);
            return [
                'state' => $state,
                'warning' => 'routes.json のパースに失敗したため、バックアップから復元しました',
            ];
        }
    }

    // 空の初期状態
    $state = getEmptyState();
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    file_put_contents(ROUTES_JSON, $json);

    return [
        'state' => $state,
        'warning' => 'routes.json とバックアップの両方が利用できないため、空の初期状態で起動しました',
    ];
}

/**
 * 空の初期状態を返す
 */
function getEmptyState(): array {
    return [
        'baseDomains' => [
            [
                'domain'  => '127.0.0.1.nip.io',
                'current' => true,
                'ssl'     => false,
            ],
        ],
        'groups' => [],
        'routes' => [],
    ];
}

/**
 * routes.json を保存し、routing.map を再生成する。
 *
 * 1. バックアップ作成
 * 2. routes.json にアトミック書き込み
 * 3. routing.map 再生成
 *
 * @param array $state 保存する状態
 * @return void
 */
function saveState(array $state): void {
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    // バックアップ作成（既存ファイルがある場合）
    if (file_exists(ROUTES_JSON)) {
        copy(ROUTES_JSON, ROUTES_BAK);
    }

    // アトミック書き込み（一時ファイル + rename）
    $tmpFile = ROUTES_JSON . '.tmp.' . getmypid();
    file_put_contents($tmpFile, $json, LOCK_EX);
    rename($tmpFile, ROUTES_JSON);

    // routing.map 再生成
    $mapContent = generateRoutingMap($state);
    writeRoutingMap(ROUTING_MAP, $mapContent);
}

/**
 * routes.json の内容から routing.map テキストを生成する。
 *
 * 生成順序:
 * 1. ベースドメイン直アクセス → リダイレクトエントリ
 * 2. 明示登録（routes）→ 全ベースドメインとの組み合わせ
 * 3. グループ解決（登録順走査、先にマッチしたグループ優先）→ 全ベースドメインとの組み合わせ
 *
 * @param array $state
 * @return string routing.map のテキスト内容
 */
function generateRoutingMap(array $state): string {
    $lines = ["# 自動生成 — 手動編集禁止"];
    $domains = array_column($state['baseDomains'], 'domain');

    // 1. ベースドメイン直アクセス → 管理UIへリダイレクト
    $lines[] = "# ベースドメイン直アクセス → 管理UIへリダイレクト";
    foreach ($domains as $domain) {
        $lines[] = "{$domain} R:http://localhost";
    }

    // 明示登録スラグの集合（グループ解決時の重複チェック用）
    $usedSlugs = [];

    // 2. 明示登録（routes）
    if (!empty($state['routes'])) {
        $lines[] = "";
        $lines[] = "# 明示登録";
        foreach ($state['routes'] as $route) {
            $slug = $route['slug'];
            $target = $route['target'];
            $usedSlugs[$slug] = true;
            foreach ($domains as $domain) {
                $lines[] = "{$slug}.{$domain} {$target}";
            }
        }
    }

    // 3. グループ解決（登録順走査、先にマッチしたグループ優先）
    $groupEntries = [];
    foreach ($state['groups'] as $group) {
        $path = $group['path'];
        if (!is_dir($path)) {
            continue;
        }
        $entries = scanGroupDirectory($path);
        foreach ($entries as $entry) {
            $slug = $entry['slug'];
            // 明示登録スラグ・先行グループと衝突する場合はスキップ
            if (isset($usedSlugs[$slug])) {
                continue;
            }
            $usedSlugs[$slug] = true;
            $groupEntries[] = $entry;
        }
    }

    if (!empty($groupEntries)) {
        $lines[] = "";
        $lines[] = "# グループ解決";
        foreach ($groupEntries as $entry) {
            foreach ($domains as $domain) {
                $lines[] = "{$entry['slug']}.{$domain} {$entry['target']}";
            }
        }
    }

    return implode("\n", $lines) . "\n";
}

/**
 * グループディレクトリのサブディレクトリをスキャンする。
 * スラグパターン一致チェック + public/ 自動検出を行う。
 *
 * @param string $groupPath グループディレクトリのパス
 * @return array [['slug' => string, 'target' => string], ...]
 */
function scanGroupDirectory(string $groupPath): array {
    $result = scanGroupDirectoryFull($groupPath);
    return $result['valid'];
}

/**
 * グループディレクトリの全サブディレクトリをスキャンする。
 * パターン一致するものと非対応のものを分けて返す。
 *
 * @param string $groupPath グループディレクトリのパス
 * @return array ['valid' => [...], 'skipped' => [...]]
 */
function scanGroupDirectoryFull(string $groupPath): array {
    $valid = [];
    $skipped = [];

    $items = @scandir($groupPath);
    if ($items === false) {
        return ['valid' => [], 'skipped' => []];
    }

    foreach ($items as $item) {
        // . と .. をスキップ
        if ($item === '.' || $item === '..') {
            continue;
        }

        $fullPath = $groupPath . '/' . $item;

        // ディレクトリのみ対象
        if (!is_dir($fullPath)) {
            continue;
        }

        // スラグパターン一致チェック
        if (!preg_match(SLUG_PATTERN, $item)) {
            $skipped[] = [
                'name'   => $item,
                'reason' => 'スラグパターンに一致しません（英小文字・数字・ハイフンのみ使用可）',
            ];
            continue;
        }

        // public/ 自動検出: public/ があればそちらを DocumentRoot
        $target = $fullPath;
        if (is_dir($fullPath . '/public')) {
            $target = $fullPath . '/public';
        }

        $valid[] = [
            'slug'   => $item,
            'target' => $target,
        ];
    }

    return ['valid' => $valid, 'skipped' => $skipped];
}

/**
 * routing.map をアトミック書き込みする。
 * 一時ファイルに書き込み → rename で置換。
 *
 * @param string $mapPath routing.map のパス
 * @param string $content 書き込む内容
 * @return void
 */
function writeRoutingMap(string $mapPath, string $content): void {
    $tmpFile = $mapPath . '.tmp.' . getmypid();
    file_put_contents($tmpFile, $content, LOCK_EX);
    rename($tmpFile, $mapPath);
}

/**
 * JSON レスポンスを返すヘルパー
 *
 * @param mixed $data レスポンスデータ
 * @param int $statusCode HTTP ステータスコード
 */
function jsonResponse($data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit;
}

/**
 * エラーレスポンスを返すヘルパー
 *
 * @param string $message エラーメッセージ
 * @param int $statusCode HTTP ステータスコード
 */
function errorResponse(string $message, int $statusCode = 400): void {
    jsonResponse(['error' => $message], $statusCode);
}

/**
 * 衝突検出・スキップ情報を含むグループ情報を構築する。
 * groups.php と scan.php で共有。
 *
 * @param array $state routes.json の状態
 * @return array グループ情報の配列
 */
function buildGroupsInfo(array $state): array {
    $groupsInfo = [];
    $seenSlugs = [];

    // 明示登録スラグを先に収集
    foreach ($state['routes'] as $route) {
        $seenSlugs[$route['slug']] = ['type' => 'explicit'];
    }

    foreach ($state['groups'] as $group) {
        $path = $group['path'];
        $scanResult = is_dir($path) ? scanGroupDirectoryFull($path) : ['valid' => [], 'skipped' => []];
        $subdirs = [];

        foreach ($scanResult['valid'] as $entry) {
            $slug = $entry['slug'];
            $status = 'active';
            $conflictWith = null;

            if (isset($seenSlugs[$slug])) {
                $status = 'shadowed';
                $conflictWith = $seenSlugs[$slug]['type'] === 'explicit'
                    ? '明示登録'
                    : $seenSlugs[$slug]['group'];
            } else {
                $seenSlugs[$slug] = ['type' => 'group', 'group' => $path];
            }

            $subdirs[] = [
                'slug'         => $slug,
                'target'       => $entry['target'],
                'status'       => $status,
                'conflictWith' => $conflictWith,
            ];
        }

        $groupsInfo[] = [
            'path'    => $path,
            'exists'  => is_dir($path),
            'subdirs' => $subdirs,
            'skipped' => $scanResult['skipped'],
        ];
    }

    return $groupsInfo;
}
