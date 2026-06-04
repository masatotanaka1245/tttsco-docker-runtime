# TODO.md

このファイルは、このアプリ共同開発における進捗管理表です。

- `未着手`: まだ着手していない
- `進行中`: いま実装を進めている
- `検証中`: 実装は終わり、ログや動作確認をしている
- `完了`: 実装と確認が終わっている

運用ルール:

- `進行中` のタスクは常に 1 件だけにする
- タスクには、対象ファイルと確認方法をできるだけ添える
- 仕様変更を伴う場合は、`README_01.md` と必要に応じて関連ドキュメントも更新する
- 新しい再発防止ルールが出たら、`AGENTS.md` に追記する

## タスク一覧

| 状態 | タスク | 対象ファイル | 確認方法 |
| --- | --- | --- | --- |
| 検証中 | 案件ごとの `AGENTS / README / TODO` 相当メモを `project_meta` に保持し、回答生成前に補助コンテキストとして参照する | `AI_System_Data/src/ProjectContextMemory.php`, `AI_System_Data/src/PromptManager.php`, `AI_System_Data/src/SupportController.php`, `AI_System_Data/public/support.php`, `AI_System_Data/public/api/chat_normal.php`, `AI_System_Data/public/api/chat_analysis.php`, `AI_System_Data/public/api/chat_advanced.php`, `README_01.md`, `AGENTS.md`, `TODO.md` | 実装は一通り入っている。`support.php?project_id=...&tab=overview` で AGENTS / README / TODO を保存できること、再読込で値が保持されること、対象 route のログに `[PROJECT-MEMORY] loaded=... | chars=...` が出ることを確認し、さらに回答がメモ方針を補助的に参照しつつ PDF / CSV / DB 実データを優先していることを確認する |
| 進行中 | モデル責務を `main / sub / embedding` の3層で再設計し、ヘッダー設定・セッション・各チャットルートへの適用方針を確定する | `README_01.md`, `AGENTS.md`, `TODO.md`, `AI_System_Data/docs/chat_system_overview_20260603.md`, `AI_System_Data/src/ModelRoleResolver.php`, `AI_System_Data/src/OllamaModelCatalog.php`, `AI_System_Data/src/UserSettingsSchema.php`, `AI_System_Data/public/templates/header.php`, `AI_System_Data/public/api/save_user_settings.php`, `AI_System_Data/public/api/chat.php`, `AI_System_Data/src/ChatRouteDispatcher.php`, `AI_System_Data/public/api/chat_normal.php`, `AI_System_Data/public/api/chat_history_summary.php`, `AI_System_Data/public/api/chat_analysis.php`, `AI_System_Data/src/CsvEvidenceReader.php`, `AI_System_Data/src/CsvSemanticAggregationRunner.php`, `AI_System_Data/public/api/chat_advanced.php`, `AI_System_Data/public/api/chat_global.php`, `AI_System_Data/src/EmbeddingEngine.php`, `AI_System_Data/public/api/upload.php`, `AI_System_Data/public/api/upload_csv.php`, `AI_System_Data/src/RAGPipeline.php`, `AI_System_Data/config/db.sql`, `AI_System_Data/config/schema_check.sql`, `AI_System_Data/src/CsvSummaryFormatter.php`, `AI_System_Data/src/CsvAggregationPlanner.php`, `AI_System_Data/src/CsvQuestionRouter.php`, `AI_System_Data/src/ChatRouteSelector.php` | 実装はほぼ出揃っており、ここからは全 route での実効確認と不足補正を優先する。`ModelRoleResolver` に既定値と実効値が集約され、`embedding_model` は設定画面・保存API・セッションに通り、DB列が無い環境では `UserSettingsSchema` により安全にフォールバックする。`advanced_hybrid` / `global` では `main = 因数分解・最終統合`, `sub = 中間処理` が route 内で反映され、保存時には `Ollama /api/tags` を使って `main / sub / embedding` のモデル存在チェックが行われる。`data_analysis` でも第一段として `main = 因数分解・最終統合・評価`, `sub = SQL生成・証拠読解・semantic補助` の責務分担が反映され、deterministic 集計は main/sub に依存せず維持される。加えて `history_summary / normal_rag / data_analysis / advanced_hybrid / global` の `result` payload と dispatcher ログに `model_roles` / `[MODEL-ROLES]` が揃い、`callOllamaChat()` では `[OLLAMA-PAYLOAD]` / `[OLLAMA-THINK]` により Gemma 系の `<|think|>` 自動付与と思考トレース有無を後追い確認できるようになった。今回の優先確認では、本番ログまたは `result.model_roles` を見て、各 route の最終回答が `main` で着地し、中間処理が `sub` で走っていること、さらに `diagram_mode=on` の CSV 概要で確実にグラフが返ることを確認する |
| 検証中 | 単発・軽量ルートの最終回答ガードを rule-first に寄せ、deterministic 出力を壊さずに質問適合性を確認する | `AI_System_Data/src/LightweightFinalAnswerGuard.php`, `AI_System_Data/public/api/chat_analysis.php`, `AI_System_Data/src/CsvQuickResponseRunner.php`, `AI_System_Data/public/api/chat_history_summary.php`, `AI_System_Data/src/ChatRouteDispatcher.php`, `AI_System_Data/public/api/chat_advanced.php`, `AI_System_Data/src/ChatRouteSelector.php`, `README_01.md`, `AGENTS.md` | `CSV-SUMMARY`, `history_summary`, `advanced_hybrid.doc_extract` の軽量最終回答で、`[FINAL-GUARD]` が短時間で出ること、`json:chart` や Mermaid が rewrite で壊れないこと、`グラフにしてください。` で deterministic な `json:chart` が返ること、資料PDF質問で資料名・ページ番号付きの根拠寄り回答になることを確認 |
| 検証中 | CSV の月別・年月系自然文を `data_analysis.csv_agg` に安定して寄せつつ、`ユニーク件数` と `各値の件数分布` と `特定値件数` の取り分けを整える | `AI_System_Data/src/ChatRouteFactorizer.php`, `AI_System_Data/src/CsvAggregationPlanner.php`, `AI_System_Data/src/ChatHistoryContextResolver.php`, `AI_System_Data/src/CsvDateAggregationRunner.php`, `AI_System_Data/src/CsvValueAggregationRunner.php`, `AI_System_Data/src/CsvAggregationAnswerFormatter.php` | `csvのデータを月別で集計してください。`, `Datetime から年月で集計してください。`, `年月（日時は不要）で集計してください。`, `2026年4月を抽出して件数を出してください。`, `「年月」カラムのユニークな値27件の各レコード数を集計してもらえますか。`, `「年月」カラムから2025年3月の総件数を抽出してください。` が意図どおり `date_histogram` / `value_distribution` / `exact_value_count` に分かれ、`context_source` / `target_column` / `target_value` がログで意図どおり見えることを確認 |
| 検証中 | 全社横断ブリーフィング系質問の route と前段ログ文言の整合性を見直す | `AI_System_Data/src/ChatRouteSelector.php`, `AI_System_Data/public/api/chat_global.php`, `AI_System_Data/public/api/chat.php`, `AI_System_Data/src/ChatRouteDispatcher.php` | `global_no_project` / `global_cross` について、selector の前段ログ、dispatcher の `最終ルート決定`, `[FINAL-ANSWER] route=...`, `result.mode_used` が同じ route 名で揃うことを確認 |
| 未着手 | `CsvAggregationTargetResolver` の責務を棚卸しし、対象解決と SQL 実行の境界を見直す | `AI_System_Data/src/CsvAggregationTargetResolver.php`, `AI_System_Data/public/api/chat_analysis.php` | 既存 `CSV-AGG` ログキーを崩さずに構文確認と主要ルートの回帰確認 |
| 未着手 | `chat_advanced.php` の責務分離候補を棚卸しし、分割順序を確定する | `AI_System_Data/public/api/chat_advanced.php`, `AI_System_Data/docs/chat_system_overview_20260603.md` | 俯瞰メモ更新と、分割候補の責務一覧が揃っていることを確認 |
