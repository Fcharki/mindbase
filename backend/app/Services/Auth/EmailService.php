<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Password;

class EmailService
{
    /**
     * Send email verification.
     */
    public function sendVerificationEmail(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        $user->sendEmailVerificationNotification();
    }

    /**
     * Send password reset email.
     */
    public function sendPasswordResetEmail(string $email): string
    {
        return Password::sendResetLink([
            'email' => $email,
        ]);
    }
}
