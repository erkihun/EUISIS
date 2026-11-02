<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\PositionOccupancy;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ReportingHierarchyFixture;

/*
 * Deleting a registration made by mistake: only with employees.delete inside
 * the organization scope, only for a record with no history, with a reason,
 * leaving the full record in the audit log. Everyone else is separated by
 * employment status, never deleted.
 */

beforeEach(function (): void {
    $this->f = ReportingHierarchyFixture::build();
    $this->mistake = $this->f->person('Mistake', $this->f->member);
    $this->hr = $this->f->user($this->f->person('HR', $this->f->advisor), ['employees.view', 'employees.viewAny', 'employees.manage', 'employees.delete'], 'RL HR', scopeRow: true);
    $this->editor = $this->f->user($this->f->person('Editor', $this->f->advisor), ['employees.view', 'employees.viewAny', 'employees.manage'], 'RL Editor', scopeRow: true);
    app(OrganizationScopeService::class)->clearCache();
});

it('deletes a mistaken registration with its placement and keeps the record in the audit log', function (): void {
    $assignmentId = $this->mistake->current_assignment_id;
    $account = User::factory()->create(['email' => 'mistake.account@rl.test']);
    $account->forceFill(['employee_id' => $this->mistake->id])->save();

    $this->actingAs($this->hr)->delete(route('employees.destroy', $this->mistake), ['reason' => 'Registered twice by the intake desk'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('employees.index'));

    expect(Employee::query()->whereKey($this->mistake->id)->exists())->toBeFalse()
        ->and(EmployeeAssignment::query()->whereKey($assignmentId)->exists())->toBeFalse()
        ->and(PositionOccupancy::query()->where('employee_id', $this->mistake->id)->exists())->toBeFalse()
        ->and($account->fresh()->employee_id)->toBeNull()
        ->and(User::query()->whereKey($account->id)->exists())->toBeTrue();

    $audit = DB::table('audit_logs')->where('event_type', 'employee_deleted')->first();
    expect($audit)->not->toBeNull()
        ->and((string) $audit->old_values)->toContain($this->mistake->employee_number)
        ->and((string) $audit->reason)->toContain('Registered twice');
});

it('requires the permission, the scope and a reason', function (): void {
    $this->actingAs($this->editor)->delete(route('employees.destroy', $this->mistake), ['reason' => 'Registered twice'])->assertForbidden();

    $other = ReportingHierarchyFixture::build('XX');
    $otherHr = $other->user($other->person('Other HR', $other->advisor), ['employees.view', 'employees.manage', 'employees.delete'], 'RL HR', scopeRow: true);
    app(OrganizationScopeService::class)->clearCache();
    $this->actingAs($otherHr)->delete(route('employees.destroy', $this->mistake), ['reason' => 'Registered twice'])->assertForbidden();

    $this->actingAs($this->hr)->from(route('employees.show', $this->mistake))->delete(route('employees.destroy', $this->mistake), ['reason' => ''])->assertSessionHasErrors('reason');
    expect(Employee::query()->whereKey($this->mistake->id)->exists())->toBeTrue();
});

it('refuses a record that has become a history, such as one with an ID card', function (): void {
    DB::table('id_cards')->insert([
        'id' => (string) Str::uuid(), 'employee_id' => $this->mistake->id, 'card_number' => 'RL-CARD-1', 'status' => 'active', 'is_current' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($this->hr)->from(route('employees.show', $this->mistake))
        ->delete(route('employees.destroy', $this->mistake), ['reason' => 'Registered twice'])
        ->assertSessionHasErrors('employee');
    expect(Employee::query()->whereKey($this->mistake->id)->exists())->toBeTrue()
        ->and(EmployeeAssignment::query()->where('employee_id', $this->mistake->id)->exists())->toBeTrue();
});
