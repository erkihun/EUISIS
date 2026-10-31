<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ServiceMonitoring\Application\Services\ComplianceService;
use Modules\ServiceMonitoring\Application\Services\FiscalCalendar;
use Modules\ServiceMonitoring\Application\Services\ParticipantResolver;
use Modules\ServiceMonitoring\Application\Services\PeriodDirectory;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAccess;
use Modules\ServiceMonitoring\Domain\Enums\ClarificationStatus;
use Modules\ServiceMonitoring\Domain\Models\ActionItem;
use Modules\ServiceMonitoring\Domain\Models\Clarification;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;

/**
 * The module's home: the current period, the user's own reporting status,
 * and (deferred, scoped) compliance, overdue action items and open
 * clarifications.
 */
final class DashboardController extends Controller
{
    public function __construct(
        private readonly PeriodDirectory $periods,
        private readonly ParticipantResolver $participants,
        private readonly ComplianceService $compliance,
        private readonly ServiceMonitoringAccess $access,
        private readonly FiscalCalendar $calendar,
    ) {}

    public function __invoke(Request $request): Response
    {
        $this->authorize('viewAny', ReportingPeriod::class);
        $user = $request->user();
        $period = $this->periods->current();
        $mine = $period === null ? collect() : $this->participants->reportingFor($user, $period->ends_on->toDateString());
        $mineStatus = $period === null ? [] : $this->compliance->forPeriod($period, $mine);

        return Inertia::render('ServiceMonitoring/Dashboard', [
            'period' => $period === null ? null : $this->periods->period($period),
            'mine' => $mine->map(fn (Participant $p): array => [
                'participant_id' => $p->id,
                'level' => $p->level->value,
                'organization' => ['id' => $p->organization_id, 'name_en' => $p->organization?->name_en, 'name_am' => $p->organization?->name_am],
                'compliance' => $mineStatus[$p->id] ?? null,
                'can_start' => $user->can('start', $p) && $period !== null && ! $period->isLocked() && $this->calendar->today() >= $period->opens_on->toDateString(),
            ])->values()->all(),
            'compliance' => Inertia::defer(function () use ($period, $user): ?array {
                if ($period === null) {
                    return null;
                }
                $expected = $this->participants->visibleExpectedFor($period, $user)->get();

                return ['tally' => ComplianceService::tally($this->compliance->forPeriod($period, $expected)), 'participants' => $expected->count()];
            }, 'figures'),
            'overdue' => Inertia::defer(fn (): array => $this->overdue($user), 'figures'),
            'clarifications' => Inertia::defer(fn (): array => $this->clarifications($user), 'figures'),
            'can' => [
                'manage_periods' => $this->access->canManagePeriods($user),
                'review' => $user->can('service_monitoring.review'),
                'verify' => $this->access->canVerifyTechnology($user),
                'reports' => $this->access->canViewReports($user),
            ],
        ]);
    }

    /** @return array{count: int, items: list<array<string, mixed>>} */
    private function overdue($user): array
    {
        $query = ActionItem::query()->overdue($this->calendar->today())
            ->whereHas('visit.participant', fn ($q) => $this->access->scopeParticipants($q, $user));

        return [
            'count' => (clone $query)->count(),
            'items' => $query->with('ownerOrganization:id,name_en,name_am')->orderBy('due_on')->limit(10)->get()->map(fn (ActionItem $item): array => [
                'id' => $item->id, 'title' => $item->title, 'due_on' => $item->due_on->toDateString(), 'status' => $item->status->value,
                'owner' => ['name_en' => $item->ownerOrganization?->name_en, 'name_am' => $item->ownerOrganization?->name_am],
            ])->all(),
        ];
    }

    /** @return array{count: int, items: list<array<string, mixed>>} */
    private function clarifications($user): array
    {
        $query = Clarification::query()->whereIn('status', [ClarificationStatus::Open->value, ClarificationStatus::Answered->value])
            ->whereHas('participant', fn ($q) => $this->access->scopeParticipants($q, $user));

        return [
            'count' => (clone $query)->count(),
            'items' => $query->with('participant.organization:id,name_en,name_am')->latest()->limit(10)->get()->map(fn (Clarification $c): array => [
                'id' => $c->id, 'subject' => $c->subject, 'status' => $c->status->value, 'created_at' => $c->created_at?->toIso8601String(),
                'organization' => ['name_en' => $c->participant?->organization?->name_en, 'name_am' => $c->participant?->organization?->name_am],
            ])->all(),
        ];
    }
}
