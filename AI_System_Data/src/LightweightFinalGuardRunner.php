<?php

require_once __DIR__ . '/LightweightFinalAnswerGuard.php';

final class LightweightFinalGuardRunner
{
    private string $ollamaHost;
    /** @var callable|null */
    private $logger;

    public function __construct(string $ollamaHost, ?callable $logger = null)
    {
        $this->ollamaHost = $ollamaHost;
        $this->logger = $logger;
    }

    public function review(
        string $originalMessage,
        string $context,
        string $finalResponse,
        string $model,
        string $routeLabel,
        ?array $fallbackEvalResult = null
    ): array {
        $guard = new LightweightFinalAnswerGuard($this->ollamaHost);
        $guardResult = $guard->review(
            $originalMessage,
            $context,
            $finalResponse,
            $model,
            $routeLabel
        );

        $this->log("[LIGHTWEIGHT-GUARD-RUNNER] route={$routeLabel} applied");

        return [
            'response' => (string)($guardResult['response'] ?? $finalResponse),
            'eval_result' => $guardResult['eval_result'] ?? $fallbackEvalResult,
        ];
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $message);
        }
    }
}
