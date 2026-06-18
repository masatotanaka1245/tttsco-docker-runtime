<?php

require_once __DIR__ . '/CsvMetadataCatalog.php';
require_once __DIR__ . '/CsvSearchTermExtractor.php';

class ChatHistoryContextResolver
{
    private $pdo;
    private $projectId;
    private $metadataCatalog;
    private $extractor;
    private $files = null;

    public function __construct(PDO $pdo, int $projectId)
    {
        $this->pdo = $pdo;
        $this->projectId = $projectId;
        $this->metadataCatalog = new CsvMetadataCatalog($pdo, $projectId);
        $this->extractor = new CsvSearchTermExtractor(static function (string $text): string {
            return (string)$text;
        });
    }

    public function findMentionedCsvFileName(string $message): ?string
    {
        return $this->extractor->findMentionedCsvFileName($message, $this->loadFiles());
    }

    public function findMentionedCsvColumnTarget(string $message): ?array
    {
        $mentionedCsv = $this->findMentionedCsvFileName($message);
        if ($mentionedCsv !== null) {
            $fileScopedMatches = [];
            foreach ($this->loadFiles() as $file) {
                $fileName = (string)($file['file_name'] ?? '');
                if ($fileName !== $mentionedCsv) {
                    continue;
                }
                foreach ((array)($file['columns'] ?? []) as $column) {
                    $column = (string)$column;
                    if ($column === '') {
                        continue;
                    }
                    if ($this->hasImplicitColumnMention($message, $column)) {
                        $fileScopedMatches[$fileName . '|' . $column] = [
                            'file_name' => $fileName,
                            'column_name' => $column,
                        ];
                    }
                }
                break;
            }

            if (count($fileScopedMatches) === 1) {
                return array_values($fileScopedMatches)[0];
            }
        }

        $matches = [];

        foreach ($this->loadFiles() as $file) {
            $fileName = (string)($file['file_name'] ?? '');
            foreach ((array)($file['columns'] ?? []) as $column) {
                $column = (string)$column;
                if ($column === '') {
                    continue;
                }
                if ($this->hasImplicitColumnMention($message, $column)) {
                    $matches[$fileName . '|' . $column] = [
                        'file_name' => $fileName,
                        'column_name' => $column,
                    ];
                }
            }
        }

        return count($matches) === 1 ? array_values($matches)[0] : null;
    }

    public function findRecentCsvContext(array $recentHistory): ?array
    {
        $artifactState = $this->findRecentArtifactState($recentHistory);
        if ($artifactState !== null) {
            return [
                'target_file_name' => $artifactState['target_file_name'] ?? null,
                'target_column' => $artifactState['target_column'] ?? null,
                'source_role' => $artifactState['source_role'] ?? null,
                'source_message' => $artifactState['source_message'] ?? null,
            ];
        }

        return null;
    }

    public function findRecentArtifactState(array $recentHistory): ?array
    {
        $userContext = $this->scanRecentCsvContext($recentHistory, 'user');
        $assistantContext = $this->scanRecentCsvContext($recentHistory, 'assistant');

        if ($userContext === null) {
            return $assistantContext;
        }
        if ($assistantContext === null) {
            return $userContext;
        }

        return $this->scoreArtifactState($assistantContext) > $this->scoreArtifactState($userContext)
            ? $assistantContext
            : $userContext;
    }

    public function isLikelyStateContinuationDirective(string $message): bool
    {
        $message = trim($message);
        if ($message === '') {
            return false;
        }

        if (preg_match('/((これまで|今まで|過去|直近).*(会話|やりとり|チャット|履歴).*(まとめ|要約|整理|報告書|レポート|PDF))|((会話|やりとり|チャット|履歴).*(報告書|レポート|PDF).*(作成|作って|出力|生成))/u', $message) === 1) {
            return false;
        }

        if (preg_match('/(別の|他の|新しい|違う).*(CSV|csv|ファイル|列|カラム|項目)|((CSV|csv|ファイル|列|カラム|項目).*(別の|他の|新しい|違う))/u', $message) === 1) {
            return false;
        }

        if (preg_match('/(若い順|古い順|昇順|降順|新しい順|新しいものから|古いものから|グラフ|グラフ化|チャート|棒グラフ|折れ線|円グラフ|可視化|並び替え|並べ替え|ソート|時間帯ごと|時間ごと|時刻帯|すべて表示|全件|全部|続きを表示|すべてのランキング|ランキングを表示|表ではなくグラフ|グラフではなく表|表にして|一覧にして)/iu', $message) === 1) {
            return true;
        }

        if (preg_match('/((この|その|同じ|前回|直前|先ほど|さっき|引き続き|続けて).*(集計|条件|グラフ|表|並び順|並び替え|チャート))|((集計|条件|グラフ|表|並び順|並び替え|チャート).*(この|その|同じ|前回|直前|先ほど|さっき))/u', $message) === 1) {
            return true;
        }

        return false;
    }

