<?php
/**
 * import_postgresql.php - 他のWindowsサーバーのPostgreSQLからデータを取得し、
 * 本システムの構造化データテーブルおよびAIチャット用RAGベクトルデータベースに統合格納するAPI。
 * 配置場所: public/api/import_postgresql.php
 * [安定化改修] 低スペック環境において、Ollamaがパンクするのを防ぐため、バッチ処理単位を5行ごとに縮小しスリープ延長
 * ★[改善] ハードコード撤廃。ユーザー設定からOllamaホストを動的に取得するよう改修
 */

// 大規模なデータインポートを考慮し、タイムアウト制限を無効化
set_time_limit(0);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/EmbeddingEngine.php';
require_once __DIR__ . '/../../src/ModelRoleResolver.php';
require_once __DIR__ . '/../../src/VectorSearch.php';
require_once __DIR__ . '/../../src/ProjectAccess.php';
require_once __DIR__ . '/../../src/AppLogger.php';
require_once __DIR__ . '/../../src/UserSettingsSessionSynchronizer.php';

header('Content-Type: application/json; charset=utf-8');

// 1. 認証チェック
$auth = new Auth($pdo);
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'ログインが必要です。']);
    exit;
}

// セッションロックの即時解放（進行状況監視ポーリングをブロッキングさせないための必須処理）
$sessionId = session_id();
$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'user';
session_write_close();

// ログ環境の設定
$basePath = realpath(__DIR__ . '/../../');
$logDir = $basePath . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}
$progressFile = $logDir . DIRECTORY_SEPARATOR . 'progress_' . $sessionId . '.json';
$debugLog = $logDir . DIRECTORY_SEPARATOR . 'upload_debug.log';
$projectName = 'PostgreSQLデータインポート';

function pgLogger($msg) {
    global $debugLog;
    $ts = date('Y-m-d H:i:s');
    file_put_contents($debugLog, "[$ts] [PG_IMPORT] $msg\n", FILE_APPEND);
}

function updatePgProgress($status, $stage, $current, $total, $message = '', $error = null) {
    global $progressFile, $projectName;
    $progress = ($total > 0) ? min(100, round(($current / $total) * 100)) : 0;
    
    $data = [
        'status' => $status,
        'stage' => $stage,
        'progress' => (int)$progress,
        'current' => (float)$current,
        'total' => (int)$total,
        'message' => $message,
        'error' => $error,
        'estimated_remaining' => null,
        'updated_at' => time(),
        'project_name' => $projectName
    ];
    file_put_contents($progressFile, json_encode($data), LOCK_EX);
}

// 2. CSRFトークンの検証
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => '不正なリクエストです（CSRF検証失敗）']);
    exit;
}

// 3. パラメータの取得とバリデーション
$input = json_decode(file_get_contents('php://input'), true);
$project_id = filter_var($input['project_id'] ?? 0, FILTER_VALIDATE_INT);
$pg_host    = trim($input['pg_host'] ?? '');
$pg_port    = filter_var($input['pg_port'] ?? 5432, FILTER_VALIDATE_INT);
$pg_dbname  = trim($input['pg_dbname'] ?? '');
$pg_user    = trim($input['pg_user'] ?? '');
$pg_pass    = trim($input['pg_pass'] ?? '');
$pg_query   = trim($input['pg_query'] ?? '');
$file_name  = trim($input['import_name'] ?? '');

if (!$project_id || empty($pg_host) || empty($pg_dbname) || empty($pg_user) || empty($pg_query) || empty($file_name)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '必須パラメータが不足しています。']);
    exit;
}

UserSettingsSessionSynchronizer::sync($pdo, (int)$_SESSION['user_id']);

if (!canAccessProject($pdo, (int)$project_id, $user_id, $role)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'この案件へデータを登録する権限がありません。']);
    exit;
}

// 拡張子 .csv を強制付与してシステム互換性を確保
if (pathinfo($file_name, PATHINFO_EXTENSION) !== 'csv') {
    $file_name .= '.csv';
}

try {
    // 案件名の取得
    $stmtProj = $pdo->prepare("SELECT project_name FROM projects WHERE id = ?");
    $stmtProj->execute([$project_id]);
    if ($projRow = $stmtProj->fetch(PDO::FETCH_ASSOC)) {
        $projectName = $projRow['project_name'];
    }
} catch (Exception $e) {
    pgLogger("プロジェクト名取得エラー: " . $e->getMessage());
}

pgLogger("=== PostgreSQLインポート開始 (接続先: {$pg_host}:{$pg_port} | DB: {$pg_dbname}) ===");
updatePgProgress('processing', 'init', 0, 0, "他のWindowsサーバーのPostgreSQLへ接続を試みています...");

