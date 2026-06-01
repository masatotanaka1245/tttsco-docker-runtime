<?php
/**
 * EmbeddingEngine クラス
 * テキストを Ollama API を使用してベクトル化します。
 * タイムアウト設定を延長し、大容量のテキスト処理に対応させています。
 * ★[改善] ユーザー設定からのAIサーバーURL動的取得機能、および num_gpu=999 強制オフロードを実装
 */
class EmbeddingEngine {
    private $ollamaUrl;
    private $model;

    /**
     * コンストラクタ
     * 引数でホストが明示的に渡されない場合は、ユーザーのセッション（設定）から動的に取得します。
     */
    public function __construct(?string $ollamaHost = null, string $model = 'mxbai-embed-large') {
        // ホストが指定されていない場合はセッションから取得
        if ($ollamaHost === null) {
            if (session_status() === PHP_SESSION_NONE) {
                @session_start();
            }
            $ollamaHost = $_SESSION['ollama_host'] ?? 'http://127.0.0.1:11434';
            session_write_close(); // セッションロックの解放
        }

        $this->ollamaUrl = rtrim($ollamaHost, '/') . '/api/embeddings';
        $this->model = $model;
    }

    /**
     * テキストをベクトル化する
     *
     * @param string $text ベクトル化するテキスト
     * @return array 数値配列（ベクトルデータ）
     */
    public function embed(string $text): array {
        $curl = curl_init($this->ollamaUrl);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
        // ★VRAM保護・GPU最適化: options で num_gpu を明示的に指定
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
            'model' => $this->model,
            'prompt' => $text,
            'options' => [
                'num_gpu' => 999 // 可能な限り全レイヤーをGPUにオフロードさせる
            ]
        ]));
        
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        
        // --- 修正点: タイムアウトを15秒から120秒に延長 ---
        // PDFの1ページ分などの長いテキストを処理する際、
        // AIサーバーの計算時間を十分に確保するために必要です。
        curl_setopt($curl, CURLOPT_TIMEOUT, 120); 
        
        $res = curl_exec($curl);
        
        // 通信自体のエラーチェック
        if ($res === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new RuntimeException("Ollama API タイムアウトまたは接続エラー: {$error}");
        }
        
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        // HTTPステータスコードのチェック
        if ($httpCode !== 200) {
             throw new RuntimeException("Ollama API エラー (HTTP {$httpCode}): " . $res);
        }
        
        $data = json_decode($res, true);
        if (!isset($data['embedding'])) {
            throw new RuntimeException("Ollamaからベクトルデータが返却されませんでした。");
        }

        return $data['embedding']; 
    }
}