<?php

class CsvSearchTermExtractor
{
    /** @var callable */
    private $normalizer;

    public function __construct(callable $normalizer)
    {
        $this->normalizer = $normalizer;
    }

    public function findMentionedCsvFileName(string $question, array $csvMetadata): ?string
    {
        $normalizedQuestion = $this->normalizeCsvRouteText($question);
        if ($normalizedQuestion === '') {
            return null;
        }

        foreach ($csvMetadata as $file) {
            $fileName = (string)($file['file_name'] ?? '');
            $normalizedFile = $this->normalizeCsvRouteText($fileName);
            if ($normalizedFile === '') {
                continue;
            }
            if (mb_strpos($normalizedQuestion, $normalizedFile) !== false || mb_strpos($normalizedFile, $normalizedQuestion) !== false) {
                return $fileName;
            }
        }

        return null;
    }

    public function normalizeCsvRouteText(string $text): string
    {
        $text = mb_strtolower($this->normalizeUtf8($text), 'UTF-8');
        $text = preg_replace('/\.(csv|tsv)$/iu', '', $text);
        $text = preg_replace('/[\s　「」『』【】\\[\\]（）()、。,.，．:：;；!！?？#]+/u', '', $text);
        return trim((string)$text);
    }

    public function extractCsvSearchTerms(string $question, array $csvMetadata): array
    {
        $question = $this->normalizeUtf8($question);
        $terms = [];

        $mentionedCsv = $this->findMentionedCsvFileName($question, $csvMetadata);
        if ($mentionedCsv !== null) {
            $baseName = preg_replace('/\.(csv|tsv)$/iu', '', $mentionedCsv);
            $baseName = preg_replace('/[（(].*$/u', '', (string)$baseName);
            $baseName = trim((string)$baseName);
            if ($baseName !== '') {
                $terms[] = $baseName;
            }
        }

        if (preg_match_all('/[「『"“]([^」』"”]{2,60})[」』"”]/u', $question, $matches)) {
            foreach ($matches[1] as $term) {
                $terms[] = $term;
            }
        }

        if (preg_match_all('/[A-Za-z][A-Za-z0-9_.@:\\-]{2,}/u', $question, $matches)) {
            foreach ($matches[0] as $term) {
                $terms[] = $term;
            }
        }

        $hasExplicitTerm = !empty($terms);

        if (preg_match_all('/[\p{Han}\p{Hiragana}\p{Katakana}ー]{2,}/u', $question, $matches)) {
            foreach ($matches[0] as $term) {
                $terms[] = $term;
            }
        }

        if (!$hasExplicitTerm && $this->isBroadCsvOverviewQuestion($question)) {
            return [];
        }

        $stopWords = [
            'CSV', 'csv', 'データ', '登録済み', '内容', '概要', 'まとめ', '要約', '集計',
            '教えて', 'ください', 'どんな', 'なに', '何', 'この', 'その', '対象',
            '項目', '列', 'カラム', 'ヘッダ', 'ヘッダー', '行', '件', 'レコード',
            'ファイル', '全体', '確認', '説明', '一覧', '入っています', 'あります',
            '全ての', 'すべての', 'どのような', '特定して', '表にして', '集計にして',
            '日付別', '月別', '年別',
        ];
        $stopMap = array_fill_keys($stopWords, true);

        $cleaned = [];
        foreach ($terms as $term) {
            $term = $this->normalizeUtf8((string)$term);
            $term = preg_replace('/\s+/u', ' ', $term);
            $term = preg_replace('/(について|に関して|のこと|とは)$/u', '', $term);
            $term = preg_replace('/^[「『"“]+|[」』"”]+$/u', '', $term);
            $term = preg_replace('/^[\s、。,.，．:：;；!?！？()（）\[\]【】]+|[\s、。,.，．:：;；!?！？()（）\[\]【】]+$/u', '', $term);
            $term = trim((string)$term);
            if ($term === '' || mb_strlen($term) < 2 || isset($stopMap[$term]) || $this->isGenericCsvSearchTerm($term)) {
                continue;
            }
            if (preg_match('/^(CSV|csv)$/u', $term)) {
                continue;
            }
            $cleaned[$term] = true;
            if (count($cleaned) >= 8) {
                break;
            }
        }

        return array_keys($cleaned);
    }

    public function isBroadCsvOverviewQuestion(string $question): bool
    {
        return (bool)preg_match('/(CSV|csv|データ)/u', $question)
            && (bool)preg_match('/(登録済み|全体|概要|まとめ|要約|集計|内容|傾向|概況)/u', $question)
            && !preg_match('/[「『"“][^」』"”]{2,60}[」』"”]/u', $question)
            && !preg_match('/[A-Za-z][A-Za-z0-9_.@:\-]{2,}/u', str_replace(['CSV', 'csv'], '', $question));
    }

    public function isGenericCsvSearchTerm(string $term): bool
    {
        if (preg_match('/(登録済み|データ|集計|概要|教えて|ください|まとめ|要約|内容|全体|CSV|csv)/u', $term)) {
            return true;
        }
        return false;
    }

    private function normalizeUtf8(string $text): string
    {
        return (string)call_user_func($this->normalizer, $text);
    }
}
