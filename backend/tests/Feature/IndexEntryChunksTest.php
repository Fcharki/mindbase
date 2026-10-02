<?php

namespace Tests\Feature;

use App\Jobs\IndexEntryChunks;
use App\Models\EntryChunk;
use App\Models\KnowledgeEntry;
use App\Models\Summary;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\EmbeddingService;
use App\Services\ChunkingService;
use App\Services\EntrySummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A stand-in for the embedding service that returns a distinct unit length
 * vector per input, so a stored chunk can be traced back to the text it came
 * from without loading a model during the test run.
 */
class FakeEmbeddingService extends EmbeddingService
{
    /** @var list<list<string>> */
    public array $batches = [];

    /**
     * Text the service should answer with an empty vector for, standing in for
     * a chunk the model has nothing to say about.
     *
     * @var list<string>
     */
    public array $emptyFor = [];

    public function __construct(private readonly int $dimension = 4) {}

    public function embedMany(array $texts): array
    {
        $texts = array_values($texts);

        $this->batches[] = $texts;

        return array_map(
            fn (string $text) => in_array($text, $this->emptyFor, true)
                ? []
                : $this->vectorFor($text),
            $texts,
        );
    }

    private function vectorFor(string $text): array
    {
        $vector = array_fill(0, $this->dimension, 0.0);
        $vector[abs(crc32($text)) % $this->dimension] = 1.0;

        return $vector;
    }
}

class IndexEntryChunksTest extends TestCase
{
    use RefreshDatabase;

    private function entry(string $content = '<p>The parser handles nested tables.</p>'): KnowledgeEntry
    {
        $owner = User::factory()->create();

        return KnowledgeEntry::create([
            'workspace_id' => Workspace::create([
                'name' => 'Acme',
                'owner_id' => $owner->id,
            ])->id,
            'created_by' => $owner->id,
            'title' => 'Entry',
            'content' => $content,
        ]);
    }

    private function fake(): FakeEmbeddingService
    {
        $service = new FakeEmbeddingService;

        $this->app->instance(EmbeddingService::class, $service);

        return $service;
    }

    private function index(KnowledgeEntry $entry, string $hash): void
    {
        (new IndexEntryChunks($entry->id, $hash))->handle(
            $this->app->make(ChunkingService::class),
            $this->app->make(EmbeddingService::class),
            $this->app->make(EntrySummaryService::class),
        );
    }

    /**
     * A fenced code block with nothing else in it.
     *
     * This is the fixture that used to break indexing. flatten() strips HTML
     * tags but leaves markdown fences alone, so the chunk reaches the AI
     * service as prose that the fence syntax then swallows whole. bge-m3 answers
     * with an empty vector for what is left, and one such chunk raised inside
     * embedMany and took the entry's entire index down with it, three retries
     * in.
     */
    private function fencedCodeOnly(): string
    {
        return "```sql\n".str_repeat('SELECT id FROM orders; ', 90).'```';
    }

    public function test_it_stores_a_vector_for_each_chunk(): void
    {
        $entry = $this->entry();
        $hashes = app(EntrySummaryService::class);
        $this->fake();

        $this->index($entry, $hashes->hashFor($entry->content));

        $chunks = EntryChunk::where('knowledge_entry_id', $entry->id)->get();

        $this->assertNotEmpty($chunks);

        foreach ($chunks as $chunk) {
            $this->assertNotEmpty($chunk->body);
            $this->assertNotEmpty($chunk->embedding);
        }
    }

    public function test_it_indexes_the_summary_and_the_content_separately(): void
    {
        $entry = $this->entry();
        $hashes = app(EntrySummaryService::class);

        Summary::create([
            'knowledge_entry_id' => $entry->id,
            'content_hash' => $hashes->hashFor($entry->content),
            'body' => 'The overview.',
            'backend' => 't5',
        ]);

        $this->fake();
        $this->index($entry->load('summary'), $hashes->hashFor($entry->content));

        $sources = EntryChunk::where('knowledge_entry_id', $entry->id)->pluck('source')->all();

        $this->assertContains('summary', $sources);
        $this->assertContains('content', $sources);
    }

