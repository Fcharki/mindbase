<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores a vector as the bracketed string Postgres expects.
 *
 * Postgres has no array parameter type that matches pgvector, so an array bound
 * to a vector column fails with a conversion error that names neither the column
 * nor the model. A bulk insert bypasses model casts entirely, so the indexing job
 * formats this column with the same helper; anything that does go through the
 * model is covered here.
 *
 * @implements CastsAttributes<list<float>, list<float>>
 */
class AsVector implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return list<float>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (is_array($value)) {
            return array_map('floatval', $value);
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode(self::normalize($value), true);

        return is_array($decoded) ? array_map('floatval', $decoded) : [];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return self::encode(is_array($value) ? $value : []);
    }

    /**
     * Format a vector for a query binding.
     *
     * Public because the bulk insert path cannot use a cast and must produce
     * exactly the same string.
     *
     * @param  list<float>  $vector
     */
    public static function encode(array $vector): string
    {
        return '['.implode(',', array_map(
            static fn ($component) => self::format((float) $component),
            $vector,
        )).']';
    }

    /**
     * Postgres prints floats without an exponent and without a trailing .0, and
     * a value like 1e-05 is rejected, so the formatting has to be explicit.
     */
    private static function format(float $value): string
    {
        if (is_nan($value) || is_infinite($value)) {
            throw new \InvalidArgumentException('A vector component is not a finite number.');
        }

        return rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.');
    }

    /**
     * Postgres wraps a vector in braces: {1,2,3}. json_decode wants brackets.
     */
    private static function normalize(string $value): string
    {
        $value = trim($value);

        if (str_starts_with($value, '{') && str_ends_with($value, '}')) {
            return '['.substr($value, 1, -1).']';
        }

        return $value;
    }
}
