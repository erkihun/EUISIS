<?php

declare(strict_types=1);

use App\Services\IdCards\CardQrPayloadService;
use App\Services\IdCards\IdCardQrCodeRenderer;
use App\Services\IdCards\IdCardQrPayloadTooLongException;
use App\Services\IdCards\IdCardRenderDataFactory;
use App\Services\IdCards\IdCardSvgRenderer;
use App\Services\IdCards\QrCodeVersionResolver;
use App\Services\IdCards\QrPayloadContainsPiiException;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Data\QRMatrix;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/IdCardFrontFieldsTest.php';

/** Builds the matrix at whatever symbol the resolver picks for a payload. */
function idCardQrMatrix(string $url): QRMatrix
{
    $symbol = app(QrCodeVersionResolver::class)->resolve($url);

    $options = new QROptions;
    $options->version = $symbol['version'];
    $options->eccLevel = EccLevel::Q;

    return (new QRCode($options))->getQRMatrix($url);
}

it('defaults the card QR to model 2, a version 6 floor and error correction Q', function (): void {
    expect(config('id_cards.qr.model'))->toBe(2)
        ->and(config('id_cards.qr.minimum_version'))->toBe(6)
        ->and(config('id_cards.qr.default_error_correction'))->toBe('Q')
        ->and(config('id_cards.qr.auto_upgrade_version'))->toBeTrue();
});

it('generates the card QR at version 6 with error correction Q', function (): void {
    $card = frontCard();
    $url = app(CardQrPayloadService::class)->buildStableQrUrl($card);

    $matrix = idCardQrMatrix($url);

    // Read as properties: the getters are deprecated for removal in v7.
    expect($matrix->version->getVersionNumber())->toBe(6)
        ->and((string) $matrix->eccLevel)->toBe('Q');
});

it('renders the card QR as a version 6 symbol', function (): void {
    $card = frontCard();
    $url = app(CardQrPayloadService::class)->buildStableQrUrl($card);

    $svg = app(IdCardQrCodeRenderer::class)->idCardInlineSvgContent($url);

    // Version 6 is 41x41 modules; the viewBox adds the 4-module quiet zone
    // on each side, so a correct symbol measures 49 units square.
    expect($svg)->toContain('viewBox="0 0 49 49"');
});

it('keeps the QR payload to the public checker URL only', function (): void {
    $card = frontCard();

    $url = app(CardQrPayloadService::class)->buildStableQrUrl($card);

    expect($url)->toBe(rtrim((string) config('app.url'), '/').'/id-checker/'.$card->public_card_uuid);
});

it('never puts employee data in the QR payload', function (): void {
    $card = frontCard();
    $employee = $card->employee;

    $url = app(CardQrPayloadService::class)->buildStableQrUrl($card);

    foreach ([$employee->full_name, $employee->employee_number, $employee->phone, $card->card_number] as $pii) {
        expect($url)->not->toContain((string) $pii);
    }
});

it('grows the symbol for a payload past the floor rather than failing', function (): void {
    // Beyond version 6-Q but inside the ceiling: this is the auto-upgrade
    // path, so it must render rather than error.
    $long = 'https://verification.example.gov.et/id-checker/'.str_repeat('x', 120);

    expect(app(IdCardQrCodeRenderer::class)->idCardSvgDataUri($long))->toStartWith('data:image/svg+xml;base64,');
});

it('fails with a clear error once the payload passes the ceiling', function (): void {
    // No allowed version holds this, and error correction is never lowered
    // to make it fit.
    $huge = 'https://verification.example.gov.et/id-checker/'.str_repeat('x', 2000);

    expect(fn () => app(IdCardQrCodeRenderer::class)->idCardSvgDataUri($huge))
        ->toThrow(IdCardQrPayloadTooLongException::class, 'QR_PAYLOAD_TOO_LONG');
});

it('leaves the feedback QR free to pick its own symbol version', function (): void {
    // The feedback QR shares this renderer but is not bound by the card
    // specification, so a payload too long for the card must still render.
    $long = 'https://verification.example.gov.et/id-checker/'.str_repeat('x', 2000);

    expect(app(IdCardQrCodeRenderer::class)->asInlineSvgContent($long))->not->toBe('');
});

it('does not change the card identity when the QR symbol is generated', function (): void {
    $card = frontCard();
    $before = $card->getRawOriginal();

    app(IdCardSvgRenderer::class)->renderBack(app(IdCardRenderDataFactory::class)->make($card));

    expect($card->refresh()->getRawOriginal())->toBe($before);
});

