<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeServiceFeedback;
use App\Models\IdCard;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Services\ServiceFeedback\EmployeeFeedbackTokenService;
use App\Services\ServiceFeedback\OrganizationFeedbackQrService;
use App\Services\ServiceFeedback\PublicFeedbackFormToken;
use App\Support\EmployeePhotoStorage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PublicFeedbackScenario;

/*
 * The employee feedback QR (the existing token on the card back) confirms the
 * scanned person by photo and full name while the employee is active — the ID
 * card's status plays no part. Nothing else about the employee reaches the
 * page, the photo comes only through a short-lived signed link bound to the
 * scanned token, and the browser can never change whom a submission is about.
 */

/** A real 1×1 PNG of one colour, so storage, MIME sniffing and image decoding all see a genuine image. */
function efiPng(int $red, int $green, int $blue): string
{
    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return "\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0))
        .$chunk('IDAT', (string) gzcompress("\x00".chr($red).chr($green).chr($blue)))
        .$chunk('IEND', '');
}

/** Store a photo the way EmployeePhotoStorage does and point the employee at it. */
function efiGivePhoto(PublicFeedbackScenario $scenario, string $bytes): string
{
    $path = EmployeePhotoStorage::PREFIX.Str::uuid().'.png';
    Storage::disk(EmployeePhotoStorage::PRIVATE_DISK)->put($path, $bytes);
    $scenario->employee->forceFill(['photo_path' => $path])->save();

    return $path;
}

/** @return array<string, mixed> the page's Inertia props */
function efiProps(TestResponse $response): array
{
    $props = null;
    $response->assertInertia(function (Assert $page) use (&$props): void {
        $props = $page->toArray()['props'];
    });

    return $props;
}

function efiPhotoUrl(TestResponse $response): ?string
{
    return efiProps($response)['target']['photo_url'] ?? null;
}

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    $this->withHeader('X-Locale', 'en');

    $this->a = PublicFeedbackScenario::employeeTarget('EFA');
    $this->b = PublicFeedbackScenario::employeeTarget('EFB');
    $this->b->employee->forceFill(['full_name' => 'Dawit Bekele'])->save();

    $this->photoA = efiGivePhoto($this->a, efiPng(200, 30, 30));
    $this->photoB = efiGivePhoto($this->b, efiPng(30, 30, 200));
    $this->pageA = '/service-feedback/'.$this->a->token->token;

    // Employee A also holds an ID card, so tests can show its state does not matter.
    $this->cardA = PublicFeedbackScenario::activeCard($this->a->employee, 'EFA-CARD-1');
});

// ── 1–3: what a valid scan shows ─────────────────────────────────────────────

it('shows the full name, the current job designation and a photo link, and nothing else, for an active employee', function (): void {
    $response = $this->get($this->pageA)->assertOk();
    $target = efiProps($response)['target'];

    expect(array_keys($target))->toBe(['target_type', 'display_name', 'position_title', 'photo_url'])
        ->and($target['target_type'])->toBe('employee')
        ->and($target['display_name'])->toBe('Hanna Girma')
        ->and($target['position_title'])->toBe('Licensing Officer')
        ->and($target['photo_url'])->toStartWith('/service-feedback/'.$this->a->token->token.'/photo?');
});

