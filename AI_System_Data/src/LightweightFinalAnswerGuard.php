<?php

require_once __DIR__ . '/ChatEvaluator.php';

class LightweightFinalAnswerGuard
{
    private string $ollamaHost;

    public function __construct(string $ollamaHost)
    {
        $this->ollamaHost = rtrim($ollamaHost, '/');
    }

    public function review(
        string $question,
        string $context,
        string $draftAnswer,
        string $model,
        string $routeLabel,
        array $options = []
    ): array {
        $safeContext = trim($context) !== '' ? $context : '軽量ルートの根拠コンテキストは空です。既存ドラフトのみを確認してください。';
        $policy = $this->buildPolicy($question, $safeContext, $draftAnswer, $routeLabel, $options);
        $finalAnswer = trim($draftAnswer);

        if (($policy['use_llm_judge'] ?? false) !== true) {
            $evalResult = $this->buildRuleBasedResult($question, $safeContext, $finalAnswer, $routeLabel, $policy);
        } else {
            $evaluator = new ChatEvaluator($this->ollamaHost);
            $evalResult = $evaluator->evaluateDraft($question, $safeContext, $finalAnswer, $model);

            if (($evalResult['needs_revision'] ?? false) === true && ($policy['allow_llm_rewrite'] ?? true)) {
                $feedback = (string)($evalResult['feedback'] ?? '既存根拠だけで最終回答を調整してください。');
                $forbiddenActions = $evalResult['forbidden_actions'] ?? [];
                if (!is_array($forbiddenActions)) {
                    $forbiddenActions = [$forbiddenActions];
                }

                $rewritten = $evaluator->reviseDraftTextOnly(
                    $question,
                    $safeContext,
                    $finalAnswer,
                    $feedback,
                    $model,
                    $forbiddenActions
                );

                if ($rewritten !== '') {
                    $finalAnswer = $rewritten;
                    $evalResult['needs_revision'] = false;
                    $evalResult['feedback'] = $feedback . "\n[LIGHTWEIGHT-FINAL-GUARD] 軽量ルートで既存根拠のみを使って最終回答を修正しました。";
                }
            }
        }

        if (function_exists('chatLogger')) {
            $evaluationMode = (string)($evalResult['evaluation_mode'] ?? 'unknown');
            $evaluationSource = (string)($evalResult['evaluation_source'] ?? 'unknown');
            $verdict = (string)($evalResult['verdict'] ?? 'unknown');
            $score = (int)($evalResult['total_score'] ?? 0);
            $relevance = (int)($evalResult['scores']['answer_relevance'] ?? 0);
            $faithfulness = (int)($evalResult['scores']['faithfulness'] ?? 0);

            chatLogger("[FINAL-GUARD] route={$routeLabel} | source={$evaluationSource} | mode={$evaluationMode} | verdict={$verdict} | score={$score} | relevance={$relevance} | faithfulness={$faithfulness}");

            if ($finalAnswer !== $draftAnswer) {
                chatLogger("[FINAL-GUARD-REWRITE] route={$routeLabel} | responseChars=" . mb_strlen($finalAnswer));
            }
        }

        return [
            'response' => $finalAnswer,
            'eval_result' => $evalResult,
        ];
    }

    private function buildPolicy(
        string $question,
        string $context,
        string $draftAnswer,
        string $routeLabel,
        array $options
    ): array {
        $containsRenderableChart = $this->containsRenderableChart($draftAnswer);
        $isHistorySummary = $routeLabel === 'history_summary';
        $isLightweightDataRoute = $routeLabel === 'data_analysis_lightweight';
        $isLightweightDocRoute = $routeLabel === 'advanced_lightweight_doc_final';

        $policy = [
            'use_llm_judge' => true,
            'allow_llm_rewrite' => true,
            'chart_expected' => $this->isChartRequest($question),
            'contains_renderable_chart' => $containsRenderableChart,
            'requires_title_and_page' => $this->questionRequiresTitleAndPage($question),
            'requires_strict_evidence_only' => $this->questionRequiresStrictEvidenceOnly($question),
        ];

        if ($isHistorySummary || $isLightweightDataRoute || $isLightweightDocRoute) {
            $policy['use_llm_judge'] = false;
            $policy['allow_llm_rewrite'] = false;
        }

        if ($containsRenderableChart) {
            $policy['use_llm_judge'] = false;
            $policy['allow_llm_rewrite'] = false;
        }

        if (array_key_exists('use_llm_judge', $options)) {
            $policy['use_llm_judge'] = (bool)$options['use_llm_judge'];
        }
        if (array_key_exists('allow_llm_rewrite', $options)) {
            $policy['allow_llm_rewrite'] = (bool)$options['allow_llm_rewrite'];
        }

        return $policy;
    }

