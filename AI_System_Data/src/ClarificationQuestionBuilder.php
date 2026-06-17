<?php

final class ClarificationQuestionBuilder
{
    public static function shouldAsk(array $evalResult, string $question = ''): bool
    {
        $verdict = (string)($evalResult['verdict'] ?? '');
        if (!in_array($verdict, ['need_more_data', 'reject'], true)) {
            return false;
        }

        $feedback = trim((string)($evalResult['feedback'] ?? ''));
        $mustFix = $evalResult['must_fix'] ?? [];
        if (!is_array($mustFix)) {
            $mustFix = [$mustFix];
        }
        $joinedMustFix = implode("\n", array_map('strval', $mustFix));
        $joinedText = trim($feedback . "\n" . $joinedMustFix . "\n" . (string)$question);

        if ($joinedText === '') {
            return false;
        }

        $consultationSignals = [
            '/(具体的な.*(教えて|示して|指定して)|方向性|どの側面|どの観点|何を強調|確認応答|意図表明|相談)/u',
            '/(対象(の)?ファイル|対象列|対象の列|期間|年月|日付|条件|集計軸|対象範囲)(を|が).*(必要|不明|不足)/u',
            '/(質問内容|依頼内容).*(曖昧|不明|不足|特定できない)/u',
            '/(名称|案件名|プロジェクト名).*(方向性|具体化|強調)/u',
            '/(質問と回答の対象|回答は.*側の内容へずれ|対象を一致させ)/u',
        ];

        foreach ($consultationSignals as $pattern) {
            if (preg_match($pattern, $joinedText)) {
                return true;
            }
        }

        return false;
    }

    public static function build(string $question, array $evalResult): string
    {
        $feedback = trim((string)($evalResult['feedback'] ?? ''));
        $question = trim($question);
        $mustFix = $evalResult['must_fix'] ?? [];
        if (!is_array($mustFix)) {
            $mustFix = [$mustFix];
        }

        if (preg_match('/(案件名|プロジェクト名|名称)/u', $question . "\n" . $feedback)) {
            return "確認させてください。より合う名称に整えるため、何を一番強調したいか教えてください。例えば、目的・機能・対象範囲・動作確認中であること、のどれを主役にしたいですか。";
        }

        if (preg_match('/(会話|履歴|チャット)/u', $question . "\n" . $feedback)) {
            return "確認させてください。今回は資料やCSVの要約ではなく、この会話スレッド自体の要約でよいですか。問題なければ、簡潔版か詳細版かもあわせて教えてください。";
        }

        if (preg_match('/(年月|月別|日付|期間|年別|日別)/u', $question . "\n" . $feedback)) {
            return "確認させてください。集計の解釈を合わせたいので、どの列またはどの期間を基準にしたいか教えてください。列名が分かればその名前、期間なら例えば「2025年3月」や「2025年4月〜6月」のように指定してください。";
        }

        if (preg_match('/(CSV|列名|カラム|ファイル)/u', $question . "\n" . $feedback)) {
            return "確認させてください。取り違えを避けるため、対象にしたいCSVや列名があれば教えてください。ファイル名か列名のどちらかが分かるだけでも、その条件に合わせて回答を組み直します。";
        }

        if (preg_match('/(PDF|資料|留意点|制約|確認すべき要点)/u', $question . "\n" . $feedback)) {
            return "確認させてください。資料から何を優先して拾うかを合わせたいので、留意点・制約事項・運用上の注意・確認項目のうち、特に重視したい観点があれば教えてください。";
        }

        $hints = [];
        foreach ($mustFix as $item) {
            $item = trim((string)$item);
            if ($item === '') {
                continue;
            }
            $item = preg_replace('/^(具体的に|必ず|まずは)\s*/u', '', $item);
            if ($item !== null && $item !== '') {
                $hints[] = $item;
            }
            if (count($hints) >= 2) {
                break;
            }
        }

        if (!empty($hints)) {
            return "このままだと意図を取り違える可能性があるため、確認させてください。特に次のどちらを優先したいですか。 " . implode(' / ', $hints) . "。";
        }

        return "確認させてください。より正確にお答えするため、今回のご依頼で特に重視したい対象・期間・観点をもう少し具体的に教えてください。いただいた条件に合わせて、その内容で回答を組み直します。";
    }
}
