-- ==================================================================
-- Production schema check for tepscoapp
-- Target: MySQL 8.0.33
-- Usage: phpMyAdmin で tepscoapp を選択して、このSQLを実行してください。
-- Result:
--   MISSING_TABLE  : 本番DBに不足しているテーブル
--   EXTRA_TABLE    : 本番DBにだけ存在するテーブル
--   MISSING_COLUMN : 本番DBに不足しているカラム
--   EXTRA_COLUMN   : 本番DBにだけ存在するカラム
--   TYPE_MISMATCH  : 型またはNULL許容が期待値と異なるカラム
--   MISSING_INDEX  : 本番DBに不足している、または定義差のあるインデックス
--   DEFAULT_MISMATCH : DBカラム既定値が期待値と異なる
-- Note:
--   schema_check.sql は主にテーブル / カラム / INDEX / DB既定値の整合を検査します。
--   実行時の実効既定値は AI_System_Data/src/ModelRoleResolver.php を正本とし、
--   OLLAMA_EMBED_MODEL などの環境変数上書きはこのSQLの検査対象外です。
-- ==================================================================

USE tepscoapp;

DROP TEMPORARY TABLE IF EXISTS expected_schema_tables;
DROP TEMPORARY TABLE IF EXISTS expected_schema_columns;
DROP TEMPORARY TABLE IF EXISTS expected_schema_indexes;
DROP TEMPORARY TABLE IF EXISTS expected_schema_defaults;

CREATE TEMPORARY TABLE expected_schema_tables (
    table_name VARCHAR(64) NOT NULL PRIMARY KEY
);

CREATE TEMPORARY TABLE expected_schema_columns (
    table_name VARCHAR(64) NOT NULL,
    ordinal_position INT NOT NULL,
    column_name VARCHAR(64) NOT NULL,
    column_type VARCHAR(255) NOT NULL,
    is_nullable VARCHAR(3) NOT NULL,
    PRIMARY KEY (table_name, column_name)
);

CREATE TEMPORARY TABLE expected_schema_indexes (
    table_name VARCHAR(64) NOT NULL,
    index_name VARCHAR(64) NOT NULL,
    non_unique TINYINT(1) NOT NULL,
    columns_csv VARCHAR(255) NOT NULL,
    PRIMARY KEY (table_name, index_name)
);

CREATE TEMPORARY TABLE expected_schema_defaults (
    table_name VARCHAR(64) NOT NULL,
    column_name VARCHAR(64) NOT NULL,
    expected_default VARCHAR(255) NULL,
    PRIMARY KEY (table_name, column_name)
);

INSERT INTO expected_schema_tables (table_name)
VALUES
('chat_evaluations'),
('chat_history'),
('chat_reasoning_steps'),
('chat_threads'),
('doc_chunks'),
('documents'),
('embeddings'),
('logs'),
('project_comments'),
('project_csv_files'),
('project_csv_rows'),
('project_faqs'),
('project_members'),
('project_meta'),
('projects'),
('users');

INSERT INTO expected_schema_columns
    (table_name, ordinal_position, column_name, column_type, is_nullable)