    private function buildRuleBasedResult(
        string $question,
        string $context,
        string $draftAnswer,
        string $routeLabel,
        array $policy
    ): array {
        $verdict = 'pass';
        $totalScore = 96;
        $feedback = [];
        $mustFix = [];
        $forbiddenActions = [];
        $relevance = 96;
        $faithfulness = 98;
        $clarity = 94;
        $proactivity = 94;

        if (($policy['chart_expected'] ?? false) && !$this->containsRenderableChart($draftAnswer)) {
            $verdict = 'revise_text_only';
            $totalScore = 84;
            $relevance = 80;
            $clarity = 84;
            $feedback[] = 'グラフ要求に対して、描画可能な `json:chart` ブロックが見当たりません。';
            $mustFix[] = '図表要求時は deterministic な `json:chart` を維持する';
            $forbiddenActions[] = '既存の件数を推測で書き換える';
        }

        if (($policy['requires_title_and_page'] ?? false) && !$this->hasTitleAndPageBullets($draftAnswer)) {
            $verdict = 'revise_text_only';
            $totalScore = min($totalScore, 82);
            $relevance = min($relevance, 80);
            $clarity = min($clarity, 82);
            $feedback[] = '資料名とページ番号付きの箇条書き形式が不足しています。';
            $mustFix[] = '各留意点に資料名とページ番号を添える';
        }

        if (($policy['requires_strict_evidence_only'] ?? false) && $this->looksLikeGenericAdvice($draftAnswer)) {
            $verdict = 'revise_text_only';
            $totalScore = min($totalScore, 80);
            $relevance = min($relevance, 80);
            $faithfulness = min($faithfulness, 80);
            $feedback[] = '根拠だけを求める質問に対して、一般論や推測が混ざっている可能性があります。';
            $mustFix[] = '根拠断片にない一般論を足さない';
            $forbiddenActions[] = '法規名や設計方針の推測追加';
        }

        if (trim($draftAnswer) === '') {
            $verdict = 'revise_text_only';
            $totalScore = min($totalScore, 70);
            $relevance = min($relevance, 70);
            $clarity = min($clarity, 70);
            $feedback[] = '最終回答が空です。';
            $mustFix[] = '空回答のまま出荷しない';
        }

        if (empty($feedback)) {
            $feedback[] = $this->buildPassFeedback($routeLabel);
        }

        return [
            'question_type' => 'lightweight_rule_guard',
            'verdict' => $verdict,
            'evaluation_mode' => 'rule',
            'evaluation_source' => 'lightweight_rule_guard',
            'scores' => [
                'proactivity' => $proactivity,
                'faithfulness' => $faithfulness,
                'answer_relevance' => $relevance,
                'clarity' => $clarity,
            ],
            'total_score' => $totalScore,
            'feedback' => implode(' ', $feedback),
            'next_action' => $verdict === 'pass' ? '' : '既存根拠だけで出力形式と質問適合性を再確認する',
            'sql_hint' => '',
            'must_fix' => array_values(array_unique($mustFix)),
            'forbidden_actions' => array_values(array_unique($forbiddenActions)),
            'needs_revision' => $verdict !== 'pass',
        ];
    }

    private function buildPassFeedback(string $routeLabel): string
    {
        if ($routeLabel === 'history_summary') {
            return '履歴サマリーの軽量ルートとして、質問意図と出力形式をルールベースで確認しました。';
        }
        if ($routeLabel === 'advanced_lightweight_doc_final') {
            return '資料PDF向け軽量最終回答として、根拠優先・形式遵守をルールベースで確認しました。';
        }
        return '軽量ルートの最終回答として、質問適合性と出力形式をルールベースで確認しました。';
    }

    private function isChartRequest(string $question): bool
    {
        return preg_match('/(グラフ|チャート|chart|図にして|可視化)/iu', $question) === 1;
    }

    private function containsRenderableChart(string $text): bool
    {
        return preg_match('/```(?:json:chart|json:chart_data|mermaid)/u', $text) === 1;
    }

    private function questionRequiresTitleAndPage(string $question): bool
    {
        return preg_match('/(資料名.*ページ番号|ページ番号.*資料名|資料名とページ番号|ページ番号付き|ページ付き|P\.[0-9]|3点だけ|3件だけ)/u', $question) === 1;
    }

    private function questionRequiresStrictEvidenceOnly(string $question): bool
    {
        return preg_match('/(根拠だけ|推測は入れない|推測しない|資料にあることだけ|根拠のみ)/u', $question) === 1;
    }

    private function hasTitleAndPageBullets(string $text): bool
    {
        return preg_match('/^- \[[^\]\n]+\/\s*P\.[0-9]+\]/mu', $text) === 1;
    }

    private function looksLikeGenericAdvice(string $text): bool
    {
        return preg_match('/(建築基準法|消防法|用途地域|耐震性|シックハウス|最新の基準|専門家との連携|空調負荷計算)/u', $text) === 1;
    }
}
