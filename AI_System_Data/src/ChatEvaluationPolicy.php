<?php

class ChatEvaluationPolicy
{
    public static function shouldEvaluateNormalRag(
        string $message,
        string $response,
        string $contextText,
        int $sourceCount,
        bool $reportMode,
        bool $diagramMode,
        array $conversationIntentProfile = []
    ): array {
        if ($reportMode || $diagramMode) {
            return self::yes('output_mode_requires_quality_check');
        }

        $normalized = mb_strtolower(trim($message), 'UTF-8');
        $responseLength = mb_strlen(trim($response));
        $contextLength = mb_strlen(trim($contextText));
        $longAnswerThreshold = self::resolveLongAnswerThreshold($conversationIntentProfile);

        if ($sourceCount === 0 && $contextLength === 0 && $responseLength < 800) {
            return self::no('light_answer_without_rag_context');
        }

        if (self::isSimpleConversational($normalized) && $responseLength < 1000) {
            return self::no('simple_conversational_answer');
        }

        if (self::isStructuredAggregationQuestion($normalized)) {
            return self::yes('structured_aggregation_question');
        }

        if ($responseLength >= $longAnswerThreshold) {
            return self::yes('long_answer', ['threshold' => $longAnswerThreshold]);
        }

        if (
            $longAnswerThreshold > 1200
            && $responseLength >= 1200
            && $responseLength < $longAnswerThreshold
        ) {
            return self::no('code_improvement_long_answer_threshold', ['threshold' => $longAnswerThreshold]);
        }

        if ($sourceCount > 0 && self::isEvidenceSensitive($normalized)) {
            return self::yes('evidence_sensitive_question');
        }

        if ($sourceCount >= 3 && $responseLength >= 700) {
            return self::yes('multi_source_answer');
        }

        return self::no('normal_rag_low_risk');
    }

    private static function yes(string $reason, array $extra = []): array
    {
        return array_merge(['evaluate' => true, 'reason' => $reason], $extra);
    }

    private static function no(string $reason, array $extra = []): array
    {
        return array_merge(['evaluate' => false, 'reason' => $reason], $extra);
    }

    private static function resolveLongAnswerThreshold(array $conversationIntentProfile): int
    {
        $userIntent = trim((string)($conversationIntentProfile['user_intent'] ?? ''));
        $expectedResponse = trim((string)($conversationIntentProfile['expected_response'] ?? ''));

        if ($userIntent === 'code_improvement' && $expectedResponse === 'implementation_plan') {
            return 3000;
        }

        return 1200;
    }

    private static function isSimpleConversational(string $message): bool
    {
        return (bool)preg_match('/(使い方|どうすれば|どこ|開き方|設定|確認|ありがとう|了解|はい|お願いします)$/u', $message)
            && !self::isEvidenceSensitive($message);
    }

    private static function isEvidenceSensitive(string $message): bool
    {
        return (bool)preg_match('/(pdf|csv|資料|根拠|出典|集計|分析|比較|留意点|報告書|レポート|図|グラフ|件数|数値|表|一覧|要約|まとめ|抽出)/iu', $message);
    }

    private static function isStructuredAggregationQuestion(string $message): bool
    {
        $hasStructuredTarget = (bool)preg_match('/(csv|列|カラム|項目|datetime|timestamp|yearmonth|name|日付|日時|年月|時間帯|時刻帯|hour)/iu', $message);
        $hasAggregationIntent = (bool)preg_match('/(集計|件数|分布|一覧|表|グラフ|チャート|抽出|多い時間帯|ピーク時間|ピーク帯|何件|何種類|ユニーク|distinct)/iu', $message);

        return $hasStructuredTarget && $hasAggregationIntent;
    }
}
