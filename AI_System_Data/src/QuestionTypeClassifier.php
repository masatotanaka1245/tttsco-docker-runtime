<?php

final class QuestionTypeClassifier
{
    public static function classify(string $question): string
    {
        $q = mb_strtolower($question);

        $isSingleFact = preg_match('/(何件|件数|総件数|いくつ|何個|何名|何行|ありますか|わかりますか|教えてください)/u', $q);
        $isBroad = preg_match('/(月別|日別|年別|推移|比較|グラフ|図|内訳|ランキング|一覧|全て|すべて|全件|傾向|分析|まとめ|要約|分類|カテゴライズ)/u', $q);

        if ($isSingleFact && !$isBroad) {
            return 'single_fact';
        }
        if (preg_match('/(全て|すべて|全件|全体|分類|カテゴライズ|カテゴリ|どんな項目|どのような項目|どんな内容|どのような内容)/u', $q)) {
            return 'full_read_categorize';
        }
        if (preg_match('/(月別|日別|年別|推移|比較|グラフ|図|内訳|ランキング|集計|平均|合計|割合|比率|最大|最小)/u', $q)) {
            return 'aggregate_compare';
        }
        if (preg_match('/(提案|改善|留意点|考察|分析|方針|課題|どうすれば|おすすめ)/u', $q)) {
            return 'proposal_analysis';
        }

        return 'general';
    }

    public static function buildPolicy(string $questionType): string
    {
        $policies = [
            'single_fact' => "- 目的は単一の答えを正確に返すこと。\n- 余計なグラフ、比較、ランキング、追加SQL、追加検索は原則禁止。\n- Contextに答えがあるのに文章が悪いだけなら verdict は revise_text_only。\n- Contextに答えそのものが存在しない場合だけ need_more_data。",
            'aggregate_compare' => "- 集計軸、数値、比較対象が質問意図と一致しているかを重視。\n- グラフはユーザーが求めた場合、または集計比較の理解に有益で正しいデータがある場合のみ許可。\n- 必要な集計結果がContextにない場合は need_more_data。",
            'full_read_categorize' => "- 質問意図に該当するデータ全体を対象にした説明・分類・要約を重視。\n- 件数が多い場合でも、検索・抽出済みContextの範囲と限界を明示できていれば評価する。\n- 不足が検索条件や対象範囲の問題なら need_more_data。",
            'proposal_analysis' => "- 根拠データに基づく洞察、提案、次アクションを評価。\n- 根拠のない一般論や架空の事実は厳しく減点。\n- 根拠はあるが構成が悪い場合は revise_text_only。",
        ];

        return $policies[$questionType] ?? "- 質問に直接答えているか、Contextに忠実かを評価。\n- 追加データが本当に必要な場合だけ need_more_data。\n- 文章構成の問題だけなら revise_text_only。";
    }
}
