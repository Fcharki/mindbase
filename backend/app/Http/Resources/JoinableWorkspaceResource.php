<?php

namespace App\Http\Resources;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A workspace as shown to somebody who has an invite code but no account
 * context yet: no role, no membership, and an owner reduced to a name.
 *
 * @mixin Workspace
 */
class JoinableWorkspaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'invite_code' => $this->invite_code,
            'owner' => UserSummaryResource::make($this->whenLoaded('owner')),
        ];
    }
}
