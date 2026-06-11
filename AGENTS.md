# AGENTS.md

最終更新: 2026-06-08

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
- 案件固有の回答方針や背景メモは、ローカル md に閉じず、必要に応じて `project_meta` の `ai_project_agents_md` / `ai_project_readme_md` / `ai_project_todo_md` へ反映して、アプリ側の回答でも参照できる状態に保つ
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

### 3.3.1 案件運用メモの扱い

- `AGENTS / README / TODO` 相当の案件メモは、回答生成の補助コンテキストとして使う
- 入口の粗い質問因数分解でも、`README / AGENTS / TODO` をまとめて補助参照してよい
- 案件メモは現在、案件状態と直近会話から自動更新される。手動編集内容も次回の会話保存時に上書きされ得る前提で扱う
- 自動更新では、現在スレッドの最近の会話を優先しつつ、案件全体の最近の会話も薄く補助参照してよい
- ただし、資料本文やDB実データより優先して断定の根拠にしてはいけない
- `TODO` は特に、作業中メモや仮説を含み得るため、事実ソースとして引用しない
- 因数分解で使う場合も、明示的な `CSV` / `PDF` / 履歴要約要求より優先して route をねじ曲げない
- 案件メモを追加した場合は、少なくとも以下を確認する
  - `support.php` から保存・再表示できること
  - `chat_debug.log` に `[PROJECT-MEMORY]` が出ること
  - 自動更新時に `[PROJECT-MEMORY-AUTO]` が出ること
  - 対象 route の回答でメモの方針が反映されても、資料根拠やDB実データを上書きしていないこと

### 3.3.2 資料タブと support.js の扱い

- `support.php` の中央 `資料` タブは、案件資料として扱う Markdown ドキュメントのプレビュー画面である
- 資料本文は `.md` ファイルとして保存するだけでなく、`documents` / `doc_chunks` にも同期される前提で扱う
- `save_material.php` / `get_material.php` / `delete_material.php` / `save_material_note.php` を触る変更では、少なくとも以下を確認する
  - 左の資料一覧クリックで中央プレビューだけが切り替わること
  - モーダル保存後に中央プレビューと一覧が即時更新されること
  - 削除後に空状態または次の資料へ自然に切り替わること
  - AI回答からの `資料メモへ追記` が既存資料または新規資料へ保存できること
- `support.js` に機能を足すときは、新機能の初期化失敗で既存 UI 全体を止めないこと
  - 特に、左トグル、中央/右のリサイズバー、既存モーダル、資料一覧クリックは巻き添え停止させない
  - 初期化は局所的に失敗を隔離できる形を優先する
- `support.php` に新しい中央タブやモーダルを足した場合は、仕様差分として `README_01.md` も同じ変更セットで更新する

### 3.4 質問因数分解の扱い

- チャット受信時の質問解釈は、自由文のまま分岐させず、できるだけ構造化して扱う
- ルーティング前の粗い因数分解と、各ルート内の詳細計画は分ける
- 因数分解の目的は以下とする
  - どのルートへ送るべきかを安定化する
  - 単一CSV / 全CSV / PDF / 案件全体の取り違えを減らす
  - 集計 / 要約 / 検索 / 比較の意図混線を減らす
  - 改善フェーズでの判定修正を、場当たりの正規表現追加だけにしない
- 案件運用メモを読む場合は、曖昧な相談系質問の normal / advanced 判定を補助する用途に留める
- 案件名変更、表現調整、動作確認の打ち出し方の相談は、`normal_rag.project_memory_consultation` のような軽い相談 route を優先し、無関係なPDF要約へ流さない

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

### 3.6 直近ログから得た教訓（2026-06-04 追加）

- 背景
  - `「年月」カラムのユニークな値27件の各レコード数を集計` のような質問で、`distinct_count` が選ばれ、`value_distribution` へ行かなかった。
  - `2025年3月の総件数` のような質問で、裸の自然文値が `target_value` として取れず、`exact_value_count` ではなく全体分布を返した。
  - `diagram_mode=on` でも、軽量CSV概要 (`CSV-SUMMARY`) は route によって chart を返したり返さなかったりする揺れがあった。
- やってはいけないこと
  - `ユニーク` という単語だけを見て、`各値の件数` や `全ての値の件数` を distinct count に倒すこと
  - 特定月や特定カテゴリの値を、引用符で囲まれていないからという理由だけで `target_value` として拾わないこと
  - 図解モードの有無を、集計結果の表現差として無視したまま運用判断すること
  - `登録済みCSVの概要` のような広域要約を、`recent_history` だけで `CSV-AGG` に誤進入させること
