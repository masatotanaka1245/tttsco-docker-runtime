<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/ProjectAccess.php';
require_once __DIR__ . '/../../src/ChatThreadManager.php';

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
$title = trim((string)($input['title'] ?? ''));

if (!$projectId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '案件IDが指定されていないか、形式が不正です'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!canAccessProject($pdo, (int)$projectId, (int)$_SESSION['user_id'], $_SESSION['role'] ?? 'user')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'この案件にアクセスする権限がありません'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $thread = ChatThreadManager::createThread($pdo, (int)$projectId, (int)$_SESSION['user_id'], $title);
    echo json_encode([
        'success' => true,
        'thread' => $thread,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'スレッドの作成に失敗しました',
    ], JSON_UNESCAPED_UNICODE);
}
