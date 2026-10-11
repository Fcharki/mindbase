<?php

namespace App\Services\Extraction\Readers;

use App\Services\Extraction\Archive;
use App\Services\Extraction\ExtractedDocument;
use App\Services\Extraction\ExtractedSection;
use App\Services\Extraction\ExtractionException;
use App\Services\Extraction\Xml;
use DOMElement;
use DOMXPath;

/**
 * Reads a .docx.
 *
 * The body, headers, footers, footnotes and endnotes are all WordprocessingML,
 * so they are walked with DOM queries rather than string replacement. That is
 * what keeps line breaks, tabs and (crucially) the boundaries between
 * paragraphs, list items and table cells intact: each maps to its own text or
 * its own separator instead of collapsing into one run-on string.
 */
final class DocxReader
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const DEFAULT_MAX_BLOCKS = 200000;

    private int $maxBlocks;

    private int $remainingBlocks;

    private bool $truncated = false;

    /**
     * @param  array<string, int>  $limits
     */
    public function __construct(array $limits = [])
    {
        $this->maxBlocks = (int) ($limits['max_paragraphs'] ?? self::DEFAULT_MAX_BLOCKS);
        $this->remainingBlocks = $this->maxBlocks > 0 ? $this->maxBlocks : PHP_INT_MAX;
    }

    public function read(Archive $archive): ExtractedDocument
    {
        if (! $archive->has('word/document.xml')) {
            throw new ExtractionException('The .docx file has no word/document.xml.');
        }

        $sections = [];

        $body = Xml::load($archive->get('word/document.xml'), 'document');
        $bodyXpath = Xml::xpath($body);
        $container = $bodyXpath->query('/w:document/w:body')->item(0);

        if ($container instanceof DOMElement) {
            $this->appendContainer($sections, $container, $bodyXpath, 'document');
        }

        foreach ($archive->names('#^word/header\d+\.xml$#') as $name) {
            $this->appendLabeledPart($sections, $archive, $name, 'Header', 'header');
        }

        foreach ($archive->names('#^word/footer\d+\.xml$#') as $name) {
            $this->appendLabeledPart($sections, $archive, $name, 'Footer', 'footer');
        }

        if ($archive->has('word/footnotes.xml')) {
            $this->appendNotes($sections, $archive, 'word/footnotes.xml', 'Footnote', 'footnote');
        }

        if ($archive->has('word/endnotes.xml')) {
            $this->appendNotes($sections, $archive, 'word/endnotes.xml', 'Endnote', 'endnote');
        }

        $warnings = [];

        if ($this->truncated) {
            $warnings[] = "Only the first {$this->maxBlocks} paragraphs and tables were read; the rest were skipped.";
        }

        return new ExtractedDocument('docx', '', $sections, $warnings);
    }

    /**
     * A part whose paragraphs and tables all belong under one heading, such as
     * a header or footer. Rendering it as a single section avoids repeating the
     * label on every paragraph.
     *
     * @param  list<ExtractedSection>  $sections
     */
    private function appendLabeledPart(array &$sections, Archive $archive, string $name, string $label, string $kind): void
    {
        $document = Xml::load($archive->get($name), $kind);
        $xpath = Xml::xpath($document);

        $root = $document->documentElement;

        if (! $root instanceof DOMElement) {
            return;
        }

        $parts = [];
        $this->appendContainer($parts, $root, $xpath, $name);

        $text = $this->join($parts);

        if ($text !== '') {
            $sections[] = new ExtractedSection($kind, $label.': '.$text, ['part' => $name]);
        }
    }

    /**
     * Footnotes and endnotes live in one part, each note being its own little
     * container. The separator and continuation stubs Word inserts are skipped.
     *
     * @param  list<ExtractedSection>  $sections
     */
    private function appendNotes(array &$sections, Archive $archive, string $name, string $label, string $kind): void
    {
        $document = Xml::load($archive->get($name), $kind);
        $xpath = Xml::xpath($document);

        foreach ($xpath->query('//w:footnote | //w:endnote') as $note) {
            if (! $note instanceof DOMElement) {
                continue;
            }

            $type = $note->getAttributeNS(self::W_NS, 'type');

            if (in_array($type, ['separator', 'continuationSeparator', 'continuationNotice'], true)) {
                continue;
            }

            $parts = [];
            $this->appendContainer($parts, $note, $xpath, $name);

            $text = $this->join($parts);

            if ($text !== '') {
                $sections[] = new ExtractedSection($kind, $label.': '.$text, ['part' => $name]);
            }
        }
    }

    /**
     * Walk the direct paragraph and table children of a container in document
     * order. Descending only one level keeps a table's own paragraphs from
     * also being emitted as loose body paragraphs.
     *
     * @param  list<ExtractedSection>  $sections
     */
    private function appendContainer(array &$sections, DOMElement $container, DOMXPath $xpath, string $part): void
    {
        $paragraphIndex = 0;
        $tableIndex = 0;

        foreach ($container->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            if ($child->localName === 'p') {
                if (! $this->takeBlock()) {
                    $this->truncated = true;

                    return;
                }

                $paragraphIndex++;
                $parsed = $this->renderParagraph($child, $xpath, $paragraphIndex, $part);

                if ($parsed !== null) {
                    $sections[] = $parsed;
                }
            } elseif ($child->localName === 'tbl') {
                if (! $this->takeBlock()) {
                    $this->truncated = true;

                    return;
                }

                $tableIndex++;
                $text = $this->tableText($child, $xpath);

                if ($text !== '') {
                    $sections[] = new ExtractedSection('table', $text, ['part' => $part, 'table' => $tableIndex]);
                }
            }
        }
    }

    /**
     * Spend one unit of the block budget. A zero limit means unlimited.
     */
    private function takeBlock(): bool
    {
        if ($this->remainingBlocks === PHP_INT_MAX) {
            return true;
        }

        if ($this->remainingBlocks <= 0) {
            return false;
        }

        $this->remainingBlocks--;

        return true;
    }

    private function renderParagraph(DOMElement $paragraph, DOMXPath $xpath, int $index, string $part): ?ExtractedSection
    {
        $text = trim($this->paragraphText($paragraph, $xpath));

        if ($text === '') {
            return null;
        }

        $location = ['part' => $part, 'paragraph' => $index];

        $level = $this->headingLevel($this->style($paragraph, $xpath));

        if ($level > 0) {
            return new ExtractedSection('heading', str_repeat('#', $level).' '.$text, $location);
        }

        if ($this->isListItem($paragraph, $xpath)) {
            return new ExtractedSection('list-item', '- '.$text, $location);
        }

        return new ExtractedSection('paragraph', $text, $location);
    }

    /**
     * Text of a paragraph, honouring the elements that carry meaning but no
     * characters: a break becomes a newline, a tab a tab. Queried in document
     * order so runs and hyperlinks interleave the way they were written.
     */
    private function paragraphText(DOMElement $paragraph, DOMXPath $xpath): string
    {
        $text = '';

        foreach ($xpath->query('.//w:t | .//w:tab | .//w:br | .//w:cr', $paragraph) as $node) {
            $text .= match ($node->localName) {
                'tab' => "\t",
                'br', 'cr' => "\n",
                default => $node->nodeValue,
            };
        }

        return $text;
    }

    private function style(DOMElement $paragraph, DOMXPath $xpath): ?string
    {
        $node = $xpath->query('w:pPr/w:pStyle', $paragraph)->item(0);

        if (! $node instanceof DOMElement) {
            return null;
        }

        $value = $node->getAttributeNS(self::W_NS, 'val');

        return $value !== '' ? $value : ($node->getAttribute('w:val') ?: null);
    }

    private function isListItem(DOMElement $paragraph, DOMXPath $xpath): bool
    {
        return $xpath->query('w:pPr/w:numPr', $paragraph)->item(0) !== null;
    }

    /**
     * "Heading1" through "Heading6" map straight onto markdown levels; "Title"
     * is the document's top level. Anything else is body text.
     */
    private function headingLevel(?string $style): int
    {
        if ($style === null) {
            return 0;
        }

        if (strcasecmp($style, 'Title') === 0) {
            return 1;
        }

        if (preg_match('/^Heading\s*(\d+)$/i', $style, $matches) === 1) {
            return min((int) $matches[1], 6);
        }

        return 0;
    }

    /**
     * A table as pipe-separated rows. Each cell's paragraphs join with a space,
     * so a wrapped cell reads as one cell rather than several.
     */
    private function tableText(DOMElement $table, DOMXPath $xpath): string
    {
        $lines = [];

        foreach ($xpath->query('w:tr', $table) as $row) {
            $cells = [];

            foreach ($xpath->query('w:tc', $row) as $cell) {
                $paragraphs = [];

                foreach ($xpath->query('w:p', $cell) as $paragraph) {
                    $text = trim($this->paragraphText($paragraph, $xpath));

                    if ($text !== '') {
                        $paragraphs[] = $text;
                    }
                }

                $cells[] = implode(' ', $paragraphs);
            }

            $this->trimTrailing($cells);

            if ($cells !== [] && implode('', $cells) !== '') {
                $lines[] = implode(' | ', $cells);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $cells
     */
    private function trimTrailing(array &$cells): void
    {
        while ($cells !== [] && end($cells) === '') {
            array_pop($cells);
        }
    }

    /**
     * @param  list<ExtractedSection>  $sections
     */
    private function join(array $sections): string
    {
        $lines = [];

        foreach ($sections as $section) {
            $lines[] = $section->text;
        }

        return implode("\n", $lines);
    }
}
