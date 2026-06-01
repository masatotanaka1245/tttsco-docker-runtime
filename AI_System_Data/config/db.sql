-- ==================================================================
-- AI システム 統合データベーススキーマ (MySQL 8.0 以上推奨)
-- 作成日: 2024-04-24
-- ==================================================================

-- 1. データベースの作成
CREATE DATABASE IF NOT EXISTS tepscoapp 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE tepscoapp;

-- ------------------------------------------------------------------
-- 2. ユーザー情報テーブル
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(64) NOT NULL UNIQUE COMMENT 'ログインID',
    password_hash VARCHAR(255) NOT NULL COMMENT 'PHP password_hash値',
    department    VARCHAR(128) NULL COMMENT '所属分野 (例: 地質分野)',
    role          ENUM('admin', 'user') NOT NULL DEFAULT 'user' COMMENT '権限レベル',
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username)
) ENGINE=InnoDB COMMENT='ユーザー認証・所属管理';

ALTER TABLE users 
ADD COLUMN default_prompt VARCHAR(50) DEFAULT 'construction_consultant' COMMENT 'デフォルトプロンプトの識別子',
ADD COLUMN default_lang VARCHAR(10) DEFAULT 'ja' COMMENT '表示言語設定',
ADD COLUMN default_model VARCHAR(50) DEFAULT 'gpt-oss:20b' COMMENT '優先使用モデル',
ADD COLUMN ollama_host VARCHAR(255) DEFAULT 'http://host.docker.internal:11434' COMMENT 'Ollama接続先URL',
ADD COLUMN sub_model VARCHAR(100) DEFAULT 'gpt-oss:20b' COMMENT 'フル思考時の統合用サブモデル';

-- ------------------------------------------------------------------
-- 3. 業務（案件）管理テーブル
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS projects (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_name  VARCHAR(255) NOT NULL COMMENT '業務件名',
    description   TEXT NULL COMMENT '業務概要',
    start_date    DATE NULL COMMENT '工事・業務期間(開始)',
    end_date      DATE NULL COMMENT '工事・業務期間(終了)',
    address       VARCHAR(512) NULL COMMENT '工事場所・住所',
    latitude      DECIMAL(10, 8) NULL COMMENT '緯度 (Leaflet.js用)',
    longitude     DECIMAL(11, 8) NULL COMMENT '経度 (Leaflet.js用)',
    created_by    BIGINT UNSIGNED NULL COMMENT '作成者ユーザーID',
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- 制約: 作成者が消えても案件データは残し、NULLをセットする
    CONSTRAINT fk_projects_created_by FOREIGN KEY (created_by) 
        REFERENCES users(id) ON DELETE SET NULL,
        
    -- 検索最適化
    INDEX idx_project_date (start_date, end_date),
    INDEX idx_geo (latitude, longitude),
    -- 業務名での部分一致検索を想定したインデックス
    INDEX idx_project_name (project_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='業務案件および位置情報管理';

-- ──────────────────────────────────────────────────────────────────────
-- 1️⃣ projects テーブルに status カラムを安全に追加
--    進行中（active）、完了（completed）、保留（on_hold）の 3 種類を採用
--    既存レコードはデフォルトで「active」とみなします
-- ──────────────────────────────────────────────────────────────────────
ALTER TABLE `projects`
  ADD COLUMN `status` ENUM('active','completed','on_hold')
    NOT NULL DEFAULT 'active'
    AFTER `created_by`;

-- 2️⃣ 速い検索のためにインデックスを作成
CREATE INDEX idx_projects_status ON `projects`(`status`);

-- ------------------------------------------------------------------
-- 4. ドキュメントメタデータテーブル
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS documents (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id    BIGINT UNSIGNED NOT NULL COMMENT '所属プロジェクトID',
    title         VARCHAR(255) NOT NULL COMMENT '資料タイトル',
    file_path     VARCHAR(512) NOT NULL COMMENT 'サーバー上の絶対パス',
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_project_docs (project_id)
) ENGINE=InnoDB COMMENT='プロジェクト関連資料管理';

-- documents テーブルの project_id 制約再設定
ALTER TABLE `documents` 
  DROP FOREIGN KEY `fk_documents_project_id`;
  
ALTER TABLE `documents`
  ADD CONSTRAINT `fk_documents_project_id` 
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) 
  ON DELETE CASCADE;

-- ------------------------------------------------------------------
-- 5. ドキュメントチャンクテーブル (RAG/ベクトル検索用)
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS doc_chunks (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    doc_id        BIGINT UNSIGNED NOT NULL COMMENT '元資料ID',
    chunk_text    LONGTEXT NOT NULL COMMENT '抽出されたテキスト本文',
    embedding     JSON NOT NULL COMMENT '多次元ベクトル [0.1, -0.2, ...]',
    image_description TEXT NULL COMMENT '画像または図表の説明',
    page_number   INT NULL COMMENT 'PDFの該当ページ番号',
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (doc_id) REFERENCES documents(id) ON DELETE CASCADE,
    INDEX idx_doc_chunks (doc_id),
    FULLTEXT INDEX ft_chunk_text (chunk_text) WITH PARSER ngram -- 日本語キーワード検索用
) ENGINE=InnoDB COMMENT='ベクトル検索用テキストチャンク';

-- ------------------------------------------------------------------
-- 6. AI対話履歴テーブル
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chat_history (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id    BIGINT UNSIGNED NOT NULL COMMENT '対話の文脈となるプロジェクトID',
    user_id       BIGINT UNSIGNED NOT NULL COMMENT '対話者ID',
    role          ENUM('user', 'assistant') NOT NULL COMMENT '発話者区分',
    message       TEXT NOT NULL COMMENT 'メッセージ内容',
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_history_context (project_id, created_at)
) ENGINE=InnoDB COMMENT='案件ごとのAI対話ログ保存';

-- 1️⃣ chat_history テーブルの project_id を NULL 許容に変更 (ダッシュボード汎用チャット対応)
-- ※ 既に NULL 許容であればこのクエリを実行しても安全です
ALTER TABLE `chat_history` 
  MODIFY COLUMN `project_id` BIGINT UNSIGNED NULL COMMENT '関連業務ID (NULLの場合は汎用対話)';

  -- chat_history テーブルの project_id 制約再設定
ALTER TABLE `chat_history` 
  DROP FOREIGN KEY `fk_chat_history_project_id`;

ALTER TABLE `chat_history`
  ADD CONSTRAINT `fk_chat_history_project_id` 
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) 
  ON DELETE CASCADE;

