<?php

declare(strict_types=1);

use App\Models\IdCard;
use App\Models\User;
use App\Services\IdCards\CardQrPayloadService;
use App\Services\IdCards\IdCardRenderDataFactory;
use App\Services\IdCards\IdCardSvgRenderer;

require_once __DIR__.'/IdCardFrontFieldsTest.php';

/**
 * The QR payload version is metadata about which payload shape a card was
 * issued under. It must never disturb the card's identity: a printed QR cannot
 * change, so the reference, token and card number stay fixed for life.
 */
function qrService(): CardQrPayloadService
{
    return app(CardQrPayloadService::class);
}

it('stamps a newly issued card with the configured payload version', function (): void {
    config()->set('security.id_card_qr_payload_version', 2);
    $card = frontCard();
    $card->forceFill(['public_card_uuid' => null, 'qr_payload_version' => null])->save();

    qrService()->ensurePublicReference($card);

    expect($card->refresh()->qr_payload_version)->toBe(2)
        ->and($card->public_card_uuid)->not->toBeNull();
});

it('leaves an already issued card on the version it was printed with', function (): void {
    $card = frontCard();
    qrService()->ensurePublicReference($card);
    $issuedUuid = $card->refresh()->public_card_uuid;

    // A later format version must not rewrite a card already in circulation.
    config()->set('security.id_card_qr_payload_version', 5);
    qrService()->ensurePublicReference($card);

    expect($card->refresh()->qr_payload_version)->toBe(1)
        ->and($card->public_card_uuid)->toBe($issuedUuid);
});

it('backfills only the version marker for a card issued before it existed', function (): void {
    $card = frontCard();
    qrService()->ensurePublicReference($card);
    $card->forceFill(['qr_payload_version' => null])->save();
    $uuid = $card->refresh()->public_card_uuid;

    qrService()->ensurePublicReference($card);

    expect($card->refresh()->qr_payload_version)->toBe(1)
        ->and($card->public_card_uuid)->toBe($uuid);
});

it('does not change card identity when the payload version changes', function (): void {
    $card = frontCard();
    qrService()->ensurePublicReference($card);
    $before = $card->refresh()->getRawOriginal();
    $qrBefore = qrService()->buildStableQrUrl($card);

    config()->set('security.id_card_qr_payload_version', 3);
    qrService()->ensurePublicReference($card);
    $after = $card->refresh();

    // card_uuid, card_number, token and the printed URL all survive untouched.
    expect($after->public_card_uuid)->toBe($before['public_card_uuid'])
        ->and($after->card_number)->toBe($before['card_number'])
        ->and($after->token_hash)->toBe($before['token_hash'])
        ->and($after->token_version)->toBe((int) $before['token_version'])
        ->and($after->getRawOriginal('qr_payload'))->toBe($before['qr_payload'])
        ->and(qrService()->buildStableQrUrl($after))->toBe($qrBefore);
});

it('stamps the current version when the reference is deliberately rotated', function (): void {
    $card = frontCard();
    qrService()->ensurePublicReference($card);
    $originalUuid = $card->refresh()->public_card_uuid;

    config()->set('security.id_card_qr_payload_version', 4);
    qrService()->rotateQrReference($card, User::factory()->create(), 'security event');
    $rotated = $card->refresh();

    // Rotation reprints the card, so a new reference and version are expected,
    // but the card number still identifies the same card.
    expect($rotated->qr_payload_version)->toBe(4)
        ->and($rotated->public_card_uuid)->not->toBe($originalUuid)
        ->and($rotated->card_number)->toBe($card->card_number);
});

it('keeps the QR payload as the id-checker link and nothing else', function (): void {
    $card = frontCard();
    $url = qrService()->buildStableQrUrl($card->fresh());

    expect($url)->toBe(config('app.url').'/id-checker/'.$card->refresh()->public_card_uuid);
});

it('never puts personal or organisational data in the QR payload', function (): void {
    $card = frontCard();
    $employee = $card->employee;
    $url = qrService()->buildStableQrUrl($card->fresh());

    foreach ([
        $employee->full_name, $employee->name_en, $employee->phone,
        $employee->nationality, $employee->email, $card->card_number,
        'Front Organization', 'POS-FRONT-9',
    ] as $secret) {
        if (filled($secret)) {
            expect($url)->not->toContain((string) $secret);
        }
    }
});

it('still renders preview and export after a version change', function (): void {
    $card = frontCard();
    config()->set('security.id_card_qr_payload_version', 2);

    $data = app(IdCardRenderDataFactory::class)->make($card->fresh());
    $front = app(IdCardSvgRenderer::class)->renderFront($data);
    $back = app(IdCardSvgRenderer::class)->renderBack($data);

    expect($front)->toContain('<svg')
        ->and($back)->toContain('<svg')
        ->and($data->qrVerificationUrl)->toBe(qrService()->buildStableQrUrl($card->fresh()));
});

it('exposes the version without widening what the API returns', function (): void {
    $card = frontCard();
    qrService()->ensurePublicReference($card);

    // qr_payload stays hidden by default; the version marker is not a secret.
    expect(array_keys($card->refresh()->toArray()))->not->toContain('qr_payload')
        ->and(IdCard::query()->whereKey($card->getKey())->value('qr_payload_version'))->toBe(1);
});
