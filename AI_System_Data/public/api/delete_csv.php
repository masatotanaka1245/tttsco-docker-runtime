<?php
/**
 * delete_csv.php - 登録済みCSVファイルおよび連動するRAGデータの物理クレンジングAPI
 * 配置場所: public/api/delete_csv.php
 * * ★[改善・セキュリティ強化版]
 * 1. 司令塔セッションロック早期解放の仕様を適用。session_write_close() でトランザクション前のブロッキングを完全回避。
 * 2. 【セキュリティ超強化】BOLA(ID列挙脆弱性)への対策。一般ユーザーが他人の案件のCSVを物理削除できないよう、厳格な権限チェックを追加。
 * 3. 大量データ行のクレンジングによるタイムアウトを防ぐため、実行時間保護と徹底的な監査ログ（upload_debug.log）出力を追加。
 */

// 大量データ削除（CASCADE連動）時のタイムアウトを防止するための安全ガード
set_time_limit(120);

// 不要な出力が偶然交じってJSONレスポンスを破壊するのを防ぐためにバッファリング
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
    echo json_encode(['success' => false, 'error' => 'ログインセッションが切れました。再ログインしてください。']);
    exit;
}

// セッションから必要な情報をローカル退避
$user_id = $_SESSION['user_id'];
$role    = $_SESSION['role'] ?? 'member';

// 2. CSRFトークン検証
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($csrfHeader) || $csrfHeader !== ($_SESSION['csrf_token'] ?? '')) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => '不正なリクエストです（CSRF検証失敗）']);
    exit;
}

// ★[改善] セッション退避完了に伴い、重いデータベース処理が始まる前にセッションロックを即時解放！
// これにより、削除処理中にユーザーが別の案件タブをロードしても画面が砂時計（読み込み中）でフリーズするのを100%回避します。
session_write_close();
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

// ログ出力設定（upload_csv.phpと同期）
$basePath = realpath(__DIR__ . '/../../');
$logDir = $basePath . DIRECTORY_SEPARATOR . 'logs';
$debugLog = $logDir . DIRECTORY_SEPARATOR . 'upload_debug.log';

function deleteLogger($msg) {
    global $debugLog;
    $ts = date('Y-m-d H:i:s');
    @file_put_contents($debugLog, "[$ts] [CSV_CLEANUP] $msg\n", FILE_APPEND);
}

// 3. パラメータの取得と型検証
$input = json_decode(file_get_contents('php://input'), true);
$csv_file_id = isset($input['csv_file_id']) ? filter_var($input['csv_file_id'], FILTER_VALIDATE_INT) : null;

if (!$csv_file_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '無効なCSVファイルIDが指定されています。']);
    exit;
}

try {
    // 4. 【セキュリティ超強化】アサイン権限チェック（BOLA脆弱性シャットアウト）
    // 削除対象のCSVファイルが存在するか、およびその親プロジェクトIDを特定
    $stmtFindFile = $pdo->prepare("SELECT project_id, file_name FROM project_csv_files WHERE id = ?");
    $stmtFindFile->execute([$csv_file_id]);
    $csvFileMeta = $stmtFindFile->fetch(PDO::FETCH_ASSOC);

    if (!$csvFileMeta) {
        http_response_code(404);
        throw new Exception('指定されたCSVファイルデータがシステム上に存在しません。');
    }

    $project_id = $csvFileMeta['project_id'];
    $file_name  = $csvFileMeta['file_name'];

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
            throw new Exception('Forbidden: このCSVデータが所属する案件へのアクセス/削除権限がありません。');
        }
    }

    deleteLogger("CSV物理削除シーケンスを開始します。CSV-ID: {$csv_file_id} | ファイル名: {$file_name} | 案件ID: {$project_id} (実行者ID: {$user_id})");

    // 5. トランザクション開始（完全な不整合防止）
    $pdo->beginTransaction();

    // ── A. documents テーブルからこのCSVに対応するRAGの親レコードを取得して削除 ──
    // upload_csv.php で documents テーブルに 'csv_db_record_{csv_file_id}' として file_path を登録している
    $stmtDoc = $pdo->prepare("SELECT id FROM documents WHERE file_path = ?");
    $stmtDoc->execute(['csv_db_record_' . $csv_file_id]);
    $doc = $stmtDoc->fetch(PDO::FETCH_ASSOC);

    $chunksDeletedCount = 0;
    if ($doc) {
        // RAGベクトル行数（doc_chunks）の確認（ロギング用）
        $stmtCountChunks = $pdo->prepare("SELECT COUNT(*) FROM doc_chunks WHERE doc_id = ?");
        $stmtCountChunks->execute([$doc['id']]);
        $chunksDeletedCount = $stmtCountChunks->fetchColumn();

        // 外部キーの ON DELETE CASCADE 制約により、doc_chunks も自動でデータベースからクレンジング（連動削除）されます
        $stmtDelDoc = $pdo->prepare("DELETE FROM documents WHERE id = ?");
        $stmtDelDoc->execute([$doc['id']]);
        deleteLogger("RAG親ドキュメント (ID: {$doc['id']}) を削除。連動するベクトルデータ ({$chunksDeletedCount} 行) のクレンジングが完了。");
    }

    // ── B. project_csv_files テーブルのレコード削除 ──
    // 物理CSV行データテーブルの削除行数の確認（ロギング用）
    $stmtCountRows = $pdo->prepare("SELECT COUNT(*) FROM project_csv_rows WHERE csv_file_id = ?");
    $stmtCountRows->execute([$csv_file_id]);
    $rowsDeletedCount = $stmtCountRows->fetchColumn();

    // 外部キーの ON DELETE CASCADE により、project_csv_rows の全レコードも自動で連動削除されます
    $stmtDelFile = $pdo->prepare("DELETE FROM project_csv_files WHERE id = ?");
    $stmtDelFile->execute([$csv_file_id]);
    deleteLogger("メタデータ project_csv_files (ID: {$csv_file_id}) を削除。連動するJSON物理レコード ({$rowsDeletedCount} 行) のクレンジングが完了。");

    $pdo->commit();
    deleteLogger("CSV物理削除トランザクションのコミットに成功しました。削除プロセス完了。");

    echo json_encode([
        'success' => true,
        'message' => 'CSVデータテーブルおよび関連するAIチャット用RAGインデックスデータを完全に削除しました。'
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $dbEx) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    deleteLogger("DATABASE FATAL ERROR (ロールバック実行): " . $dbEx->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'データベース処理中に物理クレンジングエラーが発生しました: ' . $dbEx->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    deleteLogger("FATAL EXCEPTION (ロールバック実行): " . $e->getMessage());
    // HTTPステータスコードが未設定（200のまま）の場合は適切なエラーコード（500/403/404）に変更
    if (http_response_code() === 200) {
        http_response_code(500);
    }
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
