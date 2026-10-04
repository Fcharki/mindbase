<?php

namespace Tests\Feature;

use App\Jobs\GenerateAssistantAnswer;
use App\Models\AssistantMessage;
use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\PassageFormatter;
use App\Services\AI\RagService;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AskWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The question used by most tests.
     *
     * Retrieval under sqlite falls back to a LIKE match on the whole string, so
     * a question has to appear verbatim in the body. Deliberate, rather than
     * pretending the embedding search that only exists on postgres ran here.
     */
    private const QUESTION = 'hired 10 employees';

    private function workspaceWithEntry(User $owner, string $body = 'Kaizen co. hired 10 employees in total.'): Workspace
    {
        $workspace = Workspace::create([
            'name' => 'Company',
            'owner_id' => $owner->id,
        ]);

        $workspace->members()->attach($owner->id, ['role' => 'owner']);

        $entry = KnowledgeEntry::create([
            'workspace_id' => $workspace->id,
            'created_by' => $owner->id,
            'title' => 'About company',
            'content' => $body,
        ]);

        // Retrieval reads chunks, not entries, and the panel's sources come from
        // them, so an indexed entry is what a real workspace has.
        $entry->chunks()->create([
            'source' => 'content',
            'position' => 0,
            'content_hash' => hash('sha256', $body),
            'body' => $body,
            'embedding' => array_fill(0, 1024, 0.01),
        ]);

        return $workspace->fresh();
    }

    public function test_a_question_is_accepted_and_queued_rather_than_answered_inline(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $workspace = $this->workspaceWithEntry($owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/ask", ['question' => self::QUESTION])
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.answer', null)
            ->assertJsonPath('data.question', self::QUESTION)
            ->assertJsonCount(1, 'data.sources')
            ->assertJsonPath('data.sources.0.title', 'About company');

        Queue::assertPushed(GenerateAssistantAnswer::class);
    }

    public function test_the_sources_are_saved_with_the_question_so_the_answer_cites_what_it_read(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $workspace = $this->workspaceWithEntry($owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/ask", ['question' => self::QUESTION])
            ->assertAccepted();

        $message = AssistantMessage::firstOrFail();

        $this->assertSame('pending', $message->status);
        $this->assertSame('Kaizen co. hired 10 employees in total.', $message->sources[0]['body']);
    }

    public function test_the_job_stores_the_answer_and_marks_it_ready(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $workspace = $this->workspaceWithEntry($owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/ask", ['question' => self::QUESTION]);

        $message = AssistantMessage::firstOrFail();

        $this->mockRag('Ten employees.');

        $this->runJob();

        $message->refresh();

        $this->assertSame('ready', $message->status);
        $this->assertSame('Ten employees.', $message->answer);
        $this->assertNull($message->error);
    }

    public function test_the_stored_passages_are_sent_to_the_model(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $workspace = $this->workspaceWithEntry($owner, 'Kaizen co. has 4 departments listed.');

        $this->actingAs($owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/ask", ['question' => 'has 4 departments']);

        $this->mockRag('Four departments.');

        $this->runJob();

        $this->assertStringContainsString(
            'Kaizen co. has 4 departments listed.',
            $this->ragContexts(),
        );

        $this->assertStringContainsString('has 4 departments', $this->ragPrompts());
    }

    public function test_a_question_with_no_matching_passages_is_refused_without_queueing(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $workspace = $this->workspaceWithEntry($owner);

        // An empty workspace: nothing to retrieve, so there is nothing to ask
        // about and no reason to spend a model call discovering that.
        $empty = Workspace::create(['name' => 'Empty', 'owner_id' => $owner->id]);
        $empty->members()->attach($owner->id, ['role' => 'owner']);

        $this->actingAs($owner)
            ->postJson("/api/v1/workspaces/{$empty->id}/ask", ['question' => 'anything?'])
            ->assertNotFound()
            ->assertJsonPath('message', 'No matching entries in this workspace yet. Add some entries, or reword the question.');

        Queue::assertNothingPushed();
    }

    public function test_an_unreachable_ai_service_marks_the_question_failed_with_a_reason(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $workspace = $this->workspaceWithEntry($owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/ask", ['question' => self::QUESTION]);

        $this->mock(RagService::class, function (RagService $rag) {
            $rag->shouldReceive('ask')
                ->andThrow(new ConnectException(
                    'Connection refused',
                    new GuzzleRequest('POST', 'rag'),
                ));
        });

        $this->runJob();

        $message = AssistantMessage::firstOrFail();

        $this->assertSame('failed', $message->status);
        $this->assertSame('The AI service is unavailable. Please try again.', $message->error);
    }

    public function test_an_empty_answer_is_a_failure_rather_than_a_blank_turn(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $workspace = $this->workspaceWithEntry($owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/ask", ['question' => self::QUESTION]);

        $this->mockRag('   ');

        $this->runJob();

        $message = AssistantMessage::firstOrFail();

        $this->assertSame('failed', $message->status);
        $this->assertNotNull($message->error);
    }

    public function test_polling_returns_the_answer_once_it_is_ready(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $workspace = $this->workspaceWithEntry($owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/ask", ['question' => self::QUESTION]);

        $message = AssistantMessage::firstOrFail();

        $this->actingAs($owner)
            ->getJson("/api/v1/workspaces/{$workspace->id}/ask/{$message->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $this->mockRag('Ten employees.');

        $this->runJob();

        $this->actingAs($owner)
            ->getJson("/api/v1/workspaces/{$workspace->id}/ask/{$message->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.answer', 'Ten employees.');
    }

    public function test_a_question_cannot_be_polled_from_another_workspace(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $mine = $this->workspaceWithEntry($owner);

        $other = Workspace::create(['name' => 'Theirs', 'owner_id' => $owner->id]);
        $other->members()->attach($owner->id, ['role' => 'owner']);

        $this->actingAs($owner)
            ->postJson("/api/v1/workspaces/{$mine->id}/ask", ['question' => self::QUESTION]);

        $message = AssistantMessage::firstOrFail();

        // Scoping the lookup to the workspace means a guessed id from elsewhere
        // reads as absent rather than as somebody else's question.
        $this->actingAs($owner)
            ->getJson("/api/v1/workspaces/{$other->id}/ask/{$message->id}")
            ->assertNotFound();
    }

    public function test_a_guest_cannot_ask(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceWithEntry($owner);

        $this->postJson("/api/v1/workspaces/{$workspace->id}/ask", ['question' => self::QUESTION])
            ->assertUnauthorized();
    }

    public function test_a_viewer_may_ask_but_the_view_policy_still_gates_it(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $workspace = $this->workspaceWithEntry($owner);
        $viewer = User::factory()->create();

        $workspace->members()->attach($viewer->id, ['role' => 'viewer']);

        $this->actingAs($viewer)
            ->postJson("/api/v1/workspaces/{$workspace->id}/ask", ['question' => self::QUESTION])
            ->assertAccepted();
    }

    public function test_the_question_is_required(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceWithEntry($owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/ask", [])
            ->assertJsonValidationErrors('question');
    }

    public function test_passages_are_numbered_and_titled_for_the_prompt(): void
    {
        $formatter = new PassageFormatter;

        $formatted = $formatter->format([
            ['title' => 'About company', 'body' => 'Ten employees.'],
            ['title' => null, 'body' => 'Four departments.'],
        ]);

        $this->assertStringContainsString('[1] About company', $formatted);
        $this->assertStringContainsString('[2] Untitled entry', $formatted);
        $this->assertSame('', $formatter->format(null));
    }

    /**
     * Run the queued answer job synchronously, since Queue::fake() is holding it.
     */
    private function runJob(): void
    {
        $job = new GenerateAssistantAnswer(AssistantMessage::firstOrFail()->id);

        $job->handle(
            app(RagService::class),
            app(PassageFormatter::class),
        );
    }

    /**
     * Stub the AI service with a successful answer, capturing the prompt.
     */
    private function mockRag(string $answer): void
    {
        $this->mock(RagService::class, function (RagService $rag) use ($answer) {
            // Both arguments captured, because the passages arrive separately
            // from the question and RagService is what joins them into the
            // prompt the model actually receives.
            $rag->shouldReceive('ask')
                ->andReturnUsing(function (string $prompt, string $context) use ($answer) {
                    $this->ragPrompts[] = $prompt;
                    $this->ragContexts[] = $context;

                    return $answer;
                });
        });
    }

    /**
     * @var list<string>
     */
    private array $ragPrompts = [];

    /**
     * @var list<string>
     */
    private array $ragContexts = [];

    private function ragPrompts(): string
    {
        return implode("\n", $this->ragPrompts);
    }

    private function ragContexts(): string
    {
        return implode("\n", $this->ragContexts);
    }
}
