<?php

use App\Models\ApiEndpointDefinition;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaSubsidyRule;
use App\Models\CafeteriaTransaction;
use App\Models\Employee;
use App\Models\ExternalApplication;
use App\Models\IdCard;
use App\Models\NfcCredential;
use App\Models\NfcVerificationLog;
use App\Models\ServiceProvider;
use App\Models\ServiceTerminal;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\ApiEndpointCatalogService;
use App\Services\Cafeteria\CafeteriaQrScanService;
use App\Services\Nfc\NfcCredentialService;
use App\Services\Nfc\SecureCardAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Test-only adapter models a signed transcript, not a production card protocol.
final class TestNfcAdapter implements SecureCardAdapter
{
    public function supports(NfcCredential $credential): bool
    {
        return true;
    }

    public function verify(NfcCredential $credential, ServiceTerminal $terminal, string $nonce, string $contextHash, array $proof): bool
    {
        return hash_equals(hash_hmac('sha256', $credential->credential_id.$terminal->terminal_code.$nonce.$contextHash, 'test-only-key'), $proof['mac'] ?? '');
    }
}

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->employee = Employee::create(['employee_number' => 'NFC-EMP', 'first_name' => 'Nfc', 'last_name' => 'Test', 'full_name' => 'Nfc Test', 'status' => 'active']);
    $this->card = IdCard::create(['employee_id' => $this->employee->id, 'card_number' => 'NFC-CARD', 'status' => 'active', 'is_current' => true,
        'public_card_uuid' => (string) Str::uuid(), 'qr_status' => 'active', 'expires_at' => now()->addYear(), 'activated_at' => now()]);
    $this->service = app(NfcCredentialService::class);
    $this->credential = $this->service->provision($this->card, $this->actor);
    $this->credential = $this->service->transition($this->credential, 'activate', $this->actor);
    $this->application = ExternalApplication::create(['name' => 'NFC terminal', 'code' => 'NFC-APP', 'status' => 'active', 'allowed_scopes' => ['nfc.verify', 'nfc.service_eligibility', 'nfc.service_transactions.create'], 'rate_limit_per_minute' => 1000]);
    app(ApiEndpointCatalogService::class)->sync();
    foreach (ApiEndpointDefinition::where('uri', 'like', '/api/v1/nfc/%')->get() as $endpoint) {
        $this->application->endpoints()->attach($endpoint->id, ['id' => (string) Str::uuid(), 'allowed_scope' => $endpoint->required_scope, 'is_enabled' => true]);
    }
    $this->terminal = ServiceTerminal::create(['terminal_code' => 'TEST-1', 'name' => 'Test reader', 'terminal_type' => 'verification', 'status' => 'active', 'external_application_id' => $this->application->id]);
    $this->token = $this->application->createToken('nfc', $this->application->allowed_scopes)->plainTextToken;
    $this->payload = ['credential' => $this->credential->credential_id, 'terminal_id' => $this->terminal->terminal_code];
});

it('provisions random independent references and preserves QR through replacement', function () {
    $uuid = $this->card->public_card_uuid;
    expect($this->credential->credential_id)->toMatch('/^nfc_[a-f0-9]{64}$/');
    $old = $this->service->transition($this->credential, 'replace', $this->actor);
    $next = NfcCredential::findOrFail($old->replaced_by_id);
    expect($next->credential_id)->not->toBe($old->credential_id)->and($next->status)->toBe('pending')
        ->and($this->card->fresh()->public_card_uuid)->toBe($uuid);
    $this->withToken($this->token)->postJson('/api/v1/nfc/verify', $this->payload)->assertForbidden()->assertJsonPath('reason_code', 'NFC_CREDENTIAL_REPLACED');
});

it('rejects provisioning an inactive card', function () {
    $this->service->transition($this->credential, 'revoke', $this->actor);
    $this->card->update(['status' => 'pending_print']);
    expect(fn () => $this->service->provision($this->card, $this->actor))->toThrow(ValidationException::class);
});

it('keeps NFC identity when employee or display snapshot changes', function () {
    $this->employee->update(['full_name' => 'Changed Name']);
    $this->card->update(['display_snapshot' => ['template' => 'changed']]);
    expect($this->credential->fresh()->credential_id)->toBe($this->payload['credential']);
});

it('verifies reference credentials online without leaking records or secrets', function () {
    $response = $this->withToken($this->token)->postJson('/api/v1/nfc/verify', $this->payload)->assertOk()->assertJsonPath('valid', true)->assertJsonPath('assurance', 'reference');
    expect(array_keys($response->json()))->toBe(['valid', 'eligible', 'reason_code', 'assurance']);
    expect(NfcVerificationLog::where('event_type', 'verification')->where('result', 'allowed')->exists())->toBeTrue();
});

