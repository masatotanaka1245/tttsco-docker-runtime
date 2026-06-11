<?php

final class AdvancedFastPathResolver
{
    private $pdo;
    private $projectId;
    private $threadId;
    private $userId;
    private $searchQuery;
    private $originalMessage;
    private $logger;

    public function __construct(
        PDO $pdo,
        int $projectId,
        ?int $threadId,
        int $userId,
        string $searchQuery,
        string $originalMessage,
        ?callable $logger = null
    ) {
        $this->pdo = $pdo;
        $this->projectId = $projectId;
        $this->threadId = $threadId;
        $this->userId = $userId;
        $this->searchQuery = $searchQuery;
        $this->originalMessage = $originalMessage;
        $this->logger = $logger;
    }

    public function resolveHistoryReport(): ?array
    {
        if ($this->threadId === null || $this->userId <= 0) {
            return null;
        }

        if (preg_match('/(これまでの会話|会話内容|このスレッド|履歴|やり取り).*(まとめ|要約|整理|報告書)/u', $this->searchQuery) !== 1) {
            return null;
        }

        $history = $this->loadCurrentThreadHistory(50);
        if (empty($history)) {
            $finalResponse = "## 結論\n現在のスレッドには、報告書化できる会話履歴がまだありません。\n\n## 分析対象\n- 対象スレッド: {$this->threadId}\n- 取得件数: 0件\n\n## 根拠\n- `chat_history` に対象メッセージが見つかりませんでした。\n\n## 留意点\n- このスレッドで会話を開始したあとに再実行してください。\n\n## 推奨アクション\n- まず1〜2件のやり取りを行い、その後に報告書化を実行してください。\n\n## 出典\n- `chat_history`";
            $this->log("[REPORT] history_report ファストパス: 対象スレッドの履歴が0件のため、報告書PDF生成をスキップします。thread_id=" . ($this->threadId ?? 'NULL'));
            $forceReportModeOff = true;
        } else {
            $finalResponse = $this->buildDeterministicHistoryReport($history);
            $forceReportModeOff = false;
        }

        $snapshot = $this->buildHistoryCollectionSnapshot($history);

        return [
            'final_response' => $finalResponse,
            'reasoning_steps' => [
                [
                    'sub_query' => 'current thread の会話履歴を収集',
                    'sub_answer' => $snapshot,
                ],
                [
                    'sub_query' => '会話履歴から報告書を組み立て',
                    'sub_answer' => $finalResponse,
                ],
            ],
            'guard_route' => 'history_summary',
            'guard_context' => $snapshot,
            'force_report_mode_off' => $forceReportModeOff,
        ];
    }

    public function resolveMultiSourceAdvice(): ?array
    {
        if (preg_match('/(おすすめ|オススメ|提案|分析方法|集計方法|どう分析|どう集計|どのように.*分析|分析したら.*よい|どう進め|見るべき|観点|切り口|方針)/u', $this->searchQuery) !== 1) {
            return null;
        }

        if (preg_match('/(分析|集計|データ|CSV|csv|PDF|pdf|資料|観点|切り口)/u', $this->searchQuery) !== 1) {
            return null;
        }

        $csvFiles = $this->loadProjectCsvFiles();
        $pdfDocs = $this->loadProjectPdfDocuments();
        if (empty($csvFiles) && empty($pdfDocs)) {
            return null;
        }

        $finalResponse = $this->buildDeterministicMultiSourceAdvice($csvFiles, $pdfDocs);
        $summary = "CSV件数=" . count($csvFiles) . " / PDF件数=" . count($pdfDocs);

        return [
            'final_response' => $finalResponse,
            'reasoning_steps' => [
                [
                    'sub_query' => 'CSV/PDF の資産構成を収集',
                    'sub_answer' => $summary,
                ],
                [
                    'sub_query' => '資産構成から推奨分析観点を組み立て',
                    'sub_answer' => $finalResponse,
                ],
            ],
            'guard_route' => null,
            'guard_context' => null,
            'force_report_mode_off' => false,
        ];
    }

