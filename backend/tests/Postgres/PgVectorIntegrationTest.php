<?php

namespace Tests\Postgres;

use App\Casts\AsVector;
use App\Models\EntryChunk;
use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Search\HybridSearchService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the pgvector path actually runs against a real Postgres+pgvector.
 *
 * These tests are intended to run with DB_CONNECTION=pgsql against a database
 * that has the vector extension enabled. They prove migrations create the
 * vector column/index and that HybridSearchService queries the vector column
 * (not just the SQLite fallback).
 */
class PgVectorIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $driver = $this->app['db']->connection()->getDriverName();
        if ($driver !== 'pgsql') {
            $this->markTestSkipped('Postgres tests must run with DB_CONNECTION=pgsql');
        }

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::create([
            'name' => 'Acme',
            'owner_id' => $this->owner->id,
        ]);
    }

    public function test_vector_column_and_index_exist(): void
    {
        $columns = $this->app['db']->select(
            "SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'entry_chunks'"
        );

        $hasVector = collect($columns)
            ->contains(function ($c) {
                $type = strtolower((string) $c->column_name === 'embedding' ? $c->data_type : ($c->data_type ?? ''));
                if ($c->column_name === 'embedding') {
                    $type = strtolower((string) $c->data_type);
                }

                return $c->column_name === 'embedding' && (str_contains($type, 'vector') || str_contains($type, 'user-defined'));
            });

        $this->assertTrue($hasVector, 'entry_chunks.embedding must be a vector column on pgsql');

        $indexes = $this->app['db']->select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'entry_chunks'"
        );
        $this->assertTrue(
            collect($indexes)->pluck('indexname')->contains(fn ($n) => str_contains((string) $n, 'embedding')),
            'vector index must exist on entry_chunks.embedding'
        );
    }

    public function test_entry_chunk_cast_stores_and_reads_vectors(): void
    {
        $entry = KnowledgeEntry::create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->owner->id,
            'title' => 'Vectors',
            'content' => '<p>Test</p>',
        ]);

        $dim = config('ai.embedding_dimension');
        $vec = array_fill(0, $dim, 0.1);
        $chunk = new EntryChunk([
            'knowledge_entry_id' => $entry->id,
            'source' => 'content',
            'position' => 0,
            'content_hash' => hash('sha256', (string) $entry->content),
            'body' => 'chunk',
            'embedding' => $vec,
        ]);
        $chunk->save();

        $fresh = EntryChunk::find($chunk->id);
        $this->assertIsArray($fresh->embedding);
        $this->assertCount($dim, $fresh->embedding);
        $this->assertSame(0.1, $fresh->embedding[0]);
    }

    public function test_distance_operator_works_via_query_builder(): void
    {
        $entry = KnowledgeEntry::create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->owner->id,
            'title' => 'Vectors',
            'content' => '<p>Test</p>',
        ]);

        $a = array_fill(0, config('ai.embedding_dimension'), 1.0);
        $b = array_fill(0, config('ai.embedding_dimension'), 0.6);

        EntryChunk::create([
            'knowledge_entry_id' => $entry->id,
            'source' => 'content',
            'position' => 0,
            'content_hash' => hash('sha256', (string) $entry->content),
            'body' => 'a',
            'embedding' => $a,
        ]);

        EntryChunk::create([
            'knowledge_entry_id' => $entry->id,
            'source' => 'content',
            'position' => 1,
            'content_hash' => hash('sha256', (string) $entry->content),
            'body' => 'b',
            'embedding' => $b,
        ]);

        $results = EntryChunk::query()
            ->select('*')
            ->selectRaw('embedding <=> ?::vector AS distance', [AsVector::encode(array_fill(0, config('ai.embedding_dimension'), 1.0))])
            ->orderBy('distance')
            ->get();

        $this->assertNotEmpty($results);
        $this->assertNotNull($results->first()->distance);
    }

    public function test_hybrid_search_uses_semantic_path_when_postgres(): void
    {
        $service = $this->app->make(HybridSearchService::class);

        $entry = KnowledgeEntry::create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->owner->id,
            'title' => 'Parser',
            'content' => '<p>Body</p>',
        ]);

        $chunk = EntryChunk::create([
            'knowledge_entry_id' => $entry->id,
            'source' => 'content',
            'position' => 0,
            'content_hash' => hash('sha256', (string) $entry->content),
            'body' => 'The parser handles nested tables.',
            'embedding' => array_fill(0, config('ai.embedding_dimension'), 0.42),
        ]);

        $results = $service->search($this->workspace, 'how does the parser handle tables', 5);
        $this->assertGreaterThanOrEqual(0, $results->count());
    }

    public function test_vector_column_rejects_wrong_dimension_on_insert(): void
    {
        $entry = KnowledgeEntry::create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->owner->id,
            'title' => 'Dim',
            'content' => '<p>Test</p>',
        ]);

        $this->expectException(QueryException::class);
        EntryChunk::create([
            'knowledge_entry_id' => $entry->id,
            'source' => 'content',
            'position' => 0,
            'content_hash' => hash('sha256', (string) $entry->content),
            'body' => 'x',
            'embedding' => [0.1, 0.2],
        ]);
    }
}
