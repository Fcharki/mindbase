<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The authenticated user.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            // Google-only accounts have no password the user knows, so the
            // delete confirmation must not ask for one.
            'has_password' => $this->google_id === null,
            'bio' => $this->bio,
            'location' => $this->location,
            'photo_url' => $this->photoUrl(),
            'cover_url' => $this->coverUrl(),
        ];
    }
}
