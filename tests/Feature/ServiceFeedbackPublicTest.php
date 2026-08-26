<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\AuditEventType;
use App\Enums\EmployeeStatus;
use App\Enums\FeedbackTokenStatus;
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
use App\Services\ServiceFeedback\EmployeeFeedbackTokenService;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $type = OrganizationType::query()->create(['code' => 'SF-TYPE', 'name_en' => 'Feedback Type']);

    $this->org = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'SF-ORG',
        'name_en' => 'Feedback Organization',
        'status' => 'active',
    ]);

    $this->unit = OrganizationUnit::query()->create([
        'organization_id' => $this->org->id,
        'code' => 'SF-U1',
        'name_en' => 'Licensing Unit',
        'unit_type' => 'department',
        'status' => 'active',
    ]);

    $this->position = Position::query()->create([
        'organization_id' => $this->org->id,
        'organization_unit_id' => $this->unit->id,
        'job_position_code' => 'SF-P1',
        'title_en' => 'Licensing Officer',
        'is_active' => true,
    ]);

    // Sensitive fields are populated on purpose: the leakage test below is only
    // meaningful if there is something real to leak.
    $this->employee = Employee::query()->create([
        'employee_number' => 'SF-EMP-1',
        'first_name' => 'Hanna',
        'last_name' => 'Girma',
        'full_name' => 'Hanna Girma',
        'national_id' => '1122334455667788',
        'phone' => '0912345678',
        'email' => 'hanna.girma@example.test',
        'status' => EmployeeStatus::Active->value,
    ]);

    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $this->employee->id,
        'organization_id' => $this->org->id,
        'organization_unit_id' => $this->unit->id,
        'position_id' => $this->position->id,
        'is_current' => true,
        'assignment_status' => AssignmentStatus::Active->value,
        'effective_from' => now()->toDateString(),
    ]);

    $this->employee->forceFill(['current_assignment_id' => $assignment->id])->save();

    $this->serviceType = ServiceType::query()->create([
        'code' => 'SF-LICENSE',
        'name_en' => 'Business Licensing',
        'name_am' => 'የንግድ ፈቃድ',
        'is_active' => true,
    ]);

    /*
     * Services now belong to the POSITION that performs them, so the catalog
     * entry must be attached to this employee's position before it can be
     * rated. Without this the public form correctly offers nothing.
     */
    $this->positionService = PositionService::query()->create([
        'organization_id' => $this->org->id,
        'position_id' => $this->position->id,
        'service_no' => 'SF-001',
        'name_en' => 'Business Licensing',
        'name_am' => 'የንግድ ፈቃድ',
        'is_active' => true,
        'is_performance_evaluation_enabled' => true,
        'sort_order' => 0,
    ]);

    $this->token = app(EmployeeFeedbackTokenService::class)->ensureActiveToken($this->employee);

    RateLimiter::clear('sf-submit:'.$this->token->token.'|127.0.0.1');
    RateLimiter::clear('sf-submit-ip:127.0.0.1');
});

it('opens the public feedback page with a valid token', function (): void {
    $this->get('/service-feedback/'.$this->token->token)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/ServiceFeedback')
            ->where('available', true)
            ->where('context.organization', 'Feedback Organization')
            ->where('context.organization_unit', 'Licensing Unit')
            ->where('context.position', 'Licensing Officer')
        );
});

it('offers only active services in the public dropdown', function (): void {
    // Same position, but retired — it must not reach the client.
    PositionService::query()->create([
        'organization_id' => $this->org->id,
        'position_id' => $this->position->id,
        'service_no' => 'SF-RETIRED',
        'name_en' => 'Retired Service',
        'is_active' => false,
    ]);

    $this->get('/service-feedback/'.$this->token->token)
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            $numbers = collect($page->toArray()['props']['serviceTypes'])->pluck('service_no');

            expect($numbers)->toContain('SF-001')
                ->and($numbers)->not->toContain('SF-RETIRED');
        });
});

