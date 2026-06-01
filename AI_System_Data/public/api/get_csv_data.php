<?php
/**
 * get_csv_data.php - 特定のCSVファイルの中身をデータベースから抽出しJSONで返却するAPI
 * 配置場所: public/api/get_csv_data.php
 * * ★[改善点]
 * 1. 司令塔セッションロック早期解放の仕様を完全適用。session_write_close() でデータ抽出中のブロッキングを完全回避。
 * 2. 【セキュリティ超強化】BOLA(ID列挙脆弱性)への対策。他人の案件に紐づくCSVを不正に覗き見できないよう厳格なアサイン権限チェックを追加。
 * 3. 日本語文字化けの防止、および不正な文字列によるJSONパースクラッシュガードレールを統合。
 */

// 不要な警告がJSONレスポンスを破壊するのを防ぐためにバッファリング
ob_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../src/Auth.php';

// 1. 認証チェック
$auth = new Auth($pdo);
if (!$auth->isLoggedIn()) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'ログインが必要です。']);
    exit;
}

// ★セッション情報を安全にローカル退避させ、ロックを即座に解放
$user_id = $_SESSION['user_id'];
$role    = $_SESSION['role'] ?? 'member';

session_write_close();
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

// 2. パラメータ取得と型バリデーション
$csv_file_id = filter_input(INPUT_GET, 'csv_file_id', FILTER_VALIDATE_INT);
if (!$csv_file_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '有効なCSVファイルIDが指定されていません。']);
    exit;
}

try {
    // 3. メタ情報（ヘッダーとファイル名、および所属プロジェクトID）の取得
    $stmtFile = $pdo->prepare("SELECT project_id, file_name, column_headers FROM project_csv_files WHERE id = ?");
    $stmtFile->execute([$csv_file_id]);
    $csvFile = $stmtFile->fetch(PDO::FETCH_ASSOC);

    if (!$csvFile) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => '指定されたCSVファイルが見つかりません。']);
        exit;
    }

    $project_id = $csvFile['project_id'];

    // 4. 【セキュリティ超強化】アサイン権限チェック（BOLA脆弱性の完全閉塞）
    // 一般ユーザー（admin以外）の場合、作成者またはアサインメンバーに限定する
    if ($role !== 'admin') {
        $stmtCheck = $pdo->prepare("
            SELECT COUNT(*) 
            FROM projects p
            LEFT JOIN project_members pm ON p.id = pm.project_id AND pm.user_id = ?
            WHERE p.id = ? AND (p.created_by = ? OR pm.id IS NOT NULL)
        ");
        $stmtCheck->execute([$user_id, $project_id, $user_id]);
        if ($stmtCheck->fetchColumn() == 0) {
            http_response_code(403);
            throw new Exception('Forbidden: このCSVデータが所属する案件へのアクセス権限がありません。');
        }
    }

    // 5. 格納されているすべての行データの取得（インデックス順）
    $stmtRows = $pdo->prepare("SELECT row_data FROM project_csv_rows WHERE csv_file_id = ? ORDER BY row_index ASC");
    $stmtRows->execute([$csv_file_id]);
    $rows = $stmtRows->fetchAll(PDO::FETCH_ASSOC);

    // JSONレコードから復元配列へのパース
    $parsed_rows = [];
    foreach ($rows as $r) {
        $parsed_rows[] = json_decode($r['row_data'], true);
    }

    // 6. 正常レスポンスの出力（日本語のエスケープ化防止 ＆ 破損パケットの救済対応）
    echo json_encode([
        'success'   => true,
        'file_name' => $csvFile['file_name'],
        'headers'   => json_decode($csvFile['column_headers'], true),
        'rows'      => $parsed_rows
    ], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

} catch (Exception $e) {
    // 適切なエラーコードへのマッピング処理
    if (http_response_code() === 200) {
        http_response_code(500);
    }
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}