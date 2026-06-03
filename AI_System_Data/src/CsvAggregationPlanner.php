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

    public function shouldUseStructuredAggregationRoute(string $question): bool
    {
        $hasDateIntent = preg_match('/(日付|日時|年月日|date|timestamp|時刻)/iu', $question) === 1;
        $hasAggregateIntent = preg_match('/(集計|件数|合計|平均|表に|一覧|推移|時系列|別に|グループ|何種類|ユニーク|distinct|重複なし)/iu', $question) === 1;
        $hasCsvContext = preg_match('/(CSV|csv|ファイル|データ|レコード|行)/u', $question) === 1
            || $this->findMentionedCsvFileName($question) !== null;

        if ($hasDateIntent && $hasAggregateIntent && $hasCsvContext) {
            return true;
        }

        $targetFileName = $this->findMentionedCsvFileName($question);
        if ($targetFileName === null || !$hasAggregateIntent) {
            return false;
        }

        return $this->findMentionedColumnName($question, $targetFileName) !== null;
    }

    public function buildStructuredAggregationPlan(string $question): array
    {
        $question = $this->normalizeUtf8($question);
        $targetFileName = $this->findMentionedCsvFileName($question);
        $targetColumn = $targetFileName !== null ? $this->findMentionedColumnName($question, $targetFileName) : null;

        $dateGranularity = 'day';
        if (preg_match('/(月別|月ごと)/u', $question)) {
            $dateGranularity = 'month';
        } elseif (preg_match('/(年別|年ごと)/u', $question)) {
            $dateGranularity = 'year';
        }

        $aggregationMode = 'date_histogram';
        $aggregateType = 'count';
        if ($targetColumn !== null && preg_match('/(何種類|ユニーク|distinct|重複なし|種類数)/iu', $question)) {
            $aggregationMode = 'distinct_count';
            $dateGranularity = 'none';
            $aggregateType = 'distinct_count';
        }

        return [
            'scope' => $targetFileName !== null ? 'single_file' : 'all_files',
            'target_file_name' => $targetFileName,
            'target_column' => $targetColumn,
            'aggregation_mode' => $aggregationMode,
            'aggregate_type' => $aggregateType,
            'date_granularity' => $dateGranularity,
            'wants_table' => preg_match('/(表|一覧)/u', $question) === 1,
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

    private function loadMetadata(): array
    {
        if ($this->metadataLoader === null) {
            return [];
        }

        $metadata = call_user_func($this->metadataLoader);
        return is_array($metadata) ? $metadata : [];
    }
}
