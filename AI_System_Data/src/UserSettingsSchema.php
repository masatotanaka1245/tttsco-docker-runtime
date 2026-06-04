<?php

class UserSettingsSchema
{
    private static ?bool $hasEmbeddingModelColumn = null;

    public static function hasEmbeddingModelColumn(PDO $pdo): bool
    {
        if (self::$hasEmbeddingModelColumn !== null) {
            return self::$hasEmbeddingModelColumn;
        }

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'embedding_model'");
            self::$hasEmbeddingModelColumn = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            self::$hasEmbeddingModelColumn = false;
        }

        return self::$hasEmbeddingModelColumn;
    }
}
