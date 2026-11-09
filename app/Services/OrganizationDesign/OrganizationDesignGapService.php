<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign;

use App\Enums\OrganizationDesignStatus;
use App\Models\InstitutionMandate;
use App\Models\JobDescription;
use App\Models\OrganizationFunction;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PositionProfile;
use App\Models\PositionRequirement;
use App\Models\PositionServiceTaskStandard;
use App\Models\PositionWorkLink;
use App\Models\Process;
use App\Models\ProcessTask;
use App\Models\Service;
use App\Models\WorkCompetencyRequirement;
use App\Models\WorkOccupationRequirement;
use App\Models\WorkQualificationRequirement;
use App\Services\OrganizationDesign\Concerns\BuildsDesignFindings;

/**
 * The gap report (brief §77, 118): what the design recorded at one level but
 * never carried to the next. Warnings and information only — a gap never
 * blocks anything by itself; the gates decide per record.
 */
class OrganizationDesignGapService
{
    use BuildsDesignFindings;

    public function __construct(private readonly ResponsibilityService $responsibilities) {}

    /** @return array{ok: bool, summary: array{errors: int, warnings: int, infos: int}, findings: list<array<string, mixed>>, generated_at: string} */
    public function gaps(string $organizationId): array
    {
        $findings = [];
        $today = now()->toDateString();

        // Mandate → functions.
        $mandates = InstitutionMandate::query()->where('organization_id', $organizationId)->where('status', OrganizationDesignStatus::Published)->withCount('functions')->orderBy('code')->get();
        foreach ($mandates->where('functions_count', 0) as $mandate) {
            $findings[] = $this->finding('warning', 'mandate_without_functions', ['type' => 'mandate', 'id' => $mandate->id, 'code' => $mandate->code, 'name' => $mandate->title_en]);
        }

        // Functions → services.
        $functions = OrganizationFunction::query()->where('organization_id', $organizationId)->withCount('services')->orderBy('path')->get();
        foreach ($functions->where('services_count', 0) as $function) {
            $findings[] = $this->finding('warning', 'function_without_services', ['type' => 'function', 'id' => $function->id, 'code' => $function->code, 'name' => $function->title_en]);
        }

        // Services → processes, and who owns the service (brief §14).
        $services = Service::query()->where('organization_id', $organizationId)->where('status', OrganizationDesignStatus::Published)->orderBy('code')->get();
        $servicesWithProcess = Process::query()->where('organization_id', $organizationId)->where('status', OrganizationDesignStatus::Published)->pluck('service_id')->flip();
        $liveUnits = OrganizationUnit::query()->where('organization_id', $organizationId)->where('status', 'active')->pluck('id')->flip();
        foreach ($services as $service) {
            $subject = ['type' => 'service', 'id' => $service->id, 'code' => $service->code, 'name' => $service->name_en];
            if (! $servicesWithProcess->has($service->id)) {
                $findings[] = $this->finding('warning', 'service_without_processes', $subject);
            }
            if ($service->owner_organization_unit_id === null) {
                $findings[] = $this->finding('warning', 'service_without_owner_unit', $subject);
            } elseif (! $liveUnits->has($service->owner_organization_unit_id)) {
                $findings[] = $this->finding('warning', 'service_owner_unit_invalid', $subject);
            }
        }

        // Approved manpower that never became a position (brief §34).
        foreach (PositionRequirement::query()->where('organization_id', $organizationId)->where('status', 'approved')->whereNull('position_id')->orderBy('sequence')->get() as $requirement) {
            $findings[] = $this->finding('warning', 'approved_requirement_without_position', ['type' => 'position_requirement', 'id' => $requirement->id, 'code' => $requirement->id, 'name' => $requirement->position_title_en], ['headcount' => $requirement->approved_headcount]);
        }

        // Processes → tasks.
        $processes = Process::query()->where('organization_id', $organizationId)->where('status', OrganizationDesignStatus::Published)->withCount('tasks')->orderBy('code')->get();
        foreach ($processes->where('tasks_count', 0) as $process) {
            $findings[] = $this->finding('warning', 'process_without_tasks', ['type' => 'process', 'id' => $process->id, 'code' => $process->code, 'name' => $process->name_en]);
        }

        // Tasks → standards and requirements.
        $tasks = ProcessTask::query()->where('organization_id', $organizationId)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_en', 'activity_id']);
        $withStandard = PositionServiceTaskStandard::query()->inForceOn($today)->where('organization_id', $organizationId)->whereNotNull('master_task_id')->pluck('master_task_id')->flip();
        $withRequirement = collect()
            ->merge(WorkCompetencyRequirement::query()->where('organization_id', $organizationId)->whereNotNull('task_id')->pluck('task_id'))
            ->merge(WorkOccupationRequirement::query()->where('organization_id', $organizationId)->whereNotNull('task_id')->pluck('task_id'))
            ->merge(WorkQualificationRequirement::query()->where('organization_id', $organizationId)->whereNotNull('task_id')->pluck('task_id'))
            ->flip();
        foreach ($tasks as $task) {
            $subject = ['type' => 'task', 'id' => $task->id, 'code' => $task->code, 'name' => $task->name_en];
            if (! $withStandard->has($task->id)) {
                $findings[] = $this->finding('warning', 'task_without_standard', $subject);
            }
            if (! $withRequirement->has($task->id)) {
                $findings[] = $this->finding('info', 'task_without_requirements', $subject);
            }
        }

        // Objects nobody is responsible for.
        foreach ($this->responsibilities->gaps($organizationId) as $objectType => $objects) {
            $type = rtrim($objectType, 's');
            foreach ($objects as $object) {
                $findings[] = $this->finding('warning', 'object_without_responsible_unit', ['type' => $type, 'id' => $object['id'], 'code' => $object['code'], 'name' => $object['name_en']], ['object_type' => $type]);
            }
        }

        // Positions → links, profile, job description.
        $positions = Position::query()->where('organization_id', $organizationId)->where('is_active', true)->orderBy('job_position_code')->get(['id', 'job_position_code', 'title_en', 'organization_unit_id', 'occupation_id']);
        $linked = PositionWorkLink::query()->where('organization_id', $organizationId)->distinct()->pluck('position_id')->flip();
        $profiled = PositionProfile::query()->where('organization_id', $organizationId)->where('status', OrganizationDesignStatus::Published)->pluck('position_id')->flip();
        $described = JobDescription::query()->where('organization_id', $organizationId)
            ->whereIn('status', [OrganizationDesignStatus::Published->value, OrganizationDesignStatus::Superseded->value])
            ->whereDate('effective_from', '<=', $today)->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
            ->pluck('position_id')->flip();
        foreach ($positions as $position) {
            $subject = ['type' => 'position', 'id' => $position->id, 'code' => $position->job_position_code, 'name' => $position->title_en];
            if ($position->occupation_id === null) {
                $findings[] = $this->finding('warning', 'position_without_occupation', $subject);
            }
            if (! $linked->has($position->id)) {
                $findings[] = $this->finding('warning', 'position_without_work_links', $subject);
            }
            if (! $profiled->has($position->id)) {
                $findings[] = $this->finding('warning', 'position_without_profile', $subject);
            }
            if (! $described->has($position->id)) {
                $findings[] = $this->finding('warning', 'position_without_job_description', $subject);
            }
        }

        // Units → positions.
        $unitsWithPositions = $positions->pluck('organization_unit_id')->filter()->flip();
        $units = OrganizationUnit::query()->where('organization_id', $organizationId)->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name_en']);
        foreach ($units->reject(fn ($u) => $unitsWithPositions->has($u->id)) as $unit) {
            $findings[] = $this->finding('info', 'unit_without_positions', ['type' => 'unit', 'id' => $unit->id, 'code' => $unit->code, 'name' => $unit->name_en]);
        }

        return $this->report($findings);
    }
}
