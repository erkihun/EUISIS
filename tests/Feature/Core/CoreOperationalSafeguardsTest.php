<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Services\Core\BackboneIntegrityChecks;
use Illuminate\Support\Str;

/*
 * Core Stages 6 and 7: the separated-employee card check, the Core section of
 * production:readiness and the read-only core:smoke command.
 */

beforeEach(function (): void {
    $type = OrganizationType::query()->create(['code' => 'OS-T', 'name_en' => 'Safeguard type']);
    $this->organization = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'OS-ORG', 'name_en' => 'Safeguard bureau', 'status' => 'active']);
    $position = Position::query()->create(['organization_id' => $this->organization->id, 'job_position_code' => 'OS-P', 'title_en' => 'Officer', 'is_active' => true]);
    $this->employee = Employee::query()->create(['employee_number' => 'OS-E1', 'first_name' => 'Safe', 'last_name' => 'Guard', 'full_name' => 'Safe Guard', 'status' => EmployeeStatus::Active->value]);
    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $this->employee->id, 'organization_id' => $this->organization->id, 'position_id' => $position->id,
        'is_current' => true, 'assignment_status' => AssignmentStatus::Active->value, 'effective_from' => '2025-03-01',
    ]);
    $this->employee->forceFill(['current_assignment_id' => $assignment->id])->save();
    $this->card = IdCard::query()->create(['employee_id' => $this->employee->id, 'card_number' => 'OS-C1', 'public_card_uuid' => (string) Str::uuid(), 'status' => CardStatus::Active->value, 'is_current' => true, 'expires_at' => now()->addYear()]);
});

it('passes the Core smoke test on a working platform and prints no personal data', function (): void {
    $this->artisan('core:smoke')
        ->expectsOutputToContain('A current active card verifies as valid')
        ->expectsOutputToContain('An unknown credential is refused')
        ->doesntExpectOutputToContain('OS-E1')
        ->doesntExpectOutputToContain('Safe Guard')
        ->assertSuccessful();
});

it('fails the Core smoke test while master-data writes are frozen or no card is valid', function (): void {
    config(['core.master_data_writes' => 'frozen']);
    $this->artisan('core:smoke')->assertFailed();

    config(['core.master_data_writes' => 'open']);
    $this->card->update(['status' => CardStatus::Suspended->value]);
    $this->artisan('core:smoke')->assertFailed();
});

it('reports a separated employee whose current card is still active', function (): void {
    // A status written straight to the table, as older separations were, leaves the card active.
    Employee::query()->whereKey($this->employee->id)->update(['status' => EmployeeStatus::Terminated->value]);

    $this->artisan('backbone:audit-integrity')->expectsOutputToContain('Separated employees whose current ID card is still active')->assertSuccessful();
    $this->artisan('backbone:audit-integrity', ['--strict' => true])->assertFailed();
});

it('checks the Core platform in the go-live gate', function (): void {
    config(['core.master_data_writes' => 'frozen']);

    $this->artisan('production:readiness')
        ->expectsOutputToContain('Core platform')
        ->expectsOutputToContain('Golden Path integrity')
        ->expectsOutputToContain('Master-data writes are open')
        ->expectsOutputToContain('Integration outbox is draining');
});

it('reports an employee holding two active cards and a position held beyond its seats (Stage 6 data quality)', function (): void {
    $checks = app(BackboneIntegrityChecks::class);
    expect($checks->counts()['employees_multiple_active_cards'])->toBe(0)
        ->and($checks->counts()['positions_over_capacity'])->toBe(0);

    // Written straight to the tables, as only old data or a database edit could.
    IdCard::query()->create(['employee_id' => $this->employee->id, 'card_number' => 'OS-C2', 'public_card_uuid' => (string) Str::uuid(), 'status' => CardStatus::Active->value, 'is_current' => false, 'expires_at' => now()->addYear()]);
    $second = Employee::query()->create(['employee_number' => 'OS-E2', 'first_name' => 'Second', 'last_name' => 'Holder', 'full_name' => 'Second Holder', 'status' => EmployeeStatus::Active->value]);
    EmployeeAssignment::query()->create([
        'employee_id' => $second->id, 'organization_id' => $this->organization->id, 'position_id' => $this->employee->currentAssignment->position_id,
        'is_current' => true, 'assignment_status' => AssignmentStatus::Active->value, 'effective_from' => '2025-04-01',
    ]);

    expect($checks->counts()['employees_multiple_active_cards'])->toBe(1)
        ->and($checks->counts()['positions_over_capacity'])->toBe(1);

    // Two approved seats make the same placements legitimate.
    PositionEstablishment::query()->create(['establishment_number' => 'OS-EST', 'organization_id' => $this->organization->id, 'position_id' => $this->employee->currentAssignment->position_id, 'approved_slots' => 2, 'effective_from' => '2025-01-01', 'status' => 'approved']);
    expect($checks->counts()['positions_over_capacity'])->toBe(0);
});