it('serves the scanned employee\'s own photo through the link', function (): void {
    $response = $this->get(efiPhotoUrl($this->get($this->pageA)))->assertOk();

    $served = EmployeePhotoStorage::thumbnail($this->photoA);
    expect($response->streamedContent())->toBe(Storage::disk($served['disk'])->get($served['path']))
        ->and($response->headers->get('Content-Type'))->toBeIn(['image/png', 'image/webp', 'image/jpeg'])
        ->and($response->headers->get('Content-Disposition'))->not->toContain('employee-photos')
        ->and($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=300');
});

it('keeps the name and the three choices when the employee has no photo', function (): void {
    $this->a->employee->forceFill(['photo_path' => null])->save();

    $props = efiProps($this->get($this->pageA)->assertOk());

    expect($props['available'])->toBeTrue()
        ->and($props['target']['display_name'])->toBe('Hanna Girma')
        ->and($props['target']['photo_url'])->toBeNull()
        ->and($props['enabledTypes'])->toBe(['feedback', 'suggestion', 'complaint']);
});

it('answers 404 for a recorded photo that is missing from storage, so the page falls back to its avatar', function (): void {
    Storage::disk(EmployeePhotoStorage::PRIVATE_DISK)->delete($this->photoA);

    $this->get(efiPhotoUrl($this->get($this->pageA)))->assertNotFound();
    expect(efiProps($this->get($this->pageA))['available'])->toBeTrue();
});

it('serves a small derived copy made once, leaving the stored photo untouched, and removes it with the photo', function (): void {
    $image = imagecreatetruecolor(800, 1000);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 120, 90, 60));
    ob_start();
    imagejpeg($image, null, 90);
    $original = (string) ob_get_clean();
    $path = EmployeePhotoStorage::PREFIX.Str::uuid().'.jpg';
    Storage::disk('local')->put($path, $original);

    $first = EmployeePhotoStorage::thumbnail($path);
    $second = EmployeePhotoStorage::thumbnail($path);
    [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($first['path']));

    expect($first['path'])->toStartWith(EmployeePhotoStorage::THUMBNAIL_DIRECTORY.'/')
        ->and($second)->toBe($first)
        ->and(max($width, $height))->toBeLessThanOrEqual(EmployeePhotoStorage::THUMBNAIL_SIZE)
        ->and(Storage::disk('local')->get($path))->toBe($original);

    EmployeePhotoStorage::delete($path);
    expect(Storage::disk('local')->exists($first['path']))->toBeFalse();
})->skip(! function_exists('imagecreatetruecolor'), 'GD is not installed in this PHP.');

// ── The feedback QR follows the employee, not the ID card ───────────────────

it('keeps the page, name, photo and choices whatever the state of the ID card', function (string $case): void {
    $card = $this->cardA;
    match ($case) {
        'revoked' => $card->forceFill(['status' => CardStatus::Revoked, 'revoked_at' => now()])->save(),
        'suspended' => $card->forceFill(['status' => CardStatus::Suspended])->save(),
        'lost' => $card->forceFill(['status' => CardStatus::Lost])->save(),
        'pending print' => $card->forceFill(['status' => CardStatus::PendingPrint])->save(),
        'expired' => $card->forceFill(['expires_at' => now()->subDay()])->save(),
        'qr revoked' => $card->forceFill(['qr_status' => 'revoked'])->save(),
        'no card' => $card->delete(),
    };

    $props = efiProps($this->get($this->pageA)->assertOk());

    expect($props['available'])->toBeTrue()
        ->and($props['target']['display_name'])->toBe('Hanna Girma')
        ->and($props['enabledTypes'])->toBe(['feedback', 'suggestion', 'complaint']);
    $this->get($props['target']['photo_url'])->assertOk();

    $this->post($this->pageA.'/suggestion', pfComplete('suggestion', ['message' => 'Card state must not matter.']))->assertRedirect($this->pageA.'/suggestion');
    expect(EmployeeServiceFeedback::query()->where('employee_id', $this->a->employee->id)->count())->toBe(1);
})->with(['revoked', 'suspended', 'lost', 'pending print', 'expired', 'qr revoked', 'no card']);

// ── 4–6, 41: unknown tokens and inactive employees reveal no one ─────────────

it('shows no name or photo for an unknown token', function (): void {
    $response = $this->get('/service-feedback/'.str_repeat('f', 64))->assertOk();
    $props = efiProps($response);

    expect($props['available'])->toBeFalse()
        ->and($props['target'])->toBeNull()
        ->and($response->getContent())->not->toContain('Hanna')->not->toContain('Dawit');
});

it('shows the same unavailable page, with no name or photo, once the employee is not active or the token is off', function (string $case): void {
    match ($case) {
        'token revoked' => app(EmployeeFeedbackTokenService::class)->revoke($this->a->token),
        'token suspended' => app(EmployeeFeedbackTokenService::class)->suspend($this->a->token),
        default => $this->a->employee->forceFill(['status' => EmployeeStatus::from($case)])->save(),
    };

    $response = $this->get($this->pageA)->assertOk();
    $props = efiProps($response);

    expect($props['available'])->toBeFalse()
        ->and($props['target'])->toBeNull()
        ->and($response->getContent())->not->toContain('Hanna')->not->toContain('/photo?');
})->with(['draft', 'suspended', 'transferred', 'retired', 'terminated', 'deceased', 'token revoked', 'token suspended']);

