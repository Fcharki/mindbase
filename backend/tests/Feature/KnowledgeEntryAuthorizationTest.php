<?php

namespace Tests\Feature;

use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeEntryAuthorizationTest extends TestCase
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

    public function test_member_can_create_an_entry(): void
    {
        $workspace = $this->workspace();
        $member = User::factory()->create();
        $workspace->members()->attach($member->id, ['role' => 'member']);

        $response = $this->actingAs($member)
            ->postJson("/api/workspaces/{$workspace->id}/entries", [
                'title' => 'My entry',
                'content' => 'Body',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'My entry');
    }

    public function test_viewer_cannot_create_an_entry(): void
    {
        $workspace = $this->workspace();
        $viewer = User::factory()->create();
        $workspace->members()->attach($viewer->id, ['role' => 'viewer']);

        $response = $this->actingAs($viewer)
            ->postJson("/api/workspaces/{$workspace->id}/entries", [
                'title' => 'My entry',
                'content' => 'Body',
            ]);

        $response->assertForbidden();
    }

    public function test_viewer_can_view_entries(): void
    {
        $workspace = $this->workspace();
        $viewer = User::factory()->create();
        $workspace->members()->attach($viewer->id, ['role' => 'viewer']);
        $entry = $this->entry($workspace, $workspace->owner);

        $response = $this->actingAs($viewer)
            ->getJson("/api/workspaces/{$workspace->id}/entries/{$entry->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $entry->id);
    }

    public function test_member_can_update_an_entry(): void
    {
        $workspace = $this->workspace();
        $member = User::factory()->create();
        $workspace->members()->attach($member->id, ['role' => 'member']);
        $entry = $this->entry($workspace, $workspace->owner);

        $response = $this->actingAs($member)
            ->putJson("/api/workspaces/{$workspace->id}/entries/{$entry->id}", [
                'title' => 'Updated',
                'content' => 'New body',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.title', 'Updated');
    }

    public function test_viewer_cannot_update_an_entry(): void
    {
        $workspace = $this->workspace();
        $viewer = User::factory()->create();
        $workspace->members()->attach($viewer->id, ['role' => 'viewer']);
        $entry = $this->entry($workspace, $workspace->owner);

        $response = $this->actingAs($viewer)
            ->putJson("/api/workspaces/{$workspace->id}/entries/{$entry->id}", [
                'title' => 'Updated',
                'content' => 'New body',
            ]);

        $response->assertForbidden();
    }

    public function test_member_can_delete_an_entry(): void
    {
        $workspace = $this->workspace();
        $member = User::factory()->create();
        $workspace->members()->attach($member->id, ['role' => 'member']);
        $entry = $this->entry($workspace, $workspace->owner);

        $response = $this->actingAs($member)
            ->deleteJson("/api/workspaces/{$workspace->id}/entries/{$entry->id}");

        $response->assertOk();

        $this->assertDatabaseMissing('knowledge_entries', [
            'id' => $entry->id,
        ]);
    }

    public function test_viewer_cannot_delete_an_entry(): void
    {
        $workspace = $this->workspace();
        $viewer = User::factory()->create();
        $workspace->members()->attach($viewer->id, ['role' => 'viewer']);
        $entry = $this->entry($workspace, $workspace->owner);

        $response = $this->actingAs($viewer)
            ->deleteJson("/api/workspaces/{$workspace->id}/entries/{$entry->id}");

        $response->assertForbidden();
    }

    public function test_only_owner_can_update_workspace(): void
    {
        $workspace = $this->workspace();
        $member = User::factory()->create();
        $workspace->members()->attach($member->id, ['role' => 'member']);

        $response = $this->actingAs($member)
            ->putJson("/api/workspaces/{$workspace->id}", ['name' => 'Renamed']);

        $response->assertForbidden();

        $this->actingAs($workspace->owner)
            ->putJson("/api/workspaces/{$workspace->id}", ['name' => 'Renamed'])
            ->assertOk();
    }
}
