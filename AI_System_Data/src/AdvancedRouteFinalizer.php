<?php

require_once __DIR__ . '/ChatModelRolePayload.php';
require_once __DIR__ . '/FaqAutoRegistrar.php';
require_once __DIR__ . '/ReportGenerator.php';
require_once __DIR__ . '/ChatThreadManager.php';
require_once __DIR__ . '/ProjectMemoryAutoUpdater.php';
require_once __DIR__ . '/CsvExportGenerator.php';

final class AdvancedRouteFinalizer
{
    private $pdo;
    private $projectId;
    private $threadId;
    private $userId;
    private $reasoningId;
    private $originalMessage;
    private $finalResponse;
    private $evalResult;
    private $retryCount;
    private $reportMode;
    private $csvMode;
    private $ollamaHost;
    private $repoRoot;
    private $mainModel;
    private $subModel;
    private $embeddingModel;
    private $synthesisModel;
    private $uniqueSources;
    private $normalizeUtf8;
    private $logger;

    public function __construct(
        PDO $pdo,
        int $projectId,
        ?int $threadId,
        int $userId,
        string $reasoningId,
        string $originalMessage,
        string $finalResponse,
        ?array $evalResult,
        int $retryCount,
        bool $reportMode,
        bool $csvMode,
        string $ollamaHost,
        string $repoRoot,
        string $mainModel,
        string $subModel,
        string $embeddingModel,
        string $synthesisModel,
        array $uniqueSources,
        callable $normalizeUtf8,
        ?callable $logger = null
    ) {
        $this->pdo = $pdo;
        $this->projectId = $projectId;
        $this->threadId = $threadId;
        $this->userId = $userId;
        $this->reasoningId = $reasoningId;
        $this->originalMessage = $originalMessage;
        $this->finalResponse = $finalResponse;
        $this->evalResult = $evalResult;
        $this->retryCount = $retryCount;
        $this->reportMode = $reportMode;
        $this->csvMode = $csvMode;
        $this->ollamaHost = $ollamaHost;
        $this->repoRoot = $repoRoot;
        $this->mainModel = $mainModel;
        $this->subModel = $subModel;
        $this->embeddingModel = $embeddingModel;
        $this->synthesisModel = $synthesisModel;
        $this->uniqueSources = $uniqueSources;
        $this->normalizeUtf8 = $normalizeUtf8;
        $this->logger = $logger;
    }

    public function saveHistoryAndEvaluations(): array
    {
        sendSSE('status', [
            'step' => 6,
            'message' => '💾 生成した成果品を確定し、会話履歴・推論プロセス・評価結果へ保存しています...'
        ]);
        $this->log("[DEBUG] DBトランザクションを開始し、ステップ99・対話ログ・評価スコアを一元コミットします...");

        $historyId = null;
        $reportDocument = null;
        $csvExport = null;

        try {
            $this->pdo->beginTransaction();

            if ($this->reasoningId !== '') {
                $stmtInsertStep = $this->pdo->prepare("INSERT INTO chat_reasoning_steps (project_id, session_id, original_question, step_number, sub_query, sub_answer, created_at) VALUES (?, ?, ?, 99, '最終レポートの精錬マージ', '完了', NOW())");
                $stmtInsertStep->execute([
                    $this->projectId,
                    $this->reasoningId,
                    $this->normalize($this->originalMessage)
                ]);
                $this->log("[DEBUG] chat_reasoning_steps の最終ステップ(99)をトランザクション内で正常に完了記録しました。");
            }

            $stmtUser = $this->pdo->prepare("INSERT INTO chat_history (project_id, thread_id, user_id, role, message, created_at) VALUES (?, ?, ?, 'user', ?, NOW())");
            $stmtUser->execute([$this->projectId, $this->threadId, $this->userId, $this->normalize($this->originalMessage)]);

            $stmtAi = $this->pdo->prepare("INSERT INTO chat_history (project_id, thread_id, user_id, role, message, created_at) VALUES (?, ?, ?, 'assistant', ?, NOW())");
            $stmtAi->execute([$this->projectId, $this->threadId, $this->userId, $this->normalize($this->finalResponse)]);
            $historyId = (int)$this->pdo->lastInsertId();
            $this->log("[DEBUG] chat_history 登録成功。ID: {$historyId}");

            ChatThreadManager::updateTitleFromMessage(
                $this->pdo,
                $this->projectId,
                $this->threadId,
                $this->originalMessage
            );

            if ($this->reasoningId !== '') {
                $updHist = $this->pdo->prepare("UPDATE chat_reasoning_steps SET chat_history_id = ? WHERE session_id = ?");
                $updHist->execute([$historyId, $this->reasoningId]);
            }

            if ($this->evalResult) {
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
                    $this->normalize((string)($this->evalResult['feedback'] ?? '')),
                    $this->retryCount
                ]);
                $this->log("[DEBUG] chat_evaluations へ品質審査スコアを一元トランザクション内で同期登録しました。");
            }

