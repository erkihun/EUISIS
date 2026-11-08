<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Feedback\Contracts\Data\ServiceFeedbackStatus;
use Modules\Feedback\Domain\Models\EmployeeServiceFeedback;
use Tests\Support\PublicFeedbackScenario;

/*
 * Owner request 2026-11-08: every public submission type has a Code Rule
 * reference and its own dashboard, and the public can track a Tip-off or
 * complaint by its reference (status and dates only).
 */

beforeEach(function (): void {
    app()->setLocale('en');
    foreach (['service_feedback.view', 'service_feedback.tip_offs.view', 'service_feedback.complaints.view', 'service_feedback.view_employee_target', 'service_feedback.view_organization_target'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Admin');
    $this->employee = PublicFeedbackScenario::employeeTarget('TRK');
    RateLimiter::clear('sf-submit:'.$this->employee->token->token.'|127.0.0.1');
    RateLimiter::clear('sf-submit-ip:127.0.0.1');
    $this->base = '/service-feedback/'.$this->employee->token->token;
});

it('gives every type its own Code Rule reference', function (): void {
    $this->post($this->base.'/feedback', pfComplete('feedback', ['rating' => 5, 'position_service_id' => $this->employee->positionService->id]))->assertSessionHasNoErrors();
    $this->post($this->base.'/suggestion', ['message' => 'Tip.', 'tip_type' => 'CORRUPTION'])->assertSessionHasNoErrors();
    $this->post($this->base.'/complaint', pfComplete('complaint', ['message' => 'Complaint.']))->assertSessionHasNoErrors();

    $references = EmployeeServiceFeedback::query()->pluck('reference_number', 'type')->all();
    expect($references['feedback'])->toStartWith('PSF-')
        ->and($references['suggestion'])->toStartWith('PTO-')
        ->and($references['complaint'])->toStartWith('PSC-');
});

it('lets the public track a Tip-off by reference and a complaint by reference and phone, showing only the stage', function (): void {
    $this->post($this->base.'/suggestion', ['message' => 'Tip.', 'tip_type' => 'CORRUPTION'])->assertSessionHasNoErrors();
    $this->post($this->base.'/complaint', pfComplete('complaint', ['message' => 'Complaint.', 'client_contact' => '0911 22 33 44']))->assertSessionHasNoErrors();
    $this->post($this->base.'/feedback', pfComplete('feedback', ['rating' => 4, 'position_service_id' => $this->employee->positionService->id]))->assertSessionHasNoErrors();
    $tip = EmployeeServiceFeedback::query()->where('type', 'suggestion')->sole();
    $complaint = EmployeeServiceFeedback::query()->where('type', 'complaint')->sole();
    $feedback = EmployeeServiceFeedback::query()->where('type', 'feedback')->sole();

    $this->get(route('public-submissions.track'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Public/TrackSubmission')->where('result', null));

    // A Tip-off: its reference alone, in any letter case.
    $this->post(route('public-submissions.track.lookup'), ['reference' => strtolower($tip->reference_number)])
        ->assertRedirect(route('public-submissions.track', ['reference' => $tip->reference_number]));
    $this->get(route('public-submissions.track', ['reference' => $tip->reference_number]))
        ->assertInertia(fn (Assert $page) => $page->where('result.reference', $tip->reference_number)->where('result.type', 'suggestion')
            ->where('result.stage', fn ($stage) => in_array($stage, ['received', 'in_review'], true))
            ->missing('result.organization')->missing('result.message'));

    // A complaint needs the phone it was filed with (any common spelling); a wrong one gets the same "not found".
    $this->post(route('public-submissions.track.lookup'), ['reference' => $complaint->reference_number])->assertSessionHasErrors('reference');
    $this->post(route('public-submissions.track.lookup'), ['reference' => $complaint->reference_number, 'phone' => '0911000000'])->assertSessionHasErrors('reference');
    $this->post(route('public-submissions.track.lookup'), ['reference' => $complaint->reference_number, 'phone' => '+251 911 223344'])->assertSessionHasNoErrors();

    // Feedback is not tracked, and an unknown reference is not found.
    $this->post(route('public-submissions.track.lookup'), ['reference' => $feedback->reference_number])->assertSessionHasErrors('reference');
    $this->post(route('public-submissions.track.lookup'), ['reference' => 'PTO-1999-000001'])->assertSessionHasErrors('reference');

    // The public stage follows the workflow.
    $complaint->forceFill(['status' => ServiceFeedbackStatus::Resolved])->save();
    $this->post(route('public-submissions.track.lookup'), ['reference' => $complaint->reference_number, 'phone' => '0911223344']);
    $this->get(route('public-submissions.track'))->assertInertia(fn (Assert $page) => $page->where('result.stage', 'decided'));
});

it('gives Tip-offs and complaints each their own dashboard under their own permission', function (): void {
    $this->post($this->base.'/suggestion', ['message' => 'Tip.', 'tip_type' => 'CORRUPTION', 'tip_form' => ['violation_types' => ['bribery']]])->assertSessionHasNoErrors();
    $this->post($this->base.'/complaint', pfComplete('complaint', ['message' => 'Complaint.']))->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->get(route('tip-offs.dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ServiceFeedback/SubmissionDashboard')->where('type', 'suggestion')
            ->where('figures.total', 1)->where('byTipType.CORRUPTION', 1)->where('violations.bribery', 1)->has('recent', 1));
    $this->actingAs($this->admin)->get(route('public-complaints.dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('type', 'complaint')->where('figures.total', 1)->where('byTipType', null)
            ->where('months', fn ($months) => collect($months)->sum() === 1));

    // Tip-off rights do not open the complaint dashboard.
    $tipOnly = User::factory()->create();
    $tipOnly->givePermissionTo(['service_feedback.view', 'service_feedback.tip_offs.view', 'service_feedback.view_employee_target']);
    $this->actingAs($tipOnly)->get(route('tip-offs.dashboard'))->assertOk();
    $this->actingAs($tipOnly)->get(route('public-complaints.dashboard'))->assertForbidden();
});
