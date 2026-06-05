<?php

final class ChatThreadManager
{
    private static bool $schemaEnsured = false;

    public static function ensureSchema(PDO $pdo): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS chat_threads (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                project_id BIGINT UNSIGNED NOT NULL,
                title VARCHAR(255) NOT NULL,
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_chat_threads_project_id
                    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                CONSTRAINT fk_chat_threads_created_by
                    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_chat_threads_project_id (project_id),
                INDEX idx_chat_threads_updated_at (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            COMMENT='案件内の会話スレッド'
        ");

        if (!self::columnExists($pdo, 'chat_history', 'thread_id')) {
            $pdo->exec("
                ALTER TABLE chat_history
                    ADD COLUMN thread_id BIGINT UNSIGNED NULL AFTER project_id,
                    ADD INDEX idx_chat_history_thread_id (thread_id),
                    ADD INDEX idx_chat_history_thread_context (thread_id, created_at)
            ");
        }

        self::$schemaEnsured = true;
    }

    public static function listThreads(PDO $pdo, int $projectId, int $userId): array
    {
        self::ensureSchema($pdo);
        self::backfillLegacyHistory($pdo, $projectId, $userId);

        $stmt = $pdo->prepare("
            SELECT
                t.id,
                t.project_id,
                t.title,
                t.created_by,
                t.created_at,
                t.updated_at,
                COUNT(ch.id) AS message_count,
                MAX(ch.created_at) AS last_message_at
            FROM chat_threads t
            LEFT JOIN chat_history ch ON ch.thread_id = t.id
            WHERE t.project_id = ?
            GROUP BY t.id, t.project_id, t.title, t.created_by, t.created_at, t.updated_at
            ORDER BY COALESCE(MAX(ch.created_at), t.updated_at, t.created_at) DESC, t.id DESC
        ");
        $stmt->execute([$projectId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function resolveThreadId(PDO $pdo, int $projectId, ?int $requestedThreadId, int $userId): int
    {
        self::ensureSchema($pdo);
        self::backfillLegacyHistory($pdo, $projectId, $userId);

        if ($requestedThreadId !== null && self::threadBelongsToProject($pdo, $requestedThreadId, $projectId)) {
            return $requestedThreadId;
        }

        $stmt = $pdo->prepare("
            SELECT id
            FROM chat_threads
            WHERE project_id = ?
            ORDER BY updated_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$projectId]);
        $threadId = $stmt->fetchColumn();
        if ($threadId !== false) {
            return (int)$threadId;
        }

        $thread = self::createThread($pdo, $projectId, $userId);
        return (int)$thread['id'];
    }

    public static function createThread(PDO $pdo, int $projectId, int $userId, ?string $title = null): array
    {
        self::ensureSchema($pdo);

        $threadTitle = trim((string)$title);
        if ($threadTitle === '') {
            $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM chat_threads WHERE project_id = ?");
            $stmtCount->execute([$projectId]);
            $count = (int)$stmtCount->fetchColumn();
            $threadTitle = '会話 ' . ($count + 1);
        }

        $stmt = $pdo->prepare("
            INSERT INTO chat_threads (project_id, title, created_by, created_at, updated_at)
            VALUES (?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$projectId, $threadTitle, $userId]);
        $threadId = (int)$pdo->lastInsertId();

        $stmtFetch = $pdo->prepare("
            SELECT id, project_id, title, created_by, created_at, updated_at
            FROM chat_threads
            WHERE id = ?
            LIMIT 1
        ");
        $stmtFetch->execute([$threadId]);
        $thread = $stmtFetch->fetch(PDO::FETCH_ASSOC) ?: [];
        $thread['message_count'] = 0;
        $thread['last_message_at'] = null;

        return $thread;
    }

    public static function deleteThread(PDO $pdo, int $projectId, int $threadId, int $userId): array
    {
        self::ensureSchema($pdo);

        if (!self::threadBelongsToProject($pdo, $threadId, $projectId)) {
            throw new RuntimeException('削除対象のスレッドが見つかりません。');
        }

        $stmtChatIds = $pdo->prepare("
            SELECT id
            FROM chat_history
            WHERE project_id = ? AND thread_id = ?
        ");
        $stmtChatIds->execute([$projectId, $threadId]);
        $chatIds = array_map('intval', $stmtChatIds->fetchAll(PDO::FETCH_COLUMN) ?: []);

        $counts = [
            'thread_deleted' => 0,
            'chat_history_deleted' => 0,
            'reasoning_steps_deleted' => 0,
            'evaluations_deleted' => 0,
            'faqs_deleted' => 0,
        ];

        $pdo->beginTransaction();
        try {
            if ($chatIds !== []) {
                $placeholders = implode(',', array_fill(0, count($chatIds), '?'));

                $stmtFaqs = $pdo->prepare("
                    DELETE FROM project_faqs
                    WHERE project_id = ? AND chat_history_id IN ($placeholders)
                ");
                $stmtFaqs->execute(array_merge([$projectId], $chatIds));
                $counts['faqs_deleted'] = $stmtFaqs->rowCount();

                $stmtEval = $pdo->prepare("DELETE FROM chat_evaluations WHERE chat_id IN ($placeholders)");
                $stmtEval->execute($chatIds);
                $counts['evaluations_deleted'] = $stmtEval->rowCount();

                $stmtReasoning = $pdo->prepare("DELETE FROM chat_reasoning_steps WHERE chat_history_id IN ($placeholders)");
                $stmtReasoning->execute($chatIds);
                $counts['reasoning_steps_deleted'] = $stmtReasoning->rowCount();
            }

            $stmtHistory = $pdo->prepare("DELETE FROM chat_history WHERE project_id = ? AND thread_id = ?");
            $stmtHistory->execute([$projectId, $threadId]);
            $counts['chat_history_deleted'] = $stmtHistory->rowCount();

            $stmtThread = $pdo->prepare("DELETE FROM chat_threads WHERE id = ? AND project_id = ?");
            $stmtThread->execute([$threadId, $projectId]);
            $counts['thread_deleted'] = $stmtThread->rowCount();

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $fallbackThreadId = self::resolveThreadId($pdo, $projectId, null, $userId);

        return [
            'counts' => $counts,
            'fallback_thread_id' => $fallbackThreadId,
        ];
    }

    public static function updateTitleFromMessage(PDO $pdo, int $projectId, ?int $threadId, string $message): ?string
    {
        self::ensureSchema($pdo);

        if ($threadId === null || !self::threadBelongsToProject($pdo, $threadId, $projectId)) {
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT title
            FROM chat_threads
            WHERE id = ? AND project_id = ?
            LIMIT 1
        ");
        $stmt->execute([$threadId, $projectId]);
        $currentTitle = $stmt->fetchColumn();
        if ($currentTitle === false) {
            return null;
        }

        $currentTitle = (string)$currentTitle;
        if (!self::isAutoGeneratedTitle($currentTitle)) {
            return $currentTitle;
        }

        $nextTitle = self::buildTitleFromMessage($message);
        if ($nextTitle === '' || $nextTitle === $currentTitle) {
            return $currentTitle;
        }

        $stmtUpdate = $pdo->prepare("
            UPDATE chat_threads
            SET title = ?, updated_at = NOW()
            WHERE id = ? AND project_id = ?
        ");
        $stmtUpdate->execute([$nextTitle, $threadId, $projectId]);

        return $nextTitle;
    }

    private static function backfillLegacyHistory(PDO $pdo, int $projectId, int $userId): void
    {
        $stmtLegacyCount = $pdo->prepare("
            SELECT COUNT(*)
            FROM chat_history
            WHERE project_id = ? AND thread_id IS NULL
        ");
        $stmtLegacyCount->execute([$projectId]);
        $legacyCount = (int)$stmtLegacyCount->fetchColumn();
        if ($legacyCount === 0) {
            return;
        }

        $stmtExisting = $pdo->prepare("
            SELECT id
            FROM chat_threads
            WHERE project_id = ?
            ORDER BY created_at ASC, id ASC
            LIMIT 1
        ");
        $stmtExisting->execute([$projectId]);
        $threadId = $stmtExisting->fetchColumn();
        if ($threadId === false) {
            $thread = self::createThread($pdo, $projectId, $userId, '既存会話');
            $threadId = (int)$thread['id'];
        } else {
            $threadId = (int)$threadId;
        }

        $stmtUpdate = $pdo->prepare("
            UPDATE chat_history
            SET thread_id = ?
            WHERE project_id = ? AND thread_id IS NULL
        ");
        $stmtUpdate->execute([$threadId, $projectId]);
    }

    private static function threadBelongsToProject(PDO $pdo, int $threadId, int $projectId): bool
    {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM chat_threads
            WHERE id = ? AND project_id = ?
        ");
        $stmt->execute([$threadId, $projectId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function isAutoGeneratedTitle(string $title): bool
    {
        $normalized = trim($title);
        if ($normalized === '') {
            return true;
        }

        if (preg_match('/^会話\s+\d+$/u', $normalized) === 1) {
            return true;
        }

        return in_array($normalized, ['既存会話', '新しい会話'], true);
    }

    private static function buildTitleFromMessage(string $message): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $message) ?? $message);
        if ($normalized === '') {
            return '';
        }

        $normalized = preg_replace('/[`#>*_\-\[\]\(\)]/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized, " \t\n\r\0\x0B.,:;!?");

        if ($normalized === '') {
            return '';
        }

        return mb_strimwidth($normalized, 0, 36, '...', 'UTF-8');
    }

    private static function columnExists(PDO $pdo, string $tableName, string $columnName): bool
    {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$tableName, $columnName]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
