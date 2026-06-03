<?php
// ━━━━【Apache + PHP ストリーミングバッファ決壊（リアルタイム強制流し込み）回路】━━━━
// 1. PHP自体の出力バッファリングを完全に無効化
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', 'off');
@ini_set('implicit_flush', true);
ob_implicit_flush(true);

// 2. すでに開始されているバッファリングがあれば、すべて強制的に決壊・クリア
while (ob_get_level() > 0) {
    ob_end_flush();
}

// 3. ApacheでのGzip圧縮（mod_deflate）によるせき止めを物理的に停止させる特効薬
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
    @apache_setenv('dont-vary', '1');
}

// 4. ブラウザとApacheに対し、これがリアルタイムストリーム（SSE）であることを強烈に宣言
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('X-Accel-Buffering: no'); // Nginx混在環境用の防衛シールド
header('Content-Encoding: none'); // ✨追加：Apacheのmod_deflateによる圧縮せき止めを物理的に停止させる特効薬
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

/**
 * chat.php - RAG対応 AIチャット送受信 ＆ 疑似Server-Sent Events（SSE）リアルタイム配信API (エントリー ＆ ルーター)
 *
 * [仕様]
 * 1. 共通認証・CSRF・ログ・初期化処理の集約
 * 2. 共通ヘルパー（呼出、バルクアグリゲーション、コサイン類似度）の一元定義（二重定義防止ガード付）
 * 3. 自律型スマート・ルーティング（軽量RAG、フル思考、データ分析の自動判定）
 * 4. 専用コントローラーへの安全な処理移譲 (chat_normal.php, chat_advanced.php, chat_analysis.php, chat_global.php)
 * ★ [最優先セーフティガード統合版]
 * - 案件コンテキスト内からの全社串刺し質問のデレードを完全に防止する最上位分岐キーワード検知層を実装.
 */

// ログ出力先を定数として定義（global キーワードの依存を排除）
define('CHAT_DEBUG_LOG', __DIR__ . '/../../logs/chat_debug.log');
require_once __DIR__ . '/../../src/AppLogger.php';

// =========================================================================
// 1. 共通ヘルパー関数・出力制御 の 定義
// =========================================================================

// ❌ 旧40行目〜60行目にあった古い disableObAndStreaming() 関数定義ブロック一式は完全にパージ削除されました

/**
 * chat_debug.log 専用詳細トレースライター
 */
if (!function_exists('chatLogger')) {
    function chatLogger($msg) {
        $message = (string)$msg;
        if (!shouldWriteChatLog($message)) {
            return;
        }
        appLog('chat_debug.log', '[CHAT_STREAM] ' . $message);
    }
}

/**
 * リアルタイムに進捗ステータスや最終結果をブラウザへプッシュ送信（SSE）する
 * ★[Windows Apache バッファ強制粉砕パディング版]
 */
if (!function_exists('sendSSE')) {
    function sendSSE(string $type, array $data) {
        // ✨真犯人を粉砕：Windows Apacheの頑固な4KBバッファを強制的に満たしてブラウザへ即時押し出すダミーコメント
        echo ":" . str_repeat(" ", 4096) . "\n";
        
        // 本物のデータを送信
        echo "data: " . json_encode(array_merge(["type" => $type], $data), JSON_UNESCAPED_UNICODE) . "\n\n";
        
        @ob_flush();
        @flush();
    }
}

/**
 * コサイン類似度の計算ヘルパー
 */
if (!function_exists('calculateCosineSimilarity')) {
    function calculateCosineSimilarity(array $vecA, array $vecB): float {
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        foreach ($vecA as $i => $valA) {
            $valB = $vecB[$i] ?? 0;
            $dotProduct += $valA * $valB;
            $normA += $valA * $valA;
            $normB += $valB * $valB;
        }
        if ($normA == 0 || $normB == 0) return 0.0;
        return $dotProduct / (sqrt($normA) * sqrt($normB));
    }
}

if (!function_exists('normalizeCsvRouteText')) {
    function normalizeCsvRouteText(string $text): string {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/\.(csv|tsv)$/iu', '', $text);
        $text = preg_replace('/[\s　「」『』【】\\[\\]（）()、。,.，．:：;；!！?？#]+/u', '', $text);
        return trim((string)$text);
    }
}

