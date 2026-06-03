<?php

class CsvSearchService
{
    private $extractor;
    private $metadataCatalog;

    public function __construct(CsvSearchTermExtractor $extractor, CsvMetadataCatalog $metadataCatalog)
    {
        $this->extractor = $extractor;
        $this->metadataCatalog = $metadataCatalog;
    }

    public function findMentionedCsvFileName(string $question): ?string
    {
        return $this->extractor->findMentionedCsvFileName($question, $this->metadataCatalog->loadFiles());
    }

    public function extractSearchTerms(string $question): array
    {
        return $this->extractor->extractCsvSearchTerms($question, $this->metadataCatalog->loadFiles());
    }
}
