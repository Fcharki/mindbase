<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
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

    public function test_it_updates_the_username_without_touching_verification(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/api/v1/profile', [
                'username' => 'new-name',
                'email' => $user->email,
            ])
            ->assertOk()
            ->assertJsonPath('user.username', 'new-name');

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_changing_the_email_resets_verification_and_notifies(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/api/v1/profile', [
                'username' => $user->username,
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
                'username' => $user->username,
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

    public function test_it_updates_bio_and_location(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/api/v1/profile', [
                'username' => $user->username,
                'email' => $user->email,
                'bio' => 'Backend engineer who likes small tools.',
                'location' => 'Casablanca, Morocco',
            ])
            ->assertOk()
            ->assertJsonPath('user.bio', 'Backend engineer who likes small tools.')
            ->assertJsonPath('user.location', 'Casablanca, Morocco');

        $this->assertSame(
            'Backend engineer who likes small tools.',
            $user->fresh()->bio
        );
    }

    public function test_bio_and_location_can_be_cleared(): void
    {
        $user = User::factory()->create([
            'bio' => 'Old bio',
            'location' => 'Old location',
        ]);

        $this->actingAs($user)
            ->patchJson('/api/v1/profile', [
                'username' => $user->username,
                'email' => $user->email,
                'bio' => null,
                'location' => null,
            ])
            ->assertOk()
            ->assertJsonPath('user.bio', null)
            ->assertJsonPath('user.location', null);
    }

    public function test_it_uploads_replaces_and_removes_a_profile_photo(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/api/v1/profile/photo', [
                'photo' => UploadedFile::fake()->createWithContent(
                    'avatar.png',
                    $this->pngBytes()
                ),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('message', 'Photo updated.');

        $firstPath = $user->fresh()->photo_path;

        $this->assertNotNull($firstPath);
        Storage::disk('local')->assertExists($firstPath);

        $this->actingAs($user)
            ->post('/api/v1/profile/photo', [
                'photo' => UploadedFile::fake()->createWithContent(
                    'avatar.png',
                    $this->pngBytes()
                ),
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $secondPath = $user->fresh()->photo_path;

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('local')->assertMissing($firstPath);

        $this->actingAs($user)
            ->deleteJson('/api/v1/profile/photo')
            ->assertOk()
            ->assertJsonPath('user.photo_url', null);

        $this->assertNull($user->fresh()->photo_path);
        Storage::disk('local')->assertMissing($secondPath);
    }

    public function test_a_photo_is_rejected_when_it_is_not_an_image(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/api/v1/profile/photo', [
                'photo' => UploadedFile::fake()->createWithContent(
                    'notes.txt',
                    'not an image'
                ),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photo');
    }

    public function test_a_photo_is_served_without_a_session(): void
    {
        Storage::fake('local');

        $user = User::factory()->create([
            'photo_path' => 'profile-photos/1/avatar.png',
        ]);

        Storage::disk('local')->put($user->photo_path, $this->pngBytes());

        $this->get("/api/v1/users/{$user->id}/photo")
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
    }

    public function test_a_missing_photo_is_not_found(): void
    {
        $user = User::factory()->create();

        $this->get("/api/v1/users/{$user->id}/photo")->assertNotFound();
    }

    public function test_a_viewer_sees_the_owners_public_profile(): void
    {
        $owner = User::factory()->create([
            'bio' => 'Builds things.',
            'location' => 'Rabat',
            'photo_path' => 'profile-photos/owner/avatar.png',
        ]);

        $workspace = Workspace::create([
            'name' => 'Acme',
            'owner_id' => $owner->id,
        ]);

        $workspace->members()->attach($owner->id, ['role' => 'owner']);

        $viewer = User::factory()->create();

        $this->actingAs($viewer)
            ->getJson("/api/v1/workspaces/{$workspace->id}")
            ->assertOk()
            ->assertJsonPath('data.owner.username', $owner->username)
            ->assertJsonPath('data.owner.bio', 'Builds things.')
            ->assertJsonPath('data.owner.location', 'Rabat')
            ->assertJsonPath(
                'data.owner.photo_url',
                route('users.photo', ['user' => $owner->id])
            );
    }

    private function pngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8'
            .'/x8AAusB9Wl2n0kAAAAASUVORK5CYII='
        );
    }
}
