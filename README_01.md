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

現行仕様サマリー（2026/06/03時点・最新実装反映）

本システムは、Windows本番環境（PHP 8.2.8 / MySQL 8.0.33 / Apache 2.4.57）上で動作する、案件単位のAI業務支援プラットフォームです。`AI_System_Data/public` をWeb公開ルートとし、PDF資料、CSV/TSVデータ、案件コメント、FAQ、メンバー情報をMySQLに保存します。AI推論はOllama APIを利用し、ユーザーごとに接続先URL、既定モデル、サブモデルを設定できます。

チャット受付・回答生成ロジックの俯瞰メモは [AI_System_Data/docs/chat_system_overview_20260603.md](AI_System_Data/docs/chat_system_overview_20260603.md) を参照してください。

主な現行機能

機能	内容	主要ファイル
ログイン・ユーザー管理	`users` テーブルの `password_hash` による認証、admin/user権限、部署、既定プロンプト・言語・モデル・Ollama接続先の保存	public/login.php, public/user_management.php, public/api/save_user_settings.php
案件管理	案件の作成、編集、削除、住所・座標・期間・ステータス管理。一般ユーザーは作成案件または参加案件のみ参照可能	public/support.php, public/api/add_project.php, public/api/update_project.php, src/ProjectAccess.php
案件メンバー管理	案件ごとに manager/member/viewer を割り当て、操作権限を制御	public/api/add_project_member.php, public/api/remove_project_member.php
PDF登録・RAG化	PDFを案件配下に保存し、テキスト抽出、必要に応じた画像/VLM解析、チャンク化、Embedding生成、`doc_chunks` 登録を実行	public/api/upload.php, src/EmbeddingEngine.php
CSV/TSV登録	CSV/TSVを `project_csv_files` / `project_csv_rows` に保存。DB登録を先に確定し、その後にRAG用自然言語チャンクとEmbeddingを作成。大規模CSVでは複数行をまとめてチャンク化し、Embedding連続失敗時もCSV本体登録は維持	public/api/upload_csv.php, public/api/get_csv_data.php, public/api/delete_csv.php
外部PostgreSQL取込	外部PostgreSQLの抽出結果をCSV同等の構造で案件DBへ登録	public/api/import_postgresql.php
AIチャット	`chat.php` を入口に、粗い質問因数分解で通常RAG、`advanced_hybrid.doc_extract`、CSV構造化集計 (`CSV-AGG`)、CSV証拠読解、CSV要約、admin向け全社横断調査へスマートルーティング	public/api/chat.php, chat_normal.php, chat_advanced.php, chat_analysis.php, chat_global.php
入力ガード	空入力、挨拶、誤送信に近い短文、報告書モードに不十分な指示を事前判定し、重い推論・SQL・PDF生成へ入る前に確認応答へ切替	src/ChatRequestGuard.php, public/api/chat.php
図解・報告書モード	図解モードでは必要に応じてChart.jsやMermaid図表を回答に含め、CSV日付集計では deterministic な `json:chart` を優先生成する。報告書モードでは最終回答を報告書向け構成へ整形してPDF化し、PDFタブへ登録し、RAG検索対象にも追加する	public/support.php, public/assets/js/modules/chat.js, src/ReportGenerator.php
回答モードUI	support.phpのチャット欄に「フル思考」「図解」「報告書」の小型スイッチを配置。生成中ステータスは入力欄上の控えめなバーに表示し、詳細は完了後の折りたたみログで確認	public/support.php, public/assets/js/modules/chat.js, public/assets/css/styles.css
CSV高速サマリー	小規模CSV（100行以下）の「内容まとめ」「概要」「要約」系質問は、Ollamaを呼ばずDBレコードから即時サマリーを生成	public/api/chat_analysis.php
CSV検索先行読解	CSV証拠読解では、質問文から検索語を抽出し、`row_data`・ファイル名・列ヘッダーをSQL検索してから読解対象を決定。ヒットなしや検索語が弱い大規模CSVでは、全件AI読解せず概況回答へ切替	public/api/chat_analysis.php
Text-to-SQL分析	AI生成SQLを `SqlExecutionEngine` で監査し、実在テーブル・カラム、案件スコープ、SELECT系SQLのみ実行。失敗時はモード別上限（通常1回、図解・報告書2回）で修復し、定番SQLフォールバックで正解へ誘導	src/SqlExecutionEngine.php, public/api/chat_analysis.php, public/api/chat_advanced.php
回答品質評価	LLM-as-a-Judgeで回答を評価し、必要に応じて追加抽出・再生成を行い、評価結果を `chat_evaluations` に保存。通常RAGは必要性判定により軽量回答の評価を省略し、高評価かつ重複なしの回答は要点化して `project_faqs` に自動登録する。FAQ自動登録はスキップ理由も `chat_debug.log` に出力する	src/ChatEvaluator.php, src/ChatEvaluationPolicy.php, src/FaqAutoRegistrar.php, public/api/chat_normal.php, public/api/chat_advanced.php, public/api/chat_analysis.php
チャットUI描画	SSEストリームで回答を逐次表示し、Markdown、Chart.js用 `json:chart`、Mermaid図をチャット内に描画。ブラウザ更新後も推論プロセスを再表示し、生成中の詳細ステータスは折りたたみで確認可能	public/assets/js/modules/chat.js, public/assets/js/modules/aiRenderer.js, public/support.php
詳細ログ	`chat_debug.log` にルート判定、DB収集、Ollama呼び出し、SQL修復、評価、保存処理の詳細ログを出力。support.php上でリアルタイムtail表示可能	logs/chat_debug.log, public/api/chat_debug_tail.php, src/AppLogger.php

