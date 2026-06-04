<?php

class CsvAggregationPlanner
{
    /** @var callable */
    private $normalizer;

    /** @var callable */
    private $fileNameResolver;

    /** @var callable|null */
    private $metadataLoader;

    public function __construct(callable $normalizer, callable $fileNameResolver, ?callable $metadataLoader = null)
    {
        $this->normalizer = $normalizer;
        $this->fileNameResolver = $fileNameResolver;
        $this->metadataLoader = $metadataLoader;
    }

    public function shouldUseStructuredAggregationRoute(string $question, array $recentHistory = []): bool
    {
        $hasDateIntent = preg_match('/(日付|日時|年月日|年月|月別|月ごと|年別|年ごと|date|timestamp|時刻|日時は不要|月単位)/iu', $question) === 1;
        $hasAggregateIntent = preg_match('/(集計|件数|合計|平均|表に|一覧|推移|時系列|別に|グループ|何種類|ユニーク|distinct|重複なし|分布|分類|カテゴリ|抽出して件数|抽出して、件数|若い順|古い順|昇順|降順)/iu', $question) === 1;
        $hasExplainIntent = preg_match('/(どういう|どのような|説明|意味|何を表|どんなイベント|イベント.*説明|イベント.*意味|それぞれ.*説明)/u', $question) === 1;
        $hasCsvContext = preg_match('/(CSV|csv|ファイル|データ|レコード|行)/u', $question) === 1
            || $this->findMentionedCsvFileName($question) !== null;
        $recentContext = $this->findRecentCsvContext($recentHistory);

        if ($hasDateIntent && $hasAggregateIntent && $hasCsvContext) {
            return true;
        }

        if ($hasDateIntent && $hasAggregateIntent && $this->findMentionedColumnTarget($question) !== null) {
            return true;
        }

        if ($hasDateIntent && $hasAggregateIntent && $recentContext !== null) {
            return true;
        }

        $targetFileName = $this->findMentionedCsvFileName($question);
        if ($targetFileName === null || (!$hasAggregateIntent && !$hasExplainIntent)) {
            if (($hasAggregateIntent || $hasExplainIntent) && $this->findMentionedColumnTarget($question) !== null) {
                return true;
            }

            return ($hasAggregateIntent || $hasExplainIntent) && $recentContext !== null;
        }

        return $this->findMentionedColumnName($question, $targetFileName) !== null;
    }

