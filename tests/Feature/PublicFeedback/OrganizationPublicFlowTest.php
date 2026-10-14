<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Enums\PublicFeedbackSourceType;
use App\Enums\PublicFeedbackTargetType;
use App\Enums\ServiceFeedbackType;
use App\Models\AuditLog;
use App\Models\EmployeeServiceFeedback;
use App\Models\User;
use App\Services\ServiceFeedback\OrganizationFeedbackQrService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PublicFeedbackScenario;

/*
 * The organization QR opens the same public experience as the employee QR —
 * one landing, three typed forms — and every submission stays attached to
 * the organization the scanned token resolved to (brief §0, 17–20, 27–29,
 * 42, 106). Nothing the browser sends can change the target or the type.
 */

beforeEach(function (): void {
    Storage::fake('local');

    $this->scenario = PublicFeedbackScenario::employeeTarget('OPF');
    $this->organization = $this->scenario->organization;
    $this->admin = User::factory()->create();
    $this->qr = app(OrganizationFeedbackQrService::class)->ensureActive($this->organization, $this->admin);
    $this->token = $this->qr->public_token;

    RateLimiter::clear('sf-submit:'.$this->token.'|127.0.0.1');
    RateLimiter::clear('sf-submit-ip:127.0.0.1');
});

function opfLanding(string $token): string
{
    return '/feedback/'.$token;
}

it('opens the organization landing with the institution identity, three options and no internal data', function (): void {
    $this->organization->update(['logo_path' => 'organizations/logos/test.png']);

    $this->withHeader('X-Locale', 'am')
        ->get(opfLanding($this->token))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/ServiceFeedback')
            ->where('available', true)
            ->where('type', null)
            ->where('target.target_type', 'organization')
            ->where('target.display_name', 'የአስተያየት ተቋም')
            ->where('target.logo_url', '/storage/organizations/logos/test.png')
            ->where('links.feedback', url('/feedback/'.$this->token.'/feedback'))
            ->where('links.suggestion', url('/feedback/'.$this->token.'/suggestion'))
            ->where('links.complaint', url('/feedback/'.$this->token.'/complaint'))
            ->where('links.landing', url('/feedback/'.$this->token))
            ->missing('target.id')
            ->missing('context')
            ->where('serviceTypes', []));

    expect($this->qr->fresh()->scan_count)->toBe(1);
});

it('records a rating about the organization, attached to the organization and the QR that produced it', function (): void {
    $this->post('/feedback/'.$this->token.'/feedback', [
        'rating' => 2,
        'comment' => 'Long queue at the registry.',
        'client_name' => 'Abebe',
    ])->assertRedirect('/feedback/'.$this->token.'/feedback');

    $row = EmployeeServiceFeedback::query()->sole();

    expect($row->type)->toBe(ServiceFeedbackType::Feedback)
        ->and($row->target_type)->toBe(PublicFeedbackTargetType::Organization)
        ->and($row->source_type)->toBe(PublicFeedbackSourceType::OrganizationFeedbackQr)
        ->and($row->organization_target_id)->toBe($this->organization->id)
        ->and($row->employee_id)->toBeNull()
        ->and($row->employee_assignment_id)->toBeNull()
        ->and($row->position_service_id)->toBeNull()
        ->and($row->organization_id)->toBe($this->organization->id)
        ->and($row->source_organization_qr_id)->toBe($this->qr->id)
        ->and($row->target_name_snapshot)->toBe('Feedback Organization')
        ->and($row->target_code_snapshot)->toBe('OPF-ORG')
        ->and($row->rating)->toBe(2)
        ->and($row->submitted_at)->not->toBeNull();

    $this->get('/feedback/'.$this->token.'/feedback')
        ->assertInertia(fn (Assert $page) => $page
            ->where('submitted', true)
            ->where('receipt.type', 'feedback')
            ->where('receipt.target_name', 'Feedback Organization')
            ->has('receipt.submitted_at'));
});

