<?php

/**
 * chat_analysis.php - Text-to-SQL データ集計・分析エージェント処理ルート
 * (chat.php から安全に呼び出されるコントローラーファイルです)
 *
 * ★[自律修復リトライループ（Self-Reflection Loop）＆ 4段階インジケーター完全同期版]
 * 1. 自前のインジェクション、ホワイトリスト、BOLA多重監査、PDO実行、100件丸めを「SqlExecutionEngine」へ完全委譲。
 * 2. 【解体究極進化】固定のテーブル名に依存せず、実在構造を自律監査し、JSON型（row_data）のキーを動的に特定する検索AIへ強化。
 * 3. 外部エントリーポイント「runAdvancedReasoningRoute」およびプロセッサ構造・引数インターフェースの100%完全維持。
 * 4. 【完全閉塞】生コード内から「```」のハードコードを徹底排除し、動的結合関数に集約。
 * 5. 【デグレ完全防塞】cURLストリームコールバックのポインタ消失と、プロンプト結合タイポを完全修正。
 * 6. 【思考矯正】軽量LLMの謎演算子でっち上げ（->>?$）の禁止、および物理カラム勘違い（T1.user_id等）を徹底封殺する厳格プロンプトプロトコルを統合。
 * 7. 【自動修復・二重フィルター化】シングルクォーテーションの「外側」および「内側」に潜り込む不要なゴミの「$」を100%除去するシールドへ強化。
 * 8. 【インジケーター密結合】UI側の4段階ゲージをぬるぬると心地よく伸長させるため、パケットへ step => 1〜4 を動的パディング。
 * 9. 【ガラス張り化詳細ログ】自律修復ループのAI思考・パース前後SQL・監査エラー・反省入力を完全ダンプするトレース回路を統合。
 * 10.【リテラルID動的注入】マルチテナント隔離を突破するIN句サブクエリを禁止するため、実在のファイルIDリテラルをプロンプトへ強烈にインジェクト。
 * 11.【パラメーター限界引き締め】Text-to-SQLの構文崩れをパージするため、全AI呼出部のオプションを最高度に超決定論化。
 * 12.【案件ID生リテラル強制同期】プレースホルダーへの逃亡を物理遮断するため、システムプロンプトへのprojectId展開＆水際パージシールドを完全格納。
 * 13.【クレンザー安全化＆プロトコル強化】正規表現の範囲を純粋キーに限定し、csv_file_idの物理カラム指定を強烈に教育。
 * 14.【因数分解プロンプト超厳格化】ユーザーの最初の質問から勝手に脱線して評価テーブルへ暴走するのを防ぐ絶対忠実拘束。
 * 15.【思考解放＆邪悪な置換クレンザーパージ】AIからproject_idの記述責任を解放し、クエリ破壊を引き起こしていた project_id 強制置換 preg_replace を完全削除。
 * 16.【cURL窒息バグ修正】stream送信エラーブロックの丸カッコ不整合タイポを完璧に修正・全線開通。
 * * ✨【フェーズ1: State-Saving & Reflective Feedback Loop 大改造（完全版）】
 * 17. 門番（ChatEvaluator）の指摘に基づき、文章の言い訳修正ではなく「データ追加抽出（SQLの再生成）」まで巻き戻る真のActor-Criticループを実装。
 * 18. APIスパイクを防ぐため、バッチスライスをコンテキスト最適値（100件）へチューニング。
 * * 🛠️【日本語JSONキー窒息バグの完全粉砕パッチ】
 * 19. 万能クレンザーの正規表現をマルチバイト包摂型（/iuフラグ及び非ASCIIクラス判定）へ完全アップデート。
 * 20. システムプロンプト内の抽出構文ルールを、MySQL 8.0マルチバイトJSONパス絶対拘束（3143エラー物理回避仕様）へ緊縛上書き。
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
 * 外部からのエントリーポイント（インターフェース引数100%完全同期維持版・Freeze Protocol）
 * ✨【関数名シンクロ統合】：名前を chat.php 側の呼び出し名 「runAdvancedReasoningRoute」 へ完全同期！
 */
function runAdvancedReasoningRoute($pdo, $ollama_host, $projectId, $originalMessage, $model, $promptKey, $projectContext, $historySummaryText, $user_id, $role, bool $reportMode = false, bool $diagramMode = false) {
    $processor = new AdvancedReasoningRouteProcessor(
        $pdo, $ollama_host, $projectId, $originalMessage, $model, $promptKey, $projectContext, $historySummaryText, $user_id, $role, $reportMode, $diagramMode
    );
    $processor->execute();
}

/**
 * 多段階推論型 動的スキーマ監査 Text-to-SQL データ分析エージェントプロセッサ
 */
class AdvancedReasoningRouteProcessor {
    // 依存コンポーネントとコンテキスト
    private $pdo;
    private $ollama_host;
    private $projectId;
    private $originalMessage;
    private $model;
    private $promptKey;
    private $projectContext;
    private $historySummaryText;
    private $user_id;
    private $role;
    private $reportMode = false;
    private $diagramMode = false;
    private $reportDocument = null;
    private $csvSearchService = null;
    private $csvDateColumnDetector = null;
    private $csvAggregationPlanner = null;
    private $csvAggregationQueryBuilder = null;
    private $csvAggregationAnswerFormatter = null;
    private $csvEvidenceReader = null;
    private $csvQuestionRouter = null;
    private $csvSummaryFormatter = null;
    private $csvMetadataCatalog = null;
    private $csvSampleRowRepository = null;

    // 内部ステート管理
    private $reasoningId;
    private $schemaInfo = "";
    private $dynamicTableWhitelist = [];
    private $subQueries = [];
    private $subAnswers = [];
    private $finalResponse = "";
    private $evalResult = null;
    private $retryCount = 0;
    private $databaseMemoryPrompt = ""; // フェーズ3：DB事前記憶プロンプト保持用
    
    // ファイルID配列を動的に保持・蓄積するためのプライベートプロパティ宣言
    private $availableCsvFileIds = [];

    // cURLストリーム一時バッファ・ステート（アクセス違反防止用のpublic調整維持）
    public $buffer = "";
    public $packetCounter = 0;
    public $lastLoggedLen = 0;
    public $ollamaErrorMsg = "";

    /**
     * コンストラクタ (完全DI化)
     */
    public function __construct($pdo, $ollama_host, $projectId, $originalMessage, $model, $promptKey, $projectContext, $historySummaryText, $user_id, $role, bool $reportMode = false, bool $diagramMode = false) {
        $this->pdo                = $pdo;
        $this->ollama_host        = $ollama_host;
        $this->projectId          = $projectId;
        $this->originalMessage    = $originalMessage;
        $this->model              = $model;
        $this->promptKey          = $promptKey;
        $this->projectContext     = $projectContext;
        $this->historySummaryText = $historySummaryText;
        $this->user_id            = $user_id;
        $this->role               = $role;
        $this->reportMode         = $reportMode;
        $this->diagramMode        = $diagramMode;
        $this->reasoningId        = 'sql-' . uniqid('reason_') . '-' . mt_rand(1000, 9999);
    }

    private function getOutputModeInstructions(): string {
        $instructions = '';
        if ($this->diagramMode) {
            $instructions .= "\n【図解モード】説明の理解に役立つ場合のみ、Mermaidコードブロック（```mermaid）またはChart.js用JSONコードブロック（```json:chart）を1つまで添えてください。図表が不要な場合は文章のみで構いません。";
        }
        if ($this->reportMode) {
            $instructions .= "\n【報告書モード】回答は後続処理でPDF報告書化されます。結論、分析対象、根拠、集計結果、留意点、推奨アクション、出典の順に、報告書として読みやすい見出し構成で作成してください。";
        }
        return $instructions;
    }

    private function maxSqlRepairRetries(): int {
        return ($this->reportMode || $this->diagramMode) ? 2 : 1;
    }

    private function maxEvalRetries(): int {
        return ($this->reportMode || $this->diagramMode) ? 2 : 1;
    }

