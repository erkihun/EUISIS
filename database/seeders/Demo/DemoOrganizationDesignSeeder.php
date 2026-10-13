<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\OrganizationDesignStatus;
use App\Models\Competency;
use App\Models\CompetencyFramework;
use App\Models\CompetencyLevel;
use App\Models\EducationLevel;
use App\Models\InstitutionMandate;
use App\Models\JobDescription;
use App\Models\LegalInstrumentType;
use App\Models\Occupation;
use App\Models\Organization;
use App\Models\OrganizationDesignStudy;
use App\Models\OrganizationFunction;
use App\Models\OrganizationPower;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\Position;
use App\Models\PositionCompetencyRequirement;
use App\Models\PositionProfile;
use App\Models\PositionServiceTaskStandard;
use App\Models\PositionWorkLink;
use App\Models\Process;
use App\Models\ProcessTask;
use App\Models\ResponsibilityType;
use App\Models\Service;
use App\Models\UnitResponsibility;
use App\Models\User;
use App\Services\DailyActivity\TaskStandardService;
use App\Services\OrganizationDesign\DesignStudyService;
use App\Services\OrganizationDesign\FunctionService;
use App\Services\OrganizationDesign\JobDescriptionService;
use App\Services\OrganizationDesign\MandateService;
use App\Services\OrganizationDesign\PositionProfileService;
use App\Services\OrganizationDesign\ProcessArchitectureService;
use App\Services\OrganizationDesign\ResponsibilityService;
use App\Services\OrganizationDesign\ServiceCatalogService;
use App\Services\OrganizationDesign\WorkRequirementService;
use App\Support\Demo\DemoDataset;
use Database\Seeders\LegalInstrumentTypeSeeder;
use Database\Seeders\OrganizationDesignCatalogSeeder;
use RuntimeException;

/**
 * Organization Design & Workforce Planning demo chain for ORG-1
 * (docs/organization-design.md): one mandate → two functions → two services
 * → two processes with activities, tasks, requirements and a standard →
 * responsibilities → an approved design study → position design and a
 * published job description for the HR officer, a draft profile for the
 * records clerk. Everything goes through the domain services as a designer
 * and a separate approver, so maker ≠ checker and the audit trail are real.
 * Nothing city-level is invented: classifications stay empty
 * (OFFICIAL_DATASET_REQUIRED) and no manpower parameters are seeded (D-6/D-7).
 * Every step finds its own records by code first, so a re-run adds nothing.
 */
class DemoOrganizationDesignSeeder extends DemoSeeder
{
    public const ORGANIZATION = 'ORG-1';

    public const DESIGNER_EMAIL = 'demo.org1.designer@example.test';

    public const APPROVER_EMAIL = 'demo.org1.design.approver@example.test';

    public const MANDATE_CODE = 'MND-01';

    public const STUDY_TITLE = 'DEMO review of records management';

    private Organization $organization;

    private User $designer;

    private User $approver;

    private string $epoch;

    public function run(
        MandateService $mandates,
        FunctionService $functions,
        ServiceCatalogService $services,
        ProcessArchitectureService $processes,
        WorkRequirementService $requirements,
        TaskStandardService $standards,
        ResponsibilityService $responsibilities,
        DesignStudyService $studies,
        PositionProfileService $profiles,
        JobDescriptionService $jobDescriptions,
    ): void {
        $this->call([LegalInstrumentTypeSeeder::class, OrganizationDesignCatalogSeeder::class]);
        $this->organization = DemoDataset::requireOrganization(self::ORGANIZATION);
        $this->designer = DemoDataset::requireUser(self::DESIGNER_EMAIL);
        $this->approver = DemoDataset::requireUser(self::APPROVER_EMAIL);
        $this->epoch = DemoDataset::epoch()->toDateString();

        $mandate = $this->mandate($mandates, $functions);
        $functionsByCode = OrganizationFunction::query()->where('mandate_id', $mandate->id)->get()->keyBy('code');
        $servicesByCode = $this->services($services, $functionsByCode);
        $tasksByCode = $this->processes($processes, $requirements, $servicesByCode);
        $this->standard($standards, $tasksByCode['T-01']);
        $this->responsibilities($responsibilities, $functionsByCode, $servicesByCode);
        $this->study($studies, $responsibilities);
        $this->positions($profiles, $jobDescriptions, $tasksByCode);
    }

