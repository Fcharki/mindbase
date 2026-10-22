<?php

namespace Tests\Feature;

use App\Models\KnowledgeEntry;
use App\Models\MembershipRequest;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(User $owner, string $name = 'Acme'): Workspace
    {
        $workspace = Workspace::create([
            'name' => $name,
            'owner_id' => $owner->id,
        ]);

        $workspace->members()->attach($owner->id, ['role' => 'owner']);

        return $workspace;
    }

    public function test_the_directory_requires_authentication(): void
    {
        $this->getJson('/api/v1/users?q=san')->assertUnauthorized();
        $this->getJson('/api/v1/users/SANA')->assertUnauthorized();
    }

    public function test_search_matches_usernames_case_insensitively_without_email(): void
    {
        $viewer = User::factory()->create(['username' => 'viewer']);
        User::factory()->create(['username' => 'sana']);
        User::factory()->create(['username' => 'santiago']);
        User::factory()->create(['username' => 'someone-else']);

        $response = $this->actingAs($viewer)
            ->getJson('/api/v1/users?q=@SAN')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertSame(
            ['sana', 'santiago'],
            collect($response->json('data'))->pluck('username')->all(),
        );

        $this->assertArrayNotHasKey('email', $response->json('data.0'));
    }

    public function test_search_excludes_the_caller_and_caps_results(): void
    {
        $viewer = User::factory()->create(['username' => 'person-00']);

        foreach (range(1, 25) as $index) {
            User::factory()->create([
                'username' => sprintf('person-%02d', $index),
            ]);
        }

        $response = $this->actingAs($viewer)
            ->getJson('/api/v1/users?q=person')
            ->assertOk()
            ->assertJsonCount(20, 'data');

        $this->assertNotContains(
            'person-00',
            collect($response->json('data'))->pluck('username')->all(),
        );
    }

    public function test_an_empty_search_returns_no_people(): void
    {
        $viewer = User::factory()->create();

        $this->actingAs($viewer)
            ->getJson('/api/v1/users?q=')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_public_profile_lists_owned_workspaces_and_the_viewers_membership_state(): void
    {
        $owner = User::factory()->create([
            'username' => 'sana',
            'bio' => 'Builds shared knowledge bases.',
            'location' => 'Berlin',
        ]);
        $viewer = User::factory()->create(['username' => 'viewer']);

        $joined = $this->workspace($owner, 'Joined workspace');
        $joined->members()->attach($viewer->id, ['role' => 'member']);
        KnowledgeEntry::create([
            'workspace_id' => $joined->id,
            'created_by' => $owner->id,
            'title' => 'One entry',
            'content' => 'Body',
        ]);

        $pending = $this->workspace($owner, 'Pending workspace');
        $pending->membershipRequests()->create([
            'user_id' => $viewer->id,
            'status' => MembershipRequest::STATUS_PENDING,
            'requested_role' => 'member',
        ]);

        $available = $this->workspace($owner, 'Available workspace');

        $response = $this->actingAs($viewer)
            ->getJson('/api/v1/users/SANA')
            ->assertOk()
            ->assertJsonPath('data.username', 'sana')
            ->assertJsonPath('data.bio', 'Builds shared knowledge bases.')
            ->assertJsonPath('data.location', 'Berlin')
            ->assertJsonMissingPath('data.email')
            ->assertJsonCount(3, 'data.owned_workspaces');

        $workspaces = collect($response->json('data.owned_workspaces'))->keyBy('id');

        $this->assertSame('member', $workspaces[$joined->id]['role']);
        $this->assertTrue($workspaces[$joined->id]['is_member']);
        $this->assertSame(1, $workspaces[$joined->id]['entries_count']);

        $this->assertSame('viewer', $workspaces[$pending->id]['role']);
        $this->assertFalse($workspaces[$pending->id]['is_member']);
        $this->assertSame('pending', $workspaces[$pending->id]['join_request_status']);

        $this->assertSame('viewer', $workspaces[$available->id]['role']);
        $this->assertFalse($workspaces[$available->id]['is_member']);
        $this->assertNull($workspaces[$available->id]['join_request_status']);
    }

    public function test_an_unknown_username_returns_not_found(): void
    {
        $viewer = User::factory()->create();

        $this->actingAs($viewer)
            ->getJson('/api/v1/users/nobody-here')
            ->assertNotFound();
    }

    public function test_registration_normalizes_and_requires_a_unique_username(): void
    {
        $payload = [
            'username' => '@Sana',
            'email' => 'sana@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ];

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertCreated()
            ->assertJsonPath('user.username', 'sana');

        $this->assertDatabaseHas('users', [
            'username' => 'sana',
            'email' => 'sana@example.com',
        ]);

        $this->postJson('/api/v1/auth/register', [
            ...$payload,
            'username' => 'SANA',
            'email' => 'another@example.com',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');
    }

    public function test_updating_a_profile_rejects_another_users_username(): void
    {
        $user = User::factory()->create(['username' => 'sana']);
        User::factory()->create(['username' => 'taken']);

        $this->actingAs($user)
            ->patchJson('/api/v1/profile', [
                'username' => 'taken',
                'email' => $user->email,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');
    }
}
