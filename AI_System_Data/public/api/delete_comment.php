<?php
/**
 * delete_comment.php - プロジェクトに対するユーザーコメントを削除するAPI
 * [仕様]
 * - ログイン済みユーザーのみ実行可能
 * - CSRFトークン検証
 * - コメントの投稿者本人、または管理者（admin）のみ削除が許可される権限ガードレール付き
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../src/Auth.php';
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
$comment_id = filter_var($input['comment_id'] ?? 0, FILTER_VALIDATE_INT);

if (!$comment_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'コメントIDが指定されていないか、形式が不正です']);
    exit;
}

try {
    // 1. コメントが存在するか、およびその投稿主を調査
    $stmtCheck = $pdo->prepare("SELECT user_id FROM project_comments WHERE id = ?");
    $stmtCheck->execute([$comment_id]);
    $comment = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$comment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => '削除対象のコメントが見つかりませんでした']);
        exit;
    }

    // 2. 権限チェック: 投稿者本人、または管理者(admin)であること
    if ($comment['user_id'] != $_SESSION['user_id'] && $_SESSION['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'このコメントを削除する権限がありません']);
        exit;
    }

    // 3. 物理削除
    $stmtDel = $pdo->prepare("DELETE FROM project_comments WHERE id = ?");
    $stmtDel->execute([$comment_id]);

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    jsonApiError('コメントの削除に失敗しました', 500, $e, [
        'api' => 'delete_comment',
        'comment_id' => $comment_id,
    ]);
}
