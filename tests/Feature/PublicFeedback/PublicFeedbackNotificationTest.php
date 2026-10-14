<?php

declare(strict_types=1);

use App\Actions\SystemSettings\UpdateSystemSettingsGroupAction;
use App\Enums\AssignmentStatus;
use App\Enums\EmployeeStatus;
use App\Enums\OrganizationScopeType;
use App\Enums\PublicFeedbackHandlerType;
use App\Enums\ServiceFeedbackStatus;
use App\Enums\ServiceFeedbackType;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeServiceFeedback;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Notifications\PublicFeedbackAcknowledgement;
use App\Notifications\PublicFeedbackNotification;
use App\Services\ServiceFeedback\PublicFeedbackAssignmentService;
use App\Services\ServiceFeedback\PublicFeedbackWorkflow;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\PublicFeedbackScenario;

/*
 * Internal notices on assignment, reassignment and resolution, delivered
 * after commit through the platform's notification system with a reference
 * and a link only (brief §88); an asynchronous acknowledgement to a
 * submitter who left an e-mail, when the policy allows it (§89).
 */

const PFN_VIEW = ['service_feedback.view', 'service_feedback.complaints.view', 'service_feedback.complaints.review', 'service_feedback.view_employee_target', 'service_feedback.view_organization_target'];

beforeEach(function (): void {
    app()->setLocale('en');

    foreach ([...PFN_VIEW, 'service_feedback.assign', 'service_feedback.reassign', 'service_feedback.resolve', 'service_feedback.manage_routing'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    Role::findOrCreate('Service Quality Team', 'web')->syncPermissions(PFN_VIEW);

    $this->a = PublicFeedbackScenario::employeeTarget('NTF');
    RateLimiter::clear('sf-submit:'.$this->a->token->token.'|127.0.0.1');

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('Super Admin');

    $this->scoped = fn (string $role): User => tap(User::factory()->create(), function (User $user) use ($role): void {
        $user->assignRole($role);
        UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => $this->a->organization->id, 'scope_type' => OrganizationScopeType::Self]);
    });
    $this->handler = ($this->scoped)('Service Quality Team');
    $this->teammate = ($this->scoped)('Service Quality Team');

    // A user whose employee record sits in the licensing unit, and the target employee's own account.
    $colleague = Employee::query()->create(['employee_number' => 'NTF-EMP-2', 'first_name' => 'Kebede', 'last_name' => 'Alemu', 'full_name' => 'Kebede Alemu', 'status' => EmployeeStatus::Active->value]);
    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $colleague->id, 'organization_id' => $this->a->organization->id, 'organization_unit_id' => $this->a->unit->id,
        'position_id' => $this->a->position->id, 'is_current' => true, 'assignment_status' => AssignmentStatus::Active->value, 'effective_from' => now()->toDateString(),
    ]);
    $colleague->forceFill(['current_assignment_id' => $assignment->id])->save();
    $this->unitUser = ($this->scoped)('Service Quality Team');
    $this->unitUser->forceFill(['employee_id' => $colleague->id])->save();
    $this->targetUser = ($this->scoped)('Service Quality Team');
    $this->targetUser->forceFill(['employee_id' => $this->a->employee->id])->save();
});

function ntfComplaint(ServiceFeedbackStatus $status = ServiceFeedbackStatus::Unrouted, array $overrides = []): EmployeeServiceFeedback
{
    return EmployeeServiceFeedback::query()->create(array_merge([
        'employee_id' => test()->a->employee->id,
        'organization_id' => test()->a->organization->id,
        'type' => ServiceFeedbackType::Complaint,
        'comment' => 'The narrative must never travel by mail.',
        'reference_number' => 'PSC-2026-000555',
        'status' => $status,
        'submitted_at' => now(),
    ], $overrides));
}

function ntfSetting(string $key, mixed $value): void
{
    app(UpdateSystemSettingsGroupAction::class)->execute('service_feedback', [$key => $value], test()->superAdmin);
    app(SystemSettingsService::class)->clearCache();
}

it('tells the handler when a submission is handed to them, and the new handler on reassignment', function (): void {
    Notification::fake();
    $complaint = ntfComplaint();

    app(PublicFeedbackAssignmentService::class)->assign($complaint, PublicFeedbackHandlerType::User, (string) $this->handler->id, $this->superAdmin);

    Notification::assertSentTo($this->handler, PublicFeedbackNotification::class, fn (PublicFeedbackNotification $n) => $n->kind === 'assigned' && $n->reference === 'PSC-2026-000555' && str_contains((string) $n->url, $complaint->id));
    Notification::assertNotSentTo($this->superAdmin, PublicFeedbackNotification::class);

    app(PublicFeedbackAssignmentService::class)->assign($complaint, PublicFeedbackHandlerType::User, (string) $this->teammate->id, $this->superAdmin);
    Notification::assertSentTo($this->teammate, PublicFeedbackNotification::class, fn (PublicFeedbackNotification $n) => $n->kind === 'assigned');
    Notification::assertSentToTimes($this->handler, PublicFeedbackNotification::class, 1);
});

