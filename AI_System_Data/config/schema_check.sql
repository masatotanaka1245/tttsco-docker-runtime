-- ==================================================================
-- Production schema check for tepscoapp
-- Target: MySQL 8.0.33
-- Usage: phpMyAdmin で tepscoapp を選択して、このSQLを実行してください。
-- Result:
--   MISSING_COLUMN : 本番DBに不足しているカラム
--   EXTRA_COLUMN   : 本番DBにだけ存在するカラム
--   TYPE_MISMATCH  : 型またはNULL許容が期待値と異なるカラム
-- ==================================================================

USE tepscoapp;

DROP TEMPORARY TABLE IF EXISTS expected_schema_columns;

CREATE TEMPORARY TABLE expected_schema_columns (
    table_name VARCHAR(64) NOT NULL,
    ordinal_position INT NOT NULL,
    column_name VARCHAR(64) NOT NULL,
    column_type VARCHAR(255) NOT NULL,
    is_nullable VARCHAR(3) NOT NULL,
    PRIMARY KEY (table_name, column_name)
);

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
('chat_history', 3, 'user_id', 'bigint unsigned', 'NO'),
('chat_history', 4, 'role', 'enum(''user'',''assistant'')', 'NO'),
('chat_history', 5, 'message', 'text', 'NO'),
('chat_history', 6, 'created_at', 'datetime', 'YES'),
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
('users', 12, 'sub_model', 'varchar(100)', 'YES');

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

SELECT
    'SUMMARY' AS result_type,
    @expected_column_count AS expected_columns,
    @actual_column_count AS actual_columns_in_expected_tables;
