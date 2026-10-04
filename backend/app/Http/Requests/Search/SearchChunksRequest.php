<?php

namespace App\Http\Requests\Search;

use Illuminate\Foundation\Http\FormRequest;

class SearchChunksRequest extends FormRequest
{
    /**
     * Retrieval is gated by WorkspacePolicy::view() in the controller.
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
            'q' => [
                'required',
                'string',
                'max:500',
            ],

            'limit' => [
                'nullable',
                'integer',
                'min:1',
                'max:25',
            ],
        ];
    }

    public function searchTerm(): string
    {
        return trim((string) $this->validated('q'));
    }

    /**
     * Capped lower than the entry search because every hit here is a passage
     * with its body attached, and the payload grows with the limit.
     */
    public function limit(): int
    {
        return (int) $this->validated('limit', 8);
    }
}
