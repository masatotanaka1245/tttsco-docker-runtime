# AGENTS.md

最終更新: 2026-06-03

このファイルは、このプロジェクトでエージェントにコーディング作業を依頼する際の共通運用ルールです。
目的は、機能追加・不具合修正・リファクタリングを PDCA で安定運用し、変更品質と説明責任を揃えることです。

## 1. 基本方針

- 既存仕様と既存実装を尊重する
- まず理解してから変更する
- 小さく切って進める
- 挙動変更と構造整理を混ぜない
- ログ、保存処理、ルート判定のような基幹動作は特に慎重に扱う

## 2. PDCA の回し方

### Plan

- 依頼内容を、以下のどれかに明確化してから着手する
  - 不具合修正
  - 機能追加
  - 機能改善
  - 無害な整理
  - 設計書・README更新
- 方針確認が必要な場合は、先に選択肢と推奨案を整理する
- 無害な整理では、挙動変更を入れない

### Do

- 変更は小さな単位で実施する
- 既存パターン、既存命名、既存ログ形式を優先する
- 可能な限り `apply_patch` で編集する
- 大きなファイルでは、責務単位で外出しする

### Check

- 変更後は最低限以下を実施する
  - `git diff --check`
  - 関連 PHP ファイルの `php -l`
- ローカル `php` が使えない場合は Docker コンテナ内で構文確認する
- ログ出力が重要な処理は、想定ログキーが維持されているか確認する

### Act

- 最後に以下を整理する
  - 今回何を変えたか
  - 何は変えていないか
  - 次に進める自然な候補
- 仕様差分が出た場合は `README_01.md` と `AI_System_Data/public/docs/design_v3.html` を更新する

## 3. このプロジェクトで特に守ること

### 3.1 変更方針

- 無害な整理では、以下を変えない
  - API の入出力
  - SSE イベント名
  - ログキー
  - DB保存フロー
  - ルート名
- 機能改善は、無害な整理のあとに実施する

### 3.2 ログ運用

- `chat_debug.log` は重要な観測点として扱う
- 既存の代表ログ例
  - `[INPUT-GUARD]`
  - `[SMART-ROUTER]`
  - `[CSV-SEARCH]`
  - `[CSV-EVIDENCE]`
  - `[CSV-AGG]`
  - `[CSV-AGG-SQL]`
  - `[EVAL-POLICY]`
  - `[SQL-REPAIR-POLICY]`
- 既存ログの意味を変える場合は、名称変更ではなく追加ログを優先する

### 3.3 検証方針

- 可能なら、構文チェックだけでなく関連ログの流れも確認する
- ただし、ログインセッションやブラウザ状態が必要な確認は、無理に自動化しない
- ブラウザ実動作確認が必要な場合は、確認観点をユーザーへ明示する

## 4. 主要ファイルの責務

### API ルート

- `AI_System_Data/public/api/chat.php`
  - チャット入口
  - 入力ガード
  - スマートルーティング
  - 各ルート呼び出し

- `AI_System_Data/public/api/chat_normal.php`
  - 通常 RAG
  - ストリーミング応答
  - 軽量な品質評価

- `AI_System_Data/public/api/chat_analysis.php`
  - CSV中心の分析ルート
  - CSV検索
  - CSV証拠読解
  - 構造化集計
  - Text-to-SQL
  - 品質評価
  - 保存処理

- `AI_System_Data/public/api/chat_advanced.php`
  - フル思考ルート
  - 多段計画
  - SQL抽出
  - Map-Reduce的統合
  - 品質評価
  - 保存処理

### src 配下

- `AI_System_Data/src/SqlExecutionEngine.php`
  - SQL補正
  - 安全監査
  - 実行
  - 修復ガイダンス

- `AI_System_Data/src/ChatEvaluator.php`
  - 回答評価
  - verdict / feedback / next_action / sql_hint の整形

- `AI_System_Data/src/ChatRequestGuard.php`
  - 誤送信、短文、曖昧入力のガード

- `AI_System_Data/src/ChatEvaluationPolicy.php`
  - 通常RAGで評価を走らせる条件判定

