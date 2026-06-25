<?php

final class ProjectTaskStateReducer
{
    private const ACTIONABLE_SHORT_TASK_PATTERN = '/(要約|集計|整理|更新|作成|確認|分析|抽出|比較|追記|報告書|レポート|グラフ)/u';

    /**
     * @param array<int, array<string, mixed>> $decomposedTasks
     * @return array<string, mixed>|null
     */
    public static function reduce(array $decomposedTasks, ?callable $logger = null): ?array
    {
        $usable = [];
        $skipped = 0;

        foreach ($decomposedTasks as $index => $task) {
            if (!is_array($task)) {
                self::logDecision($logger, [
                    'save_worthy' => false,
                    'reason' => 'invalid_task_payload',
                    'intent' => 'unknown',
                    'text' => '',
                    'normalized' => '',
                    'source' => 'decomposition',
                    'reducer_skip' => true,
                ]);
                $skipped++;
                continue;
            }

            $rawText = (string)($task['sub_query'] ?? '');
            $normalizedTask = self::normalizeTask((string)($task['task_text_normalized'] ?? $rawText));
            $intent = trim((string)($task['intent'] ?? 'general'));
            $source = self::resolveTaskSource($task);
            $saveReason = trim((string)($task['save_reason'] ?? ''));
            $skipReason = trim((string)($task['skip_reason'] ?? ''));
            $saveWorthy = (bool)($task['save_worthy'] ?? false);

            if (!$saveWorthy) {
                self::logDecision($logger, [
                    'save_worthy' => false,
                    'reason' => $skipReason !== '' ? $skipReason : 'save_worthy_false',
                    'intent' => $intent !== '' ? $intent : 'general',
                    'text' => $rawText,
                    'normalized' => $normalizedTask,
                    'source' => $source,
                    'reducer_skip' => false,
                ]);
                $skipped++;
                continue;
            }

            if ($normalizedTask === '') {
                self::logDecision($logger, [
                    'save_worthy' => false,
                    'reason' => 'empty_task',
                    'intent' => $intent !== '' ? $intent : 'general',
                    'text' => $rawText,
                    'normalized' => '',
                    'source' => $source,
                    'reducer_skip' => true,
                ]);
                $skipped++;
                continue;
            }

            $ephemeralAnalysis = self::analyzeEphemeralTask($normalizedTask);
            if ($ephemeralAnalysis['skip']) {
                self::logDecision($logger, [
                    'save_worthy' => false,
                    'reason' => (string)$ephemeralAnalysis['reason'],
                    'intent' => $intent !== '' ? $intent : 'general',
                    'text' => $rawText,
                    'normalized' => $normalizedTask,
                    'source' => $source,
                    'reducer_skip' => true,
                ]);
                $skipped++;
                continue;
            }

            self::logDecision($logger, [
                'save_worthy' => true,
                'reason' => $saveReason !== '' ? $saveReason : 'reducer_accepted',
                'intent' => $intent !== '' ? $intent : 'general',
                'text' => $rawText,
                'normalized' => $normalizedTask,
                'source' => $source,
                'reducer_skip' => false,
            ]);

            $usable[] = [
                'step_number' => (int)($task['step_number'] ?? ($index + 1)),
                'task' => $normalizedTask,
                'priority' => self::normalizePriority((string)($task['priority'] ?? 'medium')),
            ];
        }

        if ($usable === []) {
            return null;
        }

        usort($usable, static function (array $a, array $b): int {
            $priorityOrder = ['high' => 0, 'medium' => 1, 'low' => 2];
            $aOrder = $priorityOrder[$a['priority']] ?? 9;
            $bOrder = $priorityOrder[$b['priority']] ?? 9;
            if ($aOrder !== $bOrder) {
                return $aOrder <=> $bOrder;
            }

            return ((int)$a['step_number']) <=> ((int)$b['step_number']);
        });

        $currentTask = $usable[0]['task'];
        $pending = [];
        foreach (array_slice($usable, 1) as $task) {
            $pending[] = $task['task'];
        }

        $pending = self::uniqueNonEmpty($pending, [$currentTask]);

        return [
            'current' => [$currentTask],
            'pending' => $pending,
            'review' => [],
            'done' => [],
            'blocked' => [],
            'meta' => [
                'source' => 'decomposition',
                'usable_count' => count($usable),
                'skipped_count' => $skipped,
            ],
        ];
    }

