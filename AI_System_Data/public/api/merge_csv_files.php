<?php
/**
 * merge_csv_files.php - メインCSVへサブCSVを縦結合して新しいCSVを作成するAPI
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
$mainCsvFileId = (int)($payload['main_csv_file_id'] ?? 0);
$subCsvFileIds = $payload['sub_csv_file_ids'] ?? [];
$outputFileName = trim((string)($payload['output_file_name'] ?? ''));
$createNewCsv = !isset($payload['create_new_csv']) || filter_var($payload['create_new_csv'], FILTER_VALIDATE_BOOL);
$columnMappings = $payload['column_mappings'] ?? [];
$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? 'user');

if (!$projectId || !$mainCsvFileId || !canManageProject($pdo, $projectId, $userId, $role)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'この案件でCSV統合を実行する権限がありません。'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_array($subCsvFileIds)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'サブCSVの指定形式が不正です。'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_array($columnMappings)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '列対応の指定形式が不正です。'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $service = new ProjectCsvTableService($pdo);
    $csvFile = $service->mergeIntoMain($projectId, $mainCsvFileId, $subCsvFileIds, $outputFileName, $columnMappings, $createNewCsv);

    echo json_encode([
        'success' => true,
        'csv_file' => [
            'id' => (int)$csvFile['id'],
            'file_name' => (string)$csvFile['file_name'],
            'row_count' => (int)($csvFile['row_count'] ?? 0),
            'created_at' => (string)($csvFile['created_at'] ?? ''),
            'headers' => $csvFile['headers'] ?? (json_decode((string)($csvFile['column_headers'] ?? '[]'), true) ?: []),
        ],
        'message' => $createNewCsv ? 'CSVを統合しました。' : 'メインCSVへ統合しました。',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
