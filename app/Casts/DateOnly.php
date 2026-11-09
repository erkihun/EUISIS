<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
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
 * It also serializes as "Y-m-d". Without that a model sent to a page as an
 * array carried the Carbon value as UTC midnight of the day before
 * (Africa/Addis_Ababa is UTC+3), so a cycle starting on Hamle 1 showed as Sene 30.
 *
 * @implements CastsAttributes<Carbon, mixed>
 */
final class DateOnly implements CastsAttributes, SerializesCastableAttributes
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

    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        // Eloquent hands this method the date already turned into a UTC string, so read the stored value.
        $stored = $attributes[$key] ?? null;
        if ($stored === null || $stored === '') {
            return null;
        }

        return $stored instanceof \DateTimeInterface ? $stored->format('Y-m-d') : substr((string) $stored, 0, 10);
    }
}
