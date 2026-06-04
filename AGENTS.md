# AGENTS.md

最終更新: 2026-06-03

このファイルは、このプロジェクトでエージェントにコーディング作業を依頼する際の共通運用ルールです。
目的は、機能追加・不具合修正・リファクタリングを PDCA で安定運用し、変更品質と説明責任を揃えることです。

## 0. このファイルの役割

- `AGENTS.md` は、アプリ共同開発の運用ルールと教訓を蓄積するルールブックです。
- デバッグログや改修レビューから得た再発防止ルールを、仕様書ではなくこのファイルへ蓄積します。
- `README_01.md` にない独自仕様を作る場所ではなく、既存仕様の守り方と改修時の作法を残す場所として扱います。
- 新しい教訓や再発防止ルールが見つかったら、できるだけ同じ変更セットの中でこのファイルも更新します。

## 0.5 運用の鉄則

- `TODO.md` の `進行中` タスクは、常に 1 件だけに絞る
- 新しい教訓やルールが見つかったら、すぐに `AGENTS.md` を更新する
- `README_01.md` にない独自仕様や架空のカラムを勝手に作らない
- 仕様の正本は `README_01.md`、運用と教訓の正本は `AGENTS.md` として使い分ける
- 教訓を追記するときは、可能なら以下の3点を短く残す
  - 背景
  - やってはいけないこと
  - 推奨対応

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
- 実動作確認をしやすくするため、可能なら最終回答本文もログへ残す
  - 推奨キー:
    - `[FINAL-ANSWER]`
    - `[FINAL-ANSWER-QUESTION]`
    - `[FINAL-ANSWER-BODY]`
  - ただし長文はそのまま無制限に出さず、文字数と切り詰め有無も併記する
- 既存ログの意味を変える場合は、名称変更ではなく追加ログを優先する

### 3.3 検証方針

- 可能なら、構文チェックだけでなく関連ログの流れも確認する
- ただし、ログインセッションやブラウザ状態が必要な確認は、無理に自動化しない
- ブラウザ実動作確認が必要な場合は、確認観点をユーザーへ明示する

### 3.4 質問因数分解の扱い

- チャット受信時の質問解釈は、自由文のまま分岐させず、できるだけ構造化して扱う
- ルーティング前の粗い因数分解と、各ルート内の詳細計画は分ける
- 因数分解の目的は以下とする
  - どのルートへ送るべきかを安定化する
  - 単一CSV / 全CSV / PDF / 案件全体の取り違えを減らす
  - 集計 / 要約 / 検索 / 比較の意図混線を減らす
  - 改善フェーズでの判定修正を、場当たりの正規表現追加だけにしない

### 3.5 直近ログから得た教訓（2026-06-04）

- 背景
  - `csvのデータを月別で集計してください。` のような自然文が `advanced_hybrid` に流れ、空のSQL応答と汎用フォールバックに入るケースが出た。
  - `Datetime` / `年月` を月単位で見たい質問が、`date_histogram` ではなく `value_distribution` に倒れるケースが出た。
  - `2026年4月を抽出して件数` のような質問でも、特定値件数ではなく全体分布を返してしまうケースが出た。
- やってはいけないこと
  - 月別・年月系の自然文を、安易に `advanced_hybrid` や汎用 `value_distribution` に流すこと
  - `Datetime` や `年月日` のような日時列を、月粒度への丸めなしにそのまま分布集計へ渡すこと
  - 追い質問で CSV 名や列名が省略されたときに、説明系だけ文脈補完して集計系は補完しないこと
  - 特定月の件数要求を、全体分布の上位一覧で代用すること
- 推奨対応
  - `月別` / `年月` / `日時は不要` / `若い順` は、単一CSVの `data_analysis.csv_agg` に優先的に寄せる
  - 日時列は month 粒度へ丸めた deterministic な `date_histogram` を優先する
  - `2026年4月を抽出して件数` のような質問には、exact value count を専用モードとして扱う
  - CSV月別集計の follow-up でも、直前会話から `target_file_name` と `target_column` を補完する
  - 全社横断ブリーフィング系では、前段ログ文言より最終 route と実処理の整合性を優先して判断する

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

### 質問因数分解スキーマ

