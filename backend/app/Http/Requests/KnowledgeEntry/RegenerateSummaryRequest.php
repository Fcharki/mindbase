<?php

namespace App\Http\Requests\KnowledgeEntry;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Regenerating a stored summary, optionally naming the model to do it with.
 */
class RegenerateSummaryRequest extends FormRequest
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
            // Omitted means "whatever the service is configured to use", which
            // is not a third option worth offering here.
            'backend' => [
                'nullable',
                'string',
                Rule::in(config('ai.backends')),
            ],
        ];
    }
}
