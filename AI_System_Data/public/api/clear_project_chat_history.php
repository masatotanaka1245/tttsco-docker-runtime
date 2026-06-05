<?php
/**
 * clear_project_chat_history.php - 案件単位のチャット履歴削除 API
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/ProjectAccess.php';
require_once __DIR__ . '/../../src/AppLogger.php';
require_once __DIR__ . '/../../src/ChatHistoryMaintenance.php';

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth($pdo);
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'セッションがタイムアウトしました。再ログインしてください。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($csrfToken) || $csrfToken !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => '不正なリクエストです（CSRF検証失敗）'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$projectId = isset($input['project_id']) ? filter_var($input['project_id'], FILTER_VALIDATE_INT) : null;

if (!$projectId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '案件IDが指定されていないか、形式が不正です'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!canManageProject($pdo, (int)$projectId, (int)$_SESSION['user_id'], $_SESSION['role'] ?? 'user')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'この案件の履歴を削除する権限がありません'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $counts = ChatHistoryMaintenance::clearProjectHistory($pdo, (int)$projectId);

    echo json_encode([
        'success' => true,
        'message' => 'チャット履歴を削除しました',
        'counts' => $counts,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    jsonApiError('チャット履歴の削除に失敗しました', 500, $e, [
        'api' => 'clear_project_chat_history',
        'project_id' => (int)$projectId,
    ]);
}
