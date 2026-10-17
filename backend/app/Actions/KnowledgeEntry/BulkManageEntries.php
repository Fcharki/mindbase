<?php

namespace App\Actions\KnowledgeEntry;

use App\Actions\Action;
use App\Exceptions\ActionFailedException;
use App\Models\KnowledgeEntry;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Applies one change to many entries at once.
 *
 * Exists so a list of thirty entries can be tidied in one request rather than
 * thirty, and so the same change is authorized against every entry, not just
 * the first. Entries are never merged across workspaces: the workspace is part
 * of the query, so an id from elsewhere reads as not found.
 */
class BulkManageEntries implements Action
{
    public const ACTION_DELETE = 'delete';

    public const ACTION_TAG = 'tag';

    public const ACTION_UNTAG = 'untag';

    public const ACTION_PIN = 'pin';

    public const ACTION_UNPIN = 'unpin';

    /**
     * @param  list<int>  $entryIds
     * @param  list<string>  $tags
     */
    public function __construct(
        private readonly Workspace $workspace,
        private readonly array $entryIds,
        private readonly string $action,
        private readonly array $tags = [],
    ) {}

    /**
     * @throws ActionFailedException
     */
    public function handle(): array
    {
        if (! in_array($this->action, self::actions(), true)) {
            throw new ActionFailedException('Unknown bulk action.', 422);
        }

        if ($this->entryIds === []) {
            throw new ActionFailedException('Select at least one entry.', 422);
        }

        if (in_array($this->action, [self::ACTION_TAG, self::ACTION_UNTAG], true) && $this->tags === []) {
            throw new ActionFailedException('Provide at least one tag.', 422);
        }

        $entries = $this->workspace->knowledgeEntries()
            ->whereIn('id', $this->entryIds)
            ->get();

        if ($entries->isEmpty()) {
            throw new ActionFailedException('None of those entries are in this workspace.', 404);
        }

        foreach ($entries as $entry) {
            $this->authorize($entry);
        }

        return DB::transaction(function () use ($entries) {
            $affected = 0;

            foreach ($entries as $entry) {
                if ($this->apply($entry)) {
                    $affected++;
                }
            }

            return [
                'action' => $this->action,
                'requested' => count($this->entryIds),
                'affected' => $affected,
                // Ids that were not found in this workspace, so the caller can
                // tell a partial success from a silent one.
                'missing' => array_values(array_diff(
                    $this->entryIds,
                    $entries->pluck('id')->all()
                )),
            ];
        });
    }

    private function apply(KnowledgeEntry $entry): bool
    {
        return match ($this->action) {
            self::ACTION_DELETE => (bool) $entry->delete(),
            self::ACTION_PIN => $entry->update(['is_pinned' => true]),
            self::ACTION_UNPIN => $entry->update(['is_pinned' => false]),
            self::ACTION_TAG => $this->syncNames($entry, $this->tags, true),
            self::ACTION_UNTAG => $this->syncNames($entry, $this->tags, false),
            default => false,
        };
    }

    /**
     * @param  list<string>  $names
     */
    private function syncNames(KnowledgeEntry $entry, array $names, bool $attach): bool
    {
        $normalized = array_values(array_unique(array_map(
            fn (string $name) => trim(mb_strtolower($name)),
            $names
        )));

        $normalized = array_values(array_filter($normalized, fn (string $name) => $name !== ''));

        if ($normalized === []) {
            return false;
        }

        if ($attach) {
            app(SyncEntryTags::class, [
                'entry' => $entry,
                'tags' => array_values(array_unique(array_merge(
                    $entry->tags()->pluck('name')->all(),
                    $normalized
                ))),
            ])->handle();

            return true;
        }

        $remaining = $entry->tags()
            ->pluck('name')
            ->map(fn (string $name) => mb_strtolower($name))
            ->reject(fn (string $name) => in_array($name, $normalized, true))
            ->values()
            ->all();

        app(SyncEntryTags::class, [
            'entry' => $entry,
            'tags' => $remaining,
        ])->handle();

        return true;
    }

    private function authorize(KnowledgeEntry $entry): void
    {
        $ability = $this->action === self::ACTION_DELETE ? 'delete' : 'update';

        Gate::authorize($ability, $entry);
    }

    /**
     * @return list<string>
     */
    public static function actions(): array
    {
        return [
            self::ACTION_DELETE,
            self::ACTION_TAG,
            self::ACTION_UNTAG,
            self::ACTION_PIN,
            self::ACTION_UNPIN,
        ];
    }
}
