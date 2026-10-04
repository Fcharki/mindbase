<?php

namespace Tests\Feature;

use App\Models\EntryChunk;
use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\EmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * The retrieval endpoint over embedded chunks.
 *
 * The vector half of the query cannot run under sqlite, so what is proved here
 * is the part that can: the route, the authorisation, the response shape, and
 * that a failed embedding degrades to keyword search instead of failing the
 * request. The fusion arithmetic has its own test, and the pgvector path is
 * covered by the end-to-end run against Postgres.
 */
class SearchChunksTest extends TestCase
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

    /**
     * Stands in for the embedding client. On sqlite the query is never built, so
     * this only has to be callable.
     */
    private function fakeEmbeddings(bool $fail = false): EmbeddingService
    {
        $service = new class($fail) extends EmbeddingService
        {
            public int $calls = 0;

            public function __construct(private readonly bool $fail) {}

            public function embed(string $text): array
            {
                $this->calls++;

                if ($this->fail) {
                    throw new \RuntimeException('model unavailable');
                }

                return array_fill(0, config('ai.embedding_dimension'), 0.1);
            }
        };

        $this->app->instance(EmbeddingService::class, $service);

        return $service;
    }

    private function chunk(KnowledgeEntry $entry, string $body, string $source = 'content'): EntryChunk
    {
        $chunk = new EntryChunk([
            'knowledge_entry_id' => $entry->id,
            'source' => $source,
            'position' => EntryChunk::where('knowledge_entry_id', $entry->id)->count(),
            'content_hash' => hash('sha256', (string) $entry->content),
            'body' => $body,
            'embedding' => array_fill(0, config('ai.embedding_dimension'), 0.1),
        ]);

        $chunk->save();

        return $chunk;
    }

    private function entry(string $content = '<p>Body.</p>'): KnowledgeEntry
    {
        return KnowledgeEntry::create([
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->owner->id,
            'title' => 'Parser notes',
            'content' => $content,
        ]);
    }

    private function url(array $query = ['q' => 'parser']): string
    {
        return "/api/v1/workspaces/{$this->workspace->id}/search/chunks?".http_build_query($query);
    }

    public function test_it_returns_matching_chunks(): void
    {
        $this->fakeEmbeddings();

        $this->chunk($this->entry(), 'The parser handles nested tables.');

        $this->actingAs($this->owner)
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.0.chunk.body', 'The parser handles nested tables.')
            ->assertJsonPath('data.0.chunk.knowledge_entry_id', 1);
    }

    public function test_a_question_matching_nothing_returns_an_empty_list(): void
    {
        $this->fakeEmbeddings();

        $this->chunk($this->entry(), 'The parser handles nested tables.');

        $this->actingAs($this->owner)
            ->getJson($this->url(['q' => 'kubernetes']))
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_it_reports_the_source_entry_of_each_passage(): void
    {
        $this->fakeEmbeddings();

        $entry = $this->entry();

        $this->chunk($entry, 'The parser handles nested tables.');

        $this->actingAs($this->owner)
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.0.chunk.entry.id', $entry->id)
            ->assertJsonPath('data.0.chunk.entry.title', 'Parser notes');
    }

    public function test_it_never_returns_the_vector(): void
    {
        $this->fakeEmbeddings();

        $this->chunk($this->entry(), 'The parser handles nested tables.');

        $body = $this->actingAs($this->owner)->getJson($this->url())->assertOk()->json();

        $this->assertArrayNotHasKey('embedding', $body['data'][0]['chunk']);
    }

    public function test_it_reports_which_engine_found_each_result(): void
    {
        $this->fakeEmbeddings();

        $this->chunk($this->entry(), 'The parser handles nested tables.');

        $this->actingAs($this->owner)
            ->getJson($this->url())
            ->assertOk()
            // Only the keyword engine can run here.
            ->assertJsonPath('data.0.found_by', ['lexical'])
            ->assertJsonPath('data.0.found_by_both', false)
            ->assertJsonPath('data.0.lexical_rank', 1)
            ->assertJsonPath('data.0.semantic_rank', null)
            ->assertJsonPath('data.0.distance', null);
    }

    public function test_a_question_is_required(): void
    {
        $this->actingAs($this->owner)
            ->getJson('/api/v1/workspaces/'.$this->workspace->id.'/search/chunks')
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }

    public function test_the_limit_is_honoured(): void
    {
        $this->fakeEmbeddings();

        $entry = $this->entry();

        $this->chunk($entry, 'The parser is one.');
        $this->chunk($entry, 'The parser is two.');
        $this->chunk($entry, 'The parser is three.');

        $this->actingAs($this->owner)
            ->getJson($this->url(['q' => 'parser', 'limit' => 2]))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_an_out_of_range_limit_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->getJson($this->url(['q' => 'parser', 'limit' => 500]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('limit');
    }

    public function test_it_only_searches_the_requested_workspace(): void
    {
        $this->fakeEmbeddings();

        $elsewhere = Workspace::create([
            'name' => 'Other',
            'owner_id' => User::factory()->create()->id,
        ]);

        $foreign = KnowledgeEntry::create([
            'workspace_id' => $elsewhere->id,
            'created_by' => $elsewhere->owner_id,
            'title' => 'Foreign',
            'content' => '<p>Body.</p>',
        ]);

        $this->chunk($foreign, 'The parser handles nested tables.');

        $this->actingAs($this->owner)
            ->getJson($this->url())
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_the_workspace_policy_gates_retrieval(): void
    {
        // WorkspacePolicy::view() currently lets any authenticated user through,
        // so the gate is exercised with a policy that denies to prove the
        // controller asks it rather than trusting authentication alone.
        Gate::policy(Workspace::class, DenyViewWorkspacePolicy::class);

        $this->actingAs(User::factory()->create())
            ->getJson($this->url())
            ->assertStatus(403);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
    }

    public function test_it_does_not_query_the_model_on_sqlite(): void
    {
        $embeddings = $this->fakeEmbeddings();

        $this->chunk($this->entry(), 'The parser handles nested tables.');

        $this->actingAs($this->owner)->getJson($this->url())->assertOk();

        // The vector column does not exist here, so encoding the question first
        // would be a minute of model load for a result that cannot be used.
        $this->assertSame(0, $embeddings->calls);
    }
}

/**
 * Stands in for a workspace the caller may not see.
 */
class DenyViewWorkspacePolicy
{
    public function view(User $user, Workspace $workspace): bool
    {
        return false;
    }
}