    private function mandate(MandateService $mandates, FunctionService $functions): InstitutionMandate
    {
        $mandate = InstitutionMandate::query()->where('organization_id', $this->organization->id)->where('code', self::MANDATE_CODE)->orderByDesc('version_no')->first();
        if ($mandate === null) {
            $mandate = $mandates->createDraft($this->designer, $this->organization->id, [
                'code' => self::MANDATE_CODE, 'title_en' => 'DEMO establishment mandate', 'title_am' => 'የማቋቋሚያ ሥልጣን (ማሳያ)',
                'description_en' => 'Synthetic demo mandate: establishes the organization and assigns its human resource and service delivery functions.',
                'legal_instrument_type_id' => LegalInstrumentType::query()->where('code', 'PROCLAMATION')->value('id'),
                'legal_instrument_number' => 'DEMO-1/2024', 'issuing_authority' => 'DEMO City Council', 'effective_from' => $this->epoch,
            ]);
        }
        $power = OrganizationPower::query()->where('mandate_id', $mandate->id)->where('code', 'PW-01')->first()
            ?? $functions->createPower($this->designer, $mandate, ['code' => 'PW-01', 'title_en' => 'Administer the organization', 'title_am' => 'ተቋሙን ማስተዳደር', 'sequence' => 1]);
        $sequence = 0;
        foreach ([
            'F-01' => ['title_en' => 'Human resource management', 'title_am' => 'የሰው ሀብት አስተዳደር'],
            'F-02' => ['title_en' => 'Service delivery to citizens', 'title_am' => 'ለዜጎች አገልግሎት መስጠት'],
        ] as $code => $definition) {
            $sequence++;
            if (! OrganizationFunction::query()->where('mandate_id', $mandate->id)->where('code', $code)->exists()) {
                $functions->createFunction($this->designer, $mandate, [...$definition, 'code' => $code, 'power_id' => $power->id, 'sequence' => $sequence]);
            }
        }
        if ($mandate->status === OrganizationDesignStatus::Draft) {
            $mandates->publish($this->approver, $mandate);
        }

        return $mandate;
    }

    /** @return array<string, Service> */
    private function services(ServiceCatalogService $services, $functionsByCode): array
    {
        $out = [];
        foreach ([
            'SRV-01' => ['name_en' => 'Employee records service', 'name_am' => 'የሠራተኛ መዛግብት አገልግሎት', 'beneficiary_type' => 'employee', 'delivery_channel' => 'in_person', 'function' => 'F-01', 'unit' => 'HR'],
            'SRV-02' => ['name_en' => 'Citizen service desk', 'name_am' => 'የዜጎች አገልግሎት ጠረጴዛ', 'beneficiary_type' => 'citizen', 'delivery_channel' => 'mixed', 'function' => 'F-02', 'unit' => 'SVC'],
        ] as $code => $definition) {
            $service = Service::query()->where('organization_id', $this->organization->id)->where('code', $code)->orderByDesc('version_no')->first();
            if ($service === null) {
                $service = $services->createDraft($this->designer, $this->organization->id, [
                    'code' => $code, 'name_en' => $definition['name_en'], 'name_am' => $definition['name_am'],
                    'beneficiary_type' => $definition['beneficiary_type'], 'delivery_channel' => $definition['delivery_channel'],
                    'owner_organization_unit_id' => $this->unit($definition['unit'])->id, 'effective_from' => $this->epoch,
                    'function_ids' => [$functionsByCode[$definition['function']]->id],
                ]);
            }
            if ($service->status === OrganizationDesignStatus::Draft) {
                $services->publish($this->approver, $service);
            }
            $out[$code] = $service;
        }

        return $out;
    }

