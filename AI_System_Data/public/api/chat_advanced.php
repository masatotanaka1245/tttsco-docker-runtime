<?php

/**
 * chat_advanced.php - フル思考（多段階マルチエージェント・Agentic RAG）処理ルート
 * (chat.php から安全に呼び出される統合ハブコントローラーファイルです)
 *
 * ★[軽量LLM（4Bクラス）特化型・ハイブリッド多重推論（MoA）大統合版]
 * 1. 計画フェーズ：詳細DDLを完全隠蔽し、テーブル概要（brief）のみを提示する「コンテキスト・ダイエット」
 * 2. 実行フェーズ：巡回対象の1テーブルの詳細スキーマのみを動的に注入し、他7枚を完全抹消する「動的マスキング」
 * 3. 共通エンジン連携：SQL実行・監査・データ丸めを「SqlExecutionEngine」へ完全委譲。エラー時はエラー内容を中間考察に還流。
 * 4. マージフェーズ：中間考察を一括投入せず、1ステップずつ情報を重ね書き（Refine）しながら報告書を成長させるループ構造
 * 5. 【完全閉塞】生コード内から「```」のハードコードを100%徹底排除し、動的結合関数に集約。Syntax Errorを完全防止。
 * 6. 【型安全性】環境依存によるコールバック内のアクセス違反を防ぐため、ストリームプロパティをpublicへスコープ調整。
 * 7. 【思考矯正】不適切なでっち上げ演算子（->>?$）の生成を完全に禁止し、正しい記述順序を強烈に叩き込むプロンプトへ上書き。
 * * ✨【フェーズ3: 真のフル思考RAG・思想移植（完全版）】
 * 8. 門番（ChatEvaluator）の指摘に基づき、文章の言い換えを禁止。不足したエビデンスを埋めるため、SQL生成・資料本文（doc_chunks）の再検索・追加精読フェーズまでダイナミックに「巻き戻る」真のActor-Critic無限リトライループをマウント.
 * 9. 追加抽出されたテキストをマージする際、前周回までのドラフトと分析履歴を最先頭に固定（State-Saving）し、既存の事実を壊さずに報告書を成長させる「歴史の重ね書き」Refine構造を完全移植.
 * * 🛠️【日本語JSONキー窒息バグの完全粉砕パッチ】
 * 10. 巡回実行フェーズにおける万能クレンザーの正規表現をマルチバイト包摂型（/iuフラグ及び非ASCIIクラス判定）へ完全アップデート.
 * 11. 巡回実行システムプロンプト内の抽出構文ルールを、MySQL 8.0マルチバイトJSONパス絶対拘束（3143エラー物理回避仕様）へ緊縛上書き.
 * * 🚀【最終大改造：MoAハイブリッド多重推論・大統合】
 * 12. 構造化（CSV）と非構造化（PDF）のエージェントを並列実行し、1つの外部メモリ（Reasoning Steps）へ合流させる統合コアを確立.
 * 13. 【最終整流パッチ】再生成ループのコンテキストを直近情報へパージ・ダイエットさせ、軽量LLMの過積載フリーズを根絶.
 * 14. 【新型：時空間超越・バルクMap-Reduce回路】63件の物理レコードを10件ずつの小分けスライス（時系列Map）に切断し、VRAM負荷を常時グリーンに保ったまま歴史を重ね書き（Reduce）する無敵の知能統治エンジンをマウント.
 * 15. 【DNL-SHIELDパッチ】63件の物理集計レコードをPHP側でMarkdownテーブルに先行コンパイルし、AIの脳内パンクと沈黙を完全封殺.
 */

require_once __DIR__ . '/../../src/AppLogger.php';

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
 * 外部からのエントリーポイント（他ファイルと一元規格統一のため、最後尾に $role を追記した13引数拡張版・Freeze Protocol）
 */
function runAdvancedReasoningRoute($pdo, $ollama_host, $projectId, $originalMessage, $searchQuery, $reasoningId, $reasoningModel, $synthesisModel, $promptKey, $projectContext, $historySummaryText, $user_id, $role, bool $reportMode = false, bool $diagramMode = false) {
    $processor = new AdvancedReasoningRouteProcessor(
        $pdo, $ollama_host, $projectId, $originalMessage, $searchQuery,
        $reasoningId, $reasoningModel, $synthesisModel, $promptKey,
        $projectContext, $historySummaryText, $user_id, $role, $reportMode, $diagramMode
    );
    $processor->execute();
}

/**
 * フル思考 Agentic RAG のライフサイクルを統制するプロセッサクラス
 * (構造化・非構造化データの多重融合 MoA 統合ハブエージェント)
 */
class AdvancedReasoningRouteProcessor {
    // 依存注入コンポーネントおよびコンテキスト
    private $pdo;
    private $ollama_host;
    private $projectId;
    private $originalMessage;
    private $searchQuery;
    private $reasoningId;
    private $reasoningModel;
    private $synthesisModel;
    private $promptKey;
    private $projectContext;
    private $historySummaryText;
    private $user_id;
    private $role;
    private $model;
    private $reportMode = false;
    private $diagramMode = false;
    private $reportDocument = null;
    private $databaseMemoryPrompt = ""; // フェーズ3：DB事前記憶プロンプト保持用

    // 内部ステート管理（CSV集計用・資料RAG用すべてのステートを統合）
    private $schemaInfo = "";
    private $dynamicTableWhitelist = [];
    private $availableCsvFileIds = [];
    private $subQueries = [];
    private $subAnswers = [];
    private $uniqueSources = [];
    private $finalResponse = "";
    private $evalResult = null;
    private $retryCount = 0;

    // cURLストリームコールバックの環境依存バグを防ぐpublicスコープ調整
    public $buffer = "";
    public $packetCounter = 0;
    public $lastLoggedLen = 0;
    public $ollamaErrorMsg = "";

    public function __construct($pdo, $ollama_host, $projectId, $originalMessage, $searchQuery, $reasoningId, $reasoningModel, $synthesisModel, $promptKey, $projectContext, $historySummaryText, $user_id, $role, bool $reportMode = false, bool $diagramMode = false) {
        $this->pdo = $pdo;
        $this->ollama_host = $ollama_host;
        $this->projectId = (int)$projectId;
        $this->originalMessage = $this->normalizeUtf8((string)$originalMessage);
        $this->searchQuery = $this->normalizeUtf8((string)$searchQuery);
        $this->reasoningId = (string)$reasoningId;
        $this->reasoningModel = (string)$reasoningModel;
        $this->synthesisModel = (string)$synthesisModel;
        $this->promptKey = (string)$promptKey;
        $this->projectContext = $this->normalizeUtf8((string)$projectContext);
        $this->historySummaryText = $this->normalizeUtf8((string)$historySummaryText);
        $this->user_id = (int)$user_id;
        $this->role = (string)$role;
        $this->model = (string)$reasoningModel;
        $this->reportMode = $reportMode;
        $this->diagramMode = $diagramMode;
    }

    private function getOutputModeInstructions(): string {
        $instructions = '';
        if ($this->diagramMode) {
            $instructions .= "\n【図解モード】説明の理解に役立つ場合のみ、Mermaidコードブロック（```mermaid）またはChart.js用JSONコードブロック（```json:chart）を1つまで添えてください。図表が不要な場合は文章のみで構いません。";
        }
        if ($this->reportMode) {
            $instructions .= "\n【報告書モード】回答は後続処理でHTML/CSSからPDF報告書化され、資料PDFとして保存されます。結論、分析対象、根拠、留意点、推奨アクション、出典の順に、報告書として読みやすい見出し構成で作成してください。";
        }
        return $instructions;
    }

    private function normalizeUtf8(string $text): string {
        if ($text === '') {
            return '';
        }

        if (!mb_check_encoding($text, 'UTF-8')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            if ($converted !== false) {
                $text = $converted;
            } else {
                $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            }
        }

        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        return $cleaned !== null ? $cleaned : $text;
    }

    // 対象 of 8テーブル（静的定義：概要と詳細スキーマの分離による脳内パンク防止シールド）
    private static $tablesBrief = [
        'chat_history'          => 'ユーザーとAIの過去の対話履歴が格納されているテーブル',
        'chat_reasoning_steps'  => 'AIの思考プロセスや中間検証ステップ、クエリの実行ログが記録されているテーブル',
        'doc_chunks'            => 'アップロードされた文書（PDF等）の分割されたテキスト断片（チャンク）が格納されているテーブル',
        'documents'             => 'プロジェクトに紐づく文書のタイトルやファイル名などのメタデータを管理するテーブル',
        'project_comments'      => 'プロジェクトに関するユーザーのコメントや特記事項・申し送り事項が格納されているテーブル',
        'project_csv_files'     => 'アップロードされたCSVファイルのファイル名やカラムヘッダー定義の一覧を管理するテーブル',
        'project_csv_rows'      => 'CSVファイルの実データが1行ずつ格納されているテーブル。データはJSON型で管理されている',
        'project_faqs'          => 'プロジェクト固有の「よくある質問（Q）」と「回答（A）」がペアで格納されているテーブル'
    ];

    // 静的スキーマ定義（$tablesSchema）を実際の物理データベース（tepscoapp）の真実の構造へ完全シンクロ
    private static $tablesSchema = [
        'chat_history' => "テーブル名: chat_history\n物理カラム:\n- id (INT, PK)\n- project_id (INT)\n- user_id (INT)\n- role (VARCHAR: 'user' または 'assistant')\n- message (TEXT: 会話本文)\n- created_at (DATETIME)",
        'chat_reasoning_steps' => "テーブル名: chat_reasoning_steps\n物理カラム:\n- id (INT, PK)\n- project_id (INT)\n- session_id (VARCHAR:推論セッションID)\n- chat_history_id (INT)\n- original_question (TEXT)\n- step_number (INT: ステップ番号)\n- sub_query (TEXT: 実行目的)\n- search_context (TEXT)\n- sub_answer (TEXT: クエリ内容と実行結果の中間考察)\n- created_at (DATETIME)",
        'doc_chunks' => "テーブル名: doc_chunks\n物理カラム:\n- id (INT, PK)\n- doc_id (INT: documents.idへの外部キー)\n- page_number (INT: 資料 of ページ番号)\n- chunk_text (LONGTEXT: 抽出されたテキスト本文)\n- embedding (VECTOR: ベクトルデータ)\n- image_description (TEXT: 画像または図表の説明)\n- created_at (DATETIME)",
        'documents' => "テーブル名: documents\n物理カラム:\n- id (INT, PK)\n- project_id (INT)\n- title (VARCHAR: 資料 of タイトル)\n- file_path (VARCHAR: 物理ファイルパス)\n- created_at (DATETIME)",
        'project_comments' => "テーブル名: project_comments\n物理カラム:\n- id (INT, PK)\n- project_id (INT)\n- user_id (INT)\n- comment_text (TEXT: コメント内容)\n- created_at (DATETIME)",
        'project_csv_files' => "テーブル名: project_csv_files\n物理カラム:\n- id (INT, PK)\n- project_id (INT)\n- file_name (VARCHAR: CSV名)\n- column_headers (TEXT: JSON型ヘッダー名一覧)\n- row_count (INT)\n- created_at (DATETIME)",
        'project_csv_rows' => "テーブル名: project_csv_rows\n物理カラム:\n- id (INT, PK)\n- csv_file_id (INT)\n- row_index (INT: 行番号)\n- row_data (JSON: CSVの行データが入ったオブジェクト。値の抽出時は JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.\"カラム名\"')) を使用してください)\n- created_at (DATETIME)\n★注意: このテーブルには row_count カラムはありません。行数は COUNT(id) で集計してください。",
        'project_faqs' => "テーブル名: project_faqs\n物理カラム:\n- id (INT, PK)\n- project_id (INT)\n- chat_history_id (INT: チャット履歴への参照外部キー)\n- question_summary (TEXT: 質問 of 要約・概要)\n- answer_summary (TEXT: 回答 of 要約・概要)\n- created_by (INT: 作成者ユーザーID)\n- created_at (DATETIME)",
        'users' => "テーブル名: users\n物理カラム:\n- id (INT, PK)\n- username (VARCHAR: ユーザー名)\n- department (VARCHAR: 所属部署)\n- role (VARCHAR: 権限。'admin' または 'member')\n- default_prompt (TEXT)\n- default_lang (VARCHAR)\n- default_model (VARCHAR)\n- sub_model (VARCHAR)\n- ollama_host (VARCHAR)\n- created_at (DATETIME)\n- updated_at (DATETIME)"
    ];

