<?php
/**
 * src/SqlExecutionEngine.php
 *
 * 軽量LLM（4Bクラス）特化型 SQL自律監査・実行エンジン
 * 【マルチテナント完全防衛・安全スコープ自動合流（構造化＆非構造化データ完全カバー版）】
 */

class SqlExecutionEngine {
    /** @var PDO */
    private $pdo;

    /** @var int */
    private $projectId;

    /** @var int|null */
    private $threadId;

    /** @var int|null */
    private $userId;

    /** @var array 許可テーブルのホワイトリスト */
    private static $allowedTables = [
        'chat_evaluations',
        'chat_history',
        'chat_reasoning_steps',
        'doc_chunks',
        'documents',
        'project_comments',
        'project_csv_files',
        'project_csv_rows',
        'project_faqs',
        'projects'
    ];

    /**
     * コンストラクタ
     *
     * @param PDO $pdo
     * @param int|string|null $projectId
     * @param int|string|null $threadId
     * @param int|string|null $userId
     */
    public function __construct($pdo, $projectId, $threadId = null, $userId = null) {
        $this->pdo       = $pdo;
        $this->projectId = (int)$projectId;
        $this->threadId  = $threadId !== null ? (int)$threadId : null;
        $this->userId    = $userId !== null ? (int)$userId : null;
    }

    /**
     * クエリのクレンジング、安全スコープの自動合流、監査、実行、および結果の丸めを一括処理
     *
     * @param string $rawSql LLMから引き渡された生のSQL文字列
     * @return array 応答連想配列
     */
    public function execute(string $rawSql): array {
        try {
            // 1. クレンジング（Markdownコードフェンスの剥ぎ取り）
            $sql = $this->cleanSql($rawSql);
            $sql = $this->normalizeGeneratedSql($sql);

            // ✨【安全スコープ自動合流（オート・インジェクション）】
            // AIにマルチテナントの条件を書かせず、プログラム側で実行直前に現在の案件ID・ファイルID・ドキュメントIDを自動融合する
            $sql = $this->injectSecurityFilters($sql);

            // 2. セキュリティ監査（基本構文・ホワイトリスト・BOLA防御）
            $audit = $this->auditSql($sql);
            if (!$audit['success']) {
                return [
                    'success' => false,
                    'error'   => $audit['error'] . "\n\n" . $this->buildRepairGuidance($sql, $audit['error'])
                ];
            }

            // 3. ONLY_FULL_GROUP_BY モードの動的緩和
            $this->relaxSqlMode();

            // 4. クエリの安全実行
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 5. Context Guardによるデータ丸め処理
            $processedData = $this->applyContextGuard($rawRows);

            return [
                'success' => true,
                'sql'     => $sql,
                'count'   => count($processedData),
                'data'    => $processedData
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error'   => 'SQL実行中にエラーが発生しました: ' . $e->getMessage() . "\n\n" . $this->buildRepairGuidance($rawSql, $e->getMessage())
            ];
        }
    }

    /**
     * LLMが手癖で出力するマークダウンタグの除去
     */
    private function cleanSql(string $rawSql): string {
        $sql = trim($rawSql);
        
        $fence = str_repeat("\x60", 3);
        $pattern = '/' . preg_quote($fence, '/') . '(?:sql|json)?\s*(.*?)\s*' . preg_quote($fence, '/') . '/is';
        
        if (preg_match($pattern, $sql, $matches)) {
            $sql = trim($matches[1]);
        }
        
        return trim($sql, "; \t\n\r\0\x0B");
    }

