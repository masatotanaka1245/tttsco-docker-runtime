<?php

final class AdvancedDocAnswerBuilder
{
    private $originalMessage;
    private $subAnswers;
    private $ollamaHost;
    private $synthesisModel;
    private $composeMemoryAwarePrompt;
    private $buildEvidenceDraft;
    private $logger;

    public function __construct(
        string $originalMessage,
        array $subAnswers,
        string $ollamaHost,
        string $synthesisModel,
        callable $composeMemoryAwarePrompt,
        callable $buildEvidenceDraft,
        ?callable $logger = null
    ) {
        $this->originalMessage = $originalMessage;
        $this->subAnswers = $subAnswers;
        $this->ollamaHost = $ollamaHost;
        $this->synthesisModel = $synthesisModel;
        $this->composeMemoryAwarePrompt = $composeMemoryAwarePrompt;
        $this->buildEvidenceDraft = $buildEvidenceDraft;
        $this->logger = $logger;
    }

    public function buildLightweightDocFinalAnswer(string $currentDraft, array $stepResults = []): string
    {
        $deterministicAnswer = $this->buildDeterministicDocLightweightAnswer();
        if ($deterministicAnswer !== '') {
            return $deterministicAnswer;
        }

        $reasoningText = implode("\n\n", $this->subAnswers);
        if (mb_strlen($reasoningText) > 5000) {
            $reasoningText = mb_substr($reasoningText, 0, 5000) . "\n...[制限超過による省略]";
        }

        $groundingPacket = $this->buildDocLightweightEvidencePacket();
        if ($groundingPacket === '') {
            $groundingPacket = (string)call_user_func($this->buildEvidenceDraft, $stepResults);
        }

        $systemPrompt = "あなたは資料読解に強い業務支援AIです。"
            . "ユーザーは案件に関連する資料PDFについて次の質問への回答を求めています: 「{$this->originalMessage}」。"
            . "与えられた根拠だけを使い、過不足なく短く日本語Markdownで答えてください。"
            . "見出しと箇条書きを使ってよいですが、冗長な前置きや架空の補足は禁止です。"
            . "資料全体の一般説明よりも、質問で求められた確認事項・留意点・注意点・根拠を優先してください。"
            . "質問の依頼形式（箇条書き、件数指定、観点指定）がある場合は必ず従ってください。"
            . "「概要」「主な構成要素」といった資料紹介中心の答え方は避け、質問に対する直接の答えから始めてください。"
            . "留意点は3〜5個までに絞り、各項目は必ず `- [資料名 / P.xx]` または `- [資料名]` で始めてください。"
            . "根拠に書かれていない資料名・建物名・用途・結論を推測で補わないでください。"
            . "根拠断片に含まれない法規名、設備名、構造種別、一般論を追加してはいけません。"
            . "各留意点は、根拠断片に含まれる表現に寄せて1〜2文で書いてください。"
            . "明示的な留意点が見つからない場合は、その旨を短く明示し、代わりに確認が必要な記述断片を挙げてください。";

        $userPrompt = "【ユーザーの質問】\n{$this->originalMessage}\n\n"
            . "【最優先で使う根拠断片】\n{$groundingPacket}\n\n"
            . "【利用可能な根拠・中間考察】\n{$reasoningText}\n\n"
            . "【内部ドラフト】\n{$currentDraft}\n\n"
            . "【出力ルール】\n"
            . "1. 冒頭1〜2文で結論を書く。\n"
            . "2. その後に `## 留意点` を置き、留意点を箇条書きで3〜5件示す。\n"
            . "3. 各箇条書きは必ず資料名とページ番号を含める。\n"
            . "4. 最後に `## 根拠` を置き、使った根拠断片を短く列挙する。\n"
            . "5. 根拠断片にない内容は書かない。\n\n"
            . "上記だけを根拠に、ユーザーへ提示する最終回答のみを日本語Markdownで出力してください。";

        $response = callOllamaChat(
            $this->ollamaHost,
            $this->synthesisModel,
            (string)call_user_func($this->composeMemoryAwarePrompt, $systemPrompt),
            $userPrompt,
            null,
            ["temperature" => 0.0, "top_p" => 0.1, "num_ctx" => 4096]
        );

        return trim((string)$response);
    }

