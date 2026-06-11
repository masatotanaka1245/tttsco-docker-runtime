<?php

require_once __DIR__ . '/SqlExecutionEngine.php';
require_once __DIR__ . '/SqlFailureAnswerFormatter.php';
require_once __DIR__ . '/SqlRouteSchemaScopeHelper.php';

class AdvancedSqlSubQueryRunner
{
    private $pdo;
    private $projectId;
    private $threadId;
    private $userId;
    private $reasoningId;
    private $ollamaHost;
    private $sqlModel;
    private $analysisModel;
    private $schemaInfo;
    private $schemaInfoByTable;
    private $schemaSummaryMatrix;
    private $dynamicTableWhitelist;
    private $projectOperatingMemoryPrompt;
    private $databaseMemoryPrompt;
    private $originalMessage;
    private $maxRetries;
    private $composeMemoryAwarePrompt;
    private $logPromptBudget;
    private $logger;
    private $statusEmitter;
    private $schemaScopeHelper;

    public function __construct(array $config)
    {
        $this->pdo = $config['pdo'];
        $this->projectId = (int)($config['projectId'] ?? 0);
        $this->threadId = isset($config['threadId']) ? (int)$config['threadId'] : null;
        $this->userId = (int)($config['userId'] ?? 0);
        $this->reasoningId = (string)($config['reasoningId'] ?? '');
        $this->ollamaHost = (string)($config['ollamaHost'] ?? '');
        $this->sqlModel = (string)($config['sqlModel'] ?? '');
        $this->analysisModel = (string)($config['analysisModel'] ?? '');
        $this->schemaInfo = (string)($config['schemaInfo'] ?? '');
        $this->schemaInfoByTable = (array)($config['schemaInfoByTable'] ?? []);
        $this->schemaSummaryMatrix = (string)($config['schemaSummaryMatrix'] ?? '');
        $this->dynamicTableWhitelist = (array)($config['dynamicTableWhitelist'] ?? []);
        $this->projectOperatingMemoryPrompt = (string)($config['projectOperatingMemoryPrompt'] ?? '');
        $this->databaseMemoryPrompt = (string)($config['databaseMemoryPrompt'] ?? '');
        $this->originalMessage = (string)($config['originalMessage'] ?? '');
        $this->maxRetries = (int)($config['maxRetries'] ?? 1);
        $this->composeMemoryAwarePrompt = $config['composeMemoryAwarePrompt'];
        $this->logPromptBudget = $config['logPromptBudget'];
        $this->logger = $config['logger'];
        $this->statusEmitter = $config['statusEmitter'];
        $this->schemaScopeHelper = new SqlRouteSchemaScopeHelper(
            $this->schemaInfo,
            $this->schemaInfoByTable,
            $this->schemaSummaryMatrix,
            $this->dynamicTableWhitelist
        );
    }

