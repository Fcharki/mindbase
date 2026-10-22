<?php

namespace App\Http\Controllers;

use App\Http\Requests\Profile\DeleteProfileRequest;
use App\Http\Requests\Profile\UpdatePasswordRequest;
use App\Http\Requests\Profile\UpdatePhotoRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\EmailService;
use App\Services\WorkspaceIndexCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        $usernameChanged = $validated['username'] !== $user->username;

        $user->username = $validated['username'];

        if (array_key_exists('bio', $validated)) {
            $user->bio = $validated['bio'];
        }

        if (array_key_exists('location', $validated)) {
            $user->location = $validated['location'];
        }

        if ($emailChanged) {
            $user->email = $validated['email'];
            $user->email_verified_at = null;
        }

        $user->save();

        if ($usernameChanged) {
            app(WorkspaceIndexCache::class)->flush();
        }

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
     * Store a new profile photo, replacing the previous one.
     */
    public function updatePhoto(UpdatePhotoRequest $request): JsonResponse
    {
        $user = $request->user();
        $disk = Storage::disk('local');
        $previousPath = $user->photo_path;

        $path = $request->file('photo')->store(
            'profile-photos/'.$user->getKey(),
            'local'
        );

        if ($path === false) {
            abort(500, 'The photo could not be stored.');
        }

        $user->photo_path = $path;
        $user->save();

        if ($previousPath !== null) {
            $disk->delete($previousPath);
        }

        return response()->json([
            'message' => 'Photo updated.',
            'user' => UserResource::make($user->fresh()),
        ]);
    }

    /**
     * Remove the profile photo.
     */
    public function destroyPhoto(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->photo_path !== null) {
            Storage::disk('local')->delete($user->photo_path);

            $user->photo_path = null;
            $user->save();
        }

        return response()->json([
            'message' => 'Photo removed.',
            'user' => UserResource::make($user->fresh()),
        ]);
    }

    /**
     * Serve a user's photo.
     *
     * Photos are deliberately public: they are part of the read-only profile a
     * viewer sees for a workspace owner. Streaming them keeps the private
     * storage directory out of the web root.
     */
    public function photo(User $user): StreamedResponse
    {
        abort_if($user->photo_path === null, 404);

        $disk = Storage::disk('local');

        abort_unless($disk->exists($user->photo_path), 404);

        return $disk->response($user->photo_path, headers: [
            'Cache-Control' => 'public, max-age=86400',
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
