<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\AssignmentStatus;
use App\Enums\CardRequestStatus;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Enums\EntitlementStatus;
use App\Enums\ServiceFeedbackStatus;
use App\Enums\TransferStatus;
use App\Models\ApiEndpointDefinition;
use App\Models\ApiRequestLog;
use App\Models\AuditLog;
use App\Models\CardPrintBatch;
use App\Models\CardRequest;
use App\Models\CardVerification;
use App\Models\Employee;
use App\Models\EmployeeDuplicateFlag;
use App\Models\EmployeeServiceFeedback;
use App\Models\EmployeeTransfer;
use App\Models\Entitlement;
use App\Models\ExternalApplication;
use App\Models\HierarchyVersion;
use App\Models\IdCard;
use App\Models\NfcCredential;
use App\Models\NfcVerificationLog;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\ServiceProvider;
use App\Models\ServiceTerminal;
use App\Models\ServiceTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

class DashboardMetricService
{
    /**
     * Cards allowed in the top strip.
     *
     * A Super Admin previously saw all seventeen KPIs stacked above the fold,
     * which is the point at which a dashboard stops being read at all. The
     * strip now carries only what applies across the whole institution and
     * needs a daily glance; everything else keeps its number but moves down to
     * the section it belongs to, where it sits next to the charts that explain
     * it. Nothing is dropped.
     */
    private const HEADLINE_KPI_LIMIT = 6;

    public function kpis(User $user, array $scope, array $can): array
    {
        $kpis = [];

        if ($can['employees']) {
            $registeredEmployees = $this->employeeQuery($scope)->count();
            $activeEmployees = $this->employeeQuery($scope)
                ->where('employees.status', EmployeeStatus::Active->value)
                ->count();

            $kpis[] = $this->kpi('activeEmployees', $activeEmployees, 'users', 'primary', $this->employeeTrend($scope, EmployeeStatus::Active->value), '', 'employees.index');
            $kpis[] = $this->kpi('registeredEmployees', $registeredEmployees, 'users', 'neutral', $this->employeeTrend($scope), '', 'employees.index', 'employees');
            $kpis[] = $this->kpi('dataQualityWarnings', $this->duplicateWarningsCount($scope), 'alert', 'warning');
        }

        if ($can['organizations']) {
            $kpis[] = $this->kpi(
                'organizations',
                $this->organizationQuery($scope)->count(),
                'building',
                'neutral',
                null,
                '',
                'organizations.index',
                'organizations',
            );
            $kpis[] = $this->kpi(
                'organizationUnits',
                $this->organizationUnitQuery($scope)->count(),
                'layers',
                'neutral',
                null,
                '',
                null,
                'organizations',
            );
        }

        if ($can['positions'] ?? false) {
            $totalPositions = $this->positionQuery($scope)->count();
            $occupiedPositions = $this->occupiedPositionsCount($scope);

            $kpis[] = $this->kpi(
                'totalPositions',
                $totalPositions,
                'layers',
                'primary',
                null,
                '',
                'positions.index',
                'positions',
            );
            $kpis[] = $this->kpi(
                'occupiedPositions',
                $occupiedPositions,
                'layers',
                'success',
                null,
                '',
                'positions.status',
                'positions',
            );
            /* Vacancies drive recruitment, so this one stays up top. */
            $kpis[] = $this->kpi(
                'vacantPositions',
                $this->vacantPositionsCount($scope, $totalPositions, $occupiedPositions),
                'alert',
                'warning',
                null,
                '',
                'positions.status',
            );
        }

        if ($can['cards']) {
            // Three buckets, two grouped queries, both memoised for reuse by
            // the workflow queues and the lifecycle funnel below.
            $activeCards = $this->cardStatusCount($scope, CardStatus::Active->value);
            $pendingCardRequests = $this->cardRequestStatusCount($scope, CardRequestStatus::Submitted->value);
            $pendingApprovals = $this->cardRequestStatusCount($scope, CardRequestStatus::Submitted->value, CardRequestStatus::Verified->value);
            $expiringCards = $this->expiringCardsCount($scope);

            $kpis[] = $this->kpi(
                'activeIdCards',
                $activeCards,
                'card',
                'primary',
                $this->cardTrend($scope, CardStatus::Active->value),
                '',
                'id-cards.index',
                'cards',
            );
            $kpis[] = $this->kpi(
                'expiringIdCards',
                $expiringCards,
                'alert',
                'warning',
                null,
                '',
                'id-cards.index',
                'cards',
            );
            /* A submitted request is someone waiting on this office — headline. */
            $kpis[] = $this->kpi('pendingCardRequests', $pendingCardRequests, 'queue', 'warning', null, '', 'card-requests.index');
            $kpis[] = $this->kpi('pendingApprovals', $pendingApprovals, 'queue', 'warning', null, '', 'card-requests.index', 'cards');
        }

        if ($can['entitlements']) {
            $kpis[] = $this->kpi(
                'activeEntitlements',
                $this->entitlementQuery($scope)->where('entitlements.status', EntitlementStatus::Active->value)->count(),
                'layers',
                'success',
                null,
                '',
                null,
                'entitlements',
            );
        }

        if ($can['verification']) {
            $kpis[] = $this->kpi(
                'todayVerifications',
                $this->verificationQuery($scope)
                    ->whereDate('card_verifications.created_at', now()->toDateString())
                    ->count(),
                'shield',
                'primary',
                null,
                '',
                null,
                'verification',
            );
        }

        if ($can['transactions']) {
            $kpis[] = $this->kpi(
                'todayTransactions',
                $this->transactionQuery($scope)
                    ->whereDate('service_transactions.occurred_at', now()->toDateString())
                    ->count(),
                'activity',
                'primary',
                null,
                '',
                null,
                'transactions',
            );
        }

        if ($can['serviceFeedback']) {
            $lowRatingFeedback = $this->feedbackQuery($scope)
                ->where('employee_service_feedback.rating', '<=', 2)
                ->where('employee_service_feedback.status', ServiceFeedbackStatus::Pending->value)
                ->count();

            /*
             * Unanswered low ratings are a complaint queue, not a statistic —
             * they belong where someone will see them first.
             */
            $kpis[] = $this->kpi(
                'lowRatingFeedback',
                $lowRatingFeedback,
                'alert',
                $lowRatingFeedback > 0 ? 'warning' : 'neutral',
                null,
                '',
                'service-feedback.admin.dashboard',
            );
        }

        if ($can['transfers']) {
            $kpis[] = $this->kpi(
                'pendingTransfers',
                $this->transferQuery($scope)
                    ->whereIn('employee_transfers.status', [
                        TransferStatus::Submitted->value,
                        TransferStatus::CurrentOrganizationConfirmed->value,
                        TransferStatus::ReceivingOrganizationConfirmed->value,
                    ])->count(),
                'transfer',
                'warning',
            );
        }

        return $this->capHeadlines($kpis);
    }

