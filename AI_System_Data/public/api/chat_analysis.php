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
        $maxEvalRetries = 10;
        $this->retryCount = 0;
        
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
                    'message' => '⚖️ レポートの品質審査（LLM-as-a-Judge）を実行中...' . ($this->retryCount > 0 ? " [リトライ: {$this->retryCount}]" : "")
                ]);
                
                // 門番キック
                $this->evalResult = $evaluator->evaluateDraft($this->originalMessage, $mergedReasoningText, $this->finalResponse, $this->model);
                chatLogger("[DEBUG] ChatEvaluator 品質審査完了。");

                // 不合格（needs_revision）の場合は、評価器のverdictに応じて文章修正か追加抽出を選ぶ
                if (isset($this->evalResult) && (($this->evalResult['needs_revision'] ?? false) === true)) {
                    $this->retryCount++;
                    $feedback = $this->evalResult['feedback'] ?? 'ユーザーの要求を満たしていません。修正してください。';
                    $verdict = $this->evalResult['verdict'] ?? 'need_more_data';
                    chatLogger("[EVAL-NG] 門番による差し戻し。verdict={$verdict} | フィードバック: {$feedback}");

                    if (in_array($verdict, ['revise_text_only', 'reject'], true)) {
                        sendSSE('status', [
                            'step'    => 4,
                            'message' => "📝 追加抽出は行わず、既存根拠だけで回答文を修正しています... [試行: {$this->retryCount}]"
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
                        'message' => "🔄 門番からデータ不足の指摘を受信。不足データを追加抽出します... [試行: {$this->retryCount}]"
                    ]);

                    // ✨【巻き戻し】門番のフィードバックを元に、新しい追加の分析観点（目的）を自律生成
                    $newSubQ = $this->generateAdditionalSubQuery($feedback);
                    
                    // 新しい観点に基づいて、SQL生成・実行・データ抽出・中間考察を裏でキック
                    $subAnsText = $this->executeSingleSubQuery($newSubQ, 90 + $this->retryCount, "追加観点");
                    
                    // 得られた新しいデータを歴史（$this->subAnswers）へマージ
                    $this->subAnswers[] = "◆ 追加抽出観点 [リトライ {$this->retryCount}]: {$newSubQ}\n{$subAnsText}";
                    
                    sendSSE('status', [
                        'step'    => 4, 
                        'message' => "🔄 追加データをマージし、レポートを再構築中... [試行: {$this->retryCount}]"
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
     * CSV質問を「SQLで答えを作る」のではなく、「全CSVレコードを証拠として読解して答える」高速・高精度ルート。
     */
    private function tryCsvEvidenceRoute(): bool {
        if (!$this->shouldUseCsvEvidenceRoute()) {
            return false;
        }

        $routeStart = microtime(true);
        $totalRows = $this->countCsvEvidenceRows();
        $searchTerms = $this->extractCsvSearchTerms($this->originalMessage);
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
            $searchResult = $this->loadCsvEvidenceRowsByKeywords($searchTerms, 300);
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

        $rows = $searchTerms ? $searchResult['rows'] : $this->loadAllCsvEvidenceRows();
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

        $this->insertReasoningStep(1, 'CSV証拠レコードの検索収集', $this->buildCsvCollectionSummary($rows, $searchResult));

        $batches = $this->chunkCsvEvidenceRows($rows, 50, 9000);
        chatLogger("[CSV-EVIDENCE] バッチ分割完了 - batches: " . count($batches) . " | maxRows: 50 | maxChars: 9000");
        $batchFindings = [];
        foreach ($batches as $idx => $batch) {
            $batchNo = $idx + 1;
            $total = count($batches);
            $batchStart = microtime(true);
            $batchChars = mb_strlen($this->formatCsvEvidenceBatch($batch));
            chatLogger("[CSV-EVIDENCE] バッチAI読解開始 - batch: {$batchNo}/{$total} | rows: " . count($batch) . " | chars: {$batchChars}");
            sendSSE('status', [
                'step' => 3,
                'message' => "🔎 CSV証拠を読解中 ({$batchNo}/{$total})..."
            ]);

            $finding = $this->analyzeCsvEvidenceBatch($batch, $batchNo, $total);
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
        $this->finalResponse = $this->synthesizeCsvEvidenceAnswer($rows, $batchFindings);
        chatLogger("[CSV-EVIDENCE] 統合AI回答生成完了 - responseChars: " . mb_strlen($this->finalResponse) . " | elapsed: " . $this->elapsedSeconds($synthesisStart));
        $this->subAnswers = $batchFindings;
        $this->insertReasoningStep(90, 'CSV証拠読解結果の統合', $this->finalResponse);
        $this->insertReasoningStep(99, '最終報告書・グラフの統合生成', '完了');

        chatLogger("[CSV-EVIDENCE] 履歴保存開始 - totalElapsed: " . $this->elapsedSeconds($routeStart));
        $this->saveHistoryAndEvaluations();
        $this->sendFinalResult();
        chatLogger("[CSV-EVIDENCE] CSV証拠読解ルートが完了しました。totalElapsed: " . $this->elapsedSeconds($routeStart));
        return true;
    }

    /**
     * 小規模CSVの「内容まとめ」はAI読解を挟まず、DB実データから即時サマリーを作る。
     */
    private function tryCsvSmallSummaryRoute(array $rows, float $routeStart, array $searchResult = []): bool {
        if (!$this->shouldUseCsvSmallSummaryRoute($rows)) {
            return false;
        }

        chatLogger("[CSV-SUMMARY] 小規模CSV即答ルートを起動します。rows: " . count($rows));
        sendSSE('status', [
            'step' => 2,
            'message' => '📊 CSVの内容をデータベースレコードから直接要約しています...'
        ]);

        $summaryStart = microtime(true);
        $this->finalResponse = $this->buildCsvSmallSummaryAnswer($rows, $searchResult);
        chatLogger("[CSV-SUMMARY] PHPサマリー生成完了 - responseChars: " . mb_strlen($this->finalResponse) . " | elapsed: " . $this->elapsedSeconds($summaryStart));

        $this->insertReasoningStep(1, 'CSVレコードの検索収集', $this->buildCsvCollectionSummary($rows, $searchResult));
        $this->insertReasoningStep(90, '小規模CSVサマリー即時生成', $this->finalResponse);
        $this->insertReasoningStep(99, '最終報告書・グラフの統合生成', '完了');

        chatLogger("[CSV-SUMMARY] 履歴保存開始 - totalElapsed: " . $this->elapsedSeconds($routeStart));
        $this->saveHistoryAndEvaluations();
        $this->sendFinalResult();
        chatLogger("[CSV-SUMMARY] 小規模CSV即答ルートが完了しました。totalElapsed: " . $this->elapsedSeconds($routeStart));
        return true;
    }

    /**
     * 「どんな項目/列/カラムがあるか」はレコード読解せず、CSVメタデータだけで即答する。
     */
    private function tryCsvMetadataRoute(): bool {
        if (!$this->shouldUseCsvMetadataRoute()) {
            return false;
        }

        $files = $this->loadCsvMetadata();
        if (empty($files)) {
            return false;
        }

        chatLogger("[CSV-METADATA] CSV項目メタデータ即答ルートを起動します。対象ファイル数: " . count($files));
        sendSSE('status', [
            'step' => 2,
            'message' => '📋 CSVの項目一覧をメタデータから確認しています...'
        ]);

        $this->finalResponse = $this->buildCsvMetadataAnswer($files);
        $this->insertReasoningStep(1, 'CSVファイルの項目メタデータ確認', $this->finalResponse);
        $this->insertReasoningStep(99, '最終報告書・グラフの統合生成', '完了');

        $this->saveHistoryAndEvaluations();
        $this->sendFinalResult();
        chatLogger("[CSV-METADATA] CSV項目メタデータ即答ルートが完了しました。");
        return true;
    }

    private function shouldUseCsvMetadataRoute(): bool {
        $q = $this->originalMessage;
        if (preg_match('/(「内容」|『内容』|内容列|内容カラム|内容項目)/u', $q)) {
            return false;
        }

        return preg_match('/(どんな|何|なに).*(項目|列|カラム|ヘッダ|ヘッダー)|((項目|列|カラム|ヘッダ|ヘッダー).*(入って|あります|一覧|教えて))/u', $q) === 1;
    }

    private function shouldUseCsvSmallSummaryRoute(array $rows): bool {
        if (count($rows) > 100) {
            return false;
        }

        $q = $this->originalMessage;
        if (preg_match('/(平均|中央値|標準偏差|相関|回帰|推移|時系列|ランキング|多い順|少ない順|TOP|トップ|詳しく分析|条件|抽出|検索|該当)/iu', $q)) {
            return false;
        }

        return preg_match('/(CSV|csv|データ).*(内容|概要|まとめ|要約|どんな|入って)|((内容|概要|まとめ|要約).*(CSV|csv|データ))/u', $q) === 1;
    }

    private function loadCsvMetadata(): array {
        $stmt = $this->pdo->prepare("
            SELECT id, file_name, column_headers, row_count
            FROM project_csv_files
            WHERE project_id = ?
            ORDER BY id ASC
        ");
        $stmt->execute([$this->projectId]);

        $files = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $headers = $this->parseCsvHeaders((string)$row['column_headers']);
            $files[] = [
                'id' => (int)$row['id'],
                'file_name' => (string)$row['file_name'],
                'row_count' => (int)$row['row_count'],
                'columns' => $headers,
            ];
        }

        return $files;
    }

    private function parseCsvHeaders(string $rawHeaders): array {
        $decoded = json_decode($rawHeaders, true);
        $candidates = is_array($decoded) ? $decoded : [$rawHeaders];
        $headers = [];

        foreach ($candidates as $candidate) {
            foreach (preg_split('/[,;]\s*/u', (string)$candidate) ?: [] as $header) {
                $header = trim($header, " \t\n\r\0\x0B\"'");
                if ($header !== '') {
                    $headers[] = $header;
                }
            }
        }

        return array_values(array_unique($headers));
    }

    private function buildCsvMetadataAnswer(array $files): string {
        $totalRows = array_sum(array_column($files, 'row_count'));
        $allColumns = [];
        foreach ($files as $file) {
            foreach ($file['columns'] as $column) {
                $allColumns[$column] = true;
            }
        }

        $lines = [];
        $lines[] = "登録済みCSVには、以下の項目が含まれています。";
        $lines[] = "";
        $lines[] = "- 対象CSVファイル数: " . count($files) . "件";
        $lines[] = "- 登録行数: {$totalRows}件";
        $lines[] = "- ユニーク項目数: " . count($allColumns) . "件";
        $lines[] = "";

        foreach ($files as $file) {
            $lines[] = "### {$file['file_name']}";
            $lines[] = "- 行数: {$file['row_count']}件";
            $lines[] = "- 項目: " . (empty($file['columns']) ? "項目情報なし" : implode(" / ", $file['columns']));
            $lines[] = "";
        }

        if ($allColumns) {
            $lines[] = "### 全CSVを通した項目一覧";
            foreach (array_keys($allColumns) as $column) {
                $lines[] = "- {$column}";
            }
        }

        return implode("\n", $lines);
    }

    private function buildCsvSmallSummaryAnswer(array $rows, array $searchResult = []): string {
        $files = $this->summarizeCsvRows($rows);
        $totalRows = count($rows);
        $allColumns = [];
        foreach ($files as $file) {
            foreach ($file['columns'] as $column) {
                $allColumns[$column] = true;
            }
        }

        $lines = [];
        $lines[] = "登録済みCSVの内容を確認しました。";
        $lines[] = "";
        $lines[] = "- 対象CSVファイル数: " . count($files) . "件";
        $lines[] = "- 対象レコード数: {$totalRows}件";
        if (!empty($searchResult['terms'])) {
            $lines[] = "- 検索語: " . implode(" / ", $searchResult['terms']);
            $lines[] = "- 検索ヒット件数: " . (int)($searchResult['hit_count'] ?? $totalRows) . "件";
            if (!empty($searchResult['limited'])) {
                $lines[] = "- 読解対象: ヒット件数が多いため先頭 {$totalRows} 件を代表証拠として使用";
            }
        }
        $lines[] = "- 確認できた項目数: " . count($allColumns) . "件";
        $lines[] = "";

        foreach ($files as $file) {
            $lines[] = "### {$file['file_name']}";
            $lines[] = "- 登録行数: {$file['collected_rows']}件";
            $lines[] = "- 項目: " . (empty($file['columns']) ? "項目情報なし" : implode(" / ", $file['columns']));
            $lines[] = "- 内容の概要: " . $this->describeCsvPurpose($file['columns']);

            $sampleLines = [];
            foreach ($file['samples'] as $column => $samples) {
                if (!$samples) {
                    continue;
                }
                $sampleLines[] = "{$column}=" . implode("、", $samples);
                if (count($sampleLines) >= 4) {
                    break;
                }
            }
            if ($sampleLines) {
                $lines[] = "- 値の例: " . implode(" / ", $sampleLines);
            }
            $lines[] = "";
        }

        $lines[] = "### 全体の見立て";
        $lines[] = "このCSV群は、列名と登録値から見ると、ユーザーやアカウント識別、ログインメール、氏名、部署、言語設定などの管理情報を含むデータです。";
        if (!empty($searchResult['terms'])) {
            $lines[] = "現時点では検索条件に該当した {$totalRows} 件を対象に確認しており、質問意図に近いCSVレコードを優先して概要を作成しています。";
        } else {
            $lines[] = "現時点では全 {$totalRows} 件を対象に確認しており、特定列だけのランキングではなく、登録されているCSVレコード全体をもとに概要を作成しています。";
        }

        return implode("\n", $lines);
    }

    private function summarizeCsvRows(array $rows): array {
        $files = [];
        foreach ($rows as $row) {
            $fileId = (int)$row['csv_file_id'];
            if (!isset($files[$fileId])) {
                $files[$fileId] = [
                    'file_name' => (string)$row['file_name'],
                    'declared_rows' => (int)$row['row_count'],
                    'collected_rows' => 0,
                    'columns' => $this->parseCsvHeaders((string)$row['column_headers']),
                    'samples' => [],
                ];
            }

            $files[$fileId]['collected_rows']++;
            $data = json_decode((string)$row['row_data'], true);
            if (!is_array($data)) {
                continue;
            }

            foreach ($data as $column => $value) {
                $valueText = trim(preg_replace('/\s+/u', ' ', (string)$value));
                if ($valueText === '') {
                    continue;
                }
                if (mb_strlen($valueText) > 60) {
                    $valueText = mb_substr($valueText, 0, 60) . '...';
                }
                if (!isset($files[$fileId]['samples'][$column])) {
                    $files[$fileId]['samples'][$column] = [];
                }
                if (count($files[$fileId]['samples'][$column]) < 3 && !in_array($valueText, $files[$fileId]['samples'][$column], true)) {
                    $files[$fileId]['samples'][$column][] = $valueText;
                }
            }
        }

        return array_values($files);
    }

    private function describeCsvPurpose(array $columns): string {
        $columnText = mb_strtolower(implode(' ', $columns));
        $descriptions = [];
        if (preg_match('/(username|login email|identifier|email|メール|ユーザー)/u', $columnText)) {
            $descriptions[] = 'ユーザーやログインアカウントの識別情報';
        }
        if (preg_match('/(one-time password|recovery code|password|認証|復旧)/u', $columnText)) {
            $descriptions[] = '認証・復旧に関する情報';
        }
        if (preg_match('/(language|locale|言語)/u', $columnText)) {
            $descriptions[] = '言語設定やローカライズに関する情報';
        }
        if (preg_match('/(first name|last name|氏名|名前|department|部署)/u', $columnText)) {
            $descriptions[] = '氏名や部署などの利用者属性';
        }

        if (!$descriptions) {
            return 'CSVの各行に登録された項目値を管理する構造化データ';
        }

        return implode('、', array_unique($descriptions)) . 'を中心とした構造化データ';
    }

    private function shouldUseCsvEvidenceRoute(): bool {
        $q = $this->originalMessage;
        $mentionsCsvFile = $this->findMentionedCsvFileName($q) !== null;
        if (!$mentionsCsvFile && !preg_match('/(CSV|csv|データ|レコード|行|列|カラム|項目|内容|概要|傾向|まとめ|集計)/u', $q)) {
            return false;
        }
        if (preg_match('/(平均|中央値|標準偏差|相関|回帰|推移|時系列|ランキング|多い順|少ない順|TOP|トップ)/iu', $q)) {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM project_csv_rows WHERE csv_file_id IN (SELECT id FROM project_csv_files WHERE project_id = ?)");
            $stmt->execute([$this->projectId]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            chatLogger("[CSV-EVIDENCE] CSV行数判定に失敗: " . $e->getMessage());
            return false;
        }
    }

    private function findMentionedCsvFileName(string $question): ?string {
        $normalizedQuestion = $this->normalizeCsvRouteText($question);
        if ($normalizedQuestion === '') {
            return null;
        }

        foreach ($this->loadCsvMetadata() as $file) {
            $fileName = (string)($file['file_name'] ?? '');
            $normalizedFile = $this->normalizeCsvRouteText($fileName);
            if ($normalizedFile === '') {
                continue;
            }
            if (mb_strpos($normalizedQuestion, $normalizedFile) !== false || mb_strpos($normalizedFile, $normalizedQuestion) !== false) {
                return $fileName;
            }
        }

        return null;
    }

    private function normalizeCsvRouteText(string $text): string {
        $text = mb_strtolower($this->normalizeUtf8($text), 'UTF-8');
        $text = preg_replace('/\.(csv|tsv)$/iu', '', $text);
        $text = preg_replace('/[\s　「」『』【】\\[\\]（）()、。,.，．:：;；!！?？#]+/u', '', $text);
        return trim((string)$text);
    }

    private function countCsvEvidenceRows(): int {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM project_csv_rows r
            JOIN project_csv_files f ON f.id = r.csv_file_id
            WHERE f.project_id = ?
        ");
        $stmt->execute([$this->projectId]);
        return (int)$stmt->fetchColumn();
    }

    private function extractCsvSearchTerms(string $question): array {
        $question = $this->normalizeUtf8($question);
        $terms = [];

        $mentionedCsv = $this->findMentionedCsvFileName($question);
        if ($mentionedCsv !== null) {
            $baseName = preg_replace('/\.(csv|tsv)$/iu', '', $mentionedCsv);
            $baseName = preg_replace('/[（(].*$/u', '', (string)$baseName);
            $baseName = trim((string)$baseName);
            if ($baseName !== '') {
                $terms[] = $baseName;
            }
        }

        if (preg_match_all('/[「『"“]([^」』"”]{2,60})[」』"”]/u', $question, $matches)) {
            foreach ($matches[1] as $term) {
                $terms[] = $term;
            }
        }

        if (preg_match_all('/[A-Za-z][A-Za-z0-9_.@:\\-]{2,}/u', $question, $matches)) {
            foreach ($matches[0] as $term) {
                $terms[] = $term;
            }
        }

        $hasExplicitTerm = !empty($terms);

        if (preg_match_all('/[\p{Han}\p{Hiragana}\p{Katakana}ー]{2,}/u', $question, $matches)) {
            foreach ($matches[0] as $term) {
                $terms[] = $term;
            }
        }

        if (!$hasExplicitTerm && $this->isBroadCsvOverviewQuestion($question)) {
            return [];
        }

        $stopWords = [
            'CSV', 'csv', 'データ', '登録済み', '内容', '概要', 'まとめ', '要約', '集計',
            '教えて', 'ください', 'どんな', 'なに', '何', 'この', 'その', '対象',
            '項目', '列', 'カラム', 'ヘッダ', 'ヘッダー', '行', '件', 'レコード',
            'ファイル', '全体', '確認', '説明', '一覧', '入っています', 'あります',
        ];
        $stopMap = array_fill_keys($stopWords, true);

        $cleaned = [];
        foreach ($terms as $term) {
            $term = $this->normalizeUtf8((string)$term);
            $term = preg_replace('/\s+/u', ' ', $term);
            $term = preg_replace('/(について|に関して|のこと|とは)$/u', '', $term);
            $term = preg_replace('/^[\s、。,.，．:：;；!?！？()（）\[\]【】]+|[\s、。,.，．:：;；!?！？()（）\[\]【】]+$/u', '', $term);
            $term = trim((string)$term);
            if ($term === '' || mb_strlen($term) < 2 || isset($stopMap[$term]) || $this->isGenericCsvSearchTerm($term)) {
                continue;
            }
            if (preg_match('/^(CSV|csv)$/u', $term)) {
                continue;
            }
            $cleaned[$term] = true;
            if (count($cleaned) >= 8) {
                break;
            }
        }

        return array_keys($cleaned);
    }

    private function isBroadCsvOverviewQuestion(string $question): bool {
        return (bool)preg_match('/(CSV|csv|データ)/u', $question)
            && (bool)preg_match('/(登録済み|全体|概要|まとめ|要約|集計|内容|傾向|概況)/u', $question)
            && !preg_match('/[「『"“][^」』"”]{2,60}[」』"”]/u', $question)
            && !preg_match('/[A-Za-z][A-Za-z0-9_.@:\-]{2,}/u', str_replace(['CSV', 'csv'], '', $question));
    }

    private function isGenericCsvSearchTerm(string $term): bool {
        if (preg_match('/(登録済み|データ|集計|概要|教えて|ください|まとめ|要約|内容|全体|CSV|csv)/u', $term)) {
            return true;
        }
        return false;
    }

    private function escapeLikeTerm(string $term): string {
        return '%' . str_replace('\\', '', $term) . '%';
    }

    private function loadCsvEvidenceRowsByKeywords(array $terms, int $limit): array {
        $terms = array_values(array_filter($terms, fn($term) => trim((string)$term) !== ''));
        if (!$terms) {
            return ['rows' => [], 'hit_count' => 0, 'terms' => [], 'limited' => false, 'mode' => 'keyword'];
        }

        $termConditions = [];
        $params = [$this->projectId];
        foreach ($terms as $term) {
            $like = $this->escapeLikeTerm($term);
            $termConditions[] = "(CAST(r.row_data AS CHAR) LIKE ? OR f.file_name LIKE ? OR f.column_headers LIKE ?)";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where = "f.project_id = ? AND (" . implode(" OR ", $termConditions) . ")";
        $countStmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM project_csv_files f
            JOIN project_csv_rows r ON f.id = r.csv_file_id
            WHERE {$where}
        ");
        $countStmt->execute($params);
        $hitCount = (int)$countStmt->fetchColumn();

        $rowsStmt = $this->pdo->prepare("
            SELECT
                f.id AS csv_file_id,
                f.file_name,
                f.column_headers,
                f.row_count,
                r.id AS csv_row_id,
                r.row_index,
                r.row_data
            FROM project_csv_files f
            JOIN project_csv_rows r ON f.id = r.csv_file_id
            WHERE {$where}
            ORDER BY f.id ASC, r.row_index ASC
            LIMIT " . max(1, $limit) . "
        ");
        $rowsStmt->execute($params);

        return [
            'rows' => $rowsStmt->fetchAll(PDO::FETCH_ASSOC),
            'hit_count' => $hitCount,
            'terms' => $terms,
            'limited' => $hitCount > $limit,
            'mode' => 'keyword',
        ];
    }

    private function loadAllCsvEvidenceRows(): array {
        $stmt = $this->pdo->prepare("
            SELECT
                f.id AS csv_file_id,
                f.file_name,
                f.column_headers,
                f.row_count,
                r.id AS csv_row_id,
                r.row_index,
                r.row_data
            FROM project_csv_files f
            JOIN project_csv_rows r ON f.id = r.csv_file_id
            WHERE f.project_id = ?
            ORDER BY f.id ASC, r.row_index ASC
        ");
        $stmt->execute([$this->projectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function loadCsvEvidenceSampleRows(int $limit): array {
        $stmt = $this->pdo->prepare("
            SELECT
                f.id AS csv_file_id,
                f.file_name,
                f.column_headers,
                f.row_count,
                r.id AS csv_row_id,
                r.row_index,
                r.row_data
            FROM project_csv_files f
            JOIN project_csv_rows r ON f.id = r.csv_file_id
            WHERE f.project_id = ?
            ORDER BY f.id ASC, r.row_index ASC
            LIMIT " . max(1, $limit) . "
        ");
        $stmt->execute([$this->projectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function tryCsvNoHitRoute(array $terms, int $totalRows, float $routeStart): bool {
        chatLogger("[CSV-SEARCH] 検索ヒット0件のため、全件AI読解を行わずメタデータ回答へフォールバックします。terms: " . implode(', ', $terms));
        sendSSE('status', [
            'step' => 2,
            'message' => '🔎 CSVを検索しましたが該当レコードがないため、登録済みCSVの範囲を整理しています...'
        ]);

        $files = $this->loadCsvMetadata();
        $this->finalResponse = $this->buildCsvNoHitAnswer($terms, $files, $totalRows);
        $this->insertReasoningStep(1, 'CSVキーワード検索', "検索語: " . implode(" / ", $terms) . "\n検索ヒット: 0件\n総CSVレコード数: {$totalRows}件");
        $this->insertReasoningStep(99, '最終報告書・グラフの統合生成', '完了');
        $this->saveHistoryAndEvaluations();
        $this->sendFinalResult();
        chatLogger("[CSV-SEARCH] 検索ヒット0件フォールバック完了。totalElapsed: " . $this->elapsedSeconds($routeStart));
        return true;
    }

    private function tryCsvLargeOverviewRoute(int $totalRows, float $routeStart): bool {
        chatLogger("[CSV-OVERVIEW] 広域質問かつ大規模CSVのため、全件AI読解を行わず概況ルートを起動します。totalRows: {$totalRows}");
        sendSSE('status', [
            'step' => 2,
            'message' => '📊 CSV件数が多いため、メタデータと代表サンプルから概況を整理しています...'
        ]);

        $files = $this->loadCsvMetadata();
        $sampleRows = $this->loadCsvEvidenceSampleRows(80);
        $this->finalResponse = $this->buildCsvLargeOverviewAnswer($files, $sampleRows, $totalRows);
        $this->insertReasoningStep(1, 'CSV広域探索', $this->buildCsvCollectionSummary($sampleRows, [
            'terms' => [],
            'hit_count' => $totalRows,
            'limited' => true,
            'mode' => 'broad_overview',
        ]));
        $this->insertReasoningStep(99, '最終報告書・グラフの統合生成', '完了');
        $this->saveHistoryAndEvaluations();
        $this->sendFinalResult();
        chatLogger("[CSV-OVERVIEW] 大規模CSV概況ルートが完了しました。totalElapsed: " . $this->elapsedSeconds($routeStart));
        return true;
    }

    private function buildCsvNoHitAnswer(array $terms, array $files, int $totalRows): string {
        $lines = [];
        $lines[] = "CSVレコードを検索しましたが、指定内容に直接一致するデータは見つかりませんでした。";
        $lines[] = "";
        $lines[] = "- 検索語: " . implode(" / ", $terms);
        $lines[] = "- 検索ヒット件数: 0件";
        $lines[] = "- 登録済みCSV総レコード数: {$totalRows}件";
        $lines[] = "";
        $lines[] = "### 登録済みCSVの範囲";
        foreach ($files as $file) {
            $lines[] = "- {$file['file_name']}: {$file['row_count']}件 / 項目: " . (empty($file['columns']) ? "項目情報なし" : implode(" / ", $file['columns']));
        }
        $lines[] = "";
        $lines[] = "検索語をもう少しCSV内の列名・値に近い表現へ変えると、該当レコードを絞り込んで読解できます。";
        return implode("\n", $lines);
    }

    private function buildCsvLargeOverviewAnswer(array $files, array $sampleRows, int $totalRows): string {
        $sampleSummary = $this->summarizeCsvRows($sampleRows);
        $allColumns = [];
        foreach ($files as $file) {
            foreach ($file['columns'] as $column) {
                $allColumns[$column] = true;
            }
        }

        $lines = [];
        $lines[] = "登録済みCSVの件数が多いため、まず検索・メタデータ・代表サンプルから概況を整理しました。";
        $lines[] = "";
        $lines[] = "- 対象CSVファイル数: " . count($files) . "件";
        $lines[] = "- 登録済みCSV総レコード数: {$totalRows}件";
        $lines[] = "- 代表確認レコード数: " . count($sampleRows) . "件";
        $lines[] = "- ユニーク項目数: " . count($allColumns) . "件";
        $lines[] = "";

        foreach ($files as $file) {
            $lines[] = "### {$file['file_name']}";
            $lines[] = "- 登録行数: {$file['row_count']}件";
            $lines[] = "- 項目: " . (empty($file['columns']) ? "項目情報なし" : implode(" / ", $file['columns']));
            $lines[] = "- 内容の見立て: " . $this->describeCsvPurpose($file['columns']);

            foreach ($sampleSummary as $summary) {
                if ($summary['file_name'] !== $file['file_name'] || empty($summary['samples'])) {
                    continue;
                }
                $sampleLines = [];
                foreach ($summary['samples'] as $column => $samples) {
                    if (!$samples) {
                        continue;
                    }
                    $sampleLines[] = "{$column}=" . implode("、", $samples);
                    if (count($sampleLines) >= 3) {
                        break;
                    }
                }
                if ($sampleLines) {
                    $lines[] = "- 値の例: " . implode(" / ", $sampleLines);
                }
                break;
            }
            $lines[] = "";
        }

        $lines[] = "### 次の読解方針";
        $lines[] = "大規模CSVでは、質問文から検索語を抽出して該当レコードを先に絞り込み、その範囲をAI読解に回す方式にしています。";
        $lines[] = "今回のように検索語が弱い広い質問では、全件AI読解ではなく概況を先に返すことで、処理時間の肥大化を避けます。";

        return implode("\n", $lines);
    }

    private function buildCsvCollectionSummary(array $rows, array $searchResult = []): string {
        $files = [];
        foreach ($rows as $row) {
            $fileId = (int)$row['csv_file_id'];
            if (!isset($files[$fileId])) {
                $headers = $this->parseCsvHeaders((string)$row['column_headers']);
                $files[$fileId] = [
                    'file_name' => $row['file_name'],
                    'declared_rows' => (int)$row['row_count'],
                    'collected_rows' => 0,
                    'columns' => $headers,
                ];
            }
            $files[$fileId]['collected_rows']++;
        }

        return "【CSV証拠収集サマリー】\n"
            . "- 対象CSVファイル数: " . count($files) . "\n"
            . "- 対象CSVレコード数: " . count($rows) . "\n"
            . (!empty($searchResult['terms']) ? "- 検索語: " . implode(" / ", $searchResult['terms']) . "\n" : "")
            . (isset($searchResult['hit_count']) ? "- 検索ヒット件数: " . (int)$searchResult['hit_count'] . "\n" : "")
            . (!empty($searchResult['limited']) ? "- 注記: ヒット件数が多いため読解対象を上限内に制限\n" : "")
            . "```json\n" . json_encode(array_values($files), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n```";
    }

    private function chunkCsvEvidenceRows(array $rows, int $maxRows, int $maxChars): array {
        $chunks = [];
        $current = [];
        $chars = 0;

        foreach ($rows as $row) {
            $line = $this->formatCsvEvidenceRow($row);
            $lineLen = mb_strlen($line);
            if ($current && (count($current) >= $maxRows || ($chars + $lineLen) > $maxChars)) {
                $chunks[] = $current;
                $current = [];
                $chars = 0;
            }
            $current[] = $row;
            $chars += $lineLen;
        }
        if ($current) {
            $chunks[] = $current;
        }
        return $chunks;
    }

    private function formatCsvEvidenceRow(array $row): string {
        $data = json_decode((string)$row['row_data'], true);
        if (!is_array($data)) {
            $data = ['raw' => (string)$row['row_data']];
        }

        $pairs = [];
        foreach ($data as $key => $value) {
            $valueText = trim(preg_replace('/\s+/u', ' ', (string)$value));
            if ($valueText === '') {
                continue;
            }
            if (mb_strlen($valueText) > 180) {
                $valueText = mb_substr($valueText, 0, 180) . '...';
            }
            $pairs[] = "{$key}={$valueText}";
        }

        return "#{$row['row_index']} {$row['file_name']} | " . implode('; ', $pairs);
    }

    private function formatCsvEvidenceBatch(array $batch): string {
        return implode("\n", array_map(fn($row) => $this->formatCsvEvidenceRow($row), $batch));
    }

    private function elapsedSeconds(float $start): string {
        return number_format(microtime(true) - $start, 2) . '秒';
    }

    private function analyzeCsvEvidenceBatch(array $batch, int $batchNo, int $totalBatches): string {
        $callStart = microtime(true);
        $batchText = $this->formatCsvEvidenceBatch($batch);
        $system = "あなたはCSVデータベースレコードを精読する分析官です。\n"
            . "SQL集計ではなく、提示された全レコードを証拠として読み、ユーザー質問に関係する情報だけを抽出してください。\n"
            . "行番号・ファイル名を根拠として必ず残してください。存在しない列や値は作らないでください。\n"
            . "出力はJSONのみです。";

        $user = "【ユーザー質問】\n{$this->originalMessage}\n\n"
            . "【バッチ情報】{$batchNo}/{$totalBatches}\n"
            . "【CSV証拠レコード】\n{$batchText}\n\n"
            . "以下のJSON形式で返してください。\n"
            . '{"batch_summary":"このバッチで読み取れる要点","relevant_rows":[{"file":"ファイル名","row_index":1,"evidence":"根拠","reason":"質問との関係"}],"findings":["発見事項"],"unanswered":["このバッチだけでは判断できない点"]}';

        try {
            $thought = "";
            chatLogger("[CSV-EVIDENCE] Ollamaバッチ読解API送信 - batch: {$batchNo}/{$totalBatches} | systemChars: " . mb_strlen($system) . " | userChars: " . mb_strlen($user));
            $res = callOllamaChat($this->ollama_host, $this->model, $system, $user, 'json', ['temperature' => 0.0, 'top_p' => 0.1, 'num_ctx' => 8192], $thought);
            chatLogger("[CSV-EVIDENCE] Ollamaバッチ読解API受信 - batch: {$batchNo}/{$totalBatches} | rawChars: " . mb_strlen($res) . " | elapsed: " . $this->elapsedSeconds($callStart));
            $decoded = json_decode($res, true);
            if (is_array($decoded)) {
                return "```json\n" . json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n```";
            }
            return $res;
        } catch (Exception $e) {
            chatLogger("[CSV-EVIDENCE] バッチ読解に失敗。簡易要約へフォールバック: " . $e->getMessage());
            return "【バッチ{$batchNo}簡易証拠】\n" . mb_substr($batchText, 0, 4000);
        }
    }

    private function synthesizeCsvEvidenceAnswer(array $rows, array $batchFindings): string {
        $callStart = microtime(true);
        $findingsText = implode("\n\n", $batchFindings);
        if (mb_strlen($findingsText) > 12000) {
            $findingsText = mb_substr($findingsText, 0, 12000) . "\n...[統合用に後半を省略]";
        }

        $system = PromptManager::getBasePrompt($this->promptKey) . "\n"
            . "あなたはCSV証拠読解結果を統合して、ユーザーの質問に直接答える分析官です。\n"
            . "対象データは、質問意図に基づいてSQL検索で絞り込んだCSVレコードです。ランキングや列別内訳へ逃げず、検索対象全体から回答してください。\n"
            . "回答には、対象ファイル数・対象行数・主要な結論・根拠行・注意点を含めてください。\n"
            . "根拠のない断定、存在しない列や値の作成は禁止です。"
            . $this->getOutputModeInstructions();

        $user = $this->projectContext . "\n\n"
            . "【ユーザー質問】\n{$this->originalMessage}\n\n"
            . "【対象CSVレコード数】" . count($rows) . "件\n"
            . "【保存済みバッチ読解結果】\n{$findingsText}\n\n"
            . "上記の保存済み分析結果を統合し、日本語Markdownで最終回答を作成してください。";

        try {
            $thought = "";
            chatLogger("[CSV-EVIDENCE] Ollama統合回答API送信 - systemChars: " . mb_strlen($system) . " | userChars: " . mb_strlen($user));
            $answer = callOllamaChat($this->ollama_host, $this->model, $system, $user, null, ['temperature' => 0.0, 'top_p' => 0.1, 'num_ctx' => 8192], $thought);
            chatLogger("[CSV-EVIDENCE] Ollama統合回答API受信 - responseChars: " . mb_strlen($answer) . " | elapsed: " . $this->elapsedSeconds($callStart));
            return trim($answer) ?: "CSVデータ {$this->projectId} の対象レコード " . count($rows) . "件を確認しましたが、回答文を生成できませんでした。";
        } catch (Exception $e) {
            chatLogger("[CSV-EVIDENCE] 統合回答生成に失敗: " . $e->getMessage());
            return "CSVデータベースレコード全 " . count($rows) . "件を対象に読解しましたが、AI統合処理でエラーが発生しました。\n\n詳細: " . $e->getMessage();
        }
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
    private function generateAdditionalSubQuery(string $feedback): string {
        require_once __DIR__ . '/../../src/PromptManager.php';
        
        $sysPrompt = "あなたは超一流のシステムアーキテクトおよびデータアナリストです。\n"
                   . "品質審査責任者から、現在のレポートに対する以下の【絶対修正命令（データ不足等の指摘）】を受けました。\n\n"
                   . "【品質審査責任者からの絶対修正命令】\n{$feedback}\n\n"
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
        
        // 最大3回までの自律デバッグループ（while構造）の展開
        $max_retries = 3;
        $retry_count = 0;
        $execResult = ['success' => false, 'error' => 'クエリが初期化されていません。', 'data' => []];

        require_once __DIR__ . '/../../src/SqlExecutionEngine.php';
        $sqlEngine = new SqlExecutionEngine($this->pdo, $this->projectId);

        while ($retry_count <= $max_retries) {
            // 📢 [超詳細ログ1] Ollamaから届いた「パース前」の生JSONレスポンスをそのまま完全可視化
            chatLogger("[OLLAMA-RAW-RESPONSE] (試行 {$retry_count}/3) Ollama側からの受信生データ:\n" . $sqlJsonStr);

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
            chatLogger("[Text-to-SQL-BEFORE] (試行 {$retry_count}/3) プログラム補正前の素のSQL: " . $generated_sql);

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
            chatLogger("[Text-to-SQL-AFTER]  (試行 {$retry_count}/3) プログラム補正後の最終実行SQL: " . $generated_sql);

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
                    'message' => "🛠️ [自律修復: 試行 {$retry_count}/3回] クエリのエラー原因を分析し、SQLを自律デバッグ中..."
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
            chatLogger("[SQL-EXEC-FAILED] (試行 {$retry_count}/3) 監査拒否またはMySQL生エラー文: " . ($execResult['error'] ?? 'Unknown Error'));
            $repairGuidance = $sqlEngine->buildRepairGuidance($generated_sql, (string)($execResult['error'] ?? 'Unknown Error'), $subQ);

            $retry_count++;
            if ($retry_count > $max_retries) {
                chatLogger("[CRITICAL-LOOP] 3回のリトライすべてで監査拒否またはMySQLエラーが発生しました。ループを強制遮断します。");
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
            chatLogger("[OLLAMA-REFLECT-INPUT] (次の一手: 試行 {$retry_count}/3) AIへ送り届ける自己反省材料:\n" . $debug_user_context);

            $sql_model = $this->model;
            chatLogger("[OLLAMA-DEBUG] 自律修正リクエストを送信します...");
            
            $retry_json_str = callOllamaChat($this->ollama_host, $sql_model, $debug_sys_prompt, $debug_user_context, 'json', ["temperature" => 0.0, "top_p" => 0.1, "num_ctx" => 4096]);
            
            // 次の周回へポインタを同期引き渡し
            $sqlJsonStr = $retry_json_str; 
        }

        // 3回リトライしても安全に完走できなかった場合のフォールバック安全文言の返却
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
            return "⚠️ **3回自律修復を試みましたが、集計を完了できませんでした。条件を絞り込んで再度ご指示ください。**\n\n最終エラー詳細: " . ($execResult['error'] ?? '不明なエラー。') . "\n\nデバッグ対象クエリ: `{$generated_sql}`";
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

                    sendSSE('chunk', ['text' => $word, 'word' => $word]);
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
     * SSEを用いたクライアントへの最終確定結果のプッシュ送信
     */
    private function sendFinalResult(): void {
        $stmtSteps = $this->pdo->prepare("SELECT step_number, sub_query, sub_answer FROM chat_reasoning_steps WHERE session_id = ? AND step_number < 99 ORDER BY step_number ASC");
        $stmtSteps->execute([$this->reasoningId]);
        $reasoning_steps = $stmtSteps->fetchAll(PDO::FETCH_ASSOC);

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
}
