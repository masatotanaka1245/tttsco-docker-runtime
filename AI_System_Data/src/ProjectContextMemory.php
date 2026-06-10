<?php

final class ProjectContextMemory
{
    public const META_KEYS = [
        'agents' => 'ai_project_agents_md',
        'readme' => 'ai_project_readme_md',
        'todo' => 'ai_project_todo_md',
    ];

    public const AUTO_META_KEYS = [
        'agents' => 'ai_project_agents_auto_md',
        'readme' => 'ai_project_readme_auto_md',
        'todo' => 'ai_project_todo_auto_md',
    ];

    public const LABELS = [
        'agents' => 'AIエージェント',
        'readme' => '案件内容',
        'todo' => 'タスク一覧',
    ];

    public static function load(PDO $pdo, int $projectId): array
    {
        $docs = self::emptyDocs();
        if ($projectId <= 0) {
            return $docs;
        }

        $metaKeys = array_merge(array_values(self::META_KEYS), array_values(self::AUTO_META_KEYS));
        $placeholders = implode(',', array_fill(0, count($metaKeys), '?'));
        $stmt = $pdo->prepare(
            "SELECT meta_key, meta_value
               FROM project_meta
              WHERE project_id = ?
                AND meta_key IN ($placeholders)"
        );
        $stmt->execute(array_merge([$projectId], $metaKeys));

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $metaKey = (string)($row['meta_key'] ?? '');
            $type = array_search($metaKey, self::META_KEYS, true);
            if ($type !== false) {
                $docs[$type]['content'] = trim((string)($row['meta_value'] ?? ''));
                continue;
            }

            $autoType = array_search($metaKey, self::AUTO_META_KEYS, true);
            if ($autoType !== false) {
                $docs[$autoType]['auto_content'] = trim((string)($row['meta_value'] ?? ''));
            }
        }

        return $docs;
    }

    public static function save(PDO $pdo, int $projectId, array $docs): void
    {
        if ($projectId <= 0) {
            return;
        }

        $stmtUpsert = $pdo->prepare(
            "INSERT INTO project_meta (project_id, meta_key, meta_value, created_at, updated_at)
             VALUES (?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = NOW()"
        );
        $stmtDelete = $pdo->prepare(
            "DELETE FROM project_meta WHERE project_id = ? AND meta_key = ?"
        );

        foreach (self::META_KEYS as $type => $metaKey) {
            $content = self::extractContent($docs[$type] ?? '');
            if ($content === '') {
                $stmtDelete->execute([$projectId, $metaKey]);
                continue;
            }
            $stmtUpsert->execute([$projectId, $metaKey, $content]);
        }
    }

    public static function saveAuto(PDO $pdo, int $projectId, array $docs): void
    {
        if ($projectId <= 0) {
            return;
        }

        $stmtUpsert = $pdo->prepare(
            "INSERT INTO project_meta (project_id, meta_key, meta_value, created_at, updated_at)
             VALUES (?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = NOW()"
        );
        $stmtDelete = $pdo->prepare(
            "DELETE FROM project_meta WHERE project_id = ? AND meta_key = ?"
        );

        foreach (self::AUTO_META_KEYS as $type => $metaKey) {
            $content = self::extractContent($docs[$type] ?? '');
            if ($content === '') {
                $stmtDelete->execute([$projectId, $metaKey]);
                continue;
            }
            $stmtUpsert->execute([$projectId, $metaKey, $content]);
        }
    }

    public static function loadedTypes(array $docs): array
    {
        $loaded = [];
        foreach ($docs as $type => $doc) {
            $content = trim((string)($doc['content'] ?? ''));
            $autoContent = trim((string)($doc['auto_content'] ?? ''));
            if ($content !== '' || $autoContent !== '') {
                $loaded[] = (string)$type;
            }
        }
        return $loaded;
    }

    public static function totalChars(array $docs): int
    {
        $chars = 0;
        foreach ($docs as $doc) {
            $chars += mb_strlen(self::extractContent($doc));
            if (is_array($doc)) {
                $chars += mb_strlen(trim((string)($doc['auto_content'] ?? '')));
            }
        }
        return $chars;
    }

    private static function extractContent($doc): string
    {
        if (is_array($doc)) {
            return trim((string)($doc['content'] ?? ''));
        }

        return trim((string)$doc);
    }

    private static function emptyDocs(): array
    {
        $docs = [];
        foreach (self::META_KEYS as $type => $metaKey) {
            $docs[$type] = [
                'meta_key' => $metaKey,
                'label' => self::LABELS[$type] ?? strtoupper($type),
                'content' => '',
                'auto_meta_key' => self::AUTO_META_KEYS[$type] ?? null,
                'auto_content' => '',
            ];
        }
        return $docs;
    }
}
