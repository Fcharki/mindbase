<?php

namespace App\Http\Requests\MembershipRequest;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApproveJoinRequest extends FormRequest
{
    /**
     * Approving is restricted to the workspace owner by
     * WorkspacePolicy::manageMembers().
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The role is optional and falls back to the role the requester asked for.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'role' => [
                'nullable',
                Rule::in(['member', 'viewer']),
            ],
        ];
    }
}