it('returns a safe generic page for an unknown token', function (): void {
    $this->get('/service-feedback/'.str_repeat('a', 64))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/ServiceFeedback')
            ->where('available', false)
            ->where('context', null)
        );
});

it('returns the same generic page for a revoked token', function (): void {
    app(EmployeeFeedbackTokenService::class)->revoke($this->token);

    // Identical shape to the unknown-token response: an attacker holding a
    // token list must not be able to tell "never existed" from "revoked".
    $this->get('/service-feedback/'.$this->token->token)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('available', false)
            ->where('context', null)
        );
});

it('returns the same generic page for a suspended token', function (): void {
    app(EmployeeFeedbackTokenService::class)->suspend($this->token);

    $this->get('/service-feedback/'.$this->token->token)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('available', false));
});

it('never exposes sensitive employee data on the public page', function (): void {
    $response = $this->get('/service-feedback/'.$this->token->token)->assertOk();

    $body = $response->getContent();

    // The employee's real identity and contact details must not appear
    // anywhere in the rendered payload — not in props, not in markup.
    expect($body)->not->toContain('Hanna')
        ->and($body)->not->toContain('Girma')
        ->and($body)->not->toContain('1122334455667788')
        ->and($body)->not->toContain('0912345678')
        ->and($body)->not->toContain('hanna.girma@example.test')
        ->and($body)->not->toContain('SF-EMP-1')
        ->and($body)->not->toContain($this->employee->id);
});

it('shows the position as the masked display name rather than the employee name', function (): void {
    $this->get('/service-feedback/'.$this->token->token)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('context.display_name', 'Licensing Officer')
        );
});

it('lets a client submit a rating and comment', function (): void {
    $this->post('/service-feedback/'.$this->token->token, [
        'position_service_id' => $this->positionService->id,
        'rating' => 5,
        'comment' => 'Fast and courteous service.',
    ])->assertRedirect('/service-feedback/'.$this->token->token);

    $feedback = EmployeeServiceFeedback::query()->first();

    expect($feedback)->not->toBeNull()
        ->and($feedback->rating)->toBe(5)
        ->and($feedback->comment)->toBe('Fast and courteous service.')
        ->and($feedback->employee_id)->toBe($this->employee->id)
        ->and($feedback->status)->toBe(ServiceFeedbackStatus::Pending);
});

it('snapshots the organization, unit and position at submission time', function (): void {
    $this->post('/service-feedback/'.$this->token->token, [
        'position_service_id' => $this->positionService->id,
        'rating' => 4,
    ])->assertRedirect();

    $feedback = EmployeeServiceFeedback::query()->firstOrFail();

    expect($feedback->organization_id)->toBe($this->org->id)
        ->and($feedback->organization_unit_id)->toBe($this->unit->id)
        ->and($feedback->position_id)->toBe($this->position->id);
});

it('accepts an optional client name and contact', function (): void {
    $this->post('/service-feedback/'.$this->token->token, [
        'position_service_id' => $this->positionService->id,
        'rating' => 3,
        'client_name' => 'Abebe Kebede',
        'client_contact' => '0911000000',
    ])->assertRedirect();

    $feedback = EmployeeServiceFeedback::query()->firstOrFail();

    expect($feedback->client_name)->toBe('Abebe Kebede')
        ->and($feedback->client_contact)->toBe('0911000000');
});

it('stores an anonymous submission with no client details', function (): void {
    $this->post('/service-feedback/'.$this->token->token, [
        'position_service_id' => $this->positionService->id,
        'rating' => 2,
        'client_name' => '',
        'client_contact' => '',
    ])->assertRedirect();

    $feedback = EmployeeServiceFeedback::query()->firstOrFail();

    expect($feedback->client_name)->toBeNull()
        ->and($feedback->client_contact)->toBeNull();
});

