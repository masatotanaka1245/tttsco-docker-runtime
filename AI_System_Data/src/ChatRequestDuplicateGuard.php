<?php

final class ChatRequestDuplicateGuard
{
    public static function isValidRequestId(string $requestId): bool
    {
        return $requestId !== ''
            && strlen($requestId) <= 128
            && preg_match('/^[A-Za-z0-9_-]+$/', $requestId) === 1;
    }

    /** @return array{handle:resource,file:string,hash:string}|null */
    public static function acquire(string $directory, int $userId, ?int $projectId, ?int $threadId, string $requestId): ?array
    {
        if (!self::isValidRequestId($requestId)) {
            throw new InvalidArgumentException('Invalid request id');
        }

        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('Chat request lock directory is not writable');
        }

        $key = implode('|', [$userId, $projectId ?? 'NULL', $threadId ?? 'NULL', $requestId !== '' ? $requestId : uniqid('legacy-', true)]);
        $hash = hash('sha256', $key);
        $file = rtrim($directory, '/') . '/chat_request_' . $hash . '.lock';
        $handle = fopen($file, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open chat request lock');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return ['handle' => $handle, 'file' => $file, 'hash' => $hash];
    }

    /** @param array{handle:resource,file:string,hash:string}|null $lock */
    public static function release(?array $lock): void
    {
        $handle = $lock['handle'] ?? null;
        if (!is_resource($handle)) {
            return;
        }

        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
