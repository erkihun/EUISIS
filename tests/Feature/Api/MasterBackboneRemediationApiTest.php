<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationScopeType;
use App\Models\ApiEndpointDefinition;
use App\Models\ApiIdempotencyKey;
use App\Models\ApiRequestLog;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\ExternalApplication;
use App\Models\HierarchyVersion;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationClosurePath;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\ApiEndpointCatalogService;
use App\Services\IdCards\CardQrPayloadService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Entitlements\Contracts\Data\EntitlementStatus;
use Modules\Entitlements\Domain\Models\Entitlement;
use Modules\Entitlements\Domain\Models\ServiceProvider;
use Modules\Entitlements\Domain\Models\ServiceType;
use Spatie\Permission\Models\Permission;

/**
 * Master backbone remediation — partner API evidence.
 *
 *   F1  provider:access is not an integration credential; scope removal bites at once
 *   F2  organization bounds for applications (city / scoped self+subtree / unreviewed)
 *       and for people (permission + OrganizationScopeService)
 *   F3  stable UUIDs in the verify response feed the eligibility call end-to-end
 *   F4  eligibility for a machine principal answers, never a 500
 *   F5  validity needs status, revocation, expiry, QR status AND current card
 *   F6  durable idempotency: replay, conflicting payload, in-progress claim
 *   F16 machine principals on provider routes are bound to one provider
 *   F17 photos are short-lived signed links re-authorised at serve time
 *   F18 settlement period actually bounds the report
 *   F19 the entitlement charged is the one in force
 */
beforeEach(function (): void {
    app(ApiEndpointCatalogService::class)->sync();

    $type = OrganizationType::query()->create(['code' => 'MBR-TYPE', 'name_en' => 'Remediation Type']);
    $make = fn (string $code): Organization => Organization::query()->create([
        'organization_type_id' => $type->id, 'code' => $code, 'name_en' => 'Org '.$code, 'status' => 'active',
    ]);

    // Bureau → woreda (published hierarchy) and an unrelated bureau.
    $this->bureau = $make('MBR-BUREAU');
    $this->woreda = $make('MBR-WOREDA');
    $this->other = $make('MBR-OTHER');

    $version = HierarchyVersion::query()->create([
        'version_name' => 'MBR v1', 'status' => HierarchyVersionStatus::Published->value,
        'approval_date' => now()->subMonth(), 'effective_from' => now()->subMonth()->toDateString(),
    ]);
    foreach ([[$this->bureau, $this->bureau, 0], [$this->woreda, $this->woreda, 0], [$this->bureau, $this->woreda, 1], [$this->other, $this->other, 0]] as [$ancestor, $descendant, $depth]) {
        OrganizationClosurePath::query()->create([
            'hierarchy_version_id' => $version->id, 'ancestor_organization_id' => $ancestor->id,
            'descendant_organization_id' => $descendant->id, 'depth' => $depth,
        ]);
    }

    $this->woredaEmployee = mbrEmployee($this->woreda, 'MBR-EMP-W');
    $this->otherEmployee = mbrEmployee($this->other, 'MBR-EMP-O');
    $this->woredaCard = mbrCard($this->woredaEmployee, 'MBR-CARD-W');
    $this->otherCard = mbrCard($this->otherEmployee, 'MBR-CARD-O');

    $this->serviceType = ServiceType::query()->firstOrCreate(['code' => 'cafeteria'], ['name_en' => 'Cafeteria']);
    $this->provider = ServiceProvider::query()->create(['service_type_id' => $this->serviceType->id, 'name' => 'Bound Cafe', 'code' => 'MBR-CAFE', 'status' => 'active']);
    $this->otherProvider = ServiceProvider::query()->create(['service_type_id' => $this->serviceType->id, 'name' => 'Other Cafe', 'code' => 'MBR-OTHER-CAFE', 'status' => 'active']);
});

