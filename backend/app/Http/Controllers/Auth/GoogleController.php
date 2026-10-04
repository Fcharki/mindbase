<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleController extends Controller
{
    /**
     * Redirect the user to Google.
     */
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Handle Google's callback.
     */
    public function callback(): RedirectResponse
    {
        $googleUser = Socialite::driver('google')->user();

        // find the user using their Google ID
        $user = User::where('google_id', $googleUser->getId())->first();

        // if they don't have a Google ID yet, try their email
        if (! $user) {
            $user = User::where('email', $googleUser->getEmail())->first();

            if ($user) {
                // link the Google account to the existing email account
                $user->update([
                    'google_id' => $googleUser->getId(),
                ]);
            } else {
                // Completely new user.
                $user = User::create([
                    'name' => $googleUser->getName(),
                    'email' => $googleUser->getEmail(),
                    'password' => Str::random(40),
                    'google_id' => $googleUser->getId(),
                    'email_verified_at' => now(),
                ]);
            }
        }

        // Google verified the email address, so mark it as verified.
        if ($user->email_verified_at === null) {
            $user->update(['email_verified_at' => now()]);
        }

        // Authenticate the user using Laravel's session.
        Auth::login($user);
        request()->session()->regenerate();

        return redirect(
            config('app.frontend_url').'/auth/google/callback'
        );
    }
}
