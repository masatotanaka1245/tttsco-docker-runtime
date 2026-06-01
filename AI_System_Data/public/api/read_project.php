<?php
/**
 * read_project.php - 単一プロジェクト情報取得 API
 * [仕様]
 * - ログイン済みユーザーのみ実行可能
 * - 指定されたIDの案件詳細情報を projects テーブルから取得
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

// 2. IDの取得とバリデーション
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '案件IDが指定されていないか、形式が不正です']);
    exit;
}

try {
    // 3. データベース検索
    // 作成者名が必要な場合は users テーブルと JOIN しますが、ここでは基本情報を全取得します
    $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
    $stmt->execute([$id]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($project) {
        // 数値・座標などの型を適切に処理して返却
        echo json_encode([
            'success' => true,
            'data' => $project
        ]);
    } else {
        http_response_code(404);
        echo json_encode([
            'success' => false, 
            'error' => '指定された案件が見つかりませんでした'
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'データベースエラーが発生しました: ' . $e->getMessage()
    ]);
}