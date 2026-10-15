<?php

namespace Tests\Feature;

use App\Actions\Workspace\UpdateWorkspaceMemberRole;
use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceIndexCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The cache on the workspace list, and the writes that have to clear it.
 *
 * The list is a join, a per-workspace entry count, and each owner, so it is
 * worth not rebuilding on every load. That is only safe if a workspace, an
 * entry, or a membership changing is reflected, so those writes are exercised
 * too rather than trusted.
 */
class WorkspaceIndexCacheTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): Workspace
    {
        $owner = User::factory()->create();

        $workspace = Workspace::create([
            'name' => 'Acme',
            'owner_id' => $owner->id,
        ]);

        $workspace->members()->attach($owner->id, ['role' => 'owner']);

        return $workspace;
    }

    public function test_the_callback_runs_once_for_repeated_reads(): void
    {
        $cache = app(WorkspaceIndexCache::class);
        $calls = 0;

        $build = function () use (&$calls) {
            $calls++;

            return collect(['first']);
        };

        $first = $cache->remember(1, $build);
        $second = $cache->remember(1, $build);

        $this->assertSame(1, $calls);
        $this->assertEquals($first, $second);
    }

    public function test_each_caller_gets_their_own_entry(): void
    {
        $cache = app(WorkspaceIndexCache::class);
        $calls = 0;

        $build = function () use (&$calls) {
            $calls++;

            return collect(['value']);
        };

        $cache->remember(1, $build);
        $cache->remember(2, $build);

        $this->assertSame(2, $calls);
    }

    public function test_flushing_rebuilds_the_next_read(): void
    {
        $cache = app(WorkspaceIndexCache::class);
        $calls = 0;

        $build = function () use (&$calls) {
            $calls++;

            return collect(['value']);
        };

        $cache->remember(1, $build);
        $cache->flush();
        $cache->remember(1, $build);

        $this->assertSame(2, $calls);
    }

    public function test_repeated_requests_are_served_from_the_cache(): void
    {
        $workspace = $this->workspace();

        $first = $this->actingAs($workspace->owner)
            ->getJson('/api/v1/workspaces')
            ->assertOk()
            ->json('data');

        // Written around the model layer so nothing clears the cache. If the
        // second read rebuilds, it will pick this name up; if it is cached, it
        // will not.
        DB::table('workspaces')
            ->where('id', $workspace->id)
            ->update(['name' => 'Renamed behind the cache']);

        $second = $this->actingAs($workspace->owner)
            ->getJson('/api/v1/workspaces')
            ->assertOk()
            ->json('data');

        $this->assertSame($first, $second);
        $this->assertSame('Acme', $second[0]['name']);
    }

    public function test_a_new_entry_is_visible_on_the_next_read(): void
    {
        $workspace = $this->workspace();

        $this->actingAs($workspace->owner)
            ->getJson('/api/v1/workspaces')
            ->assertOk()
            ->assertJsonPath('data.0.entries_count', 0);

        KnowledgeEntry::create([
            'workspace_id' => $workspace->id,
            'created_by' => $workspace->owner_id,
            'title' => 'Fresh',
            'content' => 'Body',
        ]);

        $this->actingAs($workspace->owner)
            ->getJson('/api/v1/workspaces')
            ->assertOk()
            ->assertJsonPath('data.0.entries_count', 1);
    }

    public function test_a_role_change_is_visible_on_the_next_read(): void
    {
        $workspace = $this->workspace();
        $user = User::factory()->create();

        $workspace->members()->attach($user->id, ['role' => 'viewer']);

        $this->actingAs($user)
            ->getJson('/api/v1/workspaces')
            ->assertOk()
            ->assertJsonPath('data.0.role', 'viewer');

        app(UpdateWorkspaceMemberRole::class, [
            'workspace' => $workspace,
            'user' => $user,
            'role' => 'member',
        ])->handle();

        $this->actingAs($user)
            ->getJson('/api/v1/workspaces')
            ->assertOk()
            ->assertJsonPath('data.0.role', 'member');
    }
}
