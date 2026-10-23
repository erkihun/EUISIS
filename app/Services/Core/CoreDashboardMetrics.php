<?php

declare(strict_types=1);

namespace App\Services\Core;

use App\Enums\CardRequestStatus;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationUnitStatus;
use App\Models\AuditLog;
use App\Models\CardRequest;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\ExternalApplication;
use App\Models\HierarchyVersion;
use App\Models\IdCard;
use App\Models\Integration\IntegrationEvent;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * The figures on the Core dashboard: how many master records exist and which
 * need attention, inside the viewer's organization scope.
 *
 * Every figure is a single indexed count. Figures are computed once per scope
 * and kept for a few minutes, then each viewer sees only the ones their
 * permissions cover. A scoped viewer never receives a citywide figure: the
 * counts are restricted to their organizations, and the figures that only make
 * sense citywide (unplaced employees, audit volume, system integrations) are
 * left out for them.
 */
final class CoreDashboardMetrics
{
    private const CACHE_SECONDS = 300;

    private const EXPIRY_WINDOW_DAYS = 30;

    /**
     * Each figure: the domain it belongs to, the permission that shows it, and
     * whether it is citywide only. Domains are listed in display order.
     *
     * @var array<string, array{domain: string, permission: string, citywide: bool}>
     */
    private const METRICS = [
        'organizations_active' => ['domain' => 'organization', 'permission' => 'organizations.view', 'citywide' => false],
        'units_active' => ['domain' => 'organization', 'permission' => 'organization-units.viewAny', 'citywide' => false],
        'hierarchy_version' => ['domain' => 'organization', 'permission' => 'hierarchy-versions.viewAny', 'citywide' => false],
        'positions_active' => ['domain' => 'positions', 'permission' => 'positions.viewAny', 'citywide' => false],
        'positions_inactive' => ['domain' => 'positions', 'permission' => 'positions.viewAny', 'citywide' => false],
        'employees_active' => ['domain' => 'employees', 'permission' => 'employees.view', 'citywide' => false],
        'assignments_current' => ['domain' => 'employees', 'permission' => 'employees.view', 'citywide' => false],
        'employees_unplaced' => ['domain' => 'employees', 'permission' => 'employees.view', 'citywide' => true],
        'cards_active' => ['domain' => 'credentials', 'permission' => 'cards.view', 'citywide' => false],
        'cards_expiring' => ['domain' => 'credentials', 'permission' => 'cards.view', 'citywide' => false],
        'card_requests_open' => ['domain' => 'credentials', 'permission' => 'id-cards.viewAny', 'citywide' => false],
        'users_active' => ['domain' => 'governance', 'permission' => 'users.viewAny', 'citywide' => false],
        'applications_active' => ['domain' => 'governance', 'permission' => 'api_management.view', 'citywide' => true],
        'integration_pending' => ['domain' => 'governance', 'permission' => 'integrations.view', 'citywide' => true],
        'audit_events_24h' => ['domain' => 'governance', 'permission' => 'audit.view', 'citywide' => true],
    ];

    /**
     * The page each domain opens: the first of these the viewer may open.
     *
     * @var array<string, array<string, string>> route => permission that page checks
     */
    private const DOMAIN_LINKS = [
        'organization' => ['organizations.index' => 'organizations.view', 'organization-units.index' => 'organization-units.viewAny'],
        'positions' => ['positions.index' => 'positions.viewAny'],
        'employees' => ['employees.index' => 'employees.view'],
        'credentials' => ['id-cards.index' => 'cards.view', 'card-requests.index' => 'id-cards.viewAny'],
        'governance' => ['users.index' => 'users.viewAny', 'audit-logs.index' => 'audit.view', 'api-management.index' => 'api_management.view', 'integrations.index' => 'integrations.view'],
    ];

    public function __construct(private readonly OrganizationScopeService $scope) {}

