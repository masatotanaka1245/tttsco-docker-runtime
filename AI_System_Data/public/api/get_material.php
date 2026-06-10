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

$projectId = filter_input(INPUT_GET, 'project_id', FILTER_VALIDATE_INT);
$documentId = filter_input(INPUT_GET, 'material_document_id', FILTER_VALIDATE_INT);
$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? 'user');

if (!$projectId || !canAccessProject($pdo, (int)$projectId, $userId, $role)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'この案件へアクセスする権限がありません。']);
    exit;
}

$service = new ProjectMaterialDocumentService($pdo, dirname(__DIR__, 2));
$markdownPreviewParser = new Parsedown();
$markdownPreviewParser->setBreaksEnabled(true);
$markdownPreviewParser->setSafeMode(true);

try {
    $materialDocuments = $service->listByProject((int)$projectId);
    $selectedDocument = $service->resolveSelectedDocument($materialDocuments, $documentId ? (int)$documentId : null);

    if (!$selectedDocument) {
        echo json_encode([
            'success' => true,
            'material_document' => $service->buildSelectedPayload(null),
            'material_documents' => [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $content = $service->readContent((string)$selectedDocument['file_path'], (int)($selectedDocument['id'] ?? 0));
    $previewHtml = $content !== '' ? $markdownPreviewParser->text($content) : '';

    echo json_encode([
        'success' => true,
        'material_document' => $service->buildSelectedPayload($selectedDocument, $content, $previewHtml),
        'material_documents' => $service->buildDocumentsPayload($materialDocuments),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('get_material.php Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '資料メモの取得に失敗しました。']);
}
