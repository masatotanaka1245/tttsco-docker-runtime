<?php

require_once __DIR__ . '/FaqSummaryFormatter.php';

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

    public function __construct(PDO $pdo, int $threshold = 92, int $duplicateScanLimit = 200) {
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
                    'evaluation_mode' => (string)($evalResult['evaluation_mode'] ?? 'none'),
                    'evaluation_source' => (string)($evalResult['evaluation_source'] ?? 'none'),
                    'score' => (int)($evalResult['total_score'] ?? 0),
                    'relevance' => (int)(($evalResult['scores']['answer_relevance'] ?? 0)),
                    'faithfulness' => (int)(($evalResult['scores']['faithfulness'] ?? 0)),
                ]);
                return false;
            }

            $questionSummary = FaqSummaryFormatter::buildQuestionSummary($question);
            $answerSummary = FaqSummaryFormatter::buildAnswerSummary($answer);

            if ($questionSummary === '' || $answerSummary === '' || !FaqSummaryFormatter::isAnswerEligible($answerSummary)) {
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

        $verdict = trim((string)($evalResult['verdict'] ?? ''));
        if ($verdict !== 'pass') {
            return ['qualified' => false, 'reason' => 'verdict_not_pass'];
        }

        if ($this->hasMismatchPair($evalResult)) {
            return ['qualified' => false, 'reason' => 'mismatch_pair'];
        }

        $feedback = $this->normalizeText((string)($evalResult['feedback'] ?? ''));
        $answerText = $this->normalizeText($answer);

        if (str_contains($feedback, '[ASK-USER-CLARIFICATION]')) {
            return ['qualified' => false, 'reason' => 'clarification_marker'];
        }

        if (str_contains($feedback, '[TEXT-ONLY-REWRITE]')) {
            return ['qualified' => false, 'reason' => 'rewrite_marker'];
        }

        if ($this->containsInsufficientEvidenceText($feedback, $answerText)) {
            return ['qualified' => false, 'reason' => 'insufficient_evidence_text'];
        }

        if ($this->isOperationalOnlyAnswer($answerText)) {
            return ['qualified' => false, 'reason' => 'operation_notice_text'];
        }

        if (($evalResult['needs_revision'] ?? true) === true) {
            return ['qualified' => false, 'reason' => 'needs_revision'];
        }

        $evaluationMode = (string)($evalResult['evaluation_mode'] ?? '');
        $evaluationSource = (string)($evalResult['evaluation_source'] ?? '');
        if (!$this->isEligibleEvaluationMode($evaluationMode, $evaluationSource)) {
            return ['qualified' => false, 'reason' => 'unsupported_evaluation_mode'];
        }

        if ($evaluationSource === 'lightweight_rule_guard') {
            return ['qualified' => false, 'reason' => 'lightweight_guard_not_promotable'];
        }

        if (preg_match('/評価プロセス|タイムアウト|フェイルセーフ|初期ドラフトを採用/u', $feedback)) {
            return ['qualified' => false, 'reason' => 'fallback_or_timeout_feedback'];
        }

        $totalScore = (int)($evalResult['total_score'] ?? 0);
        $scores = $evalResult['scores'] ?? [];
        $relevance = (int)($scores['answer_relevance'] ?? 0);
        $faithfulness = (int)($scores['faithfulness'] ?? 0);

        $minimumScore = $this->threshold;
        $minimumRelevance = 95;
        $minimumFaithfulness = 90;

        if ($evaluationSource === 'lightweight_rule_guard') {
            // deterministic 軽量ルートは score が安定している前提で少しだけ厳しめに残す
            $minimumScore = max($minimumScore, 95);
            $minimumFaithfulness = 95;
        }

        if ($totalScore < $minimumScore || $relevance < $minimumRelevance || $faithfulness < $minimumFaithfulness) {
            return ['qualified' => false, 'reason' => 'score_below_threshold'];
        }

        $questionText = $this->normalizeText($question);
        if (mb_strlen($questionText) < 8 || mb_strlen($answerText) < 120) {
            return ['qualified' => false, 'reason' => 'question_or_answer_too_short'];
        }

        if (preg_match('/(通信エラー|内部サーバーエラー|AIサーバー通信エラー|回答の生成に失敗|Token Limit|セッションが切れました)/u', $answerText)) {
            return ['qualified' => false, 'reason' => 'error_text_detected'];
        }

        $questionSummary = FaqSummaryFormatter::buildQuestionSummary($questionText);
        $answerSummary = FaqSummaryFormatter::buildAnswerSummary($answerText);
        if (FaqSummaryFormatter::looksLikeProvisionalOrOperationalAnswer($questionSummary, $answerSummary)) {
            return ['qualified' => false, 'reason' => 'provisional_or_operational_answer'];
        }
        if (FaqSummaryFormatter::looksLikeProjectSpecificAnalysis($questionSummary, $answerSummary)) {
            return ['qualified' => false, 'reason' => 'project_specific_analysis'];
        }

        return ['qualified' => true, 'reason' => 'qualified'];
    }

    private function hasMismatchPair(array $evalResult): bool {
        $mismatchPair = $evalResult['mismatch_pair'] ?? null;
        if (!is_array($mismatchPair) || count($mismatchPair) !== 2) {
            return false;
        }

        $expected = trim((string)($mismatchPair[0] ?? ''));
        $actual = trim((string)($mismatchPair[1] ?? ''));
        return $expected !== '' && $actual !== '';
    }

    private function containsInsufficientEvidenceText(string $feedback, string $answerText): bool {
        $combined = trim($feedback . "\n" . $answerText);
        if ($combined === '') {
            return false;
        }

        return preg_match(
            '/(確認させてください|指定してください|追加情報|追加の情報|もう少し詳しく|根拠不足|判断できません|情報がありません|見つかりません|情報が不足|追加検索|追加抽出|追加SQL|必要な情報.*不足|対象.*教えてください)/u',
            $combined
        ) === 1;
    }

    private function isOperationalOnlyAnswer(string $answerText): bool {
        if ($answerText === '') {
            return false;
        }

        if (
            preg_match('/(報告書|レポート|PDF).*(作成|生成|出力).*(完了|しました)|((作成|生成|出力).*(完了|しました).*(報告書|レポート|PDF))/u', $answerText) === 1
            && mb_strlen($answerText) <= 220
        ) {
            return true;
        }

        if (
            preg_match('/(アップロード|保存しました|保存してください|クリック|ボタン|画面|モーダル|タブ|ダウンロード|インポート|エクスポート)/u', $answerText) === 1
            && preg_match('/(結論|理由|根拠|概要|分析|要約|件数|集計|推奨|補足)/u', $answerText) !== 1
        ) {
            return true;
        }

        if (
            preg_match('/(CSV).*(作成|保存|出力|ダウンロード|アップロード)|((作成|保存|出力|ダウンロード|アップロード).*(CSV))/u', $answerText) === 1
            && preg_match('/(対象CSV|対象行数|値ごとの件数|集計結果|結論|概要|分析)/u', $answerText) !== 1
        ) {
            return true;
        }

        return false;
    }

    private function isEligibleEvaluationMode(string $evaluationMode, string $evaluationSource): bool {
        if ($evaluationMode === 'real') {
            return true;
        }

        return false;
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