    public function test_it_sends_every_chunk_in_one_request(): void
    {
        $entry = $this->entry('<p>'.implode('</p><p>', array_fill(0, 120, 'Body text.')).'</p>');
        $hashes = app(EntrySummaryService::class);
        $service = $this->fake();

        $this->index($entry, $hashes->hashFor($entry->content));

        // One batch, not one request per chunk: the model takes a minute to load.
        $this->assertCount(1, $service->batches);
        $this->assertSame(
            EntryChunk::where('knowledge_entry_id', $entry->id)->count(),
            count($service->batches[0]),
        );
    }

    public function test_it_stores_the_revision_the_chunks_came_from(): void
    {
        $entry = $this->entry();
        $hashes = app(EntrySummaryService::class);
        $this->fake();

        $this->index($entry, $hashes->hashFor($entry->content));

        $this->assertSame(
            $hashes->hashFor($entry->content),
            EntryChunk::where('knowledge_entry_id', $entry->id)->first()->content_hash,
        );
    }

    public function test_a_job_for_a_superseded_revision_indexes_nothing(): void
    {
        $entry = $this->entry();
        $hashes = app(EntrySummaryService::class);
        $this->fake();

        // The hash of the old text, against an entry that has since been edited.
        $this->index($entry, $hashes->hashFor('Something else entirely.'));

        $this->assertSame(0, EntryChunk::where('knowledge_entry_id', $entry->id)->count());
    }

    public function test_a_deleted_entry_is_skipped(): void
    {
        $entry = $this->entry();
        $hashes = app(EntrySummaryService::class);
        $this->fake();
        $hash = $hashes->hashFor($entry->content);

        $entry->delete();

        $this->index($entry, $hash);

        $this->assertSame(0, EntryChunk::count());
    }

    public function test_reindexing_replaces_the_previous_chunks(): void
    {
        $entry = $this->entry();
        $hashes = app(EntrySummaryService::class);
        $this->fake();

        $long = '<p>'.implode('</p><p>', array_fill(0, 300, 'Body text.')).'</p>';
        $entry->update(['content' => $long]);
        $this->index($entry->load('summary'), $hashes->hashFor($long));

        $this->assertGreaterThan(1, EntryChunk::where('knowledge_entry_id', $entry->id)->count());

        $short = '<p>Now much shorter.</p>';
        $entry->update(['content' => $short]);
        $this->index($entry->load('summary'), $hashes->hashFor($short));

        // Chunks from the longer revision would otherwise keep answering
        // searches with text the entry no longer contains.
        $this->assertSame(1, EntryChunk::where('knowledge_entry_id', $entry->id)->count());
    }

    public function test_an_entry_of_only_markup_leaves_no_chunks_behind(): void
    {
        $entry = $this->entry();
        $hashes = app(EntrySummaryService::class);
        $this->fake();

        $this->index($entry, $hashes->hashFor($entry->content));
        $this->assertNotEmpty(EntryChunk::where('knowledge_entry_id', $entry->id)->get());

        $entry->update(['content' => '<p>  </p>']);
        $this->index($entry->load('summary'), $hashes->hashFor('<p>  </p>'));

        $this->assertSame(0, EntryChunk::where('knowledge_entry_id', $entry->id)->count());
    }

    public function test_the_vector_is_not_serialised(): void
    {
        $entry = $this->entry();
        $hashes = app(EntrySummaryService::class);
        $this->fake();

        $this->index($entry, $hashes->hashFor($entry->content));

        $chunk = EntryChunk::where('knowledge_entry_id', $entry->id)->first()->toArray();

        $this->assertArrayNotHasKey('embedding', $chunk);
        $this->assertArrayHasKey('body', $chunk);
    }

