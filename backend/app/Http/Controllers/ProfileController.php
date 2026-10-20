<?php

namespace App\Http\Controllers;

use App\Http\Requests\Profile\DeleteProfileRequest;
use App\Http\Requests\Profile\UpdatePasswordRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\EmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProfileController extends Controller
{
    public function __construct(
        private EmailService $emailService
    ) {}

    /**
     * The authenticated user.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'user' => UserResource::make($request->user()),
        ]);
    }

    /**
     * Update name and email.
     *
     * Changing the email address invalidates the old verification, because the
     * address Google (or the previous confirmation email) vouched for is no
     * longer the one on the account.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $emailChanged = strcasecmp($validated['email'], $user->email) !== 0;

        $user->name = $validated['name'];

        if ($emailChanged) {
            $user->email = $validated['email'];
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $this->emailService->sendVerificationEmail($user);
        }

        return response()->json([
            'message' => $emailChanged
                ? 'Profile updated. Check your new email address to verify it.'
                : 'Profile updated.',
            'user' => UserResource::make($user->fresh()),
        ]);
    }

    /**
     * Change the password.
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        // The cast on the model hashes this; forceFill bypasses mass-assignment
        // guards that would otherwise stop a password update here.
        $user->forceFill([
            'password' => $request->validated('password'),
        ])->save();

        return response()->json([
            'message' => 'Password updated successfully.',
        ]);
    }

    /**
     * Delete the account.
     *
     * Owned workspaces (and their entries) cascade away with the user, which
     * is why the request demands the typed email and, where one exists, the
     * password.
     */
    public function destroy(DeleteProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json([
            'message' => 'Account deleted.',
        ]);
    }
}
