<?php

declare(strict_types=1);

use App\Services\Cafeteria\CafeteriaQrScanService;
use App\Services\IdCards\CardQrPayloadService;
use App\Services\IdCards\QrPayloadParser;
use App\Services\Transport\TransportQrScanService;

require_once __DIR__.'/IdCardFrontFieldsTest.php';

it('resolves the card uuid from every payload a scanner can deliver', function (string $build): void {
    $card = frontCard();
    $uuid = $card->public_card_uuid;
    $value = str_replace('{uuid}', $uuid, $build);

    expect(app(QrPayloadParser::class)->parse($value))->toBe(strtolower($uuid));
})->with([
    // The payload actually printed on the card.
    'printed id-checker url' => ['http://127.0.0.1:8000/id-checker/{uuid}'],
    // The short form a deployment can configure.
    'short c url' => ['https://id.gov.et/c/{uuid}'],
    // The older public page, still in the field on issued cards.
    'legacy verify url' => ['https://verify.example.et/verify/card/{uuid}'],
    // An operator typing or pasting the reference by hand.
    'raw uuid' => ['{uuid}'],
    'whitespace padded' => ['   {uuid}   '],
    // Scanners vary in how they report hex and trailing separators.
    'trailing slash' => ['https://id.gov.et/c/{uuid}/'],
    'with query string' => ['http://127.0.0.1:8000/id-checker/{uuid}?scanned=1'],
    'with fragment' => ['http://127.0.0.1:8000/id-checker/{uuid}#top'],
]);

it('resolves an uppercase uuid to its stored lowercase form', function (): void {
    $card = frontCard();

    expect(app(QrPayloadParser::class)->parse(strtoupper($card->public_card_uuid)))
        ->toBe(strtolower($card->public_card_uuid));
});

it('rejects a value that is not a card reference', function (?string $value): void {
    expect(app(QrPayloadParser::class)->parse($value))->toBeNull();
})->with([
    'empty' => [''],
    'null' => [null],
    'junk text' => ['not-a-card'],
    'bare number' => ['12345'],
    // A UUID outside the card path must not be mistaken for the reference,
    // or a crafted link could point a terminal at an unrelated identifier.
    'uuid in query only' => ['https://evil.example/?card=11111111-1111-4111-8111-111111111111'],
    'uuid in fragment only' => ['https://evil.example/#11111111-1111-4111-8111-111111111111'],
    'unknown path segment' => ['https://evil.example/admin/11111111-1111-4111-8111-111111111111'],
    'malformed uuid' => ['http://127.0.0.1:8000/id-checker/not-a-uuid'],
]);

it('resolves the payload the card itself prints', function (): void {
    $card = frontCard();
    $printed = app(CardQrPayloadService::class)->buildStableQrUrl($card);

    // The regression that prompted this: the parser knew /verify/card but not
    // the /id-checker URL the cards actually carry, so every terminal scan of
    // a real card failed to resolve.
    expect(app(CardQrPayloadService::class)->resolvePublicUuidFromScanValue($printed))
        ->toBe($card->public_card_uuid);
});

it('routes every terminal through the same parser', function (string $service): void {
    $card = frontCard();
    $printed = app(CardQrPayloadService::class)->buildStableQrUrl($card);

    $resolver = new ReflectionMethod($service, 'resolveCard');
    $resolver->setAccessible(true);

    expect($resolver->invoke(app($service), $printed)?->id)->toBe($card->id);
})->with([
    'cafeteria' => [CafeteriaQrScanService::class],
    'transport' => [TransportQrScanService::class],
]);
