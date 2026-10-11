<?php

namespace App\Services\Extraction\Readers;

use App\Services\Extraction\Archive;
use App\Services\Extraction\ExtractedDocument;
use App\Services\Extraction\ExtractedSection;
use App\Services\Extraction\ExtractionException;
use App\Services\Extraction\Relationships;
use App\Services\Extraction\Xml;
use DateTimeImmutable;
use DOMElement;
use DOMXPath;

/**
 * Reads a .xlsx.
 *
 * A worksheet only stores the cells that have something in them, so a reader
 * that appends each cell in turn loses the column alignment a spreadsheet is
 * built on. This resolves each cell's real reference (`B3`) to a column and
 * lays the row out against the sheet's column range, so a row keeps its place
 * under its headers even when cells in between are blank.
 *
 * Typed cells are resolved the way Excel stores them: shared and inline
 * strings, booleans, error literals, formulas (using the cached result), and
 * numbers that a style marks as a date.
 */
final class XlsxReader
{
    private const R_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * Excel's built-in format ids that represent a date or time.
     *
     * @var list<int>
     */
    private const DATE_FORMATS = [
        14, 15, 16, 17, 18, 19, 20, 21, 22,
        27, 28, 29, 30, 31, 32, 33, 34, 35, 36,
        45, 46, 47, 50, 51, 52, 53, 54, 55, 56, 57, 58,
    ];

    private bool $date1904 = false;

    /**
     * Rows and cells are budgeted across the whole workbook, so several large
     * sheets cannot add up to more work than one limit allows.
     */
    private int $remainingRows;

    private int $remainingCells;

    private bool $truncated = false;

    private bool $exhausted = false;

    /**
     * @param  array<string, int>  $limits
     */
    public function __construct(array $limits = [])
    {
        $maxRows = (int) ($limits['max_rows'] ?? 200000);
        $maxCells = (int) ($limits['max_cells'] ?? 2000000);

        $this->remainingRows = $maxRows > 0 ? $maxRows : PHP_INT_MAX;
        $this->remainingCells = $maxCells > 0 ? $maxCells : PHP_INT_MAX;
    }

    public function read(Archive $archive): ExtractedDocument
    {
        if (! $archive->has('xl/workbook.xml')) {
            throw new ExtractionException('The .xlsx file has no xl/workbook.xml.');
        }

        $warnings = [];

        $workbook = Xml::load($archive->get('xl/workbook.xml'), 'workbook');
        $xpath = Xml::xpath($workbook);
        $this->date1904 = $this->uses1904($xpath);

        $relationships = Relationships::load($archive, 'xl/_rels/workbook.xml.rels');
        $shared = $this->sharedStrings($archive);
        $dateStyles = $this->dateStyles($archive);

        $sections = [];

        $sheets = $xpath->query('//ss:sheets/ss:sheet');

        if ($sheets->length === 0) {
            $warnings[] = 'The workbook has no worksheets.';
        }

        foreach ($sheets as $sheet) {
            if ($this->exhausted) {
                break;
            }

            if (! $sheet instanceof DOMElement) {
                continue;
            }

            $name = $sheet->getAttribute('name');
            $target = $relationships[$sheet->getAttributeNS(self::R_NS, 'id')]['target'] ?? null;

            if ($target === null) {
                $warnings[] = "Worksheet \"{$name}\" has no file reference.";

                continue;
            }

            $path = Relationships::resolve('xl/', $target);

            if (! $archive->has($path)) {
                $warnings[] = "Worksheet \"{$name}\" is referenced but missing from the file.";

                continue;
            }

            $text = $this->worksheetText($archive, $path, $name, $shared, $dateStyles, $warnings);

            if ($text !== '') {
                $sections[] = new ExtractedSection('worksheet', $text, ['sheet' => $name]);
            }
        }

        if ($this->truncated) {
            $warnings[] = 'The workbook was truncated at the configured row or cell limit; the rest was skipped.';
        }

        return new ExtractedDocument('xlsx', '', $sections, $warnings);
    }

