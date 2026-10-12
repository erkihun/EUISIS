<?php

declare(strict_types=1);

namespace App\Services\ServiceFeedback;

use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Who may manage an organization's feedback QR: the permission for the
 * action AND the organization inside the user's scope (brief §96). The
 * permission alone would let a scoped administrator mint or revoke codes for
 * another institution by guessing a route parameter.
 */
class OrganizationFeedbackQrAccess
{
    public const ABILITIES = ['view', 'create', 'export', 'disable', 'rotate'];

    public function __construct(private readonly OrganizationScopeService $scope) {}

    public function can(User $user, string $ability, Organization $organization): bool
    {
        if (! in_array($ability, self::ABILITIES, true)) {
            return false;
        }

        return $user->hasAnyPermission(['service_feedback.organization_qr.'.$ability])
            && $this->scope->canAccessOrganization($user, (string) $organization->getKey());
    }

    public function authorize(User $user, string $ability, Organization $organization): void
    {
        if (! $this->can($user, $ability, $organization)) {
            throw new AccessDeniedHttpException('You may not manage this organization\'s feedback QR.');
        }
    }

    /** @return array<string, bool> */
    public function abilities(User $user, Organization $organization): array
    {
        $abilities = [];

        foreach (self::ABILITIES as $ability) {
            $abilities[$ability] = $this->can($user, $ability, $organization);
        }

        return $abilities;
    }
}