if (!function_exists('buildCsvRouteTextVariants')) {
    function buildCsvRouteTextVariants(string $text): array {
        $variants = [];
        $push = function (string $candidate) use (&$variants): void {
            $normalized = normalizeCsvRouteText($candidate);
            if ($normalized !== '') {
                $variants[$normalized] = true;
            }
        };

        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $push($text);
        $withoutExt = preg_replace('/\.(csv|tsv)$/iu', '', $text);
        $push((string)$withoutExt);

        $baseName = preg_replace('/[（(].*$/u', '', (string)$withoutExt);
        $push(trim((string)$baseName));

        if (preg_match('/^[「『"“](.+)[」』"”]$/u', $text, $matches)) {
            $push($matches[1]);
        }

        return array_keys($variants);
    }
}

if (!function_exists('findMentionedCsvFileName')) {
    function findMentionedCsvFileName(PDO $pdo, int $projectId, string $message): ?string {
        $messageVariants = buildCsvRouteTextVariants($message);
        if (empty($messageVariants)) {
            return null;
        }

        $stmt = $pdo->prepare("SELECT file_name FROM project_csv_files WHERE project_id = ? ORDER BY id DESC");
        $stmt->execute([$projectId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $fileName) {
            $fileName = (string)$fileName;
            $fileVariants = buildCsvRouteTextVariants($fileName);
            foreach ($messageVariants as $messageVariant) {
                foreach ($fileVariants as $fileVariant) {
                    if ($fileVariant === '') {
                        continue;
                    }
                    if (mb_strpos($messageVariant, $fileVariant) !== false || mb_strpos($fileVariant, $messageVariant) !== false) {
                        return $fileName;
                    }
                }
            }
        }

        return null;
    }
}

if (!function_exists('factorizeChatRequest')) {
    function factorizeChatRequest(PDO $pdo, ?int $projectId, string $message): array {
        $mentionedCsv = null;
        if ($projectId !== null) {
            try {
                $mentionedCsv = findMentionedCsvFileName($pdo, (int)$projectId, $message);
            } catch (Throwable $e) {
                $mentionedCsv = null;
            }
        }

        $hasAggregateIntent = preg_match('/(集計|件数|合計|平均|表に|一覧|推移|時系列|別に|グループ|何種類|ユニーク|distinct|重複なし)/iu', $message) === 1;
        $hasSummaryIntent = preg_match('/(要約|まとめ|概要|内容を要約|内容をまとめ|どんな内容|内容を教えて)/u', $message) === 1;
        $hasDateIntent = preg_match('/(日付|日時|年月日|月別|年別|日別|date|timestamp|時刻)/iu', $message) === 1;
        $hasDocReference = preg_match('/(PDF|pdf|資料|図面|仕様書|文書|設計書|報告書)/u', $message) === 1;
        $hasDocActionIntent = preg_match('/(留意点|注意点|確認すべき|確認事項|法規|基準|安全面|設計上|施工前|不明点|見落とし|箇条書きで抽出|箇条書きで|抽出してください)/u', $message) === 1;
        $hasDistinctIntent = preg_match('/(何種類|ユニーク|distinct|重複なし|種類数)/iu', $message) === 1;
        $targetsAllCsv = preg_match('/(全て|すべて|全部|全件)/u', $message) === 1
            && preg_match('/(CSV|csv|ファイル|データ|レコード|行)/u', $message) === 1;

        $intent = 'unknown';
        $target = 'unknown';
        $scope = 'unknown';
        $operation = 'unknown';
        $timeAxis = 'none';
        $outputFormat = preg_match('/(表に|表形式|テーブル|一覧で|一覧にして)/u', $message) === 1 ? 'table' : 'prose';
        $route = null;

        if ($hasAggregateIntent && $hasDateIntent && $mentionedCsv !== null) {
            $intent = 'aggregate';
            $target = 'single_csv';
            $scope = 'records_with_date';
            $operation = 'count';
            $timeAxis = preg_match('/(月別|月ごと)/u', $message) === 1
                ? 'month'
                : (preg_match('/(年別|年ごと)/u', $message) === 1 ? 'year' : 'day');
            $route = 'data_analysis.csv_agg';
        } elseif ($hasAggregateIntent && $hasDateIntent && $targetsAllCsv) {
            $intent = 'aggregate';
            $target = 'all_csv';
            $scope = 'records_with_date';
            $operation = 'count';
            $timeAxis = preg_match('/(月別|月ごと)/u', $message) === 1
                ? 'month'
                : (preg_match('/(年別|年ごと)/u', $message) === 1 ? 'year' : 'day');
            $route = 'data_analysis.csv_agg';
        } elseif ($mentionedCsv !== null && $hasAggregateIntent && $hasDistinctIntent) {
            $intent = 'aggregate';
            $target = 'single_csv';
            $scope = 'file_content';
            $operation = 'distinct_count';
            $route = 'data_analysis.csv_agg';
        } elseif ($hasSummaryIntent && $mentionedCsv !== null) {
            $intent = 'summarize';
            $target = 'single_csv';
            $scope = 'file_content';
            $operation = 'summarize';
            $route = 'data_analysis.csv_summary';
        } elseif ($hasSummaryIntent && $projectId !== null && preg_match('/(CSV|csv|ファイル|データ)/u', $message) === 1) {
            $intent = 'summarize';
            $target = 'all_csv';
            $scope = 'project_wide';
            $operation = 'summarize';
            $route = 'data_analysis.csv_summary';
        } elseif ($projectId !== null && !$targetsAllCsv && $mentionedCsv === null && ($hasDocReference || $hasDocActionIntent)) {
            $intent = 'extract_evidence';
            $target = 'pdf';
            $scope = 'project_wide';
            $operation = 'extract_evidence';
            $outputFormat = preg_match('/(箇条書き|3点|3つ|列挙|リスト)/u', $message) === 1 ? 'bullets' : $outputFormat;
            $route = 'advanced_hybrid.doc_extract';
        }

        return [
            'intent' => $intent,
            'target' => $target,
            'target_file_name' => $mentionedCsv,
            'scope' => $scope,
            'operation' => $operation,
            'time_axis' => $timeAxis,
            'output_format' => $outputFormat,
            'route' => $route,
        ];
    }
}

/**
 * Ollama Chat API を呼び出すヘルパー（VRAM保護最適化 ＆ 思考プロセス抽出対応版）
 */
if (!function_exists('callOllamaChat')) {
    function callOllamaChat($ollamaHost, $model, $system, $user, $format = null, $options = [], &$thoughtProcess = null) {
        $default_options = ["num_ctx" => 4096, "temperature" => 0.1];
        $final_options = array_merge($default_options, $options);

        if (strpos(strtolower($model), 'gemma') !== false) {
            if (strpos($system, '<|think|确') !== 0) {
                $system = "<|think|>\n" . $system;
            }
        }

        $ch = curl_init("{$ollamaHost}/api/chat");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        
        $payload = [
            "model" => $model,
            "messages" => [
                ["role" => "system", "content" => $system],
                ["role" => "user", "content" => $user]
            ],
            "stream" => false,
            "options" => $final_options
        ];
        
        if ($format === 'json') {
            $payload['format'] = 'json';
        }
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 180);
        
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        
        if ($res === false || $code !== 200) {
            throw new Exception("Ollama API 通信エラー (Code: {$code}) / 詳細: {$err}");
        }
        
        $json = json_decode($res, true);
        $content = $json['message']['content'] ?? '';
        
        $thoughtProcess = ""; 
        
        if (preg_match('/<\|channel>thought(.*?)<channel\|>/s', $content, $matches)) {
            $thoughtProcess .= trim($matches[1]) . "\n";
            $content = preg_replace('/<\|channel>thought.*?<channel\|>/s', '', $content);
        }
        if (preg_match('/<think>(.*?)<\/think>/s', $content, $matches)) {
            $thoughtProcess .= trim($matches[1]) . "\n";
            $content = preg_replace('/<think>.*?<\/think>/s', '', $content);
        }
        
        $thoughtProcess = trim($thoughtProcess);
        return trim($content);
    }
}

