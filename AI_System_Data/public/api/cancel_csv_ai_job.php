<?php
/**
 * cancel_csv_ai_job.php - CSVカテゴリ分けジョブのキャンセル要求API
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/ProjectAccess.php';
require_once __DIR__ . '/../../src/CsvAiCategorizationJobService.php';

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth($pdo);
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'ログインが必要です。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRFトークンが正しくありません。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$jobId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($payload['job_id'] ?? ''));
$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? 'user');

if ($jobId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'job_id が必要です。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$basePath = realpath(__DIR__ . '/../../');
$jobService = new CsvAiCategorizationJobService($pdo, $basePath ?: dirname(__DIR__, 2));
$job = $jobService->readJob($jobId);

if (!$job) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'ジョブが見つかりません。'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!canManageProject($pdo, (int)$job['project_id'], $userId, $role)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'このジョブをキャンセルする権限がありません。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = $jobService->requestCancel($jobId);
if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'キャンセル要求の登録に失敗しました。'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$result['cancelable']) {
    echo json_encode([
        'success' => false,
        'error' => 'このジョブはすでに完了または停止済みです。',
        'job' => $result['job'],
        'status' => $result['status'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => !empty($result['completed_now']) ? 'ジョブを停止しました。' : 'キャンセル要求を受け付けました。',
    'job' => $result['job'],
    'status' => $result['status'],
    'completed_now' => !empty($result['completed_now']),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
