<?php

namespace App\Http\Requests\Workspace;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Retrieval options for regenerating an answer.
 *
 * Separate from AskWorkspaceRequest because the question itself is not part of
 * it: a regenerate re-asks what is already stored on the message.
 */
class AskOptionsRequest extends FormRequest
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
            'top_k' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'rewrite' => ['sometimes', 'boolean'],
        ];
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
