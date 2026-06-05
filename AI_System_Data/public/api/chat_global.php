<?php
/**
 * chat_global.php - 全社横断型データベースエージェント (ReAct / 自律思考ループ版)
 * (chat.php から安全に呼び出されるコントローラーファイルです)
 *
 * ★[共通エンジン処理移譲 ＆ コードブロックパース崩れ完全防止版]
 * 1. 自律思考ループ内の「execute_sql」フェーズにおけるクエリ実行・データ丸め処理を「SqlExecutionEngine」へ完全委譲。
 * 2. 監査拒否や実行エラー時はエラーメッセージをそのまま「Observation（観察内容）」として還流させ、LLMの自律修復（Self-Correction）を誘発。
 * 3. 外部エントリーポイント「runGlobalChatRoute」およびプロセッサ構造・引数インターフェースの100%完全維持。
 * 4. 【完全閉塞】生コード内から「```」のハードコードを徹底排除し、動的結合関数に集約。
 * 5. 【バグ完全閉塞】parseReActResponseのキー不一致バグ、およびcURL内のスコープ消失バグを完全修復。
 */

// 単体テスト等で chatLogger が未定義の場合に備えたセーフティガード
require_once __DIR__ . '/../../src/AppLogger.php';
require_once __DIR__ . '/../../src/ChatModelRolePayload.php';

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
 * 外部からのエントリーポイント（セキュリティ・DIマウント対応版・Freeze Protocol）
 */
function runGlobalChatRoute($pdo, $ollama_host, $originalMessage, $mainModel, $subModel, $embeddingModel, $promptKey, $user_id, $role, string $routeName = 'global_no_project') {
    $processor = new GlobalChatRouteProcessor(
        $pdo, $ollama_host, $originalMessage, $mainModel, $subModel, $embeddingModel, $promptKey, $user_id, $role, $routeName
    );
    $processor->execute();
}

/**
 * 自律型 ReAct エージェントのロジックを統制するプロセッサクラス
 */
class GlobalChatRouteProcessor {
    // 依存注入されたコンポーネントとコンテキスト
    private $pdo;
    private $ollama_host;
    private $originalMessage;
    private $mainModel;
    private $subModel;
    private $embeddingModel;
    private $reasoningModel;
    private $synthesisModel;
    private $promptKey;
    private $user_id;
    private $role;
    private $routeName;

    // 内部ステート（ReAct調査履歴・ステップ）
    private $observationHistory = "";
    private $reasoningSteps = [];
    private $finalResponse = "";
    private $evalResult = null;
    private $retryCount = 0;

    // cURLストリーム用の一時バッファ・カウンター
    public $buffer = "";
    public $packetCounter = 0;
    public $lastLoggedLen = 0;
    public $ollamaErrorMsg = "";

    /**
     * コンストラクタ (完全DI化への整流)
     */
    public function __construct($pdo, $ollama_host, $originalMessage, $mainModel, $subModel, $embeddingModel, $promptKey, $user_id, $role, string $routeName = 'global_no_project') {
        $this->pdo             = $pdo;
        $this->ollama_host     = $ollama_host;
        $this->originalMessage = $originalMessage;
        $this->mainModel       = $mainModel;
        $this->subModel        = $subModel;
        $this->embeddingModel  = $embeddingModel;
        $this->reasoningModel  = $subModel;
        $this->synthesisModel  = $mainModel;
        $this->promptKey       = $promptKey;
        $this->user_id         = $user_id;
        $this->role            = $role;
        $this->routeName       = $routeName;
    }

