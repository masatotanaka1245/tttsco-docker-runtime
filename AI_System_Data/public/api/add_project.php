<?php
/**
 * add_project.php - プロジェクト（案件）新規登録 API
 * * [仕様]
 * - ログイン済みユーザーのみ実行可能
 * - CSRFトークンの検証を実施
 * - 入力データをバリデーションし、projectsテーブルへ保存
 * - 成功時に作成された project_id を返却
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../src/Auth.php';
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

// 4. バリデーションとデータの正規化
$projectName = isset($input['project_name']) ? trim($input['project_name']) : '';
if (empty($projectName)) {
    echo json_encode(['success' => false, 'error' => '業務名（案件名）は必須です']);
    exit;
}

// 日付や座標、文字列が空の場合はNULLに変換するヘルパー
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
    // 5. データベース保存
    $sql = "INSERT INTO projects (
                project_name, 
                description, 
                start_date, 
                end_date, 
                address, 
                latitude, 
                longitude, 
                created_by,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $projectName,
        $description,
        $startDate,
        $endDate,
        $address,
        $latitude,
        $longitude,
        $_SESSION['user_id']
    ]);

    $newId = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'project_id' => $newId,
        'message' => '案件を登録しました'
    ]);

} catch (PDOException $e) {
    jsonApiError('案件の登録に失敗しました', 500, $e, ['api' => 'add_project']);
}
