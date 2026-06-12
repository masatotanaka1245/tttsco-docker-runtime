<?php

final class DocChunkSummaryBuilder
{
    public static function build(string $chunkText, string $imageDescription = '', int $limit = 110): string
    {
        $text = self::normalizeWhitespace($chunkText);
        if ($text === '') {
            return self::compact(self::normalizeWhitespace($imageDescription), $limit);
        }

        $heading = self::extractMarkdownHeading($chunkText);
        if ($heading !== '') {
            $lead = self::extractLeadSentence($text);
            return self::compact($heading . ($lead !== '' ? ' | ' . $lead : ''), $limit);
        }

        if (preg_match('/^CSV「(.+?)」の(第\d+(?:〜第?\d+)?)行のデータ[:：]\s*(.+)$/u', $text, $matches)) {
            $fileName = trim((string)$matches[1]);
            $rowLabel = trim((string)$matches[2]);
            $detail = self::summarizeCsvNarrative((string)$matches[3]);
            return self::compact("CSV {$fileName} {$rowLabel} | {$detail}", $limit);
        }

        $lead = self::extractLeadSentence($text);
        $category = self::extractImageCategory($imageDescription);
        if ($category !== '' && $lead !== '') {
            return self::compact("{$category} | {$lead}", $limit);
        }
        if ($lead !== '') {
            return self::compact($lead, $limit);
        }

        return self::compact($text, $limit);
    }

    private static function normalizeWhitespace(string $text): string
    {
        $text = preg_replace('/```.+?```/su', ' ', $text) ?? $text;
        $text = preg_replace('/\[(.*?)\]\((.*?)\)/u', '$1', $text) ?? $text;
        $text = preg_replace('/[`*_>#-]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    private static function compact(string $text, int $limit): string
    {
        $text = self::normalizeWhitespace($text);
        if ($text === '') {
            return '';
        }
        return mb_strimwidth($text, 0, $limit, '...');
    }

    private static function extractMarkdownHeading(string $chunkText): string
    {
        if (preg_match('/^\s*#+\s+(.+)$/mu', $chunkText, $matches)) {
            return self::compact((string)$matches[1], 50);
        }
        return '';
    }

    private static function extractLeadSentence(string $text): string
    {
        $text = preg_replace('/^【[^】]+】/u', '', $text) ?? $text;
        $parts = preg_split('/(?<=[。！？.!?])\s+/u', $text);
        foreach ((array)$parts as $part) {
            $part = trim((string)$part);
            if ($part !== '') {
                return self::compact($part, 90);
            }
        }
        return self::compact($text, 90);
    }

    private static function summarizeCsvNarrative(string $detail): string
    {
        $pairs = [];
        foreach ((array)preg_split('/、/u', $detail) as $part) {
            if (preg_match('/^(.+?)は「(.*?)」$/u', trim((string)$part), $matches)) {
                $key = trim((string)$matches[1]);
                $value = trim((string)$matches[2]);
                if ($key === '' || $value === '') {
                    continue;
                }
                $pairs[] = "{$key}={$value}";
                if (count($pairs) >= 3) {
                    break;
                }
            }
        }
        return implode(' / ', $pairs);
    }

    private static function extractImageCategory(string $imageDescription): string
    {
        if (preg_match('/ページ種別:\s*([^|]+)/u', $imageDescription, $matches)) {
            return trim((string)$matches[1]);
        }
        if (
            str_starts_with($imageDescription, '本文中心ページ')
            || str_starts_with($imageDescription, 'テキスト主体ページ')
        ) {
            return trim($imageDescription);
        }
        return '';
    }
}
