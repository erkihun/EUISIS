<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Models\ExternalApplication;
use Illuminate\Support\Collection;

/**
 * Access review for registered partner applications (master backbone
 * remediation). Each application gets zero or more findings an administrator
 * should act on; nothing here changes access by itself.
 *
 *   UNREVIEWED          legacy application whose organization bound nobody
 *                       has decided — it currently reads no organization data
 *   UNSCOPED            scoped, holds organization-bound scopes, but has no
 *                       organization grants — it reads nothing
 *   OVERPRIVILEGED      approved city-wide while holding employee or photo
 *                       scopes, or holding a `*` token
 *   LEGACY_CREDENTIAL   a token carrying abilities the application is no
 *                       longer allowed (or `provider:access` / `*`)
 *   PROVIDER_UNBOUND    holds the provider transaction scope but is bound to
 *                       no service provider, so every provider call is refused
 */
class PartnerAccessReviewService
{
    public const ORGANIZATION_BOUND_SCOPES = [
        'employees.basic_read', 'employee_assignments.read', 'employees.photo_read', 'organizations.read',
        'organization_units.read', 'positions.read', 'organization_structure.read', 'id_cards.verify',
        'service_eligibility.check', 'reports.read_limited',
    ];

    public const PERSONAL_DATA_SCOPES = ['employees.basic_read', 'employee_assignments.read', 'employees.photo_read'];

    /** @return list<string> */
    public function findings(ExternalApplication $application): array
    {
        $scopes = $application->allowed_scopes ?? [];
        $tokens = $application->relationLoaded('tokens') ? $application->tokens : $application->tokens()->get(['id', 'abilities']);
        $grantCount = $application->organization_scopes_count ?? $application->organizationScopes()->count();
        $findings = [];

        if ($application->organization_access === ExternalApplication::ACCESS_UNREVIEWED) {
            $findings[] = 'UNREVIEWED';
        }

        if ($application->organization_access === ExternalApplication::ACCESS_SCOPED
            && $grantCount === 0
            && array_intersect($scopes, self::ORGANIZATION_BOUND_SCOPES) !== []) {
            $findings[] = 'UNSCOPED';
        }

        $wildcard = $tokens->contains(fn ($token): bool => in_array('*', (array) $token->abilities, true));

        if ($wildcard || ($application->organization_access === ExternalApplication::ACCESS_CITY && array_intersect($scopes, self::PERSONAL_DATA_SCOPES) !== [])) {
            $findings[] = 'OVERPRIVILEGED';
        }

        $legacy = $tokens->contains(fn ($token): bool => collect((array) $token->abilities)
            ->contains(fn ($ability): bool => in_array($ability, ['*', 'provider:access'], true) || ! in_array($ability, $scopes, true)));

        if ($legacy) {
            $findings[] = 'LEGACY_CREDENTIAL';
        }

        if (in_array('service_transactions.create', $scopes, true) && $application->service_provider_id === null) {
            $findings[] = 'PROVIDER_UNBOUND';
        }

        return $findings;
    }

    /** @return Collection<int, array{code: string, name: string, status: string, organization_access: string, grants: int, findings: list<string>}> */
    public function report(): Collection
    {
        return ExternalApplication::query()
            ->withCount('organizationScopes')
            ->with('tokens:id,tokenable_id,tokenable_type,abilities')
            ->orderBy('code')
            ->get()
            ->map(fn (ExternalApplication $application): array => [
                'code' => $application->code,
                'name' => $application->name,
                'status' => $application->status,
                'organization_access' => $application->organization_access,
                'grants' => (int) $application->organization_scopes_count,
                'findings' => $this->findings($application),
            ]);
    }
}