    /**
     * Keeps the top strip within `HEADLINE_KPI_LIMIT`.
     *
     * A user with every permission qualifies for more headline cards than the
     * strip should hold. Rather than dropping the overflow, it is demoted to
     * the `overflow` group, which the page renders in the same compact row it
     * uses for section metrics — still one click from the detail, just no
     * longer competing for the top of the page.
     *
     * @param  array<int, array<string, mixed>>  $kpis
     * @return array<int, array<string, mixed>>
     */
    private function capHeadlines(array $kpis): array
    {
        $seen = 0;

        foreach ($kpis as $index => $kpi) {
            if (($kpi['group'] ?? 'headline') !== 'headline') {
                continue;
            }

            $seen++;

            if ($seen > self::HEADLINE_KPI_LIMIT) {
                $kpis[$index]['group'] = 'overflow';
            }
        }

        return $kpis;
    }

    public function sectionCards(array $scope, array $can): array
    {
        $cards = [];

        if ($can['employees']) {
            $cards['employees'] = [
                'duplicateWarnings' => $this->duplicateWarningsCount($scope),
                'missingPhotoCount' => $this->employeeQuery($scope)->whereNull('employees.photo_path')->count(),
                'missingDocumentCount' => $this->employeeQuery($scope)->doesntHave('documents')->count(),
                'averageDataQualityScore' => round((float) $this->employeeQuery($scope)->avg('employees.data_quality_score'), 2),
            ];
        }

        if ($can['organizations']) {
            $cards['organizations'] = [
                'draftHierarchyVersions' => HierarchyVersion::query()->where('status', 'draft')->count(),
                'activeHierarchyVersion' => HierarchyVersion::query()
                    ->where('status', 'published')
                    ->orderByDesc('effective_from')
                    ->value('version_name'),
                'missingMetadataCount' => $this->organizationQuery($scope)
                    ->where(function (Builder $query): void {
                        $query->whereNull('organizations.legal_basis_ref')
                            ->orWhereNull('organizations.name_am');
                    })->count(),
            ];
        }

        if ($can['positions'] ?? false) {
            $totalPositions = $this->positionQuery($scope)->count();
            $occupiedPositions = $this->occupiedPositionsCount($scope);

            $cards['positions'] = [
                'totalPositions' => $totalPositions,
                'activePositions' => $this->positionQuery($scope)->where('positions.is_active', true)->count(),
                'occupiedPositions' => $occupiedPositions,
                'vacantPositions' => $this->vacantPositionsCount($scope, $totalPositions, $occupiedPositions),
                'positionsWithOccupation' => $this->positionQuery($scope)
                    ->whereNotNull('positions.occupation_id')
                    ->count(),
            ];
        }

        if ($can['cards']) {
            $cards['cards'] = [
                'expiringSoonCount' => $this->expiringCardsCount($scope),
                'pendingPrintBatches' => CardPrintBatch::query()->where('status', 'pending')->count(),
                'duplicateActiveCardWarnings' => $this->duplicateActiveCardWarnings($scope),
            ];
        }

        if ($can['verification']) {
            $cards['verification'] = [
                'allowedToday' => $this->verificationQuery($scope)
                    ->whereDate('card_verifications.created_at', now()->toDateString())
                    ->where('card_verifications.allowed', true)
                    ->count(),
                'deniedToday' => $this->verificationQuery($scope)
                    ->whereDate('card_verifications.created_at', now()->toDateString())
                    ->where('card_verifications.allowed', false)
                    ->count(),
            ];
        }

        if ($can['entitlements']) {
            $cards['entitlements'] = [
                'expiringSoonCount' => $this->entitlementQuery($scope)
                    ->whereNotNull('entitlements.effective_to')
                    ->whereBetween('entitlements.effective_to', [now()->toDateString(), now()->addDays(30)->toDateString()])
                    ->count(),
                'exhaustedCount' => $this->entitlementQuery($scope)
                    ->where('entitlements.status', EntitlementStatus::Exhausted->value)
                    ->count(),
            ];
        }

        if ($can['providers']) {
            $cards['providers'] = [
                'activeProviders' => $this->providerQuery($scope)
                    ->where('service_providers.status', 'active')
                    ->count(),
            ];
        }

        if ($can['serviceFeedback']) {
            $feedback = $this->feedbackQuery($scope);
            $feedbackTotal = (clone $feedback)->count();

            $cards['feedback'] = [
                'total' => $feedbackTotal,
                'averageSatisfaction' => $feedbackTotal > 0
                    ? round((float) (clone $feedback)->avg('employee_service_feedback.rating'), 2)
                    : null,
                'lowRatingPending' => (clone $feedback)
                    ->where('employee_service_feedback.rating', '<=', 2)
                    ->where('employee_service_feedback.status', ServiceFeedbackStatus::Pending->value)
                    ->count(),
                'pendingReview' => (clone $feedback)
                    ->where('employee_service_feedback.status', ServiceFeedbackStatus::Pending->value)
                    ->count(),
            ];
        }

        if ($can['nfc'] ?? false) {
            // One grouped query for credential states rather than a count each.
            $byStatus = $this->nfcCredentialQuery($scope)
                ->toBase()
                ->selectRaw('nfc_credentials.status as state, count(*) as aggregate')
                ->groupBy('nfc_credentials.status')
                ->pluck('aggregate', 'state');

            $cards['nfc'] = [
                'active' => (int) ($byStatus['active'] ?? 0),
                'pending' => (int) ($byStatus['pending'] ?? 0),
                'suspended' => (int) ($byStatus['suspended'] ?? 0),
                'revoked' => (int) ($byStatus['revoked'] ?? 0),
                'lost' => (int) ($byStatus['lost'] ?? 0),
                'activeTerminals' => $this->serviceTerminalQuery($scope)->where('service_terminals.status', 'active')->count(),
                'inactiveTerminals' => $this->serviceTerminalQuery($scope)->where('service_terminals.status', '!=', 'active')->count(),
                // NFC taps are counted separately from QR card verifications:
                // they are different credentials and must never be summed.
                'verificationsToday' => NfcVerificationLog::query()
                    ->whereDate('nfc_verification_logs.occurred_at', now()->toDateString())
                    ->whereIn('nfc_verification_logs.nfc_credential_id', $this->nfcCredentialQuery($scope)->select('nfc_credentials.id'))
                    ->count(),
            ];
        }

        if ($can['integration'] ?? false) {
            // API Management is system-level configuration, not organizational
            // data, so it is gated on the permission rather than on scope.
            $today = now()->toDateString();

            $cards['integration'] = [
                'activeApplications' => ExternalApplication::query()->where('status', 'active')->count(),
                'activeTokens' => DB::table('personal_access_tokens')
                    ->where('tokenable_type', ExternalApplication::class)
                    ->count(),
                'activeEndpoints' => ApiEndpointDefinition::query()->where('status', 'active')->count(),
                'requestsToday' => ApiRequestLog::query()->whereDate('requested_at', $today)->count(),
                'failedToday' => ApiRequestLog::query()->whereDate('requested_at', $today)->where('success', false)->count(),
            ];
        }

        return $cards;
    }

