<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Route::middleware(['web', 'court.audit'])->get('/_audit/read', fn () => response('ok'))
        ->name('test.audit.read');

    Route::middleware(['web', 'court.audit'])->post('/_audit/write', fn () => response()->json(['ok' => true]))
        ->name('test.audit.write');

    Route::middleware(['web', 'court.audit'])->get('/_audit/forbidden', fn () => abort(403))
        ->name('test.audit.forbidden');

    Route::middleware(['web', 'court.audit'])->get('/_audit/notification-count', fn () => response()->json(['count' => 0]))
        ->name('court.admin.notifications.count');

    Route::middleware(['web', 'court.audit', 'court.use-guard:applicant', 'court.auth:applicant'])
        ->get('/_audit/applicant', fn () => response('ok'))
        ->name('test.audit.applicant');
});

test('public read activity is audited', function (): void {
    $this->get('/_audit/read')->assertOk();

    $audit = DB::table('court_system_audits')->where('route', 'test.audit.read')->sole();

    expect($audit->method)->toBe('GET')
        ->and($audit->actor_type)->toBe('guest')
        ->and($audit->outcome)->toBe('success')
        ->and($audit->response_status)->toBe(200);
});

test('sensitive request values are recursively redacted and only one request audit is written', function (): void {
    $this->post('/_audit/write', [
        'title' => 'Permitted value',
        'password' => 'NeverStoreThis',
        'profile' => [
            'otp_code' => '991122',
            'api_token' => 'secret-token',
        ],
    ])->assertOk();

    $audits = DB::table('court_system_audits')->where('route', 'test.audit.write')->get();
    $context = json_decode($audits->sole()->context, true, flags: JSON_THROW_ON_ERROR);

    expect($audits)->toHaveCount(1)
        ->and($context['input']['title'])->toBe('Permitted value')
        ->and($context['input']['password'])->toBe('[redacted]')
        ->and($context['input']['profile']['otp_code'])->toBe('[redacted]')
        ->and($context['input']['profile']['api_token'])->toBe('[redacted]');
});

test('applicant activity is attributed to the applicant provider', function (): void {
    $applicant = Applicant::query()->create([
        'first_name' => 'Audit',
        'middle_name' => 'Coverage',
        'last_name' => 'Applicant',
        'phone' => '0911000001',
        'email' => 'audit-applicant@example.test',
        'password' => Hash::make('AuditPass123!'),
        'national_id_number' => '1234567890123456',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($applicant, 'applicant')
        ->get('/_audit/applicant')
        ->assertOk();

    $audit = DB::table('court_system_audits')->where('route', 'test.audit.applicant')->sole();

    expect($audit->actor_type)->toBe('applicant')
        ->and($audit->user_id)->toBe($applicant->id);
});

test('unnamed routes still receive an auditable action identifier', function (): void {
    Route::middleware(['web', 'court.audit'])->get('/_audit/unnamed', fn () => response('ok'));

    $this->get('/_audit/unnamed')->assertOk();

    $audit = DB::table('court_system_audits')->where('route', '_audit/unnamed')->sole();

    expect($audit->action)->toBe('GET _audit/unnamed');
});

test('failed activity records the failure outcome and response status', function (): void {
    $this->get('/_audit/forbidden')->assertForbidden();

    $audit = DB::table('court_system_audits')->where('route', 'test.audit.forbidden')->sole();

    expect($audit->outcome)->toBe('failure')
        ->and($audit->response_status)->toBe(403);
});

test('notification count polling is not audited', function (): void {
    $this->get('/_audit/notification-count')->assertOk();

    expect(DB::table('court_system_audits')->where('route', 'court.admin.notifications.count')->exists())->toBeFalse();
});

test('model mutations outside controllers are audited with sensitive values redacted', function (): void {
    $user = User::factory()->create();
    $user->forceFill([
        'name' => 'Updated Audit Name',
    ])->save();

    $audit = DB::table('court_system_audits')
        ->where('action', 'model.updated')
        ->where('module', 'court_staff_member')
        ->latest('id')
        ->first();
    $context = json_decode($audit->context, true, flags: JSON_THROW_ON_ERROR);

    expect($context['model_id'])->toBe($user->id)
        ->and($context['new_values']['name'])->toBe('Updated Audit Name');
});

test('successful api login is attributed to the verified actor and redacts credentials', function (): void {
    $applicant = Applicant::query()->create([
        'first_name' => 'API',
        'middle_name' => 'Audit',
        'last_name' => 'Applicant',
        'phone' => '0911000002',
        'email' => 'api-audit-applicant@example.test',
        'password' => Hash::make('ApiAuditPass123!'),
        'national_id_number' => '2234567890123456',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->postJson('/court/api/v1/auth/login', [
        'guard' => 'applicant',
        'email' => $applicant->email,
        'password' => 'ApiAuditPass123!',
        'device_name' => 'audit-test',
    ])->assertOk();

    $audit = DB::table('court_system_audits')->where('route', 'api/v1/auth/login')->sole();
    $context = json_decode($audit->context, true, flags: JSON_THROW_ON_ERROR);

    expect($audit->actor_type)->toBe('applicant')
        ->and($audit->user_id)->toBe($applicant->id)
        ->and($context['input']['password'])->toBe('[redacted]');
});
