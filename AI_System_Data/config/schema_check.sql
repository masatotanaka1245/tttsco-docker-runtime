-- ==================================================================
-- Production schema check for tepscoapp
-- Target: MySQL 8.0.33
-- Usage:
--   1. phpMyAdmin などで tepscoapp を選択した状態で実行してください。
--   2. このSQLは読み取り専用です。ALTER / UPDATE / INSERT / DELETE を含みません。
-- Result:
--   DB_CONTEXT               : 実行中DBの確認
--   MISSING_TABLE            : 本番DBに不足しているテーブル
--   EXTRA_TABLE              : 本番DBにだけ存在するテーブル
--   MISSING_COLUMN           : 本番DBに不足しているカラム
--   EXTRA_COLUMN             : 本番DBにだけ存在するカラム
--   TYPE_MISMATCH            : 型またはNULL許容が期待値と異なるカラム
--   MISSING_INDEX            : 本番DBに不足している、または定義差のあるインデックス
--   DEFAULT_MISMATCH         : DBカラム既定値が期待値と異なる
--   TARGET_*_INVENTORY       : 本番DBの現行定義の棚卸し
-- Note:
--   - schema_check.sql は主にテーブル / カラム / INDEX / 制約 / DB既定値の整合を検査します。
--   - 実行時の実効既定値は AI_System_Data/src/ModelRoleResolver.php を正本とし、
--     OLLAMA_EMBED_MODEL などの環境変数上書きはこのSQLの検査対象外です。
--   - project_meta の meta_key 値そのものや documents / CSV / FAQ の実データ件数は
--     本SQLの対象外です。必要なら別途、運用確認用のSELECTを個別に実行してください。
-- ==================================================================

SELECT
    'DB_CONTEXT' AS report_type,
    DATABASE() AS current_database,
    @@version AS mysql_version,
    @@version_comment AS mysql_distribution;