it('rejects a rating outside 1 to 5', function (int $rating): void {
    $this->post('/service-feedback/'.$this->token->token, [
        'position_service_id' => $this->positionService->id,
        'rating' => $rating,
    ])->assertSessionHasErrors('rating');

    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
})->with([0, 6, -1, 99]);

it('requires a service type and a rating', function (): void {
    $this->post('/service-feedback/'.$this->token->token, [])
        ->assertSessionHasErrors(['position_service_id', 'rating']);
});

it('rejects a service type that is inactive', function (): void {
    $inactive = ServiceType::query()->create([
        'code' => 'SF-OFF',
        'name_en' => 'Inactive Service',
        'is_active' => false,
    ]);

    $this->post('/service-feedback/'.$this->token->token, [
        'position_service_id' => $inactive->id,
        'rating' => 4,
    ])->assertSessionHasErrors('position_service_id');
});

it('does not store feedback submitted against a revoked token', function (): void {
    app(EmployeeFeedbackTokenService::class)->revoke($this->token);

    $this->post('/service-feedback/'.$this->token->token, [
        'position_service_id' => $this->positionService->id,
        'rating' => 5,
    ])->assertRedirect();

    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
});

it('rate limits repeated submissions from one device', function (): void {
    $payload = [
        'position_service_id' => $this->positionService->id,
        'rating' => 5,
    ];

    // The per-token ceiling is 3 per 10 minutes.
    for ($i = 0; $i < 3; $i++) {
        $this->post('/service-feedback/'.$this->token->token, $payload)->assertRedirect();
    }

    $this->post('/service-feedback/'.$this->token->token, $payload)
        ->assertStatus(429);

    expect(EmployeeServiceFeedback::query()->count())->toBe(3);
});

it('writes an audit entry when feedback is submitted', function (): void {
    $this->post('/service-feedback/'.$this->token->token, [
        'position_service_id' => $this->positionService->id,
        'rating' => 1,
    ])->assertRedirect();

    $log = AuditLog::query()
        ->where('event_type', AuditEventType::ServiceFeedbackSubmitted->value)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->organization_id)->toBe($this->org->id)
        // Anonymous submission: there is no actor to attribute.
        ->and($log->actor_user_id)->toBeNull();
});

it('audits a scan of an unknown token', function (): void {
    $this->get('/service-feedback/'.str_repeat('b', 64))->assertOk();

    expect(AuditLog::query()->where('event_type', AuditEventType::ServiceFeedbackBlocked->value)->exists())
        ->toBeTrue();
});

it('issues a long random token that contains no employee identifier', function (): void {
    expect(strlen($this->token->token))->toBe(64)
        ->and($this->token->token)->toMatch('/^[0-9a-f]{64}$/')
        ->and($this->token->token)->not->toContain($this->employee->employee_number);
});

it('reuses the existing active token instead of rotating it', function (): void {
    $again = app(EmployeeFeedbackTokenService::class)->ensureActiveToken($this->employee);

    expect($again->id)->toBe($this->token->id);
});

it('replaces the old token when regenerated', function (): void {
    $fresh = app(EmployeeFeedbackTokenService::class)->regenerate($this->employee);

    expect($fresh->token)->not->toBe($this->token->token)
        ->and($fresh->status)->toBe(FeedbackTokenStatus::Active)
        ->and($this->token->fresh()->status)->toBe(FeedbackTokenStatus::Revoked);

    // The retired QR must stop resolving the moment it is replaced.
    $this->get('/service-feedback/'.$this->token->token)
        ->assertInertia(fn (Assert $page) => $page->where('available', false));
});

it('never reinstates a revoked token', function (): void {
    $service = app(EmployeeFeedbackTokenService::class);
    $service->revoke($this->token);

    expect($service->activate($this->token))->toBeFalse()
        ->and($this->token->fresh()->status)->toBe(FeedbackTokenStatus::Revoked);
});

it('reinstates a suspended token', function (): void {
    $service = app(EmployeeFeedbackTokenService::class);
    $service->suspend($this->token);

    expect($service->activate($this->token))->toBeTrue()
        ->and($this->token->fresh()->status)->toBe(FeedbackTokenStatus::Active);
});

