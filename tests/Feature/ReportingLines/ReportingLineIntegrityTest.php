<?php

declare(strict_types=1);

use App\Actions\Positions\EnsureSingleUnitHead;
use App\Actions\Positions\UpdatePositionAction;
use App\Enums\OrganizationDesignStatus;
use App\Models\Position;
use App\Models\PositionProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationDesign\PositionProfileService;
use App\Services\ReportingLines\ReportingDiagnostics;
use App\Services\ReportingLines\ReportingGraphRepository;
use App\Support\Database\HeadPositionIndexes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\Support\ReportingHierarchyFixture;

/*
 * Integrity under change and concurrency (ADR 0025): one head per unit and
 * per organization at the application and the database, no loops through
 * profiles or head marks, a read-only diagnostic report, and the explicit,
 * dry-runnable remediation command.
 */

beforeEach(function (): void {
    $this->f = ReportingHierarchyFixture::build();
    app(ReportingGraphRepository::class)->forget();
    foreach (['positions.update', 'positions.view', 'positions.viewAny'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web')->givePermissionTo(['positions.update', 'positions.view', 'positions.viewAny']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Admin');
});

it('refuses a second head for a unit and for the organization through the action', function (): void {
    $deputy = $this->f->position('RL-P-DEP', 'Deputy', $this->f->team);
    try {
        app(UpdatePositionAction::class)->execute($deputy, ['is_unit_head' => true], $this->admin);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('is_unit_head');
    }

    $secondOrgHead = $this->f->position('RL-P-BH2', 'Second Bureau Head', null);
    try {
        app(UpdatePositionAction::class)->execute($secondOrgHead, ['is_unit_head' => true], $this->admin);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors()['is_unit_head'][0])->toBe(__('positions.organization_head_taken', ['position' => EnsureSingleUnitHead::describe($this->f->bureauHead)]));
    }
});

it('backs the rule with a database index that refuses a concurrent second head', function (): void {
    expect(HeadPositionIndexes::status())->toBe([HeadPositionIndexes::UNIT => true, HeadPositionIndexes::ORGANIZATION => true])
        ->and(HeadPositionIndexes::violations())->toBe([]);

    // What a racing request that passed the check would do: write a second head row directly.
    $insert = fn (?string $unitId) => DB::table('positions')->insert([
        'id' => (string) Str::uuid(), 'organization_id' => $this->f->bureau->id, 'organization_unit_id' => $unitId,
        'job_position_code' => 'RL-RACE-'.Str::random(4), 'title_en' => 'Racer', 'is_active' => true, 'is_unit_head' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => $insert($this->f->team->id))->toThrow(QueryException::class)
        ->and(fn () => $insert(null))->toThrow(QueryException::class);

    // The guard turns that database refusal into the form's validation error.
    try {
        app(EnsureSingleUnitHead::class)->guard(fn () => $insert($this->f->team->id), $this->f->team->id);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors()['is_unit_head'][0])->toBe(__('positions.unit_head_taken_concurrently'));
    }

    // A soft-deleted head no longer counts, so the unit can get a new one.
    $this->f->teamLeader->delete();
    $insert($this->f->team->id);
    expect(Position::query()->where('organization_unit_id', $this->f->team->id)->where('is_unit_head', true)->count())->toBe(1);
});

it('refuses a profile line that would loop through the inferred lines, and re-checks at publish', function (): void {
    $service = app(PositionProfileService::class);
    $designer = User::factory()->create();

    // Director → Team Member would loop: member → leader → director → member.
    try {
        $service->createDraft($designer, $this->f->director, ['reports_to_position_id' => $this->f->member->id, 'effective_from' => '2026-01-01']);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors()['reports_to_position_id'][0])->toBe(__('reporting-lines.validation.cycle'));
    }

    // Self and another organization's position are refused too.
    expect(fn () => $service->createDraft($designer, $this->f->director, ['reports_to_position_id' => $this->f->director->id, 'effective_from' => '2026-01-01']))->toThrow(ValidationException::class);

    // A line that is fine when drafted but loops by the time it is published is refused at publish.
    $draft = $service->createDraft($designer, $this->f->directorateOfficer, ['reports_to_position_id' => $this->f->sectorHead->id, 'effective_from' => '2026-01-01']);
    PositionProfile::query()->create([
        'position_id' => $this->f->sectorHead->id, 'organization_id' => $this->f->bureau->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1,
        'reports_to_position_id' => $this->f->directorateOfficer->id, 'status' => OrganizationDesignStatus::Published->value, 'effective_from' => '2026-01-01',
    ]);
    $publisher = User::factory()->create();
    expect(fn () => $service->publish($publisher, $draft->fresh()))->toThrow(ValidationException::class);
});