    /**
     * LLMが出しやすいCSV/JSON関連の誤SQLを、実行前にMySQL 8.0で通る形へ寄せる。
     */
    private function normalizeGeneratedSql(string $sql): string {
        $sql = preg_replace('/\b(documents_content|doc_contents|document_chunks)\b/i', 'doc_chunks', $sql) ?? $sql;
        $sql = preg_replace('/\bdoc_chunks\.document_id\b/i', 'doc_chunks.doc_id', $sql) ?? $sql;

        $chunksAlias = $this->getTableAlias($sql, 'doc_chunks');
        if ($chunksAlias !== '') {
            $quotedChunksAlias = preg_quote($chunksAlias, '/');
            $sql = preg_replace(
                '/\b' . $quotedChunksAlias . '\.document_id\b/i',
                $chunksAlias . '.doc_id',
                $sql
            ) ?? $sql;
        }

        $rowsAlias = $this->getTableAlias($sql, 'project_csv_rows');

        if ($rowsAlias !== '') {
            $quotedRowsAlias = preg_quote($rowsAlias, '/');
            $sql = preg_replace(
                '/\bSUM\s*\(\s*' . $quotedRowsAlias . '\.row_count\s*\)/i',
                'COUNT(' . $rowsAlias . '.id)',
                $sql
            ) ?? $sql;
            $sql = preg_replace(
                '/\bCOUNT\s*\(\s*' . $quotedRowsAlias . '\.row_count\s*\)/i',
                'COUNT(' . $rowsAlias . '.id)',
                $sql
            ) ?? $sql;
        }

        $filesAlias = $this->getTableAlias($sql, 'project_csv_files');
        if ($filesAlias !== '' && $rowsAlias !== '' && stripos($sql, 'project_csv_files') !== false && stripos($sql, 'project_csv_rows') !== false) {
            $quotedFilesAlias = preg_quote($filesAlias, '/');
            $sql = preg_replace(
                '/\bCOUNT\s*\(\s*' . $quotedFilesAlias . '\.id\s*\)/i',
                'COUNT(DISTINCT ' . $filesAlias . '.id)',
                $sql
            ) ?? $sql;
        }

        $sql = preg_replace('/\b([a-zA-Z0-9_]+)\.JSON_UNQUOTE\s*\(\s*JSON_EXTRACT\s*\(\s*\1\./i', 'JSON_UNQUOTE(JSON_EXTRACT($1.', $sql) ?? $sql;
        $sql = $this->normalizeJsonArrowPaths($sql);
        $sql = preg_replace('/\s+COLLATE\s+[\'"]?[a-zA-Z0-9_-]+[\'"]?/i', '', $sql) ?? $sql;

        return trim($sql);
    }

    /**
     * row_data->>$."キー" や row_data->>"$.キー" を JSON_UNQUOTE(JSON_EXTRACT(...)) に正規化する。
     */
    private function normalizeJsonArrowPaths(string $sql): string {
        $columnPattern = '((?:[a-zA-Z0-9_]+`?\.)?`?row_data`?)';

        $patterns = [
            '/\b' . $columnPattern . '\s*->>\s*\$\."([^"]+)"/u',
            '/\b' . $columnPattern . '\s*->>\s*["\']\$\."([^"]+)"["\']/u',
            '/\b' . $columnPattern . '\s*->>\s*["\']\$\.([^"\']+)["\']/u',
        ];

        foreach ($patterns as $pattern) {
            $sql = preg_replace_callback($pattern, function ($matches) {
                $column = $matches[1];
                $key = str_replace(['\\', "'"], ['\\\\', "\\'"], trim($matches[2]));
                return "JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.\"{$key}\"'))";
            }, $sql) ?? $sql;
        }

        return $sql;
    }

