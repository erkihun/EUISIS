<?php

declare(strict_types=1);

use App\Actions\Entitlements\GrantEntitlementAction;
use App\Actions\IdCards\GenerateCardTokenAction;
use App\Enums\AssignmentStatus;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Enums\OrganizationStatus;
use App\Models\CardVerification;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\ServiceProvider;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\IdCards\CardQrPayloadService;
use App\Services\IdCards\IdCardQrCodeRenderer;
use App\Services\Verification\VerifyCardForServiceAction;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach (['cards.manage', 'entitlements.view'] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }
    Role::findOrCreate('HR Officer', 'web')->syncPermissions(Permission::all());
});

function makeActiveCard(): array
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'SEC-BUREAU'], ['name_en' => 'Security Bureau']);
    $organization = Organization::query()->firstOrCreate(
        ['code' => 'SEC-ORG'],
        ['organization_type_id' => $type->id, 'name_en' => 'Security Org', 'status' => OrganizationStatus::Active]
    );

    $employee = Employee::query()->create([
        'employee_number' => 'EMP-SEC-'.uniqid(),
        'first_name' => 'John',
        'last_name' => 'Security',
        'full_name' => 'John Security',
        'phone' => '0911000001',
        'email' => 'john.security@test.local',
        'date_of_birth' => '1990-01-15',
        'status' => EmployeeStatus::Active,
    ]);

    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id,
        'organization_id' => $organization->id,
        'assignment_status' => AssignmentStatus::Active,
        'effective_from' => now()->toDateString(),
        'is_current' => true,
    ]);
    $employee->update(['current_assignment_id' => $assignment->id]);

    $card = IdCard::query()->create([
        'employee_id' => $employee->id,
        'card_number' => 'CARD-SEC-'.uniqid(),
        'status' => CardStatus::Active,
        'expires_at' => now()->addYear(),
        'token_version' => 0,
        'is_current' => true,
    ]);

    return [$employee, $card];
}

// Test 17: QR payload excludes PII
it('QR token does not contain employee PII', function (): void {
    [$employee, $card] = makeActiveCard();
    $token = app(GenerateCardTokenAction::class)->execute($card);

    expect($token)->not->toContain($employee->full_name)
        ->and($token)->not->toContain($employee->phone ?? '')
        ->and($token)->not->toContain($employee->email ?? '')
        ->and($token)->not->toContain($employee->employee_number);
});

// Test 18: Raw QR token is not stored in database
it('raw token is never stored in database - only sha256 hash', function (): void {
    [$employee, $card] = makeActiveCard();
    $rawToken = app(GenerateCardTokenAction::class)->execute($card);

    $card->refresh();
    expect($card->token_hash)->not->toBeNull()
        ->and($card->token_hash)->not->toBe($rawToken)
        ->and($card->token_hash)->toBe(hash('sha256', $rawToken));
});

// Test 19: Verification returns minimal safe response
it('card verification response excludes PII', function (): void {
    [$employee, $card] = makeActiveCard();

    $serviceType = ServiceType::query()->firstOrCreate(
        ['code' => 'transport-sec'],
        ['name_en' => 'Transport Sec']
    );
    $provider = ServiceProvider::query()->firstOrCreate(
        ['code' => 'SP-SEC-TEST'],
        ['service_type_id' => $serviceType->id, 'name' => 'Sec Provider', 'status' => 'active']
    );

    $actor = User::factory()->create();
    $actor->assignRole('HR Officer');
    app(GrantEntitlementAction::class)->execute($employee, $serviceType, $provider, $actor, 10);

    $rawToken = app(GenerateCardTokenAction::class)->execute($card);
    $token = $card->id.'|'.$rawToken;

    $result = app(VerifyCardForServiceAction::class)->execute($token, $serviceType, $provider);

    expect($result)->toHaveKey('allowed')
        ->and($result)->not->toHaveKey('full_name')
        ->and($result)->not->toHaveKey('phone')
        ->and($result)->not->toHaveKey('email')
        ->and($result)->not->toHaveKey('date_of_birth')
        ->and($result['allowed'])->toBeTrue();
});

// Test 20: Verification denial is audited
it('denied verification creates audit log', function (): void {
    $serviceType = ServiceType::query()->firstOrCreate(
        ['code' => 'transport-audit'],
        ['name_en' => 'Transport Audit']
    );

    $result = app(VerifyCardForServiceAction::class)->execute(
        'invalid-uuid|invalid-token',
        $serviceType,
        null,
    );

    expect($result['allowed'])->toBeFalse()
        ->and($result['result_code'])->toBe('invalid_token');

    expect(CardVerification::query()
        ->where('result_code', 'invalid_token')
        ->exists()
    )->toBeTrue();
});

