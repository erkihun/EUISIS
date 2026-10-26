<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CardRequest;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationScope;

/** Card requests (ADR 0009): verification and approval by someone other than the requester, while pending. */
final class CardRequestPolicy
{
    use ChecksOrganizationScope;

    public function view(User $user, CardRequest $request): bool
    {
        return $this->permits($user, 'id-cards.viewAny', $this->employeeOrganization((string) $request->employee_id));
    }

    public function verify(User $user, CardRequest $request): bool
    {
        return $this->permits($user, 'card-requests.verify', $this->employeeOrganization((string) $request->employee_id),
            in_array($request->status, ['draft', CardRequest::SUBMITTED], true) && (int) $request->requested_by !== (int) $user->getKey());
    }

    public function approve(User $user, CardRequest $request): bool
    {
        return $this->permits($user, 'card-requests.approve', $this->employeeOrganization((string) $request->employee_id),
            in_array($request->status, CardRequest::PENDING, true) && (int) $request->requested_by !== (int) $user->getKey());
    }

    public function reject(User $user, CardRequest $request): bool
    {
        return $this->permits($user, 'card-requests.approve', $this->employeeOrganization((string) $request->employee_id),
            in_array($request->status, CardRequest::PENDING, true));
    }

    public function cancel(User $user, CardRequest $request): bool
    {
        return $this->permits($user, 'card-requests.create', $this->employeeOrganization((string) $request->employee_id),
            in_array($request->status, CardRequest::PENDING, true));
    }
}
