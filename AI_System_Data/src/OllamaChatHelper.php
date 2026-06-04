<?php

class OllamaChatHelper
{
    public static function prepareSystemPrompt(string $model, string $system): string
    {
        if (self::isGemmaModel($model) && strpos($system, '<|think|>') !== 0) {
            return "<|think|>\n" . $system;
        }

        return $system;
    }

    public static function buildChatPayload(
        string $model,
        string $system,
        string $user,
        ?string $format = null,
        array $options = [],
        array $extra = []
    ): array {
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => self::prepareSystemPrompt($model, $system)],
                ['role' => 'user', 'content' => $user],
            ],
            'stream' => false,
        ];

        if (!empty($options)) {
            $payload['options'] = $options;
        }

        if ($format !== null) {
            $payload['format'] = $format;
        }

        foreach ($extra as $key => $value) {
            $payload[$key] = $value;
        }

        return $payload;
    }

    public static function extractVisibleContent(string $content, ?string &$thoughtProcess = null): string
    {
        $thought = '';

        if (preg_match('/<\|channel>thought(.*?)<channel\|>/s', $content, $matches)) {
            $thought .= trim((string)$matches[1]) . "\n";
            $content = preg_replace('/<\|channel>thought.*?<channel\|>/s', '', $content);
        }

        if (preg_match('/<think>(.*?)<\/think>/s', $content, $matches)) {
            $thought .= trim((string)$matches[1]) . "\n";
            $content = preg_replace('/<think>.*?<\/think>/s', '', $content);
        }

        if ($thoughtProcess !== null) {
            $thoughtProcess = trim($thought);
        }

        return trim($content);
    }

    public static function isGemmaModel(string $model): bool
    {
        return strpos(strtolower($model), 'gemma') !== false;
    }

    public static function hasThinkToken(string $system): bool
    {
        return strpos($system, '<|think|>') === 0;
    }
}
