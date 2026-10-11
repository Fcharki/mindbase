<?php

namespace App\Services\Extraction;

use App\Models\Document;

/**
 * What came back from trying to read text out of an uploaded file.
 *
 * Keeping the status next to the text distinguishes "the file was blank" from
 * "the file could not be read at all", which an empty string alone cannot.
 */
class ExtractionResult
{
    /**
     * @param  list<string>  $warnings  non-fatal problems found while reading
     */
    private function __construct(
        public readonly string $status,
        public readonly string $text = '',
        public readonly ?string $error = null,
        public readonly array $warnings = [],
    ) {}

    /**
     * @param  list<string>  $warnings
     */
    public static function ready(string $text, array $warnings = []): self
    {
        return new self(Document::EXTRACTION_READY, $text, null, $warnings);
    }

    /**
     * @param  list<string>  $warnings
     */
    public static function empty(array $warnings = []): self
    {
        return new self(Document::EXTRACTION_EMPTY, '', null, $warnings);
    }

    public static function unsupported(string $reason): self
    {
        return new self(Document::EXTRACTION_UNSUPPORTED, '', $reason);
    }

    public static function failed(string $reason): self
    {
        return new self(Document::EXTRACTION_FAILED, '', $reason);
    }

    public function hasText(): bool
    {
        return $this->status === Document::EXTRACTION_READY && trim($this->text) !== '';
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }
}
