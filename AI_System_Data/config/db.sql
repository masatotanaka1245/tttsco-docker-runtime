-- ==================================================================
-- AI システム 統合データベーススキーマ
-- Target: MySQL 8.0.33 / PHP 8.2.8 / Apache 2.4.57
-- Updated: 2026-06-01
-- ==================================================================

CREATE DATABASE IF NOT EXISTS tepscoapp
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE tepscoapp;

-- ------------------------------------------------------------------
-- ユーザー情報
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','user') NOT NULL DEFAULT 'user',
    department VARCHAR(100) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    default_prompt VARCHAR(50) DEFAULT 'construction_consultant' COMMENT 'デフォルトプロンプトの識別子',
    default_lang VARCHAR(10) DEFAULT 'ja' COMMENT '表示言語設定',
    default_model VARCHAR(50) DEFAULT 'gpt-oss:20b' COMMENT '優先使用モデル',
    ollama_host VARCHAR(255) DEFAULT 'http://127.0.0.1:11434' COMMENT 'Ollama接続先URL',
    sub_model VARCHAR(100) DEFAULT 'gpt-oss:20b' COMMENT '中間処理・補助分析用サブモデル',
    embedding_model VARCHAR(100) DEFAULT 'mxbai-embed-large' COMMENT 'ベクトル化専用モデル'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ユーザー認証・所属管理';

