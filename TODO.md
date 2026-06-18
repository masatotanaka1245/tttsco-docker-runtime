# TODO.md

このファイルは、このアプリ共同開発における進捗管理表です。

## ステータス定義

- `未着手`: まだ着手していない
- `進行中`: いま実装を進めている
- `検証中`: 実装は終わり、ログや動作確認をしている
- `実装済み（要改善）`: 動く状態までは入っているが、品質やUXに改善余地がある
- `保留`: 現行構成から外しており、再導入方針が決まるまで止める
- `要確認`: 実装有無や優先度を追加確認したい
- `完了`: 実装と確認が終わっている

## 運用ルール

- `進行中` のタスクは常に 1 件だけにする
- 実装済みのものを `未着手` のまま残さない
- 未確認のものは断定せず `要確認` に置く
- 仕様変更を伴う場合は、`README_01.md` と必要に応じて関連ドキュメントも更新する
- 新しい再発防止ルールが出たら、`AGENTS.md` に追記する

## 進行中

| 優先度 | 状態 | タスク | 対象ファイル | 確認方法 |
| --- | --- | --- | --- | --- |
| P1 | 進行中 | 案件ごとの `AGENTS / README / TODO` 相当メモを `project_meta` に保持し、資料メモ `.md`・現在の主成果品・`TODO` ステータスを含む成果品ベースの自動更新を安定化する | `AI_System_Data/src/ProjectContextMemory.php`, `AI_System_Data/src/ProjectMemoryAutoUpdater.php`, `AI_System_Data/src/SupportController.php`, `AI_System_Data/public/api/chat.php`, `AI_System_Data/public/api/chat_normal.php`, `AI_System_Data/public/api/chat_analysis.php`, `AI_System_Data/public/api/chat_advanced.php` | `support.php?project_id=...&tab=memory` で自動生成メモと手動メモが分かれて表示されること、`現在の主成果品` と `進行中タスク` が出ること、`chat_debug.log` に `[PROJECT-MEMORY]` と `[PROJECT-MEMORY-AUTO]` が出ることを確認する |

## 未着手タスク

| 優先度 | 状態 | タスク | 対象ファイル | 確認方法 |
| --- | --- | --- | --- | --- |
| P1 | 未着手 | 回答生成精度の全体改善に向け、route ごとの `prompt packet` を再設計し、`project memory / DB memory / 会話履歴 / RAG根拠 / 質問本文` の優先順位と総文字数上限を決定論的に制御する | `AI_System_Data/public/api/chat_normal.php`, `AI_System_Data/public/api/chat_analysis.php`, `AI_System_Data/public/api/chat_advanced.php`, `AI_System_Data/src/PromptManager.php`, `AI_System_Data/src/ProjectContextMemory.php`, `AI_System_Data/src/ChatHistoryContextResolver.php` | route ごとの `[PROMPT-BUDGET]` を比較し、`現在の資料md` と `直前の成果品ステート` が会話履歴より優先されることを実ログで確認する |
| P1 | 未着手 | `ChatRouteFactorizer / ChatRouteSelector` の正規表現分岐を棚卸しし、信頼度スコアと確認質問フォールバックを持つ `route confidence` 方式へ寄せる | `AI_System_Data/src/ChatRouteFactorizer.php`, `AI_System_Data/src/ChatRouteSelector.php`, `AI_System_Data/src/ChatHistoryContextResolver.php`, `AI_System_Data/public/api/chat.php` | 低信頼度では確認質問へ倒れ、高信頼度時だけ route を強制決定することを `chat_debug.log` で確認する |
| P2 | 未着手 | `どのように分析したらよいでしょうか。` のような抽象的な分析相談を、重い SQL ループではなく metadata / advisory 寄りの提案ルートへ寄せる | `AI_System_Data/src/ChatRouteFactorizer.php`, `AI_System_Data/src/ChatRouteSelector.php`, `AI_System_Data/public/api/chat_analysis.php`, `AI_System_Data/public/api/chat_normal.php` | 抽象相談で空SQLや自己修復ループへ入らず、短時間で提案系回答が返ることを確認する |
| P2 | 未着手 | `資料` タブまわりのフロント実装を `support.js` から段階的に分離し、`materials.js` 相当の専用モジュールへ整理する | `AI_System_Data/public/assets/js/support.js`, `AI_System_Data/public/assets/js/modules/`, `AI_System_Data/public/support.php`, `AI_System_Data/public/templates/modals.php` | 資料一覧、編集、保存後即時更新、削除後更新、AI回答からの追記が従来どおり動くことを確認する |
| P2 | 未着手 | `Datetime` / `時間帯` 系の質問を日別・月別ではなく時間帯別の粒度として解釈し、follow-up でも列・粒度継承を安定化する | `AI_System_Data/src/CsvAggregationPlanner.php`, `AI_System_Data/src/CsvDateAggregationRunner.php`, `AI_System_Data/src/ChatHistoryContextResolver.php` | `Datetimeから多い時間帯を教えて` と `時間帯ごとにグラフ化してください。` が時間帯粒度で返ることを確認する |
| P3 | 未着手 | headless browser による報告書 PDF 変換ログの文字化けを抑え、Chrome / Edge 実行可否が読み取れる形に整える | `AI_System_Data/src/ReportGenerator.php`, `AI_System_Data/logs/chat_debug.log` | `[REPORT] headless browser version:` 周辺のログが文字化けせず読めることを確認する |