/*
 * Public card reference stability.
 *
 * `public_card_uuid` is printed into the QR on a physical card, which cannot be
 * patched after issue. Ordinary edits — the holder's name, contact details,
 * assignment, or the card's own dates — must never rotate it, or every card in
 * circulation would silently stop verifying.
 */

it('keeps the public card uuid stable when employee details are updated', function (): void {
    [$employee, $card] = makeActiveCard();

    app(CardQrPayloadService::class)->ensurePublicReference($card);
    $before = $card->fresh()->public_card_uuid;

    expect($before)->not->toBeNull();

    $employee->update([
        'first_name' => 'Renamed',
        'full_name' => 'Renamed Security',
        'phone' => '0911777666',
        'email' => 'renamed@test.local',
        'photo_path' => 'photos/new.jpg',
    ]);

    expect($card->fresh()->public_card_uuid)->toBe($before);
});

it('keeps the public card uuid stable when card details are updated', function (): void {
    [, $card] = makeActiveCard();

    app(CardQrPayloadService::class)->ensurePublicReference($card);
    $before = $card->fresh()->public_card_uuid;

    $card->update([
        'issued_at' => now(),
        'expires_at' => now()->addYears(3),
    ]);

    expect($card->fresh()->public_card_uuid)->toBe($before);
});

it('returns the same qr url however often it is rebuilt', function (): void {
    [, $card] = makeActiveCard();

    $service = app(CardQrPayloadService::class);
    $first = $service->buildStableQrUrl($card);

    for ($i = 0; $i < 5; $i++) {
        expect($service->buildStableQrUrl($card->fresh()))->toBe($first);
    }

    // The URL a printed card carries: the OTP-gated checker, keyed on the
    // stable public reference and nothing else.
    expect($first)->toContain('/id-checker/'.$card->fresh()->public_card_uuid);
});

it('keeps the card qr url free of employee identifiers', function (): void {
    [$employee, $card] = makeActiveCard();

    $url = app(CardQrPayloadService::class)->buildStableQrUrl($card);

    expect($url)->not->toContain($employee->id)
        ->and($url)->not->toContain($employee->employee_number)
        ->and($url)->not->toContain('John')
        ->and($url)->not->toContain('Security')
        ->and($url)->not->toContain('0911000001')
        ->and($url)->not->toContain('john.security@test.local')
        ->and($url)->not->toContain($card->card_number);
});

it('rotates the public card uuid only on an explicit security rotation', function (): void {
    [, $card] = makeActiveCard();

    $service = app(CardQrPayloadService::class);
    $service->ensurePublicReference($card);
    $before = $card->fresh()->public_card_uuid;

    $actor = User::factory()->create();
    $actor->assignRole('HR Officer');

    $service->rotateQrReference($card->fresh(), $actor, 'Card reported lost');

    expect($card->fresh()->public_card_uuid)->not->toBe($before);
});

/*
 * QR image integrity.
 *
 * The renderer wraps the library's output in its own <svg>. The library
 * base64-encodes to a data URI by default, and embedding that inside the
 * wrapper as plain text produces a blank white box that no scanner can read —
 * a failure that is invisible until someone tries to scan a printed card.
 */

it('renders real svg markup rather than a nested data uri', function (): void {
    $uri = app(IdCardQrCodeRenderer::class)->asSvgDataUri('https://example.test/id-checker/abc', 200);

    expect($uri)->toStartWith('data:image/svg+xml;base64,');

    $decoded = base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), true);

    expect($decoded)->toStartWith('<svg')
        // The failure mode: a data: URI sitting inside the wrapper as text.
        ->and($decoded)->not->toContain('>data:image')
        // Real module geometry, not an empty container.
        ->and($decoded)->toContain('<path');
});

it('keeps the inner qr viewbox so the code is not cropped', function (): void {
    $uri = app(IdCardQrCodeRenderer::class)->asSvgDataUri('https://example.test/id-checker/abc', 200);
    $decoded = base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), true);

    // The module count varies with URL length, so the inner element must keep
    // its own viewBox; a hardcoded one on the wrapper would crop the code.
    expect($decoded)->toMatch('/viewBox="0 0 \d+ \d+"/');
});

it('returns raw markup from the inline svg helper', function (): void {
    $inline = app(IdCardQrCodeRenderer::class)->asInlineSvgContent('https://example.test/id-checker/abc');

    expect($inline)->toStartWith('<svg')
        ->and($inline)->not->toStartWith('data:');
});

it('renders a scannable qr for a real card verification url', function (): void {
    [, $card] = makeActiveCard();

    $url = app(CardQrPayloadService::class)->buildStableQrUrl($card);
    $uri = app(IdCardQrCodeRenderer::class)->asSvgDataUri($url, 200);
    $decoded = base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), true);

    expect($decoded)->toContain('<path')
        ->and(strlen($decoded))->toBeGreaterThan(1000);
});
