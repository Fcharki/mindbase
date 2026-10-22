<?php

namespace App\Http\Requests\Profile;

use App\Http\Requests\Concerns\NormalizesUsername;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    use NormalizesUsername;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeUsername();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'username' => [
                'required',
                'string',
                'min:3',
                'max:30',
                'regex:/^[a-z0-9][a-z0-9_-]*[a-z0-9]$/',
                Rule::unique('users', 'username')->ignore($this->user()?->id),
            ],

            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->user()?->id),
            ],

            'bio' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'location' => [
                'nullable',
                'string',
                'max:120',
            ],
        ];
    }
}