## 実装済み（要改善）

| 優先度 | 状態 | タスク | 対象ファイル | 確認方法 |
| --- | --- | --- | --- | --- |
| P1 | 実装済み（要改善） | CSV AI categorization を進捗付き非同期ジョブで実行し、結果を新しい CSV として生成する基盤 | `AI_System_Data/src/CsvAiCategorizationJobService.php`, `AI_System_Data/public/api/start_csv_ai_categorize_job.php`, `AI_System_Data/public/api/get_csv_ai_job_status.php`, `AI_System_Data/bin/run_csv_ai_categorize_job.php`, `AI_System_Data/public/assets/js/modules/csv.js`, `AI_System_Data/public/templates/modals.php` | ジョブ起動、進捗取得、完了後の新規CSV追加までは実装済み。残りはトーストの `ジョブが見つかりません`、完了後自動反映、UX文言、精度改善を確認・補正する |
| P1 | 実装済み（要改善） | `project memory` 自動更新の品質ゲート第1段として `ProjectMemoryAutoUpdater.php` の `shouldRefreshFromEvaluation()` に pre-check を追加し、低品質な回答・確認質問・操作案内・根拠不足回答で `AGENTS / README / TODO` 自動メモが汚染されないようにする | `AI_System_Data/src/ProjectMemoryAutoUpdater.php` | `verdict_not_pass` / `mismatch_pair` / `evaluation_fallback` / `judge_fallback` / `clarification_marker` / `rewrite_marker` / `operation_notice_marker` / `insufficient_evidence_marker` / `intent_drift_marker` / `clarification_text` / `operation_notice_text` / `insufficient_evidence_text` / `error_notice_text` / `completion_notice_text` で `[PROJECT-MEMORY-AUTO] skipped=quality_guard` が出ることを確認済み。高品質な `project_memory_consultation` では `project_meta` が更新され、確認質問 / 操作案内 / 情報不足系では更新されないことも確認済み。残りは `chat_history_summary.php` の無条件 refresh 見直し、reason 判定順の微調整、実運用ログでの継続確認を行う |
| P1 | 実装済み（要改善） | FAQ auto-registration の第一段として `FaqAutoRegistrar.php` に登録直前ガードを追加し、FAQ 登録してはいけない回答を自動登録対象から外す | `AI_System_Data/src/FaqAutoRegistrar.php` | `verdict_not_pass` / `mismatch_pair` / `clarification_marker` / `rewrite_marker` / `insufficient_evidence_text` / `operation_notice_text` で `[FAQ-AUTO]` が skip されること、実運用では FAQ 登録ログの確認、誤登録/登録漏れの検証、必要に応じた判定文言の調整を続ける |
| P1 | 実装済み（要改善） | 回答直前の適合性ガード第1段として `LightweightFinalAnswerGuard.php` に deterministic rule を追加し、操作説明だけの回答、根拠不足定型文、`相談 / 資料メモ更新 / 報告書化` の代表的な誤脱線を出荷前に止める | `AI_System_Data/src/LightweightFinalAnswerGuard.php` | `FINAL-GUARD-OPERATION-NOTICE` / `FINAL-GUARD-INSUFFICIENT-EVIDENCE` / `FINAL-GUARD-INTENT-DRIFT` が機能すること、`project_memory_consultation` / `history_report` / `data_analysis.csv_agg` の正常系が過剰ブロックされないことを確認済み。残りは実運用ログで「確認質問に倒したいのに倒せなかった実例」を収集し、必要なら `ClarificationQuestionBuilder.php` を拡張する |
| P1 | 実装済み（要改善） | 会話履歴から `成果品ステート` を復元し、CSV follow-up の route lock と履歴の報告書化優先を実装する | `AI_System_Data/src/ChatHistoryContextResolver.php`, `AI_System_Data/src/ChatRouteSelector.php`, `AI_System_Data/public/api/chat.php`, `AI_System_Data/public/api/chat_analysis.php`, `AI_System_Data/public/api/chat_history_summary.php` | `若い順で`, `グラフ化して`, `これまでの会話を報告書にして` が期待 route に入り、`route_detail_override` と着地が実ログで安定することを確認する |
| P2 | 実装済み（要改善） | `chat_analysis.php` の planner / runner / prompt に復元した `成果品ステート` を渡し、route lock 後の出力継続を決定論的にする | `AI_System_Data/public/api/chat_analysis.php`, `AI_System_Data/src/CsvAggregationPlanner.php`, `AI_System_Data/src/CsvDateAggregationRunner.php`, `AI_System_Data/src/CsvValueAggregationRunner.php`, `AI_System_Data/src/CsvAggregationAnswerFormatter.php` | `output_format` / `chart_type` / `base_sql` を保持した follow-up が崩れないことを確認する |
| P2 | 実装済み（要改善） | `main / sub / sql / embedding / vision` の5層モデル責務を route 全体へ適用する | `AI_System_Data/src/ModelRoleResolver.php`, `AI_System_Data/public/api/save_user_settings.php`, `AI_System_Data/public/api/chat.php`, `AI_System_Data/public/api/upload.php` | `result.model_roles` と `[MODEL-ROLES]` ログ、`[PDF-IMAGE-MODEL] vision_model=...` の出力を route ごとに確認する |
| P2 | 実装済み（要改善） | 単発・軽量ルートの最終回答ガードを rule-first に寄せ、deterministic 出力を壊さずに質問適合性を確認する | `AI_System_Data/src/LightweightFinalAnswerGuard.php`, `AI_System_Data/public/api/chat_analysis.php`, `AI_System_Data/public/api/chat_history_summary.php` | `json:chart` や Mermaid が rewrite で壊れず、資料PDF質問で根拠寄り回答になることを確認する |
| P2 | 実装済み（要改善） | 報告書モードの本文精度を route 横断で揃えるため、通常ルートとデータ分析ルートでも報告書向け最終整形を共通化する | `AI_System_Data/src/ReportAnswerPolisher.php`, `AI_System_Data/public/api/chat_normal.php`, `AI_System_Data/public/api/chat_analysis.php`, `AI_System_Data/src/ReportGenerator.php` | `report_mode=on` で `## 結論 / ## 分析対象 / ## 根拠 / ## 留意点 / ## 推奨アクション / ## 出典` の骨格へ寄ることを確認する |
| P2 | 実装済み（要改善） | 文章改善・提案相談で、CSV名言及だけで `data_analysis` に倒れないよう route 競合と RAG コンテキスト汚染を抑える | `AI_System_Data/src/ChatRouteFactorizer.php`, `AI_System_Data/src/ChatRouteSelector.php`, `AI_System_Data/public/api/chat_normal.php`, `AI_System_Data/src/RAGPipeline.php` | 相談系で CSV 証拠読解へ落ちず、`[RAG-CONTEXT-FILTER]` と `最終ルート決定` が期待どおりになることを確認する |
| P3 | 実装済み（要改善） | 全社横断ブリーフィング系の route・ReAct・host timeout フォールバックの安定化 | `AI_System_Data/public/api/chat_global.php`, `AI_System_Data/public/api/chat.php`, `AI_System_Data/src/ChatRouteDispatcher.php`, `AI_System_Data/src/EmbeddingEngine.php` | `GLOBAL-REACT-STOP` / `GLOBAL-MODEL-FALLBACK` と最終回答が数十秒以内に着地することを確認する |

