<?php

declare(strict_types=1);

use App\Console\Commands\Core\CoreSnapshot;
use App\Enums\EmployeeStatus;
use App\Enums\MasterDataWriteMode;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/*
 * Core Stage 4 cutover tooling: the master-data write freeze and the Golden
 * Path snapshot used to validate a migration or a restore.
 */

beforeEach(function (): void {
    $this->type = OrganizationType::query()->create(['code' => 'CT-T', 'name_en' => 'Cutover type']);
    Permission::findOrCreate('organizations.view', 'web');
    Permission::findOrCreate('organizations.create', 'web');
    $this->admin = User::factory()->create();
    $this->admin->givePermissionTo(['organizations.view', 'organizations.create']);
});

it('refuses master-data changes while writes are frozen and leaves reads alone', function (): void {
    config(['core.master_data_writes' => 'frozen']);
    $this->actingAs($this->admin);

    $this->from(route('organizations.index'))
        ->post(route('organizations.store'), ['organization_type_id' => $this->type->id, 'name_en' => 'Frozen org', 'status' => 'active'])
        ->assertRedirect(route('organizations.index'))
        ->assertSessionHas('flash.message', __('backbone.master_data_frozen'));
    expect(Organization::query()->where('name_en', 'Frozen org')->exists())->toBeFalse();

    $this->postJson(route('organizations.store'), ['organization_type_id' => $this->type->id, 'name_en' => 'Frozen org', 'status' => 'active'])
        ->assertStatus(423)->assertJsonPath('error_code', 'master_data_frozen');

    $this->get(route('organizations.index'))->assertOk();
});

it('lets master-data changes through to the action when writes are open', function (): void {
    config(['core.master_data_writes' => 'open']);
    $this->actingAs($this->admin)
        ->post(route('organizations.store'), ['organization_type_id' => $this->type->id, 'name_en' => 'Open org', 'status' => 'active']);

    // The request reached the organization action (here it stops at the missing code rule), not the freeze.
    expect(session('flash.message'))->not->toBe(__('backbone.master_data_frozen'))
        ->and(session('errors')?->first())->toBe('No active code rule is configured for this entity.');
});

it('fingerprints the Golden Path tables and detects any change against an earlier snapshot', function (): void {
    $employee = Employee::query()->create(['employee_number' => 'CT-E1', 'first_name' => 'Snap', 'last_name' => 'Shot', 'full_name' => 'Snap Shot', 'status' => EmployeeStatus::Active->value]);
    $file = storage_path('framework/testing/core-snapshot-'.getmypid().'.json');

    expect(Artisan::call('core:snapshot', ['--output' => $file]))->toBe(0);
    $snapshot = json_decode((string) file_get_contents($file), true);
    expect($snapshot['tables']['employees']['count'])->toBe(1)
        ->and(file_get_contents($file))->not->toContain('CT-E1');

    expect(Artisan::call('core:snapshot', ['--compare' => $file]))->toBe(0);

    $employee->update(['status' => EmployeeStatus::Suspended->value]);
    expect(Artisan::call('core:snapshot', ['--compare' => $file]))->toBe(1)
        ->and(Artisan::output())->toContain('DIFFERENT');

    @unlink($file);
});

it('rehearses a database move: the copy on another connection matches, and a drift is explained by status', function (): void {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('The rehearsal copies tables through SQLite DDL.');
    }
    Employee::query()->create(['employee_number' => 'CT-R1', 'first_name' => 'Re', 'last_name' => 'Hearsal', 'full_name' => 'Re Hearsal', 'status' => EmployeeStatus::Active->value]);
    Employee::query()->create(['employee_number' => 'CT-R2', 'first_name' => 'Co', 'last_name' => 'Py', 'full_name' => 'Co Py', 'status' => EmployeeStatus::Active->value]);

    // The target database: a separate SQLite file holding a copy of every Golden Path table.
    $target = storage_path('framework/testing/core-target-'.getmypid().'.sqlite');
    @unlink($target);
    touch($target);
    config(['database.connections.core_target' => ['driver' => 'sqlite', 'database' => $target, 'foreign_key_constraints' => false]]);
    foreach (array_keys(CoreSnapshot::TABLES) as $table) {
        $ddl = DB::selectOne("select sql from sqlite_master where type = 'table' and name = ?", [$table])?->sql;
        if ($ddl === null) {
            continue;
        }
        DB::connection('core_target')->statement($ddl);
        foreach (DB::table($table)->get() as $row) {
            DB::connection('core_target')->table($table)->insert((array) $row);
        }
    }

    $source = storage_path('framework/testing/core-source-'.getmypid().'.json');
    expect(Artisan::call('core:snapshot', ['--output' => $source]))->toBe(0);
    expect(json_decode((string) file_get_contents($source), true)['tables']['employees']['statuses']['status'])->toBe(['active' => 2]);
    expect(Artisan::call('core:snapshot', ['--connection' => 'core_target', '--compare' => $source]))->toBe(0);

    DB::connection('core_target')->table('employees')->where('employee_number', 'CT-R2')->update(['status' => EmployeeStatus::Suspended->value]);
    expect(Artisan::call('core:snapshot', ['--connection' => 'core_target', '--compare' => $source]))->toBe(1)
        ->and(Artisan::output())->toContain('DIFFERENT')->toContain('suspended');

    expect(Artisan::call('core:snapshot', ['--connection' => 'nowhere']))->toBe(1);

    DB::purge('core_target');
    @unlink($target);
    @unlink($source);
});

it('carries the request id into the log context of the request', function (): void {
    $response = $this->withHeader('X-Request-ID', 'cutover-trace-1')->get('/health/live');

    expect($response->headers->get('X-Request-ID'))->toBe('cutover-trace-1')
        ->and(Context::get('request_id'))->toBe('cutover-trace-1');
});

it('allows master-data writes only in the core and legacy modes, failing closed on an unknown value', function (string $setting, bool $writes): void {
    config(['core.master_data_writes' => $setting]);
    $this->actingAs($this->admin);

    $response = $this->postJson(route('organizations.store'), ['organization_type_id' => $this->type->id, 'name_en' => 'Mode org', 'status' => 'active']);

    expect($response->status() === 423)->toBe(! $writes)
        ->and(MasterDataWriteMode::fromSetting($setting)->allowsWrites())->toBe($writes);
})->with([
    'core' => ['core', true],
    'legacy' => ['legacy', true],
    'open (old spelling)' => ['open', true],
    'cutover' => ['cutover', false],
    'rollback' => ['rollback', false],
    'frozen (old spelling)' => ['frozen', false],
    'a typo' => ['coer', false],
]);
