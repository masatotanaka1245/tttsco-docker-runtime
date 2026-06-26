<?php

declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/AppLogger.php';
require_once __DIR__ . '/../../src/ReportRegenerationCandidateFinder.php';

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
            'error' => '再生成候補応答のJSON化に失敗しました: ' . json_last_error_msg(),
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
    $sendJson(['success' => false, 'error' => '再生成候補の確認は管理者のみ利用できます。'], 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    $sendJson(['success' => false, 'error' => 'GETメソッドのみ利用できます。'], 405);
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if ($sessionCsrf === '' || !hash_equals($sessionCsrf, $csrfToken)) {
    $sendJson(['success' => false, 'error' => 'CSRFトークンが正しくありません。'], 403);
}

$documentId = (int)($_GET['document_id'] ?? 0);
if ($documentId <= 0) {
    $sendJson(['success' => false, 'error' => 'document_id を指定してください。'], 400);
}

try {
    $finder = new ReportRegenerationCandidateFinder($pdo);
    $candidate = $finder->findByDocumentId($documentId);

    $sendJson([
        'success' => true,
        'read_only' => true,
        'document_id' => $documentId,
        'candidate' => [
            'applicable' => !empty($candidate['applicable']),
            'confidence' => (string)($candidate['confidence'] ?? 'none'),
            'mode' => (string)($candidate['mode'] ?? 'none'),
            'exact_replay' => !empty($candidate['exact_replay']),
            'reason' => (string)($candidate['reason'] ?? ''),
            'project_id' => (int)($candidate['project_id'] ?? 0),
            'title' => (string)($candidate['title'] ?? ''),
            'user_chat_history_id' => (int)($candidate['user_chat_history_id'] ?? 0),
            'assistant_chat_history_id' => (int)($candidate['assistant_chat_history_id'] ?? 0),
            'thread_id' => (int)($candidate['thread_id'] ?? 0),
            'reasoning_session_id' => (string)($candidate['reasoning_session_id'] ?? ''),
            'reasoning_steps_count' => (int)($candidate['reasoning_steps_count'] ?? 0),
            'has_report_draft_step' => !empty($candidate['has_report_draft_step']),
            'time_distance_seconds' => (int)($candidate['time_distance_seconds'] ?? 0),
            'matched_signals' => array_values((array)($candidate['matched_signals'] ?? [])),
            'user_created_at' => (string)($candidate['user_created_at'] ?? ''),
            'assistant_created_at' => (string)($candidate['assistant_created_at'] ?? ''),
            'user_message_excerpt' => (string)($candidate['user_message_excerpt'] ?? ''),
            'assistant_message_excerpt' => (string)($candidate['assistant_message_excerpt'] ?? ''),
            'note' => (string)($candidate['note'] ?? ''),
        ],
        'checked_at' => time(),
    ]);
} catch (Throwable $e) {
    appLog('chat_debug.log', '[REPORT-REGEN-CANDIDATE] api_failed', [
        'document_id' => $documentId,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    $sendJson(['success' => false, 'error' => '再生成候補の取得に失敗しました。'], 500);
}