it('records a suggestion and a complaint with its reference and attachment about the organization', function (): void {
    $this->post('/feedback/'.$this->token.'/suggestion', ['message' => 'Publish the required documents online.'])
        ->assertRedirect('/feedback/'.$this->token.'/suggestion');

    $this->post('/feedback/'.$this->token.'/complaint', [
        'message' => 'I was sent between three windows for one form.',
        'incident_date' => now()->subDay()->toDateString(),
        'attachments' => [UploadedFile::fake()->image('queue.png', 80, 80)],
    ])->assertRedirect('/feedback/'.$this->token.'/complaint');

    $rows = EmployeeServiceFeedback::query()->orderBy('created_at')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->type)->toBe(ServiceFeedbackType::Suggestion)
        ->and($rows[0]->organization_target_id)->toBe($this->organization->id)
        ->and($rows[1]->type)->toBe(ServiceFeedbackType::Complaint)
        ->and($rows[1]->reference_number)->toMatch('/^PSC-\d{4}-\d{6}$/')
        ->and($rows[1]->attachments()->count())->toBe(1)
        ->and(AuditLog::query()->where('event_type', AuditEventType::ServiceComplaintSubmitted->value)->value('organization_id'))->toBe($this->organization->id);

    $this->get('/feedback/'.$this->token.'/complaint')
        ->assertInertia(fn (Assert $page) => $page->where('submitted', true)->where('receipt.reference', $rows[1]->reference_number));
});

it('ignores any target or type the browser tries to supply', function (): void {
    $this->post('/feedback/'.$this->token.'/suggestion', [
        'message' => 'Forged fields must not matter.',
        'employee_id' => $this->scenario->employee->id,
        'employee_target_id' => $this->scenario->employee->id,
        'organization_target_id' => PublicFeedbackScenario::employeeTarget('OPF2')->organization->id,
        'target_type' => 'employee',
        'source_type' => 'employee_card_qr',
        'type' => 'complaint',
        'status' => 'resolved',
        'reference_number' => 'PSC-2026-999999',
    ])->assertRedirect();

    $row = EmployeeServiceFeedback::query()->sole();

    expect($row->type)->toBe(ServiceFeedbackType::Suggestion)
        ->and($row->target_type)->toBe(PublicFeedbackTargetType::Organization)
        ->and($row->organization_target_id)->toBe($this->organization->id)
        ->and($row->employee_id)->toBeNull()
        // No routing rule exists here, so the row is unrouted — never the forged 'resolved'.
        ->and($row->status->value)->toBe('unrouted')
        ->and($row->reference_number)->toBeNull();
});

it('does not accept a position service for an organization target', function (): void {
    $this->post('/feedback/'.$this->token.'/feedback', [
        'rating' => 5,
        'position_service_id' => $this->scenario->positionService->id,
    ])->assertSessionHasErrors('position_service_id');

    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
});

it('shows the generic unavailable page and stores nothing for an unknown, revoked or disabled token', function (): void {
    $service = app(OrganizationFeedbackQrService::class);

    $this->get(opfLanding(str_repeat('0', 64)))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('available', false)->where('target', null));

    $service->disable($this->qr, $this->admin);
    $this->get(opfLanding($this->token))->assertOk()->assertInertia(fn (Assert $page) => $page->where('available', false));
    $this->post('/feedback/'.$this->token.'/suggestion', ['message' => 'Still here?'])->assertRedirect(opfLanding($this->token));

    $service->enable($this->qr->fresh(), $this->admin);
    $service->revoke($this->qr->fresh(), 'test', $this->admin);
    $this->get(opfLanding($this->token).'/complaint')->assertOk()->assertInertia(fn (Assert $page) => $page->where('available', false));
    $this->post('/feedback/'.$this->token.'/complaint', ['message' => 'Still here?'])->assertRedirect(opfLanding($this->token));

    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
});

it('shows the generic unavailable page for an inactive organization', function (): void {
    $this->organization->update(['status' => 'inactive']);

    $this->get(opfLanding($this->token))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('available', false));
    $this->post('/feedback/'.$this->token.'/feedback', ['rating' => 4])->assertRedirect(opfLanding($this->token));

    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
});

it('stores an accidental double submit once', function (): void {
    $payload = ['message' => 'The same suggestion, sent twice by a double tap.'];

    $this->post('/feedback/'.$this->token.'/suggestion', $payload)->assertRedirect();
    // Feature requests do not carry cookies automatically; mimic one browser session.
    $this->withCookie(config('session.cookie'), session()->getId());
    $this->post('/feedback/'.$this->token.'/suggestion', $payload)->assertRedirect();

    expect(EmployeeServiceFeedback::query()->count())->toBe(1);

    // A different message is a new submission, not a duplicate.
    $this->post('/feedback/'.$this->token.'/suggestion', ['message' => 'A second, different suggestion.'])->assertRedirect();
    expect(EmployeeServiceFeedback::query()->count())->toBe(2);
});

