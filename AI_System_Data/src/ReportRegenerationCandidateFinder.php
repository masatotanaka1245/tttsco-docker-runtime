<?php

declare(strict_types=1);

final class ReportRegenerationCandidateFinder
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findByDocumentId(int $documentId): array
    {
        $document = $this->loadDocument($documentId);
        if ($document === null) {
            return [
                'applicable' => false,
                'confidence' => 'none',
                'mode' => 'none',
                'exact_replay' => false,
                'reason' => 'document_not_found',
            ];
        }

        return $this->findForDocument($document);
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public function findForDocument(array $document): array
    {
        $documentId = (int)($document['id'] ?? 0);
        $projectId = (int)($document['project_id'] ?? 0);
        $title = (string)($document['title'] ?? '');
        $filePath = (string)($document['file_path'] ?? '');
        $createdAt = (string)($document['created_at'] ?? '');

        if ($documentId <= 0 || $projectId <= 0 || $createdAt === '') {
            return [
                'applicable' => false,
                'confidence' => 'none',
                'mode' => 'none',
                'exact_replay' => false,
                'reason' => 'invalid_document_record',
            ];
        }

        if (!$this->isGeneratedReport($title, $filePath)) {
            return [
                'applicable' => false,
                'confidence' => 'none',
                'mode' => 'none',
                'exact_replay' => false,
                'reason' => 'not_generated_report',
            ];
        }

        $assistantCandidates = $this->loadNearbyAssistantMessages($projectId, $createdAt);
        $bestMatch = null;
        $bestScore = -1;

        foreach ($assistantCandidates as $assistant) {
            $evaluation = $this->evaluateAssistantCandidate($projectId, $assistant, $title, $createdAt);
            if (($evaluation['score'] ?? -1) > $bestScore) {
                $bestScore = (int)($evaluation['score'] ?? -1);
                $bestMatch = $evaluation;
            }
        }

        if (!is_array($bestMatch) || $bestScore < 0) {
            return [
                'applicable' => true,
                'confidence' => 'low',
                'mode' => 'near_rebuild',
                'exact_replay' => false,
                'reason' => 'no_nearby_assistant_match',
                'document_id' => $documentId,
                'project_id' => $projectId,
                'title' => $title,
            ];
        }

        return [
            'applicable' => true,
            'confidence' => $this->scoreToConfidence((int)$bestMatch['score']),
            'mode' => 'near_rebuild',
            'exact_replay' => false,
            'reason' => 'matched_nearby_chat_history',
            'document_id' => $documentId,
            'project_id' => $projectId,
            'title' => $title,
            'user_chat_history_id' => (int)($bestMatch['user_chat_history_id'] ?? 0),
            'assistant_chat_history_id' => (int)($bestMatch['assistant_chat_history_id'] ?? 0),
            'thread_id' => (int)($bestMatch['thread_id'] ?? 0),
            'reasoning_session_id' => (string)($bestMatch['reasoning_session_id'] ?? ''),
            'reasoning_steps_count' => (int)($bestMatch['reasoning_steps_count'] ?? 0),
            'has_report_draft_step' => !empty($bestMatch['has_report_draft_step']),
            'time_distance_seconds' => (int)($bestMatch['time_distance_seconds'] ?? 0),
            'matched_signals' => array_values((array)($bestMatch['matched_signals'] ?? [])),
            'user_created_at' => (string)($bestMatch['user_created_at'] ?? ''),
            'assistant_created_at' => (string)($bestMatch['assistant_created_at'] ?? ''),
            'user_message_excerpt' => (string)($bestMatch['user_message_excerpt'] ?? ''),
            'assistant_message_excerpt' => (string)($bestMatch['assistant_message_excerpt'] ?? ''),
            'note' => '原本HTMLと生成時スナップショットが失われているため exact replay は行えません。再生成する場合は既存レコードを更新せず、新しい documents レコードとして扱う前提です。',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadDocument(int $documentId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT id, project_id, title, file_path, created_at
            FROM documents
            WHERE id = ?
            LIMIT 1
        ');
        $stmt->execute([$documentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function isGeneratedReport(string $title, string $filePath): bool
    {
        return str_starts_with($title, 'AI報告書_')
            || str_contains($filePath, '/report_')
            || str_contains($filePath, 'report_');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadNearbyAssistantMessages(int $projectId, string $documentCreatedAt): array
    {
        $stmt = $this->pdo->prepare('
            SELECT
                id,
                thread_id,
                user_id,
                role,
                message,
                created_at,
                ABS(TIMESTAMPDIFF(SECOND, created_at, ?)) AS diff_seconds
            FROM chat_history
            WHERE project_id = ?
              AND role = "assistant"
              AND created_at BETWEEN DATE_SUB(?, INTERVAL 45 MINUTE) AND DATE_ADD(?, INTERVAL 5 MINUTE)
            ORDER BY diff_seconds ASC, created_at DESC, id DESC
            LIMIT 8
        ');
        $stmt->execute([$documentCreatedAt, $projectId, $documentCreatedAt, $documentCreatedAt]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string, mixed> $assistant
     * @return array<string, mixed>
     */
    private function evaluateAssistantCandidate(int $projectId, array $assistant, string $documentTitle, string $documentCreatedAt): array
    {
        $assistantId = (int)($assistant['id'] ?? 0);
        $threadId = (int)($assistant['thread_id'] ?? 0);
        $assistantCreatedAt = (string)($assistant['created_at'] ?? '');
        $assistantMessage = (string)($assistant['message'] ?? '');
        $diffSeconds = (int)($assistant['diff_seconds'] ?? 0);

        $user = $this->loadPrecedingUserMessage($projectId, $threadId, $assistantCreatedAt, $assistantId);
        $reasoning = $this->loadReasoningSteps($projectId, $assistantId);
        $reasoningSteps = (array)($reasoning['steps'] ?? []);
        $reasoningSessionId = (string)($reasoning['session_id'] ?? '');
        $reasoningStepCount = count($reasoningSteps);
        $hasReportDraftStep = $this->hasReportDraftStep($reasoningSteps);
        $userMessage = (string)($user['message'] ?? '');
        $signals = [];
        $score = 0;

        if ($diffSeconds <= 300) {
            $score += 3;
            $signals[] = 'created_at_within_5m';
        } elseif ($diffSeconds <= 900) {
            $score += 2;
            $signals[] = 'created_at_within_15m';
        } elseif ($diffSeconds <= 2700) {
            $score += 1;
            $signals[] = 'created_at_within_45m';
        }

        if ($threadId > 0) {
            $score += 1;
            $signals[] = 'thread_bound';
        }

        if (!empty($user)) {
            $score += 1;
            $signals[] = 'preceding_user_message';
        }

        if ($reasoningStepCount >= 2) {
            $score += 2;
            $signals[] = 'reasoning_steps_present';
        }

        if ($reasoningSessionId !== '') {
            $score += 1;
            $signals[] = 'reasoning_session_bound';
        }

        if ($hasReportDraftStep) {
            $score += 2;
            $signals[] = 'report_draft_step';
        }

        if ($this->looksLikeReportRequest($userMessage)) {
            $score += 2;
            $signals[] = 'user_requested_report';
        }

        if ($this->looksLikeReportAnswer($assistantMessage)) {
            $score += 1;
            $signals[] = 'assistant_report_body';
        }

        if ($this->titleMatchesTimestamp($documentTitle, $documentCreatedAt, $assistantCreatedAt)) {
            $score += 1;
            $signals[] = 'title_timestamp_match';
        }

        return [
            'score' => $score,
            'user_chat_history_id' => (int)($user['id'] ?? 0),
            'assistant_chat_history_id' => $assistantId,
            'thread_id' => $threadId,
            'reasoning_session_id' => $reasoningSessionId,
            'reasoning_steps_count' => $reasoningStepCount,
            'has_report_draft_step' => $hasReportDraftStep,
            'time_distance_seconds' => $diffSeconds,
            'matched_signals' => $signals,
            'user_created_at' => (string)($user['created_at'] ?? ''),
            'assistant_created_at' => $assistantCreatedAt,
            'user_message_excerpt' => $this->excerpt($userMessage),
            'assistant_message_excerpt' => $this->excerpt($assistantMessage),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadPrecedingUserMessage(int $projectId, int $threadId, string $assistantCreatedAt, int $assistantId): array
    {
        if ($threadId <= 0 || $assistantCreatedAt === '') {
            return [];
        }

        $stmt = $this->pdo->prepare('
            SELECT id, thread_id, user_id, role, message, created_at
            FROM chat_history
            WHERE project_id = ?
              AND thread_id = ?
              AND role = "user"
              AND (
                    created_at < ?
                 OR (created_at = ? AND id < ?)
              )
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ');
        $stmt->execute([$projectId, $threadId, $assistantCreatedAt, $assistantCreatedAt, $assistantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    /**
     * @return array{session_id:string,steps:array<int,array<string,mixed>>}
     */
    private function loadReasoningSteps(int $projectId, int $assistantChatHistoryId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT session_id, step_number, sub_query, sub_answer, created_at
            FROM chat_reasoning_steps
            WHERE project_id = ?
              AND chat_history_id = ?
            ORDER BY step_number ASC, id ASC
        ');
        $stmt->execute([$projectId, $assistantChatHistoryId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'session_id' => (string)($rows[0]['session_id'] ?? ''),
            'steps' => $rows,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $steps
     */
    private function hasReportDraftStep(array $steps): bool
    {
        foreach ($steps as $step) {
            if ((int)($step['step_number'] ?? 0) !== 2) {
                continue;
            }
            $text = trim(((string)($step['sub_query'] ?? '')) . "\n" . ((string)($step['sub_answer'] ?? '')));
            if ($text === '') {
                continue;
            }
            if (preg_match('/報告書|構成案|要旨|結論|分析対象|推奨アクション|出典/u', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeReportRequest(string $message): bool
    {
        if ($message === '') {
            return false;
        }

        return preg_match('/報告書|レポート|構成案|まとめて|要約して/u', $message) === 1;
    }

    private function looksLikeReportAnswer(string $message): bool
    {
        if ($message === '') {
            return false;
        }

        return preg_match('/##\s*結論|##\s*分析対象|##\s*推奨アクション|報告書/u', $message) === 1;
    }

    private function titleMatchesTimestamp(string $title, string $documentCreatedAt, string $assistantCreatedAt): bool
    {
        if (preg_match('/AI報告書_(\d{8})_(\d{6})\.pdf/u', $title, $matches) !== 1) {
            return false;
        }

        $titleTimestamp = DateTimeImmutable::createFromFormat('Ymd_His', $matches[1] . '_' . $matches[2]);
        $docTimestamp = strtotime($documentCreatedAt);
        $assistantTimestamp = strtotime($assistantCreatedAt);

        if (!$titleTimestamp || $docTimestamp === false || $assistantTimestamp === false) {
            return false;
        }

        $titleUnix = $titleTimestamp->getTimestamp();

        return abs($titleUnix - $docTimestamp) <= 120
            || abs($titleUnix - $assistantTimestamp) <= 120;
    }

    private function excerpt(string $text, int $limit = 120): string
    {
        $normalized = trim((string)preg_replace('/\s+/u', ' ', $text));
        if ($normalized === '') {
            return '';
        }

        if (mb_strlen($normalized) <= $limit) {
            return $normalized;
        }

        return mb_substr($normalized, 0, $limit) . '...';
    }

    private function scoreToConfidence(int $score): string
    {
        if ($score >= 9) {
            return 'high';
        }
        if ($score >= 6) {
            return 'medium';
        }
        if ($score >= 3) {
            return 'low';
        }

        return 'none';
    }
}