            sendSSE('status', [
                'step' => 6,
                'message' => '📚 回答をナレッジ候補として評価し、FAQ登録条件を確認しています...'
            ]);
            $faqRegistrar = new FaqAutoRegistrar($this->pdo);
            $faqRegistrar->registerIfQualified(
                $this->projectId,
                $historyId,
                $this->userId,
                $this->originalMessage,
                $this->finalResponse,
                $this->evalResult
            );

            $this->pdo->commit();
            $this->log("[DEBUG] DBトランザクションコミット成功。すべての書き込みデータ整合性を完全保護しました。");
            $fallbackGuardReason = $this->getDownstreamFallbackGuardReason();
            if ($fallbackGuardReason !== null) {
                $this->log("[EVAL-FALLBACK-GUARD] blocked=project_memory_refresh | route=advanced | reason={$fallbackGuardReason}");
                $this->log("[PROJECT-MEMORY-AUTO] skipped=quality_guard | thread_id=" . ($this->threadId === null ? 'NULL' : (string)$this->threadId));
            } elseif (ProjectMemoryAutoUpdater::shouldRefreshFromEvaluation($this->evalResult, $this->finalResponse)) {
                ProjectMemoryAutoUpdater::refresh(
                    $this->pdo,
                    $this->projectId,
                    $this->threadId,
                    $this->userId,
                    fn(string $message) => $this->log($message)
                );
            } else {
                $this->log("[PROJECT-MEMORY-AUTO] skipped=quality_guard | thread_id=" . ($this->threadId === null ? 'NULL' : (string)$this->threadId));
            }
            $reportDocument = $this->createReportDocumentIfRequested($historyId);
            $csvExport = $this->createCsvExportIfRequested($historyId);
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
                $this->log("[WARN] DBトランザクション内で例外エラーを検知したため、一斉ロールバックを執行しました。");
            }
            $this->log("データベースへの履歴永続化エラー: " . $e->getMessage());
        }

        return [
            'history_id' => $historyId,
            'report_document' => $reportDocument,
            'csv_export' => $csvExport,
        ];
    }

    public function sendFinalResult($reportDocument, $csvExport = null): void
    {
        $stmtSteps = $this->pdo->prepare("SELECT step_number, sub_query, sub_answer FROM chat_reasoning_steps WHERE session_id = ? AND step_number < 99 ORDER BY step_number ASC");
        $stmtSteps->execute([$this->reasoningId]);
        $reasoningSteps = $stmtSteps->fetchAll(PDO::FETCH_ASSOC);
        $sourceDocs = array_values($this->uniqueSources);

        $this->logFinalResponseSnapshot('advanced_hybrid', $this->finalResponse);

        sendSSE('result', [
            'status' => 'success',
            'response' => $this->finalResponse,
            'sources' => $sourceDocs,
            'reasoning_steps' => $reasoningSteps,
            'mode_used' => 'advanced_reasoning_multi_step',
            'detected_page' => null,
            'hit_count' => count($sourceDocs),
            'applied_model' => $this->synthesisModel,
            'model_roles' => ChatModelRolePayload::build($this->mainModel, $this->subModel, $this->embeddingModel, 'main'),
            'created_at' => date('Y/m/d H:i'),
            'report_document' => $reportDocument,
            'csv_export' => $csvExport,
        ]);
        $this->log("=== [MoA大統合ハブコントローラー] ハイブリッド並列多重推論パイプライン全線開通・処理完了 ===");
    }

    private function createReportDocumentIfRequested(int $historyId)
    {
        if (!$this->reportMode || $this->projectId === 0) {
            return null;
        }
        $fallbackGuardReason = $this->getDownstreamFallbackGuardReason();
        if ($fallbackGuardReason !== null) {
            $this->log("[EVAL-FALLBACK-GUARD] blocked=report_generation | route=advanced | reason={$fallbackGuardReason}");
            sendSSE('status', [
                'step' => 6,
                'message' => '⚠️ 評価が不安定なため、報告書PDF生成はスキップしました。'
            ]);
            return null;
        }
        if (($this->evalResult['verdict'] ?? '') === 'reject') {
            $this->log('[REPORT] 品質評価がrejectのため、報告書PDF生成をスキップしました。chat_history_id=' . $historyId);
            sendSSE('status', [
                'step' => 6,
                'message' => '⚠️ 回答が報告書成果品として不足しているため、PDF出力はスキップしました。'
            ]);
            return null;
        }

        try {
            sendSSE('status', [
                'step' => 6,
                'message' => '📄 育てた報告書成果品をPDFとして出力し、資料タブへ登録しています...'
            ]);
            $generator = new ReportGenerator(
                $this->pdo,
                $this->repoRoot,
                $this->ollamaHost,
                fn(string $message) => $this->log($message)
            );

            $reportDocument = $generator->createFromChat(
                $this->projectId,
                $historyId,
                $this->userId,
                $this->originalMessage,
                $this->finalResponse,
                $this->evalResult,
                $this->reasoningId
            );
            sendSSE('status', [
                'step' => 6,
                'message' => '✅ 報告書成果品をPDFタブへ登録し、検索対象へ反映しました。'
            ]);

            return $reportDocument;
        } catch (Throwable $e) {
            $this->log('[REPORT] 報告書PDF登録に失敗: ' . $e->getMessage());
            sendSSE('status', [
                'step' => 6,
                'message' => '⚠️ 報告書PDFの登録に失敗しました。管理者ログを確認してください。'
            ]);
            return null;
        }
    }

    private function createCsvExportIfRequested(int $historyId)
    {
        if (!$this->csvMode || $this->projectId === 0) {
            return null;
        }
        $fallbackGuardReason = $this->getDownstreamFallbackGuardReason();
        if ($fallbackGuardReason !== null) {
            $this->log("[EVAL-FALLBACK-GUARD] blocked=csv_generation | route=advanced | reason={$fallbackGuardReason}");
            return null;
        }
        if (($this->evalResult['verdict'] ?? '') === 'reject') {
            $this->log('[CSV-EXPORT] 品質評価がrejectのため、生成CSV登録をスキップしました。chat_history_id=' . $historyId);
            return null;
        }

        try {
            sendSSE('status', [
                'step' => 6,
                'message' => '🧾 育てた表をCSV成果品として登録しています...'
            ]);
            $generator = new CsvExportGenerator(
                $this->pdo,
                fn(string $message) => $this->log($message)
            );
            $csvExport = $generator->createFromChat(
                $this->projectId,
                $historyId,
                $this->userId,
                $this->originalMessage,
                $this->finalResponse
            );
            sendSSE('status', [
                'step' => 6,
                'message' => $csvExport !== null
                    ? '✅ 生成した表をCSV成果品としてCSVタブへ登録しました。'
                    : 'ℹ️ CSV化モードは有効でしたが、成果品として保存できる表は見つかりませんでした。'
            ]);

            return $csvExport;
        } catch (Throwable $e) {
            $this->log('[CSV-EXPORT] 生成CSV登録に失敗: ' . $e->getMessage());
            sendSSE('status', [
                'step' => 6,
                'message' => '⚠️ 生成CSVの登録に失敗しました。管理者ログを確認してください。'
            ]);
            return null;
        }
    }

    private function logFinalResponseSnapshot(string $routeName, string $response): void
    {
        $normalized = trim((string)$response);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $limit = 4000;
        $isTruncated = mb_strlen($normalized) > $limit;
        $preview = $isTruncated ? mb_substr($normalized, 0, $limit) . '...' : $normalized;
        $question = trim((string)$this->originalMessage);
        $question = preg_replace('/\s+/u', ' ', $question) ?? $question;

        $this->log("[FINAL-ANSWER] route={$routeName} | questionChars=" . mb_strlen($question) . " | responseChars=" . mb_strlen($response) . " | truncated=" . ($isTruncated ? 'yes' : 'no'));
        $this->log("[FINAL-ANSWER-QUESTION] {$question}");
        $this->log("[FINAL-ANSWER-BODY] " . $preview);
    }

    private function normalize(string $text): string
    {
        return (string)call_user_func($this->normalizeUtf8, $text);
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $message);
        }
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
}
