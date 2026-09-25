<?php

namespace App\Services\Search;

use App\Models\KnowledgeEntry;
use App\Models\Tag;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Full-text search across knowledge entries and tags.
 *
 * On Postgres this uses the native tsvector / plainto_tsquery search backed by
 * the GIN expression indexes created in the search migration. On any other
 * driver (sqlite in tests, etc.) it falls back to a simple LIKE match so the
 * feature keeps working everywhere in this project's given setup.
 */
class SearchService
{
    private const TS_CONFIGURATION = 'english';

    /**
     * @return array{query: string, entries: Collection<int, KnowledgeEntry>, tags: Collection<int, Tag>}
     */
    public function search(
        Workspace $workspace,
        string $query,
        ?string $tag = null,
        int $limit = 20
    ): array {
        $query = trim($query);
        $tag = $tag !== null ? mb_strtolower(trim($tag)) : null;

        if ($query === '' && $tag === null) {
            return [
                'query' => '',
                'entries' => collect(),
                'tags' => collect(),
            ];
        }

        return [
            'query' => $query,
            'entries' => $this->searchEntries($workspace, $query, $tag, $limit),
            'tags' => $query === ''
                ? collect()
                : $this->searchTags($workspace, $query, $limit),
        ];
    }

    /**
     * Entries matching the query in their title or content, or tagged with a
     * matching tag. When $tag is given the results are restricted to entries
     * carrying that tag.
     *
     * @return Collection<int, KnowledgeEntry>
     */
    public function searchEntries(
        Workspace $workspace,
        string $query,
        ?string $tag = null,
        int $limit = 20
    ): Collection {
        $entries = $workspace->knowledgeEntries()
            ->with('creator:id,name', 'tags:id,name,workspace_id')
            ->withCount('tags');

        if ($this->isPostgres() && $query !== '') {
            $entries->select('knowledge_entries.*')
                ->selectRaw($this->documentRank().' AS relevance', [$query]);
        }

        if ($query !== '') {
            $entries->where(function (Builder $where) use ($query): void {
                if ($this->isPostgres()) {
                    $where->whereRaw($this->documentMatch(), [$query]);
                } else {
                    $where
                        ->where('knowledge_entries.title', 'like', "%{$query}%")
                        ->orWhere('knowledge_entries.content', 'like', "%{$query}%");
                }

                $where->orWhereHas('tags', function (Builder $tags) use ($query): void {
                    if ($this->isPostgres()) {
                        $tags->whereRaw(
                            "to_tsvector('".self::TS_CONFIGURATION."', tags.name) @@ plainto_tsquery('".self::TS_CONFIGURATION."', ?)",
                            [$query]
                        );
                    } else {
                        $tags->where('tags.name', 'like', "%{$query}%");
                    }
                });
            });
        }

        if ($tag !== null) {
            $entries->whereHas('tags', fn (Builder $tags) => $tags->where('tags.name', $tag));
        }

        if ($this->isPostgres() && $query !== '') {
            $entries->orderByDesc('relevance');
        }

        return $entries
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Tags whose name matches the query, with the number of entries each is
     * attached to.
     *
     * @return Collection<int, Tag>
     */
    public function searchTags(
        Workspace $workspace,
        string $query,
        int $limit = 10
    ): Collection {
        $tags = $workspace->tags()->withCount('entries');

        if ($query !== '') {
            if ($this->isPostgres()) {
                $tags->whereRaw(
                    "to_tsvector('".self::TS_CONFIGURATION."', tags.name) @@ plainto_tsquery('".self::TS_CONFIGURATION."', ?)",
                    [$query]
                );
            } else {
                $tags->where('tags.name', 'like', "%{$query}%");
            }
        }

        return $tags->orderBy('name')->limit($limit)->get();
    }

    private function documentMatch(): string
    {
        return $this->documentVector()
            ." @@ plainto_tsquery('".self::TS_CONFIGURATION."', ?)";
    }

    private function documentRank(): string
    {
        return 'ts_rank('
            .$this->documentVector()
            .", plainto_tsquery('".self::TS_CONFIGURATION."', ?))";
    }

    /**
     * The tsvector expression used for title + content. It must stay in sync
     * with the expression indexed by the search migration so Postgres can use
     * the GIN index.
     */
    private function documentVector(): string
    {
        return "to_tsvector('".self::TS_CONFIGURATION."', regexp_replace(coalesce(knowledge_entries.title, '') || ' ' || coalesce(knowledge_entries.content, ''), '<[^>]+>', ' ', 'g'))";
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
}
