<?php

declare(strict_types=1);

namespace App\Backbone\Contracts;

use App\Backbone\Data\AssignmentContext;

/** Reads an employee's employment context. Assignments change only through backbone commands. */
interface AssignmentContextReader
{
    public function currentFor(string $employeeUuid): ?AssignmentContext;

    public function find(string $assignmentUuid): ?AssignmentContext;
}
