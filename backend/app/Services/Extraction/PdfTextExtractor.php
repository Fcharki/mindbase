<?php

namespace App\Services\Extraction;

use Smalot\PdfParser\Parser;

/**
 * Reads the text layer out of a PDF.
 *
 * This reads embedded text, it does not OCR. A scanned PDF with no text layer
 * comes back empty, which the caller records as such rather than as an error.
 * Password-protected PDFs raise, and the caller turns that into a failed result.
 */
class PdfTextExtractor
{
    public function extract(string $absolutePath): string
    {
        $document = (new Parser)->parseFile($absolutePath);

        return $document->getText();
    }
}
