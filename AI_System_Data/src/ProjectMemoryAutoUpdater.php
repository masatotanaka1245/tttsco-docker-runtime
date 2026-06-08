<?php

require_once __DIR__ . '/ProjectContextMemory.php';

final class ProjectMemoryAutoUpdater
{
    public static function shouldRefreshFromEvaluation(?array $evalResult, string $finalResponse = ''): bool
    {
        if (empty($evalResult)) {
            return !self::looksLikeIncompleteOrUnsafeAnswer($finalResponse);
        }

        if (array_key_exists('allow_memory_refresh', $evalResult)) {
            return (bool)$evalResult['allow_memory_refresh'];
        }

        $evaluationSource = (string)($evalResult['evaluation_source'] ?? '');
        $evaluationMode = (string)($evalResult['evaluation_mode'] ?? '');
        $verdict = (string)($evalResult['verdict'] ?? '');
        $totalScore = (int)($evalResult['total_score'] ?? 0);

        if ($evaluationSource === 'judge_fallback' || $evaluationMode === 'fallback') {
            return false;
        }

        if ($verdict === 'reject' || $totalScore < 85) {
            return false;
        }

        return !self::looksLikeIncompleteOrUnsafeAnswer($finalResponse);
    }

    public static function refresh(PDO $pdo, int $projectId, ?int $threadId, int $userId, ?callable $logger = null): array
    {
        if ($projectId <= 0) {
            return ProjectContextMemory::load($pdo, $projectId);
        }

        $snapshot = self::collectSnapshot($pdo, $projectId, $threadId, $userId);
        $docs = [
            'agents' => self::buildAgentsDoc($snapshot),
            'readme' => self::buildReadmeDoc($snapshot),
            'todo' => self::buildTodoDoc($snapshot),
        ];

        ProjectContextMemory::save($pdo, $projectId, $docs);
        $loadedDocs = ProjectContextMemory::load($pdo, $projectId);

        if ($logger !== null) {
            $loaded = ProjectContextMemory::loadedTypes($loadedDocs);
            $logger(
                '[PROJECT-MEMORY-AUTO] refreshed='
                . (empty($loaded) ? 'none' : implode(',', $loaded))
                . ' | chars=' . ProjectContextMemory::totalChars($loadedDocs)
                . ' | thread_id=' . ($threadId === null ? 'NULL' : (string)$threadId)
            );
        }

        return $loadedDocs;
    }

    private static function looksLikeIncompleteOrUnsafeAnswer(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return true;
        }