チャット回答生成のルート概要

1. `public/api/chat.php` が質問文、案件ID、モデル、プロンプト、回答モード（フル思考・図解・報告書）を受け取る。
2. `ChatRequestGuard.php` が誤送信・不明瞭入力・報告書モードに不十分な指示を事前判定し、必要なら軽量な確認応答で終了する。
3. `chat.php` が質問を粗く因数分解し、通常RAG、`advanced_hybrid.doc_extract`、CSV構造化集計 (`CSV-AGG`)、CSV検索先行読解、CSV要約、フル思考、全社横断調査へ分岐する。
4. PDF/CSV/コメント/FAQ/会話履歴などの関連データをMySQLから取得する。
5. 必要に応じてOllamaで回答生成、推論統合、SQL生成、品質評価を行う。CSV日付集計はAI生成SQLに頼らず、日付列検出と定番SQLで構造化集計し、図解モードでは `json:chart` を deterministic に組み立てる。SQL自己修復と品質評価の差し戻しは、通常は短く、図解・報告書モードでは少し厚めに実行する。
6. 回答本文、推論ステップ、評価スコアを `chat_history`、`chat_reasoning_steps`、`chat_evaluations` に保存する。高評価回答は必要に応じて `project_faqs` に自動登録する。
7. フロントエンドはSSEで受信した本文をリアルタイム描画し、Chart.js / Mermaid の図表も描画する。生成中ステータスは入力欄上の小さなバーに表示し、詳細ログは回答完了後に折りたたみで確認できる。
8. 報告書モードの場合は、回答保存後に報告書向けの最終整形を追加で実行し、`ReportGenerator.php` がHTML/CSS報告書を作成して、Composerで導入した mPDF を優先してPDF化する。mPDFが利用できない検証環境では wkhtmltopdf または Chrome/Edge headless にフォールバックする。生成PDFは `documents` と `doc_chunks` に登録され、アップロードPDFと同じようにPDFタブとRAG検索で利用できる。

現行の補足ポイント

- `advanced_hybrid` では、資料中心質問を `advanced_hybrid.doc_extract` として因数分解し、`doc-only semantic_extract` の場合はシーケンス1のSQL分析とPlannerを省略して資料RAGへ寄せる。
- `chat_advanced.php` の資料PDF抽出では、PDF優先の定番SQL、軽量最終回答ルート、最終回答本文ログ（`[FINAL-ANSWER-*]`）を使って、速度と観測性を両立している。軽量最終回答ルートは `ChatEvaluator` の本審査を省略するため、評価結果には `evaluation_mode=synthetic` を付与して本審査結果と区別する。
- `chat_analysis.php` の `CSV-AGG` は、日付列候補をサンプル判定してから構造化集計し、図解モードでは単一CSVは折れ線、複数CSVは棒グラフを基本に `json:chart` を出力する。
- `CSV-AGG` の日付系は `CsvDateAggregationRunner`、distinct / 分布系は `CsvValueAggregationRunner`、semantic 系は `CsvSemanticAggregationRunner` に切り出し済みで、対象CSV解決は `CsvAggregationTargetResolver` に寄せている。
- `FaqAutoRegistrar.php` は、高評価回答のみをFAQ候補として扱い、`chat_history_id`・質問要約・回答要約の重複を避けながら保存する。`evaluation_mode=real` の本審査結果だけをFAQ候補にし、保存されなかった場合も理由を `[FAQ-AUTO]` ログで追える。
- `project_comments` / `project_faqs` は回答生成ルートの参照対象には入っているが、現状は PDF / CSV より前面には出にくい。FAQ は一部質問で明示的に参照しやすい一方、コメントは主にスキーマ文脈やSQL到達時の補助ソースとして使われる。コメント・FAQ 系の質問語を優先ルーティングする改善は将来候補として扱う。

