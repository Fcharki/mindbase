<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MembershipRequestTest extends TestCase
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

    public function test_user_can_request_to_join_a_workspace(): void
    {
        $workspace = $this->workspace();
        $requester = User::factory()->create();

        $response = $this->actingAs($requester)
            ->postJson("/api/workspaces/{$workspace->id}/join-requests", [
                'role' => 'viewer',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.requested_role', 'viewer')
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('membership_requests', [
            'workspace_id' => $workspace->id,
            'user_id' => $requester->id,
            'status' => 'pending',
        ]);
    }

    public function test_owner_cannot_request_their_own_workspace(): void
    {
        $workspace = $this->workspace();

        $response = $this->actingAs($workspace->owner)
            ->postJson("/api/workspaces/{$workspace->id}/join-requests");

        $response->assertStatus(409);
    }

    public function test_member_cannot_request_to_join(): void
    {
        $workspace = $this->workspace();
        $member = User::factory()->create();
        $workspace->members()->attach($member->id, ['role' => 'member']);

        $response = $this->actingAs($member)
            ->postJson("/api/workspaces/{$workspace->id}/join-requests");

        $response->assertStatus(409);
    }

    public function test_duplicate_pending_request_returns_409(): void
    {
        $workspace = $this->workspace();
        $requester = User::factory()->create();
        $workspace->membershipRequests()->create([
            'user_id' => $requester->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($requester)
            ->postJson("/api/workspaces/{$workspace->id}/join-requests");

        $response->assertStatus(409);
    }

    public function test_only_owner_can_view_pending_requests(): void
    {
        $workspace = $this->workspace();
        $member = User::factory()->create();
        $workspace->members()->attach($member->id, ['role' => 'member']);

        $response = $this->actingAs($member)
            ->getJson("/api/workspaces/{$workspace->id}/join-requests");

        $response->assertForbidden();
    }

    public function test_owner_sees_only_pending_requests(): void
    {
        $workspace = $this->workspace();
        $requester = User::factory()->create();
        $workspace->membershipRequests()->create([
            'user_id' => $requester->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($workspace->owner)
            ->getJson("/api/workspaces/{$workspace->id}/join-requests");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.id', $requester->id);
    }

    public function test_owner_can_approve_a_request_and_assign_role(): void
    {
        $workspace = $this->workspace();
        $requester = User::factory()->create();
        $request = $workspace->membershipRequests()->create([
            'user_id' => $requester->id,
            'status' => 'pending',
            'requested_role' => 'viewer',
        ]);

        $response = $this->actingAs($workspace->owner)
            ->postJson(
                "/api/workspaces/{$workspace->id}/join-requests/{$request->id}/approve",
                ['role' => 'member']
            );

        $response->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $workspace->id,
            'user_id' => $requester->id,
            'role' => 'member',
        ]);
    }

    public function test_approve_defaults_to_requested_role(): void
    {
        $workspace = $this->workspace();
        $requester = User::factory()->create();
        $request = $workspace->membershipRequests()->create([
            'user_id' => $requester->id,
            'status' => 'pending',
            'requested_role' => 'viewer',
        ]);

        $this->actingAs($workspace->owner)
            ->postJson("/api/workspaces/{$workspace->id}/join-requests/{$request->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $workspace->id,
            'user_id' => $requester->id,
            'role' => 'viewer',
        ]);
    }

    public function test_owner_can_reject_a_request(): void
    {
        $workspace = $this->workspace();
        $requester = User::factory()->create();
        $request = $workspace->membershipRequests()->create([
            'user_id' => $requester->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($workspace->owner)
            ->deleteJson("/api/workspaces/{$workspace->id}/join-requests/{$request->id}");

        $response->assertOk();

        $this->assertDatabaseMissing('membership_requests', [
            'id' => $request->id,
        ]);
    }

    public function test_rejected_user_can_request_again(): void
    {
        $workspace = $this->workspace();
        $requester = User::factory()->create();
        $request = $workspace->membershipRequests()->create([
            'user_id' => $requester->id,
            'status' => 'pending',
        ]);

        $this->actingAs($workspace->owner)
            ->deleteJson("/api/workspaces/{$workspace->id}/join-requests/{$request->id}")
            ->assertOk();

        $this->actingAs($requester)
            ->postJson("/api/workspaces/{$workspace->id}/join-requests")
            ->assertCreated();
    }
}