it('does not expose the raw token when the model is serialised', function (): void {
    expect(EmployeeFeedbackToken::query()->firstOrFail()->toArray())
        ->not->toHaveKey('token');
});

it('does not expose submitter ip or user agent when serialised', function (): void {
    $this->post('/service-feedback/'.$this->token->token, [
        'position_service_id' => $this->positionService->id,
        'rating' => 4,
    ])->assertRedirect();

    expect(EmployeeServiceFeedback::query()->firstOrFail()->toArray())
        ->not->toHaveKey('ip_address')
        ->not->toHaveKey('user_agent');
});

it('leaves the existing id checker flow untouched', function (): void {
    // The feedback module must not have altered the card QR route.
    $this->get('/id-checker')->assertOk();
});

/*
 * QR scan behaviour.
 *
 * These assert the property a phone camera actually exercises: the string
 * encoded in the printed QR, fetched verbatim, must land on the feedback page
 * and never on the ID Checker.
 */

it('encodes only the public feedback url in the qr payload', function (): void {
    $payload = $this->token->publicUrl();

    expect($payload)->toBe(config('app.url').'/service-feedback/'.$this->token->token)
        ->and($payload)->toContain('/service-feedback/')
        // The two public QR flows must never be confusable.
        ->and($payload)->not->toContain('id-checker');
});

it('keeps employee identifiers out of the qr payload', function (): void {
    $payload = $this->token->publicUrl();

    expect($payload)->not->toContain($this->employee->id)
        ->and($payload)->not->toContain($this->employee->employee_number)
        ->and($payload)->not->toContain('Hanna')
        ->and($payload)->not->toContain('Girma')
        ->and($payload)->not->toContain('0912345678')
        ->and($payload)->not->toContain('hanna.girma@example.test')
        ->and($payload)->not->toContain($this->org->id)
        ->and($payload)->not->toContain($this->serviceType->id);
});

it('opens the feedback page when the scanned qr url is followed verbatim', function (): void {
    // Exactly what a phone camera hands to the browser.
    $scanned = $this->token->publicUrl();

    $this->get($scanned)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/ServiceFeedback')
            ->where('available', true)
        );
});

it('does not route a scanned feedback qr to the id checker', function (): void {
    $response = $this->get('/service-feedback/'.$this->token->token)->assertOk();

    $response->assertInertia(fn (Assert $page) => $page->component('Public/ServiceFeedback'));

    // Belt and braces: the ID Checker component must not appear in the payload.
    expect($response->getContent())->not->toContain('Public/IdChecker');
});

it('shows the office context a client needs to confirm the right desk', function (): void {
    $this->get('/service-feedback/'.$this->token->token)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('context.organization', 'Feedback Organization')
            ->where('context.organization_unit', 'Licensing Unit')
            ->where('context.position', 'Licensing Officer')
            // Masked by design: the desk is identified, the person is not.
            ->where('context.display_name', 'Licensing Officer')
        );
});

it('offers the full feedback form to an anonymous visitor', function (): void {
    $this->get('/service-feedback/'.$this->token->token)
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            $props = $page->toArray()['props'];

            // A service type dropdown with real options is what makes the
            // form submittable; an empty catalog would render a dead page.
            expect($props['available'])->toBeTrue()
                ->and($props['serviceTypes'])->not->toBeEmpty()
                ->and($props['submitted'])->toBeFalse();
        });
});

it('links a submission to the correct employee and assignment', function (): void {
    $this->post('/service-feedback/'.$this->token->token, [
        'position_service_id' => $this->positionService->id,
        'rating' => 4,
        'comment' => 'Clear guidance, thank you.',
    ])->assertRedirect();

    $feedback = EmployeeServiceFeedback::query()->firstOrFail();

    expect($feedback->employee_id)->toBe($this->employee->id)
        ->and($feedback->employee_feedback_token_id)->toBe($this->token->id)
        ->and($feedback->organization_id)->toBe($this->org->id)
        ->and($feedback->position_service_id)->toBe($this->positionService->id);
});

