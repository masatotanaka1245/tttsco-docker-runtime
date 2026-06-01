<?php
/**
 * VectorSearch クラス
 * データベースからベクトル類似度に基づき、関連するチャンク（および画像説明）を取得します。
 * [改修内容]
 * - $targetPage パラメータを追加し、指定されたページ番号のみに検索対象を完全に絞り込む
 * - 【追加】「フル思考モード」の内部プロセス、類似度詳細スコア、サブクエリを chat_debug.log に詳細出力する機能を搭載
 */
require_once __DIR__ . '/AppLogger.php';

class VectorSearch {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * デバッグログ出力用 (chat.phpや他のAPIからも呼び出せるようにパブリックな静的メソッドに拡張)
     */
    public static function writeDebugLog($message, $data = null) {
        $content = '[VectorSearch] ' . (string)$message;
        if ($data) {
            $content .= " | Data: " . (is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : $data);
        }
        if (!shouldWriteChatLog($content)) {
            return;
        }
        appLog('chat_debug.log', $content);
    }

    /**
     * 【新機能】フル思考モード（多段階推論）のライフサイクルを一元追跡するための特化型ログ関数
     * chat.php の因数分解、個別検証、最終マージのフェーズで呼び出します。
     * * @param string $sessionId   推論セッションID (reasoning_id)
     * @param int    $stepNumber  ステップ番号 (1, 2, 3... 99は最終マージ)
     * @param string $phase       実行フェーズ (DECOMPOSITION, SUB-RAG, SYNTHESIS 等)
     * @param string $message     ログ内容
     * @param mixed  $data        詳細オブジェクトデータ
     */
    public static function writeReasoningLog(string $sessionId, int $stepNumber, string $phase, string $message, $data = null) {
        $stepLabel = ($stepNumber === 99) ? "SYNTHESIS" : "STEP-{$stepNumber}";
        self::writeDebugLog("[REASONING-PIPELINE] [Session: $sessionId] [$stepLabel] [$phase] $message", $data);
    }

    /**
     * コサイン類似度を計算する
     */
    private function cosineSimilarity(array $vecA, array $vecB): float {
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        foreach ($vecA as $i => $valA) {
            $valB = $vecB[$i] ?? 0;
            $dotProduct += $valA * $valB;
            $normA += $valA * $valA;
            $normB += $valB * $valB;
        }
        if ($normA == 0 || $normB == 0) return 0.0;
        return $dotProduct / (sqrt($normA) * sqrt($normB));
    }

    /**
     * 質問ベクトルに近いチャンクを検索
     * @param array $queryEmbedding 質問のベクトルデータ
     * @param int $projectId プロジェクトID
     * @param int $limit 取得上限数
     * @param int|null $targetPage 絞り込み対象のページ番号
     */
    public function search(array $queryEmbedding, int $projectId, int $limit = 6, ?int $targetPage = null): array {
        self::writeDebugLog("=== セマンティック検索 開始 ===");
        self::writeDebugLog("Searching for project_id: $projectId | Target Page: " . ($targetPage !== null ? $targetPage : 'ALL_PAGES'));
        
        $stmt = null;
        try {
            // ベースSQLの組み立て
            $sql = "SELECT c.id, c.doc_id, d.title, c.chunk_text, c.page_number, c.embedding, c.image_description 
                    FROM doc_chunks c
                    JOIN documents d ON c.doc_id = d.id
                    WHERE d.project_id = ?";
            
            $params = [$projectId];

            // 特定のページが指定されている場合、SQLレベルで対象ページを厳格にロックオン
            if ($targetPage !== null) {
                $sql .= " AND c.page_number = ?";
                $params[] = $targetPage;
            }
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
        } catch (PDOException $e) {
            // カラムが存在しない場合などのフォールバック
            self::writeDebugLog("Column 'image_description' might be missing, retrying with minimal columns...");
            
            if ($e->getCode() == '42S22') {
                $sql = "SELECT c.id, c.doc_id, d.title, c.chunk_text, c.embedding 
                        FROM doc_chunks c
                        JOIN documents d ON c.doc_id = d.id
                        WHERE d.project_id = ?";
                $params = [$projectId];
                if ($targetPage !== null) {
                    $sql .= " AND c.page_number = ?";
                    $params[] = $targetPage;
                }
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
            } else {
                self::writeDebugLog("SQL Error in search", $e->getMessage());
                throw $e;
            }
        }
        
        $results = [];
        $fetchedCount = 0;
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $fetchedCount++;
            $docEmbedding = json_decode($row['embedding'], true);
            if (!$docEmbedding) {
                continue;
            }

            $score = $this->cosineSimilarity($queryEmbedding, $docEmbedding);
            $results[] = [
                'id' => $row['id'],
                'document_id' => $row['doc_id'],
                'title' => $row['title'],
                'content' => $row['chunk_text'],
                'page_number' => $row['page_number'] ?? '-',
                'image_description' => $row['image_description'] ?? null,
                'score' => $score
            ];
        }

        self::writeDebugLog("ベクトルマッチング結果: DBレコード抽出数 = $fetchedCount 件, 有効ベクトル数 = " . count($results) . " 件");

        // 類似度スコアが高い順にソート
        usort($results, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        // ページ指定されている場合はリミットを無視してそのページの全チャンクを返すことで漏れを防ぐ
        $finalResults = ($targetPage !== null) ? $results : array_slice($results, 0, $limit);

        // 【デバッグ強化】ヒットした上位ドキュメントとコサイン類似度スコアをログにわかりやすく明記
        self::writeDebugLog("--- セマンティック検索 類似度ランキング（TOP " . count($finalResults) . "） ---");
        foreach ($finalResults as $rank => $item) {
            $rankNum = $rank + 1;
            $title = $item['title'];
            $page = $item['page_number'];
            $score = number_format($item['score'], 4);
            $excerpt = mb_strimwidth(preg_replace('/\s+/', ' ', $item['content']), 0, 80, '...');
            
            self::writeDebugLog("[Rank #$rankNum] Score: $score | Doc: $title (P.$page) | Text: \"$excerpt\"");
        }
        self::writeDebugLog("=== セマンティック検索 終了 ===");

        return $finalResults;
    }
}