チャット受付・ルーティング現行メモ（2026/06/03整理）

1. 入口と前処理
- 入口は `public/api/chat.php`。ここで `project_id`、メッセージ本文、`advanced_reasoning`、`report_mode`、`diagram_mode` を受け取る。
- 受付直後に `[INPUT-MODE]` を `chat_debug.log` へ出力し、回答モードの実入力を追えるようにしている。
- `ChatRequestGuard.php` が空入力、挨拶、誤送信に近い短文、報告書モードに不十分な依頼を先に弾く。
- 直近8件の `chat_history` を読み、`history_summary_text` を下流へ渡す。加えて、CSV追い質問の補完用に直近会話から対象CSV名・列名も再利用する。

2. ルーティングの大枠
- `chat.php` は粗い質問因数分解を行い、まず `data_analysis.csv_agg` / `data_analysis.csv_summary` / `advanced_hybrid.doc_extract` のような軽量な route 候補を作る。
- その後、入力モード、質問語、登録済みCSV名の言及、全社横断キーワード、履歴要約要求などを加味して最終ルートを決める。
- 大きな分岐先は以下の4つ。
  - `normal_rag`: 通常の資料検索・回答
  - `data_analysis`: CSV要約、CSV証拠読解、CSV構造化集計
  - `advanced_hybrid`: フル思考、資料抽出、重い多段階推論
  - `global_cross` / `global_no_project`: admin向け全社横断調査

3. 回答モードの影響
- `report_mode=on` は最優先でフル思考寄せし、報告書向け構成・PDF化・保存まで含めた経路に乗せる。
- `diagram_mode=on` は図表生成を許可するが、CSV要約・CSV集計の軽量ルートは維持する。
- `advanced_reasoning=on` でも、CSV要約やCSV集計は軽量 `data_analysis` を優先するよう調整済み。

4. CSV軽量ルートの現状
- `data_analysis.csv_summary`
  - 全CSV/単一CSVの概要質問
  - 小規模CSVは即答サマリー
  - 大規模CSVは `CSV-OVERVIEW` 概況回答へ切替
- `data_analysis.csv_agg`
  - 日付集計 (`date_histogram`)
  - ユニーク件数 (`distinct_count`)
  - 値分布 (`value_distribution`)
  - 値のカテゴリ整理 (`semantic_category_summary`)
  - カテゴリに属する行だけ絞った再集計 (`category_filtered_distribution`)
  - 列名の意味説明 (`column_semantics`)
- 単一CSV・単一列の質問では、`project_csv_rows` を `csv_file_id` で必ず絞る方針へ寄せている。

5. 会話文脈の補完
- 追い質問でCSV名や列名が消えても、直前会話に `集計.csv / event_type` のような文脈があれば、`chat.php` 側で補完して `data_analysis.csv_agg` に戻す。
- 現状の補完対象は主に「CSV名」「列名」で、より広い意味的な会話参照はまだ限定的。

6. 回答表示と品質審査
- ブラウザへ本文を見せるのは最終 `result` のみ。内部では生成途中ドラフトを作るが、本文 `chunk` は出さない。
- `advanced_hybrid` と一部 `data_analysis` は回答生成後に品質評価を行う。結果は `chat_evaluations` に保存される。
- 評価結果は `evaluation_mode=real / synthetic / fallback` で区別する。
- FAQ自動登録は `evaluation_mode=real` の本審査通過系だけを候補にする。

