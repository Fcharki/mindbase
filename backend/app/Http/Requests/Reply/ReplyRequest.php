<?php

namespace App\Http\Requests\Reply;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating and editing a reply take the same payload, so both endpoints share
 * this contract. Mirrors CommentRequest; kept separate rather than shared so the
 * two can diverge without touching every comment endpoint.
 */
class ReplyRequest extends FormRequest
{
    /**
     * Authorization is handled by ReplyPolicy through Gate::authorize() in the
     * controller, which keeps it ahead of the 404 that nested routes return.
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
                // See CommentRequest: whitespace alone is not a post.
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
