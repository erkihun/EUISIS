<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\EmployeeStatus;
use App\Enums\OrganizationScopeType;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\OrganizationUnit;
use App\Models\Permission;
use App\Models\Position;
use App\Models\PositionService;
use App\Models\Role;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Modules\Feedback\Application\Services\EmployeeFeedbackTokenService;
use Modules\Feedback\Application\Services\OrganizationFeedbackQrService;
use Modules\Feedback\Application\Services\PublicFeedbackTargetResolver;
use Modules\Feedback\Application\Services\PublicFeedbackWorkflow;
use Modules\Feedback\Contracts\Data\ServiceFeedbackStatus;
use Modules\Feedback\Contracts\Data\ServiceFeedbackType;
use Modules\Feedback\Domain\Models\EmployeeFeedbackToken;
use Modules\Feedback\Domain\Models\EmployeeServiceFeedback;
use Modules\Feedback\Domain\Models\OrganizationFeedbackQrCode;
use Modules\Feedback\Domain\Models\PublicFeedbackRoutingRule;
use Tests\Support\PublicFeedbackScenario;

/*
 * QA brief §6–7: neither QR identity moves because of ordinary life in the
 * organisation. The employee token survives position, unit and institution
 * changes, catalog and routing changes and workflow activity; the institution
 * token survives renames, branding, structure versions, administrators and
 * configuration. Rotation is explicit and authorised (§96).
 */

beforeEach(function (): void {
    app()->setLocale('en');
    Permission::findOrCreate('service_feedback.organization_qr.rotate', 'web');
    Permission::findOrCreate('service_feedback.organization_qr.view', 'web');
    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('Super Admin');

    $this->a = PublicFeedbackScenario::employeeTarget('STA');
    $this->b = PublicFeedbackScenario::employeeTarget('STB');
});

it('keeps the employee feedback token through position, unit, institution, status, catalog, routing and workflow changes', function (): void {
    $employee = $this->a->employee;
    $token = $this->a->token->token;
    $tokens = app(EmployeeFeedbackTokenService::class);

    // A complaint exists so that workflow activity can happen on it.
    $complaint = EmployeeServiceFeedback::query()->create([
        'employee_id' => $employee->id, 'organization_id' => $this->a->organization->id, 'type' => ServiceFeedbackType::Complaint,
        'comment' => 'x', 'status' => ServiceFeedbackStatus::Assigned, 'submitted_at' => now(),
    ]);

    // Position and unit change inside the institution.
    $otherUnit = OrganizationUnit::query()->create(['organization_id' => $this->a->organization->id, 'unit_type' => 'department', 'code' => 'STA-U2', 'name_en' => 'Records', 'status' => 'active']);
    $otherPosition = Position::query()->create(['organization_id' => $this->a->organization->id, 'organization_unit_id' => $otherUnit->id, 'job_position_code' => 'STA-P2', 'title_en' => 'Records Officer', 'is_active' => true]);
    $this->a->assignment->forceFill(['is_current' => false, 'effective_to' => now()->toDateString()])->save();
    $move = EmployeeAssignment::query()->create(['employee_id' => $employee->id, 'organization_id' => $this->a->organization->id, 'organization_unit_id' => $otherUnit->id, 'position_id' => $otherPosition->id, 'is_current' => true, 'assignment_status' => AssignmentStatus::Active->value, 'effective_from' => now()->toDateString()]);
    $employee->forceFill(['current_assignment_id' => $move->id])->save();

    // Transfer to another institution.
    $move->forceFill(['is_current' => false])->save();
    $transfer = EmployeeAssignment::query()->create(['employee_id' => $employee->id, 'organization_id' => $this->b->organization->id, 'organization_unit_id' => $this->b->unit->id, 'position_id' => $this->b->position->id, 'is_current' => true, 'assignment_status' => AssignmentStatus::Active->value, 'effective_from' => now()->toDateString()]);
    $employee->forceFill(['current_assignment_id' => $transfer->id, 'first_name' => 'Renamed'])->save();

    // Suspension and reinstatement, new service, new routing rule, workflow activity.
    $employee->forceFill(['status' => EmployeeStatus::Suspended->value])->save();
    $employee->forceFill(['status' => EmployeeStatus::Active->value])->save();
    PositionService::query()->create(['organization_id' => $this->b->organization->id, 'position_id' => $this->b->position->id, 'service_no' => 'STB-002', 'name_en' => 'New service', 'is_active' => true, 'is_performance_evaluation_enabled' => true, 'sort_order' => 1]);
    PublicFeedbackRoutingRule::query()->create(['organization_id' => $this->b->organization->id, 'handler_type' => 'user', 'handler_id' => (string) $this->superAdmin->id, 'priority' => 5, 'is_active' => true, 'created_by' => $this->superAdmin->id]);
    app(PublicFeedbackWorkflow::class)->transition($complaint, ServiceFeedbackStatus::UnderReview, $this->superAdmin);
    app(PublicFeedbackWorkflow::class)->transition($complaint, ServiceFeedbackStatus::Resolved, $this->superAdmin);

    expect($tokens->ensureActiveToken($employee->fresh())->token)->toBe($token)
        ->and(EmployeeFeedbackToken::query()->where('employee_id', $employee->id)->where('status', 'active')->count())->toBe(1)
        ->and(app(PublicFeedbackTargetResolver::class)->resolveEmployeeToken($token)?->employeeId)->toBe($employee->id)
        // A new scan after the transfer carries the new institution's context.
        ->and(app(PublicFeedbackTargetResolver::class)->resolveEmployeeToken($token)?->contextOrganizationId)->toBe($this->b->organization->id)
        // The earlier complaint keeps its submission-time institution.
        ->and($complaint->fresh()->organization_id)->toBe($this->a->organization->id);

    // Only the explicit, audited administrative action rotates it.
    $tokens->regenerate($employee->fresh(), $this->superAdmin);
    expect($tokens->ensureActiveToken($employee->fresh())->token)->not->toBe($token);
});

