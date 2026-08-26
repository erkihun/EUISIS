<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\AuditEventType;
use App\Enums\EmployeeStatus;
use App\Enums\FeedbackTokenStatus;
use App\Enums\OrganizationScopeType;
use App\Enums\ServiceFeedbackStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeFeedbackToken;
use App\Models\EmployeeServiceFeedback;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PositionService;
use App\Models\ServiceType;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** Build an organization with one employee and a feedback token. */
function feedbackOrg(string $prefix): array
{
    $type = OrganizationType::query()->firstOrCreate(
        ['code' => 'SFA-TYPE'],
        ['name_en' => 'Feedback Admin Type'],
    );

    $org = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => $prefix.'-ORG',
        'name_en' => $prefix.' Organization',
        'status' => 'active',
    ]);

    $unit = OrganizationUnit::query()->create([
        'organization_id' => $org->id,
        'code' => $prefix.'-U1',
        'name_en' => $prefix.' Unit',
        'unit_type' => 'department',
        'status' => 'active',
    ]);

    $position = Position::query()->create([
        'organization_id' => $org->id,
        'organization_unit_id' => $unit->id,
        'job_position_code' => $prefix.'-P1',
        'title_en' => $prefix.' Officer',
        'is_active' => true,
    ]);

    $employee = Employee::query()->create([
        'employee_number' => $prefix.'-EMP',
        'first_name' => 'Test',
        'last_name' => $prefix,
        'full_name' => 'Test '.$prefix,
        'status' => EmployeeStatus::Active->value,
    ]);

    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id,
        'organization_id' => $org->id,
        'organization_unit_id' => $unit->id,
        'position_id' => $position->id,
        'is_current' => true,
        'assignment_status' => AssignmentStatus::Active->value,
        'effective_from' => now()->toDateString(),
    ]);

    $employee->forceFill(['current_assignment_id' => $assignment->id])->save();

    return compact('org', 'unit', 'position', 'employee');
}

/** Record feedback directly, bypassing the public form. */
function makeFeedback(array $context, ?PositionService $service, int $rating, ?string $comment = null): EmployeeServiceFeedback
{
    return EmployeeServiceFeedback::query()->create([
        'employee_id' => $context['employee']->id,
        'organization_id' => $context['org']->id,
        'organization_unit_id' => $context['unit']->id,
        'position_id' => $context['position']->id,
        'position_service_id' => $service?->getKey(),
        'rating' => $rating,
        'comment' => $comment,
        'status' => ServiceFeedbackStatus::Pending,
    ]);
}

beforeEach(function (): void {
    app()->setLocale('en');

    /*
     * Roles and permissions are created explicitly rather than seeded, matching
     * the convention in the other feature suites. The grants mirror RoleSeeder:
     * Organizational Admin deliberately does NOT receive
     * `service_feedback.delete`, which is what the deletion test asserts.
     */
    foreach ([
        'service_feedback.view',
        'service_feedback.review',
        'service_feedback.hide',
        'service_feedback.delete',
        'service_feedback.export',
        'service_feedback.settings.manage',
        'employees.view',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());

    Role::findOrCreate('Organizational Admin', 'web')->syncPermissions([
        'service_feedback.view',
        'service_feedback.review',
        'service_feedback.hide',
        'service_feedback.export',
        'service_feedback.settings.manage',
        'employees.view',
    ]);

    // No feedback permissions at all — the unauthorised-access baseline.
    Role::findOrCreate('Employee', 'web');

    $this->serviceType = ServiceType::query()->create([
        'code' => 'SFA-SVC',
        'name_en' => 'Admin Test Service',
        'is_active' => true,
    ]);

    $this->alpha = feedbackOrg('ALPHA');
    $this->beta = feedbackOrg('BETA');

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('Super Admin');
});

it('lets a super admin view the feedback dashboard', function (): void {
    makeFeedback($this->alpha, null, 5);
    makeFeedback($this->beta, null, 3);

    $this->actingAs($this->superAdmin)
        ->get(route('service-feedback.admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ServiceFeedback/Dashboard')
            ->where('summary.total', 2)
            // round() yields a whole float, which JSON-encodes as `4`.
            ->where('summary.average', fn (float|int $avg): bool => (float) $avg === 4.0)
        );
});

it('aggregates the rating distribution across all five stars', function (): void {
    foreach ([5, 5, 4, 1] as $rating) {
        makeFeedback($this->alpha, null, $rating);
    }

    $this->actingAs($this->superAdmin)
        ->get(route('service-feedback.admin.dashboard'))
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            $distribution = collect($page->toArray()['props']['ratingDistribution'])
                ->pluck('count', 'rating');

            // Every star value is present even when it has no submissions.
            expect($distribution)->toHaveCount(5)
                ->and($distribution[5])->toBe(2)
                ->and($distribution[4])->toBe(1)
                ->and($distribution[3])->toBe(0)
                ->and($distribution[1])->toBe(1);
        });
});

