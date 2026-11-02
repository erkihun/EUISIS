<?php

declare(strict_types=1);

namespace App\Backbone\Contracts;

use App\Backbone\Data\SupervisorResolution;

/**
 * Who supervises whom (ADR 0025): the organizational reporting line derived
 * from unit leadership, published position profiles and acting appointments.
 * It names people and positions only; whether the supervisor may ACT on a
 * record is a separate question each workflow answers with its permission,
 * organization scope and delegation rules.
 */
interface ReportingLines
{
    /** The direct supervisor on the date (today when omitted), or why there is none. */
    public function directSupervisor(string $employeeUuid, ?string $asOf = null): SupervisorResolution;

    /**
     * The supervisor, their supervisor and so on, ending at the top, at a
     * problem, or at the depth limit. Never skips an unresolved level.
     *
     * @return list<SupervisorResolution>
     */
    public function chain(string $employeeUuid, ?string $asOf = null): array;

    /**
     * Employees whose direct supervisor is the given employee on the date,
     * through every position the employee holds (substantively or acting).
     *
     * @return list<string> employee ids
     */
    public function directReportIds(string $employeeUuid, ?string $asOf = null): array;

    /** Whether the first employee supervises the second on the date, directly or (unless $directOnly) at any depth. */
    public function supervises(string $supervisorUuid, string $employeeUuid, ?string $asOf = null, bool $directOnly = false): bool;
}
