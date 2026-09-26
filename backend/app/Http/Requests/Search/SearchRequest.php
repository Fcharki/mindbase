<?php

namespace App\Http\Requests\Search;

use Illuminate\Foundation\Http\FormRequest;

class SearchRequest extends FormRequest
{
    /**
     * Searching is gated by WorkspacePolicy::view() in the controller.
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
                'nullable',
                'string',
                'max:100',
            ],

            'tag' => [
                'nullable',
                'string',
                'max:60',
            ],

            'limit' => [
                'nullable',
                'integer',
                'min:1',
                'max:50',
            ],
        ];
    }

    public function searchTerm(): string
    {
        return (string) $this->validated('q', '');
    }

    public function tag(): ?string
    {
        return $this->validated('tag');
    }

    public function limit(): int
    {
        return (int) $this->validated('limit', 20);
    }
}
