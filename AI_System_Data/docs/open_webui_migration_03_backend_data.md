# バックエンド・データ・AI処理の棚卸し

## 現行API・処理の分類（確認済み）

| 分類 | 現行の代表API/処理 | 新構成での責務 |
| --- | --- | --- |
| 案件・権限 | `add/update/delete_project.php`、メンバー追加/削除、`ProjectAccess` | 業務API。Open WebUI外で認可する |
| 資料 | `upload.php`、資料メモCRUD、PDF表示/整合性確認 | 文書サービス + オブジェクトストレージ + 非同期抽出 |
| CSV | upload/download/delete、行編集、統合、AI分類、PostgreSQL取込 | データサービス + ジョブワーカー |
| 会話 | `chat.php`、normal/analysis/advanced/global各ルート、SSE | Open WebUIが会話基盤、業務処理はToolへ分離 |
| 帳票 | `ReportGenerator`、再生成候補API | 帳票サービス。HTML/PDFレンダラを分離 |
| 監査・運用 | ログtail、評価、FAQ自動登録、進捗/取消 | 監査ログ、ジョブ基盤、評価サービス |

## MySQLの主要データ（確認済み）

| 現行テーブル | 役割 | 新構成での扱い（提案） |
| --- | --- | --- |
| `users` | ログイン、部署、モデル設定 | Open WebUIのユーザーとは分離。業務プロフィールと外部ID対応を持つ |
| `projects` / `project_members` | 案件と案件ロール | 業務MySQLの正本として移行 |
| `documents` / `doc_chunks` / `embeddings` | 文書、チャンク、ベクトル | 文書メタデータは業務DB。RAGはOpen WebUI Knowledgeまたは外部ベクトルDBへ分離 |
| `chat_threads` / `chat_history` | スレッド・回答履歴 | 会話本体はOpen WebUIに委ねる。業務イベント・成果物参照のみ業務DBに持つ |
| `chat_evaluations` / `chat_reasoning_steps` | 評価・中間推論 | まず移行不要。必要なら監査用イベントとして再設計 |
| `project_meta` | AGENTS/README/TODO相当の案件運用メモ | 業務DBの版管理付きMarkdown成果物として移行候補 |
| `project_comments` / `project_faqs` | コメント、共有知識 | 業務DB。FAQはKnowledge同期を選択可能にする |
| `project_csv_files` / `project_csv_rows` | CSVメタデータとJSON行データ | CSVカタログと分析用ストアへ再設計。大量データをJSON行テーブルへ固定しない |
| `logs` | アプリログ | アプリDBにためず、構造化ログ基盤へ送る |

## LLM・RAG・文書解析・CSV・Text-to-SQL

| 領域 | 現行の確認済み内容 | 新構成の提案 |
| --- | --- | --- |
| モデル | main/sub/sql/embedding/visionの役割を設定可能。Ollamaを利用 | モデルの選択・接続はOpen WebUI設定を優先。業務Toolが必要な専用モデルだけを自身の設定で持つ |
| RAG | PDF/資料メモ/CSVをチャンク化・Embedding化して検索 | 一般文書はOpen WebUI Knowledge。案件横断・厳密なACL・独自メタデータが必要なら外部ベクトルDBを採用 |
| 文書解析 | PDFテキスト抽出、画像化、Vision解析、チャンク化 | Docling/Tika等のOpen WebUI設定をまず検証。帳票/OCR品質が要件化する部分だけ外部文書処理サービスに分離 |
| CSV | 小規模要約、日付/値の決定論的集計、証拠読解、AI分類 | CSVを安全な分析テーブルへ正規化し、SQL集計はLLMを介さないToolとして提供 |
| Text-to-SQL | LLM生成SQLをSELECTのみ・案件範囲・許可テーブルで監査し、修復あり | read-only分析用DBユーザー、ビュー/意味層、ASTまたは厳格なallowlist、行数/実行時間上限を必須にする |

## バッチ・外部連携（確認済み）

- `bin/backfill_doc_chunk_image_descriptions.php`、`bin/backfill_material_rag.php`、`bin/run_csv_ai_categorize_job.php` が存在する。
- PDF/CSV取込は進捗ファイルとポーリング、キャンセルフラグを利用する実装がある。
- 外部PostgreSQLからデータを取り込むAPIがある。
- PDF生成は mPDF を優先し、wkhtmltopdf またはブラウザheadlessへフォールバックする実装がある。

新構成では FastAPI の受付API、キュー、ワーカー、オブジェクトストレージを分け、ジョブID・状態・再試行回数・エラー要約を業務DBに保存します。Open WebUIのHTTPリクエスト内で重い処理を完走させません。
