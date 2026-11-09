<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Services;

use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Modules\Feedback\Contracts\OrganizationFeedbackQrPermissions;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Who may manage an organization's feedback QR: the permission for the
 * action AND the organization inside the user's scope (brief §96). The
 * permission alone would let a scoped administrator mint or revoke codes for
 * another institution by guessing a route parameter.
 */
class OrganizationFeedbackQrAccess implements OrganizationFeedbackQrPermissions
{
    /** Ability => permission, spelled out so the permission audit sees every name. */
    public const PERMISSIONS = [
        'view' => 'service_feedback.organization_qr.view',
        'create' => 'service_feedback.organization_qr.create',
        'export' => 'service_feedback.organization_qr.export',
        'disable' => 'service_feedback.organization_qr.disable',
        'rotate' => 'service_feedback.organization_qr.rotate',
    ];

    public const ABILITIES = ['view', 'create', 'export', 'disable', 'rotate'];

    public function __construct(private readonly OrganizationScopeService $scope) {}

    public function can(User $user, string $ability, Organization $organization): bool
    {
        $permission = self::PERMISSIONS[$ability] ?? null;

        if ($permission === null) {
            return false;
        }

        return $user->hasAnyPermission([$permission])
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
