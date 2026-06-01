<?php
/**
 * chat_debug_tail.php - chat_debug.log の差分取得API
 */

ob_start();

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../src/Auth.php';

$auth = new Auth($pdo);
$role = $_SESSION['role'] ?? 'user';
$sessionCsrf = $_SESSION['csrf_token'] ?? '';
session_write_close();

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'ログインが必要です。'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($role !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'ログ表示は管理者のみ利用できます。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if ($sessionCsrf === '' || !hash_equals($sessionCsrf, $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRFトークンが正しくありません。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$offset = filter_input(INPUT_GET, 'offset', FILTER_VALIDATE_INT);
$offset = $offset !== false && $offset !== null ? max(0, $offset) : 0;
$maxBytes = 65536;

$basePath = realpath(__DIR__ . '/../../');
$logPath = $basePath . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'chat_debug.log';

if (!is_file($logPath)) {
    echo json_encode([
        'success' => true,
        'content' => '',
        'offset' => 0,
        'size' => 0,
        'truncated' => false,
        'updated_at' => time(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$size = filesize($logPath);
if ($size === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'ログサイズを取得できません。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$truncated = false;
if ($offset > $size) {
    $offset = 0;
    $truncated = true;
}

$start = $offset;
if ($size - $offset > $maxBytes) {
    $start = max(0, $size - $maxBytes);
    $truncated = true;
}

$content = '';
$fp = @fopen($logPath, 'rb');
if ($fp) {
    if (flock($fp, LOCK_SH)) {
        fseek($fp, $start);
        $content = stream_get_contents($fp) ?: '';
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

echo json_encode([
    'success' => true,
    'content' => $content,
    'offset' => $size,
    'size' => $size,
    'truncated' => $truncated,
    'updated_at' => time(),
], JSON_UNESCAPED_UNICODE);
