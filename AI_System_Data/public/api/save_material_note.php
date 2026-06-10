<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/ProjectAccess.php';
require_once __DIR__ . '/../../src/ProjectMaterialDocumentService.php';

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth($pdo);
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'ログインが必要です。']);
    exit;
}

$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($csrfHeader) || !isset($_SESSION['csrf_token']) || $csrfHeader !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRFトークンが正しくありません。']);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? 'user');

if ($role !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => '資料メモを更新する権限がありません。']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$projectId = filter_var($input['project_id'] ?? null, FILTER_VALIDATE_INT);
$documentId = filter_var($input['material_document_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
$question = trim((string)($input['question'] ?? ''));
$answer = trim((string)($input['answer'] ?? ''));
$title = trim((string)($input['title'] ?? ''));
$sourceKind = trim((string)($input['source_kind'] ?? 'general_ai_answer'));

if (!$projectId || !canAccessProject($pdo, (int)$projectId, $userId, $role)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'この案件へアクセスする権限がありません。']);
    exit;
}

if ($answer === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => '保存対象の回答が空です。']);
    exit;
}

$service = new ProjectMaterialDocumentService($pdo, dirname(__DIR__, 2));

try {
    $existingDocument = $documentId ? $service->findById((int)$projectId, (int)$documentId) : null;
    $nowLabel = date('Y-m-d H:i');
    $sectionTitle = $sourceKind === 'csv_analysis' ? 'CSV読解メモ' : 'AI回答';
    $appendBlock = "## {$sectionTitle} {$nowLabel}\n\n";
    if ($question !== '') {
        $appendBlock .= "### 質問\n\n{$question}\n\n";
    }
    $appendBlock .= "### 回答\n\n{$answer}\n";

    if ($existingDocument) {
        $baseContent = $service->readContent((string)$existingDocument['file_path'], (int)($existingDocument['id'] ?? 0));
        $nextContent = trim($baseContent) !== ''
            ? rtrim($baseContent) . "\n\n" . $appendBlock
            : '# ' . trim((string)$existingDocument['title']) . "\n\n" . $appendBlock;
        $saved = $service->save((int)$projectId, (string)$existingDocument['title'], $nextContent, (int)$existingDocument['id']);
        $created = false;
    } else {
        if ($title !== '') {
            $resolvedTitle = $title;
        } elseif ($sourceKind === 'csv_analysis') {
            $resolvedTitle = 'CSV読解メモ_' . date('Ymd');
        } else {
            $resolvedTitle = 'AI資料メモ_' . date('Ymd');
        }
        $nextContent = '# ' . $resolvedTitle . "\n\n" . $appendBlock;
        $saved = $service->save((int)$projectId, $resolvedTitle, $nextContent, null);
        $created = true;
    }

    echo json_encode([
        'success' => true,
        'created' => $created,
        'material_document' => [
            'document_id' => (int)$saved['document_id'],
            'title' => (string)$saved['title'],
            'file_path' => (string)$saved['file_path'],
            'modified_at' => (string)($saved['modified_at'] ?? ''),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('save_material_note.php Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '資料メモの保存に失敗しました。']);
}
