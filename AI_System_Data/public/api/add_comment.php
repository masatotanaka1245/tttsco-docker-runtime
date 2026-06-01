<?php
/**
 * add_comment.php - プロジェクトに対するユーザーコメントを保存し、保存したデータを返すAPI
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
    echo json_encode(['success' => false, 'error' => '不正なリクエストです（CSRF）']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$project_id = filter_var($input['project_id'] ?? 0, FILTER_VALIDATE_INT);
$comment = trim($input['comment'] ?? '');

if (!$project_id || empty($comment)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '案件IDまたはコメントが不足しています']);
    exit;
}

if (!canAccessProject($pdo, (int)$project_id, (int)$_SESSION['user_id'], $_SESSION['role'] ?? 'user')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'この案件にコメントする権限がありません']);
    exit;
}

try {
    // コメントをDBに登録
    $stmt = $pdo->prepare("INSERT INTO project_comments (project_id, user_id, comment_text, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->execute([$project_id, $_SESSION['user_id'], $comment]);
    
    $new_comment_id = $pdo->lastInsertId();

    // 登録したばかりのコメントデータと投稿者名を取得して返す
    $stmtGet = $pdo->prepare("SELECT pc.*, u.username FROM project_comments pc JOIN users u ON pc.user_id = u.id WHERE pc.id = ?");
    $stmtGet->execute([$new_comment_id]);
    $new_comment = $stmtGet->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'comment' => $new_comment
    ]);
} catch (PDOException $e) {
    jsonApiError('コメントの追加に失敗しました', 500, $e, [
        'api' => 'add_comment',
        'project_id' => $project_id,
    ]);
}