it('blocks invalid credential states', function (string $status, string $reason) {
    $this->credential->update(['status' => $status]);
    $this->withToken($this->token)->postJson('/api/v1/nfc/verify', $this->payload)->assertForbidden()->assertJsonPath('reason_code', $reason);
})->with(['pending' => ['pending', 'NFC_CREDENTIAL_INACTIVE'], 'suspended' => ['suspended', 'NFC_CREDENTIAL_SUSPENDED'], 'lost' => ['lost', 'NFC_CREDENTIAL_LOST'], 'revoked' => ['revoked', 'NFC_CREDENTIAL_REVOKED'], 'expired' => ['expired', 'NFC_CREDENTIAL_EXPIRED']]);

it('blocks invalid underlying cards', function (string $status, string $reason) {
    $this->card->update(['status' => $status]);
    $this->withToken($this->token)->postJson('/api/v1/nfc/verify', $this->payload)->assertForbidden()->assertJsonPath('reason_code', $reason);
})->with([['suspended', 'CARD_INACTIVE'], ['lost', 'CARD_LOST'], ['revoked', 'CARD_REVOKED'], ['replaced', 'CARD_REPLACED'], ['expired', 'CARD_EXPIRED']]);

it('blocks expiry and inactive employees immediately', function () {
    $this->card->update(['expires_at' => now()->subMinute()]);
    $this->withToken($this->token)->postJson('/api/v1/nfc/verify', $this->payload)->assertForbidden()->assertJsonPath('reason_code', 'CARD_EXPIRED');
    $this->card->update(['expires_at' => now()->addYear()]);
    $this->employee->update(['status' => 'suspended']);
    $this->postJson('/api/v1/nfc/verify', $this->payload)->assertForbidden()->assertJsonPath('reason_code', 'EMPLOYEE_INACTIVE');
});

it('requires explicitly assigned endpoints including for legacy empty assignments', function () {
    $this->application->endpoints()->detach();
    $this->withToken($this->token)->postJson('/api/v1/nfc/verify', $this->payload)->assertForbidden()->assertJsonPath('reason_code', 'ENDPOINT_NOT_ALLOWED');
});

it('does not accept legacy provider scopes', function () {
    $token = $this->application->createToken('legacy', ['provider:access'])->plainTextToken;
    $this->withToken($token)->postJson('/api/v1/nfc/verify', $this->payload)->assertForbidden()->assertJsonPath('reason_code', 'SCOPE_MISSING');
});

it('enforces live application scope removal', function () {
    $this->application->update(['allowed_scopes' => []]);
    $this->withToken($this->token)->postJson('/api/v1/nfc/verify', $this->payload)->assertForbidden()->assertJsonPath('reason_code', 'SCOPE_MISSING');
});

it('blocks unregistered or foreign terminals', function () {
    $this->terminal->update(['external_application_id' => null]);
    $this->withToken($this->token)->postJson('/api/v1/nfc/verify', $this->payload)->assertForbidden()->assertJsonPath('reason_code', 'TERMINAL_NOT_ALLOWED');
});

it('never authorizes transactions from static references', function () {
    $this->terminal->update(['service_type' => 'cafeteria']);
    $this->withToken($this->token)->postJson('/api/v1/nfc/service-transactions/verify-and-record', $this->payload + ['service_type' => 'cafeteria', 'reference' => (string) Str::uuid()])
        ->assertForbidden()->assertJsonPath('reason_code', 'CRYPTOGRAPHIC_PROOF_REQUIRED');
});

it('fails closed without hardware integration', function () {
    $this->credential->update(['credential_type' => 'secure_smart_card']);
    $this->withToken($this->token)->postJson('/api/v1/nfc/verify', $this->payload)->assertForbidden()->assertJsonPath('reason_code', 'INVALID_CRYPTOGRAPHIC_PROOF');
});

it('accepts a bound proof once and rejects replay', function () {
    config(['nfc.adapter' => TestNfcAdapter::class]);
    $this->credential->update(['credential_type' => 'secure_smart_card']);
    $challenge = $this->withToken($this->token)->postJson('/api/v1/nfc/challenges', $this->payload)->assertOk()->json();
    $payload = $this->payload + ['challenge' => $challenge['challenge'], 'proof' => ['mac' => hash_hmac('sha256', $this->credential->credential_id.$this->terminal->terminal_code.$challenge['challenge'].$challenge['context_hash'], 'test-only-key')]];
    $this->postJson('/api/v1/nfc/verify', $payload)->assertOk()->assertJsonPath('assurance', 'cryptographic');
    $this->postJson('/api/v1/nfc/verify', $payload)->assertForbidden()->assertJsonPath('reason_code', 'REPLAY_DETECTED');
    expect(NfcVerificationLog::where('event_type', 'replay_attempt')->exists())->toBeTrue();
});

it('rejects an expired challenge', function () {
    config(['nfc.adapter' => TestNfcAdapter::class]);
    $this->credential->update(['credential_type' => 'secure_smart_card']);
    $challenge = $this->withToken($this->token)->postJson('/api/v1/nfc/challenges', $this->payload)->assertOk()->json();
    $this->travel(2)->minutes();
    $this->postJson('/api/v1/nfc/verify', $this->payload + ['challenge' => $challenge['challenge'], 'proof' => []])->assertForbidden()->assertJsonPath('reason_code', 'INVALID_CRYPTOGRAPHIC_PROOF');
});

