<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\EntryChunk;
use App\Models\KnowledgeEntry;
use App\Models\Summary;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ChunkingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChunkingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function entry(string $content, ?string $summary = null): KnowledgeEntry
    {
        $owner = User::factory()->create();

        $entry = KnowledgeEntry::create([
            'workspace_id' => Workspace::create([
                'name' => 'Acme',
                'owner_id' => $owner->id,
            ])->id,
            'created_by' => $owner->id,
            'title' => 'Entry',
            'content' => $content,
        ]);

        if ($summary !== null) {
            Summary::create([
                'knowledge_entry_id' => $entry->id,
                'content_hash' => str_repeat('a', 64),
                'body' => $summary,
                'backend' => 't5',
            ]);

            $entry->load('summary');
        }

        return $entry;
    }

    public function test_it_splits_content_into_several_chunks(): void
    {
        $entry = $this->entry(
            '<p>'.implode('</p><p>', array_fill(0, 120, 'The parser handles nested tables.')).'</p>'
        );

        $chunks = app(ChunkingService::class)->chunksFor($entry);

        $this->assertGreaterThan(1, count($chunks));
    }

    public function test_content_positions_start_at_zero(): void
    {
        $chunks = app(ChunkingService::class)->chunksFor(
            $this->entry('<p>'.implode('</p><p>', array_fill(0, 120, 'Body text.')).'</p>')
        );

        $content = array_values(array_filter($chunks, fn ($chunk) => $chunk['source'] === 'content'));

        $this->assertSame(range(0, count($content) - 1), array_column($content, 'position'));
    }

    public function test_each_source_numbers_its_own_positions(): void
    {
        $chunks = app(ChunkingService::class)->chunksFor(
            $this->entry(
                '<p>'.implode('</p><p>', array_fill(0, 120, 'Body text.')).'</p>',
                'A summary that runs on for a while and therefore takes more than one chunk to hold at the size we use.'
            )
        );

        $summary = array_values(array_filter($chunks, fn ($chunk) => $chunk['source'] === 'summary'));

        // The two sources are unique-constrained together, so a shared counter
        // would be a constraint violation waiting to happen.
        $this->assertNotEmpty($summary);
        $this->assertSame(range(0, count($summary) - 1), array_column($summary, 'position'));
    }

    public function test_it_indexes_the_summary_alongside_the_content(): void
    {
        $chunks = app(ChunkingService::class)->chunksFor(
            $this->entry('<p>Some body text.</p>', 'The overview of the entry.')
        );

        $this->assertSame('summary', $chunks[0]['source']);
        $this->assertSame('The overview of the entry.', $chunks[0]['body']);
    }

    public function test_an_entry_without_a_summary_still_gets_content_chunks(): void
    {
        $chunks = app(ChunkingService::class)->chunksFor($this->entry('<p>Body only.</p>'));

        $this->assertCount(1, $chunks);
        $this->assertSame('content', $chunks[0]['source']);
    }

    public function test_markup_is_stripped_from_the_stored_body(): void
    {
        $chunks = app(ChunkingService::class)->chunksFor(
            $this->entry('<h2>Parser</h2><p>We shipped it.</p>')
        );

        $this->assertSame('Parser We shipped it.', $chunks[0]['body']);
        $this->assertStringNotContainsString('<', $chunks[0]['body']);
    }

    public function test_script_and_style_content_is_dropped(): void
    {
        $chunks = app(ChunkingService::class)->chunksFor(
            $this->entry('<style>.a{color:red}</style><script>alert(1)</script><p>Real prose.</p>')
        );

        $this->assertStringNotContainsString('alert(1)', $chunks[0]['body']);
        $this->assertStringNotContainsString('color:red', $chunks[0]['body']);
        $this->assertStringContainsString('Real prose.', $chunks[0]['body']);
    }

    public function test_words_are_not_glued_together_across_a_tag(): void
    {
        $chunks = app(ChunkingService::class)->chunksFor(
            $this->entry('<p>before</p><p>after</p>')
        );

        $this->assertSame('before after', $chunks[0]['body']);
    }

    public function test_an_entry_of_only_markup_produces_no_chunks(): void
    {
        $this->assertSame([], app(ChunkingService::class)->chunksFor($this->entry('<p>  </p>')));
    }

    public function test_a_sentence_longer_than_the_target_is_cut_rather_than_dropped(): void
    {
        $chunks = app(ChunkingService::class)->split(str_repeat('x', 5000));

        $joined = implode('', $chunks);

        $this->assertCount(3, $chunks);
        $this->assertSame(5000, strlen($joined));
    }

    public function test_consecutive_chunks_share_an_overlap(): void
    {
        $chunks = app(ChunkingService::class)->split(
            implode(' ', array_fill(0, 200, 'A sentence about the parser.'))
        );

        $this->assertGreaterThan(1, count($chunks));
        $this->assertNotSame($chunks[0], $chunks[1]);

        // The tail of one chunk reappears at the head of the next, so an idea
        // spanning the boundary is not indexed as two fragments.
        $tail = substr($chunks[0], -120);

        $this->assertStringContainsString($tail, $chunks[1]);
    }

    public function test_the_overlap_does_not_begin_mid_word(): void
    {
        $chunks = app(ChunkingService::class)->split(
            implode(' ', array_fill(0, 200, 'alpha beta gamma delta'))
        );

        foreach (array_slice($chunks, 1) as $chunk) {
            $this->assertMatchesRegularExpression('/^[a-z]/', $chunk);
        }
    }

    public function test_the_chunk_count_is_capped(): void
    {
        $chunks = app(ChunkingService::class)->split(
            implode(' ', array_fill(0, 20000, 'Padding sentence.'))
        );

        $capped = app(ChunkingService::class)->chunksFor(
            $this->entry('<p>'.implode('</p><p>', array_fill(0, 20000, 'Padding sentence.')).'</p>')
        );

        $this->assertGreaterThan(ChunkingService::MAX_CHUNKS, count($chunks));
        $this->assertCount(ChunkingService::MAX_CHUNKS, $capped);
    }

    public function test_extracted_attachment_text_is_indexed_as_its_own_source(): void
    {
        $entry = $this->entry('<p>Body only.</p>');
        $document = $this->document($entry, Document::EXTRACTION_READY, 'A fact that lives only in the attachment.');

        $chunks = app(ChunkingService::class)->chunksFor($entry);

        $sources = array_column($chunks, 'source');
        $bodies = array_column($chunks, 'body');

        $this->assertContains(EntryChunk::sourceForDocument($document->id), $sources);
        $this->assertContains('A fact that lives only in the attachment.', $bodies);
    }

    public function test_an_attachment_without_extracted_text_is_not_indexed(): void
    {
        $entry = $this->entry('<p>Body only.</p>');
        $this->document($entry, Document::EXTRACTION_PENDING, null);

        $chunks = app(ChunkingService::class)->chunksFor($entry);

        $this->assertSame(['content'], array_column($chunks, 'source'));
    }

    public function test_each_attachment_numbers_its_own_positions(): void
    {
        $entry = $this->entry('<p>Body only.</p>');

        $first = $this->document($entry, Document::EXTRACTION_READY, str_repeat('Attachment one sentence. ', 300));
        $second = $this->document($entry, Document::EXTRACTION_READY, str_repeat('Attachment two sentence. ', 300));

        $chunks = app(ChunkingService::class)->chunksFor($entry);

        foreach ([$first, $second] as $document) {
            $positions = array_column(array_filter(
                $chunks,
                fn ($chunk) => $chunk['source'] === EntryChunk::sourceForDocument($document->id)
            ), 'position');

            $this->assertNotEmpty($positions);
            $this->assertSame(range(0, count($positions) - 1), $positions);
        }
    }

    private function document(KnowledgeEntry $entry, string $status, ?string $text): Document
    {
        return Document::create([
            'knowledge_entry_id' => $entry->id,
            'disk' => 'documents',
            'path' => 'workspaces/1/entries/1/'.uniqid().'.txt',
            'original_name' => 'notes.txt',
            'extraction_status' => $status,
            'extracted_text' => $text,
        ]);
    }
}
