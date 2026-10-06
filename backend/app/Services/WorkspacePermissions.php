<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * The single source of truth for what a workspace role may do.
 *
 * Roles are not a flat read/write split: a viewer joins the discussion but is
 * kept away from the documents themselves. Unknown roles are denied.
 */
final class WorkspacePermissions
{
    /** Start a thread, or reply inside one. */
    public const DISCUSS = 'discuss';

    /** Create or change content: entries, tags, document uploads. */
    public const CONTRIBUTE = 'contribute';

    /** Retrieve the stored bytes behind a document. */
    public const DOWNLOAD = 'download';

    /** Remove content belonging to other people. */
    public const MODERATE = 'moderate';

    /**
     * Owner can do anything; a member adds and takes documents but only
     * moderates their own words; a viewer can talk and nothing else.
     *
     * @var array<string, list<string>>
     */
    private const MATRIX = [
        'owner' => [self::DISCUSS, self::CONTRIBUTE, self::DOWNLOAD, self::MODERATE],
        'member' => [self::DISCUSS, self::CONTRIBUTE, self::DOWNLOAD],
        'viewer' => [self::DISCUSS],
    ];

    /** @return list<string> */
    public static function capabilities(): array
    {
        return [self::DISCUSS, self::CONTRIBUTE, self::DOWNLOAD, self::MODERATE];
    }

    /**
     * The user's role in a workspace, or null if they are not a member.
     *
     * Ownership lives on workspaces.owner_id rather than the pivot, so both
     * halves must be consulted to answer this once.
     */
    public function roleOf(User $user, int $workspaceId): ?string
    {
        $ownerId = Workspace::whereKey($workspaceId)->value('owner_id');

        if ($ownerId === null) {
            return null;
        }

        if ((int) $ownerId === (int) $user->id) {
            return 'owner';
        }

        $role = DB::table('workspace_members')
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $user->id)
            ->value('role');

        return $role === null ? null : (string) $role;
    }

    /**
     * Whether the role grants the capability. An unknown role is denied, so a
     * typo in a seed or migration fails closed instead of granting write access.
     */
    public function grants(?string $role, string $capability): bool
    {
        if ($role === null) {
            return false;
        }

        return in_array($capability, self::MATRIX[$role] ?? [], true);
    }

    /** Whether the user holds the capability in the given workspace. */
    public function can(User $user, int $workspaceId, string $capability): bool
    {
        return $this->grants($this->roleOf($user, $workspaceId), $capability);
    }

    /** Membership is the precondition for several capabilities, not one itself. */
    public function isMember(User $user, int $workspaceId): bool
    {
        return $this->roleOf($user, $workspaceId) !== null;
    }

    public function isOwner(User $user, int $workspaceId): bool
    {
        return $this->roleOf($user, $workspaceId) === 'owner';
    }

    public function canDiscuss(User $user, int $workspaceId): bool
    {
        return $this->can($user, $workspaceId, self::DISCUSS);
    }

    public function canContribute(User $user, int $workspaceId): bool
    {
        return $this->can($user, $workspaceId, self::CONTRIBUTE);
    }

    public function canDownload(User $user, int $workspaceId): bool
    {
        return $this->can($user, $workspaceId, self::DOWNLOAD);
    }

    public function canModerate(User $user, int $workspaceId): bool
    {
        return $this->can($user, $workspaceId, self::MODERATE);
    }
}
