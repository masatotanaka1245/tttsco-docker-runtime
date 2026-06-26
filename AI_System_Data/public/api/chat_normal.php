<?php
/**
 * chat_normal.php - 一問一答型・通常RAGストリーミング処理ルート
 * (chat.php から安全に呼び出されるコントローラーファイルです)
 *
 * ★[品質評価（LLM-as-a-Judge）一元トランザクション保護 ＆ 13引数インターフェース拡張版]
 */

require_once __DIR__ . '/../../src/ProjectContextMemory.php';
require_once __DIR__ . '/../../src/ProjectMemoryAutoUpdater.php';
require_once __DIR__ . '/../../src/ChatModelRolePayload.php';
require_once __DIR__ . '/../../src/ChatThreadManager.php';
require_once __DIR__ . '/../../src/ReportAnswerPolisher.php';
require_once __DIR__ . '/../../src/CsvExportGenerator.php';
require_once __DIR__ . '/../../src/LightweightFinalAnswerGuard.php';

function runNormalStreamingRoute($pdo, $ollama_host, $projectId, $originalMessage, $routeDetail, $searchQuery, $model, $subModel, $embeddingModel, $promptKey, $projectContext, $historySummaryText, $vectorSearch, $engine, $user_id, $role, $threadId = null, bool $reportMode = false, bool $diagramMode = false, bool $csvMode = false, array $conversationIntentProfile = []) {
    $processor = new NormalStreamingRouteProcessor(
        $pdo, $ollama_host, $projectId, $originalMessage, $routeDetail, $searchQuery,
        $model, $subModel, $embeddingModel, $promptKey, $projectContext, $historySummaryText,
        $vectorSearch, $engine, $user_id, $role, $threadId, $reportMode, $diagramMode, $csvMode, $conversationIntentProfile
    );
    $processor->execute();
}

class NormalStreamingRouteProcessor {
    private $pdo;
    private $ollama_host;
    private $projectId;
    private $originalMessage;
    private $routeDetail;
    private $searchQuery;
    private $model;
    private $subModel;
    private $embeddingModel;
    private $promptKey;
    private $projectContext;
    private $historySummaryText;
    private $vectorSearch;
    private $engine;
    private $user_id;
    private $role;
    private $threadId;
    private $reportMode = false;
    private $diagramMode = false;
    private $reportDocument = null;
    private $csvMode = false;
    private $csvExport = null;
    private $conversationIntentProfile = [];

    private $targetPage = null;
    private $referAllMode = false;
    private $contextText = "";
    private $sourceDocs = [];
    private $fullResponse = "";
    private $evalResult = null;
    private $retryCount = 0;
    private $projectOperatingMemoryPrompt = "";

    private $tokenCount = 0;
    private $lastLoggedLength = 0;
    private $buffer = "";
    private $ollamaErrorMsg = "";
    private $routeStartedAt = 0.0;

    public function __construct($pdo, $ollama_host, $projectId, $originalMessage, $routeDetail, $searchQuery, $model, $subModel, $embeddingModel, $promptKey, $projectContext, $historySummaryText, $vectorSearch, $engine, $user_id, $role, $threadId = null, bool $reportMode = false, bool $diagramMode = false, bool $csvMode = false, array $conversationIntentProfile = []) {
        $this->pdo                = $pdo;
        $this->ollama_host        = $ollama_host;
        $this->projectId          = $projectId;
        $this->originalMessage    = $this->normalizeUtf8((string)$originalMessage);
        $this->routeDetail        = (string)$routeDetail;
        $this->searchQuery        = $this->normalizeUtf8((string)$searchQuery);
        $this->model              = $model;
        $this->subModel           = $subModel;
        $this->embeddingModel     = $embeddingModel;
        $this->promptKey          = $promptKey;
        $this->projectContext     = $this->normalizeUtf8((string)$projectContext);
        $this->historySummaryText = $this->normalizeUtf8((string)$historySummaryText);
        $this->vectorSearch       = $vectorSearch;
        $this->engine             = $engine;
        $this->user_id            = $user_id;
        $this->role               = $role;
        $this->threadId           = $threadId;
        $this->reportMode         = $reportMode;
        $this->diagramMode        = $diagramMode;
        $this->csvMode            = $csvMode;
        $this->conversationIntentProfile = $conversationIntentProfile;
        $this->routeStartedAt     = microtime(true);
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

        chatLogger("[PROMPT-BUDGET] route=normal | phase={$phase} | num_ctx={$numCtx} | totalChars={$totalChars} | " . implode(' | ', $segments));
    }

    private function logPhaseTiming(string $phase, array $fields = []): void
    {
        $elapsedMs = (int)round((microtime(true) - $this->routeStartedAt) * 1000);
        $parts = [
            '[NORMAL-PHASE-TIMING]',
            "phase={$phase}",
            'project_id=' . (($this->projectId === null || (int)$this->projectId <= 0) ? 'NULL' : (string)(int)$this->projectId),
            'thread_id=' . ($this->threadId === null ? 'NULL' : (string)$this->threadId),
            'route_detail=' . ($this->routeDetail !== '' ? $this->routeDetail : 'none'),
        ];

        $requestType = trim((string)($this->conversationIntentProfile['request_type'] ?? ''));
        if ($requestType !== '') {
            $parts[] = "request_type={$requestType}";
        }

        $userIntent = trim((string)($this->conversationIntentProfile['user_intent'] ?? ''));
        if ($userIntent !== '') {
            $parts[] = "intent={$userIntent}";
        }

        foreach ($fields as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $parts[] = "{$key}={$value}";
        }

        $parts[] = "elapsed_ms={$elapsedMs}";
        chatLogger(implode(' | ', $parts));
    }

    public function execute(): void {
        chatLogger(">>> [通常ルート] 通常ストリーミングルートを起動します");
        $this->logPhaseTiming('normal_start');
        sendSSE('status', ['message' => '🔍 関連ドキュメントのベクトル類似度検索を実行しています...']);
        $this->parseQuery();
        $this->buildRagContext();
        sendSSE('status', ['message' => '⚙️ 関連資料の抽出が完了しました。回答の生成処理（推論）を開始します...']);
        if (!$this->streamOllamaGeneration()) {
            return;
        }

        $this->runQualityEvaluationIfNeeded();
        $this->applyReportModeFinalPolishIfNeeded();
        $this->applyFallbackFinalAnswerGuardIfNeeded();
        // 履歴永続化処理の一元トランザクション保護近代化へ委譲
        $this->saveHistoryAndEvaluations();
        $this->sendFinalResult();
    }