    /**
     * @param  array<string, Service>  $servicesByCode
     * @return array<string, ProcessTask>
     */
    private function processes(ProcessArchitectureService $processes, WorkRequirementService $requirements, array $servicesByCode): array
    {
        $tasks = [];
        $definitions = [
            'PRC-01' => ['service' => 'SRV-01', 'unit' => 'HR', 'name_en' => 'Maintain employee records', 'name_am' => 'የሠራተኛ መዛግብትን ማስተዳደር', 'activity' => ['A-01', 'Keep personnel files', 'የሠራተኛ ፋይሎችን መያዝ'],
                'tasks' => [
                    ['T-01', 'Update employee record', 'የሠራተኛ መዝገብ ማዘመን', 'Updated personnel record', '2423', 'BACHELOR', 2],
                    ['T-02', 'Archive personnel file', 'የሠራተኛ ፋይል ማህደር ማስቀመጥ', 'Archived file with index entry', '4110', 'DIPLOMA', 1],
                ]],
            'PRC-02' => ['service' => 'SRV-02', 'unit' => 'SVC', 'name_en' => 'Handle service requests', 'name_am' => 'የአገልግሎት ጥያቄዎችን ማስተናገድ', 'activity' => ['A-02', 'Receive and resolve requests', 'ጥያቄዎችን መቀበልና መፍታት'],
                'tasks' => [
                    ['T-03', 'Register service request', 'የአገልግሎት ጥያቄ መመዝገብ', 'Registered request with reference number', '4110', 'DIPLOMA', 1],
                    ['T-04', 'Resolve service request', 'የአገልግሎት ጥያቄ መፍታት', 'Resolved request and notified citizen', '3353', 'BACHELOR', 2],
                ]],
        ];
        foreach ($definitions as $code => $definition) {
            $process = Process::query()->where('organization_id', $this->organization->id)->where('code', $code)->orderByDesc('version_no')->first();
            if ($process === null) {
                $process = $processes->createDraft($this->designer, $this->organization->id, [
                    'service_id' => $servicesByCode[$definition['service']]->id, 'code' => $code, 'name_en' => $definition['name_en'], 'name_am' => $definition['name_am'],
                    'process_type' => 'core', 'owner_organization_unit_id' => $this->unit($definition['unit'])->id, 'effective_from' => $this->epoch,
                ]);
                [$activityCode, $activityEn, $activityAm] = $definition['activity'];
                $activity = $processes->createActivity($this->designer, $process, ['code' => $activityCode, 'name_en' => $activityEn, 'name_am' => $activityAm, 'sequence' => 1]);
                foreach ($definition['tasks'] as $i => [$taskCode, $en, $am, $output, $isco, $education, $years]) {
                    $task = $processes->createTask($this->designer, $activity, ['code' => $taskCode, 'name_en' => $en, 'name_am' => $am, 'required_output' => $output, 'sequence' => $i + 1]);
                    $requirements->sync($this->designer, $task, [
                        'occupations' => [['occupation_id' => $this->occupationId($isco), 'is_primary' => true]],
                        'competencies' => [['competency_id' => $this->competencyId(), 'minimum_level_id' => CompetencyLevel::query()->where('code', 'PROFICIENT')->value('id'), 'is_mandatory' => true]],
                        'qualifications' => [['education_level_id' => EducationLevel::query()->where('code', $education)->value('id'), 'field_of_study' => $isco === '2423' ? 'Human resource management' : 'Public administration', 'experience_years' => $years, 'is_mandatory' => true]],
                    ]);
                }
            }
            if ($process->status === OrganizationDesignStatus::Draft) {
                $processes->publish($this->approver, $process);
            }
            foreach (ProcessTask::query()->where('process_id', $process->id)->get() as $task) {
                $tasks[$task->code] = $task;
            }
        }

        return $tasks;
    }

