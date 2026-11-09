<?php

declare(strict_types=1);

namespace App\Backbone\Events;

/**
 * An employee record was saved. Dispatched synchronously inside the saving
 * transaction; see AssignmentChanged.
 */
final readonly class EmployeeChanged
{
    /** @param  list<string>  $changedAttributes  attributes changed by an update (empty on create) */
    public function __construct(
        public string $employeeId,
        public bool $created,
        public array $changedAttributes,
    ) {}

    /** @param  list<string>  $attributes */
    public function changedAny(array $attributes): bool
    {
        return array_intersect($attributes, $this->changedAttributes) !== [];
    }
}
