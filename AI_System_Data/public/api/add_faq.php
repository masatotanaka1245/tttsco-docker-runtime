<?php
/**
 * add_faq.php - チャットの回答をプロジェクトのAIナレッジ(FAQ)として保存するAPI
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
$project_id = filter_var($input['project_id'] ?? 0, FILTER_VALIDATE_INT);
$question = trim($input['question'] ?? '');
$answer = trim($input['answer'] ?? '');

if (!$project_id || empty($question) || empty($answer)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '案件ID、または質問・回答の概要が入力されていません']);
    exit;
}

if (!canAccessProject($pdo, (int)$project_id, (int)$_SESSION['user_id'], $_SESSION['role'] ?? 'user')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'この案件にナレッジを登録する権限がありません']);
    exit;
}

try {
    $stmt = $pdo->prepare("INSERT INTO project_faqs (project_id, question_summary, answer_summary, created_by, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([$project_id, $question, $answer, $_SESSION['user_id']]);
    
    echo json_encode(['success' => true, 'faq_id' => (int)$pdo->lastInsertId()]);
} catch (PDOException $e) {
    jsonApiError('ナレッジの登録に失敗しました', 500, $e, [
        'api' => 'add_faq',
        'project_id' => $project_id,
    ]);
}