function mbrEmployee(Organization $organization, string $number): Employee
{
    $unit = OrganizationUnit::query()->create(['organization_id' => $organization->id, 'code' => $number.'-U', 'name_en' => 'Unit '.$number, 'unit_type' => 'department', 'status' => 'active']);
    $position = Position::query()->create(['organization_id' => $organization->id, 'organization_unit_id' => $unit->id, 'job_position_code' => $number.'-P', 'title_en' => 'Officer', 'is_active' => true]);
    $employee = Employee::query()->create(['employee_number' => $number, 'first_name' => 'Abebe', 'last_name' => 'Kebede', 'full_name' => 'Abebe Kebede', 'status' => EmployeeStatus::Active->value]);
    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id, 'organization_id' => $organization->id, 'organization_unit_id' => $unit->id, 'position_id' => $position->id,
        'is_current' => true, 'assignment_status' => AssignmentStatus::Active->value, 'effective_from' => now()->subYear()->toDateString(),
    ]);
    $employee->forceFill(['current_assignment_id' => $assignment->id])->save();

    return $employee->fresh();
}

function mbrCard(Employee $employee, string $number, array $overrides = []): IdCard
{
    $card = IdCard::query()->create([
        'employee_id' => $employee->id, 'card_number' => $number, 'status' => CardStatus::Active->value, 'is_current' => true,
        'issued_at' => now()->subMonth(), 'expires_at' => now()->addYear(), ...$overrides,
    ]);
    app(CardQrPayloadService::class)->ensurePublicReference($card);

    return $card->refresh();
}

/**
 * A registered application with a token, assigned every endpoint its scopes reach.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: ExternalApplication, 1: string}
 */
function mbrApplication(array $scopes, array $attributes = []): array
{
    $application = ExternalApplication::query()->create([
        'name' => 'Remediation Partner', 'code' => 'MBR-'.Str::random(6), 'status' => 'active',
        'allowed_scopes' => $scopes, 'rate_limit_per_minute' => 500, 'organization_access' => 'city', ...$attributes,
    ]);
    $ids = ApiEndpointDefinition::query()->whereIn('required_scope', $scopes)->pluck('id')->all();
    $application->endpoints()->sync(collect($ids)->mapWithKeys(fn (string $id): array => [$id => ['id' => (string) Str::uuid(), 'is_enabled' => true]])->all());

    return [$application, $application->createToken('mbr', $scopes)->plainTextToken];
}

/** A person with a personal token and the given screen permissions. */
function mbrPerson(array $abilities, array $permissions = []): array
{
    $user = User::factory()->create();
    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return [$user, $user->createToken('mbr-person', $abilities)->plainTextToken];
}

// ── F1 ───────────────────────────────────────────────────────────────────

it('refuses a provider-portal token on every directory and verification route (F1)', function (string $uri): void {
    [, $token] = mbrPerson(['provider:access'], ['employees.view', 'organizations.view', 'organization-units.view', 'positions.view']);

    $this->withToken($token)->getJson($uri)->assertForbidden()->assertJsonPath('error_code', 'missing_scope');
})->with([
    'employees' => '/api/v1/employees',
    'organization directory' => '/api/v1/organizations/directory',
    'directory' => '/api/v1/organizations',
    'positions' => '/api/v1/positions',
    'units' => '/api/v1/organization-units',
]);

it('stops an application at once when a scope is removed, even though its token still carries it (F1)', function (): void {
    [$application, $token] = mbrApplication(['employees.basic_read']);
    $this->withToken($token)->getJson('/api/v1/employees')->assertOk();

    $application->forceFill(['allowed_scopes' => []])->save();
    // A fresh request resolves the application again, as production does.
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/employees')->assertForbidden();
});

it('holds a person to their own screen permission behind the scope (F1)', function (): void {
    [, $token] = mbrPerson(['employees.basic_read']);

    $this->withToken($token)->getJson('/api/v1/employees')->assertForbidden();
});

// ── F2 ───────────────────────────────────────────────────────────────────