/**
 * CSV自然言語チャンクの Markdown テーブル自動再集約（VRAM保護版）
 */
if (!function_exists('aggregateCsvChunksToMarkdown')) {
    function aggregateCsvChunksToMarkdown(array $csvChunks, string $fileName): string {
        if (empty($csvChunks)) return "";
        
        $rows = [];
        $all_headers = ['行番号'];
        $current_text_length = 0;
        $max_guard_length = 4000; 
        $truncated_count = 0;
        
        foreach ($csvChunks as $chunk) {
            $text = $chunk['content'] ?? $chunk['chunk_text'] ?? '';
            if (empty($text)) continue;

            $temp_len = mb_strlen($text);
            if (($current_text_length + $temp_len) > $max_guard_length) {
                $truncated_count++;
                continue;
            }

            $cleaned = preg_replace('/^CSV「[^」]+」の第(\d+)行のデータ：/', '', $text);
            $cleaned = preg_replace('/amp;です。$/', '', $cleaned); 
            $cleaned = preg_replace('/です。$/', '', $cleaned);
            
            $row_index_match = [];
            preg_match('/第(\d+)行/', $text, $row_index_match);
            $row_idx = $row_index_match[1] ?? '?';
            
            $row_data = ['行番号' => $row_idx];
            
            $parts = explode('、', $cleaned);
            foreach ($parts as $part) {
                if (preg_match('/^(.+?)は「(.*?)」$/', trim($part), $m)) {
                    $col = trim($m[1]);
                    $val = trim($m[2]);
                    $row_data[$col] = $val;
                    if (!in_array($col, $all_headers)) {
                        $all_headers[] = $col;
                    }
                }
            }
            $rows[] = $row_data;
            $current_text_length += $temp_len;
        }
        
        if (empty($rows)) return "";
        
        $md = "以下の表は、類似検索に合致した「{$fileName}」のデータ行レコード一覧です。\n\n";
        $md .= "| " . implode(" | ", $all_headers) . " |\n";
        $md .= "| " . implode(" | ", array_map(function() { return ":---"; }, $all_headers)) . " |\n";
        
        foreach ($rows as $row) {
            $cols = [];
            foreach ($all_headers as $h) {
                $cell_val = $row[$h] ?? '';
                if (mb_strlen($cell_val) > 50) {
                    $cell_val = mb_substr($cell_val, 0, 50) . '...';
                }
                $cols[] = $cell_val;
            }
            $md .= "| " . implode(" | ", $cols) . " |\n";
        }

        if ($truncated_count > 0) {
            $md .= "\n*（※他、類似スコアの低い {$truncated_count} 件のデータは、AIメモリ保護のため省略されました）*\n";
            $md .= "\n";
            chatLogger("[CONTEXT-GUARD] CSVデータ結合を {$current_text_length} 文字で制限。{$truncated_count} 件を省略。");
        }
        
        return $md;
    }
}

