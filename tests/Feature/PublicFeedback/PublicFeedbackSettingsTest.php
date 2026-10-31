<?php

declare(strict_types=1);

use App\Actions\SystemSettings\UpdateSystemSettingsGroupAction;
use App\Enums\AuditEventType;
use App\Enums\OrganizationScopeType;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Feedback\Application\Services\OrganizationFeedbackQrService;
use Modules\Feedback\Application\Services\PublicFeedbackSettings;
use Modules\Feedback\Domain\Enums\SubmitterIdentityMode;
use Modules\Feedback\Domain\Models\EmployeeServiceFeedback;
use Tests\Support\PublicFeedbackScenario;

/*
 * Public feedback settings (brief §93): one city-wide policy group — master
 * switch, identity mode, attachments, and which of the three types the
 * public may submit. Per-organization overrides are a NEEDS_DECISION and
 * are not built.
 */

beforeEach(function (): void {
    app()->setLocale('en');
    Storage::fake('local');

    foreach (['service_feedback.settings.manage', 'service_feedback.view', 'service_feedback.complaints.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    Role::findOrCreate('Organizational Admin', 'web')->syncPermissions(['service_feedback.settings.manage', 'service_feedback.view']);

    $this->a = PublicFeedbackScenario::employeeTarget('SET');
    RateLimiter::clear('sf-submit:'.$this->a->token->token.'|127.0.0.1');
    RateLimiter::clear('sf-submit-ip:127.0.0.1');

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('Super Admin');

    $this->orgAdmin = User::factory()->create();
    $this->orgAdmin->assignRole('Organizational Admin');
    UserOrganizationScope::query()->create([
        'user_id' => $this->orgAdmin->id,
        'organization_id' => $this->a->organization->id,
        'scope_type' => OrganizationScopeType::Self,
    ]);

    $this->orgToken = app(OrganizationFeedbackQrService::class)->ensureActive($this->a->organization, $this->superAdmin)->public_token;
    RateLimiter::clear('sf-submit:'.$this->orgToken.'|127.0.0.1');
});

function pfSetting(string $key, mixed $value): void
{
    app(UpdateSystemSettingsGroupAction::class)->execute('service_feedback', [$key => $value], test()->superAdmin);
    app(SystemSettingsService::class)->clearCache();
}

it('ships with every type enabled and evidence allowed, with no identity switch left to set', function (): void {
    $settings = app(SystemSettingsService::class);

    expect($settings->get('service_feedback', 'enabled'))->toBeTrue()
        ->and($settings->get('service_feedback', 'allow_attachments'))->toBeTrue()
        ->and($settings->get('service_feedback', 'feedback_enabled'))->toBeTrue()
        ->and($settings->get('service_feedback', 'suggestion_enabled'))->toBeTrue()
        ->and($settings->get('service_feedback', 'complaint_enabled'))->toBeTrue()
        // Who must give a name is business policy (PublicSubmissionPolicy), not a setting.
        ->and(PublicFeedbackSettings::KEYS)->not->toContain('identity_mode');
});

it('hides a disabled submission type from the public landing and refuses its form and store', function (): void {
    pfSetting('complaint_enabled', false);

    $this->get('/service-feedback/'.$this->a->token->token)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('enabledTypes', ['feedback', 'suggestion']));
    $this->get('/service-feedback/'.$this->a->token->token.'/complaint')->assertNotFound();
    $this->post('/service-feedback/'.$this->a->token->token.'/complaint', pfComplete('complaint', ['message' => 'Still trying.']))->assertNotFound();

    $this->get('/feedback/'.$this->orgToken.'/complaint')->assertNotFound();
    $this->post('/feedback/'.$this->orgToken.'/complaint', pfComplete('complaint', ['message' => 'Still trying.']))->assertNotFound();

    // The other types keep working.
    $this->post('/feedback/'.$this->orgToken.'/suggestion', pfComplete('suggestion', ['message' => 'Open earlier.']))->assertRedirect();
    expect(EmployeeServiceFeedback::query()->count())->toBe(1);
});

it('turns the public pages off with the master switch', function (): void {
    pfSetting('enabled', false);

    $this->get('/service-feedback/'.$this->a->token->token)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('available', false));
    $this->get('/feedback/'.$this->orgToken)
        ->assertInertia(fn (Assert $page) => $page->where('available', false));
    $this->post('/service-feedback/'.$this->a->token->token.'/suggestion', pfComplete('suggestion', ['message' => 'x']))->assertRedirect('/service-feedback/'.$this->a->token->token);

    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
});

it('refuses Tip-off and complaint evidence when it is not allowed', function (): void {
    pfSetting('allow_attachments', false);

    $this->get('/service-feedback/'.$this->a->token->token.'/complaint')
        ->assertInertia(fn (Assert $page) => $page
            ->where('requirements.complaint.evidence_allowed', false)
            ->where('requirements.suggestion.evidence_allowed', false));

    foreach (['complaint', 'suggestion'] as $type) {
        $this->post('/service-feedback/'.$this->a->token->token.'/'.$type, pfComplete($type, [
            'message' => 'With a file.',
            'attachments' => [UploadedFile::fake()->createWithContent('evidence.pdf', "%PDF-1.4\n%fake\n")],
        ]))->assertSessionHasErrors('attachments');
    }

    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
});

