<?php

namespace App\Services\Extraction\Readers;

use App\Services\Extraction\Archive;
use App\Services\Extraction\ExtractedDocument;
use App\Services\Extraction\ExtractedSection;
use App\Services\Extraction\ExtractionException;
use App\Services\Extraction\Relationships;
use App\Services\Extraction\Xml;
use DOMElement;
use DOMXPath;

/**
 * Reads a .pptx.
 *
 * Slide order comes from the relationships referenced by `presentation.xml`,
 * not from sorting `slideN.xml` filenames: the numbers are assigned at author
 * time and do not have to reflect the running order. Each slide's title is
 * pulled out first (it is the shape carrying a title placeholder), then the
 * body, tables and any linked charts.
 */
final class PptxReader
{
    private const R_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const DEFAULT_MAX_SLIDES = 5000;

    private int $maxSlides;

    /**
     * @param  array<string, int>  $limits
     */
    public function __construct(array $limits = [])
    {
        $this->maxSlides = (int) ($limits['max_slides'] ?? self::DEFAULT_MAX_SLIDES);
    }

    public function read(Archive $archive): ExtractedDocument
    {
        if (! $archive->has('ppt/presentation.xml')) {
            throw new ExtractionException('The .pptx file has no ppt/presentation.xml.');
        }

        $sections = [];
        $warnings = [];
        $read = 0;

        foreach ($this->slideParts($archive) as $number => $path) {
            if ($this->maxSlides > 0 && $read >= $this->maxSlides) {
                $warnings[] = "Only the first {$this->maxSlides} slides were read; the rest were skipped.";

                break;
            }

            if (! $archive->has($path)) {
                $warnings[] = "Slide {$number} is referenced but missing from the file.";

                continue;
            }

            $this->readSlide($archive, $path, $number, $sections, $warnings);
            $read++;
        }

        return new ExtractedDocument('pptx', '', $sections, $warnings);
    }

    /**
     * The slides in the order the presentation lists them.
     *
     * If the part is unavailable the reader falls back to natural-sorted
     * filenames, which is the best that can be done without the manifest.
     *
     * @return array<int, string> one-based slide number to part path
     */
    private function slideParts(Archive $archive): array
    {
        $relationships = Relationships::load($archive, 'ppt/_rels/presentation.xml.rels');

        if ($relationships === []) {
            return $this->fallbackSlides($archive);
        }

        $xpath = Xml::xpath(Xml::load($archive->get('ppt/presentation.xml'), 'presentation'));

        $slides = [];
        $number = 0;

        foreach ($xpath->query('//p:sldIdLst/p:sldId') as $slideId) {
            if (! $slideId instanceof DOMElement) {
                continue;
            }

            $rid = $slideId->getAttributeNS(self::R_NS, 'id');
            $target = $relationships[$rid]['target'] ?? null;

            if ($target === null) {
                continue;
            }

            $number++;
            $slides[$number] = Relationships::resolve('ppt/', $target);
        }

        return $slides === [] ? $this->fallbackSlides($archive) : $slides;
    }

    /**
     * @return array<int, string>
     */
    private function fallbackSlides(Archive $archive): array
    {
        $names = $archive->names('#^ppt/slides/slide\d+\.xml$#');
        sort($names, SORT_NATURAL);

        $slides = [];

        foreach ($names as $index => $name) {
            $slides[$index + 1] = $name;
        }

        return $slides;
    }

    /**
     * @param  list<ExtractedSection>  $sections
     * @param  list<string>  $warnings
     */
    private function readSlide(Archive $archive, string $path, int $number, array &$sections, array &$warnings): void
    {
        $xpath = Xml::xpath(Xml::load($archive->get($path), 'slide'));

        $title = $this->title($xpath);
        $body = $this->body($xpath);

        $tables = [];
        $charts = [];

        foreach ($xpath->query('//a:tbl') as $table) {
            if ($table instanceof DOMElement) {
                $text = $this->tableText($table, $xpath);

                if ($text !== '') {
                    $tables[] = $text;
                }
            }
        }

        foreach ($this->charts($archive, $path, $number, $warnings) as $chart) {
            $charts[] = $chart;
        }

        if ($title === '' && $body === '' && $tables === [] && $charts === []) {
            return;
        }

        $sections[] = new ExtractedSection(
            $title === '' ? 'slide' : 'title',
            $title === '' ? "Slide {$number}" : "Slide {$number}: {$title}",
            ['slide' => $number],
        );

        if ($body !== '') {
            $sections[] = new ExtractedSection('slide-body', $body, ['slide' => $number]);
        }

        foreach ($tables as $table) {
            $sections[] = new ExtractedSection('table', $table, ['slide' => $number]);
        }

        foreach ($charts as $chart) {
            $sections[] = new ExtractedSection('chart', $chart, ['slide' => $number]);
        }
    }

