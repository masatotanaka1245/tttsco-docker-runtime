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
    echo json_encode(['success' => false, 'error' => '資料メモを更新する権限がありません。']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$projectId = filter_var($input['project_id'] ?? null, FILTER_VALIDATE_INT);
$documentId = filter_var($input['material_document_id'] ?? null, FILTER_VALIDATE_INT) ?: null;

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
    $existingDocument = $documentId ? $service->findById((int)$projectId, (int)$documentId) : null;
    if ($documentId && !$existingDocument) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => '対象の資料メモが見つかりませんでした。']);
        exit;
    }

    $title = trim((string)($input['material_title'] ?? ''));
    $contentInput = trim((string)($input['material_content'] ?? ''));
    $appendNote = trim((string)($input['material_append_note'] ?? ''));
    $baseContent = $contentInput;

    if ($baseContent === '' && $existingDocument) {
        $baseContent = $service->readContent((string)$existingDocument['file_path'], (int)($existingDocument['id'] ?? 0));
    }

    if ($appendNote !== '') {
        $appendHeader = '## 更新 ' . date('Y-m-d H:i');
        $appendBlock = $appendHeader . "\n\n" . $appendNote;
        if ($baseContent !== '') {
            $baseContent = rtrim($baseContent) . "\n\n" . $appendBlock;
        } else {
            $headingTitle = $title !== '' ? $title : '資料メモ_' . date('Ymd_His');
            $baseContent = '# ' . $headingTitle . "\n\n" . $appendBlock;
        }
    }

    if (trim($baseContent) === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => '資料メモの内容が空です。']);
        exit;
    }

    if ($title === '' && $existingDocument) {
        $title = (string)$existingDocument['title'];
    }

    $saved = $service->save((int)$projectId, $title, $baseContent, $documentId ? (int)$documentId : null);
    $materialDocuments = $service->listByProject((int)$projectId);
    $selectedDocument = $service->resolveSelectedDocument($materialDocuments, (int)$saved['document_id']);
    if (!$selectedDocument) {
        $selectedDocument = [
            'id' => (int)$saved['document_id'],
            'title' => (string)$saved['title'],
            'file_path' => (string)$saved['file_path'],
            'material_modified_at' => (string)($saved['modified_at'] ?? ''),
        ];
    }

    $previewHtml = $markdownPreviewParser->text((string)$saved['content']);

    echo json_encode([
        'success' => true,
        'flash_message' => '資料メモを更新しました。',
        'material_document' => $service->buildSelectedPayload($selectedDocument, (string)$saved['content'], (string)$previewHtml),
        'material_documents' => $service->buildDocumentsPayload($materialDocuments),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('save_material.php Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '資料メモの保存に失敗しました。']);
}
