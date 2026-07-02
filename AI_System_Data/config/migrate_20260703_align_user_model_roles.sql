-- ==================================================================
-- Align user model role columns and defaults
-- Target: MySQL 8.0.33 / tepscoapp
-- Created: 2026-07-03
--
-- Purpose:
--   live DB に残っている users の model 設定 drift を、
--   runtime の希望構成へ安全に寄せるための migration SQL。
--
-- Target:
--   - users.default_model default
--   - users.sub_model default
--   - users.sql_model column
--   - users.embedding_model column
--   - users.vision_model column
--   - admin(id=1) の sub_model 条件付き補正
--
-- Important:
--   - 実行前に必ず backup を取得すること
--   - schema migration と data backfill は section を分けている
--   - 全ユーザー一括上書きは避ける
--   - admin(id=1) の sub_model=gemma4:e2b 補正は条件付き
--   - schema_check.sql は検査専用のまま維持し、この SQL に自己修復を集約する
--
-- Idempotency note:
--   - MySQL 8.0.33 前提
--   - 既存 migration と同様、INFORMATION_SCHEMA を見てから ALTER を実行する
--   - 再実行前には DESCRIBE users / SHOW CREATE TABLE users で状態を確認すること
--
-- Rollback note:
--   - 追加列を DROP すると保存済み model 設定が失われる
--   - default 変更だけ戻す場合は MODIFY で旧 default に戻せる
--   - data backfill は旧値を別記録していない限り完全復元できない
--   - 実行前 backup を必須とする
-- ==================================================================

USE tepscoapp;

-- ------------------------------------------------------------------
-- 0. Pre-check (read-only)
-- ------------------------------------------------------------------
DESCRIBE users;
SHOW CREATE TABLE users;
SELECT id, username, default_model, sub_model, ollama_host
FROM users
ORDER BY id
LIMIT 20;

-- ------------------------------------------------------------------
-- 1. Schema migration
-- ------------------------------------------------------------------

-- 1-1. users.sql_model を追加（未存在時のみ）
SELECT IF(
    EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users'
          AND COLUMN_NAME = 'sql_model'
    ),
    'SELECT ''skip users.sql_model'' AS migration',
    'ALTER TABLE users ADD COLUMN sql_model VARCHAR(100) COLLATE utf8mb4_unicode_ci DEFAULT ''codellama:7b'' COMMENT ''Text-to-SQL・SQL自己修復用モデル'' AFTER sub_model'
) INTO @sql;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 1-2. users.embedding_model を追加（未存在時のみ）
SELECT IF(
    EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users'
          AND COLUMN_NAME = 'embedding_model'
    ),
    'SELECT ''skip users.embedding_model'' AS migration',
    'ALTER TABLE users ADD COLUMN embedding_model VARCHAR(100) COLLATE utf8mb4_unicode_ci DEFAULT ''mxbai-embed-large'' COMMENT ''ベクトル化専用モデル'' AFTER sql_model'
) INTO @sql;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 1-3. users.vision_model を追加（未存在時のみ）
SELECT IF(
    EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users'
          AND COLUMN_NAME = 'vision_model'
    ),
    'SELECT ''skip users.vision_model'' AS migration',
    'ALTER TABLE users ADD COLUMN vision_model VARCHAR(100) COLLATE utf8mb4_unicode_ci DEFAULT ''gemma4:e4b'' COMMENT ''PDF・画像解析用ビジョンモデル'' AFTER embedding_model'
) INTO @sql;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 1-4. users.default_model の default を希望構成へ修正
SELECT IF(
    EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users'
          AND COLUMN_NAME = 'default_model'
    ),
    'ALTER TABLE users MODIFY COLUMN default_model VARCHAR(50) COLLATE utf8mb4_unicode_ci DEFAULT ''gemma4:e4b'' COMMENT ''優先使用モデル''',
    'SELECT ''skip users.default_model'' AS migration'
) INTO @sql;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 1-5. users.sub_model の default を希望構成へ修正
SELECT IF(
    EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users'
          AND COLUMN_NAME = 'sub_model'
    ),
    'ALTER TABLE users MODIFY COLUMN sub_model VARCHAR(100) COLLATE utf8mb4_unicode_ci DEFAULT ''gemma4:e4b'' COMMENT ''中間処理・補助分析用サブモデル''',
    'SELECT ''skip users.sub_model'' AS migration'
) INTO @sql;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------------
-- 2. Data backfill
-- ------------------------------------------------------------------

-- 2-1. 新規追加列は NULL / 空値だけを補完
--      列追加時の DEFAULT で埋まる環境でも、空値・NULL残存を安全に補完する。
UPDATE users
SET
  sql_model = COALESCE(NULLIF(sql_model, ''), 'codellama:7b'),
  embedding_model = COALESCE(NULLIF(embedding_model, ''), 'mxbai-embed-large'),
  vision_model = COALESCE(NULLIF(vision_model, ''), 'gemma4:e4b')
WHERE
  sql_model IS NULL OR sql_model = ''
  OR embedding_model IS NULL OR embedding_model = ''
  OR vision_model IS NULL OR vision_model = '';

-- 2-2. admin(id=1) の sub_model だけを条件付き補正
--      全ユーザー一括上書きは避け、今回確認済みの drift に限定する。
UPDATE users
SET sub_model = 'gemma4:e4b'
WHERE id = 1
  AND sub_model = 'gemma4:e2b';

-- Optional broader backfill candidate (今回は未採用):
-- UPDATE users
-- SET sub_model = 'gemma4:e4b'
-- WHERE sub_model IN ('gpt-oss:20b', 'gemma4:e2b')
--    OR sub_model IS NULL
--    OR sub_model = '';

-- ------------------------------------------------------------------
-- 3. Verification queries
-- ------------------------------------------------------------------
DESCRIBE users;
SHOW CREATE TABLE users;
SELECT id, username, default_model, sub_model, sql_model, embedding_model, vision_model, ollama_host
FROM users
ORDER BY id
LIMIT 20;