## 検証中

| 優先度 | 状態 | タスク | 対象ファイル | 確認方法 |
| --- | --- | --- | --- | --- |
| P1 | 検証中 | CSV の日付・年月・時間帯系自然文を `data_analysis.csv_agg` に安定して寄せつつ、`ユニーク件数` / `件数分布` / `特定値件数` / `時間帯別集計` の取り分けを整える | `AI_System_Data/src/ChatRouteFactorizer.php`, `AI_System_Data/src/CsvAggregationPlanner.php`, `AI_System_Data/src/CsvDateAggregationRunner.php`, `AI_System_Data/src/CsvValueAggregationRunner.php` | 月別・年月別・特定月件数・時間帯別質問が意図どおり分かれることを `chat_debug.log` で確認する |
| P1 | 検証中 | CSV の曖昧質問を、誤った概況回答ではなく確認質問へ倒す境界を整える | `AI_System_Data/src/CsvAggregationPlanner.php`, `AI_System_Data/src/CsvAggregationAnswerFormatter.php`, `AI_System_Data/public/api/chat_analysis.php` | 列未指定の `グラフ化してください` で確認質問に止まることを確認する |
| P2 | 検証中 | evaluator fallback が未完了回答を通してしまう条件を絞り、低品質回答で `project memory` を汚染しないよう補強する | `AI_System_Data/src/ChatEvaluator.php`, `AI_System_Data/src/ProjectMemoryAutoUpdater.php`, `AI_System_Data/src/AdvancedRouteFinalizer.php` | 低品質回答で `allow_memory_refresh=false` と `[PROJECT-MEMORY-AUTO] skipped=quality_guard` が出ることを確認する |
| P2 | 検証中 | `chat_advanced.php` の責務分離候補を棚卸しし、分割順序を確定する | `AI_System_Data/public/api/chat_advanced.php`, `AI_System_Data/src/AdvancedDocAnswerBuilder.php`, `AI_System_Data/src/AdvancedSubQueryNormalizer.php`, `AI_System_Data/src/AdvancedRoutePlanner.php`, `AI_System_Data/src/AdvancedPlanExecutor.php`, `AI_System_Data/src/AdvancedDraftComposer.php`, `AI_System_Data/src/AdvancedCriticLoop.php`, `AI_System_Data/src/AdvancedRouteFinalizer.php` | `advanced_hybrid.doc_extract` で既存ログと最終回答が崩れないことを確認する |
| P2 | 検証中 | 本番環境の PDF プレビューで `documents.file_path` と実ファイル配置のズレを吸収し、`view_pdf.php` の安定配信を確認する | `AI_System_Data/public/api/view_pdf.php`, `AI_System_Data/public/api/delete_pdf.php`, `AI_System_Data/public/support.php`, `AI_System_Data/public/assets/js/modules/ui.js` | `pdf_view.log` の `stream started` 到達と、Chrome / Edge 埋め込みプレビュー成功を確認する |

