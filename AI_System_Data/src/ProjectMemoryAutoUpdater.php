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
        $autoDocs = [
            'agents' => self::buildAgentsDoc($snapshot),
            'readme' => self::buildReadmeDoc($snapshot),
            'todo' => self::buildTodoDoc($snapshot),
        ];

        ProjectContextMemory::saveAuto($pdo, $projectId, $autoDocs);
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

        $threadHistory = [];
        if ($threadId !== null) {
            $threadHistory = self::fetchAll(
                $pdo,
                "SELECT id, thread_id, user_id, role, message, created_at
                   FROM chat_history
                  WHERE project_id = ?
                    AND thread_id = ?
                  ORDER BY created_at DESC
                  LIMIT 24",
                [$projectId, $threadId]
            );
        }

        $projectRecentHistory = self::fetchAll(
            $pdo,
            "SELECT id, thread_id, user_id, role, message, created_at
               FROM chat_history
              WHERE project_id = ?
              ORDER BY created_at DESC
              LIMIT 36",
            [$projectId]
        );

        $history = self::mergeHistoryRows($threadHistory, $projectRecentHistory, 36);
        $recentEvaluations = self::fetchRecentEvaluatedAnswers($pdo, $projectId, $threadId);

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
            'recent_evaluations' => $recentEvaluations,
            'thread_history_count' => count($threadHistory),
            'project_recent_history_count' => count($projectRecentHistory),
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
        $lines[] = '- 参照履歴: 現在スレッド ' . (int)($snapshot['thread_history_count'] ?? 0) . '件 / 案件全体の最近履歴 ' . (int)($snapshot['project_recent_history_count'] ?? 0) . '件';
        $improvementLines = self::buildImprovementLogLines($snapshot['recent_evaluations'] ?? []);
        if ($improvementLines !== []) {
            $lines[] = '';
            $lines[] = '## 改善ログ';
            foreach ($improvementLines as $line) {
                $lines[] = $line;
            }
        }

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
        $lines[] = '- メモ生成に使った履歴件数: ' . count($snapshot['history']) . '件';
        $lines[] = '- うち現在スレッド由来: ' . (int)($snapshot['thread_history_count'] ?? 0) . '件';
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

    private static function mergeHistoryRows(array $threadHistory, array $projectRecentHistory, int $limit): array
    {
        $merged = [];
        $seen = [];

        foreach ([$threadHistory, $projectRecentHistory] as $sourceRows) {
            foreach ($sourceRows as $row) {
                $id = (int)($row['id'] ?? 0);
                if ($id > 0) {
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                }
                $merged[] = $row;
            }
        }

        usort($merged, static function (array $a, array $b): int {
            return strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? ''));
        });

        if (count($merged) <= $limit) {
            return $merged;
        }

        return array_slice($merged, -$limit);
    }

    private static function fetchRecentEvaluatedAnswers(PDO $pdo, int $projectId, ?int $threadId): array
    {
        $sql = "
            SELECT
                h.id,
                h.project_id,
                h.thread_id,
                h.message AS answer_message,
                h.created_at,
                e.total_score,
                e.relevance_score,
                e.faithfulness_score,
                e.clarity_score,
                e.feedback,
                (
                    SELECT hu.message
                      FROM chat_history hu
                     WHERE hu.project_id = h.project_id
                       AND hu.role = 'user'
                       AND hu.created_at <= h.created_at
                       AND (h.thread_id IS NULL OR hu.thread_id = h.thread_id)
                     ORDER BY hu.created_at DESC, hu.id DESC
                     LIMIT 1
                ) AS question_message
            FROM chat_history h
            INNER JOIN chat_evaluations e ON e.chat_id = h.id
            WHERE h.project_id = ?
              AND h.role = 'assistant'
        ";
        $params = [$projectId];
        if ($threadId !== null) {
            $sql .= " AND h.thread_id = ? ";
            $params[] = $threadId;
        }
        $sql .= " ORDER BY h.created_at DESC, h.id DESC LIMIT 12";

        return self::fetchAll($pdo, $sql, $params);
    }

    private static function buildImprovementLogLines(array $recentEvaluations): array
    {
        $grouped = [];

        foreach ($recentEvaluations as $row) {
            $totalScore = (int)($row['total_score'] ?? 0);
            $relevance = (int)($row['relevance_score'] ?? 0);
            $faithfulness = (int)($row['faithfulness_score'] ?? 0);
            $question = self::compactLine((string)($row['question_message'] ?? ''), 90);
            $feedback = self::compactLine((string)($row['feedback'] ?? ''), 120);

            if ($question === '') {
                continue;
            }
            if ($totalScore >= 92 && $relevance >= 90 && $faithfulness >= 90) {
                continue;
            }

            [$issue, $improvement, $nextRule, $signature] = self::inferImprovementFromEvaluation(
                $question,
                (string)($row['answer_message'] ?? ''),
                (string)($row['feedback'] ?? ''),
                $totalScore,
                $relevance,
                $faithfulness
            );
            if ($signature === '') {
                continue;
            }

            $severity = self::calculateImprovementSeverity($totalScore, $relevance, $faithfulness);
            if (!isset($grouped[$signature])) {
                $grouped[$signature] = [
                    'issue' => $issue,
                    'improvement' => $improvement,
                    'next_rule' => $nextRule,
                    'question' => $question,
                    'feedback' => $feedback,
                    'total_score' => $totalScore,
                    'relevance' => $relevance,
                    'faithfulness' => $faithfulness,
                    'severity' => $severity,
                    'latest_created_at' => (string)($row['created_at'] ?? ''),
                    'occurrences' => 1,
                ];
                continue;
            }

            $grouped[$signature]['occurrences'] = (int)$grouped[$signature]['occurrences'] + 1;
            if (
                $severity > (int)$grouped[$signature]['severity']
                || (
                    $severity === (int)$grouped[$signature]['severity']
                    && strcmp((string)($row['created_at'] ?? ''), (string)$grouped[$signature]['latest_created_at']) > 0
                )
            ) {
                $grouped[$signature]['issue'] = $issue;
                $grouped[$signature]['improvement'] = $improvement;
                $grouped[$signature]['next_rule'] = $nextRule;
                $grouped[$signature]['question'] = $question;
                $grouped[$signature]['feedback'] = $feedback;
                $grouped[$signature]['total_score'] = $totalScore;
                $grouped[$signature]['relevance'] = $relevance;
                $grouped[$signature]['faithfulness'] = $faithfulness;
                $grouped[$signature]['severity'] = $severity;
                $grouped[$signature]['latest_created_at'] = (string)($row['created_at'] ?? '');
            }
        }

        if (empty($grouped)) {
            return [];
        }

        uasort($grouped, static function (array $a, array $b): int {
            $severityCompare = ((int)$b['severity'] <=> (int)$a['severity']);
            if ($severityCompare !== 0) {
                return $severityCompare;
            }
            return strcmp((string)$b['latest_created_at'], (string)$a['latest_created_at']);
        });

        $lines = [];
        $count = 0;
        foreach ($grouped as $item) {
            $lines[] = '- 症状: ' . (string)$item['issue'];
            $lines[] = '  質問: ' . (string)$item['question'];
            $lines[] = '  評価: score=' . (int)$item['total_score'] . ' / relevance=' . (int)$item['relevance'] . ' / faithfulness=' . (int)$item['faithfulness'];
            if ((int)$item['occurrences'] > 1) {
                $lines[] = '  発生: 直近で ' . (int)$item['occurrences'] . '件';
            }
            if ((string)$item['feedback'] !== '') {
                $lines[] = '  補足: ' . (string)$item['feedback'];
            }
            $lines[] = '  改善策: ' . (string)$item['improvement'];
            $lines[] = '  次回ルール: ' . (string)$item['next_rule'];

            $count++;
            if ($count >= 3) {
                break;
            }
        }

        return $lines;
    }

    private static function inferImprovementFromEvaluation(
        string $question,
        string $answer,
        string $feedback,
        int $totalScore,
        int $relevance,
        int $faithfulness
    ): array {
        $text = mb_strtolower($question . "\n" . $answer . "\n" . $feedback);

        if (preg_match('/(csv|\.csv|列|カラム|グラフ|件数|統合|転記|結合|マージ)/u', $text)) {
            if (preg_match('/(グラフ|チャート)/u', $text) && !preg_match('/「.+」列|列ごと|カラム/u', $question)) {
                return [
                    'CSV質問で対象列が曖昧なまま概況寄りの回答になった',
                    'グラフや件数分布は、対象列が未指定なら確認質問を返してから集計する。',
                    'CSVグラフ要求では、対象ファイルと対象列の両方が確定するまで分析を始めない。',
                    'csv_graph_clarify',
                ];
            }

            if (preg_match('/(統合|転記|結合|マージ)/u', $text)) {
                return [
                    'CSV操作相談を分析質問として扱ってしまった',
                    '操作案内と分析を分離し、統合・転記・結合はまず手順案内や前提確認として返す。',
                    'CSVファイル名に言及していても、操作相談なら analysis へ強制遷移しない。',
                    'csv_operation_guidance',
                ];
            }

            return [
                'CSV質問で意図解釈が浅く、要点の切り分けが弱かった',
                'CSV質問では、対象ファイル・列・操作種別を先に固定してから回答する。',
                'CSVは「要約」「集計」「グラフ」「統合」のどれかを最初に見極める。',
                'csv_intent_scope',
            ];
        }

        if (preg_match('/(pdf|資料|ページ|図表|画像|markdown|md)/u', $text)) {
            return [
                '資料/PDF質問で全体要約や薄い説明に寄り、本文根拠が弱かった',
                '資料全体要約より本文ページの根拠を優先し、薄い image_description は回答本文へ入れない。',
                '資料質問では、本文ページ・ページ番号・具体記述を先に使い、全体要約は補助にとどめる。',
                'pdf_detail_first',
            ];
        }

        if ($relevance < 90) {
            return [
                '質問意図に対して答えの焦点がずれた',
                '曖昧な要求は確認質問へ倒し、答えられる前提がそろってから本文回答する。',
                '意図が曖昧なら、推測回答より確認質問を優先する。',
                'general_clarify_first',
            ];
        }

        if ($faithfulness < 90) {
            return [
                '根拠に対する忠実性が弱かった',
                '資料本文・DB実データ・確定済み設定だけを根拠にし、補助メモや推測を断定に使わない。',
                '断定前に、根拠が資料本文か実データかを必ず確認する。',
                'grounding_stricter',
            ];
        }

        if ($totalScore >= 88 && trim($feedback) === '') {
            return ['', '', '', ''];
        }

        return [
            '最近の回答で品質スコアが伸びなかった',
            '回答前に対象・根拠・次アクションを明示して、ぼんやりした要約を避ける。',
            '低スコア回答では、結論・根拠・次アクションの三点を明示する。',
            'generic_low_score',
        ];
    }

    private static function calculateImprovementSeverity(int $totalScore, int $relevance, int $faithfulness): int
    {
        $severity = max(0, 100 - $totalScore);
        $severity += max(0, 95 - $relevance);
        $severity += max(0, 95 - $faithfulness);
        return $severity;
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
