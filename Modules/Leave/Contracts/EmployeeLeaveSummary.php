<?php

declare(strict_types=1);

namespace Modules\Leave\Contracts;

/**
 * An employee's leave at a glance, for pages outside the module (My Portal).
 */
interface EmployeeLeaveSummary
{
    /**
     * @return array{
     *     budget_year: int,
     *     balances: list<array{code: string, name_en: string, name_am: string, available: float, pending: float}>,
     *     pending_requests: int,
     *     next_leave: array{starts_on: string, ends_on: string, name_en: string, name_am: string}|null,
     * }|null null when the employee is unknown
     */
    public function forEmployee(string $employeeId, string $today): ?array;
}