7. 図解モードの現状
- `diagram_mode=on` では、軽量CSV集計結果は PHP側で deterministic に `json:chart` を組み立てる。
- 通常RAG / フル思考では、必要時のみ Mermaid または Chart.js 用ブロックを含める。
- 大規模CSV概況ルートでも、現在は上位CSV件数を `json:chart` で返せる。
- 過去ログ再描画では `language-json:chart` / `language-json:chart_data` のみをグラフとして復元する。

8. いまの責務の重なり
- `chat.php` に、入力受理、入力ガード、会話履歴取得、最終ルーティングが同居している。
- ただし、粗い因数分解は `ChatRouteFactorizer`、CSV文脈補完は `ChatHistoryContextResolver` へ切り出し済み。
- ルート選択の判断本体も `ChatRouteSelector` へ切り出し済みで、`chat.php` には主に dispatch が残っている。
- dispatch も `ChatRouteDispatcher` へ切り出し済みで、入口はかなり薄くなってきている。
- `chat_analysis.php` に、CSV即答、CSV証拠読解、CSV集計、会話保存、評価、報告書生成の一部が共存している。
- ただし軽い側から分割を進めており、`CsvQuickResponseRunner`、`CsvMetadataResponseRunner`、`CsvEvidenceRouteRunner`、`CsvDateAggregationRunner`、`CsvValueAggregationRunner`、`CsvSemanticAggregationRunner` は外出し済み。
- `chat_advanced.php` は、資料抽出、SQL分析、統合、評価、再生成、報告書寄りの制御が1ファイルに寄っている。

将来のファイル分割候補メモ

- `chat.php`
  - `ChatRouteFactorizer`
  - `ChatRouteSelector`
  - `ChatHistoryContextResolver`
  - `CsvFollowupContextResolver`
  - `ChatModePolicy`
- `chat_analysis.php`
  - `CsvStructuredAggregationRunner`
  - `CsvEvidenceRouteRunner`
  - `CsvOverviewRouteRunner`
  - `CsvSemanticsAnalyzer`
- `chat_advanced.php`
  - `AdvancedRoutePlanner`
  - `AdvancedRouteMerger`
  - `AdvancedRouteCriticLoop`
  - `AdvancedRouteReportFinalizer`

分割方針の基本

- まずは現行仕様をこのメモで固定し、挙動を変えずに責務単位で切り出す。
- 最初の分割対象は `chat.php` の文脈補完＋因数分解まわりが安全。
- 次に `chat_analysis.php` の CSV軽量ルート群を Runner 単位で分ける。
- `chat_advanced.php` は影響範囲が広いので、最後に小さく刻んで進める。

運用上の注意

- 本番環境はDocker前提ではなく、Windows + Apache + PHP + MySQL の構成を基準とする。
- Ollama接続先はユーザー設定の `ollama_host` を優先し、Docker利用時のみ `host.docker.internal:11434` のような接続先を使う。
- `logs/chat_debug.log` は詳細調査に有効だが、運用時はログ肥大化に注意する。
- `chat_debug.log` には `[INPUT-GUARD]`、`[SMART-ROUTER]`、`[CSV-AGG]`、`[SQL-REPAIR-POLICY]`、`[EVAL-POLICY]`、`[EVAL-REAL]`、`[EVAL-SYNTHETIC]`、`[EVAL-FALLBACK]`、`[FINAL-ANSWER]`、`[FAQ-AUTO]` などの制御ログを出力し、誤送信抑止、因数分解結果、集計ルート、SQL修復回数、品質評価の種別、最終回答本文、FAQ自動登録の可否を後から確認できる。
- Chart.js用グラフは json:chart コードブロック、業務フロー図は mermaid コードブロックとして回答に含める。
- 報告書モードでPDF出力を使うには、本番環境ではComposerで `mpdf/mpdf` をインストールし、`vendor/autoload.php` を利用可能にする。mPDFが利用できない検証環境では、`AI_System_Data/tools/wkhtmltopdf.exe`、環境変数 `WKHTMLTOPDF_PATH`、環境変数 `CHROME_PATH`、またはChrome/Edge headlessにフォールバックする。

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
DB_NAME=tepscoapp
DB_USER=newuser
DB_PASS=password
DATA_ROOT=D:\AI_System_Data\public
OLLAMA_HOST=http://tsc23ews009:11434
OLLAMA_EMBED_MODEL=mxbai-embed-large
OLLAMA_CHAT_MODEL=gpt-oss:20b
1.5 データベース構成
テーブル	主なカラム	備考
projects	id, project_name, latitude, longitude, address
documents	id, project_id, title, file_path, created_at
doc_chunks	id, doc_id, chunk_text, embedding, image_description, page_number	ベクトル検索・RAG用
chat_history	id, project_id, user_id, role, message, created_at
logs	id, user_id, action, details, created_at
1.6 セットアップ手順（変更点）
Ollama のセットアップ
Bash
Run
ollama pull mxbai-embed-large
ollama pull gpt-oss:20b
MySQL テーブル作成
Sql

