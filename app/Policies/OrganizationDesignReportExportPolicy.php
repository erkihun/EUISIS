<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OrganizationDesignReportExport;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\OrganizationScope\OrganizationScopeService;

/** Reports (organization-design-reports.view) and their exports (organization-design-reports.export). */
readonly class OrganizationDesignReportExportPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private OrganizationScopeService $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('organization-design-reports.view');
    }

    public function create(User $user): bool
    {
        return $user->can('organization-design-reports.export');
    }

    /** Download: the requester, or an exporter whose scope covers the export's organization (or everything, for a scope-wide export). */
    public function download(User $user, OrganizationDesignReportExport $export): bool
    {
        if (! $user->can('organization-design-reports.export')) {
            return false;
        }
        if ((int) $export->requested_by === (int) $user->getKey()) {
            return true;
        }

        return $export->organization_id === null ? $this->scope->isUnrestricted($user) : $this->scope->canAccess($user, $export->organization_id);
    }
}
