<?php

declare(strict_types=1);

namespace Modules\Court\Application\Policies;

use Modules\Court\Domain\Models\CourtStaffMember as User;
use Modules\Court\Domain\Models\CourtCase;

final class CourtCasePolicy
{
    public function view(User $user, CourtCase $case): bool
    {
        // Registrar can preview any case before acceptance
        if ($this->hasPerm($user, 'cases.review')) return true;

        // Assigned officer can view even if not yet accepted
        if (!empty($case->assigned_user_id) && (int)$case->assigned_user_id === (int)$user->id) {
            return true;
        }

        // Everyone else only after acceptance
        return ($case->review_status ?? 'accepted') === 'accepted';
    }

    public function review(User $user, ?CourtCase $case = null): bool
    {
        return $this->hasPerm($user, 'cases.review');
    }

    private function hasPerm(User $user, string $perm): bool
    {
        if (method_exists($user, 'hasPermission')) {
            try {
                return (bool) $user->hasPermission($perm);
            } catch (\Throwable $e) {
            }
        }
        if (method_exists($user, 'can')) {
            try {
                return (bool) $user->can($perm);
            } catch (\Throwable $e) {
            }
        }
        if (function_exists('userHasPermission')) {
            try {
                return (bool) \Modules\Court\Application\Support\CourtPermissions::check($perm);
            } catch (\Throwable $e) {
            }
        }
        return false;
    }
}
