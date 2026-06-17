<?php

final class AnswerAlignmentChecker
{
    public static function analyze(string $question, string $answer): array
    {
        $expectedTargets = self::detectIntentTargets($question, true);
        $answerTargets = self::detectIntentTargets($answer, false);
        $mismatches = [];

        $pairs = [
            'history' => ['csv', 'pdf'],
            'csv' => ['history', 'pdf'],
            'pdf' => ['history', 'csv'],
        ];

        foreach ($pairs as $expected => $wrongTargets) {
            if (empty($expectedTargets[$expected])) {
                continue;
            }
            if (!empty($answerTargets[$expected])) {
                continue;
            }
            foreach ($wrongTargets as $wrongTarget) {
                if (!empty($answerTargets[$wrongTarget])) {
                    $mismatches[] = [$expected, $wrongTarget];
                    break;
                }
            }
        }

        if (!empty($expectedTargets['report']) && empty($answerTargets['report']) && !empty($answerTargets['history'])) {
            $mismatches[] = ['report', 'history'];
        }

        if ($mismatches === []) {
            return [
                'has_mismatch' => false,
                'expected_targets' => array_keys(array_filter($expectedTargets)),
                'answer_targets' => array_keys(array_filter($answerTargets)),
                'feedback' => '',
            ];
        }

        $descriptions = [
            'history' => '会話履歴',
            'csv' => 'CSV',
            'pdf' => 'PDF/資料',
            'report' => '報告書',
        ];
        [$expected, $actual] = $mismatches[0];
        $feedback = '質問では ' . ($descriptions[$expected] ?? $expected) . ' を求めているのに、回答は ' . ($descriptions[$actual] ?? $actual) . ' 側の内容へずれています。質問と回答の対象を一致させてください。';

        return [
            'has_mismatch' => true,
            'expected_targets' => array_keys(array_filter($expectedTargets)),
            'answer_targets' => array_keys(array_filter($answerTargets)),
            'feedback' => $feedback,
        ];
    }

    private static function detectIntentTargets(string $text, bool $isQuestion): array
    {
        $text = trim($text);
        $lower = mb_strtolower($text);

        $targets = [
            'history' => false,
            'csv' => false,
            'pdf' => false,
            'report' => false,
        ];

        $targets['history'] = preg_match('/(会話内容|会話履歴|これまでの会話|チャット履歴|履歴をまとめ|対象履歴|ユーザー発言|AI回答)/u', $text) === 1;
        $targets['csv'] = preg_match('/(csv|カラム|列|対象CSVファイル数|対象レコード数|登録済みCSV|件数分布|ランキング)/u', $lower) === 1;
        $targets['pdf'] = preg_match('/(pdf|資料|図面|ページ番号|資料名|P\.[0-9]+|doc_chunks)/u', $text) === 1;
        $targets['report'] = preg_match('/(報告書|レポート|## 結論|## 分析対象|## 根拠|## 推奨アクション|## 出典)/u', $text) === 1;

        if ($isQuestion) {
            if (preg_match('/(要約|まとめ|詳しくまとめ|簡潔にまとめ)/u', $text) === 1 && preg_match('/(会話|履歴|チャット)/u', $text) !== 1) {
                $targets['history'] = false;
            }
            if (preg_match('/(報告書|レポート|pdf化)/u', $text) === 1) {
                $targets['report'] = true;
            }
        }

        return $targets;
    }
}
