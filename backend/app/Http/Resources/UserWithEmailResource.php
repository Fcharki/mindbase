<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user embedded in a workspace context, where the caller needs to see who
 * they are and how to reach them (workspace owners, membership requesters).
 *
 * @mixin User
 */
class UserWithEmailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            // Read-only profile details a viewer sees for a workspace owner.
            'bio' => $this->bio,
            'location' => $this->location,
            'photo_url' => $this->photoUrl(),
        ];
    }
}