it('computes low rated and pending counts', function (): void {
    makeFeedback($this->alpha, null, 1);
    makeFeedback($this->alpha, null, 2);
    makeFeedback($this->alpha, null, 5);

    $this->actingAs($this->superAdmin)
        ->get(route('service-feedback.admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.low_rated', 2)
            ->where('summary.pending', 3)
        );
});

it('confines an organizational admin to their own organization', function (): void {
    makeFeedback($this->alpha, null, 5, 'Alpha comment');
    makeFeedback($this->beta, null, 2, 'Beta comment');

    $scoped = User::factory()->create();
    $scoped->assignRole('Organizational Admin');

    UserOrganizationScope::query()->create([
        'user_id' => $scoped->id,
        'organization_id' => $this->alpha['org']->id,
        'scope_type' => OrganizationScopeType::Self,
    ]);

    $this->actingAs($scoped)
        ->get(route('service-feedback.admin.index'))
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            $rows = collect($page->toArray()['props']['feedback']['data']);

            expect($rows)->toHaveCount(1)
                ->and($rows->first()['comment'])->toBe('Alpha comment');
        });
});

it('excludes other organizations from a scoped dashboard total', function (): void {
    makeFeedback($this->alpha, null, 5);
    makeFeedback($this->beta, null, 1);
    makeFeedback($this->beta, null, 1);

    $scoped = User::factory()->create();
    $scoped->assignRole('Organizational Admin');

    UserOrganizationScope::query()->create([
        'user_id' => $scoped->id,
        'organization_id' => $this->alpha['org']->id,
        'scope_type' => OrganizationScopeType::Self,
    ]);

    $this->actingAs($scoped)
        ->get(route('service-feedback.admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.total', 1)
            ->where('summary.average', fn (float|int $avg): bool => (float) $avg === 5.0)
        );
});

it('forbids a scoped admin from opening feedback in another organization', function (): void {
    $foreign = makeFeedback($this->beta, null, 2);

    $scoped = User::factory()->create();
    $scoped->assignRole('Organizational Admin');

    UserOrganizationScope::query()->create([
        'user_id' => $scoped->id,
        'organization_id' => $this->alpha['org']->id,
        'scope_type' => OrganizationScopeType::Self,
    ]);

    $this->actingAs($scoped)
        ->get(route('service-feedback.admin.show', $foreign->id))
        ->assertForbidden();
});

it('blocks a user without the view permission from the admin pages', function (): void {
    $outsider = User::factory()->create();
    $outsider->assignRole('Employee');

    $this->actingAs($outsider)->get(route('service-feedback.admin.index'))->assertForbidden();
    $this->actingAs($outsider)->get(route('service-feedback.admin.dashboard'))->assertForbidden();
    $this->actingAs($outsider)->get(route('service-feedback.admin.reports'))->assertForbidden();
});

it('redirects a guest away from the admin pages', function (): void {
    $this->get(route('service-feedback.admin.index'))->assertRedirect();
});

