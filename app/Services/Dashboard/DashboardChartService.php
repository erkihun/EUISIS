<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;

class DashboardChartService
{
    public function __construct(
        private readonly DashboardMetricService $metrics,
    ) {}

    /**
     * Truncate a timestamp column to a date, using the syntax the active driver
     * understands. Postgres has no DATE() function, so cast instead; SQLite,
     * MySQL and MariaDB all support DATE().
     */
    private function dateExpression(string $column): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? "CAST($column AS date)"
            : "DATE($column)";
    }

    public function charts(array $scope, array $can): array
    {
        return [
            'employeesByStatus' => $can['employees'] ? $this->employeesByStatus($scope) : [],
            'employeesByOrganizationType' => $can['employees'] ? $this->employeesByOrganizationType($scope) : [],
            'employeeRegistrationsTrend' => $can['employees'] ? $this->employeeRegistrationsTrend($scope) : [],
            'organizationsByType' => $can['organizations'] ? $this->organizationsByType($scope) : [],
            'organizationsByStatus' => $can['organizations'] ? $this->organizationsByStatus($scope) : [],
            'positionsByGradeLevel' => ($can['positions'] ?? false) ? $this->positionsByGradeLevel($scope) : [],
            'positionsByJobFamily' => ($can['positions'] ?? false) ? $this->positionsByJobFamily($scope) : [],
            'positionsByOrganization' => ($can['positions'] ?? false) ? $this->positionsByOrganization($scope) : [],
            'cardsByStatus' => $can['cards'] ? $this->cardsByStatus($scope) : [],
            'cardRequestsByStatus' => $can['cards'] ? $this->cardRequestsByStatus($scope) : [],
            'cardLifecycleFunnel' => $can['cards'] ? $this->cardLifecycleFunnel($scope) : [],
            'verificationAllowedDenied' => $can['verification'] ? $this->verificationAllowedDenied($scope) : [],
            'denialReasons' => $can['verification'] ? $this->denialReasons($scope) : [],
            'verificationTrend' => $can['verification'] ? $this->verificationTrend($scope) : [],
            'entitlementsByServiceType' => $can['entitlements'] ? $this->entitlementsByServiceType($scope) : [],
            'entitlementsByStatus' => $can['entitlements'] ? $this->entitlementsByStatus($scope) : [],
            'serviceTransactionsTrend' => $can['transactions'] ? $this->serviceTransactionsTrend($scope) : [],
            'transactionsByServiceType' => $can['transactions'] ? $this->transactionsByServiceType($scope) : [],
            'transactionsByStatus' => $can['transactions'] ? $this->transactionsByStatus($scope) : [],
            'providersTopUsage' => $can['providers'] || $can['transactions'] ? $this->providersTopUsage($scope) : [],
            'providersByServiceType' => $can['providers'] ? $this->providersByServiceType($scope) : [],
            'transfersByStatus' => $can['transfers'] ? $this->transfersByStatus($scope) : [],
            'transferAging' => $can['transfers'] ? $this->transferAging($scope) : [],
            'auditEventDistribution' => $can['audit'] ? $this->auditEventDistribution($scope) : [],
        ];
    }

    private function employeesByStatus(array $scope): array
    {
        return $this->metrics->employeeQuery($scope)
            ->selectRaw('employees.status as "key", COUNT(*) as "value"')
            ->groupBy('employees.status')
            ->orderBy('employees.status')
            ->get()
            ->map(fn ($row): array => ['key' => $row->key, 'value' => (int) $row->value])
            ->all();
    }

    private function employeesByOrganizationType(array $scope): array
    {
        return $this->metrics->employeeQuery($scope)
            ->join('employee_assignments as organization_type_assignment', 'organization_type_assignment.id', '=', 'employees.current_assignment_id')
            ->join('organizations', 'organizations.id', '=', 'organization_type_assignment.organization_id')
            ->join('organization_types', 'organization_types.id', '=', 'organizations.organization_type_id')
            ->selectRaw('organization_types.name_en as "key", COUNT(*) as "value"')
            ->groupBy('organization_types.name_en')
            ->orderByDesc('value')
            ->limit($scope['top_limit'])
            ->get()
            ->map(fn ($row): array => ['key' => $row->key, 'value' => (int) $row->value])
            ->all();
    }

    private function employeeRegistrationsTrend(array $scope): array
    {
        return $this->metrics->employeeQuery($scope)
            ->whereBetween('employees.created_at', [$scope['date_from'], $scope['date_to']])
            ->selectRaw($this->dateExpression('employees.created_at').' as label, COUNT(*) as "value"')
            ->groupBy(DB::raw($this->dateExpression('employees.created_at')))
            ->orderBy('label')
            ->get()
            ->map(fn ($row): array => ['label' => $row->label, 'value' => (int) $row->value])
            ->all();
    }

    private function organizationsByType(array $scope): array
    {
        return $this->metrics->organizationQuery($scope)
            ->join('organization_types', 'organization_types.id', '=', 'organizations.organization_type_id')
            ->selectRaw('organization_types.name_en as "key", COUNT(*) as "value"')
            ->groupBy('organization_types.name_en')
            ->orderByDesc('value')
            ->limit($scope['top_limit'])
            ->get()
            ->map(fn ($row): array => ['key' => $row->key, 'value' => (int) $row->value])
            ->all();
    }

    private function organizationsByStatus(array $scope): array
    {
        return $this->metrics->organizationQuery($scope)
            ->selectRaw('organizations.status as "key", COUNT(*) as "value"')
            ->groupBy('organizations.status')
            ->orderBy('organizations.status')
            ->get()
            ->map(fn ($row): array => ['key' => $row->key, 'value' => (int) $row->value])
            ->all();
    }

    private function positionsByGradeLevel(array $scope): array
    {
        return $this->metrics->positionQuery($scope)
            ->whereNotNull('positions.grade_level')
            ->selectRaw('positions.grade_level as "key", COUNT(*) as "value"')
            ->groupBy('positions.grade_level')
            ->orderByDesc('value')
            ->limit($scope['top_limit'])
            ->get()
            ->map(fn ($row): array => ['key' => $row->key, 'value' => (int) $row->value])
            ->all();
    }

    private function positionsByJobFamily(array $scope): array
    {
        return $this->metrics->positionQuery($scope)
            ->whereNotNull('positions.job_family')
            ->selectRaw('positions.job_family as "key", COUNT(*) as "value"')
            ->groupBy('positions.job_family')
            ->orderByDesc('value')
            ->limit($scope['top_limit'])
            ->get()
            ->map(fn ($row): array => ['key' => $row->key, 'value' => (int) $row->value])
            ->all();
    }

    private function positionsByOrganization(array $scope): array
    {
        return $this->metrics->positionQuery($scope)
            ->join('organizations', 'organizations.id', '=', 'positions.organization_id')
            ->selectRaw('organizations.name_en as "key", COUNT(*) as "value"')
            ->groupBy('organizations.name_en')
            ->orderByDesc('value')
            ->limit($scope['top_limit'])
            ->get()
            ->map(fn ($row): array => ['key' => $row->key, 'value' => (int) $row->value])
            ->all();
    }

    private function cardsByStatus(array $scope): array
    {
        return $this->metrics->cardQuery($scope)
            ->selectRaw('id_cards.status as "key", COUNT(*) as "value"')
            ->groupBy('id_cards.status')
            ->orderBy('id_cards.status')
            ->get()
            ->map(fn ($row): array => ['key' => $row->key, 'value' => (int) $row->value])
            ->all();
    }

    private function cardRequestsByStatus(array $scope): array
    {
        return $this->metrics->cardRequestQuery($scope)
            ->selectRaw('card_requests.status as "key", COUNT(*) as "value"')
            ->groupBy('card_requests.status')
            ->orderBy('card_requests.status')
            ->get()
            ->map(fn ($row): array => ['key' => $row->key, 'value' => (int) $row->value])
            ->all();
    }

    private function cardLifecycleFunnel(array $scope): array
    {
        return [
            ['key' => 'requested', 'value' => $this->metrics->cardRequestQuery($scope)->count()],
            ['key' => 'verified', 'value' => $this->metrics->cardRequestQuery($scope)->where('card_requests.status', 'verified')->count()],
            ['key' => 'approved', 'value' => $this->metrics->cardRequestQuery($scope)->where('card_requests.status', 'approved')->count()],
            ['key' => 'printed', 'value' => $this->metrics->cardQuery($scope)->where('id_cards.status', 'printed')->count()],
            ['key' => 'issued', 'value' => $this->metrics->cardQuery($scope)->where('id_cards.status', 'issued')->count()],
            ['key' => 'active', 'value' => $this->metrics->cardQuery($scope)->where('id_cards.status', 'active')->count()],
        ];
    }

    private function verificationAllowedDenied(array $scope): array
    {
        return $this->metrics->verificationQuery($scope)
            ->whereBetween('card_verifications.created_at', [$scope['date_from'], $scope['date_to']])
            ->selectRaw('card_verifications.allowed as "key", COUNT(*) as "value"')
            ->groupBy('card_verifications.allowed')
            ->get()
            ->map(fn ($row): array => ['key' => (bool) $row->key ? 'allowed' : 'denied', 'value' => (int) $row->value])
            ->all();
    }

    private function denialReasons(array $scope): array
    {
        return $this->metrics->verificationQuery($scope)
            ->whereBetween('card_verifications.created_at', [$scope['date_from'], $scope['date_to']])
            ->where('card_verifications.allowed', false)
            ->selectRaw('card_verifications.result_code as "key", COUNT(*) as "value"')
            ->groupBy('card_verifications.result_code')
            ->orderByDesc('value')
            ->limit($scope['top_limit'])
            ->get()
            ->map(fn ($row): array => ['key' => $row->key, 'value' => (int) $row->value])
            ->all();
    }

    private function verificationTrend(array $scope): array
    {
        return $this->metrics->verificationQuery($scope)
            ->whereBetween('card_verifications.created_at', [$scope['date_from'], $scope['date_to']])
            ->selectRaw($this->dateExpression('card_verifications.created_at').' as label, COUNT(*) as "value"')
            ->groupBy(DB::raw($this->dateExpression('card_verifications.created_at')))
            ->orderBy('label')
            ->get()
            ->map(fn ($row): array => ['label' => $row->label, 'value' => (int) $row->value])
            ->all();
    }

    private function entitlementsByServiceType(array $scope): array
    {
        return $this->metrics->entitlementQuery($scope)
            ->join('service_types', 'service_types.id', '=', 'entitlements.service_type_id')
            ->selectRaw('service_types.name_en as "key", COUNT(*) as "value"')
            ->groupBy('service_types.name_en')
            ->orderByDesc('value')
            ->get()
            ->map(fn ($row): array => ['key' => $row->key, 'value' => (int) $row->value])
            ->all();
    }

    private function entitlementsByStatus(array $scope): array
    {
        return $this->metrics->entitlementQuery($scope)
            ->selectRaw('entitlements.status as "key", COUNT(*) as "value"')
            ->groupBy('entitlements.status')
            ->orderBy('entitlements.status')
            ->get()
            ->map(fn ($row): array => ['key' => $row->key, 'value' => (int) $row->value])
            ->all();
    }

    private function serviceTransactionsTrend(array $scope): array
    {
        return $this->metrics->transactionQuery($scope)
            ->whereBetween('service_transactions.occurred_at', [$scope['date_from'], $scope['date_to']])
            ->selectRaw($this->dateExpression('service_transactions.occurred_at').' as label, COUNT(*) as "value"')
            ->groupBy(DB::raw($this->dateExpression('service_transactions.occurred_at')))
            ->orderBy('label')
            ->get()
            ->map(fn ($row): array => ['label' => $row->label, 'value' => (int) $row->value])
            ->all();
    }

    private function transactionsByServiceType(array $scope): array
    {
        return $this->metrics->transactionQuery($scope)
            ->join('service_types', 'service_types.id', '=', 'service_transactions.service_type_id')
            ->selectRaw('service_types.name_en as "key", COUNT(*) as "value"')
            ->groupBy('service_types.name_en')
            ->orderByDesc('value')
            ->get()
            ->map(fn ($row): array => ['key' => $row->key, 'value' => (int) $row->value])
            ->all();
    }

    private function transactionsByStatus(array $scope): array
    {
        return $this->metrics->transactionQuery($scope)
            ->selectRaw('service_transactions.status as "key", COUNT(*) as "value"')
            ->groupBy('service_transactions.status')
            ->orderBy('service_transactions.status')
            ->get()
            ->map(fn ($row): array => ['key' => $row->key, 'value' => (int) $row->value])
            ->all();
    }

    private function providersTopUsage(array $scope): array
    {
        return $this->metrics->providerQuery($scope)
            ->leftJoin('service_transactions', 'service_transactions.service_provider_id', '=', 'service_providers.id')
            ->selectRaw('service_providers.name as "key", COUNT(service_transactions.id) as "value"')
            ->groupBy('service_providers.name')
            ->orderByDesc('value')
            ->limit($scope['top_limit'])
            ->get()
            ->map(fn ($row): array => ['key' => $row->key, 'value' => (int) $row->value])
            ->all();
    }

    private function providersByServiceType(array $scope): array
    {
        return $this->metrics->providerQuery($scope)
            ->join('service_types', 'service_types.id', '=', 'service_providers.service_type_id')
            ->selectRaw('service_types.name_en as "key", COUNT(service_providers.id) as "value"')
            ->groupBy('service_types.name_en')
            ->orderByDesc('value')
            ->get()
            ->map(fn ($row): array => ['key' => $row->key, 'value' => (int) $row->value])
            ->all();
    }

    private function transfersByStatus(array $scope): array
    {
        return $this->metrics->transferQuery($scope)
            ->selectRaw('employee_transfers.status as "key", COUNT(*) as "value"')
            ->groupBy('employee_transfers.status')
            ->orderBy('employee_transfers.status')
            ->get()
            ->map(fn ($row): array => ['key' => $row->key, 'value' => (int) $row->value])
            ->all();
    }

    private function transferAging(array $scope): array
    {
        $pendingTransferQuery = $this->metrics->transferQuery($scope)
            ->whereIn('employee_transfers.status', ['submitted', 'current_organization_confirmed', 'receiving_organization_confirmed']);

        return [
            [
                'key' => '0_3_days',
                'value' => (clone $pendingTransferQuery)
                    ->where('employee_transfers.created_at', '>=', now()->subDays(3))
                    ->count(),
            ],
            [
                'key' => '4_7_days',
                'value' => (clone $pendingTransferQuery)
                    ->whereBetween('employee_transfers.created_at', [now()->subDays(7), now()->subDays(4)])
                    ->count(),
            ],
            [
                'key' => '8_plus_days',
                'value' => (clone $pendingTransferQuery)
                    ->where('employee_transfers.created_at', '<', now()->subDays(7))
                    ->count(),
            ],
        ];
    }

    private function auditEventDistribution(array $scope): array
    {
        return DB::table('audit_logs')
            ->when(
                ! $scope['global_access'] && $scope['organization_ids'] !== [],
                fn ($query) => $query->whereIn('organization_id', $scope['organization_ids'])
            )
            ->when(
                ! $scope['global_access'] && $scope['organization_ids'] === [],
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->whereBetween('created_at', [$scope['date_from'], $scope['date_to']])
            ->selectRaw('event_type as "key", COUNT(*) as "value"')
            ->groupBy('event_type')
            ->orderByDesc('value')
            ->limit($scope['top_limit'])
            ->get()
            ->map(fn ($row): array => ['key' => $row->key, 'value' => (int) $row->value])
            ->all();
    }
}
