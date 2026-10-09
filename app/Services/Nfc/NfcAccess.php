<?php

namespace App\Services\Nfc;

use App\Models\IdCard;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Support\Collection;

final class NfcAccess
{
    /**
     * Gate a single card's credential actions.
     *
     * A card is required: outside an organizational context there is nothing to
     * scope against, so anyone but a global admin is refused.
     */
    public function allows(User $user, string $permission, ?IdCard $card = null): bool
    {
        if (! $user->isActive()) {
            return false;
        }
        if ($user->hasRole(['Super Admin', 'System Admin'])) {
            return true;
        }
        if (! $user->can($permission)) {
            return false;
        }

        // Organization admins can manage explicitly permitted cards, but cannot
        // grant application/terminal access across organizational boundaries.
        return $card !== null && app(OrganizationScopeService::class)->canAccessEmployee($user, $card->employee);
    }

    /**
     * Gate a listing or dashboard, where scoping is applied to the query rather
     * than to one record.
     *
     * Holding the permission is enough to open the page; `organizationScope()`
     * then narrows what it contains. Without this split an organizational admin
     * holding `nfc_logs.view` could never open the log page at all, because
     * `allows()` has no card to scope against.
     */
    public function allowsListing(User $user, string $permission): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        return $user->hasRole(['Super Admin', 'System Admin']) || $user->can($permission);
    }

    /**
     * Organization ids this user may see, or an empty collection for
     * unrestricted users. Callers MUST treat empty as "no filter".
     */
    public function organizationScope(User $user): Collection
    {
        return app(OrganizationScopeService::class)->accessibleOrganizationIds($user);
    }

    public function cardPayload(User $user, IdCard $card): array
    {
        $can = [];
        foreach (['view', 'provision', 'activate', 'suspend', 'revoke', 'replace'] as $action) {
            $can[$action] = $this->allows($user, 'nfc_credentials.'.$action, $card);
        }

        return ['can' => $can, 'credentials' => $can['view'] ? $card->nfcCredentials()->latest()->limit(25)->get()
            ->map(fn ($c) => $c->only(['id', 'credential_id', 'status', 'credential_type', 'issued_at', 'activated_at', 'expires_at', 'last_used_at', 'key_version'])) : []];
    }
}
