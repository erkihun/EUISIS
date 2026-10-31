<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Policies;

use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;
use Modules\Feedback\Contracts\ReadModels\FeedbackReportExportRecord;
use Modules\Feedback\Domain\Models\PublicFeedbackReportExport;

/**
 * Who may see the submissions report, request an export, and read one back
 * (brief §86–87). A download goes to the requester, or to an exporter whose
 * scope covers the export's institution — or everything, for a city-wide one.
 */
class PublicFeedbackReportExportPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private readonly OrganizationScopeService $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(['service_feedback.view']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyPermission(['service_feedback.view']) && $user->hasAnyPermission(['service_feedback.export']);
    }

    public function download(User $user, PublicFeedbackReportExport|FeedbackReportExportRecord $export): bool
    {
        // A Situation Room export was computed with its requester's grants: only they read it back.
        if (($export->report ?? 'submissions') !== 'submissions') {
            return (int) $export->requested_by === (int) $user->getKey() && $user->hasAnyPermission(['situation_room.export']);
        }
        if (! $user->hasAnyPermission(['service_feedback.export'])) {
            return false;
        }
        if ((int) $export->requested_by === (int) $user->getKey()) {
            return true;
        }

        return $export->organization_id === null
            ? $this->scope->isUnrestricted($user)
            : $this->scope->canAccessOrganization($user, $export->organization_id);
    }
}
