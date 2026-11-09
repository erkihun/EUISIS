<?php

declare(strict_types=1);

use App\Backbone\Commands\StampJobDescriptionOnCurrentAssignments;
use App\Backbone\Events\AssignmentChanged;
use App\Backbone\Events\EmployeeChanged;
use App\Enums\AssignmentStatus;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\Position;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Communication\Infrastructure\Jobs\SyncEmployeeCommunicationMemberships;
use Modules\Transport\Infrastructure\Jobs\ReevaluateTransportEligibility;

/*
 * Stage 2 of the master backbone separation: the backbone publishes domain
 * events and transport / communication listen, instead of the backbone
 * calling their jobs; job descriptions stamp assignments through a backbone
 * command; the chain has a read-only integrity audit and positions get a
 * foreign key to their organization on server databases.
 */

function stageTwoOrganization(string $code = 'S2-ORG'): Organization
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'S2-T'], ['name_en' => 'Stage two type']);

    return Organization::query()->firstOrCreate(['code' => $code], ['organization_type_id' => $type->id, 'name_en' => "Org {$code}", 'status' => 'active']);
}

function stageTwoEmployee(): Employee
{
    return Employee::query()->create([
        'employee_number' => 'S2-'.Str::upper(Str::random(8)), 'first_name' => 'Stage', 'last_name' => 'Two',
        'full_name' => 'Stage Two', 'status' => EmployeeStatus::Active,
    ]);
}

function stageTwoAssignment(Employee $employee, Organization $organization): EmployeeAssignment
{
    return EmployeeAssignment::query()->create([
        'employee_id' => $employee->id, 'organization_id' => $organization->id,
        'assignment_status' => AssignmentStatus::Active, 'effective_from' => now()->toDateString(), 'is_current' => true,
    ]);
}

it('publishes assignment and employee changes as backbone events', function (): void {
    Event::fake([AssignmentChanged::class, EmployeeChanged::class]);
    $employee = stageTwoEmployee();
    $assignment = stageTwoAssignment($employee, stageTwoOrganization());
    $assignment->update(['organization_id' => stageTwoOrganization('S2-OTHER')->id]);
    $employee->update(['status' => EmployeeStatus::Suspended]);
    $assignment->delete();

    Event::assertDispatched(AssignmentChanged::class, fn (AssignmentChanged $e) => $e->created && $e->employeeId === (string) $employee->id);
    Event::assertDispatched(AssignmentChanged::class, fn (AssignmentChanged $e) => ! $e->created && $e->changedAny(['organization_id']));
    Event::assertDispatched(AssignmentChanged::class, fn (AssignmentChanged $e) => $e->deleted);
    Event::assertDispatched(EmployeeChanged::class, fn (EmployeeChanged $e) => $e->created);
    Event::assertDispatched(EmployeeChanged::class, fn (EmployeeChanged $e) => $e->changedAny(['status']));
});

it('lets transport and communication follow placement changes through those events', function (): void {
    Bus::fake([ReevaluateTransportEligibility::class, SyncEmployeeCommunicationMemberships::class]);
    config(['features.transport' => true]);
    $employee = stageTwoEmployee();

    DB::transaction(function () use ($employee): void {
        $assignment = stageTwoAssignment($employee, stageTwoOrganization());
        $employee->update(['current_assignment_id' => $assignment->id]);
    });

    Bus::assertDispatched(ReevaluateTransportEligibility::class);
    // One membership sync per employee per transaction, even after two saves.
    Bus::assertDispatchedTimes(SyncEmployeeCommunicationMemberships::class, 1);
});

it('ignores saves that change nothing either module cares about', function (): void {
    $employee = stageTwoEmployee();
    $assignment = stageTwoAssignment($employee, stageTwoOrganization());
    Bus::fake([ReevaluateTransportEligibility::class, SyncEmployeeCommunicationMemberships::class]);

    $assignment->update(['reason' => 'Corrected wording']);
    $employee->update(['first_name' => 'Renamed']);

    Bus::assertNotDispatched(ReevaluateTransportEligibility::class);
    Bus::assertNotDispatched(SyncEmployeeCommunicationMemberships::class);
});

it('stamps the job description only on the position\'s current assignments', function (): void {
    $position = Position::query()->create(['job_position_code' => 'S2-POS-1', 'title_en' => 'Stage two officer', 'organization_id' => stageTwoOrganization()->id, 'is_active' => true]);
    $current = stageTwoAssignment(stageTwoEmployee(), stageTwoOrganization());
    $current->update(['position_id' => $position->id]);
    $closed = stageTwoAssignment(stageTwoEmployee(), stageTwoOrganization());
    $closed->update(['position_id' => $position->id, 'is_current' => false, 'assignment_status' => AssignmentStatus::Closed]);
    $jd = (string) Str::uuid();

    DB::statement('PRAGMA foreign_keys = OFF');
    $stamped = app(StampJobDescriptionOnCurrentAssignments::class)->execute($position->id, $jd);
    DB::statement('PRAGMA foreign_keys = ON');

    expect($stamped)->toBe(1)
        ->and($current->fresh()->job_description_id)->toBe($jd)
        ->and($closed->fresh()->job_description_id)->toBeNull();
})->skip(fn () => DB::getDriverName() !== 'sqlite', 'Uses a SQLite pragma to stamp an id with no job description row.');

it('reports a position whose organization does not exist, read-only', function (): void {
    DB::statement('PRAGMA foreign_keys = OFF');
    $orphan = Position::query()->create(['job_position_code' => 'S2-ORPHAN', 'title_en' => 'Orphan', 'organization_id' => (string) Str::uuid(), 'is_active' => true]);
    DB::statement('PRAGMA foreign_keys = ON');

    $this->artisan('backbone:audit-integrity')
        ->expectsTable(['Check', 'Result'], [
            ['Positions naming an organization that does not exist', '1'],
            ['Positions whose unit belongs to another organization', '0'],
            ['Employees pointing at a missing, closed or foreign assignment', '0'],
            ['Current assignments the employee does not point at', '0'],
            ['Card replacement records not linked to the new card', '0'],
            ['Separated employees whose placement is still open', '0'],
            ['Separated employees whose current ID card is still active', '0'],
            ['Employees holding more than one active ID card', '0'],
            ['Positions held by more employees than their approved seats (one without an establishment)', '0'],
            ['positions.organization_id foreign key present', 'no'],
        ])
        ->assertSuccessful();
    $this->artisan('backbone:audit-integrity', ['--strict' => true])->assertFailed();

    expect($orphan->fresh())->not->toBeNull();
})->skip(fn () => DB::getDriverName() !== 'sqlite', 'Server databases enforce the key, so an orphan cannot be created.');

it('enforces the position -> organization key on server databases', function (): void {
    expect(collect(Schema::getForeignKeys('positions'))->contains(fn (array $key): bool => $key['columns'] === ['organization_id']))->toBeTrue();
})->skip(fn () => DB::getDriverName() === 'sqlite', 'SQLite cannot add a key to an existing table in place.');