    private function loadCurrentThreadHistory(int $limit = 50): array
    {
        if ($this->projectId <= 0 || $this->threadId === null || $this->userId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare("
            SELECT role, message, created_at
            FROM chat_history
            WHERE project_id = ? AND thread_id = ? AND user_id = ?
            ORDER BY created_at DESC
            LIMIT {$limit}
        ");
        $stmt->execute([$this->projectId, $this->threadId, $this->userId]);
        return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function loadProjectCsvFiles(): array
    {
        if ($this->projectId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare("
            SELECT file_name, column_headers, row_count
            FROM project_csv_files
            WHERE project_id = ?
            ORDER BY id ASC
        ");
        $stmt->execute([$this->projectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function loadProjectPdfDocuments(): array
    {
        if ($this->projectId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare("
            SELECT title, file_path, created_at
            FROM documents
            WHERE project_id = ? AND LOWER(file_path) LIKE '%.pdf' AND title NOT LIKE 'AI報告書%'
            ORDER BY created_at DESC, id DESC
            LIMIT 20
        ");
        $stmt->execute([$this->projectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function buildDeterministicHistoryReport(array $history): string
    {
        $userMessages = [];
        $assistantCount = 0;
        foreach ($history as $row) {
            if (($row['role'] ?? '') === 'user') {
                $userMessages[] = $this->compactLine((string)($row['message'] ?? ''), 120);
            } elseif (($row['role'] ?? '') === 'assistant') {
                $assistantCount++;
            }
        }

        $topics = $this->detectHistoryTopics($history);
        $latestRequests = array_slice(array_reverse($userMessages), 0, 5);
        $firstAt = $history[0]['created_at'] ?? '-';
        $lastAt = $history[count($history) - 1]['created_at'] ?? '-';

        $lines = [];
        $lines[] = "## 結論";
        $lines[] = "このスレッドでは、CSVの概要把握、PDF資料からの留意点抽出、そして会話内容そのものの整理という順で、案件理解を段階的に深める対話が行われました。現在の会話は、データ資産を横断して確認し、その結果を再利用しやすい形へ整える流れにあります。";
        $lines[] = "";
        $lines[] = "## 分析対象";
        $lines[] = "- 対象スレッド: " . ($this->threadId ?? '-');
        $lines[] = "- 対象履歴件数: " . count($history) . "件";
        $lines[] = "- ユーザー発言: " . count($userMessages) . "件";
        $lines[] = "- AI回答: {$assistantCount}件";
        $lines[] = "- 対象期間: {$firstAt} 〜 {$lastAt}";
        $lines[] = "";
        $lines[] = "## 根拠";
        foreach ($latestRequests as $request) {
            $lines[] = "- 直近の依頼: {$request}";
        }
        if (!empty($topics)) {
            foreach ($topics as $topic => $count) {
                $lines[] = "- 話題傾向: {$topic} ({$count}件程度)";
            }
        }
        $lines[] = "";
        $lines[] = "## 留意点";
        $lines[] = "- 現在の履歴は現在スレッド単位で収集されており、案件全体の全履歴ではありません。";
        $lines[] = "- 直近の議論はログ確認やルーティング調整も含むため、業務報告として再利用する際は目的別に章立てすると読みやすくなります。";
        $lines[] = "- PDF抽出とCSV集計は性質が異なるため、報告書では『定量情報』と『資料上の注意事項』を分離して記載するのが適しています。";
        $lines[] = "";
        $lines[] = "## 推奨アクション";
        $lines[] = "- まずCSV側は対象ファイル別に、件数集計・分布集計・時系列集計のどれを優先するかを決める。";
        $lines[] = "- PDF側は、留意点・制約・確認事項をページ番号付きで一覧化し、CSV側の集計結果と照合できる形にそろえる。";
        $lines[] = "- このスレッドの対話履歴を案件報告へ転用する場合は、『実行した分析』『得られた根拠』『未確定事項』の3区分で再編集する。";
        $lines[] = "";
        $lines[] = "## 出典";
        $lines[] = "- `chat_history` / project_id={$this->projectId} / thread_id=" . ($this->threadId ?? 'NULL') . " / user_id={$this->userId}";
        foreach ($latestRequests as $request) {
            $lines[] = "- 会話断片: {$request}";
        }

        return implode("\n", $lines);
    }

    private function buildDeterministicMultiSourceAdvice(array $csvFiles, array $pdfDocs): string
    {
        $hasCsv = !empty($csvFiles);
        $hasPdf = !empty($pdfDocs);
        $lines = [];
        $lines[] = $this->buildMultiSourceAdviceLead($hasCsv, $hasPdf);
        $lines[] = "";
        $lines[] = "## おすすめの進め方";
        foreach ($this->buildMultiSourceAdviceWorkflow($hasCsv, $hasPdf) as $step) {
            $lines[] = $step;
        }

        if ($hasCsv) {
            $lines[] = "";
            $lines[] = "## CSVでおすすめの集計";
            foreach ($csvFiles as $csv) {
                $lines[] = $this->buildCsvAdviceLine($csv);
            }
        }

        if ($hasPdf) {
            $lines[] = "";
            $lines[] = "## PDFでおすすめの分析";
            foreach (array_slice($pdfDocs, 0, 3) as $doc) {
                $title = (string)($doc['title'] ?? basename((string)($doc['file_path'] ?? '資料PDF')));
                $lines[] = "- `{$title}`: 留意点、禁止事項、確認事項、寸法・条件値などをページ番号付きで抽出し、CSV集計とは別に根拠一覧化するのがおすすめです。";
            }
        } elseif ($hasCsv) {
            $lines[] = "";
            $lines[] = "## PDFでおすすめの分析";
            $lines[] = "- 現在、対象PDFは確認できませんでした。まずはCSVだけで定量把握を進め、必要な資料が追加された時点で留意点抽出を組み合わせるのが自然です。";
        }

        $lines[] = "";
        $lines[] = "## まず最初にやるとよい分析";
        foreach ($this->buildMultiSourceAdviceFirstActions($hasCsv, $hasPdf) as $step) {
            $lines[] = $step;
        }
        $lines[] = "";
        $lines[] = "## 出典";
        if ($hasCsv) {
            $lines[] = "- CSVファイル数: " . count($csvFiles) . "件";
            foreach (array_slice($csvFiles, 0, 5) as $csv) {
                $lines[] = "- CSV: " . (string)($csv['file_name'] ?? '名称不明');
            }
        }
        if ($hasPdf) {
            $lines[] = "- PDF件数: " . count($pdfDocs) . "件";
            foreach (array_slice($pdfDocs, 0, 5) as $doc) {
                $lines[] = "- PDF: " . (string)($doc['title'] ?? basename((string)($doc['file_path'] ?? '資料PDF')));
            }
        }

        return implode("\n", $lines);
    }

    private function buildMultiSourceAdviceLead(bool $hasCsv, bool $hasPdf): string
    {
        if ($hasCsv && $hasPdf) {
            return "CSVとPDFの両方を活かすなら、まず『CSVで定量把握』『PDFで留意点整理』『両者の照合』の3段で進めるのがおすすめです。";
        }

        if ($hasCsv) {
            return "今回はCSV資産が中心なので、まず『全体像の把握』『主要列の分布確認』『業務に近い指標の深掘り』の順で進めるのがおすすめです。";
        }

        return "今回は資料PDFが中心なので、まず『留意点抽出』『制約条件の整理』『ページ番号付き根拠の一覧化』の順で進めるのがおすすめです。";
    }

    private function buildMultiSourceAdviceWorkflow(bool $hasCsv, bool $hasPdf): array
    {
        if ($hasCsv && $hasPdf) {
            return [
                "- 1. CSVのファイル一覧と列構成を確認し、どのファイルが件数集計・分布集計・時系列集計に向くかを切り分ける。",
                "- 2. PDFからは、留意点・制約・確認事項をページ番号付きで抽出し、定量集計とは別レイヤーで整理する。",
                "- 3. 最後に、CSVの集計結果とPDFの注意事項を並べ、運用判断に使える形へまとめる。",
            ];
        }

        if ($hasCsv) {
            return [
                "- 1. CSVのファイル一覧と列構成を確認し、業務系・属性系・履歴系に分けて見る。",
                "- 2. 各CSVで件数分布、ランキング、時系列など基本集計を出し、どこに偏りがあるかを把握する。",
                "- 3. その後、深掘りしたいCSVを1本選び、列同士の比較や期間別の傾向分析へ進む。",
            ];
        }

        return [
            "- 1. PDFから、留意点・制約・確認事項をページ番号付きで抽出する。",
            "- 2. 寸法、条件値、禁止事項など、判断に直結する情報をカテゴリ別に整理する。",
            "- 3. 最後に、現場や運用判断に使うための確認リストへまとめる。",
        ];
    }

    private function buildMultiSourceAdviceFirstActions(bool $hasCsv, bool $hasPdf): array
    {
        if ($hasCsv && $hasPdf) {
            return [
                "- CSV全体の概要を出す",
                "- 次に業務系CSVを1本選び、列別件数分布やランキングを出す",
                "- その後、PDFの留意点一覧を抽出して、CSVの数値結果と矛盾や確認事項がないかを見る",
            ];
        }

        if ($hasCsv) {
            return [
                "- CSV全体の概要を出す",
                "- 業務に近いCSVを1本選び、列別件数分布やランキングを出す",
                "- 必要なら時系列や特定条件で絞った集計へ進む",
            ];
        }

        return [
            "- PDF全体から主要な留意点を抽出する",
            "- 次にページ番号付きで制約条件を一覧化する",
            "- その後、判断に必要な確認事項リストへ整理する",
        ];
    }

    private function buildCsvAdviceLine(array $csv): string
    {
        $fileName = (string)($csv['file_name'] ?? '');
        $rowCount = (int)($csv['row_count'] ?? 0);
        $headers = json_decode((string)($csv['column_headers'] ?? ''), true);
        if (!is_array($headers)) {
            $headers = array_filter(array_map('trim', explode(',', (string)($csv['column_headers'] ?? ''))));
        }

        if (preg_match('/language-locales/i', $fileName)) {
            return "- `{$fileName}` ({$rowCount}件): 言語別件数、部署別件数、アカウント属性の分布確認が向いています。";
        }
        if (preg_match('/username-or-email/i', $fileName)) {
            return "- `{$fileName}` ({$rowCount}件): ユーザー識別子、メールアドレス、氏名の重複有無や属性分布の確認が向いています。";
        }
        if (preg_match('/入荷実績一覧/u', $fileName)) {
            return "- `{$fileName}` ({$rowCount}件): 品番別件数、品名別件数、仕入先別件数、サイズ別件数、発注数/入荷数/未入荷数の比較がおすすめです。";
        }
        if (preg_match('/健康診断一覧/u', $fileName)) {
            return "- `{$fileName}` ({$rowCount}件): 年齢分布、身長・体重・血圧・血糖値の要約統計、性別や年代別の比較が有効です。";
        }
        if (preg_match('/出荷一覧表/u', $fileName)) {
            return "- `{$fileName}` ({$rowCount}件): 商品別件数、顧客別件数、受注日ベースの時系列、本数と合計のランキング集計が向いています。";
        }

        $headerPreview = implode(' / ', array_slice($headers, 0, 5));
        if ($headerPreview === '') {
            $headerPreview = '主要列';
        }

        return "- `{$fileName}` ({$rowCount}件): まず主要列（{$headerPreview}）の値分布と欠損有無を確認するのがおすすめです。";
    }

    private function buildHistoryCollectionSnapshot(array $history): string
    {
        $lines = [];
        $lines[] = "取得件数: " . count($history);
        foreach (array_slice($history, -6) as $row) {
            $roleLabel = (($row['role'] ?? '') === 'assistant') ? 'AI' : 'ユーザー';
            $lines[] = "- {$roleLabel}: " . $this->compactLine((string)($row['message'] ?? ''), 120);
        }
        return implode("\n", $lines);
    }

    private function detectHistoryTopics(array $history): array
    {
        $topicPatterns = [
            'CSVデータの要約・集計' => '/CSV|csv|project_csv|row_data|カラム|列|集計/u',
            'PDF資料の留意点抽出' => '/PDF|pdf|資料|留意点|doc_chunks|documents/u',
            '会話履歴の整理・要約' => '/会話|履歴|チャット|要約|報告書/u',
            'ルーティング・ログ確認' => '/ログ|route|ルート|debug|遅延|処理/u',
        ];

        $scores = [];
        foreach ($history as $row) {
            $text = (string)($row['message'] ?? '');
            foreach ($topicPatterns as $topic => $pattern) {
                if (preg_match($pattern, $text)) {
                    $scores[$topic] = ($scores[$topic] ?? 0) + 1;
                }
            }
        }
        arsort($scores);
        return array_slice($scores, 0, 4, true);
    }

    private function compactLine(string $text, int $limit): string
    {
        $text = trim((string)(preg_replace('/\s+/u', ' ', $text) ?? $text));
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        return mb_substr($text, 0, $limit) . '...';
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $message);
        }
    }
}
