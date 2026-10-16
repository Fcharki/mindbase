<?php

namespace Tests\Feature;

use App\Exceptions\ActionFailedException;
use App\Jobs\GenerateEntrySummary;
use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\BackendBudget;
use App\Services\AI\SummarizationService;
use App\Services\EntrySummaryService;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A backend that cannot finish inside its budget is refused at the door.
 *
 * The slow backend needs roughly 33 minutes on the hardware this project runs
 * on and the budget is 10. The old behaviour queued it anyway, so the caller got
 * a 202 and a job that occupied a worker until it was killed by a transport
 * timeout. These tests pin the refusal, and the escape hatch, in place.
 */
class BackendBudgetTest extends TestCase
{
    use RefreshDatabase;

    private KnowledgeEntry $entry;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create();

        $workspace = Workspace::create([
            'name' => 'Acme',
            'owner_id' => $owner->id,
        ]);

        $this->entry = KnowledgeEntry::create([
            'workspace_id' => $workspace->id,
            'created_by' => $owner->id,
            'title' => 'Entry',
            'content' => '<p>Some knowledge.</p>',
        ]);
    }

    private function url(): string
    {
        return "/api/v1/workspaces/{$this->entry->workspace_id}"
            ."/entries/{$this->entry->id}/summary/regenerate";
    }

    public function test_a_backend_within_budget_is_queued(): void
    {
        Queue::fake();

        $this->actingAs(User::find($this->entry->created_by))
            ->postJson($this->url(), ['backend' => 't5'])
            ->assertStatus(202);

        Queue::assertPushed(GenerateEntrySummary::class);
    }

    public function test_a_backend_over_budget_is_refused_with_the_numbers(): void
    {
        Queue::fake();

        $response = $this->actingAs(User::find($this->entry->created_by))
            ->postJson($this->url(), ['backend' => 'qwen'])
            ->assertStatus(422);

        $message = $response->json('message');

        // The point of the whole feature: the reason, and both figures behind
        // it, arrive with the refusal instead of ten minutes later.
        $this->assertStringContainsString('qwen', $message);
        $this->assertStringContainsString(
            (string) config('ai.backend_seconds.qwen'),
            $message,
        );
        $this->assertStringContainsString(
            (string) config('ai.background_timeout'),
            $message,
        );
        $this->assertStringContainsString('AI_ALLOW_SLOW_BACKENDS', $message);

        // Nothing queued, so no worker is occupied and no retry storm follows.
        Queue::assertNotPushed(GenerateEntrySummary::class);
    }

    public function test_omitting_the_backend_uses_the_default_and_is_always_allowed(): void
    {
        Queue::fake();

        $this->actingAs(User::find($this->entry->created_by))
            ->postJson($this->url())
            ->assertStatus(202);

        Queue::assertPushed(
            GenerateEntrySummary::class,
            fn (GenerateEntrySummary $job) => $job->backend === null,
        );
    }

    public function test_the_escape_hatch_queues_the_slow_backend_anyway(): void
    {
        Queue::fake();
        config(['ai.allow_slow_backends' => true]);

        $this->actingAs(User::find($this->entry->created_by))
            ->postJson($this->url(), ['backend' => 'qwen'])
            ->assertStatus(202);

        Queue::assertPushed(
            GenerateEntrySummary::class,
            fn (GenerateEntrySummary $job) => $job->backend === 'qwen',
        );
    }

    public function test_raising_the_budget_makes_the_slow_backend_affordable(): void
    {
        Queue::fake();
        config(['ai.background_timeout' => config('ai.backend_seconds.qwen')]);

        $this->actingAs(User::find($this->entry->created_by))
            ->postJson($this->url(), ['backend' => 'qwen'])
            ->assertStatus(202);

        Queue::assertPushed(GenerateEntrySummary::class);
    }

    public function test_the_job_refuses_a_backend_that_overran_its_budget(): void
    {
        // The endpoint refuses, but a job queued before a config change can
        // still land after it, so the job asks the same question.
        $this->expectException(ActionFailedException::class);
        $this->expectExceptionMessageMatches('/qwen/');

        (new BackendBudget)->assertAffordable('qwen');
    }

    public function test_an_unlisted_backend_is_left_to_the_service_to_resolve(): void
    {
        // The AI service falls back to its own default for a name it does not
        // know, and pre-empting that here would refuse something that works.
        (new BackendBudget)->assertAffordable('some-future-model');

        $this->assertTrue(true);
    }

    public function test_a_transport_timeout_names_the_backend_and_the_estimate(): void
    {
        $service = new class extends SummarizationService
        {
            public function __construct() {}

            public function summarizeWithMetadata(
                string $text,
                ?string $backend = null,
                ?int $timeout = null
            ): array {
                throw new ConnectException(
                    'cURL error 28: Operation timed out',
                    new Request('POST', '/summarize'),
                );
            }
        };

        $this->app->instance(SummarizationService::class, $service);

        try {
            (new GenerateEntrySummary(
                $this->entry->id,
                app(EntrySummaryService::class)->hashFor($this->entry->content),
                'qwen',
            ))->handle(
                $service,
                app(EntrySummaryService::class),
                app(BackendBudget::class),
            );

            $this->fail('The transport failure should have been rethrown.');
        } catch (ActionFailedException $e) {
            $this->assertStringContainsString('qwen', $e->getMessage());
            $this->assertStringContainsString(
                (string) config('ai.backend_seconds.qwen'),
                $e->getMessage(),
            );
            $this->assertStringNotContainsString('cURL error 28', $e->getMessage());
        }
    }
}