it('still renders both card faces with the pinned QR', function (string $orientation): void {
    $card = frontCard();
    $data = app(IdCardRenderDataFactory::class)->make($card, $orientation);

    expect(app(IdCardSvgRenderer::class)->renderFront($data))->toContain('<svg')
        ->and(app(IdCardSvgRenderer::class)->renderBack($data))->toContain('<svg');
})->with(['landscape', 'portrait']);

it('upgrades to a larger symbol when the payload outgrows the floor', function (): void {
    $resolver = app(QrCodeVersionResolver::class);

    // Comfortably inside version 6-Q (608 bits).
    $short = 'https://id.gov.et/c/'.str_repeat('a', 26);
    // Past version 6-Q, but well inside the version 12 ceiling.
    $long = 'https://id.gov.et/c/'.str_repeat('a', 100);

    $shortSymbol = $resolver->resolve($short);
    $longSymbol = $resolver->resolve($long);

    expect($shortSymbol['version'])->toBe(6)
        ->and($longSymbol['version'])->toBeGreaterThan(6)
        // The symbol grows; the recovery level never drops to make room.
        ->and($shortSymbol['ecc'])->toBe('Q')
        ->and($longSymbol['ecc'])->toBe('Q');
});

it('picks the smallest version that holds the payload', function (): void {
    $resolver = app(QrCodeVersionResolver::class);
    $payload = 'https://id.gov.et/c/'.str_repeat('a', 100);

    $symbol = $resolver->resolve($payload);

    // Nothing smaller would have fitted, or the resolver over-sized the code.
    expect($symbol['required_bits'])->toBeLessThanOrEqual($symbol['capacity_bits']);

    config()->set('id_cards.qr.maximum_version', $symbol['version'] - 1);
    expect(fn () => $resolver->resolve($payload))->toThrow(IdCardQrPayloadTooLongException::class);
});

it('reports QR_PAYLOAD_TOO_LONG beyond the configured ceiling', function (): void {
    $payload = 'https://id.gov.et/c/'.str_repeat('a', 2000);

    expect(fn () => app(QrCodeVersionResolver::class)->resolve($payload))
        ->toThrow(IdCardQrPayloadTooLongException::class, 'QR_PAYLOAD_TOO_LONG');
});

it('refuses a payload carrying employee data', function (string $payload): void {
    expect(fn () => app(QrCodeVersionResolver::class)->resolve($payload))
        ->toThrow(QrPayloadContainsPiiException::class);
})->with([
    'query parameter' => ['https://id.gov.et/c/abc?employee_number=EMP-1'],
    'json object' => ['{"name":"Yared","phone":"+251911000000"}'],
    'named field' => ['https://id.gov.et/c/abc/national_id/123'],
    'position data' => ['https://id.gov.et/c/abc/position/director'],
]);

it('keeps the card identity stable when the symbol version changes', function (): void {
    $card = frontCard();
    $before = $card->getRawOriginal();
    $uuid = $card->public_card_uuid;
    $number = $card->card_number;

    // Force a longer payload, which resolves to a different symbol version.
    config()->set('id_cards.qr.base_url', 'https://a-much-longer-verification-domain.example.gov.et/id-checker');
    app(IdCardSvgRenderer::class)->renderBack(app(IdCardRenderDataFactory::class)->make($card->fresh()));

    expect($card->refresh()->getRawOriginal())->toBe($before)
        ->and($card->public_card_uuid)->toBe($uuid)
        ->and($card->card_number)->toBe($number);
});

it('indexes the public card uuid uniquely for billion-scale lookup', function (): void {
    // A scan resolves by public_card_uuid, so it must be indexed and unique.
    expect(Schema::hasColumn('id_cards', 'public_card_uuid'))->toBeTrue();

    $card = frontCard();

    // v4 UUID: 122 random bits, not derived from any sequential key.
    expect($card->public_card_uuid)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i')
        ->and($card->public_card_uuid)->not->toContain((string) $card->id)
        ->and($card->public_card_uuid)->not->toContain((string) $card->employee_id);
});

it('records the resolved symbol as card metadata without touching identity', function (): void {
    $card = frontCard();
    $url = app(CardQrPayloadService::class)->buildStableQrUrl($card);

    $symbol = app(IdCardQrCodeRenderer::class)->resolveSymbol($url);

    expect($symbol['model'])->toBe(2)
        ->and($symbol['ecc'])->toBe('Q')
        ->and($symbol['version'])->toBeGreaterThanOrEqual(6);
});
