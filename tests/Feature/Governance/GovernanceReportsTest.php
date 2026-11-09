<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\ExternalApplication;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\User;
use App\Services\Positions\PositionCapacityService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/*
 * Stage 8 governance reports: the privileged-account review (access:review),
 * the API consumer registry (api:consumers) and the data-quality rules added
 * to structure:audit. All read-only.
 */

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-25 09:00'));
    config(['security.mfa_required_roles' => ['Super Admin']]);
    Role::findOrCreate('Super Admin', 'web');
    Role::findOrCreate('City Admin', 'web');
});

it('reviews privileged accounts for stale sign-in, missing MFA and disabled holders', function (): void {
    User::factory()->create(['name' => 'Recent Admin', 'status' => 'active', 'last_login_at' => now()->subDays(3), 'email' => 'recent@example.test'])->assignRole('City Admin');
    User::factory()->create(['name' => 'Old Admin', 'status' => 'active', 'last_login_at' => now()->subDays(200)])->assignRole('City Admin');
    User::factory()->create(['name' => 'Fresh Super', 'status' => 'active', 'last_login_at' => null])->assignRole('Super Admin');
    User::factory()->create(['name' => 'Gone Admin', 'status' => 'inactive', 'last_login_at' => now()->subDays(400)])->assignRole('City Admin');
    User::factory()->create(['name' => 'Plain User', 'status' => 'active']);

    Artisan::call('access:review', ['--json' => true]);
    $output = Artisan::output();
    $rows = collect(json_decode($output, true))->keyBy('name');

    expect($rows->keys()->sort()->values()->all())->toBe(['Fresh Super', 'Gone Admin', 'Old Admin', 'Recent Admin'])
        ->and($rows['Recent Admin']['findings'])->toBe([])
        ->and($rows['Recent Admin']['organization_scope'])->toBe('city-wide')
        ->and($rows['Old Admin']['findings'])->toBe(['STALE'])
        ->and($rows['Fresh Super']['findings'])->toBe(['NEVER_SIGNED_IN', 'MFA_MISSING'])
        ->and($rows['Fresh Super']['mfa'])->toBe('missing')
        ->and($rows['Gone Admin']['findings'])->toBe(['INACTIVE_HOLDS_PRIVILEGE'])
        ->and($output)->not->toContain('recent@example.test');

    $this->artisan('access:review')->expectsOutputToContain('4 privileged account(s); 3 need a decision.')->assertSuccessful();
});

it('lists every API consumer with owner, version, scope and credential findings', function (): void {
    $active = ExternalApplication::query()->create([
        'name' => 'Cafeteria terminal', 'code' => 'CAF-1', 'owner_institution' => 'Cafeteria office', 'status' => 'active',
        'allowed_scopes' => ['id_cards.verify'], 'rate_limit_per_minute' => 60, 'last_used_at' => now()->subDay(),
    ]);
    $active->createToken('terminal', ['id_cards.verify']);
    ExternalApplication::query()->create([
        'name' => 'Old report reader', 'code' => 'OLD-1', 'status' => 'active',
        'allowed_scopes' => ['organizations.read'], 'rate_limit_per_minute' => 60, 'last_used_at' => now()->subDays(120),
    ]);

    Artisan::call('api:consumers', ['--json' => true]);
    $output = Artisan::output();
    $rows = collect(json_decode($output, true))->keyBy('code');

    expect($rows->keys()->all())->toBe(['CAF-1', 'OLD-1'])
        ->and($rows['CAF-1'])->toMatchArray(['owner_institution' => 'Cafeteria office', 'api_version' => 'v1', 'environment' => 'testing', 'scopes' => ['id_cards.verify'], 'tokens' => 1])
        ->and($rows['CAF-1']['findings'])->not->toContain('STALE')
        ->and($rows['CAF-1']['next_token_expiry'])->not->toBeNull()
        ->and($rows['OLD-1']['findings'])->toContain('STALE')
        ->and($output)->not->toContain('plainTextToken');

    $this->artisan('api:consumers')->expectsOutputToContain('2 consumer(s) in testing')->assertSuccessful();
});

it('reports seats still held by an ended assignment and organizations without an Amharic name', function (): void {
    $type = OrganizationType::query()->create(['code' => 'GV-T', 'name_en' => 'Governance type']);
    $organization = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'GV-ORG', 'name_en' => 'Governance bureau', 'name_am' => 'ቢሮ', 'status' => 'active']);
    $unnamed = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'GV-NO-AM', 'name_en' => 'No Amharic name', 'status' => 'active']);
    $position = Position::query()->create(['organization_id' => $organization->id, 'job_position_code' => 'GV-P', 'title_en' => 'Officer', 'is_active' => true]);
    $employee = Employee::query()->create(['employee_number' => 'GV-E1', 'first_name' => 'Gov', 'last_name' => 'Check', 'full_name' => 'Gov Check', 'status' => EmployeeStatus::Active->value]);
    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id, 'organization_id' => $organization->id, 'position_id' => $position->id,
        'is_current' => true, 'assignment_status' => AssignmentStatus::Active->value, 'effective_from' => '2025-03-01',
    ]);
    $establishment = PositionEstablishment::query()->create(['establishment_number' => 'GV-EST', 'organization_id' => $organization->id, 'position_id' => $position->id, 'approved_slots' => 1, 'effective_from' => '2025-01-01', 'status' => 'approved']);
    $occupancy = app(PositionCapacityService::class)->recordOccupancy($establishment, $assignment, Carbon::parse('2025-03-01'));

    // Ended outside the application: the seat was never released.
    DB::table('employee_assignments')->where('id', $assignment->id)->update(['assignment_status' => AssignmentStatus::Closed->value]);

    $this->artisan('structure:audit')
        ->expectsOutputToContain('Occupied seats whose assignment is no longer active')
        ->expectsOutputToContain($occupancy->id)
        ->expectsOutputToContain('Active organizations without an Amharic name')
        ->expectsOutputToContain($unnamed->id)
        ->assertExitCode(0);
});
