<?php

require_once __DIR__ . '/SqlExecutionEngine.php';

final class AdvancedPlanExecutor
{
    private $pdo;
    private $projectId;
    private $ollamaHost;
    private $reasoningModel;
    private $sqlModel;
    private $searchQuery;
    private $originalMessage;
    private $reasoningId;
    private $threadId;
    private $userId;
    private $composeMemoryAwarePrompt;
    private $inferOperationType;
    private $buildDocChunkEvidenceSummary;
    private $registerSource;
    private $appendSubAnswer;
    private $logger;

    public function __construct(
        PDO $pdo,
        int $projectId,
        string $ollamaHost,
        string $reasoningModel,
        string $sqlModel,
        string $searchQuery,
        string $originalMessage,
        string $reasoningId,
        ?int $threadId,
        ?int $userId,
        callable $composeMemoryAwarePrompt,
        callable $inferOperationType,
        callable $buildDocChunkEvidenceSummary,
        callable $registerSource,
        callable $appendSubAnswer,
        ?callable $logger = null
    ) {
        $this->pdo = $pdo;
        $this->projectId = $projectId;
        $this->ollamaHost = $ollamaHost;
        $this->reasoningModel = $reasoningModel;
        $this->sqlModel = $sqlModel;
        $this->searchQuery = $searchQuery;
        $this->originalMessage = $originalMessage;
        $this->reasoningId = $reasoningId;
        $this->threadId = $threadId;
        $this->userId = $userId;
        $this->composeMemoryAwarePrompt = $composeMemoryAwarePrompt;
        $this->inferOperationType = $inferOperationType;
        $this->buildDocChunkEvidenceSummary = $buildDocChunkEvidenceSummary;
        $this->registerSource = $registerSource;
        $this->appendSubAnswer = $appendSubAnswer;
        $this->logger = $logger;
    }

    public function execute(array $plan, array $tablesSchema): array
    {
        $stepResults = [];
        $stepCounter = 0;
        $sqlEngine = new SqlExecutionEngine($this->pdo, $this->projectId, $this->threadId, $this->userId);
        $fence = str_repeat("\x60", 3);

        foreach ($plan as $stepItem) {
            $stepCounter++;
            $tableName = (string)($stepItem['table'] ?? '');
            $purpose = (string)($stepItem['purpose'] ?? '');
            $operationType = (string)($stepItem['operation_type'] ?? call_user_func(
                $this->inferOperationType,
                $purpose . ' ' . $this->searchQuery
            ));

            if (!isset($tablesSchema[$tableName])) {
                continue;
            }

            $this->log("【フル思考】フェーズ2.{$stepCounter}: テーブル「{$tableName}」の動的マスキングスキャン実行中...");
            sendSSE('status', [
                'step' => 3,
                'message' => "🔍 【シーケンス2/3】資料巡回ステップ [{$stepCounter}/" . count($plan) . "]: テーブル「{$tableName}」を自動精読中...",
            ]);

            $generatedSql = $this->generateSqlForStep(
                $tableName,
                $purpose,
                $operationType,
                $tablesSchema,
                $fence,
                $stepCounter
            );

            $subAnsText = '';
            $resultJson = '[]';
            $isSafeSql = preg_match('/^\s*SELECT/i', $generatedSql)
                && !preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|EXECUTE)\b/i', $generatedSql);

            if ($generatedSql === '' || !$isSafeSql) {
                $subAnsText = "⚠️ SQLの構築に失敗したか、安全ではないクエリと判定されました。\n生成されたSQL: {$generatedSql}";
            } else {
                [$subAnsText, $resultJson] = $this->runSqlForStep(
                    $sqlEngine,
                    $tableName,
                    $purpose,
                    $operationType,
                    $generatedSql,
                    $fence,
                    $stepCounter
                );
            }

            $this->persistReasoningStep($stepCounter, $tableName, $purpose, $subAnsText);

            $stepResults[] = [
                'step' => $stepCounter,
                'table' => $tableName,
                'purpose' => $purpose,
                'result' => $subAnsText,
                'result_json' => $resultJson,
            ];

            call_user_func(
                $this->appendSubAnswer,
                "◆ 資料巡回ステップ {$stepCounter} (テーブル: {$tableName}): {$purpose}\n{$subAnsText}"
            );
        }

