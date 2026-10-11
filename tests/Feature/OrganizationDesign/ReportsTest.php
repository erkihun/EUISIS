<?php

declare(strict_types=1);

use App\Enums\OrganizationDesignStatus;
use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Models\Competency;
use App\Models\CompetencyFramework;
use App\Models\CompetencyLevel;
use App\Models\InstitutionMandate;
use App\Models\JobDescription;
use App\Models\LegalInstrumentType;
use App\Models\Occupation;
use App\Models\Organization;
use App\Models\OrganizationDesignReportExport;
use App\Models\OrganizationFunction;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\Position;
use App\Models\PositionServiceTaskStandard;
use App\Models\Process;
use App\Models\ProcessActivity;
use App\Models\ProcessTask;
use App\Models\Service;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Models\WorkCompetencyRequirement;
use App\Services\OrganizationDesign\JobDescriptionService;
use App\Services\OrganizationDesign\OrganizationDesignGapService;
use App\Services\OrganizationDesign\OrganizationDesignReportService;
use App\Services\OrganizationDesign\PositionProfileService;
use App\Services\OrganizationDesign\TraceabilityService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * Organization Design & Workforce Planning, phase 9: traceability from the
 * mandate down to the job description, the gap report, tabular reports with
 * organization scope, and queued CSV/XLSX exports stored privately.
 * Brief §76–78, 88, 98–99, 104–105; tests 69–76.
 */

beforeEach(function (): void {
    foreach (['organization-design-reports.view', 'organization-design-reports.export', 'position-profiles.viewAny', 'job-descriptions.viewAny'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web');
    Role::findOrCreate('Design Reporter', 'web')->syncPermissions(['organization-design-reports.view']);
    Role::findOrCreate('Design Exporter', 'web')->syncPermissions(['organization-design-reports.view', 'organization-design-reports.export']);
    Role::findOrCreate('Nobody', 'web')->syncPermissions([]);
    CompetencyLevel::query()->firstOrCreate(['code' => 'PROFICIENT'], ['name_en' => 'Proficient', 'rank' => 3, 'is_active' => true]);
});

function rpOrganization(string $suffix): Organization
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'RP-BUREAU'], ['name_en' => 'RP Bureau', 'is_demo' => false]);

    return Organization::query()->create([
        'organization_type_id' => $type->id, 'code' => 'RP-'.$suffix.'-'.uniqid(), 'name_en' => 'RP Org '.$suffix,
        'status' => OrganizationStatus::Active, 'effective_from' => now()->subYear()->toDateString(), 'is_demo' => false,
    ]);
}

function rpUser(Organization $organization, string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => $organization->id, 'scope_type' => OrganizationScopeType::Self, 'is_active' => true, 'effective_from' => now()->subDay()->toDateString()]);

    return $user->fresh();
}

function rpUnit(Organization $organization, string $code): OrganizationUnit
{
    $type = OrganizationUnitType::query()->firstOrCreate(['code' => 'RP-DIR'], ['name_en' => 'Directorate', 'is_active' => true]);

    return OrganizationUnit::query()->create(['organization_id' => $organization->id, 'organization_unit_type_id' => $type->id, 'code' => $code, 'name_en' => 'Unit '.$code, 'unit_type' => 'directorate', 'status' => 'active']);
}

function rpPosition(Organization $organization, OrganizationUnit $unit, string $code = 'P-1', string $title = 'Senior Accountant'): Position
{
    static $n = 0;
    $isco = '25'.str_pad((string) (++$n), 2, '0', STR_PAD_LEFT);
    $occupation = Occupation::query()->create(['code' => 'RP-'.$isco.'-'.substr(uniqid(), -3), 'isco_code' => $isco, 'name_en' => 'Accountant', 'is_active' => true]);

    return Position::query()->create(['organization_id' => $organization->id, 'organization_unit_id' => $unit->id, 'occupation_id' => $occupation->id, 'job_position_code' => $code.'-'.substr(uniqid(), -4), 'title_en' => $title, 'grade_level' => 'IX', 'is_active' => true]);
}