it('marks feedback as reviewed and records the reviewer', function (): void {
    $feedback = makeFeedback($this->alpha, null, 2, 'Slow service');

    $this->actingAs($this->superAdmin)
        ->post(route('service-feedback.admin.review', $feedback->id), [
            'status' => 'reviewed',
            'review_note' => 'Followed up with the unit head.',
        ])
        ->assertRedirect();

    $feedback->refresh();

    expect($feedback->status)->toBe(ServiceFeedbackStatus::Reviewed)
        ->and((string) $feedback->reviewed_by)->toBe((string) $this->superAdmin->id)
        ->and($feedback->review_note)->toBe('Followed up with the unit head.')
        ->and($feedback->reviewed_at)->not->toBeNull();

    expect(AuditLog::query()->where('event_type', AuditEventType::ServiceFeedbackReviewed->value)->exists())
        ->toBeTrue();
});

it('marks feedback as resolved', function (): void {
    $feedback = makeFeedback($this->alpha, null, 1);

    $this->actingAs($this->superAdmin)
        ->post(route('service-feedback.admin.review', $feedback->id), ['status' => 'resolved'])
        ->assertRedirect();

    expect($feedback->fresh()->status)->toBe(ServiceFeedbackStatus::Resolved);

    expect(AuditLog::query()->where('event_type', AuditEventType::ServiceFeedbackResolved->value)->exists())
        ->toBeTrue();
});

it('rejects an unsupported review status', function (): void {
    $feedback = makeFeedback($this->alpha, null, 3);

    $this->actingAs($this->superAdmin)
        ->post(route('service-feedback.admin.review', $feedback->id), ['status' => 'deleted'])
        ->assertSessionHasErrors('status');
});

it('hides and restores a feedback entry', function (): void {
    $feedback = makeFeedback($this->alpha, null, 1, 'Abusive comment');

    $this->actingAs($this->superAdmin)
        ->post(route('service-feedback.admin.hide', $feedback->id))
        ->assertRedirect();

    expect($feedback->fresh()->status)->toBe(ServiceFeedbackStatus::Hidden);

    // The same endpoint toggles back, so a mistaken hide is reversible.
    $this->actingAs($this->superAdmin)
        ->post(route('service-feedback.admin.hide', $feedback->id))
        ->assertRedirect();

    expect($feedback->fresh()->status)->toBe(ServiceFeedbackStatus::Pending);
});

it('keeps hidden feedback out of the dashboard comment feed', function (): void {
    $hidden = makeFeedback($this->alpha, null, 1, 'Hidden comment');
    makeFeedback($this->alpha, null, 5, 'Visible comment');

    $hidden->forceFill(['status' => ServiceFeedbackStatus::Hidden])->save();

    $this->actingAs($this->superAdmin)
        ->get(route('service-feedback.admin.dashboard'))
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            $comments = collect($page->toArray()['props']['recentComments'])->pluck('comment');

            expect($comments)->toContain('Visible comment')
                ->and($comments)->not->toContain('Hidden comment');
        });
});

it('does not let an organizational admin delete feedback', function (): void {
    $feedback = makeFeedback($this->alpha, null, 1);

    $scoped = User::factory()->create();
    $scoped->assignRole('Organizational Admin');

    UserOrganizationScope::query()->create([
        'user_id' => $scoped->id,
        'organization_id' => $this->alpha['org']->id,
        'scope_type' => OrganizationScopeType::Self,
    ]);

    // Deliberately withheld from the role: destruction of client feedback is
    // reserved for Super/System Admin. Hiding is the scoped admin's tool.
    $this->actingAs($scoped)
        ->delete(route('service-feedback.admin.destroy', $feedback->id))
        ->assertForbidden();

    expect(EmployeeServiceFeedback::query()->whereKey($feedback->id)->exists())->toBeTrue();
});

it('lets a super admin delete feedback and audits it first', function (): void {
    $feedback = makeFeedback($this->alpha, null, 1);

    $this->actingAs($this->superAdmin)
        ->delete(route('service-feedback.admin.destroy', $feedback->id))
        ->assertRedirect();

    expect(EmployeeServiceFeedback::query()->whereKey($feedback->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('event_type', AuditEventType::ServiceFeedbackDeleted->value)->exists())
        ->toBeTrue();
});

it('filters the feedback list by rating', function (): void {
    makeFeedback($this->alpha, null, 5);
    makeFeedback($this->alpha, null, 1);

    $this->actingAs($this->superAdmin)
        ->get(route('service-feedback.admin.index', ['rating' => 1]))
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            $rows = collect($page->toArray()['props']['feedback']['data']);

            expect($rows)->toHaveCount(1)
                ->and($rows->first()['rating'])->toBe(1);
        });
});