        $patterns = [
            '/結果を待つ流れ/u',
            '/実行していません/u',
            '/追加抽出/u',
            '/追加検索/u',
            '/追加SQL/u',
            '/フェイルセーフ/u',
            '/初期ドラフトを採用/u',
            '/^SELECT\b/imu',
            '/```sql/iu',
            '/^⚠️/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function collectSnapshot(PDO $pdo, int $projectId, ?int $threadId, int $userId): array
    {
        $project = self::fetchOne(
            $pdo,
            "SELECT project_name, description, status FROM projects WHERE id = ?",
            [$projectId]
        );

        $csvFiles = self::fetchAll(
            $pdo,
            "SELECT file_name, column_headers, row_count FROM project_csv_files WHERE project_id = ? ORDER BY id ASC",
            [$projectId]
        );

        $pdfDocs = self::fetchAll(
            $pdo,
            "SELECT title, file_path, created_at
               FROM documents
              WHERE project_id = ?
                AND LOWER(file_path) LIKE '%.pdf'
                AND title NOT LIKE 'AI報告書%'
              ORDER BY created_at DESC, id DESC
              LIMIT 12",
            [$projectId]
        );

        $historySql = "
            SELECT role, message, created_at
              FROM chat_history
             WHERE project_id = ?
               AND user_id = ?";
        $historyParams = [$projectId, $userId];
        if ($threadId !== null) {
            $historySql .= " AND thread_id = ?";
            $historyParams[] = $threadId;
        }
        $historySql .= " ORDER BY created_at DESC LIMIT 24";
        $history = array_reverse(self::fetchAll($pdo, $historySql, $historyParams));

        $commentCount = (int)self::fetchScalar(
            $pdo,
            "SELECT COUNT(*) FROM project_comments WHERE project_id = ?",
            [$projectId]
        );
        $faqCount = (int)self::fetchScalar(
            $pdo,
            "SELECT COUNT(*) FROM project_faqs WHERE project_id = ?",
            [$projectId]
        );

        return [
            'project_id' => $projectId,
            'thread_id' => $threadId,
            'user_id' => $userId,
            'project_name' => (string)($project['project_name'] ?? '名称未設定'),
            'description' => trim((string)($project['description'] ?? '')),
            'status' => trim((string)($project['status'] ?? '')),
            'csv_files' => $csvFiles,
            'pdf_docs' => $pdfDocs,
            'history' => $history,
            'comment_count' => $commentCount,
            'faq_count' => $faqCount,
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    private static function buildAgentsDoc(array $snapshot): string
    {
        $topics = self::detectTopics($snapshot['history']);
        $lines = [];
        $lines[] = '# AGENTS';
        $lines[] = '';
        $lines[] = '> 自動更新: ' . $snapshot['generated_at'];
        $lines[] = '> この内容は案件状態と直近会話から自動生成されます。';
        $lines[] = '';
        $lines[] = '## 回答方針';
        $lines[] = '- 現在の案件では、`CSV / PDF / DB実データ` を最優先の根拠として扱う。';
        $lines[] = '- 現在スレッドの文脈を優先し、follow-up や履歴要約は thread 単位で継続する。';
        $lines[] = '- PDFを根拠に答える場合は、できるだけ資料名とページ番号を付ける。';
        $lines[] = '- CSVを扱う場合は、概要・件数分布・時系列・ランキングを混同せず切り分ける。';
        $lines[] = '- グラフ要求では `json:chart` を優先し、報告書要求では見出し構成を明確にする。';
        $lines[] = '';
        $lines[] = '## 現在の重点';
        if (empty($topics)) {
            $lines[] = '- 直近会話がまだ少ないため、案件の全体像把握を優先する。';
        } else {
            foreach ($topics as $topic => $count) {
                $lines[] = '- ' . $topic . ' (' . $count . '件程度)';
            }
        }
        $lines[] = '';
        $lines[] = '## 運用メモ';
        $lines[] = '- プロジェクトID: ' . $snapshot['project_id'];
        $lines[] = '- 現在スレッド: ' . ($snapshot['thread_id'] === null ? '未選択' : (string)$snapshot['thread_id']);
        $lines[] = '- FAQ件数: ' . (int)$snapshot['faq_count'] . '件 / コメント件数: ' . (int)$snapshot['comment_count'] . '件';

        return implode("\n", $lines);
    }

    private static function buildReadmeDoc(array $snapshot): string
    {
        $csvFiles = $snapshot['csv_files'];
        $pdfDocs = $snapshot['pdf_docs'];
        $latestRequests = self::extractLatestUserMessages($snapshot['history'], 5);

        $lines = [];
        $lines[] = '# README';
        $lines[] = '';
        $lines[] = '> 自動更新: ' . $snapshot['generated_at'];
        $lines[] = '';
        $lines[] = '## 案件概要';
        $lines[] = '- 案件名: ' . $snapshot['project_name'];
        if ($snapshot['status'] !== '') {
            $lines[] = '- ステータス: ' . $snapshot['status'];
        }
        if ($snapshot['description'] !== '') {
            $lines[] = '- 説明: ' . self::compactLine($snapshot['description'], 200);
        }
        $lines[] = '';
        $lines[] = '## 保有データ';
        $lines[] = '- CSVファイル: ' . count($csvFiles) . '件';
        foreach (array_slice($csvFiles, 0, 5) as $csv) {
            $lines[] = '  - ' . (string)($csv['file_name'] ?? '名称不明') . ' (' . (int)($csv['row_count'] ?? 0) . '行)';
        }
        $lines[] = '- PDF資料: ' . count($pdfDocs) . '件';
        foreach (array_slice($pdfDocs, 0, 5) as $pdf) {
            $lines[] = '  - ' . (string)($pdf['title'] ?? basename((string)($pdf['file_path'] ?? '資料PDF')));
        }
        $lines[] = '';
        $lines[] = '## 直近スレッドの傾向';
        if (empty($latestRequests)) {
            $lines[] = '- 現在スレッドには、まだ要約できるユーザー依頼が十分にありません。';
        } else {
            foreach ($latestRequests as $request) {
                $lines[] = '- ' . $request;
            }
        }
        $lines[] = '';
        $lines[] = '## 使い方の前提';
        $lines[] = '- まずCSVで定量情報を把握し、次にPDFで留意点や制約を抽出し、最後に両者を照合する流れが取りやすい。';
        $lines[] = '- 履歴要約や履歴報告書化は、案件全体ではなく現在スレッド基準で扱う。';

        return implode("\n", $lines);
    }

    private static function buildTodoDoc(array $snapshot): string
    {
        $csvFiles = $snapshot['csv_files'];
        $pdfDocs = $snapshot['pdf_docs'];
        $latestRequests = self::extractLatestUserMessages($snapshot['history'], 6);

        $lines = [];
        $lines[] = '# TODO';
        $lines[] = '';
        $lines[] = '> 自動更新: ' . $snapshot['generated_at'];
        $lines[] = '';
        $lines[] = '## 直近依頼ベースの優先タスク';
        if (empty($latestRequests)) {
            $lines[] = '- [ ] まず現在スレッドで1〜2件やり取りし、文脈を作る';
        } else {
            foreach ($latestRequests as $request) {
                $lines[] = '- [ ] ' . $request;
            }
        }
        $lines[] = '';
        $lines[] = '## 推奨の次アクション';
        if (!empty($csvFiles)) {
            $lines[] = '- [ ] CSV全体の概要確認、または対象CSVを1本に絞った件数分布/ランキング確認';
        }
        if (!empty($pdfDocs)) {
            $lines[] = '- [ ] PDFの留意点・制約・確認事項をページ番号付きで一覧化';
        }
        if (!empty($csvFiles) && !empty($pdfDocs)) {
            $lines[] = '- [ ] CSVの数値結果とPDFの注意事項を照合し、運用判断に使える形へ整理';
        }
        if (empty($csvFiles) && empty($pdfDocs)) {
            $lines[] = '- [ ] まずCSVまたはPDFを登録し、分析対象を用意する';
        }
        $lines[] = '';
        $lines[] = '## 補足';
        $lines[] = '- 現在スレッドの履歴件数: ' . count($snapshot['history']) . '件';
        $lines[] = '- このTODOは自動生成のため、次回の会話保存時に更新される';

        return implode("\n", $lines);
    }

    private static function detectTopics(array $history): array
    {
        $topicPatterns = [
            'CSV集計・概要確認' => '/CSV|csv|集計|概要|カラム|列/u',
            'PDF抽出・RAG確認' => '/PDF|pdf|資料|留意点|図面|doc_chunks/u',
            '履歴要約・報告書化' => '/会話|履歴|チャット|報告書|レポート/u',
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

    private static function extractLatestUserMessages(array $history, int $limit): array
    {
        $messages = [];
        foreach (array_reverse($history) as $row) {
            if (($row['role'] ?? '') !== 'user') {
                continue;
            }
            $messages[] = self::compactLine((string)($row['message'] ?? ''), 120);
            if (count($messages) >= $limit) {
                break;
            }
        }
        return $messages;
    }

    private static function compactLine(string $text, int $limit): string
    {
        $text = trim((string)(preg_replace('/\s+/u', ' ', $text) ?? $text));
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        return mb_substr($text, 0, $limit) . '...';
    }

    private static function fetchOne(PDO $pdo, string $sql, array $params): array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    private static function fetchAll(PDO $pdo, string $sql, array $params): array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    private static function fetchScalar(PDO $pdo, string $sql, array $params)
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