function rpMandate(Organization $organization): InstitutionMandate
{
    $type = LegalInstrumentType::query()->firstOrCreate(['code' => 'PROCLAMATION'], ['name_en' => 'Proclamation', 'sort_order' => 10]);

    return InstitutionMandate::query()->create([
        'organization_id' => $organization->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => 'MND-'.substr(uniqid(), -4),
        'title_en' => 'Mandate', 'legal_instrument_type_id' => $type->id, 'legal_instrument_number' => '1/2020', 'effective_from' => '2020-01-01', 'status' => OrganizationDesignStatus::Published,
    ]);
}

function rpFunction(Organization $organization, InstitutionMandate $mandate, string $code): OrganizationFunction
{
    $function = OrganizationFunction::query()->create(['organization_id' => $organization->id, 'mandate_id' => $mandate->id, 'code' => $code, 'title_en' => 'Function '.$code, 'path' => '', 'depth' => 0]);
    $function->forceFill(['path' => $function->id])->save();

    return $function;
}

function rpService(Organization $organization, string $code, ?OrganizationFunction $function = null): Service
{
    $service = Service::query()->create(['organization_id' => $organization->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => $code, 'name_en' => 'Service '.$code, 'effective_from' => '2021-01-01', 'status' => OrganizationDesignStatus::Published]);
    if ($function) {
        $service->functions()->attach($function->id, ['id' => (string) Str::uuid(), 'organization_id' => $organization->id]);
    }

    return $service;
}

function rpProcess(Organization $organization, Service $service, string $code): Process
{
    return Process::query()->create(['organization_id' => $organization->id, 'service_id' => $service->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => $code, 'name_en' => 'Process '.$code, 'process_type' => 'core', 'effective_from' => '2022-01-01', 'status' => OrganizationDesignStatus::Published]);
}

function rpTask(Organization $organization, Process $process, string $code, bool $withStandard = true, bool $withRequirements = true): ProcessTask
{
    $activity = ProcessActivity::query()->create(['process_id' => $process->id, 'organization_id' => $organization->id, 'code' => 'A-'.$code, 'name_en' => 'Activity '.$code, 'path' => '', 'depth' => 0]);
    $activity->forceFill(['path' => $activity->id])->save();
    $task = ProcessTask::query()->create(['activity_id' => $activity->id, 'process_id' => $process->id, 'organization_id' => $organization->id, 'code' => $code, 'name_en' => 'Task '.$code, 'required_output' => 'Output']);
    if ($withStandard) {
        PositionServiceTaskStandard::query()->create(['master_task_id' => $task->id, 'organization_id' => $organization->id, 'version_no' => 1, 'status' => 'approved', 'planned_quantity' => '10', 'quantity_unit' => 'items', 'effective_from' => '2024-01-01']);
    }
    if ($withRequirements) {
        $framework = CompetencyFramework::query()->firstOrCreate(['code' => 'RP-FW'], ['name_en' => 'RP framework', 'is_active' => true]);
        $competency = Competency::query()->create(['framework_id' => $framework->id, 'code' => 'NUM-'.substr(uniqid(), -3), 'name_en' => 'Numeracy', 'is_active' => true]);
        WorkCompetencyRequirement::query()->create(['organization_id' => $organization->id, 'task_id' => $task->id, 'competency_id' => $competency->id, 'minimum_level_id' => CompetencyLevel::query()->where('code', 'PROFICIENT')->value('id'), 'is_mandatory' => true]);
    }

    return $task;
}