## 5. 現在のリファクタリング方針

対象の最優先は `AI_System_Data/public/api/chat_analysis.php`。

### リファクタリングの考え方

- 機能追加を続けながら一気に全面整理するのではなく、壊しにくい単位で段階的に分ける
- 基本順序は以下の3段階とする
  1. 機能を変えない整理
  2. 責務分離
  3. 挙動改善
- リファクタリングの途中では、構造変更と仕様改善を同じ変更セットに混ぜない

### フェーズ定義

1. 棚卸しフェーズ
   - 大きいファイルの責務を見える化する
   - ここでは基本的に挙動変更を入れない
2. 無害な分離フェーズ
   - 同じ動作のまま、メソッドやクラスを外出しする
3. ポリシー分離フェーズ
   - 判定ロジックを独立クラスへ寄せる
4. 改善フェーズ
   - 分離後に、必要な挙動改善を入れる

### 棚卸し対象

- `AI_System_Data/public/api/chat.php`
- `AI_System_Data/public/api/chat_analysis.php`
- `AI_System_Data/public/api/chat_advanced.php`
- `AI_System_Data/public/api/chat_normal.php`
- `AI_System_Data/src/SqlExecutionEngine.php`
- `AI_System_Data/src/ChatEvaluator.php`

### 現在の順序

1. `CsvSearchTermExtractor`
2. `CsvDateColumnDetector`
3. `CsvAggregationPlanner`
4. `CsvAggregationQueryBuilder`
5. `CsvAggregationAnswerFormatter`
6. `CsvEvidenceReader`
7. `CsvQuestionRouter`
8. `CsvSummaryFormatter`
9. `CsvMetadataCatalog`
10. `CsvSearchService`
11. `CsvSampleRowRepository`
12. CSV helper の依存整理
13. 残存CSVユーティリティの棚卸し

### すでに分離済み

- `AI_System_Data/src/CsvSearchTermExtractor.php`
- `AI_System_Data/src/CsvDateColumnDetector.php`
- `AI_System_Data/src/CsvAggregationPlanner.php`
- `AI_System_Data/src/CsvAggregationQueryBuilder.php`
- `AI_System_Data/src/CsvAggregationAnswerFormatter.php`
- `AI_System_Data/src/CsvEvidenceReader.php`
- `AI_System_Data/src/CsvQuestionRouter.php`
- `AI_System_Data/src/CsvSummaryFormatter.php`
- `AI_System_Data/src/CsvMetadataCatalog.php`
- `AI_System_Data/src/CsvSearchService.php`
- `AI_System_Data/src/CsvSampleRowRepository.php`
- `CsvEvidenceReader` / `CsvSummaryFormatter` の helper 依存整理

### 現時点の到達点

- `chat_analysis.php` の CSV 周辺は、無害な整理フェーズとしてはかなり十分な段階まで整理済み
  - helper の外出し
  - 薄い wrapper の削減
  - callback / local helper / 完了処理の共通化
  - 読みやすさ改善のための小さな責務整理
- `chat_advanced.php` は、棚卸し完了のうえで小さな共通化フェーズに入っている
  - logger callback
  - 完了処理
  - 出力モード判定
  - サブクエリ正規化入口
  - target table 判定 / 正規化
  - CSV Map-Reduce 対象判定
  - サブクエリ判定文脈生成

### 次の優先候補

- `chat_advanced.php` の無害整理をもう少しだけ継続する
  - まだ自然に名前付き helper へ寄せられる小さな重複があるか確認
  - 薄すぎる整理しか残っていないなら、この段は完了と判断する
- `chat_analysis.php` と `chat_advanced.php` の共通化候補を棚卸しする
  - logger callback
  - 完了処理
  - 出力モード判定
  - 正規化 helper
  - route / plan / evidence 系の共通責務
- 次段として、改善フェーズへ進むかを判断する
  - 集計質問の優先制御
  - 0件時の再探索
  - 評価結果の `next_action` / `sql_hint` 活用
  - AI因数分解JSONの導入検討

