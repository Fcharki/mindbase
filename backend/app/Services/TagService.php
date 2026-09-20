<?php

namespace App\Services;

use App\Models\KnowledgeEntry;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Collection;

class TagService
{
    /**
     * Attach tags to an entry by name.
     *
     * Tags are created within the entry's workspace when they don't exist
     * yet, thanks to the unique (workspace_id, name) constraint. This is
     * the persistence path the AI service will call once tag generation
     * exists.
     *
     * @param  array<int, string>  $names
     * @return Collection<int, Tag>
     */
    public function syncForEntry(KnowledgeEntry $entry, array $names): mixed
    {
        $names = array_values(array_unique(array_map(
            fn (string $name) => trim(mb_strtolower($name)),
            $names
        )));

        $names = array_filter($names, fn (string $name) => $name !== '');

        $ids = collect($names)->map(function (string $name) use ($entry) {
            return Tag::firstOrCreate([
                'workspace_id' => $entry->workspace_id,
                'name' => $name,
            ])->id;
        });

        $entry->tags()->sync($ids);

        return $entry->tags()->get();
    }
}