VALUES
('chat_evaluations', 1, 'id', 'bigint unsigned', 'NO'),
('chat_evaluations', 2, 'chat_id', 'bigint unsigned', 'NO'),
('chat_evaluations', 3, 'proactivity_score', 'int', 'NO'),
('chat_evaluations', 4, 'faithfulness_score', 'int', 'NO'),
('chat_evaluations', 5, 'relevance_score', 'int', 'NO'),
('chat_evaluations', 6, 'clarity_score', 'int', 'NO'),
('chat_evaluations', 7, 'total_score', 'int', 'NO'),
('chat_evaluations', 8, 'feedback', 'text', 'YES'),
('chat_evaluations', 9, 'retry_count', 'int', 'YES'),
('chat_evaluations', 10, 'created_at', 'datetime', 'YES'),
('chat_history', 1, 'id', 'bigint unsigned', 'NO'),
('chat_history', 2, 'project_id', 'bigint unsigned', 'YES'),
('chat_history', 3, 'thread_id', 'bigint unsigned', 'YES'),
('chat_history', 4, 'user_id', 'bigint unsigned', 'NO'),
('chat_history', 5, 'role', 'enum(''user'',''assistant'')', 'NO'),
('chat_history', 6, 'message', 'text', 'NO'),
('chat_history', 7, 'created_at', 'datetime', 'YES'),
('chat_reasoning_steps', 1, 'id', 'bigint unsigned', 'NO'),
('chat_reasoning_steps', 2, 'chat_history_id', 'bigint unsigned', 'YES'),
('chat_reasoning_steps', 3, 'project_id', 'bigint unsigned', 'NO'),
('chat_reasoning_steps', 4, 'session_id', 'varchar(255)', 'NO'),
('chat_reasoning_steps', 5, 'original_question', 'longtext', 'NO'),
('chat_reasoning_steps', 6, 'step_number', 'int', 'NO'),
('chat_reasoning_steps', 7, 'sub_query', 'varchar(512)', 'NO'),
('chat_reasoning_steps', 8, 'search_context', 'longtext', 'YES'),
('chat_reasoning_steps', 9, 'sub_answer', 'longtext', 'YES'),
('chat_reasoning_steps', 10, 'created_at', 'datetime', 'YES'),
('chat_threads', 1, 'id', 'bigint unsigned', 'NO'),
('chat_threads', 2, 'project_id', 'bigint unsigned', 'NO'),
('chat_threads', 3, 'title', 'varchar(255)', 'NO'),
('chat_threads', 4, 'created_by', 'bigint unsigned', 'YES'),
('chat_threads', 5, 'created_at', 'datetime', 'YES'),
('chat_threads', 6, 'updated_at', 'datetime', 'YES'),
('doc_chunks', 1, 'id', 'bigint unsigned', 'NO'),
('doc_chunks', 2, 'doc_id', 'bigint unsigned', 'NO'),
('doc_chunks', 3, 'chunk_text', 'longtext', 'NO'),
('doc_chunks', 4, 'embedding', 'json', 'NO'),
('doc_chunks', 5, 'image_description', 'text', 'YES'),
('doc_chunks', 6, 'page_number', 'int', 'YES'),
('doc_chunks', 7, 'created_at', 'datetime', 'YES'),
('documents', 1, 'id', 'bigint unsigned', 'NO'),
('documents', 2, 'project_id', 'bigint unsigned', 'NO'),
('documents', 3, 'title', 'varchar(255)', 'NO'),
('documents', 4, 'file_path', 'varchar(512)', 'NO'),
('documents', 5, 'created_at', 'datetime', 'YES'),
('embeddings', 1, 'id', 'bigint', 'NO'),
('embeddings', 2, 'document_id', 'bigint', 'NO'),
('embeddings', 3, 'embedding', 'json', 'NO'),
('embeddings', 4, 'created_at', 'timestamp', 'YES'),
('logs', 1, 'id', 'bigint unsigned', 'NO'),
('logs', 2, 'user_id', 'bigint unsigned', 'YES'),
('logs', 3, 'action', 'varchar(255)', 'NO'),
('logs', 4, 'details', 'json', 'YES'),
('logs', 5, 'created_at', 'datetime', 'YES'),
('project_comments', 1, 'id', 'bigint unsigned', 'NO'),
('project_comments', 2, 'project_id', 'bigint unsigned', 'NO'),
('project_comments', 3, 'user_id', 'bigint unsigned', 'NO'),
('project_comments', 4, 'comment_text', 'text', 'NO'),
('project_comments', 5, 'created_at', 'datetime', 'YES'),
('project_comments', 6, 'updated_at', 'datetime', 'YES'),
('project_csv_files', 1, 'id', 'bigint unsigned', 'NO'),
('project_csv_files', 2, 'project_id', 'bigint unsigned', 'NO'),
('project_csv_files', 3, 'file_name', 'varchar(255)', 'NO'),
('project_csv_files', 4, 'column_headers', 'text', 'NO'),
('project_csv_files', 5, 'row_count', 'int', 'NO'),
('project_csv_files', 6, 'created_at', 'datetime', 'YES'),
('project_csv_rows', 1, 'id', 'bigint unsigned', 'NO'),
('project_csv_rows', 2, 'csv_file_id', 'bigint unsigned', 'NO'),
('project_csv_rows', 3, 'row_index', 'int', 'NO'),
('project_csv_rows', 4, 'row_data', 'json', 'NO'),
('project_csv_rows', 5, 'created_at', 'datetime', 'YES'),
('project_faqs', 1, 'id', 'bigint unsigned', 'NO'),
('project_faqs', 2, 'project_id', 'bigint unsigned', 'NO'),
('project_faqs', 3, 'chat_history_id', 'bigint unsigned', 'YES'),
('project_faqs', 4, 'question_summary', 'varchar(512)', 'NO'),
('project_faqs', 5, 'answer_summary', 'text', 'NO'),
('project_faqs', 6, 'created_by', 'bigint unsigned', 'YES'),
('project_faqs', 7, 'created_at', 'datetime', 'YES'),
('project_members', 1, 'id', 'bigint unsigned', 'NO'),
('project_members', 2, 'project_id', 'bigint unsigned', 'NO'),
('project_members', 3, 'user_id', 'bigint unsigned', 'NO'),
('project_members', 4, 'role', 'enum(''manager'',''member'',''viewer'')', 'NO'),
('project_members', 5, 'assigned_at', 'datetime', 'YES'),
('project_meta', 1, 'id', 'bigint unsigned', 'NO'),
('project_meta', 2, 'project_id', 'bigint unsigned', 'NO'),
('project_meta', 3, 'meta_key', 'varchar(100)', 'NO'),
('project_meta', 4, 'meta_value', 'text', 'YES'),
('project_meta', 5, 'created_at', 'datetime', 'YES'),
('project_meta', 6, 'updated_at', 'datetime', 'YES'),
('projects', 1, 'id', 'bigint unsigned', 'NO'),
('projects', 2, 'project_name', 'varchar(255)', 'NO'),
('projects', 3, 'description', 'text', 'YES'),
('projects', 4, 'start_date', 'date', 'YES'),
('projects', 5, 'end_date', 'date', 'YES'),
('projects', 6, 'address', 'varchar(512)', 'YES'),
('projects', 7, 'latitude', 'decimal(10,8)', 'YES'),
('projects', 8, 'longitude', 'decimal(11,8)', 'YES'),
('projects', 9, 'created_by', 'bigint unsigned', 'YES'),
('projects', 10, 'status', 'enum(''active'',''completed'',''on_hold'')', 'NO'),
('projects', 11, 'created_at', 'datetime', 'YES'),
('projects', 12, 'updated_at', 'datetime', 'YES'),
('users', 1, 'id', 'bigint unsigned', 'NO'),
('users', 2, 'username', 'varchar(64)', 'NO'),
('users', 3, 'password_hash', 'varchar(255)', 'NO'),
('users', 4, 'role', 'enum(''admin'',''user'')', 'NO'),
('users', 5, 'department', 'varchar(100)', 'YES'),
('users', 6, 'created_at', 'datetime', 'YES'),
('users', 7, 'updated_at', 'datetime', 'YES'),
('users', 8, 'default_prompt', 'varchar(50)', 'YES'),
('users', 9, 'default_lang', 'varchar(10)', 'YES'),
('users', 10, 'default_model', 'varchar(50)', 'YES'),
('users', 11, 'ollama_host', 'varchar(255)', 'YES'),
('users', 12, 'sub_model', 'varchar(100)', 'YES'),
('users', 13, 'sql_model', 'varchar(100)', 'YES'),
('users', 14, 'embedding_model', 'varchar(100)', 'YES'),
('users', 15, 'vision_model', 'varchar(100)', 'YES');

