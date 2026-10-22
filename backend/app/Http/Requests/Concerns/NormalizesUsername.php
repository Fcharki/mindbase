<?php

namespace App\Http\Requests\Concerns;

trait NormalizesUsername
{
    protected function normalizeUsername(): void
    {
        if (! is_string($this->username)) {
            return;
        }

        $this->merge([
            'username' => ltrim(strtolower(trim($this->username)), '@'),
        ]);
    }
}