it('does not allow ordinary users to provision or administer terminals', function () {
    $this->actingAs($this->actor)->postJson(route('nfc.provision', $this->card), ['credential_type' => 'ndef_reference'])->assertForbidden();
    $this->get(route('nfc-management.terminals.index'))->assertForbidden();
    $this->get(route('nfc-management.dashboard'))->assertForbidden();
});

function nfcCafeteriaFixture($test): CafeteriaProvider
{
    $test->travelTo(Carbon::parse('2026-09-14 10:00:00'));
    $service = ServiceType::firstOrCreate(['code' => 'cafeteria'], ['name_en' => 'Cafeteria']);
    $provider = ServiceProvider::create(['code' => 'NFC-CAFE', 'name' => 'NFC Cafe', 'service_type_id' => $service->id, 'status' => 'active']);
    $cafeteria = CafeteriaProvider::create(['code' => 'NFC-CAFE', 'name_en' => 'NFC Cafe', 'service_provider_id' => $provider->id, 'is_active' => true]);
    CafeteriaSubsidyRule::create(['code' => 'NFC-SUBSIDY', 'name_en' => 'Subsidy', 'subsidy_amount' => 100, 'currency' => 'ETB', 'effective_from' => '2026-01-01', 'applies_to' => 'all_employees', 'is_active' => true]);
    $test->terminal->update(['provider_id' => $provider->id, 'cafeteria_provider_id' => $cafeteria->id, 'service_type' => 'cafeteria']);

    return $cafeteria;
}

function signedNfcPayload($test, string $purpose, array $extra = []): array
{
    config(['nfc.adapter' => TestNfcAdapter::class]);
    $test->credential->update(['credential_type' => 'secure_smart_card']);
    $payload = $test->payload + ['purpose' => $purpose] + $extra;
    $challenge = $test->withToken($test->token)->postJson('/api/v1/nfc/challenges', $payload)->assertOk()->json();

    return $payload + ['challenge' => $challenge['challenge'], 'proof' => ['mac' => hash_hmac('sha256', $test->credential->credential_id.$test->terminal->terminal_code.$challenge['challenge'].$challenge['context_hash'], 'test-only-key')]];
}

it('checks cafeteria eligibility without consuming service and records secure NFC only once', function () {
    nfcCafeteriaFixture($this);
    $this->withToken($this->token)->postJson('/api/v1/nfc/service-eligibility', $this->payload + ['service_type' => 'cafeteria'])->assertOk()->assertJsonPath('eligible', true);
    expect(CafeteriaTransaction::count())->toBe(0);
    $payload = signedNfcPayload($this, 'record', ['service_type' => 'cafeteria', 'reference' => (string) Str::uuid()]);
    $this->postJson('/api/v1/nfc/service-transactions/verify-and-record', $payload)->assertOk()->assertJsonPath('eligible', true);
    expect(CafeteriaTransaction::count())->toBe(1);
    $payload = signedNfcPayload($this, 'record', ['service_type' => 'cafeteria', 'reference' => (string) Str::uuid()]);
    $this->postJson('/api/v1/nfc/service-transactions/verify-and-record', $payload)->assertForbidden()->assertJsonPath('reason_code', 'ALREADY_SERVED');
    expect(CafeteriaTransaction::count())->toBe(1);
});

it('uses the same cafeteria records for QR and NFC', function () {
    $cafeteria = nfcCafeteriaFixture($this);
    $qr = app(CafeteriaQrScanService::class)->process($this->card->public_card_uuid, $cafeteria, now());
    expect($qr['allowed'])->toBeTrue()->and($this->card->fresh()->public_card_uuid)->toBe($this->card->public_card_uuid);
    $payload = signedNfcPayload($this, 'record', ['service_type' => 'cafeteria', 'reference' => (string) Str::uuid()]);
    $this->postJson('/api/v1/nfc/service-transactions/verify-and-record', $payload)->assertForbidden()->assertJsonPath('reason_code', 'ALREADY_SERVED');
    expect(CafeteriaTransaction::count())->toBe(1);
});

it('rejects proof reused for another purpose or transaction reference', function () {
    nfcCafeteriaFixture($this);
    $payload = signedNfcPayload($this, 'record', ['service_type' => 'cafeteria', 'reference' => (string) Str::uuid()]);
    $payload['reference'] = (string) Str::uuid();
    $this->postJson('/api/v1/nfc/service-transactions/verify-and-record', $payload)->assertForbidden()->assertJsonPath('reason_code', 'INVALID_CRYPTOGRAPHIC_PROOF');
    expect(CafeteriaTransaction::count())->toBe(0);
});

it('never stores proof material in NFC logs', function () {
    $payload = signedNfcPayload($this, 'verify');
    $this->postJson('/api/v1/nfc/verify', $payload)->assertOk();
    $logs = NfcVerificationLog::all()->toJson();
    expect($logs)->not->toContain($payload['challenge'])->not->toContain($payload['proof']['mac']);
});