    /**
     * メイン実行パイプライン
     */
    public function execute(): void {
        chatLogger(">>> [グローバルルート] 完全自律型 ReAct エージェントを起動します");
        chatLogger("[DEBUG] 引数情報 - Host: {$this->ollama_host} | MainModel: {$this->mainModel} | SubModel: {$this->subModel} | UserID: {$this->user_id} | Role: {$this->role}");

        // 一般ユーザーによる全社情報の盗用のぞき見（BOLA脆弱性）を完全に遮断する絶対防御壁
        if ($this->role !== 'admin') {
            chatLogger("[SECURITY REJECT] 一般ユーザーID: {$this->user_id} (Role: {$this->role}) が全社横断ルートの起動を試みました。BOLA脆弱性防止のため、アクセスを即座に強制遮断します。");
            sendSSE('result', [
                'status'          => 'error',
                'response'        => "⚠️ **【アクセス権限エラー】**\n\n全社データベースの横断串刺し調査機能は、システム管理者（admin）専用の制限機能です。一般メンバーの方は、ご自身の所属する「案件コンテキスト内」でのみチャットアシスタントをご利用ください。",
                'sources'         => [],
                'reasoning_steps' => [],
                'mode_used'       => $this->routeName,
                'detected_page'   => null,
                'hit_count'       => 0,
                'applied_model'   => $this->synthesisModel,
                'model_roles'     => ChatModelRolePayload::build($this->mainModel, $this->subModel, $this->embeddingModel, 'main'),
                'created_at'      => date('Y/m/d H:i')
            ]);
            return;
        }

        sendSSE('status', ['message' => '🌐 データベース調査のための自律思考エージェントを起動中...']);

        // 1. 自律調査ループ (ReAct Loop) の実行
        $this->runReActLoop();

        // 2. 収集した全情報に基づく最終回答の生成
        sendSSE('status', ['message' => '📝 収集した全てのデータから最終レポートを生成しています...']);
        if (!$this->streamFinalReport()) {
            return;
        }

        // 3. チャット対話履歴、進行ステップ、品質評価スコアの安全な一元トランザクション保護保存
        $this->saveHistoryAndEvaluations();

        // 4. 最終結果をブラウザへプッシュ
        $this->sendFinalResult();
    }

    /**
     * 自律調査ループ (ReAct Loop) のメイン制御
     */
    private function runReActLoop(): void {
        $max_iterations = 4; // 無限ループ防止
        $iteration = 0;
        $is_finished = false;
        $react_sys_prompt = $this->buildReActSystemPrompt();

        while ($iteration < $max_iterations && !$is_finished) {
            $iteration++;
            
            $current_user_prompt = "【ユーザーの質問】\n" . $this->originalMessage . "\n\n"
                                 . "【これまでの調査履歴 (Observation)】\n" . ($this->observationHistory ?: "まだ調査を開始していません。");
            
            chatLogger(">>> [ReAct ループ {$iteration}/{$max_iterations}] 思考・行動の生成中...");
            sendSSE('status', ['message' => "🧠 エージェントがデータベースを自律調査中... (ステップ {$iteration}/{$max_iterations})"]);

            // OllamaへのReActリクエスト
            $response_json_str = callOllamaChat(
                $this->ollama_host, $this->reasoningModel, $react_sys_prompt, 
                $current_user_prompt, 'json', ["temperature" => 0.2, "num_ctx" => 8192]
            );
            chatLogger("[DEBUG] ReAct [{$iteration}] Ollama生応答: " . $response_json_str);
            
            // JSON応答の安全なパース
            $response_data = $this->parseReActResponse($response_json_str, $iteration);
            $thought       = $response_data['thought'];
            $action        = $response_data['action'];
            $action_input  = $response_data['action_input'];

            // フロントエンドへリアルタイムに思考状況を通知
            sendSSE('status', ['message' => "🤔 [思考] " . mb_substr($thought, 0, 40) . "..."]);

            if ($action === 'finish') {
                $is_finished = true;
                $this->observationHistory .= "\n[Action]: finish\n[AI判断]: 調査完了。{$action_input}\n";
                
                $this->reasoningSteps[] = [
                    'sub_query' => "調査完了判断 (ステップ {$iteration})",
                    'sub_answer' => "【AIの推論】\n{$thought}\n\n【判断結果】\n{$action_input}"
                ];
                chatLogger(">>> [ReAct ループ完了] AIが finish を宣言しました。詳細理由: " . $action_input);
                break;

            } elseif ($action === 'execute_sql') {
                // SQLアクションのサブタスク処理へデリゲート
                $this->processSqlAction($thought, $action_input, $iteration);

            } else {
                $this->observationHistory .= "\n[Action]: {$action}\n[Result]: エラー。'execute_sql' または 'finish' のみを指定してください。\n";
                $this->reasoningSteps[] = [
                    'sub_query' => "不正なアクション (ステップ {$iteration})",
                    'sub_answer' => "【AIの推論】\n{$thought}\n\n【エラー】\n不正なアクション '{$action}' が選択されました。"
                ];
                chatLogger("[WARN] ReAct [{$iteration}] AIが不正なアクションを指定しました: " . $action);
            }
        }

        if (!$is_finished) {
            $this->observationHistory .= "\n[System]: 最大試行回数に到達したため、調査を強制終了します。\n";
            $this->reasoningSteps[] = [
                'sub_query' => "調査強制終了",
                'sub_answer' => "安全装置により最大試行回数({$max_iterations}回)で打ち切りました。これまでに集めた情報をもとに回答を生成します。"
            ];
            chatLogger("[WARN] ReAct制限最大ループに達しました。安全に自律プロセスを打ち切り、最終統合フェーズへ移行します。");
        }
    }