/** A fully traced chain: mandate → function → service → process → activity → task → position (linked) → published JD. */
function rpChain(Organization $organization, Position $position, string $suffix = '1'): ProcessTask
{
    $mandate = rpMandate($organization);
    $function = rpFunction($organization, $mandate, 'F-'.$suffix);
    $service = rpService($organization, 'S-'.$suffix, $function);
    $process = rpProcess($organization, $service, 'PRC-'.$suffix);
    $task = rpTask($organization, $process, 'T-'.$suffix);
    $designer = User::factory()->create();
    $approver = User::factory()->create();
    $profiles = app(PositionProfileService::class);
    $profiles->syncWorkLinks($designer, $position, [['object_type' => 'task', 'object_id' => $task->id, 'is_primary' => true]]);
    $profile = $profiles->createDraft($designer, $position, ['purpose_en' => 'Keeps the books', 'effective_from' => '2025-01-01']);
    $profiles->publish($approver, $profile);
    $jds = app(JobDescriptionService::class);
    $jd = $jds->createDraft($designer, $position, ['effective_from' => '2025-06-01']);
    $jds->submit($designer, $jd);
    $jds->approve($approver, $jd);
    $jds->publish($approver, $jd);

    return $task;
}

// 69 ─────────────────────────────────────────────────────────────────────────
test('the gap report names what is designed but not carried through — and says nothing about a fully traced chain', function (): void {
    $organization = rpOrganization('A');
    $unit = rpUnit($organization, 'U-1');
    $traced = rpPosition($organization, $unit, 'P-OK');
    rpChain($organization, $traced);

    $mandate = rpMandate($organization);
    $orphanFunction = rpFunction($organization, $mandate, 'F-ORPHAN');
    rpService($organization, 'S-ORPHAN');
    $second = rpService($organization, 'S-2');
    rpProcess($organization, $second, 'PRC-EMPTY');
    rpTask($organization, rpProcess($organization, $second, 'PRC-2'), 'T-BARE', withStandard: false, withRequirements: false);
    $bare = rpPosition($organization, $unit, 'P-BARE');
    $emptyUnit = rpUnit($organization, 'U-EMPTY');

    $report = app(OrganizationDesignGapService::class)->gaps($organization->id);
    $codes = collect($report['findings'])->map(fn ($f) => $f['code'].':'.($f['subject']['code'] ?? ''))->all();

    expect($report['ok'])->toBeTrue()
        ->and($codes)->toContain('function_without_services:F-ORPHAN')
        ->and($codes)->toContain('service_without_processes:S-ORPHAN')
        ->and($codes)->toContain('process_without_tasks:PRC-EMPTY')
        ->and($codes)->toContain('task_without_standard:T-BARE')
        ->and($codes)->toContain('task_without_requirements:T-BARE')
        ->and($codes)->toContain('position_without_work_links:'.$bare->job_position_code)
        ->and($codes)->toContain('position_without_profile:'.$bare->job_position_code)
        ->and($codes)->toContain('position_without_job_description:'.$bare->job_position_code)
        ->and($codes)->toContain('unit_without_positions:U-EMPTY')
        ->and($codes)->not->toContain('position_without_profile:'.$traced->job_position_code)
        ->and($codes)->not->toContain('position_without_job_description:'.$traced->job_position_code)
        ->and($codes)->not->toContain('function_without_services:F-1')
        ->and(collect($report['findings'])->firstWhere('code', 'task_without_requirements')['severity'])->toBe('info')
        ->and(collect($report['findings'])->firstWhere('code', 'position_without_job_description')['severity'])->toBe('warning');
    expect($report['findings'][0]['message'])->toBeString()->not->toContain('organization-design.validation');
});

