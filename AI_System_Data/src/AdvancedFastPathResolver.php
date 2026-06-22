<?php

require_once __DIR__ . '/ProjectContextMemory.php';

final class AdvancedFastPathResolver
{
    private $pdo;
    private $projectId;
    private $threadId;
    private $userId;
    private $searchQuery;
    private $originalMessage;
    private $routeDetail;
    private $logger;

    public function __construct(
        PDO $pdo,
        int $projectId,
        ?int $threadId,
        int $userId,
        string $searchQuery,
        string $originalMessage,
        string $routeDetail = '',
        ?callable $logger = null
    ) {
        $this->pdo = $pdo;
        $this->projectId = $projectId;
        $this->threadId = $threadId;
        $this->userId = $userId;
        $this->searchQuery = $searchQuery;
        $this->originalMessage = $originalMessage;
        $this->routeDetail = trim($routeDetail);
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
        $csvLogMetadataCompare = $this->resolveCsvLogMetadataCompare();
        if ($csvLogMetadataCompare !== null) {
            return $csvLogMetadataCompare;
        }

        $forcedByRoute = $this->routeDetail === 'advanced_hybrid.multi_source_advice';
        $hasAdviceIntent = preg_match('/(おすすめ|オススメ|提案|分析方法|集計方法|どう分析|どう集計|どのように.*分析|分析したら.*よい|どう進め|見るべき|観点|切り口|方針)/u', $this->searchQuery) === 1;
        $hasProjectSummaryIntent = preg_match('/(案件|プロジェクト).*(内容|概要|全体像|まとめ|要約|詳細)|((内容|概要|全体像|まとめ|要約|詳細).*(案件|プロジェクト))/u', $this->searchQuery) === 1;
        $hasAssetContext = preg_match('/(分析|集計|データ|CSV|csv|PDF|pdf|資料|観点|切り口|案件|プロジェクト)/u', $this->searchQuery) === 1;

        if (!$forcedByRoute && !$hasAdviceIntent && !$hasProjectSummaryIntent) {
            return null;
        }
        if (!$forcedByRoute && !$hasAssetContext) {
            return null;
        }

        $csvFiles = $this->loadProjectCsvFiles();
        $pdfDocs = $this->loadProjectPdfDocuments();
        $materialDocs = $this->loadProjectMaterialDocuments();
        $projectMemoryDocs = ProjectContextMemory::load($this->pdo, $this->projectId);
        $projectMemorySnapshot = $this->buildProjectMemorySnapshot($projectMemoryDocs);
        $recentContext = $this->buildRecentAdviceContext($this->loadCurrentThreadHistory(8));

        if (
            empty($csvFiles)
            && empty($pdfDocs)
            && empty($materialDocs)
            && empty($projectMemorySnapshot['highlights'])
        ) {
            return null;
        }

        $isSummaryMode = $hasProjectSummaryIntent && !$hasAdviceIntent;
        $finalResponse = $isSummaryMode
            ? $this->buildDeterministicProjectAssetSummary($csvFiles, $pdfDocs, $materialDocs, $projectMemorySnapshot, $recentContext)
            : $this->buildDeterministicMultiSourceAdvice($csvFiles, $pdfDocs, $materialDocs, $projectMemorySnapshot, $recentContext);
        $summary = "CSV件数=" . count($csvFiles)
            . " / PDF件数=" . count($pdfDocs)
            . " / 資料メモ件数=" . count($materialDocs)
            . " / 運用メモ要点=" . count((array)($projectMemorySnapshot['highlights'] ?? []));

        return [
            'final_response' => $finalResponse,
            'reasoning_steps' => [
                [
                    'sub_query' => '案件メモ・資料メモ・CSV/PDF の advisory 用メタ情報を収集',
                    'sub_answer' => $summary,
                ],
                [
                    'sub_query' => $isSummaryMode ? '資産構成から案件の全体像を整理' : '資産構成と現在地から推奨分析観点を組み立て',
                    'sub_answer' => $finalResponse,
                ],
            ],
            'guard_route' => null,
            'guard_context' => null,
            'force_report_mode_off' => false,
        ];
    }

