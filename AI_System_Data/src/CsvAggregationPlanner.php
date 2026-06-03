<?php

class CsvAggregationPlanner
{
    /** @var callable */
    private $normalizer;

    /** @var callable */
    private $fileNameResolver;

    public function __construct(callable $normalizer, callable $fileNameResolver)
    {
        $this->normalizer = $normalizer;
        $this->fileNameResolver = $fileNameResolver;
    }

    public function shouldUseStructuredAggregationRoute(string $question): bool
    {
        $hasDateIntent = preg_match('/(日付|日時|年月日|date|timestamp|時刻)/iu', $question) === 1;
        $hasAggregateIntent = preg_match('/(集計|件数|合計|平均|表に|一覧|推移|時系列|別に|グループ)/u', $question) === 1;
        $hasCsvContext = preg_match('/(CSV|csv|ファイル|データ|レコード|行)/u', $question) === 1
            || $this->findMentionedCsvFileName($question) !== null;

        return $hasDateIntent && $hasAggregateIntent && $hasCsvContext;
    }

    public function buildStructuredAggregationPlan(string $question): array
    {
        $question = $this->normalizeUtf8($question);
        $targetFileName = $this->findMentionedCsvFileName($question);

        $dateGranularity = 'day';
        if (preg_match('/(月別|月ごと)/u', $question)) {
            $dateGranularity = 'month';
        } elseif (preg_match('/(年別|年ごと)/u', $question)) {
            $dateGranularity = 'year';
        }

        $aggregateType = 'count';
        if (preg_match('/(合計|総数)/u', $question)) {
            $aggregateType = 'count';
        }

        return [
            'scope' => $targetFileName !== null ? 'single_file' : 'all_files',
            'target_file_name' => $targetFileName,
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
}
