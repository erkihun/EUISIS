<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign;

use App\Enums\OrganizationDesignStatus;
use App\Models\HierarchyVersion;
use App\Models\InstitutionMandate;
use App\Models\JobDescription;
use App\Models\LegacyBaseline;
use App\Models\LegalSource;
use App\Models\ManpowerPlan;
use App\Models\OrganizationEdge;
use App\Models\OrganizationFunction;
use App\Models\OrganizationPower;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PositionCompetencyRequirement;
use App\Models\PositionProfile;
use App\Models\PositionServiceTaskStandard;
use App\Models\Process;
use App\Models\ProcessActivity;
use App\Models\ProcessTask;
use App\Models\Service;
use App\Models\UnitResponsibility;
use App\Models\WorkCompetencyRequirement;
use App\Models\WorkOccupationRequirement;

/**
 * The 22 steps of the legacy capture wizard (brief §61) and, for each
 * capture step, how many records of that kind the organization already
 * has. Existing data is detected and reused, never copied: a step is
 * complete when its records exist, and the page where a person enters more
 * is the module's ordinary page for that kind, filtered to the organization.
 */
final class LegacyBaselineStepRegistry
{
    /** Steps that are the baseline's own workflow, not captured data. */
    public const WORKFLOW_STEPS = ['validation', 'review', 'approval', 'publish'];

    /** step key => [route name of the entry page, or null when the baseline page itself is the entry]. */
    public const STEPS = [
        'organization_sources' => 'organization-design.legal-sources.index',
        'mandates' => 'organization-design.mandates.index',
        'powers_functions' => 'organization-design.functions.index',
        'services' => 'organization-design.services.index',
        'processes' => 'organization-design.processes.index',
        'activities' => 'organization-design.processes.index',
        'tasks' => 'organization-design.processes.index',
        'work_standards' => 'organization-design.processes.index',
        'occupations' => 'organization-design.professions.index',
        'professions' => 'organization-design.professions.index',
        'competencies' => 'organization-design.competency-catalog.index',
        'unit_design' => 'organization-design.studies.index',
        'responsibilities' => 'organization-design.responsibilities.index',
        'structure_version' => 'organization-design.structure.index',
        'workforce_planning' => 'organization-design.manpower-plans.index',
        'positions' => 'organization-design.position-design.index',
        'position_profiles' => 'organization-design.position-design.index',
        'job_descriptions' => 'organization-design.position-design.index',
        'validation' => null,
        'review' => null,
        'approval' => null,
        'publish' => null,
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::STEPS);
    }

    /**
     * Records already recorded for the organization, per capture step.
     * One grouped or counting query per step; nothing is loaded.
     *
     * @return array<string, int>
     */
    public function detect(LegacyBaseline $baseline): array
    {
        $id = $baseline->organization_id;
        $published = OrganizationDesignStatus::Published;

        return [
            'organization_sources' => LegalSource::query()->where('organization_id', $id)->count() + ($baseline->legal_source_id ? 1 : 0),
            'mandates' => InstitutionMandate::query()->where('organization_id', $id)->whereIn('status', [$published, OrganizationDesignStatus::Draft, OrganizationDesignStatus::UnderReview, OrganizationDesignStatus::Approved])->count(),
            'powers_functions' => OrganizationPower::query()->where('organization_id', $id)->count() + OrganizationFunction::query()->where('organization_id', $id)->count(),
            'services' => Service::query()->where('organization_id', $id)->whereNot('status', OrganizationDesignStatus::Cancelled)->count(),
            'processes' => Process::query()->where('organization_id', $id)->whereNot('status', OrganizationDesignStatus::Cancelled)->count(),
            'activities' => ProcessActivity::query()->where('organization_id', $id)->where('is_active', true)->count(),
            'tasks' => ProcessTask::query()->where('organization_id', $id)->where('is_active', true)->count(),
            'work_standards' => PositionServiceTaskStandard::query()->where('organization_id', $id)->whereNotNull('master_task_id')->count(),
            'occupations' => WorkOccupationRequirement::query()->where('organization_id', $id)->whereNotNull('occupation_id')->distinct()->count('occupation_id')
                + Position::query()->where('organization_id', $id)->where('is_active', true)->whereNotNull('occupation_id')->distinct()->count('occupation_id'),
            'professions' => WorkOccupationRequirement::query()->where('organization_id', $id)->whereNotNull('profession_id')->distinct()->count('profession_id'),
            'competencies' => WorkCompetencyRequirement::query()->where('organization_id', $id)->distinct()->count('competency_id')
                + PositionCompetencyRequirement::query()->where('organization_id', $id)->distinct()->count('competency_id'),
            'unit_design' => OrganizationUnit::query()->where('organization_id', $id)->where('status', 'active')->count(),
            'responsibilities' => UnitResponsibility::query()->where('organization_id', $id)->count(),
            'structure_version' => HierarchyVersion::query()->where('status', 'published')
                ->whereIn('id', OrganizationEdge::query()->where(fn ($q) => $q->where('parent_organization_id', $id)->orWhere('child_organization_id', $id))->select('hierarchy_version_id'))
                ->count() + ($baseline->hierarchy_version_id ? 1 : 0),
            'workforce_planning' => ManpowerPlan::query()->where('organization_id', $id)->whereNot('status', OrganizationDesignStatus::Cancelled)->count(),
            'positions' => Position::query()->where('organization_id', $id)->where('is_active', true)->count(),
            'position_profiles' => PositionProfile::query()->where('organization_id', $id)->whereNot('status', OrganizationDesignStatus::Cancelled)->count(),
            'job_descriptions' => JobDescription::query()->where('organization_id', $id)->whereNot('status', OrganizationDesignStatus::Cancelled)->count(),
        ];
    }

    /** The entry page for a capture step, filtered to the organization; null for workflow steps. */
    public function entryUrl(string $step, string $organizationId): ?string
    {
        $route = self::STEPS[$step] ?? null;

        return $route === null ? null : route($route, ['organization_id' => $organizationId]);
    }
}
