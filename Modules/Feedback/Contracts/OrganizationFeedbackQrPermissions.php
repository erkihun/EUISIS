<?php

declare(strict_types=1);

namespace Modules\Feedback\Contracts;

use App\Models\Organization;
use App\Models\User;

/** Whether a user may use an institution's feedback QR (ADR 0017), for the organization profile. */
interface OrganizationFeedbackQrPermissions
{
    /** $ability: view, create, export, disable or rotate; the permission and the user's organization scope both apply. */
    public function can(User $user, string $ability, Organization $organization): bool;
}
