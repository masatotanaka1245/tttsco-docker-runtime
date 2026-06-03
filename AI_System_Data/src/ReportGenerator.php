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
        $this->logReportPreflight($projectId, $chatHistoryId, $question, $answer, $evaluation, $reasoningSessionId);
        $project = $this->loadProject($projectId);
        $evidence = $this->loadEvidenceSummary($projectId, $reasoningSessionId);
        $this->log('[REPORT] 参照データ概要ロード完了: documentsChars=' . mb_strlen($evidence['documents_text'] ?? '')
            . ' | csvChars=' . mb_strlen($evidence['csv_text'] ?? '')
            . ' | reasoningChars=' . mb_strlen($evidence['reasoning_text'] ?? ''));
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
        $this->log('[REPORT] HTML生成完了: path=' . $this->relativePath($htmlPath)
            . ' | bytes=' . (is_file($htmlPath) ? filesize($htmlPath) : 0)
            . ' | chars=' . mb_strlen($html));

        $converter = $this->renderPdf($htmlPath, $pdfPath);
        if (!is_file($pdfPath) || filesize($pdfPath) === 0) {
            throw new RuntimeException('報告書PDFの生成に失敗しました。PDF変換コマンドを確認してください。');
        }
        $this->log('[REPORT] PDF生成後チェック: path=' . $this->relativePath($pdfPath) . ' | bytes=' . filesize($pdfPath));

        $plainText = $this->buildSearchText($project, $question, $answer, $evaluation, $evidence);
        $chunks = $this->splitTextIntoChunks($plainText, 300);
        $this->log('[REPORT] 検索登録テキスト準備完了: plainChars=' . mb_strlen($plainText)
            . ' | initialChunks=' . count($chunks)
            . ' | embedModel=' . $this->embedModel);
        $embeddingEngine = new EmbeddingEngine($this->ollamaHost, $this->embedModel);
        $searchChunks = [];
        foreach ($chunks as $idx => $chunk) {
            $searchChunks = array_merge(
                $searchChunks,
                $this->embedChunkWithAdaptiveSplit($embeddingEngine, $chunk, ($idx + 1) . '/' . count($chunks))
            );
        }
        if (!$searchChunks) {
            $fallbackChunk = mb_substr($plainText, 0, 180);
            $fallbackEmbedding = $embeddingEngine->embed($fallbackChunk);
            $searchChunks[] = ['text' => $fallbackChunk, 'embedding' => $fallbackEmbedding];
            $this->log('[REPORT] embeddingフォールバック生成: 短縮メタ情報を検索対象として登録します。');
        }
        $this->log('[REPORT] embedding生成フェーズ完了: searchableChunks=' . count($searchChunks));
        $dbPath = '01_RAG_Documents/' . $projectId . '/' . basename($pdfPath);

        $this->pdo->beginTransaction();
        try {
            $stmtDoc = $this->pdo->prepare('INSERT INTO documents (project_id, title, file_path, created_at) VALUES (?, ?, ?, NOW())');
            $stmtDoc->execute([$projectId, $title, $dbPath]);
            $docId = (int)$this->pdo->lastInsertId();

            $stmtChunk = $this->pdo->prepare('INSERT INTO doc_chunks (doc_id, page_number, chunk_text, embedding, image_description, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            foreach ($searchChunks as $idx => $chunkData) {
                $stmtChunk->execute([
                    $docId,
                    1,
                    $chunkData['text'],
                    json_encode($chunkData['embedding']),
                    'AI生成報告書（Report Mode）'
                ]);
                $this->log("[REPORT] doc_chunks登録: doc_id={$docId} chunk=" . ($idx + 1) . '/' . count($searchChunks));
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

    private function embedChunkWithAdaptiveSplit(EmbeddingEngine $embeddingEngine, string $chunk, string $label, int $depth = 0): array
    {
        $chunk = trim($chunk);
        if ($chunk === '') {
            return [];
        }

        try {
            $embedding = $embeddingEngine->embed($chunk);
            $this->log("[REPORT] embedding生成: chunk={$label} | chars=" . mb_strlen($chunk));
            return [['text' => $chunk, 'embedding' => $embedding]];
        } catch (Throwable $e) {
            $length = mb_strlen($chunk);
            $canSplit = $length > 120 && $depth < 4;
            if ($canSplit) {
                $this->log("[REPORT] embedding再分割: chunk={$label} | chars={$length} | reason=" . $this->summarizeException($e));
                $mid = (int)ceil($length / 2);
                $left = mb_substr($chunk, 0, $mid);
                $right = mb_substr($chunk, $mid);
                return array_merge(
                    $this->embedChunkWithAdaptiveSplit($embeddingEngine, $left, $label . '-a', $depth + 1),
                    $this->embedChunkWithAdaptiveSplit($embeddingEngine, $right, $label . '-b', $depth + 1)
                );
            }

            $this->log("[REPORT] embedding生成をスキップ: chunk={$label} | chars={$length} | reason=" . $this->summarizeException($e));
            return [];
        }
    }

    private function summarizeException(Throwable $e): string
    {
        return mb_substr(preg_replace('/\s+/u', ' ', $e->getMessage()) ?: $e->getMessage(), 0, 240);
    }

    private function renderPdf(string $htmlPath, string $pdfPath): string
    {
        $this->log('[REPORT] PDF変換プリフライト: composerAutoload=' . ($this->composerAutoloadExists() ? 'yes' : 'no')
            . ' | mpdfLoaded=' . (class_exists('\\Mpdf\\Mpdf') ? 'yes' : 'no')
            . ' | japaneseFonts=' . $this->detectJapaneseFontSummary());
        if ($this->loadComposerAutoload() && class_exists('\\Mpdf\\Mpdf')) {
            $this->log('[REPORT] PDF変換ツール検出: mPDF');
            try {
                $html = (string)file_get_contents($htmlPath);
                $tempDir = $this->basePath . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'mpdf';
                if (!is_dir($tempDir)) {
                    @mkdir($tempDir, 0777, true);
                }

                $fontDirs = method_exists('\\Mpdf\\Config\\ConfigVariables', 'getDefaults')
                    ? (new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir']
                    : [];
                $fontData = method_exists('\\Mpdf\\Config\\FontVariables', 'getDefaults')
                    ? (new \Mpdf\Config\FontVariables())->getDefaults()['fontdata']
                    : [];
                $notoRegular = '/usr/share/opentype/noto/NotoSansCJK-Regular.ttc';
                $notoBold = '/usr/share/opentype/noto/NotoSansCJK-Bold.ttc';
                $this->log('[REPORT] mPDFフォント設定: notoRegular=' . (is_file($notoRegular) ? 'yes' : 'no')
                    . ' | notoBold=' . (is_file($notoBold) ? 'yes' : 'no')
                    . ' | tempDir=' . (is_dir($tempDir) && is_writable($tempDir) ? $tempDir : sys_get_temp_dir()));
                if (is_file($notoRegular)) {
                    $fontDirs[] = dirname($notoRegular);
                    $fontData['notosanscjkjp'] = [
                        'R' => basename($notoRegular),
                        'B' => is_file($notoBold) ? basename($notoBold) : basename($notoRegular),
                    ];
                }

                $mpdf = new \Mpdf\Mpdf([
                    'mode' => 'utf-8',
                    'format' => 'A4',
                    'tempDir' => is_dir($tempDir) && is_writable($tempDir) ? $tempDir : sys_get_temp_dir(),
                    'fontDir' => $fontDirs,
                    'fontdata' => $fontData,
                    'autoScriptToLang' => true,
                    'autoLangToFont' => true,
                    'default_font' => is_file($notoRegular) ? 'notosanscjkjp' : 'sans-serif',
                    'margin_left' => 16,
                    'margin_right' => 16,
                    'margin_top' => 18,
                    'margin_bottom' => 18,
                ]);
                $mpdf->SetTitle($this->extractHtmlTitle($html) ?: 'AI報告書');
                $mpdf->WriteHTML($html);
                $mpdf->Output($pdfPath, \Mpdf\Output\Destination::FILE);

                if (is_file($pdfPath) && filesize($pdfPath) > 0) {
                    $this->log('[REPORT] mPDF実行成功: size=' . filesize($pdfPath) . ' bytes');
                    return 'mpdf';
                }
                $this->log('[REPORT] mPDF実行失敗: PDFファイルが生成されませんでした。');
            } catch (Throwable $e) {
                $this->log('[REPORT] mPDF実行失敗: ' . $e->getMessage());
            }
        } else {
            $this->log('[REPORT] mPDFは利用不可のためフォールバック変換へ進みます。autoload='
                . ($this->composerAutoloadExists() ? 'yes' : 'no')
                . ' | class=' . (class_exists('\\Mpdf\\Mpdf') ? 'yes' : 'no'));
        }

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
            $version = trim((string)@shell_exec(escapeshellarg($browser) . ' --version 2>&1'));
            if ($version !== '') {
                $this->log('[REPORT] headless browser version: ' . mb_substr($version, 0, 160));
            }
            $fileUrl = 'file:///' . str_replace(DIRECTORY_SEPARATOR, '/', realpath($htmlPath));
            $cmd = escapeshellarg($browser)
                . ' --headless --disable-gpu --no-sandbox --disable-dev-shm-usage --print-to-pdf-no-header --print-to-pdf=' . escapeshellarg($pdfPath)
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
            . ' Composerでmpdf/mpdfをインストールするか、tools/wkhtmltopdf.exe、WKHTMLTOPDF_PATH、CHROME_PATH、または Chrome/Edge headless を利用できるようにしてください。'
        );
    }

    private function loadComposerAutoload(): bool
    {
        if (class_exists('\\Mpdf\\Mpdf')) {
            return true;
        }

        $autoloadCandidates = [
            $this->basePath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php',
            dirname($this->basePath) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php',
            $this->basePath . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php',
        ];

        foreach ($autoloadCandidates as $autoloadPath) {
            $realPath = realpath($autoloadPath);
            if ($realPath && is_file($realPath)) {
                require_once $realPath;
                $this->log('[REPORT] Composer autoload検出: ' . $realPath . ' | mpdfClass=' . (class_exists('\\Mpdf\\Mpdf') ? 'yes' : 'no'));
                return class_exists('\\Mpdf\\Mpdf');
            }
        }

        $this->log('[REPORT] Composer autoload未検出: checked=' . implode(', ', $autoloadCandidates));
        return false;
    }

    private function composerAutoloadExists(): bool
    {
        foreach ([
            $this->basePath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php',
            dirname($this->basePath) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php',
            $this->basePath . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php',
        ] as $autoloadPath) {
            $realPath = realpath($autoloadPath);
            if ($realPath && is_file($realPath)) {
                return true;
            }
        }
        return false;
    }

    private function detectJapaneseFontSummary(): string
    {
        $knownFiles = [
            '/usr/share/opentype/noto/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/truetype/fonts-japanese-gothic.ttf',
        ];
        foreach ($knownFiles as $file) {
            if (is_file($file)) {
                return 'file:' . $file;
            }
        }

        $fcMatch = trim((string)@shell_exec('command -v fc-match >/dev/null 2>&1 && fc-match "Noto Sans CJK JP" 2>&1'));
        if ($fcMatch !== '') {
            return 'fc-match:' . mb_substr($fcMatch, 0, 180);
        }

        return 'none';
    }

    private function logReportPreflight(
        int $projectId,
        int $chatHistoryId,
        string $question,
        string $answer,
        ?array $evaluation,
        ?string $reasoningSessionId
    ): void {
        $this->log('[REPORT] 生成プリフライト: project_id=' . $projectId
            . ' | chat_history_id=' . $chatHistoryId
            . ' | questionChars=' . mb_strlen($question)
            . ' | answerChars=' . mb_strlen($answer)
            . ' | verdict=' . (string)($evaluation['verdict'] ?? 'none')
            . ' | score=' . (string)($evaluation['total_score'] ?? 'none')
            . ' | reasoningSession=' . ($reasoningSessionId ?: 'none')
            . ' | basePath=' . $this->basePath);
    }

    private function relativePath(string $path): string
    {
        $normalizedBase = rtrim(str_replace('\\', '/', $this->basePath), '/');
        $normalizedPath = str_replace('\\', '/', $path);
        if (str_starts_with($normalizedPath, $normalizedBase . '/')) {
            return substr($normalizedPath, strlen($normalizedBase) + 1);
        }
        return $path;
    }

    private function extractHtmlTitle(string $html): string
    {
        if (preg_match('/<title>(.*?)<\/title>/is', $html, $matches)) {
            return trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        return '';
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
            . '@page{size:A4;margin:18mm 16mm;}body{font-family:"Noto Sans CJK JP","Noto Sans JP","Yu Gothic","Meiryo",sans-serif;color:#1f2937;line-height:1.72;font-size:12px;}'
            . 'h1{font-size:24px;margin:0 0 8px;color:#111827;}h2{font-size:16px;margin:24px 0 8px;border-bottom:1px solid #cbd5e1;padding-bottom:5px;color:#334155;}h3{font-size:13px;margin:16px 0 6px;color:#475569;}'
            . '.cover{border-left:6px solid #4F5D95;padding:18px 20px;margin-bottom:20px;background:#f8fafc;}'
            . '.meta{color:#64748b;font-size:11px;margin-top:8px}.pill{display:inline-block;border:1px solid #c7d2fe;color:#3730a3;background:#eef2ff;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:bold;margin-left:6px;}'
            . '.section{page-break-inside:avoid}.box{border:1px solid #e2e8f0;background:#fff;padding:12px 14px;border-radius:8px;margin:8px 0;}.summary{background:#eef2ff;border-color:#c7d2fe;color:#312e81;font-weight:600;}'
            . 'pre{white-space:pre-wrap;font-family:"Noto Sans CJK JP","Noto Sans JP","Yu Gothic","Meiryo",sans-serif;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;}'
            . '.report-body p{margin:0 0 9px;}.report-body ul{margin:6px 0 12px 1.2em;padding:0;}.report-body li{margin:3px 0;}.report-body strong{color:#111827;}'
            . 'table{width:100%;border-collapse:collapse;margin:8px 0 14px;}td,th{border:1px solid #e2e8f0;padding:6px;vertical-align:top;}th{background:#f1f5f9;font-weight:700;}'
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
            '品質評価: ' . $this->buildEvaluationSearchText($evaluation)
        ];
        return $this->normalizeUtf8(implode("\n\n", array_filter($lines, fn($v) => trim((string)$v) !== '')));
    }

    private function buildEvidenceHtml(array $evidence): string
    {
        $html = '';
        foreach (['documents_text' => '登録資料', 'csv_text' => 'CSVデータ', 'reasoning_text' => '根拠メモ'] as $key => $label) {
            $value = trim((string)($evidence[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $html .= '<div class="box"><h3 class="small">' . $this->e($label) . '</h3><pre>' . $this->e($value) . '</pre></div>';
        }
        return $html !== '' ? $html : '<div class="box small">参照データはありません。</div>';
    }

    private function buildEvaluationHtml(?array $evaluation): string
    {
        if (!$evaluation) {
            return '<span class="small">評価データはありません。</span>';
        }
        $score = $evaluation['total_score'] ?? null;
        $verdict = $evaluation['verdict'] ?? '';
        $feedback = trim((string)($evaluation['feedback'] ?? ''));
        $feedback = $this->removeInternalRewriteNotes($feedback);
        $feedback = mb_substr($feedback, 0, 260) . (mb_strlen($feedback) > 260 ? '...' : '');

        $rows = [];
        if ($score !== null) {
            $rows[] = '<tr><th>総合評価</th><td>' . $this->e((string)$score) . '点</td></tr>';
        }
        if ($verdict !== '') {
            $rows[] = '<tr><th>判定</th><td>' . $this->e((string)$verdict) . '</td></tr>';
        }
        if ($feedback !== '') {
            $rows[] = '<tr><th>コメント</th><td>' . nl2br($this->e($feedback)) . '</td></tr>';
        }

        return $rows ? '<table>' . implode('', $rows) . '</table>' : '<span class="small">評価データはありません。</span>';
    }

    private function buildEvaluationSearchText(?array $evaluation): string
    {
        if (!$evaluation) {
            return '';
        }
        $parts = [];
        if (isset($evaluation['total_score'])) {
            $parts[] = '総合評価 ' . $evaluation['total_score'] . '点';
        }
        if (!empty($evaluation['verdict'])) {
            $parts[] = '判定 ' . $evaluation['verdict'];
        }
        if (!empty($evaluation['feedback'])) {
            $parts[] = 'コメント ' . $this->removeInternalRewriteNotes((string)$evaluation['feedback']);
        }
        return implode(' / ', $parts);
    }

    private function removeInternalRewriteNotes(string $text): string
    {
        $text = preg_replace('/\[TEXT-ONLY-REWRITE\].*$/su', '', $text);
        return trim((string)$text);
    }

    private function makeShortSummary(string $answer): string
    {
        $plain = preg_replace('/```.*?```/su', '', $answer);
        $plain = $this->stripDecorativeSymbols((string)$plain);
        $plain = preg_replace('/[#*_>`\[\]\(\)\-]+/u', ' ', (string)$plain);
        $plain = trim((string)preg_replace('/\s+/u', ' ', (string)$plain));
        if ($plain === '') {
            return '本報告書は、チャット回答と登録済み資料をもとに自動生成されました。';
        }
        return mb_substr($plain, 0, 220) . (mb_strlen($plain) > 220 ? '...' : '');
    }

    private function formatReportBody(string $markdown): string
    {
        $markdown = $this->stripDecorativeSymbols($this->normalizeUtf8($markdown));
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
            $lineCount = count($lines);
            for ($i = 0; $i < $lineCount; $i++) {
                $line = $lines[$i];
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
                if ($this->isMarkdownTableStart($lines, $i)) {
                    if ($inList) {
                        $html .= '</ul>';
                        $inList = false;
                    }
                    [$tableHtml, $consumed] = $this->consumeMarkdownTable($lines, $i);
                    $html .= $tableHtml;
                    $i += $consumed - 1;
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
        $text = $this->stripDecorativeSymbols($text);
        $escaped = $this->e($text);
        $escaped = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $escaped);
        return $escaped !== null ? $escaped : $this->e($text);
    }

    private function isMarkdownTableStart(array $lines, int $index): bool
    {
        $current = trim((string)($lines[$index] ?? ''));
        $next = trim((string)($lines[$index + 1] ?? ''));
        return str_contains($current, '|')
            && preg_match('/^\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)+\|?$/u', $next) === 1;
    }

    private function consumeMarkdownTable(array $lines, int $index): array
    {
        $rows = [];
        for ($i = $index; $i < count($lines); $i++) {
            $line = trim((string)$lines[$i]);
            if ($line === '' || !str_contains($line, '|')) {
                break;
            }
            if ($i === $index + 1 && preg_match('/^\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)+\|?$/u', $line)) {
                continue;
            }
            $cells = array_map('trim', explode('|', trim($line, '|')));
            if ($cells) {
                $rows[] = $cells;
            }
        }

        if (!$rows) {
            return ['', 0];
        }

        $html = '<table>';
        foreach ($rows as $rowIndex => $cells) {
            $tag = $rowIndex === 0 ? 'th' : 'td';
            $html .= '<tr>';
            foreach ($cells as $cell) {
                $html .= '<' . $tag . '>' . $this->inlineMarkdown($cell) . '</' . $tag . '>';
            }
            $html .= '</tr>';
        }
        $html .= '</table>';

        return [$html, $i - $index];
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
        $stmtDocs = $this->pdo->prepare(
            "SELECT title, created_at FROM documents
             WHERE project_id = ?
               AND title NOT LIKE 'AI報告書\\_%' ESCAPE '\\\\'
               AND file_path NOT LIKE '%/report\\_%' ESCAPE '\\\\'
             ORDER BY created_at DESC
             LIMIT 10"
        );
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
                $steps[] = 'Step ' . $row['step_number'] . ': ' . $row['sub_query'] . "\n" . $this->compactReasoningExcerpt((string)$row['sub_answer']);
            }
        }

        return [
            'documents_text' => implode("\n", $docs),
            'csv_text' => implode("\n", $csv),
            'reasoning_text' => implode("\n\n", $steps),
        ];
    }

    private function compactReasoningExcerpt(string $text): string
    {
        $text = $this->normalizeUtf8($text);
        $text = preg_replace('/\[TEXT-ONLY-REWRITE\].*$/su', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        return mb_substr($text, 0, 700) . (mb_strlen($text) > 700 ? '...' : '');
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

    private function stripDecorativeSymbols(string $text): string
    {
        $text = preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', '', $text);
        return $text !== null ? $text : '';
    }

    private function log(string $message): void
    {
        if ($this->logger) {
            ($this->logger)($message);
        }
    }
}
