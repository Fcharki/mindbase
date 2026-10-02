<?php

namespace Tests\Feature;

use App\Exceptions\ActionFailedException;
use App\Jobs\GenerateEntrySummary;
use App\Models\KnowledgeEntry;
use App\Models\Summary;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\SummarizationService;
use App\Services\EntrySummaryService;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Summary generation happens in the background, so these tests pin the two
 * things that arrangement makes risky: that a job queued for content the user
 * has already replaced is thrown away, and that a failed generation never
 * destroys the summary that is already stored.
 */
class GenerateEntrySummaryTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): Workspace
    {
        return Workspace::create([
            'name' => 'Acme',
            'owner_id' => User::factory()->create()->id,
        ]);
    }

    private function entry(?string $content = '<p>Some knowledge.</p>'): KnowledgeEntry
    {
        $workspace = $this->workspace();

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

    private function serviceReturning(string $summary, string $backend = 't5'): SummarizationService
    {
        return new class($summary, $backend) extends SummarizationService
        {
            /**
             * Records the arguments the job passed, so a test can tell that a
             * requested backend and timeout reached the service.
             *
             * @var list<mixed>
             */
            public array $calls = [];

            public function __construct(
                private readonly string $summary,
                private readonly string $backend,
            ) {}

            public function summarize(string $text): string
            {
                return $this->summary;
            }

            public function summarizeWithMetadata(
                string $text,
                ?string $backend = null,
                ?int $timeout = null,
            ): array {
                $this->calls[] = [$backend, $timeout];

                return ['summary' => $this->summary, 'backend' => $this->backend];
            }
        };
    }

    public function test_it_stores_a_summary_of_the_current_content(): void
    {
        $entry = $this->entry();
        $this->fakeSummarization($this->serviceReturning('A concise summary.'));

        GenerateEntrySummary::dispatch(
            $entry->id,
            app(EntrySummaryService::class)->hashFor($entry->content),
        );

        $this->assertDatabaseHas('summaries', [
            'knowledge_entry_id' => $entry->id,
            'body' => 'A concise summary.',
            'backend' => 't5',
        ]);
    }

    public function test_it_records_which_backend_produced_the_summary(): void
    {
        $entry = $this->entry();
        $this->fakeSummarization($this->serviceReturning('A summary.', 'qwen'));

        GenerateEntrySummary::dispatch(
            $entry->id,
            app(EntrySummaryService::class)->hashFor($entry->content),
        );

        $this->assertDatabaseHas('summaries', [
            'knowledge_entry_id' => $entry->id,
            'backend' => 'qwen',
        ]);
    }

    public function test_a_summary_of_content_the_user_has_replaced_is_discarded(): void
    {
        $entry = $this->entry('<p>The original content.</p>');
        $hashes = app(EntrySummaryService::class);
        $staleHash = $hashes->hashFor('<p>The original content.</p>');

        // The user edits again before this job gets to run.
        $entry->update(['content' => '<p>The replacement content.</p>']);

        $this->fakeSummarization($this->serviceReturning('A summary of the old content.'));

        GenerateEntrySummary::dispatch($entry->id, $staleHash);

        $this->assertDatabaseMissing('summaries', [
            'knowledge_entry_id' => $entry->id,
        ]);
    }

    public function test_an_empty_summary_leaves_the_existing_one_alone(): void
    {
        $entry = $this->entry();
        $hashes = app(EntrySummaryService::class);

        Summary::create([
            'knowledge_entry_id' => $entry->id,
            'content_hash' => $hashes->hashFor($entry->content),
            'body' => 'The good summary.',
            'backend' => 't5',
        ]);

        $this->fakeSummarization($this->serviceReturning(''));

        GenerateEntrySummary::dispatch($entry->id, $hashes->hashFor($entry->content));

        $this->assertDatabaseHas('summaries', [
            'knowledge_entry_id' => $entry->id,
            'body' => 'The good summary.',
        ]);
    }

    public function test_an_entry_with_no_prose_stores_nothing(): void
    {
        $entry = $this->entry('<p>   </p>');
        $this->fakeSummarization($this->serviceReturning('never used'));

        GenerateEntrySummary::dispatch(
            $entry->id,
            app(EntrySummaryService::class)->hashFor($entry->content),
        );

        $this->assertDatabaseCount('summaries', 0);
    }

    public function test_a_deleted_entry_is_a_no_op_rather_than_a_failure(): void
    {
        $entry = $this->entry();
        $entry->delete();

        $this->fakeSummarization($this->serviceReturning('never used'));

        GenerateEntrySummary::dispatch($entry->id, str_repeat('a', 64));

        $this->assertDatabaseCount('summaries', 0);
    }

    public function test_a_transport_failure_propagates_so_the_queue_can_retry(): void
    {
        $entry = $this->entry();

        $this->fakeSummarization(new class extends SummarizationService
        {
            public function __construct() {}

            public function summarize(string $text): string
            {
                throw new ConnectException(
                    'Connection refused',
                    new PsrRequest('POST', 'summarize'),
                );
            }

            public function summarizeWithMetadata(
                string $text,
                ?string $backend = null,
                ?int $timeout = null,
            ): array {
                throw new ConnectException(
                    'Connection refused',
                    new PsrRequest('POST', 'summarize'),
                );
            }
        });

        // Nothing is stored, and the failure is left to bubble up so the queue
        // can release the job and retry it rather than treating an outage as a
        // completed run. It is wrapped rather than rethrown raw so the
        // failed_jobs record explains itself; retry behaviour is unchanged,
        // since the queue retries any exception either way.
        try {
            GenerateEntrySummary::dispatch(
                $entry->id,
                app(EntrySummaryService::class)->hashFor($entry->content),
            );
            $this->fail('Expected the transport failure to propagate.');
        } catch (ActionFailedException $e) {
            $this->assertDatabaseCount('summaries', 0);
            $this->assertStringContainsString('Connection refused', $e->getMessage());
        }
    }

    public function test_regenerating_replaces_rather_than_duplicates(): void
    {
        $entry = $this->entry();
        $hashes = app(EntrySummaryService::class);
        $this->fakeSummarization($this->serviceReturning('The second summary.'));

        GenerateEntrySummary::dispatch($entry->id, $hashes->hashFor($entry->content));
        $this->fakeSummarization($this->serviceReturning('The third summary.'));
        GenerateEntrySummary::dispatch($entry->id, $hashes->hashFor($entry->content));

        $this->assertSame(1, Summary::where('knowledge_entry_id', $entry->id)->count());
        $this->assertDatabaseHas('summaries', [
            'knowledge_entry_id' => $entry->id,
            'body' => 'The third summary.',
        ]);
    }
}
