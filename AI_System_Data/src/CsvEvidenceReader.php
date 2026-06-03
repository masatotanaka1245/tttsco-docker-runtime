<?php

class CsvEvidenceReader
{
    private $pdo;
    private $projectId;
    private $originalMessage;
    private $ollamaHost;
    private $model;
    private $promptKey;
    private $projectContext;
    private $metadataCatalog;
    private $summaryFormatter;
    private $outputModeInstructions;

    public function __construct(
        PDO $pdo,
        int $projectId,
        string $originalMessage,
        string $ollamaHost,
        string $model,
        string $promptKey,
        string $projectContext,
        CsvMetadataCatalog $metadataCatalog,
        CsvSummaryFormatter $summaryFormatter,
        callable $outputModeInstructions
    ) {
        $this->pdo = $pdo;
        $this->projectId = $projectId;
        $this->originalMessage = $originalMessage;
        $this->ollamaHost = $ollamaHost;
        $this->model = $model;
        $this->promptKey = $promptKey;
        $this->projectContext = $projectContext;
        $this->metadataCatalog = $metadataCatalog;
        $this->summaryFormatter = $summaryFormatter;
        $this->outputModeInstructions = $outputModeInstructions;
    }

    public function countRows(): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM project_csv_rows r
            JOIN project_csv_files f ON f.id = r.csv_file_id
            WHERE f.project_id = ?
        ");
        $stmt->execute([$this->projectId]);
        return (int)$stmt->fetchColumn();
    }

    public function loadRowsByKeywords(array $terms, int $limit): array
    {
        $terms = array_values(array_filter($terms, fn($term) => trim((string)$term) !== ''));
        if (!$terms) {
            return ['rows' => [], 'hit_count' => 0, 'terms' => [], 'limited' => false, 'mode' => 'keyword'];
        }

        $termConditions = [];
        $params = [$this->projectId];
        foreach ($terms as $term) {
            $like = $this->escapeLikeTerm($term);
            $termConditions[] = "(CAST(r.row_data AS CHAR) LIKE ? OR f.file_name LIKE ? OR f.column_headers LIKE ?)";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where = "f.project_id = ? AND (" . implode(" OR ", $termConditions) . ")";
        $countStmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM project_csv_files f
            JOIN project_csv_rows r ON f.id = r.csv_file_id
            WHERE {$where}
        ");
        $countStmt->execute($params);
        $hitCount = (int)$countStmt->fetchColumn();

        $rowsStmt = $this->pdo->prepare("
            SELECT
                f.id AS csv_file_id,
                f.file_name,
                f.column_headers,
                f.row_count,
                r.id AS csv_row_id,
                r.row_index,
                r.row_data
            FROM project_csv_files f
            JOIN project_csv_rows r ON f.id = r.csv_file_id
            WHERE {$where}
            ORDER BY f.id ASC, r.row_index ASC
            LIMIT " . max(1, $limit) . "
        ");
        $rowsStmt->execute($params);

        return [
            'rows' => $rowsStmt->fetchAll(PDO::FETCH_ASSOC),
            'hit_count' => $hitCount,
            'terms' => $terms,
            'limited' => $hitCount > $limit,
            'mode' => 'keyword',
        ];
    }

    public function loadAllRows(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                f.id AS csv_file_id,
                f.file_name,
                f.column_headers,
                f.row_count,
                r.id AS csv_row_id,
                r.row_index,
                r.row_data
            FROM project_csv_files f
            JOIN project_csv_rows r ON f.id = r.csv_file_id
            WHERE f.project_id = ?
            ORDER BY f.id ASC, r.row_index ASC
        ");
        $stmt->execute([$this->projectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function loadSampleRows(int $limit): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                f.id AS csv_file_id,
                f.file_name,
                f.column_headers,
                f.row_count,
                r.id AS csv_row_id,
                r.row_index,
                r.row_data
            FROM project_csv_files f
            JOIN project_csv_rows r ON f.id = r.csv_file_id
            WHERE f.project_id = ?
            ORDER BY f.id ASC, r.row_index ASC
            LIMIT " . max(1, $limit) . "
        ");
        $stmt->execute([$this->projectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function buildNoHitAnswer(array $terms, array $files, int $totalRows): string
    {
        $lines = [];
        $lines[] = "CSVレコードを検索しましたが、指定内容に直接一致するデータは見つかりませんでした。";
        $lines[] = "";
        $lines[] = "- 検索語: " . implode(" / ", $terms);
        $lines[] = "- 検索ヒット件数: 0件";
        $lines[] = "- 登録済みCSV総レコード数: {$totalRows}件";
        $lines[] = "";
        $lines[] = "### 登録済みCSVの範囲";
        foreach ($files as $file) {
            $lines[] = "- {$file['file_name']}: {$file['row_count']}件 / 項目: " . (empty($file['columns']) ? "項目情報なし" : implode(" / ", $file['columns']));
        }
        $lines[] = "";
        $lines[] = "検索語をもう少しCSV内の列名・値に近い表現へ変えると、該当レコードを絞り込んで読解できます。";
        return implode("\n", $lines);
    }

    public function buildLargeOverviewAnswer(array $files, array $sampleRows, int $totalRows, bool $diagramMode = false): string
    {
        $sampleSummary = $this->summaryFormatter->summarizeRows($sampleRows);
        $allColumns = [];
        foreach ($files as $file) {
            foreach ($file['columns'] as $column) {
                $allColumns[$column] = true;
            }
        }

        $lines = [];
        $lines[] = "登録済みCSVの件数が多いため、まず検索・メタデータ・代表サンプルから概況を整理しました。";
        $lines[] = "";
        $lines[] = "- 対象CSVファイル数: " . count($files) . "件";
        $lines[] = "- 登録済みCSV総レコード数: {$totalRows}件";
        $lines[] = "- 代表確認レコード数: " . count($sampleRows) . "件";
        $lines[] = "- ユニーク項目数: " . count($allColumns) . "件";
        $lines[] = "";

        foreach ($files as $file) {
            $lines[] = "### {$file['file_name']}";
            $lines[] = "- 登録行数: {$file['row_count']}件";
            $lines[] = "- 項目: " . (empty($file['columns']) ? "項目情報なし" : implode(" / ", $file['columns']));
            $lines[] = "- 内容の見立て: " . $this->summaryFormatter->describePurpose($file['columns']);

            foreach ($sampleSummary as $summary) {
                if ($summary['file_name'] !== $file['file_name'] || empty($summary['samples'])) {
                    continue;
                }
                $sampleLines = [];
                foreach ($summary['samples'] as $column => $samples) {
                    if (!$samples) {
                        continue;
                    }
                    $sampleLines[] = "{$column}=" . implode("、", $samples);
                    if (count($sampleLines) >= 3) {
                        break;
                    }
                }
                if ($sampleLines) {
                    $lines[] = "- 値の例: " . implode(" / ", $sampleLines);
                }
                break;
            }
            $lines[] = "";
        }

        $lines[] = "### 次の読解方針";
        $lines[] = "大規模CSVでは、質問文から検索語を抽出して該当レコードを先に絞り込み、その範囲をAI読解に回す方式にしています。";
        $lines[] = "今回のように検索語が弱い広い質問では、全件AI読解ではなく概況を先に返すことで、処理時間の肥大化を避けます。";

        if ($diagramMode) {
            $chartBlock = $this->buildLargeOverviewChartBlock($files, $totalRows);
            if ($chartBlock !== '') {
                $lines[] = "";
                $lines[] = "### グラフ";
                $lines[] = $chartBlock;
            }
        }

        return implode("\n", $lines);
    }

    private function buildLargeOverviewChartBlock(array $files, int $totalRows): string
    {
        if (empty($files) || $totalRows <= 0) {
            return '';
        }

        usort($files, fn($a, $b) => ((int)($b['row_count'] ?? 0)) <=> ((int)($a['row_count'] ?? 0)));
        $topFiles = array_slice($files, 0, 6);
        if (empty($topFiles)) {
            return '';
        }

        $labels = [];
        $data = [];
        foreach ($topFiles as $file) {
            $fileName = (string)($file['file_name'] ?? 'CSV');
            $rowCount = (int)($file['row_count'] ?? 0);
            if ($rowCount <= 0) {
                continue;
            }
            $labels[] = $fileName;
            $data[] = $rowCount;
        }

        if (empty($labels)) {
            return '';
        }

        $chart = [
            'type' => count($labels) <= 5 ? 'pie' : 'bar',
            'title' => '登録済みCSVのレコード件数構成',
            'labels' => $labels,
            'datasets' => [[
                'label' => 'レコード数',
                'data' => $data,
            ]],
        ];

        $fence = str_repeat("\x60", 3);
        return "登録済みCSVごとのレコード件数を比較しやすいよう、上位ファイルを図表化しました。"
            . "\n" . $fence . "json:chart\n"
            . json_encode($chart, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n" . $fence;
    }

    public function buildCollectionSummary(array $rows, array $searchResult = []): string
    {
        $files = [];
        foreach ($rows as $row) {
            $fileId = (int)$row['csv_file_id'];
            if (!isset($files[$fileId])) {
                $headers = $this->metadataCatalog->parseHeaders((string)$row['column_headers']);
                $files[$fileId] = [
                    'file_name' => $row['file_name'],
                    'declared_rows' => (int)$row['row_count'],
                    'collected_rows' => 0,
                    'columns' => $headers,
                ];
            }
            $files[$fileId]['collected_rows']++;
        }

        return "【CSV証拠収集サマリー】\n"
            . "- 対象CSVファイル数: " . count($files) . "\n"
            . "- 対象CSVレコード数: " . count($rows) . "\n"
            . (!empty($searchResult['terms']) ? "- 検索語: " . implode(" / ", $searchResult['terms']) . "\n" : "")
            . (isset($searchResult['hit_count']) ? "- 検索ヒット件数: " . (int)$searchResult['hit_count'] . "\n" : "")
            . (!empty($searchResult['limited']) ? "- 注記: ヒット件数が多いため読解対象を上限内に制限\n" : "")
            . "```json\n" . json_encode(array_values($files), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n```";
    }

    public function chunkRows(array $rows, int $maxRows, int $maxChars): array
    {
        $chunks = [];
        $current = [];
        $chars = 0;

        foreach ($rows as $row) {
            $line = $this->formatRow($row);
            $lineLen = mb_strlen($line);
            if ($current && (count($current) >= $maxRows || ($chars + $lineLen) > $maxChars)) {
                $chunks[] = $current;
                $current = [];
                $chars = 0;
            }
            $current[] = $row;
            $chars += $lineLen;
        }
        if ($current) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    public function formatRow(array $row): string
    {
        $data = json_decode((string)$row['row_data'], true);
        if (!is_array($data)) {
            $data = ['raw' => (string)$row['row_data']];
        }

        $pairs = [];
        foreach ($data as $key => $value) {
            $valueText = trim(preg_replace('/\s+/u', ' ', (string)$value));
            if ($valueText === '') {
                continue;
            }
            if (mb_strlen($valueText) > 180) {
                $valueText = mb_substr($valueText, 0, 180) . '...';
            }
            $pairs[] = "{$key}={$valueText}";
        }

        return "#{$row['row_index']} {$row['file_name']} | " . implode('; ', $pairs);
    }

    public function formatBatch(array $batch): string
    {
        return implode("\n", array_map(fn($row) => $this->formatRow($row), $batch));
    }

    public function analyzeBatch(array $batch, int $batchNo, int $totalBatches): string
    {
        $callStart = microtime(true);
        $batchText = $this->formatBatch($batch);
        $system = "あなたはCSVデータベースレコードを精読する分析官です。\n"
            . "SQL集計ではなく、提示された全レコードを証拠として読み、ユーザー質問に関係する情報だけを抽出してください。\n"
            . "行番号・ファイル名を根拠として必ず残してください。存在しない列や値は作らないでください。\n"
            . "出力はJSONのみです。";

        $user = "【ユーザー質問】\n{$this->originalMessage}\n\n"
            . "【バッチ情報】{$batchNo}/{$totalBatches}\n"
            . "【CSV証拠レコード】\n{$batchText}\n\n"
            . "以下のJSON形式で返してください。\n"
            . '{"batch_summary":"このバッチで読み取れる要点","relevant_rows":[{"file":"ファイル名","row_index":1,"evidence":"根拠","reason":"質問との関係"}],"findings":["発見事項"],"unanswered":["このバッチだけでは判断できない点"]}';

        try {
            $thought = "";
            chatLogger("[CSV-EVIDENCE] Ollamaバッチ読解API送信 - batch: {$batchNo}/{$totalBatches} | systemChars: " . mb_strlen($system) . " | userChars: " . mb_strlen($user));
            $res = callOllamaChat($this->ollamaHost, $this->model, $system, $user, 'json', ['temperature' => 0.0, 'top_p' => 0.1, 'num_ctx' => 8192], $thought);
            chatLogger("[CSV-EVIDENCE] Ollamaバッチ読解API受信 - batch: {$batchNo}/{$totalBatches} | rawChars: " . mb_strlen($res) . " | elapsed: " . $this->elapsedSeconds($callStart));
            $decoded = json_decode($res, true);
            if (is_array($decoded)) {
                return "```json\n" . json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n```";
            }
            return $res;
        } catch (Exception $e) {
            chatLogger("[CSV-EVIDENCE] バッチ読解に失敗。簡易要約へフォールバック: " . $e->getMessage());
            return "【バッチ{$batchNo}簡易証拠】\n" . mb_substr($batchText, 0, 4000);
        }
    }

    public function synthesizeAnswer(array $rows, array $batchFindings): string
    {
        $callStart = microtime(true);
        $findingsText = implode("\n\n", $batchFindings);
        if (mb_strlen($findingsText) > 12000) {
            $findingsText = mb_substr($findingsText, 0, 12000) . "\n...[統合用に後半を省略]";
        }

        require_once __DIR__ . '/PromptManager.php';
        $system = PromptManager::getBasePrompt($this->promptKey) . "\n"
            . "あなたはCSV証拠読解結果を統合して、ユーザーの質問に直接答える分析官です。\n"
            . "対象データは、質問意図に基づいてSQL検索で絞り込んだCSVレコードです。ランキングや列別内訳へ逃げず、検索対象全体から回答してください。\n"
            . "回答には、対象ファイル数・対象行数・主要な結論・根拠行・注意点を含めてください。\n"
            . "根拠のない断定、存在しない列や値の作成は禁止です。"
            . call_user_func($this->outputModeInstructions);

        $user = $this->projectContext . "\n\n"
            . "【ユーザー質問】\n{$this->originalMessage}\n\n"
            . "【対象CSVレコード数】" . count($rows) . "件\n"
            . "【保存済みバッチ読解結果】\n{$findingsText}\n\n"
            . "上記の保存済み分析結果を統合し、日本語Markdownで最終回答を作成してください。";

        try {
            $thought = "";
            chatLogger("[CSV-EVIDENCE] Ollama統合回答API送信 - systemChars: " . mb_strlen($system) . " | userChars: " . mb_strlen($user));
            $answer = callOllamaChat($this->ollamaHost, $this->model, $system, $user, null, ['temperature' => 0.0, 'top_p' => 0.1, 'num_ctx' => 8192], $thought);
            chatLogger("[CSV-EVIDENCE] Ollama統合回答API受信 - responseChars: " . mb_strlen($answer) . " | elapsed: " . $this->elapsedSeconds($callStart));
            return trim($answer) ?: "CSVデータ {$this->projectId} の対象レコード " . count($rows) . "件を確認しましたが、回答文を生成できませんでした。";
        } catch (Exception $e) {
            chatLogger("[CSV-EVIDENCE] 統合回答生成に失敗: " . $e->getMessage());
            return "CSVデータベースレコード全 " . count($rows) . "件を対象に読解しましたが、AI統合処理でエラーが発生しました。\n\n詳細: " . $e->getMessage();
        }
    }

    private function escapeLikeTerm(string $term): string
    {
        return '%' . str_replace('\\', '', $term) . '%';
    }

    private function elapsedSeconds(float $start): string
    {
        return number_format(microtime(true) - $start, 2) . '秒';
    }
}