WITH
expected_tables AS (
    SELECT 'chat_evaluations' AS table_name
    UNION ALL SELECT 'chat_history'
    UNION ALL SELECT 'chat_reasoning_steps'
    UNION ALL SELECT 'chat_threads'
    UNION ALL SELECT 'doc_chunks'
    UNION ALL SELECT 'documents'
    UNION ALL SELECT 'embeddings'
    UNION ALL SELECT 'logs'
    UNION ALL SELECT 'project_comments'
    UNION ALL SELECT 'project_csv_files'
    UNION ALL SELECT 'project_csv_rows'
    UNION ALL SELECT 'project_faqs'
    UNION ALL SELECT 'project_members'
    UNION ALL SELECT 'project_meta'
    UNION ALL SELECT 'projects'
    UNION ALL SELECT 'users'
),
expected_columns AS (
    SELECT 'chat_evaluations' AS table_name, 1 AS ordinal_position, 'id' AS column_name, 'bigint unsigned' AS column_type, 'NO' AS is_nullable
    UNION ALL SELECT 'chat_evaluations', 2, 'chat_id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'chat_evaluations', 3, 'proactivity_score', 'int', 'NO'
    UNION ALL SELECT 'chat_evaluations', 4, 'faithfulness_score', 'int', 'NO'
    UNION ALL SELECT 'chat_evaluations', 5, 'relevance_score', 'int', 'NO'
    UNION ALL SELECT 'chat_evaluations', 6, 'clarity_score', 'int', 'NO'
    UNION ALL SELECT 'chat_evaluations', 7, 'total_score', 'int', 'NO'
    UNION ALL SELECT 'chat_evaluations', 8, 'feedback', 'text', 'YES'
    UNION ALL SELECT 'chat_evaluations', 9, 'retry_count', 'int', 'YES'
    UNION ALL SELECT 'chat_evaluations', 10, 'created_at', 'datetime', 'YES'

    UNION ALL SELECT 'chat_history', 1, 'id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'chat_history', 2, 'project_id', 'bigint unsigned', 'YES'
    UNION ALL SELECT 'chat_history', 3, 'thread_id', 'bigint unsigned', 'YES'
    UNION ALL SELECT 'chat_history', 4, 'user_id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'chat_history', 5, 'role', 'enum(''user'',''assistant'')', 'NO'
    UNION ALL SELECT 'chat_history', 6, 'message', 'text', 'NO'
    UNION ALL SELECT 'chat_history', 7, 'created_at', 'datetime', 'YES'

    UNION ALL SELECT 'chat_reasoning_steps', 1, 'id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'chat_reasoning_steps', 2, 'chat_history_id', 'bigint unsigned', 'YES'
    UNION ALL SELECT 'chat_reasoning_steps', 3, 'project_id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'chat_reasoning_steps', 4, 'session_id', 'varchar(255)', 'NO'
    UNION ALL SELECT 'chat_reasoning_steps', 5, 'original_question', 'longtext', 'NO'
    UNION ALL SELECT 'chat_reasoning_steps', 6, 'step_number', 'int', 'NO'
    UNION ALL SELECT 'chat_reasoning_steps', 7, 'sub_query', 'varchar(512)', 'NO'
    UNION ALL SELECT 'chat_reasoning_steps', 8, 'search_context', 'longtext', 'YES'
    UNION ALL SELECT 'chat_reasoning_steps', 9, 'sub_answer', 'longtext', 'YES'
    UNION ALL SELECT 'chat_reasoning_steps', 10, 'created_at', 'datetime', 'YES'

    UNION ALL SELECT 'chat_threads', 1, 'id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'chat_threads', 2, 'project_id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'chat_threads', 3, 'title', 'varchar(255)', 'NO'
    UNION ALL SELECT 'chat_threads', 4, 'created_by', 'bigint unsigned', 'YES'
    UNION ALL SELECT 'chat_threads', 5, 'created_at', 'datetime', 'YES'
    UNION ALL SELECT 'chat_threads', 6, 'updated_at', 'datetime', 'YES'

    UNION ALL SELECT 'doc_chunks', 1, 'id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'doc_chunks', 2, 'doc_id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'doc_chunks', 3, 'chunk_text', 'longtext', 'NO'
    UNION ALL SELECT 'doc_chunks', 4, 'embedding', 'json', 'NO'
    UNION ALL SELECT 'doc_chunks', 5, 'image_description', 'text', 'YES'
    UNION ALL SELECT 'doc_chunks', 6, 'page_number', 'int', 'YES'
    UNION ALL SELECT 'doc_chunks', 7, 'created_at', 'datetime', 'YES'

    UNION ALL SELECT 'documents', 1, 'id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'documents', 2, 'project_id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'documents', 3, 'title', 'varchar(255)', 'NO'
    UNION ALL SELECT 'documents', 4, 'file_path', 'varchar(512)', 'NO'
    UNION ALL SELECT 'documents', 5, 'created_at', 'datetime', 'YES'

    UNION ALL SELECT 'embeddings', 1, 'id', 'bigint', 'NO'
    UNION ALL SELECT 'embeddings', 2, 'document_id', 'bigint', 'NO'
    UNION ALL SELECT 'embeddings', 3, 'embedding', 'json', 'NO'
    UNION ALL SELECT 'embeddings', 4, 'created_at', 'timestamp', 'YES'

    UNION ALL SELECT 'logs', 1, 'id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'logs', 2, 'user_id', 'bigint unsigned', 'YES'
    UNION ALL SELECT 'logs', 3, 'action', 'varchar(255)', 'NO'
    UNION ALL SELECT 'logs', 4, 'details', 'json', 'YES'
    UNION ALL SELECT 'logs', 5, 'created_at', 'datetime', 'YES'

    UNION ALL SELECT 'project_comments', 1, 'id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'project_comments', 2, 'project_id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'project_comments', 3, 'user_id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'project_comments', 4, 'comment_text', 'text', 'NO'
    UNION ALL SELECT 'project_comments', 5, 'created_at', 'datetime', 'YES'
    UNION ALL SELECT 'project_comments', 6, 'updated_at', 'datetime', 'YES'

    UNION ALL SELECT 'project_csv_files', 1, 'id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'project_csv_files', 2, 'project_id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'project_csv_files', 3, 'file_name', 'varchar(255)', 'NO'
    UNION ALL SELECT 'project_csv_files', 4, 'column_headers', 'text', 'NO'
    UNION ALL SELECT 'project_csv_files', 5, 'row_count', 'int', 'NO'
    UNION ALL SELECT 'project_csv_files', 6, 'created_at', 'datetime', 'YES'

    UNION ALL SELECT 'project_csv_rows', 1, 'id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'project_csv_rows', 2, 'csv_file_id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'project_csv_rows', 3, 'row_index', 'int', 'NO'
    UNION ALL SELECT 'project_csv_rows', 4, 'row_data', 'json', 'NO'
    UNION ALL SELECT 'project_csv_rows', 5, 'created_at', 'datetime', 'YES'

    UNION ALL SELECT 'project_faqs', 1, 'id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'project_faqs', 2, 'project_id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'project_faqs', 3, 'chat_history_id', 'bigint unsigned', 'YES'
    UNION ALL SELECT 'project_faqs', 4, 'question_summary', 'varchar(512)', 'NO'
    UNION ALL SELECT 'project_faqs', 5, 'answer_summary', 'text', 'NO'
    UNION ALL SELECT 'project_faqs', 6, 'created_by', 'bigint unsigned', 'YES'
    UNION ALL SELECT 'project_faqs', 7, 'created_at', 'datetime', 'YES'

    UNION ALL SELECT 'project_members', 1, 'id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'project_members', 2, 'project_id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'project_members', 3, 'user_id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'project_members', 4, 'role', 'enum(''manager'',''member'',''viewer'')', 'NO'
    UNION ALL SELECT 'project_members', 5, 'assigned_at', 'datetime', 'YES'

    UNION ALL SELECT 'project_meta', 1, 'id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'project_meta', 2, 'project_id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'project_meta', 3, 'meta_key', 'varchar(100)', 'NO'
    UNION ALL SELECT 'project_meta', 4, 'meta_value', 'text', 'YES'
    UNION ALL SELECT 'project_meta', 5, 'created_at', 'datetime', 'YES'
    UNION ALL SELECT 'project_meta', 6, 'updated_at', 'datetime', 'YES'

    UNION ALL SELECT 'projects', 1, 'id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'projects', 2, 'project_name', 'varchar(255)', 'NO'
    UNION ALL SELECT 'projects', 3, 'description', 'text', 'YES'
    UNION ALL SELECT 'projects', 4, 'start_date', 'date', 'YES'
    UNION ALL SELECT 'projects', 5, 'end_date', 'date', 'YES'
    UNION ALL SELECT 'projects', 6, 'address', 'varchar(512)', 'YES'
    UNION ALL SELECT 'projects', 7, 'latitude', 'decimal(10,8)', 'YES'
    UNION ALL SELECT 'projects', 8, 'longitude', 'decimal(11,8)', 'YES'
    UNION ALL SELECT 'projects', 9, 'created_by', 'bigint unsigned', 'YES'
    UNION ALL SELECT 'projects', 10, 'status', 'enum(''active'',''completed'',''on_hold'')', 'NO'
    UNION ALL SELECT 'projects', 11, 'created_at', 'datetime', 'YES'
    UNION ALL SELECT 'projects', 12, 'updated_at', 'datetime', 'YES'

    UNION ALL SELECT 'users', 1, 'id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'users', 2, 'username', 'varchar(64)', 'NO'
    UNION ALL SELECT 'users', 3, 'password_hash', 'varchar(255)', 'NO'
    UNION ALL SELECT 'users', 4, 'role', 'enum(''admin'',''user'')', 'NO'
    UNION ALL SELECT 'users', 5, 'department', 'varchar(100)', 'YES'
    UNION ALL SELECT 'users', 6, 'created_at', 'datetime', 'YES'
    UNION ALL SELECT 'users', 7, 'updated_at', 'datetime', 'YES'
    UNION ALL SELECT 'users', 8, 'default_prompt', 'varchar(50)', 'YES'
    UNION ALL SELECT 'users', 9, 'default_lang', 'varchar(10)', 'YES'
    UNION ALL SELECT 'users', 10, 'default_model', 'varchar(50)', 'YES'
    UNION ALL SELECT 'users', 11, 'ollama_host', 'varchar(255)', 'YES'
    UNION ALL SELECT 'users', 12, 'sub_model', 'varchar(100)', 'YES'
    UNION ALL SELECT 'users', 13, 'sql_model', 'varchar(100)', 'YES'
    UNION ALL SELECT 'users', 14, 'embedding_model', 'varchar(100)', 'YES'
    UNION ALL SELECT 'users', 15, 'vision_model', 'varchar(100)', 'YES'
),
expected_indexes AS (
    SELECT 'chat_evaluations' AS table_name, 'idx_chat_evaluations_chat_id' AS index_name, 1 AS non_unique, 'chat_id' AS columns_csv

    UNION ALL SELECT 'chat_history', 'idx_chat_history_context', 1, 'project_id,created_at'
    UNION ALL SELECT 'chat_history', 'idx_chat_history_project_id', 1, 'project_id'
    UNION ALL SELECT 'chat_history', 'idx_chat_history_thread_context', 1, 'thread_id,created_at'
    UNION ALL SELECT 'chat_history', 'idx_chat_history_thread_id', 1, 'thread_id'
    UNION ALL SELECT 'chat_history', 'idx_chat_history_user_id', 1, 'user_id'

    UNION ALL SELECT 'chat_reasoning_steps', 'idx_chat_reasoning_steps_project_id', 1, 'project_id'
    UNION ALL SELECT 'chat_reasoning_steps', 'idx_chat_reasoning_steps_session', 1, 'session_id'

    UNION ALL SELECT 'chat_threads', 'idx_chat_threads_project_id', 1, 'project_id'
    UNION ALL SELECT 'chat_threads', 'idx_chat_threads_updated_at', 1, 'updated_at'

    UNION ALL SELECT 'doc_chunks', 'ft_doc_chunks_chunk_text', 1, 'chunk_text'
    UNION ALL SELECT 'doc_chunks', 'idx_doc_chunks_doc_id', 1, 'doc_id'

    UNION ALL SELECT 'documents', 'idx_documents_project_id', 1, 'project_id'

    UNION ALL SELECT 'logs', 'idx_logs_action', 1, 'action'
    UNION ALL SELECT 'logs', 'idx_logs_created_at', 1, 'created_at'
    UNION ALL SELECT 'logs', 'idx_logs_user_id', 1, 'user_id'

    UNION ALL SELECT 'project_comments', 'idx_project_comments_project_id', 1, 'project_id'
    UNION ALL SELECT 'project_comments', 'idx_project_comments_user_id', 1, 'user_id'

    UNION ALL SELECT 'project_csv_files', 'idx_project_csv_files_project_id', 1, 'project_id'
    UNION ALL SELECT 'project_csv_rows', 'idx_project_csv_rows_file_index', 1, 'csv_file_id,row_index'

    UNION ALL SELECT 'project_faqs', 'idx_project_faqs_chat_history_id', 1, 'chat_history_id'
    UNION ALL SELECT 'project_faqs', 'idx_project_faqs_created_by', 1, 'created_by'
    UNION ALL SELECT 'project_faqs', 'idx_project_faqs_project_id', 1, 'project_id'

    UNION ALL SELECT 'project_members', 'idx_project_members_project_id', 1, 'project_id'
    UNION ALL SELECT 'project_members', 'idx_project_members_user_id', 1, 'user_id'
    UNION ALL SELECT 'project_members', 'unique_project_user', 0, 'project_id,user_id'

    UNION ALL SELECT 'project_meta', 'idx_project_meta_project_id', 1, 'project_id'
    UNION ALL SELECT 'project_meta', 'unique_project_key', 0, 'project_id,meta_key'

    UNION ALL SELECT 'projects', 'idx_projects_created_by', 1, 'created_by'
    UNION ALL SELECT 'projects', 'idx_projects_geo', 1, 'latitude,longitude'
    UNION ALL SELECT 'projects', 'idx_projects_start_date', 1, 'start_date'
    UNION ALL SELECT 'projects', 'idx_projects_status', 1, 'status'

    UNION ALL SELECT 'users', 'username', 0, 'username'
),
expected_defaults AS (
    SELECT 'project_csv_files' AS table_name, 'row_count' AS column_name, '0' AS expected_default
    UNION ALL SELECT 'users', 'default_prompt', 'construction_consultant'
    UNION ALL SELECT 'users', 'default_lang', 'ja'
    UNION ALL SELECT 'users', 'default_model', 'gemma4:e4b'
    UNION ALL SELECT 'users', 'ollama_host', 'http://127.0.0.1:11434'
    UNION ALL SELECT 'users', 'sub_model', 'gemma4:e4b'
    UNION ALL SELECT 'users', 'sql_model', 'codellama:7b'
    UNION ALL SELECT 'users', 'embedding_model', 'mxbai-embed-large'
    UNION ALL SELECT 'users', 'vision_model', 'gemma4:e4b'
),
actual_indexes AS (
    SELECT
        TABLE_NAME,
        INDEX_NAME,
        MIN(NON_UNIQUE) AS non_unique,
        GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS columns_csv
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND INDEX_NAME <> 'PRIMARY'
    GROUP BY TABLE_NAME, INDEX_NAME
)
SELECT
    'MISSING_TABLE' AS issue,
    e.table_name,
    NULL AS object_name,
    'table must exist' AS expected_value,
    'missing' AS actual_value,
    NULL AS detail
