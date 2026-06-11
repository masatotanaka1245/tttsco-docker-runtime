# チャット受付・回答生成ロジック俯瞰メモ

更新日: 2026-06-08

このメモは、`public/api/chat.php` を入口とするチャット受付から、各ルートの回答生成、評価、保存、図解モード、今後の分割候補までを俯瞰するための現行仕様メモです。

理想仕様ではなく、「いま実装がどう動いているか」を優先して整理しています。

## 0. 直近ログから確定した次着手方針

### 2026-06-05

- 第1優先は、主要ユーザーフローごとの期待動作を先に固定することです。
- 背景として、内部の責務分離やモデル責務の整理はかなり進んだ一方、実際の使用感では以下のズレが残っています。
  - CSV 集計の follow-up で、CSV 名と列名は補完できても、直前の `value_distribution` や `若い順` / `古い順` / `グラフ化` の意図が継続されず、広い `CSV-OVERVIEW` へ落ちることがある
  - `report_mode=on` かつ `これまでの会話内容をまとめて報告書を作成` のような依頼でも、`history_summary` が優先され、報告書化へ進まないことがある
- そのため、次の修正では局所的な if 文追加より先に、主要フローごとに
  - どの route が正解か
  - どの文脈を引き継ぐべきか
  - `report_mode` / `diagram_mode` が route を変えるのか、出力だけを変えるのか
  を固定してから実装へ戻る方針を取る

### 2026-06-04

- 第1優先は、`main / sub / sql / embedding` の 4 層でモデル責務を再設計することです。
- 目標は以下の整理です。
  - `main`: 因数分解、最終統合、最終リライト
  - `sub`: SQL生成、補助分析、分類、画像処理などの中間処理
  - `embedding`: ベクトル化専用
- 先に md 上で責務を固定し、そのあとヘッダー設定、セッション値、各 route の受け渡し、`EmbeddingEngine` の設定可否を順に実装します。
- 第2優先は、CSV の月別・年月系自然文を軽量 `data_analysis.csv_agg` に安定して寄せる改善束の検証です。
- 第3優先は、全社横断ブリーフィング系質問で、前段ログの表現と最終 route の整合性を高めることです。

## 1. 全体像

チャット系の主要ルートは以下の4本です。

- `normal_rag`
  - 通常の資料検索と回答
  - 主担当: `public/api/chat_normal.php`
- `data_analysis`
  - CSV要約、CSV証拠読解、CSV構造化集計
  - 主担当: `public/api/chat_analysis.php`
- `advanced_hybrid`
  - フル思考、多段階推論、資料抽出、重い統合処理
  - 主担当: `public/api/chat_advanced.php`
- `global_cross` / `global_no_project`
  - admin 向け全社横断調査
  - 主担当: `public/api/chat_global.php`

入口は共通して `public/api/chat.php` です。

## 2. 入口の処理順

`chat.php` では概ね以下の順で処理します。

1. 入力受理
2. `INPUT-MODE` / `OUTPUT-MODE` ログ出力
3. `ChatRequestGuard` による入力ガード
4. 重複リクエストガード
5. 会話履歴読み込み
6. 粗い質問因数分解
7. ルーティング上書き
8. 最終ルート決定
9. ルート別コントローラーへ処理移譲

受け取る主な入力:

- `project_id`
- `message`
- `advanced_reasoning`
- `report_mode`
- `diagram_mode`
- `csv_mode`
- モデル情報
- プロンプトキー

現状のモデル運用は、概ね次の通りです。

- `chat.php` では `ModelRoleResolver` が
  - `main_model`
  - `sub_model`
  - `sql_model`
  - `embedding_model`
  を解決する
- そのうえで、既存 route 互換のために当面は
  - `reasoning_model = main_model`
  - `synthesis_model = sub_model`
  - `sql_generation_model = sql_model`
  のエイリアスを維持している