    public function test_chunks_are_removed_when_the_entry_is_deleted(): void
    {
        $entry = $this->entry();
        $hashes = app(EntrySummaryService::class);
        $this->fake();

        $this->index($entry, $hashes->hashFor($entry->content));
        $this->assertNotEmpty(EntryChunk::where('knowledge_entry_id', $entry->id)->get());

        $entry->delete();

        $this->assertSame(0, EntryChunk::where('knowledge_entry_id', $entry->id)->count());
    }

    public function test_one_unembeddable_chunk_does_not_cost_the_entry_its_index(): void
    {
        $entry = $this->entry($this->fencedCodeOnly());
        $hashes = app(EntrySummaryService::class);
        $service = $this->fake();

        $this->index($entry, $hashes->hashFor($entry->content));

        // The entry really does produce more than one chunk, so there is
        // something left for the second one to be.
        $sent = $service->batches[0];
        $this->assertGreaterThan(1, count($sent));

        // The service answers with nothing for the chunk the fence swallowed.
        $service->emptyFor = [$sent[0]];

        $this->index($entry->load('summary'), $hashes->hashFor($entry->content));

        $this->assertSame(
            count($sent) - 1,
            EntryChunk::where('knowledge_entry_id', $entry->id)->count(),
        );
    }

    public function test_an_unembeddable_chunk_leaves_a_gap_rather_than_colliding(): void
    {
        // Distinct sentences, so the chunks are distinct. Repeating one sentence
        // makes the overlapping pieces byte-identical, and dropping one of them
        // would drop both.
        $body = implode('</p><p>', array_map(
            static fn (int $n): string => "Sentence number {$n} carries a distinct fact.",
            range(1, 120),
        ));

        $entry = $this->entry('<p>'.$body.'</p>');

        $hashes = app(EntrySummaryService::class);
        $service = $this->fake();

        $this->index($entry, $hashes->hashFor($entry->content));

        $sent = $service->batches[0];
        $this->assertGreaterThan(2, count($sent));
        $this->assertSame(count($sent), count(array_unique($sent)), 'Chunks should be distinct.');

        $service->emptyFor = [$sent[1]];

        $this->index($entry->load('summary'), $hashes->hashFor($entry->content));

        $positions = EntryChunk::where('knowledge_entry_id', $entry->id)
            ->orderBy('position')
            ->pluck('position')
            ->all();

        // Positions are assigned before embedding, so a survivor after the
        // dropped one keeps its own number rather than shifting down into a
        // duplicate. The insert would fail on the unique index if it did.
        $this->assertNotContains(1, $positions);
        $this->assertContains(2, $positions);
        $this->assertSame(count($sent) - 1, count($positions));
    }

    public function test_an_entry_with_nothing_embeddable_stores_no_rows_without_failing(): void
    {
        $entry = $this->entry();
        $hashes = app(EntrySummaryService::class);
        $service = $this->fake();

        $service->emptyFor = ['The parser handles nested tables.'];

        // No exception: an entry the model cannot read is empty, not broken.
        $this->index($entry, $hashes->hashFor($entry->content));

        $this->assertSame(0, EntryChunk::where('knowledge_entry_id', $entry->id)->count());
    }

    public function test_reindexing_clears_chunks_that_lost_their_vector(): void
    {
        $entry = $this->entry();
        $hashes = app(EntrySummaryService::class);
        $service = $this->fake();

        $this->index($entry, $hashes->hashFor($entry->content));
        $this->assertNotEmpty(EntryChunk::where('knowledge_entry_id', $entry->id)->get());

        $service->emptyFor = $service->batches[0];

        $this->index($entry->load('summary'), $hashes->hashFor($entry->content));

        // Rows are replaced wholesale, so a chunk that lost its vector does not
        // linger to answer searches with a stale row.
        $this->assertSame(0, EntryChunk::where('knowledge_entry_id', $entry->id)->count());
    }
}