- 推奨対応
  - `ユニーク件数` と `各値の件数分布` を planner で明示的に分ける
  - `2025年3月` のような裸の自然文値も exact count 候補として抽出する
  - `diagram_mode=on` の軽量CSV概要は deterministic に `json:chart` を返し、route ごとの差を残さない
  - `登録済みCSVの概要` のような広域要約では、`recent_history` だけで `CSV-AGG` へ誤進入させない
  - `これまでの会話内容を要約` のような履歴要約は、`report_mode` より intent を優先して `history_summary` に寄せる

### 3.7 モデル責務の教訓（2026-06-04）

- 背景
  - ヘッダーでは `メイン使用モデル`、`サブモデル(中間処理用)`、`SQLモデル`、`Embeddingモデル` を設定できるようになり、route 側も `main / sub / sql / embedding` の責務へ寄せ始めている。
  - ただし本番ログでの最終確認はまだこれからで、`data_analysis` は第一段の責務分担まで実装済みという状態である。
- やってはいけないこと
  - `main` / `sub` / `embedding` の責務を決めないまま、場当たり的に route ごとの使用モデルを増やすこと
  - ヘッダーの説明文と実際の使用箇所がズレたまま運用すること
  - モデル名の保存可否だけを実装し、どの工程でどのモデルが責任を持つかを md に残さないこと
- 推奨対応
  - `main` は因数分解・最終統合・最終リライトを担うモデルとして定義する
  - `sub` は SQL生成、補助分析、分類、画像処理などの中間処理を担うモデルとして定義する
  - `embedding` はベクトル化専用として独立させ、少なくとも設定可能性を検討する
  - `reasoning_model` / `synthesis_model` の互換別名を残す場合でも、route 内の実責務は `main = 因数分解・最終統合`, `sub = 中間処理` に寄せて読めるようにする
  - `data_analysis` では、deterministic な SQL 集計まで無理に `sub` へ寄せず、AIを使う補助工程だけを `sub` に分ける
  - 実装前に `README_01.md` と俯瞰メモへ責務分担を書き、`TODO.md` の進行中を 1 件に絞ってから着手する
  - 既定値や session からの実効値解決は、route ごとにバラバラに書かず `ModelRoleResolver` に寄せる
  - DB列の追加を伴う設定項目は、先に UI と保存APIへ入れる場合でも、`UserSettingsSchema` のような存在確認で本番互換を壊さない
  - モデル名の保存時は `Ollama /api/tags` などで存在確認を行い、typo や未取得モデルをセッションやDBへそのまま保存しない
  - `mxbai-embed-large` と `mxbai-embed-large:latest` のような tag 差は、保存時に Ollama の実在モデル名へ正規化して吸収する
  - 本番検証では、ログだけでなく `result.model_roles` も見て、`main_model` / `sub_model` / `sql_model` / `embedding_model` / `applied_role` が設計どおり返っているかを確認する
  - `sub_model` と `sql_model` は「その route で使われたかどうか」ではなく configured value を返すことがあるため、実際の出荷役は `applied_role` や route 内ログで判断する
  - `history_summary` や `normal_rag` のような軽量 route でも、`result.model_roles` の shape は他 route と揃える

### 3.8 軽量ルート最終回答の教訓（2026-06-04）

- 背景
  - `CSV-SUMMARY` や `history_summary` のような単発・軽量ルートでは、回答生成後に `evaluation_mode=none` のまま出荷される経路があった。
  - `advanced_hybrid.doc_extract` の軽量最終回答では、根拠断片があるのに一般論へ流れるケースが出た。
- やってはいけないこと
  - 軽量ルートだからという理由で、最終回答の質問適合性チェックを完全に省略すること
  - 軽量回答の最終審査で、毎回 LLM judge と text-only rewrite を走らせて軽量ルートを重くすること
  - 資料根拠がある質問で、根拠にない法規名や一般論を最終回答へ混ぜること
  - `json:chart` や Mermaid の deterministic 出力を、最終審査の rewrite で別形式の JSON や別件数へ壊すこと
