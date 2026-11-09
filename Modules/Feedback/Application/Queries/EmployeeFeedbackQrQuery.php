<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Queries;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Modules\Feedback\Application\Services\EmployeeFeedbackTokenService;
use Modules\Feedback\Contracts\Data\EmployeeFeedbackQrLink;
use Modules\Feedback\Contracts\EmployeeFeedbackQr;

final class EmployeeFeedbackQrQuery implements EmployeeFeedbackQr
{
    public function __construct(private readonly EmployeeFeedbackTokenService $tokens) {}

    public function ensureActiveFor(Employee $employee, ?User $actor = null, ?Request $request = null): ?EmployeeFeedbackQrLink
    {
        $token = $this->tokens->ensureActiveTokenFor($employee, $actor, $request);

        return $token === null ? null : new EmployeeFeedbackQrLink($token->publicUrl(), $token->status->value);
    }
}