    private static function normalizePriority(string $priority): string
    {
        $priority = trim(strtolower($priority));
        return in_array($priority, ['high', 'medium', 'low'], true) ? $priority : 'medium';
    }

    private static function normalizeTask(string $task): string
    {
        $task = trim((string)(preg_replace('/\s+/u', ' ', $task) ?? $task));
        return preg_replace('/[。．]+$/u', '', $task) ?? $task;
    }

    /**
     * @return array{skip:bool, reason:string}
     */
    private static function analyzeEphemeralTask(string $task): array
    {
        if (mb_strlen($task) < 6 && preg_match(self::ACTIONABLE_SHORT_TASK_PATTERN, $task) !== 1) {
            return ['skip' => true, 'reason' => 'too_short'];
        }

        if (preg_match('/\?|？$/u', $task)) {
            return ['skip' => true, 'reason' => 'question_like'];
        }

        if (preg_match(
            '/(どのCSV|どの列|対象列|対象CSV|追加情報|指定してください|教えてください|確認させてください|補足してください|もう少し詳しく)/u',
            $task
        ) === 1) {
            return ['skip' => true, 'reason' => 'clarification_request'];
        }

        return ['skip' => false, 'reason' => 'accepted'];
    }

    /**
     * @param string[] $values
     * @param string[] $exclude
     * @return string[]
     */
    private static function uniqueNonEmpty(array $values, array $exclude = []): array
    {
        $normalizedExclude = [];
        foreach ($exclude as $value) {
            $normalized = self::normalizeTask($value);
            if ($normalized !== '') {
                $normalizedExclude[$normalized] = true;
            }
        }

        $unique = [];
        foreach ($values as $value) {
            $normalized = self::normalizeTask($value);
            if ($normalized === '' || isset($normalizedExclude[$normalized]) || isset($unique[$normalized])) {
                continue;
            }
            $unique[$normalized] = true;
        }

        return array_keys($unique);
    }

    /**
     * @param array<string, mixed> $task
     */
    private static function resolveTaskSource(array $task): string
    {
        $routeHint = trim((string)($task['route_hint'] ?? ''));
        if ($routeHint !== '') {
            return $routeHint;
        }

        $targetHint = trim((string)($task['target_hint'] ?? ''));
        if ($targetHint !== '') {
            return 'target:' . $targetHint;
        }

        return 'decomposition';
    }

    /**
     * @param array{save_worthy:bool, reason:string, intent:string, text:string, normalized:string, source:string, reducer_skip:bool} $decision
     */
    private static function logDecision(?callable $logger, array $decision): void
    {
        if ($logger === null) {
            return;
        }

        $logger(
            '[TASK-REDUCER] save_worthy=' . ($decision['save_worthy'] ? 'true' : 'false')
            . ' | reason=' . ($decision['reason'] !== '' ? $decision['reason'] : 'none')
            . ' | intent=' . ($decision['intent'] !== '' ? $decision['intent'] : 'general')
            . ' | text=' . self::compactForLog($decision['text'], 80)
            . ' | normalized=' . self::compactForLog($decision['normalized'], 80)
            . ' | source=' . ($decision['source'] !== '' ? $decision['source'] : 'decomposition')
            . ' | reducer_skip=' . ($decision['reducer_skip'] ? 'true' : 'false')
        );
    }

    private static function compactForLog(string $text, int $limit): string
    {
        $text = trim((string)(preg_replace('/\s+/u', ' ', $text) ?? $text));
        if ($text === '') {
            return '(empty)';
        }

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit - 1) . '…';
    }
}
