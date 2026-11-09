<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Application\Policies;

use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use Modules\Cafeteria\Domain\Models\CafeteriaSubsidyLedger;

readonly class CafeteriaSubsidyLedgerPolicy
{
    use DeniesNonAdminUsers;

    public function viewAny(User $user): bool
    {
        return $user->can('cafeteria_ledger.view');
    }

    public function view(User $user, CafeteriaSubsidyLedger $ledger): bool
    {
        return $user->can('cafeteria_ledger.view');
    }
}
