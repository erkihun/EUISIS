<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Services;

use App\Backbone\Contracts\EmployeeFeedbackLinks;
use App\Models\Employee;
use App\Models\User;

/** The service-feedback module's side of the backbone's EmployeeFeedbackLinks contract. */
final class EmployeeFeedbackLinkAdapter implements EmployeeFeedbackLinks
{
    public function __construct(private readonly EmployeeFeedbackTokenService $tokens) {}

    public function provision(Employee $employee, ?User $actor = null): void
    {
        $this->tokens->ensureActiveTokenFor($employee, $actor);
    }

    public function publicUrlFor(Employee $employee): ?string
    {
        return $this->tokens->activeToken($employee)?->publicUrl();
    }
}
