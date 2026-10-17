<?php

namespace Tests\Feature;

use App\Models\KnowledgeEntry;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagManagementTest extends TestCase
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

    private function tag(Workspace $workspace, string $name, ?int $parentId = null): Tag
    {
        return $workspace->tags()->create([
            'name' => $name,
            'parent_id' => $parentId,
        ]);
    }

    public function test_the_tag_vocabulary_is_listed_with_entry_counts(): void
    {
        $workspace = $this->workspace();
        $beta = $this->tag($workspace, 'beta');
        $this->tag($workspace, 'alpha');

        $entry = KnowledgeEntry::create([
            'workspace_id' => $workspace->id,
            'created_by' => $workspace->owner_id,
            'title' => 'An entry',
            'content' => 'Body',
        ]);
        $entry->tags()->attach($beta->id);

        $response = $this->actingAs($workspace->owner)
            ->getJson("/api/v1/workspaces/{$workspace->id}/tags");

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            // Ordered by name, so a manager screen reads alphabetically.
            ->assertJsonPath('data.0.name', 'alpha')
            ->assertJsonPath('data.1.name', 'beta')
            ->assertJsonPath('data.1.entries_count', 1);
    }

    public function test_a_viewer_may_read_the_tag_vocabulary_but_not_change_it(): void
    {
        $workspace = $this->workspace();
        $tag = $this->tag($workspace, 'ideas');
        $viewer = User::factory()->create();
        $workspace->members()->attach($viewer->id, ['role' => 'viewer']);

        $this->actingAs($viewer)
            ->getJson("/api/v1/workspaces/{$workspace->id}/tags")
            ->assertOk();

        $this->actingAs($viewer)
            ->patchJson("/api/v1/workspaces/{$workspace->id}/tags/{$tag->id}", [
                'name' => 'thoughts',
            ])
            ->assertForbidden();
    }

    public function test_a_tag_can_be_renamed_and_names_are_normalized(): void
    {
        $workspace = $this->workspace();
        $tag = $this->tag($workspace, 'ideas');

        $this->actingAs($workspace->owner)
            ->patchJson("/api/v1/workspaces/{$workspace->id}/tags/{$tag->id}", [
                'name' => '  Big Ideas ',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'big ideas');

        $this->assertDatabaseHas('tags', ['id' => $tag->id, 'name' => 'big ideas']);
    }

    public function test_a_tag_can_be_nested_under_another(): void
    {
        $workspace = $this->workspace();
        $parent = $this->tag($workspace, 'engineering');
        $child = $this->tag($workspace, 'backend');

        $this->actingAs($workspace->owner)
            ->patchJson("/api/v1/workspaces/{$workspace->id}/tags/{$child->id}", [
                'parent_id' => $parent->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.parent_id', $parent->id);

        $this->assertDatabaseHas('tags', [
            'id' => $child->id,
            'parent_id' => $parent->id,
        ]);
    }

    public function test_a_tag_can_be_promoted_back_to_a_root(): void
    {
        $workspace = $this->workspace();
        $parent = $this->tag($workspace, 'engineering');
        $child = $this->tag($workspace, 'backend', $parent->id);

        $this->actingAs($workspace->owner)
            ->patchJson("/api/v1/workspaces/{$workspace->id}/tags/{$child->id}", [
                'parent_id' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.parent_id', null);
    }

    public function test_a_tag_cannot_be_its_own_parent(): void
    {
        $workspace = $this->workspace();
        $tag = $this->tag($workspace, 'ideas');

        $this->actingAs($workspace->owner)
            ->patchJson("/api/v1/workspaces/{$workspace->id}/tags/{$tag->id}", [
                'parent_id' => $tag->id,
            ])
            ->assertStatus(422);
    }

    public function test_a_tag_cannot_be_nested_under_its_own_descendant(): void
    {
        $workspace = $this->workspace();
        $grandparent = $this->tag($workspace, 'engineering');
        $child = $this->tag($workspace, 'backend', $grandparent->id);
        $grandchild = $this->tag($workspace, 'php', $child->id);

        // Making the grandparent a child of its own grandchild would close the
        // loop, so it must be refused rather than stored.
        $this->actingAs($workspace->owner)
            ->patchJson("/api/v1/workspaces/{$workspace->id}/tags/{$grandparent->id}", [
                'parent_id' => $grandchild->id,
            ])
            ->assertStatus(422);
    }

    public function test_a_parent_from_another_workspace_is_rejected(): void
    {
        $mine = $this->workspace();
        $theirs = $this->workspace();
        $tag = $this->tag($mine, 'ideas');
        $foreign = $this->tag($theirs, 'elsewhere');

        $this->actingAs($mine->owner)
            ->patchJson("/api/v1/workspaces/{$mine->id}/tags/{$tag->id}", [
                'parent_id' => $foreign->id,
            ])
            ->assertStatus(422);
    }

    public function test_a_tag_from_another_workspace_reads_as_absent(): void
    {
        $mine = $this->workspace();
        $theirs = $this->workspace();
        $foreign = $this->tag($theirs, 'elsewhere');

        $this->actingAs($mine->owner)
            ->patchJson("/api/v1/workspaces/{$mine->id}/tags/{$foreign->id}", [
                'name' => 'hijacked',
            ])
            ->assertNotFound();
    }

    public function test_a_guest_cannot_touch_the_tag_vocabulary(): void
    {
        $workspace = $this->workspace();
        $tag = $this->tag($workspace, 'ideas');

        $this->getJson("/api/v1/workspaces/{$workspace->id}/tags")
            ->assertUnauthorized();

        $this->patchJson("/api/v1/workspaces/{$workspace->id}/tags/{$tag->id}", [
            'name' => 'nope',
        ])->assertUnauthorized();
    }
}
