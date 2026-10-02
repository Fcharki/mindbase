<?php

namespace Tests\Feature;

use App\Jobs\GenerateEntrySummary;
use App\Models\KnowledgeEntry;
use App\Models\Summary;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\SummarizationService;
use App\Services\EntrySummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Regeneration lets a caller name the model for one entry without changing what
 * the service uses everywhere else, which is how a single stored summary gets
 * upgraded after a backend swap.
 */
class RegenerateEntrySummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 'qwen' is used here as a stand-in for "a backend other than the
        // default", which is what these tests are about: that the choice reaches
        // the job and the AI service. Whether a given backend is affordable is
        // a separate question, and on this hardware the answer is no, so the
        // refusal is opted out of here and covered in BackendBudgetTest.
        config(['ai.allow_slow_backends' => true]);
    }

    private function entry(?string $content = '<p>Some knowledge.</p>'): KnowledgeEntry
    {
        $workspace = Workspace::create([
            'name' => 'Acme',
            'owner_id' => User::factory()->create()->id,
        ]);

        return KnowledgeEntry::create([
            'workspace_id' => $workspace->id,
            'created_by' => $workspace->owner_id,
            'title' => 'Entry',
            'content' => $content,
        ]);
    }

    private function fakeSummarization(SummarizationService $service): void
    {
        $this->app->instance(SummarizationService::class, $service);
    }

    /**
     * Records the backend it was asked for so the test can prove the choice
     * reached the AI service rather than being silently dropped.
     */
    private function serviceRecordingBackend(string $summary): SummarizationService
    {
        return new class($summary) extends SummarizationService
        {
            public ?string $askedFor = null;

            public function __construct(private readonly string $summary) {}

            public function summarize(string $text): string
            {
                return $this->summary;
            }

            public function summarizeWithMetadata(
                string $text,
                ?string $backend = null,
                ?int $timeout = null
            ): array {
                $this->askedFor = $backend;

                return ['summary' => $this->summary, 'backend' => $backend];
            }
        };
    }

    private function serviceReturning(string $summary): SummarizationService
    {
        return new class($summary) extends SummarizationService
        {
            public function __construct(private readonly string $summary) {}

            public function summarize(string $text): string
            {
                return $this->summary;
            }

            public function summarizeWithMetadata(
                string $text,
                ?string $backend = null,
                ?int $timeout = null
            ): array {
                return ['summary' => $this->summary, 'backend' => 'qwen'];
            }
        };
    }

    public function test_it_queues_a_regeneration(): void
    {
        Queue::fake();
        $entry = $this->entry();

        $this->actingAs(User::find($entry->created_by))
            ->postJson("/api/v1/workspaces/{$entry->workspace_id}/entries/{$entry->id}/summary/regenerate")
            ->assertStatus(202)
            ->assertExactJson([
                'message' => 'Summary regeneration queued.',
                'data' => ['status' => 'queued'],
            ]);

        Queue::assertPushed(GenerateEntrySummary::class);
    }

    public function test_it_names_the_requested_backend_on_the_job(): void
    {
        Queue::fake();
        $entry = $this->entry();

        $this->actingAs(User::find($entry->created_by))
            ->postJson("/api/v1/workspaces/{$entry->workspace_id}/entries/{$entry->id}/summary/regenerate", [
                'backend' => 'qwen',
            ])
            ->assertStatus(202);

        Queue::assertPushed(
            GenerateEntrySummary::class,
            fn (GenerateEntrySummary $job) => $job->backend === 'qwen',
        );
    }

    public function test_an_omitted_backend_leaves_the_choice_to_the_service(): void
    {
        Queue::fake();
        $entry = $this->entry();

        $this->actingAs(User::find($entry->created_by))
            ->postJson("/api/v1/workspaces/{$entry->workspace_id}/entries/{$entry->id}/summary/regenerate", [
                'backend' => null,
            ])
            ->assertStatus(202);

        Queue::assertPushed(
            GenerateEntrySummary::class,
            fn (GenerateEntrySummary $job) => $job->backend === null,
        );
    }

    public function test_an_unknown_backend_is_rejected(): void
    {
        Queue::fake();
        $entry = $this->entry();

        $this->actingAs(User::find($entry->created_by))
            ->postJson("/api/v1/workspaces/{$entry->workspace_id}/entries/{$entry->id}/summary/regenerate", [
                'backend' => 'gpt-9',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('backend');

        Queue::assertNotPushed(GenerateEntrySummary::class);
    }

    public function test_an_entry_with_no_content_cannot_be_regenerated(): void
    {
        Queue::fake();
        $entry = $this->entry('<p>   </p>');

        $this->actingAs(User::find($entry->created_by))
            ->postJson("/api/v1/workspaces/{$entry->workspace_id}/entries/{$entry->id}/summary/regenerate")
            ->assertStatus(422)
            ->assertExactJson(['message' => 'This entry has no content to summarize.']);

        Queue::assertNotPushed(GenerateEntrySummary::class);
    }

    public function test_a_viewer_cannot_regenerate_a_summary(): void
    {
        Queue::fake();
        $entry = $this->entry();
        $viewer = User::factory()->create();
        $entry->workspace->members()->attach($viewer->id, ['role' => 'viewer']);

        $this->actingAs($viewer)
            ->postJson("/api/v1/workspaces/{$entry->workspace_id}/entries/{$entry->id}/summary/regenerate")
            ->assertStatus(403);

        Queue::assertNotPushed(GenerateEntrySummary::class);
    }

    public function test_the_chosen_backend_reaches_the_ai_service(): void
    {
        $entry = $this->entry();
        $service = $this->serviceRecordingBackend('A better summary.');
        $this->fakeSummarization($service);

        GenerateEntrySummary::dispatch(
            $entry->id,
            app(EntrySummaryService::class)->hashFor($entry->content),
            'qwen',
        );

        $this->assertSame('qwen', $service->askedFor);
        $this->assertDatabaseHas('summaries', [
            'knowledge_entry_id' => $entry->id,
            'body' => 'A better summary.',
            'backend' => 'qwen',
        ]);
    }

    public function test_regenerating_replaces_the_existing_summary_in_place(): void
    {
        $entry = $this->entry();
        $hashes = app(EntrySummaryService::class);

        Summary::create([
            'knowledge_entry_id' => $entry->id,
            'content_hash' => $hashes->hashFor($entry->content),
            'body' => 'The cheap summary.',
            'backend' => 't5',
        ]);

        $this->fakeSummarization($this->serviceReturning('The improved summary.'));

        GenerateEntrySummary::dispatch(
            $entry->id,
            $hashes->hashFor($entry->content),
            'qwen',
        );

        $this->assertSame(1, Summary::where('knowledge_entry_id', $entry->id)->count());

        $summary = Summary::where('knowledge_entry_id', $entry->id)->first();

        $this->assertSame('The improved summary.', $summary->body);
        $this->assertSame('qwen', $summary->backend);
    }

    public function test_it_does_not_report_a_backend_it_cannot_guarantee(): void
    {
        Queue::fake();
        $entry = $this->entry();

        $response = $this->actingAs(User::find($entry->created_by))
            ->postJson("/api/v1/workspaces/{$entry->workspace_id}/entries/{$entry->id}/summary/regenerate")
            ->assertStatus(202);

        // The service may fall back to its default, so the response only claims
        // what it knows: that the work was queued.
        $this->assertArrayNotHasKey('backend', $response->json('data'));
    }
}