INSERT INTO expected_schema_indexes
    (table_name, index_name, non_unique, columns_csv)
VALUES
('chat_history', 'idx_chat_history_context', 1, 'project_id,created_at'),
('chat_history', 'idx_chat_history_project_id', 1, 'project_id'),
('chat_history', 'idx_chat_history_thread_context', 1, 'thread_id,created_at'),
('chat_history', 'idx_chat_history_thread_id', 1, 'thread_id'),
('chat_history', 'idx_chat_history_user_id', 1, 'user_id'),
('chat_threads', 'idx_chat_threads_project_id', 1, 'project_id'),
('chat_threads', 'idx_chat_threads_updated_at', 1, 'updated_at');

INSERT INTO expected_schema_defaults
    (table_name, column_name, expected_default)
VALUES
('users', 'default_model', 'gemma4:e4b'),
('users', 'ollama_host', 'http://127.0.0.1:11434'),
('users', 'sub_model', 'gpt-oss:20b'),
('users', 'sql_model', 'codellama:7b'),
('users', 'embedding_model', 'mxbai-embed-large'),
('users', 'vision_model', 'gemma4:e4b');

SELECT
    'MISSING_TABLE' AS issue,
    e.table_name,
    NULL AS detail
FROM expected_schema_tables e
LEFT JOIN INFORMATION_SCHEMA.TABLES t
    ON t.TABLE_SCHEMA = DATABASE()
   AND t.TABLE_NAME = e.table_name