    /**
     * メメイン実行パイプライン
     */
    public function execute(): void {
        $this->model = $this->model; // ステートバインド同期維持

        chatLogger(">>> [データ分析ルート] 動的スキーマ監査型 多段階集計エージェントを起動します");
        chatLogger("[DEBUG] 引数情報 - Host: {$this->ollama_host} | Model: {$this->model} | UserID: {$this->user_id} | Role: {$this->role}");

        // 0. BOLA脆弱性防止・アサイン権限チェック
        if (!$this->checkAuthority()) {
            return;
        }

        // ステップ1：要求分解フェーズ（プロトコル開始・因数分解）のstatus同期インジェクション
        sendSSE('status', [
            'step'    => 1, 
            'message' => '🧠 データ分析の切り口を因数分解し、多段階集計シナリオを構築しています...'
        ]);
        chatLogger("[DEBUG] 生成されたデータ分析セッションID (reasoningId): {$this->reasoningId}");

        // 1. データベースの実在テーブル構造（SHOW TABLES等）を動的にロードしてインジェクションコンテキスト化
        if (!$this->loadCsvSchemas()) {
            return;
        }

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // ✨【フェーズ4】記憶（キャッシュ）の自動ロード＆自動リフレッシュ回路
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        if ($this->projectId > 0) {
            $stmtMem = $this->pdo->prepare("SELECT meta_value FROM project_meta WHERE project_id = ? AND meta_key = 'ai_database_memory'");
            $stmtMem->execute([$this->projectId]);
            $jsonStr = $stmtMem->fetchColumn();

            // 万が一、記憶キャッシュデータがまだ存在しない場合はその場で強制自動生成（リフレッシュ）
            if (empty($jsonStr)) {
                require_once __DIR__ . '/../../src/SqlExecutionEngine.php';
                $sqlEngine = new SqlExecutionEngine($this->pdo, $this->projectId);
                $sqlEngine->generateAndSaveDatabaseMemory();
                
                // 生成された最新の記憶を再ロード
                $stmtMem->execute([$this->projectId]);
                $jsonStr = $stmtMem->fetchColumn();
            }

            // PromptManagerを読み込み、記憶をAI用制約プロンプトにコンパイルしてプロパティに格納
            require_once __DIR__ . '/../../src/PromptManager.php';
            $this->databaseMemoryPrompt = PromptManager::getDatabaseMemoryInstruction($jsonStr);
        }
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

        if ($this->tryCsvStructuredAggregationRoute()) {
            return;
        }

        if ($this->tryCsvEvidenceRoute()) {
            return;
        }

        // 2. ユーザーの質問を複数の具体的な分析観点（サブクエリ）へ因数分解
        $this->decomposeQuestion();

        // 3. 各分析ステップのループ処理（SQL生成、安全監査、実行、中間考察）
        $this->processSubQueries();

        // ステップ4：最終レポート・考察生成フェーズ（マージ・ Chart.js 構築）のstatus同期インジェクション
        sendSSE('status', [
            'step'    => 4, 
            'message' => '📈 全ての中間考察を統合し、最終レポートを描画しています...'
        ]);
        
        // 初回のストリーム生成で一旦ドラフトを生成・送出
        if (!$this->streamFinalReport()) {
            return;
        }

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // ✨【フェーズ1: 構造的欠陥の完全修復】
        // 門番からのフィードバック（データ不足等）を受け、データ追加抽出のSQL生成フェーズまで
        // 「巻き戻る」真のActor-Critic無限リトライループ
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        $maxEvalRetries = $this->maxEvalRetries();
        $this->retryCount = 0;
        chatLogger("[EVAL-POLICY] maxEvalRetries={$maxEvalRetries} | report_mode=" . ($this->reportMode ? 'on' : 'off') . " | diagram_mode=" . ($this->diagramMode ? 'on' : 'off'));

        try {
            require_once __DIR__ . '/../../src/ChatEvaluator.php';
            $evaluator = new ChatEvaluator($this->ollama_host);
            
            while ($this->retryCount < $maxEvalRetries) {
                $mergedReasoningText = implode("\n\n", $this->subAnswers);
                if (mb_strlen($mergedReasoningText) > 4000) {
                    $mergedReasoningText = mb_substr($mergedReasoningText, 0, 4000) . "\n...[制限超過による省略]";
                }

                sendSSE('status', [
                    'step'    => 4,
                    'message' => '⚖️ レポートの品質審査（LLM-as-a-Judge）を実行中...' . ($this->retryCount > 0 ? " [リトライ: {$this->retryCount}/{$maxEvalRetries}]" : "")
                ]);
                
                // 門番キック
                $this->evalResult = $evaluator->evaluateDraft($this->originalMessage, $mergedReasoningText, $this->finalResponse, $this->model);
                $evaluationMode = (string)($this->evalResult['evaluation_mode'] ?? 'unknown');
                $evaluationSource = (string)($this->evalResult['evaluation_source'] ?? 'unknown');
                $verdict = (string)($this->evalResult['verdict'] ?? 'unknown');
                $score = (int)($this->evalResult['total_score'] ?? 0);
                $relevance = (int)($this->evalResult['scores']['answer_relevance'] ?? 0);
                $faithfulness = (int)($this->evalResult['scores']['faithfulness'] ?? 0);
                chatLogger("[DEBUG] ChatEvaluator 品質審査完了。");
                chatLogger("[EVAL-" . strtoupper($evaluationMode) . "] source={$evaluationSource} | verdict={$verdict} | score={$score} | relevance={$relevance} | faithfulness={$faithfulness}");

                // 不合格（needs_revision）の場合は、評価器のverdictに応じて文章修正か追加抽出を選ぶ
                if (isset($this->evalResult) && (($this->evalResult['needs_revision'] ?? false) === true)) {
                    $this->retryCount++;
                    $feedback = $this->evalResult['feedback'] ?? 'ユーザーの要求を満たしていません。修正してください。';
                    $verdict = $this->evalResult['verdict'] ?? 'need_more_data';
                    $nextAction = trim((string)($this->evalResult['next_action'] ?? ''));
                    $sqlHint = trim((string)($this->evalResult['sql_hint'] ?? ''));
                    chatLogger("[EVAL-NG] 門番による差し戻し。verdict={$verdict} | next_action=" . ($nextAction !== '' ? $nextAction : 'none') . " | sql_hint=" . ($sqlHint !== '' ? $sqlHint : 'none') . " | フィードバック: {$feedback}");

                    if (in_array($verdict, ['revise_text_only', 'reject'], true)) {
                        sendSSE('status', [
                            'step'    => 4,
                            'message' => "📝 追加抽出は行わず、既存根拠だけで回答文を修正しています... [試行: {$this->retryCount}/{$maxEvalRetries}]"
                        ]);

                        $forbiddenActions = $this->evalResult['forbidden_actions'] ?? [];
                        if (!is_array($forbiddenActions)) {
                            $forbiddenActions = [$forbiddenActions];
                        }

                        $rewritten = $evaluator->reviseDraftTextOnly(
                            $this->originalMessage,
                            $mergedReasoningText,
                            $this->finalResponse,
                            $feedback,
                            $this->model,
                            $forbiddenActions
                        );

                        if (!empty($rewritten)) {
                            $this->finalResponse = $rewritten;
                            $this->evalResult['needs_revision'] = false;
                            $this->evalResult['feedback'] = $feedback . "\n[TEXT-ONLY-REWRITE] 既存根拠のみで最終回答を修正しました。";
                            chatLogger("[EVAL-TEXT-ONLY] verdict={$verdict} のため追加SQLを行わず最終回答を文章修正しました。");
                            break;
                        }
                    }

                    sendSSE('status', [
                        'step'    => 4,
                        'message' => "🔄 門番からデータ不足の指摘を受信。不足データを追加抽出します... [試行: {$this->retryCount}/{$maxEvalRetries}]"
                    ]);

                    // ✨【巻き戻し】門番のフィードバックを元に、新しい追加の分析観点（目的）を自律生成
                    $newSubQ = $this->generateAdditionalSubQuery([
                        'feedback' => $feedback,
                        'next_action' => $nextAction,
                        'sql_hint' => $sqlHint,
                    ]);
                    
                    // 新しい観点に基づいて、SQL生成・実行・データ抽出・中間考察を裏でキック
                    $subAnsText = $this->executeSingleSubQuery($newSubQ, 90 + $this->retryCount, "追加観点");
                    
                    // 得られた新しいデータを歴史（$this->subAnswers）へマージ
                    $this->subAnswers[] = "◆ 追加抽出観点 [リトライ {$this->retryCount}]: {$newSubQ}\n{$subAnsText}";
                    
                    sendSSE('status', [
                        'step'    => 4,
                        'message' => "🔄 追加データをマージし、レポートを再構築中... [試行: {$this->retryCount}/{$maxEvalRetries}]"
                    ]);

                    // 新たなデータを含めて、最終レポートをバックグラウンドで再生成（ストリームなし）
                    $this->generateFinalReportBackground();
                    
                } else {
                    chatLogger("[EVAL-OK] 門番の審査をパスしました（試行回数: {$this->retryCount}）。");
                    break;
                }
            }
        } catch (Exception $evalEx) {
            chatLogger("品質評価エージェントキック中に例外検出(スキップ保護): " . $evalEx->getMessage());
        }
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

        // 5. チャット対話履歴、進行ステップ、品質評価スコアの安全な一括永続化
        $this->saveHistoryAndEvaluations();

        // 6. 最終確定結果を出荷（UI側で 'result' イベントを受信し、最終的に生成された文字列へ上書きされる）
        $this->sendFinalResult();
    }

    /**
     * プロジェクトに対するアクセス権限チェック
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
            chatLogger("[SECURITY WARN] ユーザーID: {$this->user_id} が権限のない案件ID: {$this->projectId} のデータ分析APIをコールしました。処理を拒否します。");
            sendSSE('result', [
                'status'          => 'error', 
                'response'        => "⚠️ 閲覧権限エラー：この案件に対するアクセス権限がありません。", 
                'sources'         => [], 
                'mode_used'       => $this->promptKey, 
                'detected_page'   => null, 
                'hit_count'       => 0, 
                'reasoning_steps' => [],
                'applied_model'   => $this->model, 
                'created_at'      => date('Y/m/d H:i')
            ]);
            return false;
        }

        return true;
    }

    /**
     * CSVの構造化集計ルート。
     * 日付別件数のような質問は、AI読解より先にサンプル判定 -> 集計SQL生成で処理する。
     */
    private function tryCsvStructuredAggregationRoute(): bool {
        $csvAggregationPlanner = $this->getCsvAggregationPlanner();
        if (!$csvAggregationPlanner->shouldUseStructuredAggregationRoute($this->originalMessage)) {
            return false;
        }

        $routeStart = microtime(true);
        $plan = $csvAggregationPlanner->buildStructuredAggregationPlan($this->originalMessage);
        $csvAggregationAnswerFormatter = $this->getCsvAggregationAnswerFormatter();
        chatLogger("[CSV-AGG] 集計プリフライト開始 - scope: {$plan['scope']} | aggregate: {$plan['aggregate_type']} | date_granularity: {$plan['date_granularity']} | target_file: " . ($plan['target_file_name'] ?? 'all'));

        sendSSE('status', [
            'step' => 2,
            'message' => '🧭 集計意図を判定し、CSVサンプルから日付列候補を探索しています...'
        ]);

        $targets = $this->detectCsvAggregationTargets($plan);
        chatLogger("[CSV-AGG] サンプル判定完了 - target_files: " . count($targets));
        if (empty($targets)) {
            chatLogger("[CSV-AGG] サンプル判定の結果、集計に使える日付列候補を検出できませんでした。CSV証拠読解ルートへフォールバックします。");
            return false;
        }

        $targetSummary = [];
        foreach ($targets as $target) {
            $targetSummary[] = [
                'csv_file_id' => $target['csv_file_id'],
                'file_name' => $target['file_name'],
                'date_columns' => $target['date_columns'],
                'sample_rows' => $target['sample_rows_checked'],
            ];
        }
        $this->insertReasoningStep(
            1,
            'CSV集計プリフライト',
            "【集計計画】\n" . json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n\n【日付列候補】\n" . json_encode($targetSummary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        sendSSE('status', [
            'step' => 3,
            'message' => '📊 日付候補列ごとにSQL集計を実行しています...'
        ]);

        $aggregatedRows = [];
        $executedSqls = [];
        foreach ($targets as $target) {
            foreach ($target['date_columns'] as $dateColumn) {
                $sqlInfo = $this->executeCsvDateAggregationQuery($target, $dateColumn, $plan);
                if (!empty($sqlInfo['rows'])) {
                    $aggregatedRows = array_merge($aggregatedRows, $sqlInfo['rows']);
                }
                if (!empty($sqlInfo['sql'])) {
                    $executedSqls[] = [
                        'file_name' => $target['file_name'],
                        'date_column' => $dateColumn,
                        'sql' => $sqlInfo['sql'],
                        'raw_group_count' => $sqlInfo['raw_group_count'] ?? 0,
                    ];
                }
            }
        }

        if (empty($aggregatedRows)) {
            chatLogger("[CSV-AGG] 日付列候補は見つかりましたが、集計結果が0件でした。CSV証拠読解ルートへフォールバックします。");
            return false;
        }

        usort($aggregatedRows, function ($a, $b) {
            return [$a['date'], $a['file_name'], $a['date_column']] <=> [$b['date'], $b['file_name'], $b['date_column']];
        });

        $this->finalResponse = $csvAggregationAnswerFormatter->buildStructuredAggregationAnswer($plan, $aggregatedRows, $targets, $this->diagramMode);
        $this->subAnswers[] = $this->finalResponse;

