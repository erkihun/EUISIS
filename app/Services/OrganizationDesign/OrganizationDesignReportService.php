<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign;

use App\Enums\OrganizationDesignStatus;
use App\Models\Competency;
use App\Models\EmployeeAssignment;
use App\Models\InstitutionMandate;
use App\Models\JobDescription;
use App\Models\ManpowerPlan;
use App\Models\ManpowerPlanLine;
use App\Models\Occupation;
use App\Models\Organization;
use App\Models\OrganizationFunction;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PositionCompetencyRequirement;
use App\Models\PositionEstablishment;
use App\Models\PositionProfile;
use App\Models\PositionQualificationRequirement;
use App\Models\PositionRequirement;
use App\Models\PositionServiceTaskStandard;
use App\Models\PositionWorkLink;
use App\Models\Process;
use App\Models\ProcessActivity;
use App\Models\ProcessTask;
use App\Models\Service;
use App\Models\UnitResponsibility;
use App\Models\User;
use App\Models\WorkCompetencyRequirement;
use App\Models\WorkOccupationRequirement;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Support\Collection;

/**
 * Tabular reports over the design (brief §88, 98–99, 104–105), every one of
 * them cut to the organizations the user may see. Rows are plain arrays so
 * the page and the export share them.
 */
class OrganizationDesignReportService
{
    public const REPORTS = [
        'coverage', 'traceability', 'gaps', 'position_readiness', 'workforce',
        'service_catalog', 'function_service_matrix', 'service_process_matrix', 'process_activity_matrix', 'activity_standards',
        'occupation_demand', 'competency_demand', 'responsibility_matrix', 'fte_vs_headcount', 'position_requirements', 'vacancy', 'jd_register',
    ];

    /** The most organizations one run covers; the organization filter narrows it. */
    public const MAX_ORGANIZATIONS = 200;