it('records submission metadata for abuse investigation', function (): void {
    $this->post('/service-feedback/'.$this->token->token, [
        'position_service_id' => $this->positionService->id,
        'rating' => 3,
    ])->assertRedirect();

    $feedback = EmployeeServiceFeedback::query()->firstOrFail();

    // Stored server-side, but withheld from every serialised payload.
    expect($feedback->ip_address)->not->toBeNull()
        ->and($feedback->getAttribute('user_agent'))->not->toBeNull();
});

/*
 * Token stability.
 *
 * A printed QR is a physical object: once it is on a desk, the only things that
 * may invalidate it are an explicit regenerate or revoke. Ordinary edits to the
 * employee, their assignment or their contact details must leave it untouched,
 * so these lock that guarantee against future regressions.
 */

it('keeps the feedback token stable when employee details are updated', function (): void {
    $before = $this->token->token;

    $this->employee->update([
        'first_name' => 'Renamed',
        'last_name' => 'Person',
        'full_name' => 'Renamed Person',
        'phone' => '0911999888',
        'email' => 'renamed@example.test',
    ]);

    expect($this->employee->fresh()->activeFeedbackToken->token)->toBe($before);
});

it('keeps the feedback token stable when the employee photo changes', function (): void {
    $before = $this->token->token;

    $this->employee->update(['photo_path' => 'photos/updated-portrait.jpg']);

    expect($this->employee->fresh()->activeFeedbackToken->token)->toBe($before);
});

it('keeps the feedback token stable when the assignment changes', function (): void {
    $before = $this->token->token;

    $newUnit = OrganizationUnit::query()->create([
        'organization_id' => $this->org->id,
        'code' => 'SF-U2',
        'name_en' => 'Permits Unit',
        'unit_type' => 'department',
        'status' => 'active',
    ]);

    EmployeeAssignment::query()
        ->where('employee_id', $this->employee->id)
        ->update(['organization_unit_id' => $newUnit->id]);

    expect($this->employee->fresh()->activeFeedbackToken->token)->toBe($before);

    // And the printed QR still resolves after the move.
    $this->get('/service-feedback/'.$before)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('available', true));
});

it('keeps the feedback token stable when employment status changes', function (): void {
    $before = $this->token->token;

    $this->employee->update(['status' => EmployeeStatus::Suspended->value]);

    expect($this->employee->fresh()->activeFeedbackToken?->token)->toBe($before);
});

it('does not mint extra tokens when the qr url is rebuilt repeatedly', function (): void {
    $before = $this->token->token;

    for ($i = 0; $i < 5; $i++) {
        app(EmployeeFeedbackTokenService::class)->ensureActiveTokenFor($this->employee);
    }

    expect(EmployeeFeedbackToken::query()->where('employee_id', $this->employee->id)->count())->toBe(1)
        ->and($this->employee->fresh()->activeFeedbackToken->token)->toBe($before);
});

it('changes the token only on an explicit regenerate', function (): void {
    $before = $this->token->token;

    $fresh = app(EmployeeFeedbackTokenService::class)->regenerate($this->employee);

    expect($fresh->token)->not->toBe($before)
        // The old code is retired, not merely superseded.
        ->and($this->token->fresh()->status)->toBe(FeedbackTokenStatus::Revoked);

    // A QR printed from the old token stops working immediately.
    $this->get('/service-feedback/'.$before)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('available', false));

    // The replacement works.
    $this->get('/service-feedback/'.$fresh->token)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('available', true));
});

it('audits both sides of a regeneration', function (): void {
    app(EmployeeFeedbackTokenService::class)->regenerate($this->employee);

    expect(AuditLog::query()->where('event_type', AuditEventType::ServiceFeedbackQrRegenerated->value)->exists())
        ->toBeTrue();
});