    private function runQualityEvaluationIfNeeded(): void {
        if ($this->isProjectMemoryConsultationRoute()) {
            $this->loadProjectOperatingMemoryPrompt();
            $guard = new LightweightFinalAnswerGuard($this->ollama_host);
            $guardContext = trim($this->contextText) !== ''
                ? $this->contextText
                : (trim($this->projectOperatingMemoryPrompt) !== '' ? $this->projectOperatingMemoryPrompt : '案件運用メモ優先の相談ルート');

            $guardResult = $guard->review(
                $this->originalMessage,
                $guardContext,
                $this->fullResponse,
                $this->model,
                'normal_project_memory_consultation',
                [
                    'use_llm_judge' => false,
                    'allow_llm_rewrite' => false,
                ]
            );

            $this->fullResponse = (string)($guardResult['response'] ?? $this->fullResponse);
            $this->evalResult = $guardResult['eval_result'] ?? null;
            if (is_array($this->evalResult)) {
                $this->evalResult['allow_memory_refresh'] =
                    (($this->evalResult['verdict'] ?? '') === 'pass')
                    && ((int)($this->evalResult['total_score'] ?? 0) >= 92);
            }

            chatLogger(
                "[JUDGE-NORMAL-SKIP] consultation 専用軽量ルートのため通常RAG品質評価を rule-first ガードへ置換しました。"
                . " reason=project_memory_consultation"
                . " | responseChars=" . mb_strlen($this->fullResponse)
                . " | contextChars=" . mb_strlen($guardContext)
                . " | sources=" . count($this->sourceDocs)
            );
            $this->logPhaseTiming('judge_skipped', [
                'reason' => 'project_memory_consultation',
                'response_chars' => mb_strlen($this->fullResponse),
                'context_chars' => mb_strlen($guardContext),
                'sources' => count($this->sourceDocs),
            ]);
            return;
        }

        require_once __DIR__ . '/../../src/ChatEvaluationPolicy.php';
        $policy = ChatEvaluationPolicy::shouldEvaluateNormalRag(
            $this->originalMessage,
            $this->fullResponse,
            $this->contextText,
            count($this->sourceDocs),
            $this->reportMode,
            $this->diagramMode
        );

        if (($policy['evaluate'] ?? false) !== true) {
            chatLogger("[JUDGE-NORMAL-SKIP] 通常RAG品質評価をスキップしました。reason={$policy['reason']} | responseChars=" . mb_strlen($this->fullResponse) . " | contextChars=" . mb_strlen($this->contextText) . " | sources=" . count($this->sourceDocs));
            $this->logPhaseTiming('judge_skipped', [
                'reason' => (string)($policy['reason'] ?? 'unknown'),
                'response_chars' => mb_strlen($this->fullResponse),
                'context_chars' => mb_strlen($this->contextText),
                'sources' => count($this->sourceDocs),
            ]);
            return;
        }

        try {
            require_once __DIR__ . '/../../src/ChatEvaluator.php';
            require_once __DIR__ . '/../../src/EvaluationRecoveryCoordinator.php';
            $evaluator = new ChatEvaluator($this->ollama_host);
            $recoveryCoordinator = new EvaluationRecoveryCoordinator($evaluator);

            sendSSE('status', ['message' => '⚖️ 回答の品質確認を実行中...']);
            $contextForEval = trim((string)$this->contextText);
            if ($contextForEval === '') {
                $contextForEval = "通常RAG検索（中間ステップなし）";
            }
            chatLogger("[JUDGE-NORMAL-START] 通常RAG品質評価を開始します。reason={$policy['reason']} | responseChars=" . mb_strlen($this->fullResponse) . " | contextChars=" . mb_strlen($contextForEval) . " | sources=" . count($this->sourceDocs));
            $this->logPhaseTiming('judge_start', [
                'reason' => (string)($policy['reason'] ?? 'unknown'),
                'response_chars' => mb_strlen($this->fullResponse),
                'context_chars' => mb_strlen($contextForEval),
                'sources' => count($this->sourceDocs),
                'timeout' => 90,
            ]);
            $this->evalResult = $evaluator->evaluateDraft($this->originalMessage, $contextForEval, $this->fullResponse, $this->model);
            $evaluationMode = (string)($this->evalResult['evaluation_mode'] ?? 'unknown');
            $evaluationSource = (string)($this->evalResult['evaluation_source'] ?? 'unknown');
            $verdict = (string)($this->evalResult['verdict'] ?? 'unknown');
            $score = (int)($this->evalResult['total_score'] ?? 0);
            $relevance = (int)($this->evalResult['scores']['answer_relevance'] ?? 0);
            $faithfulness = (int)($this->evalResult['scores']['faithfulness'] ?? 0);
            chatLogger("[EVAL-" . strtoupper($evaluationMode) . "] source={$evaluationSource} | verdict={$verdict} | score={$score} | relevance={$relevance} | faithfulness={$faithfulness}");
            $this->logPhaseTiming('judge_end', [
                'result' => $verdict,
                'evaluation_mode' => $evaluationMode,
                'evaluation_source' => $evaluationSource,
                'score' => $score,
            ]);

            if (($this->evalResult['needs_revision'] ?? false) === true) {
                $verdict = $this->evalResult['verdict'] ?? 'revise_text_only';
                $recoveryResult = $recoveryCoordinator->resolve(
                    $this->originalMessage,
                    $contextForEval,
                    $this->fullResponse,
                    $this->model,
                    $this->evalResult,
                    [
                        'clarify_feedback_suffix' => '[ASK-USER-CLARIFICATION] 差し戻し内容をユーザー向け確認質問へ変換し、追加情報の取得を優先しました。',
                        'rewrite_feedback_suffix' => '[TEXT-ONLY-REWRITE] 通常RAGルートでは追加検索を行わず、既存根拠のみで最終回答を修正しました。',
                    ]
                );

                if (($recoveryResult['action'] ?? 'none') === 'clarify') {
                    $this->fullResponse = (string)($recoveryResult['response'] ?? $this->fullResponse);
                    $this->evalResult = $recoveryResult['eval_result'] ?? $this->evalResult;
                    chatLogger("[JUDGE-NORMAL-CLARIFY] verdict={$verdict} のため回答再生成へ進まず、確認質問を返しました。");
                    chatLogger("[JUDGE-NORMAL-EVAL] 通常RAG回答品質管理審査結果マトリクス:\n" . print_r($this->evalResult, true));
                    chatLogger("[DEBUG] ChatEvaluator による通常RAG最終回答品質審査が正常開通しました。");
                    return;
                }

                if (($recoveryResult['action'] ?? 'none') === 'rewrite') {
                    sendSSE('status', ['message' => '📝 品質確認の指摘を反映し、既存根拠だけで回答を整えています...']);
                    $this->fullResponse = (string)($recoveryResult['response'] ?? $this->fullResponse);
                    $this->evalResult = $recoveryResult['eval_result'] ?? $this->evalResult;
                    chatLogger("[JUDGE-NORMAL-REWRITE] verdict={$verdict} のため通常RAG回答を文章修正しました。");
                }
            }

            chatLogger("[JUDGE-NORMAL-EVAL] 通常RAG回答品質管理審査結果マトリクス:\n" . print_r($this->evalResult, true));
            chatLogger("[DEBUG] ChatEvaluator による通常RAG最終回答品質審査が正常開通しました。");
        } catch (Exception $evalEx) {
            $this->logPhaseTiming('judge_exception', [
                'error_type' => get_class($evalEx),
                'message' => mb_substr($evalEx->getMessage(), 0, 160),
            ]);
            chatLogger("品質評価エージェントキック中に例外検出(スキップ保護): " . $evalEx->getMessage());
        }
    }

    private function applyReportModeFinalPolishIfNeeded(): void
    {
        if (!$this->reportMode) {
            return;
        }

        $currentDraft = trim((string)$this->fullResponse);
        if ($currentDraft === '') {
            return;
        }

        chatLogger("[REPORT-POLISH] 通常ルートの報告書向け最終整形を実行します。");
        sendSSE('status', ['message' => '📄 報告書として読みやすい構成へ最終整形しています...']);

        $referenceContext = trim((string)$this->contextText);
        if ($referenceContext === '') {
            $referenceContext = "利用可能な参考資料は限定的です。現在の回答ドラフトを基に、断定を避けて整形してください。";
        }

        $polisher = new ReportAnswerPolisher(
            $this->ollama_host,
            $this->model,
            fn(string $prompt): string => $this->buildSystemPrompt() . "\n" . $prompt,
            function (string $message): void {
                chatLogger($message);
            }
        );

        $this->fullResponse = $polisher->polish(
            $this->originalMessage,
            $referenceContext,
            $currentDraft
        );
    }

    private function parseQuery(): void {
        if (preg_match('/([0-9]+)\s*ページ/u', $this->searchQuery, $matches)) {
            $this->targetPage = (int)$matches[1];
        } elseif (preg_match('/(?:p(?:age)?\.?\s*)\s*([0-9]+)/i', $this->searchQuery, $matches)) {
            $this->targetPage = (int)$matches[1];
        }
        if ($this->targetPage === null && preg_match('/(全体|すべての資料|全資料|すべてのページ|全ページ|要約|まとめ|比較|一覧|鳥瞰|全体マップ|総合)/u', $this->searchQuery)) {
            $this->referAllMode = true;
        }
    }