    public function buildStructuredAggregationPlan(string $question, array $recentHistory = []): array
    {
        $question = $this->normalizeUtf8($question);
        $targetFileName = $this->findMentionedCsvFileName($question);
        $targetColumn = $targetFileName !== null ? $this->findMentionedColumnName($question, $targetFileName) : null;
        $contextSource = ($targetFileName !== null || $targetColumn !== null) ? 'explicit' : 'none';
        if ($targetFileName === null || $targetColumn === null) {
            $columnTarget = $this->findMentionedColumnTarget($question);
            if ($columnTarget !== null) {
                $targetFileName = (string)$columnTarget['file_name'];
                $targetColumn = (string)$columnTarget['column_name'];
                $contextSource = 'explicit_column_target';
            }
        }
        if (($targetFileName === null || $targetColumn === null) && !empty($recentHistory)) {
            $recentContext = $this->findRecentCsvContext($recentHistory);
            if ($recentContext !== null) {
                $targetFileName = $targetFileName ?? (string)($recentContext['target_file_name'] ?? '');
                $targetColumn = $targetColumn ?? (string)($recentContext['target_column'] ?? '');
                if ($targetFileName !== '' || $targetColumn !== '') {
                    $contextSource = 'recent_history';
                }
            }
        }
        if ($targetColumn === null && $this->hasDateIntent($question)) {
            $inferredDateTarget = $this->findSingleDateLikeColumnTarget($question);
            if ($inferredDateTarget !== null) {
                $targetFileName = (string)$inferredDateTarget['file_name'];
                $targetColumn = (string)$inferredDateTarget['column_name'];
                $contextSource = 'inferred_date_column';
            }
        }
        $sourceColumn = $targetFileName !== null ? $this->findSemanticSourceColumn($targetFileName, [$targetColumn]) : null;
        $categoryFilterLabel = $targetFileName !== null ? $this->extractRequestedCategoryLabel($question, $targetFileName) : null;
        $targetValue = $this->extractRequestedTargetValue($question, [$targetColumn]);

        $dateGranularity = 'day';
        if (preg_match('/(月別|月ごと|年月|日時は不要|月単位)/u', $question)) {
            $dateGranularity = 'month';
        } elseif (preg_match('/(年別|年ごと)/u', $question)) {
            $dateGranularity = 'year';
        }

        $sortOrder = 'asc';
        if (preg_match('/(若い順|降順|新しい順|新しいものから)/u', $question)) {
            $sortOrder = 'desc';
        } elseif (preg_match('/(古い順|昇順|古いものから)/u', $question)) {
            $sortOrder = 'asc';
        }

        $aggregationMode = 'date_histogram';
        $aggregateType = 'count';
        $hasDistinctIntent = preg_match('/(何種類|ユニーク|distinct|重複なし|種類数)/iu', $question) === 1;
        $hasSemanticCategoryIntent = preg_match('/(カテゴリ|カテゴリー|分類|傾向|どのような情報|どんな情報|分析してください|分析して|テーマ)/u', $question) === 1;
        $hasColumnExplainIntent = preg_match('/(どういう|どのような|説明|意味|何を表|どんなイベント|イベント.*説明|イベント.*意味|それぞれ.*説明)/u', $question) === 1;
        $hasDateIntent = $this->hasDateIntent($question);
        $hasValueDistributionIntent = preg_match('/(全ての値|すべての値|各値|値ごとの件数|各レコード数|それぞれの件数|内訳|分布|一覧|表に)/u', $question) === 1;
        $wantsDistinctOnly = $hasDistinctIntent && !$hasValueDistributionIntent;
        $wantsExactCount = $targetColumn !== null
            && $targetValue !== null
            && preg_match('/(件数|何件|件ありますか|件ある|集計)/u', $question) === 1;
        $isDateLikeColumn = $targetColumn !== null && $this->isDateLikeColumnName($targetColumn);

        if ($wantsExactCount) {
            $aggregationMode = 'exact_value_count';
            $dateGranularity = 'none';
            $aggregateType = 'exact_value_count';
        } elseif ($targetColumn !== null && $wantsDistinctOnly) {
            $aggregationMode = 'distinct_count';
            $dateGranularity = 'none';
            $aggregateType = 'distinct_count';
        } elseif ($targetColumn !== null && $hasColumnExplainIntent) {
            $aggregationMode = 'column_semantics';
            $dateGranularity = 'none';
            $aggregateType = 'column_semantics';
        } elseif ($targetColumn !== null && $isDateLikeColumn && ($hasDateIntent || preg_match('/(若い順|古い順|昇順|降順)/u', $question) === 1)) {
            $aggregationMode = 'date_histogram';
            $aggregateType = 'count';
        } elseif ($targetColumn !== null && $sourceColumn !== null && $categoryFilterLabel !== null) {
            $aggregationMode = 'category_filtered_distribution';
            $dateGranularity = 'none';
            $aggregateType = 'category_filtered_distribution';
        } elseif ($targetColumn !== null && $hasSemanticCategoryIntent) {
            $aggregationMode = 'semantic_category_summary';
            $dateGranularity = 'none';
            $aggregateType = 'semantic_category_summary';
        } elseif ($targetColumn !== null) {
            $aggregationMode = 'value_distribution';
            $dateGranularity = 'none';
            $aggregateType = 'value_distribution';
        }

        return [
            'scope' => $targetFileName !== null ? 'single_file' : 'all_files',
            'target_file_name' => $targetFileName,
            'target_column' => $targetColumn,
            'source_column' => $sourceColumn,
            'category_filter_label' => $categoryFilterLabel,
            'target_value' => $targetValue,
            'context_source' => $contextSource,
            'aggregation_mode' => $aggregationMode,
            'aggregate_type' => $aggregateType,
            'date_granularity' => $dateGranularity,
            'sort_order' => $sortOrder,
            'wants_table' => preg_match('/(表|一覧)/u', $question) === 1,
            'wants_all_values' => preg_match('/(全ての値|すべての値|各値|値ごとの件数|各レコード数|それぞれの件数|全件)/u', $question) === 1,
            'wants_detail' => preg_match('/(どのような情報|内容|項目|レコードを特定)/u', $question) === 1,
        ];
    }

    private function findMentionedCsvFileName(string $question): ?string
    {
        return call_user_func($this->fileNameResolver, $question);
    }

    private function normalizeUtf8(string $text): string
    {
        return (string)call_user_func($this->normalizer, $text);
    }

    private function findMentionedColumnName(string $question, string $targetFileName): ?string
    {
        $metadata = $this->loadMetadata();
        foreach ($metadata as $file) {
            if ((string)($file['file_name'] ?? '') !== $targetFileName) {
                continue;
            }

            foreach (($file['columns'] ?? []) as $column) {
                $column = (string)$column;
                if ($column === '') {
                    continue;
                }
                if (mb_stripos($question, $column, 0, 'UTF-8') !== false) {
                    return $column;
                }
            }
        }

        return null;
    }

    private function findMentionedColumnTarget(string $question): ?array
    {
        $matches = [];
        foreach ($this->loadMetadata() as $file) {
            foreach (($file['columns'] ?? []) as $column) {
                $column = (string)$column;
                if ($column === '') {
                    continue;
                }
                if (mb_stripos($question, $column, 0, 'UTF-8') !== false) {
                    $key = (string)($file['file_name'] ?? '') . '|' . $column;
                    $matches[$key] = [
                        'file_name' => (string)($file['file_name'] ?? ''),
                        'column_name' => $column,
                    ];
                }
            }
        }

        return count($matches) === 1 ? array_values($matches)[0] : null;
    }

