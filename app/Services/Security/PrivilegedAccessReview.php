<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Support\Carbon;

/**
 * Periodic review of privileged accounts (docs/governance/security-governance.md):
 * every account holding a city-wide role or a role that must use MFA, with
 * the findings an administrator should act on. Nothing is changed here.
 *
 *   NEVER_SIGNED_IN           active, but has never signed in
 *   STALE                     active, last sign-in older than the review window
 *   MFA_MISSING               must use MFA but has not finished enrolment
 *   INACTIVE_HOLDS_PRIVILEGE  disabled account still holding the role
 *
 * Ids, names, roles and dates only; never e-mail, phone or national ID.
 */
final readonly class PrivilegedAccessReview
{
    public function __construct(private OrganizationScopeService $scope) {}

    /** @return list<string> */
    public function privilegedRoles(): array
    {
        return array_values(array_unique([
            ...OrganizationScopeService::UNRESTRICTED_ROLES,
            ...array_filter((array) config('security.mfa_required_roles', []), 'is_string'),
        ]));
    }

    /** @return list<array{id: int|string, name: string, status: string, roles: list<string>, last_sign_in: string|null, mfa: string, organization_scope: string, findings: list<string>}> */
    public function rows(int $staleDays = 90): array
    {
        $privileged = $this->privilegedRoles();
        $cutoff = Carbon::now()->subDays($staleDays);
        $rows = [];

        User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $privileged))
            ->with(['roles:id,name,scope_type'])
            ->withCount('organizationScopes')
            ->orderBy('name')
            ->get()
            ->each(function (User $user) use ($privileged, $cutoff, &$rows): void {
                $active = $user->isActive();
                $mfaRequired = $user->requiresMfa();
                $findings = [];

                if (! $active) {
                    $findings[] = 'INACTIVE_HOLDS_PRIVILEGE';
                } elseif ($user->last_login_at === null) {
                    $findings[] = 'NEVER_SIGNED_IN';
                } elseif ($user->last_login_at->lt($cutoff)) {
                    $findings[] = 'STALE';
                }
                if ($active && $mfaRequired && ! $user->hasMfaEnabled()) {
                    $findings[] = 'MFA_MISSING';
                }

                $rows[] = [
                    'id' => $user->id,
                    'name' => (string) $user->name,
                    'status' => (string) $user->status,
                    'roles' => $user->roles->pluck('name')->intersect($privileged)->sort()->values()->all(),
                    'last_sign_in' => $user->last_login_at?->toDateString(),
                    'mfa' => match (true) {
                        $user->hasMfaEnabled() => 'enrolled',
                        $mfaRequired => 'missing',
                        default => 'not_required',
                    },
                    'organization_scope' => $this->scope->hasUnrestrictedRole($user)
                        ? 'city-wide'
                        : $user->organization_scopes_count.' organization grant(s)',
                    'findings' => $findings,
                ];
            });

        return $rows;
    }
}
