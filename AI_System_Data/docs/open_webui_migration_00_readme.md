# Open WebUI 移行資料

作成日: 2026-07-12

更新確認日: 2026-10-03

対象: 現行 PHP / MySQL システムを参照して新規に構築する Open WebUI ベースの業務支援システム

## この資料の目的

現行システムを移植するための設計書ではありません。案件単位で資料・データを扱い、対話から成果品を育てるという業務価値を残しながら、Open WebUI と独立した業務サービスの組み合わせへ再設計するための判断材料です。

確認済み事項は現行リポジトリのコード、SQL、設定、既存仕様書から抽出しました。新構成の記述は **提案**、動作確認が必要なものは **要検証** と明記します。

2026-10-03 に、現行 `main` の `20cb0c5`（2026-08-14）までのコードと作業メモを再確認しました。直近のコード修正は、entry質問分解stepのuser履歴紐付け、圧縮履歴での質問・回答ペア保持、`request_id` 単位の処理中二重送信防止です。`TODO.md` の進行中は、新規チャットでのentry履歴紐付けの運用確認1件です。本資料の追加は移行検討の記録であり、Open WebUIへの移行実装や現行アプリの構成変更を意味しません。

## 読む順序

1. [現行機能・状態の棚卸し](open_webui_migration_01_current_inventory.md)
2. [画面、利用者操作、業務フロー](open_webui_migration_02_user_flows.md)
3. [データ、処理、連携の棚卸し](open_webui_migration_03_backend_data.md)
4. [移行範囲と Open WebUI 実装方針](open_webui_migration_04_target_architecture.md)
5. [新プロジェクトの Markdown 運用](open_webui_migration_05_project_operations.md)

## 証跡と参照元

| 区分 | 主な参照元 |
| --- | --- |
| 現行仕様 | `README_01.md`、`AI_System_Data/docs/chat_system_overview_20260603.md` |
| DB | `AI_System_Data/config/db.sql` |
| UI / API | `AI_System_Data/public/support.php`、`AI_System_Data/public/api/`、`AI_System_Data/public/assets/js/modules/` |
| 実装状況 | `TODO.md`（2026-10-03 再確認時点） |
| Open WebUI の機能 | [Knowledge](https://docs.openwebui.com/features/workspace/knowledge/)、[Functions](https://docs.openwebui.com/features/extensibility/plugin/functions/)、[OpenAPI Tool Servers](https://docs.openwebui.com/features/extensibility/plugin/tools/openapi-servers/)、[RAG](https://docs.openwebui.com/features/chat-conversations/rag/) |

## 設計の結論

- Open WebUI は、認証済みチャット、モデル接続、標準ファイル管理、Knowledge/RAG、会話表示、標準的なユーザー・グループ管理を担う。
- 案件、案件メンバー、CSV台帳・集計、帳票、業務権限、外部データ取込、監査ログは Open WebUI の外に置く。
- 独自業務機能は Python / FastAPI の API と非同期ワーカーとして実装し、OpenAPI Tool を第一候補に接続する。Open WebUI Function は薄い接続・表示補助に限定する。
- Open WebUI 本体のソース、内部DBスキーマ、内部APIへの直接依存を業務データの正本にしない。

## 非対象

- 現行 PHP アプリの改修、データ移行、Open WebUI の起動・構築
- 現行画面のピクセル単位の再現
- 現行の全ルーティング・プロンプト・ログキーの互換維持