it('refuses marking a head whose published profile reports into its own unit', function (): void {
    $candidate = $this->f->position('RL-P-CAND', 'Candidate', $this->f->finance);
    PositionProfile::query()->create([
        'position_id' => $candidate->id, 'organization_id' => $this->f->bureau->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1,
        'reports_to_position_id' => $this->f->position('RL-P-FINM', 'Finance Member', $this->f->finance)->id, 'status' => OrganizationDesignStatus::Published->value, 'effective_from' => '2026-01-01',
    ]);
    $this->f->financeDirector->update(['is_unit_head' => false]);

    try {
        app(UpdatePositionAction::class)->execute($candidate, ['is_unit_head' => true], $this->admin);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors()['is_unit_head'][0])->toBe(__('reporting-lines.validation.head_cycle'));
    }
    expect($candidate->fresh()->is_unit_head)->toBeFalse();
});

it('produces a read-only diagnostic report naming the unit or position to fix', function (): void {
    $this->f->person('Director', $this->f->director);
    $orphan = $this->f->unit('RL-ORPHAN', 'Orphan', $this->f->sector);
    DB::table('acting_appointments')->insert([
        ['id' => (string) Str::uuid(), 'organization_id' => $this->f->bureau->id, 'position_id' => $this->f->director->id, 'employee_id' => $this->f->person('Acting One', $this->f->directorateOfficer)->id, 'starts_on' => '2026-01-01', 'ends_on' => null, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ['id' => (string) Str::uuid(), 'organization_id' => $this->f->bureau->id, 'position_id' => $this->f->director->id, 'employee_id' => $this->f->person('Acting Two', $this->f->directorateOfficer)->id, 'starts_on' => '2026-01-01', 'ends_on' => null, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $before = DB::table('positions')->count();

    $report = app(ReportingDiagnostics::class)->forOrganization($this->f->bureau);
    $codes = array_column($report['findings'], 'code');

    expect($codes)->toContain('unit_head_missing')->toContain('head_vacant')->toContain('acting_overlap')
        ->and(collect($report['findings'])->firstWhere('code', 'unit_head_missing')['unit_id'])->toBe($orphan->id)
        ->and($report['summary']['errors'])->toBeGreaterThan(0)
        ->and($report['head_indexes'][HeadPositionIndexes::UNIT])->toBeTrue()
        ->and(DB::table('positions')->count())->toBe($before);

    Artisan::call('reporting:diagnose', ['--organization' => [$this->f->bureau->code], '--preflight' => true]);
    expect(Artisan::output())->toContain('Orphan')->toContain(HeadPositionIndexes::UNIT);
});

it('applies unit heads from an HR-written CSV, dry run first, never guessing', function (): void {
    $this->f->teamLeader->update(['is_unit_head' => false]);
    $orphan = $this->f->unit('RL-ORPHAN', 'Orphan', $this->f->sector);
    $this->f->position('RL-P-ORPH', 'Orphan Lead', $orphan);
    $csv = tempnam(sys_get_temp_dir(), 'heads');
    file_put_contents($csv, implode("\n", [
        'organization_code,unit_code,job_position_code',
        "{$this->f->bureau->code},{$this->f->team->code},{$this->f->teamLeader->job_position_code}",
        "{$this->f->bureau->code},{$orphan->code},RL-P-ORPH",
        "{$this->f->bureau->code},{$this->f->directorate->code},RL-P-ORPH",
        "{$this->f->bureau->code},NOPE,RL-P-ORPH",
    ]));

    Artisan::call('reporting:apply-unit-heads', ['file' => $csv, '--dry-run' => true]);
    expect(Artisan::output())->toContain('would mark as head')->toContain('not placed in that unit')->toContain('unit not found')
        ->and($this->f->teamLeader->fresh()->is_unit_head)->toBeFalse();

    Artisan::call('reporting:apply-unit-heads', ['file' => $csv, '--actor' => $this->admin->email]);
    expect($this->f->teamLeader->fresh()->is_unit_head)->toBeTrue()
        ->and(Position::query()->where('job_position_code', 'RL-P-ORPH')->value('is_unit_head'))->toBeTruthy()
        ->and(DB::table('audit_logs')->where('event_type', 'reporting_lines.unit_head_applied')->count())->toBe(2);
    unlink($csv);
});

it('reports and installs the head indexes idempotently', function (): void {
    Artisan::call('reporting:ensure-head-indexes', ['--check' => true]);
    expect(Artisan::output())->toContain('present');
    expect(Artisan::call('reporting:ensure-head-indexes'))->toBe(0);
});
