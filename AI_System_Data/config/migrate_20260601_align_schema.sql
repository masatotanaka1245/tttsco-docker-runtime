-- ==================================================================
-- Existing database alignment migration
-- Target: MySQL 8.0.33 / tepscoapp
-- Created: 2026-06-01
--
-- Purpose:
--   既存DBを README_01.md / config/db.sql の現行106カラム構成に寄せます。
--   INFORMATION_SCHEMA を見てから実行するため、対象カラムが無い場合はスキップします。
-- ==================================================================

USE tepscoapp;

-- users.department: varchar(128) -> varchar(100)
SELECT IF(
    EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users'
          AND COLUMN_NAME = 'department'
    ),
    'ALTER TABLE users MODIFY COLUMN department VARCHAR(100) NULL',
    'SELECT ''skip users.department'' AS migration'
) INTO @sql;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- chat_reasoning_steps.original_question: text -> longtext
SELECT IF(
    EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'chat_reasoning_steps'
          AND COLUMN_NAME = 'original_question'
    ),
    'ALTER TABLE chat_reasoning_steps MODIFY COLUMN original_question LONGTEXT NOT NULL COMMENT ''ユーザーの元の質問''',
    'SELECT ''skip chat_reasoning_steps.original_question'' AS migration'
) INTO @sql;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- chat_reasoning_steps.search_context: text -> longtext
SELECT IF(
    EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'chat_reasoning_steps'
          AND COLUMN_NAME = 'search_context'
    ),
    'ALTER TABLE chat_reasoning_steps MODIFY COLUMN search_context LONGTEXT NULL COMMENT ''このサブ質問でヒットしたRAG資料情報(JSON)''',
    'SELECT ''skip chat_reasoning_steps.search_context'' AS migration'
) INTO @sql;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- chat_reasoning_steps.sub_answer: text -> longtext
SELECT IF(
    EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'chat_reasoning_steps'
          AND COLUMN_NAME = 'sub_answer'
    ),
    'ALTER TABLE chat_reasoning_steps MODIFY COLUMN sub_answer LONGTEXT NULL COMMENT ''このサブ質問に対して生成された個別回答''',
    'SELECT ''skip chat_reasoning_steps.sub_answer'' AS migration'
) INTO @sql;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- logs.ip_address: 現行アプリ・README構成では未使用のため削除
SELECT IF(
    EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'logs'
          AND COLUMN_NAME = 'ip_address'
    ),
    'ALTER TABLE logs DROP COLUMN ip_address',
    'SELECT ''skip logs.ip_address'' AS migration'
) INTO @sql;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