- `normal_rag` は main only
- `data_analysis` は deterministic な集計を main 側の PHP/SQL で維持しつつ、AI を使う補助処理から段階的に `sub` へ寄せ始めている
- `advanced_hybrid` は route 内で
  - `main`: 最初の因数分解、最終統合、最終リライト、品質評価
  - `sub`: SQL生成、補助分析、追加抽出キーワード生成などの中間処理
  へ寄せ始めている
- `global_cross / global_no_project` も route 内で
  - `main`: 最終レポート生成
  - `sub`: ReAct ループと中間調査
  へ寄せ始めている
- embedding は設定画面に入力欄を追加し始めており、チャット入口・通常RAG・アップロード系では resolver 経由の設定へ寄せ始めている
- 設定保存時は `Ollama /api/tags` を確認し、`main / sub / sql / embedding` のモデル名が実在しない場合は保存しない
- `data_analysis` は次段で以下のように分ける
  - `main`: 因数分解、最終統合、品質評価、最終リライト
  - `sub`: SQL生成と再生成、CSV証拠読解のバッチ分析、semantic 系の補助推定
  - deterministic な `CSV-AGG` は PHP / SQL 主体のまま維持する
- 2026-06-04 の第一段として、`data_analysis` はすでに
  - CSV証拠読解
  - semantic 系 runner
  - Text-to-SQL 生成と再生成
  から `sub` を使う形へ寄せ始めている
- 2026-06-04 の追加調整として、`CsvEvidenceReader` は
  - `sub`: バッチ読解
  - `main`: 保存済み読解結果の最終統合
  に分離した
- `CsvSemanticAggregationRunner` は semantic/category 系の補助推定を `sub` で実行し、プリフライトと実行ログにも補助推定モデルを出す
- `history_summary` / `normal_rag` / `advanced_hybrid` / `global` / `data_analysis` の `result` payload には `model_roles` を含め、最終回答が `main` で着地したことを後から確認できるようにした
- `ChatRouteDispatcher` は route 決定時に `[MODEL-ROLES]` ログを出し、入口段階でも `main / sub / sql / embedding` の実効値を追えるようにした

次段の改修では、この状態を `data_analysis` とモデル存在チェックまで含めて `main / sub / sql / embedding` の責務分担に揃える。

## 3. 入力ガード

`src/ChatRequestGuard.php` が、重い推論へ入る前に軽量確認応答へ切り替える責務を持ちます。

代表例:

- 空入力
- 挨拶のみ
- 誤送信に近い短文
- 報告書モードとして不十分な指示

この段階で止まる場合、下流の RAG / SQL / PDF 生成には進みません。

## 4. 会話履歴の使い方

`chat.php` は `chat_history` から直近8件を取得し、`history_summary_text` として下流へ渡します。

使い道は2系統あります。

- 回答生成時の会話文脈
- CSV追い質問の文脈補完

現在の文脈補完は、主に以下に限定されています。

- CSVファイル名
- CSV列名

例:

- 先の質問: `集計.csv の event_type を表にしてください`
- 次の質問: `どういうイベントか説明できますか`

この場合、直前会話から `集計.csv` と `event_type` を補完して、軽量 `data_analysis.csv_agg` に戻します。

## 5. 回答モード

### `advanced_reasoning`

- ユーザーがフル思考を明示した場合のフラグ
- ただし CSV 要約・CSV 集計では、軽量 `data_analysis` を優先する調整が入っています

### `report_mode`

- 最優先でフル思考寄せ
- 報告書向け構成
- 回答後に PDF 化
- `documents` / `doc_chunks` に登録

### `diagram_mode`

- 図表出力を許可
- 軽量 CSV 集計では PHP 側で deterministic に `json:chart` を組み立てる
- 通常 RAG / フル思考では、必要時のみ Mermaid または Chart.js 用ブロックを付与

### `csv_mode`

