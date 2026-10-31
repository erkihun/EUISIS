<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ServiceMonitoring\Application\Actions\AddFinding;
use Modules\ServiceMonitoring\Application\Actions\CreateActionItem;
use Modules\ServiceMonitoring\Application\Actions\CreateVisit;
use Modules\ServiceMonitoring\Application\Actions\UpdateVisit;
use Modules\ServiceMonitoring\Application\Services\ActionItemPresenter;
use Modules\ServiceMonitoring\Application\Services\FiscalCalendar;
use Modules\ServiceMonitoring\Application\Services\PeriodDirectory;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAccess;
use Modules\ServiceMonitoring\Domain\Enums\FindingSeverity;
use Modules\ServiceMonitoring\Domain\Models\ActionItem;
use Modules\ServiceMonitoring\Domain\Models\MonitoringVisit;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Http\Requests\AddFindingRequest;
use Modules\ServiceMonitoring\Http\Requests\CreateActionItemRequest;
use Modules\ServiceMonitoring\Http\Requests\SaveVisitRequest;

/** Monitoring visits with their team, findings and action items (D22). */
final class VisitController extends Controller
{
    public function __construct(
        private readonly ServiceMonitoringAccess $access,
        private readonly FiscalCalendar $calendar,
        private readonly ActionItemPresenter $items,
    ) {}

    public function index(Request $request, PeriodDirectory $periods): Response
    {
        $this->authorize('viewAny', Participant::class);
        $user = $request->user();
        $visits = MonitoringVisit::query()
            ->whereHas('participant', fn ($q) => $this->access->scopeParticipants($q, $user))
            ->with('participant.organization:id,name_en,name_am')->withCount(['findings', 'actionItems'])
            ->orderByDesc('visited_on')->paginate(30)->withQueryString();
        $manageable = $this->access->scopeParticipants(Participant::query(), $user)->whereNull('active_to')->with('organization:id,name_en,name_am')->get()
            ->filter(fn (Participant $p): bool => $this->access->canManageVisits($user, $p))->values();

        return Inertia::render('ServiceMonitoring/Visits/Index', [
            'visits' => $visits->through(fn (MonitoringVisit $v): array => [
                'id' => $v->id, 'visited_on' => $v->visited_on->toDateString(), 'purpose' => $v->purpose, 'status' => $v->status->value,
                'findings' => $v->findings_count, 'action_items' => $v->action_items_count,
                'organization' => ['name_en' => $v->participant?->organization?->name_en, 'name_am' => $v->participant?->organization?->name_am],
            ]),
            'participants' => $manageable->map(fn (Participant $p): array => ['id' => $p->id, 'name_en' => $p->organization?->name_en, 'name_am' => $p->organization?->name_am])->all(),
            'years' => $periods->years(),
            'team_candidates' => $manageable->isEmpty() ? [] : $this->teamCandidates(),
        ]);
    }

    public function store(SaveVisitRequest $request, CreateVisit $create): RedirectResponse
    {
        $participant = Participant::query()->findOrFail($request->validated('participant_id'));
        $visit = $create->execute($participant, $request->validated(), $request->user());

        return redirect()->route('service-monitoring.visits.show', $visit)->with('success', __('service-monitoring.flash.visit_saved'));
    }

    public function show(Request $request, MonitoringVisit $visit): Response
    {
        $this->authorize('view', $visit);
        $visit->load(['participant.organization:id,name_en,name_am', 'members:id,name', 'findings', 'actionItems.ownerOrganization:id,name_en,name_am', 'actionItems.ownerUser:id,name', 'actionItems.evidence', 'period.fiscalYear']);
        $user = $request->user();
        $today = $this->calendar->today();

        return Inertia::render('ServiceMonitoring/Visits/Show', [
            'visit' => [
                'id' => $visit->id, 'visited_on' => $visit->visited_on->toDateString(), 'purpose' => $visit->purpose, 'summary' => $visit->summary,
                'status' => $visit->status->value, 'period_id' => $visit->period_id, 'period_label' => $visit->period?->label(),
                'organization' => ['id' => $visit->participant?->organization_id, 'name_en' => $visit->participant?->organization?->name_en, 'name_am' => $visit->participant?->organization?->name_am],
                'members' => $visit->members->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name])->values()->all(),
                'findings' => $visit->findings->map(fn ($f): array => ['id' => $f->id, 'section' => $f->section, 'body' => $f->body, 'severity' => $f->severity->value])->values()->all(),
                'action_items' => $visit->actionItems->map(fn (ActionItem $i): array => $this->items->present($i, $user, $today))->values()->all(),
            ],
            'severities' => FindingSeverity::values(),
            'team_candidates' => $user->can('update', $visit) ? $this->teamCandidates() : [],
            'can' => ['update' => $user->can('update', $visit)],
        ]);
    }

    public function update(SaveVisitRequest $request, MonitoringVisit $visit, UpdateVisit $update): RedirectResponse
    {
        $update->execute($visit, $request->validated(), $request->user());

        return back()->with('success', __('service-monitoring.flash.visit_saved'));
    }

    public function addFinding(AddFindingRequest $request, MonitoringVisit $visit, AddFinding $add): RedirectResponse
    {
        $section = $request->validated('section');
        $add->execute($visit, $section === null ? null : (int) $section, (string) $request->validated('body'), FindingSeverity::from((string) $request->validated('severity')), $request->user());

        return back()->with('success', __('service-monitoring.flash.finding_added'));
    }

    public function addActionItem(CreateActionItemRequest $request, MonitoringVisit $visit, CreateActionItem $create): RedirectResponse
    {
        $create->execute($visit, $request->validated(), $request->user());

        return back()->with('success', __('service-monitoring.flash.action_item_saved'));
    }

    /** @return list<array{id: int, name: string}> */
    private function teamCandidates(): array
    {
        return User::query()->where('status', 'active')
            ->whereHas('roles.permissions', fn ($q) => $q->whereIn('name', ['service_monitoring.visits.manage', 'service_monitoring.support.respond']))
            ->orderBy('name')->limit(300)->get(['id', 'name'])->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name])->all();
    }
}
