<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user's public profile, without email or private account settings.
 *
 * @mixin User
 */
class PublicUserResource extends JsonResource
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
            'created_at' => $this->created_at,
            'owned_workspaces' => PublicWorkspaceResource::collection(
                $this->whenLoaded('ownedWorkspaces')
            ),
        ];
    }
}