    private function standard(TaskStandardService $standards, ProcessTask $task): void
    {
        if (PositionServiceTaskStandard::query()->where('master_task_id', $task->id)->exists()) {
            return;
        }
        $standard = $standards->createDraft($this->designer, $task, ['planned_quantity' => '40', 'quantity_unit' => 'records', 'planned_time_minutes' => '15', 'effective_from' => now()->toDateString()]);
        $standards->approve($this->approver, $standard);
    }

    /** @param array<string, Service> $servicesByCode */
    private function responsibilities(ResponsibilityService $responsibilities, $functionsByCode, array $servicesByCode): void
    {
        $types = ResponsibilityType::query()->pluck('id', 'code');
        $processes = Process::query()->where('organization_id', $this->organization->id)->pluck('id', 'code');
        foreach ([
            'HR' => [['function', $functionsByCode['F-01']->id, 'ACCOUNTABLE'], ['service', $servicesByCode['SRV-01']->id, 'RESPONSIBLE'], ['process', $processes['PRC-01'], 'RESPONSIBLE']],
            'SVC' => [['function', $functionsByCode['F-02']->id, 'ACCOUNTABLE'], ['service', $servicesByCode['SRV-02']->id, 'RESPONSIBLE'], ['process', $processes['PRC-02'], 'RESPONSIBLE']],
            'ICT' => [['process', $processes['PRC-02'], 'SUPPORTING']],
        ] as $unitKey => $items) {
            $unit = $this->unit($unitKey);
            if (UnitResponsibility::query()->where('organization_unit_id', $unit->id)->exists()) {
                continue;
            }
            $responsibilities->sync($this->designer, $unit, array_map(fn ($i) => ['object_type' => $i[0], 'object_id' => $i[1], 'responsibility_type_id' => $types[$i[2]]], $items));
        }
    }

    private function study(DesignStudyService $studies, ResponsibilityService $responsibilities): void
    {
        if (OrganizationDesignStudy::query()->where('organization_id', $this->organization->id)->where('title_en', self::STUDY_TITLE)->exists()) {
            return;
        }
        $study = $studies->create($this->designer, $this->organization->id, [
            'title_en' => self::STUDY_TITLE, 'title_am' => 'የመዛግብት አስተዳደር ግምገማ (ማሳያ)', 'study_type' => 'review',
            'reason' => 'Records work is split between the HR and Service Delivery directorates; a dedicated team is proposed.', 'started_at' => $this->epoch,
        ]);
        $proposal = $studies->createProposal($this->designer, $study, [
            'proposal_type' => 'create', 'name_en' => 'Records Team', 'name_am' => 'የመዛግብት ቡድን',
            'proposed_unit_type_id' => OrganizationUnitType::query()->where('code', 'team')->value('id'), 'parent_existing_unit_id' => $this->unit('SVC')->id,
            'design_rationale' => 'One team owns the records process end to end.', 'sequence' => 1,
        ]);
        $responsibilities->sync($this->designer, $proposal, [['object_type' => 'process', 'object_id' => Process::query()->where('organization_id', $this->organization->id)->where('code', 'PRC-01')->value('id'), 'responsibility_type_id' => ResponsibilityType::query()->where('code', 'SUPPORTING')->value('id')]]);
        $studies->submit($this->designer, $study);
        $studies->approve($this->approver, $study);
    }