it('stops serving the photo the moment the employee leaves service, even through a link still within its life', function (): void {
    $link = efiPhotoUrl($this->get($this->pageA));
    $this->get($link)->assertOk();

    $this->a->employee->forceFill(['status' => EmployeeStatus::Terminated])->save();

    $this->get($link)->assertNotFound();
});

// ── 7, 11, 12: photo links cannot be bent, guessed or kept ──────────────────

it('never lets employee A\'s link fetch employee B\'s photo', function (): void {
    $linkA = efiPhotoUrl($this->get($this->pageA));
    $bodyA = $this->get($linkA)->assertOk()->streamedContent();

    $servedB = EmployeePhotoStorage::thumbnail($this->photoB);
    expect($bodyA)->not->toBe(Storage::disk($servedB['disk'])->get($servedB['path']))
        ->and($bodyA)->not->toBe(Storage::disk('local')->get($this->photoB));

    // Swapping B's token into A's signed link breaks the signature.
    $this->get(str_replace($this->a->token->token, $this->b->token->token, $linkA))->assertForbidden();
});

it('refuses an unsigned, altered or expired photo link', function (): void {
    $link = efiPhotoUrl($this->get($this->pageA));

    $this->get('/service-feedback/'.$this->a->token->token.'/photo')->assertForbidden();
    $this->get(preg_replace('/v=[0-9a-f]+/', 'v=0000000000000000', $link))->assertForbidden();
    $this->get(preg_replace('/expires=\d+/', 'expires='.now()->addYear()->getTimestamp(), $link))->assertForbidden();

    $this->travel(16)->minutes();
    $this->get($link)->assertForbidden();
});

it('keeps the storage path, file name and employee id out of the photo link', function (): void {
    $link = (string) efiPhotoUrl($this->get($this->pageA));

    expect($link)->not->toContain('employee-photos')
        ->and($link)->not->toContain(pathinfo($this->photoA, PATHINFO_FILENAME))
        ->and($link)->not->toContain($this->a->employee->id)
        ->and($link)->not->toContain('storage');
});

it('gives every screen of one visit the same photo link, so the photo downloads once', function (): void {
    $this->travelTo(now()->startOfHour()->addMinutes(1));

    $landing = efiPhotoUrl($this->get($this->pageA));
    $this->travel(2)->minutes();
    $form = efiPhotoUrl($this->get($this->pageA.'/complaint'));

    expect($form)->toBe($landing);
});

// ── 8–10, 15, 19–20: the browser never chooses the target ────────────────────

it('ignores employee B\'s ids, a forged target type and a forged assignment in a submission on A\'s code', function (): void {
    $this->post($this->pageA.'/suggestion', pfComplete('suggestion', [
        'message' => 'Please open the second window earlier.',
        'employee_id' => $this->b->employee->id,
        'target_employee_id' => $this->b->employee->id,
        'employee_assignment_id' => $this->b->assignment->id,
        'target_type' => 'organization',
        'organization_target_id' => $this->b->organization->id,
    ]))->assertRedirect();

    $row = EmployeeServiceFeedback::query()->sole();
    expect($row->employee_id)->toBe($this->a->employee->id)
        ->and($row->employee_assignment_id)->toBe($this->a->assignment->id)
        ->and($row->target_type->value)->toBe('employee')
        ->and($row->organization_target_id)->toBeNull();
});

it('does not accept a raw employee id, card id or card uuid in place of the token', function (): void {
    foreach ([$this->a->employee->id, $this->cardA->id, $this->cardA->public_card_uuid, $this->a->employee->employee_number] as $guess) {
        expect(efiProps($this->get('/service-feedback/'.$guess))['available'])->toBeFalse();
        $this->get('/service-feedback/'.$guess.'/photo')->assertForbidden();
    }
});

it('checks the employee again at submission: leaving service while the form was open blocks the send', function (): void {
    $formToken = efiProps($this->get($this->pageA.'/complaint'))['formToken'];
    expect($formToken)->not->toBeNull();

    $this->a->employee->forceFill(['status' => EmployeeStatus::Terminated])->save();

    $this->post($this->pageA.'/complaint', pfComplete('complaint', ['form_token' => $formToken, 'message' => 'Rude treatment at the desk.']))
        ->assertRedirect($this->pageA);

    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
});