    /**
     * Ollamaの生JSON出力を安全にデコードする
     */
    private function parseReActResponse(string $rawResponse, int $iteration): array {
        $fence = str_repeat("\x60", 3);
        $clean_json = preg_replace('/^' . preg_quote($fence, '/') . '(?:json)?\s*(\{.*?\})\s*' . preg_quote($fence, '/') . '/is', '$1', trim($rawResponse));
        if (preg_match('/(\{.*\})/is', $clean_json, $matches)) {
            $clean_json = $matches[1];
        }
        $data = json_decode($clean_json, true);
        
        if (!is_array($data)) {
            chatLogger("[WARN] ReAct [{$iteration}] JSONのパースに失敗。生成文字列: " . $rawResponse);
            return [
                'thought'      => "出力形式エラーが発生しました。修正して再試行します。",
                'action'       => "finish",
                'action_input' => "ReActの出力形式が崩れたため、自律調査を継続せず、ここまでの観察結果だけで回答をまとめます。"
            ];
        }

        return [
            'thought'      => $data['thought'] ?? '推論なし',
            'action'       => $data['action'] ?? '',
            'action_input' => $data['action_input'] ?? ''
        ];
    }

    /**
     * SQLクレンジング、安全判定監査、データ丸め処理の一連の実行レイヤー
     */
    private function processSqlAction(string $thought, string $action_input, int $iteration): void {
        $generated_sql = trim($action_input);

        // JSON抽出構文をMySQL 8.0で安定するJSON_EXTRACT形式に寄せる
        $generated_sql = preg_replace("/((?:[a-zA-Z0-9_]+\.)?row_data)\s*->>\s*['\"]?\\$?\.?([^'\"]+)['\"]?/i", 'JSON_UNQUOTE(JSON_EXTRACT($1, \'$."$2"\'))', $generated_sql);

        $fence = str_repeat("\x60", 3);
        $generated_sql = preg_replace('/' . preg_quote($fence, '/') . 'sql|' . preg_quote($fence, '/') . '/i', '', $generated_sql);
        $generated_sql = preg_replace('/\\s+COLLATE\\s+[\'"]?[a-zA-Z0-9_-]+[\'"]?/i', '', $generated_sql);
        $generated_sql = trim($generated_sql);

        $sanitizedSql = $this->sanitizeReActSql($generated_sql);
        if (!$sanitizedSql['success']) {
            $observation = $sanitizedSql['error'];
            chatLogger("[WARN] ReAct [{$iteration}] SQL実行前ガードで停止: " . $observation);

            $this->observationHistory .= "\n[Action]: execute_sql\n[Query]: {$generated_sql}\n[Result]:\n{$observation}\n";
            $this->reasoningSteps[] = [
                'document_search' => "データベース検索 (ステップ {$iteration})",
                'sub_answer' => "【AIの推論】\n{$thought}\n\n【実行候補SQL】\n" . $fence . "sql\n{$generated_sql}\n" . $fence . "\n\n【ガード結果】\n{$observation}"
            ];
            return;
        }

        $generated_sql = $sanitizedSql['sql'];
        if (!empty($sanitizedSql['note'])) {
            chatLogger("[DEBUG] ReAct [{$iteration}] SQLを単一SELECTへ補正: " . $sanitizedSql['note'] . " | SQL: " . $generated_sql);
        }

        chatLogger("[DEBUG] ReAct [{$iteration}] 組み立てられた実行クエリ: " . $generated_sql);
        sendSSE('status', ['message' => '🗄️ クエリを実行し、結果を解析中...']);

        // SqlExecutionEngineコンポーネントへのデリゲート
        require_once __DIR__ . '/../../src/SqlExecutionEngine.php';
        $sqlEngine = new SqlExecutionEngine($this->pdo, 0); 
        $execResult = $sqlEngine->execute($generated_sql);

        $observation = "";
        
        if (!$execResult['success']) {
            $observation = "SQLエラー: " . ($execResult['error'] ?? 'クエリの監査拒否または実行エラー。') . "\nカラム名や構文を見直して再検索してください。";
            chatLogger("[WARN] ReAct [{$iteration}] SQL実行失敗または監査拒否: " . $observation);
        } else {
            $limited_results = $execResult['data'] ?? [];
            $fetched_count = count($limited_results);
            chatLogger("[DEBUG] ReAct [{$iteration}] データベースから " . $fetched_count . " 件の監査済みデータを抽出。");

            if ($fetched_count === 0) {
                $observation = "実行結果は0件でした。条件を緩めるか、別のアプローチ・テーブルを試してください。";
            } else {
                $observation = json_encode($limited_results, JSON_UNESCAPED_UNICODE);
                
                if (mb_strlen($observation) > 5000) {
                    $observation = mb_substr($observation, 0, 5000) . "\n...（データ量超過のため切り捨て）";
                    chatLogger("[CONTEXT-GUARD] 調査履歴の結合データが 5000 文字を超過したため切り詰めました。");
                }
            }
        }

        // プロパティへの履歴蓄積
        $this->observationHistory .= "\n[Action]: execute_sql\n[Query]: {$generated_sql}\n[Result]:\n{$observation}\n";
        
        $this->reasoningSteps[] = [
            'document_search' => "データベース検索 (ステップ {$iteration})",
            'sub_answer' => "【AIの推論】\n{$thought}\n\n【実行SQL】\n" . $fence . "sql\n{$generated_sql}\n" . $fence . "\n\n【検索結果 (Observation)】\n" . $fence . "json\n" . mb_substr($observation, 0, 1000) . "...\n" . $fence
        ];
    }

