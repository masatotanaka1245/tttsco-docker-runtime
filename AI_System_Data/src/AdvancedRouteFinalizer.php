<?php

require_once __DIR__ . '/ChatModelRolePayload.php';
require_once __DIR__ . '/FaqAutoRegistrar.php';
require_once __DIR__ . '/ReportGenerator.php';

final class AdvancedRouteFinalizer
{
    private $pdo;
    private $projectId;
    private $userId;
    private $reasoningId;
    private $originalMessage;
    private $finalResponse;
    private $evalResult;
    private $retryCount;
    private $reportMode;
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
        int $userId,
        string $reasoningId,
        string $originalMessage,
        string $finalResponse,
        ?array $evalResult,
        int $retryCount,
        bool $reportMode,
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
        $this->userId = $userId;
        $this->reasoningId = $reasoningId;
        $this->originalMessage = $originalMessage;
        $this->finalResponse = $finalResponse;
        $this->evalResult = $evalResult;
        $this->retryCount = $retryCount;
        $this->reportMode = $reportMode;
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
            'message' => '💾 最終回答の品質確認が完了しました。会話履歴・推論プロセス・評価結果を保存しています...'
        ]);
        $this->log("[DEBUG] DBトランザクションを開始し、ステップ99・対話ログ・評価スコアを一元コミットします...");

        $historyId = null;
        $reportDocument = null;

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

            $stmtUser = $this->pdo->prepare("INSERT INTO chat_history (project_id, user_id, role, message, created_at) VALUES (?, ?, 'user', ?, NOW())");
            $stmtUser->execute([$this->projectId, $this->userId, $this->normalize($this->originalMessage)]);

            $stmtAi = $this->pdo->prepare("INSERT INTO chat_history (project_id, user_id, role, message, created_at) VALUES (?, ?, 'assistant', ?, NOW())");
            $stmtAi->execute([$this->projectId, $this->userId, $this->normalize($this->finalResponse)]);
            $historyId = (int)$this->pdo->lastInsertId();
            $this->log("[DEBUG] chat_history 登録成功。ID: {$historyId}");

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
                'message' => '📚 高評価回答のFAQ自動登録条件を確認しています...'
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
            $reportDocument = $this->createReportDocumentIfRequested($historyId);
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
        ];
    }

    public function sendFinalResult($reportDocument): void
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
        ]);
        $this->log("=== [MoA大統合ハブコントローラー] ハイブリッド並列多重推論パイプライン全線開通・処理完了 ===");
    }

    private function createReportDocumentIfRequested(int $historyId)
    {
        if (!$this->reportMode || $this->projectId === 0) {
            return null;
        }
        if (($this->evalResult['verdict'] ?? '') === 'reject') {
            $this->log('[REPORT] 品質評価がrejectのため、報告書PDF生成をスキップしました。chat_history_id=' . $historyId);
            sendSSE('status', [
                'step' => 6,
                'message' => '⚠️ 回答が報告書として成立しない判定のため、PDF生成はスキップしました。'
            ]);
            return null;
        }

        try {
            sendSSE('status', [
                'step' => 6,
                'message' => '📄 報告書モード: HTML/CSS報告書をPDF化し、資料PDFへ登録しています...'
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
                'message' => '✅ 報告書PDFをPDFタブへ登録し、検索対象化しました。'
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
}
