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
require_once __DIR__ . '/../../src/AdvancedRouteCompletionCoordinator.php';
require_once __DIR__ . '/../../src/AdvancedSqlSubQueryRunner.php';
require_once __DIR__ . '/../../src/AdvancedFastPathResolver.php';
require_once __DIR__ . '/../../src/AdvancedReasoningStepRecorder.php';
require_once __DIR__ . '/../../src/AdvancedBulkCsvMapReducer.php';
require_once __DIR__ . '/../../src/AdvancedFinalDraftGenerator.php';
require_once __DIR__ . '/../../src/RouteRuntimeCallbackFactory.php';

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
    private $schemaInfoByTable = [];
    private $schemaSummaryMatrix = "";
    private $dynamicTableWhitelist = [];
    private $availableCsvFileIds = [];
    private $subQueries = [];
    private $subAnswers = [];
    private $uniqueSources = [];
    private $finalResponse = "";
    private $evalResult = null;
    private $retryCount = 0;

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

    private function createChatLoggerCallback(): callable
    {
        return RouteRuntimeCallbackFactory::logger('chatLogger');
    }

    private function createPromptBudgetLogger(string $routeLabel): callable
    {
        return RouteRuntimeCallbackFactory::promptBudgetLogger($routeLabel, $this->createChatLoggerCallback());
    }

    private function createSseSenderCallback(): callable
    {
        return RouteRuntimeCallbackFactory::sse('sendSSE');
    }

    private function createStatusStepEmitter(int $step): callable
    {
        return RouteRuntimeCallbackFactory::statusEmitter('sendSSE', $step);
    }

    private function buildSqlSubQueryRunner(): AdvancedSqlSubQueryRunner
    {
        return new AdvancedSqlSubQueryRunner([
            'pdo' => $this->pdo,
            'projectId' => $this->projectId,
            'threadId' => $this->threadId,
            'userId' => $this->user_id,
            'reasoningId' => $this->reasoningId,
            'ollamaHost' => $this->ollama_host,
            'sqlModel' => $this->sqlModel,
            'analysisModel' => $this->reasoningModel,
            'schemaInfo' => $this->schemaInfo,
            'schemaInfoByTable' => $this->schemaInfoByTable,
            'schemaSummaryMatrix' => $this->schemaSummaryMatrix,
            'dynamicTableWhitelist' => $this->dynamicTableWhitelist,
            'projectOperatingMemoryPrompt' => $this->projectOperatingMemoryPrompt,
            'databaseMemoryPrompt' => $this->databaseMemoryPrompt,
            'originalMessage' => $this->normalizeUtf8($this->originalMessage),
            'maxRetries' => $this->maxSqlRepairRetries(),
            'composeMemoryAwarePrompt' => fn(string $prompt): string => $this->composeMemoryAwarePrompt($prompt),
            'logPromptBudget' => $this->createPromptBudgetLogger('advanced_hybrid'),
            'logger' => $this->createChatLoggerCallback(),
            'statusEmitter' => $this->createStatusStepEmitter(2),
        ]);
    }

    private function buildFastPathResolver(): AdvancedFastPathResolver
    {
        return new AdvancedFastPathResolver(
            $this->pdo,
            $this->projectId,
            $this->threadId,
            $this->user_id,
            (string)$this->searchQuery,
            (string)$this->originalMessage,
            $this->createChatLoggerCallback()
        );
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
            $this->createChatLoggerCallback()
        );
    }

    private function buildSubQueryNormalizer(): AdvancedSubQueryNormalizer
    {
        return new AdvancedSubQueryNormalizer(
            $this->searchQuery,
            $this->originalMessage,
            array_values(array_unique(array_merge($this->dynamicTableWhitelist, array_keys(self::$tablesSchema)))),
            $this->createChatLoggerCallback()
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
                $this->buildReasoningStepRecorder()->recordPlannerThought($thought);
            },
            $this->createChatLoggerCallback()
        );
    }

    private function buildReasoningStepRecorder(): AdvancedReasoningStepRecorder
    {
        return new AdvancedReasoningStepRecorder(
            $this->pdo,
            $this->projectId,
            $this->reasoningId,
            $this->originalMessage,
            fn(string $text): string => $this->normalizeUtf8($text),
            $this->createChatLoggerCallback()
        );
    }

    private function buildBulkCsvMapReducer(): AdvancedBulkCsvMapReducer
    {
        return new AdvancedBulkCsvMapReducer(
            $this->pdo,
            $this->projectId,
            $this->originalMessage,
            $this->ollama_host,
            $this->model,
            $this->buildReasoningStepRecorder(),
            $this->createChatLoggerCallback(),
            function (string $message, int $step): void {
                call_user_func($this->createSseSenderCallback(), 'status', [
                    'step' => $step,
                    'message' => $message,
                ]);
            }
        );
    }

    private function buildFinalDraftGenerator(): AdvancedFinalDraftGenerator
    {
        return new AdvancedFinalDraftGenerator([
            'originalMessage' => $this->originalMessage,
            'ollamaHost' => $this->ollama_host,
            'model' => $this->model,
            'synthesisModel' => $this->synthesisModel,
            'subAnswers' => $this->subAnswers,
            'buildLightweightDocFinalAnswer' => fn(string $draft, array $stepResults): string => $this->buildLightweightDocFinalAnswer($draft, $stepResults),
            'applyReportModeFinalPolish' => fn(string $draft): string => $this->applyReportModeFinalPolish($draft),
            'buildEvidenceDraft' => fn(array $stepResults): string => $this->buildEvidenceDraft($stepResults),
            'logPromptBudget' => function (string $phase, array $segments, int $numCtx): void {
                $segments['projectMemory'] = $this->projectOperatingMemoryPrompt;
                $segments['databaseMemory'] = $this->databaseMemoryPrompt;
                call_user_func($this->createPromptBudgetLogger('advanced_hybrid'), $phase, $segments, $numCtx);
            },
            'logger' => $this->createChatLoggerCallback(),
            'statusEmitter' => function (string $message, int $step): void {
                call_user_func($this->createSseSenderCallback(), 'status', [
                    'step' => $step,
                    'message' => $message,
                ]);
            },
        ]);
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

    private function buildRouteFinalizerFor(string $finalResponse, ?array $evalResult): AdvancedRouteFinalizer
    {
        return new AdvancedRouteFinalizer(
            $this->pdo,
            $this->projectId,
            $this->threadId,
            $this->user_id,
            $this->reasoningId,
            $this->originalMessage,
            $finalResponse,
            $evalResult,
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

    private function buildCompletionCoordinator(): AdvancedRouteCompletionCoordinator
    {
        return new AdvancedRouteCompletionCoordinator(
            (string)$this->ollama_host,
            (string)$this->originalMessage,
            (string)$this->synthesisModel,
            $this->createChatLoggerCallback()
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
        $this->logAdvancedDebug('route bootstrap', [
            'host' => $this->ollama_host,
            'mainModel' => $this->mainModel,
            'subModel' => $this->subModel,
            'sqlModel' => $this->sqlModel,
            'userId' => $this->user_id,
            'role' => $this->role,
        ]);

        // 0. BOLA脆弱性防止・アサイン権限チェック（最下部 of 実体を安全にキック）
        $phaseStart = microtime(true);
        if (!$this->checkAuthority()) {
            $this->logAdvancedTiming('権限チェックで処理終了', $phaseStart);
            return;
        }
        $this->logAdvancedTiming('権限チェック完了', $phaseStart);

        // 1. データベース of 実在テーブル構造（SHOW TABLES等）を動的にロードしてインジェクションコンテキスト化
        $phaseStart = microtime(true);
        if (!$this->loadCsvSchemas()) {
            $this->logAdvancedTiming('スキーマロード失敗', $phaseStart);
            return;
        }
        $this->logAdvancedTiming('スキーマロード完了', $phaseStart);

        $this->logAdvancedDebug('reasoning session prepared', [
            'reasoningId' => $this->reasoningId,
        ]);

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
        $this->logAdvancedTiming('DB記憶ロード完了', $phaseStart, [
            'promptChars' => mb_strlen($this->databaseMemoryPrompt),
        ]);

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
        $this->logAdvancedTiming('シーケンス1 SQL分析完了', $phaseStart, [
            'subQueries' => count($this->subQueries),
            'subAnswers' => count($this->subAnswers),
        ]);

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
        $this->logAdvancedTiming('シーケンス2 資料RAG巡回完了', $phaseStart, [
            'planSteps' => count($plan),
            'stepResults' => count($stepResults),
        ]);

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // 🛠️ 【シーケンス3：最終品質審査・反省リライト】
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        $phaseStart = microtime(true);
        sendSSE('status', ['step' => 5, 'message' => '📈 【シーケンス3/3】ハイブリッド知能 of 重ね書きレポートを成長マージ中...']);
        $this->mergeAndRefineReport($stepResults);
        $this->logAdvancedTiming('シーケンス3 統合・品質審査完了', $phaseStart, [
            'responseChars' => mb_strlen($this->finalResponse),
            'retryCount' => $this->retryCount,
        ]);

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // 🛠️ 【シーケンス4：一元トランザクション永続化 ＆ 出荷】
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        $phaseStart = microtime(true);
        $this->completeAdvancedRoute();
        $this->logAdvancedTiming('シーケンス4 保存・出荷完了', $phaseStart);
        chatLogger("[ADV-TIMING] フル思考ルート全体完了 | totalElapsed: " . $this->elapsedSeconds($pipelineStart));
    }

    private function elapsedSeconds(float $start): string {
        return number_format(microtime(true) - $start, 2) . '秒';
    }

    private function logAdvancedTiming(string $label, float $start, array $context = []): void
    {
        $segments = [];
        foreach ($context as $key => $value) {
            $segments[] = "{$key}: {$value}";
        }
        $segments[] = "elapsed: " . $this->elapsedSeconds($start);
        chatLogger("[ADV-TIMING] {$label} | " . implode(' | ', $segments));
    }

    private function logAdvancedDebug(string $label, array $context = []): void
    {
        $segments = [];
        foreach ($context as $key => $value) {
            $segments[] = "{$key}: {$value}";
        }
        $suffix = empty($segments) ? '' : ' | ' . implode(' | ', $segments);
        chatLogger("[ADV-DEBUG] {$label}{$suffix}");
    }

    private function tryHistoryReportFastPath(): bool {
        if (!$this->reportMode) {
            return false;
        }
        $result = $this->buildFastPathResolver()->resolveHistoryReport();
        if ($result === null) {
            return false;
        }

        $this->finalResponse = (string)($result['final_response'] ?? '');
        if (!empty($result['force_report_mode_off'])) {
            $this->reportMode = false;
        }

        foreach ((array)($result['reasoning_steps'] ?? []) as $index => $step) {
            $this->buildReasoningStepRecorder()->recordFastPathStep(
                $index + 1,
                (string)($step['sub_query'] ?? ''),
                (string)($step['sub_answer'] ?? '')
            );
        }
        $guardSpec = null;
        if (!empty($result['guard_route']) && isset($result['guard_context'])) {
            $guardSpec = [
                'route' => (string)$result['guard_route'],
                'context' => (string)$result['guard_context'],
            ];
        }
        $this->completeAdvancedRoute($guardSpec);
        return true;
    }

    private function tryMultiSourceAdviceFastPath(): bool {
        $result = $this->buildFastPathResolver()->resolveMultiSourceAdvice();
        if ($result === null) {
            return false;
        }

        $this->finalResponse = (string)($result['final_response'] ?? '');
        foreach ((array)($result['reasoning_steps'] ?? []) as $index => $step) {
            $this->buildReasoningStepRecorder()->recordFastPathStep(
                $index + 1,
                (string)($step['sub_query'] ?? ''),
                (string)($step['sub_answer'] ?? '')
            );
        }
        $this->completeAdvancedRoute();
        return true;
    }

    private function completeAdvancedRoute(?array $guardSpec = null): void {
        $result = $this->buildCompletionCoordinator()->complete(
            (string)$this->finalResponse,
            $this->evalResult,
            $guardSpec,
            fn(string $finalResponse, ?array $evalResult): AdvancedRouteFinalizer => $this->buildRouteFinalizerFor($finalResponse, $evalResult)
        );

        $this->finalResponse = (string)($result['final_response'] ?? $this->finalResponse);
        $this->evalResult = $result['eval_result'] ?? $this->evalResult;
        $this->reportDocument = $result['report_document'] ?? $this->reportDocument;
        $this->csvExport = $result['csv_export'] ?? $this->csvExport;
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
        return $this->buildSqlSubQueryRunner()->executeSingleSubQuery(
            $this->normalizeSubQueryItem($subQItem),
            $step_counter,
            $stepLabel
        );
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
            $mapReduceResult = $this->buildBulkCsvMapReducer()->run();
            $currentDraft = (string)($mapReduceResult['draft'] ?? '');
        }

        $draftGenerationResult = $this->buildFinalDraftGenerator()->generate(
            $currentDraft,
            $stepResults,
            $baseSystemPrompt,
            $chartInstruction,
            $this->shouldUseLightweightDocFinalAnswerRoute()
        );
        $currentDraft = (string)($draftGenerationResult['draft'] ?? $currentDraft);
        $this->finalResponse = $currentDraft;
        $this->evalResult = $draftGenerationResult['eval_result'] ?? $this->evalResult;
        if (!empty($draftGenerationResult['finalized_early'])) {
            $this->retryCount = 0;
            return;
        }

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
     * 【ファクト基盤】実在構造（SHOW TABLES / DESCRIBE）の取得、およびプロジェクト内全データの動的カウント
     */
    private function loadCsvSchemas(): bool {
        chatLogger("[DEBUG] データベースの実在スキーマ構造を動的に解析・スキャンします...");
        
        $this->schemaInfo = "【INFORMATION_SCHEMAコンテキスト (実在データベース構造)】\n";
        $this->schemaInfoByTable = [];
        $this->schemaSummaryMatrix = "";
        
        try {
            $tablesStmt = $this->pdo->query("SHOW TABLES");
            $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);
            $this->dynamicTableWhitelist = $tables; 
            
            foreach ($tables as $tableName) {
                $tableSchema = "■ テーブル名: `{$tableName}`\n";

                $descStmt = $this->pdo->query("DESCRIBE `{$tableName}`");
                $fields = $descStmt->fetchAll(PDO::FETCH_ASSOC);
                $tableSchema .= "  [物理カラム構成]:\n";
                foreach ($fields as $f) {
                    $tableSchema .= "    - カラム名: {$f['Field']} (型: {$f['Type']})\n";
                }

                if ($tableName === 'project_csv_rows') {
                    $tableSchema .= "  [JSON型属性(row_data)の自律スキャンキー一覧]:\n";

                    $stmtCsv = $this->pdo->prepare("SELECT id, file_name, column_headers, row_count FROM project_csv_files WHERE project_id = ?");
                    $stmtCsv->execute([$this->projectId]);
                    $csvFiles = $stmtCsv->fetchAll(PDO::FETCH_ASSOC);

                    $this->availableCsvFileIds = [];

                    foreach ($csvFiles as $csv) {
                        $this->availableCsvFileIds[] = (int)$csv['id'];

                        $headers = json_decode($csv['column_headers'], true);
                        $tableSchema .= "    - ファイルID (csv_file_id): {$csv['id']} (元のファイル名: {$csv['file_name']})\n";
                        $tableSchema .= "      - row_dataの内部に格納されている有効な項目キー名一覧:\n";
                        $tableSchema .= "        " . implode(", ", $headers) . "\n";
                        
                        $stmtSample = $this->pdo->prepare("SELECT row_data FROM project_csv_rows WHERE csv_file_id = ? LIMIT 1");
                        $stmtSample->execute([$csv['id']]);
                        $sample = $stmtSample->fetch(PDO::FETCH_ASSOC);
                        if ($sample) {
                            $tableSchema .= "      - row_data 実データサンプル: " . mb_substr($sample['row_data'], 0, 180) . "...\n";
                        }
                    }
                }
                $this->schemaInfoByTable[$tableName] = rtrim($tableSchema) . "\n";
                $this->schemaInfo .= $this->schemaInfoByTable[$tableName] . "\n";
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

            $this->schemaSummaryMatrix = "【現在のプロジェクト内実在データ総数マトリクス】\n";
            $this->schemaSummaryMatrix .= "  - project_csv_rows (CSVデータ行本文の総レコード数): {$cntCsvRows} 件\n";
            $this->schemaSummaryMatrix .= "  - project_comments (アサインメンバーからのコメント総数): {$cntComments} 件\n";
            $this->schemaSummaryMatrix .= "  - project_faqs (登録済みのFAQナレッジ総数): {$cntFaqs} 件\n";
            $this->schemaInfo .= $this->schemaSummaryMatrix . "\n";

            chatLogger("網羅調査完了 - CSV行数: {$cntCsvRows}件, コメント: {$cntComments}件, FAQ: {$cntFaqs}件");
            chatLogger("[DEBUG] 動的スキーマ情報およびデータ総数マトリクスの構築完了。文字数: " . mb_strlen($this->schemaInfo) . "文字");
            return true;
            
        } catch (Exception $e) {
            chatLogger("[CRITICAL] スキーマ構造および実在データの動的網羅解析中に致命的例外が発生: " . $e->getMessage());
            return false;
        }
    }
}
