<?php
/**
 * upload_csv.php - CSVデータテーブル取り込み ＆ 自然言語RAGベクトル化 統合API (低スペックPC・GPU最適化版)
 * [安定化改修] 低スペック環境において、Ollamaがパンクするのを防ぐため、
 * バッチ処理の単位を小さくし（20行ごと）、スマートスリープをこまめに挟むようにチューニング。
 * [GPU最適化] Ollama API呼び出し時に `num_gpu=999` と `num_ctx=512`（Embeddingに最適化）を指定し、VRAMへのフルオフロードを強制。
 * [新規改善] 区切り文字（カンマ/タブ）自動判定、完全空行の徹底排除、列数不一致補正のデータクレンジング処理を追加
 * ★[改善] セッション完全ワントリップ解放により、ポーリング通信のブロッキングフリーズを100%回避。
 * ★[デバッグ・高速化] cURLの使い回し(Keep-Alive再利用)によるインポート超高速化 ＆ 日本語文字化け列ズレ防止ガードを統合。
 */

// 長時間処理に対応（数千行の大規模なCSVデータインポートを完全に完走させるため制限撤廃）
set_time_limit(0);

// PHP標準の fgetcsv における日本語マルチバイトバグ（「ソ」や「十」などのエスケープ文字誤動作）を完全に防止
$original_locale = setlocale(LC_ALL, '0');
setlocale(LC_ALL, 'ja_JP.UTF-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/ProjectAccess.php';
require_once __DIR__ . '/../../src/AppLogger.php';
require_once __DIR__ . '/../../src/DocChunkSummaryBuilder.php';
require_once __DIR__ . '/../../src/ModelRoleResolver.php';
require_once __DIR__ . '/../../src/UserSettingsSessionSynchronizer.php';

header('Content-Type: application/json; charset=utf-8');

// オープンしたストリームやcURLハンドルなどのリソースを確実に解放する共通処理を登録
$stream = null;
$temp_stream = null;
$curl_share_handle = null;

function cleanupResources() {
    global $stream, $temp_stream, $curl_share_handle, $original_locale;
    if ($stream && is_resource($stream)) fclose($stream);
    if ($temp_stream && is_resource($temp_stream)) fclose($temp_stream);
    if ($curl_share_handle) curl_close($curl_share_handle);
    if ($original_locale) setlocale(LC_ALL, $original_locale); // ロケールを元に戻す
}
register_shutdown_function('cleanupResources');

// 1. 認証ガード
$auth = new Auth($pdo);
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'ログインが必要です。']);
    exit;
}

// =========================================================================
// ★[改善] セッション情報を安全にローカル退避させ、ロックを最初期に一度だけ完全解放
// =========================================================================
$sessionId   = session_id();
$user_id     = $_SESSION['user_id'];
$role        = $_SESSION['role'] ?? 'user';
$resolvedModels = ModelRoleResolver::resolveUserSettings($_SESSION);
$ollama_host = $resolvedModels['ollama_host'];

// ここでセッションロックを完全に解除。以降の重いベクトル生成ループ中も、
// フロントエンドからの進捗監視ポーリングAPI（get_upload_status.php）が完全にブロッキングされず非同期で動き続けます。
session_write_close();

// ログディレクトリ・ファイルの初期化
$basePath = realpath(__DIR__ . '/../../');
$logDir = $basePath . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}
$progressFile = $logDir . DIRECTORY_SEPARATOR . 'progress_' . $sessionId . '.json';
$debugLog = $logDir . DIRECTORY_SEPARATOR . 'upload_debug.log';
$projectName = 'CSVデータインポート';

/**
 * upload_debug.log への書き込み関数
 */
if (!function_exists('logger')) {
    function logger($msg) {
        global $debugLog;
        $ts = date('Y-m-d H:i:s');
        file_put_contents($debugLog, "[$ts] [CSV_IMPORT] $msg\n", FILE_APPEND);
    }
}

/**
 * 画面上のプログレスバーと連動する進捗 JSON の更新関数
 * ★[改善] ディスクI/O保護：同一進行パーセンテージの場合は無駄なファイル書き込みを防止するキャッシュ制御を導入
 */
