<?php

namespace App\Http\Requests\KnowledgeEntry;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating and editing an entry take the same payload, so both endpoints
 * share this contract.
 */
class KnowledgeEntryRequest extends FormRequest
{
    /**
     * Authorization is handled by KnowledgeEntryPolicy through
     * Gate::authorize() in the controller, which keeps it ahead of the 404
     * that nested routes return for entries in another workspace.
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
            'title' => [
                'required',
                'string',
                'max:255',
            ],

            'content' => [
                'nullable',
                'string',
            ],
        ];
    }
}