- 回答本文の Markdown 表を案件CSVとして保存したいときのフラグ
- `csv_mode=on` の場合、回答保存後に `CsvExportGenerator` が表を抽出し、`project_csv_files` / `project_csv_rows` へ登録する
- `csv化してください` や `一つのcsvファイルにしてください` のような依頼は、重い `CSV-EVIDENCE` より `data_analysis.csv_export_request` を優先する方針で整備を進めている

## 6. ルーティングの決め方

現在は `src/ChatRouteFactorizer.php` が、質問文を粗く分解して route 候補を返します。

主な route 候補:

- `data_analysis.csv_summary`
- `data_analysis.csv_agg`
- `data_analysis.csv_export_request`
- `advanced_hybrid.doc_extract`

その後、現在は `src/ChatRouteSelector.php` が以下を見て最終候補を決めます。

- `advanced_reasoning`
- `report_mode`
- `diagram_mode`
- 全社横断キーワード
- CSVファイル名の明示言及
- 履歴要約要求
- 通常RAG優先パターン

補足:

- 直前の CSV 会話文脈補完は `src/ChatHistoryContextResolver.php` に切り出し済み
- route 候補の因数分解は `src/ChatRouteFactorizer.php` に切り出し済み
- ルート選択の判断本体は `src/ChatRouteSelector.php` に切り出し済み
- controller の dispatch 本体は `src/ChatRouteDispatcher.php` に切り出し済み

### 現在の優先の考え方

- `report_mode=on` はフル思考優先
- CSV要約 / CSV集計は `advanced_reasoning=on` でも軽量優先
- `advanced_hybrid.doc_extract` は CSV ルートで上書きしない
- 全社横断キーワードは最上位で `global` 系へ

### 6.1 主要ユーザーフローと期待着地（2026-06-05 固定方針）

以下は、今後の route 修正で基準にする期待動作です。現状の実装がそうなっているとは限らず、ずれている箇所は今後の改修対象として扱います。

| フロー | 代表質問 | 期待 route | 引き継ぐ文脈 | mode の扱い | 現状の主なズレ |
| --- | --- | --- | --- | --- | --- |
| CSV 概要把握 | `登録済みのCSVデータを集計して概要を教えてください。` | `data_analysis.csv_summary` | 案件内 CSV 一覧 | `diagram_mode=on` でも軽量 route は維持し、必要なら deterministic な `json:chart` を足す | 大きなズレは少ない |
| CSV 集計 | `YearMonth カラムの分布を集計してグラフ化してください。` | `data_analysis.csv_agg` | `target_file_name`, `target_column`, `aggregation_mode=value_distribution` | `diagram_mode=on` は route を変えず、出力へ chart を加える | 概ね安定している |
| CSV follow-up | `若い順でグラフ化してください。` | 直前の `data_analysis.csv_agg` を継続 | 現在スレッド内の CSV 名、列名、直前の `aggregation_mode`, `sort_order`, 図表意図 | `diagram_mode` が未指定でも、直前がグラフ化なら chart を継続候補として扱う | 直前のグラフ意図継承を強化中 |
| 履歴要約 | `これまでの会話内容を簡潔にまとめてください。` | `history_summary` | 現在スレッド内の `chat_history` | `report_mode=off` なら軽量最優先 | 概ね安定している |
| 履歴の報告書化 | `これまでの会話内容を簡潔にまとめて報告書を作成してください。` または `report_mode=on` 付きの履歴要約 | `advanced_hybrid` もしくは報告書専用フロー | 現在スレッド内の `chat_history`, 報告書モード, 章立て意図 | `report_mode=on` は履歴要約 intent より強く扱い、報告書化へ進める | ここを優先する方針へ修正中 |
| PDF 留意点抽出 | `この案件に関連する資料PDFから主要な留意点を抽出してください。` | `advanced_hybrid.doc_extract` | project documents, `doc_chunks`, 軽量根拠整形 | `report_mode=off` なら lightweight doc final を許可 | route は安定、候補ノイズは残課題 |

