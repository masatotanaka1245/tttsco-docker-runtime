<?php
/**
 * start_csv_ai_categorize_job.php - CSVカテゴリ分けジョブ起動API
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/ProjectAccess.php';
require_once __DIR__ . '/../../src/ModelRoleResolver.php';
require_once __DIR__ . '/../../src/UserSettingsSessionSynchronizer.php';
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
$projectId = (int)($payload['project_id'] ?? 0);
$csvFileId = (int)($payload['csv_file_id'] ?? 0);
$targetColumn = trim((string)($payload['target_column'] ?? ''));
$outputFileName = trim((string)($payload['output_file_name'] ?? ''));
$categoryColumnName = trim((string)($payload['category_column_name'] ?? 'AIカテゴリ'));
$reasonColumnName = trim((string)($payload['reason_column_name'] ?? 'AI分類理由'));
$instructions = trim((string)($payload['instructions'] ?? ''));
$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? 'user');

if (!$projectId || !$csvFileId || $targetColumn === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '案件・CSV・対象列は必須です。'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!canManageProject($pdo, $projectId, $userId, $role)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'この案件でカテゴリ分けジョブを開始する権限がありません。'], JSON_UNESCAPED_UNICODE);
    exit;
}

UserSettingsSessionSynchronizer::sync($pdo, $userId);
$resolvedModels = ModelRoleResolver::resolveUserSettings($_SESSION);

$stmt = $pdo->prepare("SELECT file_name FROM project_csv_files WHERE id = ? AND project_id = ? LIMIT 1");
$stmt->execute([$csvFileId, $projectId]);
$csvFile = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$csvFile) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => '対象のCSVファイルが見つかりません。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmtRows = $pdo->prepare("SELECT row_data FROM project_csv_rows WHERE csv_file_id = ?");
$stmtRows->execute([$csvFileId]);
$hasNonEmptyValue = false;
while ($row = $stmtRows->fetch(PDO::FETCH_ASSOC)) {
    $rowData = json_decode((string)($row['row_data'] ?? ''), true);
    if (!is_array($rowData)) {
        continue;
    }

    $value = trim((string)($rowData[$targetColumn] ?? ''));
    if ($value !== '') {
        $hasNonEmptyValue = true;
        break;
    }
}

if (!$hasNonEmptyValue) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => '選択した列には値が入っている行がありません。別の列を選ぶか、そのままキャンセルしてください。',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$basePath = realpath(__DIR__ . '/../../');
$jobService = new CsvAiCategorizationJobService($pdo, $basePath ?: dirname(__DIR__, 2));
$job = $jobService->createJob([
    'project_id' => $projectId,
    'user_id' => $userId,
    'source_csv_file_id' => $csvFileId,
    'source_file_name' => (string)$csvFile['file_name'],
    'target_column' => $targetColumn,
    'output_file_name' => $outputFileName,
    'category_column_name' => $categoryColumnName,
    'reason_column_name' => $reasonColumnName,
    'instructions' => $instructions,
    'ollama_host' => $resolvedModels['ollama_host'],
    'model' => $resolvedModels['worker_model'] ?? $resolvedModels['sub_model'] ?? $resolvedModels['main_model'],
]);

$phpBinary = PHP_BINARY ?: 'php';
$scriptPath = $jobService->getCliScriptPath();
$jobId = $job['job_id'];
$command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($jobId) . ' > /dev/null 2>&1 &';
exec($command);

echo json_encode([
    'success' => true,
    'job_id' => $jobId,
    'message' => 'カテゴリ分けジョブを開始しました。',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