    private function buildRagContext(): void {
        if ($this->projectId === null) {
            return;
        }
        if ($this->shouldSkipDocumentRagForCodeImprovement()) {
            $this->contextText = "【コード改善実装計画モード】\nこの質問は、実装依頼・コード改善依頼として扱います。PDF/資料ベクトル検索結果は今回の主根拠にしません。案件運用メモ、直近会話、回答優先ガイドを優先してください。";
            chatLogger(
                "[RAG-CONTEXT-POLICY] policy=skip_vector_rag"
                . " | reason=code_improvement_implementation_plan"
                . " | user_intent=" . (string)($this->conversationIntentProfile['user_intent'] ?? 'unknown')
                . " | expected_response=" . (string)($this->conversationIntentProfile['expected_response'] ?? 'unknown')
                . " | route_detail=" . ($this->routeDetail !== '' ? $this->routeDetail : 'normal_rag')
            );
            return;
        }
        if ($this->isProjectMemoryConsultationRoute()) {
            $this->contextText = "【案件運用メモ優先モード】\nこの質問は、案件運用メモと直近会話の文脈を優先して検討する相談モードとして扱います。PDF/CSVのベクトル検索結果は今回の主根拠にしません。";
            chatLogger("[PROJECT-MEMORY] normal consultation route のため資料RAGをスキップします。route_detail={$this->routeDetail}");
            return;
        }
        try {
            $qEmb = $this->engine->embed(mb_substr($this->searchQuery, 0, 500));
            $all_hits = [];

            if ($this->referAllMode) {
                $stmtSummary = $this->pdo->prepare("SELECT c.id, c.doc_id, d.title, c.chunk_text, c.page_number, c.image_description FROM doc_chunks c JOIN documents d ON c.doc_id = d.id WHERE d.project_id = ? AND c.page_number = 0");
                $stmtSummary->execute([$this->projectId]);
                $summaries = $stmtSummary->fetchAll(PDO::FETCH_ASSOC);
                $summaryByDocId = [];
                foreach ($summaries as $sum) {
                    $summaryByDocId[(int)($sum['doc_id'] ?? 0)] = $sum;
                }

                $semanticHits = $this->vectorSearch->search($qEmb, $this->projectId, 12, null, $this->searchQuery);
                $detailHits = [];
                $referencedSummaryDocIds = [];
                foreach ($semanticHits as $sHit) {
                    if ($sHit['page_number'] == 0 || $sHit['page_number'] === '0') {
                        continue;
                    }
                    $detailHits[] = $sHit;
                    $docId = (int)($sHit['document_id'] ?? 0);
                    if ($docId > 0 && !in_array($docId, $referencedSummaryDocIds, true)) {
                        $referencedSummaryDocIds[] = $docId;
                    }
                }

                $referencedSummaryDocIds = array_slice($referencedSummaryDocIds, 0, 3);
                foreach ($referencedSummaryDocIds as $docId) {
                    if (!isset($summaryByDocId[$docId])) {
                        continue;
                    }
                    $sum = $summaryByDocId[$docId];
                    $all_hits[] = [
                        'document_id' => $sum['doc_id'],
                        'title' => $sum['title'],
                        'content' => $sum['chunk_text'],
                        'page_number' => 0,
                        'image_description' => $sum['image_description'],
                        'score' => 0.92,
                    ];
                }

                if (empty($detailHits)) {
                    foreach (array_slice($summaries, 0, 2) as $sum) {
                        $all_hits[] = [
                            'document_id' => $sum['doc_id'],
                            'title' => $sum['title'],
                            'content' => $sum['chunk_text'],
                            'page_number' => 0,
                            'image_description' => $sum['image_description'],
                            'score' => 0.90,
                        ];
                    }
                }

                foreach ($detailHits as $detailHit) {
                    $all_hits[] = $detailHit;
                }
            } else {
                $all_hits = $this->vectorSearch->search($qEmb, $this->projectId, 9999, $this->targetPage, $this->searchQuery);
            }

            $pdf_hits = [];
            $material_hits = [];
            $csv_hits_by_doc = [];

            $preferAdviceContext = $this->isProposalOrWritingAdviceQuestion();
            $preferMaterialContext = $this->isMaterialDraftingQuestion();
            if ($preferAdviceContext) {
                chatLogger("[RAG-CONTEXT-FILTER] 提案・文章改善相談としてノイズ抑制フィルタを有効化しました。");
            }
            if ($preferMaterialContext) {
                chatLogger("[MATERIAL-RAG] 資料メモ優先モードを有効化しました。Markdown成果品を先に探索します。");
            }

            foreach ($all_hits as $hit) {
                $imageDescription = (string)($hit['image_description'] ?? '');
                $is_csv = mb_strpos($imageDescription, 'CSVデータ行レコード') === 0;
                $sourceType = (string)($hit['source_type'] ?? '');
                if ($is_csv) {
                    if ($hit['score'] >= 0.50) {
                        $csv_hits_by_doc[$hit['document_id']][] = $hit;
                    }
                } else {
                    if ($preferMaterialContext && $sourceType === 'material_note') {
                        if ($hit['score'] >= 0.22) {
                            $material_hits[] = $hit;
                        }
                        continue;
                    }
                    if ($hit['score'] >= 0.35 && !$this->shouldSkipPdfHitForAdvice($hit, $preferAdviceContext)) {
                        $pdf_hits[] = $hit;
                    }
                }
            }

            if ($preferMaterialContext) {
                $pdf_hits = $this->mergeMaterialFirstHits($material_hits, $pdf_hits, 6);
            } elseif ($this->referAllMode) {
                $pdf_hits = $this->rebalanceReferAllPdfHits($pdf_hits, 6);
            } else {
                $pdf_hits = array_slice($pdf_hits, 0, 6);
            }
            chatLogger("トリアージ完了。資料/PDF適合チャンク: " . count($pdf_hits) . "件 | 資料メモ候補: " . count($material_hits) . "件 | 適合CSVファイル(score>=0.50): " . count($csv_hits_by_doc) . "件");

            $suppressedImageDescriptionCount = 0;
            foreach ($pdf_hits as $hit) {
                $pNum = $hit['page_number'];
                $sourceType = (string)($hit['source_type'] ?? '');
                if ($sourceType === 'material_note') {
                    $label = "【資料メモ: {$hit['title']}】";
                } else {
                    $label = ($pNum == 0) ? "【資料全体の構成・要約（目次情報）】" : "【参考資料: {$hit['title']} P.{$pNum}】";
                }
                $this->contextText .= "{$label}\n[本文テキスト]:\n{$hit['content']}\n";
                $imageDescriptionForContext = $this->prepareImageDescriptionForContext((string)($hit['image_description'] ?? ''));
                if ($imageDescriptionForContext !== '') {
                    $this->contextText .= "[このページに含まれる画像/図表の説明]:\n{$imageDescriptionForContext}\n";
                } elseif (!empty($hit['image_description'])) {
                    $suppressedImageDescriptionCount++;
                }
                $this->contextText .= "\n";
                $this->sourceDocs[] = [
                    "title" => $hit['title'],
                    "page" => $pNum,
                    "doc_id" => $hit['document_id'],
                    "source_type" => $sourceType !== '' ? $sourceType : 'document',
                ];
            }

            if ($suppressedImageDescriptionCount > 0) {
                chatLogger("[RAG-CONTEXT-FILTER] 汎用的すぎる image_description を {$suppressedImageDescriptionCount} 件、回答コンテキストから除外しました。");
            }

            foreach ($csv_hits_by_doc as $doc_id => $c_hits) {
                if (empty($c_hits)) continue;
                $fileName = str_replace('[CSVデータ] ', '', $c_hits[0]['title']);
                chatLogger("  CSVバルクアグリゲーション実行: {$fileName} (合致数: " . count($c_hits) . "行)");
                if ($preferAdviceContext) {
                    $tableMarkdown = summarizeCsvChunksForAdvice($c_hits, $fileName);
                    $this->contextText .= "【構造化データの補助情報: {$fileName}】\n{$tableMarkdown}\n\n";
                } else {
                    $tableMarkdown = aggregateCsvChunksToMarkdown($c_hits, $fileName);
                    $this->contextText .= "【構造化データテーブル: {$fileName}】\n{$tableMarkdown}\n\n";
                }
                $this->sourceDocs[] = [
                    "title" => "[CSVデータ] " . $fileName,
                    "page" => 1,
                    "doc_id" => $doc_id,
                    "source_type" => 'csv',
                ];
            }

            $max_pdf_ctx_length = 4000;
            if (mb_strlen($this->contextText) > $max_pdf_ctx_length) {
                $truncated_pdf_length = mb_strlen($this->contextText) - $max_pdf_ctx_length;
                $this->contextText = mb_substr($this->contextText, 0, $max_pdf_ctx_length) . "\n\n...[⚠️システム安全セーフガード：Token制限保護のため、以降のデータは省略されました。解決には詳細な指定を添えてください]";
                chatLogger("[CONTEXT-GUARD] コンテキスト合計文字数が限界を超過したため、後半の {$truncated_pdf_length} 文字を自動的に切り詰めました。");
            }

            // ⭕ 構築とセーフガードが完全に完了した真実の文字数を完璧にダンプ！
            chatLogger("[RAG-CONTEXT-TOTAL] 最終構築されたコンテキスト総文字数: " . mb_strlen($this->contextText) . "文字");

        } catch (Exception $e) {
            chatLogger("RAG検索例外: " . $e->getMessage());
            $this->contextText = "";
        }
    }