    private function title(DOMXPath $xpath): string
    {
        foreach ($xpath->query('//p:sp') as $shape) {
            if (! $shape instanceof DOMElement || ! $this->isTitle($shape, $xpath)) {
                continue;
            }

            $parts = [];

            foreach ($xpath->query('.//a:p', $shape) as $paragraph) {
                $text = trim($this->paragraphText($paragraph, $xpath));

                if ($text !== '') {
                    $parts[] = $text;
                }
            }

            if ($parts !== []) {
                return implode(' ', $parts);
            }
        }

        return '';
    }

    /**
     * Body text of every non-title shape, as paragraphs joined by newlines.
     */
    private function body(DOMXPath $xpath): string
    {
        $lines = [];

        foreach ($xpath->query('//p:sp') as $shape) {
            if (! $shape instanceof DOMElement || $this->isTitle($shape, $xpath)) {
                continue;
            }

            foreach ($xpath->query('.//a:p', $shape) as $paragraph) {
                $text = trim($this->paragraphText($paragraph, $xpath));

                if ($text !== '') {
                    $lines[] = $text;
                }
            }
        }

        return implode("\n", $lines);
    }

    private function isTitle(DOMElement $shape, DOMXPath $xpath): bool
    {
        $placeholder = $xpath->query('p:nvSpPr/p:nvPr/p:ph', $shape)->item(0);

        if (! $placeholder instanceof DOMElement) {
            return false;
        }

        return in_array($placeholder->getAttribute('type'), ['title', 'ctrTitle'], true);
    }

    private function paragraphText(DOMElement $paragraph, DOMXPath $xpath): string
    {
        $text = '';

        foreach ($xpath->query('.//a:t | .//a:br', $paragraph) as $node) {
            $text .= $node->localName === 'br' ? "\n" : $node->nodeValue;
        }

        return $text;
    }

    private function tableText(DOMElement $table, DOMXPath $xpath): string
    {
        $lines = [];

        foreach ($xpath->query('a:tr', $table) as $row) {
            $cells = [];

            foreach ($xpath->query('a:tc', $row) as $cell) {
                $parts = [];

                foreach ($xpath->query('.//a:p', $cell) as $paragraph) {
                    $text = trim($this->paragraphText($paragraph, $xpath));

                    if ($text !== '') {
                        $parts[] = $text;
                    }
                }

                $cells[] = implode(' ', $parts);
            }

            if ($cells !== [] && implode('', $cells) !== '') {
                $lines[] = implode(' | ', $cells);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Chart parts linked from the slide. Charts are their own XML with their
     * own relationships, so a chart is read through the slide's `.rels`.
     *
     * @param  list<string>  $warnings
     * @return list<string>
     */
    private function charts(Archive $archive, string $slidePath, int $number, array &$warnings): array
    {
        $directory = dirname($slidePath);
        $relsPath = $directory.'/_rels/'.basename($slidePath).'.rels';

        $charts = [];

        foreach (Relationships::load($archive, $relsPath) as $relationship) {
            if (! str_ends_with($relationship['type'], '/chart')) {
                continue;
            }

            $chartPath = Relationships::resolve($directory.'/', $relationship['target']);

            if (! $archive->has($chartPath)) {
                continue;
            }

            try {
                $text = $this->chartText(Xml::load($archive->get($chartPath), 'chart'));
            } catch (ExtractionException $exception) {
                $warnings[] = "A chart on slide {$number} could not be read.";

                continue;
            }

            if ($text !== '') {
                $charts[] = $text;
            }
        }

        return $charts;
    }

    private function chartText(\DOMDocument $chart): string
    {
        $xpath = Xml::xpath($chart);

        $lines = [];

        $title = $xpath->query('//c:title//a:t')->item(0);

        if ($title !== null && trim($title->nodeValue) !== '') {
            $lines[] = 'Chart: '.trim($title->nodeValue);
        }

        $series = [];

        foreach ($xpath->query('//c:ser/c:tx//c:v') as $name) {
            $text = trim($name->nodeValue);

            if ($text !== '') {
                $series[] = $text;
            }
        }

        if ($series !== []) {
            $lines[] = 'Series: '.implode(', ', $series);
        }

        return implode("\n", $lines);
    }
}
