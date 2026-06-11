<?php

final class RouteRuntimeCallbackFactory
{
    public static function logger(callable $writer): callable
    {
        return function (string $message) use ($writer): void {
            call_user_func($writer, $message);
        };
    }

    public static function sse(callable $sender): callable
    {
        return function (string $event, array $payload) use ($sender): void {
            call_user_func($sender, $event, $payload);
        };
    }

    public static function statusEmitter(callable $sender, int $step): callable
    {
        return function (string $message) use ($sender, $step): void {
            call_user_func($sender, 'status', [
                'step' => $step,
                'message' => $message,
            ]);
        };
    }

    public static function promptBudgetLogger(string $routeLabel, callable $writer): callable
    {
        return function (string $phase, array $parts, int $numCtx) use ($routeLabel, $writer): void {
            $segments = [];
            $totalChars = 0;
            foreach ($parts as $label => $text) {
                $chars = mb_strlen((string)$text);
                $segments[] = "{$label}Chars={$chars}";
                $totalChars += $chars;
            }

            call_user_func(
                $writer,
                "[PROMPT-BUDGET] route={$routeLabel} | phase={$phase} | num_ctx={$numCtx} | totalChars={$totalChars} | " . implode(' | ', $segments)
            );
        };
    }
}
