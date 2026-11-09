<?php

declare(strict_types=1);

namespace Modules\Feedback\Contracts;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Modules\Feedback\Contracts\Data\EmployeeFeedbackQrLink;

/**
 * An employee's public feedback QR (ADR 0017), for the employee profile.
 */
interface EmployeeFeedbackQr
{
    /**
     * The employee's active feedback link, issuing one only when an active
     * employee has none and no administrator revoked or suspended theirs
     * (create-if-absent, never a regenerate; audited as the actor). Null
     * when no QR should exist.
     */
    public function ensureActiveFor(Employee $employee, ?User $actor = null, ?Request $request = null): ?EmployeeFeedbackQrLink;
}
