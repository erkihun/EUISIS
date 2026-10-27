<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CafeteriaReportRun;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminUsers;
use App\Services\Cafeteria\CafeteriaProviderAccessService;
use App\Services\OrganizationScope\OrganizationScopeService;

readonly class CafeteriaReportRunPolicy
{
    use DeniesNonAdminUsers;

    public function __construct(private OrganizationScopeService $scope, private CafeteriaProviderAccessService $providers) {}

    public function viewAny(User $user): bool
    {
        return $user->can('cafeteria_reports.view');
    }

    public function view(User $user, CafeteriaReportRun $report): bool
    {
        if (! $user->can('cafeteria_reports.view')
            || (! $this->scope->isUnrestricted($user) && ! $this->scope->canAccessOrganization($user, $report->organization_id))) {
            return false;
        }
        if ($this->providers->canAccessAllProviders($user)) {
            return true;
        }
        $providerIds = $report->filters['provider_ids'] ?? (isset($report->filters['provider_id']) ? [$report->filters['provider_id']] : null);

        return is_array($providerIds) && $providerIds !== []
            && array_diff($providerIds, $this->providers->accessibleProviderIds($user)) === [];
    }

    public function generate(User $user): bool
    {
        return $user->can('cafeteria_reports.generate');
    }

    public function export(User $user): bool
    {
        return $user->can('cafeteria_reports.export');
    }
}
