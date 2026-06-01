TEPSCOルーティンズ (AI System for Civil Engineering)
建設コンサルタント実務特化型 AI 支援プラットフォーム
建設コンサルタントの全11分野における業務高度化を目的とし、情報の高密度化とオンプレミス環境での安全性を両立させた統合システム。

スタック	バージョン
PHP	8.2.8
MySQL	8.0.33
Apache	2.4.57
Ollama	0.3.x (LLM)
Leaflet.js	1.9.4
Tailwind CSS	3.x

1. 要件定義書
1.1 システムの目的
建設コンサルタント業（全11分野）の共通業務を、PHP 8.2.8 と MySQL 8.0.33 を活用して効率化する。
完全オンプレミス環境にて、LLM（大規模言語モデル）を用いた RAG検索、PDF管理・閲覧、地理空間検索を実現し、セキュアかつ直感的なデータ管理を行う。

1.2 機能要件
機能	内容	主要ファイル
対話型AI UI	専門業務をサポートするチャットインターフェース（PHP実装）	public/api/chat.php
RAG検索	過去報告書等の意味検索（MySQL 上でベクトルデータを管理）	src/EmbeddingEngine.php, src/VectorSearch.php, src/RAGPipeline.php
フォルダ監視自動化	共有フォルダ（public/）を常時監視し、ファイル投入時に自動で DB へインデックス化	scripts/watchdog.php
PDF管理・閲覧	分野別の資料管理と、ブラウザ内でのセキュアな PDF ビューア機能	public/support.php, public/viewer.php, public/api/delete_pdf.php
PDF削除	既存 PDF をリストから削除できる機能	public/api/delete_pdf.php
地理空間検索	案件座標に基づいた地図（Leaflet.js）上からの資料抽出	public/search.php
検索結果の全分野横断	キーワード＋空間検索結果を一覧表示	public/search.php
検索結果の PDF プレビュー	選択した資料を即座に確認	public/search.php
ログ自動集計	監査ログ、チャットログ、ファイル操作ログを自動で集計	logs/, config/database.php
API エンドポイント	chat.php, upload.php, delete_pdf.php, serve_pdf.php, search.php など	public/api/
案件情報の編集support.phpで案件情報（案件名・住所・座標・備考など）を編集できるモーダルを実装。入力内容は`api/update_project.php`にPOSTされ、PDOで更新されます。public/support.php,api/update_project.php
資料の検索search.phpでキーワード＋地理空間検索を実行し、該当PDFを一覧表示。選択したPDFはviewer.phpでプレビューします。public/search.php,public/viewer.php

1.3 フォルダ構成（変更点を含む）

\\10.5.98.129\htdocs\tepscoapp1
├── AI_System_Data
│   ├── public
│   │   ├── assets
│   │   │   └── css
│   │   │       ├── styles.css
│   │   │       └── tailwind.css
│   │   ├── templates
│   │   │   ├── footer.php
│   │   │   └── header.php
│   │   ├── api
│   │   │   ├── add_project.php
│   │   │   ├── chat.php
│   │   │   ├── upload.php
│   │   │   ├── user_api.php
│   │   │   ├── delete_pdf.php
│   │   │   └── view_pdf.php
│   │   ├── .htaccess
│   │   ├── debug_hash.php
│   │   ├── index.php
│   │   ├── login.php
│   │   ├── logout.php
│   │   ├── search.php
│   │   ├── serve_pdf.php
│   │   ├── support.php
│   │   ├── user_management.php
│   │   └── viewer.php
│   ├── config
│   │   ├── database.php
│   │   ├── session.php
│   │   ├── modules/
│   │   │    ├── api.js       # (1) 共通通信処理・設定取得
│   │   │    │    ├── map.js       # (2) Leaflet地図操作・住所検索
│   │   │    │    ├── project.js   # (3) 案件のCRUD処理 (登録/更新/削除)
│   │   │    │    ├── chat.js      # (4) AIチャット送受信・UI描画
│   │   │    │    ├── upload.js    # (5) PDFアップロード・ポーリング進捗管理
│   │   │    │    └── ui.js        # (6) タブ切替、モーダル開閉、リサイザー等の純粋なUI制御
│   │   │    │
│   │   │    └── support.js        # (7) エントリーポイント（各モジュールを束ねて起動するファイル）
│   └── src
│       ├── Auth.php
│       ├── User.php
│       ├── Parsedown.php
│       ├── autoload.php
│       ├── EmbeddingEngine.php
│       ├── RAGPipeline.php
│       ├── VectorSearch.php
│       ├── Lib
│       │   └── Parsedown.php
│       └── Service
│           ├── ChatService.php
│           ├── RAGService.php
│           └── DocumentService.php
├── UIUX設計書_初版.html
├── README_01.md
├── old
│   └── README_01.md
├── 仮.html
└── 設計書_初版.html

1.4 環境変数（.env）