    public function buildDocChunkEvidenceSummary(array $rows): string
    {
        if (empty($rows)) {
            return "- 該当する資料本文は取得できませんでした。";
        }

        usort($rows, function (array $a, array $b): int {
            return $this->scoreDocChunkEvidenceRow($b) <=> $this->scoreDocChunkEvidenceRow($a);
        });

        $rows = $this->selectDiversifiedDocChunkRows($rows, min(count($rows), 8));

        $lines = [];
        $maxRows = count($rows);
        for ($i = 0; $i < $maxRows; $i++) {
            $row = $rows[$i];
            $title = trim((string)($row['title'] ?? $row['file_path'] ?? '資料名不明'));
            $page = (int)($row['page_number'] ?? 0);
            $chunkText = $this->summarizeDocEvidenceText((string)($row['chunk_text'] ?? ''), 180);
            $imageDescription = $this->compactEvidenceText((string)($row['image_description'] ?? ''), 140);

            $header = "- 資料: {$title}";
            if ($page > 0) {
                $header .= " / ページ: {$page}";
            }
            $lines[] = $header;

            if ($chunkText !== '') {
                $lines[] = "  - 本文抜粋: {$chunkText}";
            }
            if ($imageDescription !== '') {
                $lines[] = "  - 図表説明: {$imageDescription}";
            }
        }

        if (count($rows) > $maxRows) {
            $lines[] = "- ほか " . (count($rows) - $maxRows) . " 件の根拠断片あり";
        }

        return implode("\n", $lines);
    }

    private function buildDeterministicDocLightweightAnswer(): string
    {
        $blocks = $this->extractDocEvidenceBlocks();
        if (empty($blocks)) {
            return '';
        }

        $strictEvidenceOnly = preg_match('/(根拠だけ|推測は入れない|推測しない|資料にあることだけ|根拠のみ)/u', $this->originalMessage) === 1;
        $requestedLimit = $this->extractRequestedDocItemLimit();
        $limit = $requestedLimit ?? 3;
        $limit = max(1, min($limit, 5));
        $selectedBlocks = $this->selectDocEvidenceBlocks($blocks, $limit);

        $lines = [];
        $lines[] = $strictEvidenceOnly
            ? '資料PDFから確認できた記述だけを抜き出します。'
            : '資料PDFから確認できた主要な留意点を整理します。';
        $lines[] = '';
        $lines[] = '## 留意点';
        foreach ($selectedBlocks as $block) {
            $lines[] = $this->formatDocEvidenceBullet($block, $strictEvidenceOnly);
        }
        $lines[] = '';
        $lines[] = '## 根拠';
        foreach ($selectedBlocks as $block) {
            $lines[] = $this->formatDocEvidenceSource($block);
        }

        return trim(implode("\n", $lines));
    }

    private function buildDocLightweightEvidencePacket(): string
    {
        $blocks = $this->extractDocEvidenceBlocks();
        if (empty($blocks)) {
            return '';
        }

        $blocks = $this->selectDocEvidenceBlocks($blocks, min(count($blocks), 6));

        $packetLines = [];
        $maxBlocks = count($blocks);
        for ($i = 0; $i < $maxBlocks; $i++) {
            $block = $blocks[$i];
            $title = trim((string)($block['title'] ?? '資料名不明'));
            $page = (int)($block['page'] ?? 0);
            $header = ($i + 1) . '. ' . $title;
            if ($page > 0) {
                $header .= " / P.{$page}";
            }
            $packetLines[] = $header;

            if (trim((string)($block['chunk'] ?? '')) !== '') {
                $packetLines[] = "   - 本文: " . $this->compactEvidenceText((string)$block['chunk'], 180);
            }
            if (trim((string)($block['image'] ?? '')) !== '') {
                $packetLines[] = "   - 図表: " . $this->compactEvidenceText((string)$block['image'], 120);
            }
        }

        if (count($blocks) > $maxBlocks) {
            $packetLines[] = '- ほか ' . (count($blocks) - $maxBlocks) . ' 件の資料断片あり';
        }

        return implode("\n", $packetLines);
    }