### 6.2 route 優先順位の再確認ポイント

- `report_mode=on` は常に「重い route へ送る」ではなく、「報告書として保存・出荷したい依頼」で route を押し上げる
- ただし履歴要約については、UI で `report_mode=on` が付いている時点で、文面に `報告書` がなくても `history_summary` より報告書化を優先してよい
- CSV follow-up では、`target_file_name` と `target_column` だけでなく、直前の `aggregation_mode`, `sort_order`, `output_format`, `diagram_mode`, `wants_chart` まで引き継ぐ
- スレッド導入後の follow-up と履歴要約は、案件全体ではなく現在の会話スレッドを優先して文脈継続する
- `diagram_mode=on` は原則として route を変えず、軽量 route のまま deterministic な chart を足す方向で扱う
- `advanced_reasoning=on` でも、CSV 要約・CSV 集計・履歴要約の軽量 route は維持してよい

## 7. `normal_rag`

担当: `public/api/chat_normal.php`

役割:

- 通常の資料検索
- 回答生成
- 必要に応じた品質評価

現在の表示仕様:

- 内部では回答ドラフトを生成する
- ただし本文 `chunk` はブラウザへ出さない
- ブラウザへ見せる本文は最終 `result` のみ

## 8. `data_analysis`

担当: `public/api/chat_analysis.php`

### 8.1 主要ルート

- CSV即答サマリー
- CSV export request
- CSV証拠読解
- CSV構造化集計
- 大規模CSV概況回答

`csv化してください` や `一つのcsvファイルにしてください` のような依頼は、まず `data_analysis.csv_export_request` を候補とし、重い `CSV-EVIDENCE` より lightweight な表生成と CSV 登録を優先する。

### 8.2 `CSV-AGG` の現状

対応している主な集計:

- `date_histogram`
- `distinct_count`
- `exact_value_count`
- `value_distribution`
- `semantic_category_summary`
- `category_filtered_distribution`
- `column_semantics`

直近ログから見えた弱点:

- `csvのデータを月別で集計してください。` のように CSV 名や列名が省略された自然文は、まだ `advanced_hybrid` に落ちることがある
- `Datetime` / `年月` を月単位で見たい質問でも、`value_distribution` に誤って倒れることがある
- `2026年4月を抽出して件数` のような質問に、特定値件数ではなく上位分布一覧を返すことがある
- 集計系の follow-up は、説明系に比べて文脈補完がまだ弱い

現在の分割状況:

- 日付系集計の実行本体は `src/CsvDateAggregationRunner.php` に切り出し済み
- `distinct_count` / `value_distribution` は `src/CsvValueAggregationRunner.php` に切り出し済み
- 日付列候補の検出と対象CSV解決は `src/CsvAggregationTargetResolver.php` に寄せ始めている
- `column_semantics` / `semantic_category_summary` / `category_filtered_distribution` は `src/CsvSemanticAggregationRunner.php` に切り出し済み
- `chat_analysis.php` 側には、各 `aggregation_mode` を `switch` で各 runner へ振り分ける薄い分岐が残っている
- runner 生成時に使う SSE / 完了処理 / reasoning step / logger などのコールバックも、`chat_analysis.php` 内で共通メソッド化済み

### 8.3 スコープ制御

単一CSV・単一列の集計では、`project_csv_rows` を `csv_file_id` で絞る方針に寄せています。

これにより、案件内に複数CSVがある場合でも混線を避けます。

### 8.4 大規模CSV概況

広い質問かつ大規模CSVでは、全件AI読解を避けて以下を返します。

- ファイル一覧
- 項目一覧
- 代表サンプル
- 次の読解方針

`diagram_mode=on` の場合は、上位CSVのレコード件数を `json:chart` で返せます。

## 9. `advanced_hybrid`

担当: `public/api/chat_advanced.php`

役割:

- フル思考
- SQL分析と資料RAGの統合
- 資料中心質問の抽出
- 門番評価と差し戻し

