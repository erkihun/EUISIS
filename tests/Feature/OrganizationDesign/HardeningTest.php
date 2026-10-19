<?php

declare(strict_types=1);

use App\Enums\OrganizationDesignStatus;
use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Models\InstitutionMandate;
use App\Models\LegalInstrumentType;
use App\Models\ManpowerPlan;
use App\Models\ManpowerPlanLine;
use App\Models\Occupation;
use App\Models\Organization;
use App\Models\OrganizationDesignReportExport;
use App\Models\OrganizationDesignStudy;
use App\Models\OrganizationFunction;
use App\Models\OrganizationPower;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\Position;
use App\Models\PositionProfile;
use App\Models\PositionService;
use App\Models\PositionServiceSubService;
use App\Models\PositionServiceTask;
use App\Models\PositionServiceTaskStandard;
use App\Models\PositionWorkLink;
use App\Models\Process;
use App\Models\ProcessActivity;
use App\Models\ProcessTask;
use App\Models\Service;
use App\Models\UnitDesignProposal;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Models\WorkforcePlanningMethodology;
use App\Services\OrganizationDesign\JobDescriptionService;
use App\Services\OrganizationDesign\MappingWorkspaceService;
use App\Services\OrganizationDesign\OrganizationDesignGapService;
use App\Services\OrganizationDesign\OrganizationDesignReportService;
use App\Services\OrganizationDesign\PositionProfileService;
use App\Services\OrganizationDesign\TraceabilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * Organization Design & Workforce Planning, phase 11: hardening. Every
 * route of the domain is swept for IDOR with a fully permitted but scoped
 * user, the heavy reads carry query-count ceilings, and the indexes the
 * hot paths rely on exist on both drivers. Brief §89–93, 101–103, 122–123;
 * tests 80–82.
 */

beforeEach(function (): void {
    $names = array_column(require base_path('database/seeders/data/organization-design-permissions.php'), 'name');
    foreach ([...$names, 'positions.view', 'work_standards.manage', 'work_standards.approve', 'organization-units.request_create', 'organization-units.request_update', 'organization-units.request_move', 'organization-units.request_deactivate'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web');
    Role::findOrCreate('Design Everything', 'web')->syncPermissions(Permission::query()->where('name', 'like', '%')->pluck('name')->all());
    LegalInstrumentType::query()->firstOrCreate(['code' => 'PROCLAMATION'], ['name_en' => 'Proclamation', 'sort_order' => 10]);
});

function hdOrganization(string $suffix): Organization
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'HD-BUREAU'], ['name_en' => 'HD Bureau', 'is_demo' => false]);

    return Organization::query()->create([
        'organization_type_id' => $type->id, 'code' => 'HD-'.$suffix.'-'.uniqid(), 'name_en' => 'HD Org '.$suffix,
        'status' => OrganizationStatus::Active, 'effective_from' => now()->subYear()->toDateString(), 'is_demo' => false,
    ]);
}

function hdUser(Organization $organization): User
{
    $user = User::factory()->create();
    $user->assignRole('Design Everything');
    UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => $organization->id, 'scope_type' => OrganizationScopeType::Self, 'is_active' => true, 'effective_from' => now()->subDay()->toDateString()]);

    return $user->fresh();
}