it('returns the original complaint receipt when a duplicate follows another complaint', function (): void {
    $first = ['message' => 'First complaint for duplicate receipt test.'];
    $second = ['message' => 'Another complaint between retries.'];

    $this->post('/feedback/'.$this->token.'/complaint', $first)->assertRedirect();
    $firstReference = EmployeeServiceFeedback::query()->where('comment', $first['message'])->firstOrFail()->reference_number;

    $this->withCookie(config('session.cookie'), session()->getId());
    $this->post('/feedback/'.$this->token.'/complaint', $second)->assertRedirect();
    $this->post('/feedback/'.$this->token.'/complaint', $first)
        ->assertRedirect()
        ->assertSessionHas('feedback_receipt.reference', $firstReference);

    expect(EmployeeServiceFeedback::query()->count())->toBe(2);
});

it('keeps matching feedback from different visitor sessions as separate submissions', function (): void {
    $payload = ['rating' => 4];

    $this->post('/feedback/'.$this->token.'/feedback', $payload)->assertRedirect();
    session()->regenerate();
    $this->post('/feedback/'.$this->token.'/feedback', $payload)->assertRedirect();

    expect(EmployeeServiceFeedback::query()->count())->toBe(2);
});

it('does not show an organization receipt on an employee form in the same session', function (): void {
    $this->post('/feedback/'.$this->token.'/suggestion', ['message' => 'Organization suggestion.'])->assertRedirect();

    $this->get('/service-feedback/'.$this->scenario->token->token.'/feedback')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/ServiceFeedback')
            ->where('available', true)
            ->where('submitted', false)
            ->where('receipt', null)
            ->where('target.target_type', 'employee'));
});

it('does not show a complaint receipt on another form type for the same QR', function (): void {
    $this->post('/feedback/'.$this->token.'/complaint', ['message' => 'Organization complaint.'])->assertRedirect();

    $this->get('/feedback/'.$this->token.'/suggestion')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/ServiceFeedback')
            ->where('available', true)
            ->where('submitted', false)
            ->where('receipt', null)
            ->where('type', 'suggestion'));
});

it('lets a visitor correct an inapplicable category without refreshing the form', function (): void {
    $formToken = $this->get('/feedback/'.$this->token.'/suggestion')
        ->assertOk()
        ->inertiaProps()['formToken'];

    $this->post('/feedback/'.$this->token.'/suggestion', [
        'message' => 'A suggestion with a category to correct.',
        'category_id' => (string) Str::uuid(),
        'form_token' => $formToken,
    ])->assertSessionHasErrors('category_id');

    $this->post('/feedback/'.$this->token.'/suggestion', [
        'message' => 'A suggestion with a category to correct.',
        'form_token' => $formToken,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(EmployeeServiceFeedback::query()->count())->toBe(1);
});

it('consumes a one-time form token so a replayed form is refused', function (): void {
    $formToken = $this->get(opfLanding($this->token).'/suggestion')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('formToken'))
        ->inertiaProps()['formToken'];

    expect($formToken)->toMatch('/^[0-9a-f]{32}$/');

    $this->post('/feedback/'.$this->token.'/suggestion', ['message' => 'First use.', 'form_token' => $formToken])->assertRedirect();
    $this->post('/feedback/'.$this->token.'/suggestion', ['message' => 'Replay with the same token.', 'form_token' => $formToken])
        ->assertSessionHasErrors('form_token');

    expect(EmployeeServiceFeedback::query()->count())->toBe(1);
});

it('keeps the employee flow attached to the scanned employee when an organization target is forged', function (): void {
    $this->post('/service-feedback/'.$this->scenario->token->token.'/suggestion', [
        'message' => 'Forged organization target on an employee QR.',
        'organization_target_id' => $this->organization->id,
        'target_type' => 'organization',
    ])->assertRedirect();

    $row = EmployeeServiceFeedback::query()->sole();

    expect($row->target_type)->toBe(PublicFeedbackTargetType::Employee)
        ->and($row->employee_id)->toBe($this->scenario->employee->id)
        ->and($row->organization_target_id)->toBeNull();
});