it('requires a name and a phone on every Feedback and Complaint: neither is ever anonymous', function (): void {
    $this->post('/service-feedback/'.$this->a->token->token.'/complaint', ['message' => 'No identity.'])
        ->assertSessionHasErrors(['client_name', 'client_contact']);
    $this->post('/service-feedback/'.$this->a->token->token.'/feedback', ['rating' => 4, 'comment' => 'Fine.', 'position_service_id' => $this->a->positionService->id])
        ->assertSessionHasErrors(['client_name', 'client_contact']);
    $this->post('/service-feedback/'.$this->a->token->token.'/complaint', ['message' => 'Bad phone.', 'client_name' => 'Abebe', 'client_contact' => 'not-a-phone'])
        ->assertSessionHasErrors('client_contact');
    expect(EmployeeServiceFeedback::query()->count())->toBe(0);

    // The throttle counts every POST to one code, failed ones included; the accepted case uses the institution's code.
    $this->post('/feedback/'.$this->orgToken.'/complaint', [
        'message' => 'Named.', 'client_name' => 'Abebe', 'client_contact' => '+251 911 000000', 'client_email' => 'abebe@example.com', 'submitter_organization' => 'Bole Traders',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $row = EmployeeServiceFeedback::query()->sole();
    expect($row->submitter_identity_mode)->toBe(SubmitterIdentityMode::Identified)
        ->and($row->client_name)->toBe('Abebe')
        ->and($row->client_contact)->toBe('+251 911 000000')
        ->and($row->client_email)->toBe('abebe@example.com')
        ->and($row->submitter_organization)->toBe('Bole Traders');
});

it('accepts a Tip-off with no tipster details, and validates the details a tipster does offer', function (): void {
    $this->post('/service-feedback/'.$this->a->token->token.'/suggestion', ['message' => 'Bad phone.', 'tip_type' => 'CORRUPTION', 'client_contact' => '??'])
        ->assertSessionHasErrors('client_contact');
    $this->post('/service-feedback/'.$this->a->token->token.'/suggestion', ['message' => 'Bad e-mail.', 'tip_type' => 'CORRUPTION', 'client_email' => 'nope'])
        ->assertSessionHasErrors('client_email');
    $this->post('/feedback/'.$this->orgToken.'/suggestion', ['message' => 'No details at all.', 'tip_type' => 'CORRUPTION'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $row = EmployeeServiceFeedback::query()->sole();
    expect($row->submitter_identity_mode)->toBe(SubmitterIdentityMode::Optional)
        ->and($row->client_name)->toBeNull()
        ->and($row->client_contact)->toBeNull()
        ->and($row->client_email)->toBeNull();
});

it('lets a city-level account with the settings permission update the policy and audits it', function (): void {
    $this->actingAs($this->superAdmin)
        ->get(route('service-feedback.settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ServiceFeedback/Settings')
            ->where('feedbackSettings.complaint_enabled', true)
            ->missing('feedbackSettings.identity_mode')
            // The fixed identity matrix is shown read-only.
            ->where('identityPolicy.0.type', 'feedback')
            ->where('identityPolicy.0.anonymous_allowed', false)
            ->where('identityPolicy.1.type', 'suggestion')
            ->where('identityPolicy.1.anonymous_allowed', true)
            ->where('identityPolicy.2.type', 'complaint')
            ->where('identityPolicy.2.anonymous_allowed', false));

    $this->actingAs($this->superAdmin)
        ->put(route('service-feedback.settings.update'), [
            'enabled' => true, 'allow_attachments' => false,
            'feedback_enabled' => true, 'suggestion_enabled' => false, 'complaint_enabled' => true,
        ])->assertRedirect();

    $settings = app(SystemSettingsService::class);
    expect($settings->get('service_feedback', 'allow_attachments'))->toBeFalse()
        ->and($settings->get('service_feedback', 'suggestion_enabled'))->toBeFalse()
        ->and(AuditLog::query()->where('event_type', AuditEventType::ServiceFeedbackSettingsUpdated->value)->exists())->toBeTrue();

    // An identity mode can no longer be set: a posted value changes nothing, and anonymous complaints stay refused.
    $this->actingAs($this->superAdmin)
        ->put(route('service-feedback.settings.update'), ['identity_mode' => 'anonymous'])
        ->assertRedirect();
    $this->post('/feedback/'.$this->orgToken.'/complaint', ['message' => 'Still anonymous?'])
        ->assertSessionHasErrors(['client_name', 'client_contact']);

    // The policy is city-wide: a scoped administrator holding the permission does not edit it.
    $this->actingAs($this->orgAdmin)->get(route('service-feedback.settings.index'))->assertForbidden();
    $this->actingAs($this->orgAdmin)->put(route('service-feedback.settings.update'), ['allow_attachments' => true])->assertForbidden();
    expect($settings->get('service_feedback', 'allow_attachments'))->toBeFalse();
});
