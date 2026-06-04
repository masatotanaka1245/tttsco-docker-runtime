<?php

class OllamaModelCatalog
{
    public static function probe(string $ollamaHost, int $timeoutSeconds = 3): array
    {
        if (!function_exists('curl_init')) {
            return [
                'success' => false,
                'http_code' => 0,
                'models' => [],
                'error' => 'cURL が利用できないため、Ollama モデル一覧を取得できません。'
            ];
        }

        $url = rtrim($ollamaHost, '/') . '/api/tags';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return [
                'success' => false,
                'http_code' => $httpCode,
                'models' => [],
                'error' => $curlError !== '' ? $curlError : 'Ollama モデル一覧の取得に失敗しました。'
            ];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return [
                'success' => false,
                'http_code' => $httpCode,
                'models' => [],
                'error' => 'Ollama モデル一覧のレスポンス形式が不正です。'
            ];
        }

        return [
            'success' => true,
            'http_code' => $httpCode,
            'models' => self::extractModelNames($decoded),
            'error' => null
        ];
    }

    private static function extractModelNames(array $decoded): array
    {
        $models = [];
        foreach (($decoded['models'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = trim((string)($row['name'] ?? $row['model'] ?? ''));
            if ($name !== '') {
                $models[] = $name;
            }
        }

        return array_values(array_unique($models));
    }

    public static function resolveRequestedModel(string $requestedModel, array $availableModels): ?string
    {
        $requestedModel = trim($requestedModel);
        if ($requestedModel === '') {
            return null;
        }

        if (in_array($requestedModel, $availableModels, true)) {
            return $requestedModel;
        }

        $requestedBase = self::baseModelName($requestedModel);
        $latestCandidate = $requestedBase . ':latest';
        if (in_array($latestCandidate, $availableModels, true)) {
            return $latestCandidate;
        }

        $baseMatches = [];
        foreach ($availableModels as $availableModel) {
            if (self::baseModelName((string)$availableModel) === $requestedBase) {
                $baseMatches[] = (string)$availableModel;
            }
        }

        if (count($baseMatches) === 1) {
            return $baseMatches[0];
        }

        return null;
    }

    private static function baseModelName(string $model): string
    {
        $model = trim($model);
        if ($model === '') {
            return '';
        }

        $parts = explode(':', $model, 2);
        return trim((string)$parts[0]);
    }
}
