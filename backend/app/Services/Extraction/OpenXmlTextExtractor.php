<?php

namespace App\Services\Extraction;

use App\Services\Extraction\Readers\DocxReader;
use App\Services\Extraction\Readers\PptxReader;
use App\Services\Extraction\Readers\XlsxReader;

/**
 * Reads office documents: .docx, .xlsx and .pptx.
 *
 * All three are zip archives of XML, so this opens the archive once, under the
 * size and expansion limits in config/extraction.php, and hands it to the reader
 * for the extension. The work of understanding each format lives in the reader;
 * this class only validates the extension and manages the archive's lifetime.
 *
 * The legacy binary formats (.doc/.xls/.ppt) are a different container entirely
 * and are deliberately not handled; the caller marks them unsupported.
 */
class OpenXmlTextExtractor
{
    private const EXTENSIONS = ['docx', 'xlsx', 'pptx'];

    public function extract(string $absolutePath, string $extension, ?string $filename = null): ExtractedDocument
    {
        $extension = strtolower($extension);

        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw new ExtractionException("No extractor for .{$extension} files.");
        }

        $limits = config('extraction.limits', []);

        $archive = new Archive($absolutePath, $limits);
        $archive->open();

        try {
            $document = match ($extension) {
                'docx' => (new DocxReader($limits))->read($archive),
                'xlsx' => (new XlsxReader($limits))->read($archive),
                'pptx' => (new PptxReader($limits))->read($archive),
            };
        } finally {
            $archive->close();
        }

        // Readers do not know the name the file was uploaded under; attach it
        // here so the result always carries it for traceability.
        return new ExtractedDocument(
            $document->type,
            $filename ?? basename($absolutePath),
            $document->sections,
            $document->warnings,
        );
    }

    /**
     * Flat-text convenience wrapper for callers that only want the string.
     */
    public function extractText(string $absolutePath, string $extension, ?string $filename = null): string
    {
        return $this->extract($absolutePath, $extension, $filename)->toText();
    }
}