it('filters the feedback list by organization', function (): void {
    makeFeedback($this->alpha, null, 5);
    makeFeedback($this->beta, null, 4);

    $this->actingAs($this->superAdmin)
        ->get(route('service-feedback.admin.index', ['organization_id' => $this->beta['org']->id]))
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            expect(collect($page->toArray()['props']['feedback']['data']))->toHaveCount(1);
        });
});

it('never exposes submitter ip or user agent to the admin screens', function (): void {
    $feedback = makeFeedback($this->alpha, null, 3, 'A comment');
    $feedback->forceFill(['ip_address' => '203.0.113.9', 'user_agent' => 'ScannerBot/9.9'])->save();

    $response = $this->actingAs($this->superAdmin)
        ->get(route('service-feedback.admin.show', $feedback->id))
        ->assertOk();

    expect($response->getContent())->not->toContain('203.0.113.9')
        ->and($response->getContent())->not->toContain('ScannerBot');
});

it('shows the low rating report', function (): void {
    makeFeedback($this->alpha, null, 1, 'Very poor');
    makeFeedback($this->alpha, null, 5, 'Excellent');

    $this->actingAs($this->superAdmin)
        ->get(route('service-feedback.admin.reports'))
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            $low = collect($page->toArray()['props']['lowRated'])->pluck('comment');

            expect($low)->toContain('Very poor')
                ->and($low)->not->toContain('Excellent');
        });
});

it('exports filtered feedback as csv', function (): void {
    makeFeedback($this->alpha, null, 4, 'Exported comment');

    $response = $this->actingAs($this->superAdmin)
        ->get(route('service-feedback.admin.export'))
        ->assertOk();

    $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $csv = $response->streamedContent();

    expect($csv)->toContain('Exported comment')
        ->and($csv)->toContain('Test ALPHA')
        // The header row must be present for Excel to parse the columns.
        ->and($csv)->toContain('Submitted At');

    expect(AuditLog::query()->where('event_type', AuditEventType::ServiceFeedbackExported->value)->exists())
        ->toBeTrue();
});

it('confines a scoped admin export to their own organization', function (): void {
    makeFeedback($this->alpha, null, 5, 'Alpha only');
    makeFeedback($this->beta, null, 5, 'Beta secret');

    $scoped = User::factory()->create();
    $scoped->assignRole('Organizational Admin');

    UserOrganizationScope::query()->create([
        'user_id' => $scoped->id,
        'organization_id' => $this->alpha['org']->id,
        'scope_type' => OrganizationScopeType::Self,
    ]);

    $csv = $this->actingAs($scoped)
        ->get(route('service-feedback.admin.export'))
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('Alpha only')
        ->and($csv)->not->toContain('Beta secret');
});

