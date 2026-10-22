<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGoogleUser(?string $id, ?string $email, ?string $name = 'Ada Lovelace'): void
    {
        $socialite = new SocialiteUser;

        $socialite->id = $id;
        $socialite->email = $email;
        $socialite->name = $name;

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialite);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_redirect_sends_the_user_to_google(): void
    {
        config([
            'services.google.client_id' => 'client-id',
            'services.google.redirect' => 'http://localhost:8001/api/v1/auth/google/callback',
        ]);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com/o/oauth2/auth?client_id=client-id'));

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get('/api/v1/auth/google')
            ->assertRedirect('https://accounts.google.com/o/oauth2/auth?client_id=client-id');
    }

    public function test_a_new_google_user_is_created_and_logged_in(): void
    {
        config(['app.frontend_url' => 'http://localhost:3001']);

        $this->fakeGoogleUser('google-123', 'ada@example.com');

        $response = $this->get('/api/v1/auth/google/callback');

        $response->assertRedirect('http://localhost:3001/auth/google/callback');

        $user = User::where('email', 'ada@example.com')->first();

        $this->assertNotNull($user);
        $this->assertSame('google-123', $user->google_id);
        $this->assertSame('ada-lovelace', $user->username);

        // Google has already verified the address, so demanding a second
        // confirmation email would be asking the user to prove a thing Google
        // already proved.
        $this->assertNotNull($user->email_verified_at);

        $this->assertAuthenticatedAs($user);
    }

    public function test_returning_google_user_is_logged_back_in(): void
    {
        config(['app.frontend_url' => 'http://localhost:3001']);

        $user = User::factory()->create([
            'google_id' => 'google-123',
            'email' => 'ada@example.com',
        ]);

        $this->fakeGoogleUser('google-123', 'ada@example.com');

        $this->get('/api/v1/auth/google/callback')
            ->assertRedirect('http://localhost:3001/auth/google/callback');

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::count());
    }

    public function test_an_existing_email_account_is_linked_rather_than_duplicated(): void
    {
        config(['app.frontend_url' => 'http://localhost:3001']);

        $user = User::factory()->create([
            'google_id' => null,
            'email' => 'ada@example.com',
        ]);

        $this->fakeGoogleUser('google-123', 'ada@example.com');

        $this->get('/api/v1/auth/google/callback');

        $user->refresh();

        $this->assertSame('google-123', $user->google_id);
        $this->assertSame(1, User::count());
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_linked_account_is_marked_verified_on_sign_in(): void
    {
        config(['app.frontend_url' => 'http://localhost:3001']);

        $user = User::factory()->create([
            'google_id' => 'google-123',
            'email' => 'ada@example.com',
            'email_verified_at' => null,
        ]);

        $this->fakeGoogleUser('google-123', 'ada@example.com');

        $this->get('/api/v1/auth/google/callback');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }
}