    private function findRecentCsvContext(array $recentHistory): ?array
    {
        for ($i = count($recentHistory) - 1; $i >= 0; $i--) {
            $historyMessage = trim((string)($recentHistory[$i]['message'] ?? ''));
            if ($historyMessage === '') {
                continue;
            }

            $mentionedCsv = $this->findMentionedCsvFileName($historyMessage);
            $mentionedColumnTarget = $this->findMentionedColumnTarget($historyMessage);
            if ($mentionedCsv === null && !empty($mentionedColumnTarget['file_name'])) {
                $mentionedCsv = (string)$mentionedColumnTarget['file_name'];
            }

            if ($mentionedCsv !== null || $mentionedColumnTarget !== null) {
                return [
                    'target_file_name' => $mentionedCsv,
                    'target_column' => $mentionedColumnTarget['column_name'] ?? null,
                ];
            }
        }

        return null;
    }

    private function findSingleDateLikeColumnTarget(string $question): ?array
    {
        $matches = [];
        foreach ($this->loadMetadata() as $file) {
            foreach ((array)($file['columns'] ?? []) as $column) {
                $column = (string)$column;
                if ($column === '' || !$this->isDateLikeColumnName($column)) {
                    continue;
                }
                $key = (string)($file['file_name'] ?? '') . '|' . $column;
                $matches[$key] = [
                    'file_name' => (string)($file['file_name'] ?? ''),
                    'column_name' => $column,
                ];
            }
        }

        return count($matches) === 1 ? array_values($matches)[0] : null;
    }

    private function findSemanticSourceColumn(string $targetFileName, array $excludedColumns = []): ?string
    {
        $preferred = ['タイトル', '件名', 'テーマ', '内容', '課題', '概要'];
        $excluded = array_values(array_filter(array_map('strval', $excludedColumns)));

        foreach ($this->loadMetadata() as $file) {
            if ((string)($file['file_name'] ?? '') !== $targetFileName) {
                continue;
            }

            foreach ($preferred as $candidate) {
                if (in_array($candidate, $excluded, true)) {
                    continue;
                }
                foreach (($file['columns'] ?? []) as $column) {
                    if ((string)$column === $candidate) {
                        return $candidate;
                    }
                }
            }
        }

        return null;
    }

    private function extractRequestedCategoryLabel(string $question, string $targetFileName): ?string
    {
        $columns = [];
        foreach ($this->loadMetadata() as $file) {
            if ((string)($file['file_name'] ?? '') === $targetFileName) {
                $columns = array_map('strval', (array)($file['columns'] ?? []));
                break;
            }
        }

        if (!preg_match_all('/[「『"]([^」』"]+)[」』"]/u', $question, $matches)) {
            return null;
        }

        foreach (array_reverse($matches[1]) as $candidate) {
            $candidate = preg_replace('/^.*[：:]/u', '', (string)$candidate);
            $candidate = preg_replace('/^[「『"\s]+/u', '', (string)$candidate);
            $candidate = preg_replace('/[」』"\s]+$/u', '', (string)$candidate);
            $candidate = preg_replace('/[（(].*$/u', '', (string)$candidate);
            $candidate = trim((string)$candidate);
            if ($candidate === '' || in_array($candidate, $columns, true)) {
                continue;
            }
            return $candidate;
        }

        return null;
    }

    private function extractRequestedTargetValue(string $question, array $excludedValues = []): ?string
    {
        $excluded = array_values(array_filter(array_map('strval', $excludedValues)));

        if (preg_match_all('/[「『"]([^」』"]+)[」』"]/u', $question, $matches)) {
            foreach (array_reverse($matches[1]) as $candidate) {
                $candidate = trim((string)$candidate);
                if ($candidate === '' || in_array($candidate, $excluded, true)) {
                    continue;
                }
                return $candidate;
            }
        }

        if (preg_match_all('/(\d{4}年\d{1,2}月(?:\d{1,2}日)?)/u', $question, $dateMatches)) {
            foreach (array_reverse($dateMatches[1]) as $candidate) {
                $candidate = trim((string)$candidate);
                if ($candidate === '' || in_array($candidate, $excluded, true)) {
                    continue;
                }
                return $candidate;
            }
        }

        if (preg_match_all('/(\d{4}[\/\-]\d{1,2}(?:[\/\-]\d{1,2})?)/u', $question, $isoDateMatches)) {
            foreach (array_reverse($isoDateMatches[1]) as $candidate) {
                $candidate = trim((string)$candidate);
                if ($candidate === '' || in_array($candidate, $excluded, true)) {
                    continue;
                }
                return $candidate;
            }
        }

        return null;
    }

    private function hasDateIntent(string $question): bool
    {
        return preg_match('/(日付|日時|年月日|年月|月別|月ごと|年別|年ごと|date|timestamp|時刻|日時は不要|月単位)/iu', $question) === 1;
    }

    private function isDateLikeColumnName(string $column): bool
    {
        return preg_match('/(日付|日時|年月日|年月|date|datetime|timestamp|access_date|time|時刻)/iu', $column) === 1;
    }

    private function loadMetadata(): array
    {
        if ($this->metadataLoader === null) {
            return [];
        }

        $metadata = call_user_func($this->metadataLoader);
        return is_array($metadata) ? $metadata : [];
    }
}
