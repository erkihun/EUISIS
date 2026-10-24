<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
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
