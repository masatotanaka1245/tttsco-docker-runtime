<?php

require_once __DIR__ . '/EmbeddingEngine.php';

class ReportGenerator
{
    private PDO $pdo;
    private string $basePath;
    private string $ollamaHost;
    private string $embedModel;
    private $logger;

    public function __construct(PDO $pdo, string $basePath, string $ollamaHost, ?callable $logger = null, string $embedModel = 'mxbai-embed-large')
    {
        $this->pdo = $pdo;
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
        $this->ollamaHost = rtrim($ollamaHost, '/');
        $this->embedModel = $embedModel;
        $this->logger = $logger;
    }

    public function createFromChat(
        int $projectId,
        int $chatHistoryId,
        int $userId,
        string $question,
        string $answer,
        ?array $evaluation = null,
        ?string $reasoningSessionId = null
    ): array {
        $project = $this->loadProject($projectId);
        $evidence = $this->loadEvidenceSummary($projectId, $reasoningSessionId);
        $title = 'AI報告書_' . date('Ymd_His') . '.pdf';
        $basename = 'report_' . uniqid('', true);
        $projectDir = $this->basePath . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . '01_RAG_Documents' . DIRECTORY_SEPARATOR . $projectId;
        if (!is_dir($projectDir)) {
            @mkdir($projectDir, 0777, true);
        }

        $htmlPath = $projectDir . DIRECTORY_SEPARATOR . $basename . '.html';
        $pdfPath = $projectDir . DIRECTORY_SEPARATOR . $basename . '.pdf';
        $html = $this->buildHtml($project, $question, $answer, $evaluation, $evidence);
        file_put_contents($htmlPath, $html, LOCK_EX);

        $converter = $this->renderPdf($htmlPath, $pdfPath);
        if (!is_file($pdfPath) || filesize($pdfPath) === 0) {
            throw new RuntimeException('報告書PDFの生成に失敗しました。PDF変換コマンドを確認してください。');
        }

        $plainText = $this->buildSearchText($project, $question, $answer, $evaluation, $evidence);
        $chunks = $this->splitTextIntoChunks($plainText, 700);
        $embeddingEngine = new EmbeddingEngine($this->ollamaHost, $this->embedModel);
        $chunkEmbeddings = [];
        foreach ($chunks as $idx => $chunk) {
            $chunkEmbeddings[$idx] = $embeddingEngine->embed($chunk);
            $this->log("[REPORT] embedding生成: chunk=" . ($idx + 1) . '/' . count($chunks));
        }
        $dbPath = '01_RAG_Documents/' . $projectId . '/' . basename($pdfPath);

        $this->pdo->beginTransaction();
        try {
            $stmtDoc = $this->pdo->prepare('INSERT INTO documents (project_id, title, file_path, created_at) VALUES (?, ?, ?, NOW())');
            $stmtDoc->execute([$projectId, $title, $dbPath]);
            $docId = (int)$this->pdo->lastInsertId();

            $stmtChunk = $this->pdo->prepare('INSERT INTO doc_chunks (doc_id, page_number, chunk_text, embedding, image_description, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            foreach ($chunks as $idx => $chunk) {
                $stmtChunk->execute([
                    $docId,
                    1,
                    $chunk,
                    json_encode($chunkEmbeddings[$idx]),
                    'AI生成報告書（Report Mode）'
                ]);
                $this->log("[REPORT] doc_chunks登録: doc_id={$docId} chunk=" . ($idx + 1) . '/' . count($chunks));
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->log("[REPORT] 報告書PDF登録完了: doc_id={$docId} | converter={$converter} | path={$dbPath}");
        return [
            'document_id' => $docId,
            'title' => $title,
            'file_path' => $dbPath,
            'converter' => $converter
        ];
    }

    private function renderPdf(string $htmlPath, string $pdfPath): string
    {
        $wkhtmltopdf = $this->resolveBinary('wkhtmltopdf', [
            getenv('WKHTMLTOPDF_PATH') ?: '',
            $this->basePath . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'wkhtmltopdf.exe',
            'C:\\Program Files\\wkhtmltopdf\\bin\\wkhtmltopdf.exe',
            'C:\\Program Files (x86)\\wkhtmltopdf\\bin\\wkhtmltopdf.exe',
        ]);
        if ($wkhtmltopdf !== '') {
            $this->log('[REPORT] PDF変換ツール検出: wkhtmltopdf=' . $wkhtmltopdf);
            $cmd = escapeshellarg($wkhtmltopdf)
                . ' --encoding utf-8 --enable-local-file-access '
                . escapeshellarg($htmlPath) . ' '
                . escapeshellarg($pdfPath) . ' 2>&1';
            $output = (string)shell_exec($cmd);
            if (is_file($pdfPath) && filesize($pdfPath) > 0) {
                $this->log('[REPORT] wkhtmltopdf実行成功: size=' . filesize($pdfPath) . ' bytes');
                return 'wkhtmltopdf';
            }
            $this->log('[REPORT] wkhtmltopdf実行失敗: ' . $this->summarizeCommandOutput($output));
        }

        $browser = $this->resolveBinary('google-chrome', [
            getenv('CHROME_PATH') ?: '',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/usr/bin/google-chrome',
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
            'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
            'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
        ]);
        if ($browser !== '') {
            $this->log('[REPORT] PDF変換ツール検出: headless browser=' . $browser);
            $fileUrl = 'file:///' . str_replace(DIRECTORY_SEPARATOR, '/', realpath($htmlPath));
            $cmd = escapeshellarg($browser)
                . ' --headless --disable-gpu --no-sandbox --disable-dev-shm-usage --print-to-pdf=' . escapeshellarg($pdfPath)
                . ' ' . escapeshellarg($fileUrl) . ' 2>&1';
            $output = (string)shell_exec($cmd);
            if (is_file($pdfPath) && filesize($pdfPath) > 0) {
                $this->log('[REPORT] headless browser実行成功: size=' . filesize($pdfPath) . ' bytes');
                return 'headless_browser';
            }
            $this->log('[REPORT] headless browser実行失敗: ' . $this->summarizeCommandOutput($output));
        }

        throw new RuntimeException(
            'PDF変換ツールが見つかりません。'
            . ' tools/wkhtmltopdf.exe、WKHTMLTOPDF_PATH、CHROME_PATH、または Chrome/Edge headless を利用できるようにしてください。'
        );
    }

    private function summarizeCommandOutput(string $output): string
    {
        $output = trim($output);
        if ($output === '') {
            return '出力なし';
        }

        $lines = preg_split('/\r\n|\r|\n/', $output) ?: [$output];
        $important = array_values(array_filter($lines, static function ($line) {
            return stripos($line, 'error') !== false
                || stripos($line, 'failed') !== false
                || stripos($line, 'written to file') !== false
                || stripos($line, 'warning') !== false;
        }));
        $targetLines = $important ?: $lines;
        $summary = implode("\n", array_slice($targetLines, -5));

        return mb_substr($summary, 0, 1000);
    }

    private function resolveBinary(string $binaryName, array $candidatePaths): string
    {
        $detected = trim((string)@shell_exec('command -v ' . escapeshellarg($binaryName) . ' 2>&1'));
        if ($detected !== '' && is_file($detected)) {
            return $detected;
        }
        foreach ($candidatePaths as $candidate) {
            if ($candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    private function buildHtml(array $project, string $question, string $answer, ?array $evaluation, array $evidence): string
    {
        $projectName = $this->e($project['project_name'] ?? 'プロジェクト');
        $createdAt = date('Y年m月d日 H:i');
        $score = $evaluation['total_score'] ?? null;
        $scoreHtml = $score !== null ? '<span class="pill">品質評価 ' . $this->e((string)$score) . '点</span>' : '';
        $summary = $this->makeShortSummary($answer);

        return '<!doctype html><html lang="ja"><head><meta charset="utf-8">'
            . '<title>' . $projectName . ' AI報告書</title>'
            . '<style>'
            . '@page{size:A4;margin:18mm 16mm;}body{font-family:"Yu Gothic","Meiryo",sans-serif;color:#1f2937;line-height:1.72;font-size:12px;}'
            . 'h1{font-size:24px;margin:0 0 8px;color:#111827;}h2{font-size:16px;margin:24px 0 8px;border-bottom:1px solid #cbd5e1;padding-bottom:5px;color:#334155;}h3{font-size:13px;margin:16px 0 6px;color:#475569;}'
            . '.cover{border-left:6px solid #4F5D95;padding:18px 20px;margin-bottom:20px;background:#f8fafc;}'
            . '.meta{color:#64748b;font-size:11px;margin-top:8px}.pill{display:inline-block;border:1px solid #c7d2fe;color:#3730a3;background:#eef2ff;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:bold;margin-left:6px;}'
            . '.section{page-break-inside:avoid}.box{border:1px solid #e2e8f0;background:#fff;padding:12px 14px;border-radius:8px;margin:8px 0;}.summary{background:#eef2ff;border-color:#c7d2fe;color:#312e81;font-weight:600;}'
            . 'pre{white-space:pre-wrap;font-family:"Yu Gothic","Meiryo",sans-serif;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;}'
            . '.report-body p{margin:0 0 9px;}.report-body ul{margin:6px 0 12px 1.2em;padding:0;}.report-body li{margin:3px 0;}.report-body strong{color:#111827;}'
            . 'table{width:100%;border-collapse:collapse;margin:8px 0;}td,th{border:1px solid #e2e8f0;padding:6px;vertical-align:top;}th{background:#f1f5f9;}'
            . '.small{font-size:10px;color:#64748b}.footer{margin-top:28px;padding-top:10px;border-top:1px solid #e2e8f0;color:#64748b;font-size:10px;}'
            . '</style></head><body>'
            . '<div class="cover"><h1>' . $projectName . ' AI報告書</h1>'
            . '<div class="meta">生成日時: ' . $this->e($createdAt) . $scoreHtml . '</div>'
            . '<div class="meta">場所: ' . $this->e((string)($project['address'] ?? '')) . '</div></div>'
            . '<div class="section"><h2>0. 要旨</h2><div class="box summary">' . nl2br($this->e($summary)) . '</div></div>'
            . '<div class="section"><h2>1. 依頼内容</h2><div class="box">' . nl2br($this->e($question)) . '</div></div>'
            . '<div class="section"><h2>2. 報告書本文</h2><div class="report-body">' . $this->formatReportBody($answer) . '</div></div>'
            . '<div class="section"><h2>3. 参照データの概要</h2>' . $this->buildEvidenceHtml($evidence) . '</div>'
            . '<div class="section"><h2>4. 品質評価</h2><div class="box">' . $this->buildEvaluationHtml($evaluation) . '</div></div>'
            . '<div class="footer">このPDFは報告書モードにより自動生成され、通常のアップロードPDFと同じ検索対象として登録されています。</div>'
            . '</body></html>';
    }

    private function buildSearchText(array $project, string $question, string $answer, ?array $evaluation, array $evidence): string
    {
        $lines = [
            'AI生成報告書',
            'プロジェクト: ' . ($project['project_name'] ?? ''),
            '場所: ' . ($project['address'] ?? ''),
            '概要: ' . ($project['description'] ?? ''),
            '依頼内容: ' . $question,
            '報告書本文:',
            $answer,
            '参照データ概要:',
            $evidence['documents_text'] ?? '',
            $evidence['csv_text'] ?? '',
            $evidence['reasoning_text'] ?? '',
            '品質評価: ' . json_encode($evaluation ?? [], JSON_UNESCAPED_UNICODE)
        ];
        return $this->normalizeUtf8(implode("\n\n", array_filter($lines, fn($v) => trim((string)$v) !== '')));
    }

    private function buildEvidenceHtml(array $evidence): string
    {
        $html = '';
        foreach (['documents_text' => '登録PDF', 'csv_text' => 'CSVデータ', 'reasoning_text' => '推論ステップ'] as $key => $label) {
            $value = trim((string)($evidence[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $html .= '<h3 class="small">' . $this->e($label) . '</h3><pre>' . $this->e($value) . '</pre>';
        }
        return $html !== '' ? $html : '<div class="box small">参照データはありません。</div>';
    }

    private function buildEvaluationHtml(?array $evaluation): string
    {
        if (!$evaluation) {
            return '<span class="small">評価データはありません。</span>';
        }
        return '<pre>' . $this->e(json_encode($evaluation, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</pre>';
    }

    private function makeShortSummary(string $answer): string
    {
        $plain = preg_replace('/```.*?```/su', '', $answer);
        $plain = preg_replace('/[#*_>`\[\]\(\)\-]+/u', ' ', (string)$plain);
        $plain = trim((string)preg_replace('/\s+/u', ' ', (string)$plain));
        if ($plain === '') {
            return '本報告書は、チャット回答と登録済み資料をもとに自動生成されました。';
        }
        return mb_substr($plain, 0, 220) . (mb_strlen($plain) > 220 ? '...' : '');
    }

    private function formatReportBody(string $markdown): string
    {
        $markdown = $this->normalizeUtf8($markdown);
        $blocks = preg_split('/(```.*?```)/su', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$markdown];
        $html = '';

        foreach ($blocks as $block) {
            if ($block === '') {
                continue;
            }
            if (preg_match('/^```(?:[a-zA-Z0-9:_-]+)?\s*(.*?)```$/su', trim($block), $m)) {
                $html .= '<pre>' . $this->e(trim($m[1])) . '</pre>';
                continue;
            }

            $lines = preg_split('/\r\n|\r|\n/u', $block) ?: [];
            $inList = false;
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    if ($inList) {
                        $html .= '</ul>';
                        $inList = false;
                    }
                    continue;
                }
                if (preg_match('/^(#{2,4})\s*(.+)$/u', $line, $m)) {
                    if ($inList) {
                        $html .= '</ul>';
                        $inList = false;
                    }
                    $level = min(3, max(2, strlen($m[1])));
                    $html .= '<h' . $level . '>' . $this->inlineMarkdown($m[2]) . '</h' . $level . '>';
                    continue;
                }
                if (preg_match('/^[-*]\s+(.+)$/u', $line, $m)) {
                    if (!$inList) {
                        $html .= '<ul>';
                        $inList = true;
                    }
                    $html .= '<li>' . $this->inlineMarkdown($m[1]) . '</li>';
                    continue;
                }
                if ($inList) {
                    $html .= '</ul>';
                    $inList = false;
                }
                $html .= '<p>' . $this->inlineMarkdown($line) . '</p>';
            }
            if ($inList) {
                $html .= '</ul>';
            }
        }

        return $html !== '' ? $html : '<p>報告書本文はありません。</p>';
    }

    private function inlineMarkdown(string $text): string
    {
        $escaped = $this->e($text);
        $escaped = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $escaped);
        return $escaped !== null ? $escaped : $this->e($text);
    }

    private function loadProject(int $projectId): array
    {
        $stmt = $this->pdo->prepare('SELECT project_name, description, address FROM projects WHERE id = ?');
        $stmt->execute([$projectId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function loadEvidenceSummary(int $projectId, ?string $reasoningSessionId): array
    {
        $docs = [];
        $stmtDocs = $this->pdo->prepare('SELECT title, created_at FROM documents WHERE project_id = ? ORDER BY created_at DESC LIMIT 10');
        $stmtDocs->execute([$projectId]);
        foreach ($stmtDocs->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $docs[] = '- ' . $row['title'] . ' (' . $row['created_at'] . ')';
        }

        $csv = [];
        $stmtCsv = $this->pdo->prepare('SELECT file_name, column_headers, row_count FROM project_csv_files WHERE project_id = ? ORDER BY created_at DESC LIMIT 10');
        $stmtCsv->execute([$projectId]);
        foreach ($stmtCsv->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $headers = json_decode((string)$row['column_headers'], true);
            $csv[] = '- ' . $row['file_name'] . ' / ' . (int)$row['row_count'] . '行 / 項目: ' . implode(', ', is_array($headers) ? array_slice($headers, 0, 20) : []);
        }

        $steps = [];
        if ($reasoningSessionId) {
            $stmtSteps = $this->pdo->prepare('SELECT step_number, sub_query, sub_answer FROM chat_reasoning_steps WHERE session_id = ? ORDER BY step_number ASC LIMIT 20');
            $stmtSteps->execute([$reasoningSessionId]);
            foreach ($stmtSteps->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $steps[] = 'Step ' . $row['step_number'] . ': ' . $row['sub_query'] . "\n" . mb_substr((string)$row['sub_answer'], 0, 500);
            }
        }

        return [
            'documents_text' => implode("\n", $docs),
            'csv_text' => implode("\n", $csv),
            'reasoning_text' => implode("\n\n", $steps),
        ];
    }

    private function splitTextIntoChunks(string $text, int $maxLength): array
    {
        $chunks = [];
        $parts = preg_split('/\n{2,}/u', $text) ?: [$text];
        $current = '';
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (mb_strlen($current) + mb_strlen($part) + 2 > $maxLength && $current !== '') {
                $chunks[] = $current;
                $current = '';
            }
            if (mb_strlen($part) > $maxLength) {
                for ($offset = 0; $offset < mb_strlen($part); $offset += $maxLength) {
                    $slice = mb_substr($part, $offset, $maxLength);
                    if ($current !== '') {
                        $chunks[] = $current;
                        $current = '';
                    }
                    $chunks[] = $slice;
                }
            } else {
                $current .= ($current === '' ? '' : "\n\n") . $part;
            }
        }
        if ($current !== '') {
            $chunks[] = $current;
        }
        return $chunks ?: [$text];
    }

    private function e(string $value): string
    {
        return htmlspecialchars($this->normalizeUtf8($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function normalizeUtf8(string $text): string
    {
        if ($text === '') {
            return '';
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }
        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        return $cleaned !== null ? $cleaned : $text;
    }

    private function log(string $message): void
    {
        if ($this->logger) {
            ($this->logger)($message);
        }
    }
}
