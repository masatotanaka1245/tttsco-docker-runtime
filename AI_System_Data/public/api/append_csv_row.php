<?php
/**
 * append_csv_row.php - 手作業CSV台帳へ1行追加するAPI
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/ProjectAccess.php';
require_once __DIR__ . '/../../src/ProjectCsvTableService.php';

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth($pdo);
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'ログインが必要です。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($token) || !isset($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRFトークンが正しくありません。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$projectId = (int)($payload['project_id'] ?? 0);
$csvFileId = (int)($payload['csv_file_id'] ?? 0);
$rowData = $payload['row_data'] ?? [];
$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? 'user');

if (!$projectId || !$csvFileId || !canManageProject($pdo, $projectId, $userId, $role)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'この案件へCSV行を追加する権限がありません。'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_array($rowData)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '行データの形式が不正です。'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $service = new ProjectCsvTableService($pdo);
    $csvFile = $service->appendRow($projectId, $csvFileId, $rowData);

    echo json_encode([
        'success' => true,
        'csv_file' => [
            'id' => (int)$csvFile['id'],
            'file_name' => (string)$csvFile['file_name'],
            'row_count' => (int)($csvFile['row_count'] ?? 0),
            'created_at' => (string)($csvFile['created_at'] ?? ''),
            'headers' => json_decode((string)$csvFile['column_headers'], true) ?: [],
        ],
        'last_appended_row_index' => (int)($csvFile['last_appended_row_index'] ?? 0),
        'message' => 'CSVに1行追加しました。',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
