<?php
/**
 * delete_faq.php - 登録済みのナレッジ(FAQ)を削除するAPI
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/ProjectAccess.php';
require_once __DIR__ . '/../../src/AppLogger.php';

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth($pdo);
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'ログインが必要です']);
    exit;
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => '不正なリクエストです（CSRF検証失敗）']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$faq_id = filter_var($input['faq_id'] ?? ($input['id'] ?? 0), FILTER_VALIDATE_INT);

if (!$faq_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '無効なFAQ IDです']);
    exit;
}

try {
    $stmtCheck = $pdo->prepare("SELECT project_id, created_by FROM project_faqs WHERE id = ?");
    $stmtCheck->execute([$faq_id]);
    $faq = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$faq) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => '指定されたFAQが見つかりません']);
        exit;
    }

    if ((int)$faq['created_by'] !== (int)$_SESSION['user_id'] && !canManageProject($pdo, (int)$faq['project_id'], (int)$_SESSION['user_id'], $_SESSION['role'] ?? 'user')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'このFAQを削除する権限がありません']);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM project_faqs WHERE id = ?");
    $stmt->execute([$faq_id]);
    
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    jsonApiError('ナレッジの削除に失敗しました', 500, $e, [
        'api' => 'delete_faq',
        'faq_id' => $faq_id,
    ]);
}