FROM expected_tables e
LEFT JOIN INFORMATION_SCHEMA.TABLES t
    ON t.TABLE_SCHEMA = DATABASE()
   AND t.TABLE_NAME = e.table_name
WHERE t.TABLE_NAME IS NULL

UNION ALL

SELECT
    'EXTRA_TABLE' AS issue,
    t.TABLE_NAME AS table_name,
    NULL AS object_name,
    'not expected by current code snapshot' AS expected_value,
    'present' AS actual_value,
    NULL AS detail
FROM INFORMATION_SCHEMA.TABLES t
LEFT JOIN expected_tables e
    ON e.table_name = t.TABLE_NAME
WHERE t.TABLE_SCHEMA = DATABASE()
  AND t.TABLE_TYPE = 'BASE TABLE'
  AND e.table_name IS NULL

UNION ALL

SELECT
    'MISSING_COLUMN' AS issue,
    e.table_name,
    e.column_name AS object_name,
    CONCAT(e.column_type, ' / nullable=', e.is_nullable) AS expected_value,
    'missing' AS actual_value,
    CONCAT('ordinal_position=', e.ordinal_position) AS detail
FROM expected_columns e
LEFT JOIN INFORMATION_SCHEMA.COLUMNS c
    ON c.TABLE_SCHEMA = DATABASE()
   AND c.TABLE_NAME = e.table_name
   AND c.COLUMN_NAME = e.column_name
