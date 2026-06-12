-- ==================================================================
-- Add vision_model column to users
-- Target: MySQL 8.0.33 / tepscoapp
-- Created: 2026-06-12
--
-- Purpose:
--   PDFページ全体要約・スライス読解・画像解析に使う vision_model を
--   users テーブルへ追加します。
--   既に存在する環境では INFORMATION_SCHEMA を見てスキップします。
-- ==================================================================

USE tepscoapp;

SELECT IF(
    EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users'
          AND COLUMN_NAME = 'vision_model'
    ),
    'SELECT ''skip users.vision_model'' AS migration',
    'ALTER TABLE users ADD COLUMN vision_model VARCHAR(100) DEFAULT ''gemma4:e4b'' COMMENT ''PDF・画像解析用ビジョンモデル'' AFTER embedding_model'
) INTO @sql;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