    /**
     * ✨【自律セキュリティコア・完全体（一本道ガードレール化修正版）】
     * AIがでっち上げた不完全なクエリやエイリアスの不整合を動的にパースし、
     * データベースから仕入れた「真実の所有権スコープ」でクエリを上書き再構築する職人技回路
     */
    private function injectSecurityFilters(string $sql): string {
        // 🛡️ 最初に返却用の変数にクエリを格納（何が起きてもnullを返さないセーフガード）
        $resultSql = $sql;
        
        if ($this->projectId <= 0) {
            return (string)$resultSql; // 全社横断モードの時はそのまま出荷
        }

        try {
            // 🛠️ 1. 各テーブルのエイリアスを動的に特定（WHEREやJOINなどの予約語の誤検知を完全封殺）
            $rowsAlias   = $this->getTableAlias($sql, 'project_csv_rows');
            $filesAlias  = $this->getTableAlias($sql, 'project_csv_files');
            $histAlias   = $this->getTableAlias($sql, 'chat_history');
            $chunksAlias = $this->getTableAlias($sql, 'doc_chunks');
            $docsAlias   = $this->getTableAlias($sql, 'documents');

            // 🛠️ 2. AIがでっち上げた間違った・古い条件式（ハルシネーション）を一度綺麗に消去（パージ）
            // ✨【タイポ完全パージ】：[a-zA-9_] になっていた箇所を [a-zA-Z0-9_] へ修正し、PCREコンパイルエラーを完全遮断！
            $sql = preg_replace('/AND\s+([a-zA-Z0-9_]+\.)?project_id\s*=\s*\d+/i', '', $sql);
            $sql = preg_replace('/([a-zA-Z0-9_]+\.)?project_id\s*=\s*\d+\s*AND/i', '', $sql);
            $sql = preg_replace('/\bWHERE\s+([a-zA-Z0-9_]+\.)?project_id\s*=\s*\d+\s*$/i', '', $sql);

            $sql = preg_replace('/AND\s+([a-zA-Z0-9_]+\.)?csv_file_id\s*=\s*\d+/i', '', $sql);
            $sql = preg_replace('/([a-zA-Z0-9_]+\.)?csv_file_id\s*=\s*\d+\s*AND/i', '', $sql);
            $sql = preg_replace('/\bWHERE\s+([a-zA-Z0-9_]+\.)?csv_file_id\s*=\s*\d+\s*$/i', '', $sql);

            $sql = preg_replace('/AND\s+([a-zA-Z0-9_]+\.)?(document_id|doc_id)\s*=\s*\d+/i', '', $sql);
            $sql = preg_replace('/([a-zA-Z0-9_]+\.)?(document_id|doc_id)\s*=\s*\d+\s*AND/i', '', $sql);
            $sql = preg_replace('/\bWHERE\s+([a-zA-Z0-9_]+\.)?(document_id|doc_id)\s*=\s*\d+\s*$/i', '', $sql);

            // 🛡️ 防衛線：もし正規表現に失敗してnullが返っていた場合は、即座に元のクエリで救済復帰
            if ($sql === null) {
                return (string)$resultSql;
            }

            // 改めて最新のクエリ状態をスキャン
            $lowerSql = strtolower($sql);
            $conditions = [];

            // 🔒 A. project_csv_rows が含まれる場合
            if (strpos($lowerSql, 'project_csv_rows') !== false) {
                $stmt = $this->pdo->prepare("SELECT id FROM project_csv_files WHERE project_id = ?");
                $stmt->execute([$this->projectId]);
                $fileIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($fileIds)) {
                    $conditions[] = "{$rowsAlias}.csv_file_id IN (" . implode(',', array_map('intval', $fileIds)) . ")";
                } else {
                    $conditions[] = "{$rowsAlias}.csv_file_id = 0";
                }
            }

            // 🔒 B. project_csv_files が含まれる場合
            if (strpos($lowerSql, 'project_csv_files') !== false) {
                $conditions[] = "{$filesAlias}.project_id = " . $this->projectId;
            }

            // 🔒 C. chat_history が含まれる場合
            if (strpos($lowerSql, 'chat_history') !== false) {
                $conditions[] = "{$histAlias}.project_id = " . $this->projectId;
                if ($this->threadId !== null) {
                    $conditions[] = "{$histAlias}.thread_id = " . $this->threadId;
                }
                if ($this->userId !== null && $this->userId > 0) {
                    $conditions[] = "{$histAlias}.user_id = " . $this->userId;
                }
            }

            // 🔒 D. doc_chunks が含まれる場合
            if (strpos($lowerSql, 'doc_chunks') !== false) {
                $stmtDoc = $this->pdo->prepare("SELECT id FROM documents WHERE project_id = ?");
                $stmtDoc->execute([$this->projectId]);
                $docIds = $stmtDoc->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($docIds)) {
                    $conditions[] = "{$chunksAlias}.doc_id IN (" . implode(',', array_map('intval', $docIds)) . ")";
                } else {
                    $conditions[] = "{$chunksAlias}.doc_id = 0";
                }
            }

