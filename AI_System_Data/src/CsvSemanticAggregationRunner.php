<?php

class CsvSemanticAggregationRunner
{
    private $statusSender;
    private $logger;
    private $setFinalResponse;
    private $appendSubAnswer;
    private $insertReasoningStep;
    private $completeRoute;
    private $elapsedFormatter;
    private $ollamaHost;
    private $workerModel;

    public function __construct(
        callable $statusSender,
        callable $setFinalResponse,
        callable $appendSubAnswer,
        callable $insertReasoningStep,
        callable $completeRoute,
        callable $elapsedFormatter,
        string $ollamaHost,
        string $workerModel,
        ?callable $logger = null
    ) {
        $this->statusSender = $statusSender;
        $this->setFinalResponse = $setFinalResponse;
        $this->appendSubAnswer = $appendSubAnswer;
        $this->insertReasoningStep = $insertReasoningStep;
        $this->completeRoute = $completeRoute;
        $this->elapsedFormatter = $elapsedFormatter;
        $this->ollamaHost = $ollamaHost;
        $this->workerModel = $workerModel;
        $this->logger = $logger;
    }

    public function runColumnSemantics(
        array $plan,
        float $routeStart,
        CsvAggregationTargetResolver $targetResolver,
        CsvAggregationAnswerFormatter $formatter,
        bool $diagramMode
    ): bool {
        $target = $targetResolver->findFileTarget((string)($plan['target_file_name'] ?? ''));
        $targetColumn = (string)($plan['target_column'] ?? '');
        if ($target === null || $targetColumn === '') {
            $this->log('[CSV-AGG] column_semantics の対象ファイルまたは列を解決できませんでした。CSV証拠読解ルートへフォールバックします。');
            return false;
        }

        $this->sendStatus(2, $this->statusMessage($plan, '🧭 説明対象のCSVと列を特定しています...'));
        ($this->insertReasoningStep)(
            1,
            $this->reasoningStepTitle($plan, 'CSV column semantics プリフライト', 'CSV column semantics の再編集プリフライト'),
            $this->reasoningStepLead($plan)
            . "【集計計画】\n" . json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n\n【対象CSV】\n" . json_encode($target, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n\n【補助推定モデル】\n{$this->workerModel}"
        );

        $this->sendStatus(3, $this->statusMessage($plan, '📊 対象列の主要な値を集計し、意味を整理しています...'));
        $result = $targetResolver->executeValueDistributionQuery($target, $targetColumn);
        $rows = $result['rows'] ?? [];
        if (empty($rows)) {
            $this->log('[CSV-AGG] column_semantics の集計結果が0件でした。CSV証拠読解ルートへフォールバックします。');
            return false;
        }

        $analysis = $this->analyzeCsvColumnSemantics($plan, $target, $targetColumn, $rows);
        if (empty($analysis['items'])) {
            $this->log('[CSV-AGG] column_semantics の意味推定に失敗したため、値分布回答へフォールバックします。');
            $finalResponse = $formatter->buildValueDistributionAnswer($plan, $target, $rows);
        } else {
            $finalResponse = $formatter->buildColumnSemanticsAnswer($plan, $target, $rows, $analysis, $diagramMode);
        }

        ($this->setFinalResponse)($finalResponse);
        ($this->appendSubAnswer)($finalResponse);
        ($this->insertReasoningStep)(
            90,
            $this->reasoningStepTitle($plan, 'CSV column semantics の実行結果', 'CSV column semantics の再編集結果'),
            $this->reasoningStepLead($plan)
            . "### {$target['file_name']} / {$targetColumn}\n```sql\n{$result['sql']}\n```\n- groups: " . count($rows)
                . "\n- semantics: " . json_encode($analysis, JSON_UNESCAPED_UNICODE)
        );
        $this->log("[CSV-AGG] column_semantics ルート完了 - groups: " . count($rows) . " | elapsed: " . $this->elapsed($routeStart));
        ($this->completeRoute)();
        return true;
    }