it('does not block a submission when the ID card is revoked while the form is open', function (): void {
    $formToken = efiProps($this->get($this->pageA.'/complaint'))['formToken'];

    $this->cardA->forceFill(['status' => CardStatus::Revoked, 'revoked_at' => now()])->save();

    $this->post($this->pageA.'/complaint', pfComplete('complaint', ['form_token' => $formToken, 'message' => 'Rude treatment at the desk.']))
        ->assertRedirect($this->pageA.'/complaint');

    expect(EmployeeServiceFeedback::query()->sole()->employee_id)->toBe($this->a->employee->id);
});

it('receipts a complaint against the scanned employee by name, keeping the masked snapshots', function (): void {
    $formToken = app(PublicFeedbackFormToken::class)->issue();

    $this->post($this->pageA.'/complaint', pfComplete('complaint', ['form_token' => $formToken, 'message' => 'Asked for an unofficial fee.']))
        ->assertRedirect($this->pageA.'/complaint')
        ->assertSessionHas('feedback_receipt.target_name', 'Hanna Girma');

    $row = EmployeeServiceFeedback::query()->sole();
    expect($row->employee_id)->toBe($this->a->employee->id)
        // Submission snapshots are unchanged by this feature: role label and number, never the photo.
        ->and($row->target_name_snapshot)->toBe('Licensing Officer')
        ->and($row->target_code_snapshot)->toBe('EFA-EMP-1');
});

// ── 13, 47: privacy of the whole payload ─────────────────────────────────────

it('sends no protected personal data, HR data or internal ids anywhere in the page', function (): void {
    $this->a->employee->forceFill([
        'national_id' => '9988776655443322',
        'phone' => '0911223344',
        'email' => 'hanna.private@example.test',
        'date_of_birth' => '1990-04-17',
        'address' => 'Bole Sub-City House 1234',
        'emergency_contact_name' => 'Selam Emergency',
        'emergency_contact_phone' => '0955667788',
        'gender' => 'female',
    ])->save();

    $response = $this->get($this->pageA)->assertOk();
    $body = (string) $response->getContent();

    foreach ([
        '9988776655443322', '0911223344', 'hanna.private@example.test', '1990-04-17', 'Bole Sub-City', 'Selam Emergency', '0955667788',
        'EFA-EMP-1', 'EFA-CARD-1',
        $this->a->employee->id, $this->a->assignment->id, $this->cardA->id, $this->cardA->public_card_uuid, $this->a->token->id,
        $this->a->position->id, $this->a->position->job_position_code,
        $this->photoA, pathinfo($this->photoA, PATHINFO_FILENAME),
        // The job designation (position title) is public by design; the unit and institution are not.
        'Licensing Unit', 'Feedback Organization',
    ] as $private) {
        expect($body)->not->toContain($private);
    }

    foreach (['national_id', 'date_of_birth', 'salary', 'bank', 'discipline', 'grievance', 'performance', 'assignment_history', 'employee_number'] as $field) {
        expect($body)->not->toContain('"'.$field.'"');
    }
});

// ── 14, 23–26: search engines, referrers and caches ──────────────────────────

