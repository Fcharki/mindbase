<?php

namespace App\Services\Extraction;

/**
 * One structural piece of an extracted document: a paragraph, a slide, a
 * worksheet, a table.
 *
 * The text is the rendered form that may be returned to the caller; location
 * carries the source coordinates (slide number, worksheet name, paragraph
 * index) that let a chunk be traced back to where it came from.
 */
final class ExtractedSection
{
    /**
     * @param  array<string, int|string>  $location
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $text,
        public readonly array $location = [],
    ) {}
}