WHERE c.COLUMN_NAME IS NULL

UNION ALL

SELECT
    'EXTRA_COLUMN' AS issue,
    c.TABLE_NAME AS table_name,
    c.COLUMN_NAME AS object_name,
    'not expected by current code snapshot' AS expected_value,
    CONCAT(c.COLUMN_TYPE, ' / nullable=', c.IS_NULLABLE) AS actual_value,
    CONCAT('ordinal_position=', c.ORDINAL_POSITION) AS detail
FROM INFORMATION_SCHEMA.COLUMNS c
LEFT JOIN expected_columns e
    ON e.table_name = c.TABLE_NAME
   AND e.column_name = c.COLUMN_NAME
WHERE c.TABLE_SCHEMA = DATABASE()
  AND e.column_name IS NULL

UNION ALL

SELECT
    'TYPE_MISMATCH' AS issue,
    e.table_name,
    e.column_name AS object_name,
    CONCAT(e.column_type, ' / nullable=', e.is_nullable) AS expected_value,
    CONCAT(c.COLUMN_TYPE, ' / nullable=', c.IS_NULLABLE) AS actual_value,
    CONCAT('ordinal_position=', e.ordinal_position) AS detail
FROM expected_columns e
JOIN INFORMATION_SCHEMA.COLUMNS c
    ON c.TABLE_SCHEMA = DATABASE()
   AND c.TABLE_NAME = e.table_name
   AND c.COLUMN_NAME = e.column_name
