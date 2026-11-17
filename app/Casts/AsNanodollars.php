<?php

declare(strict_types=1);

namespace App\Casts;

use App\Money\Nanodollars;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Maps a bigint nanodollar column to Nanodollars and back.
 *
 * @implements CastsAttributes<Nanodollars|null, Nanodollars|null>
 */
class AsNanodollars implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Nanodollars
    {
        return $value === null ? null : Nanodollars::of((int) $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return $value instanceof Nanodollars ? $value->amount : null;
    }
}
