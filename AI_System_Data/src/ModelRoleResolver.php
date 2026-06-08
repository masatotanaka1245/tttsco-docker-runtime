<?php

class ModelRoleResolver
{
    public const DEFAULT_OLLAMA_HOST = 'http://127.0.0.1:11434';
    public const DEFAULT_MAIN_MODEL = 'gemma4:e4b';
    public const DEFAULT_SUB_MODEL = 'gpt-oss:20b';
    public const DEFAULT_SQL_MODEL = 'gpt-oss:20b';
    public const DEFAULT_EMBEDDING_MODEL = 'mxbai-embed-large';

    public static function defaults(): array
    {
        return [
            'ollama_host' => self::DEFAULT_OLLAMA_HOST,
            'main_model' => self::DEFAULT_MAIN_MODEL,
            'default_model' => self::DEFAULT_MAIN_MODEL,
            'sub_model' => self::DEFAULT_SUB_MODEL,
            'sql_model' => self::DEFAULT_SQL_MODEL,
            'embedding_model' => getenv('OLLAMA_EMBED_MODEL') ?: self::DEFAULT_EMBEDDING_MODEL,
        ];
    }

    public static function resolveUserSettings(array $session, array $overrides = []): array
    {
        $defaults = self::defaults();

        $ollamaHost = self::normalizeHost(
            $overrides['ollama_host']
                ?? $session['ollama_host']
                ?? getenv('OLLAMA_HOST')
                ?? $defaults['ollama_host']
        );

        $mainModel = self::normalizeModel(
            $overrides['main_model']
                ?? $overrides['default_model']
                ?? $overrides['model']
                ?? $session['default_model']
                ?? $defaults['main_model'],
            $defaults['main_model']
        );

        $subModel = self::normalizeModel(
            $overrides['sub_model']
                ?? $session['sub_model']
                ?? $defaults['sub_model'],
            $defaults['sub_model']
        );

        $sqlModel = self::normalizeModel(
            $overrides['sql_model']
                ?? $session['sql_model']
                ?? $subModel
                ?? $defaults['sql_model'],
            $defaults['sql_model']
        );

        $embeddingModel = self::normalizeModel(
            $overrides['embedding_model']
                ?? $session['embedding_model']
                ?? getenv('OLLAMA_EMBED_MODEL')
                ?? $defaults['embedding_model'],
            $defaults['embedding_model']
        );

        return [
            'ollama_host' => $ollamaHost,
            'main_model' => $mainModel,
            'default_model' => $mainModel,
            'sub_model' => $subModel,
            'sql_model' => $sqlModel,
            'embedding_model' => $embeddingModel,
            'worker_model' => $subModel,
            'integration_model' => $mainModel,
        ];
    }

    public static function resolveChatModels(array $session, array $input = []): array
    {
        $settings = self::resolveUserSettings($session, $input);

        // 既存ルートは reasoning/synthesis 名を前提にしているため、段階移行中は互換エイリアスを維持する。
        $settings['reasoning_model'] = $settings['main_model'];
        $settings['synthesis_model'] = $settings['sub_model'];
        $settings['sql_model'] = $settings['sql_model'] ?? $settings['sub_model'];

        return $settings;
    }

    private static function normalizeHost(?string $value): string
    {
        $host = rtrim(trim((string)$value), '/');
        return $host !== '' ? $host : self::DEFAULT_OLLAMA_HOST;
    }

    private static function normalizeModel(?string $value, string $fallback): string
    {
        $model = trim((string)$value);
        return $model !== '' ? $model : $fallback;
    }
}
