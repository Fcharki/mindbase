<?php

namespace App\Http\Requests\KnowledgeEntry;

use App\Actions\KnowledgeEntry\BulkManageEntries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkEntriesRequest extends FormRequest
{
    /**
     * Gated by WorkspacePolicy::view() in the controller, then per-entry inside
     * the action, because one request can touch many entries.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(BulkManageEntries::actions())],
            'entry_ids' => ['required', 'array', 'min:1', 'max:200'],
            'entry_ids.*' => ['integer'],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['string', 'max:50'],
        ];
    }

    /**
     * @return list<int>
     */
    public function entryIds(): array
    {
        return array_values(array_map(
            'intval',
            (array) $this->validated('entry_ids', [])
        ));
    }

    public function action(): string
    {
        return (string) $this->validated('action');
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return array_values((array) $this->validated('tags', []));
    }
}
