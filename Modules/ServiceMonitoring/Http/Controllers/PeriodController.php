<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ServiceMonitoring\Application\Actions\CreateFiscalYear;
use Modules\ServiceMonitoring\Application\Actions\GrantExtension;
use Modules\ServiceMonitoring\Application\Actions\LockPeriod;
use Modules\ServiceMonitoring\Application\Actions\ReopenPeriod;
use Modules\ServiceMonitoring\Application\Actions\RevokeExtension;
use Modules\ServiceMonitoring\Application\Actions\UpdatePeriodSchedule;
use Modules\ServiceMonitoring\Application\Services\ComplianceService;
use Modules\ServiceMonitoring\Application\Services\ParticipantResolver;
use Modules\ServiceMonitoring\Application\Services\PeriodDirectory;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAccess;
use Modules\ServiceMonitoring\Domain\Models\Certification;
use Modules\ServiceMonitoring\Domain\Models\DeadlineExtension;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;
use Modules\ServiceMonitoring\Http\Requests\GrantExtensionRequest;
use Modules\ServiceMonitoring\Http\Requests\ReasonRequest;
use Modules\ServiceMonitoring\Http\Requests\StoreFiscalYearRequest;
use Modules\ServiceMonitoring\Http\Requests\UpdatePeriodScheduleRequest;

/** Fiscal years, period schedules, compliance per period, extensions, lock and reopen. */
final class PeriodController extends Controller
{
    public function __construct(
        private readonly PeriodDirectory $periods,
        private readonly ParticipantResolver $participants,
        private readonly ComplianceService $compliance,
        private readonly ServiceMonitoringAccess $access,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ReportingPeriod::class);

        return Inertia::render('ServiceMonitoring/Periods/Index', [
            'years' => $this->periods->years(),
            'can' => ['manage' => $this->access->canManagePeriods($request->user())],
        ]);
    }

    public function show(Request $request, ReportingPeriod $period): Response
    {
        $this->authorize('view', $period);
        $user = $request->user();
        $period->load('fiscalYear');
        $expected = $this->participants->visibleExpectedFor($period, $user)->with(['organization:id,name_en,name_am', 'parent.organization:id,name_en,name_am'])->get();
        $status = $this->compliance->forPeriod($period, $expected);
        $extensions = DeadlineExtension::query()->where('period_id', $period->id)->whereIn('participant_id', $expected->pluck('id'))->with('grantedBy:id,name')->latest()->get();
        $certified = Certification::query()->where('period_id', $period->id)->pluck('certified_at', 'participant_id');

        return Inertia::render('ServiceMonitoring/Periods/Show', [
            'period' => $this->periods->period($period) + ['ec_year' => $period->fiscalYear->ec_year],
            'rows' => $expected->map(fn (Participant $p): array => [
                'participant_id' => $p->id,
                'level' => $p->level->value,
                'organization' => ['id' => $p->organization_id, 'name_en' => $p->organization?->name_en, 'name_am' => $p->organization?->name_am],
                'parent' => $p->parent ? ['name_en' => $p->parent->organization?->name_en, 'name_am' => $p->parent->organization?->name_am] : null,
                'compliance' => $status[$p->id],
                'certified_at' => isset($certified[$p->id]) ? CarbonImmutable::parse($certified[$p->id])->toIso8601String() : null,
                'can_extend' => $this->access->canGrantExtension($user, $p),
            ])->sortBy(fn (array $r): string => $r['level'].($r['organization']['name_en'] ?? ''))->values()->all(),
            'extensions' => $extensions->map(fn (DeadlineExtension $e): array => [
                'id' => $e->id, 'participant_id' => $e->participant_id, 'due_at' => $e->due_at->toIso8601String(), 'reason' => $e->reason,
                'granted_by' => $e->grantedBy?->name, 'revoked_at' => $e->revoked_at?->toIso8601String(), 'active' => $e->isActive(),
            ])->all(),
            'tally' => ComplianceService::tally($status),
            'can' => [
                'manage' => $this->access->canManagePeriods($user),
                'lock' => $user->can('lock', $period),
                'reopen' => $user->can('reopen', $period),
            ],
        ]);
    }

    public function storeFiscalYear(StoreFiscalYearRequest $request, CreateFiscalYear $create): RedirectResponse
    {
        $year = $create->execute((int) $request->validated('ec_year'), $request->user());

        return back()->with('success', __('service-monitoring.flash.fiscal_year_created', ['year' => $year->ec_year]));
    }

    public function updateSchedule(UpdatePeriodScheduleRequest $request, ReportingPeriod $period, UpdatePeriodSchedule $update): RedirectResponse
    {
        $update->execute($period, $request->validated(), $request->user());

        return back()->with('success', __('service-monitoring.flash.schedule_updated'));
    }

    public function lock(Request $request, ReportingPeriod $period, LockPeriod $lock): RedirectResponse
    {
        $this->authorize('lock', $period);
        $lock->execute($period, $request->user());

        return back()->with('success', __('service-monitoring.flash.locked'));
    }

    public function reopen(ReasonRequest $request, ReportingPeriod $period, ReopenPeriod $reopen): RedirectResponse
    {
        $this->authorize('reopen', $period);
        $reopen->execute($period, $request->user(), (string) $request->validated('reason'));

        return back()->with('success', __('service-monitoring.flash.reopened'));
    }

    public function grantExtension(GrantExtensionRequest $request, ReportingPeriod $period, GrantExtension $grant): RedirectResponse
    {
        $participant = Participant::query()->findOrFail($request->validated('participant_id'));
        $dueAt = CarbonImmutable::parse((string) $request->validated('due_at'), ReportingPeriod::TIMEZONE);
        $grant->execute($period, $participant, $dueAt, (string) $request->validated('reason'), $request->user());

        return back()->with('success', __('service-monitoring.flash.extension_granted'));
    }

    public function revokeExtension(ReasonRequest $request, DeadlineExtension $extension, RevokeExtension $revoke): RedirectResponse
    {
        $this->authorize('grantExtension', $extension->participant);
        $revoke->execute($extension, $request->user(), (string) $request->validated('reason'));

        return back()->with('success', __('service-monitoring.flash.extension_revoked'));
    }
}
