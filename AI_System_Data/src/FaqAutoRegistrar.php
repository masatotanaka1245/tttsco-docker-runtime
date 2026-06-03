<?php

/**
 * 高評価チャット回答を、案件FAQとして軽量に自動登録する。
 * 評価エラー時のフェイルセーフ高得点や、エラー回答・重複FAQは登録しない。
 */
class FaqAutoRegistrar {
    /** @var PDO */
    private $pdo;

    /** @var int */
    private $threshold;

    /** @var int */
    private $duplicateScanLimit;

    public function __construct(PDO $pdo, int $threshold = 95, int $duplicateScanLimit = 200) {
        $this->pdo = $pdo;
        $this->threshold = $threshold;
        $this->duplicateScanLimit = $duplicateScanLimit;
    }

    public function registerIfQualified(
        ?int $projectId,
        int $chatHistoryId,
        int $userId,
        string $question,
        string $answer,
        ?array $evalResult
    ): bool {
        try {
            $qualification = $this->buildQualificationResult($projectId, $chatHistoryId, $question, $answer, $evalResult);
            if (!$qualification['qualified']) {
                $this->logSkip($qualification['reason'], [
                    'chat_history_id' => $chatHistoryId,
                    'project_id' => (int)$projectId,
                    'score' => (int)($evalResult['total_score'] ?? 0),
                    'relevance' => (int)(($evalResult['scores']['answer_relevance'] ?? 0)),
                    'faithfulness' => (int)(($evalResult['scores']['faithfulness'] ?? 0)),
                ]);
                return false;
            }

            $questionSummary = $this->buildQuestionSummary($question);
            $answerSummary = $this->buildAnswerSummary($answer);

            if ($questionSummary === '' || $answerSummary === '') {
                $this->log("[FAQ-AUTO] 要約結果が空のため登録をスキップしました。chat_history_id: {$chatHistoryId}");
                return false;
            }

            $duplicateFaqId = $this->findDuplicateFaqId((int)$projectId, $chatHistoryId, $questionSummary, $answerSummary);
            if ($duplicateFaqId !== null) {
                $this->log("[FAQ-AUTO] 重複FAQを検知したため登録をスキップしました。faq_id: {$duplicateFaqId} | question: {$questionSummary}");
                return false;
            }

            $stmt = $this->pdo->prepare("
                INSERT INTO project_faqs
                    (project_id, chat_history_id, question_summary, answer_summary, created_by, created_at)
                VALUES
                    (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                (int)$projectId,
                $chatHistoryId,
                $questionSummary,
                $answerSummary,
                $userId
            ]);

            $faqId = (int)$this->pdo->lastInsertId();
            $score = (int)($evalResult['total_score'] ?? 0);
            $this->log("[FAQ-AUTO] 高評価チャットをFAQへ自動登録しました。faq_id: {$faqId} | chat_history_id: {$chatHistoryId} | score: {$score}");
            return true;
        } catch (Throwable $e) {
            $this->log("[FAQ-AUTO] 自動FAQ登録中に例外を検知しましたが、チャット保存は継続します: " . $e->getMessage());
            return false;
        }
    }

    private function buildQualificationResult(?int $projectId, int $chatHistoryId, string $question, string $answer, ?array $evalResult): array {
        if ($projectId === null || $projectId <= 0 || $chatHistoryId <= 0 || !$evalResult) {
            return ['qualified' => false, 'reason' => 'missing_project_or_eval'];
        }

        if (($evalResult['needs_revision'] ?? true) === true) {
            return ['qualified' => false, 'reason' => 'needs_revision'];
        }

        $feedback = (string)($evalResult['feedback'] ?? '');
        if (preg_match('/評価プロセス|タイムアウト|フェイルセーフ|初期ドラフトを採用/u', $feedback)) {
            return ['qualified' => false, 'reason' => 'fallback_or_timeout_feedback'];
        }

        $totalScore = (int)($evalResult['total_score'] ?? 0);
        $scores = $evalResult['scores'] ?? [];
        $relevance = (int)($scores['answer_relevance'] ?? 0);
        $faithfulness = (int)($scores['faithfulness'] ?? 0);

        if ($totalScore < $this->threshold || $relevance < 100 || $faithfulness < 90) {
            return ['qualified' => false, 'reason' => 'score_below_threshold'];
        }

        $questionText = $this->normalizeText($question);
        $answerText = $this->normalizeText($answer);
        if (mb_strlen($questionText) < 8 || mb_strlen($answerText) < 120) {
            return ['qualified' => false, 'reason' => 'question_or_answer_too_short'];
        }

        if (preg_match('/(通信エラー|内部サーバーエラー|AIサーバー通信エラー|回答の生成に失敗|Token Limit|セッションが切れました)/u', $answerText)) {
            return ['qualified' => false, 'reason' => 'error_text_detected'];
        }

        return ['qualified' => true, 'reason' => 'qualified'];
    }

