<?php

final class ChatQuestionDecomposer
{
    /** @var string[] */
    private const ACTION_KEYWORDS = [
        '要約', '報告書', 'レポート', '集計', 'グラフ', 'グラフ化', '整理',
        'まとめ', '更新', '作成', '確認', '分析', '抽出', '比較', '追記',
    ];

    private const SAVE_WORTHY_SHORT_TASK_PATTERN = '/(要約|集計|整理|更新|作成|確認|分析|抽出|比較|追記|報告書|レポート|グラフ)/u';

    public function decompose(string $question): array
    {
        $normalizedQuestion = $this->normalizeWhitespace($question);
        if ($normalizedQuestion === '') {
            return [
                'original_question' => '',
                'sub_questions' => [],
            ];
        }

        $parts = $this->splitIntoParts($normalizedQuestion);
        $subQuestions = [];
        foreach ($parts as $index => $part) {
            $subQuery = $this->normalizeSubQuery($part);
            if ($subQuery === '') {
                continue;
            }

            $saveAnalysis = $this->analyzeSaveWorthiness($subQuery);

            $subQuestions[] = [
                'step_number' => count($subQuestions) + 1,
                'sub_query' => $subQuery,
                'intent' => $this->detectIntent($subQuery),
                'target_hint' => $this->detectTargetHint($subQuery),
                'route_hint' => $this->detectRouteHint($subQuery),
                'priority' => $index === 0 ? 'high' : 'medium',
                'save_worthy' => (bool)$saveAnalysis['save_worthy'],
                'save_reason' => $saveAnalysis['save_reason'],
                'skip_reason' => $saveAnalysis['skip_reason'],
                'task_text_normalized' => $saveAnalysis['task_text_normalized'],
                'request_mode' => $saveAnalysis['request_mode'],
                'update_policy' => $saveAnalysis['update_policy'],
            ];
        }

        if ($subQuestions === []) {
            $saveAnalysis = $this->analyzeSaveWorthiness($normalizedQuestion);
            $subQuestions[] = [
                'step_number' => 1,
                'sub_query' => $normalizedQuestion,
                'intent' => $this->detectIntent($normalizedQuestion),
                'target_hint' => $this->detectTargetHint($normalizedQuestion),
                'route_hint' => $this->detectRouteHint($normalizedQuestion),
                'priority' => 'high',
                'save_worthy' => (bool)$saveAnalysis['save_worthy'],
                'save_reason' => $saveAnalysis['save_reason'],
                'skip_reason' => $saveAnalysis['skip_reason'],
                'task_text_normalized' => $saveAnalysis['task_text_normalized'],
                'request_mode' => $saveAnalysis['request_mode'],
                'update_policy' => $saveAnalysis['update_policy'],
            ];
        }

        return [
            'original_question' => $normalizedQuestion,
            'sub_questions' => $subQuestions,
        ];
    }

    /**
     * @return string[]
     */
    private function splitIntoParts(string $question): array
    {
        if (!$this->looksCompound($question)) {
            return [$question];
        }

        $working = $question;
        $working = preg_replace('/(?:その後|続けて|次に|さらに|あわせて|合わせて)/u', '<<STEP>>', $working) ?? $working;
        $working = preg_replace('/(?:してから|したうえで)/u', '<<STEP>>', $working) ?? $working;
        if ($this->countActionKeywords($working) >= 2) {
            $working = preg_replace('/、/u', '<<STEP>>', $working) ?? $working;
        }

        $parts = array_values(array_filter(array_map(
            fn(string $part): string => trim($part),
            explode('<<STEP>>', $working)
        ), static fn(string $part): bool => $part !== ''));

        if (count($parts) <= 1) {
            return [$question];
        }

        return $parts;
    }

    private function looksCompound(string $question): bool
    {
        if (preg_match('/(?:その後|続けて|次に|さらに|あわせて|合わせて|してから|したうえで)/u', $question)) {
            return true;
        }

        return mb_substr_count($question, '、') >= 1 && $this->countActionKeywords($question) >= 2;
    }

    private function countActionKeywords(string $text): int
    {
        $count = 0;
        foreach (self::ACTION_KEYWORDS as $keyword) {
            if (mb_strpos($text, $keyword) !== false) {
                $count++;
            }
        }

        return $count;
    }