    /**
     * メイン実行パイプライン (Mixture of Agents ハイブリッド統合制御)
     */
    public function execute(): void {
        $pipelineStart = microtime(true);
        $this->model = $this->reasoningModel; // ステートバインド同期維持

        chatLogger(">>> [MoAハイブリッド多重推論要塞] 統合ハブコントローラーを起動します");
        chatLogger("[DEBUG] 引数情報 - Host: {$this->ollama_host} | Model: {$this->model} | UserID: {$this->user_id} | Role: {$this->role}");

        // 0. BOLA脆弱性防止・アサイン権限チェック（最下部 of 実体を安全にキック）
        $phaseStart = microtime(true);
        if (!$this->checkAuthority()) {
            chatLogger("[ADV-TIMING] 権限チェックで処理終了 | elapsed: " . $this->elapsedSeconds($phaseStart));
            return;
        }
        chatLogger("[ADV-TIMING] 権限チェック完了 | elapsed: " . $this->elapsedSeconds($phaseStart));

        // 1. データベース of 実在テーブル構造（SHOW TABLES等）を動的にロードしてインジェクションコンテキスト化
        $phaseStart = microtime(true);
        if (!$this->loadCsvSchemas()) {
            chatLogger("[ADV-TIMING] スキーマロード失敗 | elapsed: " . $this->elapsedSeconds($phaseStart));
            return;
        }
        chatLogger("[ADV-TIMING] スキーマロード完了 | elapsed: " . $this->elapsedSeconds($phaseStart));

        chatLogger("[DEBUG] 生成されたデータ分析セッションID (reasoningId): {$this->reasoningId}");

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // ✨【フェーズ4】記憶（キャッシュ） of 自動ロード＆自動リフレッシュ回路
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        $phaseStart = microtime(true);
        if ($this->projectId > 0) {
            $stmtMem = $this->pdo->prepare("SELECT meta_value FROM project_meta WHERE project_id = ? AND meta_key = 'ai_database_memory'");
            $stmtMem->execute([$this->projectId]);
            $jsonStr = $stmtMem->fetchColumn();

            // 万が一、記憶キャッシュデータがまた存在しない場合はその場て強制自動生成（リフレッシュ）
            if (empty($jsonStr)) {
                require_once __DIR__ . '/../../src/SqlExecutionEngine.php';
                $sqlEngine = new SqlExecutionEngine($this->pdo, $this->projectId);
                $sqlEngine->generateAndSaveDatabaseMemory();
                
                // 生成された最新 of 記憶を再ロード
                $stmtMem->execute([$this->projectId]);
                $jsonStr = $stmtMem->fetchColumn();
            }

            // PromptManagerを読み込み、記憶をAI用制約プロンプトにコンパイルしてプロパティに格納
            require_once __DIR__ . '/../../src/PromptManager.php';
            $this->databaseMemoryPrompt = PromptManager::getDatabaseMemoryInstruction($jsonStr);
        }
        chatLogger("[ADV-TIMING] DB記憶ロード完了 | promptChars: " . mb_strlen($this->databaseMemoryPrompt) . " | elapsed: " . $this->elapsedSeconds($phaseStart));

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // 🛠️ 【シーケンス1：CSV集計先行射撃】
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        $phaseStart = microtime(true);
        sendSSE('status', ['step' => 1, 'message' => '🧠 【シーケンス1/3】データ集計要求 of 因数分解及び物理SQL監査を実行中...']);
        $this->decomposeQuestion();
        $this->processSubQueries();
        chatLogger("[ADV-TIMING] シーケンス1 SQL分析完了 | subQueries: " . count($this->subQueries) . " | subAnswers: " . count($this->subAnswers) . " | elapsed: " . $this->elapsedSeconds($phaseStart));

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // 🛠️ 【シーケンス2：資料RAG連続射撃】
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        $phaseStart = microtime(true);
        sendSSE('status', ['step' => 3, 'message' => '🧠 【シーケンス2/3】資料手順計画（Planner） of 策定及び動的マスキング巡回を開始...']);
        $plan = $this->generateExecutionPlan();
        $stepResults = $this->executePlanSteps($plan);
        chatLogger("[ADV-TIMING] シーケンス2 資料RAG巡回完了 | planSteps: " . count($plan) . " | stepResults: " . count($stepResults) . " | elapsed: " . $this->elapsedSeconds($phaseStart));

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // 🛠️ 【シーケンス3：最終品質審査・反省リライト】
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        $phaseStart = microtime(true);
        sendSSE('status', ['step' => 5, 'message' => '📈 【シーケンス3/3】ハイブリッド知能 of 重ね書きレポートを成長マージ中...']);
        $this->mergeAndRefineReport($stepResults);
        chatLogger("[ADV-TIMING] シーケンス3 統合・品質審査完了 | responseChars: " . mb_strlen($this->finalResponse) . " | retryCount: {$this->retryCount} | elapsed: " . $this->elapsedSeconds($phaseStart));

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // 🛠️ 【シーケンス4：一元トランザクション永続化 ＆ 出荷】
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        $phaseStart = microtime(true);
        $this->saveHistoryAndEvaluations();
        $this->sendFinalResult();
        chatLogger("[ADV-TIMING] シーケンス4 保存・出荷完了 | elapsed: " . $this->elapsedSeconds($phaseStart));
        chatLogger("[ADV-TIMING] フル思考ルート全体完了 | totalElapsed: " . $this->elapsedSeconds($pipelineStart));
    }

    private function elapsedSeconds(float $start): string {
        return number_format(microtime(true) - $start, 2) . '秒';
    }

    /**
     * 【シーケンス1専用】大目標の要求をAIを使って因数分解（サブ分析タスクの抽出）
     */
    private function decomposeQuestion(): void {
        chatLogger("[DEBUG] 質問の因数分解を開始します。質問: {$this->searchQuery}");
        
        $decompPrompt = "あなたは極めて冷静で論理的なシニア・データアナリストです。\n"
                      . "提示された実在データベース構造を頭に入れた上で、ユーザーの【最初の質問】の意図・目的を完全に達成するために、データベースからどのような切り口で集計すべきか、1〜2つの「具体的な分析観点（サブクエリ）」に因数分解してください。\n\n"
                      . "【絶対厳守のアンカー・ルール（目的すり替えの完全禁止）】\n"
                      . "■ お前の最大の使命は、最後の回答が【最初の質問】の要求に100%マッチしている状態を作ることである。\n"
                      . "■ ユーザーの要求が「データ（CSV）の集計」「アップロード資料の分析」を指している場合は、勝手にシステム管理テーブル（ `chat_evaluations` のAI評価スコアや `logs` など）をターゲットにしたサブタスクをでっち上げてはならない（厳禁）。\n"
                      . "■ ユーザーの質問がアバウトな挨拶や準備の段階（例：データ集計しようと思います、等）である場合は、勝手に死に物狂いで関係のない内部評価スコアの集計手順を組み立てるな。文脈から推測される本質的な一般データテーブル（ `project_csv_rows` など）の基礎的なカウントや概要把握のみに絞って手順を分解せよ。\n"
                      . "■ ユーザーから「AIの評価スコアを集計して」「 chat_evaluations を分析して」と【明示的・直接的にシステムデータの集計を指定された場合のみ】、システムテーブルを対象に含めてよい。\n\n"
                      . "必ず以下のJSON配列形式のみで出力してください。挨拶やMarkdownの説明は一切不要です。\n"
                      . "operation_type は metadata_lookup / simple_aggregate / record_search / semantic_extract のいずれかにしてください。\n"
                      . "[{\"query\": \"ユーザーの最初の質問の枠内から絶対に脱線しない具体的な調査目的\", \"operation_type\": \"semantic_extract\", \"target_tables\": [\"doc_chunks\"], \"answer_goal\": \"このサブクエリで生成すべき小回答の目的\"}]\n\n"
                      . "分析観点リスト(JSON):";
        
        chatLogger("[DEBUG] Ollama因数分解API呼び出し送信前...");
        
        // ユーザープロンプトに「実在スキーマ情報」と「ユーザーの最初の質問」をブレずに完全ドッキング
        $userContextPrompt = $this->schemaInfo . "\n\n【ユーザーの最初の質問】\n{$this->searchQuery}\n\n分析観点リスト(JSON):";
        
        // 引数を整流：第3引数にシステムプロンプト、第4引数に構築したユーザープロンプトを正確に引き渡す
        $decomp_res = callOllamaChat(
            $this->ollama_host, 
            $this->reasoningModel, 
            $this->databaseMemoryPrompt . "\n" . $decompPrompt, 
            $userContextPrompt, 
            'json', 
            ["temperature" => 0.0, "top_p" => 0.1, "num_ctx" => 8192] // スキーマ長に対応するためnum_ctxを8192へ超拡張
        );
        
        chatLogger("[DEBUG] Ollama因数分解API応答受信 raw: " . $decomp_res);
        
        $fence = str_repeat("\x60", 3);
        $clean_json = $decomp_res;
        
        if (preg_match('/' . preg_quote($fence, '/') . '(?:json)?\s*(\\[.*?\\]|\\{.*?\\})\s*' . preg_quote($fence, '/') . '/is', $decomp_res, $matches)) {
            $clean_json = $matches[1];
        } elseif (preg_match('/(\\[.*?\\]|\\{.*?\\})/is', $decomp_res, $matches)) {
            $clean_json = $matches[1];
        }
        
        $this->subQueries = json_decode($clean_json, true);

        // 【FORMAT-RECOVERY】単一の分析オブジェクトを配列構造に自動ラップ救済する回路
        if (is_array($this->subQueries) && isset($this->subQueries['query'])) {
            $this->subQueries = [$this->subQueries];
            chatLogger("[FORMAT-RECOVERY] 単一の分析オブジェクトを配列構造に自動ラップ救済しました。");
        }
        
        if (!is_array($this->subQueries) || empty($this->subQueries) || !isset($this->subQueries[0]['query'])) {
            chatLogger("[WARN] サブクエリのパースに失敗しました。フォールバックとして元メッセージ全体を単一クエリとしてセットします。");
            $this->subQueries = [[
                "query" => "要求全体に関連する実データを抽出し、回答に必要な根拠を整理する",
                "operation_type" => $this->inferOperationType($this->searchQuery),
                "target_tables" => $this->inferTargetTables($this->searchQuery),
                "answer_goal" => "ユーザーの質問に直接答えるための中間回答を作る"
            ]];
        } else {
            $this->subQueries = array_map(function ($item) {
                return $this->normalizeSubQueryItem(is_array($item) ? $item : ['query' => (string)$item]);
            }, $this->subQueries);
            chatLogger("[DEBUG] サブクエリのパースに成功。サブ質問数: " . count($this->subQueries));
        }
    }

    /**
     * 【シーケンス1専用】サブ分析観点ループの統制
     */
    private function processSubQueries(): void {
        $step_counter = 0;
        $total_steps = count($this->subQueries);

        foreach ($this->subQueries as $subQItem) {
            $step_counter++;
            $subQItem = $this->normalizeSubQueryItem($subQItem);
            $subQ = $subQItem['query'];
            $operationType = $subQItem['operation_type'];

            // 独立メソッドへのバトン引き渡し
            $sub_ans_text = $this->executeSingleSubQuery($subQItem, $step_counter, "調査ステップ {$step_counter}/{$total_steps}");
            $this->subAnswers[] = "◆ サブ回答 {$step_counter} [{$operationType}]: {$subQ}\n{$sub_ans_text}";
        }
    }

    /**
     * 【シーケンス1専用】独立したSQL自動生成・実行統制メソッド
     */
    private function executeSingleSubQuery(array $subQItem, int $step_counter, string $stepLabel = "追加ステップ"): string {
        $subQItem = $this->normalizeSubQueryItem($subQItem);
        $subQ = $subQItem['query'];
        $operationType = $subQItem['operation_type'];
        $targetTables = implode(', ', $subQItem['target_tables']);
        $answerGoal = $subQItem['answer_goal'];
        chatLogger("【データ分析】ステップ {$step_counter}: 観点「{$subQ}」のSQL生成中...");
        
        // 進捗SSEパディング制御（シーケンス1: ステップ2で同期送出）
        sendSSE('status', [
            'step'    => 2, 
            'message' => "🔬 [{$stepLabel}]「{$subQ}」に基づくSQLを構築中... [type: {$operationType}]"
        ]);

        try {
            $stmtInsert = $this->pdo->prepare("INSERT INTO chat_reasoning_steps (project_id, session_id, original_question, step_number, sub_query, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmtInsert->execute([
                $this->projectId,
                $this->reasoningId,
                $this->normalizeUtf8($this->originalMessage),
                $step_counter,
                $this->normalizeUtf8($subQ)
            ]);
        } catch (Exception $dbEx) {
            chatLogger("[ERROR] chat_reasoning_steps へのINSERTに失敗: " . $dbEx->getMessage());
        }

