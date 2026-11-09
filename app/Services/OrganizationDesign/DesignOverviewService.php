<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign;

use App\Enums\OrganizationDesignStatus;
use App\Models\DesignImportBatch;
use App\Models\EmployeeAssignment;
use App\Models\InstitutionMandate;
use App\Models\JobDescription;
use App\Models\LegacyBaseline;
use App\Models\LegalSource;
use App\Models\ManpowerPlan;
use App\Models\ManpowerPlanLine;
use App\Models\Organization;
use App\Models\Position;
use App\Models\PositionProfile;
use App\Models\PositionServiceTaskStandard;
use App\Models\ProcessTask;
use App\Models\Service;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The module's landing figures (brief §101–102) and its "as of date X"
 * answers (§91). Every figure is one aggregate query over the records the
 * user may see; nothing is loaded row by row and nothing is invented: a
 * figure the data cannot support is simply zero.
 */
class DesignOverviewService
{
    public function __construct(private readonly OrganizationScopeService $scope) {}

    /** @return array<string, int|string> */
    public function kpis(User $user, ?string $organizationId): array
    {
        $scoped = fn (Builder $q, string $column = 'organization_id'): Builder => $this->scoped($q, $user, $organizationId, $column);
        $today = now()->toDateString();

        $organizations = $scoped(Organization::query()->where('status', 'active'), 'id')->count();
        $withPublishedMandate = $scoped(InstitutionMandate::query()->where('status', OrganizationDesignStatus::Published))->distinct()->count('organization_id');
        $positions = $scoped(Position::query()->where('is_active', true));
        $positionCount = (clone $positions)->count();
        $occupied = $scoped(EmployeeAssignment::query()->where('is_current', true)->whereNotNull('position_id'))->distinct()->count('position_id');
        $withJd = $scoped(JobDescription::query()->where('status', OrganizationDesignStatus::Published))->distinct()->count('position_id');
        $withProfile = $scoped(PositionProfile::query()->where('status', OrganizationDesignStatus::Published))->distinct()->count('position_id');
        $tasks = $scoped(ProcessTask::query()->where('is_active', true))->count();
        $tasksWithStandard = $scoped(PositionServiceTaskStandard::query()->inForceOn($today)->whereNotNull('master_task_id'))->distinct()->count('master_task_id');
        $publishedServices = $scoped(Service::query()->where('status', OrganizationDesignStatus::Published));
        $servicesWithoutFunction = (clone $publishedServices)->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('function_service')->whereColumn('function_service.service_id', 'services.id'))->count();
        $planLines = $scoped(ManpowerPlanLine::query()->whereIn('plan_id', ManpowerPlan::query()->where('status', OrganizationDesignStatus::Approved)->select('id')));