    private function resolveCsvLogMetadataCompare(): ?array
    {
        if (!$this->shouldResolveCsvLogMetadataCompare()) {
            return null;
        }

        $csvFiles = $this->loadProjectCsvFiles();
        if (empty($csvFiles)) {
            return null;
        }

        $toolTerms = $this->extractQuotedTerms($this->searchQuery);
        $profiles = [];
        foreach ($csvFiles as $csv) {
            $profile = $this->buildCsvLogMetadataProfile($csv, $toolTerms);
            if ($profile !== null) {
                $profiles[] = $profile;
            }
        }

        if (empty($profiles)) {
            return null;
        }

        $relevantProfiles = array_values(array_filter($profiles, function (array $profile): bool {
            return !empty($profile['is_relevant']);
        }));

        if (empty($relevantProfiles)) {
            $logLikeProfiles = array_values(array_filter($profiles, function (array $profile): bool {
                return !empty($profile['is_log_like']);
            }));
            $relevantProfiles = !empty($logLikeProfiles) ? $logLikeProfiles : array_slice($profiles, 0, 2);
        }

        if (empty($relevantProfiles)) {
            return null;
        }

        $commonKeys = $this->computeCommonKeys($relevantProfiles);
        $diffKeys = $this->computeDistinctKeys($relevantProfiles, $commonKeys);
        $integrationKeys = $this->buildIntegrationKeyCandidates($relevantProfiles);
        $matchedTerms = $this->buildMatchedToolTerms($relevantProfiles, $toolTerms);

        $this->log(
            "[ADV-FASTPATH] csv_log_metadata_compare matched | csv_files=" . count($relevantProfiles)
            . " | common_keys=" . count($commonKeys)
            . " | diff_keys=" . count($diffKeys)
            . " | matched_terms=" . count($matchedTerms)
        );

        $finalResponse = $this->buildCsvLogMetadataCompareAnswer(
            $relevantProfiles,
            $commonKeys,
            $diffKeys,
            $integrationKeys,
            $matchedTerms
        );

        return [
            'final_response' => $finalResponse,
            'reasoning_steps' => [
                [
                    'sub_query' => 'CSVファイル一覧・列ヘッダ・row_data JSON keys を deterministic に収集',
                    'sub_answer' => $this->buildCsvLogMetadataCompareSnapshot($relevantProfiles, $matchedTerms),
                ],
                [
                    'sub_query' => 'ログ構造の共通項目・差異・統合観点を整理',
                    'sub_answer' => $finalResponse,
                ],
            ],
            'guard_route' => null,
            'guard_context' => null,
            'force_report_mode_off' => false,
        ];
    }

