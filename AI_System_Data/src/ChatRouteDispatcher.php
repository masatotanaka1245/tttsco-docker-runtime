<?php

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

        $globalCrossPattern = '/(全社|横断|データベース全体|すべての(案件|プロジェクト)|全体を見渡して|全システム|システム全体)/u';

        if ($isHistorySummaryMode) {
            $routeName = 'history_summary';
            $this->log("[SMART-ROUTER] 最終ルート決定: {$routeName}");
            require_once $this->apiBasePath . '/chat_history_summary.php';
            runHistorySummaryRoute(
                $context['pdo'],
                $projectId,
                $message,
                $context['reasoning_model'],
                $context['prompt_key'],
                $context['user_id'],
                $context['role']
            );
            return $routeName;
        }

        if (preg_match($globalCrossPattern, $message)) {
            $routeName = 'global_cross';
            $this->log("[SMART-ROUTER] 明示的な全社横断キーワードを検出。強制的に「グローバル調査エージェント(ReAct)」をキックします。");
            $this->log("[SMART-ROUTER] 最終ルート決定: {$routeName}");
            require_once $this->apiBasePath . '/chat_global.php';
            runGlobalChatRoute(
                $context['pdo'],
                $context['ollama_host'],
                $message,
                $context['reasoning_model'],
                $context['synthesis_model'],
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
            require_once $this->apiBasePath . '/chat_global.php';
            runGlobalChatRoute(
                $context['pdo'],
                $context['ollama_host'],
                $message,
                $context['reasoning_model'],
                $context['synthesis_model'],
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
            require_once $this->apiBasePath . '/chat_analysis.php';
            runAdvancedReasoningRoute(
                $context['pdo'],
                $context['ollama_host'],
                $projectId,
                $message,
                $context['reasoning_model'],
                $context['prompt_key'],
                $context['project_context'],
                $context['history_summary_text'],
                $context['user_id'],
                $context['role'],
                (bool)$context['report_mode'],
                (bool)$context['diagram_mode']
            );
            return $routeName;
        }

        if ($advancedReasoning) {
            $routeName = 'advanced_hybrid';
            $this->log("[SMART-ROUTER] 最終ルート決定: {$routeName}");
            require_once $this->apiBasePath . '/chat_advanced.php';
            runAdvancedReasoningRoute(
                $context['pdo'],
                $context['ollama_host'],
                $projectId,
                $message,
                $context['search_query'],
                $context['reasoning_id'],
                $context['reasoning_model'],
                $context['synthesis_model'],
                $context['prompt_key'],
                $context['project_context'],
                $context['history_summary_text'],
                $context['user_id'],
                $context['role'],
                (bool)$context['report_mode'],
                (bool)$context['diagram_mode']
            );
            return $routeName;
        }

        $routeName = 'normal_rag';
        $this->log("[SMART-ROUTER] 最終ルート決定: {$routeName}");
        require_once $this->apiBasePath . '/chat_normal.php';

        $engine = new EmbeddingEngine($context['ollama_host'], 'mxbai-embed-large');
        $vectorSearch = new VectorSearch($context['pdo']);

        runNormalStreamingRoute(
            $context['pdo'],
            $context['ollama_host'],
            $projectId,
            $message,
            $context['search_query'],
            $context['reasoning_model'],
            $context['prompt_key'],
            $context['project_context'],
            $context['history_summary_text'],
            $vectorSearch,
            $engine,
            $context['user_id'],
            $context['role'],
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
}
