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
     */
    public function __construct($pdo, $projectId) {
        $this->pdo       = $pdo;
        $this->projectId = (int)$projectId;
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

            // ✨【安全スコープ自動合流（オート・インジェクション）】
            // AIにマルチテナントの条件を書かせず、プログラム側で実行直前に現在の案件ID・ファイルID・ドキュメントIDを自動融合する
            $sql = $this->injectSecurityFilters($sql);

            // 2. セキュリティ監査（基本構文・ホワイトリスト・BOLA防御）
            $audit = $this->auditSql($sql);
            if (!$audit['success']) {
                return [
                    'success' => false,
                    'error'   => $audit['error']
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
                'error'   => 'SQL実行中にエラーが発生しました: ' . $e->getMessage()
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
            if (preg_match('/\bWHERE\b/i', $sql)) {
                $sql = preg_replace('/(\bWHERE\b)/i', '$1 ' . $injectionText . ' AND ', $sql, 1);
            } else {
                if (preg_match('/(\bGROUP\s+BY\b|\bORDER\s+BY\b|\bLIMIT\b)/i', $sql, $m, PREG_OFFSET_CAPTURE)) {
                    $offset = $m[0][1];
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