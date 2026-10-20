<?php

namespace App\Http\Requests\Profile;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeleteProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Deleting an account is irreversible and cascades to every workspace the
     * user owns, so it needs more than a session. The typed email proves
     * intent; the password proves identity, but only for accounts that have
     * one — a Google-only account has no password the user could supply.
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
                $this->matchesAccountEmail(),
            ],

            'password' => [
                Rule::requiredIf(fn () => $this->user()?->google_id === null),
                'nullable',
                'string',
                'current_password',
            ],
        ];
    }

    /**
     * @return Closure(string, mixed, Closure(string): void): void
     */
    private function matchesAccountEmail(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (strcasecmp((string) $value, (string) $this->user()?->email) !== 0) {
                $fail('The email does not match the account being deleted.');
            }
        };
    }
}