    public function workflowQueues(array $scope, array $can): array
    {
        $items = [];

        if ($can['cards']) {
            $items[] = $this->queue('awaitingVerification', 'card-requests.index', $this->cardRequestStatusCount($scope, CardRequestStatus::Submitted->value), 'warning');
            $items[] = $this->queue('awaitingApproval', 'card-requests.index', $this->cardRequestStatusCount($scope, CardRequestStatus::Verified->value), 'warning');
            $items[] = $this->queue('awaitingIssuance', 'id-cards.index', $this->cardStatusCount($scope, CardStatus::Printed->value), 'primary');
        }

        if ($can['transfers']) {
            $items[] = $this->queue('transfersPendingConfirmation', 'transfers.dashboard', $this->transferQuery($scope)
                ->whereIn('employee_transfers.status', [
                    TransferStatus::Submitted->value,
                    TransferStatus::CurrentOrganizationConfirmed->value,
                    TransferStatus::ReceivingOrganizationConfirmed->value,
                ])->count(), 'warning');
        }

        if ($can['organizations']) {
            $items[] = $this->queue('hierarchyVersionsAwaitingPublish', 'organizations.index', HierarchyVersion::query()
                ->where('status', 'draft')
                ->count(), 'neutral');
        }

        return array_values(array_filter($items, fn (array $item): bool => $item['count'] > 0));
    }