        return $stepResults;
    }

    private function generateSqlForStep(
        string $tableName,
        string $purpose,
        string $operationType,
        array $tablesSchema,
        string $fence,
        int $stepCounter
    ): string {
        $targetSchema = $tablesSchema[$tableName];
        if ($tableName === 'doc_chunks' && isset($tablesSchema['documents'])) {
            $targetSchema .= "\n\n関連テーブルとしてJOIN可能:\n" . $tablesSchema['documents'];
        }

        $projectConstraint = '';
        if ($tableName === 'doc_chunks') {
            $projectConstraint = "・doc_chunks には project_id が存在しません。案件で絞り込む場合は documents d と JOIN し、d.project_id = {$this->projectId} を使用してください。\n";
        } elseif ($tableName !== 'project_csv_rows' && $tableName !== 'users') {
            $projectConstraint = "・テーブルに [project_id] カラムが存在する場合は、必ず [project_id = {$this->projectId}] の絞り込み条件を含めてください。\n";
        }

        $sqlSysPrompt = "あなたは極めて正確なデータ抽出SQLを構築する MySQL エキスパートです。\n"
            . "提示された【対象データの詳細スキーマ情報】のみを頭に入れ、ユーザーの目的を達成するための MySQL 8.0 互換の SELECT クエリを作成してください。\n"
            . "関係のない他のテーブルは使わないでください。ただし doc_chunks から本文を読む場合は、出典表示のため documents とのJOINを許可します。\n\n"
            . "【対象テーブルのスキーマ情報】\n"
            . $targetSchema . "\n\n"
            . "★【重要：マルチテナント隔離の絶対ルール（プレースホルダー完全禁止）】\n"
            . "・クエリを組み立てる際、お前が WHERE 条件に必ず「生の数字」で直接指定しなければいけない現在の project_id は 【 {$this->projectId} 】 です。\n"
            . "・`:project_id` などのプレースホルダー記号や変数は【絶対に生成禁止】です。必ず文字通り `WHERE [テーブル名].project_id = {$this->projectId}` のように、生の整数値を直接クエリ文字列に書き込んでください。\n\n"
            . "【絶対ルール】\n"
            . $projectConstraint
            . "・★【最重要：MySQL 8.0 日本語JSONパスの絶対拘束ルール】\n"
            . "  JSON型（row_data カラム）から「所属」や「課題など」といった日本語キーを抽出・集計する際は、必ず `JSON_UNQUOTE(JSON_EXTRACT(T1.row_data, '$.\"項目名\"'))` を使用せよ。\n"
            . "  `project_csv_rows` に `row_count` カラムは存在しない。CSV行数は `COUNT(T1.id)`、CSVファイル側の登録行数は `project_csv_files.row_count` を使用せよ。\n"
            . "・キャスト時に COLLATE 句は絶対に使用しないでください。\n"
            . "・PDFや資料本文を読む semantic_extract では title, page_number, chunk_text, chunk_summary, image_description を優先して取得してください。\n"
            . "・出力は必ず実行可能なSQL文字列1つのみを内包したJSON形式で出力してください。Markdownや説明テキスト、コメントは完全禁止です。\n"
            . '{"sql": "SELECT ..."}';

        $sqlSysPrompt = str_replace('$ ', '$', (string)call_user_func($this->composeMemoryAwarePrompt, $sqlSysPrompt));
        $sqlUserPrompt = "【全体の質問】\n{$this->searchQuery}\n\n【このステップの目的】\n{$purpose}\n\n【operation_type】\n{$operationType}";

        if ($this->shouldUsePresetDocSemanticExtractSql($tableName, $operationType)) {
            $generatedSql = $this->buildPresetDocSemanticExtractSql();
            $this->log("[AUTO-SQL-PRESET] (ステップ {$stepCounter}) 資料PDF抽出の定番SQLを適用しました。");
        } elseif ($this->shouldUsePresetProjectCsvFilesSql($tableName, $operationType, $purpose)) {
            $generatedSql = $this->buildPresetProjectCsvFilesSql();
            $this->log("[AUTO-SQL-PRESET] (ステップ {$stepCounter}) CSVファイル一覧の定番SQLを適用しました。");
        } else {
            $sqlJsonStr = callOllamaChat(
                $this->ollamaHost,
                $this->sqlModel,
                $sqlSysPrompt,
                $sqlUserPrompt,
                'json',
                ["temperature" => 0.0, "top_p" => 0.1, "num_ctx" => 4096]
            );
            $this->log("[AUTO-SQL-RAW] (ステップ {$stepCounter}) 受信生クエリデータ: " . $sqlJsonStr);

            $cleanJsonPattern = '/^' . preg_quote($fence, '/') . '(?:json|sql)?\s*(.*?)\s*' . preg_quote($fence, '/') . '$/ms';
            $cleanJsonStr = preg_replace($cleanJsonPattern, '$1', trim((string)$sqlJsonStr));
            $sqlData = json_decode((string)$cleanJsonStr, true);

            if (is_array($sqlData) && isset($sqlData['sql'])) {
                $generatedSql = trim((string)$sqlData['sql']);
            } elseif (is_array($sqlData) && isset($sqlData['query']) && preg_match('/^\s*SELECT/i', (string)$sqlData['query'])) {
                $generatedSql = trim((string)$sqlData['query']);
            } elseif (preg_match('/SELECT\s+.*?(?:;|$)/is', (string)$sqlJsonStr, $matches)) {
                $generatedSql = trim((string)$matches[0]);
            } else {
                $generatedSql = trim((string)$cleanJsonStr);
            }
        }

        $this->log("[AGENT-SQL-BEFORE] (ステップ {$stepCounter}) プログラム補正前の素のSQL: " . $generatedSql);

        $generatedSql = preg_replace('/:\??project_id/i', (string)$this->projectId, $generatedSql);
        $generatedSql = preg_replace(
            "/((?:[a-zA-Z0-9_]+\.)?row_data)\s*->>\s*['\"]?\\$?\.?([a-zA-Z0-9_\-\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{4E00}-\x{9FAF}]+)['\"]?/u",
            'JSON_UNQUOTE(JSON_EXTRACT($1, \'$."$2"\'))',
            (string)$generatedSql
        );
        $generatedSql = preg_replace('/' . preg_quote($fence, '/') . 'sql|' . preg_quote($fence, '/') . '/i', '', (string)$generatedSql);
        $generatedSql = preg_replace('/["\'}\]\s;]+$/', '', trim((string)$generatedSql));
        $generatedSql = preg_replace('/\\s+COLLATE\\s+[\'"]?[a-zA-Z0-9_-]+[\'"]?/i', '', (string)$generatedSql);

        $this->log("[AGENT-SQL-AFTER]  (ステップ {$stepCounter}) プログラム補正後の最終実行SQL: " . $generatedSql);
        return trim((string)$generatedSql);
    }

    private function runSqlForStep(
        SqlExecutionEngine $sqlEngine,
        string $tableName,
        string $purpose,
        string $operationType,
        string $generatedSql,
        string $fence,
        int $stepCounter
    ): array {
        $subAnsText = '';
        $resultJson = '[]';

        try {
            $execResult = $sqlEngine->execute($generatedSql);

            if (!$execResult['success']) {
                $this->log("[AGENT-EXEC-FAILED] (ステップ {$stepCounter}) 監査拒否または生エラー: " . ($execResult['error'] ?? 'Unknown Error'));
                $repairGuidance = $sqlEngine->buildRepairGuidance($generatedSql, (string)($execResult['error'] ?? 'Unknown Error'), $purpose);
                $this->log("[AGENT-REPAIR-GUIDANCE] (ステップ {$stepCounter}) 正解誘導ヒント:\n" . $repairGuidance);

                $fallbackSql = $sqlEngine->suggestFallbackSql($purpose, $generatedSql);
                if ($fallbackSql) {
                    $this->log("[AGENT-FALLBACK-SQL] (ステップ {$stepCounter}) 実在スキーマに基づく定番SQLで救済試行: " . $fallbackSql);
                    $fallbackResult = $sqlEngine->execute($fallbackSql);
                    if (($fallbackResult['success'] ?? false) === true) {
                        $generatedSql = $fallbackResult['sql'] ?? $fallbackSql;
                        $execResult = $fallbackResult;
                        $this->log("[AGENT-FALLBACK-SQL-SUCCESS] (ステップ {$stepCounter}) 定番SQLによる救済に成功しました。");
                    }
                }
            }

            if (!$execResult['success']) {
                $subAnsText = "⚠️ クエリの実行がセキュリティまたは構文監査により遮断されました。理由: " . ($execResult['error'] ?? '不明な拒否。');
                return [$subAnsText, $resultJson];
            }

            $rows = $execResult['data'] ?? [];
            foreach ($rows as $row) {
                if (in_array($tableName, ['doc_chunks', 'documents', 'project_faqs', 'project_csv_files'], true)) {
                    call_user_func($this->registerSource, $row, $tableName, $stepCounter);
                }
            }

            $resultJson = json_encode($rows, JSON_UNESCAPED_UNICODE);
            if (mb_strlen((string)$resultJson) > 3000) {
                $resultJson = mb_substr((string)$resultJson, 0, 3000) . "\n...[Context Guard: 容量超過のためデータ末尾をカット]";
            }

            if ($tableName === 'doc_chunks' && $operationType === 'semantic_extract') {
                $subAnsText = "【実行SQL】\n" . $fence . "sql\n{$generatedSql}\n" . $fence
                    . "\n\n【取得した資料根拠】\n" . call_user_func($this->buildDocChunkEvidenceSummary, $rows);
            } else {
                $analysisSys = "あなたはデータアナリストです。提示された【実行したクエリ】と【抽出データ】から、何が読み取れるか客観的なインサイトのみを日本語で簡潔に1行〜数行で要約してください。";
                $analysisUser = "【ステップ目的】\n{$purpose}\n\n========================================\n【実行クエリ】\n{$generatedSql}\n========================================\n【抽出データ】\n{$resultJson}";
                $analysisThought = '';

                $analysisRes = callOllamaChat(
                    $this->ollamaHost,
                    $this->reasoningModel,
                    $analysisSys,
                    $analysisUser,
                    null,
                    ["num_ctx" => 4096],
                    $analysisThought
                );

                if ($analysisThought !== '') {
                    $analysisRes = "🤔 **[データ考察プロセス]**\n<details><summary>分析過程を展開</summary>\n\n"
                        . $analysisThought . "\n\n</details>\n\n---\n\n" . $analysisRes;
                }

                $subAnsText = "【実行SQL】\n" . $fence . "sql\n{$generatedSql}\n" . $fence
                    . "\n\n【取得データ抜粋】\n" . $fence . "json\n{$resultJson}\n" . $fence
                    . "\n\n【中間考察】\n" . $analysisRes;
            }
        } catch (Exception $e) {
            $this->log("[AGENT-EXEC-FAILED] (ステップ {$stepCounter}) 例外詳細: " . $e->getMessage());
            $subAnsText = "[ERROR] クエリ実行エラー: " . $e->getMessage() . "\nSQL: {$generatedSql}";
        }

        return [$subAnsText, (string)$resultJson];
    }

    private function persistReasoningStep(int $stepCounter, string $tableName, string $purpose, string $subAnsText): void
    {
        if ($this->reasoningId === '') {
            return;
        }

        try {
            $stmtInsertStep = $this->pdo->prepare("INSERT INTO chat_reasoning_steps (project_id, session_id, original_question, step_number, sub_query, sub_answer, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $stmtInsertStep->execute([
                $this->projectId,
                $this->reasoningId,
                $this->originalMessage,
                2 + $stepCounter,
                "[資料巡回: {$tableName}] {$purpose}",
                $subAnsText,
            ]);
        } catch (Exception $ex) {
            $this->log("資料巡回ステップ保存例外: " . $ex->getMessage());
        }
    }

    private function shouldUsePresetDocSemanticExtractSql(string $tableName, string $operationType): bool
    {
        return $tableName === 'doc_chunks' && $operationType === 'semantic_extract';
    }

    private function buildPresetDocSemanticExtractSql(): string
    {
        return "SELECT d.id AS doc_id, d.title, d.file_path, c.page_number, c.chunk_text, c.chunk_summary, c.image_description
                FROM documents d
                JOIN doc_chunks c ON d.id = c.doc_id
                WHERE d.project_id = {$this->projectId}
                  AND LOWER(d.file_path) LIKE '%.pdf'
                  AND d.title NOT LIKE 'AI報告書%'
                ORDER BY d.created_at DESC, d.id DESC, c.page_number ASC, c.id ASC
                LIMIT 24";
    }

    private function shouldUsePresetProjectCsvFilesSql(string $tableName, string $operationType, string $purpose): bool
    {
        if ($tableName !== 'project_csv_files') {
            return false;
        }

        $context = $purpose . "\n" . $this->searchQuery;
        $looksLikeCsvOverview = preg_match('/(CSV|csv|ファイル|データセット)/u', $context) === 1
            && preg_match('/(一覧|概要|内訳|カラム|列|ヘッダー|行数|row_count|ファイル名)/u', $context) === 1;

        return in_array($operationType, ['semantic_extract', 'simple_aggregate'], true) && $looksLikeCsvOverview;
    }

    private function buildPresetProjectCsvFilesSql(): string
    {
        return "SELECT file_name, column_headers, row_count
                FROM project_csv_files
                WHERE project_id = {$this->projectId}
                ORDER BY id ASC";
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $message);
        }
    }
}