    /**
     * @param  list<string>  $shared
     * @param  array<int, bool>  $dateStyles
     * @param  list<string>  $warnings
     */
    private function worksheetText(
        Archive $archive,
        string $path,
        string $name,
        array $shared,
        array $dateStyles,
        array &$warnings,
    ): string {
        $xpath = Xml::xpath(Xml::load($archive->get($path), 'worksheet'));

        /** @var array<int, array<int, string>> $grid */
        $grid = [];
        $rowFallback = 0;
        $minRow = null;
        $maxRow = 0;
        $minCol = null;
        $maxCol = 0;

        foreach ($xpath->query('//ss:sheetData/ss:row') as $row) {
            if (! $row instanceof DOMElement) {
                continue;
            }

            if (! $this->takeRow()) {
                $this->truncated = true;
                $this->exhausted = true;

                break;
            }

            $rowFallback++;
            $rowNumber = ctype_digit($row->getAttribute('r')) ? (int) $row->getAttribute('r') : $rowFallback;

            $colFallback = -1;

            foreach ($xpath->query('ss:c', $row) as $cell) {
                if (! $cell instanceof DOMElement) {
                    continue;
                }

                if (! $this->takeCell()) {
                    $this->truncated = true;
                    $this->exhausted = true;

                    break 2;
                }

                $col = $this->columnIndex($cell->getAttribute('r'));

                if ($col === null) {
                    $col = ++$colFallback;
                } else {
                    $colFallback = $col;
                }

                $value = $this->cellValue($cell, $xpath, $shared, $dateStyles, $name, $warnings);

                $grid[$rowNumber][$col] = $value;
                $maxRow = max($maxRow, $rowNumber);
                $maxCol = max($maxCol, $col);

                if ($value !== '') {
                    $minRow = $minRow === null ? $rowNumber : min($minRow, $rowNumber);
                    $minCol = $minCol === null ? $col : min($minCol, $col);
                }
            }
        }

        if ($grid === []) {
            return '';
        }

        $minCol ??= 0;
        $minRow ??= 1;

        ksort($grid);

        $lines = [];

        foreach ($grid as $cells) {
            ksort($cells);

            $values = [];

            for ($col = $minCol; $col <= $maxCol; $col++) {
                $values[] = $cells[$col] ?? '';
            }

            while ($values !== [] && end($values) === '') {
                array_pop($values);
            }

            $lines[] = implode("\t", $values);
        }

        $range = $this->dimension($xpath) ?? $this->range($minRow, $minCol, $maxRow, $maxCol);

        return "Worksheet: {$name} ({$range})\n".implode("\n", $lines);
    }

    /**
     * @param  list<string>  $shared
     * @param  array<int, bool>  $dateStyles
     * @param  list<string>  $warnings
     */
    private function cellValue(
        DOMElement $cell,
        DOMXPath $xpath,
        array $shared,
        array $dateStyles,
        string $sheet,
        array &$warnings,
    ): string {
        $type = $cell->getAttribute('t');

        if ($type === 'inlineStr') {
            $text = '';

            foreach ($xpath->query('.//ss:t', $cell) as $run) {
                $text .= $run->nodeValue;
            }

            return $text;
        }

        $value = $xpath->query('ss:v', $cell)->item(0);

        if ($value === null) {
            if ($xpath->query('ss:f', $cell)->item(0) !== null) {
                $warnings[] = sprintf(
                    'Worksheet "%s" has a formula with no cached result%s.',
                    $sheet,
                    $this->refSuffix($cell),
                );
            }

            return '';
        }

        $raw = $value->nodeValue;

        return match ($type) {
            's' => $shared[(int) $raw] ?? '',
            'b' => $raw === '0' ? 'FALSE' : 'TRUE',
            'str', 'e' => $raw,
            default => $this->number($cell, $raw, $dateStyles),
        };
    }

    /**
     * A number, or the date it encodes when the cell's style says it is one.
     * Excel keeps dates as day serials, so without the style check a date would
     * extract as an opaque integer.
     *
     * @param  array<int, bool>  $dateStyles
     */
    private function number(DOMElement $cell, string $raw, array $dateStyles): string
    {
        $style = (int) $cell->getAttribute('s');

        if (($dateStyles[$style] ?? false) && is_numeric($raw)) {
            return $this->formatSerial((float) $raw, $this->date1904);
        }

        return $raw;
    }

    /**
     * Convert an Excel date serial to a readable date, dropping the time when
     * the serial has no fractional part.
     *
     * The 1900 system counts a non-existent 1900-02-29, so serials below 60 use
     * a base one day later to stay aligned with real calendar dates.
     */
    private function formatSerial(float $serial, bool $date1904): string
    {
        $days = (int) floor($serial);

        $base = $date1904
            ? new DateTimeImmutable('1904-01-01')
            : new DateTimeImmutable($days < 60 ? '1899-12-31' : '1899-12-30');

        $date = $base->modify(($days >= 0 ? '+' : '').$days.' days');

        $seconds = (int) round(($serial - $days) * 86400);

        if ($seconds > 0) {
            return $date->modify('+'.$seconds.' seconds')->format('Y-m-d H:i:s');
        }

        return $date->format('Y-m-d');
    }

