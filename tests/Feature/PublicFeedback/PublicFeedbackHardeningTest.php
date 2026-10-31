<?php

declare(strict_types=1);

use App\Enums\OrganizationScopeType;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Modules\Feedback\Application\Services\PublicFeedbackAssignmentService;
use Modules\Feedback\Application\Services\PublicFeedbackWorkflow;
use Modules\Feedback\Contracts\Data\ServiceFeedbackStatus;
use Modules\Feedback\Contracts\Data\ServiceFeedbackType;
use Modules\Feedback\Domain\Enums\PublicFeedbackHandlerType;
use Modules\Feedback\Domain\Models\EmployeeServiceFeedback;
use Modules\Feedback\Domain\Models\PublicFeedbackReferral;
use Tests\Support\PublicFeedbackScenario;

/*
 * Brief §121 36, 37, 43, 44 and §112: no management route answers to a
 * user outside the submission's scope or to the employee it is about;
 * sequential identifiers are never accepted; stored text is escaped on
 * output; audit rows never carry the narrative or the contact.
 */

const PFH_ALL = [
    'service_feedback.view', 'service_feedback.complaints.view', 'service_feedback.complaints.review', 'service_feedback.review',
    'service_feedback.view_employee_target', 'service_feedback.view_organization_target', 'service_feedback.view_submitter_identity',
    'service_feedback.view_sensitive_attachments', 'service_feedback.assign', 'service_feedback.reassign', 'service_feedback.resolve',
    'service_feedback.close', 'service_feedback.refer_discipline', 'service_feedback.manage_routing',
];