-- ------------------------------------------------------------------
-- 業務・案件
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS projects (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_name VARCHAR(255) NOT NULL COMMENT '業務件名',
    description TEXT NULL COMMENT '業務概要',
    start_date DATE NULL COMMENT '工事・業務期間(開始)',
    end_date DATE NULL COMMENT '工事・業務期間(終了)',
    address VARCHAR(512) NULL COMMENT '工事場所・住所',
    latitude DECIMAL(10,8) NULL COMMENT '緯度 (Leaflet.js用)',
    longitude DECIMAL(11,8) NULL COMMENT '経度 (Leaflet.js用)',
    created_by BIGINT UNSIGNED NULL,
    status ENUM('active','completed','on_hold') NOT NULL DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_projects_created_by
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_projects_start_date (start_date),
    INDEX idx_projects_status (status),
    INDEX idx_projects_geo (latitude, longitude),
    INDEX idx_projects_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='業務案件および位置情報管理';

-- ------------------------------------------------------------------
-- 資料メタデータ
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL COMMENT 'プロジェクトID',
    title VARCHAR(255) NOT NULL,
    file_path VARCHAR(512) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_documents_project_id
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_documents_project_id (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='プロジェクト関連資料管理';

-- ------------------------------------------------------------------
-- RAGチャンク
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS doc_chunks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    doc_id BIGINT UNSIGNED NOT NULL,
    chunk_text LONGTEXT NOT NULL COMMENT '抽出されたテキスト本文',
    embedding JSON NOT NULL,
    image_description TEXT NULL,
    page_number INT NULL COMMENT 'PDFの該当ページ番号',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_doc_chunks_doc_id
        FOREIGN KEY (doc_id) REFERENCES documents(id) ON DELETE CASCADE,
    INDEX idx_doc_chunks_doc_id (doc_id),
    FULLTEXT INDEX ft_doc_chunks_chunk_text (chunk_text) WITH PARSER ngram
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ベクトル検索用テキストチャンク';

-- ------------------------------------------------------------------
-- チャット履歴
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chat_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NULL COMMENT '関連業務ID (NULLの場合は汎用対話)',
    user_id BIGINT UNSIGNED NOT NULL COMMENT '対話者ID',
    role ENUM('user','assistant') NOT NULL COMMENT '発話者区分',
    message TEXT NOT NULL COMMENT 'メッセージ内容',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_chat_history_project_id
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_history_user_id
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_chat_history_project_id (project_id),
    INDEX idx_chat_history_user_id (user_id),
    INDEX idx_chat_history_context (project_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='案件ごとのAI対話ログ保存';

-- ------------------------------------------------------------------
-- AI評価
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chat_evaluations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chat_id BIGINT UNSIGNED NOT NULL,
    proactivity_score INT NOT NULL,
    faithfulness_score INT NOT NULL,
    relevance_score INT NOT NULL,
    clarity_score INT NOT NULL,
    total_score INT NOT NULL,
    feedback TEXT NULL,
    retry_count INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_chat_evaluations_chat_id
        FOREIGN KEY (chat_id) REFERENCES chat_history(id) ON DELETE CASCADE,
    INDEX idx_chat_evaluations_chat_id (chat_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='AI回答品質評価';

-- ------------------------------------------------------------------
-- 推論プロセス
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chat_reasoning_steps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chat_history_id BIGINT UNSIGNED NULL COMMENT '最終的なチャット履歴との紐づけ',
    project_id BIGINT UNSIGNED NOT NULL,
    session_id VARCHAR(255) NOT NULL COMMENT '現在進行中のセッション識別',
    original_question LONGTEXT NOT NULL COMMENT 'ユーザーの元の質問',
    step_number INT NOT NULL COMMENT '因数分解されたクエリの連番 (1, 2, 3...)',
    sub_query VARCHAR(512) NOT NULL COMMENT '因数分解されたサブ質問テキスト',
    search_context LONGTEXT NULL COMMENT 'このサブ質問でヒットしたRAG資料情報(JSON)',
    sub_answer LONGTEXT NULL COMMENT 'このサブ質問に対して生成された個別回答',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_chat_reasoning_steps_project_id
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_chat_reasoning_steps_project_id (project_id),
    INDEX idx_chat_reasoning_steps_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='AI多段階推論一時データ保存';

-- ------------------------------------------------------------------
-- 操作ログ
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(255) NOT NULL,
    details JSON NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_logs_user_id
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_logs_user_id (user_id),
    INDEX idx_logs_created_at (created_at),
    INDEX idx_logs_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='システム操作証跡';

-- ------------------------------------------------------------------
-- 案件メンバー
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS project_members (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role ENUM('manager','member','viewer') NOT NULL DEFAULT 'member' COMMENT '案件内の役割',
    assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_project_members_project_id
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_members_user_id
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_project_user (project_id, user_id),
    INDEX idx_project_members_project_id (project_id),
    INDEX idx_project_members_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='案件アサインユーザー管理';

-- ------------------------------------------------------------------
-- 案件メタ情報
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS project_meta (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    meta_key VARCHAR(100) NOT NULL COMMENT '情報のキー (例: client_name, tags, budget)',
    meta_value TEXT NULL COMMENT '情報の内容',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_project_meta_project_id
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    UNIQUE KEY unique_project_key (project_id, meta_key),
    INDEX idx_project_meta_project_id (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='案件追加メタ情報';

-- ------------------------------------------------------------------
-- 案件コメント
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS project_comments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    comment_text TEXT NOT NULL COMMENT 'コメント本文',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_project_comments_project_id
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_comments_user_id
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_project_comments_project_id (project_id),
    INDEX idx_project_comments_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='案件ユーザーコメント';

-- ------------------------------------------------------------------
-- 案件FAQ
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS project_faqs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    chat_history_id BIGINT UNSIGNED NULL COMMENT '元となったチャット履歴ID（任意）',
    question_summary VARCHAR(512) NOT NULL COMMENT 'FAQの質問・課題概要',
    answer_summary TEXT NOT NULL COMMENT 'AIによる回答・解決策の概要',
    created_by BIGINT UNSIGNED NULL COMMENT 'FAQとして登録したユーザー',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_project_faqs_project_id
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_faqs_chat_history_id
        FOREIGN KEY (chat_history_id) REFERENCES chat_history(id) ON DELETE SET NULL,
    CONSTRAINT fk_project_faqs_created_by
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_project_faqs_project_id (project_id),
    INDEX idx_project_faqs_chat_history_id (chat_history_id),
    INDEX idx_project_faqs_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='案件別AIナレッジ・FAQ';

-- ------------------------------------------------------------------
-- CSV管理
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS project_csv_files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL COMMENT 'CSVファイル名',
    column_headers TEXT NOT NULL COMMENT '列名のリスト（JSON配列形式）',
    row_count INT NOT NULL DEFAULT 0 COMMENT '総行数',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_project_csv_files_project_id
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_project_csv_files_project_id (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='CSVインポート管理';

CREATE TABLE IF NOT EXISTS project_csv_rows (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    csv_file_id BIGINT UNSIGNED NOT NULL,
    row_index INT NOT NULL COMMENT '何行目のデータか',
    row_data JSON NOT NULL COMMENT '1行分のデータ',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_project_csv_rows_file_id
        FOREIGN KEY (csv_file_id) REFERENCES project_csv_files(id) ON DELETE CASCADE,
    INDEX idx_project_csv_rows_file_index (csv_file_id, row_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='CSV行データ（JSON形式）';

-- ------------------------------------------------------------------
-- 旧ベクトル保存テーブル互換
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS embeddings (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    document_id BIGINT NOT NULL,
    embedding JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='旧ベクトル保存互換テーブル';

-- ------------------------------------------------------------------
-- 初期データ
-- ------------------------------------------------------------------
INSERT INTO users (username, password_hash, role, department)
VALUES (
    'admin',
    '$2y$10$afVQUm5Pxqc3ccNXsyuZe.fFGAtOSt6.l4bC7pdFsVAi/aW1vZ2fK',
    'admin',
    'システム管理'
)
ON DUPLICATE KEY UPDATE username = username;

INSERT INTO projects (
    project_name,
    description,
    start_date,
    end_date,
    address,
    latitude,
    longitude,
    status
)
SELECT
    '福島第一遮水壁調査',
    '凍土遮水壁の温度維持管理および地下水流動解析支援',
    '2023-10-01',
    '2025-03-31',
    '福島県双葉郡大熊町大字夫沢字北原22',
    37.42140000,
    141.03250000,
    'active'
WHERE NOT EXISTS (
    SELECT 1 FROM projects WHERE project_name = '福島第一遮水壁調査'
);