// =========================================================================
// 2. 初期化・認証・セッション情報の確保と解放
// =========================================================================

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/EmbeddingEngine.php';
require_once __DIR__ . '/../../src/VectorSearch.php';
require_once __DIR__ . '/../../src/PromptManager.php';
require_once __DIR__ . '/../../src/ChatRequestGuard.php';

// 認証チェック
$auth = new Auth($pdo);
if (!$auth->isLoggedIn()) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode(['type' => 'result', 'status' => 'error', 'error' => 'セッションが切れました。再ログインしてください。'], JSON_UNESCAPED_UNICODE);
    exit;
}

// セッション情報を安全にローカル退避
$user_id       = $_SESSION['user_id'];
$username      = $_SESSION['username'] ?? 'ゲスト';
$role          = $_SESSION['role'] ?? 'member';
$ollama_host   = rtrim($_SESSION['ollama_host'] ?? 'http://127.0.0.1:11434', '/');
$default_model = $_SESSION['default_model'] ?? 'gemma4:e4b';
$sub_model     = $_SESSION['sub_model'] ?? 'gpt-oss:20b';
$session_csrf  = $_SESSION['csrf_token'] ?? ''; 

// 安全な照合
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($csrfToken) || $csrfToken !== $session_csrf) {
    chatLogger("CRITICAL: CSRF検証に失敗しました。");
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['type' => 'result', 'status' => 'error', 'error' => '安全な通信トークン（CSRF）が確認できません。画面をリロードしてください。'], JSON_UNESCAPED_UNICODE);
    exit;
}

session_write_close();

// =========================================================================
// 3. リクエスト解析・バリデーション・スマートルーティング
// =========================================================================

