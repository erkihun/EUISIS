<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\AuditEventType;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PublicIdCheckOtp;
use App\Notifications\PublicIdCheckOtpNotification;
use App\Services\IdCards\CardQrPayloadService;
use App\Services\PublicIdCheckerService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $type = OrganizationType::query()->create(['code' => 'PIC-TYPE', 'name_en' => 'Checker Type']);

    $this->org = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'PIC-ORG',
        'name_en' => 'Checker Organization',
        'status' => 'active',
    ]);

    $unit = OrganizationUnit::query()->create([
        'organization_id' => $this->org->id,
        'code' => 'PIC-U1',
        'name_en' => 'Checker Unit',
        'unit_type' => 'department',
        'status' => 'active',
    ]);

    $position = Position::query()->create([
        'organization_id' => $this->org->id,
        'organization_unit_id' => $unit->id,
        'job_position_code' => 'PIC-P1',
        'title_en' => 'Checker Position',
        'is_active' => true,
    ]);

    $this->employee = Employee::query()->create([
        'employee_number' => 'PIC-EMP-1',
        'first_name' => 'Selam',
        'last_name' => 'Tesfaye',
        'full_name' => 'Selam Tesfaye',
        'national_id' => '9876543210987654',
        'phone' => '0911222333',
        'email' => 'selam@example.test',
        'status' => EmployeeStatus::Active->value,
    ]);

    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $this->employee->id,
        'organization_id' => $this->org->id,
        'organization_unit_id' => $unit->id,
        'position_id' => $position->id,
        'is_current' => true,
        'assignment_status' => AssignmentStatus::Active->value,
        'effective_from' => now()->toDateString(),
    ]);

    $this->employee->forceFill(['current_assignment_id' => $assignment->id])->save();

    $this->card = IdCard::query()->create([
        'employee_id' => $this->employee->id,
        'card_number' => 'PIC-CARD-0001',
        'status' => CardStatus::Active->value,
        'is_current' => true,
        'issued_at' => now()->subMonth(),
        'expires_at' => now()->addYear(),
    ]);

    app(CardQrPayloadService::class)->ensurePublicReference($this->card);
    $this->card->refresh();

    $this->uuid = $this->card->public_card_uuid;
});

/** Issue a code and return the plaintext, captured from the notification. */
function issueCode(string $uuid): string
{
    $captured = null;

    Notification::fake();
    app(PublicIdCheckerService::class)->sendOtp($uuid);

    Notification::assertSentOnDemand(
        PublicIdCheckOtpNotification::class,
        function (PublicIdCheckOtpNotification $notification) use (&$captured): bool {
            // The code is private on the notification; read it off the SMS body.
            preg_match('/\d{6}/', $notification->toSmsText(), $matches);
            $captured = $matches[0] ?? null;

            return true;
        },
    );

    return (string) $captured;
}

// ── Public access ──────────────────────────────────────────────────────

it('opens the checker page without logging in', function (): void {
    $this->get(route('id-checker.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Public/IdChecker'));
});

it('opens the checker page for a scanned card uuid', function (): void {
    $this->get(route('id-checker.show', $this->uuid))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/IdChecker')
            ->where('card.found', true)
            ->where('card.checkable', true)
        );
});

it('points the printed qr at the otp-gated checker', function (): void {
    $qr = app(CardQrPayloadService::class)->buildStableQrUrl($this->card);

    expect($qr)->toContain('/id-checker/'.$this->uuid);
});

it('encodes no personal data in the qr payload', function (): void {
    $qr = app(CardQrPayloadService::class)->buildStableQrUrl($this->card);

    foreach ([
        $this->employee->full_name,
        $this->employee->national_id,
        $this->employee->phone,
        $this->employee->email,
        $this->employee->employee_number,
        $this->org->name_en,
        $this->card->card_number,
    ] as $secret) {
        expect($qr)->not->toContain($secret);
    }
});

// ── The core rule: nothing before OTP ──────────────────────────────────

