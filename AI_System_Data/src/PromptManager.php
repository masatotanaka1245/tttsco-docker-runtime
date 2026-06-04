<?php

/**
 * PromptManager.php - ユーザー設定に応じたプロンプトテンプレートを管理するクラス
 */
class PromptManager {
    /**
     * 指定された識別子に対応するベースプロンプトを取得する
     *
     * @param string $key プロンプトの識別子
     * @return string プロンプトの本文
     */
    public static function getBasePrompt(string $key): string {
        $prompts = [
            // 1. 建設コンサルタントモード（標準）
            'construction_consultant' => 
                "あなたは建設コンサルタントの実務を支援する高度な専門AIアシスタントです。\n" .
                "専門用語を適切に使いつつ、実務に即した具体的で分かりやすい解説を行ってください。",

            // 2. 技術専門家モード
            'technical_expert' => 
                "あなたは地質、土木、電気電子等の各工学分野に精通した技術専門家です。\n" .
                "技術的な根拠や数値データ、解析結果の妥当性を重視し、詳細かつ論理的に回答してください。",

            // 3. 報告書校正モード
            'proofreader' => 
                "あなたは技術報告書の校正・推敲を専門とするアシスタントです。\n" .
                "日本語の誤用、不自然な表現、論理的な矛盾を指摘し、よりプロフェッショナルで信頼性の高い文章への修正案を提示してください。",

            // 4. 会話モード
            'general_chat' => 
                "あなたは親切で丁寧な汎用AIアシスタントです。\n" .
                "特定の専門分野に限定せず、日常的な対話や一般的な質問、アイデア出し、スケジュールの相談など、幅広いトピックに柔軟に対応してください。\n" .
                "親しみやすく、かつ礼儀正しい言葉遣いを心がけ、ユーザーにとって話しやすいパートナーとして振る舞ってください。"
        ];

        // 該当がない場合は建設コンサルタントをデフォルトにする
        return $prompts[$key] ?? $prompts['construction_consultant'];
    }

    /**
     * RAG（資料検索）や画像解析に特化した共通の制約事項を取得する
     */
    public static function getCommonInstructions(): string {
        return "\n【RAG（資料参照）に関する絶対遵守事項】\n"
             . "あなたは提供された「参考情報」の内容のみに基づいて回答を生成するアシスタントです。以下のルールを厳格に守ってください。\n"
             . "1. 【外部知識の排除】 提供された資料に明確な記載がない情報は決して自身の知識で推測せず、「提供された資料からは判断できません」と回答してください。\n"
             . "2. 【業務背景の適用】 冒頭に提示される「現在の業務背景」を大前提とし、その文脈（目的や場所、期間など）に沿って回答を構成してください。\n"
             . "3. 【情報の統合】 [本文テキスト]だけでなく、[画像/図表の説明]などのメタデータも等しく重要な根拠として扱い、回答に組み込んでください。\n"
             . "4. 【視覚情報の言語化】 写真や図面に基づく記述を行う際は、「資料の〇〇ページの写真（画像解析）を確認すると〜」と具体的に言及し、視覚的な状況が論理的に伝わるよう解説してください。\n"
             . "5. 【引用の絶対義務】 回答の事実を述べる各文、または段落の末尾には、必ず根拠となった引用元を指定フォーマットで明記してください。\n"
             . "   フォーマット例: 「〜ということが確認できます。（資料名 P.XX）」\n"
             . "6. 【図表の出力】 ユーザーから「グラフ」「チャート」「分布」「推移」と要求された場合は、Chart.js用の ```json:chart ... ``` ブロックを優先してください。業務フローなど図解が必要な場合のみMermaid.jsを使用してください。\n"
             . "7. 【論理的な構成】 「資料全体の構成・要約（目次情報）」が提供されている場合はそれを全体像として把握し、結論から先に述べる構造的で読みやすい回答を作成してください。";
    }

    /**
     * ダッシュボードRAG連携時専用のシステムプロンプト指示（Markdownリンク誘導用）
     *
     * @param int $projectId 現在フォーカスしているプロジェクトID
     * @return string
     */
    public static function getDashboardLinkInstruction(int $projectId): string {
        return "\n【ダッシュボード連携特別ルール】\n"
             . "1. 現在、あなたはダッシュボード上で選択された特定のプロジェクトにフォーカスしています。\n"
             . "2. 回答を締めくくる際、または資料をより詳しく分析・チャットで深掘りしたい場合、必ず回答文の末尾（または適切な文脈中）に、業務支援画面へ直接ジャンプできるリンクを以下の書式（Markdownリンク）で設置してください。\n"
             . "   書式: `[業務支援画面で詳細を確認する](support.php?project_id={$projectId})` \n"
             . "3. このリンクはブラウザが認識して直接該当業務のRAG対話画面へとユーザーを遷移させます。必ずこの引数付きURLを含んだマークダウンリンクを回答の中に提示してください。";
    }

