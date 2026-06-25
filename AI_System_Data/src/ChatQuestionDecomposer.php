<?php

final class ChatQuestionDecomposer
{
    /** @var string[] */
    private const ACTION_KEYWORDS = [
        '要約', '報告書', 'レポート', '集計', 'グラフ', 'グラフ化', '整理',
        'まとめ', '更新', '作成', '確認', '分析', '抽出', '比較', '追記',
    ];

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

            $subQuestions[] = [
                'step_number' => count($subQuestions) + 1,
                'sub_query' => $subQuery,
                'intent' => $this->detectIntent($subQuery),
                'target_hint' => $this->detectTargetHint($subQuery),
                'route_hint' => $this->detectRouteHint($subQuery),
                'priority' => $index === 0 ? 'high' : 'medium',
                'save_worthy' => $this->isSaveWorthy($subQuery),
            ];
        }

        if ($subQuestions === []) {
            $subQuestions[] = [
                'step_number' => 1,
                'sub_query' => $normalizedQuestion,
                'intent' => $this->detectIntent($normalizedQuestion),
                'target_hint' => $this->detectTargetHint($normalizedQuestion),
                'route_hint' => $this->detectRouteHint($normalizedQuestion),
                'priority' => 'high',
                'save_worthy' => $this->isSaveWorthy($normalizedQuestion),
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
        $part = $this->normalizeWhitespace($part);
        if ($part === '') {
            return '';
        }

        $replacements = [
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

        $part = preg_replace('/して$/u', 'する', $part) ?? $part;
        $part = preg_replace('/し$/u', 'する', $part) ?? $part;

        return trim($part, " \t\n\r\0\x0B。");
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
        if (mb_strlen($subQuery) < 6) {
            return false;
        }

        if (preg_match('/\?|？$/u', $subQuery)) {
            return false;
        }

        if (preg_match('/どのCSV|どの列|追加情報|対象列|対象CSV/u', $subQuery)) {
            return false;
        }

        return true;
    }

    private function normalizeWhitespace(string $text): string
    {
        $text = trim((string)$text);
        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }
}
