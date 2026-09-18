<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

class DashboardDataService
{
    public function __construct(
        private readonly DashboardScopeService $scopeService,
        private readonly DashboardMetricService $metricService,
        private readonly DashboardChartService $chartService,
    ) {}

    public function build(User $user, array $filters): array
    {
        $scope = $this->scopeService->resolve($user, $filters);
        $can = $this->sectionPermissions($user);

        return Cache::remember(
            $this->cacheKey($user, $scope, $can),
            now()->addSeconds(120),
            fn (): array => [
                'filters' => [
                    'dateRange' => $scope['date_range'],
                    'dateFrom' => $scope['date_from']->toDateString(),
                    'dateTo' => $scope['date_to']->toDateString(),
                    'organizationId' => $scope['selected_organization_id'],
                    'organizationOptions' => $scope['organization_options'],
                ],
                'can' => $can,
                'header' => $this->header($user, $scope),
                'kpis' => $this->metricService->kpis($user, $scope, $can),
                'cards' => $this->metricService->sectionCards($scope, $can),
                'charts' => $this->chartService->charts($scope, $can),
                'workflowQueues' => $this->metricService->workflowQueues($scope, $can),
                'alerts' => $this->metricService->alerts($scope, $can),
                'recentActivity' => $this->metricService->recentActivity($scope, $can['audit']),
                'meta' => [
                    'scope' => [
                        'providerOnly' => $scope['provider_only'],
                        'globalAccess' => $scope['global_access'],
                    ],
                ],
            ],
        );
    }

    /**
     * Page header context: whose data this is, when it was computed, and the
     * actions this user may actually take.
     *
     * `generatedAt` is built inside the cached payload on purpose, so it
     * reports when the figures were computed rather than when the page was
     * rendered — otherwise a cached dashboard would claim to be fresh.
     */
    private function header(User $user, array $scope): array
    {
        $selectedId = $scope['selected_organization_id'];
        $selected = $selectedId === null ? null : collect($scope['organization_options'])
            ->firstWhere('id', $selectedId);

        return [
            'generatedAt' => now()->toIso8601String(),
            'organizationName' => $selected['name'] ?? null,
            'organizationCount' => count($scope['organization_ids']),
            'globalAccess' => $scope['global_access'],
            'quickActions' => $this->quickActions($user, $selectedId),
        ];
    }

    /**
     * Only actions backed by a real route AND a permission this user holds.
     *
     * @return list<array{key: string, routeName: string, params: array<string, string>}>
     */
    private function quickActions(User $user, ?string $selectedOrganizationId): array
    {
        $candidates = [
            ['key' => 'addEmployee', 'routeName' => 'employees.create', 'permissions' => ['employees.manage']],
            ['key' => 'addPosition', 'routeName' => 'positions.create', 'permissions' => ['positions.create']],
            ['key' => 'issueIdCard', 'routeName' => 'card-requests.create', 'permissions' => ['id-cards.create', 'cards.manage']],
            ['key' => 'verify', 'routeName' => 'cafeteria.scan', 'permissions' => ['cafeteria_transactions.scan']],
            ['key' => 'viewReports', 'routeName' => 'service-feedback.admin.reports', 'permissions' => ['service_feedback.view']],
            ['key' => 'apiManagement', 'routeName' => 'api-management.index', 'permissions' => ['api_management.view']],
        ];

        $actions = [];

        foreach ($candidates as $candidate) {
            if (Route::has($candidate['routeName']) && $this->hasAnyPermission($user, $candidate['permissions'])) {
                $actions[] = ['key' => $candidate['key'], 'routeName' => $candidate['routeName'], 'params' => []];
            }
        }

        // The organogram route needs a concrete organization, so it is offered
        // only once the dashboard is narrowed to one.
        if ($selectedOrganizationId !== null && Route::has('organizations.organogram') && $this->hasAnyPermission($user, ['organizations.view', 'organizations.viewAny'])) {
            $actions[] = [
                'key' => 'organogram',
                'routeName' => 'organizations.organogram',
                'params' => ['organization' => $selectedOrganizationId],
            ];
        }

        return $actions;
    }

    public function canViewDashboard(User $user): bool
    {
        return $this->hasAnyPermission($user, [
            'dashboard.view',
            'reports.view',
            'employees.view',
            'employees.viewAny',
            'organizations.view',
            'organizations.viewAny',
            'transactions.view',
            'service-transactions.viewAny',
            'cards.view',
            'id-cards.viewAny',
            'service_feedback.view',
        ]);
    }

    public function sectionPermissions(User $user): array
    {
        return [
            'dashboard' => $this->canViewDashboard($user),
            'employees' => $this->hasAnyPermission($user, ['employees.viewAny', 'employees.view', 'employees.manage']),
            'organizations' => $this->hasAnyPermission($user, ['organizations.viewAny', 'organizations.view', 'organizations.manage']),
            'positions' => $this->hasAnyPermission($user, ['positions.viewAny', 'positions.view', 'positions.manage']),
            'cards' => $this->hasAnyPermission($user, ['id-cards.viewAny', 'id-cards.view', 'cards.view']),
            'verification' => $this->hasAnyPermission($user, ['id-cards.verify', 'card-verifications.viewAny', 'id-cards.viewAny', 'cards.view']),
            'entitlements' => $this->hasAnyPermission($user, ['entitlements.viewAny', 'entitlements.view', 'entitlements.manage']),
            'transactions' => $this->hasAnyPermission($user, ['service-transactions.viewAny', 'transactions.view', 'transactions.manage']),
            'providers' => $this->hasAnyPermission($user, ['providers.viewAny', 'transactions.view', 'transactions.manage']),
            'serviceFeedback' => $this->hasAnyPermission($user, ['service_feedback.view']),
            'transfers' => $this->hasAnyPermission($user, ['transfers.viewAny', 'transfers.view']),
            'audit' => $this->hasAnyPermission($user, ['audit-logs.viewAny', 'audit.view', 'reports.view']),
            // Modules that may not be provisioned in every deployment; the
            // dashboard omits their sections entirely when unavailable.
            'nfc' => $this->hasAnyPermission($user, ['nfc_credentials.view', 'nfc_terminals.view']),
            'integration' => $this->hasAnyPermission($user, ['api_management.view', 'api_management.logs.view']),
        ];
    }

    private function hasAnyPermission(User $user, array $permissions): bool
    {
        if ($user->hasRole('Super Admin')) {
            return true;
        }

        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    private function cacheKey(User $user, array $scope, array $can): string
    {
        return 'dashboard:'.md5(json_encode([
            'user' => $user->id,
            'date_range' => $scope['date_range'],
            'date_from' => $scope['date_from']->toDateString(),
            'date_to' => $scope['date_to']->toDateString(),
            'organization_ids' => $scope['organization_ids'],
            'provider_ids' => $scope['provider_ids'],
            'service_type_ids' => $scope['service_type_ids'],
            'can' => $can,
        ], JSON_THROW_ON_ERROR));
    }
}