it('keeps the institution feedback token through rename, branding, structure version, administrator, service and routing changes', function (): void {
    $organization = $this->a->organization;
    $codes = app(OrganizationFeedbackQrService::class);
    $token = $codes->ensureActive($organization, $this->superAdmin)->public_token;

    $organization->forceFill(['name_en' => 'Renamed Bureau', 'name_am' => 'የተቀየረ ቢሮ', 'logo_path' => 'logos/new.png', 'branding_primary_color' => '#112233', 'branding_secondary_color' => '#445566'])->save();
    HierarchyVersion::query()->create(['version_name' => 'Structure 2026-Q4', 'status' => 'draft', 'effective_from' => now()->toDateString()]);
    $newAdmin = User::factory()->create();
    UserOrganizationScope::query()->create(['user_id' => $newAdmin->id, 'organization_id' => $organization->id, 'scope_type' => OrganizationScopeType::Self]);
    PositionService::query()->create(['organization_id' => $organization->id, 'position_id' => $this->a->position->id, 'service_no' => 'STA-002', 'name_en' => 'Another service', 'is_active' => true, 'is_performance_evaluation_enabled' => true, 'sort_order' => 1]);
    PublicFeedbackRoutingRule::query()->create(['organization_id' => $organization->id, 'handler_type' => 'user', 'handler_id' => (string) $this->superAdmin->id, 'priority' => 5, 'is_active' => true, 'created_by' => $this->superAdmin->id]);

    $organization->refresh();
    expect($codes->ensureActive($organization, $this->superAdmin)->public_token)->toBe($token)
        ->and(OrganizationFeedbackQrCode::query()->where('organization_id', $organization->id)->where('status', 'active')->count())->toBe(1)
        ->and(app(PublicFeedbackTargetResolver::class)->resolveOrganizationToken($token)?->organizationTargetId)->toBe($organization->id)
        // The public page shows the current name; history keeps its own snapshot.
        ->and(app(PublicFeedbackTargetResolver::class)->resolveOrganizationToken($token)?->publicDisplay('en')['display_name'])->toBe('Renamed Bureau');

    // The public page still resolves after all of it.
    $this->get('/feedback/'.$token)->assertOk();
});

it('reuses no token and keeps history when an institution code is rotated by an authorised user', function (): void {
    $organization = $this->a->organization;
    $codes = app(OrganizationFeedbackQrService::class);
    $old = $codes->ensureActive($organization, $this->superAdmin);
    $submission = EmployeeServiceFeedback::query()->create([
        'employee_id' => null, 'organization_target_id' => $organization->id, 'target_type' => 'organization', 'source_type' => 'organization_feedback_qr',
        'source_organization_qr_id' => $old->id, 'organization_id' => $organization->id, 'type' => ServiceFeedbackType::Suggestion, 'comment' => 'Before rotation',
        'status' => ServiceFeedbackStatus::Pending, 'submitted_at' => now(), 'target_name_snapshot' => $organization->name_en,
    ]);

    $new = $codes->rotate($old, 'Printed poster compromised', $this->superAdmin);

    expect($new->public_token)->not->toBe($old->public_token)
        ->and($old->fresh()->status->value)->toBe('revoked')
        ->and($new->rotated_from_id)->toBe($old->id)
        ->and(OrganizationFeedbackQrCode::query()->where('public_token', $old->public_token)->count())->toBe(1)
        ->and($submission->fresh()->source_organization_qr_id)->toBe($old->id)
        ->and($submission->fresh()->organization_target_id)->toBe($organization->id);

    $this->get('/feedback/'.$old->public_token)->assertOk()->assertInertia(fn ($page) => $page->where('available', false));
    $this->get('/feedback/'.$new->public_token)->assertOk()->assertInertia(fn ($page) => $page->where('available', true));
});
