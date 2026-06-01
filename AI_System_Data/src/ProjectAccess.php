<?php
/**
 * ProjectAccess.php - 案件単位の参照・管理権限チェック
 */

function canAccessProject(PDO $pdo, int $projectId, int $userId, string $role): bool
{
    if ($role === 'admin') {
        return true;
    }

    $stmt = $pdo->prepare("
        SELECT 1
        FROM projects p
        LEFT JOIN project_members pm
            ON p.id = pm.project_id AND pm.user_id = ?
        WHERE p.id = ?
          AND (p.created_by = ? OR pm.user_id IS NOT NULL)
        LIMIT 1
    ");
    $stmt->execute([$userId, $projectId, $userId]);

    return (bool)$stmt->fetchColumn();
}

function canManageProject(PDO $pdo, int $projectId, int $userId, string $role): bool
{
    if ($role === 'admin') {
        return true;
    }

    $stmt = $pdo->prepare("
        SELECT 1
        FROM projects p
        LEFT JOIN project_members pm
            ON p.id = pm.project_id AND pm.user_id = ? AND pm.role IN ('manager', 'member')
        WHERE p.id = ?
          AND (p.created_by = ? OR pm.user_id IS NOT NULL)
        LIMIT 1
    ");
    $stmt->execute([$userId, $projectId, $userId]);

    return (bool)$stmt->fetchColumn();
}
