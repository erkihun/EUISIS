<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeServiceFeedback;
use App\Models\Organization;
use App\Models\User;
use App\Services\ServiceFeedback\OrganizationFeedbackQrService;
use App\Services\ServiceFeedback\PublicFeedbackDataQuality;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\PublicFeedbackScenario;

/*
 * The Call action on a QR landing page dials the institution's validated
 * public number: the scanned institution's, or the institution the scanned
 * employee serves now. It never carries an employee's own number, never a
 * value that is not a plain phone number, and it is absent when there is none.
 */

/** @return array<string, mixed> */
function qrcProps(TestResponse $response): array
{
    $props = null;
    $response->assertInertia(function (Assert $page) use (&$props): void {
        $props = $page->toArray()['props'];
    });

    return $props;
}

function qrcOrganizationToken(Organization $organization): string
{
    return app(OrganizationFeedbackQrService::class)->ensureActive($organization)->public_token;
}

beforeEach(function (): void {
    $this->withHeader('X-Locale', 'en');

    $this->a = PublicFeedbackScenario::employeeTarget('QCA');
    $this->b = PublicFeedbackScenario::employeeTarget('QCB');

    // Personal numbers that must never surface on a public page.
    $this->a->employee->forceFill(['phone' => '0911000111', 'emergency_contact_phone' => '0922000222'])->save();
    $this->b->employee->forceFill(['phone' => '0933000333'])->save();

    $this->a->organization->update(['public_contact_phone' => '011 123 4567']);
    $this->b->organization->update(['public_contact_phone' => '+251 58 220 1234']);

    $this->pageA = '/service-feedback/'.$this->a->token->token;
    $this->pageB = '/service-feedback/'.$this->b->token->token;
});

it('gives an employee QR the public number of the institution the employee serves', function (): void {
    $props = qrcProps($this->get($this->pageA)->assertOk());

    expect($props['call'])->toBe(['href' => 'tel:+251111234567', 'display' => '011 123 4567'])
        // The identity confirmation: name, job designation and photo link only — no institution data in it.
        ->and(array_keys($props['target']))->toBe(['target_type', 'display_name', 'position_title', 'photo_url'])
        ->and($props['links']['feedback'])->toEndWith('/service-feedback/'.$this->a->token->token.'/feedback');
});

it('gives each employee their own institution\'s number, never another\'s', function (): void {
    expect(qrcProps($this->get($this->pageA))['call']['href'])->toBe('tel:+251111234567')
        ->and(qrcProps($this->get($this->pageB))['call']['href'])->toBe('tel:+251582201234')
        ->and(qrcProps($this->get($this->pageA))['call']['href'])->toBe('tel:+251111234567');
});

it('offers no Call when the employee\'s institution has no public number, and never falls back to the employee\'s own', function (): void {
    $this->a->organization->update(['public_contact_phone' => null]);

    $response = $this->get($this->pageA)->assertOk();
    $props = qrcProps($response);

    expect($props['call'])->toBeNull()
        ->and($props['available'])->toBeTrue()
        ->and($props['enabledTypes'])->toBe(['feedback', 'suggestion', 'complaint']);

    $body = (string) $response->getContent();
    foreach (['0911000111', '+251911000111', '0922000222', 'tel:'] as $private) {
        expect($body)->not->toContain($private);
    }
});

it('never puts an employee\'s personal or emergency number on the page, even with a Call action', function (): void {
    $body = (string) $this->get($this->pageA)->assertOk()->getContent();

    foreach (['0911000111', '+251911000111', '911000111', '0922000222', '0933000333'] as $private) {
        expect($body)->not->toContain($private);
    }
    expect($body)->toContain('tel:+251111234567');
});

it('gives an organization QR exactly that institution\'s number, or none', function (): void {
    $tokenA = qrcOrganizationToken($this->a->organization);
    $tokenB = qrcOrganizationToken($this->b->organization);

    expect(qrcProps($this->get('/feedback/'.$tokenA)->assertOk())['call'])->toBe(['href' => 'tel:+251111234567', 'display' => '011 123 4567'])
        ->and(qrcProps($this->get('/feedback/'.$tokenB)->assertOk())['call']['href'])->toBe('tel:+251582201234');

    $this->a->organization->update(['public_contact_phone' => null]);
    $props = qrcProps($this->get('/feedback/'.$tokenA)->assertOk());

    expect($props['call'])->toBeNull()
        ->and($props['target']['display_name'])->toBe('Feedback Organization')
        ->and($props['target'])->not->toHaveKey('public_contact_phone');
});

it('offers no Call on the unavailable page', function (): void {
    expect(qrcProps($this->get('/service-feedback/'.str_repeat('0', 64))->assertOk())['call'])->toBeNull();
});

it('never turns a stored value that is not a plain phone number into a link', function (string $stored): void {
    // Written past the model, as a direct database edit or an old import would.
    DB::table('organizations')->where('id', $this->a->organization->id)->update(['public_contact_phone' => $stored]);

    $response = $this->get($this->pageA)->assertOk();

    expect(qrcProps($response)['call'])->toBeNull()
        ->and((string) $response->getContent())->not->toContain('javascript:')->not->toContain('data:text');
})->with(['javascript:alert(1)', 'tel:+251111234567', 'data:text/html,x', '0111234567;ext=9', '911223344']);

