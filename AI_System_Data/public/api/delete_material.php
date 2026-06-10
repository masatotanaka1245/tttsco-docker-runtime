<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Parsedown.php';
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
    echo json_encode(['success' => false, 'error' => '資料メモを削除する権限がありません。']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$projectId = filter_var($input['project_id'] ?? null, FILTER_VALIDATE_INT);
$documentId = filter_var($input['material_document_id'] ?? null, FILTER_VALIDATE_INT);

if (!$projectId || !$documentId || !canAccessProject($pdo, (int)$projectId, $userId, $role)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'この案件へアクセスする権限がありません。']);
    exit;
}

$service = new ProjectMaterialDocumentService($pdo, dirname(__DIR__, 2));
$markdownPreviewParser = new Parsedown();
$markdownPreviewParser->setBreaksEnabled(true);
$markdownPreviewParser->setSafeMode(true);

try {
    $deleted = $service->delete((int)$projectId, (int)$documentId);
    if (!$deleted) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => '対象の資料メモが見つかりませんでした。']);
        exit;
    }

    $materialDocuments = $service->listByProject((int)$projectId);
    $selectedDocument = $service->resolveSelectedDocument($materialDocuments);
    $selectedContent = '';
    $previewHtml = '';
    if ($selectedDocument) {
        $selectedContent = $service->readContent((string)$selectedDocument['file_path'], (int)($selectedDocument['id'] ?? 0));
        $previewHtml = $selectedContent !== '' ? $markdownPreviewParser->text($selectedContent) : '';
    }

    echo json_encode([
        'success' => true,
        'flash_message' => '資料メモを削除しました。',
        'material_document' => $service->buildSelectedPayload($selectedDocument, $selectedContent, $previewHtml),
        'material_documents' => $service->buildDocumentsPayload($materialDocuments),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('delete_material.php Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '資料メモの削除に失敗しました。']);
}
