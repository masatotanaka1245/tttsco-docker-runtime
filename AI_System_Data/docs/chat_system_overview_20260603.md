# チャット受付・回答生成ロジック俯瞰メモ

更新日: 2026-06-03

このメモは、`public/api/chat.php` を入口とするチャット受付から、各ルートの回答生成、評価、保存、図解モード、今後の分割候補までを俯瞰するための現行仕様メモです。

理想仕様ではなく、「いま実装がどう動いているか」を優先して整理しています。

## 0. 直近ログから確定した次着手方針（2026-06-04）

- 第1優先は、`main / sub / embedding` の 3 層でモデル責務を再設計することです。
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
- モデル情報
- プロンプトキー

現状のモデル運用は、概ね次の通りです。

- `chat.php` では `ModelRoleResolver` が
  - `main_model`
  - `sub_model`
  - `embedding_model`
  を解決する
- そのうえで、既存 route 互換のために当面は
  - `reasoning_model = main_model`
  - `synthesis_model = sub_model`
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
- 設定保存時は `Ollama /api/tags` を確認し、`main / sub / embedding` のモデル名が実在しない場合は保存しない
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
- `ChatRouteDispatcher` は route 決定時に `[MODEL-ROLES]` ログを出し、入口段階でも `main / sub / embedding` の実効値を追えるようにした

次段の改修では、この状態を `data_analysis` とモデル存在チェックまで含めて `main / sub / embedding` の責務分担に揃える。

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

## 6. ルーティングの決め方

現在は `src/ChatRouteFactorizer.php` が、質問文を粗く分解して route 候補を返します。

主な route 候補:

- `data_analysis.csv_summary`
- `data_analysis.csv_agg`
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
- CSV証拠読解
- CSV構造化集計
- 大規模CSV概況回答

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

つまり、本審査以外の `synthetic` / `fallback` は FAQ 候補にしません。

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

## 17. 分割の進め方

おすすめ順:

1. まず現行挙動をこのメモで固定
2. `chat.php` の文脈補完と因数分解を切り出す
3. `chat.php` のルート選択判断を切り出す
4. `chat.php` の controller dispatch を切り出す
5. `chat_analysis.php` の軽量CSVルート群を Runner 単位で切り出す
6. `chat_advanced.php` は最後に小さく刻む

最初から大手術するより、挙動を変えずに責務単位で移す方が安全です。