beforeEach(function (): void {
    app()->setLocale('en');
    Storage::fake('local');

    foreach (PFH_ALL as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    Role::findOrCreate('Full Scoped Admin', 'web')->syncPermissions(PFH_ALL);

    $this->a = PublicFeedbackScenario::employeeTarget('HRD');
    $this->b = PublicFeedbackScenario::employeeTarget('HRD2');
    RateLimiter::clear('sf-submit:'.$this->a->token->token.'|127.0.0.1');

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('Super Admin');

    // Every grant, but scoped to institution B: nothing of A is theirs.
    $this->foreign = User::factory()->create();
    $this->foreign->assignRole('Full Scoped Admin');
    UserOrganizationScope::query()->create(['user_id' => $this->foreign->id, 'organization_id' => $this->b->organization->id, 'scope_type' => OrganizationScopeType::Self]);

    // Every grant, in scope, but the employee the complaint is about.
    $this->target = User::factory()->create(['employee_id' => $this->a->employee->id]);
    $this->target->assignRole('Full Scoped Admin');
    UserOrganizationScope::query()->create(['user_id' => $this->target->id, 'organization_id' => $this->a->organization->id, 'scope_type' => OrganizationScopeType::Self]);

    $this->insider = User::factory()->create();
    $this->insider->assignRole('Full Scoped Admin');
    UserOrganizationScope::query()->create(['user_id' => $this->insider->id, 'organization_id' => $this->a->organization->id, 'scope_type' => OrganizationScopeType::Self]);
});

it('answers 403 on every management route to a foreign-scope admin and to the target employee', function (): void {
    $this->post('/service-feedback/'.$this->a->token->token.'/complaint', pfComplete('complaint', [
        'message' => 'Hardening fixture.', 'client_name' => 'Abebe', 'client_contact' => '0911223344',
        'attachments' => [UploadedFile::fake()->createWithContent('evidence.pdf', "%PDF-1.4\n%fake\n")],
    ]))->assertRedirect();
    $complaint = EmployeeServiceFeedback::query()->sole();
    app(PublicFeedbackAssignmentService::class)->assign($complaint, PublicFeedbackHandlerType::User, (string) $this->insider->id, $this->superAdmin);
    app(PublicFeedbackWorkflow::class)->transition($complaint, ServiceFeedbackStatus::UnderReview, $this->insider);
    $attachment = $complaint->attachments()->sole();
    $referral = PublicFeedbackReferral::query()->create([
        'submission_id' => $complaint->id, 'kind' => 'discipline', 'status' => 'requested', 'notes' => 'x', 'requested_by' => $this->insider->id, 'requested_at' => now(),
    ]);
    $params = ['type' => 'complaint', 'submission' => $complaint];

    $attempts = [
        ['get', route('service-submissions.show', $params), []],
        ['post', route('service-submissions.transition', $params), ['status' => 'resolved']],
        ['post', route('service-submissions.assign', $params), ['handler_type' => 'user', 'handler_id' => (string) $this->insider->id]],
        ['get', route('service-submissions.attachments.download', $params + ['attachment' => $attachment]), []],
        ['post', route('service-submissions.subjects.store', $params), ['employee_id' => $this->b->employee->id]],
        ['post', route('service-submissions.referrals.store', $params), ['notes' => 'Refer.']],
        ['patch', route('service-submissions.referrals.update', $params + ['referral' => $referral]), ['status' => 'accepted']],
    ];

    foreach ([$this->foreign, $this->target] as $user) {
        foreach ($attempts as [$method, $url, $payload]) {
            $this->actingAs($user)->{$method}($url, $payload)->assertForbidden();
        }
    }

    // The insider is allowed; the fixtures are not the problem.
    $this->actingAs($this->insider)->get(route('service-submissions.show', $params))->assertOk();
    expect($complaint->fresh()->status)->toBe(ServiceFeedbackStatus::UnderReview)
        ->and($referral->fresh()->status)->toBe('requested');
});

it('never accepts a sequential or guessed identifier on a public or management route', function (): void {
    $this->get('/service-feedback/1')->assertOk()->assertInertia(fn ($page) => $page->where('available', false));
    $this->get('/feedback/1')->assertOk()->assertInertia(fn ($page) => $page->where('available', false));
    $this->get('/feedback/'.str_repeat('a', 64))->assertOk()->assertInertia(fn ($page) => $page->where('available', false));
    $this->post('/service-feedback/1/complaint', pfComplete('complaint', ['message' => 'x']))->assertRedirect('/service-feedback/1');

    $this->actingAs($this->superAdmin)->get('/service-submissions/complaint/1')->assertNotFound();
    $this->actingAs($this->superAdmin)->get('/service-submissions/complaint/00000000-0000-0000-0000-000000000000')->assertNotFound();
    $this->actingAs($this->superAdmin)->get('/feedback-admin/submission-reports/exports/1')->assertNotFound();
    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
});

it('escapes stored text on every page that shows it', function (): void {
    $payload = '<script>alert("xss")</script><img src=x onerror=alert(1)>';
    $this->post('/service-feedback/'.$this->a->token->token.'/complaint', pfComplete('complaint', ['message' => $payload, 'client_name' => $payload, 'incident_location' => $payload]))->assertRedirect();
    $complaint = EmployeeServiceFeedback::query()->sole();
    expect($complaint->comment)->toBe($payload);

    foreach ([
        route('service-submissions.complaints'),
        route('service-submissions.show', ['type' => 'complaint', 'submission' => $complaint]),
        route('service-feedback.submission-reports.index'),
        route('service-feedback.admin.dashboard'),
    ] as $url) {
        $this->actingAs($this->superAdmin)->get($url)->assertOk()->assertDontSee($payload, false)->assertDontSee('<script>alert', false);
    }
});

it('keeps the narrative and the contact out of the audit trail', function (): void {
    $this->post('/service-feedback/'.$this->a->token->token.'/complaint', pfComplete('complaint', [
        'message' => 'SECRET-NARRATIVE the counter was closed', 'client_name' => 'Abebe Kebede', 'client_contact' => '0911223344',
    ]))->assertRedirect();
    $complaint = EmployeeServiceFeedback::query()->sole();
    app(PublicFeedbackAssignmentService::class)->assign($complaint, PublicFeedbackHandlerType::User, (string) $this->insider->id, $this->superAdmin, 'Handled by the insider');
    app(PublicFeedbackWorkflow::class)->transition($complaint, ServiceFeedbackStatus::UnderReview, $this->insider);
    app(PublicFeedbackWorkflow::class)->transition($complaint, ServiceFeedbackStatus::Resolved, $this->insider, 'Reopened the counter');

    $rows = AuditLog::query()->get();
    expect($rows->count())->toBeGreaterThanOrEqual(4);
    foreach ($rows as $row) {
        $blob = json_encode([$row->old_values, $row->new_values, $row->reason], JSON_UNESCAPED_UNICODE);
        expect($blob)->not->toContain('SECRET-NARRATIVE')->not->toContain('0911223344')->not->toContain('Abebe Kebede');
    }
});

it('keeps the three types strictly on one table with no sibling records in other domains', function (): void {
    foreach (['feedback' => ['position_service_id' => $this->a->positionService->id, 'rating' => 4], 'suggestion' => ['message' => 'S'], 'complaint' => ['message' => 'C']] as $type => $payload) {
        $this->post('/service-feedback/'.$this->a->token->token.'/'.$type, pfComplete($type, $payload))->assertRedirect();
    }

    expect(EmployeeServiceFeedback::query()->pluck('type')->map(fn (ServiceFeedbackType $t) => $t->value)->sort()->values()->all())->toBe(['complaint', 'feedback', 'suggestion'])
        ->and(DB::table('grievances')->count())->toBe(0)
        ->and(DB::table('administrative_tribunal_cases')->count())->toBe(0);
});