        $sqlLogLines = [];
        foreach ($executedSqls as $sqlInfo) {
            $sqlLogLines[] = "### {$sqlInfo['file_name']} / {$sqlInfo['date_column']}\n"
                . "- raw groups: {$sqlInfo['raw_group_count']}\n"
                . "```sql\n{$sqlInfo['sql']}\n```";
        }
        $this->insertReasoningStep(90, 'CSV日付集計SQLの実行結果', implode("\n\n", $sqlLogLines));
        chatLogger("[CSV-AGG] 構造化集計ルート完了 - rows: " . count($aggregatedRows) . " | sqls: " . count($executedSqls) . " | elapsed: " . $this->elapsedSeconds($routeStart));
        $this->completeCsvRoute();
        return true;
    }

    private function detectCsvAggregationTargets(array $plan): array {
        $csvMetadataCatalog = $this->getCsvMetadataCatalog();
        $csvSampleRowRepository = $this->getCsvSampleRowRepository();
        $csvDateColumnDetector = $this->getCsvDateColumnDetector();
        $files = $csvMetadataCatalog->loadFiles();
        if ($plan['target_file_name']) {
            $files = array_values(array_filter($files, fn($file) => $file['file_name'] === $plan['target_file_name']));
        }

        $targets = [];
        foreach ($files as $file) {
            $sampleRows = $csvSampleRowRepository->loadRowsForFile((int)$file['id'], 12);
            $dateColumns = $csvDateColumnDetector->detectDateColumnsForFile($file, $sampleRows);
            if (empty($dateColumns)) {
                continue;
            }
            $targets[] = [
                'csv_file_id' => (int)$file['id'],
                'file_name' => (string)$file['file_name'],
                'columns' => $file['columns'],
                'date_columns' => $dateColumns,
                'sample_rows_checked' => count($sampleRows),
            ];
        }

        return $targets;
    }

    private function executeCsvDateAggregationQuery(array $target, string $dateColumn, array $plan): array {
        $csvAggregationQueryBuilder = $this->getCsvAggregationQueryBuilder();
        $csvDateColumnDetector = $this->getCsvDateColumnDetector();
        $csvFileId = (int)$target['csv_file_id'];
        $sql = $csvAggregationQueryBuilder->buildDateAggregationSql($csvFileId, $dateColumn);

        chatLogger("[CSV-AGG-SQL] file={$target['file_name']} | column={$dateColumn} | sql={$sql}");

        $stmt = $this->pdo->query($sql);
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $normalized = [];
        foreach ($rows as $row) {
            $bucket = $csvDateColumnDetector->normalizeDateBucket((string)($row['raw_date'] ?? ''), $plan['date_granularity']);
            if ($bucket === null) {
                continue;
            }
            $key = $target['file_name'] . '|' . $dateColumn . '|' . $bucket;
            if (!isset($normalized[$key])) {
                $normalized[$key] = [
                    'file_name' => $target['file_name'],
                    'date_column' => $dateColumn,
                    'date' => $bucket,
                    'record_count' => 0,
                    'columns' => $target['columns'],
                ];
            }
            $normalized[$key]['record_count'] += (int)$row['record_count'];
        }

        return [
            'sql' => $sql,
            'raw_group_count' => count($rows),
            'rows' => array_values($normalized),
        ];
    }

    /**
     * CSV質問を「SQLで答えを作る」のではなく、「全CSVレコードを証拠として読解して答える」高速・高精度ルート。
     */
    private function tryCsvEvidenceRoute(): bool {
        $csvQuestionRouter = $this->getCsvQuestionRouter();
        if (!$csvQuestionRouter->shouldUseEvidenceRoute($this->originalMessage)) {
            return false;
        }

        $routeStart = microtime(true);
        $csvEvidenceReader = $this->getCsvEvidenceReader();
        $csvSearchService = $this->getCsvSearchService();
        $totalRows = $csvEvidenceReader->countRows();
        $searchTerms = $csvSearchService->extractSearchTerms($this->originalMessage);
        chatLogger("[CSV-SEARCH] CSV探索フェーズ開始 - totalRows: {$totalRows} | terms: " . ($searchTerms ? implode(', ', $searchTerms) : 'なし'));

        if ($this->tryCsvMetadataRoute()) {
            return true;
        }

        if ($totalRows <= 0) {
            return false;
        }

        $searchResult = [
            'rows' => [],
            'hit_count' => 0,
            'terms' => $searchTerms,
            'limited' => false,
            'mode' => 'broad_overview',
        ];

        if ($searchTerms) {
            $searchResult = $csvEvidenceReader->loadRowsByKeywords($searchTerms, 300);
            chatLogger("[CSV-SEARCH] キーワード検索完了 - hits: {$searchResult['hit_count']} | loaded: " . count($searchResult['rows']) . " | limited: " . ($searchResult['limited'] ? 'yes' : 'no') . " | elapsed: " . $this->elapsedSeconds($routeStart));

            if ($searchResult['hit_count'] === 0 && $this->tryCsvNoHitRoute($searchTerms, $totalRows, $routeStart)) {
                return true;
            }
        } else {
            chatLogger("[CSV-SEARCH] 有効な検索語がないため、広域概況ルート候補として処理します。");
        }

        if (!$searchTerms && $totalRows > 100 && $this->tryCsvLargeOverviewRoute($totalRows, $routeStart)) {
            return true;
        }

        $rows = $searchTerms ? $searchResult['rows'] : $csvEvidenceReader->loadAllRows();
        chatLogger("[CSV-EVIDENCE] DBレコード収集完了 - rows: " . count($rows) . " | totalRows: {$totalRows} | elapsed: " . $this->elapsedSeconds($routeStart));
        if (empty($rows)) {
            return false;
        }

        if ($this->tryCsvSmallSummaryRoute($rows, $routeStart, $searchResult)) {
            return true;
        }

        chatLogger("[CSV-EVIDENCE] CSV証拠読解ルートを起動します。対象行数: " . count($rows) . " | searchHits: {$searchResult['hit_count']}");
        sendSSE('status', [
            'step' => 2,
            'message' => '📚 CSVデータベースレコードを検索で絞り込み、質問に関係する証拠を分割読解しています...'
        ]);

        $this->insertReasoningStep(1, 'CSV証拠レコードの検索収集', $csvEvidenceReader->buildCollectionSummary($rows, $searchResult));

        $batches = $csvEvidenceReader->chunkRows($rows, 50, 9000);
        chatLogger("[CSV-EVIDENCE] バッチ分割完了 - batches: " . count($batches) . " | maxRows: 50 | maxChars: 9000");
        $batchFindings = [];
        foreach ($batches as $idx => $batch) {
            $batchNo = $idx + 1;
            $total = count($batches);
            $batchStart = microtime(true);
            $batchChars = mb_strlen($csvEvidenceReader->formatBatch($batch));
            chatLogger("[CSV-EVIDENCE] バッチAI読解開始 - batch: {$batchNo}/{$total} | rows: " . count($batch) . " | chars: {$batchChars}");
            sendSSE('status', [
                'step' => 3,
                'message' => "🔎 CSV証拠を読解中 ({$batchNo}/{$total})..."
            ]);

            $finding = $csvEvidenceReader->analyzeBatch($batch, $batchNo, $total);
            chatLogger("[CSV-EVIDENCE] バッチAI読解完了 - batch: {$batchNo}/{$total} | responseChars: " . mb_strlen($finding) . " | elapsed: " . $this->elapsedSeconds($batchStart));
            $batchFindings[] = $finding;
            $this->insertReasoningStep(10 + $idx, "CSV証拠バッチ読解 {$batchNo}/{$total}", $finding);
        }

        sendSSE('status', [
            'step' => 4,
            'message' => '🧾 保存済みのCSV読解結果を統合し、最終回答を生成しています...'
        ]);

        $synthesisStart = microtime(true);
        chatLogger("[CSV-EVIDENCE] 統合AI回答生成開始 - findingCount: " . count($batchFindings));
        $this->finalResponse = $csvEvidenceReader->synthesizeAnswer($rows, $batchFindings);
        chatLogger("[CSV-EVIDENCE] 統合AI回答生成完了 - responseChars: " . mb_strlen($this->finalResponse) . " | elapsed: " . $this->elapsedSeconds($synthesisStart));
        $this->subAnswers = $batchFindings;
        $this->insertReasoningStep(90, 'CSV証拠読解結果の統合', $this->finalResponse);
        chatLogger("[CSV-EVIDENCE] 履歴保存開始 - totalElapsed: " . $this->elapsedSeconds($routeStart));
        $this->completeCsvRoute();
        chatLogger("[CSV-EVIDENCE] CSV証拠読解ルートが完了しました。totalElapsed: " . $this->elapsedSeconds($routeStart));
        return true;
    }

    /**
     * 小規模CSVの「内容まとめ」はAI読解を挟まず、DB実データから即時サマリーを作る。
     */
    private function tryCsvSmallSummaryRoute(array $rows, float $routeStart, array $searchResult = []): bool {
        $csvQuestionRouter = $this->getCsvQuestionRouter();
        if (!$csvQuestionRouter->shouldUseSmallSummaryRoute($this->originalMessage, count($rows))) {
            return false;
        }

        $csvEvidenceReader = $this->getCsvEvidenceReader();
        $csvSummaryFormatter = $this->getCsvSummaryFormatter();
        chatLogger("[CSV-SUMMARY] 小規模CSV即答ルートを起動します。rows: " . count($rows));
        sendSSE('status', [
            'step' => 2,
            'message' => '📊 CSVの内容をデータベースレコードから直接要約しています...'
        ]);

        $summaryStart = microtime(true);
        $this->finalResponse = $csvSummaryFormatter->buildSmallSummaryAnswer($rows, $searchResult);
        chatLogger("[CSV-SUMMARY] PHPサマリー生成完了 - responseChars: " . mb_strlen($this->finalResponse) . " | elapsed: " . $this->elapsedSeconds($summaryStart));

        $this->insertReasoningStep(1, 'CSVレコードの検索収集', $csvEvidenceReader->buildCollectionSummary($rows, $searchResult));
        $this->insertReasoningStep(90, '小規模CSVサマリー即時生成', $this->finalResponse);

        chatLogger("[CSV-SUMMARY] 履歴保存開始 - totalElapsed: " . $this->elapsedSeconds($routeStart));
        $this->completeCsvRoute();
        chatLogger("[CSV-SUMMARY] 小規模CSV即答ルートが完了しました。totalElapsed: " . $this->elapsedSeconds($routeStart));
        return true;
    }

    /**
     * 「どんな項目/列/カラムがあるか」はレコード読解せず、CSVメタデータだけで即答する。
     */
    private function tryCsvMetadataRoute(): bool {
        $csvQuestionRouter = $this->getCsvQuestionRouter();
        if (!$csvQuestionRouter->shouldUseMetadataRoute($this->originalMessage)) {
            return false;
        }

        $csvMetadataCatalog = $this->getCsvMetadataCatalog();
        $csvSummaryFormatter = $this->getCsvSummaryFormatter();
        $files = $csvMetadataCatalog->loadFiles();
        if (empty($files)) {
            return false;
        }

        chatLogger("[CSV-METADATA] CSV項目メタデータ即答ルートを起動します。対象ファイル数: " . count($files));
        sendSSE('status', [
            'step' => 2,
            'message' => '📋 CSVの項目一覧をメタデータから確認しています...'
        ]);

        $this->finalResponse = $csvSummaryFormatter->buildMetadataAnswer($files);
        $this->insertReasoningStep(1, 'CSVファイルの項目メタデータ確認', $this->finalResponse);
        $this->completeCsvRoute();
        chatLogger("[CSV-METADATA] CSV項目メタデータ即答ルートが完了しました。");
        return true;
    }

    private function createUtf8Normalizer(): callable {
        return function (string $text): string {
            return $this->normalizeUtf8($text);
        };
    }

    private function getCsvSearchService(): CsvSearchService {
        if ($this->csvSearchService instanceof CsvSearchService) {
            return $this->csvSearchService;
        }

        require_once __DIR__ . '/../../src/CsvSearchService.php';
        require_once __DIR__ . '/../../src/CsvSearchTermExtractor.php';
        $csvMetadataCatalog = $this->getCsvMetadataCatalog();
        $extractor = new CsvSearchTermExtractor($this->createUtf8Normalizer());
        $this->csvSearchService = new CsvSearchService($extractor, $csvMetadataCatalog);

        return $this->csvSearchService;
    }

    private function getCsvDateColumnDetector(): CsvDateColumnDetector {
        if ($this->csvDateColumnDetector instanceof CsvDateColumnDetector) {
            return $this->csvDateColumnDetector;
        }

        require_once __DIR__ . '/../../src/CsvDateColumnDetector.php';
        $this->csvDateColumnDetector = new CsvDateColumnDetector($this->createUtf8Normalizer());

        return $this->csvDateColumnDetector;
    }

    private function getCsvAggregationPlanner(): CsvAggregationPlanner {
        if ($this->csvAggregationPlanner instanceof CsvAggregationPlanner) {
            return $this->csvAggregationPlanner;
        }

        require_once __DIR__ . '/../../src/CsvAggregationPlanner.php';
        $csvSearchService = $this->getCsvSearchService();
        $this->csvAggregationPlanner = new CsvAggregationPlanner(
            $this->createUtf8Normalizer(),
            $this->createMentionedCsvFileNameResolver($csvSearchService)
        );

        return $this->csvAggregationPlanner;
    }

    private function getCsvAggregationQueryBuilder(): CsvAggregationQueryBuilder {
        if ($this->csvAggregationQueryBuilder instanceof CsvAggregationQueryBuilder) {
            return $this->csvAggregationQueryBuilder;
        }

        require_once __DIR__ . '/../../src/CsvAggregationQueryBuilder.php';
        $this->csvAggregationQueryBuilder = new CsvAggregationQueryBuilder();

        return $this->csvAggregationQueryBuilder;
    }

    private function getCsvAggregationAnswerFormatter(): CsvAggregationAnswerFormatter {
        if ($this->csvAggregationAnswerFormatter instanceof CsvAggregationAnswerFormatter) {
            return $this->csvAggregationAnswerFormatter;
        }

        require_once __DIR__ . '/../../src/CsvAggregationAnswerFormatter.php';
        $this->csvAggregationAnswerFormatter = new CsvAggregationAnswerFormatter();

        return $this->csvAggregationAnswerFormatter;
    }

    private function tryCsvNoHitRoute(array $terms, int $totalRows, float $routeStart): bool {
        chatLogger("[CSV-SEARCH] 検索ヒット0件のため、全件AI読解を行わずメタデータ回答へフォールバックします。terms: " . implode(', ', $terms));
        sendSSE('status', [
            'step' => 2,
            'message' => '🔎 CSVを検索しましたが該当レコードがないため、登録済みCSVの範囲を整理しています...'
        ]);

        $csvMetadataCatalog = $this->getCsvMetadataCatalog();
        $csvEvidenceReader = $this->getCsvEvidenceReader();
        $files = $csvMetadataCatalog->loadFiles();
        $this->finalResponse = $csvEvidenceReader->buildNoHitAnswer($terms, $files, $totalRows);
        $this->insertReasoningStep(1, 'CSVキーワード検索', "検索語: " . implode(" / ", $terms) . "\n検索ヒット: 0件\n総CSVレコード数: {$totalRows}件");
        $this->completeCsvRoute();
        chatLogger("[CSV-SEARCH] 検索ヒット0件フォールバック完了。totalElapsed: " . $this->elapsedSeconds($routeStart));
        return true;
    }

    private function tryCsvLargeOverviewRoute(int $totalRows, float $routeStart): bool {
        chatLogger("[CSV-OVERVIEW] 広域質問かつ大規模CSVのため、全件AI読解を行わず概況ルートを起動します。totalRows: {$totalRows}");
        sendSSE('status', [
            'step' => 2,
            'message' => '📊 CSV件数が多いため、メタデータと代表サンプルから概況を整理しています...'
        ]);

        $csvMetadataCatalog = $this->getCsvMetadataCatalog();
        $csvEvidenceReader = $this->getCsvEvidenceReader();
        $files = $csvMetadataCatalog->loadFiles();
        $sampleRows = $csvEvidenceReader->loadSampleRows(80);
        $this->finalResponse = $csvEvidenceReader->buildLargeOverviewAnswer($files, $sampleRows, $totalRows);
        $this->insertReasoningStep(1, 'CSV広域探索', $csvEvidenceReader->buildCollectionSummary($sampleRows, [
            'terms' => [],
            'hit_count' => $totalRows,
            'limited' => true,
            'mode' => 'broad_overview',
        ]));
        $this->completeCsvRoute();
        chatLogger("[CSV-OVERVIEW] 大規模CSV概況ルートが完了しました。totalElapsed: " . $this->elapsedSeconds($routeStart));
        return true;
    }

    private function elapsedSeconds(float $start): string {
        return number_format(microtime(true) - $start, 2) . '秒';
    }

    private function completeCsvRoute(): void {
        $this->insertReasoningStep(99, '最終報告書・グラフの統合生成', '完了');
        $this->saveHistoryAndEvaluations();
        $this->sendFinalResult();
    }

    private function createOutputModeInstructionsProvider(): callable {
        return function (): string {
            return $this->getOutputModeInstructions();
        };
    }

    private function createChatLoggerCallback(): callable {
        return function (string $message): void {
            chatLogger($message);
        };
    }

    private function createMentionedCsvFileNameResolver(CsvSearchService $csvSearchService): callable {
        return function (string $question) use ($csvSearchService): ?string {
            return $csvSearchService->findMentionedCsvFileName($question);
        };
    }

    private function createCsvRowsAvailableChecker(CsvEvidenceReader $csvEvidenceReader): callable {
        return function () use ($csvEvidenceReader): bool {
            return $csvEvidenceReader->countRows() > 0;
        };
    }

    private function getCsvEvidenceReader(): CsvEvidenceReader {
        if ($this->csvEvidenceReader instanceof CsvEvidenceReader) {
            return $this->csvEvidenceReader;
        }

        require_once __DIR__ . '/../../src/CsvEvidenceReader.php';
        $metadataCatalog = $this->getCsvMetadataCatalog();
        $summaryFormatter = $this->getCsvSummaryFormatter();
        $this->csvEvidenceReader = new CsvEvidenceReader(
            $this->pdo,
            (int)$this->projectId,
            $this->originalMessage,
            $this->ollama_host,
            $this->model,
            $this->promptKey,
            $this->projectContext,
            $metadataCatalog,
            $summaryFormatter,
            $this->createOutputModeInstructionsProvider()
        );

        return $this->csvEvidenceReader;
    }

    private function getCsvQuestionRouter(): CsvQuestionRouter {
        if ($this->csvQuestionRouter instanceof CsvQuestionRouter) {
            return $this->csvQuestionRouter;
        }

        require_once __DIR__ . '/../../src/CsvQuestionRouter.php';
        $csvSearchService = $this->getCsvSearchService();
        $csvEvidenceReader = $this->getCsvEvidenceReader();
        $this->csvQuestionRouter = new CsvQuestionRouter(
            $this->createMentionedCsvFileNameResolver($csvSearchService),
            $this->createCsvRowsAvailableChecker($csvEvidenceReader),
            $this->createChatLoggerCallback()
        );

        return $this->csvQuestionRouter;
    }

    private function getCsvSummaryFormatter(): CsvSummaryFormatter {
        if ($this->csvSummaryFormatter instanceof CsvSummaryFormatter) {
            return $this->csvSummaryFormatter;
        }

        require_once __DIR__ . '/../../src/CsvSummaryFormatter.php';
        $csvMetadataCatalog = $this->getCsvMetadataCatalog();
        $this->csvSummaryFormatter = new CsvSummaryFormatter($csvMetadataCatalog);

        return $this->csvSummaryFormatter;
    }

    private function getCsvMetadataCatalog(): CsvMetadataCatalog {
        if ($this->csvMetadataCatalog instanceof CsvMetadataCatalog) {
            return $this->csvMetadataCatalog;
        }

        require_once __DIR__ . '/../../src/CsvMetadataCatalog.php';
        $this->csvMetadataCatalog = new CsvMetadataCatalog($this->pdo, (int)$this->projectId);

        return $this->csvMetadataCatalog;
    }

    private function getCsvSampleRowRepository(): CsvSampleRowRepository {
        if ($this->csvSampleRowRepository instanceof CsvSampleRowRepository) {
            return $this->csvSampleRowRepository;
        }

        require_once __DIR__ . '/../../src/CsvSampleRowRepository.php';
        $this->csvSampleRowRepository = new CsvSampleRowRepository($this->pdo);

        return $this->csvSampleRowRepository;
    }

    private function insertReasoningStep(int $stepNumber, string $subQuery, string $subAnswer): void {
        try {
            $subQuery = $this->normalizeUtf8($subQuery);
            $subAnswer = $this->normalizeUtf8($subAnswer);
            $originalQuestion = $this->normalizeUtf8($this->originalMessage);
            $stmt = $this->pdo->prepare("
                INSERT INTO chat_reasoning_steps
                    (project_id, session_id, original_question, step_number, sub_query, sub_answer, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$this->projectId, $this->reasoningId, $originalQuestion, $stepNumber, $subQuery, $subAnswer]);
        } catch (Exception $e) {
            chatLogger("[CSV-EVIDENCE] reasoning step保存に失敗: " . $e->getMessage());
        }
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

    /**
     * 実在構造（SHOW TABLES / DESCRIBE）の取得、およびプロジェクト内全データの動的カウント
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

    /**
     * 大目標の要求をAIを使って因数分解（サブ分析タスクの抽出）
     */
    private function decomposeQuestion(): void {
        chatLogger("[DEBUG] 質問の因数分解を開始します。質問: {$this->originalMessage}");
        
        $decompPrompt = "あなたは極めて冷静で論理的なシニア・データアナリストです。\n"
                      . "提示された実在データベース構造を頭に入れた上で、ユーザーの【最初の質問】の意図・目的を完全に達成するために、データベースからどのような切り口で集計すべきか、1〜2つの「具体的な分析観点（サブクエリ）」に因数分解してください。\n\n"
                      . "【絶対厳守のアンカー・ルール（目的すり替えの完全禁止）】\n"
                      . "■ お前の最大の使命は、最後の回答が【最初の質問】の要求に100%マッチしている状態を作ることである。\n"
                      . "■ ユーザーの要求が「データ（CSV）の集計」「アップロード資料の分析」を指している場合は、勝手にシステム管理テーブル（ `chat_evaluations` のAI評価スコアや `logs` など）をターゲットにしたサブタスクをでっち上げてはならない（厳禁）。\n"
                      . "■ ユーザーの質問がアバウトな挨拶や準備の段階（例：データ集計しようと思います、等）である場合は、勝手に深読みして関係のない内部評価スコアの集計手順を組み立てるな。文脈から推測される本質的な一般データテーブル（ `project_csv_rows` など）の基礎的なカウントや概要把握のみに絞って手順を分解せよ。\n"
                      . "■ ユーザーから「AIの評価スコアを集計して」「 chat_evaluations を分析して」と【明示的・直接的にシステムデータの集計を指定された場合のみ】、システムテーブルを対象に含めてよい。\n\n"
                      . "必ず以下のJSON配列形式のみで出力してください。挨拶やMarkdownの説明は一切不要です。\n"
                      . "[{\"query\": \"ユーザーの最初の質問の枠内から絶対に脱線しない具体的な集計目的\"}]\n\n"
                      . $this->schemaInfo . "\n\n"
                      . "【ユーザーの最初の質問】\n{$this->originalMessage}\n\n"
                      . "分析観点リスト(JSON):";
        
        chatLogger("[DEBUG] Ollama因数分解API呼び出し送信前...");
        $decomp_res = callOllamaChat($this->ollama_host, $this->model, $decompPrompt, "", 'json', ["temperature" => 0.0, "top_p" => 0.1, "num_ctx" => 4096]);
        chatLogger("[DEBUG] Ollama因数分解API応答受信 raw: " . $decomp_res);
        
        $fence = str_repeat("\x60", 3);
        $clean_json = $decomp_res;
        
        if (preg_match('/' . preg_quote($fence, '/') . '(?:json)?\s*(\\[.*?\\]|\\{.*?\\})\s*' . preg_quote($fence, '/') . '/is', $decomp_res, $matches)) {
            $clean_json = $matches[1];
        } elseif (preg_match('/(\\[.*?\\]|\\{.*?\\})/is', $decomp_res, $matches)) {
            $clean_json = $matches[1];
        }
        
        $this->subQueries = json_decode($clean_json, true);

        if (is_array($this->subQueries) && isset($this->subQueries['query'])) {
            $this->subQueries = [$this->subQueries];
            chatLogger("[FORMAT-RECOVERY] 単一の分析オブジェクトを配列構造に自動ラップ救済しました。");
        }
        
        if (!is_array($this->subQueries) || empty($this->subQueries) || !isset($this->subQueries[0]['query'])) {
            chatLogger("[WARN] サブクエリのパースに失敗しました。フォールバックとして元メッセージ全体を単一クエリとしてセットします。");
            $this->subQueries = [["query" => "要求全体の総合的な集計と分析"]];
        } else {
            chatLogger("[DEBUG] サブクエリのパースに成功。サブ質問数: " . count($this->subQueries));
        }
    }

    /**
     * サブ分析観点ループの統制
     */
    private function processSubQueries(): void {
        $step_counter = 0;
        $total_steps = count($this->subQueries);

        foreach ($this->subQueries as $subQItem) {
            $step_counter++;
            $subQ = $subQItem['query'];
            
            // リファクタリング：SQL生成〜考察取得までを独立メソッドへ委譲
            $sub_ans_text = $this->executeSingleSubQuery($subQ, $step_counter, "分析ステップ {$step_counter}/{$total_steps}");
            $this->subAnswers[] = "◆ 観点 {$step_counter}: {$subQ}\n{$sub_ans_text}";
        }
    }

    /**
     * ✨【フェーズ1: 巻き戻し用独立メソッド】
     * 1つの分析観点（サブクエリ）に基づくSQL生成、自律デバッグループ、データスライス抽出、および考察生成を行う。
     * 門番からのリトライ時にも同じロジックを流用して安全に動作させる。
     */
    private function executeSingleSubQuery(string $subQ, int $step_counter, string $stepLabel = "追加ステップ"): string {
        $this->model = $this->model; // 脳内型警告完全パージ維持

        chatLogger("【データ分析】ステップ {$step_counter}: 観点「{$subQ}」のSQL生成中...");
        
        sendSSE('status', [
            'step'    => 2, 
            'message' => "🔬 [{$stepLabel}]「{$subQ}」に基づくSQLを集計構築中..."
        ]);

        try {
            $stmtInsert = $this->pdo->prepare("INSERT INTO chat_reasoning_steps (project_id, session_id, original_question, step_number, sub_query, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmtInsert->execute([$this->projectId, $this->reasoningId, $this->originalMessage, $step_counter, $subQ]);
        } catch (Exception $dbEx) {
            chatLogger("[ERROR] chat_reasoning_steps へのINSERTに失敗: " . $dbEx->getMessage());
        }

        // 🛠️【修正要件1】AIの思考リソースを集計に特化させるため、$sql_sys_promptを完全に書き換え（思考解放プロトコル）
        $sql_sys_prompt = "【ミッション】\n"
                        . "お前は実在するデータベース構造を自律監査するデータアナリストAIである。提示された「INFORMATION_SCHEMAコンテキスト」および「実在データ総数マトリクス」を熟読し、ユーザーの質問を解決するために最適なSELECTクエリを1つ構築せよ。\n\n"
                        . "【最重要：思考解放プロトコル】\n"
                        . "■ マルチテナント隔離（ `project_id` や `csv_file_id` によるデータの覗き見防止条件 ）については、システム（PHP側）が実行直前に自動的にWHERE句へ結合・強制インジェクトするため、お前は【一切記述しなくてよい】。条件句への project_id の記載は完全に省略せよ。\n"
                        . "■ お前はただ、ユーザーの質問に答えるために「どのテーブルをFROM/JOINし、どのJSONキーを抽出し、どうグループ化・集計するか」という【純粋な集計ロジックの構築】だけに全リソースを集中させよ。\n\n"
                        . "【重要構文ルール】\n"
                        . "1. 過去の嘘のスキーマの記憶は完全パージせよ。必ず、提供された【INFORMATION_SCHEMAコンテキスト】に現実に表記されている実在の物理カラム構成のみを使用すること。\n"
                        . "2. CSVデータ自体の「中身・本文」の集計や概要, 傾向をユーザーから求められた場合は、メタ情報を管理する `project_csv_files` ではなく、必ず実データ行がレコードとして詰まっている【 `project_csv_rows` 】テーブルを使用せよ。\n"
                        . "3. MySQL 8.0 の日本語JSONキー抽出は `JSON_UNQUOTE(JSON_EXTRACT(T1.row_data, '$.\"項目名\"'))` を使用せよ。`row_data->>$.項目名` や `row_data->>$.'項目名'` は生成禁止。\n"
                        . "   `project_csv_rows` に `row_count` カラムは存在しない。CSV行数は `COUNT(T1.id)`、CSVファイル側の登録行数は `project_csv_files.row_count` を使用せよ。\n"
                        . "4. テーブルのエイリアスは必ず `project_csv_rows T1` のように `T1` などの一意のエイリアスを付与せよ。\n"
                        . "5. 【絶対Group Byルール】SELECT 句に集計関数（SUM/AVG/COUNT等）と, 非集計カラム（JSON項目含む）を同時に含める場合は、必ずクエリの末尾に `GROUP BY` 句を明記せよ。これを怠ると MySQL 1140 構文エラーで即死する。\n\n"
                        . "【出力制約】\n"
                        . "出力は必ず実行可能なSQL文字列1つのみを内包したJSON形式【のみ】を出力せよ。Markdownや余計な解説文プロースは一切排除せよ。\n"
                        . '{"sql": "SELECT ..."}';

        // ✨【フェーズ4】初回SQL生成システムプロンプトの最先頭へ記憶プロンプトをインジェクト
        $sql_sys_prompt = $this->databaseMemoryPrompt . "\n" . $sql_sys_prompt;

        $sql_user_prompt = $this->schemaInfo . "\n\n【ユーザーの分析観点】\n" . $subQ;
        $sql_model = $this->model;
        
        // 初回SQL生成オプション引き締め維持
        $sql_json_str = callOllamaChat($this->ollama_host, $sql_model, $sql_sys_prompt, $sql_user_prompt, 'json', ["temperature" => 0.0, "top_p" => 0.1, "num_ctx" => 4096]);
        
        // クレンジングと安全監査実行メソッドの呼び出し（自律デバッグリトライループ層へ移行）
        $sub_ans_text = $this->executeAndAnalyzeSql($sql_json_str, $subQ, $step_counter, $stepLabel);

        try {
            $stmtUpdAns = $this->pdo->prepare("UPDATE chat_reasoning_steps SET sub_answer = ? WHERE session_id = ? AND step_number = ?");
            $stmtUpdAns->execute([$sub_ans_text, $this->reasoningId, $step_counter]);
        } catch (Exception $dbEx2) {
            chatLogger("[ERROR] chat_reasoning_steps のUPDATEに失敗: " . $dbEx2->getMessage());
        }
        
        return $sub_ans_text;
    }

    /**
     * ✨【フェーズ1: 巻き戻し用追加クエリ生成メソッド】
     * 門番からの修正指示（Feedback）に基づき、新しいデータ抽出の目的を自律生成させる
     */
    private function generateAdditionalSubQuery($feedbackInput): string {
        require_once __DIR__ . '/../../src/PromptManager.php';

        if (is_array($feedbackInput)) {
            $feedback = trim((string)($feedbackInput['feedback'] ?? ''));
            $nextAction = trim((string)($feedbackInput['next_action'] ?? ''));
            $sqlHint = trim((string)($feedbackInput['sql_hint'] ?? ''));
        } else {
            $feedback = trim((string)$feedbackInput);
            $nextAction = '';
            $sqlHint = '';
        }

        $sysPrompt = "あなたは超一流のシステムアーキテクトおよびデータアナリストです。\n"
                   . "品質審査責任者から、現在のレポートに対する以下の【絶対修正命令（データ不足等の指摘）】を受けました。\n\n"
                   . "【品質審査責任者からの絶対修正命令】\n{$feedback}\n\n"
                   . ($nextAction !== '' ? "【推奨される次の一手】\n{$nextAction}\n\n" : '')
                   . ($sqlHint !== '' ? "【SQL/集計ヒント】\n{$sqlHint}\n\n" : '')
                   . "この指示に完全に従い、不足しているデータを新たに抽出するための「追加の分析観点（SQLで集計・検索する具体的な目的）」を1つだけ、簡潔な文字列として出力してください。\n"
                   . "【絶対ルール】\n出力はJSONではなく、純粋なテキストのみにしてください。挨拶や説明プロースは一切禁止します。";

        // 記憶プロンプトをインジェクトして実在のスキーマをカンニングさせる
        $sysPrompt = $this->databaseMemoryPrompt . "\n" . $sysPrompt;

        $userPrompt = "【ユーザーの元の質問】\n{$this->originalMessage}\n\n追加の分析観点:";
        $thoughtDummy = "";
        
        $newSubQ = callOllamaChat($this->ollama_host, $this->model, $sysPrompt, $userPrompt, null, ["temperature" => 0.0, "top_p" => 0.1, "num_ctx" => 2048], $thoughtDummy);
        
        return trim($newSubQ);
    }

    /**
     * 共通監査・実行エンジンへの委譲および【自己反省・自律修復リトライループ】統制コア
     * ★[ログ最高解像度・AI脳内ハック超詳細化版]
     */
    private function executeAndAnalyzeSql(string $sqlJsonStr, string $subQ, int $stepCounter, string $stepLabel = ""): string {
        $fence = str_repeat("\x60", 3);
        
        // モード別上限までの自律デバッグループ（while構造）の展開
        $max_retries = $this->maxSqlRepairRetries();
        $retry_count = 0;
        $execResult = ['success' => false, 'error' => 'クエリが初期化されていません。', 'data' => []];
        chatLogger("[SQL-REPAIR-POLICY] max_retries={$max_retries} | report_mode=" . ($this->reportMode ? 'on' : 'off') . " | diagram_mode=" . ($this->diagramMode ? 'on' : 'off'));

        require_once __DIR__ . '/../../src/SqlExecutionEngine.php';
        $sqlEngine = new SqlExecutionEngine($this->pdo, $this->projectId);

        while ($retry_count <= $max_retries) {
            // 📢 [超詳細ログ1] Ollamaから届いた「パース前」の生JSONレスポンスをそのまま完全可視化
            chatLogger("[OLLAMA-RAW-RESPONSE] (試行 {$retry_count}/{$max_retries}) Ollama側からの受信生データ:\n" . $sqlJsonStr);

            // 初回のJSONパース・抽出処理
            $clean_json = preg_replace('/^' . preg_quote($fence, '/') . '(?:json|sql)?\s*(.*?)\s*' . preg_quote($fence, '/') . '$/ms', '$1', trim($sqlJsonStr));
            $sql_data = json_decode($clean_json, true);
            
            if (is_array($sql_data) && isset($sql_data['sql'])) {
                $generated_sql = trim($sql_data['sql']);
            } else {
                if (preg_match('/SELECT\s+.*?(?:;|$)/is', $sqlJsonStr, $matches)) {
                    $generated_sql = trim($matches[0]);
                } else {
                    $generated_sql = trim($clean_json);
                }
            }

            // 📢 [超詳細ログ2] 万能クレンザーで補正をかける「前」の、AIが組み立てた素のSQLを克明に記録
            chatLogger("[Text-to-SQL-BEFORE] (試行 {$retry_count}/{$max_retries}) プログラム補正前の素のSQL: " . $generated_sql);

            // ━━━━【SQL実行直前の構文補正レイヤー（万能クレンザー）】━━━━
            // 追加防衛シールド：AIが変数に逃げた場合も、水際で生のプロジェクトID数字へ強制書き換え
            $generated_sql = preg_replace('/:\??project_id/i', $this->projectId, $generated_sql);
            
            // 🔄 [最終ネジ締め修正] SQLを絶対に破壊せず、日本語キーだけを確実に射撃する安全クレンザー（Unicodeブロック完全拘束版）
            $generated_sql = preg_replace(
                "/((?:[a-zA-Z0-9_]+\.)?row_data)\s*->>\s*['\"]?\\$?\.?([a-zA-Z0-9_\-\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{4E00}-\x{9FAF}]+)['\"]?/u",
                'JSON_UNQUOTE(JSON_EXTRACT($1, \'$."$2"\'))',
                $generated_sql
            );
            
            $generated_sql = preg_replace('/' . preg_quote($fence, '/') . 'sql|' . preg_quote($fence, '/') . '/i', '', $generated_sql);
            $generated_sql = preg_replace('/\\s+COLLATE\\s+[\'"]?[a-zA-Z0-9_-]+[\'"]?/i', '', $generated_sql);
            
            // 📢 [超詳細ログ3] プログラムが構文補正した「後」の、実際にMySQLへ送り込まれる最終確定SQLを記録
            chatLogger("[Text-to-SQL-AFTER]  (試行 {$retry_count}/{$max_retries}) プログラム補正後の最終実行SQL: " . $generated_sql);

            // 動的ホワイトリスト照合回路
            if (preg_match('/FROM\s+`?([a-zA-Z0-9_-]+)`?/i', $generated_sql, $tableMatch)) {
                $extractedTable = $tableMatch[1];
                if (in_array($extractedTable, $this->dynamicTableWhitelist, true)) {
                    chatLogger("[SECURITY APPROVED] AIが自律特定したテーブル '{$extractedTable}' は動的ホワイトリストに存在するため、監査パスを安全承認します。");
                }
            }

            // フロントエンド UI への進捗（4段階インジケーター）同期SSEパケット送信
            if ($retry_count > 0) {
                sendSSE('status', [
                    'step'    => 3,
                    'message' => "🛠️ [自律修復: 試行 {$retry_count}/{$max_retries}回] クエリのエラー原因を分析し、SQLを自律デバッグ中..."
                ]);
            } else {
                sendSSE('status', [
                    'step'    => 3, 
                    'message' => "📊 [{$stepLabel}] 集計結果の安全監査を実行し、中間考察を生成中..."
                ]);
            }

            // 共通実行エンジンへの安全委譲
            $execResult = $sqlEngine->execute($generated_sql);

            // 安全に開通（success === true）した瞬間にループを即座に脱出
            if ($execResult['success'] === true) {
                chatLogger("[DEBUG-LOOP] 成功！ 試行 {$retry_count} 回目にしてSQLの正常実行開通を確認しました。");
                break;
            }

            // 📢 [超詳細ログ4] 実行失敗時、セキュリティ監査の拒否理由やMySQLの生エラー文をダイレクトに記録
            chatLogger("[SQL-EXEC-FAILED] (試行 {$retry_count}/{$max_retries}) 監査拒否またはMySQL生エラー文: " . ($execResult['error'] ?? 'Unknown Error'));
            $repairGuidance = $sqlEngine->buildRepairGuidance($generated_sql, (string)($execResult['error'] ?? 'Unknown Error'), $subQ);

            $retry_count++;
            if ($retry_count > $max_retries) {
                chatLogger("[CRITICAL-LOOP] {$max_retries}回のリトライすべてで監査拒否またはMySQLエラーが発生しました。ループを強制遮断します。");
                break;
            }

            // AIデバッグ専用反省コンテキストのロード
            $debug_sys_prompt = "高度なMySQL 8.0のエキスパートシステムとして、提示された【失敗したクエリ】と、MySQLサーバーが返した【生の構成エラー文】を深く自己反省（Self-Reflection）してください。\n"
                              . "特に `only_full_group_by` の厳格な制約（SELECT句にある非集計カラムはすべてGROUP BYに記述するか集計関数で囲む）を満たしているか、またはJSONキー抽出構文 `JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.\"キー\"'))` が正確であるか、利用可能なカラム名を誤認でっち上げしていないか確認し、論理的な解決策を構築してください。\n"
                              . "指示スキーマに存在しない物理カラム名は勝手にでっち上げてはいけません。\n\n"
                              . "【絶対ルール】\n"
                              . "出力は必ず、修正・デバッグされた実行可能なSQL文字列1つのみを内包したJSON形式【のみ】を出力してください。挨拶やMarkdown、他の説明プロースは一切排除してください。\n"
                              . "SELECT '説明文' のような実テーブルを読まないダミーSQLは禁止です。必ずFROM句で実在テーブルを参照してください。\n"
                              . '{"sql": "SELECT ..."}';

            // ✨【フェーズ4】自律修復（デバッグ）システムプロンプトの最先頭へ記憶プロンプトをインジェクト
            $debug_sys_prompt = $this->databaseMemoryPrompt . "\n" . $debug_sys_prompt;

            $debug_user_context = "【動的INFORMATION_SCHEMA構成】\n" . $this->schemaInfo . "\n\n"
                                . "【この分析タスクの本来の目的】\n" . $subQ . "\n\n"
                                . "❌ 【前回失敗した不正なSQL】\n" . $generated_sql . "\n\n"
                                . "⚠️ 【MySQLから返された生のエラー文】\n" . ($execResult['error'] ?? 'Unknown Error') . "\n\n"
                                . $repairGuidance;

            // 📢 [超詳細ログ5] 次の周回リトライに向けて、AIの脳みそへ叩き込む「反省用インジェクションデータ」をすべてダンプ
            chatLogger("[OLLAMA-REFLECT-INPUT] (次の一手: 試行 {$retry_count}/{$max_retries}) AIへ送り届ける自己反省材料:\n" . $debug_user_context);

            $sql_model = $this->model;
            chatLogger("[OLLAMA-DEBUG] 自律修正リクエストを送信します...");
            
            $retry_json_str = callOllamaChat($this->ollama_host, $sql_model, $debug_sys_prompt, $debug_user_context, 'json', ["temperature" => 0.0, "top_p" => 0.1, "num_ctx" => 4096]);
            
            // 次の周回へポインタを同期引き渡し
            $sqlJsonStr = $retry_json_str; 
        }

        // 自律修復上限まで試しても安全に完走できなかった場合のフォールバック安全文言の返却
        if (!$execResult['success']) {
            chatLogger("[WARN] 自律修復上限超過：最終SQLの監査遮断またはエラーが解消されませんでした。");
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
            return "⚠️ **{$max_retries}回の自律修復を試みましたが、集計を完了できませんでした。条件を絞り込んで再度ご指示ください。**\n\n最終エラー詳細: " . ($execResult['error'] ?? '不明なエラー。') . "\n\nデバッグ対象クエリ: `{$generated_sql}`";
        }

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // ✨【フェーズ1: 構造的欠陥の完全修復】
        // APIスパイクを抑制する、最適コンテキストサイズ（100件）の自律バッチスライス回路 (Map-Reduce) と State-Saving
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        $limited_results = $execResult['data'] ?? [];
        $batch_size = 100; // APIスパイクを回避する最適なLLMコンテキストサイズ（SqlExecutionEngineの上限と完全シンクロ）
        $batches = array_chunk($limited_results, $batch_size);
        
        if (empty($batches)) {
            $batches = [[]]; // データ0件の場合の空バッチ
        }
        
        $accumulated_insight = ""; // State-Saving用 蓄積知識プロパティ
        
        foreach ($batches as $index => $batch) {
            $batch_num = $index + 1;
            $total_batches = count($batches);
            $batch_json = json_encode($batch, JSON_UNESCAPED_UNICODE);
            
            chatLogger("[DEBUG] バッチスライス巡回中... ({$batch_num}/{$total_batches})");
            sendSSE('status', [
                'step'    => 3, 
                'message' => "📚 [{$stepLabel}] 抽出データを分割精読中 ({$batch_num}/{$total_batches} バッチ目)..."
            ]);

            $sub_analysis_sys = "あなたは卓越したデータアナリストです。\n";
            if (!empty($accumulated_insight)) {
                // ✨【State-Saving】蓄積知識を最先頭にインジェクトして引き継ぐ
                $sub_analysis_sys .= "【これまでのバッチから得られた蓄積知識（State-Saving）】\n" . $accumulated_insight . "\n\n";
            }
            $sub_analysis_sys .= "提示された【実行したクエリ】と【今回のデータバッチ ({$batch_num}/{$total_batches})】から、新たに何が読み取れるか客観的なインサイトを抽出し、これまでの蓄積知識と論理的に統合して「最新の考察」を日本語で簡潔に再構築してください。";

            $sub_analysis_user = "【分析観点】\n{$subQ}\n\n【実行クエリ】\n{$generated_sql}\n\n【今回データバッチ】\n{$batch_json}";
            
            $analysisThought = "";
            $analysisRes = callOllamaChat($this->ollama_host, $this->model, $sub_analysis_sys, $sub_analysis_user, null, ["temperature" => 0.0, "top_p" => 0.1, "num_ctx" => 4096], $analysisThought);
            
            if (!empty($analysisThought)) {
                $analysisRes = "🤔 **[データ考察プロセス (Batch {$batch_num})]**\n<details><summary>分析過程を展開</summary>\n\n" . $analysisThought . "\n\n</details>\n\n---\n\n" . $analysisRes;
            }
            
            // 知識のアップデート（歴史の重ね書き上書き更新）
            $accumulated_insight = $analysisRes;
            
            // 即時コミット（リアルタイム永続化）
            $temp_sub_answer = "【実行SQL】\n" . $fence . "sql\n{$generated_sql}\n" . $fence . "\n\n【段階的理解進捗】\n現在 {$batch_num} / {$total_batches} バッチを精読完了。\n\n【最新の中間考察】\n{$accumulated_insight}";
            
            try {
                $stmtUpdAns = $this->pdo->prepare("UPDATE chat_reasoning_steps SET sub_answer = ? WHERE session_id = ? AND step_number = ?");
                $stmtUpdAns->execute([$temp_sub_answer, $this->reasoningId, $stepCounter]);
            } catch (Exception $e) {
                chatLogger("[ERROR] バッチ中間考察の即時保存に失敗: " . $e->getMessage());
            }
        }
        
        return "【実行SQL】\n" . $fence . "sql\n{$generated_sql}\n" . $fence . "\n\n【段階的分割巡回（全 {$total_batches} バッチ）による最終統合考察】\n{$accumulated_insight}";
    }

    /**
     * 各ステップ考察を統合した最終 Chart.js 準拠レポートの生成（初回ストリーム用）
     */
    private function streamFinalReport(): bool {
        // パース失敗時のインテリジェント・フォールバック計画
        if (empty($this->subQueries) || !isset($this->subQueries[0]['query'])) {
            chatLogger("実行計画のJSONパースに失敗。インテリジェント・フォールバック計画を適用します。");
            
            if (preg_match('/(集計|件数|平均|合計|割合)/u', $this->searchQuery)) {
                $fallbackTable = 'project_csv_rows';
            } elseif (preg_match('/(会話|履歴|チャット|これまでの|まとめ)/u', $this->searchQuery)) {
                $fallbackTable = 'chat_history';
            } else {
                $fallbackTable = 'doc_chunks';
            }

            $this->subQueries = [
                ["query" => "ユーザーの要求に関連する対話ログまたはデータを物理抽出する"]
            ];
        }

        try {
            $stmtInsertStep = $this->pdo->prepare("INSERT INTO chat_reasoning_steps (project_id, session_id, original_question, step_number, sub_query, created_at) VALUES (?, ?, ?, 99, '最終報告書・グラフの統合生成', NOW())");
            $stmtInsertStep->execute([$this->projectId, $this->reasoningId, $this->originalMessage]);
        } catch (Exception $dbEx3) {
            chatLogger("[ERROR] 最終マージステップのINSERTに失敗: " . $dbEx3->getMessage());
        }

        $mergedReasoningText = implode("\n\n", $this->subAnswers);

        // コンテキスト全体の切り詰めセーフガード (4000文字制限)
        $max_pdf_ctx_length = 4000;
        if (mb_strlen($mergedReasoningText) > $max_pdf_ctx_length) {
            $truncated_length = mb_substr($mergedReasoningText, 0, $max_pdf_ctx_length);
            $mergedReasoningText = $truncated_length . "\n\n...[⚠️システム安全セーフガード：Token制限保護のため、以降のデータ考察は省略されました]";
            chatLogger("[CONTEXT-GUARD] Analysis統合コンテキスト合計が限界を超過したため、後半を自動的にカットしました。");
        }

        // ✨【フェーズ4】最終マージプロンプトのベースプロンプト直後に記憶プロンプトをインジェクト
        $system_prompt = PromptManager::getBasePrompt($this->promptKey) . "\n" . $this->databaseMemoryPrompt . "\n" . PromptManager::getCommonInstructions() . "\n" . PromptManager::getDashboardLinkInstruction($this->projectId) . $this->getOutputModeInstructions();
        
        // 生コード内のバッククォート3連記号のハードコードを完全排除
        $fence = str_repeat("\x60", 3);
        
        $system_prompt .= "\n\n// ── UIモジュールから仕入れた関数群を support.php へ正確に再出荷 ──【データ分析・報告指示（Chart.js完全準拠版）】\n"
                       . "あなたはデータアナリストです。提供された「各観点の中間考察結果」を総合して、ユーザーの質問に対する最終的な分析レポートを詳細に作成してください。\n"
                       . "レポート内でデータをグラフ視覚化する際は、Mermaidなどのテキストベースの簡易グラフは一切使用せず、**必ず以下の構造に100%厳格に準拠したグラフ専用JSONデータブロックを出力してください。**\n"
                       . "グラフデータの前後項目は必ず " . $fence . "json:chart と " . $fence . " のコードブロックで正確に囲んでください。\n\n"
                       . "【指定JSON構造ルール】\n"
                       . $fence . "json:chart\n"
                       . "{\n"
                       . "  \"type\": \"bar\" または \"line\" または \"pie\",\n"
                       . "  \"title\": \"グラフの明確なタイトル\",\n"
                       . "  \"labels\": [\"項目1\", \"項目2\", \"項目3\"],\n"
                       . "  \"datasets\": [\n"
                       . "    {\n"
                       . "      \"label\": \"凡例名（例：利用回数、件数など）\",\n"
                       . "      \"data\": [100, 250, 400]\n"
                       . "    }\n"
                       . "  ]\n"
                       . "}\n"
                       . $fence . "\n\n"
                       . "【可視化の選定・出力上の注意】\n"
                       . "- 時系列の変動や推移を示す場合は、必ず type を \"line\" に設定してください。\n"
                       . "- 比率や内訳の分布を示す場合は、必ず type を \"pie\" に設定してください。\n"
                       . "- 単純な件数や数値の比較を示す場合は、必ず type を \"bar\" に設定してください。\n"
                       . "- ※超重要: datasets内のdataプロパティは「数値の1次元配列」です。二重配列にしたり、オブジェクトをネストしたりすることは絶対に厳禁です。これらを破るとフロントエンドのChart.jsが即死します。\n"
                       . "- JSONブロックの直前、または直後には、その集計結果から読み取れる深い技術的インサイトや業務改善施策の解説テキストを日本語で豊富に添えてください。";

        $prompt_user = $this->projectContext . "\n\n"
                     . (!empty($this->historySummaryText) ? "【これまでの会話の文脈】\n{$this->historySummaryText}\n\n" : "")
                     . "【ユーザーの質問】\n{$this->originalMessage}\n\n"
                     . "【各観点の中間考察結果】\n" . $mergedReasoningText;

        chatLogger("[DEBUG] cURLによるOllama最終ストリーミング(api/generate)接続処理を開始します...");
        $get_ch = curl_init("{$this->ollama_host}/api/generate");
        
        // 環境による無名関数内のコンテキスト消失を防ぐため、$thisを$selfに完全にバインド同期
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

                    // 回答本文は内部バッファへ保持し、品質確認後に result イベントでのみ出荷する。
                }
            }

            $current_len = mb_strlen($self->finalResponse);
            if ($current_len - $self->lastLoggedLen >= 100) {
                chatLogger("  [ストリーム進行中] パケット受信数: {$self->packetCounter}回 | 累積文字数: {$current_len}文字");
                $self->lastLoggedLen = $current_len;
            }

            return strlen($data);
        };

        curl_setopt($get_ch, CURLOPT_POST, true);
        curl_setopt($get_ch, CURLOPT_POSTFIELDS, json_encode([
            'model'   => $this->model, 
            'prompt'  => "{$system_prompt}\n\n{$prompt_user}\n\n回答（日本語で詳細に）:",
            'stream'  => true,
            'options' => ['temperature' => 0.0, 'top_p' => 0.1, 'num_ctx' => 8192]
        ]));
        curl_setopt($get_ch, CURLOPT_WRITEFUNCTION, $writeCallback);
        curl_setopt($get_ch, CURLOPT_TIMEOUT, 180);

        chatLogger("[DEBUG] cURL送信・接続実行...");
        $success   = curl_exec($get_ch);
        $curl_error = curl_error($get_ch);
        $http_code  = curl_getinfo($get_ch, CURLINFO_HTTP_CODE);
        curl_close($get_ch);

        chatLogger("[DEBUG] cURL通信完了。HTTPコード: {$http_code} | cURLエラー: " . ($curl_error ?: 'なし'));

        if (!empty($this->ollamaErrorMsg)) {
            chatLogger("CRITICAL: Ollama内部システムエラーを検知しました: {$this->ollamaErrorMsg}");
            sendSSE('error', ['status' => 'error', 'error' => "⚠️ Ollama AIサーバーエラー: {$self->ollamaErrorMsg}"]);
            return false;
        }

        if (!$success) {
            chatLogger("CRITICAL: AIサーバー通信失敗 (cURL Error: {$curl_error})");
            sendSSE('error', ['status' => 'error', 'error' => 'AIサーバーとのストリーム通信に失敗しました: ' . $curl_error]);
            return false;
        }

        if ($http_code !== 200) {
            chatLogger("CRITICAL: AIサーバーがエラーコード {$http_code} を返しました。");
            sendSSE('error', ['status' => 'error', 'error' => "⚠️ AIサーバー通信エラー (HTTPステータス: {$http_code})"]);
            return false;
        }

        $this->finalResponse = trim($this->finalResponse);
        chatLogger("[DEBUG] 生成された最終回答文字数: " . mb_strlen($this->finalResponse) . "文字");

        if (empty($this->finalResponse)) {
            $this->finalResponse = "⚠️ **【システム安全ガードレールによる技術案内】**\n\n大変申し訳ありません。データ分析データのマージ中に、AIサーバーが一時的なメモリ制限（VRAM Token Limit）超を検出し、回答を構成できませんでした。データを小さく絞り込んで再度集計を指示してください。";
            chatLogger("[WARN] 最終レポート回答が空(0文字)で返却されました。セーフガードメッセージに差し替えます。");
        }

        return true;
    }

    /**
     * ✨【フェーズ1: 巻き戻し用】
     * 追加抽出されたデータを統合して、バックグラウンドで最終レポートを再生成する（ストリームなし）
     */
    private function generateFinalReportBackground(): void {
        $mergedReasoningText = implode("\n\n", $this->subAnswers);
        $max_pdf_ctx_length = 4000;
        if (mb_strlen($mergedReasoningText) > $max_pdf_ctx_length) {
            $truncated_length = mb_substr($mergedReasoningText, 0, $max_pdf_ctx_length);
            $mergedReasoningText = $truncated_length . "\n\n...[⚠️システム安全セーフガード：Token制限保護のため、以降のデータ考察は省略されました]";
        }

        require_once __DIR__ . '/../../src/PromptManager.php';
        $system_prompt = PromptManager::getBasePrompt($this->promptKey) . "\n" . $this->databaseMemoryPrompt . "\n" . PromptManager::getCommonInstructions() . "\n" . PromptManager::getDashboardLinkInstruction($this->projectId) . $this->getOutputModeInstructions();
        
        $fence = str_repeat("\x60", 3);
        $system_prompt .= "\n\n// ── UIモジュールから仕入れた関数群を support.php へ正確に再出荷 ──【データ分析・報告指示（Chart.js完全準拠版）】\n"
                       . "あなたはデータアナリストです。提供された「各観点の中間考察結果」を総合して、ユーザーの質問に対する最終的な分析レポートを詳細に作成してください。\n"
                       . "レポート内でデータをグラフ視覚化する際は、Mermaidなどのテキストベースの簡易グラフは一切使用せず、**必ず以下の構造に100%厳格に準拠したグラフ専用JSONデータブロックを出力してください。**\n"
                       . "グラフデータの前後項目は必ず " . $fence . "json:chart と " . $fence . " のコードブロックで正確に囲んでください。\n\n"
                       . "【指定JSON構造ルール】\n"
                       . $fence . "json:chart\n"
                       . "{\n"
                       . "  \"type\": \"bar\" または \"line\" または \"pie\",\n"
                       . "  \"title\": \"グラフの明確なタイトル\",\n"
                       . "  \"labels\": [\"項目1\", \"項目2\", \"項目3\"],\n"
                       . "  \"datasets\": [\n"
                       . "    {\n"
                       . "      \"label\": \"凡例名（例：利用回数、件数など）\",\n"
                       . "      \"data\": [100, 250, 400]\n"
                       . "    }\n"
                       . "  ]\n"
                       . "}\n"
                       . $fence . "\n\n"
                       . "【可視化の選定・出力上の注意】\n"
                       . "- 時系列の変動や推移を示す場合は、必ず type を \"line\" に設定してください。\n"
                       . "- 比率や内訳の分布を示す場合は、必ず type を \"pie\" に設定してください。\n"
                       . "- 単純な件数や数値の比較を示す場合は、必ず type を \"bar\" に設定してください。\n"
                       . "- ※超重要: datasets内のdataプロパティは「数値の1次元配列」です。二重配列にしたり、オブジェクトをネストしたりすることは絶対に厳禁です。これらを破るとフロントエンドのChart.jsが即死します。\n"
                       . "- JSONブロックの直前、または直後には、その集計結果から読み取れる深い技術的インサイトや業務改善施策の解説テキストを日本語で豊富に添えてください。";

        $prompt_user = $this->projectContext . "\n\n"
                     . (!empty($this->historySummaryText) ? "【これまでの会話の文脈】\n{$this->historySummaryText}\n\n" : "")
                     . "【ユーザーの質問】\n{$this->originalMessage}\n\n"
                     . "【各観点の中間考察結果（追加抽出分を含む）】\n" . $mergedReasoningText;

        $thoughtDummy = "";
        $finalRes = callOllamaChat($this->ollama_host, $this->model, $system_prompt, $prompt_user . "\n\n回答（日本語で詳細に）:", null, ['temperature' => 0.0, 'top_p' => 0.1, 'num_ctx' => 8192], $thoughtDummy);

        if (!empty($finalRes)) {
            $this->finalResponse = trim($finalRes);
            chatLogger("[DEBUG] バックグラウンドでの最終レポート再生成が完了しました。");
        }
    }

    /**
     * 履歴永続化処理の一元トランザクション保護 ＆ スコアキー・物理カラム不整合の解消
     */
    private function saveHistoryAndEvaluations(): void {
        chatLogger("[DEBUG] DBトランザクションを開始し、ステップ99・対話ログ・評価スコアを一元コミットします...");
        try {
            $this->pdo->beginTransaction();
            $safeOriginalMessage = $this->normalizeUtf8($this->originalMessage);
            $safeFinalResponse = $this->normalizeUtf8($this->finalResponse);

            $stmtUpdAns = $this->pdo->prepare("UPDATE chat_reasoning_steps SET sub_answer = '完了' WHERE session_id = ? AND step_number = 99");
            $stmtUpdAns->execute([$this->reasoningId]);
            chatLogger("[DEBUG] chat_reasoning_steps の最終ステップ(99)を完了状態に更新しました。");

            $stmtUser = $this->pdo->prepare("INSERT INTO chat_history (project_id, user_id, role, message, created_at) VALUES (?, ?, 'user', ?, NOW())");
            $stmtUser->execute([$this->projectId, $this->user_id, $safeOriginalMessage]);

            $stmtAi = $this->pdo->prepare("INSERT INTO chat_history (project_id, user_id, role, message, created_at) VALUES (?, ?, 'assistant', ?, NOW())");
            $stmtAi->execute([$this->projectId, $this->user_id, $safeFinalResponse]);
            $historyId = $this->pdo->lastInsertId();
            chatLogger("[DEBUG] chat_history 登録成功. ID: {$historyId}");

            $updHist = $this->pdo->prepare("UPDATE chat_reasoning_steps SET chat_history_id = ? WHERE session_id = ?");
            $updHist->execute([$historyId, $this->reasoningId]);
            chatLogger("[DEBUG] chat_reasoning_steps のセッションを chat_history_id: {$historyId} にバインド完了。");

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
                    $this->evalResult['scores']['answer_relevance'] ?? 0, 
                    $this->evalResult['scores']['clarity'] ?? 0,
                    $this->evalResult['total_score'] ?? 0,
                    $this->evalResult['feedback'] ?? '',
                    $this->retryCount ?? 0
                ]);
                chatLogger("[DEBUG] chat_evaluations へ品質審査スコアを正常に登録・同期しました。");
            }

            require_once __DIR__ . '/../../src/FaqAutoRegistrar.php';
            $faqRegistrar = new FaqAutoRegistrar($this->pdo);
            $faqRegistrar->registerIfQualified(
                (int)$this->projectId,
                (int)$historyId,
                (int)$this->user_id,
                $this->originalMessage,
                $this->finalResponse,
                $this->evalResult
            );

            $this->pdo->commit();
            chatLogger("[DEBUG] DBトランザクションコミット成功。すべての書き込みデータ整合性を完全保護しました。");
            $this->createReportDocumentIfRequested((int)$historyId);
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
                chatLogger("[WARN] DBトランザクション内で例外エラーを検知したため、一斉ロールバックを執行しました。");
            }
            chatLogger("[ERROR] 履歴・評価スコアのDB一括保存中に例外発生: " . $e->getMessage());
        }
    }

    private function createReportDocumentIfRequested(int $historyId): void {
        if (!$this->reportMode || $this->projectId === null) {
            return;
        }
        if (($this->evalResult['verdict'] ?? '') === 'reject') {
            chatLogger('[REPORT] 品質評価がrejectのため、報告書PDF生成をスキップしました。chat_history_id=' . $historyId);
            sendSSE('status', [
                'step' => 6,
                'message' => '⚠️ 回答が報告書として成立しない判定のため、PDF生成はスキップしました。'
            ]);
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
                $this->createChatLoggerCallback()
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
     * SSEを用いたクライアントへの最終確定結果のプッシュ送信
     */
    private function sendFinalResult(): void {
        $stmtSteps = $this->pdo->prepare("SELECT step_number, sub_query, sub_answer FROM chat_reasoning_steps WHERE session_id = ? AND step_number < 99 ORDER BY step_number ASC");
        $stmtSteps->execute([$this->reasoningId]);
        $reasoning_steps = $stmtSteps->fetchAll(PDO::FETCH_ASSOC);
        $this->logFinalResponseSnapshot('data_analysis', $this->finalResponse);

        sendSSE('result', [
            'status'          => 'success',
            'response'        => $this->finalResponse,
            'sources'         => [],
            'reasoning_steps' => $reasoning_steps,
            'mode_used'       => 'advanced_analytics_multi_step',
            'detected_page'   => null,
            'hit_count'       => 0,
            'applied_model'   => $this->model,
            'created_at'      => date('Y/m/d H:i'),
            'report_document' => $this->reportDocument
        ]);
        chatLogger("=== Text-to-SQL 自律修復型分析パイプライン完了 ===");
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
}
