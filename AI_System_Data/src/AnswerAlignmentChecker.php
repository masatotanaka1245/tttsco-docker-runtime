<?php

final class AnswerAlignmentChecker
{
    public static function analyze(string $question, string $answer): array
    {
        $expectedTargets = self::detectIntentTargets($question, true);
        $answerTargets = self::detectIntentTargets($answer, false);
        $mismatches = [];

        $pairs = [
            'history' => ['csv', 'pdf', 'aggregate', 'consult', 'material'],
            'csv' => ['history', 'pdf', 'consult'],
            'pdf' => ['history', 'csv', 'aggregate', 'consult'],
            'report' => ['history', 'consult', 'aggregate'],
            'consult' => ['aggregate', 'report', 'history'],
            'material' => ['pdf', 'report', 'history', 'aggregate'],
            'aggregate' => ['consult', 'history'],
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
        if (!empty($expectedTargets['consult']) && empty($answerTargets['consult']) && !empty($answerTargets['aggregate'])) {
            $mismatches[] = ['consult', 'aggregate'];
        }
        if (!empty($expectedTargets['material']) && empty($answerTargets['material']) && !empty($answerTargets['pdf'])) {
            $mismatches[] = ['material', 'pdf'];
        }
        if (!empty($expectedTargets['aggregate']) && empty($answerTargets['aggregate']) && !empty($answerTargets['consult'])) {
            $mismatches[] = ['aggregate', 'consult'];
        }

        if ($mismatches === []) {
            return [
                'has_mismatch' => false,
                'expected_targets' => self::buildActiveTargetList($expectedTargets),
                'answer_targets' => self::buildActiveTargetList($answerTargets),
                'feedback' => '',
            ];
        }

        $descriptions = [
            'history' => '会話履歴',
            'csv' => 'CSV',
            'pdf' => 'PDF/資料',
            'report' => '報告書',
            'consult' => '相談・提案',
            'material' => '資料メモ更新',
            'aggregate' => '集計・可視化',
        ];
        [$expected, $actual] = $mismatches[0];
        $feedback = '質問では ' . ($descriptions[$expected] ?? $expected) . ' を求めているのに、回答は ' . ($descriptions[$actual] ?? $actual) . ' 側の内容へずれています。質問と回答の対象を一致させてください。';

        return [
            'has_mismatch' => true,
            'expected_targets' => self::buildActiveTargetList($expectedTargets),
            'answer_targets' => self::buildActiveTargetList($answerTargets),
            'mismatch_pair' => [$expected, $actual],
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
            'consult' => false,
            'material' => false,
            'aggregate' => false,
        ];

        $targets['history'] = preg_match('/(会話内容|会話履歴|これまでの会話|チャット履歴|履歴をまとめ|対象履歴|ユーザー発言|AI回答)/u', $text) === 1;
        $targets['csv'] = preg_match('/(csv|カラム|列|対象CSVファイル数|対象レコード数|登録済みCSV|件数分布|ランキング)/u', $lower) === 1;
        $targets['pdf'] = preg_match('/(pdf|資料|図面|ページ番号|資料名|P\.[0-9]+|doc_chunks)/u', $text) === 1;
        $targets['report'] = preg_match('/(報告書|レポート|## 結論|## 分析対象|## 根拠|## 推奨アクション|## 出典)/u', $text) === 1;
        $targets['consult'] = preg_match('/(提案|相談|良い案|よい案|候補|どう進め|進め方|どう書|言い換え|おすすめ|オススメ|方針|整理しましょう|まずは次の|方向性)/u', $text) === 1;
        $targets['material'] = preg_match('/(資料メモ|markdown|mdファイル|資料に追加|追記|章立て|見出し|ドラフト|たたき台|下書き|差分更新|資料を育て)/iu', $text) === 1;
        $targets['aggregate'] = preg_match('/(集計|件数|分布|ランキング|上位|合計|平均|グラフ|チャート|一覧|表にして|可視化|ユニーク|distinct|json:chart|Mermaid|棒グラフ|折れ線|円グラフ)/iu', $text) === 1;

        if ($isQuestion) {
            if (preg_match('/(要約|まとめ|詳しくまとめ|簡潔にまとめ)/u', $text) === 1 && preg_match('/(会話|履歴|チャット)/u', $text) !== 1) {
                $targets['history'] = false;
            }
            if (preg_match('/(報告書|レポート|pdf化)/u', $text) === 1) {
                $targets['report'] = true;
            }
            if ($targets['material']) {
                $targets['pdf'] = false;
            }
            if ($targets['consult']) {
                $targets['aggregate'] = $targets['aggregate']
                    && preg_match('/(集計してください|件数を出して|分布を出して|グラフにしてください|可視化してください)/u', $text) === 1;
            }
        } else {
            if ($targets['material']) {
                $targets['pdf'] = $targets['pdf']
                    && preg_match('/(資料メモ|markdown|md|追記|章立て|見出し|差分|ドラフト)/iu', $text) !== 1;
            }
            if ($targets['consult']) {
                $targets['aggregate'] = $targets['aggregate']
                    && preg_match('/(提案|候補|進め方|どう書|言い換え|方向性|まずは次の)/u', $text) !== 1;
            }
        }

        return $targets;
    }

    private static function buildActiveTargetList(array $targets): array
    {
        return array_keys(array_filter($targets, static fn($value): bool => $value === true));
    }
}
