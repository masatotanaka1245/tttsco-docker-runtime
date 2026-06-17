<?php

final class EvaluationResultHelper
{
    public static function normalizeStringList($items): array
    {
        if (!is_array($items)) {
            $items = [$items];
        }

        return array_values(array_unique(array_filter(array_map(static function ($item): string {
            return trim((string)$item);
        }, $items), static function (string $item): bool {
            return $item !== '';
        })));
    }

    public static function appendFeedback(string $feedback, string $suffix): string
    {
        $feedback = trim($feedback);
        $suffix = trim($suffix);

        if ($feedback === '') {
            return $suffix;
        }
        if ($suffix === '') {
            return $feedback;
        }

        return $feedback . "\n" . $suffix;
    }
}
