<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the response contract that the resources in App\Http\Resources define.
 *
 * The value of a resource layer is that the shape of a response is decided in
 * one place, so these assertions are about the shape rather than any single
 * endpoint's behaviour.
 */
class ResourceContractTest extends TestCase
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

    public function test_workspace_index_omits_members_while_show_includes_them(): void
    {
        $workspace = $this->workspace();
        $workspace->members()->attach(User::factory()->create()->id, [
            'role' => 'member',
        ]);

        $indexed = $this->actingAs($workspace->owner)
            ->getJson('/api/v1/workspaces')
            ->assertOk()
            ->json('data.0');

        // The index lists every workspace, so a member list here would have
        // been scoped to the caller and would not match the detail endpoint.
        $this->assertArrayNotHasKey('members', $indexed);
        $this->assertArrayHasKey('entries_count', $indexed);
        $this->assertSame('owner', $indexed['role']);

        $shown = $this->actingAs($workspace->owner)
            ->getJson("/api/v1/workspaces/{$workspace->id}")
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $shown['members']);
        $this->assertSame(
            ['member', 'owner'],
            collect($shown['members'])->pluck('pivot.role')->sort()->values()->all()
        );
    }

    public function test_workspace_role_reflects_the_calling_users_membership(): void
    {
        $workspace = $this->workspace();
        $viewer = User::factory()->create();
        $workspace->members()->attach($viewer->id, ['role' => 'viewer']);

        $this->actingAs($viewer)
            ->getJson('/api/v1/workspaces')
            ->assertOk()
            ->assertJsonPath('data.0.role', 'viewer');

        $this->actingAs($workspace->owner)
            ->getJson('/api/v1/workspaces')
            ->assertOk()
            ->assertJsonPath('data.0.role', 'owner');
    }

    public function test_the_member_endpoints_and_the_workspace_detail_agree_on_a_member_shape(): void
    {
        $workspace = $this->workspace();
        $member = User::factory()->create();
        $workspace->members()->attach($member->id, ['role' => 'member']);

        $added = $this->actingAs($workspace->owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/members", [
                'email' => $member->email,
                'role' => 'viewer',
            ])
            ->assertStatus(409);

        $this->assertSame(
            'That user is already a member of this workspace.',
            $added->json('message')
        );

        $changed = $this->actingAs($workspace->owner)
            ->patchJson("/api/v1/workspaces/{$workspace->id}/members/{$member->id}", [
                'role' => 'viewer',
            ])
            ->assertOk()
            ->json('data');

        $fromDetail = $this->actingAs($workspace->owner)
            ->getJson("/api/v1/workspaces/{$workspace->id}")
            ->assertOk()
            ->json('data.members');

        $this->assertContains($changed, $fromDetail);
    }

    public function test_a_document_never_exposes_its_storage_internals(): void
    {
        $workspace = $this->workspace();
        $entry = KnowledgeEntry::create([
            'workspace_id' => $workspace->id,
            'created_by' => $workspace->owner_id,
            'title' => 'Entry',
            'content' => 'Body',
        ]);

        Document::create([
            'knowledge_entry_id' => $entry->id,
            'uploaded_by' => $workspace->owner_id,
            'disk' => 'documents',
            'path' => 'acme/deadbeef.pdf',
            'original_name' => 'notes.pdf',
            'mime_type' => 'application/pdf',
            'size' => 2048,
            'checksum' => 'a-checksum',
        ]);

        $document = $this->actingAs($workspace->owner)
            ->getJson("/api/v1/workspaces/{$workspace->id}/entries/{$entry->id}/documents")
            ->assertOk()
            ->json('data.0');

        $this->assertArrayNotHasKey('path', $document);
        $this->assertArrayNotHasKey('checksum', $document);
        $this->assertSame('2 KB', $document['human_size']);
        $this->assertSame(
            ['id', 'name'],
            array_keys($document['uploader'])
        );
    }

    public function test_the_invite_code_lookup_exposes_less_than_the_workspace_detail(): void
    {
        $workspace = $this->workspace();

        $this->getJson("/api/v1/join/{$workspace->invite_code}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['id', 'name', 'invite_code', 'owner' => ['id', 'name']],
            ])
            ->assertJsonMissingPath('data.owner_id')
            ->assertJsonMissingPath('data.role')
            ->assertJsonMissingPath('data.members');
    }
}
