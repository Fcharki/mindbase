<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceMembershipTest extends TestCase
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

    public function test_owner_can_add_a_member_by_email(): void
    {
        $workspace = $this->workspace();
        $user = User::factory()->create();

        $response = $this->actingAs($workspace->owner)
            ->postJson("/api/workspaces/{$workspace->id}/members", [
                'email' => $user->email,
                'role' => 'member',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.pivot.role', 'member');

        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'member',
        ]);
    }

    public function test_member_cannot_manage_members(): void
    {
        $workspace = $this->workspace();
        $member = User::factory()->create();
        $workspace->members()->attach($member->id, ['role' => 'member']);

        $response = $this->actingAs($member)
            ->postJson("/api/workspaces/{$workspace->id}/members", [
                'email' => User::factory()->create()->email,
                'role' => 'member',
            ]);

        $response->assertForbidden();
    }

    public function test_adding_an_unregistered_email_returns_422(): void
    {
        $workspace = $this->workspace();

        $response = $this->actingAs($workspace->owner)
            ->postJson("/api/workspaces/{$workspace->id}/members", [
                'email' => 'nobody@example.com',
                'role' => 'member',
            ]);

        $response->assertStatus(422);
    }

    public function test_adding_an_existing_member_returns_409(): void
    {
        $workspace = $this->workspace();
        $member = User::factory()->create();
        $workspace->members()->attach($member->id, ['role' => 'viewer']);

        $response = $this->actingAs($workspace->owner)
            ->postJson("/api/workspaces/{$workspace->id}/members", [
                'email' => $member->email,
                'role' => 'viewer',
            ]);

        $response->assertStatus(409);
    }

    public function test_owner_can_change_a_members_role(): void
    {
        $workspace = $this->workspace();
        $member = User::factory()->create();
        $workspace->members()->attach($member->id, ['role' => 'member']);

        $response = $this->actingAs($workspace->owner)
            ->patchJson("/api/workspaces/{$workspace->id}/members/{$member->id}", [
                'role' => 'viewer',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.pivot.role', 'viewer');
    }

    public function test_owner_cannot_change_their_own_role(): void
    {
        $workspace = $this->workspace();

        $response = $this->actingAs($workspace->owner)
            ->patchJson("/api/workspaces/{$workspace->id}/members/{$workspace->owner_id}", [
                'role' => 'member',
            ]);

        $response->assertStatus(422);
    }

    public function test_owner_can_remove_a_member(): void
    {
        $workspace = $this->workspace();
        $member = User::factory()->create();
        $workspace->members()->attach($member->id, ['role' => 'member']);

        $response = $this->actingAs($workspace->owner)
            ->deleteJson("/api/workspaces/{$workspace->id}/members/{$member->id}");

        $response->assertOk();

        $this->assertDatabaseMissing('workspace_members', [
            'workspace_id' => $workspace->id,
            'user_id' => $member->id,
        ]);
    }

    public function test_owner_cannot_remove_themselves(): void
    {
        $workspace = $this->workspace();

        $response = $this->actingAs($workspace->owner)
            ->deleteJson("/api/workspaces/{$workspace->id}/members/{$workspace->owner_id}");

        $response->assertStatus(422);
    }

    public function test_workspace_gets_an_invite_code_on_creation(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->postJson('/api/workspaces', ['name' => 'New team']);

        $response->assertCreated();

        $this->assertNotNull(
            $response->json('data.invite_code')
        );
    }

    public function test_workspace_is_publicly_lookupable_by_invite_code(): void
    {
        $workspace = $this->workspace();

        $response = $this->getJson("/api/join/{$workspace->invite_code}");

        $response->assertOk()
            ->assertJsonPath('data.id', $workspace->id)
            ->assertJsonPath('data.name', 'Acme')
            ->assertJsonPath('data.owner.name', $workspace->owner->name);
    }

    public function test_unknown_invite_code_returns_404(): void
    {
        $this->getJson('/api/join/does-not-exist')->assertNotFound();
    }
}
