<?php

namespace Tests\Feature;

use App\Services\Extraction\ExtractedDocument;
use App\Services\Extraction\ExtractionException;
use App\Services\Extraction\OpenXmlTextExtractor;
use Tests\TestCase;
use ZipArchive;

class OpenXmlTextExtractorTest extends TestCase
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const A = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    private const P = 'http://schemas.openxmlformats.org/presentationml/2006/main';

    private const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const SS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/km_openxml_'.uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_a_docx_keeps_its_structure(): void
    {
        $path = $this->archive('docx', [
            'word/document.xml' => $this->documentXml(),
            'word/header1.xml' => '<?xml version="1.0"?>'
                .'<w:hdr xmlns:w="'.self::W.'"><w:p><w:r><w:t>Confidential</w:t></w:r></w:p></w:hdr>',
            'word/footnotes.xml' => '<?xml version="1.0"?>'
                .'<w:footnotes xmlns:w="'.self::W.'">'
                .'<w:footnote w:type="separator"><w:p><w:r><w:t>do not keep</w:t></w:r></w:p></w:footnote>'
                .'<w:footnote w:id="1"><w:p><w:r><w:t>A note</w:t></w:r></w:p></w:footnote>'
                .'</w:footnotes>',
        ]);

        $text = $this->extractor()->extract($path, 'docx')->toText();

        $this->assertStringContainsString('# Report', $text);
        $this->assertStringContainsString('# Intro', $text);
        $this->assertStringContainsString('- First point', $text);
        $this->assertStringContainsString("Body text\nafter break", $text);
        $this->assertStringContainsString("Name | Role\nAlice | Lead", $text);
        $this->assertStringContainsString('Header: Confidential', $text);
        $this->assertStringContainsString('Footnote: A note', $text);
        $this->assertStringNotContainsString('do not keep', $text);
    }

    public function test_a_docx_with_an_empty_body_is_empty(): void
    {
        $path = $this->archive('docx', [
            'word/document.xml' => '<?xml version="1.0"?>'
                .'<w:document xmlns:w="'.self::W.'"><w:body></w:body></w:document>',
        ]);

        $document = $this->extractor()->extract($path, 'docx');

        $this->assertInstanceOf(ExtractedDocument::class, $document);
        $this->assertTrue($document->isEmpty());
    }

    public function test_a_docx_with_malformed_xml_raises(): void
    {
        $path = $this->archive('docx', [
            'word/document.xml' => 'this is not xml',
        ]);

        $this->expectException(ExtractionException::class);

        $this->extractor()->extract($path, 'docx');
    }

    public function test_a_pptx_follows_the_presentation_order_and_separates_titles(): void
    {
        $path = $this->archive('pptx', [
            'ppt/presentation.xml' => '<?xml version="1.0"?>'
                .'<p:presentation xmlns:p="'.self::P.'" xmlns:r="'.self::R.'"><p:sldIdLst>'
                .'<p:sldId id="256" r:id="rId3"/>'
                .'<p:sldId id="257" r:id="rId1"/>'
                .'<p:sldId id="258" r:id="rId2"/>'
                .'</p:sldIdLst></p:presentation>',
            'ppt/_rels/presentation.xml.rels' => '<?xml version="1.0"?>'
                .'<Relationships xmlns="'.self::REL.'">'
                .'<Relationship Id="rId1" Type=".../slide" Target="slides/slide1.xml"/>'
                .'<Relationship Id="rId2" Type=".../slide" Target="slides/slide2.xml"/>'
                .'<Relationship Id="rId3" Type=".../slide" Target="slides/slide3.xml"/>'
                .'</Relationships>',
            'ppt/slides/slide1.xml' => $this->slideXml('SLIDE ONE', 'Body one'),
            'ppt/slides/slide2.xml' => $this->slideXml('SLIDE TWO', 'Body two'),
            'ppt/slides/slide3.xml' => $this->slideXml('SLIDE THREE', 'Body three'),
        ]);

        $document = $this->extractor()->extract($path, 'pptx');
        $text = $document->toText();

        $this->assertStringContainsString('Slide 1: SLIDE THREE', $text);
        $this->assertStringContainsString('Slide 2: SLIDE ONE', $text);
        $this->assertStringContainsString('Slide 3: SLIDE TWO', $text);

        $this->assertLessThan(
            strpos($text, 'SLIDE ONE'),
            strpos($text, 'SLIDE THREE'),
        );

        $this->assertSame('title', $document->sections[0]->kind);
        $this->assertSame(1, $document->sections[0]->location['slide']);
    }

    public function test_an_xlsx_resolves_shared_strings_and_keeps_columns_aligned(): void
    {
        $path = $this->archive('xlsx', [
            'xl/workbook.xml' => '<?xml version="1.0"?>'
                .'<workbook xmlns="'.self::SS.'" xmlns:r="'.self::R.'"><sheets>'
                .'<sheet name="Data" sheetId="1" r:id="rId1"/>'
                .'</sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?>'
                .'<Relationships xmlns="'.self::REL.'">'
                .'<Relationship Id="rId1" Type=".../worksheet" Target="worksheets/sheet1.xml"/>'
                .'</Relationships>',
            'xl/sharedStrings.xml' => '<?xml version="1.0"?>'
                .'<sst xmlns="'.self::SS.'">'
                .'<si><t>Name</t></si><si><t>Alice</t></si><si><t>Active</t></si>'
                .'</sst>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?>'
                .'<worksheet xmlns="'.self::SS.'"><sheetData>'
                .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c></row>'
                .'<row r="2"><c r="A2" t="inlineStr"><is><t>Bob</t></is></c><c r="C2" t="b"><v>1</v></c></row>'
                .'<row r="3"><c r="A3"><v>42</v></c><c r="B3"><f>1+1</f><v>2</v></c></row>'
                .'</sheetData></worksheet>',
        ]);

        $text = $this->extractor()->extract($path, 'xlsx')->toText();

        $this->assertStringContainsString('Worksheet: Data (A1:C3)', $text);
        $this->assertStringContainsString("Name\tAlice\tActive", $text);
        $this->assertStringContainsString("Bob\t\tTRUE", $text);
        $this->assertStringContainsString("42\t2", $text);
    }

    public function test_an_xlsx_formats_date_serials(): void
    {
        $path = $this->archive('xlsx', $this->xlsxWith(
            '<?xml version="1.0"?>'
            .'<styleSheet xmlns="'.self::SS.'">'
            .'<cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="14"/></cellXfs>'
            .'</styleSheet>',
            '<?xml version="1.0"?>'
            .'<worksheet xmlns="'.self::SS.'"><sheetData>'
            .'<row r="1"><c r="A1" s="1"><v>45000</v></c></row>'
            .'</sheetData></worksheet>',
        ));

        $text = $this->extractor()->extract($path, 'xlsx')->toText();

        $this->assertStringContainsString('2023-03-15', $text);
    }

    public function test_an_xlsx_warns_about_a_formula_without_a_cached_result(): void
    {
        $path = $this->archive('xlsx', $this->xlsxWith(
            null,
            '<?xml version="1.0"?>'
            .'<worksheet xmlns="'.self::SS.'"><sheetData>'
            .'<row r="1"><c r="A1"><f>SUM(B1:B2)</f></c></row>'
            .'</sheetData></worksheet>',
        ));

        $document = $this->extractor()->extract($path, 'xlsx');

        $this->assertNotEmpty($document->warnings);
        $this->assertStringContainsString('no cached result', $document->warnings[0]);
        $this->assertStringContainsString('A1', $document->warnings[0]);
    }

    public function test_a_docx_over_the_block_limit_is_truncated_with_a_warning(): void
    {
        config(['extraction.limits' => ['max_paragraphs' => 1]]);

        $path = $this->archive('docx', [
            'word/document.xml' => $this->documentXml(),
        ]);

        $document = $this->extractor()->extract($path, 'docx');
        $text = $document->toText();

        $this->assertStringContainsString('# Report', $text);
        $this->assertStringNotContainsString('Intro', $text);
        $this->assertNotEmpty($document->warnings);
        $this->assertStringContainsString('first 1 paragraphs', $document->warnings[0]);
    }

    public function test_a_worksheet_over_the_row_limit_is_truncated_with_a_warning(): void
    {
        config(['extraction.limits' => ['max_rows' => 1]]);

        $path = $this->archive('xlsx', $this->xlsxWith(
            null,
            '<?xml version="1.0"?>'
            .'<worksheet xmlns="'.self::SS.'"><sheetData>'
            .'<row r="1"><c r="A1" t="inlineStr"><is><t>Row one</t></is></c></row>'
            .'<row r="2"><c r="A2" t="inlineStr"><is><t>Row two</t></is></c></row>'
            .'</sheetData></worksheet>',
        ));

        $document = $this->extractor()->extract($path, 'xlsx');
        $text = $document->toText();

        $this->assertStringContainsString('Row one', $text);
        $this->assertStringNotContainsString('Row two', $text);
        $this->assertNotEmpty($document->warnings);
        $this->assertStringContainsString('truncated', $document->warnings[0]);
    }

    public function test_a_pptx_over_the_slide_limit_is_truncated_with_a_warning(): void
    {
        config(['extraction.limits' => ['max_slides' => 1]]);

        $path = $this->archive('pptx', [
            'ppt/presentation.xml' => '<?xml version="1.0"?>'
                .'<p:presentation xmlns:p="'.self::P.'" xmlns:r="'.self::R.'"><p:sldIdLst>'
                .'<p:sldId id="256" r:id="rId1"/>'
                .'<p:sldId id="257" r:id="rId2"/>'
                .'</p:sldIdLst></p:presentation>',
            'ppt/_rels/presentation.xml.rels' => '<?xml version="1.0"?>'
                .'<Relationships xmlns="'.self::REL.'">'
                .'<Relationship Id="rId1" Type=".../slide" Target="slides/slide1.xml"/>'
                .'<Relationship Id="rId2" Type=".../slide" Target="slides/slide2.xml"/>'
                .'</Relationships>',
            'ppt/slides/slide1.xml' => $this->slideXml('KEEP', 'Body one'),
            'ppt/slides/slide2.xml' => $this->slideXml('DROP', 'Body two'),
        ]);

        $document = $this->extractor()->extract($path, 'pptx');
        $text = $document->toText();

        $this->assertStringContainsString('KEEP', $text);
        $this->assertStringNotContainsString('DROP', $text);
        $this->assertNotEmpty($document->warnings);
        $this->assertStringContainsString('first 1 slides', $document->warnings[0]);
    }

    public function test_a_file_that_is_not_an_archive_raises(): void
    {
        $path = $this->dir.'/plain.docx';
        file_put_contents($path, 'this is not a zip archive');

        $this->expectException(ExtractionException::class);

        $this->extractor()->extract($path, 'docx');
    }

    public function test_archive_expansion_limits_are_enforced(): void
    {
        config(['extraction.limits' => [
            'max_file_bytes' => 0,
            'max_entries' => 1,
            'max_uncompressed_bytes' => 0,
            'max_part_bytes' => 0,
        ]]);

        $path = $this->archive('docx', [
            'word/document.xml' => $this->documentXml(),
            'word/styles.xml' => '<x/>',
        ]);

        $this->expectException(ExtractionException::class);

        $this->extractor()->extract($path, 'docx');
    }

    public function test_an_unsupported_extension_raises(): void
    {
        $this->expectException(ExtractionException::class);

        $this->extractor()->extract($this->dir.'/x.doc', 'doc');
    }

    private function documentXml(): string
    {
        return '<?xml version="1.0"?>'
            .'<w:document xmlns:w="'.self::W.'"><w:body>'
            .'<w:p><w:pPr><w:pStyle w:val="Title"/></w:pPr><w:r><w:t>Report</w:t></w:r></w:p>'
            .'<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Intro</w:t></w:r></w:p>'
            .'<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>First point</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t>Body text</w:t></w:r><w:br/><w:r><w:t>after break</w:t></w:r></w:p>'
            .'<w:tbl>'
            .'<w:tr><w:tc><w:p><w:r><w:t>Name</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Role</w:t></w:r></w:p></w:tc></w:tr>'
            .'<w:tr><w:tc><w:p><w:r><w:t>Alice</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Lead</w:t></w:r></w:p></w:tc></w:tr>'
            .'</w:tbl>'
            .'</w:body></w:document>';
    }

    private function slideXml(string $title, string $body): string
    {
        return '<?xml version="1.0"?>'
            .'<p:sld xmlns:a="'.self::A.'" xmlns:p="'.self::P.'"><p:cSld><p:spTree>'
            .'<p:sp><p:nvSpPr><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr>'
            .'<p:txBody><a:p><a:r><a:t>'.$title.'</a:t></a:r></a:p></p:txBody></p:sp>'
            .'<p:sp><p:nvSpPr><p:nvPr/></p:nvSpPr>'
            .'<p:txBody><a:p><a:r><a:t>'.$body.'</a:t></a:r></a:p></p:txBody></p:sp>'
            .'</p:spTree></p:cSld></p:sld>';
    }

    /**
     * A minimal workbook pointing at a single worksheet, with optional styles.
     *
     * @return array<string, string>
     */
    private function xlsxWith(?string $styles, string $worksheet): array
    {
        $entries = [
            'xl/workbook.xml' => '<?xml version="1.0"?>'
                .'<workbook xmlns="'.self::SS.'" xmlns:r="'.self::R.'"><sheets>'
                .'<sheet name="Data" sheetId="1" r:id="rId1"/>'
                .'</sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?>'
                .'<Relationships xmlns="'.self::REL.'">'
                .'<Relationship Id="rId1" Type=".../worksheet" Target="worksheets/sheet1.xml"/>'
                .'</Relationships>',
            'xl/worksheets/sheet1.xml' => $worksheet,
        ];

        if ($styles !== null) {
            $entries['xl/styles.xml'] = $styles;
        }

        return $entries;
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function archive(string $extension, array $entries): string
    {
        $path = $this->dir.'/sample.'.$extension;

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return $path;
    }

    private function extractor(): OpenXmlTextExtractor
    {
        return new OpenXmlTextExtractor;
    }
}