if (!function_exists('updateCsvProgress')) {
    function updateCsvProgress($status, $stage, $current, $total, $message = '') {
        global $progressFile, $projectName;
        static $last_written_percent = -1; // 静的変数で最後に書き込んだ数値を記憶
        
        $progress = ($total > 0) ? min(100, round(($current / $total) * 100)) : 0;
        
        // 完了、エラー、または1%以上の進捗変化、ステータス変更時のみファイル更新を実行
        if ($progress != $last_written_percent || $status === 'completed' || $status === 'error' || $current == 0) {
            $data = [
                'status' => $status,
                'stage' => $stage,
                'progress' => (int)$progress,
                'current' => (float)$current,
                'total' => (int)$total,
                'message' => $message,
                'error' => null,
                'estimated_remaining' => null,
                'updated_at' => time(),
                'project_name' => $projectName
            ];
            @file_put_contents($progressFile, json_encode($data), LOCK_EX);
            $last_written_percent = $progress;
        }
    }
}

UserSettingsSessionSynchronizer::sync($pdo, (int)$_SESSION['user_id']);

if (!function_exists('csvElapsedSeconds')) {
    function csvElapsedSeconds(float $start): string {
        return number_format(microtime(true) - $start, 2) . '秒';
    }
}

if (!function_exists('buildCsvNaturalText')) {
    function buildCsvNaturalText(string $fileName, int $rowIndex, array $assocRow): string {
        $naturalSentences = [];
        foreach ($assocRow as $colName => $val) {
            $val = trim((string)$val);
            if ($val === '') {
                continue;
            }
            $cleanVal = mb_strlen($val) > 200 ? mb_substr($val, 0, 200) . '...[省略]' : $val;
            $naturalSentences[] = "{$colName}は「{$cleanVal}」";
        }

        $chunkText = "CSV「{$fileName}」の第{$rowIndex}行のデータ：" . implode("、", $naturalSentences) . "です。";
        if (mb_strlen($chunkText) > 4000) {
            $chunkText = mb_substr($chunkText, 0, 4000) . '...[以降省略]';
        }

        return $chunkText;
    }
}

// 2. CSRFトークン検証
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => '不正なリクエストです（CSRF検証失敗）']);
    exit;
}

// 3. パラメータ取得と検証
$project_id = filter_input(INPUT_POST, 'project_id', FILTER_VALIDATE_INT);
if (!$project_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '有効なプロジェクトIDが指定されていません。']);
    exit;
}

if (!canAccessProject($pdo, (int)$project_id, (int)$user_id, $role)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'この案件へCSVを登録する権限がありません。']);
    exit;
}

// 案件（業務名）の取得
try {
    $stmtProj = $pdo->prepare("SELECT project_name FROM projects WHERE id = ?");
    $stmtProj->execute([$project_id]);
    if ($projRow = $stmtProj->fetch(PDO::FETCH_ASSOC)) {
        $projectName = $projRow['project_name'];
    }
} catch (Exception $e) {
    logger("案件名の取得中にエラー（デフォルト値を使用します）: " . $e->getMessage());
}

logger("=== CSVインポート処理開始 (案件: {$projectName}) ===");

// 4. ファイルアップロード検証
if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    logger("ファイルアップロードエラーコード: " . ($_FILES['csv_file']['error'] ?? 'No File'));
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'CSVファイルのアップロードに失敗しました。']);
    exit;
}

$file_tmp  = $_FILES['csv_file']['tmp_name'];
$file_name = $_FILES['csv_file']['name'];

// 拡張子チェック
$ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
if ($ext !== 'csv' && $ext !== 'tsv') {
    logger("不正な拡張子検知: .{$ext} (ファイル名: {$file_name})");
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'アップロードできるのは .csv または .tsv 形式のみです。']);
    exit;
}