### 9.1 `advanced_hybrid.doc_extract`

資料中心の質問はここへ寄せます。

`doc-only semantic_extract` の場合は、以下を省略します。

- シーケンス1のSQL分析
- Planner

そのまま資料RAGへ寄せる軽量化が入っています。

### 9.2 軽量最終回答ルート

資料PDF抽出では、本審査を省略する軽量最終回答ルートがあります。

この場合の評価は本審査ではなく:

- `evaluation_mode=synthetic`

として保存されます。

## 10. 品質評価

関連:

- `src/ChatEvaluator.php`
- `src/ChatEvaluationPolicy.php`
- `src/FaqAutoRegistrar.php`

### 10.1 評価モード

- `real`
  - 本審査
- `synthetic`
  - 軽量ルートの擬似評価
- `fallback`
  - 審査失敗時のフェイルセーフ

### 10.2 FAQ自動登録

FAQ 自動登録は、現在以下のみ候補にします。

- `evaluation_mode=real`
- `evaluation_source=lightweight_rule_guard` の高スコア軽量回答

つまり、`fallback` は FAQ 候補にせず、軽量系は rule-first の決定論ガードを通ったものだけを候補にします。

## 11. 回答表示タイミング

現在は全ルートで、本文をブラウザに見せるのは最終 `result` のみです。

内部では回答ドラフト生成は行いますが、途中本文 `chunk` は UI に出しません。

見えるもの:

- `status`
- 最終 `result`
- `error`

## 12. 図解・グラフモードの現状

### 12.1 出力形式

- Chart.js: ````json:chart`
- Mermaid: ````mermaid`

### 12.2 軽量CSV集計

軽量CSV集計は、PHP 側で deterministic に chart JSON を組み立てます。

### 12.3 フロント描画

`public/assets/js/modules/chat.js` が以下を担当します。

- Markdown 描画
- `json:chart` の Chart.js 描画
- Mermaid 描画
- 過去ログの再初期化
- グラフ拡大モーダル

現在、過去ログ初期化では `language-json:chart` / `language-json:chart_data` のみをグラフとして扱うよう調整済みです。

## 13. 参照ソースの強さ

回答生成の参照対象には以下が入っています。

- PDF (`documents`, `doc_chunks`)
- CSV (`project_csv_files`, `project_csv_rows`)
- コメント (`project_comments`)
- FAQ (`project_faqs`)
- 会話履歴 (`chat_history`)

ただし現状の強さには差があります。

- PDF / CSV: 強い
- FAQ: 一部質問で明示的に使われやすい
- コメント: 主に補助的

コメント・FAQ系の質問語を明示優先ルーティングする改善は、将来候補です。

## 14. ログ

主なログ:

- `[INPUT-MODE]`
- `[OUTPUT-MODE]`
- `[INPUT-GUARD]`
- `[SMART-ROUTER]`
- `[CSV-AGG]`
- `[CSV-AGG-SQL]`
- `[CSV-OVERVIEW]`
- `[SQL-REPAIR-POLICY]`
- `[EVAL-POLICY]`
- `[EVAL-REAL]`
- `[EVAL-SYNTHETIC]`
- `[EVAL-FALLBACK]`
- `[FINAL-ANSWER]`
- `[FAQ-AUTO]`

このあたりを見れば、

- モード入力
- ルーティング
- 軽量集計
- SQL修復
- 評価種別
- 最終回答
- FAQ自動登録

まで追えます。

## 15. 現時点で責務が重なっている箇所

### `chat.php`

- 入力受理
- 入力ガード
- 会話履歴取得
- 例外処理

### `chat_analysis.php`

- CSV即答
- CSV証拠読解
- CSV集計
- 保存
- 評価
- 一部の報告書寄り処理

### `chat_advanced.php`

- 資料抽出
- SQL分析
- 統合
- 品質評価
- 差し戻しループ
- 報告書寄り制御

