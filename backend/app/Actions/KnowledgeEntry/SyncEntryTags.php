<?php

namespace App\Actions\KnowledgeEntry;

use App\Actions\Action;
use App\Models\KnowledgeEntry;
use App\Models\Tag;
use App\Services\TagService;
use Illuminate\Support\Collection;

class SyncEntryTags implements Action
{
    public function __construct(
        private readonly KnowledgeEntry $entry,
        /** @var list<string> */
        private readonly array $tags,
    ) {}

    /**
     * Replace the entry's tags with the given set.
     *
     * @return Collection<int, Tag>
     */
    public function handle(): Collection
    {
        return app(TagService::class)->syncForEntry($this->entry, $this->tags);
    }
}
