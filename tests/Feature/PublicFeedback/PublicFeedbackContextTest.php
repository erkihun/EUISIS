<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Feedback\Application\Services\EmployeeFeedbackTokenService;
use Modules\Feedback\Application\Services\OrganizationFeedbackQrService;
use Modules\Feedback\Contracts\Data\ServiceFeedbackStatus;
use Modules\Feedback\Contracts\Data\ServiceFeedbackType;
use Modules\Feedback\Domain\Models\EmployeeServiceFeedback;
use Tests\Support\PublicFeedbackScenario;

/*
 * QA brief §12–14 and §65–68: a submission keeps its submission-time context
 * after a transfer or a rename; rows survive archiving of the employee, the
 * institution and the QR; a hard delete of an employee is refused rather
 * than cascading through public history; a landing request stays within a
 * small query budget; lists are paginated.
 */

beforeEach(function (): void {
    app()->setLocale('en');
    foreach (['service_feedback.complaints.view', 'service_feedback.view_employee_target', 'service_feedback.view_organization_target'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('Super Admin');

    $this->a = PublicFeedbackScenario::employeeTarget('CTA');
    $this->b = PublicFeedbackScenario::employeeTarget('CTB');
    RateLimiter::clear('sf-submit-ip:127.0.0.1');
    $this->orgToken = app(OrganizationFeedbackQrService::class)->ensureActive($this->a->organization, $this->superAdmin)->public_token;
});

it('keeps the submission-time context after the employee is transferred, while a new scan uses the new context', function (): void {
    $this->post('/service-feedback/'.$this->a->token->token.'/complaint', pfComplete('complaint', ['message' => 'Before transfer.']))->assertRedirect();
    $before = EmployeeServiceFeedback::query()->sole();

    $this->a->assignment->forceFill(['is_current' => false, 'effective_to' => now()->toDateString()])->save();
    $transfer = EmployeeAssignment::query()->create(['employee_id' => $this->a->employee->id, 'organization_id' => $this->b->organization->id, 'organization_unit_id' => $this->b->unit->id, 'position_id' => $this->b->position->id, 'is_current' => true, 'assignment_status' => AssignmentStatus::Active->value, 'effective_from' => now()->toDateString()]);
    $this->a->employee->forceFill(['current_assignment_id' => $transfer->id])->save();
    RateLimiter::clear('sf-submit:'.$this->a->token->token.'|127.0.0.1');

    $this->post('/service-feedback/'.$this->a->token->token.'/complaint', pfComplete('complaint', ['message' => 'After transfer.']))->assertRedirect();
    $after = EmployeeServiceFeedback::query()->where('comment', 'After transfer.')->sole();

    expect($before->fresh()->organization_id)->toBe($this->a->organization->id)
        ->and($before->fresh()->organization_unit_id)->toBe($this->a->unit->id)
        ->and($before->fresh()->position_id)->toBe($this->a->position->id)
        ->and($before->fresh()->employee_assignment_id)->toBe($this->a->assignment->id)
        ->and($after->organization_id)->toBe($this->b->organization->id)
        ->and($after->employee_assignment_id)->toBe($transfer->id)
        ->and($after->employee_id)->toBe($this->a->employee->id);

    $this->actingAs($this->superAdmin)
        ->get(route('service-submissions.show', ['type' => 'complaint', 'submission' => $before]))
        ->assertInertia(fn (Assert $page) => $page->where('entry.organization', $this->a->organization->name_en)->where('entry.position', $this->a->position->title_en));
});

it('keeps the institution submission intelligible after a rename and keeps its QR valid', function (): void {
    $this->post('/feedback/'.$this->orgToken.'/feedback', pfComplete('feedback', ['rating' => 5, 'comment' => 'Before rename.']))->assertRedirect();
    $row = EmployeeServiceFeedback::query()->sole();
    $oldName = $this->a->organization->name_en;

    $this->a->organization->forceFill(['name_en' => 'Renamed Institution'])->save();

    expect($row->fresh()->target_name_snapshot)->toBe($oldName)
        ->and($row->fresh()->organization_target_id)->toBe($this->a->organization->id);
    $this->get('/feedback/'.$this->orgToken)->assertOk()->assertInertia(fn (Assert $page) => $page->where('available', true)->where('target.display_name', 'Renamed Institution'));
    $this->actingAs($this->superAdmin)->get(route('service-feedback.admin.show', $row))->assertOk();
});

it('keeps rows when the employee leaves, the institution is archived or the QR is revoked, and refuses a hard delete of the employee', function (): void {
    $this->post('/service-feedback/'.$this->a->token->token.'/suggestion', pfComplete('suggestion', ['message' => 'Employee row.']))->assertRedirect();
    $this->post('/feedback/'.$this->orgToken.'/suggestion', pfComplete('suggestion', ['message' => 'Institution row.']))->assertRedirect();
    expect(EmployeeServiceFeedback::query()->count())->toBe(2);

    $this->a->employee->forceFill(['status' => EmployeeStatus::Terminated->value])->save();
    app(EmployeeFeedbackTokenService::class)->revoke($this->a->token, $this->superAdmin);
    app(OrganizationFeedbackQrService::class)->revoke(app(OrganizationFeedbackQrService::class)->activeFor($this->a->organization), 'Archived', $this->superAdmin);
    $this->a->organization->delete(); // soft delete = archive

    expect(EmployeeServiceFeedback::query()->count())->toBe(2)
        ->and(EmployeeServiceFeedback::query()->where('target_type', 'organization')->sole()->organization_target_id)->toBe($this->a->organization->id);
    $this->get('/service-feedback/'.$this->a->token->token)->assertOk()->assertInertia(fn (Assert $page) => $page->where('available', false));
    $this->get('/feedback/'.$this->orgToken)->assertOk()->assertInertia(fn (Assert $page) => $page->where('available', false));

    // A hard delete of the employee must not cascade through public history.
    expect(fn () => DB::table('employees')->where('id', $this->a->employee->id)->delete())->toThrow(QueryException::class);
    expect(EmployeeServiceFeedback::query()->count())->toBe(2)
        ->and(Employee::query()->whereKey($this->a->employee->id)->exists())->toBeTrue();
})->skip(fn () => DB::getDriverName() === 'sqlite' && ! (bool) DB::selectOne('PRAGMA foreign_keys')->foreign_keys, 'SQLite foreign keys are off');

it('resolves a landing page within a small query budget and paginates every list', function (): void {
    DB::enableQueryLog();
    $this->get('/service-feedback/'.$this->a->token->token)->assertOk();
    $employeeQueries = count(DB::getQueryLog());
    DB::flushQueryLog();
    $this->get('/feedback/'.$this->orgToken)->assertOk();
    $organizationQueries = count(DB::getQueryLog());
    DB::flushQueryLog();
    $this->get('/service-feedback/'.$this->a->token->token.'/complaint')->assertOk();
    $formQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Seven queries resolve the target (token, employee, assignment, office context ×3, scan count) plus the
    // page's own catalog; the rest is the public layout's shared props (settings, navigation, footer, announcements).
    expect($employeeQueries)->toBeLessThanOrEqual(14)
        ->and($organizationQueries)->toBeLessThanOrEqual(14)
        ->and($formQueries)->toBeLessThanOrEqual(16);

    for ($i = 0; $i < 25; $i++) {
        EmployeeServiceFeedback::query()->create(['employee_id' => $this->a->employee->id, 'organization_id' => $this->a->organization->id, 'type' => ServiceFeedbackType::Complaint, 'comment' => "Row {$i}", 'status' => ServiceFeedbackStatus::Pending, 'submitted_at' => now()->subMinutes($i)]);
    }
    $this->actingAs($this->superAdmin)->get(route('service-submissions.complaints'))
        ->assertInertia(fn (Assert $page) => $page->where('entries.per_page', 20)->where('entries.total', 25)->has('entries.data', 20));
});