    private function extractDocEvidenceBlocks(): array
    {
        $raw = implode("\n", array_map(static fn($answer) => (string)$answer, $this->subAnswers));
        if (trim($raw) === '') {
            return [];
        }

        $lines = preg_split('/\R/u', $raw) ?: [];
        $blocks = [];
        $current = null;

        $flushCurrent = static function (?array $block) use (&$blocks): void {
            if ($block === null) {
                return;
            }
            $hasEvidence = trim((string)($block['chunk'] ?? '')) !== '' || trim((string)($block['image'] ?? '')) !== '';
            if ($hasEvidence) {
                $blocks[] = $block;
            }
        };

        foreach ($lines as $line) {
            $trimmed = trim((string)$line);
            if ($trimmed === '') {
                continue;
            }

            if (preg_match('/^- 資料:\s*(.+?)(?:\s*\/\s*ページ:\s*([0-9]+))?$/u', $trimmed, $matches)) {
                $flushCurrent($current);
                $current = [
                    'title' => trim((string)$matches[1]),
                    'page' => isset($matches[2]) ? (int)$matches[2] : 0,
                    'chunk' => '',
                    'image' => '',
                ];
                continue;
            }

            if ($current === null) {
                continue;
            }

            if (preg_match('/^-?\s*本文抜粋:\s*(.+)$/u', $trimmed, $matches)) {
                $current['chunk'] = trim((string)$matches[1]);
                continue;
            }

            if (preg_match('/^-?\s*図表説明:\s*(.+)$/u', $trimmed, $matches)) {
                $current['image'] = trim((string)$matches[1]);
            }
        }

        $flushCurrent($current);

        return $blocks;
    }

    private function scoreDocChunkEvidenceRow(array $row): int
    {
        $text = trim(implode(' ', [
            (string)($row['title'] ?? ''),
            (string)($row['chunk_text'] ?? ''),
            (string)($row['image_description'] ?? ''),
        ]));
        $text = $this->normalizeDocEvidenceText($text);

        if ($text === '') {
            return -100;
        }

        $score = 0;
        $focusTerms = $this->extractDocQuestionFocusTerms();
        foreach ($focusTerms as $term) {
            if ($term !== '' && mb_stripos($text, $term, 0, 'UTF-8') !== false) {
                $score += 8;
            }
        }

        if (preg_match('/(留意|注意|確認|必要|遵守|禁止|検証|点検|対策|非常用|防火|避難|有効幅員|階段|進入口|開錠|幅員|仕様|法規|基準|設備|構造|仕上げ)/u', $text)) {
            $score += 8;
        }

        if (preg_match('/(非常用進入口|有効幅員|踊り場|開錠できる|避難経路|防火区画)/u', $text)) {
            $score += 10;
        }

        if (preg_match('/(建築基準法|消防法|第[0-9]+条|m\b|mm\b|有効幅|高さ|縮尺)/u', $text)) {
            $score += 4;
        }

        if (preg_match('/(archsync|arch-model|トレーニング|サンプルモデル)/iu', $text)) {
            $score -= 5;
        }

        if (preg_match('/(図面リスト|確認申請図面リスト|通し番号|図面番号|縮尺|建築図)/u', $text)) {
            $score -= 6;
        }

        if (preg_match('/(\|\s*[0-9]{1,3}\s*\|.*\|\s*[A-Z][0-9]{3,}\s*\||室内仕上げ表|配置図|平面図-?[0-9]*|立面図|断面図|矩計図)/u', $text)) {
            $score -= 14;
        }

        if (preg_match('/(面積表|面積求積|容積対象外|容積対象|昇降路の部分|エレベーターの昇降路|階容積対象面積|容積対象外面積|㎡|BIMソフト|Archicad|設計一級建築士登録|登録第\*+号|登録第[0-9＊*]+号)/iu', $text)) {
            $score -= 16;
        }

        if ($this->isWeakDocEvidenceText($text)) {
            $score -= 10;
        }

        if ($this->isVeryWeakDocEvidenceText($text)) {
            $score -= 18;
        }

        if (mb_strlen($text) < 40) {
            $score -= 2;
        }

        return $score;
    }

