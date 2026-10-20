<?php

namespace App\Http\Resources;

use App\Models\EntryImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Image metadata for the API.
 *
 * The on-disk path and checksum are storage internals. The editor only needs
 * the absolute URL it can put in an <img> tag.
 *
 * @mixin EntryImage
 */
class EntryImageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'url' => $this->url(),
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'human_size' => $this->human_size,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
