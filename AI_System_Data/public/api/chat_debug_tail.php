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

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

$sendJson = function (array $payload, int $statusCode = 200) use ($jsonFlags): void {
    http_response_code($statusCode);
    $json = json_encode($payload, $jsonFlags);
    if ($json === false) {
        $payload = [
            'success' => false,
            'error' => 'ログ応答のJSON化に失敗しました: ' . json_last_error_msg(),
            'updated_at' => time(),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    echo $json;
    exit;
};

if (!$auth->isLoggedIn()) {
    $sendJson(['success' => false, 'error' => 'ログインが必要です。'], 401);
}

if ($role !== 'admin') {
    $sendJson(['success' => false, 'error' => 'ログ表示は管理者のみ利用できます。'], 403);
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if ($sessionCsrf === '' || !hash_equals($sessionCsrf, $csrfToken)) {
    $sendJson(['success' => false, 'error' => 'CSRFトークンが正しくありません。'], 403);
}

$offset = filter_input(INPUT_GET, 'offset', FILTER_VALIDATE_INT);
$offset = $offset !== false && $offset !== null ? max(0, $offset) : 0;
$maxBytes = 65536;

$basePath = realpath(__DIR__ . '/../../');
$logPath = $basePath . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'chat_debug.log';

if (!is_file($logPath)) {
    $sendJson([
        'success' => true,
        'content' => '',
        'offset' => 0,
        'size' => 0,
        'truncated' => false,
        'updated_at' => time(),
    ]);
}

clearstatcache(true, $logPath);
$size = filesize($logPath);
if ($size === false) {
    $sendJson(['success' => false, 'error' => 'ログサイズを取得できません。'], 500);
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
} else {
    $sendJson(['success' => false, 'error' => 'ログファイルを開けません。'], 500);
}

$sendJson([
    'success' => true,
    'content' => $content,
    'offset' => $size,
    'size' => $size,
    'truncated' => $truncated,
    'updated_at' => time(),
]);
