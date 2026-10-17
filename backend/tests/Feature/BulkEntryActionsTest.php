<?php

namespace Tests\Feature;

use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BulkEntryActionsTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(?User $owner = null): Workspace
    {
        $owner ??= User::factory()->create();
        $workspace = Workspace::create([
            'name' => 'Acme',
            'owner_id' => $owner->id,
        ]);
        $workspace->members()->attach($owner->id, ['role' => 'owner']);

        return $workspace;
    }

    private function entry(Workspace $workspace, string $title = 'An entry'): KnowledgeEntry
    {
        return KnowledgeEntry::create([
            'workspace_id' => $workspace->id,
            'created_by' => $workspace->owner_id,
            'title' => $title,
            'content' => 'Body',
        ]);
    }

    public function test_pin_and_unpin_act_on_the_whole_selection(): void
    {
        $workspace = $this->workspace();
        $first = $this->entry($workspace, 'First');
        $second = $this->entry($workspace, 'Second');

        $this->actingAs($workspace->owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/entries/bulk", [
                'action' => 'pin',
                'entry_ids' => [$first->id, $second->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.affected', 2);

        $this->assertDatabaseHas('knowledge_entries', ['id' => $first->id, 'is_pinned' => true]);
        $this->assertDatabaseHas('knowledge_entries', ['id' => $second->id, 'is_pinned' => true]);

        $this->actingAs($workspace->owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/entries/bulk", [
                'action' => 'unpin',
                'entry_ids' => [$first->id],
            ])
            ->assertOk();

        $this->assertDatabaseHas('knowledge_entries', ['id' => $first->id, 'is_pinned' => false]);
        $this->assertDatabaseHas('knowledge_entries', ['id' => $second->id, 'is_pinned' => true]);
    }

    public function test_tag_and_untag_apply_names_without_dropping_existing_ones(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace);

        $this->actingAs($workspace->owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/entries/bulk", [
                'action' => 'tag',
                'entry_ids' => [$entry->id],
                'tags' => ['Alpha'],
            ])
            ->assertOk();

        $this->actingAs($workspace->owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/entries/bulk", [
                'action' => 'tag',
                'entry_ids' => [$entry->id],
                'tags' => ['beta'],
            ])
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            ['alpha', 'beta'],
            $entry->fresh()->tags->pluck('name')->all(),
        );

        $this->actingAs($workspace->owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/entries/bulk", [
                'action' => 'untag',
                'entry_ids' => [$entry->id],
                'tags' => ['alpha'],
            ])
            ->assertOk();

        $this->assertSame(['beta'], $entry->fresh()->tags->pluck('name')->all());
    }

    public function test_delete_removes_every_selected_entry(): void
    {
        $workspace = $this->workspace();
        $first = $this->entry($workspace, 'First');
        $second = $this->entry($workspace, 'Second');

        $this->actingAs($workspace->owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/entries/bulk", [
                'action' => 'delete',
                'entry_ids' => [$first->id, $second->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.affected', 2);

        $this->assertDatabaseMissing('knowledge_entries', ['id' => $first->id]);
        $this->assertDatabaseMissing('knowledge_entries', ['id' => $second->id]);
    }

    public function test_ids_from_another_workspace_read_as_missing_not_affected(): void
    {
        $mine = $this->workspace();
        $theirs = $this->workspace();
        $mineEntry = $this->entry($mine, 'Mine');
        $foreign = $this->entry($theirs, 'Theirs');

        $this->actingAs($mine->owner)
            ->postJson("/api/v1/workspaces/{$mine->id}/entries/bulk", [
                'action' => 'pin',
                'entry_ids' => [$mineEntry->id, $foreign->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.affected', 1)
            ->assertJsonPath('data.missing', [$foreign->id]);

        $this->assertDatabaseHas('knowledge_entries', ['id' => $foreign->id, 'is_pinned' => false]);
    }

    public function test_a_selection_with_nothing_in_this_workspace_is_not_found(): void
    {
        $mine = $this->workspace();
        $theirs = $this->workspace();
        $foreign = $this->entry($theirs, 'Theirs');

        $this->actingAs($mine->owner)
            ->postJson("/api/v1/workspaces/{$mine->id}/entries/bulk", [
                'action' => 'pin',
                'entry_ids' => [$foreign->id],
            ])
            ->assertNotFound();
    }

    public function test_a_viewer_cannot_bulk_change_entries(): void
    {
        $workspace = $this->workspace();
        $viewer = User::factory()->create();
        $workspace->members()->attach($viewer->id, ['role' => 'viewer']);
        $entry = $this->entry($workspace);

        $this->actingAs($viewer)
            ->postJson("/api/v1/workspaces/{$workspace->id}/entries/bulk", [
                'action' => 'pin',
                'entry_ids' => [$entry->id],
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('knowledge_entries', ['id' => $entry->id, 'is_pinned' => false]);
    }

    public function test_the_action_and_selection_are_validated(): void
    {
        $workspace = $this->workspace();

        $this->actingAs($workspace->owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/entries/bulk", [
                'action' => 'explode',
                'entry_ids' => [1],
            ])
            ->assertJsonValidationErrors('action');

        $this->actingAs($workspace->owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/entries/bulk", [
                'action' => 'pin',
                'entry_ids' => [],
            ])
            ->assertJsonValidationErrors('entry_ids');
    }

    public function test_tagging_without_a_tag_is_refused(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace);

        $this->actingAs($workspace->owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/entries/bulk", [
                'action' => 'tag',
                'entry_ids' => [$entry->id],
            ])
            ->assertStatus(422);
    }

    public function test_a_guest_cannot_bulk_change_entries(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace);

        $this->postJson("/api/v1/workspaces/{$workspace->id}/entries/bulk", [
            'action' => 'pin',
            'entry_ids' => [$entry->id],
        ])->assertUnauthorized();
    }
}