    public function executeSingleSubQuery(array $subQItem, int $stepCounter, string $stepLabel = '追加ステップ'): string
    {
        $subQ = (string)($subQItem['query'] ?? '');
        $operationType = (string)($subQItem['operation_type'] ?? 'lookup');
        $targetTableList = array_values(array_filter(array_map('strval', (array)($subQItem['target_tables'] ?? []))));
        $targetTables = implode(', ', $targetTableList);
        $answerGoal = (string)($subQItem['answer_goal'] ?? '');

        $this->log("【データ分析】ステップ {$stepCounter}: 観点「{$subQ}」のSQL生成中...");
        $this->emitStatus("🔬 [{$stepLabel}]「{$subQ}」に基づくSQLを構築中... [type: {$operationType}]");

        try {
            $stmtInsert = $this->pdo->prepare(
                "INSERT INTO chat_reasoning_steps (project_id, session_id, original_question, step_number, sub_query, created_at) VALUES (?, ?, ?, ?, ?, NOW())"
            );
            $stmtInsert->execute([
                $this->projectId,
                $this->reasoningId,
                $this->originalMessage,
                $stepCounter,
                $subQ,
            ]);
        } catch (Exception $dbEx) {
            $this->log("[ERROR] chat_reasoning_steps へのINSERTに失敗: " . $dbEx->getMessage());
        }

        $sqlSysPrompt = "【ミッション】\n"
            . "お前は実在するデータベース構造を自律監査するデータアナリストAIである。提示された「INFORMATION_SCHEMAコンテキスト」および「実在データ総数マトリクス」を熟読し、サブクエリの処理タイプに合うSELECTクエリを1つ構築せよ。\n\n"
            . "【最重要：思考解放プロトコル】\n"
            . "■ マルチテナント隔離（ `project_id` や `csv_file_id` によるデータの覗き見防止条件 ）については、システム（PHP側）が実行直前に自動的にWHERE句へ結合・強制インジェクトするため、お前は【一切記述しなくてよい】。条件句への project_id の記載は完全に省略せよ。\n"
            . "■ お前はただ、ユーザーの質問に答えるために「どのテーブルをFROM/JOINし、どのJSONキーを抽出し、どうグループ化・集計するか」という【純粋な集計ロジックの構築】だけに全リソースを集中させよ。\n\n"
            . "【重要構文ルール】\n"
            . "1. 過去の嘘のスキーマの記憶は完全パージせよ。必ず、提供された【INFORMATION_SCHEMAコンテキスト】に現実に表記されている実在の物理カラム構成のみを使用すること。\n"
            . "2. CSVデータ自体の「中身・本文」の集計や概要, 傾向をユーザーから求められた場合は、メタ情報を管理する `project_csv_files` ではなく、必ず実データ行がレコードとして詰まっている【 `project_csv_rows` 】テーブルを使用せよ。\n"
            . "3. PDFやアップロード資料の本文・図表説明を読む場合は `doc_chunks` を中心にし、必要に応じて `documents` とJOINして title, page_number, chunk_text, image_description を取得せよ。\n"
            . "4. MySQL 8.0 の日本語JSONキー抽出は `JSON_UNQUOTE(JSON_EXTRACT(T1.row_data, '$.\"項目名\"'))` を使用せよ。`row_data->>$.項目名` や `row_data->>$.'項目名'` は生成禁止。\n"
            . "   `project_csv_rows` に `row_count` カラムは存在しない。CSV行数は `COUNT(T1.id)`、CSVファイル側の登録行数は `project_csv_files.row_count` を使用せよ。\n"
            . "5. テーブルのエイリアスは必ず `project_csv_rows T1` のように `T1` などの一意のエイリアスを付与せよ。\n"
            . "6. 【絶対Group Byルール】SELECT 句に集計関数（SUM/AVG/COUNT等）と、非集計カラム（JSON項目含む）を同時に含める場合は、必ずクエリの末尾に `GROUP BY` 句を明記せよ。これを怠ると MySQL 1140 構文エラーで即死する。\n\n"
            . "【出力制約】\n"
            . "出力は必ず実行可能なSQL文字列1つのみを内包したJSON形式【のみ】を出力せよ。Markdownや余計な解説文プロースは一切排除せよ。\n"
            . '{"sql": "SELECT ..."}';

        $sqlSysPrompt = $this->wrapMemoryAwarePrompt($sqlSysPrompt);
        $scopedSchemaInfo = $this->schemaScopeHelper->buildScopedSchemaInfo($targetTableList);
        $sqlUserPrompt = $scopedSchemaInfo
            . "\n\n【サブクエリ】\n" . $subQ
            . "\n\n【operation_type】\n" . $operationType
            . "\n\n【候補テーブル】\n" . $targetTables
            . "\n\n【このサブクエリの回答目標】\n" . $answerGoal;

        $presetSql = $this->resolvePresetSqlForSubQuery($subQ, $operationType, $targetTables, $answerGoal);
        if ($presetSql !== null) {
            $this->log("[AUTO-SQL-PRESET] 軽量定番SQLを適用しました。sub_query=" . $subQ . " | operation_type=" . $operationType);
            $sqlJsonStr = json_encode(['sql' => $presetSql], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $this->recordPromptBudget('sql_generate', [
                'system' => $sqlSysPrompt,
                'schema' => $scopedSchemaInfo,
                'subQuery' => $subQ,
                'operationType' => $operationType,
                'targetTables' => $targetTables,
                'answerGoal' => $answerGoal,
                'projectMemory' => $this->projectOperatingMemoryPrompt,
                'databaseMemory' => $this->databaseMemoryPrompt,
            ], 4096);

            $sqlJsonStr = callOllamaChat(
                $this->ollamaHost,
                $this->sqlModel,
                $sqlSysPrompt,
                $sqlUserPrompt,
                'json',
                ["temperature" => 0.0, "top_p" => 0.1, "num_ctx" => 4096]
            );
        }

        $subAnswerText = $this->executeAndAnalyzeSql($sqlJsonStr, $subQ, $stepCounter, $stepLabel, $targetTableList);

        try {
            $stmtUpdAns = $this->pdo->prepare("UPDATE chat_reasoning_steps SET sub_answer = ? WHERE session_id = ? AND step_number = ?");
            $stmtUpdAns->execute([$subAnswerText, $this->reasoningId, $stepCounter]);
        } catch (Exception $dbEx) {
            $this->log("[ERROR] chat_reasoning_steps のUPDATEに失敗: " . $dbEx->getMessage());
        }

        return $subAnswerText;
    }

    private function resolvePresetSqlForSubQuery(string $subQ, string $operationType, string $targetTables, string $answerGoal): ?string
    {
        $combined = trim($subQ . "\n" . $answerGoal . "\n" . $targetTables);
        $countIntent = preg_match('/(件数|何件|総数|数|ファイル数|行数|レコード数)/u', $combined) === 1;

        if ($combined === '') {
            return null;
        }

        if ($countIntent && preg_match('/project_csv_files/u', $targetTables)) {
            return "SELECT COUNT(*) AS total_csv_files, COALESCE(SUM(row_count), 0) AS total_registered_rows FROM project_csv_files WHERE project_id = {$this->projectId}";
        }

        if ($countIntent && preg_match('/documents/u', $targetTables)) {
            return "SELECT COUNT(*) AS total_documents FROM documents WHERE project_id = {$this->projectId} AND title NOT LIKE 'AI報告書%'";
        }

        if ($countIntent && preg_match('/chat_history/u', $targetTables)) {
            $conditions = ["project_id = {$this->projectId}"];
            if ($this->threadId !== null) {
                $conditions[] = "thread_id = {$this->threadId}";
            }
            if ($this->userId > 0) {
                $conditions[] = "user_id = {$this->userId}";
            }
            return "SELECT COUNT(*) AS total_messages FROM chat_history WHERE " . implode(' AND ', $conditions);
        }

        if ($operationType === 'metadata_lookup' && preg_match('/project_csv_files/u', $targetTables)) {
            return "SELECT file_name, column_headers, row_count FROM project_csv_files WHERE project_id = {$this->projectId} ORDER BY id ASC";
        }

        if (
            $operationType === 'metadata_lookup'
            && preg_match('/documents/u', $targetTables)
            && preg_match('/(一覧|概要|棚卸し|把握|確認|資料名|ファイル名)/u', $combined)
        ) {
            return "SELECT title, file_path, created_at FROM documents WHERE project_id = {$this->projectId} AND title NOT LIKE 'AI報告書%' ORDER BY created_at DESC, id DESC LIMIT 20";
        }

        if ($operationType === 'metadata_lookup' && preg_match('/(会話|履歴|チャット|これまで|要約|まとめ)/u', $combined)) {
            $conditions = ["project_id = {$this->projectId}"];
            if ($this->threadId !== null) {
                $conditions[] = "thread_id = {$this->threadId}";
            }
            if ($this->userId > 0) {
                $conditions[] = "user_id = {$this->userId}";
            }
            return "SELECT role, message, created_at FROM chat_history WHERE " . implode(' AND ', $conditions) . " ORDER BY created_at ASC LIMIT 50";
        }

        return null;
    }

    private function truncateForPrompt(string $text, int $maxChars): string
    {
        $trimmed = trim($text);
        if (mb_strlen($trimmed) <= $maxChars) {
            return $trimmed;
        }
        return rtrim(mb_substr($trimmed, 0, $maxChars - 1)) . '…';
    }

    private function buildSqlRepairContext(string $schemaInfo, string $subQ, string $failedSql, string $error, string $repairGuidance): string
    {
        $compactError = $this->truncateForPrompt(preg_replace("/\s+/u", ' ', trim($error)), 500);
        $compactSql = $this->truncateForPrompt($failedSql, 1200);
        $compactGuidance = $this->truncateForPrompt($repairGuidance, 1400);

        return "【対象スキーマ】\n" . $schemaInfo . "\n\n"
            . "【本来の目的】\n" . $subQ . "\n\n"
            . "【失敗したSQL】\n" . $compactSql . "\n\n"
            . "【エラー要約】\n" . $compactError . "\n\n"
            . "【修復ヒント】\n" . $compactGuidance;
    }

    private function executeAndAnalyzeSql(string $sqlJsonStr, string $subQ, int $stepCounter, string $stepLabel = '', array $targetTables = []): string
    {
        $fence = str_repeat("\x60", 3);
        $retryCount = 0;
        $usedFallbackSql = false;
        $execResult = ['success' => false, 'error' => 'クエリが初期化されていません。', 'data' => []];
        $generatedSql = '';

        $this->log("[SQL-REPAIR-POLICY] max_retries={$this->maxRetries} | report_mode=off | diagram_mode=off");
        $sqlEngine = new SqlExecutionEngine($this->pdo, $this->projectId, $this->threadId, $this->userId);

        while ($retryCount <= $this->maxRetries) {
            $this->log("[OLLAMA-RAW-RESPONSE] (集計試行 {$retryCount}/{$this->maxRetries}) 受信生データ:\n" . $sqlJsonStr);

            $cleanJson = preg_replace('/^' . preg_quote($fence, '/') . '(?:json|sql)?\s*(.*?)\s*' . preg_quote($fence, '/') . '$/ms', '$1', trim($sqlJsonStr));
            $sqlData = json_decode($cleanJson, true);

            if (is_array($sqlData) && isset($sqlData['sql'])) {
                $generatedSql = trim($sqlData['sql']);
            } elseif (is_array($sqlData) && isset($sqlData['query']) && preg_match('/^\s*SELECT/i', (string)$sqlData['query'])) {
                $generatedSql = trim((string)$sqlData['query']);
            } elseif (preg_match('/SELECT\s+.*?(?:;|$)/is', $sqlJsonStr, $matches)) {
                $generatedSql = trim($matches[0]);
            } else {
                $generatedSql = trim($cleanJson);
            }

            $generatedSql = preg_replace('/:\??project_id/i', (string)$this->projectId, $generatedSql);
            $generatedSql = preg_replace(
                "/((?:[a-zA-Z0-9_]+\.)?row_data)\s*->>\s*['\"]?\\$?\.?([a-zA-Z0-9_\-\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{4E00}-\x{9FAF}]+)['\"]?/u",
                'JSON_UNQUOTE(JSON_EXTRACT($1, \'$."$2"\'))',
                $generatedSql
            );
            $generatedSql = preg_replace('/' . preg_quote($fence, '/') . 'sql|' . preg_quote($fence, '/') . '/i', '', $generatedSql);
            $generatedSql = preg_replace('/["\'}\]\s;]+$/', '', trim($generatedSql));
            $generatedSql = preg_replace('/\\s+COLLATE\\s+[\'"]?[a-zA-Z0-9_-]+[\'"]?/i', '', $generatedSql);

            $this->log("[Text-to-SQL-AFTER] (集計試行 {$retryCount}/{$this->maxRetries}) 補正後実行SQL: " . $generatedSql);

            if ($retryCount > 0) {
                $this->emitStatus("⚠️ [SQL構文エラー検知] MySQLからエラーが返されました。現在、AIがサーバーログを自己反省（Self-Reflection）し、修正クエリを自動再生成中... [デバッグ試行: {$retryCount}/{$this->maxRetries}回]");
            } else {
                $this->emitStatus("📊 [集計試行: 初回射撃] 「{$subQ}」を解決するSELECTクエリの動的ホワイトリスト安全監査を実行中...");
            }

            $malformedReason = $this->schemaScopeHelper->detectMalformedSql($generatedSql);
            if ($malformedReason !== null) {
                $execResult = [
                    'success' => false,
                    'error' => 'LLM出力の事前監査で失敗: ' . $malformedReason,
                    'data' => [],
                ];
                $this->log("[SQL-MALFORMED-PRECHECK] (集計試行 {$retryCount}/{$this->maxRetries}) {$malformedReason} | sql=" . $generatedSql);
            } else {
                if (preg_match('/FROM\s+`?([a-zA-Z0-9_-]+)`?/i', $generatedSql, $tableMatch)) {
                    $extractedTable = $tableMatch[1];
                    if ($this->schemaScopeHelper->isAllowedTargetTable($extractedTable)) {
                        $this->log("[SECURITY APPROVED] 動的ホワイトリスト監査パスを安全承認します。");
                    }
                }
                $execResult = $sqlEngine->execute($generatedSql);
            }

            if (($execResult['success'] ?? false) === true) {
                $this->log("[DEBUG-LOOP] SQLの正常実行開通を確認しました。");
                $this->emitStatus("✅ [SQL監査合格] クエリの正常実行開通を確認しました。物理データベースからのレコード抽出に成功。");
                break;
            }

            $this->log("[SQL-EXEC-FAILED] (集計試行 {$retryCount}/{$this->maxRetries}) MySQL生エラー文: " . ($execResult['error'] ?? 'Unknown Error'));
            $repairGuidance = $sqlEngine->buildRepairGuidance($generatedSql, (string)($execResult['error'] ?? 'Unknown Error'), $subQ);
            $repairTables = $targetTables;
            if (preg_match_all('/(?:FROM|JOIN)\s+`?([a-zA-Z0-9_-]+)`?/i', $generatedSql, $repairMatches)) {
                $repairTables = array_merge($repairTables, $repairMatches[1]);
            }
            $repairSchemaInfo = $this->schemaScopeHelper->buildScopedSchemaInfo($repairTables);

            $retryCount++;
            if ($retryCount > $this->maxRetries) {
                $this->log("[CRITICAL-LOOP] {$this->maxRetries}回のリトライすべてで監査拒否またはMySQLエラーが発生。ループ強制遮断。");
                break;
            }

            $debugSysPrompt = "高度なMySQL 8.0のエキスパートシステムとして、提示された【失敗したクエリ】と、MySQLサーバーが返した【生の構成エラー文】を深く自己反省（Self-Reflection）してください。\n"
                . "出力は必ず、修正・デバッグされた実行可能なSQL文字列1つのみを内包したJSON形式【のみ】を出力してください。\n"
                . "SELECT '説明文' のような実テーブルを読まないダミーSQLは禁止です。必ずFROM句で実在テーブルを参照してください。\n"
                . '{"sql": "SELECT ..."}';
            $debugSysPrompt = $this->wrapMemoryAwarePrompt($debugSysPrompt);
            $debugUserContext = $this->buildSqlRepairContext(
                $repairSchemaInfo,
                $subQ,
                $generatedSql,
                (string)($execResult['error'] ?? 'Unknown Error'),
                $repairGuidance
            );

            $this->recordPromptBudget('sql_repair', [
                'system' => $debugSysPrompt,
                'schema' => $repairSchemaInfo,
                'subQuery' => $subQ,
                'repairContext' => $debugUserContext,
                'projectMemory' => $this->projectOperatingMemoryPrompt,
                'databaseMemory' => $this->databaseMemoryPrompt,
            ], 4096);

            $sqlJsonStr = callOllamaChat(
                $this->ollamaHost,
                $this->sqlModel,
                $debugSysPrompt,
                $debugUserContext,
                'json',
                ["temperature" => 0.0, "top_p" => 0.1, "num_ctx" => 4096]
            );
        }

        if (!($execResult['success'] ?? false)) {
            $this->log("[WARN] 自律修復上限超過：最終SQLのエラーが解消されませんでした。");
            $fallbackSql = $sqlEngine->suggestFallbackSql($subQ, $generatedSql);
            if ($fallbackSql) {
                $this->log("[FALLBACK-SQL] 自律修復上限後、実在スキーマに基づく定番SQLで最終救済を試行します: " . $fallbackSql);
                $fallbackResult = $sqlEngine->execute($fallbackSql);
                if (($fallbackResult['success'] ?? false) === true) {
                    $generatedSql = $fallbackResult['sql'] ?? $fallbackSql;
                    $execResult = $fallbackResult;
                    $usedFallbackSql = true;
                    $this->log("[FALLBACK-SQL-SUCCESS] 定番SQLによる救済に成功しました。");
                }
            }
        }

        if (!($execResult['success'] ?? false)) {
            return SqlFailureAnswerFormatter::buildFailureAnswer((string)($execResult['error'] ?? '不明なエラー。'));
        }

        $limitedResults = $execResult['data'] ?? [];
        $batchSize = 100;
        $batches = array_chunk($limitedResults, $batchSize);
        if (empty($batches)) {
            $batches = [[]];
        }

        $accumulatedInsight = '';
        foreach ($batches as $index => $batch) {
            $batchNum = $index + 1;
            $totalBatches = count($batches);
            $batchJson = json_encode($batch, JSON_UNESCAPED_UNICODE);

            $this->log("[DEBUG] バッチスライス巡回中... ({$batchNum}/{$totalBatches})");
            $this->emitStatus("📚 [データ分割巡回中] 抽出レコードが巨大なため、安全に分割スキャンを実行中... 現在 {$batchNum} / 全 {$totalBatches} バッチ目をAIがディープ精読・インサイト抽出中...");

            $subAnalysisSys = "外お前はデータアナリストです。実行されたSQLとその集計結果から、客観的な考察を簡潔にまとめてください。";
            if ($accumulatedInsight !== '') {
                $subAnalysisSys .= "【これまでのバッチから得られた蓄積知識（State-Saving）】\n" . $accumulatedInsight . "\n\n";
            }
            $subAnalysisSys .= "提示された【実行したクエリ】と【今回のデータバッチ ({$batchNum}/{$totalBatches})】から、新たに何が読み取れるか客観的なインサイトを抽出し、これまでの蓄積知識と論理的に統合して「最新の考察」を日本語で簡潔に再構築してください。";

            $subAnalysisUser = "【分析観点】\n{$subQ}\n\n========================================\n【実行クエリ】\n{$generatedSql}\n========================================\n【今回データバッチ】\n{$batchJson}";

            $analysisThought = '';
            $analysisRes = callOllamaChat(
                $this->ollamaHost,
                $this->analysisModel,
                $subAnalysisSys,
                $subAnalysisUser,
                null,
                ["temperature" => 0.0, "top_p" => 0.1, "num_ctx" => 4096],
                $analysisThought
            );

            if (!empty($analysisThought)) {
                $analysisRes = "🤔 **[データ考察プロセス (Batch {$batchNum})]**\n<details><summary>分析過程を展開</summary>\n\n" . $analysisThought . "\n\n</details>\n\n---\n\n" . $analysisRes;
            }

            $accumulatedInsight = $analysisRes;

            $tempSubAnswer = "【実行SQL】\n" . $fence . "sql\n{$generatedSql}\n" . $fence . "\n\n【段階的理解進捗】\n現在 {$batchNum} / {$totalBatches} バッチを精読完了。\n\n【最新の中間考察】\n{$accumulatedInsight}";
            try {
                $stmtUpdAns = $this->pdo->prepare("UPDATE chat_reasoning_steps SET sub_answer = ? WHERE session_id = ? AND step_number = ?");
                $stmtUpdAns->execute([$tempSubAnswer, $this->reasoningId, $stepCounter]);
            } catch (Exception $e) {
                $this->log("[ERROR] バッチ中間考察の即時永続化に失敗: " . $e->getMessage());
            }
        }

        $prefix = SqlFailureAnswerFormatter::buildFallbackPrefix($usedFallbackSql);
        $totalBatches = count($batches);
        return $prefix . "【実行SQL】\n" . $fence . "sql\n{$generatedSql}\n" . $fence . "\n\n【段階的分割巡回（全 {$totalBatches} バッチ）による最終統合考察】\n{$accumulatedInsight}";
    }

    private function wrapMemoryAwarePrompt(string $prompt): string
    {
        return (string)call_user_func($this->composeMemoryAwarePrompt, $prompt);
    }

    private function recordPromptBudget(string $phase, array $parts, int $numCtx): void
    {
        call_user_func($this->logPromptBudget, $phase, $parts, $numCtx);
    }

    private function log(string $message): void
    {
        call_user_func($this->logger, $message);
    }

    private function emitStatus(string $message): void
    {
        call_user_func($this->statusEmitter, $message);
    }
}
