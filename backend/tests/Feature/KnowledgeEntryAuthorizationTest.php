<?php

namespace Tests\Feature;

use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\SummarizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class KnowledgeEntryAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests are about who may do what, so nothing should reach the
        // models. Writing an entry queues background work, and a queue that is
        // not faked runs it inline against whatever happens to be listening.
        Queue::fake();
    }

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
            'content' => 'Body',
        ], $attributes));
    }

    public function test_member_can_create_an_entry(): void
    {
        $workspace = $this->workspace();
        $member = User::factory()->create();
        $workspace->members()->attach($member->id, ['role' => 'member']);

        $response = $this->actingAs($member)
            ->postJson("/api/v1/workspaces/{$workspace->id}/entries", [
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
            ->postJson("/api/v1/workspaces/{$workspace->id}/entries", [
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
            ->getJson("/api/v1/workspaces/{$workspace->id}/entries/{$entry->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $entry->id);
    }

    public function test_default_viewer_can_browse_workspace_entries(): void
    {
        $workspace = $this->workspace();
        $viewer = User::factory()->create();
        $entry = $this->entry($workspace, $workspace->owner);

        $this->actingAs($viewer)
            ->getJson("/api/v1/workspaces/{$workspace->id}")
            ->assertOk()
            ->assertJsonPath('data.role', 'viewer');

        $this->actingAs($viewer)
            ->getJson("/api/v1/workspaces/{$workspace->id}/entries")
            ->assertOk()
            ->assertJsonPath('data.0.id', $entry->id);

        $this->actingAs($viewer)
            ->getJson("/api/v1/workspaces/{$workspace->id}/entries/{$entry->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $entry->id);
    }

    public function test_is_member_separates_a_real_viewer_from_a_stranger(): void
    {
        $workspace = $this->workspace();
        $realViewer = User::factory()->create();
        $stranger = User::factory()->create();

        $workspace->members()->attach($realViewer->id, ['role' => 'viewer']);

        // Both are reported as role "viewer", so the role alone cannot tell the
        // frontend which panels will 403.
        $this->actingAs($realViewer)
            ->getJson("/api/v1/workspaces/{$workspace->id}")
            ->assertOk()
            ->assertJsonPath('data.role', 'viewer')
            ->assertJsonPath('data.is_member', true);

        $this->actingAs($stranger)
            ->getJson("/api/v1/workspaces/{$workspace->id}")
            ->assertOk()
            ->assertJsonPath('data.role', 'viewer')
            ->assertJsonPath('data.is_member', false);

        $this->actingAs($workspace->owner)
            ->getJson("/api/v1/workspaces/{$workspace->id}")
            ->assertOk()
            ->assertJsonPath('data.role', 'owner')
            ->assertJsonPath('data.is_member', true);
    }

    public function test_default_viewer_cannot_create_an_entry(): void
    {
        $workspace = $this->workspace();
        $viewer = User::factory()->create();

        $this->actingAs($viewer)
            ->postJson("/api/v1/workspaces/{$workspace->id}/entries", [
                'title' => 'My entry',
                'content' => 'Body',
            ])
            ->assertForbidden();
    }

    public function test_default_viewer_can_summarize_an_entry(): void
    {
        $workspace = $this->workspace();
        $viewer = User::factory()->create();
        $entry = $this->entry($workspace, $workspace->owner, [
            'content' => '<p>Important shared knowledge.</p>',
        ]);

        $service = new class extends SummarizationService
        {
            public function __construct() {}

            public function summarize(string $text): string
            {
                return 'A concise summary of the shared knowledge.';
            }
        };

        $this->app->instance(SummarizationService::class, $service);

        $this->actingAs($viewer)
            ->postJson("/api/v1/workspaces/{$workspace->id}/entries/{$entry->id}/summarize")
            ->assertOk()
            ->assertJsonPath(
                'data.summary',
                'A concise summary of the shared knowledge.'
            );
    }

    public function test_member_can_update_an_entry(): void
    {
        $workspace = $this->workspace();
        $member = User::factory()->create();
        $workspace->members()->attach($member->id, ['role' => 'member']);
        $entry = $this->entry($workspace, $workspace->owner);

        $response = $this->actingAs($member)
            ->putJson("/api/v1/workspaces/{$workspace->id}/entries/{$entry->id}", [
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
            ->putJson("/api/v1/workspaces/{$workspace->id}/entries/{$entry->id}", [
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
            ->deleteJson("/api/v1/workspaces/{$workspace->id}/entries/{$entry->id}");

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
            ->deleteJson("/api/v1/workspaces/{$workspace->id}/entries/{$entry->id}");

        $response->assertForbidden();
    }

    public function test_only_owner_can_update_workspace(): void
    {
        $workspace = $this->workspace();
        $member = User::factory()->create();
        $workspace->members()->attach($member->id, ['role' => 'member']);

        $response = $this->actingAs($member)
            ->putJson("/api/v1/workspaces/{$workspace->id}", ['name' => 'Renamed']);

        $response->assertForbidden();

        $this->actingAs($workspace->owner)
            ->putJson("/api/v1/workspaces/{$workspace->id}", ['name' => 'Renamed'])
            ->assertOk();
    }
}
