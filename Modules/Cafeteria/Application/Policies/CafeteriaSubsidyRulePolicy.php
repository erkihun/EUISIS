<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Application\Policies;

use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use Modules\Cafeteria\Domain\Models\CafeteriaSubsidyRule;

readonly class CafeteriaSubsidyRulePolicy
{
    use DeniesNonAdminUsers;

    public function viewAny(User $user): bool
    {
        return $user->can('cafeteria_subsidy_rules.view');
    }

    public function view(User $user, CafeteriaSubsidyRule $rule): bool
    {
        return $user->can('cafeteria_subsidy_rules.view');
    }

    public function create(User $user): bool
    {
        return $user->can('cafeteria_subsidy_rules.create');
    }

    public function update(User $user, CafeteriaSubsidyRule $rule): bool
    {
        return $user->can('cafeteria_subsidy_rules.update');
    }

    public function archive(User $user, CafeteriaSubsidyRule $rule): bool
    {
        return $user->can('cafeteria_subsidy_rules.archive');
    }
}
