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

    /**
     * @param array<int, array{sub_query?: string, step_number?: int}> $steps
     */
    public function recordDecomposedSteps(
        array $steps,
        string $initialAnswer = '[DECOMPOSED-PENDING]',
        ?int $chatHistoryId = null
    ): int
    {
        $savedCount = 0;

        foreach ($steps as $index => $step) {
            $subQuery = trim((string)($step['sub_query'] ?? ''));
            if ($subQuery === '') {
                continue;
            }

            $stepNumber = (int)($step['step_number'] ?? ($index + 1));
            $packetJson = $this->encodeDecompositionPacket($step);
            try {
                $stmt = $this->pdo->prepare("
                    INSERT INTO chat_reasoning_steps
                        (chat_history_id, project_id, session_id, original_question, step_number, sub_query, search_context, sub_answer, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $chatHistoryId,
                    $this->projectId,
                    $this->reasoningId,
                    $this->normalize($this->originalMessage),
                    $stepNumber,
                    $this->normalize($subQuery),
                    $packetJson,
                    $this->normalize($initialAnswer),
                ]);
                $stepId = (int)$this->pdo->lastInsertId();
                $this->log(
                    '[QUESTION-DECOMPOSE-SAVE] project_id=' . $this->projectId
                    . ' | user_chat_history_id=' . ($chatHistoryId === null ? 'pending' : (string)$chatHistoryId)
                    . ' | reasoning_step_id=' . $stepId
                    . ' | session_id=' . $this->reasoningId
                    . ' | step_number=' . $stepNumber
                    . ' | sub_question_count=' . count($steps)
                    . ' | save_worthy=' . (!empty($step['save_worthy']) ? 'true' : 'false')
                    . ' | request_mode=' . (string)($step['request_mode'] ?? 'none')
                    . ' | update_policy=' . (string)($step['update_policy'] ?? 'none')
                );
                $savedCount++;
            } catch (Exception $e) {
                $this->log("[QUESTION-DECOMPOSE-SAVE] reasoning step 保存失敗: " . $e->getMessage());
            }
        }

        return $savedCount;
    }

    private function normalize(string $value): string
    {
        return (string)call_user_func($this->normalizeUtf8, $value);
    }

    /** @param array<string, mixed> $step */
    private function encodeDecompositionPacket(array $step): ?string
    {
        $packet = [
            'type' => 'question_decomposition',
            'schema_version' => 1,
            'packet' => $step,
        ];
        $json = json_encode($packet, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            $this->log('[QUESTION-DECOMPOSE-SAVE] packet_json=failed | reason=' . json_last_error_msg());
            return null;
        }

        return $json;
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $message);
        }
    }
}
