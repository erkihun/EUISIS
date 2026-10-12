<?php

declare(strict_types=1);

use App\Enums\OrganizationScopeType;
use App\Enums\ServiceFeedbackStatus;
use App\Enums\ServiceFeedbackType;
use App\Models\EmployeeServiceFeedback;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\ServiceFeedback\OrganizationFeedbackQrService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PublicFeedbackScenario;

/*
 * Granular visibility (brief §36, 55–57, 86): seeing a submission is not
 * seeing who sent it, nor its evidence; the two target types are separate
 * grants; contact data is encrypted at rest.
 */

const PF_VIEW = ['service_feedback.view', 'service_feedback.complaints.view', 'service_feedback.complaints.review', 'service_feedback.export'];
const PF_TARGETS = ['service_feedback.view_employee_target', 'service_feedback.view_organization_target'];

beforeEach(function (): void {
    app()->setLocale('en');
    Storage::fake('local');

    foreach ([...PF_VIEW, ...PF_TARGETS, 'service_feedback.view_submitter_identity', 'service_feedback.view_sensitive_attachments', 'service_feedback.review'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    Role::findOrCreate('Reviewer', 'web')->syncPermissions([...PF_VIEW, ...PF_TARGETS]);
    Role::findOrCreate('Identity Reviewer', 'web')->syncPermissions([...PF_VIEW, ...PF_TARGETS, 'service_feedback.view_submitter_identity', 'service_feedback.view_sensitive_attachments']);
    Role::findOrCreate('Employee-only Reviewer', 'web')->syncPermissions([...PF_VIEW, 'service_feedback.view_employee_target']);
    Role::findOrCreate('Blind Reviewer', 'web')->syncPermissions(PF_VIEW);

    $this->a = PublicFeedbackScenario::employeeTarget('VIS');
    RateLimiter::clear('sf-submit:'.$this->a->token->token.'|127.0.0.1');
    RateLimiter::clear('sf-submit-ip:127.0.0.1');

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('Super Admin');
    $this->orgToken = app(OrganizationFeedbackQrService::class)->ensureActive($this->a->organization, $this->superAdmin)->public_token;
    RateLimiter::clear('sf-submit:'.$this->orgToken.'|127.0.0.1');

    foreach (['reviewer' => 'Reviewer', 'identityReviewer' => 'Identity Reviewer', 'employeeOnly' => 'Employee-only Reviewer', 'blind' => 'Blind Reviewer'] as $property => $role) {
        $this->{$property} = User::factory()->create();
        $this->{$property}->assignRole($role);
        UserOrganizationScope::query()->create([
            'user_id' => $this->{$property}->id,
            'organization_id' => $this->a->organization->id,
            'scope_type' => OrganizationScopeType::Self,
        ]);
    }
});

function visComplaint(array $overrides = []): EmployeeServiceFeedback
{
    return EmployeeServiceFeedback::query()->create(array_merge([
        'employee_id' => test()->a->employee->id,
        'organization_id' => test()->a->organization->id,
        'type' => ServiceFeedbackType::Complaint,
        'comment' => 'Visibility fixture',
        'client_name' => 'Abebe Kebede',
        'client_contact' => '0911223344',
        'status' => ServiceFeedbackStatus::Assigned,
        'submitted_at' => now(),
    ], $overrides));
}

it('masks the submitter name and contact unless the user may view submitter identity', function (): void {
    $complaint = visComplaint();

    $this->actingAs($this->reviewer)
        ->get(route('service-submissions.show', ['type' => 'complaint', 'submission' => $complaint]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('entry.client_name', null)
            ->where('entry.client_contact', null)
            ->where('entry.identity_hidden', true));

    $this->actingAs($this->identityReviewer)
        ->get(route('service-submissions.show', ['type' => 'complaint', 'submission' => $complaint]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('entry.client_name', 'Abebe Kebede')
            ->where('entry.client_contact', '0911223344')
            ->where('entry.identity_hidden', false));

    // The rating inbox applies the same rule.
    $rating = visComplaint(['type' => ServiceFeedbackType::Feedback, 'rating' => 2, 'status' => ServiceFeedbackStatus::Pending]);
    $this->actingAs($this->reviewer)
        ->get(route('service-feedback.admin.show', $rating))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('feedback.client_name', null)->where('feedback.client_contact', null)->where('feedback.identity_hidden', true));
    $this->actingAs($this->identityReviewer)
        ->get(route('service-feedback.admin.show', $rating))
        ->assertInertia(fn (Assert $page) => $page->where('feedback.client_contact', '0911223344'));
});

it('keeps submitter identity out of the csv export without the permission', function (): void {
    visComplaint(['type' => ServiceFeedbackType::Feedback, 'rating' => 1, 'status' => ServiceFeedbackStatus::Pending, 'comment' => 'Exported row']);

    $masked = $this->actingAs($this->reviewer)->get(route('service-feedback.admin.export'))->assertOk()->streamedContent();
    expect($masked)->toContain('Exported row')->not->toContain('0911223344')->not->toContain('Abebe Kebede');

    $full = $this->actingAs($this->identityReviewer)->get(route('service-feedback.admin.export'))->assertOk()->streamedContent();
    expect($full)->toContain('0911223344');
});

it('encrypts the submitter contact at rest and still reads rows written before encryption', function (): void {
    $this->post('/service-feedback/'.$this->a->token->token.'/complaint', [
        'message' => 'Encrypted contact.', 'client_name' => 'Abebe', 'client_contact' => '0911223344',
    ])->assertRedirect();

    $row = EmployeeServiceFeedback::query()->sole();
    $raw = DB::table('employee_service_feedback')->where('id', $row->id)->value('client_contact');

    expect($raw)->not->toBe('0911223344')
        ->and($row->client_contact)->toBe('0911223344');

    DB::table('employee_service_feedback')->insert([
        'id' => (string) Str::uuid(), 'employee_id' => $this->a->employee->id, 'organization_id' => $this->a->organization->id,
        'target_type' => 'employee', 'source_type' => 'employee_card_qr', 'type' => 'suggestion', 'comment' => 'Legacy row',
        'client_contact' => '0922334455', 'status' => 'pending', 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(EmployeeServiceFeedback::query()->where('comment', 'Legacy row')->sole()->client_contact)->toBe('0922334455');
});

it('requires the sensitive attachments permission to list and download complaint evidence', function (): void {
    $this->post('/service-feedback/'.$this->a->token->token.'/complaint', [
        'message' => 'With evidence.',
        'attachments' => [UploadedFile::fake()->create('evidence.pdf', 10, 'application/pdf')],
    ])->assertRedirect();
    $complaint = EmployeeServiceFeedback::query()->sole();
    $attachment = $complaint->attachments()->sole();

    $this->actingAs($this->reviewer)
        ->get(route('service-submissions.show', ['type' => 'complaint', 'submission' => $complaint]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('entry.attachments', [])
            ->where('entry.attachment_count', 1)
            ->where('entry.attachments_hidden', true));
    $this->actingAs($this->reviewer)
        ->get(route('service-submissions.attachments.download', ['type' => 'complaint', 'submission' => $complaint, 'attachment' => $attachment]))
        ->assertForbidden();

    $this->actingAs($this->identityReviewer)
        ->get(route('service-submissions.show', ['type' => 'complaint', 'submission' => $complaint]))
        ->assertInertia(fn (Assert $page) => $page->has('entry.attachments', 1)->where('entry.attachments_hidden', false));
    $this->actingAs($this->identityReviewer)
        ->get(route('service-submissions.attachments.download', ['type' => 'complaint', 'submission' => $complaint, 'attachment' => $attachment]))
        ->assertOk();
});

it('limits the inbox and the detail page to the target types the user may view', function (): void {
    $employeeTargeted = visComplaint();
    $organizationTargeted = visComplaint([
        'employee_id' => null, 'organization_target_id' => $this->a->organization->id,
        'target_type' => 'organization', 'source_type' => 'organization_feedback_qr',
    ]);

    $this->actingAs($this->reviewer)->get(route('service-submissions.complaints'))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 2));

    $this->actingAs($this->employeeOnly)->get(route('service-submissions.complaints'))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 1)->where('entries.data.0.id', $employeeTargeted->id));
    $this->actingAs($this->employeeOnly)->get(route('service-submissions.show', ['type' => 'complaint', 'submission' => $organizationTargeted]))->assertForbidden();
    $this->actingAs($this->employeeOnly)->get(route('service-submissions.show', ['type' => 'complaint', 'submission' => $employeeTargeted]))->assertOk();

    $this->actingAs($this->blind)->get(route('service-submissions.complaints'))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 0));
    $this->actingAs($this->blind)->get(route('service-submissions.show', ['type' => 'complaint', 'submission' => $employeeTargeted]))->assertForbidden();

    // The rating inbox counts only what the user may see as well.
    visComplaint(['type' => ServiceFeedbackType::Feedback, 'rating' => 4, 'status' => ServiceFeedbackStatus::Pending]);
    visComplaint(['type' => ServiceFeedbackType::Feedback, 'rating' => 5, 'status' => ServiceFeedbackStatus::Pending, 'employee_id' => null,
        'organization_target_id' => $this->a->organization->id, 'target_type' => 'organization', 'source_type' => 'organization_feedback_qr']);
    $this->actingAs($this->employeeOnly)->get(route('service-feedback.admin.index'))
        ->assertInertia(fn (Assert $page) => $page->where('feedback.total', 1));
    $this->actingAs($this->reviewer)->get(route('service-feedback.admin.index'))
        ->assertInertia(fn (Assert $page) => $page->where('feedback.total', 2));
});
