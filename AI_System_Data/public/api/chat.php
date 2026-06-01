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

    chatLogger("=== 新着チャット受信 | Host: {$ollama_host} | Model: {$selected_model} ===");
    chatLogger("ユーザー: {$username} (ID: {$user_id}) | 案件ID: " . ($project_id ?? 'NULL (汎用)'));
    chatLogger("質問内容: " . mb_substr($message, 0, 50) . (mb_strlen($message) > 50 ? '...' : ''));

    // 🧠 自律型スマート・ルーティング (Smart Routing) ──【ハイブリッド要塞・完全調和版】
    $advanced_reasoning = false;
    $is_analysis_mode   = false;
    
    $complex_pattern  = '/(比較|違い|相違|対比|網羅|分析|解析|詳細|詳しく|まとめ|総括|検討|留意点|評価|影響|検証|整合性|关系|どう違う|解説して)/u';
    $analysis_pattern = '/(集計|何種類|割合|平均|カウント|件数|グラフ|チャート|分布|推移|合計)/u';
    
    // 🔥【絶対防衛線】フロントからの明示的指定、または長文・複雑な質問の場合は「ハイブリッド脳」を絶対最優先する
    if ((isset($input['advanced_reasoning']) && $input['advanced_reasoning'] === true) || 
        preg_match($complex_pattern, $message) || 
        mb_strlen($message) >= 50) {
        
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

    // =========================================================================
    // 4. 回答分岐 ＆ 各コントローラーファイルへの処理移譲
    // =========================================================================
    
    // 全社横断質問の誤判定を100%遮断する最上位ルーティング判定セーフティガード
    $global_cross_pattern = '/(全社|横断|データベース全体|すべての(案件|プロジェクト)|全体を見渡して|全システム|システム全体)/u';

    if (preg_match($global_cross_pattern, $message)) {
        chatLogger("[SMART-ROUTER] 明示的な全社横断キーワードを検出。強制的に「グローバル調査エージェント(ReAct)」をキックします。");
        
        require_once __DIR__ . '/chat_global.php';
        runGlobalChatRoute($pdo, $ollama_host, $message, $reasoning_model, $synthesis_model, $prompt_key, $user_id, $role);

    } elseif ($project_id === null) {
        // ━━━━【全社横断ルート: グローバル・データベース・エージェント】━━━━
        require_once __DIR__ . '/chat_global.php';
        runGlobalChatRoute($pdo, $ollama_host, $message, $reasoning_model, $synthesis_model, $prompt_key, $user_id, $role);

    } elseif ($is_analysis_mode && !$advanced_reasoning) {
        // ━━━━【集計ルート: Text-to-SQL データ分析エージェント】━━━━
        require_once __DIR__ . '/chat_analysis.php';
        runAdvancedReasoningRoute($pdo, $ollama_host, $project_id, $message, $reasoning_model, $prompt_key, $project_context, $history_summary_text, $user_id, $role);

    } elseif ($advanced_reasoning) {
        // ━━━━【重厚ルート: フル思考ハイブリッド・エージェント (RAG & SQL)】━━━━
        require_once __DIR__ . '/chat_advanced.php';
        runAdvancedReasoningRoute($pdo, $ollama_host, $project_id, $message, $search_query, $reasoning_id, $reasoning_model, $synthesis_model, $prompt_key, $project_context, $history_summary_text, $user_id, $role);

    } else {
        // ━━━━【通常ルート: 一問一答型 RAG ストリーミング】━━━━
        require_once __DIR__ . '/chat_normal.php';
        
        $engine       = new EmbeddingEngine($ollama_host, "mxbai-embed-large");
        $vectorSearch = new VectorSearch($pdo);
        
        runNormalStreamingRoute($pdo, $ollama_host, $project_id, $message, $search_query, $reasoning_model, $prompt_key, $project_context, $history_summary_text, $vectorSearch, $engine, $user_id, $role);
    }

} catch (Throwable $e) {
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