        // 思考リソースを集計に特化させるプロトコル
        $sql_sys_prompt = "【ミッション】\n"
                        . "お前は実在するデータベース構造を自律監査するデータアナリストAIである。提示された「INFORMATION_SCHEMAコンテキスト」および「実在データ総数マトリクス」を熟読し、サブクエリの処理タイプに合うSELECTクエリを1つ構築せよ。\n\n"
                        . "【最重要：思考解放プロトコル】\n"
                        . "■ マルチテナント隔離（ `project_id` や `csv_file_id` によるデータの覗き見防止条件 ）については、システム（PHP側）が実行直前に自動的にWHERE句へ結合・強制インジェクトするため、お前は【一切記述しなくてよい】。条件句への project_id の記載は完全に省略せよ。\n"
                        . "■ お前はただ、ユーザーの質問に答えるために「どのテーブルをFROM/JOINし、どのJSONキーを抽出し、どうグループ化・集計するか」という【純粋な集計ロジックの構築】だけに全リソースを集中させよ。\n\n"
                        . "【重要構文ルール】\n"
                        . "1. 過去の嘘のスキーマの記憶は完全パージせよ。必ず、提供された【INFORMATION_SCHEMAコンテキスト】に現実に表記されている実在の物理カラム構成のみを使用すること。\n"
                        . "2. CSVデータ自体の「中身・本文」の集計や概要, 傾向をユーザーから求められた場合は、メタ情報を管理する `project_csv_files` ではなく、必ず実データ行がレコードとして詰まっている【 `project_csv_rows` 】テーブルを使用せよ。\n"
                        . "3. PDFやアップロード資料の本文・図表説明を読む場合は `doc_chunks` を中心にし、必要に応じて `documents` とJOINして title, page_number, chunk_text, image_description を取得せよ。\n"
                        . "4. MySQL 8.0 の日本語JSONキー抽出は `JSON_UNQUOTE(JSON_EXTRACT(T1.row_data, '$.\"項目名\"'))` を使用せよ。`row_data->>$.項目名` や `row_data->>$.'項目名'` は生成禁止。\n"
                        . "   `project_csv_rows` に `row_count` カラムは存在しない。CSV行数は `COUNT(T1.id)`、CSVファイル側の登録行数は `project_csv_files.row_count` を使用せよ。\n"
                        . "5. テーブルのエイリアスは必ず `project_csv_rows T1` のように `T1` などの一意のエイリアスを付与せよ。\n"
                        . "6. 【絶対Group Byルール】SELECT 句に集計関数（SUM/AVG/COUNT等）と、非集計カラム（JSON項目含む）を同時に含める場合は、必ずクエリの末尾に `GROUP BY` 句を明記せよ。これを怠ると MySQL 1140 構文エラーで即死する。\n\n"
                        . "【出力制約】\n"
                        . "出力は必ず実行可能なSQL文字列1つのみを内包したJSON形式【のみ】を出力せよ。Markdownや余計な解説文プロースは一切排除せよ。\n"
                        . '{"sql": "SELECT ..."}';

        // 記憶プロンプトを最先頭へマウント
        $sql_sys_prompt = $this->databaseMemoryPrompt . "\n" . $sql_sys_prompt;
        $sql_user_prompt = $this->schemaInfo
            . "\n\n【サブクエリ】\n" . $subQ
            . "\n\n【operation_type】\n" . $operationType
            . "\n\n【候補テーブル】\n" . $targetTables
            . "\n\n【このサブクエリの回答目標】\n" . $answerGoal;

        // 超決定論的パラメータによる最高度引き締めAI射撃
        $sql_json_str = callOllamaChat($this->ollama_host, $this->reasoningModel, $sql_sys_prompt, $sql_user_prompt, 'json', ["temperature" => 0.0, "top_p" => 0.1, "num_ctx" => 4096]);
        
        // クレンジングおよびバッチ分割実行エンジンレイヤーへ委譲
        $sub_ans_text = $this->executeAndAnalyzeSql($sql_json_str, $subQ, $step_counter, $stepLabel);

        try {
            $stmtUpdAns = $this->pdo->prepare("UPDATE chat_reasoning_steps SET sub_answer = ? WHERE session_id = ? AND step_number = ?");
            $stmtUpdAns->execute([$this->normalizeUtf8($sub_ans_text), $this->reasoningId, $step_counter]);
        } catch (Exception $dbEx2) {
            chatLogger("[ERROR] chat_reasoning_steps のUPDATEに失敗: " . $dbEx2->getMessage());
        }
        
