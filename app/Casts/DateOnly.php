<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A calendar date stored as exactly "Y-m-d".
 *
 * Laravel's `date` cast writes "Y-m-d H:i:s"; on a text-typed column (SQLite)
 * that breaks equality keys (updateOrCreate on a period) and inclusive
 * range filters (period_end <= '2026-12-31'). Performance periods are pure
 * dates, so they are stored and compared as dates everywhere.
 *
 * @implements CastsAttributes<Carbon, mixed>
 */
final class DateOnly implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null || $value === '' ? null : Carbon::parse(substr((string) $value, 0, 10))->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ($value instanceof \DateTimeInterface ? Carbon::instance($value) : Carbon::parse((string) $value))->toDateString();
    }
}
