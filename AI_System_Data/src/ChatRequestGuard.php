<?php

class ChatRequestGuard
{
    public static function inspect(string $message, ?int $projectId, bool $reportMode, bool $diagramMode): array
    {
        $message = trim($message);
        $normalized = self::normalize($message);
        $length = mb_strlen($normalized);

        if ($message === '') {
            return self::block('empty', 'メッセージが空です。質問内容を入力してください。');
        }

        if (self::isGreeting($normalized)) {
            return [
                'action' => 'direct_reply',
                'reason' => 'greeting',
                'response' => 'こんにちは。案件の資料、CSV、PDF、会話履歴について質問できます。必要な内容を入力してください。',
                'mode_used' => 'input_guard_greeting',
            ];
        }

        if (self::looksLikeAccidentalInput($message, $normalized)) {
            $hint = $reportMode
                ? '報告書モードでは「どの資料をもとに、何をまとめるか」をもう少し具体的に入力してください。'
                : '質問内容が短すぎるか不明確です。PDF、CSV、案件情報など、確認したい内容をもう少し具体的に入力してください。';

            return self::block('ambiguous_short_input', $hint);
        }

        if ($reportMode && !self::hasReportIntent($normalized)) {
            return self::block(
                'report_intent_missing',
                '報告書モードでPDFを作成するには、報告書にまとめたい内容を具体的に入力してください。例: 「登録PDFとCSVをもとに、主要な留意点を報告書としてまとめてください」。'
            );
        }

        return [
            'action' => 'continue',
            'reason' => 'ok',
            'response' => '',
            'mode_used' => '',
        ];
    }

    private static function block(string $reason, string $response): array
    {
        return [
            'action' => 'clarification',
            'reason' => $reason,
            'response' => $response,
            'mode_used' => 'input_guard_' . $reason,
        ];
    }

    private static function normalize(string $message): string
    {
        $message = mb_strtolower($message, 'UTF-8');
        $message = preg_replace('/[\s　]+/u', '', $message) ?? $message;
        return trim($message);
    }

    private static function isGreeting(string $normalized): bool
    {
        return (bool)preg_match('/^(こんにちは|こんばんは|おはよう|お疲れさま|お疲れ様|ありがとう|ありがとうございます|よろしく|お願いします|了解|はい)$/u', $normalized);
    }

    private static function looksLikeAccidentalInput(string $raw, string $normalized): bool
    {
        $contentOnly = preg_replace('/[、。,.，．!！?？「」『』【】\[\]()（）\-_ー〜~\s　]/u', '', $normalized) ?? $normalized;
        if (mb_strlen($contentOnly) <= 2) {
            return true;
        }

        if (mb_strlen($contentOnly) <= 5 && !preg_match('/(pdf|csv|sql|図|表|件|数|列|資料|案件|会話|報告|集計)/iu', $contentOnly)) {
            return true;
        }

        if (preg_match('/^(サイド|さいど|再度|つづき|続き|あ|ああ|test|テスト)$/iu', $contentOnly)) {
            return true;
        }

        return false;
    }

    private static function hasReportIntent(string $normalized): bool
    {
        if (mb_strlen($normalized) < 12) {
            return false;
        }

        return (bool)preg_match('/(報告書|レポート|まとめ|要約|整理|留意点|分析|考察|集計|pdf|csv|資料|案件|作成|出力)/iu', $normalized);
    }
}
