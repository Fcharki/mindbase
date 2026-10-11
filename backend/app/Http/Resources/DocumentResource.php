<?php

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Document metadata for the API.
 *
 * The on-disk location and the checksum are storage internals and are
 * deliberately absent: the browser addresses a document by id and downloads it
 * through the API, so neither is ever something a client needs to see.
 *
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'knowledge_entry_id' => $this->knowledge_entry_id,
            'uploaded_by' => $this->uploaded_by,
            'disk' => $this->disk,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'human_size' => $this->human_size,
            'extraction_status' => $this->extraction_status,
            'uploader' => UserSummaryResource::make($this->whenLoaded('uploader')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