    private function buildSystemPrompt(): string {
        $this->loadProjectOperatingMemoryPrompt();
        $projectScopeInstruction = PromptManager::getProjectScopeInstruction(
            $this->projectId !== null ? (int)$this->projectId : null
        );
        $system_prompt = PromptManager::getBasePrompt($this->promptKey) . "\n"
                       . $this->projectOperatingMemoryPrompt . "\n"
                       . $projectScopeInstruction . "\n"
                       . PromptManager::getCommonInstructions() . "\n"
                       . PromptManager::getDashboardLinkInstruction($this->projectId ?? 0);
        chatLogger(
            "[PROJECT-SCOPE] current_project_id=" . (($this->projectId === null || (int)$this->projectId <= 0) ? 'NULL' : (string)(int)$this->projectId)
            . " | thread_id=" . ($this->threadId === null ? 'NULL' : (string)$this->threadId)
            . " | source=prompt_scope_instruction"
            . " | mode=" . (($this->projectId === null || (int)$this->projectId <= 0) ? 'global' : 'project')
            . " | ok=1"
        );
        if ($this->targetPage !== null) {
            $system_prompt .= "\n【超重要】ユーザーは明示的に「{$this->targetPage}ページ」を指定して質問しています。必ず提供された参考資料のうち「P.{$this->targetPage}」と書かれたブロックの情報のみを根拠として回答してください。関係のない他のページの情報を混ぜて答えてはなりません。";
        } elseif ($this->referAllMode) {
            $system_prompt .= "\n【超重要・マクロ分析ルール】ユーザーは資料全体の横断的な要約・総括を求めています。\n1. 各資料 of 全体マップ（目次情報）を骨格にしてください。\n2. 各ページの詳細情報（P.1〜）を肉付けして全体を解説してください。\n3. すべての登録済み資料に平等に言及し大局的な知見を提示してください。";
        }
        if ($this->diagramMode) {
            $system_prompt .= "\n【図解モード】説明の理解に役立つ場合のみ、Mermaidコードブロック（```mermaid）またはChart.js用JSONコードブロック（```json:chart）を1つまで添えてください。図表が不要な場合は文章のみで構いません。";
        }
        if ($this->reportMode) {
            $system_prompt .= "\n【報告書モード】回答は後続処理でPDF報告書化されます。結論、分析対象、根拠、留意点、推奨アクション、出典の順に、報告書として読みやすい見出し構成で作成してください。";
        }
        if ($this->csvMode) {
            $system_prompt .= "\n【CSV化モード】集計結果や一覧をCSVとして保存できるよう、表形式が有効な場合は少なくとも1つのMarkdown表を含めてください。列名と行データを省略せず、Markdown表として完結させてください。";
        }
        if ($this->isProjectMemoryConsultationRoute()) {
            $system_prompt .= "\n【案件運用メモ優先の相談モード】この質問では、案件運用メモと現在スレッドの文脈を優先し、短く実務的に支援してください。"
                            . "\n- 根拠のない固有名詞や案件情報を補わないこと"
                            . "\n- PDFやCSVの本文要約へ逸れないこと"
                            . "\n- 回答は 400文字程度までを目安にすること"
                            . "\n- 案件名や表現の相談では、まず強調したい観点を2〜3個に整理する"
                            . "\n- 直前のAI回答を引きずりすぎず、現在の質問にだけ素直に答える";
        }
        if ($this->isMaterialDraftingQuestion()) {
            $system_prompt .= "\n【資料メモ優先モード】この質問は、Markdown資料メモを育てる相談として扱ってください。"
                            . "\n- 既存の資料メモが見つかった場合は、PDF全体要約より先にその内容を確認すること"
                            . "\n- 回答は、資料メモへどの差分を入れるか分かる形で具体化すること"
                            . "\n- PDFは補助根拠として使ってよいが、資料メモの章立て・追記・修正文案を主役にすること";
        }
        return $system_prompt;
    }

    private function loadProjectOperatingMemoryPrompt(): void
    {
        if ((int)$this->projectId <= 0 || $this->projectOperatingMemoryPrompt !== '') {
            return;
        }

        require_once __DIR__ . '/../../src/PromptManager.php';
        $projectMemoryDocs = ProjectContextMemory::load($this->pdo, (int)$this->projectId);
        $this->projectOperatingMemoryPrompt = PromptManager::getProjectOperatingMemoryInstruction($projectMemoryDocs);
        chatLogger("[PROJECT-MEMORY] route=normal | loaded=" . (empty(ProjectContextMemory::loadedTypes($projectMemoryDocs)) ? 'none' : implode(',', ProjectContextMemory::loadedTypes($projectMemoryDocs))) . " | chars=" . ProjectContextMemory::totalChars($projectMemoryDocs));
    }

