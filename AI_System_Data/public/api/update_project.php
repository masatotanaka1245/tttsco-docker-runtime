<?php
/**
 * update_project.php - プロジェクト（案件）情報更新 API
 * [仕様]
 * - ログイン済みユーザーのみ実行可能
 * - CSRFトークンの検証を実施
 * - 指定されたIDの案件情報をバリデーションし、projectsテーブルを更新
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
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'リクエストデータが正しくありません']);
    exit;
}

// 4. バリデーション
$id = isset($input['id']) ? filter_var($input['id'], FILTER_VALIDATE_INT) : null;
$projectName = isset($input['project_name']) ? trim($input['project_name']) : '';

if (!$id || empty($projectName)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'IDまたは業務名が不足しています']);
    exit;
}

if (!canManageProject($pdo, (int)$id, (int)$_SESSION['user_id'], $_SESSION['role'] ?? 'user')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'この案件を更新する権限がありません']);
    exit;
}

// 5. データの正規化（空文字列をNULLに変換）
$toNull = function($val) {
    $v = trim((string)$val);
    return ($v === '') ? null : $v;
};

$description = $toNull($input['description'] ?? '');
$startDate   = $toNull($input['start_date'] ?? '');
$endDate     = $toNull($input['end_date'] ?? '');
$address     = $toNull($input['address'] ?? '');
$latitude    = (isset($input['latitude']) && is_numeric($input['latitude'])) ? (float)$input['latitude'] : null;
$longitude   = (isset($input['longitude']) && is_numeric($input['longitude'])) ? (float)$input['longitude'] : null;

try {
    // 6. データベース更新実行
    $sql = "UPDATE projects SET 
                project_name = ?, 
                description = ?, 
                start_date = ?, 
                end_date = ?, 
                address = ?, 
                latitude = ?, 
                longitude = ?,
                updated_at = NOW()
            WHERE id = ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $projectName,
        $description,
        $startDate,
        $endDate,
        $address,
        $latitude,
        $longitude,
        $id
    ]);

    // 更新対象が存在したか確認
    if ($stmt->rowCount() === 0) {
        // データが全く同じで更新されなかった場合も rowCount は 0 になるため、
        // 厳密な存在チェックが必要な場合は別途 SELECT を行う必要がありますが、
        // ここでは「成功」としてレスポンスを返します。
    }

    echo json_encode([
        'success' => true,
        'message' => '案件情報を更新しました'
    ]);

} catch (PDOException $e) {
    jsonApiError('案件情報の更新に失敗しました', 500, $e, ['api' => 'update_project', 'project_id' => $id]);
}