    private function normalizeSubQuery(string $part): string
    {
        $part = $this->trimTaskText($this->normalizeWhitespace($part));
        if ($part === '') {
            return '';
        }

        $replacements = [
            '/にしてください$/u' => 'にする',
            '/出してください$/u' => '出す',
            '/してください$/u' => 'する',
            '/グラフにして$/u' => 'グラフ化する',
            '/要点も?まとめてください$/u' => '要点をまとめる',
            '/まとめてください$/u' => 'まとめる',
            '/整理してください$/u' => '整理する',
            '/要約してください$/u' => '要約する',
            '/更新してください$/u' => '更新する',
            '/作成してください$/u' => '作成する',
            '/集計してください$/u' => '集計する',
        ];
        foreach ($replacements as $pattern => $replacement) {
            $updated = preg_replace($pattern, $replacement, $part);
            if (is_string($updated) && $updated !== $part) {
                $part = $updated;
                break;
            }
        }

        $terminalVerbReplacements = [
            '/決めて$/u' => '決める',
            '/見て$/u' => '見る',
            '/開いて$/u' => '開く',
        ];
        foreach ($terminalVerbReplacements as $pattern => $replacement) {
            $updated = preg_replace($pattern, $replacement, $part);
            if (is_string($updated) && $updated !== $part) {
                $part = $updated;
                break;
            }
        }

        $part = preg_replace('/して$/u', 'する', $part) ?? $part;
        $part = preg_replace('/し$/u', 'する', $part) ?? $part;

        return $this->trimTaskText($part);
    }

    private function detectIntent(string $subQuery): string
    {
        $patterns = [
            'report' => '/報告書|レポート|PDF化/u',
            'summarize' => '/要約|まとめ/u',
            'aggregate' => '/集計|件数|合計|月別|日別/u',
            'visualize' => '/グラフ|グラフ化|チャート/u',
            'update' => '/更新|追記|修正/u',
            'analyze' => '/分析|傾向|比較/u',
            'organize' => '/整理|確認/u',
        ];

        foreach ($patterns as $intent => $pattern) {
            if (preg_match($pattern, $subQuery)) {
                return $intent;
            }
        }

        return 'general';
    }

    private function detectTargetHint(string $subQuery): string
    {
        if (preg_match('/CSV|csv|一覧表|データ/u', $subQuery)) {
            return 'csv';
        }
        if (preg_match('/資料|PDF|pdf|資料メモ/u', $subQuery)) {
            return 'document';
        }
        if (preg_match('/会話履歴|履歴|チャット/u', $subQuery)) {
            return 'history';
        }

        return 'general';
    }

    private function detectRouteHint(string $subQuery): string
    {
        if (preg_match('/会話履歴|履歴/u', $subQuery) && preg_match('/報告書|レポート/u', $subQuery)) {
            return 'advanced_hybrid.history_report';
        }
        if (preg_match('/CSV|csv|一覧表|集計|件数|月別|日別/u', $subQuery)) {
            return 'data_analysis.csv_agg';
        }
        if (preg_match('/資料|PDF|pdf|資料メモ/u', $subQuery)) {
            return 'advanced_hybrid.doc_extract';
        }

        return 'normal_rag';
    }

    private function isSaveWorthy(string $subQuery): bool
    {
        return (bool)$this->analyzeSaveWorthiness($subQuery)['save_worthy'];
    }

    /**
     * @return array{
     *   save_worthy:bool,
     *   save_reason:?string,
     *   skip_reason:?string,
     *   task_text_normalized:string,
     *   request_mode:string,
     *   update_policy:string
     * }
     */
    private function analyzeSaveWorthiness(string $subQuery): array
    {
        $normalized = $this->trimTaskText($this->normalizeWhitespace($subQuery));
        $requestMode = $this->detectRequestMode($normalized);
        $updatePolicy = $this->detectUpdatePolicy($requestMode, $normalized);
        if ($normalized === '') {
            return [
                'save_worthy' => false,
                'save_reason' => null,
                'skip_reason' => 'empty_task',
                'task_text_normalized' => '',
                'request_mode' => 'unknown',
                'update_policy' => 'todo_candidate_denied',
            ];
        }

        if ($updatePolicy !== 'todo_candidate_allowed') {
            return [
                'save_worthy' => false,
                'save_reason' => null,
                'skip_reason' => $updatePolicy,
                'task_text_normalized' => $normalized,
                'request_mode' => $requestMode,
                'update_policy' => $updatePolicy,
            ];
        }

        if (mb_strlen($normalized) < 6 && preg_match(self::SAVE_WORTHY_SHORT_TASK_PATTERN, $normalized) !== 1) {
            return [
                'save_worthy' => false,
                'save_reason' => null,
                'skip_reason' => 'too_short',
                'task_text_normalized' => $normalized,
                'request_mode' => $requestMode,
                'update_policy' => $updatePolicy,
            ];
        }

        if (preg_match('/\?|？$/u', $normalized)) {
            return [
                'save_worthy' => false,
                'save_reason' => null,
                'skip_reason' => 'question_like',
                'task_text_normalized' => $normalized,
                'request_mode' => $requestMode,
                'update_policy' => $updatePolicy,
            ];
        }

        if (preg_match('/どのCSV|どの列|追加情報|対象列|対象CSV/u', $normalized)) {
            return [
                'save_worthy' => false,
                'save_reason' => null,
                'skip_reason' => 'clarification_request',
                'task_text_normalized' => $normalized,
                'request_mode' => $requestMode,
                'update_policy' => $updatePolicy,
            ];
        }

        return [
            'save_worthy' => true,
            'save_reason' => $this->resolveSaveReason($normalized),
            'skip_reason' => null,
            'task_text_normalized' => $normalized,
            'request_mode' => $requestMode,
            'update_policy' => $updatePolicy,
        ];
    }