        return [
            'organizations' => $organizations,
            'baselines_published' => $scoped(LegacyBaseline::query()->where('status', OrganizationDesignStatus::Published))->distinct()->count('organization_id'),
            'baselines_in_progress' => $scoped(LegacyBaseline::query()->whereIn('status', [OrganizationDesignStatus::Draft, OrganizationDesignStatus::UnderReview, OrganizationDesignStatus::Approved]))->count(),
            'baselines_failing_validation' => $scoped(LegacyBaseline::query()->whereNotIn('status', [OrganizationDesignStatus::Cancelled, OrganizationDesignStatus::Superseded])->where('validation_status', 'failed'))->count(),
            'organizations_without_mandate' => max(0, $organizations - $withPublishedMandate),
            'legal_sources' => $this->sourcesVisible($user, $organizationId)->count(),
            'services_without_function' => $servicesWithoutFunction,
            'tasks_without_standard' => max(0, $tasks - $tasksWithStandard),
            // FTE stays a decimal string: summed in SQL, never rounded here.
            'required_fte' => (string) ((clone $planLines)->sum('calculated_fte') ?: '0'),
            'approved_headcount' => (int) (clone $planLines)->sum('approved_headcount'),
            'positions' => $positionCount,
            'positions_vacant' => max(0, $positionCount - $occupied),
            'positions_without_jd' => max(0, $positionCount - $withJd),
            'positions_without_profile' => max(0, $positionCount - $withProfile),
            'imports_waiting' => $scoped(DesignImportBatch::query()->whereIn('status', [DesignImportBatch::STATUS_VALIDATED, DesignImportBatch::STATUS_MAPPING_REQUIRED]))->count(),
        ];
    }

    /**
     * What applied to one organization on a date: the mandate versions in
     * force, the job descriptions in force, the published baseline that
     * described it (the latest one whose effective date is on or before the
     * date) and the manpower plan whose period covers it.
     *
     * @return array<string, mixed>
     */
    public function asOf(string $organizationId, string $date): array
    {
        $day = Carbon::parse($date)->toDateString();
        $inForce = fn (Builder $q): Builder => $q->whereDate('effective_from', '<=', $day)->where(fn ($o) => $o->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day));

        $mandates = $inForce(InstitutionMandate::query()->where('organization_id', $organizationId)->whereIn('status', [OrganizationDesignStatus::Published, OrganizationDesignStatus::Superseded]))
            ->orderBy('code')->limit(50)->get(['id', 'code', 'version_no', 'title_en', 'title_am', 'status', 'effective_from', 'effective_to']);
        $jdCount = $inForce(JobDescription::query()->where('organization_id', $organizationId)->whereIn('status', [OrganizationDesignStatus::Published, OrganizationDesignStatus::Superseded]))->count();
        $baseline = LegacyBaseline::query()->where('organization_id', $organizationId)->whereIn('status', [OrganizationDesignStatus::Published, OrganizationDesignStatus::Superseded])
            ->whereDate('effective_date', '<=', $day)->orderByDesc('effective_date')->orderByDesc('version_no')->with('snapshot')->first();
        $plan = ManpowerPlan::query()->where('organization_id', $organizationId)->where('status', OrganizationDesignStatus::Approved)
            ->whereDate('planning_period_start', '<=', $day)->whereDate('planning_period_end', '>=', $day)->orderByDesc('planning_period_start')->first(['id', 'reference_number', 'title_en', 'title_am', 'planning_period_start', 'planning_period_end']);

        return [
            'date' => $day,
            'mandates' => $mandates->map(fn (InstitutionMandate $m) => ['id' => $m->id, 'code' => $m->code, 'version_no' => $m->version_no, 'title_en' => $m->title_en, 'title_am' => $m->title_am, 'status' => $m->status->value, 'effective_from' => $m->effective_from?->toDateString(), 'effective_to' => $m->effective_to?->toDateString()])->all(),
            'job_descriptions_in_force' => $jdCount,
            'baseline' => $baseline ? [
                'id' => $baseline->id, 'reference_number' => $baseline->reference_number, 'version_no' => $baseline->version_no, 'title_en' => $baseline->title_en, 'title_am' => $baseline->title_am,
                'status' => $baseline->status->value, 'effective_date' => $baseline->effective_date?->toDateString(), 'counts' => $baseline->snapshot?->counts,
            ] : null,
            'plan' => $plan ? ['id' => $plan->id, 'reference_number' => $plan->reference_number, 'title_en' => $plan->title_en, 'title_am' => $plan->title_am, 'period_start' => $plan->planning_period_start?->toDateString(), 'period_end' => $plan->planning_period_end?->toDateString()] : null,
        ];
    }

    private function scoped(Builder $query, User $user, ?string $organizationId, string $column): Builder
    {
        $this->scope->applyOrganizationScope($query, $user, $column);
        if ($organizationId !== null && $organizationId !== '') {
            $query->where($column, $organizationId);
        }

        return $query;
    }

    private function sourcesVisible(User $user, ?string $organizationId): Builder
    {
        $query = LegalSource::query()->where('status', 'active');
        if ($organizationId !== null && $organizationId !== '') {
            return $query->citableBy($organizationId);
        }
        if (! $this->scope->isUnrestricted($user)) {
            $allowed = $this->scope->allowedOrganizationIds($user);
            $query->where(fn ($q) => $q->whereNull('organization_id')->orWhereIn('organization_id', $allowed));
        }

        return $query;
    }
}
