<?php

namespace App\Http\Requests\KnowledgeEntry;

use Illuminate\Foundation\Http\FormRequest;

class SyncTagsRequest extends FormRequest
{
    /**
     * Retagging an entry is an edit, gated by KnowledgeEntryPolicy::update().
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The list is the full desired state of the entry's tags, not a patch, so
     * a payload without any tags clears them.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'tags' => [
                'required',
                'array',
                'max:50',
            ],

            'tags.*' => [
                'required',
                'string',
                'max:60',
            ],
        ];
    }
}
