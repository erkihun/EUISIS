<?php

declare(strict_types=1);

namespace App\Backbone\Events;

/**
 * An employee assignment was created, changed or deleted.
 *
 * Dispatched synchronously from the backbone while the change is still inside
 * its transaction; listeners that start work elsewhere must wait for the commit.
 * Service modules listen to this instead of the backbone calling them.
 */
final readonly class AssignmentChanged
{
    /** @param  list<string>  $changedAttributes  attributes changed by an update (empty on create and delete) */
    public function __construct(
        public string $employeeId,
        public string $assignmentId,
        public bool $created,
        public bool $deleted,
        public array $changedAttributes,
    ) {}

    /** @param  list<string>  $attributes */
    public function changedAny(array $attributes): bool
    {
        return array_intersect($attributes, $this->changedAttributes) !== [];
    }
}
