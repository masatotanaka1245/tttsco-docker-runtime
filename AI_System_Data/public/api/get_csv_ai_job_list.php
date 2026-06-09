<?php
/**
 * get_csv_ai_job_list.php - CSVカテゴリ分けジョブ一覧取得API
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

$projectId = (int)($_GET['project_id'] ?? 0);
$limit = (int)($_GET['limit'] ?? 10);
$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? 'user');

if ($projectId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'project_id が必要です。'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!canAccessProject($pdo, $projectId, $userId, $role)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'この案件のジョブ一覧を参照する権限がありません。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$basePath = realpath(__DIR__ . '/../../');
$jobService = new CsvAiCategorizationJobService($pdo, $basePath ?: dirname(__DIR__, 2));
$items = $jobService->listJobsByProject($projectId, $limit);

echo json_encode([
    'success' => true,
    'items' => $items,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