if (!extension_loaded('pdo_pgsql')) {
    $message = 'PHP拡張 pdo_pgsql が有効ではありません。Windows本番環境では php.ini で extension=pdo_pgsql を有効化してください。Docker検証環境ではイメージを再ビルドしてください。';
    pgLogger("CONNECTION ERROR: {$message}");
    updatePgProgress('error', 'missing_driver', 0, 0, $message, 'pdo_pgsql extension is not loaded');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 4. 他のWindowsサーバーのPostgreSQLへリモート接続 (PDOドライバ)
    $pgDsn = "pgsql:host={$pg_host};port={$pg_port};dbname={$pg_dbname};options='--client_encoding=UTF8'";
    $pgPdo = new PDO($pgDsn, $pg_user, $pg_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 15 // 接続タイムアウト15秒
    ]);
    pgLogger("PostgreSQLデータベースへの接続に成功しました。");
} catch (PDOException $e) {
    pgLogger("CONNECTION ERROR: " . $e->getMessage());
    updatePgProgress('error', 'error', 0, 0, "接続失敗", $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '外部PostgreSQLへの接続に失敗しました: ' . $e->getMessage()]);
    exit;
}

try {
    // 5. クエリの実行と全件ロード
    updatePgProgress('processing', 'fetch', 0, 0, "SQLクエリを送信し、データをフェッチしています...");
    pgLogger("実行SQL: [ {$pg_query} ]");
    
    $pgStmt = $pgPdo->prepare($pg_query);
    $pgStmt->execute();
    
    // 全行取得
    $dataset = $pgStmt->fetchAll(PDO::FETCH_ASSOC);
    $total_rows = count($dataset);
    
    pgLogger("データフェッチ完了。レコード数: {$total_rows}件");
    
    if ($total_rows === 0) {
        throw new Exception("クエリの実行結果が0件でした。インポートを中断します。");
    }

    // カラムヘッダー（列名）の抽出
    $headers = array_keys($dataset[0]);
    $column_headers_json = json_encode($headers, JSON_UNESCAPED_UNICODE);
    pgLogger("検出された列ヘッダー: " . $column_headers_json);

    // =========================================================================
    // ★改善: ハードコード撤廃。セッションからOllamaのURLを動的に取得する
    // =========================================================================
    @session_start();
    $resolvedModels = ModelRoleResolver::resolveUserSettings($_SESSION);
    $ollama_host = $resolvedModels['ollama_host'];
    session_write_close();

    // Ollamaベクトル化エンジンの初期化（num_gpu最適化済みのエンジンを利用）
    $engine = new EmbeddingEngine($ollama_host, $resolvedModels['embedding_model']);

    // 指数バックオフ付きEmbeddingヘルパー
    $embedWithRetry = function($text, $row_idx) use ($engine) {
        $max_retries = 5;
        $delay = 1;
        for ($i = 0; $i < $max_retries; $i++) {
            try {
                return $engine->embed(mb_substr($text, 0, 300));
            } catch (Exception $e) {
                if ($i === $max_retries - 1) throw $e;
                pgLogger("[Ollama-Embeddingリトライ] 行: {$row_idx} | 試行: " . ($i + 1) . " | 待機: {$delay}s | Error: " . $e->getMessage());
                sleep($delay);
                $delay *= 2;
            }
        }
        return null;
    };

    // 6. DBトランザクション開始 (不整合を防止)
    $pdo->beginTransaction();
    pgLogger("ローカルMySQL・トランザクションを開始しました。");

    // ── A. CSVメタデータ管理テーブルへの登録 ──
    $stmtFile = $pdo->prepare("
        INSERT INTO project_csv_files (project_id, file_name, column_headers, created_at) 
        VALUES (?, ?, ?, NOW())
    ");
    $stmtFile->execute([$project_id, $file_name, $column_headers_json]);
    $csv_file_id = $pdo->lastInsertId();
    pgLogger("project_csv_files への登録完了 (ID: {$csv_file_id})");

    // ── B. RAG検索親データとして documents テーブルへ登録 ──
    $stmtDoc = $pdo->prepare("
        INSERT INTO documents (project_id, title, file_path, created_at) 
        VALUES (?, ?, ?, NOW())
    ");
    $doc_title = "[CSVデータ] " . $file_name;
    $stmtDoc->execute([$project_id, $doc_title, 'csv_db_record_' . $csv_file_id]);
    $document_id = $pdo->lastInsertId();
    pgLogger("documents RAG親ノード登録完了 (ID: {$document_id})");

    // 各行を順次パース、インサート、セマンティック翻訳、ベクトル化
    $insert_rows_stmt = $pdo->prepare("
        INSERT INTO project_csv_rows (csv_file_id, row_index, row_data, created_at) 
        VALUES (?, ?, ?, NOW())
    ");

    $insert_chunk_stmt = $pdo->prepare("
        INSERT INTO doc_chunks (doc_id, page_number, chunk_text, embedding, image_description)
        VALUES (?, ?, ?, ?, ?)
    ");

    $row_index = 1;
    foreach ($dataset as $row_assoc) {
        // トリミングとクレンジング
        $cleaned_assoc = [];
        foreach ($row_assoc as $key => $val) {
            $cleaned_assoc[trim($key)] = ($val !== null) ? trim((string)$val) : '';
        }

        // 1. ローカルMySQLへJSONレコードとして行登録
        $row_json = json_encode($cleaned_assoc, JSON_UNESCAPED_UNICODE);
        $insert_rows_stmt->execute([$csv_file_id, $row_index, $row_json]);

        // 2. セマンティック翻訳文章の自動生成
        $natural_sentences = [];
        foreach ($cleaned_assoc as $col_name => $val) {
            if ($val !== '') {
                // RAGの検索ノイズとDBエラーを防ぐため、1セルあたりの文字数を制限
                $clean_val = mb_strlen($val) > 200 ? mb_substr($val, 0, 200) . '...[省略]' : $val;
                $natural_sentences[] = "{$col_name}は「{$clean_val}」";
            }
        }
        $chunk_text = "CSV「{$file_name}」の第{$row_index}行のデータ：" . implode("、", $natural_sentences) . "です。";

        // データベースの chunk_text カラム容量超過を防ぐための全体文字数制限
        if (mb_strlen($chunk_text) > 4000) {
            $chunk_text = mb_substr($chunk_text, 0, 4000) . '...[以降省略]';
        }

        // 3. ベクトル化 (Ollama呼び出し)
        $vector = $embedWithRetry($chunk_text, $row_index);
        $vector_json = json_encode($vector);

        // 4. RAGインデックス用 doc_chunks への別行格納
        $insert_chunk_stmt->execute([$document_id, 1, $chunk_text, $vector_json, "CSVデータ行レコード"]);

        // =========================================================================
        // ★低スペックPC（VRAM制限環境）向け安定化チューニング
        // 連続でOllamaにリクエストを投げるとパンク（Connection Reset等）するため、
        // 5行ごとにこまめに進捗を保存し、1秒間の長めのスリープ（息継ぎ）を挟みます。
        // =========================================================================
        if ($row_index % 5 === 0) {
            $statusMsg = "({$row_index}/{$total_rows}行目) データをセマンティック翻訳 ＆ RAGベクトル格納中...";
            updatePgProgress('processing', 'storing', $row_index, $total_rows, $statusMsg);
            
            // AIサーバーへの負荷集中を防ぐため、5件ごとに1秒間の息継ぎを入れる
            usleep(1000000);
        }

        $row_index++;
    }

    // 全行終了後に最終的な進捗を更新
    updatePgProgress('processing', 'storing', $total_rows, $total_rows, "データ格納処理が完了しました。");

    // 総取り込み行数の反映
    $total_rows_imported = $row_index - 1;
    $stmtUpdateCount = $pdo->prepare("UPDATE project_csv_files SET row_count = ? WHERE id = ?");
    $stmtUpdateCount->execute([$total_rows_imported, $csv_file_id]);

    $pdo->commit();
    pgLogger("=== コミット成功: PostgreSQLからのデータ取得 ＆ RAG索引化が完了しました (計 {$total_rows_imported}行) ===");

    // 進捗完了を通知
    updatePgProgress('completed', 'done', $total_rows_imported, $total_rows_imported, 'データの取得とRAGインデックスの構築が完了しました！');

    echo json_encode([
        'success'     => true,
        'message'     => '外部PostgreSQLからのデータインポートおよびRAGベクトル化が完了しました。',
        'file_id'     => $csv_file_id,
        'document_id' => $document_id,
        'total_rows'  => $total_rows_imported
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
        pgLogger("データベースロールバックを実行しました（不整合を防止）");
    }
    
    pgLogger("FATAL ERROR: " . $e->getMessage());

    // エラー進捗の書き出し
    $errorData = [
        'status' => 'error',
        'stage' => 'error',
        'progress' => 0,
        'current' => 0,
        'total' => 0,
        'message' => 'インポート失敗',
        'error' => $e->getMessage(),
        'updated_at' => time(),
        'project_name' => $projectName
    ];
    file_put_contents($progressFile, json_encode($errorData), LOCK_EX);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'インポート処理中に致命的なエラーが発生しました: ' . $e->getMessage()
    ]);
}
?>
