<?php

declare(strict_types=1);

namespace App\Backbone\Contracts;

use App\Backbone\Data\EmployeeIdentity;
use App\Models\User;

/** Looks up employee identity from the backbone. Read-only; service modules never write employees. */
interface EmployeeDirectory
{
    public function findByUuid(string $employeeUuid): ?EmployeeIdentity;

    public function findByNumber(string $employeeNumber): ?EmployeeIdentity;

    /** The employee, or null when unknown or outside the viewer's organization scope. */
    public function findFor(User $viewer, string $employeeUuid): ?EmployeeIdentity;
}