try {
    $input      = json_decode(file_get_contents('php://input'), true);
    $message    = trim($input['message'] ?? '');
    $project_id = (isset($input['project_id']) && $input['project_id'] !== '') ? filter_var($input['project_id'], FILTER_VALIDATE_INT) : null;
    
    if (empty($message)) {
        throw new Exception('Bad Request: メッセージが空です。');
    }

    $selected_model = $input['model'] ?? $default_model;
    $prompt_key     = $input['prompt_mode'] ?? 'construction_consultant';
    $reasoning_id   = $input['reasoning_id'] ?? ($input['advanced_reasoning_id'] ?? null);
    $report_mode    = (isset($input['report_mode']) && $input['report_mode'] === true);
    $diagram_mode   = (isset($input['diagram_mode']) && $input['diagram_mode'] === true);
    $input_advanced_reasoning = (isset($input['advanced_reasoning']) && $input['advanced_reasoning'] === true);

    chatLogger("=== 新着チャット受信 | Host: {$ollama_host} | Model: {$selected_model} ===");
    chatLogger("ユーザー: {$username} (ID: {$user_id}) | 案件ID: " . ($project_id ?? 'NULL (汎用)'));
    chatLogger("質問内容: " . mb_substr($message, 0, 50) . (mb_strlen($message) > 50 ? '...' : ''));
    chatLogger(
        "[INPUT-MODE] prompt_mode={$prompt_key} | advanced_reasoning=" . ($input_advanced_reasoning ? 'on' : 'off') .
        " | reasoning_id=" . ($reasoning_id ?: 'none') .
        " | report_mode=" . ($report_mode ? 'on' : 'off') .
        " | diagram_mode=" . ($diagram_mode ? 'on' : 'off')
    );
    if ($report_mode || $diagram_mode) {
        chatLogger("[OUTPUT-MODE] report_mode=" . ($report_mode ? 'on' : 'off') . " | diagram_mode=" . ($diagram_mode ? 'on' : 'off'));
    }

    $requestGuard = ChatRequestGuard::inspect($message, $project_id !== null ? (int)$project_id : null, $report_mode, $diagram_mode);
    if (($requestGuard['action'] ?? 'continue') !== 'continue') {
        $routeName = $requestGuard['mode_used'] ?: 'input_guard';
        $routeStart = microtime(true);
        chatLogger("[INPUT-GUARD] action={$requestGuard['action']} | reason={$requestGuard['reason']} | report_mode=" . ($report_mode ? 'on' : 'off') . " | diagram_mode=" . ($diagram_mode ? 'on' : 'off'));
        sendSSE('result', [
            'status' => 'success',
            'response' => $requestGuard['response'],
            'sources' => [],
            'mode_used' => $routeName,
            'detected_page' => null,
            'hit_count' => 0,
            'reasoning_steps' => [],
            'applied_model' => $selected_model,
            'created_at' => date('Y/m/d H:i'),
            'report_document' => null
        ]);
        chatLogger("[SMART-ROUTER] ルート処理完了: {$routeName} | elapsed: " . number_format(microtime(true) - $routeStart, 2) . "秒");
        exit;
    }

    // 🧠 自律型スマート・ルーティング (Smart Routing) ──【ハイブリッド要塞・完全調和版】
    $advanced_reasoning = false;
    $is_analysis_mode   = false;
    $is_history_summary_mode = false;
    $prefer_normal_rag = false;

    $complex_pattern  = '/(比較|違い|相違|対比|網羅|分析|解析|詳細|詳しく|まとめ|総括|検討|留意点|評価|影響|検証|整合性|关系|どう違う|解説して)/u';
    $analysis_pattern = '/(集計|何種類|割合|平均|カウント|件数|グラフ|チャート|分布|推移|合計)/u';
    $csv_evidence_pattern = '/(CSV|csv|登録済み.*データ|データ.*(内容|概要|項目|列|カラム|入って)|列には|カラムには|項目には)/u';
    $history_summary_pattern = '/((これまで|今まで|過去|直近).*(会話|やりとり|チャット|履歴).*(まとめ|要約|整理)|((会話|やりとり|チャット|履歴).*(まとめ|要約|整理)))/u';
    $structured_analysis_pattern = '/(transaction_uid|login_seconds|row_data|APP_\d+|ユーザー.*(操作|時間)|操作.*(時間|秒|秒数)|ログイン秒|利用時間|滞在時間|実行時間)/iu';
    $normal_rag_preferred_pattern = '/(良い案|よい案|方法|支援する方法|設計書案|仕様書案|要件定義|システム.*構築|提案|企画|たたき台|ドラフト)/u';
    $explicit_advanced = $input_advanced_reasoning;
    $factorizedQuery = factorizeChatRequest($pdo, $project_id, $message);
    if (($factorizedQuery['route'] ?? null) !== null) {
        chatLogger("[SMART-ROUTER] 質問因数分解: " . json_encode($factorizedQuery, JSON_UNESCAPED_UNICODE));
    }
    if ($report_mode && $project_id !== null) {
        $explicit_advanced = true;
        chatLogger("[SMART-ROUTER] 報告書モードを検知。PDF生成・検索登録のためフル思考ルートへ寄せます。");
    }

    $dedupeLockFile = null;
    $dedupeLocked = false;
    $dedupeWindowSeconds = 45;
    $dedupeHash = hash('sha256', implode('|', [
        (string)$user_id,
        (string)($project_id ?? 'NULL'),
        mb_strtolower($message, 'UTF-8'),
        (string)$selected_model
    ]));
    $dedupeLockFile = __DIR__ . '/../../logs/chat_request_' . $dedupeHash . '.lock';
    if (is_file($dedupeLockFile)) {
        $lockAge = time() - (int)filemtime($dedupeLockFile);
        if ($lockAge >= 0 && $lockAge < $dedupeWindowSeconds) {
            chatLogger("[SMART-ROUTER] 重複リクエストを抑止しました。hash: {$dedupeHash} | age: {$lockAge}s");
            sendSSE('result', [
                'status' => 'success',
                'response' => '同じ内容のリクエストが直前に送信され、現在処理中です。完了を待ってから再実行してください。',
                'sources' => [],
                'mode_used' => 'duplicate_guard',
                'detected_page' => null,
                'hit_count' => 0,
                'reasoning_steps' => [],
                'applied_model' => $selected_model,
                'created_at' => date('Y/m/d H:i')
            ]);
            exit;
        }
    }
    @file_put_contents($dedupeLockFile, json_encode([
        'started_at' => date('c'),
        'user_id' => $user_id,
        'project_id' => $project_id,
        'model' => $selected_model,
        'message_preview' => mb_substr($message, 0, 120)
    ], JSON_UNESCAPED_UNICODE));
    $dedupeLocked = true;

    $csvSummaryOrAggRoute = in_array(($factorizedQuery['route'] ?? null), ['data_analysis.csv_agg', 'data_analysis.csv_summary'], true);
    $allowCsvRouteOverride = $project_id !== null
        && !$report_mode
        && $csvSummaryOrAggRoute;

    if ($allowCsvRouteOverride && ($factorizedQuery['route'] ?? null) === 'data_analysis.csv_agg') {
        $is_analysis_mode = true;
        chatLogger("[SMART-ROUTER] CSV集計系の質問は軽量分析を優先します。explicit_advanced=" . ($explicit_advanced ? 'on' : 'off') . " | file=" . ($factorizedQuery['target_file_name'] ?? 'all'));

    } elseif ($allowCsvRouteOverride && ($factorizedQuery['route'] ?? null) === 'data_analysis.csv_summary') {
        $is_analysis_mode = true;
        chatLogger("[SMART-ROUTER] CSV要約系の質問は軽量分析を優先します。explicit_advanced=" . ($explicit_advanced ? 'on' : 'off') . " | target=" . ($factorizedQuery['target'] ?? 'unknown'));

    // 🔥【絶対防衛線】フロントから明示指定された場合は「ハイブリッド脳」を最優先する
    } elseif ($explicit_advanced) {
        $advanced_reasoning = true;
        $is_analysis_mode   = false;
        chatLogger("[SMART-ROUTER] フル思考モードの明示指定を検知。ハイブリッド多重推論統合ハブをキックします。");

    } elseif (preg_match($history_summary_pattern, $message)) {
        $is_history_summary_mode = true;
        chatLogger("[SMART-ROUTER] 会話履歴要約要求を検知。軽量履歴サマリールートを優先します。");

    } elseif ($project_id !== null && preg_match($structured_analysis_pattern, $message)) {
        $is_analysis_mode = true;
        chatLogger("[SMART-ROUTER] 構造化データ参照に適した質問を検知。データ分析ルートを優先します。");

    } elseif ($project_id !== null && ($factorizedQuery['route'] ?? null) === 'data_analysis.csv_agg') {
        $is_analysis_mode = true;
        chatLogger("[SMART-ROUTER] 質問因数分解によりCSV集計ルートを優先します。target=" . ($factorizedQuery['target'] ?? 'unknown') . " | file=" . ($factorizedQuery['target_file_name'] ?? 'all'));

    } elseif ($project_id !== null && ($factorizedQuery['route'] ?? null) === 'data_analysis.csv_summary') {
        $is_analysis_mode = true;
        chatLogger("[SMART-ROUTER] 質問因数分解によりCSV要約ルートを優先します。file=" . ($factorizedQuery['target_file_name'] ?? 'unknown'));

    } elseif ($project_id !== null && ($factorizedQuery['route'] ?? null) === 'advanced_hybrid.doc_extract') {
        $advanced_reasoning = true;
        $is_analysis_mode   = false;
        chatLogger("[SMART-ROUTER] 質問因数分解により資料PDF抽出ルートを優先します。target=" . ($factorizedQuery['target'] ?? 'unknown'));

    } elseif ($project_id !== null && preg_match($csv_evidence_pattern, $message)) {
        // PDF/報告書系の資料抽出が明示されている場合は、CSV証拠読解で上書きしない
        $is_analysis_mode = true;
        chatLogger("[SMART-ROUTER] CSV証拠読解に適した質問を検知。CSV全件証拠収集ルートを優先します。");

    } elseif (preg_match($normal_rag_preferred_pattern, $message)) {
        $prefer_normal_rag = true;
        chatLogger("[SMART-ROUTER] 提案・設計書作成系の質問を検知。通常RAGルートを優先します。");

    } elseif (!$prefer_normal_rag && (
        preg_match($complex_pattern, $message) ||
        mb_strlen($message) >= 50)) {

        $advanced_reasoning = true;
        $is_analysis_mode   = false;
        chatLogger("[SMART-ROUTER] 高度なマルチタスク文脈を検知。最優先で「ハイブリッド多重推論統合ハブ(chat_advanced.php Colonial)」をキックします。");
        
    } elseif ($project_id !== null && preg_match($analysis_pattern, $message)) {
        // 純粋な短い集計文（例：「CSVの総件数は？」など）のみ、単発集計ルートへ流す
        $is_analysis_mode = true;
        chatLogger("[SMART-ROUTER] 純粋なデータ集計要求を検知。単発の「データ分析エージェント(chat_analysis.php)」を起動します。");
    }

    if ($advanced_reasoning && empty($reasoning_id)) {
        $reasoning_id = 'auto-' . uniqid('reason_') . '-' . mt_rand(1000, 9999);
        chatLogger("[SMART-ROUTER] 自律生成推論セッションID: {$reasoning_id}");
    }

    $reasoning_model = $selected_model;
    $synthesis_model = $sub_model;

    if ($advanced_reasoning) {
        $selected_model = "{$reasoning_model}(分析) + {$synthesis_model}(統合)"; 
    }

    // 📁 案件コンテキストの構築
    $project_context = "";
    if ($project_id !== null) {
        if ($role !== 'admin') {
            $stmtCheck = $pdo->prepare("
                SELECT COUNT(*) 
                FROM projects p 
                LEFT JOIN project_members pm ON p.id = pm.project_id AND pm.user_id = ?
                WHERE p.id = ? AND (p.created_by = ? OR pm.id IS NOT NULL)
            ");
            $stmtCheck->execute([$user_id, $project_id, $user_id]);
            if ($stmtCheck->fetchColumn() == 0) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(403);
                throw new Exception('Forbidden: 指定されたプロジェクトへのアクセス権限がありません。');
            }
        }

        $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
        $stmt->execute([$project_id]);
        $project_info = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($project_info) {
            $project_context = "【現在の業務背景】\n業務名: {$project_info['project_name']}\n場所: {$project_info['address']}\n概要: " . ($project_info['description'] ?? '特記なし') . "\n";
        }
    }

    $search_query = $message;
    $history_summary_text = "";

    try {
        $historySql = $project_id === null
            ? "SELECT role, message FROM chat_history WHERE project_id IS NULL AND user_id = ? ORDER BY created_at DESC LIMIT 8"
            : "SELECT role, message FROM chat_history WHERE project_id = ? AND user_id = ? ORDER BY created_at DESC LIMIT 8";
        $stmtHistory = $pdo->prepare($historySql);
        $stmtHistory->execute($project_id === null ? [$user_id] : [$project_id, $user_id]);
        $recentHistory = array_reverse($stmtHistory->fetchAll(PDO::FETCH_ASSOC));
        if ($recentHistory) {
            $historyLines = [];
            foreach ($recentHistory as $h) {
                $roleLabel = $h['role'] === 'assistant' ? 'AI' : 'ユーザー';
                $historyLines[] = $roleLabel . ': ' . mb_substr(preg_replace('/\s+/u', ' ', (string)$h['message']), 0, 500);
            }
            $history_summary_text = implode("\n", $historyLines);
        }
    } catch (Throwable $historyEx) {
        chatLogger("[WARN] 会話履歴コンテキスト取得に失敗: " . $historyEx->getMessage());
    }

    if (
        $project_id !== null &&
        !$advanced_reasoning &&
        !$is_history_summary_mode &&
        ($factorizedQuery['route'] ?? null) !== 'advanced_hybrid.doc_extract'
    ) {
        try {
            $mentionedCsv = findMentionedCsvFileName($pdo, (int)$project_id, $message);
            if ($mentionedCsv !== null) {
                $is_analysis_mode = true;
                $prefer_normal_rag = false;
                chatLogger("[SMART-ROUTER] 登録済みCSVファイル名への言及を検知。CSV分析ルートへ切替: {$mentionedCsv}");
            }
        } catch (Throwable $csvRouteEx) {
            chatLogger("[SMART-ROUTER] CSVファイル名ルーティング確認に失敗: " . $csvRouteEx->getMessage());
        }
    }

    // =========================================================================
    // 4. 回答分岐 ＆ 各コントローラーファイルへの処理移譲
    // =========================================================================
    
    // 全社横断質問の誤判定を100%遮断する最上位ルーティング判定セーフティガード
    $global_cross_pattern = '/(全社|横断|データベース全体|すべての(案件|プロジェクト)|全体を見渡して|全システム|システム全体)/u';
    $routeStart = microtime(true);
    $routeName = 'normal_rag';

    if ($is_history_summary_mode) {
        // ━━━━【軽量ルート: 会話履歴サマリー】━━━━
        $routeName = 'history_summary';
        chatLogger("[SMART-ROUTER] 最終ルート決定: {$routeName}");
        require_once __DIR__ . '/chat_history_summary.php';
        runHistorySummaryRoute($pdo, $project_id, $message, $reasoning_model, $prompt_key, $user_id, $role);

    } elseif (preg_match($global_cross_pattern, $message)) {
        $routeName = 'global_cross';
        chatLogger("[SMART-ROUTER] 明示的な全社横断キーワードを検出。強制的に「グローバル調査エージェント(ReAct)」をキックします。");
        chatLogger("[SMART-ROUTER] 最終ルート決定: {$routeName}");

        require_once __DIR__ . '/chat_global.php';
        runGlobalChatRoute($pdo, $ollama_host, $message, $reasoning_model, $synthesis_model, $prompt_key, $user_id, $role);

    } elseif ($project_id === null) {
        // ━━━━【全社横断ルート: グローバル・データベース・エージェント】━━━━
        $routeName = 'global_no_project';
        chatLogger("[SMART-ROUTER] 最終ルート決定: {$routeName}");
        require_once __DIR__ . '/chat_global.php';
        runGlobalChatRoute($pdo, $ollama_host, $message, $reasoning_model, $synthesis_model, $prompt_key, $user_id, $role);

    } elseif ($is_analysis_mode && !$advanced_reasoning) {
        // ━━━━【集計ルート: Text-to-SQL データ分析エージェント】━━━━
        $routeName = 'data_analysis';
        chatLogger("[SMART-ROUTER] 最終ルート決定: {$routeName}");
        require_once __DIR__ . '/chat_analysis.php';
        runAdvancedReasoningRoute($pdo, $ollama_host, $project_id, $message, $reasoning_model, $prompt_key, $project_context, $history_summary_text, $user_id, $role, $report_mode, $diagram_mode);

    } elseif ($advanced_reasoning) {
        // ━━━━【重厚ルート: フル思考ハイブリッド・エージェント (RAG & SQL)】━━━━
        $routeName = 'advanced_hybrid';
        chatLogger("[SMART-ROUTER] 最終ルート決定: {$routeName}");
        require_once __DIR__ . '/chat_advanced.php';
        runAdvancedReasoningRoute($pdo, $ollama_host, $project_id, $message, $search_query, $reasoning_id, $reasoning_model, $synthesis_model, $prompt_key, $project_context, $history_summary_text, $user_id, $role, $report_mode, $diagram_mode);

    } else {
        // ━━━━【通常ルート: 一問一答型 RAG ストリーミング】━━━━
        $routeName = 'normal_rag';
        chatLogger("[SMART-ROUTER] 最終ルート決定: {$routeName}");
        require_once __DIR__ . '/chat_normal.php';

        $engine       = new EmbeddingEngine($ollama_host, "mxbai-embed-large");
        $vectorSearch = new VectorSearch($pdo);
        
        runNormalStreamingRoute($pdo, $ollama_host, $project_id, $message, $search_query, $reasoning_model, $prompt_key, $project_context, $history_summary_text, $vectorSearch, $engine, $user_id, $role, $report_mode, $diagram_mode);
    }

    chatLogger("[SMART-ROUTER] ルート処理完了: {$routeName} | elapsed: " . number_format(microtime(true) - $routeStart, 2) . "秒");

    if ($dedupeLocked && $dedupeLockFile !== null && is_file($dedupeLockFile)) {
        @unlink($dedupeLockFile);
    }

} catch (Throwable $e) {
    if (!empty($routeName) && !empty($routeStart)) {
        chatLogger("[SMART-ROUTER] ルート処理中断: {$routeName} | elapsed: " . number_format(microtime(true) - $routeStart, 2) . "秒");
    }

    if (!empty($dedupeLocked) && !empty($dedupeLockFile) && is_file($dedupeLockFile)) {
        @unlink($dedupeLockFile);
    }

    chatLogger("CRITICAL TRACE: [File] " . $e->getFile() . " [Line] " . $e->getLine() . "行目 [Error] " . $e->getMessage());

    if (headers_sent() || (!empty($input) && !empty($message))) {
        sendSSE('error', [
            'status' => 'error',
            'error'  => "⚠️ 内部サーバーエラーが発生しました。詳細: " . $e->getMessage()
        ]);
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
}
?>
