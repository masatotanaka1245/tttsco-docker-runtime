<?php

class CsvDateColumnDetector
{
    /** @var callable */
    private $normalizer;

    public function __construct(callable $normalizer)
    {
        $this->normalizer = $normalizer;
    }

    public function detectDateColumnsForFile(array $file, array $sampleRows): array
    {
        $scores = [];
        foreach ($file['columns'] as $column) {
            $scores[$column] = 0;
            if ($this->isDateLikeColumnName($column)) {
                $scores[$column] += 3;
            }
        }

        foreach ($sampleRows as $sampleRow) {
            $data = json_decode((string)$sampleRow['row_data'], true);
            if (!is_array($data)) {
                continue;
            }
            foreach ($data as $column => $value) {
                if (!isset($scores[$column])) {
                    $scores[$column] = 0;
                }
                $scores[$column] += $this->scoreDateLikeValue($column, (string)$value);
            }
        }

        $dateColumns = [];
        foreach ($scores as $column => $score) {
            if ($score >= 3) {
                $dateColumns[] = $column;
            }
        }

        return array_values(array_unique($dateColumns));
    }

    public function isDateLikeColumnName(string $column): bool
    {
        if (preg_match('/(password|otp|one-time|recovery code|identifier|code)/iu', $column)) {
            return false;
        }

        return preg_match('/(日付|日時|年月日|生年月日|date|timestamp|created|updated|発注日|入荷日|受注日|診断日|確認日)/iu', $column) === 1;
    }

    public function isDateLikeValue(string $value): bool
    {
        $value = trim($this->normalizeUtf8($value));
        if ($value === '') {
            return false;
        }
        if (preg_match('/^\d{4}[\/\-\.]\d{1,2}[\/\-\.]\d{1,2}(?:\s+\d{1,2}:\d{1,2}(?::\d{1,2})?)?$/u', $value)) {
            return true;
        }
        if (preg_match('/^\d{4}年\d{1,2}月\d{1,2}日/u', $value)) {
            return true;
        }
        if ($this->isCompactDateValue($value)) {
            return true;
        }
        return false;
    }

    public function normalizeDateBucket(string $rawValue, string $granularity = 'day'): ?string
    {
        $value = trim($this->normalizeUtf8($rawValue));
        if ($value === '') {
            return null;
        }

        $value = preg_replace('/\s+/u', ' ', $value);
        $value = str_replace(['年', '月', '日', '.'], ['-', '-', '', '-'], $value);
        $value = str_replace('/', '-', $value);

        $formats = [
            'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d',
            'Y-n-j H:i:s', 'Y-n-j H:i', 'Y-n-j',
            'Ymd', 'Ymd His',
        ];

        $dt = null;
        foreach ($formats as $format) {
            $parsed = \DateTime::createFromFormat($format, $value);
            if ($parsed instanceof \DateTime) {
                $dt = $parsed;
                break;
            }
        }

        if (!$dt && preg_match('/^\d{4}-\d{1,2}-\d{1,2}/', $value)) {
            try {
                $dt = new \DateTime(substr($value, 0, 10));
            } catch (\Exception $e) {
                $dt = null;
            }
        }

        if (!$dt) {
            return null;
        }

        if ($granularity === 'month') {
            return $dt->format('Y-m');
        }
        if ($granularity === 'year') {
            return $dt->format('Y');
        }
        return $dt->format('Y-m-d');
    }

    public function escapeJsonPathKey(string $key): string
    {
        $key = str_replace('\\', '\\\\', $key);
        return str_replace("'", "\\'", $key);
    }

    private function scoreDateLikeValue(string $column, string $value): int
    {
        $normalized = trim($this->normalizeUtf8($value));
        if ($normalized === '') {
            return 0;
        }

        if (preg_match('/^\d{4}[\/\-\.]\d{1,2}[\/\-\.]\d{1,2}(?:\s+\d{1,2}:\d{1,2}(?::\d{1,2})?)?$/u', $normalized)) {
            return 2;
        }

        if (preg_match('/^\d{4}年\d{1,2}月\d{1,2}日/u', $normalized)) {
            return 2;
        }

        if ($this->isDateLikeColumnName($column) && $this->isCompactDateValue($normalized)) {
            return 2;
        }

        return 0;
    }

    private function isCompactDateValue(string $value): bool
    {
        if (!preg_match('/^\d{8}$/', $value)) {
            return false;
        }

        $year = (int)substr($value, 0, 4);
        $month = (int)substr($value, 4, 2);
        $day = (int)substr($value, 6, 2);

        return $year >= 1900 && $year <= 2100 && checkdate($month, $day, $year);
    }

    private function normalizeUtf8(string $text): string
    {
        return (string)call_user_func($this->normalizer, $text);
    }
}
