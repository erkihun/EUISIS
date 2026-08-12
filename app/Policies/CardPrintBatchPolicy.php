<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CardPrintBatch;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;

readonly class CardPrintBatchPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private OrganizationScopeService $organizationScopeService) {}

    public function viewAny(User $user): bool
    {
        return $user->can('id-cards.createPrintBatch') || $user->can('cards.manage');
    }

    public function view(User $user, CardPrintBatch $batch): bool
    {
        return ($user->can('id-cards.createPrintBatch') || $user->can('cards.manage'))
            && $this->allCardsWithinScope($user, $batch);
    }

    public function create(User $user): bool
    {
        return $user->can('id-cards.createPrintBatch') || $user->can('cards.manage');
    }

    public function markPrinted(User $user, CardPrintBatch $batch): bool
    {
        return ($user->can('id-cards.print') || $user->can('cards.manage'))
            && $this->allCardsWithinScope($user, $batch);
    }

    /**
     * A batch commonly spans multiple employees (and can span multiple
     * organizations for an unrestricted actor). A scoped actor — e.g. an
     * Organizational Admin — may only view/print a batch where every card
     * belongs to an employee inside their own accessible organizations.
     */
    private function allCardsWithinScope(User $user, CardPrintBatch $batch): bool
    {
        if ($this->organizationScopeService->isUnrestricted($user)) {
            return true;
        }

        return $batch->items()
            ->with('card.employee.currentAssignment')
            ->get()
            ->every(function ($item) use ($user): bool {
                $employee = $item->card?->employee;

                return $employee !== null && $this->organizationScopeService->canAccessEmployee($user, $employee);
            });
    }
}
