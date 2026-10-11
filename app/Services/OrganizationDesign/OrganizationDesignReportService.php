<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign;

use App\Enums\OrganizationDesignStatus;
use App\Models\InstitutionMandate;
use App\Models\JobDescription;
use App\Models\ManpowerPlan;
use App\Models\Organization;
use App\Models\OrganizationFunction;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PositionCompetencyRequirement;
use App\Models\PositionProfile;
use App\Models\PositionQualificationRequirement;
use App\Models\PositionServiceTaskStandard;
use App\Models\PositionWorkLink;
use App\Models\Process;
use App\Models\ProcessActivity;
use App\Models\ProcessTask;
use App\Models\Service;
use App\Models\UnitResponsibility;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Support\Collection;

/**
 * Tabular reports over the design (brief §88, 98–99, 104–105), every one of
 * them cut to the organizations the user may see. Rows are plain arrays so
 * the page and the export share them.
 */
class OrganizationDesignReportService
{
    public const REPORTS = ['coverage', 'traceability', 'gaps', 'position_readiness', 'workforce'];

    /** The most organizations one run covers; the organization filter narrows it. */
    public const MAX_ORGANIZATIONS = 200;

    /** @var array<string, list<string>> */
    public const COLUMNS = [
        'coverage' => ['organization_code', 'organization_name', 'mandates', 'functions', 'services', 'processes', 'activities', 'tasks', 'tasks_with_standard', 'units', 'units_with_responsibilities', 'positions', 'positions_linked', 'positions_with_profile', 'positions_with_job_description', 'manpower_plans_approved'],
        'traceability' => ['organization_code', 'mandate_code', 'function_code', 'service_code', 'process_code', 'activity_code', 'task_code', 'task_name', 'positions', 'job_descriptions'],
        'gaps' => ['organization_code', 'severity', 'code', 'subject_type', 'subject_code', 'subject_name', 'message'],
        'position_readiness' => ['organization_code', 'position_code', 'position_title', 'unit_code', 'occupation', 'work_links', 'competencies', 'qualifications', 'profile_status', 'job_description_status', 'job_description_reference'],
        'workforce' => ['organization_code', 'reference_number', 'title', 'status', 'period_start', 'period_end', 'methodology', 'lines', 'calculated_fte', 'headcount', 'approved_by'],
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
