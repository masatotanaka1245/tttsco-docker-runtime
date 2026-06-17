<?php

require_once __DIR__ . '/ChatEvaluator.php';
require_once __DIR__ . '/EvaluationResultHelper.php';

final class EvaluationRecoveryCoordinator
{
    private ChatEvaluator $evaluator;

    public function __construct(ChatEvaluator $evaluator)
    {
        $this->evaluator = $evaluator;
    }

    public function resolve(
        string $question,
        string $context,
        string $draftAnswer,
        string $model,
        array $evalResult,
        array $options = []
    ): array {
        if (($evalResult['needs_revision'] ?? false) !== true) {
            return [
                'action' => 'none',
                'response' => $draftAnswer,
                'eval_result' => $evalResult,
            ];
        }

        $allowClarification = ($options['allow_clarification'] ?? true) === true;
        $allowTextRewrite = ($options['allow_text_rewrite'] ?? true) === true;
        $feedback = (string)($evalResult['feedback'] ?? '既存根拠に基づいて回答を修正してください。');
        $verdict = (string)($evalResult['verdict'] ?? 'revise_text_only');

        if ($allowClarification && $this->evaluator->shouldAskUserClarification($evalResult, $question)) {
                $clarificationQuestion = $this->evaluator->buildClarificationQuestion($question, $evalResult);
                if ($clarificationQuestion !== '') {
                    $resolvedEval = $evalResult;
                    $resolvedEval['needs_revision'] = false;
                    $resolvedEval['feedback'] = EvaluationResultHelper::appendFeedback(
                        $feedback,
                        (string)($options['clarify_feedback_suffix'] ?? '[ASK-USER-CLARIFICATION] 差し戻し内容をユーザー向け確認質問へ変換し、追加情報の取得を優先しました。')
                    );

                return [
                    'action' => 'clarify',
                    'response' => $clarificationQuestion,
                    'eval_result' => $resolvedEval,
                ];
            }
        }

        if ($allowTextRewrite && in_array($verdict, ['revise_text_only', 'reject'], true)) {
            $forbiddenActions = EvaluationResultHelper::normalizeStringList($evalResult['forbidden_actions'] ?? []);

            $rewritten = $this->evaluator->reviseDraftTextOnly(
                $question,
                $context,
                $draftAnswer,
                $feedback,
                $model,
                $forbiddenActions
            );

            if ($rewritten !== '') {
                $resolvedEval = $evalResult;
                $resolvedEval['needs_revision'] = false;
                $resolvedEval['feedback'] = EvaluationResultHelper::appendFeedback(
                    $feedback,
                    (string)($options['rewrite_feedback_suffix'] ?? '[TEXT-ONLY-REWRITE] 既存根拠のみで最終回答を修正しました。')
                );

                return [
                    'action' => 'rewrite',
                    'response' => $rewritten,
                    'eval_result' => $resolvedEval,
                ];
            }
        }

        return [
            'action' => 'none',
            'response' => $draftAnswer,
            'eval_result' => $evalResult,
        ];
    }
}