    public function alerts(array $scope, array $can): array
    {
        $alerts = [];

        if ($can['cards']) {
            $expiringCards = $this->cardQuery($scope)
                ->whereBetween('id_cards.expires_at', [now(), now()->addDays(30)])
                ->count();

            if ($expiringCards > 0) {
                $alerts[] = $this->alert('cardsExpiringSoon', 'warning', $expiringCards, route('id-cards.index'));
            }

            $expiredCards = $this->cardQuery($scope)
                ->where('id_cards.status', CardStatus::Expired->value)
                ->count();

            if ($expiredCards > 0) {
                $alerts[] = $this->alert('expiredCardsPendingRenewal', 'critical', $expiredCards, route('id-cards.index'));
            }
        }

        if ($can['employees']) {
            $employeesWithoutCard = $this->employeeQuery($scope)
                ->whereDoesntHave('cardRequests.cards', fn (Builder $query) => $query->where('is_current', true))
                ->count();

            if ($employeesWithoutCard > 0) {
                $alerts[] = $this->alert('employeesWithoutIdCard', 'warning', $employeesWithoutCard, route('employees.index'));
            }

            $duplicateWarnings = $this->duplicateWarningsCount($scope);

            if ($duplicateWarnings > 0) {
                $alerts[] = $this->alert('duplicateRisk', 'warning', $duplicateWarnings, route('employees.index'));
            }
        }

        if ($can['transfers']) {
            $staleTransfers = $this->transferQuery($scope)
                ->whereIn('employee_transfers.status', [
                    TransferStatus::Submitted->value,
                    TransferStatus::CurrentOrganizationConfirmed->value,
                    TransferStatus::ReceivingOrganizationConfirmed->value,
                ])
                ->where('employee_transfers.created_at', '<=', now()->subDays(7))
                ->count();

            if ($staleTransfers > 0) {
                $alerts[] = $this->alert('staleTransferApprovals', 'critical', $staleTransfers, route('transfers.dashboard'));
            }
        }

        if ($can['audit']) {
            $verificationSpike = $this->verificationQuery($scope)
                ->where('card_verifications.allowed', false)
                ->where('card_verifications.created_at', '>=', now()->subDay())
                ->count();

            if ($verificationSpike > 10) {
                $alerts[] = $this->alert('verificationDenialSpike', 'critical', $verificationSpike, route('audit-logs.index'));
            }
        }

        if ($can['serviceFeedback']) {
            $lowRatingPending = $this->feedbackQuery($scope)
                ->where('employee_service_feedback.rating', '<=', 2)
                ->where('employee_service_feedback.status', ServiceFeedbackStatus::Pending->value)
                ->count();

            if ($lowRatingPending > 0) {
                $alerts[] = $this->alert('lowRatingFeedback', 'warning', $lowRatingPending, route('service-feedback.admin.dashboard'));
            }
        }

        return $alerts;
    }

    public function recentActivity(array $scope, bool $canAudit): array
    {
        if (! $canAudit) {
            return [];
        }

        $logs = AuditLog::query()
            ->when(
                ! $scope['global_access'] && $scope['organization_ids'] !== [],
                fn (Builder $query) => $query->whereIn('organization_id', $scope['organization_ids'])
            )
            ->when(
                ! $scope['global_access'] && $scope['organization_ids'] === [],
                fn (Builder $query) => $query->whereRaw('1 = 0')
            )
            ->orderByDesc('created_at')
            ->limit($scope['activity_limit'])
            ->get(['id', 'event_type', 'auditable_type', 'actor_user_id', 'created_at']);

        // Batch-resolve actor display names
        $actorIds = $logs->pluck('actor_user_id')->filter()->unique()->values();
        $actorNames = $actorIds->isNotEmpty()
            ? User::query()->whereIn('id', $actorIds)->pluck('name', 'id')
            : collect();

        return $logs->map(fn (AuditLog $log): array => [
            'id' => $log->id,
            'event' => $log->event_type->value,
            'actor' => $log->actor_user_id
                ? ($actorNames->get($log->actor_user_id) ?? $log->actor_user_id)
                : 'system',
            'subject' => $log->auditable_type ? class_basename($log->auditable_type) : null,
            'timestamp' => $log->created_at?->toIso8601String(),
            'severity' => $this->severityForAuditEvent($log->event_type->value),
        ])->all();
    }

