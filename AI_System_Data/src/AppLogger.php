<?php
/**
 * AppLogger.php - API用の軽量ログヘルパー
 */

function appLog(string $fileName, string $message, array $context = []): void
{
    $basePath = realpath(__DIR__ . '/..');
    if ($basePath === false) {
        return;
    }

    $logDir = $basePath . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }

    $safeFileName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $fileName) ?: 'app_error.log';
    $path = $logDir . DIRECTORY_SEPARATOR . $safeFileName;
    $maxBytes = (int)(getenv('LOG_MAX_BYTES') ?: 5242880);

    if ($maxBytes > 0 && file_exists($path) && filesize($path) > $maxBytes) {
        @rename($path, $path . '.1');
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if ($context) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function appDebugEnabled(): bool
{
    $value = strtolower((string)(getenv('APP_DEBUG') ?: 'false'));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function appEnvEnabled(string $key, bool $default = false): bool
{
    $raw = getenv($key);
    if ($raw === false || $raw === '') {
        return $default;
    }

    return in_array(strtolower((string)$raw), ['1', 'true', 'yes', 'on'], true);
}

function shouldWriteChatLog(string $message): bool
{
    if (appEnvEnabled('CHAT_DEBUG_VERBOSE', false)) {
        return true;
    }

    return preg_match('/CRITICAL|ERROR|WARN|SECURITY|FAILED|FATAL|失敗|例外|拒否|エラー/u', $message) === 1;
}

function jsonApiError(string $userMessage, int $statusCode = 500, ?Throwable $exception = null, array $context = []): void
{
    if ($exception) {
        appLog('app_error.log', $exception->getMessage(), $context + [
            'exception' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ]);
    }

    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'error' => appDebugEnabled() && $exception ? $userMessage . ': ' . $exception->getMessage() : $userMessage,
    ], JSON_UNESCAPED_UNICODE);
}