    /**
     * @return array{citywide: bool, organization_count: int|null, domains: list<array{key: string, href: string|null, metrics: list<array{key: string, value: int|string|null}>}>}
     */
    public function for(User $user): array
    {
        $citywide = $this->scope->isUnrestricted($user);
        $organizationIds = $citywide ? null : $this->scope->allowedOrganizationIds($user);
        $values = $this->values($user, $organizationIds);

        $domains = [];
        foreach (self::METRICS as $key => $metric) {
            if (($metric['citywide'] && ! $citywide) || ! $user->can($metric['permission'])) {
                continue;
            }
            $domains[$metric['domain']] ??= ['key' => $metric['domain'], 'href' => $this->domainLink($user, $metric['domain']), 'metrics' => []];
            $domains[$metric['domain']]['metrics'][] = ['key' => $key, 'value' => $values[$key] ?? null];
        }

        return [
            'citywide' => $citywide,
            'organization_count' => $organizationIds === null ? null : count($organizationIds),
            'domains' => array_values($domains),
        ];
    }

    /**
     * @param  list<string>|null  $organizationIds  null for a citywide viewer
     * @return array<string, int|string|null>
     */
    private function values(User $user, ?array $organizationIds): array
    {
        $scopeKey = $organizationIds === null ? 'all' : sha1(implode(',', collect($organizationIds)->map(fn ($id): string => (string) $id)->sort()->values()->all()));

        return Cache::remember('core.dashboard.'.$scopeKey, self::CACHE_SECONDS, fn (): array => $this->compute($user, $organizationIds));
    }

    /**
     * @param  list<string>|null  $organizationIds
     * @return array<string, int|string|null>
     */
    private function compute(User $user, ?array $organizationIds): array
    {
        $inOrganizations = fn (Builder $query, string $column = 'organization_id'): Builder => $organizationIds === null ? $query : $query->whereIn($column, $organizationIds);
        // Employee-owned records follow the employee's current assignment, as everywhere else in the scope model.
        $employeeIds = $this->scope->employeeIdsInScope($user);
        $ofEmployees = fn (Builder $query, string $column = 'employee_id'): Builder => $employeeIds === null ? $query : $query->whereIn($column, $employeeIds);
        $now = now();

        return [
            'organizations_active' => $inOrganizations(Organization::query()->where('status', OrganizationStatus::Active->value), 'id')->count(),
            'units_active' => $inOrganizations(OrganizationUnit::query()->where('status', OrganizationUnitStatus::Active->value))->count(),
            'hierarchy_version' => HierarchyVersion::query()->where('status', HierarchyVersionStatus::Published->value)->latest('effective_from')->value('version_name'),
            'positions_active' => $inOrganizations(Position::query()->where('is_active', true))->count(),
            'positions_inactive' => $inOrganizations(Position::query()->where('is_active', false))->count(),
            'employees_active' => $ofEmployees(Employee::query()->where('status', EmployeeStatus::Active->value), 'id')->count(),
            'assignments_current' => $inOrganizations(EmployeeAssignment::query()->where('is_current', true))->count(),
            'employees_unplaced' => Employee::query()->where('status', EmployeeStatus::Active->value)->whereNull('current_assignment_id')->count(),
            'cards_active' => $ofEmployees(IdCard::query()->where('status', CardStatus::Active->value)->where('is_current', true))->count(),
            'cards_expiring' => $ofEmployees(IdCard::query()->where('status', CardStatus::Active->value)->where('is_current', true)
                ->whereBetween('expires_at', [$now, $now->copy()->addDays(self::EXPIRY_WINDOW_DAYS)]))->count(),
            'card_requests_open' => $ofEmployees(CardRequest::query()->whereIn('status', [CardRequestStatus::Submitted->value, CardRequestStatus::Verified->value]))->count(),
            'users_active' => $this->scope->applyUserScope(User::query()->where('status', 'active'), $user)->count(),
            'applications_active' => ExternalApplication::query()->where('status', 'active')->count(),
            'integration_pending' => Schema::hasTable('integration_events') ? IntegrationEvent::query()->where('status', IntegrationEvent::STATUS_PENDING)->count() : null,
            'audit_events_24h' => AuditLog::query()->where('created_at', '>=', $now->copy()->subDay())->count(),
        ];
    }

    private function domainLink(User $user, string $domain): ?string
    {
        foreach (self::DOMAIN_LINKS[$domain] ?? [] as $route => $permission) {
            if ($user->can($permission)) {
                return route($route);
            }
        }

        return null;
    }
}
