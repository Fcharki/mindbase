<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user together with their role on a workspace.
 *
 * The role lives on the pivot, so the model handed to this resource has to
 * come from Workspace::members() for "pivot" to be populated.
 *
 * @mixin User
 */
class WorkspaceMemberResource extends JsonResource
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
            'pivot' => [
                'role' => $this->pivot?->role,
            ],
        ];
    }
}
