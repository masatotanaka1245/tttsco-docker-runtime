<?php
/**
 * add_project_member.php - プロジェクトにメンバーを追加・更新するAPI
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
$user_id = filter_var($input['user_id'] ?? 0, FILTER_VALIDATE_INT);
$role = $input['role'] ?? 'member';
$allowedRoles = ['manager', 'member', 'viewer'];
if (!in_array($role, $allowedRoles, true)) {
    $role = 'member';
}

if (!$project_id || !$user_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '必要なパラメータが不足しています']);
    exit;
}

if (!canManageProject($pdo, (int)$project_id, (int)$_SESSION['user_id'], $_SESSION['role'] ?? 'user')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'この案件のメンバーを編集する権限がありません']);
    exit;
}

try {
    // 既存の登録がある場合は役割(role)をアップデートする
    $stmt = $pdo->prepare("
        INSERT INTO project_members (project_id, user_id, role, assigned_at) 
        VALUES (?, ?, ?, NOW()) 
        ON DUPLICATE KEY UPDATE role = VALUES(role)
    ");
    $stmt->execute([$project_id, $user_id, $role]);
    
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    jsonApiError('メンバーの追加に失敗しました', 500, $e, [
        'api' => 'add_project_member',
        'project_id' => $project_id,
        'target_user_id' => $user_id,
    ]);
}
