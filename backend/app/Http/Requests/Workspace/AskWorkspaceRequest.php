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
        ];
    }

    public function question(): string
    {
        return trim((string) $this->validated('question'));
    }
}
