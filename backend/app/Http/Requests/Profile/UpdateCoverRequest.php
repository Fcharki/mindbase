<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCoverRequest extends FormRequest
{
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
            'cover' => [
                'required',
                'image',
                'mimes:jpeg,jpg,png,webp',
                'max:4096',
            ],
        ];
    }
}