it('shows no employee information before a verified otp', function (): void {
    $body = $this->get(route('id-checker.show', $this->uuid))->getContent();

    // The scan page is the whole attack surface: if any of this appears here,
    // the QR is a public lookup of the card holder.
    foreach ([
        'Selam Tesfaye',
        'PIC-EMP-1',
        'Checker Organization',
        'Checker Unit',
        'Checker Position',
        '9876543210987654',
        '0911222333',
        'selam@example.test',
    ] as $secret) {
        expect($body)->not->toContain($secret);
    }
});

it('does not leak employee data when only an otp has been sent', function (): void {
    Notification::fake();

    $body = $this->postJson(route('id-checker.send-otp', $this->uuid))->getContent();

    expect($body)->not->toContain('Selam Tesfaye')
        ->and($body)->not->toContain('selam@example.test')
        ->and($body)->not->toContain('0911222333');
});

// ── OTP delivery ───────────────────────────────────────────────────────

it('sends the otp to the employee, not to the caller', function (): void {
    Notification::fake();

    $this->postJson(route('id-checker.send-otp', $this->uuid))->assertOk();

    Notification::assertSentOnDemand(PublicIdCheckOtpNotification::class);
});

it('stores only a hash of the otp', function (): void {
    $code = issueCode($this->uuid);
    $row = PublicIdCheckOtp::query()->where('card_uuid', $this->uuid)->sole();

    expect($row->otp_hash)->not->toBe($code)
        ->and(Hash::check($code, $row->otp_hash))->toBeTrue();
});

it('keeps employee data out of the notification body', function (): void {
    $captured = null;

    Notification::fake();
    app(PublicIdCheckerService::class)->sendOtp($this->uuid);

    Notification::assertSentOnDemand(
        PublicIdCheckOtpNotification::class,
        function (PublicIdCheckOtpNotification $notification) use (&$captured): bool {
            $captured = $notification->toSmsText();

            return true;
        },
    );

    // The message reaches a phone or inbox that others may see.
    expect($captured)->not->toContain('Selam Tesfaye')
        ->and($captured)->not->toContain('PIC-EMP-1')
        ->and($captured)->not->toContain('Checker Organization')
        ->and($captured)->not->toContain('PIC-CARD-0001');
});

it('invalidates a previous otp when a new one is issued', function (): void {
    $first = issueCode($this->uuid);
    issueCode($this->uuid);

    $this->postJson(route('id-checker.verify-otp', $this->uuid), ['otp' => $first])
        ->assertStatus(422);
});

// ── Verification ───────────────────────────────────────────────────────

it('shows safe employee info after a valid otp', function (): void {
    $code = issueCode($this->uuid);

    $response = $this->postJson(route('id-checker.verify-otp', $this->uuid), ['otp' => $code])
        ->assertOk()
        ->assertJsonPath('verified', true)
        ->assertJsonPath('employee.full_name', 'Selam Tesfaye')
        ->assertJsonPath('employee.employee_number', 'PIC-EMP-1')
        ->assertJsonPath('employee.organization', 'Checker Organization')
        ->assertJsonPath('employee.organization_unit', 'Checker Unit')
        ->assertJsonPath('employee.position', 'Checker Position')
        ->assertJsonPath('employee.card_status', 'active');

    expect($response->json('employee.issued_at'))->not->toBeNull()
        ->and($response->json('employee.expires_at'))->not->toBeNull()
        ->and($response->json('employee.verified_at'))->not->toBeNull();
});

it('never returns sensitive fields even after a valid otp', function (): void {
    $code = issueCode($this->uuid);

    $body = $this->postJson(route('id-checker.verify-otp', $this->uuid), ['otp' => $code])
        ->assertOk()
        ->getContent();

    foreach (['9876543210987654', '0911222333', 'selam@example.test', 'national_id', 'salary'] as $secret) {
        expect($body)->not->toContain($secret);
    }
});

it('rejects an incorrect otp', function (): void {
    issueCode($this->uuid);

    $this->postJson(route('id-checker.verify-otp', $this->uuid), ['otp' => '000000'])
        ->assertStatus(422)
        ->assertJsonPath('verified', false);
});

