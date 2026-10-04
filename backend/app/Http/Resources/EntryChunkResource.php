<?php

namespace App\Http\Resources;

use App\Models\EntryChunk;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An embedded passage of an entry.
 *
 * The vector itself is never serialised. The caller asked a question in words
 * and got an answer in words; a thousand floats back would be noise, and it is
 * the single largest field in the table.
 *
 * @mixin EntryChunk
 */
class EntryChunkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'knowledge_entry_id' => $this->knowledge_entry_id,
            'source' => $this->source,
            'position' => $this->position,
            'body' => $this->body,

            // Just enough of the entry to navigate to the passage's source.
            // Deliberately not the full resource: a retrieved chunk is shown
            // inline, and shipping every entry's markup with every hit would be
            // most of the response.
            'entry' => $this->whenLoaded('entry', fn () => [
                'id' => $this->entry->id,
                'title' => $this->entry->title,
            ]),
        ];
    }
}
