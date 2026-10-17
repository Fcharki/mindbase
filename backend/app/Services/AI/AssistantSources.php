<?php

namespace App\Services\AI;

use App\Services\Search\HybridSearchResult;
use Illuminate\Support\Collection;

/**
 * Shapes retrieved passages into the `sources` column of an answer.
 *
 * The stored shape is the contract for two later readers: the prompt formatter,
 * which renders them for the model, and the citation UI, which links each one
 * back to the entry and exact chunk it came from. Keeping the mapping in one
 * place is what stops asking and regenerating from storing different shapes.
 */
class AssistantSources
{
    /**
     * @param  Collection<int, HybridSearchResult>  $passages
     * @return list<array<string, mixed>>
     */
    public static function fromPassages(Collection $passages): array
    {
        return $passages
            ->map(fn (HybridSearchResult $passage) => [
                'entry_id' => $passage->chunk->knowledge_entry_id,
                // The chunk itself, so a citation can be opened at the exact
                // passage the model read rather than at the entry as a whole.
                'chunk_id' => $passage->chunk->id,
                'position' => $passage->chunk->position,
                'source' => $passage->chunk->source,
                'title' => $passage->chunk->entry?->title,
                'body' => $passage->chunk->body,
                'found_by' => $passage->foundBy,
            ])
            ->values()
            ->all();
    }
}
