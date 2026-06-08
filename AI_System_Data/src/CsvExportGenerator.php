<?php

final class CsvExportGenerator
{
    private PDO $pdo;
    private $logger;

    public function __construct(PDO $pdo, ?callable $logger = null)
    {
        $this->pdo = $pdo;
        $this->logger = $logger;
    }

    public function createFromChat(
        int $projectId,
        int $chatHistoryId,
        int $userId,
        string $question,
        string $answer
    ): ?array {
        $table = $this->extractBestMarkdownTable($answer);
        if ($table === null) {
            $this->log('[CSV-EXPORT] Markdown表が見つからないため、生成CSVの登録をスキップしました。');
            return null;
        }

        $headers = $table['headers'];
        $rows = $table['rows'];
        if (empty($headers) || empty($rows)) {
            $this->log('[CSV-EXPORT] 表ヘッダーまたは行データが不足しているため、生成CSVの登録をスキップしました。');
            return null;
        }

        $fileName = $this->buildFileName($question, $chatHistoryId);

        $this->pdo->beginTransaction();
        try {
            $stmtFile = $this->pdo->prepare(
                'INSERT INTO project_csv_files (project_id, file_name, column_headers, row_count, created_at) VALUES (?, ?, ?, ?, NOW())'
            );
            $stmtFile->execute([
                $projectId,
                $fileName,
                json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                count($rows),
            ]);
            $csvFileId = (int)$this->pdo->lastInsertId();

            $stmtRow = $this->pdo->prepare(
                'INSERT INTO project_csv_rows (csv_file_id, row_index, row_data, created_at) VALUES (?, ?, ?, NOW())'
            );
            foreach ($rows as $index => $row) {
                $rowData = [];
                foreach ($headers as $offset => $header) {
                    $rowData[$header] = (string)($row[$offset] ?? '');
                }

                $stmtRow->execute([
                    $csvFileId,
                    $index + 1,
                    json_encode($rowData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }

            $this->pdo->commit();
            $this->log('[CSV-EXPORT] 生成CSVを登録しました。csv_file_id=' . $csvFileId . ' | rows=' . count($rows));

            return [
                'csv_file_id' => $csvFileId,
                'file_name' => $fileName,
                'row_count' => count($rows),
                'column_headers' => $headers,
                'created_by' => $userId,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function extractBestMarkdownTable(string $answer): ?array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $answer);
        $lines = explode("\n", $normalized);
        $best = null;

        $count = count($lines);
        for ($i = 0; $i < $count - 1; $i++) {
            if (!$this->looksLikeTableRow($lines[$i]) || !$this->looksLikeAlignmentRow($lines[$i + 1])) {
                continue;
            }

            $block = [$lines[$i], $lines[$i + 1]];
            $j = $i + 2;
            while ($j < $count && $this->looksLikeTableRow($lines[$j])) {
                $block[] = $lines[$j];
                $j++;
            }

            $parsed = $this->parseMarkdownTableBlock($block);
            if ($parsed === null) {
                continue;
            }

            if ($best === null || count($parsed['rows']) > count($best['rows'])) {
                $best = $parsed;
            }

            $i = $j - 1;
        }

        return $best;
    }

    private function parseMarkdownTableBlock(array $block): ?array
    {
        if (count($block) < 3) {
            return null;
        }

        $headers = $this->splitMarkdownRow($block[0]);
        if (empty($headers)) {
            return null;
        }

        $rows = [];
        foreach (array_slice($block, 2) as $line) {
            $cells = $this->splitMarkdownRow($line);
            if (empty($cells)) {
                continue;
            }

            $cells = array_slice(array_pad($cells, count($headers), ''), 0, count($headers));
            $hasContent = false;
            foreach ($cells as $cell) {
                if (trim($cell) !== '') {
                    $hasContent = true;
                    break;
                }
            }
            if ($hasContent) {
                $rows[] = $cells;
            }
        }

        if (empty($rows)) {
            return null;
        }

        return [
            'headers' => array_map(fn(string $cell): string => $this->normalizeHeader($cell), $headers),
            'rows' => $rows,
        ];
    }

    private function splitMarkdownRow(string $line): array
    {
        $trimmed = trim($line);
        $trimmed = trim($trimmed, '|');
        if ($trimmed === '') {
            return [];
        }

        return array_map(
            static fn(string $cell): string => trim($cell),
            explode('|', $trimmed)
        );
    }

    private function looksLikeTableRow(string $line): bool
    {
        return substr_count($line, '|') >= 2;
    }

    private function looksLikeAlignmentRow(string $line): bool
    {
        $cells = $this->splitMarkdownRow($line);
        if (empty($cells)) {
            return false;
        }

        foreach ($cells as $cell) {
            if (preg_match('/^:?-{3,}:?$/', str_replace(' ', '', $cell)) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function normalizeHeader(string $header): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($header)) ?? trim($header);
        return $normalized !== '' ? $normalized : 'column';
    }

    private function buildFileName(string $question, int $chatHistoryId): string
    {
        $base = 'AI集計表_' . date('Ymd_His');
        if (preg_match('/[「『"]([^」』"]+)[」』"]/u', $question, $matches) === 1) {
            $hint = preg_replace('/[^\p{L}\p{N}_-]+/u', '_', trim((string)$matches[1])) ?? '';
            $hint = trim($hint, '_');
            if ($hint !== '') {
                $base .= '_' . mb_substr($hint, 0, 24);
            }
        }

        return $base . '_' . $chatHistoryId . '.csv';
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $message);
        }
    }
}
