<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\Eloquent\Model;

/**
 * A model a module publishes so other modules can read its records (ADR 0017):
 * relations, eager loads and attributes work as usual, and every write is
 * refused, through the model and through its query builder. Only the owning
 * module writes, with its own models and commands.
 */
abstract class ReadOnlyModel extends Model
{
    public function save(array $options = []): bool
    {
        throw ReadOnlyModelException::for(static::class, 'save');
    }

    public function newEloquentBuilder($query): ReadOnlyBuilder
    {
        return new ReadOnlyBuilder($query);
    }
}
