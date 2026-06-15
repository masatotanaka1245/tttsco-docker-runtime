<?php

class FaqSummaryFormatter
{
    public static function normalizePlainText(string $text): string
    {
        return self::normalizeText(self::stripMarkdown($text));
    }

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

        $summary = self::buildStructuredSummary($lines);
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

    public static function looksLikeProvisionalOrOperationalAnswer(string $question, string $answerSummary): bool
    {
        $questionText = self::normalizePlainText($question);
        $answerText = self::normalizePlainText($answerSummary);

        if ($questionText === '' || $answerText === '') {
            return true;
        }

        if (preg_match('/(転記|統合|結合|取り込|設定|更新|削除|保存|グラフ化|ダウンロード|アップロード).*(できますか|可能ですか)/u', $questionText)) {
            return true;
        }

        if (preg_match('/(断定することはできません|情報が不足|追加の情報|ご確認いただく必要|必要があります|指定してください|確認してください|選択してください)/u', $answerText)) {
            return true;
        }

        if (preg_match('/(手順|操作|クリック|ボタン|モーダル|チェックボックス)/u', $answerText) && mb_strlen($answerText) < 220) {
            return true;
        }

        return false;
    }

    public static function looksLikeProjectSpecificAnalysis(string $question, string $answerSummary): bool
    {
        $questionText = self::normalizePlainText($question);
        $answerText = self::normalizePlainText($answerSummary);

        if (preg_match('/[A-Za-z0-9._-]+\.(csv|pdf|md)\b/u', $questionText)) {
            return true;
        }

        if (preg_match('/(対象CSV|対象ファイル数|対象行数|元レコード数|ユニーク値数|値ごとの件数|グラフ|棒グラフ|件数 列|列について)/u', $answerText)) {
            return true;
        }

        if (preg_match('/(Alli_total|AI集計表|統合データテーブル|入荷実績一覧|健康診断一覧)/u', $questionText . "\n" . $answerText)) {
            return true;
        }

        return false;
    }

    private static function removeNonFaqBlocks(string $text): string
    {
        $text = preg_replace('/```(?:json|chart|chart_data|mermaid|sql)?[\s\S]*?```/iu', '', $text) ?? $text;
        $text = preg_replace('/<object\b[\s\S]*?<\/object>/iu', '', $text) ?? $text;
        $text = preg_replace('/<canvas\b[\s\S]*?<\/canvas>/iu', '', $text) ?? $text;
        return $text;
    }

    private static function buildStructuredSummary(array $lines): string
    {
        $conclusion = $lines[0] ?? '';
        $supports = [];
        $notes = [];

        foreach (array_slice($lines, 1) as $line) {
            if (
                preg_match('/(必要があります|確認してください|指定してください|注意|前提|条件|補足|できません|未対応|制約)/u', $line)
            ) {
                if (count($notes) < 2) {
                    $notes[] = $line;
                }
                continue;
            }

            if (count($supports) < 3) {
                $supports[] = $line;
            }
        }

        $parts = [];
        if ($conclusion !== '') {
            $parts[] = '結論: ' . $conclusion;
        }
        foreach ($supports as $support) {
            $parts[] = '補足: ' . $support;
        }
        foreach ($notes as $note) {
            $parts[] = '注意: ' . $note;
        }

        return implode("\n", $parts);
    }

    private static function shouldSkipAnswerLine(string $line): bool
    {
        if (preg_match('/^(data:|type:|status:|```|\{|\}|\[|\]|labels:|datasets:)/iu', $line)) {
            return true;
        }
        if (preg_match('/(Chart\.js|Mermaid|chat_debug|推論プロセス|ストリーム進行中|品質審査|### グラフ|### 値ごとの件数一覧)/u', $line)) {
            return true;
        }
        if (preg_match('/^(ありがとうございます|承知しました|了解しました|ご質問ありがとうございます)[。！!]*$/u', $line)) {
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
