<?php
/**
 * read_projects.php - プロジェクト（案件）一覧取得 API
 * [仕様]
 * - ログイン済みユーザーのみ実行可能
 * - 全案件のリストを projects テーブルから取得（更新日時の降順）
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../src/Auth.php';

header('Content-Type: application/json; charset=utf-8');

// 1. 認証チェック
$auth = new Auth($pdo);
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'セッションがタイムアウトしました。再ログインしてください。']);
    exit;
}

try {
    // 2. データベースから全案件を取得
    // 左カラムのリスト表示や地図のプロット用に使用されます
    $sql = "SELECT id, project_name, description, address, latitude, longitude, updated_at 
            FROM projects 
            ORDER BY updated_at DESC";
            
    $stmt = $pdo->query($sql);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. 正常レスポンスの返却
    echo json_encode([
        'success' => true,
        'data' => $projects
    ]);

} catch (PDOException $e) {
    // SQLエラーが発生した場合はステータスコード500でエラー理由を返す
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'データベースエラーが発生しました: ' . $e->getMessage()
    ]);
}