Apply
CREATE TABLE doc_chunks (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    doc_id INT NOT NULL,
    chunk_text TEXT NOT NULL,
    embedding JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
watchdog の実行
scripts/watchdog.php を Windows タスクスケジューラに登録し、public/01_RAG_Documents/ を監視。新規 PDF が追加されると自動でベクトル化・インデックス化されます。

1.7 運用・監視
watchdog：public/01_RAG_Documents/ を監視し、ファイル投入時に EmbeddingEngine で埋め込みを生成、doc_chunks に保存。
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
src/VectorSearch.php	MySQL の doc_chunks からベクトル検索を行うクラス。コサイン類似度計算。
src/RAGPipeline.php	EmbeddingEngine と VectorSearch を組み合わせ、RAG 検索＋チャット生成を行う。
public/api/chat.php	RAGPipeline を呼び出し、チャット履歴を chat_history に保存。
public/api/delete_pdf.php	PDF ファイルと DB レコードを削除。
public/api/serve_pdf.php	doc_chunks ではなく documents からファイルパスを取得し PDF をストリーム。
public/search.php	FULLTEXT 検索を使用し、doc_chunks ではなく documents を検索。
public/support.php	PDF リストに「削除」ボタンを追加。
scripts/watchdog.php	PDF 追加を検知し、EmbeddingEngine で埋め込みを生成、doc_chunks に保存。
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
検索機能	search.php を FULLTEXT / RAG 検索へ変更、doc_chunks を参照	検索速度と精度が向上。
API エンドポイント	chat.php が RAGPipeline を呼び出すよう更新	チャットの回答精度向上。
データベース	doc_chunks / project_csv_files / project_csv_rows / chat_evaluations / project_faqs を利用	ベクトル、CSV、評価、FAQを格納。
環境変数	OLLAMA_HOST, OLLAMA_EMBED_MODEL, OLLAMA_CHAT_MODEL を追加	Ollama サーバー設定を簡易化。
監視スクリプト	watchdog.php を追加	PDF 追加時に自動で埋め込み化。
UI	support.php に削除ボタン、search.php に PDF プレビューを追加	UX が向上。

6. 使い方（簡易フロー）
PDF アップロード
support.php
 から PDF をアップロード → upload.php が受け取り、doc_chunks に埋め込みを作成。
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
mysql -u newuser -p tepscoapp < config/db.sql

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
2026‑06‑02	CSV検索先行読解、CSVアップロード高速化、FAQ自動登録、チャットUI描画改善、設計書更新	Codex
2026‑06‑03	図解モード・報告書モードのMVP追加。AI回答をHTML/CSS報告書としてPDF化し、PDFタブ表示とRAG検索対象登録に対応	Codex
2026‑06‑03	入力ガード、通常RAGの評価省略ポリシー、SQL自己修復・品質評価のモード別上限、回答モードUI、控えめな生成中ステータス表示を追加	Codex

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

データベース（2026/6/1）
localhost/INFORMATION_SCHEMA/COLUMNS/		http://3dsg.tepsco.co.jp/phpMyadmin/index.php?route=/database/sql&db=tepscoapp

   行 0 - 105 の表示 (合計 106, クエリの実行時間： 0.0028 秒。)


SELECT
    TABLE_NAME AS 'テーブル名',
    ORDINAL_POSITION AS '位置',
    COLUMN_NAME AS 'カラム名',
    COLUMN_TYPE AS 'データ型(長さ)',
    IS_NULLABLE AS 'NULL許容',
    COLUMN_KEY AS 'キー',
    COLUMN_DEFAULT AS 'デフォルト値',
    COLUMN_COMMENT AS '論理名/コメント'
FROM
    INFORMATION_SCHEMA.COLUMNS
WHERE
    TABLE_SCHEMA = 'tepscoapp'
ORDER BY
    TABLE_NAME,
    ORDINAL_POSITION;


テーブル名	位置	カラム名	データ型(長さ)	NULL許容	キー	デフォルト値	論理名/コメント
chat_evaluations	1	id	bigint unsigned	NO	PRI	NULL
chat_evaluations	2	chat_id	bigint unsigned	NO	MUL	NULL
chat_evaluations	3	proactivity_score	int	NO		NULL
chat_evaluations	4	faithfulness_score	int	NO		NULL
chat_evaluations	5	relevance_score	int	NO		NULL
chat_evaluations	6	clarity_score	int	NO		NULL
chat_evaluations	7	total_score	int	NO		NULL
chat_evaluations	8	feedback	text	YES		NULL
chat_evaluations	9	retry_count	int	YES		0
chat_evaluations	10	created_at	datetime	YES		CURRENT_TIMESTAMP
chat_history	1	id	bigint unsigned	NO	PRI	NULL
chat_history	2	project_id	bigint unsigned	YES	MUL	NULL	関連業務ID (NULLの場合は汎用対話)
chat_history	3	user_id	bigint unsigned	NO	MUL	NULL	対話者ID
chat_history	4	role	enum('user','assistant')	NO		NULL	発話者区分
chat_history	5	message	text	NO		NULL	メッセージ内容
chat_history	6	created_at	datetime	YES		CURRENT_TIMESTAMP
chat_reasoning_steps	1	id	bigint unsigned	NO	PRI	NULL
chat_reasoning_steps	2	chat_history_id	bigint unsigned	YES		NULL	最終的なチャット履歴との紐づけ
chat_reasoning_steps	3	project_id	bigint unsigned	NO	MUL	NULL
chat_reasoning_steps	4	session_id	varchar(255)	NO		NULL	現在進行中のセッション識別
chat_reasoning_steps	5	original_question	longtext	NO		NULL	ユーザーの元の質問
chat_reasoning_steps	6	step_number	int	NO		NULL	因数分解されたクエリの連番 (1, 2, 3...)
chat_reasoning_steps	7	sub_query	varchar(512)	NO		NULL	因数分解されたサブ質問テキスト
chat_reasoning_steps	8	search_context	longtext	YES		NULL	このサブ質問でヒットしたRAG資料情報(JSON)
chat_reasoning_steps	9	sub_answer	longtext	YES		NULL	このサブ質問に対して生成された個別回答
chat_reasoning_steps	10	created_at	datetime	YES		CURRENT_TIMESTAMP
doc_chunks	1	id	bigint unsigned	NO	PRI	NULL
doc_chunks	2	doc_id	bigint unsigned	NO	MUL	NULL
doc_chunks	3	chunk_text	longtext	NO	MUL	NULL	抽出されたテキスト本文
doc_chunks	4	embedding	json	NO		NULL
doc_chunks	5	image_description	text	YES		NULL
doc_chunks	6	page_number	int	YES		NULL	PDFの該当ページ番号
doc_chunks	7	created_at	datetime	YES		CURRENT_TIMESTAMP
documents	1	id	bigint unsigned	NO	PRI	NULL
documents	2	project_id	bigint unsigned	NO	MUL	NULL	プロジェクトID
documents	3	title	varchar(255)	NO		NULL
documents	4	file_path	varchar(512)	NO		NULL
documents	5	created_at	datetime	YES		CURRENT_TIMESTAMP
embeddings	1	id	bigint	NO	PRI	NULL
embeddings	2	document_id	bigint	NO		NULL
embeddings	3	embedding	json	NO		NULL
embeddings	4	created_at	timestamp	YES		CURRENT_TIMESTAMP
logs	1	id	bigint unsigned	NO	PRI	NULL
logs	2	user_id	bigint unsigned	YES	MUL	NULL
logs	3	action	varchar(255)	NO		NULL
logs	4	details	json	YES		NULL
logs	5	created_at	datetime	YES		CURRENT_TIMESTAMP
project_comments	1	id	bigint unsigned	NO	PRI	NULL
project_comments	2	project_id	bigint unsigned	NO	MUL	NULL
project_comments	3	user_id	bigint unsigned	NO	MUL	NULL
project_comments	4	comment_text	text	NO		NULL	コメント本文
project_comments	5	created_at	datetime	YES		CURRENT_TIMESTAMP
project_comments	6	updated_at	datetime	YES		CURRENT_TIMESTAMP
project_csv_files	1	id	bigint unsigned	NO	PRI	NULL
project_csv_files	2	project_id	bigint unsigned	NO	MUL	NULL
project_csv_files	3	file_name	varchar(255)	NO		NULL	CSVファイル名
project_csv_files	4	column_headers	text	NO		NULL	列名のリスト（JSON配列形式）
project_csv_files	5	row_count	int	NO		0	総行数
project_csv_files	6	created_at	datetime	YES		CURRENT_TIMESTAMP
project_csv_rows	1	id	bigint unsigned	NO	PRI	NULL
project_csv_rows	2	csv_file_id	bigint unsigned	NO	MUL	NULL
project_csv_rows	3	row_index	int	NO		NULL	何行目のデータか
project_csv_rows	4	row_data	json	NO		NULL	1行分のデータ (例: {"測定日時": "2026/05/21", "水位(m)": 2.45})
project_csv_rows	5	created_at	datetime	YES		CURRENT_TIMESTAMP
project_faqs	1	id	bigint unsigned	NO	PRI	NULL
project_faqs	2	project_id	bigint unsigned	NO	MUL	NULL
project_faqs	3	chat_history_id	bigint unsigned	YES	MUL	NULL	元となったチャット履歴ID（任意）
project_faqs	4	question_summary	varchar(512)	NO		NULL	FAQの質問・課題概要
project_faqs	5	answer_summary	text	NO		NULL	AIによる回答・解決策の概要
project_faqs	6	created_by	bigint unsigned	YES	MUL	NULL	FAQとして登録したユーザー
project_faqs	7	created_at	datetime	YES		CURRENT_TIMESTAMP
project_members	1	id	bigint unsigned	NO	PRI	NULL
project_members	2	project_id	bigint unsigned	NO	MUL	NULL
project_members	3	user_id	bigint unsigned	NO	MUL	NULL
project_members	4	role	enum('manager','member','viewer')	NO		member	案件内の役割
project_members	5	assigned_at	datetime	YES		CURRENT_TIMESTAMP
project_meta	1	id	bigint unsigned	NO	PRI	NULL
project_meta	2	project_id	bigint unsigned	NO	MUL	NULL
project_meta	3	meta_key	varchar(100)	NO		NULL	情報のキー (例: client_name, tags, budget)
project_meta	4	meta_value	text	YES		NULL	情報の内容
project_meta	5	created_at	datetime	YES		CURRENT_TIMESTAMP
project_meta	6	updated_at	datetime	YES		CURRENT_TIMESTAMP
projects	1	id	bigint unsigned	NO	PRI	NULL
projects	2	project_name	varchar(255)	NO		NULL	業務件名
projects	3	description	text	YES		NULL	業務概要
projects	4	start_date	date	YES	MUL	NULL	工事・業務期間(開始)
projects	5	end_date	date	YES		NULL	工事・業務期間(終了)
projects	6	address	varchar(512)	YES		NULL	工事場所・住所
projects	7	latitude	decimal(10,8)	YES	MUL	NULL	緯度 (Leaflet.js用)
projects	8	longitude	decimal(11,8)	YES		NULL	経度 (Leaflet.js用)
projects	9	created_by	bigint unsigned	YES	MUL	NULL
projects	10	status	enum('active','completed','on_hold')	NO	MUL	active
projects	11	created_at	datetime	YES		CURRENT_TIMESTAMP
projects	12	updated_at	datetime	YES		CURRENT_TIMESTAMP
users	1	id	bigint unsigned	NO	PRI	NULL
users	2	username	varchar(64)	NO	UNI	NULL
users	3	password_hash	varchar(255)	NO		NULL
users	4	role	enum('admin','user')	NO		user
users	5	department	varchar(100)	YES		NULL
users	6	created_at	datetime	YES		CURRENT_TIMESTAMP

users	7	updated_at	datetime	YES		CURRENT_TIMESTAMP
users	8	default_prompt	varchar(50)	YES		construction_consultant	デフォルトプロンプトの識別子
users	9	default_lang	varchar(10)	YES		ja	表示言語設定
users	10	default_model	varchar(50)	YES		gpt-oss:20b	優先使用モデル
users	11	ollama_host	varchar(255)	YES		http://tsc25dtp116:11434	Ollama接続先URL
users	12	sub_model	varchar(100)	YES		gpt-oss:20b	フル思考時の統合用サブモデル