    /**
     * @return array<int, bool> cell style position to "is a date"
     */
    private function dateStyles(Archive $archive): array
    {
        if (! $archive->has('xl/styles.xml')) {
            return [];
        }

        $xpath = Xml::xpath(Xml::load($archive->get('xl/styles.xml'), 'styles'));

        $custom = [];

        foreach ($xpath->query('//ss:numFmts/ss:numFmt') as $format) {
            if ($format instanceof DOMElement) {
                $custom[(int) $format->getAttribute('numFmtId')] = $format->getAttribute('formatCode');
            }
        }

        $styles = [];
        $position = 0;

        foreach ($xpath->query('//ss:cellXfs/ss:xf') as $xf) {
            $styles[$position++] = $this->isDateFormat((int) $xf->getAttribute('numFmtId'), $custom);
        }

        return $styles;
    }

    /**
     * @param  array<int, string>  $custom
     */
    private function isDateFormat(int $numFmtId, array $custom): bool
    {
        if (in_array($numFmtId, self::DATE_FORMATS, true)) {
            return true;
        }

        $code = $custom[$numFmtId] ?? null;

        if ($code === null) {
            return false;
        }

        // Strip quoted literals, bracketed sections and escaped characters so
        // only the format's own tokens are tested for date characters.
        $code = (string) preg_replace('/"[^"]*"/', '', $code);
        $code = (string) preg_replace('/\[[^\]]*\]/', '', $code);
        $code = (string) preg_replace('/\\\\./', '', $code);

        return preg_match('/[ymdhs]/i', $code) === 1;
    }

    /**
     * @return list<string>
     */
    private function sharedStrings(Archive $archive): array
    {
        if (! $archive->has('xl/sharedStrings.xml')) {
            return [];
        }

        $xpath = Xml::xpath(Xml::load($archive->get('xl/sharedStrings.xml'), 'shared strings'));

        $strings = [];

        foreach ($xpath->query('//ss:si') as $item) {
            $text = '';

            foreach ($xpath->query('.//ss:t', $item) as $run) {
                $text .= $run->nodeValue;
            }

            $strings[] = $text;
        }

        return $strings;
    }

    private function uses1904(DOMXPath $xpath): bool
    {
        $properties = $xpath->query('//ss:workbookPr')->item(0);

        if (! $properties instanceof DOMElement) {
            return false;
        }

        return in_array($properties->getAttribute('date1904'), ['1', 'true'], true);
    }

    private function dimension(DOMXPath $xpath): ?string
    {
        $node = $xpath->query('//ss:dimension')->item(0);

        if (! $node instanceof DOMElement) {
            return null;
        }

        $ref = $node->getAttribute('ref');

        return $ref !== '' ? $ref : null;
    }

    private function range(int $minRow, int $minCol, int $maxRow, int $maxCol): string
    {
        return $this->columnLetter($minCol).$minRow.':'.$this->columnLetter($maxCol).$maxRow;
    }

    /**
     * Spend one unit of the row or cell budget. A zero limit means unlimited.
     */
    private function takeRow(): bool
    {
        if ($this->remainingRows === PHP_INT_MAX) {
            return true;
        }

        if ($this->remainingRows <= 0) {
            return false;
        }

        $this->remainingRows--;

        return true;
    }

    private function takeCell(): bool
    {
        if ($this->remainingCells === PHP_INT_MAX) {
            return true;
        }

        if ($this->remainingCells <= 0) {
            return false;
        }

        $this->remainingCells--;

        return true;
    }

    private function columnIndex(string $ref): ?int
    {
        if (preg_match('/^([A-Za-z]+)\d+$/', trim($ref), $matches) !== 1) {
            return null;
        }

        $letters = strtoupper($matches[1]);
        $index = 0;

        for ($i = 0; $i < strlen($letters); $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }

        return $index - 1;
    }

    private function columnLetter(int $index): string
    {
        $letters = '';
        $index++;

        while ($index > 0) {
            $letters = chr(65 + ($index - 1) % 26).$letters;
            $index = intdiv($index - 1, 26);
        }

        return $letters;
    }

    private function refSuffix(DOMElement $cell): string
    {
        $ref = $cell->getAttribute('r');

        return $ref !== '' ? ' (cell '.$ref.')' : '';
    }
}
