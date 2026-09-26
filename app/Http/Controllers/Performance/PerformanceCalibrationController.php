<?php

declare(strict_types=1);

namespace App\Http\Controllers\Performance;

use App\Enums\CommitteeType;
use App\Enums\Performance\ResultStatus;
use App\Models\GrievanceCommittee;
use App\Models\Organization;
use App\Models\PerformanceCalibrationItem;
use App\Models\PerformanceCalibrationSession;
use App\Models\PerformanceCycle;
use App\Models\PerformanceResult;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\Performance\EpmsAccess;
use App\Services\Performance\PerformanceCalibrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PerformanceCalibrationController extends PerformanceController
{
    public function __construct(
        private readonly PerformanceCalibrationService $calibration,
        private readonly OrganizationScopeService $scope,
        private readonly EpmsAccess $access,
    ) {}

    public function index(Request $request): Response
    {
        $this->ensureEnabled();
        $user = $request->user();
        abort_unless($user->can('performance_calibration.view'), 403);

        return Inertia::render('Performance/Calibration/Index', [
            'sessions' => $this->scope->applyOrganizationScope(PerformanceCalibrationSession::query(), $user)
                ->with(['cycle:id,name_en,name_am', 'organization:id,name_en,name_am'])->withCount('items')->latest()->paginate(20)
                ->through(fn ($s) => ['id' => $s->getKey(), 'title' => $s->title, 'status' => $s->status->value, 'session_date' => $s->session_date?->toDateString(),
                    'items_count' => $s->items_count, 'cycle' => $s->cycle?->only(['name_en', 'name_am']), 'organization' => $s->organization?->only(['name_en', 'name_am'])]),
            'cycles' => PerformanceCycle::query()->whereIn('status', ['YEAR_END_REVIEW', 'CALIBRATION'])
                ->where(fn ($q) => $q->whereNull('organization_id')->orWhereIn('organization_id', $this->scope->allowedOrganizationIds($user)))
                ->get(['id', 'name_en', 'name_am'])->toArray(),
            'organizations' => $this->scope->applyOrganizationScope(Organization::query(), $user, 'id')->orderBy('name_en')->limit(200)->get(['id', 'name_en', 'name_am'])->toArray(),
            'committees' => $this->scope->applyOrganizationScope(GrievanceCommittee::query(), $user)
                ->where('committee_type', CommitteeType::PerformanceCalibration->value)->get(['id', 'name_en', 'name_am', 'organization_id'])->toArray(),
            'can' => ['manage' => $user->can('performance_calibration.manage')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cycle_id' => ['required', 'uuid', 'exists:performance_cycles,id'], 'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'organization_unit_id' => ['nullable', 'uuid', 'exists:organization_units,id'], 'committee_id' => ['nullable', 'uuid'],
            'title' => ['required', 'string', 'max:255'], 'session_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $session = $this->calibration->createSession(PerformanceCycle::query()->findOrFail($data['cycle_id']), $data, $request->user());

        return to_route('performance.calibration.show', $session);
    }

    public function show(Request $request, PerformanceCalibrationSession $session): Response
    {
        $user = $request->user();
        $member = $this->access->isCommitteeMember($user, $session->committee);
        abort_unless($member || $this->access->inScope($user, 'performance_calibration.view', $session->organization_id), 403);

        $candidates = PerformanceResult::query()->where('cycle_id', $session->cycle_id)->where('organization_id', $session->organization_id)
            ->when($session->organization_unit_id, fn ($q, $u) => $q->where('organization_unit_id', $u))
            ->where('is_current', true)->whereIn('status', [ResultStatus::Calculated->value, ResultStatus::PendingCalibration->value])
            ->whereNotIn('id', $session->items()->select('result_id'))->with('employee:id,full_name,name_en,employee_number')->limit(200)->get();

        return Inertia::render('Performance/Calibration/Show', [
            'session' => ['id' => $session->getKey(), 'title' => $session->title, 'status' => $session->status->value, 'session_date' => $session->session_date?->toDateString(),
                'committee' => $session->committee ? ['name_en' => $session->committee->name_en, 'name_am' => $session->committee->name_am] : null],
            'items' => $session->items()->with('employee:id,full_name,name_en,employee_number')->get()->map(fn (PerformanceCalibrationItem $i) => [
                'id' => $i->getKey(), 'employee' => ['name' => $i->employee?->full_name, 'name_en' => $i->employee?->name_en, 'number' => $i->employee?->employee_number],
                'manager_score' => $i->manager_score, 'proposed_score' => $i->proposed_score, 'calibrated_score' => $i->calibrated_score, 'reason' => $i->reason, 'decided_at' => $i->decided_at?->toIso8601String(),
            ])->all(),
            'candidates' => $candidates->map(fn ($r) => ['id' => $r->getKey(), 'employee' => $r->employee?->full_name, 'employee_en' => $r->employee?->name_en, 'final_score' => $r->final_score, 'rating_en' => $r->rating_label_en, 'rating_am' => $r->rating_label_am])->all(),
            'can' => [
                'manage' => $this->access->inScope($user, 'performance_calibration.manage', $session->organization_id),
                'decide' => $user->can('performance_calibration.manage') && ($member || $session->committee_id === null),
                'finalize' => $this->access->inScope($user, 'performance_calibration.finalize', $session->organization_id),
            ],
        ]);
    }

    public function addResults(Request $request, PerformanceCalibrationSession $session): RedirectResponse
    {
        $data = $request->validate(['result_ids' => ['required', 'array', 'min:1', 'max:500'], 'result_ids.*' => ['uuid']]);
        $this->calibration->addResults($session, $data['result_ids'], $request->user());

        return $this->saved();
    }

    public function decide(Request $request, PerformanceCalibrationItem $item): RedirectResponse
    {
        $data = $request->validate(['calibrated_score' => ['required', 'numeric', 'min:0', 'max:200'], 'reason' => ['required', 'string', 'max:2000']]);
        $this->calibration->decide($item, (string) $data['calibrated_score'], $data['reason'], $request->user());

        return $this->saved();
    }

    public function finalize(Request $request, PerformanceCalibrationSession $session): RedirectResponse
    {
        $this->calibration->finalize($session, $request->user());

        return $this->saved();
    }
}