- 改善フェーズでは、質問を最低限以下のキーへ因数分解して扱う
  - `intent`
    - `summarize`
    - `aggregate`
    - `search`
    - `compare`
    - `explain`
    - `report`
  - `target`
    - `single_csv`
    - `all_csv`
    - `pdf`
    - `project`
    - `history`
  - `target_file_name`
    - 単一CSVや単一資料が推定できる場合に設定する
  - `scope`
    - `file_content`
    - `records_with_date`
    - `matching_records`
    - `project_wide`
  - `operation`
    - `count`
    - `sum`
    - `average`
    - `list`
    - `summarize`
    - `extract_evidence`
  - `time_axis`
    - `none`
    - `day`
    - `month`
    - `year`
  - `output_format`
    - `table`
    - `bullets`
    - `prose`
    - `report`
  - `route`
    - `normal_rag`
    - `data_analysis.csv_agg`
    - `data_analysis.csv_evidence`
    - `data_analysis.csv_summary`
    - `advanced_hybrid`

### 因数分解の実装方針

- 第1段: 入口の粗い因数分解
  - 実装場所は基本的に `AI_System_Data/public/api/chat.php`
  - 役割:
    - 何をしたい質問か
    - 主対象は何か
    - どのルートへ送るべきか
- 第2段: ルート内の詳細計画
  - CSV系は `AI_System_Data/public/api/chat_analysis.php`
  - 高度分析系は `AI_System_Data/public/api/chat_advanced.php`
  - 役割:
    - どの列を使うか
    - SQL集計に向くか
    - evidence読解に向くか
    - 表 / 箇条書き / 文章のどれで返すか

### 因数分解の具体例

- `「出荷一覧表」を日付別に集計してください。`
  - `intent=aggregate`
  - `target=single_csv`
  - `target_file_name=出荷一覧表`
  - `scope=records_with_date`
  - `operation=count`
  - `time_axis=day`
  - `output_format=table`
  - `route=data_analysis.csv_agg`

- `全てのcsvファイルから、日付のあるレコードを特定して、日付別にどのような情報があるか、表にしてください。`
  - `intent=aggregate`
  - `target=all_csv`
  - `scope=records_with_date`
  - `operation=count`
  - `time_axis=day`
  - `output_format=table`
  - `route=data_analysis.csv_agg`

- `出荷一覧表の内容を要約してください。`
  - `intent=summarize`
  - `target=single_csv`
  - `target_file_name=出荷一覧表`
  - `scope=file_content`
  - `operation=summarize`
  - `time_axis=none`
  - `output_format=prose`
  - `route=data_analysis.csv_summary`

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

- 改善フェーズへ入る
  - まず質問因数分解スキーマを基準として扱う
  - 場当たりの正規表現追加ではなく、`intent / target / route` の整合で判断する
- `chat.php` に粗い因数分解を導入する
  - 単一CSV集計
  - 全CSV集計
  - 単一CSV要約
  - CSV名言及ありの通常RAG誤流入
- `chat_analysis.php` にCSV専用の詳細因数分解を導入する
  - 構造化集計へ送る条件
  - evidence読解へ送る条件
  - metadata / summary へ送る条件
- 集計改善を入れる
  - 単一CSV集計が `CSV-EVIDENCE` へ落ちないようにする
  - 日付列誤判定を減らす
  - 0件時の再探索方針を定義する
- `chat_advanced.php` の改善を入れる
  - 資料中心質問では、不要なSQL生成シーケンスを先に踏まない
  - `semantic_extract + doc_chunks/documents` は資料RAG優先へ寄せる
  - `doc_chunks` 抽出は PDF優先・件数制限つきの定番SQLでノイズを減らす
  - `doc-only semantic_extract` では、重い統合・品質審査ループを短い最終整形へ寄せる
  - `doc-only semantic_extract` では、Planner も省略して定番プランへ寄せる
  - 軽量最終整形は、重い通常ドラフト生成より前に適用する
  - `doc-only semantic_extract` では、`doc_chunks` の中間考察LLMを挟まず、生の根拠断片を優先して最終整形へ渡す
  - 資料系の確認質問は、明示的に `PDF` と書かれていなくても、案件文脈では `advanced_hybrid` の資料抽出候補として因数分解する
  - `doc_chunks` の根拠断片は、そのまま先頭順で使わず、質問との近さと注意喚起っぽさで並べ替える

### 判断メモ

