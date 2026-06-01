<?php
/**
 * get_reasoning_status.php - AIの多段階推論(Agentic RAG)の進捗状況をデータベースから解析しリアルタイムに返却するAPI
 * [改修内容]
 * - テキストの進行メッセージだけでなく、進捗パーセンテージ、進捗数、現在のステータスを構造化データとしてJSONに拡張返却
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../src/Auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // 1. 認証チェック
    $auth = new Auth($pdo);
    if (!$auth->isLoggedIn()) {
        throw new Exception('Unauthorized');
    }

    // 2. セッションID（reasoning_id）の取得とサニタイズ
    $reasoning_id = filter_input(INPUT_GET, 'reasoning_id', FILTER_SANITIZE_SPECIAL_CHARS);
    if (empty($reasoning_id)) {
        throw new Exception('Invalid ID');
    }

    // 3. セッションIDに紐づくすべての推論ステップをDBから取得
    $stmt = $pdo->prepare("
        SELECT step_number, sub_query, sub_answer 
        FROM chat_reasoning_steps 
        WHERE session_id = ? 
        ORDER BY step_number ASC
    ");
    $stmt->execute([$reasoning_id]);
    $steps = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // デフォルトレスポンスデータ
    $progress = 0;
    $current_step_num = 0;
    $total_steps_num = 0;
    $processing_status = 'init'; // 'init', 'processing', 'synthesis', 'completed'

    // 4. 進捗ステータス分析ロジック
    if (empty($steps)) {
        // まだレコードが作成されていない場合
        $msg = '🧠 質問の意図を分析し、最適な網羅的検証シナリオを構築しています...';
        $progress = 5;
        $processing_status = 'init';
    } else {
        $total_sub_steps = 0;     // 最終統合(99)を除いた純粋なサブ検証クエリ数
        $completed_sub_steps = 0; // すでに検証が終わったクエリ数
        $current_step = null;     // 現在処理中のステップオブジェクト
        $synthesis_step = null;   // 最終統合ステップオブジェクト

        foreach ($steps as $s) {
            if ((int)$s['step_number'] === 99) {
                $synthesis_step = $s;
            } else {
                $total_sub_steps++;
                if ($s['sub_answer'] !== null) {
                    $completed_sub_steps++;
                } else if ($current_step === null) {
                    // まだ回答が入っていない最初のステップを「現在処理中」と判定
                    $current_step = $s;
                }
            }
        }

        $total_steps_num = $total_sub_steps;
        $current_step_num = $completed_sub_steps;

        // 5. ステータスに基づき、詳細なリアルタイム文言と％数値を自動構築
        if ($synthesis_step !== null) {
            if ($synthesis_step['sub_answer'] === null) {
                // すべての個別検証は完了しているが、最終マージ中
                $msg = "⚡ 全ての個別検証（計 {$total_sub_steps} グループ）が完了しました。現在、全データを対比マージし、網羅的な最終回答を構築しています...";
                $progress = 90;
                $processing_status = 'synthesis';
            } else {
                $msg = '✨ 最終回答の生成が完了しました。';
                $progress = 100;
                $processing_status = 'completed';
            }
        } else {
            // 現在個別のサブステップを検証中の場合
            if ($current_step !== null) {
                $current_num = $completed_sub_steps + 1;
                $current_step_num = $current_num;
                // クエリ名（例: 「〇〇」の検証（類似度第 1〜5 位データ））を取得
                $query_desc = htmlspecialchars($current_step['sub_query']);
                
                $msg = "🔄 [進捗: {$current_num}/{$total_sub_steps} グループ目] 🔍 {$query_desc} を精読し、論理的な技術検証を行っています...";
                
                // 進捗パーセンテージ計算（10%〜85%の間で段階的に増加させる。残り10%はsynthesis用に確保）
                $base_pct = 10;
                $step_share = 75 / max(1, $total_sub_steps);
                $progress = round($base_pct + ($completed_sub_steps * $step_share) + ($step_share * 0.3)); // 処理中のステップも少し進んでいると見なす
                $processing_status = 'processing';
            } else {
                // サブクエリは全部埋まっているが、99のレコードがまだインサートされていない極小の隙間
                $msg = '⚡ 個別検証データを整理し、回答の統合プロセスに移行しています...';
                $progress = 85;
                $processing_status = 'synthesis';
            }
        }
    }

    echo json_encode([
        'success'  => true, 
        'message'  => $msg,
        'progress' => (int)$progress,               // 進捗率 (0〜100)
        'current'  => (int)$current_step_num,       // 現在のステップ番号
        'total'    => (int)$total_steps_num,         // 総ステップ数
        'status'   => $processing_status            // 内部処理ステータス
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'error'   => $e->getMessage()
    ]);
}