<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Stage 7 of the master backbone separation: evidence before retiring the
 * duplicate masters. The report is read-only and prints counts only.
 */

function stageSevenReport(): array
{
    Artisan::call('backbone:duplicate-masters', ['--json' => true]);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

function stageSevenOffice(Organization $organization, string $code): string
{
    $id = (string) Str::uuid();
    DB::table('institution_offices')->insert(['id' => $id, 'institution_id' => $organization->id, 'office_level' => 'branch', 'office_code' => $code, 'name_en' => 'Office '.$code, 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

it('reports offices without an organization unit and is ready only when none are left', function (): void {
    $type = OrganizationType::query()->create(['code' => 'S7-T', 'name_en' => 'Stage seven type']);
    $organization = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'S7-ORG', 'name_en' => 'Stage seven', 'status' => 'active']);
    $mapped = stageSevenOffice($organization, 'S7-OFF-1');
    $unmapped = stageSevenOffice($organization, 'S7-OFF-2');
    OrganizationUnit::query()->create(['organization_id' => $organization->id, 'code' => 'S7-U', 'name_en' => 'Mapped office', 'unit_type' => 'office', 'status' => 'active', 'institution_office_id' => $mapped]);

    $offices = stageSevenReport()['institution_offices'];
    expect($offices['offices_live'])->toBe(2)
        ->and($offices['offices_with_unit'])->toBe(1)
        ->and($offices['offices_without_unit'])->toBe(1)
        ->and($offices['ready_to_retire'])->toBeFalse();

    // A closed (soft-deleted) office does not hold retirement back.
    DB::table('institution_offices')->where('id', $unmapped)->update(['deleted_at' => now()]);
    expect(stageSevenReport()['institution_offices']['ready_to_retire'])->toBeTrue();
});

it('compares account national IDs with the linked employee without printing any of them', function (): void {
    $matching = Employee::query()->create(['employee_number' => 'S7-E1', 'first_name' => 'Same', 'last_name' => 'Id', 'full_name' => 'Same Id', 'status' => EmployeeStatus::Active->value, 'national_id' => 'ETH-S7-111']);
    $different = Employee::query()->create(['employee_number' => 'S7-E2', 'first_name' => 'Other', 'last_name' => 'Id', 'full_name' => 'Other Id', 'status' => EmployeeStatus::Active->value, 'national_id' => 'ETH-S7-222']);
    User::factory()->create(['email' => 's7-a@example.test', 'employee_id' => $matching->id, 'national_id' => 'ETH-S7-111']);
    User::factory()->create(['email' => 's7-b@example.test', 'employee_id' => $different->id, 'national_id' => 'ETH-S7-999']);
    User::factory()->create(['email' => 's7-c@example.test', 'national_id' => 'ETH-S7-333']);

    $accounts = stageSevenReport()['users_national_id'];
    expect($accounts)->toMatchArray(['accounts_with_national_id' => 3, 'linked_and_matching' => 1, 'linked_but_different' => 1, 'account_not_linked' => 1, 'ready_to_retire' => false]);

    Artisan::call('backbone:duplicate-masters');
    expect(Artisan::output())->not->toContain('ETH-S7')->not->toContain('s7-a@example.test')->toContain('not ready');
});

it('changes nothing in the database', function (): void {
    $before = DB::table('users')->count() + DB::table('institution_offices')->count();
    Artisan::call('backbone:duplicate-masters');

    expect(DB::table('users')->count() + DB::table('institution_offices')->count())->toBe($before);
});