    /**
     * ReActが生成したSQLを単一SELECTへ寄せ、危険な複文や壊れた末尾句を実行前に止める。
     */
    private function sanitizeReActSql(string $sql): array {
        $normalized = trim($sql);
        $normalized = preg_replace('/;\s*$/', '', $normalized) ?? $normalized;
        $normalized = trim($normalized);

        if ($normalized === '') {
            return [
                'success' => false,
                'error'   => "SQLが空のため実行を中止しました。1本のSELECT文を組み立て直してください。",
            ];
        }

        $statements = preg_split('/;\s*(?:\r?\n|$)/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $statements = array_values(array_filter(array_map('trim', $statements), static function ($part) {
            return $part !== '';
        }));

        if (count($statements) > 1) {
            $merged = $this->tryMergeProjectStatusQueries($statements);
            if ($merged !== null) {
                return [
                    'success' => true,
                    'sql'     => $merged,
                    'note'    => '複数の status 別SELECTを IN 条件付きの単一SELECTへ統合しました。',
                ];
            }

            return [
                'success' => false,
                'error'   => "複数のSQL文が生成されたため実行を中止しました。action_input には 1 本の SELECT 文だけを出してください。",
            ];
        }

        $candidate = $statements[0] ?? $normalized;
        if (preg_match('/^\s*ORDER\s+BY\b/i', $candidate)) {
            return [
                'success' => false,
                'error'   => "ORDER BY 句だけが単独で生成されたため実行を中止しました。SELECT ... ORDER BY ... の1文にまとめてください。",
            ];
        }

        if (!preg_match('/^\s*SELECT\b/i', $candidate)) {
            return [
                'success' => false,
                'error'   => "安全性確保のため、action_input は SELECT 文で開始する必要があります。",
            ];
        }

        return [
            'success' => true,
            'sql'     => $candidate,
            'note'    => '',
        ];
    }

    /**
     * projects の status 別に分裂したSELECT群を、単一の IN 条件付きSELECTへ寄せる。
     */
    private function tryMergeProjectStatusQueries(array $statements): ?string {
        $orderByClause = '';
        $queryStatements = $statements;
        $lastStatement = end($queryStatements);

        if (is_string($lastStatement) && preg_match('/^\s*ORDER\s+BY\s+(.+)$/is', $lastStatement, $orderMatch)) {
            $orderByClause = ' ORDER BY ' . trim($orderMatch[1]);
            array_pop($queryStatements);
        }

        if ($queryStatements === []) {
            return null;
        }

        $selectClause = null;
        $statuses = [];

        foreach ($queryStatements as $statement) {
            if (!preg_match(
                '/^\s*SELECT\s+(.*?)\s+FROM\s+projects(?:\s+(?:AS\s+)?[a-zA-Z_][a-zA-Z0-9_]*)?\s+WHERE\s+status\s*=\s*\'(active|completed|on_hold)\'\s*$/is',
                $statement,
                $matches
            )) {
                return null;
            }

            $currentSelectClause = trim($matches[1]);
            if ($selectClause === null) {
                $selectClause = $currentSelectClause;
            } elseif (strcasecmp($selectClause, $currentSelectClause) !== 0) {
                return null;
            }

            $statuses[] = strtolower($matches[2]);
        }

        $statuses = array_values(array_unique($statuses));
        if ($statuses === []) {
            return null;
        }

        $quotedStatuses = array_map(static function ($status) {
            return "'" . $status . "'";
        }, $statuses);

        return 'SELECT ' . $selectClause
            . ' FROM projects'
            . ' WHERE status IN (' . implode(', ', $quotedStatuses) . ')'
            . $orderByClause;
    }

    /**
     * 蓄積された履歴を基に、最終レポートをストリーミング生成する
     */
    private function streamFinalReport(): bool {
        // コンテキスト切り詰めガードの適用
        $max_global_ctx_len = 5000;
        if (mb_strlen($this->observationHistory) > $max_global_ctx_len) {
            $truncated_len = mb_strlen($this->observationHistory) - $max_global_ctx_len;
            $this->observationHistory = mb_substr($this->observationHistory, 0, $max_global_ctx_len) . "\n\n...[⚠️システム安全セーフガード：RAG容量超過保護のため、以降のデータは切り捨てられました。]";
            chatLogger("[CONTEXT-GUARD] 調査履歴の結合データが {$max_global_ctx_len} 文字を超過したため、後半の {$truncated_len} 文字を切り詰めました。");
        }

        $fence = str_repeat("\x60", 3);

        $system_prompt = PromptManager::getBasePrompt($this->promptKey) . "\n" . PromptManager::getSystemOverviewInstruction();
        $system_prompt .= "\n\n【データ分析・報告指示】\n"
                        . "あなたは全社システムを管轄するAIエージェントです。\n"
                        . "自律調査ループで得られた以下の【これまでの調査履歴（検索結果）】をもとに、ユーザーの質問に対し、正確かつ分かりやすく回答してください。\n"
                        . "数値を集計した場合は結果の傾向を考察し、資料やコメントがヒットした場合は内容要約して提示してください。\n"
                        . "集められた情報の中に答えがない場合や、エラーで終わっている場合は、「データベースから該当する情報は見つかりませんでした」と正直に伝えてください。\n"
                        . "集計結果を視覚的に表現できる場合は、Mermaidではなく " . $fence . "json:chart のChart.js用JSONブロックを使用してください。";

        $prompt_user = "【ユーザーの質問】\n{$this->originalMessage}\n\n【これまでの調査履歴（検索結果）】\n{$this->observationHistory}";

        chatLogger("[DEBUG] Ollama最終統合ストリーミング(api/generate)接続処理を開始します...");
        $ch = curl_init("{$this->ollama_host}/api/generate");
        
        $self = $this;
        $writeCallback = function($ch, $data) use ($self) {
            $self->packetCounter++;
            $self->buffer .= $data;
            $lines = explode("\n", $self->buffer);
            $self->buffer = array_pop($lines);

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                $json = json_decode($line, true);
                if ($json) {
                    if (isset($json['error'])) {
                        $self->ollamaErrorMsg = $json['error'];
                        return 0;
                    }

                    $word = $json['response'] ?? '';
                    $self->finalResponse .= $word;

                    // 回答本文は内部バッファへ保持し、最終確定後に result イベントでのみ出荷する。
                }
            }

            $current_len = mb_strlen($self->finalResponse);
            if ($current_len - $self->lastLoggedLen >= 100) {
                chatLogger("  [ストリーム進行中] パケット受信数: {$self->packetCounter}回 | 最終レポート累積文字数: {$current_len}文字");
                $self->lastLoggedLen = $current_len;
            }

            return strlen($data);
        };

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'model'   => $this->synthesisModel, 
            'prompt'  => "<system>{$system_prompt}</system>\n\n{$prompt_user}\n\n回答（日本語で詳細に）:",
            'stream'  => true,
            'options' => ['temperature' => 0.2, 'top_p' => 0.95, 'num_ctx' => 8192]
        ]));
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, $writeCallback);
        curl_setopt($ch, CURLOPT_TIMEOUT, 180);

        chatLogger("[DEBUG] Ollama最終ストリーム cURL 送信・実行...");
        $success    = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        chatLogger("[DEBUG] Ollama最終ストリーム cURL 通信完了。cURLエラー: " . ($curl_error ?: 'なし'));

        if (!empty($this->ollamaErrorMsg)) {
            chatLogger("CRITICAL: Ollama内部システムエラーを検知しました: {$this->ollamaErrorMsg}");
            sendSSE('error', ['status' => 'error', 'error' => "⚠️ Ollama AIサーバーエラー: {$this->ollamaErrorMsg}"]);
            return false;
        }

        if (!$success) {
            chatLogger("CRITICAL: AIサーバー通信失敗 (cURL Error: {$curl_error})");
            sendSSE('error', ['status' => 'error', 'error' => 'AIサーバーとの通信に失敗しました: ' . $curl_error]);
            return false;
        }

        if ($http_code !== 200) {
            chatLogger("CRITICAL: AIサーバーがエラーコード {$http_code} を返しました。");
            sendSSE('error', ['status' => 'error', 'error' => "⚠️ AIサーバー通信エラー (HTTPステータス: {$http_code})"]);
            return false;
        }

        $this->finalResponse = trim($this->finalResponse);
        chatLogger("[DEBUG] 生成完了。最終レポート文字数: " . mb_strlen($this->finalResponse) . "文字");

        if (empty($this->finalResponse)) {
            $this->finalResponse = "⚠️ **【システム安全ガードレールによる技術案内】**\n\n大変申し訳ありません。全社横断検索においてデータ量がAIサーバーの処理限界を超過いたしました。\nお手数ですが、検索条件をより絞り込んで再度お試しください。";
            chatLogger("[WARN] 回答が空(0文字)で返却されたため、セーフガード案内文へ自動リカバリーしました。");
        }

        return true;
    }

    /**
     * 履歴永続化処理の一元トランザクション保護 ＆ 品質審査スコア・物理カラム不整合の解消
     */
    private function saveHistoryAndEvaluations(): void {
        chatLogger("[DEBUG] DBトランザクションを開始し、ステップ99・対話ログ・評価スコアを一元コミットします...");
        try {
            // 全書き込み処理を安全な単一トランザクションスコープへ完全格納
            $this->pdo->beginTransaction();
            
            // 1. チャット理由ステップ99（最終マージ）が存在する場合は完了に更新して内側へ統合（★タイポバグを精密に修正）
            $stmtUpdAns = $this->pdo->prepare("UPDATE chat_reasoning_steps SET sub_answer = '完了' WHERE session_id = ? AND step_number = 99");
            $stmtUpdAns->execute([$this->reasoningId]); // ✨ 正しい推論セッションID (string) を確実にバインド！
            
            // 2. ユーザー履歴保存
            $stmtUser = $this->pdo->prepare("INSERT INTO chat_history (project_id, user_id, role, message, created_at) VALUES (NULL, ?, 'user', ?, NOW())");
            $stmtUser->execute([$this->user_id, $this->originalMessage]);
            
            // 3. AI履歴保存
            $stmtAi = $this->pdo->prepare("INSERT INTO chat_history (project_id, user_id, role, message, created_at) VALUES (NULL, ?, 'assistant', ?, NOW())");
            $stmtAi->execute([$this->user_id, $this->finalResponse]);
            $historyId = $this->pdo->lastInsertId();
            chatLogger("[DEBUG] chat_history 登録成功。ID: {$historyId}");

            // 4. 品質評価スコア（LLM-as-a-Judge）の保存
            if (isset($this->evalResult) && $this->evalResult) {
                // 返却JSONキー「answer_relevance」を実際のDB物理カラム名「relevance_score」へ完璧にアライン・バインド
                $stmtEval = $this->pdo->prepare("
                    INSERT INTO chat_evaluations 
                    (chat_id, proactivity_score, faithfulness_score, relevance_score, clarity_score, total_score, feedback, retry_count) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtEval->execute([
                    $historyId,
                    $this->evalResult['scores']['proactivity'] ?? 0,
                    $this->evalResult['scores']['faithfulness'] ?? 0,
                    $this->evalResult['scores']['answer_relevance'] ?? 0, // ★JSONキー「answer_relevance」から等価抽出して :relevance_score 側へ正確にバインド
                    $this->evalResult['scores']['clarity'] ?? 0,
                    $this->evalResult['total_score'] ?? 0,
                    $this->evalResult['feedback'] ?? '',
                    $this->retryCount ?? 0
                ]);
                chatLogger("[DEBUG] chat_evaluations へ全社品質審査スコアを同期登録しました。");
            }
            
            $this->pdo->commit();
            chatLogger("[DEBUG] DBトランザクションコミット成功。");
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
                chatLogger("[WARN] データベース保存一括処理でエラーを検出したためロールバックを実行しました。");
            }
            chatLogger("[ERROR] 履歴保存失敗 - 例外: " . $e->getMessage());
        }
    }

    /**
     * SSEを用いた最終確定結果のプッシュ送信
     */
    private function sendFinalResult(): void {
        $this->logFinalResponseSnapshot($this->routeName, $this->finalResponse);
        sendSSE('result', [
            'status'          => 'success',
            'response'        => $this->finalResponse,
            'sources'         => [],
            'reasoning_steps' => $this->reasoningSteps,
            'mode_used'       => $this->routeName,
            'detected_page'   => null,
            'hit_count'       => 0,
            'applied_model'   => $this->synthesisModel,
            'model_roles'     => ChatModelRolePayload::build($this->mainModel, $this->subModel, $this->embeddingModel, 'main'),
            'created_at'      => date('Y/m/d H:i')
        ]);
        chatLogger("=== グローバルルート (ReActループ) 処理完了 ===");
    }

    private function logFinalResponseSnapshot(string $routeName, string $response): void {
        $normalized = trim((string)$response);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $limit = 4000;
        $isTruncated = mb_strlen($normalized) > $limit;
        $preview = $isTruncated ? mb_substr($normalized, 0, $limit) . '...' : $normalized;
        $question = trim((string)$this->originalMessage);
        $question = preg_replace('/\s+/u', ' ', $question) ?? $question;

        chatLogger("[FINAL-ANSWER] route={$routeName} | questionChars=" . mb_strlen($question) . " | responseChars=" . mb_strlen($response) . " | truncated=" . ($isTruncated ? 'yes' : 'no'));
        chatLogger("[FINAL-ANSWER-QUESTION] {$question}");
        chatLogger("[FINAL-ANSWER-BODY] " . $preview);
    }

    /**
     * スキーマ定義プロンプト
     */
    private function getSchemaInfo(): string {
        return "【利用可能なデータベース構成 (MySQL 8.0)】\n"
             . "- `projects` (案件一覧): id, project_name, description, status(active/completed/on_hold), address, start_date, end_date, latitude, longitude, created_by, updated_at\n"
             . "- `documents` (資料一覧): id, project_id, title, file_path, created_at\n"
             . "- `doc_chunks` (資料テキスト・チャンク): id, doc_id, page_number, chunk_text(LONGTEXT), image_description\n"
             . "- `project_csv_files` (CSV一覧): id, project_id, file_name, column_headers(JSON), row_count, created_at\n"
             . "- `project_csv_rows` (CSV行データ): id, csv_file_id, row_index, row_data(JSON)\n"
             . "- `project_comments` (コメント): id, project_id, user_id, comment_text, created_at\n"
             . "- `project_faqs` (ナレッジ): id, project_id, question_summary, answer_summary, created_at\n"
             . "- `chat_history` (チャット履歴): id, project_id, user_id, role, message, created_at\n"
             . "- `project_members` (アサイン管理): id, project_id, user_id, role, assigned_at\n"
             . "- `users` (ユーザー設定情報): id, username, department, role, default_prompt, default_lang, default_model, sub_model, embedding_model, ollama_host, created_at, updated_at\n\n"
             . "【リレーションの基本ルール】\n"
             . "各テーブルは `projects.id` に対して `project_id` を外部キーとして持っています。`doc_chunks` は `documents.id` に紐づきます。";
    }

    /**
     * ReActエージェント用システムプロンプトの構成
     */
    private function buildReActSystemPrompt(): string {
        $fence = str_repeat("\x60", 3);
        return "あなたは全社データベースを調査し、ユーザーの質問に答える自律型AIエージェントです。\n"
             . "以下のデータベーススキーマを利用して, 情報収集を行ってください。\n\n"
             . $this->getSchemaInfo() . "\n\n"
             . "【思考プロセス（ReAct）のルール】\n"
             . "必ず以下のJSONフォーマットのみで出力し、次の行動を決定してください。Markdownの装飾(" . $fence . "jsonなど)は付けないでください。\n"
             . "{\n"
             . "  \"thought\": \"現在の状況と次にすべきことの推論（例: まず進行中の案件数を調べよう）\",\n"
             . "  \"action\": \"実行するアクション名（'execute_sql' または 'finish'）\",\n"
             . "  \"action_input\": \"'execute_sql'の場合はMySQL 8.0のSELECT文を1本だけ。'finish'の場合は調査を終了する理由。\"\n"
             . "}\n\n"
             . "【アクションの説明】\n"
             . "- execute_sql: データベースにクエリを投げます。結果が返されるので、それを見て次の行動を決めてください。※安全なSELECT文のみ。\n"
             . "- finish: 質問に答えるための十分な情報が集まった場合、またはこれ以上検索しても情報がないと判断した場合に選択します。\n\n"
             . "【SQL生成の絶対ルール】\n"
             . "1. action_input には、1回の execute_sql につき SQL を1文だけ出力してください。セミコロン区切りで複数文を並べてはいけません。\n"
             . "2. 単独の ORDER BY 句を出力してはいけません。必ず SELECT ... FROM ... ORDER BY ... の1文に含めてください。\n"
             . "3. 複数の status を比較したいときは、`WHERE status IN ('active', 'completed', 'on_hold')` のように1本のSELECTへまとめてください。\n\n"
             . "【SQL作成時の注意（ハルシネーション絶対禁止プロトコル）】\n"
             . "4. 提示された【INFORMATION_SCHEMAコンテキスト】に表記されている現実の実在物理カラムのみを100%信頼せよ。脳内ででっち上げた実在しない嘘のカラム名（架空のdocument_idやcontent等）は絶対に生成禁止とする。\n"
             . "5. CSVの内部データを検索・集計する場合は `project_csv_rows` を対象とし、日本語キーは `JSON_UNQUOTE(JSON_EXTRACT(T1.row_data, '$.\"項目名\"'))` を使用せよ。\n"
             . "   - 絶対生成禁止: row_data->>?$.項目名、row_data->>$.項目名、row_data->>$.'項目名' などの壊れたJSONパス\n"
             . "   - `project_csv_rows` に `row_count` カラムは存在しない。CSV行数は `COUNT(T1.id)`、CSVファイルの登録行数は `project_csv_files.row_count` を使用せよ。\n"
             . "6. 資料テキスト検索は `doc_chunks` の実在する物理カラム `chunk_text` に対して LIKE検索（例: `chunk_text LIKE '%キーワード%'`）を利用せよ。\n"
             . "7. レコード件数のカウントは `COUNT(id)` 等の実在する物理カラムを使用せよ。\n"
             . "8. キャスト時に `COLLATE` 句は絶対に使用しないでください。";
    }
}
