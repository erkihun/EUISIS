<?php

declare(strict_types=1);

namespace App\Support\Rbac;

/**
 * A record an employee applied with (a transfer or vacancy application). The
 * Gate's role bypass never lets the applicant decide their own record, Super
 * Admin or not: the record's policy refuses them every workflow decision.
 * Modules implement it on their models, so the Gate names no module class.
 */
interface ApplicantOwnedRecord
{
    /** The employee who applied. */
    public function applicantEmployeeId(): ?string;
}
