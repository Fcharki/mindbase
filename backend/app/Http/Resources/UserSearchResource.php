<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One user returned by username search.
 *
 * @mixin User
 */
class UserSearchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'bio' => $this->bio,
            'location' => $this->location,
            'photo_url' => $this->photoUrl(),
            'owned_workspaces_count' => $this->owned_workspaces_count ?? 0,
        ];
    }
}