// 70 ─────────────────────────────────────────────────────────────────────────
test('traceability follows one task from its mandate to the job description in force, and the report lists the whole chain per task', function (): void {
    $organization = rpOrganization('B');
    $unit = rpUnit($organization, 'U-1');
    $position = rpPosition($organization, $unit);
    $task = rpChain($organization, $position);
    $jd = JobDescription::query()->where('position_id', $position->id)->firstOrFail();

    $trace = app(TraceabilityService::class)->trace('task', $task->id);
    expect($trace['subject']['code'])->toBe('T-1')
        ->and($trace['upstream'])->toHaveCount(5)
        ->and(collect($trace['upstream'])->pluck('type')->all())->toBe(['mandate', 'function', 'service', 'process', 'activity'])
        ->and(collect($trace['downstream'])->where('type', 'position')->first()['code'])->toBe($position->job_position_code)
        ->and(collect($trace['downstream'])->where('type', 'job_description')->first()['code'])->toBe($jd->reference_number);

    $positionTrace = app(TraceabilityService::class)->trace('position', $position->id);
    expect(collect($positionTrace['upstream'])->pluck('type')->all())->toContain('task', 'process', 'service', 'function', 'mandate');

    $reporter = rpUser($organization, 'Design Reporter');
    $result = app(OrganizationDesignReportService::class)->run('traceability', $reporter, ['organization_id' => $organization->id]);
    expect($result['columns'])->toContain('mandate_code', 'function_code', 'service_code', 'process_code', 'activity_code', 'task_code', 'positions', 'job_descriptions')
        ->and($result['rows'])->toHaveCount(1)
        ->and($result['rows'][0]['task_code'])->toBe('T-1')
        ->and($result['rows'][0]['function_code'])->toBe('F-1')
        ->and($result['rows'][0]['positions'])->toContain($position->job_position_code)
        ->and($result['rows'][0]['job_descriptions'])->toContain($jd->reference_number);
});

// 71 ─────────────────────────────────────────────────────────────────────────
test('the coverage report counts the design per organization and the readiness report grades each position', function (): void {
    $organization = rpOrganization('C');
    $unit = rpUnit($organization, 'U-1');
    $traced = rpPosition($organization, $unit, 'P-OK');
    rpChain($organization, $traced);
    $bare = rpPosition($organization, $unit, 'P-BARE');
    $reporter = rpUser($organization, 'Design Reporter');
    $reports = app(OrganizationDesignReportService::class);

    $coverage = $reports->run('coverage', $reporter, ['organization_id' => $organization->id]);
    expect($coverage['rows'])->toHaveCount(1);
    $row = $coverage['rows'][0];
    expect($row['organization_code'])->toBe($organization->code)
        ->and($row['mandates'])->toBe(1)->and($row['functions'])->toBe(1)->and($row['services'])->toBe(1)->and($row['processes'])->toBe(1)->and($row['tasks'])->toBe(1)
        ->and($row['tasks_with_standard'])->toBe(1)->and($row['positions'])->toBe(2)->and($row['positions_linked'])->toBe(1)
        ->and($row['positions_with_profile'])->toBe(1)->and($row['positions_with_job_description'])->toBe(1)->and($row['units'])->toBe(1);

    $readiness = $reports->run('position_readiness', $reporter, ['organization_id' => $organization->id]);
    $byCode = collect($readiness['rows'])->keyBy('position_code');
    expect($readiness['rows'])->toHaveCount(2)
        ->and($byCode[$traced->job_position_code]['profile_status'])->toBe('published')
        ->and($byCode[$traced->job_position_code]['job_description_status'])->toBe('published')
        ->and($byCode[$traced->job_position_code]['work_links'])->toBe(1)
        ->and($byCode[$bare->job_position_code]['profile_status'])->toBeNull()
        ->and($byCode[$bare->job_position_code]['work_links'])->toBe(0);

    $gaps = $reports->run('gaps', $reporter, ['organization_id' => $organization->id]);
    expect($gaps['columns'])->toContain('severity', 'code', 'subject_code', 'message')
        ->and(collect($gaps['rows'])->pluck('code')->all())->toContain('position_without_profile');
});

