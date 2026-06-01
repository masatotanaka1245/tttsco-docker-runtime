<?php
/**
 * get_csv_file_id.php - doc_id に紐づく documents レコードの file_path（csv_db_record_X）から、
 * 本来の構造化 project_csv_files.id を逆算抽出して返却する連携API
 * 配置場所: public/api/get_csv_file_id.php
 * * ★[改善点]
 * 1. 司令塔セッションロック早期解放の仕様を完全適用。session_write_close() でデータベースアクセス中の並行ブロッキングを完全回避。
 * 2. 【セキュリティ超強化】BOLA(ID列挙脆弱性)への対策。一般ユーザーが他人の案件に属するdoc_idをリクエストした際、
 * 逆引き情報の漏洩を100%遮断する厳格なアサインメンバーシップ検証層を追加。
 * 3. 正常・例外レスポンス時の日本語文字化け防止および、JSONクラッシュセーフガードを徹底。
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

// 2. パラメータのバリデーション
$doc_id = filter_input(INPUT_GET, 'doc_id', FILTER_VALIDATE_INT);
if (!$doc_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '有効なドキュメントIDが指定されていません。']);
    exit;
}

try {
    // 3. documents レコードから file_path およびアサイン検証のための project_id を取得
    $stmt = $pdo->prepare("SELECT project_id, title, file_path FROM documents WHERE id = ?");
    $stmt->execute([$doc_id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => '指定されたドキュメントが見つかりません。']);
        exit;
    }

    $project_id = $doc['project_id'];

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
            throw new Exception('Forbidden: 指定されたファイルが所属する案件へのアクセス権限がありません。');
        }
    }

    // 5. パスの接頭辞（csv_db_record_）に基づいてCSVファイルかどうかを判定
    if (strpos($doc['file_path'], 'csv_db_record_') === 0) {
        // file_path の末尾から project_csv_files.id を算出
        $csv_file_id = (int)str_replace('csv_db_record_', '', $doc['file_path']);
        
        // 元のクリーンなファイル名を取得（[CSVデータ] 接頭辞のトリミング）
        $file_name = str_replace('[CSVデータ] ', '', $doc['title']);
        
        echo json_encode([
            'success'     => true,
            'is_csv'      => true,
            'csv_file_id' => $csv_file_id,
            'file_name'   => $file_name
        ], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    } else {
        echo json_encode([
            'success' => true,
            'is_csv'  => false
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (Exception $e) {
    if (http_response_code() === 200) {
        http_response_code(500);
    }
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}