<?php

namespace App\Http\Requests\Comment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating and editing a comment take the same payload, so both endpoints
 * share this contract.
 */
class CommentRequest extends FormRequest
{
    /**
     * Authorization is handled by CommentPolicy through Gate::authorize() in the
     * controller, which keeps it ahead of the 404 that nested routes return for
     * an entry in another workspace.
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
            'body' => [
                'required',
                'string',
                'max:5000',
                // A body of nothing but whitespace would render as an empty
                // comment that still occupies a row and a line in the
                // discussion, so it is rejected rather than trimmed.
                'not_regex:/^\s*$/',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.not_regex' => 'Write something before posting.',
        ];
    }
}