/** One record of every organization-scoped kind the routes take, all in $organization. */
function hdFixtures(Organization $organization): array
{
    $o = $organization->id;
    $unitType = OrganizationUnitType::query()->firstOrCreate(['code' => 'HD-DIR'], ['name_en' => 'Directorate', 'is_active' => true]);
    $unit = OrganizationUnit::query()->create(['organization_id' => $o, 'organization_unit_type_id' => $unitType->id, 'code' => 'U-'.substr(uniqid(), -4), 'name_en' => 'Unit', 'unit_type' => 'directorate', 'status' => 'active']);
    $mandate = InstitutionMandate::query()->create(['organization_id' => $o, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => 'MND-'.substr(uniqid(), -4), 'title_en' => 'Mandate', 'legal_instrument_type_id' => LegalInstrumentType::query()->value('id'), 'legal_instrument_number' => '1/2020', 'effective_from' => '2020-01-01', 'status' => OrganizationDesignStatus::Draft]);
    $power = OrganizationPower::query()->create(['organization_id' => $o, 'mandate_id' => $mandate->id, 'code' => 'PW-'.substr(uniqid(), -4), 'title_en' => 'Power']);
    $function = OrganizationFunction::query()->create(['organization_id' => $o, 'mandate_id' => $mandate->id, 'code' => 'F-'.substr(uniqid(), -4), 'title_en' => 'Function', 'path' => '', 'depth' => 0]);
    $function->forceFill(['path' => $function->id])->save();
    $service = Service::query()->create(['organization_id' => $o, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => 'SRV-'.substr(uniqid(), -4), 'name_en' => 'Service', 'effective_from' => '2021-01-01', 'status' => OrganizationDesignStatus::Draft]);
    $process = Process::query()->create(['organization_id' => $o, 'service_id' => $service->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => 'PRC-'.substr(uniqid(), -4), 'name_en' => 'Process', 'process_type' => 'core', 'effective_from' => '2022-01-01', 'status' => OrganizationDesignStatus::Draft]);
    $activity = ProcessActivity::query()->create(['process_id' => $process->id, 'organization_id' => $o, 'code' => 'A-'.substr(uniqid(), -4), 'name_en' => 'Activity', 'path' => '', 'depth' => 0]);
    $activity->forceFill(['path' => $activity->id])->save();
    $task = ProcessTask::query()->create(['activity_id' => $activity->id, 'process_id' => $process->id, 'organization_id' => $o, 'code' => 'T-'.substr(uniqid(), -4), 'name_en' => 'Task']);
    $standard = PositionServiceTaskStandard::query()->create(['master_task_id' => $task->id, 'organization_id' => $o, 'version_no' => 1, 'status' => 'draft', 'planned_quantity' => '10', 'quantity_unit' => 'items', 'effective_from' => '2024-01-01']);
    $position = Position::query()->create(['organization_id' => $o, 'organization_unit_id' => $unit->id, 'job_position_code' => 'P-'.substr(uniqid(), -4), 'title_en' => 'Officer', 'grade_level' => 'IX', 'is_active' => true]);
    $positionService = PositionService::query()->create(['organization_id' => $o, 'position_id' => $position->id, 'service_no' => (string) random_int(1, 999999), 'name_en' => 'Legacy', 'is_active' => true]);
    $sub = PositionServiceSubService::query()->create(['position_service_id' => $positionService->id, 'organization_id' => $o, 'code' => 'SS-'.substr(uniqid(), -4), 'name_en' => 'Sub']);
    $positionTask = PositionServiceTask::query()->create(['sub_service_id' => $sub->id, 'position_service_id' => $positionService->id, 'organization_id' => $o, 'code' => 'PT-'.substr(uniqid(), -4), 'name_en' => 'Legacy task', 'is_active' => true]);
    $designer = User::factory()->create();
    $approver = User::factory()->create();
    $profiles = app(PositionProfileService::class);
    $profiles->syncWorkLinks($designer, $position, [['object_type' => 'task', 'object_id' => $task->id]]);
    $published = $profiles->createDraft($designer, $position, ['purpose_en' => 'Purpose', 'effective_from' => '2025-01-01']);
    $profiles->publish($approver, $published);
    $jd = app(JobDescriptionService::class)->createDraft($designer, $position, ['effective_from' => '2025-06-01']);
    $profile = $profiles->newVersion($designer, $published, ['effective_from' => '2026-01-01']);
    $study = OrganizationDesignStudy::query()->create(['organization_id' => $o, 'reference_number' => 'ODS-'.substr(uniqid(), -6), 'title_en' => 'Study', 'study_type' => 'review', 'status' => OrganizationDesignStatus::Draft, 'prepared_by' => $designer->id, 'created_by' => $designer->id]);
    $proposal = UnitDesignProposal::query()->create(['study_id' => $study->id, 'organization_id' => $o, 'proposal_type' => 'create', 'name_en' => 'New unit', 'proposed_unit_type_id' => $unitType->id, 'status' => 'proposed']);
    $methodology = WorkforcePlanningMethodology::query()->create(['lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => 'HD-'.substr(uniqid(), -4), 'name_en' => 'Fixed', 'calculation_type' => 'FIXED_MINIMUM', 'parameters' => ['minimum_positions' => '1'], 'rounding_rule' => 'ceil', 'status' => OrganizationDesignStatus::Published, 'effective_from' => '2024-01-01']);
    $plan = ManpowerPlan::query()->create(['reference_number' => 'MPP-'.substr(uniqid(), -6), 'organization_id' => $o, 'methodology_id' => $methodology->id, 'title_en' => 'Plan', 'planning_period_start' => '2027-01-01', 'planning_period_end' => '2027-12-31', 'status' => OrganizationDesignStatus::Draft, 'prepared_by' => $designer->id]);
    $occupation = Occupation::query()->create(['code' => 'HD-'.substr(uniqid(), -4), 'isco_code' => '26'.random_int(10, 99), 'name_en' => 'Occupation', 'is_active' => true]);
    $line = ManpowerPlanLine::query()->create(['plan_id' => $plan->id, 'organization_id' => $o, 'organization_unit_id' => $unit->id, 'occupation_id' => $occupation->id, 'inputs' => [], 'sequence' => 1]);
    $export = OrganizationDesignReportExport::query()->create(['organization_id' => $o, 'report' => 'coverage', 'format' => 'csv', 'status' => 'ready', 'disk' => 'local', 'path' => 'organization-design/exports/none.csv', 'filename' => 'none.csv', 'requested_by' => $designer->id]);
    $source = \App\Models\LegalSource::query()->create(['organization_id' => $o, 'code' => 'LS-'.substr(uniqid(), -4), 'title_en' => 'Source', 'source_type_id' => LegalInstrumentType::query()->value('id'), 'status' => 'active', 'created_by' => $designer->id]);
    $attachment = \App\Models\LegalSourceAttachment::query()->create(['legal_source_id' => $source->id, 'disk' => 'local', 'path' => 'organization-design/legal-sources/none.pdf', 'original_name' => 'none.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1, 'sha256' => str_repeat('0', 64), 'uploaded_by' => $designer->id, 'created_at' => now()]);
    $citation = \App\Models\LegalSourceCitation::query()->create(['legal_source_id' => $source->id, 'subject_type' => $mandate->getMorphClass(), 'subject_id' => $mandate->id, 'created_by' => $designer->id, 'created_at' => now()]);
    $baseline = \App\Models\LegacyBaseline::query()->create(['organization_id' => $o, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'reference_number' => 'LOB-'.substr(uniqid(), -8), 'title_en' => 'Baseline', 'status' => OrganizationDesignStatus::Draft, 'prepared_by' => $designer->id, 'created_by' => $designer->id]);

    return [
        'organization' => $organization, 'unit' => $unit, 'mandate' => $mandate, 'power' => $power, 'function' => $function, 'service' => $service, 'process' => $process, 'activity' => $activity, 'task' => $task,
        'source' => $source, 'attachment' => $attachment, 'citation' => $citation, 'baseline' => $baseline,
        'standard' => $standard, 'position' => $position, 'positionTask' => $positionTask, 'positionService' => $positionService, 'profile' => $profile, 'jobDescription' => $jd, 'study' => $study, 'proposal' => $proposal, 'plan' => $plan, 'line' => $line, 'export' => $export,
    ];
}

// 80 ─────────────────────────────────────────────────────────────────────────
test('every organization-design route refuses a fully permitted user of another organization (IDOR sweep)', function (): void {
    $mine = hdOrganization('A');
    $theirs = hdOrganization('B');
    $me = hdUser($mine);
    $f = hdFixtures($theirs);
    $me->refresh();

    // City-level catalog parameters are not organization-scoped; their permission tests live in their own phases.
    $cityLevel = ['classification', 'competency', 'level', 'type', 'educationLevel', 'occupation', 'methodology', 'profession', 'specialization', 'responsibilityType', 'framework'];
    $subjects = ['io.sync' => ['activity', $f['activity']], 'requirements.sync' => ['task', $f['task']], 'responsibilities.sync' => ['unit', $f['unit']]];
    $swept = 0;
    $skipped = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();
        if (! str_starts_with($name, 'organization-design.')) {
            continue;
        }
        $short = substr($name, strlen('organization-design.'));
        $params = $route->parameterNames();
        $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');
        $values = [];
        $cityOnly = $params !== [] && collect($params)->every(fn ($p) => in_array($p, $cityLevel, true));
        if ($cityOnly) {
            $skipped[] = $short;

            continue;
        }
        foreach ($params as $param) {
            if (in_array($param, ['subjectType', 'subject'], true)) {
                $values['subjectType'] = $subjects[$short][0];
                $values['subject'] = $subjects[$short][1]->getKey();
            } elseif ($param === 'sectionKey') {
                $values[$param] = 'authorities';
            } elseif ($param === 'step') {
                // A wizard step of a foreign baseline: the baseline itself carries the scope.
                $values[$param] = 'mandates';
            } elseif ($param === 'kind') {
                // The lookup endpoint: an organization-scoped kind without the organization in scope.
                $values[$param] = 'tasks';
            } elseif (isset($f[$param])) {
                $values[$param] = $f[$param]->getKey();
            } elseif (in_array($param, $cityLevel, true)) {
                $values[$param] = (string) Str::uuid();
            } else {
                test()->fail("No foreign fixture for route parameter {{$param}} of {$name}; add one to hdFixtures().");
            }
        }
        $body = ['organization_id' => $theirs->id, 'master_task_id' => $f['task']->id, 'report' => 'coverage', 'format' => 'csv'];
        $url = route($name, $params === [] && $method === 'GET' ? ['organization_id' => $theirs->id, 'report' => 'coverage'] : $values);
        $response = $this->actingAs($me)->from('/')->call($method, $url, $method === 'GET' ? [] : $body);
        $status = $response->getStatusCode();
        if ($params === []) {
            // Lists and stores: either refused outright, refused by validation, or served without a trace of the foreign organization.
            expect($status)->toBeIn([403, 422, 302, 200], "{$method} {$short} answered {$status}");
            if ($status === 200) {
                expect($response->getContent())->not->toContain($theirs->id, "{$short} leaks organization {$theirs->code}");
                expect($response->getContent())->not->toContain($f['position']->job_position_code, "{$short} leaks a foreign position");
            }
            if ($status === 302) {
                expect($response->getSession()->get('errors')?->any())->toBeTrue("{$method} {$short} accepted a foreign organization");
            }
        } else {
            expect($status)->toBeIn([403, 404], "{$method} {$short} answered {$status} for a foreign record");
        }
        $swept++;
    }
    expect($swept)->toBeGreaterThan(90)->and($skipped)->not->toBeEmpty();
});

// 81 ─────────────────────────────────────────────────────────────────────────
test('the heavy reads stay within a fixed number of queries whatever the size of the organization (no N+1)', function (): void {
    $organization = hdOrganization('C');
    $f = hdFixtures($organization);
    $unit = $f['unit'];
    $tasks = collect(range(1, 40))->map(fn ($i) => ProcessTask::query()->create(['activity_id' => $f['activity']->id, 'process_id' => $f['process']->id, 'organization_id' => $organization->id, 'code' => 'TQ-'.$i, 'name_en' => 'Task '.$i]));
    foreach (range(1, 120) as $i) {
        $position = Position::query()->create(['organization_id' => $organization->id, 'organization_unit_id' => $unit->id, 'job_position_code' => 'PQ-'.$i, 'title_en' => 'Officer '.$i, 'is_active' => true]);
        PositionWorkLink::query()->create(['position_id' => $position->id, 'organization_id' => $organization->id, 'object_type' => 'task', 'object_id' => $tasks[$i % 40]->id]);
        if ($i % 3 === 0) {
            PositionProfile::query()->create(['position_id' => $position->id, 'organization_id' => $organization->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'status' => OrganizationDesignStatus::Published, 'effective_from' => '2025-01-01']);
        }
    }
    $user = hdUser($organization);

    $count = function (callable $run): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $reports = app(OrganizationDesignReportService::class);
    expect($count(fn () => $reports->run('position_readiness', $user, ['organization_id' => $organization->id])))->toBeLessThanOrEqual(25)
        ->and($count(fn () => $reports->run('coverage', $user, ['organization_id' => $organization->id])))->toBeLessThanOrEqual(30)
        ->and($count(fn () => app(TraceabilityService::class)->rows($organization->id)))->toBeLessThanOrEqual(15)
        ->and($count(fn () => app(OrganizationDesignGapService::class)->gaps($organization->id)))->toBeLessThanOrEqual(30)
        ->and($count(fn () => app(MappingWorkspaceService::class)->unmapped($organization->id)))->toBeLessThanOrEqual(15)
        ->and($count(fn () => $this->actingAs($user)->get(route('organization-design.position-design.index', ['organization_id' => $organization->id]))->assertOk()))->toBeLessThanOrEqual(40);
});

// 82 ─────────────────────────────────────────────────────────────────────────
test('the indexes the hot paths rely on exist', function (): void {
    foreach ([
        'process_activities' => 'process_activities_org_idx',
        'process_tasks' => 'process_tasks_org_active_idx',
        'work_occupation_requirements' => 'work_occupation_requirements_org_task_idx',
        'work_competency_requirements' => 'work_competency_requirements_org_task_idx',
        'work_qualification_requirements' => 'work_qualification_requirements_org_task_idx',
        'unit_design_proposals' => 'unit_design_proposals_org_idx',
        'manpower_plan_lines' => 'manpower_plan_lines_org_idx',
        'position_requirements' => 'position_requirements_org_idx',
        'position_profiles' => 'position_profiles_org_status_idx',
        'position_work_links' => 'position_work_links_org_position_idx',
        'position_competency_requirements' => 'pcr_org_idx',
        'position_qualification_requirements' => 'pqr_org_idx',
        'job_descriptions' => 'job_descriptions_profile_idx',
        'organization_design_report_exports' => 'od_report_exports_org_idx',
        'position_service_tasks' => 'position_service_tasks_org_master_idx',
    ] as $table => $index) {
        expect(Schema::hasIndex($table, $index))->toBeTrue("{$table} lacks {$index}");
    }
});