### 第1スプリントの扱い

- まずは `chat_analysis.php` に絞る
- 理由:
  - 現時点で最も責務が集中している
  - CSV集計と証拠読解の課題がここに集まっている
  - 他ルートを巻き込まず安全に分離しやすい
- 第1スプリントでは、以下のような外出しを優先する
  - 検索語抽出
  - 日付列判定
  - 集計SQL生成
- 実施済み:
  - 検索語抽出
  - 日付列判定
  - 集計SQL生成
  - 回答整形
  - CSV証拠読解
  - CSV質問ルーティング判定
  - 要約系ロジック整理
  - CSVメタデータ取得整理
  - CSV検索依存整理
  - CSVサンプル行取得整理
  - 未使用wrapper整理
  - CSV helper 依存整理
  - 未使用CSV委譲の追加整理
  - CsvEvidenceReader 読み出し委譲の直接呼び化
  - CsvSummaryFormatter 出力委譲の直接呼び化
  - CsvEvidenceReader 出力委譲の直接呼び化
  - CsvQuestionRouter / CsvMetadataCatalog 判定・取得委譲の直接呼び化
  - CsvAggregationPlanner / CsvSampleRowRepository / CsvDateColumnDetector の直接呼び化
  - CsvSearchService / CsvEvidenceReader の薄い橋渡し委譲整理
  - CsvAggregationAnswerFormatter 出力委譲の直接呼び化
  - getter 内クロージャと local helper 参照の整理
  - CSVルート本体での local helper 参照の追加整理
  - 集計側 helper 参照の local 化
  - UTF-8 正規化クロージャ生成の共通化
  - helper 用 callback 生成の共通化
  - 報告書生成側への logger callback 共通化
  - CSV helper 判定 callback の共通化
  - CSVルート完了処理の共通化
  - `chat_advanced.php` での logger callback 共通化着手
  - `chat_advanced.php` での完了処理共通化
  - `chat_advanced.php` での出力モード判定共通化
  - `chat_advanced.php` でのサブクエリ正規化入口の共通化
  - `chat_advanced.php` での target table 判定共通化
  - `chat_advanced.php` での CSV Map-Reduce 対象判定共通化
  - `chat_advanced.php` での target table 正規化共通化
  - `chat_advanced.php` でのサブクエリ判定文脈生成の共通化
- 判断メモ:
  - `chat_analysis.php` は無害整理フェーズの到達点にかなり近い
  - 以降は `chat_advanced.php` の整理と、両者の共通化候補棚卸しを優先する
- この段階では動作を変えず、ログの補強だけを必要最小限で行う

### やらない方がいい進め方

- いきなり全面書き直しを行う
- `chat.php`、`chat_analysis.php`、`chat_advanced.php` を同時に大規模改修する
- ログ仕様、UI仕様、SQLロジックを一度にまとめて変える

### 実務上の進め方

- 1回の改修で1責務だけ分ける
- 毎回 `php -l` と `git diff --check` を行う
- ログキーは維持する
- 既存の SSE イベント名は維持する
- 挙動変更と構造変更を混ぜない
- `AGENTS.md` は進捗メモとしてこまめに更新してよい
  - 着手前: 今回の対象と順序を反映する
  - 区切りごと: 分離済み項目と次候補を更新する
  - 完了時: 実施済み内容と次の一手を反映する

## 6. 依頼時の推奨ルール

以下のどれで進めるかを明示すると、作業が安定しやすいです。

- 方針から決めたい
- まず調査とレビュー
- 無害な整理で進める
- 機能改善まで含めて進める
- 設計書・READMEも更新する

## 7. 禁止に近い扱い

- 無害な整理フェーズで機能変更を混ぜること
- 関連の薄いファイルをまとめて大規模変更すること
- 既存ログキーを黙って変えること
- 既存の SSE 表示契約を黙って変えること
- ユーザーの未指示な破壊的操作

## 8. 完了報告の最低ライン

完了時は最低限、以下を共有する。

- 変更ファイル
- 変更の要点
- 実施した確認
- 未確認事項
- 次に自然な一手