it('marks every tokenized page and photo as unindexable, referrer-free and uncached by shared caches', function (): void {
    $link = efiPhotoUrl($this->get($this->pageA));

    foreach ([$this->pageA, $this->pageA.'/feedback', '/service-feedback/'.str_repeat('e', 64)] as $page) {
        $this->get($page)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    $photo = $this->get($link)->assertOk();
    $photo->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertHeader('Referrer-Policy', 'no-referrer');
    expect($photo->headers->get('Cache-Control'))->not->toContain('public');
});

it('leaves the referrer policy of ordinary pages as it was', function (): void {
    $this->get('/id-checker')->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

// ── 16–19: the QR does not move ──────────────────────────────────────────────

it('keeps the same token and card reference when the photo changes, and shows the new photo', function (): void {
    $token = $this->a->token->token;
    $cardUuid = $this->cardA->public_card_uuid;
    $before = efiPhotoUrl($this->get($this->pageA));

    $newPhoto = efiGivePhoto($this->a, efiPng(30, 200, 30));
    $after = efiPhotoUrl($this->get($this->pageA));

    expect($this->a->token->refresh()->token)->toBe($token)
        ->and($this->cardA->refresh()->public_card_uuid)->toBe($cardUuid)
        ->and($after)->not->toBe($before);

    $served = EmployeePhotoStorage::thumbnail($newPhoto);
    expect($this->get($after)->assertOk()->streamedContent())->toBe(Storage::disk($served['disk'])->get($served['path']));
});

it('keeps the same token after a name correction, shows the corrected name and leaves earlier snapshots alone', function (): void {
    $this->post($this->pageA.'/suggestion', pfComplete('suggestion', ['message' => 'Before the correction.']))->assertRedirect();
    $earlier = EmployeeServiceFeedback::query()->sole();
    $token = $this->a->token->token;

    $this->a->employee->forceFill(['full_name' => 'Hana Girma Tadesse'])->save();

    expect(efiProps($this->get($this->pageA))['target']['display_name'])->toBe('Hana Girma Tadesse')
        ->and($this->a->token->refresh()->token)->toBe($token)
        ->and($earlier->refresh()->target_name_snapshot)->toBe('Licensing Officer')
        ->and($earlier->target_code_snapshot)->toBe('EFA-EMP-1');
});

it('keeps the same token through a transfer and a card reprint', function (): void {
    $token = $this->a->token->token;

    // Transfer: a new current assignment in another unit and position.
    $unit = OrganizationUnit::query()->create(['organization_id' => $this->a->organization->id, 'code' => 'EFA-U2', 'name_en' => 'Permits Unit', 'unit_type' => 'department', 'status' => 'active']);
    $position = Position::query()->create(['organization_id' => $this->a->organization->id, 'organization_unit_id' => $unit->id, 'job_position_code' => 'EFA-P2', 'title_en' => 'Permits Officer', 'is_active' => true]);
    $this->a->assignment->forceFill(['is_current' => false, 'effective_to' => now()->toDateString()])->save();
    $transfer = EmployeeAssignment::query()->create([
        'employee_id' => $this->a->employee->id, 'organization_id' => $this->a->organization->id, 'organization_unit_id' => $unit->id,
        'position_id' => $position->id, 'is_current' => true, 'assignment_status' => AssignmentStatus::Active->value, 'effective_from' => now()->addDay()->toDateString(),
    ]);
    $this->a->employee->forceFill(['current_assignment_id' => $transfer->id])->save();

    // Reprint: the old card is replaced by a new active one.
    $this->cardA->forceFill(['status' => CardStatus::Replaced, 'is_current' => false])->save();
    $reprint = PublicFeedbackScenario::activeCard($this->a->employee, 'EFA-CARD-2');

    expect(efiProps($this->get($this->pageA))['available'])->toBeTrue()
        ->and($this->a->token->refresh()->token)->toBe($token)
        ->and(IdCard::query()->whereKey($reprint->id)->value('status'))->toBe(CardStatus::Active);
});

// ── 20, 34: the OTP-gated ID Checker is untouched ────────────────────────────

it('keeps the ID Checker free of the name and photo before an OTP is verified', function (): void {
    $response = $this->get('/id-checker/'.$this->cardA->public_card_uuid)->assertOk();

    $response->assertInertia(fn (Assert $page) => $page->component('Public/IdChecker')->missing('employee'));
    expect((string) $response->getContent())->not->toContain('Hanna')->not->toContain('/photo?');
});

// ── 37: both languages ───────────────────────────────────────────────────────

it('shows the card\'s Amharic name in Amharic and its English name in English', function (): void {
    $this->a->employee->forceFill(['full_name' => 'ሃና ግርማ', 'name_en' => 'Hanna Girma'])->save();

    expect(efiProps($this->withHeader('X-Locale', 'am')->get($this->pageA))['target']['display_name'])->toBe('ሃና ግርማ')
        ->and(efiProps($this->withHeader('X-Locale', 'en')->get($this->pageA))['target']['display_name'])->toBe('Hanna Girma');
});

// ── 50: organization QR keeps its own identity ───────────────────────────────

it('leaves the organization QR identity unchanged', function (): void {
    $code = app(OrganizationFeedbackQrService::class)->ensureActive($this->a->organization);

    $target = efiProps($this->get('/feedback/'.$code->public_token)->assertOk())['target'];

    expect($target['target_type'])->toBe('organization')
        ->and($target['display_name'])->toBe('Feedback Organization')
        ->and($target)->not->toHaveKey('photo_url');
});