- 推奨対応
  - 軽量ルートの最終回答ガードは rule-first にし、低リスク経路では LLM judge を常時起動しない
  - `json:chart` や Mermaid を含む回答は rewrite 禁止とし、deterministic な payload をそのまま保存・出荷する
  - ログには `[FINAL-GUARD]` を残しつつ、rewrite は本当に必要な高リスク時だけに絞る
  - `doc_chunks` 根拠を使う軽量最終回答では、資料名・ページ・本文抜粋に寄せた決定論寄りの整形を優先し、一般論を足さない
  - Gemma 系モデルの Ollama 呼び出しでは、`[OLLAMA-PAYLOAD]` / `[OLLAMA-THINK]` ログで `<|think|>` 付与有無と思考トレース有無を確認できるようにする

### 3.9 主要フロー見直しの教訓（2026-06-05）

- 背景
  - 内部の責務分離や helper 化はかなり進んだが、実際に使った感覚では「よくなった実感」が弱いというフィードバックが出た。
  - 直近ログでは、CSV 集計の follow-up で CSV 名と列名は補完できても、直前の `aggregation_mode=value_distribution` や `若い順` / `グラフ化` の意図が継続されず、広い `CSV-OVERVIEW` へ落ちるケースが見えた。
  - `これまでの会話内容を簡潔にまとめて報告書を作成` のような依頼でも、`report_mode=on` に反して `history_summary` が優先され、報告書化へ進まないケースが見えた。
- やってはいけないこと
  - helper 分割や局所修正だけを積み重ね、主要ユーザーフローごとの期待着地を決めないまま route を増やすこと
  - CSV follow-up で `target_file_name` と `target_column` だけ補完して満足し、`aggregation_mode` や `sort_order` の継続を考えないこと
  - `履歴要約` と `報告書化` が共存する依頼で、`history_summary` を機械的に最優先し、報告書化 intent を潰すこと
- 推奨対応
  - 実装修正の前に、主要フローごとに「期待 route」「引き継ぐ文脈」「mode の効き方」を md に固定する
  - CSV 集計の follow-up では、`target_file_name` / `target_column` に加えて `aggregation_mode` / `sort_order` / `output_format` / `diagram_mode` を引き継ぐ
  - `これまでの会話内容をまとめて報告書化` のような依頼では、`history_summary` より報告書 intent を優先する
  - 実運用では、履歴要約質問に `report_mode=on` が付いている時点で、文面に `報告書` がなくても報告書化フローを優先してよい
  - `diagram_mode=on` は route を変えるより、軽量 route のまま deterministic な chart を足す方向で扱う

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
  - `chat_advanced.php` での SQL サブクエリ実行責務を `AdvancedSqlSubQueryRunner` へ移動
  - `chat_advanced.php` での fast path 群を `AdvancedFastPathResolver` へ移動
  - `chat_advanced.php` での最終 guard・保存・送信を `AdvancedRouteCompletionCoordinator` へ移動
  - `chat_advanced.php` での reasoning step 保存を `AdvancedReasoningStepRecorder` へ移動
  - `chat_advanced.php` での CSV 全件 Map-Reduce を `AdvancedBulkCsvMapReducer` へ移動
  - `chat_advanced.php` での最終ドラフト生成を `AdvancedFinalDraftGenerator` へ移動
- 判断メモ:
  - `chat_analysis.php` は無害整理フェーズの到達点にかなり近い
  - `chat_advanced.php` はオーケストレーション中心へかなり整理できた
  - 以降は `chat_advanced.php` に残る薄い helper と、`chat_analysis.php` との共通化候補棚卸しを優先する
- この段階では動作を変えず、ログの補強だけを必要最小限で行う

### `chat_analysis.php` と `chat_advanced.php` の共通化候補メモ

- 先に共通化を検討してよいもの
  - prompt budget の記録形式
  - logger / status emitter / normalizeUtf8 の callback 束
  - SQL 失敗時の短い fallback 文面
  - malformed SQL の事前判定
  - scoped schema の組み立て
  - lightweight final guard の適用入口
- 無理に共通化しない方がよいもの
  - `chat_analysis.php` の aggregation_mode ごとの deterministic runner
  - `chat_advanced.php` の資料PDF向け lightweight final
  - `chat_advanced.php` の CSV 全件 Map-Reduce
- 次に着手するなら、薄い共通 helper として切り出しやすい順
  1. prompt budget / logger callback 束
  2. malformed SQL + scoped schema
  3. lightweight final guard の適用ラッパー
  4. SQL 失敗 fallback 文面の整形

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
