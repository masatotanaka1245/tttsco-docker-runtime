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
        $hasDateIntent = $this->hasDateIntent($question);
        $hasTimeBandIntent = $this->hasTimeBandIntent($question);
        $hasAggregateIntent = preg_match('/(集計|件数|合計|平均|表に|一覧|推移|時系列|別に|グループ|何種類|ユニーク|distinct|重複なし|分布|分類|カテゴリ|抽出して件数|抽出して、件数|若い順|古い順|昇順|降順|グラフ|グラフ化|チャート|ピーク時間|多い時間帯|ランキング|多い順|少ない順|上位|TOP|トップ|全件|すべて表示|続きを表示)/iu', $question) === 1;
        $hasExplainIntent = preg_match('/(どういう|どのような|説明|意味|何を表|どんなイベント|イベント.*説明|イベント.*意味|それぞれ.*説明)/u', $question) === 1;
        $hasColumnExistsIntent = preg_match('/(ありますよね|ありますか|存在しますか|入っていますか|含まれていますか|ありますよね。?)/u', $question) === 1;
        $hasSummaryIntent = preg_match('/(要約|まとめ|概要|内容を要約|内容をまとめ|どんな内容|内容を教えて)/u', $question) === 1;
        $hasCsvContext = preg_match('/(CSV|csv|ファイル|データ|レコード|行)/u', $question) === 1
            || $this->findMentionedCsvFileName($question) !== null;
        $mentionedFile = $this->findMentionedCsvFileName($question);
        $mentionedColumnTarget = $this->findMentionedColumnTarget($question);
        $mentionedColumnName = $this->findMentionedColumnNameAcrossFiles($question);
        $explicitColumnReference = $this->findExplicitColumnReference($question);
        $recentContext = $this->findRecentAggregationContext($recentHistory);
        $isAggregationFollowUp = $this->isAggregationFollowUpIntent($question);
        $isBroadCsvSummary = $hasSummaryIntent
            && $hasCsvContext
            && !$hasDateIntent
            && !$hasExplainIntent
            && $mentionedFile === null
            && $mentionedColumnTarget === null;

        if ($isBroadCsvSummary) {
            return false;
        }

        if (($hasDateIntent || $hasTimeBandIntent) && $hasAggregateIntent && $hasCsvContext) {
            return true;
        }

        if (($hasDateIntent || $hasTimeBandIntent) && $hasAggregateIntent && $mentionedColumnTarget !== null) {
            return true;
        }

        if (($hasDateIntent || $hasTimeBandIntent) && $hasAggregateIntent && $mentionedColumnName !== null) {
            return true;
        }

        if (($hasDateIntent || $hasTimeBandIntent) && $hasAggregateIntent && $explicitColumnReference !== null) {
            return true;
        }

        if (($hasDateIntent || $hasTimeBandIntent) && $hasAggregateIntent && $recentContext !== null) {
            return true;
        }

        if (preg_match('/(グラフ|グラフ化|チャート|棒グラフ|折れ線|円グラフ|可視化)/u', $question) === 1 && $mentionedFile !== null) {
            return true;
        }

        if ($isAggregationFollowUp && $recentContext !== null) {
            return true;
        }

        $targetFileName = $mentionedFile;
        if ($targetFileName === null || (!$hasAggregateIntent && !$hasExplainIntent)) {
            if (($hasAggregateIntent || $hasExplainIntent || $hasColumnExistsIntent) && $mentionedColumnTarget !== null) {
                return true;
            }

            if (($hasAggregateIntent || $hasExplainIntent || $hasColumnExistsIntent) && $mentionedColumnName !== null) {
                return true;
            }

            if (($hasAggregateIntent || $hasExplainIntent || $hasColumnExistsIntent) && $explicitColumnReference !== null) {
                return true;
            }

            return ($hasExplainIntent || $hasColumnExistsIntent || ($hasAggregateIntent && $hasDateIntent) || $isAggregationFollowUp) && $recentContext !== null;
        }

        return $this->findMentionedColumnName($question, $targetFileName) !== null;
    }

    public function buildStructuredAggregationPlan(string $question, array $recentHistory = [], array $options = []): array
    {
        $question = $this->normalizeUtf8($question);
        $hasDateIntent = $this->hasDateIntent($question);
        $hasTimeBandIntent = $this->hasTimeBandIntent($question);
        $targetFileName = $this->findMentionedCsvFileName($question);
        $explicitTargetFileName = $targetFileName;
        $explicitColumnReference = $this->findExplicitColumnReference($question);
        $targetColumn = $explicitColumnReference;
        $explicitColumnTarget = null;
        if ($targetColumn === null && $targetFileName !== null) {
            $targetColumn = $this->findMentionedColumnName($question, $targetFileName);
        }
        $contextSource = ($targetFileName !== null || $targetColumn !== null) ? 'explicit' : 'none';
        if ($explicitColumnReference !== null && $targetFileName === null) {
            $contextSource = 'explicit_column_reference';
        }
        $recentAggregationContext = !empty($recentHistory) ? $this->findRecentAggregationContext($recentHistory) : null;
        $recentArtifactState = is_array($options['recent_artifact_state'] ?? null)
            ? (array)$options['recent_artifact_state']
            : null;
        $recentAggregationContext = $this->mergeRecentAggregationContext($recentAggregationContext, $recentArtifactState);
        $routeDetail = trim((string)($options['route_detail'] ?? ''));
        $routeLockActive = $routeDetail === 'data_analysis.csv_agg.route_lock';
        if (($targetFileName === null || $targetColumn === null) && $explicitColumnReference === null) {
            $explicitColumnTarget = $this->findMentionedColumnTarget($question);
            if ($explicitColumnTarget !== null && ($targetFileName === null || $targetFileName === (string)$explicitColumnTarget['file_name'])) {
                $targetFileName = (string)$explicitColumnTarget['file_name'];
                $targetColumn = (string)$explicitColumnTarget['column_name'];
                $contextSource = 'explicit_column_target';
            }
        }
        if ($targetColumn !== null && $explicitColumnReference !== null && $contextSource === 'none') {
            $contextSource = 'explicit_column_reference';
        }
        if ($targetColumn === null) {
            $globalColumn = $targetFileName !== null
                ? $this->findMentionedColumnName($question, $targetFileName)
                : $this->findMentionedColumnNameAcrossFiles($question);
            if ($globalColumn !== null) {
                $targetColumn = $globalColumn;
                if ($contextSource === 'none') {
                    $contextSource = 'explicit_column_name';
                }
            }
        }
        $targetValue = $this->extractRequestedTargetValue($question, [$targetColumn, $explicitColumnReference]);
        $canUseRecentHistoryContext = $this->isRecentContextCarryOverIntent($question)
            || $routeLockActive
            || (
                $targetValue !== null
                && $targetFileName === null
                && $targetColumn === null
            );
        if (($targetFileName === null || $targetColumn === null) && $canUseRecentHistoryContext && $recentAggregationContext !== null) {
            $targetFileName = $targetFileName ?? (string)($recentAggregationContext['target_file_name'] ?? '');
            $targetColumn = $targetColumn ?? (string)($recentAggregationContext['target_column'] ?? '');
            if ($targetFileName !== '' || $targetColumn !== '') {
                $contextSource = $this->isAggregationFollowUpIntent($question) ? 'recent_history_followup' : 'recent_history';
            }
        }
        $dateGranularity = $this->detectDateGranularity($question, $hasTimeBandIntent);
        $dateFilterMeta = $this->detectDateFilterMeta($question);
        $questionHasExplicitColumnMarker = preg_match('/([「『"].+[」』"]\s*(?:カラム|列|項目)|(?:カラム|列|項目))/u', $question) === 1;
        $preferExplicitYearMonthDateAxis = $dateFilterMeta['mode'] === 'explicit_year_month'
            && !$hasTimeBandIntent
            && !$questionHasExplicitColumnMarker;

        if (
            $preferExplicitYearMonthDateAxis
            && $targetColumn !== null
            && !$this->isDateLikeColumnName($targetColumn)
            && $explicitColumnReference === null
            && in_array($contextSource, ['explicit_column_target', 'explicit_column_name'], true)
        ) {
            if ($contextSource === 'explicit_column_target' && $explicitTargetFileName === null) {
                $targetFileName = null;
            }
            $targetColumn = null;
            $explicitColumnTarget = null;
            $contextSource = 'none';
        }

        if ($targetColumn === null && ($hasDateIntent || $hasTimeBandIntent || $preferExplicitYearMonthDateAxis)) {
            $inferredDateTarget = $this->findSingleDateLikeColumnTarget($targetFileName);
            if ($inferredDateTarget !== null) {
                $targetFileName = (string)$inferredDateTarget['file_name'];
                $targetColumn = (string)$inferredDateTarget['column_name'];
                $contextSource = 'inferred_date_column';
            }
        }

        if (
            $preferExplicitYearMonthDateAxis
            && $dateGranularity === 'day'
            && preg_match('/(日別|日ごと|日単位)/u', $question) !== 1
        ) {
            $dateGranularity = 'month';
        }

        $sourceColumn = $targetFileName !== null ? $this->findSemanticSourceColumn($targetFileName, [$targetColumn]) : null;
        $categoryFilterLabel = $targetFileName !== null ? $this->extractRequestedCategoryLabel($question, $targetFileName) : null;

        $temporalBucketMode = $this->detectTemporalBucketMode($question, $hasTimeBandIntent);
        $dateAxisCandidates = $this->detectDateAxisCandidates($targetFileName !== '' ? $targetFileName : null, $targetColumn);

        $hasExplicitSortIntent = preg_match('/(若い順|古い順|昇順|降順|新しい順|新しいものから|古いものから)/u', $question) === 1;
        $sortOrder = 'asc';
        if (preg_match('/(若い順|降順|新しい順|新しいものから)/u', $question)) {
            $sortOrder = 'desc';
        } elseif (preg_match('/(古い順|昇順|古いものから)/u', $question)) {
            $sortOrder = 'asc';
        } elseif (!empty($recentAggregationContext['sort_order'])) {
            $sortOrder = (string)$recentAggregationContext['sort_order'];
        }

        $aggregationMode = 'date_histogram';
        $aggregateType = 'count';
        $hasDistinctIntent = preg_match('/(何種類|ユニーク|distinct|重複なし|種類数)/iu', $question) === 1;
        $hasRankingIntent = preg_match('/(ランキング|多い順|少ない順|上位|TOP|トップ)/iu', $question) === 1;
        $hasColumnExistsIntent = preg_match('/(ありますよね|ありますか|存在しますか|入っていますか|含まれていますか|ありますよね。?)/u', $question) === 1;
        $hasSemanticCategoryIntent = preg_match('/(カテゴリ|カテゴリー|分類|傾向|どのような情報|どんな情報|分析してください|分析して|テーマ)/u', $question) === 1;
        $hasColumnExplainIntent = preg_match('/(どういう|どのような|説明|意味|何を表|どんなイベント|イベント.*説明|イベント.*意味|それぞれ.*説明)/u', $question) === 1;
        $hasValueDistributionIntent = preg_match('/(全ての値|すべての値|各値|値ごとの件数|各レコード数|それぞれの件数|内訳|分布|一覧|表に|ランキング|多い順|少ない順|上位|TOP|トップ)/u', $question) === 1;
        $wantsDistinctOnly = $hasDistinctIntent && !$hasValueDistributionIntent;
        $wantsExactCount = $targetColumn !== null
            && $targetValue !== null
            && preg_match('/(件数|何件|件ありますか|件ある|集計)/u', $question) === 1;
        $isDateLikeColumn = $targetColumn !== null && $this->isDateLikeColumnName($targetColumn);
        if ($preferExplicitYearMonthDateAxis && $isDateLikeColumn) {
            $wantsExactCount = false;
        }
        $isAggregationFollowUp = $this->isAggregationFollowUpIntent($question);
        $recentAggregationMode = (string)($recentAggregationContext['aggregation_mode'] ?? '');
        $recentDateGranularity = (string)($recentAggregationContext['date_granularity'] ?? '');
        $usedRecentAggregationMode = false;
        $usesValueOrdering = !$hasRankingIntent && (
            $hasExplicitSortIntent
            || ($isAggregationFollowUp && !empty($recentAggregationContext['uses_value_ordering']))
        );
        $explicitChartIntent = preg_match('/(グラフ|グラフ化|チャート|可視化|棒グラフ|折れ線|円グラフ)/u', $question) === 1;
        $explicitTableIntent = preg_match('/(表にして|表で|表形式|一覧にして|一覧で|テーブルで|グラフではなく表)/u', $question) === 1;
        $explicitOutputIntent = $explicitChartIntent || $explicitTableIntent;
        $countOnlyRequest = $this->detectCountOnlyRequest($question, $dateFilterMeta);
        $wantsChart = $explicitChartIntent;
        if (
            !$explicitOutputIntent
            && ($isAggregationFollowUp || $routeLockActive)
            && !empty($recentAggregationContext['wants_chart'])
        ) {
            $wantsChart = true;
        }
        $chartType = $this->detectRequestedChartType($question);
        if (
            !$explicitTableIntent
            && $chartType === null
            && ($isAggregationFollowUp || $routeLockActive)
            && !empty($recentAggregationContext['chart_type'])
        ) {
            $chartType = (string)$recentAggregationContext['chart_type'];
        }
        if ($explicitTableIntent) {
            $chartType = null;
        }
        $wantsTable = $explicitTableIntent;
        if (
            !$explicitOutputIntent
            && ($isAggregationFollowUp || $routeLockActive)
            && !empty($recentAggregationContext['wants_table'])
        ) {
            $wantsTable = true;
        }
        $outputFormat = 'prose';
        if ($wantsChart) {
            $outputFormat = 'chart';
        } elseif ($wantsTable) {
            $outputFormat = 'table';
        }
        $usedRecentOutputFormat = false;
        if (
            !$explicitOutputIntent
            && $outputFormat === 'prose'
            && ($isAggregationFollowUp || $routeLockActive)
            && !empty($recentAggregationContext['output_format'])
        ) {
            $outputFormat = (string)$recentAggregationContext['output_format'];
            $usedRecentOutputFormat = true;
            if ($outputFormat === 'chart') {
                $wantsChart = true;
                $wantsTable = false;
            } elseif ($outputFormat === 'table') {
                $wantsTable = true;
                $wantsChart = false;
                $chartType = null;
            }
        }

        if ($targetColumn !== null && $hasColumnExistsIntent) {
            $aggregationMode = 'column_exists';
            $dateGranularity = 'none';
            $aggregateType = 'column_exists';
        } elseif ($wantsExactCount) {
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
        } elseif (
            $targetColumn !== null
            && $isAggregationFollowUp
            && $recentAggregationMode !== ''
            && !$hasDateIntent
            && !$hasDistinctIntent
            && !$hasColumnExplainIntent
            && !$hasSemanticCategoryIntent
            && $targetValue === null
        ) {
            $aggregationMode = $recentAggregationMode;
            $dateGranularity = $recentAggregationMode === 'date_histogram'
                ? ($recentDateGranularity !== '' ? $recentDateGranularity : $dateGranularity)
                : 'none';
            $aggregateType = $recentAggregationMode === 'date_histogram' ? 'count' : $recentAggregationMode;
            $usedRecentAggregationMode = true;
        } elseif (
            $targetColumn !== null
            && $isDateLikeColumn
            && (
                $hasDateIntent
                || $dateFilterMeta['mode'] === 'explicit_year_month'
                || preg_match('/(若い順|古い順|昇順|降順)/u', $question) === 1
            )
        ) {
            $aggregationMode = 'date_histogram';
            $aggregateType = 'count';
        } elseif ($targetColumn !== null && $hasValueDistributionIntent) {
            $aggregationMode = 'value_distribution';
            $dateGranularity = 'none';
            $aggregateType = 'value_distribution';
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

        $clarificationMeta = $this->buildClarificationMeta(
            $question,
            [
                'explicit_target_file_name' => $explicitTargetFileName,
                'explicit_column_reference' => $explicitColumnReference,
                'explicit_column_target' => $explicitColumnTarget,
                'target_file_name' => $targetFileName,
                'target_column' => $targetColumn,
                'aggregation_mode' => $aggregationMode,
                'context_source' => $contextSource,
                'has_date_intent' => $hasDateIntent,
                'has_time_band_intent' => $hasTimeBandIntent,
                'has_semantic_category_intent' => $hasSemanticCategoryIntent,
                'is_aggregation_follow_up' => $isAggregationFollowUp,
                'route_lock_active' => $routeLockActive,
                'targets_all_csv' => $this->targetsAllCsv($question),
                'date_filter_mode' => $dateFilterMeta['mode'],
                'date_filter_value' => $dateFilterMeta['value'],
                'temporal_bucket_mode' => $temporalBucketMode,
                'date_axis_candidates' => $dateAxisCandidates,
            ]
        );

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
            'used_recent_aggregation_mode' => $usedRecentAggregationMode,
            'recent_aggregation_mode' => $recentAggregationMode,
            'date_granularity' => $dateGranularity,
            'date_filter_mode' => $dateFilterMeta['mode'],
            'date_filter_value' => $dateFilterMeta['value'],
            'date_axis_candidates' => $dateAxisCandidates,
            'temporal_bucket_mode' => $temporalBucketMode,
            'sort_order' => $sortOrder,
            'uses_value_ordering' => $usesValueOrdering,
            'wants_chart' => $wantsChart,
            'chart_type' => $chartType,
            'wants_table' => $wantsTable,
            'output_format' => $outputFormat,
            'used_recent_output_format' => $usedRecentOutputFormat,
            'count_only_request' => $countOnlyRequest,
            'wants_all_values' => preg_match('/(全ての値|すべての値|各値|値ごとの件数|各レコード数|それぞれの件数|全件|全部|すべて表示|続きを表示|すべてのランキング|ランキングを表示)/u', $question) === 1,
            'wants_detail' => preg_match('/(どのような情報|内容|項目|レコードを特定)/u', $question) === 1,
            'base_sql' => $recentAggregationContext['base_sql'] ?? null,
            'route_lock_active' => $routeLockActive,
            'route_detail' => $routeDetail,
            'last_success_route' => $recentAggregationContext['last_success_route'] ?? null,
            'needs_clarification' => $clarificationMeta['needs_clarification'],
            'clarification_reason' => $clarificationMeta['clarification_reason'],
            'missing_dimensions' => $clarificationMeta['missing_dimensions'],
        ];
    }

    private function buildClarificationMeta(string $question, array $context): array
    {
        $dateFilterMode = trim((string)($context['date_filter_mode'] ?? ''));
        $temporalBucketMode = trim((string)($context['temporal_bucket_mode'] ?? ''));
        $hasAggregateIntent = preg_match('/(集計|件数|合計|平均|表に|一覧|推移|時系列|別に|グループ|何種類|ユニーク|distinct|重複なし|分布|分類|カテゴリ|抽出して件数|抽出して、件数|若い順|古い順|昇順|降順|グラフ|グラフ化|チャート|ピーク時間|多い時間帯|ランキング|多い順|少ない順|上位|TOP|トップ|全件|すべて表示|続きを表示)/iu', $question) === 1;
        $hasCategoryGroupingIntent = preg_match('/(カテゴリ別|カテゴリー別|分類別|項目別|列ごと|内訳|分布|ランキング|多い順|少ない順|上位|TOP|トップ)/u', $question) === 1;
        $hasChartIntent = preg_match('/(グラフ|グラフ化|チャート|棒グラフ|折れ線|円グラフ|可視化)/u', $question) === 1;
        $hasExploratoryAnalysisIntent = preg_match('/(分析してください|分析して|傾向を教えて|傾向を見て|見てください|問題点を教えて)/u', $question) === 1;
        $hasBroadAggregateQuestion = $hasAggregateIntent
            || !empty($context['has_date_intent'])
            || !empty($context['has_time_band_intent'])
            || $hasChartIntent
            || $dateFilterMode !== ''
            || ($temporalBucketMode !== '' && $temporalBucketMode !== 'none');
        $hasBroadAggregateQuestion = $hasBroadAggregateQuestion || $hasExploratoryAnalysisIntent;

        if (!$hasBroadAggregateQuestion) {
            return [
                'needs_clarification' => false,
                'clarification_reason' => '',
                'missing_dimensions' => [],
            ];
        }

        $explicitTargetFileName = trim((string)($context['explicit_target_file_name'] ?? ''));
        $explicitColumnReference = trim((string)($context['explicit_column_reference'] ?? ''));
        $targetFileName = trim((string)($context['target_file_name'] ?? ''));
        $targetColumn = trim((string)($context['target_column'] ?? ''));
        $aggregationMode = trim((string)($context['aggregation_mode'] ?? ''));
        $contextSource = trim((string)($context['context_source'] ?? ''));
        $isAggregationFollowUp = !empty($context['is_aggregation_follow_up']);
        $targetsAllCsv = !empty($context['targets_all_csv']);
        $dateAxisCandidates = array_values(array_filter(array_map('strval', (array)($context['date_axis_candidates'] ?? []))));
        $availableCsvCount = $this->countAvailableCsvFiles();
        $questionHasExplicitColumnMarker = preg_match('/([「『"].+[」』"]\s*(?:カラム|列|項目)|(?:カラム|列|項目))/u', $question) === 1;
        $usesWeakImplicitColumnTarget = $explicitTargetFileName === ''
            && $explicitColumnReference === ''
            && !empty($context['explicit_column_target'])
            && !$questionHasExplicitColumnMarker
            && (
                $hasCategoryGroupingIntent
                || preg_match('/(件数|売上)/u', $question) === 1
            );

        $missingDimensions = [];
        $clarificationReason = '';

        if (
            $availableCsvCount > 1
            && $targetFileName === ''
            && !$targetsAllCsv
            && !$isAggregationFollowUp
        ) {
            $missingDimensions[] = 'target_file_name';
            $clarificationReason = 'missing_target_file';
        }

        if ($dateFilterMode === 'month_only') {
            $missingDimensions[] = 'period';
            $clarificationReason = 'ambiguous_period_without_year';
        } elseif (in_array($dateFilterMode, ['relative_month', 'relative_year'], true)) {
            $missingDimensions[] = 'period';
            $clarificationReason = 'relative_period_requires_reference_date';
        } elseif (
            $dateFilterMode === 'explicit_year_month'
            && $explicitColumnReference === ''
            && empty($context['explicit_column_target'])
            && $targetColumn === ''
        ) {
            $missingDimensions[] = 'target_column';
            $missingDimensions[] = 'period_filter';
            $clarificationReason = 'explicit_period_filter_not_supported';
        }

        if ($temporalBucketMode === 'weekday') {
            $missingDimensions[] = 'temporal_bucket';
            $clarificationReason = 'unsupported_temporal_bucket_weekday';
        } elseif ($temporalBucketMode === 'am_pm') {
            $missingDimensions[] = 'temporal_bucket';
            $clarificationReason = 'unsupported_temporal_bucket_am_pm';
        } elseif ($temporalBucketMode === 'time_band') {
            $missingDimensions[] = 'temporal_bucket';
            $clarificationReason = 'unsupported_temporal_bucket_time_band';
        }

        if (!empty($context['has_date_intent']) || !empty($context['has_time_band_intent'])) {
            if ($targetColumn !== '' && !$this->isDateLikeColumnName($targetColumn)) {
                $missingDimensions[] = 'date_axis';
                if ($clarificationReason === '') {
                    $clarificationReason = 'invalid_date_axis';
                }
            } elseif ($targetColumn !== '') {
                if (count($dateAxisCandidates) > 1 && ($targetFileName !== '' || $availableCsvCount <= 1 || $targetsAllCsv)) {
                    $missingDimensions[] = 'target_column';
                    $missingDimensions[] = 'date_axis';
                    if ($clarificationReason === '') {
                        $clarificationReason = 'ambiguous_date_axis';
                    }
                } elseif (count($dateAxisCandidates) === 0 && ($targetFileName !== '' || $availableCsvCount <= 1 || $targetsAllCsv)) {
                    $missingDimensions[] = 'target_column';
                    $missingDimensions[] = 'date_axis';
                    if ($clarificationReason === '') {
                        $clarificationReason = 'missing_date_axis';
                    }
                }
            } elseif ($targetColumn === '') {
                if (count($dateAxisCandidates) > 1 && ($targetFileName !== '' || $availableCsvCount <= 1 || $targetsAllCsv)) {
                    $missingDimensions[] = 'target_column';
                    $missingDimensions[] = 'date_axis';
                    if ($clarificationReason === '') {
                        $clarificationReason = 'ambiguous_date_axis';
                    }
                } elseif (count($dateAxisCandidates) === 0 && ($targetFileName !== '' || $availableCsvCount <= 1 || $targetsAllCsv)) {
                    $missingDimensions[] = 'target_column';
                    $missingDimensions[] = 'date_axis';
                    if ($clarificationReason === '') {
                        $clarificationReason = 'missing_date_axis';
                    }
                } else {
                    $missingDimensions[] = 'target_column';
                    $missingDimensions[] = 'date_axis';
                    if ($clarificationReason === '') {
                        $clarificationReason = 'missing_target_column';
                    }
                }
            }
        } elseif ($hasCategoryGroupingIntent || $hasChartIntent || $hasAggregateIntent) {
            if ($targetColumn === '') {
                $missingDimensions[] = 'target_column';
                $missingDimensions[] = 'group_axis';
                $clarificationReason = $clarificationReason !== '' ? $clarificationReason : 'missing_target_column';
            }
        }

        if ($usesWeakImplicitColumnTarget) {
            if ($availableCsvCount > 1 && !$targetsAllCsv && !$isAggregationFollowUp) {
                $missingDimensions[] = 'target_file_name';
            }
            $missingDimensions[] = 'target_column';
            $missingDimensions[] = 'group_axis';
            $clarificationReason = $clarificationReason !== '' ? $clarificationReason : 'weak_implicit_column_match';
        }

        if (
            $aggregationMode === 'value_distribution'
            && $targetColumn !== ''
            && in_array($contextSource, ['recent_history', 'recent_history_followup'], true)
            && $explicitTargetFileName === ''
            && $explicitColumnReference === ''
            && empty($context['explicit_column_target'])
            && !$isAggregationFollowUp
        ) {
            $missingDimensions[] = 'group_axis';
            $clarificationReason = $clarificationReason !== '' ? $clarificationReason : 'weak_value_distribution_context';
        }

        $missingDimensions = array_values(array_unique(array_filter($missingDimensions)));

        return [
            'needs_clarification' => !empty($missingDimensions),
            'clarification_reason' => $clarificationReason,
            'missing_dimensions' => $missingDimensions,
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

    private function isAggregationFollowUpIntent(string $question): bool
    {
        return preg_match('/(若い順|古い順|昇順|降順|グラフ|グラフ化|チャート|棒グラフ|折れ線|円グラフ|表にして|表で|一覧にして|一覧で|テーブルで|表ではなくグラフ|グラフではなく表|並び替え|並べ替え|ソート|時間帯ごと|時間ごと|時刻帯|すべて表示|全件|全部|続きを表示|すべてのランキング|ランキングを表示)/iu', $question) === 1;
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
                if ($this->hasImplicitColumnMention($question, $column)) {
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
                if ($this->hasImplicitColumnMention($question, $column)) {
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

    private function findMentionedColumnNameAcrossFiles(string $question): ?string
    {
        $matchedColumns = [];
        foreach ($this->loadMetadata() as $file) {
            foreach ((array)($file['columns'] ?? []) as $column) {
                $column = (string)$column;
                if ($column === '') {
                    continue;
                }
                if ($this->hasImplicitColumnMention($question, $column)) {
                    $matchedColumns[$column] = true;
                }
            }
        }

        if (count($matchedColumns) === 1) {
            return array_key_first($matchedColumns);
        }

        return null;
    }

    private function findExplicitColumnReference(string $question): ?string
    {
        if (preg_match_all('/[「『"]([^」』"]+)[」』"]\s*(?:カラム|列|項目)/u', $question, $matches)) {
            foreach (array_reverse($matches[1]) as $candidate) {
                $candidate = trim((string)$candidate);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        if (preg_match_all('/(?:^|[\s　])([A-Za-z_][A-Za-z0-9_]*|[一-龠ぁ-んァ-ヶー]+)\s*(?:カラム|列|項目)/u', $question, $matches)) {
            foreach (array_reverse($matches[1]) as $candidate) {
                $candidate = trim((string)$candidate);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        if (preg_match('/(?:^|[\s　])([A-Za-z_][A-Za-z0-9_]*|[一-龠ぁ-んァ-ヶー]+)\s*から\s*(?:年月|月別|年別|日別|日時|日付|時刻|時間帯|時刻帯|時間ごと|時ごと)/u', $question, $matches) === 1) {
            $candidate = trim((string)($matches[1] ?? ''));
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function hasImplicitColumnMention(string $question, string $column): bool
    {
        $column = trim($column);
        if (!$this->shouldUseImplicitColumnMatch($column)) {
            return false;
        }

        return mb_stripos($question, $column, 0, 'UTF-8') !== false;
    }

    private function shouldUseImplicitColumnMatch(string $column): bool
    {
        return mb_strlen($column, 'UTF-8') > 1;
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

    private function findRecentAggregationContext(array $recentHistory): ?array
    {
        $userContext = $this->scanRecentAggregationContext($recentHistory, 'user');
        if ($userContext !== null) {
            return $userContext;
        }

        return $this->scanRecentAggregationContext($recentHistory, 'assistant');
    }

    private function scanRecentAggregationContext(array $recentHistory, ?string $roleFilter): ?array
    {
        for ($i = count($recentHistory) - 1; $i >= 0; $i--) {
            $role = (string)($recentHistory[$i]['role'] ?? '');
            if ($roleFilter !== null && $role !== $roleFilter) {
                continue;
            }

            $historyMessage = trim((string)($recentHistory[$i]['message'] ?? ''));
            if ($historyMessage === '') {
                continue;
            }

            $mentionedCsv = $this->findMentionedCsvFileName($historyMessage);
            $mentionedColumnTarget = $this->findMentionedColumnTarget($historyMessage);
            $explicitColumnReference = $this->findExplicitColumnReference($historyMessage);
            $globalColumn = $explicitColumnReference ?? $this->findMentionedColumnNameAcrossFiles($historyMessage);
            if ($mentionedCsv === null && !empty($mentionedColumnTarget['file_name'])) {
                $mentionedCsv = (string)$mentionedColumnTarget['file_name'];
            }

            $targetColumn = $mentionedColumnTarget['column_name'] ?? $globalColumn;
            if ($mentionedCsv === null && $targetColumn === null) {
                continue;
            }

            $targetValue = $this->extractRequestedTargetValue($historyMessage, [$targetColumn]);
            $hasDistinctIntent = preg_match('/(何種類|ユニーク|distinct|重複なし|種類数)/iu', $historyMessage) === 1;
            $hasRankingIntent = preg_match('/(ランキング|多い順|少ない順|上位|TOP|トップ)/iu', $historyMessage) === 1;
            $hasColumnExplainIntent = preg_match('/(どういう|どのような|説明|意味|何を表|どんなイベント|イベント.*説明|イベント.*意味|それぞれ.*説明)/u', $historyMessage) === 1;
            $hasColumnExistsIntent = preg_match('/(ありますよね|ありますか|存在しますか|入っていますか|含まれていますか|ありますよね。?)/u', $historyMessage) === 1;
            $hasDateIntent = $this->hasDateIntent($historyMessage);
            $hasTimeBandIntent = $this->hasTimeBandIntent($historyMessage);
            $hasSemanticCategoryIntent = preg_match('/(カテゴリ|カテゴリー|分類|傾向|どのような情報|どんな情報|分析してください|分析して|テーマ)/u', $historyMessage) === 1;
            $hasValueDistributionIntent = preg_match('/(全ての値|すべての値|各値|値ごとの件数|各レコード数|それぞれの件数|内訳|分布|一覧|表に|ランキング|多い順|少ない順|上位|TOP|トップ)/u', $historyMessage) === 1;
            $sortOrder = preg_match('/(若い順|降順|新しい順|新しいものから)/u', $historyMessage) === 1 ? 'desc' : 'asc';
            $usesValueOrdering = preg_match('/(若い順|古い順|昇順|降順|新しい順|新しいものから|古いものから)/u', $historyMessage) === 1 && !$hasRankingIntent;
            $wantsChart = preg_match('/(グラフ|グラフ化|チャート|可視化|```json:chart)/u', $historyMessage) === 1;
            $chartType = $this->detectRequestedChartType($historyMessage) ?? $this->detectRenderedChartType($historyMessage);
            $wantsTable = preg_match('/(表|一覧)/u', $historyMessage) === 1;
            $dateGranularity = $this->detectDateGranularity($historyMessage, $hasTimeBandIntent);

            $aggregationMode = 'value_distribution';
            if ($targetColumn !== null && $hasColumnExistsIntent) {
                $aggregationMode = 'column_exists';
            } elseif ($targetColumn !== null && $targetValue !== null && preg_match('/(件数|何件|件ありますか|件ある|集計)/u', $historyMessage) === 1) {
                $aggregationMode = 'exact_value_count';
            } elseif ($targetColumn !== null && $hasDistinctIntent && !$hasValueDistributionIntent) {
                $aggregationMode = 'distinct_count';
            } elseif ($targetColumn !== null && $hasColumnExplainIntent) {
                $aggregationMode = 'column_semantics';
            } elseif ($targetColumn !== null && ($hasDateIntent || $hasTimeBandIntent || $this->isDateLikeColumnName((string)$targetColumn))) {
                $aggregationMode = 'date_histogram';
            } elseif ($targetColumn !== null && $hasSemanticCategoryIntent) {
                $aggregationMode = 'semantic_category_summary';
            }

            $outputFormat = 'prose';
            if ($wantsChart) {
                $outputFormat = 'chart';
            } elseif ($wantsTable) {
                $outputFormat = 'table';
            }

            return [
                'last_success_route' => 'data_analysis.csv_agg',
                'target_file_name' => $mentionedCsv,
                'target_column' => $targetColumn,
                'target_value' => $targetValue,
                'aggregation_mode' => $aggregationMode,
                'date_granularity' => $aggregationMode === 'date_histogram' ? $dateGranularity : 'none',
                'sort_order' => $sortOrder,
                'uses_value_ordering' => $usesValueOrdering,
                'wants_chart' => $wantsChart,
                'chart_type' => $chartType,
                'wants_table' => $wantsTable,
                'output_format' => $outputFormat,
                'base_sql' => $this->extractLatestSqlBlock($historyMessage),
            ];
        }

        return null;
    }

    private function findSingleDateLikeColumnTarget(?string $targetFileName = null): ?array
    {
        if (($targetFileName === null || $targetFileName === '') && $this->countAvailableCsvFiles() !== 1) {
            return null;
        }

        $matches = [];
        foreach ($this->loadMetadata() as $file) {
            $fileName = (string)($file['file_name'] ?? '');
            if ($targetFileName !== null && $targetFileName !== '' && $fileName !== $targetFileName) {
                continue;
            }
            foreach ((array)($file['columns'] ?? []) as $column) {
                $column = (string)$column;
                if ($column === '' || !$this->isDateLikeColumnName($column)) {
                    continue;
                }
                $key = $fileName . '|' . $column;
                $matches[$key] = [
                    'file_name' => $fileName,
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

        if (preg_match_all('/([「『"])([^」』"]+)([」』"])/u', $question, $matches, PREG_OFFSET_CAPTURE)) {
            for ($i = count($matches[0]) - 1; $i >= 0; $i--) {
                $fullMatch = (string)($matches[0][$i][0] ?? '');
                $fullOffset = (int)($matches[0][$i][1] ?? 0);
                $candidate = trim((string)($matches[2][$i][0] ?? ''));
                $trailing = substr($question, $fullOffset + strlen($fullMatch));
                if (preg_match('/^\s*(?:カラム|列|項目)/u', $trailing) === 1) {
                    continue;
                }
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
        return preg_match('/(日付|日時|年月日|年月|月別|月ごと|日別|年別|年ごと|date|timestamp|時刻|日時は不要|月単位|\d{1,2}月分|先月|今月|今年)/iu', $question) === 1;
    }

    private function hasTimeBandIntent(string $question): bool
    {
        return preg_match('/(時間帯|時間帯別|時刻帯|時刻帯別|時間ごと|時ごと|hour|何時台|時台|ピーク時間|多い時間帯|午前|午後|曜日別)/iu', $question) === 1;
    }

    private function detectDateGranularity(string $question, bool $hasTimeBandIntent): string
    {
        if ($hasTimeBandIntent) {
            return 'hour';
        }

        if (preg_match('/(月別|月ごと|年月|日時は不要|月単位)/u', $question) === 1) {
            return 'month';
        }

        if (preg_match('/(年別|年ごと)/u', $question) === 1) {
            return 'year';
        }

        return 'day';
    }

    private function detectDateFilterMeta(string $question): array
    {
        if (preg_match('/(?:^|[^0-9])(\d{4})[\-\/](\d{1,2})分(?=$|[^0-9])/u', $question, $matches) === 1) {
            return [
                'mode' => 'explicit_year_month',
                'value' => sprintf('%04d-%02d', (int)($matches[1] ?? 0), (int)($matches[2] ?? 0)),
            ];
        }

        if (preg_match('/(\d{4})年(\d{1,2})月分/u', $question, $matches) === 1) {
            return [
                'mode' => 'explicit_year_month',
                'value' => sprintf('%04d-%02d', (int)($matches[1] ?? 0), (int)($matches[2] ?? 0)),
            ];
        }

        if (preg_match('/(?:^|[^0-9])(\d{4})[\-\/](\d{1,2})(?=$|[^0-9])/u', $question, $matches) === 1) {
            return [
                'mode' => 'explicit_year_month',
                'value' => sprintf('%04d-%02d', (int)($matches[1] ?? 0), (int)($matches[2] ?? 0)),
            ];
        }

        if (preg_match('/(\d{4})年(\d{1,2})月/u', $question, $matches) === 1) {
            return [
                'mode' => 'explicit_year_month',
                'value' => sprintf('%04d-%02d', (int)($matches[1] ?? 0), (int)($matches[2] ?? 0)),
            ];
        }

        if (preg_match('/(\d{1,2})月分/u', $question, $matches) === 1) {
            return [
                'mode' => 'month_only',
                'value' => sprintf('%02d', (int)($matches[1] ?? 0)),
            ];
        }

        if (preg_match('/先月/u', $question) === 1 || preg_match('/今月/u', $question) === 1) {
            return [
                'mode' => 'relative_month',
                'value' => preg_match('/先月/u', $question) === 1 ? '先月' : '今月',
            ];
        }

        if (preg_match('/今年/u', $question) === 1) {
            return [
                'mode' => 'relative_year',
                'value' => '今年',
            ];
        }

        return [
            'mode' => '',
            'value' => '',
        ];
    }

    private function detectCountOnlyRequest(string $question, array $dateFilterMeta): bool
    {
        if ((string)($dateFilterMeta['mode'] ?? '') !== 'explicit_year_month') {
            return false;
        }

        $hasCountIntent = preg_match('/(件数を(?:出して|教えて)|何件(?:ですか)?|件数)/u', $question) === 1;
        if (!$hasCountIntent) {
            return false;
        }

        $hasHistogramIntent = preg_match('/(月別に集計|月別に見て|月ごと|日別に集計|日別に見て|日ごと|日別件数|推移|グラフ|グラフ化|チャート|可視化)/u', $question) === 1;

        return !$hasHistogramIntent;
    }

    private function detectTemporalBucketMode(string $question, bool $hasTimeBandIntent): string
    {
        if (preg_match('/曜日別/u', $question) === 1) {
            return 'weekday';
        }

        if (preg_match('/(午前|午後)/u', $question) === 1) {
            return 'am_pm';
        }

        if (preg_match('/(時間帯別|時刻帯別|時間帯ごと|時刻帯ごと)/u', $question) === 1) {
            return 'time_band';
        }

        if ($hasTimeBandIntent) {
            return 'hour';
        }

        return 'none';
    }

    private function detectDateAxisCandidates(?string $targetFileName, ?string $targetColumn): array
    {
        if (
            $targetColumn !== null
            && $targetColumn !== ''
            && $this->isDateLikeColumnName($targetColumn)
            && $this->dateLikeColumnExistsInScope($targetFileName, $targetColumn)
        ) {
            return [$targetColumn];
        }

        $candidates = [];
        foreach ($this->loadMetadata() as $file) {
            if ($targetFileName !== null && $targetFileName !== '' && (string)($file['file_name'] ?? '') !== $targetFileName) {
                continue;
            }

            foreach ((array)($file['columns'] ?? []) as $column) {
                $column = trim((string)$column);
                if ($column === '' || !$this->isDateLikeColumnName($column)) {
                    continue;
                }
                $candidates[$column] = true;
            }
        }

        return array_keys($candidates);
    }

    private function dateLikeColumnExistsInScope(?string $targetFileName, string $targetColumn): bool
    {
        foreach ($this->loadMetadata() as $file) {
            if ($targetFileName !== null && $targetFileName !== '' && (string)($file['file_name'] ?? '') !== $targetFileName) {
                continue;
            }

            $columns = array_map('strval', (array)($file['columns'] ?? []));
            if (in_array($targetColumn, $columns, true)) {
                return true;
            }
        }

        return false;
    }

    private function isRecentContextCarryOverIntent(string $question): bool
    {
        if ($this->isAggregationFollowUpIntent($question)) {
            return true;
        }

        if (preg_match('/((この|その|同じ|前回|直前|先ほど|さっき|引き続き|続けて).*(CSV|ファイル|列|カラム|項目|集計|条件|ランキング|グラフ))|((CSV|ファイル|列|カラム|項目|集計|条件|ランキング|グラフ).*(この|その|同じ|前回|直前|先ほど|さっき))/u', $question) === 1) {
            return true;
        }

        return false;
    }

    private function isDateLikeColumnName(string $column): bool
    {
        return preg_match('/(登録日|更新日|作成日|公開日|受付日|発生日|日付|日時|年月日|年月|date|datetime|timestamp|access_date|created_at|updated_at|time|時刻)/iu', $column) === 1;
    }

    private function detectRequestedChartType(string $question): ?string
    {
        if (preg_match('/(円グラフ|pie)/iu', $question) === 1) {
            return 'pie';
        }

        if (preg_match('/(棒グラフ|bar)/iu', $question) === 1) {
            return 'bar';
        }

        if (preg_match('/(折れ線|折れ線グラフ|line)/iu', $question) === 1) {
            return 'line';
        }

        return null;
    }

    private function detectRenderedChartType(string $message): ?string
    {
        if (preg_match('/"type"\s*:\s*"([^"]+)"/u', $message, $matches) === 1) {
            $type = strtolower(trim((string)($matches[1] ?? '')));
            if (in_array($type, ['bar', 'line', 'pie'], true)) {
                return $type;
            }
        }

        return null;
    }

    private function extractLatestSqlBlock(string $message): ?string
    {
        if (preg_match_all('/```sql\s*(.*?)```/isu', $message, $matches) !== 1) {
            return null;
        }

        $sqlBlocks = array_values(array_filter(array_map(static function ($value): string {
            return trim((string)$value);
        }, $matches[1])));

        if (empty($sqlBlocks)) {
            return null;
        }

        return array_pop($sqlBlocks);
    }

    private function mergeRecentAggregationContext(?array $recentAggregationContext, ?array $recentArtifactState): ?array
    {
        if ($recentArtifactState === null || $recentArtifactState === []) {
            return $recentAggregationContext;
        }

        if ($recentAggregationContext === null || $recentAggregationContext === []) {
            return $recentArtifactState;
        }

        $merged = $recentAggregationContext;
        foreach ($recentArtifactState as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $merged[$key] = $value;
        }

        return $merged;
    }

    private function loadMetadata(): array
    {
        if ($this->metadataLoader === null) {
            return [];
        }

        $metadata = call_user_func($this->metadataLoader);
        return is_array($metadata) ? $metadata : [];
    }

    private function countAvailableCsvFiles(): int
    {
        return count(array_values(array_filter($this->loadMetadata(), static function ($file): bool {
            return trim((string)($file['file_name'] ?? '')) !== '';
        })));
    }

    private function targetsAllCsv(string $question): bool
    {
        return preg_match('/(全てのCSV|すべてのCSV|全CSV|登録済みCSV全体|すべてのデータ|全データ|全ファイル)/u', $question) === 1;
    }
}
