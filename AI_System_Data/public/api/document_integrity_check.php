<?php

declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/AppLogger.php';
require_once __DIR__ . '/../../src/DocumentFileIntegrityChecker.php';

$auth = new Auth($pdo);
$role = $_SESSION['role'] ?? 'user';
$sessionCsrf = $_SESSION['csrf_token'] ?? '';
session_write_close();

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

$sendJson = static function (array $payload, int $statusCode = 200) use ($jsonFlags): void {
    http_response_code($statusCode);
    $json = json_encode($payload, $jsonFlags);
    if ($json === false) {
        $payload = [
            'success' => false,
            'error' => '整合チェック応答のJSON化に失敗しました: ' . json_last_error_msg(),
            'checked_at' => time(),
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
    $sendJson(['success' => false, 'error' => '整合チェックは管理者のみ利用できます。'], 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    $sendJson(['success' => false, 'error' => 'GETメソッドのみ利用できます。'], 405);
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if ($sessionCsrf === '' || !hash_equals($sessionCsrf, $csrfToken)) {
    $sendJson(['success' => false, 'error' => 'CSRFトークンが正しくありません。'], 403);
}

try {
    $basePath = realpath(__DIR__ . '/../../');
    if ($basePath === false) {
        $sendJson(['success' => false, 'error' => 'ベースパスを解決できません。'], 500);
    }

    $checker = new DocumentFileIntegrityChecker($pdo, $basePath);
    $result = $checker->scanPdfDocuments();

    $sendJson([
        'success' => true,
        'read_only' => true,
        'summary' => $result['summary'],
        'missing' => array_map(
            static fn(array $record): array => [
                'document_id' => (int)($record['document_id'] ?? 0),
                'project_id' => (int)($record['project_id'] ?? 0),
                'title' => (string)($record['title'] ?? ''),
                'file_path' => (string)($record['file_path'] ?? ''),
                'html_fallback' => !empty($record['html_fallback']),
                'created_at' => (string)($record['created_at'] ?? ''),
            ],
            $result['missing']
        ),
        'checked_at' => time(),
    ]);
} catch (Throwable $e) {
    appLog('chat_debug.log', '[DOC-INTEGRITY] api_failed', [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    $sendJson(['success' => false, 'error' => '整合チェックの取得に失敗しました。'], 500);
}
