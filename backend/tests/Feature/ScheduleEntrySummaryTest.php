<?php

namespace Tests\Feature;

use App\Jobs\GenerateEntrySummary;
use App\Jobs\IndexEntryChunks;
use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * An edit queues a summary rather than generating one inline, so the response
 * has to come back without waiting on the model.
 */
class ScheduleEntrySummaryTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): Workspace
    {
        return Workspace::create([
            'name' => 'Acme',
            'owner_id' => User::factory()->create()->id,
        ]);
    }

    private function asOwner(Workspace $workspace): User
    {
        return User::find($workspace->owner_id);
    }

    public function test_creating_an_entry_queues_a_summary(): void
    {
        Queue::fake();

        $workspace = $this->workspace();

        $this->actingAs($this->asOwner($workspace))
            ->postJson("/api/v1/workspaces/{$workspace->id}/entries", [
                'title' => 'Parser',
                'content' => '<p>We shipped the parser.</p>',
            ])
            ->assertCreated();

        Queue::assertPushed(GenerateEntrySummary::class);
    }

    public function test_updating_an_entry_queues_a_fresh_summary(): void
    {
        Queue::fake();

        $workspace = $this->workspace();
        $entry = KnowledgeEntry::create([
            'workspace_id' => $workspace->id,
            'created_by' => $workspace->owner_id,
            'title' => 'Parser',
            'content' => '<p>The original content.</p>',
        ]);

        $this->actingAs($this->asOwner($workspace))
            ->putJson("/api/v1/workspaces/{$workspace->id}/entries/{$entry->id}", [
                'title' => 'Parser',
                'content' => '<p>The replacement content.</p>',
            ])
            ->assertOk();

        Queue::assertPushed(
            GenerateEntrySummary::class,
            fn (GenerateEntrySummary $job) => $job->knowledgeEntryId === $entry->id,
        );
    }

    public function test_the_queued_job_carries_the_hash_of_the_content_it_was_given(): void
    {
        Queue::fake();

        $workspace = $this->workspace();
        $content = '<p>The replacement content.</p>';

        $this->actingAs($this->asOwner($workspace))
            ->postJson("/api/v1/workspaces/{$workspace->id}/entries", [
                'title' => 'Parser',
                'content' => $content,
            ])
            ->assertCreated();

        Queue::assertPushed(
            GenerateEntrySummary::class,
            fn (GenerateEntrySummary $job) => $job->contentHash === hash('sha256', $content),
        );
    }

    public function test_creating_an_entry_also_queues_chunk_indexing(): void
    {
        Queue::fake();

        $workspace = $this->workspace();
        $content = '<p>We shipped the parser.</p>';

        $this->actingAs($this->asOwner($workspace))
            ->postJson("/api/v1/workspaces/{$workspace->id}/entries", [
                'title' => 'Parser',
                'content' => $content,
            ])
            ->assertCreated();

        // Retrieval needs the entry's text as vectors, and that does not depend
        // on the summary finishing first.
        Queue::assertPushed(
            IndexEntryChunks::class,
            fn (IndexEntryChunks $job) => $job->contentHash === hash('sha256', $content),
        );
    }

    public function test_an_entry_with_no_prose_queues_nothing(): void
    {
        Queue::fake();

        $workspace = $this->workspace();

        $this->actingAs($this->asOwner($workspace))
            ->postJson("/api/v1/workspaces/{$workspace->id}/entries", [
                'title' => 'Empty',
                'content' => '<p>   </p>',
            ])
            ->assertCreated();

        Queue::assertNotPushed(GenerateEntrySummary::class);
        Queue::assertNotPushed(IndexEntryChunks::class);
    }

    public function test_renaming_without_changing_content_queues_nothing_new(): void
    {
        Queue::fake();

        $workspace = $this->workspace();
        $entry = KnowledgeEntry::create([
            'workspace_id' => $workspace->id,
            'created_by' => $workspace->owner_id,
            'title' => 'Parser',
            'content' => '<p>Unchanged content.</p>',
        ]);

        $this->actingAs($this->asOwner($workspace))
            ->putJson("/api/v1/workspaces/{$workspace->id}/entries/{$entry->id}", [
                'title' => 'Parser v2',
                'content' => '<p>Unchanged content.</p>',
            ])
            ->assertOk();

        // A summary of identical content would be regenerated to the same text,
        // and the vectors would come out identical too, so the queue is left alone.
        Queue::assertNotPushed(GenerateEntrySummary::class);
        Queue::assertNotPushed(IndexEntryChunks::class);
    }
}
