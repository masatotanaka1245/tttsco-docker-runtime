<?php

require_once __DIR__ . '/LightweightFinalGuardRunner.php';
require_once __DIR__ . '/AdvancedRouteFinalizer.php';

final class AdvancedRouteCompletionCoordinator
{
    private $ollamaHost;
    private $originalMessage;
    private $synthesisModel;
    private $logger;

    public function __construct(
        string $ollamaHost,
        string $originalMessage,
        string $synthesisModel,
        ?callable $logger = null
    ) {
        $this->ollamaHost = $ollamaHost;
        $this->originalMessage = $originalMessage;
        $this->synthesisModel = $synthesisModel;
        $this->logger = $logger;
    }

    public function complete(
        string $finalResponse,
        ?array $evalResult,
        ?array $guardSpec,
        callable $finalizerFactory
    ): array {
        if ($guardSpec !== null && !empty($guardSpec['route']) && isset($guardSpec['context'])) {
            [$finalResponse, $evalResult] = $this->applyLightweightGuard(
                $finalResponse,
                $evalResult,
                (string)$guardSpec['route'],
                (string)$guardSpec['context']
            );
        }

        /** @var AdvancedRouteFinalizer $finalizer */
        $finalizer = $finalizerFactory($finalResponse, $evalResult);
        $persisted = $finalizer->saveHistoryAndEvaluations();
        $finalizer->sendFinalResult($persisted['report_document'] ?? null, $persisted['csv_export'] ?? null);

        return [
            'final_response' => $finalResponse,
            'eval_result' => $evalResult,
            'report_document' => $persisted['report_document'] ?? null,
            'csv_export' => $persisted['csv_export'] ?? null,
        ];
    }

    private function applyLightweightGuard(
        string $finalResponse,
        ?array $evalResult,
        string $routeLabel,
        string $context
    ): array {
        $guardRunner = new LightweightFinalGuardRunner($this->ollamaHost, $this->logger);
        $guardResult = $guardRunner->review(
            $this->originalMessage,
            $context,
            $finalResponse,
            $this->synthesisModel,
            $routeLabel,
            $evalResult
        );

        return [
            (string)($guardResult['response'] ?? $finalResponse),
            $guardResult['eval_result'] ?? $evalResult,
        ];
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $message);
        }
    }
}
