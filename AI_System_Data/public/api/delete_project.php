<?php
/**
 * delete_project.php - プロジェクト（案件）削除 API
 * [仕様]
 * - ログイン済みユーザーのみ実行可能
 * - CSRFトークンの検証を実施
 * - 指定されたIDの案件を削除
 * - 物理削除時、DBの外部キー制約（ON DELETE CASCADE）により関連資料も削除される想定
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/ProjectAccess.php';
require_once __DIR__ . '/../../src/AppLogger.php';

header('Content-Type: application/json; charset=utf-8');

// 1. 認証チェック
$auth = new Auth($pdo);
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'セッションがタイムアウトしました。再ログインしてください。']);
    exit;
}

// 2. CSRFトークンの検証 (support.js の X-CSRF-Token ヘッダーに対応)
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($csrfToken) || $csrfToken !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => '不正なリクエストです（CSRF検証失敗）']);
    exit;
}

// 3. 入力データの取得
$input = json_decode(file_get_contents('php://input'), true);
$id = isset($input['id']) ? filter_var($input['id'], FILTER_VALIDATE_INT) : null;

if (!$id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '削除対象のIDが指定されていないか、形式が不正です']);
    exit;
}

if (!canManageProject($pdo, (int)$id, (int)$_SESSION['user_id'], $_SESSION['role'] ?? 'user')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'この案件を削除する権限がありません']);
    exit;
}

try {
    // 4. データベース削除実行
    // 前のステップで作成した projects テーブル定義（外部キー制約）により、
    // 案件を削除すると関連する資料や履歴が連動して処理される設定になっている必要があります。
    $stmt = $pdo->prepare("DELETE FROM projects WHERE id = ?");
    $stmt->execute([$id]);

    // 実際に削除された行数を確認
    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'error' => '指定された案件が見つからないか、既に削除されています']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => '案件を完全に削除しました'
    ]);

} catch (PDOException $e) {
    jsonApiError('案件の削除に失敗しました', 500, $e, ['api' => 'delete_project', 'project_id' => $id]);
}