it('rejects an expired otp', function (): void {
    $code = issueCode($this->uuid);

    PublicIdCheckOtp::query()->where('card_uuid', $this->uuid)
        ->update(['expires_at' => now()->subMinute()]);

    $this->postJson(route('id-checker.verify-otp', $this->uuid), ['otp' => $code])
        ->assertStatus(422)
        ->assertJsonPath('message_key', 'idChecker.otpExpired');
});

it('locks the otp after the attempt ceiling', function (): void {
    $code = issueCode($this->uuid);
    $service = app(PublicIdCheckerService::class);

    // Driven through the service, not HTTP: the per-minute route throttle
    // would answer 429 first and hide the per-code ceiling being tested.
    foreach (range(1, PublicIdCheckOtp::MAX_ATTEMPTS) as $ignored) {
        expect($service->verifyOtp($this->uuid, '000000')['verified'])->toBeFalse();
    }

    // Even the correct code must now fail, or the ceiling means nothing.
    $result = $service->verifyOtp($this->uuid, $code);

    expect($result['verified'])->toBeFalse()
        ->and($result['reason'])->toBe('too_many_attempts');
});

it('rejects a malformed otp', function (): void {
    $this->postJson(route('id-checker.verify-otp', $this->uuid), ['otp' => 'abc'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('otp');
});

// ── Blocked cards ──────────────────────────────────────────────────────

it('blocks a revoked card from being checked', function (): void {
    $this->card->update(['status' => CardStatus::Revoked->value]);

    $this->get(route('id-checker.show', $this->uuid))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('card.checkable', false)->where('card.status_code', 'revoked'));

    Notification::fake();
    $this->postJson(route('id-checker.send-otp', $this->uuid))->assertStatus(422);
    Notification::assertNothingSent();
});

it('blocks an expired card from being checked', function (): void {
    $this->card->update(['expires_at' => now()->subDay()]);

    $this->get(route('id-checker.show', $this->uuid))
        ->assertInertia(fn (Assert $page) => $page->where('card.checkable', false)->where('card.status_code', 'expired'));
});

it('blocks a lost card from being checked', function (): void {
    $this->card->update(['status' => CardStatus::Lost->value]);

    $this->get(route('id-checker.show', $this->uuid))
        ->assertInertia(fn (Assert $page) => $page->where('card.checkable', false)->where('card.status_code', 'lost'));
});

it('answers identically for an unknown card and a revoked card', function (): void {
    $unknown = $this->postJson(route('id-checker.send-otp', '11111111-2222-3333-4444-555555555555'));

    $this->card->update(['status' => CardStatus::Revoked->value]);
    $revoked = $this->postJson(route('id-checker.send-otp', $this->uuid));

    // Enumeration guard: the two must be indistinguishable.
    expect($unknown->status())->toBe($revoked->status())
        ->and($unknown->json('message_key'))->toBe($revoked->json('message_key'));
});

it('does not error on a malformed card reference', function (): void {
    $this->get(route('id-checker.show', 'not-a-uuid'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('card.found', false));
});

// ── Audit ──────────────────────────────────────────────────────────────

it('audits a public scan', function (): void {
    $this->get(route('id-checker.show', $this->uuid));

    expect(AuditLog::query()->where('event_type', AuditEventType::PublicIdCheckScanned->value)->exists())->toBeTrue();
});

it('audits otp sent, verified and info displayed', function (): void {
    $code = issueCode($this->uuid);
    $this->postJson(route('id-checker.verify-otp', $this->uuid), ['otp' => $code])->assertOk();

    foreach ([
        AuditEventType::PublicIdCheckOtpSent,
        AuditEventType::PublicIdCheckOtpVerified,
        AuditEventType::PublicIdCheckInfoDisplayed,
    ] as $event) {
        expect(AuditLog::query()->where('event_type', $event->value)->exists())
            ->toBeTrue("missing audit entry for {$event->value}");
    }
});

it('audits a failed otp attempt', function (): void {
    issueCode($this->uuid);
    $this->postJson(route('id-checker.verify-otp', $this->uuid), ['otp' => '000000']);

    expect(AuditLog::query()->where('event_type', AuditEventType::PublicIdCheckOtpFailed->value)->exists())->toBeTrue();
});

it('audits a blocked check against an invalid card', function (): void {
    $this->get(route('id-checker.show', '11111111-2222-3333-4444-555555555555'));

    expect(AuditLog::query()->where('event_type', AuditEventType::PublicIdCheckBlocked->value)->exists())->toBeTrue();
});

it('records the anonymous checker without an actor', function (): void {
    $this->get(route('id-checker.show', $this->uuid));

    $log = AuditLog::query()->where('event_type', AuditEventType::PublicIdCheckScanned->value)->latest('created_at')->sole();

    expect($log->actor_user_id)->toBeNull()
        ->and($log->request_ip)->not->toBeNull();
});

// ── Rate limiting ──────────────────────────────────────────────────────

it('rate limits otp sending', function (): void {
    Notification::fake();

    // 3 per 10 minutes per card+IP.
    foreach (range(1, 3) as $ignored) {
        $this->postJson(route('id-checker.send-otp', $this->uuid))->assertOk();
    }

    $this->postJson(route('id-checker.send-otp', $this->uuid))->assertStatus(429);
});

it('rate limits otp verification', function (): void {
    issueCode($this->uuid);

    foreach (range(1, 5) as $ignored) {
        $this->postJson(route('id-checker.verify-otp', $this->uuid), ['otp' => '000000']);
    }

    $this->postJson(route('id-checker.verify-otp', $this->uuid), ['otp' => '000000'])->assertStatus(429);
});

// ── Mobile / front-end contract ────────────────────────────────────────

it('ships the scanner as a separate lazy chunk', function (): void {
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

    $page = collect($manifest)->first(fn (array $entry): bool => str_contains($entry['src'] ?? '', 'Public/IdChecker'));
    $scanner = collect($manifest)->first(fn (array $entry): bool => str_contains($entry['src'] ?? '', 'public/QrScanner'));

    // The QR library is ~335 kB and most visitors arrive having already
    // scanned with their phone camera, so it must not sit in the page chunk.
    expect($page)->not->toBeNull()
        ->and($scanner)->not->toBeNull()
        ->and($page['file'])->not->toBe($scanner['file']);
});

it('declares a responsive viewport', function (): void {
    $body = $this->get(route('id-checker.index'))->getContent();

    // Without this the page renders at desktop width and zooms out on a phone.
    expect($body)->toContain('name="viewport"')
        ->and($body)->toContain('width=device-width');
});

it('keeps the manual token fallback available when a card is not supplied', function (): void {
    $this->get(route('id-checker.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('cardUuid', null));
});

it('accepts a six digit code submitted from the mobile input', function (): void {
    $code = issueCode($this->uuid);

    $this->postJson(route('id-checker.verify-otp', $this->uuid), ['otp' => $code])
        ->assertOk()
        ->assertJsonPath('verified', true);
});

it('rejects a code shorter than six digits', function (): void {
    issueCode($this->uuid);

    $this->postJson(route('id-checker.verify-otp', $this->uuid), ['otp' => '12345'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('otp');
});

it('defines every interface string in both locales', function (): void {
    $extract = function (string $file): array {
        preg_match_all('/^\s{4}([a-zA-Z_][a-zA-Z0-9_]*):/m', (string) file_get_contents(base_path($file)), $matches);

        return $matches[1];
    };

    $en = $extract('resources/js/i18n/en/idChecker.ts');
    $am = $extract('resources/js/i18n/am/idChecker.ts');

    sort($en);
    sort($am);

    // A missing key renders as its raw path on a public government page.
    expect($en)->not->toBeEmpty()->and($am)->toBe($en);
});

// ── QR entry paths ─────────────────────────────────────────────────────

it('builds a qr payload that any external scanner can open', function (): void {
    $qr = app(CardQrPayloadService::class)->buildStableQrUrl($this->card);

    // A phone camera, Telegram or any QR app opens this as a plain URL, so it
    // must be absolute and must resolve to the checker route.
    expect($qr)->toStartWith(config('app.url'))
        ->and($qr)->toContain('/id-checker/'.$this->uuid);

    $path = parse_url($qr, PHP_URL_PATH);

    $this->get($path)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/IdChecker')
            ->where('cardUuid', $this->uuid)
            ->where('card.checkable', true)
        );
});

it('does not send an otp merely because the card page was opened', function (): void {
    Notification::fake();

    // The security rule: arriving from an external QR or a shared link must
    // never trigger a message, or anyone holding the URL could spam the
    // employee. Only the in-page scanner sets ?scanned=1.
    $this->get(route('id-checker.show', $this->uuid))->assertOk();

    Notification::assertNothingSent();
    expect(PublicIdCheckOtp::query()->count())->toBe(0);
});

it('does not flag auto-send for an external qr arrival', function (): void {
    $this->get(route('id-checker.show', $this->uuid))
        ->assertInertia(fn (Assert $page) => $page->where('autoSend', false));
});

it('flags auto-send only for an in-page scan', function (): void {
    $this->get(route('id-checker.show', $this->uuid).'?scanned=1')
        ->assertInertia(fn (Assert $page) => $page->where('autoSend', true));
});

it('never flags auto-send for a card that cannot be checked', function (): void {
    $this->card->update(['status' => CardStatus::Revoked->value]);

    // A revoked card must not trigger a message even from the scanner.
    $this->get(route('id-checker.show', $this->uuid).'?scanned=1')
        ->assertInertia(fn (Assert $page) => $page->where('autoSend', false));
});

it('still requires the post endpoint even when auto-send is flagged', function (): void {
    Notification::fake();

    // The flag is a UI hint; the GET itself must send nothing.
    $this->get(route('id-checker.show', $this->uuid).'?scanned=1')->assertOk();

    Notification::assertNothingSent();
    expect(PublicIdCheckOtp::query()->count())->toBe(0);
});

it('exposes otp sending over post only', function (): void {
    // A GET would let a crafted <img> tag trigger a message to the employee.
    $this->get('/id-checker/'.$this->uuid.'/send-otp')->assertStatus(405);
});

it('sends the otp only when explicitly requested after an external scan', function (): void {
    Notification::fake();

    $this->get(route('id-checker.show', $this->uuid))->assertOk();
    Notification::assertNothingSent();

    $this->postJson(route('id-checker.send-otp', $this->uuid))->assertOk();
    Notification::assertSentOnDemand(PublicIdCheckOtpNotification::class);
});

it('carries a token detected by the built-in scanner through to sending', function (): void {
    Notification::fake();

    // The in-page scanner navigates to /id-checker/{uuid}; from there the flow
    // is identical to an external scan.
    $this->get(route('id-checker.show', $this->uuid))
        ->assertInertia(fn (Assert $page) => $page->where('cardUuid', $this->uuid));

    $this->postJson(route('id-checker.send-otp', $this->uuid))
        ->assertOk()
        ->assertJsonPath('sent', true);
});

// ── Legacy QR URLs ─────────────────────────────────────────────────────

it('redirects the legacy verify url to the otp-gated checker', function (): void {
    // Cards printed before the Global ID Checker carry /verify/card/{uuid} in
    // their QR. A physical card cannot be patched, so that URL must keep
    // working — but it must no longer show anything without consent.
    $this->get('/verify/card/'.$this->uuid)
        ->assertRedirect(route('id-checker.show', $this->uuid));
});

it('shows no employee data through the legacy verify url', function (): void {
    $body = $this->followingRedirects()
        ->get('/verify/card/'.$this->uuid)
        ->assertOk()
        ->getContent();

    foreach (['Selam Tesfaye', 'PIC-EMP-1', 'Checker Organization', 'PIC-CARD-0001'] as $secret) {
        expect($body)->not->toContain($secret);
    }
});

it('does not auto-send when arriving from a legacy qr', function (): void {
    Notification::fake();

    $this->followingRedirects()->get('/verify/card/'.$this->uuid)->assertOk();

    Notification::assertNothingSent();
});
