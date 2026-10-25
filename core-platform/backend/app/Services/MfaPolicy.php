<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

/**
 * Who must use multi-factor authentication (ADR 0008), by category, from Core
 * settings › Security; all privileged categories are on by default:
 *  - city-wide administrators (settings, hierarchy approval, Integration
 *    Center, account and security administration);
 *  - organization administrators (accounts in their organizations, employee
 *    approval);
 *  - officers (employee verification, card approval, printing, issuing and
 *    activation);
 *  - viewers (read only; off by default).
 * An account that enrolled voluntarily is challenged too. `core.mfa_enforce`
 * switches enforcement off for the test suite only; production readiness and
 * system health report it.
 */
final readonly class MfaPolicy
{
    public function __construct(private CoreSettings $settings) {}

    public static function enforced(): bool
    {
        return (bool) config('core.mfa_enforce', true);
    }

    public function requires(User $user): bool
    {
        $category = match (true) {
            $user->role === 'core_admin' && $user->isCityWide() => 'security.mfa_city_administrators',
            $user->role === 'core_admin' => 'security.mfa_organization_administrators',
            $user->role === 'core_officer' => 'security.mfa_officers',
            default => 'security.mfa_viewers',
        };

        return (bool) $this->settings->get($category);
    }

    /** Whether the account must pass the second factor before it may work. */
    public function applies(User $user): bool
    {
        return self::enforced() && ($this->requires($user) || $user->hasMfaEnabled());
    }
}