補足:

- 2026-06-05 時点では、上の粒度だけでは分割順を決めにくいため、`chat_advanced.php` の内部責務をもう一段細かく棚卸しする
- 観測ログを崩さないため、関数の並びではなく「入出力の束」で分ける

## 16. 将来のファイル分割候補

### `chat.php`

- `ChatRouteFactorizer`
- `ChatRouteSelector`
- `ChatRouteDispatcher`
- `ChatHistoryContextResolver`
- `CsvFollowupContextResolver`
- `ChatModePolicy`

### `chat_analysis.php`

- `CsvStructuredAggregationRunner`
- `CsvEvidenceRouteRunner` （CSV証拠読解本体）は切り出し済み
- `CsvOverviewRouteRunner`
- `CsvSemanticsAnalyzer`
- `CsvQuickResponseRunner` （小規模即答サマリー / 大規模概況）は切り出し済み
- `CsvMetadataResponseRunner` （項目一覧 / no-hit フォールバック）は切り出し済み
- `CsvDateAggregationRunner` （日付ヒストグラム系）は切り出し済み
- `CsvValueAggregationRunner` （distinct count / value distribution）は切り出し済み
- `CsvSemanticAggregationRunner` （semantic系3種）は切り出し済み
- `CsvAggregationTargetResolver` （対象CSV解決 / 日付列候補判定）は shared helper として追加済み

### `chat_advanced.php`

- `AdvancedRoutePlanner`
- `AdvancedRouteMerger`
- `AdvancedRouteCriticLoop`
- `AdvancedRouteReportFinalizer`

#### 2026-06-05 時点の責務棚卸し

1. ルート全体の進行管理
   - `execute`
   - `completeAdvancedRoute`
   - `elapsedSeconds`
   - 役割: フェーズ接続、タイミング計測、ルート全体の進行制御

2. 質問因数分解とサブクエリ補正
   - `decomposeQuestion`
   - `generateAdditionalSubQuery`
   - `normalizeSubQueries`
   - `isDocExtractionQuery`
   - `shouldForceSemanticDocExtract`
   - `forceSemanticDocExtractSubQueries`
   - `isDocOnlySemanticExtractQuery`
   - `shouldSkipSqlSequenceForDocOnlySubQueries`
   - 役割: 最初の質問を後続フロー向けの `subQueries` へ正規化する

3. SQL分析シーケンス
   - `processSubQueries`
   - `executeSingleSubQuery`
   - `executeAndAnalyzeSql`
   - `buildSqlRepairGuidance`
   - `isLikelySqlAuditFailure`
   - 役割: Text-to-SQL の生成、監査、自己修復、要約

4. 実行計画と資料巡回
   - `generateExecutionPlan`
   - `executePlanSteps`
   - `buildPresetDocPlan`
   - `shouldUsePresetDocPlan`
   - `buildPresetDocSql`
   - `shouldUsePresetDocSql`
   - `extractPdfHintFromQuestion`
   - 役割: Planner と定番プランを切り替えつつ `doc_chunks` / `documents` を巡回する

5. PDF 向け軽量最終回答
   - `shouldUseLightweightDocFinalAnswerRoute`
   - `buildLightweightDocFinalAnswer`
   - `buildDeterministicDocLightweightAnswer`
   - `buildDocLightweightEvidencePacket`
   - `extractDocEvidenceBlocks`
   - `scoreDocEvidenceBlock`
   - `selectBestDocEvidenceBlocks`
   - `isWeakDocEvidenceText`
   - `isVeryWeakDocEvidenceText`
   - `normalizeDocEvidenceText`
   - `summarizeDocEvidenceText`
   - `buildDocChunkEvidenceSummary`
   - 役割: 資料PDF抽出を deterministic に整形し、`DOC-CANDIDATES` 観測込みで出荷する

