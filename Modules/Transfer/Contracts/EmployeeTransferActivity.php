<?php

declare(strict_types=1);

namespace Modules\Transfer\Contracts;

/** An employee's transfer applications and the open announcements, for My Portal's dashboard card (ADR 0017). */
interface EmployeeTransferActivity
{
    /**
     * The employee's five latest applications, and up to three open announcements
     * they have no live application against.
     *
     * @return array{applications: list<array<string, mixed>>, open_announcements: list<array<string, mixed>>}
     */
    public function forEmployee(string $employeeId): array;
}
