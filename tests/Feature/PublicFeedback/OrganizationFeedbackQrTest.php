<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Enums\OrganizationFeedbackQrStatus;
use App\Enums\OrganizationScopeType;
use App\Enums\PublicFeedbackSourceType;
use App\Enums\PublicFeedbackTargetType;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationFeedbackQrCode;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\ServiceFeedback\OrganizationFeedbackQrService;
use App\Services\ServiceFeedback\PublicFeedbackTargetResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PublicFeedbackScenario;

/*
 * An organization's public feedback QR: one stable opaque token per
 * organization, minted deliberately, never rotated by ordinary changes,
 * rotated or revoked only by an authorized person with a reason, and never
 * reused (brief §7–13, 69–72, 75).
 */

beforeEach(function (): void {
    app()->setLocale('en');

    foreach ([
        'service_feedback.organization_qr.view',
        'service_feedback.organization_qr.create',
        'service_feedback.organization_qr.export',
        'service_feedback.organization_qr.disable',
        'service_feedback.organization_qr.rotate',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    Role::findOrCreate('Organizational Admin', 'web')->syncPermissions([
        'service_feedback.organization_qr.view',
        'service_feedback.organization_qr.create',
        'service_feedback.organization_qr.export',
        'service_feedback.organization_qr.disable',
    ]);
    Role::findOrCreate('Employee', 'web');

    $this->organization = PublicFeedbackScenario::employeeTarget('OQR')->organization;
    $this->other = PublicFeedbackScenario::employeeTarget('OQR2')->organization;

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('Super Admin');

    $this->orgAdmin = User::factory()->create();
    $this->orgAdmin->assignRole('Organizational Admin');
    UserOrganizationScope::query()->create([
        'user_id' => $this->orgAdmin->id,
        'organization_id' => $this->organization->id,
        'scope_type' => OrganizationScopeType::Self,
    ]);

    $this->service = app(OrganizationFeedbackQrService::class);
    $this->resolver = app(PublicFeedbackTargetResolver::class);
});

function oqrToken(Organization $organization): string
{
    return (string) OrganizationFeedbackQrCode::query()
        ->where('organization_id', $organization->id)
        ->where('status', OrganizationFeedbackQrStatus::Active->value)
        ->value('public_token');
}

it('lets an authorized administrator enable the organization QR and mints one opaque active code', function (): void {
    $this->actingAs($this->superAdmin)
        ->post(route('organizations.feedback-qr.generate', $this->organization))
        ->assertRedirect(route('organizations.feedback-qr.show', $this->organization));

    $codes = OrganizationFeedbackQrCode::query()->where('organization_id', $this->organization->id)->get();

    expect($codes)->toHaveCount(1)
        ->and($codes->first()->status)->toBe(OrganizationFeedbackQrStatus::Active)
        ->and($codes->first()->public_token)->toMatch('/^[0-9a-f]{64}$/')
        ->and($codes->first()->enabled_at)->not->toBeNull()
        ->and($codes->first()->created_by)->toBe($this->superAdmin->id)
        ->and(AuditLog::query()->where('event_type', AuditEventType::OrganizationFeedbackQrGenerated->value)->count())->toBe(1);
});

it('shows no code until one is deliberately enabled and never mints one by opening the page', function (): void {
    $this->actingAs($this->superAdmin)
        ->get(route('organizations.feedback-qr.show', $this->organization))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ServiceFeedback/OrganizationQr')
            ->where('qr', null)
            ->where('organization.id', $this->organization->id));

    expect(OrganizationFeedbackQrCode::query()->count())->toBe(0);

    $this->service->ensureActive($this->organization, $this->superAdmin);
    $first = oqrToken($this->organization);

    $this->actingAs($this->superAdmin)->get(route('organizations.feedback-qr.show', $this->organization))->assertOk();
    $this->actingAs($this->superAdmin)->get(route('organizations.feedback-qr.show', $this->organization))->assertOk();

    expect(OrganizationFeedbackQrCode::query()->count())->toBe(1)
        ->and(oqrToken($this->organization))->toBe($first);
});

it('keeps the same code when the organization is renamed or asked for its code again', function (): void {
    $first = $this->service->ensureActive($this->organization, $this->superAdmin);

    $this->organization->update(['name_en' => 'Renamed Bureau', 'name_am' => 'የተቀየረ ቢሮ']);

    expect($this->service->ensureActive($this->organization->fresh(), $this->superAdmin)->id)->toBe($first->id)
        ->and(OrganizationFeedbackQrCode::query()->count())->toBe(1);
});

it('refuses a second active code for one organization at the database', function (): void {
    $this->service->ensureActive($this->organization, $this->superAdmin);

    expect(fn () => DB::table('organization_feedback_qr_codes')->insert([
        'id' => (string) Str::uuid(),
        'organization_id' => $this->organization->id,
        'public_token' => str_repeat('b', 64),
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('stops resolving while disabled and resolves again once enabled', function (): void {
    $qr = $this->service->ensureActive($this->organization, $this->superAdmin);
    $token = oqrToken($this->organization);

    expect($this->resolver->resolveOrganizationToken($token))->not->toBeNull();

    $this->actingAs($this->orgAdmin)->post(route('organizations.feedback-qr.disable', $this->organization))->assertRedirect();
    expect($qr->fresh()->status)->toBe(OrganizationFeedbackQrStatus::Disabled)
        ->and($this->resolver->resolveOrganizationToken($token))->toBeNull();

    $this->actingAs($this->orgAdmin)->post(route('organizations.feedback-qr.enable', $this->organization))->assertRedirect();
    expect($qr->fresh()->status)->toBe(OrganizationFeedbackQrStatus::Active)
        ->and($this->resolver->resolveOrganizationToken($token))->not->toBeNull()
        ->and(OrganizationFeedbackQrCode::query()->count())->toBe(1);
});

it('rotates only with the stronger permission, revoking the old code and keeping its history', function (): void {
    $old = $this->service->ensureActive($this->organization, $this->superAdmin);
    $oldToken = oqrToken($this->organization);

    $this->actingAs($this->orgAdmin)
        ->post(route('organizations.feedback-qr.rotate', $this->organization), ['reason' => 'Printed sheet photographed and shared'])
        ->assertForbidden();

    $this->actingAs($this->superAdmin)
        ->post(route('organizations.feedback-qr.rotate', $this->organization), ['reason' => 'Printed sheet photographed and shared'])
        ->assertRedirect();

    $old->refresh();
    $new = OrganizationFeedbackQrCode::query()->where('organization_id', $this->organization->id)->where('status', 'active')->sole();

    expect($old->status)->toBe(OrganizationFeedbackQrStatus::Revoked)
        ->and($old->revoked_by)->toBe($this->superAdmin->id)
        ->and($old->revocation_reason)->toBe('Printed sheet photographed and shared')
        ->and($new->rotated_from_id)->toBe($old->id)
        ->and($new->public_token)->not->toBe($oldToken)
        ->and($this->resolver->resolveOrganizationToken($oldToken))->toBeNull()
        ->and($this->resolver->resolveOrganizationToken($new->public_token))->not->toBeNull()
        ->and(AuditLog::query()->where('event_type', AuditEventType::OrganizationFeedbackQrRotated->value)->count())->toBe(1);
});

it('requires a reason to rotate or revoke', function (): void {
    $this->service->ensureActive($this->organization, $this->superAdmin);

    $this->actingAs($this->superAdmin)
        ->from(route('organizations.feedback-qr.show', $this->organization))
        ->post(route('organizations.feedback-qr.rotate', $this->organization), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect(OrganizationFeedbackQrCode::query()->count())->toBe(1);
});

it('never reuses a revoked token and resolves nothing for it', function (): void {
    $qr = $this->service->ensureActive($this->organization, $this->superAdmin);
    $token = oqrToken($this->organization);

    $this->actingAs($this->superAdmin)
        ->post(route('organizations.feedback-qr.revoke', $this->organization), ['reason' => 'Organization reorganised'])
        ->assertRedirect();

    expect($qr->fresh()->status)->toBe(OrganizationFeedbackQrStatus::Revoked)
        ->and($this->resolver->resolveOrganizationToken($token))->toBeNull();

    // The token column is unique across every row, revoked ones included.
    expect(fn () => DB::table('organization_feedback_qr_codes')->insert([
        'id' => (string) Str::uuid(),
        'organization_id' => $this->other->id,
        'public_token' => $token,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('resolves nothing for an inactive organization even with an active code', function (): void {
    $this->service->ensureActive($this->organization, $this->superAdmin);
    $token = oqrToken($this->organization);

    $this->organization->update(['status' => 'inactive']);

    expect($this->resolver->resolveOrganizationToken($token))->toBeNull()
        ->and($this->resolver->resolveOrganizationToken(str_repeat('0', 64)))->toBeNull()
        ->and($this->resolver->resolveOrganizationToken('not-a-token'))->toBeNull();
});

it('resolves an active code to an organization target with safe public display only', function (): void {
    $this->service->ensureActive($this->organization, $this->superAdmin);
    $context = $this->resolver->resolveOrganizationToken(oqrToken($this->organization));

    expect($context->targetType)->toBe(PublicFeedbackTargetType::Organization)
        ->and($context->sourceType)->toBe(PublicFeedbackSourceType::OrganizationFeedbackQr)
        ->and($context->organizationTargetId)->toBe($this->organization->id)
        ->and($context->employeeId)->toBeNull()
        ->and($context->contextOrganizationId)->toBe($this->organization->id)
        ->and($context->targetNameSnapshot)->toBe('Feedback Organization')
        ->and($context->targetCodeSnapshot)->toBe('OQR-ORG');

    $display = $context->publicDisplay('am');
    expect($display['target_type'])->toBe('organization')
        ->and($display['display_name'])->toBe('የአስተያየት ተቋም')
        ->and($display)->toHaveKeys(['logo_url', 'short_name'])
        ->and($display)->not->toHaveKey('id');
});

it('refuses QR management outside the user scope and without permission', function (): void {
    $this->actingAs($this->orgAdmin)->get(route('organizations.feedback-qr.show', $this->other))->assertForbidden();
    $this->actingAs($this->orgAdmin)->post(route('organizations.feedback-qr.generate', $this->other))->assertForbidden();

    $employee = User::factory()->create();
    $employee->assignRole('Employee');
    $this->actingAs($employee)->get(route('organizations.feedback-qr.show', $this->organization))->assertForbidden();
});

it('serves PNG and PDF exports of the code and audits each export', function (): void {
    $this->service->ensureActive($this->organization, $this->superAdmin);

    $this->actingAs($this->orgAdmin)
        ->get(route('organizations.feedback-qr.png', $this->organization))
        ->assertOk()
        ->assertHeader('content-type', 'image/png');

    $this->actingAs($this->orgAdmin)
        ->get(route('organizations.feedback-qr.pdf', $this->organization))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect(AuditLog::query()->where('event_type', AuditEventType::OrganizationFeedbackQrExported->value)->count())->toBe(2);
});

it('keeps the raw token out of the model JSON but gives the page the public URL', function (): void {
    $qr = $this->service->ensureActive($this->organization, $this->superAdmin);

    expect($qr->toArray())->not->toHaveKey('public_token')
        ->and($qr->publicUrl())->toContain('/feedback/'.oqrToken($this->organization));
});
