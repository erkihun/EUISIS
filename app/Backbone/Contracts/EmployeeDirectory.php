<?php

declare(strict_types=1);

namespace App\Backbone\Contracts;

use App\Backbone\Data\EmployeeIdentity;

/** Looks up employee identity from the backbone. Read-only; service modules never write employees. */
interface EmployeeDirectory
{
    public function findByUuid(string $employeeUuid): ?EmployeeIdentity;

    public function findByNumber(string $employeeNumber): ?EmployeeIdentity;
}