Apply
DB_HOST=localhost
DB_NAME=aisystem
DB_USER=newuser
DB_PASS=password
DATA_ROOT=D:\AI_System_Data\public
OLLAMA_HOST=http://tsc23ews009:11434
OLLAMA_EMBED_MODEL=nomic-embed-text
OLLAMA_CHAT_MODEL=llama3:70b
1.5 データベース構成
テーブル	主なカラム	備考
projects	id, project_name, latitude, longitude, address	
documents	id, project_id, title, file_path, created_at	
document_chunks	id, doc_id, chunk_text, embedding	新規：ベクトル検索用
chat_history	id, project_id, user_id, role, message, created_at	
logs	id, user_id, action, details, created_at	
1.6 セットアップ手順（変更点）
Ollama のセットアップ
Bash
Run
ollama pull mxbai-embed-large
ollama pull llama3:70b
MySQL テーブル作成
Sql

Apply
CREATE TABLE document_chunks (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    doc_id INT NOT NULL,
    chunk_text TEXT NOT NULL,
    embedding JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
watchdog の実行
scripts/watchdog.php を Windows タスクスケジューラに登録し、public/01_RAG_Documents/ を監視。新規 PDF が追加されると自動でベクトル化・インデックス化されます。

1.7 運用・監視
watchdog：public/01_RAG_Documents/ を監視し、ファイル投入時に EmbeddingEngine で埋め込みを生成、document_chunks に保存。
phpMyAdmin：データベース管理。
定期バックアップ：mysqldump で自動ダンプ。
ログ監視：logs/ 配下にアプリケーションログを出力。
API レート制限：
chat.php
 で簡易レート制限を実装（オプション）。

1.8 開発・拡張
UI コンポーネント：Tailwind CSS をベースに分野別配色切替。
GIS 連携：Leaflet.js を拡張し、特定地点の地質断面図等のポップアップ表示を検討。
RAG 改善：VectorSearch を MySQL の JSON カラムと GIST インデックスを併用して高速化。
テスト：tests/ に PHPUnit テストを追加。
CI/CD：GitHub Actions でコード品質チェック、デプロイを自動化。

2. UI/UX 仕様（抜粋）
2.1 ダッシュボード画面
左・中央：システム全体のインデックス件数、進行中プロジェクト数、AI からの重要通知。
右（AI対話パネル）：システム全般への質問や、最新アクティビティの要約確認が可能な常設チャットエリア。

2.2 業務支援画面
左カラム（業務一覧）：担当案件のリスト。
中央カラム（業務詳細）：
① 概要（業務名、工事期間、工事場所）
② 資料管理（PDFアップロードエリア、ファイルリスト）
③ 削除ボタン：PDF をリストから削除。
右カラム（AI対話）：
バブル配置（左＝AI、右＝ユーザー）
履歴保持（スクロールで確認）

2.3 資料検索画面
検索バー：キーワード入力＋検索ボタン。
左カラム（地図）：Leaflet.js による地図表示。
中央カラム（検索結果）：全分野横断検索結果。
右カラム（PDFプレビュー）：選択した資料を即座に確認。

3. アーキテクチャ

Apply
┌───────────────────────┐
│  Windows Server (Head)│
│  ├─ PHP UI (Admin/Chat)│
│  ├─ watchdog (Scanner) │
│  ├─ MySQL (Vector DB)  │
│  └─ Apache / Leaflet.js│
└───────┬────────────────┘
        │ HTTPS (Port: 443)
        ▼
┌───────────────────────┐
│  Ollama Server (GPU)  │
│  ├─ LLM 推論 (Ollama)  │
│  ├─ Embedding API      │
│  └─ Vision/OCR API     │
└───────────────────────┘

4. 主要ファイル一覧（変更点を含む）
ファイル	内容
src/EmbeddingEngine.php	Ollama の埋め込み API を呼び出すクラス。curl_error() でエラーハンドリング。
src/VectorSearch.php	MySQL の document_chunks からベクトル検索を行うクラス。コサイン類似度計算。
src/RAGPipeline.php	EmbeddingEngine と VectorSearch を組み合わせ、RAG 検索＋チャット生成を行う。
public/api/chat.php	RAGPipeline を呼び出し、チャット履歴を chat_history に保存。
public/api/delete_pdf.php	PDF ファイルと DB レコードを削除。
public/api/serve_pdf.php	document_chunks ではなく documents からファイルパスを取得し PDF をストリーム。
public/search.php	FULLTEXT 検索を使用し、document_chunks ではなく documents を検索。
public/support.php	PDF リストに「削除」ボタンを追加。
scripts/watchdog.php	PDF 追加を検知し、EmbeddingEngine で埋め込みを生成、document_chunks に保存。
config/database.php	PDO 接続設定。
config/session.php	セッション開始。
src/Auth.php	ログイン状態判定。
public/index.php	トップページ。
public/login.php	ログイン画面。
public/logout.php	ログアウト。
public/viewer.php	PDF.js ビューア。
public/serve_pdf.php	PDF ファイルを返却。
public/api/upload.php	PDF アップロード。
public/api/user_api.php	ユーザー情報取得。
public/.htaccess	Apache 設定。
public/debug_hash.php	デバッグ用。

5. 変更点のまとめ
変更項目	変更内容	影響
PDF 削除	delete_pdf.php を追加し、support.php に削除ボタンを実装	ユーザーはリストから PDF を削除できるように。
RAG 改善	VectorSearch を導入し、ベクトル検索で類似文書を取得	検索精度向上。
検索機能	search.php を FULLTEXT 検索へ変更、document_chunks を参照	検索速度と精度が向上。
API エンドポイント	chat.php が RAGPipeline を呼び出すよう更新	チャットの回答精度向上。
データベース	document_chunks テーブル追加	ベクトルデータを格納。
環境変数	OLLAMA_HOST, OLLAMA_EMBED_MODEL, OLLAMA_CHAT_MODEL を追加	Ollama サーバー設定を簡易化。
監視スクリプト	watchdog.php を追加	PDF 追加時に自動で埋め込み化。
UI	support.php に削除ボタン、search.php に PDF プレビューを追加	UX が向上。

6. 使い方（簡易フロー）
PDF アップロード
support.php
 から PDF をアップロード → upload.php が受け取り、document_chunks に埋め込みを作成。
PDF 削除
support.php
 のリストから「削除」ボタンをクリック → delete_pdf.php がファイルと DB レコードを削除。
チャット
chat.php
 へ質問を送信 → RAGPipeline が類似文書を取得し、Ollama で回答生成。
検索
search.php
 でキーワード検索 → FULLTEXT + ベクトル検索で結果を表示。
PDF プレビュー
検索結果をクリック → 
viewer.php
 で PDF をプレビュー。

7. 開発手順（簡易）
Bash
Run
# 1. リポジトリをクローン
cd ai-system/AI_System_Data

# 2. 依存ライブラリをインストール
composer install

# 3. 環境変数を設定
cp .env.example .env
# 編集して DB / Ollama の情報を入力

# 4. データベースを作成
mysql -u newuser -p aisystem < sql/init.sql

# 5. watchdog を Windows タスクスケジューラに登録
#   - 実行ファイル: php.exe
#   - 引数: scripts/watchdog.php
#   - トリガー: 毎分

# 6. Apache を再起動

8. 参考資料
Ollama ドキュメント
https://ollama.ai/docs
Leaflet.js
https://leafletjs.com/
Tailwind CSS
https://tailwindcss.com/
PHP 8.2
https://www.php.net/manual/en/migration82.php
MySQL 8.0
https://dev.mysql.com/doc/

9. 変更履歴
日付	変更内容	変更者
2026‑05‑11	PDF 削除機能追加、RAG 改善、検索機能更新	ChatGPT
2026‑05‑11	README 更新	ChatGPT

\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\logs\chat_debug.log
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\logs\upload_debug.log

\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\docs\design_v3.html

\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\assets\css\styles.css
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\assets\css\tailwind.css

\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\config\database.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\config\session.php

\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\src\Auth.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\src\autoload.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\src\ChatEvaluator.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\src\EmbeddingEngine.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\src\Parsedown.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\src\PromptManager.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\src\RAGPipeline.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\src\SqlExecutionEngine.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\src\User.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\src\VectorSearch.php

\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\src\Lib\Parsedown.php

\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\src\Service\ChatService.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\src\Service\RAGService.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\src\Service\DocumentService.php

\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\templates\footer.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\templates\header.php

\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\api\add_comment.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\api\add_faq.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\api\add_project_member.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\api\add_project.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\api\cancel_upload.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\api\chat_advanced.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\api\chat_analysis.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\api\chat_global.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\api\chat_history.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\api\chat_normal.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\api\chat.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\api\debug_tools.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\api\delete_comment.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\api\delete_csv.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\api\delete_faq.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\api\delete_pdf.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\api\delete_project.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\api\get_csv_data.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\api\get_csv_file_id.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\api\get_reasoning_status.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\api\get_upload_status.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\api\import_postgresql.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\api\read_project.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\api\read_projects.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\api\remove_project_member.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\api\save_user_settings.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\api\update_project.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\api\upload_csv.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\api\upload.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\api\user_api.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\api\view_pdf.php

\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\assets\js\modules\aiRenderer.js
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\assets\js\support.js
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\assets\js\modules\api.js
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\assets\js\modules\chat.js
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\assets\js\modules\csv.js
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\assets\js\modules\map.js
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\assets\js\modules\project.js
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\assets\js\modules\ui.js
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\assets\js\modules\upload.js

\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\.htaccess
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\debug_hash.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\index.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\login.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\logout.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\search.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\serve_pdf.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\support.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\user_management.php
\\10.5.98.129\htdocs\tepscoapp1\AI_System_Data\public\viewer.php
\\10.5.98.129\htdocs\tepscoapp1\UIUX設計書_初版.html
\\10.5.98.129\htdocs\tepscoapp1\README_01.md
\\10.5.98.129\htdocs\tepscoapp1\old\README_01.md
\\10.5.98.129\htdocs\tepscoapp1\仮.html
\\10.5.98.129\htdocs\tepscoapp1\設計書_初版.html