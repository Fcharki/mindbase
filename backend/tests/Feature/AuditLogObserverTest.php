<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AuditLogObserverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_audit_log_created_when_workspace_created(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $workspace = Workspace::create([
            'name' => 'Test',
            'owner_id' => $owner->id,
        ]);
        $workspace->members()->attach($owner->id, ['role' => 'owner']);

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'workspace',
            'action' => 'created',
            'workspace_id' => $workspace->id,
        ]);
    }

    public function test_each_action_is_logged_exactly_once(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $workspace = Workspace::create([
            'name' => 'Once',
            'owner_id' => $owner->id,
        ]);
        $workspace->members()->attach($owner->id, ['role' => 'owner']);

        // The observer must be registered once: a second registration would
        // double every row here.
        $this->assertDatabaseCount('audit_logs', 1);

        $workspace->update(['name' => 'Renamed']);

        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_audit_log_created_when_knowledge_entry_created(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create([
            'name' => 'Test',
            'owner_id' => $owner->id,
        ]);
        $workspace->members()->attach($owner->id, ['role' => 'owner']);

        $this->actingAs($owner);
        $entry = $workspace->knowledgeEntries()->create([
            'title' => 'Entry',
            'content' => 'Content',
            'created_by' => $owner->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'knowledge_entry',
            'action' => 'created',
            'workspace_id' => $workspace->id,
            'entity_id' => $entry->id,
        ]);
    }

    public function test_audit_log_created_when_member_added(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = $this->workspaceFor($owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/members", [
                'email' => $member->email,
                'role' => 'member',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'member',
            'action' => 'added',
            'workspace_id' => $workspace->id,
            'entity_id' => $member->id,
        ]);
    }

    public function test_audit_log_created_when_join_request_approved(): void
    {
        $owner = User::factory()->create();
        $requester = User::factory()->create();
        $workspace = $this->workspaceFor($owner);

        $this->actingAs($requester)->postJson(
            "/api/v1/workspaces/{$workspace->id}/join-requests",
            ['role' => 'member'],
        )->assertCreated();

        $requestId = $workspace->membershipRequests()->firstOrFail()->id;

        $this->actingAs($owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/join-requests/{$requestId}/approve")
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'join_request',
            'action' => 'approved',
            'workspace_id' => $workspace->id,
        ]);
    }

    private function workspaceFor(User $owner): Workspace
    {
        $workspace = Workspace::create([
            'name' => 'Test',
            'owner_id' => $owner->id,
        ]);
        $workspace->members()->attach($owner->id, ['role' => 'owner']);

        return $workspace;
    }
}
