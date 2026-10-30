<?php

declare(strict_types=1);

namespace Modules\Transport\Contracts;

use Carbon\CarbonInterface;
use Modules\Transport\Contracts\Data\EmployeeTransportSummary;

/**
 * One employee's own transport, for self-service pages (My Portal): the
 * passes and stops assigned, today's trips on the current route and the
 * boardings recorded against the card. Nothing financial and nothing
 * security-relevant (no QR hashes, nonces or device metadata).
 */
interface EmployeeTransportActivity
{
    public function forEmployee(string $employeeId, CarbonInterface $today): EmployeeTransportSummary;
}
