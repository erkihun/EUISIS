<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CodeRule;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;

class CodeRulePolicy
{
    use DeniesNonAdminUsers;

    public function viewAny(User $user): bool
    {
        return $user->can('code-rules.viewAny');
    }

    public function view(User $user, CodeRule $codeRule): bool
    {
        return $user->can('code-rules.view');
    }

    public function create(User $user): bool
    {
        return $user->can('code-rules.create');
    }

    public function update(User $user, CodeRule $codeRule): bool
    {
        return $user->can('code-rules.update');
    }

    public function archive(User $user, CodeRule $codeRule): bool
    {
        return $user->can('code-rules.delete') || $user->can('code-rules.archive');
    }

    public function restore(User $user, CodeRule $codeRule): bool
    {
        return $user->can('code-rules.restore');
    }

    public function viewDeleted(User $user): bool
    {
        return $user->can('code-rules.viewDeleted');
    }

    public function preview(User $user): bool
    {
        return $user->can('code-rules.preview');
    }

    public function generate(User $user): bool
    {
        return $user->can('code-rules.generate');
    }
}
