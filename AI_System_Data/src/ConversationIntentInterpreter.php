<?php

final class ConversationIntentInterpreter
{
    /**
     * @param array<int, array<string, mixed>> $recentHistory
     * @param array<int, array<string, mixed>> $decompositionSteps
     * @return array<string, mixed>
     */
    public function interpret(
        string $message,
        array $recentHistory = [],
        array $decompositionSteps = [],
        ?int $projectId = null,
        ?int $threadId = null
    ): array {
        $normalizedMessage = $this->normalizeText($message);
        $primaryStep = $this->extractPrimaryStep($decompositionSteps);
        $requestMode = trim((string)($primaryStep['request_mode'] ?? ''));
        $updatePolicy = trim((string)($primaryStep['update_policy'] ?? ''));
        $targetHint = trim((string)($primaryStep['target_hint'] ?? ''));

        $conversationRelation = $this->detectConversationRelation($normalizedMessage, $recentHistory);
        $topicShift = $this->detectTopicShift($normalizedMessage, $conversationRelation);
        $requestType = $this->detectRequestType($normalizedMessage, $conversationRelation, $requestMode);
        $userIntent = $this->detectUserIntent($normalizedMessage, $requestType, $targetHint);
        $expectedResponse = $this->detectExpectedResponse($normalizedMessage, $conversationRelation, $requestType, $userIntent);
        $contextDependency = $this->detectContextDependency($normalizedMessage, $conversationRelation, $recentHistory);
        $needsClarification = $this->detectNeedsClarification($normalizedMessage, $requestType, $userIntent, $targetHint);
        $needsAction = $this->detectNeedsAction($conversationRelation, $requestType, $userIntent);
        $todoPolicyHint = $this->detectTodoPolicyHint(
            $conversationRelation,
            $requestType,
            $userIntent,
            $needsClarification,
            $updatePolicy
        );
        $needsTodo = $needsAction && $todoPolicyHint === 'allow';
        $answerStrategy = $this->detectAnswerStrategy(
            $conversationRelation,
            $requestType,
            $userIntent,
            $expectedResponse,
            $needsClarification
        );

        return [
            'conversation_relation' => $conversationRelation,
            'topic_shift' => $topicShift,
            'user_intent' => $userIntent,
            'request_type' => $requestType,
            'expected_response' => $expectedResponse,
            'context_dependency' => $contextDependency,
            'needs_action' => $needsAction,
            'needs_todo' => $needsTodo,
            'needs_clarification' => $needsClarification,
            'todo_policy_hint' => $todoPolicyHint,
            'answer_strategy' => $answerStrategy,
            'project_id' => $projectId,
            'thread_id' => $threadId,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $decompositionSteps
     * @return array<string, mixed>
     */
    private function extractPrimaryStep(array $decompositionSteps): array
    {
        foreach ($decompositionSteps as $step) {
            if (is_array($step)) {
                return $step;
            }
        }

        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $recentHistory
     */
    private function detectConversationRelation(string $message, array $recentHistory): string
    {
        if ($message === '') {
            return 'unknown';
        }

        if ($this->matchesAny($message, [
            '/(現在の進行中タスク|次に何をすべき|次はどうしましょう|今のTODO)/u',
        ])) {
            return 'status_check';
        }

        if ($this->matchesAny($message, [
            '/(やっぱり前の話に戻りたい|前の話に戻りたい|一旦そこは置いて|そこは置いて|元の話に戻りたい|戻っても良いでしょうか|戻ってもよいでしょうか)/u',
        ])) {
            return 'rollback';
        }

        if ($this->matchesAny($message, [
            '/(それは違います|違います。|違います、|因数分解に戻りたい|前提が違う|その解釈は違う)/u',
        ])) {
            return 'correction';
        }

        if ($this->matchesAny($message, [
            '/^(はい|了解|お願いします|その方向で進めてください|それでお願いします|続けてください|続けましょう|次に進みますか)/u',
        ])) {
            return 'follow_up';
        }

        if (!empty($recentHistory) && mb_strlen($message) <= 18 && $this->matchesAny($message, [
            '/^(それ|これ|さっきの|前のやつ|前の件|その件)/u',
        ])) {
            return 'follow_up';
        }

        return 'new_request';
    }

    private function detectTopicShift(string $message, string $conversationRelation): bool
    {
        if (in_array($conversationRelation, ['rollback', 'correction'], true)) {
            return true;
        }

        return $this->matchesAny($message, [
            '/(別の話|他の話|話を変えて|別件|別の観点)/u',
        ]);
    }

    private function detectRequestType(string $message, string $conversationRelation, string $requestMode): string
    {
        if ($conversationRelation === 'status_check') {
            return 'consultation';
        }

        if (in_array($conversationRelation, ['rollback', 'correction'], true)) {
            return 'consultation';
        }

        if ($requestMode === 'clarification' || $this->looksLikeClarification($message)) {
            return 'clarification';
        }

        if ($requestMode === 'artifact' || $this->looksLikeArtifact($message)) {
            return 'artifact';
        }

        if ($requestMode === 'consultation' || $this->looksLikeConsultation($message)) {
            return 'consultation';
        }

        if ($this->looksLikeAnswerOnlyRequest($message)) {
            return 'answer_only';
        }

        if ($conversationRelation === 'follow_up') {
            return 'command';
        }

        if ($requestMode === 'command' || $this->looksLikeCommand($message)) {
            return 'command';
        }

        return 'unknown';
    }

    private function detectUserIntent(string $message, string $requestType, string $targetHint): string
    {
        if ($requestType === 'consultation') {
            return 'consultation';
        }

        if ($requestType === 'artifact') {
            return 'create_artifact';
        }

        if ($this->looksLikeCodeImprovementCommand($message)) {
            return 'code_improvement';
        }

        if ($this->matchesAny($message, [
            '/(会話履歴|これまでの会話|チャット履歴|履歴を要約)/u',
        ])) {
            return 'summarize_history';
        }

        if ($this->matchesAny($message, [
            '/(CSV|csv|一覧表|売上|件数|月別|日別).*(集計|分析)|((集計|分析).*(CSV|csv|一覧表|売上|件数|月別|日別))/u',
        ])) {
            return 'aggregate_data';
        }

        if ($targetHint === 'document' || $this->matchesAny($message, [
            '/(この資料|資料メモ|PDF|pdf|要点|資料を要約)/u',
        ])) {
            return 'inspect_document';
        }

        if ($requestType === 'command') {
            return 'execute_task';
        }

        return 'unknown';
    }

    private function detectExpectedResponse(
        string $message,
        string $conversationRelation,
        string $requestType,
        string $userIntent
    ): string {
        if ($conversationRelation === 'status_check') {
            return 'status_answer';
        }

        if ($requestType === 'clarification') {
            return 'clarification_question';
        }

        if ($requestType === 'consultation') {
            return 'proposal';
        }

        if ($requestType === 'artifact') {
            return 'artifact_plan';
        }

        if ($userIntent === 'code_improvement') {
            return 'implementation_plan';
        }

        if ($requestType === 'answer_only') {
            return 'summary';
        }

        if ($userIntent === 'summarize_history') {
            return 'summary';
        }

        if ($conversationRelation === 'follow_up' || $requestType === 'command' || $userIntent === 'aggregate_data') {
            return 'execution_result';
        }

        if ($this->matchesAny($message, [
            '/(要点|要約|まとめて)/u',
        ])) {
            return 'summary';
        }

        return 'unknown';
    }

    /**
     * @param array<int, array<string, mixed>> $recentHistory
     */
    private function detectContextDependency(string $message, string $conversationRelation, array $recentHistory): string
    {
        if (in_array($conversationRelation, ['follow_up', 'rollback', 'correction', 'status_check'], true)) {
            return 'high';
        }

        if (!empty($recentHistory) && $this->matchesAny($message, [
            '/(それ|これ|前の|さっきの|その方向|その件)/u',
        ])) {
            return 'high';
        }

        if ($this->matchesAny($message, [
            '/(このCSV|この資料|資料メモ|この案件|会話履歴|現在の追記ポイント)/u',
        ])) {
            return 'medium';
        }

        return 'low';
    }

    private function detectNeedsClarification(
        string $message,
        string $requestType,
        string $userIntent,
        string $targetHint
    ): bool {
        if ($requestType === 'clarification') {
            return true;
        }

        if ($userIntent === 'aggregate_data' && $this->matchesAny($message, [
            '/^(集計してください|分析してください)$/u',
            '/(どのCSV|どの列|対象列|対象CSV)/u',
        ])) {
            return true;
        }

        if ($targetHint === '' && $this->matchesAny($message, [
            '/^(これを|それを|前のやつを|さっきの件を).*(やって|進めて|直して)/u',
        ])) {
            return true;
        }

        return false;
    }

    private function detectNeedsAction(string $conversationRelation, string $requestType, string $userIntent): bool
    {
        if ($requestType === 'artifact' || $requestType === 'command') {
            return true;
        }

        if ($conversationRelation === 'follow_up' && $userIntent === 'execute_task') {
            return true;
        }

        return false;
    }

    private function detectTodoPolicyHint(
        string $conversationRelation,
        string $requestType,
        string $userIntent,
        bool $needsClarification,
        string $updatePolicy
    ): string {
        if ($updatePolicy === 'read_only' || in_array($conversationRelation, ['status_check', 'rollback', 'correction'], true)) {
            return 'read_only';
        }

        if ($needsClarification || $requestType === 'clarification') {
            return 'deny';
        }

        if ($conversationRelation === 'follow_up') {
            return 'read_only';
        }

        if (in_array($requestType, ['consultation', 'answer_only'], true)) {
            return 'read_only';
        }

        if (in_array($requestType, ['artifact', 'command'], true) || in_array($userIntent, ['aggregate_data', 'create_artifact', 'execute_task', 'code_improvement'], true)) {
            return 'allow';
        }

        return 'unknown';
    }

    private function detectAnswerStrategy(
        string $conversationRelation,
        string $requestType,
        string $userIntent,
        string $expectedResponse,
        bool $needsClarification
    ): string {
        if ($needsClarification || $requestType === 'clarification' || $expectedResponse === 'clarification_question') {
            return 'ask_targeted_clarification';
        }

        if ($conversationRelation === 'status_check') {
            return 'summarize_current_state_then_recommend_next_step';
        }

        if ($conversationRelation === 'follow_up') {
            return 'continue_previous_execution_lane';
        }

        if (in_array($conversationRelation, ['rollback', 'correction'], true)) {
            return 'acknowledge_correction_then_realign';
        }

        if ($requestType === 'consultation') {
            return 'acknowledge_context_then_recommend_next_step';
        }

        if ($requestType === 'artifact') {
            return 'confirm_inputs_then_prepare_artifact';
        }

        if ($userIntent === 'aggregate_data') {
            return 'execute_or_clarify_data_task';
        }

        if ($expectedResponse === 'summary' || ($userIntent === 'inspect_document' && $requestType === 'answer_only') || $userIntent === 'summarize_history') {
            return 'answer_from_retrieved_context';
        }

        if ($requestType === 'command') {
            return 'execute_requested_task';
        }

        return 'respond_normally';
    }

    private function looksLikeConsultation(string $message): bool
    {
        return $this->matchesAny($message, [
            '/(どう進めるのがよい|大丈夫でしょうか|この方針は自然|この方針は改善した方がよい|回答精度を上げたい|キャッチボールがうまくできていない|意図は常に解釈|ロジック改善に戻るのは良くない|どう思いますか|方針としてはどう思う|進めて大丈夫|問題はありますか)/u',
            '/(どのように分析したら|どう分析すれば|どこから見れば|何から見れば|次はどうしましょうか)/u',
        ]);
    }

    private function looksLikeClarification(string $message): bool
    {
        return $this->matchesAny($message, [
            '/^(これをやってください|それを直してください|前のやつをお願いします|さっきの件を進めてください)$/u',
            '/^(詳細を教えてください|内容を教えてください|これについて教えてください|集計してください|このデータを見てください)$/u',
        ]);
    }

    private function looksLikeArtifact(string $message): bool
    {
        return $this->matchesAny($message, [
            '/(csv化してください|CSV化してください|報告書にしてください|レポートにしてください|PDFにしてください|CSVファイルにしてください|一つのcsvファイルにしてください|構成案を作ってください)/u',
        ]);
    }

    private function looksLikeAnswerOnlyRequest(string $message): bool
    {
        return $this->matchesAny($message, [
            '/(この資料の要点を教えて|この資料を要約して|これまでの会話内容を詳しくまとめて|会話履歴を要約して)/u',
        ]);
    }

    private function looksLikeCommand(string $message): bool
    {
        return $this->matchesAny($message, [
            '/(質問分解ロジックを改善|CSVを集計|月別に集計|分析してください|原因を特定してください|要約してください|整理してください|追記ポイントを整理してください|確認してください)/u',
            '/(回答生成ロジック|質問分解ロジック|route選択ロジック|ルート選択ロジック|ProjectMemoryAutoUpdater|ChatRouteSelector|ConversationIntentInterpreter|overcapture|ロジック).*(改善してください|修正してください|直してください)/u',
            '/(改善してください|修正してください|直してください).*(回答生成ロジック|質問分解ロジック|route選択ロジック|ルート選択ロジック|ProjectMemoryAutoUpdater|ChatRouteSelector|ConversationIntentInterpreter|overcapture|ロジック)/u',
        ]);
    }

    private function looksLikeCodeImprovementCommand(string $message): bool
    {
        return $this->matchesAny($message, [
            '/(回答生成ロジック|質問分解ロジック|route選択ロジック|ルート選択ロジック|ProjectMemoryAutoUpdater|ChatRouteSelector|ConversationIntentInterpreter|overcapture|ロジック).*(改善してください|修正してください|直してください)/u',
            '/(改善してください|修正してください|直してください).*(回答生成ロジック|質問分解ロジック|route選択ロジック|ルート選択ロジック|ProjectMemoryAutoUpdater|ChatRouteSelector|ConversationIntentInterpreter|overcapture|ロジック)/u',
        ]);
    }

    /**
     * @param string[] $patterns
     */
    private function matchesAny(string $message, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return true;
            }
        }

        return false;
    }

    private function normalizeText(string $text): string
    {
        $text = trim((string)$text);
        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }
}