6. 統合・推敲・追加探索
   - `buildEvidenceDraft`
   - `mergeAndRefineReport`
   - `generateAdditionalChunkQuery`
   - `applyReportModeFinalPolish`
   - 役割: advanced_hybrid の重い統合ループを担当する
   - 2026-06-05 時点で、周辺のドラフト組み立てと報告書整形は `AdvancedDraftComposer`、品質評価ループ本体は `AdvancedCriticLoop` へ寄せ始めている

7. 保存・出荷・権限
   - `saveHistoryAndEvaluations`
   - `createReportDocumentIfRequested`
   - `checkAuthority`
   - `sendFinalResult`
   - `logFinalResponseSnapshot`
   - 役割: 履歴保存、評価同期、レポート出力、最終レスポンス送出
   - 2026-06-05 時点で、履歴保存・PDF登録・最終出荷は `AdvancedRouteFinalizer` へ寄せ始めている

8. 共有ユーティリティ
   - `composeMemoryAwarePrompt`
   - `buildModelRolesPayload`
   - `normalizeUtf8`
   - `loadCsvSchemas`
   - `buildLogger`
   - 役割: 各責務群から使う補助処理を薄く提供する

## 17. 分割の進め方

おすすめ順:

1. まず現行挙動をこのメモで固定
2. `chat.php` の文脈補完と因数分解を切り出す
3. `chat.php` のルート選択判断を切り出す
4. `chat.php` の controller dispatch を切り出す
5. `chat_analysis.php` の軽量CSVルート群を Runner 単位で切り出す
6. `chat_advanced.php` は最後に小さく刻む

最初から大手術するより、挙動を変えずに責務単位で移す方が安全です。

### `chat_advanced.php` の推奨分割順

`chat_advanced.php` は最後に回す前提を維持しつつ、着手するなら次の順が安全です。

1. `AdvancedDocAnswerBuilder`
   - 対象: PDF 向け軽量最終回答の関数群
   - 理由: `DOC-CANDIDATES` で観測しやすく、他フェーズからの依存が比較的薄い

2. `AdvancedSubQueryNormalizer`
   - 対象: 因数分解後の正規化、`semantic_extract` 補正、doc-only 判定
   - 理由: route 前半の分岐ルールを局所化できる

3. `AdvancedRoutePlanner`
   - 対象: Planner、preset plan
   - 理由: advanced_hybrid の中盤入口を独立観測しやすくなる

4. `AdvancedPlanExecutor`
   - 対象: preset SQL、RAG/SQL ステップ巡回、資料巡回ステップ保存
   - 理由: 実行フェーズの副作用と SQL 実行をまとめて外へ寄せられる

5. `AdvancedDraftComposer`
   - 対象: `buildEvidenceDraft`、`generateAdditionalChunkQuery`、`applyReportModeFinalPolish`
   - 理由: 統合ループの周辺依存を先に薄くしてから本体へ着手できる

6. `AdvancedCriticLoop`
   - 対象: ChatEvaluator ループ、text-only rewrite、doc_chunks 再探索、最終リライト
   - 理由: `mergeAndRefineReport()` の最も重い判断塊を独立観測しやすくなる

7. `AdvancedRouteFinalizer`
   - 対象: 保存・評価・レポート・出荷
   - 理由: route 共通 helper 化の候補にもなりやすい

8. `AdvancedRouteMerger`
   - 対象: 統合・推敲・追加探索
   - 理由: 他の束を外した後の方が依存の向きが見えやすい

### `chat_analysis.php` / `chat_advanced.php` の次の共通化候補

- 共通 helper 化しやすいもの
  - prompt budget の記録
  - logger / status emitter / UTF-8 正規化 callback の束
  - malformed SQL の事前判定
  - scoped schema の組み立て
  - lightweight final guard の適用入口
- route 固有として残すもの
  - `chat_analysis.php` の deterministic aggregation runner 群
  - `chat_advanced.php` の資料PDF向け lightweight final
  - `chat_advanced.php` の CSV 全件 Map-Reduce
