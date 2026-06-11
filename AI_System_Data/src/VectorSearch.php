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

    private function extractSearchTerms(string $queryText): array {
        $terms = [];
        $source = mb_strtolower($queryText);

        $knownTerms = [
            'pdf', 'csv', '資料', '図面', '報告書', '仕様書', '留意点', '課題', '提案',
            '防火', '構造', '所在地', '工事', '建築', '設計', 'グラフ', '集計',
            '概要', '要約', '項目', '内容', 'コメント', 'faq'
        ];
        foreach ($knownTerms as $term) {
            if (mb_stripos($source, mb_strtolower($term)) !== false) {
                $terms[$term] = true;
            }
        }

        if (preg_match_all('/[A-Za-z0-9_]{2,}|[\p{Han}\p{Katakana}]{2,}/u', $queryText, $matches)) {
            foreach ($matches[0] as $term) {
                $term = trim((string)$term);
                if ($term === '' || mb_strlen($term) < 2) {
                    continue;
                }
                $terms[$term] = true;
                if (count($terms) >= 10) {
                    break;
                }
            }
        }

        return array_slice(array_keys($terms), 0, 10);
    }

    private function buildSearchSql(int $projectId, ?int $targetPage, array $terms, bool $includeImageDescription = true, int $candidateLimit = 800): array {
        $imageColumn = $includeImageDescription ? ', c.image_description' : '';
        $sql = "SELECT c.id, c.doc_id, d.title, d.file_path, c.chunk_text, c.page_number, c.embedding{$imageColumn}
                FROM doc_chunks c
                JOIN documents d ON c.doc_id = d.id
                WHERE d.project_id = ?";
        $params = [$projectId];

        if ($targetPage !== null) {
            $sql .= " AND c.page_number = ?";
            $params[] = $targetPage;
        } elseif (!empty($terms)) {
            $likeConditions = ["c.page_number = 0"];
            foreach ($terms as $term) {
                $likeConditions[] = "c.chunk_text LIKE ?";
                $params[] = '%' . $term . '%';
                if ($includeImageDescription) {
                    $likeConditions[] = "c.image_description LIKE ?";
                    $params[] = '%' . $term . '%';
                }
                $likeConditions[] = "d.title LIKE ?";
                $params[] = '%' . $term . '%';
            }
            $sql .= " AND (" . implode(' OR ', $likeConditions) . ")";
            $sql .= " ORDER BY CASE WHEN c.page_number = 0 THEN 0 ELSE 1 END, c.id DESC LIMIT " . max(50, $candidateLimit);
        }

        return [$sql, $params];
    }

    /**
     * 質問ベクトルに近いチャンクを検索
     * @param array $queryEmbedding 質問のベクトルデータ
     * @param int $projectId プロジェクトID
     * @param int $limit 取得上限数
     * @param int|null $targetPage 絞り込み対象のページ番号
     */
    public function search(array $queryEmbedding, int $projectId, int $limit = 6, ?int $targetPage = null, string $queryText = ''): array {
        self::writeDebugLog("=== セマンティック検索 開始 ===");
        self::writeDebugLog("Searching for project_id: $projectId | Target Page: " . ($targetPage !== null ? $targetPage : 'ALL_PAGES'));

        $terms = $targetPage === null ? $this->extractSearchTerms($queryText) : [];
        $usedKeywordPrefilter = !empty($terms);
        if ($usedKeywordPrefilter) {
            self::writeDebugLog("Keyword prefilter terms: " . implode(', ', $terms));
        }

        $stmt = null;
        try {
            [$sql, $params] = $this->buildSearchSql($projectId, $targetPage, $terms, true);
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
        } catch (PDOException $e) {
            // カラムが存在しない場合などのフォールバック
            self::writeDebugLog("Column 'image_description' might be missing, retrying with minimal columns...");

            if ($e->getCode() == '42S22') {
                [$sql, $params] = $this->buildSearchSql($projectId, $targetPage, $terms, false);
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
            } else {
                self::writeDebugLog("SQL Error in search", $e->getMessage());
                throw $e;
            }
        }
        
        $results = [];
        $fetchedCount = 0;
        $materialFallbackCount = 0;

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $fetchedCount++;
            $docEmbedding = json_decode($row['embedding'], true);
            $filePath = strtolower((string)($row['file_path'] ?? ''));
            $isMaterialMarkdown = str_ends_with($filePath, '.md');
            $sourceType = $this->resolveSourceType((string)($row['title'] ?? ''), $filePath);

            if (!$docEmbedding) {
                if ($isMaterialMarkdown && !empty($terms)) {
                    $fallbackScore = $this->scoreMaterialKeywordFallback(
                        $terms,
                        (string)($row['title'] ?? ''),
                        (string)($row['chunk_text'] ?? ''),
                        (string)($row['image_description'] ?? '')
                    );
                    if ($fallbackScore > 0) {
                        $materialFallbackCount++;
                        $results[] = [
                            'id' => $row['id'],
                            'document_id' => $row['doc_id'],
                            'title' => $row['title'],
                            'content' => $row['chunk_text'],
                            'page_number' => $row['page_number'] ?? '-',
                            'image_description' => $row['image_description'] ?? null,
                            'score' => $fallbackScore,
                            'match_type' => 'material_keyword_fallback',
                            'source_type' => $sourceType,
                        ];
                    }
                }
                continue;
            }

            $score = $this->cosineSimilarity($queryEmbedding, $docEmbedding);
            $score += $this->scoreMaterialSemanticBoost(
                $terms,
                $sourceType,
                (string)($row['title'] ?? ''),
                (string)($row['chunk_text'] ?? '')
            );
            $results[] = [
                'id' => $row['id'],
                'document_id' => $row['doc_id'],
                'title' => $row['title'],
                'content' => $row['chunk_text'],
                'page_number' => $row['page_number'] ?? '-',
                'image_description' => $row['image_description'] ?? null,
                'score' => $score,
                'match_type' => 'semantic_vector',
                'source_type' => $sourceType,
            ];
        }

        self::writeDebugLog("ベクトルマッチング結果: DBレコード抽出数 = $fetchedCount 件, 有効ベクトル数 = " . count($results) . " 件");
        if ($materialFallbackCount > 0) {
            self::writeDebugLog("資料メモのキーワードfallback一致: {$materialFallbackCount} 件");
        }

        if ($usedKeywordPrefilter && count($results) === 0) {
            self::writeDebugLog("Keyword prefilter returned no valid vectors. Falling back to full project vector scan.");
            return $this->search($queryEmbedding, $projectId, $limit, $targetPage, '');
        }

        // 類似度スコアが高い順にソート
        usort($results, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        // ページ指定されている場合はリミットを無視してそのページの全チャンクを返すことで漏れを防ぐ
        $finalResults = ($targetPage !== null) ? $results : array_slice($results, 0, $limit);
        $sourceMix = $this->summarizeSourceTypes($finalResults);
        if ($sourceMix !== []) {
            self::writeDebugLog("上位ヒットのソース内訳", $sourceMix);
        }
        $materialTitles = $this->collectMaterialTitles($finalResults);
        if ($materialTitles !== []) {
            self::writeDebugLog("上位ヒットに含まれた資料メモ", $materialTitles);
        }

        // 【デバッグ強化】ヒットした上位ドキュメントとコサイン類似度スコアをログにわかりやすく明記
        self::writeDebugLog("--- セマンティック検索 類似度ランキング（TOP " . count($finalResults) . "） ---");
        foreach ($finalResults as $rank => $item) {
            $rankNum = $rank + 1;
            $title = $item['title'];
            $page = $item['page_number'];
            $score = number_format($item['score'], 4);
            $matchType = (string)($item['match_type'] ?? 'semantic_vector');
            $sourceType = (string)($item['source_type'] ?? 'document');
            $excerpt = mb_strimwidth(preg_replace('/\s+/', ' ', $item['content']), 0, 80, '...');

            self::writeDebugLog("[Rank #$rankNum] Score: $score | Match: $matchType | Source: $sourceType | Doc: $title (P.$page) | Text: \"$excerpt\"");
        }
        self::writeDebugLog("=== セマンティック検索 終了 ===");

        return $finalResults;
    }

    private function scoreMaterialKeywordFallback(array $terms, string $title, string $chunkText, string $imageDescription = ''): float
    {
        $title = mb_strtolower($title);
        $chunkText = mb_strtolower($chunkText);
        $imageDescription = mb_strtolower($imageDescription);
        $headingText = mb_strtolower(implode(' ', $this->extractMarkdownHeadings($chunkText)));

        $score = 0.0;
        foreach ($terms as $term) {
            $term = mb_strtolower((string)$term);
            if ($term === '') {
                continue;
            }

            if (mb_stripos($title, $term) !== false) {
                $score += 0.12;
            }
            if (mb_stripos($chunkText, $term) !== false) {
                $score += 0.06;
            }
            if ($headingText !== '' && mb_stripos($headingText, $term) !== false) {
                $score += 0.08;
            }
            if ($imageDescription !== '' && mb_stripos($imageDescription, $term) !== false) {
                $score += 0.04;
            }
        }

        return min(0.42, $score);
    }

    private function scoreMaterialSemanticBoost(array $terms, string $sourceType, string $title, string $chunkText): float
    {
        if ($sourceType !== 'material_note' || empty($terms)) {
            return 0.0;
        }

        $title = mb_strtolower($title);
        $chunkText = mb_strtolower($chunkText);
        $headingText = mb_strtolower(implode(' ', $this->extractMarkdownHeadings($chunkText)));
        $score = 0.0;
        foreach ($terms as $term) {
            $term = mb_strtolower((string)$term);
            if ($term === '') {
                continue;
            }

            if (mb_stripos($title, $term) !== false) {
                $score += 0.03;
            }
            if ($headingText !== '' && mb_stripos($headingText, $term) !== false) {
                $score += 0.03;
            }
            if (mb_stripos($chunkText, $term) !== false) {
                $score += 0.01;
            }
        }

        return min(0.08, $score);
    }

    private function resolveSourceType(string $title, string $filePath): string
    {
        if (str_ends_with($filePath, '.md')) {
            return 'material_note';
        }
        if (str_ends_with($filePath, '.pdf')) {
            return 'pdf';
        }
        if (str_ends_with($filePath, '.csv') || str_starts_with($title, '[CSVデータ]')) {
            return 'csv';
        }

        return 'document';
    }

    private function summarizeSourceTypes(array $results): array
    {
        $summary = [];
        foreach ($results as $item) {
            $type = (string)($item['source_type'] ?? 'document');
            $summary[$type] = ($summary[$type] ?? 0) + 1;
        }
        return $summary;
    }

    private function collectMaterialTitles(array $results, int $limit = 5): array
    {
        $titles = [];
        foreach ($results as $item) {
            if (($item['source_type'] ?? '') !== 'material_note') {
                continue;
            }
            $title = trim((string)($item['title'] ?? ''));
            if ($title === '' || in_array($title, $titles, true)) {
                continue;
            }
            $titles[] = $title;
            if (count($titles) >= $limit) {
                break;
            }
        }
        return $titles;
    }

    private function extractMarkdownHeadings(string $content, int $limit = 4): array
    {
        if ($content === '') {
            return [];
        }

        preg_match_all('/^\s{0,3}#{1,6}\s+(.+)$/mu', $content, $matches);
        $headings = [];
        foreach (($matches[1] ?? []) as $heading) {
            $heading = trim(strip_tags((string)$heading));
            if ($heading === '') {
                continue;
            }
            $headings[] = $heading;
            if (count($headings) >= $limit) {
                break;
            }
        }

        return $headings;
    }
}