it('gives an unreviewed legacy application no organization-bound data (F2)', function (): void {
    [, $token] = mbrApplication(['employees.basic_read', 'organizations.read'], ['organization_access' => 'unreviewed']);

    $this->withToken($token)->getJson('/api/v1/employees')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($token)->getJson('/api/v1/employees/'.$this->woredaEmployee->id)->assertNotFound();
    $this->withToken($token)->getJson('/api/v1/organizations/'.$this->bureau->id)->assertNotFound();
});

it('defaults a newly registered application to scoped with no grants', function (): void {
    $application = ExternalApplication::query()->create(['name' => 'New', 'code' => 'MBR-NEW', 'status' => 'active', 'allowed_scopes' => [], 'rate_limit_per_minute' => 60]);

    expect($application->fresh()->organization_access)->toBe('scoped');
});

it('expands a subtree grant through the published hierarchy and stops at its edge (F2)', function (): void {
    [$application, $token] = mbrApplication(['employees.basic_read', 'organizations.read'], ['organization_access' => 'scoped']);
    $application->organizationScopes()->create(['organization_id' => $this->bureau->id, 'scope_type' => 'subtree']);

    $numbers = collect($this->withToken($token)->getJson('/api/v1/employees')->assertOk()->json('data'))->pluck('employee_number');
    expect($numbers->all())->toBe(['MBR-EMP-W']);

    $this->withToken($token)->getJson('/api/v1/employees/'.$this->woredaEmployee->id)->assertOk();
    $this->withToken($token)->getJson('/api/v1/employees/'.$this->otherEmployee->id)->assertNotFound();
    $this->withToken($token)->getJson('/api/v1/organizations/'.$this->woreda->id)->assertOk();
    $this->withToken($token)->getJson('/api/v1/organizations/'.$this->other->id.'/employees')->assertNotFound();
});

it('limits a self grant to that organization only (F2)', function (): void {
    [$application, $token] = mbrApplication(['employees.basic_read'], ['organization_access' => 'scoped']);
    $application->organizationScopes()->create(['organization_id' => $this->bureau->id, 'scope_type' => 'self']);

    // The woreda sits under the bureau, but a self grant does not reach it.
    $this->withToken($token)->getJson('/api/v1/employees/'.$this->woredaEmployee->id)->assertNotFound();
});

it('bounds card verification and eligibility to the application organizations (F2)', function (): void {
    [$application, $token] = mbrApplication(['id_cards.verify', 'service_eligibility.check'], ['organization_access' => 'scoped']);
    $application->organizationScopes()->create(['organization_id' => $this->woreda->id, 'scope_type' => 'self']);

    $this->withToken($token)->getJson('/api/v1/id-cards/verify/'.$this->woredaCard->public_card_uuid)->assertOk();
    $this->withToken($token)->getJson('/api/v1/id-cards/verify/'.$this->otherCard->public_card_uuid)->assertNotFound()->assertJsonPath('valid', false);
    $this->withToken($token)->getJson('/api/v1/employees/'.$this->otherEmployee->id.'/service-eligibility?service_type=cafeteria')->assertNotFound();
});

// ── F3 / F4 ──────────────────────────────────────────────────────────────

it('returns stable uuids that drive the eligibility call end-to-end, for a machine principal, without a 500 (F3, F4)', function (): void {
    [, $token] = mbrApplication(['id_cards.verify', 'service_eligibility.check']);

    $verified = $this->withToken($token)->getJson('/api/v1/id-cards/verify/'.$this->woredaCard->public_card_uuid)
        ->assertOk()
        ->assertJsonPath('valid', true)
        ->assertJsonPath('employee_uuid', $this->woredaEmployee->id)
        ->assertJsonPath('card_uuid', $this->woredaCard->public_card_uuid)
        ->assertJsonPath('organization_uuid', $this->woreda->id)
        ->assertJsonPath('assignment_status', 'active');

    expect($verified->json('verified_at'))->not->toBeNull();

    $eligibility = $this->withToken($token)
        ->getJson('/api/v1/employees/'.$verified->json('employee_uuid').'/service-eligibility?service_type=cafeteria');

    expect($eligibility->status())->toBeIn([200, 403])
        ->and($eligibility->json())->toHaveKeys(['eligible', 'reason_code', 'service', 'effective_context', 'checked_at'])
        ->and($eligibility->json('effective_context.employee_uuid'))->toBe($this->woredaEmployee->id);
});