-- ------------------------------------------------------------------
-- 7. 操作ログテーブル (監査用)
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS logs (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       BIGINT UNSIGNED NULL,
    action        VARCHAR(255) NOT NULL COMMENT 'アクション名(LOGIN, UPLOAD, CHAT等)',
    details       JSON NULL COMMENT '詳細パラメータ',
    ip_address    VARCHAR(45) NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_logs_created (created_at),
    INDEX idx_logs_action (action)
) ENGINE=InnoDB COMMENT='システム操作証跡';

-- ------------------------------------------------------------------
-- 8. 初期データ投入
-- ------------------------------------------------------------------

-- 管理者ユーザーの作成 (パスワード: admin123)
INSERT INTO users (username, password_hash, role, department)
VALUES (
    'admin', 
    '$2y$10$89v8l8eOe6c5jNfKp8O8f8gGkJ9q9w9Zk8lM1234567890abc', 
    'admin', 
    'システム管理'
)
ON DUPLICATE KEY UPDATE username=username;

-- サンプル案件の作成 (福島第一遮水壁調査)
INSERT INTO projects (
    project_name, 
    description, 
    start_date, 
    end_date, 
    address, 
    latitude, 
    longitude
)
VALUES (
    '福島第一遮水壁調査', 
    '凍土遮水壁の温度維持管理および地下水流動解析支援', 
    '2023-10-01', 
    '2025-03-31', 
    '福島県双葉郡大熊町大字夫沢字北原22', 
    37.42140000, 
    141.03250000
);

