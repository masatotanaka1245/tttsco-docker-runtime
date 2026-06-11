<?php

final class AdvancedReasoningStepRecorder
{
    private PDO $pdo;
    private int $projectId;
    private string $reasoningId;
    private string $originalMessage;
    /** @var callable */
    private $normalizeUtf8;
    /** @var callable|null */
    private $logger;

    public function __construct(
        PDO $pdo,
        int $projectId,
        string $reasoningId,
        string $originalMessage,
        callable $normalizeUtf8,
        ?callable $logger = null
    ) {
        $this->pdo = $pdo;
        $this->projectId = $projectId;
        $this->reasoningId = $reasoningId;
        $this->originalMessage = $originalMessage;
        $this->normalizeUtf8 = $normalizeUtf8;
        $this->logger = $logger;
    }

    public function recordPlannerThought(string $thought): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO chat_reasoning_steps (project_id, session_id, original_question, step_number, sub_query, sub_answer, created_at) VALUES (?, ?, ?, 0, '【AIの思考プロセス: 実行計画生成】', ?, NOW())"
            );
            $stmt->execute([
                $this->projectId,
                $this->reasoningId,
                $this->originalMessage,
                $thought,
            ]);
        } catch (Exception $e) {
            $this->log("思考プロセス保存例外: " . $e->getMessage());
        }
    }

    public function recordFastPathStep(int $stepNumber, string $subQuery, string $subAnswer): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO chat_reasoning_steps
                    (project_id, session_id, original_question, step_number, sub_query, sub_answer, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $this->projectId,
                $this->reasoningId,
                $this->normalize($this->originalMessage),
                $stepNumber,
                $this->normalize($subQuery),
                $this->normalize($subAnswer),
            ]);
        } catch (Exception $e) {
            $this->log("[ADV-FASTPATH] reasoning step 保存失敗: " . $e->getMessage());
        }
    }

    public function upsertProgressStep(int $stepNumber, string $subQuery, string $subAnswer): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO chat_reasoning_steps (project_id, session_id, original_question, step_number, sub_query, sub_answer, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE sub_answer = ?
            ");
            $normalizedAnswer = $this->normalize($subAnswer);
            $stmt->execute([
                $this->projectId,
                $this->reasoningId,
                $this->normalize($this->originalMessage),
                $stepNumber,
                $this->normalize($subQuery),
                $normalizedAnswer,
                $normalizedAnswer,
            ]);
        } catch (Exception $e) {
            $this->log("[DB-SAVE-WARN] 外部メモリの即時セーブに失敗: " . $e->getMessage());
        }
    }

    private function normalize(string $value): string
    {
        return (string)call_user_func($this->normalizeUtf8, $value);
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $message);
        }
    }
}