    /** @var array<string, list<string>> */
    public const COLUMNS = [
        'coverage' => ['organization_code', 'organization_name', 'mandates', 'functions', 'services', 'processes', 'activities', 'tasks', 'tasks_with_standard', 'units', 'units_with_responsibilities', 'positions', 'positions_linked', 'positions_with_profile', 'positions_with_job_description', 'manpower_plans_approved'],
        'traceability' => ['organization_code', 'mandate_code', 'function_code', 'service_code', 'process_code', 'activity_code', 'task_code', 'task_name', 'positions', 'job_descriptions'],
        'gaps' => ['organization_code', 'severity', 'code', 'subject_type', 'subject_code', 'subject_name', 'message'],
        'position_readiness' => ['organization_code', 'position_code', 'position_title', 'unit_code', 'occupation', 'work_links', 'competencies', 'qualifications', 'profile_status', 'job_description_status', 'job_description_reference'],
        'workforce' => ['organization_code', 'reference_number', 'title', 'status', 'period_start', 'period_end', 'methodology', 'lines', 'calculated_fte', 'headcount', 'approved_by'],
        'service_catalog' => ['organization_code', 'service_code', 'service_name', 'classification_code', 'cpc_code', 'beneficiary_type', 'delivery_channel', 'owner_unit_code', 'functions', 'processes', 'status', 'effective_from'],
        'function_service_matrix' => ['organization_code', 'mandate_code', 'function_code', 'function_name', 'service_code', 'service_name', 'service_status'],
        'service_process_matrix' => ['organization_code', 'service_code', 'service_name', 'process_code', 'process_name', 'process_type', 'activities', 'tasks', 'status'],
        'process_activity_matrix' => ['organization_code', 'process_code', 'activity_code', 'activity_name', 'tasks', 'standards_in_force', 'occupations_required', 'competencies_required'],
        'activity_standards' => ['organization_code', 'process_code', 'subject_type', 'subject_code', 'subject_name', 'version_no', 'planned_quantity', 'quantity_unit', 'planned_time_minutes', 'planned_quality', 'quality_unit', 'effective_from', 'effective_to'],
        'occupation_demand' => ['organization_code', 'occupation_code', 'occupation_name', 'tasks_requiring', 'positions', 'approved_headcount', 'current_employees'],
        'competency_demand' => ['organization_code', 'competency_code', 'competency_name', 'tasks_requiring', 'positions_requiring', 'mandatory_positions'],
        'responsibility_matrix' => ['organization_code', 'unit_code', 'unit_name', 'object_type', 'object_code', 'object_name', 'responsibility_type'],
        'fte_vs_headcount' => ['organization_code', 'reference_number', 'unit_code', 'occupation', 'calculated_fte', 'recommended_headcount', 'approved_headcount', 'implemented_positions', 'current_employees'],
        'position_requirements' => ['organization_code', 'reference_number', 'unit_code', 'occupation', 'profession', 'position_title', 'required_fte', 'approved_headcount', 'status', 'position_code', 'establishment_number'],
        'vacancy' => ['organization_code', 'position_code', 'position_title', 'unit_code', 'approved_slots', 'current_employees', 'vacancies'],
        'jd_register' => ['organization_code', 'reference_number', 'position_code', 'position_title', 'version_no', 'status', 'effective_from', 'effective_to', 'prepared_by', 'approved_by', 'published_by'],
    ];

    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly TraceabilityService $traceability,
        private readonly OrganizationDesignGapService $gaps,
        private readonly WorkforcePlanningService $workforce,
    ) {}

    /**
     * @param  array{organization_id?: string|null}  $filters
     * @return array{report: string, columns: list<string>, rows: list<array<string, mixed>>, organizations: int, truncated: bool}
     */
    public function run(string $report, User $user, array $filters): array
    {
        if (! in_array($report, self::REPORTS, true)) {
            $report = 'coverage';
        }
        $organizations = $this->organizations($user, $filters['organization_id'] ?? null);
        $rows = match ($report) {
            'coverage' => $organizations->map(fn (Organization $o) => $this->coverageRow($o))->all(),
            'traceability' => $organizations->flatMap(fn (Organization $o) => collect($this->traceability->rows($o->id))->map(fn ($r) => ['organization_code' => $o->code, ...$r]))->all(),
            'gaps' => $organizations->flatMap(fn (Organization $o) => collect($this->gaps->gaps($o->id)['findings'])->map(fn ($f) => [
                'organization_code' => $o->code, 'severity' => $f['severity'], 'code' => $f['code'], 'subject_type' => $f['subject']['type'] ?? null, 'subject_code' => $f['subject']['code'] ?? null, 'subject_name' => $f['subject']['name'] ?? null, 'message' => $f['message'], 'subject_id' => $f['subject']['id'] ?? null,
            ]))->all(),
            'position_readiness' => $this->readinessRows($organizations),
            'workforce' => $this->workforceRows($organizations),
            default => $organizations->flatMap(fn (Organization $o) => collect($this->catalogRows($report, $o))->map(fn ($r) => ['organization_code' => $o->code, ...$r]))->all(),
        };

        return ['report' => $report, 'columns' => self::COLUMNS[$report], 'rows' => array_values($rows), 'organizations' => $organizations->count(), 'truncated' => $organizations->count() >= self::MAX_ORGANIZATIONS];
    }

    /** @return Collection<int, Organization> */
    public function organizations(User $user, ?string $organizationId): Collection
    {
        $query = Organization::query()->orderBy('code');
        $this->scope->applyOrganizationScope($query, $user, 'id');
        if ($organizationId !== null && $organizationId !== '') {
            $query->whereKey($organizationId);
        }

        return $query->limit(self::MAX_ORGANIZATIONS)->get(['id', 'code', 'name_en', 'name_am']);
    }

    /** @return array<string, mixed> */
    private function coverageRow(Organization $organization): array
    {
        $id = $organization->id;
        $today = now()->toDateString();
        $positions = Position::query()->where('organization_id', $id)->where('is_active', true)->pluck('id');

        return [
            'organization_code' => $organization->code,
            'organization_name' => $organization->name_en,
            'mandates' => InstitutionMandate::query()->where('organization_id', $id)->where('status', OrganizationDesignStatus::Published)->count(),
            'functions' => OrganizationFunction::query()->where('organization_id', $id)->count(),
            'services' => Service::query()->where('organization_id', $id)->where('status', OrganizationDesignStatus::Published)->count(),
            'processes' => Process::query()->where('organization_id', $id)->where('status', OrganizationDesignStatus::Published)->count(),
            'activities' => ProcessActivity::query()->where('organization_id', $id)->where('is_active', true)->count(),
            'tasks' => ProcessTask::query()->where('organization_id', $id)->where('is_active', true)->count(),
            'tasks_with_standard' => PositionServiceTaskStandard::query()->inForceOn($today)->where('organization_id', $id)->whereNotNull('master_task_id')->distinct()->count('master_task_id'),
            'units' => OrganizationUnit::query()->where('organization_id', $id)->where('status', 'active')->count(),
            'units_with_responsibilities' => UnitResponsibility::query()->where('organization_id', $id)->whereNotNull('organization_unit_id')->distinct()->count('organization_unit_id'),
            'positions' => $positions->count(),
            'positions_linked' => PositionWorkLink::query()->whereIn('position_id', $positions)->distinct()->count('position_id'),
            'positions_with_profile' => PositionProfile::query()->whereIn('position_id', $positions)->where('status', OrganizationDesignStatus::Published)->distinct()->count('position_id'),
            'positions_with_job_description' => JobDescription::query()->whereIn('position_id', $positions)
                ->whereIn('status', [OrganizationDesignStatus::Published->value, OrganizationDesignStatus::Superseded->value])
                ->whereDate('effective_from', '<=', $today)->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
                ->distinct()->count('position_id'),
            'manpower_plans_approved' => ManpowerPlan::query()->where('organization_id', $id)->where('status', OrganizationDesignStatus::Approved)->count(),
        ];
    }

    /**
     * @param  Collection<int, Organization>  $organizations
     * @return list<array<string, mixed>>
     */
    private function readinessRows(Collection $organizations): array
    {
        $ids = $organizations->pluck('id');
        if ($ids->isEmpty()) {
            return [];
        }
        $today = now()->toDateString();
        $codes = $organizations->pluck('code', 'id');
        $positions = Position::query()->whereIn('organization_id', $ids)->where('is_active', true)
            ->with(['organizationUnit:id,code', 'occupation:id,code,isco_code,name_en'])
            ->withCount(['profiles as profiles_count'])
            ->orderBy('organization_id')->orderBy('job_position_code')->limit(5000)->get();
        $positionIds = $positions->pluck('id');
        $links = PositionWorkLink::query()->whereIn('position_id', $positionIds)->selectRaw('position_id, count(*) as n')->groupBy('position_id')->pluck('n', 'position_id');
        $competencies = PositionCompetencyRequirement::query()->whereIn('position_id', $positionIds)->selectRaw('position_id, count(*) as n')->groupBy('position_id')->pluck('n', 'position_id');
        $qualifications = PositionQualificationRequirement::query()->whereIn('position_id', $positionIds)->selectRaw('position_id, count(*) as n')->groupBy('position_id')->pluck('n', 'position_id');
        $profiles = PositionProfile::query()->whereIn('position_id', $positionIds)->orderByDesc('version_no')->get(['position_id', 'status'])
            ->groupBy('position_id')->map(fn ($g) => $g->firstWhere('status', OrganizationDesignStatus::Published)?->status ?? $g->first()->status);
        $jds = JobDescription::query()->whereIn('position_id', $positionIds)->orderByDesc('version_no')->get(['id', 'position_id', 'status', 'reference_number', 'effective_from', 'effective_to'])
            ->groupBy('position_id')->map(function ($g) use ($today) {
                $inForce = $g->first(fn ($jd) => in_array($jd->status, [OrganizationDesignStatus::Published, OrganizationDesignStatus::Superseded], true)
                    && $jd->effective_from?->toDateString() <= $today && ($jd->effective_to === null || $jd->effective_to->toDateString() >= $today));

                return $inForce ?? $g->first();
            });

        return $positions->map(function (Position $p) use ($codes, $links, $competencies, $qualifications, $profiles, $jds): array {
            $profile = $profiles->get($p->id);
            $jd = $jds->get($p->id);

            return [
                'organization_code' => $codes[$p->organization_id] ?? null,
                'position_id' => $p->id,
                'position_code' => $p->job_position_code,
                'position_title' => $p->title_en,
                'unit_code' => $p->organizationUnit?->code,
                'occupation' => $p->occupation ? trim(($p->occupation->isco_code ?? $p->occupation->code).' '.$p->occupation->name_en) : null,
                'work_links' => (int) ($links[$p->id] ?? 0),
                'competencies' => (int) ($competencies[$p->id] ?? 0),
                'qualifications' => (int) ($qualifications[$p->id] ?? 0),
                'profile_status' => $profile?->value,
                'job_description_status' => $jd?->status?->value,
                'job_description_reference' => $jd?->reference_number,
                'job_description_id' => $jd?->id,
            ];
        })->all();
    }

    /**
     * The catalog reports (brief §44), one organization at a time.
     *
     * @return list<array<string, mixed>>
     */
    private function catalogRows(string $report, Organization $organization): array
    {
        $id = $organization->id;
        $today = now()->toDateString();
        $published = [OrganizationDesignStatus::Published->value, OrganizationDesignStatus::Superseded->value];

        return match ($report) {
            'service_catalog' => (function () use ($id): array {
                $processCounts = Process::query()->where('organization_id', $id)->where('status', OrganizationDesignStatus::Published)->selectRaw('service_id, count(*) as n')->groupBy('service_id')->pluck('n', 'service_id');

                return Service::query()->where('organization_id', $id)->where('status', OrganizationDesignStatus::Published)->with(['functions:id,code', 'classification:id,code', 'cpcClassification:id,code', 'ownerUnit:id,code'])->orderBy('code')->limit(5000)->get()
                    ->map(fn (Service $s) => [
                        'service_code' => $s->code, 'service_name' => $s->name_en, 'classification_code' => $s->classification?->code, 'cpc_code' => $s->cpcClassification?->code,
                        'beneficiary_type' => $s->beneficiary_type instanceof \BackedEnum ? $s->beneficiary_type->value : $s->beneficiary_type, 'delivery_channel' => $s->delivery_channel instanceof \BackedEnum ? $s->delivery_channel->value : $s->delivery_channel,
                        'owner_unit_code' => $s->ownerUnit?->code, 'functions' => $s->functions->pluck('code')->sort()->implode(', '), 'processes' => (int) ($processCounts[$s->id] ?? 0), 'status' => $s->status->value, 'effective_from' => $s->effective_from?->toDateString(),
                    ])->all();
            })(),
            'function_service_matrix' => OrganizationFunction::query()->where('organization_id', $id)->with(['mandate:id,code', 'services:id,code,name_en,status'])->orderBy('path')->limit(5000)->get()
                ->flatMap(fn (OrganizationFunction $f) => $f->services->isEmpty()
                    ? [['mandate_code' => $f->mandate?->code, 'function_code' => $f->code, 'function_name' => $f->title_en, 'service_code' => null, 'service_name' => null, 'service_status' => null]]
                    : $f->services->map(fn ($s) => ['mandate_code' => $f->mandate?->code, 'function_code' => $f->code, 'function_name' => $f->title_en, 'service_code' => $s->code, 'service_name' => $s->name_en, 'service_status' => $s->status->value]))->all(),
            'service_process_matrix' => Process::query()->where('organization_id', $id)->whereIn('status', $published)->with('service:id,code,name_en')->withCount(['activities', 'tasks'])->orderBy('code')->limit(5000)->get()
                ->map(fn (Process $p) => ['service_code' => $p->service?->code, 'service_name' => $p->service?->name_en, 'process_code' => $p->code, 'process_name' => $p->name_en, 'process_type' => $p->process_type instanceof \BackedEnum ? $p->process_type->value : $p->process_type, 'activities' => (int) $p->activities_count, 'tasks' => (int) $p->tasks_count, 'status' => $p->status->value])->all(),
            'process_activity_matrix' => (function () use ($id, $today): array {
                $activities = ProcessActivity::query()->where('organization_id', $id)->where('is_active', true)->with('process:id,code')->withCount('tasks')->orderBy('path')->limit(5000)->get();
                $tasks = ProcessTask::query()->where('organization_id', $id)->where('is_active', true)->get(['id', 'activity_id'])->groupBy('activity_id');
                $standards = PositionServiceTaskStandard::query()->inForceOn($today)->where('organization_id', $id)->get(['master_task_id', 'activity_id']);
                $byTask = $standards->whereNotNull('master_task_id')->groupBy('master_task_id');
                $byActivity = $standards->whereNotNull('activity_id')->groupBy('activity_id');
                $occ = WorkOccupationRequirement::query()->where('organization_id', $id)->get(['task_id', 'activity_id']);
                $comp = WorkCompetencyRequirement::query()->where('organization_id', $id)->get(['task_id', 'activity_id']);
                $count = fn ($rows, $activity, $taskIds) => $rows->filter(fn ($r) => $r->activity_id === $activity->id || in_array($r->task_id, $taskIds, true))->count();

                return $activities->map(function (ProcessActivity $a) use ($tasks, $byTask, $byActivity, $occ, $comp, $count): array {
                    $taskIds = ($tasks->get($a->id) ?? collect())->pluck('id')->all();
                    $standardsInForce = ($byActivity->get($a->id)?->count() ?? 0) + collect($taskIds)->sum(fn ($t) => $byTask->get($t)?->count() ?? 0);

                    return ['process_code' => $a->process?->code, 'activity_code' => $a->code, 'activity_name' => $a->name_en, 'tasks' => (int) $a->tasks_count, 'standards_in_force' => $standardsInForce, 'occupations_required' => $count($occ, $a, $taskIds), 'competencies_required' => $count($comp, $a, $taskIds)];
                })->all();
            })(),
            'activity_standards' => (function () use ($id, $today): array {
                $standards = PositionServiceTaskStandard::query()->inForceOn($today)->where('organization_id', $id)->where(fn ($q) => $q->whereNotNull('master_task_id')->orWhereNotNull('activity_id'))->orderByDesc('effective_from')->limit(5000)->get();
                $tasks = ProcessTask::query()->whereIn('id', $standards->pluck('master_task_id')->filter())->with('process:id,code')->get()->keyBy('id');
                $activities = ProcessActivity::query()->whereIn('id', $standards->pluck('activity_id')->filter())->with('process:id,code')->get()->keyBy('id');

                return $standards->map(function (PositionServiceTaskStandard $s) use ($tasks, $activities): array {
                    $subject = $s->master_task_id ? $tasks->get($s->master_task_id) : $activities->get($s->activity_id);

                    return [
                        'process_code' => $subject?->process?->code, 'subject_type' => $s->master_task_id ? 'task' : 'activity', 'subject_code' => $subject?->code, 'subject_name' => $subject?->name_en, 'version_no' => $s->version_no,
                        'planned_quantity' => $s->planned_quantity === null ? null : (string) $s->planned_quantity, 'quantity_unit' => $s->quantity_unit, 'planned_time_minutes' => $s->planned_time_minutes === null ? null : (string) $s->planned_time_minutes,
                        'planned_quality' => $s->planned_quality === null ? null : (string) $s->planned_quality, 'quality_unit' => $s->quality_unit, 'effective_from' => $s->effective_from?->toDateString(), 'effective_to' => $s->effective_to?->toDateString(),
                    ];
                })->sortBy(['process_code', 'subject_code'])->values()->all();
            })(),
            'occupation_demand' => (function () use ($id): array {
                $positions = Position::query()->where('organization_id', $id)->where('is_active', true)->whereNotNull('occupation_id')->get(['id', 'occupation_id']);
                $current = EmployeeAssignment::query()->whereIn('position_id', $positions->pluck('id'))->where('is_current', true)->selectRaw('position_id, count(*) as n')->groupBy('position_id')->pluck('n', 'position_id');
                $tasks = WorkOccupationRequirement::query()->where('organization_id', $id)->selectRaw('occupation_id, count(*) as n')->groupBy('occupation_id')->pluck('n', 'occupation_id');
                $approved = PositionRequirement::query()->where('organization_id', $id)->whereIn('status', ['approved', 'implemented'])->selectRaw('occupation_id, sum(approved_headcount) as n')->groupBy('occupation_id')->pluck('n', 'occupation_id');
                $ids = $positions->pluck('occupation_id')->merge($tasks->keys())->merge($approved->keys())->unique();

                return Occupation::query()->whereIn('id', $ids)->orderBy('isco_code')->get(['id', 'code', 'isco_code', 'name_en'])->map(function (Occupation $o) use ($positions, $current, $tasks, $approved): array {
                    $own = $positions->where('occupation_id', $o->id)->pluck('id');

                    return ['occupation_code' => $o->isco_code ?? $o->code, 'occupation_name' => $o->name_en, 'tasks_requiring' => (int) ($tasks[$o->id] ?? 0), 'positions' => $own->count(), 'approved_headcount' => (int) ($approved[$o->id] ?? 0), 'current_employees' => (int) $own->sum(fn ($p) => $current[$p] ?? 0)];
                })->all();
            })(),
            'competency_demand' => (function () use ($id): array {
                $tasks = WorkCompetencyRequirement::query()->where('organization_id', $id)->selectRaw('competency_id, count(*) as n')->groupBy('competency_id')->pluck('n', 'competency_id');
                $positions = PositionCompetencyRequirement::query()->where('organization_id', $id)->get(['competency_id', 'is_mandatory'])->groupBy('competency_id');
                $ids = $tasks->keys()->merge($positions->keys())->unique();

                return Competency::query()->whereIn('id', $ids)->orderBy('code')->get(['id', 'code', 'name_en'])->map(fn (Competency $c) => [
                    'competency_code' => $c->code, 'competency_name' => $c->name_en, 'tasks_requiring' => (int) ($tasks[$c->id] ?? 0),
                    'positions_requiring' => ($positions->get($c->id) ?? collect())->count(), 'mandatory_positions' => ($positions->get($c->id) ?? collect())->where('is_mandatory', true)->count(),
                ])->all();
            })(),
            'responsibility_matrix' => (function () use ($id): array {
                $rows = UnitResponsibility::query()->where('organization_id', $id)->whereNotNull('organization_unit_id')->with(['unit:id,code,name_en', 'type:id,code'])->limit(10000)->get();
                $objects = [];
                foreach (UnitResponsibility::OBJECT_MODELS as $type => $model) {
                    $ids = $rows->where('object_type', $type)->pluck('object_id')->unique();
                    $objects[$type] = $ids->isEmpty() ? collect() : $model::query()->whereIn('id', $ids)->get()->keyBy('id');
                }

                return $rows->map(function (UnitResponsibility $r) use ($objects): array {
                    $object = $objects[$r->object_type]?->get($r->object_id);

                    return ['unit_code' => $r->unit?->code, 'unit_name' => $r->unit?->name_en, 'object_type' => $r->object_type, 'object_code' => $object?->code, 'object_name' => $object?->name_en ?? $object?->title_en, 'responsibility_type' => $r->type?->code];
                })->sortBy(['unit_code', 'object_type', 'object_code'])->values()->all();
            })(),
            'fte_vs_headcount' => (function () use ($id): array {
                $plans = ManpowerPlan::query()->where('organization_id', $id)->whereIn('status', [OrganizationDesignStatus::Approved->value, OrganizationDesignStatus::UnderReview->value, OrganizationDesignStatus::Draft->value])->pluck('reference_number', 'id');
                $requirements = PositionRequirement::query()->whereIn('plan_id', $plans->keys())->get(['plan_id', 'organization_unit_id', 'occupation_id', 'position_id', 'status']);
                $current = EmployeeAssignment::query()->whereIn('position_id', $requirements->pluck('position_id')->filter())->where('is_current', true)->selectRaw('position_id, count(*) as n')->groupBy('position_id')->pluck('n', 'position_id');

                return ManpowerPlanLine::query()->whereIn('plan_id', $plans->keys())->with(['unit:id,code', 'occupation:id,code,isco_code,name_en'])->orderBy('sequence')->limit(10000)->get()->map(function (ManpowerPlanLine $l) use ($plans, $requirements, $current): array {
                    $implemented = $requirements->filter(fn ($r) => $r->plan_id === $l->plan_id && $r->organization_unit_id === $l->organization_unit_id && $r->occupation_id === $l->occupation_id && $r->position_id !== null);

                    return [
                        'reference_number' => $plans[$l->plan_id] ?? null, 'unit_code' => $l->unit?->code, 'occupation' => $l->occupation ? trim(($l->occupation->isco_code ?? $l->occupation->code).' '.$l->occupation->name_en) : null,
                        'calculated_fte' => $l->calculated_fte === null ? null : (string) $l->calculated_fte, 'recommended_headcount' => $l->recommended_headcount, 'approved_headcount' => $l->approved_headcount,
                        'implemented_positions' => $implemented->count(), 'current_employees' => (int) $implemented->sum(fn ($r) => $current[$r->position_id] ?? 0),
                    ];
                })->all();
            })(),
            'position_requirements' => PositionRequirement::query()->where('organization_id', $id)->with(['plan:id,reference_number', 'unit:id,code', 'occupation:id,code,isco_code,name_en', 'profession:id,code,name_en', 'position:id,job_position_code', 'establishment:id,establishment_number'])->orderBy('sequence')->limit(10000)->get()
                ->map(fn (PositionRequirement $r) => [
                    'reference_number' => $r->plan?->reference_number, 'unit_code' => $r->unit?->code, 'occupation' => $r->occupation ? trim(($r->occupation->isco_code ?? $r->occupation->code).' '.$r->occupation->name_en) : null, 'profession' => $r->profession?->name_en,
                    'position_title' => $r->position_title_en, 'required_fte' => $r->required_fte === null ? null : (string) $r->required_fte, 'approved_headcount' => $r->approved_headcount, 'status' => $r->status instanceof \BackedEnum ? $r->status->value : $r->status,
                    'position_code' => $r->position?->job_position_code, 'establishment_number' => $r->establishment?->establishment_number,
                ])->all(),
            'vacancy' => (function () use ($id): array {
                $positions = Position::query()->where('organization_id', $id)->where('is_active', true)->with('organizationUnit:id,code')->orderBy('job_position_code')->limit(20000)->get(['id', 'job_position_code', 'title_en', 'organization_unit_id']);
                $slots = PositionEstablishment::query()->whereIn('position_id', $positions->pluck('id'))->where('status', 'approved')->selectRaw('position_id, sum(approved_slots) as n')->groupBy('position_id')->pluck('n', 'position_id');
                $current = EmployeeAssignment::query()->whereIn('position_id', $positions->pluck('id'))->where('is_current', true)->selectRaw('position_id, count(*) as n')->groupBy('position_id')->pluck('n', 'position_id');

                return $positions->map(fn (Position $p) => ['position_code' => $p->job_position_code, 'position_title' => $p->title_en, 'unit_code' => $p->organizationUnit?->code, 'approved_slots' => (int) ($slots[$p->id] ?? 0), 'current_employees' => (int) ($current[$p->id] ?? 0), 'vacancies' => (int) ($slots[$p->id] ?? 0) - (int) ($current[$p->id] ?? 0)])->all();
            })(),
            'jd_register' => JobDescription::query()->where('organization_id', $id)->with(['position:id,job_position_code,title_en', 'preparer:id,name', 'approver:id,name', 'publisher:id,name'])->orderBy('reference_number')->orderBy('version_no')->limit(10000)->get()
                ->map(fn (JobDescription $jd) => [
                    'reference_number' => $jd->reference_number, 'position_code' => $jd->position?->job_position_code, 'position_title' => $jd->position?->title_en, 'version_no' => $jd->version_no, 'status' => $jd->status->value,
                    'effective_from' => $jd->effective_from?->toDateString(), 'effective_to' => $jd->effective_to?->toDateString(), 'prepared_by' => $jd->preparer?->name, 'approved_by' => $jd->approver?->name, 'published_by' => $jd->publisher?->name,
                ])->all(),
            default => [],
        };
    }

    /**
     * @param  Collection<int, Organization>  $organizations
     * @return list<array<string, mixed>>
     */
    private function workforceRows(Collection $organizations): array
    {
        $codes = $organizations->pluck('code', 'id');
        if ($codes->isEmpty()) {
            return [];
        }

        return ManpowerPlan::query()->whereIn('organization_id', $codes->keys())->with(['methodology:id,code,version_no', 'approver:id,name'])->orderBy('reference_number')->limit(2000)->get()
            ->map(function (ManpowerPlan $plan) use ($codes): array {
                $totals = $this->workforce->totals($plan);

                return [
                    'organization_code' => $codes[$plan->organization_id] ?? null,
                    'plan_id' => $plan->id,
                    'reference_number' => $plan->reference_number,
                    'title' => $plan->title_en,
                    'status' => $plan->status->value,
                    'period_start' => $plan->planning_period_start?->toDateString(),
                    'period_end' => $plan->planning_period_end?->toDateString(),
                    'methodology' => $plan->methodology ? $plan->methodology->code.' v'.$plan->methodology->version_no : null,
                    'lines' => $totals['lines'],
                    'calculated_fte' => $totals['calculated_fte'],
                    'headcount' => $totals['headcount'],
                    'approved_by' => $plan->approver?->name,
                ];
            })->all();
    }
}
