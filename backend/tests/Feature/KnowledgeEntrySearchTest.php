<?php

namespace Tests\Feature;

use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeEntrySearchTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): Workspace
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create([
            'name' => 'Acme',
            'owner_id' => $owner->id,
        ]);
        $workspace->members()->attach($owner->id, ['role' => 'owner']);

        return $workspace;
    }

    private function entry(
        Workspace $workspace,
        User $creator,
        array $attributes = []
    ): KnowledgeEntry {
        return KnowledgeEntry::create(array_merge([
            'workspace_id' => $workspace->id,
            'created_by' => $creator->id,
            'title' => 'An entry',
            'content' => 'Plain body text',
        ], $attributes));
    }

    public function test_default_viewer_can_search_a_workspace(): void
    {
        $workspace = $this->workspace();
        $viewer = User::factory()->create();
        $entry = $this->entry($workspace, $workspace->owner, [
            'title' => 'Laravel deployment guide',
        ]);

        $this->actingAs($viewer)
            ->getJson("/api/v1/workspaces/{$workspace->id}/search?q=laravel")
            ->assertOk()
            ->assertJsonPath('data.entries.0.id', $entry->id);
    }

    public function test_search_matches_entries_by_title(): void
    {
        $workspace = $this->workspace();
        $match = $this->entry($workspace, $workspace->owner, [
            'title' => 'Deploying Laravel to production',
        ]);
        $this->entry($workspace, $workspace->owner, [
            'title' => 'Shopping list for camping',
        ]);

        $this->actingAs($workspace->owner)
            ->getJson("/api/v1/workspaces/{$workspace->id}/search?q=laravel")
            ->assertOk()
            ->assertJsonPath('data.entries.0.id', $match->id)
            ->assertJsonCount(1, 'data.entries');
    }

    public function test_search_matches_entries_by_content(): void
    {
        $workspace = $this->workspace();
        $match = $this->entry($workspace, $workspace->owner, [
            'title' => 'Trip notes',
            'content' => '<p>We hiked the <strong>Blue Ridge</strong> trail.</p>',
        ]);

        $this->actingAs($workspace->owner)
            ->getJson("/api/v1/workspaces/{$workspace->id}/search?q=hiked")
            ->assertOk()
            ->assertJsonPath('data.entries.0.id', $match->id);
    }

    public function test_search_does_not_leak_entries_across_workspaces(): void
    {
        $workspaceA = $this->workspace();
        $workspaceB = $this->workspace();
        $secret = $this->entry($workspaceA, $workspaceA->owner, [
            'title' => 'Private Laravel deploy strategy',
        ]);
        $visible = $this->entry($workspaceB, $workspaceB->owner, [
            'title' => 'Another Laravel document',
        ]);

        $user = User::factory()->create();
        $workspaceB->members()->attach($user->id, ['role' => 'member']);

        $this->actingAs($user)
            ->getJson("/api/v1/workspaces/{$workspaceB->id}/search?q=laravel")
            ->assertOk()
            ->assertJsonCount(1, 'data.entries')
            ->assertJsonPath('data.entries.0.id', $visible->id);

        $this->assertNotEquals($secret->id, $visible->id);
        $this->assertDatabaseHas('knowledge_entries', [
            'id' => $secret->id,
        ]);
    }

    public function test_search_finds_entries_by_tag_name(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner, [
            'title' => 'Architecture notes',
            'content' => 'No filler match here',
        ]);
        $tag = $workspace->tags()->create(['name' => 'design']);

        $this->entry($workspace, $workspace->owner, [
            'title' => 'Groceries',
            'content' => 'Unrelated body',
        ]);

        $entry->tags()->attach($tag->id);

        $this->actingAs($workspace->owner)
            ->getJson("/api/v1/workspaces/{$workspace->id}/search?q=design")
            ->assertOk()
            ->assertJsonPath('data.entries.0.id', $entry->id);
    }

    public function test_search_returns_matching_tags_with_entry_counts(): void
    {
        $workspace = $this->workspace();
        $tag = $workspace->tags()->create(['name' => 'php']);
        $match = $this->entry($workspace, $workspace->owner, [
            'title' => 'Hello world',
        ]);
        $match->tags()->attach($tag->id);

        $response = $this->actingAs($workspace->owner)
            ->getJson("/api/v1/workspaces/{$workspace->id}/search?q=php")
            ->assertOk();

        $response->assertJsonPath('data.tags.0.name', 'php');
        $response->assertJsonPath('data.tags.0.entries_count', 1);
    }

    public function test_tag_filter_narrows_results(): void
    {
        $workspace = $this->workspace();
        $tagged = $this->entry($workspace, $workspace->owner, [
            'title' => 'Laravel queues',
        ]);
        $other = $this->entry($workspace, $workspace->owner, [
            'title' => 'Laravel routes',
            'content' => 'Routes are mapped in api.php',
        ]);
        $tag = $workspace->tags()->create(['name' => 'queues']);
        $tagged->tags()->attach($tag->id);

        $this->actingAs($workspace->owner)
            ->getJson("/api/v1/workspaces/{$workspace->id}/search?q=laravel&tag=queues")
            ->assertOk()
            ->assertJsonCount(1, 'data.entries')
            ->assertJsonPath('data.entries.0.id', $tagged->id)
            ->assertJsonPath('data.entries.0.title', 'Laravel queues');

        $this->assertNotEquals($tagged->id, $other->id);
    }

    public function test_search_requires_a_query_or_tag(): void
    {
        $workspace = $this->workspace();
        $this->entry($workspace, $workspace->owner, [
            'title' => 'Anything at all',
        ]);

        $this->actingAs($workspace->owner)
            ->getJson("/api/v1/workspaces/{$workspace->id}/search")
            ->assertOk()
            ->assertJsonCount(0, 'data.entries')
            ->assertJsonCount(0, 'data.tags');
    }

    public function test_viewer_can_search_entries(): void
    {
        $workspace = $this->workspace();
        $viewer = User::factory()->create();
        $workspace->members()->attach($viewer->id, ['role' => 'viewer']);
        $match = $this->entry($workspace, $workspace->owner, [
            'title' => 'Public Laravel guide',
        ]);

        $this->actingAs($viewer)
            ->getJson("/api/v1/workspaces/{$workspace->id}/search?q=laravel")
            ->assertOk()
            ->assertJsonPath('data.entries.0.id', $match->id);
    }
}
