<?php

namespace App\Http\Requests\MembershipRequest;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJoinRequest extends FormRequest
{
    /**
     * Requesting to join is open to any authenticated user; whether it is
     * allowed at all depends on the caller's relationship to the workspace,
     * which the RequestWorkspaceMembership action checks.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The role is optional and defaults to "member" when omitted.
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
