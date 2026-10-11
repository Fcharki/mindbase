<?php

namespace App\Services\Extraction;

use App\Models\Document;
use App\Services\DocumentStorageService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Turns an uploaded file into the plain text that gets chunked and embedded.
 *
 * PDFs and office documents go through a dedicated extractor; formats that are
 * already text are read straight from disk. Every path returns a result rather
 * than throwing, because a file nobody can read must not fail the job that
 * indexes its entry.
 */
class TextExtractionService
{
    public function __construct(
        private readonly PdfTextExtractor $pdf,
        private readonly OpenXmlTextExtractor $openXml,
    ) {}

    public function extract(Document $document): ExtractionResult
    {
        $disk = Storage::disk($document->disk ?: DocumentStorageService::DISK);

        if (! $disk->exists($document->path)) {
            return ExtractionResult::failed('The stored file is missing.');
        }

        $extension = $document->extension();

        try {
            if (in_array($extension, ['docx', 'xlsx', 'pptx'], true)) {
                $extracted = $this->openXml->extract(
                    $disk->path($document->path),
                    $extension,
                    $document->original_name,
                );

                $text = $extracted->toText();
                $warnings = $extracted->warnings;
            } else {
                $warnings = [];

                $text = match ($extension) {
                    'pdf' => $this->pdf->extract($disk->path($document->path)),
                    'txt', 'md', 'csv' => $disk->get($document->path),
                    default => null,
                };
            }
        } catch (Throwable $e) {
            Log::warning('Document text extraction failed.', [
                'document_id' => $document->id,
                'extension' => $extension,
                'message' => $e->getMessage(),
            ]);

            return ExtractionResult::failed($e->getMessage());
        }

        // A null text means the format is one we never claimed to read; an empty
        // string means the file was read and had nothing in it. The two are
        // recorded differently so the difference is visible.
        if ($text === null) {
            return ExtractionResult::unsupported(
                $extension === '' ? 'The file has no extension.' : "No extractor for .{$extension} files."
            );
        }

        if ($warnings !== []) {
            Log::warning('Document text extraction completed with warnings.', [
                'document_id' => $document->id,
                'extension' => $extension,
                'warnings' => $warnings,
            ]);
        }

        $text = $this->normalise($text);

        if ($text === '') {
            return ExtractionResult::empty($warnings);
        }

        return ExtractionResult::ready($this->cap($text), $warnings);
    }

    /**
     * Even out the whitespace extraction leaves behind, without flattening the
     * newlines that separate paragraphs, slides and spreadsheet rows, or the
     * tabs that separate table and spreadsheet cells.
     */
    private function normalise(string $text): string
    {
        $text = str_replace("\r\n", "\n", $text);
        $text = preg_replace('/ +/u', ' ', $text) ?? $text;
        $text = preg_replace('/[ \t]*\n[ \t]*/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function cap(string $text): string
    {
        $max = (int) config('extraction.max_characters', 200000);

        if ($max > 0 && mb_strlen($text) > $max) {
            Log::info('Document text truncated before indexing.', [
                'max_characters' => $max,
            ]);

            return mb_substr($text, 0, $max);
        }

        return $text;
    }
}