it('rejects an eligibility call without a service type with 422, not 500', function (): void {
    [, $token] = mbrApplication(['service_eligibility.check']);

    $this->withToken($token)->getJson('/api/v1/employees/'.$this->woredaEmployee->id.'/service-eligibility')->assertUnprocessable();
});

// ── F5 ───────────────────────────────────────────────────────────────────

it('reports a card valid only when every validity condition holds (F5)', function (array $overrides, bool $valid, string $status): void {
    [, $token] = mbrApplication(['id_cards.verify']);
    $this->woredaCard->forceFill($overrides)->save();

    $this->withToken($token)->getJson('/api/v1/id-cards/verify/'.$this->woredaCard->public_card_uuid)
        ->assertOk()
        ->assertJsonPath('valid', $valid)
        ->assertJsonPath('status', $status);
})->with([
    'active current card' => [[], true, 'active'],
    'qr revoked' => [['qr_status' => 'revoked'], false, 'revoked'],
    'superseded card' => [['is_current' => false], false, 'replaced'],
    'expired' => [['expires_at' => now()->subDay()], false, 'expired'],
    'revoked_at set' => [['revoked_at' => now()->subHour()], false, 'revoked'],
    'lost' => [['status' => CardStatus::Lost->value], false, 'lost'],
    'suspended' => [['status' => CardStatus::Suspended->value], false, 'suspended'],
]);

// ── F17 ──────────────────────────────────────────────────────────────────

it('serves a photo through a signed link and re-authorises the issuing principal (F17)', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('employee-photos/mbr-photo.jpg', 'jpeg-bytes');
    $this->woredaEmployee->forceFill(['photo_path' => 'employee-photos/mbr-photo.jpg'])->save();

    [$application, $token] = mbrApplication(['id_cards.verify', 'employees.photo_read']);

    $url = $this->withToken($token)->getJson('/api/v1/id-cards/verify/'.$this->woredaCard->public_card_uuid)
        ->assertOk()->json('employee.photo_url');

    expect($url)->toContain('signature=')->not->toContain('/storage/');

    $this->get($url)->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'no-store, private');

    // Tampered: the signature no longer matches.
    $this->get(str_replace('principal=application', 'principal=user', $url))->assertForbidden();

    // Scope withdrawn after the link was issued: the link dies with it.
    $application->forceFill(['allowed_scopes' => ['id_cards.verify']])->save();
    $this->app['auth']->forgetGuards();
    $this->get($url)->assertNotFound();
});

it('lets a photo link expire', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('employee-photos/mbr-photo.jpg', 'jpeg-bytes');
    $this->woredaEmployee->forceFill(['photo_path' => 'employee-photos/mbr-photo.jpg'])->save();
    [, $token] = mbrApplication(['id_cards.verify', 'employees.photo_read']);

    $url = $this->withToken($token)->getJson('/api/v1/id-cards/verify/'.$this->woredaCard->public_card_uuid)->json('employee.photo_url');

    $this->travel(10)->minutes();
    $this->get($url)->assertForbidden();
});

it('gives no photo link to an application without the photo scope', function (): void {
    $this->woredaEmployee->forceFill(['photo_path' => 'employee-photos/mbr-photo.jpg'])->save();
    [, $token] = mbrApplication(['id_cards.verify']);

    $this->withToken($token)->getJson('/api/v1/id-cards/verify/'.$this->woredaCard->public_card_uuid)
        ->assertOk()->assertJsonPath('employee.photo_url', null);
});

