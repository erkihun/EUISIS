<?php

declare(strict_types=1);

use App\Actions\SystemSettings\UpdateSystemSettingsGroupAction;
use App\Enums\AuditEventType;
use App\Enums\OrganizationScopeType;
use App\Enums\SubmitterIdentityMode;
use App\Models\AuditLog;
use App\Models\EmployeeServiceFeedback;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\ServiceFeedback\OrganizationFeedbackQrService;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
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

it('ships with every type enabled, optional identity and attachments allowed', function (): void {
    $settings = app(SystemSettingsService::class);

    expect($settings->get('service_feedback', 'enabled'))->toBeTrue()
        ->and($settings->get('service_feedback', 'identity_mode'))->toBe('optional')
        ->and($settings->get('service_feedback', 'allow_attachments'))->toBeTrue()
        ->and($settings->get('service_feedback', 'feedback_enabled'))->toBeTrue()
        ->and($settings->get('service_feedback', 'suggestion_enabled'))->toBeTrue()
        ->and($settings->get('service_feedback', 'complaint_enabled'))->toBeTrue();
});

it('hides a disabled submission type from the public landing and refuses its form and store', function (): void {
    pfSetting('complaint_enabled', false);

    $this->get('/service-feedback/'.$this->a->token->token)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('enabledTypes', ['feedback', 'suggestion']));
    $this->get('/service-feedback/'.$this->a->token->token.'/complaint')->assertNotFound();
    $this->post('/service-feedback/'.$this->a->token->token.'/complaint', ['message' => 'Still trying.'])->assertNotFound();

    $this->get('/feedback/'.$this->orgToken.'/complaint')->assertNotFound();
    $this->post('/feedback/'.$this->orgToken.'/complaint', ['message' => 'Still trying.'])->assertNotFound();

    // The other types keep working.
    $this->post('/feedback/'.$this->orgToken.'/suggestion', ['message' => 'Open earlier.'])->assertRedirect();
    expect(EmployeeServiceFeedback::query()->count())->toBe(1);
});

it('turns the public pages off with the master switch', function (): void {
    pfSetting('enabled', false);

    $this->get('/service-feedback/'.$this->a->token->token)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('available', false));
    $this->get('/feedback/'.$this->orgToken)
        ->assertInertia(fn (Assert $page) => $page->where('available', false));
    $this->post('/service-feedback/'.$this->a->token->token.'/suggestion', ['message' => 'x'])->assertRedirect('/service-feedback/'.$this->a->token->token);

    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
});

it('refuses complaint attachments when they are not allowed', function (): void {
    pfSetting('allow_attachments', false);

    $this->get('/service-feedback/'.$this->a->token->token.'/complaint')
        ->assertInertia(fn (Assert $page) => $page->where('allowAttachments', false));

    $this->post('/service-feedback/'.$this->a->token->token.'/complaint', [
        'message' => 'With a file.',
        'attachments' => [UploadedFile::fake()->create('evidence.pdf', 10, 'application/pdf')],
    ])->assertSessionHasErrors('attachments');

    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
});

it('collects no identity in anonymous mode and records the mode on the row', function (): void {
    pfSetting('identity_mode', 'anonymous');

    $this->get('/service-feedback/'.$this->a->token->token.'/suggestion')
        ->assertInertia(fn (Assert $page) => $page->where('identityMode', 'anonymous'));

    $this->post('/service-feedback/'.$this->a->token->token.'/suggestion', [
        'message' => 'Anonymous suggestion.', 'client_name' => 'Abebe', 'client_contact' => '0911000000',
    ])->assertRedirect();

    $row = EmployeeServiceFeedback::query()->sole();
    expect($row->client_name)->toBeNull()
        ->and($row->client_contact)->toBeNull()
        ->and($row->submitter_identity_mode)->toBe(SubmitterIdentityMode::Anonymous);
});

it('requires a name and a valid phone or email in identified mode', function (): void {
    pfSetting('identity_mode', 'identified');

    $this->post('/service-feedback/'.$this->a->token->token.'/complaint', ['message' => 'No identity.'])
        ->assertSessionHasErrors(['client_name', 'client_contact']);
    $this->post('/service-feedback/'.$this->a->token->token.'/complaint', ['message' => 'Bad contact.', 'client_name' => 'Abebe', 'client_contact' => 'not-a-contact'])
        ->assertSessionHasErrors('client_contact');
    $this->post('/service-feedback/'.$this->a->token->token.'/complaint', ['message' => 'Phone.', 'client_name' => 'Abebe', 'client_contact' => '+251 911 000000'])
        ->assertRedirect();
    // The throttle counts every POST to one code, failed ones included; the e-mail case uses the institution's code.
    $this->post('/feedback/'.$this->orgToken.'/complaint', ['message' => 'Email.', 'client_name' => 'Abebe', 'client_contact' => 'abebe@example.com'])
        ->assertRedirect();

    $rows = EmployeeServiceFeedback::query()->orderBy('submitted_at')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->submitter_identity_mode)->toBe(SubmitterIdentityMode::Identified)
        ->and($rows[0]->client_contact)->toBe('+251 911 000000');
});

it('validates a contact when one is offered in optional mode', function (): void {
    $this->post('/service-feedback/'.$this->a->token->token.'/suggestion', ['message' => 'Bad contact.', 'client_contact' => '??'])
        ->assertSessionHasErrors('client_contact');
    $this->post('/service-feedback/'.$this->a->token->token.'/suggestion', ['message' => 'No contact at all.'])
        ->assertRedirect();

    expect(EmployeeServiceFeedback::query()->sole()->submitter_identity_mode)->toBe(SubmitterIdentityMode::Optional);
});

it('lets a city-level account with the settings permission update the policy and audits it', function (): void {
    $this->actingAs($this->superAdmin)
        ->get(route('service-feedback.settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ServiceFeedback/Settings')
            ->where('settings.identity_mode', 'optional')
            ->where('settings.complaint_enabled', true));

    $this->actingAs($this->superAdmin)
        ->put(route('service-feedback.settings.update'), [
            'enabled' => true, 'identity_mode' => 'identified', 'allow_attachments' => false,
            'feedback_enabled' => true, 'suggestion_enabled' => false, 'complaint_enabled' => true,
        ])->assertRedirect();

    $settings = app(SystemSettingsService::class);
    expect($settings->get('service_feedback', 'identity_mode'))->toBe('identified')
        ->and($settings->get('service_feedback', 'allow_attachments'))->toBeFalse()
        ->and($settings->get('service_feedback', 'suggestion_enabled'))->toBeFalse()
        ->and(AuditLog::query()->where('event_type', AuditEventType::ServiceFeedbackSettingsUpdated->value)->exists())->toBeTrue();

    $this->actingAs($this->superAdmin)
        ->put(route('service-feedback.settings.update'), ['identity_mode' => 'secret'])
        ->assertSessionHasErrors('identity_mode');

    // The policy is city-wide: a scoped administrator holding the permission does not edit it.
    $this->actingAs($this->orgAdmin)->get(route('service-feedback.settings.index'))->assertForbidden();
    $this->actingAs($this->orgAdmin)->put(route('service-feedback.settings.update'), ['identity_mode' => 'anonymous'])->assertForbidden();
    expect($settings->get('service_feedback', 'identity_mode'))->toBe('identified');
});
