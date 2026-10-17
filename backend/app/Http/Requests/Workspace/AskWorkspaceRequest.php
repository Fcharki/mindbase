<?php

namespace App\Http\Requests\Workspace;

use Illuminate\Foundation\Http\FormRequest;

class AskWorkspaceRequest extends FormRequest
{
    /**
     * Gated by WorkspacePolicy::view() in the controller.
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
            'question' => ['required', 'string', 'max:500'],
            // How many passages the answer may cite. Bounded so a client cannot
            // ask for the whole index and pin a worker to a giant prompt.
            'top_k' => ['sometimes', 'integer', 'min:1', 'max:20'],
            // Whether retrieval may rewrite the question when the literal words
            // find nothing.
            'rewrite' => ['sometimes', 'boolean'],
        ];
    }

    public function question(): string
    {
        return trim((string) $this->validated('question'));
    }

    public function topK(): int
    {
        return (int) ($this->validated('top_k') ?? 8);
    }

    public function rewrite(): bool
    {
        return (bool) $this->validated('rewrite', false);
    }
}