## 保留 / 再導入検討

| 優先度 | 状態 | タスク | 対象ファイル | 確認方法 |
| --- | --- | --- | --- | --- |
| P3 | 保留 | watchdog 常駐監視を現行構成へ戻すか判断し、必要なら実装・Docker・README・運用手順をまとめて再導入する | `docker-compose.yml`, `README_01.md`, `README_DOCKER.md`, `AI_System_Data/public/docs/design_v3.html`, `scripts/watchdog.php` | 方針決定までは現行構成から外す。再導入する場合は、実ファイル配置、起動方法、ログ出力、監視対象パスが揃うことを確認する |

## 要確認

| 優先度 | 状態 | タスク | 対象ファイル | 確認方法 |
| --- | --- | --- | --- | --- |
| P3 | 要確認 | `PromptManager.php` を中心とした prompt 組み立て責務の再配置範囲をどこまで広げるか | `AI_System_Data/src/PromptManager.php`, `AI_System_Data/public/api/chat_normal.php`, `AI_System_Data/public/api/chat_analysis.php`, `AI_System_Data/public/api/chat_advanced.php` | `PROMPT-BUDGET` ログと route ごとの差分を見て、共通化が有効な範囲を見極める |

## 完了

| 優先度 | 状態 | タスク | 対象ファイル | 確認方法 |
| --- | --- | --- | --- | --- |
| P1 | 完了 | `chat_threads` と `chat_history.thread_id` を正式スキーマへ反映し、README / 設計書の説明も現行実装へ追従させる | `AI_System_Data/config/db.sql`, `AI_System_Data/config/schema_check.sql`, `README_01.md`, `README_DOCKER.md`, `AI_System_Data/public/docs/design_v3.html` | `db.sql` / `schema_check.sql` / README / 設計書で thread 構成、`view_pdf.php`、モデル既定値、watchdog 現状が整合していることを確認した |
