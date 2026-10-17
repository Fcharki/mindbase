<?php

namespace Tests\Unit\Services;

use App\Models\KnowledgeEntry;
use App\Services\EntryExportService;
use Tests\TestCase;

class EntryExportServiceTest extends TestCase
{
    private EntryExportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new EntryExportService;
    }

    public function test_block_elements_become_markdown(): void
    {
        $markdown = $this->service->htmlToMarkdown(
            '<h1>Title</h1><h2>Sub</h2><p>Paragraph.</p>'
        );

        $this->assertStringContainsString('# Title', $markdown);
        $this->assertStringContainsString('## Sub', $markdown);
        $this->assertStringContainsString('Paragraph.', $markdown);
    }

    public function test_inline_formatting_is_preserved(): void
    {
        $markdown = $this->service->htmlToMarkdown(
            '<p><strong>bold</strong> and <em>italic</em> and <code>code()</code></p>'
        );

        $this->assertStringContainsString('**bold**', $markdown);
        $this->assertStringContainsString('*italic*', $markdown);
        $this->assertStringContainsString('`code()`', $markdown);
    }

    public function test_lists_and_links_are_converted(): void
    {
        $markdown = $this->service->htmlToMarkdown(
            '<ul><li>one</li><li>two</li></ul>'
            .'<ol><li>first</li><li>second</li></ol>'
            .'<p><a href="https://example.com">Example</a></p>'
        );

        $this->assertStringContainsString('- one', $markdown);
        $this->assertStringContainsString('- two', $markdown);
        $this->assertStringContainsString('1. first', $markdown);
        $this->assertStringContainsString('2. second', $markdown);
        $this->assertStringContainsString('[Example](https://example.com)', $markdown);
    }

    public function test_blockquotes_and_code_blocks_are_converted(): void
    {
        $markdown = $this->service->htmlToMarkdown(
            '<blockquote><p>Quoted</p></blockquote><pre>echo 1;</pre>'
        );

        $this->assertStringContainsString('> Quoted', $markdown);
        $this->assertStringContainsString("```\necho 1;\n```", $markdown);
    }

    public function test_unknown_elements_keep_their_text(): void
    {
        $markdown = $this->service->htmlToMarkdown('<div><span>kept</span></div>');

        $this->assertSame('kept', $markdown);
    }

    public function test_empty_content_is_empty(): void
    {
        $this->assertSame('', $this->service->htmlToMarkdown(''));
        $this->assertSame('', $this->service->htmlToMarkdown('   '));
    }

    public function test_the_json_form_carries_both_renditions(): void
    {
        $entry = new KnowledgeEntry;
        $entry->title = 'Plan';
        $entry->content = '<p>Body</p>';

        $array = $this->service->toArray($entry);

        $this->assertSame('Plan', $array['title']);
        $this->assertSame('<p>Body</p>', $array['content_html']);
        $this->assertSame('Body', $array['content_markdown']);
        $this->assertSame([], $array['tags']);
    }

    public function test_the_pdf_form_is_a_pdf_document(): void
    {
        $entry = new KnowledgeEntry;
        $entry->title = 'Plan';
        $entry->content = '<h1>Body</h1><p>Hello there.</p>';

        $pdf = $this->service->toPdf($entry);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('%%EOF', $pdf);
    }
}