    private function scanRecentCsvContext(array $recentHistory, ?string $roleFilter): ?array
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
            $mentionedColumnTarget = $this->findMentionedCsvColumnTarget($historyMessage);
            $explicitColumnReference = $this->findExplicitColumnReference($historyMessage);
            if ($role === 'assistant') {
                $explicitColumnReference = $this->resolveKnownColumnName($explicitColumnReference, $mentionedCsv);
            }
            $globalColumn = $explicitColumnReference ?? $this->findMentionedCsvColumnNameAcrossFiles($historyMessage);
            if ($mentionedCsv === null && !empty($mentionedColumnTarget['file_name'])) {
                $mentionedCsv = (string)$mentionedColumnTarget['file_name'];
            }

            $targetColumn = $mentionedColumnTarget['column_name'] ?? $globalColumn;

            if ($mentionedCsv === null && $targetColumn === null) {
                continue;
            }

            $targetValue = $role === 'assistant'
                ? null
                : $this->extractRequestedTargetValue($historyMessage, [$targetColumn]);
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

            if ($mentionedCsv !== null || $targetColumn !== null) {
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
                    'source_role' => $role,
                    'source_message' => $historyMessage,
                ];
            }
        }

        return null;
    }

    private function findMentionedCsvColumnNameAcrossFiles(string $message): ?string
    {
        $matchedColumns = [];
        foreach ($this->loadFiles() as $file) {
            foreach ((array)($file['columns'] ?? []) as $column) {
                $column = (string)$column;
                if ($column === '') {
                    continue;
                }
                if ($this->hasImplicitColumnMention($message, $column)) {
                    $matchedColumns[$column] = true;
                }
            }
        }

        if (count($matchedColumns) === 1) {
            return array_key_first($matchedColumns);
        }

        return null;
    }

    private function findExplicitColumnReference(string $message): ?string
    {
        if (preg_match_all('/[「『"]([^」』"]+)[」』"]\s*(?:カラム|列|項目)/u', $message, $matches)) {
            foreach (array_reverse($matches[1]) as $candidate) {
                $candidate = trim((string)$candidate);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        if (preg_match_all('/(?:^|[\s　])([A-Za-z_][A-Za-z0-9_]*|[一-龠ぁ-んァ-ヶー]+)\s*(?:カラム|列|項目)/u', $message, $matches)) {
            foreach (array_reverse($matches[1]) as $candidate) {
                $candidate = trim((string)$candidate);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        if (preg_match('/(?:^|[\s　])([A-Za-z_][A-Za-z0-9_]*|[一-龠ぁ-んァ-ヶー]+)\s*から\s*(?:年月|月別|年別|日別|日時|日付|時刻|時間帯|時刻帯|時間ごと|時ごと)/u', $message, $matches) === 1) {
            $candidate = trim((string)($matches[1] ?? ''));
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveKnownColumnName(?string $candidate, ?string $fileName = null): ?string
    {
        $candidate = trim((string)$candidate);
        if ($candidate === '') {
            return null;
        }

        foreach ($this->loadFiles() as $file) {
            $currentFileName = (string)($file['file_name'] ?? '');
            if ($fileName !== null && $fileName !== '' && $currentFileName !== $fileName) {
                continue;
            }

            foreach ((array)($file['columns'] ?? []) as $column) {
                $column = trim((string)$column);
                if ($column === '') {
                    continue;
                }

                if (mb_strtolower($candidate, 'UTF-8') === mb_strtolower($column, 'UTF-8')) {
                    return $column;
                }
            }
        }

        return null;
    }

    private function hasImplicitColumnMention(string $message, string $column): bool
    {
        $column = trim($column);
        if (!$this->shouldUseImplicitColumnMatch($column)) {
            return false;
        }

        return mb_stripos($message, $column, 0, 'UTF-8') !== false;
    }

    private function shouldUseImplicitColumnMatch(string $column): bool
    {
        return mb_strlen($column, 'UTF-8') > 1;
    }

    private function loadFiles(): array
    {
        if ($this->files === null) {
            $this->files = $this->metadataCatalog->loadFiles();
        }

        return $this->files;
    }

    private function hasDateIntent(string $message): bool
    {
        return preg_match('/(日付|日時|年月日|年月|月別|月ごと|年別|年ごと|日別|date|timestamp|時刻|日時は不要|月単位)/iu', $message) === 1;
    }

    private function hasTimeBandIntent(string $message): bool
    {
        return preg_match('/(時間帯|時刻帯|時間ごと|時ごと|hour|何時台|時台|ピーク時間|多い時間帯)/iu', $message) === 1;
    }

    private function isDateLikeColumnName(string $columnName): bool
    {
        return preg_match('/(date|datetime|timestamp|time|年月日|年月|日付|日時|時刻)/iu', $columnName) === 1;
    }

    private function detectDateGranularity(string $message, bool $hasTimeBandIntent): string
    {
        if ($hasTimeBandIntent) {
            return 'hour';
        }
        if (preg_match('/(月別|月ごと|年月|日時は不要|月単位)/u', $message) === 1) {
            return 'month';
        }
        if (preg_match('/(年別|年ごと)/u', $message) === 1) {
            return 'year';
        }
        return 'day';
    }

    private function detectRequestedChartType(string $message): ?string
    {
        if (preg_match('/(棒グラフ|bar chart|bar)/iu', $message) === 1) {
            return 'bar';
        }
        if (preg_match('/(折れ線|線グラフ|line chart|line)/iu', $message) === 1) {
            return 'line';
        }
        if (preg_match('/(円グラフ|pie chart|pie)/iu', $message) === 1) {
            return 'pie';
        }

        return null;
    }

    private function detectRenderedChartType(string $message): ?string
    {
        if (preg_match('/"type"\s*:\s*"([^"]+)"/u', $message, $matches) === 1) {
            $candidate = trim((string)($matches[1] ?? ''));
            return $candidate !== '' ? $candidate : null;
        }

        return null;
    }

    private function extractRequestedTargetValue(string $message, array $columnCandidates = []): ?string
    {
        if (preg_match_all('/[「『"]([^」』"]+)[」』"]/u', $message, $matches)) {
            $quotedValues = array_values(array_filter(array_map(static function ($value): string {
                return trim((string)$value);
            }, $matches[1])));

            foreach (array_reverse($quotedValues) as $candidate) {
                if (!$this->isColumnCandidateValue($candidate, $columnCandidates)) {
                    return $candidate;
                }
            }
        }

        if (preg_match('/(\d{4}年\d{1,2}月(?:\d{1,2}日)?)/u', $message, $matches) === 1) {
            $candidate = trim((string)($matches[1] ?? ''));
            if ($candidate !== '' && !$this->isColumnCandidateValue($candidate, $columnCandidates)) {
                return $candidate;
            }
        }

        if (preg_match('/(\d{4}[\/\-]\d{1,2}(?:[\/\-]\d{1,2})?)/u', $message, $matches) === 1) {
            $candidate = trim((string)($matches[1] ?? ''));
            if ($candidate !== '' && !$this->isColumnCandidateValue($candidate, $columnCandidates)) {
                return $candidate;
            }
        }

        return null;
    }

    private function isColumnCandidateValue(string $candidate, array $columnCandidates): bool
    {
        foreach ($columnCandidates as $columnCandidate) {
            $columnCandidate = trim((string)$columnCandidate);
            if ($columnCandidate !== '' && $candidate === $columnCandidate) {
                return true;
            }
        }

        return false;
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

    private function scoreArtifactState(array $state): int
    {
        $score = 0;

        if (!empty($state['target_file_name'])) {
            $score += 2;
        }
        if (!empty($state['target_column'])) {
            $score += 4;
        }
        if (!empty($state['base_sql'])) {
            $score += 3;
        }
        if (($state['aggregation_mode'] ?? 'value_distribution') !== 'value_distribution') {
            $score += 2;
        }
        if (!empty($state['wants_chart']) || !empty($state['wants_table'])) {
            $score += 1;
        }

        return $score;
    }
}
