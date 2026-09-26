<?php

namespace App\Http\Requests\Workspace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRoleRequest extends FormRequest
{
    /**
     * Changing a role is restricted to the workspace owner by
     * WorkspacePolicy::manageMembers().
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
            'role' => [
                'required',
                Rule::in(['member', 'viewer']),
            ],
        ];
    }
}
