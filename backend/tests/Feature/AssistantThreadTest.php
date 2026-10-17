<?php

namespace Tests\Feature;

use App\Jobs\GenerateAssistantAnswer;
use App\Models\AssistantMessage;
use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AssistantThreadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Embedding search only exists on postgres; under sqlite retrieval falls
     * back to a LIKE match, so the question has to appear in the body. Reranking
     * is a model call this suite has no service for, so it is switched off.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.rerank_enabled' => false]);
    }

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

        $entry->chunks()->create([
            'source' => 'content',
            'position' => 0,
            'content_hash' => hash('sha256', $body),
            'body' => $body,
            'embedding' => array_fill(0, 1024, 0.01),
        ]);

        return $workspace->fresh();
    }

    private function message(Workspace $workspace, string $question): AssistantMessage
    {
        return AssistantMessage::create([
            'workspace_id' => $workspace->id,
            'user_id' => $workspace->owner_id,
            'question' => $question,
            'answer' => 'Ten employees.',
            'status' => AssistantMessage::STATUS_READY,
        ]);
    }

    public function test_the_thread_lists_recent_questions_newest_first(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceWithEntry($owner);

        $this->message($workspace, 'first');
        $this->message($workspace, 'second');

        $this->actingAs($owner)
            ->getJson("/api/v1/workspaces/{$workspace->id}/ask/messages")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.question', 'second')
            ->assertJsonPath('data.0.answer', 'Ten employees.');
    }

    public function test_regenerating_reuses_the_row_and_requeues_the_answer(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $workspace = $this->workspaceWithEntry($owner);
        $message = $this->message($workspace, 'hired 10 employees');
        $message->update(['feedback' => 'down']);

        $this->actingAs($owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/ask/{$message->id}/regenerate")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.answer', null);

        $message->refresh();

        $this->assertSame(AssistantMessage::STATUS_PENDING, $message->status);
        $this->assertNull($message->answer);
        // A regenerate is a fresh judgement, so the old feedback goes with the
        // old answer.
        $this->assertNull($message->feedback);

        Queue::assertPushed(GenerateAssistantAnswer::class);
    }

    public function test_a_pending_question_cannot_be_regenerated(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $workspace = $this->workspaceWithEntry($owner);
        $message = $this->message($workspace, 'hired 10 employees');
        $message->update(['status' => AssistantMessage::STATUS_PENDING]);

        $this->actingAs($owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/ask/{$message->id}/regenerate")
            ->assertStatus(409);

        Queue::assertNothingPushed();
    }

    public function test_regenerating_with_nothing_to_retrieve_is_refused(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $workspace = $this->workspaceWithEntry($owner);
        $message = $this->message($workspace, 'a question about nothing at all');

        $this->actingAs($owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/ask/{$message->id}/regenerate")
            ->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_feedback_is_recorded_changed_and_cleared(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceWithEntry($owner);
        $message = $this->message($workspace, 'hired 10 employees');

        $this->actingAs($owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/ask/{$message->id}/feedback", [
                'feedback' => 'up',
            ])
            ->assertOk()
            ->assertJsonPath('data.feedback', 'up');

        $this->actingAs($owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/ask/{$message->id}/feedback", [
                'feedback' => 'down',
            ])
            ->assertOk()
            ->assertJsonPath('data.feedback', 'down');

        $this->actingAs($owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/ask/{$message->id}/feedback", [
                'feedback' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.feedback', null);

        $this->assertDatabaseHas('assistant_messages', [
            'id' => $message->id,
            'feedback' => null,
        ]);
    }

    public function test_an_unknown_feedback_value_is_rejected(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceWithEntry($owner);
        $message = $this->message($workspace, 'hired 10 employees');

        $this->actingAs($owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/ask/{$message->id}/feedback", [
                'feedback' => 'maybe',
            ])
            ->assertJsonValidationErrors('feedback');
    }

    public function test_a_message_cannot_be_reached_from_another_workspace(): void
    {
        $owner = User::factory()->create();
        $mine = $this->workspaceWithEntry($owner);
        $other = Workspace::create(['name' => 'Theirs', 'owner_id' => $owner->id]);
        $other->members()->attach($owner->id, ['role' => 'owner']);

        $message = $this->message($mine, 'hired 10 employees');

        $this->actingAs($owner)
            ->postJson("/api/v1/workspaces/{$other->id}/ask/{$message->id}/feedback", [
                'feedback' => 'up',
            ])
            ->assertNotFound();
    }

    public function test_a_guest_cannot_read_the_thread(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceWithEntry($owner);

        $this->getJson("/api/v1/workspaces/{$workspace->id}/ask/messages")
            ->assertUnauthorized();
    }
}