    private function detectRequestMode(string $subQuery): string
    {
        if ($subQuery === '') {
            return 'unknown';
        }

        if ($this->looksLikeClarificationRequest($subQuery)) {
            return 'clarification';
        }

        if (preg_match('/(前提を確認する|関連資料を探す|回答を整える|根拠を確認する|一旦内容を整理|一度内容を整理|とりあえず整理)/u', $subQuery) === 1) {
            return 'ephemeral';
        }

        if (preg_match('/(報告書|レポート|PDF|Markdown|markdown|CSVに出力|CSV出力|出力する)/u', $subQuery) === 1) {
            return 'artifact';
        }

        if ($this->looksLikeConsultationAdviceRequest($subQuery)) {
            return 'consultation';
        }

        if (preg_match('/(要約|集計|整理|更新|作成|確認|分析|抽出|比較|追記|まとめる|出す|確認する)/u', $subQuery) === 1) {
            return 'command';
        }

        return 'unknown';
    }

    private function looksLikeConsultationAdviceRequest(string $subQuery): bool
    {
        return preg_match(
            '/(現在の進行中タスク|(?:次に)?何をすべき|今のTODO|方針としてはどう思う|どう思いますか|進めて大丈夫|大丈夫ですか|問題はありますか|問題ないですか|方針に問題|どこから手を付けるべき|どこから着手|何から手を付ける|この案件で、?まずどこから見れば|まずどこから見れば|どこから見れば|何から見れば|どこから分析すれば|まず何を確認すべき|分析の進め方を教えて|どのような観点で見れば|どう分析すれば|どのように分析したら|どう見れば)/u',
            $subQuery
        ) === 1;
    }

    private function looksLikeClarificationRequest(string $subQuery): bool
    {
        if (preg_match('/(どのCSV|どの列|どれを見れば|どれを見る|これを(?:まとめる|整理する|要約する)|このデータを見てください)/u', $subQuery) === 1) {
            return true;
        }

        if (preg_match('/^(集計する|集計してください)$/u', $subQuery) === 1) {
            return true;
        }

        if ($this->hasMonthOnlyReference($subQuery) && !$this->hasExplicitAggregationInstruction($subQuery)) {
            return true;
        }

        return false;
    }

    private function hasMonthOnlyReference(string $subQuery): bool
    {
        return preg_match('/(?:^|[^0-9])(?:\d{1,2}|今|先|来)月分/u', $subQuery) === 1;
    }

    private function hasExplicitAggregationInstruction(string $subQuery): bool
    {
        return preg_match('/(月別に集計|日別に集計|売上|件数|合計|平均|CSV|列|カラム|一覧表|データ|集計(?:する|して))/u', $subQuery) === 1;
    }

    private function detectUpdatePolicy(string $requestMode, string $subQuery): string
    {
        if ($requestMode === 'consultation') {
            return 'read_only';
        }

        if ($requestMode === 'clarification') {
            return 'clarification_required';
        }

        if ($requestMode === 'ephemeral') {
            return 'ephemeral_only';
        }

        if ($requestMode === 'command' || $requestMode === 'artifact') {
            return 'todo_candidate_allowed';
        }

        if ($subQuery === '') {
            return 'todo_candidate_denied';
        }

        return 'todo_candidate_allowed';
    }

    private function resolveSaveReason(string $subQuery): string
    {
        if (preg_match('/報告書|レポート|PDF|作成|グラフ|グラフ化/u', $subQuery)) {
            return 'artifact_request';
        }

        if (preg_match('/分析|集計|比較|抽出/u', $subQuery)) {
            return 'analysis_task';
        }

        return 'decomposition_save_worthy';
    }

    private function normalizeWhitespace(string $text): string
    {
        $text = trim((string)$text);
        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }

    private function trimTaskText(string $text): string
    {
        $text = trim($text);
        return preg_replace('/[。．]+$/u', '', $text) ?? $text;
    }
}
