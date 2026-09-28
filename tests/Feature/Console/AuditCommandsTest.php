<?php

declare(strict_types=1);

use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use Illuminate\Support\Facades\DB;

function auditCommandEmployee(string $number): Employee
{
    return Employee::query()->create([
        'employee_number' => $number, 'first_name' => 'Audit', 'last_name' => 'Case',
        'full_name' => 'Audit Case', 'status' => EmployeeStatus::Active,
    ]);
}

function auditCommandCard(Employee $employee, string $number, CardStatus $status): IdCard
{
    return IdCard::query()->create([
        'employee_id' => $employee->id, 'card_number' => $number, 'status' => $status,
        'token_hash' => hash('sha256', $number), 'issued_at' => now(), 'expires_at' => now()->addYear(),
        'token_version' => 1, 'is_current' => false,
    ]);
}

it('passes every read-only audit on consistent data', function (string $command): void {
    // SKIPPED would mean a check's query does not run on this database engine.
    $this->artisan($command)->doesntExpectOutputToContain('SKIPPED')->assertExitCode(0);
})->with(['data:audit-duplicates', 'structure:audit', 'cafeteria:audit-configuration']);

it('reports an employee holding two live cards without changing anything', function (): void {
    $employee = auditCommandEmployee('AUD-1');
    auditCommandCard($employee, 'AUD-CARD-1', CardStatus::Active);
    auditCommandCard($employee, 'AUD-CARD-2', CardStatus::PendingPrint);
    $before = DB::table('id_cards')->orderBy('id')->get()->toArray();

    $this->artisan('data:audit-duplicates')
        ->expectsOutputToContain($employee->id)
        ->assertExitCode(1);

    expect(DB::table('id_cards')->orderBy('id')->get()->toArray())->toEqual($before);
});

it('reports organization units that form a parent cycle', function (): void {
    $type = OrganizationType::query()->create(['code' => 'AUD-T', 'name_en' => 'Audit type']);
    $organization = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'AUD-ORG', 'name_en' => 'Audit organization', 'status' => 'active']);
    $a = OrganizationUnit::query()->create(['organization_id' => $organization->id, 'code' => 'AUD-A', 'name_en' => 'A', 'unit_type' => 'directorate', 'status' => 'active']);
    $b = OrganizationUnit::query()->create(['organization_id' => $organization->id, 'parent_unit_id' => $a->id, 'code' => 'AUD-B', 'name_en' => 'B', 'unit_type' => 'team', 'status' => 'active']);
    DB::table('organization_units')->where('id', $a->id)->update(['parent_unit_id' => $b->id]);

    $this->artisan('structure:audit')
        ->expectsOutputToContain($a->id)
        ->assertExitCode(1);
});

it('blocks go-live when production rules are enforced on a test configuration', function (): void {
    // The test environment is not production and serves plain HTTP.
    $this->artisan('production:readiness', ['--strict' => true])->assertExitCode(1);
});
