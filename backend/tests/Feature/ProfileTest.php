<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_authenticated_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.has_password', true);
    }

    public function test_a_google_only_account_reports_no_password(): void
    {
        $user = User::factory()->create(['google_id' => 'google-1']);

        $this->actingAs($user)
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('user.has_password', false);
    }

    public function test_it_updates_the_name_without_touching_verification(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/api/v1/profile', [
                'name' => 'New Name',
                'email' => $user->email,
            ])
            ->assertOk()
            ->assertJsonPath('user.name', 'New Name');

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_changing_the_email_resets_verification_and_notifies(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/api/v1/profile', [
                'name' => $user->name,
                'email' => 'changed@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('user.email', 'changed@example.com')
            ->assertJsonPath('user.email_verified_at', null);

        $this->assertNull($user->fresh()->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_it_rejects_an_email_already_in_use(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/api/v1/profile', [
                'name' => $user->name,
                'email' => $other->email,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_it_updates_the_password_with_the_current_one(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/api/v1/profile/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_it_rejects_a_wrong_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/api/v1/profile/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_deleting_requires_the_matching_email_and_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->deleteJson('/api/v1/profile', [
                'email' => 'someone-else@example.com',
                'password' => 'password',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_deleting_removes_the_user_and_owned_workspaces(): void
    {
        $user = User::factory()->create();

        $workspace = Workspace::create([
            'name' => 'Acme',
            'owner_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->deleteJson('/api/v1/profile', [
                'email' => $user->email,
                'password' => 'password',
            ])
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('workspaces', ['id' => $workspace->id]);
    }

    public function test_a_google_only_account_can_delete_without_a_password(): void
    {
        $user = User::factory()->create(['google_id' => 'google-1']);

        $this->actingAs($user)
            ->deleteJson('/api/v1/profile', [
                'email' => $user->email,
            ])
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }
}
