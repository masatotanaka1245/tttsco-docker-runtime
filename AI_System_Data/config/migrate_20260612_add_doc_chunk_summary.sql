-- ==================================================================
-- Add chunk_summary column to doc_chunks
-- Target: MySQL 8.0.33 / tepscoapp
-- Created: 2026-06-12
-- ==================================================================

USE tepscoapp;

SELECT IF(
    EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'doc_chunks'
          AND COLUMN_NAME = 'chunk_summary'
    ),
    'SELECT ''skip doc_chunks.chunk_summary'' AS migration',
    'ALTER TABLE doc_chunks ADD COLUMN chunk_summary TEXT NULL COMMENT ''チャンクの短い要約'' AFTER chunk_text'
) INTO @sql;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
