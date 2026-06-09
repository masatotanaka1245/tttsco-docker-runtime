<?php
/**
 * get_csv_ai_job_status.php - CSVカテゴリ分けジョブ状態取得API
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

$jobId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['job_id'] ?? ''));
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
$status = $jobService->readStatus($jobId);

if (!$job || !$status) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'ジョブが見つかりません。'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!canAccessProject($pdo, (int)$job['project_id'], $userId, $role)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'このジョブの参照権限がありません。'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'success' => true,
    'job' => $job,
    'status' => $status,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
