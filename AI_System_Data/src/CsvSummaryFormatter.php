<?php

class CsvSummaryFormatter
{
    private $metadataCatalog;

    public function __construct(CsvMetadataCatalog $metadataCatalog)
    {
        $this->metadataCatalog = $metadataCatalog;
    }

    public function buildMetadataAnswer(array $files): string
    {
        $totalRows = array_sum(array_column($files, 'row_count'));
        $allColumns = [];
        foreach ($files as $file) {
            foreach ($file['columns'] as $column) {
                $allColumns[$column] = true;
            }
        }

        $lines = [];
        $lines[] = "登録済みCSVには、以下の項目が含まれています。";
        $lines[] = "";
        $lines[] = "- 対象CSVファイル数: " . count($files) . "件";
        $lines[] = "- 登録行数: {$totalRows}件";
        $lines[] = "- ユニーク項目数: " . count($allColumns) . "件";
        $lines[] = "";

        foreach ($files as $file) {
            $lines[] = "### {$file['file_name']}";
            $lines[] = "- 行数: {$file['row_count']}件";
            $lines[] = "- 項目: " . (empty($file['columns']) ? "項目情報なし" : implode(" / ", $file['columns']));
            $lines[] = "";
        }

        if ($allColumns) {
            $lines[] = "### 全CSVを通した項目一覧";
            foreach (array_keys($allColumns) as $column) {
                $lines[] = "- {$column}";
            }
        }

        return implode("\n", $lines);
    }

    public function buildSmallSummaryAnswer(array $rows, array $searchResult = []): string
    {
        $files = $this->summarizeRows($rows);
        $totalRows = count($rows);
        $allColumns = [];
        foreach ($files as $file) {
            foreach ($file['columns'] as $column) {
                $allColumns[$column] = true;
            }
        }

        $lines = [];
        $lines[] = "登録済みCSVの内容を確認しました。";
        $lines[] = "";
        $lines[] = "- 対象CSVファイル数: " . count($files) . "件";
        $lines[] = "- 対象レコード数: {$totalRows}件";
        if (!empty($searchResult['terms'])) {
            $lines[] = "- 検索語: " . implode(" / ", $searchResult['terms']);
            $lines[] = "- 検索ヒット件数: " . (int)($searchResult['hit_count'] ?? $totalRows) . "件";
            if (!empty($searchResult['limited'])) {
                $lines[] = "- 読解対象: ヒット件数が多いため先頭 {$totalRows} 件を代表証拠として使用";
            }
        }
        $lines[] = "- 確認できた項目数: " . count($allColumns) . "件";
        $lines[] = "";

        foreach ($files as $file) {
            $lines[] = "### {$file['file_name']}";
            $lines[] = "- 登録行数: {$file['collected_rows']}件";
            $lines[] = "- 項目: " . (empty($file['columns']) ? "項目情報なし" : implode(" / ", $file['columns']));
            $lines[] = "- 内容の概要: " . $this->describePurpose($file['columns']);

            $sampleLines = [];
            foreach ($file['samples'] as $column => $samples) {
                if (!$samples) {
                    continue;
                }
                $sampleLines[] = "{$column}=" . implode("、", $samples);
                if (count($sampleLines) >= 4) {
                    break;
                }
            }
            if ($sampleLines) {
                $lines[] = "- 値の例: " . implode(" / ", $sampleLines);
            }
            $lines[] = "";
        }

        $lines[] = "### 全体の見立て";
        $lines[] = "このCSV群は、列名と登録値から見ると、ユーザーやアカウント識別、ログインメール、氏名、部署、言語設定などの管理情報を含むデータです。";
        if (!empty($searchResult['terms'])) {
            $lines[] = "現時点では検索条件に該当した {$totalRows} 件を対象に確認しており、質問意図に近いCSVレコードを優先して概要を作成しています。";
        } else {
            $lines[] = "現時点では全 {$totalRows} 件を対象に確認しており、特定列だけのランキングではなく、登録されているCSVレコード全体をもとに概要を作成しています。";
        }

        return implode("\n", $lines);
    }

    public function summarizeRows(array $rows): array
    {
        $files = [];
        foreach ($rows as $row) {
            $fileId = (int)$row['csv_file_id'];
            if (!isset($files[$fileId])) {
                $files[$fileId] = [
                    'file_name' => (string)$row['file_name'],
                    'declared_rows' => (int)$row['row_count'],
                    'collected_rows' => 0,
                    'columns' => $this->parseHeaders((string)$row['column_headers']),
                    'samples' => [],
                ];
            }

            $files[$fileId]['collected_rows']++;
            $data = json_decode((string)$row['row_data'], true);
            if (!is_array($data)) {
                continue;
            }

            foreach ($data as $column => $value) {
                $valueText = trim(preg_replace('/\s+/u', ' ', (string)$value));
                if ($valueText === '') {
                    continue;
                }
                if (mb_strlen($valueText) > 60) {
                    $valueText = mb_substr($valueText, 0, 60) . '...';
                }
                if (!isset($files[$fileId]['samples'][$column])) {
                    $files[$fileId]['samples'][$column] = [];
                }
                if (count($files[$fileId]['samples'][$column]) < 3 && !in_array($valueText, $files[$fileId]['samples'][$column], true)) {
                    $files[$fileId]['samples'][$column][] = $valueText;
                }
            }
        }

        return array_values($files);
    }

    public function describePurpose(array $columns): string
    {
        $columnText = mb_strtolower(implode(' ', $columns));
        $descriptions = [];
        if (preg_match('/(username|login email|identifier|email|メール|ユーザー)/u', $columnText)) {
            $descriptions[] = 'ユーザーやログインアカウントの識別情報';
        }
        if (preg_match('/(one-time password|recovery code|password|認証|復旧)/u', $columnText)) {
            $descriptions[] = '認証・復旧に関する情報';
        }
        if (preg_match('/(language|locale|言語)/u', $columnText)) {
            $descriptions[] = '言語設定やローカライズに関する情報';
        }
        if (preg_match('/(first name|last name|氏名|名前|department|部署)/u', $columnText)) {
            $descriptions[] = '氏名や部署などの利用者属性';
        }

        if (!$descriptions) {
            return 'CSVの各行に登録された項目値を管理する構造化データ';
        }

        return implode('、', array_unique($descriptions)) . 'を中心とした構造化データ';
    }

    private function parseHeaders(string $rawHeaders): array
    {
        return $this->metadataCatalog->parseHeaders($rawHeaders);
    }
}
