<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Policies;

use App\Models\User;
use Modules\Letters\Application\Services\LettersAccess;
use Modules\Letters\Domain\Models\LetterNumberingRule;

/** Numbering rules: an institution's administrator for its own; the platform default needs city-wide reach. */
final class LetterNumberingRulePolicy
{
    public function __construct(private readonly LettersAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $user->can('letters.numbering.manage');
    }

    public function manage(User $user, LetterNumberingRule $rule): bool
    {
        return $this->access->canManageNumbering($user, $rule->organization_id);
    }
}