    private function streamOllamaGeneration(): bool {
        if ($this->isProjectMemoryConsultationRoute()) {
            $fastPathResponse = $this->buildConsultationFastPathResponse();
            if ($fastPathResponse !== '') {
                $this->fullResponse = $fastPathResponse;
                chatLogger("[PROJECT-MEMORY] consultation fast path を適用しました。responseChars=" . mb_strlen($this->fullResponse));
                $this->logPhaseTiming('generation_fast_path', [
                    'response_chars' => mb_strlen($this->fullResponse),
                ]);
                return true;
            }
        }

        $this->logPhaseTiming('prompt_build_start');
        $system_prompt = $this->buildSystemPrompt();
        $dialogue_context_prompt = $this->buildDialogueContextPrompt();
        $project_context_prompt = $this->buildProjectContextPrompt();
        $priority_header_prompt = $this->buildPriorityHeaderPrompt();
        $prompt_user = $priority_header_prompt . $project_context_prompt . $dialogue_context_prompt;
        $reference_context_prompt = '';
        if ($this->isProjectMemoryConsultationRoute()) {
            $reference_context_prompt = $this->contextText . "\n";
        } else {
            $reference_context_prompt = "【参考資料情報】\n" . (!empty($this->contextText) ? $this->contextText : "（指定された資料データは見つかりませんでした）") . "\n";
        }
        $prompt_user .= $reference_context_prompt;
        $question_prompt = "質問：" . $this->originalMessage;
        $prompt_user .= $question_prompt;

        $this->logPromptBudget('final_generate', [
            'system' => $system_prompt,
            'priority' => $priority_header_prompt,
            'project' => $project_context_prompt,
            'history' => $dialogue_context_prompt,
            'context' => $reference_context_prompt,
            'question' => $question_prompt,
            'projectMemory' => $this->projectOperatingMemoryPrompt,
        ], 8192);
        $this->logPhaseTiming('prompt_built', [
            'total_chars' => mb_strlen($prompt_user),
            'system_chars' => mb_strlen($system_prompt),
            'project_memory_chars' => mb_strlen($this->projectOperatingMemoryPrompt),
            'history_chars' => mb_strlen($dialogue_context_prompt),
            'context_chars' => mb_strlen($reference_context_prompt),
            'source_docs' => count($this->sourceDocs),
        ]);

        chatLogger("Ollama接続開始。モデル: {$this->model} | プロンプト総文字数: " . mb_strlen($prompt_user) . "文字");
        $this->logPhaseTiming('generation_start', [
            'model' => $this->model,
            'timeout' => 300,
            'prompt_chars' => mb_strlen($prompt_user),
        ]);

        // 📢 【推論プロンプト送信フェーズ】合体プロンプトの完全ダンプ
        chatLogger("[OLLAMA-RAW-PROMPT] Ollamaへ最終投入される生の合体ユーザープロンプト:\n" . $prompt_user);

        $ch = curl_init("{$this->ollama_host}/api/generate");

        // cURL内部スレッド実行時のオブジェクトコンテキスト消失を防ぐため、インスタンス参照をローカル変数へキャプチャ
        $me = $this;

        // 🔄 通常ストリーミングルートの cURL コールバック関数（$me コンテキスト完全維持・ネジ締め版）
        $writeCallback = function($ch, $data) use ($me) {
            $me->buffer .= $data;
            $lines = explode("\n", $me->buffer);
            $me->buffer = array_pop($lines);

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;

                $json = json_decode($line, true);
                if ($json) {
                    if (isset($json['error'])) {
                        $me->ollamaErrorMsg = $json['error'];
                        return 0;
                    }
                    $word = $json['response'] ?? $json['message']['content'] ?? '';
                    $me->fullResponse .= $word;
                    $me->tokenCount++;
                    
                    // 回答本文は内部バッファへ保持し、品質確認後に result イベントでのみ出荷する。
                }
            }
            $current_len = mb_strlen($me->fullResponse);
            if ($current_len - $me->lastLoggedLength >= 50) {
                chatLogger("  [推論進行中] 累積文字数: {$current_len}文字 | 受信チャンク数: {$me->tokenCount}回");
                $me->lastLoggedLength = $current_len;
            }
            return strlen($data);
        };

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'model' => $this->model,
            'prompt' => "<system>{$system_prompt}</system>\n\n{$prompt_user}\n\n回答（日本語で詳細に）:",
            'stream' => true,
            // 🛠️【通常RAGストリーミング】限界引き締めオプション配列書き換え ＆ 8192文脈拡張（超決定論・拡張仕様化）
            'options' => [
                'temperature' => 0.0,
                'top_p' => 0.1,
                'num_ctx' => 8192
            ]
        ]));
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, $writeCallback);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        
        $success    = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!empty($this->ollamaErrorMsg)) {
            $this->logPhaseTiming('generation_error', [
                'error_type' => 'ollama_internal_error',
                'message' => mb_substr($this->ollamaErrorMsg, 0, 160),
            ]);
            chatLogger("CRITICAL: Ollama内部システムエラーを検知しました: {$this->ollamaErrorMsg}");
            sendSSE('error', ['status' => 'error', 'error' => "⚠️ Ollama AIサーバーエラー: {$this->ollamaErrorMsg}"]);
            return false;
        }
        if (!$success) {
            $this->logPhaseTiming('generation_error', [
                'error_type' => 'curl_exec_failed',
                'message' => mb_substr($curl_error, 0, 160),
                'http_code' => $http_code,
            ]);
            chatLogger("CRITICAL: Ollama推論ストリーム通信失敗 (cURL Error: {$curl_error})");
            sendSSE('error', ['status' => 'error', 'error' => 'AIサーバーとのストリーミング通信に失敗しました: ' . $curl_error]);
            return false;
        }
        if ($http_code !== 200) {
            $this->logPhaseTiming('generation_error', [
                'error_type' => 'http_status',
                'http_code' => $http_code,
            ]);
            chatLogger("CRITICAL: AIサーバーがエラーコード {$http_code} を返しました。");
            sendSSE('error', ['status' => 'error', 'error' => "⚠️ AIサーバー通信エラー (HTTPステータス: {$http_code})"]);
            return false;
        }
        $this->fullResponse = trim($this->fullResponse);
        if (empty($this->fullResponse)) {
            $this->fullResponse = "⚠️ **[システム安全ガードレールによる技術案内]**\n\n大変申し訳ありません。検索に合致したデータ量が多すぎるか、AIサーバーが一時的に極めて高負荷なため、処理能力限界（Token Limit）を超過し回答を構成できませんでした。";
        }
        $this->logPhaseTiming('generation_end', [
            'response_chars' => mb_strlen($this->fullResponse),
            'token_chunks' => $this->tokenCount,
            'http_code' => $http_code,
        ]);
        return true;
    }

    /**
     * 【修正要件2】履歴永続化処理の一元トランザクション保護 ＆ スコアキー・物理カラム不整合の解消
     */
    private function saveHistoryAndEvaluations(): void {
        if ($this->projectId === null) {
            return;
        }
        $this->logPhaseTiming('save_start');
        sendSSE('status', ['message' => '💾 回答生成が完了しました。会話履歴と評価結果を保存しています...']);
        chatLogger("[DEBUG] DBトランザクションを開始し、対話ログ・評価スコアを一元コミットします...");
        try {
            // 全書き込み処理を完璧な単一トランザクションスコープへ完全格納
            $this->pdo->beginTransaction();

            // 1. ユーザー履歴保存
            $stmtUser = $this->pdo->prepare("INSERT INTO chat_history (project_id, thread_id, user_id, role, message, created_at) VALUES (?, ?, ?, 'user', ?, NOW())");
            $stmtUser->execute([$this->projectId, $this->threadId, $this->user_id, $this->normalizeUtf8($this->originalMessage)]);

            // 2. AI履歴保存
            $stmtAi = $this->pdo->prepare("INSERT INTO chat_history (project_id, thread_id, user_id, role, message, created_at) VALUES (?, ?, ?, 'assistant', ?, NOW())");
            $stmtAi->execute([$this->projectId, $this->threadId, $this->user_id, $this->normalizeUtf8($this->fullResponse)]);
            $historyId = $this->pdo->lastInsertId();
            chatLogger("[DEBUG] chat_history 登録成功。ID: {$historyId}");

            ChatThreadManager::updateTitleFromMessage(
                $this->pdo,
                (int)$this->projectId,
                $this->threadId !== null ? (int)$this->threadId : null,
                $this->originalMessage
            );

            // 3. 品質評価スコア（LLM-as-a-Judge）の保存（マッピング不整合を100%解消してバインド）
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
                    $this->evalResult['scores']['answer_relevance'] ?? 0, // ★JSONキー「answer_relevance」から等価抽出して :relevance_score 側へバインド
                    $this->evalResult['scores']['clarity'] ?? 0,
                    $this->evalResult['total_score'] ?? 0,
                    $this->normalizeUtf8((string)($this->evalResult['feedback'] ?? '')),
                    $this->retryCount ?? 0
                ]);
                chatLogger("[DEBUG] chat_evaluations へ通常RAG品質審査スコアを正常に登録・同期しました。");
            }

            require_once __DIR__ . '/../../src/FaqAutoRegistrar.php';
            sendSSE('status', ['message' => '📚 高評価回答のFAQ自動登録条件を確認しています...']);
            $faqRegistrar = new FaqAutoRegistrar($this->pdo);
            $faqRegistrar->registerIfQualified(
                $this->projectId,
                (int)$historyId,
                (int)$this->user_id,
                $this->originalMessage,
                $this->fullResponse,
                $this->evalResult
            );

            // すべてのインサートが完全に成功したため一括コミットを執行
            $this->pdo->commit();
            chatLogger("[DEBUG] DBトランザクションコミット成功。通常RAGのデータ整合性を完全保護しました。");
            $fallbackGuardReason = $this->getDownstreamFallbackGuardReason();
            if ($this->shouldSkipProjectMemoryRefresh()) {
                chatLogger("[PROJECT-MEMORY-AUTO] skipped=route_guard | route_detail=" . (string)$this->routeDetail . " | operation=" . $this->inferPriorityOperation() . " | reason=consultation_read_only");
                ProjectMemoryAutoUpdater::logAutoMemoryDecision(
                    fn(string $message) => chatLogger($message),
                    [
                        'project_id' => $this->projectId,
                        'thread_id' => $this->threadId,
                        'route_detail' => (string)$this->routeDetail,
                        'conversation_intent_profile' => $this->conversationIntentProfile,
                    ],
                    [
                        'action' => 'skip',
                        'guard' => 'route_guard',
                        'reason' => 'consultation_read_only',
                    ]
                );
            } elseif ($fallbackGuardReason !== null) {
                chatLogger("[EVAL-FALLBACK-GUARD] blocked=project_memory_refresh | route=normal | reason={$fallbackGuardReason}");
                chatLogger("[PROJECT-MEMORY-AUTO] skipped=quality_guard | thread_id=" . ($this->threadId === null ? 'NULL' : (string)$this->threadId));
                ProjectMemoryAutoUpdater::logAutoMemoryDecision(
                    fn(string $message) => chatLogger($message),
                    [
                        'project_id' => $this->projectId,
                        'thread_id' => $this->threadId,
                        'route_detail' => (string)$this->routeDetail,
                        'conversation_intent_profile' => $this->conversationIntentProfile,
                    ],
                    [
                        'action' => 'skip',
                        'guard' => 'quality_guard',
                        'reason' => (string)$fallbackGuardReason,
                    ],
                    $this->evalResult
                );
            } elseif (ProjectMemoryAutoUpdater::shouldRefreshFromEvaluation($this->evalResult, $this->fullResponse, fn(string $message) => chatLogger($message), [
                'project_id' => $this->projectId,
                'thread_id' => $this->threadId,
                'route_detail' => (string)$this->routeDetail,
                'conversation_intent_profile' => $this->conversationIntentProfile,
            ])) {
                ProjectMemoryAutoUpdater::refresh(
                    $this->pdo,
                    (int)$this->projectId,
                    $this->threadId !== null ? (int)$this->threadId : null,
                    (int)$this->user_id,
                    fn(string $message) => chatLogger($message),
                    [
                        'conversation_intent_profile' => $this->conversationIntentProfile,
                        'route_detail' => (string)$this->routeDetail,
                    ]
                );
            } else {
                chatLogger("[PROJECT-MEMORY-AUTO] skipped=quality_guard | thread_id=" . ($this->threadId === null ? 'NULL' : (string)$this->threadId));
            }
            $this->createReportDocumentIfRequested((int)$historyId);
            $this->createCsvExportIfRequested((int)$historyId);
            $this->logPhaseTiming('save_end', [
                'history_id' => $historyId,
                'has_eval' => is_array($this->evalResult) ? 'yes' : 'no',
            ]);
        } catch (Exception $e) {
            // 障害発生時は一斉ロールバックを執行
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
                chatLogger("[WARN] DBトランザクション内で例外エラーを検知したため、一斉ロールバックを執行しました。");
            }
            $this->logPhaseTiming('save_exception', [
                'error_type' => get_class($e),
                'message' => mb_substr($e->getMessage(), 0, 160),
            ]);
            chatLogger("DB履歴・評価保存例外: " . $e->getMessage());
        }
    }

    private function createReportDocumentIfRequested(int $historyId): void {
        if (!$this->reportMode || $this->projectId === null) {
            return;
        }
        $fallbackGuardReason = $this->getDownstreamFallbackGuardReason();
        if ($fallbackGuardReason !== null) {
            chatLogger("[EVAL-FALLBACK-GUARD] blocked=report_generation | route=normal | reason={$fallbackGuardReason}");
            sendSSE('status', ['message' => '⚠️ 評価が不安定なため、報告書PDF生成はスキップしました。']);
            return;
        }
        if (($this->evalResult['verdict'] ?? '') === 'reject') {
            chatLogger('[REPORT] 品質評価がrejectのため、報告書PDF生成をスキップしました。chat_history_id=' . $historyId);
            sendSSE('status', ['message' => '⚠️ 回答が報告書として成立しない判定のため、PDF生成はスキップしました。']);
            return;
        }
        try {
            require_once __DIR__ . '/../../src/ReportGenerator.php';
            sendSSE('status', ['message' => '📄 報告書モード: HTML/CSS報告書をPDF化し、資料PDFへ登録しています...']);
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
                $this->fullResponse,
                $this->evalResult,
                null
            );
            sendSSE('status', ['message' => '✅ 報告書PDFをPDFタブへ登録し、検索対象化しました。']);
        } catch (Throwable $e) {
            chatLogger('[REPORT] 報告書PDF登録に失敗: ' . $e->getMessage());
            sendSSE('status', ['message' => '⚠️ 報告書PDFの登録に失敗しました。管理者ログを確認してください。']);
        }
    }

    private function createCsvExportIfRequested(int $historyId): void {
        if (!$this->csvMode || $this->projectId === null) {
            return;
        }
        $fallbackGuardReason = $this->getDownstreamFallbackGuardReason();
        if ($fallbackGuardReason !== null) {
            chatLogger("[EVAL-FALLBACK-GUARD] blocked=csv_generation | route=normal | reason={$fallbackGuardReason}");
            return;
        }
        if (($this->evalResult['verdict'] ?? '') === 'reject') {
            chatLogger('[CSV-EXPORT] 品質評価がrejectのため、生成CSV登録をスキップしました。chat_history_id=' . $historyId);
            return;
        }

        try {
            sendSSE('status', ['message' => '🧾 回答内の表を生成CSVとして登録しています...']);
            $generator = new CsvExportGenerator(
                $this->pdo,
                function ($msg) { chatLogger($msg); }
            );
            $this->csvExport = $generator->createFromChat(
                (int)$this->projectId,
                $historyId,
                (int)$this->user_id,
                $this->originalMessage,
                $this->fullResponse
            );

            if ($this->csvExport !== null) {
                sendSSE('status', ['message' => '✅ 生成CSVをCSVタブへ登録しました。']);
            } else {
                sendSSE('status', ['message' => 'ℹ️ CSV化モードは有効でしたが、保存できる表は見つかりませんでした。']);
            }
        } catch (Throwable $e) {
            chatLogger('[CSV-EXPORT] 生成CSV登録に失敗: ' . $e->getMessage());
            sendSSE('status', ['message' => '⚠️ 生成CSVの登録に失敗しました。管理者ログを確認してください。']);
        }
    }

    private function applyFallbackFinalAnswerGuardIfNeeded(): void
    {
        $fallbackGuardReason = $this->getDownstreamFallbackGuardReason();
        if ($fallbackGuardReason === null) {
            return;
        }

        if (is_array($this->evalResult) && (($this->evalResult['needs_revision'] ?? false) === true)) {
            return;
        }

        $unsafeReason = $this->detectUnsafeFallbackFinalAnswerReason($this->fullResponse);
        if ($unsafeReason === null) {
            return;
        }

        chatLogger("[EVAL-FALLBACK-GUARD] blocked=final_answer | route=normal | reason={$fallbackGuardReason} | unsafe={$unsafeReason}");
        $this->fullResponse = $this->buildFallbackClarificationResponse();
    }

    private function sendFinalResult(): void {
        $this->logFinalResponseSnapshot('normal_rag', $this->fullResponse);
        $this->logPhaseTiming('send_final', [
            'response_chars' => mb_strlen($this->fullResponse),
            'source_docs' => count($this->sourceDocs),
        ]);
        sendSSE('result', [
            'status'          => 'success',
            'response'        => $this->fullResponse,
            'sources'         => $this->sourceDocs,
            'route_detail'    => $this->routeDetail,
            'mode_used'       => $this->promptKey,
            'detected_page'   => $this->targetPage,
            'hit_count'       => count($this->sourceDocs),
            'reasoning_steps' => [],
            'applied_model'   => $this->model,
            'model_roles'     => ChatModelRolePayload::build($this->model, $this->subModel, $this->embeddingModel, 'main'),
            'created_at'      => date('Y/m/d H:i'),
            'report_document' => $this->reportDocument,
            'csv_export'      => $this->csvExport
        ]);
        $this->logPhaseTiming('stream_close');
        chatLogger("=== 通常RAGストリーミングパイプライン完了 ===");
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

    private function getDownstreamFallbackGuardReason(): ?string
    {
        if (!is_array($this->evalResult) || $this->evalResult === []) {
            return 'missing_eval';
        }

        if (($this->evalResult['judge_fallback'] ?? false) === true) {
            return 'judge_fallback';
        }

        $evaluationMode = trim((string)($this->evalResult['evaluation_mode'] ?? ''));
        if ($evaluationMode === 'fallback') {
            return 'evaluation_mode_fallback';
        }

        $evaluationSource = trim((string)($this->evalResult['evaluation_source'] ?? ''));
        if ($evaluationSource === 'judge_fallback') {
            return 'judge_fallback';
        }

        $feedback = trim((string)($this->evalResult['feedback'] ?? ''));
        if (
            $feedback !== ''
            && preg_match('/(フェイルセーフ|タイムアウト|初期ドラフトを採用|評価プロセス.*エラー)/u', $feedback) === 1
        ) {
            return 'fallback_feedback';
        }

        return null;
    }

    private function detectUnsafeFallbackFinalAnswerReason(string $response): ?string
    {
        $normalized = $this->normalizeFallbackGuardText($response);
        if ($normalized === '') {
            return 'empty_response';
        }

        if ($this->looksLikeProvisionalOnlyAnswer($normalized)) {
            return 'provisional_only';
        }

        if ($this->looksLikeInsufficientOnlyAnswer($normalized)) {
            return 'insufficient_only';
        }

        if ($this->looksLikeOperationOnlyFallbackAnswer($normalized)) {
            return 'operation_only';
        }

        if ($this->looksLikeProcessingNoticeOnlyAnswer($normalized)) {
            return 'processing_notice_only';
        }

        if ($this->hasUnclosedStructuredBlock($response)) {
            return 'unclosed_structure';
        }

        if ($this->looksLikeIncompleteFallbackAnswer($response, $normalized)) {
            return 'incomplete_response';
        }

        return null;
    }

    private function buildFallbackClarificationResponse(): string
    {
        return '回答を確定するための評価が完了しなかったため、このまま断定せず確認させてください。対象ファイル・列・期間・目的など、分かる条件をもう少し指定してください。';
    }

    private function normalizeFallbackGuardText(string $text): string
    {
        $normalized = trim($this->normalizeUtf8($text));
        $normalized = preg_replace('/\s+/u', ' ', $normalized);
        return $normalized !== null ? trim($normalized) : trim($text);
    }

    private function looksLikeProvisionalOnlyAnswer(string $text): bool
    {
        return preg_match('/^(以下のように修正します|以下の通り修正します|以下のように対応します|確認しました|対応しました)(。|\.|！|!|\s*)$/u', $text) === 1;
    }

    private function looksLikeInsufficientOnlyAnswer(string $text): bool
    {
        return preg_match('/^(情報がありません|判断できません|見つかりません|根拠が不足しています|追加情報が必要です)(。|\.|！|!|\s*)$/u', $text) === 1;
    }

    private function looksLikeOperationOnlyFallbackAnswer(string $text): bool
    {
        $segments = preg_split('/[。.!！?\n]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($segments) || $segments === [] || count($segments) > 3) {
            return false;
        }

        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            if (preg_match('/(アップロード|再アップロード|選択|指定|クリック|入力|保存|送信|確認).*(してください|して下さい)/u', $segment) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function looksLikeProcessingNoticeOnlyAnswer(string $text): bool
    {
        return preg_match('/^(処理中です|少々お待ちください|しばらくお待ちください|お待ちください)(。|\.|！|!|\s*)$/u', $text) === 1;
    }

    private function hasUnclosedStructuredBlock(string $text): bool
    {
        if (substr_count($text, '```') % 2 !== 0) {
            return true;
        }

        if (preg_match('/^\s*[\{\[]/u', $text) === 1) {
            if (substr_count($text, '{') !== substr_count($text, '}')) {
                return true;
            }
            if (substr_count($text, '[') !== substr_count($text, ']')) {
                return true;
            }
        }

        $tagPairs = [
            'table',
            'ul',
            'ol',
            'div',
            'section',
            'article',
            'pre',
            'code',
        ];
        foreach ($tagPairs as $tag) {
            if (preg_match('/<' . $tag . '\b/i', $text) === 1 && preg_match('/<\/' . $tag . '>/i', $text) !== 1) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeIncompleteFallbackAnswer(string $rawText, string $normalized): bool
    {
        if ($normalized === '') {
            return true;
        }

        if (preg_match('/[：:、,\(\[「『\-]$/u', $normalized) === 1) {
            return true;
        }

        if (preg_match('/(\.\.\.|…)\s*$/u', $normalized) === 1) {
            return true;
        }

        $trimmedRaw = rtrim($rawText);
        if ($trimmedRaw !== '' && preg_match('/[。.!！?）】」』>]\s*$/u', $trimmedRaw) !== 1 && mb_strlen($normalized) <= 120) {
            return true;
        }

        return false;
    }

    private function isProjectMemoryConsultationRoute(): bool
    {
        return $this->routeDetail === 'normal_rag.project_memory_consultation';
    }

    private function shouldSkipDocumentRagForCodeImprovement(): bool
    {
        $userIntent = trim((string)($this->conversationIntentProfile['user_intent'] ?? ''));
        $expectedResponse = trim((string)($this->conversationIntentProfile['expected_response'] ?? ''));

        return $userIntent === 'code_improvement'
            && $expectedResponse === 'implementation_plan';
    }

    private function buildProjectContextPrompt(): string
    {
        if ($this->isProjectMemoryConsultationRoute()) {
            return '';
        }

        return $this->projectContext . "\n";
    }

    private function buildPriorityHeaderPrompt(): string
    {
        require_once __DIR__ . '/../../src/PromptManager.php';

        return PromptManager::buildResponsePriorityHeader(
            $this->originalMessage,
            (string)$this->routeDetail,
            $this->inferPriorityOperation(),
            (string)$this->projectOperatingMemoryPrompt,
            $this->detectPrimaryEvidenceType(),
            $this->conversationIntentProfile
        );
    }

    private function inferPriorityOperation(): string
    {
        $message = trim((string)$this->originalMessage);
        if ($message === '') {
            return '';
        }

        if ($this->isMaterialDraftingQuestion()) {
            return 'material_workflow';
        }

        if (
            $this->isProjectMemoryConsultationRoute()
            && preg_match('/(進行中タスク|現在のタスク|現在の主成果品|次に何をすべき|次に何をすれば|現在地|今の状況|優先タスク|次アクション)/u', $message) === 1
        ) {
            return 'status_alignment';
        }

        return '';
    }

    private function shouldSkipProjectMemoryRefresh(): bool
    {
        return $this->isProjectMemoryConsultationRoute()
            && $this->inferPriorityOperation() === 'status_alignment';
    }

    private function detectPrimaryEvidenceType(): string
    {
        if ($this->isMaterialDraftingQuestion()) {
            return 'material_note';
        }

        if ($this->isProjectMemoryConsultationRoute()) {
            return 'project_memory';
        }

        $types = [];
        foreach ($this->sourceDocs as $doc) {
            $type = trim((string)($doc['source_type'] ?? ''));
            if ($type === 'material_note') {
                $types['material_note'] = true;
                continue;
            }
            if ($type === 'csv') {
                $types['csv'] = true;
                continue;
            }
            if ($type !== '') {
                $types['pdf'] = true;
            }
        }

        if (!empty($types['material_note']) && (count($types) > 1 || !empty($types['pdf']) || !empty($types['csv']))) {
            return 'mixed';
        }
        if (!empty($types['csv']) && !empty($types['pdf'])) {
            return 'mixed';
        }
        if (!empty($types['material_note'])) {
            return 'material_note';
        }
        if (!empty($types['csv'])) {
            return 'csv';
        }
        if (!empty($types['pdf'])) {
            return 'pdf';
        }

        if ($this->historySummaryText !== '') {
            return 'history';
        }

        return 'unknown';
    }

    private function buildDialogueContextPrompt(): string
    {
        if ($this->historySummaryText === '') {
            return '';
        }

        if ($this->shouldSkipDocumentRagForCodeImprovement()) {
            $filteredHistory = $this->filterHistorySummaryTextForCodeImprovement();
            if ($filteredHistory === '') {
                return '';
            }

            return "【これまでの会話の文脈】\n{$filteredHistory}\n\n";
        }

        if ($this->isStructuredAggregationLikeQuestion()) {
            $structuredHistory = $this->extractConsultationHistoryContext();
            if ($structuredHistory === '') {
                return '';
            }

            return "【これまでの会話の文脈】\n{$structuredHistory}\n\n";
        }

        if (!$this->isProjectMemoryConsultationRoute()) {
            return "【これまでの会話の文脈】\n{$this->historySummaryText}\n\n";
        }

        $consultationHistory = $this->extractConsultationHistoryContext();
        if ($consultationHistory === '') {
            return '';
        }

        return "【これまでの会話の文脈】\n{$consultationHistory}\n\n";
    }

    private function extractConsultationHistoryContext(): string
    {
        $text = trim($this->historySummaryText);
        if ($text === '') {
            return '';
        }

        $lines = preg_split('/\R/u', $text) ?: [];
        $userLines = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || mb_strpos($trimmed, 'AI:') === 0) {
                continue;
            }
            if (mb_strpos($trimmed, 'ユーザー:') === 0) {
                $userLines[] = $trimmed;
            }
        }

        if (empty($userLines)) {
            return '';
        }

        $userLines = array_slice($userLines, -2);
        return implode("\n", $userLines);
    }

    private function filterHistorySummaryTextForCodeImprovement(): string
    {
        $text = trim($this->historySummaryText);
        if ($text === '') {
            return '';
        }

        $lines = preg_split('/\R/u', $text) ?: [];
        $keptLines = [];
        $removedLines = 0;

        foreach ($lines as $line) {
            $trimmed = trim((string)$line);
            if ($trimmed === '') {
                continue;
            }

            $isAssistantLine = mb_strpos($trimmed, 'AI:') === 0;
            if ($isAssistantLine && $this->looksLikeDocumentExtractionHistoryLine($trimmed)) {
                $removedLines++;
                continue;
            }

            $keptLines[] = $trimmed;
        }

        if (empty($keptLines)) {
            $fallbackUserOnly = $this->extractConsultationHistoryContext();
            $keptLineCount = $fallbackUserOnly === '' ? 0 : count(preg_split('/\R/u', $fallbackUserOnly) ?: []);
            chatLogger(
                "[HISTORY-CONTEXT-POLICY] policy=filter_doc_extract_assistant_history"
                . " | reason=code_improvement_implementation_plan"
                . " | removed_lines={$removedLines}"
                . " | kept_lines={$keptLineCount}"
                . " | fallback=user_only"
            );
            return $fallbackUserOnly;
        }

        chatLogger(
            "[HISTORY-CONTEXT-POLICY] policy=filter_doc_extract_assistant_history"
            . " | reason=code_improvement_implementation_plan"
            . " | removed_lines={$removedLines}"
            . " | kept_lines=" . count($keptLines)
            . " | fallback=none"
        );

        return implode("\n", $keptLines);
    }

    private function looksLikeDocumentExtractionHistoryLine(string $line): bool
    {
        return preg_match('/(PDF図面|\.pdf|資料PDF|PDFから確認|根拠ページ|\/P\.|doc_extract|留意点)/u', $line) === 1;
    }

    private function buildConsultationFastPathResponse(): string
    {
        $message = trim($this->originalMessage);
        if ($message === '') {
            return '';
        }

        if (preg_match('/(案件名|プロジェクト名|名称)/u', $message) && preg_match('/(変えたい|変更|見直|変える)/u', $message)) {
            return "案件名の方向性、いっしょに整理しましょう。\n"
                . "まずは次のどれを一番強調したいか決めると進めやすいです。\n"
                . "1. 動作確認・検証であること\n"
                . "2. AIチャットやRAGの機能であること\n"
                . "3. 実運用を見据えた確認であること\n"
                . "この3つのうち主役にしたい軸を教えていただければ、その方向に合わせて短めの案件名候補を整えます。";
        }

        if (preg_match('/動作確認/u', $message) && preg_match('/強調/u', $message)) {
            return "了解です。今は「開発中」よりも「動作確認・検証中」であることを前に出すのがよさそうです。\n"
                . "表現の方向としては次の3つが使いやすいです。\n"
                . "1. 動作確認を実施中\n"
                . "2. 実運用を想定した検証中\n"
                . "3. AIチャット機能の挙動確認中\n"
                . "説明文にするなら、「support.php のAIチャット機能について、実運用を想定した動作確認を進めています。」くらいの言い方が自然です。";
        }

        return '';
    }

    private function isStructuredAggregationLikeQuestion(): bool
    {
        return preg_match('/(csv|列|カラム|項目|datetime|timestamp|yearmonth|name|日付|日時|年月|時間帯|時刻帯|hour)/iu', $this->originalMessage) === 1
            && preg_match('/(集計|件数|分布|一覧|表|グラフ|チャート|抽出|多い時間帯|ピーク時間|ピーク帯|何件|何種類|ユニーク|distinct)/iu', $this->originalMessage) === 1;
    }

    private function isProposalOrWritingAdviceQuestion(): bool
    {
        $message = trim((string)$this->originalMessage);
        if ($message === '') {
            return false;
        }

        $hasAdviceIntent = preg_match('/(良い案|よい案|案はありますか|追加したい|言い換え|どう表現|どんな項目|どう書|相談|提案|候補)/u', $message) === 1;
        $hasWritingContext = preg_match('/(目標|文章|文言|表現|項目|評価|人材育成|育成)/u', $message) === 1;
        $hasStrongAggregationIntent = preg_match('/(集計|件数|分布|ランキング|上位|何件|何種類|ユニーク|distinct|グラフ|チャート|一覧にして|表にして)/iu', $message) === 1;

        return $hasAdviceIntent && $hasWritingContext && !$hasStrongAggregationIntent;
    }

    private function isMaterialDraftingQuestion(): bool
    {
        $message = trim((string)$this->originalMessage);
        if ($message === '') {
            return false;
        }

        $hasMaterialIntent = preg_match('/(資料メモ|markdown|md|資料に追加|追記|修正|書き換え|章立て|見出し|ドラフト|たたき台|下書き|構成)/u', $message) === 1;
        $hasStrongAggregationIntent = preg_match('/(集計|件数|分布|ランキング|上位|何件|何種類|ユニーク|distinct|グラフ|チャート|一覧にして|表にして)/iu', $message) === 1;

        return $hasMaterialIntent && !$hasStrongAggregationIntent;
    }

    private function shouldSkipPdfHitForAdvice(array $hit, bool $preferAdviceContext): bool
    {
        $content = trim((string)($hit['content'] ?? ''));
        $imageDescription = trim((string)($hit['image_description'] ?? ''));
        $combined = $content . "\n" . $imageDescription;

        if ($combined === '') {
            return true;
        }

        if (preg_match('/(分析対象の画像.*(添付|提供).*(ありませ|おりませ)|画像を再度アップロード|現在ご提示いただいた画像には|内容が確認できる画像を再度ご提供|期待される出力形式|ご依頼内容の確認と対応方針)/u', $combined) === 1) {
            return true;
        }

        if ($preferAdviceContext && preg_match('/(想定される出力形式|処理結果|このページに含まれる画像\/図表の説明)/u', $combined) === 1) {
            return true;
        }

        return false;
    }

    private function prepareImageDescriptionForContext(string $imageDescription): string
    {
        $normalized = trim((string)(preg_replace('/\s+/u', ' ', $imageDescription) ?? $imageDescription));
        if ($normalized === '') {
            return '';
        }

        if (
            str_starts_with($normalized, '本文中心ページ')
            || str_starts_with($normalized, 'テキスト主体ページ')
            || str_starts_with($normalized, '資料全体の要約・構成情報')
            || str_starts_with($normalized, '案件資料メモ')
            || str_starts_with($normalized, 'ページ種別: 未判定')
            || str_contains($normalized, 'CSVデータ')
        ) {
            return '';
        }

        if (preg_match('/^ページ種別:\s*[^|]+$/u', $normalized) === 1) {
            return '';
        }

        if (mb_strlen($normalized) < 18) {
            return '';
        }

        return $normalized;
    }

    private function rebalanceReferAllPdfHits(array $pdfHits, int $limit = 6): array
    {
        if (empty($pdfHits)) {
            return [];
        }

        $summaryHits = [];
        $detailHits = [];
        foreach ($pdfHits as $hit) {
            if (($hit['page_number'] ?? null) == 0) {
                $summaryHits[] = $hit;
            } else {
                $detailHits[] = $hit;
            }
        }

        $selected = [];
        foreach (array_slice($detailHits, 0, min(4, $limit)) as $hit) {
            $selected[] = $hit;
        }
        foreach (array_slice($summaryHits, 0, max(0, $limit - count($selected))) as $hit) {
            $selected[] = $hit;
        }
        if (count($selected) < $limit) {
            foreach (array_slice($detailHits, count($selected), $limit - count($selected)) as $hit) {
                $selected[] = $hit;
            }
        }

        chatLogger("[RAG-CONTEXT-FILTER] referAllMode で資料全体要約=" . count($summaryHits) . "件 / 本文ページ=" . count($detailHits) . "件 を再配分し、本文優先で " . count($selected) . "件を採用しました。");
        return array_slice($selected, 0, $limit);
    }

    private function mergeMaterialFirstHits(array $materialHits, array $pdfHits, int $limit = 6): array
    {
        $selected = [];
        foreach (array_slice($materialHits, 0, min(4, $limit)) as $hit) {
            $selected[] = $hit;
        }
        foreach (array_slice($pdfHits, 0, max(0, $limit - count($selected))) as $hit) {
            $selected[] = $hit;
        }

        chatLogger("[MATERIAL-RAG] 資料メモ=" . count($materialHits) . "件 / PDF=" . count($pdfHits) . "件 から、資料メモ優先で " . count($selected) . "件を採用しました。");
        return array_slice($selected, 0, $limit);
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
            
            $row_data_match = [];
            preg_match('/第(\d+)行/', $text, $row_data_match);
            $row_idx = $row_data_match[1] ?? '?';
            
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

if (!function_exists('summarizeCsvChunksForAdvice')) {
    function summarizeCsvChunksForAdvice(array $csvChunks, string $fileName): string {
        if (empty($csvChunks)) return "";

        $headers = [];
        $rowCount = 0;

        foreach ($csvChunks as $chunk) {
            $text = (string)($chunk['content'] ?? $chunk['chunk_text'] ?? '');
            if ($text === '') {
                continue;
            }
            $rowCount++;
            $cleaned = preg_replace('/^CSV「[^」]+」の第(\d+)行のデータ：/u', '', $text);
            $parts = preg_split('/、/u', (string)$cleaned) ?: [];
            foreach ($parts as $part) {
                if (preg_match('/^(.+?)は「(.*?)」$/u', trim($part), $m)) {
                    $column = trim((string)$m[1]);
                    if ($column !== '' && !in_array($column, $headers, true)) {
                        $headers[] = $column;
                    }
                }
            }
        }

        $headerPreview = empty($headers) ? '（列情報を抽出できませんでした）' : implode(' / ', array_slice($headers, 0, 12));
        $suffix = count($headers) > 12 ? ' / ...' : '';

        return "- 対象CSV: {$fileName}\n"
            . "- 参照行数: {$rowCount}件\n"
            . "- 主な列: {$headerPreview}{$suffix}";
    }
}
