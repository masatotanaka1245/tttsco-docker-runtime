<?php

final class DocChunkImageDescriptionNormalizer
{
    public static function normalizeForStorage(string $imageDescription, string $chunkText = ''): string
    {
        $normalized = self::normalizeWhitespace($imageDescription);
        $chunkLength = self::chunkTextLength($chunkText);

        if ($normalized === '') {
            return $chunkLength >= 120 ? '本文中心ページ' : '';
        }

        if (preg_match('/^Auto Native Text\s*\|\s*textChars=\d+$/iu', $normalized)) {
            return '本文中心ページ';
        }

        if (preg_match('/^VLM Fallback to Native Text\s*\|\s*textChars=\d+$/iu', $normalized)) {
            return '本文中心ページ（VLMフォールバック）';
        }

        if (self::isVisionUnavailableResponse($normalized)) {
            return $chunkLength >= 120 ? '本文中心ページ（VLMフォールバック）' : 'ページ種別: 未判定';
        }

        if (str_starts_with($normalized, '種類と概要:')) {
            $normalized = preg_replace('/^種類と概要:\s*/u', '', $normalized) ?? $normalized;
            $normalized = preg_replace('/\s*\(Mode:\s*[^)]+\)\s*$/iu', '', $normalized) ?? $normalized;
            $normalized = self::normalizeWhitespace($normalized);
        }

        if (preg_match('/^【([^】]+)】\s*(.+)$/u', $normalized, $matches)) {
            $category = trim((string)$matches[1]);
            $summary = self::compactText((string)$matches[2], 100);
            if ($summary !== '' && !self::isVisionUnavailableResponse($summary)) {
                return "ページ種別: {$category} | 概要: {$summary}";
            }
            return "ページ種別: {$category}";
        }

        if (str_starts_with($normalized, 'ページ種別:')) {
            return self::compactText($normalized, 140);
        }

        if (
            str_starts_with($normalized, '本文中心ページ')
            || str_starts_with($normalized, 'テキスト主体ページ')
            || str_starts_with($normalized, '資料全体の要約・構成情報')
            || str_starts_with($normalized, '案件資料メモ')
            || str_contains($normalized, 'CSVデータ')
        ) {
            return self::compactText($normalized, 140);
        }

        return self::compactText($normalized, 140);
    }

    public static function isVisionUnavailableResponse(string $text): bool
    {
        $normalized = self::normalizeWhitespace($text);
        if ($normalized === '') {
            return false;
        }

        return (bool)preg_match(
            '/(画像|写真|添付|分析対象).{0,60}(ない|ありません|おりません|未提供|見当たりません|確認できません|説明できません)|'
            . '内容.{0,20}確認できません|具体的な内容.{0,20}確認できません|空白のページ|概要.{0,20}説明できません|'
            . '再度.{0,20}(アップロード|提供)/iu',
            $normalized
        );
    }

    private static function normalizeWhitespace(string $text): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    private static function compactText(string $text, int $limit = 140): string
    {
        $normalized = self::normalizeWhitespace($text);
        if ($normalized === '') {
            return '';
        }

        return mb_strimwidth($normalized, 0, $limit, '...');
    }

    private static function chunkTextLength(string $chunkText): int
    {
        return mb_strlen((string)preg_replace('/\s+/u', '', $chunkText));
    }
}