    /**
     * @param  string|null  $routeName  Destination for click-through. Safe to
     *                                  emit unconditionally: a KPI only exists
     *                                  when its section permission was granted.
     * @param  string  $group  Where the card belongs on the page. `headline`
     *                         earns a place in the top strip; every other value
     *                         names the dashboard section that renders it
     *                         inline. See `HEADLINE_KPI_LIMIT`.
     */
    private function kpi(
        string $key,
        int|float $value,
        string $icon,
        string $tone,
        ?array $trend = null,
        string $suffix = '',
        ?string $routeName = null,
        string $group = 'headline',
    ): array {
        $formatted = $suffix !== ''
            ? number_format((float) $value, 2).$suffix
            : number_format((float) $value);

        /*
         * An empty queue is good news, so it must not wear a warning colour.
         *
         * Tone was previously fixed per metric — "Pending Card Requests" was
         * always amber, "Expired Cards" always red — which meant a perfectly
         * clear desk still lit up the dashboard with six alarm-coloured zeros
         * and taught the reader to ignore the colour entirely. Tone now
         * describes the value, not the category.
         */
        if (($tone === 'warning' || $tone === 'critical') && (float) $value === 0.0) {
            $tone = 'neutral';
        }

        return [
            'key' => $key,
            'labelKey' => "dashboard.kpis.{$key}",
            'value' => $value,
            'valueFormatted' => $formatted,
            'href' => $routeName !== null && Route::has($routeName) ? route($routeName) : null,
            'trend' => $trend['delta'] ?? null,
            'trendDirection' => $trend['direction'] ?? null,
            'comparisonLabelKey' => $trend !== null ? 'dashboard.previousPeriod' : null,
            'icon' => $icon,
            'tone' => $tone,
            'group' => $group,
        ];
    }

    private function queue(string $key, string $routeName, int $count, string $tone): array
    {
        return [
            'key' => $key,
            'labelKey' => "dashboard.workflow.{$key}",
            'count' => $count,
            'href' => route($routeName),
            'tone' => $tone,
        ];
    }

    private function alert(string $key, string $severity, int $count, string $href): array
    {
        return [
            'key' => $key,
            'titleKey' => "dashboard.alerts.{$key}.title",
            'descriptionKey' => "dashboard.alerts.{$key}.description",
            'severity' => $severity,
            'count' => $count,
            'href' => $href,
        ];
    }

    private function employeeTrend(array $scope, ?string $status = null): array
    {
        $current = $this->employeeTrendCount($scope, $scope['date_from'], $scope['date_to'], $status);
        $previous = $this->employeeTrendCount($scope, $scope['previous_from'], $scope['previous_to'], $status);

        return $this->trend($current, $previous);
    }

    private function cardTrend(array $scope, ?string $status = null): array
    {
        $current = $this->cardTrendCount($scope, $scope['date_from'], $scope['date_to'], $status);
        $previous = $this->cardTrendCount($scope, $scope['previous_from'], $scope['previous_to'], $status);

        return $this->trend($current, $previous);
    }

    private function trend(int $current, int $previous): array
    {
        $delta = $current - $previous;

        return [
            'delta' => $delta,
            'direction' => $delta === 0 ? 'flat' : ($delta > 0 ? 'up' : 'down'),
        ];
    }

    private function employeeTrendCount(array $scope, CarbonImmutable $from, CarbonImmutable $to, ?string $status = null): int
    {
        return $this->employeeQuery($scope)
            ->when($status !== null, fn (Builder $query) => $query->where('employees.status', $status))
            ->whereBetween('employees.created_at', [$from, $to])
            ->count();
    }

    private function cardTrendCount(array $scope, CarbonImmutable $from, CarbonImmutable $to, ?string $status = null): int
    {
        return $this->cardQuery($scope)
            ->when($status !== null, fn (Builder $query) => $query->where('id_cards.status', $status))
            ->whereBetween('id_cards.created_at', [$from, $to])
            ->count();
    }

    private function duplicateWarningsCount(array $scope): int
    {
        return EmployeeDuplicateFlag::query()
            ->join('employees', 'employees.id', '=', 'employee_duplicate_flags.employee_id')
            ->when(
                ! $scope['global_access'] && $scope['organization_ids'] !== [],
                function (Builder $query) use ($scope): void {
                    $query->join('employee_assignments as employee_duplicate_scope_assignment', 'employee_duplicate_scope_assignment.id', '=', 'employees.current_assignment_id')
                        ->whereIn('employee_duplicate_scope_assignment.organization_id', $scope['organization_ids']);
                }
            )
            ->when(
                ! $scope['global_access'] && $scope['organization_ids'] === [],
                fn (Builder $query) => $query->whereRaw('1 = 0')
            )
            ->count();
    }

