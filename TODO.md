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
| 進行中 | CSV の月別・年月系自然文を `data_analysis.csv_agg` に安定して寄せ、month 集計・特定月件数・follow-up 補完をまとめて整える | `AI_System_Data/src/ChatRouteFactorizer.php`, `AI_System_Data/src/CsvAggregationPlanner.php`, `AI_System_Data/src/ChatHistoryContextResolver.php`, `AI_System_Data/src/CsvDateAggregationRunner.php`, `AI_System_Data/src/CsvValueAggregationRunner.php` | `csvのデータを月別で集計してください。`, `Datetime から年月で集計してください。`, `年月（日時は不要）で集計してください。`, `2026年4月を抽出して件数を出してください。` が `data_analysis.csv_agg` に乗ることをログで確認 |
| 未着手 | 全社横断ブリーフィング系質問の route と前段ログ文言の整合性を見直す | `AI_System_Data/src/ChatRouteSelector.php`, `AI_System_Data/public/api/chat_global.php`, `AI_System_Data/public/api/chat.php` | `global_no_project` / `global_cross` の最終 route と途中ログが意図どおり揃うことを確認 |
| 未着手 | `CsvAggregationTargetResolver` の責務を棚卸しし、対象解決と SQL 実行の境界を見直す | `AI_System_Data/src/CsvAggregationTargetResolver.php`, `AI_System_Data/public/api/chat_analysis.php` | 既存 `CSV-AGG` ログキーを崩さずに構文確認と主要ルートの回帰確認 |
| 未着手 | `chat_advanced.php` の責務分離候補を棚卸しし、分割順序を確定する | `AI_System_Data/public/api/chat_advanced.php`, `AI_System_Data/docs/chat_system_overview_20260603.md` | 俯瞰メモ更新と、分割候補の責務一覧が揃っていることを確認 |
