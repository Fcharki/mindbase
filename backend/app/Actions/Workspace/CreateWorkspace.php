<?php

namespace App\Actions\Workspace;

use App\Actions\Action;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class CreateWorkspace implements Action
{
    public function __construct(
        private readonly string $name,
        private readonly User $owner,
    ) {}

    /**
     * Create a workspace with the creator as its owner.
     *
     * The owner is recorded twice on purpose: owner_id is the authoritative
     * answer for WorkspacePolicy, and the members row is what the rest of the
     * application reads roles from, so both are written together or not at all.
     */
    public function handle(): Workspace
    {
        return DB::transaction(function (): Workspace {
            $workspace = Workspace::create([
                'name' => $this->name,
                'owner_id' => $this->owner->id,
            ]);

            $workspace->members()->attach($this->owner->id, [
                'role' => 'owner',
            ]);

            return $workspace;
        });
    }
}