// ── F16 ──────────────────────────────────────────────────────────────────

it('refuses a machine principal on provider routes until it is bound to a provider, without a 500 (F16)', function (): void {
    [, $token] = mbrApplication(['id_cards.verify']);

    $this->withToken($token)->postJson('/api/v1/cards/verify', ['token' => 'x|y', 'service_type' => 'cafeteria'])
        ->assertForbidden()->assertJsonPath('error_code', 'provider_binding_missing');
});

it('makes a person name the provider of a card check and keeps the service in the path (ADR 0003)', function (): void {
    [$person, $token] = mbrPerson(['provider:access']);
    UserOrganizationScope::query()->create([
        'user_id' => $person->id, 'organization_id' => $this->woreda->id, 'scope_type' => OrganizationScopeType::ServiceProvider,
        'service_provider_id' => $this->provider->id, 'service_type_id' => $this->serviceType->id, 'is_active' => true,
    ]);

    // Without a provider the provider scope was skipped and any provider's entitlement matched.
    $this->withToken($token)->postJson('/api/v1/cards/verify', ['token' => 'x|y', 'service_type' => 'cafeteria'])
        ->assertStatus(422)->assertJsonPath('error_code', 'provider_required');
    $this->withToken($token)->postJson('/api/v1/services/cafeteria/authorize', ['token' => 'x|y'])
        ->assertStatus(422)->assertJsonPath('error_code', 'provider_required');
    expect(AuditLog::query()->where('event_type', 'provider_api_denied')->where('reason', 'No service provider named.')->count())->toBe(2);

    $this->withToken($token)->postJson('/api/v1/services/cafeteria/authorize', ['token' => 'x|y', 'provider_code' => 'MBR-CAFE'])
        ->assertOk()->assertJsonPath('data.allowed', false);
    $this->withToken($token)->postJson('/api/v1/services/cafeteria/authorize', ['token' => 'x|y', 'provider_code' => 'MBR-OTHER-CAFE'])
        ->assertForbidden()->assertJsonPath('error_code', 'provider_scope_denied');

    // A body service that differs from the path is refused, not silently replaced.
    ServiceType::query()->firstOrCreate(['code' => 'transport'], ['name_en' => 'Transport']);
    $this->withToken($token)->postJson('/api/v1/services/cafeteria/authorize', ['token' => 'x|y', 'provider_code' => 'MBR-CAFE', 'service_type' => 'transport'])
        ->assertStatus(422)->assertJsonValidationErrors('service_type');
});

it('lets a bound application act only for its own provider (F16)', function (): void {
    [, $token] = mbrApplication(['id_cards.verify', 'service_transactions.create'], ['service_provider_id' => $this->provider->id]);

    $this->withToken($token)->postJson('/api/v1/cards/verify', ['token' => 'x|y', 'service_type' => 'cafeteria', 'provider_code' => 'MBR-CAFE'])
        ->assertOk()->assertJsonPath('data.allowed', false);

    $this->withToken($token)->postJson('/api/v1/cards/verify', ['token' => 'x|y', 'service_type' => 'cafeteria', 'provider_code' => 'MBR-OTHER-CAFE'])
        ->assertForbidden()->assertJsonPath('error_code', 'provider_binding_denied');

    // Every item of an offline batch is checked, not just a top-level code.
    $this->withToken($token)->postJson('/api/v1/offline-sync/transactions', ['transactions' => [
        ['token' => 'x|y', 'service_type' => 'cafeteria', 'provider_code' => 'MBR-CAFE'],
        ['token' => 'x|y', 'service_type' => 'cafeteria', 'provider_code' => 'MBR-OTHER-CAFE'],
    ]])->assertForbidden();

    expect(ApiRequestLog::query()->where('failure_reason', 'request_failed')->count())->toBeGreaterThan(0);
});