WHERE t.TABLE_NAME IS NULL
ORDER BY e.table_name;

SELECT
    'EXTRA_TABLE' AS issue,
    t.TABLE_NAME AS table_name,
    NULL AS detail
FROM INFORMATION_SCHEMA.TABLES t
LEFT JOIN expected_schema_tables e
    ON e.table_name = t.TABLE_NAME
WHERE t.TABLE_SCHEMA = DATABASE()
  AND t.TABLE_TYPE = 'BASE TABLE'
  AND e.table_name IS NULL
ORDER BY t.TABLE_NAME;

SELECT
    'MISSING_COLUMN' AS issue,
    e.table_name,
    e.column_name,
    e.column_type AS expected_column_type,
    NULL AS actual_column_type,
    e.is_nullable AS expected_nullable,
    NULL AS actual_nullable
FROM expected_schema_columns e
LEFT JOIN INFORMATION_SCHEMA.COLUMNS c
    ON c.TABLE_SCHEMA = DATABASE()
   AND c.TABLE_NAME = e.table_name
   AND c.COLUMN_NAME = e.column_name
WHERE c.COLUMN_NAME IS NULL
ORDER BY e.table_name, e.ordinal_position;

SELECT
    'EXTRA_COLUMN' AS issue,
    c.TABLE_NAME AS table_name,
    c.COLUMN_NAME AS column_name,
    NULL AS expected_column_type,
    c.COLUMN_TYPE AS actual_column_type,
    NULL AS expected_nullable,
    c.IS_NULLABLE AS actual_nullable
FROM INFORMATION_SCHEMA.COLUMNS c
LEFT JOIN expected_schema_columns e
    ON e.table_name = c.TABLE_NAME
   AND e.column_name = c.COLUMN_NAME
WHERE c.TABLE_SCHEMA = DATABASE()
  AND e.column_name IS NULL
ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION;

SELECT
    'TYPE_MISMATCH' AS issue,
    e.table_name,
    e.column_name,
    e.column_type AS expected_column_type,
    c.COLUMN_TYPE AS actual_column_type,
    e.is_nullable AS expected_nullable,
    c.IS_NULLABLE AS actual_nullable