    /**
     * ★修正: プロジェクト未選択時（汎用対話モード）に、全体プロジェクト一覧の対応ルールとシステム概要を教える
     *
     * @return string
     */
    public static function getSystemOverviewInstruction(): string {
        return "\n【システムガイダンス＆全体プロジェクト対応ルール】\n"
             . "1. 現在は「汎用対話モード」です。一般的な質問やシステムの使い方に加えて、『現在登録されているプロジェクト全体』に関する質問にも対応します。\n"
             . "2. あなたにはユーザーからの【質問】の直前に、【現在登録されているプロジェクト一覧】の情報が提供されています。ユーザーから「進行中のプロジェクトは？」「どんな業務がある？」等と聞かれた場合は、その一覧をもとに答えてください。\n"
             . "3. もしユーザーが特定のプロジェクトについてさらに深く（関連資料に基づいて）知りたい素振りを見せた場合は、「画面の『Active Projects』リストから対象の案件をクリックすると、関連資料を読み込んだ専門的な対話（案件フォーカスモード）が可能です」と案内してください。\n"
             . "4. 「業務支援」画面からは、新しい案件の登録やPDFのアップロード・AI解析が行えます。";
    }

    /**
     * ✨【次世代アーキテクチャ・フェーズ3】
     * project_meta からロードされた記憶JSONをパースし、AIに対する「鉄壁のカンニングペーパー」を生成する
     *
     * @param string|null $jsonStr project_meta から引き抜いた meta_value（JSON形式）
     * @return string
     */
    public static function getDatabaseMemoryInstruction(?string $jsonStr): string {
        // 安全なフォールバック文字列を初期値として設定（一本道構造の要）
        $fallbackMsg = "\n【現在のデータベース構造と実在するバリューの記憶】\n現在、利用可能なデータベース構造の事前記憶はありません。安全な標準スキーマに基づいて推論してください。\n";
        $instruction = $fallbackMsg;

        if (empty($jsonStr)) {
            return $instruction;
        }

        try {
            $memoryData = json_decode($jsonStr, true);
            
            // デコード失敗時や意図した配列構造になっていない場合はフォールバック
            if (!is_array($memoryData) || empty($memoryData['tables'])) {
                return $instruction;
            }

            // 記憶データを見やすく構造化（JSONをそのまま文字列化して埋め込み）
            // ※ 軽量LLMは整形されたJSON文字列の読解に非常に優れているため、そのまま見せるのが効果的です。
            $prettyJson = json_encode($memoryData['tables'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            $instruction = "\n【現在のデータベース構造と実在するバリューの記憶】\n"
                         . "以下のJSONは、この案件（プロジェクト）に紐づく実際のテーブル、カラム名、サンプルデータ、および資料情報の完全な記憶です。\n"
                         . "```json\n"
                         . $prettyJson . "\n"
                         . "```\n\n"
                         . "【データ検索・SQL生成における絶対厳守ガードレール】\n"
                         . "1. 【ハルシネーションの徹底抑止】あなたは上記の「記憶」に実在するテーブル名、および実在するカラム・キー名（大文字・小文字の区別を厳守）のみを使用して思考・クエリ生成を行わなければなりません。上記に存在しない項目をあなたの想像ででっち上げることは固く禁じます。\n"
                         . "   MySQL 8.0でCSVのJSONキーを読む場合は `JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.\"キー名\"'))` を使ってください。`row_data->>$.キー名` は禁止です。\n"
                         . "2. 【存在しない値の検索禁止】条件指定（WHERE句など）を行う際は、上記の「samples」に実在する具体的な値の傾向（例: APP_1, APP_2など）を大前提とし、データベースに100%存在しない架空の値で空振りするクエリを作らないでください。\n"
                         . "3. 【ダミーSQL禁止】エラー回避のために `SELECT '説明文'` のような実テーブルを読まないSQLを返してはいけません。必ずFROM句で実在テーブルを読み、根拠データを取得してください。\n"
                         . "4. 【複数テーブルの安全横断】構造化データ（project_csv_rows）と非構造化資料（doc_chunks）の繋がりが明記されている場合、それぞれのテーブルの境界を守りつつ、自律的に安全に跨いで（JOINや多段階ステップで）クロス集計・分析を組み立ててください。\n";

        } catch (Exception $e) {
            // 例外発生時は初期値のフォールバックを返す
            $instruction = $fallbackMsg;
        }

        // ✨ どの分岐を通っても必ず string 型が返却される「一本道構造」
        return $instruction;
    }

    /**
     * 案件ごとに人手で管理する AGENTS / README / TODO 相当メモを、
     * 回答生成時の補助コンテキストとして整形する。
     *
     * @param array<string, array{label?: string, content?: string}> $memoryDocs
     */
    public static function getProjectOperatingMemoryInstruction(array $memoryDocs): string
    {
        $sections = [];
        foreach (['agents', 'readme', 'todo'] as $type) {
            $content = trim((string)($memoryDocs[$type]['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $label = (string)($memoryDocs[$type]['label'] ?? strtoupper($type));
            if (mb_strlen($content) > 3000) {
                $content = mb_substr($content, 0, 3000) . "\n...[後半省略]";
            }
            $sections[] = "### {$label}\n{$content}";
        }

        if (empty($sections)) {
            return '';
        }

        return "\n【案件運用メモ】\n"
             . "以下は、この案件に対して人手で管理されている補助メモです。\n"
             . "1. AGENTS は回答方針・禁止事項・優先ルールとして扱ってください。\n"
             . "2. README は案件やシステムの前提知識として扱ってください。\n"
             . "3. TODO は現在の課題や優先論点として扱ってください。ただし、TODO の内容を根拠資料の代わりに断定してはいけません。\n"
             . "4. 資料本文・DB実データ・FAQ・コメントと矛盾する場合は、実データと資料本文を優先してください。\n\n"
             . implode("\n\n", $sections) . "\n";
    }
}
