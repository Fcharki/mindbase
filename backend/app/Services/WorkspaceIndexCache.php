<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Caches the per-caller workspace list served at GET /workspaces.
 *
 * That query is the widest read in the application: a left join to the caller's
 * membership, a count of every workspace's entry total, and each owner, all of
 * it rebuilt on every load of the workspace picker. Caching it per caller is
 * safe only while a workspace, an entry, or a membership changing can be seen to
 * change it, so every such write bumps one shared version instead of every
 * caller's key individually. Keys carrying the old version are left to expire.
 */
class WorkspaceIndexCache
{
    private const VERSION_KEY = 'workspaces:index:version';

    /**
     * A backstop for a write that misses its invalidation, not the usual path:
     * every write that can change the list bumps the version, so nothing is
     * meant to age out at all.
     */
    private const TTL_SECONDS = 300;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function remember(int $userId, Closure $callback): mixed
    {
        return Cache::remember(
            sprintf('workspaces:index:%d:%s', $userId, $this->version()),
            self::TTL_SECONDS,
            $callback,
        );
    }

    /**
     * Invalidate every caller's cached list.
     *
     * The version is replaced with a fresh random value rather than incremented,
     * so a write that runs twice cannot leave the version where it started and
     * keep serving the old list.
     */
    public function flush(): void
    {
        Cache::forever(self::VERSION_KEY, (string) Str::uuid());
    }

    private function version(): string
    {
        return (string) Cache::get(self::VERSION_KEY, 'initial');
    }
}
