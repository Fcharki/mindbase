<?php

namespace Tests\Feature;

use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagSyncTest extends TestCase
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

    private function entry(Workspace $workspace, User $creator): KnowledgeEntry
    {
        return KnowledgeEntry::create([
            'workspace_id' => $workspace->id,
            'created_by' => $creator->id,
            'title' => 'An entry',
            'content' => 'Body',
        ]);
    }

    public function test_tags_are_created_and_attached(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);

        $response = $this->actingAs($workspace->owner)
            ->postJson("/api/workspaces/{$workspace->id}/entries/{$entry->id}/tags", [
                'tags' => ['PHP', 'Laravel'],
            ]);

        $response->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertDatabaseHas('tags', [
            'workspace_id' => $workspace->id,
            'name' => 'php',
        ]);
        $this->assertDatabaseCount('knowledge_entry_tag', 2);
    }

    public function test_tag_sync_is_idempotent(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);

        $this->actingAs($workspace->owner)
            ->postJson("/api/workspaces/{$workspace->id}/entries/{$entry->id}/tags", [
                'tags' => ['PHP'],
            ])
            ->assertOk();

        $this->actingAs($workspace->owner)
            ->postJson("/api/workspaces/{$workspace->id}/entries/{$entry->id}/tags", [
                'tags' => ['PHP', 'Laravel'],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertDatabaseCount('tags', 2);
        $this->assertDatabaseCount('knowledge_entry_tag', 2);
    }

    public function test_same_tag_name_is_scoped_per_workspace(): void
    {
        $workspaceA = $this->workspace();
        $workspaceB = $this->workspace();
        $entryA = $this->entry($workspaceA, $workspaceA->owner);
        $entryB = $this->entry($workspaceB, $workspaceB->owner);

        $this->actingAs($workspaceA->owner)
            ->postJson("/api/workspaces/{$workspaceA->id}/entries/{$entryA->id}/tags", [
                'tags' => ['shared'],
            ])
            ->assertOk();

        $this->actingAs($workspaceB->owner)
            ->postJson("/api/workspaces/{$workspaceB->id}/entries/{$entryB->id}/tags", [
                'tags' => ['shared'],
            ])
            ->assertOk();

        $this->assertDatabaseCount('tags', 2);
    }

    public function test_viewer_cannot_tag_an_entry(): void
    {
        $workspace = $this->workspace();
        $viewer = User::factory()->create();
        $workspace->members()->attach($viewer->id, ['role' => 'viewer']);
        $entry = $this->entry($workspace, $workspace->owner);

        $response = $this->actingAs($viewer)
            ->postJson("/api/workspaces/{$workspace->id}/entries/{$entry->id}/tags", [
                'tags' => ['PHP'],
            ]);

        $response->assertForbidden();
    }

    public function test_entry_responses_include_tags(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $tag = $workspace->tags()->create(['name' => 'ideas']);
        $entry->tags()->attach($tag->id);

        $response = $this->actingAs($workspace->owner)
            ->getJson("/api/workspaces/{$workspace->id}/entries/{$entry->id}");

        $response->assertOk()
            ->assertJsonPath('data.tags_count', 1)
            ->assertJsonPath('data.tags.0.name', 'ideas');
    }
}
