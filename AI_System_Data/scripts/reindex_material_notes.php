<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/ProjectMaterialDocumentService.php';

const MATERIAL_REINDEX_ALLOWED_SCOPES = ['all', 'csv_analysis'];

/**
 * @return array{project_id:int|null, all:bool, scope:string, help:bool}
 */
function parseMaterialReindexArgs(array $argv): array
{
    $options = [
        'project_id' => null,
        'all' => false,
        'scope' => 'all',
        'help' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }

        if ($arg === '--all') {
            $options['all'] = true;
            continue;
        }

        if (str_starts_with($arg, '--project-id=')) {
            $raw = substr($arg, strlen('--project-id='));
            if ($raw === '' || !ctype_digit($raw) || (int)$raw <= 0) {
                throw new InvalidArgumentException('project-id must be a positive integer.');
            }
            $options['project_id'] = (int)$raw;
            continue;
        }

        if (str_starts_with($arg, '--scope=')) {
            $scope = substr($arg, strlen('--scope='));
            if (!in_array($scope, MATERIAL_REINDEX_ALLOWED_SCOPES, true)) {
                throw new InvalidArgumentException('scope must be one of: ' . implode(', ', MATERIAL_REINDEX_ALLOWED_SCOPES));
            }
            $options['scope'] = $scope;
            continue;
        }

        throw new InvalidArgumentException('Unknown option: ' . $arg);
    }

    if ($options['project_id'] !== null && $options['all'] === true) {
        throw new InvalidArgumentException('--project-id and --all cannot be used together.');
    }

    return $options;
}

function printMaterialReindexUsage($stream = STDOUT): void
{
    fwrite($stream, "Usage:\n");
    fwrite($stream, "  php /var/www/html/scripts/reindex_material_notes.php --project-id=4 [--scope=all|csv_analysis]\n");
    fwrite($stream, "  php /var/www/html/scripts/reindex_material_notes.php --all [--scope=all|csv_analysis]\n");
    fwrite($stream, "  php /var/www/html/scripts/reindex_material_notes.php --help\n");
    fwrite($stream, "\n");
    fwrite($stream, "Notes:\n");
    fwrite($stream, "  --project-id and --all cannot be used together.\n");
    fwrite($stream, "  --scope defaults to all.\n");
    fwrite($stream, "  --dry-run is not supported in this first version.\n");
}

function printProjectSummary(array $summary): void
{
    echo "checked: " . (int)($summary['checked'] ?? 0) . PHP_EOL;
    echo "eligible: " . (int)($summary['eligible'] ?? 0) . PHP_EOL;
    echo "reindexed: " . (int)($summary['reindexed'] ?? 0) . PHP_EOL;
    echo "already_indexed: " . (int)($summary['already_indexed'] ?? 0) . PHP_EOL;
    echo "skipped_empty: " . (int)($summary['skipped_empty'] ?? 0) . PHP_EOL;
    echo "skipped_scope: " . (int)($summary['skipped_scope'] ?? 0) . PHP_EOL;
    echo "embedded_chunks: " . (int)($summary['embedded_chunks'] ?? 0) . PHP_EOL;
    echo "failed_chunks: " . (int)($summary['failed_chunks'] ?? 0) . PHP_EOL;
    echo "partial_success: " . (int)($summary['partial_success'] ?? 0) . PHP_EOL;
    echo "full_success: " . (int)($summary['full_success'] ?? 0) . PHP_EOL;
}

try {
    $options = parseMaterialReindexArgs($argv);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL . PHP_EOL);
    printMaterialReindexUsage(STDERR);
    exit(1);
}

if ($options['help'] === true) {
    printMaterialReindexUsage();
    exit(0);
}

if ($options['project_id'] === null && $options['all'] === false) {
    printMaterialReindexUsage(STDERR);
    exit(1);
}

$basePath = realpath(__DIR__ . '/..');
if ($basePath === false) {
    fwrite(STDERR, "Base path could not be resolved.\n");
    exit(1);
}

$service = new ProjectMaterialDocumentService($pdo, $basePath);
$scope = $options['scope'];

try {
    echo "Material note reindex started" . PHP_EOL;

    if ($options['all'] === true) {
        echo "target: all_projects" . PHP_EOL;
        echo "scope: {$scope}" . PHP_EOL;

        $summary = $service->backfillAllProjectsEmbeddings($scope);

        echo "projects: " . (int)($summary['projects'] ?? 0) . PHP_EOL;
        printProjectSummary($summary);

        foreach ((array)($summary['project_summaries'] ?? []) as $projectSummary) {
            echo PHP_EOL;
            echo "project_id: " . (int)($projectSummary['project_id'] ?? 0) . PHP_EOL;
            echo "scope: " . (string)($projectSummary['scope'] ?? $scope) . PHP_EOL;
            printProjectSummary($projectSummary);
        }
    } else {
        $projectId = (int)$options['project_id'];
        echo "target: project_id={$projectId}" . PHP_EOL;
        echo "scope: {$scope}" . PHP_EOL;

        $summary = $scope === 'all'
            ? $service->backfillProjectEmbeddings($projectId)
            : $service->backfillProjectEmbeddingsByScope($projectId, $scope);

        printProjectSummary($summary);
    }

    echo "Material note reindex completed" . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Material note reindex failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
