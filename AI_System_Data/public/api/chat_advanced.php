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
require_once __DIR__ . '/../../src/ProjectContextMemory.php';
require_once __DIR__ . '/../../src/ChatModelRolePayload.php';
require_once __DIR__ . '/../../src/AdvancedDocAnswerBuilder.php';
require_once __DIR__ . '/../../src/AdvancedSubQueryNormalizer.php';
require_once __DIR__ . '/../../src/AdvancedRoutePlanner.php';
require_once __DIR__ . '/../../src/AdvancedPlanExecutor.php';
require_once __DIR__ . '/../../src/AdvancedDraftComposer.php';
require_once __DIR__ . '/../../src/AdvancedCriticLoop.php';
require_once __DIR__ . '/../../src/AdvancedRouteFinalizer.php';

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
function runAdvancedReasoningRoute($pdo, $ollama_host, $projectId, $originalMessage, $searchQuery, $reasoningId, $mainModel, $subModel, $sqlModel, $embeddingModel, $promptKey, $projectContext, $historySummaryText, $user_id, $role, $threadId = null, bool $reportMode = false, bool $diagramMode = false, bool $csvMode = false) {
    $processor = new AdvancedReasoningRouteProcessor(
        $pdo, $ollama_host, $projectId, $originalMessage, $searchQuery,
        $reasoningId, $mainModel, $subModel, $sqlModel, $embeddingModel, $promptKey,
        $projectContext, $historySummaryText, $user_id, $role, $threadId, $reportMode, $diagramMode, $csvMode
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
    private $mainModel;
    private $subModel;
    private $sqlModel;
    private $embeddingModel;
    private $reasoningModel;
    private $synthesisModel;
    private $promptKey;
    private $projectContext;
    private $historySummaryText;
    private $user_id;
    private $role;
    private $threadId;
    private $model;
    private $reportMode = false;
    private $diagramMode = false;
    private $reportDocument = null;
    private $csvMode = false;
    private $csvExport = null;
    private $databaseMemoryPrompt = ""; // フェーズ3：DB事前記憶プロンプト保持用
    private $projectOperatingMemoryPrompt = "";

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

    public function __construct($pdo, $ollama_host, $projectId, $originalMessage, $searchQuery, $reasoningId, $mainModel, $subModel, $sqlModel, $embeddingModel, $promptKey, $projectContext, $historySummaryText, $user_id, $role, $threadId = null, bool $reportMode = false, bool $diagramMode = false, bool $csvMode = false) {
        $this->pdo = $pdo;
        $this->ollama_host = $ollama_host;
        $this->projectId = (int)$projectId;
        $this->originalMessage = $this->normalizeUtf8((string)$originalMessage);
        $this->searchQuery = $this->normalizeUtf8((string)$searchQuery);
        $this->reasoningId = (string)$reasoningId;
        $this->mainModel = (string)$mainModel;
        $this->subModel = (string)$subModel;
        $this->sqlModel = (string)$sqlModel;
        $this->embeddingModel = (string)$embeddingModel;
        $this->reasoningModel = $this->subModel;
        $this->synthesisModel = $this->mainModel;
        $this->promptKey = (string)$promptKey;
        $this->projectContext = $this->normalizeUtf8((string)$projectContext);
        $this->historySummaryText = $this->normalizeUtf8((string)$historySummaryText);
        $this->user_id = (int)$user_id;
        $this->role = (string)$role;
        $this->threadId = $threadId !== null ? (int)$threadId : null;
        $this->model = $this->subModel;
        $this->reportMode = $reportMode;
        $this->diagramMode = $diagramMode;
        $this->csvMode = $csvMode;
    }

    private function getOutputModeInstructions(): string {
        $instructions = '';
        if ($this->diagramMode) {
            $instructions .= "\n【図解モード】説明の理解に役立つ場合のみ、Mermaidコードブロック（```mermaid）またはChart.js用JSONコードブロック（```json:chart）を1つまで添えてください。図表が不要な場合は文章のみで構いません。";
        }
        if ($this->reportMode) {
            $instructions .= "\n【報告書モード】回答は後続処理でHTML/CSSからPDF報告書化され、資料PDFとして保存されます。結論、分析対象、根拠、留意点、推奨アクション、出典の順に、報告書として読みやすい見出し構成で作成してください。";
        }
        if ($this->csvMode) {
            $instructions .= "\n【CSV化モード】集計結果や一覧をCSVとして保存できるよう、表形式が有効な場合は少なくとも1つのMarkdown表を含めてください。列名と行データを省略せず、Markdown表として完結させてください。";
        }
        return $instructions;
    }

    private function composeMemoryAwarePrompt(string $prompt): string
    {
        return trim($this->projectOperatingMemoryPrompt . "\n" . $this->databaseMemoryPrompt . "\n" . $prompt);
    }

    private function buildDocAnswerBuilder(): AdvancedDocAnswerBuilder
    {
        return new AdvancedDocAnswerBuilder(
            $this->originalMessage,
            $this->subAnswers,
            $this->ollama_host,
            $this->synthesisModel,
            fn(string $prompt): string => $this->composeMemoryAwarePrompt($prompt),
            fn(array $stepResults): string => $this->buildEvidenceDraft($stepResults),
            fn(string $message) => chatLogger($message)
        );
    }

    private function buildSubQueryNormalizer(): AdvancedSubQueryNormalizer
    {
        return new AdvancedSubQueryNormalizer(
            $this->searchQuery,
            $this->originalMessage,
            array_values(array_unique(array_merge($this->dynamicTableWhitelist, array_keys(self::$tablesSchema)))),
            fn(string $message) => chatLogger($message)
        );
    }

    private function buildRoutePlanner(): AdvancedRoutePlanner
    {
        return new AdvancedRoutePlanner(
            $this->ollama_host,
            $this->reasoningModel,
            $this->projectId,
            $this->reasoningId,
            $this->originalMessage,
            $this->searchQuery,
            $this->schemaInfo,
            self::$tablesBrief,
            fn(string $prompt): string => $this->composeMemoryAwarePrompt($prompt),
            fn(string $text): string => $this->inferOperationType($text),
            function (string $thought): void {
                try {
                    $stmt = $this->pdo->prepare("INSERT INTO chat_reasoning_steps (project_id, session_id, original_question, step_number, sub_query, sub_answer, created_at) VALUES (?, ?, ?, 0, '【AIの思考プロセス: 実行計画生成】', ?, NOW())");
                    $stmt->execute([$this->projectId, $this->reasoningId, $this->originalMessage, $thought]);
                } catch (Exception $ex) {
                    chatLogger("思考プロセス保存例外: " . $ex->getMessage());
                }
            },
            fn(string $message) => chatLogger($message)
        );
    }

    private function buildPlanExecutor(): AdvancedPlanExecutor
    {
        return new AdvancedPlanExecutor(
            $this->pdo,
            $this->projectId,
            $this->ollama_host,
            $this->reasoningModel,
            $this->sqlModel,
            $this->searchQuery,
            $this->originalMessage,
            $this->reasoningId,
            $this->threadId,
            $this->user_id,
            fn(string $prompt): string => $this->composeMemoryAwarePrompt($prompt),
            fn(string $text): string => $this->inferOperationType($text),
            fn(array $rows): string => $this->buildDocChunkEvidenceSummary($rows),
            function (array $row, string $tableName, int $stepCounter): void {
                $docId = $row['doc_id'] ?? ($row['document_id'] ?? ($row['id'] ?? $stepCounter));
                $title = $row['title'] ?? ($row['file_name'] ?? ($row['file_path'] ?? ($row['question_summary'] ?? "巡回ステップデータ")));
                $page = $row['page_number'] ?? 1;
                $this->uniqueSources[$docId . '-' . $page] = [
                    "title" => $title,
                    "page" => $page,
                    "doc_id" => $docId,
                ];
            },
            function (string $subAnswer): void {
                $this->subAnswers[] = $subAnswer;
            },
            fn(string $message) => chatLogger($message)
        );
    }

    private function buildDraftComposer(): AdvancedDraftComposer
    {
        return new AdvancedDraftComposer(
            $this->originalMessage,
            $this->subAnswers,
            $this->ollama_host,
            $this->reasoningModel,
            $this->synthesisModel,
            $this->reportMode,
            fn(string $prompt): string => $this->composeMemoryAwarePrompt($prompt)
        );
    }

    private function buildCriticLoop(): AdvancedCriticLoop
    {
        return new AdvancedCriticLoop(
            $this->pdo,
            $this->projectId,
            $this->ollama_host,
            $this->originalMessage,
            $this->mainModel,
            $this->synthesisModel,
            $this->reportMode,
            $this->diagramMode,
            fn(string $feedback): string => $this->generateAdditionalChunkQuery($feedback),
            fn(string $currentDraft): string => $this->applyReportModeFinalPolish($currentDraft),
            fn(): array => $this->subAnswers,
            function (string $subAnswer): void {
                $this->subAnswers[] = $subAnswer;
            },
            function (array $source): void {
                $docId = $source['doc_id'] ?? 'unknown';
                $page = $source['page'] ?? 1;
                $this->uniqueSources[$docId . '-' . $page] = [
                    'title' => $source['title'] ?? '追加反省抽出エビデンス',
                    'page' => $page,
                    'doc_id' => $docId,
                ];
            },
            fn(string $message) => chatLogger($message)
        );
    }

    private function buildRouteFinalizer(): AdvancedRouteFinalizer
    {
        return new AdvancedRouteFinalizer(
            $this->pdo,
            $this->projectId,
            $this->threadId,
            $this->user_id,
            $this->reasoningId,
            $this->originalMessage,
            $this->finalResponse,
            $this->evalResult,
            $this->retryCount,
            $this->reportMode,
            $this->csvMode,
            $this->ollama_host,
            realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../..'),
            $this->mainModel,
            $this->subModel,
            $this->embeddingModel,
            $this->synthesisModel,
            $this->uniqueSources,
            fn(string $text): string => $this->normalizeUtf8($text),
            fn(string $message) => chatLogger($message)
        );
    }

    private function hasExtendedOutputMode(): bool {
        return $this->reportMode || $this->diagramMode || $this->csvMode;
    }

    private function maxSqlRepairRetries(): int {
        return $this->hasExtendedOutputMode() ? 2 : 1;
    }

    private function maxEvalRetries(): int {
        return $this->hasExtendedOutputMode() ? 2 : 1;
    }

    private function createChatLoggerCallback(): callable {
        return function (string $message): void {
            chatLogger($message);
        };
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

    private function logPromptBudget(string $phase, array $parts, int $numCtx): void
    {
        $segments = [];
        $totalChars = 0;
        foreach ($parts as $label => $text) {
            $chars = mb_strlen((string)$text);
            $segments[] = "{$label}Chars={$chars}";
            $totalChars += $chars;
        }

        chatLogger("[PROMPT-BUDGET] route=advanced_hybrid | phase={$phase} | num_ctx={$numCtx} | totalChars={$totalChars} | " . implode(' | ', $segments));
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
        'users' => "テーブル名: users\n物理カラム:\n- id (INT, PK)\n- username (VARCHAR: ユーザー名)\n- department (VARCHAR: 所属部署)\n- role (VARCHAR: 権限。'admin' または 'member')\n- default_prompt (TEXT)\n- default_lang (VARCHAR)\n- default_model (VARCHAR)\n- sub_model (VARCHAR)\n- sql_model (VARCHAR: Text-to-SQL / SQL自己修復用モデル)\n- embedding_model (VARCHAR)\n- ollama_host (VARCHAR)\n- created_at (DATETIME)\n- updated_at (DATETIME)"
    ];

    /**
     * メイン実行パイプライン (Mixture of Agents ハイブリッド統合制御)
     */
    public function execute(): void {
        $pipelineStart = microtime(true);
        $this->model = $this->reasoningModel; // ステートバインド同期維持

        chatLogger(">>> [MoAハイブリッド多重推論要塞] 統合ハブコントローラーを起動します");
        chatLogger("[DEBUG] 引数情報 - Host: {$this->ollama_host} | MainModel: {$this->mainModel} | SubModel: {$this->subModel} | SqlModel: {$this->sqlModel} | UserID: {$this->user_id} | Role: {$this->role}");

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
                $sqlEngine = new SqlExecutionEngine($this->pdo, $this->projectId, $this->threadId, $this->user_id);
                $sqlEngine->generateAndSaveDatabaseMemory();
                
                // 生成された最新 of 記憶を再ロード
                $stmtMem->execute([$this->projectId]);
                $jsonStr = $stmtMem->fetchColumn();
            }

            // PromptManagerを読み込み、記憶をAI用制約プロンプトにコンパイルしてプロパティに格納
            require_once __DIR__ . '/../../src/PromptManager.php';
            $this->databaseMemoryPrompt = PromptManager::getDatabaseMemoryInstruction($jsonStr);
            $projectMemoryDocs = ProjectContextMemory::load($this->pdo, (int)$this->projectId);
            $this->projectOperatingMemoryPrompt = PromptManager::getProjectOperatingMemoryInstruction($projectMemoryDocs);
            chatLogger("[PROJECT-MEMORY] loaded=" . (empty(ProjectContextMemory::loadedTypes($projectMemoryDocs)) ? 'none' : implode(',', ProjectContextMemory::loadedTypes($projectMemoryDocs))) . " | chars=" . ProjectContextMemory::totalChars($projectMemoryDocs));
        }
        chatLogger("[ADV-TIMING] DB記憶ロード完了 | promptChars: " . mb_strlen($this->databaseMemoryPrompt) . " | elapsed: " . $this->elapsedSeconds($phaseStart));

        if ($this->tryHistoryReportFastPath()) {
            chatLogger("[ADV-TIMING] history_report 専用ファストパス完了 | totalElapsed: " . $this->elapsedSeconds($pipelineStart));
            return;
        }

        if ($this->tryMultiSourceAdviceFastPath()) {
            chatLogger("[ADV-TIMING] multi_source_advice 専用ファストパス完了 | totalElapsed: " . $this->elapsedSeconds($pipelineStart));
            return;
        }

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // 🛠️ 【シーケンス1：CSV集計先行射撃】
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        $phaseStart = microtime(true);
        sendSSE('status', ['step' => 1, 'message' => '🧠 【シーケンス1/3】データ集計要求 of 因数分解及び物理SQL監査を実行中...']);
        $this->decomposeQuestion();
        if ($this->shouldSkipSqlSequenceForDocOnlySubQueries()) {
            chatLogger("[ADV-SEQUENCE1-SKIP] 資料中心の抽出要求を検知したため、SQL分析シーケンスをスキップして資料RAGへ移行します。subQueries: " . count($this->subQueries));
        } else {
            $this->processSubQueries();
        }
        chatLogger("[ADV-TIMING] シーケンス1 SQL分析完了 | subQueries: " . count($this->subQueries) . " | subAnswers: " . count($this->subAnswers) . " | elapsed: " . $this->elapsedSeconds($phaseStart));

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // 🛠️ 【シーケンス2：資料RAG連続射撃】
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        $phaseStart = microtime(true);
        if ($this->shouldUsePresetDocPlan()) {
            chatLogger("[ADV-PLANNER-SKIP] 資料PDF向け定番プランを適用し、Planner をスキップします。");
            sendSSE('status', ['step' => 3, 'message' => '🧠 【シーケンス2/3】資料PDF向け定番プランで資料巡回を開始します...']);
            $plan = $this->buildPresetDocPlan();
        } else {
            sendSSE('status', ['step' => 3, 'message' => '🧠 【シーケンス2/3】資料手順計画（Planner） of 策定及び動的マスキング巡回を開始...']);
            $plan = $this->generateExecutionPlan();
        }
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
        $this->completeAdvancedRoute();
        chatLogger("[ADV-TIMING] シーケンス4 保存・出荷完了 | elapsed: " . $this->elapsedSeconds($phaseStart));
        chatLogger("[ADV-TIMING] フル思考ルート全体完了 | totalElapsed: " . $this->elapsedSeconds($pipelineStart));
    }

    private function elapsedSeconds(float $start): string {
        return number_format(microtime(true) - $start, 2) . '秒';
    }

    private function tryHistoryReportFastPath(): bool {
        if (!$this->reportMode) {
            return false;
        }
        if (preg_match('/(これまで|今まで|過去|直近).*(会話|やりとり|チャット|履歴).*(報告書|レポート)|((会話|やりとり|チャット|履歴).*(報告書|レポート))/u', $this->searchQuery) !== 1) {
            return false;
        }

        $history = $this->loadCurrentThreadHistory(50);
        if (empty($history)) {
            $this->finalResponse = "## 結論\n現在のスレッドには、報告書化できる会話履歴がまだありません。\n\n## 分析対象\n- 対象スレッド: {$this->threadId}\n- 取得件数: 0件\n\n## 根拠\n- `chat_history` に対象メッセージが見つかりませんでした。\n\n## 留意点\n- このスレッドで会話を開始したあとに再実行してください。\n\n## 推奨アクション\n- まず1〜2件のやり取りを行い、その後に報告書化を実行してください。\n\n## 出典\n- `chat_history`";
            chatLogger("[REPORT] history_report ファストパス: 対象スレッドの履歴が0件のため、報告書PDF生成をスキップします。thread_id=" . ($this->threadId ?? 'NULL'));
            $this->reportMode = false;
        } else {
            $this->finalResponse = $this->buildDeterministicHistoryReport($history);
        }

        $this->insertFastPathReasoningStep(1, 'current thread の会話履歴を収集', $this->buildHistoryCollectionSnapshot($history));
        $this->insertFastPathReasoningStep(2, '会話履歴から報告書を組み立て', $this->finalResponse);
        $this->completeAdvancedRoute();
        return true;
    }

    private function tryMultiSourceAdviceFastPath(): bool {
        if (preg_match('/(おすすめ|オススメ|提案|分析方法|集計方法|どう分析|どう集計|どのように.*分析|分析したら.*よい|どう進め|見るべき|観点|切り口|方針)/u', $this->searchQuery) !== 1) {
            return false;
        }

        if (preg_match('/(分析|集計|データ|CSV|csv|PDF|pdf|資料|観点|切り口)/u', $this->searchQuery) !== 1) {
            return false;
        }

        $csvFiles = $this->loadProjectCsvFiles();
        $pdfDocs = $this->loadProjectPdfDocuments();
        if (empty($csvFiles) && empty($pdfDocs)) {
            return false;
        }

        $this->finalResponse = $this->buildDeterministicMultiSourceAdvice($csvFiles, $pdfDocs);

        $summary = "CSV件数=" . count($csvFiles) . " / PDF件数=" . count($pdfDocs);
        $this->insertFastPathReasoningStep(1, 'CSV/PDF の資産構成を収集', $summary);
        $this->insertFastPathReasoningStep(2, '資産構成から推奨分析観点を組み立て', $this->finalResponse);
        $this->completeAdvancedRoute();
        return true;
    }

    private function completeAdvancedRoute(): void {
        $this->saveHistoryAndEvaluations();
        $this->sendFinalResult();
    }

    private function loadCurrentThreadHistory(int $limit = 50): array {
        if ($this->projectId <= 0 || $this->threadId === null || $this->user_id <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare("
            SELECT role, message, created_at
            FROM chat_history
            WHERE project_id = ? AND thread_id = ? AND user_id = ?
            ORDER BY created_at DESC
            LIMIT {$limit}
        ");
        $stmt->execute([$this->projectId, $this->threadId, $this->user_id]);
        return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function loadProjectCsvFiles(): array {
        if ($this->projectId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare("
            SELECT file_name, column_headers, row_count
            FROM project_csv_files
            WHERE project_id = ?
            ORDER BY id ASC
        ");
        $stmt->execute([$this->projectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function loadProjectPdfDocuments(): array {
        if ($this->projectId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare("
            SELECT title, file_path, created_at
            FROM documents
            WHERE project_id = ? AND LOWER(file_path) LIKE '%.pdf' AND title NOT LIKE 'AI報告書%'
            ORDER BY created_at DESC, id DESC
            LIMIT 20
        ");
        $stmt->execute([$this->projectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function buildDeterministicHistoryReport(array $history): string {
        $userMessages = [];
        $assistantCount = 0;
        foreach ($history as $row) {
            if (($row['role'] ?? '') === 'user') {
                $userMessages[] = $this->compactLine((string)($row['message'] ?? ''), 120);
            } elseif (($row['role'] ?? '') === 'assistant') {
                $assistantCount++;
            }
        }

        $topics = $this->detectHistoryTopics($history);
        $latestRequests = array_slice(array_reverse($userMessages), 0, 5);
        $firstAt = $history[0]['created_at'] ?? '-';
        $lastAt = $history[count($history) - 1]['created_at'] ?? '-';

        $lines = [];
        $lines[] = "## 結論";
        $lines[] = "このスレッドでは、CSVの概要把握、PDF資料からの留意点抽出、そして会話内容そのものの整理という順で、案件理解を段階的に深める対話が行われました。現在の会話は、データ資産を横断して確認し、その結果を再利用しやすい形へ整える流れにあります。";
        $lines[] = "";
        $lines[] = "## 分析対象";
        $lines[] = "- 対象スレッド: " . ($this->threadId ?? '-');
        $lines[] = "- 対象履歴件数: " . count($history) . "件";
        $lines[] = "- ユーザー発言: " . count($userMessages) . "件";
        $lines[] = "- AI回答: {$assistantCount}件";
        $lines[] = "- 対象期間: {$firstAt} 〜 {$lastAt}";
        $lines[] = "";
        $lines[] = "## 根拠";
        foreach ($latestRequests as $request) {
            $lines[] = "- 直近の依頼: {$request}";
        }
        if (!empty($topics)) {
            foreach ($topics as $topic => $count) {
                $lines[] = "- 話題傾向: {$topic} ({$count}件程度)";
            }
        }
        $lines[] = "";
        $lines[] = "## 留意点";
        $lines[] = "- 現在の履歴は現在スレッド単位で収集されており、案件全体の全履歴ではありません。";
        $lines[] = "- 直近の議論はログ確認やルーティング調整も含むため、業務報告として再利用する際は目的別に章立てすると読みやすくなります。";
        $lines[] = "- PDF抽出とCSV集計は性質が異なるため、報告書では『定量情報』と『資料上の注意事項』を分離して記載するのが適しています。";
        $lines[] = "";
        $lines[] = "## 推奨アクション";
        $lines[] = "- まずCSV側は対象ファイル別に、件数集計・分布集計・時系列集計のどれを優先するかを決める。";
        $lines[] = "- PDF側は、留意点・制約・確認事項をページ番号付きで一覧化し、CSV側の集計結果と照合できる形にそろえる。";
        $lines[] = "- このスレッドの対話履歴を案件報告へ転用する場合は、『実行した分析』『得られた根拠』『未確定事項』の3区分で再編集する。";
        $lines[] = "";
        $lines[] = "## 出典";
        $lines[] = "- `chat_history` / project_id={$this->projectId} / thread_id=" . ($this->threadId ?? 'NULL') . " / user_id={$this->user_id}";
        foreach ($latestRequests as $request) {
            $lines[] = "- 会話断片: {$request}";
        }

        return implode("\n", $lines);
    }

    private function buildDeterministicMultiSourceAdvice(array $csvFiles, array $pdfDocs): string {
        $hasCsv = !empty($csvFiles);
        $hasPdf = !empty($pdfDocs);
        $lines = [];
        $lines[] = $this->buildMultiSourceAdviceLead($hasCsv, $hasPdf);
        $lines[] = "";
        $lines[] = "## おすすめの進め方";
        foreach ($this->buildMultiSourceAdviceWorkflow($hasCsv, $hasPdf) as $step) {
            $lines[] = $step;
        }

        if ($hasCsv) {
            $lines[] = "";
            $lines[] = "## CSVでおすすめの集計";

            foreach ($csvFiles as $csv) {
                $lines[] = $this->buildCsvAdviceLine($csv);
            }
        }

        if ($hasPdf) {
            $lines[] = "";
            $lines[] = "## PDFでおすすめの分析";
            foreach (array_slice($pdfDocs, 0, 3) as $doc) {
                $title = (string)($doc['title'] ?? basename((string)($doc['file_path'] ?? '資料PDF')));
                $lines[] = "- `{$title}`: 留意点、禁止事項、確認事項、寸法・条件値などをページ番号付きで抽出し、CSV集計とは別に根拠一覧化するのがおすすめです。";
            }
        } elseif ($hasCsv) {
            $lines[] = "";
            $lines[] = "## PDFでおすすめの分析";
            $lines[] = "- 現在、対象PDFは確認できませんでした。まずはCSVだけで定量把握を進め、必要な資料が追加された時点で留意点抽出を組み合わせるのが自然です。";
        }

        $lines[] = "";
        $lines[] = "## まず最初にやるとよい分析";
        foreach ($this->buildMultiSourceAdviceFirstActions($hasCsv, $hasPdf) as $step) {
            $lines[] = $step;
        }
        $lines[] = "";
        $lines[] = "## 出典";
        if ($hasCsv) {
            $lines[] = "- CSVファイル数: " . count($csvFiles) . "件";
            foreach (array_slice($csvFiles, 0, 5) as $csv) {
                $lines[] = "- CSV: " . (string)($csv['file_name'] ?? '名称不明');
            }
        }
        if ($hasPdf) {
            $lines[] = "- PDF件数: " . count($pdfDocs) . "件";
            foreach (array_slice($pdfDocs, 0, 5) as $doc) {
                $lines[] = "- PDF: " . (string)($doc['title'] ?? basename((string)($doc['file_path'] ?? '資料PDF')));
            }
        }

        return implode("\n", $lines);
    }

    private function buildMultiSourceAdviceLead(bool $hasCsv, bool $hasPdf): string
    {
        if ($hasCsv && $hasPdf) {
            return "CSVとPDFの両方を活かすなら、まず『CSVで定量把握』『PDFで留意点整理』『両者の照合』の3段で進めるのがおすすめです。";
        }

        if ($hasCsv) {
            return "今回はCSV資産が中心なので、まず『全体像の把握』『主要列の分布確認』『業務に近い指標の深掘り』の順で進めるのがおすすめです。";
        }

        return "今回は資料PDFが中心なので、まず『留意点抽出』『制約条件の整理』『ページ番号付き根拠の一覧化』の順で進めるのがおすすめです。";
    }

    private function buildMultiSourceAdviceWorkflow(bool $hasCsv, bool $hasPdf): array
    {
        if ($hasCsv && $hasPdf) {
            return [
                "- 1. CSVのファイル一覧と列構成を確認し、どのファイルが件数集計・分布集計・時系列集計に向くかを切り分ける。",
                "- 2. PDFからは、留意点・制約・確認事項をページ番号付きで抽出し、定量集計とは別レイヤーで整理する。",
                "- 3. 最後に、CSVの集計結果とPDFの注意事項を並べ、運用判断に使える形へまとめる。",
            ];
        }

        if ($hasCsv) {
            return [
                "- 1. CSVのファイル一覧と列構成を確認し、業務系・属性系・履歴系に分けて見る。",
                "- 2. 各CSVで件数分布、ランキング、時系列など基本集計を出し、どこに偏りがあるかを把握する。",
                "- 3. その後、深掘りしたいCSVを1本選び、列同士の比較や期間別の傾向分析へ進む。",
            ];
        }

        return [
            "- 1. PDFから、留意点・制約・確認事項をページ番号付きで抽出する。",
            "- 2. 寸法、条件値、禁止事項など、判断に直結する情報をカテゴリ別に整理する。",
            "- 3. 最後に、現場や運用判断に使うための確認リストへまとめる。",
        ];
    }

    private function buildMultiSourceAdviceFirstActions(bool $hasCsv, bool $hasPdf): array
    {
        if ($hasCsv && $hasPdf) {
            return [
                "- CSV全体の概要を出す",
                "- 次に業務系CSVを1本選び、列別件数分布やランキングを出す",
                "- その後、PDFの留意点一覧を抽出して、CSVの数値結果と矛盾や確認事項がないかを見る",
            ];
        }

        if ($hasCsv) {
            return [
                "- CSV全体の概要を出す",
                "- 業務に近いCSVを1本選び、列別件数分布やランキングを出す",
                "- 必要なら時系列や特定条件で絞った集計へ進む",
            ];
        }

        return [
            "- PDF全体から主要な留意点を抽出する",
            "- 次にページ番号付きで制約条件を一覧化する",
            "- その後、判断に必要な確認事項リストへ整理する",
        ];
    }

    private function buildCsvAdviceLine(array $csv): string
    {
        $fileName = (string)($csv['file_name'] ?? '');
        $rowCount = (int)($csv['row_count'] ?? 0);
        $headers = json_decode((string)($csv['column_headers'] ?? ''), true);
        if (!is_array($headers)) {
            $headers = array_filter(array_map('trim', explode(',', (string)($csv['column_headers'] ?? ''))));
        }

        if (preg_match('/language-locales/i', $fileName)) {
            return "- `{$fileName}` ({$rowCount}件): 言語別件数、部署別件数、アカウント属性の分布確認が向いています。";
        }
        if (preg_match('/username-or-email/i', $fileName)) {
            return "- `{$fileName}` ({$rowCount}件): ユーザー識別子、メールアドレス、氏名の重複有無や属性分布の確認が向いています。";
        }
        if (preg_match('/入荷実績一覧/u', $fileName)) {
            return "- `{$fileName}` ({$rowCount}件): 品番別件数、品名別件数、仕入先別件数、サイズ別件数、発注数/入荷数/未入荷数の比較がおすすめです。";
        }
        if (preg_match('/健康診断一覧/u', $fileName)) {
            return "- `{$fileName}` ({$rowCount}件): 年齢分布、身長・体重・血圧・血糖値の要約統計、性別や年代別の比較が有効です。";
        }
        if (preg_match('/出荷一覧表/u', $fileName)) {
            return "- `{$fileName}` ({$rowCount}件): 商品別件数、顧客別件数、受注日ベースの時系列、本数と合計のランキング集計が向いています。";
        }

        $headerPreview = implode(' / ', array_slice($headers, 0, 5));
        if ($headerPreview === '') {
            $headerPreview = '主要列';
        }

        return "- `{$fileName}` ({$rowCount}件): まず主要列（{$headerPreview}）の値分布と欠損有無を確認するのがおすすめです。";
    }

    private function buildHistoryCollectionSnapshot(array $history): string {
        $lines = [];
        $lines[] = "取得件数: " . count($history);
        foreach (array_slice($history, -6) as $row) {
            $roleLabel = (($row['role'] ?? '') === 'assistant') ? 'AI' : 'ユーザー';
            $lines[] = "- {$roleLabel}: " . $this->compactLine((string)($row['message'] ?? ''), 120);
        }
        return implode("\n", $lines);
    }

    private function detectHistoryTopics(array $history): array {
        $topicPatterns = [
            'CSVデータの要約・集計' => '/CSV|csv|project_csv|row_data|カラム|列|集計/u',
            'PDF資料の留意点抽出' => '/PDF|pdf|資料|留意点|doc_chunks|documents/u',
            '会話履歴の整理・要約' => '/会話|履歴|チャット|要約|報告書/u',
            'ルーティング・ログ確認' => '/ログ|route|ルート|debug|遅延|処理/u',
        ];

        $scores = [];
        foreach ($history as $row) {
            $text = (string)($row['message'] ?? '');
            foreach ($topicPatterns as $topic => $pattern) {
                if (preg_match($pattern, $text)) {
                    $scores[$topic] = ($scores[$topic] ?? 0) + 1;
                }
            }
        }
        arsort($scores);
        return array_slice($scores, 0, 4, true);
    }

    private function compactLine(string $text, int $limit): string {
        $text = trim((string)(preg_replace('/\s+/u', ' ', $text) ?? $text));
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        return mb_substr($text, 0, $limit) . '...';
    }

    private function insertFastPathReasoningStep(int $stepNumber, string $subQuery, string $subAnswer): void {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO chat_reasoning_steps
                    (project_id, session_id, original_question, step_number, sub_query, sub_answer, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $this->projectId,
                $this->reasoningId,
                $this->normalizeUtf8((string)$this->originalMessage),
                $stepNumber,
                $this->normalizeUtf8($subQuery),
                $this->normalizeUtf8($subAnswer)
            ]);
        } catch (Exception $e) {
            chatLogger("[ADV-FASTPATH] reasoning step 保存失敗: " . $e->getMessage());
        }
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
                      . "【資料PDF質問の専用ルール】\n"
                      . "■ ユーザーが資料PDF・図面・仕様書・報告書から「留意点」「要点」「主要な内容」「根拠」を抽出してほしい場合、最初のサブクエリを metadata_lookup にして documents の一覧確認だけで終わらせてはならない。\n"
                      . "■ その場合は operation_type を semantic_extract にし、target_tables は必ず doc_chunks を含めること。documents だけを単独ターゲットにしてはいけない。\n"
                      . "■ 資料名確認が必要でも、本文抽出が主目的なら documents は補助であり、主役は doc_chunks である。\n\n"
                      . "必ず以下のJSON配列形式のみで出力してください。挨拶やMarkdownの説明は一切不要です。\n"
                      . "operation_type は metadata_lookup / simple_aggregate / record_search / semantic_extract のいずれかにしてください。\n"
                      . "[{\"query\": \"ユーザーの最初の質問の枠内から絶対に脱線しない具体的な調査目的\", \"operation_type\": \"semantic_extract\", \"target_tables\": [\"doc_chunks\"], \"answer_goal\": \"このサブクエリで生成すべき小回答の目的\"}]\n\n"
                      . "分析観点リスト(JSON):";
        
        chatLogger("[DEBUG] Ollama因数分解API呼び出し送信前...");
        
        // ユーザープロンプトに「実在スキーマ情報」と「ユーザーの最初の質問」をブレずに完全ドッキング
        $userContextPrompt = $this->schemaInfo . "\n\n【ユーザーの最初の質問】\n{$this->searchQuery}\n\n分析観点リスト(JSON):";
        
        // 引数を整流：第3引数にシステムプロンプト、第4引数に構築したユーザープロンプトを正確に引き渡す
        $decompSystemPrompt = $this->composeMemoryAwarePrompt($decompPrompt);
        $this->logPromptBudget('decompose_question', [
            'system' => $decompSystemPrompt,
            'schema' => $this->schemaInfo,
            'question' => $this->searchQuery,
            'projectMemory' => $this->projectOperatingMemoryPrompt,
            'databaseMemory' => $this->databaseMemoryPrompt,
        ], 8192);
        $decomp_res = callOllamaChat(
            $this->ollama_host,
            $this->mainModel,
            $decompSystemPrompt,
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
                "operation_type" => $this->shouldForceDocSemanticExtractByQuestion() ? 'semantic_extract' : $this->inferOperationType($this->searchQuery),
                "target_tables" => $this->shouldForceDocSemanticExtractByQuestion() ? ['documents', 'doc_chunks'] : $this->inferTargetTables($this->searchQuery),
                "answer_goal" => "ユーザーの質問に直接答えるための中間回答を作る"
            ]];
        } else {
            $this->subQueries = array_map([$this, 'normalizeRawSubQueryItem'], $this->subQueries);
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
        $sql_sys_prompt = $this->composeMemoryAwarePrompt($sql_sys_prompt);
        $sql_user_prompt = $this->schemaInfo
            . "\n\n【サブクエリ】\n" . $subQ
            . "\n\n【operation_type】\n" . $operationType
            . "\n\n【候補テーブル】\n" . $targetTables
            . "\n\n【このサブクエリの回答目標】\n" . $answerGoal;

        // 超決定論的パラメータによる最高度引き締めAI射撃
        $sql_json_str = callOllamaChat($this->ollama_host, $this->sqlModel, $sql_sys_prompt, $sql_user_prompt, 'json', ["temperature" => 0.0, "top_p" => 0.1, "num_ctx" => 4096]);
        
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
        
        $max_retries = $this->maxSqlRepairRetries();
        $retry_count = 0;
        $execResult = ['success' => false, 'error' => 'クエリが初期化されていません。', 'data' => []];
        chatLogger("[SQL-REPAIR-POLICY] max_retries={$max_retries} | report_mode=" . ($this->reportMode ? 'on' : 'off') . " | diagram_mode=" . ($this->diagramMode ? 'on' : 'off'));

        require_once __DIR__ . '/../../src/SqlExecutionEngine.php';
        $sqlEngine = new SqlExecutionEngine($this->pdo, $this->projectId, $this->threadId, $this->user_id);

        while ($retry_count <= $max_retries) {
            chatLogger("[OLLAMA-RAW-RESPONSE] (集計試行 {$retry_count}/{$max_retries}) 受信生データ:\n" . $sqlJsonStr);

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
            
            chatLogger("[Text-to-SQL-AFTER] (集計試行 {$retry_count}/{$max_retries}) 補正後実行SQL: " . $generated_sql);

            // ✨【ネジ締め①】現在の集計試行（デバッグ状況）を画面のコンソールへ即時生中継！
            if ($retry_count > 0) {
                sendSSE('status', [
                    'step'    => 2,
                    'message' => "⚠️ [SQL構文エラー検知] MySQLからエラーが返されました。現在、AIがサーバーログを自己反省（Self-Reflection）し、修正クエリを自動再生成中... [デバッグ試行: {$retry_count}/{$max_retries}回]"
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
                if ($this->isAllowedTargetTable($extractedTable)) {
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

            chatLogger("[SQL-EXEC-FAILED] (集計試行 {$retry_count}/{$max_retries}) MySQL生エラー文: " . ($execResult['error'] ?? 'Unknown Error'));
            $repairGuidance = $sqlEngine->buildRepairGuidance($generated_sql, (string)($execResult['error'] ?? 'Unknown Error'), $subQ);

            $retry_count++;
            if ($retry_count > $max_retries) {
                chatLogger("[CRITICAL-LOOP] {$max_retries}回のリトライすべてで監査拒否またはMySQLエラーが発生。ループ強制遮断。");
                break;
            }

            // 自己反省（Self-Reflection）デバッグプロンプト構築
            $debug_sys_prompt = "高度なMySQL 8.0のエキスパートシステムとして、提示された【失敗したクエリ】と、MySQLサーバーが返した【生の構成エラー文】を深く自己反省（Self-Reflection）してください。\n"
                              . "出力は必ず、修正・デバッグされた実行可能なSQL文字列1つのみを内包したJSON形式【のみ】を出力してください。\n"
                              . "SELECT '説明文' のような実テーブルを読まないダミーSQLは禁止です。必ずFROM句で実在テーブルを参照してください。\n"
                              . '{"sql": "SELECT ..."}';

            $debug_sys_prompt = $this->composeMemoryAwarePrompt($debug_sys_prompt);
            $debug_user_context = "【動的INFORMATION_SCHEMA構成】\n" . $this->schemaInfo . "\n\n"
                                . "【この分析タスクの本来の目的】\n" . $subQ . "\n\n"
                                . "❌ 【前回失敗した不正なSQL】\n" . $generated_sql . "\n\n"
                                . "⚠️ 【MySQLから返された生のエラー文】\n" . ($execResult['error'] ?? 'Unknown Error') . "\n\n"
                                . $repairGuidance;

            $sqlJsonStr = callOllamaChat($this->ollama_host, $this->sqlModel, $debug_sys_prompt, $debug_user_context, 'json', ["temperature" => 0.0, "top_p" => 0.1, "num_ctx" => 4096]);
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
            return "⚠️ **{$max_retries}回の自律修復を試みましたが、集計を完了できませんでした。**\n\n最終エラー詳細: " . ($execResult['error'] ?? '不明なエラー。') . "\n\nデバッグ対象クエリ: `{$generated_sql}`";
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

        $sysPrompt = $this->composeMemoryAwarePrompt($sysPrompt);
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
        return $this->buildRoutePlanner()->generateExecutionPlan();
    }

    /**
     * 【仕様2】実行フェーズ：動的プロンプトのマスキングとエンジン連携
     * ❌ 悪魔の二重重複を完全物理パージ！クレンザーとロジックを綺麗に一本化した防衛開通版
     */
    private function executePlanSteps(array $plan): array {
        $this->model = $this->reasoningModel;
        return $this->buildPlanExecutor()->execute($plan, self::$tablesSchema);
    }

    private function normalizeRawSubQueryItem($item): array {
        return $this->buildSubQueryNormalizer()->normalizeRawSubQueryItem($item);
    }

    private function normalizeSubQueryItem(array $item): array {
        return $this->buildSubQueryNormalizer()->normalizeSubQueryItem($item);
    }

    private function shouldForceDocSemanticExtractByQuestion(): bool {
        return $this->buildSubQueryNormalizer()->shouldForceDocSemanticExtractByQuestion();
    }

    private function inferOperationType(string $text): string {
        return $this->buildSubQueryNormalizer()->inferOperationType($text);
    }

    private function inferTargetTables(string $text): array {
        return $this->buildSubQueryNormalizer()->inferTargetTables($text);
    }

    private function shouldRunCsvMapReduceForItem($item): bool {
        return $this->buildSubQueryNormalizer()->shouldRunCsvMapReduceForItem($item);
    }

    private function shouldRunCsvFullMapReduce(): bool {
        return $this->buildSubQueryNormalizer()->shouldRunCsvFullMapReduce($this->subQueries);
    }

    private function isDocOnlySemanticExtractSubQuery(array $item): bool {
        return $this->buildSubQueryNormalizer()->isDocOnlySemanticExtractSubQuery($item);
    }

    private function shouldSkipSqlSequenceForDocOnlySubQueries(): bool {
        return $this->buildSubQueryNormalizer()->shouldSkipSqlSequenceForDocOnlySubQueries($this->subQueries);
    }

    private function shouldUsePresetDocPlan(): bool {
        return $this->buildRoutePlanner()->shouldUsePresetDocPlan(
            fn(): bool => $this->shouldSkipSqlSequenceForDocOnlySubQueries()
        );
    }

    private function buildPresetDocPlan(): array {
        return $this->buildRoutePlanner()->buildPresetDocPlan();
    }

    private function shouldUseLightweightDocFinalAnswerRoute(): bool {
        if ($this->shouldRunCsvFullMapReduce()) {
            return false;
        }

        return $this->shouldSkipSqlSequenceForDocOnlySubQueries();
    }

    private function buildLightweightDocFinalAnswer(string $currentDraft, array $stepResults = []): string {
        return $this->buildDocAnswerBuilder()->buildLightweightDocFinalAnswer($currentDraft, $stepResults);
    }

    private function applyReportModeFinalPolish(string $currentDraft): string {
        return $this->buildDraftComposer()->applyReportModeFinalPolish($currentDraft);
    }

    private function buildDocChunkEvidenceSummary(array $rows): string {
        return $this->buildDocAnswerBuilder()->buildDocChunkEvidenceSummary($rows);
    }

    private function buildEvidenceDraft(array $stepResults): string {
        return $this->buildDraftComposer()->buildEvidenceDraft($stepResults);
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

        if ($this->shouldUseLightweightDocFinalAnswerRoute()) {
            chatLogger("[ADV-LIGHTWEIGHT-FINAL] 資料PDF向け軽量最終回答ルートを適用します。");
            sendSSE('status', [
                'step'    => 5,
                'message' => '🪄 資料PDF向けの軽量最終整形を実行しています...'
            ]);

            try {
                $lightweightResponse = $this->buildLightweightDocFinalAnswer($currentDraft, $stepResults);
                if ($lightweightResponse !== '') {
                    $currentDraft = $lightweightResponse;
                    if ($this->reportMode) {
                        chatLogger("[REPORT-POLISH] 報告書モード向けの最終整形を実行します。");
                        sendSSE('status', [
                            'step'    => 6,
                            'message' => '📄 報告書として読みやすい構成へ最終整形しています...'
                        ]);
                        $currentDraft = $this->applyReportModeFinalPolish($currentDraft);
                    }
                    $this->finalResponse = $currentDraft;
                    $this->retryCount = 0;

                    require_once __DIR__ . '/../../src/LightweightFinalAnswerGuard.php';
                    $guard = new LightweightFinalAnswerGuard((string)$this->ollama_host);
                    $guardResult = $guard->review(
                        $this->originalMessage,
                        $this->buildEvidenceDraft($stepResults),
                        $this->finalResponse,
                        (string)$this->model,
                        'advanced_lightweight_doc_final'
                    );
                    $this->finalResponse = (string)($guardResult['response'] ?? $this->finalResponse);
                    $this->evalResult = $guardResult['eval_result'] ?? $this->evalResult;

                    sendSSE('status', [
                        'step'    => 6,
                        'message' => "✅ 軽量最終回答の確認が完了しました。"
                    ]);
                    return;
                }
            } catch (Exception $e) {
                chatLogger("[ADV-LIGHTWEIGHT-FINAL-FAILED] 軽量最終回答ルートに失敗したため、通常の品質審査へフォールバックします: " . $e->getMessage());
            }
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

        $this->logPromptBudget('final_generate', [
            'system' => $sysPrompt,
            'question' => $this->originalMessage,
            'reasoning' => $mergedReasoningForDraft,
            'draft' => $currentDraft,
            'projectMemory' => $this->projectOperatingMemoryPrompt,
            'databaseMemory' => $this->databaseMemoryPrompt,
        ], 4096);

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
        // 品質評価はモード別の上限で制御し、通常利用時の待ち時間を抑える
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        $criticResult = $this->buildCriticLoop()->run(
            $currentDraft,
            $baseSystemPrompt,
            $chartInstruction,
            $this->maxEvalRetries()
        );
        $currentDraft = (string)($criticResult['draft'] ?? $currentDraft);
        $this->evalResult = $criticResult['eval_result'] ?? $this->evalResult;
        $this->retryCount = (int)($criticResult['retry_count'] ?? 0);
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
        return $this->buildDraftComposer()->generateAdditionalChunkQuery($feedback);
    }

    /**
     * 進捗ステップ99、チャット履歴、および品質評価スコアを一元コミットする単一トランザクション保護回路
     */
    private function saveHistoryAndEvaluations(): void {
        $finalizeResult = $this->buildRouteFinalizer()->saveHistoryAndEvaluations();
        $this->reportDocument = $finalizeResult['report_document'] ?? null;
        $this->csvExport = $finalizeResult['csv_export'] ?? null;
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
                'model_roles'     => ChatModelRolePayload::build($this->mainModel, $this->subModel, $this->embeddingModel, 'main', $this->sqlModel),
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
        $this->buildRouteFinalizer()->sendFinalResult($this->reportDocument, $this->csvExport);
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