    public function runSemanticCategory(
        array $plan,
        float $routeStart,
        CsvAggregationTargetResolver $targetResolver,
        CsvAggregationAnswerFormatter $formatter,
        bool $diagramMode
    ): bool {
        $target = $targetResolver->findFileTarget((string)($plan['target_file_name'] ?? ''));
        $targetColumn = (string)($plan['target_column'] ?? '');
        if ($target === null || $targetColumn === '') {
            $this->log('[CSV-AGG] semantic_category_summary の対象ファイルまたは列を解決できませんでした。CSV証拠読解ルートへフォールバックします。');
            return false;
        }

        $this->sendStatus(2, $this->statusMessage($plan, '🧭 集計対象のCSVと列を特定しています...'));
        ($this->insertReasoningStep)(
            1,
            $this->reasoningStepTitle($plan, 'CSV semantic category プリフライト', 'CSV semantic category の再編集プリフライト'),
            $this->reasoningStepLead($plan)
            . "【集計計画】\n" . json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n\n【対象CSV】\n" . json_encode($target, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n\n【補助推定モデル】\n{$this->workerModel}"
        );

        $this->sendStatus(3, $this->statusMessage($plan, '📊 対象列の値分布をカテゴリ別に整理しています...'));
        $result = $targetResolver->executeValueDistributionQuery($target, $targetColumn);
        $rows = $result['rows'] ?? [];
        if (empty($rows)) {
            $this->log('[CSV-AGG] semantic_category_summary の集計結果が0件でした。CSV証拠読解ルートへフォールバックします。');
            return false;
        }

        $analysis = $this->analyzeCsvValueCategories($plan, $target, $targetColumn, $rows);
        if (empty($analysis['categories'])) {
            $this->log('[CSV-AGG] semantic_category_summary のカテゴリ化に失敗したため、値分布回答へフォールバックします。');
            $finalResponse = $formatter->buildValueDistributionAnswer($plan, $target, $rows);
        } else {
            $finalResponse = $formatter->buildSemanticCategoryAnswer($plan, $target, $rows, $analysis, $diagramMode);
        }

        ($this->setFinalResponse)($finalResponse);
        ($this->appendSubAnswer)($finalResponse);
        ($this->insertReasoningStep)(
            90,
            $this->reasoningStepTitle($plan, 'CSV semantic category の実行結果', 'CSV semantic category の再編集結果'),
            $this->reasoningStepLead($plan)
            . "### {$target['file_name']} / {$targetColumn}\n```sql\n{$result['sql']}\n```\n- groups: " . count($rows)
                . "\n- category_summary: " . json_encode($analysis, JSON_UNESCAPED_UNICODE)
        );
        $this->log("[CSV-AGG] semantic_category_summary ルート完了 - groups: " . count($rows) . " | elapsed: " . $this->elapsed($routeStart));
        ($this->completeRoute)();
        return true;
    }

    public function runCategoryFilteredDistribution(
        array $plan,
        float $routeStart,
        CsvAggregationTargetResolver $targetResolver,
        CsvAggregationAnswerFormatter $formatter,
        bool $diagramMode
    ): bool {
        $target = $targetResolver->findFileTarget((string)($plan['target_file_name'] ?? ''));
        $targetColumn = (string)($plan['target_column'] ?? '');
        $sourceColumn = (string)($plan['source_column'] ?? '');
        $categoryLabel = (string)($plan['category_filter_label'] ?? '');
        if ($target === null || $targetColumn === '' || $sourceColumn === '' || $categoryLabel === '') {
            $this->log('[CSV-AGG] category_filtered_distribution の対象解決に失敗したため、CSV証拠読解ルートへフォールバックします。');
            return false;
        }

        $this->sendStatus(2, $this->statusMessage($plan, '🧭 カテゴリ対象のCSV・列・条件を特定しています...'));
        $sourceResult = $targetResolver->executeValueDistributionQuery($target, $sourceColumn);
        $sourceRows = $sourceResult['rows'] ?? [];
        if (empty($sourceRows)) {
            $this->log('[CSV-AGG] category_filtered_distribution の前段値分布が0件でした。CSV証拠読解ルートへフォールバックします。');
            return false;
        }

        $matchedValues = $this->resolveCategoryMatchedValues($plan, $target, $sourceColumn, $categoryLabel, $sourceRows);
        if (empty($matchedValues)) {
            $this->log('[CSV-AGG] category_filtered_distribution のカテゴリ判定結果が空でした。CSV証拠読解ルートへフォールバックします。');
            return false;
        }

        $this->sendStatus(3, $this->statusMessage($plan, '📊 該当カテゴリのレコードを抽出し、件数を集計しています...'));
        $itemSortOrder = !empty($plan['uses_value_ordering'])
            ? (string)($plan['sort_order'] ?? 'asc')
            : null;
        $result = $targetResolver->executeFilteredDistributionQuery($target, $sourceColumn, $targetColumn, $matchedValues, $itemSortOrder);
        $rows = $result['rows'] ?? [];
        if (empty($rows)) {
            $this->log('[CSV-AGG] category_filtered_distribution の集計結果が0件でした。CSV証拠読解ルートへフォールバックします。');
            return false;
        }

        $finalResponse = $formatter->buildCategoryFilteredDistributionAnswer($plan, $target, $rows, $matchedValues, $diagramMode);
        ($this->setFinalResponse)($finalResponse);
        ($this->appendSubAnswer)($finalResponse);
        ($this->insertReasoningStep)(
            90,
            $this->reasoningStepTitle($plan, 'CSV category filtered distribution の実行結果', 'CSV category filtered distribution の再編集結果'),
            $this->reasoningStepLead($plan)
            . "### {$target['file_name']} / {$targetColumn}\n```sql\n{$result['sql']}\n```\n- category: {$categoryLabel}\n- matched_values: " . json_encode($matchedValues, JSON_UNESCAPED_UNICODE)
        );
        $this->log("[CSV-AGG] category_filtered_distribution ルート完了 - groups: " . count($rows) . " | matched_values: " . count($matchedValues) . " | elapsed: " . $this->elapsed($routeStart));
        ($this->completeRoute)();
        return true;
    }

