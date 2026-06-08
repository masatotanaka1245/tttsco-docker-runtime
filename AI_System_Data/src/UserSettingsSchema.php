<?php

class UserSettingsSchema
{
    private static ?bool $hasEmbeddingModelColumn = null;
    private static ?bool $hasSqlModelColumn = null;

    public static function hasEmbeddingModelColumn(PDO $pdo): bool
    {
        return self::hasColumn($pdo, 'embedding_model', self::$hasEmbeddingModelColumn);
    }

    public static function hasSqlModelColumn(PDO $pdo): bool
    {
        return self::hasColumn($pdo, 'sql_model', self::$hasSqlModelColumn);
    }

    private static function hasColumn(PDO $pdo, string $columnName, ?bool &$cache): bool
    {
        if ($cache !== null) {
            return $cache;
        }

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE '{$columnName}'");
            $cache = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $cache = false;
        }

        return $cache;
    }
}
