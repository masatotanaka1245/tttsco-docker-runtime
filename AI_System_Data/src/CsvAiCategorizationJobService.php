<?php
/**
 * CsvAiCategorizationJobService.php - CSV AIカテゴリ分けジョブの状態管理
 */

class CsvAiCategorizationJobService
{
    private PDO $pdo;
    private string $basePath;
    private string $jobDir;

    public function __construct(PDO $pdo, string $basePath)
    {
        $this->pdo = $pdo;
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
        $this->jobDir = $this->basePath . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'csv_ai_jobs';
        $this->ensureJobDirectory();
    }

    public function createJob(array $payload): array
    {
        $jobId = bin2hex(random_bytes(12));
        $now = date('c');

        $job = [
            'job_id' => $jobId,
            'project_id' => (int)($payload['project_id'] ?? 0),
            'user_id' => (int)($payload['user_id'] ?? 0),
            'source_csv_file_id' => (int)($payload['source_csv_file_id'] ?? 0),
            'source_file_name' => (string)($payload['source_file_name'] ?? ''),
            'target_column' => (string)($payload['target_column'] ?? ''),
            'output_file_name' => (string)($payload['output_file_name'] ?? ''),
            'category_column_name' => (string)($payload['category_column_name'] ?? 'AIカテゴリ'),
            'reason_column_name' => (string)($payload['reason_column_name'] ?? 'AI分類理由'),
            'instructions' => (string)($payload['instructions'] ?? ''),
            'ollama_host' => (string)($payload['ollama_host'] ?? ''),
            'model' => (string)($payload['model'] ?? ''),
            'status' => 'pending',
            'created_at' => $now,
            'started_at' => null,
            'finished_at' => null,
            'output_csv_file_id' => null,
        ];

        $this->writeJson($this->getJobPath($jobId), $job);
        $this->writeStatus($jobId, [
            'job_id' => $jobId,
            'status' => 'pending',
            'stage' => 'queued',
            'progress' => 0,
            'current' => 0,
            'total' => 0,
            'message' => 'カテゴリ分けジョブをキューへ登録しました。',
            'error' => null,
            'project_id' => $job['project_id'],
            'output_csv_file_id' => null,
            'updated_at' => time(),
        ]);

        return $job;
    }

    public function readJob(string $jobId): ?array
    {
        return $this->readJson($this->getJobPath($jobId));
    }

    public function updateJob(string $jobId, array $patch): ?array
    {
        $current = $this->readJob($jobId);
        if (!$current) {
            return null;
        }

        $updated = array_merge($current, $patch);
        $this->writeJson($this->getJobPath($jobId), $updated);
        return $updated;
    }

    public function readStatus(string $jobId): ?array
    {
        return $this->readJson($this->getStatusPath($jobId));
    }

    public function writeStatus(string $jobId, array $status): void
    {
        $status['updated_at'] = $status['updated_at'] ?? time();
        $this->writeJson($this->getStatusPath($jobId), $status);
    }

    public function listJobsByProject(int $projectId, int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        $paths = glob($this->jobDir . DIRECTORY_SEPARATOR . 'job_*.json') ?: [];
        $items = [];

        foreach ($paths as $path) {
            $job = $this->readJson($path);
            if (!is_array($job) || (int)($job['project_id'] ?? 0) !== $projectId) {
                continue;
            }

            $jobId = (string)($job['job_id'] ?? '');
            if ($jobId === '') {
                continue;
            }

            $status = $this->readStatus($jobId) ?: [];
            $effectiveStatus = (string)($status['status'] ?? $job['status'] ?? '');
            if ($effectiveStatus === 'canceled') {
                $this->deleteJobArtifacts($jobId);
                continue;
            }

            $items[] = [
                'job' => $job,
                'status' => $status,
            ];
        }

        usort($items, static function (array $a, array $b): int {
            $aTime = strtotime((string)($a['job']['created_at'] ?? '')) ?: 0;
            $bTime = strtotime((string)($b['job']['created_at'] ?? '')) ?: 0;
            return $bTime <=> $aTime;
        });

        return array_slice($items, 0, $limit);
    }

    public function requestCancel(string $jobId, int $staleAfterSeconds = 180): ?array
    {
        $job = $this->readJob($jobId);
        $status = $this->readStatus($jobId);
        if (!$job || !$status) {
            return null;
        }

        $currentStatus = (string)($status['status'] ?? $job['status'] ?? 'pending');
        if (!in_array($currentStatus, ['pending', 'processing'], true)) {
            return [
                'job' => $job,
                'status' => $status,
                'cancelable' => false,
            ];
        }

        $updatedAt = (int)($status['updated_at'] ?? 0);
        $isStale = $updatedAt > 0 && (time() - $updatedAt) >= max(30, $staleAfterSeconds);

        if ($currentStatus === 'pending' || $isStale) {
            $updatedJob = $this->updateJob($jobId, [
                'status' => 'canceled',
                'finished_at' => date('c'),
                'cancel_requested' => true,
                'cancel_requested_at' => date('c'),
            ]) ?: $job;

            $status = array_merge($status, [
                'status' => 'canceled',
                'stage' => 'canceled',
                'message' => $currentStatus === 'pending'
                    ? 'キュー待機中のジョブを停止しました。'
                    : '応答が止まっていたジョブを停止しました。',
                'cancel_requested' => true,
            ]);
            $this->writeStatus($jobId, $status);

            return [
                'job' => $updatedJob,
                'status' => $status,
                'cancelable' => true,
                'completed_now' => true,
            ];
        }

        $updatedJob = $this->updateJob($jobId, [
            'cancel_requested' => true,
            'cancel_requested_at' => date('c'),
        ]) ?: $job;

        $status['cancel_requested'] = true;
        $status['message'] = 'キャンセル要求を受け付けました。現在の行処理が終わり次第停止します。';
        $this->writeStatus($jobId, $status);

        return [
            'job' => $updatedJob,
            'status' => $status,
            'cancelable' => true,
            'completed_now' => false,
        ];
    }

    public function isCancelRequested(string $jobId): bool
    {
        $job = $this->readJob($jobId);
        $status = $this->readStatus($jobId);
        return (bool)($job['cancel_requested'] ?? false) || (bool)($status['cancel_requested'] ?? false);
    }

    public function getCliScriptPath(): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'run_csv_ai_categorize_job.php';
    }

    private function ensureJobDirectory(): void
    {
        if (!is_dir($this->jobDir)) {
            @mkdir($this->jobDir, 0777, true);
        }
    }

    private function getJobPath(string $jobId): string
    {
        return $this->jobDir . DIRECTORY_SEPARATOR . 'job_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $jobId) . '.json';
    }

    private function getStatusPath(string $jobId): string
    {
        return $this->jobDir . DIRECTORY_SEPARATOR . 'status_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $jobId) . '.json';
    }

    private function deleteJobArtifacts(string $jobId): void
    {
        $jobPath = $this->getJobPath($jobId);
        $statusPath = $this->getStatusPath($jobId);

        if (is_file($jobPath)) {
            @unlink($jobPath);
        }
        if (is_file($statusPath)) {
            @unlink($statusPath);
        }
    }

    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function writeJson(string $path, array $payload): void
    {
        @file_put_contents(
            $path,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }
}