it('caps an offline batch', function (): void {
    [, $token] = mbrApplication(['service_transactions.create'], ['service_provider_id' => $this->provider->id]);
    $items = array_fill(0, 201, ['token' => 'x|y', 'service_type' => 'cafeteria', 'provider_code' => 'MBR-CAFE']);

    $this->withToken($token)->postJson('/api/v1/offline-sync/transactions', ['transactions' => $items])->assertUnprocessable();
});

// ── F6 idempotency ───────────────────────────────────────────────────────

it('replays a repeated request and refuses a conflicting one under the same key (F6)', function (): void {
    [, $token] = mbrApplication(['service_transactions.create'], ['service_provider_id' => $this->provider->id]);
    $body = ['transactions' => [['token' => 'x|y', 'service_type' => 'cafeteria', 'provider_code' => 'MBR-CAFE']]];

    $first = $this->withToken($token)->withHeader('Idempotency-Key', 'mbr-key-1')->postJson('/api/v1/offline-sync/transactions', $body)->assertOk();
    $this->withToken($token)->withHeader('Idempotency-Key', 'mbr-key-1')->postJson('/api/v1/offline-sync/transactions', $body)
        ->assertOk()->assertHeader('Idempotent-Replay', 'true')->assertExactJson($first->json());

    $changed = ['transactions' => [['token' => 'x|z', 'service_type' => 'cafeteria', 'provider_code' => 'MBR-CAFE']]];
    $this->withToken($token)->withHeader('Idempotency-Key', 'mbr-key-1')->postJson('/api/v1/offline-sync/transactions', $changed)
        ->assertStatus(409)->assertJsonPath('error_code', 'idempotency_key_reused');

    expect(ApiIdempotencyKey::query()->where('idempotency_key', 'mbr-key-1')->count())->toBe(1);
});

it('refuses a duplicate that arrives while the original is still running (F6)', function (): void {
    [$application, $token] = mbrApplication(['service_transactions.create'], ['service_provider_id' => $this->provider->id]);
    $body = ['transactions' => [['token' => 'x|y', 'service_type' => 'cafeteria', 'provider_code' => 'MBR-CAFE']]];

    // The claim a concurrent first request would hold.
    ApiIdempotencyKey::query()->create([
        'principal_type' => 'application', 'principal_id' => (string) $application->id, 'route' => 'api/v1/offline-sync/transactions',
        'idempotency_key' => 'mbr-key-2', 'request_hash' => hash('sha256', 'POST|api/v1/offline-sync/transactions|'.json_encode($body, JSON_UNESCAPED_UNICODE)),
        'status' => ApiIdempotencyKey::STATUS_IN_PROGRESS, 'created_at' => now(), 'expires_at' => now()->addDay(),
    ]);

    $this->withToken($token)->withHeader('Idempotency-Key', 'mbr-key-2')->postJson('/api/v1/offline-sync/transactions', $body)
        ->assertStatus(409)->assertJsonPath('error_code', 'idempotency_in_progress');
});

it('treats one key sent to two different service paths as reused, not a replay of the first', function (): void {
    [$application, $token] = mbrApplication(['service_transactions.create'], ['service_provider_id' => $this->provider->id]);
    $body = ['token' => 'x|y', 'provider_code' => 'MBR-CAFE'];

    $this->withToken($token)->withHeader('Idempotency-Key', 'mbr-key-paths')->postJson('/api/v1/services/cafeteria/transactions', $body);

    $this->withToken($token)->withHeader('Idempotency-Key', 'mbr-key-paths')->postJson('/api/v1/services/transport/transactions', $body)
        ->assertStatus(409)->assertJsonPath('error_code', 'idempotency_key_reused');
});

it('enforces one claim per principal, route and key in the database (F6)', function (): void {
    $row = ['principal_type' => 'application', 'principal_id' => 'p1', 'route' => 'r', 'idempotency_key' => 'k', 'request_hash' => 'h', 'status' => 'in_progress', 'created_at' => now(), 'expires_at' => now()->addDay()];
    ApiIdempotencyKey::query()->create($row);

    expect(fn () => ApiIdempotencyKey::query()->create($row))->toThrow(UniqueConstraintViolationException::class);
});