    private function analyzeCsvValueCategories(array $plan, array $target, string $targetColumn, array $rows): array
    {
        $items = [];
        foreach (array_slice($rows, 0, 80) as $row) {
            $item = trim((string)($row['item'] ?? ''));
            if ($item === '') {
                continue;
            }
            $items[] = [
                'item' => $item,
                'count' => (int)($row['record_count'] ?? 0),
            ];
        }

        if (empty($items)) {
            return [];
        }

        $systemPrompt = "あなたはCSV列分析の補助AIです。"
            . "与えられた列値と件数だけを根拠に、テーマ別カテゴリへ整理してください。"
            . "外部知識の補完や、データにない抽象論は禁止です。"
            . "出力はJSONのみ。カテゴリ名、件数、代表例、短い見立てを返してください。"
            . "件数は与えられた値リストの count を合算して求め、examples には必ず実在する item を入れてください。";

        $userPrompt = "対象CSV: " . (string)($target['file_name'] ?? '対象CSV') . "\n"
            . "対象列: {$targetColumn}\n"
            . $this->buildArtifactStatePromptNote($plan)
            . "値一覧(JSON):\n"
            . json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n\n以下のJSON形式で返してください。\n"
            . "{\n"
            . '  "overall_summary": "全体の傾向",'
            . "\n"
            . '  "categories": [{"name":"カテゴリ名","count":0,"examples":["代表例1","代表例2"],"insight":"短い見立て"}],'
            . "\n"
            . '  "observations": ["補足1","補足2"]'
            . "\n}";

        try {
            $this->log("[CSV-AGG] semantic_category_summary のAIカテゴリ分析を実行します - model: {$this->workerModel} | items: " . count($items));
            $res = callOllamaChat(
                $this->ollamaHost,
                $this->workerModel,
                $systemPrompt,
                $userPrompt,
                'json',
                ["temperature" => 0.0, "top_p" => 0.1, "num_ctx" => 4096]
            );
            $decoded = json_decode((string)$res, true);
            return is_array($decoded) ? $decoded : [];
        } catch (Throwable $e) {
            $this->log('[CSV-AGG] semantic_category_summary のAIカテゴリ分析に失敗: ' . $e->getMessage());
            return [];
        }
    }

    private function analyzeCsvColumnSemantics(array $plan, array $target, string $targetColumn, array $rows): array
    {
        $items = [];
        foreach (array_slice($rows, 0, 20) as $row) {
            $item = trim((string)($row['item'] ?? ''));
            if ($item === '') {
                continue;
            }
            $items[] = [
                'item' => $item,
                'count' => (int)($row['record_count'] ?? 0),
            ];
        }

        if (empty($items)) {
            return [];
        }

        $systemPrompt = "あなたはCSV列の値説明を行う補助AIです。"
            . "与えられた列名・値名・件数だけを根拠に、各値が何を表していそうかを簡潔に説明してください。"
            . "外部仕様を断定せず、名前から読み取れる範囲に留め、曖昧なら推定であることを明示してください。"
            . "出力はJSONのみ。";

        $userPrompt = "対象CSV: " . (string)($target['file_name'] ?? '対象CSV') . "\n"
            . "対象列: {$targetColumn}\n"
            . $this->buildArtifactStatePromptNote($plan)
            . "値一覧(JSON):\n"
            . json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n\n以下のJSON形式で返してください。\n"
            . "{\n"
            . '  "overall_summary": "列全体の短い説明",'
            . "\n"
            . '  "items": [{"name":"値","count":0,"group":"近い分類","inferred_meaning":"値名から読み取れる意味"}],'
            . "\n"
            . '  "observations": ["補足1","補足2"]'
            . "\n}";

        try {
            $this->log("[CSV-AGG] column_semantics のAI説明生成を実行します - model: {$this->workerModel} | items: " . count($items));
            $res = callOllamaChat(
                $this->ollamaHost,
                $this->workerModel,
                $systemPrompt,
                $userPrompt,
                'json',
                ['temperature' => 0.1]
            );
            $decoded = json_decode($res, true);
            return is_array($decoded) ? $decoded : [];
        } catch (Throwable $e) {
            $this->log('[CSV-AGG] column_semantics のAI説明生成に失敗: ' . $e->getMessage());
            return [];
        }
    }