WHERE LOWER(c.COLUMN_TYPE) <> LOWER(e.column_type)
   OR c.IS_NULLABLE <> e.is_nullable

UNION ALL

SELECT
    'MISSING_INDEX' AS issue,
    e.table_name,
    e.index_name AS object_name,
    CONCAT('non_unique=', e.non_unique, ' / columns=', e.columns_csv) AS expected_value,
    COALESCE(CONCAT('non_unique=', a.non_unique, ' / columns=', a.columns_csv), 'missing') AS actual_value,
    NULL AS detail
FROM expected_indexes e
LEFT JOIN actual_indexes a
    ON a.TABLE_NAME = e.table_name
   AND a.INDEX_NAME = e.index_name
WHERE a.INDEX_NAME IS NULL
   OR a.columns_csv <> e.columns_csv
   OR a.non_unique <> e.non_unique

UNION ALL

SELECT
    'DEFAULT_MISMATCH' AS issue,
    e.table_name,
    e.column_name AS object_name,
    COALESCE(e.expected_default, 'NULL') AS expected_value,
    COALESCE(c.COLUMN_DEFAULT, 'NULL') AS actual_value,
    NULL AS detail
FROM expected_defaults e
JOIN INFORMATION_SCHEMA.COLUMNS c
    ON c.TABLE_SCHEMA = DATABASE()
   AND c.TABLE_NAME = e.table_name
   AND c.COLUMN_NAME = e.column_name