it('namespaces keys by principal so two partners never collide (F6)', function (): void {
    [, $tokenA] = mbrApplication(['service_transactions.create'], ['service_provider_id' => $this->provider->id]);
    [, $tokenB] = mbrApplication(['service_transactions.create'], ['service_provider_id' => $this->provider->id]);
    $body = ['transactions' => [['token' => 'x|y', 'service_type' => 'cafeteria', 'provider_code' => 'MBR-CAFE']]];

    $this->withToken($tokenA)->withHeader('Idempotency-Key', 'shared')->postJson('/api/v1/offline-sync/transactions', $body)->assertOk();
    $this->app['auth']->forgetGuards();
    $this->withToken($tokenB)->withHeader('Idempotency-Key', 'shared')->postJson('/api/v1/offline-sync/transactions', $body)
        ->assertOk()->assertHeaderMissing('Idempotent-Replay');
});

// ── F18 / F19 ────────────────────────────────────────────────────────────

it('bounds a settlement report to its period (F18)', function (): void {
    [, $token] = mbrApplication(['reports.read_limited'], ['service_provider_id' => $this->provider->id]);
    foreach ([['2026-09-15 12:00:00', 100], ['2026-10-02 12:00:00', 40], ['2026-10-30 12:00:00', 2]] as [$at, $amount]) {
        DB::table('service_transactions')->insert([
            'id' => (string) Str::uuid(), 'employee_id' => $this->woredaEmployee->id, 'service_type_id' => $this->serviceType->id,
            'service_provider_id' => $this->provider->id, 'status' => 'settled', 'occurred_at' => $at, 'amount' => $amount, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $this->withToken($token)->getJson('/api/v1/providers/'.$this->provider->id.'/settlements/2026-10')
        ->assertOk()->assertJsonPath('settlement_amount', 42)->assertJsonPath('transaction_count', 2);
    $this->withToken($token)->getJson('/api/v1/providers/'.$this->provider->id.'/settlements/2026-13')
        ->assertUnprocessable()->assertJsonPath('error_code', 'invalid_period');
    // A bound application cannot read another provider's settlement.
    $this->withToken($token)->getJson('/api/v1/providers/'.$this->otherProvider->id.'/settlements/2026-10')->assertForbidden();
});

it('charges the entitlement in force, not the first row (F19)', function (): void {
    $base = ['employee_id' => $this->woredaEmployee->id, 'service_type_id' => $this->serviceType->id];
    Entitlement::query()->create([...$base, 'status' => EntitlementStatus::Expired->value, 'effective_from' => now()->subYears(2)->toDateString(), 'effective_to' => now()->subYear()->toDateString()]);
    Entitlement::query()->create([...$base, 'service_provider_id' => $this->otherProvider->id, 'status' => EntitlementStatus::Active->value, 'effective_from' => now()->subMonth()->toDateString()]);
    $neutral = Entitlement::query()->create([...$base, 'status' => EntitlementStatus::Active->value, 'effective_from' => now()->subMonth()->toDateString()]);
    $own = Entitlement::query()->create([...$base, 'service_provider_id' => $this->provider->id, 'status' => EntitlementStatus::Active->value, 'effective_from' => now()->subWeek()->toDateString()]);
    Entitlement::query()->create([...$base, 'service_provider_id' => $this->provider->id, 'status' => EntitlementStatus::Active->value, 'effective_from' => now()->addMonth()->toDateString()]);

    expect(Entitlement::inForceFor($this->woredaEmployee->id, $this->serviceType->id, $this->provider->id)?->id)->toBe($own->id)
        ->and(Entitlement::inForceFor($this->woredaEmployee->id, $this->serviceType->id, null)?->id)->toBe($neutral->id);
});