    private function resolveCategoryMatchedValues(array $plan, array $target, string $sourceColumn, string $categoryLabel, array $rows): array
    {
        $items = [];
        foreach (array_slice($rows, 0, 100) as $row) {
            $item = trim((string)($row['item'] ?? ''));
            if ($item === '') {
                continue;
            }
            $items[] = [
                'item' => $item,
                'count' => (int)($row['record_count'] ?? 0),
            ];
        }

        if (empty($items)) {
            return [];
        }

        $systemPrompt = "あなたはCSV列分類の補助AIです。"
            . "与えられたカテゴリ名に該当する項目だけを、実在する item から選別してください。"
            . "推測で新しい項目を作らず、values には与えられた item だけを入れてください。"
            . "出力はJSONのみです。";

        $userPrompt = "対象CSV: " . (string)($target['file_name'] ?? '対象CSV') . "\n"
            . "判定列: {$sourceColumn}\n"
            . "カテゴリ名: {$categoryLabel}\n"
            . $this->buildArtifactStatePromptNote($plan)
            . "候補値(JSON):\n"
            . json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n\n以下のJSON形式で返してください。\n"
            . "{\n"
            . '  "values": ["該当するitem1", "該当するitem2"],'
            . "\n"
            . '  "reason": "短い説明"'
            . "\n}";

        try {
            $this->log("[CSV-AGG] category_filtered_distribution のカテゴリ判定を実行します - model: {$this->workerModel} | category: {$categoryLabel} | items: " . count($items));
            $res = callOllamaChat(
                $this->ollamaHost,
                $this->workerModel,
                $systemPrompt,
                $userPrompt,
                'json',
                ["temperature" => 0.0, "top_p" => 0.1, "num_ctx" => 4096]
            );
            $decoded = json_decode((string)$res, true);
            $values = array_values(array_filter(array_map('strval', (array)($decoded['values'] ?? []))));
            $validItems = array_flip(array_map(fn($item) => (string)$item['item'], $items));
            $values = array_values(array_filter($values, fn($value) => isset($validItems[$value])));
            return array_values(array_unique($values));
        } catch (Throwable $e) {
            $this->log('[CSV-AGG] category_filtered_distribution のカテゴリ判定に失敗: ' . $e->getMessage());
            return [];
        }
    }

    private function sendStatus(int $step, string $message): void
    {
        call_user_func($this->statusSender, 'status', [
            'step' => $step,
            'message' => $message,
        ]);
    }

    private function elapsed(float $start): string
    {
        return (string)call_user_func($this->elapsedFormatter, $start);
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $message);
        }
    }

    private function buildArtifactStatePromptNote(array $plan): string
    {
        $lines = [];
        if (!empty($plan['route_lock_active'])) {
            $lines[] = "引き継ぎ状態: route_lock継続中";
        }
        if (!empty($plan['aggregation_mode'])) {
            $lines[] = "直前モード: " . (string)$plan['aggregation_mode'];
        }
        if (!empty($plan['output_format'])) {
            $lines[] = "希望出力: " . (string)$plan['output_format'];
        }
        if (!empty($plan['base_sql'])) {
            $lines[] = "直前SQL:\n```sql\n" . trim((string)$plan['base_sql']) . "\n```";
        }

        if (empty($lines)) {
            return '';
        }

        return "【引き継ぎ成果品ステート】\n" . implode("\n", $lines) . "\n";
    }

    private function statusMessage(array $plan, string $defaultMessage): string
    {
        if (empty($plan['route_lock_active'])) {
            return $defaultMessage;
        }

        $outputFormat = (string)($plan['output_format'] ?? 'prose');
        $modeLabel = match ($outputFormat) {
            'chart' => 'グラフ出力',
            'table' => '表形式出力',
            default => '集計結果',
        };

        return "🔁 直前の成果品ステートを引き継ぎ、{$modeLabel}を再編集しています...";
    }

    private function reasoningStepTitle(array $plan, string $defaultTitle, string $followUpTitle): string
    {
        return !empty($plan['route_lock_active']) ? $followUpTitle : $defaultTitle;
    }

    private function reasoningStepLead(array $plan): string
    {
        if (empty($plan['route_lock_active'])) {
            return '';
        }

        $outputFormat = (string)($plan['output_format'] ?? 'prose');
        $modeLabel = match ($outputFormat) {
            'chart' => 'グラフ中心',
            'table' => '表中心',
            default => '文章中心',
        };

        return "【編集モード】\n直前の成果品ステートを引き継ぎ、{$modeLabel}の再編集として処理します。\n\n";
    }
}
