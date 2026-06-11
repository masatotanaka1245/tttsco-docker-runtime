<?php

class SqlFailureAnswerFormatter
{
    public static function buildFailureAnswer(string $error, int $maxLength = 240): string
    {
        $compactError = preg_replace("/\s+/u", ' ', trim($error));
        if (!is_string($compactError) || $compactError === '') {
            $compactError = '不明なエラー。';
        }

        if ($maxLength > 0 && mb_strlen($compactError, 'UTF-8') > $maxLength) {
            $compactError = mb_substr($compactError, 0, $maxLength - 1, 'UTF-8') . '…';
        }

        return "⚠️ 集計を完了できませんでした。\n\nエラー: {$compactError}";
    }

    public static function buildFallbackPrefix(bool $usedFallbackSql): string
    {
        return $usedFallbackSql
            ? "【補足】自動修復では開通しなかったため、定番SQLで集計しました。\n\n"
            : '';
    }
}