    private function duplicateActiveCardWarnings(array $scope): int
    {
        return $this->cardQuery($scope)
            ->select('id_cards.employee_id')
            ->whereIn('id_cards.status', [CardStatus::Active->value, CardStatus::Issued->value])
            ->groupBy('id_cards.employee_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();
    }

    public function positionOccupancyCounts(array $scope): array
    {
        $total = $this->positionQuery($scope)->count();
        $occupied = $this->occupiedPositionsCount($scope);

        return [
            'occupied' => $occupied,
            'vacant' => max($total - $occupied, 0),
        ];
    }

    private function occupiedPositionsCount(array $scope): int
    {
        return $this->positionQuery($scope)
            ->whereHas('assignments', fn (Builder $query): Builder => $query
                ->where('is_current', true)
                ->where('assignment_status', AssignmentStatus::Active->value))
            ->count();
    }

    private function vacantPositionsCount(array $scope, ?int $totalPositions = null, ?int $occupiedPositions = null): int
    {
        $totalPositions ??= $this->positionQuery($scope)->count();
        $occupiedPositions ??= $this->occupiedPositionsCount($scope);

        return max($totalPositions - $occupiedPositions, 0);
    }

    private function expiringCardsCount(array $scope): int
    {
        return $this->cardQuery($scope)
            ->whereNotNull('id_cards.expires_at')
            ->whereBetween('id_cards.expires_at', [now(), now()->addDays(30)])
            ->count();
    }

    private function severityForAuditEvent(string $event): string
    {
        if (str_contains($event, 'rejected') || str_contains($event, 'denied')) {
            return 'warning';
        }

        if (str_contains($event, 'revoked') || str_contains($event, 'security')) {
            return 'critical';
        }

        return 'info';
    }

    public function employeeQuery(array $scope): Builder
    {
        return Employee::query()
            ->when(
                ! $scope['global_access'] && $scope['organization_ids'] !== [],
                function (Builder $query) use ($scope): void {
                    $query->join('employee_assignments as employee_scope_assignment', 'employee_scope_assignment.id', '=', 'employees.current_assignment_id')
                        ->whereIn('employee_scope_assignment.organization_id', $scope['organization_ids']);
                }
            )
            ->when(
                ! $scope['global_access'] && $scope['organization_ids'] === [],
                fn (Builder $query) => $query->whereRaw('1 = 0')
            );
    }

    public function organizationQuery(array $scope): Builder
    {
        return Organization::query()
            ->when(
                ! $scope['global_access'] && $scope['organization_ids'] !== [],
                fn (Builder $query) => $query->whereIn('organizations.id', $scope['organization_ids'])
            )
            ->when(
                ! $scope['global_access'] && $scope['organization_ids'] === [],
                fn (Builder $query) => $query->whereRaw('1 = 0')
            );
    }

    public function organizationUnitQuery(array $scope): Builder
    {
        return OrganizationUnit::query()
            ->when(
                ! $scope['global_access'] && $scope['organization_ids'] !== [],
                fn (Builder $query) => $query->whereIn('organization_units.organization_id', $scope['organization_ids'])
            )
            ->when(
                ! $scope['global_access'] && $scope['organization_ids'] === [],
                fn (Builder $query) => $query->whereRaw('1 = 0')
            );
    }

    public function positionQuery(array $scope): Builder
    {
        return Position::query()
            ->when(
                ! $scope['global_access'] && ! empty($scope['organization_ids']),
                fn (Builder $query) => $query->whereIn('positions.organization_id', $scope['organization_ids'])
            )
            ->when(
                ! $scope['global_access'] && empty($scope['organization_ids']),
                fn (Builder $query) => $query->whereRaw('1 = 0')
            );
    }

    public function cardQuery(array $scope): Builder
    {
        return IdCard::query()
            ->join('employees as card_employee', 'card_employee.id', '=', 'id_cards.employee_id')
            ->leftJoin('employee_assignments as card_scope_assignment', 'card_scope_assignment.id', '=', 'card_employee.current_assignment_id')
            ->when(
                ! $scope['global_access'] && ! $scope['provider_only'] && $scope['organization_ids'] !== [],
                fn (Builder $query) => $query->whereIn('card_scope_assignment.organization_id', $scope['organization_ids'])
            )
            ->when(
                ! $scope['global_access'] && ! $scope['provider_only'] && $scope['organization_ids'] === [],
                fn (Builder $query) => $query->whereRaw('1 = 0')
            );
    }

    /**
     * NFC credentials, scoped through the ID card they belong to so they obey
     * exactly the same organizational boundary as the cards themselves.
     */
    /**
     * Status buckets resolved with one grouped query per table instead of one
     * COUNT per status.
     *
     * The dashboard asks for the same buckets from several places (KPIs,
     * workflow queues, the lifecycle funnel), so the result is memoised for the
     * lifetime of the request. Keyed by scope, because the same service
     * instance answers for whichever organizations the viewer may see.
     *
     * @var array<string, array<string, int>>
     */
    private array $statusCounts = [];

    /** @return array<string, int> status => count */
    public function cardStatusCounts(array $scope): array
    {
        return $this->statusCounts['cards:'.$this->scopeKey($scope)] ??= $this->cardQuery($scope)
            ->toBase()
            ->selectRaw('id_cards.status as status_key, COUNT(*) as aggregate')
            ->groupBy('id_cards.status')
            ->pluck('aggregate', 'status_key')
            ->map(fn ($value): int => (int) $value)
            ->all();
    }

    /** @return array<string, int> status => count */
    public function cardRequestStatusCounts(array $scope): array
    {
        return $this->statusCounts['requests:'.$this->scopeKey($scope)] ??= $this->cardRequestQuery($scope)
            ->toBase()
            ->selectRaw('card_requests.status as status_key, COUNT(*) as aggregate')
            ->groupBy('card_requests.status')
            ->pluck('aggregate', 'status_key')
            ->map(fn ($value): int => (int) $value)
            ->all();
    }

    /** A status with no rows is a real zero, not missing data. */
    public function cardStatusCount(array $scope, string $status): int
    {
        return $this->cardStatusCounts($scope)[$status] ?? 0;
    }

    /** Sums one or more request statuses from the memoised map. */
    public function cardRequestStatusCount(array $scope, string ...$statuses): int
    {
        $counts = $this->cardRequestStatusCounts($scope);
        $total = 0;

        foreach ($statuses as $status) {
            $total += $counts[$status] ?? 0;
        }

        return $total;
    }

    private function scopeKey(array $scope): string
    {
        return md5(json_encode([
            $scope['global_access'],
            $scope['provider_only'],
            $scope['organization_ids'],
        ], JSON_THROW_ON_ERROR));
    }

    public function nfcCredentialQuery(array $scope): Builder
    {
        return NfcCredential::query()
            ->join('id_cards as nfc_card', 'nfc_card.id', '=', 'nfc_credentials.id_card_id')
            ->join('employees as nfc_employee', 'nfc_employee.id', '=', 'nfc_card.employee_id')
            ->leftJoin('employee_assignments as nfc_scope_assignment', 'nfc_scope_assignment.id', '=', 'nfc_employee.current_assignment_id')
            ->when(
                ! $scope['global_access'] && ! $scope['provider_only'] && $scope['organization_ids'] !== [],
                fn (Builder $query) => $query->whereIn('nfc_scope_assignment.organization_id', $scope['organization_ids'])
            )
            ->when(
                ! $scope['global_access'] && ! $scope['provider_only'] && $scope['organization_ids'] === [],
                fn (Builder $query) => $query->whereRaw('1 = 0')
            );
    }

    /** Terminals carry their own organization, so they scope directly. */
    public function serviceTerminalQuery(array $scope): Builder
    {
        return ServiceTerminal::query()
            ->when(
                ! $scope['global_access'] && $scope['organization_ids'] !== [],
                fn (Builder $query) => $query->whereIn('service_terminals.organization_id', $scope['organization_ids'])
            )
            ->when(
                ! $scope['global_access'] && $scope['organization_ids'] === [],
                fn (Builder $query) => $query->whereRaw('1 = 0')
            );
    }

    public function cardRequestQuery(array $scope): Builder
    {
        return CardRequest::query()
            ->join('employees as card_request_employee', 'card_request_employee.id', '=', 'card_requests.employee_id')
            ->leftJoin('employee_assignments as card_request_scope_assignment', 'card_request_scope_assignment.id', '=', 'card_request_employee.current_assignment_id')
            ->when(
                ! $scope['global_access'] && ! $scope['provider_only'] && $scope['organization_ids'] !== [],
                fn (Builder $query) => $query->whereIn('card_request_scope_assignment.organization_id', $scope['organization_ids'])
            )
            ->when(
                ! $scope['global_access'] && ! $scope['provider_only'] && $scope['organization_ids'] === [],
                fn (Builder $query) => $query->whereRaw('1 = 0')
            );
    }

    public function entitlementQuery(array $scope): Builder
    {
        return Entitlement::query()
            ->join('employees as entitlement_employee', 'entitlement_employee.id', '=', 'entitlements.employee_id')
            ->leftJoin('employee_assignments as entitlement_scope_assignment', 'entitlement_scope_assignment.id', '=', 'entitlement_employee.current_assignment_id')
            ->when(
                ! $scope['global_access'] && ! $scope['provider_only'] && $scope['organization_ids'] !== [],
                fn (Builder $query) => $query->whereIn('entitlement_scope_assignment.organization_id', $scope['organization_ids'])
            )
            ->when(
                ! $scope['global_access'] && ! $scope['provider_only'] && $scope['organization_ids'] === [],
                fn (Builder $query) => $query->whereRaw('1 = 0')
            )
            ->when(
                $scope['provider_only'] && $scope['provider_ids'] === [],
                fn (Builder $query) => $query->whereRaw('1 = 0')
            )
            ->when($scope['provider_ids'] !== [], fn (Builder $query) => $query->whereIn('entitlements.service_provider_id', $scope['provider_ids']))
            ->when($scope['service_type_ids'] !== [], fn (Builder $query) => $query->whereIn('entitlements.service_type_id', $scope['service_type_ids']));
    }

    public function transactionQuery(array $scope): Builder
    {
        return ServiceTransaction::query()
            ->leftJoin('employees as service_transaction_employee', 'service_transaction_employee.id', '=', 'service_transactions.employee_id')
            ->leftJoin('employee_assignments as service_transaction_scope_assignment', 'service_transaction_scope_assignment.id', '=', 'service_transaction_employee.current_assignment_id')
            ->when(
                ! $scope['global_access'] && ! $scope['provider_only'] && $scope['organization_ids'] !== [],
                fn (Builder $query) => $query->whereIn('service_transaction_scope_assignment.organization_id', $scope['organization_ids'])
            )
            ->when(
                ! $scope['global_access'] && ! $scope['provider_only'] && $scope['organization_ids'] === [],
                fn (Builder $query) => $query->whereRaw('1 = 0')
            )
            ->when(
                $scope['provider_only'] && $scope['provider_ids'] === [],
                fn (Builder $query) => $query->whereRaw('1 = 0')
            )
            ->when($scope['provider_ids'] !== [], fn (Builder $query) => $query->whereIn('service_transactions.service_provider_id', $scope['provider_ids']))
            ->when($scope['service_type_ids'] !== [], fn (Builder $query) => $query->whereIn('service_transactions.service_type_id', $scope['service_type_ids']));
    }

    public function verificationQuery(array $scope): Builder
    {
        return CardVerification::query()
            ->leftJoin('service_providers as verification_provider', 'verification_provider.id', '=', 'card_verifications.service_provider_id')
            ->leftJoin('id_cards as verification_card', 'verification_card.id', '=', 'card_verifications.id_card_id')
            ->leftJoin('employees as verification_employee', 'verification_employee.id', '=', 'verification_card.employee_id')
            ->leftJoin('employee_assignments as verification_scope_assignment', 'verification_scope_assignment.id', '=', 'verification_employee.current_assignment_id')
            ->when(
                ! $scope['global_access'] && ! $scope['provider_only'] && $scope['organization_ids'] !== [],
                fn (Builder $query) => $query->whereIn('verification_scope_assignment.organization_id', $scope['organization_ids'])
            )
            ->when(
                ! $scope['global_access'] && ! $scope['provider_only'] && $scope['organization_ids'] === [],
                fn (Builder $query) => $query->whereRaw('1 = 0')
            )
            ->when(
                $scope['provider_only'] && $scope['provider_ids'] === [],
                fn (Builder $query) => $query->whereRaw('1 = 0')
            )
            ->when($scope['provider_ids'] !== [], fn (Builder $query) => $query->whereIn('card_verifications.service_provider_id', $scope['provider_ids']))
            ->when($scope['service_type_ids'] !== [], fn (Builder $query) => $query->whereIn('card_verifications.service_type_id', $scope['service_type_ids']));
    }

    public function providerQuery(array $scope): Builder
    {
        return ServiceProvider::query()
            ->when(
                ! $scope['global_access'] && ! $scope['provider_only'] && $scope['organization_ids'] !== [],
                fn (Builder $query) => $query->whereIn('service_providers.organization_id', $scope['organization_ids'])
            )
            ->when(
                ! $scope['global_access'] && ! $scope['provider_only'] && $scope['organization_ids'] === [],
                fn (Builder $query) => $query->whereRaw('1 = 0')
            )
            ->when(
                $scope['provider_only'] && $scope['provider_ids'] === [],
                fn (Builder $query) => $query->whereRaw('1 = 0')
            )
            ->when($scope['provider_ids'] !== [], fn (Builder $query) => $query->whereIn('service_providers.id', $scope['provider_ids']))
            ->when($scope['service_type_ids'] !== [], fn (Builder $query) => $query->whereIn('service_providers.service_type_id', $scope['service_type_ids']));
    }

    public function feedbackQuery(array $scope): Builder
    {
        return EmployeeServiceFeedback::query()
            ->when(
                ! $scope['global_access'] && ! $scope['provider_only'] && $scope['organization_ids'] !== [],
                fn (Builder $query) => $query->whereIn('employee_service_feedback.organization_id', $scope['organization_ids'])
            )
            ->when(
                ! $scope['global_access'] && ! $scope['provider_only'] && $scope['organization_ids'] === [],
                fn (Builder $query) => $query->whereRaw('1 = 0')
            )
            ->when(
                $scope['provider_only'],
                fn (Builder $query) => $query->whereRaw('1 = 0')
            );
    }

    public function transferQuery(array $scope): Builder
    {
        return EmployeeTransfer::query()
            ->when(
                ! $scope['global_access'] && $scope['organization_ids'] !== [],
                function (Builder $query) use ($scope): void {
                    $query->where(function (Builder $nested) use ($scope): void {
                        $nested->whereIn('employee_transfers.from_organization_id', $scope['organization_ids'])
                            ->orWhereIn('employee_transfers.to_organization_id', $scope['organization_ids']);
                    });
                }
            )
            ->when(
                ! $scope['global_access'] && $scope['organization_ids'] === [],
                fn (Builder $query) => $query->whereRaw('1 = 0')
            );
    }
}