- `chat_analysis.php` の無害な整理フェーズは、CSV周辺については到達点にかなり近い
- 今後は無害整理の継続より、質問因数分解を基準にした改善フェーズの価値が高い
- とくに以下は優先度が高い
  - 単一CSV集計要求を構造化集計へ安定して送ること
  - CSV名つき要約要求を通常RAGではなくCSV要約へ安定して送ること
  - OTPや識別子のような列を日付列と誤判定しないこと
- 改善フェーズ着手:
  - `chat.php` に粗い質問因数分解を導入し、単一CSV集計 / 全CSV集計 / 単一CSV要約を先に判定する
  - `CsvSearchTermExtractor` のCSV名解決を、完全名だけでなくベース名でも一致しやすいよう改善する
  - `CsvQuestionRouter` の要約判定を改善し、CSV名言及つき要約が `CSV-EVIDENCE` へ流れにくいようにする
  - `CsvDateColumnDetector` の日付列判定を改善し、OTPや識別子を日付列と誤判定しにくいようにする
  - `chat_advanced.php` で、`semantic_extract + doc_chunks/documents` の資料中心質問はシーケンス1のSQL分析をスキップし、資料RAGへ直接移行する
  - `chat_advanced.php` で、資料PDF抽出時は `documents + doc_chunks` の定番SQLを使い、`AI報告書` などのノイズ源を減らす
  - `chat_advanced.php` で、資料PDFの留意点抽出は軽量最終回答ルートを使い、重い評価ループをできるだけ避ける
  - `chat_advanced.php` で、資料PDFの留意点抽出は Planner を省略し、定番プランで資料巡回へ直行する
  - `chat_advanced.php` で、軽量最終回答ルートを通常の重いドラフト生成より先に実行し、二重生成を避ける
  - `chat_advanced.php` で、資料PDFの留意点抽出は `doc_chunks` の生本文・ページ・資料名をそのまま根拠として渡し、抽象的な中間考察で根拠を薄めない
  - `chat.php` の粗い因数分解で、`施工前に確認すべき事項` のような資料系確認質問を `advanced_hybrid.doc_extract` へ寄せる
  - `chat_advanced.php` の軽量最終回答ルートは、固定の「留意点」説明ではなく、元の質問文の依頼形式に従う
  - `chat_advanced.php` で、資料紹介的な断片や図面リストより、質問に近い注意喚起・確認事項の断片を優先して最終整形へ渡す

### 現時点の検証スナップショット

- CSV系
  - 単一CSV日付集計は `CSV-AGG` に安定して入る
  - 全CSV日付集計も `CSV-AGG` に入り、OTP列の誤判定は解消済み
  - 単一CSV要約は `CSV-SUMMARY` に入り、通常RAGへの誤流入は抑えられている
- 資料PDF系 (`advanced_hybrid`)
  - 資料抽出質問は `advanced_hybrid.doc_extract` として粗く因数分解できる
  - シーケンス1 SQL分析スキップ、Plannerスキップ、定番SQL、軽量最終回答ルートは有効
  - 全体時間はおおむね 60〜100 秒台まで短縮できている
  - ただし精度はまだ改善余地がある
    - 資料紹介・概要説明へ寄りやすい
    - 質問ごとの差がまだ弱い
    - 具体的な確認事項・留意点より、一般化した説明が前に出やすい
- 運用判断
  - 現時点では「経路最適化と観測性改善は一段成功」とみなしてよい
  - 次にこの領域へ戻る場合は、速度改善よりも `advanced_hybrid` の回答精度改善を優先する
  - いったん別作業へ移ってよい段階に入っている

### 次の別作業候補

- 報告書生成モードの改善
  - `report_mode` では、最終回答をそのままPDF化するだけでなく、報告書専用の最終整形を一度通す
  - 結論 / 分析対象 / 根拠 / 留意点 / 推奨アクション / 出典 の型を安定させる
  - 一般的な資料紹介より、案件で確認できた事実と要確認事項を優先する
  - `ReportGenerator` 側でも、参照データ概要を報告書の根拠メモとして見やすく残す
- チャットの図・グラフモード改善
  - まずはCSV集計結果の可視化から着手し、棒グラフ・折れ線などを安定出力する
  - PDF系の質問では、図よりも表や要点整理の方が有効なケースを優先する
  - 第1段では、`CSV-AGG` の構造化集計結果から deterministic に `json:chart` を組み立て、LLMに依存せずChart.js描画できる状態を優先する
  - 第2段では、長すぎる系列ラベルを圧縮し、複数CSV・複数日付列が混在する場合は棒グラフへ寄せて比較しやすさを優先する

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
