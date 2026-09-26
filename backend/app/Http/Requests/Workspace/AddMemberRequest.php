<?php

namespace App\Http\Requests\Workspace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddMemberRequest extends FormRequest
{
    /**
     * Adding a member is restricted to the workspace owner by
     * WorkspacePolicy::manageMembers().
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The email is not required to belong to a registered account here; the
     * AddWorkspaceMember action rejects unknown addresses with a 422 so the
     * owner gets a clearer message than a validation error would give.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
            ],

            'role' => [
                'required',
                Rule::in(['member', 'viewer']),
            ],
        ];
    }
}