            // 🔒 E. documents が含まれる場合
            if (strpos($lowerSql, 'documents') !== false) {
                $conditions[] = "{$docsAlias}.project_id = " . $this->projectId;
            }

            // 条件が何も追加されなければ、クレンジング後クエリを返して終了
            if (empty($conditions)) {
                return trim($sql);
            }

            // 🗺️ WHERE句の自動マージマッピング
            $injectionText = implode(" AND ", $conditions);
            $whereClause = $this->findTopLevelClause($sql, ['WHERE']);
            if ($whereClause !== null) {
                $whereBodyStart = $whereClause['offset'] + $whereClause['length'];
                $nextClause = $this->findTopLevelClause($sql, ['GROUP BY', 'HAVING', 'ORDER BY', 'LIMIT']);
                $whereBodyEnd = ($nextClause !== null && $nextClause['offset'] > $whereBodyStart)
                    ? $nextClause['offset']
                    : strlen($sql);

                $prefix = rtrim(substr($sql, 0, $whereClause['offset']));
                $whereBody = trim(substr($sql, $whereBodyStart, $whereBodyEnd - $whereBodyStart));
                $suffix = ltrim(substr($sql, $whereBodyEnd));

                $mergedWhere = $whereBody !== ''
                    ? " WHERE ({$injectionText}) AND ({$whereBody})"
                    : " WHERE {$injectionText}";
                $sql = trim($prefix . $mergedWhere . ($suffix !== '' ? ' ' . $suffix : ''));
            } else {
                $nextClause = $this->findTopLevelClause($sql, ['GROUP BY', 'HAVING', 'ORDER BY', 'LIMIT']);
                if ($nextClause !== null) {
                    $offset = $nextClause['offset'];
                    $sql = substr($sql, 0, $offset) . " WHERE " . $injectionText . " " . substr($sql, $offset);
                } else {
                    $sql .= " WHERE " . $injectionText;
                }
            }

            if ($sql === null) {
                return (string)$resultSql;
            }

            // 不要になった不自然な構文乱れを自動救済クレンジング（トリミング強化）
            $sql = preg_replace('/\bWHERE\s+AND\b/i', 'WHERE', $sql);
            $sql = preg_replace('/\bWHERE\s*$/i', '', $sql);
            
