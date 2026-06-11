<?php

final class SqlRouteSchemaScopeHelper
{
    private string $schemaInfo;
    /** @var array<string,string> */
    private array $schemaInfoByTable;
    private string $schemaSummaryMatrix;
    /** @var string[] */
    private array $dynamicTableWhitelist;

    /**
     * @param array<string,string> $schemaInfoByTable
     * @param string[] $dynamicTableWhitelist
     */
    public function __construct(
        string $schemaInfo,
        array $schemaInfoByTable,
        string $schemaSummaryMatrix,
        array $dynamicTableWhitelist
    ) {
        $this->schemaInfo = $schemaInfo;
        $this->schemaInfoByTable = $schemaInfoByTable;
        $this->schemaSummaryMatrix = $schemaSummaryMatrix;
        $this->dynamicTableWhitelist = $dynamicTableWhitelist;
    }

    /**
     * @param array<int,string> $tables
     * @return array<int,string>
     */
    public function normalizeSchemaTableList(array $tables): array
    {
        $normalized = [];
        foreach ($tables as $table) {
            $tableName = trim((string)$table, " \t\n\r\0\x0B`");
            if ($tableName === '') {
                continue;
            }
            if (in_array($tableName, $this->dynamicTableWhitelist, true) && !in_array($tableName, $normalized, true)) {
                $normalized[] = $tableName;
            }
        }
        return $normalized;
    }

    public function isAllowedTargetTable(string $tableName): bool
    {
        $normalized = trim($tableName, " \t\n\r\0\x0B`");
        if ($normalized === '') {
            return false;
        }
        return in_array($normalized, $this->dynamicTableWhitelist, true);
    }

    /**
     * @param array<int,string> $preferredTables
     */
    public function buildScopedSchemaInfo(array $preferredTables = []): string
    {
        $tables = $this->normalizeSchemaTableList($preferredTables);
        if (empty($tables)) {
            return $this->schemaInfo;
        }

        $segments = ["【INFORMATION_SCHEMAコンテキスト (実在データベース構造 / scoped)】"];
        foreach ($tables as $tableName) {
            if (!isset($this->schemaInfoByTable[$tableName])) {
                continue;
            }
            $segments[] = rtrim($this->schemaInfoByTable[$tableName]);
        }

        if (count($segments) === 1) {
            return $this->schemaInfo;
        }

        if ($this->schemaSummaryMatrix !== '') {
            $segments[] = rtrim($this->schemaSummaryMatrix);
        }

        return implode("\n\n", $segments) . "\n";
    }

    public function detectMalformedSql(string $sql): ?string
    {
        $trimmed = trim($sql);
        if ($trimmed === '') {
            return 'SQLが空文字です。';
        }
        if (!preg_match('/^\s*SELECT\b/i', $trimmed)) {
            return 'SELECT文で開始していません。';
        }
        if (preg_match('/^\s*SELECT\b\s*$/i', $trimmed)) {
            return 'SELECT句だけで停止しています。';
        }
        if (!preg_match('/\bFROM\b/i', $trimmed)) {
            return 'FROM句が存在しません。';
        }
        return null;
    }
}