it('follows a transfer: the same QR then calls the new institution, and earlier submissions keep their context', function (): void {
    $this->post($this->pageA.'/suggestion', pfComplete('suggestion', ['message' => 'Before the transfer.']))->assertRedirect();
    $before = EmployeeServiceFeedback::query()->sole();
    $token = $this->a->token->token;

    $this->a->assignment->forceFill(['is_current' => false, 'effective_to' => now()->toDateString()])->save();
    $moved = EmployeeAssignment::query()->create([
        'employee_id' => $this->a->employee->id, 'organization_id' => $this->b->organization->id,
        'organization_unit_id' => $this->b->unit->id, 'position_id' => $this->b->position->id,
        'is_current' => true, 'assignment_status' => AssignmentStatus::Active->value, 'effective_from' => now()->addDay()->toDateString(),
    ]);
    $this->a->employee->forceFill(['current_assignment_id' => $moved->id])->save();

    expect(qrcProps($this->get($this->pageA))['call']['href'])->toBe('tel:+251582201234')
        ->and($this->a->token->refresh()->token)->toBe($token)
        ->and($before->refresh()->organization_id)->toBe($this->a->organization->id);
});

it('does not rotate any QR when a public number is set or changed', function (): void {
    $employeeToken = $this->a->token->token;
    $organizationToken = qrcOrganizationToken($this->a->organization);

    $this->a->organization->update(['public_contact_phone' => '0911 555 666']);
    $this->a->organization->update(['public_contact_phone' => null]);

    expect($this->a->token->refresh()->token)->toBe($employeeToken)
        ->and(qrcOrganizationToken($this->a->organization))->toBe($organizationToken);
});

it('creates nothing when a page with a Call action is viewed', function (): void {
    $this->get($this->pageA)->assertOk();
    $this->get('/feedback/'.qrcOrganizationToken($this->a->organization))->assertOk();

    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
});

// ── Managing the number ──────────────────────────────────────────────────────

describe('organization public contact phone', function (): void {
    beforeEach(function (): void {
        foreach (['organizations.view', 'organizations.update'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::findOrCreate('Super Admin', 'web');
        Role::findOrCreate('Viewer', 'web')->givePermissionTo('organizations.view');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');

        $this->organization = $this->a->organization->refresh();
        $this->payload = [
            'organization_type_id' => $this->organization->organization_type_id,
            'code' => $this->organization->code,
            'name_en' => $this->organization->name_en,
            'status' => 'active',
        ];
    });

    it('stores a valid number in dialable form and records it in the audit trail', function (): void {
        $this->actingAs($this->admin)
            ->patch(route('organizations.update', $this->organization), $this->payload + ['public_contact_phone' => '0911 22 33 44'])
            ->assertSessionHasNoErrors();

        expect($this->organization->refresh()->public_contact_phone)->toBe('+251911223344');
        $audit = DB::table('audit_logs')->where('auditable_id', $this->organization->id)->where('event_type', 'organization_updated')->latest('created_at')->first();
        expect((string) $audit?->old_values)->toContain('public_contact_phone')
            ->and((string) $audit?->new_values)->toContain('+251911223344');
    });

    it('refuses a value that is not a plain phone number', function (string $value): void {
        $this->actingAs($this->admin)
            ->patch(route('organizations.update', $this->organization), $this->payload + ['public_contact_phone' => $value])
            ->assertSessionHasErrors('public_contact_phone');

        expect($this->organization->refresh()->public_contact_phone)->toBe('+251111234567');
    })->with(['javascript:alert(1)', '0111234567;ext=2', 'call me', '911223344']);

    it('clears the number when the field is emptied, and keeps it when a form leaves the field out', function (): void {
        $this->actingAs($this->admin)->patch(route('organizations.update', $this->organization), $this->payload)->assertSessionHasNoErrors();
        expect($this->organization->refresh()->public_contact_phone)->toBe('+251111234567');

        $this->actingAs($this->admin)->patch(route('organizations.update', $this->organization), $this->payload + ['public_contact_phone' => ''])->assertSessionHasNoErrors();
        expect($this->organization->refresh()->public_contact_phone)->toBeNull();
    });

    it('lets only users who may edit the institution change it', function (): void {
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');

        $this->actingAs($viewer)
            ->patch(route('organizations.update', $this->organization), $this->payload + ['public_contact_phone' => '0911 999 888'])
            ->assertForbidden();

        expect($this->organization->refresh()->public_contact_phone)->toBe('+251111234567');
    });
});

it('reports institutions without a number and numbers that cannot be dialled', function (): void {
    $this->b->organization->update(['public_contact_phone' => null]);
    DB::table('organizations')->where('id', $this->a->organization->id)->update(['public_contact_phone' => 'call reception']);

    $findings = collect(app(PublicFeedbackDataQuality::class)->run())->keyBy('key');

    expect($findings['active_organizations_without_public_phone']['sample'])->toContain($this->b->organization->id)
        ->and($findings['active_organizations_without_public_phone']['severity'])->toBe('INFO')
        ->and($findings['organizations_with_invalid_public_phone']['sample'])->toContain($this->a->organization->id)
        ->and($findings['organizations_with_invalid_public_phone']['severity'])->toBe('NEEDS_DATA_REVIEW');
});