FROM expected_schema_columns e
JOIN INFORMATION_SCHEMA.COLUMNS c
    ON c.TABLE_SCHEMA = DATABASE()
   AND c.TABLE_NAME = e.table_name
   AND c.COLUMN_NAME = e.column_name
WHERE LOWER(c.COLUMN_TYPE) <> LOWER(e.column_type)
   OR c.IS_NULLABLE <> e.is_nullable
ORDER BY e.table_name, e.ordinal_position;

SELECT
    'MISSING_INDEX' AS issue,
    e.table_name,
    e.index_name,
    e.columns_csv AS expected_columns_csv,
    s.columns_csv AS actual_columns_csv,
    e.non_unique AS expected_non_unique,
    s.non_unique AS actual_non_unique
FROM expected_schema_indexes e
LEFT JOIN (
    SELECT
        TABLE_NAME,
        INDEX_NAME,
        MIN(NON_UNIQUE) AS non_unique,
        GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS columns_csv
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND INDEX_NAME <> 'PRIMARY'
    GROUP BY TABLE_NAME, INDEX_NAME
) s
    ON s.TABLE_NAME = e.table_name
   AND s.INDEX_NAME = e.index_name
WHERE s.INDEX_NAME IS NULL
   OR s.columns_csv <> e.columns_csv
   OR s.non_unique <> e.non_unique
ORDER BY e.table_name, e.index_name;

SELECT
    'DEFAULT_MISMATCH' AS issue,
    e.table_name,
    e.column_name,
    e.expected_default AS expected_column_default,
    c.COLUMN_DEFAULT AS actual_column_default
FROM expected_schema_defaults e
JOIN INFORMATION_SCHEMA.COLUMNS c
    ON c.TABLE_SCHEMA = DATABASE()
   AND c.TABLE_NAME = e.table_name
   AND c.COLUMN_NAME = e.column_name
WHERE COALESCE(c.COLUMN_DEFAULT, '__NULL__') <> COALESCE(e.expected_default, '__NULL__')
ORDER BY e.table_name, e.column_name;

SELECT COUNT(*) INTO @expected_column_count
FROM expected_schema_columns;

SELECT COUNT(*) INTO @actual_column_count
FROM INFORMATION_SCHEMA.COLUMNS c
WHERE c.TABLE_SCHEMA = DATABASE()
  AND EXISTS (
      SELECT 1
      FROM expected_schema_columns e
      WHERE e.table_name = c.TABLE_NAME
  );

SELECT COUNT(*) INTO @expected_table_count
FROM expected_schema_tables;

SELECT COUNT(*) INTO @actual_table_count
FROM INFORMATION_SCHEMA.TABLES t
WHERE t.TABLE_SCHEMA = DATABASE()
  AND t.TABLE_TYPE = 'BASE TABLE'
  AND EXISTS (
      SELECT 1
      FROM expected_schema_tables e
      WHERE e.table_name = t.TABLE_NAME
  );

SELECT COUNT(*) INTO @expected_index_count
FROM expected_schema_indexes;

SELECT COUNT(*) INTO @actual_index_count
FROM (
    SELECT DISTINCT s.TABLE_NAME, s.INDEX_NAME
    FROM INFORMATION_SCHEMA.STATISTICS s
    WHERE s.TABLE_SCHEMA = DATABASE()
      AND s.INDEX_NAME <> 'PRIMARY'
      AND EXISTS (
          SELECT 1
          FROM expected_schema_indexes e
          WHERE e.table_name = s.TABLE_NAME
            AND e.index_name = s.INDEX_NAME
      )
) actual_indexes;

SELECT
    'SUMMARY' AS result_type,
    @expected_table_count AS expected_tables,
    @actual_table_count AS actual_tables_in_expected_set,
    @expected_column_count AS expected_columns,
    @actual_column_count AS actual_columns_in_expected_tables,
    @expected_index_count AS expected_indexes,
    @actual_index_count AS actual_indexes_in_expected_set;
