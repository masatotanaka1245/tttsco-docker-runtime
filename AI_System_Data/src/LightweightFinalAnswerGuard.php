<?php

require_once __DIR__ . '/ChatEvaluator.php';
require_once __DIR__ . '/AnswerAlignmentChecker.php';
require_once __DIR__ . '/EvaluationRecoveryCoordinator.php';
require_once __DIR__ . '/EvaluationResultHelper.php';

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
        $evaluator = null;
        $recoveryCoordinator = null;

        if (($policy['use_llm_judge'] ?? false) !== true) {
            $evalResult = $this->buildRuleBasedResult($question, $safeContext, $finalAnswer, $routeLabel, $policy);
        } else {
            $evaluator = new ChatEvaluator($this->ollamaHost);
            $recoveryCoordinator = new EvaluationRecoveryCoordinator($evaluator);
            $evalResult = $evaluator->evaluateDraft($question, $safeContext, $finalAnswer, $model);
        }

        if (($evalResult['needs_revision'] ?? false) === true) {
            $evaluator = $evaluator instanceof ChatEvaluator ? $evaluator : new ChatEvaluator($this->ollamaHost);
            $recoveryCoordinator = $recoveryCoordinator instanceof EvaluationRecoveryCoordinator
                ? $recoveryCoordinator
                : new EvaluationRecoveryCoordinator($evaluator);

            $recoveryResult = $recoveryCoordinator->resolve(
                $question,
                $safeContext,
                $finalAnswer,
                $model,
                $evalResult,
                [
                    'allow_text_rewrite' => ($policy['allow_llm_rewrite'] ?? true) === true,
                    'clarify_feedback_suffix' => '[LIGHTWEIGHT-FINAL-GUARD] 質問と回答対象の不一致または条件不足を検知したため、確認質問へ切り替えました。',
                    'rewrite_feedback_suffix' => '[LIGHTWEIGHT-FINAL-GUARD] 軽量ルートで既存根拠のみを使って最終回答を修正しました。',
                ]
            );

            if (($recoveryResult['action'] ?? 'none') !== 'none') {
                $finalAnswer = (string)($recoveryResult['response'] ?? $finalAnswer);
                $evalResult = $recoveryResult['eval_result'] ?? $evalResult;
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

        if ($this->isOperationOnlyAnswer($draftAnswer)) {
            $verdict = 'reject';
            $totalScore = min($totalScore, 42);
            $relevance = min($relevance, 38);
            $clarity = min($clarity, 58);
            $feedback[] = '[FINAL-GUARD-OPERATION-NOTICE] 質問と回答の対象が一致していません。回答が操作案内や画面操作の説明に偏っており、依頼への実質回答になっていません。';
            $mustFix[] = '操作手順ではなく、質問に対する結論・提案・整理結果を先に示す';
            $forbiddenActions[] = 'アップロードやクリック案内だけで回答を代用する';
        }

        if ($this->isInsufficientEvidenceAnswer($draftAnswer)) {
            $verdict = 'need_more_data';
            $totalScore = min($totalScore, 40);
            $relevance = min($relevance, 35);
            $faithfulness = min($faithfulness, 75);
            $clarity = min($clarity, 60);
            $feedback[] = '[FINAL-GUARD-INSUFFICIENT-EVIDENCE] 依頼内容に答えるには根拠または条件が不足しています。対象のファイル・列・期間・観点など、質問内容を特定する追加情報が必要です。';
            $mustFix[] = '不足している対象条件を明示し、必要なら確認質問へ切り替える';
            $forbiddenActions[] = '情報がありませんだけで最終回答として出荷する';
        }

        $intentDrift = $this->detectIntentDrift($question, $draftAnswer);
        if ($intentDrift !== null) {
            $verdict = 'reject';
            $totalScore = min($totalScore, 43);
            $relevance = min($relevance, 36);
            $faithfulness = min($faithfulness, 78);
            $clarity = min($clarity, 60);
            $feedback[] = $intentDrift['feedback'];
            $mustFix[] = $intentDrift['must_fix'];
            $forbiddenActions[] = $intentDrift['forbidden_action'];
        }

        $alignment = AnswerAlignmentChecker::analyze($question, $draftAnswer);
        $mismatchPair = is_array($alignment['mismatch_pair'] ?? null) ? $alignment['mismatch_pair'] : null;
        if (($alignment['has_mismatch'] ?? false) === true) {
            $verdict = 'reject';
            $totalScore = min($totalScore, 45);
            $relevance = min($relevance, 35);
            $faithfulness = min($faithfulness, 70);
            $clarity = min($clarity, 60);
            $feedback[] = (string)($alignment['feedback'] ?? '質問と回答の対象が一致していません。');
            $mustFix[] = '質問で求められた対象に回答対象を揃える';
            $forbiddenActions[] = '別ソースの要約で質問を代用する';
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
            'must_fix' => EvaluationResultHelper::normalizeStringList($mustFix),
            'forbidden_actions' => EvaluationResultHelper::normalizeStringList($forbiddenActions),
            'mismatch_pair' => $mismatchPair,
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

    private function isOperationOnlyAnswer(string $text): bool
    {
        $normalized = trim($text);
        if ($normalized === '') {
            return false;
        }

        $hasOperationMarkers = preg_match('/(アップロードしてください|クリックしてください|画面で確認してください|保存してください|CSVを選択してください|ファイルを選択してください|ボタンを押してください|タブを開いてください|モーダルを開いてください|ダウンロードしてください)/u', $normalized) === 1;
        $hasSubstantiveMarkers = preg_match('/(結論|理由|根拠|概要|要約|分析|提案|方針|進め方|追記|章立て|見出し|構成案|報告書|整理すると|次にすべきこと)/u', $normalized) === 1;

        return $hasOperationMarkers && !$hasSubstantiveMarkers;
    }

    private function isInsufficientEvidenceAnswer(string $text): bool
    {
        $normalized = trim($text);
        if ($normalized === '') {
            return false;
        }

        $hasInsufficientMarkers = preg_match('/(情報がありません|判断できません|見つかりません|根拠が不足しています|根拠不足です|追加情報が必要です|情報が不足しています|条件が不足しています)/u', $normalized) === 1;
        $hasSubstantiveMarkers = preg_match('/(結論|理由|根拠|概要|要約|分析|提案|方針|補足|推奨アクション)/u', $normalized) === 1;

        return $hasInsufficientMarkers && !$hasSubstantiveMarkers;
    }

    private function detectIntentDrift(string $question, string $answer): ?array
    {
        $question = trim($question);
        $answer = trim($answer);
        if ($question === '' || $answer === '') {
            return null;
        }

        $wantsConsult = preg_match('/(相談|提案|おすすめ|オススメ|どう進め|進め方|方針|整理してください|良い案|よい案)/u', $question) === 1;
        $wantsMaterial = preg_match('/(資料メモ|markdown|mdファイル|追記|章立て|見出し|ドラフト|たたき台|下書き|資料を育て)/iu', $question) === 1;
        $wantsReport = preg_match('/(報告書|レポート|報告書化|レポート化|構成案)/u', $question) === 1;

        $answerLooksAggregateOrOperation = preg_match('/(集計してください|件数を出して|グラフ化してください|CSVを選択してください|アップロードしてください|クリックしてください|保存してください)/u', $answer) === 1;
        $answerLooksPdfExplanation = preg_match('/(PDF|資料|ページ番号|資料名|P\.[0-9]+|図面)/u', $answer) === 1
            && preg_match('/(資料メモ|markdown|md|追記|章立て|見出し|ドラフト)/iu', $answer) !== 1;
        $answerLooksHistoryOnly = preg_match('/(会話履歴|これまでの会話|チャット履歴|履歴をまとめると)/u', $answer) === 1
            && preg_match('/(報告書|レポート|構成案|結論|推奨アクション|出典)/u', $answer) !== 1;

        if ($wantsConsult && $answerLooksAggregateOrOperation) {
            return [
                'feedback' => '[FINAL-GUARD-INTENT-DRIFT] 質問と回答の対象が一致していません。相談・提案依頼に対して、集計手順や操作案内へ脱線しています。',
                'must_fix' => '相談・提案としての結論や次アクションを先に示す',
                'forbidden_action' => '相談依頼を操作説明だけで代用する',
            ];
        }

        if ($wantsMaterial && $answerLooksPdfExplanation) {
            return [
                'feedback' => '[FINAL-GUARD-INTENT-DRIFT] 質問と回答の対象が一致していません。資料メモ更新の依頼に対して、PDF説明や資料の一般説明へ脱線しています。',
                'must_fix' => '資料メモへ追記すべき章・見出し・要点に回答を揃える',
                'forbidden_action' => '資料メモ更新依頼をPDF説明だけで代用する',
            ];
        }

        if ($wantsReport && ($answerLooksHistoryOnly || $this->isOperationOnlyAnswer($answer))) {
            return [
                'feedback' => '[FINAL-GUARD-INTENT-DRIFT] 質問と回答の対象が一致していません。報告書化依頼に対して、単なる履歴説明または操作案内へ脱線しています。',
                'must_fix' => '報告書や構成案としての体裁に回答対象を揃える',
                'forbidden_action' => '報告書化依頼を履歴説明や操作案内だけで代用する',
            ];
        }

        return null;
    }
}