CREATE TABLE embeddings (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    document_id BIGINT NOT NULL,
    embedding JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------------
-- スキーマ適用完了
-- ------------------------------------------------------------------

-- 推論プロセス（因数分解タスク）管理テーブル
CREATE TABLE IF NOT EXISTS chat_reasoning_steps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chat_history_id BIGINT UNSIGNED NULL COMMENT '最終的なチャット履歴との紐づけ',
    project_id BIGINT UNSIGNED NOT NULL,
    session_id VARCHAR(255) NOT NULL COMMENT '現在進行中のセッション識別',
    original_question TEXT NOT NULL COMMENT 'ユーザーの元の質問',
    step_number INT NOT NULL COMMENT '因数分解されたクエリの連番 (1, 2, 3...)',
    sub_query VARCHAR(512) NOT NULL COMMENT '因数分解されたサブ質問テキスト',
    search_context TEXT NULL COMMENT 'このサブ質問でヒットしたRAG資料情報(JSON)',
    sub_answer TEXT NULL COMMENT 'このサブ質問に対して生成された個別回答',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='AI多段階推論一時データ保存';

-- ──────────────────────────────────────────────────────────────────────
-- 案件（プロジェクト）機能拡張用 リレーションテーブル定義
-- ──────────────────────────────────────────────────────────────────────

-- 1. 業務アサイン管理テーブル (project_members)
-- どのユーザーが、どの案件に、どのような役割で参加しているかを管理します。
CREATE TABLE IF NOT EXISTS project_members (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role ENUM('manager', 'member', 'viewer') NOT NULL DEFAULT 'member' COMMENT '案件内の役割',
    assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_project_user (project_id, user_id) -- 同じ案件に同じ人を重複登録させない
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='案件アサインユーザー管理';


-- 2. 業務への追加情報テーブル (project_meta)
-- 案件ごとに異なる柔軟な付随情報（タグ、顧客名、予算、特別なパラメーターなど）を
-- Key-Value形式、またはJSON形式で保存できるようにします。
CREATE TABLE IF NOT EXISTS project_meta (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    meta_key VARCHAR(100) NOT NULL COMMENT '情報のキー (例: client_name, tags, budget)',
    meta_value TEXT NULL COMMENT '情報の内容',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    UNIQUE KEY unique_project_key (project_id, meta_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='案件追加メタ情報';


-- 3. 業務へのユーザーコメントテーブル (project_comments)
-- チームメンバーが案件に対してメモや進捗の申し送り、議論を残せるようにします。
CREATE TABLE IF NOT EXISTS project_comments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    comment_text TEXT NOT NULL COMMENT 'コメント本文',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='案件ユーザーコメント';


-- 4. 業務のAIチャットFAQ・ナレッジテーブル (project_faqs)
-- チャットで解決した「重要なやり取り（気づき）」を、後から誰でも見られるように
-- FAQや概要として保存（ピン留め）しておくテーブルです。
CREATE TABLE IF NOT EXISTS project_faqs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    chat_history_id BIGINT UNSIGNED NULL COMMENT '元となったチャット履歴ID（任意）',
    question_summary VARCHAR(512) NOT NULL COMMENT 'FAQの質問・課題概要',
    answer_summary TEXT NOT NULL COMMENT 'AIによる回答・解決策の概要',
    created_by BIGINT UNSIGNED NULL COMMENT 'FAQとして登録したユーザー',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (chat_history_id) REFERENCES chat_history(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='案件別AIナレッジ・FAQ';


-- 1. CSVインポート管理テーブル（メタデータ）
CREATE TABLE IF NOT EXISTS project_csv_files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL COMMENT 'CSVファイル名',
    column_headers TEXT NOT NULL COMMENT '列名のリスト（JSON配列形式）',
    row_count INT NOT NULL DEFAULT 0 COMMENT '総行数',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='CSVインポート管理';

-- 2. CSVレコード（行）保存テーブル
-- 各行のデータをJSON型で保存することで、列数が異なるCSVでも同一テーブルに高速に格納できます。
CREATE TABLE IF NOT EXISTS project_csv_rows (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    csv_file_id BIGINT UNSIGNED NOT NULL,
    row_index INT NOT NULL COMMENT '何行目のデータか',
    row_data JSON NOT NULL COMMENT '1行分のデータ (例: {"測定日時": "2026/05/21", "水位(m)": 2.45})',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (csv_file_id) REFERENCES project_csv_files(id) ON DELETE CASCADE,
    
    -- 高速化インデックス
    INDEX idx_csv_file (csv_file_id, row_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='CSV行データ（JSON形式）';

CREATE TABLE chat_evaluations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chat_id BIGINT UNSIGNED NOT NULL,          -- 評価対象のチャット履歴ID
    proactivity_score INT NOT NULL,            -- 提案力 (0-100)
    faithfulness_score INT NOT NULL,           -- 忠実性 (0-100)
    relevance_score INT NOT NULL,              -- 的確性 (0-100)
    clarity_score INT NOT NULL,                -- 可読性 (0-100)
    total_score INT NOT NULL,                  -- 総合スコア (0-100)
    feedback TEXT,                             -- AI自身のダメ出しコメント
    retry_count INT DEFAULT 0,                 -- 自己修正を行った回数（0=一発合格, 1=1回書き直し）
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (chat_id) REFERENCES chat_history(id) ON DELETE CASCADE
) ENGINE=InnoDB;