// 72 ─────────────────────────────────────────────────────────────────────────
test('reports respect the organization scope and the report permission', function (): void {
    $mine = rpOrganization('D');
    $theirs = rpOrganization('D2');
    rpPosition($mine, rpUnit($mine, 'U-1'));
    rpPosition($theirs, rpUnit($theirs, 'U-X'));
    $reporter = rpUser($mine, 'Design Reporter');
    $nobody = rpUser($mine, 'Nobody');

    $result = app(OrganizationDesignReportService::class)->run('position_readiness', $reporter, []);
    expect($result['rows'])->toHaveCount(1)->and($result['rows'][0]['organization_code'])->toBe($mine->code);
    $foreign = app(OrganizationDesignReportService::class)->run('position_readiness', $reporter, ['organization_id' => $theirs->id]);
    expect($foreign['rows'])->toHaveCount(0);

    $this->actingAs($nobody)->get(route('organization-design.reports.index'))->assertForbidden();
    $this->actingAs($reporter)->get(route('organization-design.reports.index', ['report' => 'coverage']))
        ->assertInertia(fn ($page) => $page->component('OrganizationDesign/Reports/Index')
            ->where('report', 'coverage')
            ->where('result.rows.0.organization_code', $mine->code)
            ->has('organizations', 1)
            ->where('can.export', false));
    $this->actingAs($reporter)->get(route('organization-design.reports.index', ['report' => 'not-a-report']))
        ->assertInertia(fn ($page) => $page->where('report', 'coverage'));
});

// 73 ─────────────────────────────────────────────────────────────────────────
test('a CSV export is queued, stored on the private disk with formulas neutralised, and downloaded by its requester', function (): void {
    Storage::fake('local');
    $organization = rpOrganization('E');
    $unit = rpUnit($organization, 'U-1');
    rpPosition($organization, $unit, 'P-EVIL', '=HYPERLINK("http://attacker/")');
    $exporter = rpUser($organization, 'Design Exporter');

    $this->actingAs($exporter)->post(route('organization-design.reports.export'), ['report' => 'position_readiness', 'format' => 'csv', 'organization_id' => $organization->id])->assertRedirect();
    $export = OrganizationDesignReportExport::query()->where('requested_by', $exporter->id)->latest('id')->firstOrFail();
    expect($export->status)->toBe('ready')->and($export->report)->toBe('position_readiness')->and($export->format)->toBe('csv')->and($export->row_count)->toBe(1)->and($export->path)->not->toBeNull();
    Storage::disk('local')->assertExists($export->path);
    $content = Storage::disk('local')->get($export->path);
    expect($content)->toContain("'=HYPERLINK")->not->toContain(",=HYPERLINK");

    $response = $this->actingAs($exporter)->get(route('organization-design.reports.exports.download', $export));
    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('.csv');
});

// 74 ─────────────────────────────────────────────────────────────────────────
test('an XLSX export is stored too; exporting needs the export permission and a download stays inside the scope', function (): void {
    Storage::fake('local');
    $mine = rpOrganization('F');
    $theirs = rpOrganization('F2');
    rpPosition($mine, rpUnit($mine, 'U-1'));
    $exporter = rpUser($mine, 'Design Exporter');
    $reporter = rpUser($mine, 'Design Reporter');
    $foreignExporter = rpUser($theirs, 'Design Exporter');

    $this->actingAs($reporter)->post(route('organization-design.reports.export'), ['report' => 'coverage', 'format' => 'xlsx'])->assertForbidden();
    $this->actingAs($exporter)->from('/')->post(route('organization-design.reports.export'), ['report' => 'coverage', 'format' => 'pdf'])->assertSessionHasErrors('format');
    $this->actingAs($exporter)->post(route('organization-design.reports.export'), ['report' => 'coverage', 'format' => 'xlsx'])->assertRedirect();
    $export = OrganizationDesignReportExport::query()->where('requested_by', $exporter->id)->latest('id')->firstOrFail();
    expect($export->status)->toBe('ready')->and($export->path)->toEndWith('.xlsx')->and($export->organization_id)->toBeNull();
    Storage::disk('local')->assertExists($export->path);

    $this->actingAs($foreignExporter)->get(route('organization-design.reports.exports.download', $export))->assertForbidden();
    $this->actingAs($exporter)->get(route('organization-design.reports.exports.download', $export))->assertOk();
    $this->actingAs($exporter)->get(route('organization-design.reports.index'))
        ->assertInertia(fn ($page) => $page->where('exports.0.id', $export->id)->where('exports.0.status', 'ready')->where('can.export', true));
});