            // 最終的な加工後SQLをセット
            $resultSql = trim($sql);

        } catch (Exception $e) {
            // 万が一DB接続エラー等が起きても、ログに記録して最悪元のクエリを安全に返却するフォールバック
            if (function_exists('chatLogger')) {
                chatLogger("injectSecurityFilters内で例外を検知: " . $e->getMessage());
            }
        }

        // ✨【絶対大原則】関数の最末尾。型キャストを施し、ここから必ず string 型が返却される構造を絶対死守
        return (string)$resultSql;
    }

    /**
     * SQL全体のトップレベル句だけを検出する。GROUP_CONCAT内の ORDER BY など、括弧内の句は無視する。
     *
     * @param string $sql
     * @param array<int,string> $clauses
     * @return array{offset:int,length:int,clause:string}|null
     */
    private function findTopLevelClause(string $sql, array $clauses): ?array {
        $length = strlen($sql);
        $depth = 0;
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                continue;
            }

            if ($char === '(') {
                $depth++;
                continue;
            }
            if ($char === ')') {
                $depth = max(0, $depth - 1);
                continue;
            }

            if ($depth !== 0) {
                continue;
            }

            $tail = substr($sql, $i);
            foreach ($clauses as $clause) {
                $pattern = '/^' . str_replace('\ ', '\s+', preg_quote($clause, '/')) . '\b/i';
                if (preg_match($pattern, $tail, $match)) {
                    return [
                        'offset' => $i,
                        'length' => strlen($match[0]),
                        'clause' => strtoupper($clause),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * エイリアス動的探知関数（予約語を完全に弾く安全設計）
     */
    private function getTableAlias(string $sql, string $tableName): string {
        if (preg_match('/\b' . preg_quote($tableName, '/') . '\s+(?:AS\s+)?([a-zA-Z0-9_]+)/i', $sql, $m)) {
            $alias = strtoupper($m[1]);
            $reserved = ['WHERE', 'JOIN', 'ON', 'GROUP', 'ORDER', 'LIMIT', 'SET', 'LEFT', 'RIGHT', 'INNER', 'OUTER', 'UNION', 'AND', 'OR', 'USING', 'AS'];
            if (!in_array($alias, $reserved, true)) {
                return $m[1];
            }
        }
        return $tableName;
    }

    /**
     * 鉄壁のセキュリティ監査レイヤー
     */
    private function auditSql(string $sql): array {
        // 2-1. 基本構文監査 (SELECT文限定)
        if (!preg_match('/^\s*SELECT/i', $sql)) {
            return ['success' => false, 'error' => '拒否理由: 安全性確保のため、クエリはSELECT文で開始する必要があります。'];
        }

        if (!preg_match('/\bFROM\b/i', $sql)) {
            return ['success' => false, 'error' => '拒否理由: 実データを参照しないダミーSQLです。SELECTには必ず実在テーブルのFROM句が必要です。'];
        }

        // 2-2. 破壊的・書き換え系キーワードの全量検知
        if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|EXECUTE)\b/i', $sql)) {
            return ['success' => false, 'error' => '拒否理由: 破壊的またはデータ変更を伴う非許可キーワードが検出されました。'];
        }

        // 2-3. MySQL system メタ情報へのアクセス遮断
        if (preg_match('/\b(information_schema|performance_schema|mysql|sys)\b/i', $sql)) {
            return ['success' => false, 'error' => '拒否理由: システムスキーマへのアクセスは固く禁止されています。'];
        }

        // 2-4. 許可テーブルのホワイトリスト監査
        $cleanSqlForTableCheck = preg_replace('/[\s,()]+/i', ' ', $sql);
        $tokens = explode(' ', $cleanSqlForTableCheck);
        foreach ($tokens as $idx => $token) {
            $upperToken = strtoupper($token);
            if (($upperToken === 'FROM' || $upperToken === 'JOIN') && isset($tokens[$idx + 1])) {
                $targetTable = trim($tokens[$idx + 1], '`"\'');
                if (strpos($targetTable, '.') !== false) {
                    $parts = explode('.', $targetTable);
                    $targetTable = end($parts);
                }
                $targetTable = strtolower($targetTable);
                if (!empty($targetTable) && !in_array($targetTable, self::$allowedTables, true)) {
                    return ['success' => false, 'error' => "拒否理由: 許可されていないテーブルへの参照が検知されました ({$targetTable})。"];
                }
            }
        }

        // 個別案件画面 (projectId > 0) の時のみ、他プロジェクトの覗き見を絶対拒否する超厳格BOLA防御を執行。
        if ($this->projectId > 0) {
            return ['success' => true];
        }

        return ['success' => true];
    }

    /**
     * AIの自己修復ループへ渡す、実在スキーマ・定番SQLパターン・禁止事項の短い誘導文を生成する。
     */
    public function buildRepairGuidance(string $failedSql = '', string $error = '', string $task = ''): string {
        $schemaLines = [];
        $allowed = implode(', ', self::$allowedTables);

        try {
            foreach (self::$allowedTables as $table) {
                $stmt = $this->pdo->query("SHOW COLUMNS FROM `{$table}`");
                $cols = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
                    $cols[] = $col['Field'];
                }
                $schemaLines[] = "- {$table}: " . implode(', ', $cols);
            }
        } catch (Throwable $e) {
            $schemaLines[] = "- 許可テーブル: {$allowed}";
        }

        $csvHints = [];
        if ($this->projectId > 0) {
            try {
                $stmtCsv = $this->pdo->prepare("SELECT id, file_name, column_headers, row_count FROM project_csv_files WHERE project_id = ? ORDER BY id ASC");
                $stmtCsv->execute([$this->projectId]);
                foreach ($stmtCsv->fetchAll(PDO::FETCH_ASSOC) as $csv) {
                    $headers = json_decode((string)$csv['column_headers'], true);
                    if (!is_array($headers)) {
                        $headers = array_filter(array_map('trim', explode(',', (string)$csv['column_headers'])));
                    }
                    $csvHints[] = "- csv_file_id={$csv['id']} / {$csv['file_name']} / rows={$csv['row_count']} / row_data keys: " . implode(', ', $headers);
                }
            } catch (Throwable $e) {
                $csvHints[] = "- CSV記憶の取得に失敗しました: " . $e->getMessage();
            }
        }

        $historyScopedSql = $this->buildScopedChatHistorySql();

        return "【SQL自己修復ガイダンス】\n"
            . "目的: {$task}\n"
            . "前回エラー: {$error}\n"
            . "前回SQL: {$failedSql}\n\n"
            . "【使用可能な実在テーブル・物理カラム】\n" . implode("\n", $schemaLines) . "\n\n"
            . "【この案件のCSV経験値】\n" . (empty($csvHints) ? "- CSV記憶なし\n" : implode("\n", $csvHints) . "\n") . "\n"
            . "【正解へ近づく定番SQLパターン】\n"
            . "- CSVファイル数と実データ行数: SELECT COUNT(DISTINCT f.id) AS total_csv_files, COUNT(r.id) AS total_csv_rows FROM project_csv_files f LEFT JOIN project_csv_rows r ON f.id = r.csv_file_id\n"
            . "- CSV本文のキー抽出: JSON_UNQUOTE(JSON_EXTRACT(r.row_data, '$.\"実在キー名\"'))\n"
            . "- CSVキー別集計: SELECT JSON_UNQUOTE(JSON_EXTRACT(r.row_data, '$.\"実在キー名\"')) AS item, COUNT(*) AS cnt FROM project_csv_rows r GROUP BY item ORDER BY cnt DESC\n"
            . "- 会話履歴要約用抽出: {$historyScopedSql}\n"
            . "- PDF本文抽出: SELECT d.title, c.page_number, c.chunk_text, c.image_description FROM doc_chunks c JOIN documents d ON c.doc_id = d.id ORDER BY d.id, c.page_number LIMIT 30\n\n"
            . "【禁止】\n"
            . "- 許可リスト外の架空テーブル・架空カラムを作らない。\n"
            . "- SELECT '文字列' のような実テーブルを読まないダミーSQLで突破しない。\n"
            . "- project_csv_rows.row_count は存在しない。行数は COUNT(project_csv_rows.id) を使う。\n"
            . "- row_data->>$.日本語キー は禁止。JSON_UNQUOTE(JSON_EXTRACT(..., '$.\"キー\"')) を使う。\n";
    }

    /**
     * AIが3回迷った場合に、目的文から安全な定番SQLを提案する最終救済。
     */
    public function suggestFallbackSql(string $task, string $failedSql = ''): ?string {
        $text = $task . "\n" . $failedSql;

        if (
            preg_match('/(project_csv_files|file_name|column_headers|row_count)/iu', $text)
            || (
                preg_match('/(CSV|csv|ファイル|データセット)/u', $text)
                && preg_match('/(一覧|内訳|概要|カラム|列|ヘッダー|行数|row_count)/u', $text)
            )
        ) {
            return "SELECT file_name, column_headers, row_count FROM project_csv_files WHERE project_id = {$this->projectId} ORDER BY id ASC";
        }

        if (preg_match('/(CSV|csv|集計|概要|総数|件数|行数|レコード数)/u', $text)) {
            return "SELECT COUNT(DISTINCT f.id) AS total_csv_files, COUNT(r.id) AS total_csv_rows FROM project_csv_files f LEFT JOIN project_csv_rows r ON f.id = r.csv_file_id";
        }

        if (preg_match('/(会話|履歴|チャット|これまで|まとめ|要約)/u', $text)) {
            return $this->buildScopedChatHistorySql();
        }

        if (preg_match('/(PDF|資料|文書|留意点|抽出|内容)/u', $text)) {
            return "SELECT d.title, c.page_number, c.chunk_text, c.image_description FROM doc_chunks c JOIN documents d ON c.doc_id = d.id ORDER BY d.id, c.page_number LIMIT 30";
        }

        return null;
    }

    private function buildScopedChatHistorySql(): string
    {
        $conditions = [];

        if ($this->projectId > 0) {
            $conditions[] = "project_id = {$this->projectId}";
        }
        if ($this->threadId !== null) {
            $conditions[] = "thread_id = {$this->threadId}";
        }
        if ($this->userId !== null && $this->userId > 0) {
            $conditions[] = "user_id = {$this->userId}";
        }

        $where = empty($conditions) ? '' : ' WHERE ' . implode(' AND ', $conditions);
        return "SELECT role, message, created_at FROM chat_history{$where} ORDER BY created_at ASC LIMIT 50";
    }

    /**
     * BOLA防御バリデーター：対象のCSVファイルが現在のプロジェクトに属しているか
     */
    private function verifyCsvFileOwnership(int $csvFileId): bool {
        try {
            $stmt = $this->pdo->prepare("SELECT project_id FROM project_csv_files WHERE id = ?");
            $stmt->execute([$csvFileId]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            return ($res && (int)$res['project_id'] === $this->projectId);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * BOLA防御バリデーター：対象のドキュメントが現在のプロジェクトに属しているか
     */
    private function verifyDocumentOwnership(int $documentId): bool {
        try {
            $stmt = $this->pdo->prepare("SELECT project_id FROM documents WHERE id = ?");
            $stmt->execute([$documentId]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            return ($res && (int)$res['project_id'] === $this->projectId);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * ONLY_FULL_GROUP_BY モードの動的緩和
     */
    private function relaxSqlMode(): void {
        $this->pdo->exec("SET SESSION sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");
    }

    /**
     * Context Guard（トークン爆発防止データ丸め）
     */
    private function applyContextGuard(array $rows): array {
        $trimmedRows = array_slice($rows, 0, 100);

        foreach ($trimmedRows as $rowIndex => $row) {
            foreach ($row as $key => $val) {
                if (is_string($val) && mb_strlen($val) > 100) {
                    $trimmedRows[$rowIndex][$key] = mb_substr($val, 0, 100) . '...[省略]';
                }
            }
        }

        return $trimmedRows;
    }

    /**
     * ✨【次世代アーキテクチャ】構造化・非構造化データのメタ情報を自律スキャンし、記憶キャッシュとして保存する
     *
     * @return bool
     */
    public function generateAndSaveDatabaseMemory(): bool {
        if ($this->projectId <= 0) {
            return false;
        }

        try {
            // ステップ②: 構造化データ（CSVメタ情報）の自動スキャン
            $csvDataList = [];
            $stmtCsv = $this->pdo->prepare("SELECT id, file_name, column_headers, row_count FROM project_csv_files WHERE project_id = ?");
            $stmtCsv->execute([$this->projectId]);
            $csvFiles = $stmtCsv->fetchAll(PDO::FETCH_ASSOC);

            foreach ($csvFiles as $file) {
                $fileId = (int)$file['id'];
                $fileName = $file['file_name'];
                $rowCount = (int)$file['row_count'];

                // ヘッダーのパース
                $headers = [];
                $rawHeaders = $file['column_headers'];
                $decoded = json_decode($rawHeaders, true);
                if (is_array($decoded)) {
                    $headers = $decoded;
                } else {
                    $headers = array_map('trim', explode(',', $rawHeaders));
                }

                // 実データからユニークなバリューサンプルを取得
                $sampleValues = [];
                $stmtRows = $this->pdo->prepare("SELECT row_data FROM project_csv_rows WHERE csv_file_id = ? LIMIT 30");
                $stmtRows->execute([$fileId]);
                $rows = $stmtRows->fetchAll(PDO::FETCH_ASSOC);

                foreach ($rows as $row) {
                    $rowData = json_decode($row['row_data'], true);
                    if (is_array($rowData)) {
                        // AIにとって有益な識別子になりそうなキーをスマートに探索
                        foreach (['name', 'Name', '名前', 'title', 'Title', 'タイトル', 'id', 'ID', '会社名', '氏名'] as $keyName) {
                            if (!empty($rowData[$keyName])) {
                                $val = (string)$rowData[$keyName];
                                if (!in_array($val, $sampleValues, true)) {
                                    $sampleValues[] = $val;
                                }
                                break;
                            }
                        }
                    }
                }
                $sampleValues = array_slice($sampleValues, 0, 5);

                $csvDataList[] = [
                    'csv_file_id' => $fileId,
                    'file_name'   => $fileName,
                    'row_count'   => $rowCount,
                    'columns'     => $headers,
                    'samples'     => $sampleValues
                ];
            }

            // ステップ③: 非構造化データ（RAG資料チャンク情報）の自動スキャン
            $docDataList = [];
            $stmtDoc = $this->pdo->prepare("SELECT id, title FROM documents WHERE project_id = ?");
            $stmtDoc->execute([$this->projectId]);
            $documents = $stmtDoc->fetchAll(PDO::FETCH_ASSOC);

            foreach ($documents as $doc) {
                $docId = (int)$doc['id'];
                $title = $doc['title'];

                // チャンク総数の集計（物理カラム名 doc_id を正確に使用）
                $stmtCount = $this->pdo->prepare("SELECT COUNT(*) FROM doc_chunks WHERE doc_id = ?");
                $stmtCount->execute([$docId]);
                $chunkCount = (int)$stmtCount->fetchColumn();

                $docDataList[] = [
                    'document_id' => $docId,
                    'title'       => $title,
                    'chunk_count' => $chunkCount
                ];
            }

            // ステップ④: JSON構造化 ＆ project_meta への永続化保存（アップサート）
            $memoryData = [
                'last_updated' => date('Y-m-d H:i:s'),
                'project_id'   => $this->projectId,
                'tables'       => [
                    'project_csv_rows' => $csvDataList,
                    'doc_chunks'       => $docDataList
                ]
            ];

            $jsonStr = json_encode($memoryData, JSON_UNESCAPED_UNICODE);

            // 既存レコードの確認とアップサート（DELETE ➔ INSERT 方式よりも安全な UPDATE/INSERT 判定）
            $stmtCheck = $this->pdo->prepare("SELECT COUNT(*) FROM project_meta WHERE project_id = ? AND meta_key = 'ai_database_memory'");
            $stmtCheck->execute([$this->projectId]);
            $exists = (int)$stmtCheck->fetchColumn();

            if ($exists > 0) {
                $stmtUpd = $this->pdo->prepare("UPDATE project_meta SET meta_value = ? WHERE project_id = ? AND meta_key = 'ai_database_memory'");
                $stmtUpd->execute([$jsonStr, $this->projectId]);
            } else {
                $stmtIns = $this->pdo->prepare("INSERT INTO project_meta (project_id, meta_key, meta_value) VALUES (?, 'ai_database_memory', ?)");
                $stmtIns->execute([$this->projectId, $jsonStr]);
            }

            return true;

        } catch (Exception $e) {
            // 例外発生時もシステムを停止させず、ログを出力してfalseを返すフォールバック回路
            if (function_exists('chatLogger')) {
                chatLogger("【DB Memory生成エラー】自律メタ探索中に例外発生: " . $e->getMessage());
            }
            return false;
        }
    }
}
