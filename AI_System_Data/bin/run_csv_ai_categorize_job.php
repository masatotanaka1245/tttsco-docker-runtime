<?php
/**
 * run_csv_ai_categorize_job.php - CSVカテゴリ分けのバックグラウンド worker
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$jobId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($argv[1] ?? ''));
if ($jobId === '') {
    fwrite(STDERR, "job_id is required\n");
    exit(1);
}

$basePath = realpath(__DIR__ . '/..');
require_once $basePath . '/config/database.php';
require_once $basePath . '/src/AppLogger.php';
require_once $basePath . '/src/OllamaChatHelper.php';
require_once $basePath . '/src/ProjectCsvTableService.php';
require_once $basePath . '/src/CsvAiCategorizationJobService.php';

$jobService = new CsvAiCategorizationJobService($pdo, $basePath);
$csvService = new ProjectCsvTableService($pdo);
$job = $jobService->readJob($jobId);

if (!$job) {
    appLog('csv_ai_job.log', 'job not found', ['job_id' => $jobId]);
    exit(1);
}

$logJob = static function (string $message, array $context = []) use ($jobId): void {
    appLog('csv_ai_job.log', $message, array_merge([
        'job_id' => $jobId,
    ], $context));
};

$writeStatus = static function (array $status) use ($jobService, $jobId): void {
    $jobService->writeStatus($jobId, $status);
};

$callCategorizer = static function (string $host, string $model, string $system, string $user): array {
    $payload = OllamaChatHelper::buildChatPayload($model, $system, $user, 'json', [
        'temperature' => 0.0,
        'top_p' => 0.1,
        'num_ctx' => 4096,
    ]);

    $ch = curl_init(rtrim($host, '/') . '/api/chat');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);

    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($res === false || $code !== 200) {
        throw new RuntimeException("Ollama API error ({$code}): {$err}");
    }

    $json = json_decode($res, true);
    $content = $json['message']['content'] ?? '';
    $decoded = json_decode($content, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('AIの分類結果JSONを解析できませんでした。');
    }

    return [
        'category' => trim((string)($decoded['category'] ?? '')),
        'reason' => trim((string)($decoded['reason'] ?? '')),
    ];
};

$markCanceled = static function (int $current, int $total, int $outputCsvFileId = 0, string $reason = 'cancel requested') use ($jobService, $jobId, $writeStatus, $job, $logJob): never {
    $jobService->updateJob($jobId, [
        'status' => 'canceled',
        'finished_at' => date('c'),
    ]);
    $progress = $total > 0 ? (int)min(100, round(($current / $total) * 100)) : 0;
    $writeStatus([
        'job_id' => $jobId,
        'status' => 'canceled',
        'stage' => 'canceled',
        'progress' => $progress,
        'current' => $current,
        'total' => $total,
        'message' => 'カテゴリ分けジョブをキャンセルしました。',
        'error' => null,
        'project_id' => (int)($job['project_id'] ?? 0),
        'output_csv_file_id' => $outputCsvFileId,
        'cancel_requested' => true,
    ]);
    $logJob('job canceled', [
        'reason' => $reason,
        'current' => $current,
        'total' => $total,
        'progress' => $progress,
        'output_csv_file_id' => $outputCsvFileId,
    ]);
    exit(0);
};

try {
    $logJob('job worker booted', [
        'project_id' => (int)($job['project_id'] ?? 0),
        'source_csv_file_id' => (int)($job['source_csv_file_id'] ?? 0),
        'target_column' => (string)($job['target_column'] ?? ''),
        'model' => (string)($job['model'] ?? ''),
    ]);

    if ($jobService->isCancelRequested($jobId)) {
        $markCanceled(0, 0, (int)($job['output_csv_file_id'] ?? 0), 'cancel detected before processing started');
    }

    $jobService->updateJob($jobId, [
        'status' => 'processing',
        'started_at' => date('c'),
    ]);
    $logJob('job status switched to processing');

    $sourceCsv = $csvService->findById((int)$job['project_id'], (int)$job['source_csv_file_id']);
    if (!$sourceCsv) {
        throw new RuntimeException('元のCSVファイルが見つかりません。');
    }

    $headers = json_decode((string)($sourceCsv['column_headers'] ?? ''), true);
    if (!is_array($headers) || !in_array($job['target_column'], $headers, true)) {
        throw new RuntimeException('対象列が元CSVに存在しません。');
    }

    $outputHeaders = $headers;
    $categoryColumn = trim((string)$job['category_column_name']) !== '' ? trim((string)$job['category_column_name']) : 'AIカテゴリ';
    $reasonColumn = trim((string)$job['reason_column_name']) !== '' ? trim((string)$job['reason_column_name']) : 'AI分類理由';
    if (!in_array($categoryColumn, $outputHeaders, true)) {
        $outputHeaders[] = $categoryColumn;
    }
    if (!in_array($reasonColumn, $outputHeaders, true)) {
        $outputHeaders[] = $reasonColumn;
    }

    $outputFileName = trim((string)$job['output_file_name']);
    if ($outputFileName === '') {
        $outputFileName = preg_replace('/\.csv$/iu', '', (string)$sourceCsv['file_name']) . '_categorized.csv';
    }

    $outputCsv = $csvService->createManualCsv((int)$job['project_id'], $outputFileName, $outputHeaders);
    $jobService->updateJob($jobId, [
        'output_csv_file_id' => (int)$outputCsv['id'],
        'output_file_name' => (string)$outputCsv['file_name'],
    ]);

    $stmtRows = $pdo->prepare("
        SELECT row_index, row_data
        FROM project_csv_rows
        WHERE csv_file_id = ?
        ORDER BY row_index ASC
    ");
    $stmtRows->execute([(int)$job['source_csv_file_id']]);
    $rows = $stmtRows->fetchAll(PDO::FETCH_ASSOC);
    $total = count($rows);
    $logJob('source rows loaded', [
        'total' => $total,
        'output_csv_file_id' => (int)$outputCsv['id'],
    ]);

    $writeStatus([
        'job_id' => $jobId,
        'status' => 'processing',
        'stage' => 'prepare',
        'progress' => 0,
        'current' => 0,
        'total' => $total,
        'message' => 'カテゴリ分けジョブの準備を完了しました。',
        'error' => null,
        'project_id' => (int)$job['project_id'],
        'output_csv_file_id' => (int)$outputCsv['id'],
    ]);

    $system = "あなたはCSVデータのカテゴリ分類アシスタントです。\n"
        . "対象列の値を1つの短いカテゴリ名へ分類し、理由も1文で返してください。\n"
        . "出力はJSONのみで、必ず {\"category\":\"...\",\"reason\":\"...\"} の形式にしてください。";
    if (trim((string)$job['instructions']) !== '') {
        $system .= "\n【追加指示】\n" . trim((string)$job['instructions']);
    }

    foreach ($rows as $index => $row) {
        if ($jobService->isCancelRequested($jobId)) {
            $markCanceled($index, $total, (int)$outputCsv['id'], 'cancel detected during row loop');
        }

        $rowData = json_decode((string)($row['row_data'] ?? ''), true);
        if (!is_array($rowData)) {
            $rowData = [];
        }

        $targetValue = trim((string)($rowData[$job['target_column']] ?? ''));
        if ($targetValue === '') {
            $category = '';
            $reason = '対象列の値が空です。';
        } else {
            $user = "【対象列】\n{$job['target_column']}\n\n"
                . "【対象値】\n{$targetValue}\n\n"
                . "【行データ(JSON)】\n" . json_encode($rowData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $result = $callCategorizer((string)$job['ollama_host'], (string)$job['model'], $system, $user);
            $category = $result['category'] !== '' ? $result['category'] : '未分類';
            $reason = $result['reason'] !== '' ? $result['reason'] : '理由は返却されませんでした。';
        }

        $outputRow = $rowData;
        $outputRow[$categoryColumn] = $category;
        $outputRow[$reasonColumn] = $reason;
        $csvService->appendRow((int)$job['project_id'], (int)$outputCsv['id'], $outputRow);

        $current = $index + 1;
        $progress = $total > 0 ? (int)min(100, round(($current / $total) * 100)) : 100;
        $writeStatus([
            'job_id' => $jobId,
            'status' => 'processing',
            'stage' => 'categorizing',
            'progress' => $progress,
            'current' => $current,
            'total' => $total,
            'message' => "カテゴリ分け中 ({$current} / {$total})",
            'error' => null,
            'project_id' => (int)$job['project_id'],
            'output_csv_file_id' => (int)$outputCsv['id'],
        ]);
    }

    $jobService->updateJob($jobId, [
        'status' => 'completed',
        'finished_at' => date('c'),
    ]);
    $writeStatus([
        'job_id' => $jobId,
        'status' => 'completed',
        'stage' => 'completed',
        'progress' => 100,
        'current' => $total,
        'total' => $total,
        'message' => 'カテゴリ分けCSVを作成しました。',
        'error' => null,
        'project_id' => (int)$job['project_id'],
        'output_csv_file_id' => (int)$outputCsv['id'],
    ]);
    $logJob('job completed', [
        'total' => $total,
        'output_csv_file_id' => (int)$outputCsv['id'],
    ]);
} catch (Throwable $e) {
    $jobService->updateJob($jobId, [
        'status' => 'error',
        'finished_at' => date('c'),
    ]);
    $writeStatus([
        'job_id' => $jobId,
        'status' => 'error',
        'stage' => 'error',
        'progress' => 0,
        'current' => 0,
        'total' => 0,
        'message' => 'カテゴリ分けジョブでエラーが発生しました。',
        'error' => $e->getMessage(),
        'project_id' => (int)($job['project_id'] ?? 0),
        'output_csv_file_id' => (int)($job['output_csv_file_id'] ?? 0),
    ]);
    appLog('csv_ai_job.log', 'CSV AI categorize job failed', [
        'job_id' => $jobId,
        'error' => $e->getMessage(),
    ]);
    exit(1);
}

exit(0);
