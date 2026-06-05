<?php

require_once __DIR__ . '/ModelRoleResolver.php';

class ChatRouteDispatcher
{
    private $logger;
    private $apiBasePath;

    public function __construct($logger = null, ?string $apiBasePath = null)
    {
        $this->logger = is_callable($logger) ? $logger : null;
        $this->apiBasePath = $apiBasePath ?: dirname(__DIR__) . '/public/api';
    }

    public function dispatch(array $context): string
    {
        $message = (string)($context['message'] ?? '');
        $projectId = $context['project_id'] ?? null;
        $isHistorySummaryMode = (bool)($context['is_history_summary_mode'] ?? false);
        $isAnalysisMode = (bool)($context['is_analysis_mode'] ?? false);
        $advancedReasoning = (bool)($context['advanced_reasoning'] ?? false);
        $threadId = isset($context['thread_id']) && $context['thread_id'] !== null
            ? (int)$context['thread_id']
            : null;

        $globalCrossPattern = '/(全社|横断|データベース全体|すべての(案件|プロジェクト)|全体を見渡して|全システム|システム全体)/u';

        $mainModel = (string)($context['main_model'] ?? $context['reasoning_model'] ?? ModelRoleResolver::DEFAULT_MAIN_MODEL);
        $subModel = (string)($context['sub_model'] ?? $context['synthesis_model'] ?? ModelRoleResolver::DEFAULT_SUB_MODEL);
        $embeddingModel = (string)($context['embedding_model'] ?? ModelRoleResolver::DEFAULT_EMBEDDING_MODEL);

        if ($isHistorySummaryMode) {
            $routeName = 'history_summary';
            $this->log("[SMART-ROUTER] 最終ルート決定: {$routeName}");
            $this->logModelRoles($routeName, $mainModel, $subModel, $embeddingModel);
            require_once $this->apiBasePath . '/chat_history_summary.php';
            runHistorySummaryRoute(
                $context['pdo'],
                $context['ollama_host'],
                $projectId,
                $message,
                $mainModel,
                $subModel,
                $embeddingModel,
                $context['prompt_key'],
                $context['user_id'],
                $context['role'],
                $threadId
            );
            return $routeName;
        }

        if (preg_match($globalCrossPattern, $message)) {
            $routeName = 'global_cross';
            $this->log("[SMART-ROUTER] 明示的な全社横断キーワードを検出。強制的に「グローバル調査エージェント(ReAct)」をキックします。");
            $this->log("[SMART-ROUTER] 最終ルート決定: {$routeName}");
            $this->logModelRoles($routeName, $mainModel, $subModel, $embeddingModel);
            require_once $this->apiBasePath . '/chat_global.php';
            runGlobalChatRoute(
                $context['pdo'],
                $context['ollama_host'],
                $message,
                $mainModel,
                $subModel,
                $embeddingModel,
                $context['prompt_key'],
                $context['user_id'],
                $context['role'],
                $routeName
            );
            return $routeName;
        }

        if ($projectId === null) {
            $routeName = 'global_no_project';
            $this->log("[SMART-ROUTER] 最終ルート決定: {$routeName}");
            $this->logModelRoles($routeName, $mainModel, $subModel, $embeddingModel);
            require_once $this->apiBasePath . '/chat_global.php';
            runGlobalChatRoute(
                $context['pdo'],
                $context['ollama_host'],
                $message,
                $mainModel,
                $subModel,
                $embeddingModel,
                $context['prompt_key'],
                $context['user_id'],
                $context['role'],
                $routeName
            );
            return $routeName;
        }

        if ($isAnalysisMode && !$advancedReasoning) {
            $routeName = 'data_analysis';
            $this->log("[SMART-ROUTER] 最終ルート決定: {$routeName}");
            $this->logModelRoles($routeName, $mainModel, $subModel, $embeddingModel);
            require_once $this->apiBasePath . '/chat_analysis.php';
            runAdvancedReasoningRoute(
                $context['pdo'],
                $context['ollama_host'],
                $projectId,
                $message,
                $mainModel,
                $subModel,
                $embeddingModel,
                $context['prompt_key'],
                $context['project_context'],
                $context['history_summary_text'],
                $context['user_id'],
                $context['role'],
                $threadId,
                (bool)$context['report_mode'],
                (bool)$context['diagram_mode']
            );
            return $routeName;
        }

        if ($advancedReasoning) {
            $routeName = 'advanced_hybrid';
            $this->log("[SMART-ROUTER] 最終ルート決定: {$routeName}");
            $this->logModelRoles($routeName, $mainModel, $subModel, $embeddingModel);
            require_once $this->apiBasePath . '/chat_advanced.php';
            runAdvancedReasoningRoute(
                $context['pdo'],
                $context['ollama_host'],
                $projectId,
                $message,
                $context['search_query'],
                $context['reasoning_id'],
                $mainModel,
                $subModel,
                $embeddingModel,
                $context['prompt_key'],
                $context['project_context'],
                $context['history_summary_text'],
                $context['user_id'],
                $context['role'],
                $threadId,
                (bool)$context['report_mode'],
                (bool)$context['diagram_mode']
            );
            return $routeName;
        }

        $routeName = 'normal_rag';
        $this->log("[SMART-ROUTER] 最終ルート決定: {$routeName}");
        $this->logModelRoles($routeName, $mainModel, $subModel, $embeddingModel);
        require_once $this->apiBasePath . '/chat_normal.php';

        $engine = new EmbeddingEngine($context['ollama_host'], $embeddingModel);
        $vectorSearch = new VectorSearch($context['pdo']);

        runNormalStreamingRoute(
            $context['pdo'],
            $context['ollama_host'],
            $projectId,
            $message,
            $context['search_query'],
            $mainModel,
            $subModel,
            $embeddingModel,
            $context['prompt_key'],
            $context['project_context'],
            $context['history_summary_text'],
            $vectorSearch,
            $engine,
            $context['user_id'],
            $context['role'],
            $threadId,
            (bool)$context['report_mode'],
            (bool)$context['diagram_mode']
        );

        return $routeName;
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $message);
        }
    }

    private function logModelRoles(string $routeName, string $mainModel, string $subModel, string $embeddingModel): void
    {
        $this->log("[MODEL-ROLES] route={$routeName} | main_model={$mainModel} | sub_model={$subModel} | embedding_model={$embeddingModel}");
    }
}
