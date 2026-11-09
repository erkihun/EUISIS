<?php

declare(strict_types=1);

namespace App\Support\Database;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/** The query builder of a ReadOnlyModel: reads only; every write method throws. */
class ReadOnlyBuilder extends Builder
{
    private const WRITES = ['insert', 'insertOrIgnore', 'insertGetId', 'insertUsing', 'insertOrIgnoreUsing', 'updateOrInsert', 'truncate'];

    public function create(array $attributes = [])
    {
        throw ReadOnlyModelException::for($this->model::class, 'create');
    }

    public function createQuietly(array $attributes = [])
    {
        throw ReadOnlyModelException::for($this->model::class, 'createQuietly');
    }

    public function forceCreate(array $attributes)
    {
        throw ReadOnlyModelException::for($this->model::class, 'forceCreate');
    }

    public function forceCreateQuietly(array $attributes = [])
    {
        throw ReadOnlyModelException::for($this->model::class, 'forceCreateQuietly');
    }

    public function firstOrCreate(array $attributes = [], Closure|array $values = [])
    {
        throw ReadOnlyModelException::for($this->model::class, 'firstOrCreate');
    }

    public function createOrFirst(array $attributes = [], Closure|array $values = [])
    {
        throw ReadOnlyModelException::for($this->model::class, 'createOrFirst');
    }

    public function updateOrCreate(array $attributes, Closure|array $values = [])
    {
        throw ReadOnlyModelException::for($this->model::class, 'updateOrCreate');
    }

    public function incrementOrCreate(array $attributes, string $column = 'count', $default = 1, $step = 1, array $extra = [])
    {
        throw ReadOnlyModelException::for($this->model::class, 'incrementOrCreate');
    }

    public function update(array $values)
    {
        throw ReadOnlyModelException::for($this->model::class, 'update');
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        throw ReadOnlyModelException::for($this->model::class, 'upsert');
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        throw ReadOnlyModelException::for($this->model::class, 'increment');
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        throw ReadOnlyModelException::for($this->model::class, 'decrement');
    }

    public function delete()
    {
        throw ReadOnlyModelException::for($this->model::class, 'delete');
    }

    public function forceDelete()
    {
        throw ReadOnlyModelException::for($this->model::class, 'forceDelete');
    }

    public function touch($column = null)
    {
        throw ReadOnlyModelException::for($this->model::class, 'touch');
    }

    public function __call($method, $parameters)
    {
        if (in_array($method, self::WRITES, true)) {
            throw ReadOnlyModelException::for($this->model::class, $method);
        }

        return parent::__call($method, $parameters);
    }
}
