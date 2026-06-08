<?php

require_once __DIR__ . '/ModelRoleResolver.php';
require_once __DIR__ . '/UserSettingsSchema.php';

class UserSettingsSessionSynchronizer
{
    public static function sync(PDO $pdo, ?int $userId = null): array
    {
        $defaults = ModelRoleResolver::defaults();
        $settings = [
            'default_prompt' => 'construction_consultant',
            'default_lang' => 'ja',
            'default_model' => (string)($_SESSION['default_model'] ?? $defaults['main_model']),
            'sub_model' => (string)($_SESSION['sub_model'] ?? $defaults['sub_model']),
            'sql_model' => (string)($_SESSION['sql_model'] ?? $defaults['sql_model']),
            'embedding_model' => (string)($_SESSION['embedding_model'] ?? $defaults['embedding_model']),
            'ollama_host' => (string)($_SESSION['ollama_host'] ?? $defaults['ollama_host']),
        ];

        $resolvedUserId = $userId ?? (int)($_SESSION['user_id'] ?? 0);
        if ($resolvedUserId <= 0) {
            self::writeSession($settings);
            return $settings;
        }

        $hasEmbeddingModelColumn = UserSettingsSchema::hasEmbeddingModelColumn($pdo);
        $hasSqlModelColumn = UserSettingsSchema::hasSqlModelColumn($pdo);
        try {
            $selectColumns = 'default_prompt, default_lang, default_model, sub_model, ollama_host';
            if ($hasSqlModelColumn) {
                $selectColumns .= ', sql_model';
            }
            if ($hasEmbeddingModelColumn) {
                $selectColumns .= ', embedding_model';
            }

            $stmt = $pdo->prepare("SELECT {$selectColumns} FROM users WHERE id = ?");
            $stmt->execute([$resolvedUserId]);
            $dbSettings = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($dbSettings)) {
                foreach ($dbSettings as $key => $value) {
                    if ($value === null || $value === '') {
                        continue;
                    }
                    $settings[$key] = (string)$value;
                }
            }
        } catch (Throwable $e) {
            // DB 読み込み失敗時は既存セッションと既定値を優先する
        }

        if (!$hasEmbeddingModelColumn && ($settings['embedding_model'] ?? '') === '') {
            $settings['embedding_model'] = $defaults['embedding_model'];
        }
        if (!$hasSqlModelColumn && ($settings['sql_model'] ?? '') === '') {
            $settings['sql_model'] = $settings['sub_model'] ?? $defaults['sql_model'];
        }

        self::writeSession($settings);
        return $settings;
    }

    private static function writeSession(array $settings): void
    {
        $_SESSION['default_prompt'] = (string)($settings['default_prompt'] ?? 'construction_consultant');
        $_SESSION['default_lang'] = (string)($settings['default_lang'] ?? 'ja');
        $_SESSION['default_model'] = (string)($settings['default_model'] ?? ModelRoleResolver::DEFAULT_MAIN_MODEL);
        $_SESSION['sub_model'] = (string)($settings['sub_model'] ?? ModelRoleResolver::DEFAULT_SUB_MODEL);
        $_SESSION['sql_model'] = (string)($settings['sql_model'] ?? $_SESSION['sub_model'] ?? ModelRoleResolver::DEFAULT_SQL_MODEL);
        $_SESSION['embedding_model'] = (string)($settings['embedding_model'] ?? ModelRoleResolver::DEFAULT_EMBEDDING_MODEL);
        $_SESSION['ollama_host'] = rtrim((string)($settings['ollama_host'] ?? ModelRoleResolver::DEFAULT_OLLAMA_HOST), '/');
    }
}