    private function buildDeterministicProjectAssetSummary(
        array $csvFiles,
        array $pdfDocs,
        array $materialDocs,
        array $projectMemorySnapshot,
        string $recentContext
    ): string
    {
        $lines = [];
        $lines[] = "案件の内容を、現在確認できる成果品から整理します。";
        $lines[] = "";
        $lines[] = "## 全体像";
        if (!empty($csvFiles) && (!empty($pdfDocs) || !empty($materialDocs))) {
            $lines[] = "この案件では、CSVによる定量データに加えて、PDFや資料メモによる文書系成果品も存在しており、数値把握と文書根拠整理を組み合わせて進める前提の構成になっています。";
        } elseif (!empty($csvFiles)) {
            $lines[] = "この案件では、CSVによる構造化データが主な成果品であり、まず件数分布や時系列などの定量把握から進めやすい状態です。";
        } elseif (!empty($materialDocs)) {
            $lines[] = "この案件では、資料メモや運用メモが作業用成果品として育っており、まず現在の主成果品と進行中タスクを確認してから深掘り対象を決める進め方が中心になります。";
        } else {
            $lines[] = "この案件では、PDF資料が主な成果品であり、留意点・制約・確認事項をページ番号付きで整理する進め方が中心になります。";
        }
        foreach ((array)($projectMemorySnapshot['highlights'] ?? []) as $highlight) {
            $lines[] = "- " . $highlight;
        }
        if ($recentContext !== '') {
            $lines[] = "- 直近の相談文脈: " . $recentContext;
        }
        $lines[] = "";
        $lines[] = "## 現在確認できる成果品";
        $lines[] = "- CSVファイル数: " . count($csvFiles) . "件";
        $lines[] = "- PDF資料数: " . count($pdfDocs) . "件";
        $lines[] = "- 資料メモ数: " . count($materialDocs) . "件";
        if (!empty($csvFiles)) {
            foreach (array_slice($csvFiles, 0, 5) as $csv) {
                $lines[] = "- CSV: " . (string)($csv['file_name'] ?? '名称不明') . " (" . (int)($csv['row_count'] ?? 0) . "件)";
            }
        }
        if (!empty($pdfDocs)) {
            foreach (array_slice($pdfDocs, 0, 5) as $doc) {
                $lines[] = "- PDF: " . (string)($doc['title'] ?? basename((string)($doc['file_path'] ?? '資料PDF')));
            }
        }
        if (!empty($materialDocs)) {
            foreach (array_slice($materialDocs, 0, 5) as $doc) {
                $lines[] = "- 資料メモ: " . $this->describeDocumentTitle($doc, '資料メモ');
            }
        }
        $lines[] = "";
        $lines[] = "## 進め方の見立て";
        foreach ($this->buildMultiSourceAdviceWorkflow(!empty($csvFiles), !empty($pdfDocs), !empty($materialDocs)) as $step) {
            $lines[] = $step;
        }
        $lines[] = "";
        $lines[] = "## 次に着手しやすいこと";
        foreach ($this->buildMultiSourceAdviceFirstActions(!empty($csvFiles), !empty($pdfDocs), !empty($materialDocs), $projectMemorySnapshot) as $step) {
            $lines[] = $step;
        }
        $lines[] = "";
        $lines[] = "## 出典";
        $lines[] = "- `project_csv_files`: " . count($csvFiles) . "件";
        $lines[] = "- `documents` (PDF): " . count($pdfDocs) . "件";
        $lines[] = "- `documents` (Markdown資料メモ): " . count($materialDocs) . "件";
        $lines[] = "- `project_meta` advisory要点: " . count((array)($projectMemorySnapshot['highlights'] ?? [])) . "件";

        return implode("\n", $lines);
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
            SELECT id, file_name, column_headers, row_count
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

    private function loadProjectMaterialDocuments(): array
    {
        if ($this->projectId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare("
            SELECT d.id, d.title, d.file_path, d.created_at, c.chunk_text
            FROM documents d
            LEFT JOIN (
                SELECT c1.doc_id, c1.chunk_text
                FROM doc_chunks c1
                INNER JOIN (
                    SELECT doc_id, MIN(id) AS min_chunk_id
                    FROM doc_chunks
                    WHERE page_number = 1
                    GROUP BY doc_id
                ) picked
                  ON picked.min_chunk_id = c1.id
            ) c
              ON c.doc_id = d.id
            WHERE d.project_id = ?
              AND LOWER(d.file_path) LIKE '%.md'
            ORDER BY d.created_at DESC, d.id DESC
            LIMIT 6
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

    private function buildDeterministicMultiSourceAdvice(
        array $csvFiles,
        array $pdfDocs,
        array $materialDocs,
        array $projectMemorySnapshot,
        string $recentContext
    ): string
    {
        $hasCsv = !empty($csvFiles);
        $hasPdf = !empty($pdfDocs);
        $hasMaterial = !empty($materialDocs);
        $lines = [];
        $lines[] = $this->buildMultiSourceAdviceLead($hasCsv, $hasPdf, $hasMaterial, $projectMemorySnapshot);
        $lines[] = "";
        $lines[] = "## まず見るべきもの";
        foreach ($this->buildPriorityFocusLines($csvFiles, $pdfDocs, $materialDocs, $projectMemorySnapshot, $recentContext) as $step) {
            $lines[] = $step;
        }

        $lines[] = "";
        $lines[] = "## CSVで確認する観点";
        if ($hasCsv) {
            foreach (array_slice($csvFiles, 0, 2) as $csv) {
                $lines[] = $this->buildCsvAdviceLine($csv);
            }
        } else {
            $lines[] = "- 現在、確認できるCSVはありません。まずは資料メモや運用メモから、集計したい指標や対象期間を固めるのが自然です。";
        }

        $lines[] = "";
        $lines[] = "## PDF/資料メモで確認する観点";
        if ($hasMaterial || $hasPdf) {
            foreach (array_slice($materialDocs, 0, 1) as $doc) {
                $title = $this->describeDocumentTitle($doc, '資料メモ');
                $summary = $this->extractMaterialDocSummary($doc);
                $lines[] = "- 資料メモ `{$title}`: 既存の章立てや追記ポイントを確認し、今回の観点をどこへ反映するかを先に決めるのがおすすめです。"
                    . ($summary !== '' ? " 概要: {$summary}" : '');
            }
            foreach (array_slice($pdfDocs, 0, 1) as $doc) {
                $title = (string)($doc['title'] ?? basename((string)($doc['file_path'] ?? '資料PDF')));
                $lines[] = "- `{$title}`: 留意点や制約条件をページ付きで抜き出し、CSV結果とは別に根拠整理するのがおすすめです。";
            }
        } elseif ($hasCsv) {
            $lines[] = "- 現在、対象PDFや資料メモは確認できませんでした。まずはCSVだけで定量把握を進め、必要な資料が追加された時点で留意点抽出を組み合わせるのが自然です。";
        } else {
            $lines[] = "- 現在は文書系や運用メモの整理が主になります。資料メモの見出しと運用メモの次アクションを照らし合わせ、何を成果品として育てるかを先に決めるのがおすすめです。";
        }

        $lines[] = "";
        $lines[] = "## 次に実行するとよい具体アクション";
        foreach ($this->buildMultiSourceAdviceFirstActions($hasCsv, $hasPdf, $hasMaterial, $projectMemorySnapshot) as $step) {
            $lines[] = $step;
        }
        $lines[] = "";
        $lines[] = "## 出典";
        if ($hasCsv) {
            $lines[] = "- CSVファイル数: " . count($csvFiles) . "件";
            $lines[] = "- 主なCSV: " . $this->describeCsvTitle((array)$csvFiles[0]);
        }
        if ($hasPdf) {
            $lines[] = "- PDF件数: " . count($pdfDocs) . "件";
            $lines[] = "- 主なPDF: " . $this->describeDocumentTitle((array)$pdfDocs[0], 'PDF');
        }
        if ($hasMaterial) {
            $lines[] = "- 資料メモ件数: " . count($materialDocs) . "件";
            $lines[] = "- 主な資料メモ: " . $this->describeDocumentTitle((array)$materialDocs[0], '資料メモ');
        }
        foreach (array_slice((array)($projectMemorySnapshot['highlights'] ?? []), 0, 2) as $highlight) {
            $lines[] = "- project_meta: " . $highlight;
        }

        return implode("\n", $lines);
    }

    private function buildMultiSourceAdviceLead(bool $hasCsv, bool $hasPdf, bool $hasMaterial, array $projectMemorySnapshot): string
    {
        $activeArtifact = trim((string)($projectMemorySnapshot['active_artifact'] ?? ''));
        $currentTask = trim((string)($projectMemorySnapshot['current_task'] ?? ''));

        if ($activeArtifact !== '' || $currentTask !== '') {
            $lead = "現在の主成果品と進行中タスクを軸に、必要なCSV・PDF・資料メモを順番に当てていく進め方がおすすめです。";
            if ($activeArtifact !== '') {
                $lead .= " 主成果品は {$activeArtifact} です。";
            }
            return $lead;
        }

        if ($hasCsv && ($hasPdf || $hasMaterial)) {
            return "CSVと文書系成果品を両方活かすなら、まず『CSVで定量把握』『PDF/資料メモで留意点整理』『両者の照合』の3段で進めるのがおすすめです。";
        }

        if ($hasCsv) {
            return "今回はCSV資産が中心なので、まず『全体像の把握』『主要列の分布確認』『業務に近い指標の深掘り』の順で進めるのがおすすめです。";
        }

        if ($hasMaterial) {
            return "今回は資料メモや運用メモが先に育っているため、まず『既存メモの見出し確認』『不足観点の洗い出し』『必要な資料の追加参照』の順で進めるのがおすすめです。";
        }

        return "今回は資料PDFが中心なので、まず『留意点抽出』『制約条件の整理』『ページ番号付き根拠の一覧化』の順で進めるのがおすすめです。";
    }

    private function buildMultiSourceAdviceWorkflow(bool $hasCsv, bool $hasPdf, bool $hasMaterial): array
    {
        if ($hasCsv && ($hasPdf || $hasMaterial)) {
            return [
                "- 1. 主要CSVを決め、件数分布・時系列・ランキングのどれを見るか先に切る。",
                "- 2. PDFや資料メモから留意点や制約条件を拾い、定量結果とは別レイヤーで整理する。",
                "- 3. 数値結果と文書側の注意事項を並べ、成果品更新に使える形へまとめる。",
            ];
        }

        if ($hasCsv) {
            return [
                "- 1. 主要CSVを1本決め、件数分布や時系列の傾向を確認する。",
                "- 2. その後、偏りが見えた列を対象にランキングや比較へ進む。",
            ];
        }

        if ($hasMaterial) {
            return [
                "- 1. 今の依頼に近い資料メモを1本選ぶ。",
                "- 2. 運用メモの進行中タスクを見て、資料メモへ足すべき観点を決める。",
                "- 3. 必要なときだけPDFを補助参照し、差分を具体化する。",
            ];
        }

        return [
            "- 1. PDFから、留意点・制約・確認事項をページ番号付きで抽出する。",
            "- 2. 寸法、条件値、禁止事項など、判断に直結する情報をカテゴリ別に整理する。",
            "- 3. 最後に、現場や運用判断に使うための確認リストへまとめる。",
        ];
    }

    private function buildMultiSourceAdviceFirstActions(bool $hasCsv, bool $hasPdf, bool $hasMaterial, array $projectMemorySnapshot): array
    {
        $tasks = [];
        $currentTask = trim((string)($projectMemorySnapshot['current_task'] ?? ''));
        $nextAction = trim((string)($projectMemorySnapshot['next_action'] ?? ''));

        if ($currentTask !== '') {
            $tasks[] = "- 進行中タスクを確認する: {$currentTask}";
        }
        if ($nextAction !== '' && $nextAction !== $currentTask) {
            $tasks[] = "- 次アクション候補を確認する: {$nextAction}";
        }

        if ($hasCsv && ($hasPdf || $hasMaterial)) {
            return [
                ...$tasks,
                "- CSV全体の概要を出す",
                "- 業務系CSVを1本選び、件数分布か時系列のどちらを先に見るか決める",
                "- PDFや資料メモの留意点と、CSVの数値結果を照合する",
            ];
        }

        if ($hasCsv) {
            return [
                ...$tasks,
                "- CSV全体の概要を出す",
                "- 業務に近いCSVを1本選び、件数分布かランキングを出す",
                "- 必要なら時系列や特定条件で絞った集計へ進む",
            ];
        }

        if ($hasMaterial) {
            return [
                ...$tasks,
                "- 資料メモの中で今の依頼に一番近いものを1本開く",
                "- 既存の章立てや追記ポイントを確認し、どの観点を先に埋めるか決める",
                "- 不足する根拠がある場合だけ、関連PDFを追加参照する",
            ];
        }

        return [
            ...$tasks,
            "- PDF全体から主要な留意点を抽出する",
            "- 次にページ番号付きで制約条件を一覧化する",
            "- その後、判断に必要な確認事項リストへ整理する",
        ];
    }

    private function buildPriorityFocusLines(
        array $csvFiles,
        array $pdfDocs,
        array $materialDocs,
        array $projectMemorySnapshot,
        string $recentContext
    ): array {
        $lines = [];

        foreach ((array)($projectMemorySnapshot['highlights'] ?? []) as $highlight) {
            $lines[] = '- ' . $highlight;
        }

        if (!empty($materialDocs)) {
            $lines[] = '- 既存の資料メモ: ' . $this->describeDocumentTitle((array)$materialDocs[0], '資料メモ') . ' から、今の依頼に近い章や追記ポイントを確認する';
        }

        if (!empty($csvFiles)) {
            $lines[] = '- 主要CSV: ' . $this->describeCsvTitle((array)$csvFiles[0]) . ' の列構成と件数から、先に出せる定量観点を見極める';
        }

        if (!empty($pdfDocs)) {
            $lines[] = '- 主要PDF: ' . $this->describeDocumentTitle((array)$pdfDocs[0], 'PDF') . ' から、留意点や制約条件の根拠を拾えるか確認する';
        }

        if ($recentContext !== '') {
            $lines[] = '- 直近の相談文脈: ' . $this->compactLine($recentContext, 60);
        }

        if (empty($lines)) {
            $lines[] = '- まず案件運用メモから、今の主成果品と次アクションを確認する';
        }

        return array_slice($lines, 0, 4);
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
            return "- `{$fileName}` ({$rowCount}件): 言語別や部署別の分布確認から入るのが向いています。";
        }
        if (preg_match('/username-or-email/i', $fileName)) {
            return "- `{$fileName}` ({$rowCount}件): 識別子やメールの重複、属性分布の確認が向いています。";
        }
        if (preg_match('/入荷実績一覧/u', $fileName)) {
            return "- `{$fileName}` ({$rowCount}件): 品番別件数や仕入先別件数、発注数と入荷数の比較がおすすめです。";
        }
        if (preg_match('/健康診断一覧/u', $fileName)) {
            return "- `{$fileName}` ({$rowCount}件): 年齢分布や主要指標の要約統計、年代別比較が有効です。";
        }
        if (preg_match('/出荷一覧表/u', $fileName)) {
            return "- `{$fileName}` ({$rowCount}件): 商品別件数や受注日の時系列、本数や合計のランキング確認が向いています。";
        }

        $headerPreview = implode(' / ', array_slice($headers, 0, 5));
        if ($headerPreview === '') {
            $headerPreview = '主要列';
        }

        return "- `{$fileName}` ({$rowCount}件): 主要列（{$headerPreview}）の値分布と欠損有無から確認するのがおすすめです。";
    }

    private function buildProjectMemorySnapshot(array $projectMemoryDocs): array
    {
        $snapshot = [
            'active_artifact' => '',
            'target' => '',
            'current_task' => '',
            'next_action' => '',
            'highlights' => [],
        ];

        $text = $this->flattenProjectMemoryText($projectMemoryDocs);
        if ($text === '') {
            return $snapshot;
        }

        $snapshot['active_artifact'] = $this->extractLabeledValue($text, ['現在の主成果品', '現在の主レーン', 'レーン']);
        $snapshot['target'] = $this->extractLabeledValue($text, ['主対象']);
        $snapshot['current_task'] = $this->extractLabeledValue($text, ['進行中タスク']);
        $snapshot['next_action'] = $this->extractLabeledValue($text, ['次アクション', '次に実行するとよい具体アクション', '次に着手しやすいこと']);

        if ($snapshot['current_task'] === '') {
            $snapshot['current_task'] = $this->extractSectionBullet($text, '進行中');
        }
        if ($snapshot['next_action'] === '') {
            $snapshot['next_action'] = $this->extractSectionBullet($text, '次に着手しやすいこと');
        }

        foreach ([
            '現在の主成果品' => $snapshot['active_artifact'],
            '主対象' => $snapshot['target'],
            '進行中タスク' => $snapshot['current_task'],
            '次アクション' => $snapshot['next_action'],
        ] as $label => $value) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            $snapshot['highlights'][] = $label . ': ' . $value;
        }

        return $snapshot;
    }

    private function flattenProjectMemoryText(array $projectMemoryDocs): string
    {
        $parts = [];
        foreach (['todo', 'agents', 'readme'] as $type) {
            $autoContent = trim((string)($projectMemoryDocs[$type]['auto_content'] ?? ''));
            $content = trim((string)($projectMemoryDocs[$type]['content'] ?? ''));
            if ($autoContent !== '') {
                $parts[] = $autoContent;
            }
            if ($content !== '') {
                $parts[] = $content;
            }
        }

        return implode("\n\n", $parts);
    }

    private function extractLabeledValue(string $text, array $labels): string
    {
        foreach ($labels as $label) {
            $quoted = preg_quote($label, '/');
            if (preg_match('/^[\-\*\d\.\s]*' . $quoted . '\s*[:：]\s*(.+)$/mu', $text, $matches) === 1) {
                return $this->compactLine(trim((string)($matches[1] ?? '')), 120);
            }
        }

        return '';
    }

    private function extractSectionBullet(string $text, string $heading): string
    {
        $quoted = preg_quote($heading, '/');
        if (preg_match('/^##\s*' . $quoted . '\s*(?:\R|$)([\s\S]*?)(?=^##\s|\z)/mu', $text, $matches) !== 1) {
            return '';
        }

        $section = trim((string)($matches[1] ?? ''));
        if ($section === '') {
            return '';
        }

        $lines = preg_split('/\R/u', $section) ?: [];
        foreach ($lines as $line) {
            $normalized = trim((string)preg_replace('/^[\-\*\d\.\s]+/u', '', $line));
            if ($normalized !== '') {
                return $this->compactLine($normalized, 120);
            }
        }

        return '';
    }

    private function buildRecentAdviceContext(array $history): string
    {
        if (empty($history)) {
            return '';
        }

        $userLines = [];
        foreach ($history as $row) {
            if ((string)($row['role'] ?? '') !== 'user') {
                continue;
            }
            $message = trim((string)($row['message'] ?? ''));
            if ($message === '') {
                continue;
            }
            $userLines[] = $this->compactLine($message, 80);
        }

        if (empty($userLines)) {
            return '';
        }

        return implode(' / ', array_slice($userLines, -2));
    }

    private function shouldResolveCsvLogMetadataCompare(): bool
    {
        $message = $this->searchQuery;
        $quotedTerms = $this->extractQuotedTerms($message);
        $listedTerms = $this->extractListedToolTerms($message);

        $hasKeyHint = preg_match('/(ToolSource|Timestamp|UserID|LogID|ActionType|ActionDetail|EventType|SessionID|InputPrompt|OutputResult)/u', $message) === 1;
        $hasLogContext = preg_match('/(ログ|記録|記録して|記録され|項目|カラム|列|構造|キー|フィールド)/u', $message) === 1;
        $hasCompareIntent = preg_match('/(比較|共通|差異|統合|揃え|そろえ|マッピング|対応付け|整理|どのような情報|何が記録|見るべき項目|確認すべき列)/u', $message) === 1;
        $hasMultiToolHint = count(array_unique(array_merge($quotedTerms, $listedTerms))) >= 2
            || preg_match('/(複数ツール|各AIツール|各ツール|複数の生成AI|3つの生成AI|統合する場合)/u', $message) === 1;
        $hasNumericAggregateIntent = preg_match('/(月別|日別|件数|合計|平均|ランキング|グラフ|推移|割合)/u', $message) === 1;

        return ($hasLogContext || $hasKeyHint)
            && $hasCompareIntent
            && ($hasMultiToolHint || $hasKeyHint)
            && !$hasNumericAggregateIntent;
    }

    private function extractQuotedTerms(string $text): array
    {
        $terms = [];
        if (preg_match_all('/[「『"]([^"」』]{2,40})["」』]/u', $text, $matches) === 1) {
            foreach ((array)($matches[1] ?? []) as $term) {
                $normalized = trim((string)$term);
                if ($normalized !== '') {
                    $terms[] = $normalized;
                }
            }
        }
        return array_values(array_unique($terms));
    }

    private function extractListedToolTerms(string $text): array
    {
        if (preg_match('/(.{0,80})のログ/u', $text, $matches) !== 1) {
            return [];
        }

        $segment = trim((string)($matches[1] ?? ''));
        if ($segment === '' || preg_match('/(案件|プロジェクト|今回|この案件)/u', $segment) === 1) {
            return [];
        }

        $parts = preg_split('/[、,\/／]+/u', $segment) ?: [];
        $terms = [];
        foreach ($parts as $part) {
            $term = trim((string)preg_replace('/(の|に関する|について|生成AI|AIツール|ツール)$/u', '', $part));
            if ($term === '' || mb_strlen($term) < 2 || mb_strlen($term) > 30) {
                continue;
            }
            if (preg_match('/(ログ|項目|構造|比較|統合|共通|差異)/u', $term) === 1) {
                continue;
            }
            $terms[] = $term;
        }

        return array_values(array_unique($terms));
    }

    private function buildCsvLogMetadataProfile(array $csv, array $toolTerms): ?array
    {
        $csvFileId = (int)($csv['id'] ?? 0);
        if ($csvFileId <= 0) {
            return null;
        }

        $headers = $this->parseCsvHeaders((string)($csv['column_headers'] ?? ''));
        $sampleRows = $this->loadCsvRowSamples($csvFileId, 8);
        $sampleKeys = $this->extractJsonKeysFromRows($sampleRows);
        $allKeys = array_values(array_unique(array_merge($headers, $sampleKeys)));
        $toolSourceKey = $this->findMatchingKey($allKeys, ['ToolSource', 'toolsource', 'tool_source']);
        $sampleToolValues = $toolSourceKey !== null
            ? $this->loadDistinctJsonValues($csvFileId, $toolSourceKey, 8)
            : [];

        $isLogLike = $this->isLogLikeCsvProfile($allKeys, $sampleToolValues, (string)($csv['file_name'] ?? ''));
        $matchedTerms = [];
        foreach ($toolTerms as $term) {
            $termLower = mb_strtolower($term);
            $fileNameLower = mb_strtolower((string)($csv['file_name'] ?? ''));
            if ($fileNameLower !== '' && mb_strpos($fileNameLower, $termLower) !== false) {
                $matchedTerms[] = $term;
                continue;
            }
            foreach ($sampleToolValues as $toolValue) {
                if (mb_strpos(mb_strtolower($toolValue), $termLower) !== false) {
                    $matchedTerms[] = $term;
                    break;
                }
            }
        }

        return [
            'csv_file_id' => $csvFileId,
            'file_name' => (string)($csv['file_name'] ?? ''),
            'row_count' => (int)($csv['row_count'] ?? 0),
            'headers' => $headers,
            'sample_keys' => $sampleKeys,
            'all_keys' => $allKeys,
            'sample_tool_values' => $sampleToolValues,
            'tool_source_key' => $toolSourceKey,
            'is_log_like' => $isLogLike,
            'matched_terms' => array_values(array_unique($matchedTerms)),
            'is_relevant' => $isLogLike && (!empty($matchedTerms) || count($toolTerms) === 0),
        ];
    }

    private function parseCsvHeaders(string $rawHeaders): array
    {
        $headers = json_decode($rawHeaders, true);
        if (!is_array($headers)) {
            $headers = array_filter(array_map('trim', explode(',', $rawHeaders)));
        }

        $normalized = [];
        foreach ($headers as $header) {
            $header = trim((string)$header);
            if ($header !== '') {
                $normalized[] = $header;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function loadCsvRowSamples(int $csvFileId, int $limit): array
    {
        $stmt = $this->pdo->prepare("
            SELECT row_data
            FROM project_csv_rows
            WHERE csv_file_id = ?
            ORDER BY row_index ASC
            LIMIT {$limit}
        ");
        $stmt->execute([$csvFileId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    private function extractJsonKeysFromRows(array $sampleRows): array
    {
        $keys = [];
        foreach ($sampleRows as $rowJson) {
            $decoded = json_decode((string)$rowJson, true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach (array_keys($decoded) as $key) {
                $key = trim((string)$key);
                if ($key !== '') {
                    $keys[] = $key;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    private function findMatchingKey(array $keys, array $candidates): ?string
    {
        foreach ($keys as $key) {
            foreach ($candidates as $candidate) {
                if (mb_strtolower($key) === mb_strtolower($candidate)) {
                    return $key;
                }
            }
        }
        return null;
    }

    private function loadDistinctJsonValues(int $csvFileId, string $jsonKey, int $limit): array
    {
        $escapedKey = str_replace(['\\', '"'], ['\\\\', '\\"'], $jsonKey);
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.\"{$escapedKey}\"')) AS item
            FROM project_csv_rows
            WHERE csv_file_id = ?
              AND JSON_EXTRACT(row_data, '$.\"{$escapedKey}\"') IS NOT NULL
            ORDER BY item ASC
            LIMIT {$limit}
        ");
        $stmt->execute([$csvFileId]);
        $values = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $values[] = $value;
            }
        }
        return array_values(array_unique($values));
    }

    private function isLogLikeCsvProfile(array $keys, array $toolValues, string $fileName): bool
    {
        $signals = 0;
        $lowerKeys = array_map('mb_strtolower', $keys);

        foreach (['timestamp', 'userid', 'toolsource', 'actiontype', 'actiondetail', 'eventtype', 'logid'] as $required) {
            if (in_array($required, $lowerKeys, true)) {
                $signals++;
            }
        }

        if (!empty($toolValues)) {
            $signals++;
        }

        if (preg_match('/(ログ|log|統合|audit|event)/iu', $fileName) === 1) {
            $signals++;
        }

        return $signals >= 2;
    }

    private function computeCommonKeys(array $profiles): array
    {
        $keySets = [];
        foreach ($profiles as $profile) {
            $keys = array_values(array_unique(array_map('strval', (array)($profile['all_keys'] ?? []))));
            if (!empty($keys)) {
                $keySets[] = $keys;
            }
        }

        if (empty($keySets)) {
            return [];
        }

        $common = $keySets[0];
        foreach (array_slice($keySets, 1) as $keys) {
            $common = array_values(array_intersect($common, $keys));
        }

        return array_slice($common, 0, 12);
    }

    private function computeDistinctKeys(array $profiles, array $commonKeys): array
    {
        $commonLookup = array_fill_keys($commonKeys, true);
        $distinct = [];
        foreach ($profiles as $profile) {
            foreach ((array)($profile['all_keys'] ?? []) as $key) {
                if (!isset($commonLookup[$key])) {
                    $distinct[] = (string)$key;
                }
            }
        }

        return array_slice(array_values(array_unique($distinct)), 0, 16);
    }

    private function buildIntegrationKeyCandidates(array $profiles): array
    {
        $priority = ['Timestamp', 'UserID', 'Email', 'Name', 'ToolSource', 'LogID', 'SessionID', 'ActionType', 'ActionDetail', 'EventType'];
        $available = [];
        foreach ($profiles as $profile) {
            foreach ((array)($profile['all_keys'] ?? []) as $key) {
                $available[mb_strtolower((string)$key)] = (string)$key;
            }
        }

        $candidates = [];
        foreach ($priority as $key) {
            $lower = mb_strtolower($key);
            if (isset($available[$lower])) {
                $candidates[] = $available[$lower];
            }
        }

        return $candidates;
    }

    private function buildMatchedToolTerms(array $profiles, array $toolTerms): array
    {
        $matched = [];
        foreach ($profiles as $profile) {
            foreach ((array)($profile['matched_terms'] ?? []) as $term) {
                $matched[] = (string)$term;
            }
            foreach ((array)($profile['sample_tool_values'] ?? []) as $toolValue) {
                foreach ($toolTerms as $term) {
                    if (mb_strpos(mb_strtolower($toolValue), mb_strtolower($term)) !== false) {
                        $matched[] = $term;
                    }
                }
            }
        }

        return array_values(array_unique($matched));
    }

    private function buildCsvLogMetadataCompareAnswer(
        array $profiles,
        array $commonKeys,
        array $diffKeys,
        array $integrationKeys,
        array $matchedTerms
    ): string {
        $lines = [];
        $lines[] = "確認できるCSVメタデータと少数サンプルの `row_data` JSON keys をもとに、ログ構造の比較観点を整理します。今回は汎用 Text-to-SQL 集計ではなく、列構造とキー構成の把握を優先しています。";
        $lines[] = "";
        $lines[] = "## 各CSV/各ツールで記録している主な項目";

        foreach (array_slice($profiles, 0, 3) as $profile) {
            $title = $this->describeCsvTitle($profile);
            $keys = array_slice((array)($profile['all_keys'] ?? []), 0, 10);
            $toolValues = array_slice((array)($profile['sample_tool_values'] ?? []), 0, 5);
            $matched = array_slice((array)($profile['matched_terms'] ?? []), 0, 3);
            $line = "- `{$title}`";
            $line .= " (" . (int)($profile['row_count'] ?? 0) . "件)";
            if (!empty($matched)) {
                $line .= ": 質問中のツール名と一致した候補は " . implode(' / ', $matched) . " です。";
            } elseif (!empty($toolValues)) {
                $line .= ": `ToolSource` サンプル値として " . implode(' / ', $toolValues) . " を確認できました。";
            } else {
                $line .= ": `ToolSource` の値までは少数サンプルでは断定できませんでした。";
            }
            if (!empty($keys)) {
                $line .= " 主な項目は " . implode(' / ', $keys) . " です。";
            }
            $lines[] = $line;
        }

        $lines[] = "";
        $lines[] = "## 共通項目";
        if (!empty($commonKeys)) {
            $lines[] = "- 共通して確認できたキー: " . implode(' / ', array_slice($commonKeys, 0, 12));
        } else {
            $lines[] = "- 少数サンプル時点では、複数CSVをまたいで安定して共通と断定できるキーは限定的です。少なくとも `Timestamp` / `UserID` / `ToolSource` の有無を優先確認するのが安全です。";
        }

        $lines[] = "";
        $lines[] = "## 差異項目";
        if (!empty($diffKeys)) {
            $lines[] = "- 差異として見えた候補キー: " . implode(' / ', array_slice($diffKeys, 0, 12));
        } else {
            $lines[] = "- 少数サンプルでは、ツールごとの専用キー差分よりも共通スキーマで管理されている可能性が高く見えます。";
        }

        $lines[] = "";
        $lines[] = "## 統合キー候補";
        if (!empty($integrationKeys)) {
            $lines[] = "- まず候補にしやすいキー: " . implode(' / ', $integrationKeys);
        } else {
            $lines[] = "- `Timestamp` / `UserID` / `ToolSource` / `LogID` の有無をまず確認してください。これらが無い場合は、統合キーを別途設計する必要があります。";
        }

        $lines[] = "";
        $lines[] = "## 注意点";
        $lines[] = "- `Timestamp` がある場合は、時刻形式とタイムゾーン表記を先に揃える必要があります。";
        $lines[] = "- `UserID` と `Email` / `Name` が混在する場合は、ユーザー同定ルールを先に決めないと重複や取り違えが起きやすくなります。";
        $lines[] = "- `ToolSource` が同一CSV内の識別子として使われている場合は、ツール別CSVとして分けて考えるより、共通スキーマ + ツール種別列として扱う方が整理しやすいです。";
        $lines[] = "- `LogID` や `SessionID` が無いCSVでは、イベント単位の厳密突合は難しいため、時刻 + ユーザー + 操作種別の複合キーを検討する必要があります。";

        $lines[] = "";
        $lines[] = "## 次に確認すべきCSV列";
        $nextColumns = !empty($integrationKeys)
            ? array_slice($integrationKeys, 0, 6)
            : ['Timestamp', 'UserID', 'ToolSource', 'LogID', 'ActionType', 'ActionDetail'];
        $lines[] = "- 優先確認列: " . implode(' / ', $nextColumns);
        if (!empty($matchedTerms)) {
            $lines[] = "- 今回の質問で挙がったツール名候補: " . implode(' / ', array_slice($matchedTerms, 0, 6));
        }

        $lines[] = "";
        $lines[] = "## 出典";
        foreach (array_slice($profiles, 0, 3) as $profile) {
            $lines[] = "- `project_csv_files`: " . $this->describeCsvTitle($profile) . " / rows=" . (int)($profile['row_count'] ?? 0);
            $lines[] = "- `project_csv_rows.row_data` sample keys: " . implode(' / ', array_slice((array)($profile['sample_keys'] ?? []), 0, 8));
        }

        return implode("\n", $lines);
    }

    private function buildCsvLogMetadataCompareSnapshot(array $profiles, array $matchedTerms): string
    {
        $lines = [];
        $lines[] = "対象CSV数: " . count($profiles);
        if (!empty($matchedTerms)) {
            $lines[] = "一致ツール候補: " . implode(' / ', array_slice($matchedTerms, 0, 6));
        }
        foreach (array_slice($profiles, 0, 3) as $profile) {
            $lines[] = "- " . $this->describeCsvTitle($profile)
                . " | rows=" . (int)($profile['row_count'] ?? 0)
                . " | keys=" . implode(', ', array_slice((array)($profile['all_keys'] ?? []), 0, 8));
        }
        return implode("\n", $lines);
    }

    private function describeDocumentTitle(array $document, string $fallback): string
    {
        $title = trim((string)($document['title'] ?? ''));
        if ($title !== '') {
            return $this->compactLine($title, 60);
        }

        $filePath = trim((string)($document['file_path'] ?? ''));
        if ($filePath !== '') {
            return $this->compactLine(basename($filePath), 60);
        }

        return $fallback;
    }

    private function describeCsvTitle(array $csv): string
    {
        $fileName = trim((string)($csv['file_name'] ?? ''));
        if ($fileName === '') {
            return '対象CSV';
        }

        return $this->compactLine($fileName, 60);
    }

    private function extractMaterialDocSummary(array $document): string
    {
        $chunkText = trim((string)($document['chunk_text'] ?? ''));
        if ($chunkText === '') {
            return '';
        }

        $chunkText = preg_replace('/^#.+$/mu', '', $chunkText) ?? $chunkText;
        $chunkText = trim((string)preg_replace('/\s+/u', ' ', $chunkText));
        if ($chunkText === '') {
            return '';
        }

        return $this->compactLine($chunkText, 40);
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