    private function findDuplicateFaqId(int $projectId, int $chatHistoryId, string $questionSummary, string $answerSummary): ?int {
        $stmt = $this->pdo->prepare("
            SELECT id
            FROM project_faqs
            WHERE project_id = ?
              AND (chat_history_id = ? OR question_summary = ?)
            LIMIT 1
        ");
        $stmt->execute([$projectId, $chatHistoryId, $questionSummary]);
        $directHit = $stmt->fetchColumn();
        if ($directHit !== false) {
            return (int)$directHit;
        }

        $stmtRecent = $this->pdo->prepare("
            SELECT id, question_summary, answer_summary
            FROM project_faqs
            WHERE project_id = ?
            ORDER BY id DESC
            LIMIT {$this->duplicateScanLimit}
        ");
        $stmtRecent->execute([$projectId]);

        $normalizedQuestion = $this->normalizeFaqKey($questionSummary);
        $normalizedAnswer = $this->normalizeFaqKey($answerSummary);

        while ($row = $stmtRecent->fetch(PDO::FETCH_ASSOC)) {
            $existingQuestion = $this->normalizeFaqKey((string)($row['question_summary'] ?? ''));
            $existingAnswer = $this->normalizeFaqKey((string)($row['answer_summary'] ?? ''));
            if ($existingQuestion === '' && $existingAnswer === '') {
                continue;
            }

            if ($normalizedQuestion !== '' && $normalizedQuestion === $existingQuestion) {
                return (int)$row['id'];
            }

            if ($normalizedAnswer !== '' && $normalizedAnswer === $existingAnswer) {
                return (int)$row['id'];
            }
        }

        return null;
    }

    private function buildQuestionSummary(string $question): string {
        $question = $this->normalizeText($question);
        $question = preg_replace('/^(質問|依頼|相談)\s*[:：]\s*/u', '', $question) ?? $question;
        if (mb_strlen($question) > 180) {
            $question = mb_substr($question, 0, 180) . '...';
        }
        return trim($question);
    }

    private function buildAnswerSummary(string $answer): string {
        $answer = $this->removeNonFaqBlocks($answer);
        $answer = str_replace(["\r\n", "\r"], "\n", $answer);

        $lines = [];
        foreach (explode("\n", $answer) as $line) {
            $line = trim($line);
            if ($line === '' || $this->shouldSkipAnswerLine($line)) {
                continue;
            }

            $line = preg_replace('/^#{1,6}\s*/u', '', $line) ?? $line;
            $line = preg_replace('/^\s*[-*]\s*/u', '- ', $line) ?? $line;
            $line = preg_replace('/\s+/u', ' ', $line) ?? $line;

            if (mb_strlen($line) > 180) {
                $line = mb_substr($line, 0, 180) . '...';
            }

            $lines[] = $line;
            if (count($lines) >= 6) {
                break;
            }
        }

        if (!$lines) {
            $plain = $this->normalizeText($this->stripMarkdown($answer));
            if (mb_strlen($plain) > 700) {
                $plain = mb_substr($plain, 0, 700) . '...';
            }
            return $plain;
        }

        $summary = implode("\n", $lines);
        if (mb_strlen($summary) > 900) {
            $summary = mb_substr($summary, 0, 900) . '...';
        }

        return trim($summary);
    }

    private function removeNonFaqBlocks(string $text): string {
        $text = preg_replace('/```(?:json|chart|chart_data|mermaid|sql)?[\s\S]*?```/iu', '', $text) ?? $text;
        $text = preg_replace('/<object\b[\s\S]*?<\/object>/iu', '', $text) ?? $text;
        $text = preg_replace('/<canvas\b[\s\S]*?<\/canvas>/iu', '', $text) ?? $text;
        return $text;
    }

    private function shouldSkipAnswerLine(string $line): bool {
        if (preg_match('/^(data:|type:|status:|```|\{|\}|\[|\]|labels:|datasets:)/iu', $line)) {
            return true;
        }
        if (preg_match('/(Chart\.js|Mermaid|chat_debug|推論プロセス|ストリーム進行中|品質審査)/u', $line)) {
            return true;
        }
        return false;
    }

    private function stripMarkdown(string $text): string {
        $text = preg_replace('/!\[[^\]]*\]\([^)]+\)/u', '', $text) ?? $text;
        $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/u', '$1', $text) ?? $text;
        $text = preg_replace('/[*_`>#]/u', '', $text) ?? $text;
        return $text;
    }

    private function normalizeText(string $text): string {
        $text = $this->stripMarkdown($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    private function normalizeFaqKey(string $text): string {
        $text = mb_strtolower($this->normalizeText($text), 'UTF-8');
        $text = preg_replace('/[\p{Z}\p{C}\p{P}]+/u', '', $text) ?? $text;
        return trim($text);
    }

    private function logSkip(string $reason, array $meta = []): void {
        $pairs = [];
        foreach ($meta as $key => $value) {
            $pairs[] = $key . ': ' . $value;
        }
        $suffix = $pairs ? ' | ' . implode(' | ', $pairs) : '';
        $this->log("[FAQ-AUTO] 登録条件未達のためFAQ自動登録をスキップしました。reason: {$reason}{$suffix}");
    }

    private function log(string $message): void {
        if (function_exists('chatLogger')) {
            chatLogger($message);
        }
    }
}