    /** @param array<string, ProcessTask> $tasks */
    private function positions(PositionProfileService $profiles, JobDescriptionService $jobDescriptions, array $tasks): void
    {
        $head = DemoDataset::position($this->organization, 'P-HEAD');
        foreach ([
            'P-HR' => ['links' => [['task', 'T-01', true], ['task', 'T-02', false], ['process', 'PRC-01', false]], 'publish' => true,
                'purpose_en' => 'Keeps the organization\'s employee records complete, current and retrievable.', 'purpose_am' => 'የተቋሙን የሠራተኛ መዛግብት ሙሉ፣ ወቅታዊና ተደራሽ ያደርጋል።'],
            'P-CLERK' => ['links' => [['task', 'T-03', true], ['task', 'T-04', false]], 'publish' => false,
                'purpose_en' => 'Registers and files citizen service requests.', 'purpose_am' => 'የዜጎችን የአገልግሎት ጥያቄዎች ይመዘግባል፣ ያስቀምጣል።'],
        ] as $key => $definition) {
            $position = DemoDataset::position($this->organization, $key) ?? throw new RuntimeException("Demo position {$key} of ".self::ORGANIZATION.' is missing.');
            if (! PositionWorkLink::query()->where('position_id', $position->id)->exists()) {
                $profiles->syncWorkLinks($this->designer, $position, array_map(fn ($l) => [
                    'object_type' => $l[0], 'object_id' => $l[0] === 'task' ? $tasks[$l[1]]->id : Process::query()->where('organization_id', $this->organization->id)->where('code', $l[1])->value('id'), 'is_primary' => $l[2],
                ], $definition['links']));
            }
            if (! PositionCompetencyRequirement::query()->where('position_id', $position->id)->exists()) {
                $profiles->deriveRequirements($this->designer, $position);
            }
            $profile = PositionProfile::query()->where('position_id', $position->id)->orderByDesc('version_no')->first();
            if ($profile === null) {
                $profile = $profiles->createDraft($this->designer, $position, [
                    'purpose_en' => $definition['purpose_en'], 'purpose_am' => $definition['purpose_am'], 'reports_to_position_id' => $head?->id,
                    'working_conditions_en' => 'Office based; occasional field visits to branch offices.', 'working_conditions_am' => 'በቢሮ ውስጥ፤ አልፎ አልፎ ወደ ቅርንጫፍ ጽ/ቤቶች የመስክ ጉብኝት።',
                    'authorities_en' => 'Approves routine record corrections within the directorate.', 'authorities_am' => 'በዳይሬክቶሬቱ ውስጥ መደበኛ የመዝገብ ማስተካከያዎችን ያጸድቃል።',
                    'effective_from' => $this->epoch,
                ]);
            }
            if (! $definition['publish']) {
                continue;
            }
            if ($profile->status === OrganizationDesignStatus::Draft) {
                $profiles->publish($this->approver, $profile);
            }
            if (! JobDescription::query()->where('position_id', $position->id)->exists()) {
                $jd = $jobDescriptions->createDraft($this->designer, $position, ['effective_from' => $this->epoch]);
                $jobDescriptions->submit($this->designer, $jd);
                $jobDescriptions->approve($this->approver, $jd);
                $jobDescriptions->publish($this->approver, $jd);
            }
        }
    }

    private function unit(string $key): OrganizationUnit
    {
        return DemoDataset::unit($this->organization, $key) ?? throw new RuntimeException("Demo unit {$key} of ".self::ORGANIZATION.' is missing.');
    }

    private function occupationId(string $isco): string
    {
        return Occupation::query()->where('isco_code', $isco)->value('id')
            ?? Occupation::query()->where('is_active', true)->orderBy('code')->value('id')
            ?? throw new RuntimeException('No occupation exists; OccupationSeeder must run first.');
    }

    /** An existing active competency, else one demo competency in a demo framework (reference data is reused, never overwritten). */
    private function competencyId(): string
    {
        $existing = Competency::query()->where('is_active', true)->orderBy('code')->value('id');
        if ($existing !== null) {
            return $existing;
        }
        $framework = CompetencyFramework::query()->firstOrCreate(['code' => 'DEMO-CF'], ['name_en' => 'DEMO competency framework', 'name_am' => 'የብቃት ማዕቀፍ (ማሳያ)', 'is_active' => true]);

        return Competency::query()->firstOrCreate(['framework_id' => $framework->id, 'code' => 'DEMO-RECORDS'], ['name_en' => 'Records management', 'name_am' => 'የመዛግብት አስተዳደር', 'is_active' => true])->id;
    }
}