    private function extractDocQuestionFocusTerms(): array
    {
        $terms = [];
        $message = (string)$this->originalMessage;
        $push = function (string $term) use (&$terms): void {
            if ($term !== '' && !in_array($term, $terms, true)) {
                $terms[] = $term;
            }
        };

        $patterns = [
            '施工前',
            '確認',
            '確認事項',
            '留意点',
            '注意点',
            '安全',
            '安全面',
            '法規',
            '基準',
            '設備',
            '構造',
            '仕様',
            '図面',
            '不明点',
            '見落とし',
            '設計',
        ];

        foreach ($patterns as $pattern) {
            if (mb_stripos($message, $pattern, 0, 'UTF-8') !== false) {
                $push($pattern);
            }
        }

        return $terms;
    }

    private function compactEvidenceText(string $text, int $limit): string
    {
        $text = trim((string)(preg_replace('/\s+/u', ' ', $text) ?? $text));
        if ($text === '') {
            return '';
        }

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit) . '...';
    }

    private function extractRequestedDocItemLimit(): ?int
    {
        if (preg_match('/([0-9]+)\s*(点|件|項目)だけ/u', $this->originalMessage, $matches)) {
            return (int)$matches[1];
        }

        return null;
    }

    private function formatDocEvidenceBullet(array $block, bool $strictEvidenceOnly): string
    {
        $reference = $this->buildDocEvidenceReference($block);
        $evidence = trim((string)($block['chunk'] ?? '')) !== ''
            ? $this->summarizeDocEvidenceText((string)$block['chunk'], $strictEvidenceOnly ? 150 : 120)
            : $this->compactEvidenceText((string)($block['image'] ?? ''), 120);

        if ($evidence === '') {
            $evidence = '該当箇所の記載内容を要確認。';
        }

        return "- {$reference} {$evidence}";
    }

    private function formatDocEvidenceSource(array $block): string
    {
        $reference = $this->buildDocEvidenceReference($block);
        $source = trim((string)($block['chunk'] ?? '')) !== ''
            ? $this->summarizeDocEvidenceText((string)$block['chunk'], 90)
            : $this->compactEvidenceText((string)($block['image'] ?? ''), 90);

        if ($source === '') {
            $source = '本文抜粋なし';
        }

        return "- {$reference} {$source}";
    }

    private function buildDocEvidenceReference(array $block): string
    {
        $title = trim((string)($block['title'] ?? '資料名不明'));
        $page = (int)($block['page'] ?? 0);
        return $page > 0 ? "[{$title} / P.{$page}]" : "[{$title}]";
    }

    private function selectDiversifiedDocChunkRows(array $rows, int $limit): array
    {
        $selected = [];
        $usedPageKeys = [];
        $deferred = [];
        $strongCount = 0;
        $candidateLogs = [];

        foreach ($rows as $row) {
            $title = trim((string)($row['title'] ?? $row['file_path'] ?? '資料名不明'));
            $page = (int)($row['page_number'] ?? 0);
            $pageKey = mb_strtolower($title, 'UTF-8') . '#' . $page;
            $text = $this->normalizeDocEvidenceText((string)($row['chunk_text'] ?? ''));
            $classification = $this->classifyDocEvidenceText($text);

            $candidateLogs[] = "row|{$classification}|{$title}|P{$page}|"
                . $this->scoreDocChunkEvidenceRow($row)
                . '|'
                . $this->compactEvidenceText($text, 90);

            if ($classification === 'very_weak') {
                continue;
            }

            if ($classification === 'weak') {
                $deferred[] = $row;
                continue;
            }

            if (!isset($usedPageKeys[$pageKey])) {
                $selected[] = $row;
                $usedPageKeys[$pageKey] = true;
                $strongCount++;
            } else {
                $deferred[] = $row;
            }

            if (count($selected) >= $limit) {
                $this->logDocEvidenceCandidates('rows', $candidateLogs, count($selected), count($deferred));
                return $selected;
            }
        }

        $this->logDocEvidenceCandidates('rows', $candidateLogs, count($selected), count($deferred));

        if ($strongCount > 0) {
            return $selected;
        }

        foreach ($deferred as $row) {
            $selected[] = $row;
            if (count($selected) >= $limit) {
                break;
            }
        }

        return $selected;
    }

    private function selectDocEvidenceBlocks(array $blocks, int $limit): array
    {
        $selected = [];
        $usedPageKeys = [];
        $deferred = [];
        $strongCount = 0;
        $candidateLogs = [];

        foreach ($blocks as $block) {
            $title = trim((string)($block['title'] ?? '資料名不明'));
            $page = (int)($block['page'] ?? 0);
            $pageKey = mb_strtolower($title, 'UTF-8') . '#' . $page;
            $text = trim((string)($block['chunk'] ?? '')) !== ''
                ? (string)$block['chunk']
                : (string)($block['image'] ?? '');
            $normalized = $this->normalizeDocEvidenceText($text);

            if ($normalized === '') {
                continue;
            }

            $classification = $this->classifyDocEvidenceText($normalized);
            $candidateLogs[] = "block|{$classification}|{$title}|P{$page}|"
                . $this->compactEvidenceText($normalized, 90);

            if ($classification === 'very_weak') {
                continue;
            }

            if ($classification === 'weak') {
                $deferred[] = $block;
                continue;
            }

            if (!isset($usedPageKeys[$pageKey])) {
                $selected[] = $block;
                $usedPageKeys[$pageKey] = true;
                $strongCount++;
            } else {
                $deferred[] = $block;
            }

            if (count($selected) >= $limit) {
                $this->logDocEvidenceCandidates('blocks', $candidateLogs, count($selected), count($deferred));
                return $selected;
            }
        }

        $this->logDocEvidenceCandidates('blocks', $candidateLogs, count($selected), count($deferred));

        if ($strongCount > 0) {
            return $selected;
        }

        foreach ($deferred as $block) {
            $selected[] = $block;
            if (count($selected) >= $limit) {
                break;
            }
        }

        return $selected;
    }

    private function summarizeDocEvidenceText(string $text, int $limit): string
    {
        $text = $this->normalizeDocEvidenceText($text);
        if ($text === '') {
            return '';
        }

        $preferred = $this->extractPreferredDocEvidenceSnippet($text);
        if ($preferred !== '') {
            return $this->compactEvidenceText($preferred, $limit);
        }

        $segments = preg_split('/(?:。|！|!|？|\?|：|:|  +|(?<=\])\s+)/u', $text) ?: [];
        foreach ($segments as $segment) {
            $segment = trim((string)$segment);
            if ($segment === '' || $this->isWeakDocEvidenceText($segment)) {
                continue;
            }
            if (preg_match('/(留意|注意|確認|必要|遵守|禁止|非常用|防火|避難|有効幅員|階段|進入口|開錠|幅員)/u', $segment)) {
                return $this->compactEvidenceText($segment, $limit);
            }
        }

        return $this->compactEvidenceText($text, $limit);
    }

    private function extractPreferredDocEvidenceSnippet(string $text): string
    {
        $patterns = [
            '/(非常用進入口[^。]{0,90})/u',
            '/(廊下の有効幅員[^。]{0,90})/u',
            '/(階段[^。]{0,90}踊り場[^。]{0,60})/u',
            '/(避難[^。]{0,90})/u',
            '/(防火[^。]{0,90})/u',
            '/(開錠できる[^。]{0,80})/u',
            '/(有効幅員[^。]{0,80})/u',
            '/(特例の適用の有無[^。]{0,80})/u',
            '/((?:留意|注意|確認|必要|遵守|禁止)[^。]{0,90})/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                return trim((string)$matches[1]);
            }
        }

        return '';
    }

    private function normalizeDocEvidenceText(string $text): string
    {
        $text = trim((string)$text);
        if ($text === '') {
            return '';
        }

        if (class_exists('Normalizer')) {
            $normalized = \Normalizer::normalize($text, \Normalizer::FORM_KC);
            if (is_string($normalized) && $normalized !== '') {
                $text = $normalized;
            }
        }

        $text = strtr($text, [
            '⾯' => '面',
            '⼊' => '入',
            '⽕' => '火',
            '⽤' => '用',
            '⾼' => '高',
            '⼨' => '寸',
            '⾃' => '自',
            '⾏' => '行',
        ]);

        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = preg_replace('/(?<=[\p{Han}\p{Hiragana}\p{Katakana}])\s+(?=[\p{Han}\p{Hiragana}\p{Katakana}])/u', '', $text) ?? $text;
        $text = preg_replace('/\s*([,.:;\/\]\[()])\s*/u', '$1', $text) ?? $text;
        $text = preg_replace('/\|\s*:?-{2,}\s*/u', '| ', $text) ?? $text;
        $text = preg_replace('/\[\s*省略\s*\]/u', '[省略]', $text) ?? $text;

        return trim($text);
    }

    private function isWeakDocEvidenceText(string $text): bool
    {
        $text = $this->normalizeDocEvidenceText($text);
        if ($text === '') {
            return true;
        }

        return preg_match('/(主要用途|駐車台数|建築物の数|申請に係る建築物の数|最高の高さ|主体構造|地上[0-9]+階|天井高|主なスパン|軒裏|敷地測量図|縮尺|図面リスト|通し番号|室内仕上げ表|配置図|平面図-?[0-9]*|立面図|断面図|矩計図)/u', $text) === 1;
    }

    private function isVeryWeakDocEvidenceText(string $text): bool
    {
        $text = $this->normalizeDocEvidenceText($text);
        if ($text === '') {
            return true;
        }

        if (preg_match('/\|\s*:?-{2,}\s*\|/u', $text)) {
            return true;
        }

        if (preg_match('/^\|\s*[0-9]{1,3}\s*\|/u', $text)) {
            return true;
        }

        if (preg_match('/(\|\s*[0-9]{1,3}\s*\|.*\|\s*[A-Z][0-9]{3,}\s*\||\|\s*説[0-9]+\s*\||室内仕上げ表|配置図|平面図-?[0-9]*|立面図|断面図|矩計図|面積表|面積求積|容積対象外面積|容積対象面積|階容積対象面積|容積対象外|容積対象|昇降路の部分|エレベーターの昇降路|㎡|BIMソフト|Archicad|設計一級建築士登録|登録第\*+号|登録第[0-9＊*]+号)/iu', $text)) {
            return true;
        }

        if (preg_match('/(確認申請図面リスト|図面番号区分|電子テキスト本文|画像内には、文字|画像内には文字|表データ、ラベル|2x2 タイル詳細|archsyncトレーニング|archsync training)/iu', $text)) {
            return true;
        }

        if (preg_match('/^.{0,12}\.\.\.\[省略\]$/u', $text)) {
            return true;
        }

        if (preg_match('/^(必要|概要|一覧|図面|資料名不明).{0,10}\[省略\]$/u', $text)) {
            return true;
        }

        if ($this->hasExcessiveNumericDensity($text)) {
            return true;
        }

        return false;
    }

    private function classifyDocEvidenceText(string $text): string
    {
        $text = $this->normalizeDocEvidenceText($text);
        if ($text === '' || $this->isVeryWeakDocEvidenceText($text)) {
            return 'very_weak';
        }
        if ($this->isWeakDocEvidenceText($text)) {
            return 'weak';
        }
        return 'strong';
    }

    private function logDocEvidenceCandidates(string $kind, array $candidateLogs, int $selectedCount, int $deferredCount): void
    {
        if ($this->logger === null) {
            return;
        }

        if (empty($candidateLogs)) {
            call_user_func($this->logger, "[DOC-CANDIDATES] {$kind}=0 | selected={$selectedCount} | deferred={$deferredCount}");
            return;
        }

        $preview = array_slice($candidateLogs, 0, 6);
        call_user_func(
            $this->logger,
            "[DOC-CANDIDATES] {$kind}=" . count($candidateLogs)
            . " | selected={$selectedCount} | deferred={$deferredCount}"
            . " | " . implode(' || ', $preview)
        );
    }

    private function hasExcessiveNumericDensity(string $text): bool
    {
        $text = $this->normalizeDocEvidenceText($text);
        if ($text === '') {
            return false;
        }

        $digits = preg_match_all('/[0-9]/u', $text);
        $numberRuns = preg_match_all('/[0-9][0-9,.\-]*/u', $text);
        $length = max(1, mb_strlen($text));

        if ($digits >= 20 && $digits / $length >= 0.28) {
            return true;
        }

        if ($numberRuns >= 8 && preg_match('/(Y[0-9]+|X[0-9]+|A\s*[0-9]{2,}|[0-9]{1,3},[0-9]{3})/u', $text)) {
            return true;
        }

        return false;
    }
}
