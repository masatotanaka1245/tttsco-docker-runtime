<?php

require_once __DIR__ . '/ProjectContextMemory.php';
require_once __DIR__ . '/ProjectTaskStateReducer.php';

final class ProjectMemoryAutoUpdater
{
    public static function shouldRefreshFromEvaluation(?array $evalResult, string $finalResponse = ''): bool
    {
        $skipReason = self::detectAutoRefreshSkipReason($evalResult, $finalResponse);
        if ($skipReason !== null) {
            self::logAutoRefreshSkip($skipReason, $evalResult, $finalResponse);
            return false;
        }

        if (empty($evalResult)) {
            return true;
        }

        if (array_key_exists('allow_memory_refresh', $evalResult)) {
            $allowed = (bool)$evalResult['allow_memory_refresh'];
            if (!$allowed) {
                self::logAutoRefreshSkip('allow_memory_refresh_false', $evalResult, $finalResponse);
            }
            return $allowed;
        }

        if ((int)($evalResult['total_score'] ?? 0) < 85) {
            self::logAutoRefreshSkip('score_below_threshold', $evalResult, $finalResponse);
            return false;
        }

        return true;
    }

    public static function refresh(PDO $pdo, int $projectId, ?int $threadId, int $userId, ?callable $logger = null, array $context = []): array
    {
        if ($projectId <= 0) {
            return ProjectContextMemory::load($pdo, $projectId);
        }

        $beforeDocs = ProjectContextMemory::load($pdo, $projectId);
        $intentSkipMeta = self::resolveConversationIntentSkipMeta((array)($context['conversation_intent_profile'] ?? []));
        if ($intentSkipMeta !== null) {
            self::logConversationIntentSkip($logger, $intentSkipMeta, $threadId, (string)($context['route_detail'] ?? ''));
            return $beforeDocs;
        }

        $snapshot = self::collectSnapshot($pdo, $projectId, $threadId, $userId);
        $todoState = self::resolveTodoState($snapshot, (array)($context['decomposed_tasks'] ?? []), $logger);
        $autoDocs = [
            'agents' => self::buildAgentsDoc($snapshot, $todoState),
            'readme' => self::buildReadmeDoc($snapshot),
            'todo' => self::buildTodoDoc($snapshot, $todoState),
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
                . ' | todo_source=' . (($todoState['meta']['source'] ?? 'history_snapshot'))
            );
            $logger('[PROJECT-MEMORY-AUTO] diff | ' . self::buildAutoRefreshDiffSummary($beforeDocs, $loadedDocs));
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

    private static function detectAutoRefreshSkipReason(?array $evalResult, string $finalResponse): ?string
    {
        if (!empty($evalResult)) {
            $evaluationSource = (string)($evalResult['evaluation_source'] ?? '');
            $evaluationMode = (string)($evalResult['evaluation_mode'] ?? '');
            $verdict = trim((string)($evalResult['verdict'] ?? ''));
            $feedback = self::normalizeAutoRefreshText((string)($evalResult['feedback'] ?? ''));

            if ($evaluationSource === 'judge_fallback' || $evaluationMode === 'fallback') {
                return 'evaluation_fallback';
            }

            if ($verdict !== '' && $verdict !== 'pass') {
                return 'verdict_not_pass';
            }

            if (self::hasMismatchPair($evalResult)) {
                return 'mismatch_pair';
            }

            $markerReason = self::detectFeedbackMarkerReason($feedback);
            if ($markerReason !== null) {
                return $markerReason;
            }
        }

        return self::detectAutoRefreshSkipReasonFromText($finalResponse);
    }

    private static function detectFeedbackMarkerReason(string $feedback): ?string
    {
        if ($feedback === '') {
            return null;
        }

        $markers = [
            '[ASK-USER-CLARIFICATION]' => 'clarification_marker',
            '[TEXT-ONLY-REWRITE]' => 'rewrite_marker',
            '[FINAL-GUARD-OPERATION-NOTICE]' => 'operation_notice_marker',
            '[FINAL-GUARD-INSUFFICIENT-EVIDENCE]' => 'insufficient_evidence_marker',
            '[FINAL-GUARD-INTENT-DRIFT]' => 'intent_drift_marker',
        ];

        foreach ($markers as $marker => $reason) {
            if (str_contains($feedback, $marker)) {
                return $reason;
            }
        }

        return null;
    }

    private static function detectAutoRefreshSkipReasonFromText(string $text): ?string
    {
        $normalized = self::normalizeAutoRefreshText($text);
        if ($normalized === '') {
            return 'empty_response';
        }

        if (self::looksLikeIncompleteOrUnsafeAnswer($normalized)) {
            return 'unsafe_answer';
        }

        if (self::looksLikeClarificationHeavyAnswer($normalized)) {
            return 'clarification_text';
        }

        if (self::looksLikeOperationOnlyAnswer($normalized)) {
            return 'operation_notice_text';
        }

        if (self::looksLikeInsufficientEvidenceAnswer($normalized)) {
            return 'insufficient_evidence_text';
        }

        if (self::looksLikeErrorNoticeAnswer($normalized)) {
            return 'error_notice_text';
        }

        if (self::looksLikeCompletionNoticeOnlyAnswer($normalized)) {
            return 'completion_notice_text';
        }

        return null;
    }

    private static function hasMismatchPair(array $evalResult): bool
    {
        $mismatchPair = $evalResult['mismatch_pair'] ?? null;
        if (!is_array($mismatchPair) || count($mismatchPair) !== 2) {
            return false;
        }

        $expected = trim((string)($mismatchPair[0] ?? ''));
        $actual = trim((string)($mismatchPair[1] ?? ''));
        return $expected !== '' && $actual !== '';
    }

    private static function looksLikeClarificationHeavyAnswer(string $text): bool
    {
        $hasClarificationMarkers = preg_match(
            '/(確認させてください|指定してください|追加情報|追加の情報|もう少し詳しく|教えてください|補足してください|対象を教えてください|どの(?:列|カラム|項目|資料)|何を対象にするか)/u',
            $text
        ) === 1;

        return $hasClarificationMarkers && !self::containsSubstantiveAnswerMarkers($text);
    }

    private static function looksLikeOperationOnlyAnswer(string $text): bool
    {
        if (
            preg_match('/(アップロードしてください|クリックしてください|画面で確認してください|保存してください|CSVを選択してください|ファイルを選択してください|ボタンを押してください|タブを開いてください|モーダルを開いてください|ダウンロードしてください)/u', $text) !== 1
        ) {
            return false;
        }

        return !self::containsSubstantiveAnswerMarkers($text);
    }

    private static function looksLikeInsufficientEvidenceAnswer(string $text): bool
    {
        $hasInsufficientMarkers = preg_match(
            '/(情報がありません|判断できません|見つかりません|根拠が不足しています|根拠不足です|根拠不足|追加情報が必要です|情報が不足しています|条件が不足しています|必要な情報.*不足)/u',
            $text
        ) === 1;

        return $hasInsufficientMarkers && !self::containsSubstantiveAnswerMarkers($text);
    }

    private static function looksLikeErrorNoticeAnswer(string $text): bool
    {
        $hasErrorMarkers = preg_match(
            '/(通信エラー|内部サーバーエラー|AIサーバー通信エラー|回答の生成に失敗|Token Limit|セッションが切れました|エラーが発生しました|処理に失敗しました)/u',
            $text
        ) === 1;

        return $hasErrorMarkers && !self::containsSubstantiveAnswerMarkers($text);
    }

    private static function looksLikeCompletionNoticeOnlyAnswer(string $text): bool
    {
        $isShort = mb_strlen($text) <= 220;
        $hasCompletionMarkers = preg_match(
            '/((報告書|レポート|PDF|CSV|資料メモ|markdown|md).*(作成|生成|出力|保存|登録|反映).*(完了|しました))|(((作成|生成|出力|保存|登録|反映).*(完了|しました)).*(報告書|レポート|PDF|CSV|資料メモ|markdown|md))/iu',
            $text
        ) === 1;

        return $isShort && $hasCompletionMarkers && !self::containsSubstantiveAnswerMarkers($text);
    }

    private static function containsSubstantiveAnswerMarkers(string $text): bool
    {
        return preg_match(
            '/(結論|理由|根拠|概要|要約|分析|提案|方針|進行中タスク|主成果品|レーン|主対象|次に|優先|整理すると|ポイント|追記|章立て|見出し|構成案|件数|集計結果|推奨アクション)/u',
            $text
        ) === 1;
    }

    private static function normalizeAutoRefreshText(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }

    private static function logAutoRefreshSkip(string $reason, ?array $evalResult, string $finalResponse): void
    {
        $verdict = trim((string)($evalResult['verdict'] ?? ''));
        $evaluationMode = trim((string)($evalResult['evaluation_mode'] ?? ''));
        $evaluationSource = trim((string)($evalResult['evaluation_source'] ?? ''));
        $score = (int)($evalResult['total_score'] ?? 0);
        $responsePreview = self::compactLine($finalResponse, 120);

        error_log(
            '[PROJECT-MEMORY-AUTO] skipped=quality_guard'
            . ' | reason=' . $reason
            . ' | verdict=' . ($verdict !== '' ? $verdict : 'none')
            . ' | evaluation_mode=' . ($evaluationMode !== '' ? $evaluationMode : 'none')
            . ' | evaluation_source=' . ($evaluationSource !== '' ? $evaluationSource : 'none')
            . ' | score=' . $score
            . ' | response=' . ($responsePreview !== '' ? $responsePreview : '(empty)')
        );
    }

    private static function buildAutoRefreshDiffSummary(array $beforeDocs, array $afterDocs): string
    {
        $beforeAutoDocs = self::extractAutoDocContents($beforeDocs);
        $afterAutoDocs = self::extractAutoDocContents($afterDocs);
        $changed = [];

        foreach (ProjectContextMemory::AUTO_META_KEYS as $type => $_metaKey) {
            $before = trim((string)($beforeAutoDocs[$type] ?? ''));
            $after = trim((string)($afterAutoDocs[$type] ?? ''));
            if ($before !== $after) {
                $changed[] = $type;
            }
        }

        $beforeHighlights = self::extractAutoRefreshHighlights($beforeAutoDocs);
        $afterHighlights = self::extractAutoRefreshHighlights($afterAutoDocs);

        return implode(' | ', [
            'changed=' . ($changed === [] ? 'none' : implode(',', $changed)),
            'lane_before=' . self::formatDiffLogValue($beforeHighlights['lane'] ?? ''),
            'lane_after=' . self::formatDiffLogValue($afterHighlights['lane'] ?? ''),
            'target_before=' . self::formatDiffLogValue($beforeHighlights['target'] ?? ''),
            'target_after=' . self::formatDiffLogValue($afterHighlights['target'] ?? ''),
            'task_before=' . self::formatDiffLogValue($beforeHighlights['current_task'] ?? ''),
            'task_after=' . self::formatDiffLogValue($afterHighlights['current_task'] ?? ''),
            'next_before=' . self::formatDiffLogValue($beforeHighlights['next_action'] ?? ''),
            'next_after=' . self::formatDiffLogValue($afterHighlights['next_action'] ?? ''),
            'chars_before=' . self::countDocChars($beforeAutoDocs),
            'chars_after=' . self::countDocChars($afterAutoDocs),
        ]);
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>|null
     */
    private static function resolveConversationIntentSkipMeta(array $profile): ?array
    {
        if ($profile === []) {
            return null;
        }

        $conversationRelation = trim((string)($profile['conversation_relation'] ?? 'unknown'));
        $requestType = trim((string)($profile['request_type'] ?? 'unknown'));
        $todoPolicyHint = trim((string)($profile['todo_policy_hint'] ?? 'unknown'));
        $needsTodo = (bool)($profile['needs_todo'] ?? false);

        if (in_array($conversationRelation, ['status_check', 'rollback', 'correction'], true)) {
            return [
                'reason' => 'conversation_intent_' . $conversationRelation,
                'relation' => $conversationRelation,
                'request_type' => $requestType,
                'todo_policy_hint' => $todoPolicyHint,
                'needs_todo' => $needsTodo,
            ];
        }

        if ($requestType === 'consultation') {
            return [
                'reason' => 'conversation_intent_consultation',
                'relation' => $conversationRelation,
                'request_type' => $requestType,
                'todo_policy_hint' => $todoPolicyHint,
                'needs_todo' => $needsTodo,
            ];
        }

        if ($conversationRelation === 'follow_up' && $todoPolicyHint === 'read_only') {
            return [
                'reason' => 'conversation_intent_follow_up_read_only',
                'relation' => $conversationRelation,
                'request_type' => $requestType,
                'todo_policy_hint' => $todoPolicyHint,
                'needs_todo' => $needsTodo,
            ];
        }

        if ($todoPolicyHint === 'read_only') {
            return [
                'reason' => 'conversation_intent_read_only',
                'relation' => $conversationRelation,
                'request_type' => $requestType,
                'todo_policy_hint' => $todoPolicyHint,
                'needs_todo' => $needsTodo,
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $skipMeta
     */
    private static function logConversationIntentSkip(?callable $logger, array $skipMeta, ?int $threadId, string $routeDetail): void
    {
        $message =
            '[PROJECT-MEMORY-AUTO-SKIP] reason=' . (string)($skipMeta['reason'] ?? 'conversation_intent_skip')
            . ' | relation=' . (string)($skipMeta['relation'] ?? 'unknown')
            . ' | request_type=' . (string)($skipMeta['request_type'] ?? 'unknown')
            . ' | todo_policy_hint=' . (string)($skipMeta['todo_policy_hint'] ?? 'unknown')
            . ' | needs_todo=' . ((bool)($skipMeta['needs_todo'] ?? false) ? '1' : '0')
            . ' | thread_id=' . ($threadId === null ? 'NULL' : (string)$threadId)
            . ' | route_detail=' . ($routeDetail !== '' ? $routeDetail : 'unknown');

        if ($logger !== null) {
            $logger($message);
            return;
        }

        error_log($message);
    }

    private static function extractAutoDocContents(array $docs): array
    {
        $contents = [];
        foreach (ProjectContextMemory::AUTO_META_KEYS as $type => $_metaKey) {
            $doc = $docs[$type] ?? '';
            if (is_array($doc)) {
                $contents[$type] = trim((string)($doc['auto_content'] ?? ''));
                continue;
            }
            $contents[$type] = trim((string)$doc);
        }

        return $contents;
    }

    private static function extractAutoRefreshHighlights(array $autoDocs): array
    {
        $todoText = trim((string)($autoDocs['todo'] ?? ''));
        $agentsText = trim((string)($autoDocs['agents'] ?? ''));

        $lane = self::extractMarkdownLineValue($todoText, ['レーン', '現在の主成果品']);
        if ($lane === '') {
            $lane = self::extractMarkdownLineValue($agentsText, ['レーン', '現在の主成果品']);
        }

        $target = self::extractMarkdownLineValue($todoText, ['主対象']);
        if ($target === '') {
            $target = self::extractMarkdownLineValue($agentsText, ['主対象']);
        }

        $currentTask = self::extractMarkdownLineValue($todoText, ['進行中タスク']);
        if ($currentTask === '') {
            $currentTask = self::extractFirstTaskInSection($todoText, '進行中');
        }
        if ($currentTask === '') {
            $currentTask = self::extractMarkdownLineValue($agentsText, ['進行中タスク']);
        }

        $nextAction = self::extractFirstTaskInSection($todoText, '未着手');
        if ($nextAction === '' || str_contains($nextAction, '追加タスクはまだ抽出されていません')) {
            $nextAction = '';
        }
        if ($nextAction === '') {
            $nextAction = $currentTask;
        }

        return [
            'lane' => $lane,
            'target' => $target,
            'current_task' => $currentTask,
            'next_action' => $nextAction,
        ];
    }

    private static function extractMarkdownLineValue(string $text, array $labels): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        foreach ($labels as $label) {
            if (preg_match('/^- ' . preg_quote($label, '/') . ':\s*(.+)$/mu', $text, $matches) === 1) {
                return self::compactLine(trim((string)($matches[1] ?? '')), 80);
            }
        }

        return '';
    }

    private static function extractFirstTaskInSection(string $text, string $sectionTitle): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $pattern = '/^##\s*' . preg_quote($sectionTitle, '/') . '\s*$\R(?P<body>.*?)(?=^\#\#\s|\z)/msu';
        if (preg_match($pattern, $text, $matches) !== 1) {
            return '';
        }

        $body = (string)($matches['body'] ?? '');
        $lines = preg_split('/\R/u', $body) ?: [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^- \[(?:進行中|未着手|検証中|完了|保留)\]\s*(.+)$/u', $line, $taskMatches) === 1) {
                return self::compactLine(trim((string)($taskMatches[1] ?? '')), 80);
            }
            if (preg_match('/^- (.+)$/u', $line, $taskMatches) === 1) {
                return self::compactLine(trim((string)($taskMatches[1] ?? '')), 80);
            }
        }

        return '';
    }

    private static function countDocChars(array $docs): int
    {
        $chars = 0;
        foreach ($docs as $doc) {
            $chars += mb_strlen(trim((string)$doc));
        }

        return $chars;
    }

    private static function formatDiffLogValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'none';
        }

        return self::compactLine($value, 80);
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

        $materialDocs = self::fetchAll(
            $pdo,
            "SELECT id, title, file_path, created_at
               FROM documents
              WHERE project_id = ?
                AND LOWER(file_path) LIKE '%.md'
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
        $recentComments = self::fetchAll(
            $pdo,
            "SELECT comment_text, created_at
               FROM project_comments
              WHERE project_id = ?
              ORDER BY created_at DESC, id DESC
              LIMIT 5",
            [$projectId]
        );
        $faqCount = (int)self::fetchScalar(
            $pdo,
            "SELECT COUNT(*) FROM project_faqs WHERE project_id = ?",
            [$projectId]
        );
        $recentFaqs = self::fetchAll(
            $pdo,
            "SELECT question_summary, answer_summary, created_at
               FROM project_faqs
              WHERE project_id = ?
              ORDER BY created_at DESC, id DESC
              LIMIT 5",
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
            'material_docs' => $materialDocs,
            'primary_csv_file' => self::selectPrimaryCsvFile($csvFiles),
            'primary_pdf_doc' => self::selectPrimaryDocument($pdfDocs),
            'primary_material_doc' => self::selectPrimaryDocument($materialDocs),
            'history' => $history,
            'recent_evaluations' => $recentEvaluations,
            'thread_history_count' => count($threadHistory),
            'project_recent_history_count' => count($projectRecentHistory),
            'comment_count' => $commentCount,
            'recent_comments' => $recentComments,
            'faq_count' => $faqCount,
            'recent_faqs' => $recentFaqs,
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    private static function buildAgentsDoc(array $snapshot, ?array $todoStateOverride = null): string
    {
        $topics = self::detectTopics($snapshot['history']);
        $latestRequests = self::extractLatestUserMessages($snapshot['history'], 3);
        $todoState = $todoStateOverride ?? self::buildTodoState($snapshot);
        $activeArtifact = self::resolveActiveArtifactLane($snapshot, $latestRequests);
        $lines = [];
        $lines[] = '# AGENTS';
        $lines[] = '';
        $lines[] = '> 自動更新: ' . $snapshot['generated_at'];
        $lines[] = '> この内容は案件状態と直近会話から自動生成されます。';
        $lines[] = '';
        $lines[] = '## 現在の主成果品';
        $lines[] = '- レーン: ' . $activeArtifact['label'];
        $lines[] = '- 理由: ' . $activeArtifact['reason'];
        if (!empty($todoState['current'])) {
            $lines[] = '- 進行中タスク: ' . $todoState['current'][0];
        }
        $lines[] = '';
        $lines[] = '## 回答方針';
        $lines[] = '- 現在の案件では、`資料md / CSV / PDF / コメント / FAQ / プロジェクト情報 / DB実データ` を、成果品完成のための情報源として横断参照する。';
        $lines[] = '- 情報源ごとの役割を固定しすぎず、質問と成果品の段階に応じて必要なソースを組み合わせる。';
        $lines[] = '- 現在スレッドの文脈を優先し、follow-up や履歴要約は thread 単位で継続する。';
        $lines[] = '- プロジェクトの方針・次アクション・優先順位は、チャット保存のたびに運用メモへ反映し直す。';
        $lines[] = '- TODO に `進行中` がある場合は、その成果品レーンを明示的な方針変更があるまで継続する。';
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
        if (!empty($latestRequests)) {
            $lines[] = '- 直近依頼: ' . $latestRequests[0];
        }
        $sourceHighlights = self::buildSourceHighlightLines($snapshot);
        if ($sourceHighlights !== []) {
            $lines[] = '';
            $lines[] = '## 参照ソースの要点';
            foreach ($sourceHighlights as $line) {
                $lines[] = $line;
            }
        }
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
        $materialDocs = $snapshot['material_docs'];
        $latestRequests = self::extractLatestUserMessages($snapshot['history'], 5);
        $activeArtifact = self::resolveActiveArtifactLane($snapshot, $latestRequests);

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
        $lines[] = '- 資料メモ（Markdown）: ' . count($materialDocs) . '件';
        foreach (array_slice($materialDocs, 0, 5) as $material) {
            $lines[] = '  - ' . (string)($material['title'] ?? basename((string)($material['file_path'] ?? '資料メモ')));
        }
        $lines[] = '- PDF資料: ' . count($pdfDocs) . '件';
        foreach (array_slice($pdfDocs, 0, 5) as $pdf) {
            $lines[] = '  - ' . (string)($pdf['title'] ?? basename((string)($pdf['file_path'] ?? '資料PDF')));
        }
        $lines[] = '- コメント: ' . (int)($snapshot['comment_count'] ?? 0) . '件';
        $lines[] = '- FAQナレッジ: ' . (int)($snapshot['faq_count'] ?? 0) . '件';
        $lines[] = '';
        $lines[] = '## 現在の主レーン';
        $lines[] = '- ' . $activeArtifact['label'] . ': ' . $activeArtifact['reason'];
        $lines[] = '';
        $lines[] = '## 最近の情報源';
        foreach (self::buildSourceHighlightLines($snapshot) as $line) {
            $lines[] = $line;
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
        $lines[] = '- 資料メモ / CSV / PDF / コメント / FAQ / 案件情報は、成果品完成のための並列な情報源として扱う。';
        $lines[] = '- 運用メモは、チャットのやり取りが保存されるたびに、プロジェクトの方針・次アクション・進め方を反映して更新する。';
        $lines[] = '- 履歴要約や履歴報告書化は、案件全体ではなく現在スレッド基準で扱う。';

        return implode("\n", $lines);
    }

    private static function buildTodoDoc(array $snapshot, ?array $todoStateOverride = null): string
    {
        $todoState = $todoStateOverride ?? self::buildTodoState($snapshot);
        $latestRequests = self::extractLatestUserMessages($snapshot['history'], 4);
        $activeArtifact = self::resolveActiveArtifactLane($snapshot, $latestRequests);
        $artifactFocus = self::describeArtifactFocus($snapshot, $activeArtifact);

        $lines = [];
        $lines[] = '# TODO';
        $lines[] = '';
        $lines[] = '> 自動更新: ' . $snapshot['generated_at'];
        $lines[] = '';
        $lines[] = '## 現在の主成果品';
        $lines[] = '- レーン: ' . $activeArtifact['label'];
        $lines[] = '- 主対象: ' . $artifactFocus;
        $lines[] = '- 理由: ' . $activeArtifact['reason'];
        $lines[] = '- 進行中タスク: ' . $todoState['current'][0];
        $lines[] = '';
        $lines[] = '## 進行中';
        foreach ($todoState['current'] as $task) {
            $lines[] = '- [進行中] ' . $task;
        }
        $lines[] = '';
        $lines[] = '## 未着手';
        if (empty($todoState['pending'])) {
            $lines[] = '- [未着手] 追加タスクはまだ抽出されていません。';
        } else {
            foreach ($todoState['pending'] as $task) {
                $lines[] = '- [未着手] ' . $task;
            }
        }
        $lines[] = '';
        $lines[] = '## 検証中';
        if (empty($todoState['review'])) {
            $lines[] = '- [検証中] 直前の成果品が更新されたら、内容確認と差分修正へ進む。';
        } else {
            foreach ($todoState['review'] as $task) {
                $lines[] = '- [検証中] ' . $task;
            }
        }
        $lines[] = '';
        $lines[] = '## 完了';
        if (empty($todoState['done'])) {
            $lines[] = '- [完了] まだ自動判定できる完了項目はありません。';
        } else {
            foreach ($todoState['done'] as $task) {
                $lines[] = '- [完了] ' . $task;
            }
        }
        $lines[] = '';
        $lines[] = '## 保留';
        if (empty($todoState['blocked'])) {
            $lines[] = '- [保留] 現時点で大きな保留事項はありません。';
        } else {
            foreach ($todoState['blocked'] as $task) {
                $lines[] = '- [保留] ' . $task;
            }
        }
        $lines[] = '';
        $lines[] = '## 補足';
        $lines[] = '- 進行中は常に1件だけ自動選定する。';
        $lines[] = '- メモ生成に使った履歴件数: ' . count($snapshot['history']) . '件';
        $lines[] = '- うち現在スレッド由来: ' . (int)($snapshot['thread_history_count'] ?? 0) . '件';
        $lines[] = '- このTODOは自動生成のため、次回の会話保存時に更新される';

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array<string, mixed>> $decomposedTasks
     */
    private static function resolveTodoState(array $snapshot, array $decomposedTasks, ?callable $logger = null): array
    {
        if ($decomposedTasks !== []) {
            $reducedState = ProjectTaskStateReducer::reduce($decomposedTasks, $logger);
            if ($reducedState !== null) {
                if ($logger !== null) {
                    $logger(
                        '[PROJECT-TASK-REDUCER] source=decomposition'
                        . ' | current=' . count((array)($reducedState['current'] ?? []))
                        . ' | pending=' . count((array)($reducedState['pending'] ?? []))
                        . ' | skipped=' . (int)($reducedState['meta']['skipped_count'] ?? 0)
                    );
                }
                return $reducedState;
            }

            if ($logger !== null) {
                $logger('[PROJECT-TASK-REDUCER] source=decomposition | current=0 | pending=0 | skipped=' . count($decomposedTasks));
                $logger(
                    '[PROJECT-MEMORY-AUTO] skipped=task_guard'
                    . ' | reason=no_save_worthy_tasks'
                    . ' | source=decomposition'
                    . ' | fallback=history_snapshot'
                    . ' | task_count=' . count($decomposedTasks)
                );
            }
        }

        $fallbackState = self::buildTodoState($snapshot);
        $fallbackState['meta'] = ['source' => 'history_snapshot'];
        return $fallbackState;
    }

    private static function detectTopics(array $history): array
    {
        $topicPatterns = [
            '資料メモ・Markdown編集' => '/資料メモ|markdown|md|追記|修正|見出し|章立て|ドラフト/u',
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
            $message = trim((string)($row['message'] ?? ''));
            if ($message === '' || self::looksLikeLowSignalUserMessage($message)) {
                continue;
            }
            $messages[] = self::compactLine($message, 120);
            if (count($messages) >= $limit) {
                break;
            }
        }
        return $messages;
    }

    private static function extractLatestAssistantMessages(array $history, int $limit): array
    {
        $messages = [];
        foreach (array_reverse($history) as $row) {
            if (($row['role'] ?? '') !== 'assistant') {
                continue;
            }
            $messages[] = self::compactLine((string)($row['message'] ?? ''), 160);
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
            $createdAtCompare = strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? ''));
            if ($createdAtCompare !== 0) {
                return $createdAtCompare;
            }

            return ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
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

    private static function buildTodoState(array $snapshot): array
    {
        $latestRequests = self::extractLatestUserMessages($snapshot['history'], 6);
        $activeArtifact = self::resolveActiveArtifactLane($snapshot, $latestRequests);
        $todoSignals = self::buildTodoSignals($snapshot['history'], (array)($snapshot['recent_evaluations'] ?? []));
        $currentTask = self::buildCurrentTodoTask($snapshot, $activeArtifact, $todoSignals);

        $pending = [];
        foreach ($todoSignals['open_interactions'] as $interaction) {
            $pending[] = self::normalizeTodoTask((string)($interaction['user_message'] ?? ''));
            if (count($pending) >= 3) {
                break;
            }
        }
        foreach (self::buildRecommendedPendingTasks($snapshot, $activeArtifact) as $task) {
            if (count($pending) >= 4) {
                break;
            }
            $pending[] = $task;
        }

        return [
            'current' => [$currentTask],
            'pending' => self::uniqueNonEmptyLines($pending, [$currentTask]),
            'review' => self::buildReviewTasks($snapshot, $activeArtifact, $todoSignals),
            'done' => self::buildCompletedTasks($snapshot, $todoSignals),
            'blocked' => self::buildBlockedTasks($snapshot, $activeArtifact, $todoSignals),
        ];
    }

    private static function resolveActiveArtifactLane(array $snapshot, array $latestRequests): array
    {
        $latestText = trim((string)($latestRequests[0] ?? ''));
        $materialDocs = $snapshot['material_docs'] ?? [];
        $csvFiles = $snapshot['csv_files'] ?? [];
        $pdfDocs = $snapshot['pdf_docs'] ?? [];

        if ($latestText !== '') {
            if (preg_match('/資料メモ|markdown|md|追記|修正|見出し|章立て|ドラフト|たたき台|資料に追加/u', $latestText)) {
                return ['key' => 'material_note', 'label' => '資料メモ', 'reason' => '直近依頼が資料メモの作成・追記・修正を示している。'];
            }
            if (preg_match('/todo|タスク|進捗|ステータス|aiエージェント|運用メモ|方針/u', mb_strtolower($latestText))) {
                return ['key' => 'operating_memory', 'label' => '運用メモ', 'reason' => '直近依頼が案件運用メモやタスク整理を主題にしている。'];
            }
            if (preg_match('/報告書|レポート|pdf|pdf化/u', $latestText)) {
                return ['key' => 'report', 'label' => '報告書/PDF', 'reason' => '直近依頼が報告書化またはPDF成果品の作成を示している。'];
            }
            if (preg_match('/csv|集計|グラフ|件数|ランキング|列|カラム/u', mb_strtolower($latestText))) {
                return ['key' => 'csv', 'label' => 'CSV分析', 'reason' => '直近依頼がCSV集計やグラフ化を主題にしている。'];
            }
        }

        if (!empty($materialDocs)) {
            return ['key' => 'material_note', 'label' => '資料メモ', 'reason' => '既存のMarkdown資料メモがあり、作業用成果品として継続育成しやすい。'];
        }
        if (!empty($csvFiles) && !empty($pdfDocs)) {
            return ['key' => 'hybrid_analysis', 'label' => 'CSV+PDF照合', 'reason' => 'CSVとPDFが揃っており、複数ソースを照合しながら成果品を進めやすい。'];
        }
        if (!empty($csvFiles)) {
            return ['key' => 'csv', 'label' => 'CSV分析', 'reason' => 'CSVが登録済みで、集計や可視化から着手しやすい。'];
        }
        if (!empty($pdfDocs)) {
            return ['key' => 'pdf', 'label' => 'PDF確認', 'reason' => 'PDFが登録済みで、資料本文や図表を情報源として成果品化を進めやすい。'];
        }

        return ['key' => 'operating_memory', 'label' => '運用メモ', 'reason' => 'まだ主要成果品が少ないため、方針整理から始めるのが安全。'];
    }

    private static function buildCurrentTodoTask(array $snapshot, array $activeArtifact, array $todoSignals): string
    {
        foreach ($todoSignals['open_interactions'] as $interaction) {
            $userMessage = trim((string)($interaction['user_message'] ?? ''));
            if ($userMessage === '' || self::looksLikeLowSignalUserMessage($userMessage)) {
                continue;
            }
            $assistantMessage = trim((string)($interaction['assistant_message'] ?? ''));
            if ($assistantMessage === '' || !self::looksLikeClarificationAssistantAction($assistantMessage)) {
                return self::normalizeTodoTask($userMessage);
            }
        }
        if (!empty($todoSignals['review_interactions'][0])) {
            return self::buildReviewCurrentTask($activeArtifact, $todoSignals['review_interactions'][0]);
        }

        return self::buildDefaultCurrentTask($snapshot, $activeArtifact);
    }

    private static function buildDefaultCurrentTask(array $snapshot, array $activeArtifact): string
    {
        $primaryMaterial = (array)($snapshot['primary_material_doc'] ?? []);
        $primaryCsv = (array)($snapshot['primary_csv_file'] ?? []);
        $primaryPdf = (array)($snapshot['primary_pdf_doc'] ?? []);
        $materialLabel = self::describeDocumentName($primaryMaterial, '資料メモ');
        $csvLabel = self::describeCsvName($primaryCsv);
        $pdfLabel = self::describeDocumentName($primaryPdf, 'PDF');

        return match ($activeArtifact['key']) {
            'material_note' => $materialLabel !== ''
                ? '資料メモ「' . $materialLabel . '」を開き、今回の依頼に必要な章・追記ポイントを更新する'
                : '資料メモを新規作成し、今回の成果品の土台を作る',
            'csv' => $csvLabel !== ''
                ? 'CSV「' . $csvLabel . '」を起点に、対象列・集計軸・出力形式を確定する'
                : '対象CSVを1本に絞り、集計軸と出力形式を確定する',
            'report' => $materialLabel !== '' || $pdfLabel !== ''
                ? '資料メモ「' . ($materialLabel !== '' ? $materialLabel : $pdfLabel) . '」を土台に、報告書の見出し構成と根拠を整理する'
                : '報告書の見出し構成と根拠を整理し、成果品ドラフトを組み立てる',
            'hybrid_analysis' => $csvLabel !== '' || $pdfLabel !== ''
                ? 'CSV「' . ($csvLabel !== '' ? $csvLabel : '対象CSV') . '」と資料「' . ($pdfLabel !== '' ? $pdfLabel : ($materialLabel !== '' ? $materialLabel : '関連資料')) . '」を照合し、判断材料をまとめる'
                : 'CSV・PDF・資料メモなど複数ソースを照合し、判断材料をまとめる',
            default => '案件運用メモを更新し、次に作る成果品レーンを1つに絞る',
        };
    }

    private static function buildRecommendedPendingTasks(array $snapshot, array $activeArtifact): array
    {
        $tasks = [];
        $materialDocs = $snapshot['material_docs'] ?? [];
        $csvFiles = $snapshot['csv_files'] ?? [];
        $pdfDocs = $snapshot['pdf_docs'] ?? [];
        $materialLabel = self::describeDocumentName((array)($snapshot['primary_material_doc'] ?? []), '資料メモ');
        $csvLabel = self::describeCsvName((array)($snapshot['primary_csv_file'] ?? []));
        $pdfLabel = self::describeDocumentName((array)($snapshot['primary_pdf_doc'] ?? []), 'PDF');

        if ($activeArtifact['key'] !== 'material_note') {
            if (!empty($materialDocs)) {
                $tasks[] = $materialLabel !== ''
                    ? '資料メモ「' . $materialLabel . '」を確認し、今回の依頼に近い章や見出しを再利用する'
                    : '既存の資料メモを確認し、今回の依頼に近い章や見出しを再利用する';
            } else {
                $tasks[] = '資料メモが未作成なら、先に叩き台Markdownを1本用意する';
            }
        }
        if (!empty($csvFiles)) {
            $tasks[] = $csvLabel !== ''
                ? 'CSV「' . $csvLabel . '」で、件数・分布・時系列のどれを見るかを明示する'
                : 'CSV側で必要な件数・分布・時系列のどれを見るかを明示する';
        }
        if (!empty($pdfDocs)) {
            $tasks[] = $pdfLabel !== ''
                ? 'PDF「' . $pdfLabel . '」から必要な根拠ページ・関連記述を抜き出して、成果品へ反映する'
                : 'PDFや関連資料から必要な根拠ページ・関連記述を抜き出して、成果品へ反映する';
        }
        if ((int)($snapshot['comment_count'] ?? 0) > 0) {
            $tasks[] = '案件コメントに最新の申し送りや方針変更があれば、成果品へ反映する';
        }
        if ((int)($snapshot['faq_count'] ?? 0) > 0) {
            $tasks[] = '既存FAQに再利用できる解決策があれば、今回の成果品へ転用する';
        }
        if (empty($csvFiles) && empty($pdfDocs) && empty($materialDocs)) {
            $tasks[] = '分析対象のCSV / PDF / 資料メモを追加し、成果品の起点を作る';
        }

        return $tasks;
    }

    private static function buildReviewTasks(array $snapshot, array $activeArtifact, array $todoSignals): array
    {
        $tasks = [];
        if (!empty($todoSignals['review_interactions'][0])) {
            $tasks[] = self::buildReviewCurrentTask($activeArtifact, $todoSignals['review_interactions'][0]);
        } elseif (!empty($todoSignals['completed_interactions'][0])) {
            $tasks[] = match ($activeArtifact['key']) {
                'material_note' => '更新した資料メモの章立てと追記内容を確認し、必要なら差分修正する',
                'csv' => '直前の集計結果を確認し、列・並び順・グラフ形式の再指定が必要か確認する',
                'report' => '報告書ドラフトの結論・根拠・出典の並びを確認し、確定前に調整する',
                default => '直前の成果品ドラフトを確認し、追加の差分修正が必要か判断する',
            };
        }

        return self::uniqueNonEmptyLines($tasks);
    }

    private static function buildSourceHighlightLines(array $snapshot): array
    {
        $lines = [];
        $primaryMaterial = (array)($snapshot['primary_material_doc'] ?? []);
        if ($primaryMaterial !== []) {
            $title = self::describeDocumentName($primaryMaterial, '資料メモ');
            if ($title !== '') {
                $dateLabel = self::formatDateLabel((string)($primaryMaterial['created_at'] ?? ''));
                $suffix = $dateLabel !== '' ? ' / 最新: ' . $dateLabel : '';
                $lines[] = '- 資料メモ: ' . $title . $suffix;
            }
        }
        $secondaryMaterials = array_slice((array)($snapshot['material_docs'] ?? []), 1, 2);
        if ($secondaryMaterials !== []) {
            $names = [];
            foreach ($secondaryMaterials as $material) {
                $name = self::describeDocumentName((array)$material, '資料メモ');
                if ($name !== '') {
                    $names[] = $name;
                }
            }
            if ($names !== []) {
                $lines[] = '- 資料メモ候補: ' . implode(' / ', array_map(static fn(string $name): string => self::compactLine($name, 32), $names));
            }
        }
        foreach (array_slice((array)($snapshot['csv_files'] ?? []), 0, 2) as $csv) {
            $name = self::compactLine((string)($csv['file_name'] ?? '名称不明のCSV'), 70);
            if ($name !== '') {
                $lines[] = '- CSV: ' . $name . ' (' . (int)($csv['row_count'] ?? 0) . '行)';
            }
        }
        foreach (array_slice((array)($snapshot['pdf_docs'] ?? []), 0, 1) as $pdf) {
            $title = self::compactLine((string)($pdf['title'] ?? basename((string)($pdf['file_path'] ?? '資料PDF'))), 70);
            if ($title !== '') {
                $lines[] = '- PDF: ' . $title;
            }
        }
        foreach (array_slice((array)($snapshot['recent_comments'] ?? []), 0, 2) as $comment) {
            $text = self::compactLine((string)($comment['comment_text'] ?? ''), 90);
            if ($text !== '') {
                $lines[] = '- コメント: ' . $text;
            }
        }
        foreach (array_slice((array)($snapshot['recent_faqs'] ?? []), 0, 2) as $faq) {
            $question = self::compactLine((string)($faq['question_summary'] ?? ''), 60);
            $answer = self::compactLine((string)($faq['answer_summary'] ?? ''), 80);
            if ($question !== '' || $answer !== '') {
                $lines[] = '- FAQ: ' . trim($question . ($answer !== '' ? ' => ' . $answer : ''));
            }
        }
        if (($snapshot['description'] ?? '') !== '') {
            $lines[] = '- 案件情報: ' . self::compactLine((string)$snapshot['description'], 90);
        }

        return self::uniqueNonEmptyLines($lines);
    }

    private static function buildCompletedTasks(array $snapshot, array $todoSignals): array
    {
        $tasks = [];
        if (!empty($snapshot['material_docs'] ?? [])) {
            $tasks[] = '資料メモの保存基盤は用意済み';
        }
        if (!empty($snapshot['csv_files'] ?? [])) {
            $tasks[] = '分析対象CSVの登録は完了済み';
        }
        if (!empty($snapshot['pdf_docs'] ?? [])) {
            $tasks[] = '参照用PDFの登録は完了済み';
        }
        foreach (array_slice($todoSignals['completed_interactions'], 0, 3) as $interaction) {
            $task = self::normalizeTodoTask((string)($interaction['user_message'] ?? ''));
            if ($task !== '') {
                $tasks[] = '対応済み: ' . $task;
            }
        }

        return self::uniqueNonEmptyLines($tasks);
    }

    private static function buildBlockedTasks(array $snapshot, array $activeArtifact, array $todoSignals): array
    {
        $materialDocs = $snapshot['material_docs'] ?? [];
        $csvFiles = $snapshot['csv_files'] ?? [];
        $pdfDocs = $snapshot['pdf_docs'] ?? [];
        $tasks = [];

        if ($activeArtifact['key'] === 'material_note' && empty($materialDocs)) {
            $tasks[] = '資料メモを主レーンにしたいが、まだMarkdown成果品が存在しない';
        }
        if (empty($materialDocs) && empty($csvFiles) && empty($pdfDocs)) {
            $tasks[] = '主要成果品が未登録のため、まず資料md / CSV / PDF のいずれかを用意する必要がある';
        }
        foreach (array_slice($todoSignals['waiting_interactions'], 0, 2) as $interaction) {
            $tasks[] = self::buildWaitingTask((string)($interaction['user_message'] ?? ''), (string)($interaction['assistant_message'] ?? ''));
        }
        foreach (array_slice($todoSignals['open_interactions'], 0, 1) as $interaction) {
            $userMessage = (string)($interaction['user_message'] ?? '');
            if ($userMessage !== '' && empty($csvFiles) && preg_match('/csv|集計|グラフ|列|カラム/u', mb_strtolower($userMessage))) {
                $tasks[] = 'CSV分析の依頼があるが、対象CSVが未登録または不足している';
            }
            if ($userMessage !== '' && empty($pdfDocs) && preg_match('/pdf|資料|図面|報告書/u', mb_strtolower($userMessage))) {
                $tasks[] = '資料読解や報告書化の依頼があるが、参照できるPDF資料が不足している';
            }
        }

        return self::uniqueNonEmptyLines($tasks);
    }

    private static function buildTodoSignals(array $history, array $recentEvaluations): array
    {
        $interactions = array_reverse(self::extractRecentInteractions($history, 10));
        $evaluationIndex = self::indexRecentEvaluations($recentEvaluations);
        $open = [];
        $waiting = [];
        $review = [];
        $completed = [];

        foreach ($interactions as $interaction) {
            $assistantMessage = trim((string)($interaction['assistant_message'] ?? ''));
            if ($assistantMessage === '') {
                $open[] = $interaction;
                continue;
            }
            if (self::looksLikeClarificationAssistantAction($assistantMessage)) {
                $waiting[] = $interaction;
                continue;
            }
            $interaction['evaluation'] = self::matchEvaluationForInteraction($interaction, $evaluationIndex);
            if (self::shouldReviewInteraction($interaction)) {
                $review[] = $interaction;
                continue;
            }
            if (self::isCompletedInteraction($interaction)) {
                $completed[] = $interaction;
                continue;
            }
            $open[] = $interaction;
        }

        return [
            'open_interactions' => $open,
            'waiting_interactions' => $waiting,
            'review_interactions' => $review,
            'completed_interactions' => $completed,
        ];
    }

    private static function extractRecentInteractions(array $history, int $limit): array
    {
        $interactions = [];
        $current = null;

        foreach ($history as $row) {
            $role = (string)($row['role'] ?? '');
            $message = trim((string)($row['message'] ?? ''));
            if ($message === '') {
                continue;
            }

            if ($role === 'user') {
                if ($current !== null) {
                    $interactions[] = $current;
                }
                $current = [
                    'user_message' => $message,
                    'user_created_at' => (string)($row['created_at'] ?? ''),
                    'assistant_message' => '',
                    'assistant_created_at' => '',
                ];
                continue;
            }

            if ($role === 'assistant' && $current !== null) {
                $current['assistant_message'] = trim(
                    $current['assistant_message'] === ''
                        ? $message
                        : ($current['assistant_message'] . "\n\n" . $message)
                );
                $current['assistant_created_at'] = (string)($row['created_at'] ?? '');
            }
        }

        if ($current !== null) {
            $interactions[] = $current;
        }

        if (count($interactions) <= $limit) {
            return $interactions;
        }

        return array_slice($interactions, -$limit);
    }

    private static function isCompletedInteraction(array $interaction): bool
    {
        $assistantMessage = trim((string)($interaction['assistant_message'] ?? ''));
        if ($assistantMessage === '') {
            return false;
        }

        if (self::looksLikeIncompleteOrUnsafeAnswer($assistantMessage)) {
            return false;
        }

        if (self::looksLikeClarificationAssistantAction($assistantMessage)) {
            return false;
        }

        $evaluation = (array)($interaction['evaluation'] ?? []);
        if ($evaluation !== [] && !self::isHighConfidenceEvaluation($evaluation)) {
            return false;
        }

        return true;
    }

    private static function shouldReviewInteraction(array $interaction): bool
    {
        $assistantMessage = trim((string)($interaction['assistant_message'] ?? ''));
        if ($assistantMessage === '') {
            return false;
        }

        $evaluation = (array)($interaction['evaluation'] ?? []);
        if ($evaluation === []) {
            return false;
        }

        if (self::isHighConfidenceEvaluation($evaluation)) {
            return false;
        }

        return !self::looksLikeClarificationAssistantAction($assistantMessage);
    }

    private static function isHighConfidenceEvaluation(array $evaluation): bool
    {
        return (int)($evaluation['total_score'] ?? 0) >= 90
            && (int)($evaluation['relevance_score'] ?? 0) >= 90
            && (int)($evaluation['faithfulness_score'] ?? 0) >= 90;
    }

    private static function buildReviewCurrentTask(array $activeArtifact, array $interaction): string
    {
        $task = self::normalizeTodoTask((string)($interaction['user_message'] ?? ''));

        return match ($activeArtifact['key']) {
            'csv' => $task !== ''
                ? '直前のCSV回答を見直し、必要なら再集計・並び順・出力形式を補正する: ' . $task
                : '直前のCSV回答を見直し、必要なら再集計・並び順・出力形式を補正する',
            'report' => $task !== ''
                ? '直前の報告書/PDF回答を見直し、根拠と構成を補正する: ' . $task
                : '直前の報告書/PDF回答を見直し、根拠と構成を補正する',
            'material_note' => $task !== ''
                ? '直前の資料メモ回答を見直し、追記内容と章立てを補正する: ' . $task
                : '直前の資料メモ回答を見直し、追記内容と章立てを補正する',
            default => $task !== ''
                ? '直前回答の精度を確認し、必要なら根拠・結論・次アクションを補強する: ' . $task
                : '直前回答の精度を確認し、必要なら根拠・結論・次アクションを補強する',
        };
    }

    private static function indexRecentEvaluations(array $recentEvaluations): array
    {
        $index = [];
        foreach ($recentEvaluations as $evaluation) {
            $question = trim((string)($evaluation['question_message'] ?? ''));
            if ($question === '') {
                continue;
            }
            $key = self::buildInteractionLookupKey($question);
            $index[$key][] = $evaluation;
        }

        return $index;
    }

    private static function matchEvaluationForInteraction(array $interaction, array $evaluationIndex): array
    {
        $userMessage = trim((string)($interaction['user_message'] ?? ''));
        if ($userMessage === '') {
            return [];
        }

        $key = self::buildInteractionLookupKey($userMessage);
        $candidates = $evaluationIndex[$key] ?? [];
        if ($candidates === []) {
            return [];
        }

        $assistantMessage = trim((string)($interaction['assistant_message'] ?? ''));
        $assistantCreatedAt = (string)($interaction['assistant_created_at'] ?? '');
        foreach ($candidates as $candidate) {
            $candidateAnswer = trim((string)($candidate['answer_message'] ?? ''));
            $candidateCreatedAt = (string)($candidate['created_at'] ?? '');
            if (
                $assistantCreatedAt !== ''
                && $candidateCreatedAt !== ''
                && $assistantCreatedAt === $candidateCreatedAt
            ) {
                return $candidate;
            }
            if ($assistantMessage !== '' && $candidateAnswer !== '' && self::messagesRoughlyMatch($assistantMessage, $candidateAnswer)) {
                return $candidate;
            }
        }

        return (array)$candidates[0];
    }

    private static function buildInteractionLookupKey(string $message): string
    {
        $message = mb_strtolower(trim($message));
        $message = preg_replace('/\s+/u', ' ', $message) ?? $message;
        return $message;
    }

    private static function messagesRoughlyMatch(string $left, string $right): bool
    {
        $left = self::buildInteractionLookupKey(self::compactLine($left, 80));
        $right = self::buildInteractionLookupKey(self::compactLine($right, 80));

        return $left !== '' && $right !== '' && ($left === $right || str_starts_with($left, $right) || str_starts_with($right, $left));
    }

    private static function buildWaitingTask(string $userMessage, string $assistantMessage): string
    {
        $userMessage = trim($userMessage);
        $assistantMessage = trim($assistantMessage);
        $combined = mb_strtolower($userMessage . "\n" . $assistantMessage);

        if (preg_match('/csv|集計|グラフ|列|カラム/u', $combined)) {
            return 'CSV依頼は追加条件待ちのため、対象ファイル・列・出力形式の確定が必要';
        }
        if (preg_match('/報告書|レポート|pdf|図面|資料/u', $combined)) {
            return '報告書/PDF依頼は追加条件待ちのため、対象範囲や根拠ソースの確定が必要';
        }

        return '追加条件や確認事項の返答待ちのため、次の処理に進めていない依頼がある';
    }

    private static function looksLikeCompletedAssistantAction(string $message): bool
    {
        if ($message === '' || self::looksLikeIncompleteOrUnsafeAnswer($message)) {
            return false;
        }

        return preg_match('/完了|保存|登録|作成|生成|整理|反映|まとめ/u', $message) === 1;
    }

    private static function looksLikeClarificationAssistantAction(string $message): bool
    {
        if ($message === '') {
            return false;
        }

        return preg_match('/どの(?:列|カラム|項目|資料)|指定してください|教えてください|確認させてください|補足してください|もう少し詳しく|対象を教えて/u', $message) === 1;
    }

    private static function looksLikeLowSignalUserMessage(string $message): bool
    {
        $message = trim($message);
        if ($message === '') {
            return true;
        }

        $normalized = mb_strtolower($message);
        if (mb_strlen($normalized) <= 24 && preg_match('/^(はい|了解|承知|ok|ありがとうございます|ありがとう|お願いします|お願い|進めてください|続けてください|確認お願いします|お願いします。|はい、お願いします。|大丈夫です|問題ありません)[!！。.\s]*$/u', $normalized) === 1) {
            return true;
        }

        return false;
    }

    private static function normalizeTodoTask(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $normalized = preg_replace('/^[>\-\[\]\d\.\s]+/u', '', $text) ?? $text;
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? trim($normalized);
        if ($normalized === '') {
            return '';
        }

        return self::compactLine($normalized, 110);
    }

    private static function describeArtifactFocus(array $snapshot, array $activeArtifact): string
    {
        $materialLabel = self::describeDocumentName((array)($snapshot['primary_material_doc'] ?? []), '資料メモ');
        $csvLabel = self::describeCsvName((array)($snapshot['primary_csv_file'] ?? []));
        $pdfLabel = self::describeDocumentName((array)($snapshot['primary_pdf_doc'] ?? []), 'PDF');

        return match ($activeArtifact['key']) {
            'material_note' => $materialLabel !== '' ? '資料メモ「' . $materialLabel . '」' : '作業用の資料メモ',
            'csv' => $csvLabel !== '' ? 'CSV「' . $csvLabel . '」' : '対象CSV',
            'report' => $materialLabel !== '' ? '資料メモ「' . $materialLabel . '」を元にした報告書ドラフト' : ($pdfLabel !== '' ? 'PDF「' . $pdfLabel . '」を元にした報告書ドラフト' : '報告書ドラフト'),
            'hybrid_analysis' => ($csvLabel !== '' ? 'CSV「' . $csvLabel . '」' : 'CSV')
                . ' + '
                . ($pdfLabel !== '' ? 'PDF「' . $pdfLabel . '」' : ($materialLabel !== '' ? '資料メモ「' . $materialLabel . '」' : '関連資料')),
            'pdf' => $pdfLabel !== '' ? 'PDF「' . $pdfLabel . '」' : '参照PDF',
            default => '案件運用メモと次アクション',
        };
    }

    private static function selectPrimaryCsvFile(array $csvFiles): array
    {
        return isset($csvFiles[0]) && is_array($csvFiles[0]) ? $csvFiles[0] : [];
    }

    private static function selectPrimaryDocument(array $documents): array
    {
        return isset($documents[0]) && is_array($documents[0]) ? $documents[0] : [];
    }

    private static function describeDocumentName(array $document, string $fallback): string
    {
        $name = trim((string)($document['title'] ?? ''));
        if ($name === '') {
            $path = trim((string)($document['file_path'] ?? ''));
            $name = $path !== '' ? basename($path) : $fallback;
        }

        return self::compactLine($name, 80);
    }

    private static function describeCsvName(array $csv): string
    {
        $name = trim((string)($csv['file_name'] ?? ''));
        if ($name === '') {
            return '';
        }

        return self::compactLine($name, 80);
    }

    private static function formatDateLabel(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1
            ? substr($value, 0, 10)
            : self::compactLine($value, 16);
    }

    private static function uniqueNonEmptyLines(array $lines, array $exclude = []): array
    {
        $results = [];
        $seen = [];

        foreach ($exclude as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $seen[$line] = true;
            }
        }

        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '' || isset($seen[$line])) {
                continue;
            }
            $seen[$line] = true;
            $results[] = $line;
        }

        return $results;
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
