<?php

namespace App\Services\Extraction;

/**
 * The structured result of reading a file.
 *
 * Extractors return this rather than a bare string so the document type, the
 * source filename, the structural sections and any warnings survive as long as
 * they are useful. `toText()` projects it back down to the flat text the
 * chunking pipeline consumes.
 */
final class ExtractedDocument
{
    /**
     * @param  list<ExtractedSection>  $sections
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly string $type,
        public readonly string $filename,
        public readonly array $sections = [],
        public readonly array $warnings = [],
    ) {}

    public static function plain(string $type, string $filename, string $text): self
    {
        return new self($type, $filename, [new ExtractedSection('text', $text)]);
    }

    /**
     * The flat text that is stored and chunked.
     *
     * Sections keep their own internal newlines; a blank line separates them so
     * paragraph, slide and worksheet boundaries survive into the chunker.
     */
    public function toText(): string
    {
        $parts = [];

        foreach ($this->sections as $section) {
            $text = trim($section->text);

            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode("\n\n", $parts);
    }

    public function isEmpty(): bool
    {
        return trim($this->toText()) === '';
    }
}
