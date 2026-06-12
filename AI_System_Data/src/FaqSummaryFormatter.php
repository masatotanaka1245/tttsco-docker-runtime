<?php

class FaqSummaryFormatter
{
    public static function buildQuestionSummary(string $question): string
    {
        $question = self::normalizeText($question);
        $question = preg_replace('/^(質問|依頼|相談)\s*[:：]\s*/u', '', $question) ?? $question;
        if (mb_strlen($question) > 180) {
            $question = mb_substr($question, 0, 180) . '...';
        }
        return trim($question);
    }

    public static function buildAnswerSummary(string $answer): string
    {
        $answer = self::removeNonFaqBlocks($answer);
        $answer = str_replace(["\r\n", "\r"], "\n", $answer);

        $lines = [];
        foreach (explode("\n", $answer) as $line) {
            $line = trim($line);
            if ($line === '' || self::shouldSkipAnswerLine($line)) {
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

        if ($lines === []) {
            $plain = self::normalizeText(self::stripMarkdown($answer));
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

    public static function isAnswerEligible(string $answerSummary): bool
    {
        $answerText = self::normalizeText($answerSummary);
        if (mb_strlen($answerText) < 40) {
            return false;
        }

        if (preg_match('/(通信エラー|内部サーバーエラー|AIサーバー通信エラー|回答の生成に失敗|Token Limit|セッションが切れました|画像が提供されておりません|内容が確認できません)/u', $answerText)) {
            return false;
        }

        return true;
    }

    private static function removeNonFaqBlocks(string $text): string
    {
        $text = preg_replace('/```(?:json|chart|chart_data|mermaid|sql)?[\s\S]*?```/iu', '', $text) ?? $text;
        $text = preg_replace('/<object\b[\s\S]*?<\/object>/iu', '', $text) ?? $text;
        $text = preg_replace('/<canvas\b[\s\S]*?<\/canvas>/iu', '', $text) ?? $text;
        return $text;
    }

    private static function shouldSkipAnswerLine(string $line): bool
    {
        if (preg_match('/^(data:|type:|status:|```|\{|\}|\[|\]|labels:|datasets:)/iu', $line)) {
            return true;
        }
        if (preg_match('/(Chart\.js|Mermaid|chat_debug|推論プロセス|ストリーム進行中|品質審査)/u', $line)) {
            return true;
        }
        return false;
    }

    private static function stripMarkdown(string $text): string
    {
        $text = preg_replace('/!\[[^\]]*\]\([^)]+\)/u', ' ', $text) ?? $text;
        $text = preg_replace('/\[[^\]]+\]\([^)]+\)/u', '$1', $text) ?? $text;
        $text = preg_replace('/[`*_>#-]+/u', ' ', $text) ?? $text;
        return $text;
    }

    private static function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }
}
