<?php

final class ProjectTaskStateReducer
{
    private const ACTIONABLE_SHORT_TASK_PATTERN = '/(要約|集計|整理|更新|作成|確認|分析|抽出|比較|追記|報告書|レポート|グラフ)/u';

    /**
     * @param array<int, array<string, mixed>> $decomposedTasks
     * @return array<string, mixed>|null
     */
    public static function reduce(array $decomposedTasks): ?array
    {
        $usable = [];
        $skipped = 0;

        foreach ($decomposedTasks as $index => $task) {
            if (!is_array($task)) {
                $skipped++;
                continue;
            }

            if (!(bool)($task['save_worthy'] ?? false)) {
                $skipped++;
                continue;
            }

            $subQuery = self::normalizeTask((string)($task['sub_query'] ?? ''));
            if ($subQuery === '' || self::looksLikeEphemeralTask($subQuery)) {
                $skipped++;
                continue;
            }

            $usable[] = [
                'step_number' => (int)($task['step_number'] ?? ($index + 1)),
                'task' => $subQuery,
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

    private static function looksLikeEphemeralTask(string $task): bool
    {
        if (mb_strlen($task) < 6 && preg_match(self::ACTIONABLE_SHORT_TASK_PATTERN, $task) !== 1) {
            return true;
        }

        if (preg_match('/\?|？$/u', $task)) {
            return true;
        }

        return preg_match(
            '/(どのCSV|どの列|対象列|対象CSV|追加情報|指定してください|教えてください|確認させてください|補足してください|もう少し詳しく)/u',
            $task
        ) === 1;
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
}
