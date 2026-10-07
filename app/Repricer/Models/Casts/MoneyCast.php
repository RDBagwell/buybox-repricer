<?php

namespace App\Repricer\Models\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Integer-cents column <-> Money. Refuses floats outright.
 *
 * @implements CastsAttributes<Money|null, Money|int|null>
 */
final class MoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        return $value === null ? null : Money::cents((int) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return match (true) {
            $value === null => null,
            $value instanceof Money => $value->cents,
            is_int($value) => $value,
            default => throw new InvalidArgumentException("{$key} must be Money or integer cents, got ".get_debug_type($value).'.'),
        };
    }
}
