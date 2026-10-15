<?php

namespace Tests\Feature;

use App\Jobs\IndexEntryChunks;
use App\Models\EntryChunk;
use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\EmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The backfill for entries that never made it into the index.
 *
 * An entry created through the API is indexed by a queued job; one seeded,
 * imported, or caught by a failed job is not, and hybrid search then answers
 * every question about it with "no matching entries". This command is how those
 * entries are recovered.
 */
class ReindexEntriesCommandTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();

        $this->workspace = Workspace::create([
            'name' => 'Acme',
            'owner_id' => $this->owner->id,
        ]);
    }

    private function entry(string $title = 'DNS notes'): KnowledgeEntry
    {
        return KnowledgeEntry::create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->owner->id,
            'title' => $title,
            'content' => '<p>DNS is the domain name system.</p>',
        ]);
    }

    private function chunkFor(KnowledgeEntry $entry): EntryChunk
    {
        $chunk = new EntryChunk([
            'knowledge_entry_id' => $entry->id,
            'source' => EntryChunk::SOURCE_CONTENT,
            'position' => 0,
            'content_hash' => hash('sha256', (string) $entry->content),
            'body' => 'DNS is the domain name system.',
            'embedding' => array_fill(0, config('ai.embedding_dimension'), 0.1),
        ]);

        $chunk->save();

        return $chunk;
    }

    public function test_it_queues_indexing_for_entries_without_chunks(): void
    {
        Queue::fake();

        $entry = $this->entry();

        $this->artisan('entries:reindex')->assertSuccessful();

        Queue::assertPushed(
            IndexEntryChunks::class,
            fn (IndexEntryChunks $job) => $job->knowledgeEntryId === $entry->id,
        );
    }

    public function test_it_leaves_entries_that_already_have_chunks_alone(): void
    {
        Queue::fake();

        $this->chunkFor($this->entry());

        $this->artisan('entries:reindex')->assertSuccessful();

        Queue::assertNotPushed(IndexEntryChunks::class);
    }

    public function test_the_all_flag_reindexes_entries_that_already_have_chunks(): void
    {
        Queue::fake();

        $entry = $this->entry();
        $this->chunkFor($entry);

        $this->artisan('entries:reindex', ['--all' => true])->assertSuccessful();

        Queue::assertPushed(
            IndexEntryChunks::class,
            fn (IndexEntryChunks $job) => $job->knowledgeEntryId === $entry->id,
        );
    }

    public function test_it_can_be_restricted_to_one_workspace(): void
    {
        Queue::fake();

        $other = Workspace::create([
            'name' => 'Other',
            'owner_id' => User::factory()->create()->id,
        ]);

        $mine = $this->entry('Mine');
        $theirs = KnowledgeEntry::create([
            'workspace_id' => $other->id,
            'created_by' => $other->owner_id,
            'title' => 'Theirs',
            'content' => '<p>Body.</p>',
        ]);

        $this->artisan('entries:reindex', [
            '--workspace' => $this->workspace->id,
        ])->assertSuccessful();

        Queue::assertPushed(
            IndexEntryChunks::class,
            fn (IndexEntryChunks $job) => $job->knowledgeEntryId === $mine->id,
        );
        Queue::assertNotPushed(
            IndexEntryChunks::class,
            fn (IndexEntryChunks $job) => $job->knowledgeEntryId === $theirs->id,
        );
    }

    public function test_the_sync_flag_indexes_without_a_queue_worker(): void
    {
        $embeddings = new class extends EmbeddingService
        {
            public function __construct() {}

            public function embedMany(array $texts): array
            {
                return array_map(
                    fn () => array_fill(0, config('ai.embedding_dimension'), 0.1),
                    $texts,
                );
            }
        };

        $this->app->instance(EmbeddingService::class, $embeddings);

        $entry = $this->entry();

        $this->artisan('entries:reindex', ['--sync' => true])->assertSuccessful();

        $this->assertDatabaseHas('entry_chunks', [
            'knowledge_entry_id' => $entry->id,
        ]);
    }
}