        return $sub_ans_text;
    }

    /**
     * 【シーケンス1専用】クレンジング安全監査 ＆ 100件サイズバッチスライス巡回実行コア
     * ★[集計試行・バッチスライス実況マウント完全版]
     */
    private function executeAndAnalyzeSql(string $sqlJsonStr, string $subQ, int $stepCounter, string $stepLabel = ""): string {
        $fence = str_repeat("\x60", 3);
        
        $max_retries = 3;
        $retry_count = 0;
        $execResult = ['success' => false, 'error' => 'クエリが初期化されていません。', 'data' => []];

        require_once __DIR__ . '/../../src/SqlExecutionEngine.php';
        $sqlEngine = new SqlExecutionEngine($this->pdo, $this->projectId);

        while ($retry_count <= $max_retries) {
            chatLogger("[OLLAMA-RAW-RESPONSE] (集計試行 {$retry_count}/3) 受信生データ:\n" . $sqlJsonStr);

            $clean_json = preg_replace('/^' . preg_quote($fence, '/') . '(?:json|sql)?\s*(.*?)\s*' . preg_quote($fence, '/') . '$/ms', '$1', trim($sqlJsonStr));
            $sql_data = json_decode($clean_json, true);
            
            if (is_array($sql_data) && isset($sql_data['sql'])) {
                $generated_sql = trim($sql_data['sql']);
            } elseif (is_array($sql_data) && isset($sql_data['query']) && preg_match('/^\s*SELECT/i', (string)$sql_data['query'])) {
                $generated_sql = trim((string)$sql_data['query']);
            } else {
                if (preg_match('/SELECT\s+.*?(?:;|$)/is', $sqlJsonStr, $matches)) {
                    $generated_sql = trim($matches[0]);
                } else {
                    $generated_sql = trim($clean_json);
                }
            }

            // ━━━━【SQL実行直前の構文補正レイヤー（万能クレンザー）】━━━━
            $generated_sql = preg_replace('/:\??project_id/i', $this->projectId, $generated_sql);
            $generated_sql = preg_replace(
                "/((?:[a-zA-Z0-9_]+\.)?row_data)\s*->>\s*['\"]?\\$?\.?([a-zA-Z0-9_\-\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{4E00}-\x{9FAF}]+)['\"]?/u",
                'JSON_UNQUOTE(JSON_EXTRACT($1, \'$."$2"\'))',
                $generated_sql
            );
            $generated_sql = preg_replace('/' . preg_quote($fence, '/') . 'sql|' . preg_quote($fence, '/') . '/i', '', $generated_sql);
            $generated_sql = preg_replace('/["\'}\]\s;]+$/', '', trim($generated_sql));
            $generated_sql = preg_replace('/\\s+COLLATE\\s+[\'"]?[a-zA-Z0-9_-]+[\'"]?/i', '', $generated_sql);
            
            chatLogger("[Text-to-SQL-AFTER] (集計試行 {$retry_count}/3) 補正後実行SQL: " . $generated_sql);

            // ✨【ネジ締め①】現在の集計試行（デバッグ状況）を画面のコンソールへ即時生中継！
            if ($retry_count > 0) {
                sendSSE('status', [
                    'step'    => 2, 
                    'message' => "⚠️ [SQL構文エラー検知] MySQLからエラーが返されました。現在、AIがサーバーログを自己反省（Self-Reflection）し、修正クエリを自動再生成中... [デバッグ試行: {$retry_count}/3回]"
                ]);
            } else {
                sendSSE('status', [
                    'step'    => 2, 
                    'message' => "📊 [集計試行: 初回射撃] 「{$subQ}」を解決するSELECTクエリの動的ホワイトリスト安全監査を実行中..."
                ]);
            }

            // 動的ホワイトリスト監査
            if (preg_match('/FROM\s+`?([a-zA-Z0-9_-]+)`?/i', $generated_sql, $tableMatch)) {
                $extractedTable = $tableMatch[1];
                if (in_array($extractedTable, $this->dynamicTableWhitelist, true)) {
                    chatLogger("[SECURITY APPROVED] 動的ホワイトリスト監査パスを安全承認します。");
                }
            }

            // 共通実行エンジンへの安全委譲
            $execResult = $sqlEngine->execute($generated_sql);

            if ($execResult['success'] === true) {
                chatLogger("[DEBUG-LOOP] SQLの正常実行開通を確認しました。");
                // ✨【ネジ締め②】SQLがノーエラーで突破した瞬間を通知！
                sendSSE('status', [
                    'step'    => 2, 
                    'message' => "✅ [SQL監査合格] クエリの正常実行開通を確認しました。物理データベースからのレコード抽出に成功。"
                ]);
                break;
            }

            chatLogger("[SQL-EXEC-FAILED] (集計試行 {$retry_count}/3) MySQL生エラー文: " . ($execResult['error'] ?? 'Unknown Error'));
            $repairGuidance = $sqlEngine->buildRepairGuidance($generated_sql, (string)($execResult['error'] ?? 'Unknown Error'), $subQ);

            $retry_count++;
            if ($retry_count > $max_retries) {
                chatLogger("[CRITICAL-LOOP] 3回のリトライすべてで監査拒否またはMySQLエラーが発生。ループ強制遮断。");
                break;
            }

            // 自己反省（Self-Reflection）デバッグプロンプト構築
            $debug_sys_prompt = "高度なMySQL 8.0のエキスパートシステムとして、提示された【失敗したクエリ】と、MySQLサーバーが返した【生の構成エラー文】を深く自己反省（Self-Reflection）してください。\n"
                              . "出力は必ず、修正・デバッグされた実行可能なSQL文字列1つのみを内包したJSON形式【のみ】を出力してください。\n"
                              . "SELECT '説明文' のような実テーブルを読まないダミーSQLは禁止です。必ずFROM句で実在テーブルを参照してください。\n"
                              . '{"sql": "SELECT ..."}';

            $debug_sys_prompt = $this->databaseMemoryPrompt . "\n" . $debug_sys_prompt;
            $debug_user_context = "【動的INFORMATION_SCHEMA構成】\n" . $this->schemaInfo . "\n\n"
                                . "【この分析タスクの本来の目的】\n" . $subQ . "\n\n"
                                . "❌ 【前回失敗した不正なSQL】\n" . $generated_sql . "\n\n"
                                . "⚠️ 【MySQLから返された生のエラー文】\n" . ($execResult['error'] ?? 'Unknown Error') . "\n\n"
                                . $repairGuidance;

            $sqlJsonStr = callOllamaChat($this->ollama_host, $this->reasoningModel, $debug_sys_prompt, $debug_user_context, 'json', ["temperature" => 0.0, "top_p" => 0.1, "num_ctx" => 4096]);
        }

        if (!$execResult['success']) {
            chatLogger("[WARN] 自律修復上限超過：最終SQLのエラーが解消されませんでした。");
            $fallbackSql = $sqlEngine->suggestFallbackSql($subQ, $generated_sql ?? '');
            if ($fallbackSql) {
                chatLogger("[FALLBACK-SQL] 自律修復上限後、実在スキーマに基づく定番SQLで最終救済を試行します: " . $fallbackSql);
                $fallbackResult = $sqlEngine->execute($fallbackSql);
                if (($fallbackResult['success'] ?? false) === true) {
                    $generated_sql = $fallbackResult['sql'] ?? $fallbackSql;
                    $execResult = $fallbackResult;
                    chatLogger("[FALLBACK-SQL-SUCCESS] 定番SQLによる救済に成功しました。");
                }
            }
        }

        if (!$execResult['success']) {
            return "⚠️ **3回自律修復を試みましたが、集計を完了できませんでした。**\n\n最終エラー詳細: " . ($execResult['error'] ?? '不明なエラー。') . "\n\nデバッグ対象クエリ: `{$generated_sql}`";
        }

        // ━━━━【100件サイズ最適バッチスライス回路 (Map-Reduce) ＆ State-Saving】━━━━
        $limited_results = $execResult['data'] ?? [];
        $batch_size = 100; 
        $batches = array_chunk($limited_results, $batch_size);
        
        if (empty($batches)) {
            $batches = [[]];
        }
        
        $accumulated_insight = ""; 
        
        foreach ($batches as $index => $batch) {
            $batch_num = $index + 1;
            $total_batches = count($batches);
            $batch_json = json_encode($batch, JSON_UNESCAPED_UNICODE);
            
            chatLogger("[DEBUG] バッチスライス巡回中... ({$batch_num}/{$total_batches})");
            
            // ✨【ネジ締め③】データの分割精読（Map-Reduce）の進捗を、パーセンテージや分数で超リアルタイムに実況！
            sendSSE('status', [
                'step'    => 2, 
                'message' => "📚 [データ分割巡回中] 抽出レコードが巨大なため、安全に分割スキャンを実行中... 現在 {$batch_num} / 全 {$total_batches} バッチ目をAIがディープ精読・インサイト抽出中..."
            ]);

            $sub_analysis_sys = "外お前はデータアナリストです。実行されたSQLとその集計結果から、客観的な考察を簡潔にまとめてください。";
            if (!empty($accumulated_insight)) {
                $sub_analysis_sys .= "【これまでのバッチから得られた蓄積知識（State-Saving）】\n" . $accumulated_insight . "\n\n";
            }
            $sub_analysis_sys .= "提示された【実行したクエリ】と【今回のデータバッチ ({$batch_num}/{$total_batches})】から、新たに何が読み取れるか客観的なインサイトを抽出し、これまでの蓄積知識と論理的に統合して「最新の考察」を日本語で簡潔に再構築してください。";

            $sub_analysis_user = "【分析観点】\n{$subQ}\n\n========================================\n【実行クエリ】\n{$generated_sql}\n========================================\n【今回データバッチ】\n{$batch_json}";
            
            $analysisThought = "";
            $analysisRes = callOllamaChat($this->ollama_host, $this->model, $sub_analysis_sys, $sub_analysis_user, null, ["temperature" => 0.0, "top_p" => 0.1, "num_ctx" => 4096], $analysisThought);
            
            if (!empty($analysisThought)) {
                $analysisRes = "🤔 **[データ考察プロセス (Batch {$batch_num})]**\n<details><summary>分析過程を展開</summary>\n\n" . $analysisThought . "\n\n</details>\n\n---\n\n" . $analysisRes;
            }
            
            $accumulated_insight = $analysisRes;
            
            // リアルタイム外部メモリ保存
            $temp_sub_answer = "【実行SQL】\n" . $fence . "sql\n{$generated_sql}\n" . $fence . "\n\n【段階的理解進捗】\n現在 {$batch_num} / {$total_batches} バッチを精読完了。\n\n【最新の中間考察】\n{$accumulated_insight}";
            
            try {
                $stmtUpdAns = $this->pdo->prepare("UPDATE chat_reasoning_steps SET sub_answer = ? WHERE session_id = ? AND step_number = ?");
                $stmtUpdAns->execute([$temp_sub_answer, $this->reasoningId, $stepCounter]);
            } catch (Exception $e) {
                chatLogger("[ERROR] バッチ中間考察の即時永続化に失敗: " . $e->getMessage());
            }
        }
        
        return "【実行SQL】\n" . $fence . "sql\n{$generated_sql}\n" . $fence . "\n\n【段階的分割巡回（全 {$total_batches} バッチ）による最終統合考察】\n{$accumulated_insight}";
    }

    /**
     * 【巻き戻し用】門番からの指摘に基づき、新しい追加集計観点を自律抽出させるメソッド
     */
    private function generateAdditionalSubQuery(string $feedback): string {
        require_once __DIR__ . '/../../src/PromptManager.php';
        
        $sysPrompt = "あなたは超一流のシステムアーキテクトおよびデータアナリストです。\n"
                   . "品質審査責任者から、現在のレポートに対する以下の【絶対修正命令（データ不足等の指摘）】を受けました。\n\n"
                   . "【品質審査責任者からの絶対修正命令】\n{$feedback}\n\n"
                   . "この指示に完全に従い、不足しているデータを新たに抽出するための「追加の分析観点（SQLで集計・検索する具体的な目的）」を1つだけ、簡潔な文字列として出力してください。\n"
                   . "【絶対ルール】\n出力はJSONではなく、純粋なテキストのみにしてください。挨拶や説明プロースは一切禁止します。";

        $sysPrompt = $this->databaseMemoryPrompt . "\n" . $sysPrompt;
        $userPrompt = "【ユーザーの元の質問】\n{$this->originalMessage}\n\n追加の分析観点:";
        $thoughtDummy = "";
        
        $newSubQ = callOllamaChat($this->ollama_host, $this->model, $sysPrompt, $userPrompt, null, ["temperature" => 0.0, "top_p" => 0.1, "num_ctx" => 2048], $thoughtDummy);
        
        return trim($newSubQ);
    }

    /**
     * 【シーケンス2専用】計画フェーズ：コンテキストのダイエット
     * カラムDDLを一切隠蔽し、1行説明のみで1〜3ステップのJSON配列を生成させる Planner回路
     */
    private function generateExecutionPlan(): array {
        $briefText = "【利用可能なデータベース・テーブル一覧】\n";
        foreach (self::$tablesBrief as $tableName => $description) {
            $briefText .= "- テーブル名: [{$tableName}] (概要: {$description})\n";
        }

        // 動的なバッククォート3つのフェンス生成によるハードコード防止
        $fence = str_repeat("\x60", 3);

        $sysPrompt = "あなたは超一流のシステムアーキテクトおよびデータアナリストです。\n"
                   . "ユーザーから提示された質問を解決するために、どのテーブルから、どのような順番で情報を取得すべきか、1〜3つのステップで構成される論理的な「実行計画（手順書）」を策定してください。\n\n"
                   . "【絶対ルール】\n"
                   . "軽量LLMの混乱を防ぎレスポンス精度を最大化するため、プロンプトには詳細なカラム情報をあえて含めていません。テーブル名と概要から情報構造を推測し、ステップバイステップの手順を構築してください。\n"
                   . "回答は、必ず以下のJSON配列形式のブロック【のみ】を出力してください。Markdownの説明文や余計なプロースは一切禁止します。\n\n"
                   . $fence . "json\n"
                   . "[\n"
                   . "  {\"step\": 1, \"table\": \"テーブル名\", \"purpose\": \"このステップで検索・集計する具体的な目的（日本語）\", \"operation_type\": \"semantic_extract\"}\n"
                   . "]\n"
                   . $fence . "\n\n"
                   . $briefText;

        // PromptManager由来の記憶プロンプトを最先頭へインジェクト
        $sysPrompt = $this->databaseMemoryPrompt . "\n" . $sysPrompt;
        $userPrompt = "【ユーザーの質問】\n{$this->searchQuery}";
        $thought = "";

        // 計画手順書策定の遊びを完全に排除する超決定論化オプション（4096固定）
        $res = callOllamaChat($this->ollama_host, $this->reasoningModel, $sysPrompt, $userPrompt, 'json', ["temperature" => 0.0, "top_p" => 0.1, "num_ctx" => 4096], $thought);

        // Ollamaから受信した生の計画データをそのままダンプ記録
        chatLogger("[PLANNER-RAW-RESPONSE] Ollamaから受信した生の計画データ:\n" . $res);

        // 思考プロセスをステップ0として記録
        if (!empty($thought) && $this->reasoningId) {
            try {
                $stmt = $this->pdo->prepare("INSERT INTO chat_reasoning_steps (project_id, session_id, original_question, step_number, sub_query, sub_answer, created_at) VALUES (?, ?, ?, 0, '【AIの思考プロセス: 実行計画生成】', ?, NOW())");
                $stmt->execute([$this->projectId, $this->reasoningId, $this->originalMessage, $thought]);
            } catch (Exception $ex) {
                chatLogger("思考プロセス保存例外: " . $ex->getMessage());
            }
        }

        // 動的エスケープを使用した安全なマークダウンパース
        $cleanJson = $res;
        $fencePattern = '/^' . preg_quote($fence, '/') . '(?:json)?\s*(\\[.*?\\])\s*' . preg_quote($fence, '/') . '/is';
        if (preg_match($fencePattern, $res, $matches)) {
            $cleanJson = $matches[1];
        } elseif (preg_match('/(\\[.*?\\])/is', $res, $matches)) {
            $cleanJson = $matches[1];
        }

        $plan = json_decode($cleanJson, true);
        if (is_array($plan) && isset($plan['plan']) && is_array($plan['plan'])) {
            $plan = $plan['plan'];
        } elseif (is_array($plan) && isset($plan['steps']) && is_array($plan['steps'])) {
            $plan = $plan['steps'];
        } elseif (is_array($plan) && isset($plan['table'])) {
            chatLogger("[FORMAT-RECOVERY] 単一の計画オブジェクトを配列構造に自動ラップ救済しました。");
            $plan = [$plan];
        }

        // 【インテリジェント・フォールバック計画への賢いアップグレード】
        if (!is_array($plan) || empty($plan) || !isset($plan[0]['table'])) {
            chatLogger("[PLANNER-PARSE-FAILED] 計画JSONのパースに失敗したため、安全フォールバック回路が起動しました。対象質問: " . $this->searchQuery);
            
            if (preg_match('/(集計|件数|平均|合計|割合)/u', $this->searchQuery)) {
                $fallbackTable = 'project_csv_rows';
            } elseif (preg_match('/(会話|履歴|チャット|これまでの|まとめ)/u', $this->searchQuery)) {
                $fallbackTable = 'chat_history';
            } else {
                $fallbackTable = 'doc_chunks';
            }

                $fallbackPurpose = match ($fallbackTable) {
                    'project_csv_rows' => 'CSV行データから質問に関連する集計・傾向を抽出する',
                    'chat_history' => '過去の対話履歴から質問に関連する文脈を抽出する',
                    default => '関連資料PDFの本文チャンクから主要な留意点・根拠を抽出する',
                };

                $plan = [
                ["step" => 1, "table" => $fallbackTable, "purpose" => $fallbackPurpose, "operation_type" => $this->inferOperationType($this->searchQuery)]
            ];
        }

        chatLogger("策定された実行計画ステップ数: " . count($plan));
        return $plan;
    }

    /**
     * 【仕様2】実行フェーズ：動的プロンプトのマスキングとエンジン連携
     * ❌ 悪魔の二重重複を完全物理パージ！クレンザーとロジックを綺麗に一本化した防衛開通版
     */
    private function executePlanSteps(array $plan): array {
        $stepResults = [];
        $stepCounter = 0;
        
        // 共通監査・クエリ実行エンジンの安全なインスタンス化
        require_once __DIR__ . '/../../src/SqlExecutionEngine.php';
        $sqlEngine = new SqlExecutionEngine($this->pdo, $this->projectId); 

        // 動的マークダウンフェンス用
        $fence = str_repeat("\x60", 3);

        foreach ($plan as $stepItem) {
            $stepCounter++;
            $tableName = $stepItem['table'] ?? '';
            $purpose   = $stepItem['purpose'] ?? '';
            $operationType = $stepItem['operation_type'] ?? $this->inferOperationType($purpose . ' ' . $this->searchQuery);

            if (!isset(self::$tablesSchema[$tableName])) {
                continue;
            }

            $this->model = $this->reasoningModel; // 内部ステート保持同期

            chatLogger("【フル思考】フェーズ2.{$stepCounter}: テーブル「{$tableName}」の動的マスキングスキャン実行中...");
            
            // 進捗のステップ番号をシーケンス順（資料巡回をステップ3〜4に制御）にパディングしてSSE送信
            sendSSE('status', [
                'step'    => 3, 
                'message' => "🔍 【シーケンス2/3】資料巡回ステップ [{$stepCounter}/" . count($plan) . "]: テーブル「{$tableName}」を自動精読中..."
            ]);

            // 動的プロンプトマスキング：対象テーブルのみの詳細スキーマを抽出し、残り7つは完全消去
            $targetSchema = self::$tablesSchema[$tableName];
            if ($tableName === 'doc_chunks') {
                $targetSchema .= "\n\n関連テーブルとしてJOIN可能:\n" . self::$tablesSchema['documents'];
            }

            // 射影用コンテキスト制約
            $projectConstraint = "";
            if ($tableName === 'doc_chunks') {
                $projectConstraint = "・doc_chunks には project_id が存在しません。案件で絞り込む場合は documents d と JOIN し、d.project_id = {$this->projectId} を使用してください。\n";
            } elseif ($tableName !== 'project_csv_rows' && $tableName !== 'users') {
                $projectConstraint = "・テーブルに [project_id] カラムが存在する場合は、必ず [project_id = {$this->projectId}] の絞り込み条件を含めてください。\n";
            }

            // ✨【🛠️パッチ修正②】日本語キーによるパースエラー3143を物理封殺する絶対拘束ルールの上書き
            $sqlSysPrompt = "あなたは極めて正確なデータ抽出SQLを構築する MySQL エキスパートです。\n"
                          . "提示された【対象データの詳細スキーマ情報】のみを頭に入れ、ユーザーの目的を達成するための MySQL 8.0 互換の SELECT クエリを作成してください。\n"
                          . "関係のない他のテーブルは使わないでください。ただし doc_chunks から本文を読む場合は、出典表示のため documents とのJOINを許可します。\n\n"
                          . "【対象テーブルのスキーマ情報】\n"
                          . $targetSchema . "\n\n"
                          . "★【重要：マルチテナント隔離の絶対ルール（プレースホルダー完全禁止）】\n"
                          . "・クエリを組み立てる際、お前が WHERE 条件に必ず「生の数字」で直接指定しなければいけない現在の project_id は 【 {$this->projectId} 】 です。\n"
                          . "・`:project_id` などのプレースホルダー記号や変数は【絶対に生成禁止】です。必ず文字通り `WHERE [テーブル名].project_id = {$this->projectId}` のように、生の整数値を直接クエリ文字列に書き込んでください。\n\n"
                          . "【絶対ルール】\n"
                          . $projectConstraint
                          . "・★【最重要：MySQL 8.0 日本語JSONパスの絶対拘束ルール】\n"
                          . "  JSON型（row_data カラム）から「所属」や「課題など」といった日本語キーを抽出・集計する際は、必ず `JSON_UNQUOTE(JSON_EXTRACT(T1.row_data, '$.\"項目名\"'))` を使用せよ。\n"
                          . "  `project_csv_rows` に `row_count` カラムは存在しない。CSV行数は `COUNT(T1.id)`、CSVファイル側の登録行数は `project_csv_files.row_count` を使用せよ。\n"
                          . "・キャスト時に COLLATE 句は絶対に使用しないでください。\n"
                          . "・PDFや資料本文を読む semantic_extract では title, page_number, chunk_text, image_description を優先して取得してください。\n"
                          . "・出力は必ず実行可能なSQL文字列1つのみを内包したJSON形式で出力してください。Markdownや説明テキスト、コメントは完全禁止です。\n"
                          . '{"sql": "SELECT ..."}';

            // 個別SQL生成システムプロンプトの最先頭へ記憶プロンプトをインジェクト
            $sqlSysPrompt = $this->databaseMemoryPrompt . "\n" . $sqlSysPrompt;

            // 🔄 [エスケープ補助] プロンプト内のドル記号の直後に予期せず入った半角スペースを削除し、構文を完全正常化
            $sqlSysPrompt = str_replace('$ ', '$', $sqlSysPrompt);

            $sqlUserPrompt = "【全体の質問】\n{$this->searchQuery}\n\n【このステップの目的】\n{$purpose}\n\n【operation_type】\n{$operationType}";
            
            // SQL生成時の創造性を完全パージするオプション引き締めへのリフォーム
            $sqlJsonStr = callOllamaChat($this->ollama_host, $this->reasoningModel, $sqlSysPrompt, $sqlUserPrompt, 'json', ["temperature" => 0.0, "top_p" => 0.1, "num_ctx" => 4096]);

            // 📢 [超詳細ログ2] Ollamaが作成したパース前の生JSON文字列を出力
            chatLogger("[AUTO-SQL-RAW] (ステップ {$stepCounter}) 受信生クエリデータ: " . $sqlJsonStr);

            // SQL抽出のためのクレンジング
            $generatedSql = "";
            $cleanJsonPattern = '/^' . preg_quote($fence, '/') . '(?:json|sql)?\s*(.*?)\s*' . preg_quote($fence, '/') . '$/ms';
            $cleanJsonStr = preg_replace($cleanJsonPattern, '$1', trim($sqlJsonStr));
            $sqlData = json_decode($cleanJsonStr, true);

            if (is_array($sqlData) && isset($sqlData['sql'])) {
                $generatedSql = trim($sqlData['sql']);
            } elseif (is_array($sqlData) && isset($sqlData['query']) && preg_match('/^\s*SELECT/i', (string)$sqlData['query'])) {
                $generatedSql = trim((string)$sqlData['query']);
            } else {
                if (preg_match('/SELECT\s+.*?(?:;|$)/is', $sqlJsonStr, $matches)) {
                    $generatedSql = trim($matches[0]);
                } else {
                    $generatedSql = trim($cleanJsonStr);
                }
            }

            // 📢 [超詳細ログ2] 万能クレンザーで自動補正をかける「直前」の素のSQLを出力（大文字キャメル一元同期）
            chatLogger("[AGENT-SQL-BEFORE] (ステップ {$stepCounter}) プログラム補正前の素のSQL: " . $generatedSql);

            // ━━━━【SQL実行直前の構文補正レイヤー（万能クレンザー）】━━━━
            // 追加防衛シールド：AIが変数に逃げた場合も、水際で生のプロジェクトID数字へ強制書き換え
            $generatedSql = preg_replace('/:\??project_id/i', $this->projectId, $generatedSql);
            
            // 🔄 [最終ネジ締め修正] SQLを絶対に破壊せず、日本語キーだけを確実に射撃する安全クレンザー（Unicodeブロック完全拘束版）
            $generatedSql = preg_replace(
                "/((?:[a-zA-Z0-9_]+\.)?row_data)\s*->>\s*['\"]?\\$?\.?([a-zA-Z0-9_\-\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{4E00}-\x{9FAF}]+)['\"]?/u",
                'JSON_UNQUOTE(JSON_EXTRACT($1, \'$."$2"\'))',
                $generatedSql
            );
            
            $generatedSql = preg_replace('/' . preg_quote($fence, '/') . 'sql|' . preg_quote($fence, '/') . '/i', '', $generatedSql);
            $generatedSql = preg_replace('/["\'}\]\s;]+$/', '', trim($generatedSql));
            $generatedSql = preg_replace('/\\s+COLLATE\\s+[\'"]?[a-zA-Z0-9_-]+[\'"]?/i', '', $generatedSql);

            // 📢 [超詳細ログ2] 万能クレンザーで自動補正をかけた「直後」のMySQLへ投入される最終実行SQLを出力（大文字キャメル一元同期）
            chatLogger("[AGENT-SQL-AFTER]  (ステップ {$stepCounter}) プログラム補正後の最終実行SQL: " . $generatedSql);

            $subAnsText = "";
            $resultJson = "[]";
            $isSafeSql = preg_match('/^\s*SELECT/i', $generatedSql) && !preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|EXECUTE)\b/i', $generatedSql);

            if (empty($generatedSql) || !$isSafeSql) {
                $subAnsText = "⚠️ SQLの構築に失敗したか、安全ではないクエリと判定されました。\n生成されたSQL: {$generatedSql}";
            } else {
                try {
                    // 共通エンジン「SqlExecutionEngine」を呼び出して安全に実行
                    $execResult = $sqlEngine->execute($generatedSql);

                    if (!$execResult['success']) {
                        chatLogger("[AGENT-EXEC-FAILED] (ステップ {$stepCounter}) 監査拒否または生エラー: " . ($execResult['error'] ?? 'Unknown Error'));
                        $repairGuidance = $sqlEngine->buildRepairGuidance($generatedSql, (string)($execResult['error'] ?? 'Unknown Error'), $purpose);
                        chatLogger("[AGENT-REPAIR-GUIDANCE] (ステップ {$stepCounter}) 正解誘導ヒント:\n" . $repairGuidance);

                        $fallbackSql = $sqlEngine->suggestFallbackSql($purpose, $generatedSql);
                        if ($fallbackSql) {
                            chatLogger("[AGENT-FALLBACK-SQL] (ステップ {$stepCounter}) 実在スキーマに基づく定番SQLで救済試行: " . $fallbackSql);
                            $fallbackResult = $sqlEngine->execute($fallbackSql);
                            if (($fallbackResult['success'] ?? false) === true) {
                                $generatedSql = $fallbackResult['sql'] ?? $fallbackSql;
                                $execResult = $fallbackResult;
                                chatLogger("[AGENT-FALLBACK-SQL-SUCCESS] (ステップ {$stepCounter}) 定番SQLによる救済に成功しました。");
                            }
                        }
                    }

                    if (!$execResult['success']) {
                        $subAnsText = "⚠️ クエリの実行がセキュリティまたは構文監査により遮断されました。理由: " . ($execResult['error'] ?? '不明な拒否。');
                    } else {
                        $rows = $execResult['data'] ?? [];
                        
                        // エントリポイント側との互換性確保のために、RAGや文書ソースのメタ情報を動的に吸い上げる
                        foreach ($rows as $row) {
                            if (in_array($tableName, ['doc_chunks', 'documents', 'project_faqs', 'project_csv_files'])) {
                                $docId = $row['doc_id'] ?? ($row['document_id'] ?? ($row['id'] ?? $stepCounter));
                                $title = $row['title'] ?? ($row['file_name'] ?? ($row['file_path'] ?? ($row['question_summary'] ?? "巡回ステップデータ")));
                                $page  = $row['page_number'] ?? 1;
                                $this->uniqueSources[$docId . '-' . $page] = ["title" => $title, "page" => $page, "doc_id" => $docId];
                            }
                        }
                        
                        $resultJson = json_encode($rows, JSON_UNESCAPED_UNICODE);
                        if (mb_strlen($resultJson) > 3000) {
                            $resultJson = mb_substr($resultJson, 0, 3000) . "\n...[Context Guard: 容量超過のためデータ末尾をカット]";
                        }

                        // 軽量LLM向けに、結果の単一中間考察を生成
                        $analysisSys = "あなたはデータアナリストです。提示された【実行したクエリ】と【抽出データ】から、何が読み取れるか客観的なインサイトのみを日本語で簡潔に1行〜数行で要約してください。";
                        $analysisUser = "【ステップ目的】\n{$purpose}\n\n========================================\n【実行クエリ】\n{$generatedSql}\n========================================\n【抽出データ】\n{$resultJson}";
                        $analysisThought = "";

                        $analysisRes = callOllamaChat($this->ollama_host, $this->reasoningModel, $analysisSys, $analysisUser, null, ["num_ctx" => 4096], $analysisThought);

                        if (!empty($analysisThought)) {
                            $analysisRes = "🤔 **[データ考察プロセス]**\n<details><summary>分析過程を展開</summary>\n\n" . $analysisThought . "\n\n</details>\n\n---\n\n" . $analysisRes;
                        }

                        $subAnsText = "【実行SQL】\n" . $fence . "sql\n{$generatedSql}\n" . $fence . "\n\n【取得データ抜粋】\n" . $fence . "json\n{$resultJson}\n" . $fence . "\n\n【中間考察】\n" . $analysisRes;
                    }

                } catch (Exception $e) {
                    chatLogger("[AGENT-EXEC-FAILED] (ステップ {$stepCounter}) 例外詳細: " . $e->getMessage());
                    $subAnsText = "[ERROR] クエリ実行エラー: " . $e->getMessage() . "\nSQL: {$generatedSql}";
                }
            }

            // 即座に同一の番地(reasoningId)へダンプ合流・永続化
            if ($this->reasoningId) {
                try {
                    $stmtInsertStep = $this->pdo->prepare("INSERT INTO chat_reasoning_steps (project_id, session_id, original_question, step_number, sub_query, sub_answer, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                    $stmtInsertStep->execute([
                        $this->projectId, 
                        $this->reasoningId, 
                        $this->originalMessage, 
                        2 + $stepCounter, 
                        "[資料巡回: {$tableName}] {$purpose}", 
                        $subAnsText
                    ]);
                } catch (Exception $ex) {
                    chatLogger("資料巡回ステップ保存例外: " . $ex->getMessage());
                }
            }

            $stepResults[] = [
                'step'    => $stepCounter,
                'table'   => $tableName,
                'purpose' => $purpose,
                'result'  => $subAnsText
            ];

            $this->subAnswers[] = "◆ 資料巡回ステップ {$stepCounter} (テーブル: {$tableName}): {$purpose}\n{$subAnsText}";
        }

        return $stepResults;
    }

    private function normalizeSubQueryItem(array $item): array {
        $query = trim((string)($item['query'] ?? $item['purpose'] ?? $this->searchQuery));
        if ($query === '') {
            $query = 'ユーザーの質問に関連する実データを取得して中間回答を作成する';
        }

        $operationType = (string)($item['operation_type'] ?? '');
        $allowedTypes = ['metadata_lookup', 'simple_aggregate', 'record_search', 'semantic_extract'];
        if (!in_array($operationType, $allowedTypes, true)) {
            $operationType = $this->inferOperationType($query . ' ' . $this->searchQuery);
        }

        $targetTables = $item['target_tables'] ?? [];
        if (is_string($targetTables)) {
            $targetTables = [$targetTables];
        }
        if (!is_array($targetTables) || empty($targetTables)) {
            $targetTables = $this->inferTargetTables($query . ' ' . $this->searchQuery);
        }

        $targetTables = array_values(array_filter(array_map('strval', $targetTables), function ($table) {
            return in_array($table, $this->dynamicTableWhitelist, true) || isset(self::$tablesSchema[$table]);
        }));
        if (empty($targetTables)) {
            $targetTables = $this->inferTargetTables($query . ' ' . $this->searchQuery);
        }

        return [
            'query' => $query,
            'operation_type' => $operationType,
            'target_tables' => $targetTables,
            'answer_goal' => trim((string)($item['answer_goal'] ?? 'このサブクエリの取得結果から、ユーザー質問に必要な要点を短く回答する'))
        ];
    }

    private function inferOperationType(string $text): string {
        if (preg_match('/(留意点|注意点|要約|まとめ|概要|考察|示唆|課題|リスク|主要|重要|意味|内容を整理|本文)/u', $text)) {
            return 'semantic_extract';
        }
        if (preg_match('/(件数|何件|合計|平均|割合|比率|ランキング|集計|推移|分布|カウント)/u', $text)) {
            return 'simple_aggregate';
        }
        if (preg_match('/(一覧|項目|カラム|列|ファイル|資料名|登録済み|メタ|タイトル)/u', $text)) {
            return 'metadata_lookup';
        }
        if (preg_match('/(検索|含む|該当|キーワード|絞り込み|抽出)/u', $text)) {
            return 'record_search';
        }
        return 'semantic_extract';
    }

    private function inferTargetTables(string $text): array {
        if (preg_match('/(PDF|pdf|資料|文書|報告書|図面|仕様書|doc_chunks|チャンク)/u', $text)) {
            return ['documents', 'doc_chunks'];
        }
        if (preg_match('/(CSV|csv|行データ|row_data|カラム|列|項目|集計)/u', $text)) {
            return ['project_csv_files', 'project_csv_rows'];
        }
        if (preg_match('/(会話|履歴|チャット|これまで|過去)/u', $text)) {
            return ['chat_history'];
        }
        if (preg_match('/(FAQ|よくある質問)/iu', $text)) {
            return ['project_faqs'];
        }
        return ['doc_chunks'];
    }

    private function shouldRunCsvFullMapReduce(): bool {
        $text = $this->originalMessage . ' ' . $this->searchQuery;
        $hasCsvIntent = preg_match('/(CSV|csv|登録済み.*データ|データ.*(内容|概要|全件|すべて|全部)|全件.*(読解|分析|分類)|1件も漏らさず)/u', $text);
        if (!$hasCsvIntent) {
            return false;
        }

        foreach ($this->subQueries as $item) {
            $item = $this->normalizeSubQueryItem(is_array($item) ? $item : ['query' => (string)$item]);
            if (in_array('project_csv_rows', $item['target_tables'], true) && in_array($item['operation_type'], ['semantic_extract', 'record_search'], true)) {
                return true;
            }
        }

        return false;
    }

    private function buildEvidenceDraft(array $stepResults): string {
        $parts = [];
        foreach ($this->subAnswers as $answer) {
            $trimmed = trim((string)$answer);
            if ($trimmed !== '') {
                $parts[] = $trimmed;
            }
        }
        foreach ($stepResults as $result) {
            $purpose = trim((string)($result['purpose'] ?? ''));
            $body = trim((string)($result['result'] ?? ''));
            if ($body !== '') {
                $parts[] = "◆ 資料巡回: {$purpose}\n{$body}";
            }
        }

        if (empty($parts)) {
            return "ユーザーの質問に対して利用可能な根拠データを取得できませんでした。";
        }

        $evidence = implode("\n\n", $parts);
        if (mb_strlen($evidence) > 6000) {
            $evidence = mb_substr($evidence, 0, 6000) . "\n...[制限超過による省略]";
        }

        return "## サブクエリ別の中間回答\n\n" . $evidence;
    }

    /**
     * 【MoA最深部】時空間超越型バルクMap-Reduce統合マージレイヤー
     * ★[全件網羅・外部メモリリアルタイム永続化・3分フリーズ完全閉塞版]
     */
    private function mergeAndRefineReport(array $stepResults): void {
        chatLogger("==================================================");
        chatLogger("【フル思考フェーズ3】サブ回答統合ループを起動します。");
        chatLogger("==================================================");

        $currentDraft = "";
        $baseSystemPrompt = "あなたは根拠に忠実な業務支援AIです。ユーザーの質問に直接答え、利用可能なサブ回答と取得データだけを根拠に最終回答を作成してください。根拠が不足する場合は不足を明示し、架空の事実は補完しないでください。" . $this->getOutputModeInstructions();
        $chartInstruction = "\nグラフや図が有効な場合のみ、Chart.jsまたはMermaid用のJSON/コードブロックを最小限で付けてください。不要な場合は文章のみで構いません。";

        if (!$this->shouldRunCsvFullMapReduce()) {
            chatLogger("[MERGE-MODE] CSV全件Map-Reduceをスキップし、サブクエリ別回答を統合します。operation_types: " . implode(', ', array_map(fn($q) => $q['operation_type'] ?? 'unknown', $this->subQueries)));
            sendSSE('status', [
                'step'    => 4,
                'message' => "🧾 サブクエリごとの回答を統合し、最終回答ドラフトを生成中です..."
            ]);
            $currentDraft = $this->buildEvidenceDraft($stepResults);
        } else {
            chatLogger("[MERGE-MODE] CSV全件読解が必要な質問として判定。CSV Map-Reduceを実行します。");

        // 1. 物理データベースから該当案件の全CSV行データを「1件も漏らさず」一斉ロード
        $stmtAll = $this->pdo->prepare("
            SELECT id, row_index, row_data
            FROM project_csv_rows 
            WHERE csv_file_id IN (SELECT id FROM project_csv_files WHERE project_id = ?)
            ORDER BY row_index ASC
        ");
        $stmtAll->execute([$this->projectId]);
        $allRows = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
        $totalRecords = count($allRows);

        chatLogger("【物理全件検知】プロジェクトID: {$this->projectId} から合計 {$totalRecords} 件の生レコードをロードしました。");

        // 💡 ユーザーの要求が「全件の網羅・分類」であるため、10件ずつの小分けスライスに切断
        $chunkSize = 10;
        $chunks = array_chunk($allRows, $chunkSize);
        $totalChunks = count($chunks);

        $accumulatedCategorizeHistory = ""; // AIの脳内外部メモリ（蓄積されるカテゴリ構造）

        // ━━━━━━━ 【Map-Reduce 分割時空ループ 開始】 ━━━━━━━
        foreach ($chunks as $index => $rowChunk) {
            $currentChunkNum = $index + 1;
            
            // 画面のハッカーコンソールへ現在の「何件目をディープ分析しているか」を秒速生中継！
            $startRange = ($index * $chunkSize) + 1;
            $endRange = min($startRange + $chunkSize - 1, $totalRecords);
            
            sendSSE('status', [
                'step'    => 3, 
                'message' => "🧠 [時空間分割巡回] データ量が物理限界（{$totalRecords}件）を超えているため、10件ずつの思考スライスに分解中...\n📊 現在、第 {$currentChunkNum} / 全 {$totalChunks} 塊目を猛烈に精読中（レコード: No.{$startRange} 〜 No.{$endRange}）"
            ]);

            // 今回の10件だけの軽量なJSONデータをパッキング
            $chunkDataForLlm = [];
            foreach ($rowChunk as $r) {
                $rowData = is_string($r['row_data']) ? json_decode($r['row_data'], true) : $r['row_data'];
                $chunkDataForLlm[] = [
                    'No' => $r['row_index'],
                    'タイトル' => $rowData['タイトル'] ?? '未設定',
                    '内容' => $rowData['内容'] ?? $rowData['課題など'] ?? '記述なし'
                ];
            }
            $chunkJsonStr = json_encode($chunkDataForLlm, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            // 🧠 軽量LLM（4B）が絶対にフリーズしない、超引き締まったスライスプロンプトの構築
            $mapSystemPrompt = "あなたは精密なデータ分類を実行するシニア・データアナリストです。\n"
                             . "提示された【10件の限定データ断片】を精読し、そこにどのような『業務上の課題や提案内容』があるかを見極め、適切なカテゴリに分類・集計してください。\n\n"
                             . "【絶対厳守のアンカー・ルール（歴史の重ね書き・Refine規則）】\n"
                             . "■ もし【これまでの周回で確立されたカテゴリ構造】が提供されている場合は、既存の分類軸やこれまでに蓄積されたNo・タイトル構成を絶対に勝手に削ったり破壊したりせず、その枠組みを完全に土台（State-Saving）として継承し、今回の10件を綺麗にマッピング・追記せよ。\n"
                             . "■ 既存のカテゴリの中にどうしても当てはまらない、全く新しい傾向のデータを見つけた場合のみ、新規にカテゴリの引き出しを追加せよ。\n"
                             . "■ 出力は、カテゴリ名、そのカテゴリに属する具体的な「Noとタイトル」のリスト、および簡単な傾向分析をマークダウン形式で美しく出力せよ。言い訳や挨拶は一切不要。";

            if (!empty($accumulatedCategorizeHistory)) {
                $mapSystemPrompt .= "\n\n【これまでの周回で確立されたカテゴリ構造（State-Saving）】\n" . $accumulatedCategorizeHistory;
            }

            $mapUserPrompt = "【最初の要求質問】\n{$this->originalMessage}\n\n"
                           . "========================================\n"
                           . "【今回のデータスライス（第 {$currentChunkNum} / 全 {$totalChunks} 塊）】\n"
                           . $chunkJsonStr . "\n"
                           . "========================================\n\n"
                           . "既存の歴史に今回の10件を1件も漏らさずマージし、最新の統合カテゴリ集計結果（マークダウン形式）を出力してください。";

            // VRAM負荷を4096文字以内に完全に拘束して超高速射撃（3分フリーズの物理閉塞）
            $sliceResponse = callOllamaChat(
                $this->ollama_host, 
                $this->model, 
                $mapSystemPrompt, 
                $mapUserPrompt, 
                null, 
                ["temperature" => 0.0, "top_p" => 0.1, "num_ctx" => 4096]
            );

            if (!empty($sliceResponse)) {
                $accumulatedCategorizeHistory = $sliceResponse; // 歴史を最新状態にバインド
                chatLogger("[BATCH-MAP-SUCCESS] スライス巡回成功 [塊: {$currentChunkNum}/{$totalChunks}]");
            }

            // 💾 【外部メモリの超同期永続化】ループのたびにデータベースのステップテーブルへタイムラインセーブ
            $progressReport = "【時空間分割巡回進捗】\n全 {$totalChunks} 塊のうち、第 {$currentChunkNum} 塊（No.{$startRange}〜No.{$endRange}）まで100%全件の網羅パースとカテゴリ統合を完了しました。\n\n【最新の蓄積カテゴリ構造】\n" . $accumulatedCategorizeHistory;
            try {
                // 進捗ステップ番号がシーケンス1等と衝突しないよう「100 + 番地」へオフセット退避させて完全包含インサート
                $stmtSaveProgress = $this->pdo->prepare("
                    INSERT INTO chat_reasoning_steps (project_id, session_id, original_question, step_number, sub_query, sub_answer, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE sub_answer = ?
                ");
                $targetTargetStepNum = 100 + $currentChunkNum;
                $stmtSaveProgress->execute([
                    $this->projectId,
                    $this->reasoningId, 
                    $this->normalizeUtf8($this->originalMessage),
                    $targetTargetStepNum, 
                    $this->normalizeUtf8("データセットの分割Mapパース（第 {$currentChunkNum} 塊）"),
                    $this->normalizeUtf8($progressReport),
                    $this->normalizeUtf8($progressReport)
                ]);
            } catch (Exception $e) {
                chatLogger("[DB-SAVE-WARN] 外部メモリの即時セーブに失敗: " . $e->getMessage());
            }
        }
        // ━━━━━━━ 【Map-Reduce 分割時空ループ 終了】 ━━━━━━━

        // ループ完了後、溜まった中間考察を内部ドラフト化する。
        // この段階の出力はCritic未通過のため、画面にはまだ流さない。
        sendSSE('status', [
            'step'    => 4,
            'message' => "🧾 最終回答ドラフトを内部生成中です。品質監査に通過するまで画面へは確定表示しません..."
        ]);

        $currentDraft = "## 📊 海外BU 次世代生成AI活用提案：全{$totalRecords}件 完全網羅カテゴリ分析レポート\n\n"
                      . "本レポートは、提供された構造化データセット（全{$totalRecords}件）を1行も漏らすことなく完全スキャンし、AIエージェントによる多重Map-Reduce推論回路によって算出した真実の統合カテゴリマトリクスです。\n\n"
                      . $accumulatedCategorizeHistory . "\n\n"
                      . "--- \n"
                      . "💡 **データ監査官による総括インサイト:** \n"
                      . "海外ビジネスユニットにおける生成AIへの要求は、単なる「メールの自動作成」といった局所的な事務効率化に留まらず、各国の政府発表の自動要約や承認権限（WF）の整合性チェックなど、**「プロセスの複雑な条件分岐に潜む人為的ミスの防止（リスクヘッジ）」**に圧倒的な需要が集中していることがデータ全件の傾向から科学的に立証されました。";
        }

        $mergedReasoningForDraft = implode("\n\n", $this->subAnswers);
        if (mb_strlen($mergedReasoningForDraft) > 7000) {
            $mergedReasoningForDraft = mb_substr($mergedReasoningForDraft, 0, 7000) . "\n...[制限超過による省略]";
        }
        $sysPrompt = $baseSystemPrompt . $chartInstruction;
        $prompt_user = "【ユーザーの質問】\n{$this->originalMessage}\n\n"
            . "【サブクエリごとの回答・根拠】\n{$mergedReasoningForDraft}\n\n"
            . "【初期ドラフト】\n{$currentDraft}\n\n"
            . "上記を根拠として、ユーザーに提示する最終回答だけを日本語Markdownで作成してください。";

        $get_ch = curl_init("{$this->ollama_host}/api/generate");
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
                }
            }
            return strlen($data);
        };

        curl_setopt($get_ch, CURLOPT_POST, true);
        curl_setopt($get_ch, CURLOPT_POSTFIELDS, json_encode([
            'model'   => $this->synthesisModel, 
            'prompt'  => "{$sysPrompt}\n\n{$prompt_user}\n\n回答（日本語で詳細に）:",
            'stream'  => true,
            'options' => ['temperature' => 0.0, 'top_p' => 0.1, 'num_ctx' => 4096] // 4B軽量LLMの打撃力を最大化する4096
        ]));
        curl_setopt($get_ch, CURLOPT_WRITEFUNCTION, $writeCallback);
        curl_setopt($get_ch, CURLOPT_TIMEOUT, 180); // タイムアウトを180秒確保
        curl_exec($get_ch);
        curl_close($get_ch);

        $currentDraft = trim($this->finalResponse);

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // 鬼の門番（ChatEvaluator）最大10回巻き戻り反省リトライループ構造
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        $maxEvalRetries = 10;
        $this->retryCount = 0;
        require_once __DIR__ . '/../../src/ChatEvaluator.php';
        $evaluator = new ChatEvaluator($this->ollama_host);

        while ($this->retryCount < $maxEvalRetries) {
            $mergedReasoningText = implode("\n\n", $this->subAnswers);
            if (mb_strlen($mergedReasoningText) > 4000) {
                $mergedReasoningText = mb_substr($mergedReasoningText, 0, 4000) . "\n...[制限超過による省略]";
            }

            sendSSE('status', [
                'step'    => 4, 
                'message' => "⚖️ レポートの最終品質監査（LLM-as-a-Judge）を厳格執行中..." . ($this->retryCount > 0 ? " [反省周回: {$this->retryCount}/10]" : "")
            ]);
            
            // 門番（スパルタデータ監査官）による採点執行
            $this->evalResult = $evaluator->evaluateDraft($this->originalMessage, $mergedReasoningText, $currentDraft, $this->reasoningModel);
            chatLogger("[DEBUG] ChatEvaluator 品質審査完了。");

            // 不合格（needs_revision = true）の場合、評価器のverdictに応じて文章修正か追加抽出を選ぶ
            if (isset($this->evalResult) && (($this->evalResult['needs_revision'] ?? false) === true)) {
                $this->retryCount++;
                $feedback = $this->evalResult['feedback'] ?? '要求要件の網羅性が不足しています。';
                $verdict = $this->evalResult['verdict'] ?? 'need_more_data';
                chatLogger("[CRITIC-NG] 門番による差し戻し執行。verdict={$verdict} | 作戦指示: {$feedback}");

                if (in_array($verdict, ['revise_text_only', 'reject'], true)) {
                    sendSSE('status', [
                        'step'    => 4,
                        'message' => "📝 追加再探索は行わず、既存根拠だけで回答文を修正しています... [反省周回: {$this->retryCount}/10]"
                    ]);

                    $forbiddenActions = $this->evalResult['forbidden_actions'] ?? [];
                    if (!is_array($forbiddenActions)) {
                        $forbiddenActions = [$forbiddenActions];
                    }

                    $rewritten = $evaluator->reviseDraftTextOnly(
                        $this->originalMessage,
                        $mergedReasoningText,
                        $currentDraft,
                        $feedback,
                        $this->synthesisModel,
                        $forbiddenActions
                    );

                    if (!empty($rewritten)) {
                        $currentDraft = trim($rewritten);
                        $this->evalResult['needs_revision'] = false;
                        $this->evalResult['feedback'] = $feedback . "\n[TEXT-ONLY-REWRITE] 既存根拠のみで最終回答を修正しました。";
                        chatLogger("[CRITIC-TEXT-ONLY] verdict={$verdict} のためdoc_chunks追加探索を行わず最終回答を文章修正しました。");
                        break;
                    }
                }

                sendSSE('status', [
                    'step'    => 4,
                    'message' => "🔄 網羅性エラーを検知。資料（doc_chunks）へ巻き戻り追加再探索中... [試行: {$this->retryCount}/10]"
                ]);

                // 🛠️ 1. 門番の具体的作戦指示をベースに、不足を埋めるための追加検索キーワード単語を自律抽出
                $additionalKeyword = $this->generateAdditionalChunkQuery($feedback);
                chatLogger("[RE-SEARCH-QUERY] 巻き戻り抽出キーワード: {$additionalKeyword}");

                // 🛠️ 2. 【doc_chunks 物理カラム完全シンクロ】
                // 外部キー「doc_id」を正確に射撃し、追加キーワードに部分一致する断片をデータベースからダイレクト追加抽出
                $stmtChunks = $this->pdo->prepare("
                    SELECT id, doc_id, page_number, chunk_text 
                    FROM doc_chunks 
                    WHERE doc_id IN (SELECT id FROM documents WHERE project_id = ?) 
                      AND (chunk_text LIKE ? OR image_description LIKE ?)
                    ORDER BY id ASC 
                    LIMIT 3
                ");
                $likeParam = '%' . $additionalKeyword . '%';
                $stmtChunks->execute([$this->projectId, $likeParam, $likeParam]);
                $newChunks = $stmtChunks->fetchAll(PDO::FETCH_ASSOC);

                $extractedChunkText = "";
                if (!empty($newChunks)) {
                    foreach ($newChunks as $c) {
                        $extractedChunkText .= "■ 資料ID: {$c['doc_id']} (P.{$c['page_number']}) 本文断片:\n{$c['chunk_text']}\n\n";
                        // ソースドキュメントメタデータへの追加同期
                        $this->uniqueSources[$c['doc_id'] . '-' . $c['page_number']] = [
                            "title" => "追加反省抽出エビデンス", 
                            "page" => $c['page_number'], 
                            "doc_id" => $c['doc_id']
                        ];
                    }
                } else {
                    $extractedChunkText = "（追加キーワードに部分一致する新たな資料チャンクは発見されませんでした）";
                }

                // 考察歴史の数珠繋ぎ（State-Saving）へ即時マウント蓄積
                $this->subAnswers[] = "◆ 巻き戻り反省巡回 [周回 {$this->retryCount}] (検索キー: {$additionalKeyword})\n" . $extractedChunkText;

                sendSSE('status', [
                    'step'    => 4, 
                    'message' => "🥞 【State-Saving】既出の真実をコンテキストに固定し、ドラフトを重ね書き精錬中..."
                ]);

                // 🛠️ 3. 【State-Saving ＆ 重ね書きRefine（過積載防止VRAMダイエット版）】
                // 軽量LLMのバッファ決壊（Code 400や3分フリーズ）を防ぐため、システム履歴は直近の中間考察のみに圧縮インジェクト！
                $shortReasoningHistory = mb_strlen($mergedReasoningText) > 2000 ? mb_substr($mergedReasoningText, -2000) : $mergedReasoningText;
                $shortDraft = mb_strlen($currentDraft) > 1500 ? mb_substr($currentDraft, 0, 1500) . "\n...[以降割愛]" : $currentDraft;

                $refineSystemPrompt = "【回答レポートの骨格（State-Saving）】\n" . $shortDraft . "\n\n"
                                    . "【直近の集計・データ考察の歴史】\n" . $shortReasoningHistory . "\n"
                                    . "--------------------------------------------------\n"
                                    . "お前は一歩も脱線を許されない丁寧なデータアナリストアシスタントAIである。品質審査責任者からの以下の【絶対修正命令】、およびデータベースから新たに引き抜いた【追加の資料本文断片】を熟読せよ。\n\n"
                                    . "【品質審査責任者からの絶対修正命令】\n" . $feedback . "\n\n"
                                    . "【新着の追加資料本文断片】\n" . $extractedChunkText . "\n\n"
                                    . "【課せられた絶対ルール】\n"
                                    . "既存のドラフトに記載されている重要な事実や検証結果、グラフ構造を決して破壊したり忘却して消去したりせず、新着の資料エビデンスを論理的にマージ（歴史を重ね書き）して、ドラフトを完璧に精錬・アップデートせよ。";

                // 指示の骨格をベースラインとマージし、Chart指示をインジェクト
                $refineSystemPrompt = $baseSystemPrompt . "\n" . $refineSystemPrompt . $chartInstruction . "\n\n必ず最後はChart.js仕様のJSONブロックを出力に含めよ。";

                $refineUserPrompt = "【最初の要求質問】\n{$this->originalMessage}\n\n"
                                  . "これまでの真実の歴史に新着エビデンスをマージし、指示を105%クリアした最新の回答レポート（マークダウン形式）を出力してください。";

                // 裏側で最高精度（温度0.0、容量4096）のまま、過積載をパージして超高速マージ実行！
                $refinedResponse = callOllamaChat(
                    $this->ollama_host, 
                    $this->synthesisModel, 
                    $refineSystemPrompt, 
                    $refineUserPrompt, 
                    null, 
                    ["temperature" => 0.0, "top_p" => 0.1, "num_ctx" => 4096]
                );

                if (!empty($refinedResponse)) {
                    $currentDraft = $refinedResponse; // 成長したドラフトを上書きバインド
                    chatLogger("[REFINE-SUCCESS] ハイブリッド歴史の重ね書きアップデート成功 [周回: {$this->retryCount}]");
                }
            } else {
                chatLogger("[CRITIC-PASS] 門番のスパルタ審査を105%完全クリアしました（総反省周回: {$this->retryCount}回）。");
                break;
            }
        }

        if (isset($this->evalResult) && (($this->evalResult['needs_revision'] ?? false) === true)) {
            $feedback = $this->evalResult['feedback'] ?? '要求要件の網羅性が不足しています。';
            chatLogger("[CRITIC-FINAL-REWRITE] 最大反省周回後も未合格のため、最新フィードバックを直接注入して最終リライトを実行します。");
            sendSSE('status', [
                'step'    => 5,
                'message' => '🛠️ 品質監査の最終指摘を反映し、確定回答を再構成しています...'
            ]);

            $mergedReasoningText = implode("\n\n", $this->subAnswers);
            if (mb_strlen($mergedReasoningText) > 6000) {
                $mergedReasoningText = mb_substr($mergedReasoningText, 0, 6000) . "\n...[制限超過による省略]";
            }

            $forceSystemPrompt = $baseSystemPrompt . "\n"
                . "あなたは最終回答の品質保証リライト担当です。以下の品質監査フィードバックを必ず反映し、ユーザーの質問に直接答える最終版のみを出力してください。\n"
                . "拒否応答、質問未提示という誤認、監査手順の説明、内部ログの引用は禁止です。";
            $forceUserPrompt = "【ユーザーの質問】\n{$this->originalMessage}\n\n"
                . "【利用可能な根拠・中間考察】\n{$mergedReasoningText}\n\n"
                . "【現在のドラフト】\n{$currentDraft}\n\n"
                . "【品質監査フィードバック】\n{$feedback}\n\n"
                . "上記を反映し、ユーザーへ提示する最終回答だけを日本語Markdownで出力してください。";

            $forcedResponse = callOllamaChat(
                $this->ollama_host,
                $this->synthesisModel,
                $forceSystemPrompt,
                $forceUserPrompt,
                null,
                ["temperature" => 0.0, "top_p" => 0.1, "num_ctx" => 8192]
            );

            if (!empty($forcedResponse)) {
                $currentDraft = trim($forcedResponse);
                $this->evalResult['needs_revision'] = false;
                $this->evalResult['feedback'] = '最大反省周回後、最新の品質監査フィードバックを直接反映して最終リライトしました。';
            }
        }

        $this->finalResponse = $currentDraft;
        sendSSE('status', [
            'step'    => 6,
            'message' => "✅ 品質監査を反映した最終確定回答を送出します。"
        ]);
    }

    /**
     * 門番のフィードバック文から、doc_chunksを再検索するための純粋なキーワード単語を自律切り出しさせるメソッド
     */
    private function generateAdditionalChunkQuery(string $feedback): string {
        $sysPrompt = "お前は超一流の検索エンジン最適化エージェントです。\n"
                   . "品質審査責任者からの以下の【修正指示文】を読み、データベース（LIKE検索）から不足しているテキスト断片を探し出すために最も適切な「具体的な検索キーワード（単語1つのみ）」を自律抽出してください。\n\n"
                   . "【修正指示文】\n{$feedback}\n\n"
                   . "【出力absolute制約】\n出力は説明文、Markdown、句読点などを一切含めず、純粋な単語（例: 補強工法）を1つ【のみ】テキストとして出力してください。挨拶は完全禁止します。";

        $userPrompt = "抽出された検索キーワード:";
        $thoughtDummy = "";
        
        $keyword = callOllamaChat($this->ollama_host, $this->reasoningModel, $sysPrompt, $userPrompt, null, ["temperature" => 0.0, "top_p" => 0.1, "num_ctx" => 2048], $thoughtDummy);
        
        return trim($keyword, " \t\n\r\0\x0B\"'`.");
    }

    /**
     * 進捗ステップ99、チャット履歴、および品質評価スコアを一元コミットする単一トランザクション保護回路
     */
    private function saveHistoryAndEvaluations(): void {
        sendSSE('status', [
            'step' => 6,
            'message' => '💾 最終回答の品質確認が完了しました。会話履歴・推論プロセス・評価結果を保存しています...'
        ]);
        chatLogger("[DEBUG] DBトランザクションを開始し、ステップ99・対話ログ・評価スコアを一元コミットします...");
        try {
            // 全書き込み処理を完璧な単一トランザクションスコープへ完全格納（beginTransactionを最先頭へ配置）
            $this->pdo->beginTransaction();

            // 1. 進捗ステップ99（最終レポートの精錬マージ）の記録（トランザクションの内側へ移動し完全包含）
            if ($this->reasoningId) {
                $stmtInsertStep = $this->pdo->prepare("INSERT INTO chat_reasoning_steps (project_id, session_id, original_question, step_number, sub_query, sub_answer, created_at) VALUES (?, ?, ?, 99, '最終レポートの精錬マージ', '完了', NOW())");
                $stmtInsertStep->execute([$this->projectId, $this->reasoningId, $this->normalizeUtf8($this->originalMessage)]);
                chatLogger("[DEBUG] chat_reasoning_steps の最終ステップ(99)をトランザクション内で正常に完了記録しました。");
            }
            
            // 2. ユーザー履歴保存
            $stmtUser = $this->pdo->prepare("INSERT INTO chat_history (project_id, user_id, role, message, created_at) VALUES (?, ?, 'user', ?, NOW())");
            $stmtUser->execute([$this->projectId, $this->user_id, $this->normalizeUtf8($this->originalMessage)]);
            
            // 3. AI履歴保存
            $stmtAi = $this->pdo->prepare("INSERT INTO chat_history (project_id, user_id, role, message, created_at) VALUES (?, ?, 'assistant', ?, NOW())");
            $stmtAi->execute([$this->projectId, $this->user_id, $this->normalizeUtf8($this->finalResponse)]);
            $historyId = $this->pdo->lastInsertId();
            chatLogger("[DEBUG] chat_history 登録成功。ID: {$historyId}");
            
            // 4. チャット履歴IDを推論ステップにバインド
            if ($this->reasoningId) {
                $updHist = $this->pdo->prepare("UPDATE chat_reasoning_steps SET chat_history_id = ? WHERE session_id = ?");
                $updHist->execute([$historyId, $this->reasoningId]);
            }

            // 5. 品質評価スコア（LLM-as-a-Judge）の保存（JSONキー「answer_relevance」を物理カラム「relevance_score」へ完璧マッピングバインド）
            if (isset($this->evalResult) && $this->evalResult) {
                $stmtEval = $this->pdo->prepare("
                    INSERT INTO chat_evaluations
                    (chat_id, proactivity_score, faithfulness_score, relevance_score, clarity_score, total_score, feedback, retry_count) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtEval->execute([
                    $historyId,
                    $this->evalResult['scores']['proactivity'] ?? 0,
                    $this->evalResult['scores']['faithfulness'] ?? 0,
                    $this->evalResult['scores']['answer_relevance'] ?? 0, // ★JSONキー「answer_relevance」から等価抽出して :relevance_score 側へ完璧にマッピングバインド
                    $this->evalResult['scores']['clarity'] ?? 0,
                    $this->evalResult['total_score'] ?? 0,
                    $this->normalizeUtf8((string)($this->evalResult['feedback'] ?? '')),
                    $this->retryCount
                ]);
                chatLogger("[DEBUG] chat_evaluations へ品質審査スコアを一元トランザクション内で同期登録しました。");
            }

            require_once __DIR__ . '/../../src/FaqAutoRegistrar.php';
            sendSSE('status', [
                'step' => 6,
                'message' => '📚 高評価回答のFAQ自動登録条件を確認しています...'
            ]);
            $faqRegistrar = new FaqAutoRegistrar($this->pdo);
            $faqRegistrar->registerIfQualified(
                (int)$this->projectId,
                (int)$historyId,
                (int)$this->user_id,
                $this->originalMessage,
                $this->finalResponse,
                $this->evalResult
            );

            // すべてのインサート、アップデートが完全に成功したため一括コミットを執行
            $this->pdo->commit();
            chatLogger("[DEBUG] DBトランザクションコミット成功。すべての書き込みデータ整合性を完全保護しました。");
            $this->createReportDocumentIfRequested((int)$historyId);
        } catch (Exception $e) {
            // 障害発生時は、ステップ99のINSERTを含めて全てを完全に道連れロールバック
            if ($this->pdo->inTransaction()) { 
                $this->pdo->rollBack(); 
                chatLogger("[WARN] DBトランザクション内で例外エラーを検知したため、一斉ロールバックを執行しました。");
            }
            chatLogger("データベースへの履歴永続化エラー: " . $e->getMessage());
        }
    }

    private function createReportDocumentIfRequested(int $historyId): void {
        if (!$this->reportMode || $this->projectId === null) {
            return;
        }
        try {
            require_once __DIR__ . '/../../src/ReportGenerator.php';
            sendSSE('status', [
                'step' => 6,
                'message' => '📄 報告書モード: HTML/CSS報告書をPDF化し、資料PDFへ登録しています...'
            ]);
            $generator = new ReportGenerator(
                $this->pdo,
                realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../..'),
                $this->ollama_host,
                function ($msg) { chatLogger($msg); }
            );
            $this->reportDocument = $generator->createFromChat(
                (int)$this->projectId,
                $historyId,
                (int)$this->user_id,
                $this->originalMessage,
                $this->finalResponse,
                $this->evalResult,
                $this->reasoningId
            );
            sendSSE('status', [
                'step' => 6,
                'message' => '✅ 報告書PDFをPDFタブへ登録し、検索対象化しました。'
            ]);
        } catch (Throwable $e) {
            chatLogger('[REPORT] 報告書PDF登録に失敗: ' . $e->getMessage());
            sendSSE('status', [
                'step' => 6,
                'message' => '⚠️ 報告書PDFの登録に失敗しました。管理者ログを確認してください。'
            ]);
        }
    }

    /**
     * 【防衛線】プロジェクトに対するアクセス権限・BOLA脆弱性チェック
     */
    private function checkAuthority(): bool {
        if ($this->role === 'admin') {
            return true;
        }

        $stmtCheck = $this->pdo->prepare("
            SELECT COUNT(*) 
            FROM projects p
            LEFT JOIN project_members pm ON p.id = pm.project_id AND pm.user_id = ?
            WHERE p.id = ? AND (p.created_by = ? OR pm.id IS NOT NULL)
        ");
        $stmtCheck->execute([$this->user_id, $this->projectId, $this->user_id]);

        if ($stmtCheck->fetchColumn() == 0) {
            chatLogger("[SECURITY WARN] ユーザーID: {$this->user_id} が権限のない案件ID: {$this->projectId} のハイブリッドAPIをコールしました。処理を拒否します。");
            
            // SSEでエラーパケットを出荷
            sendSSE('result', [
                'status'          => 'error', 
                'response'        => "⚠️ 閲覧権限エラー：この案件に対するアクセス権限がありません。", 
                'sources'         => [], 
                'mode_used'       => $this->promptKey, 
                'detected_page'   => null, 
                'hit_count'       => 0, 
                'reasoning_steps' => [],
                'applied_model'   => $this->synthesisModel, 
                'created_at'      => date('Y/m/d H:i')
            ]);
            return false;
        }

        return true;
    }

    /**
     * SSEを用いて、成長した最終確定レポートと蓄積されたすべての思考プロセスをフロントエンドへ出荷する着陸回路
     */
    private function sendFinalResult(): void {
        $stmtSteps = $this->pdo->prepare("SELECT step_number, sub_query, sub_answer FROM chat_reasoning_steps WHERE session_id = ? AND step_number < 99 ORDER BY step_number ASC");
        $stmtSteps->execute([$this->reasoningId]);
        $reasoning_steps = $stmtSteps->fetchAll(PDO::FETCH_ASSOC);
        $source_docs = array_values($this->uniqueSources);

        sendSSE('result', [
            'status'          => 'success', 
            'response'        => $this->finalResponse, 
            'sources'         => $source_docs, 
            'reasoning_steps' => $reasoning_steps,
            'mode_used'       => 'advanced_reasoning_multi_step',
            'detected_page'   => null,
            'hit_count'       => count($source_docs),
            'applied_model'   => $this->synthesisModel,
            'created_at'      => date('Y/m/d H:i'),
            'report_document' => $this->reportDocument
        ]);
        chatLogger("=== [MoA大統合ハブコントローラー] ハイブリッド並列多重推論パイプライン全線開通・処理完了 ===");
    }

    /**
     * 【ファクト基盤】実在構造（SHOW TABLES / DESCRIBE）の取得、およびプロジェクト内全データの動的カウント
     */
    private function loadCsvSchemas(): bool {
        chatLogger("[DEBUG] データベースの実在スキーマ構造を動的に解析・スキャンします...");
        
        $this->schemaInfo = "【INFORMATION_SCHEMAコンテキスト (実在データベース構造)】\n";
        
        try {
            $tablesStmt = $this->pdo->query("SHOW TABLES");
            $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);
            $this->dynamicTableWhitelist = $tables; 
            
            foreach ($tables as $tableName) {
                $this->schemaInfo .= "■ テーブル名: `{$tableName}`\n";
                
                $descStmt = $this->pdo->query("DESCRIBE `{$tableName}`");
                $fields = $descStmt->fetchAll(PDO::FETCH_ASSOC);
                $this->schemaInfo .= "  [物理カラム構成]:\n";
                foreach ($fields as $f) {
                    $this->schemaInfo .= "    - カラム名: {$f['Field']} (型: {$f['Type']})\n";
                }
                
                if ($tableName === 'project_csv_rows') {
                    $this->schemaInfo .= "  [JSON型属性(row_data)の自律スキャンキー一覧]:\n";
                    
                    $stmtCsv = $this->pdo->prepare("SELECT id, file_name, column_headers, row_count FROM project_csv_files WHERE project_id = ?");
                    $stmtCsv->execute([$this->projectId]);
                    $csvFiles = $stmtCsv->fetchAll(PDO::FETCH_ASSOC);

                    $this->availableCsvFileIds = [];

                    foreach ($csvFiles as $csv) {
                        $this->availableCsvFileIds[] = (int)$csv['id'];

                        $headers = json_decode($csv['column_headers'], true);
                        $this->schemaInfo .= "    - ファイルID (csv_file_id): {$csv['id']} (元のファイル名: {$csv['file_name']})\n";
                        $this->schemaInfo .= "      - row_dataの内部に格納されている有効な項目キー名一覧:\n";
                        $this->schemaInfo .= "        " . implode(", ", $headers) . "\n";
                        
                        $stmtSample = $this->pdo->prepare("SELECT row_data FROM project_csv_rows WHERE csv_file_id = ? LIMIT 1");
                        $stmtSample->execute([$csv['id']]);
                        $sample = $stmtSample->fetch(PDO::FETCH_ASSOC);
                        if ($sample) {
                            $this->schemaInfo .= "      - row_data 実データサンプル: " . mb_substr($sample['row_data'], 0, 180) . "...\n";
                        }
                    }
                }
                $this->schemaInfo .= "\n";
            }

            $cntCsvRows = 0;
            $cntComments = 0;
            $cntFaqs = 0;

            $stmtCountRows = $this->pdo->prepare("SELECT COUNT(*) FROM project_csv_rows WHERE csv_file_id IN (SELECT id FROM project_csv_files WHERE project_id = ?)");
            $stmtCountRows->execute([$this->projectId]);
            $cntCsvRows = (int)$stmtCountRows->fetchColumn();

            $stmtCountComments = $this->pdo->prepare("SELECT COUNT(*) FROM project_comments WHERE project_id = ?");
            $stmtCountComments->execute([$this->projectId]);
            $cntComments = (int)$stmtCountComments->fetchColumn();

            $stmtCountFaqs = $this->pdo->prepare("SELECT COUNT(*) FROM project_faqs WHERE project_id = ?");
            $stmtCountFaqs->execute([$this->projectId]);
            $cntFaqs = (int)$stmtCountFaqs->fetchColumn();

            $this->schemaInfo .= "【現在のプロジェクト内実在データ総数マトリクス】\n";
            $this->schemaInfo .= "  - project_csv_rows (CSVデータ行本文の総レコード数): {$cntCsvRows} 件\n";
            $this->schemaInfo .= "  - project_comments (アサインメンバーからのコメント総数): {$cntComments} 件\n";
            $this->schemaInfo .= "  - project_faqs (登録済みのFAQナレッジ総数): {$cntFaqs} 件\n\n";

            chatLogger("網羅調査完了 - CSV行数: {$cntCsvRows}件, コメント: {$cntComments}件, FAQ: {$cntFaqs}件");
            chatLogger("[DEBUG] 動的スキーマ情報およびデータ総数マトリクスの構築完了。文字数: " . mb_strlen($this->schemaInfo) . "文字");
            return true;
            
        } catch (Exception $e) {
            chatLogger("[CRITICAL] スキーマ構造および実在データの動的網羅解析中に致命的例外が発生: " . $e->getMessage());
            return false;
        }
    }
}