WHERE COALESCE(c.COLUMN_DEFAULT, '__NULL__') <> COALESCE(e.expected_default, '__NULL__')

ORDER BY issue, table_name, object_name;

SELECT
    'TARGET_TABLE_INVENTORY' AS report_type,
    t.TABLE_NAME,
    t.ENGINE,
    t.TABLE_COLLATION,
    t.TABLE_ROWS,
    t.CREATE_TIME,
    t.UPDATE_TIME
FROM INFORMATION_SCHEMA.TABLES t
WHERE t.TABLE_SCHEMA = DATABASE()
  AND t.TABLE_NAME IN (
      'project_meta',
      'chat_threads',
      'chat_history',
      'documents',
      'project_csv_files',
      'project_csv_rows',
      'project_comments',
      'project_faqs',
      'users'
  )
ORDER BY t.TABLE_NAME;

SELECT
    'TARGET_COLUMN_INVENTORY' AS report_type,
    c.TABLE_NAME,
    c.ORDINAL_POSITION,
    c.COLUMN_NAME,
    c.COLUMN_TYPE,
    c.IS_NULLABLE,
    c.COLUMN_DEFAULT,
    c.COLUMN_KEY,
    c.EXTRA
FROM INFORMATION_SCHEMA.COLUMNS c
WHERE c.TABLE_SCHEMA = DATABASE()
  AND c.TABLE_NAME IN (
      'project_meta',
      'chat_threads',
      'chat_history',
      'documents',
      'project_csv_files',
      'project_csv_rows',
      'project_comments',
      'project_faqs',
      'users'
  )
ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION;

SELECT
    'TARGET_INDEX_INVENTORY' AS report_type,
    s.TABLE_NAME,
    s.INDEX_NAME,
    MIN(s.NON_UNIQUE) AS non_unique,
    GROUP_CONCAT(s.COLUMN_NAME ORDER BY s.SEQ_IN_INDEX SEPARATOR ',') AS columns_csv,
    MIN(s.INDEX_TYPE) AS index_type
FROM INFORMATION_SCHEMA.STATISTICS s
WHERE s.TABLE_SCHEMA = DATABASE()
  AND s.TABLE_NAME IN (
      'project_meta',
      'chat_threads',
      'chat_history',
      'documents',
      'project_csv_files',
      'project_csv_rows',
      'project_comments',
      'project_faqs',
      'users'
  )
GROUP BY s.TABLE_NAME, s.INDEX_NAME
ORDER BY s.TABLE_NAME, s.INDEX_NAME;

SELECT
    'TARGET_CONSTRAINT_INVENTORY' AS report_type,
    tc.TABLE_NAME,
    tc.CONSTRAINT_NAME,
    tc.CONSTRAINT_TYPE
FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
WHERE tc.TABLE_SCHEMA = DATABASE()
  AND tc.TABLE_NAME IN (
      'project_meta',
      'chat_threads',
      'chat_history',
      'documents',
      'project_csv_files',
      'project_csv_rows',
      'project_comments',
      'project_faqs',
      'users'
  )
ORDER BY tc.TABLE_NAME, tc.CONSTRAINT_TYPE, tc.CONSTRAINT_NAME;