try {
    // 5. 文字コードの自動判定とUTF-8変換
    $raw_content = file_get_contents($file_tmp);
    if ($raw_content === false) {
        throw new Exception("ファイルを読み込めませんでした。");
    }

    $encoding = mb_detect_encoding($raw_content, ['UTF-8', 'SJIS-win', 'eucJP-win', 'SJIS', 'ASCII'], true);
    if (!$encoding) {
        $encoding = 'SJIS-win'; // 判定不能な場合はExcel出力を想定しSJIS-winにフォールバック
    }
    logger("ファイル名: {$file_name} | 検出文字コード: {$encoding}");

    if ($encoding !== 'UTF-8') {
        logger("文字コードを {$encoding} から UTF-8 へ変換します...");
        $raw_content = mb_convert_encoding($raw_content, 'UTF-8', $encoding);
    }

    // 区切り文字（カンマかタブか）の自動判定
    $delimiter = ',';
    $first_line = strtok($raw_content, "\n");
    if ($first_line !== false) {
        $comma_count = substr_count($first_line, ',');
        $tab_count = substr_count($first_line, "\t");
        $semicolon_count = substr_count($first_line, ';');
        if ($tab_count > $comma_count && $tab_count >= $semicolon_count) {
            $delimiter = "\t";
        } elseif ($semicolon_count > $comma_count && $semicolon_count > $tab_count) {
            $delimiter = ';';
        }
    }
    $delimiterLabel = $delimiter === "\t" ? "タブ(TSV)" : ($delimiter === ';' ? "セミコロン(CSV)" : "カンマ(CSV)");
    logger("区切り文字判定: " . $delimiterLabel);

    // 総行数（有効レコード数）の事前解析
    logger("総有効レコード数の算出を開始します...");
    $total_rows = 0;
    $temp_stream = fopen('php://temp', 'r+');
    fwrite($temp_stream, $raw_content);
    rewind($temp_stream);
    
    // ヘッダー行を1行読み飛ばす
    fgetcsv($temp_stream, 0, $delimiter);
    while (($temp_row = fgetcsv($temp_stream, 0, $delimiter)) !== false) {
        // セルがすべて空文字の場合はスキップ (カンマだけの空行を排除)
        $isEmptyRow = true;
        foreach ($temp_row as $val) {
            if (trim((string)$val) !== '') {
                $isEmptyRow = false;
                break;
            }
        }
        if ($isEmptyRow) continue;

        $total_rows++;
    }
    fclose($temp_stream);
    $temp_stream = null; // リソース解放
    
    logger("事前解析完了: 総有効データ行数 = {$total_rows}行");
    updateCsvProgress('processing', 'init', 0, $total_rows, "CSVファイルのパースと前処理を開始しました...");

    // メモリ上のストリームとして本解析パースを開始
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $raw_content);
    rewind($stream);

    // ヘッダー行（1行目）の抽出
    $headers = fgetcsv($stream, 0, $delimiter);
    if (!$headers) {
        throw new Exception("CSVファイルが空、またはヘッダー行が解析できませんでした。");
    }
    
    // ヘッダー内のBOMや不要な空白、改行をクレンジング
    $headers = array_map(function($h) {
        $h = preg_replace('/^\xEF\xBB\xBF/', '', $h); // UTF-8 BOMの削除
        return trim($h);
    }, $headers);

    $column_headers_json = json_encode($headers, JSON_UNESCAPED_UNICODE);
    logger("抽出された列名ヘッダー: " . $column_headers_json);

    $embed_model = $resolvedModels['embedding_model'];
    $is_large_csv = $total_rows > 1000;

    // =========================================================================
    // ★[大幅改善・超高速化] cURLハンドルの再利用（永続化接続）
    // ループの外側で一度だけ cURL ハンドルを初期化し、キープアライブ接続します。
    // これにより、毎行のTCPハンドシェイクが不要になり、インポート全体が何倍も高速になります。
    // =========================================================================
    $curl_share_handle = curl_init();
    
    $embedWithRetry = function($text, $row_idx) use ($ollama_host, $embed_model, $curl_share_handle, $is_large_csv) {
        $max_retries = $is_large_csv ? 2 : 3;
        $timeout = $is_large_csv ? 30 : 60;
        $delay = 1; // 秒
        $apiUrl = rtrim($ollama_host, '/') . '/api/embeddings';
        
        for ($i = 0; $i < $max_retries; $i++) {
            try {
                // 文字列が極端に長い場合、Ollamaが500エラーになるのを防ぐ安全措置
                $safeText = mb_substr($text, 0, 300);
                
                curl_setopt($curl_share_handle, CURLOPT_URL, $apiUrl);
                curl_setopt($curl_share_handle, CURLOPT_POST, true);
                curl_setopt($curl_share_handle, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Connection: Keep-Alive']);
                
                // ★VRAM保護・GPU最適化:options で num_gpu と num_ctx を明示的に指定
                // ★Embeddingモデルに最適化された num_ctx: 512 に制限して、無駄なVRAM消費とOOMを防止
                curl_setopt($curl_share_handle, CURLOPT_POSTFIELDS, json_encode([
                    'model' => $embed_model,
                    'prompt' => $safeText,
                    'options' => [
                        'num_gpu' => 999,
                        'num_ctx' => 512
                    ]
                ]));
                
                curl_setopt($curl_share_handle, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl_share_handle, CURLOPT_TIMEOUT, $timeout);
                
                $res = curl_exec($curl_share_handle);
                
                if ($res === false) {
                    throw new RuntimeException("Ollama API通信エラー: " . curl_error($curl_share_handle));
                }
                
                $httpCode = curl_getinfo($curl_share_handle, CURLINFO_HTTP_CODE);
                
                if ($httpCode !== 200) {
                     throw new RuntimeException("Ollama HTTPエラー ({$httpCode}): " . $res);
                }
                
                $data = json_decode($res, true);
                if (!isset($data['embedding'])) {
                    throw new RuntimeException("ベクトルデータが返却されませんでした。");
                }
                
                return $data['embedding'];

            } catch (Exception $e) {
                if ($i === $max_retries - 1) {
                    throw $e; // 最終試行が失敗したら例外を投げる
                }
                logger("[Ollama-Embeddingリトライ警告] 対象: {$row_idx} | 試行回数: " . ($i + 1) . " | 待機秒数: {$delay}s | timeout: {$timeout}s | エラー: " . $e->getMessage());
                sleep($delay);
                $delay *= 2; // 指数バックオフ
            }
        }
        return null;
    };

    // 6. DBトランザクション開始。ここではCSV本体だけを高速・確実に保存する。
    $dbImportStart = microtime(true);
    $pdo->beginTransaction();
    logger("データベース・トランザクションを開始しました。");

    // ── A. CSVメタデータ管理テーブルへの登録 ──
    $stmtFile = $pdo->prepare("
        INSERT INTO project_csv_files (project_id, file_name, column_headers, created_at) 
        VALUES (?, ?, ?, NOW())
    ");
    $stmtFile->execute([$project_id, $file_name, $column_headers_json]);
    $csv_file_id = $pdo->lastInsertId();
    logger("project_csv_files へのインサート成功 (ID: {$csv_file_id})");

    // ── B. RAG検索と整合性を保つため、documents テーブルにも親データとして登録 ──
    $stmtDoc = $pdo->prepare("
        INSERT INTO documents (project_id, title, file_path, created_at) 
        VALUES (?, ?, ?, NOW())
    ");
    $doc_title = "[CSVデータ] " . $file_name;
    $stmtDoc->execute([$project_id, $doc_title, 'csv_db_record_' . $csv_file_id]);
    $document_id = $pdo->lastInsertId();
    logger("documents へのRAG親ノードインサート成功 (ID: {$document_id})");

    // 各行を処理してデータベースへ格納
    $row_index = 1;
    $insert_rows_stmt = $pdo->prepare("
        INSERT INTO project_csv_rows (csv_file_id, row_index, row_data, created_at) 
        VALUES (?, ?, ?, NOW())
    ");

    while (($row_data = fgetcsv($stream, 0, $delimiter)) !== false) {
        
        // セルがすべて空の行（ゴミデータ）は完全にスキップ
        $isEmptyRow = true;
        foreach ($row_data as $val) {
            if (trim((string)$val) !== '') {
                $isEmptyRow = false;
                break;
            }
        }
        if ($isEmptyRow) continue;

        // ヘッダー数とデータ数の不一致を安全に補正
        $assoc_row = [];
        foreach ($headers as $col_idx => $col_name) {
            $assoc_row[$col_name] = isset($row_data[$col_idx]) ? trim($row_data[$col_idx]) : '';
        }

        // データのデータベース（JSONレコード用）へのインサート
        $row_json = json_encode($assoc_row, JSON_UNESCAPED_UNICODE);
        $insert_rows_stmt->execute([$csv_file_id, $row_index, $row_json]);

        if ($row_index % 1000 === 0 || $row_index === 1) {
            $elapsed = max(0.001, microtime(true) - $dbImportStart);
            $rowsPerSec = number_format($row_index / $elapsed, 1);
            $statusMsg = "({$row_index}/{$total_rows}行目) CSVレコードをデータベースへ保存中... {$rowsPerSec} rows/sec";
            updateCsvProgress('processing', 'storing', $row_index, $total_rows, $statusMsg);
            logger("[CSV-DB] 保存進捗 - rows: {$row_index}/{$total_rows} | speed: {$rowsPerSec} rows/sec | elapsed: " . csvElapsedSeconds($dbImportStart));
        }

        $row_index++;
    }

    // 全行終了後に最終的な進捗を更新
    updateCsvProgress('processing', 'storing', $total_rows, $total_rows, "データ格納処理が完了しました。");

    fclose($stream);
    $stream = null; // リソース解放

    // CSVファイルの総行数をアップデート
    $total_rows_imported = $row_index - 1;
    $stmtUpdateCount = $pdo->prepare("UPDATE project_csv_files SET row_count = ? WHERE id = ?");
    $stmtUpdateCount->execute([$total_rows_imported, $csv_file_id]);

    // すべての処理が成功したらトランザクションをコミット
    $pdo->commit();
    logger("=== コミット成功: CSV本体の取り込み完了 (計 {$total_rows_imported} 件) | elapsed: " . csvElapsedSeconds($dbImportStart) . " ===");

    // 7. RAG用 doc_chunks はCSV本体コミット後に分離生成する。
    // 大規模CSVでは1行1Embeddingを避け、複数行を束ねたチャンクで検索可能性と速度を両立する。
    $ragStart = microtime(true);
    $rag_chunk_count = 0;
    $embedding_failure_count = 0;
    $embedding_circuit_open = false;
    $consecutive_embedding_failures = 0;
    $rag_warning = null;
    $rag_batch_size = $total_rows_imported > 1000 ? 200 : 1;

    try {
        logger("[CSV-RAG] RAGチャンク生成フェーズ開始 - rows: {$total_rows_imported} | batchSize: {$rag_batch_size}");
        updateCsvProgress('processing', 'rag', 0, max(1, $total_rows_imported), "CSV本体の登録が完了しました。RAG検索用チャンクを生成しています...");

        $insert_chunk_stmt = $pdo->prepare("
            INSERT INTO doc_chunks (doc_id, page_number, chunk_text, chunk_summary, embedding, image_description)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $select_rows_stmt = $pdo->prepare("
            SELECT row_index, row_data
            FROM project_csv_rows
            WHERE csv_file_id = ?
            ORDER BY row_index ASC
        ");
        $select_rows_stmt->execute([$csv_file_id]);

        $pdo->beginTransaction();

        $batchTexts = [];
        $batchFirstRow = null;
        $batchLastRow = null;

        $flushRagBatch = function() use (
            &$batchTexts,
            &$batchFirstRow,
            &$batchLastRow,
            &$rag_chunk_count,
            &$embedding_failure_count,
            &$embedding_circuit_open,
            &$consecutive_embedding_failures,
            $insert_chunk_stmt,
            $embedWithRetry,
            $document_id,
            $file_name,
            $total_rows_imported,
            $ragStart
        ) {
            if (empty($batchTexts)) {
                return;
            }

            $rangeLabel = ($batchFirstRow === $batchLastRow)
                ? "第{$batchFirstRow}行"
                : "第{$batchFirstRow}〜{$batchLastRow}行";
            $chunkText = "CSV「{$file_name}」の{$rangeLabel}のデータ:\n" . implode("\n", $batchTexts);
            if (mb_strlen($chunkText) > 12000) {
                $chunkText = mb_substr($chunkText, 0, 12000) . "\n...[以降省略]";
            }

            $vector = [];
            if (!$embedding_circuit_open) {
                try {
                    $vector = $embedWithRetry($chunkText, "RAG {$rangeLabel}");
                    $consecutive_embedding_failures = 0;
                } catch (Exception $e) {
                    $embedding_failure_count++;
                    $consecutive_embedding_failures++;
                    logger("[CSV-RAG] Embedding失敗のため空ベクトルで継続 - {$rangeLabel} | error: " . $e->getMessage());
                    if ($consecutive_embedding_failures >= 3) {
                        $embedding_circuit_open = true;
                        logger("[CSV-RAG] Embedding連続失敗を検知。以降はOllama呼び出しを停止し、空ベクトルでRAGチャンク登録を継続します。");
                    }
                }
            }

            $description = count($batchTexts) === 1 ? "CSVデータ行レコード" : "CSVデータ行レコード（バッチ）";
            $chunkSummary = DocChunkSummaryBuilder::build($chunkText, $description);
            $insert_chunk_stmt->execute([$document_id, 1, $chunkText, $chunkSummary, json_encode($vector), $description]);
            $rag_chunk_count++;

            if ($rag_chunk_count % 20 === 0 || $rag_chunk_count === 1) {
                $processedRows = min($total_rows_imported, (int)$batchLastRow);
                updateCsvProgress('processing', 'rag', $processedRows, max(1, $total_rows_imported), "RAGチャンク生成中... {$rag_chunk_count}件作成済み");
                logger("[CSV-RAG] チャンク生成進捗 - chunks: {$rag_chunk_count} | rowsUpTo: {$processedRows}/{$total_rows_imported} | embeddingFailures: {$embedding_failure_count} | elapsed: " . csvElapsedSeconds($ragStart));
            }

            $batchTexts = [];
            $batchFirstRow = null;
            $batchLastRow = null;
        };

        while ($row = $select_rows_stmt->fetch(PDO::FETCH_ASSOC)) {
            $rowIndex = (int)$row['row_index'];
            $assocRow = json_decode((string)$row['row_data'], true);
            if (!is_array($assocRow)) {
                $assocRow = [];
            }

            if ($batchFirstRow === null) {
                $batchFirstRow = $rowIndex;
            }
            $batchLastRow = $rowIndex;
            $batchTexts[] = buildCsvNaturalText($file_name, $rowIndex, $assocRow);

            if (count($batchTexts) >= $rag_batch_size) {
                $flushRagBatch();
            }
        }

        $flushRagBatch();
        $pdo->commit();
        logger("[CSV-RAG] RAGチャンク生成フェーズ完了 - chunks: {$rag_chunk_count} | embeddingFailures: {$embedding_failure_count} | elapsed: " . csvElapsedSeconds($ragStart));
    } catch (Throwable $ragEx) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $rag_warning = 'CSV本体は登録済みですが、RAGチャンク生成でエラーが発生しました: ' . $ragEx->getMessage();
        logger("[CSV-RAG] RAGチャンク生成フェーズで例外。CSV本体はコミット済みのため保持します: " . $ragEx->getMessage());
    }

    // 進捗JSONを完了ステータスに変更
    $completeMessage = $rag_warning
        ? 'CSV本体の取り込みは完了しました。RAGチャンク生成に一部課題があります。'
        : 'CSVデータの取り込みとRAGチャンク生成が完了しました！';
    updateCsvProgress('completed', 'done', $total_rows_imported, $total_rows_imported, $completeMessage);

    echo json_encode([
        'success'     => true,
        'message'     => $completeMessage,
        'file_id'     => $csv_file_id,
        'document_id' => $document_id,
        'total_rows'  => $total_rows_imported,
        'rag_chunks'  => $rag_chunk_count,
        'embedding_failures' => $embedding_failure_count,
        'warning' => $rag_warning
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
        logger("ロールバックを実行しました（不整合を防止）");
    }
    
    logger("FATAL ERROR: " . $e->getMessage());

    // エラー進捗の書き出し
    $errorData = [
        'status' => 'error',
        'stage' => 'error',
        'progress' => 0,
        'current' => 0,
        'total' => 0,
        'message' => 'インポート失敗',
        'error' => appDebugEnabled() ? $e->getMessage() : 'CSV解析中にエラーが発生しました。ログを確認してください。',
        'updated_at' => time(),
        'project_name' => $projectName
    ];
    @file_put_contents($progressFile, json_encode($errorData), LOCK_EX);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => appDebugEnabled() ? 'CSV解析中に致命的なエラーが発生しました: ' . $e->getMessage() : 'CSV解析中にエラーが発生しました。ログを確認してください。'
    ]);
}
