<?php

namespace App\Http\Requests\Workspace;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating and renaming a workspace take the same payload, so both endpoints
 * share this contract.
 */
class WorkspaceRequest extends FormRequest
{
    /**
     * Authorization is handled by WorkspacePolicy through Gate::authorize() in
     * the controller, which keeps it ahead of the 404 that nested routes
     * return for resources belonging to another workspace.
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
            'name' => [
                'required',
                'string',
                'max:255',
            ],
        ];
    }
}