it('resolves a role or unit handler to its users, never the employee the complaint is about', function (): void {
    Notification::fake();
    $complaint = ntfComplaint();

    app(PublicFeedbackAssignmentService::class)->assign($complaint, PublicFeedbackHandlerType::Role, (string) Role::findByName('Service Quality Team', 'web')->id, $this->superAdmin);
    Notification::assertSentTo([$this->handler, $this->teammate, $this->unitUser], PublicFeedbackNotification::class);
    Notification::assertNotSentTo($this->targetUser, PublicFeedbackNotification::class);

    Notification::fake();
    app(PublicFeedbackAssignmentService::class)->assign($complaint, PublicFeedbackHandlerType::OrganizationUnit, $this->a->unit->id, $this->superAdmin);
    Notification::assertSentTo($this->unitUser, PublicFeedbackNotification::class, fn (PublicFeedbackNotification $n) => $n->kind === 'assigned');
    Notification::assertNotSentTo([$this->handler, $this->teammate, $this->targetUser], PublicFeedbackNotification::class);
});

it('tells whoever assigned the submission when an outcome is recorded by someone else', function (): void {
    Notification::fake();
    $complaint = ntfComplaint();
    app(PublicFeedbackAssignmentService::class)->assign($complaint, PublicFeedbackHandlerType::User, (string) $this->handler->id, $this->teammate);
    app(PublicFeedbackWorkflow::class)->transition($complaint, ServiceFeedbackStatus::UnderReview, $this->handler);

    app(PublicFeedbackWorkflow::class)->transition($complaint, ServiceFeedbackStatus::Resolved, $this->handler, 'Counter reopened.');

    Notification::assertSentTo($this->teammate, PublicFeedbackNotification::class, fn (PublicFeedbackNotification $n) => $n->kind === 'resolved');
    Notification::assertNotSentTo($this->handler, PublicFeedbackNotification::class, fn (PublicFeedbackNotification $n) => $n->kind === 'resolved');
});

it('keeps the narrative out of the mail and uses the configured channels', function (): void {
    app(UpdateSystemSettingsGroupAction::class)->execute('notifications', ['email_notifications_enabled' => true], $this->superAdmin);
    app(SystemSettingsService::class)->clearCache();
    Notification::fake();
    $complaint = ntfComplaint();

    app(PublicFeedbackAssignmentService::class)->assign($complaint, PublicFeedbackHandlerType::User, (string) $this->handler->id, $this->superAdmin);

    Notification::assertSentTo($this->handler, PublicFeedbackNotification::class, function (PublicFeedbackNotification $n, array $channels) use ($complaint): bool {
        $mail = $n->toMail($this->handler)->toArray();
        $text = json_encode($mail, JSON_UNESCAPED_UNICODE);

        return in_array('mail', $channels, true) && in_array('database', $channels, true)
            && str_contains($text, 'PSC-2026-000555')
            && ! str_contains($text, 'narrative must never')
            && str_contains($text, $complaint->id);
    });
});

it('acknowledges a submitter by e-mail only when the policy allows, and never by phone', function (): void {
    Notification::fake();

    $this->post('/service-feedback/'.$this->a->token->token.'/complaint', pfComplete('complaint', ['message' => 'Quiet.', 'client_name' => 'Abebe', 'client_contact' => '0911223344', 'client_email' => 'abebe@example.com']))->assertRedirect();
    Notification::assertSentOnDemandTimes(PublicFeedbackAcknowledgement::class, 0);

    ntfSetting('acknowledgement_enabled', true);
    $this->post('/service-feedback/'.$this->a->token->token.'/complaint', pfComplete('complaint', ['message' => 'Acknowledged.', 'client_name' => 'Abebe', 'client_contact' => '0911223344', 'client_email' => 'abebe@example.com']))->assertRedirect();
    Notification::assertSentOnDemand(PublicFeedbackAcknowledgement::class, function (PublicFeedbackAcknowledgement $n, array $channels, AnonymousNotifiable $notifiable): bool {
        $text = json_encode($n->toMail($notifiable)->toArray(), JSON_UNESCAPED_UNICODE);

        return $notifiable->routes['mail'] === 'abebe@example.com'
            && $channels === ['mail']
            && str_contains($text, (string) $n->reference)
            && ! str_contains($text, 'Acknowledged.');
    });

    $this->post('/service-feedback/'.$this->a->token->token.'/complaint', pfComplete('complaint', ['message' => 'By phone.', 'client_name' => 'Abebe', 'client_contact' => '0911223344']))->assertRedirect();
    Notification::assertSentOnDemandTimes(PublicFeedbackAcknowledgement::class, 1);
});