it('opens the employee feedback qr page with a ready-to-scan token', function (): void {
    $employee = $this->alpha['employee'];

    // The page provisions on load, so the QR is present on the first visit
    // rather than requiring an explicit "Generate" press.
    $this->actingAs($this->superAdmin)
        ->get(route('employees.feedback-qr.show', $employee->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ServiceFeedback/EmployeeQr')
            ->where('token.status', 'active')
        );

    expect(EmployeeFeedbackToken::query()->where('employee_id', $employee->id)->exists())->toBeTrue();

    expect(AuditLog::query()->where('event_type', AuditEventType::ServiceFeedbackQrGenerated->value)->exists())
        ->toBeTrue();
});

it('exports the feedback qr as a png image', function (): void {
    $employee = $this->alpha['employee'];

    $this->actingAs($this->superAdmin)
        ->post(route('employees.feedback-qr.generate', $employee->id))
        ->assertRedirect();

    $response = $this->actingAs($this->superAdmin)
        ->get(route('employees.feedback-qr.png', $employee->id))
        ->assertOk();

    $response->assertHeader('content-type', 'image/png');

    // Verify real PNG bytes rather than an empty or error body.
    expect(substr($response->getContent(), 0, 4))->toBe("\x89PNG");
});

it('returns 404 for a png export when no token exists', function (): void {
    $this->actingAs($this->superAdmin)
        ->get(route('employees.feedback-qr.png', $this->alpha['employee']->id))
        ->assertNotFound();
});

it('regenerates the qr and retires the previous token', function (): void {
    $employee = $this->alpha['employee'];

    $this->actingAs($this->superAdmin)->post(route('employees.feedback-qr.generate', $employee->id));

    $original = EmployeeFeedbackToken::query()->where('employee_id', $employee->id)->firstOrFail();

    $this->actingAs($this->superAdmin)
        ->post(route('employees.feedback-qr.regenerate', $employee->id))
        ->assertRedirect();

    expect($original->fresh()->status)->toBe(FeedbackTokenStatus::Revoked)
        ->and(EmployeeFeedbackToken::query()->where('employee_id', $employee->id)->count())->toBe(2);
});

it('revokes the qr token', function (): void {
    $employee = $this->alpha['employee'];

    $this->actingAs($this->superAdmin)->post(route('employees.feedback-qr.generate', $employee->id));
    $this->actingAs($this->superAdmin)
        ->post(route('employees.feedback-qr.revoke', $employee->id))
        ->assertRedirect();

    expect(EmployeeFeedbackToken::query()->where('employee_id', $employee->id)->first()->status)
        ->toBe(FeedbackTokenStatus::Revoked);

    expect(AuditLog::query()->where('event_type', AuditEventType::ServiceFeedbackQrRevoked->value)->exists())
        ->toBeTrue();
});

it('blocks qr management without the settings permission', function (): void {
    $outsider = User::factory()->create();
    $outsider->assignRole('Employee');

    $this->actingAs($outsider)
        ->get(route('employees.feedback-qr.show', $this->alpha['employee']->id))
        ->assertForbidden();
});

it('shows employee feedback stats excluding hidden entries', function (): void {
    $employee = $this->alpha['employee'];

    makeFeedback($this->alpha, null, 5);
    $hidden = makeFeedback($this->alpha, null, 1);
    $hidden->forceFill(['status' => ServiceFeedbackStatus::Hidden])->save();

    $this->actingAs($this->superAdmin)
        ->get(route('employees.feedback-qr.show', $employee->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // A suppressed 1-star must not drag the visible average down.
            ->where('stats.total', 1)
            ->where('stats.average', fn (float|int $avg): bool => (float) $avg === 5.0)
        );
});

it('does not shadow the public feedback route with the admin routes', function (): void {
    $employee = $this->alpha['employee'];

    $this->actingAs($this->superAdmin)->post(route('employees.feedback-qr.generate', $employee->id));

    $token = EmployeeFeedbackToken::query()->where('employee_id', $employee->id)->firstOrFail();

    // The public page must still resolve for an anonymous visitor.
    auth()->logout();

    $this->get('/service-feedback/'.$token->token)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/ServiceFeedback')
            ->where('available', true)
        );
});

/*
 * Automatic token provisioning.
 *
 * An active employee must always have a scannable feedback QR without an
 * administrator having to press "Generate" first — but auto-provisioning must
 * not override a deliberate revocation, nor issue a public URL for someone who
 * has left the service.
 */

it('provisions a feedback token automatically when the qr page is opened', function (): void {
    $employee = $this->alpha['employee'];

    expect(EmployeeFeedbackToken::query()->where('employee_id', $employee->id)->exists())->toBeFalse();

    $this->actingAs($this->superAdmin)
        ->get(route('employees.feedback-qr.show', $employee->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // The card renders with a live QR on first visit.
            ->where('token.status', 'active')
            ->where('unavailableReason', null)
        );

    $token = EmployeeFeedbackToken::query()->where('employee_id', $employee->id)->firstOrFail();

    expect($token->status)->toBe(FeedbackTokenStatus::Active)
        ->and(strlen($token->token))->toBe(64);
});

it('does not rotate the token on repeat visits to the qr page', function (): void {
    $employee = $this->alpha['employee'];

    $this->actingAs($this->superAdmin)->get(route('employees.feedback-qr.show', $employee->id))->assertOk();
    $first = EmployeeFeedbackToken::query()->where('employee_id', $employee->id)->firstOrFail();

    $this->actingAs($this->superAdmin)->get(route('employees.feedback-qr.show', $employee->id))->assertOk();

    // A printed QR must keep working no matter how often the page is opened.
    expect(EmployeeFeedbackToken::query()->where('employee_id', $employee->id)->count())->toBe(1)
        ->and(EmployeeFeedbackToken::query()->firstOrFail()->token)->toBe($first->token);
});

it('does not resurrect a token an administrator revoked', function (): void {
    $employee = $this->alpha['employee'];

    $this->actingAs($this->superAdmin)->get(route('employees.feedback-qr.show', $employee->id))->assertOk();
    $this->actingAs($this->superAdmin)->post(route('employees.feedback-qr.revoke', $employee->id))->assertRedirect();

    // Revisiting must respect the revocation rather than silently re-issuing.
    $this->actingAs($this->superAdmin)
        ->get(route('employees.feedback-qr.show', $employee->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('token', null)
            ->where('unavailableReason', 'disabled')
        );

    expect(EmployeeFeedbackToken::query()->where('employee_id', $employee->id)->where('status', 'active')->exists())
        ->toBeFalse();
});

it('does not auto-provision a token for an inactive employee', function (): void {
    $employee = $this->alpha['employee'];
    $employee->forceFill(['status' => EmployeeStatus::Terminated->value])->save();

    $this->actingAs($this->superAdmin)
        ->get(route('employees.feedback-qr.show', $employee->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('token', null)
            ->where('unavailableReason', 'inactive_employee')
        );

    expect(EmployeeFeedbackToken::query()->where('employee_id', $employee->id)->exists())->toBeFalse();
});

it('lets an admin explicitly generate a token after revoking one', function (): void {
    $employee = $this->alpha['employee'];

    $this->actingAs($this->superAdmin)->get(route('employees.feedback-qr.show', $employee->id))->assertOk();
    $this->actingAs($this->superAdmin)->post(route('employees.feedback-qr.revoke', $employee->id))->assertRedirect();

    // The explicit button still works — only implicit provisioning defers.
    $this->actingAs($this->superAdmin)
        ->post(route('employees.feedback-qr.generate', $employee->id))
        ->assertRedirect();

    expect(EmployeeFeedbackToken::query()->where('employee_id', $employee->id)->where('status', 'active')->exists())
        ->toBeTrue();
});

it('issues a distinct token per employee', function (): void {
    $this->actingAs($this->superAdmin)->get(route('employees.feedback-qr.show', $this->alpha['employee']->id));
    $this->actingAs($this->superAdmin)->get(route('employees.feedback-qr.show', $this->beta['employee']->id));

    $tokens = EmployeeFeedbackToken::query()->pluck('token');

    expect($tokens)->toHaveCount(2)
        ->and($tokens->unique())->toHaveCount(2);
});

it('makes an auto-provisioned token immediately scannable by the public', function (): void {
    $employee = $this->alpha['employee'];

    $this->actingAs($this->superAdmin)->get(route('employees.feedback-qr.show', $employee->id))->assertOk();

    $token = EmployeeFeedbackToken::query()->where('employee_id', $employee->id)->firstOrFail();

    // The service must be attached to this employee's position before a client
    // can rate it — services belong to the role that performs them.
    $positionService = PositionService::query()->create([
        'organization_id' => $this->alpha['org']->id,
        'position_id' => $this->alpha['position']->id,
        'service_no' => 'ALPHA-001',
        'name_en' => 'Admin Test Service',
        'is_active' => true,
        'is_performance_evaluation_enabled' => true,
        'sort_order' => 0,
    ]);

    auth()->logout();

    // End to end: provisioned by an admin page load, scanned by an anonymous client.
    $this->get('/service-feedback/'.$token->token)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/ServiceFeedback')
            ->where('available', true)
        );

    $this->post('/service-feedback/'.$token->token, [
        'position_service_id' => $positionService->id,
        'rating' => 5,
        'comment' => 'Auto-provisioned QR works.',
    ])->assertRedirect();

    expect(EmployeeServiceFeedback::query()->where('employee_id', $employee->id)->count())->toBe(1);
});
