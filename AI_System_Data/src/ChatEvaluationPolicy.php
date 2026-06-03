<?php

class ChatEvaluationPolicy
{
    public static function shouldEvaluateNormalRag(
        string $message,
        string $response,
        string $contextText,
        int $sourceCount,
        bool $reportMode,
        bool $diagramMode
    ): array {
        if ($reportMode || $diagramMode) {
            return self::yes('output_mode_requires_quality_check');
        }

        $normalized = mb_strtolower(trim($message), 'UTF-8');
        $responseLength = mb_strlen(trim($response));
        $contextLength = mb_strlen(trim($contextText));

        if ($sourceCount === 0 && $contextLength === 0 && $responseLength < 800) {
            return self::no('light_answer_without_rag_context');
        }

        if (self::isSimpleConversational($normalized) && $responseLength < 1000) {
            return self::no('simple_conversational_answer');
        }

        if ($responseLength >= 1200) {
            return self::yes('long_answer');
        }

        if ($sourceCount > 0 && self::isEvidenceSensitive($normalized)) {
            return self::yes('evidence_sensitive_question');
        }

        if ($sourceCount >= 3 && $responseLength >= 700) {
            return self::yes('multi_source_answer');
        }

        return self::no('normal_rag_low_risk');
    }

    private static function yes(string $reason): array
    {
        return ['evaluate' => true, 'reason' => $reason];
    }

    private static function no(string $reason): array
    {
        return ['evaluate' => false, 'reason' => $reason];
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
}
