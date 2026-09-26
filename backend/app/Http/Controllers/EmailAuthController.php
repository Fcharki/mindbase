<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\EmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class EmailAuthController extends Controller
{
    public function __construct(
        private EmailService $emailService
    ) {}

    /**
     * Register a new user.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        $this->emailService->sendVerificationEmail($user);

        return response()->json([
            'message' => 'Account created successfully. Please verify your email.',
            'user' => UserResource::make($user),
        ], 201);
    }

    /**
     * Login.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid email or password.',
            ], 401);
        }

        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Please verify your email before logging in.',
                'email_verified' => false,
            ], 403);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json([
            'message' => 'Login successful.',
            'user' => UserResource::make($user),
        ]);
    }

    /**
     * Logout.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Get authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => UserResource::make($request->user()),
        ]);
    }

    /**
     * Verify email.
     */
    public function verifyEmail(
        Request $request,
        int $id,
        string $hash
    ): Response {
        if (! URL::hasValidSignature($request)) {
            return redirect(config('app.frontend_url').'/auth/email-verified?status=invalid');
        }

        $user = User::findOrFail($id);

        if (! hash_equals(
            sha1($user->getEmailForVerification()),
            $hash
        )) {
            return redirect(config('app.frontend_url').'/auth/email-verified?status=invalid');
        }

        if ($user->hasVerifiedEmail()) {
            return redirect(config('app.frontend_url').'/auth/email-verified?status=already-verified');
        }

        $user->markEmailAsVerified();

        return redirect(config('app.frontend_url').'/auth/email-verified?status=success');
    }

    /**
     * Resend verification email.
     */
    public function resendVerificationEmail(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email is already verified.',
            ]);
        }

        $this->emailService->sendVerificationEmail($user);

        return response()->json([
            'message' => 'Verification email sent successfully.',
        ]);
    }

    /**
     * Send password reset email.
     */
    public function forgotPassword(
        ForgotPasswordRequest $request
    ): JsonResponse {
        $validated = $request->validated();

        $status = $this->emailService->sendPasswordResetEmail(
            $validated['email']
        );

        if ($status !== Password::RESET_LINK_SENT) {
            return response()->json([
                'message' => __($status),
            ], 400);
        }

        return response()->json([
            'message' => 'If an account with that email exists, a password reset link has been sent.',
        ]);
    }

    /**
     * Reset password.
     */
    public function resetPassword(
        ResetPasswordRequest $request
    ): JsonResponse {
        $validated = $request->validated();

        $status = Password::reset(
            [
                'email' => $validated['email'],
                'password' => $validated['password'],
                'password_confirmation' => $validated['password_confirmation'],
                'token' => $validated['token'],
            ],
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                ])->save();

                // Invalidate all existing Sanctum tokens.
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => __($status),
            ], 400);
        }

        return response()->json([
            'message' => 'Password reset successfully.',
        ]);
    }
}
